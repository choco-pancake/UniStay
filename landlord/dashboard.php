<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

$authenticatedUser = require_signed_in('landlord');
$pdo = database();
$user_id = (int)($_SESSION['user_id'] ?? 0);
$profileIncomplete = !landlord_profile_complete($pdo, $user_id);
$userName = trim((string)($authenticatedUser['name'] ?? 'Landlord')) ?: 'Landlord';
$initials = strtoupper(substr($userName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniStay - Landlord Dashboard</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../assets/css/landlord-dashboard.css">
    <style>
        /* Profile Completion Toast */
        .profile-toast {
          position: fixed;
          bottom: 24px;
          right: 24px;
          z-index: 9999;
          width: min(calc(100vw - 32px), 430px);
          background: #ffffff;
          border-radius: 18px;
          box-shadow: 0 14px 40px rgba(60, 30, 20, 0.18), 0 2px 8px rgba(60, 30, 20, 0.08);
          border: 1px solid #ebd9cb;
          overflow: hidden;
          animation: toast-slide-up 0.35s cubic-bezier(0.16, 1, 0.3, 1) both;
          transition: opacity 0.25s ease, transform 0.25s ease;
        }
        .profile-toast.is-dismissing {
          opacity: 0;
          transform: translateY(16px) scale(0.96);
          pointer-events: none;
        }
        .profile-toast-inner {
          display: flex;
          align-items: flex-start;
          gap: 14px;
          padding: 18px 20px 16px;
        }
        .profile-toast-icon {
          width: 44px;
          height: 44px;
          border-radius: 12px;
          background: #fbf4ee;
          color: #7a4b3a;
          display: grid;
          place-items: center;
          flex-shrink: 0;
        }
        .profile-toast-icon svg {
          width: 22px;
          height: 22px;
        }
        .profile-toast-body {
          flex: 1;
          min-width: 0;
        }
        .profile-toast-header {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 12px;
          margin-bottom: 4px;
        }
        .profile-toast-header strong {
          font-size: 15px;
          font-weight: 700;
          color: #3b2820;
        }
        .profile-toast-close {
          background: none;
          border: none;
          font-size: 22px;
          line-height: 1;
          color: #9a8c84;
          cursor: pointer;
          padding: 0 4px;
        }
        .profile-toast-close:hover {
          color: #3b2820;
        }
        .profile-toast-body p {
          margin: 0 0 14px;
          font-size: 13.5px;
          line-height: 1.45;
          color: #6b584d;
        }
        .profile-toast-actions {
          display: flex;
          align-items: center;
          justify-content: flex-end;
          gap: 10px;
        }
        .toast-btn {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          padding: 8px 18px;
          border-radius: 10px;
          font-size: 13px;
          font-weight: 600;
          cursor: pointer;
          text-decoration: none;
          transition: all 0.18s ease;
        }
        .toast-btn--secondary {
          border: 1px solid #d8c3b4;
          background: #ffffff;
          color: #6b584d;
        }
        .toast-btn--secondary:hover {
          background: #fbf8f5;
          border-color: #bfa593;
          color: #3b2820;
        }
        .toast-btn--primary {
          border: none;
          background: linear-gradient(135deg, #7a4b3a 0%, #633b2c 100%);
          color: #ffffff;
          box-shadow: 0 4px 12px rgba(122, 75, 58, 0.25);
        }
        .toast-btn--primary:hover {
          filter: brightness(1.06);
        }
        @keyframes toast-slide-up {
          from { opacity: 0; transform: translateY(24px) scale(0.96); }
          to { opacity: 1; transform: translateY(0) scale(1); }
        }
        @media (max-width: 520px) {
          .profile-toast { bottom: 16px; right: 16px; left: 16px; width: auto; }
        }
    </style>
</head>
<body>

<div class="app-container">
    
    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-university"></i></div>
            <h2>UniStay</h2>
        </div>
        
        <nav class="nav-menu">
            <a href="dashboard.php" class="nav-item active"><i class="fas fa-th-large"></i> Dashboard</a>
            <a href="properties.php" class="nav-item"><i class="fas fa-home"></i> Properties</a>
            <a href="rooms.php" class="nav-item"><i class="fas fa-door-open"></i> Rooms</a>
            <a href="../tenant/map.php" class="nav-item"><i class="fas fa-map"></i> Map</a>
            <a href="settings.php" class="nav-item"><i class="fas fa-cog"></i> Settings and profile</a>
        </nav>

        <div class="nav-menu bottom-nav">
            <a href="../auth/logout.php" class="nav-item logout"><i class="fas fa-sign-out-alt"></i> Log out</a>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">
        
        <!-- TOP HEADER -->
        <header class="top-header">
            <h1>Dashboard</h1>
            
            <div class="header-actions">
                <!-- Notifications -->
                <a href="notifications.php" class="bell-icon-wrapper" aria-label="Notifications">
                    <i class="far fa-bell notification-icon"></i>
                    <span class="notification-badge">3</span>
                </a>

                <!-- User Profile Info -->
                <div class="user-profile-header">
                    <div class="user-avatar-small">
                        <span style="font-weight:700; color:#5c3527; font-size:14px;"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="user-info">
                        <span class="user-name"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="user-role">Landlord</span>
                    </div>
                </div>

                <!-- Profile Dropdown Button -->
                <a href="settings.php" class="profile-btn">
                    Profile <i class="fas fa-chevron-down"></i>
                </a>
            </div>
        </header>

        <!-- WELCOME SECTION (UPDATED WITH DYNAMIC SPAN) -->
        <div class="welcome-section">
            <div class="welcome-text">
                <h2>Welcome back, <span id="userWelcomeName"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span></h2>
                <p>Here's an overview of your rental properties.</p>
            </div>
            <a href="properties.php" class="btn-primary"><i class="fas fa-plus"></i> Add property</a>
        </div>

        <!-- STATS CARDS -->
        <div class="stats-grid">
            <div class="stat-card">
                <p class="stat-label">TOTAL PROPERTIES</p>
                <h3 class="stat-value">4</h3>
                <p class="stat-sub">Listed on UniStay</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">TOTAL ROOMS</p>
                <h3 class="stat-value">24</h3>
                <p class="stat-sub">Across all properties</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">OCCUPANCY RATE</p>
                <h3 class="stat-value">75%</h3>
                <p class="stat-sub">18 rooms occupied</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">ACCOUNT STATUS</p>
                <h3 class="stat-value verified-text">Verified</h3>
                <p class="stat-sub">Profile and documents approved</p>
            </div>
        </div>

        <!-- DASHBOARD GRID (Properties & Bookings) -->
        <div class="dashboard-grid">
            
            <!-- Left Column: My Properties -->
            <div class="section-container">
                <div class="section-header">
                    <h2>My properties</h2>
                    <a href="properties.php" class="view-all">View all →</a>
                </div>
                
                <div class="list-container">
                    <!-- Property Item 1 -->
                    <div class="property-item">
                        <div class="item-img">
                            <img src="data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100'%3E%3Crect width='100%25' height='100%25' fill='%23d1d1d1'/%3E%3Ctext x='50%25' y='50%25' fill='%238b8079' font-family='sans-serif' font-size='12' text-anchor='middle' dy='.3em'%3EProp%3C/text%3E%3C/svg%3E" alt="Property">
                        </div>
                        <div class="item-info">
                            <h4>Sunrise Residences</h4>
                            <p class="item-sub">12 Rizal St, Daguapan City</p>
                            <p class="item-sub">Near University of Luzon • 8 rooms</p>
                        </div>
                    </div>

                    <!-- Property Item 2 -->
                    <div class="property-item">
                        <div class="item-img">
                            <img src="data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100'%3E%3Crect width='100%25' height='100%25' fill='%23d1d1d1'/%3E%3Ctext x='50%25' y='50%25' fill='%238b8079' font-family='sans-serif' font-size='12' text-anchor='middle' dy='.3em'%3EProp%3C/text%3E%3C/svg%3E" alt="Property">
                        </div>
                        <div class="item-info">
                            <h4>Greenview Dorm</h4>
                            <p class="item-sub">8 Bonuan Gueset Rd, Daguapan City</p>
                            <p class="item-sub">Near University of Pangasinan • 10 rooms</p>
                        </div>
                    </div>

                    <!-- Property Item 3 -->
                    <div class="property-item">
                        <div class="item-img">
                            <img src="data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100'%3E%3Crect width='100%25' height='100%25' fill='%23d1d1d1'/%3E%3Ctext x='50%25' y='50%25' fill='%238b8079' font-family='sans-serif' font-size='12' text-anchor='middle' dy='.3em'%3EProp%3C/text%3E%3C/svg%3E" alt="Property">
                        </div>
                        <div class="item-info">
                            <h4>The Study House</h4>
                            <p class="item-sub">35 Arellano St, Daguapan City</p>
                            <p class="item-sub">Near Universidad de Daguapan • 6 rooms</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Recent Bookings -->
            <div class="section-container">
                <div class="section-header">
                    <h2>Recent bookings</h2>
                    <a href="tenants.php" class="view-all">View all →</a>
                </div>
                
                <div class="list-container">
                    <!-- Booking Item 1 -->
                    <div class="booking-item-card">
                        <div class="item-img">
                            <img src="data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100'%3E%3Crect width='100%25' height='100%25' fill='%23d1d1d1'/%3E%3Ctext x='50%25' y='50%25' fill='%238b8079' font-family='sans-serif' font-size='12' text-anchor='middle' dy='.3em'%3ERoom%3C/text%3E%3C/svg%3E" alt="Room">
                        </div>
                        <div class="item-info">
                            <h4>Room 204 - Twin</h4>
                            <p class="item-sub">Sunrise Residences • Daguapan City</p>
                            <p class="item-sub">Near University of Luzon • 6 months</p>
                            <p class="item-tenant">Alex Mendoza • Tenant</p>
                            <a href="tenants.php" class="btn-outline">View details</a>
                        </div>
                    </div>

                    <!-- Booking Item 2 -->
                    <div class="booking-item-card">
                        <div class="item-img">
                            <img src="data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100'%3E%3Crect width='100%25' height='100%25' fill='%23d1d1d1'/%3E%3Ctext x='50%25' y='50%25' fill='%238b8079' font-family='sans-serif' font-size='12' text-anchor='middle' dy='.3em'%3ERoom%3C/text%3E%3C/svg%3E" alt="Room">
                        </div>
                        <div class="item-info">
                            <h4>Room 105 - Single</h4>
                            <p class="item-sub">Greenview Dorm • Daguapan City</p>
                            <p class="item-sub">Near University of Pangasinan • 4 months</p>
                            <p class="item-tenant">Sofia Lim • Tenant</p>
                            <a href="tenants.php" class="btn-outline">View details</a>
                        </div>
                    </div>

                    <!-- Booking Item 3 -->
                    <div class="booking-item-card">
                        <div class="item-img">
                            <img src="data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100'%3E%3Crect width='100%25' height='100%25' fill='%23d1d1d1'/%3E%3Ctext x='50%25' y='50%25' fill='%238b8079' font-family='sans-serif' font-size='12' text-anchor='middle' dy='.3em'%3ERoom%3C/text%3E%3C/svg%3E" alt="Room">
                        </div>
                        <div class="item-info">
                            <h4>Room 302 - Quad</h4>
                            <p class="item-sub">The Study House • Daguapan City</p>
                            <p class="item-sub">Near Universidad de Daguapan • 1 year</p>
                            <p class="item-tenant">Miguel Reyes • Tenant</p>
                            <a href="tenants.php" class="btn-outline">View details</a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<?php if ($profileIncomplete): ?>
<aside class="profile-toast" id="profileToast" role="alert" aria-live="polite">
    <div class="profile-toast-inner">
        <div class="profile-toast-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                <circle cx="12" cy="7" r="4"></circle>
            </svg>
        </div>
        <div class="profile-toast-body">
            <div class="profile-toast-header">
                <strong>Finish setting up your profile</strong>
                <button type="button" class="profile-toast-close" id="profileToastDismiss" aria-label="Dismiss toast">&times;</button>
            </div>
            <p>Would you like to complete your landlord profile information now or finish it later?</p>
            <div class="profile-toast-actions">
                <button type="button" class="toast-btn toast-btn--secondary" id="toastBtnLater">Later</button>
                <a href="properties.php" class="toast-btn toast-btn--primary" id="toastBtnNow">Now</a>
            </div>
        </div>
    </div>
</aside>
<script>
(function () {
  var toast = document.getElementById('profileToast');
  if (!toast) return;

  if (sessionStorage.getItem('unistay_landlord_toast_dismissed') === '1') {
    toast.remove();
    return;
  }

  function dismiss() {
    toast.classList.add('is-dismissing');
    sessionStorage.setItem('unistay_landlord_toast_dismissed', '1');
    setTimeout(function () {
      toast.remove();
    }, 260);
  }

  var btnLater = document.getElementById('toastBtnLater');
  var btnClose = document.getElementById('profileToastDismiss');

  if (btnLater) btnLater.addEventListener('click', dismiss);
  if (btnClose) btnClose.addEventListener('click', dismiss);
})();
</script>
<?php endif; ?>

<!-- Custom JS -->
<script src="../assets/js/landlord-dashboard.js" defer></script>
</body>
</html>
