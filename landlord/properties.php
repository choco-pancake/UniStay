<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

$authenticatedUser = require_signed_in('landlord');
$pdo = database();
$user_id = (int)($_SESSION['user_id'] ?? 0);
$landlordProfile = get_authenticated_profile($pdo, $user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Properties | Landlord - UniStay</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="../assets/css/common.css?v=28">
  <link rel="stylesheet" href="../assets/css/dorms.css?v=28">
  <link rel="stylesheet" href="../assets/css/landlord-properties.css?v=26">
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="../assets/js/tenant/popover.js"></script>
  <script src="../assets/js/tenant/sidebar.js"></script>
  <script src="../assets/js/tenant/profile-menu.js"></script>
</head>
<body class="dorms-page landlord-properties-page">
  <svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" style="display:none;"><defs>
    <symbol id="grid-icon" viewBox="0 0 24 24"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></symbol>
    <symbol id="bed-icon" viewBox="0 0 24 24"><path d="M3 19V9h18v10M3 16h18M5 9V5h5v4m4 0V5h5v4"/></symbol>
    <symbol id="door-icon" viewBox="0 0 24 24"><path d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m-2 0h18M14 12v.01" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    <symbol id="map-icon" viewBox="0 0 24 24"><path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2Zm6-2v16m6-14v16"/></symbol>
    <symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
    <symbol id="bell-icon" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M10 20h4"/></symbol>
    <symbol id="bell-off-icon" viewBox="0 0 24 24"><path d="m3 3 18 18M10 20h4M6 6c-1 2 0 7-3 10h13M18 13V8a6 6 0 0 0-8-6"/></symbol>
    <symbol id="arrow-icon" viewBox="0 0 24 24"><path d="M4 12h16m-6-6 6 6-6 6"/></symbol>
    <symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
    <symbol id="search-icon" viewBox="0 0 24 24"><circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/></symbol>
    <symbol id="caret-icon" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></symbol>
    <symbol id="pin-icon" viewBox="0 0 24 24"><path d="M19 9c0 5-7 12-7 12S5 14 5 9a7 7 0 0 1 14 0Z"/><circle cx="12" cy="9" r="2"/></symbol>
    <symbol id="school-icon" viewBox="0 0 24 24"><path d="m2 9 10-5 10 5-10 5Z"/><path d="M6 11v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5M22 9v6"/></symbol>
    <symbol id="plus-icon" viewBox="0 0 24 24"><path d="M12 5v14m-7-7h14"/></symbol>
  </defs></svg>

  <a class="skip" href="#properties-main">Skip to content</a>

  <!-- UniStay Sidebar -->
  <aside class="sidebar" aria-label="UniStay Landlord Portal">
    <div class="brand">
      <img src="../assets/images/tenant/logo.png" width="50" height="50" alt="">
      <span class="brand-name">UniStay</span>
    </div>
    <nav aria-label="Main navigation">
      <a href="dashboard.php" title="Dashboard"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
      <a href="properties.php" title="Properties" aria-current="page"><svg aria-hidden="true"><use href="#bed-icon"/></svg><span class="nav-label">Properties</span></a>
      <a href="rooms.php" title="Rooms"><svg aria-hidden="true"><use href="#door-icon"/></svg><span class="nav-label">Rooms</span></a>
    </nav>
    <button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true">
      <span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span>
      <span class="toggle-label">Collapse</span>
    </button>
  </aside>

  <!-- Main Shell -->
  <div class="shell">
    <div class="shell-orbs" aria-hidden="true"><i></i><i></i><i></i></div>
    <header class="topbar">
      <h1>Properties</h1>
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
        <button class="avatar" type="button" aria-label="Open profile menu" aria-expanded="false" aria-controls="profile-popover" <?= !empty($landlordProfile['photo']) ? 'style="background-image:url(\''.htmlspecialchars($landlordProfile['photo'], ENT_QUOTES, 'UTF-8').'\');"' : '' ?>>
          <?php if (!empty($landlordProfile['photo'])): ?>
            <img src="<?= htmlspecialchars($landlordProfile['photo']) ?>" alt="" referrerpolicy="no-referrer" class="avatar-img" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline-flex';">
            <span style="display:none;"><?= htmlspecialchars($landlordProfile['initials'] ?? 'L') ?></span>
          <?php else: ?>
            <?= htmlspecialchars($landlordProfile['initials'] ?? 'L') ?>
          <?php endif; ?>
        </button>

        <!-- Notification Popover -->
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

    <main id="properties-main" tabindex="-1">
      <!-- Toolbar matching Tenant Dorms -->
      <div class="um-toolbar">
        <div class="um-toolbar-left">
          <label class="um-search">
            <svg aria-hidden="true"><use href="#search-icon"/></svg>
            <input id="prop-search" type="search" placeholder="Search my properties..." autocomplete="off">
          </label>
          <div class="um-select" id="uni-select">
            <button type="button" class="um-select-btn is-placeholder" aria-haspopup="listbox" aria-expanded="false">
              <span class="um-select-value">Filter by university</span>
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
        <div class="um-toolbar-right">
          <button type="button" id="openAddModalBtn" class="btn-add-property">
            <svg aria-hidden="true" width="18" height="18"><use href="#plus-icon"/></svg>
            Add a Property
          </button>
        </div>
      </div>

      <!-- Properties Grid matching Tenant Dorms -->
      <div class="dorms-grid" id="dorms-grid" aria-live="polite">
        <!-- Rendered dynamically by landlord-properties.js -->
      </div>
    </main>
  </div>

  <!-- Expanded Floating Workstation Modal (#modalOverlay) -->
  <div id="modalOverlay" hidden>
    <div class="modal-card">
      <div class="modal-top-bar">
        <div class="modal-title-group">
          <h2 id="modalMainHeading">Add a Property</h2>
          <span class="modal-step-badge" id="stepBadge">Step 1 of 2: Property Details</span>
        </div>
        <button type="button" id="closeModalBtn" class="modal-close-btn" aria-label="Close dialog">✕</button>
      </div>

      <form id="propForm" novalidate>
        <input type="hidden" name="property_id" id="editPropId" value="">
        <!-- PAGE 1: Property Details (Split 2-Column Workstation) -->
        <div class="add-prop-page1-grid" id="page1Grid">
          <!-- LEFT COLUMN: All inputs in strict order separated by clean dividers -->
          <div class="form-col-left">
            <!-- 1. Dorm Name -->
            <div class="field-block">
              <label class="field-label" for="propName">Dorm Name *</label>
              <input id="propName" name="name" class="field-input" required placeholder="e.g. Sunrise Residences" autocomplete="off">
            </div>

            <hr class="form-section-divider">

            <!-- 2. Near which university -->
            <div class="field-block">
              <span class="field-label">Near which university *</span>
              <p class="field-help">Click a university to jump to it on the map and set your allowed area (1 km max):</p>
              <input type="hidden" name="university" id="universityInput" value="">
              <div class="uni-picker-grid">
                <button type="button" class="uni-picker-btn" data-uni="Universidad de Dagupan">
                  <strong>UDD</strong>
                  <span>Universidad de Dagupan</span>
                </button>
                <button type="button" class="uni-picker-btn" data-uni="University of Pangasinan">
                  <strong>UPang</strong>
                  <span>University of Pangasinan</span>
                </button>
                <button type="button" class="uni-picker-btn" data-uni="University of Luzon">
                  <strong>UL</strong>
                  <span>University of Luzon</span>
                </button>
                <button type="button" class="uni-picker-btn" data-uni="Lyceum Northwestern University">
                  <strong>LNU</strong>
                  <span>Lyceum Northwestern University</span>
                </button>
              </div>
            </div>

            <hr class="form-section-divider">

            <!-- 3. Location Setting Method & Address with Live Suggestions -->
            <div class="field-block">
              <span class="field-label">Location setting method:</span>
              <div class="location-mode-row">
                <button type="button" class="loc-mode-btn is-active" data-mode="type">⌨️ Type the address</button>
                <button type="button" class="loc-mode-btn" data-mode="map">📍 Pin on the map</button>
              </div>
              <input type="hidden" name="locMode" id="locModeInput" value="type">
            </div>

            <div class="field-block">
              <label class="field-label" for="addressInput">Address / location *</label>
              <div class="loc-input-wrap">
                <input id="addressInput" name="address" class="field-input" required autocomplete="off" placeholder="Start typing street, barangay, or landmark in Dagupan...">
                <!-- Live Suggestions Dropdown Container -->
                <div id="locSuggestions" class="loc-suggestions-box" hidden></div>
              </div>
              <p id="addrHint" class="field-help" style="margin-top:4px;">Format: <b>Street, Barangay, City</b>. Typing reveals suggestions; clicking pins the spot.</p>
            </div>

            <hr class="form-section-divider">

            <!-- 4. Description (Placed below address/location) -->
            <div class="field-block">
              <label class="field-label" for="propDesc">Description</label>
              <textarea id="propDesc" name="description" rows="3" class="field-textarea" placeholder="Tell students about your dorm, house rules, facilities, study atmosphere..."></textarea>
            </div>

            <hr class="form-section-divider">

            <!-- 5. Dorm Photos at the bottom -->
            <div class="field-block">
              <span class="field-label">Dorm Photos * <small class="field-help">(Upload 1 to 3 photos)</small></span>
              <div id="photoZone" class="photo-drop-box">
                <div id="photoPromptWrap" class="photo-prompt-wrap">
                  <div class="photo-drop-icon">📷</div>
                  <span class="photo-drop-title">Drag &amp; drop photos here</span>
                  <span class="photo-drop-hint">or click to browse · JPG, PNG, WebP up to 5 MB (1–3 photos)</span>
                </div>
                <div id="photoPreview" class="photo-preview-grid" style="display:none;"></div>
                <p id="photoCount" class="field-help" style="display:none;"></p>
                <input id="photoInput" type="file" accept="image/*" multiple style="display:none;">
              </div>
            </div>
          </div>

          <!-- RIGHT COLUMN: Leaflet Map (Fills 100% of the right workstation) -->
          <div class="form-col-right">
            <div class="map-panel-box full-height-map-box">
              <div class="field-label" style="display:flex;justify-content:space-between;align-items:center;">
                <span>Property Location on Map *</span>
                <span class="field-help" style="font-weight:400;margin:0;">Click map to adjust pin position</span>
              </div>
              <div class="map-wrapper full-height-map-wrapper">
                <div id="pickMap" class="um-map" style="height:100%; width:100%;"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Page 1 Footer -->
        <div class="modal-footer" id="page1Footer">
          <button type="button" id="btnEditBackToProperty" class="btn-secondary" hidden>
            ← Back to Property
          </button>
          <p id="errorMsg1" class="modal-footer-error" hidden></p>
          <div style="margin-left:auto;">
            <button type="button" id="btnPage1Continue" class="btn-primary">
              Continue to Rooms &amp; Rates →
            </button>
          </div>
        </div>

        <!-- PAGE 2: Rooms & Rates -->
        <div class="add-prop-page2-grid" id="page2Grid" hidden>
          <div class="rooms-section-header">
            <div>
              <h3>Rooms &amp; Inventory</h3>
              <p class="field-help" style="margin:2px 0 0;">Add the rooms available in this property, set monthly rent and optional deposit.</p>
            </div>
          </div>
          <div id="rooms" class="rooms-container"></div>
          <button type="button" id="addRoom" class="add-room-dashed-btn">
            <span class="add-room-icon">＋</span>
            <span class="add-room-title">Add Another Room / Unit</span>
            <span class="add-room-sub">Have more rooms with different pricing or amenities? Click to add another room form.</span>
          </button>
        </div>

        <!-- Page 2 Footer -->
        <div class="modal-footer" id="page2Footer" hidden>
          <button type="button" id="btnPage2Back" class="btn-secondary">
            ← Back to Details
          </button>
          <p id="errorMsg2" class="modal-footer-error" hidden></p>
          <button type="submit" id="savePropBtn" class="btn-primary">
            Save Property
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Enlarged Floating Dorm Inspection Modal (#dorm-modal) -->
  <dialog id="dorm-modal" class="dorm-modal" aria-labelledby="dorm-modal-title">
    <button type="button" class="dorm-modal-close" id="dorm-modal-close-btn" aria-label="Close dialog">✕</button>
    <div class="dorm-modal-body" id="dorm-modal-content">
      <!-- Populated dynamically by JavaScript -->
    </div>
  </dialog>

  <div id="toast" class="toast-popup" style="display:none;">Property saved!</div>

  <!-- Client-side bootstrap -->
  <script>
    window.UNISTAY_TENANT_PROFILE = <?= json_encode($landlordProfile) ?>;
    window.CURRENT_LANDLORD = <?= json_encode($landlordProfile) ?>;
  </script>
  <script src="../assets/js/store.js"></script>
  <script src="../assets/js/landlord-properties.js?v=28"></script>
  <script src="../assets/js/landlord-notifications.js?v=2"></script>
</body>
</html>