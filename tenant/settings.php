<?php
require_once '../config/database.php';
session_start();

$user_id = $_SESSION['user_id'] ?? 1;
$successMessage = '';
$errorMessage = '';

// Handle Profile Update Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $firstName              = trim($_POST['first_name'] ?? '');
    $middleName             = trim($_POST['middle_name'] ?? '');
    $lastName               = trim($_POST['last_name'] ?? '');
    $phoneNumber            = trim($_POST['phone_number'] ?? '');
    $messengerLink          = trim($_POST['messenger_link'] ?? '');
    $personalEmail          = trim($_POST['personal_email'] ?? '');
    $dateOfBirth            = trim($_POST['date_of_birth'] ?? '');
    $gender                 = trim($_POST['gender'] ?? '');
    $school                 = trim($_POST['school'] ?? '');
    $emergencyName          = trim($_POST['emergency_name'] ?? '');
    $emergencyRelationship  = trim($_POST['emergency_relationship'] ?? '');
    $emergencyPhone         = trim($_POST['emergency_phone'] ?? '');

    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $profilePhotoPath = $_POST['existing_profile_photo'] ?? '';
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $fileName = time() . '_' . basename($_FILES['profile_photo']['name']);
        $destPath = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $destPath)) {
            $profilePhotoPath = $destPath;
        }
    }

    $schoolIdPath = $_POST['existing_school_id'] ?? 'school_id.png';
    if (isset($_FILES['school_id']) && $_FILES['school_id']['error'] === UPLOAD_ERR_OK) {
        $fileName = 'school_id_' . time() . '_' . basename($_FILES['school_id']['name']);
        $destPath = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['school_id']['tmp_name'], $destPath)) {
            $schoolIdPath = $destPath;
        }
    }

    $govIdPath = $_POST['existing_gov_id'] ?? 'gov_id.png';
    if (isset($_FILES['gov_id']) && $_FILES['gov_id']['error'] === UPLOAD_ERR_OK) {
        $fileName = 'gov_id_' . time() . '_' . basename($_FILES['gov_id']['name']);
        $destPath = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['gov_id']['tmp_name'], $destPath)) {
            $govIdPath = $destPath;
        }
    }

    if (isset($pdo) && $pdo) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET 
                first_name = ?, middle_name = ?, last_name = ?, phone_number = ?, 
                messenger_link = ?, personal_email = ?, date_of_birth = ?, gender = ?, 
                school = ?, emergency_name = ?, emergency_relationship = ?, emergency_phone = ?,
                profile_photo = ?, school_id_file = ?, gov_id_file = ?
                WHERE id = ?");
            $stmt->execute([
                $firstName, $middleName, $lastName, $phoneNumber, 
                $messengerLink, $personalEmail, $dateOfBirth, $gender, 
                $school, $emergencyName, $emergencyRelationship, $emergencyPhone,
                $profilePhotoPath, $schoolIdPath, $govIdPath, $user_id
            ]);
            $successMessage = "Profile updated successfully!";
        } catch (\PDOException $e) {
            $errorMessage = "Database update failed: " . $e->getMessage();
        }
    }
}

// Fetch User Data
$user = [
    'first_name'             => 'John',
    'middle_name'            => 'A.',
    'last_name'              => 'Doe',
    'phone_number'           => '09123456789',
    'messenger_link'         => 'https://m.me/johndoe',
    'personal_email'         => 'john.doe@example.com',
    'date_of_birth'          => '2000-01-01',
    'gender'                 => 'Male',
    'school'                 => 'Universidad de Dagupan',
    'emergency_name'         => 'Jane Doe',
    'emergency_relationship' => 'Parent',
    'emergency_phone'        => '09987654321',
    'profile_photo'          => '',
    'school_id_file'         => 'school_id.png',
    'gov_id_file'            => 'gov_id.png',
    'role'                   => 'Tenant'
];

if (isset($pdo) && $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch();
        if ($row) {
            $user = array_merge($user, $row);
        }
    } catch (\Exception $e) {}
}

$relationshipOptions = ['Parent', 'Guardian', 'Sibling', 'Relative', 'Friend', 'Other'];
$genderOptions = ['Male', 'Female', 'Other'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniStay - Account Settings & Profile</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: sans-serif; }
        body { background-color: #fcfbf9; display: flex; height: 100vh; overflow: hidden; }

        /* Sidebar */
        aside.sidebar {
            width: 260px;
            background-color: #7c2d12;
            color: #fff;
            display: flex;
            flex-direction: column;
            padding: 20px;
            flex-shrink: 0;
        }
        .logo-container {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 40px;
            font-size: 18px;
            font-weight: bold;
        }
        .logo-placeholder { width: 35px; height: 35px; background: #fff; border-radius: 50%; }
        aside.sidebar nav a {
            display: block;
            color: #d6d3d1;
            text-decoration: none;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 8px;
            font-size: 15px;
        }
        aside.sidebar nav a:hover, aside.sidebar nav a.active {
            background-color: rgba(255, 255, 255, 0.1);
            color: #fff;
            font-weight: bold;
        }

        /* Main Wrapper */
        .main-wrapper { flex: 1; display: flex; flex-direction: column; overflow-y: auto; position: relative; }

        /* Header */
        header.top-header {
            background: #fff;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e7e5e4;
        }
        header.top-header h1 { font-size: 22px; color: #292524; }
        .avatar-circle { width: 38px; height: 38px; background: #d1d5db; border-radius: 50%; flex-shrink: 0; overflow: hidden; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; color: #555; }
        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }

        /* Header Right Controls */
        .header-right { display: flex; align-items: center; gap: 20px; position: relative; }
        .notif-btn { background: none; border: none; font-size: 18px; cursor: pointer; position: relative; padding: 5px; color: #57534e; }
        .notif-badge { position: absolute; top: 0; right: 0; background: #dc2626; color: white; font-size: 10px; width: 14px; height: 14px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }

        /* Dropdowns */
        .notif-dropdown, .profile-dropdown {
            display: none;
            position: absolute;
            top: 50px; right: 0;
            width: 300px;
            background: #fff;
            border: 1px solid #e7e5e4;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-radius: 8px;
            z-index: 100;
            padding: 15px;
        }
        .profile-dropdown { width: 220px; }
        .notif-item { padding: 10px 0; border-bottom: 1px solid #f0eeec; font-size: 13px; color: #44403c; }
        .notif-item:last-child { border-bottom: none; }
        .profile-menu-container { position: relative; cursor: pointer; }
        .profile-trigger { display: flex; align-items: center; gap: 10px; }
        .profile-dropdown a { display: block; padding: 8px 10px; color: #374151; text-decoration: none; font-size: 13px; border-radius: 4px; }
        .profile-dropdown a:hover { background: #f3f4f6; color: #7c2d12; }
        .profile-dropdown .logout-link { color: #dc2626; font-weight: bold; border-top: 1px solid #f0eeec; margin-top: 5px; padding-top: 10px; }

        /* Content Body */
        .content-body { padding: 30px; max-width: 1000px; margin: 0 auto; width: 100%; }

        /* Tabs Navigation */
        .tabs { display: flex; gap: 20px; border-bottom: 2px solid #e5e7eb; margin-bottom: 25px; }
        .tab-btn { background: none; border: none; padding-bottom: 10px; font-size: 16px; cursor: pointer; color: #6b7280; font-weight: normal; }
        .tab-btn.active { color: #7c2d12; border-bottom: 2px solid #7c2d12; font-weight: bold; margin-bottom: -2px; }

        /* Cards & Elements */
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); margin-bottom: 25px; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Buttons & Alerts */
        .btn-primary { background: #7c2d12; color: white; padding: 10px 20px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; }
        .btn-secondary { background: #fff; border: 1px solid #7c2d12; color: #7c2d12; padding: 8px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 14px; }
        .btn-secondary:hover { background: #7c2d12; color: #fff; }
        .alert-success { background: #d1fae5; color: #065f46; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
        .alert-error { background: #fee2e2; color: #991b1b; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }

        /* Profile Specific Styles */
        .profile-view-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px; }
        .info-group label { display: block; font-size: 12px; color: #78716c; text-transform: uppercase; font-weight: bold; margin-bottom: 4px; }
        .info-group span { font-size: 14px; color: #292524; }
        .avatar-edit-container { position: relative; width: 70px; height: 70px; flex-shrink: 0; }
        .avatar-edit-container .avatar-placeholder { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; background: #d1d5db; display: flex; align-items: center; justify-content: center; color: #555; font-size: 12px; font-weight: bold; overflow: hidden; }
        .avatar-edit-btn {
            position: absolute; bottom: 0; right: 0;
            background: #7c2d12; color: white; border: 2px solid white;
            width: 24px; height: 24px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        /* Drag and Drop Upload Zone Styles */
        .upload-dropzone {
            border: 2px dashed #d7d3d0;
            background: #fafaf9;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            font-size: 13px;
            color: #78716c;
            transition: all 0.2s ease;
        }
        .upload-dropzone:hover, .upload-dropzone.dragover {
            border-color: #7c2d12;
            background: #fdfbfb;
        }
        .dropzone-icon {
            width: 36px;
            height: 36px;
            margin: 0 auto 8px auto;
            color: #9ca3af;
        }
        .upload-options {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 12px;
        }
        .upload-option-btn {
            background: #fff;
            border: 1px solid #d1d5db;
            color: #374151;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .upload-option-btn svg {
            width: 14px;
            height: 14px;
            stroke: currentColor;
            fill: none;
        }
        .upload-option-btn:hover {
            background: #7c2d12;
            color: #fff;
            border-color: #7c2d12;
        }

        /* Account & Security Specific Styles */
        .password-row { display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid #f0eeec; }
        .password-frame { display: none; background: #fafaf9; border: 1px solid #e7e5e4; padding: 20px; border-radius: 8px; margin-top: 15px; }
        .danger-zone { border: 1px solid #fee2e2; background: #fff5f5; padding: 20px; border-radius: 8px; margin-top: 30px; }
        .btn-danger { background: #dc2626; color: white; padding: 9px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; margin-top: 10px; }
        
        /* Modal Overlay */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); justify-content: center; align-items: center; z-index: 1000; }
        .modal-card { background: #fff; padding: 30px; border-radius: 12px; width: 100%; max-width: 450px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); }

        /* Toggle Switch for Notifications */
        .switch { position: relative; display: inline-block; width: 44px; height: 24px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 24px; }
        .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #7c2d12; }
        input:checked + .slider:before { transform: translateX(20px); }
    </style>
</head>
<body>

    <!-- Sidebar Navigation -->
    <aside class="sidebar">
        <div class="logo-container">
            <div class="logo-placeholder"></div>
            <span>UniStay</span>
        </div>
        <nav>
            <a href="dashboard.php">Dashboard</a>
            <a href="map.php">Map</a>
            <a href="rooms.php">Rooms</a>
            <a href="settings.php" class="active">Settings & Profile</a>
        </nav>
    </aside>

    <div class="main-wrapper">
        <!-- Top Header Bar -->
        <header class="top-header">
            <h1 id="headerTitle">User Profile</h1>

            <div class="header-right">
                <!-- Notifications Popover Trigger -->
                <div style="position: relative;">
                    <button type="button" class="notif-btn" onclick="toggleNotifications()">
                        🔔 <span class="notif-badge">3</span>
                    </button>
                    <div id="notifDropdown" class="notif-dropdown">
                        <div style="font-weight: bold; font-size: 13px; margin-bottom: 10px; color: #292524;">Notifications</div>
                        <div class="notif-item">Your rent payment was verified successfully.</div>
                        <div class="notif-item">New dormitory rule announcement updated.</div>
                        <div class="notif-item">Maintenance request status changed to In Progress.</div>
                        <div style="text-align: center; margin-top: 10px;">
                            <a href="#" onclick="switchTab('notifications'); toggleNotifications(); return false;" style="font-size: 12px; color: #7c2d12; text-decoration: none; font-weight: bold;">View all notifications</a>
                        </div>
                    </div>
                </div>

                <!-- Profile Preview Popover Trigger -->
                <div class="profile-menu-container" onclick="toggleProfileDropdown(event)">
                    <div class="profile-trigger">
                        <div class="avatar-circle">
                            <?php if (!empty($user['profile_photo'])): ?>
                                <img src="<?= htmlspecialchars($user['profile_photo']) ?>" alt="Avatar">
                            <?php else: ?>
                                <?= strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)) ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div style="font-size: 13px; font-weight: bold; color: #292524;"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                            <div style="font-size: 11px; color: #78716c;"><?= htmlspecialchars($user['role'] ?? 'Tenant'); ?></div>
                        </div>
                    </div>

                    <div id="profileDropdown" class="profile-dropdown">
                        <div style="padding: 5px 10px; font-size: 12px; color: #78716c; border-bottom: 1px solid #f0eeec; margin-bottom: 5px;">Signed in as <strong><?= htmlspecialchars($user['first_name']); ?></strong></div>
                        <a href="#" onclick="switchTab('profile')">View Profile</a>
                        <a href="../auth/logout.php" class="logout-link">Log Out</a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <div class="content-body">
            <?php if (!empty($successMessage)): ?>
                <div class="alert-success"><?= htmlspecialchars($successMessage) ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert-error"><?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>

            <!-- Navigation Tabs -->
            <div class="tabs">
                <button type="button" class="tab-btn active" onclick="switchTab('profile')" id="btn-profile">Profile</button>
                <button type="button" class="tab-btn" onclick="switchTab('security')" id="btn-security">Account & Security</button>
                <button type="button" class="tab-btn" onclick="switchTab('notifications')" id="btn-notifications">Notifications</button>
            </div>

            <!-- TAB 1: PROFILE -->
            <div id="tab-profile" class="tab-content active">
                <!-- VIEW MODE -->
                <div id="profileViewCard" class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                        <div style="display: flex; align-items: center; gap: 20px;">
                            <div style="width: 70px; height: 70px; border-radius: 50%; background: #d1d5db; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #555; font-size: 18px; overflow: hidden;">
                                <?php if (!empty($user['profile_photo'])): ?>
                                    <img src="<?= htmlspecialchars($user['profile_photo']) ?>" style="width:100%; height:100%; object-fit:cover;" alt="Avatar">
                                <?php else: ?>
                                    <?= strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)) ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h2 style="color: #292524; font-size: 20px; margin-bottom: 4px;"><?= htmlspecialchars($user['last_name'] . ', ' . $user['first_name'] . ' ' . $user['middle_name']) ?></h2>
                                <span style="background: #fef3c7; color: #d97706; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: bold;"><?= htmlspecialchars($user['role']) ?></span>
                            </div>
                        </div>
                        <button type="button" class="btn-secondary" onclick="toggleEditMode()">Edit Profile</button>
                    </div>

                    <h3 style="color: #292524; font-size: 16px; margin-bottom: 15px; border-bottom: 1px solid #f0eeec; padding-bottom: 8px;">Personal Information</h3>
                    <div class="profile-view-grid">
                        <div class="info-group"><label>First Name</label><span><?= htmlspecialchars($user['first_name']) ?></span></div>
                        <div class="info-group"><label>Middle Name</label><span><?= htmlspecialchars($user['middle_name']) ?></span></div>
                        <div class="info-group"><label>Last Name</label><span><?= htmlspecialchars($user['last_name']) ?></span></div>
                        <div class="info-group"><label>Phone Number</label><span><?= htmlspecialchars($user['phone_number']) ?></span></div>
                        <div class="info-group"><label>Messenger Link</label><span><?= htmlspecialchars($user['messenger_link']) ?></span></div>
                        <div class="info-group"><label>Email Address</label><span><?= htmlspecialchars($user['personal_email']) ?></span></div>
                        <div class="info-group"><label>Birthdate</label><span><?= htmlspecialchars($user['date_of_birth']) ?></span></div>
                        <div class="info-group"><label>Gender</label><span><?= htmlspecialchars($user['gender']) ?></span></div>
                        <div class="info-group"><label>School</label><span><?= htmlspecialchars($user['school']) ?></span></div>
                    </div>

                    <h3 style="color: #292524; font-size: 16px; margin: 25px 0 15px 0; border-bottom: 1px solid #f0eeec; padding-bottom: 8px;">Emergency Contact</h3>
                    <div class="profile-view-grid">
                        <div class="info-group"><label>Emergency Contact Name</label><span><?= htmlspecialchars($user['emergency_name']) ?></span></div>
                        <div class="info-group"><label>Relationship</label><span><?= htmlspecialchars($user['emergency_relationship']) ?></span></div>
                        <div class="info-group"><label>Phone Number</label><span><?= htmlspecialchars($user['emergency_phone']) ?></span></div>
                    </div>

                    <h3 style="color: #292524; font-size: 16px; margin: 25px 0 15px 0; border-bottom: 1px solid #f0eeec; padding-bottom: 8px;">Uploaded Identification</h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div><label style="font-size: 12px; color: #78716c; font-weight: bold;">School ID</label><div style="background: #f5f5f4; padding: 10px; border-radius: 6px; margin-top: 5px; font-size: 13px;"><?= htmlspecialchars(basename($user['school_id_file'])) ?></div></div>
                        <div><label style="font-size: 12px; color: #78716c; font-weight: bold;">Government ID</label><div style="background: #f5f5f4; padding: 10px; border-radius: 6px; margin-top: 5px; font-size: 13px;"><?= htmlspecialchars(basename($user['gov_id_file'])) ?></div></div>
                    </div>
                </div>

                <!-- EDIT MODE FORM -->
                <form id="profileEditForm" action="settings.php" method="POST" enctype="multipart/form-data" class="card" style="display: none;">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="existing_profile_photo" value="<?= htmlspecialchars($user['profile_photo']) ?>">
                    <input type="hidden" name="existing_school_id" value="<?= htmlspecialchars($user['school_id_file']) ?>">
                    <input type="hidden" name="existing_gov_id" value="<?= htmlspecialchars($user['gov_id_file']) ?>">

                    <div style="display: flex; align-items: center; gap: 20px; background: #fafaf9; padding: 15px; border-radius: 8px; margin-bottom: 25px; border: 1px solid #f5f5f4;">
                        <div class="avatar-edit-container">
                            <div class="avatar-placeholder" id="avatarPreviewContainer">
                                <?php if (!empty($user['profile_photo'])): ?>
                                    <img src="<?= htmlspecialchars($user['profile_photo']) ?>" style="width:100%; height:100%; object-fit:cover;" alt="Avatar">
                                <?php else: ?>
                                    <?= strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)) ?>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="avatar-edit-btn" onclick="document.getElementById('profile_photo_input').click();">✎</button>
                            <input type="file" id="profile_photo_input" name="profile_photo" accept="image/*" style="display: none;" onchange="previewAvatar(this)">
                        </div>
                        <div>
                            <h3 style="color: #292524; font-size: 16px; margin-bottom: 2px;"><?= htmlspecialchars($user['last_name'] . ', ' . $user['first_name'] . ' ' . $user['middle_name']) ?></h3>
                            <span style="color: #78716c; font-size: 13px;">Role: <strong><?= htmlspecialchars($user['role']) ?></strong></span>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="color: #292524; font-size: 16px;">Edit Personal Information</h3>
                        <button type="button" class="btn-secondary" onclick="toggleEditMode()">Cancel</button>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 15px;">
                        <div>
                            <label style="display: block; font-size: 13px; color: #44403c; margin-bottom: 5px; font-weight: 500;">First Name</label>
                            <input type="text" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>" style="width:100%; padding: 9px; border:1px solid #d7d3d0; border-radius:6px;" required>
                        </div>
                        <div>
                            <label style="display: block; font-size: 13px; color: #44403c; margin-bottom: 5px; font-weight: 500;">Middle Name</label>
                            <input type="text" name="middle_name" value="<?= htmlspecialchars($user['middle_name']) ?>" style="width:100%; padding: 9px; border:1px solid #d7d3d0; border-radius:6px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 13px; color: #44403c; margin-bottom: 5px; font-weight: 500;">Last Name</label>
                            <input type="text" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>" style="width:100%; padding: 9px; border:1px solid #d7d3d0; border-radius:6px;" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 15px;">
                        <div>
                            <label style="display: block; font-size: 13px; color: #44403c; margin-bottom: 5px; font-weight: 500;">Phone Number</label>
                            <input type="text" name="phone_number" value="<?= htmlspecialchars($user['phone_number']) ?>" style="width:100%; padding: 9px; border:1px solid #d7d3d0; border-radius:6px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 13px; color: #44403c; margin-bottom: 5px; font-weight: 500;">Messenger Link</label>
                            <input type="text" name="messenger_link" value="<?= htmlspecialchars($user['messenger_link']) ?>" style="width:100%; padding: 9px; border:1px solid #d7d3d0; border-radius:6px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 13px; color: #44403c; margin-bottom: 5px; font-weight: 500;">Email Address</label>
                            <input type="email" name="personal_email" value="<?= htmlspecialchars($user['personal_email']) ?>" style="width:100%; padding: 9px; border:1px solid #d7d3d0; border-radius:6px;" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 25px;">
                        <div>
                            <label style="display: block; font-size: 13px; color: #44403c; margin-bottom: 5px; font-weight: 500;">Birthdate</label>
                            <input type="date" name="date_of_birth" value="<?= htmlspecialchars($user['date_of_birth']) ?>" style="width:100%; padding: 9px; border:1px solid #d7d3d0; border-radius:6px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 13px; color: #44403c; margin-bottom: 5px; font-weight: 500;">Gender</label>
                            <select name="gender" style="width:100%; padding: 9px; border:1px solid #d7d3d0; border-radius:6px; background:#fff;">
                                <?php foreach ($genderOptions as $opt): ?>
                                    <option value="<?= $opt ?>" <?= ($user['gender'] === $opt) ? 'selected' : '' ?>><?= $opt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display: block; font-size: 13px; color: #44403c; margin-bottom: 5px; font-weight: 500;">School</label>
                            <input type="text" name="school" value="<?= htmlspecialchars($user['school']) ?>" style="width:100%; padding: 9px; border:1px solid #d7d3d0; border-radius:6px;">
                        </div>
                    </div>

                    <h3 style="color: #292524; font-size: 16px; margin-bottom: 15px; border-bottom: 1px solid #f0eeec; padding-bottom: 8px;">Edit Emergency Contact</h3>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 25px;">
                        <div>
                            <label style="display: block; font-size: 13px; color: #44403c; margin-bottom: 5px; font-weight: 500;">Contact Name</label>
                            <input type="text" name="emergency_name" value="<?= htmlspecialchars($user['emergency_name']) ?>" style="width:100%; padding: 9px; border:1px solid #d7d3d0; border-radius:6px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 13px; color: #44403c; margin-bottom: 5px; font-weight: 500;">Relationship</label>
                            <select name="emergency_relationship" style="width:100%; padding: 9px; border:1px solid #d7d3d0; border-radius:6px; background:#fff;">
                                <?php foreach ($relationshipOptions as $rel): ?>
                                    <option value="<?= $rel ?>" <?= ($user['emergency_relationship'] === $rel) ? 'selected' : '' ?>><?= $rel ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display: block; font-size: 13px; color: #44403c; margin-bottom: 5px; font-weight: 500;">Phone Number</label>
                            <input type="text" name="emergency_phone" value="<?= htmlspecialchars($user['emergency_phone']) ?>" style="width:100%; padding: 9px; border:1px solid #d7d3d0; border-radius:6px;">
                        </div>
                    </div>

                    <h3 style="color: #292524; font-size: 16px; margin-bottom: 15px; border-bottom: 1px solid #f0eeec; padding-bottom: 8px;">Update Identification Documents</h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px;">
                        
                        <!-- School ID Upload Box -->
                        <div>
                            <label style="display: block; font-size: 13px; color: #44403c; margin-bottom: 5px; font-weight: 500;">School ID</label>
                            <div class="upload-dropzone" id="dropzone_school_id" ondragover="handleDragOver(event, this)" ondragleave="handleDragLeave(this)" ondrop="handleDrop(event, 'school_id_input', 'school_id_label')">
                                <div class="dropzone-icon">
                                    <svg viewBox="0 0 24 24" width="36" height="36" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                                </div>
                                <div id="school_id_label" style="font-weight: 500; color: #292524;"><?= htmlspecialchars(basename($user['school_id_file'])) ?></div>
                                <div style="font-size: 11px; color: #78716c; margin-top: 2px;">Drag and drop your files here</div>
                                <div class="upload-options">
                                    <button type="button" class="upload-option-btn" onclick="document.getElementById('school_id_input').click()">
                                        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg> Upload File
                                    </button>
                                    <button type="button" class="upload-option-btn" onclick="document.getElementById('school_id_input').click()">
                                        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg> Select Photo
                                    </button>
                                </div>
                                <input type="file" id="school_id_input" name="school_id" accept="image/*" style="display: none;" onchange="updateFileName(this, 'school_id_label')">
                            </div>
                        </div>

                        <!-- Government ID Upload Box -->
                        <div>
                            <label style="display: block; font-size: 13px; color: #44403c; margin-bottom: 5px; font-weight: 500;">Government ID</label>
                            <div class="upload-dropzone" id="dropzone_gov_id" ondragover="handleDragOver(event, this)" ondragleave="handleDragLeave(this)" ondrop="handleDrop(event, 'gov_id_input', 'gov_id_label')">
                                <div class="dropzone-icon">
                                    <svg viewBox="0 0 24 24" width="36" height="36" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                                </div>
                                <div id="gov_id_label" style="font-weight: 500; color: #292524;"><?= htmlspecialchars(basename($user['gov_id_file'])) ?></div>
                                <div style="font-size: 11px; color: #78716c; margin-top: 2px;">Drag and drop your files here</div>
                                <div class="upload-options">
                                    <button type="button" class="upload-option-btn" onclick="document.getElementById('gov_id_input').click()">
                                        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg> Upload File
                                    </button>
                                    <button type="button" class="upload-option-btn" onclick="document.getElementById('gov_id_input').click()">
                                        <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg> Select Photo
                                    </button>
                                </div>
                                <input type="file" id="gov_id_input" name="gov_id" accept="image/*" style="display: none;" onchange="updateFileName(this, 'gov_id_label')">
                            </div>
                        </div>

                    </div>

                    <div style="text-align: right;">
                        <button type="submit" class="btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>

            <!-- TAB 2: ACCOUNT & SECURITY -->
            <div id="tab-security" class="tab-content">
                <div class="card">
                    <h3 style="color: #292524; margin-bottom: 5px;">Account Settings and Security</h3>
                    <p style="color: #6b7280; font-size: 14px; margin-bottom: 20px;">Manage your login credentials, safety, and account deletion tasks.</p>

                    <div style="display: flex; justify-content: space-between; align-items: center; background: #fafaf9; padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; border: 1px solid #f5f5f4;">
                        <div style="display: flex; gap: 12px; align-items: center;">
                            <div style="width: 40px; height: 40px; background: #d1d5db; border-radius: 50%; overflow: hidden; display: flex; align-items: center; justify-content: center;">
                                <?php if (!empty($user['profile_photo'])): ?>
                                    <img src="<?= htmlspecialchars($user['profile_photo']) ?>" style="width:100%; height:100%; object-fit:cover;" alt="Avatar">
                                <?php else: ?>
                                    <?= strtoupper(substr($user['first_name'], 0, 1)) ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <strong style="font-size: 14px; color: #292524;"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></strong><br>
                                <small style="color: #78716c;"><?= htmlspecialchars($user['personal_email']); ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="password-row">
                        <div>
                            <strong style="color: #292524; font-size: 15px;">Password</strong>
                            <p style="margin: 0; font-size: 13px; color: #78716c;">Modify your account password securely.</p>
                        </div>
                        <button type="button" class="btn-secondary" onclick="togglePasswordFrame()">change password?</button>
                    </div>

                    <div id="passwordFrame" class="password-frame">
                        <form action="update-password.php" method="POST">
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; font-size: 13px; color: #44403c; margin-bottom: 5px; font-weight: 500;">Current password *</label>
                                <input type="password" name="current_password" placeholder="Enter current password" style="width:100%; padding: 10px; border:1px solid #d7d3d0; border-radius:6px; background: #fff;" required>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                                <div>
                                    <label style="display: block; font-size: 13px; color: #44403c; margin-bottom: 5px; font-weight: 500;">New password *</label>
                                    <input type="password" name="new_password" placeholder="Enter new password" style="width:100%; padding: 10px; border:1px solid #d7d3d0; border-radius:6px; background: #fff;" required>
                                </div>
                                <div>
                                    <label style="display: block; font-size: 13px; color: #44403c; margin-bottom: 5px; font-weight: 500;">Confirm password *</label>
                                    <input type="password" name="confirm_password" placeholder="Confirm new password" style="width:100%; padding: 10px; border:1px solid #d7d3d0; border-radius:6px; background: #fff;" required>
                                </div>
                            </div>
                            <div style="display: flex; gap: 10px; margin-top: 20px;">
                                <button type="submit" class="btn-primary">Save Changes</button>
                                <button type="button" onclick="togglePasswordFrame()" style="background: transparent; border: 1px solid #d1d5db; padding: 10px 15px; border-radius: 6px; cursor: pointer; color: #374151;">Cancel</button>
                            </div>
                        </form>
                    </div>

                    <div class="danger-zone">
                        <h4 style="color: #dc2626; font-size: 15px; margin-bottom: 4px;">Danger Zone</h4>
                        <p style="font-size: 12px; color: #6b7280; margin-bottom: 10px;">Once you delete your account, there is no going back. Please be certain.</p>
                        <button type="button" class="btn-danger" onclick="openDeleteModal()">Delete Account</button>
                    </div>
                </div>
            </div>

            <!-- TAB 3: NOTIFICATIONS -->
            <div id="tab-notifications" class="tab-content">
                <div class="card">
                    <h3 style="color: #292524; margin-bottom: 5px;">Notification Preferences</h3>
                    <p style="color: #6b7280; font-size: 14px; margin-bottom: 20px;">Choose how you want to be notified about lease updates, messages, and announcements.</p>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-bottom: 1px solid #f0eeec;">
                        <div>
                            <strong style="color: #292524; font-size: 14px;">Push Notifications</strong>
                            <p style="font-size: 12px; color: #78716c;">Receive push alerts on your device for critical room and payment updates.</p>
                        </div>
                        <label class="switch">
                            <input type="checkbox" checked>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Panel Modal for Delete Account Task -->
    <div id="deleteModal" class="modal-overlay">
        <div class="modal-card">
            <h3 style="color: #dc2626; margin-top: 0; font-size: 18px;">Delete Account Confirmation</h3>
            <p style="font-size: 13px; color: #4b5563; line-height: 1.5; margin-bottom: 15px;">To confirm account deletion, please type your account email address below:</p>
            <form action="delete-account.php" method="POST">
                <div style="margin-bottom: 15px;">
                    <input type="email" name="confirm_email" placeholder="<?= htmlspecialchars($user['personal_email']); ?>" style="width:100%; padding: 10px; border:1px solid #d7d3d0; border-radius:6px;" required>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="closeDeleteModal()" style="background: #f3f4f6; border: 1px solid #d1d5db; padding: 9px 15px; border-radius: 6px; cursor: pointer;">Cancel</button>
                    <button type="submit" style="background: #dc2626; color: white; border: none; padding: 9px 15px; border-radius: 6px; font-weight: bold; cursor: pointer;">I understand, delete account</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tab Switching Logic
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));

            document.getElementById('tab-' + tabName).classList.add('active');
            document.getElementById('btn-' + tabName).classList.add('active');

            const titles = {
                'profile': 'User Profile',
                'security': 'Account Settings',
                'notifications': 'Notification Settings'
            };
            document.getElementById('headerTitle').innerText = titles[tabName];
        }

        function togglePasswordFrame() {
            const frame = document.getElementById('passwordFrame');
            frame.style.display = (frame.style.display === 'block') ? 'none' : 'block';
        }

        function toggleEditMode() {
            const viewCard = document.getElementById('profileViewCard');
            const editForm = document.getElementById('profileEditForm');
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
                    container.innerHTML = '<img src="' + e.target.result + '" style="width:100%; height:100%; object-fit:cover;" alt="Avatar">';
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function updateFileName(input, labelId) {
            if (input.files && input.files[0]) {
                document.getElementById(labelId).innerText = input.files[0].name;
            }
        }

        // Drag and Drop Handlers
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
                document.getElementById(labelId).innerText = e.dataTransfer.files[0].name;
            }
        }

        function toggleNotifications() {
            const dropdown = document.getElementById('notifDropdown');
            dropdown.style.display = (dropdown.style.display === 'block') ? 'none' : 'block';
            document.getElementById('profileDropdown').style.display = 'none';
        }

        function toggleProfileDropdown(event) {
            event.stopPropagation();
            const dropdown = document.getElementById('profileDropdown');
            dropdown.style.display = (dropdown.style.display === 'block') ? 'none' : 'block';
            document.getElementById('notifDropdown').style.display = 'none';
        }

        function openDeleteModal() { document.getElementById('deleteModal').style.display = 'flex'; }
        function closeDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }

        window.onclick = function(event) {
            if (!event.target.closest('.header-right')) {
                document.getElementById('notifDropdown').style.display = 'none';
                document.getElementById('profileDropdown').style.display = 'none';
            }
        }
    </script>
</body>
</html>