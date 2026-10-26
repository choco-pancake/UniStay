<?php

/** Resolve stored ID paths from the admin page, including tenant-relative uploads. */
function resolve_doc_web_url(?string $path, string $role = 'landlord'): string
{
    $path = trim(str_replace('\\', '/', (string)$path));
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;

    $root = dirname(__DIR__);
    $roots = [$root . '/tenant/uploads', $root . '/uploads'];
    $relative = preg_replace('#^(?:\./|\.\./)+#', '', ltrim($path, '/'));
    $project = basename($root) . '/';
    if (str_starts_with($relative, $project)) $relative = substr($relative, strlen($project));
    $candidates = [];
    if (str_starts_with($relative, 'tenant/uploads/')) {
        $candidates[] = $root . '/' . $relative;
    } elseif (str_starts_with($relative, 'uploads/')) {
        if ($role === 'tenant') $candidates[] = $root . '/tenant/' . $relative;
        $candidates[] = $root . '/' . $relative;
    } elseif (!str_contains($relative, '/') && !str_contains($relative, ':')) {
        if ($role === 'tenant') $candidates[] = $roots[0] . '/' . $relative;
        $candidates[] = $roots[1] . '/' . $relative;
    }
    // Some older records contain an absolute filesystem path.
    if (str_starts_with($path, '/') || preg_match('#^[a-z]:/#i', $path)) $candidates[] = $path;
    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) continue;
        foreach ($roots as $allowed) {
            $allowed = realpath($allowed);
            if ($allowed !== false && str_starts_with($resolved, $allowed . DIRECTORY_SEPARATOR)) {
                $webPath = str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, strlen(realpath($root)) + 1));
                return '../' . implode('/', array_map('rawurlencode', explode('/', $webPath)));
            }
        }
    }
    return '';
}
