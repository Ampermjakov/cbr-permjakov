<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/email.php';
require_once __DIR__.'/cbr.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function jsonOut(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    case 'send_otp': {
        $email = trim(strtolower($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonOut(['error' => 'Некорректный email'], 400);

        $ip = getRealIP();
        $db = getDB();

        $recent = $db->prepare("SELECT COUNT(*) FROM cbr_otp WHERE email=? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $recent->execute([$email]);
        if ($recent->fetchColumn() >= 3) jsonOut(['error' => 'Слишком много попыток. Подождите 10 минут.'], 429);

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $db->prepare("INSERT INTO cbr_otp (email,code,created_at,expires_at) VALUES (?,?,NOW(),DATE_ADD(NOW(),INTERVAL ".OTP_EXPIRE." SECOND))")
           ->execute([$email, password_hash($code, PASSWORD_DEFAULT)]);

        if (!sendOtpEmail($email, $code)) jsonOut(['error' => 'Не удалось отправить письмо'], 500);

        logAnalytics('send_otp');
        jsonOut(['ok' => true, 'message' => 'Код отправлен на '.$email]);
    }

    case 'verify_otp': {
        $email = trim(strtolower($_POST['email'] ?? ''));
        $code  = trim($_POST['code'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
            jsonOut(['error' => 'Неверные данные'], 400);
        }

        $db   = getDB();
        $rows = $db->prepare("SELECT id, code FROM cbr_otp WHERE email=? AND used=0 AND expires_at>NOW() ORDER BY id DESC LIMIT 5");
        $rows->execute([$email]);
        $otps = $rows->fetchAll();

        $matched = null;
        foreach ($otps as $otp) {
            if (password_verify($code, $otp['code'])) { $matched = $otp; break; }
        }
        if (!$matched) jsonOut(['error' => 'Неверный или истёкший код'], 400);

        $db->prepare("UPDATE cbr_otp SET used=1 WHERE id=?")->execute([$matched['id']]);

        $ip  = getRealIP();
        $geo = getGeoInfo($ip);
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $dev = parseUA($ua);

        $user = $db->prepare("SELECT id, verified FROM cbr_users WHERE email=?");
        $user->execute([$email]);
        $userData = $user->fetch();

        if (!$userData) {
            $db->prepare("INSERT INTO cbr_users (email,verified,created_at,ip,country,region,city,isp,device_type,browser,os,is_proxy,is_vpn,is_tor,is_mobile,user_agent)
                VALUES (?,1,NOW(),?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                $email, $ip,
                $geo['country']??'', $geo['region']??'', $geo['city']??'', $geo['isp']??'',
                $dev['device'], $dev['browser'], $dev['os'],
                $geo['is_proxy']??0, $geo['is_vpn']??0, $geo['is_tor']??0, $geo['is_mobile']??0,
                mb_substr($ua,0,500)
            ]);
            $userId = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO cbr_notifications (user_id, email_notify) VALUES (?,1)")->execute([$userId]);
        } else {
            $userId = (int)$userData['id'];
            if (!$userData['verified']) {
                $db->prepare("UPDATE cbr_users SET verified=1 WHERE id=?")->execute([$userId]);
            }
            $db->prepare("UPDATE cbr_users SET last_login=NOW(), ip=?, country=?, region=?, city=?, is_proxy=?, is_vpn=?, is_tor=?, is_mobile=? WHERE id=?")
               ->execute([$ip, $geo['country']??'', $geo['region']??'', $geo['city']??'', $geo['is_proxy']??0, $geo['is_vpn']??0, $geo['is_tor']??0, $geo['is_mobile']??0, $userId]);
        }

        $token = bin2hex(random_bytes(32));
        $db->prepare("INSERT INTO cbr_sessions (user_id,token,created_at,expires_at,ip) VALUES (?,?,NOW(),DATE_ADD(NOW(),INTERVAL ".SESSION_EXPIRE." SECOND),?)")
           ->execute([$userId, $token, $ip]);

        setcookie('cbr_session', $token, [
            'expires'  => time() + SESSION_EXPIRE,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        logAnalytics('login');
        jsonOut(['ok' => true, 'redirect' => SITE_URL.'/?page=dashboard']);
    }

    case 'add_tracking': {
        $session = getSession();
        if (!$session) jsonOut(['error' => 'Не авторизован'], 401);

        $url = trim($_POST['url'] ?? '');
        if (!$url) jsonOut(['error' => 'Укажите URL'], 400);

        if (!preg_match('#^https?://#', $url)) $url = 'https://'.$url;
        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) jsonOut(['error' => 'Некорректный URL'], 400);

        $db = getDB();
        $exists = $db->prepare("SELECT id FROM cbr_tracked WHERE user_id=? AND url=? AND status='active'");
        $exists->execute([$session['user_id'], $url]);
        if ($exists->fetch()) jsonOut(['error' => 'Уже отслеживается'], 409);

        $count = $db->prepare("SELECT COUNT(*) FROM cbr_tracked WHERE user_id=? AND status='active'");
        $count->execute([$session['user_id']]);
        if ($count->fetchColumn() >= 50) jsonOut(['error' => 'Превышен лимит (50 сервисов)'], 429);

        $result  = checkUrl($url);
        $inList  = ($result['status'] === 'found') ? 1 : 0;
        $listData= $inList ? json_encode($result['items'][0] ?? null) : null;
        $name    = $parsed['host'];

        $db->prepare("INSERT INTO cbr_tracked (user_id,url,name,added_at,last_check,in_list,list_data) VALUES (?,?,?,NOW(),NOW(),?,?)")
           ->execute([$session['user_id'], $url, $name, $inList, $listData]);

        logAnalytics('add_tracking', $url);
        jsonOut(['ok' => true, 'in_list' => $inList, 'id' => (int)$db->lastInsertId()]);
    }

    case 'remove_tracking': {
        $session = getSession();
        if (!$session) jsonOut(['error' => 'Не авторизован'], 401);
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonOut(['error' => 'Нет id'], 400);
        $db = getDB();
        $db->prepare("UPDATE cbr_tracked SET status='paused' WHERE id=? AND user_id=?")->execute([$id, $session['user_id']]);
        jsonOut(['ok' => true]);
    }

    case 'update_notifications': {
        $session = getSession();
        if (!$session) jsonOut(['error' => 'Не авторизован'], 401);
        $db           = getDB();
        $emailNotify  = (int)(bool)($_POST['email_notify']  ?? 0);
        $tgChatId     = trim($_POST['tg_chat_id']  ?? '');
        $tgUsername   = trim($_POST['tg_username'] ?? '');
        $maxUserId    = trim($_POST['max_user_id'] ?? '');
        $db->prepare("INSERT INTO cbr_notifications (user_id,email_notify,tg_chat_id,tg_username,max_user_id)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE email_notify=?, tg_chat_id=?, tg_username=?, max_user_id=?"
        )->execute([
            $session['user_id'], $emailNotify, $tgChatId ?: null, $tgUsername ?: null, $maxUserId ?: null,
            $emailNotify, $tgChatId ?: null, $tgUsername ?: null, $maxUserId ?: null,
        ]);
        jsonOut(['ok' => true]);
    }

    case 'recheck': {
        $session = getSession();
        if (!$session) jsonOut(['error' => 'Не авторизован'], 401);
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonOut(['error' => 'Нет id'], 400);
        $db   = getDB();
        $row  = $db->prepare("SELECT url FROM cbr_tracked WHERE id=? AND user_id=? AND status='active'");
        $row->execute([$id, $session['user_id']]);
        $item = $row->fetch();
        if (!$item) jsonOut(['error' => 'Не найдено'], 404);

        $result   = checkUrl($item['url']);
        $inList   = ($result['status'] === 'found') ? 1 : 0;
        $listData = $inList ? json_encode($result['items'][0] ?? null) : null;
        $db->prepare("UPDATE cbr_tracked SET in_list=?, list_data=?, last_check=NOW() WHERE id=?")->execute([$inList, $listData, $id]);
        jsonOut(['ok' => true, 'in_list' => $inList, 'status' => $result['status']]);
    }

    default:
        jsonOut(['error' => 'Unknown action'], 404);
}
