(() => {
  const KEY  = 'unistay.sidebarCollapsed';
  const FROM = 'unistay.navFrom';
  const root = document.documentElement;

  // Apply the saved collapsed state immediately (script is in <head>) so there's no flash
  try { root.classList.toggle('sidebar-collapsed', localStorage.getItem(KEY) === '1'); } catch (e) {}

  // Arrived from another sidebar page? Tell the CSS which option was selected there (its position
  // in the nav), so this page can empty that option's highlight while the new one fills in.
  // Done here in <head>, before the first paint, so there is no flash and the animation plays once.
  try {
    const raw = sessionStorage.getItem(FROM);
    sessionStorage.removeItem(FROM);
    const o = raw ? JSON.parse(raw) : null;
    if (o && Date.now() - o.t < 8000 && Number.isInteger(o.i) && o.i >= 0 && o.i < 10) {
      root.setAttribute('data-nav-prev', String(o.i + 1));
    }
  } catch (e) {}

  // Call from any page to show the Bookings count pill: setBookingsBadge(6). 0 hides it.
  window.setBookingsBadge = (n) => {
    const b = document.getElementById('bookings-badge');
    if (!b) return;
    n = Number(n) || 0;
    b.textContent = n > 99 ? '99+' : String(n);
    b.hidden = n <= 0;
  };

  document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('sidebar-toggle');
    const nav = document.querySelector('.sidebar nav');

    // Collapse / expand
    if (btn) {
      const sync = () => {
        const c = root.classList.contains('sidebar-collapsed');
        btn.setAttribute('aria-expanded', String(!c));
        btn.setAttribute('aria-label', c ? 'Expand sidebar' : 'Collapse sidebar');
      };
      sync();
      requestAnimationFrame(() => requestAnimationFrame(() => root.classList.add('sidebar-ready')));
      btn.addEventListener('click', () => {
        const c = root.classList.toggle('sidebar-collapsed');
        try { localStorage.setItem(KEY, c ? '1' : '0'); } catch (e) {}
        sync();
      });
    }

    // Navigation stays a normal, instant page load. We only remember which option is selected
    // right now; the next page plays the whole highlight animation (once).
    if (nav) {
      nav.addEventListener('click', (e) => {
        const a = e.target.closest('a');
        if (!a || !nav.contains(a)) return;
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ||
            a.target === '_blank') return;
        try {
          const cur = nav.querySelector('a[aria-current="page"]');
          const i = cur ? [...nav.children].indexOf(cur) : -1;
          if (i < 0 || a === cur) sessionStorage.removeItem(FROM);
          else sessionStorage.setItem(FROM, JSON.stringify({ i, t: Date.now() }));
        } catch (err) {}
      });
    }
  });
})();