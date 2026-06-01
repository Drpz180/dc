<?php
// Endpoint para receber o postback/check-payment do gateway e atualizar pedido Pix
require_once __DIR__ . '/../../app/Database.php';

header('Content-Type: application/json; charset=utf-8');

// === ensure local timezone and ISO formatting ===
date_default_timezone_set('America/Sao_Paulo');
function iso8601Local($ts = null) {
    try {
        if (is_string($ts) && trim($ts) !== '') {
            $dt = new DateTime($ts, new DateTimeZone('America/Sao_Paulo'));
        } else {
            $dt = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        }
        return $dt->format('c'); // ISO‑8601 with offset, e.g. 2025-12-24T01:49:01-03:00
    } catch (\Throwable $e) {
        return date('c');
    }
}

function normalizePaymentStatus(?string $status): ?string {
    if ($status === null) return null;
    $s = strtolower(trim($status));
    if ($s === '') return null;

    if (in_array($s, ['pago','paid','approved','concluded','completed','paid_out','success'], true)) {
        return 'pago';
    }
    if (in_array($s, ['pendente','pending','waiting_payment','created','waiting','unpaid'], true)) {
        return 'pendente';
    }
    if (in_array($s, ['reembolsado','refunded','refund'], true)) {
        return 'reembolsado';
    }
    return $s;
}

// Aceita GET ou POST (gateway pode enviar ambos)
$txid = $_POST['txid'] ?? $_GET['txid'] ?? null;
$status = $_POST['status'] ?? $_GET['status'] ?? null;
$endToEndId = null;
$paidAtIso  = null;
$rawStatusIn = $status;

// Se vier JSON no corpo (Umbrella/AtivoPay/AureaPag), decodifica e extrai campos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (is_array($payload)) {
        // Umbrella envia objectId como id da transação
        if (empty($txid) && !empty($payload['objectId'])) {
            $txid = $payload['objectId'];
        }
        $dataBlock = $payload['data'] ?? [];
        $statusUmb = strtolower($dataBlock['status'] ?? '');
        $map = ['paid' => 'pago', 'waiting_payment' => 'pendente', 'refunded' => 'reembolsado'];
        if (empty($status) && isset($map[$statusUmb])) {
            $status = $map[$statusUmb];
        }
        $endToEndId = $dataBlock['endToEndId'] ?? null;
        $paidAtIso  = $dataBlock['paidAt'] ?? null;

        // AureaPag: mapeia hash/status/pix
        if (empty($txid) && !empty($payload['hash'])) {
            $txid = $payload['hash'];
        }
        // NOVO: usa payment_status quando presente
        if (empty($status)) {
            $ps = strtolower($payload['payment_status'] ?? '');
            $st = strtolower($payload['status'] ?? '');
            if (in_array($ps, ['paid','approved','concluded','pago','completed']) || in_array($st, ['paid','approved','concluded','pago','completed'])) {
                $status = 'pago';
            } elseif ($ps || $st) {
                // waiting_payment, pending, etc.
                $status = 'pendente';
            }
        }
        $endToEndId = $payload['pix']['end_to_end_id'] ?? ($payload['end_to_end_id'] ?? $endToEndId);
        $paidAtIso  = $payload['paid_at'] ?? ($payload['approved_at'] ?? $paidAtIso);

        @file_put_contents(__DIR__ . '/../../pix_api_debug.log', date('Y-m-d H:i:s') . " [WEBHOOK RAW] txid={$txid} body=" . $raw . "\n", FILE_APPEND);
    }
}

// Consulta status do pagamento se não veio no payload
if ($txid && !$status) {
    require_once __DIR__ . '/../../app/PixGateway.php';
    try {
        $resp = \PixGateway::getStatus($txid);
        $status = (!empty($resp['paid']) && $resp['paid']) ? 'pago' : 'pendente';
    } catch (\Throwable $e) {
        $status = null;
    }
}

$status = normalizePaymentStatus(is_string($status) ? $status : null);
@file_put_contents(
    __DIR__ . '/../../pix_api_debug.log',
    date('Y-m-d H:i:s') . " [WEBHOOK STATUS] txid={$txid} in=" . json_encode($rawStatusIn) . " normalized=" . json_encode($status) . "\n",
    FILE_APPEND
);

// Após registrar pedido pendente (PIX gerado), envie eventos de inicialização de compra e venda pendente
if ($txid && $status === 'pendente') {
    $pdo = \Database::getConnection();
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE txid = :txid LIMIT 1");
    $stmt->execute(['txid' => $txid]);
    $order = $stmt->fetch();

    // Busca campanha associada
    $campaign = null;
    if (!empty($order['campaign_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $order['campaign_id']]);
        $campaign = $stmt->fetch();
    }

    // Extrai utms
    $orderPayload = [];
    if (!empty($order['webhook_payload'])) {
        $decoded = json_decode($order['webhook_payload'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            if (isset($decoded['utm']) && is_array($decoded['utm'])) {
                $orderPayload = $decoded['utm'];
            } else {
                $orderPayload = $decoded;
            }
        }
    }

    // prepara diretório de logs
    $logsDir = realpath(__DIR__ . '/../../logs') ?: (__DIR__ . '/../../logs');
    if (!is_dir($logsDir)) @mkdir($logsDir, 0775, true);

    // Utmify: status waiting_payment
    if ($campaign && !empty($campaign['utmify_api_token'])) {
        $utmToken = $campaign['utmify_api_token'];
        $utmEndpoint = 'https://api.utmify.com.br/api-credentials/orders';

        // Monta products
        $products = [];
        if (!empty($order['items'])) {
            $items = json_decode($order['items'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($items)) {
                foreach ($items as $it) {
                    $products[] = [
                        'id' => $it['externalRef'] ?? ($it['id'] ?? uniqid('p_')),
                        'name' => $it['title'] ?? ($it['name'] ?? 'Produto'),
                        'planId' => $it['planId'] ?? null,
                        'planName' => $it['planName'] ?? null,
                        'quantity' => isset($it['quantity']) ? (int)$it['quantity'] : 1,
                        'priceInCents' => (int)round((($it['unitPrice'] ?? ($it['price'] ?? 0)) * 100))
                    ];
                }
            }
        }
        if (empty($products)) {
            $products[] = [
                'id' => $txid . '_item',
                'name' => 'Ebook',
                'planId' => null,
                'planName' => null,
                'quantity' => 1,
                'priceInCents' => (int)round(($order['amount'] ?? 0) * 100)
            ];
        }

        // Monta trackingParameters
        $trackingParameters = [
            'src' => $orderPayload['src'] ?? null,
            'sck' => $orderPayload['sck'] ?? null,
            'utm_source' => $orderPayload['utm_source'] ?? ($orderPayload['utmSource'] ?? null),
            'utm_campaign' => $orderPayload['utm_campaign'] ?? ($orderPayload['utmCampaign'] ?? null),
            'utm_medium' => $orderPayload['utm_medium'] ?? ($orderPayload['utmMedium'] ?? null),
            'utm_content' => $orderPayload['utm_content'] ?? ($orderPayload['utmContent'] ?? null),
            'utm_term' => $orderPayload['utm_term'] ?? ($orderPayload['utmTerm'] ?? null)
        ];

        // Monta customer
        $country = strtoupper($orderPayload['country_code'] ?? ($orderPayload['country'] ?? 'BR'));
        if (empty($country) || strlen($country) !== 2) $country = 'BR';

        $customer = [
            'name' => $order['buyer_name'] ?? null,
            'email' => $order['buyer_email'] ?? null,
            'phone' => $order['buyer_phone'] ?? ($order['buyer_phone'] ?? null),
            'document' => $order['buyer_cpf'] ?? null,
            'country' => $country,
            'ip' => $orderPayload['user_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null)
        ];

        // Monta commission
        $totalCents = (int)round(($order['amount'] ?? 0) * 100);
        // Exemplo: gatewayFeeInCents = 1 real + 3% do valor
        $gatewayFeeInCents = 100 + (int)round($totalCents * 0.03);
        $userCommissionInCents = $totalCents - $gatewayFeeInCents;

        $commission = [
            'totalPriceInCents' => $totalCents,
            'gatewayFeeInCents' => $gatewayFeeInCents,
            'userCommissionInCents' => $userCommissionInCents,
            'currency' => 'BRL'
        ];

        $utmBody = [
            'orderId' => (string)$txid,
            'platform' => 'VakinhaClone',
            'paymentMethod' => 'pix',
            'status' => 'waiting_payment',
            // FIX: send ISO‑8601 with timezone
            'createdAt' => iso8601Local($order['created_at'] ?? null),
            'approvedDate' => null,
            'refundedAt' => null,
            'customer' => $customer,
            'products' => $products,
            'trackingParameters' => $trackingParameters,
            'commission' => $commission,
            'isTest' => false
        ];

        @file_put_contents($logsDir . '/utmify_events.log', date('Y-m-d H:i:s') . " [UTMIFY SEND] txid={$txid} endpoint={$utmEndpoint} body=" . json_encode($utmBody) . PHP_EOL, FILE_APPEND);

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

        @file_put_contents($logsDir . '/utmify_events.log', date('Y-m-d H:i:s') . " [UTMIFY RESP] txid={$txid} http_code={$codeU} err={$errU} resp=" . substr($respU,0,1000) . PHP_EOL, FILE_APPEND);
    }

    // Facebook Pixel: evento InitiateCheckout server-side
    if ($campaign && !empty($campaign['facebook_pixel_id']) && !empty($campaign['facebook_access_token'])) {
        $pixelId = $campaign['facebook_pixel_id'];
        $accessToken = $campaign['facebook_access_token'];
        $eventTime = time();
        $value = (float)($order['amount'] ?? 0);
        $currency = 'BRL';

        $user_data = [];
        $email = strtolower(trim($order['buyer_email'] ?? ''));
        if (!empty($email)) $user_data['em'] = hash('sha256', $email);
        if (!empty($orderPayload['fbc'])) $user_data['fbc'] = $orderPayload['fbc'];
        if (!empty($orderPayload['fbp'])) $user_data['fbp'] = $orderPayload['fbp'];
        $client_ip = $orderPayload['user_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        $client_ua = $orderPayload['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
        if (!empty($client_ip)) $user_data['client_ip_address'] = $client_ip;
        if (!empty($client_ua)) $user_data['client_user_agent'] = $client_ua;

        $custom_data = [
            'currency' => $currency,
            'value'    => $value,
            'utm_params' => $orderPayload
        ];

        $event = [
            'data' => [[
                'event_name' => 'InitiateCheckout',
                'event_time' => $eventTime,
                'user_data'  => $user_data,
                'custom_data'=> $custom_data,
                'event_source_url' => $_SERVER['HTTP_REFERER'] ?? null,
                'action_source' => 'website'
            ]]
        ];

        $fbUrl = 'https://graph.facebook.com/v13.0/' . urlencode($pixelId) . '/events?access_token=' . urlencode($accessToken);
        @file_put_contents($logsDir . '/facebook_events.log', date('Y-m-d H:i:s') . " [FB SEND] txid={$txid} url={$fbUrl} payload=" . json_encode($event) . PHP_EOL, FILE_APPEND);

        $ch = curl_init($fbUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($event),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        @file_put_contents($logsDir . '/facebook_events.log', date('Y-m-d H:i:s') . " [FB RESP] txid={$txid} http_code={$code} err={$err} resp=" . substr($resp,0,1000) . PHP_EOL, FILE_APPEND);
    }

    http_response_code(200);
    echo json_encode(['ok' => true, 'txid' => $txid, 'status' => 'pendente']);
    exit;
} elseif ($txid && $status === 'pago') {
    $pdo = \Database::getConnection();
    // atualiza pedido como pago com paid_at do postback (se existir)
    $paidAtDb = $paidAtIso ? date('Y-m-d H:i:s', strtotime($paidAtIso)) : date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("UPDATE orders SET status='pago', paid_at=:paid_at WHERE txid=:txid");
    $stmt->execute(['paid_at' => $paidAtDb, 'txid' => $txid]);

    // busca pedido completo
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE txid = :txid LIMIT 1");
    $stmt->execute(['txid' => $txid]);
    $order = $stmt->fetch();

    // persiste detalhes do postback Umbrella em webhook_payload (auditoria)
    try {
        $wp = [];
        if (!empty($order['webhook_payload'])) {
            $tmp = json_decode($order['webhook_payload'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $wp = $tmp;
        }
        $wp['umbrella_postback'] = [
            'status'     => 'pago',
            'endToEndId' => $endToEndId,
            'paidAt'     => $paidAtIso
        ];
        $stmt = $pdo->prepare("UPDATE orders SET webhook_payload=:wp WHERE txid=:txid");
        $stmt->execute(['wp' => json_encode($wp), 'txid' => $txid]);
    } catch (\Throwable $e) {
        // silencioso
    }

    // log básico
    file_put_contents(__DIR__ . '/../../pix_api_debug.log', date('Y-m-d H:i:s') . " [WEBHOOK] txid={$txid} updated to pago endToEndId=" . ($endToEndId ?? 'null') . "\n", FILE_APPEND);

    // Busca campanha associada (se existir)
    $campaign = null;
    if (!empty($order['campaign_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $order['campaign_id']]);
        $campaign = $stmt->fetch();
    }

    // Extrai utms armazenadas no webhook_payload (se houver)
    $orderPayload = [];
    if (!empty($order['webhook_payload'])) {
        $decoded = json_decode($order['webhook_payload'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // pode vir { "utm": { ... } } ou raw utm object
            if (isset($decoded['utm']) && is_array($decoded['utm'])) {
                $orderPayload = $decoded['utm'];
            } else {
                $orderPayload = $decoded;
            }
        }
    }

    // prepara diretório de logs
    $logsDir = realpath(__DIR__ . '/../../logs') ?: (__DIR__ . '/../../logs');
    if (!is_dir($logsDir)) @mkdir($logsDir, 0775, true);

    // 1) Dispara evento Facebook Conversions API (server-side) se campaign config completa
    if ($campaign && !empty($campaign['facebook_pixel_id']) && !empty($campaign['facebook_access_token'])) {
        $pixelId = $campaign['facebook_pixel_id'];
        $accessToken = $campaign['facebook_access_token'];
        $eventTime = time();
        $value = (float)($order['amount'] ?? 0);
        $currency = 'BRL';

        // user_data: inclui hash de email, fbc, fbp, client_ip_address, client_user_agent
        $user_data = [];
        $email = strtolower(trim($order['buyer_email'] ?? ''));
        if (!empty($email)) {
            $user_data['em'] = hash('sha256', $email);
        }
        if (!empty($orderPayload['fbc'])) $user_data['fbc'] = $orderPayload['fbc'];
        if (!empty($orderPayload['fbp'])) $user_data['fbp'] = $orderPayload['fbp'];
        // Facebook exige client_ip_address e client_user_agent dentro de user_data
        $client_ip = $orderPayload['user_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        $client_ua = $orderPayload['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
        if (!empty($client_ip)) $user_data['client_ip_address'] = $client_ip;
        if (!empty($client_ua)) $user_data['client_user_agent'] = $client_ua;

        $custom_data = [
            'currency' => $currency,
            'value'    => $value,
            'utm_params' => $orderPayload
        ];

        $event = [
            'data' => [[
                'event_name' => 'Purchase',
                'event_time' => $eventTime,
                'user_data'  => $user_data,
                'custom_data'=> $custom_data,
                'event_source_url' => $_SERVER['HTTP_REFERER'] ?? null,
                'action_source' => 'website'
            ]]
        ];

        $fbUrl = 'https://graph.facebook.com/v13.0/' . urlencode($pixelId) . '/events?access_token=' . urlencode($accessToken);
        @file_put_contents($logsDir . '/facebook_events.log', date('Y-m-d H:i:s') . " [FB SEND] txid={$txid} url={$fbUrl} payload=" . json_encode($event) . PHP_EOL, FILE_APPEND);

        $ch = curl_init($fbUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($event),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        @file_put_contents($logsDir . '/facebook_events.log', date('Y-m-d H:i:s') . " [FB RESP] txid={$txid} http_code={$code} err={$err} resp=" . substr($resp,0,1000) . PHP_EOL, FILE_APPEND);

        file_put_contents(__DIR__ . '/../../pix_api_debug.log', date('Y-m-d H:i:s') . " [FB CAPI] txid={$txid} code={$code} err={$err} resp=" . substr($resp,0,1000) . "\n", FILE_APPEND);
    }

    // 1.1) Dispara evento TikTok Events API (server-side) para Purchase
    if ($campaign && !empty($campaign['tiktok_pixel_id']) && !empty($campaign['tiktok_access_token'])) {
        $ttPixelId = trim((string)$campaign['tiktok_pixel_id']);
        $ttAccessToken = trim((string)$campaign['tiktok_access_token']);
        $ttUrl = 'https://business-api.tiktok.com/open_api/v1.3/event/track/';

        $email = strtolower(trim((string)($order['buyer_email'] ?? '')));
        $phone = preg_replace('/\D/', '', (string)($order['buyer_phone'] ?? ''));
        if (empty($phone) && !empty($orderPayload['buyer_phone'])) {
            $phone = preg_replace('/\D/', '', (string)$orderPayload['buyer_phone']);
        }
        if (empty($phone) && !empty($orderPayload['phone'])) {
            $phone = preg_replace('/\D/', '', (string)$orderPayload['phone']);
        }
        $externalIdRaw = trim((string)($orderPayload['external_id'] ?? ''));
        if ($externalIdRaw === '') $externalIdRaw = $email !== '' ? $email : (string)$txid;
        $clientIp = $orderPayload['user_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        $clientUa = $orderPayload['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
        $ttclid = substr(trim((string)($orderPayload['ttclid'] ?? '')), 0, 1000);
        $ttp = trim((string)($orderPayload['ttp'] ?? ''));

        $user = [
            'external_id' => hash('sha256', $externalIdRaw),
        ];
        if (!empty($email)) $user['email'] = hash('sha256', $email);
        if (!empty($phone) && strlen($phone) >= 10) $user['phone_number'] = hash('sha256', $phone);
        if (!empty($clientIp)) $user['ip'] = $clientIp;
        if (!empty($clientUa)) $user['user_agent'] = $clientUa;
        if (!empty($ttp)) $user['ttp'] = $ttp;

        $context = [
            'ad' => [],
            'user' => [],
            'page' => [
                'url' => (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '') . '/checkout.php?slug=' . urlencode((string)($campaign['slug'] ?? '')),
                'referrer' => $_SERVER['HTTP_REFERER'] ?? null,
            ],
            'ip' => $clientIp,
            'user_agent' => $clientUa,
        ];
        if (!empty($ttclid)) $context['ad']['callback'] = $ttclid;
        if (!empty($ttp)) $context['user']['ttp'] = $ttp;
        $context['user']['external_id'] = hash('sha256', $externalIdRaw);
        if (empty($context['ad'])) unset($context['ad']);
        if (empty($context['user'])) unset($context['user']);

        $amount = (float)($order['amount'] ?? 0);
        $ttPayload = [
            'event_source' => 'web',
            'event_source_id' => $ttPixelId,
            'data' => [[
                'event' => 'Purchase',
                'event_time' => time(),
                'event_id' => 'purchase_' . $txid,
                'user' => $user,
                'properties' => [
                    'currency' => 'BRL',
                    'value' => $amount,
                    'content_type' => 'product',
                    'content_id' => 'campaign_' . (string)($campaign['id'] ?? ''),
                    'content_name' => (string)($campaign['title'] ?? 'Campanha'),
                    'quantity' => 1,
                ],
                'context' => $context,
                'page' => [
                    'url' => (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '') . '/checkout.php?slug=' . urlencode((string)($campaign['slug'] ?? ''))
                ]
            ]]
        ];

        @file_put_contents($logsDir . '/tiktok_events.log', date('Y-m-d H:i:s') . " [TT SEND] txid={$txid} url={$ttUrl} payload=" . json_encode($ttPayload) . PHP_EOL, FILE_APPEND);

        $chT = curl_init($ttUrl);
        curl_setopt_array($chT, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($ttPayload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Access-Token: ' . $ttAccessToken,
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $respT = curl_exec($chT);
        $codeT = curl_getinfo($chT, CURLINFO_HTTP_CODE);
        $errT = curl_error($chT);
        curl_close($chT);

        @file_put_contents($logsDir . '/tiktok_events.log', date('Y-m-d H:i:s') . " [TT RESP] txid={$txid} http_code={$codeT} err={$errT} resp=" . substr((string)$respT, 0, 1200) . PHP_EOL, FILE_APPEND);
        @file_put_contents(__DIR__ . '/../../pix_api_debug.log', date('Y-m-d H:i:s') . " [TT CAPI] txid={$txid} code={$codeT} err={$errT} resp=" . substr((string)$respT, 0, 1000) . "\n", FILE_APPEND);
    }

    // 2) Envia notificação para Utmify (se token/config houver) - formato conforme documentação Utmify
    if ($campaign && !empty($campaign['utmify_api_token'])) {
        $utmToken = $campaign['utmify_api_token'];
        $utmEndpoint = 'https://api.utmify.com.br/api-credentials/orders';

        // produtos: tenta obter do campo 'items' ou 'products' ou monta um produto genérico
        $products = [];
        if (!empty($order['items'])) {
            $items = json_decode($order['items'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($items)) {
                foreach ($items as $it) {
                    $products[] = [
                        'id' => $it['externalRef'] ?? ($it['id'] ?? uniqid('p_')),
                        'name' => $it['title'] ?? ($it['name'] ?? 'Produto'),
                        'planId' => $it['planId'] ?? null,
                        'planName' => $it['planName'] ?? null,
                        'quantity' => isset($it['quantity']) ? (int)$it['quantity'] : 1,
                        'priceInCents' => (int)round((($it['unitPrice'] ?? ($it['price'] ?? 0)) * 100))
                    ];
                }
            }
        }
        // fallback: um produto representando o valor total
        if (empty($products)) {
            $products[] = [
                'id' => $txid . '_item',
                'name' => 'Ebook',
                'planId' => null,
                'planName' => null,
                'quantity' => 1,
                'priceInCents' => (int)round(($order['amount'] ?? 0) * 100)
            ];
        }

        // trackingParameters conforme especificação
        $trackingParameters = [
            'src' => $orderPayload['src'] ?? null,
            'sck' => $orderPayload['sck'] ?? null,
            'utm_source' => $orderPayload['utm_source'] ?? ($orderPayload['utmSource'] ?? null),
            'utm_campaign' => $orderPayload['utm_campaign'] ?? ($orderPayload['utmCampaign'] ?? null),
            'utm_medium' => $orderPayload['utm_medium'] ?? ($orderPayload['utmMedium'] ?? null),
            'utm_content' => $orderPayload['utm_content'] ?? ($orderPayload['utmContent'] ?? null),
            'utm_term' => $orderPayload['utm_term'] ?? ($orderPayload['utmTerm'] ?? null)
        ];

        // customer
        $country = strtoupper($orderPayload['country_code'] ?? ($orderPayload['country'] ?? 'BR'));
        // Valida country para Utmify (deve ser ISO 3166-1 alfa-2, ex: BR)
        if (empty($country) || strlen($country) !== 2) $country = 'BR';

        $customer = [
            'name' => $order['buyer_name'] ?? null,
            'email' => $order['buyer_email'] ?? null,
            'phone' => $order['buyer_phone'] ?? ($order['buyer_phone'] ?? null),
            'document' => $order['buyer_cpf'] ?? null,
            'country' => $country,
            'ip' => $orderPayload['user_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null)
        ];

        // commission: usa total em centavos e define gatewayFee como 0 (padrão) e userCommission igual ao total
        $totalCents = (int)round(($order['amount'] ?? 0) * 100);
        $commission = [
            'totalPriceInCents' => $totalCents,
            'gatewayFeeInCents' => $orderPayload['gateway_fee_cents'] ?? 0,
            'userCommissionInCents' => $orderPayload['user_commission_cents'] ?? $totalCents,
            'currency' => 'BRL'
        ];

        $approvedIso = iso8601Local($paidAtDb); // FIX: define approved ISO
        $utmBody = [
            'orderId' => (string)$txid,
            'platform' => 'VakinhaClone',
            'paymentMethod' => 'pix',
            'status' => 'paid',
            'createdAt' => iso8601Local($order['created_at'] ?? null),
            'approvedDate' => $approvedIso,
            'refundedAt' => null,
            'customer' => $customer,
            'products' => $products,
            'trackingParameters' => $trackingParameters,
            'commission' => $commission,
            'isTest' => false
        ];

        @file_put_contents($logsDir . '/utmify_events.log', date('Y-m-d H:i:s') . " [UTMIFY SEND] txid={$txid} endpoint={$utmEndpoint} body=" . json_encode($utmBody) . PHP_EOL, FILE_APPEND);

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

        @file_put_contents($logsDir . '/utmify_events.log', date('Y-m-d H:i:s') . " [UTMIFY RESP] txid={$txid} http_code={$codeU} err={$errU} resp=" . substr($respU,0,1000) . PHP_EOL, FILE_APPEND);

        file_put_contents(__DIR__ . '/../../pix_api_debug.log', date('Y-m-d H:i:s') . " [UTMIFY] txid={$txid} code={$codeU} err={$errU} body=" . substr(json_encode($utmBody),0,2000) . " resp=" . substr($respU,0,1000) . "\n", FILE_APPEND);
    }

    http_response_code(200);
    echo json_encode(['ok' => true, 'txid' => $txid, 'status' => 'pago']);
    exit;
} else {
    @file_put_contents(
        __DIR__ . '/../../pix_api_debug.log',
        date('Y-m-d H:i:s') . " [WEBHOOK IGNORE] txid=" . json_encode($txid) . " status=" . json_encode($status) . "\n",
        FILE_APPEND
    );
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'txid/status not matched', 'txid' => $txid, 'status' => $status]);
}
