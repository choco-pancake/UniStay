<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

$authenticatedUser = require_signed_in('landlord');
$pdo = database();
$userId = (int)($_SESSION['user_id'] ?? 0);
$profileIncomplete = !landlord_profile_complete($pdo, $userId);
$isNewLandlord = !$profileIncomplete && !empty($_SESSION['start_landlord_tour']);
unset($_SESSION['start_landlord_tour']);
$landlordProfile = get_authenticated_profile($pdo, $userId);
$landlordNotifications = [];
require_once __DIR__ . '/../config/notifications.php';
$landlordNotifications = get_user_in_app_notifications($pdo, $userId, 50);
$landlordVerification = get_landlord_verification($pdo, $userId);
$verificationStatus = $landlordVerification['status'] ?? 'unverified';

// 1. Total Properties
$stmt = $pdo->prepare('SELECT COUNT(*) FROM properties WHERE landlord_id = ?');
$stmt->execute([$userId]);
$totalProperties = (int)$stmt->fetchColumn();

// 2. Occupancy Rate & Capacity
$stmt = $pdo->prepare('
    SELECT COUNT(r.id) AS total_rooms,
           COALESCE(SUM(r.occupants), 0) AS total_occupants,
           COALESCE(SUM(r.capacity), 0) AS total_capacity
    FROM rooms r
    JOIN properties p ON p.id = r.property_id
    WHERE p.landlord_id = ?
');
$stmt->execute([$userId]);
$roomStats = $stmt->fetch() ?: ['total_rooms' => 0, 'total_occupants' => 0, 'total_capacity' => 0];
$totalOccupants = (int)$roomStats['total_occupants'];
$totalCapacity = (int)$roomStats['total_capacity'];
$occupancyRate = $totalCapacity > 0 ? round(($totalOccupants / $totalCapacity) * 100) : 0;
$occupancySub = $totalCapacity > 0 ? "{$totalOccupants} of {$totalCapacity} beds occupied" : "No rooms listed yet";

// 3. Total Tenants (Active Residents)
$stmt = $pdo->prepare('
    SELECT COUNT(DISTINCT b.user_id)
    FROM bookings b
    JOIN properties p ON p.id = b.property_id
    WHERE p.landlord_id = ? AND b.status IN ("approved", "active", "confirmed")
');
$stmt->execute([$userId]);
$activeTenantsCount = (int)$stmt->fetchColumn();
$displayTenantsCount = max($activeTenantsCount, $totalOccupants);
$tenantsSub = $displayTenantsCount > 0 
    ? ($totalProperties > 0 ? "Across {$totalProperties} " . ($totalProperties === 1 ? 'property' : 'properties') : "Active student residents")
    : "No active tenants yet";

// 4. Recent Bookings for this landlord's properties
$stmt = $pdo->prepare('
    SELECT b.id, b.status, b.created_at,
           u.first_name, u.last_name, u.personal_email, u.photo AS user_photo, u.profile_photo,
           p.name AS property_name, p.university,
           r.name AS room_name, r.type AS room_type
    FROM bookings b
    JOIN properties p ON p.id = b.property_id
    JOIN users u ON u.id = b.user_id
    LEFT JOIN rooms r ON r.id = b.room_id
    WHERE p.landlord_id = ?
    ORDER BY b.id DESC
    LIMIT 4
');
$stmt->execute([$userId]);
$recentBookings = $stmt->fetchAll() ?: [];

// Helper to resolve tenant details
function resolve_tenant_details(array $b): array
{
    $name = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
    if ($name === '') {
        $name = (string)($b['personal_email'] ?? 'Tenant');
    }
    $initial = strtoupper(substr($name, 0, 1));
    $photo = resolve_user_profile_photo_url($b['profile_photo'] ?? null, $b['user_photo'] ?? null, 'tenant');
    return ['name' => $name, 'initial' => $initial, 'photo' => $photo];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard · UniStay Landlord</title>
<link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/common.css?v=28">
<link rel="stylesheet" href="../assets/css/dashboardstyle.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/dashboardstyle.css'); ?>">
<?php if ($isNewLandlord): ?><link rel="stylesheet" href="../assets/css/landlord-onboarding.css?v=1"><?php endif; ?>
<style>
/* Same serif title treatment as the Map and Tenant Dashboard */
.topbar h1 { font-family: Georgia, "Times New Roman", serif; }

/* Subtext Link: no underline, darken on hover and active click */
.card-subtext-link {
  color: #7b4a3a;
  font-weight: 600;
  text-decoration: none !important;
  transition: color 0.18s ease;
}
.card-subtext-link:hover {
  color: #4a271c;
  text-decoration: none !important;
}
.card-subtext-link:active {
  color: #2b140d;
  text-decoration: none !important;
}
.card-subtext-link.is-danger {
  color: #dc2626;
}
.card-subtext-link.is-danger:hover {
  color: #991b1b;
}
.card-subtext-link.is-danger:active {
  color: #7f1d1d;
}

/* Recent Bookings Card Footer & View All Link */
.current-booking-card {
  display: flex;
  flex-direction: column;
}
.current-booking-card .recent-bookings-body {
  flex: 1;
}
.recent-bookings-footer {
  padding: 14px 20px;
  border-top: 1px solid #ebd9cb;
  text-align: center;
  background: #ffffff;
  border-bottom-left-radius: 20px;
  border-bottom-right-radius: 20px;
  margin-top: auto;
}
.recent-bookings-view-all {
  color: #7a4b3a;
  font-weight: 600;
  font-size: 0.9rem;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: color 0.18s ease, transform 0.18s ease;
}
.recent-bookings-view-all:hover {
  color: #4a271c;
  transform: translateX(2px);
}
.recent-bookings-view-all:active {
  color: #2b140d;
}

/* Manage Properties Button: Landing Page Log in button style with enlarge hover & active */
.btn-manage-properties {
  font: 500 13px 'DM Sans', sans-serif;
  padding: 0 20px;
  height: 40px;
  border-radius: 8px;
  border: 1px solid var(--brown, #7a4b3a);
  background: var(--brown, #7a4b3a);
  color: #ffffff;
  text-decoration: none;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
  transform: scale(1);
  transform-origin: center;
  transition: transform 0.18s ease, background-color 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
  margin-top: 10px;
}
.btn-manage-properties:hover,
.btn-manage-properties:focus-visible {
  color: #ffffff;
  background: var(--brown-dark, #5f3425);
  border-color: var(--brown-dark, #5f3425);
  transform: scale(1.08);
  box-shadow: 0 8px 22px rgba(122, 75, 58, 0.22);
}
.btn-manage-properties:active {
  color: #ffffff;
  background: var(--brown-darker, #432419);
  border-color: var(--brown-darker, #432419);
  transform: scale(1.12);
  box-shadow: 0 4px 14px rgba(122, 75, 58, 0.28);
}

/* Profile Completion Floating Toast - Smooth Transition */
.profile-toast {
  position: fixed;
  bottom: 24px;
  right: 24px;
  z-index: 9999;
  width: min(calc(100vw - 32px), 430px);
  background: #ffffff;
  border-radius: 18px;
  box-shadow: 0 14px 40px rgba(60, 30, 20, 0.18), 0 2px 8px rgba(60, 30, 20, 0.08);
  border: 1px solid #ebd9cb;
  overflow: hidden;
  animation: toast-smooth-in 0.5s cubic-bezier(0.16, 1, 0.3, 1) 0.25s both;
  transition: opacity 0.35s cubic-bezier(0.4, 0, 0.2, 1), transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
}

.profile-toast.is-dismissing {
  opacity: 0;
  transform: translateY(22px) scale(0.95);
  pointer-events: none;
}

.profile-toast-inner {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  padding: 18px 20px 16px;
}

.profile-toast-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: #fbf4ee;
  color: #7a4b3a;
  display: grid;
  place-items: center;
  flex-shrink: 0;
}

.profile-toast-icon svg {
  width: 22px;
  height: 22px;
}

.profile-toast-body {
  flex: 1;
  min-width: 0;
}

.profile-toast-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 4px;
}

.profile-toast-header strong {
  font-size: 15px;
  font-weight: 700;
  color: #3b2820;
}

.profile-toast-close {
  background: none;
  border: none;
  font-size: 22px;
  line-height: 1;
  color: #9a8c84;
  cursor: pointer;
  padding: 0 4px;
}

.profile-toast-close:hover {
  color: #3b2820;
}

.profile-toast-body p {
  margin: 0 0 14px;
  font-size: 13.5px;
  line-height: 1.45;
  color: #6b584d;
}

.profile-toast-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
}

.toast-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 8px 18px;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  transition: all 0.18s ease;
}

.toast-btn--secondary {
  border: 1px solid #d8c3b4;
  background: #ffffff;
  color: #6b584d;
}

.toast-btn--secondary:hover {
  background: #fbf8f5;
  border-color: #bfa593;
  color: #3b2820;
}

.toast-btn--primary {
  border: none;
  background: linear-gradient(135deg, #7a4b3a 0%, #633b2c 100%);
  color: #ffffff;
  box-shadow: 0 4px 12px rgba(122, 75, 58, 0.25);
}

.toast-btn--primary:hover {
  filter: brightness(1.06);
}

@keyframes toast-smooth-in {
  from {
    opacity: 0;
    transform: translateY(28px) scale(0.95);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

@media (max-width: 520px) {
  .profile-toast {
    bottom: 16px;
    right: 16px;
    left: 16px;
    width: auto;
  }
}
</style>
<script src="../assets/js/tenant/popover.js"></script>
<script src="../assets/js/tenant/sidebar.js"></script>
<script src="../assets/js/tenant/profile-menu.js"></script>
</head>
<body>
<svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs>
<symbol id="grid-icon" viewBox="0 0 24 24"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></symbol>
<symbol id="home-icon" viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3Z"/></symbol>
<symbol id="bed-icon" viewBox="0 0 24 24"><path d="M3 19V9h18v10M3 16h18M5 9V5h5v4m4 0V5h5v4"/></symbol>
<symbol id="door-icon" viewBox="0 0 24 24"><path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m-2 0h18M14 12v.01" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="user-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></symbol>
<symbol id="booking-icon" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4m8-4v4M3 10h18"/></symbol>
<symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
<symbol id="badge-icon" viewBox="0 0 24 24"><path d="m12 2 3 3 4-1 1 4 3 3-3 3-1 4-4 1-3 3-3-3-4-1-1-4-3-3 3-3 1-4 4 1Z"/><path d="m8 12 3 3 5-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="bell-icon" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M10 20h4"/></symbol>
<symbol id="bell-off-icon" viewBox="0 0 24 24"><path d="m3 3 18 18M10 20h4M6 6c-1 2 0 7-3 10h13M18 13V8a6 6 0 0 0-8-6"/></symbol>
<symbol id="arrow-icon" viewBox="0 0 24 24"><path d="M4 12h16m-6-6 6 6-6 6"/></symbol>
<symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
<symbol id="location-icon" viewBox="0 0 24 24"><path d="M12 21s-6-5.686-6-10a6 6 0 1 1 12 0c0 4.314-6 10-6 10Z"/><circle cx="12" cy="11" r="2"/></symbol>
<symbol id="logout-icon" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
</defs></svg>
<a class="skip" href="#dashboard-page">Skip to dashboard</a>

<!-- Landlord Sidebar -->
<aside class="sidebar" aria-label="UniStay Landlord Portal">
<a href="dashboard.php" class="brand" title="UniStay Home"><img src="../assets/images/tenant/logo.png" width="50" height="50" alt="UniStay logo"><span class="brand-name">UniStay</span></a>
<nav aria-label="Main navigation">
<a href="dashboard.php" title="Dashboard" aria-current="page"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
<a href="properties.php" title="Properties"><svg aria-hidden="true"><use href="#bed-icon"/></svg><span class="nav-label">Properties</span></a>
<a href="tenants.php" title="Requests"><svg aria-hidden="true"><use href="#user-icon"/></svg><span class="nav-label">Requests</span></a>
<a href="settings.php" title="Settings"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
</nav>
<button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true"><span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span><span class="toggle-label">Collapse</span></button>
</aside>

<div class="shell">
<header class="topbar"><h1>Dashboard</h1><div class="profile">
<button id="notification-bell" class="bell" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="notification-popover"><svg aria-hidden="true"><use href="#bell-icon"/></svg></button>
<button class="avatar" type="button" aria-label="<?= htmlspecialchars($landlordProfile['name'], ENT_QUOTES, 'UTF-8') ?> — open profile menu" aria-expanded="false" aria-controls="profile-popover" <?= !empty($landlordProfile['photo']) ? 'style="background-image:url(\''.htmlspecialchars($landlordProfile['photo'], ENT_QUOTES, 'UTF-8').'\');"' : '' ?>>
  <?php if (!empty($landlordProfile['photo'])): ?>
    <img src="<?= htmlspecialchars($landlordProfile['photo'], ENT_QUOTES, 'UTF-8') ?>" alt="" referrerpolicy="no-referrer" class="avatar-img" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline-flex';">
    <span style="display:none;"><?= htmlspecialchars($landlordProfile['initials'] ?? 'L', ENT_QUOTES, 'UTF-8') ?></span>
  <?php else: ?>
    <?= htmlspecialchars($landlordProfile['initials'] ?? 'L', ENT_QUOTES, 'UTF-8') ?>
  <?php endif; ?>
</button>
<section id="notification-popover" class="popover" aria-labelledby="popover-title" hidden>
<h2 id="popover-title">Notifications</h2>
<div class="empty" id="popover-empty"><span class="empty-icon"><svg aria-hidden="true"><use href="#bell-off-icon"/></svg></span><strong>No new notifications</strong><p>We’ll let you know when something arrives.</p></div>
<ul class="notification-list" id="popover-list" aria-label="Unread notifications" hidden></ul>
<a class="view-all" id="view-all" href="notifications.php?from=dashboard" aria-label="View all notifications">View all notifications <svg aria-hidden="true"><use href="#arrow-icon"/></svg></a>
</section>
</div></header>

<main id="dashboard-page" tabindex="-1">

    <!-- Dashboard Overview: 3 Stat Cards on Left, Recent Bookings on Right -->
    <section class="dashboard-overview">
        
        <!-- Left Column: The 3 Requested Stat Cards -->
        <div class="stats-grid">
            
            <!-- Card 1: TOTAL PROPERTIES -->
            <article class="stat-card">
                <div class="stat-card-head">
                    <span class="stat-card-icon">
                        <svg aria-hidden="true"><use href="#home-icon"/></svg>
                    </span>
                    <span class="card-label">TOTAL PROPERTIES</span>
                </div>
                <div class="card-middle">
                    <h3 class="card-value"><?= $totalProperties ?></h3>
                </div>
                <div class="card-bottom">
                    <p class="card-subtext"><?= $totalProperties === 1 ? '1 property listed' : "{$totalProperties} properties listed" ?></p>
                </div>
            </article>

            <!-- Card 2: OCCUPANCY RATE -->
            <article class="stat-card">
                <div class="stat-card-head">
                    <span class="stat-card-icon">
                        <svg aria-hidden="true"><use href="#bed-icon"/></svg>
                    </span>
                    <span class="card-label">OCCUPANCY RATE</span>
                </div>
                <div class="card-middle">
                    <h3 class="card-value"><?= $occupancyRate ?>%</h3>
                </div>
                <div class="card-bottom">
                    <p class="card-subtext"><?= htmlspecialchars($occupancySub, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </article>

            <!-- Card 3: TOTAL TENANTS -->
            <article class="stat-card">
                <div class="stat-card-head">
                    <span class="stat-card-icon">
                        <svg aria-hidden="true"><use href="#user-icon"/></svg>
                    </span>
                    <span class="card-label">TOTAL TENANTS</span>
                </div>
                <div class="card-middle">
                    <h3 class="card-value"><?= $displayTenantsCount ?></h3>
                </div>
                <div class="card-bottom">
                    <p class="card-subtext"><?= htmlspecialchars($tenantsSub, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </article>

        </div>

        <!-- Right Column: Recent Bookings -->
        <section class="current-booking-card">
            <header class="current-booking-header">
                <h2 class="section-title">Recent Bookings</h2>
            </header>

            <div class="recent-bookings-body">
                <?php if (empty($recentBookings)): ?>
                    <div class="recent-bookings-empty">
                        <span class="recent-bookings-empty-icon">
                            <svg aria-hidden="true"><use href="#booking-icon"/></svg>
                        </span>
                        <h3>No Bookings Yet</h3>
                        <p>Verified student reservations will appear here automatically.</p>
                        <a href="properties.php" class="btn-manage-properties">Manage Properties</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($recentBookings as $b):
                        $tenant = resolve_tenant_details($b);
                        $roomTitle = trim((string)($b['room_name'] ?? ''));
                        if ($roomTitle === '') {
                            $roomTitle = 'Reserved Room';
                        }
                        $propTitle = (string)($b['property_name'] ?? 'Property');
                        $uniTitle = (string)($b['university'] ?? '');
                        $roomType = ucfirst(strtolower((string)($b['room_type'] ?? 'Room')));
                        $status = strtolower((string)($b['status'] ?? 'pending'));
                    ?>
                        <article class="recent-booking-card">
                            <div class="booking-avatar-circle">
                                <?php if ($tenant['photo'] !== ''): ?>
                                    <img src="<?= htmlspecialchars($tenant['photo'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($tenant['name'], ENT_QUOTES, 'UTF-8') ?>" onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='grid';">
                                    <span style="display:none;"><?= htmlspecialchars($tenant['initial'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php else: ?>
                                    <span><?= htmlspecialchars($tenant['initial'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="booking-info-col">
                                <div class="booking-room-heading">
                                    <span><?= htmlspecialchars($roomTitle, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="booking-status-tag <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <p class="booking-dorm-sub">
                                    <?= htmlspecialchars($propTitle, ENT_QUOTES, 'UTF-8') ?>
                                    <?php if ($uniTitle !== ''): ?> &bull; <?= htmlspecialchars($uniTitle, ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                                </p>
                                <p class="booking-tenant-meta">
                                    <svg style="width:14px;height:14px;color:var(--brown);" aria-hidden="true"><use href="#user-icon"/></svg>
                                    <span><?= htmlspecialchars($tenant['name'], ENT_QUOTES, 'UTF-8') ?> &bull; <?= htmlspecialchars($roomType, ENT_QUOTES, 'UTF-8') ?></span>
                                </p>
                            </div>
                            <a href="tenants.php" class="btn-booking-details">View details</a>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Recent Bookings Footer: View All link at the very bottom -->
            <footer class="recent-bookings-footer">
                <a class="recent-bookings-view-all" href="tenants.php" aria-label="View all bookings">
                    View all bookings &rarr;
                </a>
            </footer>
        </section>

    </section>

</main>
</div>

<p id="feedback" class="notification-feedback" role="status"></p>
<script id="notification-data" type="application/json"><?= json_encode($landlordNotifications, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<script>window.UNISTAY_NOTIFICATION_ACCOUNT = <?= json_encode(['id' => $userId, 'role' => 'landlord']) ?>;</script>
<script src="../assets/js/notification-preferences.js?v=3"></script>
<script src="../assets/js/landlord-notifications.js?v=7"></script>

<?php if (false && $profileIncomplete): // Replaced by the mandatory centered registration modal. ?>
<aside class="profile-toast" id="profileToast" role="alert" aria-live="polite">
    <div class="profile-toast-inner">
        <div class="profile-toast-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                <circle cx="12" cy="7" r="4"></circle>
            </svg>
        </div>
        <div class="profile-toast-body">
            <div class="profile-toast-header">
                <strong>Finish setting up your profile</strong>
                <button type="button" class="profile-toast-close" id="profileToastDismiss" aria-label="Dismiss toast">&times;</button>
            </div>
            <p>Would you like to complete your landlord profile information now or finish it later?</p>
            <div class="profile-toast-actions">
                <button type="button" class="toast-btn toast-btn--secondary" id="toastBtnLater">Later</button>
                <a href="settings.php?mode=edit" class="toast-btn toast-btn--primary" id="toastBtnNow">Now</a>
            </div>
        </div>
    </div>
</aside>
<script>
(function () {
  var toast = document.getElementById('profileToast');
  if (!toast) return;

  if (sessionStorage.getItem('unistay_landlord_toast_dismissed') === '1') {
    toast.remove();
    return;
  }

  function dismiss() {
    toast.classList.add('is-dismissing');
    sessionStorage.setItem('unistay_landlord_toast_dismissed', '1');
    setTimeout(function () {
      toast.remove();
    }, 360);
  }

  var btnLater = document.getElementById('toastBtnLater');
  var btnClose = document.getElementById('profileToastDismiss');

  if (btnLater) btnLater.addEventListener('click', dismiss);
  if (btnClose) btnClose.addEventListener('click', dismiss);
})();
</script>
<?php endif; ?>

<script>
window.UNISTAY_TENANT_PROFILE = <?= json_encode($landlordProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.CURRENT_LANDLORD = <?= json_encode($landlordProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<?php if ($isNewLandlord): ?>
<?php require __DIR__ . '/inc/onboarding.php'; ?>
<script src="../assets/js/landlord-onboarding.js?v=1"></script>
<?php endif; ?>
<?php require __DIR__ . '/../config/registration-modal.php'; ?>
</body>
</html>
