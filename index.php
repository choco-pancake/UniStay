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
for ($landingPhotoIndex = 1; $landingPhotoIndex <= 8; $landingPhotoIndex++) {
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
<link rel="stylesheet" href="<?= htmlspecialchars(landing_asset_url('assets/css/topbar.css'), ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(landing_asset_url('assets/css/landingpage.css'), ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(landing_asset_url('assets/css/landlord-apply-fields.css'), ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(landing_asset_url('assets/css/page-transition.css'), ENT_QUOTES, 'UTF-8') ?>">

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
      <a class="loginbtn" href="auth/login.php">Log in</a>
      <a class="btn" href="auth/register.php">Sign up</a>
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
        <a class="btn primary swap" id="hc-cta" href="tenant/dorms.php">Browse Dorms</a>
        <div class="hero-progress">
          <span class="hero-count" id="hc-count">01 / 08</span>
          <span class="hero-bars" id="hc-bars" aria-hidden="true"></span>
          <span class="hero-hint">Click the photo to see the next</span>
        </div>
      </div>

      <div class="stack" id="stack">
        <div class="sq" style="--n:1;--ratio:<?= $landingPhotos['01']['ratio'] ?>" data-kicker="Browse Listings" data-title="Dorm Gallery"
            data-desc="Browse off-campus dorms with real photos, transparent rates, and trusted landlord profiles."
            data-cta="Browse Dorms" data-href="tenant/dorms.php">
          <picture class="sq-img"><?php if ($landingPhotos['01']['webp'] !== null): ?><source srcset="<?= htmlspecialchars($landingPhotos['01']['webp'], ENT_QUOTES, 'UTF-8') ?>" type="image/webp"><?php endif; ?><img src="<?= htmlspecialchars($landingPhotos['01']['png'], ENT_QUOTES, 'UTF-8') ?>" width="<?= $landingPhotos['01']['width'] ?>" height="<?= $landingPhotos['01']['height'] ?>" alt="UniStay dorm gallery interface" loading="eager" decoding="async" fetchpriority="high"></picture>
          <div class="sq-overlay"></div><div class="sq-badge"><span class="sq-dot"></span>Dorm Gallery</div><span class="sq-num">01</span>
        </div>
        <div class="sq" style="--n:2;--ratio:<?= $landingPhotos['02']['ratio'] ?>" data-kicker="Direct Contact" data-title="Messaging Made Easy"
            data-desc="See your landlord's details at a glance and reach out directly without the back-and-forth."
            data-cta="Find a Dorm" data-href="tenant/dorms.php">
          <picture class="sq-img"><?php if ($landingPhotos['02']['webp'] !== null): ?><source srcset="<?= htmlspecialchars($landingPhotos['02']['webp'], ENT_QUOTES, 'UTF-8') ?>" type="image/webp"><?php endif; ?><img src="<?= htmlspecialchars($landingPhotos['02']['png'], ENT_QUOTES, 'UTF-8') ?>" width="<?= $landingPhotos['02']['width'] ?>" height="<?= $landingPhotos['02']['height'] ?>" alt="UniStay landlord contact interface" loading="eager" decoding="async"></picture>
          <div class="sq-overlay"></div><div class="sq-badge"><span class="sq-dot"></span>Landlord Details</div><span class="sq-num">02</span>
        </div>
        <div class="sq" style="--n:3;--ratio:<?= $landingPhotos['03']['ratio'] ?>" data-kicker="Tenant Dashboard" data-title="Up-to-Date Cards"
            data-desc="Your current booking card always shows the latest status, dates, and room details."
            data-cta="Book a Room" data-href="tenant/dorms.php">
          <picture class="sq-img"><?php if ($landingPhotos['03']['webp'] !== null): ?><source srcset="<?= htmlspecialchars($landingPhotos['03']['webp'], ENT_QUOTES, 'UTF-8') ?>" type="image/webp"><?php endif; ?><img src="<?= htmlspecialchars($landingPhotos['03']['png'], ENT_QUOTES, 'UTF-8') ?>" width="<?= $landingPhotos['03']['width'] ?>" height="<?= $landingPhotos['03']['height'] ?>" alt="UniStay tenant booking card" loading="lazy" decoding="async"></picture>
          <div class="sq-overlay"></div><div class="sq-badge"><span class="sq-dot"></span>Current Booking</div><span class="sq-num">03</span>
        </div>
        <div class="sq" style="--n:4;--ratio:<?= $landingPhotos['04']['ratio'] ?>" data-kicker="Campus Landmarks" data-title="Interactive Map"
            data-desc="Explore dorms and landmarks on a live map with pin markers near universities in Dagupan."
            data-cta="Open the Map" data-href="tenant/map.php">
          <picture class="sq-img"><?php if ($landingPhotos['04']['webp'] !== null): ?><source srcset="<?= htmlspecialchars($landingPhotos['04']['webp'], ENT_QUOTES, 'UTF-8') ?>" type="image/webp"><?php endif; ?><img src="<?= htmlspecialchars($landingPhotos['04']['png'], ENT_QUOTES, 'UTF-8') ?>" width="<?= $landingPhotos['04']['width'] ?>" height="<?= $landingPhotos['04']['height'] ?>" alt="UniStay interactive map" loading="lazy" decoding="async"></picture>
          <div class="sq-overlay"></div><div class="sq-badge"><span class="sq-dot"></span>Interactive Map</div><span class="sq-num">04</span>
        </div>
        <div class="sq" style="--n:5;--ratio:<?= $landingPhotos['05']['ratio'] ?>" data-kicker="Landlord Dashboard" data-title="Keep Track of Bookings"
            data-desc="Landlords see recent bookings at a glance and stay on top of every tenant's status."
            data-cta="Apply as Landlord" data-href="#landlord-application">
          <picture class="sq-img"><?php if ($landingPhotos['05']['webp'] !== null): ?><source srcset="<?= htmlspecialchars($landingPhotos['05']['webp'], ENT_QUOTES, 'UTF-8') ?>" type="image/webp"><?php endif; ?><img src="<?= htmlspecialchars($landingPhotos['05']['png'], ENT_QUOTES, 'UTF-8') ?>" width="<?= $landingPhotos['05']['width'] ?>" height="<?= $landingPhotos['05']['height'] ?>" alt="UniStay landlord bookings tracker" loading="lazy" decoding="async"></picture>
          <div class="sq-overlay"></div><div class="sq-badge"><span class="sq-dot"></span>Recent Bookings</div><span class="sq-num">05</span>
        </div>
        <div class="sq" style="--n:6;--ratio:<?= $landingPhotos['06']['ratio'] ?>" data-kicker="Landlord Tools" data-title="Property Addition"
            data-desc="Add a new property in minutes with a simple, guided details form."
            data-cta="List a Property" data-href="#landlord-application">
          <picture class="sq-img"><?php if ($landingPhotos['06']['webp'] !== null): ?><source srcset="<?= htmlspecialchars($landingPhotos['06']['webp'], ENT_QUOTES, 'UTF-8') ?>" type="image/webp"><?php endif; ?><img src="<?= htmlspecialchars($landingPhotos['06']['png'], ENT_QUOTES, 'UTF-8') ?>" width="<?= $landingPhotos['06']['width'] ?>" height="<?= $landingPhotos['06']['height'] ?>" alt="UniStay property listing form" loading="lazy" decoding="async"></picture>
          <div class="sq-overlay"></div><div class="sq-badge"><span class="sq-dot"></span>Add a Property</div><span class="sq-num">06</span>
        </div>
        <div class="sq" style="--n:7;--ratio:<?= $landingPhotos['07']['ratio'] ?>" data-kicker="Landlord Hub" data-title="Request Center"
            data-desc="Review tenant requests as they come in and respond from one organized place."
            data-cta="Apply as Landlord" data-href="#landlord-application">
          <picture class="sq-img"><?php if ($landingPhotos['07']['webp'] !== null): ?><source srcset="<?= htmlspecialchars($landingPhotos['07']['webp'], ENT_QUOTES, 'UTF-8') ?>" type="image/webp"><?php endif; ?><img src="<?= htmlspecialchars($landingPhotos['07']['png'], ENT_QUOTES, 'UTF-8') ?>" width="<?= $landingPhotos['07']['width'] ?>" height="<?= $landingPhotos['07']['height'] ?>" alt="UniStay tenant request center" loading="lazy" decoding="async"></picture>
          <div class="sq-overlay"></div><div class="sq-badge"><span class="sq-dot"></span>Tenant Requests</div><span class="sq-num">07</span>
        </div>
        <div class="sq" style="--n:8;--ratio:<?= $landingPhotos['08']['ratio'] ?>" data-kicker="Safe Community" data-title="Verification Center"
            data-desc="Admins review submitted tenant and landlord documents to keep the community safe."
            data-cta="See Features" data-href="pages/features.php">
          <picture class="sq-img"><?php if ($landingPhotos['08']['webp'] !== null): ?><source srcset="<?= htmlspecialchars($landingPhotos['08']['webp'], ENT_QUOTES, 'UTF-8') ?>" type="image/webp"><?php endif; ?><img src="<?= htmlspecialchars($landingPhotos['08']['png'], ENT_QUOTES, 'UTF-8') ?>" width="<?= $landingPhotos['08']['width'] ?>" height="<?= $landingPhotos['08']['height'] ?>" alt="UniStay document verification center" loading="lazy" decoding="async"></picture>
          <div class="sq-overlay"></div><div class="sq-badge"><span class="sq-dot"></span>Admin Review</div><span class="sq-num">08</span>
        </div>
      </div>
    </section>
  </div>
</div>

<dialog id="landlord-dialog" class="apply-dialog" aria-labelledby="apply-title">
  <form id="landlord-form" enctype="multipart/form-data" novalidate>
    <div class="apply-head">
      <h2 id="apply-title">Apply as a Landlord</h2>
      <button type="button" class="apply-close" data-close-landlord-form aria-label="Close">&times;</button>
    </div>
    <p class="apply-sub">Tell us about yourself and your experience. Property details are added only after your application is approved.</p>

    <div class="apply-grid">
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
  <div class="la-field apply-address">
    <label class="la-label" for="la-address">Residential Address *</label>
    <input id="la-address" class="la-input" name="residential_address" required maxlength="255" autocomplete="street-address">
  </div>
  <div class="la-field apply-experience">
    <label class="la-label" for="la-experience">Rental or Property-Management Experience *</label>
    <textarea id="la-experience" class="la-input la-textarea" name="experience_summary" rows="4" required minlength="30" maxlength="1500" placeholder="Briefly describe your experience and how you will responsibly support student tenants."></textarea>
  </div>

  <div class="la-field apply-upload">
    <label class="la-label" for="la-gov-id">Government-issued ID *</label>
      <div class="la-dropzone" id="la-dropzone" role="button" tabindex="0" aria-label="Upload government-issued ID">
        <div id="la-dz-empty">
          <svg class="la-dz-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
          <div class="la-dz-filename">No file chosen</div>
          <div class="la-dz-hint">Drag and drop your file here, or browse</div>
          <div class="la-dz-btn-row"><button type="button" class="la-dz-btn">Upload File</button></div>
        </div>
        <div id="la-dz-filled" class="la-dz-active" hidden>
          <div class="la-dz-preview-wrap"><img id="la-dz-preview" class="la-dz-preview-img" alt="Government ID preview"></div>
          <div id="la-dz-name" class="la-dz-filename"></div>
          <div class="la-dz-btn-row">
            <button type="button" class="la-dz-btn">Change Photo</button>
            <button type="button" class="la-dz-btn la-dz-btn-danger" id="la-dz-remove">Remove Photo</button>
          </div>
        </div>
        <input type="file" id="la-gov-id" class="la-file-input" name="government_id" accept="image/jpeg,image/png,image/webp" required>
      </div>
      <span class="la-hint">JPG, PNG, or WebP &middot; 10 MB maximum</span>
    </div>

    <label class="full apply-agreement"><input type="checkbox" name="responsibility_acknowledged" value="1" required><span>I agree to provide safe and habitable housing, disclose fees honestly, publish truthful listings, treat applicants fairly, and follow UniStay policies.</span></label>
  </div>

    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['landlord_application_csrf'], ENT_QUOTES, 'UTF-8') ?>">
    <input type="text" name="website" tabindex="-1" autocomplete="off" class="apply-hp" aria-hidden="true">

    <p id="apply-msg" class="apply-msg" role="status"></p>
    <div class="apply-actions">
      <button type="button" class="btn" data-close-landlord-form>Cancel</button>
      <button type="submit" class="btn primary" id="apply-btn">Submit Application</button>
    </div>
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

<script src="<?= htmlspecialchars(landing_asset_url('assets/js/page-transition.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(landing_asset_url('assets/js/landing-page.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(landing_asset_url('assets/js/landlord-apply-fields.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(landing_asset_url('assets/js/landlord-apply.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
