/**
 * UniStay Landlord Properties Controller
 * Features:
 * - Unified dorms grid and toolbar matching Tenant Dorms UI
 * - Expanded floating workstation modal with 2-column layout
 * - 4 Dagupan University buttons with auto-panning Leaflet map
 * - Live location autocomplete suggestions
 * - 1-3 photo uploader with preview and removal
 * - Page 1 -> Continue -> Page 2 Rooms & Inventory -> Save flow
 * - Enlarged dorm inspection modal (#dorm-modal)
 */

(() => {
  'use strict';

  const $ = (s) => document.querySelector(s);
  const AMENITIES = [
    'WiFi',
    'Aircon',
    'Private CR',
    'Study desk',
    'Laundry',
    'Kitchen',
    'Refrigerator',
    'Water heater',
    'CCTV & Security',
    'Own electric submeter',
    'Balcony',
    'Bed with mattress'
  ];
  const ROOM_TYPES = { SINGLE: 1, TWIN: 2, QUAD: 4, QUINTUPLE: 5, SEXTUPLE: 6, OCTUPLE: 8, DECUPLE: 10 };
  const MAX_ROOM_PHOTOS = 3;

  let photoFiles = [];
  let existingPhotos = [];
  let ownershipProofFile = null;
  let existingOwnershipProof = null;
  let pin = null;
  let myList = [];
  let currentFilter = '';
  let currentSearch = '';

  const landlord = window.CURRENT_LANDLORD || { id: 1, name: 'Juan Dela Cruz', role: 'Landlord' };

  // ---- Dagupan Universities (1 km max radius) ----
  const UNIVERSITIES = [
    { name: 'Universidad de Dagupan',         short: 'UDD',   lat: 16.0507, lng: 120.3408 },
    { name: 'University of Pangasinan',       short: 'UPang', lat: 16.0471, lng: 120.3425 },
    { name: 'University of Luzon',            short: 'UL',    lat: 16.0398, lng: 120.3359 },
    { name: 'Lyceum Northwestern University', short: 'LNU',   lat: 16.0354, lng: 120.3305 },
  ];
  const NEAR_KM = 1.0;
  const PAD = 0.010;
  const OUT_MSG = `Location must be within ${NEAR_KM} km of one of the 4 Dagupan universities.`;

  // Curated Dagupan Streets & Barangays (Strictly Street, Barangay, Dagupan City <= 1.0 km of universities, NO specific places)
  const DAGUPAN_SPOTS = [
    { title: 'Arellano Street, Barangay Pantal, Dagupan City', sub: 'Dagupan City · Near Universidad de Dagupan & UL', lat: 16.0435, lng: 120.3340 },
    { title: 'Perez Boulevard, Barangay Pogo Chico, Dagupan City', sub: 'Dagupan City · Near University of Pangasinan', lat: 16.0465, lng: 120.3418 },
    { title: 'Burgos Street, Barangay Poblacion Oeste, Dagupan City', sub: 'Dagupan City · Near Universidad de Dagupan', lat: 16.0485, lng: 120.3390 },
    { title: 'Tapuac Road, Barangay Tapuac, Dagupan City', sub: 'Dagupan City · Near Lyceum Northwestern University', lat: 16.0360, lng: 120.3310 },
    { title: 'De Venecia Highway, Barangay Tapuac, Dagupan City', sub: 'Dagupan City · Near Lyceum Northwestern University', lat: 16.0320, lng: 120.3280 },
    { title: 'M.H. Del Pilar Street, Barangay Herrero-Perez, Dagupan City', sub: 'Dagupan City · Near University of Pangasinan', lat: 16.0475, lng: 120.3440 },
    { title: 'Rizal Extension, Barangay Dagupan Centro, Dagupan City', sub: 'Dagupan City · Near University of Luzon', lat: 16.0405, lng: 120.3365 },
    { title: 'MacArthur Highway, Barangay Mayombo, Dagupan City', sub: 'Dagupan City · Near University of Pangasinan', lat: 16.0450, lng: 120.3470 },
    { title: 'Zamora Street, Barangay Pantal, Dagupan City', sub: 'Dagupan City · Near Universidad de Dagupan', lat: 16.0520, lng: 120.3350 },
    { title: 'Malued Road, Barangay Malued, Dagupan City', sub: 'Dagupan City · Near University of Luzon', lat: 16.0410, lng: 120.3410 },
    { title: 'Fernandez Avenue, Barangay Downtown, Dagupan City', sub: 'Dagupan City · Near University of Pangasinan', lat: 16.0450, lng: 120.3380 },
    { title: 'Guilig Street, Barangay Poblacion, Dagupan City', sub: 'Dagupan City · Near University of Luzon', lat: 16.0420, lng: 120.3348 }
  ];

  const _lats = UNIVERSITIES.map(u => u.lat), _lngs = UNIVERSITIES.map(u => u.lng);
  const BOUNDS = L.latLngBounds(
    [Math.min(..._lats) - PAD, Math.min(..._lngs) - PAD],
    [Math.max(..._lats) + PAD, Math.max(..._lngs) + PAD]
  );

  function nearestUni(lat, lng) {
    const rad = (d) => d * Math.PI / 180;
    return UNIVERSITIES.map(u => {
      const a = Math.sin(rad(lat - u.lat) / 2) ** 2 +
                Math.cos(rad(u.lat)) * Math.cos(rad(lat)) * Math.sin(rad(lng - u.lng) / 2) ** 2;
      return { uni: u, km: 6371 * 2 * Math.asin(Math.sqrt(a)) };
    }).sort((x, y) => x.km - y.km)[0];
  }

  const STREET_ZOOM = 18, UNI_ZOOM = 17;
  const baseTiles = () => L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 });

  const kmLat = NEAR_KM / 111.32;
  const DEFAULT_BOUNDS = L.latLngBounds(UNIVERSITIES.flatMap(u => {
    const kmLng = NEAR_KM / (111.32 * Math.cos(u.lat * Math.PI / 180));
    return [[u.lat - kmLat, u.lng - kmLng], [u.lat + kmLat, u.lng + kmLng]];
  }));

  // Initialize Leaflet map with zoom lock preventing zooming out past the 4 universities
  const map = L.map('pickMap', {
    maxBounds: BOUNDS,
    maxBoundsViscosity: 1.0,
    maxZoom: STREET_ZOOM,
    zoomSnap: 0.25,
    attributionControl: false
  });
  baseTiles().addTo(map);
  map.fitBounds(DEFAULT_BOUNDS, { padding: [16, 16] });

  const HOME_VIEW = { center: map.getCenter(), zoom: map.getZoom() };
  // Lock minZoom to the 4 universities bounding view so user cannot zoom out further
  map.setMinZoom(HOME_VIEW.zoom);

  // University circles
  const areaCircles = L.layerGroup().addTo(map);
  UNIVERSITIES.forEach(u => {
    L.circle([u.lat, u.lng], {
      radius: NEAR_KM * 1000,
      color: '#c25835',
      weight: 1.8,
      dashArray: '4',
      fillColor: '#f78361',
      fillOpacity: 0.10,
      interactive: false
    }).addTo(areaCircles);

    L.circleMarker([u.lat, u.lng], {
      radius: 7,
      color: '#fff',
      weight: 2,
      fillColor: '#b03525',
      fillOpacity: 1,
      bubblingMouseEvents: false
    }).addTo(map).bindTooltip(u.name);
  });

  // Reset View Control
  const ResetView = L.Control.extend({
    options: { position: 'bottomleft' },
    onAdd() {
      const bar = L.DomUtil.create('div', 'leaflet-bar');
      const btn = L.DomUtil.create('a', '', bar);
      btn.href = '#'; btn.role = 'button';
      btn.title = 'Reset view';
      btn.textContent = '⤢ Reset view';
      btn.style.cssText = 'width:auto;padding:0 12px;font-size:12px;line-height:28px;white-space:nowrap;cursor:pointer;background:#fff;color:#7a4b3a;font-weight:600;border:none;';
      L.DomEvent.disableClickPropagation(bar);
      L.DomEvent.on(btn, 'click', (e) => {
        L.DomEvent.preventDefault(e);
        map.flyTo(HOME_VIEW.center, HOME_VIEW.zoom, { duration: 0.8 });
      });
      return bar;
    }
  });
  map.addControl(new ResetView());

  let marker = null;
  let locMode = 'type';
  const ADDR_RE = /^[^,]{2,},\s*[^,]{2,},\s*[^,]{2,}(,\s*[^,]{2,})?$/;
  const addrInput = $('#addressInput');
  const addrHint = $('#addrHint');
  const uniInput = $('#universityInput');
  const suggestionsBox = $('#locSuggestions');

  function setHint(msg, color = 'var(--muted)') {
    if (!addrHint) return;
    addrHint.textContent = msg;
    addrHint.style.color = color;
  }

  function onPinContextMenu(e) {
    if (e && e.originalEvent) {
      L.DomEvent.preventDefault(e.originalEvent);
      L.DomEvent.stopPropagation(e.originalEvent);
    }
    if (!marker) return;

    const popupContent = document.createElement('div');
    popupContent.className = 'pin-context-menu';
    popupContent.innerHTML = `
      <div style="font-size:13px;font-weight:700;color:var(--ink);margin-bottom:3px;">Dorm Pin Options</div>
      <p style="font-size:11.5px;color:var(--muted);margin:0 0 10px;line-height:1.35;">What would you like to do with this pinned location?</p>
      <div style="display:flex;flex-direction:column;gap:6px;">
        <button type="button" class="pin-remove-btn" style="display:flex;align-items:center;justify-content:center;gap:6px;width:100%;padding:8px 12px;border-radius:8px;border:1px solid #f6cfca;background:#fff5f4;color:#b03525;font-size:12px;font-weight:600;cursor:pointer;transition:all 0.15s ease;">
          <svg style="width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2;"><path d="M2 2l10 10M12 2L2 12"/></svg>
          Remove this pin
        </button>
        <button type="button" class="pin-cancel-btn" style="display:flex;align-items:center;justify-content:center;width:100%;padding:6px 12px;border-radius:8px;border:1px solid #ebd9cb;background:#fff;color:var(--text);font-size:11.5px;font-weight:600;cursor:pointer;transition:all 0.15s ease;">
          Keep Pin (Cancel)
        </button>
      </div>
    `;

    popupContent.querySelector('.pin-remove-btn').onclick = (evt) => {
      evt.stopPropagation();
      map.closePopup();
      clearPin();
      if (addrInput) addrInput.value = '';
      setHint('Pin removed. Click on the map or type an address to place a new pin.', 'var(--muted)');
    };

    popupContent.querySelector('.pin-cancel-btn').onclick = (evt) => {
      evt.stopPropagation();
      map.closePopup();
    };

    L.popup({ offset: [0, -14], closeButton: true, className: 'unistay-pin-popup' })
      .setLatLng(marker.getLatLng())
      .setContent(popupContent)
      .openOn(map);
  }

  function placePin(lat, lng, zoom) {
    pin = { lat: +(+lat).toFixed(6), lng: +(+lng).toFixed(6) };
    const ll = [pin.lat, pin.lng];
    if (marker) {
      marker.setLatLng(ll);
    } else {
      marker = L.marker(ll, { draggable: true }).addTo(map);
      marker.on('dragend', onPinDragged);
      marker.on('contextmenu', onPinContextMenu);
      setTimeout(() => {
        const el = marker?.getElement();
        if (el) el.addEventListener('contextmenu', (e) => e.preventDefault());
      }, 50);
    }
    if (zoom) map.setView(ll, zoom);
  }

  function clearPin() {
    pin = null;
    if (marker) { map.removeLayer(marker); marker = null; }
  }

  function onPinDragged() {
    const ll = marker.getLatLng();
    const near = nearestUni(ll.lat, ll.lng);
    if (near.km > NEAR_KM) {
      marker.setLatLng([pin.lat, pin.lng]);
      return setHint(OUT_MSG, '#b03525');
    }
    placePin(ll.lat, ll.lng);
    selectUniversity(near.uni.name, false);
    reverseFill();
  }

  // Location setting mode: type vs map
  function setLocationMode(m) {
    locMode = m;
    $('#locModeInput').value = m;
    document.querySelectorAll('.loc-mode-btn').forEach(btn => {
      btn.classList.toggle('is-active', btn.dataset.mode === m);
    });
    map.getContainer().style.cursor = (m === 'map') ? 'crosshair' : '';
    setHint(m === 'map'
      ? 'Click anywhere inside the allowed circles on the map to pin your dorm.'
      : 'Type your street and barangay. Live suggestions will appear below.');
  }

  document.querySelectorAll('.loc-mode-btn').forEach(btn => {
    btn.onclick = () => setLocationMode(btn.dataset.mode);
  });

  map.on('click', (e) => {
    const near = nearestUni(e.latlng.lat, e.latlng.lng);
    if (near.km > NEAR_KM) {
      setHint(OUT_MSG, '#b03525');
      return;
    }
    placePin(e.latlng.lat, e.latlng.lng);
    selectUniversity(near.uni.name, false);
    reverseFill();
  });

  // Reverse geocode
  async function reverseFill() {
    if (!addrInput || !pin) return;
    setHint('Looking up address…');
    try {
      const r = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=18&lat=${pin.lat}&lon=${pin.lng}`, { headers: { Accept: 'application/json' } });
      if (!r.ok) throw new Error();
      const d = await r.json();
      const a = d.address || {};
      const street = [a.house_number, a.road || a.pedestrian || a.footway].filter(Boolean).join(' ');
      const brgy = a.suburb || a.neighbourhood || a.quarter || a.village || a.hamlet;
      const city = a.city || a.town || a.municipality || 'Dagupan City';
      let parts = [street, brgy, city].filter(Boolean);
      if (parts.length < 3) parts = (d.display_name || '').split(',').map(s => s.trim()).filter(Boolean).slice(0, 3);
      addrInput.value = parts.join(', ');
      setHint('Address filled in from pin ✓', '#27522e');
    } catch {
      setHint('Pin placed on map ✓', '#27522e');
    }
  }

  // University selector button click (removes dropdown, auto-pans map, waits for user to click map to place pin)
  function selectUniversity(uniName, panMap = true) {
    if (!uniName) return;
    uniInput.value = uniName;
    document.querySelectorAll('.uni-picker-btn').forEach(b => {
      b.classList.toggle('is-selected', b.dataset.uni === uniName);
    });
    if (panMap) {
      const u = UNIVERSITIES.find(x => x.name === uniName);
      if (u) {
        map.flyTo([u.lat, u.lng], UNI_ZOOM, { duration: 0.8 });
        if (!pin) {
          setHint(`Map panned to near ${u.short}. Click anywhere on the map to pin your dorm location.`, 'var(--muted)');
        }
      }
    }
  }

  document.querySelectorAll('.uni-picker-btn').forEach(btn => {
    btn.onclick = () => selectUniversity(btn.dataset.uni, true);
  });

  // ---- Live Location Autocomplete Suggestions ----
  let suggestTimer = 0;
  function renderSuggestions(matches) {
    if (!suggestionsBox) return;
    if (!matches.length) {
      suggestionsBox.hidden = true;
      suggestionsBox.innerHTML = '';
      return;
    }
    suggestionsBox.innerHTML = matches.map((m, idx) => `
      <div class="suggestion-item" data-idx="${idx}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9c0 5-7 12-7 12S5 14 5 9a7 7 0 0 1 14 0Z"/><circle cx="12" cy="9" r="2"/></svg>
        <div class="suggestion-text">
          <strong>${esc(m.title)}</strong>
          <small>${esc(m.sub)}</small>
        </div>
      </div>
    `).join('');
    suggestionsBox.hidden = false;

    suggestionsBox.querySelectorAll('.suggestion-item').forEach(item => {
      item.onclick = () => {
        const idx = +item.dataset.idx;
        const spot = matches[idx];
        if (!spot) return;
        addrInput.value = spot.title;
        suggestionsBox.hidden = true;
        placePin(spot.lat, spot.lng, 17);
        const near = nearestUni(spot.lat, spot.lng);
        selectUniversity(near.uni.name, true);
        setHint('Location selected and pinned ✓', '#27522e');
      };
    });
  }

  if (addrInput) {
    addrInput.addEventListener('input', () => {
      clearTimeout(suggestTimer);
      const query = addrInput.value.trim().toLowerCase();
      if (query.length < 2) {
        renderSuggestions([]);
        return;
      }

      suggestTimer = setTimeout(async () => {
        // 1. Local Dagupan Landmarks/Barangays match (strictly <= 1 km)
        const localMatches = DAGUPAN_SPOTS.filter(s =>
          (s.title.toLowerCase().includes(query) || s.sub.toLowerCase().includes(query)) &&
          nearestUni(s.lat, s.lng).km <= NEAR_KM
        );

        // 2. Fetch Nominatim within viewbox, strictly <= 1 km, NO specific places (Street, Barangay, City only)
        let osmMatches = [];
        try {
          const vb = [BOUNDS.getWest(), BOUNDS.getNorth(), BOUNDS.getEast(), BOUNDS.getSouth()].join(',');
          const r = await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&limit=10&countrycodes=ph&viewbox=${vb}&q=${encodeURIComponent(query + ' Dagupan')}`, { headers: { Accept: 'application/json' } });
          if (r.ok) {
            const data = await r.json();
            const commercialClasses = new Set(['amenity', 'shop', 'tourism', 'leisure', 'office', 'craft', 'commercial', 'club']);
            osmMatches = data
              .filter(d => {
                const lat = +d.lat, lon = +d.lon;
                if (Number.isNaN(lat) || Number.isNaN(lon)) return false;
                if (nearestUni(lat, lon).km > NEAR_KM) return false;
                if (d.class && commercialClasses.has(d.class)) return false;
                return true;
              })
              .map(d => {
                const addr = d.address || {};
                const road = (addr.road || addr.pedestrian || addr.footway || addr.residential || '').trim();
                let brgy = (addr.suburb || addr.neighbourhood || addr.quarter || addr.village || addr.hamlet || '').trim();
                if (brgy) {
                  brgy = brgy.replace(/^barangay\s+/i, '').trim();
                  brgy = 'Barangay ' + brgy;
                }

                let title = '';
                if (road && brgy) {
                  title = `${road}, ${brgy}, Dagupan City`;
                } else if (brgy) {
                  title = `${brgy}, Dagupan City`;
                } else if (road) {
                  title = `${road}, Dagupan City`;
                } else {
                  return null;
                }

                const near = nearestUni(+d.lat, +d.lon);
                return {
                  title,
                  sub: `Dagupan City · ${near.km.toFixed(1)} km from ${near.uni.short}`,
                  lat: +d.lat,
                  lng: +d.lon
                };
              })
              .filter(Boolean);
          }
        } catch (_) {}

        // Deduplicate and ensure strict 1 km proximity boundary
        const combined = [...localMatches];
        osmMatches.forEach(om => {
          if (nearestUni(om.lat, om.lng).km <= NEAR_KM && !combined.some(c => c.title.toLowerCase() === om.title.toLowerCase() || (Math.abs(c.lat - om.lat) < 0.001 && Math.abs(c.lng - om.lng) < 0.001))) {
            combined.push(om);
          }
        });

        renderSuggestions(combined.slice(0, 5));
      }, 250);
    });

    // Close suggestions on outside click
    document.addEventListener('click', (e) => {
      if (!e.target.closest('.loc-input-wrap') && suggestionsBox) {
        suggestionsBox.hidden = true;
      }
    });
  }

  // ---- Photo Upload (1 to 3 photos) ----
  function renderPhotos() {
    const box = $('#photoPreview');
    const cnt = $('#photoCount');
    const prompt = $('#photoPromptWrap');
    if (!box) return;

    if (!photoFiles.length && !existingPhotos.length) {
      if (prompt) prompt.style.display = 'flex';
      box.style.display = 'none';
      box.innerHTML = '';
      if (cnt) cnt.style.display = 'none';
      return;
    }

    if (prompt) prompt.style.display = 'none';
    box.style.display = 'flex';

    let thumbs = '';
    if (photoFiles.length > 0) {
      thumbs = photoFiles.map((f, i) => `
        <div class="photo-preview-thumb">
          <img src="${URL.createObjectURL(f)}" alt="Dorm photo ${i + 1}">
          <button type="button" class="photo-thumb-rm" data-rm="${i}" aria-label="Remove photo">✕</button>
        </div>
      `).join('');

      if (photoFiles.length < 3) {
        thumbs += `
          <div class="photo-add-slot" id="photoAddSlot">
            <span style="font-size:18px;line-height:1;">＋</span>
            <span>Add more</span>
          </div>
        `;
      }
    } else if (existingPhotos.length > 0) {
      thumbs = existingPhotos.map((src, i) => `
        <div class="photo-preview-thumb">
          <img src="${esc(src)}" alt="Current photo ${i + 1}">
        </div>
      `).join('') + `
        <div class="photo-add-slot" id="photoAddSlot">
          <span style="font-size:18px;line-height:1;">＋</span>
          <span>Upload new</span>
        </div>
      `;
    }

    box.innerHTML = thumbs;

    box.querySelectorAll('.photo-thumb-rm').forEach(btn => {
      btn.onclick = (e) => {
        e.stopPropagation();
        photoFiles.splice(+btn.dataset.rm, 1);
        renderPhotos();
      };
    });

    const addSlot = box.querySelector('#photoAddSlot');
    if (addSlot) {
      addSlot.onclick = (e) => {
        e.stopPropagation();
        photoInput.click();
      };
    }

    if (cnt) {
      const numPhotos = photoFiles.length || existingPhotos.length;
      cnt.textContent = `${numPhotos} of 3 photo(s) selected ✓`;
      cnt.style.display = 'block';
      cnt.style.color = '#27522e';
    }
  }

  function addPhotos(incoming) {
    for (const f of incoming) {
      if (photoFiles.length >= 3) break;
      if (!f.type.startsWith('image/') || f.size > 5 * 1024 * 1024) continue;
      if (!photoFiles.some(p => p.name === f.name && p.size === f.size)) {
        photoFiles.push(f);
      }
    }
    renderPhotos();
  }

  const photoInput = $('#photoInput');
  const photoZone = $('#photoZone');
  if (photoInput) {
    photoInput.onchange = (e) => { addPhotos([...e.target.files]); e.target.value = ''; };
  }
  if (photoZone && photoInput) {
    photoZone.onclick = (e) => {
      if (e.target.closest('.photo-thumb-rm') || e.target.closest('#photoAddSlot')) return;
      if (e.target !== photoInput && photoFiles.length < 3) photoInput.click();
    };
    photoZone.ondragover = (e) => { e.preventDefault(); photoZone.classList.add('dz-over'); };
    photoZone.ondragleave = () => photoZone.classList.remove('dz-over');
    photoZone.ondrop = (e) => {
      e.preventDefault();
      photoZone.classList.remove('dz-over');
      if (e.dataTransfer?.files?.length) addPhotos([...e.dataTransfer.files]);
    };
  }

  // ---- Proof of Property Ownership (Page 1) ----
  const ownershipInput = $('#ownershipInput');
  const ownershipZone = $('#ownershipProofZone');
  const ownershipPreview = $('#ownershipPreview');
  const ownershipPromptWrap = $('#ownershipPromptWrap');
  const ownershipCount = $('#ownershipCount');

  function renderOwnershipProof() {
    if (!ownershipPreview || !ownershipPromptWrap || !ownershipCount) return;

    if (ownershipProofFile) {
      ownershipPromptWrap.style.display = 'none';
      ownershipPreview.style.display = 'flex';
      ownershipCount.style.display = 'block';
      ownershipCount.textContent = '1 document selected ✓';
      ownershipCount.style.color = '#27522e';

      const isImg = ownershipProofFile.type.startsWith('image/');
      if (isImg) {
        ownershipPreview.innerHTML = `
          <div class="photo-preview-thumb">
            <img src="${URL.createObjectURL(ownershipProofFile)}" alt="Ownership Document">
            <button type="button" class="photo-thumb-rm" id="rmOwnershipProof" aria-label="Remove document">✕</button>
          </div>
        `;
      } else {
        ownershipPreview.innerHTML = `
          <div class="ownership-doc-badge">
            <span class="ownership-doc-icon">📄</span>
            <div class="ownership-doc-info">
              <strong class="ownership-doc-name">${esc(ownershipProofFile.name)}</strong>
              <span class="ownership-doc-size">${(ownershipProofFile.size / (1024 * 1024)).toFixed(2)} MB · Document</span>
            </div>
            <button type="button" class="photo-thumb-rm" id="rmOwnershipProof" aria-label="Remove document" style="position:static; margin-left:auto;">✕</button>
          </div>
        `;
      }

      const rmBtn = ownershipPreview.querySelector('#rmOwnershipProof');
      if (rmBtn) {
        rmBtn.onclick = (e) => {
          e.stopPropagation();
          ownershipProofFile = null;
          existingOwnershipProof = null;
          renderOwnershipProof();
        };
      }
    } else if (existingOwnershipProof) {
      ownershipPromptWrap.style.display = 'none';
      ownershipPreview.style.display = 'flex';
      ownershipCount.style.display = 'block';
      ownershipCount.textContent = '1 document on file ✓';
      ownershipCount.style.color = '#27522e';

      const isImg = /\.(jpg|jpeg|png|webp)$/i.test(existingOwnershipProof);
      const fileName = existingOwnershipProof.split('/').pop() || 'Ownership Document';
      if (isImg) {
        ownershipPreview.innerHTML = `
          <div class="photo-preview-thumb">
            <img src="${esc(existingOwnershipProof)}" alt="Ownership Document">
            <button type="button" class="photo-thumb-rm" id="rmOwnershipProof" aria-label="Remove document">✕</button>
          </div>
        `;
      } else {
        ownershipPreview.innerHTML = `
          <div class="ownership-doc-badge">
            <span class="ownership-doc-icon">📄</span>
            <div class="ownership-doc-info">
              <strong class="ownership-doc-name">${esc(fileName)}</strong>
              <span class="ownership-doc-size">Uploaded Ownership Proof</span>
            </div>
            <button type="button" class="photo-thumb-rm" id="rmOwnershipProof" aria-label="Remove document" style="position:static; margin-left:auto;">✕</button>
          </div>
        `;
      }

      const rmBtn = ownershipPreview.querySelector('#rmOwnershipProof');
      if (rmBtn) {
        rmBtn.onclick = (e) => {
          e.stopPropagation();
          ownershipProofFile = null;
          existingOwnershipProof = null;
          renderOwnershipProof();
        };
      }
    } else {
      ownershipPromptWrap.style.display = 'flex';
      ownershipPreview.style.display = 'none';
      ownershipPreview.innerHTML = '';
      ownershipCount.style.display = 'none';
    }
  }

  function setOwnershipFile(file) {
    if (!file) return;
    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    const ext = file.name.split('.').pop().toLowerCase();
    if (!allowedTypes.includes(file.type) && !['jpg', 'jpeg', 'png', 'webp', 'pdf'].includes(ext)) {
      alert('Proof of ownership must be a JPG, PNG, WebP image or PDF document.');
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      alert('Proof of ownership file must be under 10 MB.');
      return;
    }
    ownershipProofFile = file;
    existingOwnershipProof = null;
    renderOwnershipProof();
  }

  if (ownershipInput) {
    ownershipInput.onchange = (e) => {
      if (e.target.files && e.target.files[0]) {
        setOwnershipFile(e.target.files[0]);
      }
      e.target.value = '';
    };
  }

  if (ownershipZone && ownershipInput) {
    ownershipZone.onclick = (e) => {
      if (e.target.closest('#rmOwnershipProof')) return;
      if (e.target !== ownershipInput && !ownershipProofFile && !existingOwnershipProof) {
        ownershipInput.click();
      }
    };
    ownershipZone.ondragover = (e) => { e.preventDefault(); ownershipZone.classList.add('dz-over'); };
    ownershipZone.ondragleave = () => ownershipZone.classList.remove('dz-over');
    ownershipZone.ondrop = (e) => {
      e.preventDefault();
      ownershipZone.classList.remove('dz-over');
      if (e.dataTransfer?.files?.length) {
        setOwnershipFile(e.dataTransfer.files[0]);
      }
    };
  }

  // ---- Rooms (Page 2) ----
  const typeOptions = Object.keys(ROOM_TYPES).map(t => `<option>${t}</option>`).join('');

  function attachRoomPhotoPicker(zone, input, preview, count, prompt) {
    const files = [];
    const render = () => {
      if (!files.length) {
        if (prompt) prompt.style.display = 'flex';
        preview.style.display = 'none';
        preview.innerHTML = '';
        if (count) count.style.display = 'none';
        return;
      }

      if (prompt) prompt.style.display = 'none';
      preview.style.display = 'flex';

      let thumbs = files.map((f, i) => `
        <div class="photo-preview-thumb">
          <img src="${URL.createObjectURL(f)}" alt="Room photo ${i + 1}">
          <button type="button" class="photo-thumb-rm" data-rm="${i}" aria-label="Remove photo">✕</button>
        </div>
      `).join('');

      if (files.length < MAX_ROOM_PHOTOS) {
        thumbs += `
          <div class="photo-add-slot" data-add-slot>
            <span style="font-size:18px;line-height:1;">＋</span>
            <span>Add more</span>
          </div>
        `;
      }

      preview.innerHTML = thumbs;

      preview.querySelectorAll('.photo-thumb-rm').forEach(btn => {
        btn.onclick = (e) => {
          e.stopPropagation();
          files.splice(+btn.dataset.rm, 1);
          render();
        };
      });

      const addSlot = preview.querySelector('[data-add-slot]');
      if (addSlot) {
        addSlot.onclick = (e) => {
          e.stopPropagation();
          input.click();
        };
      }

      if (count) {
        count.textContent = `${files.length} of ${MAX_ROOM_PHOTOS} photo(s) selected ✓`;
        count.style.display = 'block';
        count.style.color = '#27522e';
      }
    };

    const add = (incoming) => {
      for (const f of incoming) {
        if (files.length >= MAX_ROOM_PHOTOS) break;
        if (!f.type.startsWith('image/') || f.size > 5 * 1024 * 1024) continue;
        if (!files.some(p => p.name === f.name && p.size === f.size)) {
          files.push(f);
        }
      }
      render();
    };

    input.onchange = (e) => { add([...e.target.files]); e.target.value = ''; };
    zone.onclick = (e) => {
      if (e.target.closest('.photo-thumb-rm') || e.target.closest('[data-add-slot]')) return;
      if (e.target !== input && files.length < MAX_ROOM_PHOTOS) input.click();
    };
    zone.ondragover = (e) => { e.preventDefault(); zone.classList.add('dz-over'); };
    zone.ondragleave = () => zone.classList.remove('dz-over');
    zone.ondrop = (e) => {
      e.preventDefault();
      zone.classList.remove('dz-over');
      if (e.dataTransfer?.files?.length) add([...e.dataTransfer.files]);
    };

    render();
    return { files, clear: () => { files.length = 0; render(); } };
  }

  function addRoom() {
    const d = document.createElement('div');
    d.className = 'room-item-box';
    d.innerHTML = `
      <div class="room-item-header">
        <strong>Room details</strong>
        <button type="button" class="rm-room-btn" aria-label="Remove room">✕ Remove</button>
      </div>
      <div class="room-fields-grid">
        <div class="field-block">
          <label class="field-label">Room name / number *</label>
          <input data-f="name" required class="field-input" placeholder="e.g. Room 101">
        </div>
        <div class="field-block">
          <label class="field-label">Room type *</label>
          <select data-f="type" class="field-input">${typeOptions}</select>
        </div>
        <div class="field-block">
          <label class="field-label">Monthly rent (₱) *</label>
          <input type="number" data-f="rent" min="0" step="1" required class="field-input" placeholder="e.g. 3500">
        </div>
        <div class="field-block">
          <label class="field-label">Deposit (₱)</label>
          <input type="number" data-f="deposit" min="0" step="1" class="field-input" placeholder="e.g. 1000">
        </div>
      </div>
      <div class="field-block" style="margin-top:16px;">
        <span class="field-label">Room Photos <small class="field-help">(Upload 1 to 3 photos)</small></span>
        <div data-zone class="photo-drop-box">
          <div data-prompt class="photo-prompt-wrap">
            <div class="photo-drop-icon">📷</div>
            <span class="photo-drop-title">Drag &amp; drop photos here</span>
            <span class="photo-drop-hint">or click to browse · JPG, PNG, WebP up to 5 MB (1–3 photos)</span>
          </div>
          <div data-preview class="photo-preview-grid" style="display:none;"></div>
          <p data-count class="field-help" style="display:none;margin-top:4px;"></p>
          <input data-f="photo" type="file" accept="image/*" multiple style="display:none;">
        </div>
      </div>
      <div class="field-block" style="margin-top:16px;">
        <span class="field-label">Amenities</span>
        <div class="amenities-grid">
          ${AMENITIES.map(a => `
            <label class="amenity-checkbox-item">
              <input type="checkbox" value="${a}">
              <span>${a}</span>
            </label>
          `).join('')}
        </div>
      </div>
    `;

    d.querySelector('.rm-room-btn').onclick = () => {
      if (document.querySelectorAll('.room-item-box').length > 1) {
        d.remove();
        updateRoomHeaders();
      }
    };

    d.picker = attachRoomPhotoPicker(
      d.querySelector('[data-zone]'),
      d.querySelector('[data-f="photo"]'),
      d.querySelector('[data-preview]'),
      d.querySelector('[data-count]'),
      d.querySelector('[data-prompt]')
    );
    $('#rooms').appendChild(d);
    updateRoomHeaders();
  }

  function updateRoomHeaders() {
    const boxes = document.querySelectorAll('.room-item-box');
    boxes.forEach((box, idx) => {
      const title = box.querySelector('.room-item-header strong');
      if (title) title.textContent = `Room ${idx + 1} details`;
      const rmBtn = box.querySelector('.rm-room-btn');
      if (rmBtn) {
        rmBtn.style.display = boxes.length > 1 ? 'inline-flex' : 'none';
      }
    });
  }

  if ($('#addRoom')) {
    $('#addRoom').onclick = () => {
      addRoom();
      setTimeout(() => {
        const boxes = document.querySelectorAll('.room-item-box');
        const last = boxes[boxes.length - 1];
        if (last) last.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }, 50);
    };
  }
  if ($('#rooms')) addRoom();

  // ---- 2-Step Workstation Navigation ----
  const modalOverlay = $('#modalOverlay');
  const page1Grid = $('#page1Grid');
  const page2Grid = $('#page2Grid');
  const page1Footer = $('#page1Footer');
  const page2Footer = $('#page2Footer');
  const stepBadge = $('#stepBadge');
  const errorMsg1 = $('#errorMsg1');
  const errorMsg2 = $('#errorMsg2');
  const btnContinue = $('#btnPage1Continue');
  const btnBack = $('#btnPage2Back');
  const btnEditBackToProperty = $('#btnEditBackToProperty');
  let currentInspectingProperty = null;

  let isAddClosing = false;
  function openAddModal() {
    if (!modalOverlay || isAddClosing) return;
    modalOverlay.classList.remove('is-closing');
    modalOverlay.hidden = false;
    document.body.style.overflow = 'hidden';

    if (!$('#editPropId')?.value) {
      if ($('#modalMainHeading')) $('#modalMainHeading').textContent = 'Add a Property';
      if ($('#savePropBtn')) $('#savePropBtn').textContent = 'Save Property';
      if (btnEditBackToProperty) btnEditBackToProperty.hidden = true;
      currentInspectingProperty = null;
      existingPhotos = [];
      photoFiles = [];
      renderPhotos();
      existingOwnershipProof = null;
      ownershipProofFile = null;
      renderOwnershipProof();
      clearPin();
      if (uniInput) uniInput.value = '';
      document.querySelectorAll('.uni-picker-btn').forEach(b => b.classList.remove('is-selected'));
      if (addrInput) addrInput.value = '';
      if (suggestionsBox) { suggestionsBox.hidden = true; suggestionsBox.innerHTML = ''; }
      setHint('Click anywhere inside the allowed university radius on the map, or select a university / type an address.', 'var(--muted)');
    }

    goToPage(1);
  }

  function closeAddModal() {
    if (!modalOverlay || modalOverlay.hidden || isAddClosing) return;
    isAddClosing = true;
    modalOverlay.classList.add('is-closing');
    setTimeout(() => {
      modalOverlay.hidden = true;
      modalOverlay.classList.remove('is-closing');
      document.body.style.overflow = '';
      isAddClosing = false;
      if ($('#editPropId')) $('#editPropId').value = '';
      if ($('#modalMainHeading')) $('#modalMainHeading').textContent = 'Add a Property';
      if ($('#savePropBtn')) $('#savePropBtn').textContent = 'Save Property';
      if (btnEditBackToProperty) btnEditBackToProperty.hidden = true;
      existingPhotos = [];
      photoFiles = [];
      existingOwnershipProof = null;
      ownershipProofFile = null;
      renderOwnershipProof();
    }, 200);
  }

  function openEditModal(p) {
    if (!p) return;
    currentInspectingProperty = p;
    if ($('#editPropId')) $('#editPropId').value = p.id;
    if ($('#modalMainHeading')) $('#modalMainHeading').textContent = 'Edit Property';
    if ($('#savePropBtn')) $('#savePropBtn').textContent = 'Save Changes';

    if (btnEditBackToProperty) {
      btnEditBackToProperty.hidden = false;
      btnEditBackToProperty.onclick = () => {
        closeAddModal();
        if (currentInspectingProperty) {
          openInspectionModal(currentInspectingProperty);
        }
      };
    }

    openAddModal();

    if ($('#propName')) $('#propName').value = p.name || '';
    if (addrInput) addrInput.value = p.address || '';
    if ($('#propDesc')) $('#propDesc').value = p.description || '';
    if (p.university) selectUniversity(p.university, false);
    if (p.lat && p.lng) {
      placePin(p.lat, p.lng, 17);
    }

    existingPhotos = (p.photos && p.photos.length) ? p.photos : (p.photo ? [p.photo] : []);
    photoFiles = [];
    renderPhotos();

    existingOwnershipProof = p.proof_of_ownership || p.proofOfOwnership || null;
    ownershipProofFile = null;
    renderOwnershipProof();

    const roomsContainer = $('#rooms');
    if (roomsContainer) {
      roomsContainer.innerHTML = '';
      if (p.rooms && p.rooms.length) {
        p.rooms.forEach(r => {
          addRoom();
          const lastBox = roomsContainer.lastElementChild;
          if (lastBox) {
            const setVal = (f, v) => {
              const el = lastBox.querySelector(`[data-f="${f}"]`);
              if (el) el.value = v;
            };
            setVal('name', r.name || '');
            setVal('type', r.type || 'SINGLE');
            setVal('rent', r.rent || '');
            setVal('deposit', r.deposit || '');
            if (Array.isArray(r.amenities)) {
              lastBox.querySelectorAll('input[type=checkbox]').forEach(cb => {
                cb.checked = r.amenities.includes(cb.value);
              });
            }
          }
        });
      } else {
        addRoom();
      }
    }
  }

  function goToPage(page) {
    if (page === 1) {
      page1Grid.hidden = false;
      page1Footer.hidden = false;
      page2Grid.hidden = true;
      page2Footer.hidden = true;
      stepBadge.textContent = 'Step 1 of 2: Property Details';
      setTimeout(() => {
        map.invalidateSize();
        if (pin) {
          map.setView([pin.lat, pin.lng], 17);
        } else {
          map.fitBounds(DEFAULT_BOUNDS, { padding: [16, 16], maxZoom: 16 });
          map.setMinZoom(HOME_VIEW.zoom);
        }
      }, 100);
    } else {
      page1Grid.hidden = true;
      page1Footer.hidden = true;
      page2Grid.hidden = false;
      page2Footer.hidden = false;
      stepBadge.textContent = 'Step 2 of 2: Rooms & Inventory';
    }
  }

  if ($('#openAddModalBtn')) $('#openAddModalBtn').onclick = openAddModal;
  if ($('#closeModalBtn')) $('#closeModalBtn').onclick = closeAddModal;

  // Click outside Add a Property card to close
  if (modalOverlay) {
    modalOverlay.addEventListener('click', (e) => {
      if (e.target === modalOverlay) {
        closeAddModal();
      }
    });
  }

  // Continue to Page 2 validation
  if (btnContinue) {
    btnContinue.onclick = () => {
      errorMsg1.hidden = true;
      const name = $('#propName')?.value.trim();
      const uni = uniInput?.value;
      const address = addrInput?.value.trim();

      if (!name) {
        errorMsg1.textContent = 'Please enter a dorm name.';
        errorMsg1.hidden = false;
        return;
      }
      if (!uni) {
        errorMsg1.textContent = 'Please select which Dagupan university this dorm is near.';
        errorMsg1.hidden = false;
        return;
      }
      if (!address || !ADDR_RE.test(address)) {
        errorMsg1.textContent = 'Address format must be: Street, Barangay, City.';
        errorMsg1.hidden = false;
        return;
      }
      if (!pin) {
        errorMsg1.textContent = 'Please place a pin for your dorm location on the map.';
        errorMsg1.hidden = false;
        return;
      }
      const isEditing = Boolean($('#editPropId')?.value);
      if (!isEditing && (photoFiles.length < 1 || photoFiles.length > 3)) {
        errorMsg1.textContent = 'Please upload 1 to 3 photos of your dorm.';
        errorMsg1.hidden = false;
        return;
      }
      if (photoFiles.length > 3) {
        errorMsg1.textContent = 'Please upload up to 3 photos of your dorm.';
        errorMsg1.hidden = false;
        return;
      }
      if (!isEditing && !ownershipProofFile && !existingOwnershipProof) {
        errorMsg1.textContent = 'Please upload Proof of Property Ownership (Land Title, Deed of Sale, Tax Declaration, or Lease).';
        errorMsg1.hidden = false;
        return;
      }

      goToPage(2);
    };
  }

  if (btnBack) {
    btnBack.onclick = () => goToPage(1);
  }

  // Submit Form (Page 2)
  $('#propForm').onsubmit = async (e) => {
    e.preventDefault();
    errorMsg2.hidden = true;

    const editId = $('#editPropId')?.value;
    const f = new FormData();
    if (editId) f.set('property_id', editId);
    f.set('name', $('#propName').value.trim());
    f.set('university', uniInput.value);
    f.set('address', addrInput.value.trim());
    f.set('description', $('#propDesc').value.trim());
    f.set('lat', pin.lat);
    f.set('lng', pin.lng);
    f.set('common_school_pct', 0);

    const roomBoxes = [...document.querySelectorAll('.room-item-box')];

    const rooms = roomBoxes.map(r => {
      const g = (k) => r.querySelector(`[data-f="${k}"]`)?.value.trim() || '';
      const type = g('type') || 'SINGLE';
      const rentVal = +(g('rent') || 0);
      const depVal = +(g('deposit') || 0);
      return {
        name: g('name'),
        type,
        capacity: ROOM_TYPES[type] || 1,
        rent: rentVal,
        rentMax: null,
        deposit: depVal,
        depositMax: null,
        occupants: 0,
        status: 'Available',
        amenities: [...r.querySelectorAll('input[type=checkbox]:checked')].map(c => c.value)
      };
    });

    if (rooms.some(r => !r.name || !r.rent)) {
      errorMsg2.textContent = 'Each room needs a name and monthly payment.';
      errorMsg2.hidden = false;
      return;
    }

    photoFiles.forEach(pf => f.append('photos[]', pf));
    if (ownershipProofFile) {
      f.append('proof_of_ownership', ownershipProofFile);
    } else if (existingOwnershipProof) {
      f.append('existing_proof_of_ownership', existingOwnershipProof);
    }
    roomBoxes.forEach((el, i) => {
      if (el.picker?.files?.length) {
        el.picker.files.forEach(rp => f.append(`room_photo_${i}[]`, rp));
      }
    });
    f.append('rooms', JSON.stringify(rooms));

    try {
      await Store.add(f);
    } catch (err) {
      errorMsg2.textContent = err.message || 'Error saving property.';
      errorMsg2.hidden = false;
      return;
    }

    // Success
    closeAddModal();
    e.target.reset();
    $('#rooms').innerHTML = '';
    addRoom();
    photoFiles = [];
    existingPhotos = [];
    ownershipProofFile = null;
    existingOwnershipProof = null;
    renderPhotos();
    renderOwnershipProof();
    clearPin();
    if (uniInput) uniInput.value = '';
    if ($('#editPropId')) $('#editPropId').value = '';
    if ($('#modalMainHeading')) $('#modalMainHeading').textContent = 'Add a Property';
    if ($('#savePropBtn')) $('#savePropBtn').textContent = 'Save Property';
    document.querySelectorAll('.uni-picker-btn').forEach(b => b.classList.remove('is-selected'));

    const toast = $('#toast');
    if (toast) {
      toast.textContent = editId ? 'Property updated!' : 'Property saved!';
      toast.style.display = 'block';
      setTimeout(() => { toast.style.display = 'none'; }, 2500);
    }

    renderMine();
  };

  // ---- Properties Grid Rendering (Matching Tenant Dorms) ----
  const grid = $('#dorms-grid');
  const searchInput = $('#prop-search');
  const uniSelect = $('#uni-select');
  const uniBtn = uniSelect?.querySelector('.um-select-btn');
  const uniValue = uniSelect?.querySelector('.um-select-value');
  const uniMenu = uniSelect?.querySelector('.um-select-menu');
  const dormModal = $('#dorm-modal');
  const dormModalCloseBtn = $('#dorm-modal-close-btn');
  const dormModalContent = $('#dorm-modal-content');
  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function fmtPrice(val) {
    const num = Number(val || 0);
    return '₱' + num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function landlordInitials(name) {
    return String(name || '').trim().split(/\s+/).filter(Boolean).slice(0, 2)
      .map(w => w[0]).join('').toUpperCase() || '?';
  }

  function shortUni(uni) {
    const u = String(uni || '').toLowerCase();
    if (u.includes('dagupan') || u.includes('udd')) return 'UdD';
    if (u.includes('pangasinan') || u.includes('upang')) return 'UPang';
    if (u.includes('luzon') || u.includes(' ul') || u === 'ul') return 'UL';
    if (u.includes('lyceum') || u.includes('lnu')) return 'LNU';
    return uni || 'Dagupan';
  }

  async function renderMine() {
    try {
      myList = await Store.byLandlord();
    } catch (err) {
      console.error(err);
      myList = [];
    }
    applyGridFilter();
  }

  function applyGridFilter() {
    if (!grid) return;
    const filtered = myList.filter(p => {
      const matchUni = !currentFilter || p.university === currentFilter;
      const matchSearch = !currentSearch ||
        p.name.toLowerCase().includes(currentSearch) ||
        p.address.toLowerCase().includes(currentSearch) ||
        p.university.toLowerCase().includes(currentSearch);
      return matchUni && matchSearch;
    });

    if (!filtered.length) {
      grid.innerHTML = `
        <div class="dorms-empty" style="grid-column:1/-1;text-align:center;padding:48px 20px;">
          <div class="dorms-empty-icon" style="font-size:36px;margin-bottom:10px;">🏠</div>
          <h3 style="font-family:Georgia,serif;font-size:20px;color:var(--ink);margin:0 0 8px;">No properties found</h3>
          <p style="font-size:13.5px;color:var(--muted);margin:0;">${myList.length ? 'Try adjusting your search or university filter.' : 'You have not listed any properties yet. Click "+ Add a Property" to begin!'}</p>
        </div>
      `;
      return;
    }

    grid.innerHTML = filtered.map(p => {
      const coverPhoto = p.photos?.[0] || p.photo || '../assets/images/tenant/default-dorm.jpg';
      const minRent = p.rooms?.length ? Math.min(...p.rooms.map(r => +r.rent || 0).filter(Boolean)) : 0;
      const totalOccupants = p.tenants?.length || 0;
      const totalCapacity = p.rooms?.reduce((acc, r) => acc + (r.capacity || 0), 0) || 0;

      return `
        <article class="dorm-card" data-id="${p.id}" tabindex="0" role="button" aria-label="View ${esc(p.name)}" style="cursor:pointer;">
          <div class="dorm-card-photo-wrap">
            <img class="dorm-card-photo" src="${esc(coverPhoto)}" alt="${esc(p.name)}" loading="lazy">
            <div class="dorm-card-actions">
              <button type="button" class="dorm-card-edit-btn" data-id="${p.id}" title="Edit ${esc(p.name)}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                <span>Edit</span>
              </button>
            </div>
          </div>

          <div class="dorm-card-body">
            <div class="dorm-card-meta-top">
              <h3 class="dorm-card-title">${esc(p.name)}</h3>
              <div class="dorm-card-location">
                <svg aria-hidden="true"><use href="#pin-icon"/></svg>
                <span>${esc(p.address)}</span>
              </div>
              <div class="dorm-card-uni">
                <svg aria-hidden="true"><use href="#school-icon"/></svg>
                <span>Near ${esc(p.university)}</span>
              </div>
            </div>

            <!-- All Tenants Display Strip -->
            <div class="dorm-card-tenants-wrap" title="${totalOccupants ? esc(p.tenants.map(t => t.name).join(', ')) : 'No occupants registered yet'}">
              <div class="dorm-card-occ-header">
                <div class="dorm-card-occ-pill">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                  <span><strong>${totalOccupants}</strong> / ${totalCapacity} Occupants</span>
                </div>
              </div>
              ${totalOccupants > 0 ? `
                <div class="dorm-card-all-tenants" aria-label="Current tenants">
                  ${p.tenants.map(t => `
                    <div class="dorm-card-tenant-badge" title="${esc(t.name)} · ${esc(t.school || 'Student')}">
                      <span class="dorm-card-tenant-avatar">${esc(landlordInitials(t.name))}</span>
                      <span class="dorm-card-tenant-name">${esc(t.name)}</span>
                    </div>
                  `).join('')}
                </div>
              ` : `
                <div class="dorm-card-tenants-vacant">
                  <span>No occupants registered yet · All ${totalCapacity} beds vacant</span>
                </div>
              `}
            </div>

            <div class="dorm-card-footer-rent">
              <span class="dorm-card-rent-label">Starting rent:</span>
              <strong class="dorm-card-rent-value">${minRent ? fmtPrice(minRent) + ' / mo' : 'No rooms yet'}</strong>
            </div>
          </div>
        </article>
      `;
    }).join('');

    // Attach card clicks
    grid.querySelectorAll('.dorm-card').forEach(card => {
      card.addEventListener('click', () => {
        const id = +card.dataset.id;
        const p = myList.find(x => x.id === id);
        if (p) openInspectionModal(p);
      });
    });

    // Attach edit button clicks
    grid.querySelectorAll('.dorm-card-edit-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const id = +btn.dataset.id;
        const p = myList.find(x => x.id === id);
        if (p) openEditModal(p);
      });
    });
  }

  // Toolbar search and custom dropdown
  if (searchInput) {
    searchInput.addEventListener('input', (e) => {
      currentSearch = e.target.value.trim().toLowerCase();
      applyGridFilter();
    });
  }

  if (uniSelect && uniBtn && uniMenu && uniValue) {
    uniBtn.onclick = () => {
      const open = uniBtn.getAttribute('aria-expanded') === 'true';
      uniBtn.setAttribute('aria-expanded', String(!open));
      uniMenu.hidden = open;
    };

    uniMenu.querySelectorAll('li[role="option"]').forEach(opt => {
      opt.onclick = () => {
        const val = opt.getAttribute('data-value') || '';
        const label = opt.textContent.trim();
        currentFilter = val;
        uniValue.textContent = val ? label : 'Filter by university';
        uniBtn.classList.toggle('is-placeholder', !val);

        // Update aria-selected across all options so "All universities" doesn't stay permanently selected
        uniMenu.querySelectorAll('li[role="option"]').forEach(item => {
          const isTarget = (item === opt);
          item.setAttribute('aria-selected', isTarget ? 'true' : 'false');
          item.classList.toggle('is-selected', isTarget);
        });

        uniMenu.hidden = true;
        uniBtn.setAttribute('aria-expanded', 'false');
        applyGridFilter();
      };
    });

    document.addEventListener('click', (e) => {
      if (!uniSelect.contains(e.target)) {
        uniMenu.hidden = true;
        uniBtn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // ---- Enlarged Floating Dorm Inspection Modal ----
  let inspectionMap = null;
  function openInspectionModal(p) {
    if (!dormModal || !dormModalContent) return;
    if (inspectionMap) { inspectionMap.remove(); inspectionMap = null; }
    currentInspectingProperty = p;

    const modalTitle = $('#dorm-modal-title');
    if (modalTitle) modalTitle.textContent = p.name || 'Property overview';

    const photos = p.photos?.length ? p.photos : (p.photo ? [p.photo] : ['../assets/images/tenant/default-dorm.jpg']);
    const rooms = p.rooms || [];
    // Photo Stage: 1 photo -> compact rounded photo; 2 or 3 photos -> containerless 3D coverflow carousel
    let photoStageHtml = '';
    if (photos.length === 1) {
      photoStageHtml = `
        <div class="dorm-single-square-wrap">
          <img src="${esc(photos[0])}" alt="${esc(p.name)}" loading="lazy">
        </div>
      `;
    } else {
      photoStageHtml = `
        <div class="um-carousel" id="inspectCarousel">
          <div class="um-carousel-track">
            ${photos.map((src, idx) => `
              <img class="um-slide ${idx === 0 ? 'is-active' : (idx === 1 ? 'is-next' : '')}"
                   src="${esc(src)}"
                   data-idx="${idx}"
                   alt="${esc(p.name)} photo ${idx + 1}"
                   loading="lazy">
            `).join('')}
          </div>
          <button type="button" class="um-carousel-btn prev" id="carouselPrevBtn" aria-label="Previous photo">
            <svg viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></svg>
          </button>
          <button type="button" class="um-carousel-btn next" id="carouselNextBtn" aria-label="Next photo">
            <svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg>
          </button>
          <div class="um-carousel-count" id="carouselCount">1 / ${photos.length}</div>
        </div>
      `;
    }

    dormModalContent.innerHTML = `
      <!-- Left Half: Brown Login Theme, Fixed non-scrollable workstation -->
      <div class="dorm-inspect-col-left">
        <!-- Top Group: Dorm Name on left, Location to its right, and shortened Near University to the right of location -->
        <div class="dorm-modal-meta-top">
          <div class="dorm-modal-title-row">
            <h2 id="dorm-modal-title" class="dorm-modal-main-title">${esc(p.name)}</h2>
            <span class="dorm-modal-title-meta">
              <span class="dorm-modal-side-address" title="${esc(p.address)}">
                <svg class="dorm-modal-meta-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                <span>${esc(p.address)}</span>
              </span>
              <span class="dorm-modal-meta-dot">•</span>
              <span class="dorm-modal-side-uni">
                <svg class="dorm-modal-meta-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                <span>near ${esc(shortUni(p.university))}</span>
              </span>
            </span>
          </div>
        </div>

        <!-- Mid-Row: Photo Stage on Left, Description Box on Right -->
        <div class="dorm-modal-mid-row">
          <div class="dorm-modal-mid-photo">
            ${photoStageHtml}
          </div>
          <div class="dorm-modal-desc-box dorm-modal-mid-desc">
            <strong class="dorm-modal-desc-title">Description</strong>
            <p class="dorm-modal-desc-text">${esc(p.description || 'No additional description provided.')}</p>
          </div>
        </div>

        <!-- Property Location Map positioned below mid-row -->
        <div class="dorm-modal-map-wrap">
          <div class="dorm-modal-map-header">
            <strong>Property Location on Map</strong>
          </div>
          <div id="inspectionModalMap" class="dorm-modal-map um-map"></div>
        </div>

        <!-- Left Column Action Buttons (Edit + Delete) -->
        <div class="dorm-modal-left-actions">
          <button type="button" id="dormModalEditBtn" class="dorm-inspect-edit-btn" title="Edit property">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            <span>Edit property</span>
          </button>
          <button type="button" id="dormModalDeleteBtn" class="dorm-inspect-del-btn" title="Delete property">
            <svg style="width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;"><path d="M3 6h18m-2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            <span>Delete property</span>
          </button>
        </div>
      </div>

      <!-- Right Half: Clean White Workstation, Only Scrollable Half -->
      <div class="dorm-inspect-col-right">
        <div class="dorm-modal-rooms-header">
          <h3 class="dorm-modal-rooms-title">Available Rooms (${rooms.length})</h3>
          <span class="dorm-modal-rooms-avail">${rooms.filter(r => r.status === 'Available').length} available</span>
        </div>
        <div class="dorm-modal-rooms-grid">
          ${rooms.length ? rooms.map(r => {
            const rPhotos = (r.photos && r.photos.length) ? r.photos : (r.photo ? [r.photo] : []);
            return `
            <div class="dorm-modal-room-card">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
                <div>
                  <strong style="font-size:15px;color:var(--ink);">${esc(r.name)}</strong>
                  <span style="font-size:11.5px;padding:2px 8px;border-radius:12px;background:#eef1eb;color:#27522e;font-weight:600;margin-left:6px;">${esc(r.type)}</span>
                  <p style="margin:4px 0 0;font-size:12px;color:var(--muted);">${r.occupants || 0} of ${r.capacity} occupants · <span style="font-weight:600;color:${r.status === 'Available' ? '#27522e' : '#b03525'}">${esc(r.status || 'Available')}</span></p>
                </div>
                <div style="text-align:right;">
                  <span style="font-size:16px;font-weight:700;color:var(--brown-darker);">${fmtPrice(r.rent)}</span>
                  <span style="font-size:11px;color:var(--muted);">/ mo</span>
                  ${r.deposit ? `<div style="font-size:11.5px;color:var(--muted);margin-top:2px;">Deposit: ${fmtPrice(r.deposit)}</div>` : ''}
                </div>
              </div>

              <!-- Visible Room Photos -->
              ${rPhotos.length ? `
                <div class="dorm-modal-room-photos count-${Math.min(rPhotos.length, 3)}">
                  ${rPhotos.slice(0, 3).map((src, pIdx) => `
                    <div class="dorm-modal-room-photo-item" title="${esc(r.name)} photo ${pIdx + 1}">
                      <img src="${esc(src)}" alt="${esc(r.name)} photo ${pIdx + 1}" loading="lazy" class="dorm-modal-room-img" onclick="window.open('${esc(src)}', '_blank')">
                    </div>
                  `).join('')}
                </div>
              ` : ''}

              <!-- Room Tenants List -->
              ${r.tenants && r.tenants.length ? `
                <div class="modal-room-tenants-wrap">
                  <div class="modal-room-tenants-header">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <strong>Current Tenants (${r.tenants.length} of ${r.capacity} beds occupied)</strong>
                  </div>
                  <div class="modal-room-tenants-list">
                    ${r.tenants.map(t => `
                      <div class="modal-tenant-chip">
                        <span class="modal-tenant-avatar">${esc(landlordInitials(t.name))}</span>
                        <div class="modal-tenant-meta">
                          <span class="modal-tenant-name">${esc(t.name)}</span>
                          <span class="modal-tenant-school">${esc(t.school || 'Student')}</span>
                        </div>
                      </div>
                    `).join('')}
                  </div>
                </div>
              ` : `
                <div class="modal-room-empty-beds">
                  <span>🛏️ All ${r.capacity} bed(s) available · No current occupants</span>
                </div>
              `}

              ${r.amenities?.length ? `
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;padding-top:8px;border-top:1px dashed #f0e6df;">
                  ${r.amenities.map(a => `<span style="font-size:11px;padding:2px 8px;border-radius:6px;background:#fbf8f5;border:1px solid #ebd9cb;color:var(--brown);">${esc(a)}</span>`).join('')}
                </div>
              ` : ''}
            </div>
          `;
          }).join('') : '<p style="font-size:13.5px;color:var(--muted);padding:24px 0;text-align:center;">No rooms configured for this property.</p>'}
        </div>
      </div>
    `;

    let isDormClosing = false;
    function closeDormModal() {
      if (!dormModal || !dormModal.open || isDormClosing) return;
      isDormClosing = true;
      dormModal.classList.add('is-closing');
      setTimeout(() => {
        dormModal.close();
        dormModal.classList.remove('is-closing');
        isDormClosing = false;
      }, 200);
    }

    // Wire up edit button in content
    const editBtn = $('#dormModalEditBtn');
    if (editBtn) {
      editBtn.onclick = () => {
        closeDormModal();
        openEditModal(p);
      };
    }

    // Wire up delete button in content
    const delBtn = $('#dormModalDeleteBtn');
    if (delBtn) {
      delBtn.onclick = async () => {
        if (!confirm(`Are you sure you want to delete "${p.name}" and all of its rooms?`)) return;
        try {
          await Store.remove(p.id);
          closeDormModal();
          renderMine();
        } catch (err) {
          alert(err.message || 'Error deleting property.');
        }
      };
    }

    dormModal.classList.remove('is-closing');
    dormModal.showModal();

    // Wire carousel navigation for 2 or 3 photos (Bounded 3D Coverflow Carousel)
    if (photos.length > 1) {
      let currentIdx = 0;
      const slides = dormModalContent.querySelectorAll('.um-slide');
      const prevBtn = dormModalContent.querySelector('#carouselPrevBtn');
      const nextBtn = dormModalContent.querySelector('#carouselNextBtn');
      const countEl = dormModalContent.querySelector('#carouselCount');

      function updateCarousel() {
        slides.forEach((sl, idx) => {
          sl.classList.remove('is-active', 'is-prev', 'is-next');
          if (idx === currentIdx) {
            sl.classList.add('is-active');
          } else if (idx === currentIdx - 1) {
            // Photo immediately before active moves to the left
            sl.classList.add('is-prev');
          } else if (idx === currentIdx + 1) {
            // Photo immediately after active is at the right
            sl.classList.add('is-next');
          }
        });

        // Prev button bounds: disabled at first photo
        if (prevBtn) {
          const canPrev = currentIdx > 0;
          prevBtn.style.opacity = canPrev ? '1' : '0.22';
          prevBtn.style.pointerEvents = canPrev ? 'auto' : 'none';
          prevBtn.style.cursor = canPrev ? 'pointer' : 'default';
        }

        // Next button bounds: disabled at last photo
        if (nextBtn) {
          const canNext = currentIdx < photos.length - 1;
          nextBtn.style.opacity = canNext ? '1' : '0.22';
          nextBtn.style.pointerEvents = canNext ? 'auto' : 'none';
          nextBtn.style.cursor = canNext ? 'pointer' : 'default';
        }

        if (countEl) {
          countEl.textContent = `${currentIdx + 1} / ${photos.length}`;
        }
      }

      if (prevBtn) {
        prevBtn.onclick = (e) => {
          e.stopPropagation();
          if (currentIdx > 0) {
            currentIdx--;
            updateCarousel();
          }
        };
      }

      if (nextBtn) {
        nextBtn.onclick = (e) => {
          e.stopPropagation();
          if (currentIdx < photos.length - 1) {
            currentIdx++;
            updateCarousel();
          }
        };
      }

      slides.forEach((sl, idx) => {
        sl.onclick = (e) => {
          e.stopPropagation();
          if (idx !== currentIdx) {
            currentIdx = idx;
            updateCarousel();
          }
        };
      });

      updateCarousel();
    }

    if (dormModalCloseBtn) dormModalCloseBtn.onclick = () => closeDormModal();

    // Click outside dorm inspection modal card to close
    dormModal.onclick = (e) => {
      const rect = dormModal.getBoundingClientRect();
      const inDialog = (
        rect.top <= e.clientY && e.clientY <= rect.top + rect.height &&
        rect.left <= e.clientX && e.clientX <= rect.left + rect.width
      );
      if (!inDialog) {
        closeDormModal();
      }
    };

    if (window.L && p.lat && p.lng) {
      const mapEl = document.getElementById('inspectionModalMap');
      if (mapEl) {
        L.DomEvent.disableScrollPropagation(mapEl);
        mapEl.addEventListener('wheel', (e) => e.stopPropagation(), { passive: false });
      }

      inspectionMap = L.map('inspectionModalMap', {
        scrollWheelZoom: true,
        zoomControl: true,
        attributionControl: false,
        minZoom: HOME_VIEW.zoom,
        maxBounds: BOUNDS,
        maxBoundsViscosity: 1.0
      }).setView([+p.lat, +p.lng], 17);
      baseTiles().addTo(inspectionMap);
      const m = L.marker([+p.lat, +p.lng]).addTo(inspectionMap);
      m.bindPopup(`<strong style="font-size:13px;color:var(--brown);">${esc(p.name)}</strong><br><span style="font-size:11.5px;color:#555;">${esc(p.address)}</span>`).openPopup();
      setTimeout(() => inspectionMap && inspectionMap.invalidateSize(), 120);
    }
  }

  // Close modals on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      if (modalOverlay && !modalOverlay.hidden) closeAddModal();
      if (dormModal && dormModal.open) dormModal.close();
    }
  });

  // Initial load
  renderMine();
})();