<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

start_app_session();
$pdo = database();
$adminProfile = require_signed_in('admin');

$successMessage = '';
$errorMessage = '';

// Search & Filter
$search = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? 'all';
$landlordStatusFilter = $_GET['landlord_status'] ?? 'all';

$sql = "SELECT id, first_name, last_name, personal_email, phone_number, role, verification_status, created_at FROM users WHERE 1=1";
$params = [];

if ($roleFilter !== 'all' && in_array($roleFilter, ['tenant', 'landlord', 'admin'], true)) {
    $sql .= " AND role = ?";
    $params[] = $roleFilter;
}

if ($roleFilter === 'landlord' && $landlordStatusFilter !== 'all') {
    if ($landlordStatusFilter === 'verified') {
        $sql .= " AND verification_status = 'verified'";
    } elseif ($landlordStatusFilter === 'pending') {
        $sql .= " AND (verification_status != 'verified' OR verification_status IS NULL)";
    }
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
            background-color: #fbf8f3;
            color: #292524;
            font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            margin: 0;
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styling based on screenshot */
        .sidebar {
            width: 260px;
            background-color: #73280e;
            color: #ffffff;
            display: flex;
            flex-direction: column;
            padding: 24px 16px;
            box-sizing: border-box;
            flex-shrink: 0;
        }

        .sidebar .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #fff;
            font-family: 'Fraunces', serif;
            font-size: 1.25rem;
            padding: 8px 12px;
            margin-bottom: 32px;
        }

        .sidebar nav {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
        }

        .sidebar nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            border-radius: 10px;
            font-weight: 500;
            font-size: 0.95rem;
            transition: background 0.15s, color 0.15s;
        }

        .sidebar nav a:hover,
        .sidebar nav a[aria-current="page"] {
            background: rgba(255, 255, 255, 0.15);
            color: #ffffff;
        }

        .sidebar nav a svg {
            width: 20px;
            height: 20px;
            fill: currentColor;
        }

        .sidebar-toggle {
            background: none;
            border: 0;
            color: rgba(255, 255, 255, 0.7);
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            padding: 8px 12px;
            font-size: 0.85rem;
        }

        .admin-shell {
            flex: 1;
            padding: 30px 48px;
            overflow-y: auto;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
            box-sizing: border-box;
        }

        .admin-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eae1d9;
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
            border: 1px solid #eae1d9;
            padding: 8px 16px;
            border-radius: 999px;
            box-shadow: 0 2px 8px rgba(60, 30, 20, 0.04);
        }

        .admin-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #73280e;
            color: #fff;
            display: grid;
            place-items: center;
            font-weight: 700;
            font-size: 13px;
        }

        .admin-logout-btn {
            display: inline-flex;
            align-items: center;
            padding: 8px 18px;
            background: #fdf2f0;
            border: 1px solid #f8d7d2;
            color: #991b1b;
            border-radius: 999px;
            font-size: 0.88rem;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.15s;
        }
        .admin-logout-btn:hover {
            background: #fae4e1;
        }

        /* ============ Users Tabs ============ */
        .users-tabs {
            display: flex;
            gap: 6px;
            background: #ffffff;
            border: 1px solid #eae1d9;
            border-radius: 12px;
            padding: 5px;
            margin-bottom: 20px;
            width: fit-content;
            box-shadow: 0 2px 8px rgba(60, 30, 20, 0.04);
            flex-wrap: wrap;
        }

        .users-tab {
            padding: 9px 20px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            color: #78665c;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.15s, color 0.15s;
        }

        .users-tab:hover { background: #f7efe8; color: #3b2820; }

        .users-tab.is-active {
            background: #73280e;
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(115, 40, 14, 0.25);
        }

        /* ============ Toolbar ============ */
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
            max-width: 560px;
        }

        .search-input, .status-select {
            width: 100%;
            padding: 10px 16px;
            background: #ffffff;
            border: 1px solid #eae1d9;
            border-radius: 10px;
            font-family: inherit;
            font-size: 0.92rem;
            color: #292524;
            outline: none;
        }

        .status-select {
            max-width: 240px;
            cursor: pointer;
        }

        .search-input:focus, .status-select:focus {
            border-color: #73280e;
            box-shadow: 0 0 0 3px rgba(115, 40, 14, 0.12);
        }

        /* ============ Tables & Section Cards ============ */
        .table-card,
        .section-card {
            background: #ffffff;
            border: 1px solid #eae1d9;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 4px 16px rgba(60, 30, 20, 0.04);
            overflow-x: auto;
            margin-bottom: 20px;
        }

        .section-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .section-card-head h2 {
            margin: 0;
            font-family: 'Fraunces', serif;
            font-size: 1.2rem;
            color: #3b2820;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-count {
            font-size: 0.78rem;
            color: #73280e;
            background: #fdf2ec;
            border-radius: 999px;
            padding: 4px 12px;
            font-weight: 600;
        }

        .empty-note {
            text-align: center;
            color: #8c7569;
            padding: 24px;
            font-size: 0.9rem;
            margin: 0;
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
            border-bottom: 2px solid #eae1d9;
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
        .badge-role.tenant   { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        .badge-role.admin    { background: #f3e8ff; color: #6b21a8; border: 1px solid #e9d5ff; }

        .status-pill { font-weight: 600; font-size: 0.85rem; }
        .status-pill.verified { color: #065f46; }
        .status-pill.pending  { color: #b45309; text-decoration: underline; }
        .status-pill.rejected { color: #991b1b; }
        .status-pill.neutral  { color: #78665c; font-weight: 400; }
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
            <img src="../assets/images/tenant/logo.png" width="40" height="40" alt="UniStay logo">
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

        <?php
        $renderUserRows = function (array $list, bool $showVerification = true): void {
            foreach ($list as $u) {
                $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                if ($name === '') { $name = (string)($u['personal_email'] ?? 'User'); }
                $role = strtolower($u['role'] ?? 'tenant');
                $vStatus = $u['verification_status'] ?? 'unverified';
                ?>
                <tr>
                    <td>#<?= (int)$u['id'] ?></td>
                    <td><strong><?= htmlspecialchars($name) ?></strong></td>
                    <td><?= htmlspecialchars($u['personal_email'] ?? '') ?></td>
                    <td><?= htmlspecialchars($u['phone_number'] ?? '—') ?></td>
                    <td><span class="badge-role <?= htmlspecialchars($role) ?>"><?= htmlspecialchars($role) ?></span></td>
                    <?php if ($showVerification): ?>
                    <td>
                        <?php if ($role === 'landlord'): ?>
                            <?php if ($vStatus === 'verified'): ?>
                                <span class="status-pill verified">✓ Verified</span>
                            <?php elseif ($vStatus === 'pending'): ?>
                                <a href="verification.php" class="status-pill pending">⏳ Review ID</a>
                            <?php elseif ($vStatus === 'rejected'): ?>
                                <span class="status-pill rejected">⚠ Rejected</span>
                            <?php else: ?>
                                <span class="status-pill neutral">Unverified</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="status-pill neutral">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td><?= !empty($u['created_at']) ? htmlspecialchars(date('M d, Y', strtotime($u['created_at']))) : '—' ?></td>
                </tr>
                <?php
            }
        };
        ?>

        <!-- View Tabs (Landlords / Tenants / Admins / All) -->
        <nav class="users-tabs" aria-label="User type">
            <a href="?role=landlord&search=<?= urlencode($search) ?>"
               class="users-tab <?= $roleFilter === 'landlord' ? 'is-active' : '' ?>">Landlords</a>
            <a href="?role=tenant&search=<?= urlencode($search) ?>"
               class="users-tab <?= $roleFilter === 'tenant' ? 'is-active' : '' ?>">Tenants</a>
            <a href="?role=admin&search=<?= urlencode($search) ?>"
               class="users-tab <?= $roleFilter === 'admin' ? 'is-active' : '' ?>">Admins</a>
            <a href="?role=all&search=<?= urlencode($search) ?>"
               class="users-tab <?= $roleFilter === 'all' ? 'is-active' : '' ?>">All Users</a>
        </nav>

        <div class="users-toolbar">
            <form class="search-form" method="GET" id="filter-form">
                <input type="hidden" name="role" value="<?= htmlspecialchars($roleFilter) ?>">
                <input type="text" name="search" class="search-input" placeholder="Search by name, email, or phone..." value="<?= htmlspecialchars($search) ?>">
                <?php if ($roleFilter === 'landlord'): ?>
                    <select name="landlord_status" class="status-select" onchange="this.form.submit()">
                        <option value="all" <?= $landlordStatusFilter === 'all' ? 'selected' : '' ?>>All Landlords</option>
                        <option value="verified" <?= $landlordStatusFilter === 'verified' ? 'selected' : '' ?>>Verified</option>
                        <option value="pending" <?= $landlordStatusFilter === 'pending' ? 'selected' : '' ?>>Pending / Unverified</option>
                    </select>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($roleFilter === 'landlord'): ?>
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
                            <tr><td colspan="7" class="empty-note">No landlords found matching your search.</td></tr>
                        <?php else: ?>
                            <?php $renderUserRows($users, true); ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($roleFilter === 'tenant'): ?>
            <div class="table-card">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Date Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr><td colspan="6" class="empty-note">No student tenants found matching your search.</td></tr>
                        <?php else: ?>
                            <?php $renderUserRows($users, false); ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($roleFilter === 'admin'): ?>
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
                            <tr><td colspan="7" class="empty-note">No administrator accounts found.</td></tr>
                        <?php else: ?>
                            <?php $renderUserRows($users, true); ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
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
                            <tr><td colspan="7" class="empty-note">No users found matching your search.</td></tr>
                        <?php else: ?>
                            <?php $renderUserRows($users, true); ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>