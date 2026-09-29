<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cbr.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_name('cbr_ui');
session_start();

$session     = getSession();
$page        = $_GET['page'] ?? '';
$showLogin   = isset($_GET['login']);

if ($page === 'dashboard') {
    require __DIR__ . '/dashboard.php';
    exit;
}

if (isset($_GET['logout'])) {
    if ($session) {
        getDB()->prepare("DELETE FROM cbr_sessions WHERE token=?")->execute([$_COOKIE['cbr_session'] ?? '']);
    }
    setcookie('cbr_session', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: ' . SITE_URL . '/');
    exit;
}

$checkResult = null;
$checkedUrl  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['url'])) {
    $checkedUrl  = trim($_POST['url']);
    if (!preg_match('#^https?://#', $checkedUrl)) $checkedUrl = 'https://' . $checkedUrl;
    $checkResult = checkUrl($checkedUrl);
    logAnalytics('check', $checkedUrl);
}

$cacheAge   = getCacheAge();
$recentCbr  = getRecentCbrEntries(10);
$cbrCount   = getCbrCount();
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ЦБ РФ Стоп-лист — проверка сервисов | permjakov.ru</title>
    <meta name="description" content="Проверка сайтов и сервисов по предупредительному списку Центрального банка России. Отслеживание статуса с уведомлениями.">
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
                <a href="https://pd.permjakov.ru/" class="nav-tool">РНК ПД</a>
                <a href="https://chek.permjakov.ru/" class="nav-tool">РКН Аудит</a>
                <a href="https://rkn.permjakov.ru" class="nav-tool">РКН Блок</a>
                <a href="https://cbr.permjakov.ru" class="nav-tool active">ЦБ Стоп-лист</a>
                <a href="https://whois.permjakov.ru" class="nav-tool">Whois</a>
                <a href="https://monitor.permjakov.ru" class="nav-tool">Мониторинг</a>
            </div>
            <div class="nav-right">
                <?php if ($session): ?>
                    <a href="?page=dashboard" class="nav-tool nav-tool-cab">Кабинет</a>
                    <a href="?logout=1" class="nav-tool" style="color:var(--text-3)">Выйти</a>
                <?php else: ?>
                    <button class="nav-tool nav-tool-login" onclick="openLogin()">Войти</button>
                <?php endif; ?>
            </div>
            <button class="nav-burger" id="navBurger" aria-label="Меню">
                <span></span><span></span><span></span>
            </button>
        </div>
    </nav>

    <div class="container">

        <header class="header">
            <div class="header-badge">
                <span class="badge-dot"></span>
                Официальный список ЦБ РФ<?php if ($cbrCount): ?> · <?= number_format($cbrCount) ?> записей<?php endif; ?>
            </div>
            <h1>ЦБ РФ Стоп-лист</h1>
            <p>Проверка сервисов по предупредительному списку Центрального банка России.<br>Добавьте в отслеживаемые — получайте уведомления об изменениях.</p>
        </header>

        <div class="card ad-1">
            <form method="POST" class="search-form" id="checkForm">
                <input type="text" name="url" class="search-input"
                    placeholder="example.com или https://example.com"
                    value="<?= htmlspecialchars($_POST['url'] ?? '') ?>"
                    autocomplete="off" spellcheck="false" required>
                <button type="submit" class="search-btn" id="checkBtn">Проверить →</button>
            </form>
            <div class="form-hint">
                <span>Примеры:</span>
                <button type="button" class="example-btn" onclick="fillUrl('forex-mmcis.ru')">forex-mmcis.ru</button>
                <button type="button" class="example-btn" onclick="fillUrl('sberbank.ru')">sberbank.ru</button>
                <button type="button" class="example-btn" onclick="fillUrl('permjakov.ru')">permjakov.ru</button>
            </div>
            <?php if ($cacheAge): ?>
                <div class="cache-info">Данные ЦБ РФ обновлены: <?= htmlspecialchars($cacheAge) ?></div>
            <?php endif; ?>
        </div>

        <?php if ($checkResult): ?>
            <?php if ($checkResult['status'] === 'error'): ?>
                <div class="card result-card ad-2">
                    <div class="result-state result-error">
                        <div class="result-icon">⚠️</div>
                        <div>
                            <div class="result-title">Ошибка проверки</div>
                            <div class="result-sub"><?= htmlspecialchars($checkResult['message']) ?></div>
                        </div>
                    </div>
                </div>

            <?php elseif ($checkResult['status'] === 'found'): ?>
                <div class="card result-card ad-2">
                    <div class="result-state result-danger">
                        <div class="result-icon">🚨</div>
                        <div>
                            <div class="result-title">Обнаружен в стоп-листе ЦБ РФ</div>
                            <div class="result-sub"><?= htmlspecialchars(parse_url($checkedUrl, PHP_URL_HOST)) ?> · <?= $checkResult['count'] ?> совпадение(й)</div>
                        </div>
                    </div>
                    <?php foreach (array_slice($checkResult['items'], 0, 3) as $item): ?>
                        <div class="cbr-item cbr-item-danger">
                            <?php foreach ($item as $k => $v): if (!$v) continue; ?>
                                <div class="cbr-row">
                                    <span class="cbr-key"><?= htmlspecialchars($k) ?></span>
                                    <span class="cbr-val"><?= htmlspecialchars((string)$v) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="result-actions">
                        <a href="https://cbr.ru/development/warning-list/" target="_blank" rel="noopener" class="btn-secondary">Официальная страница ЦБ РФ ↗</a>
                        <?php if ($session): ?>
                            <button class="btn-primary" onclick="addTracking('<?= htmlspecialchars($checkedUrl, ENT_QUOTES) ?>')">+ Отслеживать</button>
                        <?php else: ?>
                            <button class="btn-primary" onclick="openTrackModal('<?= htmlspecialchars($checkedUrl, ENT_QUOTES) ?>')">+ Добавить в отслеживаемые</button>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: ?>
                <div class="card result-card ad-2">
                    <div class="result-state result-clean">
                        <div class="result-icon">✅</div>
                        <div>
                            <div class="result-title">Не найден в стоп-листе</div>
                            <div class="result-sub"><?= htmlspecialchars(parse_url($checkedUrl, PHP_URL_HOST)) ?> отсутствует в предупредительном списке ЦБ РФ</div>
                        </div>
                    </div>
                    <div class="result-actions">
                        <?php if ($session): ?>
                            <button class="btn-outline" onclick="addTracking('<?= htmlspecialchars($checkedUrl, ENT_QUOTES) ?>')">+ Отслеживать изменения</button>
                        <?php else: ?>
                            <button class="btn-outline" onclick="openTrackModal('<?= htmlspecialchars($checkedUrl, ENT_QUOTES) ?>')">+ Отслеживать изменения</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($recentCbr): ?>
            <div class="card recent-card ad-3">
                <div class="recent-hdr">
                    <h2 class="recent-title">Последние внесённые в реестр</h2>
                    <span class="recent-sub">10 последних записей</span>
                </div>
                <?php foreach ($recentCbr as $entry): ?>
                    <div class="recent-row">
                        <div class="recent-main">
                            <span class="recent-site"><?= htmlspecialchars($entry['site'] ?: $entry['name']) ?></span>
                            <span class="recent-sign"><?= htmlspecialchars($entry['sign'] ?: $entry['org_type']) ?></span>
                        </div>
                        <span class="recent-date"><?= $entry['dt'] ? htmlspecialchars(date('d.m.Y', strtotime($entry['dt']))) : '—' ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">🔍</div>
                <h3>Актуальные данные</h3>
                <p>Проверка по официальному JSON API ЦБ РФ. Кэш обновляется каждый час.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📡</div>
                <h3>Мониторинг 24/7</h3>
                <p>Добавьте сервис в список — система проверяет его ежедневно автоматически.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🔔</div>
                <h3>Уведомления</h3>
                <p>Email, Telegram или MAX — мгновенное уведомление при изменении статуса.</p>
            </div>
        </div>

        <footer class="footer">
            <p>© <?= date('Y') ?> <a href="https://permjakov.ru">permjakov.ru</a> · Данные: <a href="https://cbr.ru/development/warning-list/" target="_blank" rel="noopener">cbr.ru</a></p>
            <div class="footer-links">
                <a href="https://pd.permjakov.ru/" class="nav-tool">РНК ПД</a>
                <a href="https://chek.permjakov.ru/" class="nav-tool">РКН Аудит</a>
                <a href="https://rkn.permjakov.ru" class="nav-tool">РКН Блок</a>
                <a href="https://cbr.permjakov.ru" class="nav-tool active">ЦБ Стоп-лист</a>
                <a href="https://whois.permjakov.ru" class="nav-tool">Whois</a>
                <a href="https://monitor.permjakov.ru" class="nav-tool">Мониторинг</a>
            </div>
        </footer>
    </div>

    <div class="nav-mobile" id="navMobile">
        <button class="nav-mobile-close" id="navMobileClose" aria-label="Закрыть">✕</button>
        <a href="https://pd.permjakov.ru/" class="nav-tool">РНК ПД</a>
        <a href="https://chek.permjakov.ru/" class="nav-tool">РКН Аудит</a>
        <a href="https://rkn.permjakov.ru" class="nav-tool">РКН Блок</a>
        <a href="https://cbr.permjakov.ru" class="nav-tool active">ЦБ Стоп-лист</a>
        <a href="https://whois.permjakov.ru" class="nav-tool">Whois</a>
        <a href="https://monitor.permjakov.ru" class="nav-tool">Мониторинг</a>
    </div>

    <div class="modal-overlay" id="trackModal">
        <div class="modal-box">
            <div class="modal-hdr">
                <span class="modal-ttl">Добавить в отслеживаемые</span>
                <button class="modal-close" onclick="closeModal('trackModal')">✕</button>
            </div>
            <div class="modal-bdy" id="trackModalBody">
                <p class="modal-desc">Введите email — мы уведомим вас при изменении статуса сервиса в списке ЦБ РФ.</p>
                <div id="trackUrl" class="track-url-preview"></div>
                <div id="stepEmail">
                    <div class="f-group">
                        <label class="f-label">Email</label>
                        <input type="email" id="trackEmail" class="f-input" placeholder="you@example.com" autocomplete="email">
                    </div>
                    <button class="btn-primary btn-full" onclick="sendOtp()">Получить код →</button>
                    <div class="modal-note">На email придёт 6-значный код подтверждения</div>
                </div>
                <div id="stepCode" style="display:none">
                    <div class="f-group">
                        <label class="f-label">Код из письма</label>
                        <input type="text" id="trackCode" class="f-input f-mono" placeholder="000000" maxlength="6" autocomplete="one-time-code">
                    </div>
                    <button class="btn-primary btn-full" onclick="verifyOtp()">Войти и добавить →</button>
                    <button class="btn-link" onclick="backToEmail()">← Изменить email</button>
                </div>
                <div id="stepDone" style="display:none">
                    <div class="step-done">✅ Добавлено в отслеживаемые!</div>
                    <a href="?page=dashboard" class="btn-primary btn-full">Открыть кабинет →</a>
                </div>
                <div id="modalErr" class="modal-err" style="display:none"></div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="loginModal">
        <div class="modal-box modal-box-sm">
            <div class="modal-hdr">
                <span class="modal-ttl">Войти</span>
                <button class="modal-close" onclick="closeModal('loginModal')">✕</button>
            </div>
            <div class="modal-bdy">
                <p class="modal-desc">Введите email — вышлем код для входа</p>
                <div id="loginStepEmail">
                    <div class="f-group">
                        <label class="f-label">Email</label>
                        <input type="email" id="loginEmail" class="f-input" placeholder="you@example.com" autocomplete="email">
                    </div>
                    <button class="btn-primary btn-full" onclick="loginSendOtp()">Получить код →</button>
                </div>
                <div id="loginStepCode" style="display:none">
                    <div class="f-group">
                        <label class="f-label">Код из письма</label>
                        <input type="text" id="loginCode" class="f-input f-mono" placeholder="000000" maxlength="6" autocomplete="one-time-code">
                    </div>
                    <button class="btn-primary btn-full" onclick="loginVerify()">Войти →</button>
                    <button class="btn-link" onclick="loginBackToEmail()">← Изменить email</button>
                </div>
                <div id="loginErr" class="modal-err" style="display:none"></div>
            </div>
        </div>
    </div>

    <script>
        var _trackUrl = '';

        function fillUrl(v) {
            document.querySelector('.search-input').value = v;
            document.querySelector('.search-input').focus();
        }

        document.getElementById('checkForm').addEventListener('submit', function() {
            var btn = document.getElementById('checkBtn');
            btn.disabled = true;
            btn.textContent = 'Проверяем...';
        });

        function openTrackModal(url) {
            _trackUrl = url;
            document.getElementById('trackUrl').textContent = url;
            document.getElementById('stepEmail').style.display = '';
            document.getElementById('stepCode').style.display = 'none';
            document.getElementById('stepDone').style.display = 'none';
            document.getElementById('modalErr').style.display = 'none';
            document.getElementById('trackModal').classList.add('open');
            setTimeout(function() {
                document.getElementById('trackEmail').focus();
            }, 100);
        }

        function openLogin() {
            document.getElementById('loginStepEmail').style.display = '';
            document.getElementById('loginStepCode').style.display = 'none';
            document.getElementById('loginErr').style.display = 'none';
            document.getElementById('loginModal').classList.add('open');
            setTimeout(function() {
                document.getElementById('loginEmail').focus();
            }, 100);
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('open');
        }

        document.querySelectorAll('.modal-overlay').forEach(function(el) {
            el.addEventListener('click', function(e) {
                if (e.target === el) el.classList.remove('open');
            });
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(function(el) {
                el.classList.remove('open');
            });
        });

        async function sendOtp() {
            var email = document.getElementById('trackEmail').value.trim();
            if (!email) {
                showErr('modalErr', 'Введите email');
                return;
            }
            setLoading('trackModal', true);
            var r = await api({
                action: 'send_otp',
                email: email
            });
            setLoading('trackModal', false);
            if (r.ok) {
                document.getElementById('stepEmail').style.display = 'none';
                document.getElementById('stepCode').style.display = '';
                document.getElementById('trackCode').focus();
            } else showErr('modalErr', r.error);
        }

        async function verifyOtp() {
            var email = document.getElementById('trackEmail').value.trim();
            var code = document.getElementById('trackCode').value.trim();
            if (!code) {
                showErr('modalErr', 'Введите код');
                return;
            }
            setLoading('trackModal', true);
            var r = await api({
                action: 'verify_otp',
                email: email,
                code: code
            });
            setLoading('trackModal', false);
            if (r.ok) {
                var r2 = await api({
                    action: 'add_tracking',
                    url: _trackUrl
                });
                if (r2.ok) {
                    document.getElementById('stepCode').style.display = 'none';
                    document.getElementById('stepDone').style.display = '';
                } else if (r2.error === 'Уже отслеживается') {
                    document.getElementById('stepCode').style.display = 'none';
                    document.getElementById('stepDone').style.display = '';
                } else {
                    showErr('modalErr', r2.error || 'Ошибка добавления');
                }
            } else showErr('modalErr', r.error);
        }

        function backToEmail() {
            document.getElementById('stepEmail').style.display = '';
            document.getElementById('stepCode').style.display = 'none';
            document.getElementById('modalErr').style.display = 'none';
        }

        async function loginSendOtp() {
            var email = document.getElementById('loginEmail').value.trim();
            if (!email) {
                showErr('loginErr', 'Введите email');
                return;
            }
            var r = await api({
                action: 'send_otp',
                email: email
            });
            if (r.ok) {
                document.getElementById('loginStepEmail').style.display = 'none';
                document.getElementById('loginStepCode').style.display = '';
                document.getElementById('loginCode').focus();
            } else showErr('loginErr', r.error);
        }

        async function loginVerify() {
            var email = document.getElementById('loginEmail').value.trim();
            var code = document.getElementById('loginCode').value.trim();
            var r = await api({
                action: 'verify_otp',
                email: email,
                code: code
            });
            if (r.ok && r.redirect) {
                window.location.href = r.redirect;
            } else showErr('loginErr', r.error);
        }

        function loginBackToEmail() {
            document.getElementById('loginStepEmail').style.display = '';
            document.getElementById('loginStepCode').style.display = 'none';
            document.getElementById('loginErr').style.display = 'none';
        }

        async function addTracking(url) {
            var r = await api({
                action: 'add_tracking',
                url: url
            });
            if (r.ok) {
                alert('Добавлено в отслеживаемые!');
            } else if (r.error === 'Уже отслеживается') {
                alert('Уже в списке отслеживаемых.');
            } else alert(r.error || 'Ошибка');
        }

        async function api(data) {
            try {
                var fd = new FormData();
                Object.keys(data).forEach(function(k) {
                    fd.append(k, data[k]);
                });
                var r = await fetch('api.php', {
                    method: 'POST',
                    body: fd
                });
                return await r.json();
            } catch (e) {
                return {
                    error: 'Сетевая ошибка'
                };
            }
        }

        function showErr(id, msg) {
            var el = document.getElementById(id);
            el.textContent = msg;
            el.style.display = '';
        }

        function setLoading(modalId, on) {
            document.querySelectorAll('#' + modalId + ' .btn-primary').forEach(function(b) {
                b.disabled = on;
                if (on) b.dataset.orig = b.textContent, b.textContent = 'Загрузка...';
                else if (b.dataset.orig) b.textContent = b.dataset.orig;
            });
        }

        (function() {
            var burger = document.getElementById('navBurger');
            var mobile = document.getElementById('navMobile');
            var closeBtn = document.getElementById('navMobileClose');
            if (!burger || !mobile) return;

            function openNav() {
                mobile.classList.add('open');
                burger.classList.add('open');
                document.body.style.overflow = 'hidden';
            }

            function closeNav() {
                mobile.classList.remove('open');
                burger.classList.remove('open');
                document.body.style.overflow = '';
            }
            burger.addEventListener('click', function() {
                mobile.classList.contains('open') ? closeNav() : openNav();
            });
            if (closeBtn) closeBtn.addEventListener('click', closeNav);
            mobile.querySelectorAll('a').forEach(function(a) {
                a.addEventListener('click', closeNav);
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeNav();
            });
        })();

        <?php if ($showLogin || isset($_GET['login'])): ?>
            window.addEventListener('load', openLogin);
        <?php endif; ?>
    </script>

    <?php if (isset($_COOKIE['cookie_consent']) && $_COOKIE['cookie_consent'] === 'accepted'): ?>
        <?php include 'metrika.php'; ?>
    <?php endif; ?>
    <?php if (!isset($_COOKIE['cookie_consent'])): ?>
        <?php include 'cookie-banner.php'; ?>
    <?php endif; ?>
</body>

</html>