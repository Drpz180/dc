<?php
require_once __DIR__ . '/../../app/Database.php';

header('Content-Type: application/json; charset=utf-8');

$txid = $_GET['txid'] ?? null;
if (!$txid) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_txid']);
    exit;
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("SELECT status, paid_at FROM orders WHERE txid = :txid LIMIT 1");
    $stmt->execute(['txid' => $txid]);
    $row = $stmt->fetch();

    if (!$row) {
        echo json_encode(['ok' => true, 'status' => 'not_found']);
        exit;
    }

    $status = strtolower($row['status'] ?? '');
    $paidAt = $row['paid_at'] ?? null;

    // normalize
    $isPaid = in_array($status, ['pago', 'paid', 'concluida'], true);

    echo json_encode([
        'ok' => true,
        'status' => $isPaid ? 'pago' : 'pendente',
        'raw_status' => $status,
        'paid_at' => $paidAt
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
}
