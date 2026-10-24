<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

$authenticatedUser = require_signed_in('landlord');
$pdo = database();
$user_id = (int)($_SESSION['user_id'] ?? 0);

$successMessage = '';
$errorMessage = '';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Handle Actions
if ($requestMethod === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_profile') {
        $firstName     = trim($_POST['first_name'] ?? '');
        $middleName    = trim($_POST['middle_name'] ?? '');
        $lastName      = trim($_POST['last_name'] ?? '');
        $phoneNumber   = trim($_POST['phone_number'] ?? '');
        $facebookLink  = trim($_POST['facebook_link'] ?? '');
        $personalEmail = trim($_POST['personal_email'] ?? '');
        $dateOfBirth   = trim($_POST['date_of_birth'] ?? '');
        $gender        = trim($_POST['gender'] ?? '');

        // Validation
        if ($firstName === '') {
            $errorMessage = "First name is required.";
        } elseif ($phoneNumber === '') {
            $errorMessage = "Phone number is required.";
        } elseif ($facebookLink === '') {
            $errorMessage = "Facebook link is required so tenants can contact you.";
        } elseif ($personalEmail === '' || !filter_var($personalEmail, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = "A valid email address is required.";
        }

        $uploadDir = __DIR__ . '/../uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Profile Photo Upload
        $profilePhotoPath = $_POST['existing_profile_photo'] ?? '';
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $fileName = 'profile_' . $user_id . '_' . time() . '.' . $ext;
                $destPath = $uploadDir . $fileName;
                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $destPath)) {
                    $profilePhotoPath = '../uploads/' . $fileName;
                }
            }
        }

        // Government ID Upload
        $govIdPath = $_POST['existing_gov_id'] ?? '';
        $newGovIdUploaded = false;
        if (isset($_FILES['gov_id']) && $_FILES['gov_id']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['gov_id']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'])) {
                $fileName = 'govid_' . $user_id . '_' . time() . '.' . $ext;
                $destPath = $uploadDir . $fileName;
                if (move_uploaded_file($_FILES['gov_id']['tmp_name'], $destPath)) {
                    $govIdPath = '../uploads/' . $fileName;
                    $newGovIdUploaded = true;
                }
            }
        }

        try {
            // Determine verification status: if Government ID uploaded, set status to pending for admin review
            $stmtCurr = $pdo->prepare("SELECT verification_status FROM users WHERE id = ?");
            $stmtCurr->execute([$user_id]);
            $currentStatus = (string)($stmtCurr->fetchColumn() ?: 'unverified');

            $newStatus = $currentStatus;
            $newNotes = null;
            if ($govIdPath !== '' && ($newGovIdUploaded || in_array($currentStatus, ['unverified', 'rejected'], true))) {
                $newStatus = 'pending';
                $newNotes = null; // clear prior rejection note
            }

            $updateStmt = $pdo->prepare("UPDATE users SET 
                first_name = ?, middle_name = ?, last_name = ?, phone_number = ?, 
                facebook_link = ?, personal_email = ?, date_of_birth = ?, gender = ?, 
                profile_photo = ?, photo = ?, gov_id_file = ?,
                verification_status = ?, verification_notes = ?,
                profile_completed = 1
                WHERE id = ?");
            
            $updateStmt->execute([
                $firstName,
                $middleName !== '' ? $middleName : null,
                $lastName !== '' ? $lastName : null,
                $phoneNumber,
                $facebookLink,
                $personalEmail,
                $dateOfBirth !== '' ? $dateOfBirth : null,
                $gender !== '' ? $gender : null,
                $profilePhotoPath,
                $profilePhotoPath,
                $govIdPath,
                $newStatus,
                $newNotes,
                $user_id
            ]);

            // Update session
            if (isset($_SESSION['user'])) {
                $_SESSION['user']['name'] = trim($firstName . ' ' . $lastName) ?: $firstName;
                if ($profilePhotoPath !== '') {
                    $_SESSION['user']['photo'] = $profilePhotoPath;
                }
            }

            $successMessage = ($newStatus === 'pending')
                ? "Profile updated successfully! Your Government ID has been submitted for admin verification."
                : "Profile updated successfully!";
        } catch (\PDOException $e) {
            $errorMessage = "Database update failed: " . $e->getMessage();
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
    'date_of_birth'       => '',
    'gender'              => '',
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

$landlordProfile = get_authenticated_profile($pdo, $user_id);
$userInitials = $landlordProfile['initials'] ?? 'L';
$effectiveAvatar = $landlordProfile['photo'] ?? '';
$genderOptions = ['Male', 'Female', 'Prefer not to say', 'Other'];
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
    <link rel="stylesheet" href="../assets/css/common.css?v=30">
    <link rel="stylesheet" href="../assets/css/settings.css?v=4">
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
    <script src="../assets/js/tenant/popover.js"></script>
    <script src="../assets/js/tenant/sidebar.js"></script>
    <script src="../assets/js/tenant/profile-menu.js"></script>
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
            <a href="tenants.php" title="Tenants"><svg aria-hidden="true"><use href="#user-icon"/></svg><span class="nav-label">Tenants</span></a>
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
                <button type="button" class="settings-tab-btn" onclick="switchTab('security')" id="btn-security" role="tab" aria-selected="false">Account & Security</button>
                <button type="button" class="settings-tab-btn" onclick="switchTab('notifications')" id="btn-notifications" role="tab" aria-selected="false">Notifications</button>
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
                        <div class="info-display-group">
                            <span class="info-display-label">Birthdate</span>
                            <div class="info-display-val"><?= htmlspecialchars($user['date_of_birth'] ?: 'Not specified') ?></div>
                        </div>
                        <div class="info-display-group">
                            <span class="info-display-label">Gender</span>
                            <div class="info-display-val"><?= htmlspecialchars($user['gender'] ?: 'Not specified') ?></div>
                        </div>
                    </div>

                    <h3 class="settings-section-title">Uploaded Verification Documents</h3>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 20px;">
                        <!-- Government ID -->
                        <div>
                            <span class="info-display-label" style="display:block; margin-bottom:6px;">Government ID *</span>
                            <?php if (!empty($user['gov_id_file'])): ?>
                                <div class="doc-preview-card">
                                    <div style="display:flex; align-items:center; gap:12px;">
                                        <div class="doc-preview-thumb">
                                            <?php if (preg_match('/\.(jpg|jpeg|png|webp)$/i', $user['gov_id_file'])): ?>
                                                <img src="<?= htmlspecialchars($user['gov_id_file']) ?>" alt="ID Thumbnail">
                                            <?php else: ?>
                                                📄
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <strong style="display:block; font-size:0.92rem; color:#292524; word-break:break-all;"><?= htmlspecialchars(basename($user['gov_id_file'])) ?></strong>
                                            <span style="font-size:0.8rem; color:#78665c;">Valid Government ID</span>
                                        </div>
                                    </div>
                                    <div>
                                        <button type="button" class="btn-settings-secondary" style="padding:6px 14px; font-size:0.85rem;" onclick="openPreviewModal('<?= htmlspecialchars($user['gov_id_file']) ?>')">View</button>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div style="background:#fcf8f5; border:1px dashed #ebd9cb; border-radius:12px; padding:16px; text-align:center; color:#8c7569;">
                                    <p style="margin:0 0 6px; font-size:0.88rem; font-weight:600;">No Government ID</p>
                                    <span style="font-size:0.82rem;">Required for verification</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- EDIT MODE FORM -->
                <form id="profileEditForm" action="settings.php" method="POST" enctype="multipart/form-data" class="settings-card" style="display: none;">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="existing_profile_photo" value="<?= htmlspecialchars($user['profile_photo']) ?>">
                    <input type="hidden" name="existing_gov_id" value="<?= htmlspecialchars($user['gov_id_file']) ?>">

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
                                <svg viewBox="0 0 24 24"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            </button>
                            <input type="file" id="profile_photo_input" name="profile_photo" accept="image/*" style="display: none;" onchange="previewAvatar(this)">
                        </div>
                        <div>
                            <h3 style="color: #3b2820; font-size: 1.15rem; margin-bottom: 4px;"><?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Update Profile') ?></h3>
                            <span class="profile-role-badge">Role: Landlord</span>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 class="settings-section-title" style="margin:0; border:none;">Edit Personal Information</h3>
                        <button type="button" class="btn-settings-secondary" onclick="toggleEditMode()">Cancel</button>
                    </div>

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
                            <label class="settings-label">Last Name</label>
                            <input type="text" name="last_name" class="settings-input" value="<?= htmlspecialchars($user['last_name'] ?? '') ?>">
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Phone Number *</label>
                            <input type="text" name="phone_number" class="settings-input" value="<?= htmlspecialchars($user['phone_number']) ?>" required placeholder="09XX XXX XXXX">
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Facebook Link *</label>
                            <input type="text" name="facebook_link" class="settings-input" value="<?= htmlspecialchars($user['facebook_link']) ?>" placeholder="facebook.com/yourprofile or https://facebook.com/..." required>
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Email Address *</label>
                            <input type="email" name="personal_email" class="settings-input" value="<?= htmlspecialchars($user['personal_email']) ?>" required>
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Birthdate</label>
                            <input type="date" name="date_of_birth" class="settings-input" value="<?= htmlspecialchars($user['date_of_birth']) ?>">
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Gender</label>
                            <select name="gender" class="settings-select">
                                <option value="">Select Gender</option>
                                <?php foreach ($genderOptions as $opt): ?>
                                    <option value="<?= $opt ?>" <?= ($user['gender'] === $opt) ? 'selected' : '' ?>><?= $opt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <h3 class="settings-section-title">Government Identification *</h3>
                    <p style="color: #78665c; font-size: 0.88rem; margin-bottom: 12px;">Upload a valid government ID (Passport, Driver's License, PhilSys National ID, Postal ID, PRC ID) to complete verification.</p>

                    <div style="max-width: 580px; margin-bottom: 24px;">
                        <div class="settings-dropzone" id="dropzone_gov_id" ondragover="handleDragOver(event, this)" ondragleave="handleDragLeave(this)" ondrop="handleDrop(event, 'gov_id_input', 'gov_id_label')">
                            <svg class="dropzone-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                                <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                <polyline points="21 15 16 10 5 21"></polyline>
                            </svg>
                            <div id="gov_id_label" class="dropzone-filename"><?= !empty($user['gov_id_file']) ? htmlspecialchars(basename($user['gov_id_file'])) : 'No file chosen' ?></div>
                            <div class="dropzone-hint">Drag and drop your ID photo here, or browse (JPG, PNG, WebP up to 5MB)</div>
                            <div class="dropzone-btn-row">
                                <button type="button" class="dropzone-btn" onclick="document.getElementById('gov_id_input').click()">
                                    Choose Government ID
                                </button>
                            </div>
                            <input type="file" id="gov_id_input" name="gov_id" accept="image/*,application/pdf" style="display: none;" onchange="updateFileName(this, 'gov_id_label')">
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 28px;">
                        <button type="button" class="btn-settings-secondary" onclick="toggleEditMode()">Cancel</button>
                        <button type="submit" class="btn-settings-primary">Save Changes</button>
                    </div>
                </form>
            </div>

            <!-- TAB 2: ACCOUNT & SECURITY -->
            <div id="tab-security" class="settings-tab-content">
                <div class="settings-card">
                    <h3 class="settings-section-title" style="margin-top:0;">Account Settings and Security</h3>
                    <p style="color: #78665c; font-size: 0.94rem; margin-bottom: 22px;">Manage your password credentials and account security preferences.</p>

                    <form action="settings.php" method="POST" style="max-width: 500px;">
                        <input type="hidden" name="action" value="update_password">
                        <div class="settings-field">
                            <label class="settings-label">Current Password</label>
                            <input type="password" name="current_password" class="settings-input" required placeholder="Enter current password">
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">New Password</label>
                            <input type="password" name="new_password" class="settings-input" required minlength="8" placeholder="At least 8 characters">
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="settings-input" required minlength="8" placeholder="Re-enter new password">
                        </div>
                        <button type="submit" class="btn-settings-primary" style="margin-top: 14px;">Update Password</button>
                    </form>

                    <div class="settings-card" style="margin-top: 24px; border-color: #fecaca; background: #fff5f5;">
                        <h4 style="color: #dc2626; margin: 0 0 8px; font-size: 1.1rem; font-family:'Fraunces', serif;">Danger Zone</h4>
                        <p style="font-size: 0.85rem; color: #78665c; margin-bottom: 14px;">Once you delete your account, your profile, properties, and rooms cannot be recovered. Please be certain.</p>
                        <button type="button" class="btn-settings-danger" onclick="openDeleteModal()">Delete Account</button>
                    </div>
                </div>
            </div>

            <!-- TAB 3: NOTIFICATIONS -->
            <div id="tab-notifications" class="settings-tab-content">
                <div class="settings-card">
                    <h3 class="settings-section-title" style="margin-top:0;">Notification Preferences</h3>
                    <p style="color: #78665c; font-size: 0.94rem; margin-bottom: 24px;">Configure how you receive student booking inquiries, messages, and platform alerts.</p>

                    <div style="display:flex; flex-direction:column; gap:20px; max-width:600px;">
                        <label style="display:flex; align-items:center; justify-content:space-between; gap:20px; padding:12px 0; border-bottom:1px solid #ebd9cb; cursor:pointer;">
                            <div>
                                <strong style="display:block; color:#292524; font-size:0.95rem;">Student Booking Inquiries</strong>
                                <span style="font-size:0.85rem; color:#78665c;">Receive instant notifications when students apply for a room</span>
                            </div>
                            <input type="checkbox" id="togglePushNotificationsLandlord" checked style="width:20px; height:20px; accent-color:#7a4b3a;">
                        </label>
                        <label style="display:flex; align-items:center; justify-content:space-between; gap:20px; padding:12px 0; border-bottom:1px solid #ebd9cb; cursor:pointer;">
                            <div>
                                <strong style="display:block; color:#292524; font-size:0.95rem;">Verification Status Updates</strong>
                                <span style="font-size:0.85rem; color:#78665c;">Receive notification when your government ID is reviewed</span>
                            </div>
                            <input type="checkbox" checked style="width:20px; height:20px; accent-color:#7a4b3a;">
                        </label>
                        <label style="display:flex; align-items:center; justify-content:space-between; gap:20px; padding:12px 0; cursor:pointer;">
                            <div>
                                <strong style="display:block; color:#292524; font-size:0.95rem;">Email Summaries</strong>
                                <span style="font-size:0.85rem; color:#78665c;">Receive periodic occupancy and monthly earnings summaries</span>
                            </div>
                            <input type="checkbox" checked style="width:20px; height:20px; accent-color:#7a4b3a;">
                        </label>
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

    <!-- Modal for Image Preview -->
    <div id="previewModal" class="preview-modal" onclick="if(event.target===this)closePreviewModal()">
        <div class="preview-modal-box">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; font-size:1.1rem; color:#292524;">Government ID Document</h3>
                <button type="button" class="btn-settings-secondary" style="padding:4px 10px;" onclick="closePreviewModal()">✕ Close</button>
            </div>
            <img id="modalPreviewImg" class="preview-modal-img" src="" alt="Government ID Preview">
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

        function toggleEditMode() {
            const viewCard = document.getElementById('profileViewCard');
            const editForm = document.getElementById('profileEditForm');
            if (viewCard && editForm) {
                if (editForm.style.display === 'none') {
                    viewCard.style.display = 'none';
                    editForm.style.display = 'block';
                } else {
                    editForm.style.display = 'none';
                    viewCard.style.display = 'block';
                }
            }
        }

        function previewAvatar(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const container = document.getElementById('avatarPreviewContainer');
                    if (container) {
                        container.innerHTML = '<img src="' + e.target.result + '" alt="Avatar" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">';
                    }
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function updateFileName(input, labelId) {
            const label = document.getElementById(labelId);
            if (input.files && input.files[0] && label) {
                label.textContent = input.files[0].name;
                label.style.fontWeight = '600';
                label.style.color = '#7a4b3a';
            }
        }

        function handleDragOver(e, dropzone) {
            e.preventDefault();
            dropzone.classList.add('is-dragover');
        }

        function handleDragLeave(dropzone) {
            dropzone.classList.remove('is-dragover');
        }

        function handleDrop(e, inputId, labelId) {
            e.preventDefault();
            const dropzone = e.currentTarget;
            dropzone.classList.remove('is-dragover');
            const input = document.getElementById(inputId);
            if (e.dataTransfer && e.dataTransfer.files.length > 0 && input) {
                input.files = e.dataTransfer.files;
                updateFileName(input, labelId);
            }
        }

        function openPreviewModal(imgSrc) {
            const modal = document.getElementById('previewModal');
            const img = document.getElementById('modalPreviewImg');
            if (modal && img) {
                img.src = imgSrc;
                modal.classList.add('is-active');
            }
        }

        function closePreviewModal() {
            const modal = document.getElementById('previewModal');
            if (modal) {
                modal.classList.remove('is-active');
            }
        }

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

        const pushToggle = document.getElementById('togglePushNotificationsLandlord');
        if (pushToggle) {
            const savedPush = localStorage.getItem('unistay_push_notifications');
            if (savedPush === 'off') {
                pushToggle.checked = false;
            } else if (savedPush === 'on') {
                pushToggle.checked = true;
            }
            pushToggle.addEventListener('change', function() {
                localStorage.setItem('unistay_push_notifications', this.checked ? 'on' : 'off');
            });
        }

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
</body>
</html>