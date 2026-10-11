<?php
// Получение данных с государственных сайтов обычным HTTP-запросом (curl) — без запуска внешних программ.
// Раньше для этого запускался Node.js с безголовым браузером; теперь не нужен ни он, ни proc_open/shell_exec.
//
// Параметры $opt:
//   cafile  — файл с цепочкой доверенных сертификатов (российский УЦ Минцифры; в системном хранилище нет
//             нужного промежуточного); если не указан — системное хранилище;
//   proxy   — адрес прокси (например socks5h://127.0.0.1:1090), если сайт недоступен с адреса сервера;
//   method, body, headers — для POST-запросов;
//   timeout — общее время запроса, секунды (по умолчанию 240).
// Возвращает ['body' => строка] или ['error' => текст, 'code' => HTTP-код или 0].
function httpFetch(string $url, array $opt = []): array {
    if (!function_exists('curl_init')) return ['error' => 'Не установлено расширение curl', 'code' => 0];

    $ch = curl_init($url);
    $headers = array_merge([
        'Accept: */*',
        'Accept-Language: ru-RU,ru;q=0.9',
    ], $opt['headers'] ?? []);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => (int) ($opt['timeout'] ?? 240),
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    if (!empty($opt['cafile']) && is_file($opt['cafile'])) curl_setopt($ch, CURLOPT_CAINFO, $opt['cafile']);
    if (!empty($opt['proxy'])) curl_setopt($ch, CURLOPT_PROXY, $opt['proxy']);
    if (($opt['method'] ?? 'GET') === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['body'] ?? '');
    }

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) return ['error' => 'Сетевая ошибка: ' . $err, 'code' => 0];
    if ($code < 200 || $code >= 300) return ['error' => 'Ответ сервера ' . $code, 'code' => $code];
    if ($body === '') return ['error' => 'Пустой ответ', 'code' => $code];

    return ['body' => $body, 'code' => $code];
}
