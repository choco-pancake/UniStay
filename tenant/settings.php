<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

start_app_session();
$user_id = $_SESSION['user_id'] ?? null;
if (!is_numeric($user_id)) {
    header('Location: ../auth/login.php?redirect=tenant/settings.php');
    exit;
}

$pdo = database();
$user_id = (int)$user_id;
$successMessage = '';
$errorMessage = '';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Handle Actions
if ($requestMethod === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_profile') {
        $firstName              = trim($_POST['first_name'] ?? '');
        $middleName             = trim($_POST['middle_name'] ?? '');
        $lastName               = trim($_POST['last_name'] ?? '');
        $phoneNumber            = trim($_POST['phone_number'] ?? '');
        $messengerLink          = trim($_POST['facebook_link'] ?? '');
        $personalEmail          = trim($_POST['personal_email'] ?? '');
        $dateOfBirth            = trim($_POST['date_of_birth'] ?? '');
        $gender                 = trim($_POST['gender'] ?? '');
        $school                 = trim($_POST['school'] ?? '');
        $emergencyName          = trim($_POST['emergency_name'] ?? '');
        $emergencyRelationship  = trim($_POST['emergency_relationship'] ?? '');
        $emergencyPhone         = trim($_POST['emergency_phone'] ?? '');

        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $profilePhotoPath = $_POST['existing_profile_photo'] ?? '';
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $fileName = time() . '_' . basename($_FILES['profile_photo']['name']);
            $destPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $destPath)) {
                $profilePhotoPath = 'uploads/' . $fileName;
            }
        }

        $schoolIdPath = $_POST['existing_school_id'] ?? '';
        if (isset($_FILES['school_id']) && $_FILES['school_id']['error'] === UPLOAD_ERR_OK) {
            $fileName = 'school_id_' . time() . '_' . basename($_FILES['school_id']['name']);
            $destPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['school_id']['tmp_name'], $destPath)) {
                $schoolIdPath = 'uploads/' . $fileName;
            }
        }

        $govIdPath = $_POST['existing_gov_id'] ?? '';
        if (isset($_FILES['gov_id']) && $_FILES['gov_id']['error'] === UPLOAD_ERR_OK) {
            $fileName = 'gov_id_' . time() . '_' . basename($_FILES['gov_id']['name']);
            $destPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['gov_id']['tmp_name'], $destPath)) {
                $govIdPath = 'uploads/' . $fileName;
            }
        }

        $isComplete = ($firstName !== '' && $phoneNumber !== '' && $school !== '' && $gender !== '' && $dateOfBirth !== '' && $emergencyName !== '' && $emergencyPhone !== '');

        try {
            $stmt = $pdo->prepare("UPDATE users SET 
                first_name = ?, middle_name = ?, last_name = ?, phone_number = ?, 
                facebook_link = ?, personal_email = ?, date_of_birth = ?, gender = ?, 
                school = ?, emergency_name = ?, emergency_relationship = ?, emergency_phone = ?,
                profile_photo = ?, school_id_file = ?, gov_id_file = ?,
                profile_completed = CASE WHEN ? = 1 OR profile_completed = 1 THEN 1 ELSE profile_completed END
                WHERE id = ?");
            $stmt->execute([
                $firstName,
                $middleName !== '' ? $middleName : null,
                $lastName !== '' ? $lastName : null,
                $phoneNumber, 
                $messengerLink, $personalEmail, $dateOfBirth, $gender, 
                $school, $emergencyName, $emergencyRelationship, $emergencyPhone,
                $profilePhotoPath, $schoolIdPath, $govIdPath,
                $isComplete ? 1 : 0,
                $user_id
            ]);
            if (isset($_SESSION['user'])) {
                $_SESSION['user']['name'] = trim($firstName . ' ' . $lastName) ?: $firstName;
                if ($profilePhotoPath !== '') {
                    $_SESSION['user']['photo'] = $profilePhotoPath;
                }
            }
            $successMessage = $isComplete 
                ? "Profile completed successfully! You now have full access to view room details and submit bookings." 
                : "Profile updated successfully!";
        } catch (\PDOException $e) {
            $errorMessage = "Database update failed: " . $e->getMessage();
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
            $st = $pdo->prepare("SELECT personal_email, email FROM users WHERE id = ? LIMIT 1");
            $st->execute([$user_id]);
            $uRow = $st->fetch();
            $realEmail1 = strtolower(trim((string)($uRow['personal_email'] ?? '')));
            $realEmail2 = strtolower(trim((string)($uRow['email'] ?? '')));

            if ($confirmEmail !== '' && ($confirmEmail === $realEmail1 || $confirmEmail === $realEmail2)) {
                $pdo->prepare("DELETE FROM bookings WHERE user_id = ?")->execute([$user_id]);
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
                session_destroy();
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
$genderOptions = ['Male', 'Female', 'Other'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#7a4b3a">
    <title>Account Settings & Profile · UniStay</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/common.css?v=24">
    <link rel="stylesheet" href="../assets/css/settings.css?v=2">
    <script src="../assets/js/tenant/popover.js"></script>
    <script src="../assets/js/tenant/sidebar.js"></script>
    <script src="../assets/js/tenant/profile-menu.js"></script>
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
            <?php if (!empty($successMessage)): ?>
                <div class="settings-alert is-success" role="alert">✓ <?= htmlspecialchars($successMessage) ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="settings-alert is-error" role="alert">⚠ <?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>

            <!-- Navigation Tabs -->
            <div class="settings-tabs" role="tablist">
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
                                <h2 class="profile-view-name"><?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'Student Profile') ?></h2>
                                <span class="profile-role-badge"><?= htmlspecialchars($user['role'] ?? 'Tenant') ?></span>
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
                            <span class="info-display-label">Messenger Link</span>
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
                        <div class="info-display-group">
                            <span class="info-display-label">School ID</span>
                            <div class="info-display-val"><?= $user['school_id_file'] !== '' ? ('📄 ' . htmlspecialchars(basename($user['school_id_file']))) : 'Not uploaded yet' ?></div>
                        </div>
                        <div class="info-display-group">
                            <span class="info-display-label">Government ID</span>
                            <div class="info-display-val"><?= $user['gov_id_file'] !== '' ? ('📄 ' . htmlspecialchars(basename($user['gov_id_file']))) : 'Not uploaded yet' ?></div>
                        </div>
                    </div>
                </div>

                <!-- EDIT MODE FORM -->
                <form id="profileEditForm" action="settings.php" method="POST" enctype="multipart/form-data" class="settings-card" style="display: none;">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="existing_profile_photo" value="<?= htmlspecialchars($user['profile_photo']) ?>">
                    <input type="hidden" name="existing_school_id" value="<?= htmlspecialchars($user['school_id_file']) ?>">
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
                            <span class="profile-role-badge">Role: <?= htmlspecialchars($user['role'] ?? 'Tenant') ?></span>
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
                            <label class="settings-label">Last Name <span style="font-weight: normal; color: #8b7467; font-size: 12px;">(optional)</span></label>
                            <input type="text" name="last_name" class="settings-input" value="<?= htmlspecialchars($user['last_name'] ?? '') ?>">
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Phone Number</label>
                            <input type="text" name="phone_number" class="settings-input" value="<?= htmlspecialchars($user['phone_number']) ?>">
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Messenger Link</label>
                            <input type="text" name="facebook_link" class="settings-input" value="<?= htmlspecialchars($user['facebook_link']) ?>">
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
                                <?php foreach ($genderOptions as $opt): ?>
                                    <option value="<?= $opt ?>" <?= ($user['gender'] === $opt) ? 'selected' : '' ?>><?= $opt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">School / University</label>
                            <input type="text" name="school" class="settings-input" value="<?= htmlspecialchars($user['school']) ?>">
                        </div>
                    </div>

                    <h3 class="settings-section-title">Edit Emergency Contact</h3>
                    <div class="settings-grid-3">
                        <div class="settings-field">
                            <label class="settings-label">Contact Name</label>
                            <input type="text" name="emergency_name" class="settings-input" value="<?= htmlspecialchars($user['emergency_name']) ?>">
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Relationship</label>
                            <select name="emergency_relationship" class="settings-select">
                                <?php foreach ($relationshipOptions as $rel): ?>
                                    <option value="<?= $rel ?>" <?= ($user['emergency_relationship'] === $rel) ? 'selected' : '' ?>><?= $rel ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="settings-field">
                            <label class="settings-label">Emergency Phone</label>
                            <input type="text" name="emergency_phone" class="settings-input" value="<?= htmlspecialchars($user['emergency_phone']) ?>">
                        </div>
                    </div>

                    <h3 class="settings-section-title">Update Identification Documents</h3>
                    <div class="settings-grid-2">
                        <!-- School ID Dropzone -->
                        <div>
                            <label class="settings-label" style="margin-bottom: 6px; display:block;">School ID</label>
                            <div class="settings-dropzone" id="dropzone_school_id" ondragover="handleDragOver(event, this)" ondragleave="handleDragLeave(this)" ondrop="handleDrop(event, 'school_id_input', 'school_id_label')">
                                <svg class="dropzone-icon" viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                    <polyline points="21 15 16 10 5 21"></polyline>
                                </svg>
                                <div id="school_id_label" class="dropzone-filename"><?= $user['school_id_file'] !== '' ? htmlspecialchars(basename($user['school_id_file'])) : 'No file chosen' ?></div>
                                <div class="dropzone-hint">Drag and drop your file here, or browse</div>
                                <div class="dropzone-btn-row">
                                    <button type="button" class="dropzone-btn" onclick="document.getElementById('school_id_input').click()">
                                        Upload File
                                    </button>
                                </div>
                                <input type="file" id="school_id_input" name="school_id" accept="image/*" style="display: none;" onchange="updateFileName(this, 'school_id_label')">
                            </div>
                        </div>

                        <!-- Government ID Dropzone -->
                        <div>
                            <label class="settings-label" style="margin-bottom: 6px; display:block;">Government ID</label>
                            <div class="settings-dropzone" id="dropzone_gov_id" ondragover="handleDragOver(event, this)" ondragleave="handleDragLeave(this)" ondrop="handleDrop(event, 'gov_id_input', 'gov_id_label')">
                                <svg class="dropzone-icon" viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                    <polyline points="21 15 16 10 5 21"></polyline>
                                </svg>
                                <div id="gov_id_label" class="dropzone-filename"><?= $user['gov_id_file'] !== '' ? htmlspecialchars(basename($user['gov_id_file'])) : 'No file chosen' ?></div>
                                <div class="dropzone-hint">Drag and drop your file here, or browse</div>
                                <div class="dropzone-btn-row">
                                    <button type="button" class="dropzone-btn" onclick="document.getElementById('gov_id_input').click()">
                                        Upload File
                                    </button>
                                </div>
                                <input type="file" id="gov_id_input" name="gov_id" accept="image/*" style="display: none;" onchange="updateFileName(this, 'gov_id_label')">
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px;">
                        <button type="button" class="btn-settings-secondary" onclick="toggleEditMode()">Cancel</button>
                        <button type="submit" class="btn-settings-primary">Save Changes</button>
                    </div>
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
                    <p style="color: #78665c; font-size: 0.94rem; margin-bottom: 22px;">Choose how you want to be notified about lease updates, landlord messages, and announcements.</p>
                    
                    <div class="switch-label">
                        <div>
                            <strong style="color: #3b2820; font-size: 0.95rem;">Push Notifications</strong>
                            <p style="margin: 3px 0 0; font-size: 0.82rem; color: #8b7467;">Receive push alerts on your device for room availability and verified bookings.</p>
                        </div>
                        <label class="switch-toggle" aria-label="Push notifications toggle">
                            <input type="checkbox" checked>
                            <span class="switch-slider"></span>
                        </label>
                    </div>

                    <div class="switch-label">
                        <div>
                            <strong style="color: #3b2820; font-size: 0.95rem;">Email Announcements</strong>
                            <p style="margin: 3px 0 0; font-size: 0.82rem; color: #8b7467;">Get email updates regarding university announcements and student dorm guidelines.</p>
                        </div>
                        <label class="switch-toggle" aria-label="Email announcements toggle">
                            <input type="checkbox" checked>
                            <span class="switch-slider"></span>
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
                    <input type="email" name="confirm_email" class="settings-input" style="width:100%;" placeholder="<?= htmlspecialchars($user['personal_email'] ?: ($user['email'] ?? '')); ?>" required>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn-settings-secondary" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="btn-settings-danger">I understand, delete account</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        window.UNISTAY_TENANT_PROFILE = <?= json_encode($tenantProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

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
            }

            const titles = {
                'profile': 'User Profile',
                'security': 'Account Settings & Security',
                'notifications': 'Notification Settings'
            };
            const headerTitle = document.getElementById('headerTitle');
            if (headerTitle) headerTitle.innerText = titles[tabName] || 'Settings';
        }

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

        function previewAvatar(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const container = document.getElementById('avatarPreviewContainer');
                    if (container) {
                        container.innerHTML = '<img src="' + e.target.result + '" style="width:100%; height:100%; object-fit:cover;" alt="Avatar">';
                    }
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        function updateFileName(input, labelId) {
            if (input.files && input.files[0]) {
                const el = document.getElementById(labelId);
                if (el) el.innerText = input.files[0].name;
            }
        }

        function handleDragOver(e, dropzone) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.add('dragover');
        }

        function handleDragLeave(dropzone) {
            dropzone.classList.remove('dragover');
        }

        function handleDrop(e, inputId, labelId) {
            e.preventDefault();
            e.stopPropagation();
            const dropzone = e.currentTarget;
            dropzone.classList.remove('dragover');

            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                const fileInput = document.getElementById(inputId);
                fileInput.files = e.dataTransfer.files;
                const label = document.getElementById(labelId);
                if (label) label.innerText = e.dataTransfer.files[0].name;
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

        window.addEventListener('DOMContentLoaded', function() {
            const params = new URLSearchParams(window.location.search);
            if (params.get('edit') === '1' || window.location.hash === '#edit') {
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
</body>
</html>