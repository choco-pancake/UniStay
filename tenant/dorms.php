<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

start_app_session();
if (isset($_GET['visitor']) || isset($_GET['guest'])) {
    clear_authenticated_session();
}
$sessionTenant = session_user_with_role('tenant');
if ($sessionTenant !== null) enforce_profile_completion($sessionTenant);
$user_id = $sessionTenant['id'] ?? null;
$isAuthenticated = $sessionTenant !== null;
$profileCompleted = false;
$showOnboardingToast = false;
$forceTenantTour = !empty($_SESSION['start_tenant_tour']) || (!empty($_GET['tour']) && $_GET['tour'] === '1') || (!empty($_GET['tutorial']) && $_GET['tutorial'] === '1');
if (!empty($_SESSION['start_tenant_tour'])) {
    unset($_SESSION['start_tenant_tour']);
}
$tenantProfile = null;
$tenantSchool = '';

if ($isAuthenticated) {
    $pdo = database();
    $profileCompleted = tenant_profile_complete($pdo, (int)$user_id);
    $tenantProfile = get_authenticated_profile($pdo, (int)$user_id);

    try {
        $schoolStmt = $pdo->prepare('SELECT school FROM users WHERE id = ? LIMIT 1');
        $schoolStmt->execute([(int)$user_id]);
        $tenantSchool = trim((string)$schoolStmt->fetchColumn());
    } catch (Throwable $e) {
        $tenantSchool = '';
    }

    $tenantVerification = get_tenant_verification($pdo, (int)$user_id);
    require_once __DIR__ . '/../config/notifications.php';
    $tenantNotifications = get_user_in_app_notifications($pdo, (int)$user_id, 50);
    $isNewTenantRegistration = false;
    if (!empty($_SESSION['is_new_tenant_registration']) || !empty($_SESSION['show_profile_completion_toast'])) {
        $isNewTenantRegistration = true;
        unset($_SESSION['is_new_tenant_registration'], $_SESSION['show_profile_completion_toast']);
    }
    $freshLogin = !empty($_SESSION['profile_modal_pending']);
    unset($_SESSION['profile_modal_pending']);
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
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400..800&family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/common.css?v=35">
<link rel="stylesheet" href="../assets/css/dorms.css?v=45">
<link rel="stylesheet" href="../assets/css/confirm-modal.css?v=<?= filemtime(__DIR__ . '/../assets/css/confirm-modal.css') ?>">
<link rel="stylesheet" href="../assets/css/walkthrough.css?v=1">
<script src="../assets/js/tenant/popover.js?v=2"></script>
<script src="../assets/js/tenant/sidebar.js?v=5"></script>
<?php if ($isAuthenticated): ?>
<script src="../assets/js/tenant/profile-menu.js?v=31"></script>
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
  <a href="../index.php" class="brand" title="UniStay Home">
    <img src="../assets/images/tenant/logo.png" width="50" height="50" alt="">
    <span class="brand-name">UniStay</span>
  </a>
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
        <span>Tutorial</span>
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

    </div>

    <div class="dorms-stay-filters" role="tablist" aria-label="Filter by stay status">
      <span class="stay-pill" aria-hidden="true"></span>
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
      <!-- Skeleton Loaders while Dorms Fetch -->
      <?php for ($i = 0; $i < 6; $i++): ?>
        <article class="dorm-card skeleton-card" aria-hidden="true">
          <div class="skeleton-card-thumb skeleton-shimmer"></div>
          <div class="skeleton-card-body">
            <div class="skeleton-line skeleton-line-title skeleton-shimmer"></div>
            <div class="skeleton-line skeleton-line-sub skeleton-shimmer"></div>
            <div class="skeleton-line skeleton-line-price skeleton-shimmer"></div>
            <div class="skeleton-card-btn skeleton-shimmer"></div>
          </div>
        </article>
      <?php endfor; ?>
    </div>
  </main>
</div>

<!-- Enlarged Dorm & Room Inspection Modal (Single Unified Dialog) -->
<dialog id="dorm-modal" class="dorm-modal" aria-labelledby="dorm-modal-title">
  <div class="dorm-modal-header" id="dorm-modal-header">
    <button type="button" class="btn-modal-back" id="dorm-modal-back-btn" aria-label="Back to dorm" title="Back to dorm">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
    </button>
    <h2 id="dorm-modal-title">Dorm details</h2>
    <button type="button" class="dorm-modal-close" id="dorm-modal-close-btn" aria-label="Close dialog">✕</button>
  </div>
  <div class="dorm-modal-body" id="dorm-modal-content">
    <!-- Populated by JavaScript -->
  </div>
</dialog>

<!-- Landlord Profile Modal (Rectangular 2-Column Workstation Card) -->
<dialog id="landlord-profile-modal" class="landlord-profile-modal" aria-labelledby="landlord-modal-name">
  <div class="landlord-profile-card-rect">
    <button type="button" class="landlord-profile-close-btn" id="landlord-profile-close-btn" aria-label="Close landlord profile">✕</button>

    <!-- Left Column: UniStay Brown Gradient Theme (Landlord Details & Contact) -->
    <div class="landlord-profile-col-left">
      <div class="landlord-avatar-wrap">
        <div id="landlord-avatar-container"></div>
      </div>
      <h2 id="landlord-modal-name" class="landlord-profile-name">Landlord Name</h2>
      <div id="landlord-modal-context" class="landlord-profile-context"></div>
      <div class="landlord-profile-meta">
        <span class="landlord-status-tag" id="landlord-modal-status">Verified Landlord</span>
        <span class="meta-dot" id="landlord-modal-status-dot"></span>
        <span id="landlord-modal-since">Member since 2024</span>
      </div>

      <!-- Action & Contact Buttons on Brown -->
      <div id="landlord-messenger-container" class="landlord-messenger-wrap"></div>

      <div class="landlord-contact-section">
        <div class="landlord-contact-card" id="landlord-phone-card">
          <div class="landlord-contact-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
          </div>
          <div class="landlord-contact-info">
            <span class="landlord-contact-label">Phone</span>
            <span id="landlord-modal-phone" class="landlord-contact-val">Not provided</span>
          </div>
        </div>

        <div class="landlord-contact-card" id="landlord-email-card">
          <div class="landlord-contact-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
          </div>
          <div class="landlord-contact-info">
            <span class="landlord-contact-label">Email</span>
            <span id="landlord-modal-email" class="landlord-contact-val">Not provided</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Right Column: Dormitories Managed -->
    <div class="landlord-profile-col-right">
      <div class="landlord-props-header">
        <div class="landlord-props-title-group">
          <h3>Dormitories Managed</h3>
          <span class="landlord-props-badge" id="landlord-props-count">0</span>
        </div>
        <p class="landlord-props-subtitle">Properties actively owned &amp; managed by this landlord</p>
      </div>
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

<script src="../assets/js/confirm-modal.js?v=<?= filemtime(__DIR__ . '/../assets/js/confirm-modal.js') ?>"></script>
<script src="../assets/js/store.js"></script>
<script src="../assets/js/tenant/dorm-details.js?v=10"></script>
<script src="../assets/js/tenant/walkthrough.js?v=1"></script>
<?php if ($isAuthenticated): ?>
<script id="notification-data" type="application/json"><?= json_encode($tenantNotifications ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>

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
  const FORCE_TENANT_TOUR = <?= $forceTenantTour ? 'true' : 'false' ?>;
  const TENANT_SCHOOL = <?= json_encode($tenantSchool, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  const grid = document.getElementById('dorms-grid');
  const searchInput = document.getElementById('dorm-search');
  const uniSelect = document.getElementById('uni-select');
  const uniBtn = uniSelect.querySelector('.um-select-btn');
  const uniValue = uniSelect.querySelector('.um-select-value');
  const uniMenu = uniSelect.querySelector('.um-select-menu');
  const dormModal = document.getElementById('dorm-modal');
  const dormModalHeader = document.getElementById('dorm-modal-header');
  const dormModalTitle = document.getElementById('dorm-modal-title');
  const dormModalBackBtn = document.getElementById('dorm-modal-back-btn');
  const dormModalCloseBtn = document.getElementById('dorm-modal-close-btn');
  const dormModalContent = document.getElementById('dorm-modal-content');
  const authGateModal = document.getElementById('auth-gate-modal');
  const authGateCloseBtn = document.getElementById('auth-gate-close-btn');
  const onboardingToastModal = document.getElementById('onboarding-toast-modal');
  const btnToastLater = document.getElementById('btn-toast-later');
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

  function resolveTenantUniversity(school) {
    const s = String(school || '').toLowerCase().replace(/[^a-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim();
    if (!s) return null;
    const byName = UNIVERSITIES.find(u => u.name.toLowerCase() === s);
    if (byName) return byName;
    const rules = [
      { name: 'Universidad de Dagupan',         re: /dagupan|\budd\b|\bcdd\b/ },
      { name: 'University of Pangasinan',       re: /\bupang\b|phinma|pangasinan/, not: /state/ },
      { name: 'University of Luzon',            re: /luzon|\bul\b/ },
      { name: 'Lyceum Northwestern University', re: /lyceum|northwestern|\blnu\b/ },
    ];
    for (const r of rules) {
      if (r.re.test(s) && !(r.not && r.not.test(s))) {
        return UNIVERSITIES.find(u => u.name === r.name) || null;
      }
    }
    return null;
  }

  // Dorms tagged with the tenant's school first, then nearest to that school
  function sortForTenant(list) {
    const school = resolveTenantUniversity(TENANT_SCHOOL);
    if (!school) return list;
    const target = school.name.toLowerCase();
    return list
      .map((p, i) => ({
        p, i,
        own: String(p.university || '').trim().toLowerCase() === target ? 0 : 1,
        d: havKm({ lat: Number(p.lat), lng: Number(p.lng) }, school),
      }))
      .sort((a, b) => (a.own - b.own) || (a.d - b.d) || (a.i - b.i))
      .map(x => x.p);
  }

  function landlordInitials(name) {
    return String(name || '').trim().split(/\s+/).filter(Boolean).slice(0, 2)
      .map(w => w[0]).join('').toUpperCase() || '?';
  }

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function getPropertyAmenities(property) {
    const amenities = [];
    const seen = new Set();

    (property.rooms || []).forEach(room => {
      (Array.isArray(room.amenities) ? room.amenities : []).forEach(amenity => {
        const label = String(amenity || '').trim();
        if (!label || seen.has(label)) return;
        seen.add(label);
        amenities.push(label);
      });
    });

    return amenities;
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
      const lPhoto = p.landlordPhoto || (p.landlord && p.landlord.photo) || null;
      const landlordAvatarHtml = lPhoto
        ? `<span class="landlord-avatar-small has-img" aria-hidden="true"><img src="${esc(lPhoto)}" alt="${esc(p.landlordName)}" class="landlord-avatar-thumb" onerror="this.style.display='none';this.nextElementSibling.style.display='grid';"><span class="landlord-avatar-fallback" style="display:none;">${esc(initials)}</span></span>`
        : `<span class="landlord-avatar-small" aria-hidden="true">${esc(initials)}</span>`;

      let stayBadgeHtml = '';
      if (p.userStayStatus === 'active') {
        stayBadgeHtml = '<span class="dorm-card-stay-badge stay-active">● Active Stay</span>';
      } else if (p.userStayStatus === 'upcoming') {
        stayBadgeHtml = '<span class="dorm-card-stay-badge stay-upcoming">● Upcoming Stay</span>';
      } else if (p.userStayStatus === 'rejected') {
        stayBadgeHtml = '<span class="dorm-card-stay-badge stay-rejected">● Stay Rejected</span>';
      }
      const cancelStayHtml = p.userStayStatus === 'upcoming'
        ? `<button type="button" class="dorm-card-cancel-stay" data-cancel-stay="${p.id}">Cancel Stay</button>`
        : '';
      const amenities = getPropertyAmenities(p);
      const visibleAmenities = amenities.slice(0, 4);
      const remainingAmenities = amenities.slice(4);
      const amenitiesHtml = amenities.length ? `
        <div class="dorm-card-amenities" aria-label="Included amenities">
          ${visibleAmenities.map(amenity => `<span class="dorm-card-amenity">${esc(amenity)}</span>`).join('')}
          ${remainingAmenities.length ? `<span class="dorm-card-amenity dorm-card-amenity-more" title="${esc(remainingAmenities.join(', '))}" aria-label="${remainingAmenities.length} more amenities: ${esc(remainingAmenities.join(', '))}">+${remainingAmenities.length} more</span>` : ''}
        </div>
      ` : '';

      return `
        <article class="dorm-card" data-id="${p.id}" tabindex="0" role="button" aria-label="View ${esc(p.name)}">
          <div class="dorm-card-photo-wrap">
            <img class="dorm-card-photo" src="${esc(coverPhoto)}" alt="${esc(p.name)}" loading="lazy">
            ${IS_AUTHENTICATED ? `
            <span class="dorm-card-uni-badge">
              <svg><use href="#school-icon"/></svg>
              ${esc(p.university)}
            </span>` : ''}
            ${stayBadgeHtml}
          </div>
          <div class="dorm-card-body">
            <h3 class="dorm-card-title">${esc(p.name)}</h3>
            <div class="dorm-card-location">
              <svg><use href="#pin-icon"/></svg>
              <span>${esc(p.address)}</span>
            </div>
            ${IS_AUTHENTICATED ? `
            <div class="dorm-card-uni">
              <svg><use href="#school-icon"/></svg>
              <span>Near ${esc(p.university)}</span>
            </div>` : ''}
            ${amenitiesHtml}
            <div class="dorm-card-landlord" data-landlord-prop="${p.id}" role="button" tabindex="0" title="Click to view landlord profile">
              ${landlordAvatarHtml}
              <span>Landlord: <strong>${esc(p.landlordName || 'Landlord')}</strong>${isLandlordVerified(p) ? verifiedCheckHtml() : ''}</span>
            </div>
            ${cancelStayHtml ? `
            <div class="dorm-card-actions">
              ${cancelStayHtml}
            </div>` : ''}
          </div>
        </article>
      `;
    }).join('');

    // Attach click events
    grid.querySelectorAll('.dorm-card').forEach(card => {
      card.addEventListener('click', (e) => {
        if (e.target.closest('[data-cancel-stay]') || e.target.closest('.dorm-card-landlord')) return;
        const id = Number(card.dataset.id);
        const prop = allProperties.find(x => x.id === id);
        if (prop) openEnlargedModal(prop);
      });
      card.addEventListener('keydown', (e) => {
        if (e.target !== card) return;
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          const id = Number(card.dataset.id);
          const prop = allProperties.find(x => x.id === id);
          if (prop) openEnlargedModal(prop);
        }
      });
    });

    grid.querySelectorAll('[data-cancel-stay]').forEach(button => {
      button.addEventListener('click', async e => {
        e.stopPropagation();
        const id = Number(button.dataset.cancelStay);
        const prop = allProperties.find(item => item.id === id);
        if (!prop) return;

        const confirmed = await UniStayConfirm.open({
          crumb: 'UniStay • Your Stay',
          title: `Cancel your stay at ${prop.name}?`,
          message: 'Your upcoming stay at this dorm will be cancelled. You can send a new booking request later if you change your mind.',
          confirmText: 'Yes, cancel stay',
          cancelText: 'Keep my stay',
          danger: true
        });
        if (!confirmed) return;

        button.disabled = true;
        button.textContent = 'Cancelling...';
        try {
          const response = await fetch('../api/book.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ property_id: id, action: 'cancel' })
          });
          const result = await response.json();
          if (!response.ok || !result.ok) throw new Error(result.error || result.message || 'Could not cancel this stay.');
          prop.hasBooked = false;
          prop.userStayStatus = null;
          updateStayCounts();
          applyFilters();
        } catch (error) {
          alert(error.message || 'Could not cancel this stay.');
          button.disabled = false;
          button.textContent = 'Cancel Stay';
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

  // Transitions between Dorm Card & Room Details Card (Single Anchored Modal)
  function transitionToRoom(p, room) {
    openRoomDetailsModal(p, room, 'slide-right');
  }

  function transitionBackToDorm(p) {
    openEnlargedModal(p, 'slide-left');
  }

  // Enlarged Dorm Modal:
  // photo, dorm name, location, near university, description, list of rooms (Option 2),
  // percentage of students from a certain school, distance from school, landlord name
  const dormDetails = createTenantDormDetails({
    dormModal, dormModalContent, dormModalTitle, dormModalBackBtn, dormModalCloseBtn,
    IS_AUTHENTICATED, PROFILE_COMPLETED, CAN_VIEW_FULL_ROOMS, esc, nearestUniversity, landlordInitials,
    openLandlordProfile, openGateModal, updateStayCounts, applyFilters
  });
  function openEnlargedModal(p, direction) { dormDetails.showDorm(p, direction); }
  function openRoomDetailsModal(p, room, direction) { dormDetails.showRoom(p, room, direction); }

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

  function closeDormModal() {
    if (!dormModal || !dormModal.open || dormModal.classList.contains('is-closing')) return;
    dormModal.classList.add('is-closing');
    setTimeout(() => {
      dormModal.close();
      dormModal.classList.remove('is-closing');
    }, 200);
  }

  dormModalCloseBtn.addEventListener('click', () => closeDormModal());
  dormModal.addEventListener('click', (e) => {
    const rect = dormModal.getBoundingClientRect();
    if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) {
      closeDormModal();
    }
  });
  dormModal.addEventListener('cancel', (e) => {
    e.preventDefault();
    closeDormModal();
  });

  function closeAuthGateModal() {
    if (!authGateModal || !authGateModal.open || authGateModal.classList.contains('is-closing')) return;
    authGateModal.classList.add('is-closing');
    setTimeout(() => {
      authGateModal.close();
      authGateModal.classList.remove('is-closing');
    }, 200);
  }

  authGateCloseBtn.addEventListener('click', () => closeAuthGateModal());
  authGateModal.addEventListener('click', (e) => {
    const rect = authGateModal.getBoundingClientRect();
    if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) {
      closeAuthGateModal();
    }
  });
  authGateModal.addEventListener('cancel', (e) => {
    e.preventDefault();
    closeAuthGateModal();
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
    const verified = isLandlordVerified(prop);
    const statusEl = document.getElementById('landlord-modal-status');
    const statusDotEl = document.getElementById('landlord-modal-status-dot');
    const avatarContainer = document.getElementById('landlord-avatar-container');
    const nameEl = document.getElementById('landlord-modal-name');
    const contextEl = document.getElementById('landlord-modal-context');
    const sinceEl = document.getElementById('landlord-modal-since');
    const messengerContainer = document.getElementById('landlord-messenger-container');
    const phoneEl = document.getElementById('landlord-modal-phone');
    const emailEl = document.getElementById('landlord-modal-email');
    const propsListEl = document.getElementById('landlord-props-list');

    if (nameEl) nameEl.innerHTML = landlordNameHtml(landlord.name, verified);
    if (statusEl) statusEl.hidden = !verified;
    if (statusDotEl) statusDotEl.hidden = !verified;
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
      if (!<?= $isAuthenticated ? 'true' : 'false' ?>) {
        messengerContainer.innerHTML = '';
      } else {
        let messengerHtml = '';
        if (landlord.messenger) {
          messengerHtml += `
            <a href="${esc(landlord.messenger)}" target="_blank" rel="noopener noreferrer" class="landlord-messenger-btn" title="Open Messenger chat">
              <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.145 2 11.258c0 2.91 1.455 5.518 3.736 7.207V22l3.418-1.876c.9.25 1.86.386 2.846.386 5.523 0 10-4.145 10-9.252C22 6.145 17.523 2 12 2Zm1.085 12.355-2.613-2.788-5.1 2.788 5.61-5.955 2.68 2.788 5.033-2.788-5.61 5.955Z"/></svg>
              <span>Open Messenger Chat &nearr;</span>
            </a>
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
    }

    if (phoneEl) {
      if (!<?= $isAuthenticated ? 'true' : 'false' ?>) {
        phoneEl.textContent = 'Log in to view';
      } else if (landlord.phone) {
        phoneEl.innerHTML = `<a href="tel:${esc(landlord.phone)}" class="landlord-contact-val">${esc(landlord.phone)}</a>`;
      } else {
        phoneEl.textContent = 'Not provided';
      }
    }

    if (emailEl) {
      if (!<?= $isAuthenticated ? 'true' : 'false' ?>) {
        emailEl.textContent = 'Log in to view';
      } else if (landlord.email) {
        emailEl.innerHTML = `<a href="mailto:${esc(landlord.email)}" class="landlord-contact-val">${esc(landlord.email)}</a>`;
      } else {
        emailEl.textContent = 'Not provided';
      }
    }

    const countBadge = document.getElementById('landlord-props-count');

    if (propsListEl) {
      const landlordProps = allProperties.filter(x => {
        const xLandlordId = (x.landlord && x.landlord.id) || x.landlord_id;
        return xLandlordId && landlord.id && Number(xLandlordId) === Number(landlord.id);
      });

      const displayProps = landlordProps.length ? landlordProps : [prop];
      if (countBadge) countBadge.textContent = displayProps.length;

      propsListEl.innerHTML = displayProps.map(item => {
        const itemPhotos = item.photos && item.photos.length ? item.photos : (item.photo ? [item.photo] : ['../uploads/properties/st_albert_dorm.jpg']);
        const roomCount = (item.rooms || []).length;
        const roomCountText = roomCount > 0 ? `${roomCount} Room Unit${roomCount === 1 ? '' : 's'}` : 'Rooms available';
        return `
          <div class="landlord-prop-item">
            <img class="landlord-prop-thumb" src="${esc(itemPhotos[0])}" alt="${esc(item.name)}" loading="lazy">
            <div class="landlord-prop-details">
              <div class="landlord-prop-name">${esc(item.name)}</div>
              <div class="landlord-prop-uni">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m2 9 10-5 10 5-10 5Z"/><path d="M6 11v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5M22 9v6"/></svg>
                <span>Near ${esc(item.university)}</span>
              </div>
              <div class="landlord-prop-sub">
                <span class="landlord-prop-units">${esc(roomCountText)}</span>
                <span class="meta-dot"></span>
                <span class="landlord-prop-address">${esc(item.address)}</span>
              </div>
            </div>
          </div>
        `;
      }).join('');


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

  if (<?= !empty($freshLogin) ? 'true' : 'false' ?>) {
  try { sessionStorage.removeItem('unistay_profile_toast_dismissed'); } catch (e) {}
}

  // Orchestrate Initial Guide & Toast Flow:
  // For new registrations: Always launch guide first, then the completion toast upon finish/skip.
  if (IS_NEW_TENANT_REGISTRATION) {
    try {
      localStorage.removeItem('unistay_guide_viewed');
      sessionStorage.removeItem('unistay_profile_toast_dismissed');
    } catch (e) {}
  }

  function maybeShowOnboardingToast() {
    if (onboardingToastModal && SHOW_ONBOARDING_TOAST && !sessionStorage.getItem('unistay_profile_toast_dismissed')) {
      onboardingToastModal.showModal();
    }
  }

  const walkthrough = createTenantWalkthrough({
    grid,
    dormModal,
    getProperties: () => allProperties,
    openDorm: (p, dir) => openEnlargedModal(p, dir),
    openRoom: (p, room, dir) => openRoomDetailsModal(p, room, dir),
    IS_AUTHENTICATED,
    PROFILE_COMPLETED,
    CAN_VIEW_FULL_ROOMS,
    onEnd: maybeShowOnboardingToast
  });

  if (guideLauncherBtn) guideLauncherBtn.addEventListener('click', () => walkthrough.start());

  let guideHasBeenViewed = false;
  try { guideHasBeenViewed = !!localStorage.getItem('unistay_guide_viewed'); } catch (e) { }

  // Check if current page load was an explicit browser reload / refresh
  let isPageReload = false;
  try {
    const navEntries = performance.getEntriesByType('navigation');
    if (navEntries.length > 0 && navEntries[0].type === 'reload') {
      isPageReload = true;
    }
  } catch (e) { }

  // If reloaded, clear visitor tour session flag so it replays upon refresh
  if (isPageReload) {
    try { sessionStorage.removeItem('unistay_visitor_tour_played'); } catch (e) { }
  }

  let visitorTourPlayed = false;
  try { visitorTourPlayed = sessionStorage.getItem('unistay_visitor_tour_played') === '1'; } catch (e) { }

  // Auto-play tutorial ONCE upon entering visitor view (!IS_AUTHENTICATED && !visitorTourPlayed)
  // or when completed profile first loads
  const SHOULD_AUTO_TOUR = FORCE_TENANT_TOUR || (!IS_AUTHENTICATED && !visitorTourPlayed) || (PROFILE_COMPLETED && (!guideHasBeenViewed || IS_NEW_TENANT_REGISTRATION));
  if (!IS_AUTHENTICATED && SHOULD_AUTO_TOUR) {
    try { sessionStorage.setItem('unistay_visitor_tour_played', '1'); } catch (e) { }
  }
  if (!SHOULD_AUTO_TOUR) maybeShowOnboardingToast();

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

    placeStayPill(true);
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

  const stayTabs = document.querySelector('.dorms-stay-filters');
  const stayPill = stayTabs.querySelector('.stay-pill');
  let stayPillReady = false;

  function placeStayPill(instant) {
    const active = stayTabs.querySelector('.stay-filter-btn.is-active');
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

  document.querySelectorAll('.stay-filter-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.stay-filter-btn').forEach(b => {
        b.classList.remove('is-active');
        b.setAttribute('aria-selected', 'false');
      });
      btn.classList.add('is-active');
      btn.setAttribute('aria-selected', 'true');
      currentStayFilter = btn.dataset.stay;
      placeStayPill();
      applyFilters();
    });
  });

  placeStayPill(true);
  window.addEventListener('load', () => placeStayPill(true));
  window.addEventListener('resize', () => placeStayPill(true));
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => placeStayPill(true));

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
      allProperties = sortForTenant(await Store.all());
      updateStayCounts();
      renderCards(allProperties);

      // Deep link support (?property=101&room=1 or ?property=101)
      const params = new URLSearchParams(window.location.search);
      const propId = params.get('property');
      if (SHOULD_AUTO_TOUR && !propId) walkthrough.start();
      const roomId = params.get('room');
      const focusContact = params.get('contact') === '1';
      if (propId) {
        const targetProp = allProperties.find(x => String(x.id) === String(propId));
        if (targetProp) {
          if (roomId) {
            const targetRoom = (targetProp.rooms || []).find(r => String(r.id) === String(roomId));
            if (targetRoom) {
              // Open the dorm first, then slide into the specific room card
              openEnlargedModal(targetProp);
              setTimeout(() => openRoomDetailsModal(targetProp, targetRoom, 'slide-right'), 380);
              if (focusContact) {
                setTimeout(() => {
                  const landlordCard = dormModalContent.querySelector('#room-landlord-btn');
                  if (!landlordCard) return;
                  landlordCard.classList.add('contact-attention');
                  landlordCard.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
                  landlordCard.focus({ preventScroll: true });
                }, 520);
              }
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
<script>
window.UNISTAY_NOTIFICATION_ACCOUNT = <?= json_encode(['id' => (int)($_SESSION['user_id'] ?? 0), 'role' => 'tenant']) ?>;
</script>
<script src="../assets/js/notification-preferences.js?v=2"></script>
<script src="../assets/js/tenant/notifications.js?v=7"></script>
</body>
</html>
