<?php
require_once __DIR__.'/config.php';

// cbr.ru закрыт защитой DDoS-Guard (JS-проверка), которую file_get_contents/curl
// пройти не может — данные получаем через headless-браузер (fetch-cbr.js).
// Результат пишется во временный файл: чтение большого JSON через stdout-пайп
// proc_open на Windows на порядок медленнее файлового I/O.
function fetchCbrJson(): array {
    $outFile = sys_get_temp_dir() . '/cbr_fetch_' . bin2hex(random_bytes(8)) . '.json';

    $cmd = escapeshellarg(NODE_BIN) . ' ' . escapeshellarg(CBR_FETCH_SCRIPT) . ' '
         . escapeshellarg(CBR_API_URL) . ' ' . escapeshellarg($outFile);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = @proc_open($cmd, $descriptors, $pipes, __DIR__);
    if (!is_resource($process)) return ['error' => 'Не удалось запустить обработчик ЦБ РФ'];

    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || !is_file($outFile)) {
        @unlink($outFile);
        error_log('CBR fetch-cbr.js failed (exit '.$exitCode.'): '.$stderr);
        return ['error' => 'Не удалось получить данные с ЦБ РФ'];
    }

    $json = file_get_contents($outFile);
    @unlink($outFile);
    if (!$json) return ['error' => 'Не удалось получить данные с ЦБ РФ'];

    return ['json' => $json];
}

function syncCbrList(): array {
    $fetch = fetchCbrJson();
    if (isset($fetch['error'])) return $fetch;
    $json = $fetch['json'];

    $raw = json_decode($json, true);
    unset($json);
    if ($raw === null) return ['error' => 'Ошибка разбора JSON от ЦБ РФ'];

    $items = $raw['RC'] ?? $raw;
    unset($raw);

    $db      = getDB();
    $total   = count($items);
    $added   = 0;
    $updated = 0;

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            INSERT INTO cbr_list (cbr_id, name, site, sign, org_type, dt, date_update)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                name        = VALUES(name),
                site        = VALUES(site),
                sign        = VALUES(sign),
                org_type    = VALUES(org_type),
                dt          = VALUES(dt),
                date_update = VALUES(date_update)
        ");

        foreach ($items as $item) {
            $dt = !empty($item['DT']) ? $item['DT'] : null;
            $du = !empty($item['DateUpdate']) ? substr($item['DateUpdate'], 0, 19) : null;

            $stmt->execute([
                $item['Id']      ?? null,
                $item['Name']    ?? null,
                $item['Site']    ?? null,
                $item['Sign']    ?? null,
                $item['OrgType'] ?? null,
                $dt,
                $du,
            ]);

            $stmt->rowCount() === 1 ? $added++ : $updated++;
        }

        unset($items);

        $db->prepare("
            INSERT INTO cbr_sync (id, total, synced_at) VALUES (1, ?, NOW())
            ON DUPLICATE KEY UPDATE total = ?, synced_at = NOW()
        ")->execute([$total, $total]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        return ['error' => $e->getMessage()];
    }

    return ['total' => $total, 'added' => $added, 'updated' => $updated];
}

function normalizeUrl(string $url): string {
    $url = mb_strtolower(trim($url));
    $url = preg_replace('#^https?://#', '', $url);
    $url = preg_replace('#^www\.#', '', $url);
    $url = rtrim($url, '/');
    return $url;
}

function checkUrl(string $url): array {
    // Синхронизация с ЦБ РФ теперь идёт через headless-браузер и занимает ~2-3
    // минуты (cbr.ru сильно троттлит отдачу даже после прохождения DDoS-Guard).
    // Гонять её при заходе пользователя на сайт нельзя — обновление только через
    // cron.php по расписанию, здесь всегда читаем то, что уже накоплено в БД.
    $needle = normalizeUrl($url);
    $host   = parse_url('https://'.$needle, PHP_URL_HOST) ?: $needle;

    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT * FROM cbr_list
            WHERE LOWER(site) = ?
               OR LOWER(site) = ?
               OR LOWER(site) LIKE ?
        ");
        $stmt->execute([
            $host,
            'www.'.$host,
            '%.'.$host,
        ]);
        $found = $stmt->fetchAll();
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => 'Ошибка базы данных'];
    }

    if ($found) {
        return ['status' => 'found', 'count' => count($found), 'items' => $found];
    }
    return ['status' => 'clean'];
}

function getCacheAge(): ?string {
    try {
        $db  = getDB();
        $row = $db->query("SELECT synced_at FROM cbr_sync WHERE id = 1")->fetch();
        if (!$row) return null;
        $sec = time() - strtotime($row['synced_at']);
        if ($sec < 60)    return 'только что';
        if ($sec < 3600)  return floor($sec / 60).' мин назад';
        if ($sec < 86400) return floor($sec / 3600).' ч назад';
        return floor($sec / 86400).' дн назад';
    } catch (Exception $e) { return null; }
}

function getCbrCount(): int {
    try {
        $db  = getDB();
        $row = $db->query("SELECT total FROM cbr_sync WHERE id = 1")->fetch();
        return $row ? (int)$row['total'] : 0;
    } catch (Exception $e) { return 0; }
}

function getRecentCbrEntries(int $limit = 10): array {
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT name, site, sign, org_type, dt FROM cbr_list ORDER BY dt DESC, cbr_id DESC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Exception $e) { return []; }
}
