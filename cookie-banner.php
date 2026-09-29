<!-- Cookie Banner -->
<div class="cookie-bar" id="cookieBar" role="dialog" aria-label="Уведомление о cookies">
    <div class="cookie-bar-inner">
        <div class="cookie-bar-icon">🍪</div>
        <div class="cookie-bar-text">
            <strong>Мы используем файлы cookie</strong>
            Мы используем аналитические cookie для улучшения работы сервиса. Нажимая «Принять», вы соглашаетесь на их использование.
            <a href="#" onclick="openPrivacyModal();return false;">Политика конфиденциальности</a>
        </div>
        <div class="cookie-bar-actions">
            <button class="cookie-btn cookie-btn-decline" id="cookieDecline">Отклонить</button>
            <button class="cookie-btn cookie-btn-accept" id="cookieAccept">Принять</button>
        </div>
    </div>
</div>
<script>
(function() {
    var bar = document.getElementById('cookieBar');
    var YEAR = 60 * 60 * 24 * 365;
    function setCookie(value) {
        document.cookie = 'cookie_consent=' + value + '; max-age=' + YEAR + '; path=/; SameSite=Lax';
    }
    function hideCookieBar() {
        bar.classList.remove('visible');
        bar.addEventListener('transitionend', function() { bar.remove(); }, { once: true });
    }
    document.getElementById('cookieAccept').addEventListener('click', function() { setCookie('accepted'); hideCookieBar(); });
    document.getElementById('cookieDecline').addEventListener('click', function() { setCookie('declined'); hideCookieBar(); });
    setTimeout(function() { bar.classList.add('visible'); }, 800);
})();
</script>