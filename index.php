<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>UniStay</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Fraunces:opsz,wght@9..144,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/topbar.css">
<link rel="stylesheet" href="assets/css/landingpage.css">
<link rel="stylesheet" href="assets/css/page-transition.css">

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
    <a class="logo" href="#home"><img id="UniStay" src="assets/images/logo.png" alt="logo">UniStay</a>
    <div class="links"><a href="#home">Home</a><a href="#roles">Roles</a><a href="#tools">Tools</a></div>
    <div class="actions">
      <a class="loginbtn" href="auth/login.php">Log in</a>
      <a class="btn" href="auth/register.php?type=tenant">Sign up as Tenant</a>
      <a class="btn" href="auth/register.php?type=landlord">Apply as Landlord</a>
    </div>
  </nav>

  <div class="hero-wrap">
    <header class="hero" id="home">
      <div class="sq s1"></div><div class="sq s2"></div><div class="sq s3"></div><div class="sq s4"></div><div class="sq s5"></div><div class="sq s6"></div><div class="sq s7"></div><div class="sq s8"></div>
      <h1>Student housing, simplified for<br>everyone.</h1>
      <p>UniStay brings students and landlords together in one place &mdash; search and compare verified listings, list a property in minutes, and manage bookings and updates from a single dashboard.</p>
      <div class="ctas"><a class="btn primary" href="auth/register.php?type=tenant">Find Room</a><a class="btn soft" href="auth/register.php?type=landlord">List a Property</a></div>
      <div class="tag"><div class="tag-bob"></div></div><div class="ring"><div class="tag-bob"></div></div><div class="dot1"><div class="tag-bob"></div></div><div class="dot2"><div class="tag-bob"></div></div>
    </header>
  </div>

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
    <div class="panel" id="tenant"></div>
    <div class="panel" id="landlord" hidden></div>
  </section>

  <section class="sec cards">
    <h2 id="tools">Everything you need.</h2>
    <p class="desc" style="max-width:420px">Smart tools for search, booking, and management.</p>
    <div class="grid">
      <div class="card">
        <span class="card-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></span>
        <h3>Smart Room Search</h3>
        <p>Filter rooms by price, distance, and amenities.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></span>
        <h3>Interactive Map</h3>
        <p>Explore listings and landmarks on a live map.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span>
        <h3>Booking Manager</h3>
        <p>Request and track bookings in one dashboard.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V10l6-4 6 4v11M15 21V13h6v8M2 21h20"/></svg></span>
        <h3>Property Listings</h3>
        <p>Publish and manage your properties and rooms.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="m9 15 2 2 4-4"/></svg></span>
        <h3>Document Verification</h3>
        <p>Verify IDs and documents securely and fast.</p>
      </div>
      <div class="card">
        <span class="card-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></span>
        <h3>Instant Notifications</h3>
        <p>Stay updated with real-time alerts and updates.</p>
      </div>
    </div>
  </section>
</div>

<footer>
  <div class="f">
    <div>
      <a class="logo" href="index.php"><img id="UniStay" src="assets/images/logo.png" style="width: 54px; height: 54px;" alt="logo">UniStay</a>
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
      <li><a href="pages/terms-of-use.php">Terms of Use</a></li>
      <li><a href="pages/cookie-policy.php">Cookie Policy</a></li>
      <li><a href="pages/community-guidelines.php">Community Guidelines</a></li>
    </ul></div>
  </div>
  <div class="copy">© 2026 UniStay. Developed by Code of Duty. All Rights Reserved.</div>
</footer>

<script src="assets/js/page-transition.js"></script>
<script src="assets/js/landing-page.js"></script>
</body>
</html>