<?php
// JSON-эндпоинт для пробивки из портала lk.permjakov.ru: проверка домена по стоп-листу ЦБ.
// Отдаёт checkUrl() как есть: {status: clean|found|error, count, items}.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cbr.php';

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Укажите домен'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(checkUrl($q), JSON_UNESCAPED_UNICODE);
