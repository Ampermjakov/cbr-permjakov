<?php
if (PHP_SAPI !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403); exit;
}
require_once __DIR__.'/config.php';
require_once __DIR__.'/email.php';
require_once __DIR__.'/notify.php';
require_once __DIR__.'/cbr.php';

echo date('[Y-m-d H:i:s]')." CBR cron start\n";

// Синхронизируем список с ЦБ РФ
$sync = syncCbrList();
if (isset($sync['error'])) {
    echo "ERROR sync: {$sync['error']}\n"; exit(1);
}
echo "  Sync: total={$sync['total']} added={$sync['added']} updated={$sync['updated']}\n";

$db   = getDB();
$rows = $db->query("
    SELECT t.id, t.user_id, t.url, t.in_list,
           u.email,
           n.email_notify, n.tg_chat_id, n.max_user_id
    FROM cbr_tracked t
    JOIN cbr_users u ON u.id = t.user_id
    LEFT JOIN cbr_notifications n ON n.user_id = t.user_id
    WHERE t.status = 'active'
")->fetchAll();

$checked = 0;
$changed = 0;

foreach ($rows as $row) {
    $result   = checkUrl($row['url']);
    $newState = ($result['status'] === 'found') ? 1 : 0;
    $listData = $newState ? json_encode($result['items'][0] ?? null) : null;

    $db->prepare("UPDATE cbr_tracked SET in_list=?, list_data=?, last_check=NOW() WHERE id=?")
       ->execute([$newState, $listData, $row['id']]);
    $checked++;

    if ((int)$row['in_list'] !== $newState) {
        $changed++;
        $direction = $newState ? 'ПОЯВИЛСЯ в стоп-листе' : 'УБРАН из стоп-листа';
        echo "  CHANGED [{$direction}]: {$row['url']}\n";

        $item = $result['items'][0] ?? [];

        // Email — если включён
        if (!empty($row['email_notify']) && !empty($row['email'])) {
            $sent = $newState
                ? sendWarnEmail($row['email'], $row['url'], $item)
                : sendClearEmail($row['email'], $row['url']);
            echo "    email → {$row['email']}: " . ($sent ? 'OK' : 'FAIL') . "\n";
        }

        // Telegram — если указан chat_id
        if (!empty($row['tg_chat_id'])) {
            $newState
                ? notifyWarn($row, $row['url'], $item)
                : notifyClear($row, $row['url']);
            echo "    telegram → {$row['tg_chat_id']}: sent\n";
        }

        // MAX — если указан user_id
        if (!empty($row['max_user_id'])) {
            $newState
                ? notifyWarn($row, $row['url'], $item)
                : notifyClear($row, $row['url']);
            echo "    max → {$row['max_user_id']}: sent\n";
        }

        $db->prepare("UPDATE cbr_tracked SET notified_at=NOW() WHERE id=?")
           ->execute([$row['id']]);
    }
}

echo date('[Y-m-d H:i:s]')." Done. Checked={$checked} Changed={$changed}\n";
