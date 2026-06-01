<?php
require_once __DIR__ . '/config.php';

class PixGateway
{
    // Cria cobrança PIX (prioriza AureaPag quando selecionado/configurado)
    public static function createCharge(array $data): array
    {
        // Preferência explícita via $data['acquirer']
        $acq = strtoupper($data['acquirer'] ?? '');
        if (($acq === 'AUREAPAG') && defined('AUREAPAG_API_TOKEN') && AUREAPAG_API_TOKEN) {
            return self::createChargeAureaPag($data);
        }
        // Fallback: se AureaPag estiver configurada, usa
        if (defined('AUREAPAG_API_TOKEN') && AUREAPAG_API_TOKEN) {
            return self::createChargeAureaPag($data);
        }

        $url = UMBRELLA_BASE_URL . '/user/transactions';

        // Monta payload Umbrella em centavos
        $amountFloat = (float)($data['amount'] ?? 0);
        $amountCents = (int)round($amountFloat * 100);
        $ip = $data['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        // Sanitiza e garante phone obrigatório como string
        $phoneRaw = $data['buyerPhone'] ?? ($data['buyerWhatsapp'] ?? '');
        $phoneDigits = preg_replace('/\D/', '', (string)$phoneRaw);
        if ($phoneDigits === '' || strlen($phoneDigits) < 10) {
            // gera telefone e endereço compatíveis (DDD/cidade)
            $loc = self::pickBrazilLocation();
            $phoneDigits = self::randomBrazilPhone($loc['ddd']);
            $addrUmbrella = self::randomAddressUmbrella($loc);
        } else {
            // se já tiver telefone, ainda envia endereço aleatório válido
            $addrUmbrella = self::randomAddressUmbrella(self::pickBrazilLocation());
        }

        // Email seguro (fallback se inválido)
        $email = $data['buyerEmail'] ?? null;
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = 'doacao' . random_int(1000, 999999) . '@gmail.com';
        }

        $customerDocument = preg_replace('/\D/', '', $data['buyerCpf'] ?? '');
        $customer = [
            'name' => $data['buyerName'] ?? 'Cliente',
            'email' => $email,
            'document' => [
                'number' => $customerDocument ?: '00000000000',
                'type'   => (strlen($customerDocument) > 11 ? 'CNPJ' : 'CPF')
            ],
            'phone' => $phoneDigits,
            'externalRef' => $customerDocument ?: (substr(sha1(($data['buyerEmail'] ?? '') . microtime(true)), 0, 16)),
            // Endereço real aleatório (Umbrella exige objeto address)
            'address' => $addrUmbrella
        ];

        $items = [
            [
                'title'      => $data['description'] ?? 'Prompts GPT',
                'unitPrice'  => $amountCents,
                'quantity'   => 1,
                'tangible'   => false,
                'externalRef'=> 'donation_' . uniqid()
            ]
        ];

        $payload = [
            'amount'        => $amountCents,
            'currency'      => 'BRL',
            'paymentMethod' => 'PIX',
            'installments'  => 1,
            'pix'           => [ 'expiresInDays' => 1 ],
            'postbackUrl'   => $data['webhookUrl'] ?? (defined('BASE_URL') ? BASE_URL . '/webhooks/pix.php' : null),
            'metadata'      => json_encode(['description' => $data['description'] ?? 'Prompts GPT']),
            'traceable'     => true,
            'ip'            => $ip,
            'customer'      => $customer,
            'shipping'      => [
                'fee' => 0,
                'address' => $addrUmbrella // usa o mesmo endereço
            ],
            'items'         => $items
        ];

        $resp = self::postUmbrellaJson($url, $payload, 'createTransaction');
        $dataResp = $resp['data'] ?? [];

        // Normaliza para o checkout
        $statusUmb = strtoupper($dataResp['status'] ?? 'WAITING_PAYMENT');
        $normalized = [
            'success' => (($resp['status'] ?? 0) >= 200 && ($resp['status'] ?? 0) < 300),
            'txid'    => $dataResp['id'] ?? null,
            'status'  => in_array($statusUmb, ['PAID','PAID_OUT']) ? 'pago' : 'pendente',
            'amount'  => isset($dataResp['amount']) ? ((float)$dataResp['amount'] / 100.0) : $amountFloat,
        ];

        // QR / Copia e Cola
        $pixObj = $dataResp['pix'] ?? [];
        // tenta encontrar uma imagem base64 (se algum provedor enviar)
        $qrMaybe = $pixObj['qrCodeBase64'] ?? ($pixObj['imageBase64'] ?? ($pixObj['qrCodeImage'] ?? ($dataResp['qrCodeBase64'] ?? null)));
        if (!empty($qrMaybe)) {
            $normalized['qrCodeBase64'] = (strpos($qrMaybe, 'data:image') === 0)
                ? preg_replace('#^data:image/\w+;base64,#', '', $qrMaybe)
                : $qrMaybe;
        }
        // Copia e Cola: prioriza pix.qrcode
        $normalized['copyPasteCode'] = $pixObj['qrcode']
            ?? ($pixObj['copyPasteCode'] ?? ($pixObj['code'] ?? ($pixObj['payload'] ?? '')));
        // expiração e provider
        $normalized['expiresAt'] = $pixObj['expirationDate'] ?? ($dataResp['createdAt'] ?? null);
        $normalized['provider']  = $dataResp['provider'] ?? null;
        // opcional: expõe bruto do qrcode
        if (!empty($pixObj['qrcode'])) $normalized['qrCodeRaw'] = $pixObj['qrcode'];

        // Log bruto
        @file_put_contents(__DIR__ . '/../logs/pix_gateway_http.log',
            date('Y-m-d H:i:s') . " [Umbrella:create] URL=$url\nPayload=" . json_encode($payload) . "\nResp=" . json_encode($resp) . "\n\n",
            FILE_APPEND
        );

        if (empty($normalized['txid'])) {
            throw new \Exception('Falha ao criar transação PIX (Umbrella): ' . json_encode($resp));
        }
        return $normalized;
    }

    // Consulta status do pagamento via Umbrella
    public static function getStatus(string $txid): array
    {
        // Tenta AureaPag
        if (defined('AUREAPAG_API_TOKEN') && AUREAPAG_API_TOKEN) {
            try {
                $resp = self::getAureaJson('/public/v1/transactions/' . urlencode($txid));
                $dataResp = $resp['data'] ?? $resp;
                $st = strtolower($dataResp['status'] ?? '');
                $paid = in_array($st, ['paid','approved','concluded','pago','completed']);
                return [
                    'status' => $paid ? 'pago' : 'pendente',
                    'paid'   => $paid,
                    'raw'    => $resp
                ];
            } catch (\Throwable $e) {
                @file_put_contents(__DIR__ . '/../logs/pix_gateway_http.log',
                    date('Y-m-d H:i:s') . " [AureaPag:getStatus][ERROR] txid=$txid err=" . $e->getMessage() . "\n",
                    FILE_APPEND
                );
            }
        }

        $url = UMBRELLA_BASE_URL . '/user/transactions/' . urlencode($txid);
        try {
            $resp = self::getUmbrellaJson($url, 'getTransactionStatus');
            $dataResp = $resp['data'] ?? [];
            $statusUmb = strtoupper($dataResp['status'] ?? 'WAITING_PAYMENT');
            $paid = in_array($statusUmb, ['PAID','PAID_OUT']);
            return [
                'status' => $paid ? 'pago' : 'pendente',
                'paid'   => $paid,
                'raw'    => $resp
            ];
        } catch (\Throwable $e) {
            // Fallback: pendente
            @file_put_contents(__DIR__ . '/../logs/pix_gateway_http.log',
                date('Y-m-d H:i:s') . " [Umbrella:getStatus][ERROR] txid=$txid err=" . $e->getMessage() . "\n",
                FILE_APPEND
            );
            return ['status' => 'pendente', 'paid' => false];
        }
    }

    // ===== AureaPag implementation =====
    private static function createChargeAureaPag(array $data): array
    {
        $amountFloat = (float)($data['amount'] ?? 0);
        $amountCents = (int)round($amountFloat * 100);

        // Dados do comprador
        $name  = $data['buyerName']  ?? 'Cliente';
        $email = $data['buyerEmail'] ?? null;
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = 'doacao' . random_int(1000, 999999) . '@gmail.com'; // fallback seguro
        }
        $doc   = preg_replace('/\D/', '', $data['buyerCpf'] ?? '');

        // Gera telefone/endereço reais
        $loc   = self::pickBrazilLocation();
        $phone = preg_replace('/\D/', '', ($data['buyerPhone'] ?? ($data['buyerWhatsapp'] ?? '')));
        if ($phone === '' || strlen($phone) < 10) $phone = self::randomBrazilPhone($loc['ddd']);
        $address = self::randomAddressAurea($loc);

        // Cart para AureaPag
        $cart = [];
        if (!empty($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $it) {
                $cart[] = [
                    'product_hash'  => $it['product_hash'] ?? ((defined('AUREAPAG_PRODUCT_HASH') && AUREAPAG_PRODUCT_HASH) ? AUREAPAG_PRODUCT_HASH : ($it['externalRef'] ?? ($it['id'] ?? 'prod_' . uniqid()))),
                    'title'         => $it['title'] ?? ($it['name'] ?? 'Produto'),
                    'cover'         => null,
                    'price'         => (int)($it['unitPrice'] ?? ($it['price'] ?? $amountCents)),
                    'quantity'      => (int)($it['quantity'] ?? 1),
                    'operation_type'=> 1,
                    'tangible'      => false
                ];
            }
        }
        if (empty($cart)) {
            $cart[] = [
                'product_hash'  => (defined('AUREAPAG_PRODUCT_HASH') && AUREAPAG_PRODUCT_HASH) ? AUREAPAG_PRODUCT_HASH : 'prod_' . uniqid(),
                'title'         => 'Prompts GPT',
                'cover'         => null,
                'price'         => $amountCents,
                'quantity'      => 1,
                'operation_type'=> 1,
                'tangible'      => false
            ];
        }

        // Tracking (UTMs)
        $tracking = [];
        if (!empty($data['tracking']) && is_array($data['tracking'])) {
            $tracking = [
                'src'         => $data['tracking']['src'] ?? '',
                'utm_source'  => $data['tracking']['utm_source'] ?? '',
                'utm_medium'  => $data['tracking']['utm_medium'] ?? '',
                'utm_campaign'=> $data['tracking']['utm_campaign'] ?? '',
                'utm_term'    => $data['tracking']['utm_term'] ?? '',
                'utm_content' => $data['tracking']['utm_content'] ?? ''
            ];
        }

        $payload = [
            'amount'            => $amountCents,
            'payment_method'    => 'pix',
            'offer_hash'        => (defined('AUREAPAG_OFFER_HASH') && AUREAPAG_OFFER_HASH) ? AUREAPAG_OFFER_HASH : null,
            'customer'          => [
                'name'         => $name,
                'email'        => $email,
                'phone_number' => $phone,
                'document'     => $doc,
            ] + $address,
            'cart'              => $cart,
            'installments'      => 1,
            'expire_in_days'    => 1,
            'transaction_origin'=> 'api',
            'tracking'          => $tracking,
            'postback_url'      => $data['webhookUrl'] ?? (defined('BASE_URL') ? BASE_URL . '/webhooks/pix.php' : null),
        ];
        // Remove chave offer_hash se vazia
        if (empty($payload['offer_hash'])) unset($payload['offer_hash']);

        $resp = self::postAureaJson('/public/v1/transactions', $payload);
        $dataResp = $resp['data'] ?? $resp;

        // Normalização
        $hash = $dataResp['hash'] ?? ($dataResp['transaction_hash'] ?? null);
        $pix  = $dataResp['pix'] ?? [];
        // Preferir EMV do pix_qr_code; fallback para pix_url
        $emv  = $pix['pix_qr_code'] ?? $pix['pix_url'] ?? null;
        $copy = $pix['payload']
            ?? ($pix['qrcode'] ?? ($pix['code'] ?? ($emv ?? ($dataResp['payload'] ?? ''))));
        $qrB64= $pix['qrCodeBase64'] ?? ($pix['qr_code_base64'] ?? null);

        $normalized = [
            'success'       => true,
            'txid'          => $hash,
            'status'        => 'pendente',
            'amount'        => $amountFloat,
            'copyPasteCode' => $copy,
            'qrCodeRaw'     => $emv ?: $copy,        // garante um fallback consistente
            'payUrl'        => $pix['pix_url'] ?? null,
            'provider'      => 'AUREAPAG',
            'expiresAt'     => date('c', strtotime('+15 minutes')),
            'raw'           => $resp
        ];
        if (!empty($qrB64)) {
            $normalized['qrCodeBase64'] = (strpos($qrB64, 'data:image') === 0)
                ? preg_replace('#^data:image/\w+;base64,#', '', $qrB64)
                : $qrB64;
        }

        @file_put_contents(__DIR__ . '/../logs/pix_gateway_http.log',
            date('Y-m-d H:i:s') . " [AureaPag:create] URL=" . (AUREAPAG_BASE_URL . '/public/v1/transactions') . "\nPayload=" . json_encode($payload) . "\nResp=" . json_encode($resp) . "\n\n",
            FILE_APPEND
        );

        if (empty($normalized['txid'])) {
            throw new \Exception('Falha ao criar transação PIX (AureaPag): ' . json_encode($resp));
        }
        return $normalized;
    }

    private static function aureaHeaders(): array
    {
        return [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Vakinha/1.0'
        ];
    }

    private static function postAureaJson(string $path, array $data): array
    {
        $url = rtrim(AUREAPAG_BASE_URL, '/') . $path . '?api_token=' . urlencode(AUREAPAG_API_TOKEN);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => self::aureaHeaders(),
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $result = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($result, true);
        if ($json === null || $httpCode < 200 || $httpCode >= 300) {
            throw new \Exception("Erro [AureaPag:POST]: HTTP $httpCode, cURL: $curlErr, Body: $result");
        }
        $json['status'] = $httpCode;
        return $json;
    }

    private static function getAureaJson(string $path): array
    {
        $url = rtrim(AUREAPAG_BASE_URL, '/') . $path . '?api_token=' . urlencode(AUREAPAG_API_TOKEN);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => self::aureaHeaders(),
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $result = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($result, true);
        if ($json === null || $httpCode < 200 || $httpCode >= 300) {
            throw new \Exception("Erro [AureaPag:GET]: HTTP $httpCode, cURL: $curlErr, Body: $result");
        }
        $json['status'] = $httpCode;
        return $json;
    }

    // ===== Helpers HTTP (Umbrella) =====
    private static function commonHeaders(): array
    {
        return [
            'Content-Type: application/json',
            'x-api-key: ' . UMBRELLA_API_KEY,
            'User-Agent: ' . (defined('UMBRELLA_USER_AGENT') ? UMBRELLA_USER_AGENT : 'UMBRELLAB2B/1.0')
        ];
    }

    private static function postUmbrellaJson(string $url, array $data, string $context): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => self::commonHeaders(),
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $result = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($result, true);
        if ($json === null) {
            throw new \Exception("Resposta inválida [$context]: HTTP $httpCode, cURL: $curlErr, Body: $result");
        }
        $json['status'] = $httpCode;
        return $json;
    }

    private static function getUmbrellaJson(string $url, string $context): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => self::commonHeaders(),
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $result = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($result, true);
        if ($json === null || $httpCode < 200 || $httpCode >= 300) {
            throw new \Exception("Erro [$context]: HTTP $httpCode, cURL: $curlErr, Body: $result");
        }
        $json['status'] = $httpCode;
        return $json;
    }

    // ===== Dados e geradores BR =====

    // Lista enxuta de locais brasileiros (cidade/UF/DDD/CEP/base)
    private static function brazilLocations(): array
    {
        return [
            ['city'=>'São Paulo',        'state'=>'SP', 'ddd'=>11, 'neighborhood'=>'Bela Vista',   'zip'=>'01311-000', 'streets'=>['Av. Paulista','Rua Augusta','Rua Frei Caneca']],
            ['city'=>'Rio de Janeiro',   'state'=>'RJ', 'ddd'=>21, 'neighborhood'=>'Copacabana',   'zip'=>'22040-010', 'streets'=>['Av. Atlântica','Rua Barata Ribeiro','Rua Nossa Senhora de Copacabana']],
            ['city'=>'Belo Horizonte',   'state'=>'MG', 'ddd'=>31, 'neighborhood'=>'Savassi',      'zip'=>'30140-110', 'streets'=>['Av. Cristóvão Colombo','Rua Pernambuco','Rua Rio Grande do Norte']],
            ['city'=>'Curitiba',         'state'=>'PR', 'ddd'=>41, 'neighborhood'=>'Centro',       'zip'=>'80020-000', 'streets'=>['Rua XV de Novembro','Av. Cândido de Abreu','Rua das Flores']],
            ['city'=>'Porto Alegre',     'state'=>'RS', 'ddd'=>51, 'neighborhood'=>'Moinhos de Vento','zip'=>'90520-001','streets'=>['Rua Padre Chagas','Av. Goethe','Rua 24 de Outubro']],
            ['city'=>'Brasília',         'state'=>'DF', 'ddd'=>61, 'neighborhood'=>'Asa Sul',      'zip'=>'70390-045', 'streets'=>['SQN 308','SQS 210','CLS 402']],
            ['city'=>'Salvador',         'state'=>'BA', 'ddd'=>71, 'neighborhood'=>'Itaigara',     'zip'=>'41815-150', 'streets'=>['Av. ACM','Rua do Curiatá','Rua das Hortênsias']],
            ['city'=>'Recife',           'state'=>'PE', 'ddd'=>81, 'neighborhood'=>'Boa Viagem',   'zip'=>'51020-001', 'streets'=>['Av. Boa Viagem','Rua dos Navegantes','Rua Barão de Souza Leão']],
            ['city'=>'Fortaleza',        'state'=>'CE', 'ddd'=>85, 'neighborhood'=>'Meireles',     'zip'=>'60160-250', 'streets'=>['Av. Beira Mar','Rua Tibúrcio Cavalcante','Rua Silva Paulet']],
            ['city'=>'Florianópolis',    'state'=>'SC', 'ddd'=>48, 'neighborhood'=>'Centro',       'zip'=>'88010-400', 'streets'=>['Av. Hercílio Luz','Rua Felipe Schmidt','Rua Conselheiro Mafra']],
            ['city'=>'Goiânia',          'state'=>'GO', 'ddd'=>62, 'neighborhood'=>'Setor Bueno',  'zip'=>'74215-010', 'streets'=>['Av. T-63','Av. T-4','Rua 90']],
            ['city'=>'Belém',            'state'=>'PA', 'ddd'=>91, 'neighborhood'=>'Nazaré',       'zip'=>'66035-170', 'streets'=>['Av. Nazaré','Av. Gentil Bittencourt','Rua dos Mundurucus']],
        ];
    }

    private static function pickBrazilLocation(): array
    {
        $locs = self::brazilLocations();
        return $locs[array_rand($locs)];
    }

    // Telefone brasileiro: DDD + 9XXXXXXXX
    private static function randomBrazilPhone(?int $preferredDdd = null): string
    {
        $ddd = $preferredDdd ?: self::pickBrazilLocation()['ddd'];
        $number = '9' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
        return str_pad((string)$ddd, 2, '0', STR_PAD_LEFT) . $number;
    }

    // Endereço para Umbrella (street, streetNumber, zipCode com hífen, etc.)
    private static function randomAddressUmbrella(?array $loc = null): array
    {
        $loc = $loc ?: self::pickBrazilLocation();
        $street = $loc['streets'][array_rand($loc['streets'])];
        $number = (string) random_int(10, 3999);
        $complements = ['Apto ' . random_int(101, 999), 'Casa', 'Fundos', 'Bloco ' . random_int(1, 12), 'Sala ' . random_int(1, 50)];
        $zip = $loc['zip']; // já no formato NNNNN-NNN
        return [
            'street'       => $street,
            'streetNumber' => $number,
            'complement'   => $complements[array_rand($complements)],
            'zipCode'      => $zip,
            'neighborhood' => $loc['neighborhood'],
            'city'         => $loc['city'],
            'state'        => $loc['state'],
            'country'      => 'BR'
        ];
    }

    // Endereço para AureaPag (street_name, number, zip_code só dígitos, etc.)
    private static function randomAddressAurea(?array $loc = null): array
    {
        $loc = $loc ?: self::pickBrazilLocation();
        $street = $loc['streets'][array_rand($loc['streets'])];
        $number = (string) random_int(10, 3999);
        $complements = ['Apto ' . random_int(101, 999), 'Casa', 'Fundos', 'Bloco ' . random_int(1, 12), 'Sala ' . random_int(1, 50)];
        $zipDigits = preg_replace('/\D/', '', $loc['zip']); // NNNNNNNN
        return [
            'street_name' => $street,
            'number'      => $number,
            'complement'  => $complements[array_rand($complements)],
            'neighborhood'=> $loc['neighborhood'],
            'city'        => $loc['city'],
            'state'       => $loc['state'],
            'zip_code'    => $zipDigits
        ];
    }
}
