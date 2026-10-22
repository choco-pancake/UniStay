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
    if (!empty($_SESSION['show_profile_completion_toast'])) {
        $showOnboardingToast = true;
        unset($_SESSION['show_profile_completion_toast']);
    } elseif (!$profileCompleted) {
        $showOnboardingToast = true;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#7a4b3a">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/common.css?v=23">
<link rel="stylesheet" href="../assets/css/dorms.css?v=23">
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
      <a href="rooms.php" title="Bookings"><svg aria-hidden="true"><use href="#booking-icon"/></svg><span class="nav-label">Bookings</span></a>
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
      <?php if ($isAuthenticated): ?>
        <button id="notification-bell" class="bell" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="notification-popover">
          <svg aria-hidden="true"><use href="#bell-icon"/></svg>
        </button>
        <button class="avatar" type="button" aria-label="Open profile menu" aria-expanded="false" aria-controls="profile-popover">
          <?php if (!empty($tenantProfile['photo'])): ?>
            <img src="<?= htmlspecialchars($tenantProfile['photo']) ?>" alt="<?= htmlspecialchars($tenantProfile['name']) ?>" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">
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
<script id="notification-data" type="application/json">[]</script>
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
  const SHOW_ONBOARDING_TOAST = <?= $showOnboardingToast ? 'true' : 'false' ?>;
  const grid = document.getElementById('dorms-grid');
  const searchInput = document.getElementById('dorm-search');
  const uniSelect = document.getElementById('uni-select');
  const uniBtn = uniSelect.querySelector('.um-select-btn');
  const uniValue = uniSelect.querySelector('.um-select-value');
  const uniMenu = uniSelect.querySelector('.um-select-menu');
  const dormModal = document.getElementById('dorm-modal');
  const dormModalCloseBtn = document.getElementById('dorm-modal-close-btn');
  const dormModalContent = document.getElementById('dorm-modal-content');
  const authGateModal = document.getElementById('auth-gate-modal');
  const authGateCloseBtn = document.getElementById('auth-gate-close-btn');
  const onboardingToastModal = document.getElementById('onboarding-toast-modal');
  const btnToastLater = document.getElementById('btn-toast-later');

  let allProperties = [];
  let currentFilter = '';
  let currentSearch = '';

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

  // Render cards containing strictly:
  // photo, dorm name, location, near which university, landlord name, view details button
  function renderCards(list) {
    if (!list.length) {
      grid.innerHTML = `
        <div class="dorms-empty">
          <div class="dorms-empty-icon"><svg><use href="#bed-icon"/></svg></div>
          <h3>No dormitories found</h3>
          <p>Try searching for a different dorm name or picking another university.</p>
        </div>
      `;
      return;
    }

    grid.innerHTML = list.map((p, idx) => {
      const photos = p.photos && p.photos.length ? p.photos : (p.photo ? [p.photo] : ['../uploads/properties/st_albert_dorm.jpg']);
      const coverPhoto = photos[0];
      const initials = landlordInitials(p.landlordName);

      return `
        <article class="dorm-card" data-id="${p.id}" tabindex="0" role="button" aria-label="View ${esc(p.name)}">
          <div class="dorm-card-photo-wrap">
            <img class="dorm-card-photo" src="${esc(coverPhoto)}" alt="${esc(p.name)}" loading="lazy">
            <span class="dorm-card-uni-badge">
              <svg><use href="#school-icon"/></svg>
              ${esc(p.university)}
            </span>
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
            <div class="dorm-card-landlord">
              <span class="landlord-avatar-small" aria-hidden="true">${esc(initials)}</span>
              <span>Landlord: <strong>${esc(p.landlordName || 'Verified Landlord')}</strong></span>
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
  }

  // Enlarged Dorm Modal:
  // photo, dorm name, location, near university, description, list of rooms (Option 2),
  // percentage of students from a certain school, distance from school, landlord name
  function openEnlargedModal(p) {
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

    // Carousel HTML
    const carouselHtml = `
      <div class="dorm-modal-carousel">
        <img src="${esc(photos[0])}" alt="${esc(p.name)}" id="modal-main-img">
      </div>
    `;

    // Rooms list formatting (Option 2: Frosted Glass Overlay)
    let roomsHtml = '';
    if (!rooms.length) {
      roomsHtml = '<p style="color:#78665c;">No room units listed currently.</p>';
    } else {
      const firstRoom = rooms[0];
      const otherRooms = rooms.slice(1);

      const firstRoomHtml = `
        <div class="room-card-preview">
          <div class="room-photo-ph">
            ${firstRoom.photos && firstRoom.photos.length ? `<img src="${esc(firstRoom.photos[0])}" alt="">` : `<svg><use href="#bed-icon"/></svg>`}
          </div>
          <div class="room-card-info">
            <h4>${esc(firstRoom.name)}</h4>
            <p>${esc(firstRoom.type)} · ${firstRoom.capacity} Bed${firstRoom.capacity === 1 ? '' : 's'}</p>
            <p class="room-price">${fmtPrice(firstRoom.rent)} / month</p>
            <button type="button" class="btn-view-room btn-trigger-auth" data-room-id="${firstRoom.id}">
              View Room <svg><use href="#arrow-icon"/></svg>
            </button>
          </div>
        </div>
      `;

      let othersHtml = '';
      if (otherRooms.length > 0) {
        if (CAN_VIEW_FULL_ROOMS) {
          // If profile completed, show all rooms cleanly
          othersHtml = otherRooms.map(r => `
            <div class="room-card-preview">
              <div class="room-photo-ph">
                ${r.photos && r.photos.length ? `<img src="${esc(r.photos[0])}" alt="">` : `<svg><use href="#bed-icon"/></svg>`}
              </div>
              <div class="room-card-info">
                <h4>${esc(r.name)}</h4>
                <p>${esc(r.type)} · ${r.capacity} Bed${r.capacity === 1 ? '' : 's'}</p>
                <p class="room-price">${fmtPrice(r.rent)} / month</p>
                <a href="rooms.php?property=${p.id}&room=${r.id}" class="btn-view-room">
                  View Room <svg><use href="#arrow-icon"/></svg>
                </a>
              </div>
            </div>
          `).join('');
        } else {
          // Frosted Glass Overlay for visitors AND users with incomplete profiles
          const unlockBtnText = IS_AUTHENTICATED ? 'Complete profile to unlock' : 'Log in to unlock';
          const unlockDescText = IS_AUTHENTICATED 
            ? 'Complete your profile in Settings to unlock all room options, see full photos, and contact landlords.'
            : 'Sign in to unlock all room options, see full photos, and check real-time availability.';

          const blurredPreview = otherRooms.slice(0, 2).map(r => `
            <div class="room-card-preview" style="margin-bottom:8px;">
              <div class="room-photo-ph"><svg><use href="#bed-icon"/></svg></div>
              <div class="room-card-info">
                <h4>${esc(r.name)}</h4>
                <p>${esc(r.type)}</p>
                <p class="room-price">${fmtPrice(r.rent)} / month</p>
              </div>
            </div>
          `).join('');

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
        <div class="dorm-modal-landlord">
          <span class="dorm-modal-landlord-avatar">${esc(initials)}</span>
          <div>
            <div style="font-weight:700; color:#3b2820;">${esc(p.landlordName || 'Verified Landlord')}</div>
            <div style="font-size:0.82rem; color:#8b7467;">Property Landlord</div>
          </div>
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

    // Hook up room action triggers
    dormModalContent.querySelectorAll('.btn-trigger-auth').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (!CAN_VIEW_FULL_ROOMS) {
          openGateModal();
        } else {
          const roomId = btn.dataset.roomId;
          window.location.href = `rooms.php?property=${p.id}&room=${roomId}`;
        }
      });
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

    dormModal.showModal();
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
    const isDeferred = sessionStorage.getItem('unistay_profile_toast_dismissed');
    if (!isDeferred) {
      onboardingToastModal.showModal();
    }

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

  // Filter & Search handler
  function applyFilters() {
    const q = currentSearch.trim().toLowerCase();
    const filtered = allProperties.filter(p => {
      const matchUni = !currentFilter || p.university === currentFilter;
      const matchSearch = !q || p.name.toLowerCase().includes(q);
      return matchUni && matchSearch;
    });
    renderCards(filtered);
  }

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
      renderCards(allProperties);
    } catch (err) {
      console.error('Failed to load dormitories', err);
      grid.innerHTML = '<p class="dorms-empty">Failed to load dormitories. Please try again later.</p>';
    }
  })();
})();
</script>
</body>
</html>
