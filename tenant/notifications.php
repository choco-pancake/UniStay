<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="theme-color" content="#7a4b3a" />
    <title>All notifications · UniStay</title>
    <link rel="stylesheet" href="../assets/css/common.css?v=14" />
    <link rel="stylesheet" href="../assets/css/all-notifications.css" />
  </head>
  <body>
    <svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
      <defs>
        <symbol id="grid-icon" viewBox="0 0 24 24">
          <path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z" />
        </symbol>
        <symbol id="map-icon" viewBox="0 0 24 24">
          <path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2Zm6-2v16m6-14v16" />
        </symbol>
        <symbol id="booking-icon" viewBox="0 0 24 24">
          <path d="m2 11 7-6 7 6v10h-5v-7H7v7H2ZM13 3h9v18h-3M16 7h3m0 4h-1" />
        </symbol>
        <symbol id="settings-icon" viewBox="0 0 24 24">
          <path
            d="m9 3-1 3-3 1-2 4 2 3v4l4 3 3-1 3 1 4-3v-4l2-3-2-4-3-1-1-3Z"
          />
          <circle cx="12" cy="12" r="3" />
        </symbol>
        <symbol id="bed-icon" viewBox="0 0 24 24">
          <path d="M3 19V9h18v10M3 16h18M5 9V5h5v4m4 0V5h5v4" />
        </symbol>
        <symbol id="money-icon" viewBox="0 0 24 24">
          <path d="M6 4h16v12H6ZM2 8v12h16" />
          <circle cx="14" cy="10" r="3" />
        </symbol>
        <symbol id="calendar-icon" viewBox="0 0 24 24">
          <path d="M13 21H3V5h15v6M3 9h15M6 2v5m9-5v5" />
          <circle cx="18" cy="17" r="5" />
          <path d="M18 14v3l2 1" />
        </symbol>
        <symbol id="badge-icon" viewBox="0 0 24 24">
          <path
            d="m12 2 3 3 4-1 1 4 3 3-3 3-1 4-4 1-3 3-3-3-4-1-1-4-3-3 3-3 1-4 4 1Z"
          />
          <path d="m8 12 3 3 5-6" />
        </symbol>
        <symbol id="bell-icon" viewBox="0 0 24 24">
          <path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M10 20h4" />
        </symbol>
        <symbol id="bell-off-icon" viewBox="0 0 24 24">
          <path
            d="m3 3 18 18M10 20h4M6 6c-1 2 0 7-3 10h13M18 13V8a6 6 0 0 0-8-6"
          />
        </symbol>
        <symbol id="arrow-icon" viewBox="0 0 24 24">
          <path d="M4 12h16m-6-6 6 6-6 6" />
        </symbol>
        <symbol id="close-icon" viewBox="0 0 24 24">
          <path d="m6 6 12 12M6 18 18 6" />
        </symbol>
      </defs>
    </svg>
    <a class="skip" href="#notification-page">Skip to notifications</a>
    <aside class="sidebar" aria-label="UniStay portal">
      <div class="brand">
        <img
          src="../assets/images/tenant/logo.png"
          width="50"
          height="50"
          alt=""
        /><span>UniStay</span>
      </div>
      <nav aria-label="Main navigation">
        <a href="dashboard.php"
          ><svg aria-hidden="true"><use href="#grid-icon" /></svg>Dashboard</a
        >
        <a href="map.php"
          ><svg aria-hidden="true"><use href="#map-icon" /></svg>Map</a
        >
        <a href="rooms.php"
          ><svg aria-hidden="true"><use href="#booking-icon" /></svg>Bookings</a
        >
        <a href="settings.php"
          ><svg aria-hidden="true"><use href="#settings-icon" /></svg
          >Settings</a
        >
      </nav>
    </aside>
    <div class="shell">
      <header class="topbar">
        <h1>Notifications</h1>
        <div class="profile">
          <button
            id="notification-bell"
            class="bell"
            type="button"
            aria-label="Notifications"
            aria-expanded="false"
            aria-controls="notification-popover"
          >
            <svg aria-hidden="true"><use href="#bell-icon" /></svg>
          </button>
          <span
            class="avatar"
            role="img"
            aria-label="Profile placeholder"
          ></span>
          <section
            id="notification-popover"
            class="popover"
            aria-labelledby="popover-title"
            hidden
          >
            <h2 id="popover-title">Notifications</h2>
            <div class="empty" id="popover-empty">
              <span class="empty-icon"
                ><svg aria-hidden="true">
                  <use href="#bell-off-icon" /></svg></span
              ><strong>No new notifications</strong>
              <p>We’ll let you know when something arrives.</p>
            </div>
            <ul
              class="notification-list"
              id="popover-list"
              aria-label="Unread notifications"
              hidden
            ></ul>
            <a
              class="view-all"
              id="view-all"
              href="notifications.php?from=dashboard"
              aria-label="View all notifications"
              >View all notifications
              <svg aria-hidden="true"><use href="#arrow-icon" /></svg
            ></a>
          </section>
        </div>
      </header>
      <main id="notification-page" tabindex="-1">
        <section class="notification-hero" aria-labelledby="hero-title">
          <div>
            <span class="hero-kicker">Your UniStay updates</span>
            <h2 id="hero-title">
              A little update.<br /><em>A lot more peace of mind.</em>
            </h2>
            <p>
              A little less checking, a little more living.<br />Everything you
              need to know, right here.
            </p>
          </div>
          <div class="hero-art" aria-hidden="true">
            <span class="orbit-label">HOME, IN THE KNOW</span
            ><svg><use href="#bell-icon" /></svg
            ><span class="hero-spark">✦</span>
          </div>
        </section>
        <div class="notification-page-heading">
          <button id="read-all" type="button">
            <svg aria-hidden="true"><use href="#badge-icon" /></svg>Mark all as
            read
          </button>
          <button id="clear-all" type="button">Clear all</button>
        </div>
        <section class="full-inbox" aria-label="Notifications">
          <div class="empty" id="all-empty">
            <div class="empty-illustration" aria-hidden="true">
              <span class="letter"></span
              ><span class="letter"
                ><svg><use href="#bell-icon" /></svg></span
              ><span class="empty-check">✓</span>
            </div>
            <strong id="empty-title">You’re all caught up.</strong>
            <p>
              Your next home update will appear here.<br />For now, make
              yourself at home.
            </p>
          </div>
          <ul
            class="notification-list"
            id="all-list"
            aria-label="All notifications"
            hidden
          ></ul>
        </section>
        <div class="notification-return">
          <a id="back-to-page" class="back-dashboard" href="dashboard.php"
            ><span>Back to Dashboard</span>
            <svg aria-hidden="true"><use href="#arrow-icon" /></svg
          ></a>
        </div>
        <p class="inbox-note">
          A space for the updates that matter to your home.
        </p>
        <noscript>Enable JavaScript to view notifications.</noscript>
      </main>
    </div>
    <p id="feedback" class="notification-feedback" role="status"></p>
    <!-- Supply tenant notifications here as JSON: [{"id":"unique-id","title":"Title","message":"Message"}]. -->
    <script id="notification-data" type="application/json">
      []
    </script>
    <script src="../assets/js/tenant/notifications.js"></script>
  </body>
</html>
