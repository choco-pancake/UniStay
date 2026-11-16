<?php
require_once __DIR__ . '/config/session.php';
start_app_session();
if (empty($_SESSION['landlord_application_csrf'])) {
    $_SESSION['landlord_application_csrf'] = bin2hex(random_bytes(32));
}
/**
 * Returns a local landing-page asset URL with an automatic file-version query.
 */
function landing_asset_url(string $relativePath): string
{
    $absolutePath = __DIR__ . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $modifiedAt = is_file($absolutePath) ? filemtime($absolutePath) : false;

    return $relativePath . ($modifiedAt === false ? '' : '?v=' . $modifiedAt);
}

/**
 * Reads a landing screenshot's current geometry and selects only a fresh WebP.
 * If the PNG is replaced, the page immediately falls back to that newer source.
 *
 * @return array{png:string,webp:?string,width:int,height:int,ratio:string}
 */
function landing_photo(string $number): array
{
    $pngPath = 'assets/images/' . $number . '.png';
    $webpPath = 'assets/images/optimized/' . $number . '.webp';
    $pngAbsolute = __DIR__ . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $pngPath);
    $webpAbsolute = __DIR__ . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $webpPath);

    $dimensions = is_file($pngAbsolute) ? getimagesize($pngAbsolute) : false;
    $width = is_array($dimensions) ? (int) $dimensions[0] : 1;
    $height = is_array($dimensions) ? (int) $dimensions[1] : 1;
    $pngModifiedAt = is_file($pngAbsolute) ? filemtime($pngAbsolute) : false;
    $webpModifiedAt = is_file($webpAbsolute) ? filemtime($webpAbsolute) : false;
    $useWebp = $pngModifiedAt !== false
        && $webpModifiedAt !== false
        && $webpModifiedAt >= $pngModifiedAt;

    return [
        'png' => landing_asset_url($pngPath),
        'webp' => $useWebp ? landing_asset_url($webpPath) : null,
        'width' => $width,
        'height' => $height,
        'ratio' => number_format($width / max(1, $height), 3, '.', ''),
    ];
}

$landingPhotos = [];
for ($landingPhotoIndex = 1; $landingPhotoIndex <= 7; $landingPhotoIndex++) {
    $landingPhotoNumber = str_pad((string) $landingPhotoIndex, 2, '0', STR_PAD_LEFT);
    $landingPhotos[$landingPhotoNumber] = landing_photo($landingPhotoNumber);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>UniStay · Student Housing & Dormitory Finder</title>
<link rel="icon" type="image/png" href="<?= htmlspecialchars(landing_asset_url('assets/images/tenant/logo.png'), ENT_QUOTES, 'UTF-8') ?>">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,400;9..144,700&display=swap" rel="stylesheet">
<style id="landing-tailwind">
/* landing-tailwind:start */
:root{--topbar-height:72px;--topbar-bg:#fdfaf5;--topbar-head:#6b3624}.logo{display:flex;align-items:center;gap:10px;font-family:Fraunces,Georgia,serif;font-weight:700;font-size:19px;color:var(--topbar-head)}.logo:hover{scale:1}.topbar{position:fixed;top:0;left:0;right:0;z-index:10;height:var(--topbar-height);background:var(--topbar-bg);box-shadow:0 2px 4px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:space-between;padding:0 16px;font-family:DM Sans,sans-serif}.topbar a{transition:.2s}.topbar .logo img{width:54px;height:54px}.topbar .links{position:absolute;left:50%;transform:translateX(-50%);display:flex;gap:48px;font-size:13px;font-weight:500}.topbar .actions{display:flex;gap:12px}.topbar .btn-theme-toggle{border:0!important;color:inherit}.topbar .btn-theme-toggle:hover{transform:scale(1.08)}.topbar .logo{gap:10px}.topbar .logo-name{margin-right:0}@media (max-width:900px){.topbar{padding:0 20px}.topbar .links{display:none}}.btn-theme-toggle{display:inline-flex;align-items:center;justify-content:center;width:28px!important;height:34px!important;padding:0!important;border-radius:50%;border:none!important;background:transparent!important;color:var(--ink,#3b2820);cursor:pointer;transition:all .2s cubic-bezier(.16,1,.3,1);flex-shrink:0;box-shadow:none!important}.btn-theme-toggle:hover{transform:scale(1.08);border-color:transparent!important;color:var(--brown,#7a4b3a)}.btn-theme-toggle:active{transform:scale(.96)}.btn-theme-toggle svg{display:block;width:27px!important;height:27px!important;stroke-width:2.1!important}[data-theme=dark]{color-scheme:dark;--bg:#1e1612;--cream:#251c16;--ink:#fff7f2;--text:#e2d3c8;--head:#fff7f2;--line:#4b382d;--gray:#30231c;--dark:#150f0c;--brown:#c48974;--brown-dark:#dca08b;--brown-darker:#aa6f5b;--brand-primary:#c48974;--dark-form-surface:#382a22}[data-theme=dark] body{background:var(--bg);color:var(--text)}[data-theme=dark] .shell{background:#382a22!important;border-color:#3d2d24!important}[data-theme=dark] .admin-topbar,[data-theme=dark] .bar,[data-theme=dark] .topbar,[data-theme=dark] nav.topbar{background:rgba(30,22,18,.96);border-bottom-color:var(--line);box-shadow:0 4px 18px rgba(0,0,0,.35)}[data-theme=dark] .admin-topbar h1,[data-theme=dark] .bar .logo,[data-theme=dark] .topbar h1,[data-theme=dark] .topbar h2,[data-theme=dark] h1,[data-theme=dark] h2,[data-theme=dark] h3,[data-theme=dark] h4{color:var(--head)}[data-theme=dark] .btn-theme-toggle{background:#2b1f19;border-color:#563e33;color:#fbf5f0}[data-theme=dark] .btn-theme-toggle:hover{background:#382921;border-color:var(--brown);color:var(--brown)}[data-theme=dark] .admin-sidebar,[data-theme=dark] .sidebar,[data-theme=dark] aside{background:linear-gradient(135deg,#32221b,#241813);border-right-color:#3d2d24}[data-theme=dark] .sidebar nav a,[data-theme=dark] aside nav a{color:#d9c8bc}[data-theme=dark] .sidebar nav a:hover,[data-theme=dark] aside nav a:hover{background:hsla(25,28%,79%,.08);color:#fff7f2}[data-theme=dark] .sidebar nav a.is-active,[data-theme=dark] .sidebar nav a[aria-current=page],[data-theme=dark] aside nav a.is-active,[data-theme=dark] aside nav a[aria-current=page]{background:hsla(25,28%,79%,.14);color:#d9c8bc;font-weight:700}[data-theme=dark] .admin-card,[data-theme=dark] .card,[data-theme=dark] .confirm-modal-box,[data-theme=dark] .current-booking-card,[data-theme=dark] .logout-modal-card,[data-theme=dark] .popover,[data-theme=dark] .profile-popover,[data-theme=dark] .room-card,[data-theme=dark] .rv-card,[data-theme=dark] .settings-card,[data-theme=dark] .settings-modal-card,[data-theme=dark] .stat-card,[data-theme=dark] dialog{background:#2b1f19;border-color:var(--line);color:var(--text);box-shadow:0 4px 20px rgba(0,0,0,.4)}[data-theme=dark] .dorm-card,[data-theme=dark] .dorm-card-body{background:#382a22;border-color:#4b382d;color:#d9c8bc}[data-theme=dark] .current-booking-body,[data-theme=dark] .dorm-modal-body,[data-theme=dark] .dorm-modal-header,[data-theme=dark] .landlord-profile-card-rect{background:#2b1f19;color:var(--text)}[data-theme=dark] .dorm-card-title{color:#fff7f2}[data-theme=dark] .profile-name,[data-theme=dark] .profile-switch-header,[data-theme=dark] .settings-section-title,[data-theme=dark] .stat-card-value{color:var(--head)}[data-theme=dark] .dorm-card-amenity{background:#231a14;color:#fff7f2;border:1px solid #4b382d}[data-theme=dark] .admin-user-badge,[data-theme=dark] .topbar-categories-bar{background:#2b1f19;border-color:var(--line);color:var(--text)}[data-theme=dark] .um-select-btn,[data-theme=dark] .um-select-menu,[data-theme=dark] input:not([type=checkbox]):not([type=radio]),[data-theme=dark] select,[data-theme=dark] textarea{background:#231a14!important;color:var(--ink)!important;border-color:var(--line)!important}[data-theme=dark] .um-select-menu li{color:var(--text)}[data-theme=dark] .um-select-menu li:hover,[data-theme=dark] .um-select-menu li[aria-selected=true]{background:#34261f;color:var(--ink)}[data-theme=dark] .settings-tabs{background:#241a15;border-color:var(--line)}[data-theme=dark] .settings-tab-btn{color:#bba89d}[data-theme=dark] .settings-tab-btn.is-active,[data-theme=dark] .settings-tab-btn[aria-selected=true]{color:var(--ink)}[data-theme=dark] .settings-tabs-indicator{background:var(--brown)}[data-theme=dark] .switch-slider{background-color:#4b382d}[data-theme=dark] .switch-toggle input:checked+.switch-slider{background-color:var(--brown)}[data-theme=dark] .profile-account-item{border-color:var(--line)}[data-theme=dark] .profile-account-item:hover{background:#34261f}[data-theme=dark] .profile-account-item strong{color:var(--head)}[data-theme=dark] .skeleton-card,[data-theme=dark] .skeleton-shimmer{background:linear-gradient(90deg,#2b1f19 25%,#382921 50%,#2b1f19 75%);background-size:200% 100%}[data-theme=dark] .logout-modal-backdrop,[data-theme=dark] .settings-modal-overlay,[data-theme=dark] dialog::backdrop{background:rgba(18,12,9,.82);-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px)}html[data-theme=dark] dialog:not(.onboarding-toast-modal):not(.guide-modal):not(.landlord-profile-modal):not(.us-confirm):not(.rm-modal){position:fixed;inset:0;margin:auto}html[data-theme=dark] #modalOverlay,html[data-theme=dark] .cropper-modal-overlay,html[data-theme=dark] .logout-modal-backdrop,html[data-theme=dark] .policy-modal,html[data-theme=dark] .preview-modal,html[data-theme=dark] .settings-modal-overlay{align-items:center!important;justify-content:center!important}html[data-theme=dark] #modalOverlay>.modal-card,html[data-theme=dark] .cropper-modal-card,html[data-theme=dark] .logout-modal-card,html[data-theme=dark] .policy-modal-panel,html[data-theme=dark] .preview-modal-box,html[data-theme=dark] .settings-modal-card{margin:auto}[data-theme=dark] .notification-toast{background:#32241d;border:1px solid var(--line);color:var(--ink);box-shadow:0 16px 36px rgba(0,0,0,.55)}[data-theme=dark] .notification-toast strong{color:var(--head)}[data-theme=dark] .notification-toast span{color:var(--text)}[data-theme=dark] .wrap section{background:#2b1f19;border-color:var(--line)}[data-theme=dark] .crumb{color:#ab978c}[data-theme=dark] .foot{background:#18110d;color:#e2d3c8;border-top:1px solid var(--line)}[data-theme=dark] .feat.feature-hero{background:radial-gradient(circle at 50% 20%,#2b1f19 0,#1e1612 100%)}[data-theme=dark] .sec{background:#1e1612}[data-theme=dark] .tabs{background:#281c16;border:1px solid var(--line)}[data-theme=dark] .tab{color:#bba89d}[data-theme=dark] .tab.active{color:#fff7f2}[data-theme=dark] .tab-indicator{background:var(--brown)}[data-theme=dark] .panel{background:#2b1f19;border-color:var(--line)}[data-theme=dark] .hero-wrap.photo-section{background:#1e1612}[data-theme=dark] .hero-copy{color:var(--text)}[data-theme=dark] .hero-copy h2{color:var(--head)}[data-theme=dark] .sq{background:#2b1f19;border-color:var(--line)}[data-theme=dark] .sq-badge{background:rgba(30,22,18,.85);color:var(--ink);border-color:var(--line)}[data-theme=dark] .sq-num{color:var(--head)}[data-theme=dark] .apply-dialog{background:#2b1f19;border-color:var(--line);color:var(--text)}[data-theme=dark] .apply-ws-left{background:linear-gradient(135deg,#32221b,#241813);border-right-color:var(--line)}[data-theme=dark] .apply-ws-right{background:#2b1f19}[data-theme=dark] .la-label{color:#d1b8ab}[data-theme=dark] .la-input{background:#231a14!important;border-color:var(--line)!important;color:var(--ink)!important}[data-theme=dark] body{background-color:#1e1612}[data-theme=dark] .stage{background:#1e1612}[data-theme=dark] .card{background:#2b1f19;border-color:#563e33;box-shadow:0 16px 40px rgba(0,0,0,.45)}[data-theme=dark] .form-side{background:#2b1f19;color:#fbf5f0}[data-theme=dark] .form-side h2,[data-theme=dark] .form-side h3{color:#fbf5f0}[data-theme=dark] .field label,[data-theme=dark] .form-side .hint,[data-theme=dark] .tagline{color:#c5a898}[data-theme=dark] .control input,[data-theme=dark] .control select{background:#231914;border-color:#563e33;color:#d9c8bc}[data-theme=dark] .control input:focus,[data-theme=dark] .control select:focus{background:#281d17;border-color:var(--brown,#b85d34);color:#d9c8bc}[data-theme=dark] .control input::-moz-placeholder{color:#8a6c5d}[data-theme=dark] .control input::placeholder{color:#8a6c5d}[data-theme=dark] .control .lead-icon{stroke:#c5a898}[data-theme=dark] .btn-google{background:#231914;border-color:#563e33;color:#fbf5f0}[data-theme=dark] .btn-google:hover{background:#2f211a;border-color:#7a4b3a}[data-theme=dark] .actions--full .or:after,[data-theme=dark] .actions--full .or:before{background:#563e33}[data-theme=dark] .actions--full .or{color:#c5a898}[data-theme=dark] .back-link,[data-theme=dark] .btn-resend-link,[data-theme=dark] .forgot,[data-theme=dark] .form-footer a,[data-theme=dark] .resend-alt-link{color:#e2a07f}[data-theme=dark] .readonly-email-box{background-color:#231914!important;color:#c5a898!important;border-color:#563e33!important}:root{--bg:#fdfaf5;--brown:#7b4a3a;--head:#6b3624;--text:#8b5a4a;--gray:#d9d9d9;--dark:#3e2723;--line:#e8ddd3;--brown-dark:#5c3527;--brown-darker:#452618}html{scroll-behavior:smooth;scrollbar-color:var(--brown) transparent;scrollbar-width:thin}::-webkit-scrollbar{width:12px}::-webkit-scrollbar-track{background:transparent!important}::-webkit-scrollbar-thumb{background:var(--brown);border:3px solid #f3e9df;border-radius:999px}::-webkit-scrollbar-thumb:hover{background:var(--brown-dark)}*{box-sizing:border-box;margin:0;padding:0}body{font-family:DM Sans,sans-serif;background:var(--bg);color:var(--text);overflow-x:hidden}#features,#home,#roles{scroll-margin-top:88px}a{color:inherit;text-decoration:none;font-size:15px;transition:.2s}a:hover{scale:1.1}h1,h2{font-family:Fraunces,Georgia,serif;font-weight:700;color:var(--head);line-height:1.02;letter-spacing:-.5px}.page{position:relative;overflow:hidden}.blob{position:absolute;border-radius:50%;filter:blur(50px);pointer-events:none}.btn{font:500 13px DM Sans,sans-serif;padding:0 20px;height:40px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--brown);cursor:pointer;display:inline-flex;align-items:center;box-shadow:0 1px 3px rgba(0,0,0,.12);transition:.2s}.btn.primary{border-color:var(--brown)}.btn.primary,.loginbtn{background:var(--brown);color:#fff}.loginbtn{font:500 13px DM Sans,sans-serif;padding:0 20px;height:40px;border-radius:8px;border:1px solid var(--line);cursor:pointer;display:inline-flex;align-items:center;box-shadow:0 1px 3px rgba(0,0,0,.12);transition:.2s;border-color:var(--brown)}.loginbtn:hover{color:#fff;background:var(--brown-dark);border-color:var(--brown-dark)}.loginbtn:active{color:#fff;background:var(--brown-darker);border-color:var(--brown-darker)}.btn:hover{filter:brightness(.95)}.btn.primary:hover{color:#fff;background:var(--brown-dark);border-color:var(--brown-dark)}.btn.primary:active{color:#fff;background:var(--brown-darker);border-color:var(--brown-darker)}#sub-btn{transition:.2s}#sub-btn:not(:disabled):hover{color:#fff;background:var(--brown);border-color:var(--black);scale:1.05}#sub-btn:not(:disabled):active{background:var(--brown-darker);scale:1.02}#sub-btn:disabled,.news button:disabled{opacity:.65;cursor:not-allowed!important;scale:1!important;transform:none!important;background:#f0e6de!important;color:#8c766b!important;box-shadow:none!important;pointer-events:none!important}.hero-wrap{height:100svh;min-height:640px;display:flex;align-items:center;justify-content:center;overflow:hidden;padding-top:72px;box-sizing:border-box}.hero,.hero-wrap{position:relative}.hero{width:min(1380px,100%);height:min(600px,calc(100svh - 72px));flex:0 0 auto;transform:scale(1.08);transform-origin:center center}@media (max-width:1449px){.hero{transform:scale(.95)}}@media (min-width:1500px) and (max-width:1600px){.hero{transform:scale(1)}.sec{padding:40px 24px 0}.sec h2{margin:0 auto 10px}.tabs{margin-top:16px}.panel{margin:16px auto 0}}@media (max-width:1199px){.hero{transform:scale(.8)}}@media (max-width:999px){.hero{transform:scale(.7)}}.hero-copy{position:absolute;left:134px;top:120px;width:470px;z-index:2;opacity:1;transition:opacity .36s ease}.hero-copy.is-resetting{opacity:0;pointer-events:none}.hero-copy .swap{transition:opacity .2s ease,transform .2s ease}.hero-copy.is-swapping .swap{opacity:0;transform:translateY(10px)}.hero-kicker{display:block;margin-bottom:14px;font:700 12px DM Sans,sans-serif;letter-spacing:1.2px;text-transform:uppercase;color:var(--brown)}.hero-copy h1{font-size:48px;min-height:2.04em}.hero-copy p{max-width:400px;min-height:70px;margin:18px 0 22px;font-size:15px;line-height:23.5px}.hero .btn{height:40px;font-weight:700;box-shadow:none}.hero-progress{display:flex;align-items:center;gap:14px;margin-top:28px;font:600 13px DM Sans,sans-serif;color:var(--text)}.hero-count{min-width:52px;color:var(--brown);letter-spacing:.5px}.hero-bars{display:inline-flex;gap:4px}.hero-bars button{position:relative;width:16px;height:18px;padding:0;border:0;background:transparent;cursor:pointer}.hero-bars button:before{content:"";position:absolute;left:0;right:0;top:7px;height:4px;border-radius:999px;background:var(--line);transition:background .3s ease,transform .2s ease}.hero-bars button.on:before{background:var(--brown)}.hero-bars button:hover:before{transform:scaleY(1.75)}.hero-hint{font-weight:500;font-size:12px;opacity:.8}.stack{left:720px;top:130px;width:440px;height:360px;z-index:1}.sq,.stack{position:absolute}.sq{--d:0;inset:0;border-radius:36px;overflow:hidden;background:#efe8e1;border:1px solid hsla(0,0%,100%,.5);box-shadow:0 24px 50px rgba(92,62,48,.22);cursor:pointer;-webkit-user-select:none;-moz-user-select:none;user-select:none;transform-origin:50% 100%;transform:translate(calc(var(--d)*16px),calc(var(--d)*-12px)) scale(calc(1 - var(--d)*.035)) rotate(calc(var(--d)*1.2deg));transition:transform .55s cubic-bezier(.22,1,.36,1),opacity .4s ease,box-shadow .3s ease}.stack:not(.is-dealt) .sq{transform:translateY(-520px) rotate(-8deg) scale(.92);opacity:0}.stack.is-dealing .sq{transition-duration:.8s,.5s,.3s;transition-delay:calc((8 - var(--n))*90ms)}.stack.no-anim .sq{transition:none!important}.sq.is-gone{transform:translate(520px,-60px) rotate(18deg) scale(.92);opacity:0;pointer-events:none}.sq.is-top:hover{box-shadow:0 30px 60px rgba(92,62,48,.32);border-color:hsla(0,0%,100%,.9)}.sq.is-top:focus-visible{outline:3px solid var(--brown);outline-offset:4px}.sq-img{position:absolute;inset:0;background-size:cover;background-position:50%;transition:transform .4s ease}.sq.is-top:hover .sq-img{transform:scale(1.04)}.sq-overlay{position:absolute;inset:0;background:linear-gradient(180deg,rgba(35,18,12,.28),rgba(35,18,12,0) 35%);pointer-events:none}.sq-badge{position:absolute;top:16px;left:16px;z-index:2;display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:999px;background:hsla(38,67%,98%,.94);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border:1px solid hsla(0,0%,100%,.6);box-shadow:0 4px 12px rgba(0,0,0,.16);font:700 11px DM Sans,sans-serif;color:var(--brown);white-space:nowrap}.sq-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#2e7d32;box-shadow:0 0 6px rgba(46,125,50,.7)}.sq-num{position:absolute;right:18px;bottom:14px;z-index:2;font:700 28px Fraunces,Georgia,serif;color:#fff;text-shadow:0 2px 10px rgba(0,0,0,.55);pointer-events:none}@media (prefers-reduced-motion:reduce){.hero-copy,.hero-copy .swap,.sq{transition:none!important}}.sec{position:relative;text-align:center;padding:120px 24px 0}.sec h2{font-size:52px;max-width:680px;margin:0 auto 16px}.sec .desc{font-size:16px;line-height:25px;max-width:560px;margin:0 auto}.tabs{position:relative;display:inline-flex;gap:4px;margin-top:26px;padding:9px;border-radius:11px;background:#efebe7}.tab-indicator{position:absolute;top:9px;left:0;height:38px;border-radius:9px;background:var(--brown);z-index:0;transition:transform .35s cubic-bezier(.4,0,.2,1),width .35s cubic-bezier(.4,0,.2,1)}.tab{position:relative;z-index:1;display:flex;align-items:center;gap:8px;height:38px;padding:0 12px;border:0;border-radius:9px;color:var(--brown);font:500 13px DM Sans,sans-serif;cursor:pointer;transition:color .3s ease}.tab,.tab.active{background:transparent}.tab.active{color:#fff}.panel{max-width:1080px;margin:26px auto 0;border-radius:52px;background:var(--gray);overflow:hidden;box-shadow:0 18px 40px rgba(92,62,48,.18);transition:opacity .25s ease,transform .25s ease,height .4s cubic-bezier(.4,0,.2,1)}.panel.is-out{opacity:0;transform:translateY(12px) scale(.985)}.panel.is-in{opacity:0;transform:translateY(-12px) scale(.985)}.panel img{display:block;width:100%;height:auto}.panel[hidden]{display:none}.feat{position:relative;isolation:isolate;min-height:600px;margin-top:140px;padding:120px 24px;display:flex;align-items:center;justify-content:center;text-align:center;overflow:hidden;background:linear-gradient(180deg,hsla(37,76%,93%,0),#f8ecdd 50%,hsla(37,76%,93%,0))}.feat:before{content:"";z-index:-2;background-image:radial-gradient(rgba(123,74,58,.22) 1.2px,transparent 1.4px);background-size:26px 26px;-webkit-mask-image:radial-gradient(ellipse at center,#000 20%,transparent 72%);mask-image:radial-gradient(ellipse at center,#000 20%,transparent 72%);animation:featGrid 20s linear infinite}.feat-bg,.feat:before{position:absolute;inset:0}.feat-bg{z-index:-1;pointer-events:none}.feat-copy{position:relative;max-width:640px}.feat-kicker{display:inline-block;margin-bottom:14px;padding:6px 14px;border-radius:999px;background:hsla(0,0%,100%,.7);border:1px solid var(--line);font:700 12px DM Sans,sans-serif;letter-spacing:1.2px;text-transform:uppercase;color:var(--brown)}.feat-copy h2{font-size:56px;margin-bottom:18px}.feat-copy p{font-size:17px;line-height:27px;max-width:560px;margin:0 auto}.feat-ring{position:absolute;left:50%;top:50%;width:760px;height:760px;margin:-380px 0 0 -380px;border-radius:50%;border:1.5px solid rgba(123,74,58,.3);opacity:0;animation:featRipple 9s ease-out infinite}.feat-ring.r2{animation-delay:3s}.feat-ring.r3{animation-delay:6s}.feat-orb{position:absolute;border-radius:50%;filter:blur(60px);opacity:.8}.feat-orb.o1{left:8%;top:10%;width:340px;height:340px;background:#fbe3c2;animation:featDrift 14s ease-in-out infinite alternate}.feat-orb.o2{right:6%;top:28%;width:300px;height:300px;background:#fdd9c8;animation:featDrift 17s ease-in-out -6s infinite alternate-reverse}.feat-orb.o3{left:40%;bottom:-8%;width:320px;height:260px;background:#f4e1c8;animation:featDrift 19s ease-in-out -3s infinite alternate}.feat-chip{position:absolute;display:inline-flex;align-items:center;gap:10px;padding:10px 18px 10px 12px;border-radius:999px;background:hsla(0,0%,100%,.86);border:1px solid var(--line);box-shadow:0 12px 28px rgba(92,62,48,.14);font:600 14px DM Sans,sans-serif;color:var(--head);white-space:nowrap;backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);animation:featFloat 7s ease-in-out infinite alternate}.feat-chip svg{box-sizing:content-box;width:18px;height:18px;padding:7px;border-radius:50%;background:#f6e7dc;color:var(--brown)}.feat-chip.c1{left:5%;top:18%;--dx:18px;--dy:-22px;--r:-2deg;animation-duration:7s}.feat-chip.c2{left:13%;top:68%;--dx:-14px;--dy:20px;--r:2deg;animation-duration:8.5s;animation-delay:-2s}.feat-chip.c3{left:2%;top:43%;--dx:22px;--dy:14px;--r:-1.5deg;animation-duration:9s;animation-delay:-4s}.feat-chip.c4{right:5%;top:22%;--dx:-18px;--dy:-18px;--r:2deg;animation-duration:8s;animation-delay:-1s}.feat-chip.c5{right:10%;top:70%;--dx:16px;--dy:22px;--r:-2deg;animation-duration:7.5s;animation-delay:-3s}.feat-chip.c6{right:2%;top:46%;--dx:-22px;--dy:12px;--r:1.5deg;animation-duration:9.5s;animation-delay:-5s}@keyframes featRipple{0%{transform:scale(.25);opacity:0}15%{opacity:.7}to{transform:scale(1.5);opacity:0}}@keyframes featDrift{0%{transform:translate(0) scale(1)}to{transform:translate(60px,-40px) scale(1.15)}}@keyframes featFloat{0%{transform:translate(0) rotate(0deg)}to{transform:translate(var(--dx),var(--dy)) rotate(var(--r))}}@keyframes featGrid{0%{background-position:0 0}to{background-position:26px 26px}}@media (max-width:1100px){.feat-chip{display:none}}@media (prefers-reduced-motion:reduce){.feat-chip,.feat-orb,.feat-ring,.feat:before{animation:none}.feat-ring{opacity:.25;transform:scale(.8)}}footer{background:var(--dark);color:#f3e3d8;padding:32px 16px 28px}.f{max-width:1120px;margin:0 auto;display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:32px}.f .logo{color:#fff;margin-bottom:22px;justify-content:flex-start;align-items:center}.f>div{text-align:left}.f p{font-size:15px;line-height:23.5px;width:330px;max-width:100%;margin-left:0}.f h4{font:500 15px DM Sans,sans-serif;letter-spacing:.2px;margin-bottom:12px;color:#fff;text-transform:uppercase}.f li{list-style:none;font-size:15px;line-height:28px}.f li a{color:#f3e3d8;text-decoration:none!important;display:inline-block;transition:color .18s ease,font-weight .18s ease}.f li a:focus,.f li a:hover{color:#caa691}.f li a:active,.f li a:focus,.f li a:hover{text-decoration:none!important;font-weight:700;scale:1}.f li a:active{color:#b08972}.news{margin-top:22px}.news b{display:block;font-size:15px;color:#fff;margin-bottom:14px}.news form{display:flex;gap:12px}.news input{width:250px;height:40px;padding:0 12px;background:transparent;border:1px solid #8a6f66;border-radius:6px;color:#fff;font-family:DM Sans,sans-serif;font-size:14px;font-weight:400}.news input::-moz-placeholder{color:#a58d84;font-size:14px;font-family:DM Sans,sans-serif}.news input::placeholder{color:#a58d84;font-size:14px;font-family:DM Sans,sans-serif}.news input:focus{outline:none;border-color:#f3e3d8}.news-msg{display:block;margin-top:10px;min-height:18px;font-size:13px;line-height:18px}.news-msg.error{color:#f0b4a6}.news-msg.success{color:#bfe3c4}.news button:disabled{opacity:.65;cursor:not-allowed!important;scale:1!important;transform:none!important;background:#f0e6de!important;color:#8c766b!important;pointer-events:none!important}.news button{height:36px;padding:0 12px;border:0;border-radius:6px;background:#fff;color:var(--brown);font:700 12px DM Sans,sans-serif;cursor:pointer}.copy{max-width:1120px;margin:34px auto 0;padding-top:28px;border-top:1px solid #6b5148;font-size:15px}@media (max-width:900px){.topbar .actions .btn:not(.primary){display:none}.hero-wrap{height:auto;min-height:0;display:block;padding-top:72px}.hero{width:100%;height:auto;padding:48px 16px;display:flex;flex-direction:column;gap:20px;transform:none}.hero>*{position:static!important}.hero-copy{width:auto}.hero-copy h1{font-size:36px;min-height:0}.hero-copy p{min-height:0}.hero>.stack{position:relative!important;width:calc(100% - 48px);height:280px;margin-top:64px}.sq{--step:1;transform:translate(calc(var(--d)*6px),calc(var(--d)*-8px)) scale(calc(1 - var(--d)*.03)) rotate(calc(var(--d)*1deg))}.feat{min-height:0;margin-top:80px;padding:90px 20px}.feat-copy h2{font-size:38px}.feat-ring{width:460px;height:460px;margin:-230px 0 0 -230px}.f{grid-template-columns:1fr 1fr}}.feature-hero{min-height:100svh;margin-top:0;padding:112px 24px 72px;box-sizing:border-box}.feature-hero .feat-copy{z-index:1;width:min(760px,100%);max-width:760px}.feature-hero .feat-copy h1{margin:0 0 18px;font-family:Fraunces,Georgia,serif;font-size:clamp(48px,4.8vw,70px);line-height:1.03;color:var(--head)}.feature-hero .feat-copy p{max-width:600px}.feature-hero .feat-chip{--x:0px;--y:0px;--scatter-delay:0ms;left:50%;right:auto;top:50%;width:auto;height:auto;padding:0;border:0;border-radius:50%;background:transparent;box-shadow:none;opacity:0;animation:none;transform:translate(-50%,-50%) scale(.12);will-change:transform,opacity}.feature-hero.icons-revealed .feat-chip{animation:featScatter 1.15s cubic-bezier(.16,1,.3,1) var(--scatter-delay) both}.feat-icon-float{display:grid;place-items:center;width:64px;height:64px;border:1px solid rgba(123,74,58,.2);border-radius:50%;background:hsla(0,0%,100%,.9);box-shadow:0 14px 32px rgba(92,62,48,.16);color:var(--brown);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px)}.icons-revealed .feat-icon-float{animation:featIconFloat 5.8s ease-in-out calc(1.2s + var(--scatter-delay)) infinite alternate}.feature-hero .feat-chip svg{width:30px;height:30px;padding:0;border-radius:0;background:transparent}.feature-hero .feat-chip.c1{--x:calc(min(40vw, 520px)*-1);--y:calc(min(28vh, 210px)*-1);--scatter-delay:0ms}.feature-hero .feat-chip.c2{--x:calc(min(34vw, 440px)*-1);--y:min(26vh,190px);--scatter-delay:70ms}.feature-hero .feat-chip.c3{--x:calc(min(44vw, 570px)*-1);--y:2vh;--scatter-delay:140ms}.feature-hero .feat-chip.c4{--x:min(39vw,510px);--y:calc(min(25vh, 185px)*-1);--scatter-delay:210ms}.feature-hero .feat-chip.c5{--x:min(32vw,420px);--y:min(27vh,200px);--scatter-delay:280ms}.feature-hero .feat-chip.c6{--x:min(44vw,575px);--y:4vh;--scatter-delay:350ms}@keyframes featScatter{0%{opacity:0;transform:translate(-50%,-50%) scale(.12) rotate(-14deg)}28%{opacity:1}to{opacity:1;transform:translate(-50%,-50%) translate(var(--x),var(--y)) scale(1) rotate(0)}}@keyframes featIconFloat{0%{transform:translateZ(0) rotate(-1.5deg)}to{transform:translate3d(0,-10px,0) rotate(2deg)}}.photo-section{height:720px;min-height:0;margin:82px 0 110px;padding-top:0}.photo-section .hero{height:620px}.photo-section .hero-copy h2{min-height:2.04em;font-family:Fraunces,Georgia,serif;font-size:48px;line-height:1.08;color:var(--head)}.photo-section .stack{left:700px;top:92px;width:540px;height:440px}.photo-section .sq{left:50%;top:50%;right:auto;bottom:auto;width:min(500px,calc(390px*var(--ratio)));height:auto;aspect-ratio:var(--ratio);transform:translate(-50%,-50%) translate(calc(var(--d)*16px),calc(var(--d)*-12px)) scale(calc(1 - var(--d)*.035)) rotate(calc(var(--d)*1.2deg));transition:transform .72s cubic-bezier(.16,1,.3,1),opacity .5s ease,box-shadow .3s ease;will-change:transform,opacity}.photo-section .stack:not(.is-dealt) .sq{transform:translate(-50%,-50%) translateY(-480px) rotate(-6deg) scale(.94)}.photo-section .sq.is-gone{transform:translate(-50%,-50%) translate(570px,-44px) rotate(14deg) scale(.94)}.photo-section .sq-img,.photo-section .sq-img img{position:absolute;inset:0;display:block;width:100%;height:100%}.photo-section .sq-img img{-o-object-fit:cover;object-fit:cover}.photo-section .sq.is-top:hover .sq-img{transform:none}.photo-section .sq.is-top:hover .sq-img img{transform:scale(1.025)}.photo-section .sq-img img{transition:transform .5s cubic-bezier(.22,1,.36,1)}@media (max-width:1100px){.feature-hero .feat-chip{display:inline-flex}.photo-section .hero{transform:scale(.82)}}@media (max-width:900px){.feature-hero{min-height:720px;margin-top:0;padding:120px 20px 90px}.feature-hero .feat-copy h1{font-size:clamp(38px,10vw,44px)}.feature-hero .feat-copy{width:calc(100vw - 40px);min-width:0;max-width:calc(100vw - 40px)}.feature-hero .feat-copy p{width:100%;max-width:100%;font-size:15px;line-height:24px}.feat-icon-float{width:52px;height:52px}.feature-hero .feat-chip svg{width:25px;height:25px}.feature-hero .feat-chip.c1{--x:-30vw;--y:-210px}.feature-hero .feat-chip.c2{--x:-31vw;--y:205px}.feature-hero .feat-chip.c3{--x:-37vw;--y:24px}.feature-hero .feat-chip.c4{--x:30vw;--y:-190px}.feature-hero .feat-chip.c5{--x:25vw;--y:210px}.feature-hero .feat-chip.c6{--x:37vw;--y:24px}.photo-section{height:auto;min-height:690px;margin:70px 0 90px;padding-top:0}.photo-section .hero{min-height:690px;padding:48px 20px 26px;transform:none}.photo-section .hero-copy h2{min-height:0;font-size:38px}.photo-section .hero>.stack{width:100%;height:330px;margin-top:42px}.photo-section .sq{width:min(calc(100% - 32px),calc(280px*var(--ratio)));height:auto}.hero-progress{flex-wrap:wrap}}@media (prefers-reduced-motion:reduce){.feature-hero .feat-chip,.feature-hero.icons-revealed .feat-chip{opacity:1;animation:none;transform:translate(-50%,-50%) translate(var(--x),var(--y))}.icons-revealed .feat-icon-float,.photo-section .sq-img img{animation:none;transition:none}}.feat-brand{display:inline-flex;align-items:center;gap:10px;margin-bottom:14px;font-family:Fraunces,Georgia,serif;font-weight:700;font-size:22px;color:#6b3624}.feat-brand img{width:44px;height:44px;display:block}@media (prefers-reduced-motion:reduce){.panel{transition:none!important}}.feat-actions{display:flex;justify-content:center;flex-wrap:wrap;gap:12px;margin-top:28px}.feat-actions .btn{height:44px;padding:0 26px;font-size:14px;font-weight:700}.landlord-application-cta{transform:scale(1);transform-origin:center;transition:transform .18s ease,background-color .18s ease,box-shadow .18s ease}.landlord-application-cta:focus-visible,.landlord-application-cta:hover{transform:scale(1.08);box-shadow:0 8px 22px rgba(122,75,58,.2)}.landlord-application-cta:active{transform:scale(1.12)}.apply-dialog{position:fixed;inset:0;width:min(calc(100vw - 48px),1125px);height:min(calc(100vh - 40px),780px);max-height:min(calc(100vh - 40px),780px);margin:auto;padding:0;border:1px solid #ebd9cb;border-radius:24px;background:#ffffff;color:#2b170e;box-shadow:0 24px 60px rgba(74,44,32,.22);font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;overflow:hidden;opacity:0;transform:translateY(14px) scale(.97);transition:opacity .2s ease,transform .22s cubic-bezier(.22,1,.36,1);display:flex;flex-direction:column;box-sizing:border-box}dialog.apply-dialog:not([open]){display:none!important;pointer-events:none!important}.apply-dialog::backdrop{background:rgba(52,31,23,0);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px);transition:background-color .2s ease}.apply-dialog[open].is-open{opacity:1;transform:translateY(0) scale(1)}.apply-dialog[open].is-open::backdrop{background:rgba(52,31,23,.45)}body.landlord-dialog-open{overflow:hidden}body.landlord-dialog-open .feat-chip,body.landlord-dialog-open .feat-icon-float,body.landlord-dialog-open .feat-orb,body.landlord-dialog-open .feat-ring,body.landlord-dialog-open .feat:before{animation-play-state:paused!important}#landlord-form{display:flex;flex-direction:column;height:100%;min-height:0;margin:0;position:relative}.apply-columns{display:grid;grid-template-columns:529fr 596fr;gap:0;align-items:stretch;flex:1;min-height:0}.apply-col{display:flex;flex-direction:column;min-height:0}.apply-ws-subtitle{color:#e9b8a3;font-size:16px;font-weight:700;text-transform:none;letter-spacing:normal;margin:0 0 12px}.apply-ws-left{background:linear-gradient(135deg,#32221b,#241813);padding:32px 48px;color:#fff;display:flex;flex-direction:column;justify-content:flex-start;gap:0;border-right:1px solid #3d2d24}.apply-ws-title{font:700 32px/1.1 Fraunces,Georgia,Times New Roman,serif;letter-spacing:-.5px;color:#fff;margin:0 0 16px}.apply-left-fields{display:flex;flex-direction:column;gap:8px}.apply-ws-left .la-field{display:flex;flex-direction:column;gap:3px}.apply-ws-left .la-label{color:#fff!important;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.35px}.apply-ws-left .la-input{background:#fff!important;border:1.5px solid #fff!important;color:#2b170e!important;height:34px;padding:0 10px;border-radius:8px;font-size:.86rem;font-weight:500;box-shadow:0 2px 5px rgba(0,0,0,.08)}.apply-ws-left .la-input:focus{outline:none;border-color:#fff!important;box-shadow:0 0 0 3px hsla(0,0%,100%,.45),0 3px 8px rgba(0,0,0,.12)!important}.apply-ws-left .la-prefix-wrapper{background:#fff!important;border:1.5px solid #fff!important;border-radius:8px;height:34px;box-shadow:0 2px 5px rgba(0,0,0,.08)}.apply-ws-left .la-prefix-addon{background:#fbf6f2;color:#5a3d31;font-weight:700;font-size:.8rem;padding:0 10px;border-right:1px solid #ebd9cb}.apply-ws-left .la-prefix-wrapper .la-input{height:100%;border:none!important;box-shadow:none!important}.apply-ws-right{background:radial-gradient(55% 55% at 78% 8%, rgba(176,117,92,.85) 0, rgba(176,117,92,0) 100%), radial-gradient(60% 55% at 12% 100%, rgba(166,107,82,.9) 0, rgba(166,107,82,0) 100%), #7a4b3a;padding:32px 38px 24px;display:flex;flex-direction:column}.apply-right-header{margin-bottom:18px}.apply-right-title{font:700 32px/1.2 DM Sans,Segoe UI,system-ui,sans-serif;letter-spacing:-.8px;color:#fff7f2;margin:0}.apply-close{position:absolute;top:16px;right:20px;z-index:3;width:32px;height:32px;border-radius:0;border:none;background:none;color:#e2ab94;font-size:26px;line-height:1;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;padding:0;box-shadow:none;transition:color .15s ease,transform .15s ease}.apply-close:hover{background:none;color:#efc2ae;transform:scale(1.1)}.apply-close:active{background:none;color:#cf967f;transform:scale(.92)}.apply-right-sub{font-size:11.5px;color:#a8948a;display:block;margin-top:3px}.apply-right-fields{display:grid;grid-template-rows:repeat(2,minmax(0,1fr));gap:12px;flex:1;min-height:0}.apply-ws-right .la-field{display:flex;flex-direction:column;gap:4px;min-height:0}.apply-ws-right .la-label{color:#d9c8bc;font-size:11.5px;font-weight:600}.apply-ws-right .la-textarea{height:auto;min-height:0;max-height:none;flex:1;resize:none;padding:8px 10px;font-size:.85rem;line-height:1.4;border:1px solid #5c4639;background:#231a14;color:#fff7f2;border-radius:8px}.apply-ws-right .la-dropzone{height:auto;min-height:0;flex:1;padding:6px 10px;border:2px dashed #5c4639;background:#231a14;border-radius:10px}.apply-ws-right .la-dz-icon{width:20px;height:20px;margin:0 auto 2px}.apply-ws-right .la-dz-filename{font-size:.78rem;margin-bottom:1px}.apply-ws-right .la-dz-hint{font-size:.7rem;margin-bottom:4px}.apply-ws-right .la-dz-btn{padding:4px 12px;font-size:.75rem;border-radius:6px}.apply-ws-right .la-hint{font-size:.72rem;color:#a8948a;margin-top:2px}.apply-agreement{display:flex;gap:8px;padding:8px 10px;border-radius:8px;font-size:11.5px;cursor:pointer}.apply-agreement input{margin-top:2px;accent-color:#7a4b3a}.apply-modal-footer{display:flex;align-items:center;justify-content:flex-end;min-height:0;padding:12px 0 0;border:0;background:transparent;flex-shrink:0}.apply-modal-footer .apply-actions{display:flex;align-items:center;gap:10px;margin:0}.apply-modal-footer .apply-actions .btn{height:38px;padding:0 20px;font-size:.88rem;font-weight:700;border-radius:9px}.apply-modal-footer .btn-apply-cancel{background:transparent;color:#f3d2c3;border:1px solid #f3d2c3;font-weight:600;cursor:pointer;transition:all .18s ease}.apply-modal-footer .btn-apply-cancel:hover{background:hsla(18,57%,73%,.16);color:#fff}#landlord-form #apply-btn{background:#e2ab94;border-color:#e2ab94;color:#1e1612}#landlord-form #apply-btn:hover:not(:disabled){background:#efc2ae;border-color:#efc2ae;color:#1e1612}.apply-section{display:flex;flex-direction:column;gap:6px}.apply-section-title{font-size:.8rem;font-weight:700;color:#7a4b3a;text-transform:uppercase;letter-spacing:.04em;margin:0;padding-bottom:2px;border-bottom:1px solid #ebd9cb}.apply-grid,.apply-grid-contact,.apply-grid-personal{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px 12px}.apply-grid-verification{display:grid;grid-template-columns:1fr 1fr;gap:8px 14px}.apply-grid-personal .full{grid-column:1/-1}.apply-grid label{display:grid;gap:4px;font:600 11.5px DM Sans,sans-serif;color:var(--head);text-align:left}.apply-grid .full{grid-column:1/-1}.apply-address,.apply-experience{grid-column:auto}.apply-grid input,.apply-grid textarea{width:100%;padding:0 12px;border:1px solid #d9bea6;border-radius:10px;background:#fff;font:400 .9rem DM Sans,sans-serif;color:var(--dark);transition:border-color .18s ease,box-shadow .18s ease}.apply-grid input:not([type=checkbox]):not([type=file]){height:38px}.apply-grid textarea{min-height:72px;height:72px;padding-top:8px;padding-bottom:8px;resize:none}.apply-grid input:focus,.apply-grid textarea:focus{outline:none;border-color:#7a4b3a;box-shadow:0 0 0 3px rgba(122,75,58,.15)}.apply-upload-box{display:grid;gap:7px;padding:10px 12px;border:1px dashed #d9bea6;border-radius:10px;background:#fff;min-height:72px;align-content:center}.apply-upload-box input[type=file]{height:auto;padding:0;border:0;border-radius:0;background:transparent;font-size:13px}.apply-upload-box span{color:#8b7467;font-weight:500}.apply-agreement{display:flex!important;grid-template-columns:none;flex-direction:row;align-items:flex-start;gap:8px!important;padding:8px 12px;border:1px solid #5c4639;border-radius:10px;background:#231a14;color:#d9c8bc;font-size:12px;font-weight:500!important;line-height:1.55}.apply-agreement input[type=checkbox]{width:16px;height:16px;flex:0 0 auto;margin-top:1px;padding:0;accent-color:var(--brown)}.apply-hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0}.apply-msg{min-height:14px;margin-top:6px;font-size:12px}.apply-msg.error{color:#a33d2d}.apply-msg.success{color:#28733a}.apply-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:8px}.apply-actions .btn{height:38px;padding:0 20px;font-size:.88rem;font-weight:700}.apply-actions .btn:disabled,.apply-actions .btn:disabled:active,.apply-actions .btn:disabled:hover{pointer-events:none;cursor:default;filter:none;color:#fff;background:var(--brown);border-color:var(--brown)}@media (prefers-reduced-motion:reduce){.apply-dialog,.apply-dialog::backdrop{transition:none}}@media (max-width:520px){.apply-dialog{padding:22px 18px}.apply-grid{grid-template-columns:1fr}}@media (max-height:699px),(max-width:899px){.apply-dialog{width:min(760px,calc(100vw - 24px));max-height:calc(100dvh - 24px);height:auto;padding:0;border-radius:24px;overflow-y:auto;overflow-x:hidden;scrollbar-width:auto;scrollbar-color:#b7aaa2 transparent}.apply-dialog::-webkit-scrollbar{width:10px}.apply-dialog::-webkit-scrollbar-track{background:transparent!important}.apply-dialog::-webkit-scrollbar-thumb{background:#b7aaa2;border:3px solid #fffaf4;border-radius:999px}.apply-columns{grid-template-columns:1fr;gap:0}.apply-ws-left{padding:32px 36px;border-right:0}.apply-ws-right{padding:36px}.apply-right-fields{display:flex;flex-direction:column}.apply-grid,.apply-grid-contact,.apply-grid-personal,.apply-grid-verification{grid-template-columns:1fr}.apply-address,.apply-experience,.apply-upload{grid-column:1/-1}.apply-modal-footer{min-height:0;padding:12px 0 0}.apply-modal-footer .apply-actions{justify-content:stretch}.apply-modal-footer .apply-actions .btn{flex:1}}@media (max-width:560px){.apply-grid{grid-template-columns:1fr}.apply-address,.apply-experience,.apply-grid>label,.apply-upload{grid-column:1}}.notification-toast{position:fixed;z-index:100000;right:24px;bottom:24px;display:flex;flex-direction:column;justify-content:center;gap:8px;width:min(400px,calc(100vw - 32px));min-height:108px;box-sizing:border-box;padding:16px 20px;border:none;border-radius:16px;background:#ecdccb;color:#3b2820;box-shadow:0 16px 36px rgba(56,34,24,.18),0 4px 12px rgba(56,34,24,.08);opacity:0;transform:translateY(24px) scale(.96);pointer-events:none;font-family:DM Sans,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;transition:opacity .35s cubic-bezier(.16,1,.3,1),transform .35s cubic-bezier(.16,1,.3,1)}.notification-toast.is-visible{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}.notification-toast.is-hiding{opacity:0;transform:translateY(20px) scale(.95);pointer-events:none;transition:opacity .28s cubic-bezier(.4,0,1,1),transform .28s cubic-bezier(.4,0,1,1)}.notification-toast .toast-header{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%}.notification-toast strong{font-size:15px;font-weight:700;color:#3b2820;line-height:1.35}.notification-toast span{font-size:13.5px;font-weight:400;color:#5a4236;line-height:1.45;display:block}.notification-toast .toast-close-btn{background:transparent;border:none;color:#7a4b3a;font-size:24px;line-height:1;cursor:pointer;padding:0 4px;flex-shrink:0;transition:color .15s,transform .15s}.notification-toast .toast-close-btn:hover{color:#3b2820;transform:scale(1.15)}.notification-toast .toast-actions{display:flex;align-items:center;gap:10px;margin-top:4px}@media (prefers-reduced-motion:reduce){.notification-toast,.notification-toast.is-hiding{transition:none}}#landlord-form [hidden]{display:none!important}#landlord-form .la-field{display:flex;flex-direction:column;gap:6px;min-width:0}#landlord-form .la-label{font-size:.85rem;font-weight:600;color:#4a342b;display:block;margin:0}#landlord-form .la-input{width:100%;height:44px;padding:0 14px;border:1px solid #d9bea6;border-radius:12px;background:#fff;color:#3b2820;font-size:.94rem;font-family:inherit;transition:all .18s ease}#landlord-form .la-input:focus{outline:none;border-color:#7a4b3a;box-shadow:0 0 0 3px rgba(122,75,58,.15)}#landlord-form .la-textarea{height:auto;min-height:110px;padding:12px 14px;resize:vertical;line-height:1.5}#landlord-form .la-input::-moz-placeholder{color:#a8968c}#landlord-form .la-input::placeholder{color:#a8968c}#landlord-form .la-prefix-wrapper{display:flex;align-items:stretch;width:100%;border:1px solid #d9bea6;border-radius:12px;background:#fff;overflow:hidden;transition:border-color .2s,box-shadow .2s}#landlord-form .la-prefix-wrapper:focus-within{border-color:#7a4b3a;box-shadow:0 0 0 3px rgba(122,75,58,.15)}#landlord-form .la-prefix-addon{display:flex;align-items:center;padding:0 12px;background:#fbf6f2;color:#78665c;font-size:.9rem;font-weight:600;border-right:1px solid #ebd9cb;-webkit-user-select:none;-moz-user-select:none;user-select:none;white-space:nowrap}#landlord-form .la-prefix-wrapper .la-input{border:none!important;border-radius:0!important;box-shadow:none!important;flex:1;min-width:0}#landlord-form .la-dropzone{position:relative;border:2px dashed #d9bea6;border-radius:12px;background:#fdfaf7;text-align:center;cursor:pointer;transition:all .2s ease}#landlord-form .la-dropzone.dragover,#landlord-form .la-dropzone:focus-visible,#landlord-form .la-dropzone:hover{background:#f7ede5;border-color:#7a4b3a;transform:translateY(-1px);outline:none}#landlord-form .la-dz-icon{width:24px;height:24px;margin:0 auto 3px;color:#7a4b3a;display:block}#landlord-form .la-dz-filename{font-weight:600;color:#3b2820;font-size:.82rem;margin-bottom:1px;word-break:break-all}#landlord-form .la-dz-hint{font-size:.72rem;color:#8b7467;margin-bottom:6px}#landlord-form .la-dz-btn-row{display:flex;gap:8px;justify-content:center}#landlord-form .la-dz-btn{display:inline-flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:600;gap:6px;padding:6px 14px;background:#fff;border:1px solid #ebd9cb;border-radius:8px;font:600 .8rem inherit;font-family:inherit;color:#7a4b3a;cursor:pointer;transition:background .15s,border-color .15s}#landlord-form .la-dz-btn:hover{background:#fbf5ef;border-color:#7a4b3a}#landlord-form .la-dz-btn-danger{color:#dc2626;border-color:#fecaca}#landlord-form .la-dz-btn-danger:hover{background:#fef2f2;color:#991b1b;border-color:#fecaca}#landlord-form .la-dz-active{display:flex;flex-direction:column;align-items:center;width:100%}#landlord-form .la-dz-active .la-dz-btn-row{margin-top:8px}#landlord-form .la-dz-preview-wrap{width:100%;max-height:220px;border-radius:12px;overflow:hidden;margin-bottom:12px;background:#f5ede6;border:1px solid #e2cec0;display:flex;align-items:center;justify-content:center}#landlord-form .la-dz-preview-img{width:100%;max-height:220px;-o-object-fit:contain;object-fit:contain;display:block}#landlord-form .la-file-input{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;bottom:0;left:50%}#landlord-form .la-hint{font-size:.78rem;color:#8b7467}@media (prefers-reduced-motion:reduce){#landlord-form .la-dropzone,#landlord-form .la-input,#landlord-form .la-prefix-wrapper{transition:none}}#landlord-form .la-textarea{resize:none}#landlord-form .la-dropzone{padding:8px 12px;display:flex;flex-direction:column;justify-content:center;align-items:center;overflow:hidden}#landlord-form #la-dz-empty,#landlord-form #la-dz-filled{width:100%}#landlord-form .la-dz-preview-wrap{height:52px;max-height:52px;margin-bottom:4px}#landlord-form .la-dz-preview-img{height:100%;max-height:52px}#landlord-form .apply-grid{align-content:start;row-gap:8px}#landlord-form .apply-agreement{margin:12px 0 0;align-self:start}#landlord-form .apply-msg:empty{display:none;margin:0;min-height:0;padding:0}#landlord-form .apply-actions{margin:0}@view-transition{navigation:auto}@keyframes page-fade-in{0%{opacity:0}to{opacity:1}}@keyframes page-fade-out{0%{opacity:1}to{opacity:0}}::view-transition-old(root){animation:page-fade-out .2s cubic-bezier(.4,0,1,1) both}::view-transition-new(root){animation:page-fade-in .32s cubic-bezier(0,0,.2,1) both}html.page-entering body{animation:page-fade-in .32s cubic-bezier(0,0,.2,1) both}html.page-leaving body{animation:page-fade-out .2s cubic-bezier(.4,0,1,1) both}@media (prefers-reduced-motion:reduce){::view-transition-new(root),::view-transition-old(root),html.page-entering body,html.page-leaving body{animation:none}}[data-theme=dark]{--ds-title:#fff7f2;--ds-body:#d9c8bc;--ds-muted:#a8948a;--ds-nav:#d9c8bc;--ds-nav-hover:#fff;--ds-accent:#e2ab94;--ds-accent-hover:#efc2ae;--ds-accent-press:#cf967f;--ds-on-accent:#1e1612;--ds-accent-soft:hsla(18,57%,73%,.16);--ds-accent-light:#f3d2c3;--ds-page:#1e1612;--ds-surface:#2b1f19;--ds-footer:#150f0c;--ds-field:#231a14;--ds-field-line:#5c4639;--ds-line:#3d2d24;--topbar-bg:rgba(30,22,18,.96);--topbar-head:#fff7f2}html[data-theme=dark]{scrollbar-color:var(--ds-accent) transparent}html[data-theme=dark] ::-webkit-scrollbar-track{background:transparent!important}html[data-theme=dark] ::-webkit-scrollbar-thumb{background:var(--ds-accent);border-color:#251c16}html[data-theme=dark] ::-webkit-scrollbar-thumb:hover{background:var(--ds-accent-hover)}html[data-theme=dark],html[data-theme=dark] .page,html[data-theme=dark] body{background:var(--ds-page)}html[data-theme=dark] .blob{display:none!important}html[data-theme=dark] .hero-wrap.photo-section,html[data-theme=dark] .sec{background:transparent}html[data-theme=dark] .feat-orb.o1,html[data-theme=dark] .feat-orb.o2,html[data-theme=dark] .feat-orb.o3{background:#4a2c1f;opacity:.35}html[data-theme=dark] .feat:before{background-image:radial-gradient(hsla(16,40%,61%,.28) 1.2px,transparent 1.4px)}html[data-theme=dark] .feat-ring{border-color:hsla(16,40%,61%,.35)}html[data-theme=dark] .topbar{border-bottom:1px solid var(--ds-line)}html[data-theme=dark] .topbar .logo,html[data-theme=dark] .topbar .logo-name{color:var(--ds-title)}html[data-theme=dark] .topbar .links a{color:var(--ds-nav)}html[data-theme=dark] .topbar .links a:focus-visible,html[data-theme=dark] .topbar .links a:hover{color:var(--ds-nav-hover)}html[data-theme=dark] .feat-brand,html[data-theme=dark] .feature-hero .feat-copy h1,html[data-theme=dark] .photo-section .hero-copy h2,html[data-theme=dark] .sec h2{color:var(--ds-title)}html[data-theme=dark] .feature-hero .feat-copy p,html[data-theme=dark] .hero-copy p,html[data-theme=dark] .hero-progress,html[data-theme=dark] .sec .desc{color:var(--ds-body)}html[data-theme=dark] .btn.primary,html[data-theme=dark] .loginbtn{background:var(--ds-accent);border-color:var(--ds-accent);color:var(--ds-on-accent);box-shadow:0 2px 10px rgba(0,0,0,.35)}html[data-theme=dark] .btn.primary:hover,html[data-theme=dark] .loginbtn:hover{background:var(--ds-accent-hover);border-color:var(--ds-accent-hover);color:var(--ds-on-accent)}html[data-theme=dark] .btn.primary:active,html[data-theme=dark] .loginbtn:active{background:var(--ds-accent-press);border-color:var(--ds-accent-press);color:var(--ds-on-accent)}html[data-theme=dark] .btn:not(.primary){background:transparent;border-color:var(--ds-accent-light);color:var(--ds-accent-light);box-shadow:none;filter:none}html[data-theme=dark] .btn:not(.primary):hover{background:var(--ds-accent-soft);color:#fff}html[data-theme=dark] .news button{background:var(--ds-accent);color:var(--ds-on-accent)}html[data-theme=dark] #sub-btn:not(:disabled):hover{background:var(--ds-accent-hover);color:var(--ds-on-accent);border-color:var(--ds-accent-hover)}html[data-theme=dark] #sub-btn:not(:disabled):active{background:var(--ds-accent-press)}html[data-theme=dark] #sub-btn:disabled,html[data-theme=dark] .news button:disabled{background:#3a2c24!important;color:#8f7a6d!important}html[data-theme=dark] .tabs{background:#281c16;border:1px solid var(--ds-line)}html[data-theme=dark] .tab-indicator{background:var(--ds-accent)}html[data-theme=dark] .tab{color:var(--ds-body)}html[data-theme=dark] .tab:hover:not(.active){color:#fff}html[data-theme=dark] .tab.active{color:var(--ds-on-accent)}html[data-theme=dark] .hero-count,html[data-theme=dark] .hero-kicker{color:var(--ds-accent-light)}html[data-theme=dark] .hero-bars button:before{background:#4b382d}html[data-theme=dark] .hero-bars button.on:before{background:var(--ds-accent)}html[data-theme=dark] .sq-badge{background:rgba(30,22,18,.88);color:#f0cfc2;border-color:hsla(16,40%,61%,.45)}html[data-theme=dark] .sq-num{color:#fff}html[data-theme=dark] .feat-icon-float{background:rgba(43,31,25,.92);border-color:hsla(16,40%,61%,.35);color:var(--ds-accent-light);box-shadow:0 14px 32px rgba(0,0,0,.45)}html[data-theme=dark] footer{background:var(--ds-footer);color:var(--ds-body);border-top:0}html[data-theme=dark] .f .logo{color:var(--ds-title)}html[data-theme=dark] .f p{color:#bfaea2}html[data-theme=dark] .f h4,html[data-theme=dark] .news b{color:#f3e3d8}html[data-theme=dark] .f li a{color:#bfaea2}html[data-theme=dark] .f li a:focus,html[data-theme=dark] .f li a:hover{color:var(--ds-accent-hover)}html[data-theme=dark] .f li a:active{color:var(--ds-accent-press)}html[data-theme=dark] .copy{color:var(--ds-muted);border-top:1px solid var(--ds-line)}html[data-theme=dark] .news-msg{color:var(--ds-body)}html[data-theme=dark] .news-msg.error{color:#ff9b8a}html[data-theme=dark] .news-msg.success{color:#8fdca0}html[data-theme=dark] input:not([type=checkbox]):not([type=radio]):not([type=file]),html[data-theme=dark] textarea{border-color:var(--ds-field-line)!important}html[data-theme=dark] input::-moz-placeholder,html[data-theme=dark] textarea::-moz-placeholder{color:#8a7569}html[data-theme=dark] input::placeholder,html[data-theme=dark] textarea::placeholder{color:#8a7569}html[data-theme=dark] input:not([type=checkbox]):not([type=radio]):not([type=file]):focus,html[data-theme=dark] textarea:focus{outline:none;border-color:var(--ds-accent)!important;box-shadow:0 0 0 3px hsla(16,40%,61%,.28)}html[data-theme=dark] :focus-visible{outline-color:var(--ds-accent-hover)}html[data-theme=dark] .apply-dialog{background:var(--dark-form-surface,#382a22);border-color:var(--ds-line);color:var(--ds-body);box-shadow:0 28px 70px rgba(0,0,0,.6)}html[data-theme=dark] .apply-dialog::backdrop{background:rgba(12,8,6,0)}html[data-theme=dark] .apply-dialog[open].is-open::backdrop{background:rgba(12,8,6,.72)}html[data-theme=dark] #landlord-form .apply-ws-left{background:linear-gradient(135deg,#32221b,#241813);border-right:1px solid var(--ds-line);color:var(--ds-title)}html[data-theme=dark] #landlord-form .apply-ws-subtitle,html[data-theme=dark] #landlord-form .apply-ws-title{color:var(--ds-title)}html[data-theme=dark] #landlord-form .apply-ws-subtitle{color:#e6d3c7}html[data-theme=dark] #landlord-form .apply-ws-left .la-label{color:#e6d3c7!important}html[data-theme=dark] #landlord-form .apply-ws-left .la-input,html[data-theme=dark] #landlord-form .apply-ws-left .la-prefix-wrapper{background:var(--ds-field)!important;border:1.5px solid var(--ds-field-line)!important;color:var(--ds-title)!important;box-shadow:none}html[data-theme=dark] #landlord-form .apply-ws-left .la-prefix-wrapper .la-input{border:none!important;background:transparent!important}html[data-theme=dark] #landlord-form .apply-ws-left .la-input:focus,html[data-theme=dark] #landlord-form .apply-ws-left .la-prefix-wrapper:focus-within{border-color:var(--ds-accent)!important;box-shadow:0 0 0 3px hsla(16,40%,61%,.28)!important}html[data-theme=dark] #landlord-form .apply-ws-left .la-prefix-addon,html[data-theme=dark] #landlord-form .la-prefix-addon{background:#2d201a;color:#e6d3c7;border-right:1px solid var(--ds-field-line)}html[data-theme=dark] #landlord-form .apply-ws-right{background:var(--dark-form-surface,#382a22)}html[data-theme=dark] #landlord-form .apply-right-title{color:var(--ds-title)}html[data-theme=dark] #landlord-form .apply-ws-right .la-label{color:#e6d3c7}html[data-theme=dark] #landlord-form .apply-ws-right .la-textarea,html[data-theme=dark] #landlord-form .la-input{background:var(--ds-field)!important;border:1px solid var(--ds-field-line)!important;color:var(--ds-title)!important}html[data-theme=dark] #landlord-form .la-input::-moz-placeholder{color:#8a7569}html[data-theme=dark] #landlord-form .la-input::placeholder{color:#8a7569}html[data-theme=dark] #landlord-form .la-input:focus{border-color:var(--ds-accent)!important;box-shadow:0 0 0 3px hsla(16,40%,61%,.28)!important}html[data-theme=dark] #landlord-form .apply-ws-right .la-hint,html[data-theme=dark] #landlord-form .la-dz-hint,html[data-theme=dark] #landlord-form .la-hint{color:var(--ds-muted)}html[data-theme=dark] #landlord-form .apply-ws-right .la-dropzone,html[data-theme=dark] #landlord-form .la-dropzone{background:#231a14;border:2px dashed var(--ds-field-line)}html[data-theme=dark] #landlord-form .la-dropzone.dragover,html[data-theme=dark] #landlord-form .la-dropzone:focus-visible,html[data-theme=dark] #landlord-form .la-dropzone:hover{background:#2d201a;border-color:var(--ds-accent)}html[data-theme=dark] #landlord-form .la-dz-icon{color:var(--ds-accent)}html[data-theme=dark] #landlord-form .la-dz-filename{color:var(--ds-title)}html[data-theme=dark] #landlord-form .la-dz-preview-wrap{background:#2b1f19;border-color:var(--ds-field-line)}html[data-theme=dark] #landlord-form .la-dz-btn{background:#34261f;border-color:var(--ds-field-line);color:#f0cfc2}html[data-theme=dark] #landlord-form .la-dz-btn:hover{background:#3f2f26;border-color:var(--ds-accent)}html[data-theme=dark] #landlord-form .la-dz-btn-danger{color:#ff9b8a;border-color:hsla(9,100%,77%,.4)}html[data-theme=dark] #landlord-form .la-dz-btn-danger:hover{background:hsla(9,100%,77%,.12);color:#ffb4a6}html[data-theme=dark] #landlord-form .apply-agreement{background:#231a14;border:1px solid var(--ds-field-line);color:var(--ds-body)}html[data-theme=dark] #landlord-form .apply-agreement input{accent-color:var(--ds-accent)}html[data-theme=dark] #landlord-form .apply-modal-footer .btn-apply-cancel{background:transparent;border:1px solid var(--ds-field-line);color:var(--ds-body)}html[data-theme=dark] #landlord-form .apply-modal-footer .btn-apply-cancel:hover{background:#34261f;color:#fff}html[data-theme=dark] #landlord-form .apply-actions .btn:disabled,html[data-theme=dark] #landlord-form .apply-actions .btn:disabled:hover{background:var(--ds-accent);border-color:var(--ds-accent);color:var(--ds-on-accent);opacity:.6}html[data-theme=dark] #landlord-form .apply-close{color:var(--ds-accent-hover)}html[data-theme=dark] #landlord-form .apply-close:hover{color:#fff}html[data-theme=dark] .apply-msg.error{color:#ff9b8a}html[data-theme=dark] .apply-msg.success{color:#8fdca0}html[data-theme=dark] .apply-msg.info{color:var(--ds-body)}html[data-theme=dark] .apply-dialog{scrollbar-color:var(--ds-field-line) transparent}html[data-theme=dark] .apply-dialog::-webkit-scrollbar-thumb{background:var(--ds-field-line);border-color:var(--ds-surface)}.topbar .actions{align-items:center}.topbar .btn-theme-toggle{background:transparent!important;border:transparent!important;box-shadow:none!important;color:var(--ink,#3b2820);cursor:pointer;transition:transform .18s ease,color .18s ease;width:28px!important;height:34px!important;padding:0!important;margin-right:14px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important}.topbar .btn-theme-toggle svg{display:block;width:27px!important;height:27px!important;stroke-width:2.1!important}.topbar .btn-theme-toggle svg circle,.topbar .btn-theme-toggle svg path{transition:fill .18s ease,stroke .18s ease}.topbar .btn-theme-toggle:focus-visible,.topbar .btn-theme-toggle:hover{background:transparent!important;border-color:transparent!important;box-shadow:none!important;color:#7a4b3a;transform:scale(1.08)}.topbar .btn-theme-toggle:hover svg path{fill:currentColor}.topbar .btn-theme-toggle:active{background:transparent!important;border-color:transparent!important;box-shadow:none!important;color:#5c3527;transform:scale(.92)}.topbar .btn-theme-toggle:active svg path{fill:currentColor}html[data-theme=dark] .topbar .btn-theme-toggle{background:transparent!important;border:transparent!important;box-shadow:none!important;color:var(--ds-nav,#c5a898)}html[data-theme=dark] .topbar .btn-theme-toggle:focus-visible,html[data-theme=dark] .topbar .btn-theme-toggle:hover{background:transparent!important;border-color:transparent!important;box-shadow:none!important;color:#f59e0b;transform:scale(1.08)}html[data-theme=dark] .topbar .btn-theme-toggle:hover svg circle{fill:currentColor}html[data-theme=dark] .topbar .btn-theme-toggle:active{background:transparent!important;border-color:transparent!important;box-shadow:none!important;color:#d97706;transform:scale(.92)}html[data-theme=dark] .topbar .btn-theme-toggle:active svg circle{fill:currentColor}html[data-theme=dark] .notification-toast{background:#f6ebe2;border:1px solid #e3cfc1;color:#2b1a12;box-shadow:0 16px 36px rgba(0,0,0,.5)}html[data-theme=dark] .notification-toast strong{color:#2b1a12}html[data-theme=dark] .notification-toast span{color:#4a342b}html[data-theme=dark] .notification-toast .toast-close-btn{color:#7a4b3a}html[data-theme=dark] .notification-toast .toast-close-btn:hover{color:#2b1a12}.sq-num{display:none!important}html[data-theme=dark] .feat-icon-float,html[data-theme=dark] .feat-icon-float svg,html[data-theme=dark] .feature-hero .feat-chip svg{color:var(--ds-body)}html[data-theme=dark] .feat-actions .landlord-application-cta{font-weight:500}html[data-theme=dark] .feat-actions .landlord-application-cta:active,html[data-theme=dark] .feat-actions .landlord-application-cta:focus-visible,html[data-theme=dark] .feat-actions .landlord-application-cta:hover{font-weight:700}input:-webkit-autofill,input:-webkit-autofill:active,input:-webkit-autofill:focus,input:-webkit-autofill:hover,select:-webkit-autofill,select:-webkit-autofill:focus,select:-webkit-autofill:hover,textarea:-webkit-autofill,textarea:-webkit-autofill:focus,textarea:-webkit-autofill:hover{-webkit-transition:background-color 600000s 0s,color 600000s 0s;transition:background-color 600000s 0s,color 600000s 0s;-webkit-text-fill-color:currentColor;caret-color:currentColor}input:-webkit-autofill,select:-webkit-autofill,textarea:-webkit-autofill{filter:none;background-clip:padding-box}input:autofill,select:autofill,textarea:autofill{filter:none;background-clip:padding-box}.visible{visibility:visible}.block{display:block}.hidden{display:none}.transform{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.resize{resize:both}.outline{outline-style:solid}.transition{transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,-webkit-backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter,-webkit-backdrop-filter;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.ease-in-out{transition-timing-function:cubic-bezier(.4,0,.2,1)}.ease-out{transition-timing-function:cubic-bezier(0,0,.2,1)}
/* landing-tailwind:end */
</style>
<style id="landlord-apply-styles">
/* Landlord Application Dialog & Toast Refinements */
#landlord-dialog.apply-dialog {
  background: #ffffff;
  border: 1px solid #ebd9cb;
  border-radius: 24px;
  color: #2b170e;
  box-shadow: 0 24px 60px rgba(74, 44, 32, 0.22);
}
#landlord-form {
  background: transparent;
}
#landlord-form .apply-columns {
  background: transparent;
  display: grid !important;
  grid-template-columns: 529fr 596fr !important;
  gap: 0 !important;
  align-items: stretch !important;
  height: 100% !important;
  min-height: 0 !important;
}
/* Left column: Signature warm brown gradient */
#landlord-form .apply-ws-left {
  background: radial-gradient(55% 55% at 78% 8%, rgba(176,117,92,.85) 0, rgba(176,117,92,0) 100%), radial-gradient(60% 55% at 12% 100%, rgba(166,107,82,.9) 0, rgba(166,107,82,0) 100%), #7a4b3a !important;
  border-right: 1px solid rgba(255, 255, 255, 0.18) !important;
  padding: 32px 38px;
  color: #ffffff;
  display: flex;
  flex-direction: column;
}
/* Right column: Pure white background matching tenant dorm details card */
#landlord-form .apply-ws-right {
  background: #ffffff !important;
  border-left: 1px solid #ebd9cb !important;
  padding: 32px 38px 24px;
  color: #3b2820 !important;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  height: 100%;
  min-height: 0;
  box-sizing: border-box;
}
/* Typography for Headers */
#landlord-form .apply-left-header,
#landlord-form .apply-right-header {
  margin-bottom: 18px;
}
#landlord-form .apply-ws-left .apply-ws-title {
  font-family: Fraunces, Georgia, serif !important;
  font-size: 26px !important;
  font-weight: 700 !important;
  line-height: 1.15 !important;
  color: #ffffff !important;
  margin: 0 0 4px 0 !important;
}
#landlord-form .apply-ws-left .apply-ws-subtitle {
  font-family: 'DM Sans', sans-serif !important;
  font-size: 13px !important;
  font-weight: 500 !important;
  text-transform: uppercase !important;
  letter-spacing: 0.5px !important;
  color: rgba(255, 255, 255, 0.85) !important;
  margin: 0 !important;
}
#landlord-form .apply-ws-right .apply-ws-title {
  font-family: Fraunces, Georgia, serif !important;
  font-size: 26px !important;
  font-weight: 700 !important;
  line-height: 1.15 !important;
  color: #3b2820 !important;
  margin: 0 0 4px 0 !important;
}
#landlord-form .apply-ws-right .apply-ws-subtitle {
  font-family: 'DM Sans', sans-serif !important;
  font-size: 13px !important;
  font-weight: 600 !important;
  text-transform: uppercase !important;
  letter-spacing: 0.5px !important;
  color: #78665c !important;
  margin: 0 !important;
}
/* Left column labels are white */
#landlord-form .apply-ws-left .la-label {
  color: #ffffff !important;
  font-size: 11px !important;
  font-weight: 600 !important;
  text-transform: uppercase !important;
  letter-spacing: 0.35px !important;
  display: block !important;
  margin-bottom: 5px !important;
}
/* Right column labels are warm dark brown */
#landlord-form .apply-ws-right .la-label {
  color: #4a342b !important;
  font-size: 11px !important;
  font-weight: 700 !important;
  text-transform: uppercase !important;
  letter-spacing: 0.35px !important;
  display: block !important;
  margin-bottom: 5px !important;
}
/* Inputs in Left Column */
#landlord-form .apply-ws-left .la-field {
  margin-bottom: 11px;
}
#landlord-form .apply-ws-left .la-input {
  width: 100% !important;
  height: 38px !important;
  padding: 0 12px !important;
  background: #ffffff !important;
  border: 1.5px solid #ffffff !important;
  color: #2b170e !important;
  border-radius: 8px !important;
  font-size: 0.88rem !important;
  font-weight: 500 !important;
  box-shadow: 0 2px 5px rgba(0,0,0,0.08) !important;
  box-sizing: border-box !important;
}
#landlord-form .apply-ws-left .la-input:focus {
  outline: none !important;
  border-color: #ffffff !important;
  box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.45), 0 3px 8px rgba(0,0,0,0.12) !important;
}
#landlord-form .apply-ws-left .la-prefix-wrapper {
  display: flex !important;
  align-items: stretch !important;
  width: 100% !important;
  height: 38px !important;
  background: #ffffff !important;
  border: 1.5px solid #ffffff !important;
  border-radius: 8px !important;
  overflow: hidden !important;
  box-shadow: 0 2px 5px rgba(0,0,0,0.08) !important;
  box-sizing: border-box !important;
}
#landlord-form .apply-ws-left .la-prefix-addon {
  display: flex !important;
  align-items: center !important;
  padding: 0 10px !important;
  background: #fbf6f2 !important;
  color: #5a3d31 !important;
  font-weight: 700 !important;
  font-size: 0.8rem !important;
  border-right: 1px solid #ebd9cb !important;
  user-select: none !important;
}
#landlord-form .apply-ws-left .la-prefix-wrapper .la-input {
  height: 100% !important;
  border: none !important;
  box-shadow: none !important;
  border-radius: 0 !important;
  padding: 0 10px !important;
}
/* Right Column: Experience and Verification Exact Same Style with Increased Heights to occupy all vertical space */
#landlord-form .apply-right-fields {
  display: flex;
  flex-direction: column;
  gap: 14px;
  flex: 1;
  min-height: 0;
}
#landlord-form .apply-experience {
  display: flex;
  flex-direction: column;
  margin-bottom: 0;
}
#landlord-form #la-experience.la-textarea {
  width: 100% !important;
  min-height: 135px !important;
  height: 135px !important;
  padding: 12px 14px !important;
  background: #fdfaf7 !important;
  border: 1.5px solid #d9bea6 !important;
  color: #2b170e !important;
  border-radius: 10px !important;
  font-family: inherit !important;
  font-size: 0.88rem !important;
  line-height: 1.45 !important;
  box-shadow: 0 2px 5px rgba(0,0,0,0.04) !important;
  box-sizing: border-box !important;
  resize: none !important;
}
#landlord-form #la-experience.la-textarea:focus {
  outline: none !important;
  border-color: #7a4b3a !important;
  box-shadow: 0 0 0 3px rgba(122, 75, 58, 0.15) !important;
}
#landlord-form .apply-upload {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;
  margin-bottom: 0;
}
#landlord-form #la-dropzone.la-dropzone {
  position: relative !important;
  width: 100% !important;
  min-height: 175px !important;
  flex: 1 !important;
  background: #fdfaf7 !important;
  border: 2px dashed #d9bea6 !important;
  border-radius: 12px !important;
  box-shadow: 0 2px 5px rgba(0,0,0,0.04) !important;
  display: flex !important;
  flex-direction: column !important;
  align-items: center !important;
  justify-content: center !important;
  padding: 16px !important;
  cursor: pointer !important;
  box-sizing: border-box !important;
  transition: all .2s ease !important;
}
#landlord-form #la-dropzone.la-dropzone:hover,
#landlord-form #la-dropzone.la-dropzone.dragover {
  background: #f7ede5 !important;
  border-color: #7a4b3a !important;
}
#landlord-form #la-dz-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
}
#landlord-form .la-dz-icon {
  width: 32px !important;
  height: 32px !important;
  color: #7a4b3a !important;
  margin-bottom: 6px !important;
}
#landlord-form #la-dropzone.has-file .la-dz-icon {
  display: none !important;
}
#landlord-form .la-dz-preview-img {
  display: block;
  max-width: 100%;
  max-height: 120px;
  margin: 0 auto 8px;
  object-fit: contain;
  border-radius: 6px;
}
#landlord-form .la-dz-preview-img[hidden] {
  display: none;
}
#landlord-form .la-dz-filename {
  font-size: 0.88rem !important;
  font-weight: 600 !important;
  color: #3b2820 !important;
  margin-bottom: 2px !important;
}
#landlord-form .la-dz-hint {
  font-size: 0.78rem !important;
  color: #78665c !important;
  margin-bottom: 8px !important;
}
#landlord-form .la-dz-hint[hidden] {
  display: none !important;
}
#landlord-form .la-dz-btn {
  background: #ffffff !important;
  border: 1px solid #d9bea6 !important;
  color: #5a3d31 !important;
  font-weight: 600 !important;
  font-size: 0.8rem !important;
  border-radius: 8px !important;
  padding: 5px 14px !important;
  cursor: pointer !important;
  transition: all .15s ease !important;
}
#landlord-form .la-dz-btn:hover {
  background: #ebd9cb !important;
  color: #3b2820 !important;
}
#landlord-form .la-dz-btn-row[hidden] {
  display: none !important;
}
/* Agreement Checkbox on white right background */
#landlord-form .apply-agreement {
  display: flex !important;
  align-items: flex-start !important;
  gap: 10px !important;
  background: #fdfaf7 !important;
  border: 1px solid #ebd9cb !important;
  border-radius: 10px !important;
  padding: 10px 14px !important;
  color: #3b2820 !important;
  font-size: 0.8rem !important;
  line-height: 1.4 !important;
  margin-top: 10px !important;
  margin-bottom: 8px !important;
  cursor: pointer !important;
}
#landlord-form .apply-agreement input {
  accent-color: #7a4b3a !important;
  width: 16px !important;
  height: 16px !important;
  margin-top: 2px !important;
  flex-shrink: 0 !important;
}
/* Modal Footer & Buttons on white right background */
#landlord-form .apply-modal-footer {
  margin-top: auto !important;
  padding-top: 8px !important;
}
#landlord-form .apply-actions {
  display: flex !important;
  align-items: center !important;
  justify-content: flex-end !important;
  gap: 12px !important;
}
#landlord-form .btn-apply-cancel {
  background: transparent !important;
  border: 1px solid #d9bea6 !important;
  color: #5a3d31 !important;
  border-radius: 9999px !important;
  padding: 8px 20px !important;
  font-size: 0.88rem !important;
  font-weight: 600 !important;
  cursor: pointer !important;
  transition: all .18s ease !important;
}
#landlord-form .btn-apply-cancel:hover {
  background: #fdfaf7 !important;
  border-color: #7a4b3a !important;
  color: #3b2820 !important;
}
#landlord-form #apply-btn {
  background: #7a4b3a !important;
  border: 1.5px solid #7a4b3a !important;
  color: #ffffff !important;
  border-radius: 9999px !important;
  padding: 8px 24px !important;
  font-size: 0.88rem !important;
  font-weight: 700 !important;
  cursor: pointer !important;
  box-shadow: 0 4px 12px rgba(122,75,58,0.2) !important;
  transition: all .18s ease !important;
}
#landlord-form #apply-btn:hover:not(:disabled) {
  background: #623a2c !important;
  border-color: #623a2c !important;
  transform: translateY(-1px) !important;
  color: #ffffff !important;
}
#landlord-form .apply-close {
  color: #7a4b3a !important;
  transition: color .15s ease, transform .15s ease !important;
}
#landlord-form .apply-close:hover {
  color: #3b2820 !important;
  transform: scale(1.1) !important;
}
/* Application Feedback Message */
#landlord-form .apply-msg {
  display: none;
  min-height: 14px;
  margin: 6px 0 !important;
  font-size: 0.84rem !important;
  font-weight: 600 !important;
  line-height: 1.4 !important;
  border-radius: 8px !important;
  padding: 8px 12px !important;
}
#landlord-form .apply-msg.error {
  display: block !important;
  color: #a33d2d !important;
  background: #fdf2f0 !important;
  border: 1px solid #f5c2bc !important;
}
#landlord-form .apply-msg.success {
  display: block !important;
  color: #28733a !important;
  background: #f0fdf4 !important;
  border: 1px solid #bbf7d0 !important;
}
#landlord-form .apply-msg:empty {
  display: none !important;
}

/* ========================================================
   DARK MODE STYLES (strictly when html[data-theme="dark"])
   ======================================================== */
html[data-theme="dark"] #landlord-dialog.apply-dialog {
  background: #2b1f19 !important;
  border: 1px solid #3d2d24 !important;
  color: #e2d3c8 !important;
  box-shadow: 0 28px 70px rgba(0,0,0,0.6) !important;
}
html[data-theme="dark"] #landlord-form .apply-ws-left {
  background: linear-gradient(135deg, #32221b, #241813) !important;
  border-right: 1px solid #3d2d24 !important;
  color: #fff7f2 !important;
}
html[data-theme="dark"] #landlord-form .apply-ws-right {
  background: #2b1f19 !important;
  border-left: 1px solid #3d2d24 !important;
  color: #d9c8bc !important;
}
html[data-theme="dark"] #landlord-form .apply-ws-title {
  color: #fff7f2 !important;
}
html[data-theme="dark"] #landlord-form .apply-ws-subtitle {
  color: #c5a898 !important;
}
html[data-theme="dark"] #landlord-form .apply-ws-left .la-label,
html[data-theme="dark"] #landlord-form .apply-ws-right .la-label {
  color: #e6d3c7 !important;
}
html[data-theme="dark"] #landlord-form .la-input,
html[data-theme="dark"] #landlord-form #la-experience.la-textarea {
  background: #231a14 !important;
  border: 1.5px solid #5c4639 !important;
  color: #fff7f2 !important;
  box-shadow: none !important;
}
html[data-theme="dark"] #landlord-form .la-input:focus,
html[data-theme="dark"] #landlord-form #la-experience.la-textarea:focus {
  border-color: #e2ab94 !important;
  box-shadow: 0 0 0 3px hsla(18,57%,73%,.22) !important;
}
html[data-theme="dark"] #landlord-form .la-prefix-wrapper {
  background: #231a14 !important;
  border: 1.5px solid #5c4639 !important;
  box-shadow: none !important;
}
html[data-theme="dark"] #landlord-form .la-prefix-addon {
  background: #2d201a !important;
  color: #d9c8bc !important;
  border-right: 1px solid #5c4639 !important;
}
html[data-theme="dark"] #landlord-form #la-dropzone.la-dropzone {
  background: #231a14 !important;
  border: 2px dashed #5c4639 !important;
  box-shadow: none !important;
}
html[data-theme="dark"] #landlord-form #la-dropzone.la-dropzone:hover,
html[data-theme="dark"] #landlord-form #la-dropzone.la-dropzone.dragover {
  background: #2d201a !important;
  border-color: #e2ab94 !important;
}
html[data-theme="dark"] #landlord-form .la-dz-icon {
  color: #e2ab94 !important;
}
html[data-theme="dark"] #landlord-form .la-dz-filename {
  color: #fff7f2 !important;
}
html[data-theme="dark"] #landlord-form .la-dz-hint {
  color: #a8948a !important;
}
html[data-theme="dark"] #landlord-form .la-dz-btn {
  background: #34261f !important;
  border: 1px solid #5c4639 !important;
  color: #f0cfc2 !important;
}
html[data-theme="dark"] #landlord-form .la-dz-btn:hover {
  background: #3f2f26 !important;
  border-color: #e2ab94 !important;
  color: #ffffff !important;
}
html[data-theme="dark"] #landlord-form .apply-agreement {
  background: #231a14 !important;
  border: 1px solid #5c4639 !important;
  color: #d9c8bc !important;
}
html[data-theme="dark"] #landlord-form .btn-apply-cancel {
  background: transparent !important;
  border: 1px solid #5c4639 !important;
  color: #d9c8bc !important;
}
html[data-theme="dark"] #landlord-form .btn-apply-cancel:hover {
  background: #34261f !important;
  color: #ffffff !important;
}
html[data-theme="dark"] #landlord-form #apply-btn {
  background: #e2ab94 !important;
  border-color: #e2ab94 !important;
  color: #1e1612 !important;
  box-shadow: none !important;
}
html[data-theme="dark"] #landlord-form #apply-btn:hover:not(:disabled) {
  background: #efc2ae !important;
  border-color: #efc2ae !important;
  color: #1e1612 !important;
}
html[data-theme="dark"] #landlord-form .apply-close {
  color: #e2ab94 !important;
}
html[data-theme="dark"] #landlord-form .apply-close:hover {
  color: #ffffff !important;
}
html[data-theme="dark"] #landlord-form .apply-msg.error {
  color: #fca5a5 !important;
  background: #3b1d1d !important;
  border: 1px solid #7f1d1d !important;
}

/* Landlord Toast Styling (Light Brown Bottom-Right Notification with X button) */
#landlord-apply-toast.notification-toast {
  background: #f6ebe2 !important;
  border: 1px solid #e3cfc1 !important;
  color: #2b1a12 !important;
  box-shadow: 0 16px 36px rgba(0,0,0,0.25) !important;
}
#landlord-apply-toast strong {
  color: #2b1a12 !important;
}
#landlord-apply-toast span {
  color: #4a342b !important;
}
#landlord-apply-toast .toast-close-btn {
  color: #7a4b3a !important;
}
#landlord-apply-toast .toast-close-btn:hover {
  color: #2b1a12 !important;
}
html[data-theme="dark"] #landlord-apply-toast.notification-toast {
  background: #32241d !important;
  border: 1px solid #4b382d !important;
  color: #fbf5f0 !important;
  box-shadow: 0 16px 36px rgba(0,0,0,0.55) !important;
}
html[data-theme="dark"] #landlord-apply-toast strong {
  color: #fbf5f0 !important;
}
html[data-theme="dark"] #landlord-apply-toast span {
  color: #d9c8bc !important;
}
html[data-theme="dark"] #landlord-apply-toast .toast-close-btn {
  color: #e2ab94 !important;
}
html[data-theme="dark"] #landlord-apply-toast .toast-close-btn:hover {
  color: #fff7f2 !important;
}

/* Neutralize autofill blue background */
input:-webkit-autofill,
input:-webkit-autofill:hover, 
input:-webkit-autofill:focus,
input:-webkit-autofill:active,
textarea:-webkit-autofill,
textarea:-webkit-autofill:hover,
textarea:-webkit-autofill:focus,
textarea:-webkit-autofill:active {
  -webkit-box-shadow: 0 0 0 1000px #ffffff inset !important;
  box-shadow: 0 0 0 1000px #ffffff inset !important;
  -webkit-text-fill-color: #3b2820 !important;
  caret-color: #3b2820 !important;
  transition: background-color 5000s ease-in-out 0s !important;
}
html[data-theme="dark"] input:-webkit-autofill,
html[data-theme="dark"] input:-webkit-autofill:hover, 
html[data-theme="dark"] input:-webkit-autofill:focus,
html[data-theme="dark"] input:-webkit-autofill:active,
html[data-theme="dark"] textarea:-webkit-autofill,
html[data-theme="dark"] textarea:-webkit-autofill:hover,
html[data-theme="dark"] textarea:-webkit-autofill:focus,
html[data-theme="dark"] textarea:-webkit-autofill:active {
  -webkit-box-shadow: 0 0 0 1000px #231a14 inset !important;
  box-shadow: 0 0 0 1000px #231a14 inset !important;
  -webkit-text-fill-color: #fff7f2 !important;
  caret-color: #fff7f2 !important;
  transition: background-color 5000s ease-in-out 0s !important;
}
</style>

<script src="<?= htmlspecialchars(landing_asset_url('assets/js/theme.js'), ENT_QUOTES, 'UTF-8') ?>"></script>

</head>
<body>
<div class="page">
  <div class="blob" style="left:-60px;top:80px;width:300px;height:260px;background:#FBF1D5"></div>
  <div class="blob" style="right:-80px;top:300px;width:240px;height:300px;background:#FDE6D8"></div>
  <div class="blob" style="left:60px;top:880px;width:300px;height:300px;background:#FBF1D0"></div>
  <div class="blob" style="right:-120px;top:1040px;width:280px;height:280px;background:#F4E7D8"></div>
  <div class="blob" style="left:330px;top:1520px;width:300px;height:260px;background:#F6E9DC"></div>
  <div class="blob" style="right:-60px;top:1760px;width:260px;height:320px;background:#FDE6D8"></div>
  <div class="blob" style="left:-80px;top:1980px;width:260px;height:260px;background:#FBF1D0"></div>

  <nav class="topbar">
    <a class="logo" href="#home"><img id="UniStay" src="<?= htmlspecialchars(landing_asset_url('assets/images/logo.png'), ENT_QUOTES, 'UTF-8') ?>" alt="logo"><span class="logo-name">UniStay</span></a>
    <div class="links"><a href="#home">Home</a><a href="#roles">Roles</a><a href="#features">Features</a></div>
    <div class="actions">
      <button type="button" class="btn-theme-toggle" data-theme-toggle aria-label="Toggle Dark Mode" title="Toggle Dark Mode"></button>
      <?php if (!empty($_SESSION['user_id'])):
        $role = $_SESSION['role'] ?? 'tenant';
        $dashboard = $role === 'landlord' ? 'landlord/dashboard.php'
                  : ($role === 'admin' ? 'admin/dashboard.php' : 'tenant/dorms.php');
      ?>
        <a class="btn primary" href="<?= htmlspecialchars($dashboard, ENT_QUOTES, 'UTF-8') ?>">Proceed &rarr;</a>
      <?php else: ?>
        <a class="loginbtn" href="auth/login.php">Log in</a>
        <a class="btn" href="auth/register.php">Sign up</a>
      <?php endif; ?>
    </div>
  </nav>

  <header class="feat feature-hero" id="home">
    <div class="feat-bg">
      <span class="feat-orb o1"></span>
      <span class="feat-orb o2"></span>
      <span class="feat-orb o3"></span>
      <span class="feat-ring r1"></span>
      <span class="feat-ring r2"></span>
      <span class="feat-ring r3"></span>

      <span class="feat-chip c1" role="img" aria-label="Smart Room Search"><span class="feat-icon-float"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7.5"/><path d="m20.5 20.5-4.2-4.2"/></svg></span></span>
      <span class="feat-chip c2" role="img" aria-label="Interactive Map"><span class="feat-icon-float"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 5.5-8 11-8 11s-8-5.5-8-11a8 8 0 1 1 16 0z"/><circle cx="12" cy="10" r="2.5"/></svg></span></span>
      <span class="feat-chip c3" role="img" aria-label="Booking Manager"><span class="feat-icon-float"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 5.5A2.5 2.5 0 0 1 6 3h5v17H6a2.5 2.5 0 0 0-2.5 2z"/><path d="M20.5 5.5A2.5 2.5 0 0 0 18 3h-5v17h5a2.5 2.5 0 0 1 2.5 2z"/></svg></span></span>
      <span class="feat-chip c4" role="img" aria-label="Property Listings"><span class="feat-icon-float"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 9-7 9 7"/><path d="M5 10v10h14V10M9 20v-6h6v6"/></svg></span></span>
      <span class="feat-chip c5" role="img" aria-label="Document Verification"><span class="feat-icon-float"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="m9 15 2 2 4-4"/></svg></span></span>
      <span class="feat-chip c6" role="img" aria-label="Instant Notifications"><span class="feat-icon-float"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M14 21h-4"/></svg></span></span>
    </div>

    <div class="feat-copy">
      <div class="feat-brand">
        <img src="<?= htmlspecialchars(landing_asset_url('assets/images/logo.png'), ENT_QUOTES, 'UTF-8') ?>" width="44" height="44" alt="">
        <span>UniStay</span>
      </div>
      <h1>Live. Study. Thrive.</h1>
      <p>Smart search, a live map, booking management, property listings, document verification, and instant notifications &mdash; all working together so students and landlords can focus on what matters.</p>
      <div class="feat-actions">
        <a class="btn primary" href="tenant/dorms.php">Find Room</a>
        <button type="button" class="btn landlord-application-cta" data-open-landlord-form>List a Property</button>
      </div>
    </div>
  </header>

  <section class="sec">
    <h2 id="roles">Built for every role.</h2>
    <p class="desc">Whether you are looking for a place to stay or listing a property, UniStay has tailored tools for tenants and landlords alike.</p>
    <div class="tabs" role="tablist">
      <span class="tab-indicator" aria-hidden="true"></span>
      <button class="tab active" data-t="tenant" role="tab">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 2 9 2 12 0v-5"/></svg>Tenant Experience</button>
      <button class="tab" data-t="landlord" role="tab">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21V10l6-4 6 4v11M15 21V13h6v8M2 21h20"/></svg>Landlord Management</button>
    </div>
    <div class="panel" id="tenant"><img src="<?= htmlspecialchars(landing_asset_url('assets/images/tenant.png'), ENT_QUOTES, 'UTF-8') ?>" alt="Tenant dashboard view"></div>
    <div class="panel" id="landlord" hidden><img src="<?= htmlspecialchars(landing_asset_url('assets/images/landlord.png'), ENT_QUOTES, 'UTF-8') ?>" alt="Landlord dashboard view"></div>
  </section>

  <div class="hero-wrap photo-section">
    <section class="hero" id="features" aria-labelledby="hc-title">
      <div class="hero-copy" id="hero-copy" aria-live="polite">
        <span class="hero-kicker swap" id="hc-kicker">01 &middot; Browse Listings</span>
        <h2 class="swap" id="hc-title">Dorm Gallery</h2>
        <p class="swap" id="hc-desc">Browse off-campus dorms with real photos, transparent rates, and trusted landlord profiles.</p>
        <div class="hero-progress">
          <span class="hero-count" id="hc-count">01 / 07</span>
          <span class="hero-bars" id="hc-bars" aria-hidden="true"></span>
        </div>
      </div>

      <div class="stack" id="stack">
        <div class="sq" style="--n:1;--ratio:<?= $landingPhotos['01']['ratio'] ?>" data-kicker="Browse Listings" data-title="Dorm Gallery"
            data-desc="Browse off-campus dorms with real photos, transparent rates, and trusted landlord profiles."
            data-cta="Browse Dorms" data-href="tenant/dorms.php">
          <picture class="sq-img"><?php if ($landingPhotos['01']['webp'] !== null): ?><source srcset="<?= htmlspecialchars($landingPhotos['01']['webp'], ENT_QUOTES, 'UTF-8') ?>" type="image/webp"><?php endif; ?><img src="<?= htmlspecialchars($landingPhotos['01']['png'], ENT_QUOTES, 'UTF-8') ?>" width="<?= $landingPhotos['01']['width'] ?>" height="<?= $landingPhotos['01']['height'] ?>" alt="UniStay dorm gallery interface" loading="eager" decoding="async" fetchpriority="high"></picture>
          <div class="sq-overlay"></div><div class="sq-badge"><span class="sq-dot"></span>Dorm Gallery</div>
        </div>
        <div class="sq" style="--n:2;--ratio:<?= $landingPhotos['02']['ratio'] ?>" data-kicker="Direct Contact" data-title="Messaging Made Easy"
            data-desc="See your landlord's details at a glance and reach out directly without the back-and-forth."
            data-cta="Find a Dorm" data-href="tenant/dorms.php">
          <picture class="sq-img"><?php if ($landingPhotos['02']['webp'] !== null): ?><source srcset="<?= htmlspecialchars($landingPhotos['02']['webp'], ENT_QUOTES, 'UTF-8') ?>" type="image/webp"><?php endif; ?><img src="<?= htmlspecialchars($landingPhotos['02']['png'], ENT_QUOTES, 'UTF-8') ?>" width="<?= $landingPhotos['02']['width'] ?>" height="<?= $landingPhotos['02']['height'] ?>" alt="UniStay landlord contact interface" loading="eager" decoding="async"></picture>
          <div class="sq-overlay"></div><div class="sq-badge"><span class="sq-dot"></span>Landlord Details</div>
        </div>
        <div class="sq" style="--n:3;--ratio:<?= $landingPhotos['03']['ratio'] ?>" data-kicker="Tenant Dashboard" data-title="Up-to-Date Cards"
            data-desc="Your current booking card always shows the latest status, dates, and room details."
            data-cta="Book a Room" data-href="tenant/dorms.php">
          <picture class="sq-img"><?php if ($landingPhotos['03']['webp'] !== null): ?><source srcset="<?= htmlspecialchars($landingPhotos['03']['webp'], ENT_QUOTES, 'UTF-8') ?>" type="image/webp"><?php endif; ?><img src="<?= htmlspecialchars($landingPhotos['03']['png'], ENT_QUOTES, 'UTF-8') ?>" width="<?= $landingPhotos['03']['width'] ?>" height="<?= $landingPhotos['03']['height'] ?>" alt="UniStay tenant booking card" loading="lazy" decoding="async"></picture>
          <div class="sq-overlay"></div><div class="sq-badge"><span class="sq-dot"></span>Current Booking</div>
        </div>
        <div class="sq" style="--n:4;--ratio:<?= $landingPhotos['04']['ratio'] ?>" data-kicker="Campus Landmarks" data-title="Interactive Map"
            data-desc="Explore dorms and landmarks on a live map with pin markers near universities in Dagupan."
            data-cta="Open the Map" data-href="tenant/map.php">
          <picture class="sq-img"><?php if ($landingPhotos['04']['webp'] !== null): ?><source srcset="<?= htmlspecialchars($landingPhotos['04']['webp'], ENT_QUOTES, 'UTF-8') ?>" type="image/webp"><?php endif; ?><img src="<?= htmlspecialchars($landingPhotos['04']['png'], ENT_QUOTES, 'UTF-8') ?>" width="<?= $landingPhotos['04']['width'] ?>" height="<?= $landingPhotos['04']['height'] ?>" alt="UniStay interactive map" loading="lazy" decoding="async"></picture>
          <div class="sq-overlay"></div><div class="sq-badge"><span class="sq-dot"></span>Interactive Map</div>
        </div>
        <div class="sq" style="--n:5;--ratio:<?= $landingPhotos['05']['ratio'] ?>" data-kicker="Landlord Dashboard" data-title="Keep Track of Bookings"
            data-desc="Landlords see recent bookings at a glance and stay on top of every tenant's status."
            data-cta="Apply as Landlord" data-href="#landlord-application">
          <picture class="sq-img"><?php if ($landingPhotos['05']['webp'] !== null): ?><source srcset="<?= htmlspecialchars($landingPhotos['05']['webp'], ENT_QUOTES, 'UTF-8') ?>" type="image/webp"><?php endif; ?><img src="<?= htmlspecialchars($landingPhotos['05']['png'], ENT_QUOTES, 'UTF-8') ?>" width="<?= $landingPhotos['05']['width'] ?>" height="<?= $landingPhotos['05']['height'] ?>" alt="UniStay landlord bookings tracker" loading="lazy" decoding="async"></picture>
          <div class="sq-overlay"></div><div class="sq-badge"><span class="sq-dot"></span>Recent Bookings</div>
        </div>
        <div class="sq" style="--n:6;--ratio:<?= $landingPhotos['06']['ratio'] ?>" data-kicker="Landlord Tools" data-title="Property Addition"
            data-desc="Add a new property in minutes with a simple, guided details form."
            data-cta="List a Property" data-href="#landlord-application">
          <picture class="sq-img"><?php if ($landingPhotos['06']['webp'] !== null): ?><source srcset="<?= htmlspecialchars($landingPhotos['06']['webp'], ENT_QUOTES, 'UTF-8') ?>" type="image/webp"><?php endif; ?><img src="<?= htmlspecialchars($landingPhotos['06']['png'], ENT_QUOTES, 'UTF-8') ?>" width="<?= $landingPhotos['06']['width'] ?>" height="<?= $landingPhotos['06']['height'] ?>" alt="UniStay property listing form" loading="lazy" decoding="async"></picture>
          <div class="sq-overlay"></div><div class="sq-badge"><span class="sq-dot"></span>Add a Property</div>
        </div>
        <div class="sq" style="--n:7;--ratio:<?= $landingPhotos['07']['ratio'] ?>" data-kicker="Landlord Hub" data-title="Request Center"
            data-desc="Review tenant requests as they come in and respond from one organized place."
            data-cta="Apply as Landlord" data-href="#landlord-application">
          <picture class="sq-img"><?php if ($landingPhotos['07']['webp'] !== null): ?><source srcset="<?= htmlspecialchars($landingPhotos['07']['webp'], ENT_QUOTES, 'UTF-8') ?>" type="image/webp"><?php endif; ?><img src="<?= htmlspecialchars($landingPhotos['07']['png'], ENT_QUOTES, 'UTF-8') ?>" width="<?= $landingPhotos['07']['width'] ?>" height="<?= $landingPhotos['07']['height'] ?>" alt="UniStay tenant request center" loading="lazy" decoding="async"></picture>
          <div class="sq-overlay"></div><div class="sq-badge"><span class="sq-dot"></span>Tenant Requests</div>
        </div>
      </div>
    </section>
  </div>
</div>

<dialog id="landlord-dialog" class="apply-dialog" aria-labelledby="apply-title">
  <form id="landlord-form" enctype="multipart/form-data" novalidate>
    <button type="button" class="apply-close" data-close-landlord-form aria-label="Close">&times;</button>
    <div class="apply-columns">
      <!-- Left Column: UniStay Brown Gradient Card Theme -->
      <div class="apply-col apply-ws-left">
        <div class="apply-left-header">
          <h2 id="apply-title" class="apply-ws-title">Apply as a Landlord</h2>
          <h3 class="apply-ws-subtitle">Personal Details</h3>
        </div>
        <div class="apply-left-fields">
          <div class="la-field">
            <label class="la-label" for="la-first-name">First Name *</label>
            <input id="la-first-name" class="la-input" name="first_name" required maxlength="100" autocomplete="given-name">
          </div>
          <div class="la-field">
            <label class="la-label" for="la-middle-name">Middle Name</label>
            <input id="la-middle-name" class="la-input" name="middle_name" maxlength="100" autocomplete="additional-name">
          </div>
          <div class="la-field">
            <label class="la-label" for="la-last-name">Last Name *</label>
            <input id="la-last-name" class="la-input" name="last_name" required maxlength="100" autocomplete="family-name">
          </div>
          <div class="la-field">
            <label class="la-label" for="la-address">Residential Address *</label>
            <input id="la-address" class="la-input" name="residential_address" required maxlength="255" autocomplete="street-address">
          </div>
          <div class="la-field">
            <label class="la-label" for="la-email">Email Address *</label>
            <input id="la-email" class="la-input" type="email" name="email" required maxlength="190" autocomplete="email">
          </div>
          <div class="la-field">
            <label class="la-label" for="la-phone">Phone Number *</label>
            <div class="la-prefix-wrapper">
              <span class="la-prefix-addon">+63</span>
              <input id="la-phone" class="la-input" type="tel" inputmode="numeric" maxlength="10" autocomplete="tel-national" placeholder="9XXXXXXXXX" required>
            </div>
            <input type="hidden" name="phone" id="la-phone-full">
          </div>
          <div class="la-field">
            <label class="la-label" for="la-facebook">Facebook Profile Link *</label>
            <div class="la-prefix-wrapper">
              <span class="la-prefix-addon">facebook.com/</span>
              <input id="la-facebook" class="la-input" name="facebook_link" required maxlength="255" placeholder="username or profile.id">
            </div>
          </div>
        </div>
      </div>

      <!-- Right Column: Experience, Gov ID, & Agreement -->
      <div class="apply-col apply-ws-right">
        <div class="apply-right-header">
          <h2 class="apply-ws-title">Experience &amp; Verification</h2>
          <h3 class="apply-ws-subtitle">Credentials &amp; History</h3>
        </div>
        <div class="apply-right-fields">
          <div class="la-field apply-experience">
            <label class="la-label" for="la-experience">Rental or Management Experience *</label>
            <textarea id="la-experience" class="la-input la-textarea" name="experience_summary" rows="3" required minlength="30" maxlength="1500" placeholder="Briefly describe your rental experience and how you will responsibly support student tenants."></textarea>
          </div>
          <div class="la-field apply-upload">
            <label class="la-label" for="la-gov-id">Government-issued ID *</label>
            <div class="la-dropzone" id="la-dropzone" role="button" tabindex="0" aria-label="Upload government-issued ID">
              <div id="la-dz-empty">
                <img id="la-dz-preview" class="la-dz-preview-img" alt="Selected government ID" hidden>
                <svg class="la-dz-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                <div id="la-dz-name" class="la-dz-filename" aria-live="polite">No file chosen</div>
                <div class="la-dz-hint">Drag and drop, or browse</div>
                <div class="la-dz-btn-row"><button type="button" class="la-dz-btn">Upload File</button></div>
              </div>
              <input type="file" id="la-gov-id" class="la-file-input" name="government_id" accept="image/jpeg,image/png,image/webp" required>
            </div>
          </div>

        </div>
        <p id="apply-msg" class="apply-msg" role="status"></p>
        <label class="apply-agreement">
          <input type="checkbox" name="responsibility_acknowledged" value="1" required>
          <span>I agree to provide safe and habitable housing, disclose fees honestly, publish truthful listings, treat applicants fairly, and follow UniStay policies.</span>
        </label>
        <div class="apply-modal-footer">
          <div class="apply-actions">
            <button type="button" class="btn btn-apply-cancel" data-close-landlord-form>Cancel</button>
            <button type="submit" class="btn primary" id="apply-btn">Submit Application</button>
          </div>
        </div>
      </div>
    </div>

    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['landlord_application_csrf'], ENT_QUOTES, 'UTF-8') ?>">
    <input type="text" name="website" tabindex="-1" autocomplete="off" class="apply-hp" aria-hidden="true">
  </form>
</dialog>

<footer>
  <div class="f">
    <div>
      <a class="logo" href="index.php"><img id="UniStay" src="<?= htmlspecialchars(landing_asset_url('assets/images/logo.png'), ENT_QUOTES, 'UTF-8') ?>" style="width: 54px; height: 54px;" alt="logo">UniStay</a>
      <p>UniStay is the simplest way for students and landlords to connect, book, and manage off-campus housing.</p>
      <div class="news">
        <b>Stay connected!</b>
        <form id="sub" novalidate>
          <input type="text" name="website" id="sub-website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;">
          <input type="email" name="email" id="sub-email" placeholder="Enter your email" aria-label="Email" required>
          <button id="sub-btn" type="submit">Subscribe</button>
        </form>
        <span id="sub-msg" class="news-msg" role="status"></span>
      </div>
    </div>
    <div><h4>Product</h4><ul>
      <li><a href="pages/room-finder.php">Room Finder</a></li>
      <li><a href="pages/property-lister.php">Property Lister</a></li>
      <li><a href="pages/tenant-dashboard.php">Tenant Dashboard</a></li>
      <li><a href="pages/landlord-dashboard.php">Landlord Dashboard</a></li>
      <li><a href="pages/features.php">Features</a></li>
    </ul></div>
    <div><h4>Company</h4><ul>
      <li><a href="pages/about-us.php">About Us</a></li>
      <li><a href="pages/our-mission.php">Our Mission</a></li>
      <li><a href="pages/contact-us.php">Contact Us</a></li>
    </ul></div>
    <div><h4>Legal</h4><ul>
      <li><a href="pages/privacy-policy.php">Privacy Policy</a></li>
      <li><a href="pages/terms-of-service.php">Terms of Service</a></li>
      <li><a href="pages/terms-of-residence.php">Terms of Residence</a></li>
      <li><a href="pages/code-of-conduct.php">Code of Conduct</a></li>
      <li><a href="pages/community-guidelines.php">Community Guidelines</a></li>
    </ul></div>
  </div>
  <div class="copy">© 2026 UniStay. Developed by Code of Duty. All Rights Reserved.</div>
</footer>

<div id="landing-toast" class="notification-toast" role="status" aria-live="polite" aria-atomic="true">
  <div class="toast-header">
    <strong id="landing-toast-title">Subscribed! 🎉</strong>
    <button type="button" class="toast-close-btn" id="landing-toast-close" aria-label="Close notification">&times;</button>
  </div>
  <span id="landing-toast-msg">Subscribed! We will keep you posted.</span>
</div>

<div id="landlord-apply-toast" class="notification-toast" role="status" aria-live="polite" aria-atomic="true">
  <div class="toast-header">
    <strong id="landlord-apply-toast-title">Application Sent! 🎉</strong>
    <button type="button" class="toast-close-btn" id="landlord-apply-toast-close" aria-label="Close notification">&times;</button>
  </div>
  <span id="landlord-apply-toast-msg">We will email you once the admin has reviewed your application.</span>
</div>

<script src="<?= htmlspecialchars(landing_asset_url('assets/js/page-transition.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(landing_asset_url('assets/js/landing-page.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(landing_asset_url('assets/js/landlord-apply.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
