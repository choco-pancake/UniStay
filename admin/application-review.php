<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/landlord_applications.php';

start_app_session();
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$pdo = database();
$errorMessage = '';
$successMessage = '';
$invitationUrl = '';
$application = null;

if (isset($_GET['id'], $_GET['token'])) {
    $applicationId = (int)$_GET['id'];
    $token = trim((string)$_GET['token']);
    $application = landlord_application_from_review_token($pdo, $applicationId, $token);
    if ($application) {
        session_regenerate_id(true);
        $_SESSION['landlord_email_review'] = [
            'application_id' => $applicationId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => strtotime((string)$application['admin_review_token_expires_at']),
        ];
        $_SESSION['landlord_email_review_csrf'] = bin2hex(random_bytes(32));
        header('Location: application-review.php', true, 303);
        exit;
    }
    unset($_SESSION['landlord_email_review'], $_SESSION['landlord_email_review_csrf']);
    $errorMessage = 'This review link is invalid, expired, replaced, or has already been used.';
} else {
    $application = landlord_application_from_review_grant($pdo, (array)($_SESSION['landlord_email_review'] ?? []));
    if (!$application) {
        unset($_SESSION['landlord_email_review'], $_SESSION['landlord_email_review_csrf']);
        $errorMessage = 'This review link is invalid, expired, replaced, or has already been used.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $application) {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string)($_SESSION['landlord_email_review_csrf'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    if ($sessionCsrf === '' || $postedCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
        $errorMessage = 'This review session expired. Reopen the link from the admin email.';
    } elseif (!in_array($action, ['approve_application', 'decline_application'], true)) {
        $errorMessage = 'Choose Approve or Decline to record a decision.';
    } else {
        $result = decide_landlord_application(
            $pdo,
            (int)$application['id'],
            $action,
            (string)($_POST['decision_note'] ?? '')
        );
        if ($result['ok']) {
            $successMessage = (string)$result['message'];
            $invitationUrl = (string)($result['invitation_url'] ?? '');
            $application = null;
            unset($_SESSION['landlord_email_review'], $_SESSION['landlord_email_review_csrf']);
        } else {
            $errorMessage = (string)$result['message'];
            $application = landlord_application_from_review_grant($pdo, (array)($_SESSION['landlord_email_review'] ?? []));
        }
    }
}

function review_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$applicationName = $application
    ? trim((string)$application['first_name'] . ' ' . (string)($application['middle_name'] ?? '') . ' ' . (string)$application['last_name'])
    : '';
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="referrer" content="no-referrer">
  <meta name="theme-color" content="#7a4b3a">
  <title>Review Landlord Application | UniStay</title>
  <link rel="icon" href="../assets/images/logo.png">
  <link rel="stylesheet" href="../assets/css/common.css?v=32">
  <style>
    /* ---------- Page frame: one screen, no scrolling on desktop ---------- */
    body.review-page{height:100dvh;overflow:hidden;padding:14px;display:flex}
    .rv-shell{flex:1;min-width:0;min-height:0;display:flex;flex-direction:column;padding:0 40px 24px;
      background:linear-gradient(180deg,rgba(253,249,243,.98) 0%,rgba(249,243,234,.98) 100%);
      border-radius:22px;box-shadow:0 30px 60px rgba(92,62,48,.12);position:relative}
    .rv-topbar{flex:none;height:72px;display:flex;align-items:center;justify-content:space-between;gap:20px;border-bottom:1px solid #c9a797}
    .rv-title{display:flex;align-items:center;gap:14px;min-width:0}
    .rv-title img{width:42px;height:42px;flex-shrink:0}
    .rv-title h1{margin:0;font:700 28px/1.2 'Fraunces',Georgia,"Times New Roman",serif;letter-spacing:-.6px;color:#3b2820}
    .rv-role{color:#7a4b3a;font-size:.78rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;white-space:nowrap}

    .rv-main{flex:1;min-height:0;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.5fr);gap:22px;padding-top:20px}
    .rv-col{display:flex;flex-direction:column;gap:18px;min-height:0;min-width:0}

    /* ---------- Cards (same as .settings-card) ---------- */
    .rv-card{display:flex;flex-direction:column;min-height:0;background:#fff;border-radius:18px;border:1px solid #ebd9cb;
      box-shadow:0 4px 16px rgba(84,60,48,.06);padding:22px 26px}
    .rv-section-title{flex:none;margin:0 0 14px;padding-bottom:8px;border-bottom:1px solid #f0e6e0;
      font-size:1.05rem;font-weight:700;color:#3b2820}
    .rv-card-fill{flex:1}

    .rv-applicant{flex:1;min-height:0}
    .rv-rows{flex:1;display:flex;flex-direction:column;justify-content:space-between;gap:12px}
    .rv-row{display:flex;flex-direction:column;gap:4px;min-width:0}
    .rv-row .rv-val{display:block;min-height:40px;line-height:22px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .rv-applicant .rv-ack{margin-top:18px}

    /* Experience card */
    .rv-exp{flex:none}
    .rv-exp .rv-section-title{margin-bottom:10px}
    .rv-exp .rv-val.rv-scroll{flex:none;max-height:112px}

    /* Plain, smaller Approve / Decline buttons (no box) */
    .rv-actions{flex:none;display:flex;justify-content:flex-end;gap:10px}
    .rv-actions .rv-btn{height:36px;padding:0 18px;font-size:.86rem;border-radius:10px}

    .rv-label{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#8b7467}
    .rv-val{display:flex;align-items:center;min-height:40px;padding:9px 14px;border-radius:10px;border:1px solid #f0e6e0;
      background:#fdfaf7;color:#3b2820;font-size:.92rem;font-weight:600;word-break:break-word}
    .rv-val.rv-scroll{flex:1;min-height:0;align-items:flex-start;overflow-y:auto;line-height:1.5;font-weight:500}
    .rv-ack{flex:none;align-self:flex-start;display:inline-flex;align-items:center;gap:6px;margin-top:14px;padding:5px 14px;
      border-radius:20px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-size:.8rem;font-weight:600}

    /* ---------- ID document ---------- */
    .rv-meta{flex:none;display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
    .rv-meta .rv-section-title{margin:0;padding:0;border:0}
    .rv-link{font-size:.8rem;font-weight:600;color:#7a4b3a;text-decoration:underline}
    .rv-thumb{flex:1;min-height:0;display:flex;align-items:center;justify-content:center;border-radius:12px;overflow:hidden;
      background:#f5ede6;border:1px solid #e2cec0}
    .rv-thumb img{width:100%;height:100%;object-fit:contain;display:block;transition:transform .25s ease}
    .rv-thumb:hover img{transform:scale(1.02)}

    .rv-decision{padding:16px 26px}
.rv-decision .rv-actions{margin-top:0}

    /* ---------- Buttons (same as settings) ---------- */
    .rv-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:44px;padding:0 24px;border-radius:12px;
      border:1px solid transparent;font:700 .94rem 'DM Sans',sans-serif;cursor:pointer;transition:all .2s ease}
    .rv-btn:hover{transform:translateY(-1px)}
    .rv-btn-primary{background:linear-gradient(135deg,#7a4b3a 0%,#5d3627 100%);color:#fff;box-shadow:0 4px 14px rgba(122,75,58,.22)}
    .rv-btn-primary:hover{filter:brightness(1.08)}
    .rv-btn-secondary{background:#fff;color:#7a4b3a;border-color:#d9bea6;font-weight:600;box-shadow:0 2px 6px rgba(84,60,48,.05)}
    .rv-btn-secondary:hover{background:#fbf5ef;border-color:#7a4b3a}
    .rv-btn-danger{background:#fee2e2;color:#dc2626;border-color:#fecaca}
    .rv-btn-danger:hover{background:#fca5a5;color:#991b1b}
    .rv-btn-solid-danger{background:#dc2626;color:#fff;box-shadow:0 2px 6px rgba(220,38,38,.25)}
    .rv-btn-solid-danger:hover{background:#b91c1c}
    .rv-btn:disabled{opacity:.7;cursor:not-allowed;transform:none;filter:none}

    /* ---------- Alerts / result state (same as .settings-alert) ---------- */
    .rv-alert{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-radius:14px;font-size:.92rem;font-weight:500;line-height:1.5}
    .rv-alert.is-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
    .rv-alert.is-success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
    .rv-inline-alert{flex:none;margin-bottom:12px}
    .rv-state{flex:1;display:grid;place-items:center;padding-top:20px}
    .rv-state .rv-card{width:min(560px,100%);gap:14px}
    .rv-invite{display:block;margin-top:10px;padding:10px 14px;border-radius:10px;background:#fff;border:1px solid #a7f3d0;
      color:#065f46;font-size:.85rem;font-weight:500;word-break:break-all}

    /* ---------- Confirm modal (same as logout modal) ---------- */
    .dm{width:min(92vw,440px);padding:0;border:1px solid #ebd9cb;border-radius:20px;background:#fff;color:#3b2820;box-shadow:0 20px 50px rgba(45,25,18,.28)}
    .dm::backdrop{background:rgba(30,15,10,.58);backdrop-filter:blur(5px)}
    .dm[open]{animation:dm-in .26s cubic-bezier(.16,1,.3,1) both}
    @keyframes dm-in{from{opacity:0;transform:translateY(14px) scale(.95)}to{opacity:1;transform:none}}
    .dm-card{padding:28px 28px 24px;text-align:center}
    .dm-icon{width:60px;height:60px;margin:0 auto 14px;border-radius:50%;display:grid;place-items:center;font-size:26px;font-weight:800}
    .dm-icon.approve{background:#ecfdf5;color:#059669;border:1px solid #a7f3d0}
    .dm-icon.decline{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
    .dm h2{margin:0 0 8px;font:700 1.4rem 'Fraunces',Georgia,serif;letter-spacing:0;color:#292524}
    .dm p{margin:0 0 20px;color:#78665c;line-height:1.5;font-size:.92rem}
    .dm-reason{text-align:left;margin-bottom:18px}
    .dm-reason .rv-label{display:block;margin-bottom:6px}
    .dm-reason textarea{width:100%;min-height:92px;resize:vertical;padding:12px 14px;border:1px solid #d9bea6;border-radius:12px;font:inherit;font-size:.94rem;color:#3b2820}
    .dm-reason textarea:focus{outline:none;border-color:#7a4b3a;box-shadow:0 0 0 3px rgba(122,75,58,.15)}
    .dm-reason.invalid textarea{border-color:#dc2626;box-shadow:0 0 0 3px rgba(220,38,38,.12)}
    .dm-error{display:none;margin-top:6px;color:#dc2626;font-size:.82rem;font-weight:600}
    .dm-reason.invalid .dm-error{display:block}
    .dm-actions{display:flex;gap:12px}
    .dm-actions .rv-btn{flex:1;padding:0 16px}

    /* ---------- Small screens or short windows: fall back to normal scrolling ---------- */
    @media (max-width:900px),(max-height:620px){
      body.review-page{height:auto;min-height:100dvh;overflow:auto}
      .rv-shell{padding:0 18px 20px}
      .rv-topbar{height:auto;padding:14px 0;flex-wrap:wrap}
      .rv-title h1{font-size:22px}
      .rv-main{grid-template-columns:1fr}
      .rv-thumb{height:240px;flex:none}
      .rv-val.rv-scroll{max-height:160px}
      .rv-actions{flex-direction:column-reverse}
      .rv-actions .rv-btn{width:100%}
      .rv-row .rv-val{white-space:normal}
      .rv-exp .rv-val.rv-scroll{max-height:160px}
    }
  </style>
</head>
<body class="review-page">
<div class="rv-shell">
  <header class="rv-topbar">
    <div class="rv-title">
      <img src="../assets/images/logo.png" alt="UniStay logo">
      <h1>Landlord Application</h1>
    </div>
    <span class="rv-role">Admin Review</span>
  </header>

  <?php if ($application): ?>
    <main class="rv-main">
      <!-- LEFT: applicant details (wider, one field per line) -->
      <div class="rv-col">
        <section class="rv-card rv-applicant">
          <h2 class="rv-section-title">Applicant Information</h2>
          <?php if ($errorMessage !== ''): ?><div class="rv-alert is-error rv-inline-alert" role="alert">⚠ <?= review_h($errorMessage) ?></div><?php endif; ?>
          <div class="rv-rows">
            <div class="rv-row"><span class="rv-label">Applicant</span><div class="rv-val" title="<?= review_h($applicationName) ?>"><?= review_h($applicationName) ?></div></div>
            <div class="rv-row"><span class="rv-label">Submitted</span><div class="rv-val"><?= review_h(date('M d, Y g:i A', strtotime((string)$application['created_at']))) ?></div></div>
            <div class="rv-row"><span class="rv-label">Email</span><div class="rv-val" title="<?= review_h((string)$application['email']) ?>"><?= review_h((string)$application['email']) ?></div></div>
            <div class="rv-row"><span class="rv-label">Phone</span><div class="rv-val"><?= review_h((string)$application['phone']) ?></div></div>
            <div class="rv-row"><span class="rv-label">Residential Address</span><div class="rv-val" title="<?= review_h((string)$application['residential_address']) ?>"><?= review_h((string)$application['residential_address']) ?></div></div>
          </div>
          <span class="rv-ack">✓ Responsible-landlord requirements acknowledged</span>
        </section>
      </div>

      <!-- RIGHT: experience, then ID, then buttons -->
      <div class="rv-col">
        <section class="rv-card rv-exp">
          <h2 class="rv-section-title">Rental or Property-Management Experience</h2>
          <div class="rv-val rv-scroll"><?= nl2br(review_h((string)$application['experience_summary'])) ?></div>
        </section>

        <section class="rv-card rv-card-fill">
          <div class="rv-meta">
            <h2 class="rv-section-title">Government-Issued ID</h2>
            <a class="rv-link" href="application-document.php?id=<?= (int)$application['id'] ?>" target="_blank" rel="noreferrer">View full photo ↗</a>
          </div>
          <a class="rv-thumb" href="application-document.php?id=<?= (int)$application['id'] ?>" target="_blank" rel="noreferrer">
            <img src="application-document.php?id=<?= (int)$application['id'] ?>" alt="Government-issued ID">
          </a>
        </section>

        <form method="post" class="decision">
          <input type="hidden" name="csrf_token" value="<?= review_h((string)$_SESSION['landlord_email_review_csrf']) ?>">
          <input type="hidden" name="decision_note" id="decision-note" value="">
          <div class="rv-actions">
            <button type="submit" class="rv-btn rv-btn-danger" name="action" value="decline_application">Decline</button>
            <button type="submit" class="rv-btn rv-btn-primary" name="action" value="approve_application">Approve</button>
          </div>
        </form>
      </div>
    </main>
  <?php else: ?>
    <main class="rv-state">
      <section class="rv-card">
        <?php if ($successMessage !== ''): ?>
          <h2 class="rv-section-title">Decision recorded</h2>
          <div class="rv-alert is-success" role="status">
            <div><?= review_h($successMessage) ?>
              <?php if ($invitationUrl !== ''): ?><span class="rv-invite"><?= review_h($invitationUrl) ?></span><?php endif; ?>
            </div>
          </div>
        <?php else: ?>
          <h2 class="rv-section-title">Review link unavailable</h2>
          <div class="rv-alert is-error" role="alert">⚠ <?= review_h($errorMessage) ?></div>
        <?php endif; ?>
      </section>
    </main>
  <?php endif; ?>
</div>

<dialog id="decisionModal" class="dm" aria-labelledby="dmTitle" aria-describedby="dmMsg">
  <div class="dm-card">
    <div class="dm-icon" id="dmIcon" aria-hidden="true"></div>
    <h2 id="dmTitle"></h2>
    <p id="dmMsg"></p>
    <div class="dm-reason" id="dmReasonWrap" hidden>
      <label for="dmReason" class="rv-label">Reason for declining *</label>
      <textarea id="dmReason" maxlength="500" placeholder="Tell the applicant why, so they know what to fix"></textarea>
      <div class="dm-error">Please give a reason so the applicant gets feedback.</div>
    </div>
    <div class="dm-actions">
      <button type="button" class="rv-btn rv-btn-secondary" id="dmCancel">Cancel</button>
      <button type="button" class="rv-btn" id="dmConfirm"></button>
    </div>
  </div>
</dialog>

<script>
(function () {
  var form = document.querySelector('form.decision');
  var dlg = document.getElementById('decisionModal');
  if (!form || !dlg || typeof dlg.showModal !== 'function') return;

  var note = document.getElementById('decision-note');
  var icon = document.getElementById('dmIcon');
  var title = document.getElementById('dmTitle');
  var msg = document.getElementById('dmMsg');
  var wrap = document.getElementById('dmReasonWrap');
  var reason = document.getElementById('dmReason');
  var cancel = document.getElementById('dmCancel');
  var ok = document.getElementById('dmConfirm');
  var action = null, busy = false;

  var copy = {
    approve_application: {
      cls: 'approve', btnCls: 'rv-btn-primary', icon: '\u2713', title: 'Approve this application?',
      msg: 'The applicant will be notified and sent an invitation to continue as a landlord.',
      btn: 'Approve application', busy: 'Approving\u2026'
    },
    decline_application: {
      cls: 'decline', btnCls: 'rv-btn-solid-danger', icon: '\u2715', title: 'Decline this application?',
      msg: 'The applicant will be notified that their application was declined.',
      btn: 'Decline application', busy: 'Declining\u2026'
    }
  };

  form.addEventListener('submit', function (e) {
    var key = e.submitter && e.submitter.value;
    if (!copy[key]) return;
    e.preventDefault();
    if (busy) return;
    action = key;
    var c = copy[key];
    icon.className = 'dm-icon ' + c.cls; icon.textContent = c.icon;
    title.textContent = c.title; msg.textContent = c.msg;
    ok.className = 'rv-btn ' + c.btnCls; ok.textContent = c.btn;
    wrap.hidden = key !== 'decline_application';
    wrap.classList.remove('invalid');
    reason.value = note.value;
    dlg.showModal();
    (wrap.hidden ? cancel : reason).focus();
  });

  reason.addEventListener('input', function () { wrap.classList.remove('invalid'); });
  cancel.addEventListener('click', function () { if (!busy) dlg.close(); });
  dlg.addEventListener('cancel', function (e) { if (busy) e.preventDefault(); });
  dlg.addEventListener('click', function (e) { if (e.target === dlg && !busy) dlg.close(); });

  ok.addEventListener('click', function () {
    if (busy) return;
    if (action === 'decline_application') {
      if (!reason.value.trim()) { wrap.classList.add('invalid'); reason.focus(); return; }
      note.value = reason.value.trim();
    }
    busy = true;
    // Disabled buttons aren't submitted, so carry the action in a hidden input.
    var hidden = document.createElement('input');
    hidden.type = 'hidden'; hidden.name = 'action'; hidden.value = action;
    form.appendChild(hidden);
    ok.textContent = copy[action].busy;
    ok.disabled = true; cancel.disabled = true;
    form.querySelectorAll('.rv-actions .rv-btn').forEach(function (b) { b.disabled = true; });
    form.submit();
  });

  // Back button / bfcache: undo the processing state.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    busy = false;
    if (dlg.open) dlg.close();
    ok.disabled = false; cancel.disabled = false;
    form.querySelectorAll('.rv-actions .rv-btn').forEach(function (b) { b.disabled = false; });
    var h = form.querySelector('input[type="hidden"][name="action"]');
    if (h) h.remove();
  });
})();
</script>
</body>
</html>
