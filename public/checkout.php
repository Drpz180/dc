<?php
require_once __DIR__ . '/../app/models/Campaign.php';
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/PixGateway.php';
require_once __DIR__ . '/../app/Database.php';

date_default_timezone_set('America/Sao_Paulo');

$slug = $_GET['slug'] ?? '';
$campaign = Campaign::findBySlug($slug);

if (!$campaign) {
    http_response_code(404);
    echo "Campanha não encontrada.";
    exit;
}

function formatMoney($value) {
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

// === NOVO: validação de CPF (algoritmo comum) ===
function validCpf($cpf) {
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) != 11) return false;
    if (preg_match('/^(.)\\1+$/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) return false;
    }
    return true;
}


// === NOVO: Limite de 3 gerações de Pix por IP em 24h ===
function getClientIp(): string {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['HTTP_CLIENT_IP'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null
    ];
    foreach ($candidates as $val) {
        if (!$val) continue;
        if (strpos($val, ',') !== false) { // XFF pode ter múltiplos
            $val = trim(explode(',', $val)[0]);
        }
        if (filter_var($val, FILTER_VALIDATE_IP)) return $val;
    }
    return '0.0.0.0';
}
/**
 * Verifica se o IP está banido permanentemente.
 */
function isIpBanned(string $ip): bool {
    $banFile = __DIR__ . '/../logs/ratelimit/ban_' . md5($ip) . '.ban';
    return file_exists($banFile);
}

/**
 * Bane permanentemente o IP.
 */
function banIp(string $ip): void {
    $banFile = __DIR__ . '/../logs/ratelimit/ban_' . md5($ip) . '.ban';
    @file_put_contents($banFile, date('Y-m-d H:i:s'));
}

/**
 * Reserva uma tentativa caso ainda não tenha estourado o limite.
 * Retorna true se permitido (e registra a tentativa), false se bloqueado.
 * Após 3 tentativas, bane o IP permanentemente.
 */
function rateLimitReserveAttempt(string $ip, int $limit = 3, int $windowSeconds = 86400): bool {
    if (isIpBanned($ip)) {
        return false;
    }
    $dir = __DIR__ . '/../logs/ratelimit';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    $file = $dir . '/checkout_' . md5($ip) . '.json';
    $now  = time();

    $fp = @fopen($file, 'c+');
    if (!$fp) {
        // Falha ao abrir arquivo: fail-open (não bloquear usuário)
        return true;
    }
    @flock($fp, LOCK_EX);
    @rewind($fp);
    $raw = stream_get_contents($fp);
    $data = json_decode($raw ?: '[]', true);
    if (!is_array($data)) $data = [];
    $timestamps = array_filter($data['ts'] ?? [], function($t) use ($now, $windowSeconds) {
        return is_int($t) && $t >= ($now - $windowSeconds);
    });
    if (count($timestamps) >= $limit) {
        // BANIR PERMANENTEMENTE
        banIp($ip);
        $data['ts'] = array_values($timestamps);
        ftruncate($fp, 0); @rewind($fp); fwrite($fp, json_encode($data));
        @fflush($fp); @flock($fp, LOCK_UN); @fclose($fp);
        return false;
    }
    $timestamps[] = $now;
    $data['ts'] = array_values($timestamps);
    ftruncate($fp, 0); @rewind($fp); fwrite($fp, json_encode($data));
    @fflush($fp); @flock($fp, LOCK_UN); @fclose($fp);
    return true;
}

// Processa submissão do checkout
$pixResp = $pixError = null;
$orderStatus = null;
$txid = null;
$errors = []; // novo: acumula erros de validação

// Webhook PIX (Render: raiz = public/, sem prefixo /public na URL)
$webhookUrl = url_path('webhooks/pix.php');
@file_put_contents(__DIR__ . '/../logs/pix_debug.log', date('Y-m-d H:i:s') . " [WEBHOOK URL] using webhookUrl={$webhookUrl}\n", FILE_APPEND);

// ===== NOVO: gerador de dados de cliente com cache de 1 semana =====
const CUSTOMER_CACHE_DURATION = 7 * 24 * 60 * 60; // 1 semana em segundos
const CUSTOMER_CACHE_FILE = __DIR__ . '/../logs/customer_data_cache.json';

$FIRST_NAMES = [
    'João','Maria','Pedro','Ana','Carlos','Juliana','Lucas','Fernanda',
    'Rafael','Camila','Bruno','Beatriz','Gustavo','Larissa','Thiago','Amanda',
    'Felipe','Mariana','Leonardo','Gabriela','Rodrigo','Patricia','André','Vanessa',
    'Diego','Renata','Marcelo','Letícia','Eduardo','Carolina','Ricardo','Aline'
];

$LAST_NAMES = [
    'Silva','Santos','Oliveira','Souza','Rodrigues','Ferreira','Alves','Pereira',
    'Lima','Gomes','Costa','Ribeiro','Martins','Carvalho','Almeida','Lopes',
    'Soares','Fernandes','Vieira','Barbosa','Rocha','Dias','Nascimento','Andrade'
];

$EMAIL_DOMAINS = ['gmail.com','hotmail.com','outlook.com','yahoo.com.br'];

function loadCustomerCache(): array {
    $file = CUSTOMER_CACHE_FILE;
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

function saveCustomerCache(array $cache): void {
    @file_put_contents(CUSTOMER_CACHE_FILE, json_encode($cache));
}

function cleanExpiredCustomerCache(array $cache): array {
    $now = time();
    foreach ($cache as $key => $ts) {
        if (!is_int($ts) || ($now - $ts) > CUSTOMER_CACHE_DURATION) {
            unset($cache[$key]);
        }
    }
    saveCustomerCache($cache);
    return $cache;
}

function getUniqueRandomFrom(array $list, string $prefix, array &$cache): string {
    if (empty($list)) return '';
    $maxAttempts = count($list);
    $selected = $list[array_rand($list)];
    $attempts = 0;
    do {
        $selected = $list[array_rand($list)];
        $key = $prefix . ':' . $selected;
        $attempts++;
    } while (isset($cache[$key]) && $attempts < $maxAttempts);
    $cache[$prefix . ':' . $selected] = time();
    return $selected;
}

function generateValidCpfUnique(array &$cache): string {
    $attempts = 0;
    do {
        $digits = [];
        for ($i = 0; $i < 9; $i++) {
            $digits[] = random_int(0, 9);
        }
        // primeiro dígito verificador
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += $digits[$i] * (10 - $i);
        }
        $remainder = $sum % 11;
        $digits[] = ($remainder < 2) ? 0 : 11 - $remainder;
        // segundo dígito verificador
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += $digits[$i] * (11 - $i);
        }
        $remainder = $sum % 11;
        $digits[] = ($remainder < 2) ? 0 : 11 - $remainder;

        $cpf = implode('', $digits);
        $key = 'cpf:' . $cpf;
        $attempts++;
    } while (isset($cache[$key]) && $attempts < 100);

    $cache['cpf:' . $cpf] = time();
    return $cpf;
}

function generatePhoneUnique(array &$cache): string {
    $ddds = ['11','21','31','41','51','61','71','81','91'];
    $attempts = 0;
    do {
        $ddd    = $ddds[array_rand($ddds)];
        $prefix = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $suffix = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $phone  = $ddd . '9' . $prefix . $suffix;
        $key    = 'phone:' . $phone;
        $attempts++;
    } while (isset($cache[$key]) && $attempts < 100);

    $cache['phone:' . $phone] = time();
    return $phone;
}

function generateCustomerData(): array {
    global $FIRST_NAMES, $LAST_NAMES, $EMAIL_DOMAINS;
    $cache = loadCustomerCache();
    $cache = cleanExpiredCustomerCache($cache);

    $firstName = getUniqueRandomFrom($FIRST_NAMES, 'fname', $cache);
    $lastName  = getUniqueRandomFrom($LAST_NAMES, 'lname', $cache);
    $name      = trim($firstName . ' ' . $lastName);

    $emailPrefix = strtolower($firstName) . '.' . strtolower($lastName) . random_int(0, 999);
    $domain      = $EMAIL_DOMAINS[array_rand($EMAIL_DOMAINS)];
    $email       = $emailPrefix . '@' . $domain;

    $phone = generatePhoneUnique($cache);
    $cpf   = generateValidCpfUnique($cache);

    // salva cache atualizado
    saveCustomerCache($cache);

    return [
        'name'  => $name,
        'email' => $email,
        'phone' => $phone,
        'cpf'   => $cpf,
    ];
}

// Valor mínimo configurado na campanha
$minAmount = isset($campaign['min_amount']) ? floatval($campaign['min_amount']) : 25;
$maxAmount = 1000;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // coletar e sanitizar
    // $buyerName = trim($_POST['nome'] ?? '');
    // $buyerEmail = trim($_POST['email'] ?? '');
    // $buyerCpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
    // $buyerWhatsapp = trim($_POST['whatsapp'] ?? '');
    // Remover campos do usuário, usar aleatórios (NOVO: via generateCustomerData):
    $customerData  = generateCustomerData();
    $buyerName     = $customerData['name'];
    $buyerCpf      = $customerData['cpf'];
    $buyerEmail    = $customerData['email'];
    // tenta usar whatsapp enviado pelo formulário; se não houver, usa telefone gerado
    $buyerWhatsapp = trim($_POST['whatsapp'] ?? '');
    $buyerPhone    = $buyerWhatsapp !== '' ? $buyerWhatsapp : $customerData['phone'];

    $amount = floatval(str_replace(',', '.', $_POST['contrib-value'] ?? '0'));

    // validacoes server-side
    if ($amount < $minAmount || $amount > $maxAmount) {
        $errors['contrib-value'] = 'Valor inválido: mínimo R$ ' . number_format($minAmount,2,',','.') . ' e máximo R$ ' . number_format($maxAmount,2,',','.') . '.';
    }

    // extras e finalAmount (ATUALIZADO: extras individuais)
    $extras = 0.00;
    $selectedExtras = []; // irá guardar slug => valor para uso em payload
    if (!empty($_POST['extra_luck'])) {
        $selectedExtras['UP1'] = 10.99; // alterado para R$ 10,99
        $extras += 10.99;
    }
    if (!empty($_POST['extra_heart'])) {
        $selectedExtras['UP2'] = 24.90; // alterado para R$ 24,90
        $extras += 24.90;
    }
    if (!empty($_POST['extra_cause'])) {
        $selectedExtras['UP3'] = 58.90; // alterado para R$ 58,90
        $extras += 58.90;
    }
    $finalAmount = $amount + $extras;

    // Limite por IP: até 3 gerações de Pix em 24 horas
    if (empty($errors)) {
        $clientIp = getClientIp();
        // NOVO: verifica ban permanente
        if (isIpBanned($clientIp)) {
            $errors['rate'] = 'ERROR';
            @file_put_contents(__DIR__ . '/../logs/checkout_errors.log', date('Y-m-d H:i:s') . " [RATE_BAN] ip={$clientIp} banned\n", FILE_APPEND);
        } elseif (!rateLimitReserveAttempt($clientIp, 3, 86400)) {
            $errors['rate'] = 'ERROR';
            @file_put_contents(__DIR__ . '/../logs/checkout_errors.log', date('Y-m-d H:i:s') . " [RATE_BAN] ip={$clientIp} banned\n", FILE_APPEND);
        }
    }

    if (empty($errors)) {
        try {
            $pixData = [
                'amount'      => $finalAmount,
                'buyerName'   => $buyerName,
                'buyerEmail'  => $buyerEmail,
                'buyerPhone'  => $buyerPhone,
                'buyerCpf'    => $buyerCpf,
                'description' => 'Contribuição para campanha #' . $campaign['id'],
                'webhookUrl'  => $webhookUrl
            ];

            // Acrescenta tracking (UTMs) para AureaPag
            if (!empty($_POST['utms'])) {
                $decoded = json_decode($_POST['utms'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $pixData['tracking'] = $decoded;
                }
            }

            // === Usa AureaPag como adquirente ===
            if (defined('AUREAPAG_API_TOKEN') && AUREAPAG_API_TOKEN) {
                $pixData['acquirer'] = 'AUREAPAG';
                // Monta items/cart (em centavos) para compatibilidade
                $items = [];
                $mainCents = (int)round($amount * 100);
                $items[] = [
                    'title' => 'Prompts GPT',
                    'unitPrice' => $mainCents,
                    'quantity' => 1,
                    'tangible' => false,
                    'externalRef' => 'donation_' . time() . '_main'
                ];
                foreach ($selectedExtras as $slug => $val) {
                    $items[] = [
                        'title' => str_replace('_',' ',$slug),
                        'unitPrice' => (int)round($val * 100),
                        'quantity' => 1,
                        'tangible' => false,
                        'externalRef' => 'donation_' . time() . '_' . $slug
                    ];
                }
                $pixData['items'] = $items;
            }

            // LOG: dados enviados ao gateway
            @file_put_contents(__DIR__ . '/../logs/pix_debug.log', date('Y-m-d H:i:s') . " [REQUEST] txid=nova payload=" . json_encode($pixData, JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL, FILE_APPEND);
            
            $pixResp = PixGateway::createCharge($pixData);

            // garantir txid disponível para renderização imediata mesmo antes do insert no DB
            $txid = $pixResp['txid'] ?? $txid ?? null;
            
             // LOG: resposta do gateway
             @file_put_contents(__DIR__ . '/../logs/pix_debug.log', date('Y-m-d H:i:s') . " [RESPONSE] " . json_encode($pixResp, JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL, FILE_APPEND);

            file_put_contents(__DIR__ . '/../pix_api_debug.log', date('Y-m-d H:i:s') . "\n" . print_r($pixResp, true) . "\n\n", FILE_APPEND);

            // REGISTRA VENDA NA TABELA ORDERS
            if (!empty($pixResp['txid'])) {
                $txid = $pixResp['txid'];
                $pdo = Database::getConnection();
                // captura UTMs enviadas pelo frontend (campo hidden 'utms' com JSON)
                $utms = [];
                if (!empty($_POST['utms'])) {
                    $decoded = json_decode($_POST['utms'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $utms = $decoded;
                    }
                }

                // Fallback: preserva identificadores de match mesmo se JS/hidden falhar
                if (empty($utms['ttclid']) && !empty($_COOKIE['ttclid'])) {
                  $utms['ttclid'] = (string)$_COOKIE['ttclid'];
                }
                if (empty($utms['ttp']) && !empty($_COOKIE['_ttp'])) {
                  $utms['ttp'] = (string)$_COOKIE['_ttp'];
                }

                $buyerPhoneDigits = preg_replace('/\D/', '', (string)$buyerPhone);
                $webhook_payload = json_encode([
                  'utm' => $utms,
                  'buyer_phone' => $buyerPhoneDigits,
                ]);

                // Use data/hora de São Paulo ao registrar
                $now_local = date('Y-m-d H:i:s');        // formato para o banco
                $now_iso = date('c');                    // ISO‑8601 com offset para APIs (ex: 2025-12-04T15:30:00-03:00)

                $stmt = $pdo->prepare("INSERT INTO orders
                    (campaign_id, txid, buyer_name, buyer_email, buyer_cpf, amount, status, created_at, webhook_payload)
                    VALUES (:campaign_id, :txid, :buyer_name, :buyer_email, :buyer_cpf, :amount, 'pendente', :created_at, :webhook_payload)
                    ON CONFLICT (txid) DO UPDATE SET
                        buyer_name = EXCLUDED.buyer_name,
                        buyer_email = EXCLUDED.buyer_email,
                        buyer_cpf = EXCLUDED.buyer_cpf,
                        amount = EXCLUDED.amount,
                        webhook_payload = EXCLUDED.webhook_payload");
                $stmt->execute([
                    'campaign_id'     => $campaign['id'],
                    'txid'            => $txid,
                    'buyer_name'      => $buyerName,
                    'buyer_email'     => $buyerEmail,
                    'buyer_cpf'       => $buyerCpf,
                    'amount'          => $finalAmount,
                    'created_at'      => $now_local,
                    'webhook_payload' => $webhook_payload
                ]);

                // Dispara evento Facebook Pixel de início de checkout (InitiateCheckout) e loga no console
                if (!empty($campaign['facebook_pixel_id'])) {
                    echo "<script>
                        fbq('track', 'InitiateCheckout', {
                            value: " . json_encode($finalAmount) . ",
                            currency: 'BRL'
                        });
                        console.log('[FB PIXEL] Evento InitiateCheckout disparado', {
                            value: " . json_encode($finalAmount) . ",
                            currency: 'BRL'
                        });
                    </script>";
                }

                // Envia notificação Utmify de venda pendente (waiting_payment)
                if (!empty($campaign['utmify_api_token'])) {
                    $utmToken = $campaign['utmify_api_token'];
                    $utmEndpoint = 'https://api.utmify.com.br/api-credentials/orders';

                    // Monta products
                    $totalCents = (int)round($finalAmount * 100); // valor correto em centavos, sem desconto de taxa
                    $products = [[
                        'id' => $txid . '_item',
                        'name' => 'Prompts GPT',
                        'planId' => null,
                        'planName' => null,
                        'quantity' => 1,
                        'priceInCents' => $totalCents // valor integral
                    ]];

                    // Monta trackingParameters
                    $trackingParameters = [
                        'src' => $utms['src'] ?? null,
                        'sck' => $utms['sck'] ?? null,
                        'utm_source' => $utms['utm_source'] ?? null,
                        'utm_campaign' => $utms['utm_campaign'] ?? null,
                        'utm_medium' => $utms['utm_medium'] ?? null,
                        'utm_content' => $utms['utm_content'] ?? null,
                        'utm_term' => $utms['utm_term'] ?? null
                    ];

                    // Monta customer
                    $country = strtoupper($utms['country_code'] ?? ($utms['country'] ?? 'BR'));
                    if (empty($country) || strlen($country) !== 2) $country = 'BR';

                    $customer = [
                        'name' => $buyerName,
                        'email' => $buyerEmail,
                        'phone' => $_POST['whatsapp'] ?? null,
                        'document' => $buyerCpf,
                        'country' => $country,
                        'ip' => $utms['user_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null)
                    ];

                    // Monta commission (taxa só informativa, não altera valor enviado)
                    $gatewayFeeInCents = 100 + (int)round($totalCents * 0.03);
                    $userCommissionInCents = $totalCents - $gatewayFeeInCents;

                    $commission = [
                        'totalPriceInCents' => $totalCents, // valor integral
                        'gatewayFeeInCents' => $gatewayFeeInCents,
                        'userCommissionInCents' => $userCommissionInCents,
                        'currency' => 'BRL'
                    ];

                    $utmBody = [
                        'orderId' => (string)$txid,
                        'platform' => 'Prompts GPT',
                        'paymentMethod' => 'pix',
                        'status' => 'waiting_payment',
                        // enviar ISO‑8601 (inclui offset do horário de São Paulo)
                        'createdAt' => $now_iso,
                        'approvedDate' => null,
                        'refundedAt' => null,
                        'customer' => $customer,
                        'products' => $products,
                        'trackingParameters' => $trackingParameters,
                        'commission' => $commission,
                        'isTest' => false,
                        'amount' => $totalCents // valor integral
                    ];

                    // Envia para Utmify
                    $chU = curl_init($utmEndpoint);
                    curl_setopt_array($chU, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode($utmBody),
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: application/json',
                            'x-api-token: ' . $utmToken
                        ],
                        CURLOPT_TIMEOUT => 10,
                        CURLOPT_SSL_VERIFYPEER => false
                    ]);
                    $respU = curl_exec($chU);
                    $codeU = curl_getinfo($chU, CURLINFO_HTTP_CODE);
                    $errU = curl_error($chU);
                    curl_close($chU);

                    // Log envio/resposta
                    @file_put_contents(__DIR__ . '/../logs/utmify_events.log', date('Y-m-d H:i:s') . " [UTMIFY SEND] txid={$txid} endpoint={$utmEndpoint} body=" . json_encode($utmBody) . PHP_EOL, FILE_APPEND);
                    @file_put_contents(__DIR__ . '/../logs/utmify_events.log', date('Y-m-d H:i:s') . " [UTMIFY RESP] txid={$txid} http_code={$codeU} err={$errU} resp=" . substr($respU,0,1000) . PHP_EOL, FILE_APPEND);
                }

                // Consulta status do pagamento Pix (primeira vez)
                try {
                  $statusResp = PixGateway::getStatus($txid);
                  if (
                    (!empty($statusResp['paid']) && $statusResp['paid']) ||
                    (isset($statusResp['status']) && in_array(strtolower($statusResp['status']), ['paid', 'pago', 'concluida']))
                  ) {
                    $orderStatus = 'pago';
                    // Atualiza paid_at com timezone correto
                    $pdo->prepare("UPDATE orders SET status='pago', paid_at=? WHERE txid=?")->execute([date('Y-m-d H:i:s'), $txid]);
                  } else {
                    $orderStatus = 'pendente';
                  }
                } catch (Exception $e) {
                  // Não falhar a página se o gateway estiver lento; considera pendente e loga.
                  $orderStatus = 'pendente';
                  @file_put_contents(__DIR__ . '/../logs/pix_debug.log', date('Y-m-d H:i:s') . " [GETSTATUS ERROR] txid={$txid} msg=" . $e->getMessage() . PHP_EOL, FILE_APPEND);
                }
            }
        } catch (Exception $e) {
            $pixError = $e->getMessage();

            // LOG: erro capturado (detalhado)
            $logMsg = date('Y-m-d H:i:s') . " [ERROR] Falha ao criar cobrança PIX\n";
            $logMsg .= "Mensagem: " . $pixError . "\n";
            $logMsg .= "Payload enviado: " . json_encode($pixData, JSON_PRETTY_PRINT) . "\n";
            $logMsg .= "Resposta do gateway: " . (isset($pixResp) ? json_encode($pixResp, JSON_PRETTY_PRINT) : 'N/A') . "\n";
            $logMsg .= "Stack: " . $e->getTraceAsString() . "\n\n";
            @file_put_contents(__DIR__ . '/../logs/pix_debug.log', $logMsg, FILE_APPEND);

            // Log simplificado para debug rápido
            $apiDebugMsg = date('Y-m-d H:i:s') . "\nERRO: " . $pixError . "\n";
            $apiDebugMsg .= "Payload: " . json_encode($pixData, JSON_PRETTY_PRINT) . "\n";
            $apiDebugMsg .= "Resposta: " . (isset($pixResp) ? print_r($pixResp, true) : 'N/A') . "\n";
            $apiDebugMsg .= "Stack: " . $e->getTraceAsString() . "\n\n";
            file_put_contents(__DIR__ . '/../pix_api_debug.log', $apiDebugMsg, FILE_APPEND);
        }
    } else {
        // Loga erros para debug rápido (opcional)
        @file_put_contents(__DIR__ . '/../logs/checkout_errors.log', date('Y-m-d H:i:s') . " " . json_encode($errors) . PHP_EOL, FILE_APPEND);
    }
}

// Após gerar o QR Code, consulta status do pedido no banco e no gateway para atualizar o frontend
if (!empty($txid)) {
    $pdo = Database::getConnection();
  $stmt = $pdo->prepare("SELECT status, amount FROM orders WHERE txid = ?");
    $stmt->execute([$txid]);
    $dbOrder = $stmt->fetch();
  $orderAmountForPixel = isset($dbOrder['amount']) ? (float)$dbOrder['amount'] : null;
    if ($dbOrder && in_array(strtolower($dbOrder['status']), ['pago', 'paid', 'concluida'])) {
        $orderStatus = 'pago';
    } else {
        // Consulta no gateway (atualizado)
        try {
          $statusResp = PixGateway::getStatus($txid);
          if (
            (!empty($statusResp['paid']) && $statusResp['paid']) ||
            (isset($statusResp['status']) && in_array(strtolower($statusResp['status']), ['paid', 'pago', 'concluida']))
          ) {
            $orderStatus = 'pago';
            // Atualiza paid_at com timezone correto
            $pdo->prepare("UPDATE orders SET status='pago', paid_at=? WHERE txid=?")->execute([date('Y-m-d H:i:s'), $txid]);
          } else {
            $orderStatus = 'pendente';
          }
        } catch (Exception $e) {
          // Em caso de timeout/erro, não quebremos a renderização — assume pendente e loga o erro
          $orderStatus = 'pendente';
          @file_put_contents(__DIR__ . '/../logs/pix_debug.log', date('Y-m-d H:i:s') . " [GETSTATUS ERROR] txid={$txid} msg=" . $e->getMessage() . PHP_EOL, FILE_APPEND);
        }
    }
}

// CAPTURAR UTMs PARA MANTER NO LINK/FORM
$utmKeys = [
  'utm_source','utm_campaign','utm_medium','utm_content','utm_term','utm_id',
  'fbclid','fbc','fbp','ttclid','src','xcod','external_id','currency'
];
$capturedUtms = [];
foreach ($utmKeys as $k) {
    if (!empty($_GET[$k])) $capturedUtms[$k] = $_GET[$k];
}
$utmQuery = http_build_query($capturedUtms);
$selfUrlWithUtms = 'checkout.php?slug=' . urlencode($campaign['slug']) . ($utmQuery ? '&' . $utmQuery : '');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <?php if (!empty($campaign['tiktok_pixel_id'])): ?>
    <!-- TikTok Pixel Base Code -->
    <script>
      !function (w, d, t) {
        w.TiktokAnalyticsObject = t;
        var ttq = w[t] = w[t] || [];
        ttq.methods = ["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"];
        ttq.setAndDefer = function(target, method){
          target[method] = function(){
            target.push([method].concat(Array.prototype.slice.call(arguments, 0)));
          };
        };
        for (var i = 0; i < ttq.methods.length; i++) ttq.setAndDefer(ttq, ttq.methods[i]);
        ttq.instance = function(id){
          var inst = ttq._i[id] || [];
          for (var n = 0; n < ttq.methods.length; n++) ttq.setAndDefer(inst, ttq.methods[n]);
          return inst;
        };
        ttq.load = function(id, opts){
          var src = "https://analytics.tiktok.com/i18n/pixel/events.js";
          ttq._i = ttq._i || {};
          ttq._i[id] = [];
          ttq._i[id]._u = src;
          ttq._t = ttq._t || {};
          ttq._t[id] = +new Date();
          ttq._o = ttq._o || {};
          ttq._o[id] = opts || {};
          var s = document.createElement("script");
          s.type = "text/javascript";
          s.async = true;
          s.src = src + "?sdkid=" + id + "&lib=" + t;
          var x = document.getElementsByTagName("script")[0];
          x.parentNode.insertBefore(s, x);
        };

        function logTikTokClientEvent(eventName, payload) {
          var bodyObj = {
            source: 'checkout',
            pixel_id: ttPixelId,
            event: eventName,
            payload: payload || null,
            page: window.location.href,
            ts_client: new Date().toISOString()
          };
          var body = JSON.stringify(bodyObj);
          try {
            if (navigator.sendBeacon) {
              navigator.sendBeacon('api/tiktok_pixel_log.php', new Blob([body], { type: 'application/json' }));
              return;
            }
          } catch (e) {}
          try {
            fetch('api/tiktok_pixel_log.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: body,
              keepalive: true
            });
          } catch (e) {}
        }

        var ttPixelId = <?= json_encode((string)$campaign['tiktok_pixel_id']) ?>;
        ttq.load(ttPixelId);
        ttq.page();
        logTikTokClientEvent('PageView', { source: 'ttq.page' });
        var ttInitiatePayload = {
          content_type: 'product',
          content_id: <?= json_encode('campaign_' . (string)$campaign['id']) ?>,
          content_name: <?= json_encode((string)$campaign['title']) ?>,
          currency: 'BRL'
        };
        ttq.track('InitiateCheckout', ttInitiatePayload);
        logTikTokClientEvent('InitiateCheckout', ttInitiatePayload);
        console.log('[TT PIXEL] Base + InitiateCheckout disparados', { pixel_id: ttPixelId });
      }(window, document, 'ttq');
    </script>
  <?php endif; ?>
  <!-- NOVO: meta viewport -->
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no"/>
  <title>Contribuir para <?= htmlspecialchars($campaign['title']) ?> | Minha Vaquinha</title>
  <link rel="stylesheet" href="css/style.css">
  <link href="https://fonts.googleapis.com/css?family=Lato:400,700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css?family=Montserrat:700&display=swap" rel="stylesheet">
  <?php if (!empty($campaign['facebook_pixel_id'])): ?>
    <!-- Facebook Pixel Code -->
    <script>
      !function(f,b,e,v,n,t,s)
      {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
      n.callMethod.apply(n,arguments):n.queue.push(arguments)};
      if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
      n.queue=[];t=b.createElement(e);t.async=!0;
      t.src=v;s=b.getElementsByTagName(e)[0];
      s.parentNode.insertBefore(t,s)}(window, document,'script',
      'https://connect.facebook.net/en_US/fbevents.js');
      fbq('init', '<?= htmlspecialchars($campaign['facebook_pixel_id']) ?>');
      fbq('track', 'PageView');
      fbq('track', 'InitiateCheckout');
    </script>
    <noscript>
      <img height="1" width="1" style="display:none"
           src="https://www.facebook.com/tr?id=<?= htmlspecialchars($campaign['facebook_pixel_id']) ?>&ev=PageView&noscript=1"/>
    </noscript>
  <?php endif; ?>
  <style>
    body {
      font-family: 'Lato', Arial, sans-serif;
      background: #fafafa;
      margin: 0;
      color: #282828;
    }
    .vakinha-header {
      background: #fff;
      border-bottom: 1px solid #eee;
      margin-bottom: 0;
      padding: 0;
      height: 96px; /* antes 74px */
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .vakinha-header .container {
      width: 100%;
      max-width: 1200px;
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: space-between;
      height: 96px; /* antes 74px */
    }
    .vakinha-header .logo-area {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .vakinha-header .logo-img {
      height: 44px;
      width: auto;
      vertical-align: middle;
    }
    .vakinha-header nav {
      display: flex;
      align-items: center;
      gap: 28px;
      flex: 1;
      margin-left: 40px;
    }
    .vakinha-header .nav-item {
      font-size: 1.08em;
      color: #222;
      font-weight: bold;
      cursor: pointer;
      position: relative;
      padding: 0 6px;
      transition: color .2s;
    }
    .vakinha-header .nav-item:hover {
      color: #00c853;
    }
    .vakinha-header .nav-item .arrow {
      display: inline-block;
      vertical-align: middle;
      margin-left: 3px;
      width: 18px;
      height: 18px;
    }
    .vakinha-header .nav-right {
      display: flex;
      align-items: center;
      gap: 22px;
    }
    .vakinha-header .search-box {
      display: flex;
      align-items: center;
      gap: 4px;
      color: #00c853;
      font-size: 1.08em;
      cursor: pointer;
      font-weight: bold;
    }
    .vakinha-header .search-icon {
      width: 22px;
      height: 22px;
      vertical-align: middle;
      margin-left: 2px;
      margin-bottom: 2px;
    }
    .vakinha-header .account-link {
      color: #00c853;
      font-weight: bold;
      font-size: 1.08em;
      text-decoration: none;
      transition: color .2s;
    }
    .vakinha-header .account-link:hover {
      color: #009e3c;
      text-decoration: underline;
    }
    .vakinha-header .btn-vakinha {
      background: #00c853;
      color: #fff;
      font-weight: bold;
      border: none;
      border-radius: 8px;
      padding: 10px 28px;
      font-size: 1.08em;
      cursor: pointer;
      transition: background .2s;
      box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .vakinha-header .btn-vakinha:hover {
      background: #009e3c;
    }
    .checkout-wrapper {
      width: 100%;
      margin: 0;
      background: transparent;
      border-radius: 0;
      box-shadow: none;
      padding: 60px 0; /* remove padding lateral grande - centralizamos com .checkout-inner */
      box-sizing: border-box;
    }
    /* container centralizado que contém todo o conteúdo do checkout */
    .checkout-inner {
      max-width: 1280px;
      margin: 0 auto;
      padding: 0 64px;
      box-sizing: border-box;
    }
    /* garantir que título e subtítulo fiquem com espaçamento visual coerente */
    .checkout-title {
      margin-top: 6px;
      margin-bottom: 18px;
      font-family: 'Montserrat', Arial, sans-serif;
      font-weight: 700;
      font-size: 2.7em;
      color: #222;
      letter-spacing: -1px;
      text-align: left;
      line-height: 1.1;
    }
    .checkout-sub {
      color: #888;
      font-size: 1em;
      margin-bottom: 18px;
    }
    .checkout-form-row {
      display: flex;
      gap: 18px;
      margin-bottom: 18px;
    }
    .checkout-form-row input {
      width: 100%;
      padding: 10px;
      border: 1px solid #ddd;
      border-radius: 6px;
      font-size: 1em;
      background: #fafafa;
      box-sizing: border-box;
    }
    .checkout-form-row label {
      font-weight: bold;
      margin-bottom: 4px;
      display: block;
      color: #222;
      font-size: 1em;
    }
    .checkout-form-row .form-group {
      flex: 1;
      display: flex;
      flex-direction: column;
    }
    .checkout-form-row .form-group.short {
      max-width: 180px;
    }
    .checkout-section {
      margin-bottom: 22px;
    }
    .checkout-section label {
      font-weight: bold;
      color: #222;
      font-size: 1em;
      margin-bottom: 6px;
      display: block;
    }
    .checkout-value-row {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 18px;
    }
    .checkout-value-row input {
      width: 120px;
      padding: 10px;
      border: 1px solid #ddd;
      border-radius: 6px;
      font-size: 1.1em;
      background: #fafafa;
      box-sizing: border-box;
      font-weight: bold;
    }
    .checkout-value-row span {
      font-size: 1.1em;
      color: #888;
      font-weight: bold;
    }
    .checkout-payment-row {
      display: flex;
      gap: 18px;
      margin-bottom: 18px;
    }
    .checkout-payment-row label {
      font-weight: bold;
      color: #222;
      font-size: 1em;
      margin-bottom: 6px;
      display: block;
    }
    .checkout-payment-options {
      display: flex;
      gap: 12px;
      margin-bottom: 10px;
    }
    .checkout-payment-btn {
      padding: 8px 22px;
      border-radius: 6px;
      border: 2px solid #00c853;
      background: #fff;
      color: #00c853;
      font-weight: bold;
      font-family: 'Montserrat', Arial, sans-serif;
      cursor: pointer;
      font-size: 1em;
      transition: background .2s, color .2s;
    }
    .checkout-payment-btn.active,
    .checkout-payment-btn:hover {
      background: #00c853;
      color: #fff;
    }
    .checkout-extras {
      background: #eaffea;
      border-radius: 12px;
      padding: 4px 10px 10px 10px;
      margin-bottom: 18px;
      margin-top: 10px;
      display: flex;
      flex-direction: column;
      gap: 0;
      box-sizing: border-box;
    }
    .checkout-extras-row {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 8px;
      justify-content: flex-start;
      background: none;
      border-radius: 0;
      padding: 0;
      min-height: 38px;
    }
    .checkout-extras-row input[type="checkbox"] {
      accent-color: #00c853;
      width: 22px;
      height: 22px;
      margin-right: 8px;
    }
    .checkout-extras-label {
      font-family: 'Montserrat', Arial, sans-serif;
      font-weight: 700;
      color: #fff;
      font-size: 0.93em;
      margin-right: 8px;
      background: #00c853;
      border-radius: 12px;
      padding: 2px 10px;
      display: inline-block;
      letter-spacing: 0.5px;
      box-shadow: 0 1px 4px rgba(0,0,0,0.04);
    }
    .checkout-extras-total {
      font-family: 'Montserrat', Arial, sans-serif;
      font-weight: 700;
      color: #00c853;
      font-size: 0.98em;
      margin-left: auto;
      margin-right: 0;
      background: none;
      border-radius: 0;
      padding: 0;
      align-self: center;
    }
    .checkout-extras-items {
      display: flex;
      gap: 12px;
      flex-wrap: nowrap;
      align-items: stretch;
      margin-top: 0;
      background: none;
      border-radius: 0;
      padding: 0;
    }
    .checkout-extras-item {
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 1px 4px rgba(0,0,0,0.04);
      padding: 10px 12px 10px 12px;
      font-size: 0.97em;
      min-width: 0;
      flex: 1 1 0;
      display: flex;
      align-items: center;
      gap: 10px;
      box-sizing: border-box;
      position: relative;
    }
    .checkout-extras-item .extras-icon {
      width: 24px;
      height: 24px;
      flex: 0 0 24px;
      margin-right: 0;
      margin-bottom: 0;
      display: inline-block;
    }
    .checkout-extras-item .extras-content {
      flex: 1;
      min-width: 0;
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      justify-content: center;
    }
    .checkout-extras-item .extras-title {
      font-family: 'Montserrat', Arial, sans-serif;
      font-weight: 700;
      color: #222;
      font-size: 1em;
      margin-bottom: 2px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .checkout-extras-item .extras-desc {
      color: #444;
      font-size: 0.95em;
      margin-top: 2px;
      margin-bottom: 0;
      line-height: 1.3;
      font-family: 'Lato', Arial, sans-serif;
    }
    .checkout-extras-item .extras-price,
    .checkout-extras-item .extras-free {
      font-family: 'Montserrat', Arial, sans-serif;
      font-weight: 700;
      font-size: 0.97em;
      color: #00c853;
      background: none;
      border-radius: 0;
      padding: 0;
      margin-left: auto;
      margin-right: 0;
      position: static;
      white-space: nowrap;
      align-self: flex-start;
    }
    /* Responsivo */
    @media (max-width: 900px) {
      .checkout-extras {
        padding: 2px 4px 8px 4px;
      }
      .checkout-extras-row {
        gap: 6px;
        margin-bottom: 6px;
        min-height: 32px;
      }
      .checkout-extras-label {
        font-size: 0.92em;
        padding: 2px 8px;
      }
      .checkout-extras-total {
        font-size: 0.95em;
      }
      .checkout-extras-items {
        flex-direction: column;
        gap: 8px;
      }
      .checkout-extras-item {
        flex-direction: row;
        min-width: 100%;
        padding: 8px;
      }
      .checkout-extras-item .extras-icon {
        width: 20px;
        height: 20px;
      }
    }
    .checkout-summary {
      margin: 18px 0 12px 0;
      font-size: 1.08em;
      color: #222;
    }
    .checkout-total-row {
      font-size: 1.15em;
      font-weight: bold;
      color: #222;
      margin-bottom: 18px;
    }
    .checkout-checkbox-row {
      margin-bottom: 18px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .checkout-checkbox-row input[type="checkbox"] {
      width: 18px;
      height: 18px;
      accent-color: #00c853;
    }
    .checkout-checkbox-row label {
      font-size: 1em;
      color: #222;
      font-weight: 500;
      margin-bottom: 0;
    }
    .checkout-btn {
      background: #00c853;
      color: #fff;
      font-family: 'Montserrat', Arial, sans-serif;
      font-weight: 700;
      border: none;
      border-radius: 8px;
      padding: 16px 0;
      font-size: 1.18em;
      width: 100%;
      cursor: pointer;
      margin-bottom: 18px;
      transition: background .2s;
    }
    .checkout-btn:hover {
      background: #009e3c;
    }
    .checkout-security {
      background: #f3f3f3;
      border-radius: 12px;
      padding: 12px 18px;
      display: flex;
      align-items: center;
      gap: 18px;
      margin-bottom: 18px;
      font-size: 1em;
      color: #222;
    }
    .checkout-security .security-img {
      width: 110px;
      height: 50px;
      object-fit: contain;
      display: inline-block;
      margin-right: 0;
    }
    .checkout-security .security-label {
      font-weight: 400;
      color: #222;
      font-size: 1em;
      margin-left: 0;
    }
    @media (max-width: 900px) {
      .checkout-security .security-img {
        width: 140px;
        height: 140px;
      }
      .checkout-security {
        gap: 12px;
      }
    }
    .checkout-terms {
      font-size: 0.95em;
      color: #888;
      margin-bottom: 8px;
    }
    .checkout-note {
      font-size: 0.92em;
      color: #aaa;
      margin-top: 4px;
    }
    /* Erros de validação */
    .input-error {
      border-color: #ff3b3b !important;
      background: #fff6f6 !important;
    }
    .error-message {
      color: #ff3b3b;
      font-size: 0.97em;
      margin-top: 2px;
      margin-bottom: 0;
      font-weight: 500;
    }
    /* Responsivo */
    @media (max-width: 900px) {
      .checkout-wrapper {
        padding: 24px 6vw;
      }
      .checkout-form-row {
        flex-direction: column;
        gap: 0;
      }
      .checkout-inner {
        padding: 0 12px;
      }
      .checkout-title {
        font-size: 2em;
      }
    }
    /* ===== AJUSTE: TÍTULO MENOR NO MOBILE (CHECKOUT) ===== */
    @media (max-width: 600px) {
      .checkout-title {
        font-size: 1.15em !important;
        margin-bottom: 12px !important;
        margin-top: 2px !important;
        line-height: 1.15 !important;
      }
    }
    /* Campo de valor (novo design) */
    .amount-input-wrapper {
      display:flex;
      border:1px solid #cfcfcf;
      border-radius:8px;
      background:#fff;
      width:100%;
      max-width:420px;
      overflow:hidden;
      font-family:'Lato',Arial,sans-serif;
    }
    .amount-input-wrapper:focus-within {
      border-color:#00c853;
      box-shadow:0 0 0 3px rgba(0,200,83,0.15);
    }
    .currency-box {
      background:#f2f2f2;
      padding:12px 16px;
      font-weight:600;
      font-size:0.95em;
      color:#333;
      display:flex;
      align-items:center;
      border-right:1px solid #d9d9d9;
      user-select:none;
    }
    #contrib-value {
      flex:1;
      border:0;
      outline:none;
      padding:12px 14px;
      font-size:1.05em;
      font-weight:700;
      background:#fff;
      letter-spacing:.5px;
    }
    #contrib-value::placeholder {
      color:#9e9e9e;
      font-weight:400;
    }
    #contrib-value.input-error {
      background:#fff6f6;
    }
    .limit-hint {
      font-size:0.75em;
      color:#777;
      margin-top:6px;
    }
    @media (max-width:900px){
      .amount-input-wrapper {max-width:100%;}
    }

    /* ===== NOVO DESIGN PIX (MODERNO / RESPONSIVO) ===== */
    .pix-payment-wrapper {
      --bg:#ffffff;
      --border:#e7e7e7;
      --accent:#00c853;
      --radius:18px;
      display:flex;
      gap:28px;
      align-items:flex-start;
      justify-content:center;
      max-width:1100px;
      margin:46px auto 0;
      padding:26px 30px;
      background:linear-gradient(135deg,#ffffff 0%,#f8fdf9 100%);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:0 10px 28px -8px rgba(0,0,0,0.15);
    }
    .pix-payment-wrapper .pix-card {
      background:#fff;
      border:1px solid #f0f0f0;
      border-radius:16px;
      padding:22px 24px 20px;
      flex:1 1 360px;
      display:flex;
      flex-direction:column;
      gap:18px;
      position:relative;
      box-shadow:0 6px 20px -6px rgba(0,0,0,0.12);
    }
    .pix-payment-wrapper .pix-left {
      max-width:340px;
      align-items:center;
      text-align:center;
      gap:16px;
    }
    .qr-badge {
      background:var(--accent);
      color:#fff;
      font-family:'Montserrat',Arial,sans-serif;
      font-weight:700;
      font-size:.75em;
      letter-spacing:.8px;
      padding:6px 14px;
      border-radius:24px;
      box-shadow:0 4px 14px rgba(0,200,83,.35);
      position:absolute;
      top:-14px;
      left:20px;
    }
    .qr-img {
      width:230px;
      height:230px;
      border:10px solid #fff;
      border-radius:18px;
      background:#fafafa;
      box-shadow:0 6px 18px rgba(0,0,0,0.10);
    }
    .btn-copy-inline {
      background:#00c853;
      color:#fff;
      border:none;
      border-radius:10px;
      font-weight:700;
      font-family:'Montserrat',Arial,sans-serif;
      padding:12px 18px;
      cursor:pointer;
      font-size:.95em;
      width:100%;
      max-width:230px;
      transition:background .2s;
    }
    .btn-copy-inline:hover { background:#009e3c; }
    .expires {
      font-size:.82em;
      color:#666;
      font-weight:600;
    }
    .pix-right .pix-title {
      margin:0;
      font-size:1.65em;
      font-family:'Montserrat',Arial,sans-serif;
      font-weight:700;
      color:#222;
      letter-spacing:-.5px;
      line-height:1.2;
    }
    .pix-steps {
      list-style:none;
      margin:0;
      padding:0;
      display:flex;
      flex-direction:column;
      gap:10px;
      font-size:.95em;
      color:#333;
    }
    .pix-steps li {
      display:flex;
      gap:10px;
      line-height:1.35;
      position:relative;
      padding-left:28px;
    }
    .pix-steps li:before {
      content:counter(step);
      counter-increment:step;
      position:absolute;
      left:0;
      top:0;
      background:#00c853;
      color:#fff;
      width:22px;
      height:22px;
      border-radius:50%;
      font-size:.72em;
      font-weight:700;
      font-family:'Montserrat',Arial,sans-serif;
      display:flex;
      align-items:center;
      justify-content:center;
      box-shadow:0 4px 10px rgba(0,200,83,.3);
    }
    .pix-right .pix-info-grid {
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
      gap:12px 18px;
      background:#f6fdf7;
      border:1px solid #e2f5e6;
      padding:14px 16px;
      border-radius:14px;
      font-size:.85em;
      color:#444;
    }
    .pix-right .pix-info-grid div {
      display:flex;
      flex-direction:column;
      gap:4px;
    }
    .pix-right .pix-info-grid span {
      font-weight:600;
      color:#666;
      font-size:.75em;
      letter-spacing:.5px;
      text-transform:uppercase;
    }
    .pix-right .pix-info-grid strong {
      font-weight:700;
      color:#222;
      font-size:.95em;
      word-break:break-word;
    }
    .pix-copia-cola {
      display:flex;
      flex-direction:column;
      gap:8px;
    }
    .pix-copia-cola label {
      font-weight:700;
      font-size:.85em;
      font-family:'Montserrat',Arial,sans-serif;
      color:#222;
      letter-spacing:.5px;
    }
    .pix-copia-cola .code-row {
      display:flex;
      gap:10px;
      align-items:center;
    }
    .pix-copia-cola input {
      flex:1;
      background:#fafafa;
      border:1px solid #e3e3e3;
      border-radius:10px;
      padding:10px 12px;
      font-size:.85em;
      color:#222;
      font-family:'Lato',Arial,sans-serif;
      overflow:hidden;
      text-overflow:ellipsis;
      white-space:nowrap;
    }
    .pix-copia-cola button {
      background:#00c853;
      color:#fff;
      border:none;
      border-radius:10px;
      font-weight:700;
      font-family:'Montserrat',Arial,sans-serif;
      padding:10px 16px;
      cursor:pointer;
      font-size:.75em;
      letter-spacing:.5px;
      height:42px;
      flex:0 0 auto;
      transition:background .2s;
    }
    .pix-copia-cola button:hover { background:#009e3c; }
    .pix-status {
      margin-top:4px;
      font-size:.92em;
      font-weight:700;
      padding:10px 14px;
      border-radius:12px;
      display:inline-block;
      background:#fff7e0;
      color:#b86c00;
      box-shadow:0 2px 8px rgba(0,0,0,0.06);
    }
    .pix-status.pago {
      background:#e7fceb;
      color:#0d7a32;
      box-shadow:0 2px 10px rgba(0,150,60,0.18);
    }
    /* Ajuste ordem mobile: exibe copia e cola junto ao QR e oculta versão da direita */
    .pix-copia-mobile {display:none;}
    @media (max-width:600px){
      .pix-payment-wrapper {flex-direction:column;}
      .pix-left {order:1;}
      .pix-right {order:2;}
      .pix-right .pix-copia-cola {display:none !important;}
      .pix-copia-mobile {display:flex;flex-direction:column;gap:8px;width:100%;margin-top:4px;}
      .pix-copia-mobile label {
        font-weight:700;font-size:.85em;font-family:'Montserrat',Arial,sans-serif;color:#222;letter-spacing:.5px;
      }
      .pix-copia-mobile .code-row {
        display:flex;gap:10px;align-items:center;
      }
      .pix-copia-mobile input {
        flex:1;background:#fafafa;border:1px solid #e3e3e3;border-radius:10px;padding:10px 12px;
        font-size:.85em;color:#222;font-family:'Lato',Arial,sans-serif;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
      }
      .pix-copia-mobile button {
        background:#00c853;color:#fff;border:none;border-radius:10px;font-weight:700;
        font-family:'Montserrat',Arial,sans-serif;padding:10px 16px;cursor:pointer;font-size:.75em;letter-spacing:.5px;height:42px;
        transition:background .2s;
      }
      .pix-copia-mobile button:hover {background:#009e3c;}
    }
  </style>
  <script>
    function copyPixCode() {
      var input = document.getElementById('pix-copy-input');
      if (!input) return;
      navigator.clipboard.writeText(input.value).then(function(){
        var btn = document.getElementById('pix-copy-btn');
        if (btn) {
          btn.innerText = 'Copiado!';
          setTimeout(()=>btn.innerText='COPIAR',1500);
        }
      });
    }
  </script>
</head>
<body class="<?= $isMobile ? 'is-mobile' : 'is-desktop' ?>">
<!-- === HEADER PADRÃO VAKINHA (RESPONSIVO, IGUAL campaign.php) === -->
<div class="vakinha-header">
  <div class="container">
    <div class="logo-area">
      <a href="index.php">
        <img src="logo.png" alt="Minha Vaquinha" class="logo-img">
      </a>
    </div>
    <nav>
      <a class="nav-item" href="index.php">Início</a>
      <a class="nav-item" href="explore.php">Explorar</a>
      <a class="nav-item" href="howitworks.php">Como funciona</a>
    </nav>
    <div class="nav-right">
      <button class="search-box" aria-label="Buscar">
        <span class="search-label">Buscar</span>
        <svg class="search-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <circle cx="11" cy="11" r="7" stroke="#00c853" stroke-width="2"/>
          <line x1="16.5" y1="16.5" x2="21" y2="21" stroke="#00c853" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </button>
      <a class="account-link" href="login.php">Entrar</a>
      <a class="btn-vakinha" href="create.php">Criar vaquinha</a>
      <!-- botão menu (apenas mobile aparece) -->
      <button class="menu-mobile" aria-label="Menu" style="display:none;">
        <svg class="menu-icon" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
          <rect x="6" y="9" width="20" height="2.5" rx="1.2" fill="#282828"/>
          <rect x="6" y="15" width="20" height="2.5" rx="1.2" fill="#282828"/>
          <rect x="6" y="21" width="20" height="2.5" rx="1.2" fill="#282828"/>
        </svg>
      </button>
    </div>
  </div>
</div>
<!-- === /vakinha-header === -->
<script>
  // Força responsividade do header igual campaign.php
  (function(){
    function isMobileDevice() {
      var ua = navigator.userAgent || '';
      var isMobileUA = /Mobile|Android|iPhone|iPad|iPod|BlackBerry|BB10|IEMobile|Opera Mini|webOS|Kindle|Silk|PlayBook/i.test(ua);
      var isTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
      var isSmallScreen = window.innerWidth <= 700;
      return isMobileUA || isTouch || isSmallScreen;
    }
    function applyHeaderMobile() {
      var body = document.body;
      var nav = document.querySelector('.vakinha-header nav');
      var account = document.querySelector('.vakinha-header .account-link');
      var btnVakinha = document.querySelector('.vakinha-header .btn-vakinha');
      var menuBtn = document.querySelector('.vakinha-header .menu-mobile');
      var searchLabel = document.querySelector('.vakinha-header .search-label');
      if (isMobileDevice()) {
        body.classList.add('is-mobile');
        body.classList.remove('is-desktop');
        if (nav) nav.style.display = 'none';
        if (account) account.style.display = 'none';
        if (btnVakinha) btnVakinha.style.display = 'none';
        if (menuBtn) menuBtn.style.display = 'flex';
        if (searchLabel) searchLabel.style.display = 'none';
      } else {
        body.classList.remove('is-mobile');
        body.classList.add('is-desktop');
        if (nav) nav.style.display = '';
        if (account) account.style.display = '';
        if (btnVakinha) btnVakinha.style.display = '';
        if (menuBtn) menuBtn.style.display = 'none';
        if (searchLabel) searchLabel.style.display = '';
      }
    }
    applyHeaderMobile();
    window.addEventListener('resize', applyHeaderMobile);
  })();
</script>

<?php
// RECONSTRUIR VARS QR CODE
$qrCodeBase64 = '';
if (!empty($pixResp['qrCodeBase64'])) {
    $qr = $pixResp['qrCodeBase64'];
    $qrCodeBase64 = (strpos($qr,'data:image/png;base64,')===0) ? substr($qr,22) : $qr;
} elseif (!empty($pixResp['qrCode'])) {
    // texto puro - ignorar imagem
    $qrCodeBase64 = '';
}
// NOVO: fallback direto dos campos da AureaPag
if (empty($qrCodeBase64) && !empty($pixResp['raw']['pix']['qr_code_base64'])) {
    $qr = $pixResp['raw']['pix']['qr_code_base64'];
    $qrCodeBase64 = (strpos($qr,'data:image/png;base64,')===0) ? preg_replace('#^data:image/\w+;base64,#','',$qr) : $qr;
}

// prefer explicit copyPaste, fallback para payUrl
$copyPasteCode = $pixResp['copyPasteCode'] ?? ($pixResp['payUrl'] ?? '');
// se houver qrCodeRaw (mapeado do provider) e copyPaste vazio, usar
if (empty($copyPasteCode) && !empty($pixResp['qrCodeRaw'])) {
    $copyPasteCode = $pixResp['qrCodeRaw'];
}
// NOVO: fallbacks AureaPag (EMV)
if (empty($copyPasteCode)) {
    $copyPasteCode = $pixResp['raw']['pix']['pix_qr_code']
        ?? ($pixResp['raw']['pix']['pix_url'] ?? $copyPasteCode);
}

// Gerar URL de imagem QR quando não houver imagem base64 mas houver texto EMV
$qrImageUrl = '';
if (empty($qrCodeBase64) && !empty($copyPasteCode)) {
    $qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=230x230&data=' . urlencode($copyPasteCode);
}

// expires ISO retornado pelo provider ou por nós
$expiresAtIso = $pixResp['expiresAt'] 
    ?? ($pixResp['raw']['transaction']['expirationDate'] ?? null) 
    ?? ($pixResp['raw']['pix']['expirationDate'] ?? null);

// se não veio do provider, usar fallback agora +15 minutos (garante UI consistente)
if (empty($expiresAtIso)) {
    $expiresAtIso = date('c', strtotime('+15 minutes'));
}

// converter timestamp (trata ms ou string ISO)
$expiresLabel = '';
$ts = null;
if (is_numeric($expiresAtIso)) {
    $s = (string)$expiresAtIso;
    $ts = (strlen($s) > 10) ? intval($s) / 1000 : intval($s);
} else {
    $ts = strtotime($expiresAtIso);
}
if ($ts && $ts > time()) {
    $mins = (int)ceil(($ts - time()) / 60);
    $expiresLabel = $mins . ' minutos';
} elseif ($ts) {
    $expiresLabel = date('d/m/Y H:i', $ts);
} else {
    $expiresLabel = $expiresAtIso;
}

// Mostrar bloco PIX se houver imagem OU código/copiar/link OU txid (protege casos onde provider retorna apenas txid)
?>
<?php if (!empty($qrCodeBase64) || !empty($copyPasteCode) || !empty($txid)): ?>
  <!-- BLOCO PIX PÓS-GERAÇÃO -->
  <div class="pix-payment-wrapper">
    <div class="pix-card pix-left">
      <div class="qr-badge">PIX</div>
      <?php if (!empty($qrCodeBase64)): ?>
        <img class="qr-img" src="data:image/png;base64,<?= $qrCodeBase64 ?>" alt="QR Code Pix">
      <?php elseif (!empty($qrImageUrl)): ?>
        <!-- Exibe imagem gerada a partir do texto EMV -->
        <img class="qr-img" src="<?= htmlspecialchars($qrImageUrl) ?>" alt="QR Code Pix">
      <?php else: ?>
        <div class="qr-img" style="display:flex;align-items:center;justify-content:center;font-weight:700;color:#00a64a;">
          Copia & Cola / Link disponível
        </div>
      <?php endif; ?>
      <button type="button" class="btn-copy-inline" onclick="copyPixCode()">Copiar código</button>
      <div class="pix-copia-mobile">
        <label>Pix Copia e Cola</label>
        <div class="code-row">
          <input type="text" value="<?= htmlspecialchars($copyPasteCode) ?>" readonly id="pix-copy-input-mobile">
          <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('pix-copy-input-mobile').value);this.innerText='Copiado!';setTimeout(()=>this.innerText='COPIAR',1500);">COPIAR</button>
        </div>
      </div>
      <div class="expires">Expira em: <?= htmlspecialchars($expiresLabel) ?></div>
    </div>
    <div class="pix-card pix-right">
      <h2 class="pix-title">Finalize seu pagamento</h2>
      <ul class="pix-steps" style="counter-reset:step;">
        <li>Acesse o app do seu banco.</li>
        <li>Escolha Pix > Ler QR Code.</li>
        <li>Escaneie a imagem ao lado.</li>
        <li>Confirme os dados e conclua.</li>
      </ul>
      <div class="pix-info-grid">
        <div><span>Vaquinha</span><strong><?= htmlspecialchars($campaign['title']) ?></strong></div>
        <div><span>ID</span><strong><?= $campaign['id'] ?></strong></div>
        <div><span>Transação</span><strong><?= htmlspecialchars($txid ?? $pixResp['txid'] ?? '') ?></strong></div>
        <div><span>Valor</span><strong><?= formatMoney($pixResp['amount'] ?? ($finalAmount ?? 0)) ?></strong></div>
        <!-- <div><span>E-mail</span><strong><?= htmlspecialchars($buyerEmail ?? $_POST['email'] ?? '') ?></strong></div> -->
        <div><span>Método</span><strong>Pix</strong></div>
      </div>
      <div class="pix-copia-cola">
        <label for="pix-copy-input">Copia e Cola</label>
        <div class="code-row">
          <input type="text" id="pix-copy-input" value="<?= htmlspecialchars($copyPasteCode) ?>" readonly>
          <button id="pix-copy-btn" type="button" onclick="copyPixCode()">COPIAR</button>
        </div>
      </div>
      <div id="order-status" class="pix-status <?= $orderStatus==='pago'?'pago':'' ?>">
        <?= $orderStatus==='pago' ? 'Pedido pago! Obrigado 🎉' : 'Aguardando pagamento...' ?>
      </div>
    </div>
  </div>
<?php elseif (!empty($pixError)): ?>
  <div style="color:#ff3b3b;font-weight:bold;margin:24px;text-align:center;">
    Erro ao gerar cobrança Pix: <?= htmlspecialchars($pixError) ?>
  </div>
<?php else: ?>
  <!-- FORMULÁRIO (SEM CAMPOS DE DADOS PESSOAIS) -->
  <div class="checkout-wrapper">
    <div class="checkout-inner">
      <div class="checkout-title"><strong><?= htmlspecialchars($campaign['title']) ?></strong></div>
      <div class="checkout-sub">ID <?= $campaign['id'] ?></div>
      <!-- LOADING OVERLAY -->
      <div id="pix-loading-overlay" style="display:none;position:fixed;z-index:99999;top:0;left:0;width:100vw;height:100vh;background:rgba(255,255,255,0.85);align-items:center;justify-content:center;flex-direction:column;">
        <div style="font-size:2.2em;color:#00c853;font-family:'Montserrat',Arial,sans-serif;font-weight:700;margin-bottom:18px;">
          Gerando Pix...
        </div>
        <div style="width:48px;height:48px;border:5px solid #e0e0e0;border-top:5px solid #00c853;border-radius:50%;animation:spin 1s linear infinite;"></div>
        <style>
          @keyframes spin { 100% { transform: rotate(360deg); } }
        </style>
      </div>
      <form method="post" novalidate action="<?= htmlspecialchars($selfUrlWithUtms) ?>">
        <input type="hidden" name="utms" id="utms" value="">
        <?php if (!empty($errors['rate'])): ?>
          <div class="error-message" style="margin-bottom:12px;"><?= htmlspecialchars($errors['rate']) ?></div>
        <?php endif; ?>
        <!-- VALOR DA CONTRIBUIÇÃO COM OPÇÕES PRÉ-SELECIONADAS -->
        <div class="checkout-section">
          <label for="contrib-value">Valor da contribuição</label>
          <div class="amount-input-wrapper" style="margin-bottom:10px;">
            <span class="currency-box">R$</span>
            <input type="text" id="contrib-value" name="contrib-value" placeholder="0,00" value="<?= htmlspecialchars($_POST['contrib-value'] ?? '0,00') ?>" inputmode="decimal" autocomplete="off" required>
          </div>
          <div class="quick-values-row" style="display:flex;flex-wrap:wrap;gap:10px 10px;margin-bottom:8px;">
            <?php
              $presetValues = [30, 50, 75, 100, 200, 500, 750, 1000];
              foreach ($presetValues as $val):
                if ($val < $minAmount || $val > $maxAmount) continue;
            ?>
              <button type="button" class="quick-value-btn" data-value="<?= number_format($val,2,',','.') ?>" style="flex:1 1 45%;min-width:120px;padding:12px 0;font-size:1.08em;border:1.5px solid #e0e0e0;border-radius:10px;background:#fff;font-weight:600;color:#222;cursor:pointer;transition:.15s;">
                R$ <?= number_format($val,2,',','.') ?>
              </button>
            <?php endforeach; ?>
          </div>
          <div class="limit-hint">Mínimo R$ <?= number_format($minAmount,2,',','.') ?> - Máximo R$ <?= number_format($maxAmount,2,',','.') ?></div>
          <div id="value-error" class="error-message" style="display:<?= isset($errors['contrib-value']) ? 'block' : 'none' ?>;">
            <?= htmlspecialchars($errors['contrib-value'] ?? ('Valor deve estar entre R$ ' . number_format($minAmount,2,',','.') . ' e R$ ' . number_format($maxAmount,2,',','.'))) ?>
          </div>
        </div>
        <script>
        // Valor rápido: ao clicar, preenche o campo e dispara evento de input
        document.addEventListener('DOMContentLoaded', function(){
          var btns = document.querySelectorAll('.quick-value-btn');
          var input = document.getElementById('contrib-value');
          btns.forEach(function(btn){
            btn.addEventListener('click', function(){
              var v = btn.getAttribute('data-value');
              if(input){
                input.value = v;
                input.dispatchEvent(new Event('input', {bubbles:true}));
                input.dispatchEvent(new Event('blur', {bubbles:true}));
                input.focus();
              }
              // destaque visual
              btns.forEach(b=>b.style.borderColor='#e0e0e0');
              btn.style.borderColor='#00c853';
            });
          });
        });
        </script>
        <!-- ===== NOVO: CAMPOS DE DADOS PESSOAIS (OCULTOS) ===== -->
        <div class="checkout-section" style="display:none;">
          <label>Nome</label>
          <input type="text" name="nome" value="<?= htmlspecialchars($buyerName) ?>" required>
          <label>E-mail</label>
          <input type="email" name="email" value="<?= htmlspecialchars($buyerEmail) ?>" required>
          <label>CPF</label>
          <input type="text" name="cpf" value="<?= htmlspecialchars($buyerCpf) ?>" required>
          <label>WhatsApp</label>
          <input type="text" name="whatsapp" value="<?= htmlspecialchars($buyerPhone) ?>" required>
        </div>
        <!-- ===== /novos campos ocultos ===== -->
        <div class="checkout-section">
          <label>Forma de pagamento</label>
          <div class="checkout-payment-options">
            <button type="button" class="checkout-payment-btn active">Pix</button>
          </div>
        </div>
        <div class="checkout-extras">
          <!-- removido checkbox único; cada item terá seu próprio checkbox -->
          <div class="checkout-extras-items">
            <div class="checkout-extras-item" style="align-items:center;">
              <input type="checkbox" class="extra-checkbox" id="extra-luck" name="extra_luck" data-amount="10.99" style="margin-right:10px;">
              <img src="luck.png" alt="Sorte" class="extras-icon">
              <div class="extras-content">
                <div class="extras-title">3 números da sorte</div>
                <div class="extras-desc">Você e quem criou a vaquinha concorrem ao sorteio de R$ 15.000,00 do Vakinha Premiada</div>
              </div>
              <div class="extras-price">R$ 10,99</div>
            </div>
            <div class="checkout-extras-item" style="align-items:center;">
              <input type="checkbox" class="extra-checkbox" id="extra-heart" name="extra_heart" data-amount="24.90" style="margin-right:10px;">
              <img src="cora.png" alt="Corações" class="extras-icon">
              <div class="extras-content">
                <div class="extras-title">3 Corações</div>
                <div class="extras-desc">Destacam essa vaquinha na plataforma</div>
              </div>
              <div class="extras-price">R$ 24,90</div>
            </div>
            <div class="checkout-extras-item" style="align-items:center;">
              <input type="checkbox" class="extra-checkbox" id="extra-cause" name="extra_cause" data-amount="58.90" style="margin-right:10px;">
              <img src="doar.png" alt="Tempo de Doar" class="extras-icon">
              <div class="extras-content">
                <div class="extras-title">Vakinha além do Câncer</div>
                <div class="extras-desc">Você ajuda crianças no tratamento do câncer infantil a terem acolhimento e experiências mágicas</div>
              </div>
              <div class="extras-price">R$ 58,90</div>
            </div>
          </div>
          <div class="checkout-extras-row" style="margin-top:8px;">
            <span class="checkout-extras-label" style="background:#00c853;color:#fff;padding:6px 10px;border-radius:10px;">TURBINE SUA DOAÇÃO</span>
            <span id="extras-total" class="checkout-extras-total"></span>
          </div>
        </div>
        <div class="checkout-summary">
          Contribuição: <span id="contrib-summary">R$ 0,00</span>
        </div>
        <div class="checkout-total-row">
          Total: <span id="contrib-total">R$ 0,00</span>
        </div>
        <div class="checkout-checkbox-row">
          <input type="checkbox" id="updates">
          <label for="updates">Quero receber atualizações desta vaquinha e de outras iniciativas.</label>
        </div>
        <button type="submit" class="checkout-btn">CONTRIBUIR</button>
        <div class="checkout-security">
          <img src="selo.png" alt="Selo de segurança" class="security-img">
          <span class="security-label">Garantimos uma experiência segura para todos os nossos doadores.</span>
        </div>
        <div class="checkout-terms">
          Ao clicar no botão acima você declara que é maior de 18 anos e concorda com os Termos.
        </div>
      </form>
      <!-- SCRIPT UTM (RESTAURADO MINIMAL) -->
      <script>
      (function(){
        function getCookie(name){
          var m = document.cookie.match(new RegExp('(^|; )'+name+'=([^;]*)'));
          return m ? decodeURIComponent(m[2]) : null;
        }
        function setCookie(name,value,days){
          var d=new Date();d.setTime(d.getTime()+((days||365)*24*60*60*1000));
          document.cookie = name + "=" + encodeURIComponent(value) + ";path=/;expires=" + d.toUTCString();
        }

        var params = new URLSearchParams(location.search);
        var keys = ['utm_source','utm_campaign','utm_medium','utm_content','utm_term','fbclid','fbc','fbp','ttclid'];
        var data = {};
        keys.forEach(function(k){ if(params.has(k)) data[k] = params.get(k); });

        // Ler cookies Facebook existentes
        var fbp = getCookie('_fbp') || data['fbp'] || null;
        var fbc = getCookie('_fbc') || data['fbc'] || null;

        // Se não existir _fbc mas houver fbclid, sintetiza um _fbc padrão e persiste
        if(!fbc && params.has('fbclid')){
          fbc = 'fb.1.' + Math.floor(Date.now()/1000) + '.' + params.get('fbclid');
          setCookie('_fbc', fbc, 365);
        }

        if(fbp) data['fbp'] = fbp;
        if(fbc) data['fbc'] = fbc;

        // TikTok: mantém ttclid entre páginas/sessões e inclui _ttp para Events API
        if (params.has('ttclid')) {
          var ttclidParam = params.get('ttclid');
          if (ttclidParam) {
            data['ttclid'] = ttclidParam;
            setCookie('ttclid', ttclidParam, 30);
          }
        }
        var ttclidCookie = getCookie('ttclid');
        if (!data['ttclid'] && ttclidCookie) data['ttclid'] = ttclidCookie;

        var ttp = getCookie('_ttp');
        if (ttp) data['ttp'] = ttp;

        try{ localStorage.setItem('vakinha_utms', JSON.stringify(data)); }catch(e){}
        var el=document.getElementById('utms'); if(el) el.value = JSON.stringify(data);
      })();
           </script>
      <script>
        // Exibe overlay de loading ao enviar o formulário
        (function(){
          var form = document.querySelector('form');
          var overlay = document.getElementById('pix-loading-overlay');
          if(form && overlay){
            form.addEventListener('submit', function(ev){
              var input = document.getElementById('contrib-value');
              var err = document.getElementById('value-error');
              // Só mostra loading se não houver erro de valor
              var base = input ? parseFloat(input.value.replace(',','.')) : 0;
              if (!err || err.style.display === 'none') {
                overlay.style.display = 'flex';
              }
            });
          }
        })();
      </script>
    </div> <!-- /.checkout-inner -->
  </div> <!-- /.checkout-wrapper -->
<?php endif; ?>
  <?php if (!empty($txid)): ?>
  <script>
    (function(){
      var txid = <?= json_encode($txid) ?>;
      var orderAmount = <?= json_encode(isset($orderAmountForPixel) ? (float)$orderAmountForPixel : null) ?>;
      var campaignId = <?= json_encode('campaign_' . (string)$campaign['id']) ?>;
      var campaignTitle = <?= json_encode((string)$campaign['title']) ?>;
      var pollInterval = 3000; // ms
      var maxAttempts =  120; // opcional, para não pollar indefinidamente
      var attempts = 0;
      var timer = null;

      function fireTiktokPurchaseOnce() {
        if (typeof window.ttq === 'undefined' || typeof window.ttq.track !== 'function') {
          console.warn('[TT PIXEL] ttq indisponivel para Purchase', { txid: txid });
          return;
        }

        var dedupeKey = 'tt_purchase_sent_' + txid;
        try {
          if (localStorage.getItem(dedupeKey) === '1') {
            console.log('[TT PIXEL] Purchase ja enviado (dedupe)', { txid: txid });
            return;
          }
        } catch (e) {}

        var payload = {
          content_type: 'product',
          content_id: campaignId,
          content_name: campaignTitle,
          currency: 'BRL',
          value: orderAmount !== null ? Number(orderAmount) : undefined,
          contents: [{
            content_id: campaignId,
            content_name: campaignTitle,
            quantity: 1,
            price: orderAmount !== null ? Number(orderAmount) : undefined
          }]
        };

        if (payload.value === undefined) delete payload.value;
        if (payload.contents[0].price === undefined) delete payload.contents[0].price;

        try {
          window.ttq.track('Purchase', payload);
          try {
            var bodyObj = {
              source: 'checkout',
              pixel_id: <?= json_encode((string)$campaign['tiktok_pixel_id']) ?>,
              event: 'Purchase',
              payload: payload,
              txid: txid,
              page: window.location.href,
              ts_client: new Date().toISOString()
            };
            var body = JSON.stringify(bodyObj);
            if (navigator.sendBeacon) {
              navigator.sendBeacon('api/tiktok_pixel_log.php', new Blob([body], { type: 'application/json' }));
            } else {
              fetch('api/tiktok_pixel_log.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: body,
                keepalive: true
              });
            }
          } catch (e) {}
          localStorage.setItem(dedupeKey, '1');
          console.log('[TT PIXEL] Purchase disparado', { txid: txid, payload: payload });
        } catch (e) {
          console.error('[TT PIXEL] Erro ao disparar Purchase', { txid: txid, error: e && e.message ? e.message : e });
        }
      }

      function updateUiPaid() {
        var el = document.getElementById('order-status');
        if (!el) return;
        el.style.color = 'green';
        el.innerHTML = 'Pedido pago! Obrigado pela sua contribuição 🎉<div style="margin-top:10px;color:#222;font-weight:600;">Seu pagamento foi confirmado. Você receberá um e-mail de agradecimento.</div>';
        fireTiktokPurchaseOnce();
      }

      function checkStatus() {
        attempts++;
        fetch('api/order_status.php?txid=' + encodeURIComponent(txid), { cache: 'no-store' })
          .then(function(r){ return r.json(); })
          .then(function(json){
            if (!json || !json.ok) return;
            if (json.status === 'pago') {
              // atualiza UI e para polling
              updateUiPaid();
              if (timer) clearInterval(timer);
            } else {
              // mantém aguardando (poderia atualizar contador/tempo)
              var el = document.getElementById('order-status');
              if (el) el.innerText = 'Aguardando pagamento...';
            }
          })
          .catch(function(){ /* silencioso */ });

        if (attempts >= maxAttempts && timer) {
          clearInterval(timer);
        }
      }

      // se já estiver pago no backend (renderizado), não polla
      <?php if ($orderStatus !== 'pago'): ?>
        timer = setInterval(checkStatus, pollInterval);
        // primeira verificação imediata
        checkStatus();
      <?php endif; ?>
    })();
  </script>
  <?php endif; ?>
  <script>
  (function(){
    try {
      var stored = localStorage.getItem('vakinha_utms');
      function getCookie(name){
        var m = document.cookie.match(new RegExp('(^|; )'+name+'=([^;]*)'));
        return m ? decodeURIComponent(m[2]) : null;
      }
      var obj = {};
      if (stored) {
        obj = JSON.parse(stored);
      } else {
        var params = new URLSearchParams(window.location.search);
        var keys = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','fbclid','fbc','fbp','ttclid'];
        keys.forEach(function(k){ if (params.has(k)) obj[k] = params.get(k); });
      }
      // garantir leitura de cookies _fbp/_fbc
      var fbp = getCookie('_fbp');
      var fbc = getCookie('_fbc');
      var ttp = getCookie('_ttp');
      var ttclid = getCookie('ttclid');
      if (!obj.fbp && fbp) obj.fbp = fbp;
      if (!obj.fbc && fbc) obj.fbc = fbc;
      if (!obj.ttp && ttp) obj.ttp = ttp;
      if (!obj.ttclid && ttclid) obj.ttclid = ttclid;
      if (Object.keys(obj).length) {
        document.getElementById('utms').value = JSON.stringify(obj);
        try { localStorage.setItem('vakinha_utms', JSON.stringify(obj)); }catch(e){}
      }
    } catch(e){}
  })();
  </script>
  <script>
    // ===== Script do campo de valor (ATUALIZADO) =====
    (function(){
      var input = document.getElementById('contrib-value');
      var err = document.getElementById('value-error');
      var summary = document.getElementById('contrib-summary');
      var total = document.getElementById('contrib-total');
      var extrasTotalSpan = document.getElementById('extras-total');

      var minAmount = <?= json_encode($minAmount) ?>;
      var maxAmount = <?= json_encode($maxAmount) ?>;

      function parseBRL(str){
        if(!str) return 0;
        str = str.replace(/[^\d,]/g,'').replace(/\./g,'')
        if(str.indexOf(',') === -1) str = str + ',00';
        var parts = str.split(',');
        var ints = parts[0].replace(/^0+(?=\d)/,'') || '0';
        var dec = (parts[1] || '00').slice(0,2);
        var num = parseFloat(ints + '.' + dec);
        return isNaN(num)?0:num;
      }
      function formatBRL(n){
        n = (Math.round(n*100)/100).toFixed(2).replace('.',',');

        var parts = n.split(',');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g,'.');
        return 'R$ '+parts.join(',');
      }
      function inRange(v){ return v >= minAmount && v <= maxAmount; }

      function sumExtras(){
        var extras = 0;
        var chks = document.querySelectorAll('.extra-checkbox');
        chks.forEach(function(c){
          if(c.checked){
            var a = parseFloat(c.getAttribute('data-amount')) || 0;
            extras += a;
          }
        });
        return extras;
      }

      function update(){
        var base = parseBRL(input.value);
        var ext = sumExtras();
        summary && (summary.textContent = formatBRL(base));
        total && (total.textContent = formatBRL(base + ext));
        if (extrasTotalSpan){
          extrasTotalSpan.textContent = ext ? '+' + formatBRL(ext) : '';
        }
        if(!inRange(base)){
          err.style.display='block';
          input.classList.add('input-error');
        } else {
          err.style.display='none';
          input.classList.remove('input-error');
        }
        // destaque visual das cards selecionadas
        document.querySelectorAll('.checkout-extras-item').forEach(function(item){
          var chk = item.querySelector('.extra-checkbox');
          if (chk && chk.checked) {
            item.style.boxShadow = '0 6px 18px rgba(0,200,83,0.08)';
            item.style.border = '1px solid #cfefd9';
          } else {
            item.style.boxShadow = '0 1px 4px rgba(0,0,0,0.04)';
            item.style.border = 'none';
          }
        });
      }

      input && input.addEventListener('input', function(){
        var raw = this.value.replace(/[^\d,]/g,'')
        var c = raw.indexOf(',');
        if(c !== -1){
          raw = raw.slice(0,c+1)+raw.slice(c+1).replace(/,/g,'')
        }
        this.value = raw;
        update();
      });
      input && input.addEventListener('blur', function(){
        var v = parseBRL(this.value);
        this.value = formatBRL(v).replace('R$ ','')
        update();
      });

      document.querySelectorAll('.extra-checkbox').forEach(function(chk){
        chk.addEventListener('change', update);
      });

      var form = document.querySelector('form');
      if(form){
        form.addEventListener('submit', function(ev){
          var base = parseBRL(input.value);
          if(!inRange(base)){
            ev.preventDefault();
            err.style.display='block';
            input.classList.add('input-error');
            input.focus();
          } else {
            // envia base em formato 0,00
            input.value = base.toFixed(2).replace('.',',');

          }
        });
      }
      update();
    })();
  </script>

  <!-- === TOAST DE COMPRAS (widget) === -->
  <div class="elementor-widget-container">
    <style>
      :root { --cor-de-destaque: #f00; }
      #views b, #vagas b { color: var(--cor-de-destaque); }
      #vagas{ display: none; }
      #views { max-width: 800px; margin: auto; line-height: 1.4; }
      #toast-compras {
        position: fixed; right: 20px; bottom: 20px; z-index: 1000;
        background-color: #FFF; padding: 10px 20px; display: flex; align-items: center; justify-content: center; gap: 20px;
        border-radius: 5px; box-shadow: 1px 1px 5px #00000036;
        transform: translateY(calc(100% + 40px)); transition: all .3s ease; opacity: 0
      }
      #toast-compras.show { transform: none; opacity: 1 }
      #toast-compras * { font-family: "Roboto", sans-serif }
      #toast-compras svg { width: 40px; height: 40px; fill: #11F849; border: 3px solid #11F849; padding: 5px; border-radius: 100px }
      #toast-compras .textos .linha-1 { display: flex; gap: 20px; align-items: center; margin: 0 0 5px }
      #toast-compras .textos .linha-1 .nome { font-size: 16px; font-weight: 500; line-height: 16px; color: #1b1b1b; margin: 0 }
      #toast-compras .textos .linha-1 .tempo { font-size: 14px; font-weight: 400; line-height: 14px; color: #7a7a7a; margin: 0 }
      #toast-compras .textos .pagamento { font-size: 16px; line-height: 1; color: #7a7a7a; margin: 0 }
    </style>
    <div id="toast-compras" class="">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 96.01 447.98 319.97">
        <path d="M438.6 105.4C451.1 117.9 451.1 138.1 438.6 150.6L182.6 406.6C170.1 419.1 149.9 419.1 137.4 406.6L9.372 278.6C-3.124 266.1-3.124 245.9 9.372 233.4C21.87 220.9 42.13 220.9 54.63 233.4L159.1 338.7L393.4 105.4C405.9 92.88 426.1 92.88 438.6 105.4H438.6z"></path>
      </svg>
      <div class="textos">
        <div class="linha-1">
          <p class="nome">Raquel Silveira</p>
          <p class="tempo">há 22 minutos</p>
        </div>
        <p class="pagamento">Doou no pix</p>
      </div>
    </div>
    <script>
      const delayEmSegundosVagas = 1;
      const delayEmSegundosCompras = 1;
      const manterVagasVisiveis = false;
      const compras = [
        { nome: "Vanessa R.", tempo: "45 minutos", pagamento: "pix" },
        { nome: "Raquel Silveira", tempo: "22 minutos", pagamento: "pix" },
        { nome: "Roberta Cavalcante", tempo: "32 minutos", pagamento: "pix" },
        { nome: "Ana R.", tempo: "12 minutos", pagamento: "Pix" },
        { nome: "Marcela F.", tempo: "51 minutos", pagamento: "cartão de débito" },
        { nome: "Juliana D.", tempo: "37 minutos", pagamento: "pix" }
      ];
      setTimeout(() => { configurarVagas(); }, delayEmSegundosVagas * 1000)
      setTimeout(() => { mostrarToast(); }, delayEmSegundosCompras * 1000)
      document.addEventListener('DOMContentLoaded', function(){
        inserirData();
        configurarViews();
        if(manterVagasVisiveis) exibirVagas();
      });
      function inserirLocalizacao() {
        fetch("https://wtfismyip.com/json").then(res => res.json().then((data) => {
          const cidade = data.YourFuckingLocation.split(',')[0];
          document.querySelector('span#address').innerHTML = `de <b>${cidade}</b>`;
        }))
      }
      function exibirVagas(){ document.querySelector('#vagas').style.display = "block"; }
      function configurarVagas() {
        exibirVagas();
        const vagasInterval = setInterval(() => {
          const vagasElement = document.querySelector('#vagas b')
          const vagas = Number(vagasElement.innerText);
          if(vagas >= 100) {
            vagasElement.innerText = vagas - randomNumber(3, 8);
          } else if(vagas >= 30) {
            vagasElement.innerText = vagas - randomNumber(1, 3);
          } else if(vagas >= 4) {
            vagasElement.innerText = vagas - 1;
          } else {
            clearInterval(vagasInterval);
          }
        }, randomNumber(4, 8) * 1000);
      }
      function configurarViews() {
        const viewsInterval = setInterval(() => {
          const viewsElement = document.querySelector('#views b.qtd');
          const views = Number(viewsElement.innerText);
          if(views <= 500) {
            viewsElement.innerText = views + randomNumber(3, 8);
          } else if(views >= 2300) {
            viewsElement.innerText = views + randomNumber(-5, 5);
          } else {
           
            viewsElement.innerText = views + randomNumber(-3, 12);
          }
        }, 5 * 1000);
      }
      function mostrarToast() {
        configurarToast()
        document.querySelector('#toast-compras').classList.add('show');
        setTimeout(() => {
          document.querySelector('#toast-compras').classList.remove('show');
          setTimeout(() => { mostrarToast(); }, randomNumber(5, 10) * 1000)
        }, 2500);
      }
      function configurarToast() {
        document.querySelector('#toast-compras .nome').innerText = compras[0].nome;
        document.querySelector('#toast-compras .tempo').innerText = `há ${compras[0].tempo}`;
        document.querySelector('#toast-compras .pagamento').innerText = `Doou no ${compras[0].pagamento}`;
        compras.push(compras.shift());
      }
      function randomNumber(min, max) {
        min = Math.ceil(min);
        max = Math.floor(max);
        return Math.floor(Math.random() * (max - min + 1)) + min;
      }
      function inserirData() {
        const data = new Date().toLocaleDateString();
        const el = document.querySelector('#views b.date');
        if (el) el.innerText = data;
      }
    </script>
  </div>
</body>
</html>
