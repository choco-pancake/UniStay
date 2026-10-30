<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

start_app_session();
$authenticatedUser = require_signed_in('landlord');

$user_id = (int)($_SESSION['user_id'] ?? 0);
$pdo = database();
$profileIncomplete = !landlord_profile_complete($pdo, $user_id);
$landlordProfile = get_authenticated_profile($pdo, $user_id);
require_once __DIR__ . '/../config/notifications.php';
$landlordNotifications = get_user_in_app_notifications($pdo, $user_id, 100);
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notifications · UniStay Landlord</title>
    <link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/common.css?v=32">
    <link rel="stylesheet" href="../assets/css/all-notifications.css?v=3">
    <script src="../assets/js/tenant/popover.js?v=2"></script>
    <script src="../assets/js/tenant/sidebar.js"></script>
    <script src="../assets/js/tenant/profile-menu.js?v=31"></script>
    <script>
      window.UNISTAY_TENANT_PROFILE = <?= json_encode($landlordProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
      window.CURRENT_LANDLORD = <?= json_encode($landlordProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    </script>
  </head>
  <body>
    <svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
      <defs>
        <symbol id="grid-icon" viewBox="0 0 24 24"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></symbol>
        <symbol id="bed-icon" viewBox="0 0 24 24"><path d="M3 19V9h18v10M3 16h18M5 9V5h5v4m4 0V5h5v4"/></symbol>
        <symbol id="door-icon" viewBox="0 0 24 24"><path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m-2 0h18M14 12v.01" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="badge-icon" viewBox="0 0 24 24"><path d="m12 2 3 3 4-1 1 4 3 3-3 3-1 4-4 1-3 3-3-3-4-1-1-4-3-3 3-3 1-4 4 1Z"/><path d="m8 12 3 3 5-6"/></symbol>
        <symbol id="bell-icon" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M10 20h4"/></symbol>
        <symbol id="bell-off-icon" viewBox="0 0 24 24"><path d="m3 3 18 18M10 20h4M6 6c-1 2 0 7-3 10h13M18 13V8a6 6 0 0 0-8-6"/></symbol>
        <symbol id="arrow-icon" viewBox="0 0 24 24"><path d="M4 12h16m-6-6 6 6-6 6"/></symbol>
        <symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
        <symbol id="user-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></symbol>
        <symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
        <symbol id="logout-icon" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
      </defs>
    </svg>

    <a class="skip" href="#notification-page">Skip to notifications</a>

    <!-- UniStay Sidebar -->
    <aside class="sidebar" aria-label="UniStay Landlord Portal">
      <a href="dashboard.php" class="brand" title="UniStay Home">
        <img src="../assets/images/tenant/logo.png" width="50" height="50" alt="UniStay logo">
        <span class="brand-name">UniStay</span>
      </a>
      <nav aria-label="Main navigation">
        <a href="dashboard.php" title="Dashboard"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
        <a href="properties.php" title="Rooms"><svg aria-hidden="true"><use href="#bed-icon"/></svg><span class="nav-label">Rooms</span></a>
        <a href="tenants.php" title="Requests"><svg aria-hidden="true"><use href="#user-icon"/></svg><span class="nav-label">Requests</span></a>
        <a href="settings.php" title="Settings"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
      </nav>
      <button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true">
        <span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span>
        <span class="toggle-label">Collapse</span>
      </button>
    </aside>

    <div class="shell">
      <header class="topbar">
        <h1>Notifications</h1>
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
            aria-label="Open profile menu"
            aria-expanded="false"
            aria-controls="profile-popover"
            <?= !empty($landlordProfile['photo']) ? 'style="background-image:url(\''.htmlspecialchars($landlordProfile['photo'], ENT_QUOTES, 'UTF-8').'\');"' : '' ?>
          >
            <?php if (!empty($landlordProfile['photo'])): ?>
              <img src="<?= htmlspecialchars($landlordProfile['photo']) ?>" alt="" referrerpolicy="no-referrer" class="avatar-img" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline-flex';">
              <span style="display:none;"><?= htmlspecialchars($landlordProfile['initials'] ?? 'L') ?></span>
            <?php else: ?>
              <?= htmlspecialchars($landlordProfile['initials'] ?? 'L') ?>
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
              <p>We’ll let you know when student bookings arrive.</p>
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

      <main id="notification-page" tabindex="-1">
        <section class="notification-hero" aria-labelledby="hero-title">
          <div>
            <span class="hero-kicker">Landlord Portal Updates</span>
            <h2 id="hero-title">
              Stay in the loop.<br><em>Manage bookings effortlessly.</em>
            </h2>
            <p>
              Receive live updates on student booking requests, tenant inquiries, room occupancy changes, and listings.
            </p>
          </div>
          <div class="hero-art" aria-hidden="true">
            <span class="orbit-label">LANDLORD UPDATES</span>
            <svg><use href="#bell-icon" /></svg>
            <span class="hero-spark">✦</span>
          </div>
        </section>

        <div class="notification-page-heading">
          <button id="read-all" type="button">
            <svg aria-hidden="true"><use href="#badge-icon" /></svg>Mark all as read
          </button>
          <button id="clear-all" type="button">Clear all</button>
        </div>

        <section class="full-inbox" aria-label="Notifications">
          <div class="empty" id="all-empty">
            <div class="empty-illustration" aria-hidden="true">
              <span class="letter"></span>
              <span class="letter"><svg><use href="#bell-icon" /></svg></span>
              <span class="empty-check">✓</span>
            </div>
            <strong id="empty-title">You’re all caught up.</strong>
            <p>
              New booking requests and tenant messages will appear here.<br>Everything is currently in order.
            </p>
          </div>
          <ul
            class="notification-list"
            id="all-list"
            aria-label="All notifications"
            hidden
          ></ul>
        </section>

        <div class="notification-return">
          <a id="back-to-page" class="back-dashboard" href="properties.php">
            <span>Back to Properties</span>
            <svg aria-hidden="true"><use href="#arrow-icon" /></svg>
          </a>
        </div>
        <p class="inbox-note">
          A dedicated inbox for the property and tenant updates that matter most.
        </p>
      </main>
    </div>

    <p id="feedback" class="notification-feedback" role="status"></p>
    <script id="notification-data" type="application/json">
      <?= json_encode($landlordNotifications, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
    </script>

  <script>
window.UNISTAY_NOTIFICATION_ACCOUNT = <?= json_encode(['id' => (int)($_SESSION['user_id'] ?? 0), 'role' => 'landlord']) ?>;
</script>
<script src="../assets/js/notification-preferences.js?v=2"></script>
<script src="../assets/js/landlord-notifications.js?v=6"></script>
</body>
</html>
