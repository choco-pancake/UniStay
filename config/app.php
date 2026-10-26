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
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        ? 'https'
        : 'http';

    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $base = str_replace('\\', '/', dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/auth/login.php'))));
    $base = trim($base, '/');
    $base = $base === '' ? '' : '/' . $base;

    return $scheme . '://' . $host . $base . '/' . ltrim($path, '/');
}
