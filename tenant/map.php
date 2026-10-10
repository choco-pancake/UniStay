<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#7a4b3a">
<title>Map · UniStay</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="../assets/css/common.css?v=4">
<link rel="stylesheet" href="../assets/css/map.css?v=2">
<style>
/* Live map + property details (scoped with .um- so it doesn't collide with map.css) */
.um-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin:0 0 12px}
.um-toolbar label{display:flex;align-items:center;gap:8px;font-weight:600}
.um-toolbar select{padding:8px 16px;border:1px solid #d9c9c1;border-radius:999px;background:#fff;font:inherit;max-width:100%}
.um-count{color:#6b5a52;font-size:.9rem}
.um-layout{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:16px;align-items:start}
#leaflet-map{height:min(72vh,680px);min-height:420px;border-radius:16px;border:1px solid #d9c9c1;z-index:0}
.um-detail{max-height:min(72vh,680px);overflow-y:auto;background:#fff;border:1px solid #d9c9c1;border-radius:16px;padding:16px}
.um-empty{color:#6b5a52;margin:0}
.um-main-photo{width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:12px;background:#eee;display:block}
.um-thumbs{display:flex;gap:6px;margin:8px 0 12px;overflow-x:auto}
.um-thumbs button{flex:0 0 auto;padding:0;border:2px solid transparent;border-radius:12px;background:none;cursor:pointer}
.um-thumbs button[aria-current="true"]{border-color:#7a4b3a}
.um-thumbs img{width:64px;height:48px;object-fit:cover;border-radius:9px;display:block}
.um-detail h2{margin:0 0 2px;font-size:1.25rem}
.um-meta{margin:0 0 8px;color:#6b5a52;font-size:.9rem}
.um-summary{display:flex;flex-wrap:wrap;gap:8px 16px;margin:10px 0;padding:10px 12px;background:#f7f1ed;border-radius:12px;font-size:.92rem}
.um-desc{margin:0 0 14px;line-height:1.5}
.um-detail h3{margin:14px 0 8px;font-size:1rem}
.um-room{display:grid;grid-template-columns:72px 1fr;gap:10px;padding:10px 0;border-top:1px solid #eadfd9}
.um-room-ph-wrap{position:relative;width:72px;height:72px}
.um-room-photo{width:72px;height:72px;border-radius:10px;object-fit:cover;background:#eee;display:block}
.um-room-ph-n{position:absolute;right:3px;bottom:3px;padding:1px 5px;border-radius:999px;background:rgba(30,25,22,.72);color:#fff;font-size:.68rem;font-weight:600}
.um-room-ph{width:72px;height:72px;border-radius:10px;background:#f1e8e3;display:grid;place-items:center;color:#7a4b3a}
.um-room-ph svg{width:28px;height:28px;fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
.um-room strong{display:block}
.um-room p{margin:2px 0;font-size:.9rem}
.um-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:.78rem;font-weight:600;background:#e6f2ea;color:#216040}
.um-badge.is-occupied{background:#f6e4e4;color:#8a2f2f}
.um-badge.is-maint{background:#f6efd9;color:#7a5f15}
.um-amen{color:#6b5a52;font-size:.85rem}
.um-error{padding:12px;border-radius:12px;background:#f6e4e4;color:#8a2f2f}
@media (max-width:900px){.um-layout{grid-template-columns:1fr}.um-detail{max-height:none}}

/* Map look + cursors (same as the landlord map) */
.um-map .leaflet-tile-pane{filter:saturate(1.6) contrast(1.05)}
.um-map .leaflet-bar{border-radius:9999px;overflow:hidden}
.um-map,.um-map *{cursor:default}                              /* arrow by default */
.um-map .leaflet-interactive{cursor:pointer}                   /* dorm pins and university dots */
.um-map.um-zoom-in,.um-map.um-zoom-in *{cursor:zoom-in}        /* magnifier + */
.um-map.um-zoom-out,.um-map.um-zoom-out *{cursor:zoom-out}     /* magnifier − */
.um-map .leaflet-control,.um-map .leaflet-control *{cursor:pointer}
body.leaflet-dragging .um-map,body.leaflet-dragging .um-map *{cursor:grabbing}   /* hand while dragging */
path.leaflet-interactive:focus,.leaflet-container svg:focus{outline:none}
</style>
</head>
<body>
<svg class="symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs>
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
<a class="skip" href="#map-page">Skip to map</a>
<aside class="sidebar" aria-label="UniStay portal">
<div class="brand"><img src="../assets/images/tenant/logo.png" width="50" height="50" alt=""><span>UniStay</span></div>
<nav aria-label="Main navigation">
<a href="dashboard.php"><svg aria-hidden="true"><use href="#grid-icon"/></svg>Dashboard</a>
<a href="map.php" aria-current="page"><svg aria-hidden="true"><use href="#map-icon"/></svg>Map</a>
<a href="rooms.php"><svg aria-hidden="true"><use href="#booking-icon"/></svg>Bookings</a>
<a href="settings.php"><svg aria-hidden="true"><use href="#settings-icon"/></svg>Settings</a>
</nav>
</aside>
<div class="shell">
<header class="topbar"><h1>Map</h1><div class="profile">
<button id="notification-bell" class="bell" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="notification-popover"><svg aria-hidden="true"><use href="#bell-icon"/></svg></button>
<span class="avatar" role="img" aria-label="Profile placeholder"></span>
<section id="notification-popover" class="popover" aria-labelledby="popover-title" hidden>
<h2 id="popover-title">Notifications</h2>
<div class="empty" id="popover-empty"><span class="empty-icon"><svg aria-hidden="true"><use href="#bell-off-icon"/></svg></span><strong>No new notifications</strong><p>We’ll let you know when something arrives.</p></div>
<ul class="notification-list" id="popover-list" aria-label="Unread notifications" hidden></ul>
<a class="view-all" id="view-all" href="notifications.php?from=map" aria-label="View all notifications">View all notifications <svg aria-hidden="true"><use href="#arrow-icon"/></svg></a>
</section>
</div></header>
<main id="map-page" tabindex="-1">
  <div class="um-toolbar">
    <label for="uni-filter">University
      <select id="uni-filter"><option value="">All universities</option></select>
    </label>
    <span class="um-count" id="um-count" role="status"></span>
  </div>
  <div class="um-layout">
    <div id="leaflet-map" class="um-map" role="region" aria-label="Map of dorms near Dagupan universities"></div>
    <aside class="um-detail" id="um-detail" aria-live="polite" aria-label="Dorm details">
      <p class="um-empty">Select a pin on the map to see the dorm’s photos, rooms, and prices.</p>
    </aside>
  </div>
<noscript>Enable JavaScript to see dorms on the map and open notifications.</noscript>
</main>
</div>
<p id="feedback" class="notification-feedback" role="status"></p>
<!-- Supply tenant notifications here as JSON: [{"id":"unique-id","title":"Title","message":"Message"}]. -->
<script id="notification-data" type="application/json">[]</script>
<script src="../assets/js/tenant/notifications.js"></script>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../assets/js/store.js"></script>
<script src="../assets/js/tenant-map-markers.js"></script>
<script>
(() => {
  // Landlord-entered text is untrusted: always escape before putting it in innerHTML.
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
  const detail = document.getElementById('um-detail');
  const filter = document.getElementById('uni-filter');
  const count  = document.getElementById('um-count');
  const EMPTY_DETAIL = '<p class="um-empty">Select a pin on the map to see the dorm’s photos, rooms, and prices.</p>';

  // ---- Universities + map setup (same values as the landlord form) ----
  const UNIVERSITIES = [
    { name: 'Universidad de Dagupan',         lat: 16.0507, lng: 120.3408 },
    { name: 'University of Pangasinan',       lat: 16.0471, lng: 120.3425 },
    { name: 'University of Luzon',            lat: 16.0398, lng: 120.3359 },
    { name: 'Lyceum Northwestern University', lat: 16.0354, lng: 120.3305 },
  ];
  const NEAR_KM = 1.0, PAD = 0.010, STREET_ZOOM = 18;
  const _lats = UNIVERSITIES.map(u => u.lat), _lngs = UNIVERSITIES.map(u => u.lng);
  const BOUNDS = L.latLngBounds(
    [Math.min(..._lats) - PAD, Math.min(..._lngs) - PAD],
    [Math.max(..._lats) + PAD, Math.max(..._lngs) + PAD]);

  // Bounds of the outer edge of the 1 km radius around the given universities
  const kmLat = NEAR_KM / 111.32;
  const circleBounds = (list) => L.latLngBounds(list.flatMap(u => {
    const kmLng = NEAR_KM / (111.32 * Math.cos(u.lat * Math.PI / 180));
    return [[u.lat - kmLat, u.lng - kmLng], [u.lat + kmLat, u.lng + kmLng]];
  }));
  const DEFAULT_BOUNDS = circleBounds(UNIVERSITIES);

  const map = L.map('leaflet-map', { maxBounds: BOUNDS, maxBoundsViscosity: 1.0, maxZoom: STREET_ZOOM,
                                     zoomSnap: 0.25, attributionControl: false });
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
  map.fitBounds(DEFAULT_BOUNDS);

  // The starting view doubles as the zoomed-out limit; Reset view restores it exactly.
  const HOME_VIEW = { center: map.getCenter(), zoom: map.getZoom() };
  map.setMinZoom(HOME_VIEW.zoom);

  // At min zoom everything allowed is already visible, so lock dragging until the user zooms in.
  const syncDragLock = () => map.getZoom() <= map.getMinZoom() ? map.dragging.disable() : map.dragging.enable();
  map.on('zoomend', syncDragLock);
  syncDragLock();

  // Magnifier cursors briefly after a scroll-wheel / double-click zoom
  const mapEl = map.getContainer();
  let zoomCursorTimer;
  const flashZoomCursor = (out) => {
    mapEl.classList.remove('um-zoom-in', 'um-zoom-out');
    mapEl.classList.add(out ? 'um-zoom-out' : 'um-zoom-in');
    clearTimeout(zoomCursorTimer);
    zoomCursorTimer = setTimeout(() => mapEl.classList.remove('um-zoom-in', 'um-zoom-out'), 450);
  };
  mapEl.addEventListener('wheel', (e) => flashZoomCursor(e.deltaY > 0), { passive: true });
  mapEl.addEventListener('dblclick', (e) => flashZoomCursor(e.shiftKey));

  // ---- University dots + 1 km radius ----
  const areaCircles = L.layerGroup().addTo(map);
  const uniDots = [];
  UNIVERSITIES.forEach(u => {
    L.circle([u.lat, u.lng], { radius: NEAR_KM * 1000, color: '#b4532a', weight: 1.5, dashArray: '4', fillOpacity: 0.12, interactive: false }).addTo(areaCircles);
    const dot = L.circleMarker([u.lat, u.lng], { radius: 6, color: '#5e3528', fillColor: '#b4532a', fillOpacity: 1, bubblingMouseEvents: false })
      .addTo(map).bindTooltip(u.name);
    // A clicked SVG node can take focus and scroll into view, which reads as the map shaking.
    const unfocusable = () => {
      const el = dot.getElement();
      if (el) { el.setAttribute('focusable', 'false'); el.setAttribute('tabindex', '-1'); }
    };
    unfocusable();
    dot.on('click', (e) => {
      L.DomEvent.stop(e);
      unfocusable();
      const el = dot.getElement();
      if (el) el.blur();
      if (map.getZoom() < STREET_ZOOM) map.flyTo([u.lat, u.lng], STREET_ZOOM, { duration: 0.8 });   // at max zoom: do nothing
    });
    uniDots.push(dot);
  });
  mapEl.addEventListener('mousedown', (e) => {
    if (e.target.closest && e.target.closest('path.leaflet-interactive')) e.preventDefault();
  });

  // Reset view button (pill-shaped)
  const ResetView = L.Control.extend({
    options: { position: 'bottomleft' },
    onAdd() {
      const bar = L.DomUtil.create('div', 'leaflet-bar');
      const btn = L.DomUtil.create('a', '', bar);
      btn.href = '#'; btn.role = 'button';
      btn.title = 'Reset view back to the original starting view';
      btn.setAttribute('aria-label', 'Reset map view');
      btn.textContent = '⤢ Reset view';
      btn.style.cssText = 'width:auto;padding:0 14px;font-size:12px;line-height:30px;white-space:nowrap';
      L.DomEvent.disableClickPropagation(bar);
      L.DomEvent.on(btn, 'click', (e) => {
        L.DomEvent.preventDefault(e);
        map.flyTo(HOME_VIEW.center, HOME_VIEW.zoom, { duration: 0.8 });
      });
      return bar;
    },
  });
  map.addControl(new ResetView());

  // Hide the dashed radius circles once zoomed in close
  map.on('zoomend', () => {
    const close = map.getZoom() >= STREET_ZOOM - 1;
    close ? map.removeLayer(areaCircles) : map.addLayer(areaCircles);
  });

  // Dots fade out while zooming and back in afterwards
  function fadeDots(visible) {
    uniDots.forEach(d => {
      const el = d.getElement();
      if (!el) return;
      el.style.transition = 'opacity .3s';
      el.style.opacity = visible ? 1 : 0;
      el.style.pointerEvents = visible ? '' : 'none';
      if (!visible) { el.blur(); d.closeTooltip(); }
    });
  }
  map.on('zoomstart', () => fadeDots(false));
  map.on('zoomend', () => fadeDots(true));

  // At max zoom a dot click would do nothing, so make the dots unselectable there.
  function syncDotSelectable() {
    const selectable = map.getZoom() < STREET_ZOOM;
    uniDots.forEach(d => {
      if (d.options.interactive === selectable) return;
      d.options.interactive = selectable;
      if (!selectable) d.closeTooltip();
      if (map.hasLayer(d)) { d.remove(); d.addTo(map); }   // Leaflet only reads `interactive` when the path is built
    });
  }
  map.on('zoomend', syncDotSelectable);
  syncDotSelectable();

  // ---- Dorm details panel ----
  const isOpen = (r) => r.status === 'Available' && r.occupants < r.capacity;
  const statusClass = (s) => s === 'Occupied' ? 'is-occupied' : s === 'Under Maintenance' ? 'is-maint' : '';

  function roomHtml(r) {
    const rp = (r.photos && r.photos.length ? r.photos : (r.photo ? [r.photo] : []));
    const photo = rp.length
      ? `<div class="um-room-ph-wrap"><img class="um-room-photo" src="${esc(rp[0])}" alt="" loading="lazy">${rp.length > 1 ? `<span class="um-room-ph-n">+${rp.length - 1}</span>` : ''}</div>`
      : `<span class="um-room-ph" aria-hidden="true"><svg><use href="#bed-icon"/></svg></span>`;
    const deposit = Number(r.deposit) > 0 ? `<p>Deposit: ${esc(fmtPrice(r.deposit, r.depositMax))}</p>` : '';
    const amen = r.amenities.length ? `<p class="um-amen">${r.amenities.map(esc).join(', ')}</p>` : '';
    return `<div class="um-room">${photo}<div>
      <strong>${esc(r.name)}</strong>
      <p>${esc(r.type)} · ${r.occupants}/${r.capacity} occupied</p>
      <p>${esc(fmtPrice(r.rent, r.rentMax))} / month</p>
      ${deposit}
      <span class="um-badge ${statusClass(r.status)}">${esc(r.status)}</span>
      ${amen}
    </div></div>`;
  }

  function showDetail(p) {
    const rooms = p.rooms || [];
    const open = rooms.filter(isOpen).length;
    const mins = rooms.map(r => +r.rent);
    const maxs = rooms.map(r => +(r.rentMax ?? r.rent));
    const priceLine = rooms.length ? `From ${esc(fmtPrice(Math.min(...mins), Math.max(...maxs)))} / month` : 'No rooms listed yet';
    const photos = p.photos && p.photos.length ? p.photos : (p.photo ? [p.photo] : []);

    detail.innerHTML = `
      ${photos.length ? `<img class="um-main-photo" id="um-main" src="${esc(photos[0])}" alt="${esc(p.name)} photo 1">` : ''}
      ${photos.length > 1 ? `<div class="um-thumbs">${photos.map((src, i) =>
        `<button type="button" data-i="${i}" aria-label="Show photo ${i + 1}" ${i === 0 ? 'aria-current="true"' : ''}><img src="${esc(src)}" alt="" loading="lazy"></button>`).join('')}</div>` : ''}
      <h2>${esc(p.name)}</h2>
      <p class="um-meta">${esc(p.university)} · ${esc(p.address)}</p>
      <p class="um-meta">Listed by ${esc(p.landlordName)}</p>
      <div class="um-summary"><span>${priceLine}</span><span>${open} of ${rooms.length} room${rooms.length === 1 ? '' : 's'} open</span></div>
      ${p.description ? `<p class="um-desc">${esc(p.description)}</p>` : ''}
      <h3>Rooms</h3>
      ${rooms.length ? rooms.map(roomHtml).join('') : '<p class="um-empty">This landlord hasn’t added rooms yet.</p>'}`;

    detail.querySelectorAll('.um-thumbs button').forEach(btn => btn.addEventListener('click', () => {
      const i = +btn.dataset.i;
      const main = document.getElementById('um-main');
      main.src = photos[i]; main.alt = `${p.name} photo ${i + 1}`;
      detail.querySelectorAll('.um-thumbs button').forEach(b => b.removeAttribute('aria-current'));
      btn.setAttribute('aria-current', 'true');
    }));
    detail.scrollTop = 0;
  }

  // ---- Pins + university filter ----
  const setCount = (list) => {
    count.textContent = list.length ? `${list.length} dorm${list.length === 1 ? '' : 's'} shown` : 'No dorms match this filter yet.';
  };

  // Filter picked: show that university's 1 km radius (or the starting view for "All universities")
  function moveToFilter(list) {
    const u = UNIVERSITIES.find(x => x.name === filter.value);
    if (!filter.value) map.flyTo(HOME_VIEW.center, HOME_VIEW.zoom, { duration: 0.8 });
    else if (u) map.flyToBounds(circleBounds([u]), { duration: 0.8 });
    else if (list.length) map.flyToBounds(L.latLngBounds(list.map(p => [p.lat, p.lng])), { padding: [48, 48], maxZoom: 16, duration: 0.8 });
  }

  (async () => {
    try {
      const markers = await initDormMarkers(map, showDetail);
      [...new Set(markers.properties.map(p => p.university))].sort().forEach(u => {
        const o = document.createElement('option'); o.value = u; o.textContent = u; filter.appendChild(o);
      });
      setCount(markers.properties);
      filter.addEventListener('change', () => {
        const list = markers.show(filter.value);
        setCount(list);
        detail.innerHTML = EMPTY_DETAIL;
        moveToFilter(list);
      });
    } catch (err) {
      detail.innerHTML = `<p class="um-error">Couldn’t load dorms: ${esc(err.message)}. Refresh the page to try again.</p>`;
    }
  })();
})();
</script>
</body>
</html>