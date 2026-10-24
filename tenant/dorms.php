<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

start_app_session();
if (isset($_GET['visitor']) || isset($_GET['guest'])) {
    unset($_SESSION['user_id'], $_SESSION['user'], $_SESSION['role']);
}
$user_id = $_SESSION['user_id'] ?? null;
$isAuthenticated = is_numeric($user_id);
$profileCompleted = false;
$showOnboardingToast = false;
$tenantProfile = null;

if ($isAuthenticated) {
    $pdo = database();
    $profileCompleted = tenant_profile_complete($pdo, (int)$user_id);
    $tenantProfile = get_authenticated_profile($pdo, (int)$user_id);
    $tenantVerification = get_tenant_verification($pdo, (int)$user_id);
    require_once __DIR__ . '/../config/notifications.php';
    $tenantNotifications = get_user_in_app_notifications($pdo, (int)$user_id, 50);
    $isNewTenantRegistration = false;
    if (!empty($_SESSION['is_new_tenant_registration']) || !empty($_SESSION['show_profile_completion_toast'])) {
        $isNewTenantRegistration = true;
        unset($_SESSION['is_new_tenant_registration'], $_SESSION['show_profile_completion_toast']);
    }
    if ($isNewTenantRegistration || !$profileCompleted) {
        $showOnboardingToast = true;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dorms · UniStay</title>
<link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/common.css?v=25">
<link rel="stylesheet" href="../assets/css/dorms.css?v=25">
<script src="../assets/js/tenant/popover.js"></script>
<script src="../assets/js/tenant/sidebar.js"></script>
<?php if ($isAuthenticated): ?>
<script src="../assets/js/tenant/profile-menu.js"></script>
<?php endif; ?>
</head>
<body class="dorms-page">
<svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs>
<symbol id="grid-icon" viewBox="0 0 24 24"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></symbol>
<symbol id="map-icon" viewBox="0 0 24 24"><path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2Zm6-2v16m6-14v16"/></symbol>
<symbol id="booking-icon" viewBox="0 0 24 24"><path d="m2 11 7-6 7 6v10h-5v-7H7v7H2ZM13 3h9v18h-3M16 7h3m0 4h-1"/></symbol>
<symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
<symbol id="bed-icon" viewBox="0 0 24 24"><path d="M3 19V9h18v10M3 16h18M5 9V5h5v4m4 0V5h5v4"/></symbol>
<symbol id="bell-icon" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M10 20h4"/></symbol>
<symbol id="bell-off-icon" viewBox="0 0 24 24"><path d="m3 3 18 18M10 20h4M6 6c-1 2 0 7-3 10h13M18 13V8a6 6 0 0 0-8-6"/></symbol>
<symbol id="arrow-icon" viewBox="0 0 24 24"><path d="M4 12h16m-6-6 6 6-6 6"/></symbol>
<symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
<symbol id="search-icon" viewBox="0 0 24 24"><circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/></symbol>
<symbol id="caret-icon" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></symbol>
<symbol id="pin-icon" viewBox="0 0 24 24"><path d="M19 9c0 5-7 12-7 12S5 14 5 9a7 7 0 0 1 14 0Z"/><circle cx="12" cy="9" r="2"/></symbol>
<symbol id="school-icon" viewBox="0 0 24 24"><path d="m2 9 10-5 10 5-10 5Z"/><path d="M6 11v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5M22 9v6"/></symbol>
<symbol id="people-icon" viewBox="0 0 24 24"><circle cx="9" cy="6" r="3"/><path d="M3 21v-4a6 6 0 0 1 12 0v4M16 3a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 4v4"/></symbol>
<symbol id="measure-icon" viewBox="0 0 24 24"><path d="M4 20 20 4"/><path d="M13 4h7v7M11 20H4v-7"/></symbol>
<symbol id="lock-icon" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></symbol>
<symbol id="logout-icon" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
</defs></svg>

<a class="skip" href="#dorms-main">Skip to dorms</a>

<aside class="sidebar" aria-label="UniStay portal">
  <div class="brand">
    <img src="../assets/images/tenant/logo.png" width="50" height="50" alt="">
    <span class="brand-name">UniStay</span>
  </div>
  <nav aria-label="Main navigation">
    <?php if ($isAuthenticated): ?>
      <a href="dashboard.php" title="Dashboard"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
    <?php endif; ?>
    <a href="dorms.php" title="Dorms" aria-current="page"><svg aria-hidden="true"><use href="#bed-icon"/></svg><span class="nav-label">Dorms</span></a>
    <a href="map.php" title="Map"><svg aria-hidden="true"><use href="#map-icon"/></svg><span class="nav-label">Map</span></a>
    <?php if ($isAuthenticated): ?>
      <a href="settings.php" title="Settings"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
    <?php endif; ?>
  </nav>
  <button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true">
    <span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span>
    <span class="toggle-label">Collapse</span>
  </button>
</aside>

<div class="shell">
  <div class="shell-orbs" aria-hidden="true"><i></i><i></i><i></i></div>
  <header class="topbar">
    <h1>Dorms</h1>
    <div class="profile">
      <button id="guide-launcher-btn" class="btn-topbar-guide" type="button" title="View User Guide" aria-label="Open User Guide">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
        <span>Guide</span>
      </button>
      <?php if ($isAuthenticated): ?>
        <button id="notification-bell" class="bell" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="notification-popover">
          <svg aria-hidden="true"><use href="#bell-icon"/></svg>
        </button>
        <button class="avatar" type="button" aria-label="Open profile menu" aria-expanded="false" aria-controls="profile-popover" <?= !empty($tenantProfile['photo']) ? 'style="background-image:url(\''.htmlspecialchars($tenantProfile['photo'], ENT_QUOTES, 'UTF-8').'\');"' : '' ?>>
          <?php if (!empty($tenantProfile['photo'])): ?>
            <img src="<?= htmlspecialchars($tenantProfile['photo']) ?>" alt="" referrerpolicy="no-referrer" class="avatar-img" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline-flex';">
            <span style="display:none;"><?= htmlspecialchars($tenantProfile['initials'] ?? 'U') ?></span>
          <?php else: ?>
            <?= htmlspecialchars($tenantProfile['initials'] ?? 'U') ?>
          <?php endif; ?>
        </button>
        <section id="notification-popover" class="popover" aria-labelledby="popover-title" hidden>
          <h2 id="popover-title">Notifications</h2>
          <div class="empty" id="popover-empty"><span class="empty-icon"><svg aria-hidden="true"><use href="#bell-off-icon"/></svg></span><strong>No new notifications</strong><p>We’ll let you know when something arrives.</p></div>
          <ul class="notification-list" id="popover-list" aria-label="Unread notifications" hidden></ul>
          <a class="view-all" id="view-all" href="notifications.php?from=dorms" aria-label="View all notifications">View all notifications <svg aria-hidden="true"><use href="#arrow-icon"/></svg></a>
        </section>
      <?php else: ?>
        <div class="topbar-auth">
          <a href="../auth/login.php?redirect=tenant/dorms.php" class="btn-topbar-login">Log in</a>
          <a href="../auth/register.php?redirect=tenant/dorms.php" class="btn-topbar-signup">Sign up</a>
        </div>
      <?php endif; ?>
    </div>
  </header>

  <main id="dorms-main" tabindex="-1">
    <div class="um-toolbar">
      <div class="um-toolbar-left">
        <label class="um-search">
          <svg aria-hidden="true"><use href="#search-icon"/></svg>
          <input id="dorm-search" type="search" placeholder="Search dorms..." autocomplete="off">
        </label>
        <div class="um-select" id="uni-select">
          <button type="button" class="um-select-btn is-placeholder" aria-haspopup="listbox" aria-expanded="false">
            <span class="um-select-value">Search by university</span>
            <svg class="um-select-caret" aria-hidden="true"><use href="#caret-icon"/></svg>
          </button>
          <ul class="um-select-menu" role="listbox" aria-label="Filter by university" hidden>
            <li role="option" data-value="" aria-selected="true">All universities</li>
            <li role="option" data-value="Universidad de Dagupan" aria-selected="false">Universidad de Dagupan</li>
            <li role="option" data-value="University of Pangasinan" aria-selected="false">University of Pangasinan</li>
            <li role="option" data-value="University of Luzon" aria-selected="false">University of Luzon</li>
            <li role="option" data-value="Lyceum Northwestern University" aria-selected="false">Lyceum Northwestern University</li>
          </ul>
        </div>
      </div>
      <div>
        <a href="map.php" class="map-link-btn" title="View dorm locations on the interactive map">
          <svg aria-hidden="true"><use href="#map-icon"/></svg> View on Map
        </a>
      </div>
    </div>

    <!-- Stay Status Filter Tabs -->
    <div class="dorms-stay-filters" role="tablist" aria-label="Filter by stay status">
      <button type="button" class="stay-filter-btn is-active" data-stay="all" role="tab" aria-selected="true">
        <span>All Dorms</span>
        <span class="stay-filter-count" id="count-all">0</span>
      </button>
      <button type="button" class="stay-filter-btn" data-stay="active" role="tab" aria-selected="false">
        <span class="stay-dot dot-active" aria-hidden="true"></span>
        <span>Active Stay</span>
        <span class="stay-filter-count" id="count-active">0</span>
      </button>
      <button type="button" class="stay-filter-btn" data-stay="upcoming" role="tab" aria-selected="false">
        <span class="stay-dot dot-upcoming" aria-hidden="true"></span>
        <span>Upcoming Stay</span>
        <span class="stay-filter-count" id="count-upcoming">0</span>
      </button>
      <button type="button" class="stay-filter-btn" data-stay="rejected" role="tab" aria-selected="false">
        <span class="stay-dot dot-rejected" aria-hidden="true"></span>
        <span>Rejected Stay</span>
        <span class="stay-filter-count" id="count-rejected">0</span>
      </button>
    </div>

    <!-- Dormitory Cards Gallery Grid -->
    <div class="dorms-grid" id="dorms-grid" aria-live="polite">
      <!-- Injected by JavaScript -->
    </div>
  </main>
</div>

<!-- Enlarged Dorm Inspection Modal -->
<dialog id="dorm-modal" class="dorm-modal" aria-labelledby="dorm-modal-title">
  <div class="dorm-modal-header">
    <h2 id="dorm-modal-title">Dorm details</h2>
    <button type="button" class="dorm-modal-close" id="dorm-modal-close-btn" aria-label="Close dialog">✕</button>
  </div>
  <div class="dorm-modal-body" id="dorm-modal-content">
    <!-- Populated by JavaScript -->
  </div>
</dialog>

<!-- Room Details Card Modal (Extension of Dorm Card) -->
<dialog id="room-details-modal" class="dorm-modal room-details-modal" aria-labelledby="room-details-title">
  <div class="dorm-modal-header room-modal-header">
    <button type="button" class="btn-back-to-dorm-header" id="room-header-back-btn" aria-label="Back to dorm" title="Back to dorm">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
    </button>
    <h2 id="room-details-title" class="room-modal-header-title">Room details</h2>
    <button type="button" class="dorm-modal-close" id="room-details-close-btn" aria-label="Close dialog">✕</button>
  </div>
  <div class="dorm-modal-body room-modal-body" id="room-details-content">
    <!-- Populated by JavaScript -->
  </div>
</dialog>

<!-- Interactive User Guide Slideshow Modal (7-Step Tenant Journey) -->
<dialog id="user-guide-modal" class="guide-modal" aria-labelledby="guide-title">
  <div class="guide-modal-card">
    <div class="guide-header">
      <span class="guide-step-pill" id="guide-step-indicator">Step 1 of 7</span>
      <button type="button" class="guide-close-btn" id="guide-skip-btn" aria-label="Skip Guide">Skip Guide ✕</button>
    </div>

    <div class="guide-slides-wrapper">
      <!-- Slide 1 -->
      <div class="guide-slide active" data-step="1">
        <div class="guide-icon-banner">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
        </div>
        <h3 id="guide-title">1. Complete Registration &amp; Verification</h3>
        <p>Start by filling up your tenant details in Settings. Upload valid photos of your School ID and Government ID, and link your active Facebook profile so administrators can verify your account.</p>
        <div class="guide-highlights">
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Genuine School ID &amp; Government ID photo check</span>
          </div>
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Active Facebook link required for tenant credibility</span>
          </div>
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Quick admin review unlocks full room details and booking</span>
          </div>
        </div>
      </div>

      <!-- Slide 2 -->
      <div class="guide-slide" data-step="2">
        <div class="guide-icon-banner">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        </div>
        <h3>2. Look for a Dorm You Want to Live In</h3>
        <p>Explore verified student residences across Dagupan City. Filter by your university campus (UdD, UPang, UL, Lyceum) and inspect campus proximity and resident statistics.</p>
        <div class="guide-highlights">
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Instant search by dorm name and university filter</span>
          </div>
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Precise walking distance calculations from campus</span>
          </div>
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Real resident student community matching insights</span>
          </div>
        </div>
      </div>

      <!-- Slide 3 -->
      <div class="guide-slide" data-step="3">
        <div class="guide-icon-banner">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 19V9h18v10M3 16h18M5 9V5h5v4m4 0V5h5v4"/></svg>
        </div>
        <h3>3. Look for a Room That Caters to Your Needs</h3>
        <p>Click any dorm to view its room list. Compare room types (Single, Twin, Quad, Quintuple), view uploaded room photos, examine security deposits, and review amenities.</p>
        <div class="guide-highlights">
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Transparent monthly rates and security deposit breakdown</span>
          </div>
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Photo galleries with high-resolution room previews</span>
          </div>
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Back icon button on room card lets you easily return to dorm</span>
          </div>
        </div>
      </div>

      <!-- Slide 4 -->
      <div class="guide-slide" data-step="4">
        <div class="guide-icon-banner">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
        </div>
        <h3>4. Book the Room</h3>
        <p>Found the right room? Click "Submit Booking Request" at the bottom of the room card to send your reservation request directly to the landlord for their review.</p>
        <div class="guide-highlights">
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Instant booking dispatch with no extra reservation fees</span>
          </div>
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Confirmation in-app notification appears in your inbox</span>
          </div>
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Email notification sent to both you and the landlord</span>
          </div>
        </div>
      </div>

      <!-- Slide 5 -->
      <div class="guide-slide" data-step="5">
        <div class="guide-icon-banner">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        </div>
        <h3>5. Wait for Landlord Approval</h3>
        <p>The landlord reviews your student credentials in their portal and makes a decision. You will receive an in-app notification and email the instant they approve or decline.</p>
        <div class="guide-highlights">
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Real-time in-app notification when the landlord responds</span>
          </div>
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Track request status right on your dashboard &amp; stay tabs</span>
          </div>
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Approved bookings transition automatically into Active Stays</span>
          </div>
        </div>
      </div>

      <!-- Slide 6 -->
      <div class="guide-slide" data-step="6">
        <div class="guide-icon-banner" style="background: linear-gradient(135deg, #e7f2ff 0%, #d4e7ff 100%); color: #0084ff;">
          <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.477 2 2 6.145 2 11.258c0 2.91 1.455 5.518 3.736 7.207V22l3.418-1.876c.9.25 1.86.386 2.846.386 5.523 0 10-4.145 10-9.252C22 6.145 17.523 2 12 2Zm1.085 12.355-2.613-2.788-5.1 2.788 5.61-5.955 2.68 2.788 5.033-2.788-5.61 5.955Z"/></svg>
        </div>
        <h3>6. Communicate via Facebook Messenger</h3>
        <p>Coordinate move-in dates, schedule room viewings, or clarify house rules directly. Every landlord card and modal includes a 1-click "Chat on Facebook Messenger" button.</p>
        <div class="guide-highlights">
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Direct Messenger chat link opens conversation immediately</span>
          </div>
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Check verified landlord Facebook profile and contact details</span>
          </div>
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Direct conversation with zero hidden broker fees</span>
          </div>
        </div>
      </div>

      <!-- Slide 7 -->
      <div class="guide-slide" data-step="7">
        <div class="guide-icon-banner" style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #b45309;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
        </div>
        <h3>7. Live, Study, and Thrive!</h3>
        <p>Welcome to your college home! Move into your dorm with peace of mind, stay connected with university neighbors, and focus on reaching your academic dreams.</p>
        <div class="guide-highlights">
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Safe, verified community with fellow college students</span>
          </div>
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Convenient walking distance to campus classes and study spots</span>
          </div>
          <div class="guide-highlight-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span>Manage active stay details and payments from your portal</span>
          </div>
        </div>
      </div>
    </div>

    <div class="guide-footer">
      <div class="guide-dots" id="guide-dots" aria-label="Slide indicators">
        <button type="button" class="guide-dot active" data-goto="1" aria-label="Go to slide 1"></button>
        <button type="button" class="guide-dot" data-goto="2" aria-label="Go to slide 2"></button>
        <button type="button" class="guide-dot" data-goto="3" aria-label="Go to slide 3"></button>
        <button type="button" class="guide-dot" data-goto="4" aria-label="Go to slide 4"></button>
        <button type="button" class="guide-dot" data-goto="5" aria-label="Go to slide 5"></button>
        <button type="button" class="guide-dot" data-goto="6" aria-label="Go to slide 6"></button>
        <button type="button" class="guide-dot" data-goto="7" aria-label="Go to slide 7"></button>
      </div>

      <div class="guide-buttons">
        <button type="button" class="btn-guide-prev" id="guide-prev-btn" style="display:none;">Back</button>
        <button type="button" class="btn-guide-next" id="guide-next-btn">
          Next &rarr;
        </button>
      </div>
    </div>
  </div>
</dialog>

<!-- Landlord Profile Modal -->
<dialog id="landlord-profile-modal" class="landlord-profile-modal" aria-labelledby="landlord-modal-name">
  <div class="landlord-profile-card">
    <div class="landlord-profile-banner">
      <button type="button" class="landlord-profile-close-btn" id="landlord-profile-close-btn" aria-label="Close landlord profile">✕</button>
    </div>
    <div class="landlord-profile-header">
      <div class="landlord-avatar-wrap">
        <div id="landlord-avatar-container"></div>
        <span class="landlord-verified-badge" title="Verified Landlord">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </span>
      </div>
      <h2 id="landlord-modal-name" class="landlord-profile-name">Landlord Name</h2>
      <div id="landlord-modal-context" class="landlord-profile-context"></div>
      <div class="landlord-profile-meta">
        <span>Verified Landlord</span>
        <span class="meta-dot"></span>
        <span id="landlord-modal-since">Member since 2024</span>
      </div>
      <div id="landlord-messenger-container" style="width:100%; display:flex; flex-direction:column; align-items:center;"></div>
    </div>

    <div class="landlord-contact-section">
      <div class="landlord-contact-card" id="landlord-phone-card">
        <div class="landlord-contact-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
        </div>
        <div class="landlord-contact-info">
          <span class="landlord-contact-label">Phone Number</span>
          <span id="landlord-modal-phone" class="landlord-contact-val">Not provided</span>
        </div>
      </div>

      <div class="landlord-contact-card" id="landlord-email-card">
        <div class="landlord-contact-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
        </div>
        <div class="landlord-contact-info">
          <span class="landlord-contact-label">Email Address</span>
          <span id="landlord-modal-email" class="landlord-contact-val">Not provided</span>
        </div>
      </div>
    </div>

    <div class="landlord-props-section" id="landlord-props-section">
      <div class="landlord-props-title">Dormitories Managed</div>
      <div class="landlord-props-list" id="landlord-props-list"></div>
    </div>
  </div>
</dialog>

<!-- Centered Post-Registration Onboarding Toast Modal -->
<?php if ($showOnboardingToast): ?>
<dialog id="onboarding-toast-modal" class="onboarding-toast-modal" aria-labelledby="onboarding-toast-title">
  <div class="onboarding-toast-card">
    <div class="onboarding-toast-icon">
      <svg aria-hidden="true"><use href="#bed-icon"/></svg>
    </div>
    <h2 id="onboarding-toast-title">Welcome to UniStay!</h2>
    <p>Would you like to complete all necessary registration details now to unlock room details and booking requests, or explore dorms first?</p>
    <div class="onboarding-toast-actions">
      <a href="settings.php?edit=1" id="btn-toast-now" class="btn-toast-primary">
        Fill information now
        <svg style="width:16px;height:16px;" aria-hidden="true"><use href="#arrow-icon"/></svg>
      </a>
      <button type="button" id="btn-toast-later" class="btn-toast-secondary">
        I'll do this later
      </button>
    </div>
  </div>
</dialog>
<?php endif; ?>

<!-- Auth / Profile Gate Modal -->
<dialog id="auth-gate-modal" class="auth-gate-modal" aria-labelledby="auth-gate-title">
  <div class="auth-gate-card">
    <button type="button" class="auth-gate-close" id="auth-gate-close-btn" aria-label="Close">✕</button>
    <div class="auth-gate-icon">
      <svg aria-hidden="true"><use href="#bed-icon"/></svg>
    </div>
    <h2 id="auth-gate-title">Log in to view rooms</h2>
    <p id="auth-gate-desc">Create an account or log in to view complete room photos, check availability, and send booking inquiries to landlords.</p>
    <div class="auth-gate-actions" id="auth-gate-actions">
      <a href="../auth/login.php?redirect=tenant/dorms.php" class="btn-auth-primary">Log in</a>
      <a href="../auth/register.php?redirect=tenant/dorms.php" class="btn-auth-secondary">Sign up</a>
    </div>
  </div>
</dialog>

<script src="../assets/js/store.js"></script>
<?php if ($isAuthenticated): ?>
<script id="notification-data" type="application/json"><?= json_encode($tenantNotifications ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<script src="../assets/js/tenant/notifications.js"></script>
<?php endif; ?>

<script>
<?php if ($tenantProfile): ?>
window.UNISTAY_TENANT_PROFILE = <?= json_encode($tenantProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
<?php endif; ?>
(() => {
  const IS_AUTHENTICATED = <?= $isAuthenticated ? 'true' : 'false' ?>;
  const PROFILE_COMPLETED = <?= $profileCompleted ? 'true' : 'false' ?>;
  const CAN_VIEW_FULL_ROOMS = <?= ($isAuthenticated && $profileCompleted) ? 'true' : 'false' ?>;
  const TENANT_VERIFICATION_STATUS = <?= json_encode($tenantVerification['status'] ?? 'unverified') ?>;
  const SHOW_ONBOARDING_TOAST = <?= $showOnboardingToast ? 'true' : 'false' ?>;
  const IS_NEW_TENANT_REGISTRATION = <?= (!empty($isNewTenantRegistration)) ? 'true' : 'false' ?>;
  const grid = document.getElementById('dorms-grid');
  const searchInput = document.getElementById('dorm-search');
  const uniSelect = document.getElementById('uni-select');
  const uniBtn = uniSelect.querySelector('.um-select-btn');
  const uniValue = uniSelect.querySelector('.um-select-value');
  const uniMenu = uniSelect.querySelector('.um-select-menu');
  const dormModal = document.getElementById('dorm-modal');
  const dormModalCloseBtn = document.getElementById('dorm-modal-close-btn');
  const dormModalContent = document.getElementById('dorm-modal-content');
  const roomDetailsModal = document.getElementById('room-details-modal');
  const roomDetailsCloseBtn = document.getElementById('room-details-close-btn');
  const authGateModal = document.getElementById('auth-gate-modal');
  const authGateCloseBtn = document.getElementById('auth-gate-close-btn');
  const onboardingToastModal = document.getElementById('onboarding-toast-modal');
  const btnToastLater = document.getElementById('btn-toast-later');
  const userGuideModal = document.getElementById('user-guide-modal');
  const guideStepIndicator = document.getElementById('guide-step-indicator');
  const guideSkipBtn = document.getElementById('guide-skip-btn');
  const guidePrevBtn = document.getElementById('guide-prev-btn');
  const guideNextBtn = document.getElementById('guide-next-btn');
  const guideDots = document.getElementById('guide-dots');
  const guideLauncherBtn = document.getElementById('guide-launcher-btn');
  const landlordProfileModal = document.getElementById('landlord-profile-modal');
  const landlordProfileCloseBtn = document.getElementById('landlord-profile-close-btn');

  let allProperties = [];
  let currentFilter = '';
  let currentSearch = '';
  let currentStayFilter = 'all';

  const UNIVERSITIES = [
    { name: 'Universidad de Dagupan',         lat: 16.0507, lng: 120.3408 },
    { name: 'University of Pangasinan',       lat: 16.0471, lng: 120.3425 },
    { name: 'University of Luzon',            lat: 16.0398, lng: 120.3359 },
    { name: 'Lyceum Northwestern University', lat: 16.0354, lng: 120.3305 },
  ];

  function toRad(d) { return d * Math.PI / 180; }
  function havKm(a, b) {
    const R = 6371;
    const dLat = toRad(b.lat - a.lat), dLng = toRad(b.lng - a.lng);
    const s = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return R * 2 * Math.asin(Math.sqrt(s));
  }

  function nearestUniversity(lat, lng) {
    let best = null, minKm = Infinity;
    for (const u of UNIVERSITIES) {
      const km = havKm({ lat, lng }, { lat: u.lat, lng: u.lng });
      if (km < minKm) { minKm = km; best = u; if (minKm === 0) break; }
    }
    if (!best) return { name: UNIVERSITIES[0].name, km: 0 };
    return { name: best.name, km: Math.round(minKm * 10) / 10 };
  }

  function landlordInitials(name) {
    return String(name || '').trim().split(/\s+/).filter(Boolean).slice(0, 2)
      .map(w => w[0]).join('').toUpperCase() || '?';
  }

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function fmtPrice(val) {
    const num = Number(val || 0);
    return '₱' + num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  // Helper to extract sanitized room photo URLs
  function getRoomPhotos(r) {
    if (!r) return [];
    let list = [];
    if (Array.isArray(r.photos) && r.photos.length) {
      list = r.photos;
    } else if (typeof r.photo === 'string' && r.photo.trim() !== '' && r.photo !== '[]') {
      try {
        const parsed = JSON.parse(r.photo);
        list = Array.isArray(parsed) ? parsed : [r.photo];
      } catch (e) {
        list = [r.photo];
      }
    }
    return list.map(p => {
      if (!p || typeof p !== 'string') return '';
      if (p.startsWith('http://') || p.startsWith('https://') || p.startsWith('data:') || p.startsWith('../')) return p;
      return '../' + p.replace(/^\/+/, '');
    }).filter(Boolean);
  }

  // Render cards containing:
  // photo, dorm name, location, near which university, landlord name, view details button, stay badge
  function renderCards(list) {
    if (!list.length) {
      let emptyTitle = 'No dormitories found';
      let emptyDesc = 'Try searching for a different dorm name or picking another university.';
      if (currentStayFilter === 'active') {
        emptyTitle = 'No active stays';
        emptyDesc = IS_AUTHENTICATED
          ? 'You do not have any active dorm stays at the moment. Browse dorms to book a room!'
          : 'Log in to view your active stays.';
      } else if (currentStayFilter === 'upcoming') {
        emptyTitle = 'No upcoming stays';
        emptyDesc = IS_AUTHENTICATED
          ? 'You do not have any upcoming stay reservations.'
          : 'Log in to view your upcoming reservations.';
      } else if (currentStayFilter === 'rejected') {
        emptyTitle = 'No rejected stays';
        emptyDesc = 'You have no rejected bookings or inquiries.';
      }
      grid.innerHTML = `
        <div class="dorms-empty">
          <div class="dorms-empty-icon"><svg><use href="#bed-icon"/></svg></div>
          <h3>${esc(emptyTitle)}</h3>
          <p>${esc(emptyDesc)}</p>
          ${(!IS_AUTHENTICATED && currentStayFilter !== 'all') ? '<button type="button" class="btn-auth-primary" style="margin-top:14px;padding:8px 18px;border-radius:10px;font-size:0.9rem;" id="btn-empty-login">Log in to view your stays</button>' : ''}
        </div>
      `;
      const emptyLogin = grid.querySelector('#btn-empty-login');
      if (emptyLogin) {
        emptyLogin.addEventListener('click', () => openGateModal());
      }
      return;
    }

    grid.innerHTML = list.map((p, idx) => {
      const photos = p.photos && p.photos.length ? p.photos : (p.photo ? [p.photo] : ['../uploads/properties/st_albert_dorm.jpg']);
      const coverPhoto = photos[0];
      const initials = landlordInitials(p.landlordName);

      let stayBadgeHtml = '';
      if (p.userStayStatus === 'active') {
        stayBadgeHtml = '<span class="dorm-card-stay-badge stay-active">● Active Stay</span>';
      } else if (p.userStayStatus === 'upcoming') {
        stayBadgeHtml = '<span class="dorm-card-stay-badge stay-upcoming">● Upcoming Stay</span>';
      } else if (p.userStayStatus === 'rejected') {
        stayBadgeHtml = '<span class="dorm-card-stay-badge stay-rejected">● Stay Rejected</span>';
      }

      return `
        <article class="dorm-card" data-id="${p.id}" tabindex="0" role="button" aria-label="View ${esc(p.name)}">
          <div class="dorm-card-photo-wrap">
            <img class="dorm-card-photo" src="${esc(coverPhoto)}" alt="${esc(p.name)}" loading="lazy">
            <span class="dorm-card-uni-badge">
              <svg><use href="#school-icon"/></svg>
              ${esc(p.university)}
            </span>
            ${stayBadgeHtml}
          </div>
          <div class="dorm-card-body">
            <h3 class="dorm-card-title">${esc(p.name)}</h3>
            <div class="dorm-card-location">
              <svg><use href="#pin-icon"/></svg>
              <span>${esc(p.address)}</span>
            </div>
            <div class="dorm-card-uni">
              <svg><use href="#school-icon"/></svg>
              <span>Near ${esc(p.university)}</span>
            </div>
            <div class="dorm-card-landlord" data-landlord-prop="${p.id}" role="button" tabindex="0" title="Click to view landlord profile &amp; chat">
              <span class="landlord-avatar-small" aria-hidden="true">${esc(initials)}</span>
              <span>Landlord: <strong>${esc(p.landlordName || 'Verified Landlord')}</strong></span>
              <span class="dorm-card-landlord-chat-hint">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.145 2 11.258c0 2.91 1.455 5.518 3.736 7.207V22l3.418-1.876c.9.25 1.86.386 2.846.386 5.523 0 10-4.145 10-9.252C22 6.145 17.523 2 12 2Z"/></svg>
                Chat
              </span>
            </div>
            <div class="dorm-card-actions">
              <button type="button" class="dorm-view-details-btn" data-id="${p.id}">
                View details <svg><use href="#arrow-icon"/></svg>
              </button>
            </div>
          </div>
        </article>
      `;
    }).join('');

    // Attach click events
    grid.querySelectorAll('.dorm-card').forEach(card => {
      card.addEventListener('click', (e) => {
        const id = Number(card.dataset.id);
        const prop = allProperties.find(x => x.id === id);
        if (prop) openEnlargedModal(prop);
      });
      card.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          const id = Number(card.dataset.id);
          const prop = allProperties.find(x => x.id === id);
          if (prop) openEnlargedModal(prop);
        }
      });
    });

    // Attach landlord profile click handlers on cards
    grid.querySelectorAll('.dorm-card-landlord').forEach(el => {
      const handleLandlordClick = (e) => {
        e.stopPropagation();
        const id = Number(el.dataset.landlordProp);
        const prop = allProperties.find(x => x.id === id);
        if (prop) openLandlordProfile(prop);
      };
      el.addEventListener('click', handleLandlordClick);
      el.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          handleLandlordClick(e);
        }
      });
    });
  }

  // Transitions between Dorm Card & Room Details Card
  function transitionToRoom(p, room) {
    if (dormModal.open) {
      dormModal.classList.remove('is-swiping-in-left', 'is-swiping-in-right', 'is-swiping-out-right');
      dormModal.classList.add('is-swiping-out-left');
      setTimeout(() => {
        dormModal.close();
        dormModal.classList.remove('is-swiping-out-left');
        openRoomDetailsModal(p, room, 'swipe-right');
      }, 180);
    } else {
      openRoomDetailsModal(p, room);
    }
  }

  function transitionBackToDorm(p) {
    if (roomDetailsModal.open) {
      roomDetailsModal.classList.remove('is-swiping-in-left', 'is-swiping-in-right', 'is-swiping-out-left');
      roomDetailsModal.classList.add('is-swiping-out-right');
      setTimeout(() => {
        roomDetailsModal.close();
        roomDetailsModal.classList.remove('is-swiping-out-right');
        openEnlargedModal(p, 'swipe-left');
      }, 180);
    } else {
      openEnlargedModal(p);
    }
  }

  // Enlarged Dorm Modal:
  // photo, dorm name, location, near university, description, list of rooms (Option 2),
  // percentage of students from a certain school, distance from school, landlord name
  function openEnlargedModal(p, transitionDirection) {
    const photos = p.photos && p.photos.length ? p.photos : (p.photo ? [p.photo] : ['../uploads/properties/st_albert_dorm.jpg']);
    const nearest = nearestUniversity(p.lat, p.lng);
    const rooms = p.rooms || [];
    const initials = landlordInitials(p.landlordName);
    const totalResidents = Number(p.totalResidents || 0);
    const sameSchoolResidents = Number(p.sameSchoolResidents || 0);
    const livePct = Number(p.livePct ?? (p.commonSchoolPct || 0));

    let studentStatHtml = '';
    if (totalResidents > 0) {
      studentStatHtml = `<li><svg><use href="#people-icon"/></svg><span><b>${livePct}%</b> of students staying here are from <b>${esc(p.university)}</b> <small style="color:#8b7467;font-weight:600;">(${sameSchoolResidents} of ${totalResidents} registered resident${totalResidents === 1 ? '' : 's'})</small></span></li>`;
    } else {
      studentStatHtml = `<li><svg><use href="#people-icon"/></svg><span>No student residents booked yet · <b style="color:#7a4b3a;">Be the first from ${esc(p.university)} to stay here!</b></span></li>`;
    }

    const bookBtnText = p.hasBooked ? '✓ You booked this dorm · Click to Cancel' : 'Book / Reserve this Dorm';
    const bookBtnClass = p.hasBooked ? 'btn-dorm-booked' : 'btn-dorm-book';
    const bookingActionHtml = IS_AUTHENTICATED
      ? `<div style="margin-top:16px; padding-top:14px; border-top:1px solid #eadfd9;">
           <button type="button" class="btn-book-action ${bookBtnClass}" id="btn-toggle-booking" data-id="${p.id}" data-booked="${p.hasBooked ? '1' : '0'}">
             ${bookBtnText}
           </button>
         </div>`
      : `<div style="margin-top:16px; padding-top:14px; border-top:1px solid #eadfd9;">
           <button type="button" class="btn-book-action btn-dorm-book" id="btn-gate-booking">
             Log in to Book this Dorm
           </button>
         </div>`;

    // Property Card Style Carousel for Dorm Details Modal
    const carouselHtml = photos.length > 0 ? `
      <div class="um-carousel" id="dorm-modal-carousel">
        <div class="um-carousel-track">
          ${photos.map((src, idx) => `
            <img class="um-slide ${idx === 0 ? (photos.length === 1 ? 'is-active is-only' : 'is-active') : (idx === 1 ? 'is-next' : '')}"
                 src="${esc(src)}"
                 data-idx="${idx}"
                 alt="${esc(p.name)} photo ${idx + 1}"
                 loading="lazy">
          `).join('')}
        </div>
        ${photos.length > 1 ? `
          <button type="button" class="um-carousel-btn prev" id="dorm-carousel-prev" aria-label="Previous photo">
            <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"></polyline></svg>
          </button>
          <button type="button" class="um-carousel-btn next" id="dorm-carousel-next" aria-label="Next photo">
            <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>
          </button>
          <div class="um-carousel-count" id="dorm-carousel-count">1 / ${photos.length}</div>
        ` : ''}
      </div>
    ` : '';

    // Rooms list formatting (Option 2: Frosted Glass Overlay)
    let roomsHtml = '';
    if (!rooms.length) {
      roomsHtml = '<p style="color:#78665c;">No room units listed currently.</p>';
    } else {
      const firstRoom = rooms[0];
      const otherRooms = rooms.slice(1);
      const firstPhotos = getRoomPhotos(firstRoom);
      const firstCover = firstPhotos.length ? firstPhotos[0] : null;

      const firstRoomHtml = `
        <div class="room-card-preview" data-room-id="${firstRoom.id}" role="button" tabindex="0" title="Click to view ${esc(firstRoom.name)} details">
          <div class="room-photo-ph ${firstCover ? 'has-img' : ''}">
            ${firstCover ? `<img src="${esc(firstCover)}" alt="${esc(firstRoom.name)}" loading="lazy">` : `<svg><use href="#bed-icon"/></svg>`}
            ${firstPhotos.length > 1 ? `<span class="room-photo-count-badge">+${firstPhotos.length - 1}</span>` : ''}
          </div>
          <div class="room-card-info">
            <h4>${esc(firstRoom.name)}</h4>
            <p>${esc(firstRoom.type)} · ${firstRoom.capacity} Bed${firstRoom.capacity === 1 ? '' : 's'}</p>
            <p class="room-price">${fmtPrice(firstRoom.rent)} / month</p>
            <button type="button" class="btn-view-room" data-room-id="${firstRoom.id}">
              View Room <svg><use href="#arrow-icon"/></svg>
            </button>
          </div>
        </div>
      `;

      let othersHtml = '';
      if (otherRooms.length > 0) {
        if (CAN_VIEW_FULL_ROOMS) {
          // If profile completed, show all rooms cleanly
          othersHtml = otherRooms.map(r => {
            const rPhotos = getRoomPhotos(r);
            const rCover = rPhotos.length ? rPhotos[0] : null;
            return `
            <div class="room-card-preview" data-room-id="${r.id}" role="button" tabindex="0" title="Click to view ${esc(r.name)} details">
              <div class="room-photo-ph ${rCover ? 'has-img' : ''}">
                ${rCover ? `<img src="${esc(rCover)}" alt="${esc(r.name)}" loading="lazy">` : `<svg><use href="#bed-icon"/></svg>`}
                ${rPhotos.length > 1 ? `<span class="room-photo-count-badge">+${rPhotos.length - 1}</span>` : ''}
              </div>
              <div class="room-card-info">
                <h4>${esc(r.name)}</h4>
                <p>${esc(r.type)} · ${r.capacity} Bed${r.capacity === 1 ? '' : 's'}</p>
                <p class="room-price">${fmtPrice(r.rent)} / month</p>
                <button type="button" class="btn-view-room" data-room-id="${r.id}">
                  View Room <svg><use href="#arrow-icon"/></svg>
                </button>
              </div>
            </div>
          `;}).join('');
        } else {
          // Frosted Glass Overlay for visitors AND users with incomplete profiles
          const unlockBtnText = IS_AUTHENTICATED ? 'Complete profile to unlock' : 'Log in to unlock';
          const unlockDescText = IS_AUTHENTICATED 
            ? 'Complete your profile in Settings to unlock all room options, see full photos, and contact landlords.'
            : 'Sign in to unlock all room options, see full photos, and check real-time availability.';

          const blurredPreview = otherRooms.slice(0, 2).map(r => {
            const rPhotos = getRoomPhotos(r);
            const rCover = rPhotos.length ? rPhotos[0] : null;
            return `
            <div class="room-card-preview" style="margin-bottom:8px;">
              <div class="room-photo-ph ${rCover ? 'has-img' : ''}">
                ${rCover ? `<img src="${esc(rCover)}" alt="">` : `<svg><use href="#bed-icon"/></svg>`}
              </div>
              <div class="room-card-info">
                <h4>${esc(r.name)}</h4>
                <p>${esc(r.type)}</p>
                <p class="room-price">${fmtPrice(r.rent)} / month</p>
              </div>
            </div>
          `;}).join('');

          othersHtml = `
            <div class="rooms-frosted-wrap">
              <div class="rooms-frosted-stack" aria-hidden="true">
                ${blurredPreview}
              </div>
              <div class="rooms-frosted-overlay" id="frosted-overlay-btn" role="button" tabindex="0" aria-label="Unlock more rooms">
                <div class="frosted-lock-icon">
                  <svg aria-hidden="true"><use href="#lock-icon"/></svg>
                </div>
                <strong>${otherRooms.length} more room${otherRooms.length === 1 ? '' : 's'} in this dorm</strong>
                <p>${esc(unlockDescText)}</p>
                <button type="button" class="btn-frosted-unlock">
                  ${esc(unlockBtnText)} <svg style="width:14px;height:14px;"><use href="#arrow-icon"/></svg>
                </button>
              </div>
            </div>
          `;
        }
      }

      roomsHtml = firstRoomHtml + othersHtml;
    }

    dormModalContent.innerHTML = `
      <div class="dorm-modal-left">
        ${carouselHtml}
        <h2 style="margin:0 0 10px; font-size:1.4rem; color:#3b2820;">${esc(p.name)}</h2>
        <ul class="dorm-modal-facts">
          <li><svg><use href="#pin-icon"/></svg><span>Location: <b>${esc(p.address)}</b></span></li>
          <li><svg><use href="#school-icon"/></svg><span>Near which university: <b>${esc(p.university)}</b></span></li>
          ${studentStatHtml}
          <li><svg><use href="#measure-icon"/></svg><span>Distance from current school: <b>${nearest.km} km</b></span></li>
        </ul>
        <div class="dorm-modal-landlord" id="dorm-modal-landlord-btn" role="button" tabindex="0" title="Click to view landlord profile &amp; chat">
          <div class="dorm-modal-landlord-inner">
            <span class="dorm-modal-landlord-avatar">${esc(initials)}</span>
            <div>
              <div style="font-weight:700; color:#3b2820;">${esc(p.landlordName || 'Verified Landlord')}</div>
              <div style="font-size:0.82rem; color:#8b7467;">Property Landlord · Verified</div>
            </div>
          </div>
          <span class="dorm-modal-landlord-btn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.145 2 11.258c0 2.91 1.455 5.518 3.736 7.207V22l3.418-1.876c.9.25 1.86.386 2.846.386 5.523 0 10-4.145 10-9.252C22 6.145 17.523 2 12 2Z"/></svg>
            Contact Landlord
          </span>
        </div>
        <div class="dorm-modal-desc">
          ${esc(p.description || 'No additional description provided.')}
        </div>
        ${bookingActionHtml}
      </div>

      <div class="dorm-modal-right dorm-modal-rooms">
        <h3>List of Rooms</h3>
        ${roomsHtml}
      </div>
    `;

    // Wire Carousel Controls for Dorm Modal
    if (photos.length > 1) {
      let currentIdx = 0;
      const slides = dormModalContent.querySelectorAll('#dorm-modal-carousel .um-slide');
      const prevBtn = dormModalContent.querySelector('#dorm-carousel-prev');
      const nextBtn = dormModalContent.querySelector('#dorm-carousel-next');
      const countEl = dormModalContent.querySelector('#dorm-carousel-count');

      function updateCarousel() {
        slides.forEach((sl, idx) => {
          sl.classList.remove('is-active', 'is-prev', 'is-next');
          if (idx === currentIdx) {
            sl.classList.add('is-active');
          } else if (idx === currentIdx - 1) {
            sl.classList.add('is-prev');
          } else if (idx === currentIdx + 1) {
            sl.classList.add('is-next');
          }
        });

        if (prevBtn) {
          const canPrev = currentIdx > 0;
          prevBtn.style.opacity = canPrev ? '1' : '0.22';
          prevBtn.style.pointerEvents = canPrev ? 'auto' : 'none';
        }
        if (nextBtn) {
          const canNext = currentIdx < photos.length - 1;
          nextBtn.style.opacity = canNext ? '1' : '0.22';
          nextBtn.style.pointerEvents = canNext ? 'auto' : 'none';
        }
        if (countEl) {
          countEl.textContent = `${currentIdx + 1} / ${photos.length}`;
        }
      }

      if (prevBtn) {
        prevBtn.onclick = (e) => {
          e.stopPropagation();
          if (currentIdx > 0) {
            currentIdx--;
            updateCarousel();
          }
        };
      }
      if (nextBtn) {
        nextBtn.onclick = (e) => {
          e.stopPropagation();
          if (currentIdx < photos.length - 1) {
            currentIdx++;
            updateCarousel();
          }
        };
      }
      updateCarousel();
    }

    // Hook up booking toggle button
    const bookBtn = dormModalContent.querySelector('#btn-toggle-booking');
    if (bookBtn) {
      bookBtn.addEventListener('click', async () => {
        bookBtn.disabled = true;
        const isBooked = bookBtn.dataset.booked === '1';
        const action = isBooked ? 'cancel' : 'book';
        try {
          const res = await fetch('../api/book.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ property_id: p.id, action })
          });
          const resData = await res.json();
          if (resData.ok) {
            p.hasBooked = resData.has_booked;
            p.totalResidents = resData.totalResidents;
            p.sameSchoolResidents = resData.sameSchoolResidents;
            p.livePct = resData.livePct;
            p.commonSchoolPct = resData.livePct;
            openEnlargedModal(p);
          } else {
            alert(resData.error || 'Failed to update booking.');
            bookBtn.disabled = false;
          }
        } catch (err) {
          console.error(err);
          alert('Network error while booking.');
          bookBtn.disabled = false;
        }
      });
    }

    const gateBookBtn = dormModalContent.querySelector('#btn-gate-booking');
    if (gateBookBtn) {
      gateBookBtn.addEventListener('click', () => {
        openGateModal();
      });
    }

    // Hook up room action triggers (View Room button & card click with swipe transition!)
    dormModalContent.querySelectorAll('.btn-view-room').forEach(btn => {
      btn.onclick = (e) => {
        e.preventDefault();
        e.stopPropagation();
        const roomId = btn.dataset.roomId || btn.closest('[data-room-id]')?.dataset.roomId;
        const room = (p.rooms || []).find(r => String(r.id) === String(roomId));
        if (room) {
          transitionToRoom(p, room);
        }
      };
    });

    dormModalContent.querySelectorAll('.room-card-preview').forEach(card => {
      const roomId = card.dataset.roomId;
      const room = (p.rooms || []).find(r => String(r.id) === String(roomId));
      if (!room) return;

      card.onclick = (e) => {
        if (e.target.closest('.btn-view-room')) return;
        transitionToRoom(p, room);
      };

      card.onkeydown = (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          transitionToRoom(p, room);
        }
      };
    });

    const frostedOverlay = dormModalContent.querySelector('#frosted-overlay-btn');
    if (frostedOverlay) {
      frostedOverlay.addEventListener('click', () => openGateModal());
      frostedOverlay.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          openGateModal();
        }
      });
    }

    const dormLandlordBtn = dormModalContent.querySelector('#dorm-modal-landlord-btn');
    if (dormLandlordBtn) {
      const handleDormLandlordClick = () => openLandlordProfile(p);
      dormLandlordBtn.addEventListener('click', handleDormLandlordClick);
      dormLandlordBtn.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          handleDormLandlordClick();
        }
      });
    }

    if (transitionDirection === 'swipe-left') {
      dormModal.classList.remove('is-swiping-out-left', 'is-swiping-out-right', 'is-swiping-in-right');
      dormModal.classList.add('is-swiping-in-left');
      setTimeout(() => dormModal.classList.remove('is-swiping-in-left'), 260);
    }

    dormModal.showModal();
  }

  function openRoomDetailsModal(p, room, transitionDirection) {
    const content = document.getElementById('room-details-content');
    const headerTitle = document.getElementById('room-details-title');
    const headerBackBtn = document.getElementById('room-header-back-btn');
    if (!content) return;

    if (headerTitle) {
      headerTitle.textContent = `${room.name} · ${room.type} · ${p.name}`;
    }
    if (headerBackBtn) {
      headerBackBtn.setAttribute('title', `Back to ${p.name}`);
      headerBackBtn.setAttribute('aria-label', `Back to ${p.name}`);
      headerBackBtn.onclick = () => transitionBackToDorm(p);
    }

    const photos = getRoomPhotos(room);
    const initials = landlordInitials(p.landlordName);
    const amenities = Array.isArray(room.amenities) ? room.amenities : [];
    const nearest = nearestUniversity(Number(p.lat) || 16.0507, Number(p.lng) || 120.3408);
    const otherRooms = (p.rooms || []).filter(r => String(r.id) !== String(room.id));

    // Property Card Style Carousel for Room Details Card
    let galleryHtml = '';
    if (photos.length > 0) {
      galleryHtml = `
        <div class="um-carousel" id="room-modal-carousel">
          <div class="um-carousel-track">
            ${photos.map((src, idx) => `
              <img class="um-slide ${idx === 0 ? (photos.length === 1 ? 'is-active is-only' : 'is-active') : (idx === 1 ? 'is-next' : '')}"
                   src="${esc(src)}"
                   data-idx="${idx}"
                   alt="${esc(room.name)} photo ${idx + 1}"
                   loading="lazy">
            `).join('')}
          </div>
          ${photos.length > 1 ? `
            <button type="button" class="um-carousel-btn prev" id="room-carousel-prev" aria-label="Previous photo">
              <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"></polyline></svg>
            </button>
            <button type="button" class="um-carousel-btn next" id="room-carousel-next" aria-label="Next photo">
              <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </button>
            <div class="um-carousel-count" id="room-carousel-count">1 / ${photos.length}</div>
          ` : ''}
        </div>
      `;
    } else {
      galleryHtml = `
        <div class="um-carousel" id="room-modal-carousel">
          <div class="um-carousel-track" style="display:flex;align-items:center;justify-content:center;background:#f5eeea;">
            <div style="text-align:center;color:#9c8273;">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:block;margin:0 auto 6px;"><use href="#bed-icon"/></svg>
              <strong style="color:#633b2c; font-size:0.95rem; display:block;">${esc(room.name)}</strong>
              <span style="font-size:0.8rem;">No photo uploaded yet</span>
            </div>
          </div>
        </div>
      `;
    }

    let amenitiesHtml = '';
    if (amenities.length > 0) {
      amenitiesHtml = `
        <div class="room-amenities-wrap">
          <h4>Included Room Amenities</h4>
          <div class="room-amenities-list">
            ${amenities.map(a => `<span class="room-amenity-chip">${esc(a)}</span>`).join('')}
          </div>
        </div>
      `;
    }

    // Bottom-Left Booking Action Card (Notice: verified student badge & description removed so left half won't scroll!)
    let bookingSectionHtml = '';
    if (!IS_AUTHENTICATED) {
      bookingSectionHtml = `
        <div class="room-booking-action-card">
          <div class="room-booking-pricing-header">
            <div class="room-booking-main-price">${fmtPrice(room.rent)} <span style="font-size:0.85rem;color:#7a665a;font-weight:600;">/ mo</span></div>
            ${room.deposit ? `<div class="room-booking-deposit-sub">Deposit: ${fmtPrice(room.deposit)}</div>` : ''}
          </div>
          <button type="button" class="btn-book-room-action" id="btn-room-login-prompt">
            Log in to Book Room
          </button>
        </div>
      `;
    } else if (TENANT_VERIFICATION_STATUS !== 'verified') {
      bookingSectionHtml = `
        <div class="room-booking-action-card">
          <div class="room-booking-pricing-header">
            <div class="room-booking-main-price">${fmtPrice(room.rent)} <span style="font-size:0.85rem;color:#7a665a;font-weight:600;">/ mo</span></div>
            ${room.deposit ? `<div class="room-booking-deposit-sub">Deposit: ${fmtPrice(room.deposit)}</div>` : ''}
          </div>
          <a href="settings.php" class="btn-verify-prompt">
            Go to Settings &amp; Verification
          </a>
        </div>
      `;
    } else {
      // Verified Tenant!
      bookingSectionHtml = `
        <div class="room-booking-action-card">
          <div class="room-booking-pricing-header">
            <div class="room-booking-main-price">${fmtPrice(room.rent)} <span style="font-size:0.85rem;color:#7a665a;font-weight:600;">/ mo</span></div>
            ${room.deposit ? `<div class="room-booking-deposit-sub" style="margin-top:-4px;">Security Deposit: ${fmtPrice(room.deposit)}</div>` : ''}
          </div>
          <div id="room-booking-alert" style="display:none; padding:10px 14px; border-radius:8px; font-size:0.88rem; font-weight:600;"></div>
          <button type="button" class="btn-book-room-action" id="btn-submit-room-booking">
            Submit Booking Request
          </button>
        </div>
      `;
    }

    // Other rooms in this property for quick switching
    let otherRoomsHtml = '';
    if (otherRooms.length > 0) {
      otherRoomsHtml = `
        <div class="room-other-rooms-wrap">
          <h4>Other Rooms in this Dorm</h4>
          <div class="room-other-list">
            ${otherRooms.map(r => {
              const rPhotos = getRoomPhotos(r);
              const rCover = rPhotos.length ? rPhotos[0] : null;
              return `
                <div class="room-other-item" data-other-room-id="${r.id}" role="button" tabindex="0" title="Switch to ${esc(r.name)}">
                  <div class="room-other-left">
                    <div class="room-other-thumb">
                      ${rCover ? `<img src="${esc(rCover)}" alt="${esc(r.name)}" loading="lazy">` : `<svg><use href="#bed-icon"/></svg>`}
                    </div>
                    <div class="room-other-meta">
                      <strong>${esc(r.name)}</strong>
                      <span>${esc(r.type)} · ${r.capacity} Bed${r.capacity === 1 ? '' : 's'}</span>
                    </div>
                  </div>
                  <div class="room-other-price">${fmtPrice(r.rent)}/mo</div>
                </div>
              `;
            }).join('')}
          </div>
        </div>
      `;
    }

    content.innerHTML = `
      <!-- Left Column (Photos Carousel, Identity, Landlord, and Bottom-Left Book Now Button - NON-SCROLLABLE) -->
      <div class="dorm-modal-left">
        ${galleryHtml}

        <h2 class="room-title-heading">${esc(room.name)}</h2>
        <div class="room-prop-link">
          <svg><use href="#pin-icon"/></svg>
          <span>Part of <b>${esc(p.name)}</b> · ${esc(p.address)}</span>
        </div>

        <ul class="dorm-modal-facts" style="margin-bottom:10px;">
          <li><svg><use href="#bed-icon"/></svg><span>Room Unit: <b>${esc(room.type)} (${room.capacity} Bed${room.capacity === 1 ? '' : 's'})</b></span></li>
          <li><svg><use href="#school-icon"/></svg><span>Campus: <b>Near ${esc(p.university)}</b></span></li>
          <li><svg><use href="#measure-icon"/></svg><span>Distance from campus: <b>${nearest.km} km</b></span></li>
        </ul>

        <div class="dorm-modal-landlord" id="room-landlord-btn" role="button" tabindex="0" title="Click to view landlord profile &amp; chat" style="cursor:pointer;">
          <div class="dorm-modal-landlord-inner">
            <span class="dorm-modal-landlord-avatar">${esc(initials)}</span>
            <div>
              <div style="font-weight:700; color:#3b2820;">${esc(p.landlordName || 'Verified Landlord')}</div>
              <div style="font-size:0.82rem; color:#8b7467;">Property Landlord · Verified</div>
            </div>
          </div>
          <span class="dorm-modal-landlord-btn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.145 2 11.258c0 2.91 1.455 5.518 3.736 7.207V22l3.418-1.876c.9.25 1.86.386 2.846.386 5.523 0 10-4.145 10-9.252C22 6.145 17.523 2 12 2Z"/></svg>
            Chat Landlord
          </span>
        </div>

        <!-- Book Now Button Fitted at Bottom-Left! -->
        ${bookingSectionHtml}
      </div>

      <!-- Right Column (Specifications, Amenities, Guidelines & Other Rooms - SCROLLABLE) -->
      <div class="dorm-modal-right">
        <h3 class="room-details-section-title">Room Specifications</h3>

        <div class="room-specs-grid">
          <div class="room-spec-card">
            <span class="room-spec-label">Monthly Rate</span>
            <span class="room-spec-val" style="color:#8b3c1b;">${fmtPrice(room.rent)}</span>
          </div>
          <div class="room-spec-card">
            <span class="room-spec-label">Security Deposit</span>
            <span class="room-spec-val">${fmtPrice(room.deposit || 0)}</span>
          </div>
          <div class="room-spec-card">
            <span class="room-spec-label">Room Type</span>
            <span class="room-spec-val">${esc(room.type)}</span>
          </div>
          <div class="room-spec-card">
            <span class="room-spec-label">Bed Capacity</span>
            <span class="room-spec-val">${room.capacity} Resident${room.capacity === 1 ? '' : 's'}</span>
          </div>
          <div class="room-spec-card">
            <span class="room-spec-label">University Proximity</span>
            <span class="room-spec-val">${nearest.km} km</span>
          </div>
          <div class="room-spec-card">
            <span class="room-spec-label">Status</span>
            <span class="room-spec-val" style="color:#166534;">Available</span>
          </div>
        </div>

        ${amenitiesHtml}

        <div class="room-rules-card">
          <strong>Student Residence Guidelines</strong>
          UniStay properties prioritize student safety, quiet study hours, and verified guest policies. Connect with the landlord for specific check-in schedules.
        </div>

        ${otherRoomsHtml}
      </div>
    `;

    // Wire Room Modal Carousel
    if (photos.length > 1) {
      let currentIdx = 0;
      const slides = content.querySelectorAll('#room-modal-carousel .um-slide');
      const prevBtn = content.querySelector('#room-carousel-prev');
      const nextBtn = content.querySelector('#room-carousel-next');
      const countEl = content.querySelector('#room-carousel-count');

      function updateRoomCarousel() {
        slides.forEach((sl, idx) => {
          sl.classList.remove('is-active', 'is-prev', 'is-next');
          if (idx === currentIdx) {
            sl.classList.add('is-active');
          } else if (idx === currentIdx - 1) {
            sl.classList.add('is-prev');
          } else if (idx === currentIdx + 1) {
            sl.classList.add('is-next');
          }
        });

        if (prevBtn) {
          const canPrev = currentIdx > 0;
          prevBtn.style.opacity = canPrev ? '1' : '0.22';
          prevBtn.style.pointerEvents = canPrev ? 'auto' : 'none';
        }
        if (nextBtn) {
          const canNext = currentIdx < photos.length - 1;
          nextBtn.style.opacity = canNext ? '1' : '0.22';
          nextBtn.style.pointerEvents = canNext ? 'auto' : 'none';
        }
        if (countEl) {
          countEl.textContent = `${currentIdx + 1} / ${photos.length}`;
        }
      }

      if (prevBtn) {
        prevBtn.onclick = (e) => {
          e.stopPropagation();
          if (currentIdx > 0) {
            currentIdx--;
            updateRoomCarousel();
          }
        };
      }
      if (nextBtn) {
        nextBtn.onclick = (e) => {
          e.stopPropagation();
          if (currentIdx < photos.length - 1) {
            currentIdx++;
            updateRoomCarousel();
          }
        };
      }
      updateRoomCarousel();
    }

    // Hook other rooms quick switcher
    content.querySelectorAll('.room-other-item').forEach(item => {
      item.addEventListener('click', () => {
        const otherId = item.dataset.otherRoomId;
        const otherRoom = (p.rooms || []).find(r => String(r.id) === String(otherId));
        if (otherRoom) {
          openRoomDetailsModal(p, otherRoom);
        }
      });
    });

    // Hook landlord profile click
    const roomLandlordBtn = content.querySelector('#room-landlord-btn');
    if (roomLandlordBtn) {
      const handleRoomLandlordClick = () => openLandlordProfile(p);
      roomLandlordBtn.addEventListener('click', handleRoomLandlordClick);
      roomLandlordBtn.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          handleRoomLandlordClick();
        }
      });
    }

    const loginPromptBtn = content.querySelector('#btn-room-login-prompt');
    if (loginPromptBtn) {
      loginPromptBtn.addEventListener('click', () => {
        roomDetailsModal.close();
        openGateModal();
      });
    }

    const submitBookingBtn = content.querySelector('#btn-submit-room-booking');
    if (submitBookingBtn) {
      submitBookingBtn.addEventListener('click', async () => {
        submitBookingBtn.disabled = true;
        submitBookingBtn.textContent = 'Submitting Request...';
        const alertEl = content.querySelector('#room-booking-alert');

        try {
          const res = await fetch('../api/book.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              property_id: p.id,
              room_id: room.id,
              action: 'book'
            })
          });
          const resData = await res.json();
          if (resData.ok) {
            p.hasBooked = true;
            if (alertEl) {
              alertEl.style.display = 'block';
              alertEl.style.background = '#dcfce7';
              alertEl.style.color = '#166534';
              alertEl.style.border = '1px solid #bbf7d0';
              alertEl.innerHTML = `✓ ${esc(resData.message || 'Booking request submitted! Both you and the landlord have received email notifications.')}`;
            }
            submitBookingBtn.textContent = 'Booking Request Submitted';
            submitBookingBtn.style.background = '#15803d';
          } else {
            if (alertEl) {
              alertEl.style.display = 'block';
              alertEl.style.background = '#fee2e2';
              alertEl.style.color = '#991b1b';
              alertEl.style.border = '1px solid #fecaca';
              alertEl.textContent = resData.error || 'Failed to submit booking request.';
            }
            submitBookingBtn.disabled = false;
            submitBookingBtn.textContent = 'Submit Booking Request';
          }
        } catch (err) {
          console.error(err);
          if (alertEl) {
            alertEl.style.display = 'block';
            alertEl.style.background = '#fee2e2';
            alertEl.style.color = '#991b1b';
            alertEl.style.border = '1px solid #fecaca';
            alertEl.textContent = 'Network error while submitting booking.';
          }
          submitBookingBtn.disabled = false;
          submitBookingBtn.textContent = 'Submit Booking Request';
        }
      });
    }

    dormModal.close();
    if (transitionDirection === 'swipe-right') {
      roomDetailsModal.classList.remove('is-swiping-out-left', 'is-swiping-out-right', 'is-swiping-in-left');
      roomDetailsModal.classList.add('is-swiping-in-right');
      setTimeout(() => roomDetailsModal.classList.remove('is-swiping-in-right'), 260);
    }
    roomDetailsModal.showModal();
  }

  function openGateModal() {
    const titleEl = document.getElementById('auth-gate-title');
    const descEl = document.getElementById('auth-gate-desc');
    const actionsEl = document.getElementById('auth-gate-actions');

    if (!IS_AUTHENTICATED) {
      if (titleEl) titleEl.textContent = 'Log in to view rooms';
      if (descEl) descEl.textContent = 'Create an account or log in to view complete room photos, check availability, and send booking inquiries to landlords.';
      if (actionsEl) {
        actionsEl.innerHTML = `
          <a href="../auth/login.php?redirect=tenant/dorms.php" class="btn-auth-primary">Log in</a>
          <a href="../auth/register.php?redirect=tenant/dorms.php" class="btn-auth-secondary">Sign up</a>
        `;
      }
    } else {
      if (titleEl) titleEl.textContent = 'Complete your profile to view rooms';
      if (descEl) descEl.textContent = 'You are registered! Finish filling in your registration details in Settings to view complete room photos, check availability, and send booking inquiries to landlords.';
      if (actionsEl) {
        actionsEl.innerHTML = `
          <a href="settings.php?edit=1" class="btn-auth-primary">Complete Profile in Settings</a>
          <button type="button" class="btn-auth-secondary" id="auth-gate-close-action">Browse dorms for now</button>
        `;
        const closeAction = document.getElementById('auth-gate-close-action');
        if (closeAction) {
          closeAction.addEventListener('click', () => authGateModal.close());
        }
      }
    }
    authGateModal.showModal();
  }

  dormModalCloseBtn.addEventListener('click', () => dormModal.close());
  dormModal.addEventListener('click', (e) => {
    const rect = dormModal.getBoundingClientRect();
    if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) {
      dormModal.close();
    }
  });

  if (roomDetailsCloseBtn) {
    roomDetailsCloseBtn.addEventListener('click', () => roomDetailsModal.close());
  }
  if (roomDetailsModal) {
    roomDetailsModal.addEventListener('click', (e) => {
      const rect = roomDetailsModal.getBoundingClientRect();
      if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) {
        roomDetailsModal.close();
      }
    });
  }

  authGateCloseBtn.addEventListener('click', () => authGateModal.close());
  authGateModal.addEventListener('click', (e) => {
    const rect = authGateModal.getBoundingClientRect();
    if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) {
      authGateModal.close();
    }
  });

  // Onboarding Toast Modal Events with smooth open & close
  function dismissOnboardingModal() {
    if (!onboardingToastModal || onboardingToastModal.classList.contains('is-closing')) return;
    sessionStorage.setItem('unistay_profile_toast_dismissed', '1');
    onboardingToastModal.classList.add('is-closing');
    setTimeout(() => {
      onboardingToastModal.close();
      onboardingToastModal.classList.remove('is-closing');
    }, 220);
  }

  if (onboardingToastModal) {
    if (btnToastLater) {
      btnToastLater.addEventListener('click', (e) => {
        e.preventDefault();
        dismissOnboardingModal();
      });
    }

    onboardingToastModal.addEventListener('cancel', (e) => {
      e.preventDefault();
      dismissOnboardingModal();
    });

    onboardingToastModal.addEventListener('click', (e) => {
      const rect = onboardingToastModal.getBoundingClientRect();
      if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) {
        dismissOnboardingModal();
      }
    });
  }

  // ==========================================
  // Landlord Profile Modal Controller
  // ==========================================
  function openLandlordProfile(prop) {
    if (!landlordProfileModal || !prop) return;
    const landlord = prop.landlord || {
      id: prop.landlord_id || 0,
      name: prop.landlordName || 'Verified Landlord',
      photo: prop.landlordPhoto || null,
      phone: null,
      email: null,
      facebook: null,
      messenger: null,
      verification: 'verified',
      memberSince: '2024'
    };

    const initials = landlordInitials(landlord.name);
    const avatarContainer = document.getElementById('landlord-avatar-container');
    const nameEl = document.getElementById('landlord-modal-name');
    const contextEl = document.getElementById('landlord-modal-context');
    const sinceEl = document.getElementById('landlord-modal-since');
    const messengerContainer = document.getElementById('landlord-messenger-container');
    const phoneEl = document.getElementById('landlord-modal-phone');
    const emailEl = document.getElementById('landlord-modal-email');
    const propsListEl = document.getElementById('landlord-props-list');

    if (nameEl) nameEl.textContent = landlord.name;
    if (contextEl) {
      const dormName = prop.name || 'Dormitory';
      const uniName = prop.university ? ` · Near ${prop.university}` : '';
      contextEl.textContent = `Managing ${dormName}${uniName}`;
    }
    if (sinceEl) sinceEl.textContent = `Member since ${landlord.memberSince || '2024'}`;

    if (avatarContainer) {
      if (landlord.photo) {
        avatarContainer.innerHTML = `<img class="landlord-profile-avatar" src="${esc(landlord.photo)}" alt="${esc(landlord.name)}" onerror="this.onerror=null;this.parentElement.innerHTML='<span class=\\'landlord-profile-initials\\'>${esc(initials)}</span>';">`;
      } else {
        avatarContainer.innerHTML = `<span class="landlord-profile-initials">${esc(initials)}</span>`;
      }
    }

    if (messengerContainer) {
      let messengerHtml = '';
      if (landlord.messenger) {
        messengerHtml += `
          <a href="${esc(landlord.messenger)}" target="_blank" rel="noopener noreferrer" class="landlord-messenger-btn" title="Open Messenger chat">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.145 2 11.258c0 2.91 1.455 5.518 3.736 7.207V22l3.418-1.876c.9.25 1.86.386 2.846.386 5.523 0 10-4.145 10-9.252C22 6.145 17.523 2 12 2Zm1.085 12.355-2.613-2.788-5.1 2.788 5.61-5.955 2.68 2.788 5.033-2.788-5.61 5.955Z"/></svg>
            <span>Open Messenger Chat &nearr;</span>
          </a>
          <span class="landlord-messenger-hint">Opens Facebook Messenger app or web chat</span>
        `;
      } else {
        messengerHtml += `
          <div style="padding:10px 16px; border-radius:12px; background:#f4f5f7; color:#65676b; font-size:0.86rem; font-weight:600; text-align:center; width:100%; max-width:380px;">
            Messenger link not added by landlord
          </div>
        `;
      }

      if (landlord.facebook || landlord.phone) {
        messengerHtml += `<div class="landlord-quick-actions-row">`;
        if (landlord.facebook) {
          messengerHtml += `
            <a href="${esc(landlord.facebook)}" target="_blank" rel="noopener noreferrer" class="landlord-action-pill fb-pill" title="View Facebook profile">
              <svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
              <span>Facebook Profile</span>
            </a>
          `;
        }
        if (landlord.phone) {
          messengerHtml += `
            <a href="tel:${esc(landlord.phone)}" class="landlord-action-pill phone-pill" title="Call or text landlord">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
              <span>Call or SMS</span>
            </a>
          `;
        }
        messengerHtml += `</div>`;
      }
      messengerContainer.innerHTML = messengerHtml;
    }

    if (phoneEl) {
      if (landlord.phone) {
        phoneEl.innerHTML = `<a href="tel:${esc(landlord.phone)}" class="landlord-contact-val">${esc(landlord.phone)}</a>`;
      } else {
        phoneEl.textContent = 'Not provided';
      }
    }

    if (emailEl) {
      if (landlord.email) {
        emailEl.innerHTML = `<a href="mailto:${esc(landlord.email)}" class="landlord-contact-val">${esc(landlord.email)}</a>`;
      } else {
        emailEl.textContent = 'Not provided';
      }
    }

    if (propsListEl) {
      const landlordProps = allProperties.filter(x => {
        const xLandlordId = (x.landlord && x.landlord.id) || x.landlord_id;
        return xLandlordId && landlord.id && Number(xLandlordId) === Number(landlord.id);
      });

      const displayProps = landlordProps.length ? landlordProps : [prop];
      propsListEl.innerHTML = displayProps.map(item => {
        const itemPhotos = item.photos && item.photos.length ? item.photos : (item.photo ? [item.photo] : ['../uploads/properties/st_albert_dorm.jpg']);
        return `
          <div class="landlord-prop-item" data-prop-id="${item.id}" role="button" tabindex="0">
            <img class="landlord-prop-thumb" src="${esc(itemPhotos[0])}" alt="${esc(item.name)}" loading="lazy">
            <div class="landlord-prop-details">
              <div class="landlord-prop-name">${esc(item.name)}</div>
              <div class="landlord-prop-uni">Near ${esc(item.university)}</div>
            </div>
            <button type="button" class="btn-view-prop-mini">View</button>
          </div>
        `;
      }).join('');

      propsListEl.querySelectorAll('.landlord-prop-item').forEach(itemEl => {
        itemEl.addEventListener('click', () => {
          const pId = Number(itemEl.dataset.propId);
          const found = allProperties.find(x => x.id === pId);
          if (found) {
            closeLandlordProfile();
            openEnlargedModal(found);
          }
        });
      });
    }

    landlordProfileModal.showModal();
  }

  function closeLandlordProfile() {
    if (!landlordProfileModal || landlordProfileModal.classList.contains('is-closing')) return;
    landlordProfileModal.classList.add('is-closing');
    setTimeout(() => {
      landlordProfileModal.close();
      landlordProfileModal.classList.remove('is-closing');
    }, 220);
  }

  if (landlordProfileCloseBtn) {
    landlordProfileCloseBtn.addEventListener('click', () => closeLandlordProfile());
  }
  if (landlordProfileModal) {
    landlordProfileModal.addEventListener('click', (e) => {
      const rect = landlordProfileModal.getBoundingClientRect();
      if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) {
        closeLandlordProfile();
      }
    });
    landlordProfileModal.addEventListener('cancel', (e) => {
      e.preventDefault();
      closeLandlordProfile();
    });
  }

  // ==========================================
  // Interactive User Guide Slideshow Controller
  // ==========================================
  let guideCurrentStep = 1;
  const GUIDE_TOTAL_STEPS = 7;

  function showGuideStep(step) {
    guideCurrentStep = Math.max(1, Math.min(step, GUIDE_TOTAL_STEPS));
    if (!userGuideModal) return;

    const slides = userGuideModal.querySelectorAll('.guide-slide');
    slides.forEach(slide => {
      const slideStep = Number(slide.dataset.step);
      slide.classList.toggle('active', slideStep === guideCurrentStep);
    });

    if (guideStepIndicator) {
      guideStepIndicator.textContent = `Step ${guideCurrentStep} of ${GUIDE_TOTAL_STEPS}`;
    }

    if (guideDots) {
      guideDots.querySelectorAll('.guide-dot').forEach(dot => {
        const dotStep = Number(dot.dataset.goto);
        dot.classList.toggle('active', dotStep === guideCurrentStep);
      });
    }

    if (guidePrevBtn) {
      guidePrevBtn.style.display = guideCurrentStep > 1 ? 'inline-flex' : 'none';
    }

    if (guideNextBtn) {
      if (guideCurrentStep === GUIDE_TOTAL_STEPS) {
        guideNextBtn.innerHTML = 'All Good! Let&#39;s Go 🚀';
        guideNextBtn.classList.add('btn-guide-finish');
      } else {
        guideNextBtn.innerHTML = 'Next &rarr;';
        guideNextBtn.classList.remove('btn-guide-finish');
      }
    }
  }

  function openGuideModal() {
    if (!userGuideModal) return;
    showGuideStep(1);
    userGuideModal.showModal();
  }

  function closeGuideModal(triggerOnboardingToast = false) {
    if (!userGuideModal || userGuideModal.classList.contains('is-closing')) return;
    localStorage.setItem('unistay_guide_viewed', '1');
    userGuideModal.classList.add('is-closing');
    setTimeout(() => {
      userGuideModal.close();
      userGuideModal.classList.remove('is-closing');
      if (triggerOnboardingToast && onboardingToastModal && !sessionStorage.getItem('unistay_profile_toast_dismissed')) {
        onboardingToastModal.showModal();
      }
    }, 220);
  }

  if (guideNextBtn) {
    guideNextBtn.addEventListener('click', () => {
      if (guideCurrentStep < GUIDE_TOTAL_STEPS) {
        showGuideStep(guideCurrentStep + 1);
      } else {
        closeGuideModal(true);
      }
    });
  }

  if (guidePrevBtn) {
    guidePrevBtn.addEventListener('click', () => {
      if (guideCurrentStep > 1) {
        showGuideStep(guideCurrentStep - 1);
      }
    });
  }

  if (guideSkipBtn) {
    guideSkipBtn.addEventListener('click', () => {
      closeGuideModal(true);
    });
  }

  if (guideDots) {
    guideDots.querySelectorAll('.guide-dot').forEach(dot => {
      dot.addEventListener('click', () => {
        const targetStep = Number(dot.dataset.goto);
        if (targetStep) showGuideStep(targetStep);
      });
    });
  }

  if (userGuideModal) {
    userGuideModal.addEventListener('cancel', (e) => {
      e.preventDefault();
      closeGuideModal(true);
    });
    userGuideModal.addEventListener('click', (e) => {
      const rect = userGuideModal.getBoundingClientRect();
      if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) {
        closeGuideModal(true);
      }
    });
  }

  if (guideLauncherBtn) {
    guideLauncherBtn.addEventListener('click', () => {
      openGuideModal();
    });
  }

  // Orchestrate Initial Guide & Toast Flow:
  // For new registrations: Always launch guide first, then the completion toast upon finish/skip.
  if (IS_NEW_TENANT_REGISTRATION) {
    try {
      localStorage.removeItem('unistay_guide_viewed');
      sessionStorage.removeItem('unistay_profile_toast_dismissed');
    } catch (e) {}
  }

  const guideHasBeenViewed = localStorage.getItem('unistay_guide_viewed');
  if (!guideHasBeenViewed || IS_NEW_TENANT_REGISTRATION) {
    openGuideModal();
  } else if (onboardingToastModal && !sessionStorage.getItem('unistay_profile_toast_dismissed') && SHOW_ONBOARDING_TOAST) {
    onboardingToastModal.showModal();
  }

  function updateStayCounts() {
    const allCount = allProperties.length;
    const activeCount = allProperties.filter(p => p.userStayStatus === 'active').length;
    const upcomingCount = allProperties.filter(p => p.userStayStatus === 'upcoming').length;
    const rejectedCount = allProperties.filter(p => p.userStayStatus === 'rejected').length;

    const elAll = document.getElementById('count-all');
    const elActive = document.getElementById('count-active');
    const elUpcoming = document.getElementById('count-upcoming');
    const elRejected = document.getElementById('count-rejected');

    if (elAll) elAll.textContent = allCount;
    if (elActive) elActive.textContent = activeCount;
    if (elUpcoming) elUpcoming.textContent = upcomingCount;
    if (elRejected) elRejected.textContent = rejectedCount;
  }

  // Filter & Search handler
  function applyFilters() {
    const q = currentSearch.trim().toLowerCase();
    const filtered = allProperties.filter(p => {
      const matchUni = !currentFilter || p.university === currentFilter;
      const matchSearch = !q || p.name.toLowerCase().includes(q) || (p.address && p.address.toLowerCase().includes(q));
      let matchStay = true;
      if (currentStayFilter === 'active') {
        matchStay = (p.userStayStatus === 'active');
      } else if (currentStayFilter === 'upcoming') {
        matchStay = (p.userStayStatus === 'upcoming');
      } else if (currentStayFilter === 'rejected') {
        matchStay = (p.userStayStatus === 'rejected');
      }
      return matchUni && matchSearch && matchStay;
    });
    renderCards(filtered);
  }

  // Hook stay filter buttons
  document.querySelectorAll('.stay-filter-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.stay-filter-btn').forEach(b => {
        b.classList.remove('is-active');
        b.setAttribute('aria-selected', 'false');
      });
      btn.classList.add('is-active');
      btn.setAttribute('aria-selected', 'true');
      currentStayFilter = btn.dataset.stay;
      applyFilters();
    });
  });

  searchInput.addEventListener('input', (e) => {
    currentSearch = e.target.value;
    applyFilters();
  });

  // Custom University Select Dropdown with smooth animated open/close
  let menuCloseTimeout = null;
  const closeMenuAnimated = () => {
    if (uniMenu.hidden || uniMenu.classList.contains('is-closing')) return;
    clearTimeout(menuCloseTimeout);
    uniBtn.setAttribute('aria-expanded', 'false');
    uniMenu.classList.add('is-closing');
    menuCloseTimeout = setTimeout(() => {
      uniMenu.hidden = true;
      uniMenu.classList.remove('is-closing');
    }, 160);
  };

  const openMenu = () => {
    clearTimeout(menuCloseTimeout);
    uniMenu.classList.remove('is-closing');
    uniMenu.hidden = false;
    uniBtn.setAttribute('aria-expanded', 'true');
  };

  const toggleMenu = (open) => {
    const willOpen = open ?? uniMenu.hidden;
    if (willOpen) {
      openMenu();
    } else {
      closeMenuAnimated();
    }
  };

  uniBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    toggleMenu();
  });

  document.addEventListener('click', (e) => {
    if (!uniSelect.contains(e.target)) closeMenuAnimated();
  });

  uniMenu.querySelectorAll('li').forEach(li => {
    li.addEventListener('click', () => {
      uniMenu.querySelectorAll('li').forEach(el => {
        el.setAttribute('aria-selected', 'false');
      });
      li.setAttribute('aria-selected', 'true');
      const val = li.dataset.value;
      currentFilter = val;
      uniValue.textContent = val || 'Search by university';
      uniBtn.classList.toggle('is-placeholder', !val);
      toggleMenu(false);
      applyFilters();
    });
  });

  // Load properties
  (async () => {
    try {
      allProperties = await Store.all();
      updateStayCounts();
      renderCards(allProperties);

      // Deep link support (?property=101&room=1 or ?property=101)
      const params = new URLSearchParams(window.location.search);
      const propId = params.get('property');
      const roomId = params.get('room');
      if (propId) {
        const targetProp = allProperties.find(x => String(x.id) === String(propId));
        if (targetProp) {
          if (roomId) {
            const targetRoom = (targetProp.rooms || []).find(r => String(r.id) === String(roomId));
            if (targetRoom) {
              openRoomDetailsModal(targetProp, targetRoom);
            } else {
              openEnlargedModal(targetProp);
            }
          } else {
            openEnlargedModal(targetProp);
          }
        }
      }
    } catch (err) {
      console.error('Failed to load dormitories', err);
      grid.innerHTML = '<p class="dorms-empty">Failed to load dormitories. Please try again later.</p>';
    }
  })();
})();
</script>
</body>
</html>
