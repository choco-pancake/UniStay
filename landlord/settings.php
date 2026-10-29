<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

$authenticatedUser = require_signed_in('landlord');
$mandatoryProfileSetup = ($_SESSION['profile_onboarding_phase'] ?? '') === 'editing';
$pdo = database();
$user_id = (int)($_SESSION['user_id'] ?? 0);

$successMessage = '';
$errorMessage = '';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function validate_landlord_id_image(array $file): ?string {
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


// Handle Actions
if ($requestMethod === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_profile') {
        $firstName     = trim($_POST['first_name'] ?? '');
        $middleName    = trim($_POST['middle_name'] ?? '');
        $lastName      = trim($_POST['last_name'] ?? '');
        $phoneNumber   = trim($_POST['phone_number'] ?? '');
        $facebookLink  = trim($_POST['facebook_link'] ?? '');
        $personalEmail = trim($_POST['personal_email'] ?? '');

        $phoneDigits = preg_replace('/[^\d]/', '', $phoneNumber);
        $phoneDigits = preg_replace('/^(?:63|0)/', '', $phoneDigits);
        $phoneNumber = $phoneDigits !== '' ? '+63' . $phoneDigits : '';
        $facebookHandle = preg_replace('#^(?:https?://)?(?:www\.)?facebook\.com/#i', '', $facebookLink);
        $facebookHandle = ltrim($facebookHandle, '/');
        $facebookLink = $facebookHandle !== '' ? 'https://facebook.com/' . $facebookHandle : '';

        // Validation
        if ($firstName === '') {
            $errorMessage = "First name is required.";
        } elseif ($lastName === '') {
            $errorMessage = "Last name is required.";
        } elseif (!preg_match('/^\+639\d{9}$/', $phoneNumber)) {
            $errorMessage = "Enter a valid 10-digit Philippine mobile number beginning with 9.";
        } elseif ($facebookLink === '') {
            $errorMessage = "Facebook link is required so tenants can contact you.";
        } elseif ($personalEmail === '' || !filter_var($personalEmail, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = "A valid email address is required.";
        }

        if (empty($errorMessage) && isset($_FILES['gov_id'])) {
            $errorMessage = validate_landlord_id_image($_FILES['gov_id']) ?? '';
        }
        $currentFiles = $pdo->prepare('SELECT profile_photo, photo, gov_id_file FROM users WHERE id = ?');
        $currentFiles->execute([$user_id]);
        $storedFiles = $currentFiles->fetch() ?: [];
        $profilePhotoPath = (string)($storedFiles['profile_photo'] ?: ($storedFiles['photo'] ?? ''));
        $govIdPath = (string)($storedFiles['gov_id_file'] ?? '');
        $newGovIdUploaded = false;
        $removeGovId = ($_POST['remove_gov_id'] ?? '0') === '1';
        $uploadDir = __DIR__ . '/../uploads/';
        if (empty($errorMessage) && !is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $croppedData = (string)($_POST['cropped_profile_photo'] ?? '');
        if (empty($errorMessage) && $croppedData !== '') {
            $decoded = preg_match('#^data:image/(?:png|jpeg|jpg|webp);base64,#i', $croppedData)
                ? base64_decode(explode(',', $croppedData, 2)[1], true) : false;
            if ($decoded === false || strlen($decoded) > 10 * 1024 * 1024 || @getimagesizefromstring($decoded) === false) {
                $errorMessage = 'Please choose a valid profile image under 10 MB.';
            } else {
                $fileName = 'avatar_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.png';
                if (file_put_contents($uploadDir . $fileName, $decoded) === false) $errorMessage = 'Profile photo could not be saved.';
                else $profilePhotoPath = 'uploads/' . $fileName;
            }
        } elseif (empty($errorMessage) && isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || @getimagesize($_FILES['profile_photo']['tmp_name']) === false) {
                $errorMessage = 'Please choose a valid profile image.';
            } else {
                $fileName = 'profile_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadDir . $fileName)) $profilePhotoPath = 'uploads/' . $fileName;
                else $errorMessage = 'Profile photo could not be saved.';
            }
        }
        if (empty($errorMessage) && isset($_FILES['gov_id']) && $_FILES['gov_id']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['gov_id']['name'], PATHINFO_EXTENSION));
            $fileName = 'govid_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file($_FILES['gov_id']['tmp_name'], $uploadDir . $fileName)) {
                $govIdPath = '../uploads/' . $fileName;
                $newGovIdUploaded = true;
            } else $errorMessage = 'Government ID could not be saved.';
        } elseif (empty($errorMessage) && $removeGovId) $govIdPath = '';

        if (empty($errorMessage) && $govIdPath === '') {
            $errorMessage = 'Upload a government ID before saving your completed profile.';
        }

        if (empty($errorMessage)) {
        try {
            // Determine verification status: if Government ID uploaded, set status to pending for admin review
            $stmtCurr = $pdo->prepare("SELECT verification_status, profile_completed FROM users WHERE id = ?");
            $stmtCurr->execute([$user_id]);
            $currentRow = $stmtCurr->fetch() ?: [];
            $currentStatus = (string)($currentRow['verification_status'] ?? 'unverified');
            $wasComplete = (int)($currentRow['profile_completed'] ?? 0) === 1;
            $isComplete = $firstName !== '' && $lastName !== '' && $phoneNumber !== ''
                && $facebookLink !== '' && $personalEmail !== '' && $govIdPath !== '';

            $newStatus = $govIdPath === '' ? 'unverified' : $currentStatus;
            $newNotes = null;
            if ($govIdPath !== '' && ($newGovIdUploaded || in_array($currentStatus, ['unverified', 'rejected'], true))) {
                $newStatus = 'pending';
                $newNotes = null; // clear prior rejection note
            }

            $updateStmt = $pdo->prepare("UPDATE users SET 
                first_name = ?, middle_name = ?, last_name = ?, phone_number = ?, 
                facebook_link = ?, personal_email = ?,
                profile_photo = ?, photo = ?, gov_id_file = ?,
                verification_status = ?, verification_notes = ?,
                profile_completed = ?
                WHERE id = ?");

            $updateStmt->execute([
                $firstName,
                $middleName !== '' ? $middleName : null,
                $lastName !== '' ? $lastName : null,
                $phoneNumber,
                $facebookLink,
                $personalEmail,
                $profilePhotoPath,
                $profilePhotoPath,
                $govIdPath,
                $newStatus,
                $newNotes,
                (int)$isComplete,
                $user_id
            ]);

            // Update session
            if (isset($_SESSION['user'])) {
                $_SESSION['user']['name'] = trim($firstName . ' ' . $lastName) ?: $firstName;
                if ($profilePhotoPath !== '') {
                    $_SESSION['user']['photo'] = $profilePhotoPath;
                }
            }

            if ($isComplete && !$wasComplete) {
                unset($_SESSION['profile_completion_required'], $_SESSION['profile_onboarding_phase']);
                $_SESSION['start_landlord_tour'] = true;
                header('Location: dashboard.php', true, 303);
                exit;
            }

            $successMessage = ($newStatus === 'pending')
                ? "Profile updated successfully! Your Government ID has been submitted for admin verification."
                : "Profile updated successfully!";
        } catch (\PDOException $e) {
            $errorMessage = "Database update failed: " . $e->getMessage();
        }
        }
    } elseif ($_POST['action'] === 'update_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword     = (string)($_POST['new_password'] ?? '');
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
                    $up = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $up->execute([$newHash, $user_id]);
                    $successMessage = "Password successfully updated!";
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
                // Delete landlord's properties, rooms, bookings, and notifications
                $propIds = $pdo->query("SELECT id FROM properties WHERE landlord_id = " . (int)$user_id)->fetchAll(PDO::FETCH_COLUMN) ?: [];
                if (!empty($propIds)) {
                    $inProps = implode(',', array_map('intval', $propIds));
                    $pdo->exec("DELETE FROM bookings WHERE property_id IN ($inProps)");
                    $pdo->exec("DELETE FROM rooms WHERE property_id IN ($inProps)");
                    $pdo->exec("DELETE FROM properties WHERE landlord_id = " . (int)$user_id);
                }
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

// Fetch Current Landlord Data
$user = [
    'first_name'          => '',
    'middle_name'         => '',
    'last_name'           => '',
    'phone_number'        => '',
    'facebook_link'       => '',
    'personal_email'      => '',
    'profile_photo'       => '',
    'gov_id_file'         => '',
    'verification_status' => 'unverified',
    'verification_notes'  => '',
    'verified_at'         => null,
    'role'                => 'Landlord'
];

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $user = array_merge($user, $row);
    }
    foreach ($user as $k => $v) {
        if ($v === null) $user[$k] = '';
    }
} catch (\Exception $e) {}

$govIdDisplayUrl = (string)($user['gov_id_file'] ?? '');
if ($govIdDisplayUrl !== '' && str_contains(str_replace('\\', '/', $govIdDisplayUrl), 'uploads/landlord-applications/')) {
    try {
        $applicationDocument = $pdo->prepare('SELECT id FROM landlord_applications WHERE user_id = ? AND government_id_file = ? LIMIT 1');
        $applicationDocument->execute([$user_id, $govIdDisplayUrl]);
        $applicationDocumentId = (int)$applicationDocument->fetchColumn();
        if ($applicationDocumentId > 0) $govIdDisplayUrl = '../admin/application-document.php?id=' . $applicationDocumentId;
    } catch (Throwable $error) {
        error_log('UniStay landlord application document lookup failed: ' . $error->getMessage());
    }
}

$landlordProfile = get_authenticated_profile($pdo, $user_id);
$userInitials = $landlordProfile['initials'] ?? 'L';
$effectiveAvatar = $landlordProfile['photo'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings · UniStay Landlord</title>
    <link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/common.css?v=32">
    <link rel="stylesheet" href="../assets/css/settings.css?v=5">
    <style>
        /* Specific enhancements for Landlord Verification Badges */
        .verification-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 999px;
            letter-spacing: 0.2px;
        }
        .verification-badge.is-verified {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .verification-badge.is-pending {
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        .verification-badge.is-rejected {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .verification-badge.is-unverified {
            background: #f5f5f4;
            color: #57534e;
            border: 1px solid #e7e5e4;
        }
        .verification-callout {
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 0.92rem;
            line-height: 1.45;
        }
        .verification-callout.is-pending {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #78350f;
        }
        .verification-callout.is-rejected {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }
        .doc-preview-card {
            background: #fcf8f5;
            border: 1px solid #ebd9cb;
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }
        .doc-preview-thumb {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            background: #ebd9cb;
            display: grid;
            place-items: center;
            overflow: hidden;
            flex-shrink: 0;
            font-size: 20px;
        }
        .doc-preview-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        /* Modal for preview */
        .preview-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 99999;
            background: rgba(0,0,0,0.65);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .preview-modal.is-active {
            display: flex;
        }
        .preview-modal-box {
            background: #fff;
            border-radius: 18px;
            max-width: 650px;
            width: 100%;
            padding: 22px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.25);
            animation: zoomIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .preview-modal-img {
            max-width: 100%;
            max-height: 70vh;
            border-radius: 10px;
            display: block;
            margin: 14px auto 0;
            object-fit: contain;
            background: #f5f5f4;
        }
        @keyframes zoomIn {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
    </style>
    <script src="../assets/js/tenant/popover.js?v=2"></script>
    <script src="../assets/js/tenant/sidebar.js"></script>
    <script src="../assets/js/tenant/profile-menu.js?v=31"></script>
</head>
<body class="settings-page">
    <svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs>
        <symbol id="grid-icon" viewBox="0 0 24 24"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></symbol>
        <symbol id="home-icon" viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3Z"/></symbol>
        <symbol id="bed-icon" viewBox="0 0 24 24"><path d="M3 19V9h18v10M3 16h18M5 9V5h5v4m4 0V5h5v4"/></symbol>
        <symbol id="user-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></symbol>
        <symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
        <symbol id="bell-icon" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M10 20h4"/></symbol>
        <symbol id="bell-off-icon" viewBox="0 0 24 24"><path d="m3 3 18 18M10 20h4M6 6c-1 2 0 7-3 10h13M18 13V8a6 6 0 0 0-8-6"/></symbol>
        <symbol id="arrow-icon" viewBox="0 0 24 24"><path d="M4 12h16m-6-6 6 6-6 6"/></symbol>
        <symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
        <symbol id="close-icon" viewBox="0 0 24 24"><path d="m6 6 12 12M6 18 18 6"/></symbol>
        <symbol id="pencil-icon" viewBox="0 0 24 24"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></symbol>
        <symbol id="badge-icon" viewBox="0 0 24 24"><path d="m12 2 3 3 4-1 1 4 3 3-3 3-1 4-4 1-3 3-3-3-4-1-1-4-3-3 3-3 1-4 4 1Z"/><path d="m8 12 3 3 5-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="logout-icon" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    </defs></svg>

    <!-- Unified Landlord Sidebar Navigation -->
    <aside class="sidebar" aria-label="UniStay Landlord Portal">
        <div class="brand">
            <img src="../assets/images/tenant/logo.png" width="50" height="50" alt="UniStay logo">
            <span class="brand-name">UniStay</span>
        </div>
        <nav aria-label="Main navigation">
            <a href="dashboard.php" title="Dashboard"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
            <a href="properties.php" title="Properties"><svg aria-hidden="true"><use href="#home-icon"/></svg><span class="nav-label">Properties</span></a>
            <a href="tenants.php" title="Requests"><svg aria-hidden="true"><use href="#user-icon"/></svg><span class="nav-label">Requests</span></a>
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
            <h1 id="headerTitle">Landlord Profile</h1>
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
                    <div class="empty" id="popover-empty"><span class="empty-icon"><svg aria-hidden="true"><use href="#bell-off-icon"/></svg></span><strong>No new notifications</strong><p>We’ll let you know when student bookings arrive.</p></div>
                    <ul class="notification-list" id="popover-list" aria-label="Unread notifications" hidden></ul>
                    <a class="view-all" id="view-all" href="notifications.php?from=settings" aria-label="View all notifications">View all notifications <svg aria-hidden="true"><use href="#arrow-icon"/></svg></a>
                </section>
            </div>
        </header>

        <main id="settings-main" tabindex="-1">
            <!-- Alerts -->
            <?php if (!empty($successMessage)): ?>
                <div class="settings-alert is-success" role="alert">✓ <?= htmlspecialchars($successMessage) ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="settings-alert is-error" role="alert">⚠ <?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>

            <!-- Navigation Tabs -->
            <div class="settings-tabs" role="tablist">
                <div class="settings-tabs-indicator" id="landlordTabsIndicator"></div>
                <button type="button" class="settings-tab-btn is-active" onclick="switchTab('profile')" id="btn-profile" role="tab" aria-selected="true">Profile</button>
                <button type="button" class="settings-tab-btn" onclick="switchTab('security')" id="btn-security" role="tab" aria-selected="false" <?= $mandatoryProfileSetup ? 'disabled aria-disabled="true"' : '' ?>>Account & Security</button>
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
                                <h2 class="profile-view-name"><?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Landlord Profile') ?></h2>
                                <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
                                    <span class="profile-role-badge">Landlord</span>
                                    <?php 
                                    $vStatus = $user['verification_status'] ?? 'unverified';
                                    if ($vStatus === 'verified'): ?>
                                        <span class="verification-badge is-verified">✓ Verified Landlord</span>
                                    <?php elseif ($vStatus === 'pending'): ?>
                                        <span class="verification-badge is-pending">⏳ Under Review</span>
                                    <?php elseif ($vStatus === 'rejected'): ?>
                                        <span class="verification-badge is-rejected">⚠ Verification Rejected</span>
                                    <?php else: ?>
                                        <span class="verification-badge is-unverified">Action Required</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn-settings-secondary" onclick="toggleEditMode()">
                            <svg style="width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;" viewBox="0 0 24 24"><use href="#pencil-icon"/></svg> Edit Profile
                        </button>
                    </div>

                    <!-- Callouts for Verification Status -->
                    <?php if ($vStatus === 'pending'): ?>
                        <div class="verification-callout is-pending">
                            <span>⏳</span>
                            <div>
                                <strong>Government ID Submitted &amp; Pending Admin Review</strong>
                                <p style="margin:2px 0 0; font-size:0.86rem;">Our administrative team is reviewing your documents. Once approved, your listings and verified host badge will be activated.</p>
                            </div>
                        </div>
                    <?php elseif ($vStatus === 'rejected'): ?>
                        <div class="verification-callout is-rejected">
                            <span>⚠</span>
                            <div>
                                <strong>Verification Needs Attention</strong>
                                <p style="margin:2px 0 0; font-size:0.86rem;"><?= !empty($user['verification_notes']) ? htmlspecialchars($user['verification_notes']) : 'Please upload a clearer copy of your government ID by clicking Edit Profile.' ?></p>
                            </div>
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
                            <span class="info-display-label">Facebook Link</span>
                            <div class="info-display-val"><?= htmlspecialchars($user['facebook_link'] ?: 'None') ?></div>
                        </div>
                        <div class="info-display-group">
                            <span class="info-display-label">Email Address</span>
                            <div class="info-display-val"><?= htmlspecialchars($user['personal_email'] ?: ($user['email'] ?? 'Not specified')) ?></div>
                        </div>


                    </div>

                    <h3 class="settings-section-title">Uploaded Verification Documents</h3>
                    <div class="id-documents-grid"><div class="id-document-card">
                            <div class="id-document-meta">
                                <span class="id-document-title">Government ID</span>
                                <?php if (!empty($user['gov_id_file'])): ?>
                                    <button type="button" class="id-document-view-btn" onclick="openLightbox('<?= htmlspecialchars($govIdDisplayUrl) ?>', 'Government ID')">View full photo ↗</button>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($user['gov_id_file'])): ?>
                                <div class="id-document-thumb" onclick="openLightbox('<?= htmlspecialchars($govIdDisplayUrl) ?>', 'Government ID')">
                                    <img src="<?= htmlspecialchars($govIdDisplayUrl) ?>" alt="Government ID Preview">
                                </div>
                                <span style="font-size: 0.8rem; color: #8b7467; word-break: break-all;"><?= htmlspecialchars(basename($user['gov_id_file'])) ?></span>
                            <?php else: ?>
                                <div class="id-document-thumb" style="cursor: default; flex-direction: column; gap: 8px; color: #8b7467; font-size: 0.86rem;">
                                    <span style="font-size: 26px;">🪪</span>
                                    <span>No Government ID uploaded</span>
                                </div>
                            <?php endif; ?>
                        </div></div>
                </div>

                <!-- EDIT MODE FORM -->
                <form id="profileEditForm" action="settings.php" method="POST" enctype="multipart/form-data" class="settings-card" data-required-docs="gov_id" style="display: none;">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="existing_profile_photo" value="<?= htmlspecialchars($user['profile_photo'] ?: ($user['photo'] ?? '')) ?>">
                    <input type="hidden" name="cropped_profile_photo" id="cropped_profile_photo" value="">
                    <input type="hidden" name="existing_gov_id" value="<?= htmlspecialchars($user['gov_id_file']) ?>">
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
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h3l1.5-2h7L17 7h3v12H4z"/><circle cx="12" cy="13" r="3.5"/></svg>
                            </button>
                            <input type="file" id="profile_photo_input" name="profile_photo" accept="image/*" style="display: none;" onchange="initCropper(this)">
                        </div>
                        <div>
                            <h3 style="color: #3b2820; font-size: 1.15rem; margin-bottom: 4px;"><?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Update Profile') ?></h3>
                            <span class="profile-role-badge">Role: <?= htmlspecialchars($user['role'] ?? 'Landlord') ?></span>
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
                                <span class="field-icon" aria-hidden="true" title="Phone">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                </span>
                                <span class="input-prefix-addon">+63</span>
                                <input type="tel" name="phone_number" class="settings-input" value="<?= htmlspecialchars(preg_replace('/^(?:\+?63|0)/', '', $user['phone_number'] ?? '')) ?>" placeholder="9XXXXXXXXX" inputmode="numeric" minlength="10" maxlength="10" pattern="9[0-9]{9}" required>
                            </div>
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Facebook Link *</label>
                            <div class="input-prefix-wrapper">
                                <span class="field-icon" aria-hidden="true" title="Facebook">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                                </span>
                                <span class="input-prefix-addon">facebook.com/</span>
                                <input type="text" name="facebook_link" class="settings-input" value="<?= htmlspecialchars(preg_replace('#^(?:https?://)?(?:www\.)?facebook\.com/#i', '', $user['facebook_link'])) ?>" placeholder="username or profile ID" required>
                            </div>
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Email Address *</label>
                            <div class="input-with-icon-wrapper">
                                <span class="field-icon" aria-hidden="true" title="Email">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                                </span>
                                <input type="email" name="personal_email" class="settings-input" value="<?= htmlspecialchars($user['personal_email']) ?>" required>
                            </div>
                        </div>



</div>

                    <h3 class="settings-section-title">Identification Documents</h3>
                    <div class="settings-grid-2">
                        <!-- Government ID Dropzone -->
                        <div>
                            <label class="settings-label" style="margin-bottom: 6px; display:block;">Government ID</label>
                            <div class="settings-dropzone" id="dropzone_gov_id" onclick="openIdPicker(event,'gov_id_input')" ondragover="handleDragOver(event, this)" ondragleave="handleDragLeave(this)" ondrop="handleDrop(event, 'gov_id_input', 'gov_id_label', 'gov_id_preview', 'dropzone_gov_id_empty', 'dropzone_gov_id_filled', 'gov_id_label_filled')">
                                <div id="dropzone_gov_id_empty" style="<?= !empty($user['gov_id_file']) ? 'display:none;' : '' ?>">
                                    <svg class="dropzone-icon" viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
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
                                        <img id="gov_id_preview" class="dropzone-preview-img" src="<?= !empty($user['gov_id_file']) ? htmlspecialchars($govIdDisplayUrl) : '' ?>" alt="Government ID Preview">
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
                    <p class="profile-save-hint" id="profileSaveHint" role="status">Complete every required field and upload a government ID to enable Save Changes.</p>
                </form>
            </div>

            <!-- TAB 2: ACCOUNT & SECURITY -->
            <div id="tab-security" class="settings-tab-content">
                <div class="settings-card">
                    <h3 class="settings-section-title" style="margin-top:0;">Account Settings and Security</h3>
                    <p style="color: #78665c; font-size: 0.94rem; margin-bottom: 22px;">Manage your account credentials, security preferences, and data options.</p>

                    <div class="profile-view-identity" style="background:#fcf8f5; border:1px solid #ebd9cb; border-radius:14px; padding:16px 20px; margin-bottom:24px;">
                        <div class="profile-view-avatar" style="width:50px;height:50px;font-size:18px;">
                            <?php if (!empty($effectiveAvatar)): ?>
                                <img src="<?= htmlspecialchars($effectiveAvatar) ?>" alt="Avatar" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline';">
                                <span style="display:none;"><?= htmlspecialchars($userInitials) ?></span>
                            <?php else: ?>
                                <?= htmlspecialchars($userInitials) ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <strong style="color: #3b2820; font-size: 0.96rem;"><?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))); ?></strong><br>
                            <small style="color: #8b7467; font-size: 0.85rem;"><?= htmlspecialchars($user['personal_email'] ?: ($user['email'] ?? '')); ?></small>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 0; border-top:1px solid #f0e6e0; border-bottom:1px solid #f0e6e0;">
                        <div>
                            <strong style="color: #3b2820; font-size: 0.96rem;">Password</strong>
                            <p style="margin: 3px 0 0; font-size: 0.85rem; color: #78665c;">Modify your account password securely.</p>
                        </div>
                        <button type="button" class="btn-settings-secondary" onclick="togglePasswordFrame()">Change password?</button>
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
                        <h4 style="color: #dc2626; font-size: 1.05rem; margin-bottom: 4px;">Danger Zone</h4>
                        <p style="font-size: 0.85rem; color: #78665c; margin-bottom: 14px;">Once you delete your account, your profile and bookings cannot be recovered. Please be certain.</p>
                        <button type="button" class="btn-settings-danger" onclick="openDeleteModal()">Delete Account</button>
                    </div>
                </div>
            </div>

            <!-- TAB 3: NOTIFICATIONS -->
            <div id="tab-notifications" class="settings-tab-content">
                <div class="settings-card">
                    <h3 class="settings-section-title" style="margin-top:0;">Notification Preferences</h3>
                    <p style="color: #78665c; font-size: 0.94rem; margin-bottom: 24px;">Configure how you receive student booking inquiries, messages, and platform alerts.</p>

                    <div style="display:flex; flex-direction:column; gap:20px;">
                        <div class="switch-label">
                            <div>
                                <strong style="display:block; color:#292524; font-size:0.95rem;">Student Booking Inquiries</strong>
                                <span style="font-size:0.85rem; color:#78665c;">Receive instant notifications when students apply for a room</span>
                            </div>
                            <label class="switch-toggle" aria-label="Notification preference"><input type="checkbox" id="togglePushNotificationsLandlord" checked><span class="switch-slider"></span></label>
                        </div>
                        <div class="switch-label">
                            <div>
                                <strong style="display:block; color:#292524; font-size:0.95rem;">Verification Status Updates</strong>
                                <span style="font-size:0.85rem; color:#78665c;">Receive notification when your government ID is reviewed</span>
                            </div>
                            <label class="switch-toggle" aria-label="Notification preference"><input type="checkbox" id="toggleVerificationNotificationsLandlord" checked><span class="switch-slider"></span></label>
                        </div>
                        <div class="switch-label">
                            <div>
                                <strong style="display:block; color:#292524; font-size:0.95rem;">Email Summaries</strong>
                                <span style="font-size:0.85rem; color:#78665c;">Receive periodic occupancy and monthly earnings summaries</span>
                            </div>
                            <label class="switch-toggle" aria-label="Notification preference"><input type="checkbox" id="toggleEmailSummariesLandlord" checked><span class="switch-slider"></span></label>
                        </div>
                    </div>
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
                    <input type="email" name="confirm_email" class="settings-input" style="width:100%;" placeholder="<?= htmlspecialchars($user['personal_email'] ?? ''); ?>" required>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-settings-secondary" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="btn-settings-danger">I understand, delete account</button>
                </div>
            </form>
        </div>
    </div>

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
<div id="idLightbox" class="id-lightbox-overlay" onclick="closeLightbox(event)">
        <div class="id-lightbox-content">
            <button type="button" class="id-lightbox-close" onclick="closeLightboxDirect()" aria-label="Close full view">&times;</button>
            <img id="lightboxImg" class="id-lightbox-img" src="" alt="Full ID Preview">
            <p id="lightboxCaption" style="color: #ffffff; margin-top: 12px; font-weight: 600; font-size: 1rem;"></p>
        </div>
    </div>
    <script>
        function updateLandlordTabIndicator(btn) {
            const indicator = document.getElementById('landlordTabsIndicator');
            if (!indicator || !btn) return;
            indicator.style.transform = `translateX(${btn.offsetLeft}px)`;
            indicator.style.width = `${btn.offsetWidth}px`;
        }

        function switchTab(tabId) {
            document.querySelectorAll('.settings-tab-btn').forEach(btn => {
                btn.classList.remove('is-active');
                btn.setAttribute('aria-selected', 'false');
            });
            document.querySelectorAll('.settings-tab-content').forEach(content => {
                content.classList.remove('is-active');
            });

            const activeBtn = document.getElementById('btn-' + tabId);
            const activeTab = document.getElementById('tab-' + tabId);
            if (activeBtn && activeTab) {
                activeBtn.classList.add('is-active');
                activeBtn.setAttribute('aria-selected', 'true');
                activeTab.classList.add('is-active');
                updateLandlordTabIndicator(activeBtn);
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            const activeBtn = document.querySelector('.settings-tab-btn.is-active') || document.getElementById('btn-profile');
            if (activeBtn) updateLandlordTabIndicator(activeBtn);
        });
        window.addEventListener('resize', () => {
            const activeBtn = document.querySelector('.settings-tab-btn.is-active');
            if (activeBtn) updateLandlordTabIndicator(activeBtn);
        });

        function togglePasswordFrame() {
            const frame = document.getElementById('passwordFrame');
            if (frame) frame.classList.toggle('is-open');
        }

        const initialProfileData = {
            govIdFile: <?= json_encode($govIdDisplayUrl) ?>,
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
            const govInput = document.getElementById('gov_id_input');
            if (govInput) govInput.value = '';

            // Undo any staged ID photo removals
            const rmGov = document.getElementById('remove_gov_id');
            if (rmGov) rmGov.value = '0';

            // Restore avatar preview container
            const avatarPreview = document.getElementById('avatarPreviewContainer');
            if (avatarPreview) {
                avatarPreview.innerHTML = initialProfileData.avatarHtml;
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
            // Stage the government ID change until Save Changes.
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
        const delModal = document.getElementById('deleteModal');
        if (delModal) {
            delModal.addEventListener('click', function(e) {
                if (e.target === delModal) closeDeleteModal();
            });
        }

        if (<?= json_encode($errorMessage !== '' && ($_POST['action'] ?? '') === 'update_profile') ?>) toggleEditMode();
        // Auto open edit mode if mode=edit or #edit in URL
        document.addEventListener('DOMContentLoaded', function() {
            var params = new URLSearchParams(window.location.search);
            if (params.get('mode') === 'edit' || window.location.hash === '#edit') {
                var viewCard = document.getElementById('profileViewCard');
                var editForm = document.getElementById('profileEditForm');
                if (viewCard && editForm) {
                    viewCard.style.display = 'none';
                    editForm.style.display = 'block';
                    editForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        });
    </script>
<script>
window.UNISTAY_TENANT_PROFILE = <?= json_encode($landlordProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.UNISTAY_NOTIFICATION_ACCOUNT = <?= json_encode(['id' => (int)($_SESSION['user_id'] ?? 0), 'role' => 'landlord']) ?>;
</script>
<script src="../assets/js/profile-completion-gate.js?v=1"></script>
<script src="../assets/js/notification-preferences.js?v=3"></script>
<script src="../assets/js/landlord-notifications.js?v=7"></script>
</body>
</html>
