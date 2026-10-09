<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#7a4b3a">
<title>Dashboard · UniStay</title>
<link rel="stylesheet" href="../assets/css/common.css?v=16">
<link rel="stylesheet" href="../assets/css/notifications.css?v=2">
</head>
<body class="dashboard-page">
<svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs>
<symbol id="grid-icon" viewBox="0 0 24 24"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></symbol>
<symbol id="map-icon" viewBox="0 0 24 24"><path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2Zm6-2v16m6-14v16"/></symbol>
<symbol id="booking-icon" viewBox="0 0 24 24"><path d="m2 11 7-6 7 6v10h-5v-7H7v7H2ZM13 3h9v18h-3M16 7h3m0 4h-1"/></symbol>
<symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
<symbol id="bed-icon" viewBox="0 0 24 24"><path d="M3 19V9h18v10M3 16h18M5 9V5h5v4m4 0V5h5v4"/></symbol>
<symbol id="money-icon" viewBox="0 0 24 24"><path d="M6 4h16v12H6ZM2 8v12h16"/><circle cx="14" cy="10" r="3"/></symbol>
<symbol id="calendar-icon" viewBox="0 0 24 24"><path d="M13 21H3V5h15v6M3 9h15M6 2v5m9-5v5"/><circle cx="18" cy="17" r="5"/><path d="M18 14v3l2 1"/></symbol>
<symbol id="badge-icon" viewBox="0 0 24 24"><path d="m12 2 3 3 4-1 1 4 3 3-3 3-1 4-4 1-3 3-3-3-4-1-1-4-3-3 3-3 1-4 4 1Z"/><path d="m8 12 3 3 5-6"/></symbol>
<symbol id="bell-icon" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M10 20h4"/></symbol>
<symbol id="bell-off-icon" viewBox="0 0 24 24"><path d="m3 3 18 18M10 20h4M6 6c-1 2 0 7-3 10h13M18 13V8a6 6 0 0 0-8-6"/></symbol>
<symbol id="arrow-icon" viewBox="0 0 24 24"><path d="M4 12h16m-6-6 6 6-6 6"/></symbol>
<symbol id="close-icon" viewBox="0 0 24 24"><path d="m6 6 12 12M6 18 18 6"/></symbol>
</defs></svg>
<a class="skip" href="#dashboard">Skip to dashboard</a>
<aside class="sidebar" aria-label="UniStay portal">
<div class="brand"><img src="../assets/images/tenant/logo.png" width="50" height="50" alt=""><span>UniStay</span></div>
<nav aria-label="Main navigation">
<a href="dashboard.php" aria-current="page"><svg aria-hidden="true"><use href="#grid-icon"/></svg>Dashboard</a>
<a href="map.php"><svg aria-hidden="true"><use href="#map-icon"/></svg>Map</a>
<a href="rooms.php"><svg aria-hidden="true"><use href="#booking-icon"/></svg>Bookings</a>
<a href="settings.php"><svg aria-hidden="true"><use href="#settings-icon"/></svg>Settings</a>
</nav>
</aside>
<div class="shell">
<header class="topbar"><h1>Dashboard</h1><div class="profile">
<button id="notification-bell" class="bell" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="notification-popover"><svg aria-hidden="true"><use href="#bell-icon"/></svg></button>
<span class="avatar" role="img" aria-label="Profile placeholder"></span>
<section id="notification-popover" class="popover" aria-labelledby="popover-title" hidden>
<h2 id="popover-title">Notifications</h2>
<div class="empty" id="popover-empty"><span class="empty-icon"><svg aria-hidden="true"><use href="#bell-off-icon"/></svg></span><strong>No new notifications</strong><p>We’ll let you know when something arrives.</p></div>
<ul class="notification-list" id="popover-list" aria-label="Unread notifications" hidden></ul>
<a class="view-all" id="view-all" href="notifications.php?from=dashboard" aria-label="View all notifications">View all notifications <svg aria-hidden="true"><use href="#arrow-icon"/></svg></a>
</section>
</div></header>
<main id="dashboard" tabindex="-1">
<section class="summary" aria-label="Tenant overview">
<div class="card" id="current-room"><svg aria-hidden="true"><use href="#bed-icon"/></svg><div class="label">Current Room</div><div class="value">Room Name</div><div class="detail">Building Name • Floor Number</div></div>
<div class="card"><svg aria-hidden="true"><use href="#money-icon"/></svg><div class="label">Monthly Rent</div><div class="value">$PRICE<small>/month</small></div><div class="detail">Inclusions</div></div>
<div class="card"><svg aria-hidden="true"><use href="#calendar-icon"/></svg><div class="label">Next Payment</div><div class="value">Date</div><div class="detail">Payment Status</div></div>
<div class="card"><svg aria-hidden="true"><use href="#badge-icon"/></svg><div class="label">Account Status</div><div class="value">Standing Status</div><div class="detail">Lease Validity Date</div></div>
</section>
<div class="dashboard-grid">
<section id="map" aria-labelledby="map-title"><h2 id="map-title">Map</h2><a href="map.php" class="placeholder" aria-label="Open the UniStay map"></a></section>
<section aria-labelledby="activity-title"><h2 id="activity-title">Recent Activity</h2><div class="placeholder" role="img" aria-label="Recent activity placeholder"></div></section>
</div>
<section class="dormitories" aria-labelledby="dormitories-title"><h2 id="dormitories-title">Dormitories</h2></section>
<noscript>Enable JavaScript to open notifications.</noscript>
</main>
</div>
<p id="feedback" class="notification-feedback" role="status"></p>
<!-- Supply tenant notifications here as JSON: [{"id":"unique-id","title":"Title","message":"Message"}]. -->
<script id="notification-data" type="application/json">[]</script>
<script src="../assets/js/tenant/notifications.js"></script>
</body>
</html>
