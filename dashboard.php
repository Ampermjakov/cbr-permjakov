<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/cbr.php';

$session = requireAuth();
$db      = getDB();
$userId  = (int)$session['user_id'];

$tracked = $db->prepare("SELECT * FROM cbr_tracked WHERE user_id=? AND status='active' ORDER BY added_at DESC");
$tracked->execute([$userId]);
$items = $tracked->fetchAll();

$notif = $db->prepare("SELECT * FROM cbr_notifications WHERE user_id=?");
$notif->execute([$userId]);
$notifRow = $notif->fetch() ?: ['email_notify'=>1,'tg_chat_id'=>'','tg_username'=>'','max_user_id'=>''];

$user = $db->prepare("SELECT * FROM cbr_users WHERE id=?");
$user->execute([$userId]);
$userRow = $user->fetch();

$inListCount  = count(array_filter($items, fn($i) => $i['in_list']));
$cleanCount   = count($items) - $inListCount;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Мой кабинет — ЦБ РФ Мониторинг</title>
<link rel="icon" href="https://permjakov.ru/amper.svg" type="image/x-icon">
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="orb orb-1"></div>
<div class="orb orb-2"></div>

<nav class="topnav">
    <div class="topnav-inner">
        <a href="https://permjakov.ru" class="brand"><span>//</span> permjakov.ru</a>
        <div class="nav-tools">
            <a href="https://chek.permjakov.ru/"  class="nav-tool">РКН Аудит</a>
            <a href="https://rkn.permjakov.ru"    class="nav-tool">РКН Блок</a>
            <a href="https://cbr.permjakov.ru"    class="nav-tool active">ЦБ РФ</a>
            <a href="https://whois.permjakov.ru"  class="nav-tool">Whois</a>
            <a href="https://dns.permjakov.ru"    class="nav-tool">DNS</a>
            <a href="https://ssl.permjakov.ru"    class="nav-tool">SSL</a>
            <a href="https://monitor.permjakov.ru" class="nav-tool">Мониторинг</a>
        </div>
        <div class="nav-right">
            <a href="/" class="nav-tool">← Проверить</a>
            <a href="/?logout=1" class="nav-tool" style="color:var(--text-3)">Выйти</a>
        </div>
        <button class="nav-burger" id="navBurger" aria-label="Меню"><span></span><span></span><span></span></button>
    </div>
</nav>

<div class="container container-dash">

    <div class="dash-header">
        <div>
            <h1 class="dash-title">Мой кабинет</h1>
            <p class="dash-email"><?= htmlspecialchars($session['email']) ?></p>
        </div>
        <button class="btn-primary" onclick="openAddModal()">+ Добавить сервис</button>
    </div>

    <div class="stats-row">
        <div class="stat-chip">
            <span class="stat-num"><?= count($items) ?></span>
            <span class="stat-lbl">отслеживается</span>
        </div>
        <div class="stat-chip stat-chip-danger <?= $inListCount ? 'stat-chip-active' : '' ?>">
            <span class="stat-num"><?= $inListCount ?></span>
            <span class="stat-lbl">в стоп-листе</span>
        </div>
        <div class="stat-chip stat-chip-ok">
            <span class="stat-num"><?= $cleanCount ?></span>
            <span class="stat-lbl">чисто</span>
        </div>
    </div>

    <?php if ($inListCount > 0): ?>
    <div class="alert-banner">
        🚨 <strong><?= $inListCount ?> сервис(а)</strong> из вашего списка обнаружен в стоп-листе ЦБ РФ
    </div>
    <?php endif; ?>

    <div class="section-tabs">
        <button class="tab-btn active" onclick="switchTab('tracked')">Отслеживаемые</button>
        <button class="tab-btn" onclick="switchTab('notif')">Уведомления</button>
        <button class="tab-btn" onclick="switchTab('profile')">Профиль</button>
    </div>

    <div id="tabTracked" class="tab-pane">
        <?php if (empty($items)): ?>
        <div class="empty-state">
            <div class="empty-icon">📋</div>
            <p>Список пуст. <a href="/" class="link-accent">Проверьте сервис</a> и добавьте в отслеживаемые.</p>
        </div>
        <?php else: ?>
        <div class="tracked-list">
            <?php foreach ($items as $item):
                $listData = $item['list_data'] ? json_decode($item['list_data'], true) : null;
                $lastCheck = $item['last_check'] ? date('d.m.Y H:i', strtotime($item['last_check'])) : '—';
                $added     = date('d.m.Y', strtotime($item['added_at']));
            ?>
            <div class="tracked-item <?= $item['in_list'] ? 'tracked-item-danger' : 'tracked-item-ok' ?>" id="item-<?= $item['id'] ?>">
                <div class="tracked-status">
                    <?php if ($item['in_list']): ?>
                    <span class="badge badge-danger">🚨 В стоп-листе</span>
                    <?php else: ?>
                    <span class="badge badge-ok">✅ Чисто</span>
                    <?php endif; ?>
                </div>
                <div class="tracked-url"><?= htmlspecialchars($item['url']) ?></div>
                <?php if ($item['in_list'] && $listData): ?>
                <div class="tracked-cbr-data">
                    <?php foreach (array_slice($listData, 0, 4) as $k => $v): if (!$v) continue; ?>
                    <span class="cbr-mini-row"><span class="cbr-mini-key"><?= htmlspecialchars($k) ?>:</span> <?= htmlspecialchars((string)$v) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="tracked-meta">
                    <span>Добавлен: <?= $added ?></span>
                    <span>Проверен: <?= $lastCheck ?></span>
                </div>
                <div class="tracked-actions">
                    <button class="btn-ghost-sm" onclick="recheck(<?= $item['id'] ?>)">↻ Перепроверить</button>
                    <button class="btn-danger-sm" onclick="removeTracked(<?= $item['id'] ?>)">Удалить</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div id="tabNotif" class="tab-pane" style="display:none">
        <div class="card notif-card">
            <h3 class="notif-title">Настройки уведомлений</h3>

            <div class="notif-row">
                <div class="notif-info">
                    <span class="notif-icon">📧</span>
                    <div>
                        <div class="notif-name">Email</div>
                        <div class="notif-hint"><?= htmlspecialchars($session['email']) ?></div>
                    </div>
                </div>
                <label class="toggle">
                    <input type="checkbox" id="emailNotify" <?= $notifRow['email_notify'] ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div class="notif-row notif-row-expand">
                <div class="notif-info">
                    <span class="notif-icon">✈️</span>
                    <div>
                        <div class="notif-name">Telegram</div>
                        <div class="notif-hint">Введите Chat ID или username</div>
                    </div>
                </div>
                <button class="btn-ghost-sm" onclick="toggleExpand('tgExpand')">Настроить</button>
            </div>
            <div id="tgExpand" class="notif-expand" style="display:none">
                <div class="notif-how">
                    <strong>Как узнать свой Chat ID:</strong><br>
                    1. Откройте <a href="https://t.me/userinfobot" target="_blank" rel="noopener">@userinfobot</a> в Telegram<br>
                    2. Напишите ему любое сообщение<br>
                    3. Он ответит вашим ID — скопируйте число и вставьте ниже
                </div>
                <div class="f-group">
                    <label class="f-label">Telegram Chat ID</label>
                    <input type="text" id="tgChatId" class="f-input f-mono" placeholder="123456789"
                        value="<?= htmlspecialchars($notifRow['tg_chat_id'] ?? '') ?>">
                </div>
            </div>

            <div class="notif-row notif-row-expand">
                <div class="notif-info">
                    <span class="notif-icon">💬</span>
                    <div>
                        <div class="notif-name">MAX</div>
                        <div class="notif-hint">Уведомления через MAX</div>
                    </div>
                </div>
                <button class="btn-ghost-sm" onclick="toggleExpand('maxExpand')">Настроить</button>
            </div>
            <div id="maxExpand" class="notif-expand" style="display:none">
                <div class="notif-how">
                    <strong>Как подключить MAX:</strong><br>
                    1. Откройте бота <a href="https://max.ru/join/<?= MAX_BOT_USERNAME ?>" target="_blank" rel="noopener">@<?= MAX_BOT_USERNAME ?></a> в приложении MAX<br>
                    2. Нажмите <strong>Start</strong> или отправьте <code>/start</code><br>
                    3. Бот пришлёт ваш Chat ID — скопируйте и вставьте ниже
                </div>
                <div class="f-group">
                    <label class="f-label">MAX Chat ID</label>
                    <input type="text" id="maxUserId" class="f-input f-mono" placeholder="chat_id"
                        value="<?= htmlspecialchars($notifRow['max_user_id'] ?? '') ?>">
                </div>
            </div>

            <button class="btn-primary" style="margin-top:20px" onclick="saveNotif()">Сохранить настройки</button>
            <div id="notifMsg" class="notif-msg" style="display:none"></div>
        </div>
    </div>

    <div id="tabProfile" class="tab-pane" style="display:none">
        <div class="card">
            <h3 class="notif-title">Информация об аккаунте</h3>
            <div class="profile-grid">
                <div class="profile-row"><span class="profile-lbl">Email</span><span class="profile-val"><?= htmlspecialchars($session['email']) ?></span></div>
                <div class="profile-row"><span class="profile-lbl">Зарегистрирован</span><span class="profile-val"><?= $userRow ? date('d.m.Y', strtotime($userRow['created_at'])) : '—' ?></span></div>
                <div class="profile-row"><span class="profile-lbl">Последний вход</span><span class="profile-val"><?= $userRow['last_login'] ? date('d.m.Y H:i', strtotime($userRow['last_login'])) : '—' ?></span></div>
                <div class="profile-row"><span class="profile-lbl">IP при входе</span><span class="profile-val profile-mono"><?= htmlspecialchars($userRow['ip'] ?? '—') ?></span></div>
                <div class="profile-row"><span class="profile-lbl">Страна / Город</span><span class="profile-val"><?= htmlspecialchars(trim(($userRow['country']??'').' '.($userRow['city']??''))) ?: '—' ?></span></div>
                <div class="profile-row"><span class="profile-lbl">Устройство</span><span class="profile-val"><?= htmlspecialchars(trim(($userRow['browser']??'').' / '.($userRow['os']??''))) ?></span></div>
                <?php if ($userRow['is_vpn'] || $userRow['is_proxy'] || $userRow['is_tor']): ?>
                <div class="profile-row"><span class="profile-lbl">Анонимизация</span><span class="profile-val profile-warn">
                    <?= $userRow['is_vpn'] ? 'VPN ' : '' ?><?= $userRow['is_proxy'] ? 'Proxy ' : '' ?><?= $userRow['is_tor'] ? 'Tor' : '' ?>
                </span></div>
                <?php endif; ?>
            </div>
            <div class="profile-actions">
                <a href="/?logout=1" class="btn-danger-sm">Выйти из аккаунта</a>
            </div>
        </div>
    </div>

</div>

<div class="modal-overlay" id="addModal">
    <div class="modal-box modal-box-sm">
        <div class="modal-hdr">
            <span class="modal-ttl">Добавить сервис</span>
            <button class="modal-close" onclick="closeModal('addModal')">✕</button>
        </div>
        <div class="modal-bdy">
            <div class="f-group">
                <label class="f-label">URL сервиса</label>
                <input type="text" id="addUrl" class="f-input" placeholder="https://example.com" autocomplete="off">
            </div>
            <button class="btn-primary btn-full" onclick="doAdd()">Добавить →</button>
            <div id="addMsg" class="modal-err" style="display:none"></div>
        </div>
    </div>
</div>

<div class="nav-mobile" id="navMobile">
    <button class="nav-mobile-close" id="navMobileClose" aria-label="Закрыть">✕</button>
    <a href="https://chek.permjakov.ru/" class="m-link">РКН Аудит</a>
    <a href="https://rkn.permjakov.ru"   class="m-link">РКН Блок</a>
    <a href="https://cbr.permjakov.ru"   class="m-link active">ЦБ РФ</a>
    <a href="https://whois.permjakov.ru" class="m-link">Whois</a>
    <a href="https://dns.permjakov.ru"   class="m-link">DNS</a>
    <a href="https://ssl.permjakov.ru"   class="m-link">SSL</a>
    <a href="https://monitor.permjakov.ru" class="m-link">Мониторинг</a>
</div>

<script>
function switchTab(name) {
    document.querySelectorAll('.tab-pane').forEach(function(p){ p.style.display='none'; });
    document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
    document.getElementById('tab'+name.charAt(0).toUpperCase()+name.slice(1)).style.display = '';
    event.currentTarget.classList.add('active');
}

function toggleExpand(id) {
    var el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? '' : 'none';
}

function openAddModal() {
    document.getElementById('addUrl').value = '';
    document.getElementById('addMsg').style.display = 'none';
    document.getElementById('addModal').classList.add('open');
    setTimeout(function(){ document.getElementById('addUrl').focus(); }, 100);
}

function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.modal-overlay').forEach(function(el){
    el.addEventListener('click', function(e){ if(e.target===el) el.classList.remove('open'); });
});
document.addEventListener('keydown', function(e){ if(e.key==='Escape') document.querySelectorAll('.modal-overlay.open').forEach(function(el){ el.classList.remove('open'); }); });

async function api(data) {
    try {
        var fd = new FormData();
        Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
        var r = await fetch('api.php', {method:'POST', body:fd});
        return await r.json();
    } catch(e) { return {error:'Сетевая ошибка'}; }
}

async function doAdd() {
    var url = document.getElementById('addUrl').value.trim();
    if (!url) { showAddMsg('Введите URL'); return; }
    var btn = document.querySelector('#addModal .btn-primary');
    btn.disabled = true; btn.textContent = 'Добавление...';
    var r = await api({action:'add_tracking', url:url});
    btn.disabled = false; btn.textContent = 'Добавить →';
    if (r.ok) { closeModal('addModal'); location.reload(); }
    else showAddMsg(r.error || 'Ошибка');
}

function showAddMsg(msg) {
    var el = document.getElementById('addMsg');
    el.textContent = msg; el.style.display = '';
}

async function removeTracked(id) {
    if (!confirm('Удалить из отслеживаемых?')) return;
    var r = await api({action:'remove_tracking', id:id});
    if (r.ok) {
        var el = document.getElementById('item-'+id);
        if (el) { el.style.opacity='0'; setTimeout(function(){ el.remove(); }, 300); }
    }
}

async function recheck(id) {
    var el = document.getElementById('item-'+id);
    if (el) el.style.opacity = '0.5';
    var r = await api({action:'recheck', id:id});
    if (el) el.style.opacity = '1';
    if (r.ok) location.reload();
    else alert(r.error || 'Ошибка');
}

async function saveNotif() {
    var r = await api({
        action:       'update_notifications',
        email_notify: document.getElementById('emailNotify').checked ? 1 : 0,
        tg_chat_id:   document.getElementById('tgChatId').value.trim(),
        max_user_id:  document.getElementById('maxUserId').value.trim(),
    });
    var msg = document.getElementById('notifMsg');
    msg.style.display = '';
    if (r.ok) { msg.className = 'notif-msg notif-msg-ok'; msg.textContent = '✅ Настройки сохранены'; }
    else      { msg.className = 'notif-msg notif-msg-err'; msg.textContent = r.error || 'Ошибка'; }
    setTimeout(function(){ msg.style.display='none'; }, 3000);
}

(function(){
    var burger=document.getElementById('navBurger'), mobile=document.getElementById('navMobile'), closeBtn=document.getElementById('navMobileClose');
    if(!burger||!mobile) return;
    function openNav(){mobile.classList.add('open');burger.classList.add('open');document.body.style.overflow='hidden';}
    function closeNav(){mobile.classList.remove('open');burger.classList.remove('open');document.body.style.overflow='';}
    burger.addEventListener('click',function(){mobile.classList.contains('open')?closeNav():openNav();});
    if(closeBtn)closeBtn.addEventListener('click',closeNav);
    mobile.querySelectorAll('a').forEach(function(a){a.addEventListener('click',closeNav);});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')closeNav();});
})();
</script>

<?php if (isset($_COOKIE['cookie_consent']) && $_COOKIE['cookie_consent'] === 'accepted'): ?>
<?php include 'metrika.php'; ?>
<?php endif; ?>
</body>
</html>
