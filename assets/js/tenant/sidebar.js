(() => {
  const KEY    = 'unistay.sidebarCollapsed';
  const FADEIN = 'unistay.navFadeIn';
  const root   = document.documentElement;

  // Apply the saved collapsed state immediately (script runs in <head>) so there is no layout jump
  try { root.classList.toggle('sidebar-collapsed', localStorage.getItem(KEY) === '1'); } catch (e) {}

  // Check if we arrived from clicking a sidebar option (trigger smooth bloom fade-in on arrival)
  let shouldFadeIn = false;
  try {
    const raw = sessionStorage.getItem(FADEIN);
    if (raw) {
      sessionStorage.removeItem(FADEIN);
      const o = JSON.parse(raw);
      if (o && Date.now() - o.t < 5000) {
        shouldFadeIn = true;
        root.setAttribute('data-nav-fadein', 'true');
      }
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

  const initSidebar = () => {
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

    if (!nav) return;

    const cur = nav.querySelector('a[aria-current="page"]');

    // Play smooth bloom fade-in on arrival
    if (shouldFadeIn && cur) {
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          cur.classList.add('nav-fade-running');
          setTimeout(() => {
            root.removeAttribute('data-nav-fadein');
            cur.classList.remove('nav-fade-running');
          }, 360);
        });
      });
    } else {
      root.removeAttribute('data-nav-fadein');
    }

    // On clicking a sidebar link: dissolve out the currently active option before navigating
    nav.addEventListener('click', (e) => {
      const a = e.target.closest('a');
      if (!a || !nav.contains(a)) return;
      if (a.classList.contains('nav-logout') || (a.getAttribute('href') && a.getAttribute('href').includes('logout.php'))) return;
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || a.target === '_blank') return;
      if (a.getAttribute('aria-current') === 'page') return;

      const curA = nav.querySelector('a[aria-current="page"]');
      if (curA && curA !== a) {
        e.preventDefault();
        curA.classList.add('nav-fading-out');

        try {
          sessionStorage.setItem(FADEIN, JSON.stringify({ t: Date.now() }));
        } catch (err) {}

        const targetHref = a.href;
        setTimeout(() => {
          window.location.href = targetHref;
        }, 160);
      }
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSidebar);
  } else {
    initSidebar();
  }
})();
