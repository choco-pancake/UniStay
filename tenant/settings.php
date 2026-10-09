<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#7a4b3a">
<title>Settings · UniStay</title>
<link rel="stylesheet" href="../assets/css/common.css?v=4">
<link rel="stylesheet" href="../assets/css/settings.css">
</head>
<body>
<svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs>
<symbol id="edit-icon" viewBox="0 0 24 24"><path d="m4 16 12-12 4 4L8 20l-5 1Zm10-10 4 4"/></symbol>
<symbol id="eye-icon" viewBox="0 0 24 24"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></symbol>
<symbol id="trash-icon" viewBox="0 0 24 24"><path d="M3 5h18M9 5V2h6v3M6 5v17h12V5M9 9v9m6-9v9"/></symbol>
<symbol id="logout-icon" viewBox="0 0 24 24"><path d="M10 3H4v18h6M9 12h12m-4-4 4 4-4 4"/></symbol>
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
<a class="skip" href="#settings">Skip to settings</a>
<aside class="sidebar" aria-label="UniStay portal">
<div class="brand"><img src="../assets/images/tenant/logo.png" width="50" height="50" alt=""><span>UniStay</span></div>
<nav aria-label="Main navigation">
<a href="dashboard.php"><svg aria-hidden="true"><use href="#grid-icon"/></svg>Dashboard</a>
<a href="map.php"><svg aria-hidden="true"><use href="#map-icon"/></svg>Map</a>
<a href="rooms.php"><svg aria-hidden="true"><use href="#booking-icon"/></svg>Bookings</a>
<a href="settings.php" aria-current="page"><svg aria-hidden="true"><use href="#settings-icon"/></svg>Settings</a>
</nav>
</aside>
<div class="shell">
<header class="topbar"><h1>Settings</h1><div class="profile">
<button id="notification-bell" class="bell" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="notification-popover"><svg aria-hidden="true"><use href="#bell-icon"/></svg></button>
<span class="avatar" role="img" aria-label="Profile placeholder"></span>
<section id="notification-popover" class="popover" aria-labelledby="popover-title" hidden>
<h2 id="popover-title">Notifications</h2>
<div class="empty" id="popover-empty"><span class="empty-icon"><svg aria-hidden="true"><use href="#bell-off-icon"/></svg></span><strong>No new notifications</strong><p>We’ll let you know when something arrives.</p></div>
<ul class="notification-list" id="popover-list" aria-label="Unread notifications" hidden></ul>
<a class="view-all" id="view-all" href="notifications.php?from=settings" aria-label="View all notifications">View all notifications <svg aria-hidden="true"><use href="#arrow-icon"/></svg></a>
</section>
</div></header>
<main id="settings" tabindex="-1">
<section class="settings-panel" aria-labelledby="settings-title">
<h2 id="settings-title">Account Settings and Security</h2>
<div class="account-row"><div class="profile-card"><span class="profile-avatar" aria-hidden="true"></span><div class="profile-info"><div class="profile-name" id="profile-name">Name</div><div class="profile-email" id="profile-email">Email Address</div></div><button class="edit-profile" id="edit-profile" type="button"><svg aria-hidden="true"><use href="#edit-icon"/></svg>Edit Profile Details</button></div>
<div class="push-card"><svg aria-hidden="true"><use href="#bell-icon"/></svg><span id="push-label">Push Notifications</span><button class="push-switch" id="push-switch" type="button" role="switch" aria-checked="true" aria-labelledby="push-label"></button></div></div>
<form id="password-form">
<div><label class="password-label" for="current-password">Current password <span class="required" aria-hidden="true">*</span></label><div class="password-field"><input id="current-password" type="password" placeholder="Description" autocomplete="current-password" required maxlength="128"><button class="password-toggle" type="button" data-password="current-password" aria-label="Show current password" aria-pressed="false"><svg aria-hidden="true"><use href="#eye-icon"/></svg></button></div></div>
<div class="forgot-row"><button class="text-button" id="forgot-password" type="button">Forgot Password?</button></div>
<div class="password-columns"><div><label class="password-label" for="new-password">New password <span class="required" aria-hidden="true">*</span></label><div class="password-field"><input id="new-password" type="password" placeholder="Description" autocomplete="new-password" required maxlength="128"><button class="password-toggle" type="button" data-password="new-password" aria-label="Show new password" aria-pressed="false"><svg aria-hidden="true"><use href="#eye-icon"/></svg></button></div></div><div><label class="password-label" for="confirm-password">Confirm password <span class="required" aria-hidden="true">*</span></label><div class="password-field"><input id="confirm-password" type="password" placeholder="Description" autocomplete="new-password" required maxlength="128"><button class="password-toggle" type="button" data-password="confirm-password" aria-label="Show confirm password" aria-pressed="false"><svg aria-hidden="true"><use href="#eye-icon"/></svg></button></div></div></div>
<div class="save-row"><button class="primary" type="submit">Save and Update</button></div>
</form>
<div class="danger-zone"><h2>Danger Zone</h2><p>Description Description</p><button id="delete-account" class="danger-button" type="button"><svg aria-hidden="true"><use href="#trash-icon"/></svg>Delete Account</button></div>
<div class="logout-row"><button id="logout" class="logout-button" type="button"><svg aria-hidden="true"><use href="#logout-icon"/></svg>Log Out</button></div>
<p class="settings-status" id="settings-status" role="status"></p>
</section>
</main>
</div>
<p id="feedback" class="notification-feedback" role="status"></p>
<!-- Supply tenant notifications here as JSON: [{"id":"unique-id","title":"Title","message":"Message"}]. -->
<dialog id="profile-dialog" aria-labelledby="profile-title"><div class="dialog-header"><h2 id="profile-title">Edit Profile Details</h2><button class="close" type="button" data-close="profile-dialog" aria-label="Close profile editor"><svg aria-hidden="true"><use href="#close-icon"/></svg></button></div><form id="profile-form" class="settings-dialog-content"><p>Profile changes are saved for this browser tab’s session.</p><label for="edit-name">Name</label><input id="edit-name" autocomplete="name" required maxlength="100"><label for="edit-email">Email address</label><input id="edit-email" type="email" autocomplete="email" required maxlength="254"><div class="dialog-actions"><button class="secondary" type="button" data-close="profile-dialog">Cancel</button><button class="primary" type="submit">Save profile</button></div></form></dialog>
<dialog id="account-dialog" aria-labelledby="account-title"><div class="dialog-header"><h2 id="account-title">Account action</h2><button class="close" type="button" data-close="account-dialog" aria-label="Close account action"><svg aria-hidden="true"><use href="#close-icon"/></svg></button></div><div class="settings-dialog-content"><p id="account-description"></p><div class="dialog-actions"><button class="secondary" type="button" data-close="account-dialog">Close</button></div></div></dialog>
<script id="notification-data" type="application/json">[]</script>
<script src="../assets/js/tenant/notifications.js"></script>
<script src="../assets/js/tenant/settings.js"></script>
</body>
</html>
