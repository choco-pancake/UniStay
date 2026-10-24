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
        header('Location: ../auth/login.php?redirect=admin/dashboard.php');
        exit;
    }
}

$adminProfile = $_SESSION['user'] ?? ['name' => 'UniStay Admin', 'email' => 'admin@unistay.ph'];

// Aggregate statistics
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalLandlords = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'landlord'")->fetchColumn();
$totalTenants = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'tenant'")->fetchColumn();
$pendingVerifications = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'landlord' AND (verification_status = 'pending' OR (verification_status = 'unverified' AND gov_id_file IS NOT NULL AND gov_id_file != ''))")->fetchColumn();
$totalProperties = (int)$pdo->query("SELECT COUNT(*) FROM properties")->fetchColumn();

// Recent landlords registered
$recentLandlords = $pdo->query("SELECT id, first_name, last_name, personal_email, verification_status, created_at FROM users WHERE role = 'landlord' ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#7a4b3a">
    <title>Dashboard · UniStay Admin</title>
    <link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/common.css?v=29">
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

        /* Stat Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 18px;
            margin-bottom: 30px;
        }

        .stat-card-admin {
            background: #ffffff;
            border: 1px solid #ebd9cb;
            border-radius: 18px;
            padding: 22px 24px;
            box-shadow: 0 4px 16px rgba(60, 30, 20, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .stat-card-admin.is-highlight {
            background: linear-gradient(135deg, #fcf4ee 0%, #fff 100%);
            border-color: #dfc3b2;
        }

        .stat-card-label {
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #8c7569;
        }

        .stat-card-num {
            font-family: 'Fraunces', serif;
            font-size: 2.3rem;
            color: #3b2820;
            margin: 12px 0 4px;
            font-weight: 700;
        }

        .stat-card-sub {
            font-size: 0.88rem;
            color: #78665c;
            margin: 0;
        }

        /* Alert Callout for Pending Verification */
        .verif-banner {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 16px;
            padding: 18px 24px;
            margin-bottom: 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .verif-banner-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .verif-banner-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #fef3c7;
            color: #b45309;
            display: grid;
            place-items: center;
            font-size: 22px;
            flex-shrink: 0;
        }

        .btn-banner-action {
            background: #7a4b3a;
            color: #ffffff;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            font-size: 0.92rem;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .btn-banner-action:hover {
            background: #633b2c;
            transform: translateY(-1px);
        }

        /* Table Card */
        .table-card {
            background: #ffffff;
            border: 1px solid #ebd9cb;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 4px 16px rgba(60, 30, 20, 0.04);
        }

        .table-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }

        .table-card-header h2 {
            font-size: 1.25rem;
            color: #292524;
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
            border-bottom: 2px solid #ebd9cb;
        }

        .admin-table td {
            padding: 14px;
            font-size: 0.92rem;
            border-bottom: 1px solid #f2e9e2;
            color: #292524;
        }

        .status-badge-sm {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-badge-sm.is-verified { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .status-badge-sm.is-pending { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .status-badge-sm.is-rejected { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .status-badge-sm.is-unverified { background: #f5f5f4; color: #57534e; border: 1px solid #e7e5e4; }
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
            <a href="dashboard.php" title="Dashboard" aria-current="page"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
            <a href="verification.php" title="Verification"><svg aria-hidden="true"><use href="#badge-icon"/></svg><span class="nav-label">Verification</span></a>
            <a href="users.php" title="Users"><svg aria-hidden="true"><use href="#user-icon"/></svg><span class="nav-label">Users</span></a>
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
                <h1>Admin Overview</h1>
                <p>Welcome back, <?= htmlspecialchars($adminProfile['name']) ?>. Here is the latest activity across UniStay.</p>
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

        <?php if ($pendingVerifications > 0): ?>
            <div class="verif-banner">
                <div class="verif-banner-left">
                    <div class="verif-banner-icon">🛡️</div>
                    <div>
                        <strong style="color:#78350f; font-size:1.05rem; display:block;">
                            <?= $pendingVerifications ?> Landlord <?= $pendingVerifications === 1 ? 'Verification' : 'Verifications' ?> Waiting for Review
                        </strong>
                        <span style="color:#92400e; font-size:0.88rem;">New government IDs have been submitted and need administrative approval before dorm listings activate.</span>
                    </div>
                </div>
                <a href="verification.php" class="btn-banner-action">Review Documents &rarr;</a>
            </div>
        <?php endif; ?>

        <!-- Statistics Grid -->
        <div class="stats-grid">
            <div class="stat-card-admin is-highlight">
                <span class="stat-card-label">Pending Verifications</span>
                <div class="stat-card-num" style="color: #b45309;"><?= $pendingVerifications ?></div>
                <p class="stat-card-sub"><a href="verification.php" style="color:#7a4b3a; font-weight:600; text-decoration:none;">Open verification center &rarr;</a></p>
            </div>
            <div class="stat-card-admin">
                <span class="stat-card-label">Total Landlords</span>
                <div class="stat-card-num"><?= $totalLandlords ?></div>
                <p class="stat-card-sub">Registered property owners</p>
            </div>
            <div class="stat-card-admin">
                <span class="stat-card-label">Total Students / Tenants</span>
                <div class="stat-card-num"><?= $totalTenants ?></div>
                <p class="stat-card-sub">Active student accounts</p>
            </div>
            <div class="stat-card-admin">
                <span class="stat-card-label">Listed Properties</span>
                <div class="stat-card-num"><?= $totalProperties ?></div>
                <p class="stat-card-sub">Student dormitories & units</p>
            </div>
        </div>

        <!-- Recent Landlords Table -->
        <div class="table-card">
            <div class="table-card-header">
                <h2>Recently Registered Landlords</h2>
                <a href="verification.php" style="color:#7a4b3a; font-size:0.88rem; font-weight:600; text-decoration:none;">View all in verification &rarr;</a>
            </div>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Landlord</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Registered Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentLandlords)): ?>
                        <tr><td colspan="5" style="text-align:center; color:#8c7569; padding:24px;">No landlords found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentLandlords as $l): 
                            $name = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
                            if ($name === '') $name = (string)($l['personal_email'] ?? 'Landlord');
                            $st = $l['verification_status'] ?? 'unverified';
                        ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($name) ?></strong></td>
                                <td><?= htmlspecialchars($l['personal_email'] ?? '') ?></td>
                                <td>
                                    <?php if ($st === 'verified'): ?>
                                        <span class="status-badge-sm is-verified">✓ Verified</span>
                                    <?php elseif ($st === 'pending'): ?>
                                        <span class="status-badge-sm is-pending">⏳ Under Review</span>
                                    <?php elseif ($st === 'rejected'): ?>
                                        <span class="status-badge-sm is-rejected">⚠ Rejected</span>
                                    <?php else: ?>
                                        <span class="status-badge-sm is-unverified">Unverified</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= !empty($l['created_at']) ? htmlspecialchars(date('M d, Y', strtotime($l['created_at']))) : '—' ?></td>
                                <td>
                                    <a href="verification.php" style="color:#7a4b3a; font-weight:600; font-size:0.86rem; text-decoration:underline;">Inspect</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
