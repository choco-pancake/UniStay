(() => {
  'use strict';

  const KEY = 'unistay-tenant-profile';
  const DEFAULTS = { name: 'User', role: '', photo: '' };

  const initials = (name) =>
    name
      .trim()
      .split(/\s+/)
      .slice(0, 2)
      .map((w) => w[0] || '')
      .join('')
      .toUpperCase() || '?';

  const sanitizePhoto = (url) => {
    if (!url || typeof url !== 'string') return '';
    let p = url.trim();
    if (p.includes('unavatar.io') && !p.includes('fallback=false')) {
      p += (p.includes('?') ? '&' : '?') + 'fallback=false';
    }
    return p;
  };

  const readProfile = () => {
    const injected = window.UNISTAY_TENANT_PROFILE || null;
    if (injected && (injected.name || injected.email || injected.photo)) {
      return {
        name: injected.name || DEFAULTS.name,
        role: injected.role || DEFAULTS.role,
        photo: sanitizePhoto(injected.photo),
        initials: injected.initials || initials(injected.name || ''),
      };
    }
    return {
      name: DEFAULTS.name,
      role: DEFAULTS.role,
      photo: '',
      initials: 'U',
    };
  };

  document.addEventListener('DOMContentLoaded', () => {
    const trigger = document.querySelector('.profile .avatar');
    if (!trigger) return;
    const profile = document.querySelector('.profile');

    // Build the popover once and keep it in the DOM; content is refreshed on open.
    const pop = document.createElement('section');
    pop.id = 'profile-popover';
    pop.className = 'popover profile-popover';
    pop.setAttribute('aria-labelledby', 'profile-name');
    pop.hidden = true;
    const identity = document.createElement('div');
    identity.className = 'profile-summary';
    const photo = document.createElement('span');
    photo.className = 'profile-photo';
    const letters = document.createElement('span');
    letters.className = 'profile-initials';
    const img = document.createElement('img');
    img.alt = '';
    img.referrerPolicy = 'no-referrer';
    img.hidden = true;
    photo.append(letters, img);
    const meta = document.createElement('span');
    meta.className = 'profile-identity';
    const nameEl = document.createElement('strong');
    nameEl.id = 'profile-name';
    const roleEl = document.createElement('small');
    roleEl.className = 'profile-role';
    meta.append(nameEl, roleEl);
    identity.append(photo, meta);
    const view = document.createElement('a');
    view.className = 'view-all';
    view.id = 'view-profile';
    view.href = 'settings.php';
    view.setAttribute('aria-label', 'View profile');
    view.textContent = 'View profile';
    const arrow = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    arrow.setAttribute('aria-hidden', 'true');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', '#arrow-icon');
    arrow.append(use);
    view.append(arrow);
    const logout = document.createElement('a');
    logout.className = 'view-all profile-logout';
    logout.id = 'profile-logout';
    logout.href = '../auth/logout.php';
    logout.setAttribute('aria-label', 'Log out');
    logout.textContent = 'Log out';
    logout.addEventListener('click', (e) => {
      e.preventDefault();
      close();
      ensureLogoutModal(logout.href);
    });
    const onSettings = /\/settings\.php$/i.test(window.location.pathname);
    if (onSettings) pop.append(identity, logout);
    else pop.append(identity, view, logout);
    profile.append(pop);

    img.addEventListener('error', () => {
      img.hidden = true;
      letters.hidden = false;
      trigger.style.backgroundImage = '';
      const existingImg = trigger.querySelector('img');
      if (existingImg) existingImg.style.display = 'none';
      const existingSpan = trigger.querySelector('span');
      if (existingSpan) existingSpan.style.display = 'inline-flex';
      else if (!existingImg) trigger.textContent = letters.textContent;
    });

    const apply = (p) => {
      nameEl.textContent = p.name;
      roleEl.textContent = p.role;
      const userLetters = p.initials || initials(p.name);
      letters.textContent = userLetters;

      const triggerImg = trigger.querySelector('img');
      const triggerSpan = trigger.querySelector('span');

      if (p.photo) {
        const testImg = new Image();
        testImg.referrerPolicy = 'no-referrer';
        testImg.onload = () => {
          trigger.style.backgroundImage = 'url("' + p.photo.replace(/["\\]/g, (c) => '\\' + c) + '")';
          if (triggerImg) {
            triggerImg.src = p.photo;
            triggerImg.style.display = 'block';
          }
          if (triggerSpan) triggerSpan.style.display = 'none';
        };
        testImg.onerror = () => {
          trigger.style.backgroundImage = '';
          if (triggerImg) triggerImg.style.display = 'none';
          if (triggerSpan) {
            triggerSpan.style.display = 'inline-flex';
            triggerSpan.textContent = userLetters;
          } else {
            trigger.textContent = userLetters;
          }
          img.hidden = true;
          letters.hidden = false;
        };
        testImg.src = p.photo;

        img.referrerPolicy = 'no-referrer';
        img.src = p.photo;
        img.hidden = false;
        letters.hidden = true;
      } else {
        img.removeAttribute('src');
        img.hidden = true;
        letters.hidden = false;
        trigger.style.backgroundImage = '';
        if (triggerImg) triggerImg.style.display = 'none';
        if (triggerSpan) {
          triggerSpan.style.display = 'inline-flex';
          triggerSpan.textContent = userLetters;
        } else {
          trigger.textContent = userLetters;
        }
      }
      trigger.setAttribute('aria-label', p.name + ' — open profile menu');
    };

    const close = (focus) => {
      trigger.setAttribute('aria-expanded', 'false');
      window.UniStayPopover.hide(pop, focus ? trigger : null);
    };

    const open = () => {
      apply(readProfile());
      window.UniStayPopover.show(pop);
      trigger.setAttribute('aria-expanded', 'true');
    };

    apply(readProfile());

    trigger.addEventListener('click', () => {
      if (pop.hidden || pop.classList.contains('is-closing')) open();
      else close();
    });
    trigger.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        if (pop.hidden || pop.classList.contains('is-closing')) open();
        else close();
      } else if (e.key === 'Escape' && !pop.hidden) {
        e.preventDefault();
        close(true);
      }
    });
    document.addEventListener('click', (e) => {
      if (!pop.hidden && !pop.contains(e.target) && !trigger.contains(e.target)) close();
    });
    document.addEventListener('focusin', (e) => {
      if (!pop.hidden && !pop.contains(e.target) && !trigger.contains(e.target)) close();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !pop.hidden) {
        e.preventDefault();
        close(true);
      }
    });

    // Allow the server (or any page) to push the current tenant's details later.
    window.setTenantProfile = (p) => {
      const updated = Object.assign(readProfile(), p);
      apply(updated);
    };
  });

  const ensureLogoutModal = (targetUrl) => {
    let backdrop = document.getElementById('unistayLogoutModal');

    const closeModal = () => {
      if (!backdrop || !backdrop.classList.contains('is-active')) return;
      backdrop.classList.add('is-closing');
      setTimeout(() => {
        backdrop.classList.remove('is-active', 'is-closing');
      }, 240);
    };

    if (!backdrop) {
      const isSubdir = window.location.pathname.includes('/tenant/') ||
        window.location.pathname.includes('/landlord/') ||
        window.location.pathname.includes('/admin/') ||
        window.location.pathname.includes('/auth/');
      const logoSrc = isSubdir ? '../assets/images/tenant/logo.png' : 'assets/images/tenant/logo.png';

      backdrop = document.createElement('div');
      backdrop.id = 'unistayLogoutModal';
      backdrop.className = 'logout-modal-backdrop';
      backdrop.innerHTML = `
        <div class="logout-modal-card" role="dialog" aria-modal="true" aria-labelledby="logoutModalTitle">
          <div class="logout-modal-icon" aria-hidden="true">
            <img src="${logoSrc}" alt="UniStay" class="logout-modal-logo-img" onerror="this.onerror=null;this.src='../assets/images/logo.png';">
          </div>
          <h2 class="logout-modal-title" id="logoutModalTitle">Sign out of UniStay?</h2>
          <p class="logout-modal-desc">Are you sure you want to log out? You will need to sign in again to access your account.</p>
          <div class="logout-modal-actions">
            <button type="button" class="btn-logout-cancel" id="btnLogoutCancel">Cancel</button>
            <a href="${targetUrl || '../auth/logout.php'}" class="btn-logout-confirm" id="btnLogoutConfirm">Yes, Log Out</a>
          </div>
        </div>
      `;
      document.body.append(backdrop);

      backdrop.addEventListener('click', (e) => {
        if (!e.target.closest('.logout-modal-card')) closeModal();
      });
      backdrop.querySelector('#btnLogoutCancel').addEventListener('click', () => {
        closeModal();
      });
      backdrop.querySelector('#btnLogoutConfirm').addEventListener('click', () => {
        try { localStorage.removeItem(KEY); } catch (e) { }
      });
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && backdrop.classList.contains('is-active')) {
          closeModal();
        }
      });
    } else {
      const confirmBtn = backdrop.querySelector('#btnLogoutConfirm');
      if (confirmBtn && targetUrl) confirmBtn.href = targetUrl;
    }

    backdrop.classList.remove('is-closing');
    // Force reflow for smooth entrance
    void backdrop.offsetWidth;
    backdrop.classList.add('is-active');
  };

  // Global delegation for any logout link in any view
  document.addEventListener('click', (e) => {
    const link = e.target.closest('a[href*="logout.php"]');
    if (link && !link.closest('#unistayLogoutModal')) {
      e.preventDefault();
      ensureLogoutModal(link.href);
    }
  });

  window.UniStayLogoutModal = ensureLogoutModal;
})();
