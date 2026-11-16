<?php
// Contact-form email. No mail library needed: with a sending login saved in
// Admin → Settings (e.g. a Gmail address + app password) it sends through that
// account's SMTP server using curl. Without one it falls back to PHP's mail(),
// which only works if the server has a mail program set up.

const CVG_CONTACT_TO = 'ansondparker@gmail.com';   // default; change in Admin → Settings

/** Where contact-form messages are emailed. */
function contact_to(): string
{
    return setting('contact_form_to', CVG_CONTACT_TO);
}

function smtp_ready(): bool
{
    return setting('smtp_user') !== '' && setting('smtp_password') !== '';
}

/** Strip anything that could break out of an email header. */
function header_safe(string $s): string
{
    return trim(preg_replace('/[\r\n\t]+/', ' ', $s));
}

/**
 * Send a plain-text email. $reply_to is the visitor, so hitting Reply in the inbox
 * goes straight to them. Throws RuntimeException with a readable reason on failure.
 */
function send_mail(string $to, string $subject, string $body, string $reply_to = '', string $reply_name = ''): void
{
    $from = smtp_ready() ? setting('smtp_user') : 'website@' . preg_replace('/^www\./', '', parse_url(site_url(), PHP_URL_HOST) ?: 'cvilleglass.org');
    $subject = '=?UTF-8?B?' . base64_encode(header_safe($subject)) . '?=';
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $headers = [
        'From: =?UTF-8?B?' . base64_encode('Charlottesville Glass website') . '?= <' . $from . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    if ($reply_to !== '' && filter_var($reply_to, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . ($reply_name !== '' ? '=?UTF-8?B?' . base64_encode(header_safe($reply_name)) . '?= ' : '') . '<' . $reply_to . '>';
    }

    if (!smtp_ready()) {
        if (!function_exists('mail') || !@mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $from)) {
            throw new RuntimeException('No sending login is saved in Settings, and the server could not send mail on its own.');
        }
        return;
    }

    $message = implode("\r\n", array_merge($headers, [
        'To: <' . $to . '>',
        'Subject: ' . $subject,
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($from, '@'), 1) . '>',
    ])) . "\r\n\r\n" . str_replace("\n", "\r\n", $body) . "\r\n";

    $port = (int)setting('smtp_port', '465');
    $host = setting('smtp_host', 'smtp.gmail.com');
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $message);
    rewind($stream);
    $ch = curl_init(($port === 465 ? 'smtps://' : 'smtp://') . $host . ':' . $port);
    curl_setopt_array($ch, [
        CURLOPT_USE_SSL => CURLUSESSL_ALL,
        CURLOPT_USERNAME => setting('smtp_user'),
        CURLOPT_PASSWORD => setting('smtp_password'),
        CURLOPT_MAIL_FROM => '<' . $from . '>',
        CURLOPT_MAIL_RCPT => ['<' . $to . '>'],
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $stream,
        CURLOPT_INFILESIZE => strlen($message),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $ok = curl_exec($ch);
    $error = curl_error($ch);
    fclose($stream);
    if ($ok === false) throw new RuntimeException('Email could not be sent: ' . $error);
}
