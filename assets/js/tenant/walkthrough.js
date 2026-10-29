/*
 * Tenant booking walkthrough (spotlight tour) for tenant/dorms.php
 *
 * Replaces the old slideshow guide. It dims the page, highlights the elements a
 * tenant uses to book a room, and opens a real dorm and room along the way so the
 * booking controls can be pointed at. It never submits anything.
 */
window.createTenantWalkthrough = function (options) {
    'use strict';

    const {
        grid,
        dormModal,
        getProperties = () => [],
        openDorm,
        openRoom,
        IS_AUTHENTICATED = false,
        PROFILE_COMPLETED = false,
        CAN_VIEW_FULL_ROOMS = false,
        storageKey = 'unistay_guide_viewed',
        onEnd = () => { }
    } = options;

    let layer = null;
    let spotEl, tipEl, titleEl, textEl, countEl, dotsEl, backBtn, nextBtn;
    let steps = [];
    let index = 0;
    let active = false;
    let openedByTour = false;
    let demo = null;
    let trackRaf = 0;

    const reduceMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const clamp = (n, lo, hi) => Math.max(lo, Math.min(n, hi));

    /* ---------------------------------------------------------------- demo data */

    // A dorm (and one of its rooms) to open while explaining the booking steps.
    function pickDemo() {
        const props = getProperties() || [];
        const hasRooms = p => p && Array.isArray(p.rooms) && p.rooms.length > 0;
        let prop = null;
        let onPage = false;

        const cards = [].slice.call(grid.querySelectorAll('.dorm-card'));
        for (const card of cards) {
            const p = props.find(x => String(x.id) === String(card.dataset.id));
            if (hasRooms(p)) { prop = p; onPage = true; break; }
        }
        if (!prop) prop = props.find(hasRooms) || null;
        if (!prop) return null;

        const rooms = prop.rooms;
        const open = r => r.status !== 'Occupied' && r.status !== 'Under Maintenance';
        // Visitors and incomplete profiles only see the first room, so stay on it.
        const room = CAN_VIEW_FULL_ROOMS ? (rooms.find(open) || rooms[0]) : rooms[0];
        return { prop, room, onPage };
    }

    function ensureView(kind) {
        const inRoom = dormModal.open && dormModal.classList.contains('is-viewing-room');
        const inDorm = dormModal.open && !inRoom;
        if (kind === 'dorm' && !inDorm) {
            openDorm(demo.prop, inRoom ? 'slide-left' : undefined);
            openedByTour = true;
        } else if (kind === 'room' && !inRoom) {
            openRoom(demo.prop, demo.room, 'slide-right');
            openedByTour = true;
        }
    }

    /* -------------------------------------------------------------------- steps */

    function buildSteps() {
        const list = [];
        demo = pickDemo();

        if (IS_AUTHENTICATED && !PROFILE_COMPLETED) {
            list.push({
                host: 'page',
                target: () => document.querySelector('.sidebar nav a[href="settings.php"]'),
                title: 'Complete your profile first',
                text: 'Open Settings and add your school, valid IDs and Facebook link. Landlords check these before approving a request, and finishing your profile unlocks every room in a dorm.'
            });
        }

        list.push({
            host: 'page',
            target: () => document.querySelector('.um-toolbar-left'),
            title: 'Find dorms near your campus',
            text: 'Search by dorm name or address, or pick your university to see only the dorms close to it.'
        });

        if (demo) {
            if (demo.onPage) {
                list.push({
                    host: 'page',
                    target: () => grid.querySelector('.dorm-card[data-id="' + demo.prop.id + '"]'),
                    title: 'Open a dorm',
                    text: 'Click a dorm card, or its View details button, to see its photos, its distance from campus and its landlord.'
                });
            }

            list.push({
                host: 'modal',
                prefer: ['right', 'left', 'below', 'above'],
                before: () => ensureView('dorm'),
                target: () =>
                    dormModal.querySelector('.dorm-modal-rooms .room-card-preview[data-room-id="' + demo.room.id + '"]') ||
                    dormModal.querySelector('.dorm-modal-rooms .room-card-preview'),
                title: 'Pick a room',
                text: 'Each dorm lists its rooms. Click a room, or its View Room button, to see the rate, deposit and amenities. Want to ask something first? Use Contact Landlord on the left.'
            });

            list.push({
                host: 'modal',
                prefer: ['right', 'left', 'below', 'above'],
                before: () => ensureView('room'),
                target: () => dormModal.querySelector('.room-pricing-card'),
                title: 'Check the rate and deposit',
                text: 'The monthly rate and security deposit are shown here. Make sure the room fits your budget before you book.'
            });

            list.push({
                host: 'modal',
                prefer: ['right', 'left', 'below', 'above'],
                before: () => ensureView('room'),
                target: () => dormModal.querySelector('#btn-submit-room-booking, #btn-room-login-prompt'),
                title: IS_AUTHENTICATED ? 'Send your booking request' : 'Log in to book',
                text: IS_AUTHENTICATED
                    ? 'Press Submit Booking Request to send it to the landlord. They will review your profile, then approve or decline.'
                    : 'You need an account to send a request. Log in or sign up, then come back to this button to book the room.'
            });
        }

        list.push({
            host: 'page',
            target: () => document.querySelector('.dorms-stay-filters'),
            title: 'Track your request',
            text: 'After you book, the dorm appears under Upcoming Stay while the landlord reviews your request. Once approved, it moves to Active Stay.'
        });

        if (IS_AUTHENTICATED) {
            list.push({
                host: 'page',
                target: () => document.getElementById('notification-bell'),
                title: 'Get notified',
                text: 'You will be notified here, and by email, as soon as the landlord approves or declines your request.'
            });
        } else {
            list.push({
                host: 'page',
                target: () => document.querySelector('.topbar-auth'),
                title: 'Ready to book?',
                text: 'Log in or sign up here to send booking requests and get notified when landlords respond.'
            });
        }

        return list;
    }

    /* ---------------------------------------------------------------------- DOM */

    function buildLayer() {
        const el = document.createElement('div');
        el.className = 'wt-layer';
        el.innerHTML =
            '<div class="wt-block"></div>' +
            '<div class="wt-spot" aria-hidden="true"></div>' +
            '<div class="wt-tip" role="dialog" aria-labelledby="wt-title" aria-describedby="wt-text" tabindex="-1">' +
            '  <div class="wt-progress"><span class="wt-count"></span><span class="wt-dots" aria-hidden="true"></span></div>' +
            '  <h3 class="wt-title" id="wt-title"></h3>' +
            '  <p class="wt-text" id="wt-text"></p>' +
            '  <div class="wt-actions">' +
            '    <button type="button" class="wt-btn wt-back">Back</button>' +
            '    <button type="button" class="wt-btn wt-btn-primary wt-next">Next</button>' +
            '  </div>' +
            '</div>' +
            '<button type="button" class="wt-skip">Skip tutorial</button>';

        spotEl = el.querySelector('.wt-spot');
        tipEl = el.querySelector('.wt-tip');
        titleEl = el.querySelector('.wt-title');
        textEl = el.querySelector('.wt-text');
        countEl = el.querySelector('.wt-count');
        dotsEl = el.querySelector('.wt-dots');
        backBtn = el.querySelector('.wt-back');
        nextBtn = el.querySelector('.wt-next');

        steps.forEach(() => dotsEl.appendChild(document.createElement('i')));

        // The dorm dialog closes when you click outside its box, so keep clicks in here.
        el.addEventListener('click', e => e.stopPropagation());
        backBtn.addEventListener('click', back);
        nextBtn.addEventListener('click', next);
        el.querySelector('.wt-skip').addEventListener('click', () => end(true));
        return el;
    }

    // The layer lives in <body>, or inside the dorm dialog while that is open
    // (a modal dialog makes everything outside itself unclickable).
    function mount(host) {
        if (layer.parentNode === host) return;
        host.appendChild(layer);
        layer.classList.remove('is-ready');
    }

    /* ----------------------------------------------------------------- position */

    function position() {
        if (!active || !layer) return;
        const step = steps[index];
        if (!step) return;

        const el = step.target && step.target();
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const pad = 8, gap = 16, margin = 12, safeBottom = 76; // keeps clear of the Skip button

        let r = null;
        if (el && el.isConnected) {
            const b = el.getBoundingClientRect();
            if (b.width > 0 && b.height > 0) r = b;
        }

        layer.classList.toggle('is-centered', !r);
        const tw = tipEl.offsetWidth;
        const th = tipEl.offsetHeight;
        let x, y;

        if (!r) {
            spotEl.style.opacity = '0';
            x = (vw - tw) / 2;
            y = (vh - th) / 2;
        } else {
            const sl = r.left - pad, st = r.top - pad, sr = r.right + pad, sb = r.bottom + pad;
            const radius = (parseFloat(getComputedStyle(el).borderTopLeftRadius) || 10) + pad;

            spotEl.style.opacity = '1';
            spotEl.style.left = Math.round(sl) + 'px';
            spotEl.style.top = Math.round(st) + 'px';
            spotEl.style.width = Math.round(r.width + pad * 2) + 'px';
            spotEl.style.height = Math.round(r.height + pad * 2) + 'px';
            spotEl.style.borderRadius = radius + 'px';

            const midX = clamp(r.left + r.width / 2 - tw / 2, margin, vw - tw - margin);
            const midY = clamp(r.top + r.height / 2 - th / 2, margin, vh - safeBottom - th);
            const fits = {
                below: sb + gap + th <= vh - safeBottom,
                above: st - gap - th >= margin,
                right: sr + gap + tw <= vw - margin,
                left: sl - gap - tw >= margin
            };
            const side = (step.prefer || ['below', 'above', 'right', 'left']).find(s => fits[s]);

            if (side === 'below') { x = midX; y = sb + gap; }
            else if (side === 'above') { x = midX; y = st - gap - th; }
            else if (side === 'right') { x = sr + gap; y = midY; }
            else if (side === 'left') { x = sl - gap - tw; y = midY; }
            else { x = midX; y = Math.max(margin, vh - safeBottom - th); } // very large target
        }

        tipEl.style.left = Math.round(x) + 'px';
        tipEl.style.top = Math.round(y) + 'px';
    }

    // Keep re-measuring while things scroll or animate into place.
    function track(ms) {
        cancelAnimationFrame(trackRaf);
        const until = performance.now() + ms;
        const tick = () => {
            position();
            if (active && performance.now() < until) trackRaf = requestAnimationFrame(tick);
        };
        tick();
    }

    /* --------------------------------------------------------------------- flow */

    function show(i) {
        if (!active || !layer) return;
        index = clamp(i, 0, steps.length - 1);
        const step = steps[index];

        if (step.host === 'page') {
            mount(document.body);
            if (dormModal.open) dormModal.close();
        } else {
            if (step.before) step.before();
            mount(dormModal);
        }

        titleEl.textContent = step.title;
        textEl.textContent = step.text;
        countEl.textContent = (index + 1) + ' of ' + steps.length;
        [].forEach.call(dotsEl.children, (d, n) => d.classList.toggle('on', n === index));
        backBtn.style.visibility = index === 0 ? 'hidden' : 'visible';
        nextBtn.textContent = index === steps.length - 1 ? 'Finish' : 'Next';

        const target = step.target && step.target();
        if (target && target.scrollIntoView) {
            target.scrollIntoView({
                block: step.host === 'modal' ? 'nearest' : 'center',
                inline: 'nearest',
                behavior: reduceMotion() ? 'auto' : 'smooth'
            });
        }

        track(900);

        if (!layer.classList.contains('is-ready')) {
            // Let the first position apply without a transition, then enable gliding.
            requestAnimationFrame(() => requestAnimationFrame(() => {
                if (layer) layer.classList.add('is-ready');
            }));
        }
        nextBtn.focus({ preventScroll: true });
    }

    function next() {
        if (index >= steps.length - 1) end(false);
        else show(index + 1);
    }

    function back() {
        if (index > 0) show(index - 1);
    }

    /* ------------------------------------------------------------ start and end */

    function onKey(e) {
        if (!active) return;
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); end(true); }
        else if (e.key === 'ArrowRight') { e.preventDefault(); next(); }
        else if (e.key === 'ArrowLeft') { e.preventDefault(); back(); }
    }

    // Escape should end the tour, not close the dorm dialog underneath it.
    function guardCancel(e) {
        if (active) e.preventDefault();
    }

    function start() {
        if (active) return;
        active = true;
        openedByTour = false;
        steps = buildSteps();
        layer = buildLayer();
        document.body.appendChild(layer);

        document.addEventListener('keydown', onKey, true);
        window.addEventListener('resize', position);
        document.addEventListener('scroll', position, true);
        dormModal.addEventListener('cancel', guardCancel);

        requestAnimationFrame(() => show(0));
    }

    function end(skipped) {
        if (!active) return;
        active = false;
        cancelAnimationFrame(trackRaf);

        document.removeEventListener('keydown', onKey, true);
        window.removeEventListener('resize', position);
        document.removeEventListener('scroll', position, true);
        dormModal.removeEventListener('cancel', guardCancel);

        try { localStorage.setItem(storageKey, '1'); } catch (e) { }

        const leaving = layer;
        layer = null;
        if (leaving) {
            leaving.classList.add('is-leaving');
            setTimeout(() => leaving.remove(), 220);
        }

        if (openedByTour && dormModal.open) dormModal.close();
        openedByTour = false;

        onEnd({ skipped: !!skipped });
    }

    return { start, end, isActive: () => active };
};