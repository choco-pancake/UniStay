<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

start_app_session();
$pdo = database();
$adminProfile = require_signed_in('admin');

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_landlord') {
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '') ?: null;
    $lastName = trim($_POST['last_name'] ?? '');
    $email = strtolower(trim($_POST['personal_email'] ?? ''));
    $phone = trim($_POST['phone_number'] ?? '') ?: null;
    $password = (string)($_POST['password'] ?? '');

    if ($firstName === '' || $lastName === '') {
        $errorMessage = 'First name and last name are required.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'A valid email address is required.';
    } elseif (strlen($password) < 8) {
        $errorMessage = 'Password must be at least 8 characters long.';
    } else {
        // Check duplicate email
        $chk = $pdo->prepare('SELECT 1 FROM users WHERE personal_email = ? LIMIT 1');
        $chk->execute([$email]);
        if ($chk->fetchColumn()) {
            $errorMessage = 'An account with this email address already exists.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $pdo->prepare(
                    "INSERT INTO users (first_name, middle_name, last_name, personal_email, phone_number, password_hash, role, verification_status, profile_completed, verified_at, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, 'landlord', 'verified', 1, NOW(), NOW())"
                );
                $ins->execute([$firstName, $middleName, $lastName, $email, $phone, $hash]);
                $newId = (int)$pdo->lastInsertId();
                $successMessage = "Landlord account #{$newId} created successfully for <strong>" . htmlspecialchars($email) . "</strong> with password: <code>" . htmlspecialchars($password) . "</code>. Please provide these credentials to the landlord.";
            } catch (Throwable $e) {
                $errorMessage = 'Could not create landlord account: ' . $e->getMessage();
            }
        }
    }
}

// Search & Filter
$search = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? 'all';

$sql = "SELECT id, first_name, last_name, personal_email, phone_number, role, verification_status, created_at FROM users WHERE 1=1";
$params = [];

if ($roleFilter !== 'all' && in_array($roleFilter, ['tenant', 'landlord', 'admin'], true)) {
    $sql .= " AND role = ?";
    $params[] = $roleFilter;
}

if ($search !== '') {
    $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR personal_email LIKE ? OR phone_number LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users · UniStay Admin</title>
    <link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/common.css?v=32">
    <script src="../assets/js/tenant/sidebar.js"></script>
    <script src="../assets/js/tenant/profile-menu.js"></script>
    <script>window.UNISTAY_NOTIFICATION_ACCOUNT=<?= json_encode(['id'=>(int)$adminProfile['id'],'role'=>'admin']) ?>;</script>
    <script defer src="../assets/js/notification-preferences.js?v=3"></script>
    <script defer src="../assets/js/notification-global.js?v=1"></script>
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

        /* Toolbar */
        .users-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .search-form {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            max-width: 680px;
        }

        .search-input {
            width: 100%;
            padding: 10px 16px;
            background: #ffffff;
            border: 1px solid #ebd9cb;
            border-radius: 10px;
            font-family: inherit;
            font-size: 0.92rem;
            color: #292524;
            outline: none;
        }

        .search-input:focus {
            border-color: #7a4b3a;
            box-shadow: 0 0 0 3px rgba(122, 75, 58, 0.12);
        }

        .role-filters {
            display: flex;
            gap: 8px;
        }

        .role-filter-btn {
            padding: 8px 14px;
            background: #ffffff;
            border: 1px solid #ebd9cb;
            border-radius: 8px;
            font-size: 0.88rem;
            color: #78665c;
            text-decoration: none;
            font-weight: 600;
        }

        .role-filter-btn.is-active {
            background: #7a4b3a;
            color: #fff;
            border-color: #7a4b3a;
        }

        /* Table Card */
        .table-card {
            background: #ffffff;
            border: 1px solid #ebd9cb;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 4px 16px rgba(60, 30, 20, 0.04);
            overflow-x: auto;
        }

        .admin-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .admin-table th {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #8c7569;
            padding: 12px 14px;
            border-bottom: 2px solid #ebd9cb;
        }

        .admin-table td {
            padding: 14px;
            font-size: 0.92rem;
            border-bottom: 1px solid #f2e9e2;
            color: #292524;
        }

        .badge-role {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: capitalize;
        }
        .badge-role.landlord { background: #fdf2e9; color: #7c2d12; border: 1px solid #f8d7bd; }
        .badge-role.tenant { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        .badge-role.admin { background: #f3e8ff; color: #6b21a8; border: 1px solid #e9d5ff; }

        .btn-create-landlord {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: #7a4b3a;
            color: #ffffff;
            border: 0;
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.18s, transform 0.18s;
            box-shadow: 0 2px 8px rgba(122, 75, 58, 0.2);
        }
        .btn-create-landlord:hover {
            background: #633c2e;
            transform: translateY(-1px);
        }

        .alert-banner {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.92rem;
            line-height: 1.5;
        }
        .alert-banner.success {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }
        .alert-banner.error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }
        .alert-banner code {
            background: #ffffff;
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid #a7f3d0;
            font-family: monospace;
            font-weight: 700;
        }

        /* Modal Dialog */
        .create-landlord-dialog {
            border: 0;
            border-radius: 20px;
            padding: 28px 32px;
            width: 100%;
            max-width: 520px;
            background: #ffffff;
            box-shadow: 0 24px 60px rgba(60, 30, 20, 0.22);
        }
        .create-landlord-dialog::backdrop {
            background: rgba(30, 15, 10, 0.45);
            backdrop-filter: blur(4px);
        }
        .cl-dialog-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
        }
        .cl-dialog-head h2 {
            margin: 0;
            font-family: 'Fraunces', serif;
            font-size: 1.45rem;
            color: #3b2820;
        }
        .cl-dialog-close {
            background: none;
            border: 0;
            font-size: 1.2rem;
            color: #8c7569;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
        }
        .cl-dialog-close:hover {
            background: #f5ebe3;
            color: #3b2820;
        }
        .cl-dialog-desc {
            margin: 0 0 20px;
            font-size: 0.88rem;
            color: #78665c;
        }
        .cl-form {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .cl-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .cl-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .cl-field label {
            font-size: 0.82rem;
            font-weight: 600;
            color: #593f34;
        }
        .cl-field input {
            padding: 10px 14px;
            border: 1px solid #ebd9cb;
            border-radius: 10px;
            font-size: 0.9rem;
            font-family: inherit;
            color: #292524;
            background: #fdfbf9;
            outline: none;
            transition: border-color 0.18s, box-shadow 0.18s;
        }
        .cl-field input:focus {
            border-color: #7a4b3a;
            box-shadow: 0 0 0 3px rgba(122, 75, 58, 0.12);
            background: #fff;
        }
        .btn-gen-pass {
            background: none;
            border: 0;
            color: #7a4b3a;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            padding: 0;
            text-decoration: underline;
        }
        .cl-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
        }
        .btn-cl-cancel {
            padding: 10px 18px;
            border: 1px solid #ebd9cb;
            background: #ffffff;
            color: #78665c;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-cl-submit {
            padding: 10px 22px;
            border: 0;
            background: #7a4b3a;
            color: #ffffff;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.18s;
        }
        .btn-cl-submit:hover {
            background: #633c2e;
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
        <a href="../index.php" class="brand" title="UniStay Home">
            <img src="../assets/images/tenant/logo.png" width="50" height="50" alt="UniStay logo">
            <span class="brand-name">UniStay</span>
        </a>
        <nav aria-label="Admin navigation">
            <a href="dashboard.php" title="Dashboard"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
            <a href="verification.php" title="Verification"><svg aria-hidden="true"><use href="#badge-icon"/></svg><span class="nav-label">Verification</span></a>
            <a href="users.php" title="Users" aria-current="page"><svg aria-hidden="true"><use href="#user-icon"/></svg><span class="nav-label">Users</span></a>
            <a href="settings.php" title="Settings"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
        </nav>
        <button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true">
            <span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span>
            <span class="toggle-label">Collapse</span>
        </button>
    </aside>

    <div class="admin-shell">
        <header class="admin-topbar">
            <div class="admin-title-wrap">
                <h1>User Directory</h1>
                <p>Manage all registered accounts, student tenants, landlords, and administrators.</p>
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
            <div class="alert-banner success"><?= $successMessage ?></div>
        <?php endif; ?>
        <?php if (!empty($errorMessage)): ?>
            <div class="alert-banner error"><?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <div class="users-toolbar">
            <form class="search-form" method="GET">
                <input type="hidden" name="role" value="<?= htmlspecialchars($roleFilter) ?>">
                <input type="text" name="search" class="search-input" placeholder="Search by name, email, or phone..." value="<?= htmlspecialchars($search) ?>">
            </form>
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <div class="role-filters">
                    <a href="?role=all&search=<?= urlencode($search) ?>" class="role-filter-btn <?= $roleFilter === 'all' ? 'is-active' : '' ?>">All Users</a>
                    <a href="?role=landlord&search=<?= urlencode($search) ?>" class="role-filter-btn <?= $roleFilter === 'landlord' ? 'is-active' : '' ?>">Landlords</a>
                    <a href="?role=tenant&search=<?= urlencode($search) ?>" class="role-filter-btn <?= $roleFilter === 'tenant' ? 'is-active' : '' ?>">Students</a>
                    <a href="?role=admin&search=<?= urlencode($search) ?>" class="role-filter-btn <?= $roleFilter === 'admin' ? 'is-active' : '' ?>">Admins</a>
                </div>
                <button type="button" id="btn-open-create-landlord" class="btn-create-landlord">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Create Landlord Account
                </button>
            </div>
        </div>

        <div class="table-card">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>User ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Role</th>
                        <th>Verification Status</th>
                        <th>Date Registered</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="7" style="text-align:center; color:#8c7569; padding:24px;">No users found matching your search.</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): 
                            $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                            if ($name === '') $name = (string)($u['personal_email'] ?? 'User');
                            $role = strtolower($u['role'] ?? 'tenant');
                            $vStatus = $u['verification_status'] ?? 'unverified';
                        ?>
                            <tr>
                                <td>#<?= (int)$u['id'] ?></td>
                                <td><strong><?= htmlspecialchars($name) ?></strong></td>
                                <td><?= htmlspecialchars($u['personal_email'] ?? '') ?></td>
                                <td><?= htmlspecialchars($u['phone_number'] ?? '—') ?></td>
                                <td><span class="badge-role <?= $role ?>"><?= htmlspecialchars($role) ?></span></td>
                                <td>
                                    <?php if ($role === 'landlord'): ?>
                                        <?php if ($vStatus === 'verified'): ?>
                                            <span style="color:#065f46; font-weight:600; font-size:0.85rem;">✓ Verified</span>
                                        <?php elseif ($vStatus === 'pending'): ?>
                                            <a href="verification.php" style="color:#b45309; font-weight:600; font-size:0.85rem; text-decoration:underline;">⏳ Review ID</a>
                                        <?php elseif ($vStatus === 'rejected'): ?>
                                            <span style="color:#991b1b; font-weight:600; font-size:0.85rem;">⚠ Rejected</span>
                                        <?php else: ?>
                                            <span style="color:#78665c; font-size:0.85rem;">Unverified</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#78665c; font-size:0.85rem;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= !empty($u['created_at']) ? htmlspecialchars(date('M d, Y', strtotime($u['created_at']))) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create Landlord Modal Dialog -->
    <dialog id="create-landlord-modal" class="create-landlord-dialog">
        <div class="cl-dialog-head">
            <h2>Create Landlord Account</h2>
            <button type="button" class="cl-dialog-close" id="cl-dialog-close" aria-label="Close">✕</button>
        </div>
        <p class="cl-dialog-desc">Create an exclusive verified landlord account. The landlord can log in immediately with these credentials.</p>
        <form method="POST" action="users.php" class="cl-form">
            <input type="hidden" name="action" value="create_landlord">
            <div class="cl-row-2">
                <div class="cl-field">
                    <label for="cl_first_name">First Name *</label>
                    <input type="text" id="cl_first_name" name="first_name" required placeholder="First name">
                </div>
                <div class="cl-field">
                    <label for="cl_middle_name">Middle Name</label>
                    <input type="text" id="cl_middle_name" name="middle_name" placeholder="Middle name (optional)">
                </div>
            </div>
            <div class="cl-field">
                <label for="cl_last_name">Last Name *</label>
                <input type="text" id="cl_last_name" name="last_name" required placeholder="Last name">
            </div>
            <div class="cl-row-2">
                <div class="cl-field">
                    <label for="cl_email">Email Address *</label>
                    <input type="email" id="cl_email" name="personal_email" required placeholder="landlord@example.com">
                </div>
                <div class="cl-field">
                    <label for="cl_phone">Phone Number</label>
                    <input type="tel" id="cl_phone" name="phone_number" placeholder="09123456789">
                </div>
            </div>
            <div class="cl-field">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <label for="cl_password">Initial Password *</label>
                    <button type="button" id="btn-gen-pass" class="btn-gen-pass">Generate Random</button>
                </div>
                <input type="text" id="cl_password" name="password" required minlength="8" placeholder="Initial password (min. 8 characters)">
            </div>
            <div class="cl-actions">
                <button type="button" class="btn-cl-cancel" id="cl-dialog-cancel">Cancel</button>
                <button type="submit" class="btn-cl-submit">Create Landlord Account</button>
            </div>
        </form>
    </dialog>

    <script>
    (() => {
        const modal = document.getElementById('create-landlord-modal');
        const openBtn = document.getElementById('btn-open-create-landlord');
        const closeBtn = document.getElementById('cl-dialog-close');
        const cancelBtn = document.getElementById('cl-dialog-cancel');
        const genPassBtn = document.getElementById('btn-gen-pass');
        const passInput = document.getElementById('cl_password');

        if (openBtn && modal) {
            openBtn.addEventListener('click', () => {
                if (passInput && !passInput.value) {
                    generatePassword();
                }
                modal.showModal();
            });
        }
        if (closeBtn && modal) closeBtn.addEventListener('click', () => modal.close());
        if (cancelBtn && modal) cancelBtn.addEventListener('click', () => modal.close());

        if (modal) {
            modal.addEventListener('click', (e) => {
                const rect = modal.getBoundingClientRect();
                if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) {
                    modal.close();
                }
            });
        }

        function generatePassword() {
            const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
            let pass = '';
            for (let i = 0; i < 12; i++) {
                pass += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            if (passInput) passInput.value = pass;
        }

        if (genPassBtn) {
            genPassBtn.addEventListener('click', generatePassword);
        }
    })();
    </script>
</body>
</html>
