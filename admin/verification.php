<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/notifications.php';

start_app_session();
$pdo = database();
$adminProfile = require_signed_in('admin');
$successMessage = '';
$errorMessage = '';
$invitationUrl = '';
$rawView = $_GET['view'] ?? $_POST['view'] ?? '';
if ($rawView === 'applications') {
    $view = 'applications';
} elseif ($rawView === 'properties') {
    $view = 'properties';
} else {
    $view = 'accounts';
}
$applicationView = ($view === 'applications');
$propertyView = ($view === 'properties');
if (empty($_SESSION['admin_verification_csrf'])) {
    $_SESSION['admin_verification_csrf'] = bin2hex(random_bytes(32));
}

// Active Role & Filter
$role = $_GET['role'] ?? 'landlord';
if (!in_array($role, ['landlord', 'tenant'], true)) {
    $role = 'landlord';
}

$filter = $_GET['filter'] ?? 'pending';
if ($applicationView) {
    $validFilters = ['pending', 'approved', 'declined', 'all'];
} elseif ($propertyView) {
    $validFilters = ['pending', 'approved', 'rejected', 'all'];
} else {
    $validFilters = ['pending', 'verified', 'rejected', 'all'];
}
if (!in_array($filter, $validFilters, true)) {
    $filter = 'pending';
}

// Handle Admin Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $propertyView) {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    if ($postedCsrf === '' || !hash_equals((string)$_SESSION['admin_verification_csrf'], $postedCsrf)) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } else {
        $propertyId = (int)($_POST['property_id'] ?? 0);
        $propAction = (string)$_POST['action'];
        $notes = trim((string)($_POST['rejection_notes'] ?? ''));

        $pStmt = $pdo->prepare('SELECT p.*, u.first_name, u.last_name, u.personal_email FROM properties p JOIN users u ON u.id = p.landlord_id WHERE p.id = ? LIMIT 1');
        $pStmt->execute([$propertyId]);
        $prop = $pStmt->fetch();

        if (!$prop) {
            $errorMessage = 'Property listing not found.';
        } elseif ($propAction === 'approve_property') {
            $update = $pdo->prepare("UPDATE properties SET approval_status = 'approved' WHERE id = ?");
            if ($update->execute([$propertyId])) {
                $successMessage = "Listing \"{$prop['name']}\" (ID #{$propertyId}) has been approved and is now active for student discovery!";
                add_in_app_notification(
                    $pdo,
                    (int)$prop['landlord_id'],
                    'approval',
                    'Property Listing Approved! 🏠',
                    "Great news! Your listing \"{$prop['name']}\" has been reviewed and approved by UniStay administration. It is now published and visible to students."
                );
            } else {
                $errorMessage = "Failed to approve property listing.";
            }
        } elseif ($propAction === 'decline_property') {
            $update = $pdo->prepare("UPDATE properties SET approval_status = 'rejected' WHERE id = ?");
            if ($update->execute([$propertyId])) {
                $successMessage = "Listing \"{$prop['name']}\" (ID #{$propertyId}) has been declined.";
                $reasonMsg = $notes !== '' ? " Reason: {$notes}" : " Please ensure your proof of ownership and room photos meet the requirements before resubmitting.";
                add_in_app_notification(
                    $pdo,
                    (int)$prop['landlord_id'],
                    'system',
                    'Property Listing Update',
                    "Your listing \"{$prop['name']}\" could not be approved at this time.{$reasonMsg}"
                );
            } else {
                $errorMessage = "Failed to decline property listing.";
            }
        } elseif ($propAction === 'reset_property') {
            $update = $pdo->prepare("UPDATE properties SET approval_status = 'pending' WHERE id = ?");
            if ($update->execute([$propertyId])) {
                $successMessage = "Listing \"{$prop['name']}\" has been reset to pending review.";
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $applicationView) {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    if ($postedCsrf === '' || !hash_equals((string)$_SESSION['admin_verification_csrf'], $postedCsrf)) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } else {
        $applicationId = (int)($_POST['application_id'] ?? 0);
        $applicationAction = (string)$_POST['action'];
        $decisionNote = trim((string)($_POST['decision_note'] ?? ''));
        $applicationStatement = $pdo->prepare('SELECT * FROM landlord_applications WHERE id = ? LIMIT 1');
        $applicationStatement->execute([$applicationId]);
        $application = $applicationStatement->fetch();

        if (!$application) {
            $errorMessage = 'The landlord application could not be found.';
        } elseif (!in_array($applicationAction, ['approve_application', 'decline_application', 'regenerate_invite', 'retry_application_email'], true)) {
            $errorMessage = 'Unknown application action.';
        } elseif ($applicationAction === 'retry_application_email') {
            if (send_landlord_application_admin_email($pdo, $application)) {
                $successMessage = 'The admin notification email was sent to ' . mail_settings()['admin_recipient'] . '.';
            } else {
                $errorMessage = mail_is_configured()
                    ? 'The admin notification email still could not be delivered. Check the SMTP settings and PHP error log.'
                    : 'Gmail SMTP is not configured yet. Open Mail Settings and save a sending address and Google app password.';
            }
        } elseif (in_array($applicationAction, ['approve_application', 'decline_application'], true)) {
            $result = decide_landlord_application($pdo, $applicationId, $applicationAction, $decisionNote);
            if ($result['ok']) {
                $successMessage = (string)$result['message'];
                $invitationUrl = (string)($result['invitation_url'] ?? '');
            } else {
                $errorMessage = (string)$result['message'];
            }
        } else {
            if ($applicationAction === 'regenerate_invite' && ($application['status'] ?? '') !== 'approved') {
                $errorMessage = 'Only approved applications can receive a new invitation.';
            } else {
                $inviteToken = bin2hex(random_bytes(32));
                $invitationUrl = app_url('/auth/register.php?type=landlord&invite=' . rawurlencode($inviteToken));
                $update = $pdo->prepare("UPDATE landlord_applications SET status = 'approved', decision_note = ?, invitation_token_hash = ?, decided_at = COALESCE(decided_at, NOW()), admin_review_token_used_at = NOW() WHERE id = ? AND registered_at IS NULL");
                $update->execute([$decisionNote !== '' ? $decisionNote : ($application['decision_note'] ?? null), hash('sha256', $inviteToken), $applicationId]);
                if ($update->rowCount() !== 1) {
                    $errorMessage = 'This application has already been used to create an account.';
                    $invitationUrl = '';
                } else {
                    $name = trim(($application['first_name'] ?? '') . ' ' . ($application['last_name'] ?? ''));
                    $plain = "Hi {$name},\n\nYour UniStay landlord application was approved. Create your account using this one-time invitation:\n{$invitationUrl}";
                    $html = '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p><p>Your UniStay landlord application was approved.</p>'
                        . '<p><a href="' . htmlspecialchars($invitationUrl, ENT_QUOTES, 'UTF-8') . '" style="background:#7a4b3a;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">Create landlord account</a></p>';
                    $mailSent = send_mail((string)$application['email'], 'Your UniStay landlord application was approved', $plain, $html);
                    $successMessage = $mailSent
                        ? 'The application was approved and the invitation was emailed.'
                        : 'The application was approved. SMTP is unavailable, so copy the invitation below and send it to the applicant.';
                }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $targetId = (int)($_POST['target_user_id'] ?? 0);
    $targetRole = in_array($_POST['target_role'] ?? '', ['landlord', 'tenant'], true) ? $_POST['target_role'] : $role;
    $action = $_POST['action'];

    if ($targetId > 0) {
        if ($action === 'approve_user' || $action === 'approve_landlord' || $action === 'approve_tenant') {
            if (admin_set_verification_status($pdo, $targetId, 'verified', null, $targetRole)) {
                $roleLabel = ucfirst($targetRole);
                $successMessage = "{$roleLabel} #{$targetId} has been successfully verified! " . 
                    ($targetRole === 'landlord' ? 'Their listings are now active and can receive booking requests.' : 'They can now submit room booking requests.');
            } else {
                $errorMessage = "Failed to approve {$targetRole} verification.";
            }
        } elseif ($action === 'reject_user' || $action === 'reject_landlord' || $action === 'reject_tenant') {
            $notes = trim($_POST['rejection_notes'] ?? 'The submitted identification could not be verified. Please re-upload clear credentials.');
            if (admin_set_verification_status($pdo, $targetId, 'rejected', $notes, $targetRole)) {
                $roleLabel = ucfirst($targetRole);
                $successMessage = "{$roleLabel} #{$targetId} verification was rejected. Feedback has been sent to their portal.";
            } else {
                $errorMessage = "Failed to reject {$targetRole} verification.";
            }
        } elseif ($action === 'reset_user' || $action === 'reset_landlord' || $action === 'reset_tenant') {
            if (admin_set_verification_status($pdo, $targetId, 'pending', null, $targetRole)) {
                $roleLabel = ucfirst($targetRole);
                $successMessage = "{$roleLabel} #{$targetId} verification was reset to pending review.";
            }
        }
    }
}

// Counters
$landlordCounts = [
    'pending' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'landlord' AND (verification_status = 'pending' OR (verification_status = 'unverified' AND gov_id_file IS NOT NULL AND gov_id_file != ''))")->fetchColumn(),
    'verified' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'landlord' AND verification_status = 'verified'")->fetchColumn(),
    'rejected' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'landlord' AND verification_status = 'rejected'")->fetchColumn(),
    'all' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'landlord'")->fetchColumn(),
];

$tenantCounts = [
    'pending' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'tenant' AND (verification_status = 'pending' OR (verification_status = 'unverified' AND ((school_id_file IS NOT NULL AND school_id_file != '') OR (gov_id_file IS NOT NULL AND gov_id_file != ''))))")->fetchColumn(),
    'verified' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'tenant' AND verification_status = 'verified'")->fetchColumn(),
    'rejected' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'tenant' AND verification_status = 'rejected'")->fetchColumn(),
    'all' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'tenant'")->fetchColumn(),
];

$activeCounts = ($role === 'tenant') ? $tenantCounts : $landlordCounts;

$applicationCounts = [];
foreach (['pending', 'approved', 'declined'] as $applicationStatus) {
    $countStatement = $pdo->prepare('SELECT COUNT(*) FROM landlord_applications WHERE status = ?');
    $countStatement->execute([$applicationStatus]);
    $applicationCounts[$applicationStatus] = (int)$countStatement->fetchColumn();
}
$applicationCounts['all'] = array_sum($applicationCounts);
$applicationFilter = in_array($filter, ['pending', 'approved', 'declined', 'all'], true) ? $filter : 'pending';
$applications = [];
if ($applicationView) {
    $sql = 'SELECT * FROM landlord_applications';
    $params = [];
    if ($applicationFilter !== 'all') {
        $sql .= ' WHERE status = ?';
        $params[] = $applicationFilter;
    }
    $sql .= ' ORDER BY CASE status WHEN \'pending\' THEN 0 WHEN \'approved\' THEN 1 ELSE 2 END, id DESC';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $applications = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$propertyCounts = [
    'pending' => (int)$pdo->query("SELECT COUNT(*) FROM properties WHERE approval_status = 'pending'")->fetchColumn(),
    'approved' => (int)$pdo->query("SELECT COUNT(*) FROM properties WHERE approval_status = 'approved'")->fetchColumn(),
    'rejected' => (int)$pdo->query("SELECT COUNT(*) FROM properties WHERE approval_status = 'rejected'")->fetchColumn(),
    'all' => (int)$pdo->query("SELECT COUNT(*) FROM properties")->fetchColumn(),
];
$propertyFilter = in_array($filter, ['pending', 'approved', 'rejected', 'all'], true) ? $filter : 'pending';
$properties = [];
if ($propertyView) {
    $propSql = "
        SELECT p.*, 
               u.first_name, u.last_name, u.personal_email, u.phone_number,
               (SELECT COUNT(*) FROM rooms r WHERE r.property_id = p.id) AS room_count,
               (SELECT COALESCE(SUM(r.capacity), 0) FROM rooms r WHERE r.property_id = p.id) AS total_slots
        FROM properties p
        JOIN users u ON u.id = p.landlord_id
    ";
    $propParams = [];
    if ($propertyFilter !== 'all') {
        $propSql .= " WHERE p.approval_status = ?";
        $propParams[] = $propertyFilter;
    }
    $propSql .= " ORDER BY CASE p.approval_status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END, p.id DESC";
    $propStmt = $pdo->prepare($propSql);
    $propStmt->execute($propParams);
    $properties = $propStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Fetch matching records
$requests = admin_get_verification_requests($pdo, $role, $filter);

require_once __DIR__ . '/../config/verification_documents.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification Center · UniStay Admin</title>
    <link rel="icon" type="image/png" href="../assets/images/tenant/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/common.css?v=32">
    <script>
        function showDocumentUnavailable(image) {
            const box = image.closest('.verif-doc-box');
            const thumb = image.parentElement;
            thumb.removeAttribute('onclick');
            thumb.textContent = 'Photo unavailable';
            if (box) {
                box.classList.add('is-missing');
                const label = box.querySelector('.doc-details span');
                if (label) label.textContent = 'Photo unavailable';
                const inspect = box.querySelector('.btn-inspect');
                if (inspect) inspect.hidden = true;
            }
        }
    </script>
    <script src="../assets/js/tenant/sidebar.js"></script>
    <script src="../assets/js/tenant/profile-menu.js"></script>
    <script>window.UNISTAY_NOTIFICATION_ACCOUNT=<?= json_encode(['id'=>(int)$adminProfile['id'],'role'=>'admin']) ?>;</script>
    <script defer src="../assets/js/notification-preferences.js?v=3"></script>
    <script defer src="../assets/js/notification-global.js?v=1"></script>
    <style>
        #inspectImg[hidden], #inspectUnavailable[hidden], .btn-inspect[hidden] { display: none; }
        body {
            background-color: #f7f3ef;
            color: #292524;
            font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            margin: 0;
            display: flex;
            min-height: 100vh;
        }

        .admin-shell {
            flex: 1;
            padding: 30px 48px;
            overflow-y: auto;
            max-width: 1440px;
            margin: 0 auto;
            width: 100%;
        }

        .admin-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 1px solid #ebd9cb;
        }

        .admin-title-wrap h1 {
            font-family: 'Fraunces', serif;
            font-size: 2.1rem;
            margin: 0;
            color: #3b2820;
            letter-spacing: -0.5px;
        }

        .admin-title-wrap p {
            margin: 4px 0 0;
            color: #78665c;
            font-size: 0.95rem;
        }

        .admin-user-badge {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #ffffff;
            border: 1px solid #ebd9cb;
            padding: 8px 16px;
            border-radius: 999px;
            box-shadow: 0 2px 8px rgba(60, 30, 20, 0.04);
        }

        .admin-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #7a4b3a;
            color: #fff;
            display: grid;
            place-items: center;
            font-weight: 700;
            font-size: 13px;
        }

        /* Role Switcher Tabs */
        .role-switcher {
            display: flex;
            gap: 14px;
            margin-bottom: 22px;
        }

        .role-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 24px;
            background: #ffffff;
            border: 2px solid #ebd9cb;
            border-radius: 14px;
            font-size: 1rem;
            font-weight: 700;
            color: #78665c;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .role-tab-btn:hover {
            border-color: #7a4b3a;
            color: #7a4b3a;
            transform: translateY(-1px);
        }

        .role-tab-btn.is-active {
            background: #7a4b3a;
            border-color: #7a4b3a;
            color: #ffffff;
            box-shadow: 0 6px 18px rgba(122, 75, 58, 0.25);
        }

        .role-tab-badge {
            background: #dc2626;
            color: #ffffff;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 2px 9px;
            border-radius: 999px;
            min-width: 20px;
            text-align: center;
        }

        .role-tab-btn.is-active .role-tab-badge {
            background: #ffffff;
            color: #7a4b3a;
        }

        /* Filter Tabs */
        .filter-nav {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 24px;
            overflow-x: auto;
            padding-bottom: 4px;
        }

        .filter-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            background: #ffffff;
            border: 1px solid #ebd9cb;
            border-radius: 999px;
            color: #78665c;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .filter-pill:hover {
            border-color: #7a4b3a;
            color: #7a4b3a;
        }

        .filter-pill.is-active {
            background: #3b2820;
            color: #ffffff;
            border-color: #3b2820;
            box-shadow: 0 4px 12px rgba(59, 40, 32, 0.25);
        }

        .pill-badge {
            background: rgba(0,0,0,0.08);
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.8rem;
        }

        .filter-pill.is-active .pill-badge {
            background: rgba(255,255,255,0.25);
            color: #ffffff;
        }

        /* Alerts */
        .admin-alert {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 22px;
            font-size: 0.92rem;
            font-weight: 500;
        }
        .admin-alert.is-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .admin-alert.is-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* Verification Cards Grid */
        .verif-grid {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .verif-card {
            background: #ffffff;
            border: 1px solid #ebd9cb;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 4px 16px rgba(60, 30, 20, 0.04);
            display: grid;
            grid-template-columns: 280px 1fr 200px;
            gap: 22px;
            align-items: center;
            transition: box-shadow 0.2s ease;
        }

        .verif-card:hover {
            box-shadow: 0 8px 24px rgba(60, 30, 20, 0.08);
        }

        @media (max-width: 1180px) {
            .verif-card {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .verif-action-col {
                align-items: flex-start !important;
            }
            .verif-buttons {
                justify-content: flex-start !important;
            }
        }

        /* User Profile Info Column */
        .user-meta {
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }

        .user-avatar {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            background: #fcf4ee;
            color: #7a4b3a;
            display: grid;
            place-items: center;
            font-size: 1.15rem;
            font-weight: 700;
            flex-shrink: 0;
            overflow: hidden;
            border: 1px solid #ebd9cb;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-info h3 {
            margin: 0;
            font-size: 1.1rem;
            color: #292524;
            font-weight: 700;
        }

        .user-contact {
            margin-top: 6px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 0.86rem;
            color: #78665c;
        }

        .role-tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }
        .role-tag.is-landlord {
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }
        .role-tag.is-tenant {
            background: #f5f3ff;
            color: #5b21b6;
            border: 1px solid #ddd6fe;
        }

        .detail-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #fbf8f5;
            border: 1px solid #ebd9cb;
            padding: 2px 7px;
            border-radius: 6px;
            font-size: 0.78rem;
            color: #634f44;
            margin-top: 2px;
            word-break: break-all;
        }

        /* Documents Column */
        .verif-docs-pair {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }

        .verif-doc-box {
            background: #fbf8f5;
            border: 1px solid #ebd9cb;
            border-radius: 14px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .verif-doc-box.is-missing {
            background: #fafaf9;
            border: 1px dashed #d6d3d1;
            opacity: 0.85;
        }

        .doc-thumbnail-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .doc-thumb {
            width: 52px;
            height: 52px;
            border-radius: 10px;
            background: #ebd9cb;
            display: grid;
            place-items: center;
            font-size: 22px;
            overflow: hidden;
            cursor: pointer;
            flex-shrink: 0;
            border: 1px solid #dfc6b4;
            transition: transform 0.15s ease;
        }

        .doc-thumb:hover {
            transform: scale(1.05);
        }

        .doc-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .doc-details {
            min-width: 0;
        }

        .doc-details strong {
            display: block;
            font-size: 0.88rem;
            color: #292524;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 140px;
        }

        .doc-details span {
            font-size: 0.78rem;
            color: #8c7569;
            display: block;
        }

        .btn-inspect {
            background: #ffffff;
            border: 1px solid #ebd9cb;
            color: #7a4b3a;
            padding: 7px 12px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .btn-inspect:hover {
            background: #7a4b3a;
            color: #fff;
            border-color: #7a4b3a;
        }

        /* Status & Action Column */
        .verif-action-col {
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: flex-end;
        }

        .verif-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.82rem;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .verif-status-badge.is-pending {
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
        }

        .verif-status-badge.is-verified {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .verif-status-badge.is-rejected {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .verif-status-badge.is-unverified {
            background: #f3f4f6;
            color: #4b5563;
            border: 1px solid #e5e7eb;
        }

        .verif-buttons {
            display: flex;
            gap: 8px;
            width: 100%;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .btn-approve {
            background: #15803d;
            color: #ffffff;
            border: none;
            padding: 8px 14px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.88rem;
            cursor: pointer;
            transition: all 0.18s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-approve:hover {
            background: #166534;
            transform: translateY(-1px);
        }

        .btn-reject {
            background: #ffffff;
            color: #dc2626;
            border: 1px solid #fca5a5;
            padding: 8px 14px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.88rem;
            cursor: pointer;
            transition: all 0.18s;
        }

        .btn-reject:hover {
            background: #fef2f2;
            border-color: #ef4444;
        }

        .btn-approve:disabled, .btn-reject:disabled { opacity: .7; cursor: wait; transform: none; }

        /* Empty State */
        .verif-empty {
            background: #ffffff;
            border: 1px dashed #ebd9cb;
            border-radius: 18px;
            padding: 48px 24px;
            text-align: center;
            color: #8c7569;
        }

        .verif-empty-icon {
            font-size: 3rem;
            margin-bottom: 12px;
            display: block;
        }

        /* Inspect Modal */
        .inspect-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 99999;
            background: rgba(0,0,0,0.65);
            backdrop-filter: blur(5px);
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .inspect-modal.is-active {
            display: flex;
        }

        .inspect-box {
            background: #ffffff;
            border-radius: 20px;
            max-width: 860px;
            width: 100%;
            max-height: 92vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(0,0,0,0.3);
            animation: zoomModal 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .inspect-header {
            padding: 18px 24px;
            border-bottom: 1px solid #ebd9cb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .inspect-body {
            padding: 24px;
            overflow-y: auto;
            text-align: center;
            background: #f7f3ef;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 360px;
        }

        .inspect-img {
            max-width: 100%;
            max-height: 60vh;
            object-fit: contain;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
        }

        .inspect-footer {
            padding: 16px 24px;
            border-top: 1px solid #ebd9cb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ffffff;
        }

        @keyframes zoomModal {
            from { transform: scale(0.96); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .policy-modal { position: fixed; inset: 0; z-index: 100000; display: grid; place-items: center; padding: 20px; }
        .policy-modal-backdrop { position: fixed; inset: 0; background: rgba(30, 15, 10, 0.55); backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); transition: opacity 0.22s ease; }
        .policy-modal-panel { position: relative; width: min(100%, 640px); max-height: 84vh; display: flex; flex-direction: column; background: #FDFAF5; border: 1px solid #E8DDD3; border-radius: 20px; box-shadow: 0 20px 50px rgba(45, 25, 18, 0.28); overflow: hidden; animation: policy-pop 0.26s cubic-bezier(0.16, 1, 0.3, 1) both; }
        @keyframes policy-pop { from { opacity: 0; transform: scale(0.95) translateY(14px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        .policy-modal.is-closing { pointer-events: none; }
        .policy-modal.is-closing .policy-modal-backdrop { opacity: 0; }
        .policy-modal.is-closing .policy-modal-panel { opacity: 0; transform: scale(0.96) translateY(10px); transition: opacity 0.22s ease, transform 0.22s ease; }
        .policy-modal-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 26px 16px; border-bottom: 1px solid #E8DDD3; background: #FDFAF5; }
        .policy-modal-title-group { display: flex; flex-direction: column; gap: 2px; }
        .policy-modal-crumb { font-size: 12px; font-weight: 600; color: #A58D84; letter-spacing: 0.4px; text-transform: uppercase; margin: 0; }
        .policy-modal-header h2 { margin: 0; font-family: 'Fraunces', Georgia, serif; font-weight: 700; font-size: 24px; line-height: 1.2; color: #6B3624; }
        .policy-modal-close { display: grid; place-items: center; width: 36px; height: 36px; border: 1px solid #E8DDD3; background: #F7F1E7; color: #7B4A3A; font-size: 24px; line-height: 1; cursor: pointer; border-radius: 50%; transition: all 0.18s ease; }
        .policy-modal-close:hover { background: #7B4A3A; color: #fff; border-color: #7B4A3A; }
        .policy-modal-body { padding: 24px 26px; overflow-y: auto; font-family: 'DM Sans', sans-serif; color: #8B5A4A; scrollbar-color: #7B4A3A #F3E9DF; scrollbar-width: thin; }
        .policy-modal-body .policy-card { background: #F7F1E7; border: 1px solid #E8DDD3; border-radius: 18px; padding: 20px 24px; }
        .policy-modal-body .policy-card h2 { font-family: 'Fraunces', Georgia, serif; font-weight: 700; font-size: 19px; color: #6B3624; margin: 0 0 8px; }
        .policy-modal-body .policy-card p { font-size: 14.5px; line-height: 24px; color: #8B5A4A; margin: 0; }
        .policy-modal-footer { display: flex; align-items: center; justify-content: space-between; padding: 16px 26px; border-top: 1px solid #E8DDD3; background: #FDFAF5; }
        .policy-modal-footer-note { font-size: 12.5px; color: #A58D84; font-weight: 500; }
        .btn-policy-confirm { display: inline-flex; align-items: center; justify-content: center; height: 40px; padding: 0 24px; border: 1px solid #E8DDD3; border-radius: 999px; background: #7B4A3A; color: #fff; font: 500 13.5px 'DM Sans', sans-serif; cursor: pointer; transition: all 0.2s ease; }
        .btn-policy-confirm:hover { background: #5C3527; border-color: #5C3527; }

        .policy-modal[hidden] { display: none !important; }
        .btn-policy-cancel { display: inline-flex; align-items: center; justify-content: center; height: 40px; padding: 0 22px; border: 1px solid #E8DDD3; border-radius: 999px; background: #fff; color: #7B4A3A; font: 500 13.5px 'DM Sans', sans-serif; cursor: pointer; transition: all 0.2s ease; }
        .btn-policy-cancel:hover { background: #F7F1E7; border-color: #7B4A3A; }
    </style>
</head>
<body>
    <svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" style="display:none;"><defs>
        <symbol id="grid-icon" viewBox="0 0 24 24"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/></symbol>
        <symbol id="badge-icon" viewBox="0 0 24 24"><path d="m12 2 3 3 4-1 1 4 3 3-3 3-1 4-4 1-3 3-3-3-4-1-1-4-3-3 3-3 1-4 4 1Z"/><path d="m8 12 3 3 5-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="user-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></symbol>
        <symbol id="settings-icon" viewBox="0 0 24 24"><path d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/></symbol>
        <symbol id="chevron-icon" viewBox="0 0 24 24"><path d="m15 6-6 6 6 6"/></symbol>
        <symbol id="logout-icon" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    </defs></svg>

    <!-- Admin Sidebar Navigation (4-Item Navigation) -->
    <aside class="sidebar" aria-label="UniStay Admin Portal">
        <div class="brand">
            <img src="../assets/images/tenant/logo.png" width="50" height="50" alt="UniStay logo">
            <span class="brand-name">UniStay</span>
        </div>
        <nav aria-label="Admin navigation">
            <a href="dashboard.php" title="Dashboard"><svg aria-hidden="true"><use href="#grid-icon"/></svg><span class="nav-label">Dashboard</span></a>
            <a href="verification.php" title="Verification" aria-current="page"><svg aria-hidden="true"><use href="#badge-icon"/></svg><span class="nav-label">Verification</span></a>
            <a href="users.php" title="Users"><svg aria-hidden="true"><use href="#user-icon"/></svg><span class="nav-label">Users</span></a>
            <a href="settings.php" title="Settings"><svg aria-hidden="true"><use href="#settings-icon"/></svg><span class="nav-label">Settings</span></a>
        </nav>
        <button id="sidebar-toggle" class="sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true">
            <span class="toggle-circle"><svg aria-hidden="true"><use href="#chevron-icon"/></svg></span>
            <span class="toggle-label">Collapse</span>
        </button>
    </aside>

    <div class="admin-shell">
        <!-- Topbar -->
        <header class="admin-topbar">
            <div class="admin-title-wrap">
                <h1>Verification Center</h1>
                <p>Review landlord government IDs and student tenant identification documents.</p>
            </div>
            <div style="display:flex; align-items:center; gap:12px;">
                <div class="admin-user-badge">
                    <div class="admin-avatar">A</div>
                    <div>
                        <strong style="font-size:0.9rem; display:block;"><?= htmlspecialchars($adminProfile['name']) ?></strong>
                        <span style="font-size:0.75rem; color:#8c7569;">System Administrator</span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Role Switcher Tabs -->
        <div class="role-switcher">
            <a href="?role=landlord&filter=pending" class="role-tab-btn <?= !$applicationView && !$propertyView && $role === 'landlord' ? 'is-active' : '' ?>">
                🏠 Dorm Landlords
                <?php if ($landlordCounts['pending'] > 0): ?>
                    <span class="role-tab-badge"><?= $landlordCounts['pending'] ?></span>
                <?php endif; ?>
            </a>
            <a href="?role=tenant&filter=pending" class="role-tab-btn <?= !$applicationView && !$propertyView && $role === 'tenant' ? 'is-active' : '' ?>">
                🎓 Student Tenants
                <?php if ($tenantCounts['pending'] > 0): ?>
                    <span class="role-tab-badge"><?= $tenantCounts['pending'] ?></span>
                <?php endif; ?>
            </a>
            <a href="?view=applications&filter=pending" class="role-tab-btn <?= $applicationView ? 'is-active' : '' ?>">
                Landlord Applications
                <?php if ($applicationCounts['pending'] > 0): ?>
                    <span class="role-tab-badge"><?= $applicationCounts['pending'] ?></span>
                <?php endif; ?>
            </a>
            <a href="?view=properties&filter=pending" class="role-tab-btn <?= $propertyView ? 'is-active' : '' ?>">
                🏢 Property Listings
                <?php if ($propertyCounts['pending'] > 0): ?>
                    <span class="role-tab-badge"><?= $propertyCounts['pending'] ?></span>
                <?php endif; ?>
            </a>
        </div>

        <!-- Filter Tabs -->
        <?php if ($propertyView): ?>
        <nav class="filter-nav" aria-label="Filter property listings">
            <?php foreach (['pending' => 'Pending Review', 'approved' => 'Approved Listings', 'rejected' => 'Declined', 'all' => 'All Properties'] as $statusKey => $statusLabel): ?>
                <a href="?view=properties&filter=<?= $statusKey ?>" class="filter-pill <?= $propertyFilter === $statusKey ? 'is-active' : '' ?>">
                    <?= $statusLabel ?> <span class="pill-badge"><?= $propertyCounts[$statusKey] ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php elseif ($applicationView): ?>
        <nav class="filter-nav" aria-label="Filter landlord applications">
            <?php foreach (['pending' => 'Pending Review', 'approved' => 'Approved', 'declined' => 'Declined', 'all' => 'All Applications'] as $statusKey => $statusLabel): ?>
                <a href="?view=applications&filter=<?= $statusKey ?>" class="filter-pill <?= $applicationFilter === $statusKey ? 'is-active' : '' ?>">
                    <?= $statusLabel ?> <span class="pill-badge"><?= $applicationCounts[$statusKey] ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php else: ?>
        <nav class="filter-nav" aria-label="Filter verification requests">
            <a href="?role=<?= $role ?>&filter=pending" class="filter-pill <?= $filter === 'pending' ? 'is-active' : '' ?>">
                Pending Review
                <span class="pill-badge"><?= $activeCounts['pending'] ?></span>
            </a>
            <a href="?role=<?= $role ?>&filter=verified" class="filter-pill <?= $filter === 'verified' ? 'is-active' : '' ?>">
                Verified <?= $role === 'landlord' ? 'Landlords' : 'Tenants' ?>
                <span class="pill-badge"><?= $activeCounts['verified'] ?></span>
            </a>
            <a href="?role=<?= $role ?>&filter=rejected" class="filter-pill <?= $filter === 'rejected' ? 'is-active' : '' ?>">
                Rejected
                <span class="pill-badge"><?= $activeCounts['rejected'] ?></span>
            </a>
            <a href="?role=<?= $role ?>&filter=all" class="filter-pill <?= $filter === 'all' ? 'is-active' : '' ?>">
                All <?= $role === 'landlord' ? 'Landlords' : 'Tenants' ?>
                <span class="pill-badge"><?= $activeCounts['all'] ?></span>
            </a>
        </nav>
        <?php endif; ?>

        <!-- Content List -->
        <?php if ($propertyView): ?>
            <?php if (empty($properties)): ?>
                <div class="verif-empty">
                    <span class="verif-empty-icon">🏢</span>
                    <h3 style="margin:0 0 6px;color:#292524;">No property listings found</h3>
                    <p style="margin:0;font-size:0.92rem;">There are no properties matching the "<?= htmlspecialchars($propertyFilter) ?>" filter.</p>
                </div>
            <?php else: ?>
                <div class="verif-grid">
                <?php foreach ($properties as $prop):
                    $propName = (string)$prop['name'];
                    $landlordFullName = trim(($prop['first_name'] ?? '') . ' ' . ($prop['last_name'] ?? ''));
                    if ($landlordFullName === '') $landlordFullName = (string)($prop['personal_email'] ?? 'Landlord');
                    $status = (string)$prop['approval_status'];
                    $proofPath = (string)($prop['proof_of_ownership'] ?? '');
                    $proofUrl = $proofPath !== '' ? '../' . ltrim($proofPath, '/') : '';
                    
                    // Photos
                    $photos = json_decode((string)($prop['photo'] ?? '[]'), true);
                    if (!is_array($photos)) $photos = [];
                    $coverPhoto = !empty($photos[0]) ? '../' . ltrim($photos[0], '/') : '../assets/images/tenant/hero-1.webp';
                ?>
                    <article class="verif-card" id="property-<?= (int)$prop['id'] ?>" style="grid-template-columns: minmax(280px, 1.15fr) minmax(280px, 1.25fr) minmax(220px, 0.75fr);">
                        <!-- Left: Property & Landlord Details -->
                        <div style="display:flex; gap:16px; min-width:0;">
                            <div style="width:72px; height:72px; border-radius:12px; overflow:hidden; flex-shrink:0; border:1px solid #ebd9cb; background:#dfc6b4;">
                                <img src="<?= htmlspecialchars($coverPhoto) ?>" alt="<?= htmlspecialchars($propName) ?>" style="width:100%; height:100%; object-fit:cover;">
                            </div>
                            <div class="user-info" style="min-width:0;">
                                <h3 style="margin:0 0 4px; font-size:1.05rem;"><?= htmlspecialchars($propName) ?></h3>
                                <span class="role-tag is-landlord" style="display:inline-block; margin-bottom:6px;">Property #<?= (int)$prop['id'] ?> &bull; <?= (int)$prop['room_count'] ?> Rooms (<?= (int)$prop['total_slots'] ?> Beds)</span>
                                <div class="user-contact" style="font-size:0.83rem; line-height:1.4;">
                                    <div style="color:#57534e;"><strong>Near:</strong> <?= htmlspecialchars($prop['university'] ?? 'University') ?></div>
                                    <div style="color:#78665c; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($prop['address'] ?? '') ?>">📍 <?= htmlspecialchars($prop['address'] ?? '') ?></div>
                                    <div style="margin-top:4px; padding-top:4px; border-top:1px dashed #ebd9cb;">
                                        <strong style="color:#292524;">Landlord:</strong> <?= htmlspecialchars($landlordFullName) ?>
                                        <span style="display:block; color:#78665c;"><?= htmlspecialchars($prop['personal_email'] ?? '') ?> &bull; <?= htmlspecialchars($prop['phone_number'] ?? 'No phone') ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Middle: Proof of Ownership & Description -->
                        <div style="display:grid; gap:12px; min-width:0;">
                            <div class="verif-doc-box">
                                <div class="doc-thumbnail-wrap">
                                    <div class="doc-thumb" <?= $proofUrl !== '' ? "onclick=\"openInspectModal('" . htmlspecialchars($proofUrl, ENT_QUOTES, 'UTF-8') . "', 'Proof of Ownership', '" . htmlspecialchars(addslashes($propName), ENT_QUOTES, 'UTF-8') . "', " . (int)$prop['id'] . ", 'landlord')\"" : '' ?>>
                                        <?php if ($proofUrl !== ''): ?>
                                            <img src="<?= htmlspecialchars($proofUrl) ?>" alt="Proof of Ownership">
                                        <?php else: ?>
                                            &#10060;
                                        <?php endif; ?>
                                    </div>
                                    <div class="doc-details">
                                        <strong>Proof of Ownership</strong>
                                        <span><?= $proofPath !== '' ? htmlspecialchars(basename($proofPath)) : 'Not uploaded' ?></span>
                                    </div>
                                </div>
                                <?php if ($proofUrl !== ''): ?>
                                    <button type="button" class="btn-inspect" onclick="openInspectModal('<?= htmlspecialchars($proofUrl, ENT_QUOTES, 'UTF-8') ?>', 'Proof of Ownership', '<?= htmlspecialchars(addslashes($propName), ENT_QUOTES, 'UTF-8') ?>', <?= (int)$prop['id'] ?>, 'landlord')">Inspect Document</button>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($prop['description'])): ?>
                                <div style="font-size:0.83rem; line-height:1.4; color:#5f4a40; background:#fbf8f5; border:1px solid #ebd9cb; border-radius:10px; padding:10px 12px; max-height:85px; overflow-y:auto;">
                                    <strong style="color:#292524; display:block; margin-bottom:2px;">Description:</strong>
                                    <?= nl2br(htmlspecialchars($prop['description'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Right: Status Badge & Actions -->
                        <div class="verif-action-col">
                            <span class="verif-status-badge is-<?= $status === 'approved' ? 'verified' : ($status === 'rejected' ? 'rejected' : 'pending') ?>">
                                <?= $status === 'approved' ? 'Active & Approved' : ($status === 'rejected' ? 'Listing Declined' : 'Pending Review') ?>
                            </span>
                            <span style="font-size:0.75rem; color:#8c7569; text-align:right;">
                                Submitted: <?= date('M d, Y', strtotime($prop['created_at'] ?? 'now')) ?>
                            </span>

                            <?php if ($status === 'pending'): ?>
                                <form method="post" style="display:grid; gap:8px; width:100%; margin-top:4px;">
                                    <input type="hidden" name="view" value="properties">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_verification_csrf']) ?>">
                                    <input type="hidden" name="property_id" value="<?= (int)$prop['id'] ?>">
                                    <textarea name="rejection_notes" rows="2" maxlength="300" placeholder="Optional notes for landlord if declining..." style="width:100%; padding:8px; border:1px solid #d8c3b4; border-radius:8px; font:inherit; font-size:0.83rem;"></textarea>
                                    <div class="verif-buttons">
                                        <button class="btn-approve" name="action" value="approve_property" type="submit">Approve</button>
                                        <button class="btn-reject" name="action" value="decline_property" type="submit">Decline</button>
                                    </div>
                                </form>
                            <?php elseif ($status === 'approved'): ?>
                                <div style="font-size:0.82rem; color:#166534; font-weight:600; text-align:right; margin-top:4px;">
                                    &#10003; Discoverable by students
                                </div>
                                <form method="post" style="margin-top:6px; text-align:right;">
                                    <input type="hidden" name="view" value="properties">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_verification_csrf']) ?>">
                                    <input type="hidden" name="property_id" value="<?= (int)$prop['id'] ?>">
                                    <button class="btn-reject" style="font-size:0.8rem; padding:6px 12px;" name="action" value="decline_property" type="submit">Revoke / Decline</button>
                                </form>
                            <?php else: ?>
                                <div style="font-size:0.82rem; color:#991b1b; font-weight:600; text-align:right; margin-top:4px;">
                                    &#10005; Hidden from students
                                </div>
                                <form method="post" style="margin-top:6px; text-align:right;">
                                    <input type="hidden" name="view" value="properties">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_verification_csrf']) ?>">
                                    <input type="hidden" name="property_id" value="<?= (int)$prop['id'] ?>">
                                    <button class="btn-inspect" style="font-size:0.8rem; padding:6px 12px;" name="action" value="reset_property" type="submit">Reset to Pending</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php elseif ($applicationView): ?>
            <?php if (!mail_is_configured()): ?>
                <div style="margin-bottom:18px;background:#fff7ed;color:#92400e;border:1px solid #fed7aa;border-radius:12px;padding:14px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                    <span><strong>Admin email is not configured.</strong> Applications still create an in-app alert, but Gmail delivery needs a sending address and app password.</span>
                    <a href="../tools/mail-settings.php" style="color:#7a4b3a;font-weight:700;">Open Mail Settings</a>
                </div>
            <?php endif; ?>
            <?php if ($invitationUrl !== ''): ?>
                <div class="notice-banner is-success" style="margin-bottom:18px;word-break:break-all;background:#e5f4e8;color:#245c31;border:1px solid #b9d8bf;border-radius:12px;padding:14px;">
                    <strong style="display:block;margin-bottom:8px;">One-time landlord invitation</strong>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="text" readonly value="<?= htmlspecialchars($invitationUrl, ENT_QUOTES, 'UTF-8') ?>" style="flex:1;min-width:0;padding:9px 11px;border:1px solid #b9d8bf;border-radius:8px;background:#fff;font:inherit;">
                        <button type="button" class="btn-inspect" onclick="const input=this.previousElementSibling;input.select();navigator.clipboard?navigator.clipboard.writeText(input.value):document.execCommand('copy');this.textContent='Copied';">Copy invitation</button>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (empty($applications)): ?>
                <div class="verif-empty">
                    <span class="verif-empty-icon">&#128203;</span>
                    <h3 style="margin:0 0 6px;color:#292524;">No landlord applications found</h3>
                    <p style="margin:0;font-size:0.92rem;">There are no applications matching the selected filter.</p>
                </div>
            <?php else: ?>
                <div class="verif-grid">
                <?php foreach ($applications as $application):
                    $applicationName = trim(($application['first_name'] ?? '') . ' ' . ($application['middle_name'] ?? '') . ' ' . ($application['last_name'] ?? ''));
                    if ($applicationName === '') $applicationName = (string)($application['full_name'] ?? $application['email']);
                    $applicationStatus = (string)$application['status'];
                    $idPath = (string)($application['government_id_file'] ?? '');
                    $documentUrl = $idPath !== '' ? 'application-document.php?id=' . (int)$application['id'] : '';
                ?>
                    <article class="verif-card" id="application-<?= (int)$application['id'] ?>" style="grid-template-columns:minmax(240px,1fr) minmax(260px,1.15fr) minmax(210px,.7fr);">
                        <div class="user-meta">
                            <div class="user-avatar"><?= htmlspecialchars(strtoupper(substr($applicationName, 0, 1))) ?></div>
                            <div class="user-info">
                                <h3><?= htmlspecialchars($applicationName) ?></h3>
                                <span class="role-tag is-landlord">Landlord Applicant</span>
                                <div class="user-contact">
                                    <span><?= htmlspecialchars($application['email']) ?></span>
                                    <span><?= htmlspecialchars($application['phone']) ?></span>
                                    <span><?= htmlspecialchars($application['residential_address'] ?? '') ?></span>
                                </div>
                            </div>
                        </div>
                        <div style="display:grid;gap:10px;min-width:0;">
                            <div class="verif-doc-box">
                                <div class="doc-thumbnail-wrap">
                                    <div class="doc-thumb" <?= $documentUrl !== '' ? "onclick=\"openInspectModal('" . htmlspecialchars($documentUrl, ENT_QUOTES, 'UTF-8') . "', 'Government ID', '" . htmlspecialchars(addslashes($applicationName), ENT_QUOTES, 'UTF-8') . "', 0, 'landlord')\"" : '' ?>>
                                        <?php if ($documentUrl !== ''): ?><img src="<?= htmlspecialchars($documentUrl) ?>" alt="Government ID"><?php else: ?>&#10060;<?php endif; ?>
                                    </div>
                                    <div class="doc-details"><strong>Government ID</strong><span><?= $idPath !== '' ? htmlspecialchars(basename($idPath)) : 'Not uploaded' ?></span></div>
                                </div>
                            </div>
                            <div style="font-size:.84rem;line-height:1.45;color:#5f4a40;">
                                <strong style="display:block;color:#292524;margin-bottom:3px;">Experience and responsibility</strong>
                                <?= nl2br(htmlspecialchars((string)($application['experience_summary'] ?? ''))) ?>
                                <div style="margin-top:6px;color:#28733a;font-weight:600;">&#10003; Landlord responsibilities acknowledged</div>
                            </div>
                        </div>
                        <div class="verif-action-col">
                            <span class="verif-status-badge is-<?= $applicationStatus === 'approved' ? 'verified' : ($applicationStatus === 'declined' ? 'rejected' : 'pending') ?>"><?= htmlspecialchars(ucfirst($applicationStatus)) ?></span>
                            <?php $emailStatus = (string)($application['admin_email_status'] ?? 'unconfigured'); ?>
                            <span style="font-size:.75rem;color:<?= $emailStatus === 'sent' ? '#28733a' : '#9a3412' ?>;text-align:right;">
                                Admin email: <?= htmlspecialchars($emailStatus) ?><?= !empty($application['admin_email_sent_at']) ? ' · ' . htmlspecialchars(date('M d, Y g:i A', strtotime($application['admin_email_sent_at']))) : '' ?>
                            </span>
                            <?php if ($emailStatus !== 'sent'): ?>
                                <form method="post" style="margin:0;">
                                    <input type="hidden" name="view" value="applications">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_verification_csrf']) ?>">
                                    <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                                    <button class="btn-inspect" name="action" value="retry_application_email" type="submit">Retry admin email</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!empty($application['decision_note'])): ?><span style="font-size:.75rem;max-width:210px;text-align:right;"><?= htmlspecialchars($application['decision_note']) ?></span><?php endif; ?>
                            <?php if ($applicationStatus === 'pending'): ?>
                                <form method="post" style="display:grid;gap:8px;width:100%;">
                                    <input type="hidden" name="view" value="applications">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_verification_csrf']) ?>">
                                    <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                                    <textarea name="decision_note" rows="2" maxlength="500" placeholder="Decision note (optional)" style="width:100%;padding:8px;border:1px solid #d8c3b4;border-radius:8px;font:inherit;"></textarea>
                                    <div class="verif-buttons">
                                        <button class="btn-approve" name="action" value="approve_application" type="submit">Approve</button>
                                        <button class="btn-reject" name="action" value="decline_application" type="submit">Decline</button>
                                    </div>
                                </form>
                            <?php elseif ($applicationStatus === 'approved' && empty($application['registered_at'])): ?>
                                <form method="post">
                                    <input type="hidden" name="view" value="applications">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_verification_csrf']) ?>">
                                    <input type="hidden" name="application_id" value="<?= (int)$application['id'] ?>">
                                    <button class="btn-inspect" name="action" value="regenerate_invite" type="submit">Generate new invitation</button>
                                </form>
                            <?php elseif (!empty($application['registered_at'])): ?>
                                <span style="font-size:.75rem;color:#28733a;">Account created</span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
        <?php if (empty($requests)): ?>
            <div class="verif-empty">
                <span class="verif-empty-icon">🛡️</span>
                <h3 style="margin:0 0 6px; color:#292524;">No <?= htmlspecialchars($role) ?> verification requests found</h3>
                <p style="margin:0; font-size:0.92rem;">There are currently no <?= htmlspecialchars($role) ?> accounts matching the "<?= htmlspecialchars($filter) ?>" filter.</p>
            </div>
        <?php else: ?>
            <div class="verif-grid">
                <?php foreach ($requests as $item): 
                    $name = trim(($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? ''));
                    if ($name === '') $name = (string)($item['personal_email'] ?? ucfirst($role));
                    $initials = strtoupper(substr($name, 0, 1));
                    $st = $item['verification_status'] ?? 'unverified';
                    
                    // Documents according to role
                    if ($role === 'landlord') {
                        $doc1Title = 'Government ID';
                        $doc1Path = $item['gov_id_file'] ?? '';
                        $doc2Title = '';
                        $doc2Path = '';
                    } else {
                        $doc1Title = 'Student / School ID';
                        $doc1Path = $item['school_id_file'] ?? '';
                        $doc2Title = 'Government ID';
                        $doc2Path = $item['gov_id_file'] ?? '';
                    }

                    $webDoc1 = resolve_doc_web_url($doc1Path, $role);
                    $webDoc2 = resolve_doc_web_url($doc2Path, $role);
                    $hasDoc1 = $webDoc1 !== '';
                    $hasDoc2 = $webDoc2 !== '';

                    $avatarUrl = resolve_user_profile_photo_url(
                        $item['profile_photo'] ?? null,
                        $item['photo'] ?? null,
                        $role
                    );
                ?>
                    <article class="verif-card" id="user-<?= $item['id'] ?>">
                        <!-- User Info -->
                        <div class="user-meta">
                            <div class="user-avatar">
                                <?php if (!empty($avatarUrl)): ?>
                                    <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="">
                                <?php else: ?>
                                    <?= htmlspecialchars($initials) ?>
                                <?php endif; ?>
                            </div>
                            <div class="user-info">
                                <h3><?= htmlspecialchars($name) ?></h3>
                                <span class="role-tag <?= $role === 'landlord' ? 'is-landlord' : 'is-tenant' ?>">
                                    <?= $role === 'landlord' ? '🏠 Dorm Landlord' : '🎓 Student Tenant' ?>
                                </span>
                                <div class="user-contact">
                                    <span>✉ <?= htmlspecialchars($item['personal_email'] ?? 'No email') ?></span>
                                    <span>📞 <?= htmlspecialchars($item['phone_number'] ?? 'No phone') ?></span>
                                    
                                    <?php if ($role === 'tenant' && !empty($item['school'])): ?>
                                        <span class="detail-badge">🏫 <?= htmlspecialchars($item['school']) ?></span>
                                    <?php endif; ?>

                                    <?php if ($role === 'tenant' && !empty($item['emergency_name'])): ?>
                                        <span class="detail-badge" title="Emergency Contact">
                                            🚨 <?= htmlspecialchars($item['emergency_name']) ?> (<?= htmlspecialchars($item['emergency_relationship'] ?? 'Guardian') ?>): <?= htmlspecialchars($item['emergency_phone'] ?? 'N/A') ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($role === 'landlord' && !empty($item['facebook_link'])): ?>
                                        <a href="<?= htmlspecialchars($item['facebook_link']) ?>" target="_blank" style="color:#7a4b3a; text-decoration:underline; font-size:0.84rem;">Facebook Profile ↗</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Documents Inspection Column (Pair) -->
                        <div class="verif-docs-pair" <?= $role === 'landlord' ? 'style="grid-template-columns:minmax(0,1fr);"' : '' ?>>
                            <!-- Document 1 -->
                            <div class="verif-doc-box <?= !$hasDoc1 ? 'is-missing' : '' ?>">
                                <div class="doc-thumbnail-wrap">
                                    <div class="doc-thumb" <?= $hasDoc1 ? "onclick=\"openInspectModal('" . htmlspecialchars($webDoc1) . "', '" . htmlspecialchars(addslashes($doc1Title)) . "', '" . htmlspecialchars(addslashes($name)) . "', " . (int)$item['id'] . ", '" . $role . "')\"" : "" ?>>
                                        <?php if ($hasDoc1 && preg_match('/\.(jpg|jpeg|png|webp)$/i', parse_url($webDoc1, PHP_URL_PATH) ?? '')): ?>
                                            <img src="<?= htmlspecialchars($webDoc1) ?>" alt="<?= htmlspecialchars($doc1Title) ?>" onerror="showDocumentUnavailable(this)">
                                        <?php elseif ($hasDoc1): ?>
                                            📄
                                        <?php else: ?>
                                            ❌
                                        <?php endif; ?>
                                    </div>
                                    <div class="doc-details">
                                        <strong><?= htmlspecialchars($doc1Title) ?></strong>
                                        <span><?= $hasDoc1 ? htmlspecialchars(basename($doc1Path)) : (!empty($doc1Path) ? 'Photo unavailable' : 'Not uploaded') ?></span>
                                    </div>
                                </div>
                                <?php if ($hasDoc1): ?>
                                    <button type="button" class="btn-inspect" onclick="openInspectModal('<?= htmlspecialchars($webDoc1) ?>', '<?= htmlspecialchars(addslashes($doc1Title)) ?>', '<?= htmlspecialchars(addslashes($name)) ?>', <?= (int)$item['id'] ?>, '<?= $role ?>')">
                                        Inspect 🔍
                                    </button>
                                <?php endif; ?>
                            </div>

                            <?php if ($role !== 'landlord'): ?>
                            <!-- Document 2 -->
                            <div class="verif-doc-box <?= !$hasDoc2 ? 'is-missing' : '' ?>">
                                <div class="doc-thumbnail-wrap">
                                    <div class="doc-thumb" <?= $hasDoc2 ? "onclick=\"openInspectModal('" . htmlspecialchars($webDoc2) . "', '" . htmlspecialchars(addslashes($doc2Title)) . "', '" . htmlspecialchars(addslashes($name)) . "', " . (int)$item['id'] . ", '" . $role . "')\"" : "" ?>>
                                        <?php if ($hasDoc2 && preg_match('/\.(jpg|jpeg|png|webp)$/i', parse_url($webDoc2, PHP_URL_PATH) ?? '')): ?>
                                            <img src="<?= htmlspecialchars($webDoc2) ?>" alt="<?= htmlspecialchars($doc2Title) ?>" onerror="showDocumentUnavailable(this)">
                                        <?php elseif ($hasDoc2): ?>
                                            📄
                                        <?php else: ?>
                                            ❌
                                        <?php endif; ?>
                                    </div>
                                    <div class="doc-details">
                                        <strong><?= htmlspecialchars($doc2Title) ?></strong>
                                        <span><?= $hasDoc2 ? htmlspecialchars(basename($doc2Path)) : (!empty($doc2Path) ? 'Photo unavailable' : 'Not uploaded') ?></span>
                                    </div>
                                </div>
                                <?php if ($hasDoc2): ?>
                                    <button type="button" class="btn-inspect" onclick="openInspectModal('<?= htmlspecialchars($webDoc2) ?>', '<?= htmlspecialchars(addslashes($doc2Title)) ?>', '<?= htmlspecialchars(addslashes($name)) ?>', <?= (int)$item['id'] ?>, '<?= $role ?>')">
                                        Inspect 🔍
                                    </button>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Status & Controls -->
                        <div class="verif-action-col">
                            <?php if ($st === 'verified'): ?>
                                <span class="verif-status-badge is-verified">✓ Verified</span>
                                <span style="font-size:0.75rem; color:#8c7569;">Approved on <?= !empty($item['verified_at']) ? htmlspecialchars(date('M d, Y', strtotime($item['verified_at']))) : 'System' ?></span>
                            <?php elseif ($st === 'rejected'): ?>
                                <span class="verif-status-badge is-rejected">⚠ Rejected</span>
                                <?php if (!empty($item['verification_notes'])): ?>
                                    <span style="font-size:0.75rem; color:#991b1b; text-align:right; max-width:190px; word-break:break-word;"><?= htmlspecialchars($item['verification_notes']) ?></span>
                                <?php endif; ?>
                            <?php elseif ($st === 'pending'): ?>
                                <span class="verif-status-badge is-pending">⏳ Needs Review</span>
                            <?php else: ?>
                                <span class="verif-status-badge is-unverified">Incomplete</span>
                            <?php endif; ?>

                            <div class="verif-buttons">
                                <?php if ($st !== 'verified'): ?>
                                    <form method="POST" style="margin:0;" data-confirm-name="<?= htmlspecialchars($name) ?>">
                                        <input type="hidden" name="action" value="approve_user">
                                        <input type="hidden" name="target_user_id" value="<?= (int)$item['id'] ?>">
                                        <input type="hidden" name="target_role" value="<?= htmlspecialchars($role) ?>">
                                        <button type="submit" class="btn-approve">Approve</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($st !== 'rejected'): ?>
                                    <button type="button" class="btn-reject" onclick="openRejectModal(<?= (int)$item['id'] ?>, '<?= htmlspecialchars(addslashes($name)) ?>', '<?= htmlspecialchars($role) ?>')">
                                        Decline
                                    </button>
                                <?php else: ?>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="reset_user">
                                        <input type="hidden" name="target_user_id" value="<?= (int)$item['id'] ?>">
                                        <input type="hidden" name="target_role" value="<?= htmlspecialchars($role) ?>">
                                        <button type="submit" class="btn-inspect" style="padding:6px 10px; font-size:0.8rem;">
                                            Reset to Pending
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($successMessage !== '' || $errorMessage !== ''):
        $isError = $errorMessage !== '';
        $resultMessage = $isError ? $errorMessage : $successMessage;
        $postedAction = (string)($_POST['action'] ?? '');
        if ($isError) {
            $resultTitle = 'Action Failed';
        } elseif (strpos($postedAction, 'approve') === 0) {
            $resultTitle = 'Account Verified';
        } elseif (strpos($postedAction, 'reject') === 0) {
            $resultTitle = 'Verification Declined';
        } else {
            $resultTitle = 'Reset to Pending';
        }
    ?>
    <div class="policy-modal" id="resultModal">
        <div class="policy-modal-backdrop" data-policy-close></div>
        <div class="policy-modal-panel" role="dialog" aria-modal="true" aria-labelledby="resultModalTitle">
            <header class="policy-modal-header">
                <div class="policy-modal-title-group">
                    <p class="policy-modal-crumb">UniStay &bull; Verification Center</p>
                    <h2 id="resultModalTitle"><?= htmlspecialchars($resultTitle) ?></h2>
                </div>
                <button type="button" class="policy-modal-close" data-policy-close aria-label="Close modal">&times;</button>
            </header>
            <div class="policy-modal-body">
                <section class="policy-card">
                    <h2><?= $isError ? 'Something went wrong' : 'All done' ?></h2>
                    <p><?= htmlspecialchars($resultMessage) ?></p>
                </section>
            </div>
            <footer class="policy-modal-footer">
                <span class="policy-modal-footer-note">&copy; 2026 UniStay Legal Standards</span>
                <button type="button" class="btn-policy-confirm" data-policy-close>Understood</button>
            </footer>
        </div>
    </div>
    <?php endif; ?>

    <div class="policy-modal" id="confirmModal" hidden>
        <div class="policy-modal-backdrop" data-confirm-close></div>
        <div class="policy-modal-panel" style="width:min(100%,520px);" role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle">
            <header class="policy-modal-header">
                <div class="policy-modal-title-group">
                    <p class="policy-modal-crumb">UniStay &bull; Verification Center</p>
                    <h2 id="confirmModalTitle">Approve Verification</h2>
            </div>
            <button type="button" class="policy-modal-close" data-confirm-close aria-label="Close modal">&times;</button>
        </header>
        <div class="policy-modal-body">
            <section class="policy-card">
                <h2>Please confirm</h2>
                <p id="confirmModalText">Approve verification for this account?</p>
            </section>
        </div>
        <footer class="policy-modal-footer">
            <button type="button" class="btn-policy-cancel" data-confirm-close>Cancel</button>
            <button type="button" class="btn-policy-confirm" id="confirmModalOk">Approve</button>
        </footer>
    </div>
</div>

    <div id="inspectModal" class="inspect-modal" onclick="if(event.target===this)closeInspectModal()">
        <div class="inspect-box">
            <header class="inspect-header">
                <div>
                    <h3 id="inspectTitle" style="margin:0; color:#292524; font-size:1.15rem;">Review Document</h3>
                    <span id="inspectSub" style="font-size:0.85rem; color:#78665c;">Verify document authenticity, clarity, and account ownership.</span>
                </div>
                <button type="button" class="btn-inspect" onclick="closeInspectModal()">✕ Close</button>
            </header>
            <div class="inspect-body">
                <img id="inspectImg" class="inspect-img" src="" alt="Credential Full Resolution" onerror="this.hidden=true;document.getElementById('inspectUnavailable').hidden=false">
                    <p id="inspectUnavailable" hidden role="status">Photo unavailable. The uploaded file could not be loaded.</p>
            </div>
            <footer class="inspect-footer">
                <div style="font-size:0.88rem; color:#78665c;">
                    UniStay Identity & Property Verification
                </div>
                <div style="display:flex; gap:10px;">
                    <form id="inspectApproveForm" method="POST" style="margin:0;">
                        <input type="hidden" name="action" value="approve_user">
                        <input type="hidden" name="target_user_id" id="inspectApproveId" value="0">
                        <input type="hidden" name="target_role" id="inspectApproveRole" value="<?= htmlspecialchars($role) ?>">
                        <button type="submit" class="btn-approve">Approve</button>
                    </form>
                    <button type="button" class="btn-reject" id="inspectRejectBtn">Decline</button>
                </div>
            </footer>
        </div>
    </div>

    <!-- Reject Reason Modal -->
    <div id="rejectModal" class="inspect-modal" onclick="if(event.target===this)closeRejectModal()">
        <div class="inspect-box" style="max-width: 520px;">
            <header class="inspect-header">
                <h3 style="margin:0; color:#991b1b; font-size:1.15rem;">Decline Verification</h3>
                <button type="button" class="btn-inspect" onclick="closeRejectModal()">✕</button>
            </header>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="reject_user">
                <input type="hidden" name="target_user_id" id="rejectTargetId" value="0">
                <input type="hidden" name="target_role" id="rejectTargetRole" value="<?= htmlspecialchars($role) ?>">
                <div style="padding: 20px 24px;">
                    <p style="margin:0 0 12px; font-size:0.92rem; color:#57534e;">
                        Please provide a reason for rejecting <strong id="rejectTargetName">this user</strong>'s verification. This message will be displayed in their account settings.
                    </p>
                    
                    <label style="display:block; font-size:0.86rem; font-weight:600; color:#292524; margin-bottom:6px;">Common Reasons:</label>
                    <div style="display:flex; flex-direction:column; gap:6px; margin-bottom:12px; font-size:0.84rem;">
                        <label style="cursor:pointer;"><input type="radio" name="preset_reason" onclick="applyPresetReason(this.value)" value="Document photo is blurry, unreadable, or cut off. Please upload a clear, full document photo."> Blurry / Unreadable Document</label>
                        <label style="cursor:pointer;"><input type="radio" name="preset_reason" onclick="applyPresetReason(this.value)" value="The name or details on the ID do not match the account registration profile."> Name / Details Mismatch</label>
                        <label style="cursor:pointer;"><input type="radio" name="preset_reason" onclick="applyPresetReason(this.value)" value="Document appears expired or is not an acceptable government / school credential."> Expired or Invalid Credential</label>
                    </div>

                    <label style="display:block; font-size:0.86rem; font-weight:600; color:#292524; margin-bottom:6px;">Custom Feedback / Instructions:</label>
                    <textarea id="rejectionNotesText" name="rejection_notes" rows="4" style="width:100%; border:1px solid #ebd9cb; border-radius:10px; padding:10px; font-family:inherit; font-size:0.9rem;" required>Document photo is blurry or illegible. Please submit a high-quality scan or photo of your credentials.</textarea>
                </div>
                <footer class="inspect-footer">
                    <button type="button" class="btn-inspect" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" class="btn-reject" style="background:#dc2626; color:#fff; border:none;">Confirm Decline</button>
                </footer>
            </form>
        </div>
    </div>

    <script>
        var currentModalUserId = 0;
        var currentModalUserName = '';
        var currentModalRole = '<?= htmlspecialchars($role) ?>';

        function openInspectModal(imgUrl, docTitle, name, userId, role) {
            currentModalUserId = userId;
            currentModalUserName = name;
            currentModalRole = role || '<?= htmlspecialchars($role) ?>';

            document.getElementById('inspectTitle').textContent = docTitle + ' · ' + name;
            var approveForm = document.getElementById('inspectApproveForm');
            var rejectBtn = document.getElementById('inspectRejectBtn');
            
            if (docTitle === 'Proof of Ownership') {
                document.getElementById('inspectSub').textContent = 'Property Listing #' + userId + ' · Proof of Ownership inspection';
                if (approveForm) approveForm.style.display = 'none';
                if (rejectBtn) rejectBtn.style.display = 'none';
            } else {
                document.getElementById('inspectSub').textContent = 'Role: ' + (role === 'landlord' ? 'Dorm Landlord' : 'Student Tenant') + ' (ID #' + userId + ')';
                if (approveForm) approveForm.style.display = (userId > 0) ? '' : 'none';
                if (rejectBtn) rejectBtn.style.display = (userId > 0) ? '' : 'none';
            }

            document.getElementById('inspectImg').hidden = false;
            document.getElementById('inspectUnavailable').hidden = true;
            document.getElementById('inspectImg').src = imgUrl;
            document.getElementById('inspectApproveId').value = userId;
            document.getElementById('inspectApproveRole').value = currentModalRole;
            document.getElementById('inspectModal').classList.add('is-active');

            if (rejectBtn) {
                rejectBtn.onclick = function() {
                    closeInspectModal();
                    openRejectModal(userId, name, currentModalRole);
                };
            }
        }

        function closeInspectModal() {
            document.getElementById('inspectModal').classList.remove('is-active');
            var approveForm = document.getElementById('inspectApproveForm');
            var rejectBtn = document.getElementById('inspectRejectBtn');
            if (approveForm) approveForm.style.display = '';
            if (rejectBtn) rejectBtn.style.display = '';
        }

        function openRejectModal(userId, name, role) {
            document.getElementById('rejectTargetId').value = userId;
            document.getElementById('rejectTargetName').textContent = name;
            document.getElementById('rejectTargetRole').value = role || '<?= htmlspecialchars($role) ?>';
            document.getElementById('rejectModal').classList.add('is-active');
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('is-active');
        }

        function applyPresetReason(text) {
            document.getElementById('rejectionNotesText').value = text;
        }

        (function () {
            var modal = document.getElementById('resultModal');
            if (!modal) return;
            document.body.style.overflow = 'hidden';
            function closeResult() {
                modal.classList.add('is-closing');
                setTimeout(function () {
                    modal.remove();
                    document.body.style.overflow = '';
                }, 220);
            }
            modal.addEventListener('click', function (e) {
                if (e.target.closest('[data-policy-close]')) closeResult();
            });
            window.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeResult();
            });
        })();

        (function () {
            var modal = document.getElementById('confirmModal');
            var okBtn = document.getElementById('confirmModalOk');
            var text = document.getElementById('confirmModalText');
            var pendingForm = null, bypass = false;
            if (!modal) return;

            // Capture phase: runs before admin-verification-actions.js, which then skips the blocked submit
            document.addEventListener('submit', function (e) {
                if (bypass) return;
                var form = e.target;
                var action = form.querySelector('input[name="action"]');
                if (!action || !/^approve_(user|landlord|tenant)$/.test(action.value)) return;
                e.preventDefault();
                pendingForm = form;
                var who = form.dataset.confirmName || currentModalUserName || 'this account';
                text.textContent = 'Approve verification for ' + who + '?';
                modal.classList.remove('is-closing');
                modal.hidden = false;
                document.body.style.overflow = 'hidden';
            }, true);

            function closeConfirm() {
                modal.classList.add('is-closing');
                setTimeout(function () {
                    modal.hidden = true;
                    modal.classList.remove('is-closing');
                    document.body.style.overflow = '';
                }, 220);
            }

            okBtn.addEventListener('click', function () {
                if (!pendingForm) return;
                var form = pendingForm;
                pendingForm = null;
                bypass = true;
                form.requestSubmit();   // fires the normal submit, so your button-disabling script still runs
                bypass = false;
                closeConfirm();
            });

            modal.addEventListener('click', function (e) {
                if (e.target.closest('[data-confirm-close]')) { pendingForm = null; closeConfirm(); }
            });
            window.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !modal.hidden) { pendingForm = null; closeConfirm(); }
            });
        })();
    </script>
<script src="../assets/js/admin-verification-actions.js?v=1"></script>
</body>
</html>
