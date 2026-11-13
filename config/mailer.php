<?php

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/mail_template.php';

$autoload = __DIR__ . '/../vendor/autoload.php';

if (is_file($autoload)) {
    require_once $autoload;
}

/**
 * Removes line breaks from a header value so nothing can inject extra headers.
 */
function mail_sanitize_header(string $value): string
{
    return trim(str_replace(["\r", "\n"], '', $value));
}

/**
 * Sends a message through the configured SMTP server.
 *
 * @return array{ok:bool,id:string,error:string}
 */
function mail_send_smtp(string $to, string $subject, string $body, string $htmlBody, array $headers, array $settings): array
{
    if (!class_exists(PHPMailer::class)) {
        return [
            'ok' => false,
            'id' => '',
            'error' => 'PHPMailer is missing, run "composer install" in the project folder.',
        ];
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $settings['host'];
        $mail->Port = $settings['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $settings['username'];
        $mail->Password = $settings['password'];
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 8;
        $fromDomain = substr(strrchr((string)$settings['from'], '@') ?: '', 1);
        $mail->Hostname = $fromDomain !== '' ? $fromDomain : (string)$settings['host'];
        $mail->Sender = $settings['from'];
        $mail->addReplyTo($settings['from'], $settings['from_name']);

        $encryption = strtolower($settings['encryption']);

        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption !== 'none') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($settings['from'], $settings['from_name']);
        $mail->addAddress(mail_sanitize_header($to));
        $mail->Subject = mail_sanitize_header($subject);

        if ($htmlBody !== '') {
            $mail->isHTML(true);
            $mail->Body = $htmlBody;
            $mail->AltBody = $body;

            $logoPath = realpath(__DIR__ . '/../assets/images/logo.png') ?: realpath(__DIR__ . '/../assets/images/tenant/logo.png');
            if ($logoPath && is_file($logoPath)) {
                $mail->addEmbeddedImage($logoPath, 'unistay_logo', 'logo.png', 'base64', 'image/png');
            }
        } else {
            $mail->Body = $body;
        }

        foreach ($headers as $name => $value) {
            $name = mail_sanitize_header((string) $name);
            if ($name === '') {
                continue;
            }
            $mail->addCustomHeader($name, mail_sanitize_header((string) $value));
        }

        $mail->send();

        return [
            'ok' => true,
            'id' => (string) $mail->getLastMessageID(),
            'error' => '',
        ];
    } catch (Throwable $error) {
        $message = trim($error->getMessage());
        $detail = trim(preg_replace('/\s+/', ' ', (string) $mail->ErrorInfo) ?? '');
        $detail = trim(str_ireplace('SMTP server error: QUIT command failed', '', $detail));

        if ($detail !== '') {
            $message = $detail;
        }

        if (stripos($message, 'authenticate') !== false || stripos($message, '535') !== false) {
            $message .= ' Gmail needs a 16-character App Password (myaccount.google.com/apppasswords), not the normal account password.';
        }

        return [
            'ok' => false,
            'id' => '',
            'error' => $message,
        ];
    }
}

/**
 * Writes a message to storage/mail as an .eml file.
 *
 * This is the development fallback used when no SMTP server is configured yet,
 * so the subscriber flow can be tested on a bare XAMPP install.
 *
 * @return string Path of the written file, or an empty string on failure.
 */
function mail_store_eml(string $to, string $subject, string $body, string $htmlBody, array $headers, array $settings): string
{
    $dir = __DIR__ . '/../storage/mail';

    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return '';
    }

    $guard = $dir . '/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents(
            $guard,
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n"
        );
    }

    $from = $settings['from'] !== '' ? $settings['from'] : 'no-reply@unistay.local';
    $to = mail_sanitize_header($to);
    $boundary = 'unistay-' . bin2hex(random_bytes(12));

    $lines = [
        'To: ' . $to,
        'From: ' . mail_sanitize_header($settings['from_name']) . ' <' . $from . '>',
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'Date: ' . date('r'),
        'MIME-Version: 1.0',
    ];

    foreach ($headers as $name => $value) {
        $name = mail_sanitize_header((string) $name);
        if ($name !== '') {
            $lines[] = $name . ': ' . mail_sanitize_header((string) $value);
        }
    }

    if ($htmlBody !== '') {
        $lines[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $lines[] = '';
        $lines[] = '--' . $boundary;
        $lines[] = 'Content-Type: text/plain; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: 8bit';
        $lines[] = '';
        $lines[] = $body;
        $lines[] = '';
        $lines[] = '--' . $boundary;
        $lines[] = 'Content-Type: text/html; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: 8bit';
        $lines[] = '';
        $lines[] = $htmlBody;
        $lines[] = '';
        $lines[] = '--' . $boundary . '--';
    } else {
        $lines[] = 'Content-Type: text/plain; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: 8bit';
        $lines[] = '';
        $lines[] = $body;
    }

    $file = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
    $contents = implode("\r\n", $lines) . "\r\n";

    return @file_put_contents($file, $contents) !== false ? $file : '';
}

/**
 * Sends a plain text mail through the configured SMTP server.
 *
 * Returns true when the message was handed over to the server. When no mail
 * server is configured, or when the server refuses the message, false is
 * returned and the reason is written to the PHP error log.
 */
function send_mail(string $to, string $subject, string $body, string $htmlBody = '', array $headers = []): bool
{
    $settings = mail_settings();

    if (!mail_is_configured()) {
        error_log('UniStay mail: no SMTP server configured, see config/mail.example.php.');
        return false;
    }

    $result = mail_send_smtp($to, $subject, $body, $htmlBody, $headers, $settings);

    if (!$result['ok']) {
        error_log('UniStay mail could not be sent: ' . $result['error']);
        return false;
    }

    return true;
}

/**
 * Sends a message, or stores it as an .eml file when SMTP is not configured.
 *
 * The newsletter queue uses this so that the whole subscribe/confirm/unsubscribe
 * flow can be exercised locally before the real mail server is set up.
 *
 * @return array{ok:bool,transport:string,id:string,error:string}
 */
function mail_deliver_or_store(string $to, string $subject, string $body, string $htmlBody = '', array $headers = [], ?string &$error = null): array
{
    $error = '';
    $settings = mail_settings();

    if (mail_is_configured()) {
        $result = mail_send_smtp($to, $subject, $body, $htmlBody, $headers, $settings);
        $error = $result['error'];

        return [
            'ok' => $result['ok'],
            'transport' => 'smtp',
            'id' => $result['id'],
            'error' => $result['error'],
        ];
    }

    $stored = mail_store_eml($to, $subject, $body, $htmlBody, $headers, $settings);

    if ($stored !== '') {
        return [
            'ok' => true,
            'transport' => 'file',
            'id' => 'file:' . basename($stored),
            'error' => '',
        ];
    }

    $error = 'No SMTP server is configured and storage/mail is not writable.';

    return [
        'ok' => false,
        'transport' => 'none',
        'id' => '',
        'error' => $error,
    ];
}
