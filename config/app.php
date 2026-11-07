<?php

/**
 * Helpers for the public address of the app.
 */

/**
 * Builds the absolute address of a page of the app, for links that are sent
 * by mail.
 *
 * The host and the folder the app lives in are taken from the current
 * request, so the link works both on http://localhost/unistay and on a
 * deployed domain.
 */
function app_url(string $path = '/'): string
{
    // Set APP_URL in production (for example https://unistay.example) so links
    // built by command-line tools and cron jobs are always absolute and correct.
    $configured = trim((string) getenv('APP_URL'));

    if ($configured !== '') {
        return rtrim($configured, '/') . '/' . ltrim($path, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        ? 'https'
        : 'http';

    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/auth/login.php'));

    if (PHP_SAPI === 'cli') {
        // Command-line scripts get a file path (or "Standard input code") in
        // SCRIPT_NAME, which is not a URL path. Fall back to the project folder
        // name; set APP_URL when running this outside localhost.
        $base = '/' . basename(str_replace('\\', '/', dirname(__DIR__)));
    } else {
        $base = str_replace('\\', '/', dirname(dirname($scriptName)));
        $base = trim($base, '/');
        $base = $base === '' ? '' : '/' . $base;
    }

    return $scheme . '://' . $host . $base . '/' . ltrim($path, '/');
}

/** Returns the deployment-aware root path, for example /unistay. */
function app_base_path(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/unistay/index.php'));
    $directory = rtrim(dirname($script), '/');
    if (preg_match('#/(?:auth|tenant|landlord|admin|api)$#', $directory)) {
        $directory = rtrim(dirname($directory), '/');
    }
    return in_array($directory, ['/', '.', '\\'], true) ? '' : $directory;
}

function app_asset_path(string $path): string
{
    return app_base_path() . '/' . ltrim($path, '/');
}
