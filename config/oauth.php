<?php

/**
 * Google OAuth settings for the "Sign up with Google" button.
 *
 * The values are resolved in this order:
 *   1. the GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and GOOGLE_REDIRECT_URI
 *      environment variables,
 *   2. config/oauth.local.php, a file that returns an array with the same keys.
 *
 * config/oauth.local.php is listed in .gitignore, so the credentials never
 * end up in the repository. Copy config/oauth.example.php to get started.
 */

function google_oauth_settings(): array
{
    static $settings = null;

    if ($settings !== null) {
        return $settings;
    }

    $settings = [
        'client_id' => trim((string) getenv('GOOGLE_CLIENT_ID')),
        'client_secret' => trim((string) getenv('GOOGLE_CLIENT_SECRET')),
        'redirect_uri' => trim((string) getenv('GOOGLE_REDIRECT_URI')),
    ];

    $localFile = __DIR__ . '/oauth.local.php';

    if (is_file($localFile)) {
        $local = require $localFile;

        if (is_array($local)) {
            foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
                if ($settings[$key] === '' && isset($local[$key])) {
                    $settings[$key] = trim((string) $local[$key]);
                }
            }
        }
    }

    return $settings;
}

/**
 * The URL Google sends the visitor back to. It has to match one of the
 * authorised redirect URIs of the OAuth client exactly.
 */
function google_oauth_redirect_uri(): string
{
    $settings = google_oauth_settings();

    if ($settings['redirect_uri'] !== '') {
        return $settings['redirect_uri'];
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/auth/google_oauth.php');
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        ? 'https'
        : 'http';

    return $scheme . '://' . $host . $script;
}
