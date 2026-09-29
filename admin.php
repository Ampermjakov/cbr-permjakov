<?php
require_once __DIR__.'/config.php';

session_name('cbr_admin');
session_start();

// Создаём таблицу настроек если нет
try {
    getDB()->exec("CREATE TABLE IF NOT EXISTS cbr_settings (
        `key` VARCHAR(64) PRIMARY KEY,
        `value` TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

function getAdminPassHash(): string {
    try {
        $row = getDB()->query("SELECT value FROM cbr_settings WHERE `key`='admin_password'")->fetch();
        if ($row) return $row['value'];
    } catch (Exception $e) {}
    // Первый запуск — сохраняем хэш из config.php
    $hash = password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT);
    try {
        getDB()->prepare("INSERT INTO cbr_settings (`key`,`value`) VALUES ('admin_password',?)")->execute([$hash]);
    } catch (Exception $e) {}
    return $hash;
}

function checkAdminPass(string $input): bool {
    return password_verify($input, getAdminPassHash());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (checkAdminPass($_POST['password'])) {
        $_SESSION['admin'] = true;
    } else {
        $loginError = 'Неверный пароль';
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}
if (empty($_SESSION['admin'])) {
    ?><!DOCTYPE html>
    <html lang="ru"><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Вход — Админ</title>
    <link rel="stylesheet" href="style.css">
    </head><body>
    <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;position:relative;z-index:1">
    <div class="orb orb-1"></div><div class="orb orb-2"></div>
    <div class="card" style="width:360px;margin:0">
        <div style="text-align:center;margin-bottom:28px">
            <div style="font-family:var(--display);font-size:18px;font-weight:800;text-transform:uppercase;letter-spacing:.05em">Админ-панель</div>
            <div style="font-size:13px;color:var(--text-2);margin-top:6px">cbr.permjakov.ru</div>
        </div>
        <?php if (!empty($loginError)): ?>
        <div class="modal-err" style="display:block;margin-bottom:16px"><?= $loginError ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="f-group">
                <label class="f-label">Пароль</label>
                <input type="password" name="password" class="f-input" autofocus>
            </div>
            <button type="submit" class="btn-primary btn-full">Войти →</button>
        </form>
    </div>
    </div></body></html>
    <?php exit;
}

$db  = getDB();
$tab = $_GET['tab'] ?? 'overview';
$settingsMsg = '';

if ($tab === 'settings' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_pass'])) {
    $cur  = $_POST['current_pass']  ?? '';
    $new  = $_POST['new_pass']      ?? '';
    $rep  = $_POST['repeat_pass']   ?? '';
    if (!checkAdminPass($cur)) {
        $settingsMsg = ['type'=>'err', 'text'=>'Неверный текущий пароль'];
    } elseif (strlen($new) < 6) {
        $settingsMsg = ['type'=>'err', 'text'=>'Новый пароль должен быть не менее 6 символов'];
    } elseif ($new !== $rep) {
        $settingsMsg = ['type'=>'err', 'text'=>'Пароли не совпадают'];
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        try {
            getDB()->prepare("INSERT INTO cbr_settings (`key`,`value`) VALUES ('admin_password',?)
                ON DUPLICATE KEY UPDATE `value`=?")->execute([$hash, $hash]);
            $settingsMsg = ['type'=>'ok', 'text'=>'Пароль успешно изменён'];
        } catch (Exception $e) {
            $settingsMsg = ['type'=>'err', 'text'=>'Ошибка сохранения'];
        }
    }
}

// --- Данные для каждого таба ---

if ($tab === 'overview') {
    $stats = [
        'users'       => $db->query("SELECT COUNT(*) FROM cbr_users WHERE verified=1")->fetchColumn(),
        'tracked'     => $db->query("SELECT COUNT(*) FROM cbr_tracked WHERE status='active'")->fetchColumn(),
        'in_list'     => $db->query("SELECT COUNT(*) FROM cbr_tracked WHERE status='active' AND in_list=1")->fetchColumn(),
        'cbr_total'   => $db->query("SELECT total FROM cbr_sync WHERE id=1")->fetchColumn() ?: 0,
        'cbr_synced'  => $db->query("SELECT synced_at FROM cbr_sync WHERE id=1")->fetchColumn() ?: '—',
        'visitors_today' => $db->query("SELECT COUNT(DISTINCT ip) FROM cbr_analytics WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
        'visitors_week'  => $db->query("SELECT COUNT(DISTINCT ip) FROM cbr_analytics WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn(),
        'checks_today'   => $db->query("SELECT COUNT(*) FROM cbr_analytics WHERE query_url!='' AND DATE(created_at)=CURDATE()")->fetchColumn(),
        'vpn_count'      => $db->query("SELECT COUNT(DISTINCT ip) FROM cbr_analytics WHERE is_vpn=1 OR is_proxy=1 OR is_tor=1")->fetchColumn(),
        'otp_pending' => $db->query("SELECT COUNT(*) FROM cbr_otp WHERE used=0 AND expires_at>NOW()")->fetchColumn(),
    ];
    $recentUsers = $db->query("SELECT email, created_at, country, city, is_vpn, is_proxy, is_tor FROM cbr_users ORDER BY created_at DESC LIMIT 10")->fetchAll();
    $recentChecks= $db->query("SELECT query_url, country, city, browser, os, is_vpn, created_at FROM cbr_analytics WHERE query_url!='' ORDER BY created_at DESC LIMIT 15")->fetchAll();
}

if ($tab === 'users') {
    $users = $db->query("
        SELECT u.*,
            COUNT(t.id) as tracked_count,
            SUM(t.in_list) as in_list_count,
            n.email_notify, n.tg_chat_id, n.max_user_id
        FROM cbr_users u
        LEFT JOIN cbr_tracked t ON t.user_id=u.id AND t.status='active'
        LEFT JOIN cbr_notifications n ON n.user_id=u.id
        WHERE u.verified=1
        GROUP BY u.id
        ORDER BY u.created_at DESC
    ")->fetchAll();
}

if ($tab === 'tracked') {
    $tracked = $db->query("
        SELECT t.*, u.email
        FROM cbr_tracked t
        JOIN cbr_users u ON u.id=t.user_id
        WHERE t.status='active'
        ORDER BY t.in_list DESC, t.added_at DESC
    ")->fetchAll();
}

if ($tab === 'analytics') {
    $byCountry = $db->query("SELECT country, COUNT(DISTINCT ip) as cnt FROM cbr_analytics WHERE country!='' GROUP BY country ORDER BY cnt DESC LIMIT 15")->fetchAll();
    $byBrowser = $db->query("SELECT browser, COUNT(DISTINCT ip) as cnt FROM cbr_analytics WHERE browser!='' GROUP BY browser ORDER BY cnt DESC")->fetchAll();
    $byOS      = $db->query("SELECT os, COUNT(DISTINCT ip) as cnt FROM cbr_analytics WHERE os!='' GROUP BY os ORDER BY cnt DESC")->fetchAll();
    $byDay     = $db->query("SELECT DATE(created_at) as day, COUNT(DISTINCT ip) as cnt FROM cbr_analytics WHERE created_at >= DATE_SUB(NOW(),INTERVAL 14 DAY) GROUP BY day ORDER BY day DESC")->fetchAll();
    $anonStats = $db->query("SELECT
        COUNT(DISTINCT CASE WHEN is_vpn=1   THEN ip END) as vpn,
        COUNT(DISTINCT CASE WHEN is_proxy=1 THEN ip END) as proxy,
        COUNT(DISTINCT CASE WHEN is_tor=1   THEN ip END) as tor,
        COUNT(DISTINCT CASE WHEN is_mobile=1 THEN ip END) as mobile,
        COUNT(DISTINCT ip) as total
        FROM cbr_analytics")->fetch();
    $topUrls   = $db->query("SELECT query_url, COUNT(*) as cnt FROM cbr_analytics WHERE query_url!='' GROUP BY query_url ORDER BY cnt DESC LIMIT 20")->fetchAll();
}

if ($tab === 'cbr') {
    $inListItems = $db->query("
        SELECT t.url, t.added_at, t.notified_at, u.email, c.name, c.sign, c.org_type, c.dt
        FROM cbr_tracked t
        JOIN cbr_users u ON u.id=t.user_id
        LEFT JOIN cbr_list c ON LOWER(c.site)=LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(t.url,'https://',''),'http://',''),'/',1),'?',1))
        WHERE t.in_list=1 AND t.status='active'
        ORDER BY t.added_at DESC
    ")->fetchAll();
    $recentCbr = $db->query("SELECT * FROM cbr_list ORDER BY dt DESC LIMIT 30")->fetchAll();
}

function ago(string $dt): string {
    if (!$dt) return '—';
    $sec = time() - strtotime($dt);
    if ($sec < 60)    return 'только что';
    if ($sec < 3600)  return floor($sec/60).' мин назад';
    if ($sec < 86400) return floor($sec/3600).' ч назад';
    return date('d.m.Y H:i', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Админ — ЦБ РФ Мониторинг</title>
<link rel="stylesheet" href="style.css">
<style>
.admin-wrap { max-width: 1200px; margin: 0 auto; padding: 32px 24px 80px; position: relative; z-index: 1; }
.admin-hdr { display: flex; align-items: center; justify-content: space-between; margin-bottom: 32px; flex-wrap: wrap; gap: 12px; }
.admin-title { font-family: var(--display); font-size: 1.6em; font-weight: 800; letter-spacing: -.02em; }
.admin-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 28px; flex-wrap: wrap; }
.admin-tab { padding: 10px 20px; background: none; border: none; font-size: 13px; font-weight: 500; color: var(--text-3); cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; transition: all .2s; text-decoration: none; font-family: var(--sans); white-space: nowrap; }
.admin-tab:hover { color: var(--text-2); }
.admin-tab.active { color: var(--accent-l); border-bottom-color: var(--accent); }
.kpi-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; margin-bottom: 28px; }
.kpi { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 20px 22px; }
.kpi-val { font-family: var(--display); font-size: 2.2em; font-weight: 800; line-height: 1; color: var(--accent-l); }
.kpi-lbl { font-family: var(--mono); font-size: 10px; color: var(--text-3); text-transform: uppercase; letter-spacing: .1em; margin-top: 6px; }
.kpi-ok .kpi-val { color: var(--green); }
.kpi-warn .kpi-val { color: var(--amber); }
.admin-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.admin-table th { text-align: left; padding: 10px 14px; font-family: var(--mono); font-size: 10px; text-transform: uppercase; letter-spacing: .1em; color: var(--text-3); border-bottom: 1px solid var(--border); white-space: nowrap; }
.admin-table td { padding: 11px 14px; border-bottom: 1px solid rgba(255,255,255,.04); vertical-align: top; color: var(--text-2); word-break: break-all; }
.admin-table tr:hover td { background: rgba(255,255,255,.02); }
.tbl-wrap { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 20px; overflow-x: auto; }
.section-ttl { font-family: var(--display); font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 14px; margin-top: 28px; color: var(--text); }
.section-ttl:first-child { margin-top: 0; }
.tag { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; font-family: var(--mono); }
.tag-danger { background: rgba(244,63,94,.14); color: #fda4af; }
.tag-ok { background: var(--green-soft); color: var(--green); }
.tag-warn { background: var(--amber-soft); color: var(--amber); }
.tag-muted { background: rgba(255,255,255,.06); color: var(--text-3); }
.bar-row { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; font-size: 13px; }
.bar-lbl { min-width: 120px; color: var(--text-2); font-family: var(--mono); font-size: 12px; flex-shrink: 0; }
.bar-track { flex: 1; height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; }
.bar-fill { height: 100%; background: var(--accent); border-radius: 3px; transition: width .4s; }
.bar-cnt { min-width: 40px; text-align: right; color: var(--text-3); font-family: var(--mono); font-size: 12px; }
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
@media(max-width:640px) { .two-col { grid-template-columns: 1fr; } .kpi-grid { grid-template-columns: repeat(2,1fr); } }
</style>
</head>
<body>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>

<div class="admin-wrap">
    <div class="admin-hdr">
        <div>
            <div class="admin-title">Панель управления</div>
            <div style="font-family:var(--mono);font-size:12px;color:var(--text-3);margin-top:4px">cbr.permjakov.ru</div>
        </div>
        <div style="display:flex;gap:10px;align-items:center">
            <a href="/" class="btn-secondary" style="font-size:12px;padding:8px 16px">← Сайт</a>
            <a href="?logout=1" class="btn-danger-sm">Выйти</a>
        </div>
    </div>

    <div class="admin-tabs">
        <a href="?tab=overview"   class="admin-tab <?= $tab==='overview'  ?'active':'' ?>">Обзор</a>
        <a href="?tab=users"      class="admin-tab <?= $tab==='users'     ?'active':'' ?>">Пользователи</a>
        <a href="?tab=tracked"    class="admin-tab <?= $tab==='tracked'   ?'active':'' ?>">Отслеживаемые</a>
        <a href="?tab=analytics"  class="admin-tab <?= $tab==='analytics' ?'active':'' ?>">Аналитика</a>
        <a href="?tab=cbr"        class="admin-tab <?= $tab==='cbr'       ?'active':'' ?>">Список ЦБ РФ</a>
        <a href="?tab=settings"   class="admin-tab <?= $tab==='settings'  ?'active':'' ?>">⚙ Настройки</a>
    </div>

<?php if ($tab === 'overview'): ?>

    <div class="kpi-grid">
        <div class="kpi kpi-ok"><div class="kpi-val"><?= $stats['users'] ?></div><div class="kpi-lbl">Пользователей</div></div>
        <div class="kpi"><div class="kpi-val"><?= $stats['tracked'] ?></div><div class="kpi-lbl">Отслеживается</div></div>
        <div class="kpi <?= $stats['in_list']>0 ? 'kpi-warn' : 'kpi-ok' ?>">
            <div class="kpi-val"><?= $stats['in_list'] ?></div><div class="kpi-lbl">В стоп-листе</div>
        </div>
        <div class="kpi"><div class="kpi-val"><?= number_format($stats['cbr_total']) ?></div><div class="kpi-lbl">Записей ЦБ РФ</div></div>
        <div class="kpi kpi-ok"><div class="kpi-val"><?= $stats['visitors_today'] ?></div><div class="kpi-lbl">Уник. посетителей сегодня</div></div>
        <div class="kpi kpi-ok"><div class="kpi-val"><?= $stats['visitors_week'] ?></div><div class="kpi-lbl">Уник. посетителей за 7 дней</div></div>
        <div class="kpi"><div class="kpi-val"><?= $stats['checks_today'] ?></div><div class="kpi-lbl">Проверок сегодня</div></div>
        <div class="kpi <?= $stats['vpn_count']>0 ? 'kpi-warn' : '' ?>">
            <div class="kpi-val"><?= $stats['vpn_count'] ?></div><div class="kpi-lbl">VPN/Proxy</div>
        </div>
        <div class="kpi"><div class="kpi-val"><?= $stats['otp_pending'] ?></div><div class="kpi-lbl">OTP активных</div></div>
    </div>

    <div style="font-family:var(--mono);font-size:12px;color:var(--text-3);margin-bottom:24px">
        Список ЦБ РФ обновлён: <?= $stats['cbr_synced'] ? ago($stats['cbr_synced']) : '—' ?>
    </div>

    <p class="section-ttl">Последние регистрации</p>
    <div class="tbl-wrap">
        <table class="admin-table">
            <thead><tr>
                <th>Email</th><th>Дата</th><th>Страна / Город</th><th>Флаги</th>
            </tr></thead>
            <tbody>
            <?php foreach ($recentUsers as $u): ?>
            <tr>
                <td style="color:var(--text)"><?= htmlspecialchars($u['email']) ?></td>
                <td style="white-space:nowrap"><?= ago($u['created_at']) ?></td>
                <td><?= htmlspecialchars(trim($u['country'].' '.$u['city'])) ?></td>
                <td>
                    <?php if ($u['is_tor']): ?><span class="tag tag-danger">Tor</span> <?php endif; ?>
                    <?php if ($u['is_vpn']): ?><span class="tag tag-warn">VPN</span> <?php endif; ?>
                    <?php if ($u['is_proxy']): ?><span class="tag tag-warn">Proxy</span> <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="section-ttl">Последние проверки</p>
    <div class="tbl-wrap">
        <table class="admin-table">
            <thead><tr>
                <th>URL</th><th>Страна</th><th>Браузер / ОС</th><th>VPN</th><th>Время</th>
            </tr></thead>
            <tbody>
            <?php foreach ($recentChecks as $r): ?>
            <tr>
                <td style="color:var(--text);max-width:280px"><?= htmlspecialchars($r['query_url']) ?></td>
                <td><?= htmlspecialchars($r['country'].' '.($r['city']?'('.$r['city'].')':'')) ?></td>
                <td style="white-space:nowrap"><?= htmlspecialchars($r['browser'].' / '.$r['os']) ?></td>
                <td><?= ($r['is_vpn']) ? '<span class="tag tag-warn">VPN</span>' : '' ?></td>
                <td style="white-space:nowrap;color:var(--text-3)"><?= ago($r['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab === 'users'): ?>

    <p style="font-family:var(--mono);font-size:12px;color:var(--text-3);margin-bottom:16px"><?= count($users) ?> пользователей</p>
    <div class="tbl-wrap">
        <table class="admin-table">
            <thead><tr>
                <th>Email</th><th>Регистрация</th><th>Последний вход</th>
                <th>Страна / Город</th><th>Устройство</th><th>Флаги</th>
                <th>Сервисов</th><th>В стоп-листе</th><th>Уведомления</th>
            </tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td style="color:var(--text)"><?= htmlspecialchars($u['email']) ?></td>
                <td style="white-space:nowrap"><?= date('d.m.Y', strtotime($u['created_at'])) ?></td>
                <td style="white-space:nowrap"><?= $u['last_login'] ? ago($u['last_login']) : '—' ?></td>
                <td><?= htmlspecialchars(trim($u['country'].' '.($u['city']?'('.$u['city'].')':''))) ?></td>
                <td style="white-space:nowrap"><?= htmlspecialchars($u['browser'].' / '.$u['os']) ?></td>
                <td>
                    <?php if ($u['is_tor']): ?><span class="tag tag-danger">Tor</span> <?php endif; ?>
                    <?php if ($u['is_vpn']): ?><span class="tag tag-warn">VPN</span> <?php endif; ?>
                    <?php if ($u['is_proxy']): ?><span class="tag tag-warn">Proxy</span> <?php endif; ?>
                    <?php if ($u['is_mobile']): ?><span class="tag tag-muted">Mobile</span> <?php endif; ?>
                </td>
                <td><?= (int)$u['tracked_count'] ?></td>
                <td><?= (int)$u['in_list_count'] ? '<span class="tag tag-danger">'.(int)$u['in_list_count'].'</span>' : '0' ?></td>
                <td>
                    <?php if ($u['email_notify']): ?><span class="tag tag-ok">Email</span> <?php endif; ?>
                    <?php if ($u['tg_chat_id']): ?><span class="tag tag-muted">TG</span> <?php endif; ?>
                    <?php if ($u['max_user_id']): ?><span class="tag tag-muted">MAX</span> <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab === 'tracked'): ?>

    <?php
    $inList = array_filter($tracked, fn($r) => $r['in_list']);
    $clean  = array_filter($tracked, fn($r) => !$r['in_list']);
    ?>
    <?php if ($inList): ?>
    <p class="section-ttl" style="color:#fda4af">🚨 В стоп-листе ЦБ РФ (<?= count($inList) ?>)</p>
    <div class="tbl-wrap">
        <table class="admin-table">
            <thead><tr><th>URL</th><th>Пользователь</th><th>Добавлен</th><th>Проверен</th><th>Уведомлён</th></tr></thead>
            <tbody>
            <?php foreach ($inList as $r): ?>
            <tr>
                <td style="color:#fda4af"><?= htmlspecialchars($r['url']) ?></td>
                <td><?= htmlspecialchars($r['email']) ?></td>
                <td style="white-space:nowrap"><?= date('d.m.Y', strtotime($r['added_at'])) ?></td>
                <td style="white-space:nowrap"><?= $r['last_check'] ? ago($r['last_check']) : '—' ?></td>
                <td style="white-space:nowrap"><?= $r['notified_at'] ? ago($r['notified_at']) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <p class="section-ttl">Все отслеживаемые (<?= count($tracked) ?>)</p>
    <div class="tbl-wrap">
        <table class="admin-table">
            <thead><tr><th>Статус</th><th>URL</th><th>Пользователь</th><th>Добавлен</th><th>Последняя проверка</th></tr></thead>
            <tbody>
            <?php foreach ($tracked as $r): ?>
            <tr>
                <td><?= $r['in_list'] ? '<span class="tag tag-danger">В списке</span>' : '<span class="tag tag-ok">Чисто</span>' ?></td>
                <td style="color:var(--text)"><?= htmlspecialchars($r['url']) ?></td>
                <td><?= htmlspecialchars($r['email']) ?></td>
                <td style="white-space:nowrap"><?= date('d.m.Y', strtotime($r['added_at'])) ?></td>
                <td style="white-space:nowrap"><?= $r['last_check'] ? ago($r['last_check']) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab === 'analytics'): ?>

    <div class="kpi-grid" style="margin-bottom:28px">
        <div class="kpi kpi-ok"><div class="kpi-val"><?= number_format($anonStats['total']) ?></div><div class="kpi-lbl">Всего визитов</div></div>
        <div class="kpi kpi-warn"><div class="kpi-val"><?= number_format($anonStats['vpn']) ?></div><div class="kpi-lbl">VPN</div></div>
        <div class="kpi kpi-warn"><div class="kpi-val"><?= number_format($anonStats['proxy']) ?></div><div class="kpi-lbl">Proxy</div></div>
        <div class="kpi kpi-warn"><div class="kpi-val"><?= number_format($anonStats['tor']) ?></div><div class="kpi-lbl">Tor</div></div>
        <div class="kpi"><div class="kpi-val"><?= number_format($anonStats['mobile']) ?></div><div class="kpi-lbl">Mobile</div></div>
    </div>

    <p class="section-ttl">Визиты по дням (14 дней)</p>
    <div class="tbl-wrap" style="padding:20px 24px">
        <?php $maxDay = max(array_column($byDay,'cnt') ?: [1]); ?>
        <?php foreach ($byDay as $d): ?>
        <div class="bar-row">
            <span class="bar-lbl"><?= date('d.m', strtotime($d['day'])) ?></span>
            <div class="bar-track"><div class="bar-fill" style="width:<?= round($d['cnt']/$maxDay*100) ?>%"></div></div>
            <span class="bar-cnt"><?= $d['cnt'] ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="two-col">
        <div>
            <p class="section-ttl">Страны</p>
            <div class="tbl-wrap" style="padding:20px 24px">
                <?php $maxC = max(array_column($byCountry,'cnt') ?: [1]); ?>
                <?php foreach ($byCountry as $c): ?>
                <div class="bar-row">
                    <span class="bar-lbl"><?= htmlspecialchars($c['country'] ?: 'Неизвестно') ?></span>
                    <div class="bar-track"><div class="bar-fill" style="width:<?= round($c['cnt']/$maxC*100) ?>%"></div></div>
                    <span class="bar-cnt"><?= $c['cnt'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div>
            <p class="section-ttl">Браузеры</p>
            <div class="tbl-wrap" style="padding:20px 24px">
                <?php $maxB = max(array_column($byBrowser,'cnt') ?: [1]); ?>
                <?php foreach ($byBrowser as $b): ?>
                <div class="bar-row">
                    <span class="bar-lbl"><?= htmlspecialchars($b['browser'] ?: '—') ?></span>
                    <div class="bar-track"><div class="bar-fill" style="width:<?= round($b['cnt']/$maxB*100) ?>%"></div></div>
                    <span class="bar-cnt"><?= $b['cnt'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="section-ttl">ОС</p>
            <div class="tbl-wrap" style="padding:20px 24px">
                <?php $maxO = max(array_column($byOS,'cnt') ?: [1]); ?>
                <?php foreach ($byOS as $o): ?>
                <div class="bar-row">
                    <span class="bar-lbl"><?= htmlspecialchars($o['os'] ?: '—') ?></span>
                    <div class="bar-track"><div class="bar-fill" style="width:<?= round($o['cnt']/$maxO*100) ?>%"></div></div>
                    <span class="bar-cnt"><?= $o['cnt'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <p class="section-ttl">Топ проверяемых URL</p>
    <div class="tbl-wrap">
        <table class="admin-table">
            <thead><tr><th>#</th><th>URL</th><th>Проверок</th></tr></thead>
            <tbody>
            <?php foreach ($topUrls as $i => $u): ?>
            <tr>
                <td style="color:var(--text-3)"><?= $i+1 ?></td>
                <td style="color:var(--text)"><?= htmlspecialchars($u['query_url']) ?></td>
                <td><span class="tag tag-muted"><?= $u['cnt'] ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab === 'cbr'): ?>

    <?php if ($inListItems): ?>
    <p class="section-ttl" style="color:#fda4af">🚨 Отслеживаемые сервисы в стоп-листе</p>
    <div class="tbl-wrap">
        <table class="admin-table">
            <thead><tr><th>URL</th><th>Название</th><th>Признак</th><th>Дата ЦБ</th><th>Пользователь</th><th>Уведомлён</th></tr></thead>
            <tbody>
            <?php foreach ($inListItems as $r): ?>
            <tr>
                <td style="color:#fda4af"><?= htmlspecialchars($r['url']) ?></td>
                <td><?= htmlspecialchars($r['name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($r['sign'] ?? '—') ?></td>
                <td style="white-space:nowrap"><?= $r['dt'] ?? '—' ?></td>
                <td><?= htmlspecialchars($r['email']) ?></td>
                <td style="white-space:nowrap"><?= $r['notified_at'] ? ago($r['notified_at']) : '<span class="tag tag-warn">Нет</span>' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <p class="section-ttl">Последние добавленные в список ЦБ РФ</p>
    <div class="tbl-wrap">
        <table class="admin-table">
            <thead><tr><th>ID</th><th>Название</th><th>Сайт</th><th>Признак</th><th>Тип</th><th>Дата</th></tr></thead>
            <tbody>
            <?php foreach ($recentCbr as $r): ?>
            <tr>
                <td style="color:var(--text-3);font-family:var(--mono)"><?= $r['cbr_id'] ?></td>
                <td style="color:var(--text)"><?= htmlspecialchars($r['name'] ?? '—') ?></td>
                <td style="color:var(--accent-l)"><?= htmlspecialchars($r['site'] ?? '—') ?></td>
                <td><?= htmlspecialchars(mb_substr($r['sign'] ?? '—', 0, 60)) ?></td>
                <td><span class="tag tag-muted"><?= htmlspecialchars($r['org_type'] ?? '—') ?></span></td>
                <td style="white-space:nowrap"><?= $r['dt'] ?? '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab === 'settings'): ?>

    <div style="max-width:420px">
        <p class="section-ttl">Смена пароля</p>
        <div class="card" style="margin:0">
            <?php if ($settingsMsg): ?>
            <div class="<?= $settingsMsg['type']==='ok' ? 'notif-msg notif-msg-ok' : 'modal-err' ?>"
                 style="display:block;margin-bottom:20px">
                <?= htmlspecialchars($settingsMsg['text']) ?>
            </div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="change_pass" value="1">
                <div class="f-group">
                    <label class="f-label">Текущий пароль</label>
                    <input type="password" name="current_pass" class="f-input" required autofocus>
                </div>
                <div class="f-group">
                    <label class="f-label">Новый пароль</label>
                    <input type="password" name="new_pass" class="f-input" required minlength="6">
                </div>
                <div class="f-group">
                    <label class="f-label">Повторите новый пароль</label>
                    <input type="password" name="repeat_pass" class="f-input" required minlength="6">
                </div>
                <button type="submit" class="btn-primary btn-full">Сохранить пароль</button>
            </form>
        </div>
    </div>

<?php endif; ?>

</div>
</body>
</html>
