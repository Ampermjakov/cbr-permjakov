<?php
require_once __DIR__.'/config.php';

function sendEmail(string $to, string $subject, string $html, string $text = ''): bool {
    if (file_exists(__DIR__.'/vendor/autoload.php')) {
        require_once __DIR__.'/vendor/autoload.php';
    }
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $text ?: strip_tags($html);
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('CBR email error: '.$e->getMessage());
        }
    }
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: ".SMTP_FROM_NAME." <".SMTP_FROM_EMAIL.">\r\n";
    return mail($to, $subject, $html, $headers);
}

function emailHtml(string $title, string $body): string {
    return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
  body{margin:0;padding:0;background:#0a0a0f;font-family:'Helvetica Neue',Arial,sans-serif;}
  .wrap{max-width:560px;margin:40px auto;background:#111118;border:1px solid #1e1e2e;border-radius:12px;overflow:hidden;}
  .hdr{background:linear-gradient(135deg,#f43f5e,#8b5cf6);padding:28px 32px;}
  .hdr h1{margin:0;color:#fff;font-size:20px;font-weight:700;}
  .hdr p{margin:6px 0 0;color:rgba(255,255,255,.7);font-size:13px;}
  .bdy{padding:28px 32px;color:#c4c4d4;font-size:14px;line-height:1.7;}
  .code{background:#0a0a0f;border:1px solid #f43f5e;border-radius:8px;padding:16px 24px;margin:20px 0;text-align:center;font-size:32px;font-weight:700;letter-spacing:10px;color:#f43f5e;font-family:monospace;}
  .btn{display:inline-block;background:linear-gradient(135deg,#f43f5e,#fb7185);color:#fff;text-decoration:none;padding:13px 28px;border-radius:8px;font-weight:700;font-size:14px;margin:16px 0;}
  .ftr{padding:20px 32px;border-top:1px solid #1e1e2e;color:#3d4a62;font-size:12px;}
  a{color:#fb7185;}
</style></head><body>
<div class="wrap">
  <div class="hdr"><h1>ЦБ РФ Мониторинг</h1><p>cbr.permjakov.ru</p></div>
  <div class="bdy">{$body}</div>
  <div class="ftr">© 2026 permjakov.ru · <a href="https://cbr.permjakov.ru">cbr.permjakov.ru</a></div>
</div>
</body></html>
HTML;
}

function sendOtpEmail(string $email, string $code): bool {
    $body = "
        <p>Здравствуйте!</p>
        <p>Ваш код подтверждения для входа в <strong>ЦБ РФ Мониторинг</strong>:</p>
        <div class='code'>{$code}</div>
        <p>Код действителен <strong>10 минут</strong>.</p>
        <p style='color:#3d4a62;font-size:12px;'>Если вы не запрашивали код — проигнорируйте это письмо.</p>
    ";
    return sendEmail($email, '[ЦБ РФ Мониторинг] Код подтверждения: '.$code, emailHtml('Код подтверждения', $body));
}

function sendWarnEmail(string $email, string $url, array $data): bool {
    $name = htmlspecialchars($data['name'] ?? $url);
    $type = htmlspecialchars($data['type'] ?? '');
    $date = htmlspecialchars($data['date_add'] ?? '');
    $body = "
        <p>Здравствуйте!</p>
        <p>🚨 Отслеживаемый сервис был добавлен в предупредительный список ЦБ РФ:</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
          <tr><td style='padding:8px;color:#6b7a99;border-bottom:1px solid #1e1e2e;'>URL</td><td style='padding:8px;color:#e8e8f0;border-bottom:1px solid #1e1e2e;'>".htmlspecialchars($url)."</td></tr>
          <tr><td style='padding:8px;color:#6b7a99;border-bottom:1px solid #1e1e2e;'>Название</td><td style='padding:8px;color:#e8e8f0;border-bottom:1px solid #1e1e2e;'>{$name}</td></tr>
          <tr><td style='padding:8px;color:#6b7a99;border-bottom:1px solid #1e1e2e;'>Тип</td><td style='padding:8px;color:#e8e8f0;border-bottom:1px solid #1e1e2e;'>{$type}</td></tr>
          <tr><td style='padding:8px;color:#6b7a99;'>Дата добавления</td><td style='padding:8px;color:#e8e8f0;'>{$date}</td></tr>
        </table>
        <a class='btn' href='https://cbr.permjakov.ru/?page=dashboard'>Открыть кабинет →</a>
        <p style='color:#3d4a62;font-size:12px;'>Вы получили это письмо, так как подписаны на уведомления.</p>
    ";
    return sendEmail($email, '🚨 [ЦБ РФ] Сервис в стоп-листе: '.parse_url($url, PHP_URL_HOST), emailHtml('Тревога', $body));
}

function sendClearEmail(string $email, string $url): bool {
    $body = "
        <p>Здравствуйте!</p>
        <p>✅ Отслеживаемый сервис <strong>".htmlspecialchars($url)."</strong> был <strong>удалён</strong> из предупредительного списка ЦБ РФ.</p>
        <a class='btn' href='https://cbr.permjakov.ru/?page=dashboard'>Открыть кабинет →</a>
    ";
    return sendEmail($email, '✅ [ЦБ РФ] Сервис убран из стоп-листа: '.parse_url($url, PHP_URL_HOST), emailHtml('Статус изменился', $body));
}
