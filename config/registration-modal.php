<?php
/** Shows the "Complete your registration" modal on the dashboard for incomplete accounts. */
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/session.php';

$rmUser = session_user();
if ($rmUser === null) return;
$rmRole = (string)($rmUser['role'] ?? '');
if (!in_array($rmRole, ['tenant', 'landlord'], true)) return;

$rmComplete = $rmRole === 'tenant'
    ? tenant_profile_complete(database(), (int)$rmUser['id'])
    : landlord_profile_complete(database(), (int)$rmUser['id']);
if ($rmComplete) return;

// Both dashboards sit next to their own settings.php
$rmTarget = $rmRole === 'tenant'
    ? 'settings.php?edit=1&setup=1'
    : 'settings.php?mode=edit&setup=1';
?>
<style>
  dialog.rm-modal{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);margin:0;width:min(92vw,480px);padding:0;border:none;border-radius:24px;background:#fffdf9;color:#3b2820;
    box-shadow:0 28px 70px rgba(55,31,22,.28);text-align:center;font-family:'DM Sans',sans-serif}
  dialog.rm-modal::backdrop{background:rgba(52,31,23,.45);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px)}
  dialog.rm-modal[open]{animation:rm-in .28s cubic-bezier(.16,1,.3,1)}
  @keyframes rm-in{from{opacity:0;transform:translate(-50%,-46%) scale(.97)}to{opacity:1;transform:translate(-50%,-50%) scale(1)}}
  .rm-card{padding:38px 34px 30px}
  .rm-icon{display:grid;place-items:center;width:64px;height:64px;margin:0 auto 20px;border-radius:50%;background:#f7efe5;color:#7a4b3a}
  .rm-icon svg{width:31px;height:31px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
  .rm-card h2{margin:0 0 10px;font:700 30px/1.15 'Fraunces',Georgia,serif;letter-spacing:0;color:#3b2820}
  .rm-card p{margin:0 auto 26px;max-width:390px;color:#745f54;line-height:1.6;font-size:14px}
  .rm-go{display:inline-flex;align-items:center;justify-content:center;gap:10px;min-height:48px;padding:0 25px;border-radius:12px;
    background:#7a4b3a;color:#fff;font-weight:700;text-decoration:none;box-shadow:0 8px 20px rgba(122,75,58,.24);transition:all .18s ease}
  .rm-go:hover{background:#613829;transform:translateY(-1px)}
  @media(prefers-reduced-motion:reduce){dialog.rm-modal[open]{animation:none}.rm-go{transition:none}}
</style>

<dialog id="registrationModal" class="rm-modal" aria-labelledby="rmTitle" aria-describedby="rmCopy">
  <div class="rm-card">
    <div class="rm-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="m16.5 11.5 1.5 1.5 3-3"/></svg>
    </div>
    <h2 id="rmTitle">Complete your registration</h2>
    <p id="rmCopy">Before using your <?= htmlspecialchars($rmRole, ENT_QUOTES, 'UTF-8') ?> account, complete every required profile and verification field.</p>
    <a class="rm-go" href="<?= htmlspecialchars($rmTarget, ENT_QUOTES, 'UTF-8') ?>">Complete Profile Now <span aria-hidden="true">&rarr;</span></a>
  </div>
</dialog>

<script>
  (function () {
    var d = document.getElementById('registrationModal');
    if (!d || typeof d.showModal !== 'function') return;
    d.addEventListener('cancel', function (e) { e.preventDefault(); }); // Esc can't dismiss it
    d.addEventListener('click', function (e) { if (e.target === d) e.preventDefault(); });
    d.showModal();
  })();
</script>
