<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

start_app_session();
$pdo = database();
$adminProfile = require_signed_in('admin');

$successMessage = '';
$errorMessage = '';

if (!empty($_GET['landlord_created'])) {
    $successMessage = 'Landlord account for ' . htmlspecialchars($_GET['landlord_created'], ENT_QUOTES, 'UTF-8') . ' was successfully created and verified!';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user') {
    $targetId = trim((string)($_POST['target_user_id'] ?? ''));
    $targetRole = trim((string)($_POST['target_role'] ?? ''));
    if ($targetRole === 'admin' && (string)$targetId === (string)$adminProfile['id']) {
        $errorMessage = 'You cannot delete your own admin account while signed in.';
    } elseif ($targetId !== '') {
        $delRes = delete_user_account_completely($pdo, $targetId, $targetRole ?: null);
        if ($delRes['ok']) {
            $successMessage = "Account {$targetId} and all associated listings, rooms, bookings, applications, and activity were completely deleted from everywhere.";
        } else {
            $errorMessage = $delRes['message'];
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'suspend_user') {
    $targetId = trim((string)($_POST['target_user_id'] ?? ''));
    $targetRole = trim((string)($_POST['target_role'] ?? ''));
    $reason = trim((string)($_POST['suspension_reason'] ?? ''));
    if ($targetRole === 'admin') {
        $errorMessage = 'Administrative accounts cannot be suspended.';
    } elseif ($targetId !== '') {
        $res = admin_suspend_user($pdo, $targetId, $targetRole, $reason);
        if ($res['ok']) {
            $successMessage = $res['message'];
        } else {
            $errorMessage = $res['message'];
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unsuspend_user') {
    $targetId = trim((string)($_POST['target_user_id'] ?? ''));
    $targetRole = trim((string)($_POST['target_role'] ?? ''));
    if ($targetId !== '') {
        $res = admin_unsuspend_user($pdo, $targetId, $targetRole);
        if ($res['ok']) {
            $successMessage = $res['message'];
        } else {
            $errorMessage = $res['message'];
        }
    }
}

// Search & Filter
$search = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? 'all';

$sql = "SELECT tenant_id AS id, tenant_id AS code, first_name, last_name, email, email AS personal_email, phone_number, 'tenant' AS role, verification_status, account_status, suspension_reason, suspended_at, created_at FROM tenants
        UNION ALL
        SELECT landlord_id AS id, landlord_id AS code, first_name, last_name, email, email AS personal_email, phone_number, 'landlord' AS role, status AS verification_status, (CASE WHEN status = 'suspended' THEN 'suspended' ELSE 'active' END) AS account_status, suspension_reason, suspended_at, created_at FROM landlords
        UNION ALL
        SELECT admin_id AS id, admin_id AS code, first_name, last_name, email, email AS personal_email, phone_number, 'admin' AS role, 'verified' AS verification_status, 'active' AS account_status, NULL AS suspension_reason, NULL AS suspended_at, created_at FROM admins";

$wrapSql = "SELECT * FROM ($sql) AS u WHERE 1=1";
$params = [];

$roleFilter = strtolower(trim((string)($_GET['role'] ?? 'all')));
if (!in_array($roleFilter, ['all', 'landlord', 'tenant', 'admin'], true)) {
    $roleFilter = 'all';
}

if ($search !== '') {
    $cleanCode = strtoupper(trim($search));
    $num = (int)preg_replace('/\D/', '', $search);
    $wrapSql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone_number LIKE ? OR code = ? OR CONCAT('#', code) = ?" . ($num > 0 ? " OR id = ?" : "") . ")";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $cleanCode;
    $params[] = $cleanCode;
    if ($num > 0) {
        $params[] = $num;
    }
}

$wrapSql .= " ORDER BY created_at DESC, id DESC";
$stmt = $pdo->prepare($wrapSql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts for sliding tabs
$countAll = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM tenants) + (SELECT COUNT(*) FROM landlords) + (SELECT COUNT(*) FROM admins)")->fetchColumn();
$countLandlords = (int)$pdo->query("SELECT COUNT(*) FROM landlords")->fetchColumn();
$countTenants = (int)$pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
$countAdmins = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();

// Administrator profile metadata
$adminInitials = strtoupper(substr($adminProfile['name'] ?? 'A', 0, 1) . substr(strstr($adminProfile['name'] ?? '', ' ') ?: ($adminProfile['name'] ?? 'A'), 1, 1));
if (strlen($adminInitials) < 2) $adminInitials = strtoupper(substr($adminProfile['name'] ?? 'AD', 0, 2));
$effectiveAvatar = resolve_user_profile_photo_url($adminProfile['photo'] ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users · UniStay Admin</title>
    <link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/common.css?v=41">
    <script src="../assets/js/theme.js"></script>
    <script src="../assets/js/tenant/popover.js?v=2"></script>
    <script src="../assets/js/tenant/sidebar.js?v=11"></script>
    <script src="../assets/js/tenant/profile-menu.js?v=31"></script>
    <script>
      window.UNISTAY_TENANT_PROFILE = <?= json_encode([
        'name' => $adminProfile['name'],
        'role' => 'Administrator',
        'photo' => $adminProfile['photo'] ?? '',
        'initials' => $adminInitials,
      ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
      window.UNISTAY_NOTIFICATION_ACCOUNT = <?= json_encode(['id' => (int)$adminProfile['id'], 'role' => 'admin']) ?>;
    </script>
    <script defer src="../assets/js/notification-preferences.js?v=3"></script>
    <script defer src="../assets/js/notification-global.js?v=1"></script>
    <style>
        body {
            background-color: #f7f3ef;
            color: #292524;
            font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            margin: 0;
            display: flex;
            min-height: 100vh;
        }

        .admin-users-page .topbar h1 {
            font-family: 'Fraunces', Georgia, "Times New Roman", serif !important;
            font-weight: 700 !important;
            color: #5f3425 !important;
            letter-spacing: -0.6px;
            font-size: 2.1rem;
            margin: 0;
        }

        .shell-orbs {
            position: absolute;
            inset: 0;
            overflow: hidden;
            border-radius: inherit;
            pointer-events: none;
            z-index: 0;
        }
        .shell-orbs i {
            position: absolute;
            display: block;
            border-radius: 50%;
            filter: blur(64px);
        }
        .shell-orbs i:nth-child(1) { width: 360px; height: 360px; background: #F3E2AC; opacity: .3; top: -110px; left: 14%; }
        .shell-orbs i:nth-child(2) { width: 430px; height: 430px; background: #E4CDAF; opacity: .3; bottom: -150px; right: 6%; }
        .shell-orbs i:nth-child(3) { width: 320px; height: 320px; background: #FFBA95; opacity: .2; top: 36%; left: 50%; }

        @media (min-width: 761px) {
            .admin-users-page .shell {
                min-height: calc(100vh - 30px);
                height: calc(100vh - 30px);
                border-radius: 22px;
                padding: 0 54px 24px;
                display: flex;
                flex-direction: column;
                overflow: hidden;
            }

            .admin-users-page .topbar {
                flex-shrink: 0;
                position: relative;
                z-index: 1000;
                height: auto !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: stretch !important;
                border-bottom: 1px solid #ebd9cb !important;
                padding: 0 !important;
                gap: 0 !important;
            }

            .admin-users-page .topbar-main-row {
                height: 80px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 20px;
                padding: 0;
            }

            .admin-users-page .profile {
                display: flex;
                align-items: center;
                gap: 24px;
                position: relative;
                z-index: 1001;
                margin-left: auto;
            }

            .admin-users-page #users-main {
                flex: 1;
                min-height: 0;
                overflow-y: auto;
                overflow-x: hidden;
                padding-top: 24px;
                padding-bottom: 30px;
                margin-right: -54px;
                padding-right: 42px;
                scrollbar-color: var(--brown, #7a4b3a) #F3E9DF;
                scrollbar-width: thin;
            }

            .admin-users-page #users-main::-webkit-scrollbar {
                width: 12px;
            }

            .admin-users-page #users-main::-webkit-scrollbar-track {
                background: #F3E9DF;
                border-radius: 999px;
            }

            .admin-users-page #users-main::-webkit-scrollbar-thumb {
                background: var(--brown, #7a4b3a);
                border: 3px solid #F3E9DF;
                border-radius: 999px;
            }
        }

        /* Full-Width Categories Bar at the Bottom of Topbar Line */
        .topbar-categories-bar {
            display: flex;
            width: 100%;
            border-top: 1px solid #ebd9cb;
            margin: 0 0 -1px 0;
            padding: 0;
            background: rgba(253, 250, 245, 0.7);
            position: relative;
            z-index: 2;
        }

        .topbar-cat-tab {
            flex: 1 1 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 16px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.94rem;
            font-weight: 600;
            color: #7a4b3a;
            text-decoration: none;
            text-align: center;
            white-space: nowrap;
            background: transparent;
            border: 0;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            transition: color 0.2s ease, border-color 0.2s ease, background-color 0.2s ease;
        }

        .topbar-cat-tab:hover {
            color: #5f3425;
            background: rgba(122, 75, 58, 0.04);
        }

        .topbar-cat-tab.is-active {
            color: #5f3425;
            font-weight: 700;
            border-bottom-color: transparent;
            background: rgba(122, 75, 58, 0.02);
        }

        .topbar-cat-indicator {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background: #7a4b3a;
            border-radius: 3px 3px 0 0;
            z-index: 3;
            pointer-events: none;
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1), width 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .cat-count-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            background: #ebd9cb;
            color: #5f3425;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .topbar-cat-tab.is-active .cat-count-badge {
            background: #7a4b3a;
            color: #ffffff;
        }

        /* Controls Row inside Main */
        .users-controls-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .search-form {
            display: flex;
            align-items: center;
            margin: 0;
        }

        /* Snug Search Bar Matching Landlord Dorms */
        .um-search {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 0 0 240px;
            width: 240px;
            max-width: 240px;
            min-width: 0;
            height: 44px;
            padding: 0 16px;
            border: 1px solid #d9bea6;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(84, 60, 48, 0.08);
            transition: border-color 0.18s, box-shadow 0.18s;
        }

        .um-search svg {
            width: 17px;
            height: 17px;
            flex: none;
            stroke: #8b7467;
            stroke-width: 2;
            fill: none;
        }

        .um-search input {
            flex: 1;
            min-width: 0;
            border: 0;
            background: none;
            padding: 0;
            font: inherit;
            color: #5f3425;
            outline: none;
        }

        .um-search input::placeholder {
            color: #8b7467;
            opacity: 1;
        }

        .um-search:focus-within {
            border-color: #7a4b3a;
            box-shadow: 0 2px 8px rgba(84, 60, 48, 0.12), 0 0 0 3px rgba(122, 75, 58, 0.14);
        }

        @media (max-width: 760px) {
            .um-search {
                width: 100% !important;
                max-width: 100% !important;
                flex: 1 1 auto !important;
            }
        }

        .btn-create-landlord {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0 20px;
            height: 42px;
            background: #7a4b3a;
            color: #ffffff;
            border: 0;
            border-radius: 999px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
            box-shadow: 0 2px 8px rgba(122, 75, 58, 0.2);
            white-space: nowrap;
        }

        .btn-create-landlord:hover {
            background: #5c3527;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(122, 75, 58, 0.3);
            color: #ffffff;
        }

        .btn-create-landlord:active {
            transform: translateY(0);
        }

        /* Tenants Table Card matching Landlord Requests Table */
        .tenants-table-card {
            background: #ffffff;
            border: 1px solid #ebd9cb;
            border-radius: 20px;
            box-shadow: 0 6px 18px rgba(92, 62, 48, 0.08);
            overflow: hidden;
        }

        .tenants-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .tenants-table th {
            background: #fbf5ec;
            padding: 16px 20px;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #8c7569;
            border-bottom: 1px solid #ebd9cb;
            font-weight: 700;
        }

        .tenants-table td {
            padding: 18px 20px;
            border-bottom: 1px solid #f3ece4;
            font-size: 0.92rem;
            color: #3b2820;
            vertical-align: middle;
        }

        .tenants-table tr:last-child td {
            border-bottom: none;
        }

        .tenants-table tr:hover td {
            background: #fdfaf6;
        }

        .date-col {
            white-space: nowrap;
        }

        /* Role Badges */
        .badge-role {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-role.tenant { background: #e0f2fe; color: #0369a1; }
        .badge-role.landlord { background: #fef3c7; color: #92400e; }
        .badge-role.admin { background: #f3e8ff; color: #6b21a8; }

        /* Review Button - Styled like Landing Page Login Button */
        .btn-inspect {
            font: 500 13px 'DM Sans', sans-serif;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 16px;
            height: 32px;
            border-radius: 8px;
            background: #7a4b3a;
            border: 1px solid #7a4b3a;
            color: #ffffff !important;
            font-weight: 600;
            text-decoration: none !important;
            transition: transform 0.18s cubic-bezier(0.2, 0, 0, 1), box-shadow 0.18s ease, background-color 0.18s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
            cursor: pointer;
            transform-origin: center center;
        }

        .btn-inspect:hover {
            background: #5c3527;
            border-color: #5c3527;
            transform: scale(1.06) translateY(-1px);
            box-shadow: 0 4px 12px rgba(92, 53, 39, 0.28);
        }

        .btn-inspect:active {
            background: #452618;
            transform: scale(0.96) translateY(0);
        }

        /* Status Text (Plain text Approved without icon) */
        .status-approved {
            color: #065f46;
            font-weight: 700;
            font-size: 0.86rem;
        }

        .badge-status-suspended {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 700;
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* Suspend Button - Red Styling */
        .btn-suspend-user {
            padding: 6px 14px;
            font-size: 0.82rem;
            border-radius: 8px;
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: #dc2626;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.18s ease;
            white-space: nowrap;
        }

        .btn-suspend-user:hover {
            background: #dc2626;
            border-color: #dc2626;
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(220, 38, 38, 0.25);
        }

        .btn-unsuspend-user {
            padding: 6px 14px;
            font-size: 0.82rem;
            border-radius: 8px;
            border: 1px solid #a7f3d0;
            background: #ffffff;
            color: #059669;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.18s ease;
            white-space: nowrap;
        }

        .btn-unsuspend-user:hover {
            background: #ecfdf5;
            border-color: #10b981;
            transform: translateY(-1px);
        }

        .alert-banner {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.92rem;
            line-height: 1.5;
        }
        .alert-banner.success {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }
        .alert-banner.error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        /* Suspend Modal Dialog */
        .suspend-modal-dialog {
            border: 0;
            border-radius: 20px;
            padding: 28px 32px;
            width: 100%;
            max-width: 520px;
            background: #ffffff;
            box-shadow: 0 24px 60px rgba(60, 30, 20, 0.22);
        }
        .suspend-modal-dialog::backdrop {
            background: rgba(30, 15, 10, 0.45);
            backdrop-filter: blur(4px);
        }
        .cl-dialog-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
        }
        .cl-dialog-head h2 {
            margin: 0;
            font-family: 'Fraunces', serif;
            font-size: 1.45rem;
            color: #3b2820;
        }
        .cl-dialog-close {
            background: none;
            border: 0;
            font-size: 1.2rem;
            color: #8c7569;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
        }
        .cl-dialog-close:hover {
            background: #f5ebe3;
            color: #3b2820;
        }
        .cl-dialog-desc {
            margin: 0 0 20px;
            font-size: 0.88rem;
            color: #78665c;
        }
        .cl-form {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .cl-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .cl-field label {
            font-size: 0.82rem;
            font-weight: 600;
            color: #593f34;
        }
        .cl-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
        }
        .btn-cl-cancel {
            padding: 10px 18px;
            border: 1px solid #ebd9cb;
            background: #ffffff;
            color: #78665c;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-cl-submit {
            padding: 10px 22px;
            border: 0;
            background: #dc2626;
            color: #ffffff;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.18s;
        }
        .btn-cl-submit:hover {
            background: #b91c1c;
        }
    </style>
</head>
<body class="admin-users-page">
    <svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" style="display:none;"><defs>
        <symbol id="grid-icon" viewBox="0 0 24 24"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></symbol>
        <symbol id="badge-icon" viewBox="0 0 24 24"><path d="m12 2 3 3 4-1 1 4 3 3-3 3-1 4-4 1-3 3-3-3-4-1-1-4-3-3 3-3 1-4 4 1Z"/><path d="m8 12 3 3 5-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="user-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></symbol>
        <symbol id="mail-icon" viewBox="0 0 24 24"><path d="M3 5h18v14H3z" fill="none" stroke="currentColor" stroke-width="2"/><path d="m3 6 9 7 9-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
        <symbol id="bell-icon" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M10 20h4"/></symbol>
        <symbol id="bell-off-icon" viewBox="0 0 24 24"><path d="m3 3 18 18M10 20h4M6 6c-1 2 0 7-3 10h13M18 13V8a6 6 0 0 0-8-6"/></symbol>
        <symbol id="arrow-icon" viewBox="0 0 24 24"><path d="M4 12h16m-6-6 6 6-6 6"/></symbol>
        <symbol id="search-icon" viewBox="0 0 24 24"><circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/></symbol>
        <symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
        <symbol id="logout-icon" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    </defs></svg>

    <!-- Admin Sidebar Navigation -->
    <aside class="sidebar" aria-label="UniStay Admin Portal">
        <a href="../index.php" class="brand" title="UniStay Home">
            <img src="../assets/images/tenant/logo.png" width="50" height="50" alt="UniStay logo">
            <span class="brand-name">UniStay</span>
        </a>
        <nav aria-label="Admin navigation">
            <a href="dashboard.php" title="Dashboard"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
            <a href="verification.php" title="Verification"><svg aria-hidden="true"><use href="#badge-icon"/></svg><span class="nav-label">Verification</span></a>
            <a href="users.php" title="Users" aria-current="page"><svg aria-hidden="true"><use href="#user-icon"/></svg><span class="nav-label">Users</span></a>
            <a href="settings.php" title="Settings"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
        </nav>
        <button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true">
            <span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span>
            <span class="toggle-label">Collapse</span>
        </button>
    </aside>

    <div class="shell">
        <div class="shell-orbs" aria-hidden="true"><i></i><i></i><i></i></div>

        <header class="topbar">
            <div class="topbar-main-row">
                <h1>User Directory</h1>
                <div class="profile">
                    <button type="button" class="btn-theme-toggle" data-theme-toggle aria-label="Toggle theme" title="Toggle theme"></button>
                    <button id="notification-bell" class="bell" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="notification-popover">
                        <svg aria-hidden="true"><use href="#bell-icon"/></svg>
                    </button>
                    <button class="avatar" type="button" aria-label="Open profile menu" aria-expanded="false" aria-controls="profile-popover" <?= !empty($effectiveAvatar) ? 'style="background-image:url(\''.htmlspecialchars($effectiveAvatar, ENT_QUOTES, 'UTF-8').'\');"' : '' ?>>
                        <?php if (!empty($effectiveAvatar)): ?>
                            <img src="<?= htmlspecialchars($effectiveAvatar) ?>" alt="" referrerpolicy="no-referrer" class="avatar-img" onerror="this.style.display='none';if(this.nextElementSibling)this.nextElementSibling.style.display='inline-flex';">
                            <span style="display:none;"><?= htmlspecialchars($adminInitials) ?></span>
                        <?php else: ?>
                            <?= htmlspecialchars($adminInitials) ?>
                        <?php endif; ?>
                    </button>
                    <section id="notification-popover" class="popover" aria-labelledby="popover-title" hidden>
                        <h2 id="popover-title">Notifications</h2>
                        <div class="empty" id="popover-empty"><span class="empty-icon"><svg aria-hidden="true"><use href="#bell-off-icon"/></svg></span><strong>No new notifications</strong><p>System activities will appear here.</p></div>
                        <ul class="notification-list" id="popover-list" aria-label="Unread notifications" hidden></ul>
                        <a class="view-all" id="view-all" href="notifications.php?from=users" aria-label="View all notifications">View all notifications <svg aria-hidden="true"><use href="#arrow-icon"/></svg></a>
                    </section>
                </div>
            </div>

            <!-- Full-Width Sliding Tabs under horizontal line -->
            <nav class="topbar-categories-bar" aria-label="User Directory categories">
                <span class="topbar-cat-indicator" aria-hidden="true"></span>
                <button type="button" class="topbar-cat-tab <?= $roleFilter === 'all' ? 'is-active' : '' ?>" data-role="all" aria-selected="<?= $roleFilter === 'all' ? 'true' : 'false' ?>">
                    <span>All Users</span>
                    <span class="cat-count-badge" id="count-all"><?= $countAll ?></span>
                </button>
                <button type="button" class="topbar-cat-tab <?= $roleFilter === 'landlord' ? 'is-active' : '' ?>" data-role="landlord" aria-selected="<?= $roleFilter === 'landlord' ? 'true' : 'false' ?>">
                    <span>Landlords</span>
                    <span class="cat-count-badge" id="count-landlords"><?= $countLandlords ?></span>
                </button>
                <button type="button" class="topbar-cat-tab <?= $roleFilter === 'tenant' ? 'is-active' : '' ?>" data-role="tenant" aria-selected="<?= $roleFilter === 'tenant' ? 'true' : 'false' ?>">
                    <span>Students</span>
                    <span class="cat-count-badge" id="count-students"><?= $countTenants ?></span>
                </button>
                <button type="button" class="topbar-cat-tab <?= $roleFilter === 'admin' ? 'is-active' : '' ?>" data-role="admin" aria-selected="<?= $roleFilter === 'admin' ? 'true' : 'false' ?>">
                    <span>Admins</span>
                    <span class="cat-count-badge" id="count-admins"><?= $countAdmins ?></span>
                </button>
            </nav>
        </header>

        <main id="users-main" class="users-main-card">
            <?php if (!empty($successMessage)): ?>
                <div class="alert-banner success"><?= $successMessage ?></div>
            <?php endif; ?>
            <?php if (!empty($errorMessage)): ?>
                <div class="alert-banner error"><?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>

            <!-- Controls Row: Snug Search Bar with Icon + Create Landlord Account Link -->
            <div class="users-controls-row">
                <form class="search-form" method="GET" action="users.php" id="usersSearchForm">
                    <label class="um-search">
                        <svg aria-hidden="true"><use href="#search-icon"/></svg>
                        <input type="search" name="search" id="user-search" placeholder="Search users..." value="<?= htmlspecialchars($search) ?>" autocomplete="off">
                    </label>
                    <?php if ($roleFilter !== 'all'): ?>
                        <input type="hidden" name="role" id="hiddenRoleFilter" value="<?= htmlspecialchars($roleFilter) ?>">
                    <?php endif; ?>
                </form>

                <!-- Direct link to exclusive signup page for admin -->
                <a href="../auth/landlord-register.php?admin_create=1" class="btn-create-landlord">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    <span>Create Landlord Account</span>
                </a>
            </div>

            <!-- Table Card matching Landlord Requests Table -->
            <div class="tenants-table-card" id="users-table-card">
                <table class="tenants-table" id="users-table">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Verification Status</th>
                            <th>Date Registered</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="users-table-body">
                        <?php if (empty($users)): ?>
                            <tr id="users-empty-row"><td colspan="8" style="text-align:center; color:#8c7569; padding:32px;">No users found matching your search.</td></tr>
                        <?php else: ?>
                            <tr id="users-empty-row" style="display:none;"><td colspan="8" style="text-align:center; color:#8c7569; padding:32px;">No users found in this category.</td></tr>
                            <?php foreach ($users as $u): 
                                $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                                if ($name === '') $name = (string)($u['email'] ?? ($u['personal_email'] ?? 'User'));
                                $role = strtolower($u['role'] ?? 'tenant');
                                $vStatus = $u['verification_status'] ?? 'unverified';
                                $isInitialHidden = ($roleFilter !== 'all' && $roleFilter !== $role);
                            ?>
                                <tr class="user-row" data-role="<?= $role ?>" style="<?= $isInitialHidden ? 'display:none;' : '' ?>">
                                    <td><strong style="color:#593f34; font-weight:700;"><?= htmlspecialchars($u['code'] ?? format_user_id($u['id'], $role)) ?></strong></td>
                                    <td><strong><?= htmlspecialchars($name) ?></strong></td>
                                    <td><?= htmlspecialchars($u['email'] ?? ($u['personal_email'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars($u['phone_number'] ?? '—') ?></td>
                                    <td><span class="badge-role <?= $role ?>"><?= htmlspecialchars($role) ?></span></td>
                                    <td>
                                        <?php 
                                        $isSuspended = ($u['account_status'] ?? 'active') === 'suspended' || $vStatus === 'suspended';
                                        $suspReason = trim((string)($u['suspension_reason'] ?? ''));
                                        ?>
                                        <?php if ($isSuspended): ?>
                                            <span class="badge-status-suspended" title="<?= htmlspecialchars($suspReason ?: 'Suspended by OSAS') ?>">Suspended</span>
                                            <?php if ($suspReason !== ''): ?>
                                                <div style="font-size:0.75rem; color:#991b1b; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-top:2px;" title="<?= htmlspecialchars($suspReason) ?>"><?= htmlspecialchars($suspReason) ?></div>
                                            <?php endif; ?>
                                        <?php elseif ($role === 'landlord'): ?>
                                            <?php if ($vStatus === 'approved' || $vStatus === 'verified'): ?>
                                                <span class="status-approved">Approved</span>
                                            <?php elseif ($vStatus === 'pending'): ?>
                                                <a href="verification.php?view=applications" class="btn-inspect" title="Review landlord application">Review</a>
                                            <?php elseif ($vStatus === 'rejected' || $vStatus === 'declined'): ?>
                                                <span style="color:#991b1b; font-weight:600; font-size:0.85rem;">Declined</span>
                                            <?php else: ?>
                                                <span style="color:#78665c; font-size:0.85rem;">Unverified</span>
                                            <?php endif; ?>
                                        <?php elseif ($role === 'tenant'): ?>
                                            <?php if ($vStatus === 'verified'): ?>
                                                <span class="status-approved">Approved</span>
                                            <?php elseif ($vStatus === 'pending'): ?>
                                                <a href="verification.php?role=tenant" class="btn-inspect" title="Review student verification ID">Review</a>
                                            <?php elseif ($vStatus === 'declined' || $vStatus === 'rejected'): ?>
                                                <span style="color:#991b1b; font-weight:600; font-size:0.85rem;">Declined</span>
                                            <?php else: ?>
                                                <span style="color:#78665c; font-size:0.85rem;">Unverified</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:#6b21a8; font-weight:600; font-size:0.85rem;">Active Staff</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="date-col"><?= !empty($u['created_at']) ? htmlspecialchars(date('M d, Y', strtotime($u['created_at']))) : '—' ?></td>
                                    <td style="text-align:right;">
                                        <?php if ((int)$u['id'] !== (int)$adminProfile['id'] && $role !== 'admin'): ?>
                                            <?php if ($isSuspended): ?>
                                                <form method="POST" style="margin:0; display:inline;" onsubmit="return confirm('Reactivate and unsuspend account for <?= htmlspecialchars(addslashes($name)) ?>? This will restore their active privileges.');">
                                                    <input type="hidden" name="action" value="unsuspend_user">
                                                    <input type="hidden" name="target_user_id" value="<?= htmlspecialchars((string)$u['id']) ?>">
                                                    <input type="hidden" name="target_role" value="<?= htmlspecialchars($u['role']) ?>">
                                                    <button type="submit" class="btn-unsuspend-user" title="Reactivate this suspended account">Unsuspend</button>
                                                </form>
                                            <?php else: ?>
                                                <button type="button" class="btn-suspend-user" onclick="openSuspendModal('<?= htmlspecialchars(addslashes((string)$u['id'])) ?>', '<?= htmlspecialchars(addslashes($name)) ?>', '<?= htmlspecialchars($role) ?>', '<?= htmlspecialchars($u['code'] ?? format_user_id($u['id'], $role)) ?>')" title="Temporarily suspend this account">Suspend</button>
                                            <?php endif; ?>
                                        <?php elseif ((string)$u['id'] === (string)$adminProfile['id']): ?>
                                            <span style="color:#a89990; font-size:0.8rem; font-style:italic;">Current Admin</span>
                                        <?php else: ?>
                                            <span style="color:#a89990; font-size:0.8rem; font-style:italic;">Administrator</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- Suspend Modal Dialog -->
    <dialog id="suspend-modal" class="suspend-modal-dialog">
        <div class="cl-dialog-head">
            <h2>Suspend User Account</h2>
            <button type="button" class="cl-dialog-close" id="suspend-dialog-close" aria-label="Close">&times;</button>
        </div>
        <p class="cl-dialog-desc">Temporarily freeze login and platform access for this user. You can unsuspend them at any time.</p>
        <form method="POST" class="cl-form">
            <input type="hidden" name="action" value="suspend_user">
            <input type="hidden" name="target_user_id" id="suspend-target-id" value="0">
            <input type="hidden" name="target_role" id="suspend-target-role" value="">
            <div class="cl-field">
                <label>Target Account</label>
                <div id="suspend-target-name" style="font-weight:700; color:#3b2820; padding:8px 12px; background:#fbf5ef; border-radius:8px; font-size:0.9rem;"></div>
            </div>
            <div class="cl-field">
                <label for="suspension_reason">Reason for Suspension (Visible to User)</label>
                <textarea name="suspension_reason" id="suspension_reason" rows="3" required placeholder="e.g., Pending investigation of policy violations or falsified credentials." style="padding:10px 12px; border:1px solid #ebd9cb; border-radius:8px; font-family:inherit; font-size:0.88rem; resize:vertical;"></textarea>
            </div>
            <div class="cl-actions">
                <button type="button" class="btn-cl-cancel" id="suspend-cancel-btn">Cancel</button>
                <button type="submit" class="btn-cl-submit">Confirm Suspension</button>
            </div>
        </form>
    </dialog>

    <script>
    (() => {
        // Sliding Brown Indicator matching Tenant Dorms interaction
        const catBar = document.querySelector('.topbar-categories-bar');
        const catIndicator = catBar ? catBar.querySelector('.topbar-cat-indicator') : null;
        const catTabs = catBar ? Array.from(catBar.querySelectorAll('.topbar-cat-tab')) : [];
        let catIndicatorReady = false;

        function placeCatIndicator(targetTab, instant) {
            if (!catIndicator || !catBar) return;
            const tab = targetTab || catBar.querySelector('.topbar-cat-tab.is-active') || catTabs[0];
            if (!tab) return;
            const barRect = catBar.getBoundingClientRect();
            const tabRect = tab.getBoundingClientRect();
            const left = tabRect.left - barRect.left;
            const width = tabRect.width;

            if (instant || !catIndicatorReady) {
                catIndicator.style.transition = 'none';
                catIndicator.style.width = width + 'px';
                catIndicator.style.transform = 'translateX(' + left + 'px)';
                catIndicator.offsetHeight; // force reflow
                catIndicator.style.transition = '';
                catIndicatorReady = true;
            } else {
                catIndicator.style.width = width + 'px';
                catIndicator.style.transform = 'translateX(' + left + 'px)';
            }
        }

        function filterUsersByRole(role) {
            const rows = document.querySelectorAll('#users-table-body tr.user-row');
            let visibleCount = 0;
            rows.forEach(function(row) {
                const rowRole = row.getAttribute('data-role');
                const matches = (role === 'all' || rowRole === role);
                row.style.display = matches ? '' : 'none';
                if (matches) visibleCount++;
            });

            const emptyRow = document.getElementById('users-empty-row');
            if (emptyRow) {
                emptyRow.style.display = (visibleCount === 0) ? '' : 'none';
            }
        }

        if (catBar && catTabs.length) {
            window.addEventListener('load', function() {
                placeCatIndicator(null, true);
            });
            setTimeout(function() {
                placeCatIndicator(null, true);
            }, 60);
            window.addEventListener('resize', function() {
                placeCatIndicator(null, true);
            });

            catTabs.forEach(function(tab) {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    catTabs.forEach(function(b) {
                        b.classList.remove('is-active');
                        b.setAttribute('aria-selected', 'false');
                    });
                    tab.classList.add('is-active');
                    tab.setAttribute('aria-selected', 'true');
                    placeCatIndicator(tab, false);

                    const role = tab.getAttribute('data-role') || 'all';
                    filterUsersByRole(role);

                    const hiddenRoleInput = document.getElementById('hiddenRoleFilter');
                    if (hiddenRoleInput) {
                        hiddenRoleInput.value = role;
                    }

                    // Update URL without page reload
                    const url = new URL(window.location);
                    if (role === 'all') {
                        url.searchParams.delete('role');
                    } else {
                        url.searchParams.set('role', role);
                    }
                    window.history.pushState({}, '', url);
                });
            });
        }

        // Suspend Modal Controls
        const suspendModal = document.getElementById('suspend-modal');
        const suspendCloseBtn = document.getElementById('suspend-dialog-close');
        const suspendCancelBtn = document.getElementById('suspend-cancel-btn');
        const suspendTargetId = document.getElementById('suspend-target-id');
        const suspendTargetRole = document.getElementById('suspend-target-role');
        const suspendTargetName = document.getElementById('suspend-target-name');
        const suspendReason = document.getElementById('suspension_reason');

        window.openSuspendModal = function(id, name, role, code) {
            if (!suspendModal) return;
            suspendTargetId.value = id;
            suspendTargetRole.value = role;
            const displayCode = code || (role.charAt(0).toUpperCase() + role.slice(1) + ' #' + id);
            suspendTargetName.textContent = name + ' (' + displayCode + ')';
            if (suspendReason) suspendReason.value = '';
            suspendModal.showModal();
        };

        if (suspendCloseBtn && suspendModal) suspendCloseBtn.addEventListener('click', () => suspendModal.close());
        if (suspendCancelBtn && suspendModal) suspendCancelBtn.addEventListener('click', () => suspendModal.close());
        if (suspendModal) {
            suspendModal.addEventListener('click', (e) => {
                const rect = suspendModal.getBoundingClientRect();
                if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) {
                    suspendModal.close();
                }
            });
        }
    })();
    </script>
</body>
</html>
