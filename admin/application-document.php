<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/landlord_applications.php';

start_app_session();
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

$pdo = database();
$applicationId = (int)($_GET['id'] ?? 0);
$statement = $pdo->prepare('SELECT * FROM landlord_applications WHERE id = ? LIMIT 1');
$statement->execute([$applicationId]);
$application = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
$authorized = session_user_with_role('admin') !== null;

if (!$authorized) {
    $granted = landlord_application_from_review_grant($pdo, (array)($_SESSION['landlord_email_review'] ?? []));
    $authorized = $granted && (int)$granted['id'] === $applicationId;
}

if (!$authorized && $application) {
    $landlord = session_user_with_role('landlord');
    $authorized = $landlord !== null && (int)($application['user_id'] ?? 0) === (int)$landlord['id'];
}

if (!$application || !$authorized) {
    http_response_code(403);
    exit('Document access denied.');
}

$storedPath = str_replace('\\', '/', (string)($application['government_id_file'] ?? ''));
$fileName = basename($storedPath);
$uploadRoot = realpath(__DIR__ . '/../uploads/landlord-applications');
$filePath = $uploadRoot !== false ? realpath($uploadRoot . DIRECTORY_SEPARATOR . $fileName) : false;
if ($uploadRoot === false || $filePath === false || !str_starts_with($filePath, $uploadRoot . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
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
