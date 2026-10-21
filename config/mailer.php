<?php

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/mail_template.php';

$autoload = __DIR__ . '/../vendor/autoload.php';

if (is_file($autoload)) {
    require_once $autoload;
}

/**
 * Sends a plain text mail through the configured SMTP server.
 *
 * Returns true when the message was handed over to the server. When no mail
 * server is configured, or when the server refuses the message, false is
 * returned and the reason is written to the PHP error log.
 */
function send_mail(string $to, string $subject, string $body, string $htmlBody = ''): bool
{
    $settings = mail_settings();

    if (!mail_is_configured()) {
        error_log('UniStay mail: no SMTP server configured, see config/mail.example.php.');
        return false;
    }

    if (!class_exists(PHPMailer::class)) {
        error_log('UniStay mail: PHPMailer is missing, run "composer install" in the project folder.');
        return false;
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
        $mail->Timeout = 20;

        $encryption = strtolower($settings['encryption']);

        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption !== 'none') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($settings['from'], $settings['from_name']);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody !== '' ? $htmlBody : $body;
        $mail->AltBody = $body;

        return $mail->send();
    } catch (Throwable $error) {
        error_log('UniStay mail could not be sent: ' . $error->getMessage());
        return false;
    }
}
