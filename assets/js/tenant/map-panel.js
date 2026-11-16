(() => {
  'use strict';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const validLocation = p => p.lat !== null && p.lng !== null && p.lat !== '' && p.lng !== '' && Number.isFinite(Number(p.lat)) && Number.isFinite(Number(p.lng)) && Math.abs(Number(p.lat)) <= 90 && Math.abs(Number(p.lng)) <= 180;
  function distance(a, b) {
    const rad = n => Number(n) * Math.PI / 180;
    const lat = rad(b.lat - a.lat), lng = rad(b.lng - a.lng);
    const value = Math.sin(lat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(lng / 2) ** 2;
    return 6371 * 2 * Math.asin(Math.sqrt(Math.min(1, value)));
  }
  function nearestUniversityName(property, campuses = window.UNISTAY_TENANT_UNIVERSITIES || []) {
    if (!validLocation(property) || !campuses.length) return String(property?.university || '');
    return campuses.reduce((best, candidate) =>
      distance(property, candidate) < distance(property, best) ? candidate : best, campuses[0]).name;
  }
  function nearby(properties, campus, filters = {}) {
    const q = String(filters.query || '').trim().toLowerCase();
    if (q) {
      return properties.filter(validLocation).filter(p => {
        const name = String(p.name || '').toLowerCase();
        const addr = String(p.address || '').toLowerCase();
        const uni = String(p.university || '').toLowerCase();
        return name.includes(q) || addr.includes(q) || uni.includes(q);
      }).map(property => ({
        property,
        distance: campus ? distance(property, campus) : null
      })).sort((a, b) => {
        if (a.distance !== null && b.distance !== null) return a.distance - b.distance;
        if (a.distance !== null) return -1;
        if (b.distance !== null) return 1;
        return String(a.property.name).localeCompare(String(b.property.name));
      });
    }
    if (!campus) return [];
    return properties.filter(validLocation).map(property => ({ property, distance: distance(property, campus) }))
      .filter(item => item.distance <= 1 + 1e-9)
      .sort((a, b) => a.distance - b.distance || String(a.property.name).localeCompare(String(b.property.name)));
  }
  window.TenantMapSuggestions = { distance, nearby, validLocation, nearestUniversityName };
  window.createTenantMapPanel = function ({ detail, map, universities, profile, authenticated, profileCompleted, openLandlordProfile, openGateModal }) {
    let properties = [];
    let filters = { university: '', query: '' };
    let state = 'suggestions';
    let selectedDorm = null;
    let selectedRoom = null;
    let refresh = async () => { };
    const registeredCampus = universities.find(u => u.name.toLowerCase() === String(profile?.school || '').trim().toLowerCase());
    let campus = registeredCampus || null;
    const money = value => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value);
    const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const focusHeading = () => {
      if (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA')) {
        return;
      }
      detail.scrollTop = 0;
      const heading = detail.querySelector('h2');
      if (heading) { heading.tabIndex = -1; heading.focus({ preventScroll: true }); }
    };
    const adapter = { classList: detail.classList, open: true, setAttribute() { } };
    function shell(backLabel, backAction) {
      detail.replaceChildren();
      const nav = document.createElement('div');
      nav.className = 'map-panel-nav';

      const back = document.createElement('button');
      back.type = 'button';
      const isRoom = state === 'room';
      back.className = 'room-back-icon';
      back.setAttribute('aria-label', isRoom ? 'Back to dorm' : 'Back to suggestions');
      back.title = isRoom ? 'Back to dorm' : 'Back to suggestions';
      back.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 19-7-7 7-7M5 12h14"/></svg>';
      back.addEventListener('click', backAction);
      nav.append(back);

      if (isRoom) {
        const title = document.createElement('span');
        title.className = 'room-nav-title';
        title.textContent = 'Room details';
        nav.append(title);
      }

      const content = document.createElement('div');
      content.className = 'map-panel-content';
      detail.append(nav, content);
      return content;
    }
    // The same renderer and request handlers power the Dorms dialog and this panel.
    let content = document.createElement('div');
    const contentHost = {
      set innerHTML(html) { content.innerHTML = html; },
      querySelector: selector => content.querySelector(selector),
      querySelectorAll: selector => content.querySelectorAll(selector),
    };
    const renderer = createTenantDormDetails({
      dormModal: adapter, dormModalContent: contentHost,
      IS_AUTHENTICATED: authenticated, PROFILE_COMPLETED: profileCompleted, CAN_VIEW_FULL_ROOMS: authenticated && profileCompleted,
      esc,
      nearestUniversity(lat, lng) {
        const target = campus || universities.reduce((best, u) => distance({ lat, lng }, u) < distance({ lat, lng }, best) ? u : best, universities[0]);
        return { name: target.name, km: distance({ lat, lng }, target).toFixed(1) };
      },
      landlordInitials: name => String(name || 'Landlord').trim().split(/\s+/).slice(0, 2).map(word => word[0]).join('').toUpperCase(),
      openLandlordProfile, openGateModal,
      hasPendingBooking: () => properties.some(property =>
        property.userStayStatus === 'pending' || property.userStayStatus === 'upcoming'),
      isActiveProperty: p => state !== 'suggestions' && String(selectedDorm?.id) === String(p.id),
      onView(view, p, room) {
        state = view; selectedDorm = p; selectedRoom = room || null;
        detail.setAttribute('aria-label', view === 'room' ? 'Selected room' : 'Selected dorm');
        content = shell(view === 'room' ? '← Back to Dorm' : '← Back to Suggestions', () => {
          if (view === 'room') renderer.showDorm(p, 'slide-left'); else showSuggestions();
        });
      },
      onRendered() {
        focusHeading();
      },
      onBookingChange: async () => { await refresh(); },
    });
    function showSuggestions(focus = true, animate = state !== 'suggestions') {
      state = 'suggestions'; selectedDorm = null; selectedRoom = null;
      detail.classList.remove('is-viewing-room'); detail.setAttribute('aria-label', 'Suggested dorms');
      if (!authenticated) {
        detail.innerHTML = `
          <div class="map-suggestions ${animate ? 'anim-slide-in-left' : ''}">
            <h2>Find dorms near your campus</h2>
            <div class="dorm-rooms-locked-card" style="margin-top: 14px;">
              <div class="rooms-locked-banner">
                <div class="locked-icon-bubble">
                  <svg aria-hidden="true"><use href="#lock-icon"/></svg>
                </div>
                <h4>Dorm suggestions are protected</h4>
                <p>Log in or create an account to view personalized dorm suggestions near your campus.</p>
                <button type="button" class="btn-unlock-rooms" id="btn-gate-unlock-map">
                  Log in to show suggestions
                </button>
              </div>
            </div>
          </div>
        `;
        detail.querySelector('#btn-gate-unlock-map')?.addEventListener('click', () => {
          if (typeof openGateModal === 'function') {
            openGateModal();
          } else {
            window.location.href = '../auth/login.php?redirect=' + encodeURIComponent(window.location.pathname);
          }
        });
        detail.scrollTop = 0;
        if (focus) focusHeading();
        return;
      }
      const q = String(filters.query || '').trim();
      const list = nearby(properties, campus, filters);
      const allNearby = campus ? nearby(properties, campus) : [];
      const chooser = !registeredCampus && !q ? `<div class="map-school-choice"><p>${authenticated ? 'Set your registered school in Settings for personalized suggestions, or choose a campus below.' : 'Choose a university to see nearby dorm suggestions.'}</p>${authenticated ? '<a href="settings.php?mode=edit">Set your school</a>' : ''}<label for="map-suggestion-campus">University</label><select id="map-suggestion-campus"><option value="">Choose a university</option>${universities.map(u => `<option value="${esc(u.name)}" ${campus?.name === u.name ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select></div>` : '';
      const emptyTitle = q ? 'No dorms match your search' : (allNearby.length ? 'No nearby dorms match these filters' : 'No dorms listed within 1 km');
      const emptyDesc = q ? 'Try searching for a different dorm name, address, or campus.' : (allNearby.length ? 'Clear the search or choose All universities to see suggestions for your school.' : 'You can still select dorm markers elsewhere on the map or browse the Dorms gallery.');
      const showEmpty = (campus || q) && !list.length;

      detail.innerHTML = `<div class="map-suggestions ${animate ? 'anim-slide-in-left' : ''}"><h2>${q ? 'Search results' : (campus ? 'Dorms near ' + esc(campus.name) : 'Find dorms near your campus')}</h2><p>${q ? `Matching &ldquo;${esc(q)}&rdquo;` : (campus ? 'Within 1 km' : 'Compare locations and explore rooms without leaving the map.')}</p>${chooser}${showEmpty ? `<div class="map-suggestions-empty"><svg aria-hidden="true"><use href="#bed-icon"/></svg><h3>${emptyTitle}</h3><p>${emptyDesc}</p><a href="dorms.php">Browse Dorms</a></div>` : ''}<div class="map-suggestion-list">${list.map(({ property: p, distance: km }) => {
        const photo = p.photos?.[0] || p.photo;
        const rooms = p.rooms || [];
        const available = rooms.filter(r => r.status === 'Available' && Number(r.occupants || 0) < Number(r.capacity)).length;
        const rents = rooms.map(r => Number(r.rent)).filter(n => Number.isFinite(n) && n > 0);
        const distLabel = km !== null ? `${km.toFixed(2)} km from campus · ` : '';
        return `<button type="button" class="map-suggestion" data-property-id="${esc(p.id)}">${photo ? `<img src="${esc(photo)}" alt="" loading="lazy">` : '<span class="map-suggestion-photo"><svg aria-hidden="true"><use href="#bed-icon"/></svg></span>'}<span class="map-suggestion-info"><strong>${esc(p.name)}</strong><span>${esc(p.address)}</span><span>${distLabel}${available} room${available === 1 ? '' : 's'} available</span><b>${rents.length ? 'From ' + esc(money(Math.min(...rents))) + ' / month' : 'Rates not listed'}</b></span></button>`;
      }).join('')}</div></div>`;
      detail.querySelectorAll('[data-property-id]').forEach(button => button.addEventListener('click', () => {
        const property = properties.find(p => String(p.id) === button.dataset.propertyId);
        if (property) selectDorm(property);
      }));
      detail.querySelector('#map-suggestion-campus')?.addEventListener('change', event => {
        campus = universities.find(u => u.name === event.target.value) || null;
        showSuggestions();
        if (campus) map.flyTo([campus.lat, campus.lng], 16, { animate: !reduced(), duration: 0.6 });
      });
      detail.scrollTop = 0;
      if (focus) focusHeading();
    }
    function selectDorm(p) {
      renderer.showDorm(p, 'slide-right');
      if (validLocation(p)) map.flyTo([Number(p.lat), Number(p.lng)], Math.max(map.getZoom(), 16.5), { animate: !reduced(), duration: 0.6 });
    }
    return {
      selectDorm, showSuggestions,
      setProperties(list) {
        properties = list;
        if (selectedDorm) {
          const fresh = list.find(p => String(p.id) === String(selectedDorm.id));
          if (fresh) { Object.assign(selectedDorm, fresh); if (state === 'dorm') renderer.showDorm(selectedDorm); } else showSuggestions();
        } else showSuggestions(false);
      },
      setFilters(university, query = '') {
        filters = { university, query };
        campus = universities.find(u => u.name === university) || registeredCampus || null;
        showSuggestions(false, false);
      },
      setRefresh(callback) { refresh = callback; },
      getState: () => ({ view: state, propertyId: selectedDorm?.id || null, roomId: selectedRoom?.id || null }),
    };
  };
})();
