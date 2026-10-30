<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

$authenticatedUser = require_signed_in('tenant');

// Check if tenant has completed their profile
$pdo = database();
$user_id = current_tenant_id();
$profileIncomplete = !tenant_profile_complete($pdo, $user_id);
$tenantProfile = get_authenticated_profile($pdo, (int)$user_id);
$tenantVerification = get_tenant_verification($pdo, (int)$user_id);

// --- User Profile Data ---
$user_data = [
    "name" => $tenantProfile['name']
];

/** All photos of a JSON-array (or plain path) photo column, as URLs usable from /tenant/. */
function dash_photo_list($raw): array {
    $raw = trim((string)$raw);
    if ($raw === '' || $raw === '[]') return [];
    $decoded = json_decode($raw, true);
    $paths = is_array($decoded) ? $decoded : [$raw];
    $out = [];
    foreach ($paths as $path) {
        $path = trim((string)$path);
        if ($path === '') continue;
        $out[] = preg_match('#^https?://#i', $path) ? $path : '../' . ltrim($path, './');
    }
    return $out;
}

/** First photo of a photo column, or ''. */
function dash_first_photo($raw): string {
    $list = dash_photo_list($raw);
    return $list[0] ?? '';
}

function dash_e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function dash_verified_check(): string {
    return '<span class="verified-check" role="img" aria-label="Verified landlord" title="Verified landlord"><svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg></span>';
}

function dash_money(float $n): string {
    return '₱' . number_format($n, 2);
}

$dashUniversities = [
    'Universidad de Dagupan'         => [16.0507, 120.3408],
    'University of Pangasinan'       => [16.0471, 120.3425],
    'University of Luzon'            => [16.0398, 120.3359],
    'Lyceum Northwestern University' => [16.0354, 120.3305],
];

/** Straight-line distance in km between two lat/lng points. */
function dash_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 6371 * 2 * asin(sqrt($a));
}

// --- Most recent booking: active first, then upcoming, then pending ---
$booking = null;
try {
    $bk = $pdo->prepare("
        SELECT b.id, b.status, b.property_id, b.room_id, b.created_at, b.updated_at,
               p.name AS dorm_name, p.address, p.university, p.lat, p.lng, p.photo AS prop_photo,
               r.name AS room_name, r.type AS room_type, r.capacity, r.rent, r.rent_max,
               r.deposit, r.deposit_max, r.amenities, r.status AS room_status, r.photo AS room_photo,
               l.first_name AS landlord_first, l.last_name AS landlord_last,
               l.phone_number AS landlord_phone, l.facebook_link AS landlord_fb,
               l.verification_status AS landlord_verification
        FROM bookings b
        JOIN properties p ON p.id = b.property_id
        JOIN users l ON l.id = p.landlord_id
        LEFT JOIN rooms r ON r.id = b.room_id
        WHERE b.user_id = ? AND b.status IN ('active', 'upcoming', 'pending')
        ORDER BY FIELD(b.status, 'active', 'upcoming', 'pending'), b.updated_at DESC, b.id DESC
        LIMIT 1
    ");
    $bk->execute([(int)$user_id]);
    $booking = $bk->fetch() ?: null;
} catch (PDOException $e) {
    error_log('UniStay dashboard booking lookup failed: ' . $e->getMessage());
}

$statusLabels = [
    'active'   => 'Active Stay',
    'upcoming' => 'Upcoming Stay',
    'pending'  => 'Pending Approval',
];
$verifLabels = [
    'verified'   => 'Verified',
    'pending'    => 'Pending Review',
    'rejected'   => 'Needs Attention',
    'unverified' => 'Unverified',
];
$verifKey = strtolower((string)($tenantVerification['status'] ?? 'unverified'));
$verifLabel = $verifLabels[$verifKey] ?? 'Unverified';

if ($booking) {
    $bStatus     = (string)$booking['status'];
    $statusLabel = $statusLabels[$bStatus] ?? ucfirst($bStatus);
    $rentText    = '₱— / month';
    $rentMain    = '₱—';
    if ($booking['rent'] !== null) {
        $rentMain = dash_money((float)$booking['rent']);
        if ($booking['rent_max'] !== null && (float)$booking['rent_max'] > (float)$booking['rent']) {
            $rentMain = dash_money((float)$booking['rent']) . ' – ' . dash_money((float)$booking['rent_max']);
        }
        $rentText = $rentMain . ' / month';
    }

    if ($bStatus === 'active') {
        $duration = 'Since ' . date('M j, Y', strtotime((string)($booking['updated_at'] ?: $booking['created_at'])));
        $staySince = 'Active stay since ' . date('M j, Y', strtotime((string)($booking['updated_at'] ?: $booking['created_at'])));
    } elseif ($bStatus === 'upcoming') {
        $duration = 'Move-in date to be confirmed';
        $staySince = 'Upcoming stay';
    } else {
        $duration = 'Requested ' . date('M j, Y', strtotime((string)$booking['created_at']));
        $staySince = 'Waiting for landlord approval';
    }

    $tenantSince = $bStatus === 'active'
        ? date('M j, Y', strtotime((string)($booking['updated_at'] ?: $booking['created_at'])))
        : '—';

    $landlordName = trim(($booking['landlord_first'] ?? '') . ' ' . ($booking['landlord_last'] ?? '')) ?: 'Landlord';
    $landlordVerified = strtolower((string)($booking['landlord_verification'] ?? '')) === 'verified';
    $roomName = $booking['room_name'] ?: 'Room not specified';
    $heroPhoto = dash_first_photo($booking['room_photo'] ?? '') ?: dash_first_photo($booking['prop_photo'] ?? '');

    $current_booking = [
        "status"          => $bStatus . '-stay',
        "status_label"    => $statusLabel,
        "room_name"       => $roomName,
        "room_type"       => $booking['room_type'] ? ucfirst(strtolower((string)$booking['room_type'])) : '—',
        "dorm_name"       => (string)$booking['dorm_name'],
        "location"        => (string)$booking['address'],
        "near_university" => 'Near ' . $booking['university'],
        "duration"        => $duration,
        "tenant_since"    => $tenantSince,
        "monthly_payment" => $rentText,
        "landlord_name"   => $landlordName,
        "landlord_verified" => $landlordVerified,
        "photo"           => $heroPhoto,
        "link"            => 'dorms.php?property=' . (int)$booking['property_id'] . ($booking['room_id'] ? '&room=' . (int)$booking['room_id'] : ''),
    ];

    // Campus commute: dorm -> the tenant's own campus (falls back to the dorm's university)
    $tenantSchool = (string)($tenantProfile['school'] ?? '');
    $campusName = isset($dashUniversities[$tenantSchool]) ? $tenantSchool : (string)$booking['university'];
    $campusCard = [
        "label" => "CAMPUS COMMUTE",
        "main_value" => "—",
        "sub_value" => "Complete your profile to see your commute",
        "sub_value_2" => null,
        "icon" => "location"
    ];
    if (isset($dashUniversities[$campusName]) && $booking['lat'] !== null && $booking['lng'] !== null) {
        [$cLat, $cLng] = $dashUniversities[$campusName];
        $km = dash_km((float)$booking['lat'], (float)$booking['lng'], $cLat, $cLng);
        $walkMin = max(1, (int)round($km * 12)); // about 5 km/h walking pace
        $campusCard["main_value"] = $walkMin . ' min walk';
        $campusCard["sub_value"] = number_format($km, 1) . ' km to ' . $campusName;
    }

    // ---- Data for the floating room card ----
    $bm = [];
    $bm['photos']    = dash_photo_list($booking['room_photo'] ?? '') ?: dash_photo_list($booking['prop_photo'] ?? '');
    $bm['title']     = $booking['room_name'] ?: $booking['dorm_name'];
    $bm['amenities'] = json_decode((string)($booking['amenities'] ?? '[]'), true);
    if (!is_array($bm['amenities'])) $bm['amenities'] = [];
    $bm['beds']      = (int)($booking['capacity'] ?? 0);
    $bm['deposit']   = '';
    if ($booking['deposit'] !== null && (float)$booking['deposit'] > 0) {
        $bm['deposit'] = dash_money((float)$booking['deposit']);
        if ($booking['deposit_max'] !== null && (float)$booking['deposit_max'] > (float)$booking['deposit']) {
            $bm['deposit'] .= ' – ' . dash_money((float)$booking['deposit_max']);
        }
    }
    $nearestKm = null;
    if ($booking['lat'] !== null && $booking['lng'] !== null) {
        foreach ($dashUniversities as [$uLat, $uLng]) {
            $d = dash_km((float)$booking['lat'], (float)$booking['lng'], $uLat, $uLng);
            if ($nearestKm === null || $d < $nearestKm) $nearestKm = $d;
        }
    }
    $bm['distance'] = $nearestKm !== null ? number_format($nearestKm, 1) : null;
    $bm['initials'] = strtoupper(substr(trim((string)($booking['landlord_first'] ?? '')), 0, 1) . substr(trim((string)($booking['landlord_last'] ?? '')), 0, 1)) ?: 'L';
    $bm['phone'] = trim((string)($booking['landlord_phone'] ?? ''));
    $bm['messenger'] = '';
    $fbRaw = trim((string)($booking['landlord_fb'] ?? ''));
    if ($fbRaw !== '') {
        $handle = preg_replace('~^(?:https?://)?(?:www\.)?facebook\.com/~i', '', $fbRaw);
        if (preg_match('~profile\.php\?id=(\d+)~i', $handle, $mm)) {
            $handle = $mm[1];
        } else {
            $handle = trim(explode('?', $handle)[0], '/');
        }
        $bm['messenger'] = $handle !== '' ? 'https://m.me/' . rawurlencode($handle) : '';
    }

    $dashboard_stats = [
        [
            "label" => "CURRENT ROOM",
            "main_value" => $roomName,
            "sub_value" => $booking['dorm_name'] . ' • ' . $statusLabel,
            "sub_value_2" => null,
            "icon" => "home"
        ],
        [
            "label" => "MONTHLY RENT",
            "main_value" => $rentMain,
            "sub_value" => $booking['room_type'] ? ucfirst(strtolower((string)$booking['room_type'])) . ' room' : '—',
            "sub_value_2" => null,
            "icon" => "money"
        ],
        $campusCard,
    ];
} else {
    $current_booking = null;

    // No booking yet: show how many dorms are near the tenant's campus
    $tenantSchool = (string)($tenantProfile['school'] ?? '');
    $nearCard = [
        "label" => "DORMS NEAR YOU",
        "main_value" => "—",
        "sub_value" => "Complete your profile to filter by campus",
        "sub_value_2" => null,
        "icon" => "bed"
    ];
    try {
        if ($tenantSchool !== '') {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM properties WHERE university = ? AND approval_status != 'rejected'");
            $cnt->execute([$tenantSchool]);
            $n = (int)$cnt->fetchColumn();
            $nearCard["main_value"] = (string)$n;
            $nearCard["sub_value"] = ($n === 1 ? 'dorm' : 'dorms') . ' near ' . $tenantSchool;
        } else {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM properties WHERE approval_status != 'rejected'")->fetchColumn();
            $nearCard["main_value"] = (string)$n;
            $nearCard["sub_value"] = ($n === 1 ? 'dorm' : 'dorms') . ' available · set your school in Settings';
        }
    } catch (PDOException $e) {
        error_log('UniStay dashboard dorm count failed: ' . $e->getMessage());
    }
    $dashboard_stats = [
        [
            "label" => "CURRENT ROOM",
            "main_value" => "No room yet",
            "sub_value" => "Book a dorm to get started",
            "sub_value_2" => null,
            "icon" => "home"
        ],
        [
            "label" => "MONTHLY RENT",
            "main_value" => "₱—",
            "sub_value" => "No active booking",
            "sub_value_2" => null,
            "icon" => "money"
        ],
        $nearCard,
    ];
}

?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#7a4b3a">
<title>Dashboard · UniStay</title>
<link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/common.css?v=35">
<link rel="stylesheet" href="../assets/css/dorms.css?v=42">
<link rel="stylesheet" href="../assets/css/dashboardstyle.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/dashboardstyle.css'); ?>">
<style>
/* Same serif title treatment as the Map page (.map-page h1 in common.css?v=35) */
.topbar h1 { font-family: Georgia, "Times New Roman", serif; }

/* Room card floating over the dashboard: same look as the Dorms tab (dorms.css?v=42), forced to the center */
#bookingRoomDialog {
  position: fixed;
  inset: 0;
  margin: auto;
}
#bookingRoomDialog::backdrop { background: rgba(40, 22, 15, 0.55); backdrop-filter: blur(3px); }
#bookingRoomDialog[open] { animation: bk-in 0.34s cubic-bezier(0.16, 1, 0.3, 1) both; }
#bookingRoomDialog[open]::backdrop { animation: bk-fade 0.28s ease both; }
#bookingRoomDialog.is-closing { animation: bk-out 0.2s ease both; }
#bookingRoomDialog.is-closing::backdrop { animation: bk-fade-out 0.2s ease both; }
@keyframes bk-in { from { opacity: 0; transform: translateY(26px) scale(0.96); } to { opacity: 1; transform: none; } }
@keyframes bk-out { to { opacity: 0; transform: translateY(12px) scale(0.98); } }
@keyframes bk-fade { from { opacity: 0; } to { opacity: 1; } }
@keyframes bk-fade-out { to { opacity: 0; } }
@media (prefers-reduced-motion: reduce) {
  #bookingRoomDialog[open], #bookingRoomDialog.is-closing, #bookingRoomDialog[open]::backdrop { animation: none; }
}

/* Empty state for "Current Booking" when the tenant has no booking yet */
.booking-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 8px;
  padding: 44px 24px;
}
.booking-empty-home {
  width: 72px;
  height: 72px;
  display: grid;
  place-items: center;
  border-radius: 50%;
  color: #7a4b3a;
  background: #eadbcb;
  box-shadow: 0 10px 24px rgba(92, 62, 48, 0.12);
}
.booking-empty-home svg { width: 34px; height: 34px; fill: currentColor; }
.booking-empty h3 { margin: 0; color: #3b2820; font-size: 1.15rem; }
.booking-empty p { margin: 0 0 10px; color: #78665c; font-size: 0.92rem; max-width: 380px; }
.booking-empty .booking-actions-row {
  justify-content: center;
  margin-top: auto;
}
.booking-empty .booking-action-btn {
  flex: 0 0 auto;
  width: auto;
}

/* Profile Completion Floating Toast */
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
  animation: toast-smooth-in 0.5s cubic-bezier(0.16, 1, 0.3, 1) 0.25s both;
  transition: opacity 0.35s cubic-bezier(0.4, 0, 0.2, 1), transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
}

.profile-toast.is-dismissing {
  opacity: 0;
  transform: translateY(22px) scale(0.95);
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

@keyframes toast-smooth-in {
  from {
    opacity: 0;
    transform: translateY(28px) scale(0.95);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

@media (max-width: 520px) {
  .profile-toast {
    bottom: 16px;
    right: 16px;
    left: 16px;
    width: auto;
  }
}
</style>
<script src="../assets/js/tenant/popover.js?v=2"></script>
<script src="../assets/js/tenant/sidebar.js?v=5"></script>
<script src="../assets/js/tenant/profile-menu.js?v=31"></script>
</head>
<body>
<svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs>
<symbol id="grid-icon" viewBox="0 0 24 24"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></symbol>
<symbol id="map-icon" viewBox="0 0 24 24"><path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2Zm6-2v16m6-14v16"/></symbol>
<symbol id="booking-icon" viewBox="0 0 24 24"><path d="m2 11 7-6 7 6v10h-5v-7H7v7H2ZM13 3h9v18h-3M16 7h3m0 4h-1"/></symbol>
<symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
<symbol id="bed-icon" viewBox="0 0 24 24"><path d="M3 19V9h18v10M3 16h18M5 9V5h5v4m4 0V5h5v4"/></symbol>
<symbol id="home-icon" viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3Z"/></symbol>
<symbol id="money-icon" viewBox="0 0 24 24"><path d="M6 4h16v12H6ZM2 8v12h16"/><circle cx="14" cy="10" r="3"/></symbol>
<symbol id="calendar-icon" viewBox="0 0 24 24"><path d="M13 21H3V5h15v6M3 9h15M6 2v5m9-5v5"/><circle cx="18" cy="17" r="5"/><path d="M18 14v3l2 1"/></symbol>
<symbol id="bell-icon" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M10 20h4"/></symbol>
<symbol id="bell-off-icon" viewBox="0 0 24 24"><path d="m3 3 18 18M10 20h4M6 6c-1 2 0 7-3 10h13M18 13V8a6 6 0 0 0-8-6"/></symbol>
<symbol id="arrow-icon" viewBox="0 0 24 24"><path d="M4 12h16m-6-6 6 6-6 6"/></symbol>
<symbol id="close-icon" viewBox="0 0 24 24"><path d="m6 6 12 12M6 18 18 6"/></symbol>
<symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
<symbol id="pin-icon" viewBox="0 0 24 24"><path d="M19 9c0 5-7 12-7 12S5 14 5 9a7 7 0 0 1 14 0Z"/><circle cx="12" cy="9" r="2"/></symbol>
<symbol id="school-icon" viewBox="0 0 24 24"><path d="m2 9 10-5 10 5-10 5Z"/><path d="M6 11v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5M22 9v6"/></symbol>
<symbol id="measure-icon" viewBox="0 0 24 24"><path d="M4 20 20 4"/><path d="M13 4h7v7M11 20H4v-7"/></symbol>
<symbol id="location-icon" viewBox="0 0 24 24"><path d="M12 21s-6-5.686-6-10a6 6 0 1 1 12 0c0 4.314-6 10-6 10Z"/><circle cx="12" cy="11" r="2"/></symbol>
<symbol id="user-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></symbol>
<symbol id="logout-icon" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
</defs></svg>
<a class="skip" href="#dashboard-page">Skip to dashboard</a>
<aside class="sidebar" aria-label="UniStay portal">
<a href="../index.php" class="brand" title="UniStay Home"><img src="../assets/images/tenant/logo.png" width="50" height="50" alt=""><span class="brand-name">UniStay</span></a>
<nav aria-label="Main navigation">
<a href="dashboard.php" title="Dashboard" aria-current="page"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
<a href="dorms.php" title="Dorms"><svg aria-hidden="true"><use href="#bed-icon"/></svg><span class="nav-label">Dorms</span></a>
<a href="map.php" title="Map"><svg aria-hidden="true"><use href="#map-icon"/></svg><span class="nav-label">Map</span></a>
<a href="settings.php" title="Settings"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
</nav>
<button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true"><span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span><span class="toggle-label">Collapse</span></button>
</aside>
<div class="shell">
<header class="topbar"><h1>Dashboard</h1><div class="profile">
<button id="notification-bell" class="bell" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="notification-popover"><svg aria-hidden="true"><use href="#bell-icon"/></svg></button>
<button class="avatar" type="button" aria-label="<?= htmlspecialchars($tenantProfile['name'], ENT_QUOTES, 'UTF-8') ?> — open profile menu" aria-expanded="false" aria-controls="profile-popover" <?= !empty($tenantProfile['photo']) ? 'style="background-image:url(\''.htmlspecialchars($tenantProfile['photo'], ENT_QUOTES, 'UTF-8').'\');"' : '' ?>>
  <?php if (!empty($tenantProfile['photo'])): ?>
    <img src="<?= htmlspecialchars($tenantProfile['photo'], ENT_QUOTES, 'UTF-8') ?>" alt="" referrerpolicy="no-referrer" class="avatar-img" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline-flex';">
    <span style="display:none;"><?= htmlspecialchars($tenantProfile['initials'] ?? 'U', ENT_QUOTES, 'UTF-8') ?></span>
  <?php else: ?>
    <?= htmlspecialchars($tenantProfile['initials'] ?? 'U', ENT_QUOTES, 'UTF-8') ?>
  <?php endif; ?>
</button>
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
                            <?php if ($stat['label'] === 'MONTHLY RENT' && $current_booking): ?>
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

                    <?php if ($current_booking): ?>
                    <article class="current-booking-body" data-status="<?php echo htmlspecialchars($current_booking['status']); ?>">
                        <div class="booking-hero">
                            <img src="<?php echo htmlspecialchars($current_booking['photo'] ?: 'https://images.unsplash.com/photo-1616486338812-3dadae4b4ace?auto=format&fit=crop&w=1200&q=80'); ?>" alt="<?php echo htmlspecialchars($current_booking['room_name']); ?>">
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
                                    <?php if ($current_booking['status'] !== 'active-stay'): ?>
                                      <span class="booking-meta-pill booking-status-pill">
                                          <?php echo htmlspecialchars($current_booking['status_label']); ?>
                                      </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="booking-details-grid">
                                <div class="booking-detail-item">
                                    <span class="booking-detail-label">Room type</span>
                                    <span class="booking-detail-value"><?php echo htmlspecialchars($current_booking['room_type']); ?></span>
                                </div>
                                <div class="booking-detail-item">
                                    <span class="booking-detail-label">Tenant since</span>
                                    <span class="booking-detail-value"><?php echo htmlspecialchars($current_booking['tenant_since']); ?></span>
                                </div>
                                <div class="booking-detail-item">
                                    <span class="booking-detail-label">Monthly payment</span>
                                    <span class="booking-detail-value"><?php echo htmlspecialchars($current_booking['monthly_payment']); ?></span>
                                </div>
                                <div class="booking-detail-item">
                                    <span class="booking-detail-label">Landlord</span>
                                    <span class="booking-detail-value"><?php echo htmlspecialchars($current_booking['landlord_name']); ?><?php if ($current_booking['landlord_verified']) echo dash_verified_check(); ?></span>
                                </div>
                            </div>

                            <div class="booking-actions-row">
                                <a class="booking-action-btn primary" id="viewBookingBtn" href="<?php echo htmlspecialchars($current_booking['link']); ?>">View Booking</a>
                                <a class="booking-action-btn ghost" href="dorms.php">Browse Rooms</a>
                            </div>
                        </div>
                    </article>
                    <?php else: ?>
                    <article class="current-booking-body booking-empty" data-status="none">
                        <span class="booking-empty-home" aria-hidden="true"><svg><use href="#home-icon"></use></svg></span>
                        <h3>No booking yet</h3>
                        <p>When you book a room and the landlord approves it, your stay will show up here.</p>
                        <div class="booking-actions-row">
                            <a class="booking-action-btn primary" href="dorms.php">Browse Dorms</a>
                        </div>
                    </article>
                    <?php endif; ?>
                </section>
            </section>

</main>
</div>
<?php if ($current_booking): ?>
<!-- Room card (same markup and classes as the Dorms tab, so it looks identical) -->
<dialog id="bookingRoomDialog" class="dorm-modal is-viewing-room" aria-labelledby="dorm-modal-title">
  <div class="dorm-modal-header" id="dorm-modal-header">
    <button type="button" class="btn-modal-back" id="dorm-modal-back-btn" style="display:none;" aria-label="Back" title="Back">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
      <span>Back</span>
    </button>
    <h2 id="dorm-modal-title">Room details</h2>
    <button type="button" class="dorm-modal-close" id="dorm-modal-close-btn" style="display:inline-flex;align-items:center;justify-content:center;" aria-label="Close dialog">✕</button>
  </div>
  <div class="dorm-modal-body" id="dorm-modal-content">
    <div class="room-details-view">
      <!-- Left: photos -->
      <div class="room-details-left-panel">
        <?php if ($bm['photos']): ?>
          <div class="um-carousel room-panel-carousel" id="room-modal-carousel">
            <div class="um-carousel-track">
              <?php foreach ($bm['photos'] as $i => $src): ?>
                <img class="um-slide <?= $i === 0 ? (count($bm['photos']) === 1 ? 'is-active is-only' : 'is-active') : ($i === 1 ? 'is-next' : '') ?>"
                     src="<?= dash_e($src) ?>" data-idx="<?= $i ?>" alt="<?= dash_e($bm['title']) ?> photo <?= $i + 1 ?>" loading="lazy">
              <?php endforeach; ?>
            </div>
            <?php if (count($bm['photos']) > 1): ?>
              <button type="button" class="um-carousel-btn prev" id="room-carousel-prev" aria-label="Previous photo">
                <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"></polyline></svg>
              </button>
              <button type="button" class="um-carousel-btn next" id="room-carousel-next" aria-label="Next photo">
                <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>
              </button>
              <div class="um-carousel-count" id="room-carousel-count">1 / <?= count($bm['photos']) ?></div>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="um-carousel room-panel-carousel" id="room-modal-carousel">
            <div class="um-carousel-track room-no-photo-track">
              <div class="room-no-photo-content">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:block;margin:0 auto 8px;"><use href="#bed-icon"/></svg>
                <strong style="color:#ffffff; font-size:1.05rem; display:block;"><?= dash_e($bm['title']) ?></strong>
                <span style="font-size:0.84rem; color:rgba(255,255,255,0.75);">No photo uploaded yet</span>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Right: details -->
      <div class="room-details-right-panel">
        <h2 class="room-title-heading"><?= dash_e($bm['title']) ?></h2>
        <div class="room-prop-link">
          <svg><use href="#pin-icon"/></svg>
          <span>Part of <b><?= dash_e($current_booking['dorm_name']) ?></b> · <?= dash_e($current_booking['location']) ?></span>
        </div>

        <ul class="dorm-modal-facts room-facts-list">
          <?php if ($booking['room_type']): ?>
            <li><svg><use href="#bed-icon"/></svg><span>Room Unit: <b><?= dash_e($current_booking['room_type']) ?><?= $bm['beds'] ? ' (' . $bm['beds'] . ' Bed' . ($bm['beds'] === 1 ? '' : 's') . ')' : '' ?></b></span></li>
          <?php endif; ?>
          <li><svg><use href="#school-icon"/></svg><span>Campus: <b><?= dash_e($current_booking['near_university']) ?></b></span></li>
          <?php if ($bm['distance'] !== null): ?>
            <li><svg><use href="#measure-icon"/></svg><span>Distance from campus: <b><?= dash_e($bm['distance']) ?> km</b></span></li>
          <?php endif; ?>
        </ul>

        <div class="room-pricing-card">
          <div class="room-pricing-item">
            <span class="room-pricing-label">Monthly Rate</span>
            <span class="room-pricing-amount highlight"><?= dash_e($rentMain) ?> <small>/ month</small></span>
          </div>
          <?php if ($bm['deposit'] !== ''): ?>
          <div class="room-pricing-item secondary">
            <span class="room-pricing-label">Security Deposit</span>
            <span class="room-pricing-amount"><?= dash_e($bm['deposit']) ?></span>
          </div>
          <?php endif; ?>
        </div>

        <div class="room-landlord-card">
          <div class="room-landlord-avatar-wrap">
            <span class="room-landlord-initials"><?= dash_e($bm['initials']) ?></span>
          </div>
          <div class="room-landlord-meta">
            <div class="room-landlord-name"><span class="landlord-name-text"><?= dash_e($current_booking['landlord_name']) ?></span><?= $current_booking['landlord_verified'] ? dash_verified_check() : '' ?></div>
            <div class="room-landlord-role">Property Landlord</div>
          </div>
          <?php if ($bm['messenger'] !== ''): ?>
          <a class="btn-room-chat-landlord" href="<?= dash_e($bm['messenger']) ?>" target="_blank" rel="noopener noreferrer" style="text-decoration:none;" aria-label="Contact landlord">Contact</a>
          <?php elseif ($bm['phone'] !== ''): ?>
          <a class="btn-room-chat-landlord" href="tel:<?= dash_e($bm['phone']) ?>" style="text-decoration:none;">Contact</a>
          <?php endif; ?>
        </div>

        <!-- Your stay status (replaces the booking button of the Dorms tab) -->
        <div class="room-booking-action-wrap">
          <?php if ($booking['status'] === 'active'): ?>
            <div style="padding:10px 14px; border-radius:10px; font-size:0.88rem; font-weight:600; background:#dcfce7; color:#166534; border:1px solid #bbf7d0;">✓ Active stay · <?= dash_e($current_booking['duration']) ?></div>
          <?php elseif ($booking['status'] === 'upcoming'): ?>
            <div style="padding:10px 14px; border-radius:10px; font-size:0.88rem; font-weight:600; background:#e0f2fe; color:#075985; border:1px solid #bae6fd;">Upcoming stay · <?= dash_e($current_booking['duration']) ?></div>
          <?php else: ?>
            <div style="padding:10px 14px; border-radius:10px; font-size:0.88rem; font-weight:600; background:#fef3c7; color:#92400e; border:1px solid #fde68a;">⏳ Waiting for landlord approval · <?= dash_e($current_booking['duration']) ?></div>
          <?php endif; ?>
        </div>

        <?php if ($bm['amenities']): ?>
        <div class="room-amenities-wrap">
          <h4>Included Room Amenities</h4>
          <div class="room-amenities-list">
            <?php foreach ($bm['amenities'] as $am): ?>
              <span class="room-amenity-chip"><?= dash_e($am) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="room-rules-card">
          <strong>Student Residence Guidelines</strong>
          UniStay properties prioritize student safety, quiet study hours, and verified guest policies. Connect with the landlord for specific check-in schedules.
        </div>
      </div>
    </div>
  </div>
</dialog>
<?php endif; ?>
<p id="feedback" class="notification-feedback" role="status"></p>
<!-- Supply tenant notifications here as JSON: [{"id":"unique-id","title":"Title","message":"Message"}]. -->
<script id="notification-data" type="application/json">[]</script>
<script>
window.UNISTAY_NOTIFICATION_ACCOUNT = <?= json_encode(['id' => (int)($_SESSION['user_id'] ?? 0), 'role' => 'tenant']) ?>;
</script>
<script src="../assets/js/notification-preferences.js?v=2"></script>
<script src="../assets/js/tenant/notifications.js?v=7"></script>
<?php if (false && $profileIncomplete): // Replaced by the mandatory centered registration modal. ?>
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
            <p>Would you like to fill in the remaining profile information now, or finish it later?</p>
            <div class="profile-toast-actions">
                <button type="button" class="toast-btn toast-btn--secondary" id="toastBtnLater">Later</button>
                <a href="settings.php?mode=edit" class="toast-btn toast-btn--primary" id="toastBtnNow">Now</a>
            </div>
        </div>
    </div>
</aside>
<script>
(function () {
  var toast = document.getElementById('profileToast');
  if (!toast) return;

  if (sessionStorage.getItem('unistay_profile_toast_dismissed') === '1') {
    toast.remove();
    return;
  }

  function dismiss() {
    toast.classList.add('is-dismissing');
    sessionStorage.setItem('unistay_profile_toast_dismissed', '1');
    setTimeout(function () {
      toast.remove();
    }, 360);
  }

  var btnLater = document.getElementById('toastBtnLater');
  var btnClose = document.getElementById('profileToastDismiss');

  if (btnLater) btnLater.addEventListener('click', dismiss);
  if (btnClose) btnClose.addEventListener('click', dismiss);
})();
</script>
<?php endif; ?>
<?php if ($current_booking): ?>
<script>
(function () {
  var openBtn = document.getElementById('viewBookingBtn');
  var dlg = document.getElementById('bookingRoomDialog');
  if (!openBtn || !dlg || typeof dlg.showModal !== 'function') return; // falls back to the normal link

  var closing = false;
  function openDlg() {
    closing = false;
    dlg.classList.remove('is-closing');
    document.body.style.overflow = 'hidden';
    dlg.showModal();
  }
  function closeDlg() {
    if (closing || !dlg.open) return;
    closing = true;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    dlg.classList.add('is-closing');
    setTimeout(function () {
      dlg.close();
      dlg.classList.remove('is-closing');
      document.body.style.overflow = '';
      closing = false;
      openBtn.focus();
    }, reduce ? 0 : 200);
  }

  openBtn.addEventListener('click', function (e) {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
    e.preventDefault();
    openDlg();
  });
  document.getElementById('dorm-modal-close-btn').addEventListener('click', closeDlg);
  dlg.addEventListener('click', function (e) {
    var r = dlg.getBoundingClientRect();
    if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) closeDlg();
  });
  dlg.addEventListener('cancel', function (e) { e.preventDefault(); closeDlg(); });

  // Photo carousel (same behavior as the Dorms tab)
  var slides = dlg.querySelectorAll('#room-modal-carousel .um-slide');
  if (slides.length > 1) {
    var cur = 0;
    var prevBtn = document.getElementById('room-carousel-prev');
    var nextBtn = document.getElementById('room-carousel-next');
    var countEl = document.getElementById('room-carousel-count');
    function update() {
      slides.forEach(function (sl, i) {
        sl.classList.remove('is-active', 'is-prev', 'is-next');
        if (i === cur) sl.classList.add('is-active');
        else if (i === cur - 1) sl.classList.add('is-prev');
        else if (i === cur + 1) sl.classList.add('is-next');
      });
      prevBtn.style.opacity = cur > 0 ? '1' : '0.22';
      prevBtn.style.pointerEvents = cur > 0 ? 'auto' : 'none';
      nextBtn.style.opacity = cur < slides.length - 1 ? '1' : '0.22';
      nextBtn.style.pointerEvents = cur < slides.length - 1 ? 'auto' : 'none';
      if (countEl) countEl.textContent = (cur + 1) + ' / ' + slides.length;
    }
    prevBtn.addEventListener('click', function (e) { e.stopPropagation(); if (cur > 0) { cur--; update(); } });
    nextBtn.addEventListener('click', function (e) { e.stopPropagation(); if (cur < slides.length - 1) { cur++; update(); } });
    update();
  }
})();
</script>
<?php endif; ?>
<script>
window.UNISTAY_TENANT_PROFILE = <?= json_encode($tenantProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<?php require __DIR__ . '/../config/registration-modal.php'; ?>
</body>
</html>