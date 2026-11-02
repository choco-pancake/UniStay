<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config/notifications.php';

header('Content-Type: application/json; charset=utf-8');

function application_response(bool $ok, string $error = '', int $code = 200): never
{
    http_response_code($code);
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error]);
    exit;
}

function validate_application_id(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 'Please upload a government-issued ID.';
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'The government ID could not be uploaded. Please try again.';
    }
    $size = (int)($file['size'] ?? 0);
    if ($size < 10 * 1024 || $size > 10 * 1024 * 1024) {
        return 'The government ID must be a clear image between 10 KB and 10 MB.';
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file((string)$file['tmp_name']);
    if (!isset($allowed[$mime])) {
        return 'Government IDs must be JPG, PNG, or WebP images.';
    }
    $dimensions = @getimagesize((string)$file['tmp_name']);
    if ($dimensions === false || (int)$dimensions[0] < 200 || (int)$dimensions[1] < 120) {
        return 'Please upload a clear, readable government ID image.';
    }
    return null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    application_response(false, 'Method not allowed.', 405);
}
if (!empty($_POST['website'])) {
    application_response(true);
}

$csrf = (string)($_POST['csrf_token'] ?? '');
$sessionCsrf = (string)($_SESSION['landlord_application_csrf'] ?? '');
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    application_response(false, 'Your session expired. Refresh the page and try again.', 419);
}

$firstName = trim((string)($_POST['first_name'] ?? ''));
$middleName = trim((string)($_POST['middle_name'] ?? ''));
$lastName = trim((string)($_POST['last_name'] ?? ''));
$email = strtolower(trim((string)($_POST['email'] ?? '')));
$phone = trim((string)($_POST['phone'] ?? ''));
$address = trim((string)($_POST['residential_address'] ?? ''));
$experience = trim((string)($_POST['experience_summary'] ?? ''));
$acknowledged = (string)($_POST['responsibility_acknowledged'] ?? '') === '1';

if ($firstName === '' || $lastName === '' || $phone === '' || $address === '' || $experience === '') {
    application_response(false, 'Please fill in all required fields.', 422);
}
if (mb_strlen($firstName) > 100 || mb_strlen($middleName) > 100 || mb_strlen($lastName) > 100) {
    application_response(false, 'Please enter a valid legal name.', 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
    application_response(false, 'Please enter a valid email address.', 422);
}
if (!preg_match('/^[0-9+() .-]{7,30}$/', $phone)) {
    application_response(false, 'Please enter a valid phone number.', 422);
}
if (mb_strlen($address) > 255) {
    application_response(false, 'The residential address is too long.', 422);
}
if (mb_strlen($experience) < 30 || mb_strlen($experience) > 1500) {
    application_response(false, 'Please provide 30 to 1,500 characters about your experience.', 422);
}
if (!$acknowledged) {
    application_response(false, 'You must acknowledge the landlord responsibilities.', 422);
}

$file = $_FILES['government_id'] ?? [];
$fileError = validate_application_id($file);
if ($fileError !== null) {
    application_response(false, $fileError, 422);
}

$pdo = db();
$duplicate = $pdo->prepare("SELECT status FROM landlord_applications WHERE email = ? AND status IN ('pending', 'approved') AND registered_at IS NULL ORDER BY id DESC LIMIT 1");
$duplicate->execute([$email]);
$existingStatus = $duplicate->fetchColumn();
if ($existingStatus === 'pending') {
    application_response(false, 'You already have an application under review.', 409);
}
if ($existingStatus === 'approved') {
    application_response(false, 'Your application is already approved. Please use your landlord invitation to register.', 409);
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
$extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$directory = __DIR__ . '/../uploads/landlord-applications/';
if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    application_response(false, 'The application document could not be stored.', 500);
}
$filename = 'landlord_id_' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
$absolutePath = $directory . $filename;
if (!move_uploaded_file((string)$file['tmp_name'], $absolutePath)) {
    application_response(false, 'The application document could not be stored.', 500);
}
$documentPath = '../uploads/landlord-applications/' . $filename;

try {
    $statement = $pdo->prepare(
        'INSERT INTO landlord_applications
         (first_name, middle_name, last_name, email, phone, residential_address,
          experience_summary, government_id_file, responsibility_acknowledged_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $statement->execute([
        $firstName,
        $middleName !== '' ? $middleName : null,
        $lastName,
        $email,
        $phone,
        $address,
        $experience,
        $documentPath,
    ]);
    $applicationId = (int)$pdo->lastInsertId();
} catch (Throwable $error) {
    @unlink($absolutePath);
    error_log('Landlord application save failed: ' . $error->getMessage());
    application_response(false, 'The application could not be saved. Please try again.', 500);
}

$fullName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
try {
    $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($admins as $adminId) {
        add_in_app_notification(
            $pdo,
            (int)$adminId,
            'system',
            'New Landlord Application',
            $fullName . ' submitted a landlord application. Open the Verification Center to review it.'
        );
    }
    send_landlord_application_admin_email($pdo, [
        'id' => $applicationId,
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'last_name' => $lastName,
        'email' => $email,
        'phone' => $phone,
    ]);
} catch (Throwable $error) {
    error_log('Landlord application notification failed: ' . $error->getMessage());
}

$_SESSION['landlord_application_csrf'] = bin2hex(random_bytes(32));
application_response(true);
