<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

start_app_session();
$authenticatedUser = require_signed_in('tenant');
$mandatoryProfileSetup = ($_SESSION['profile_onboarding_phase'] ?? '') === 'editing';
$pdo = database();
$user_id = (int)$authenticatedUser['id'];
$successMessage = '';
$justCompleted = false;
$errorMessage = '';

/**
 * Validates an uploaded ID image file.
 * Requires: valid upload, allowed MIME (jpg, png, webp), size between 10KB and 10MB,
 * and valid image dimensions (min 200x120px) to prevent fake/blank/corrupted files.
 */
function validate_id_image(array $file): ?string {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return "An error occurred during file upload (Upload error code: {$file['error']}).";
    }
    if ($file['size'] < 10 * 1024) {
        return "Uploaded ID file is too small or blank (" . round($file['size'] / 1024, 1) . " KB). Please upload a clear photo of your ID (minimum 10 KB).";
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        return "Uploaded ID file exceeds the 10 MB limit. Please upload a smaller image file.";
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $file['tmp_name']);
        }
    }
    if (empty($mime) && function_exists('mime_content_type')) {
        $mime = mime_content_type($file['tmp_name']);
    }
    if (!empty($mime) && !in_array($mime, $allowedMimes, true)) {
        return "Invalid file format. Only JPG, PNG, or WEBP photos are accepted for ID verification.";
    }

    $imgInfo = @getimagesize($file['tmp_name']);
    if ($imgInfo === false) {
        return "The uploaded file is not a valid image. Please upload a genuine, legible photo of your ID.";
    }

    $width = (int)($imgInfo[0] ?? 0);
    $height = (int)($imgInfo[1] ?? 0);
    if ($width < 200 || $height < 120) {
        return "The image resolution ({$width}×{$height}px) is too small to verify as a legible ID. Minimum required resolution is 200×120 pixels.";
    }

    return null;
}

/** Deletes a stored upload, but only if it really lives inside tenant/uploads/. */
function delete_tenant_upload(string $stored): void {
    if ($stored === '') return;
    $root = realpath(__DIR__ . '/uploads');
    $target = realpath(__DIR__ . '/' . $stored);
    if ($root !== false && $target !== false && is_file($target)
        && strpos($target, $root . DIRECTORY_SEPARATOR) === 0) {
        @unlink($target);
    }
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Handle Actions
if ($requestMethod === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_profile') {
        $firstName              = trim($_POST['first_name'] ?? '');
        $middleName             = trim($_POST['middle_name'] ?? '');
        $lastName               = trim($_POST['last_name'] ?? '');
        $phoneNumber            = trim($_POST['phone_number'] ?? '');
        $personalEmail          = trim($_POST['personal_email'] ?? '');
        $school                 = trim($_POST['school'] ?? '');
        $emergencyName          = trim($_POST['emergency_name'] ?? '');
        $emergencyRelationship  = trim($_POST['emergency_relationship'] ?? '');
        $emergencyPhone         = trim($_POST['emergency_phone'] ?? '');

        // Phone Number +63 Normalization
        if ($phoneNumber !== '') {
            $cleanDigits = preg_replace('/[^\d]/', '', $phoneNumber);
            if (str_starts_with($cleanDigits, '63')) {
                $cleanDigits = substr($cleanDigits, 2);
            } elseif (str_starts_with($cleanDigits, '0')) {
                $cleanDigits = substr($cleanDigits, 1);
            }
            $phoneNumber = $cleanDigits !== '' ? ('+63' . $cleanDigits) : '';
        }

        // Emergency Phone +63 Normalization
        if ($emergencyPhone !== '') {
            $cleanDigits = preg_replace('/[^\d]/', '', $emergencyPhone);
            if (str_starts_with($cleanDigits, '63')) {
                $cleanDigits = substr($cleanDigits, 2);
            } elseif (str_starts_with($cleanDigits, '0')) {
                $cleanDigits = substr($cleanDigits, 1);
            }
            $emergencyPhone = $cleanDigits !== '' ? ('+63' . $cleanDigits) : '';
        }

        // Facebook Link Normalization
        $rawFb = trim($_POST['facebook_link'] ?? '');
        $facebookLink = '';
        if ($rawFb !== '') {
            $cleanHandle = preg_replace('#^(?:https?://)?(?:www\.)?facebook\.com/#i', '', $rawFb);
            $cleanHandle = trim($cleanHandle, '/');
            $facebookLink = $cleanHandle !== '' ? ('https://facebook.com/' . $cleanHandle) : '';
        }

        // Validation
        if ($firstName === '') {
            $errorMessage = "First name is required.";
        } elseif ($lastName === '') {
            $errorMessage = "Last name is required.";
        } elseif (!preg_match('/^\+639\d{9}$/', $phoneNumber)) {
            $errorMessage = "Enter a valid 10-digit Philippine mobile number beginning with 9.";
        } elseif ($facebookLink === '') {
            $errorMessage = "Facebook profile link is required for communication with landlords.";
        } elseif ($personalEmail === '' || !filter_var($personalEmail, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = "A valid email address is required.";
        } elseif ($school === '') {
            $errorMessage = "School is required.";
        } elseif ($emergencyName === '') {
            $errorMessage = "Emergency contact name is required.";
        } elseif ($emergencyRelationship === '') {
            $errorMessage = "Emergency contact relationship is required.";
        } elseif (!preg_match('/^\+639\d{9}$/', $emergencyPhone)) {
            $errorMessage = "Enter a valid 10-digit emergency mobile number beginning with 9.";
        }

        // ID Document Validation (min 10KB, max 10MB, min 200x120px)
        if (empty($errorMessage) && isset($_FILES['school_id']) && $_FILES['school_id']['error'] !== UPLOAD_ERR_NO_FILE) {
            $valErr = validate_id_image($_FILES['school_id']);
            if ($valErr !== null) {
                $errorMessage = "School ID: " . $valErr;
            }
        }
        if (empty($errorMessage) && isset($_FILES['gov_id']) && $_FILES['gov_id']['error'] !== UPLOAD_ERR_NO_FILE) {
            $valErr = validate_id_image($_FILES['gov_id']);
            if ($valErr !== null) {
                $errorMessage = "Government ID: " . $valErr;
            }
        }

        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Profile Photo: check for base64 cropped photo first, then standard file upload
        $profilePhotoPath = $_POST['existing_profile_photo'] ?? '';
        $croppedPhotoData = $_POST['cropped_profile_photo'] ?? '';
        if (!empty($croppedPhotoData) && preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#i', $croppedPhotoData)) {
            $parts = explode(',', $croppedPhotoData, 2);
            $decoded = base64_decode($parts[1] ?? '', true);
            if ($decoded !== false) {
                $avatarFileName = 'avatar_' . time() . '_' . bin2hex(random_bytes(4)) . '.png';
                if (file_put_contents($uploadDir . $avatarFileName, $decoded) !== false) {
                    $profilePhotoPath = 'uploads/' . $avatarFileName;
                }
            }
        } elseif (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $fileName = time() . '_' . basename($_FILES['profile_photo']['name']);
            $destPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $destPath)) {
                $profilePhotoPath = 'uploads/' . $fileName;
            }
        }

        // Read the current ID files from the database, never from the form
        $idStmt = $pdo->prepare("SELECT school_id_file, gov_id_file FROM users WHERE id = ?");
        $idStmt->execute([$user_id]);
        $currentIds = $idStmt->fetch() ?: [];

        $newSchoolIdUploaded = false;
        $schoolIdPath = (string)($currentIds['school_id_file'] ?? '');
        if (empty($errorMessage) && isset($_FILES['school_id']) && $_FILES['school_id']['error'] === UPLOAD_ERR_OK) {
            $fileName = 'school_id_' . time() . '_' . basename($_FILES['school_id']['name']);
            $destPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['school_id']['tmp_name'], $destPath)) {
                $schoolIdPath = 'uploads/' . $fileName;
                $newSchoolIdUploaded = true;
            }
        }

        $newGovIdUploaded = false;
        $govIdPath = (string)($currentIds['gov_id_file'] ?? '');
        if (empty($errorMessage) && isset($_FILES['gov_id']) && $_FILES['gov_id']['error'] === UPLOAD_ERR_OK) {
            $fileName = 'gov_id_' . time() . '_' . basename($_FILES['gov_id']['name']);
            $destPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['gov_id']['tmp_name'], $destPath)) {
                $govIdPath = 'uploads/' . $fileName;
                $newGovIdUploaded = true;
            }
        }

        // Staged removals from the edit form (a newly uploaded file always wins)
        $removeSchoolId = empty($errorMessage) && !$newSchoolIdUploaded && ($_POST['remove_school_id'] ?? '0') === '1';
        $removeGovId    = empty($errorMessage) && !$newGovIdUploaded    && ($_POST['remove_gov_id'] ?? '0') === '1';
        $oldSchoolIdFile = (string)($currentIds['school_id_file'] ?? '');
        $oldGovIdFile    = (string)($currentIds['gov_id_file'] ?? '');
        if ($removeSchoolId) $schoolIdPath = '';
        if ($removeGovId)    $govIdPath = '';

        // Completion condition: identity, contact, school, emergency contact, and both IDs are required.
        $effectiveSchoolId = $schoolIdPath;
        $effectiveGovId = $govIdPath;
        $isComplete = ($firstName !== '' && $lastName !== '' && $phoneNumber !== '' && $facebookLink !== '' && $school !== ''
        && $emergencyName !== '' && $emergencyRelationship !== '' && $emergencyPhone !== ''
        && $effectiveSchoolId !== '' && $effectiveGovId !== '');

        if (empty($errorMessage) && !$isComplete) {
            $errorMessage = 'Complete every required profile field and upload both required IDs before saving.';
        }

        if (empty($errorMessage)) {
            try {
                $currVerif = $pdo->prepare("SELECT verification_status, profile_completed FROM users WHERE id = ?");
                $currVerif->execute([$user_id]);
                $currRow = $currVerif->fetch() ?: [];
                $currentStatus = (string)($currRow['verification_status'] ?? 'unverified');
                $wasComplete = (int)($currRow['profile_completed'] ?? 0) === 1;

                $hasIds = ($effectiveSchoolId !== '' || $effectiveGovId !== '');

                $newVerifStatus = $currentStatus;
                $newVerifNotes = null;
                if ($hasIds && ($newSchoolIdUploaded || $newGovIdUploaded || in_array($currentStatus, ['unverified', 'rejected'], true))) {
                    $newVerifStatus = 'pending';
                    $newVerifNotes = null;
                }

                // Removing an ID from a verified/pending account sends it back to unverified
                if (($removeSchoolId || $removeGovId) && in_array($currentStatus, ['verified', 'pending'], true)) {
                    $newVerifStatus = 'unverified';
                    $newVerifNotes = null;
                }

                $stmt = $pdo->prepare("UPDATE users SET 
                    first_name = ?, middle_name = ?, last_name = ?, phone_number = ?, 
                    facebook_link = ?, personal_email = ?, 
                    school = ?, emergency_name = ?, emergency_relationship = ?, emergency_phone = ?,
                    profile_photo = ?, photo = ?, school_id_file = ?, gov_id_file = ?,
                    verification_status = ?, verification_notes = ?,
                    profile_completed = CASE WHEN ? = 1 OR profile_completed = 1 THEN 1 ELSE profile_completed END
                    WHERE id = ?");
                $stmt->execute([
                    $firstName,
                    $middleName !== '' ? $middleName : null,
                    $lastName,
                    $phoneNumber, 
                    $facebookLink !== '' ? $facebookLink : null, 
                    $personalEmail, 
                    $school, $emergencyName, $emergencyRelationship, $emergencyPhone,
                    $profilePhotoPath, $profilePhotoPath,
                    $schoolIdPath !== '' ? $schoolIdPath : null,
                    $govIdPath !== '' ? $govIdPath : null,
                    $newVerifStatus,
                    $newVerifNotes,
                    $isComplete ? 1 : 0,
                    $user_id
                ]);

                if ($removeSchoolId || $removeGovId) {
                    $pdo->prepare("UPDATE users SET profile_completed = 0 WHERE id = ?")->execute([$user_id]);
                    if ($removeSchoolId) delete_tenant_upload($oldSchoolIdFile);
                    if ($removeGovId)    delete_tenant_upload($oldGovIdFile);
                }

                if (isset($_SESSION['user'])) {
                    $_SESSION['user']['name'] = trim($firstName . ' ' . $lastName) ?: $firstName;
                    if ($profilePhotoPath !== '') {
                        $_SESSION['user']['photo'] = $profilePhotoPath;
                    }
                }
                $successMessage = ($removeSchoolId || $removeGovId) && $newVerifStatus === 'unverified'
                    ? "Profile updated. An ID photo was removed, so your account is unverified until you upload your IDs again."
                    : (($newVerifStatus === 'pending')
                        ? "Profile updated successfully! Your ID documents have been submitted for admin verification."
                        : ($isComplete ? "Profile completed successfully! You can now browse dorms and submit room requests once verified." : "Profile updated successfully!"));

                if ($isComplete && !$wasComplete) {
                    unset($_SESSION['profile_completion_required'], $_SESSION['profile_onboarding_phase']);
                    $_SESSION['start_tenant_tour'] = true;
                    header('Location: dorms.php', true, 303);
                    exit;
                }
                    } catch (\PDOException $e) {
                $errorMessage = "Database update failed: " . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'update_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($newPassword === '' || strlen($newPassword) < 8) {
            $errorMessage = "New password must be at least 8 characters long.";
        } elseif ($newPassword !== $confirmPassword) {
            $errorMessage = "New password and confirmation do not match.";
        } else {
            try {
                $st = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
                $st->execute([$user_id]);
                $dbPass = (string)$st->fetchColumn();

                $canUpdate = false;
                if ($dbPass === '' || password_verify($currentPassword, $dbPass)) {
                    $canUpdate = true;
                }

                if ($canUpdate) {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $upd->execute([$newHash, $user_id]);
                    $successMessage = "Your password has been changed successfully!";
                } else {
                    $errorMessage = "The current password you entered is incorrect.";
                }
            } catch (\Exception $e) {
                $errorMessage = "Failed to update password: " . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'delete_account') {
        $confirmEmail = strtolower(trim((string)($_POST['confirm_email'] ?? '')));
        try {
            $st = $pdo->prepare("SELECT personal_email FROM users WHERE id = ? LIMIT 1");
            $st->execute([$user_id]);
            $uRow = $st->fetch();
            $realEmail = strtolower(trim((string)($uRow['personal_email'] ?? '')));

            if ($confirmEmail !== '' && $confirmEmail === $realEmail) {
                $pdo->prepare("DELETE FROM bookings WHERE user_id = ?")->execute([$user_id]);
                $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$user_id]);
                $pdo->prepare("DELETE FROM notification_preferences WHERE user_id = ?")->execute([$user_id]);
                $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$user_id]);
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);

                // Sync AUTO_INCREMENT sequence to next item in database
                sync_users_auto_increment($pdo);

                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_destroy();
                }
                header('Location: ../index.php');
                exit;
            } else {
                $errorMessage = "Email confirmation did not match your account email.";
            }
        } catch (\Exception $e) {
            $errorMessage = "Could not delete account: " . $e->getMessage();
        }
    }
}

// Fetch Current User Data
$user = [
    'first_name'             => '',
    'middle_name'            => '',
    'last_name'              => '',
    'phone_number'           => '',
    'facebook_link'          => '',
    'personal_email'         => '',
    'date_of_birth'          => '',
    'gender'                 => '',
    'school'                 => '',
    'emergency_name'         => '',
    'emergency_relationship' => '',
    'emergency_phone'        => '',
    'profile_photo'          => '',
    'school_id_file'         => '',
    'gov_id_file'            => '',
    'role'                   => 'Tenant'
];

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    if ($row) {
        $user = array_merge($user, $row);
    }
    foreach ($user as $k => $v) {
        if ($v === null) $user[$k] = '';
    }
} catch (\Exception $e) {}

$tenantProfile = get_authenticated_profile($pdo, $user_id);
$userInitials = $tenantProfile['initials'] ?? 'U';
$effectiveAvatar = $tenantProfile['photo'] ?? '';

$relationshipOptions = ['Parent', 'Guardian', 'Sibling', 'Relative', 'Friend', 'Other'];
$universityOptions = [
    'Universidad de Dagupan',
    'University of Pangasinan',
    'University of Luzon',
    'Lyceum Northwestern University'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#7a4b3a">
    <title>Settings · UniStay</title>
    <link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/common.css?v=32">
    <link rel="stylesheet" href="../assets/css/settings.css?v=7">
    <style>
        /* Red "Remove Photo" button in the ID dropzones */
        .dropzone-btn.dropzone-btn-danger {
            color: #dc2626;
            border-color: #fecaca;
            background: #ffffff;
        }
        .dropzone-btn.dropzone-btn-danger:hover {
            background: #fef2f2;
            color: #991b1b;
        }
    </style>
    <script src="../assets/js/tenant/popover.js?v=2"></script>
    <script src="../assets/js/tenant/sidebar.js"></script>
    <script src="../assets/js/tenant/profile-menu.js?v=32"></script>
</head>
    <body class="settings-page">
        <svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs>
            <symbol id="grid-icon" viewBox="0 0 24 24"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></symbol>
            <symbol id="bed-icon" viewBox="0 0 24 24"><path d="M3 19V9h18v10M3 16h18M5 9V5h5v4m4 0V5h5v4"/></symbol>
            <symbol id="map-icon" viewBox="0 0 24 24"><path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2Zm6-2v16m6-14v16"/></symbol>
            <symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
            <symbol id="bell-icon" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M10 20h4"/></symbol>
            <symbol id="bell-off-icon" viewBox="0 0 24 24"><path d="m3 3 18 18M10 20h4M6 6c-1 2 0 7-3 10h13M18 13V8a6 6 0 0 0-8-6"/></symbol>
            <symbol id="arrow-icon" viewBox="0 0 24 24"><path d="M4 12h16m-6-6 6 6-6 6"/></symbol>
            <symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
            <symbol id="close-icon" viewBox="0 0 24 24"><path d="m6 6 12 12M6 18 18 6"/></symbol>
            <symbol id="pencil-icon" viewBox="0 0 24 24"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></symbol>
            <symbol id="camera-icon" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></symbol>
            <symbol id="logout-icon" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        </defs></svg>

        <!-- Sidebar Navigation -->
        <aside class="sidebar" aria-label="UniStay portal">
            <div class="brand">
                <img src="../assets/images/tenant/logo.png" width="50" height="50" alt="UniStay logo">
                <span class="brand-name">UniStay</span>
            </div>
            <nav aria-label="Main navigation">
                <a href="dashboard.php" title="Dashboard"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
                <a href="dorms.php" title="Dorms"><svg aria-hidden="true"><use href="#bed-icon"/></svg><span class="nav-label">Dorms</span></a>
                <a href="map.php" title="Map"><svg aria-hidden="true"><use href="#map-icon"/></svg><span class="nav-label">Map</span></a>
                <a href="settings.php" title="Settings" aria-current="page"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
            </nav>
            <button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true">
                <span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span>
                <span class="toggle-label">Collapse</span>
            </button>
        </aside>

        <!-- Shell Container -->
        <div class="shell">
            <div class="shell-orbs" aria-hidden="true"><i></i><i></i><i></i></div>
            <header class="topbar">
                <h1 id="headerTitle">User Profile</h1>
                <div class="profile">
                    <button id="notification-bell" class="bell" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="notification-popover">
                        <svg aria-hidden="true"><use href="#bell-icon"/></svg>
                    </button>
                    <button class="avatar" type="button" aria-label="Open profile menu" aria-expanded="false" aria-controls="profile-popover" <?= !empty($effectiveAvatar) ? 'style="background-image:url(\''.htmlspecialchars($effectiveAvatar, ENT_QUOTES, 'UTF-8').'\');"' : '' ?>>
                        <?php if (!empty($effectiveAvatar)): ?>
                            <img src="<?= htmlspecialchars($effectiveAvatar) ?>" alt="" referrerpolicy="no-referrer" class="avatar-img" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline-flex';">
                            <span style="display:none;"><?= htmlspecialchars($userInitials) ?></span>
                        <?php else: ?>
                            <?= htmlspecialchars($userInitials) ?>
                        <?php endif; ?>
                    </button>
                    <section id="notification-popover" class="popover" aria-labelledby="popover-title" hidden>
                        <h2 id="popover-title">Notifications</h2>
                        <div class="empty" id="popover-empty"><span class="empty-icon"><svg aria-hidden="true"><use href="#bell-off-icon"/></svg></span><strong>No new notifications</strong><p>We’ll let you know when something arrives.</p></div>
                        <ul class="notification-list" id="popover-list" aria-label="Unread notifications" hidden></ul>
                        <a class="view-all" id="view-all" href="notifications.php?from=settings" aria-label="View all notifications">View all notifications <svg aria-hidden="true"><use href="#arrow-icon"/></svg></a>
                    </section>
                </div>
            </header>

            <main id="settings-main" tabindex="-1">
                <!-- Alerts -->
                <?php if (!empty($errorMessage)): ?>
                    <div class="settings-alert is-error" role="alert">⚠ <?= htmlspecialchars($errorMessage) ?></div>
                <?php endif; ?>

                <?php if (!empty($successMessage)): ?>
                    <dialog id="profileSuccessModal" class="ps-modal" aria-labelledby="psTitle" aria-describedby="psMsg">
                        <div class="ps-card">
                            <div class="ps-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>
                            </div>
                            <h2 id="psTitle"><?= $justCompleted ? 'Registration complete!' : 'Profile updated' ?></h2>
                            <p id="psMsg"><?= htmlspecialchars($successMessage) ?></p>
                            <?php if ($justCompleted): ?>
                                <a class="ps-btn" href="dashboard.php">Start the tour <span aria-hidden="true">→</span></a>
                            <?php else: ?>
                                <button type="button" class="ps-btn" id="psClose">Got it</button>
                            <?php endif; ?>
                        </div>
                    </dialog>
                    <script>
                        (function () {
                            var d = document.getElementById('profileSuccessModal');
                            if (!d || typeof d.showModal !== 'function') return;
                            var required = <?= $justCompleted ? 'true' : 'false' ?>;
                            function close() {
                                d.classList.add('is-closing');
                                setTimeout(function () { d.close(); d.classList.remove('is-closing'); }, 200);
                            }
                            d.addEventListener('cancel', function (e) { e.preventDefault(); if (!required) close(); });
                            d.addEventListener('click', function (e) { if (e.target === d && !required) close(); });
                            var btn = document.getElementById('psClose');
                            if (btn) btn.addEventListener('click', close);
                            d.showModal();
                        })();
                    </script>
                <?php endif; ?>

                <div class="settings-tabs" role="tablist">
                    <div class="settings-tabs-indicator" id="tabsIndicator"></div>
                    <button type="button" class="settings-tab-btn is-active" onclick="switchTab('profile')" id="btn-profile" role="tab" aria-selected="true">Profile</button>
                    <button type="button" class="settings-tab-btn" onclick="switchTab('security')" id="btn-security" role="tab" aria-selected="false" <?= $mandatoryProfileSetup ? 'disabled aria-disabled="true"' : '' ?>>Account and Security</button>
                    <button type="button" class="settings-tab-btn" onclick="switchTab('notifications')" id="btn-notifications" role="tab" aria-selected="false" <?= $mandatoryProfileSetup ? 'disabled aria-disabled="true"' : '' ?>>Notifications</button>
                </div>

                <!-- TAB 1: PROFILE -->
                <div id="tab-profile" class="settings-tab-content is-active">
                    <!-- VIEW MODE CARD -->
                    <div id="profileViewCard" class="settings-card">
                        <div class="profile-view-header">
                            <div class="profile-view-identity">
                                <div class="profile-view-avatar">
                                    <?php if (!empty($effectiveAvatar)): ?>
                                        <img src="<?= htmlspecialchars($effectiveAvatar) ?>" alt="Avatar" referrerpolicy="no-referrer" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline';">
                                        <span style="display:none;"><?= htmlspecialchars($userInitials) ?></span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($userInitials) ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <h2 class="profile-view-name"><?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Student Profile') ?></h2>
                                    <span class="tenant-role-label">TENANT</span>
                                </div>
                            </div>
                            <button type="button" class="btn-settings-secondary" onclick="toggleEditMode()">
                                <svg style="width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;" viewBox="0 0 24 24"><use href="#pencil-icon"/></svg> Edit Profile
                            </button>
                        </div>

                        <?php
                        $vStatus = strtolower($user['verification_status'] ?? 'unverified');
                        $vNotes = $user['verification_notes'] ?? '';
                        $hasAnyId = !empty($user['school_id_file']) || !empty($user['gov_id_file']);
                        ?>
                        <?php if ($vStatus === 'verified'): ?>
                            <?php if (($_GET['notice'] ?? '') === 'verification'): ?>
                            <div id="verification-result" class="tenant-verification-feedback" role="status" style="padding:14px 18px; margin-bottom:20px; border:1px solid #bbf7d0; border-radius:12px; background:#f0fdf4; color:#166534;">
                                <strong>Account verified</strong>
                                <p style="margin:4px 0 0;">Your identification documents have been approved by the administrator.</p>
                            </div>
                            <?php endif; ?>
                        <?php elseif ($vStatus === 'pending' || ($vStatus === 'unverified' && $hasAnyId)): ?>
                            <div id="verification-result" class="tenant-verification-feedback" style="background:#fffbeb; border:1px solid #fde68a; border-radius:12px; padding:14px 18px; margin-bottom:20px; display:flex; align-items:center; gap:12px;">
                                <span style="font-size:22px;">⏳</span>
                                <div>
                                    <strong style="color:#92400e; font-size:0.95rem; display:block;">Verification Pending Admin Review</strong>
                                    <span style="color:#78350f; font-size:0.86rem;">Your identification documents have been submitted. An administrator is currently reviewing your documents.</span>
                                </div>
                            </div>
                        <?php elseif ($vStatus === 'rejected'): ?>
                            <div id="verification-result" class="tenant-verification-feedback" style="background:#fef2f2; border:1px solid #fecaca; border-radius:12px; padding:14px 18px; margin-bottom:20px; display:flex; align-items:flex-start; gap:12px;">
                                <span style="font-size:22px; line-height:1;">✕</span>
                                <div>
                                    <strong style="color:#991b1b; font-size:0.95rem; display:block;">Verification Needs Attention</strong>
                                    <span style="color:#b91c1c; font-size:0.86rem; display:block; margin:4px 0 8px;">Feedback: <?= htmlspecialchars($vNotes ?: 'Your documents could not be verified. Please upload clear photos.') ?></span>
                                    <button type="button" class="btn-settings-secondary" style="font-size:0.82rem; padding:6px 12px;" onclick="toggleEditMode()">Re-upload Valid Documents</button>
                                </div>
                            </div>
                        <?php else: ?>
                            <div id="verification-result" class="tenant-verification-feedback" style="background:#fcf8f5; border:1px dashed #ebd9cb; border-radius:12px; padding:14px 18px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                                <div>
                                    <strong style="color:#63331b; font-size:0.95rem; display:block;">Profile Incomplete</strong>
                                    <span style="color:#78665c; font-size:0.86rem;">Complete your required profile details and upload your IDs. You can request a room while admin verification is pending.</span>
                                </div>
                                <button type="button" class="btn-settings-secondary" style="font-size:0.82rem; padding:6px 12px;" onclick="toggleEditMode()">Complete Profile</button>
                            </div>
                        <?php endif; ?>

                        <h3 class="settings-section-title">Personal Information</h3>
                        <div class="settings-grid-3">
                            <div class="info-display-group">
                                <span class="info-display-label">First Name</span>
                                <div class="info-display-val"><?= htmlspecialchars($user['first_name'] ?: 'Not specified') ?></div>
                            </div>
                            <div class="info-display-group">
                                <span class="info-display-label">Middle Name</span>
                                <div class="info-display-val"><?= htmlspecialchars($user['middle_name'] ?: 'None') ?></div>
                            </div>
                            <div class="info-display-group">
                                <span class="info-display-label">Last Name</span>
                                <div class="info-display-val"><?= htmlspecialchars($user['last_name'] ?: 'Not specified') ?></div>
                            </div>
                            <div class="info-display-group">
                                <span class="info-display-label">Phone Number</span>
                                <div class="info-display-val"><?= htmlspecialchars($user['phone_number'] ?: 'Not specified') ?></div>
                            </div>
                            <div class="info-display-group">
                                <span class="info-display-label">Facebook Profile</span>
                                <div class="info-display-val">
                                    <?php if (!empty($user['facebook_link'])): ?>
                                        <a href="<?= htmlspecialchars($user['facebook_link']) ?>" target="_blank" rel="noopener noreferrer" style="color: #7a4b3a; font-weight: 600; text-decoration: underline; word-break: break-all;">
                                            <?= htmlspecialchars(preg_replace('#^https?://#', '', $user['facebook_link'])) ?> ↗
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #9a8c84;">Not specified</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-display-group">
                                <span class="info-display-label">Email Address</span>
                                <div class="info-display-val"><?= htmlspecialchars($user['personal_email'] ?: ($user['email'] ?? 'Not specified')) ?></div>
                            </div>
                            <div class="info-display-group">
                                <span class="info-display-label">University / College</span>
                                <div class="info-display-val"><?= htmlspecialchars($user['school'] ?: 'Not specified') ?></div>
                            </div>
                        </div>

                        <h3 class="settings-section-title">Emergency Contact</h3>
                        <div class="settings-grid-3">
                            <div class="info-display-group">
                                <span class="info-display-label">Contact Name</span>
                                <div class="info-display-val"><?= htmlspecialchars($user['emergency_name'] ?: 'Not specified') ?></div>
                            </div>
                            <div class="info-display-group">
                                <span class="info-display-label">Relationship</span>
                                <div class="info-display-val"><?= htmlspecialchars($user['emergency_relationship'] ?: 'Not specified') ?></div>
                            </div>
                            <div class="info-display-group">
                                <span class="info-display-label">Emergency Phone</span>
                                <div class="info-display-val"><?= htmlspecialchars($user['emergency_phone'] ?: 'Not specified') ?></div>
                            </div>
                        </div>

                        <h3 class="settings-section-title">Uploaded Identification</h3>
                        <div class="settings-grid-2">
                            <div class="id-document-card">
                                <div class="id-document-meta">
                                    <span class="id-document-title">School ID</span>
                                    <?php if (!empty($user['school_id_file'])): ?>
                                        <button type="button" class="id-document-view-btn" onclick="openLightbox('<?= htmlspecialchars($user['school_id_file']) ?>', 'School ID')">View full photo ↗</button>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($user['school_id_file'])): ?>
                                    <div class="id-document-thumb" onclick="openLightbox('<?= htmlspecialchars($user['school_id_file']) ?>', 'School ID')">
                                        <img src="<?= htmlspecialchars($user['school_id_file']) ?>" alt="School ID Preview">
                                    </div>
                                    <span style="font-size: 0.8rem; color: #8b7467; word-break: break-all;"><?= htmlspecialchars(basename($user['school_id_file'])) ?></span>
                                <?php else: ?>
                                    <div class="id-document-thumb" style="cursor: default; flex-direction: column; gap: 8px; color: #8b7467; font-size: 0.86rem;">
                                        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#camera-icon"/></svg>
                                        <span>No School ID uploaded</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="id-document-card">
                                <div class="id-document-meta">
                                    <span class="id-document-title">Government ID</span>
                                    <?php if (!empty($user['gov_id_file'])): ?>
                                        <button type="button" class="id-document-view-btn" onclick="openLightbox('<?= htmlspecialchars($user['gov_id_file']) ?>', 'Government ID')">View full photo ↗</button>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($user['gov_id_file'])): ?>
                                    <div class="id-document-thumb" onclick="openLightbox('<?= htmlspecialchars($user['gov_id_file']) ?>', 'Government ID')">
                                        <img src="<?= htmlspecialchars($user['gov_id_file']) ?>" alt="Government ID Preview">
                                    </div>
                                    <span style="font-size: 0.8rem; color: #8b7467; word-break: break-all;"><?= htmlspecialchars(basename($user['gov_id_file'])) ?></span>
                                <?php else: ?>
                                    <div class="id-document-thumb" style="cursor: default; flex-direction: column; gap: 8px; color: #8b7467; font-size: 0.86rem;">
                                        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#camera-icon"/></svg>
                                        <span>No Government ID uploaded</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- EDIT MODE FORM -->
                    <form id="profileEditForm" action="settings.php" method="POST" enctype="multipart/form-data" class="settings-card" data-required-docs="school_id,gov_id" style="display: none;">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="existing_profile_photo" value="<?= htmlspecialchars($user['profile_photo'] ?: ($user['photo'] ?? '')) ?>">
                        <input type="hidden" name="cropped_profile_photo" id="cropped_profile_photo" value="">
                        <input type="hidden" name="existing_school_id" value="<?= htmlspecialchars($user['school_id_file']) ?>">
                        <input type="hidden" name="existing_gov_id" value="<?= htmlspecialchars($user['gov_id_file']) ?>">
                        <input type="hidden" name="remove_school_id" id="remove_school_id" value="0">
                        <input type="hidden" name="remove_gov_id" id="remove_gov_id" value="0">

                        <div class="avatar-edit-box">
                            <div class="avatar-edit-preview-wrap">
                                <div class="avatar-preview-circle" id="avatarPreviewContainer">
                                    <?php if (!empty($effectiveAvatar)): ?>
                                        <img src="<?= htmlspecialchars($effectiveAvatar) ?>" alt="Avatar" referrerpolicy="no-referrer" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline';">
                                        <span style="display:none;"><?= htmlspecialchars($userInitials) ?></span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($userInitials) ?>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="avatar-edit-btn" onclick="document.getElementById('profile_photo_input').click();" title="Change photo">
                                    <svg viewBox="0 0 24 24" fill="none"><use href="#camera-icon"/></svg>
                                </button>
                                <input type="file" id="profile_photo_input" name="profile_photo" accept="image/*" style="display: none;" onchange="initCropper(this)">
                            </div>
                            <div>
                                <h3 style="color: #3b2820; font-size: 1.15rem; margin-bottom: 4px;"><?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Update Profile') ?></h3>
                                <span class="tenant-role-label">TENANT</span>
                            </div>
                        </div>

                        <h3 class="settings-section-title" style="margin-top:0;">Edit Personal Information</h3>

                        <div class="settings-grid-3">
                            <div class="settings-field">
                                <label class="settings-label">First Name *</label>
                                <input type="text" name="first_name" class="settings-input" value="<?= htmlspecialchars($user['first_name']) ?>" required>
                            </div>
                            <div class="settings-field">
                                <label class="settings-label">Middle Name</label>
                                <input type="text" name="middle_name" class="settings-input" value="<?= htmlspecialchars($user['middle_name']) ?>">
                            </div>
                            <div class="settings-field">
                                <label class="settings-label">Last Name *</label>
                                <input type="text" name="last_name" class="settings-input" value="<?= htmlspecialchars($user['last_name'] ?? '') ?>" required>
                            </div>
                            <div class="settings-field">
                                <label class="settings-label">Phone Number *</label>
                                <div class="input-prefix-wrapper">
                                    <span class="input-prefix-addon">+63</span>
                                    <input type="tel" name="phone_number" class="settings-input" value="<?= htmlspecialchars(preg_replace('/^(?:\+?63|0)/', '', $user['phone_number'] ?? '')) ?>" placeholder="9XXXXXXXXX" inputmode="numeric" minlength="10" maxlength="10" pattern="9[0-9]{9}" required>
                                </div>
                            </div>
                            <div class="settings-field">
                                <label class="settings-label">Facebook Link *</label>
                                <div class="input-prefix-wrapper">
                                    <span class="input-prefix-addon">facebook.com/</span>
                                    <input type="text" name="facebook_link" class="settings-input" value="<?= htmlspecialchars(preg_replace('#^(?:https?://)?(?:www\.)?facebook\.com/#i', '', $user['facebook_link'])) ?>" placeholder="username or profile ID" required>
                                </div>
                            </div>
                            <div class="settings-field">
                                <label class="settings-label">Email Address *</label>
                                <div class="input-with-icon-wrapper">
                                    <input type="email" name="personal_email" class="settings-input" value="<?= htmlspecialchars($user['personal_email']) ?>" required>
                                </div>
                            </div>
                            <div class="settings-field">
                                <label class="settings-label" for="schoolSelect">School / University *</label>
                                <div class="select-with-icon-wrapper" id="schoolSelectWrap">
                                    <select name="school" id="schoolSelect" class="settings-select themed-select" required>
                                        <?php foreach ($universityOptions as $uOpt): ?>
                                            <option value="<?= htmlspecialchars($uOpt) ?>" <?= ($user['school'] === $uOpt) ? 'selected' : '' ?>><?= htmlspecialchars($uOpt) ?></option>
                                        <?php endforeach; ?>
                                        <?php if (!empty($user['school']) && !in_array($user['school'], $universityOptions, true)): ?>
                                            <option value="<?= htmlspecialchars($user['school']) ?>" selected><?= htmlspecialchars($user['school']) ?> (Other)</option>
                                        <?php endif; ?>
                                    </select>
                                    <span class="select-dropdown-arrow" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                    </span>
                                </div>
                            </div>
                        </div>

                    <h3 class="settings-section-title">Edit Emergency Contact</h3>
                        <div class="settings-grid-3">
                            <div class="settings-field">
                                <label class="settings-label">Contact Name *</label>
                                <input type="text" name="emergency_name" class="settings-input" value="<?= htmlspecialchars($user['emergency_name']) ?>" required>
                            </div>
                            <div class="settings-field">
                                <label class="settings-label" for="emergency_relationship">Relationship *</label>
                                <div class="select-with-icon-wrapper" id="relationshipSelectWrap">
                                    <select name="emergency_relationship" id="emergency_relationship"
                                        class="settings-select themed-select"
                                        style="cursor: pointer;" required>
                                        <option value="" disabled
                                            <?= $user['emergency_relationship'] === '' ? 'selected' : '' ?>>
                                            Select relationship
                                        </option>
                                        <?php foreach ($relationshipOptions as $rel): ?>
                                            <option value="<?= htmlspecialchars($rel, ENT_QUOTES, 'UTF-8') ?>"
                                                <?= $user['emergency_relationship'] === $rel ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($rel, ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="select-dropdown-arrow" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                    </span>
                                </div>
                            </div>
                            <div class="settings-field">
                                <label class="settings-label">Emergency Phone *</label>
                                <div class="input-prefix-wrapper">
                                    <span class="input-prefix-addon">+63</span>
                                <input type="tel" name="emergency_phone" class="settings-input" value="<?= htmlspecialchars(preg_replace('/^(?:\+?63|0)/', '', $user['emergency_phone'] ?? '')) ?>" placeholder="9XXXXXXXXX" inputmode="numeric" minlength="10" maxlength="10" pattern="9[0-9]{9}" required>
                                </div>
                            </div>
                        </div>

                        <h3 class="settings-section-title">Identification Documents</h3>
                        <div class="settings-grid-2">
                            <!-- School ID Dropzone -->
                            <div>
                                <label class="settings-label" style="margin-bottom: 6px; display:block;">School ID</label>
                                <div class="settings-dropzone" id="dropzone_school_id" onclick="openIdPicker(event,'school_id_input')" ondragover="handleDragOver(event, this)" ondragleave="handleDragLeave(this)" ondrop="handleDrop(event, 'school_id_input', 'school_id_label', 'school_id_preview', 'dropzone_school_id_empty', 'dropzone_school_id_filled', 'school_id_label_filled')">
                                    <div id="dropzone_school_id_empty" style="<?= !empty($user['school_id_file']) ? 'display:none;' : '' ?>">
                                        <svg class="dropzone-icon" viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#camera-icon"/></svg>
                                        <div id="school_id_label" class="dropzone-filename">No file chosen</div>
                                        <div class="dropzone-hint">Drag and drop your file here, or browse</div>
                                        <div class="dropzone-btn-row">
                                            <button type="button" class="dropzone-btn">
                                                Upload File
                                            </button>
                                        </div>
                                    </div>

                                    <div id="dropzone_school_id_filled" class="dropzone-active-state" style="<?= empty($user['school_id_file']) ? 'display:none;' : '' ?>">
                                        <div class="dropzone-preview-wrap">
                                            <img id="school_id_preview" class="dropzone-preview-img" src="<?= !empty($user['school_id_file']) ? htmlspecialchars($user['school_id_file']) : '' ?>" alt="School ID Preview">
                                        </div>
                                        <div id="school_id_label_filled" class="dropzone-filename"><?= !empty($user['school_id_file']) ? htmlspecialchars(basename($user['school_id_file'])) : '' ?></div>
                                        <div class="dropzone-btn-row" style="margin-top: 8px; display:flex; gap:8px; justify-content:center;">
                                            <button type="button" class="dropzone-btn">
                                                Change Photo
                                            </button>
                                            <button type="button" class="dropzone-btn dropzone-btn-danger" onclick="removeIdPhoto('school_id')">
                                                Remove Photo
                                            </button>
                                        </div>
                                    </div>
                                    <input type="file" id="school_id_input" name="school_id" accept="image/*" style="display: none;" onchange="previewIdFile(this, 'school_id_label', 'school_id_preview', 'dropzone_school_id_empty', 'dropzone_school_id_filled', 'school_id_label_filled')">
                                </div>
                            </div>

                            <!-- Government ID Dropzone -->
                            <div>
                                <label class="settings-label" style="margin-bottom: 6px; display:block;">Government ID</label>
                                <div class="settings-dropzone" id="dropzone_gov_id" onclick="openIdPicker(event,'gov_id_input')" ondragover="handleDragOver(event, this)" ondragleave="handleDragLeave(this)" ondrop="handleDrop(event, 'gov_id_input', 'gov_id_label', 'gov_id_preview', 'dropzone_gov_id_empty', 'dropzone_gov_id_filled', 'gov_id_label_filled')">
                                    <div id="dropzone_gov_id_empty" style="<?= !empty($user['gov_id_file']) ? 'display:none;' : '' ?>">
                                        <svg class="dropzone-icon" viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#camera-icon"/></svg>
                                        <div id="gov_id_label" class="dropzone-filename">No file chosen</div>
                                        <div class="dropzone-hint">Drag and drop your file here, or browse</div>
                                        <div class="dropzone-btn-row">
                                            <button type="button" class="dropzone-btn">
                                                Upload File
                                            </button>
                                        </div>
                                    </div>

                                    <div id="dropzone_gov_id_filled" class="dropzone-active-state" style="<?= empty($user['gov_id_file']) ? 'display:none;' : '' ?>">
                                        <div class="dropzone-preview-wrap">
                                            <img id="gov_id_preview" class="dropzone-preview-img" src="<?= !empty($user['gov_id_file']) ? htmlspecialchars($user['gov_id_file']) : '' ?>" alt="Government ID Preview">
                                        </div>
                                        <div id="gov_id_label_filled" class="dropzone-filename"><?= !empty($user['gov_id_file']) ? htmlspecialchars(basename($user['gov_id_file'])) : '' ?></div>
                                        <div class="dropzone-btn-row" style="margin-top: 8px; display:flex; gap:8px; justify-content:center;">
                                            <button type="button" class="dropzone-btn">
                                                Change Photo
                                            </button>
                                            <button type="button" class="dropzone-btn dropzone-btn-danger" onclick="removeIdPhoto('gov_id')">
                                                Remove Photo
                                            </button>
                                        </div>
                                    </div>
                                    <input type="file" id="gov_id_input" name="gov_id" accept="image/*" style="display: none;" onchange="previewIdFile(this, 'gov_id_label', 'gov_id_preview', 'dropzone_gov_id_empty', 'dropzone_gov_id_filled', 'gov_id_label_filled')">
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px;">
                            <?php if (!$mandatoryProfileSetup): ?><button type="button" class="btn-settings-secondary" onclick="cancelEditProfile()">Cancel</button><?php endif; ?>
                            <button type="submit" class="btn-settings-primary" id="profileSaveButton" disabled aria-disabled="true">Save Changes</button>
                        </div>
                        <p class="profile-save-hint" id="profileSaveHint" role="status">Complete every required field and upload both required IDs to enable Save Changes.</p>
                    </form>
                </div>

                <!-- TAB 2: ACCOUNT AND SECURITY -->
                <div id="tab-security" class="settings-tab-content">
                    <div class="settings-card">
                        <h3 class="settings-section-title" style="margin-top:0;">Manage Account</h3>
                        <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 0; margin-top:6px;">
                            <div>
                                <strong style="color: #3b2820; font-size: 0.96rem;">Password</strong>
                                <p style="margin: 3px 0 0; font-size: 0.85rem; color: #78665c;">Modify your account password securely.</p>
                            </div>
                            <button type="button" class="btn-settings-secondary" onclick="togglePasswordFrame()">Change password</button>
                        </div>

                        <div id="passwordFrame" class="password-box">
                            <form action="settings.php" method="POST">
                                <input type="hidden" name="action" value="update_password">
                                <div class="settings-field" style="margin-bottom: 14px;">
                                    <label class="settings-label">Current password *</label>
                                    <input type="password" name="current_password" class="settings-input" placeholder="Enter current password" required>
                                </div>
                                <div class="settings-grid-2" style="margin-bottom: 18px;">
                                    <div class="settings-field">
                                        <label class="settings-label">New password *</label>
                                        <input type="password" name="new_password" class="settings-input" placeholder="Enter new password (min. 8 characters)" required minlength="8">
                                    </div>
                                    <div class="settings-field">
                                        <label class="settings-label">Confirm new password *</label>
                                        <input type="password" name="confirm_password" class="settings-input" placeholder="Confirm new password" required minlength="8">
                                    </div>
                                </div>
                                <div style="display: flex; gap: 10px;">
                                    <button type="submit" class="btn-settings-primary">Update Password</button>
                                    <button type="button" class="btn-settings-secondary" onclick="togglePasswordFrame()">Cancel</button>
                                </div>
                            </form>
                        </div>

                        <div class="danger-zone-box">
                            <h4 style="color: #dc2626; font-size: 1.05rem; margin:0;">Danger Zone</h4>
                            <p style="font-size: 0.85rem; color: #78665c; margin:3px 0 14px;">Once you delete your account, your profile and bookings cannot be recovered. Please be certain.</p>
                            <button type="button" class="btn-settings-danger" onclick="openDeleteModal()">Delete Account</button>
                        </div>
                    </div>
                </div>

                <!-- TAB 3: NOTIFICATIONS -->
                <div id="tab-notifications" class="settings-tab-content">
                    <div class="settings-card">
                        <h3 class="settings-section-title" style="margin-top:0;">Notification Preferences</h3>
                        <p style="color: #78665c; font-size: 0.94rem; margin-bottom: 22px;">Choose how you want to be notified about lease updates, landlord messages, and announcements.</p>

                        <div class="switch-label">
                            <div>
                                <strong style="color: #3b2820; font-size: 0.95rem;">In-app Alerts</strong>
                                <p style="margin: 3px 0 0; font-size: 0.82rem; color: #8b7467;">Show in-app alerts for new updates. Notifications remain available in your inbox when alerts are off.</p>
                            </div>
                            <label class="switch-toggle" aria-label="In-app alerts toggle">
                                <input type="checkbox" id="togglePushNotifications" checked>
                                <span class="switch-slider"></span>
                            </label>
                        </div>

                        <div class="switch-label">
                            <div>
                                <strong style="color: #3b2820; font-size: 0.95rem;">Email Announcements</strong>
                                <p style="margin: 3px 0 0; font-size: 0.82rem; color: #8b7467;">Get email updates regarding university announcements and student dorm guidelines.</p>
                            </div>
                            <label class="switch-toggle" aria-label="Email announcements toggle">
                                <input type="checkbox" id="toggleEmailAnnouncements" checked>
                                <span class="switch-slider"></span>
                            </label>
                        </div>
                        <footer class="notification-preference-footer">
                            <p id="notification-preference-status" role="status" aria-live="polite"></p>
                        </footer>
                    </div>
                </div>
            </main>
        </div>

        <!-- Delete Account Confirmation Modal -->
        <div id="deleteModal" class="settings-modal-overlay">
            <div class="settings-modal-card">
                <h3 style="color: #dc2626; font-family:'Fraunces', serif; margin-top: 0; font-size: 1.35rem;">Delete Account Confirmation</h3>
                <p style="font-size: 0.88rem; color: #5f3425; line-height: 1.5; margin: 12px 0 18px;">To permanently delete your account, please type your registered email address below to confirm:</p>
                <form action="settings.php" method="POST">
                    <input type="hidden" name="action" value="delete_account">
                    <div style="margin-bottom: 18px;">
                        <input type="email" name="confirm_email" class="settings-input" style="width:100%;" placeholder="<?= htmlspecialchars($user['personal_email'] ?: ($user['email'] ?? '')); ?>" required>
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 10px;">
                        <button type="button" class="btn-settings-secondary" onclick="closeDeleteModal()">Cancel</button>
                        <button type="submit" class="btn-settings-danger">I understand, delete account</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Circular Profile Photo Cropper Modal -->
        <div id="cropperModal" class="cropper-modal-overlay">
            <div class="cropper-modal-card">
                <div class="cropper-modal-header">
                    <h3 class="cropper-modal-title">Crop Profile Photo</h3>
                    <button type="button" class="profile-toast-close" onclick="closeCropperModal()" aria-label="Close cropper">&times;</button>
                </div>
                <div class="cropper-canvas-wrap" id="cropperCanvasWrap">
                    <canvas id="cropperCanvas" width="300" height="300"></canvas>
                    <div class="cropper-mask"></div>
                </div>
                <div class="cropper-controls">
                    <button type="button" class="cropper-btn-round" onclick="adjustZoom(-0.15)" title="Zoom Out">−</button>
                    <input type="range" id="cropperZoom" class="cropper-zoom-slider" min="0.6" max="3" step="0.05" value="1" oninput="setZoom(parseFloat(this.value))">
                    <button type="button" class="cropper-btn-round" onclick="adjustZoom(0.15)" title="Zoom In">+</button>
                </div>
                <p style="margin: 0; font-size: 0.82rem; color: #78665c; text-align: center;">Drag the image to position inside the circle, and adjust zoom.</p>
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 4px;">
                    <button type="button" class="btn-settings-secondary" onclick="closeCropperModal()">Cancel</button>
                    <button type="button" class="btn-settings-primary" onclick="applyCroppedAvatar()">Apply Crop</button>
                </div>
            </div>
        </div>

        <!-- Lightbox Modal for ID Preview -->
        <div id="idLightbox" class="id-lightbox-overlay" onclick="closeLightbox(event)">
            <div class="id-lightbox-content">
                <button type="button" class="id-lightbox-close" onclick="closeLightboxDirect()" aria-label="Close full view">&times;</button>
                <img id="lightboxImg" class="id-lightbox-img" src="" alt="Full ID Preview">
                <p id="lightboxCaption" style="color: #ffffff; margin-top: 12px; font-weight: 600; font-size: 1rem;"></p>
            </div>
        </div>

        <script>
            window.UNISTAY_TENANT_PROFILE = <?= json_encode($tenantProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

            function updateTabIndicator(btn) {
                const indicator = document.getElementById('tabsIndicator');
                if (!indicator || !btn) return;
                indicator.style.transform = `translateX(${btn.offsetLeft}px)`;
                indicator.style.width = `${btn.offsetWidth}px`;
            }

            function switchTab(tabName) {
                document.querySelectorAll('.settings-tab-content').forEach(el => el.classList.remove('is-active'));
                document.querySelectorAll('.settings-tab-btn').forEach(el => {
                    el.classList.remove('is-active');
                    el.setAttribute('aria-selected', 'false');
                });

                const targetTab = document.getElementById('tab-' + tabName);
                const targetBtn = document.getElementById('btn-' + tabName);
                if (targetTab) targetTab.classList.add('is-active');
                if (targetBtn) {
                    targetBtn.classList.add('is-active');
                    targetBtn.setAttribute('aria-selected', 'true');
                    updateTabIndicator(targetBtn);
                }

                const titles = {
                    'profile': 'User Profile',
                    'security': 'Account and Security',
                    'notifications': 'Notification Settings'
                };
                const headerTitle = document.getElementById('headerTitle');
                if (headerTitle) headerTitle.innerText = titles[tabName] || 'Settings';
            }

            window.addEventListener('DOMContentLoaded', () => {
                const activeBtn = document.querySelector('.settings-tab-btn.is-active') || document.getElementById('btn-profile');
                if (activeBtn) updateTabIndicator(activeBtn);
            });
            window.addEventListener('resize', () => {
                const activeBtn = document.querySelector('.settings-tab-btn.is-active');
                if (activeBtn) updateTabIndicator(activeBtn);
            });

            function togglePasswordFrame() {
                const frame = document.getElementById('passwordFrame');
                if (frame) frame.classList.toggle('is-open');
            }

            const initialProfileData = {
                schoolIdFile: <?= json_encode($user['school_id_file'] ?? '') ?>,
                schoolIdName: <?= json_encode(!empty($user['school_id_file']) ? basename($user['school_id_file']) : '') ?>,
                govIdFile: <?= json_encode($user['gov_id_file'] ?? '') ?>,
                govIdName: <?= json_encode(!empty($user['gov_id_file']) ? basename($user['gov_id_file']) : '') ?>,
                avatarHtml: <?= json_encode(
                    !empty($effectiveAvatar)
                        ? '<img src="' . htmlspecialchars($effectiveAvatar) . '" alt="Avatar" referrerpolicy="no-referrer" onerror="this.style.display=\'none\';if(this.nextElementSibling)this.nextElementSibling.style.display=\'inline\';"><span style="display:none;">' . htmlspecialchars($userInitials) . '</span>'
                        : htmlspecialchars($userInitials)
                ) ?>
            };

            function cancelEditProfile() {
                const form = document.getElementById('profileEditForm');
                if (form) {
                    form.reset();
                }

                // Clear cropped avatar
                const croppedInput = document.getElementById('cropped_profile_photo');
                if (croppedInput) croppedInput.value = '';

                // Reset file inputs
                const photoInput = document.getElementById('profile_photo_input');
                if (photoInput) photoInput.value = '';
                const schoolInput = document.getElementById('school_id_input');
                if (schoolInput) schoolInput.value = '';
                const govInput = document.getElementById('gov_id_input');
                if (govInput) govInput.value = '';

                // Undo any staged ID photo removals
                const rmSchool = document.getElementById('remove_school_id');
                if (rmSchool) rmSchool.value = '0';
                const rmGov = document.getElementById('remove_gov_id');
                if (rmGov) rmGov.value = '0';

                // Restore avatar preview container
                const avatarPreview = document.getElementById('avatarPreviewContainer');
                if (avatarPreview) {
                    avatarPreview.innerHTML = initialProfileData.avatarHtml;
                }

                // Restore School ID dropzone
                const schoolEmpty = document.getElementById('dropzone_school_id_empty');
                const schoolFilled = document.getElementById('dropzone_school_id_filled');
                const schoolPreview = document.getElementById('school_id_preview');
                const schoolLabelFilled = document.getElementById('school_id_label_filled');
                const schoolLabelEmpty = document.getElementById('school_id_label');
                if (initialProfileData.schoolIdFile) {
                    if (schoolEmpty) schoolEmpty.style.display = 'none';
                    if (schoolFilled) schoolFilled.style.display = 'flex';
                    if (schoolPreview) schoolPreview.src = initialProfileData.schoolIdFile;
                    if (schoolLabelFilled) schoolLabelFilled.innerText = initialProfileData.schoolIdName;
                } else {
                    if (schoolEmpty) schoolEmpty.style.display = 'block';
                    if (schoolFilled) schoolFilled.style.display = 'none';
                    if (schoolPreview) schoolPreview.src = '';
                    if (schoolLabelEmpty) schoolLabelEmpty.innerText = 'No file chosen';
                }

                // Restore Gov ID dropzone
                const govEmpty = document.getElementById('dropzone_gov_id_empty');
                const govFilled = document.getElementById('dropzone_gov_id_filled');
                const govPreview = document.getElementById('gov_id_preview');
                const govLabelFilled = document.getElementById('gov_id_label_filled');
                const govLabelEmpty = document.getElementById('gov_id_label');
                if (initialProfileData.govIdFile) {
                    if (govEmpty) govEmpty.style.display = 'none';
                    if (govFilled) govFilled.style.display = 'flex';
                    if (govPreview) govPreview.src = initialProfileData.govIdFile;
                    if (govLabelFilled) govLabelFilled.innerText = initialProfileData.govIdName;
                } else {
                    if (govEmpty) govEmpty.style.display = 'block';
                    if (govFilled) govFilled.style.display = 'none';
                    if (govPreview) govPreview.src = '';
                    if (govLabelEmpty) govLabelEmpty.innerText = 'No file chosen';
                }

                // Switch view back to profileViewCard
                const viewCard = document.getElementById('profileViewCard');
                const editForm = document.getElementById('profileEditForm');
                if (viewCard && editForm) {
                    viewCard.style.display = 'block';
                    editForm.style.display = 'none';
                }
            }

            function toggleEditMode() {
                const viewCard = document.getElementById('profileViewCard');
                const editForm = document.getElementById('profileEditForm');
                if (!viewCard || !editForm) return;
                if (viewCard.style.display === 'none') {
                    viewCard.style.display = 'block';
                    editForm.style.display = 'none';
                } else {
                    viewCard.style.display = 'none';
                    editForm.style.display = 'block';
                }
            }

            // ==================== CROPPER LOGIC ====================
            let cropperImg = null;
            let cropperScale = 1;
            let cropperBaseScale = 1;
            let cropperOffsetX = 0;
            let cropperOffsetY = 0;
            let isDragging = false;
            let dragStartX = 0;
            let dragStartY = 0;

            function initCropper(input) {
                if (input.files && input.files[0]) {
                    const file = input.files[0];
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        cropperImg = new Image();
                        cropperImg.onload = function() {
                            const modal = document.getElementById('cropperModal');
                            if (modal) modal.classList.add('is-open');

                            // Base scale ensures image covers at least the 200px circle
                            cropperBaseScale = Math.max(200 / cropperImg.width, 200 / cropperImg.height);
                            cropperScale = cropperBaseScale;
                            document.getElementById('cropperZoom').value = 1;

                            // Center image on (150, 150)
                            cropperOffsetX = 150 - (cropperImg.width * cropperScale) / 2;
                            cropperOffsetY = 150 - (cropperImg.height * cropperScale) / 2;

                            drawCropper();
                            bindCropperEvents();
                        };
                        cropperImg.src = e.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            }

            function drawCropper() {
                const canvas = document.getElementById('cropperCanvas');
                if (!canvas || !cropperImg) return;
                const ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, canvas.width, canvas.height);

                const drawW = cropperImg.width * cropperScale;
                const drawH = cropperImg.height * cropperScale;
                ctx.drawImage(cropperImg, cropperOffsetX, cropperOffsetY, drawW, drawH);
            }

            function adjustZoom(delta) {
                const slider = document.getElementById('cropperZoom');
                if (!slider) return;
                let val = parseFloat(slider.value) + delta;
                val = Math.max(parseFloat(slider.min), Math.min(parseFloat(slider.max), val));
                slider.value = val;
                setZoom(val);
            }

            function setZoom(factor) {
                if (!cropperImg) return;
                const oldScale = cropperScale;
                cropperScale = cropperBaseScale * factor;

                // Keep center point (150, 150) stationary
                const centerRelX = (150 - cropperOffsetX) / oldScale;
                const centerRelY = (150 - cropperOffsetY) / oldScale;

                cropperOffsetX = 150 - centerRelX * cropperScale;
                cropperOffsetY = 150 - centerRelY * cropperScale;
                drawCropper();
            }

            let eventsBound = false;
            function bindCropperEvents() {
                if (eventsBound) return;
                eventsBound = true;
                const wrap = document.getElementById('cropperCanvasWrap');

                function startDrag(clientX, clientY) {
                    isDragging = true;
                    dragStartX = clientX;
                    dragStartY = clientY;
                }
                function moveDrag(clientX, clientY) {
                    if (!isDragging) return;
                    const dx = clientX - dragStartX;
                    const dy = clientY - dragStartY;
                    dragStartX = clientX;
                    dragStartY = clientY;
                    cropperOffsetX += dx;
                    cropperOffsetY += dy;
                    drawCropper();
                }
                function endDrag() {
                    isDragging = false;
                }

                wrap.addEventListener('mousedown', e => startDrag(e.clientX, e.clientY));
                window.addEventListener('mousemove', e => moveDrag(e.clientX, e.clientY));
                window.addEventListener('mouseup', endDrag);

                wrap.addEventListener('touchstart', e => {
                    if (e.touches.length === 1) {
                        startDrag(e.touches[0].clientX, e.touches[0].clientY);
                    }
                }, { passive: true });
                window.addEventListener('touchmove', e => {
                    if (e.touches.length === 1) {
                        moveDrag(e.touches[0].clientX, e.touches[0].clientY);
                    }
                }, { passive: true });
                window.addEventListener('touchend', endDrag);

                // Wheel zoom support
                wrap.addEventListener('wheel', e => {
                    e.preventDefault();
                    adjustZoom(e.deltaY < 0 ? 0.08 : -0.08);
                }, { passive: false });
            }

            function applyCroppedAvatar() {
                if (!cropperImg) return;
                // High-resolution offscreen export (400x400)
                const offscreen = document.createElement('canvas');
                offscreen.width = 400;
                offscreen.height = 400;
                const ctx2 = offscreen.getContext('2d');

                // Source rectangle mapped from the 200px preview circle centered at (150, 150)
                const sx = (150 - cropperOffsetX - 100) / cropperScale;
                const sy = (150 - cropperOffsetY - 100) / cropperScale;
                const sDim = 200 / cropperScale;

                ctx2.drawImage(cropperImg, sx, sy, sDim, sDim, 0, 0, 400, 400);

                const dataUrl = offscreen.toDataURL('image/png', 0.95);
                document.getElementById('cropped_profile_photo').value = dataUrl;

                const preview = document.getElementById('avatarPreviewContainer');
                if (preview) {
                    preview.innerHTML = '<img src="' + dataUrl + '" style="width:100%;height:100%;object-fit:cover;" alt="Avatar">';
                }
                closeCropperModal();
            }

            function closeCropperModal() {
                const modal = document.getElementById('cropperModal');
                if (modal) modal.classList.remove('is-open');
            }

            // ==================== ID PHOTO PREVIEW ====================
            function openIdPicker(event, inputId) {
                if (event.target.closest('.dropzone-btn-danger') || event.target.tagName === 'INPUT') return;
                const input = document.getElementById(inputId);
                if (input) input.click();
            }

            function previewIdFile(input, labelId, imgId, emptyBoxId, filledBoxId, filledLabelId) {
                if (!input.files || !input.files[0]) return;
                const file = input.files[0];

                // 1. Check file size (min 10KB, max 10MB)
                if (file.size < 10 * 1024) {
                    alert("The selected file is too small (" + Math.round(file.size / 1024) + " KB) or blank. Please upload a clear photo of your ID (minimum 10 KB).");
                    input.value = '';
                    return;
                }
                if (file.size > 10 * 1024 * 1024) {
                    alert("The selected file exceeds the 10 MB limit. Please upload a smaller image file.");
                    input.value = '';
                    return;
                }

                // 2. Check file type
                const validTypes = ['image/jpeg', 'image/png', 'image/webp'];
                if (!validTypes.includes(file.type)) {
                    alert("Invalid file format. Please upload a JPG, PNG, or WEBP photo of your ID.");
                    input.value = '';
                    return;
                }

                // 3. Check image dimensions via Image object
                const reader = new FileReader();
                reader.onload = function(e) {
                    const testImg = new Image();
                    testImg.onload = function() {
                        if (testImg.naturalWidth < 200 || testImg.naturalHeight < 120) {
                            alert("The image resolution (" + testImg.naturalWidth + "×" + testImg.naturalHeight + "px) is too small to be a legible ID. Minimum required is 200×120 pixels.");
                            input.value = '';
                            return;
                        }
                        const previewImg = document.getElementById(imgId);
                        if (previewImg) previewImg.src = e.target.result;
                        const emptyBox = document.getElementById(emptyBoxId);
                        if (emptyBox) emptyBox.style.display = 'none';
                        const filledBox = document.getElementById(filledBoxId);
                        if (filledBox) filledBox.style.display = 'flex';
                        const filledLabel = document.getElementById(filledLabelId);
                        if (filledLabel) filledLabel.innerText = file.name;
                        const label = document.getElementById(labelId);
                        if (label) label.innerText = file.name;

                        // A newly chosen file cancels any staged removal for this ID
                        const removeFlag = document.getElementById('remove_' + input.id.replace('_input', ''));
                        if (removeFlag) removeFlag.value = '0';
                    };
                    testImg.onerror = function() {
                        alert("Could not load the file as an image. Please ensure it is a valid photo.");
                        input.value = '';
                    };
                    testImg.src = e.target.result;
                };
                reader.readAsDataURL(file);
            }

            // Stage removal of an ID photo: the dropzone goes back to the plain upload area.
            // The file is only deleted on the server when the form is saved.
            function removeIdPhoto(key) {
                // key is 'school_id' or 'gov_id'
                const input = document.getElementById(key + '_input');
                if (input) input.value = '';
                const flag = document.getElementById('remove_' + key);
                if (flag) flag.value = '1';

                const preview = document.getElementById(key + '_preview');
                if (preview) preview.removeAttribute('src');
                const filledLabel = document.getElementById(key + '_label_filled');
                if (filledLabel) filledLabel.innerText = '';
                const emptyLabel = document.getElementById(key + '_label');
                if (emptyLabel) emptyLabel.innerText = 'No file chosen';

                const filled = document.getElementById('dropzone_' + key + '_filled');
                const empty = document.getElementById('dropzone_' + key + '_empty');
                if (filled) filled.style.display = 'none';
                if (empty) empty.style.display = 'block';
            }

            function handleDragOver(e, dropzone) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('dragover');
            }

            function handleDragLeave(dropzone) {
                dropzone.classList.remove('dragover');
            }

            function handleDrop(e, inputId, labelId, imgId, emptyBoxId, filledBoxId, filledLabelId) {
                e.preventDefault();
                e.stopPropagation();
                const dropzone = e.currentTarget;
                dropzone.classList.remove('dragover');

                if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                    const fileInput = document.getElementById(inputId);
                    fileInput.files = e.dataTransfer.files;
                    previewIdFile(fileInput, labelId, imgId, emptyBoxId, filledBoxId, filledLabelId);
                }
            }

            // ==================== LIGHTBOX MODAL (Smooth Transitions) ====================
            function openLightbox(src, caption) {
                const overlay = document.getElementById('idLightbox');
                const img = document.getElementById('lightboxImg');
                const cap = document.getElementById('lightboxCaption');
                if (overlay && img) {
                    img.src = src;
                    if (cap) cap.innerText = caption || 'ID Preview';
                    overlay.classList.remove('is-closing');
                    void overlay.offsetWidth; // Force reflow for smooth entrance
                    overlay.classList.add('is-open');
                    document.body.style.overflow = 'hidden';
                }
            }

            function closeLightboxDirect() {
                const overlay = document.getElementById('idLightbox');
                if (!overlay || !overlay.classList.contains('is-open')) return;
                overlay.classList.add('is-closing');
                setTimeout(() => {
                    overlay.classList.remove('is-open', 'is-closing');
                    document.body.style.overflow = '';
                }, 240);
            }

            function closeLightbox(e) {
                if (e.target && e.target.id === 'idLightbox') {
                    closeLightboxDirect();
                }
            }
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    closeLightboxDirect();
                }
            });

            // Dropdown Arrow Flip/Unflip listeners (faces up initially, faces down when open, faces up on close/outside click)
            document.addEventListener('DOMContentLoaded', () => {
                document.querySelectorAll('.select-with-icon-wrapper').forEach(wrap => {
                    const select = wrap.querySelector('select');
                    if (!select) return;

                    select.addEventListener('mousedown', () => {
                        wrap.dataset.wasDropped = wrap.classList.contains('is-dropped') ? '1' : '0';
                    });

                    select.addEventListener('click', () => {
                        if (wrap.dataset.wasDropped === '1') {
                            wrap.classList.remove('is-dropped');
                        } else {
                            wrap.classList.add('is-dropped');
                        }
                    });

                    select.addEventListener('change', () => wrap.classList.remove('is-dropped'));
                    select.addEventListener('blur', () => wrap.classList.remove('is-dropped'));
                });

                document.addEventListener('click', (e) => {
                    document.querySelectorAll('.select-with-icon-wrapper.is-dropped').forEach(wrap => {
                        if (!wrap.contains(e.target)) {
                            wrap.classList.remove('is-dropped');
                        }
                    });
                });

                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                        document.querySelectorAll('.select-with-icon-wrapper.is-dropped').forEach(wrap => {
                            wrap.classList.remove('is-dropped');
                        });
                    }
                });
            });

            function openDeleteModal() {
                const modal = document.getElementById('deleteModal');
                if (modal) modal.classList.add('is-open');
            }
            function closeDeleteModal() {
                const modal = document.getElementById('deleteModal');
                if (modal) modal.classList.remove('is-open');
            }

            // Close delete modal on outside backdrop click
            const delModal = document.getElementById('deleteModal');
            if (delModal) {
                delModal.addEventListener('click', function(e) {
                    if (e.target === delModal) closeDeleteModal();
                });
            }

            window.addEventListener('DOMContentLoaded', function() {
                const params = new URLSearchParams(window.location.search);
                if (params.get('notice') === 'verification') {
                    switchTab('profile');
                    const result = document.getElementById('verification-result') || document.querySelector('#profileViewCard .profile-view-name');
                    if (result) {
                        result.id = 'verification-result'; result.tabIndex = -1;
                        result.focus({preventScroll:true});
                        result.scrollIntoView({block:'center',behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'});
                    }
                }
                if (params.get('edit') === '1' || params.get('mode') === 'edit' || window.location.hash === '#edit') {
                    switchTab('profile');
                    const viewCard = document.getElementById('profileViewCard');
                    const editForm = document.getElementById('profileEditForm');
                    if (viewCard && editForm) {
                        viewCard.style.display = 'none';
                        editForm.style.display = 'block';
                        editForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }
            });
        </script>

        <script>
        window.UNISTAY_NOTIFICATION_ACCOUNT = <?= json_encode(['id' => (int)($_SESSION['user_id'] ?? 0), 'role' => 'tenant']) ?>;
        </script>

        <script src="../assets/js/profile-completion-gate.js?v=1"></script>
        <script src="../assets/js/notification-preferences.js?v=2"></script>
        <script src="../assets/js/tenant/notifications.js?v=7"></script>
    </body>
</html>
