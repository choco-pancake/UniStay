<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
start_app_session();

header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$storedPath = str_replace('\\', '/', trim((string)($_GET['path'] ?? '')));
$path = preg_replace('#^(?:\./|\.\./)+#', '', $storedPath);
if ($path === '' || strlen($storedPath) > 512 || str_contains($path, '..') || !preg_match('#^(?:tenant/)?uploads/#', $path)) {
    http_response_code(400);
    exit('Invalid document path.');
}

$pdo = database();
$viewer = session_user();
$isAdmin = ($viewer['role'] ?? '') === 'admin' || !empty($_SESSION['admin']);
$allowed = false;
$source = '';

$stmt = $pdo->prepare('SELECT tenant_id FROM tenants WHERE school_id = ? OR gov_id = ?');
$stmt->execute([$storedPath, $storedPath]);
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tenantId) {
    if ($isAdmin || (($viewer['role'] ?? '') === 'tenant' && (string)$viewer['id'] === (string)$tenantId)) {
        $allowed = true;
        $source = 'tenant';
        break;
    }
    if (($viewer['role'] ?? '') === 'landlord') {
        $booking = $pdo->prepare('SELECT 1 FROM bookings b JOIN rooms r ON r.id = b.room_id JOIN dorms d ON d.id = r.dorm_id WHERE b.tenant_id = ? AND d.landlord_id = ? LIMIT 1');
        $booking->execute([$tenantId, (string)$viewer['id']]);
        if ($booking->fetchColumn()) { $allowed = true; $source = 'tenant'; break; }
    }
}

if (!$allowed) {
    $stmt = $pdo->prepare('SELECT landlord_id FROM landlords WHERE gov_id = ?');
    $stmt->execute([$storedPath]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $landlordId) {
        if ($isAdmin || (($viewer['role'] ?? '') === 'landlord' && (string)$viewer['id'] === (string)$landlordId)) {
            $allowed = true;
            $source = 'root';
            break;
        }
    }
}

if (!$allowed) {
    $stmt = $pdo->prepare('SELECT landlord_id FROM dorms WHERE proof_of_ownership = ? OR business_permit = ?');
    $stmt->execute([$storedPath, $storedPath]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $landlordId) {
        if ($isAdmin || (($viewer['role'] ?? '') === 'landlord' && (string)$viewer['id'] === (string)$landlordId)) {
            $allowed = true;
            $source = 'root';
            break;
        }
    }
}

if (!$allowed) {
    http_response_code(403);
    exit('Document access denied.');
}

$root = dirname(__DIR__);
$candidates = $source === 'tenant' && str_starts_with($path, 'uploads/')
    ? [$root . '/tenant/' . $path]
    : [$root . '/' . $path];
$file = false;
foreach ($candidates as $candidate) {
    $resolved = realpath($candidate);
    if ($resolved === false || !is_file($resolved)) continue;
    $allowedRoots = [$root . '/uploads', $root . '/tenant/uploads'];
    foreach ($allowedRoots as $allowedRoot) {
        $resolvedRoot = realpath($allowedRoot);
        if ($resolvedRoot !== false && str_starts_with($resolved, $resolvedRoot . DIRECTORY_SEPARATOR)) {
            $file = $resolved;
            break 2;
        }
    }
}
if ($file === false) {
    http_response_code(404);
    exit('Document not found.');
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
    http_response_code(415);
    exit('Unsupported document type.');
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
$downloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file));
header('Content-Disposition: inline; filename="' . $downloadName . '"');
readfile($file);
