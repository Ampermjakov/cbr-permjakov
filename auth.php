<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/email.php';

error_reporting(0);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . SITE_URL);

// ── DB ───────────────────────────────────────────────────────────────

function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `email`           VARCHAR(255) NOT NULL UNIQUE,
        `password`        VARCHAR(255) NOT NULL,
        `verified`        TINYINT(1)   NOT NULL DEFAULT 0,
        `verify_token`    VARCHAR(64)  NULL,
        `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_login_at`   DATETIME     NULL,
        `reg_ip`          VARCHAR(45)  NOT NULL DEFAULT '',
        `reg_country`     VARCHAR(2)   NOT NULL DEFAULT '',
        `reg_city`        VARCHAR(100) NOT NULL DEFAULT '',
        `reg_org`         VARCHAR(255) NOT NULL DEFAULT '',
        `reg_is_vpn`      TINYINT(1)   NOT NULL DEFAULT 0,
        `reg_ua`          TEXT         NOT NULL,
        `checks_today`    INT          NOT NULL DEFAULT 0,
        `last_check_date` DATE         NULL,
        `total_checks`    INT          NOT NULL DEFAULT 0,
        `is_blocked`      TINYINT(1)   NOT NULL DEFAULT 0,
        `block_reason`    VARCHAR(255) NOT NULL DEFAULT '',
        `is_admin`        TINYINT(1)   NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sessions` (
        `token`      VARCHAR(64)  NOT NULL PRIMARY KEY,
        `user_id`    INT UNSIGNED NOT NULL,
        `expires_at` DATETIME     NOT NULL,
        `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
        `ua`         TEXT         NOT NULL,
        INDEX `idx_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `recent_checks` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `query`      VARCHAR(255) NOT NULL,
        `query_type` VARCHAR(10)  NOT NULL,
        `checked_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_time` (`checked_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return $pdo;
}

// ── Helpers ──────────────────────────────────────────────────────────

function ok(array $data = []): void  { echo json_encode(['ok' => true]  + $data, JSON_UNESCAPED_UNICODE); exit; }
function err(string $msg):    void  { echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE); exit; }

function clientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $h) {
        $v = $_SERVER[$h] ?? '';
        if ($v) return trim(explode(',', $v)[0]);
    }
    return '';
}

function ipInfo(string $ip): array {
    $ch = curl_init("https://ipinfo.io/{$ip}/json");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5, CURLOPT_SSL_VERIFYPEER=>false]);
    $out  = curl_exec($ch); curl_close($ch);
    $data = json_decode($out, true) ?: [];
    $org  = $data['org'] ?? '';
    $vpnKeywords = ['vpn','proxy','tor','mullvad','nordvpn','expressvpn','protonvpn',
                    'surfshark','cyberghost','hidemyass','windscribe','datacenter'];
    $isVpn = false;
    foreach ($vpnKeywords as $kw) {
        if (stripos($org, $kw) !== false) { $isVpn = true; break; }
    }
    return [
        'country' => $data['country'] ?? '',
        'city'    => $data['city']    ?? '',
        'org'     => $org,
        'is_vpn'  => $isVpn,
    ];
}

function currentUser(): ?array {
    $token = $_COOKIE['whois_session'] ?? '';
    if (!$token) return null;
    $st = db()->prepare('SELECT u.* FROM sessions s JOIN users u ON u.id=s.user_id
        WHERE s.token=? AND s.expires_at > NOW()');
    $st->execute([$token]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Router ───────────────────────────────────────────────────────────

$action = $_GET['action'] ?? '';

// GET verify — верификация email по ссылке
if ($action === 'verify' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = trim($_GET['token'] ?? '');
    if (!$token) { header('Location: ' . SITE_URL . '?msg=invalid_token'); exit; }
    $st = db()->prepare('SELECT id FROM users WHERE verify_token=? AND verified=0');
    $st->execute([$token]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user) { header('Location: ' . SITE_URL . '?msg=invalid_token'); exit; }
    db()->prepare('UPDATE users SET verified=1, verify_token=NULL WHERE id=?')->execute([$user['id']]);
    header('Location: ' . SITE_URL . '?msg=verified'); exit;
}

// GET me — текущий пользователь
if ($action === 'me') {
    $user = currentUser();
    if (!$user) ok(['user' => null]);
    // Сбросить счётчик если новый день
    if ($user['last_check_date'] !== date('Y-m-d')) {
        db()->prepare('UPDATE users SET checks_today=0, last_check_date=? WHERE id=?')
            ->execute([date('Y-m-d'), $user['id']]);
        $user['checks_today'] = 0;
    }
    ok(['user' => [
        'id'           => $user['id'],
        'email'        => $user['email'],
        'checks_today' => (int)$user['checks_today'],
        'limit'        => DAILY_LIMIT,
        'is_admin'     => (bool)$user['is_admin'],
    ]]);
}

// GET recent — последние 10 проверенных доменов
if ($action === 'recent') {
    $rows = db()->query('SELECT query, query_type FROM recent_checks ORDER BY checked_at DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
    // Fallback: если recent_checks пуста, тянем домены из whois_cache
    if (empty($rows)) {
        $st2 = @db()->query("SELECT `query`, 'domain' AS query_type FROM whois_cache WHERE `query` NOT REGEXP '^[0-9]+\\\\.[0-9]+\\\\.[0-9]+\\\\.[0-9]+$' ORDER BY checked_at DESC LIMIT 10");
        if ($st2) $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
    }
    ok(['recent' => $rows]);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];

// POST register
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($body['email'] ?? ''));
    $pass  = $body['password'] ?? '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('Некорректный email');
    if (strlen($pass) < 8) err('Пароль минимум 8 символов');

    $st = db()->prepare('SELECT id FROM users WHERE email=?');
    $st->execute([$email]);
    if ($st->fetch()) err('Этот email уже зарегистрирован');

    $isAdminEmail = (strtolower($email) === strtolower(ADMIN_EMAIL));
    $hash    = password_hash($pass, PASSWORD_BCRYPT);
    $ip      = clientIp();
    $ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ipData  = ipInfo($ip);

    if ($isAdminEmail) {
        // Администратор: сразу подтверждён и с правами
        db()->prepare('INSERT INTO users (email,password,verified,verify_token,reg_ip,reg_country,reg_city,reg_org,reg_is_vpn,reg_ua,is_admin)
            VALUES (?,?,1,NULL,?,?,?,?,?,?,1)')->execute([
            $email, $hash, $ip,
            $ipData['country'], $ipData['city'], $ipData['org'], $ipData['is_vpn'] ? 1 : 0, $ua
        ]);
        ok(['message' => 'Аккаунт администратора создан. Войдите.', 'admin_ready' => true]);
    }

    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO users (email,password,verify_token,reg_ip,reg_country,reg_city,reg_org,reg_is_vpn,reg_ua)
        VALUES (?,?,?,?,?,?,?,?,?)')->execute([
        $email, $hash, $token, $ip,
        $ipData['country'], $ipData['city'], $ipData['org'], $ipData['is_vpn'] ? 1 : 0, $ua
    ]);

    $link = SITE_URL . '/auth.php?action=verify&token=' . $token;
    $body = "<p>Здравствуйте!</p>
             <p>Для завершения регистрации на <strong>whois.permjakov.ru</strong> подтвердите ваш email:</p>
             <a class='btn' href='{$link}'>Подтвердить email →</a>
             <p style='color:#3d4a62;font-size:12px;margin-top:16px'>Если вы не регистрировались — проигнорируйте это письмо.</p>";
    sendMail($email, 'Подтверждение регистрации — whois.permjakov.ru', emailHtml($body));
    ok(['message' => 'Письмо с подтверждением отправлено на ' . $email]);
}

// POST login
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($body['email'] ?? ''));
    $pass  = $body['password'] ?? '';
    $st = db()->prepare('SELECT * FROM users WHERE email=?');
    $st->execute([$email]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify($pass, $user['password'])) err('Неверный email или пароль');
    if ($user['is_blocked']) err('Ваш аккаунт заблокирован: ' . ($user['block_reason'] ?: 'нарушение правил'));
    // ADMIN_EMAIL всегда получает права администратора при входе
    if (strtolower($email) === strtolower(ADMIN_EMAIL)) {
        db()->prepare('UPDATE users SET is_admin=1, verified=1 WHERE id=?')->execute([$user['id']]);
        $user['is_admin'] = 1;
        $user['verified'] = 1;
    }
    if (!$user['verified']) err('Подтвердите email перед входом');

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + SESSION_DAYS * 86400);
    db()->prepare('INSERT INTO sessions (token,user_id,expires_at,ip,ua) VALUES (?,?,?,?,?)')
        ->execute([$token, $user['id'], $expires, clientIp(), $_SERVER['HTTP_USER_AGENT'] ?? '']);
    db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$user['id']]);

    setcookie('whois_session', $token, time() + SESSION_DAYS * 86400, '/', '', true, true);
    ok(['user' => [
        'id'           => $user['id'],
        'email'        => $user['email'],
        'checks_today' => (int)$user['checks_today'],
        'limit'        => DAILY_LIMIT,
        'is_admin'     => (bool)$user['is_admin'],
    ]]);
}

// POST logout
if ($action === 'logout') {
    $token = $_COOKIE['whois_session'] ?? '';
    if ($token) db()->prepare('DELETE FROM sessions WHERE token=?')->execute([$token]);
    setcookie('whois_session', '', time() - 3600, '/', '', true, true);
    ok();
}

err('Неизвестное действие');
