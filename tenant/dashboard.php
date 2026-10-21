<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/google_oauth.php';

try {
    $authenticatedUser = google_oauth_require_user('tenant');
} catch (RuntimeException $error) {
    http_response_code(401);
    $signInError = $error->getMessage();
}

// New tenants must finish the registration form before accessing the dashboard.
if (!isset($signInError)) {
    $pdo = database();
    $user_id = current_tenant_id();
    if (!tenant_profile_complete($pdo, $user_id)) {
        header('Location: complete-profile.php');
        exit;
    }
}

// --- Mock Data (Simulating a Backend/Database) ---

$user_data = [
    "name" => $authenticatedUser['name'] ?? 'Alex Johnson'
];

// Data for the four top cards
$dashboard_stats = [
    [
        "label" => "CURRENT ROOM",
        "main_value" => "Room Name",
        "sub_value" => "Building Name • Floor Number",
        "sub_value_2" => null,
        "icon" => "home"
    ],
    [
        "label" => "NEXT PAYMENT",
        "main_value" => "Date",
        "sub_value" => "Payment Status",
        "sub_value_2" => null,
        "icon" => "calendar"
    ],
    [
        "label" => "ACCOUNT STATUS",
        "main_value" => "Standing Status",
        "sub_value" => "Lease Validity Date",
        "sub_value_2" => null,
        "icon" => "settings"
    ]
];

$current_booking = [
    "status" => "active-stay",
    "status_label" => "Active Stay",
    "room_name" => "Room Name",
    "room_type" => "Room Type",
    "dorm_name" => "Dorm Name",
    "location" => "Location",
    "near_university" => "University Name",
    "duration" => "Stay duration",
    "monthly_payment" => "₱— / month",
    "landlord_name" => "Landlord Name"
];

?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#7a4b3a">
<title>Dashboard · UniStay</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/common.css?v=19">
<link rel="stylesheet" href="../assets/css/dashboardstyle.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/dashboardstyle.css'); ?>">
<style>
/* Same serif title treatment as the Map page (.map-page h1 in common.css) */
.topbar h1 { font-family: Georgia, "Times New Roman", serif; }
</style>
<script src="../assets/js/tenant/popover.js"></script>
<script src="../assets/js/tenant/sidebar.js"></script>
<script src="../assets/js/tenant/profile-menu.js"></script>
</head>
<body>
<svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs>
<symbol id="grid-icon" viewBox="0 0 24 24"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></symbol>
<symbol id="map-icon" viewBox="0 0 24 24"><path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2Zm6-2v16m6-14v16"/></symbol>
<symbol id="booking-icon" viewBox="0 0 24 24"><path d="m2 11 7-6 7 6v10h-5v-7H7v7H2ZM13 3h9v18h-3M16 7h3m0 4h-1"/></symbol>
<symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
<symbol id="home-icon" viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3Z"/></symbol>
<symbol id="money-icon" viewBox="0 0 24 24"><path d="M6 4h16v12H6ZM2 8v12h16"/><circle cx="14" cy="10" r="3"/></symbol>
<symbol id="calendar-icon" viewBox="0 0 24 24"><path d="M13 21H3V5h15v6M3 9h15M6 2v5m9-5v5"/><circle cx="18" cy="17" r="5"/><path d="M18 14v3l2 1"/></symbol>
<symbol id="bell-icon" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M10 20h4"/></symbol>
<symbol id="bell-off-icon" viewBox="0 0 24 24"><path d="m3 3 18 18M10 20h4M6 6c-1 2 0 7-3 10h13M18 13V8a6 6 0 0 0-8-6"/></symbol>
<symbol id="arrow-icon" viewBox="0 0 24 24"><path d="M4 12h16m-6-6 6 6-6 6"/></symbol>
<symbol id="close-icon" viewBox="0 0 24 24"><path d="m6 6 12 12M6 18 18 6"/></symbol>
<symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
<symbol id="location-icon" viewBox="0 0 24 24"><path d="M12 21s-6-5.686-6-10a6 6 0 1 1 12 0c0 4.314-6 10-6 10Z"/><circle cx="12" cy="11" r="2"/></symbol>
<symbol id="user-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></symbol>
</defs></svg>
<a class="skip" href="#dashboard-page">Skip to dashboard</a>
<aside class="sidebar" aria-label="UniStay portal">
<div class="brand"><img src="../assets/images/tenant/logo.png" width="50" height="50" alt=""><span class="brand-name">UniStay</span></div>
<nav aria-label="Main navigation">
<a href="dashboard.php" title="Dashboard" aria-current="page"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
<a href="map.php" title="Map"><svg aria-hidden="true"><use href="#map-icon"/></svg><span class="nav-label">Map</span></a>
<a href="rooms.php" title="Bookings"><svg aria-hidden="true"><use href="#booking-icon"/></svg><span class="nav-label">Bookings</span><span class="nav-badge" id="bookings-badge" hidden></span></a>
<a href="settings.php" title="Settings"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
</nav>
<button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true"><span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span><span class="toggle-label">Collapse</span></button>
</aside>
<div class="shell">
<header class="topbar"><h1>Dashboard</h1><div class="profile">
<button id="notification-bell" class="bell" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="notification-popover"><svg aria-hidden="true"><use href="#bell-icon"/></svg></button>
<button class="avatar" type="button" aria-label="Open profile menu" aria-expanded="false" aria-controls="profile-popover"></button>
<section id="notification-popover" class="popover" aria-labelledby="popover-title" hidden>
<h2 id="popover-title">Notifications</h2>
<div class="empty" id="popover-empty"><span class="empty-icon"><svg aria-hidden="true"><use href="#bell-off-icon"/></svg></span><strong>No new notifications</strong><p>We’ll let you know when something arrives.</p></div>
<ul class="notification-list" id="popover-list" aria-label="Unread notifications" hidden></ul>
<a class="view-all" id="view-all" href="notifications.php?from=dashboard" aria-label="View all notifications">View all notifications <svg aria-hidden="true"><use href="#arrow-icon"/></svg></a>
</section>
</div></header>
<main id="dashboard-page" tabindex="-1">
            <?php if (isset($signInError)): ?>
                <p role="alert"><?php echo htmlspecialchars($signInError, ENT_QUOTES, 'UTF-8'); ?> <a href="../auth/login.php">Return to sign in</a></p>
            <?php endif; ?>

            <!-- Status Cards and Current Booking -->
            <section class="dashboard-overview">
                <div class="stats-grid">
                    <?php foreach (array_slice($dashboard_stats, 0, 3) as $stat): ?>
                    <article class="stat-card">
                        <div class="stat-card-head">
                            <span class="stat-card-icon">
                                <svg aria-hidden="true"><use href="#<?php echo htmlspecialchars($stat['icon']); ?>-icon"/></svg>
                            </span>
                            <span class="card-label"><?php echo htmlspecialchars($stat['label']); ?></span>
                        </div>
                        <div class="card-middle">
                            <h3 class="card-value"><?php echo htmlspecialchars($stat['main_value']); ?></h3>
                            <?php if ($stat['label'] === 'MONTHLY RENT'): ?>
                                <span class="card-suffix">/month</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-bottom">
                            <p class="card-subtext">
                                <?php
                                echo htmlspecialchars($stat['sub_value_2'] ?? $stat['sub_value']);
                                ?>
                            </p>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>

                <section class="current-booking-card">
                    <header class="current-booking-header">
                        <h2 class="section-title">Current Booking</h2>
                    </header>

                    <article class="current-booking-body" data-status="<?php echo htmlspecialchars($current_booking['status']); ?>">
                        <div class="booking-hero">
                            <img src="https://images.unsplash.com/photo-1616486338812-3dadae4b4ace?auto=format&amp;fit=crop&amp;w=1200&amp;q=80" alt="Room interior">
                        </div>
                        <div class="booking-content">
                            <div class="booking-main">
                                <h3 class="booking-room-title"><?php echo htmlspecialchars($current_booking['room_name']); ?></h3>
                                <p class="booking-dorm-title"><?php echo htmlspecialchars($current_booking['dorm_name']); ?></p>
                                <div class="booking-meta-row">
                                    <span class="booking-meta-pill">
                                        <svg aria-hidden="true"><use href="#location-icon"/></svg>
                                        <?php echo htmlspecialchars($current_booking['location']); ?>
                                    </span>
                                    <span class="booking-meta-pill">
                                        <svg aria-hidden="true"><use href="#user-icon"/></svg>
                                        <?php echo htmlspecialchars($current_booking['near_university']); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="booking-details-grid">
                                <div class="booking-detail-item">
                                    <span class="booking-detail-label">Room type</span>
                                    <span class="booking-detail-value"><?php echo htmlspecialchars($current_booking['room_type']); ?></span>
                                </div>
                                <div class="booking-detail-item">
                                    <span class="booking-detail-label">Duration of stay</span>
                                    <span class="booking-detail-value"><?php echo htmlspecialchars($current_booking['duration']); ?></span>
                                </div>
                                <div class="booking-detail-item">
                                    <span class="booking-detail-label">Monthly payment</span>
                                    <span class="booking-detail-value"><?php echo htmlspecialchars($current_booking['monthly_payment']); ?></span>
                                </div>
                                <div class="booking-detail-item">
                                    <span class="booking-detail-label">Landlord</span>
                                    <span class="booking-detail-value"><?php echo htmlspecialchars($current_booking['landlord_name']); ?></span>
                                </div>
                            </div>

                            <div class="booking-actions-row">
                                <a class="booking-action-btn primary" href="rooms.php">View Booking</a>
                                <a class="booking-action-btn ghost" href="map.php">Browse Rooms</a>
                            </div>
                        </div>
                    </article>
                </section>
            </section>

</main>
</div>
<p id="feedback" class="notification-feedback" role="status"></p>
<!-- Supply tenant notifications here as JSON: [{"id":"unique-id","title":"Title","message":"Message"}]. -->
<script id="notification-data" type="application/json">[]</script>
<script src="../assets/js/tenant/notifications.js"></script>
</body>
</html>
