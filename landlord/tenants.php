<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

$authenticatedUser = require_signed_in('landlord');
$pdo = database();
$userId = (int)($_SESSION['user_id'] ?? 0);
$landlordProfile = get_authenticated_profile($pdo, $userId);
$profileIncomplete = !landlord_profile_complete($pdo, $userId);

$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$validStatuses = ['all', 'active', 'pending', 'cancelled'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'all';
}

$sql = '
    SELECT b.id, b.status, b.created_at,
           u.first_name, u.last_name, u.personal_email, u.phone_number, u.school, u.photo AS user_photo, u.profile_photo,
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
$counts = ['all' => 0, 'active' => 0, 'pending' => 0, 'cancelled' => 0];
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
    $photo = '';
    if (!empty($b['user_photo'])) {
        $photo = (string)$b['user_photo'];
    } elseif (!empty($b['profile_photo'])) {
        $p = (string)$b['profile_photo'];
        $photo = (strpos($p, 'http') === 0 || strpos($p, '/') === 0) ? $p : '../tenant/' . ltrim($p, '/');
    }
    return ['name' => $name, 'initial' => $initial, 'photo' => $photo];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#7a4b3a">
<title>Tenants & Bookings · UniStay</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/common.css?v=28">
<link rel="stylesheet" href="../assets/css/dashboardstyle.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/dashboardstyle.css'); ?>">
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
    width: 40px;
    height: 40px;
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
}

.tenant-meta-email {
    font-size: 0.8rem;
    color: var(--text-muted);
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
<symbol id="user-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></symbol>
<symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
<symbol id="bell-icon" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M10 20h4"/></symbol>
<symbol id="bell-off-icon" viewBox="0 0 24 24"><path d="m3 3 18 18M10 20h4M6 6c-1 2 0 7-3 10h13M18 13V8a6 6 0 0 0-8-6"/></symbol>
<symbol id="arrow-icon" viewBox="0 0 24 24"><path d="M4 12h16m-6-6 6 6-6 6"/></symbol>
<symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
</defs></svg>

<!-- Landlord Sidebar -->
<aside class="sidebar" aria-label="UniStay Landlord Portal">
<div class="brand"><img src="../assets/images/tenant/logo.png" width="50" height="50" alt=""><span class="brand-name">UniStay</span></div>
<nav aria-label="Main navigation">
<a href="dashboard.php" title="Dashboard"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
<a href="properties.php" title="Properties"><svg aria-hidden="true"><use href="#home-icon"/></svg><span class="nav-label">Properties</span></a>
<a href="tenants.php" title="Tenants" aria-current="page"><svg aria-hidden="true"><use href="#user-icon"/></svg><span class="nav-label">Tenants</span></a>
<a href="settings.php" title="Settings"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
</nav>
<button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true"><span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span><span class="toggle-label">Collapse</span></button>
</aside>

<div class="shell">
<header class="topbar"><h1>Tenants &amp; Bookings</h1><div class="profile">
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
        <!-- Tabs -->
        <div class="tenants-tabs">
            <a href="tenants.php?status=all" class="tab-btn <?= $statusFilter === 'all' ? 'active' : '' ?>">All Bookings (<?= $counts['all'] ?>)</a>
            <a href="tenants.php?status=active" class="tab-btn <?= $statusFilter === 'active' ? 'active' : '' ?>">Active (<?= $counts['active'] ?>)</a>
            <a href="tenants.php?status=pending" class="tab-btn <?= $statusFilter === 'pending' ? 'active' : '' ?>">Pending (<?= $counts['pending'] ?>)</a>
            <a href="tenants.php?status=cancelled" class="tab-btn <?= $statusFilter === 'cancelled' ? 'active' : '' ?>">Cancelled (<?= $counts['cancelled'] ?>)</a>
        </div>

        <!-- Table Card -->
        <div class="tenants-table-card">
            <?php if (empty($bookings)): ?>
                <div style="padding:48px 24px; text-align:center;">
                    <p style="color:var(--text-muted); font-size:1rem; margin-bottom:12px;">No bookings found matching this filter.</p>
                    <a href="properties.php" class="btn-booking-details">View Listed Properties</a>
                </div>
            <?php else: ?>
                <table class="tenants-table">
                    <thead>
                        <tr>
                            <th>Student Tenant</th>
                            <th>Property</th>
                            <th>Room</th>
                            <th>University / School</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $b):
                            $t = tenant_card_avatar($b);
                            $st = strtolower((string)$b['status']);
                        ?>
                            <tr>
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
                                            <div class="tenant-meta-name"><?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="tenant-meta-email"><?= htmlspecialchars((string)($b['personal_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars((string)$b['property_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string)($b['room_name'] ?? 'Room'), ENT_QUOTES, 'UTF-8') ?>
                                    <span style="display:block; font-size:0.78rem; color:var(--text-muted);"><?= htmlspecialchars((string)($b['room_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string)($b['school'] ?: $b['university']), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <span class="booking-status-tag <?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?></span>
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

<script>
window.UNISTAY_TENANT_PROFILE = <?= json_encode($landlordProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.CURRENT_LANDLORD = <?= json_encode($landlordProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
</body>
</html>
