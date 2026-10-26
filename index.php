<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>UniStay · Student Housing & Dormitory Finder</title>
<link rel="icon" type="image/png" href="assets/images/tenant/logo.png">
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
      <a class="btn" href="auth/register.php">Sign up</a>
    </div>
  </nav>

  <div class="hero-wrap">
    <header class="hero" id="home">
      <!-- Main system elements showcased in animated queue.
           Screenshots live in assets/images/ (01.png ... 08.png) -->
      <div class="sq s1" data-element="gallery">
        <div class="sq-img" style="background-image: url('assets/images/01.png');"></div>
        <div class="sq-overlay"></div>
        <div class="sq-badge"><span class="sq-dot"></span>Dorm Gallery</div>
        <div class="sq-meta">
          <span class="sq-kicker">Browse Listings</span>
          <h4 class="sq-title">Dorm Gallery</h4>
          <div class="sq-chips">
            <span>Real Photos</span>
            <span>Verified Dorms</span>
          </div>
        </div>
      </div>

      <div class="sq s2" data-element="messaging">
        <div class="sq-img" style="background-image: url('assets/images/02.png');"></div>
        <div class="sq-overlay"></div>
        <div class="sq-badge"><span class="sq-dot"></span>Landlord Details</div>
        <div class="sq-meta">
          <span class="sq-kicker">Direct Contact</span>
          <h4 class="sq-title">Messaging Made Easy</h4>
          <div class="sq-chips">
            <span>Landlord Info</span>
            <span>Quick Reach</span>
          </div>
        </div>
      </div>

      <div class="sq s3" data-element="cards">
        <div class="sq-img" style="background-image: url('assets/images/03.png');"></div>
        <div class="sq-overlay"></div>
        <div class="sq-badge"><span class="sq-dot"></span>Current Booking</div>
        <div class="sq-meta">
          <span class="sq-kicker">Tenant Dashboard</span>
          <h4 class="sq-title">Up-to-Date Cards</h4>
          <div class="sq-chips">
            <span>Live Status</span>
            <span>Move-In Dates</span>
          </div>
        </div>
      </div>

      <div class="sq s4" data-element="map">
        <div class="sq-img" style="background-image: url('assets/images/04.png');"></div>
        <div class="sq-overlay"></div>
        <div class="sq-badge"><span class="sq-dot"></span>Interactive Map</div>
        <div class="sq-meta">
          <span class="sq-kicker">Campus Landmarks</span>
          <h4 class="sq-title">Interactive Map</h4>
          <div class="sq-chips">
            <span>Pin Markers</span>
            <span>Live Pins</span>
          </div>
        </div>
      </div>

      <div class="sq s5" data-element="bookings">
        <div class="sq-img" style="background-image: url('assets/images/05.png');"></div>
        <div class="sq-overlay"></div>
        <div class="sq-badge"><span class="sq-dot"></span>Recent Bookings</div>
        <div class="sq-meta">
          <span class="sq-kicker">Landlord Dashboard</span>
          <h4 class="sq-title">Keep Track of Bookings</h4>
          <div class="sq-chips">
            <span>Booking Overview</span>
            <span>Tenant Status</span>
          </div>
        </div>
      </div>

      <div class="sq s6" data-element="property">
        <div class="sq-img" style="background-image: url('assets/images/06.png');"></div>
        <div class="sq-overlay"></div>
        <div class="sq-badge"><span class="sq-dot"></span>Add a Property</div>
        <div class="sq-meta">
          <span class="sq-kicker">Landlord Tools</span>
          <h4 class="sq-title">Property Addition</h4>
          <div class="sq-chips">
            <span>Simple Form</span>
            <span>List in Minutes</span>
          </div>
        </div>
      </div>

      <div class="sq s7" data-element="requests">
        <div class="sq-img" style="background-image: url('assets/images/07.png');"></div>
        <div class="sq-overlay"></div>
        <div class="sq-badge"><span class="sq-dot"></span>Tenant Requests</div>
        <div class="sq-meta">
          <span class="sq-kicker">Landlord Hub</span>
          <h4 class="sq-title">Request Center</h4>
          <div class="sq-chips">
            <span>Review Requests</span>
            <span>Approve Fast</span>
          </div>
        </div>
      </div>

      <div class="sq s8" data-element="verification">
        <div class="sq-img" style="background-image: url('assets/images/08.png');"></div>
        <div class="sq-overlay"></div>
        <div class="sq-badge"><span class="sq-dot"></span>Admin Review</div>
        <div class="sq-meta">
          <span class="sq-kicker">Safe Community</span>
          <h4 class="sq-title">Verification Center</h4>
          <div class="sq-chips">
            <span>Tenant Docs</span>
            <span>Landlord Docs</span>
          </div>
        </div>
      </div>

      <h1>Student housing, simplified for<br>everyone.</h1>
      <p>UniStay brings students and landlords together in one place &mdash; search and compare verified listings, list a property in minutes, and manage bookings and updates from a single dashboard.</p>
      <div class="ctas"><a class="btn primary" href="tenant/dorms.php">Find Room</a><a class="btn soft" href="auth/register.php?type=landlord">List a Property</a></div>

      <!-- Brown Hanging Luggage Tag with Synchronized Descriptions (01 to 08, then loops back to 01) -->
      <div class="tag">
        <div class="tag-bob">
          <div class="tag-slide s1">
            <span class="tag-kicker">01 &middot; GALLERY</span>
            <h3 class="tag-title">Dorm Gallery</h3>
            <p class="tag-desc">Browse off-campus dorms with real photos, transparent rates, and trusted landlord profiles.</p>
            <span class="tag-pill">Verified Listings</span>
          </div>
          <div class="tag-slide s2">
            <span class="tag-kicker">02 &middot; MESSAGING</span>
            <h3 class="tag-title">Messaging Made Easy</h3>
            <p class="tag-desc">See your landlord's details at a glance and reach out directly without the back-and-forth.</p>
            <span class="tag-pill">Direct Contact</span>
          </div>
          <div class="tag-slide s3">
            <span class="tag-kicker">03 &middot; UPDATES</span>
            <h3 class="tag-title">Up-to-Date Cards</h3>
            <p class="tag-desc">Your current booking card always shows the latest status, dates, and room details.</p>
            <span class="tag-pill">Live Status</span>
          </div>
          <div class="tag-slide s4">
            <span class="tag-kicker">04 &middot; MAP</span>
            <h3 class="tag-title">Interactive Map</h3>
            <p class="tag-desc">Explore dorms and landmarks on a live map with pin markers near universities in Dagupan.</p>
            <span class="tag-pill">Live Navigation</span>
          </div>
          <div class="tag-slide s5">
            <span class="tag-kicker">05 &middot; BOOKINGS</span>
            <h3 class="tag-title">Keep Track of Bookings</h3>
            <p class="tag-desc">Landlords see recent bookings at a glance and stay on top of every tenant's status.</p>
            <span class="tag-pill">Full Overview</span>
          </div>
          <div class="tag-slide s6">
            <span class="tag-kicker">06 &middot; PROPERTIES</span>
            <h3 class="tag-title">Property Addition</h3>
            <p class="tag-desc">Add a new property in minutes with a simple, guided details form.</p>
            <span class="tag-pill">Quick Listing</span>
          </div>
          <div class="tag-slide s7">
            <span class="tag-kicker">07 &middot; REQUESTS</span>
            <h3 class="tag-title">Request Center</h3>
            <p class="tag-desc">Review tenant requests as they come in and respond from one organized place.</p>
            <span class="tag-pill">Fast Approval</span>
          </div>
          <div class="tag-slide s8">
            <span class="tag-kicker">08 &middot; VERIFICATION</span>
            <h3 class="tag-title">Verification Center</h3>
            <p class="tag-desc">Admins review submitted tenant and landlord documents to keep the community safe.</p>
            <span class="tag-pill">Trusted Community</span>
          </div>
        </div>
      </div>
      <div class="ring"><div class="tag-bob"></div></div><div class="dot1"><div class="tag-bob"></div></div><div class="dot2"><div class="tag-bob"></div></div>
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
    <div class="panel" id="tenant"><img src="assets/images/tenant.png" alt="Tenant dashboard view"></div>
    <div class="panel" id="landlord" hidden><img src="assets/images/landlord.png" alt="Landlord dashboard view"></div>
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
      <li><a href="pages/terms-of-residence.php">Terms of Residence</a></li>
      <li><a href="pages/code-of-conduct.php">Code of Conduct</a></li>
      <li><a href="pages/community-guidelines.php">Community Guidelines</a></li>
    </ul></div>
  </div>
  <div class="copy">© 2026 UniStay. Developed by Code of Duty. All Rights Reserved.</div>
</footer>

<script src="assets/js/page-transition.js"></script>
<script src="assets/js/landing-page.js"></script>
</body>
</html>