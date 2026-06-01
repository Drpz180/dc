<?php
require_once __DIR__ . '/../../app/Database.php';
require_once __DIR__ . '/../../app/PixGateway.php';

$campaign_id = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
$pdo = Database::getConnection();
$stmt = $pdo->prepare("SELECT * FROM orders WHERE campaign_id = :cid ORDER BY created_at DESC");
$stmt->execute(['cid' => $campaign_id]);
$orders = $stmt->fetchAll();

?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <title>Pedidos PIX da Campanha</title>
</head>
<body>
  <h1>Pedidos PIX da Campanha #<?= $campaign_id ?></h1>
  <table border="1" cellpadding="5" cellspacing="0">
    <tr>
      <th>ID</th>
      <th>TXID</th>
      <th>Comprador</th>
      <th>Email</th>
      <th>CPF</th>
      <th>Valor</th>
      <th>Status</th>
      <th>Criado em</th>
      <th>Pago em</th>
      <th>Consultar Status</th>
    </tr>
    <?php foreach ($orders as $o): ?>
      <tr>
        <td><?= $o['id'] ?></td>
        <td><?= htmlspecialchars($o['txid']) ?></td>
        <td><?= htmlspecialchars($o['buyer_name']) ?></td>
        <td><?= htmlspecialchars($o['buyer_email']) ?></td>
        <td><?= htmlspecialchars($o['buyer_cpf']) ?></td>
        <td><?= number_format($o['amount'], 2, ',', '.') ?></td>
        <td><?= htmlspecialchars($o['status']) ?></td>
        <td><?= htmlspecialchars($o['created_at']) ?></td>
        <td><?= htmlspecialchars($o['paid_at']) ?></td>
        <td>
          <form method="post" style="display:inline;">
            <input type="hidden" name="txid" value="<?= htmlspecialchars($o['txid']) ?>">
            <button type="submit">Consultar</button>
          </form>
        </td>
      </tr>
      <?php
      // Consulta status se solicitado
      if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['txid']) && $_POST['txid'] === $o['txid']) {
          try {
              $statusResp = PixGateway::getStatus($o['txid']);
              echo '<tr><td colspan="10" style="background:#f7f7f7;">';
              echo '<strong>Status do Gateway:</strong> ';
              echo htmlspecialchars(json_encode($statusResp));
              // Se pago, atualiza no banco
              if (!empty($statusResp['paid']) && $statusResp['paid']) {
                  $pdo->prepare("UPDATE orders SET status='pago', paid_at=NOW() WHERE txid=?")->execute([$o['txid']]);
                  echo ' <span style="color:green;">Pedido marcado como pago!</span>';
              }
              echo '</td></tr>';
          } catch (Exception $e) {
              echo '<tr><td colspan="10" style="color:red;">Erro ao consultar status: ' . htmlspecialchars($e->getMessage()) . '</td></tr>';
          }
      }
      ?>
    <?php endforeach; ?>
  </table>
  <p><a href="index.php">Voltar</a></p>
</body>
</html>
