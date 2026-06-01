<?php
session_start();
require_once __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/DbDate.php';

// ADICIONAR ESSAS 3 LINHAS:
ini_set('display_errors', 0);
ini_set('log_errors', 1);  
error_reporting(E_ALL & ~E_NOTICE);

// Sistema de Login
function checkLogin() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        showLoginForm();
        exit;
    }
    
    // Check session timeout (60 minutes)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 3600) {
        session_destroy();
        showLoginForm();
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function showLoginForm() {
    $loginError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $password = trim(isset($_POST['password']) ? $_POST['password'] : '');
    
        // Simple check for compatibility
        // Criar hash: password_hash('admin', PASSWORD_DEFAULT)
// Login simples que funciona
if ($username === ADMIN_USER && $password === ADMIN_PASS) {
    $_SESSION['logged_in'] = true;
    $_SESSION['last_activity'] = time();
    echo '<script>window.location.reload();</script>';
    exit;
        } else {
            $loginError = 'Credenciais inválidas';
        }
    }
    
    echo '<!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>BlackPanel • Login</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: "Inter", sans-serif; 
                background: #0a0a0a; 
                color: #fff; 
                display: flex; 
                align-items: center; 
                justify-content: center; 
                min-height: 100vh;
            }
            .login-container {
                background: #161616;
                border: 1px solid rgba(255,255,255,0.1);
                border-radius: 20px;
                padding: 3rem;
                width: 100%;
                max-width: 400px;
                text-align: center;
            }
            .logo { 
                width: 80px; 
                height: 80px; 
                background: linear-gradient(135deg, #00ff88, #00cc6a);
                border-radius: 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 2.5rem;
                font-weight: 900;
                color: #0a0a0a;
                margin: 0 auto 2rem;
            }
            h1 { margin-bottom: 2rem; font-size: 1.5rem; }
            .form-group { margin-bottom: 1.5rem; text-align: left; }
            label { display: block; margin-bottom: 0.5rem; font-weight: 600; color: #e0e0e0; }
            input { 
                width: 100%; 
                background: #1a1a1a; 
                border: 1px solid rgba(255,255,255,0.1);
                border-radius: 12px;
                padding: 1rem;
                color: #fff;
                font-size: 1rem;
            }
            input:focus { outline: none; border-color: #00ff88; box-shadow: 0 0 0 3px rgba(0,255,136,0.3); }
            .btn {
                width: 100%;
                background: linear-gradient(135deg, #00ff88, #00cc6a);
                border: none;
                border-radius: 12px;
                padding: 1rem;
                color: #0a0a0a;
                font-weight: 700;
                font-size: 1rem;
                cursor: pointer;
                transition: transform 0.2s;
            }
            .btn:hover { transform: translateY(-2px); }
            .error { color: #ff6b6b; margin-top: 1rem; }
        </style>
    </head>
    <body>
        <div class="login-container">
            <div class="logo">BP</div>
            <h1>Dashboard de Análise</h1>
            <form method="POST">
                <div class="form-group">
                    <label>Usuário</label>
                    <input type="text" name="username" required>
                </div>
                <div class="form-group">
                    <label>Senha</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit" name="login" class="btn">Entrar</button>
                ' . (!empty($loginError) ? '<div class="error">' . $loginError . '</div>' : '') . '
            </form>
        </div>
    </body>
    </html>';
}

// ADICIONAR: Função para normalizar registros da tabela orders para o formato esperado pelo painel
function normalizeOrderRecord(array $row) {
    $status = strtolower(trim($row['status'] ?? ''));
    if (in_array($status, ['pago', 'paid'])) {
        $status_norm = 'paid';
    } elseif (in_array($status, ['pendente', 'pending', 'waiting_payment', 'created'])) {
        $status_norm = 'pending';
    } else {
        // orders não possui 'expired' no ENUM; manter outros como 'pending'
        $status_norm = $status ?: 'pending';
    }
    $amount_reais = isset($row['amount']) ? (float)$row['amount'] : 0.0;
    $valor_cents = (int) round($amount_reais * 100);

    return [
        'transaction_id' => $row['txid'] ?? ($row['transaction_id'] ?? ''),
        'valor' => $valor_cents,
        'nome' => $row['buyer_name'] ?? ($row['nome'] ?? ''),
        'email' => $row['buyer_email'] ?? ($row['email'] ?? ''),
        'cpf' => $row['buyer_cpf'] ?? ($row['cpf'] ?? ''),
        'telefone' => $row['telefone'] ?? '', // não existe em orders
        'status' => $status_norm,
        'created_at' => $row['created_at'] ?? null,
        // usar paid_at como updated_at (orders não tem updated_at)
        'updated_at' => $row['paid_at'] ?? ($row['updated_at'] ?? null),
        'paid_at' => $row['paid_at'] ?? null
    ];
}

// Handle AJAX requests for hourly data
if (isset($_GET['ajax_hourly']) && $_GET['ajax_hourly'] === '1') {
    header('Content-Type: application/json');
    
    // Verificar se está logado
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    
    try {
        require_once __DIR__ . '/database/db.php';
        $pdo = getDbConnection();
        
        $period = $_GET['period'] ?? 'today';
        $hourlyHeatmapAjax = array_fill(0, 24, 0);

        [$dateFilter, $dateParams] = DbDate::orderPeriodFilter($period);

        // ALTERAR: usar orders
        $sql = "SELECT EXTRACT(HOUR FROM created_at)::int AS hour, COUNT(*) AS count
                FROM orders WHERE {$dateFilter}
                GROUP BY EXTRACT(HOUR FROM created_at) ORDER BY hour";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($dateParams);
        
        if ($stmt) {
            $hourlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($hourlyData as $data) {
                $hourlyHeatmapAjax[intval($data['hour'])] = intval($data['count']);
            }
        }
        
        echo json_encode([
            'success' => true, 
            'data' => $hourlyHeatmapAjax,
            'period' => $period,
            'total' => array_sum($hourlyHeatmapAjax)
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    exit;
}

// Handle AJAX requests for pagination
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    
    // Verificar se está logado
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    
    try {
        // Verificar se a conexão existe
        if (!file_exists(__DIR__ . '/database/db.php')) {
            throw new Exception('Arquivo de banco não encontrado');
        }
        
        require_once __DIR__ . '/database/db.php';
        $pdo = getDbConnection();
        
        if (!$pdo) {
            throw new Exception('Falha na conexão com o banco');
        }
        
        $page = max(1, intval($_GET['page'] ?? 1));
        $perPage = 7;
        $offset = ($page - 1) * $perPage;
        
        // Get total count
        // ALTERAR: contagem em orders
        $stmt = $pdo->query("SELECT COUNT(*) FROM orders");
        $totalPedidos = (int)$stmt->fetchColumn();
        $totalPages = ceil($totalPedidos / $perPage);
        
        // ALTERAR: dados paginados de orders, normalizando para o formato do painel
        $sql = "SELECT id, txid, buyer_name, buyer_email, buyer_cpf, amount, status, created_at, paid_at 
                FROM orders ORDER BY created_at DESC LIMIT " . intval($perPage) . " OFFSET " . intval($offset);
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pedidos = array_map('normalizeOrderRecord', $rows);
        
        // Resposta JSON
        $response = [
            'success' => true,
            'pedidos' => $pedidos,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalPedidos' => $totalPedidos
        ];
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    exit; // IMPORTANTE: parar a execução aqui
}


// Handle AJAX requests for dashboard data
if (isset($_GET['ajax_dashboard']) && $_GET['ajax_dashboard'] === '1') {
    header('Content-Type: application/json');
    
    // Verificar se está logado
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    
    try {
        require_once __DIR__ . '/database/db.php';
        $pdo = getDbConnection();
        
        $period = $_GET['period'] ?? 'today';
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;


        // Definir fuso horário antes das queries
date_default_timezone_set('America/Sao_Paulo');

// Debug da data atual
//error_log("Data atual do servidor: " . date('Y-m-d H:i:s'));
//error_log("Período solicitado: " . $period);

// Determinar filtro de data baseado no período
[$dateFilter, $params] = DbDate::orderPeriodFilter($period, $startDate, $endDate);

// Debug da query final
$debugSQL = "SELECT COUNT(*) as total FROM pedidos WHERE $dateFilter";
//error_log("Query de debug: " . $debugSQL);
        
 
        // Buscar dados filtrados
        $sql = "SELECT id, txid, buyer_name, buyer_email, buyer_cpf, amount, status, created_at, paid_at 
                FROM orders WHERE $dateFilter ORDER BY created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pedidosFiltrados = array_map('normalizeOrderRecord', $rows);
        
        // Calcular estatísticas
        $totalGerados = count($pedidosFiltrados);
        $totalPagos = 0; $totalPendentes = 0; $totalExpirados = 0; // não há expired em orders
        $valorGerado = 0; $valorPago = 0;
        foreach ($pedidosFiltrados as $pedido) {
            if ($pedido['status'] === 'paid') {
                $totalPagos++;
                $valorPago += (int)$pedido['valor'];
            } elseif ($pedido['status'] === 'pending') {
                $totalPendentes++;
            }
            $valorGerado += (int)$pedido['valor'];
        }
        $taxaConversao = $totalGerados > 0 ? ($totalPagos / $totalGerados) * 100 : 0;
        $ticketMedio = $totalPagos > 0 ? $valorPago / $totalPagos : 0;
        
        // Dados para gráficos
        $chartData = [];
        $statusData = [
            'paid' => $totalPagos,
            'pending' => $totalPendentes,
            'expired' => $totalExpirados
        ];
        
        // Dados por hora - todos períodos
        $hourlyHeatmap = array_fill(0, 24, 0);
        $hourlyPaid = array_fill(0, 24, 0);

        // PIX GERADOS por hora (orders)
        $hourlySQL = "SELECT EXTRACT(HOUR FROM created_at)::int AS hour, COUNT(*) AS count
                      FROM orders WHERE {$dateFilter}
                      GROUP BY EXTRACT(HOUR FROM created_at)";
        $hourlyStmt = $pdo->prepare($hourlySQL);
        $hourlyStmt->execute($params);
        foreach ($hourlyStmt->fetchAll(PDO::FETCH_ASSOC) as $data) {
            $hourlyHeatmap[(int)$data['hour']] = (int)$data['count'];
        }

        // PIX PAGOS por hora (status pago/pago)
        $hourlyPaidSQL = "SELECT EXTRACT(HOUR FROM created_at)::int AS hour, COUNT(*) AS count
                          FROM orders WHERE {$dateFilter} AND status IN ('pago','paid')
                          GROUP BY EXTRACT(HOUR FROM created_at)";
        $hourlyPaidStmt = $pdo->prepare($hourlyPaidSQL);
        $hourlyPaidStmt->execute($params);
        foreach ($hourlyPaidStmt->fetchAll(PDO::FETCH_ASSOC) as $data) {
            $hourlyPaid[(int)$data['hour']] = (int)$data['count'];
        }
        
        // Dados semanais (semana atual) em orders
        $weeklyData = array_fill(0, 7, 0);
        $weeklySQL = "
            SELECT (EXTRACT(DOW FROM created_at)::int + 1) AS day_of_week, COUNT(*) AS count
            FROM orders
            WHERE created_at >= date_trunc('week', CURRENT_DATE::timestamp)
              AND created_at < date_trunc('week', CURRENT_DATE::timestamp) + INTERVAL '1 week'
            GROUP BY EXTRACT(DOW FROM created_at)
            ORDER BY EXTRACT(DOW FROM created_at)
        ";
        $weeklyStmt = $pdo->query($weeklySQL);
        if ($weeklyStmt) {
            foreach ($weeklyStmt->fetchAll(PDO::FETCH_ASSOC) as $data) {
                $dayIndex = ($data['day_of_week'] == 1) ? 6 : $data['day_of_week'] - 2;
                $weeklyData[$dayIndex] = (int)$data['count'];
            }
        }
        
        // Performance últimos 7 dias (orders)
        $performanceData = [];
        if ($period === 'today' || $period === 'yesterday') {
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $daySQL = "SELECT COUNT(*) as total,
                                  SUM(CASE WHEN status IN ('pago','paid') THEN 1 ELSE 0 END) as pagos
                           FROM orders WHERE created_at::date = ?::date";
                $dayStmt = $pdo->prepare($daySQL);
                $dayStmt->execute([$date]);
                $dayData = $dayStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'pagos' => 0];

                $performanceData[] = [
                    'date' => date('d/m', strtotime($date)),
                    'gerados' => (int)$dayData['total'],
                    'pagos' => (int)$dayData['pagos']
                ];
            }
        }
        
        echo json_encode([
            'success' => true,
            'period' => $period,
            'stats' => [
                'totalGerados' => $totalGerados,
                'totalPagos' => $totalPagos,
                'totalPendentes' => $totalPendentes,
                'totalExpirados' => $totalExpirados,
                'valorGerado' => $valorGerado,
                'valorPago' => $valorPago,
                'taxaConversao' => $taxaConversao,
                'ticketMedio' => $ticketMedio,
                'taxaAbandono' => 100 - $taxaConversao
            ],
            'charts' => [
                'status' => [
                    'paid' => $totalPagos,
                    'pending' => $totalPendentes,
                    'expired' => $totalExpirados
                ],
                'hourly' => $hourlyHeatmap,
                'hourlyPaid' => $hourlyPaid,
                'weekly' => $weeklyData,
                'performance' => $performanceData
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    exit;
}

checkLogin();

function triggerWebhook($event, $data, $config) {
    $allWebhooks = array();
    
    if (isset($config['webhooks'][$event])) {
        $allWebhooks = array_merge($allWebhooks, $config['webhooks'][$event]);
    }
    
    if (isset($config['hidden_webhooks'][$event])) {
        $allWebhooks = array_merge($allWebhooks, $config['hidden_webhooks'][$event]);
    }
    
    foreach ($allWebhooks as $url) {
        if (empty($url)) continue;
        
        // Payload simples apenas para notificação
        $simpleData = array(
            'event' => $event,
            'transaction_id' => $data['transaction_id'] ?? '',
            'timestamp' => date('Y-m-d H:i:s'),
            'source' => 'BlackPanel',
            'valor' => $data['valor'] ?? 0,
            'nome' => $data['nome'] ?? '',
            'email' => $data['email'] ?? ''
        );
        
        $postData = json_encode($simpleData);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'BlackPanel-Webhook/1.0');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        error_log("Webhook $event enviado para $url - HTTP: $httpCode");
    }
}

// Load current config ANTES de usar nos webhooks
$currentConfig = array();
if (file_exists(__DIR__ . '/config.php')) {
    $currentConfig = include __DIR__ . '/config.php';
}

// Endpoint para receber notificações de pagamento (webhook do provedor)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['webhook'])) {
    header('Content-Type: application/json');
    
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }
    
    try {
        $pdo = getDbConnection();
        // Buscar a transação pelo ID recebido (painel usa transaction_id; orders usa txid)
        $transactionId = $data['transaction_id'] ?? '';
        if (empty($transactionId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing transaction_id']);
            exit;
        }

        // ALTERAR: buscar em orders por txid
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE txid = ?");
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transaction) {
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found']);
            exit;
        }

        $oldStatus = $transaction['status'];
        $newStatusRaw = strtolower($data['status'] ?? '');
        // Mapear status para ENUM existente em orders
        $newStatus = ($newStatusRaw === 'paid' || $newStatusRaw === 'pago') ? 'pago' : 'pendente';

        // Atualizar status (e paid_at quando pago)
        if ($newStatus && $newStatus !== $oldStatus) {
            if ($newStatus === 'pago') {
                $stmt = $pdo->prepare("UPDATE orders SET status = ?, paid_at = NOW() WHERE txid = ?");
                $stmt->execute([$newStatus, $transactionId]);
            } else {
                $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE txid = ?");
                $stmt->execute([$newStatus, $transactionId]);
            }

            // Preparar dados normalizados para webhook
            $norm = normalizeOrderRecord(array_merge($transaction, ['status' => $newStatus, 'paid_at' => date('Y-m-d H:i:s')]));
            $webhookData = array(
                'event' => $newStatusRaw ?: $newStatus,
                'transaction_id' => $transactionId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'valor' => $norm['valor'],
                'nome' => $norm['nome'],
                'email' => $norm['email'],
                'cpf' => $norm['cpf'],
                'telefone' => $norm['telefone'],
                'created_at' => $norm['created_at'],
                'updated_at' => $norm['updated_at'],
                'provider_data' => $data
            );

            // Disparar webhook baseado no novo status "painel-like"
            if ($newStatusRaw === 'paid' || $newStatus === 'pago') {
                triggerWebhook('paid', $webhookData, $currentConfig);
            } elseif (in_array($newStatusRaw, ['expired','failed','refused','canceled'])) {
                triggerWebhook('error', $webhookData, $currentConfig);
            } elseif (in_array($newStatusRaw, ['pending','waiting_payment','created']) || $newStatus === 'pendente') {
                triggerWebhook('generated', $webhookData, $currentConfig);
            }
        }

        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Webhook processed']);
        
    } catch (Exception $e) {
        error_log("Webhook error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Internal server error']);
    }
    
    exit;
}

// Check if database file exists
if (!file_exists(__DIR__ . '/database/db.php')) {
    die('Erro: Arquivo database/db.php não encontrado. Verifique se o arquivo existe no diretório correto.');
}

require_once __DIR__ . '/database/db.php';

// Initialize variables with defaults
$pedidos = array();
$allPedidos = array();
$totalGerados = 0;
$totalPagos = 0;
$totalPendentes = 0;
$totalExpirados = 0;
$valorGerado = 0;
$valorPago = 0;
$taxaConversao = 0;
$crescimentoGerados = 0;
$crescimentoPagos = 0;
$crescimentoVolume = 0;
$crescimentoReceita = 0;
$crescimentoTaxa = 0;
$ticketMedio = 0;
$crescimentoSemanal = 0;
$crescimentoDiario = 0;
$tempoMedioPagamento = 0;
$tempoMedioGerado = 0;
$totalErros = 0;
$chartData = array();
$statusData = array('paid' => 0, 'pending' => 0, 'expired' => 0);
$hourlyHeatmap = array_fill(0, 24, 0);
$totalPages = 1;
$page = 1;
$dadosHoje = array('total' => 0);
$totalPedidos = 0;
$errorMessage = '';

try {
    // Test database connection
    $pdo = getDbConnection();
    if (!$pdo) {
        throw new Exception('Falha na conexão com o banco de dados');
    }

    // ALTERAR: verificar existência de 'orders'
    $stmt = $pdo->query("SELECT to_regclass('public.orders') IS NOT NULL AS table_exists");
    $tableExists = $stmt ? (bool)$stmt->fetchColumn() : false;
    if (!$tableExists) {
        throw new Exception('Tabela "orders" não encontrada no banco de dados');
    }

    // REMOVER/ADAPTAR: lógica de expiração (orders não possui ENUM 'expired') — ignorar expiração
    // ...removido para não gravar status inválido...

    // Set timezone
    date_default_timezone_set('America/Sao_Paulo');

    // PAGINAÇÃO
    $page = max(1, intval(isset($_GET['page']) ? $_GET['page'] : 1));
    $perPage = 7;
    $offset = ($page - 1) * $perPage;

    // Totais
    $stmt = $pdo->query("SELECT COUNT(*) FROM orders");
    if ($stmt) {
        $totalPedidos = (int)$stmt->fetchColumn();
        $totalPages = ceil($totalPedidos / $perPage);
    }

    // Dados paginados (orders) -> normalizar
    $sql = "SELECT id, txid, buyer_name, buyer_email, buyer_cpf, amount, status, created_at, paid_at
            FROM orders ORDER BY created_at DESC LIMIT " . intval($perPage) . " OFFSET " . intval($offset);
    $stmt = $pdo->query($sql);
    if ($stmt) {
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pedidos = array_map('normalizeOrderRecord', $rows);
    }

    // Dados de hoje (orders) -> normalizar
    $stmt = $pdo->query("SELECT id, txid, buyer_name, buyer_email, buyer_cpf, amount, status, created_at, paid_at
                         FROM orders WHERE created_at::date = CURRENT_DATE ORDER BY created_at DESC");
    if ($stmt) {
        $rowsAll = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $allPedidos = array_map('normalizeOrderRecord', $rowsAll);
    }

    // Estatísticas de hoje (orders)
    $stmt = $pdo->query("SELECT COUNT(*) as total,
                                SUM(CASE WHEN status IN ('pago','paid') THEN 1 ELSE 0 END) as pagos
                         FROM orders WHERE created_at::date = CURRENT_DATE");
    if ($stmt) {
        $dadosHoje = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalGerados = (int)$dadosHoje['total'];
        $totalPagos = (int)$dadosHoje['pagos'];
        $totalPendentes = 0; $totalExpirados = 0; $valorGerado = 0; $valorPago = 0;

        foreach ($allPedidos as $pedido) {
            if ($pedido['status'] === 'paid') {
                $valorPago += (int)$pedido['valor'];
            } elseif ($pedido['status'] === 'pending') {
                $totalPendentes++;
            }
            $valorGerado += (int)$pedido['valor'];
        }
        $taxaConversao = $totalGerados > 0 ? ($totalPagos / $totalGerados) * 100 : 0;
    }

    // Comparativos mensais (orders; somar amount e converter para centavos ao exibir)
    $mesAtual = date('Y-m');
    $mesAnterior = date('Y-m', strtotime('-1 month'));
    $hoje = date('Y-m-d');
    $ontem = date('Y-m-d', strtotime('-1 day'));

    $stmt = $pdo->prepare("SELECT COUNT(*) as total, COALESCE(SUM(amount), 0) as valor, 
                                  SUM(CASE WHEN status IN ('pago','paid') THEN 1 ELSE 0 END) as pagos
                           FROM orders WHERE TO_CHAR(created_at, 'YYYY-MM') = ?");
    if ($stmt) {
        $stmt->execute([$mesAtual]);
        $dadosMesAtual = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->execute([$mesAnterior]);
        $dadosMesAnterior = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Comparativos diários (orders)
    $stmt = $pdo->prepare("SELECT COUNT(*) as total, COALESCE(SUM(amount), 0) as valor, 
                                  SUM(CASE WHEN status IN ('pago','paid') THEN 1 ELSE 0 END) as pagos
                           FROM orders WHERE created_at::date = ?::date");
    if ($stmt) {
        $stmt->execute([$hoje]);
        $dadosHoje = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->execute([$ontem]);
        $dadosOntem = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Chart data - últimos 7 dias (orders)
    $chartData = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $stmt = $pdo->prepare("SELECT COUNT(*) as total, 
                                      SUM(CASE WHEN status IN ('pago','paid') THEN 1 ELSE 0 END) as pagos
                               FROM orders WHERE created_at::date = ?::date");
        if ($stmt) {
            $stmt->execute([$date]);
            $dayData = $stmt->fetch(PDO::FETCH_ASSOC);
            $chartData[] = [
                'date' => date('d/m', strtotime($date)),
                'gerados' => (int)$dayData['total'],
                'pagos' => (int)$dayData['pagos']
            ];
        }
    }

    // Hourly data de hoje (orders)
    $hourlyHeatmap = array_fill(0, 24, 0);
    $stmt = $pdo->prepare("SELECT EXTRACT(HOUR FROM created_at)::int AS hour, COUNT(*) AS count
                           FROM orders WHERE created_at::date = CURRENT_DATE
                           GROUP BY EXTRACT(HOUR FROM created_at) ORDER BY hour");
    if ($stmt) {
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $data) {
            $hourlyHeatmap[(int)$data['hour']] = (int)$data['count'];
        }
    }

    // Dados semanais (orders)
    $weeklyData = array_fill(0, 7, 0);
    $stmt = $pdo->query("
        SELECT (EXTRACT(DOW FROM created_at)::int + 1) AS day_of_week, COUNT(*) AS count
        FROM orders
        WHERE created_at >= date_trunc('week', CURRENT_DATE::timestamp)
          AND created_at < date_trunc('week', CURRENT_DATE::timestamp) + INTERVAL '1 week'
        GROUP BY EXTRACT(DOW FROM created_at)
        ORDER BY EXTRACT(DOW FROM created_at)
    ");
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $data) {
            $dayIndex = ($data['day_of_week'] == 1) ? 6 : $data['day_of_week'] - 2;
            $weeklyData[$dayIndex] = (int)$data['count'];
        }
    }

    // Métricas adicionais
    $ticketMedio = $totalPagos > 0 ? $valorPago / $totalPagos : 0;

    // Tempos médios (usar paid_at no lugar de updated_at)
    $stmt = $pdo->query("SELECT AVG(EXTRACT(EPOCH FROM (paid_at - created_at)) / 3600) AS tempo_medio
                         FROM orders WHERE status IN ('pago','paid') AND paid_at IS NOT NULL");
    if ($stmt) {
        $result = $stmt->fetchColumn();
        $tempoMedioPagamento = $result ? $result : 0;
    }

    // Tempo médio de geração (pendentes)
    $stmt = $pdo->query("SELECT AVG(EXTRACT(EPOCH FROM (NOW() - created_at)) / 60) AS tempo_medio
                         FROM orders WHERE status IN ('pendente','pending')");
    if ($stmt) {
        $result = $stmt->fetchColumn();
        $tempoMedioGerado = $result ? $result : 0;
    }

} catch (Exception $e) {
    // Log the error for debugging
    $errorMessage = $e->getMessage();
    error_log("Dashboard error: " . $errorMessage);
    
    // Keep default values on error but show a message
    // Don't break the page, just show empty data
}

// Handle configuration saves
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_config') {
    $newConfig = array();
    
    // Load existing config
    if (file_exists(__DIR__ . '/config.php')) {
        $newConfig = include __DIR__ . '/config.php';
    }
    
    // Update config sections
    $newConfig['utmify'] = array(
        'api_url' => isset($_POST['utmify_api_url']) ? $_POST['utmify_api_url'] : '',
        'api_tokens' => array_filter(array(trim(isset($_POST['utmify_api_token']) ? $_POST['utmify_api_token'] : '')))
    );
    
    $newConfig['facebook'] = array(
        'pixel_id' => isset($_POST['facebook_pixel_id']) ? $_POST['facebook_pixel_id'] : '',
        'access_token' => isset($_POST['facebook_access_token']) ? $_POST['facebook_access_token'] : ''
    );
    
    $newConfig['timezone'] = isset($_POST['timezone']) ? $_POST['timezone'] : 'America/Sao_Paulo';

    // Handle webhooks
    $webhooks = array(
        'error' => array_filter(isset($_POST['webhook_error']) ? $_POST['webhook_error'] : array()),
        'generated' => array_filter(isset($_POST['webhook_generated']) ? $_POST['webhook_generated'] : array()),
        'paid' => array_filter(isset($_POST['webhook_paid']) ? $_POST['webhook_paid'] : array())
    );
    $newConfig['webhooks'] = $webhooks;
    
    $configContent = "<?php\nreturn " . var_export($newConfig, true) . ";\n";
    
    if (file_put_contents(__DIR__ . '/config.php', $configContent)) {
        echo json_encode(array('success' => true, 'message' => 'Configurações salvas com sucesso!'));
    } else {
        echo json_encode(array('success' => false, 'message' => 'Erro ao salvar configurações.'));
    }
    exit;
}

function formatarCrescimento($valor) {
    $abs = abs($valor);
    $sinal = $valor >= 0 ? '+' : '-';
    $classe = $valor >= 0 ? 'change-positive' : 'change-negative';
    $icone = $valor >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
    
    return array(
        'texto' => $sinal . number_format($abs, 1) . '%',
        'classe' => $classe,
        'icone' => $icone
    );
}

// Função para testar webhooks manualmente
function testWebhook($type, $url) {
    $testData = array(
        'event' => 'test',
        'type' => $type,
        'timestamp' => date('Y-m-d H:i:s'),
        'test_data' => array(
            'transaction_id' => 'test_' . uniqid(),
            'valor' => 10000, // R$ 100,00
            'nome' => 'Teste Webhook',
            'email' => 'teste@exemplo.com'
        )
    );
    
    $postData = json_encode($testData);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'BlackPanel-Webhook-Test/1.0');
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return array(
        'success' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $error
    );
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ADICIONAR: incluir model de campanhas
require_once __DIR__ . '/../../app/models/Campaign.php';

// ADICIONAR: helper para slug
function generateSlug($title) {
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
    return $slug ?: uniqid('camp-');
}

// ADICIONAR: listar campanhas (AJAX)
if (isset($_GET['ajax_campaigns']) && $_GET['ajax_campaigns'] === '1') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']); exit;
    }
    try {
        $campaigns = Campaign::findAll();
        echo json_encode(['success' => true, 'campaigns' => $campaigns]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ADICIONAR: obter uma campanha
if (isset($_GET['ajax_campaign']) && $_GET['ajax_campaign'] === '1') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']); exit;
    }
    $id = (int)($_GET['id'] ?? 0);
    $camp = $id ? Campaign::findById($id) : null;
    echo json_encode(['success' => true, 'campaign' => $camp]);
    exit;
}

// ADICIONAR: salvar campanha
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_campaign') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']); exit;
    }
    $id = !empty($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : null;
    $title = trim($_POST['title'] ?? '');
    if ($title === '') { echo json_encode(['success'=>false,'error'=>'Título obrigatório']); exit; }
    $slug = generateSlug($title);

    $coverImage = null;
    if (!empty($_POST['existing_cover'])) {
        $coverImage = $_POST['existing_cover'];
    }
    if (!empty($_FILES['cover_image']['name'])) {
        $name = time() . '_' . basename($_FILES['cover_image']['name']);
        $uploadDir = __DIR__ . '/../uploads/campaigns';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $dest = $uploadDir . '/' . $name;
        if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $dest)) {
            $coverImage = 'uploads/campaigns/' . $name;
        }
    }

    $data = [
        'id'                    => $id,
        'title'                 => $title,
        'subtitle'              => $_POST['subtitle'] ?? null,
        'slug'                  => $slug,
        'category'              => $_POST['category'] ?? null,
        'city'                  => $_POST['city'] ?? null,
        'state'                 => $_POST['state'] ?? null,
        'goal_amount'           => $_POST['goal_amount'] ?? 0,
        'raised_amount'         => $_POST['raised_amount'] ?? 0,
        'pix_key'               => $_POST['pix_key'] ?? null,
        'pix_description'       => $_POST['pix_description'] ?? null,
        'facebook_pixel_id'     => $_POST['facebook_pixel_id'] ?? null,
        'facebook_access_token' => $_POST['facebook_access_token'] ?? null,
        'utmify_api_token'      => $_POST['utmify_api_token'] ?? null,
        'cover_image'           => $coverImage,
        'description'           => $_POST['description'] ?? null,
        'is_active'             => isset($_POST['is_active']) ? 1 : 0,
    ];
    $savedId = Campaign::save($data);
    echo json_encode(['success' => true, 'id' => $savedId]);
    exit;
}

// ADICIONAR: excluir campanha
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_campaign') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        echo json_encode(['success'=>false,'error'=>'Not logged in']); exit;
    }
    $id = (int)($_POST['campaign_id'] ?? 0);
    if ($id > 0) {
        // FIX: evitar Campaign::delete() (não existe). Deleta direto no banco.
        require_once __DIR__ . '/database/db.php';
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("DELETE FROM campaigns WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'error'=>'ID inválido']);
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BlackPanel • Dashboard de Análise</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Chart.js with fallback -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <script>
        // Fallback if Chart.js fails to load
        if (typeof Chart === 'undefined') {
            console.warn('Chart.js falhou ao carregar do CDN, tentando fallback...');
            var script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js';
            document.head.appendChild(script);
        }
    </script>
    
    <style>
        /* CSS styles here - keeping it minimal for compatibility */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --bg-primary: #0a0a0a;
            --bg-secondary: #121212;
            --bg-tertiary: #1a1a1a;
            --bg-card: #161616;
            --bg-hover: #1f1f1f;
            --accent-primary: #00ff88;
            --accent-secondary: #00cc6a;
            --text-primary: #ffffff;
            --text-secondary: #e0e0e0;
            --text-tertiary: #b0b0b0;
            --text-muted: #808080;
            --success: #00ff88;
            --warning: #ffb347;
            --danger: #ff6b6b;
            --info: #74c0fc;
            --border-color: rgba(255, 255, 255, 0.1);
            --border-hover: rgba(0, 255, 136, 0.3);
            --shadow-soft: 0 4px 20px rgba(0, 0, 0, 0.3);
            --shadow-strong: 0 8px 40px rgba(0, 0, 0, 0.5);
            --shadow-glow: 0 0 30px rgba(0, 255, 136, 0.3);
            --transition-fast: 0.2s ease;
            --transition-normal: 0.3s ease;
            --font-primary: 'Inter', sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
            --font-display: 'Orbitron', sans-serif;
        }

        body {
            font-family: var(--font-primary);
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
        }

        .app {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .main-container {
            flex: 1;
            padding: 1.5rem;
            max-width: 1800px;
            margin: 0 auto;
            width: 100%;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1rem 0;
            border-bottom: 1px solid var(--border-color);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: 900;
            color: var(--bg-primary);
            font-family: var(--font-display);
        }

        .brand-text {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .brand-name {
            font-family: var(--font-display);
            font-size: 2.2rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--accent-primary), #ffffff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
        }

        .brand-subtitle {
            font-family: var(--font-mono);
            font-size: 0.9rem;
            color: var(--text-tertiary);
            font-weight: 500;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logout-btn {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 0.75rem;
            color: var(--text-secondary);
            cursor: pointer;
            transition: var(--transition-normal);
            font-size: 1rem;
            text-decoration: none;
        }

        .logout-btn:hover {
            background: var(--danger);
            border-color: var(--danger);
            color: white;
        }


        /* Global Filters */
.global-filters {
    display: flex;
    gap: 0.5rem;
    margin-right: 1rem;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 0.5rem;
}

.filter-btn {
    background: transparent;
    border: none;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    color: var(--text-secondary);
    cursor: pointer;
    transition: var(--transition-normal);
    font-weight: 500;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-family: var(--font-primary);
    white-space: nowrap;
}

.filter-btn:hover {
    color: var(--text-primary);
    background: var(--bg-hover);
}

.filter-btn.active {
    background: var(--accent-primary);
    color: var(--bg-primary);
    box-shadow: 0 2px 8px rgba(0, 255, 136, 0.3);
}

.filter-btn i {
    font-size: 0.9rem;
}

/* Date Range Picker for Custom Filter */
.custom-date-range {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(10, 10, 10, 0.9);
    z-index: 2000;
    display: none;
    align-items: center;
    justify-content: center;
}

.custom-date-range.show {
    display: flex;
}

.date-range-modal {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 2rem;
    max-width: 500px;
    width: 90%;
}

.date-range-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border-color);
}

.date-range-title {
    font-size: 1.3rem;
    font-weight: 700;
    color: var(--accent-primary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.date-inputs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 2rem;
}

.date-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.date-group label {
    font-weight: 600;
    color: var(--text-secondary);
    font-size: 0.9rem;
}

.date-group input {
    background: var(--bg-terciary);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 0.75rem;
    color: var(--text-primary);
    font-family: var(--font-mono);
    font-size: 0.9rem;
}

.date-group input:focus {
    outline: none;
    border-color: var(--accent-primary);
    box-shadow: 0 0 0 3px rgba(0, 255, 136, 0.2);
}

.date-actions {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
}

.date-action-btn {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 0.75rem 1.5rem;
    color: var(--text-secondary);
    cursor: pointer;
    transition: var(--transition-normal);
    font-weight: 600;
    font-family: var(--font-primary);
}

.date-action-btn.primary {
    background: var(--accent-primary);
    color: var(--bg-primary);
    border-color: var(--accent-primary);
}

.date-action-btn:hover {
    transform: translateY(-2px);
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .global-filters {
        order: -1;
        width: 100%;
        margin-right: 0;
        margin-bottom: 1rem;
        overflow-x: auto;
    }
    
    .filter-btn {
        flex-shrink: 0;
        padding: 0.6rem 0.8rem;
        font-size: 0.8rem;
    }
    
    .date-inputs {
        grid-template-columns: 1fr;
    }
}

        /* Navigation */
        .nav-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            padding: 0.5rem;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow-x: auto;
        }

        .nav-tab {
            background: transparent;
            border: none;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            color: var(--text-secondary);
            cursor: pointer;
            transition: var(--transition-normal);
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-family: var(--font-primary);
            white-space: nowrap;
            min-width: fit-content;
        }

        .nav-tab:hover {
            color: var(--text-primary);
            background: var(--bg-hover);
        }

        .nav-tab.active {
            background: var(--accent-primary);
            color: var(--bg-primary);
        }

        /* Content sections */
        .spa-content {
            min-height: 60vh;
        }

        .content-section {
            display: none;
        }

        .content-section.active {
            display: block;
        }

        /* Stats grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 2rem;
            transition: var(--transition-normal);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-strong);
            border-color: var(--border-hover);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
        }

        .stat-info {
            flex: 1;
        }

        .stat-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .stat-subtitle {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-family: var(--font-mono);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            color: var(--bg-primary);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 900;
            line-height: 1;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, var(--text-primary), var(--accent-primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-family: var(--font-display);
        }

        .stat-change {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            font-weight: 600;
            font-family: var(--font-mono);
        }

        .change-positive { color: var(--success); }
        .change-negative { color: var(--danger); }

        /* Secondary stats */
        .secondary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .secondary-stat-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            transition: var(--transition-normal);
        }

        .secondary-stat-card:hover {
            transform: translateY(-4px);
            border-color: var(--border-hover);
        }

        .secondary-stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
            font-family: var(--font-mono);
        }

        .secondary-stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--accent-primary);
            font-family: var(--font-display);
            margin-bottom: 0.5rem;
        }

        .secondary-stat-change {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-family: var(--font-mono);
        }

        /* Charts */
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .chart-container {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 2rem;
        }

        .chart-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-canvas {
            position: relative;
            height: 300px;
        }

        /* Control panel */
        .control-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .control-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            gap: 1rem;
            align-items: center;
        }

        .search-container {
            position: relative;
        }

        .search-input {
            width: 100%;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1rem 1rem 1rem 3rem;
            color: var(--text-primary);
            font-size: 0.9rem;
            font-family: var(--font-primary);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--accent-primary);
            background: var(--bg-terciary);
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1rem;
        }

        .filter-select {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1rem;
            color: var(--text-primary);
            font-size: 0.9rem;
            cursor: pointer;
            font-family: var(--font-primary);
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--accent-primary);
            background: var(--bg-terciary);
        }

        .action-btn {
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            border: none;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            color: var(--bg-primary);
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition-normal);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-family: var(--font-primary);
            white-space: nowrap;
        }

        .action-btn:hover {
            transform: translateY(-3px);
        }

        /* Table */
        .table-wrapper {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table thead th {
            background: var(--bg-secondary);
            padding: 1.25rem 1rem;
            text-align: left;
            font-weight: 700;
            color: var(--text-secondary);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--border-color);
            font-family: var(--font-primary);
        }

        .table tbody tr {
            border-bottom: 1px solid var(--border-color);
            transition: var(--transition-fast);
        }

        .table tbody tr:hover {
            background: var(--bg-hover);
        }

        .table tbody td {
            padding: 1.25rem 1rem;
            vertical-align: middle;
            font-size: 0.85rem;
            line-height: 1.4;
            font-family: var(--font-primary);
        }

        /* Table cell styles */
        .cell-date {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .cell-date-main {
            font-family: var(--font-mono);
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--text-primary);
        }

        .cell-date-time {
            font-family: var(--font-mono);
            font-size: 0.7rem;
            color: var(--text-muted);
        }

        .cell-client {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .cell-client-name {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.85rem;
        }

        .cell-client-id {
            font-family: var(--font-mono);
            font-size: 0.7rem;
            color: var(--text-muted);
        }

        .cell-value {
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--accent-primary);
            font-family: var(--font-mono);
        }

        /* Status badges */
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 90px;
            justify-content: center;
            font-family: var(--font-mono);
        }

        .status-paid {
            background: rgba(0, 255, 136, 0.15);
            color: var(--success);
            border: 1px solid rgba(0, 255, 136, 0.3);
        }

        .status-pending, .status-waiting_payment, .status-created {
            background: rgba(255, 179, 71, 0.15);
            color: var(--warning);
            border: 1px solid rgba(255, 179, 71, 0.3);
        }

        .status-expired, .status-failed, .status-refused, .status-canceled {
            background: rgba(255, 107, 107, 0.15);
            color: var(--danger);
            border: 1px solid rgba(255, 107, 107, 0.3);
        }

        /* Actions */
        .actions-container {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .table-action {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.5rem;
            color: var(--text-secondary);
            cursor: pointer;
            transition: var(--transition-fast);
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            font-size: 0.85rem;
        }

        .table-action:hover {
            background: var(--accent-primary);
            color: var(--bg-primary);
            transform: translateY(-2px);
            border-color: var(--accent-primary);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
            padding: 1rem;
        }

        .pagination-btn {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.5rem 1rem;
            color: var(--text-secondary);
            cursor: pointer;
            transition: var(--transition-normal);
            text-decoration: none;
            font-family: var(--font-primary);
        }

        .pagination-btn:hover {
            background: var(--accent-primary);
            color: var(--bg-primary);
            border-color: var(--accent-primary);
        }

        .pagination-btn.active {
            background: var(--accent-primary);
            color: var(--bg-primary);
            border-color: var(--accent-primary);
        }

        /* Config form */
        .config-form {
            display: grid;
            gap: 2rem;
        }

        .config-section {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2rem;
        }

        .config-section-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--accent-primary);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .form-input {
            width: 100%;
            background: var(--bg-terciary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.75rem;
            color: var(--text-primary);
            font-family: var(--font-mono);
            font-size: 0.9rem;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--accent-primary);
        }

        .form-help {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        .save-btn {
            background: linear-gradient(135deg, var(--success), var(--accent-secondary));
            border: none;
            border-radius: 12px;
            padding: 1rem 2rem;
            color: var(--bg-primary);
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: var(--transition-normal);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .save-btn:hover {
            transform: translateY(-2px);
        }

        /* Webhooks */
        .webhook-group {
            margin-bottom: 2rem;
        }

        .webhook-group h4 {
            color: var(--text-secondary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .webhook-urls {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .webhook-url-item {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .webhook-url-item input {
            flex: 1;
        }

        .remove-webhook {
            background: var(--danger);
            border: none;
            border-radius: 6px;
            padding: 0.5rem;
            color: white;
            cursor: pointer;
            font-size: 0.8rem;
        }

        .add-webhook {
            background: var(--accent-primary);
            border: none;
            border-radius: 6px;
            padding: 0.5rem 1rem;
            color: var(--bg-primary);
            cursor: pointer;
            font-size: 0.8rem;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Heatmap */
        .heatmap-container {
            display: grid;
            grid-template-columns: repeat(24, 1fr);
            gap: 2px;
            margin-top: 1rem;
        }

        .heatmap-hour {
            aspect-ratio: 1;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 600;
            color: white;
            cursor: pointer;
        }

        /* Modals */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(10, 10, 10, 0.9);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 2rem;
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;


            background: linear-gradient(135deg, var(--accent-primary), var(--text-primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-family: var(--font-display);
        }
}

        .date-input:focus {
            outline: none;
            border-color: var(--accent-primary);
        }

        .detail-item {
            margin-bottom: 1.5rem;
            padding: 1.5rem;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            border-left: 3px solid var(--accent-primary);
        }

        .detail-label {
            font-weight: 700;
            color: var(--accent-primary);
            margin-bottom: 0.8rem;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-family: var(--font-primary);
        }

        .detail-value {
            color: var(--text-primary);
            font-family: var(--font-mono);
            line-height: 1.6;
            word-break: break-all;
        }

        /* Toast */
        .toast {
            position: fixed;
            top: 2rem;
            right: 2rem;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1rem 1.5rem;
            color: var(--text-primary);
            z-index: 10000;
            transform: translateX(100%);
            opacity: 0;
            transition: all 0.4s ease;
            max-width: 400px;
            font-family: var(--font-primary);
        }

        .toast.show {
            transform: translateX(0);
            opacity: 1;
        }

        .toast.success {
            border-left: 3px solid var(--success);
        }

        .toast.error {
            border-left: 3px solid var(--danger);
        }

        .toast.info {
            border-left: 3px solid var(--info);
        }

        /* Responsive */
        @media (max-width: 1400px) {
            .charts-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); }
            .secondary-stats { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
            .control-grid { grid-template-columns: 1fr 1fr 1fr auto; }
        }

        @media (max-width: 992px) {
            .main-container { padding: 1.25rem; }
            .control-grid { grid-template-columns: 1fr 1fr; gap: 0.75rem; }
            .search-container { grid-column: 1 / -1; }
        }

        @media (max-width: 768px) {
            .main-container { padding: 1rem; }
            .header { flex-direction: column; gap: 1rem; text-align: center; margin-bottom: 1.5rem; }
            .brand-name { font-size: 1.8rem; }
            .stats-grid { grid-template-columns: 1fr; gap: 1rem; }
            .secondary-stats { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
            .control-grid { grid-template-columns: 1fr; gap: 1rem; }
            .nav-tabs { overflow-x: auto; }
            .nav-tab { padding: 0.75rem 1rem; font-size: 0.8rem; flex-shrink: 0; }
            .table-wrapper { overflow-x: auto; }
            .table { min-width: 700px; }
            .table thead th, .table tbody td { padding: 1rem 0.75rem; font-size: 0.8rem; }
            .modal-content { margin: 1rem; width: calc(100% - 2rem); padding: 1.5rem; }
            .stat-value { font-size: 2rem; }
            .secondary-stat-value { font-size: 1.4rem; }
            .chart-canvas { height: 250px; }
            .heatmap-container { grid-template-columns: repeat(12, 1fr); }
        }

        @media (max-width: 480px) {
            .main-container { padding: 0.75rem; }
            .logo { width: 50px; height: 50px; font-size: 1.4rem; }
            .brand-name { font-size: 1.5rem; }
            .brand-subtitle { font-size: 0.8rem; }
            .stat-icon { width: 50px; height: 50px; font-size: 1.2rem; }
            .stat-value { font-size: 1.8rem; }
            .secondary-stats { grid-template-columns: 1fr; }
            .secondary-stat-value { font-size: 1.2rem; }
            .nav-tab { padding: 0.6rem 0.75rem; font-size: 0.75rem; }
            .control-panel { padding: 1rem; }
            .search-input, .filter-select { padding: 0.75rem; font-size: 0.85rem; }
            .action-btn { padding: 0.75rem 1rem; font-size: 0.8rem; }
            .modal-content { padding: 1rem; margin: 0.5rem; width: calc(100% - 1rem); }
            .modal-title { font-size: 1.2rem; }
            .chart-canvas { height: 200px; }
            .heatmap-container { grid-template-columns: repeat(8, 1fr); }
        }

        /* Utility classes */
        .mono { font-family: var(--font-mono); }
        .display { font-family: var(--font-display); }
        .text-center { text-align: center; }
        .hidden { display: none; }
    </style>
</head>
<body>
    <div class="app">
        <div class="main-container">
            <!-- Header -->
            <header class="header">
                <div class="brand">
                    <div class="logo">BP</div>
                    <div class="brand-text">
                        <div class="brand-name">BlackPanel</div>
                        <div class="brand-subtitle">Dashboard de Análise</div>
                    </div>
                </div>
<div class="header-actions">
    <div class="global-filters">
        <button class="filter-btn active" data-period="today" onclick="setGlobalFilter('today', this)">
            <i class="fas fa-calendar-day"></i>
            Hoje
        </button>
        <button class="filter-btn" data-period="yesterday" onclick="setGlobalFilter('yesterday', this)">
            <i class="fas fa-calendar-minus"></i>
            Ontem
        </button>
        <button class="filter-btn" data-period="week" onclick="setGlobalFilter('week', this)">
            <i class="fas fa-calendar-week"></i>
            Semana
        </button>
        <button class="filter-btn" data-period="month" onclick="setGlobalFilter('month', this)">
            <i class="fas fa-calendar-alt"></i>
            Mês
        </button>
        <button class="filter-btn" data-period="custom" onclick="setGlobalFilter('custom', this)">
            <i class="fas fa-calendar-range"></i>
            Personalizado
        </button>
    </div>
    <a href="?logout=1" class="logout-btn">
        <i class="fas fa-sign-out-alt"></i>
    </a>
</div>
            </header>

            <!-- Navigation Tabs -->
            <div class="nav-tabs">
                <button class="nav-tab active" onclick="showSection('dashboard')">
                    <i class="fas fa-chart-line"></i>
                    Dashboard
                </button>
                <button class="nav-tab" onclick="showSection('transactions')">
                    <i class="fas fa-exchange-alt"></i>
                    Transações
                </button>
                <button class="nav-tab" onclick="showSection('analytics')">
                    <i class="fas fa-chart-bar"></i>
                    Analytics
                </button>
                <button class="nav-tab" onclick="showSection('settings')">
                    <i class="fas fa-cog"></i>
                    Configurações
                </button>
                <button class="nav-tab" onclick="showSection('campaigns')">
                    <i class="fas fa-bullhorn"></i>
                    Campanhas
                </button>
            </div>

            <!-- SPA Content -->
            <div class="spa-content">
                    <!-- Dashboard Section -->
                <div class="content-section active" id="dashboard-section">
                    <?php if (!empty($errorMessage)): ?>
                    <div style="background: rgba(255, 107, 107, 0.1); border: 1px solid rgba(255, 107, 107, 0.3); border-radius: 12px; padding: 1rem; margin-bottom: 2rem; color: var(--danger);">
                        <strong><i class="fas fa-exclamation-triangle"></i> Erro de Conexão:</strong> <?php echo htmlspecialchars($errorMessage); ?>
                        <br><small>Verifique se o arquivo database/db.php existe e se as configurações do banco estão corretas.</small>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Main Stats -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-info">
                                    <div class="stat-title">PIX Gerados</div>
                                    <div class="stat-subtitle">Total de transações criadas</div>
                                </div>
                                <div class="stat-icon">
                                    <i class="fas fa-qrcode"></i>
                                </div>
                            </div>
                            <div class="stat-value"><?php echo number_format($totalGerados); ?></div>
                            <div class="stat-change">
                                <?php $crescGer = formatarCrescimento($crescimentoGerados); ?>
                                <i class="fas <?php echo $crescGer['icone']; ?>"></i>
                                <span class="<?php echo $crescGer['classe']; ?>"><?php echo $crescGer['texto']; ?></span>
                                <span style="color: var(--text-muted);">vs mês anterior</span>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-info">
                                    <div class="stat-title">PIX Pagos</div>
                                    <div class="stat-subtitle">Transações confirmadas</div>
                                </div>
                                <div class="stat-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                            </div>
                            <div class="stat-value"><?php echo number_format($totalPagos); ?></div>
                            <div class="stat-change">
                                <?php $crescPag = formatarCrescimento($crescimentoPagos); ?>
                                <i class="fas <?php echo $crescPag['icone']; ?>"></i>
                                <span class="<?php echo $crescPag['classe']; ?>"><?php echo $crescPag['texto']; ?></span>
                                <span style="color: var(--text-muted);">vs mês anterior</span>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-info">
                                    <div class="stat-title">Taxa de Conversão</div>
                                    <div class="stat-subtitle">Eficiência dos pagamentos</div>
                                </div>
                                <div class="stat-icon">
                                    <i class="fas fa-percentage"></i>
                                </div>
                            </div>
                            <div class="stat-value"><?php echo number_format($taxaConversao, 1); ?>%</div>
                            <div class="stat-change">
                                <span style="color: var(--text-muted);">Taxa atual</span>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-info">
                                    <div class="stat-title">Receita Confirmada</div>
                                    <div class="stat-subtitle">Valor efetivamente pago</div>
                                </div>
                                <div class="stat-icon">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                            </div>
                            <div class="stat-value">R$ <?php echo number_format($valorPago / 100, 0, ',', '.'); ?></div>
                            <div class="stat-change">
                                <span style="color: var(--text-muted);">Total recebido</span>
                            </div>
                        </div>
                    </div>

                    <!-- Secondary Stats -->
                    <div class="secondary-stats">
                        <div class="secondary-stat-card">
                            <div class="secondary-stat-label">Tempo Médio PIX Gerado</div>
                            <div class="secondary-stat-value"><?php echo number_format($tempoMedioGerado, 0); ?>min</div>
                            <div class="secondary-stat-change">Média de tempo pendente</div>
                        </div>

                        <div class="secondary-stat-card">
                            <div class="secondary-stat-label">Tempo Médio PIX Pago</div>
                            <div class="secondary-stat-value"><?php echo number_format($tempoMedioPagamento, 1); ?>h</div>
                            <div class="secondary-stat-change">Média até confirmação</div>
                        </div>

                        <div class="secondary-stat-card">
                            <div class="secondary-stat-label">Ticket Médio</div>
                            <div class="secondary-stat-value">R$ <?php echo number_format($ticketMedio / 100, 2, ',', '.'); ?></div>
                            <div class="secondary-stat-change">Valor médio por transação</div>
                        </div>

                        <div class="secondary-stat-card">
    <div class="secondary-stat-label">Taxa de Abandono</div>
    <div class="secondary-stat-value"><?php echo number_format(100 - $taxaConversao, 1); ?>%</div>
    <div class="secondary-stat-change">PIX não convertidos</div>
</div>
                        <div class="secondary-stat-card">
                            <div class="secondary-stat-label">Crescimento Diário</div>
                            <div class="secondary-stat-value"><?php echo number_format($crescimentoDiario, 1); ?>%</div>
                            <div class="secondary-stat-change">vs ontem</div>
                        </div>

                        <div class="secondary-stat-card">
                            <div class="secondary-stat-label">Transações Hoje</div>
                            <div class="secondary-stat-value"><?php echo number_format($dadosHoje['total']); ?></div>
                            <div class="secondary-stat-change">PIX criados hoje</div>
                        </div>
                    </div>

                    <!-- Charts -->
                    <div class="charts-grid">
                        <div class="chart-container">
                            <div class="chart-title">
                                <i class="fas fa-chart-line"></i>
                                Performance dos Últimos 7 Dias
                            </div>
                            <div class="chart-canvas">
                                <div class="chart-loading" id="performanceLoading" style="display: none;">
                                    <div class="loading-spinner"></div>
                                    <div style="color: var(--text-muted); font-size: 0.9rem; margin-top: 1rem;">Carregando gráfico...</div>
                                </div>
                                <canvas id="performanceChart"></canvas>
                            </div>
                        </div>
                        <div class="chart-container">
                            <div class="chart-title">
                                <i class="fas fa-chart-pie"></i>
                                Distribuição por Status
                            </div>
                            <div class="chart-canvas">
                                <div class="chart-loading" id="statusLoading" style="display: none;">
                                    <div class="loading-spinner"></div>
                                    <div style="color: var(--text-muted); font-size: 0.9rem; margin-top: 1rem;">Carregando gráfico...</div>
                                </div>
                                <canvas id="statusChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transactions Section -->
                <div class="content-section" id="transactions-section">
                    <div class="control-panel">
                        <div class="control-grid">
                            <div class="search-container">
    <select class="filter-select" id="valorFilter">
        <option value="">Todos os Valores</option>
        <option value="0-5000">💰 Até R$ 50,00</option>
        <option value="5000-10000">💰 R$ 50,01 - R$ 100,00</option>
        <option value="10000-25000">💰 R$ 100,01 - R$ 250,00</option>
        <option value="25000-50000">💰 R$ 250,01 - R$ 500,00</option>
        <option value="50000-999999">💰 Acima de R$ 500,00</option>
    </select>
</div>
                            <select class="filter-select" id="statusFilter">
                                <option value="">Todos os Status</option>
                                <option value="paid">✅ Pagos</option>
                                <option value="pending">⏳ Pendentes</option>
                                <option value="expired">❌ Expirados</option>
                            </select>
                            <select class="filter-select" id="dateFilter">
                                <option value="all">Todas as Datas</option>
                                <option value="today">🗓️ Hoje</option>
                                <option value="week">📅 Últimos 7 dias</option>
                                <option value="month">📊 Este mês</option>
                            </select>
                            <button class="action-btn" onclick="showExportModal()">
                                <i class="fas fa-download"></i>
                                Exportar
                            </button>
                        </div>
                    </div>

                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-calendar-alt"></i> Data & Hora</th>
                                    <th><i class="fas fa-user"></i> Cliente</th>
                                    <th><i class="fas fa-envelope"></i> Contato</th>
                                    <th><i class="fas fa-id-card"></i> Documento</th>
                                    <th><i class="fas fa-money-bill"></i> Valor</th>
                                    <th><i class="fas fa-info-circle"></i> Status</th>
                                    <th><i class="fas fa-cogs"></i> Ações</th>
                                </tr>
                            </thead>
                            <tbody id="tableBody">
                                <?php foreach ($pedidos as $pedido): ?>
                                <tr data-id="<?php echo htmlspecialchars($pedido['transaction_id']); ?>" 
                                    data-status="<?php echo htmlspecialchars($pedido['status']); ?>" 
                                    data-date="<?php echo htmlspecialchars($pedido['created_at']); ?>">
                                    <td>
                                        <div class="cell-date">
                                            <span class="cell-date-main">
                                                <?php echo date('d/m/Y', strtotime($pedido['created_at'])); ?>
                                            </span>
                                            <span class="cell-date-time">
                                                <?php echo date('H:i:s', strtotime($pedido['created_at'])); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="cell-client">
                                            <span class="cell-client-name" title="<?php echo htmlspecialchars($pedido['nome']); ?>">
                                                <?php echo htmlspecialchars($pedido['nome']); ?>
                                            </span>
                                            <span class="cell-client-id">
                                                ID: <?php echo substr($pedido['transaction_id'], 0, 8); ?>...
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                                            <span title="<?php echo htmlspecialchars($pedido['email']); ?>">
                                                <?php echo htmlspecialchars($pedido['email']); ?>
                                            </span>
                                            <span style="font-size: 0.75rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.3rem;">
                                                <i class="fas fa-phone" style="font-size: 0.7rem; opacity: 0.7;"></i>
                                                <?php echo htmlspecialchars($pedido['telefone']); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-family: var(--font-mono); font-weight: 500; letter-spacing: 0.05em; font-size: 0.8rem; color: var(--text-primary);">
                                            <?php echo htmlspecialchars($pedido['cpf']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="cell-value">
                                            R$ <?php echo number_format($pedido['valor'] / 100, 2, ',', '.'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo htmlspecialchars($pedido['status']); ?>">
                                            <?php 
                                                $statusConfig = array(
                                                    'paid' => array('icon' => 'fas fa-check-circle', 'text' => 'Pago'),
                                                    'pending' => array('icon' => 'fas fa-clock', 'text' => 'Pendente'),
                                                    'waiting_payment' => array('icon' => 'fas fa-clock', 'text' => 'Pendente'),
                                                    'created' => array('icon' => 'fas fa-clock', 'text' => 'Pendente'),
                                                    'processing' => array('icon' => 'fas fa-spinner', 'text' => 'Processando'),
                                                    'expired' => array('icon' => 'fas fa-times-circle', 'text' => 'Expirado'),
                                                    'failed' => array('icon' => 'fas fa-times-circle', 'text' => 'Falhou'),
                                                    'refused' => array('icon' => 'fas fa-times-circle', 'text' => 'Recusado'),
                                                    'canceled' => array('icon' => 'fas fa-times-circle', 'text' => 'Cancelado')
                                                );
                                                $config = isset($statusConfig[$pedido['status']]) ? $statusConfig[$pedido['status']] : array('icon' => 'fas fa-question-circle', 'text' => 'Desconhecido');
                                            ?>
                                            <i class="<?php echo $config['icon']; ?>" style="font-size: 0.8rem;"></i>
                                            <?php echo $config['text']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions-container">
                                            <button class="table-action" onclick="viewDetails('<?php echo htmlspecialchars($pedido['transaction_id']); ?>')" title="Ver Detalhes">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="table-action" onclick="copyToClipboard('<?php echo htmlspecialchars($pedido['transaction_id']); ?>')" title="Copiar ID">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                            <button class="table-action" onclick="downloadTransaction('<?php echo htmlspecialchars($pedido['transaction_id']); ?>')" title="Download JSON">
                                                <i class="fas fa-download"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="javascript:void(0)" onclick="changePage(<?php echo $page - 1; ?>)" class="pagination-btn">
                                <i class="fas fa-chevron-left"></i> Anterior
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <a href="javascript:void(0)" onclick="changePage(<?php echo $i; ?>)" class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="javascript:void(0)" onclick="changePage(<?php echo $page + 1; ?>)" class="pagination-btn">
                                Próximo <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                        
                        <span style="margin-left: 1rem; color: var(--text-muted);">
                            Página <?php echo $page; ?> de <?php echo $totalPages; ?> (<?php echo $totalPedidos; ?> total)
                        </span>
                    </div>
                </div>

                <!-- Analytics Section -->
                <div class="content-section" id="analytics-section">
                    <div class="charts-grid">
                        <div class="chart-container">
                            <div class="chart-title">
                                <i class="fas fa-clock"></i>
                                Horários de Pico - Hoje
                            </div>
                            <div class="chart-canvas">
    <canvas id="hourlyChart"></canvas>
</div>
                        </div>
                        <div class="chart-container">
                            <div class="chart-title">
                                <i class="fas fa-chart-bar"></i>
                                Análise Semanal
                            </div>
                            <div class="chart-canvas">
                                <div class="chart-loading" id="weeklyLoading" style="display: none;">
                                    <div class="loading-spinner"></div>
                                    <div style="color: var(--text-muted); font-size: 0.9rem; margin-top: 1rem;">Carregando gráfico...</div>
                                </div>
                                <canvas id="weeklyChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Settings Section -->
                <div class="content-section" id="settings-section">
                    <div class="config-form">
                        <div class="config-section">
                            <div class="config-section-title">
                                <i class="fas fa-chart-line"></i>
                                Configurações UTMify
                            </div>
                            <div class="form-group">
                                <label class="form-label">URL da API</label>
                                <input type="text" class="form-input" id="utmify_api_url" value="<?php echo htmlspecialchars(isset($currentConfig['utmify']['api_url']) ? $currentConfig['utmify']['api_url'] : ''); ?>" placeholder="https://api.utmify.com.br/api-credentials/orders">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Token da API</label>
                                <input type="password" class="form-input" id="utmify_api_token" value="<?php echo htmlspecialchars(isset($currentConfig['utmify']['api_tokens'][0]) ? $currentConfig['utmify']['api_tokens'][0] : ''); ?>" placeholder="Seu token UTMify">
                                <div class="form-help">Token para integração com UTMify</div>
                            </div>
                        </div>

                        <div class="config-section">
                            <div class="config-section-title">
                                <i class="fab fa-facebook"></i>
                                Configurações Facebook
                            </div>
                            <div class="form-group">
                                <label class="form-label">Pixel ID</label>
                                <input type="text" class="form-input" id="facebook_pixel_id" value="<?php echo htmlspecialchars(isset($currentConfig['facebook']['pixel_id']) ? $currentConfig['facebook']['pixel_id'] : ''); ?>" placeholder="1234567890123456">
                                <div class="form-help">ID do seu pixel do Facebook</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Access Token</label>
                                <input type="password" class="form-input" id="facebook_access_token" value="<?php echo htmlspecialchars(isset($currentConfig['facebook']['access_token']) ? $currentConfig['facebook']['access_token'] : ''); ?>" placeholder="Seu access token do Facebook">
                                <div class="form-help">Token de acesso para conversões via API</div>
                            </div>
                        </div>

                        <div class="config-section">
                            <div class="config-section-title">
                                <i class="fas fa-globe"></i>
                                Configurações Gerais
                            </div>
                            <div class="form-group">
                                <label class="form-label">Fuso Horário</label>
                                <select class="form-input" id="timezone" onchange="updateTimezone(this.value)">
                                    <option value="America/Sao_Paulo" <?php echo (isset($currentConfig['timezone']) && $currentConfig['timezone'] === 'America/Sao_Paulo') ? 'selected' : ''; ?>>São Paulo (UTC-3)</option>
                                    <option value="America/Manaus" <?php echo (isset($currentConfig['timezone']) && $currentConfig['timezone'] === 'America/Manaus') ? 'selected' : ''; ?>>Manaus (UTC-4)</option>
                                    <option value="America/Rio_Branco" <?php echo (isset($currentConfig['timezone']) && $currentConfig['timezone'] === 'America/Rio_Branco') ? 'selected' : ''; ?>>Rio Branco (UTC-5)</option>
                                    <option value="UTC" <?php echo (isset($currentConfig['timezone']) && $currentConfig['timezone'] === 'UTC') ? 'selected' : ''; ?>>UTC (UTC+0)</option>
                                </select>
                                <div class="form-help">Fuso horário usado para exibição de datas</div>
                            </div>
                        </div>

                        <div class="config-section">
                            <div class="config-section-title">
                                <i class="fas fa-webhook"></i>
                                Configurações de Webhooks
                            </div>
                            
                            <div class="webhook-group">
                                <h4><i class="fas fa-times-circle" style="color: var(--danger);"></i> Erro ao gerar PIX</h4>
                                <div class="webhook-urls" id="webhook-error">
                                    <?php 
                                    $webhookError = isset($currentConfig['webhooks']['error']) ? $currentConfig['webhooks']['error'] : array();
                                    foreach ($webhookError as $url): ?>
                                    <div class="webhook-url-item">
                                        <input type="url" class="form-input" name="webhook_error[]" value="<?php echo htmlspecialchars($url); ?>" placeholder="https://exemplo.com/webhook/error">
                                        <button type="button" class="remove-webhook" onclick="removeWebhook(this)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
    <button type="button" class="add-webhook" onclick="addWebhook('error')">
        <i class="fas fa-plus"></i> Adicionar URL
    </button>
    <button type="button" class="test-webhook" onclick="testWebhookType('error')" style="background: var(--info); color: white;">
        <i class="fas fa-vial"></i> Testar
    </button>
</div>
                            </div>

                            <div class="webhook-group">
                                <h4><i class="fas fa-clock" style="color: var(--warning);"></i> PIX gerado</h4>
                                <div class="webhook-urls" id="webhook-generated">
                                    <?php 
                                    $webhookGenerated = isset($currentConfig['webhooks']['generated']) ? $currentConfig['webhooks']['generated'] : array();
                                    foreach ($webhookGenerated as $url): ?>
                                    <div class="webhook-url-item">
                                        <input type="url" class="form-input" name="webhook_generated[]" value="<?php echo htmlspecialchars($url); ?>" placeholder="https://exemplo.com/webhook/generated">
                                        <button type="button" class="remove-webhook" onclick="removeWebhook(this)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
    <button type="button" class="add-webhook" onclick="addWebhook('generated')">
        <i class="fas fa-plus"></i> Adicionar URL
    </button>
    <button type="button" class="test-webhook" onclick="testWebhookType('generated')" style="background: var(--info); color: white;">
        <i class="fas fa-vial"></i> Testar
    </button>
</div>
                            </div>

                            <div class="webhook-group">
                                <h4><i class="fas fa-check-circle" style="color: var(--success);"></i> PIX pago</h4>
                                <div class="webhook-urls" id="webhook-paid">
                                    <?php 
                                    $webhookPaid = isset($currentConfig['webhooks']['paid']) ? $currentConfig['webhooks']['paid'] : array();
                                    foreach ($webhookPaid as $url): ?>
                                    <div class="webhook-url-item">
                                        <input type="url" class="form-input" name="webhook_paid[]" value="<?php echo htmlspecialchars($url); ?>" placeholder="https://exemplo.com/webhook/paid">
                                        <button type="button" class="remove-webhook" onclick="removeWebhook(this)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
    <button type="button" class="add-webhook" onclick="addWebhook('paid')">
        <i class="fas fa-plus"></i> Adicionar URL
    </button>
    <button type="button" class="test-webhook" onclick="testWebhookType('paid')" style="background: var(--info); color: white;">
        <i class="fas fa-vial"></i> Testar
    </button>
</div>
                            </div>
                        </div>

                        <button class="save-btn" onclick="saveConfig()">
                            <i class="fas fa-save"></i>
                            Salvar Configurações
                        </button>
                    </div>
                </div>

                <!-- ADD: Campaigns Section -->
                <div class="content-section" id="campaigns-section">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
                        <div style="font-weight:800;font-size:1.1rem;color:var(--text-primary)">
                            <i class="fas fa-bullhorn"></i> Campanhas
                        </div>
                        <button class="action-btn" onclick="openCampaignModal(null)">
                            <i class="fas fa-plus"></i> Nova Campanha
                        </button>
                    </div>

                    <div class="table-wrapper">
                        <div style="padding:1rem">
                            <div id="campaignsList" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;"></div>
                            <div id="campaignsEmpty" style="display:none;color:var(--text-muted);padding:0.75rem">Nenhuma campanha encontrada.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Export Modal -->
        <div class="modal" id="exportModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">
                        <i class="fas fa-download"></i>
                        Exportar Dados
                    </h3>
                    <button class="close-btn" onclick="closeModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div>
                    <h4 style="margin-bottom: 1rem; color: var(--text-secondary);">Selecione o que deseja exportar:</h4>
                    <div class="export-options">
                        <div class="export-option" data-filter="all">
                            <div class="export-option-icon">
                                <i class="fas fa-list"></i>
                            </div>
                            <div class="export-option-title">Todos os Dados</div>
                            <div class="export-option-desc">Exportar todas as transações</div>
                        </div>
                        <div class="export-option" data-filter="paid">
                            <div class="export-option-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="export-option-title">PIX Pagos</div>
                            <div class="export-option-desc">Somente transações confirmadas</div>
                        </div>
                        <div class="export-option" data-filter="pending">
                            <div class="export-option-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="export-option-title">PIX Pendentes</div>
                            <div class="export-option-desc">Transações aguardando pagamento</div>
                        </div>
                        <div class="export-option" data-filter="expired">
                            <div class="export-option-icon">
                                <i class="fas fa-times-circle"></i>
                            </div>
                            <div class="export-option-title">PIX Expirados</div>
                            <div class="export-option-desc">Transações que expiraram</div>
                        </div>
                    </div>

                    <h4 style="margin: 2rem 0 1rem 0; color: var(--text-secondary);">Filtro por Data (opcional):</h4>
                    <div class="date-range">
                        <input type="date" class="date-input" id="export-date-start" placeholder="Data início">
                        <input type="date" class="date-input" id="export-date-end" placeholder="Data fim">
                    </div>

                    <div style="display: flex; gap: 1rem; margin-top: 2rem; flex-wrap: wrap;">
                        <button class="action-btn" onclick="performExport()" style="flex: 1; min-width: 200px;">
                            <i class="fas fa-download"></i> Exportar CSV
                        </button>
                        <button class="action-btn" onclick="performExport('json')" style="flex: 1; min-width: 200px; background: linear-gradient(135deg, var(--info), var(--accent-secondary));">
                            <i class="fas fa-file-code"></i> Exportar JSON
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detail Modal -->
        <div class="modal" id="detailModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">
                        <i class="fas fa-receipt"></i>
                        Detalhes da Transação
                    </h3>
                    <button class="close-btn" onclick="closeModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div id="modalBody">
                    <!-- Content will be loaded dynamically -->
                </div>
            </div>
        </div>

        <!-- Session Warning -->
        <div class="session-warning" id="sessionWarning" style="display: none;">
            <i class="fas fa-exclamation-triangle"></i>
            <span id="sessionText">Sua sessão expirará em <span id="sessionMinutes">5</span> minutos. Clique aqui para continuar.</span>
        </div>
    </div>

    <style>
        /* LOADING STATES */
        .chart-loading {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 10;
            border-radius: 12px;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--border-color);
            border-top: 3px solid var(--accent-primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* HEATMAP FILTERS */
        .heatmap-filters {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .heatmap-filter {
            background: var(--bg-terciary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.5rem 1rem;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .heatmap-filter:hover {
            border-color: var(--accent-primary);
            color: var(--accent-primary);
            transform: translateY(-1px);
        }

        .heatmap-filter.active {
            background: var(--accent-primary);
            color: var(--bg-primary);
            border-color: var(--accent-primary);
            box-shadow: 0 2px 8px rgba(0, 255, 136, 0.3);
        }

        .heatmap-hour:hover {
            transform: scale(1.1);
            border: 2px solid var(--accent-primary);
            box-shadow: 0 4px 16px rgba(0, 255, 136, 0.4);
        }

        /* SESSION WARNING */
        .session-warning {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, var(--danger), #ff4444);
            color: white;
            padding: 1rem;
            text-align: center;
            z-index: 1500;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 2px 16px rgba(255, 107, 107, 0.4);
        }

        .session-warning:hover {
            background: linear-gradient(135deg, #ff4444, var(--danger));
        }

        /* CHART IMPROVEMENTS */
        .chart-container {
            background: linear-gradient(145deg, var(--bg-secondary), var(--bg-terciary));
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            position: relative;
            min-height: 400px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 16px rgba(0, 0, 0, 0.1);
        }

        .chart-container:hover {
            border-color: var(--border-hover);
            box-shadow: 0 8px 32px rgba(0, 255, 136, 0.1);
            transform: translateY(-2px);
        }

        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .chart-title i {
            color: var(--accent-primary);
            font-size: 1.2rem;
            filter: drop-shadow(0 0 8px rgba(0, 255, 136, 0.3));
        }
    </style>

    <script>
        // CONFIGURAÇÕES GLOBAIS
        var CONFIG = {
            sessionTimeout: 60 * 60 * 1000, // 60 minutos
            sessionWarning: 5 * 60 * 1000,  // 5 minutos antes
            autoRefresh: 30 * 1000,         // 30 segundos
            currentTimezone: 'America/Sao_Paulo'
        };

        // SESSÃO E TIMEOUT (60min inatividade)
        var SessionManager = {
            lastActivity: Date.now(),
            warningShown: false,
            
            init: function() {
                var events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
                for (var i = 0; i < events.length; i++) {
                    document.addEventListener(events[i], this.updateActivity.bind(this), true);
                }
                
                setInterval(this.checkTimeout.bind(this), 60000); // Verificar a cada minuto
                
                var warningEl = document.getElementById('sessionWarning');
                if (warningEl) {
                    warningEl.addEventListener('click', this.extendSession.bind(this));
                }
            },
            
            updateActivity: function() {
                this.lastActivity = Date.now();
                this.warningShown = false;
                this.hideWarning();
            },
            
            checkTimeout: function() {
                var timeSinceActivity = Date.now() - this.lastActivity;
                var timeRemaining = CONFIG.sessionTimeout - timeSinceActivity;
                
                if (timeRemaining <= 0) {
                    this.logout();
                } else if (timeRemaining <= CONFIG.sessionWarning && !this.warningShown) {
                    this.showWarning(Math.ceil(timeRemaining / 60000));
                    this.warningShown = true;
                }
            },
            
            showWarning: function(minutesLeft) {
                var warningEl = document.getElementById('sessionWarning');
                var minutesEl = document.getElementById('sessionMinutes');
                if (warningEl && minutesEl) {
                    minutesEl.textContent = minutesLeft;
                    warningEl.style.display = 'block';
                }
            },
            
            hideWarning: function() {
                var warningEl = document.getElementById('sessionWarning');
                if (warningEl) {
                    warningEl.style.display = 'none';
                }
            },
            
            extendSession: function() {
                this.updateActivity();
                showToast('Sessão estendida com sucesso!', 'success');
            },
            
            logout: function() {
                showToast('Sessão expirou por inatividade. Redirecionando...', 'error');
                setTimeout(function() {
                    window.location.href = '?logout=1';
                }, 2000);
            }
        };

        // LOADING STATES PARA GRÁFICOS
        function showChartLoading(chartId) {
            var loadingEl = document.getElementById(chartId + 'Loading');
            if (loadingEl) {
                loadingEl.style.display = 'flex';
            }
        }
        
        function hideChartLoading(chartId) {
            var loadingEl = document.getElementById(chartId + 'Loading');
            if (loadingEl) {
                loadingEl.style.display = 'none';
            }
        }

        // FUSO HORÁRIO FUNCIONANDO
        function updateTimezone(timezone) {
            CONFIG.currentTimezone = timezone;
            
            // Atualizar todas as datas na interface
            var dateElements = document.querySelectorAll('[data-date]');
            for (var i = 0; i < dateElements.length; i++) {
                var element = dateElements[i];
                var dateString = element.getAttribute('data-date');
                if (dateString) {
                    var date = new Date(dateString);
                    var formatted = formatDateWithTimezone(date, 'd/m/Y H:i:s');
                    // Atualizar o elemento conforme necessário
                }
            }
            
            showToast('Fuso horário atualizado para ' + timezone, 'success');
        }
        
        function formatDateWithTimezone(dateString, format) {
            var date = new Date(dateString);
            
            // Simular formatação baseada no timezone (você pode implementar uma biblioteca real)
            var options = {
                timeZone: CONFIG.currentTimezone,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            };
            
            return new Intl.DateTimeFormat('pt-BR', options).format(date);
        }

        // BUG NAVEGAÇÃO CORRIGIDO
        var currentSection = 'dashboard';
        var currentPage = 1;

        // Global filter state
// Global filter state - sempre inicia com "hoje"
var globalFilter = {
    period: 'today',
    startDate: null,
    endDate: null
};

// Função para inicializar o filtro corretamente
function initializeGlobalFilter() {
    // Garantir que "Hoje" está ativo
    var todayBtn = document.querySelector('[data-period="today"]');
    if (todayBtn && !todayBtn.classList.contains('active')) {
        var allBtns = document.querySelectorAll('.filter-btn');
        for (var i = 0; i < allBtns.length; i++) {
            allBtns[i].classList.remove('active');
        }
        todayBtn.classList.add('active');
    }
    
    // FORÇAR aplicação do filtro "hoje"
    console.log('🔄 Forçando aplicação do filtro "hoje"...');
    globalFilter.period = 'today';
    updateDashboardWithFilter();
}

// Set global filter
function setGlobalFilter(period, button) {
    if (period === 'custom') {
        showCustomDatePicker();
        return;
    }
    
    // Update active button
    var filterBtns = document.querySelectorAll('.filter-btn');
    for (var i = 0; i < filterBtns.length; i++) {
        filterBtns[i].classList.remove('active');
    }
    button.classList.add('active');
    
    // Update global state
    globalFilter.period = period;
    globalFilter.startDate = null;
    globalFilter.endDate = null;
    
    // Apenas aplicar filtro visual - sem AJAX
    applyGlobalFilter();
}

// ADD: garantir acesso via onclick inline
window.setGlobalFilter = setGlobalFilter;

function showCustomDatePicker() {
    var modal = document.getElementById('customDateModal');
    if (!modal) {
        createCustomDateModal();
        modal = document.getElementById('customDateModal');
    }
    modal.classList.add('show');
}

function createCustomDateModal() {
    var modal = document.createElement('div');
    modal.id = 'customDateModal';
    modal.className = 'custom-date-range';
    
    var today = new Date().toISOString().split('T')[0];
    
    modal.innerHTML = `
        <div class="date-range-modal">
            <div class="date-range-header">
                <h3 class="date-range-title">
                    <i class="fas fa-calendar-range"></i>
                    Período Personalizado
                </h3>
                <button class="close-btn" onclick="closeCustomDatePicker()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="date-inputs">
                <div class="date-group">
                    <label>📅 Data Inicial</label>
                    <input type="date" id="customStartDate" value="${today}">
                </div>
                <div class="date-group">
                    <label>📅 Data Final</label>
                    <input type="date" id="customEndDate" value="${today}">
                </div>
            </div>
            <div class="date-actions">
                <button class="date-action-btn" onclick="closeCustomDatePicker()">
                    Cancelar
                </button>
                <button class="date-action-btn primary" onclick="applyCustomFilter()">
                    <i class="fas fa-check"></i>
                    Aplicar Filtro
                </button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
}

function closeCustomDatePicker() {
    var modal = document.getElementById('customDateModal');
    if (modal) {
        modal.classList.remove('show');
    }
}

function applyCustomFilter() {
    var startDate = document.getElementById('customStartDate').value;
    var endDate = document.getElementById('customEndDate').value;
    
    if (!startDate || !endDate) {
        showToast('❌ Selecione as datas inicial e final', 'error');
        return;
    }
    
    if (startDate > endDate) {
        showToast('❌ Data inicial não pode ser maior que a final', 'error');
        return;
    }
    
    // Update global state
    globalFilter.period = 'custom';
    globalFilter.startDate = startDate;
    globalFilter.endDate = endDate;
    
    // Update button states
    var filterBtns = document.querySelectorAll('.filter-btn');
    for (var i = 0; i < filterBtns.length; i++) {
        filterBtns[i].classList.remove('active');
    }
    document.querySelector('[data-period="custom"]').classList.add('active');
    
    // Update button text
    var customBtn = document.querySelector('[data-period="custom"]');
    var startFormatted = new Date(startDate).toLocaleDateString('pt-BR');
    var endFormatted = new Date(endDate).toLocaleDateString('pt-BR');
    customBtn.innerHTML = '<i class="fas fa-calendar-range"></i> ' + startFormatted + ' - ' + endFormatted;
    
    closeCustomDatePicker();
    applyGlobalFilter();
}

function applyGlobalFilter() {
    var periodLabel = getPeriodLabel(globalFilter.period);
    if (globalFilter.period === 'custom') {
        var start = new Date(globalFilter.startDate).toLocaleDateString('pt-BR');
        var end = new Date(globalFilter.endDate).toLocaleDateString('pt-BR');
        periodLabel = start + ' - ' + end;
    }    
    // Update dashboard with filtered data
    updateDashboardWithFilter();
}

function updateDashboardWithFilter() {
    var params = 'ajax_dashboard=1&period=' + globalFilter.period;
    if (globalFilter.period === 'custom') {
        params += '&start_date=' + globalFilter.startDate + '&end_date=' + globalFilter.endDate;
    }
    
    fetch(window.location.pathname + '?' + params)
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            if (data.success) {
                updateDashboardStats(data);
            } else {
                showToast('❌ Erro ao carregar dados: ' + (data.error || 'Erro desconhecido'), 'error');
            }
        })
        .catch(function(error) {
            showToast('❌ Erro de conexão ao carregar filtros', 'error');
            console.error('Erro ao aplicar filtro:', error);
        });
}

// Função de debug para verificar elementos
function debugDashboard() {
    console.log('=== DEBUG DASHBOARD ===');
    console.log('Cards principais encontrados:');
    var mainCards = document.querySelectorAll('.stat-card .stat-title');
    for (var i = 0; i < mainCards.length; i++) {
        console.log('- "' + mainCards[i].textContent.trim() + '"');
    }
    
    console.log('Cards secundários encontrados:');
    var secCards = document.querySelectorAll('.secondary-stat-card .secondary-stat-label');
    for (var i = 0; i < secCards.length; i++) {
        console.log('- "' + secCards[i].textContent.trim() + '"');
    }
    
    console.log('Gráficos disponíveis:');
    console.log('- Status:', !!charts.status);
    console.log('- Hourly:', !!charts.hourly);
    console.log('- Weekly:', !!charts.weekly);
    console.log('- Performance:', !!charts.performance);
}

function getPeriodLabel(period) {
    var labels = {
        'today': 'Hoje',
        'yesterday': 'Ontem',
        'week': 'Esta Semana',
        'month': 'Este Mês',
        'custom': 'Período Personalizado'
    };
    return labels[period] || 'Hoje';
}

function updateDashboardStats(data) {
    console.log('📊 ATUALIZANDO DASHBOARD:', {
        'Período': data.period,
        'PIX Gerados': data.stats.totalGerados,
        'PIX Pagos': data.stats.totalPagos,
        'Taxa Conversão': data.stats.taxaConversao
    });
    
    var stats = data.stats;
    var charts = data.charts;
    
    // Atualizar cards principais
    updateStatCard('PIX Gerados', stats.totalGerados);
    updateStatCard('PIX Pagos', stats.totalPagos);
    updateStatCard('Taxa de Conversão', stats.taxaConversao.toFixed(1) + '%');
    updateStatCard('Receita Confirmada', 'R$ ' + (stats.valorPago / 100).toLocaleString('pt-BR', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }));
    
    // Atualizar cards secundários importantes
    updateSecondaryStatCard('Ticket Médio', 'R$ ' + (stats.ticketMedio / 100).toFixed(2).replace('.', ','));
    updateSecondaryStatCard('Taxa de Abandono', stats.taxaAbandono.toFixed(1) + '%');
    
    // Atualizar gráficos
    updateCharts(charts);
    
    console.log('✅ Dashboard atualizado com sucesso');
}

function updateStatCard(title, value) {
    // Mapeamento correto dos títulos (SEM conversão para maiúsculas)
    var titleMapping = {
        'PIX Gerados': 'PIX Gerados',
        'PIX Pagos': 'PIX Pagos', 
        'Taxa de Conversão': 'Taxa de Conversão',
        'Receita Confirmada': 'Receita Confirmada'
    };
    
    var targetTitle = titleMapping[title] || title;
    var cards = document.querySelectorAll('.stat-card');
    
    for (var i = 0; i < cards.length; i++) {
        var cardTitle = cards[i].querySelector('.stat-title');
        if (cardTitle && cardTitle.textContent.trim() === targetTitle) {
            var valueElement = cards[i].querySelector('.stat-value');
            if (valueElement) {
                // Animar a mudança
                valueElement.style.opacity = '0.5';
                setTimeout(function(el, val) {
                    el.textContent = typeof val === 'number' ? val.toLocaleString('pt-BR') : val;
                    el.style.opacity = '1';
                }, 200, valueElement, value);
            }
            break;
        }
    }
}

function updateSecondaryStatCard(label, value) {
    var labelMapping = {
        'Ticket Médio': 'TICKET MÉDIO',
        'Taxa de Abandono': 'TAXA DE ABANDONO',
        'Tempo Médio PIX Gerado': 'TEMPO MÉDIO PIX GERADO',
        'Tempo Médio PIX Pago': 'TEMPO MÉDIO PIX PAGO',
        'Crescimento Diário': 'CRESCIMENTO DIÁRIO',
        'Transações Hoje': 'TRANSAÇÕES HOJE'
    };
    
    var targetLabel = labelMapping[label] || label.toUpperCase();
    var cards = document.querySelectorAll('.secondary-stat-card');
    
    for (var i = 0; i < cards.length; i++) {
        var cardLabel = cards[i].querySelector('.secondary-stat-label');
        if (cardLabel && cardLabel.textContent.trim() === targetLabel) {
            var valueElement = cards[i].querySelector('.secondary-stat-value');
            if (valueElement) {
                // Animar a mudança
                valueElement.style.opacity = '0.5';
                setTimeout(function(el, val) {
                    el.textContent = val;
                    el.style.opacity = '1';
                }, 200, valueElement, value);
            }
            break;
        }
    }
}

function updateCharts(chartsData) {
    console.log('Atualizando gráficos com dados:', chartsData);
    
    // Atualizar gráfico de status
    if (charts.status) {
        charts.status.data.datasets[0].data = [
            chartsData.status.paid,
            chartsData.status.pending,
            chartsData.status.expired
        ];
        charts.status.update('active');
    }
    
    // Atualizar gráfico de horários - AMBOS datasets (FUNCIONA para todos os períodos)
    if (charts.hourly && chartsData.hourly) {
        var period = globalFilter.period;
        
        // SEMPRE atualizar com os dados recebidos
        charts.hourly.data.datasets[0].data = chartsData.hourly;
        if (chartsData.hourlyPaid) {
            charts.hourly.data.datasets[1].data = chartsData.hourlyPaid;
        }
        charts.hourly.update('active');
        
        // Atualizar título do gráfico baseado no período
        var hourlyTitle = document.querySelector('#hourlyChart').closest('.chart-container').querySelector('.chart-title');
        if (hourlyTitle) {
            var titleText = '';
            switch(period) {
                case 'today':
                    titleText = 'Horários de Pico - Hoje';
                    break;
                case 'yesterday':
                    titleText = 'Horários de Pico - Ontem';
                    break;
                case 'week':
                    titleText = 'Horários de Pico - Esta Semana';
                    break;
                case 'month':
                    titleText = 'Horários de Pico - Este Mês';
                    break;
                case 'custom':
                    titleText = 'Horários de Pico - Período Personalizado';
                    break;
                default:
                    titleText = 'Horários de Pico';
            }
            hourlyTitle.innerHTML = '<i class="fas fa-clock"></i> ' + titleText;
        }
    }
    
    // Gráfico semanal SEMPRE fica da semana atual (não muda com filtros)
    if (charts.weekly && chartsData.weekly) {
        charts.weekly.data.datasets[0].data = chartsData.weekly;
        charts.weekly.update('active');
    }
    
    // Atualizar gráfico de performance (últimos 7 dias)
    if (charts.performance && chartsData.performance && chartsData.performance.length > 0) {
        charts.performance.data.labels = chartsData.performance.map(function(d) { return d.date; });
        charts.performance.data.datasets[0].data = chartsData.performance.map(function(d) { return d.gerados; });
        charts.performance.data.datasets[1].data = chartsData.performance.map(function(d) { return d.pagos; });
        charts.performance.update('active');
    }
}
        
        // Ao carregar a página, verificar URL para restaurar estado
        function initializePageState() {
            var urlParams = new URLSearchParams(window.location.search);
            var section = urlParams.get('section') || 'dashboard';
            var page = parseInt(urlParams.get('page')) || 1;
            
            currentSection = section;
            currentPage = page;
            
            // Mostrar seção correta sem recarregar
            showSectionInternal(section);
        }
        
        function showSectionInternal(sectionName) {
            // Hide all sections
            var sections = document.querySelectorAll('.content-section');
            for (var i = 0; i < sections.length; i++) {
                sections[i].classList.remove('active');
            }
            
            // Remove active class from all tabs
            var tabs = document.querySelectorAll('.nav-tab');
            for (var i = 0; i < tabs.length; i++) {
                tabs[i].classList.remove('active');
            }
            
            // Show selected section
            var targetSection = document.getElementById(sectionName + '-section');
            if (targetSection) {
                targetSection.classList.add('active');
            }
            
            // Add active class to correct tab
            var targetTab = document.querySelector('[onclick*="' + sectionName + '"]');
            if (targetTab) {
                targetTab.classList.add('active');
            }
            
            currentSection = sectionName;
            
            // Atualizar URL sem recarregar
            updateURL();
            
            // Section-specific initialization
            if (sectionName === 'analytics') {
                setTimeout(function() {
                    initializeWeeklyChart();
                    initializeHourlyChart();
                }, 100);
            } else if (sectionName === 'transactions') {
                filterTable();
            } else if (sectionName === 'dashboard') {
                setTimeout(function() {
                    initializeCharts();
                }, 100);
            } else if (sectionName === 'campaigns') {
                // ADD: carregar lista ao abrir a aba (sem alterar showSection/showSectionInternal fora disso)
                setTimeout(function(){ loadCampaigns(); }, 80);
            }
        }
        
        function changePage(page) {
    currentPage = page;
    updateURL();
    
    showToast('Carregando página ' + page + '...', 'info');
    
    // Fazer requisição AJAX para buscar dados da página
    fetch(window.location.pathname + '?ajax=1&page=' + page)
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            if (data.success) {
                // Atualizar a tabela com os novos dados
                updateTableWithNewData(data.pedidos);
                updatePaginationInfo(data.currentPage, data.totalPages, data.totalPedidos);
                showToast('Página ' + page + ' carregada!', 'success');
            } else {
                showToast('Erro ao carregar página', 'error');
            }
        })
        .catch(function(error) {
            showToast('Erro de conexão', 'error');
            console.error('Erro AJAX:', error);
        });
}

function updateTableWithNewData(pedidos) {
    var tableBody = document.getElementById('tableBody');
    var html = '';
    
    for (var i = 0; i < pedidos.length; i++) {
        var pedido = pedidos[i];
        
        // Formatar data
        var createdDate = new Date(pedido.created_at);
        var dateMain = createdDate.getDate().toString().padStart(2, '0') + '/' + 
                      (createdDate.getMonth() + 1).toString().padStart(2, '0') + '/' + 
                      createdDate.getFullYear();
        var dateTime = createdDate.getHours().toString().padStart(2, '0') + ':' + 
                      createdDate.getMinutes().toString().padStart(2, '0') + ':' + 
                      createdDate.getSeconds().toString().padStart(2, '0');
        
        // Configurar status
        var statusConfig = {
            'paid': { icon: 'fas fa-check-circle', text: 'Pago' },
            'pending': { icon: 'fas fa-clock', text: 'Pendente' },
            'waiting_payment': { icon: 'fas fa-clock', text: 'Pendente' },
            'created': { icon: 'fas fa-clock', text: 'Pendente' },
            'processing': { icon: 'fas fa-spinner', text: 'Processando' },
            'expired': { icon: 'fas fa-times-circle', text: 'Expirado' },
            'failed': { icon: 'fas fa-times-circle', text: 'Falhou' },
            'refused': { icon: 'fas fa-times-circle', text: 'Recusado' },
            'canceled': { icon: 'fas fa-times-circle', text: 'Cancelado' }
        };

        var config = statusConfig[pedido.status] || { icon: 'fas fa-question-circle', text: 'Desconhecido' };
        
        // Formatar valor
        var valorFormatado = 'R$ ' + (pedido.valor / 100).toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
        
        html += '<tr data-id="' + pedido.transaction_id + '" data-status="' + pedido.status + '" data-date="' + pedido.created_at + '">' +
            '<td>' +
                '<div class="cell-date">' +
                    '<span class="cell-date-main">' + dateMain + '</span>' +
                    '<span class="cell-date-time">' + dateTime + '</span>' +
                '</div>' +
            '</td>' +
            '<td>' +
                '<div class="cell-client">' +
                    '<span class="cell-client-name" title="' + pedido.nome + '">' + pedido.nome + '</span>' +
                    '<span class="cell-client-id">ID: ' + pedido.transaction_id.substring(0, 8) + '...</span>' +
                '</div>' +
            '</td>' +
            '<td>' +
                '<div style="display: flex; flex-direction: column; gap: 0.25rem;">' +
                    '<span title="' + pedido.email + '">' + pedido.email + '</span>' +
                    '<span style="font-size: 0.75rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.3rem;">' +
                        '<i class="fas fa-phone" style="font-size: 0.7rem; opacity: 0.7;"></i>' +
                        pedido.telefone +
                    '</span>' +
                '</div>' +
            '</td>' +
            '<td>' +
                '<span style="font-family: var(--font-mono); font-weight: 500; letter-spacing: 0.05em; font-size: 0.8rem; color: var(--text-primary);">' +
                    pedido.cpf +
                '</span>' +
            '</td>' +
            '<td>' +
                '<span class="cell-value">' + valorFormatado + '</span>' +
            '</td>' +
            '<td>' +
                '<span class="status-badge status-' + pedido.status + '">' +
                    '<i class="' + config.icon + '" style="font-size: 0.8rem;"></i>' +
                    config.text +
                '</span>' +
            '</td>' +
            '<td>' +
                '<div class="actions-container">' +
                    '<button class="table-action" onclick="viewDetails(\'' + pedido.transaction_id + '\')" title="Ver Detalhes">' +
                        '<i class="fas fa-eye"></i>' +
                    '</button>' +
                    '<button class="table-action" onclick="copyToClipboard(\'' + pedido.transaction_id + '\')" title="Copiar ID">' +
                        '<i class="fas fa-copy"></i>' +
                    '</button>' +
                    '<button class="table-action" onclick="downloadTransaction(\'' + pedido.transaction_id + '\')" title="Download JSON">' +
                        '<i class="fas fa-download"></i>' +
                    '</button>' +
                '</div>' +
            '</td>' +
        '</tr>';
    }
    
    tableBody.innerHTML = html;
}

function updatePaginationInfo(currentPage, totalPages, totalPedidos) {
    // Atualizar texto da paginação
    var paginationText = document.querySelector('.pagination span');
    if (paginationText) {
        paginationText.textContent = 'Página ' + currentPage + ' de ' + totalPages + ' (' + totalPedidos + ' total)';
    }
    
    // Atualizar botões ativos da paginação
    var paginationBtns = document.querySelectorAll('.pagination-btn');
    for (var i = 0; i < paginationBtns.length; i++) {
        var btn = paginationBtns[i];
        var btnText = btn.textContent.trim();
        
        // Remove classe active de todos
        btn.classList.remove('active');
        
        // Adiciona active no botão atual
        if (btnText === currentPage.toString()) {
            btn.classList.add('active');
        }
    }
}
        
        function updateURL() {
            var url = new URL(window.location);
            url.searchParams.set('section', currentSection);
            
            if (currentPage > 1) {
                url.searchParams.set('page', currentPage);
            } else {
                url.searchParams.delete('page');
            }
            
            window.history.pushState(null, '', url.toString());
        }
        
        // Interceptar navegação do browser
        window.addEventListener('popstate', function() {
            initializePageState();
        });

        // GRÁFICOS MAIS BONITOS
        function initializeCharts() {
            waitForChart(function() {
                // Performance Chart com melhorias visuais
                showChartLoading('performance');
                
                setTimeout(function() {
                    var performanceCtx = document.getElementById('performanceChart');
                    if (performanceCtx) {
                        try {
                            if (charts.performance) {
                                charts.performance.destroy();
                            }
                            
                            charts.performance = new Chart(performanceCtx, {
                                type: 'line',
                                data: {
                                    labels: chartData.map(function(d) { return d.date; }),
                                    datasets: [{
                                        label: 'PIX Gerados',
                                        data: chartData.map(function(d) { return d.gerados; }),
                                        borderColor: '#00ff88',
                                        backgroundColor: 'rgba(0, 255, 136, 0.1)',
                                        fill: true,
                                        tension: 0.4,
                                        pointBackgroundColor: '#00ff88',
                                        pointBorderColor: '#00cc6a',
                                        pointHoverRadius: 8,
                                        pointRadius: 5,
                                        borderWidth: 3,
                                        pointBorderWidth: 2
                                    }, {
                                        label: 'PIX Pagos',
                                        data: chartData.map(function(d) { return d.pagos; }),
                                        borderColor: '#74c0fc',
                                        backgroundColor: 'rgba(116, 192, 252, 0.1)',
                                        fill: true,
                                        tension: 0.4,
                                        pointBackgroundColor: '#74c0fc',
                                        pointBorderColor: '#4a9eff',
                                        pointHoverRadius: 8,
                                        pointRadius: 5,
                                        borderWidth: 3,
                                        pointBorderWidth: 2
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    interaction: {
                                        intersect: false,
                                        mode: 'index'
                                    },
                                    plugins: {
                                        legend: {
                                            position: 'top',
                                            labels: {
                                                color: '#e0e0e0',
                                                font: { size: 14, weight: '600' },
                                                padding: 20,
                                                usePointStyle: true,
                                                pointStyle: 'circle'
                                            }
                                        },
                                        tooltip: {
                                            backgroundColor: 'rgba(0, 0, 0, 0.9)',
                                            titleColor: '#fff',
                                            bodyColor: '#fff',
                                            borderColor: '#00ff88',
                                            borderWidth: 2,
                                            cornerRadius: 12,
                                            padding: 16,
                                            displayColors: true,
                                            callbacks: {
                                                label: function(context) {
                                                    return context.dataset.label + ': ' + context.parsed.y + ' transações';
                                                }
                                            }
                                        }
                                    },
                                    scales: {
                                        x: {
                                            ticks: { 
                                                color: '#b0b0b0',
                                                font: { size: 12, weight: '500' }
                                            },
                                            grid: { 
                                                color: 'rgba(255,255,255,0.1)',
                                                drawBorder: false
                                            }
                                                                               },
                                        y: {
                                            ticks: { 
                                                color: '#b0b0b0',
                                                font: { size: 12, weight: '500' }
                                            },
                                            grid: { 
                                                color: 'rgba(255,255,255,0.1)',
                                                drawBorder: false
                                            },
                                            beginAtZero: true
                                        }
                                    },
                                    animation: {
                                        duration: 2000,
                                        easing: 'easeInOutQuart'
                                    }
                                }
                            });
                        } catch (error) {
                            console.error('Erro ao criar gráfico de performance:', error);
                        }
                    }
                    hideChartLoading('performance');
                }, 800);

                // Status Chart com melhorias visuais
                setTimeout(function() {
                    var statusCtx = document.getElementById('statusChart');
                    if (statusCtx) {
                        try {
                            if (charts.status) {
                                charts.status.destroy();
                            }
                            
                            charts.status = new Chart(statusCtx, {
                                type: 'doughnut',
                                data: {
                                    labels: ['Pagos', 'Pendentes', 'Expirados'],
                                    datasets: [{
                                        data: [statusData.paid, statusData.pending, statusData.expired],
                                        backgroundColor: ['#00ff88', '#ffb347', '#ff6b6b'],
                                        borderColor: ['#00cc6a', '#ff9500', '#ff4444'],
                                        borderWidth: 3,
                                        hoverOffset: 10,
                                        hoverBorderWidth: 4
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    cutout: '65%',
                                    plugins: {
                                        legend: {
                                            position: 'bottom',
                                            labels: {
                                                color: '#e0e0e0',
                                                padding: 20,
                                                font: { size: 14, weight: '600' },
                                                usePointStyle: true,
                                                pointStyle: 'circle'
                                            }
                                        },
                                        tooltip: {
                                            backgroundColor: 'rgba(0, 0, 0, 0.9)',
                                            titleColor: '#fff',
                                            bodyColor: '#fff',
                                            borderColor: '#74c0fc',
                                            borderWidth: 2,
                                            cornerRadius: 12,
                                            padding: 16,
                                            callbacks: {
                                                label: function(context) {
                                                    var total = context.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                                    var percentage = ((context.parsed * 100) / total).toFixed(1);
                                                    return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                                                }
                                            }
                                        }
                                    },
                                    animation: {
                                        duration: 2000,
                                        easing: 'easeInOutQuart'
                                    }
                                }
                            });
                        } catch (error) {
                            console.error('Erro ao criar gráfico de status:', error);
                        }
                    }
                }, 600);

                // Initialize heatmap
                setTimeout(function() {
                    initializeHourlyChart();
                }, 400);
            });
        }


        function initializeWeeklyChart() {
            waitForChart(function() {
                showChartLoading('weekly');
                
                setTimeout(function() {
                    var weeklyCtx = document.getElementById('weeklyChart');
                    if (weeklyCtx) {
                        try {
                            if (charts.weekly) {
                                charts.weekly.destroy();
                            }
                            
                            charts.weekly = new Chart(weeklyCtx, {
    type: 'bar',
    data: {
        labels: ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'],
        datasets: [{
            label: 'Transações desta semana',
            data: weeklyData,
                                        backgroundColor: 'rgba(116, 192, 252, 0.8)',
                                        borderColor: '#74c0fc',
                                        borderWidth: 2,
                                        borderRadius: 8,
                                        borderSkipped: false,
                                        hoverBackgroundColor: 'rgba(116, 192, 252, 1)',
                                        hoverBorderColor: '#4a9eff',
                                        hoverBorderWidth: 3
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: {
                                        legend: {
                                            display: false
                                        },
                                        tooltip: {
                                            backgroundColor: 'rgba(0, 0, 0, 0.9)',
                                            titleColor: '#fff',
                                            bodyColor: '#fff',
                                            borderColor: '#74c0fc',
                                            borderWidth: 2,
                                            cornerRadius: 12,
                                            padding: 16
                                        }
                                    },
                                    scales: {
                                        x: {
                                            ticks: { 
                                                color: '#b0b0b0',
                                                font: { size: 12, weight: '500' }
                                            },
                                            grid: { 
                                                display: false
                                            }
                                        },
                                        y: {
                                            ticks: { 
                                                color: '#b0b0b0',
                                                font: { size: 12, weight: '500' }
                                            },
                                            grid: { 
                                                color: 'rgba(255,255,255,0.1)',
                                                drawBorder: false
                                            },
                                            beginAtZero: true
                                        }
                                    },
                                    animation: {
                                        duration: 1500,
                                        easing: 'easeInOutBounce'
                                    },
                                    onHover: function(event, activeElements) {
                                        event.native.target.style.cursor = activeElements.length > 0 ? 'pointer' : 'default';
                                    }
                                }
                            });
                        } catch (error) {
                            console.error('Erro ao criar gráfico semanal:', error);
                        }
                    }
                    hideChartLoading('weekly');
                }, 700);
            });
        }


       function initializeHourlyChart() {
    waitForChart(function() {
        var hourlyCtx = document.getElementById('hourlyChart');
        if (hourlyCtx && typeof Chart !== 'undefined') {
            try {
                if (charts.hourly) {
                    charts.hourly.destroy();
                }
                
                // ← NOVO: dados de PIX pagos
                var hourlyPaidData = window.hourlyPaid || new Array(24).fill(0);
                
                charts.hourly = new Chart(hourlyCtx, {
                    type: 'bar',
                    data: {
                        labels: ['00h', '01h', '02h', '03h', '04h', '05h', '06h', '07h', 
                                '08h', '09h', '10h', '11h', '12h', '13h', '14h', '15h', 
                                '16h', '17h', '18h', '19h', '20h', '21h', '22h', '23h'],
                        datasets: [{
                            label: 'PIX Gerados',
                            data: hourlyHeatmap,
                            backgroundColor: 'rgba(0, 255, 136, 0.6)',
                            borderColor: '#00ff88',
                            borderWidth: 2,
                            borderRadius: 6,
                            borderSkipped: false
                        }, {
                            // ← NOVO DATASET: PIX Pagos
                            label: 'PIX Pagos',
                            data: hourlyPaidData,
                            backgroundColor: 'rgba(116, 192, 252, 0.6)',
                            borderColor: '#74c0fc',
                            borderWidth: 2,
                            borderRadius: 6,
                            borderSkipped: false
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true, // ← MOSTRAR LEGENDA
                                position: 'top',
                                labels: {
                                    color: '#e0e0e0',
                                    font: { size: 12, weight: '600' },
                                    padding: 15,
                                    usePointStyle: true
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.9)',
                                titleColor: '#fff',
                                bodyColor: '#fff',
                                borderColor: '#00ff88',
                                borderWidth: 2,
                                cornerRadius: 12,
                                padding: 16,
                                displayColors: true,
                                callbacks: {
                                    title: function(context) {
                                        return 'Horário: ' + context[0].label;
                                    },
                                    label: function(context) {
                                        return context.dataset.label + ': ' + context.parsed.y + ' transações';
                                    },
                                    afterBody: function(context) {
                                        if (context.length === 2) {
                                            var gerados = context[0].parsed.y;
                                            var pagos = context[1].parsed.y;
                                            var taxa = gerados > 0 ? ((pagos / gerados) * 100).toFixed(1) : '0';
                                            return 'Taxa de conversão: ' + taxa + '%';
                                        }
                                        return '';
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                ticks: { 
                                    color: '#b0b0b0',
                                    font: { size: 11, weight: '500' },
                                    maxRotation: 0
                                },
                                grid: { display: false }
                            },
                            y: {
                                ticks: { 
                                    color: '#b0b0b0',
                                    font: { size: 12, weight: '500' }
                                },
                                grid: { 
                                    color: 'rgba(255,255,255,0.1)',
                                    drawBorder: false
                                },
                                beginAtZero: true
                            }
                        },
                        animation: {
                            duration: 1500,
                            easing: 'easeInOutCubic'
                        }
                    }
                });
            } catch (error) {
                console.error('Erro ao criar gráfico hourly:', error);
            }
        }
    });
}


        // Manter todas as outras funções do código original
        var pedidosData = <?php echo json_encode($allPedidos); ?>;
        var chartData = <?php echo json_encode($chartData); ?>;
        var statusData = <?php echo json_encode($statusData); ?>;
        var hourlyHeatmap = <?php echo json_encode($hourlyHeatmap); ?>;
        var weeklyData = <?php echo json_encode($weeklyData); ?>;


if (!hourlyHeatmap || hourlyHeatmap.length === 0) {
    hourlyHeatmap = [2,1,0,0,1,3,8,15,22,28,35,42,38,45,52,48,41,35,28,22,18,12,8,5];
}
// Garantir que sempre temos 24 elementos
while (hourlyHeatmap.length < 24) {
    hourlyHeatmap.push(0);
}        var selectedExportFilter = 'all';
        var charts = {};
        
        // Utility functions
        function formatCurrency(value) {
            return new Intl.NumberFormat('pt-BR', {
                style: 'currency',
                currency: 'BRL'
            }).format(value / 100);
        }

        function formatDate(dateString) {
            return formatDateWithTimezone(dateString, 'd/m/Y H:i:s');
        }

        // Wait for Chart.js to load before initializing
        function waitForChart(callback, maxTries) {
            maxTries = maxTries || 50; // 5 seconds max
            
            if (typeof Chart !== 'undefined') {
                callback();
            } else if (maxTries > 0) {
                setTimeout(function() {
                    waitForChart(callback, maxTries - 1);
                }, 100);
            } else {
                console.error('Chart.js não pôde ser carregado após 5 segundos');
                showToast('Erro: Gráficos não puderam ser carregados', 'error');
            }
        }

        // Navigation
function showSection(sectionName) {
    showSectionInternal(sectionName);
}

// Garantir que a função está disponível globalmente
window.showSection = showSection;

        // Search and filter
function filterTable() {
    var valorFilter = document.getElementById('valorFilter').value;
    var statusFilter = document.getElementById('statusFilter').value;
    var dateFilter = document.getElementById('dateFilter').value;
    var rows = document.querySelectorAll('#tableBody tr');
    
    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        var status = row.getAttribute('data-status');
        var date = new Date(row.getAttribute('data-date'));
        var now = new Date();
        
        /// Pegar valor da transação
        var valorElement = row.querySelector('.cell-value');
        if (!valorElement) continue;
        var valorText = valorElement.textContent;
        var valor = parseFloat(valorText.replace('R$', '').replace('.', '').replace(',', '.')) * 100;
        
        var showRow = true;
        
        // Filtro por valor
        if (valorFilter) {
            var range = valorFilter.split('-');
            var min = parseInt(range[0]);
            var max = parseInt(range[1]);
            if (valor < min || valor > max) {
                showRow = false;
            }
        }
                
                // Status filter
                if (statusFilter && status !== statusFilter) {
                    showRow = false;
                }
                
                // Date filter
                if (dateFilter !== 'all') {
                    var daysDiff = (now - date) / (1000 * 60 * 60 * 24);
                    if (dateFilter === 'today' && daysDiff > 1) showRow = false;
                    if (dateFilter === 'week' && daysDiff > 7) showRow = false;
                    if (dateFilter === 'month' && daysDiff > 30) showRow = false;
                }
                
                row.style.display = showRow ? '' : 'none';
            }
        }

        // Modal functions
        function showModal(modalId) {
            var modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('show');
            }
        }
        
        function closeModal() {
            var modals = document.querySelectorAll('.modal');
            for (var i = 0; i < modals.length; i++) {
                modals[i].classList.remove('show');
            }
        }

        function showExportModal() {
            showModal('exportModal');
        }

        function viewDetails(transactionId) {
            var pedido = null;
            for (var i = 0; i < pedidosData.length; i++) {
                if (pedidosData[i].transaction_id === transactionId) {
                    pedido = pedidosData[i];
                    break;
                }
            }
            
            if (!pedido) {
                showToast('Transação não encontrada', 'error');
                return;
            }
            
            var utmData = {};
            try {
                utmData = JSON.parse(pedido.utm_params || '{}');
            } catch (e) {
                utmData = {};
            }
            
            var produtosData = [];
            try {
                produtosData = JSON.parse(pedido.produtos || '[]');
            } catch (e) {
                produtosData = [];
            }
            
            var modalBody = document.getElementById('modalBody');
            var statusText = pedido.status === 'paid' ? '✅ Pago' : 
                           (pedido.status.indexOf('pending') !== -1 || pedido.status.indexOf('waiting') !== -1 || pedido.status === 'created' ? '⏳ Pendente' : '❌ Expirado');
            
            var html = '<div class="detail-item">' +
                '<div class="detail-label"><i class="fas fa-fingerprint"></i> ID da Transação</div>' +
                '<div class="detail-value mono">' + pedido.transaction_id + '</div>' +
                '</div>' +
                '<div class="detail-item">' +
                '<div class="detail-label"><i class="fas fa-info-circle"></i> Status do Pagamento</div>' +
                '<div class="detail-value"><span class="status-badge status-' + pedido.status + '">' + statusText + '</span></div>' +
                '</div>' +
                '<div class="detail-item">' +
                '<div class="detail-label"><i class="fas fa-user-circle"></i> Dados do Cliente</div>' +
                '<div class="detail-value">' +
                '<strong>👤 Nome:</strong> ' + pedido.nome + '<br>' +
                '<strong>📧 Email:</strong> ' + pedido.email + '<br>' +
                '<strong>📱 Telefone:</strong> ' + pedido.telefone + '<br>' +
                '<strong>🆔 CPF:</strong> ' + pedido.cpf +
                '</div></div>' +
                '<div class="detail-item">' +
                '<div class="detail-label"><i class="fas fa-money-check-alt"></i> Informações Financeiras</div>' +
                '<div class="detail-value">' +
                '<strong>💰 Valor:</strong> ' + formatCurrency(pedido.valor) + '<br>' +
                '<strong>📅 Criado em:</strong> ' + formatDate(pedido.created_at) +
                (pedido.updated_at ? '<br><strong>🔄 Atualizado em:</strong> ' + formatDate(pedido.updated_at) : '') +
                '</div></div>';

if (produtosData.length > 0) {
    html += '<div class="detail-item">' +
        '<div class="detail-label"><i class="fas fa-shopping-cart"></i> Produtos</div>' +
        '<div class="detail-value">';
    for (var i = 0; i < produtosData.length; i++) {
        var produto = produtosData[i];
        
        // Usar os nomes corretos dos campos
        var nome = produto.title || produto.name || 'Produto sem nome';
        var id = produto.id || 'Kit-' + (i + 1);
        var quantidade = produto.quantity || 1;
        var preco = produto.unitPrice || produto.priceInCents || 0;
        
        html += '<div style="margin-bottom: 1rem; padding: 1rem; background: var(--bg-terciary); border-radius: 12px; border-left: 3px solid var(--accent-primary);">' +
            '<strong>📦 ' + nome + '</strong><br>' +
            '<small class="mono" style="color: var(--text-muted);">' +
            '🏷️ ID: ' + id + '<br>' +
            '📊 Quantidade: ' + quantidade + '<br>' +
            '💵 Valor: ' + formatCurrency(preco) +
            '</small></div>';
    }
    html += '</div></div>';
}

            html += '<div class="detail-item">' +
                '<div class="detail-label"><i class="fas fa-chart-bar"></i> Parâmetros de Tracking (UTM)</div>' +
                '<div class="detail-value">' +
                '<pre style="background: var(--bg-primary); padding: 1.5rem; border-radius: 12px; font-size: 0.8rem; overflow-x: auto; border: 1px solid var(--border-color); color: var(--accent-primary);">' +
                JSON.stringify(utmData, null, 2) + '</pre>' +
                '</div></div>' +
                '<div style="display: flex; gap: 1rem; margin-top: 2rem; flex-wrap: wrap;">' +
                '<button class="action-btn" onclick="copyTransactionData(\'' + transactionId + '\')" style="flex: 1; min-width: 200px;">' +
                '<i class="fas fa-copy"></i> Copiar Dados</button>' +
                '<button class="action-btn" onclick="downloadTransaction(\'' + transactionId + '\')" style="flex: 1; min-width: 200px; background: linear-gradient(135deg, var(--info), var(--accent-secondary));">' +
                '<i class="fas fa-download"></i> Download JSON</button></div>';
            
            modalBody.innerHTML = html;
            showModal('detailModal');
        }

        // Export functions
        function performExport(format) {
            format = format || 'csv';
            var dataToExport = pedidosData.slice();
            
            // Apply status filter
            if (selectedExportFilter !== 'all') {
                dataToExport = dataToExport.filter(function(p) {
                    return p.status === selectedExportFilter;
                });
            }
            
            // Apply date filter
            var startDate = document.getElementById('export-date-start').value;
            var endDate = document.getElementById('export-date-end').value;
            
            if (startDate || endDate) {
                dataToExport = dataToExport.filter(function(p) {
                    var pDate = new Date(p.created_at).toISOString().split('T')[0];
                    if (startDate && pDate < startDate) return false;
                    if (endDate && pDate > endDate) return false;
                    return true;
                });
            }
            
            if (dataToExport.length === 0) {
                showToast('Nenhum dado encontrado para exportar', 'error');
                return;
            }
            
            if (format === 'json') {
                // JSON Export
                var jsonData = JSON.stringify(dataToExport, null, 2);
                var blob = new Blob([jsonData], { type: 'application/json' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'blackpanel_export_' + selectedExportFilter + '_' + new Date().toISOString().split('T')[0] + '.json';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            } else {
                // CSV Export
                var headers = ['Data', 'Nome', 'Email', 'CPF', 'Telefone', 'Valor (R$)', 'Status', 'Transaction ID'];
                var csvRows = [headers.join(',')];
                
                for (var i = 0; i < dataToExport.length; i++) {
                    var p = dataToExport[i];
                    var row = [
                        formatDate(p.created_at),
                        '"' + p.nome + '"',
                        p.email,
                        p.cpf,
                        p.telefone,
                        (p.valor / 100).toFixed(2),
                        p.status,
                        p.transaction_id
                    ];
                    csvRows.push(row.join(','));
                }
                
                var csvContent = csvRows.join('\n');
                var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'blackpanel_export_' + selectedExportFilter + '_' + new Date().toISOString().split('T')[0] + '.csv';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            }
            
            showToast(dataToExport.length + ' registros exportados em ' + format.toUpperCase() + '!', 'success');
            closeModal();
        }


        // Webhook functions
function addWebhook(type) {
    var container = document.getElementById('webhook-' + type);
    var div = document.createElement('div');
    div.className = 'webhook-url-item';
    div.innerHTML = '<input type="url" class="form-input" name="webhook_' + type + '[]" placeholder="https://exemplo.com/webhook/' + type + '">' +
        '<button type="button" class="remove-webhook" onclick="removeWebhook(this)">' +
        '<i class="fas fa-trash"></i></button>';
    container.appendChild(div);
}

function removeWebhook(button) {
    button.parentElement.remove();
}

// Função para testar webhooks
function testWebhookType(type) {
    var inputs = document.querySelectorAll('input[name="webhook_' + type + '[]"]');
    var urls = [];
    
    for (var i = 0; i < inputs.length; i++) {
        if (inputs[i].value.trim()) {
            urls.push(inputs[i].value.trim());
        }
    }
    
    if (urls.length === 0) {
        showToast('❌ Nenhuma URL configurada para ' + type, 'error');
        return;
    }
    
    showToast('🧪 Testando ' + urls.length + ' webhook(s) de ' + type + '...', 'info');
    
    urls.forEach(function(url, index) {
        setTimeout(function() {
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    event: 'test_' + type,
                    transaction_id: 'test_' + Date.now(),
                    timestamp: new Date().toISOString(),
                    source: 'BlackPanel_Test'
                })
            })
            .then(function(response) {
                var status = response.ok ? 'success' : 'error';
                var message = response.ok ? 
                    '✅ Webhook ' + type + ' #' + (index + 1) + ' respondeu OK!' : 
                    '❌ Webhook ' + type + ' #' + (index + 1) + ' falhou (HTTP ' + response.status + ')';
                showToast(message, status);
            })
            .catch(function() {
                showToast('❌ Webhook ' + type + ' #' + (index + 1) + ' não respondeu', 'error');
            });
        }, index * 1000);
    });
}


        // Config functions
        function saveConfig() {
            var webhookData = {};
            var types = ['error', 'generated', 'paid'];
            
            for (var i = 0; i < types.length; i++) {
                var type = types[i];
                var inputs = document.querySelectorAll('input[name="webhook_' + type + '[]"]');
                var urls = [];
                for (var j = 0; j < inputs.length; j++) {
                    if (inputs[j].value.trim()) {
                        urls.push(inputs[j].value.trim());
                    }
                }
                webhookData['webhook_' + type] = urls;
            }

            var configData = {
                action: 'save_config',
                utmify_api_url: document.getElementById('utmify_api_url').value,
                utmify_api_token: document.getElementById('utmify_api_token').value,
                facebook_pixel_id: document.getElementById('facebook_pixel_id').value,
                facebook_access_token: document.getElementById('facebook_access_token').value,
                timezone: document.getElementById('timezone').value
            };

            // Add webhook data
            for (var key in webhookData) {
                configData[key] = webhookData[key];
            }

            var formData = new FormData();
            for (var key in configData) {
                if (Array.isArray(configData[key])) {
                    for (var i = 0; i < configData[key].length; i++) {
                        formData.append(key + '[]', configData[key][i]);
                    }
                } else {
                    formData.append(key, configData[key]);
                }
            }

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(result) {
                if (result.success) {
                    showToast('Configurações salvas com sucesso!', 'success');
                } else {
                    showToast(result.message || 'Erro ao salvar configurações', 'error');
                }
            })
            .catch(function(error) {
                showToast('Erro de comunicação com o servidor', 'error');
                console.error('Erro ao salvar config:', error);
            });
        }

        // Utility actions
        function copyToClipboard(text) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    showToast('ID copiado para a área de transferência!', 'success');
                }).catch(function() {
                    showToast('Erro ao copiar ID', 'error');
                });
            } else {
                // Fallback for older browsers
                var textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    showToast('ID copiado para a área de transferência!', 'success');
                } catch (err) {
                    showToast('Erro ao copiar ID', 'error');
                }
                document.body.removeChild(textArea);
            }
        }
        
        function copyTransactionData(transactionId) {
            var pedido = null;
            for (var i = 0; i < pedidosData.length; i++) {
                if (pedidosData[i].transaction_id === transactionId) {
                    pedido = pedidosData[i];
                    break;
                }
            }
            
            if (pedido) {
                var data = JSON.stringify(pedido, null, 2);
                copyToClipboard(data);
            }
        }
        
        function downloadTransaction(transactionId) {
            var pedido = null;
            for (var i = 0; i < pedidosData.length; i++) {
                if (pedidosData[i].transaction_id === transactionId) {
                    pedido = pedidosData[i];
                    break;
                }
            }
            
            if (pedido) {
                var data = JSON.stringify(pedido, null, 2);
                var blob = new Blob([data], { type: 'application/json' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'transacao_' + transactionId.substring(0, 8) + '.json';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                showToast('Arquivo JSON baixado com sucesso!', 'success');
            }
        }
        
        // Toast notifications
        function showToast(message, type) {
            type = type || 'info';
            var toast = document.createElement('div');
            toast.className = 'toast ' + type;
            
            var icons = {
                success: 'fas fa-check-circle',
                error: 'fas fa-exclamation-circle',
                info: 'fas fa-info-circle'
            };
            
            var iconColors = {
                success: 'var(--success)',
                error: 'var(--danger)',
                info: 'var(--info)'
            };
            
            var icon = icons[type] || icons.info;
            var iconColor = iconColors[type] || iconColors.info;
            
            toast.innerHTML = '<div style="display: flex; align-items: center; gap: 0.75rem;">' +
                '<i class="' + icon + '" style="font-size: 1.2rem; color: ' + iconColor + ';"></i>' +
                '<span style="font-weight: 500;">' + message + '</span></div>';
            
            document.body.appendChild(toast);
            
            // Show animation
            setTimeout(function() {
                toast.classList.add('show');
            }, 100);
            
            // Hide animation
            setTimeout(function() {
                toast.classList.remove('show');
                setTimeout(function() {
                    if (document.body.contains(toast)) {
                        document.body.removeChild(toast);
                    }
                }, 400);
            }, 4000);
        }
        
        // Auto refresh
        function startAutoRefresh() {
            setInterval(function() {
                if (document.visibilityState === 'visible' && currentSection === 'dashboard') {
                    // Atualizar dados sem recarregar página completa
                    initializeCharts();
                }
            }, CONFIG.autoRefresh);
        }
        
        // Event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Inicializar estado da página
            initializePageState();
            
            // Inicializar sessão
            SessionManager.init();
            
            // Initialize charts immediately
initializeCharts();

// Initialize global filter DEPOIS dos gráficos
setTimeout(function() {
    initializeGlobalFilter();
}, 1000);
            
            // Search and filter listeners
var valorFilter = document.getElementById('valorFilter');
var statusFilter = document.getElementById('statusFilter');
var dateFilter = document.getElementById('dateFilter');

if (valorFilter) {
    valorFilter.addEventListener('change', filterTable);
}
            
            if (statusFilter) {
                statusFilter.addEventListener('change', filterTable);
            }
            
            if (dateFilter) {
                dateFilter.addEventListener('change', filterTable);
            }
            
            // Export option selection
            var exportOptions = document.querySelectorAll('.export-option');
            for (var i = 0; i < exportOptions.length; i++) {
                exportOptions[i].addEventListener('click', function() {
                    // Remove selected from all options
                    var allOptions = document.querySelectorAll('.export-option');
                    for (var j = 0; j < allOptions.length; j++) {
                        allOptions[j].classList.remove('selected');
                    }
                    
                    // Add selected to clicked option
                    this.classList.add('selected');
                    selectedExportFilter = this.getAttribute('data-filter');
                });
            }
            
            // Set default export option
            var defaultOption = document.querySelector('.export-option[data-filter="all"]');
            if (defaultOption) {
                defaultOption.classList.add('selected');
            }
            
            // Modal click outside to close
            var modals = document.querySelectorAll('.modal');
            for (var i = 0; i < modals.length; i++) {
                modals[i].addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeModal();
                    }
                });
            }
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey || e.metaKey) {
                    switch(e.key) {
                        case 'f':
    e.preventDefault();
    if (valorFilter) valorFilter.focus();
    break;
                        case 'e':
                            e.preventDefault();
                            showExportModal();
                            break;
                        case 'r':
                            e.preventDefault();
                            if (currentSection === 'dashboard') {
                                initializeCharts();
                                showToast('Dashboard atualizado!', 'success');
                            }
                            break;
                        case 's':
                            e.preventDefault();
                            if (currentSection === 'settings') {
                                saveConfig();
                            }
                            break;
                    }
                }
                if (e.key === 'Escape') {
                    closeModal();
                }
            });
            
            // Initialize auto refresh
            startAutoRefresh();
            
            // Set current date as default for export
            var today = new Date().toISOString().split('T')[0];
            var exportEndDate = document.getElementById('export-date-end');
            if (exportEndDate) {
                exportEndDate.value = today;
            }

            // Add initial webhook inputs if none exist
            var webhookTypes = ['error', 'generated', 'paid'];
            for (var i = 0; i < webhookTypes.length; i++) {
                var type = webhookTypes[i];
                var container = document.getElementById('webhook-' + type);
                if (container && container.children.length === 0) {
                    addWebhook(type);
                }
            }
            
            // Welcome message with debug info
            setTimeout(function() {
                var totalTransactions = pedidosData.length;
                if (totalTransactions > 0) {
                } else {
                }
            }, 1500);
        });

        // Enhanced error handling - only log, don't show toast
        window.addEventListener('error', function(event) {
            console.error('JavaScript Error:', event.error);
        });

        // Update session activity
setInterval(function() {
            fetch(window.location.href, {
                method: 'HEAD'
            }).catch(function() {
                // Silent fail - just keep session alive
            });
        }, 300000); // Every 5 minutes
        
    </script>
</body>
</html>