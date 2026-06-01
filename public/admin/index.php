<?php
session_start();
require_once __DIR__ . '/../../app/config.php';
require_once __DIR__ . '/../../app/models/Campaign.php';
require_once __DIR__ . '/../../app/Database.php';

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . basename(__FILE__));
    exit;
}

// Login handler
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $u = trim($_POST['username'] ?? '');
    $p = trim($_POST['password'] ?? '');
    if ($u === ADMIN_USER && $p === ADMIN_PASS) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user'] = $u;
        header('Location: ' . basename(__FILE__));
        exit;
    } else {
        $loginError = 'Credenciais inválidas.';
    }
}

// Se não logado, mostrar form de login
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title>Admin • Login</title>
      <style>
        :root{--bg:#0b0b0b;--card:#0f0f0f;--accent:#00c853;--muted:#9aa0a6;--glass: rgba(255,255,255,0.04)}
        *{box-sizing:border-box}
        body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,'Helvetica Neue',Arial;background:linear-gradient(180deg,#071018 0%,#071820 100%);color:#e6eef0;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
        .login-wrap{width:100%;max-width:420px;background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));border-radius:14px;padding:28px;border:1px solid rgba(255,255,255,0.04);box-shadow:0 8px 30px rgba(2,6,23,0.6)}
        h1{margin:0 0 6px 0;font-size:1.6rem;color:white}
        p.lead{margin:0 0 18px 0;color:var(--muted);font-size:0.95rem}
        .form-group{margin-bottom:12px}
        label{display:block;font-size:0.85rem;color:var(--muted);margin-bottom:6px}
        input[type=text],input[type=password]{width:100%;padding:12px;border-radius:8px;border:1px solid rgba(255,255,255,0.06);background:rgba(255,255,255,0.02);color:#fff;font-size:1rem}
        button{width:100%;padding:12px;border-radius:10px;border:none;background:var(--accent);color:#022;font-weight:700;cursor:pointer;margin-top:6px}
        .foot{margin-top:14px;text-align:center;color:var(--muted);font-size:0.85rem}
        .error{background:#3a1212;color:#ffd6d6;padding:10px;border-radius:8px;margin-bottom:12px;border:1px solid rgba(255,80,80,0.15)}
        .brand{display:flex;align-items:center;gap:12px;margin-bottom:14px}
        .brand .logo{width:48px;height:48px;border-radius:10px;background:var(--accent);display:flex;align-items:center;justify-content:center;color:#022;font-weight:900;font-family:monospace}
      </style>
    </head>
    <body>
      <div class="login-wrap" role="main">
        <div class="brand"><div class="logo">BP</div><div><h1>BlackPanel</h1><p class="lead">Painel administrativo — faça login</p></div></div>
        <?php if ($loginError): ?><div class="error"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
          <div class="form-group">
            <label for="username">Usuário</label>
            <input id="username" name="username" type="text" required autofocus>
          </div>
          <div class="form-group">
            <label for="password">Senha</label>
            <input id="password" name="password" type="password" required>
          </div>
          <button name="login" type="submit">Entrar</button>
        </form>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// Autenticado: mostrar dashboard
$pdo = Database::getConnection();

// Metrics queries
// total orders
$stmt = $pdo->query("SELECT COUNT(*) FROM orders");
$totalOrders = (int)$stmt->fetchColumn();

// paid orders
$stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pago','paid')");
$paidOrders = (int)$stmt->fetchColumn();

// pending orders
$stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pendente','pending','waiting_payment','created')");
$pendingOrders = (int)$stmt->fetchColumn();

// conversion rate
$conversion = $totalOrders > 0 ? round(($paidOrders / $totalOrders) * 100, 1) : 0.0;

// recent orders sample
$stmt = $pdo->prepare("SELECT id, txid, buyer_name, buyer_email, buyer_cpf, amount, status, created_at, paid_at FROM orders ORDER BY created_at DESC LIMIT 12");
$stmt->execute();
$recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// campaigns list (small)
$campaigns = Campaign::findAll();

// === NOVO: Top campanhas por vendas (agrupado por orders.campaign_id) ===
$topCampaigns = [];
try {
  $stmt = $pdo->query("
    SELECT campaign_id,
           COUNT(*) AS total_orders,
           SUM(CASE WHEN status IN ('pago','paid') THEN 1 ELSE 0 END) AS paid_orders,
           COALESCE(SUM(amount),0) AS revenue
    FROM orders
    WHERE campaign_id IS NOT NULL
    GROUP BY campaign_id
    ORDER BY paid_orders DESC, revenue DESC
    LIMIT 5
  ");
  if ($stmt) {
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
      $camp = Campaign::findById((int)$r['campaign_id']);
      if ($camp) {
        $topCampaigns[] = [
          'id' => $camp['id'],
          'title' => $camp['title'],
          'slug' => $camp['slug'],
          'paid_orders' => (int)$r['paid_orders'],
          'total_orders' => (int)$r['total_orders'],
          'revenue' => (float)$r['revenue'],
        ];
      }
    }
  }
} catch (Exception $e) {
  // silencioso
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>BlackPanel • Dashboard</title>
  <style>
    :root{--bg:#071018;--card:#071827;--accent:#00c853;--muted:#9fb3b9;--glass: rgba(255,255,255,0.03)}
    *{box-sizing:border-box}
    body{margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial;background:linear-gradient(180deg,#061119 0%,#071827 100%);color:#e6f4ef;min-height:100vh}
    .wrap{max-width:1200px;margin:28px auto;padding:20px}
    header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
    .brand{display:flex;align-items:center;gap:14px}
    .brand .logo{width:50px;height:50px;border-radius:12px;background:var(--accent);display:flex;align-items:center;justify-content:center;color:#042; font-weight:900}
    .brand h1{margin:0;font-size:1.3rem}
    .actions{display:flex;gap:10px;align-items:center}
    .btn{background:transparent;border:1px solid rgba(255,255,255,0.06);padding:8px 12px;border-radius:8px;color:var(--muted);cursor:pointer}
    .btn.danger{border-color:rgba(255,80,80,0.18);color:#ffbdbd}
    .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:16px}
    .card{background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));padding:18px;border-radius:12px;border:1px solid var(--glass);box-shadow:0 6px 24px rgba(0,0,0,0.6)}
    .col-4{grid-column:span 4}
    .col-8{grid-column:span 8}
    .metric{display:flex;flex-direction:column;gap:8px}
    .metric .label{color:var(--muted);font-size:0.9rem}
    .metric .value{font-size:1.8rem;font-weight:800;color:#fff}
    .kpi-row{display:flex;gap:12px}
    .kpi{flex:1;padding:12px;border-radius:10px;background:linear-gradient(180deg, rgba(0,200,83,0.06), rgba(0,200,83,0.02));text-align:center}
    .kpi.small{background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.00))}
    table{width:100%;border-collapse:collapse;margin-top:8px}
    th,td{padding:10px;text-align:left;border-bottom:1px solid rgba(255,255,255,0.03);font-size:0.95rem;color:#dff6e6}
    th{color:var(--muted);font-size:0.85rem}
    .status-paid{color:#0b7b3a;font-weight:700}
    .status-pending{color:#e5b24a;font-weight:700}
    .footer{margin-top:18px;color:var(--muted);font-size:0.9rem;text-align:right}
    a.link{color:var(--accent);text-decoration:none}
    @media (max-width:900px){
      .grid{grid-template-columns:repeat(1,1fr)}
      .col-4,.col-8{grid-column:span 1}
      header{flex-direction:column;align-items:flex-start;gap:12px}
    }
  </style>
</head>
<body>
  <div class="wrap">
    <header>
      <div class="brand">
        <div class="logo">BP</div>
        <div>
          <h1>BlackPanel • Dashboard</h1>
          <div style="color:var(--muted);font-size:0.9rem">Usuário: <strong><?= htmlspecialchars($_SESSION['admin_user']) ?></strong></div>
        </div>
      </div>
      <div class="actions">
        <a class="btn" href="index.php">Campanhas</a>
        <a class="btn" href="pix_orders.php?campaign_id=0">Todos Pedidos</a>
        <a class="btn danger" href="?logout=1">Logout</a>
      </div>
    </header>

    <div class="grid">
      <div class="col-8">
        <div class="card">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div>
              <div style="font-size:0.95rem;color:var(--muted)">Taxa de Conversão</div>
              <div style="font-size:2.6rem;font-weight:800;color:#fff"><?= $conversion ?>% </div>
              <div style="color:var(--muted);margin-top:6px">Transações convertidas / total</div>
            </div>
            <div style="min-width:180px">
              <div class="kpi-row">
                <div class="kpi">
                  <div style="color:var(--muted)">Total Pedidos</div>
                  <div style="font-weight:800;font-size:1.4rem"><?= number_format($totalOrders) ?></div>
                </div>
                <div class="kpi small">
                  <div style="color:var(--muted)">Pagos</div>
                  <div style="font-weight:800;font-size:1.4rem;color:#aef0c3"><?= number_format($paidOrders) ?></div>
                </div>
                <div class="kpi small">
                  <div style="color:var(--muted)">Pendentes</div>
                  <div style="font-weight:800;font-size:1.4rem;color:#ffd68a"><?= number_format($pendingOrders) ?></div>
                </div>
              </div>
            </div>
          </div>

          <hr style="border:none;border-top:1px solid rgba(255,255,255,0.03);margin:16px 0">

          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <div style="font-weight:700">Últimos pedidos</div>
            <div style="color:var(--muted);font-size:0.9rem"><a class="link" href="pix_orders.php?campaign_id=0">ver todos</a></div>
          </div>

          <div style="overflow:auto">
            <table>
              <thead>
                <tr><th>#</th><th>TXID</th><th>Comprador</th><th>Valor</th><th>Status</th><th>Criado</th></tr>
              </thead>
              <tbody>
                <?php foreach ($recentOrders as $r): ?>
                  <tr>
                    <td><?= htmlspecialchars($r['id']) ?></td>
                    <td><?= htmlspecialchars(substr($r['txid'] ?? '',0,12)) ?>...</td>
                    <td><?= htmlspecialchars($r['buyer_name'] ?: $r['buyer_email'] ?: '-') ?></td>
                    <td>R$ <?= number_format((float)$r['amount'],2,',','.') ?></td>
                    <td class="<?= (in_array(strtolower($r['status']),['pago','paid'])?'status-paid':'status-pending') ?>"><?= htmlspecialchars($r['status']) ?></td>
                    <td><?= htmlspecialchars($r['created_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($recentOrders)): ?>
                  <tr><td colspan="6" style="color:var(--muted)">Nenhum pedido encontrado.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="col-4">
        <div class="card">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div style="font-weight:700">Campanhas</div>
            <div style="color:var(--muted);font-size:0.9rem"><a class="link" href="edit.php">+ nova</a></div>
          </div>
          <ul style="list-style:none;padding:0;margin:12px 0 0 0;max-height:320px;overflow:auto">
            <?php foreach ($campaigns as $c): ?>
              <li style="padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.02);display:flex;justify-content:space-between;align-items:center">
                <div>
                  <div style="font-weight:700"><?= htmlspecialchars($c['title']) ?></div>
                  <div style="color:var(--muted);font-size:0.85rem">ID <?= $c['id'] ?> • <?= number_format($c['raised_amount'],2,',','.') ?> / <?= number_format($c['goal_amount'],2,',','.') ?></div>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px">
                  <a class="btn" href="edit.php?id=<?= $c['id'] ?>">Editar</a>
                  <a class="btn" href="../campaign.php?slug=<?= urlencode($c['slug']) ?>" target="_blank">Ver</a>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <!-- ===== NOVO CARD: Top campanhas por vendas ===== -->
        <div style="height:16px"></div>
        <div class="card">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <div style="font-weight:700">Top campanhas por vendas</div>
            <div style="color:var(--muted);font-size:0.9rem"><a class="link" href="index.php">atualizar</a></div>
          </div>
          <ul style="list-style:none;padding:0;margin:12px 0 0 0">
            <?php foreach ($topCampaigns as $t): ?>
              <li style="padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.02);display:flex;justify-content:space-between;align-items:center">
                <div>
                  <div style="font-weight:700"><?= htmlspecialchars($t['title']) ?></div>
                  <div style="color:var(--muted);font-size:0.85rem">
                    <?= number_format($t['paid_orders']) ?> pagos de <?= number_format($t['total_orders']) ?> • R$ <?= number_format($t['revenue'],2,',','.') ?>
                  </div>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px">
                  <a class="btn" href="../campaign.php?slug=<?= urlencode($t['slug']) ?>" target="_blank">Ver</a>
                  <a class="btn" href="pix_orders.php?campaign_id=<?= $t['id'] ?>">Pedidos</a>
                </div>
              </li>
            <?php endforeach; ?>
            <?php if (empty($topCampaigns)): ?>
              <li style="padding:8px 0;color:var(--muted)">Sem dados de vendas.</li>
            <?php endif; ?>
          </ul>
        </div>

        <div style="height:16px"></div>

        <div class="card">
          <div style="font-weight:700;margin-bottom:8px">Ações rápidas</div>
          <div style="display:flex;flex-direction:column;gap:8px">
            <a class="btn" href="pix_orders.php?campaign_id=0">Todos Pedidos</a>
            <a class="btn" href="pix_orders.php?campaign_id=1">Pedidos campanha #1</a>
            <a class="btn" href="../" target="_blank">Ir para site</a>
          </div>
        </div>
      </div>
    </div>

    <div class="footer">BlackPanel • <?= date('Y') ?></div>
  </div>
</body>
</html>
