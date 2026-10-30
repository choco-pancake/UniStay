<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

$authenticatedUser = require_signed_in('landlord');
$pdo = database();
$userId = (int)($_SESSION['user_id'] ?? 0);
$landlordProfile = get_authenticated_profile($pdo, $userId);
$profileIncomplete = !landlord_profile_complete($pdo, $userId);
$landlordVerif = get_landlord_verification($pdo, $userId);
$isLandlordVerified = ($landlordVerif['status'] ?? '') === 'verified';
require_once __DIR__ . '/../config/notifications.php';
$landlordNotifications = get_user_in_app_notifications($pdo, $userId, 100);

$actionMsg = null;
$actionErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_action'], $_POST['booking_id'])) {
    $bookingId = (int)$_POST['booking_id'];
    $action = trim((string)$_POST['booking_action']);

    if (!$isLandlordVerified) {
        $actionErr = 'Landlord Verification Required: You must submit a valid government ID in Settings and have your account verified by an administrator before managing booking requests.';
    } else {
        $chkStmt = $pdo->prepare('
            SELECT b.id, b.user_id, b.status, u.first_name, u.last_name, p.name AS prop_name, r.name AS room_name
            FROM bookings b
            JOIN properties p ON p.id = b.property_id
            JOIN users u ON u.id = b.user_id
            LEFT JOIN rooms r ON r.id = b.room_id
            WHERE b.id = ? AND p.landlord_id = ?
            LIMIT 1
        ');
        $chkStmt->execute([$bookingId, $userId]);
        $targetBooking = $chkStmt->fetch();

        if (!$targetBooking) {
            $actionErr = 'Booking record not found or does not belong to your properties.';
        } else {
            $tenantName = trim(($targetBooking['first_name'] ?? '') . ' ' . ($targetBooking['last_name'] ?? '')) ?: 'Student';
            $propName = (string)($targetBooking['prop_name'] ?? 'Dormitory');
            $roomSuffix = !empty($targetBooking['room_name']) ? " (Room {$targetBooking['room_name']})" : "";
            $tenantUserId = (int)$targetBooking['user_id'];

            if ($action === 'approve') {
                $upd = $pdo->prepare("UPDATE bookings SET status = 'active', updated_at = NOW() WHERE id = ?");
                $upd->execute([$bookingId]);
                $actionMsg = "Booking request from {$tenantName} has been approved! They are now an active resident.";
                require_once __DIR__ . '/../config/notifications.php';
                send_booking_decision_notification($pdo, $bookingId, 'active');
                send_landlord_booking_decision_confirmation($pdo, $bookingId, 'active');
                // Send in-app notification to tenant
                add_in_app_notification(
                    $pdo,
                    $tenantUserId,
                    'approval',
                    'Booking Request Approved! 🎉',
                    "Great news! Your booking request for {$propName}{$roomSuffix} has been approved by the landlord. You are now an active resident!",
                    $bookingId
                );
            } elseif ($action === 'reject') {
                $upd = $pdo->prepare("UPDATE bookings SET status = 'rejected', updated_at = NOW() WHERE id = ?");
                $upd->execute([$bookingId]);
                $actionMsg = "Booking request from {$tenantName} has been declined.";
                require_once __DIR__ . '/../config/notifications.php';
                send_booking_decision_notification($pdo, $bookingId, 'rejected');
                send_landlord_booking_decision_confirmation($pdo, $bookingId, 'rejected');
                // Send in-app notification to tenant
                add_in_app_notification(
                    $pdo,
                    $tenantUserId,
                    'cancellation',
                    'Booking Request Declined',
                    "Your booking request for {$propName}{$roomSuffix} was declined by the landlord.",
                    $bookingId
                );
            } elseif ($action === 'end_tenancy') {
                $upd = $pdo->prepare("UPDATE bookings SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
                $upd->execute([$bookingId]);
                $actionMsg = "Tenancy for {$tenantName} has ended.";
            }
        }
    }
}

$statusFilter = trim((string)($_GET['status'] ?? 'pending'));
$validStatuses = ['pending', 'active', 'all', 'rejected', 'cancelled'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'pending';
}
$highlightBookingId = (int)($_GET['highlight'] ?? $_GET['booking_id'] ?? 0);

$sql = '
    SELECT b.id, b.status, b.created_at,
           u.first_name, u.last_name, u.personal_email, u.phone_number, u.school, u.photo AS user_photo, u.profile_photo, u.verification_status AS tenant_verification,
           p.name AS property_name, p.university,
           r.name AS room_name, r.type AS room_type, r.rent
    FROM bookings b
    JOIN properties p ON p.id = b.property_id
    JOIN users u ON u.id = b.user_id
    LEFT JOIN rooms r ON r.id = b.room_id
    WHERE p.landlord_id = ?
';
$sql .= ' ORDER BY b.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute([$userId]);
$bookings = $stmt->fetchAll() ?: [];

$matchingBookingsCount = 0;
foreach ($bookings as $b) {
    $st = strtolower((string)$b['status']);
    if ($statusFilter === 'all' || $statusFilter === $st) {
        $matchingBookingsCount++;
    }
}

// Status counts
$countsStmt = $pdo->prepare('
    SELECT b.status, COUNT(*) AS cnt
    FROM bookings b
    JOIN properties p ON p.id = b.property_id
    WHERE p.landlord_id = ?
    GROUP BY b.status
');
$countsStmt->execute([$userId]);
$counts = ['all' => 0, 'active' => 0, 'pending' => 0, 'rejected' => 0, 'cancelled' => 0];
foreach ($countsStmt->fetchAll() as $row) {
    $s = strtolower((string)$row['status']);
    if (isset($counts[$s])) {
        $counts[$s] = (int)$row['cnt'];
    }
    $counts['all'] += (int)$row['cnt'];
}

function tenant_card_avatar(array $b): array
{
    $name = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
    if ($name === '') {
        $name = (string)($b['personal_email'] ?? 'Tenant');
    }
    $initial = strtoupper(substr($name, 0, 1));
    $photo = resolve_user_profile_photo_url(
        $b['profile_photo'] ?? null,
        $b['user_photo'] ?? null,
        'tenant'
    );
    return ['name' => $name, 'initial' => $initial, 'photo' => $photo];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Requests · UniStay Landlord</title>
<link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/common.css?v=32">
<link rel="stylesheet" href="../assets/css/dashboardstyle.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/dashboardstyle.css'); ?>">
<link rel="stylesheet" href="../assets/css/confirm-modal.css?v=1">
<style>
.topbar h1 {
    font-family: 'Fraunces', Georgia, "Times New Roman", serif !important;
    font-weight: 700 !important;
    color: #5f3425 !important;
    letter-spacing: -0.6px;
}

.requests-page-title {
    font-family: 'Fraunces', Georgia, "Times New Roman", serif !important;
    font-weight: 700 !important;
    color: #5f3425 !important;
    font-size: 1.6rem;
    margin: 0 0 4px;
}

.shell-orbs {
    position: absolute;
    inset: 0;
    overflow: hidden;
    border-radius: inherit;
    pointer-events: none;
    z-index: 0;
}
.shell-orbs i {
    position: absolute;
    display: block;
    border-radius: 50%;
    filter: blur(64px);
}
.shell-orbs i:nth-child(1) { width: 360px; height: 360px; background: #F3E2AC; opacity: .3; top: -110px; left: 14%; }
.shell-orbs i:nth-child(2) { width: 430px; height: 430px; background: #E4CDAF; opacity: .3; bottom: -150px; right: 6%; }
.shell-orbs i:nth-child(3) { width: 320px; height: 320px; background: #FFBA95; opacity: .2; top: 36%; left: 50%; }

.tenants-container {
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    gap: 24px;
    padding-bottom: 40px;
}

/* Rectangular pill filters matching the dorms */
.dorms-stay-filters,
.tenants-tabs {
    position: relative;
    display: flex;
    width: max-content;
    max-width: 100%;
    gap: 4px;
    margin: 0 0 22px;
    padding: 6px;
    border-radius: 14px;
    background: #EFEBE7;
    overflow-x: auto;
    scrollbar-width: none;
    border-bottom: none;
}

.dorms-stay-filters::-webkit-scrollbar,
.tenants-tabs::-webkit-scrollbar {
    display: none;
}

.stay-filter-btn,
.tab-btn {
    position: relative;
    z-index: 1;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    height: 40px;
    padding: 0 16px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: #7a4b3a;
    font-size: 0.88rem;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
    cursor: pointer;
    transition: color 0.25s ease, background-color 0.25s ease, transform 0.15s ease;
}

.stay-pill {
    position: absolute;
    top: 6px;
    left: 0;
    width: 0;
    height: 40px;
    border-radius: 10px;
    background: #7a4b3a;
    box-shadow: 0 2px 8px rgba(122, 75, 58, 0.22);
    z-index: 0;
    pointer-events: none;
    transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1), width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
}

.stay-filter-btn:hover:not(.is-active):not(.active),
.tab-btn:hover:not(.active):not(.is-active) {
    color: #5f3425;
    background: rgba(122, 75, 58, 0.08);
}

.stay-filter-btn.is-active,
.stay-filter-btn.active,
.tab-btn.active,
.tab-btn.is-active {
    background: transparent !important;
    color: #ffffff;
    box-shadow: none !important;
}

@media (prefers-reduced-motion: reduce) {
    .stay-pill {
        transition: none !important;
    }
}

.stay-filter-count,
.tab-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    background: rgba(122, 75, 58, 0.12);
    color: #7a4b3a;
    transition: background 0.25s ease, color 0.25s ease;
}

.stay-filter-btn.is-active .stay-filter-count,
.stay-filter-btn.active .stay-filter-count,
.tab-btn.active .tab-count,
.tab-btn.is-active .tab-count {
    background: rgba(255, 255, 255, 0.22);
    color: #ffffff;
}

/* View Listed Properties button: enlarges on hover and click */
.btn-view-properties,
.btn-booking-details {
    font: 600 0.88rem 'DM Sans', sans-serif;
    padding: 0 22px;
    height: 42px;
    border-radius: 8px;
    border: 1px solid var(--brown, #7a4b3a);
    background: var(--brown, #7a4b3a);
    color: #ffffff !important;
    text-decoration: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 6px rgba(122, 75, 58, 0.18);
    transform: scale(1);
    transform-origin: center;
    transition: transform 0.18s cubic-bezier(0.2, 0, 0, 1), background-color 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
    margin-top: 14px;
}

.btn-view-properties:hover,
.btn-view-properties:focus-visible,
.btn-booking-details:hover,
.btn-booking-details:focus-visible {
    color: #ffffff !important;
    background: var(--brown-dark, #5f3425);
    border-color: var(--brown-dark, #5f3425);
    transform: scale(1.08);
    box-shadow: 0 8px 22px rgba(122, 75, 58, 0.24);
}

.btn-view-properties:active,
.btn-booking-details:active {
    color: #ffffff !important;
    background: var(--brown-darker, #432419);
    border-color: var(--brown-darker, #432419);
    transform: scale(1.12);
    box-shadow: 0 10px 28px rgba(122, 75, 58, 0.3);
}

.tenants-table-card {
    background: #ffffff;
    border: 1px solid var(--line);
    border-radius: 20px;
    box-shadow: 0 6px 18px rgba(92, 62, 48, 0.08);
    overflow: hidden;
}

.tenants-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}

.tenants-table th {
    background: #fbf5ec;
    padding: 16px 20px;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--text-muted);
    border-bottom: 1px solid var(--line);
}

.tenants-table td {
    padding: 18px 20px;
    border-bottom: 1px solid #f3ece4;
    font-size: 0.92rem;
    color: var(--text-dark);
}

.tenants-table tr:last-child td {
    border-bottom: none;
}

.tenants-table tr:hover td {
    background: #fdfaf6;
}

.tenant-cell-user {
    display: flex;
    align-items: center;
    gap: 12px;
}

.tenant-avatar-sm {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: #fbf4ee;
    border: 1px solid var(--line);
    color: var(--head);
    font-weight: 700;
    font-size: 14px;
    display: grid;
    place-items: center;
    overflow: hidden;
    flex-shrink: 0;
}

.tenant-avatar-sm img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.tenant-meta-name {
    font-weight: 700;
    color: var(--head);
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.tenant-meta-email {
    font-size: 0.8rem;
    color: var(--text-muted);
}

.btn-action-approve {
    padding: 8px 18px;
    background: #16a34a;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background 0.18s, transform 0.18s, opacity 0.18s;
}

.btn-action-approve:hover:not(.is-loading) {
    background: #15803d;
    transform: translateY(-1px);
}

.btn-action-reject {
    padding: 8px 18px;
    background: #dc2626;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background 0.18s, transform 0.18s, opacity 0.18s;
}

.btn-action-reject:hover:not(.is-loading) {
    background: #b91c1c;
    transform: translateY(-1px);
}

.btn-action-approve.is-loading,
.btn-action-reject.is-loading,
.btn-action-end.is-loading {
    opacity: 0.85;
    cursor: wait;
    pointer-events: none;
    transform: none !important;
}

.btn-action-end {
    padding: 8px 18px;
    background: #f3f4f6;
    color: #374151;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s, transform 0.18s;
}

.btn-action-end:hover:not(.is-loading) {
    background: #e5e7eb;
    color: #111827;
}

.booking-status-tag.rejected {
    background: #fee2e2;
    color: #991b1b;
}

.nav-badge-pill {
    background: #dc2626;
    color: #ffffff;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 1px 7px;
    border-radius: 999px;
    margin-left: auto;
}

.highlight-row {
    background: #fef3c7 !important;
    animation: highlightPulse 2.5s ease-out;
    box-shadow: inset 0 0 0 2px #d97706;
}

@keyframes highlightPulse {
    0% { background: #fde68a; }
    50% { background: #fef3c7; }
    100% { background: #fffbeb; }
}

.badge-verified-student {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    background: #dcfce7;
    color: #15803d;
    font-size: 0.7rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 999px;
}
</style>
<script src="../assets/js/tenant/popover.js?v=2"></script>
<script src="../assets/js/tenant/sidebar.js"></script>
<script src="../assets/js/tenant/profile-menu.js?v=31"></script>
</head>
<body>
<svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs>
<symbol id="grid-icon" viewBox="0 0 24 24"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></symbol>
<symbol id="home-icon" viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3Z"/></symbol>
<symbol id="bed-icon" viewBox="0 0 24 24"><path d="M3 19V9h18v10M3 16h18M5 9V5h5v4m4 0V5h5v4"/></symbol>
<symbol id="user-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></symbol>
<symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
<symbol id="bell-icon" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M10 20h4"/></symbol>
<symbol id="bell-off-icon" viewBox="0 0 24 24"><path d="m3 3 18 18M10 20h4M6 6c-1 2 0 7-3 10h13M18 13V8a6 6 0 0 0-8-6"/></symbol>
<symbol id="arrow-icon" viewBox="0 0 24 24"><path d="M4 12h16m-6-6 6 6-6 6"/></symbol>
<symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
<symbol id="logout-icon" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
</defs></svg>

<!-- Landlord Sidebar -->
<aside class="sidebar" aria-label="UniStay Landlord Portal">
<a href="dashboard.php" class="brand" title="UniStay Home"><img src="../assets/images/tenant/logo.png" width="50" height="50" alt="UniStay logo"><span class="brand-name">UniStay</span></a>
<nav aria-label="Main navigation">
<a href="dashboard.php" title="Dashboard"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
<a href="properties.php" title="Properties"><svg aria-hidden="true"><use href="#bed-icon"/></svg><span class="nav-label">Properties</span></a>
<a href="tenants.php" title="Requests" aria-current="page">
  <svg aria-hidden="true"><use href="#user-icon"/></svg>
  <span class="nav-label">Requests</span>
  <?php if ($counts['pending'] > 0): ?>
    <span class="nav-badge-pill"><?= $counts['pending'] ?></span>
  <?php endif; ?>
</a>
<a href="settings.php" title="Settings"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
</nav>
<button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true"><span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span><span class="toggle-label">Collapse</span></button>
</aside>

<div class="shell">
<div class="shell-orbs" aria-hidden="true"><i></i><i></i><i></i></div>
<header class="topbar"><h1>Requests</h1><div class="profile">
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
<a class="view-all" id="view-all" href="notifications.php?from=tenants" aria-label="View all notifications">View all notifications <svg aria-hidden="true"><use href="#arrow-icon"/></svg></a>
</section>
</div></header>

<main id="dashboard-page" tabindex="-1" style="padding-top:20px;">
    <div class="tenants-container">
        <?php if (!$isLandlordVerified): ?>
        <div class="verification-notice-banner" style="background:#fffbeb; border:1px solid #fde68a; border-radius:14px; padding:16px 20px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
            <div style="display:flex; align-items:center; gap:12px;">
                <span style="font-size:24px;">⚠️</span>
                <div>
                    <strong style="color:#92400e; font-size:0.96rem; display:block;">Landlord Verification Required (Status: <?= htmlspecialchars(ucfirst($landlordVerif['status'] ?? 'Unverified'), ENT_QUOTES, 'UTF-8') ?>)</strong>
                    <span style="color:#78350f; font-size:0.88rem;">You must complete your registration form and submit a valid government ID in Settings to be verified before approving or rejecting booking requests.</span>
                </div>
            </div>
            <a href="settings.php" style="background:#b45309; color:#fff; font-weight:700; font-size:0.88rem; padding:8px 16px; border-radius:8px; text-decoration:none;">Go to Settings</a>
        </div>
        <?php endif; ?>

        <?php if ($actionMsg): ?>
        <div style="background:#dcfce7; border:1px solid #bbf7d0; color:#166534; padding:14px 18px; border-radius:12px; font-weight:600; font-size:0.92rem;">
            ✓ <?= htmlspecialchars($actionMsg, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <?php if ($actionErr): ?>
        <div style="background:#fee2e2; border:1px solid #fecaca; color:#991b1b; padding:14px 18px; border-radius:12px; font-weight:600; font-size:0.92rem;">
            ✕ <?= htmlspecialchars($actionErr, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <!-- Request Center Header -->
        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:4px;">
            <div>
                <h2 class="requests-page-title">Student Booking Requests</h2>
                <p style="color:var(--text-muted); font-size:0.92rem; margin:0;">Review, approve, or decline room booking requests submitted by verified students.</p>
            </div>
            <?php if ($counts['pending'] > 0): ?>
                <div style="background:#fef3c7; border:1px solid #fde68a; color:#92400e; padding:6px 14px; border-radius:999px; font-weight:700; font-size:0.86rem; display:flex; align-items:center; gap:6px;">
                    <span style="font-size:14px;">⏳</span> <?= $counts['pending'] ?> Pending <?= $counts['pending'] === 1 ? 'Request' : 'Requests' ?> Awaiting Review
                </div>
            <?php endif; ?>
        </div>

        <!-- Filter Tabs: Rectangular Pills matching Dorms -->
        <div class="dorms-stay-filters tenants-tabs" role="tablist" aria-label="Filter by request status">
            <span class="stay-pill" aria-hidden="true"></span>
            <a href="tenants.php?status=pending" data-status="pending" class="stay-filter-btn tab-btn <?= $statusFilter === 'pending' ? 'is-active active' : '' ?>">
                <span>Pending Requests</span>
                <span class="stay-filter-count tab-count"><?= $counts['pending'] ?></span>
            </a>
            <a href="tenants.php?status=active" data-status="active" class="stay-filter-btn tab-btn <?= $statusFilter === 'active' ? 'is-active active' : '' ?>">
                <span>Active Residents</span>
                <span class="stay-filter-count tab-count"><?= $counts['active'] ?></span>
            </a>
            <a href="tenants.php?status=all" data-status="all" class="stay-filter-btn tab-btn <?= $statusFilter === 'all' ? 'is-active active' : '' ?>">
                <span>All Bookings</span>
                <span class="stay-filter-count tab-count"><?= $counts['all'] ?></span>
            </a>
            <a href="tenants.php?status=rejected" data-status="rejected" class="stay-filter-btn tab-btn <?= $statusFilter === 'rejected' ? 'is-active active' : '' ?>">
                <span>Declined</span>
                <span class="stay-filter-count tab-count"><?= $counts['rejected'] ?></span>
            </a>
            <a href="tenants.php?status=cancelled" data-status="cancelled" class="stay-filter-btn tab-btn <?= $statusFilter === 'cancelled' ? 'is-active active' : '' ?>">
                <span>Ended Tenancies</span>
                <span class="stay-filter-count tab-count"><?= $counts['cancelled'] ?></span>
            </a>
        </div>

        <!-- Table Card -->
        <div class="tenants-table-card">
            <div id="tenants-empty-state" style="padding:48px 24px; text-align:center; <?= ($matchingBookingsCount > 0 ? 'display:none;' : '') ?>">
                <p style="color:var(--text-muted); font-size:1rem; margin-bottom:12px;">No requests or bookings found matching this filter.</p>
                <a href="properties.php" class="btn-view-properties">View Listed Properties</a>
            </div>

            <table class="tenants-table" id="tenants-table" style="<?= ($matchingBookingsCount === 0 ? 'display:none;' : '') ?>">
                <thead>
                    <tr>
                        <th>Student Tenant</th>
                        <th>Property</th>
                        <th>Room &amp; Rent</th>
                        <th>University / School</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="tenants-table-body">
                    <?php foreach ($bookings as $b):
                        $t = tenant_card_avatar($b);
                        $st = strtolower((string)$b['status']);
                        $bId = (int)$b['id'];
                        $isHighlighted = ($highlightBookingId === $bId);
                        $propLabel = (string)$b['property_name'];
                        $declineMsg = $t['name'] . ' will be notified that their request for ' . $propLabel . ' was declined. This action cannot be undone.';
                        $endMsg = 'The tenancy of ' . $t['name'] . ' at ' . $propLabel . ' will be marked as ended and moved to Ended Tenancies.';
                        $isVisible = ($statusFilter === 'all' || $statusFilter === $st);
                    ?>
                        <tr id="booking-row-<?= $bId ?>" class="<?= $isHighlighted ? 'highlight-row' : '' ?>" data-status="<?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?>" style="<?= $isVisible ? '' : 'display:none;' ?>">
                                <td>
                                    <div class="tenant-cell-user">
                                        <div class="tenant-avatar-sm">
                                            <?php if ($t['photo'] !== ''): ?>
                                                <img src="<?= htmlspecialchars($t['photo'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                                            <?php else: ?>
                                                <span><?= htmlspecialchars($t['initial'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="tenant-meta-name">
                                                <span><?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php if (($b['tenant_verification'] ?? '') === 'verified'): ?>
                                                    <span class="badge-verified-student" title="Verified Student Credentials">✓ Verified</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="tenant-meta-email">
                                                <?= htmlspecialchars((string)($b['personal_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                <?php if (!empty($b['phone_number'])): ?>
                                                    · <?= htmlspecialchars((string)$b['phone_number'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars((string)$b['property_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string)($b['room_name'] ?? 'Room'), ENT_QUOTES, 'UTF-8') ?>
                                    <span style="display:block; font-size:0.78rem; color:var(--text-muted);">
                                        <?= htmlspecialchars((string)($b['room_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (!empty($b['rent'])): ?>
                                            · ₱<?= number_format((float)$b['rent'], 2) ?>/mo
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string)($b['school'] ?: $b['university']), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <span class="booking-status-tag <?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td>
                                    <?php if ($st === 'pending'): ?>
                                        <?php if ($isLandlordVerified): ?>
                                            <form method="POST" style="display:inline-flex; gap:6px;">
                                                <input type="hidden" name="booking_id" value="<?= $bId ?>">
                                                <button type="submit" name="booking_action" value="approve" class="btn-action-approve" title="Approve booking">Approve</button>
                                                <button type="submit" name="booking_action" value="reject" class="btn-action-reject" title="Decline booking"
                                                    data-confirm-crumb="UniStay &bull; Booking Request"
                                                    data-confirm-title="Decline this booking request?"
                                                    data-confirm-message="<?= htmlspecialchars($declineMsg, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-confirm-ok="Yes, decline"
                                                    data-confirm-cancel="Keep request">Decline</button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color:#b45309; font-size:0.8rem; font-weight:600;">Verification Required</span>
                                        <?php endif; ?>
                                    <?php elseif ($st === 'active'): ?>
                                        <?php if ($isLandlordVerified): ?>
                                            <form method="POST" style="display:inline-flex;">
                                                <input type="hidden" name="booking_id" value="<?= $bId ?>">
                                                <button type="submit" name="booking_action" value="end_tenancy" class="btn-action-end" title="End Tenancy"
                                                    data-confirm-crumb="UniStay &bull; Tenancy"
                                                    data-confirm-title="End this student tenancy?"
                                                    data-confirm-message="<?= htmlspecialchars($endMsg, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-confirm-ok="Yes, end tenancy"
                                                    data-confirm-cancel="Keep tenancy">End Tenancy</button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color:#166534; font-size:0.8rem; font-weight:600;">Active Resident</span>
                                        <?php endif; ?>
                                    <?php elseif ($st === 'rejected'): ?>
                                        <span style="color:#991b1b; font-size:0.8rem; font-weight:600;">Declined</span>
                                    <?php elseif ($st === 'cancelled'): ?>
                                        <span style="color:#6b7280; font-size:0.8rem;">Tenancy Ended</span>
                                    <?php else: ?>
                                        <span style="color:#9c8577; font-size:0.8rem;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
        </div>
    </div>
</main>
</div>

<script id="notification-data" type="application/json">
<?= json_encode($landlordNotifications, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
</script>
<script>
window.UNISTAY_NOTIFICATION_ACCOUNT = <?= json_encode(['id' => (int)($_SESSION['user_id'] ?? 0), 'role' => 'landlord']) ?>;
</script>
<script src="../assets/js/notification-preferences.js?v=2"></script>
<script src="../assets/js/landlord-notifications.js?v=6"></script>
<script src="../assets/js/confirm-modal.js?v=1"></script>
<script>
window.UNISTAY_TENANT_PROFILE = <?= json_encode($landlordProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.CURRENT_LANDLORD = <?= json_encode($landlordProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

document.addEventListener('DOMContentLoaded', () => {
    const hl = document.querySelector('.highlight-row');
    if (hl) {
        setTimeout(() => {
            hl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 150);
    }
    placeStayPill(true);
});

// Moving rectangular pill indicator matching dorms
const stayTabs = document.querySelector('.dorms-stay-filters');
const stayPill = stayTabs ? stayTabs.querySelector('.stay-pill') : null;
let stayPillReady = false;

function placeStayPill(instant) {
    const active = stayTabs ? stayTabs.querySelector('.stay-filter-btn.is-active, .stay-filter-btn.active') : null;
    if (!stayPill || !active) return;
    const noAnim = instant || !stayPillReady;
    if (noAnim) stayPill.style.transition = 'none';
    stayPill.style.width = active.offsetWidth + 'px';
    stayPill.style.transform = 'translateX(' + active.offsetLeft + 'px)';
    if (noAnim) {
        void stayPill.offsetWidth;
        stayPill.style.transition = '';
        stayPillReady = true;
    }
}

function applyStatusFilter(status) {
    const rows = document.querySelectorAll('#tenants-table-body tr');
    let visibleCount = 0;
    rows.forEach(row => {
        const rowStatus = row.dataset.status;
        if (status === 'all' || rowStatus === status) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const table = document.getElementById('tenants-table');
    const emptyState = document.getElementById('tenants-empty-state');
    if (table && emptyState) {
        if (visibleCount === 0) {
            table.style.display = 'none';
            emptyState.style.display = '';
        } else {
            table.style.display = '';
            emptyState.style.display = 'none';
        }
    }
}

document.querySelectorAll('.stay-filter-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.preventDefault();
        document.querySelectorAll('.stay-filter-btn').forEach(b => {
            b.classList.remove('is-active', 'active');
            b.setAttribute('aria-selected', 'false');
        });
        btn.classList.add('is-active', 'active');
        btn.setAttribute('aria-selected', 'true');
        
        placeStayPill();
        
        const status = btn.dataset.status || 'all';
        applyStatusFilter(status);

        if (window.history && window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.set('status', status);
            window.history.replaceState(null, '', url.toString());
        }
    });
});

placeStayPill(true);
window.addEventListener('load', () => placeStayPill(true));
window.addEventListener('resize', () => placeStayPill(true));
if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(() => placeStayPill(true));
}

// Loading feedback on form submission
document.addEventListener('submit', (e) => {
    const submitter = e.submitter || document.activeElement;
    if (submitter && submitter.name === 'booking_action') {
        if (submitter.value === 'approve') {
            submitter.textContent = 'Approving...';
            submitter.classList.add('is-loading');
        } else if (submitter.value === 'reject') {
            submitter.textContent = 'Declining...';
            submitter.classList.add('is-loading');
        } else if (submitter.value === 'end_tenancy') {
            submitter.textContent = 'Ending...';
            submitter.classList.add('is-loading');
        }
    }
});

// Immediate loading feedback for direct Approve click
document.addEventListener('click', (e) => {
    const approveBtn = e.target.closest('.btn-action-approve');
    if (!approveBtn || approveBtn.classList.contains('is-loading')) return;
    approveBtn.textContent = 'Approving...';
    approveBtn.classList.add('is-loading');
});

// Styled confirmation modal for Decline / End Tenancy (replaces the browser alert)
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-confirm-title]');
    if (!btn) return;
    e.preventDefault();

    const ok = await UniStayConfirm.open({
        crumb: btn.dataset.confirmCrumb,
        title: btn.dataset.confirmTitle,
        message: btn.dataset.confirmMessage,
        confirmText: btn.dataset.confirmOk,
        cancelText: btn.dataset.confirmCancel,
        danger: true
    });
    if (!ok) return;

    const form = btn.form;
    if (!form) return;

    if (btn.classList.contains('btn-action-reject')) {
        btn.textContent = 'Declining...';
        btn.classList.add('is-loading');
    } else {
        btn.textContent = 'Ending...';
        btn.classList.add('is-loading');
    }

    if (typeof form.requestSubmit === 'function') {
        form.requestSubmit(btn); // keeps the button's name/value (booking_action)
    } else {
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = btn.name;
        hidden.value = btn.value;
        form.appendChild(hidden);
        form.submit();
    }
});
</script>
</body>
</html>
