<?php
require_once '../config/database.php';
session_start();
$user_id = $_SESSION['user_id'] ?? 1;

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch() ?: [
    'first_name' => 'John', 
    'last_name' => 'Doe', 
    'personal_email' => 'john.doe@example.com',
    'role' => 'Tenant'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniStay - Account Settings</title>
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
        .avatar-circle { width: 38px; height: 38px; background: #d1d5db; border-radius: 50%; flex-shrink: 0; }

        /* Header Right Controls (Notifications & Profile Popover) */
        .header-right { display: flex; align-items: center; gap: 20px; position: relative; }
        .notif-btn { background: none; border: none; font-size: 18px; cursor: pointer; position: relative; padding: 5px; color: #57534e; }
        .notif-badge { position: absolute; top: 0; right: 0; background: #dc2626; color: white; font-size: 10px; width: 14px; height: 14px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }

        /* Notifications Dropdown Popover */
        .notif-dropdown {
            display: none;
            position: absolute;
            top: 50px; right: 50px;
            width: 320px;
            background: #fff;
            border: 1px solid #e7e5e4;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-radius: 8px;
            z-index: 100;
            padding: 15px;
        }
        .notif-item { padding: 10px 0; border-bottom: 1px solid #f0eeec; font-size: 13px; color: #44403c; }
        .notif-item:last-child { border-bottom: none; }

        /* Profile Popover Container */
        .profile-menu-container { position: relative; cursor: pointer; }
        .profile-trigger { display: flex; align-items: center; gap: 10px; }
        .profile-dropdown {
            display: none;
            position: absolute;
            top: 50px; right: 0;
            width: 220px;
            background: #fff;
            border: 1px solid #e7e5e4;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-radius: 8px;
            z-index: 100;
            padding: 12px;
        }
        .profile-dropdown a {
            display: block;
            padding: 8px 10px;
            color: #374151;
            text-decoration: none;
            font-size: 13px;
            border-radius: 4px;
        }
        .profile-dropdown a:hover { background: #f3f4f6; color: #7c2d12; }
        .profile-dropdown .logout-link { color: #dc2626; font-weight: bold; border-top: 1px solid #f0eeec; margin-top: 5px; padding-top: 10px; }

        /* Content Body */
        .content-body { padding: 30px; max-width: 1000px; margin: 0 auto; width: 100%; }

        /* Tabs Navigation */
        .tabs { display: flex; gap: 20px; border-bottom: 2px solid #e5e7eb; margin-bottom: 25px; }
        .tab-btn { background: none; border: none; padding-bottom: 10px; font-size: 16px; cursor: pointer; color: #6b7280; text-decoration: none; }
        .tab-btn.active { color: #7c2d12; border-bottom: 2px solid #7c2d12; font-weight: bold; margin-bottom: -2px; }

        /* Cards & Elements */
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); margin-bottom: 25px; }
        .danger-zone { border: 1px solid #fee2e2; background: #fff5f5; padding: 20px; border-radius: 8px; margin-top: 30px; }
        .btn-danger { background: #dc2626; color: white; padding: 9px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; margin-top: 10px; }

        /* Password Section Row & Expandable Frame */
        .password-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #f0eeec;
        }
        .change-pass-btn {
            background: #fff;
            border: 1px solid #7c2d12;
            color: #7c2d12;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .change-pass-btn:hover { background: #7c2d12; color: #fff; }
        .password-frame {
            display: none;
            background: #fafaf9;
            border: 1px solid #e7e5e4;
            padding: 20px;
            border-radius: 8px;
            margin-top: 15px;
        }

        /* Floating Panel Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .modal-card {
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
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
            <h1>Account Settings</h1>

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
                            <a href="notifications.php" style="font-size: 12px; color: #7c2d12; text-decoration: none; font-weight: bold;">View all notifications</a>
                        </div>
                    </div>
                </div>

                <!-- Profile Preview Popover Trigger -->
                <div class="profile-menu-container" onclick="toggleProfileDropdown(event)">
                    <div class="profile-trigger">
                        <div class="avatar-circle"></div>
                        <div>
                            <div style="font-size: 13px; font-weight: bold; color: #292524;"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                            <div style="font-size: 11px; color: #78716c;"><?php echo htmlspecialchars($user['role'] ?? 'Tenant'); ?></div>
                        </div>
                    </div>

                    <div id="profileDropdown" class="profile-dropdown">
                        <div style="padding: 5px 10px; font-size: 12px; color: #78716c; border-bottom: 1px solid #f0eeec; margin-bottom: 5px;">Signed in as <strong><?php echo htmlspecialchars($user['first_name']); ?></strong></div>
                        <a href="profile.php">View Profile</a>
                        <a href="../auth/logout.php" class="logout-link">Log Out</a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <div class="content-body">
            <!-- Navigation Tabs -->
            <div class="tabs">
                <a href="profile.php" class="tab-btn">Profile</a>
                <a href="settings.php" class="tab-btn active">Account & Security</a>
                <a href="notifications.php" class="tab-btn">Notifications</a>
            </div>

            <div class="card">
                <h3 style="color: #292524; margin-bottom: 5px;">Account Settings and Security</h3>
                <p style="color: #6b7280; font-size: 14px; margin-bottom: 20px;">Manage your login credentials, safety, and account deletion tasks.</p>

                <!-- Simplified Profile Details Overview Box -->
                <div style="display: flex; justify-content: space-between; align-items: center; background: #fafaf9; padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; border: 1px solid #f5f5f4;">
                    <div style="display: flex; gap: 12px; align-items: center;">
                        <div style="width: 40px; height: 40px; background: #d1d5db; border-radius: 50%;"></div>
                        <div>
                            <strong style="font-size: 14px; color: #292524;"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></strong><br>
                            <small style="color: #78716c;"><?php echo htmlspecialchars($user['personal_email']); ?></small>
                        </div>
                    </div>
                </div>

                <!-- Password Trigger Row -->
                <div class="password-row">
                    <div>
                        <strong style="color: #292524; font-size: 15px;">Password</strong>
                        <p style="margin: 0; font-size: 13px; color: #78716c;">Modify your account password securely.</p>
                    </div>
                    <button type="button" class="change-pass-btn" onclick="togglePasswordFrame()">change password?</button>
                </div>

                <!-- Expandable Password Frame Form -->
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
                            <button type="submit" style="background: #7c2d12; padding: 10px 20px; border: none; border-radius: 6px; color: white; font-weight: bold; cursor: pointer;">Save Changes</button>
                            <button type="button" onclick="togglePasswordFrame()" style="background: transparent; border: 1px solid #d1d5db; padding: 10px 15px; border-radius: 6px; cursor: pointer; color: #374151;">Cancel</button>
                        </div>
                    </form>
                </div>

                <!-- Danger Zone -->
                <div class="danger-zone">
                    <h4 style="color: #dc2626; font-size: 15px; margin-bottom: 4px;">Danger Zone</h4>
                    <p style="font-size: 12px; color: #6b7280; margin-bottom: 10px;">Once you delete your account, there is no going back. Please be certain.</p>
                    <button type="button" class="btn-danger" onclick="openDeleteModal()">Delete Account</button>
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
                    <input type="email" name="confirm_email" placeholder="<?php echo htmlspecialchars($user['personal_email']); ?>" style="width:100%; padding: 10px; border:1px solid #d7d3d0; border-radius:6px;" required>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="closeDeleteModal()" style="background: #f3f4f6; border: 1px solid #d1d5db; padding: 9px 15px; border-radius: 6px; cursor: pointer;">Cancel</button>
                    <button type="submit" style="background: #dc2626; color: white; border: none; padding: 9px 15px; border-radius: 6px; font-weight: bold; cursor: pointer;">I understand, delete account</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function togglePasswordFrame() {
            const frame = document.getElementById('passwordFrame');
            frame.style.display = (frame.style.display === 'block') ? 'none' : 'block';
        }

        function toggleNotifications() {
            const dropdown = document.getElementById('notifDropdown');
            const profileDropdown = document.getElementById('profileDropdown');
            profileDropdown.style.display = 'none'; // close other popover
            dropdown.style.display = (dropdown.style.display === 'block') ? 'none' : 'block';
        }

        function toggleProfileDropdown(event) {
            event.stopPropagation();
            const dropdown = document.getElementById('profileDropdown');
            const notifDropdown = document.getElementById('notifDropdown');
            notifDropdown.style.display = 'none'; // close other popover
            dropdown.style.display = (dropdown.style.display === 'block') ? 'none' : 'block';
        }

        function openDeleteModal() {
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close popovers when clicking outside
        window.onclick = function(event) {
            if (!event.target.closest('.header-right')) {
                document.getElementById('notifDropdown').style.display = 'none';
                document.getElementById('profileDropdown').style.display = 'none';
            }
        }
    </script>
</body>
</html>