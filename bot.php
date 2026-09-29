<?php
require_once __DIR__.'/config.php';

$source = $_GET['source'] ?? '';
$input  = file_get_contents('php://input');
$data   = json_decode($input, true);

if (!$data) { http_response_code(400); exit; }

if ($source === 'telegram') {

    $chatId = $data['message']['chat']['id']
           ?? $data['callback_query']['message']['chat']['id']
           ?? null;

    if (!$chatId) { http_response_code(200); exit; }

    $replyText = "👋 Привет!\n\n"
        . "Ваш <b>Telegram Chat ID</b>:\n"
        . "<code>{$chatId}</code>\n\n"
        . "Скопируйте его и вставьте в настройках уведомлений на сайте:\n"
        . SITE_URL . "/?page=dashboard";

    // Отвечаем прямо в теле ответа — никакого второго HTTP-запроса,
    // работает даже с российских серверов
    header('Content-Type: application/json');
    echo json_encode([
        'method'     => 'sendMessage',
        'chat_id'    => $chatId,
        'text'       => $replyText,
        'parse_mode' => 'HTML',
    ]);
    exit;

} elseif ($source === 'max') {

    $chatId  = $data['message']['recipient']['chat_id'] ?? $data['chat_id'] ?? null;
    $msgText = $data['message']['body']['text']         ?? $data['text']    ?? '';

    if (!$chatId) { http_response_code(200); exit; }

    $replyText = "👋 Привет!\n\n"
        . "Ваш **MAX Chat ID**:\n"
        . "`{$chatId}`\n\n"
        . "Скопируйте его и вставьте в настройках уведомлений на\n"
        . SITE_URL . "/?page=dashboard";

    $token = MAX_BOT_TOKEN;
    if ($token) {
        $payload = json_encode([
            'chat_id' => $chatId,
            'text'    => $replyText,
            'format'  => 'markdown',
        ]);
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nAuthorization: {$token}\r\n",
            'content' => $payload,
            'timeout' => 10,
        ]]);
        @file_get_contents('https://platform-api.max.ru/messages', false, $ctx);
    }
}

http_response_code(200);
echo json_encode(['ok' => true]);
