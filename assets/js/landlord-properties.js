const $ = (s) => document.querySelector(s);
const AMENITIES = ['WiFi', 'Aircon', 'Private CR', 'Study desk', 'Laundry', 'Kitchen'];
let photoFiles = [], pin = null;

// ---- Header + sidebar ----
$('#hName').textContent = CURRENT_LANDLORD.name;
$('#hRole').textContent = CURRENT_LANDLORD.role;
$('#avatar').textContent = CURRENT_LANDLORD.name[0];
$('#toggleSb').onclick = () => {
  const sb = $('#sidebar'), collapsed = sb.classList.toggle('w-16');
  sb.classList.toggle('w-60', !collapsed);
  document.querySelectorAll('.sb-label').forEach(e => e.classList.toggle('hidden', collapsed));
};

// ---- Map + address (two ways to set location) ----
// Required format: "Street, Barangay, City" (optional 4th part, e.g. province). Each part >= 3 chars.
const ADDR_RE = /^[^,]{3,},\s*[^,]{3,},\s*[^,]{3,}(,\s*[^,]{3,})?$/;
const FORMAT_MSG = 'Use the format: Street, Barangay, City (e.g. 123 Rizal St, Poblacion Oeste, Dagupan City).';
const addrInput = $('#addressInput'), addrHint = $('#addrHint');
const uniSel = $('#university'), uniHint = $('#uniHint'), uniPlaceholder = $('#uniPlaceholder');
let mode = 'type', geoToken = 0, typeTimer, marker;
let uniMode = 'list';   // 'list' = chosen manually, 'map' = picked on the map

// The 4 supported universities (Dagupan City). Landlords may only list dorms within NEAR_KM of one of them.
const UNIVERSITIES = [
  { name: 'Universidad de Dagupan',         lat: 16.0507, lng: 120.3408 },
  { name: 'University of Pangasinan',       lat: 16.0471, lng: 120.3425 },
  { name: 'University of Luzon',            lat: 16.0398, lng: 120.3359 },
  { name: 'Lyceum Northwestern University', lat: 16.0354, lng: 120.3305 },
];
const NEAR_KM = 1.0;      // allowed distance from any university
const PAD = 0.010;        // ~1.1 km of padding so the whole allowed area fits in the map bounds
const OUT_MSG = `That location is outside the allowed area. Dorms must be within ${NEAR_KM} km of Universidad de Dagupan, University of Pangasinan, University of Luzon, or Lyceum Northwestern University.`;

const _lats = UNIVERSITIES.map(u => u.lat), _lngs = UNIVERSITIES.map(u => u.lng);
const BOUNDS = L.latLngBounds(
  [Math.min(..._lats) - PAD, Math.min(..._lngs) - PAD],
  [Math.max(..._lats) + PAD, Math.max(..._lngs) + PAD]);

// Haversine distance (km) to the closest university
function nearestUni(lat, lng) {
  const rad = (d) => d * Math.PI / 180;
  return UNIVERSITIES.map(u => {
    const a = Math.sin(rad(lat - u.lat) / 2) ** 2 +
              Math.cos(rad(u.lat)) * Math.cos(rad(lat)) * Math.sin(rad(lng - u.lng) / 2) ** 2;
    return { uni: u, km: 6371 * 2 * Math.asin(Math.sqrt(a)) };
  }).sort((x, y) => x.km - y.km)[0];
}

const STREET_ZOOM = 18, UNI_ZOOM = 17;
// Vivid basemap (CARTO Voyager, built from OpenStreetMap data)
const baseTiles = () => L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 });

// Default view = the outer edge of each university's 1 km radius
const kmLat = NEAR_KM / 111.32;
const DEFAULT_BOUNDS = L.latLngBounds(UNIVERSITIES.flatMap(u => {
  const kmLng = NEAR_KM / (111.32 * Math.cos(u.lat * Math.PI / 180));
  return [[u.lat - kmLat, u.lng - kmLng], [u.lat + kmLat, u.lng + kmLng]];
}));

const map = L.map('pickMap', { maxBounds: BOUNDS, maxBoundsViscosity: 1.0, maxZoom: STREET_ZOOM,
                               zoomSnap: 0.25, attributionControl: false });
baseTiles().addTo(map);
map.fitBounds(DEFAULT_BOUNDS);

// The exact view the map starts in, captured after the initial fit has settled (so it already
// accounts for the container size and the maxBounds clamp). "Reset view" restores this verbatim
// instead of re-running fitBounds, which can land on a slightly different zoom.
const HOME_VIEW = { center: map.getCenter(), zoom: map.getZoom() };
map.setMinZoom(HOME_VIEW.zoom);   // the default view IS the zoomed-out limit: you can't go further out

// At min zoom the default view already shows everything inside the allowed area, so there is
// nothing to pan to — lock dragging until the user zooms in, then hand it back.
function syncDragLock() {
  const atMin = map.getZoom() <= map.getMinZoom();
  atMin ? map.dragging.disable() : map.dragging.enable();
}
map.on('zoomend', syncDragLock);
syncDragLock();

const mapEl = map.getContainer();
// Pointing-hand cursor over the map while "Pin on the map" or "Pick on the map" is active
const syncPickCursor = () => mapEl.classList.toggle('um-pick', mode === 'map' || uniMode === 'map');
let zoomCursorTimer;
const flashZoomCursor = (out) => {
  mapEl.classList.remove('um-zoom-in', 'um-zoom-out');
  mapEl.classList.add(out ? 'um-zoom-out' : 'um-zoom-in');
  clearTimeout(zoomCursorTimer);
  zoomCursorTimer = setTimeout(() => mapEl.classList.remove('um-zoom-in', 'um-zoom-out'), 450);
};
mapEl.addEventListener('wheel', (e) => flashZoomCursor(e.deltaY > 0), { passive: true });
mapEl.addEventListener('dblclick', (e) => flashZoomCursor(e.shiftKey));

// Show each university and its allowed radius
const areaCircles = L.layerGroup().addTo(map);
const uniDots = [];
UNIVERSITIES.forEach(u => {
  L.circle([u.lat, u.lng], { radius: NEAR_KM * 1000, color: '#6366f1', weight: 1.5, dashArray: '4', fillOpacity: 0.12, interactive: false }).addTo(areaCircles);
  // Clicking a university dot zooms to it (bubbling off, so it doesn't also drop a pin in "Pin on the map" mode)
  const dot = L.circleMarker([u.lat, u.lng], { radius: 6, color: '#4338ca', fillColor: '#6366f1', fillOpacity: 1, bubblingMouseEvents: false })
    .addTo(map).bindTooltip(u.name)
    // A clicked SVG node gets focus, and the browser then scrolls it into view — which reads as the
    // map shaking. Keep the dots out of the focus/scroll flow entirely.
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
      if (map.getZoom() < STREET_ZOOM) {          // at max zoom: don't move the map, do nothing at all
map.flyTo([u.lat, u.lng], STREET_ZOOM, { duration: 0.8 });   // click a blue circle from the default view -> zoom all the way to max
        if (uniMode === 'map') { setUni(u.name); setUniHint(`Selected: ${u.name}.`, 'text-green-600'); }   // pick-on-map mode
      }
    });
  uniDots.push(dot);
});
// Belt-and-braces: never let a mousedown on a map shape move focus/scroll the page.
mapEl.addEventListener('mousedown', (e) => {
  const t = e.target;
  if (t && t.classList && t.classList.contains('leaflet-interactive')) e.preventDefault();
});
// "Reset view" button: zooms back out so all 4 universities are visible (the pin is left untouched)
const ResetView = L.Control.extend({
  options: { position: 'bottomleft' },
  onAdd() {
    const bar = L.DomUtil.create('div', 'leaflet-bar');
    const btn = L.DomUtil.create('a', '', bar);
    btn.href = '#'; btn.role = 'button';
    btn.title = 'Reset view back to the original starting view';
    btn.setAttribute('aria-label', 'Reset map view');
    btn.textContent = '⤢ Reset view';
    btn.style.cssText = 'width:auto;padding:0 14px;font-size:12px;line-height:30px;white-space:nowrap;cursor:pointer';
    L.DomEvent.disableClickPropagation(bar);
    // flyTo, not fitBounds: flyTo always animates the zoom change, so Reset reliably zooms back out
    // to the starting zoom. fitBounds can pick the same zoom level it is already at and skip the animation.
    L.DomEvent.on(btn, 'click', (e) => {
      L.DomEvent.preventDefault(e);
      map.flyTo(HOME_VIEW.center, HOME_VIEW.zoom, { duration: 0.8 });
    });
    return bar;
  },
});
map.addControl(new ResetView());

// Hide the dashed radius circles once zoomed in close, so the street map stays clean
map.on('zoomend', () => {
  const close = map.getZoom() >= STREET_ZOOM - 1;
  close ? map.removeLayer(areaCircles) : map.addLayer(areaCircles);
});

// The blue university dots (and the black focus box the browser draws around a clicked dot) fade out
// while the map zooms, then fade back in on the campus once the zoom has finished.
const dotStyle = document.createElement('style');
dotStyle.textContent = 'path.leaflet-interactive:focus, .leaflet-container svg:focus { outline: none; }';   // removes the black box
document.head.appendChild(dotStyle);

function fadeDots(visible) {
  uniDots.forEach(d => {
    const el = d.getElement();
    if (!el) return;
    el.style.transition = 'opacity .3s';
    el.style.opacity = visible ? 1 : 0;
    el.style.pointerEvents = visible ? '' : 'none';   // faded dots shouldn't be clickable/hoverable
    if (!visible) { el.blur(); d.closeTooltip(); }     // drop focus box + tooltip while zooming
  });
}
map.on('zoomstart', () => fadeDots(false));
map.on('zoomend', () => fadeDots(true));              // zoom finished -> dots reappear on their universities

// At max zoom a dot click would be a no-op, so make the dots unselectable there: no hover tooltip,
// no click, no pointer cursor. Zoom back out and they become selectable again.
function syncDotSelectable() {
  const selectable = map.getZoom() < STREET_ZOOM;
  uniDots.forEach(d => {
    if (d.options.interactive === selectable) return;
    d.options.interactive = selectable;
    if (!selectable) d.closeTooltip();
    // Leaflet only reads `interactive` when the SVG path is built, so re-add to rebuild it.
    if (map.hasLayer(d)) { d.remove(); d.addTo(map); }
  });
}
map.on('zoomend', syncDotSelectable);
syncDotSelectable();

const setHint = (msg, color = 'text-slate-500') => {
  addrHint.textContent = msg;
  addrHint.className = `text-xs ${color}`;
};
// Small panel shown when the pin is clicked: current address + a Remove pin button
function pinPopup() {
  const box = document.createElement('div');
  box.className = 'text-sm space-y-2';
  const label = document.createElement('p');
  label.className = 'font-medium text-slate-800';
  label.textContent = addrInput.value.trim() || `${pin.lat}, ${pin.lng}`;   // textContent: no HTML injection
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'px-3 py-1 rounded-full bg-red-600 text-white text-xs hover:bg-red-700';
  btn.textContent = 'Remove pin';
  btn.onclick = removePin;
  box.append(label, btn);
  return box;
}
// Removes the pin AND its address (they always belong together), then resets the helper text
function removePin() {
  clearTimeout(typeTimer);
  clearPin();                 // also closes the popup (marker is removed from the map)
  addrInput.value = '';
  setMode(mode);              // cancels pending lookups and restores the default hint for the current mode
}
function placePin(lat, lng, zoom) {
  pin = { lat: +(+lat).toFixed(6), lng: +(+lng).toFixed(6) };
  const ll = [pin.lat, pin.lng];
  if (marker) marker.setLatLng(ll);
  else {
    marker = L.marker(ll, { draggable: true }).addTo(map).bindPopup(pinPopup, { maxWidth: 220 });
    marker.on('dragstart', () => marker.closePopup());
    marker.on('dragend', onPinDragged);
  }
  if (zoom) map.setView(ll, zoom);
}
function clearPin() {
  pin = null;
  if (marker) { map.removeLayer(marker); marker = null; }
}

// Switch between "type address" and "pin on map"
function setMode(m) {
  mode = m; geoToken++;
  syncPickCursor();
  addrInput.readOnly = (m === 'map');
  addrInput.placeholder = m === 'map'
    ? 'Click the map and the address will appear here'
    : 'Street, Barangay, City  (e.g. 123 Rizal St, Poblacion Oeste, Dagupan City)';
  map.getContainer().style.outline = m === 'map' ? '2px solid #6366f1' : 'none';
  setHint(m === 'map'
    ? 'Click anywhere on the map to drop your pin (drag it to adjust). The address fills in automatically.'
    : 'Required format: Street, Barangay, City. The pin is placed automatically; dragging it switches to map pinning.');
  if (m === 'type' && addrInput.value.trim()) geocode();   // re-sync pin to whatever is typed
}
document.querySelectorAll('input[name="locMode"]').forEach(r => r.onchange = () => setMode(r.value));

// ---- University: choose manually (dropdown) or pick on the map ----
function setUni(name) {                    // programmatic change -> never re-triggers the manual handler
  if (uniSel.value !== name) uniSel.value = name;
}
function setUniHint(msg, color = 'text-slate-500') {
  uniHint.textContent = msg;
  uniHint.className = `text-xs ${color}`;
}
function setUniMode(m) {
  uniMode = m;
  syncPickCursor();
  const manual = m === 'list';
  uniSel.disabled = !manual;
  uniSel.classList.toggle('bg-slate-100', !manual);
  uniSel.classList.toggle('text-slate-600', !manual);
  uniSel.classList.toggle('cursor-not-allowed', !manual);
  uniPlaceholder.textContent = manual ? 'Select nearest university' : 'Click the map to select a university';
  setUniHint(manual
    ? 'Pick a university from the list, or set the location and the nearest one will be selected automatically.'
    : 'Click the map near a university — the nearest one will be selected automatically.');
}
document.querySelectorAll('input[name="uniMode"]').forEach(r => r.onchange = () => setUniMode(r.value));
setUniMode('list');

// Manual selection: zoom the map to that university and fill in the location
uniSel.onchange = () => {
  const u = UNIVERSITIES.find(x => x.name === uniSel.value);
  if (!u) return;
  map.flyTo([u.lat, u.lng], UNI_ZOOM, { duration: 0.8 });
  placePin(u.lat, u.lng);                  // location starts at the university...
  reverseFill();                           // ...and the address fills in from the pin
  setUniHint(`${u.name} selected — the map zoomed there and the location was filled in. Drag the pin or edit the address to adjust.`, 'text-green-600');
};

// Mode 1: typed address -> pin (Nominatim search)
async function nominatim(q) {
  // viewbox (west,north,east,south) makes results near the 4 universities rank first
  const vb = [BOUNDS.getWest(), BOUNDS.getNorth(), BOUNDS.getEast(), BOUNDS.getSouth()].join(',');
  const r = await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=ph&viewbox=${vb}&q=${encodeURIComponent(q)}`,
    { headers: { Accept: 'application/json' } });
  if (!r.ok) throw new Error('Geocoding service unavailable.');
  return r.json();
}
async function geocode() {
  const q = addrInput.value.trim();
  if (!ADDR_RE.test(q)) { clearPin(); return setHint(FORMAT_MSG, 'text-amber-600'); }
  const t = ++geoToken;
  setHint('Locating address on the map…');
  try {
    let res = await nominatim(q), approx = false;
    if (!res.length) {                                   // street not found -> try barangay + city only
      const rest = q.split(',').slice(1).join(',').trim();
      res = await nominatim(rest); approx = res.length > 0;
    }
    if (t !== geoToken) return;                          // a newer request replaced this one
    if (!res.length) { clearPin(); return setHint('Address not found. Check the spelling or switch to "Pin on the map".', 'text-red-600'); }
      const near = nearestUni(+res[0].lat, +res[0].lon);
      if (near.km > NEAR_KM) { clearPin(); return setHint(OUT_MSG, 'text-red-600'); }   // not near any of the 4 universities
      placePin(res[0].lat, res[0].lon, approx ? 15 : 17);
      setUni(near.uni.name);                                                        // location filled -> nearest university selected
    const dist = `about ${near.km.toFixed(2)} km from ${near.uni.name}`;
    setHint(approx ? `Street not found, so the pin marks the barangay area (${dist}). Switch to "Pin on the map" to place it exactly.` : `Address located and pinned ✓ (${dist})`,
            approx ? 'text-amber-600' : 'text-green-600');
  } catch (err) {
    if (t === geoToken) setHint(err.message || 'Could not locate address.', 'text-red-600');
  }
}
addrInput.addEventListener('input', () => {
  if (mode !== 'type') return;
  clearPin();                                            // old pin no longer matches the edited text
  clearTimeout(typeTimer);
  typeTimer = setTimeout(geocode, 800);                  // debounce (Nominatim allows ~1 request/second)
});

// Look up the address for the current pin and write it into the address field
async function reverseFill() {
  addrInput.value = '';
  const t = ++geoToken;
  setHint('Finding address…');
  try {
    const r = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=18&lat=${pin.lat}&lon=${pin.lng}`,
      { headers: { Accept: 'application/json' } });
    if (!r.ok) throw new Error();
    const d = await r.json();
    if (t !== geoToken) return;
    const a = d.address || {};
    const street = [a.house_number, a.road || a.pedestrian || a.footway].filter(Boolean).join(' ');
    const brgy = a.suburb || a.neighbourhood || a.quarter || a.village || a.hamlet;
    const city = a.city || a.town || a.municipality || a.county;
    let parts = [street, brgy, city].filter(Boolean);
    if (parts.length < 3) parts = (d.display_name || '').split(',').map(s => s.trim()).filter(Boolean).slice(0, 3);
    addrInput.value = parts.join(', ');
    ADDR_RE.test(addrInput.value)
      ? setHint('Address filled in from your pin ✓ (drag the pin to adjust)', 'text-green-600')
      : setHint('Could not build a full address for this spot. Try a nearby spot, or switch to "Type the address".', 'text-amber-600');
  } catch {
    if (t === geoToken) setHint('Could not look up the address. Try again or switch to "Type the address".', 'text-red-600');
  }
}

// Mode 2: map click -> university (and pin + address when "Pin on the map" is active)
map.on('click', (e) => {
  const near = nearestUni(e.latlng.lat, e.latlng.lng);
  const pickMode = uniMode === 'map', pinMode = mode === 'map';
  if (!pickMode && !pinMode) return;
  if (near.km > NEAR_KM) {
    if (pickMode) setUniHint(OUT_MSG, 'text-red-600');
    if (pinMode) setHint(OUT_MSG, 'text-red-600');
    return;
  }
  setUni(near.uni.name);                        // a particular university is selected automatically
  if (pickMode) setUniHint(`Selected: ${near.uni.name} (about ${near.km.toFixed(2)} km away).`, 'text-green-600');
  if (pinMode) { placePin(e.latlng.lat, e.latlng.lng); reverseFill(); }
});

// Dragging the pin (works in both modes): the pin becomes the source of truth, so the address follows it
function onPinDragged() {
  const ll = marker.getLatLng();
  if (nearestUni(ll.lat, ll.lng).km > NEAR_KM) {          // dropped outside the allowed area -> snap back
    marker.setLatLng([pin.lat, pin.lng]);
    return setHint(OUT_MSG, 'text-red-600');
  }
  if (mode !== 'map') {                                    // switch to "Pin on the map" so the address can't disagree with the pin
    document.querySelector('input[name="locMode"][value="map"]').checked = true;
    setMode('map');
  }
  placePin(ll.lat, ll.lng);
  setUni(nearestUni(ll.lat, ll.lng).uni.name);   // location moved -> nearest university selected
  reverseFill();
}
setMode('type');

// ---- Photos (sent to PHP as real file uploads; drag & drop or click to browse) ----
function renderPhotos(skipped = 0) {
  const box = $('#photoPreview');
  box.innerHTML = photoFiles.map((f, i) => `
    <div class="relative">
      <img src="${URL.createObjectURL(f)}" class="h-28 w-40 rounded-lg object-cover" alt="Preview">
      <button type="button" data-rm="${i}" class="absolute top-1 right-1 w-6 h-6 rounded-full bg-slate-900/70 text-white text-xs leading-none hover:bg-slate-900" aria-label="Remove photo">✕</button>
    </div>`).join('');
  box.classList.toggle('hidden', photoFiles.length === 0);
  const note = skipped ? ` · ${skipped} skipped (images under 5 MB only)` : '';
  const cnt = $('#photoCount');
  cnt.textContent = photoFiles.length
    ? `${photoFiles.length} selected — at least 3 needed${photoFiles.length < 3 ? ` (${3 - photoFiles.length} to go)` : ' ✓'}${note}`
    : (skipped ? `No photos added — ${skipped} file(s) skipped (images under 5 MB only).` : '');
  cnt.classList.toggle('hidden', !photoFiles.length && !skipped);
  box.querySelectorAll('[data-rm]').forEach(b => b.onclick = () => { photoFiles.splice(+b.dataset.rm, 1); renderPhotos(); });
}
function addPhotos(files) {
  let skipped = 0;
  for (const f of files) {
    if (!f.type.startsWith('image/') || f.size > 5 * 1024 * 1024) { skipped++; continue; }
    if (!photoFiles.some(p => p.name === f.name && p.size === f.size)) photoFiles.push(f);
  }
  renderPhotos(skipped);
}
$('#photoInput').onchange = (e) => { addPhotos([...e.target.files]); e.target.value = ''; };
attachDropZone($('#photoZone'), $('#photoInput'), addPhotos);

// ---- Rooms ----
const inp = 'mt-1 w-full border rounded-lg px-3 py-2';
const typeOptions = Object.keys(ROOM_TYPES).map(t => `<option>${t}</option>`).join('');

// A price field that can be a fixed amount or a range (independent toggle per field)
const priceGroup = (field, label) => `
  <div class="text-sm" data-price="${field}" data-mode="fixed">
    <div class="flex items-center justify-between gap-2 mb-1">
      <span>${label}</span>
      <span class="inline-flex rounded-full border overflow-hidden text-xs shrink-0">
        <button type="button" data-v="fixed" class="px-2.5 py-0.5 bg-indigo-600 text-white">Fixed</button>
        <button type="button" data-v="range" class="px-2.5 py-0.5 border-l text-slate-600 hover:bg-slate-50">Range</button>
      </span>
    </div>
    <div data-fixed><input data-f="${field}" type="number" min="0" class="${inp}" placeholder="${field === 'rent' ? 'e.g. 3500' : 'e.g. 500'}"></div>
    <div data-range class="hidden grid grid-cols-2 gap-2">
      <input data-f="${field}Min" type="number" min="0" class="${inp}" placeholder="Min">
      <input data-f="${field}Max" type="number" min="0" class="${inp}" placeholder="Max">
    </div>
  </div>`;
function addRoom() {
  const d = document.createElement('div');
  d.className = 'room border rounded-lg p-4 grid md:grid-cols-3 gap-3 relative';
  d.innerHTML = `
    <label class="text-sm">Room name *<input data-f="name" class="${inp}" placeholder="Room 101"></label>
    <label class="text-sm">Room type *<select data-f="type" class="${inp}">${typeOptions}</select></label>
    ${priceGroup('rent', 'Monthly payment (₱) *')}
    ${priceGroup('deposit', 'Deposit (₱)')}
    <div class="text-sm">
      <span class="mb-1 block">Room photos <span class="text-slate-400">(up to ${MAX_ROOM_PHOTOS})</span></span>
      <div data-zone class="border-2 border-dashed border-slate-300 rounded-lg px-3 py-3 text-center cursor-pointer text-xs text-slate-500 transition hover:border-indigo-400 hover:bg-indigo-50/40">Drag &amp; drop or click to browse
        <input data-f="photo" type="file" accept="image/*" multiple class="hidden">
      </div>
      <div data-preview class="hidden mt-2 grid grid-cols-3 gap-2"></div>
      <p data-count class="mt-1 text-[11px] text-slate-500 hidden"></p>
    </div>
    <button type="button" class="rm text-sm text-red-600 self-end text-left pb-2">Remove room</button>
    <div class="md:col-span-3 flex flex-wrap gap-3 text-sm">${AMENITIES.map(a => `<label class="flex items-center gap-1"><input type="checkbox" value="${a}">${a}</label>`).join('')}</div>`;
  d.querySelector('.rm').onclick = () => document.querySelectorAll('.room').length > 1 && d.remove();
  d.querySelectorAll('[data-price]').forEach(w => w.querySelectorAll('button[data-v]').forEach(b => b.onclick = () => setPriceMode(w, b.dataset.v)));
  // Room photos: up to MAX_ROOM_PHOTOS, drop zone + previews + remove
  d.picker = attachPhotoPicker(d.querySelector('[data-zone]'), d.querySelector('[data-f="photo"]'), d.querySelector('[data-preview]'), d.querySelector('[data-count]'));
  $('#rooms').appendChild(d);
}
$('#addRoom').onclick = addRoom;
addRoom();

// ---- Submit ----
const esc = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const showError = (m) => { $('#error').textContent = m; $('#error').classList.remove('hidden'); };
$('#propForm').onsubmit = async (e) => {
  e.preventDefault();
  const f = new FormData(e.target);   // name, university, address, description
  f.set('university', uniSel.value);  // sent even when the select is locked ("Pick on the map")
  const roomEls = [...document.querySelectorAll('.room')];
  const readPrice = (w) => {
    const f = w.dataset.price;
    return w.dataset.mode === 'range'
      ? { min: +(w.querySelector(`[data-f="${f}Min"]`).value || 0), max: +(w.querySelector(`[data-f="${f}Max"]`).value || 0) }
      : { min: +(w.querySelector(`[data-f="${f}"]`).value || 0), max: 0 };
  };
  const rooms = roomEls.map((r) => {
    const g = (k) => r.querySelector(`[data-f="${k}"]`).value.trim();
    const type = g('type');
    const rent = readPrice(r.querySelector('[data-price="rent"]'));
    const dep = readPrice(r.querySelector('[data-price="deposit"]'));
    return { name: g('name'), type, capacity: ROOM_TYPES[type] || 1,
             rent: rent.min, rentMax: rent.max || null,
             deposit: dep.min, depositMax: dep.max || null, occupants: 0,
             status: 'Available', amenities: [...r.querySelectorAll('input[type=checkbox]:checked')].map(c => c.value) };
  });
  const address = f.get('address').trim();
  if (!f.get('name').trim() || !address) return showError('Please fill in all required dorm details.');
  if (!f.get('university')) return showError(uniMode === 'map' ? 'Click the map to select the nearest university.' : 'Please select the nearest university.');
  if (!ADDR_RE.test(address)) return showError(FORMAT_MSG);
  if (!pin) return showError(mode === 'map' ? 'Please click the map to pin your property.' : 'Your address could not be located on the map. Check it or switch to "Pin on the map".');
  if (photoFiles.length < 3) return showError('Please upload at least 3 dorm photos.');
  if (rooms.some(r => !r.name || !r.rent)) return showError('Each room needs a name and rent.');
  if (rooms.some(r => r.rentMax && r.rentMax < r.rent)) return showError("A room's maximum monthly payment can't be lower than its minimum.");
  if (rooms.some(r => r.depositMax && r.depositMax < r.deposit)) return showError("A room's maximum deposit can't be lower than its minimum.");

  f.set('address', address);
  photoFiles.forEach(pf => f.append('photos[]', pf));
  roomEls.forEach((el, i) => {
    el.picker.files.forEach(rp => f.append(`room_photo_${i}[]`, rp));
  });
  f.append('lat', pin.lat); f.append('lng', pin.lng); f.append('rooms', JSON.stringify(rooms));
  try { await Store.add(f); } catch (err) { return showError(err.message); }

  $('#error').classList.add('hidden');
  e.target.reset(); $('#rooms').innerHTML = ''; addRoom();
  photoFiles = []; renderPhotos(); clearPin(); setMode('type'); setUniMode('list'); map.fitBounds(DEFAULT_BOUNDS);
  $('#toast').classList.remove('hidden'); setTimeout(() => $('#toast').classList.add('hidden'), 2500);
  renderMine();
};

// ---- My properties list ----
let myList = [];
async function renderMine() {
  let list = [];
  try { list = await Store.byLandlord(); } catch (err) { showError(err.message); }
  myList = list;
  $('#myProps').innerHTML = list.length ? list.map(p => `
    <div data-id="${p.id}" class="prop-card bg-white border rounded-xl overflow-hidden cursor-pointer hover:shadow-md hover:border-indigo-300 transition">
      <img src="${esc(p.photos[0] || p.photo || '')}" class="h-32 w-full object-cover" alt="">
      <div class="p-4 text-sm">
        <p class="font-medium">${esc(p.name)}</p><p class="text-slate-500">${esc(p.address)}</p>
        <p class="text-slate-500">Near ${esc(p.university)} · ${p.rooms.length} room(s)</p>
        <button data-id="${p.id}" class="del mt-2 text-red-600">Delete</button>
      </div></div>`).join('') : '<p class="text-sm text-slate-500">No properties yet.</p>';
  document.querySelectorAll('.prop-card').forEach(c => c.onclick = (ev) => {
    if (ev.target.closest('.del')) return;
    openPropModal(c.dataset.id);
  });
  document.querySelectorAll('.del').forEach(b => b.onclick = async (ev) => {
    ev.stopPropagation();
    deleteProperty(b.dataset.id);
  });
}

async function deleteProperty(id) {
  if (!confirm('Delete this property and all its rooms?')) return;
  try { await Store.remove(id); } catch (err) { return showError(err.message); }
  closePropModal();
  renderMine();
}

// ---- Floating property panel (giant card centered over a blurred backdrop) ----
const modal = $('#propModal'), modalCard = $('#propModalCard');
let modalMap = null, modalTimer = 0;
function openPropModal(id) {
  const p = myList.find(x => String(x.id) === String(id));
  if (!p) return;
  clearTimeout(modalTimer);
  if (modalMap) { modalMap.remove(); modalMap = null; }
  const photos = (p.photos.length ? p.photos : [p.photo]).filter(Boolean);
  modalCard.innerHTML = `
    <button id="propModalClose" class="absolute top-3 right-3 z-10 w-9 h-9 rounded-full bg-white/90 border text-slate-600 hover:text-slate-900 hover:bg-white shadow" aria-label="Close">✕</button>
    ${photos.length ? `<div class="grid grid-cols-2 sm:grid-cols-3 gap-1.5 p-1.5">${photos.map(src => `<img src="${esc(src)}" class="w-full h-44 object-cover rounded-lg" alt="">`).join('')}</div>` : ''}
    <div class="p-6 space-y-5">
      <div>
        <h2 class="text-xl font-semibold">${esc(p.name)}</h2>
        <p class="text-sm text-slate-500">${esc(p.address)}</p>
        <p class="text-sm text-slate-500">Near ${esc(p.university)}</p>
        <p class="text-xs text-slate-400 mt-0.5">Listed by ${esc(p.landlordName || 'Landlord')}</p>
      </div>
      ${p.description ? `<p class="text-sm text-slate-600 whitespace-pre-line">${esc(p.description)}</p>` : ''}
      <div id="propModalMap" class="um-map h-44 rounded-lg overflow-hidden border z-0"></div>
      <div>
        <h3 class="font-medium mb-2">Rooms (${p.rooms.length})</h3>
        <div class="space-y-3">
          ${p.rooms.map(r => {
            const rp = (r.photos?.length ? r.photos : [r.photo]).filter(Boolean);
            return `
            <div class="border rounded-xl p-3 flex gap-3">
              ${rp.length ? `<div class="relative shrink-0">
                <img src="${esc(rp[0])}" class="w-28 h-24 rounded-lg object-cover" alt="">
                ${rp.length > 1 ? `<span class="absolute bottom-1 right-1 text-[10px] font-medium px-1.5 py-0.5 rounded-full bg-slate-900/70 text-white">+${rp.length - 1}</span>` : ''}
              </div>` : ''}
              <div class="text-sm flex-1 min-w-0">
                <div class="flex items-start justify-between gap-2">
                  <p class="font-medium">${esc(r.name)}</p>
                  <span class="text-[11px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-medium shrink-0">${esc(r.type)}</span>
                </div>
                <p class="text-slate-500">${esc(r.status)} · ${r.occupants || 0} of ${r.capacity || ROOM_TYPES[r.type] || '?'} occupant(s)</p>
                <p class="text-slate-700 font-medium">${fmtPrice(r.rent, r.rentMax)} / mo${+r.deposit ? ` · deposit ${fmtPrice(r.deposit, r.depositMax)}` : ''}</p>
                ${r.amenities?.length ? `<p class="text-xs text-slate-500 mt-1">${r.amenities.map(a => esc(a)).join(' · ')}</p>` : ''}
              </div>
            </div>`;
          }).join('') || '<p class="text-sm text-slate-500">No rooms yet.</p>'}
        </div>
      </div>
      <div class="flex justify-end pt-3 border-t">
        <button id="propModalDel" class="text-sm text-red-600 hover:text-red-700 font-medium">Delete property</button>
      </div>
    </div>`;
  modal.classList.remove('hidden');
  document.body.style.overflow = 'hidden';
  void modal.offsetWidth;                     // reflow so the fade/scale-in transition runs
  modal.classList.add('is-open');
  $('#propModalClose').onclick = closePropModal;
  $('#propModalDel').onclick = () => deleteProperty(p.id);
  // Location map inside the panel
    // Location map inside the panel
  if (window.L) {
    modalMap = L.map('propModalMap', { scrollWheelZoom: false, attributionControl: false }).setView([+p.lat, +p.lng], 17);
    baseTiles().addTo(modalMap);
    L.marker([+p.lat, +p.lng]).addTo(modalMap);
    setTimeout(() => modalMap && modalMap.invalidateSize(), 60);   // size is wrong until the panel finishes showing
  }
}
function closePropModal() {
  modal.classList.remove('is-open');          // fades out (see #propModal transition in properties.php)
  document.body.style.overflow = '';
  clearTimeout(modalTimer);
  modalTimer = setTimeout(() => {             // hide only after the animation finishes
    if (modalMap) { modalMap.remove(); modalMap = null; }
    modal.classList.add('hidden');
    modalCard.innerHTML = '';
  }, 200);
}
$('#propModalBackdrop').onclick = closePropModal;   // clicking outside the card closes the panel
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && modal.classList.contains('is-open')) closePropModal(); });
renderMine();