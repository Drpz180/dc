<?php
// Recebe logs client-side do TikTok Pixel e grava em arquivo local.
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) {
    $data = [];
}

$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

$entry = [
    'ts_server' => date('c'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'source' => $data['source'] ?? null,
    'pixel_id' => $data['pixel_id'] ?? null,
    'event' => $data['event'] ?? null,
    'txid' => $data['txid'] ?? null,
    'page' => $data['page'] ?? null,
    'payload' => $data['payload'] ?? null,
    'ts_client' => $data['ts_client'] ?? null,
];

@file_put_contents($logDir . '/tiktok_pixel_client.log', json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);

echo json_encode(['ok' => true]);
