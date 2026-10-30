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
$profileIncomplete = false;
$tenantProfile = null;
if ($isAuthenticated) {
    $pdo = database();
    $profileIncomplete = !tenant_profile_complete($pdo, (int)$user_id);
    $tenantProfile = get_authenticated_profile($pdo, (int)$user_id);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#7a4b3a">
<title>Map · UniStay</title>
<link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="../assets/css/common.css?v=35">
<link rel="stylesheet" href="../assets/css/map.css?v=25">
<link rel="stylesheet" href="../assets/css/dorms.css?v=45">
<script src="../assets/js/tenant/popover.js?v=2"></script>
<script src="../assets/js/tenant/sidebar.js?v=5"></script>
<?php if ($isAuthenticated): ?>
<script src="../assets/js/tenant/profile-menu.js?v=31"></script>
<?php endif; ?>
<style>
/* Profile Completion Floating Toast */
.profile-toast {
  position: fixed;
  bottom: 24px;
  right: 24px;
  z-index: 99999;
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

.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}
.um-toolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-start;gap:12px;margin:22px 0 16px}
.um-search{display:flex;align-items:center;gap:10px;flex:0 1 360px;width:min(360px,100%);min-width:190px;height:44px;padding:0 16px;border:1px solid #D9BEA6;border-radius:14px;background:#fff;box-shadow:0 2px 8px rgba(84,60,48,.12);transition:border-color .18s,box-shadow .18s}
.um-search svg{width:17px;height:17px;flex:none;fill:none;stroke:#8B7467;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.um-search input{flex:1;min-width:0;border:0;background:none;padding:0;font:inherit;color:#5f3425;outline:none}
.um-search input::placeholder{color:#8B7467;opacity:1}
.um-search:focus-within{border-color:#7a4b3a;box-shadow:0 2px 8px rgba(84,60,48,.12),0 0 0 3px rgba(122,75,58,.14)}

.um-select{position:relative}
.um-select-btn{display:flex;align-items:center;justify-content:space-between;gap:12px;height:44px;min-width:320px;padding:0 16px;border:1px solid #D9BEA6;border-radius:14px;background:#fff;box-shadow:0 2px 8px rgba(84,60,48,.12);font:inherit;color:#5f3425;cursor:pointer;transition:border-color .18s,box-shadow .18s}
.um-select-btn.is-placeholder .um-select-value{color:#8B7467}
.um-select-btn:hover{border-color:#cbb5a9}
.um-select-btn[aria-expanded="true"]{border-color:#7a4b3a;box-shadow:0 2px 8px rgba(84,60,48,.12),0 0 0 3px rgba(122,75,58,.14)}
.um-select-caret{width:17px;height:17px;flex:none;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;color:#8B7467;transition:transform .2s ease}
.um-select-btn[aria-expanded="true"] .um-select-caret{transform:rotate(180deg)}
.um-select-menu{position:absolute;top:calc(100% + 8px);right:0;z-index:1200;min-width:100%;margin:0;padding:6px;list-style:none;background:#fff;border:1px solid #eadfd9;border-radius:14px;box-shadow:0 18px 44px rgba(60,30,20,.18);transform-origin:top right}
.um-select-menu[hidden]{display:none}
.um-select-menu li{padding:10px 12px;border-radius:10px;color:#5f3425;font-size:.95rem;white-space:nowrap;cursor:pointer;transition:background .12s,color .12s}
.um-select-menu li:hover,.um-select-menu li:focus{background:#f4ece7;outline:none}
.um-select-menu li[aria-selected="true"]{background:#7a4b3a;color:#fcf9f4;font-weight:600}
@media (prefers-reduced-motion:no-preference){
  .um-select-menu{animation:um-menu-in .18s ease}
  .um-select-menu.is-closing{animation:um-menu-out .16s cubic-bezier(0.4, 0, 1, 1) forwards !important}
}
@keyframes um-menu-in{from{opacity:0;transform:translateY(-6px) scale(.98)}to{opacity:1;transform:none}}
@keyframes um-menu-out{from{opacity:1;transform:none}to{opacity:0;transform:translateY(-6px) scale(.96)}}
@media (max-width:560px){.um-search,.um-select{flex:1 1 100%}.um-select-btn{width:100%}}
.um-blank{display:grid;justify-items:center;gap:14px;text-align:center;padding:48px 20px;color:#8B7467}
.um-blank-icon{display:grid;place-items:center;width:56px;height:56px;border-radius:50%;background:#f1e8e3;color:var(--brown)}
.um-blank-icon svg{width:28px;height:28px;fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
.um-blank-art{display:block;width:150px;max-width:70%;height:auto}
.um-blank p{margin:0;color:var(--text);max-width:30ch}
.um-layout{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:16px;align-items:start}
#leaflet-map{height:min(72vh,680px);min-height:420px;border-radius:16px;border:1px solid #d9c9c1;z-index:0}
.um-detail{max-height:min(72vh,680px);overflow-y:auto;background:#fff;color:#5f3425;border:1px solid #e8ded6;border-radius:16px;padding:16px}
.um-empty{color:#8B7467;margin:0}
.um-carousel{position:relative;margin-bottom:12px}
.um-carousel-track{position:relative;z-index:0;width:100%;aspect-ratio:16/10;overflow:hidden;background:transparent}
.um-slide{position:absolute;top:50%;left:50%;width:52%;height:78%;object-fit:cover;display:block;border-radius:10px;background:#eee;opacity:0;z-index:1;transform:translate(-50%,-50%);transition:transform .5s cubic-bezier(.22,.61,.36,1),opacity .5s,box-shadow .5s,width .5s,height .5s;will-change:transform,width,height}
.um-slide.is-active{opacity:1;z-index:3;width:60%;height:92%;transform:translate(-50%,-54%);box-shadow:0 12px 24px rgba(30,20,15,.3)}
.um-slide.is-prev{opacity:.45;z-index:2;transform:translate(-150%,-46%)}
.um-slide.is-next{opacity:.45;z-index:2;transform:translate(50%,-46%)}
.um-carousel-btn{position:absolute;top:50%;transform:translateY(-50%);z-index:4;width:36px;height:36px;padding:0;border:0;background:none;color:var(--brown);display:grid;place-items:center;cursor:pointer;transition:color .18s,opacity .18s}
.um-carousel-btn:hover{color:var(--ink)}
.um-carousel-btn.prev{left:4px}.um-carousel-btn.next{right:4px}
.um-carousel-btn svg{width:28px;height:28px;fill:none;stroke:currentColor;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round}
.um-carousel-count{position:absolute;bottom:8px;right:10px;z-index:4;color:#8B7467;font-size:.75rem;font-weight:600}

.um-detail h2{margin:0 0 10px;font-size:1.25rem;color:var(--ink)}
.um-facts{list-style:none;margin:0 0 14px;padding:0;display:grid;gap:8px}
.um-facts li{display:flex;gap:10px;align-items:flex-start;font-size:.9rem;color:var(--text)}
.um-facts svg{width:17px;height:17px;flex:none;margin-top:1px;fill:none;stroke:var(--brown);stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.um-facts b{color:var(--ink);font-weight:600}

.um-landlord{display:flex;align-items:center;gap:12px;margin:14px 0;padding:12px;border-radius:12px;background:#f7f1ed;border:1px solid #f0e6df}
.um-landlord-avatar{width:44px;height:44px;border-radius:50%;flex:none;background:#e4cbbf;color:#7a4b3a;display:grid;place-items:center;font-weight:700;overflow:hidden;object-fit:cover}
.um-landlord-avatar img{width:100%;height:100%;object-fit:cover;display:block}
.um-landlord-name{font-weight:600;color:var(--ink)}
.um-landlord-role{font-size:.82rem;color:#8B7467}

.um-desc{margin:0 0 14px;line-height:1.5;color:var(--text)}
.um-detail h3{margin:16px 0 8px;font-size:1rem;color:var(--ink)}

.um-room{display:grid;grid-template-columns:64px 1fr;gap:12px;padding:12px;border-radius:12px;background:#f9f4f1;border:1px solid var(--line);margin-top:10px}
.um-room-ph-wrap{position:relative;width:64px;height:64px}
.um-room-photo{width:64px;height:64px;border-radius:10px;object-fit:cover;background:#eee;display:block}
.um-room-ph-n{position:absolute;right:3px;bottom:3px;padding:1px 5px;border-radius:999px;background:rgba(30,25,22,.72);color:#fff;font-size:.68rem;font-weight:600}
.um-room-ph{width:64px;height:64px;border-radius:10px;background:#f1e8e3;color:var(--brown);display:grid;place-items:center}
.um-room-ph svg{width:28px;height:28px;fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
.um-room strong{display:block;color:var(--ink)}
.um-room p{margin:2px 0;font-size:.86rem;color:var(--text)}
.um-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:.78rem;font-weight:600;background:#e6f2ea;color:#216040}
.um-badge.is-occupied{background:#f6e4e4;color:#8a2f2f}
.um-badge.is-maint{background:#f6efd9;color:#7a5f15}
.um-amen{color:var(--text);font-size:.85rem}
.um-room-view{margin-top:8px;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;border:1px solid #d9bea6;background:#fff;color:var(--brown);font:inherit;font-size:.82rem;font-weight:600;cursor:pointer;text-decoration:none;transition:background .18s,border-color .18s,color .18s}
.um-room-view:hover{background:var(--brown);border-color:var(--brown);color:#fff}
.um-room-view svg{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

.um-error{padding:12px;border-radius:12px;background:#f6e4e4;color:#8a2f2f}
@media (max-width:900px){.um-layout{grid-template-columns:1fr}.um-detail{max-height:none}}

/* Map look + cursors (same as the landlord map) */
.um-map .leaflet-tile-pane{filter:saturate(1.6) contrast(1.05)}
.um-map .leaflet-bar{border-radius:9999px;overflow:hidden}
.um-map,.um-map *{cursor:default}                              /* arrow by default */
.um-map .leaflet-interactive{cursor:pointer}                   /* dorm pins and university dots */
.um-map.um-zoom-in,.um-map.um-zoom-in *{cursor:zoom-in}        /* magnifier + */
.um-map.um-zoom-out,.um-map.um-zoom-out *{cursor:zoom-out}     /* magnifier − */
.um-map .leaflet-control,.um-map .leaflet-control *{cursor:pointer}
.um-map .leaflet-bar a[aria-disabled="true"],.um-map .leaflet-bar a.is-disabled{opacity:.4;cursor:default;pointer-events:none}
body.leaflet-dragging .um-map,body.leaflet-dragging .um-map *{cursor:grabbing}   /* hand while dragging */
path.leaflet-interactive:focus,.leaflet-container svg:focus{outline:none}

/* Barely-visible decorative orbs behind the white page panel */
.shell{position:relative}
.shell-orbs{position:absolute;inset:0;overflow:hidden;border-radius:inherit;pointer-events:none;z-index:0}
.shell-orbs i{position:absolute;display:block;border-radius:50%;filter:blur(64px)}
.shell-orbs i:nth-child(1){width:360px;height:360px;background:#F3E2AC;opacity:.3;top:-110px;left:14%}
.shell-orbs i:nth-child(2){width:430px;height:430px;background:#E4CDAF;opacity:.3;bottom:-150px;right:6%}
.shell-orbs i:nth-child(3){width:320px;height:320px;background:#FFBA95;opacity:.2;top:36%;left:50%}
.shell > .topbar{position:relative;z-index:2}
.shell > main{position:relative;z-index:1}
</style>
<link rel="stylesheet" href="../assets/css/tenant-map-panel.css?v=7">
</head>
<body class="map-page">
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
<symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
<symbol id="search-icon" viewBox="0 0 24 24"><circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/></symbol>
<symbol id="caret-icon" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></symbol>
<symbol id="pin-icon" viewBox="0 0 24 24"><path d="M19 9c0 5-7 12-7 12S5 14 5 9a7 7 0 0 1 14 0Z"/><circle cx="12" cy="9" r="2"/></symbol>
<symbol id="people-icon" viewBox="0 0 24 24"><circle cx="9" cy="6" r="3"/><path d="M3 21v-4a6 6 0 0 1 12 0v4M16 3a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 4v4"/></symbol>
<symbol id="school-icon" viewBox="0 0 24 24"><path d="m2 9 10-5 10 5-10 5Z"/><path d="M6 11v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5M22 9v6"/></symbol>
<symbol id="measure-icon" viewBox="0 0 24 24"><path d="M4 20 20 4"/><path d="M13 4h7v7M11 20H4v-7"/></symbol>
<symbol id="lock-icon" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></symbol>
<symbol id="logout-icon" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
</defs></svg>
<a class="skip" href="#map-page">Skip to map</a>
<aside class="sidebar" aria-label="UniStay portal">
<a href="../index.php" class="brand" title="UniStay Home"><img src="../assets/images/tenant/logo.png" width="50" height="50" alt=""><span class="brand-name">UniStay</span></a>
<nav aria-label="Main navigation">
<?php if ($isAuthenticated): ?>
<a href="dashboard.php" title="Dashboard"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
<?php endif; ?>
<a href="dorms.php" title="Dorms"><svg aria-hidden="true"><use href="#bed-icon"/></svg><span class="nav-label">Dorms</span></a>
<a href="map.php" title="Map" aria-current="page"><svg aria-hidden="true"><use href="#map-icon"/></svg><span class="nav-label">Map</span></a>
<?php if ($isAuthenticated): ?>
<a href="settings.php" title="Settings"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
<?php endif; ?>
</nav>
<button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true"><span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span><span class="toggle-label">Collapse</span></button>
</aside>
<div class="shell">
<div class="shell-orbs" aria-hidden="true"><i></i><i></i><i></i></div>
<header class="topbar"><h1>Map</h1><div class="profile">
<?php if ($isAuthenticated): ?>
<button id="notification-bell" class="bell" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="notification-popover"><svg aria-hidden="true"><use href="#bell-icon"/></svg></button>
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
<a class="view-all" id="view-all" href="notifications.php?from=map" aria-label="View all notifications">View all notifications <svg aria-hidden="true"><use href="#arrow-icon"/></svg></a>
</section>
<?php else: ?>
<div class="topbar-auth">
  <a href="../auth/login.php?redirect=tenant/map.php" class="btn-topbar-login">Log in</a>
  <a href="../auth/register.php?redirect=tenant/map.php" class="btn-topbar-signup">Sign up</a>
</div>
<?php endif; ?>
</div></header>
<main id="map-page" tabindex="-1">
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
  <div class="um-layout">
    <div id="leaflet-map" class="um-map" role="region" aria-label="Map of dorms near Dagupan universities"></div>
    <aside class="um-detail" id="um-detail" aria-live="polite" aria-label="Dorm details">
      <div class="um-blank"><span class="um-blank-icon" aria-hidden="true"><svg><use href="#bed-icon"/></svg></span><p>Loading dorm suggestions...</p></div>
    </aside>
  </div>
<noscript>Enable JavaScript to see dorms on the map and open notifications.</noscript>
</main>
</div>
<?php if ($isAuthenticated): ?>
<p id="feedback" class="notification-feedback" role="status"></p>
<!-- Supply tenant notifications here as JSON: [{"id":"unique-id","title":"Title","message":"Message"}]. -->
<script id="notification-data" type="application/json">[]</script>

<?php endif; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../assets/js/store.js"></script>
<script src="../assets/js/tenant/dorm-details.js?v=10"></script>
<script src="../assets/js/tenant/map-panel.js?v=6"></script>
<script src="../assets/js/tenant-map-markers.js?v=7"></script>
<script>
<?php if ($tenantProfile): ?>
window.UNISTAY_TENANT_PROFILE = <?= json_encode($tenantProfile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
<?php endif; ?>
(() => {
  // Landlord-entered text is untrusted: always escape before putting it in innerHTML.
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
  const detail = document.getElementById('um-detail');
  const search = document.getElementById('dorm-search');
  // ---- Universities + map setup (same values as the landlord form) ----
  const UNIVERSITIES = [
    { name: 'Universidad de Dagupan',         lat: 16.0507, lng: 120.3408 },
    { name: 'University of Pangasinan',       lat: 16.0471, lng: 120.3425 },
    { name: 'University of Luzon',            lat: 16.0398, lng: 120.3359 },
    { name: 'Lyceum Northwestern University', lat: 16.0354, lng: 120.3305 },
  ];
  const NEAR_KM = 1.0, PAD = 0.010, STREET_ZOOM = 18;
  const _lats = UNIVERSITIES.map(u => u.lat), _lngs = UNIVERSITIES.map(u => u.lng);
  const BOUNDS = L.latLngBounds(
    [Math.min(..._lats) - PAD, Math.min(..._lngs) - PAD],
    [Math.max(..._lats) + PAD, Math.max(..._lngs) + PAD]);

  // Bounds of the outer edge of the 1 km radius around the given universities
  const kmLat = NEAR_KM / 111.32;
  const circleBounds = (list) => L.latLngBounds(list.flatMap(u => {
    const kmLng = NEAR_KM / (111.32 * Math.cos(u.lat * Math.PI / 180));
    return [[u.lat - kmLat, u.lng - kmLng], [u.lat + kmLat, u.lng + kmLng]];
  }));
  const DEFAULT_BOUNDS = circleBounds(UNIVERSITIES);

  const map = L.map('leaflet-map', { maxBounds: BOUNDS, maxBoundsViscosity: 1.0, maxZoom: STREET_ZOOM,
                                     zoomControl: false,
                                     zoomSnap: 0.25, attributionControl: false });
  L.control.zoom({ position: 'topright' }).addTo(map);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
  map.fitBounds(DEFAULT_BOUNDS);

  // Keep Leaflet in sync while the sidebar animates (fires every frame of the resize)
  new ResizeObserver(() => map.invalidateSize({ animate: false })).observe(map.getContainer());

  // The starting view doubles as the zoomed-out limit; Reset view restores it exactly.
  const HOME_VIEW = { center: map.getCenter(), zoom: map.getZoom() };
  map.setMinZoom(HOME_VIEW.zoom);

  // At min zoom everything allowed is already visible, so lock dragging until the user zooms in.
  const syncDragLock = () => map.getZoom() <= map.getMinZoom() ? map.dragging.disable() : map.dragging.enable();
  map.on('zoomend', syncDragLock);
  syncDragLock();

  // Magnifier cursors briefly after a scroll-wheel / double-click zoom
  const mapEl = map.getContainer();
  let zoomCursorTimer;
  const flashZoomCursor = (out) => {
    mapEl.classList.remove('um-zoom-in', 'um-zoom-out');
    mapEl.classList.add(out ? 'um-zoom-out' : 'um-zoom-in');
    clearTimeout(zoomCursorTimer);
    zoomCursorTimer = setTimeout(() => mapEl.classList.remove('um-zoom-in', 'um-zoom-out'), 450);
  };
  mapEl.addEventListener('wheel', (e) => flashZoomCursor(e.deltaY > 0), { passive: true });
  mapEl.addEventListener('dblclick', (e) => flashZoomCursor(e.shiftKey));

  // ---- University dots + 1 km radius ----
  const areaCircles = L.layerGroup().addTo(map);
  const uniDots = [];
  UNIVERSITIES.forEach(u => {
    L.circle([u.lat, u.lng], { radius: NEAR_KM * 1000, color: '#6366f1', weight: 1.5, dashArray: '4', fillOpacity: 0.12, interactive: false }).addTo(areaCircles);
    const dot = L.circleMarker([u.lat, u.lng], { radius: 6, color: '#4338ca', fillColor: '#6366f1', fillOpacity: 1, bubblingMouseEvents: false })
      .addTo(map).bindTooltip(u.name);
    // A clicked SVG node can take focus and scroll into view, which reads as the map shaking.
    const unfocusable = () => {
      const el = dot.getElement();
      if (el) { el.setAttribute('focusable', 'false'); el.setAttribute('tabindex', '-1'); }
    };
    unfocusable();
    dot.on('click', (e) => {
      L.DomEvent.stop(e);
      unfocusable();
      const el = dot.getElement();
      if (el) el.blur();
      if (map.getZoom() < STREET_ZOOM) map.flyTo([u.lat, u.lng], STREET_ZOOM, { duration: 0.8 });   // at max zoom: do nothing
    });
    uniDots.push(dot);
  });
  mapEl.addEventListener('mousedown', (e) => {
    if (e.target.closest && e.target.closest('path.leaflet-interactive')) e.preventDefault();
  });

  // Reset view button (pill-shaped)
  let resetBtn = null;
  const ResetView = L.Control.extend({
    options: { position: 'bottomleft' },
    onAdd() {
      const bar = L.DomUtil.create('div', 'leaflet-bar');
      const btn = L.DomUtil.create('a', '', bar);
      btn.href = '#'; btn.role = 'button';
      btn.title = 'Reset view back to the original starting view';
      btn.setAttribute('aria-label', 'Reset map view');
      btn.textContent = '⤢ Reset view';
      btn.style.cssText = 'width:auto;padding:0 14px;font-size:12px;line-height:30px;white-space:nowrap';
      L.DomEvent.disableClickPropagation(bar);
      L.DomEvent.on(btn, 'click', (e) => {
        L.DomEvent.preventDefault(e);
        if (map.getZoom() <= map.getMinZoom()) return;   // already at the starting view
        map.flyTo(HOME_VIEW.center, HOME_VIEW.zoom, { duration: 0.8 });
      });
      resetBtn = btn;
      return bar;
    },
  });
  map.addControl(new ResetView());

  // Reset view has nothing left to restore at min zoom, so disable it there.
  const syncResetBtn = () => {
    if (!resetBtn) return;
    const atMin = map.getZoom() <= map.getMinZoom();
    resetBtn.setAttribute('aria-disabled', String(atMin));
    resetBtn.classList.toggle('is-disabled', atMin);
  };
  map.on('zoomend', syncResetBtn);
  syncResetBtn();

  // Hide the dashed radius circles once zoomed in close
  map.on('zoomend', () => {
    const close = map.getZoom() >= STREET_ZOOM - 1;
    close ? map.removeLayer(areaCircles) : map.addLayer(areaCircles);
  });

  // Dots fade out while zooming and back in afterwards
  function fadeDots(visible) {
    uniDots.forEach(d => {
      const el = d.getElement();
      if (!el) return;
      el.style.transition = 'opacity .3s';
      el.style.opacity = visible ? 1 : 0;
      el.style.pointerEvents = visible ? '' : 'none';
      if (!visible) { el.blur(); d.closeTooltip(); }
    });
  }
  map.on('zoomstart', () => fadeDots(false));
  map.on('zoomend', () => fadeDots(true));

  // At max zoom a dot click would do nothing, so make the dots unselectable there.
  function syncDotSelectable() {
    const selectable = map.getZoom() < STREET_ZOOM;
    uniDots.forEach(d => {
      if (d.options.interactive === selectable) return;
      d.options.interactive = selectable;
      if (!selectable) d.closeTooltip();
      if (map.hasLayer(d)) { d.remove(); d.addTo(map); }   // Leaflet only reads `interactive` when the path is built
    });
  }
  map.on('zoomend', syncDotSelectable);
  syncDotSelectable();

  // ---- Dorm details panel ----
  function landlordInitials(name) {
    return String(name || '')
      .trim().split(/\s+/).filter(Boolean).slice(0,2)
      .map(w => w[0]).join('').toUpperCase() || '?';
  }

  const IS_AUTHENTICATED = <?= $isAuthenticated ? 'true' : 'false' ?>;

  const mapPanel = createTenantMapPanel({
    detail, map, universities: UNIVERSITIES, profile: window.UNISTAY_TENANT_PROFILE || null,
    authenticated: IS_AUTHENTICATED, profileCompleted: <?= (!$profileIncomplete && $isAuthenticated) ? 'true' : 'false' ?>,
    openLandlordProfile,
    openGateModal() {
      const modal = document.getElementById('auth-gate-modal');
      if (!modal) return;
      if (IS_AUTHENTICATED) {
        modal.querySelector('h2').textContent = 'Complete your profile to unlock rooms';
        modal.querySelector('p').textContent = 'Finish your registration details in Settings to explore all room options.';
        modal.querySelector('.auth-gate-actions').innerHTML = '<a class="btn-auth-primary" href="settings.php?mode=edit">Complete profile</a>';
      }
      modal.showModal();
    }
  });

  // ==========================================
  // Landlord Profile Modal Controller for Map
  // ==========================================
  const landlordProfileModal = document.getElementById('landlord-profile-modal');
  const landlordProfileCloseBtn = document.getElementById('landlord-profile-close-btn');

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
      const allDorms = (window.allMapDorms || [prop]);
      const landlordProps = allDorms.filter(x => {
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

  // ---- Pins + search / university filter ----
  const PIN_ZOOM = 17;

  // Picking a pin zooms in on it (unless already closer) and shows its details.
  const selectDorm = p => mapPanel.selectDorm(p);

  // University picked: smoothly zoom straight in on that university's location (or starting view for "All universities")
  function moveToFilter(value) {
    const u = UNIVERSITIES.find(x => x.name === value);
    if (!value) map.flyTo(HOME_VIEW.center, HOME_VIEW.zoom, { duration: 0.8 });
    else if (u) map.flyTo([u.lat, u.lng], 16.5, { duration: 0.8 });
  }

  (async () => {
    try {
      const markers = await initDormMarkers(map, selectDorm);
      window.allMapDorms = markers.properties;
      mapPanel.setProperties(markers.properties);

      // ---- Custom university dropdown (open list is styled + animated) ----
      const uniSelect = document.getElementById('uni-select');
      const uniBtn = uniSelect.querySelector('.um-select-btn');
      const uniMenu = uniSelect.querySelector('.um-select-menu');
      const uniValueEl = uniSelect.querySelector('.um-select-value');
      const uni = { value: '' };

      let uniCloseTimer = null;
      const closeUni = () => {
        if (uniMenu.hidden || uniMenu.classList.contains('is-closing')) return;
        clearTimeout(uniCloseTimer);
        uniBtn.setAttribute('aria-expanded', 'false');
        uniMenu.classList.add('is-closing');
        uniCloseTimer = setTimeout(() => {
          uniMenu.hidden = true;
          uniMenu.classList.remove('is-closing');
        }, 160);
      };
      const openUni = () => {
        clearTimeout(uniCloseTimer);
        uniMenu.classList.remove('is-closing');
        uniMenu.hidden = false;
        uniBtn.setAttribute('aria-expanded', 'true');
      };
      uniBtn.addEventListener('click', () => uniMenu.hidden ? openUni() : closeUni());
      uniMenu.addEventListener('click', (e) => {
        const li = e.target.closest('[role="option"]');
        if (!li) return;
        uni.value = li.dataset.value;
        uniValueEl.textContent = li.textContent;
        uniBtn.classList.toggle('is-placeholder', !uni.value);
        uniMenu.querySelectorAll('[role="option"]').forEach(o => o.setAttribute('aria-selected', String(o === li)));
        search.value = '';   // picking a university resets the name search
        closeUni();
        uniBtn.focus();
        apply({ fly: true });
      });
      // Keyboard: arrow keys move, Enter/Space choose, Escape closes.
      uniMenu.addEventListener('keydown', (e) => {
        const opts = [...uniMenu.querySelectorAll('[role="option"]')];
        const i = opts.indexOf(document.activeElement);
        if (e.key === 'ArrowDown') { e.preventDefault(); opts[Math.min(i + 1, opts.length - 1)].focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); opts[Math.max(i - 1, 0)].focus(); }
        else if ((e.key === 'Enter' || e.key === ' ') && document.activeElement.matches('[role="option"]')) { e.preventDefault(); document.activeElement.click(); }
      });
      document.addEventListener('click', (e) => { if (!uniSelect.contains(e.target)) closeUni(); });
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeUni(); });

      const apply = ({ fly = false } = {}) => {
        const query = search.value.trim();
        markers.show(uni.value, query);
        if (fly) moveToFilter(uni.value);
        mapPanel.setFilters(uni.value, query);
      };
      mapPanel.setRefresh(async () => {
        await markers.refresh(uni.value, search.value.trim());
        window.allMapDorms = markers.properties;
        mapPanel.setProperties(markers.properties);
      });
      search.addEventListener('keydown', event => {
        if (event.key === 'Enter') { event.preventDefault(); apply(); }
      });
      search.addEventListener('input', () => { if (!search.value.trim()) apply(); });
      map.on('click', () => mapPanel.showSuggestions());
    } catch (err) {
      detail.innerHTML = `<p class="um-error">Couldn’t load dorms: ${esc(err.message)}. Refresh the page to try again.</p>`;
    }
  })();
})();
</script>
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
            <p>Would you like to complete the remaining details now, or finish it later?</p>
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

  if (sessionStorage.getItem('unistay_map_toast_dismissed') === '1') {
    toast.remove();
    return;
  }

  function dismiss() {
    toast.classList.add('is-dismissing');
    sessionStorage.setItem('unistay_map_toast_dismissed', '1');
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
<!-- Landlord Profile Modal (Rectangular 2-Column Workstation Card) -->
<dialog id="landlord-profile-modal" class="landlord-profile-modal" aria-labelledby="landlord-modal-name">
  <div class="landlord-profile-card-rect">
    <button type="button" class="landlord-profile-close-btn" id="landlord-profile-close-btn" aria-label="Close landlord profile">✕</button>

    <!-- Left Column: UniStay Brown Gradient Theme (Landlord Details & Contact) -->
    <div class="landlord-profile-col-left">
      <div class="landlord-avatar-wrap">
        <div id="landlord-avatar-container"></div>
        <span class="landlord-verified-badge" title="Verified Landlord">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </span>
      </div>
      <h2 id="landlord-modal-name" class="landlord-profile-name">Landlord Name</h2>
      <div id="landlord-modal-context" class="landlord-profile-context"></div>
      <div class="landlord-profile-meta">
        <span class="landlord-status-tag">Verified Landlord</span>
        <span class="meta-dot"></span>
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

<!-- "Log in to view rooms" Auth Gate Modal -->
<dialog id="auth-gate-modal" class="auth-gate-modal" aria-labelledby="auth-gate-title">
  <div class="auth-gate-card">
    <button type="button" class="auth-gate-close" id="mapAuthGateCloseBtn" aria-label="Close">✕</button>
    <div class="auth-gate-icon">
      <svg aria-hidden="true"><use href="#bed-icon"/></svg>
    </div>
    <h2 id="auth-gate-title">Log in to view rooms</h2>
    <p>Create an account or log in to view complete room photos, check availability, and send booking inquiries to landlords.</p>
    <div class="auth-gate-actions">
      <a href="../auth/login.php?redirect=tenant/map.php" class="btn-auth-primary">Log in</a>
      <a href="../auth/register.php?redirect=tenant/map.php" class="btn-auth-secondary">Sign up</a>
    </div>
  </div>
</dialog>
<script>
(function() {
  const mClose = document.getElementById('mapAuthGateCloseBtn');
  const mDialog = document.getElementById('auth-gate-modal');
  function closeMapGate() {
    if (!mDialog || !mDialog.open || mDialog.classList.contains('is-closing')) return;
    mDialog.classList.add('is-closing');
    setTimeout(() => {
      mDialog.close();
      mDialog.classList.remove('is-closing');
    }, 200);
  }
  if (mClose && mDialog) {
    mClose.addEventListener('click', closeMapGate);
    mDialog.addEventListener('click', (e) => {
      const rect = mDialog.getBoundingClientRect();
      if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) {
        closeMapGate();
      }
    });
    mDialog.addEventListener('cancel', (e) => {
      e.preventDefault();
      closeMapGate();
    });
  }
})();
</script>
<script>
window.UNISTAY_NOTIFICATION_ACCOUNT = <?= json_encode(['id' => (int)($_SESSION['user_id'] ?? 0), 'role' => 'tenant']) ?>;
</script>
<script src="../assets/js/notification-preferences.js?v=2"></script>
<script src="../assets/js/tenant/notifications.js?v=7"></script>
</body>
</html>
