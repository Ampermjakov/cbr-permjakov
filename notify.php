<?php
require_once __DIR__.'/config.php';

function sendTelegram(string $chatId, string $text): bool {
    $token = TG_BOT_TOKEN;
    if (!$token || !$chatId) return false;

    $url  = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = json_encode([
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ]);

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => $data,
        'timeout' => 10,
    ]]);

    $result = @file_get_contents($url, false, $ctx);
    if (!$result) return false;

    $resp = json_decode($result, true);
    return !empty($resp['ok']);
}

function sendMax(string $chatId, string $text): bool {
    $token = MAX_BOT_TOKEN;
    if (!$token || !$chatId) return false;

    $data = json_encode([
        'chat_id' => $chatId,
        'text'    => $text,
        'format'  => 'markdown',
    ]);

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nAuthorization: {$token}\r\n",
        'content' => $data,
        'timeout' => 10,
    ]]);

    $result = @file_get_contents('https://platform-api.max.ru/messages', false, $ctx);
    if (!$result) return false;

    $resp = json_decode($result, true);
    return isset($resp['message_id']) || isset($resp['id']);
}

function notifyWarn(array $row, string $url, array $item): void {
    $host = parse_url($url, PHP_URL_HOST) ?: $url;
    $name = $item['name'] ?? $host;
    $sign = $item['sign'] ?? '';
    $dt   = $item['dt']   ?? '';

    $tgText = "🚨 <b>Сервис в стоп-листе ЦБ РФ</b>\n\n"
        . "🌐 <b>URL:</b> {$url}\n"
        . "🏢 <b>Название:</b> {$name}\n"
        . "⚠️ <b>Признак:</b> {$sign}\n"
        . "📅 <b>Дата:</b> {$dt}\n\n"
        . "👉 <a href=\"" . SITE_URL . "/?page=dashboard\">Открыть кабинет</a>";

    $plainText = "🚨 Сервис в стоп-листе ЦБ РФ\n\n"
        . "URL: {$url}\n"
        . "Название: {$name}\n"
        . "Признак: {$sign}\n"
        . "Дата: {$dt}\n\n"
        . "Кабинет: " . SITE_URL . "/?page=dashboard";

    if (!empty($row['tg_chat_id'])) {
        sendTelegram($row['tg_chat_id'], $tgText);
    }
    if (!empty($row['max_user_id'])) {
        sendMax($row['max_user_id'], $plainText);
    }
}

function notifyClear(array $row, string $url): void {
    $host = parse_url($url, PHP_URL_HOST) ?: $url;

    $tgText = "✅ <b>Сервис убран из стоп-листа ЦБ РФ</b>\n\n"
        . "🌐 <b>URL:</b> {$url}\n\n"
        . "👉 <a href=\"" . SITE_URL . "/?page=dashboard\">Открыть кабинет</a>";

    $plainText = "✅ Сервис убран из стоп-листа ЦБ РФ\n\nURL: {$url}\n\nКабинет: " . SITE_URL . "/?page=dashboard";

    if (!empty($row['tg_chat_id'])) {
        sendTelegram($row['tg_chat_id'], $tgText);
    }
    if (!empty($row['max_user_id'])) {
        sendMax($row['max_user_id'], $plainText);
    }
}
