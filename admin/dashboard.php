<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/notifications.php';

start_app_session();
$pdo = database();
$adminProfile = require_signed_in('admin');
$adminInitials = strtoupper(substr(trim((string)($adminProfile['name'] ?? 'Admin')), 0, 1)) ?: 'A';
$adminNotifications = get_user_in_app_notifications($pdo, (int)$adminProfile['id'], 100);

// Platform summary counts
$totalProperties = (int)$pdo->query('SELECT COUNT(*) FROM dorms')->fetchColumn();
$totalTenants = (int)$pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
$totalLandlords = (int)$pdo->query("SELECT COUNT(*) FROM landlords")->fetchColumn();

// Verification status queries
$pendingLandlords = (int)$pdo->query("SELECT COUNT(*) FROM landlords WHERE status = 'pending'")->fetchColumn();
$pendingProperties = (int)$pdo->query("SELECT COUNT(*) FROM dorms WHERE approval_status = 'pending'")->fetchColumn();
$pendingVerifications = $pendingLandlords + $pendingProperties;

// Room inventory & occupancy
$roomSummary = $pdo->query("
    SELECT COUNT(*) AS total,
           COALESCE(SUM(LOWER(status) = 'occupied'), 0) AS occupied,
           COALESCE(SUM(LOWER(status) = 'available'), 0) AS available,
           COALESCE(SUM(LOWER(status) IN ('maintenance', 'under maintenance')), 0) AS maintenance,
           COALESCE(SUM(CASE WHEN LOWER(status) = 'occupied' THEN total_rent ELSE 0 END), 0) AS active_monthly_rent,
           COALESCE(SUM(total_rent), 0) AS potential_monthly_rent
    FROM rooms
")->fetch(PDO::FETCH_ASSOC);

$totalRooms = (int)($roomSummary['total'] ?? 0);
$occupiedRooms = (int)($roomSummary['occupied'] ?? 0);
$availableRooms = (int)($roomSummary['available'] ?? 0);
$maintenanceRooms = (int)($roomSummary['maintenance'] ?? 0);

// Donut chart percentages
$occupiedEnd = $totalRooms > 0 ? round($occupiedRooms / $totalRooms * 100, 2) : 0;
$availableEnd = $totalRooms > 0 ? round(($occupiedRooms + $availableRooms) / $totalRooms * 100, 2) : 0;

// Recently registered landlords
$recentLandlords = $pdo->query("
    SELECT id, first_name, last_name, email, email AS personal_email, status AS verification_status, created_at 
    FROM landlords 
    ORDER BY id DESC 
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/common.css?v=41">
    <link rel="stylesheet" href="../assets/css/confirm-modal.css?v=1">
    <link rel="stylesheet" href="../assets/css/admin-dashboard.css?v=5">
    <script src="../assets/js/tenant/popover.js?v=2"></script>
    <script src="../assets/js/tenant/sidebar.js?v=11"></script>
    <script src="../assets/js/tenant/profile-menu.js?v=31"></script>
    <script>
      window.UNISTAY_TENANT_PROFILE = <?= json_encode([
        'name' => $adminProfile['name'],
        'role' => 'Administrator',
        'photo' => $adminProfile['photo'] ?? '',
        'initials' => $adminInitials,
      ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
      window.UNISTAY_NOTIFICATION_ACCOUNT = <?= json_encode(['id' => (int)$adminProfile['id'], 'role' => 'admin']) ?>;
    </script>
    <script defer src="../assets/js/notification-preferences.js?v=3"></script>
    <script defer src="../assets/js/admin-notifications.js?v=1"></script>
</head>
<body class="admin-dashboard-page">
    <svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" style="display:none;"><defs>
        <symbol id="grid-icon" viewBox="0 0 24 24"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></symbol>
        <symbol id="badge-icon" viewBox="0 0 24 24"><path d="m12 2 3 3 4-1 1 4 3 3-3 3-1 4-4 1-3 3-3-3-4-1-1-4-3-3 3-3 1-4 4 1Z"/><path d="m8 12 3 3 5-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="user-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></symbol>
        <symbol id="mail-icon" viewBox="0 0 24 24"><path d="M3 5h18v14H3z" fill="none" stroke="currentColor" stroke-width="2"/><path d="m3 6 9 7 9-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
        <symbol id="bell-icon" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M10 20h4"/></symbol>
        <symbol id="bell-off-icon" viewBox="0 0 24 24"><path d="m3 3 18 18M10 20h4M6 6c-1 2 0 7-3 10h13M18 13V8a6 6 0 0 0-8-6"/></symbol>
        <symbol id="arrow-icon" viewBox="0 0 24 24"><path d="M4 12h16m-6-6 6 6-6 6"/></symbol>
        <symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
        <symbol id="logout-icon" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    </defs></svg>

    <a class="skip" href="#dashboard-main">Skip to main dashboard content</a>

    <!-- Admin Sidebar Navigation -->
    <aside class="sidebar" aria-label="UniStay Admin Portal">
        <a href="dashboard.php" class="brand" title="UniStay Admin Home">
            <img src="../assets/images/tenant/logo.png" width="50" height="50" alt="UniStay logo">
            <span class="brand-name">UniStay</span>
        </a>
        <nav aria-label="Admin navigation">
            <a href="dashboard.php" title="Dashboard" aria-current="page"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
            <a href="verification.php" title="Verification"><svg aria-hidden="true"><use href="#badge-icon"/></svg><span class="nav-label">Verification</span></a>
            <a href="users.php" title="Users"><svg aria-hidden="true"><use href="#user-icon"/></svg><span class="nav-label">Users</span></a>
            <a href="subscribers.php" title="Subscribers"><svg aria-hidden="true"><use href="#mail-icon"/></svg><span class="nav-label">Subscribers</span></a>
            <a href="settings.php" title="Settings"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
        </nav>
        <button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true">
            <span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span>
            <span class="toggle-label">Collapse</span>
        </button>
    </aside>

    <div class="shell">
        <div class="shell-orbs" aria-hidden="true"><i></i><i></i><i></i></div>
        <header class="topbar">
            <h1>Admin Overview</h1>
            <div class="profile">
                <button
                    id="notification-bell"
                    class="bell"
                    type="button"
                    aria-label="Notifications"
                    aria-expanded="false"
                    aria-controls="notification-popover"
                >
                    <svg aria-hidden="true"><use href="#bell-icon" /></svg>
                </button>
                <button
                    class="avatar"
                    type="button"
                    aria-label="<?= htmlspecialchars($adminProfile['name'], ENT_QUOTES, 'UTF-8') ?> — open profile menu"
                    aria-expanded="false"
                    aria-controls="profile-popover"
                    <?php if (!empty($adminProfile['photo'])): ?>style="background-image:url('<?= htmlspecialchars($adminProfile['photo'], ENT_QUOTES, 'UTF-8') ?>');"<?php endif; ?>
                >
                    <?php if (!empty($adminProfile['photo'])): ?>
                        <img src="<?= htmlspecialchars($adminProfile['photo']) ?>" alt="" referrerpolicy="no-referrer" class="avatar-img" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline-flex';">
                        <span style="display:none;"><?= htmlspecialchars($adminInitials) ?></span>
                    <?php else: ?>
                        <?= htmlspecialchars($adminInitials) ?>
                    <?php endif; ?>
                </button>

                <!-- Notification Dropdown Popover -->
                <section
                    id="notification-popover"
                    class="popover"
                    aria-labelledby="popover-title"
                    hidden
                >
                    <h2 id="popover-title">Notifications</h2>
                    <div class="empty" id="popover-empty">
                        <span class="empty-icon"><svg aria-hidden="true"><use href="#bell-off-icon" /></svg></span>
                        <strong>No new notifications</strong>
                        <p>We’ll let you know when new submissions arrive.</p>
                    </div>
                    <ul
                        class="notification-list"
                        id="popover-list"
                        aria-label="Unread notifications"
                        hidden
                    ></ul>
                    <a
                        class="view-all"
                        id="view-all"
                        href="notifications.php"
                        aria-label="View all notifications"
                    >
                        View all notifications
                        <svg aria-hidden="true"><use href="#arrow-icon" /></svg>
                    </a>
                </section>
            </div>
        </header>

        <main id="dashboard-main" class="admin-main dashboard-content" tabindex="-1">
            <section class="dashboard-overview">
                <!-- Left Column: 3 Stat Cards Stack with Tenant Dashboard Measurements -->
                <div class="stats-grid" aria-label="Platform summary metrics">
                    <!-- Stat Card 1: TOTAL ROOMS -->
                    <article class="stat-card">
                        <div class="stat-card-head">
                            <span class="stat-card-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/></svg>
                            </span>
                            <span class="card-label">Total Rooms</span>
                        </div>
                        <div class="card-middle">
                            <h3 class="card-value"><?= number_format($totalRooms) ?></h3>
                        </div>
                        <div class="card-bottom">
                            <p class="card-subtext"><?= number_format($occupiedRooms) ?> Occupied · <?= number_format($availableRooms) ?> Available</p>
                        </div>
                    </article>

                    <!-- Stat Card 2: TOTAL TENANTS -->
                    <article class="stat-card">
                        <div class="stat-card-head">
                            <span class="stat-card-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 19v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8m8 8v-2a4 4 0 0 0-3-3.87M18 3.13a4 4 0 0 1 0 7.75"/></svg>
                            </span>
                            <span class="card-label">Total Tenants</span>
                        </div>
                        <div class="card-middle">
                            <h3 class="card-value"><?= number_format($totalTenants) ?></h3>
                        </div>
                        <div class="card-bottom">
                            <p class="card-subtext"><?= number_format($totalTenants) ?> Active Tenancies · <?= number_format($totalLandlords) ?> Landlords</p>
                        </div>
                    </article>

                    <!-- Stat Card 3: REVIEW & MAINTENANCE -->
                    <article class="stat-card">
                        <div class="stat-card-head">
                            <span class="stat-card-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 3v4m0 0h4m-4 0-5 5m5-5-7 7m7-7a7 7 0 0 1-7 7M4 19v-1a3 3 0 0 1 3-3h1"/></svg>
                            </span>
                            <span class="card-label">Review &amp; Maintenance</span>
                        </div>
                        <div class="card-middle">
                            <h3 class="card-value"><?= number_format($maintenanceRooms + $pendingVerifications) ?></h3>
                        </div>
                        <div class="card-bottom">
                            <p class="card-subtext"><?= number_format($pendingVerifications) ?> Pending Review · <?= number_format($maintenanceRooms) ?> Maintenance</p>
                        </div>
                    </article>
                </div>

                <!-- Right Column: Main Stage -->
                <div class="dashboard-main-stage">
                    <!-- Full-Width Occupancy Rate Card (Covers the space previously occupied by payment trends) -->
                    <article class="occupancy-card" id="occupancy" aria-label="Room Occupancy Rate">
                        <div class="occupancy-card-head">
                            <h2>Occupancy Rate</h2>
                            <span class="occupancy-kicker"><?= $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100) : 0 ?>% Occupied</span>
                        </div>

                        <div class="occupancy-content">
                            <div
                                class="donut-chart"
                                role="img"
                                aria-label="<?= htmlspecialchars(number_format($occupiedRooms) . ' occupied, ' . number_format($availableRooms) . ' available, ' . number_format($maintenanceRooms) . ' under maintenance', ENT_QUOTES, 'UTF-8') ?>"
                                style="--occupied-end: <?= $occupiedEnd ?>%; --available-end: <?= $availableEnd ?>%;"
                            >
                                <div class="donut-center">
                                    <strong><?= number_format($occupiedRooms) ?></strong>
                                    <span>Occupied</span>
                                </div>
                            </div>

                            <ul class="legend">
                                <li>
                                    <span class="legend-label"><i class="legend-dot occupied-dot"></i>Occupied</span>
                                    <strong><?= number_format($occupiedRooms) ?></strong>
                                </li>
                                <li>
                                    <span class="legend-label"><i class="legend-dot available-dot"></i>Available</span>
                                    <strong><?= number_format($availableRooms) ?></strong>
                                </li>
                                <li>
                                    <span class="legend-label"><i class="legend-dot maintenance-dot"></i>Maintenance</span>
                                    <strong><?= number_format($maintenanceRooms) ?></strong>
                                </li>
                            </ul>
                        </div>
                    </article>

                    <!-- Recently Registered Landlords Card (Styled identically to Tenant .current-booking-card) -->
                    <section class="current-booking-card landlords-booking-card" aria-label="Recently registered landlords">
                        <header class="current-booking-header">
                            <h2 class="section-title">Recently Registered Landlords</h2>
                        </header>
                        <div class="landlords-card-body">
                            <?php if (empty($recentLandlords)): ?>
                                <p style="text-align:center; color:#8c7569; padding:28px 0; margin:0;">No landlords registered yet.</p>
                            <?php else: ?>
                                <?php foreach ($recentLandlords as $l): 
                                    $first = trim((string)($l['first_name'] ?? ''));
                                    $last = trim((string)($l['last_name'] ?? ''));
                                    $name = trim($first . ' ' . $last);
                                    if ($name === '') $name = (string)($l['email'] ?? ($l['personal_email'] ?? 'Landlord'));
                                    $initials = strtoupper(substr($first ?: ($l['email'] ?? ($l['personal_email'] ?? 'L')), 0, 1) . substr($last, 0, 1));
                                    if (strlen($initials) < 2) $initials = strtoupper(substr($name, 0, 2));
                                    $st = $l['verification_status'] ?? 'unverified';
                                    $regDate = !empty($l['created_at']) ? date('M d, Y', strtotime($l['created_at'])) : 'Recently';
                                ?>
                                    <div class="landlord-row">
                                        <div class="landlord-row-left">
                                            <div class="landlord-avatar" aria-hidden="true"><?= htmlspecialchars($initials) ?></div>
                                            <div class="landlord-user-info">
                                                <span class="landlord-user-name"><?= htmlspecialchars($name) ?></span>
                                                <span class="landlord-user-meta"><?= htmlspecialchars($l['email'] ?? ($l['personal_email'] ?? '')) ?> · Registered <?= htmlspecialchars($regDate) ?></span>
                                            </div>
                                        </div>
                                        <div class="landlord-row-right">
                                            <?php if ($st === 'verified'): ?>
                                                <span class="status-badge-sm is-verified">✓ Verified</span>
                                            <?php elseif ($st === 'pending'): ?>
                                                <span class="status-badge-sm is-pending">⏳ Under Review</span>
                                            <?php elseif ($st === 'rejected' || $st === 'declined'): ?>
                                                <span class="status-badge-sm is-rejected">⚠ Declined</span>
                                            <?php else: ?>
                                                <span class="status-badge-sm is-unverified">Unverified</span>
                                            <?php endif; ?>
                                            <a href="verification.php" class="btn-inspect">Inspect</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <footer class="activity-bottom-bar">
                            <a href="verification.php" class="activity-view-all">View all in Verification Center &rarr;</a>
                        </footer>
                    </section>
                </div>
            </section>
        </main>
    </div>

    <!-- Floating Bottom-Right Session Toast (if $pendingVerifications > 0) -->
    <?php if ($pendingVerifications > 0): ?>
    <aside id="admin-verif-toast" class="admin-verif-toast" role="alert" aria-live="polite">
        <div class="toast-icon-wrap" aria-hidden="true">🛡️</div>
        <div class="toast-body">
            <div class="toast-header">
                <strong><?= $pendingVerifications ?> <?= $pendingVerifications === 1 ? 'Item' : 'Items' ?> Waiting in Review</strong>
                <button id="toast-close-btn" class="toast-close" type="button" aria-label="Dismiss">&times;</button>
            </div>
            <p><?= $pendingLandlords > 0 ? "{$pendingLandlords} landlord ID(s)" : '' ?><?= ($pendingLandlords > 0 && $pendingProperties > 0) ? ' and ' : '' ?><?= $pendingProperties > 0 ? "{$pendingProperties} pending listing(s)" : '' ?> require accreditation.</p>
            <a href="verification.php<?= ($pendingProperties > 0 && $pendingLandlords === 0) ? '?view=properties' : '' ?>" class="toast-action-btn">Review in Verification &rarr;</a>
        </div>
    </aside>
    <script>
    (() => {
        const toast = document.getElementById('admin-verif-toast');
        const closeBtn = document.getElementById('toast-close-btn');
        if (!toast) return;
        if (sessionStorage.getItem('admin_verif_toast_dismissed') === 'true') {
            toast.style.display = 'none';
            return;
        }
        closeBtn?.addEventListener('click', () => {
            toast.classList.add('is-hiding');
            sessionStorage.setItem('admin_verif_toast_dismissed', 'true');
            setTimeout(() => { toast.style.display = 'none'; }, 260);
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
