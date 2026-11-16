<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/landlord_applications.php';

start_app_session();
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

$pdo = database();
$applicationId = trim((string)($_GET['id'] ?? ''));
$statement = $pdo->prepare('SELECT landlord_id AS id, landlord_id, gov_id AS government_id_file, status FROM landlords WHERE landlord_id = ? LIMIT 1');
$statement->execute([$applicationId]);
$application = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
$authorized = session_user_with_role('admin') !== null || !empty($_SESSION['admin']);

if (!$authorized && !empty($_SESSION['landlord_email_review']['application_id'])) {
    $authorized = (string)$_SESSION['landlord_email_review']['application_id'] === (string)$applicationId;
}

if (!$authorized && $application) {
    $landlord = session_user_with_role('landlord');
    $authorized = $landlord !== null && (string)$application['id'] === (string)$landlord['id'];
}

if (!$application || !$authorized) {
    http_response_code(403);
    exit('Document access denied.');
}

$storedPath = str_replace('\\', '/', (string)($application['government_id_file'] ?? ''));
$fileName = basename($storedPath);

$candidatePaths = [
    __DIR__ . '/../uploads/landlord-applications/' . $fileName,
    __DIR__ . '/../uploads/' . $fileName,
];

if ($storedPath !== '') {
    $cleanRel = preg_replace('#^(?:\.\./)+#', '', ltrim($storedPath, './'));
    $candidatePaths[] = __DIR__ . '/../' . $cleanRel;
}

$filePath = false;
foreach ($candidatePaths as $p) {
    $rp = realpath($p);
    if ($rp !== false && is_file($rp)) {
        $filePath = $rp;
        break;
    }
}

if ($filePath === false) {
    http_response_code(404);
    exit('Document not found.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath) ?: 'application/octet-stream';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(415);
    exit('Unsupported document type.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline; filename="government-id.' . pathinfo($filePath, PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, no-store, max-age=0');
readfile($filePath);
