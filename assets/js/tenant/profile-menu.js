(() => {
  'use strict';

  const KEY = 'unistay-tenant-profile';
  const DEFAULTS = { name: 'Alex Johnson', role: 'Tenant', photo: '' };

  const initials = (name) =>
    name
      .trim()
      .split(/\s+/)
      .slice(0, 2)
      .map((w) => w[0] || '')
      .join('')
      .toUpperCase() || '?';

  const readProfile = () => {
    let saved = null;
    try { saved = JSON.parse(localStorage.getItem(KEY)); } catch (e) {}
    const injected = window.UNISTAY_TENANT_PROFILE || null;
    const merged = Object.assign({}, DEFAULTS, injected, saved);
    return {
      name: typeof merged.name === 'string' && merged.name.trim() ? merged.name.trim() : DEFAULTS.name,
      role: typeof merged.role === 'string' && merged.role.trim() ? merged.role.trim() : DEFAULTS.role,
      photo: typeof merged.photo === 'string' ? merged.photo.trim() : '',
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
    pop.append(identity, view, logout);
    profile.append(pop);

    const apply = (p) => {
      nameEl.textContent = p.name;
      roleEl.textContent = p.role;
      letters.textContent = initials(p.name);
      if (p.photo) {
        img.src = p.photo;
        img.hidden = false;
        trigger.style.backgroundImage = 'url("' + p.photo.replace(/["\\]/g, (c) => '\\' + c) + '")';
        trigger.textContent = '';
      } else {
        img.removeAttribute('src');
        img.hidden = true;
        trigger.style.backgroundImage = '';
        trigger.textContent = initials(p.name);
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
    window.setTenantProfile = (p) => apply(Object.assign(readProfile(), p));
  });
})();