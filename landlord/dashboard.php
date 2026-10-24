<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

$authenticatedUser = require_signed_in('landlord');
$pdo = database();
$userId = (int)($_SESSION['user_id'] ?? 0);
$landlordProfile = get_authenticated_profile($pdo, $userId);
$profileIncomplete = !landlord_profile_complete($pdo, $userId);

$userName = $landlordProfile['name'] ?: 'Landlord';
$initials = $landlordProfile['initials'] ?: 'L';
$avatarPhoto = $landlordProfile['photo'] ?? '';

// 1. Total Properties for this landlord
$stmt = $pdo->prepare('SELECT COUNT(*) FROM properties WHERE landlord_id = ?');
$stmt->execute([$userId]);
$totalProperties = (int)$stmt->fetchColumn();

// 2. Total Rooms, Occupants, and Capacity
$stmt = $pdo->prepare('
    SELECT COUNT(r.id) AS total_rooms,
           COALESCE(SUM(r.occupants), 0) AS total_occupants,
           COALESCE(SUM(r.capacity), 0) AS total_capacity
    FROM rooms r
    JOIN properties p ON p.id = r.property_id
    WHERE p.landlord_id = ?
');
$stmt->execute([$userId]);
$roomStats = $stmt->fetch() ?: ['total_rooms' => 0, 'total_occupants' => 0, 'total_capacity' => 0];
$totalRooms = (int)$roomStats['total_rooms'];
$totalOccupants = (int)$roomStats['total_occupants'];
$totalCapacity = (int)$roomStats['total_capacity'];
$occupancyRate = $totalCapacity > 0 ? round(($totalOccupants / $totalCapacity) * 100) : 0;
$occupancySub = $totalCapacity > 0 ? "{$totalOccupants} of {$totalCapacity} beds occupied" : "No rooms listed yet";

// 3. Top Properties for dashboard list
$stmt = $pdo->prepare('
    SELECT p.id, p.name, p.university, p.address, p.photo,
           COUNT(r.id) AS room_count
    FROM properties p
    LEFT JOIN rooms r ON r.property_id = p.id
    WHERE p.landlord_id = ?
    GROUP BY p.id
    ORDER BY p.id DESC
    LIMIT 4
');
$stmt->execute([$userId]);
$recentProperties = $stmt->fetchAll() ?: [];

// 4. Recent Bookings for this landlord's properties
$stmt = $pdo->prepare('
    SELECT b.id, b.status, b.created_at,
           u.first_name, u.last_name, u.personal_email, u.photo AS user_photo, u.profile_photo,
           p.name AS property_name, p.university,
           r.name AS room_name, r.type AS room_type
    FROM bookings b
    JOIN properties p ON p.id = b.property_id
    JOIN users u ON u.id = b.user_id
    LEFT JOIN rooms r ON r.id = b.room_id
    WHERE p.landlord_id = ?
    ORDER BY b.id DESC
    LIMIT 4
');
$stmt->execute([$userId]);
$recentBookings = $stmt->fetchAll() ?: [];

// 5. Pending inquiries count for notification badge
$stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE property_id IN (SELECT id FROM properties WHERE landlord_id = ?) AND status = 'pending'");
$stmt->execute([$userId]);
$pendingCount = (int)$stmt->fetchColumn();

// Helper to resolve property cover photo
function resolve_property_cover(?string $photoJson): string
{
    if (empty($photoJson)) {
        return "data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100'%3E%3Crect width='100%25' height='100%25' fill='%23d1d1d1'/%3E%3Ctext x='50%25' y='50%25' fill='%238b8079' font-family='sans-serif' font-size='12' text-anchor='middle' dy='.3em'%3EDorm%3C/text%3E%3C/svg%3E";
    }
    $decoded = json_decode($photoJson, true);
    $path = '';
    if (is_array($decoded) && !empty($decoded[0])) {
        $path = (string)$decoded[0];
    } elseif (is_string($photoJson) && $photoJson !== '') {
        $path = $photoJson;
    }
    if ($path === '') {
        return "data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100'%3E%3Crect width='100%25' height='100%25' fill='%23d1d1d1'/%3E%3Ctext x='50%25' y='50%25' fill='%238b8079' font-family='sans-serif' font-size='12' text-anchor='middle' dy='.3em'%3EDorm%3C/text%3E%3C/svg%3E";
    }
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0 || strpos($path, '/') === 0) {
        return $path;
    }
    return '../' . ltrim($path, '/');
}

// Helper to resolve tenant avatar & display name
function resolve_tenant_details(array $b): array
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
                    <?php if ($pendingCount > 0): ?>
                        <span class="notification-badge"><?= $pendingCount ?></span>
                    <?php endif; ?>
                </a>

                <!-- User Profile Info -->
                <div class="user-profile-header">
                    <div class="user-avatar-small">
                        <?php if ($avatarPhoto !== ''): ?>
                            <img src="<?= htmlspecialchars($avatarPhoto, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?>" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                        <?php else: ?>
                            <span style="font-weight:700; color:#5c3527; font-size:14px;"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
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

        <!-- WELCOME SECTION -->
        <div class="welcome-section">
            <div class="welcome-text">
                <h2>Welcome back, <span id="userWelcomeName"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span></h2>
                <p>Here's an overview of your rental properties and student reservations.</p>
            </div>
            <a href="properties.php" class="btn-primary"><i class="fas fa-plus"></i> Add property</a>
        </div>

        <!-- STATS CARDS -->
        <div class="stats-grid">
            <div class="stat-card">
                <p class="stat-label">TOTAL PROPERTIES</p>
                <h3 class="stat-value"><?= $totalProperties ?></h3>
                <p class="stat-sub">Listed on UniStay</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">TOTAL ROOMS</p>
                <h3 class="stat-value"><?= $totalRooms ?></h3>
                <p class="stat-sub">Across all properties</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">OCCUPANCY RATE</p>
                <h3 class="stat-value"><?= $occupancyRate ?>%</h3>
                <p class="stat-sub"><?= htmlspecialchars($occupancySub, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="stat-card">
                <p class="stat-label">ACCOUNT STATUS</p>
                <?php if ($profileIncomplete): ?>
                    <h3 class="stat-value" style="color: #b7791f; font-size: 24px;">Action Required</h3>
                    <p class="stat-sub"><a href="settings.php" style="color: #734433; font-weight:600; text-decoration:underline;">Complete profile &rarr;</a></p>
                <?php else: ?>
                    <h3 class="stat-value verified-text">Verified</h3>
                    <p class="stat-sub">Profile and documents active</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- DASHBOARD GRID (Properties & Bookings) -->
        <div class="dashboard-grid">
            
            <!-- Left Column: My Properties -->
            <div class="section-container">
                <div class="section-header">
                    <h2>My properties</h2>
                    <a href="properties.php" class="view-all">View all &rarr;</a>
                </div>
                
                <div class="list-container">
                    <?php if (empty($recentProperties)): ?>
                        <div class="dashboard-empty-state">
                            <i class="fas fa-hotel"></i>
                            <p>No properties listed yet. Start by adding your first dorm!</p>
                            <a href="properties.php" class="btn-primary" style="display:inline-block; padding:8px 18px; font-size:12px;"><i class="fas fa-plus"></i> Add Property</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentProperties as $prop):
                            $cover = resolve_property_cover($prop['photo'] ?? '');
                            $propRooms = (int)($prop['room_count'] ?? 0);
                            $roomLabel = $propRooms === 1 ? '1 room' : "{$propRooms} rooms";
                        ?>
                            <div class="property-item">
                                <div class="item-img">
                                    <img src="<?= htmlspecialchars($cover, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)$prop['name'], ENT_QUOTES, 'UTF-8') ?>" onerror="this.onerror=null; this.src='../assets/images/placeholder-dorm.jpg';">
                                </div>
                                <div class="item-info">
                                    <h4><?= htmlspecialchars((string)$prop['name'], ENT_QUOTES, 'UTF-8') ?></h4>
                                    <p class="item-sub"><?= htmlspecialchars((string)$prop['address'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <p class="item-sub">Near <?= htmlspecialchars((string)$prop['university'], ENT_QUOTES, 'UTF-8') ?> &bull; <?= $roomLabel ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column: Recent Bookings -->
            <div class="section-container">
                <div class="section-header">
                    <h2>Recent bookings</h2>
                    <a href="tenants.php" class="view-all">View all &rarr;</a>
                </div>
                
                <div class="list-container">
                    <?php if (empty($recentBookings)): ?>
                        <div class="dashboard-empty-state">
                            <i class="fas fa-calendar-check"></i>
                            <p>No student bookings yet. Verified listings will receive booking inquiries here.</p>
                            <a href="rooms.php" class="btn-outline" style="align-self:center;">Review Rooms</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentBookings as $b):
                            $tenant = resolve_tenant_details($b);
                            $roomTitle = trim((string)($b['room_name'] ?? ''));
                            if ($roomTitle === '') {
                                $roomTitle = 'Reserved Spot';
                            }
                            $propTitle = (string)($b['property_name'] ?? 'Property');
                            $uniTitle = (string)($b['university'] ?? '');
                            $roomType = ucfirst(strtolower((string)($b['room_type'] ?? 'Room')));
                            $status = strtolower((string)($b['status'] ?? 'pending'));
                        ?>
                            <div class="booking-item-card">
                                <div class="booking-avatar-wrap">
                                    <?php if ($tenant['photo'] !== ''): ?>
                                        <img src="<?= htmlspecialchars($tenant['photo'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($tenant['name'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?php else: ?>
                                        <span><?= htmlspecialchars($tenant['initial'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="item-info" style="flex:1;">
                                    <div class="booking-header-row">
                                        <h4><?= htmlspecialchars($roomTitle, ENT_QUOTES, 'UTF-8') ?></h4>
                                        <span class="badge-status <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <p class="item-sub"><?= htmlspecialchars($propTitle, ENT_QUOTES, 'UTF-8') ?><?php if ($uniTitle !== ''): ?> &bull; <?= htmlspecialchars($uniTitle, ENT_QUOTES, 'UTF-8') ?><?php endif; ?></p>
                                    <p class="item-tenant"><i class="fas fa-user-graduate" style="margin-right:4px; color:#734433;"></i> <?= htmlspecialchars($tenant['name'], ENT_QUOTES, 'UTF-8') ?> &bull; <?= htmlspecialchars($roomType, ENT_QUOTES, 'UTF-8') ?></p>
                                    <a href="tenants.php" class="btn-outline">View details</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
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
                <a href="settings.php" class="toast-btn toast-btn--primary" id="toastBtnNow">Now</a>
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
