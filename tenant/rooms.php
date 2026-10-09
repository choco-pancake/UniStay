<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#7a4b3a">
<title>Bookings · UniStay</title>
<link rel="stylesheet" href="../assets/css/common.css?v=4">
<link rel="stylesheet" href="../assets/css/bookings.css">
</head>
<body>
<svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs>
<symbol id="bookmark-icon" viewBox="0 0 24 24"><path d="M6 3h12v18l-6-4-6 4Z"/></symbol>
<symbol id="pin-icon" viewBox="0 0 24 24"><path d="M19 9c0 5-7 12-7 12S5 14 5 9a7 7 0 0 1 14 0Z"/><circle cx="12" cy="9" r="2"/></symbol>
<symbol id="people-icon" viewBox="0 0 24 24"><circle cx="9" cy="6" r="3"/><path d="M3 21v-4a6 6 0 0 1 12 0v4M16 3a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 4v4"/></symbol>
<symbol id="chat-icon" viewBox="0 0 24 24"><path d="M3 3h18v14h-7l-5 4v-4H3Z"/><path d="M7 8h10M7 12h6"/></symbol>
<symbol id="cancel-icon" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="m8 8 8 8m-8 0 8-8"/></symbol>
<symbol id="search-icon" viewBox="0 0 24 24"><circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/></symbol>
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
</defs></svg>
<a class="skip" href="#bookings">Skip to bookings</a>
<aside class="sidebar" aria-label="UniStay portal">
<div class="brand"><img src="../assets/images/tenant/logo.png" width="50" height="50" alt=""><span>UniStay</span></div>
<nav aria-label="Main navigation">
<a href="dashboard.php"><svg aria-hidden="true"><use href="#grid-icon"/></svg>Dashboard</a>
<a href="map.php"><svg aria-hidden="true"><use href="#map-icon"/></svg>Map</a>
<a href="rooms.php" aria-current="page"><svg aria-hidden="true"><use href="#booking-icon"/></svg>Bookings</a>
<a href="settings.php"><svg aria-hidden="true"><use href="#settings-icon"/></svg>Settings</a>
</nav>
</aside>
<div class="shell">
<header class="topbar"><h1>Bookings</h1><div class="profile">
<button id="notification-bell" class="bell" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="notification-popover"><svg aria-hidden="true"><use href="#bell-icon"/></svg></button>
<span class="avatar" role="img" aria-label="Profile placeholder"></span>
<section id="notification-popover" class="popover" aria-labelledby="popover-title" hidden>
<h2 id="popover-title">Notifications</h2>
<div class="empty" id="popover-empty"><span class="empty-icon"><svg aria-hidden="true"><use href="#bell-off-icon"/></svg></span><strong>No new notifications</strong><p>We’ll let you know when something arrives.</p></div>
<ul class="notification-list" id="popover-list" aria-label="Unread notifications" hidden></ul>
<a class="view-all" id="view-all" href="notifications.php?from=bookings" aria-label="View all notifications">View all notifications <svg aria-hidden="true"><use href="#arrow-icon"/></svg></a>
</section>
</div></header>
<main id="bookings" tabindex="-1">
<div class="booking-toolbar"><div class="booking-filters" role="group" aria-label="Filter bookings"><button class="booking-filter" type="button" data-filter="all" aria-pressed="true">All Bookings<span class="filter-count">3</span></button><button class="booking-filter" type="button" data-filter="active" aria-pressed="false">Active Stays<span class="filter-count">1</span></button><button class="booking-filter" type="button" data-filter="pending" aria-pressed="false">Pending Approval<span class="filter-count">1</span></button><button class="booking-filter" type="button" data-filter="upcoming" aria-pressed="false">Upcoming Stay<span class="filter-count">1</span></button><button class="booking-filter" type="button" data-filter="saved" aria-pressed="false">Saved<span class="filter-count">0</span></button></div><label class="booking-search"><svg aria-hidden="true"><use href="#search-icon"/></svg><span class="sr-only">Search bookings</span><input id="booking-search" type="search" placeholder="Search..." autocomplete="off"></label></div>
<div class="booking-grid"><article class="booking-card" data-id="active" data-status="active" aria-label="Active Stay booking">
<div class="booking-photo"><span class="booking-status">Active Stay</span><div class="room-label"><span class="room-id">Room ID</span><span>ROOM TYPE</span></div></div>
<div class="booking-body"><h2>Dorm Name</h2><button class="bookmark" type="button" aria-label="Save active stay booking" aria-pressed="false"><svg aria-hidden="true"><use href="#bookmark-icon"/></svg></button>
<div class="booking-meta"><span><svg aria-hidden="true"><use href="#pin-icon"/></svg>Location; near which uni</span><span><svg aria-hidden="true"><use href="#bed-icon"/></svg>Duration of stay</span></div>
<p class="booking-description">Short Description<br>Percentage of students from a certain uni<br>Distance from a certain uni</p>
<div class="booking-price"><strong>₱Price/month</strong><span>₱&nbsp; Deposit</span></div>
<div class="occupants"><svg aria-hidden="true"><use href="#people-icon"/></svg>Occupant no.</div>
<div class="amenities"><span>Amenity</span><span>Amenity</span><span>Amenity</span><span>Amenity</span><span>Amenity</span></div>
<div class="landlord"><span class="landlord-avatar" aria-hidden="true"></span><div><div class="landlord-name">Landlord name <svg aria-label="Verified"><use href="#badge-icon"/></svg></div><div class="landlord-rating">Landlord • 4.9 rating</div></div><span class="verified">VERIFIED</span></div>
<div class="booking-actions"><button class="message-landlord" type="button"><svg aria-hidden="true"><use href="#chat-icon"/></svg>Message Landlord</button><button class="cancel-stay" type="button"><svg aria-hidden="true"><use href="#cancel-icon"/></svg><span>Cancel Stay</span></button></div>
</div></article><article class="booking-card" data-id="pending" data-status="pending" aria-label="Pending Approval booking">
<div class="booking-photo"><span class="booking-status">Pending Approval</span><div class="room-label"><span class="room-id">Room ID</span><span>ROOM TYPE</span></div></div>
<div class="booking-body"><h2>Dorm Name</h2><button class="bookmark" type="button" aria-label="Save pending approval booking" aria-pressed="false"><svg aria-hidden="true"><use href="#bookmark-icon"/></svg></button>
<div class="booking-meta"><span><svg aria-hidden="true"><use href="#pin-icon"/></svg>Location; near which uni</span><span><svg aria-hidden="true"><use href="#bed-icon"/></svg>Duration of stay</span></div>
<p class="booking-description">Short Description<br>Percentage of students from a certain uni<br>Distance from a certain uni</p>
<div class="booking-price"><strong>₱Price/month</strong><span>₱&nbsp; Deposit</span></div>
<div class="occupants"><svg aria-hidden="true"><use href="#people-icon"/></svg>Occupant no.</div>
<div class="amenities"><span>Amenity</span><span>Amenity</span><span>Amenity</span><span>Amenity</span><span>Amenity</span></div>
<div class="landlord"><span class="landlord-avatar" aria-hidden="true"></span><div><div class="landlord-name">Landlord name <svg aria-label="Verified"><use href="#badge-icon"/></svg></div><div class="landlord-rating">Landlord • 4.9 rating</div></div><span class="verified">VERIFIED</span></div>
<div class="booking-actions"><button class="message-landlord" type="button"><svg aria-hidden="true"><use href="#chat-icon"/></svg>Message Landlord</button><button class="cancel-stay" type="button"><svg aria-hidden="true"><use href="#cancel-icon"/></svg><span>Cancel Stay</span></button></div>
</div></article><article class="booking-card" data-id="upcoming" data-status="upcoming" aria-label="Upcoming Stay booking">
<div class="booking-photo"><span class="booking-status">Upcoming Stay</span><div class="room-label"><span class="room-id">Room ID</span><span>ROOM TYPE</span></div></div>
<div class="booking-body"><h2>Dorm Name</h2><button class="bookmark" type="button" aria-label="Save upcoming stay booking" aria-pressed="false"><svg aria-hidden="true"><use href="#bookmark-icon"/></svg></button>
<div class="booking-meta"><span><svg aria-hidden="true"><use href="#pin-icon"/></svg>Location; near which uni</span><span><svg aria-hidden="true"><use href="#bed-icon"/></svg>Duration of stay</span></div>
<p class="booking-description">Short Description<br>Percentage of students from a certain uni<br>Distance from a certain uni</p>
<div class="booking-price"><strong>₱Price/month</strong><span>₱&nbsp; Deposit</span></div>
<div class="occupants"><svg aria-hidden="true"><use href="#people-icon"/></svg>Occupant no.</div>
<div class="amenities"><span>Amenity</span><span>Amenity</span><span>Amenity</span><span>Amenity</span><span>Amenity</span></div>
<div class="landlord"><span class="landlord-avatar" aria-hidden="true"></span><div><div class="landlord-name">Landlord name <svg aria-label="Verified"><use href="#badge-icon"/></svg></div><div class="landlord-rating">Landlord • 4.9 rating</div></div><span class="verified">VERIFIED</span></div>
<div class="booking-actions"><button class="message-landlord" type="button"><svg aria-hidden="true"><use href="#chat-icon"/></svg>Message Landlord</button><button class="cancel-stay" type="button"><svg aria-hidden="true"><use href="#cancel-icon"/></svg><span>Cancel Stay</span></button></div>
</div></article></div>
<p class="booking-empty" id="booking-empty" hidden>No bookings found. Try another search or filter.</p>
<p class="booking-feedback" id="booking-feedback" role="status"></p>
</main>
</div>
<p id="feedback" class="notification-feedback" role="status"></p>
<!-- Supply tenant notifications here as JSON: [{"id":"unique-id","title":"Title","message":"Message"}]. -->
<dialog id="message-dialog" aria-labelledby="message-title"><div class="dialog-header"><h2 id="message-title">Message Landlord</h2><button class="close" type="button" data-close="message-dialog" aria-label="Close message"><svg aria-hidden="true"><use href="#close-icon"/></svg></button></div><form id="message-form" class="booking-dialog-content"><p>Save a message draft for this booking. This preview does not send messages.</p><label for="message-text">Your message</label><textarea id="message-text" maxlength="2000" required></textarea><div class="dialog-actions"><button type="button" data-close="message-dialog">Close</button><button class="primary" type="submit">Save draft</button></div></form></dialog>
<dialog id="cancel-dialog" aria-labelledby="cancel-title"><div class="dialog-header"><h2 id="cancel-title">Cancel stay?</h2><button class="close" type="button" data-close="cancel-dialog" aria-label="Close cancellation"><svg aria-hidden="true"><use href="#close-icon"/></svg></button></div><div class="booking-dialog-content"><p>This updates the booking in this preview only. No cancellation request is sent to the property.</p><div class="dialog-actions"><button type="button" data-close="cancel-dialog" autofocus>Keep stay</button><button id="confirm-cancel" class="primary" type="button">Cancel this stay</button></div></div></dialog>
<script id="notification-data" type="application/json">[]</script>
<script src="../assets/js/tenant/notifications.js"></script>
<script src="../assets/js/tenant/bookings.js"></script>
</body>
</html>