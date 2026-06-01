<?php
// FORÇAR FUSO HORÁRIO BRASILEIRO
date_default_timezone_set('America/Sao_Paulo');
ini_set('date.timezone', 'America/Sao_Paulo');

require_once __DIR__ . '/../../../app/Database.php';

function getConnection() {
    return getDbConnection();
}

function getDbConnection() {
    return Database::getConnection();
}

// Insere um novo pedido na tabela 'orders'
function insertPedido($pedido) {
    $pdo = getDbConnection();
    $sql = "INSERT INTO orders (
        campaign_id, txid, buyer_name, buyer_email, buyer_cpf, amount, status, created_at
    ) VALUES (
        :campaign_id, :txid, :buyer_name, :buyer_email, :buyer_cpf, :amount, :status, :created_at
    )";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'campaign_id' => $pedido['campaign_id'],
        'txid'        => $pedido['txid'],
        'buyer_name'  => $pedido['buyer_name'],
        'buyer_email' => $pedido['buyer_email'],
        'buyer_cpf'   => $pedido['buyer_cpf'],
        'amount'      => $pedido['amount'],
        'status'      => $pedido['status'],
        'created_at'  => $pedido['created_at'],
    ]);
}

// Atualiza o status de um pedido na tabela 'orders'
function updatePedidoStatus($txid, $status, $paid_at = null) {
    $pdo = getDbConnection();
    date_default_timezone_set('America/Sao_Paulo');
    $updated_at = date('Y-m-d H:i:s');
    $paidAtLocal = null;
    if (!empty($paid_at)) {
        $paidAtLocal = date('Y-m-d H:i:s', strtotime($paid_at));
    }

    if (strtolower($status) === 'paid' || strtolower($status) === 'pago') {
        if (!$paidAtLocal) $paidAtLocal = $updated_at;
        $sql = "UPDATE orders SET status = :status, paid_at = :paid_at WHERE txid = :txid";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'status' => $status,
            'paid_at' => $paidAtLocal,
            'txid' => $txid
        ]);
    } else {
        $sql = "UPDATE orders SET status = :status WHERE txid = :txid";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'status' => $status,
            'txid' => $txid
        ]);
    }
}

// Busca um pedido na tabela 'orders' pelo txid
function getPedidoByTransactionId($txid) {
    $pdo = getDbConnection();
    $sql = "SELECT * FROM orders WHERE txid = :txid LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['txid' => $txid]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
