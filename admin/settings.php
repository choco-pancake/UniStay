<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

start_app_session();
$pdo = database();
$adminProfile = require_signed_in('admin');
$adminId = (int)($adminProfile['id'] ?? 0);

$successMessage = '';
$errorMessage = '';
import_request_name: $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Handle Actions (Profile Update, Photo Upload, Password Update, Account Deletion)
if ($requestMethod === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'update_profile') {
        $firstName   = trim($_POST['first_name'] ?? '');
        $middleName  = trim($_POST['middle_name'] ?? '');
        $lastName    = trim($_POST['last_name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $phoneNumber = trim($_POST['phone_number'] ?? '');

        if ($firstName === '') {
            $errorMessage = "First name is required.";
        } elseif ($lastName === '') {
            $errorMessage = "Last name is required.";
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = "A valid email address is required.";
        }

        $currentFiles = $pdo->prepare('SELECT profile_photo, photo FROM users WHERE id = ?');
        $currentFiles->execute([$adminId]);
        $storedFiles = $currentFiles->fetch() ?: [];
        $profilePhotoPath = (string)($storedFiles['profile_photo'] ?: ($storedFiles['photo'] ?? ''));
        
        $uploadDir = __DIR__ . '/../uploads/';
        if (empty($errorMessage) && !is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $croppedData = (string)($_POST['cropped_profile_photo'] ?? '');
        if (empty($errorMessage) && $croppedData !== '') {
            $decoded = preg_match('#^data:image/(?:png|jpeg|jpg|webp);base64,#i', $croppedData)
                ? base64_decode(explode(',', $croppedData, 2)[1], true) : false;
            if ($decoded === false || strlen($decoded) > 10 * 1024 * 1024 || @getimagesizefromstring($decoded) === false) {
                $errorMessage = 'Please choose a valid profile image under 10 MB.';
            } else {
                $fileName = 'admin_avatar_' . $adminId . '_' . bin2hex(random_bytes(8)) . '.png';
                if (file_put_contents($uploadDir . $fileName, $decoded) === false) {
                    $errorMessage = 'Profile photo could not be saved.';
                } else {
                    $profilePhotoPath = 'uploads/' . $fileName;
                }
            }
        } elseif (empty($errorMessage) && isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || @getimagesize($_FILES['profile_photo']['tmp_name']) === false) {
                $errorMessage = 'Please choose a valid profile image.';
            } else {
                $fileName = 'admin_profile_' . $adminId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadDir . $fileName)) {
                    $profilePhotoPath = 'uploads/' . $fileName;
                } else {
                    $errorMessage = 'Profile photo could not be saved.';
                }
            }
        }

        if (empty($errorMessage)) {
            try {
                $stmt = $pdo->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ?, personal_email = ?, phone_number = ?, profile_photo = ?, photo = ? WHERE role = 'admin' AND id = ?");
                $stmt->execute([
                    $firstName, 
                    $middleName !== '' ? $middleName : null, 
                    $lastName, 
                    $email, 
                    $email, 
                    $phoneNumber !== '' ? $phoneNumber : null, 
                    $profilePhotoPath,
                    $profilePhotoPath,
                    $adminId
                ]);
                $successMessage = "Admin profile information updated successfully!";
                
                if (isset($_SESSION['user'])) {
                    $_SESSION['user']['name'] = trim($firstName . ' ' . $lastName);
                    if ($profilePhotoPath !== '') {
                        $_SESSION['user']['photo'] = $profilePhotoPath;
                    }
                }
            } catch (\PDOException $e) {
                $errorMessage = "Database update failed: " . $e->getMessage();
            }
        }
    } elseif ($action === 'update_password') {
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
                $st->execute([$adminId]);
                $dbPass = (string)$st->fetchColumn();

                if ($dbPass === '' || password_verify($currentPassword, $dbPass)) {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $up = $pdo->prepare("UPDATE users SET password_hash = ? WHERE role = 'admin' AND id = ?");
                    $up->execute([$newHash, $adminId]);
                    $successMessage = "Password successfully updated!";
                } else {
                    $errorMessage = "The current password you entered is incorrect.";
                }
            } catch (\Exception $e) {
                $errorMessage = "Failed to update password: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_account') {
        $confirmEmail = strtolower(trim((string)($_POST['confirm_email'] ?? '')));
        try {
            $st = $pdo->prepare("SELECT personal_email, email FROM users WHERE id = ? LIMIT 1");
            $st->execute([$adminId]);
            $uRow = $st->fetch();
            $realEmail = strtolower(trim((string)($uRow['personal_email'] ?: ($uRow['email'] ?? ''))));

            if ($confirmEmail !== '' && $confirmEmail === $realEmail) {
                $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$adminId]);
                $pdo->prepare("DELETE FROM notification_preferences WHERE user_id = ?")->execute([$adminId]);
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$adminId]);

                if (function_exists('sync_users_auto_increment')) {
                    sync_users_auto_increment($pdo);
                }

                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_destroy();
                }
                header('Location: ../index.php');
                exit;
            } else {
                $errorMessage = "Email confirmation did not match your administrator account email.";
            }
        } catch (\Exception $e) {
            $errorMessage = "Could not delete account: " . $e->getMessage();
        }
    }
}

// Fetch latest Admin Data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$adminId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $adminProfile = array_merge($adminProfile, $row);
    }
} catch (\Exception $e) {}

$adminInitials = strtoupper(substr($adminProfile['first_name'] ?? 'A', 0, 1) . substr($adminProfile['last_name'] ?? '', 0, 1));
$effectiveAvatar = $adminProfile['profile_photo'] ?? ($adminProfile['photo'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings · UniStay Admin</title>
    <link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/common.css?v=32">
    <link rel="stylesheet" href="../assets/css/settings.css?v=5">
    <style>
        .password-box { display: none; background: #fafaf9; border: 1px solid #e7e5e4; padding: 20px; border-radius: 8px; margin-top: 15px; }
        .password-box.is-open { display: block; }
        .danger-zone-box { border: 1px solid #fee2e2; background: #fff5f5; padding: 20px; border-radius: 8px; margin-top: 30px; }
        .btn-settings-danger { background: #dc2626; color: white; padding: 9px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; }
    </style>
    <script src="../assets/js/tenant/popover.js?v=2"></script>
    <script src="../assets/js/tenant/sidebar.js"></script>
    <script src="../assets/js/tenant/profile-menu.js?v=31"></script>
    <script>window.UNISTAY_NOTIFICATION_ACCOUNT = <?= json_encode(['id' => $adminId, 'role' => 'admin']) ?>;</script>
    <script defer src="../assets/js/notification-preferences.js?v=3"></script>
    <script defer src="../assets/js/notification-global.js?v=1"></script>
</head>
<body class="settings-page">
    <svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs>
        <symbol id="grid-icon" viewBox="0 0 24 24"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></symbol>
        <symbol id="badge-icon" viewBox="0 0 24 24"><path d="m12 2 3 3 4-1 1 4 3 3-3 3-1 4-4 1-3 3-3-3-4-1-1-4-3-3 3-3 1-4 4 1Z"/><path d="m8 12 3 3 5-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="user-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></symbol>
        <symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
        <symbol id="bell-icon" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M10 20h4"/></symbol>
        <symbol id="bell-off-icon" viewBox="0 0 24 24"><path d="m3 3 18 18M10 20h4M6 6c-1 2 0 7-3 10h13M18 13V8a6 6 0 0 0-8-6"/></symbol>
        <symbol id="arrow-icon" viewBox="0 0 24 24"><path d="M4 12h16m-6-6 6 6-6 6"/></symbol>
        <symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
        <symbol id="pencil-icon" viewBox="0 0 24 24"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></symbol>
        <symbol id="logout-icon" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    </defs></svg>

    <!-- Admin Sidebar Navigation -->
    <aside class="sidebar" aria-label="UniStay Admin Portal">
        <a href="dashboard.php" class="brand" title="UniStay Admin Home">
            <img src="../assets/images/tenant/logo.png" width="50" height="50" alt="UniStay logo">
            <span class="brand-name">UniStay</span>
        </a>
        <nav aria-label="Admin navigation">
            <a href="dashboard.php" title="Dashboard"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
            <a href="verification.php" title="Verification"><svg aria-hidden="true"><use href="#badge-icon"/></svg><span class="nav-label">Verification</span></a>
            <a href="users.php" title="Users"><svg aria-hidden="true"><use href="#user-icon"/></svg><span class="nav-label">Users</span></a>
            <a href="settings.php" title="Settings" aria-current="page"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
        </nav>
        <button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true">
            <span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span>
            <span class="toggle-label">Collapse</span>
        </button>
    </aside>

    <div class="shell">
        <div class="shell-orbs" aria-hidden="true"><i></i><i></i><i></i></div>
        <header class="topbar">
            <h1 id="headerTitle">Admin Settings</h1>
            <div class="profile">
                <button id="notification-bell" class="bell" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="notification-popover">
                    <svg aria-hidden="true"><use href="#bell-icon"/></svg>
                </button>
                <button class="avatar" type="button" aria-label="Open profile menu" aria-expanded="false" aria-controls="profile-popover" <?= !empty($effectiveAvatar) ? 'style="background-image:url(\''.htmlspecialchars($effectiveAvatar, ENT_QUOTES, 'UTF-8').'\');"' : '' ?>>
                    <?php if (!empty($effectiveAvatar)): ?>
                        <img src="<?= htmlspecialchars($effectiveAvatar) ?>" alt="" referrerpolicy="no-referrer" class="avatar-img" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline-flex';">
                        <span style="display:none;"><?= htmlspecialchars($adminInitials) ?></span>
                    <?php else: ?>
                        <?= htmlspecialchars($adminInitials) ?>
                    <?php endif; ?>
                </button>
                <section id="notification-popover" class="popover" aria-labelledby="popover-title" hidden>
                    <h2 id="popover-title">Notifications</h2>
                    <div class="empty" id="popover-empty"><span class="empty-icon"><svg aria-hidden="true"><use href="#bell-off-icon"/></svg></span><strong>No new notifications</strong><p>System activities will appear here.</p></div>
                    <ul class="notification-list" id="popover-list" aria-label="Unread notifications" hidden></ul>
                    <a class="view-all" id="view-all" href="notifications.php?from=settings" aria-label="View all notifications">View all notifications <svg aria-hidden="true"><use href="#arrow-icon"/></svg></a>
                </section>
            </div>
        </header>

        <main id="settings-main" tabindex="-1">
            <?php if (!empty($successMessage)): ?>
                <div class="settings-alert is-success" role="alert">✓ <?= htmlspecialchars($successMessage) ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="settings-alert is-error" role="alert">⚠ <?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>

            <!-- Navigation Tabs -->
            <div class="settings-tabs" role="tablist">
                <div class="settings-tabs-indicator" id="adminTabsIndicator"></div>
                <button type="button" class="settings-tab-btn is-active" onclick="switchTab('profile')" id="btn-profile" role="tab" aria-selected="true">Profile</button>
                <button type="button" class="settings-tab-btn" onclick="switchTab('security')" id="btn-security" role="tab" aria-selected="false">Account &amp; Security</button>
                <button type="button" class="settings-tab-btn" onclick="switchTab('notifications')" id="btn-notifications" role="tab" aria-selected="false">Notifications</button>
            </div>

            <!-- TAB 1: PROFILE -->
            <div id="tab-profile" class="settings-tab-content is-active">
                <!-- VIEW MODE -->
                <div id="profileViewCard" class="settings-card">
                    <div class="profile-view-header">
                        <div class="profile-view-identity">
                            <div class="profile-view-avatar">
                                <?php if (!empty($effectiveAvatar)): ?>
                                    <img src="<?= htmlspecialchars($effectiveAvatar) ?>" alt="Avatar" referrerpolicy="no-referrer" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline';">
                                    <span style="display:none;"><?= htmlspecialchars($adminInitials) ?></span>
                                <?php else: ?>
                                    <?= htmlspecialchars($adminInitials) ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h2 class="profile-view-name"><?= htmlspecialchars(trim(($adminProfile['first_name'] ?? '') . ' ' . ($adminProfile['last_name'] ?? '')) ?: 'Administrator') ?></h2>
                                <span class="profile-role-badge">System Administrator</span>
                            </div>
                        </div>
                        <button type="button" class="btn-settings-secondary" onclick="toggleEditMode()">
                            <svg style="width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;" viewBox="0 0 24 24"><use href="#pencil-icon"/></svg> Edit Profile
                        </button>
                    </div>

                    <h3 class="settings-section-title">Personal Information</h3>
                    <div class="settings-grid-3">
                        <div class="info-display-group">
                            <span class="info-display-label">First Name</span>
                            <div class="info-display-val"><?= htmlspecialchars($adminProfile['first_name'] ?: 'Not specified') ?></div>
                        </div>
                        <div class="info-display-group">
                            <span class="info-display-label">Middle Name</span>
                            <div class="info-display-val"><?= htmlspecialchars($adminProfile['middle_name'] ?: 'None') ?></div>
                        </div>
                        <div class="info-display-group">
                            <span class="info-display-label">Last Name</span>
                            <div class="info-display-val"><?= htmlspecialchars($adminProfile['last_name'] ?: 'Not specified') ?></div>
                        </div>
                        <div class="info-display-group">
                            <span class="info-display-label">Email Address</span>
                            <div class="info-display-val"><?= htmlspecialchars($adminProfile['email'] ?? $adminProfile['personal_email'] ?? 'Not specified') ?></div>
                        </div>
                        <div class="info-display-group">
                            <span class="info-display-label">Contact Number</span>
                            <div class="info-display-val"><?= htmlspecialchars($adminProfile['phone_number'] ?: 'Not specified') ?></div>
                        </div>
                    </div>
                </div>

                <!-- EDIT MODE FORM -->
                <form id="profileEditForm" action="settings.php" method="POST" enctype="multipart/form-data" class="settings-card" style="display: none;">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="existing_profile_photo" value="<?= htmlspecialchars($effectiveAvatar) ?>">
                    <input type="hidden" name="cropped_profile_photo" id="cropped_profile_photo" value="">

                    <!-- Avatar Edit Box -->
                    <div class="avatar-edit-box">
                        <div class="avatar-edit-preview-wrap">
                            <div class="avatar-preview-circle" id="avatarPreviewContainer">
                                <?php if (!empty($effectiveAvatar)): ?>
                                    <img src="<?= htmlspecialchars($effectiveAvatar) ?>" alt="Avatar" referrerpolicy="no-referrer" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline';">
                                    <span style="display:none;"><?= htmlspecialchars($adminInitials) ?></span>
                                <?php else: ?>
                                    <?= htmlspecialchars($adminInitials) ?>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="avatar-edit-btn" onclick="document.getElementById('profile_photo_input').click();" title="Change photo">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M4 7h3l1.5-2h7L17 7h3v12H4z"/><circle cx="12" cy="13" r="3.5"/></svg>
                            </button>
                            <input type="file" id="profile_photo_input" name="profile_photo" accept="image/*" style="display: none;" onchange="initCropper(this)">
                        </div>
                        <div>
                            <h3 style="color: #3b2820; font-size: 1.15rem; margin-bottom: 4px;"><?= htmlspecialchars(trim(($adminProfile['first_name'] ?? '') . ' ' . ($adminProfile['last_name'] ?? '')) ?: 'Update Profile') ?></h3>
                            <span class="profile-role-badge">System Administrator</span>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 class="settings-section-title" style="margin:0;">Edit Personal Information</h3>
                        <button type="button" class="btn-settings-secondary" onclick="toggleEditMode()">Cancel</button>
                    </div>

                    <div class="settings-grid-3" style="margin-bottom: 20px;">
                        <div class="settings-field">
                            <label class="settings-label">First Name *</label>
                            <input type="text" name="first_name" class="settings-input" value="<?= htmlspecialchars($adminProfile['first_name'] ?? '') ?>" required>
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Middle Name</label>
                            <input type="text" name="middle_name" class="settings-input" value="<?= htmlspecialchars($adminProfile['middle_name'] ?? '') ?>" placeholder="Optional">
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Last Name *</label>
                            <input type="text" name="last_name" class="settings-input" value="<?= htmlspecialchars($adminProfile['last_name'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="settings-grid-2" style="margin-bottom: 24px;">
                        <div class="settings-field">
                            <label class="settings-label">Email Address *</label>
                            <input type="email" name="email" class="settings-input" value="<?= htmlspecialchars($adminProfile['email'] ?? $adminProfile['personal_email'] ?? '') ?>" required>
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Contact Number</label>
                            <input type="text" name="phone_number" class="settings-input" value="<?= htmlspecialchars($adminProfile['phone_number'] ?? '') ?>" placeholder="Optional contact number">
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 12px;">
                        <button type="submit" class="btn-settings-primary">Save Changes</button>
                    </div>
                </form>
            </div>

            <!-- TAB 2: ACCOUNT & SECURITY -->
            <div id="tab-security" class="settings-tab-content">
                <div class="settings-card">
                    <h3 class="settings-section-title" style="margin-top:0;">Account &amp; Security</h3>
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 0;">
                        <div>
                            <strong style="color: #3b2820; font-size: 0.96rem;">Password</strong>
                            <p style="margin: 3px 0 0; font-size: 0.85rem; color: #78665c;">Update your administrator account password securely.</p>
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

                    <!-- Danger Zone (Account Deletion) -->
                    <div class="danger-zone-box">
                        <h4 style="color: #dc2626; font-size: 1.05rem; margin:0;">Danger Zone</h4>
                        <p style="font-size: 0.85rem; color: #78665c; margin:3px 0 14px;">Once you delete your admin account, it cannot be recovered. Please be certain.</p>
                        <button type="button" class="btn-settings-danger" onclick="openDeleteModal()">Delete Account</button>
                    </div>
                </div>
            </div>

            <!-- TAB 3: NOTIFICATIONS -->
            <div id="tab-notifications" class="settings-tab-content">
                <div class="settings-card">
                    <h3 class="settings-section-title" style="margin-top:0;">Notification Preferences</h3>
                    <p style="color: #78665c; font-size: 0.94rem; margin-bottom: 24px;">Configure how you receive administrative alerts and verification notices.</p>

                    <div style="display:flex; flex-direction:column; gap:20px;">
                        <div class="switch-label">
                            <div>
                                <strong style="display:block; color:#292524; font-size:0.95rem;">New Landlord Application Alerts</strong>
                                <span style="font-size:0.85rem; color:#78665c;">Instant alert when a landlord submits business permits / IDs</span>
                            </div>
                            <label class="switch-toggle" aria-label="Notification preference"><input type="checkbox" checked><span class="switch-slider"></span></label>
                        </div>
                        <div class="switch-label">
                            <div>
                                <strong style="display:block; color:#292524; font-size:0.95rem;">Student Verification Requests</strong>
                                <span style="font-size:0.85rem; color:#78665c;">Get notified when students submit IDs for verification review</span>
                            </div>
                            <label class="switch-toggle" aria-label="Notification preference"><input type="checkbox" checked><span class="switch-slider"></span></label>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Delete Account Confirmation Modal -->
    <div id="deleteModal" class="settings-modal-overlay" style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(0,0,0,0.65); align-items:center; justify-content:center; padding:20px;">
        <div class="settings-modal-card" style="background:#fff; border-radius:18px; max-width:450px; width:100%; padding:30px; box-shadow:0 20px 50px rgba(0,0,0,0.25); text-align:center; position:relative;">
            <button type="button" onclick="closeDeleteModal()" aria-label="Close" style="position:absolute;top:18px;right:18px;border:none;background:none;color:#7a4b3a;font-size:26px;cursor:pointer;">&times;</button>
            <div style="width:64px;height:64px;margin:0 auto 20px;border-radius:50%;display:grid;place-items:center;background:#fee2e2;color:#dc2626;border:1px solid #fecaca;">
                <svg viewBox="0 0 24 24" style="width:31px;height:31px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <h3 style="color:#3b2820;font-family:'Fraunces',Georgia,serif;font-weight:700;margin:0 0 10px;font-size:24px;">Delete Account</h3>
            <p style="font-size:14px;color:#745f54;line-height:1.6;margin:0 auto 20px;">This action cannot be undone. To permanently delete your admin account, please type your email address below:</p>
            <form action="settings.php" method="POST">
                <input type="hidden" name="action" value="delete_account">
                <div style="margin-bottom: 22px;">
                    <input type="email" name="confirm_email" class="settings-input" style="width:100%;text-align:center;" placeholder="<?= htmlspecialchars($adminProfile['personal_email'] ?? $adminProfile['email'] ?? ''); ?>" required>
                </div>
                <div style="display:flex;gap:12px;justify-content:center;">
                    <button type="button" class="btn-settings-secondary" onclick="closeDeleteModal()" style="flex:1;">Cancel</button>
                    <button type="submit" class="btn-settings-danger" style="flex:1;">Delete Account</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Cropper Modal for Avatar -->
    <div id="cropperModal" class="cropper-modal-overlay" style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(0,0,0,0.65); align-items:center; justify-content:center; padding:20px;">
        <div class="cropper-modal-card" style="background:#fff; border-radius:16px; width:100%; max-width:360px; padding:22px; box-shadow:0 20px 40px rgba(0,0,0,0.2);">
            <div class="cropper-modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                <h3 class="cropper-modal-title" style="font-size:16px; font-weight:bold; color:#292524;">Crop Profile Photo</h3>
                <button type="button" class="profile-toast-close" onclick="closeCropperModal()" aria-label="Close cropper" style="background:none;border:none;font-size:22px;cursor:pointer;color:#78716c;">&times;</button>
            </div>
            <div class="cropper-canvas-wrap" id="cropperCanvasWrap" style="position:relative; width:300px; height:300px; margin:0 auto 14px; background:#18181b; border-radius:8px; overflow:hidden; cursor:grab;">
                <canvas id="cropperCanvas" width="300" height="300"></canvas>
                <div class="cropper-mask" style="position:absolute; inset:0; border-radius:50%; box-shadow:0 0 0 9999px rgba(0,0,0,0.55); pointer-events:none; border:2px solid rgba(255,255,255,0.8);"></div>
            </div>
            <div class="cropper-controls" style="display:flex; align-items:center; gap:10px; margin-bottom:14px;">
                <button type="button" class="cropper-btn-round" onclick="adjustZoom(-0.15)" title="Zoom Out" style="width:32px;height:32px;border-radius:50%;background:#f3f4f6;border:1px solid #d1d5db;font-weight:bold;cursor:pointer;">−</button>
                <input type="range" id="cropperZoom" class="cropper-zoom-slider" min="0.6" max="3" step="0.05" value="1" oninput="setZoom(parseFloat(this.value))" style="flex:1;accent-color:#7c2d12;">
                <button type="button" class="cropper-btn-round" onclick="adjustZoom(0.15)" title="Zoom In" style="width:32px;height:32px;border-radius:50%;background:#f3f4f6;border:1px solid #d1d5db;font-weight:bold;cursor:pointer;">+</button>
            </div>
            <p style="margin:0; font-size:0.82rem; color:#78665c; text-align:center;">Drag the image to position inside the circle, and adjust zoom.</p>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:14px;">
                <button type="button" class="btn-settings-secondary" onclick="closeCropperModal()">Cancel</button>
                <button type="button" class="btn-settings-primary" onclick="applyCroppedAvatar()">Apply Crop</button>
            </div>
        </div>
    </div>

    <script>
        function updateAdminTabIndicator(btn) {
            const indicator = document.getElementById('adminTabsIndicator');
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
                updateAdminTabIndicator(activeBtn);
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            const activeBtn = document.querySelector('.settings-tab-btn.is-active') || document.getElementById('btn-profile');
            if (activeBtn) updateAdminTabIndicator(activeBtn);
        });
        window.addEventListener('resize', () => {
            const activeBtn = document.querySelector('.settings-tab-btn.is-active');
            if (activeBtn) updateAdminTabIndicator(activeBtn);
        });

        function togglePasswordFrame() {
            const frame = document.getElementById('passwordFrame');
            if (frame) frame.classList.toggle('is-open');
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

        function openDeleteModal() {
            const modal = document.getElementById('deleteModal');
            if (modal) modal.style.display = 'flex';
        }
        function closeDeleteModal() {
            const modal = document.getElementById('deleteModal');
            if (modal) modal.style.display = 'none';
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
                        if (modal) modal.style.display = 'flex';

                        cropperBaseScale = Math.max(200 / cropperImg.width, 200 / cropperImg.height);
                        cropperScale = cropperBaseScale;
                        document.getElementById('cropperZoom').value = 1;

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
                if (e.touches.length === 1) startDrag(e.touches[0].clientX, e.touches[0].clientY);
            }, { passive: true });
            window.addEventListener('touchmove', e => {
                if (e.touches.length === 1) moveDrag(e.touches[0].clientX, e.touches[0].clientY);
            }, { passive: true });
            window.addEventListener('touchend', endDrag);

            wrap.addEventListener('wheel', e => {
                e.preventDefault();
                adjustZoom(e.deltaY < 0 ? 0.08 : -0.08);
            }, { passive: false });
        }

        function applyCroppedAvatar() {
            if (!cropperImg) return;
            const offscreen = document.createElement('canvas');
            offscreen.width = 400;
            offscreen.height = 400;
            const ctx2 = offscreen.getContext('2d');

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
            if (modal) modal.style.display = 'none';
        }

        <?php if ($errorMessage !== '' && ($_POST['action'] ?? '') === 'update_profile'): ?>
            toggleEditMode();
        <?php endif; ?>
    </script>
</body>
</html>