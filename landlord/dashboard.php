<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

$authenticatedUser = require_signed_in('landlord');
$pdo = database();
$user_id = (int)($_SESSION['user_id'] ?? 0);
$profileIncomplete = !landlord_profile_complete($pdo, $user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Landlord Dashboard | UniStay</title>
    <style>
        :root { color-scheme: light; font-family: "Segoe UI", Arial, sans-serif; color: #3f302b; background: #fdf9f3; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 18px max(24px, calc((100% - 1040px) / 2)); background: #fff; border-bottom: 1px solid #eadfd4; }
        .brand { display: flex; align-items: center; gap: 12px; color: #5c3327; font-family: Georgia, serif; font-size: 20px; font-weight: 700; }
        .brand img { width: 42px; height: 42px; object-fit: contain; }
        a { color: #7b4a3b; }
        main { width: min(100% - 40px, 1040px); margin: 48px auto; }
        h1 { color: #5c3327; font-family: Georgia, serif; }
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-top: 28px; }
        .card { padding: 24px; border: 1px solid #eadfd4; border-radius: 14px; background: #fff; }
        .card h2 { margin-top: 0; font-size: 18px; }
        .card a { display: inline-block; margin-top: 8px; font-weight: 600; }
        @media (max-width: 520px) { header { align-items: flex-start; flex-direction: column; } }

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
<header>
    <div class="brand"><img src="../assets/images/tenant/logo.png" alt=""><span>UniStay</span></div>
    <?php if (isset($authenticatedUser)): ?>
        <span><?php echo htmlspecialchars($authenticatedUser['name'], ENT_QUOTES, 'UTF-8'); ?></span>
        <a href="../auth/login.php?action=logout">Sign out</a>
    <?php endif; ?>
</header>
<main>
    <?php if (isset($signInError)): ?>
        <p role="alert"><?php echo htmlspecialchars($signInError, ENT_QUOTES, 'UTF-8'); ?> <a href="../auth/login.php">Return to sign in</a></p>
    <?php endif; ?>
    <h1>Landlord Dashboard</h1>
    <p>Manage your UniStay listings and rooms.</p>
    <section class="cards" aria-label="Landlord tools">
        <article class="card">
            <h2>Properties</h2>
            <p>Add and manage your dorm listings.</p>
            <a href="properties.php">Manage properties</a>
        </article>
        <article class="card">
            <h2>Rooms</h2>
            <p>Review room details and availability.</p>
            <a href="rooms.php">Manage rooms</a>
        </article>
    </section>
</main>
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
</body>
</html>
