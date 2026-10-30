window.isLandlordVerified = function (p) {
  if (!p) return false;
  if (typeof p.landlordVerified === 'boolean') return p.landlordVerified;
  const l = p.landlord || {};
  return l.verified === true || l.verification === 'verified';
};
window.verifiedCheckHtml = function () {
  return '<span class="verified-check" role="img" aria-label="Verified landlord" title="Verified landlord"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg></span>';
};
window.landlordNameHtml = function (name, verified) {
  const safe = String(name ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  return `<span class="landlord-name-text">${safe}</span>` + (verified ? verifiedCheckHtml() : '');
};

window.createTenantDormDetails = function (options) {
  'use strict';
  const { dormModal, dormModalContent, dormModalTitle = null, dormModalBackBtn = null,
    dormModalCloseBtn = null, IS_AUTHENTICATED, PROFILE_COMPLETED = false, CAN_VIEW_FULL_ROOMS,
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
    const landlordVerified = isLandlordVerified(p);
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
            <div class="um-slide ${idx === 0 ? (photos.length === 1 ? 'is-active is-only' : 'is-active') : (idx === 1 ? 'is-next' : '')}"
                 data-idx="${idx}">
              <img class="um-slide-image"
                   src="${esc(src)}"
                   alt="${esc(p.name)} photo ${idx + 1}"
                   loading="lazy">
            </div>
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

    // Rooms list formatting (Count only for visitors/unregistered users; full list for verified tenants)
    let roomsHtml = '';
    if (!rooms.length) {
      roomsHtml = '<p style="color:#78665c;">No room units listed currently.</p>';
    } else if (!CAN_VIEW_FULL_ROOMS) {
      // For both visitors and unregistered/incomplete users: NO room preview. Only room count and unlock prompt.
      const unlockBtnText = IS_AUTHENTICATED ? 'Complete profile in Settings' : 'Log in to view rooms';
      const unlockDescText = IS_AUTHENTICATED
        ? 'Complete your profile in Settings to unlock room photos, rates, and submit booking requests.'
        : 'Create an account or sign in to view complete room photos, check availability, and send booking requests.';

      roomsHtml = `
        <div class="dorm-rooms-locked-card">
          <div class="rooms-locked-count-badge">
            <svg aria-hidden="true"><use href="#bed-icon"/></svg>
            <span>${rooms.length} Room${rooms.length === 1 ? '' : 's'} Available</span>
          </div>
          <div class="rooms-locked-banner">
            <div class="locked-icon-bubble">
              <svg aria-hidden="true"><use href="#lock-icon"/></svg>
            </div>
            <h4>Room details are protected</h4>
            <p>${esc(unlockDescText)}</p>
            <button type="button" class="btn-unlock-rooms" id="btn-gate-unlock">
              ${esc(unlockBtnText)}
            </button>
          </div>
        </div>
      `;
    } else {
      // If profile completed, show all rooms in exact landlord style
      roomsHtml = `
        <div class="dorm-modal-rooms-grid">
          ${rooms.map(r => {
            const rPhotos = getRoomPhotos(r);
            const rTenants = Array.isArray(r.tenants) ? r.tenants : [];
            const rAmenities = Array.isArray(r.amenities) ? r.amenities : [];
            const rOccupants = typeof r.occupants === 'number' ? r.occupants : (rTenants.length || 0);
            return `
            <div class="dorm-modal-room-card" data-room-id="${r.id}" role="button" tabindex="0" title="Click to view ${esc(r.name)} details">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
                <div>
                  <strong style="font-size:15px;color:var(--ink);">${esc(r.name)}</strong>
                  <span style="font-size:11.5px;padding:2px 8px;border-radius:12px;background:#eef1eb;color:#27522e;font-weight:600;margin-left:6px;">${esc(r.type || 'Room')}</span>
                  <p style="margin:4px 0 0;font-size:12px;color:var(--muted);">${rOccupants} of ${r.capacity} occupants · <span style="font-weight:600;color:${(r.status || 'Available') === 'Available' ? '#27522e' : '#b03525'}">${esc(r.status || 'Available')}</span></p>
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
                      <img src="${esc(src)}" alt="${esc(r.name)} photo ${pIdx + 1}" loading="lazy" class="dorm-modal-room-img">
                    </div>
                  `).join('')}
                </div>
              ` : ''}

              <!-- Room Tenants List -->
              ${rTenants.length ? `
                <div class="modal-room-tenants-wrap">
                  <div class="modal-room-tenants-header">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <strong>Current Tenants (${rTenants.length} of ${r.capacity} beds occupied)</strong>
                  </div>
                  <div class="modal-room-tenants-list">
                    ${rTenants.map(t => {
                      const tName = typeof t === 'object' ? (t.name || 'Tenant') : String(t);
                      const tPhoto = typeof t === 'object' ? (t.photo || '') : '';
                      const tSchool = typeof t === 'object' ? (t.school || 'Student') : 'Student';
                      const tInit = landlordInitials(tName);
                      return `
                      <div class="modal-tenant-chip">
                        <span class="modal-tenant-avatar">${tPhoto ? `<img src="${esc(tPhoto)}" alt="" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='grid';"><span class="tenant-avatar-fallback" style="display:none;">${esc(tInit)}</span>` : esc(tInit)}</span>
                        <div class="modal-tenant-meta">
                          <span class="modal-tenant-name">${esc(tName)}</span>
                          <span class="modal-tenant-school">${esc(tSchool)}</span>
                        </div>
                      </div>
                      `;
                    }).join('')}
                  </div>
                </div>
              ` : `
                <div class="modal-room-empty-beds">
                  <span>🛏️ All ${r.capacity} bed(s) available · No current occupants</span>
                </div>
              `}

              ${rAmenities.length ? `
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;padding-top:8px;border-top:1px dashed #f0e6df;">
                  ${rAmenities.map(a => `<span style="font-size:11px;background:#f6efe9;color:var(--brown-dark);padding:2px 7px;border-radius:6px;">${esc(a)}</span>`).join('')}
                </div>
              ` : ''}
            </div>
            `;
          }).join('')}
        </div>
      `;
    }

    dormModalContent.innerHTML = `
      <div class="dorm-details-view ${transitionDirection === 'slide-left' ? 'anim-slide-in-left' : transitionDirection === 'slide-right' ? 'anim-slide-in-right' : ''}">
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
              ${IS_AUTHENTICATED ? `
              <div class="dorm-side-fact">
                <svg><use href="#school-icon"/></svg>
                <span>Near <b>${esc(p.university)}</b></span>
              </div>
              ${studentStatHtml}
              <div class="dorm-side-fact">
                <svg><use href="#measure-icon"/></svg>
                <span>Distance: <b>${nearest.km} km</b> from campus</span>
              </div>` : ''}
            </div>
          </div>

          <!-- Description Box with uppercase title -->
          <div class="dorm-modal-desc-box">
            <strong class="dorm-modal-desc-title">Description</strong>
            <p class="dorm-modal-desc-text">${esc(p.description || 'No additional description provided.')}</p>
          </div>

          <!-- Landlord Profile Card on Brown -->
          <div class="dorm-modal-landlord dorm-landlord-on-brown" id="dorm-modal-landlord-btn" ${IS_AUTHENTICATED ? 'role="button" tabindex="0" title="Click to view landlord profile &amp; chat"' : 'style="cursor:default;"'}>
            <div class="dorm-modal-landlord-inner">
              ${landlordAvatarModalHtml}
              <div>
                <div class="dorm-landlord-name">${landlordNameHtml(p.landlordName || 'Landlord', landlordVerified)}</div>
                <div class="dorm-landlord-role">Property Landlord</div>
              </div>
            </div>
            ${IS_AUTHENTICATED ? `
            <span class="dorm-modal-landlord-btn">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.145 2 11.258c0 2.91 1.455 5.518 3.736 7.207V22l3.418-1.876c.9.25 1.86.386 2.846.386 5.523 0 10-4.145 10-9.252C22 6.145 17.523 2 12 2Z"/></svg>
              Contact
            </span>` : ''}
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

    // Hook up room action triggers (entire room card click with slide transition)
    dormModalContent.querySelectorAll('.dorm-modal-room-card, .room-card-preview').forEach(card => {
      const roomId = card.dataset.roomId;
      const room = (p.rooms || []).find(r => String(r.id) === String(roomId));
      if (!room) return;

      card.onclick = (e) => {
        if (e.target.closest('.dorm-modal-room-photo-item')) return;
        transitionToRoom(p, room);
      };

      card.onkeydown = (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          transitionToRoom(p, room);
        }
      };
    });

    const gateUnlockBtn = dormModalContent.querySelector('#btn-gate-unlock');
    if (gateUnlockBtn) {
      gateUnlockBtn.addEventListener('click', () => {
        if (IS_AUTHENTICATED) {
          window.location.href = 'settings.php?edit=1';
        } else {
          openGateModal();
        }
      });
    }
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
    if (dormLandlordBtn && IS_AUTHENTICATED) {
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
      dormModalCloseBtn.style.display = '';
    }

    const photos = getRoomPhotos(room);
    const initials = landlordInitials(p.landlordName);
    const landlordVerified = isLandlordVerified(p);
    const roomLandlordPhoto = p.landlordPhoto || (p.landlord && p.landlord.photo) || null;
    const roomLandlordAvatarHtml = roomLandlordPhoto
      ? `<span class="dorm-modal-landlord-avatar has-img"><img src="${esc(roomLandlordPhoto)}" alt="${esc(p.landlordName || 'Landlord')}" class="dorm-modal-landlord-img" onerror="this.style.display='none';this.nextElementSibling.style.display='grid';"><span style="display:none;">${esc(initials)}</span></span>`
      : `<span class="dorm-modal-landlord-avatar">${esc(initials)}</span>`;
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
              <div class="um-slide ${idx === 0 ? (photos.length === 1 ? 'is-active is-only' : 'is-active') : (idx === 1 ? 'is-next' : '')}"
                   data-idx="${idx}">
                <img class="um-slide-image"
                     src="${esc(src)}"
                     alt="${esc(room.name)} photo ${idx + 1}"
                     loading="lazy">
              </div>
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
    } else if (!PROFILE_COMPLETED) {
      bookingBtnHtml = `
        <button type="button" class="btn-submit-room-booking-primary" id="btn-room-profile-prompt">
          Complete Profile to Book
        </button>
      `;
    } else {
      // Completed tenants can book without waiting for admin identity verification.
      bookingBtnHtml = `
        <button type="button" class="btn-submit-room-booking-primary" id="btn-submit-room-booking" data-property-id="${esc(p.id)}" data-room-available="${room.status !== 'Occupied' && room.status !== 'Under Maintenance'}" ${p.hasBooked || pendingRequests.has(String(p.id)) || room.status === 'Occupied' || room.status === 'Under Maintenance' ? 'disabled' : ''}>
          ${p.hasBooked ? 'Booking already requested' : pendingRequests.has(String(p.id)) ? 'Submitting Request...' : room.status === 'Occupied' || room.status === 'Under Maintenance' ? 'Room unavailable' : 'Submit Booking Request'}
        </button>
      `;
    }

    const otherRoomsHtml = otherRooms.length ? `
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
              </div>`;
    }).join('')}
        </div>
      </div>` : `
      <div class="room-other-rooms-wrap">
        <p class="room-other-empty" role="status">There are no other rooms in this dorm.</p>
      </div>`;

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
            ${IS_AUTHENTICATED ? `
            <li><svg><use href="#school-icon"/></svg><span>Campus: <b>Near ${esc(p.university)}</b></span></li>
            <li><svg><use href="#measure-icon"/></svg><span>Distance from campus: <b>${nearest.km} km</b></span></li>` : ''}
          </ul>

            </div>
          </div>
          <!-- 4. Landlord Profile Card -->
          <div class="room-landlord-card dorm-modal-landlord dorm-landlord-on-brown" id="room-landlord-btn" ${IS_AUTHENTICATED ? 'role="button" tabindex="0" title="Click to view landlord profile &amp; chat"' : 'style="cursor:default;"'}>
            <div class="dorm-modal-landlord-inner">
              ${roomLandlordAvatarHtml}
              <div class="room-landlord-meta">
                <div class="room-landlord-name dorm-landlord-name">${landlordNameHtml(p.landlordName || 'Landlord', landlordVerified)}</div>
                <div class="room-landlord-role dorm-landlord-role">Property Landlord</div>
              </div>
            </div>
            ${IS_AUTHENTICATED ? `
            <button type="button" class="btn-room-chat-landlord dorm-modal-landlord-btn" aria-label="Contact landlord">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.145 2 11.258c0 2.91 1.455 5.518 3.736 7.207V22l3.418-1.876c.9.25 1.86.386 2.846.386 5.523 0 10-4.145 10-9.252C22 6.145 17.523 2 12 2Z"/></svg>
              Contact
            </button>` : ''}
          </div>

          <!-- Pricing and booking remain below the carousel and room information. -->
          <div class="room-pricing-card dorm-modal-desc-box">
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
    if (roomLandlordBtn && IS_AUTHENTICATED) {
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

    const profilePromptBtn = dormModalContent.querySelector('#btn-room-profile-prompt');
    if (profilePromptBtn) {
      profilePromptBtn.addEventListener('click', () => {
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
