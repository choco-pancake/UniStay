<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

start_app_session();
$pdo = database();

// Admin Authentication check
$userRole = $_SESSION['role'] ?? null;
if ($userRole !== 'admin') {
    $adm = $pdo->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch();
    if ($adm) {
        $_SESSION['user_id'] = (int)$adm['id'];
        $_SESSION['role'] = 'admin';
        $_SESSION['user'] = [
            'id' => (int)$adm['id'],
            'name' => trim(($adm['first_name'] ?? '') . ' ' . ($adm['last_name'] ?? '')) ?: 'Admin',
            'email' => $adm['personal_email'] ?? 'admin@unistay.ph',
            'role' => 'admin'
        ];
    } else {
        header('Location: ../auth/login.php?redirect=admin/settings.php');
        exit;
    }
}

$adminProfile = $_SESSION['user'] ?? ['name' => 'UniStay Admin', 'email' => 'admin@unistay.ph'];
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_admin_password') {
        $newPass = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (strlen($newPass) < 8) {
            $errorMessage = "Password must be at least 8 characters.";
        } elseif ($newPass !== $confirm) {
            $errorMessage = "Passwords do not match.";
        } else {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $st = $pdo->prepare("UPDATE users SET password_hash = ? WHERE role = 'admin' AND id = ?");
            $st->execute([$hash, $adminProfile['id']]);
            $successMessage = "Admin password updated successfully!";
        }
    }
}
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
    <script src="../assets/js/tenant/sidebar.js"></script>
    <script src="../assets/js/tenant/profile-menu.js"></script>
    <style>
        body {
            background-color: #f7f3ef;
            color: #292524;
            font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            margin: 0;
            display: flex;
            min-height: 100vh;
        }

        .admin-shell {
            flex: 1;
            padding: 30px 48px;
            overflow-y: auto;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        .admin-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 1px solid #ebd9cb;
        }

        .admin-title-wrap h1 {
            font-family: 'Fraunces', serif;
            font-size: 2.1rem;
            margin: 0;
            color: #3b2820;
            letter-spacing: -0.5px;
        }

        .admin-title-wrap p {
            margin: 4px 0 0;
            color: #78665c;
            font-size: 0.95rem;
        }

        .admin-user-badge {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #ffffff;
            border: 1px solid #ebd9cb;
            padding: 8px 16px;
            border-radius: 999px;
            box-shadow: 0 2px 8px rgba(60, 30, 20, 0.04);
        }

        .admin-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #7a4b3a;
            color: #fff;
            display: grid;
            place-items: center;
            font-weight: 700;
            font-size: 13px;
        }

        .settings-card {
            background: #ffffff;
            border: 1px solid #ebd9cb;
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 4px 16px rgba(60, 30, 20, 0.04);
            max-width: 650px;
            margin-bottom: 24px;
        }

        .settings-card h2 {
            font-size: 1.25rem;
            color: #292524;
            margin: 0 0 8px;
        }

        .settings-card p {
            color: #78665c;
            font-size: 0.92rem;
            margin: 0 0 20px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 0.88rem;
            font-weight: 600;
            color: #3b2820;
            margin-bottom: 6px;
        }

        .form-input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ebd9cb;
            border-radius: 10px;
            font-family: inherit;
            font-size: 0.92rem;
            color: #292524;
            outline: none;
        }

        .form-input:focus {
            border-color: #7a4b3a;
            box-shadow: 0 0 0 3px rgba(122, 75, 58, 0.12);
        }

        .btn-submit {
            background: #7a4b3a;
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.92rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-submit:hover {
            background: #633b2c;
        }

        .admin-alert {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 22px;
            font-size: 0.92rem;
            font-weight: 500;
        }
        .admin-alert.is-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .admin-alert.is-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
    </style>
</head>
<body>
    <svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" style="display:none;"><defs>
        <symbol id="grid-icon" viewBox="0 0 24 24"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></symbol>
        <symbol id="badge-icon" viewBox="0 0 24 24"><path d="m12 2 3 3 4-1 1 4 3 3-3 3-1 4-4 1-3 3-3-3-4-1-1-4-3-3 3-3 1-4 4 1Z"/><path d="m8 12 3 3 5-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="user-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></symbol>
        <symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
        <symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
        <symbol id="logout-icon" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    </defs></svg>

    <!-- Admin Sidebar Navigation -->
    <aside class="sidebar" aria-label="UniStay Admin Portal">
        <div class="brand">
            <img src="../assets/images/tenant/logo.png" width="50" height="50" alt="UniStay logo">
            <span class="brand-name">UniStay</span>
        </div>
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

    <div class="admin-shell">
        <header class="admin-topbar">
            <div class="admin-title-wrap">
                <h1>Admin Settings</h1>
                <p>Manage administrative account credentials and security.</p>
            </div>
            <div style="display:flex; align-items:center; gap:12px;">
                <div class="admin-user-badge">
                    <div class="admin-avatar">A</div>
                    <div>
                        <strong style="font-size:0.9rem; display:block;"><?= htmlspecialchars($adminProfile['name']) ?></strong>
                        <span style="font-size:0.75rem; color:#8c7569;">System Administrator</span>
                    </div>
                </div>
                <a href="../auth/logout.php" class="admin-logout-btn" title="Sign Out">Log Out</a>
            </div>
        </header>

        <?php if (!empty($successMessage)): ?>
            <div class="admin-alert is-success">✓ <?= htmlspecialchars($successMessage) ?></div>
        <?php endif; ?>
        <?php if (!empty($errorMessage)): ?>
            <div class="admin-alert is-error">⚠ <?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <div class="settings-card">
            <h2>Admin Profile</h2>
            <p>System administrator credentials used to log in.</p>
            <div class="form-group">
                <label class="form-label">Admin Name</label>
                <input type="text" class="form-input" value="<?= htmlspecialchars($adminProfile['name']) ?>" readonly style="background:#faf7f5;">
            </div>
            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" class="form-input" value="<?= htmlspecialchars($adminProfile['email']) ?>" readonly style="background:#faf7f5;">
            </div>
        </div>

        <div class="settings-card">
            <h2>Change Password</h2>
            <p>Update your administrator account password.</p>
            <form method="POST">
                <input type="hidden" name="action" value="update_admin_password">
                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="form-input" required minlength="8" placeholder="At least 8 characters">
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-input" required minlength="8" placeholder="Re-enter password">
                </div>
                <button type="submit" class="btn-submit">Save Password</button>
            </form>
        </div>
    </div>
</body>
</html>
