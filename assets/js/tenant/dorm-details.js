window.createTenantDormDetails = function (options) {
  'use strict';
  const { dormModal, dormModalContent, dormModalTitle = null, dormModalBackBtn = null,
    dormModalCloseBtn = null, IS_AUTHENTICATED, CAN_VIEW_FULL_ROOMS,
    esc, nearestUniversity, landlordInitials, openLandlordProfile, openGateModal,
    updateStayCounts = () => { }, applyFilters = () => { }, onView = () => { },
    onRendered = () => { }, onBookingChange = () => { }, isActiveProperty = () => true } = options;
  const pendingRequests = new Set();
  const transitionToRoom = (p, room) => openRoomDetailsModal(p, room, 'slide-right');
  const transitionBackToDorm = p => openEnlargedModal(p, 'slide-left');
  function fmtPrice(val) {
    const num = Number(val || 0);
    return '₱' + num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  // Helper to extract sanitized room photo URLs
  function getRoomPhotos(r) {
    if (!r) return [];
    let list = [];
    if (Array.isArray(r.photos) && r.photos.length) {
      list = r.photos;
    } else if (typeof r.photo === 'string' && r.photo.trim() !== '' && r.photo !== '[]') {
      try {
        const parsed = JSON.parse(r.photo);
        list = Array.isArray(parsed) ? parsed : [r.photo];
      } catch (e) {
        list = [r.photo];
      }
    }
    return list.map(p => {
      if (!p || typeof p !== 'string') return '';
      if (p.startsWith('http://') || p.startsWith('https://') || p.startsWith('data:') || p.startsWith('../')) return p;
      return '../' + p.replace(/^\/+/, '');
    }).filter(Boolean);
  }

  function openEnlargedModal(p, transitionDirection) {
    onView('dorm', p);
    // Reset Header State for Dorm Details View
    dormModal.classList.remove('is-viewing-room');
    if (dormModalTitle) dormModalTitle.textContent = 'Dorm details';
    dormModal.setAttribute('aria-labelledby', 'dorm-modal-title');
    if (dormModalBackBtn) {
      dormModalBackBtn.style.display = 'none';
      dormModalBackBtn.onclick = null;
    }
    if (dormModalCloseBtn) {
      dormModalCloseBtn.style.display = '';
    }

    const photos = p.photos && p.photos.length ? p.photos : (p.photo ? [p.photo] : ['../uploads/properties/st_albert_dorm.jpg']);
    const nearest = nearestUniversity(p.lat, p.lng);
    const rooms = p.rooms || [];
    const initials = landlordInitials(p.landlordName);
    const lPhotoModal = p.landlordPhoto || (p.landlord && p.landlord.photo) || null;
    const landlordAvatarModalHtml = lPhotoModal
      ? `<span class="dorm-modal-landlord-avatar has-img"><img src="${esc(lPhotoModal)}" alt="${esc(p.landlordName)}" class="dorm-modal-landlord-img" onerror="this.style.display='none';this.nextElementSibling.style.display='grid';"><span style="display:none;">${esc(initials)}</span></span>`
      : `<span class="dorm-modal-landlord-avatar">${esc(initials)}</span>`;
    const totalResidents = Number(p.totalResidents || 0);
    const sameSchoolResidents = Number(p.sameSchoolResidents || 0);
    const livePct = Number(p.livePct ?? (p.commonSchoolPct || 0));

    let studentStatHtml = '';
    if (totalResidents > 0) {
      studentStatHtml = `
        <div class="dorm-side-fact">
          <svg><use href="#people-icon"/></svg>
          <span><b>${livePct}%</b> students staying here are from <b>${esc(p.university)}</b> <small style="color:rgba(255,255,255,0.7);">(${sameSchoolResidents}/${totalResidents})</small></span>
        </div>
      `;
    } else {
      studentStatHtml = `
        <div class="dorm-side-fact">
          <svg><use href="#people-icon"/></svg>
          <span>No residents yet · <b style="color:#fcd34d;">Be the 1st from ${esc(p.university)}!</b></span>
        </div>
      `;
    }

    const bookingActionHtml = IS_AUTHENTICATED && p.userStayStatus === 'upcoming'
      ? `<button type="button" class="btn-dorm-booking-action btn-dorm-cancel-booking" id="btn-toggle-booking" data-id="${p.id}">Cancel Stay</button>`
      : '';

    // Property Card Style Carousel for Dorm Details Modal
    const carouselHtml = photos.length > 0 ? `
      <div class="um-carousel" id="dorm-modal-carousel">
        <div class="um-carousel-track">
          ${photos.map((src, idx) => `
            <img class="um-slide ${idx === 0 ? (photos.length === 1 ? 'is-active is-only' : 'is-active') : (idx === 1 ? 'is-next' : '')}"
                 src="${esc(src)}"
                 data-idx="${idx}"
                 alt="${esc(p.name)} photo ${idx + 1}"
                 loading="lazy">
          `).join('')}
        </div>
        ${photos.length > 1 ? `
          <button type="button" class="um-carousel-btn prev" id="dorm-carousel-prev" aria-label="Previous photo">
            <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"></polyline></svg>
          </button>
          <button type="button" class="um-carousel-btn next" id="dorm-carousel-next" aria-label="Next photo">
            <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>
          </button>
          <div class="um-carousel-count" id="dorm-carousel-count">1 / ${photos.length}</div>
        ` : ''}
      </div>
    ` : '';

    // Rooms list formatting (Option 2: Frosted Glass Overlay)
    let roomsHtml = '';
    if (!rooms.length) {
      roomsHtml = '<p style="color:#78665c;">No room units listed currently.</p>';
    } else {
      const firstRoom = rooms[0];
      const otherRooms = rooms.slice(1);
      const firstPhotos = getRoomPhotos(firstRoom);
      const firstCover = firstPhotos.length ? firstPhotos[0] : null;

      const firstRoomHtml = `
        <div class="room-card-preview" data-room-id="${firstRoom.id}" role="button" tabindex="0" title="Click to view ${esc(firstRoom.name)} details">
          <div class="room-photo-ph ${firstCover ? 'has-img' : ''}">
            ${firstCover ? `<img src="${esc(firstCover)}" alt="${esc(firstRoom.name)}" loading="lazy">` : `<svg><use href="#bed-icon"/></svg>`}
            ${firstPhotos.length > 1 ? `<span class="room-photo-count-badge">+${firstPhotos.length - 1}</span>` : ''}
          </div>
          <div class="room-card-info">
            <h4>${esc(firstRoom.name)}</h4>
            <p>${esc(firstRoom.type)} · ${firstRoom.capacity} Bed${firstRoom.capacity === 1 ? '' : 's'}</p>
            <p class="room-price">${fmtPrice(firstRoom.rent)} / month</p>
            <button type="button" class="btn-view-room" data-room-id="${firstRoom.id}">
              View Room <svg><use href="#arrow-icon"/></svg>
            </button>
          </div>
        </div>
      `;

      let othersHtml = '';
      if (otherRooms.length > 0) {
        if (CAN_VIEW_FULL_ROOMS) {
          // If profile completed, show all rooms cleanly
          othersHtml = otherRooms.map(r => {
            const rPhotos = getRoomPhotos(r);
            const rCover = rPhotos.length ? rPhotos[0] : null;
            return `
            <div class="room-card-preview" data-room-id="${r.id}" role="button" tabindex="0" title="Click to view ${esc(r.name)} details">
              <div class="room-photo-ph ${rCover ? 'has-img' : ''}">
                ${rCover ? `<img src="${esc(rCover)}" alt="${esc(r.name)}" loading="lazy">` : `<svg><use href="#bed-icon"/></svg>`}
                ${rPhotos.length > 1 ? `<span class="room-photo-count-badge">+${rPhotos.length - 1}</span>` : ''}
              </div>
              <div class="room-card-info">
                <h4>${esc(r.name)}</h4>
                <p>${esc(r.type)} · ${r.capacity} Bed${r.capacity === 1 ? '' : 's'}</p>
                <p class="room-price">${fmtPrice(r.rent)} / month</p>
                <button type="button" class="btn-view-room" data-room-id="${r.id}">
                  View Room <svg><use href="#arrow-icon"/></svg>
                </button>
              </div>
            </div>
          `;
          }).join('');
        } else {
          // Frosted Glass Overlay for visitors AND users with incomplete profiles
          const unlockBtnText = IS_AUTHENTICATED ? 'Complete profile to unlock' : 'Log in to unlock';
          const unlockDescText = IS_AUTHENTICATED
            ? 'Complete your profile in Settings to unlock all room options, see full photos, and contact landlords.'
            : 'Sign in to unlock all room options, see full photos, and check real-time availability.';

          const blurredPreview = otherRooms.slice(0, 2).map(r => {
            const rPhotos = getRoomPhotos(r);
            const rCover = rPhotos.length ? rPhotos[0] : null;
            return `
            <div class="room-card-preview" style="margin-bottom:8px;">
              <div class="room-photo-ph ${rCover ? 'has-img' : ''}">
                ${rCover ? `<img src="${esc(rCover)}" alt="">` : `<svg><use href="#bed-icon"/></svg>`}
              </div>
              <div class="room-card-info">
                <h4>${esc(r.name)}</h4>
                <p>${esc(r.type)}</p>
                <p class="room-price">${fmtPrice(r.rent)} / month</p>
              </div>
            </div>
          `;
          }).join('');

          othersHtml = `
            <div class="rooms-frosted-wrap">
              <div class="rooms-frosted-stack" aria-hidden="true">
                ${blurredPreview}
              </div>
              <div class="rooms-frosted-overlay" id="frosted-overlay-btn" role="button" tabindex="0" aria-label="Unlock more rooms">
                <div class="frosted-lock-icon">
                  <svg aria-hidden="true"><use href="#lock-icon"/></svg>
                </div>
                <strong>${otherRooms.length} more room${otherRooms.length === 1 ? '' : 's'} in this dorm</strong>
                <p>${esc(unlockDescText)}</p>
                <button type="button" class="btn-frosted-unlock">
                  ${esc(unlockBtnText)} <svg style="width:14px;height:14px;"><use href="#arrow-icon"/></svg>
                </button>
              </div>
            </div>
          `;
        }
      }

      roomsHtml = firstRoomHtml + othersHtml;
    }

    dormModalContent.innerHTML = `
      <div class="dorm-details-view ${transitionDirection === 'slide-left' ? 'anim-slide-in-left' : ''}">
        <!-- Left Column: Brown-Themed Panel matching Landlord view -->
        <div class="dorm-modal-left dorm-details-left-panel">
          <!-- Centered photo carousel above left-aligned dorm information. -->
          <div class="dorm-mid-row">
            <div class="dorm-mid-photo">
              ${carouselHtml}
            </div>
            <div class="dorm-mid-meta">
              <h2 class="dorm-mid-title">${esc(p.name)}</h2>
              <div class="dorm-side-fact">
                <svg><use href="#pin-icon"/></svg>
                <span><b>${esc(p.address)}</b></span>
              </div>
              <div class="dorm-side-fact">
                <svg><use href="#school-icon"/></svg>
                <span>Near <b>${esc(p.university)}</b></span>
              </div>
              ${studentStatHtml}
              <div class="dorm-side-fact">
                <svg><use href="#measure-icon"/></svg>
                <span>Distance: <b>${nearest.km} km</b> from campus</span>
              </div>
            </div>
          </div>

          <!-- Description Box with uppercase title -->
          <div class="dorm-modal-desc-box">
            <strong class="dorm-modal-desc-title">Description</strong>
            <p class="dorm-modal-desc-text">${esc(p.description || 'No additional description provided.')}</p>
          </div>

          <!-- Landlord Profile Card on Brown -->
          <div class="dorm-modal-landlord dorm-landlord-on-brown" id="dorm-modal-landlord-btn" role="button" tabindex="0" title="Click to view landlord profile &amp; chat">
            <div class="dorm-modal-landlord-inner">
              ${landlordAvatarModalHtml}
              <div>
                <div class="dorm-landlord-name">${esc(p.landlordName || 'Verified Landlord')}</div>
                <div class="dorm-landlord-role">Property Landlord · Verified</div>
              </div>
            </div>
            <span class="dorm-modal-landlord-btn">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.145 2 11.258c0 2.91 1.455 5.518 3.736 7.207V22l3.418-1.876c.9.25 1.86.386 2.846.386 5.523 0 10-4.145 10-9.252C22 6.145 17.523 2 12 2Z"/></svg>
              Contact Landlord
            </span>
          </div>

          <!-- Dorm Booking Action Button -->
          <div class="dorm-booking-action-wrap">
            ${bookingActionHtml}
          </div>
        </div>

        <!-- Right Column: Rooms List & Frosted Glass -->
        <div class="dorm-modal-right dorm-modal-rooms">
          <h3>List of Rooms</h3>
          ${roomsHtml}
        </div>
      </div>
    `;

    // Wire Carousel Controls for Dorm Modal
    if (photos.length > 1) {
      let currentIdx = 0;
      const slides = dormModalContent.querySelectorAll('#dorm-modal-carousel .um-slide');
      const prevBtn = dormModalContent.querySelector('#dorm-carousel-prev');
      const nextBtn = dormModalContent.querySelector('#dorm-carousel-next');
      const countEl = dormModalContent.querySelector('#dorm-carousel-count');

      function updateCarousel() {
        slides.forEach((sl, idx) => {
          sl.classList.remove('is-active', 'is-prev', 'is-next');
          if (idx === currentIdx) {
            sl.classList.add('is-active');
          } else if (idx === currentIdx - 1) {
            sl.classList.add('is-prev');
          } else if (idx === currentIdx + 1) {
            sl.classList.add('is-next');
          }
        });

        if (prevBtn) {
          const canPrev = currentIdx > 0;
          prevBtn.style.opacity = canPrev ? '1' : '0.22';
          prevBtn.style.pointerEvents = canPrev ? 'auto' : 'none';
        }
        if (nextBtn) {
          const canNext = currentIdx < photos.length - 1;
          nextBtn.style.opacity = canNext ? '1' : '0.22';
          nextBtn.style.pointerEvents = canNext ? 'auto' : 'none';
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
      updateCarousel();
    }

    // Hook up room action triggers (View Room button & card click with slide transition)
    dormModalContent.querySelectorAll('.btn-view-room').forEach(btn => {
      btn.onclick = (e) => {
        e.preventDefault();
        e.stopPropagation();
        const roomId = btn.dataset.roomId || btn.closest('[data-room-id]')?.dataset.roomId;
        const room = (p.rooms || []).find(r => String(r.id) === String(roomId));
        if (room) {
          transitionToRoom(p, room);
        }
      };
    });

    dormModalContent.querySelectorAll('.room-card-preview').forEach(card => {
      const roomId = card.dataset.roomId;
      const room = (p.rooms || []).find(r => String(r.id) === String(roomId));
      if (!room) return;

      card.onclick = (e) => {
        if (e.target.closest('.btn-view-room')) return;
        transitionToRoom(p, room);
      };

      card.onkeydown = (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          transitionToRoom(p, room);
        }
      };
    });

    const frostedOverlay = dormModalContent.querySelector('#frosted-overlay-btn');
    if (frostedOverlay) {
      frostedOverlay.addEventListener('click', () => openGateModal());
      frostedOverlay.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          openGateModal();
        }
      });
    }

    const dormLandlordBtn = dormModalContent.querySelector('#dorm-modal-landlord-btn');
    if (dormLandlordBtn) {
      const handleDormLandlordClick = () => openLandlordProfile(p);
      dormLandlordBtn.addEventListener('click', handleDormLandlordClick);
      dormLandlordBtn.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          handleDormLandlordClick();
        }
      });
    }

    // Dorm cards may cancel an existing stay; new requests are room-only.
    const toggleBookingBtn = dormModalContent.querySelector('#btn-toggle-booking');
    if (toggleBookingBtn) {
      toggleBookingBtn.addEventListener('click', async (e) => {
        e.preventDefault();
        const propId = Number(toggleBookingBtn.dataset.id);
        const newAction = 'cancel';

        toggleBookingBtn.disabled = true;
        toggleBookingBtn.textContent = 'Cancelling stay...';

        try {
          const res = await fetch('../api/book.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ property_id: propId, action: newAction })
          });
          const result = await res.json();
          if (!res.ok || !result.ok) {
            alert(result.error || result.message || 'Could not process booking.');
            toggleBookingBtn.disabled = false;
            toggleBookingBtn.textContent = 'Cancel Stay';
            return;
          }

          // Update local property and user stay states
          p.hasBooked = false;
          p.userStayStatus = null;
          if (result.totalResidents !== undefined) p.totalResidents = result.totalResidents;
          if (result.sameSchoolResidents !== undefined) p.sameSchoolResidents = result.sameSchoolResidents;
          if (result.livePct !== undefined) p.livePct = result.livePct;

          // Update toolbar stay counts and cards
          updateStayCounts();
          applyFilters();

          Promise.resolve(onBookingChange(p)).catch(() => { });
          // Refresh the modal view with updated stats and toggle button
          if (isActiveProperty(p) && dormModal.open) openEnlargedModal(p);
        } catch (err) {
          console.error(err);
          alert('Network error while processing booking request.');
          toggleBookingBtn.disabled = false;
          toggleBookingBtn.textContent = 'Cancel Stay';
        }
      });
    }

    onRendered();
    if (!dormModal.open) {
      dormModal.showModal();
    }
  }

  function openRoomDetailsModal(p, room, transitionDirection) {
    onView('room', p, room);
    // Set Header State for Room Details View:
    // The room heading labels the dialog; keep the header for back navigation.
    dormModal.classList.add('is-viewing-room');
    if (dormModalTitle) dormModalTitle.textContent = 'Room details';
    dormModal.setAttribute('aria-labelledby', 'tenant-room-title');
    if (dormModalBackBtn) {
      dormModalBackBtn.style.display = 'inline-flex';
      dormModalBackBtn.setAttribute('title', `Back to ${p.name}`);
      dormModalBackBtn.setAttribute('aria-label', `Back to ${p.name}`);
      dormModalBackBtn.onclick = () => transitionBackToDorm(p);
    }
    if (dormModalCloseBtn) {
      dormModalCloseBtn.style.display = 'none';
    }

    const photos = getRoomPhotos(room);
    const initials = landlordInitials(p.landlordName);
    const amenities = Array.isArray(room.amenities) ? room.amenities : [];
    const nearest = nearestUniversity(Number(p.lat) || 16.0507, Number(p.lng) || 120.3408);
    const otherRooms = (p.rooms || []).filter(r => String(r.id) !== String(room.id));

    // Property Card Style Carousel for Room Details (Inside Left Brown Panel)
    let galleryHtml = '';
    if (photos.length > 0) {
      galleryHtml = `
        <div class="um-carousel room-panel-carousel" id="room-modal-carousel">
          <div class="um-carousel-track">
            ${photos.map((src, idx) => `
              <img class="um-slide ${idx === 0 ? (photos.length === 1 ? 'is-active is-only' : 'is-active') : (idx === 1 ? 'is-next' : '')}"
                   src="${esc(src)}"
                   data-idx="${idx}"
                   alt="${esc(room.name)} photo ${idx + 1}"
                   loading="lazy">
            `).join('')}
          </div>
          ${photos.length > 1 ? `
            <button type="button" class="um-carousel-btn prev" id="room-carousel-prev" aria-label="Previous photo">
              <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"></polyline></svg>
            </button>
            <button type="button" class="um-carousel-btn next" id="room-carousel-next" aria-label="Next photo">
              <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </button>
            <div class="um-carousel-count" id="room-carousel-count">1 / ${photos.length}</div>
          ` : ''}
        </div>
      `;
    } else {
      galleryHtml = `
        <div class="um-carousel room-panel-carousel" id="room-modal-carousel">
          <div class="um-carousel-track room-no-photo-track">
            <div class="room-no-photo-content">
              <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:block;margin:0 auto 8px;"><use href="#bed-icon"/></svg>
              <strong style="color:#ffffff; font-size:1.05rem; display:block;">${esc(room.name)}</strong>
              <span style="font-size:0.84rem; color:rgba(255,255,255,0.75);">No photo uploaded yet</span>
            </div>
          </div>
        </div>
      `;
    }

    let amenitiesHtml = '';
    if (amenities.length > 0) {
      amenitiesHtml = `
        <div class="room-amenities-wrap">
          <h4>Included Room Amenities</h4>
          <div class="room-amenities-list">
            ${amenities.map(a => `<span class="room-amenity-chip">${esc(a)}</span>`).join('')}
          </div>
        </div>
      `;
    }

    let bookingBtnHtml = '';
    if (!IS_AUTHENTICATED) {
      bookingBtnHtml = `
        <button type="button" class="btn-submit-room-booking-primary" id="btn-room-login-prompt">
          Log in to Book Room
        </button>
      `;
    } else {
      // Any logged-in tenant can book, verified or not
      bookingBtnHtml = `
        <button type="button" class="btn-submit-room-booking-primary" id="btn-submit-room-booking" data-property-id="${esc(p.id)}" data-room-available="${room.status !== 'Occupied' && room.status !== 'Under Maintenance'}" ${p.hasBooked || pendingRequests.has(String(p.id)) || room.status === 'Occupied' || room.status === 'Under Maintenance' ? 'disabled' : ''}>
          ${p.hasBooked ? 'Booking already requested' : pendingRequests.has(String(p.id)) ? 'Submitting Request...' : room.status === 'Occupied' || room.status === 'Under Maintenance' ? 'Room unavailable' : 'Submit Booking Request'}
        </button>
      `;
    }

    // Other rooms in this property for quick switching
    let otherRoomsHtml = '';
    if (otherRooms.length > 0) {
      otherRoomsHtml = `
        <div class="room-other-rooms-wrap">
          <h4>Other Rooms in this Dorm</h4>
          <div class="room-other-list">
            ${otherRooms.map(r => {
        const rPhotos = getRoomPhotos(r);
        const rCover = rPhotos.length ? rPhotos[0] : null;
        return `
                <div class="room-other-item" data-other-room-id="${r.id}" role="button" tabindex="0" title="Switch to ${esc(r.name)}">
                  <div class="room-other-left">
                    <div class="room-other-thumb">
                      ${rCover ? `<img src="${esc(rCover)}" alt="${esc(r.name)}" loading="lazy">` : `<svg><use href="#bed-icon"/></svg>`}
                    </div>
                    <div class="room-other-meta">
                      <strong>${esc(r.name)}</strong>
                      <span>${esc(r.type)} · ${r.capacity} Bed${r.capacity === 1 ? '' : 's'}</span>
                    </div>
                  </div>
                  <div class="room-other-price">${fmtPrice(r.rent)}/mo</div>
                </div>
              `;
      }).join('')}
          </div>
        </div>
      `;
    }

    // Render Unified Modal: Left Half (Brown Theme, Carousel) + Right Half (Light/Cream, Scrollable)
    dormModalContent.innerHTML = `
      <div class="room-details-view ${transitionDirection === 'slide-right' ? 'anim-slide-in-right' : ''}">
        <div class="room-details-left-panel">
          <div class="room-panel-top-row">
            <div class="dorm-mid-photo">${galleryHtml}</div>
            <div class="room-panel-info">
          <!-- 1. Room Name & Dorm Link -->
          <h2 class="room-title-heading" id="tenant-room-title">${esc(room.name)}</h2>
          <div class="room-prop-link">
            <svg><use href="#pin-icon"/></svg>
            <span>Part of <b>${esc(p.name)}</b> · ${esc(p.address)}</span>
          </div>

          <!-- 2. Room Unit, Campus & Distance -->
          <ul class="dorm-modal-facts room-facts-list">
            <li><svg><use href="#bed-icon"/></svg><span>Room Unit: <b>${esc(room.type)} (${room.capacity} Bed${room.capacity === 1 ? '' : 's'})</b></span></li>
            <li><svg><use href="#school-icon"/></svg><span>Campus: <b>Near ${esc(p.university)}</b></span></li>
            <li><svg><use href="#measure-icon"/></svg><span>Distance from campus: <b>${nearest.km} km</b></span></li>
          </ul>

            </div>
          </div>
          <!-- 4. Landlord Profile Card -->
          <div class="room-landlord-card" id="room-landlord-btn" role="button" tabindex="0" title="Click to view landlord profile &amp; chat">
            <div class="room-landlord-avatar-wrap">
              ${p.landlordPhoto ? `<img src="${esc(p.landlordPhoto)}" class="room-landlord-img" alt="${esc(p.landlordName)}">` : `<span class="room-landlord-initials">${esc(initials)}</span>`}
              <span class="room-landlord-verified-icon" title="Verified Landlord">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
              </span>
            </div>
            <div class="room-landlord-meta">
              <div class="room-landlord-name">${esc(p.landlordName || 'Verified Landlord')}</div>
              <div class="room-landlord-role">Property Landlord · <span class="room-landlord-status">Verified</span></div>
            </div>
            <button type="button" class="btn-room-chat-landlord" aria-label="Contact landlord">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.145 2 11.258c0 2.91 1.455 5.518 3.736 7.207V22l3.418-1.876c.9.25 1.86.386 2.846.386 5.523 0 10-4.145 10-9.252C22 6.145 17.523 2 12 2Z"/></svg>
              Chat
            </button>
          </div>

          <!-- Pricing and booking remain below the carousel and room information. -->
          <div class="room-pricing-card">
            <div class="room-pricing-item">
              <span class="room-pricing-label">Monthly Rate</span>
              <span class="room-pricing-amount highlight">${fmtPrice(room.rent)} <small>/ month</small></span>
            </div>
            ${room.deposit ? `
            <div class="room-pricing-item secondary">
              <span class="room-pricing-label">Security Deposit</span>
              <span class="room-pricing-amount">${fmtPrice(room.deposit)}</span>
            </div>` : ''}
          </div>

          <!-- 5. Submit Booking Request Button -->
          <div class="room-booking-action-wrap">
            <div id="room-booking-alert" role="status" aria-live="polite" style="display:none; padding:10px 14px; border-radius:10px; font-size:0.88rem; font-weight:600; margin-bottom:10px;"></div>
            ${bookingBtnHtml}
          </div>


        </div>
        <div class="room-details-right-panel">
          <!-- 6. Included Room Amenities -->
          ${amenitiesHtml}


          <!-- 8. Other Rooms Switcher -->
          ${otherRoomsHtml}
        </div>
      </div>
    `;

    // Wire Room Modal Carousel
    if (photos.length > 1) {
      let currentIdx = 0;
      const slides = dormModalContent.querySelectorAll('#room-modal-carousel .um-slide');
      const prevBtn = dormModalContent.querySelector('#room-carousel-prev');
      const nextBtn = dormModalContent.querySelector('#room-carousel-next');
      const countEl = dormModalContent.querySelector('#room-carousel-count');

      function updateRoomCarousel() {
        slides.forEach((sl, idx) => {
          sl.classList.remove('is-active', 'is-prev', 'is-next');
          if (idx === currentIdx) {
            sl.classList.add('is-active');
          } else if (idx === currentIdx - 1) {
            sl.classList.add('is-prev');
          } else if (idx === currentIdx + 1) {
            sl.classList.add('is-next');
          }
        });

        if (prevBtn) {
          const canPrev = currentIdx > 0;
          prevBtn.style.opacity = canPrev ? '1' : '0.22';
          prevBtn.style.pointerEvents = canPrev ? 'auto' : 'none';
        }
        if (nextBtn) {
          const canNext = currentIdx < photos.length - 1;
          nextBtn.style.opacity = canNext ? '1' : '0.22';
          nextBtn.style.pointerEvents = canNext ? 'auto' : 'none';
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
            updateRoomCarousel();
          }
        };
      }
      if (nextBtn) {
        nextBtn.onclick = (e) => {
          e.stopPropagation();
          if (currentIdx < photos.length - 1) {
            currentIdx++;
            updateRoomCarousel();
          }
        };
      }
      updateRoomCarousel();
    }

    // Hook other rooms quick switcher
    dormModalContent.querySelectorAll('.room-other-item').forEach(item => {
      item.addEventListener('click', () => {
        const otherId = item.dataset.otherRoomId;
        const otherRoom = (p.rooms || []).find(r => String(r.id) === String(otherId));
        if (otherRoom) {
          openRoomDetailsModal(p, otherRoom);
        }
      });
    });

    // Hook landlord profile click
    const roomLandlordBtn = dormModalContent.querySelector('#room-landlord-btn');
    if (roomLandlordBtn) {
      const handleRoomLandlordClick = () => openLandlordProfile(p);
      roomLandlordBtn.addEventListener('click', handleRoomLandlordClick);
      roomLandlordBtn.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          handleRoomLandlordClick();
        }
      });
    }

    const loginPromptBtn = dormModalContent.querySelector('#btn-room-login-prompt');
    if (loginPromptBtn) {
      loginPromptBtn.addEventListener('click', () => {
        openGateModal();
      });
    }

    const submitBookingBtn = dormModalContent.querySelector('#btn-submit-room-booking');
    if (submitBookingBtn) {
      submitBookingBtn.addEventListener('click', async () => {
        if (submitBookingBtn.disabled || pendingRequests.has(String(p.id))) return;
        pendingRequests.add(String(p.id));
        submitBookingBtn.disabled = true;
        submitBookingBtn.textContent = 'Submitting Request...';
        const alertEl = dormModalContent.querySelector('#room-booking-alert');

        try {
          const res = await fetch('../api/book.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              property_id: p.id,
              room_id: room.id,
              action: 'book'
            })
          });
          const resData = await res.json();
          if (res.ok && resData.ok) {
            p.hasBooked = true;
            p.userStayStatus = 'upcoming';
            updateStayCounts();
            applyFilters();
            Promise.resolve(onBookingChange(p)).catch(() => { });
            if (alertEl) {
              alertEl.style.display = 'block';
              alertEl.style.background = '#dcfce7';
              alertEl.style.color = '#166534';
              alertEl.style.border = '1px solid #bbf7d0';
              alertEl.innerHTML = `✓ ${esc(resData.message || 'Booking request submitted!')}`;
            }
            submitBookingBtn.textContent = 'Booking Request Submitted';
            submitBookingBtn.style.background = '#15803d';
          } else {
            if (alertEl) {
              alertEl.style.display = 'block';
              alertEl.style.background = '#fee2e2';
              alertEl.style.color = '#991b1b';
              alertEl.style.border = '1px solid #fecaca';
              alertEl.textContent = resData.error || 'Failed to submit booking request.';
            }
            submitBookingBtn.disabled = false;
            submitBookingBtn.textContent = 'Submit Booking Request';
          }
        } catch (err) {
          console.error(err);
          if (alertEl) {
            alertEl.style.display = 'block';
            alertEl.style.background = '#fee2e2';
            alertEl.style.color = '#991b1b';
            alertEl.style.border = '1px solid #fecaca';
            alertEl.textContent = 'Network error while submitting booking.';
          }
          submitBookingBtn.disabled = false;
          submitBookingBtn.textContent = 'Submit Booking Request';
        } finally {
          pendingRequests.delete(String(p.id));
          const currentButton = dormModalContent.querySelector('#btn-submit-room-booking');
          if (currentButton && currentButton !== submitBookingBtn && currentButton.dataset.propertyId === String(p.id)) {
            currentButton.disabled = p.hasBooked || currentButton.dataset.roomAvailable !== 'true';
            currentButton.textContent = p.hasBooked ? 'Booking already requested' : currentButton.disabled ? 'Room unavailable' : 'Submit Booking Request';
          }
        }
      });
    }

    onRendered();
    if (!dormModal.open) {
      dormModal.showModal();
    }
  }

  return { showDorm: openEnlargedModal, showRoom: openRoomDetailsModal };
};
