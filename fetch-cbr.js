// Получает JSON со стоп-листом ЦБ РФ через headless-браузер, т.к. cbr.ru
// закрыт защитой DDoS-Guard (JS-проверка), которую file_get_contents/curl пройти не могут.
// stdout: сырой JSON при успехе (exit 0). При ошибке — ничего в stdout, диагностика в stderr (exit 1).

const { chromium } = require('playwright');
const fs = require('fs');

const URL = process.argv[2];
const OUT_FILE = process.argv[3];
const TIMEOUT_MS = 30000;
const HARD_DEADLINE_MS = 180000;

if (!URL || !OUT_FILE) {
    console.error('Usage: node fetch-cbr.js <url> <output-file>');
    process.exit(1);
}

let browser;

// page.goto() уважает timeout, а вот resp.text() — нет: если cbr.ru придушит
// отдачу байт до нуля (не оборвав соединение), скрипт зависнет навсегда.
// Общий watchdog гарантирует, что процесс в любом случае завершится.
const watchdog = setTimeout(() => {
    console.error('fetch-cbr error: hard deadline exceeded (' + HARD_DEADLINE_MS + 'ms)');
    try { browser?.process()?.kill('SIGKILL'); } catch (_) {}
    process.exit(1);
}, HARD_DEADLINE_MS);

(async () => {
    try {
        browser = await chromium.launch({ headless: true });
        const context = await browser.newContext({
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            locale: 'ru-RU',
        });
        const page = await context.newPage();

        const resp = await page.goto(URL, { waitUntil: 'domcontentloaded', timeout: TIMEOUT_MS });
        if (!resp) throw new Error('no response');
        if (!resp.ok()) throw new Error('http status ' + resp.status());

        // Читаем сырое тело финального ответа напрямую из сети — не через DOM
        // (рендер многомегабайтного JSON в браузерном JSON-вьюере слишком дорог/медленен).
        const text = await resp.text();
        const trimmed = text.trim();

        if (!(trimmed.startsWith('{') || trimmed.startsWith('['))) {
            throw new Error('response is not JSON (likely still a DDoS-Guard challenge page)');
        }

        JSON.parse(trimmed); // валидируем перед записью
        fs.writeFileSync(OUT_FILE, trimmed);
        clearTimeout(watchdog);
        await browser.close();
        process.exit(0);
    } catch (e) {
        clearTimeout(watchdog);
        console.error('fetch-cbr error: ' + e.message);
        if (browser) await browser.close();
        process.exit(1);
    }
})();
