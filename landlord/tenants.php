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
$params = [$userId];

if ($statusFilter !== 'all') {
    $sql .= ' AND b.status = ?';
    $params[] = $statusFilter;
}
$sql .= ' ORDER BY b.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll() ?: [];

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
<title>Request Center · UniStay Landlord</title>
<link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/common.css?v=32">
<link rel="stylesheet" href="../assets/css/dashboardstyle.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/dashboardstyle.css'); ?>">
<link rel="stylesheet" href="../assets/css/confirm-modal.css?v=1">
<style>
.tenants-container {
    display: flex;
    flex-direction: column;
    gap: 24px;
    padding-bottom: 40px;
}

.tenants-tabs {
    display: flex;
    gap: 8px;
    border-bottom: 1px solid var(--line);
    padding-bottom: 12px;
    flex-wrap: wrap;
}

.tab-btn {
    padding: 8px 18px;
    border-radius: 999px;
    font-size: 0.88rem;
    font-weight: 600;
    text-decoration: none;
    color: var(--text-muted);
    background: #fbf5ee;
    border: 1px solid var(--line);
    transition: all 0.2s ease;
}

.tab-btn:hover {
    background: #f3e9dc;
    color: var(--head);
}

.tab-btn.active {
    background: var(--brown);
    color: #ffffff;
    border-color: var(--brown);
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
    padding: 7px 15px;
    background: #16a34a;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.18s, transform 0.18s;
}

.btn-action-approve:hover {
    background: #15803d;
    transform: translateY(-1px);
}

.btn-action-reject {
    padding: 7px 15px;
    background: #dc2626;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.18s, transform 0.18s;
}

.btn-action-reject:hover {
    background: #b91c1c;
    transform: translateY(-1px);
}

.btn-action-end {
    padding: 7px 15px;
    background: #f3f4f6;
    color: #374151;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s;
}

.btn-action-end:hover {
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
<div class="brand"><img src="../assets/images/tenant/logo.png" width="50" height="50" alt=""><span class="brand-name">UniStay</span></div>
<nav aria-label="Main navigation">
<a href="dashboard.php" title="Dashboard"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
<a href="properties.php" title="Properties"><svg aria-hidden="true"><use href="#home-icon"/></svg><span class="nav-label">Properties</span></a>
<a href="tenants.php" title="Requests · Request Center" aria-current="page">
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
<header class="topbar"><h1>Request Center</h1><div class="profile">
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
                <h2 style="font-family:'Fraunces', Georgia, serif; color:var(--head); font-size:1.6rem; margin:0 0 4px;">Student Booking Requests</h2>
                <p style="color:var(--text-muted); font-size:0.92rem; margin:0;">Review, approve, or decline room booking requests submitted by verified students.</p>
            </div>
            <?php if ($counts['pending'] > 0): ?>
                <div style="background:#fef3c7; border:1px solid #fde68a; color:#92400e; padding:6px 14px; border-radius:999px; font-weight:700; font-size:0.86rem; display:flex; align-items:center; gap:6px;">
                    <span style="font-size:14px;">⏳</span> <?= $counts['pending'] ?> Pending <?= $counts['pending'] === 1 ? 'Request' : 'Requests' ?> Awaiting Review
                </div>
            <?php endif; ?>
        </div>

        <!-- Tabs -->
        <div class="tenants-tabs">
            <a href="tenants.php?status=pending" class="tab-btn <?= $statusFilter === 'pending' ? 'active' : '' ?>">Pending Requests (<?= $counts['pending'] ?>)</a>
            <a href="tenants.php?status=active" class="tab-btn <?= $statusFilter === 'active' ? 'active' : '' ?>">Active Residents (<?= $counts['active'] ?>)</a>
            <a href="tenants.php?status=all" class="tab-btn <?= $statusFilter === 'all' ? 'active' : '' ?>">All Bookings (<?= $counts['all'] ?>)</a>
            <a href="tenants.php?status=rejected" class="tab-btn <?= $statusFilter === 'rejected' ? 'active' : '' ?>">Declined (<?= $counts['rejected'] ?>)</a>
            <a href="tenants.php?status=cancelled" class="tab-btn <?= $statusFilter === 'cancelled' ? 'active' : '' ?>">Ended Tenancies (<?= $counts['cancelled'] ?>)</a>
        </div>

        <!-- Table Card -->
        <div class="tenants-table-card">
            <?php if (empty($bookings)): ?>
                <div style="padding:48px 24px; text-align:center;">
                    <p style="color:var(--text-muted); font-size:1rem; margin-bottom:12px;">No requests or bookings found matching this filter.</p>
                    <a href="properties.php" class="btn-booking-details">View Listed Properties</a>
                </div>
            <?php else: ?>
                <table class="tenants-table">
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
                    <tbody>
                        <?php foreach ($bookings as $b):
                            $t = tenant_card_avatar($b);
                            $st = strtolower((string)$b['status']);
                            $bId = (int)$b['id'];
                            $isHighlighted = ($highlightBookingId === $bId);
                            $propLabel = (string)$b['property_name'];
                            $declineMsg = $t['name'] . ' will be notified that their request for ' . $propLabel . ' was declined. This action cannot be undone.';
                            $endMsg = 'The tenancy of ' . $t['name'] . ' at ' . $propLabel . ' will be marked as ended and moved to Ended Tenancies.';
                        ?>
                            <tr id="booking-row-<?= $bId ?>" class="<?= $isHighlighted ? 'highlight-row' : '' ?>">
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
                                                <button type="submit" name="booking_action" value="approve" class="btn-action-approve" title="Approve booking">✓ Approve</button>
                                                <button type="submit" name="booking_action" value="reject" class="btn-action-reject" title="Decline booking"
                                                    data-confirm-crumb="UniStay &bull; Booking Request"
                                                    data-confirm-title="Decline this booking request?"
                                                    data-confirm-message="<?= htmlspecialchars($declineMsg, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-confirm-ok="Yes, decline"
                                                    data-confirm-cancel="Keep request">✕ Decline</button>
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
            <?php endif; ?>
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
