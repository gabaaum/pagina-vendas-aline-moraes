<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido']);
    exit;
}

$maxBytes = 64 * 1024;
$raw = file_get_contents('php://input');

if ($raw === false || strlen($raw) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Corpo vazio']);
    exit;
}

if (strlen($raw) > $maxBytes) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Dados muito grandes']);
    exit;
}

$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
    exit;
}

$row = $payload['página1'] ?? null;

if (!is_array($row)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Formato inválido']);
    exit;
}

$required = ['nome', 'email', 'telefoneWhatsapp'];

foreach ($required as $field) {
    if (empty(trim((string)($row[$field] ?? '')))) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Campos obrigatórios ausentes']);
        exit;
    }
}

$leadId = 'aline_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(6));
$receivedAt = gmdate('c');

$backupRecord = [
    'lead_id' => $leadId,
    'received_at' => $receivedAt,
    'ip' => $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? null,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'referer' => $_SERVER['HTTP_REFERER'] ?? null,
    'payload' => $payload,
    'sheety' => [
        'attempted' => false,
        'ok' => false,
        'status' => null,
        'error' => null,
    ],
];

$storageDir = dirname(__DIR__) . '/storage';
$backupFile = $storageDir . '/leads-diagnostico.jsonl';
$htaccessFile = $storageDir . '/.htaccess';

if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Não foi possível criar armazenamento']);
    exit;
}

if (!file_exists($htaccessFile)) {
    file_put_contents($htaccessFile, "Require all denied\nDeny from all\n");
}

$lineBeforeSheety = json_encode($backupRecord, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if (file_put_contents($backupFile, $lineBeforeSheety, FILE_APPEND | LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Não foi possível salvar backup']);
    exit;
}

$sheetyUrl = 'https://api.sheety.co/35f7dca5d0a749d89507e33c6442aedc/question%C3%A1rioDiagn%C3%B3sticoFinanceiro/p%C3%A1gina1';
$sheetyStatus = null;
$sheetyBody = null;
$sheetyError = null;

$ch = curl_init($sheetyUrl);

if ($ch !== false) {
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
    ]);

    $sheetyBody = curl_exec($ch);
    $sheetyStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($sheetyBody === false) {
        $sheetyError = curl_error($ch);
    }

    curl_close($ch);
} else {
    $sheetyError = 'Não foi possível iniciar cURL';
}

$sheetyOk = $sheetyStatus !== null && $sheetyStatus >= 200 && $sheetyStatus < 300;

$backupRecord['sheety'] = [
    'attempted' => true,
    'ok' => $sheetyOk,
    'status' => $sheetyStatus,
    'error' => $sheetyOk ? null : ($sheetyError ?: substr((string)$sheetyBody, 0, 500)),
];

$statusLine = json_encode([
    'lead_id' => $leadId,
    'sheety_result_at' => gmdate('c'),
    'sheety' => $backupRecord['sheety'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

file_put_contents($storageDir . '/sheety-status.jsonl', $statusLine, FILE_APPEND | LOCK_EX);

http_response_code(200);
echo json_encode([
    'ok' => true,
    'lead_id' => $leadId,
    'backup' => true,
    'sheety' => [
        'ok' => $sheetyOk,
        'status' => $sheetyStatus,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
