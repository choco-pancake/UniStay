<?php

/**
 * SMTP settings for the outgoing mail of the app (password reset links).
 *
 * The values are resolved in this order:
 *   1. the SMTP_HOST, SMTP_PORT, SMTP_ENCRYPTION, SMTP_USERNAME,
 *      SMTP_PASSWORD, SMTP_FROM and SMTP_FROM_NAME environment variables,
 *   2. config/mail.local.php, a file that returns an array with the same keys.
 *
 * config/mail.local.php is listed in .gitignore, so the credentials never end
 * up in the repository.
 */

function mail_settings(): array
{
    static $settings = null;

    if ($settings !== null) {
        return $settings;
    }

    $settings = [
        'host' => trim((string) getenv('SMTP_HOST')),
        'port' => (int) (getenv('SMTP_PORT') ?: 587),
        'encryption' => trim((string) getenv('SMTP_ENCRYPTION')) ?: 'tls',
        'username' => trim((string) getenv('SMTP_USERNAME')),
        'password' => trim((string) getenv('SMTP_PASSWORD')),
        'from' => trim((string) getenv('SMTP_FROM')),
        'from_name' => trim((string) getenv('SMTP_FROM_NAME')) ?: 'UniStay',
        'admin_recipient' => trim((string) getenv('SMTP_ADMIN_RECIPIENT')),
    ];

    $localFile = __DIR__ . '/mail.local.php';

    if (is_file($localFile)) {
        $local = require $localFile;

        if (is_array($local)) {
            foreach (array_keys($settings) as $key) {
                if ($settings[$key] === '' && isset($local[$key])) {
                    $settings[$key] = trim((string) $local[$key]);
                }
            }
        }
    }

    if ($settings['from'] === '') {
        $settings['from'] = $settings['username'];
    }
    if ($settings['admin_recipient'] === '') {
        $settings['admin_recipient'] = 'unistaysads@gmail.com';
    }

    // Google shows an app password in groups of four, the spaces are not part of it.
    $settings['password'] = preg_replace('/\s+/', '', $settings['password']) ?? '';

    return $settings;
}

/**
 * True when a mail server is configured and the app can send mail.
 */
function mail_is_configured(): bool
{
    $settings = mail_settings();

    return $settings['host'] !== ''
        && $settings['username'] !== ''
        && $settings['password'] !== '';
}

/**
 * Writes the settings to config/mail.local.php, the file that is kept out of
 * the repository. Returns false when the file cannot be written.
 */
function mail_save_settings(array $values): bool
{
    $host = trim((string) ($values['host'] ?? ''));
    $port = (int) ($values['port'] ?? 587);
    $encryption = trim((string) ($values['encryption'] ?? 'tls'));
    $username = trim((string) ($values['username'] ?? ''));
    $password = preg_replace('/\s+/', '', (string) ($values['password'] ?? '')) ?? '';
    $from = trim((string) ($values['from'] ?? ''));
    $fromName = trim((string) ($values['from_name'] ?? 'UniStay'));
    $adminRecipient = trim((string) ($values['admin_recipient'] ?? 'unistaysads@gmail.com'));

    if ($host === '' || $username === '' || $password === '' || !filter_var($adminRecipient, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if ($from === '') {
        $from = $username;
    }

    $contents = "<?php\n\n"
        . "/**\n"
        . " * Local SMTP credentials, this file is kept out of the repository.\n"
        . " */\n\n"
        . "return [\n"
        . "    'host' => '" . addslashes($host) . "',\n"
        . "    'port' => " . $port . ",\n"
        . "    'encryption' => '" . addslashes($encryption) . "',\n"
        . "    'username' => '" . addslashes($username) . "',\n"
        . "    'password' => '" . addslashes($password) . "',\n"
        . "    'from' => '" . addslashes($from) . "',\n"
        . "    'from_name' => '" . addslashes($fromName) . "',\n"
        . "    'admin_recipient' => '" . addslashes($adminRecipient) . "',\n"
        . "];\n";

    return file_put_contents(__DIR__ . '/mail.local.php', $contents) !== false;
}
