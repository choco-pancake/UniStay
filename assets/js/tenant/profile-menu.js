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

  const readProfile = (triggerFallback) => {
    const injected = window.UNISTAY_TENANT_PROFILE || null;
    if (injected && (injected.name || injected.email || injected.photo)) {
      return {
        name: injected.name || DEFAULTS.name,
        role: injected.role || DEFAULTS.role,
        photo: sanitizePhoto(injected.photo),
        initials: injected.initials || initials(injected.name || ''),
      };
    }
    if (triggerFallback) {
      const strongName = triggerFallback.querySelector('strong');
      const badgeRole = triggerFallback.querySelector('span');
      if (strongName) {
        const n = strongName.textContent.trim();
        return {
          name: n,
          role: badgeRole ? badgeRole.textContent.trim() : 'Administrator',
          photo: '',
          initials: initials(n),
        };
      }
    }
    return {
      name: DEFAULTS.name,
      role: DEFAULTS.role,
      photo: '',
      initials: 'U',
    };
  };

  document.addEventListener('DOMContentLoaded', () => {
    let trigger = document.querySelector('.profile .avatar');
    let profile = document.querySelector('.profile');

    if (!trigger) {
      trigger = document.querySelector('.admin-user-badge');
      if (trigger) {
        profile = trigger;
      } else {
        return;
      }
    }

    // Mount topbar theme toggle button if not present
    const topbarWrap = document.querySelector('.topbar') || document.querySelector('.admin-topbar');
    if (topbarWrap && !topbarWrap.querySelector('.btn-theme-toggle')) {
      const topbarThemeBtn = document.createElement('button');
      topbarThemeBtn.type = 'button';
      topbarThemeBtn.className = 'btn-theme-toggle';
      topbarThemeBtn.setAttribute('data-theme-toggle', '');
      topbarThemeBtn.setAttribute('title', 'Toggle Theme');
      topbarThemeBtn.setAttribute('aria-label', 'Toggle Theme');

      const bell = topbarWrap.querySelector('#notification-bell, .bell');
      const guide = topbarWrap.querySelector('#guide-launcher-btn, .btn-topbar-guide');

      if (bell) {
        // Place precisely between ? and notification bell
        bell.parentElement.insertBefore(topbarThemeBtn, bell);
      } else if (guide && guide.nextElementSibling) {
        guide.parentElement.insertBefore(topbarThemeBtn, guide.nextElementSibling);
      } else if (profile && profile.parentElement) {
        profile.parentElement.insertBefore(topbarThemeBtn, profile);
      } else {
        topbarWrap.append(topbarThemeBtn);
      }
    }

    const isSubdir = window.location.pathname.includes('/tenant/') ||
      window.location.pathname.includes('/landlord/') ||
      window.location.pathname.includes('/admin/') ||
      window.location.pathname.includes('/auth/');
    const switchApiUrl = isSubdir ? '../api/switch_account.php' : 'api/switch_account.php';
    const loginSwitchUrl = isSubdir ? '../auth/login.php?action=switch' : 'auth/login.php?action=switch';

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

    const roleRow = document.createElement('span');
    roleRow.className = 'profile-role-row';
    const roleEl = document.createElement('small');
    roleEl.className = 'profile-role';
    roleRow.append(roleEl);

    const onSettings = /\/settings\.php$/i.test(window.location.pathname);
    if (!onSettings) {
      const dot = document.createElement('span');
      dot.className = 'profile-role-sep';
      dot.textContent = '•';
      const view = document.createElement('a');
      view.className = 'profile-view-link';
      view.id = 'view-profile';
      view.href = 'settings.php';
      view.setAttribute('aria-label', 'View profile');
      view.textContent = 'View profile';
      roleRow.append(dot, view);
    }

    meta.append(nameEl, roleRow);
    identity.append(photo, meta);

    // Switch Account Section
    const switchSection = document.createElement('div');
    switchSection.className = 'profile-switch-section';
    const switchHeader = document.createElement('div');
    switchHeader.className = 'profile-switch-header';
    switchHeader.textContent = 'Switch Account';
    const accountsList = document.createElement('div');
    accountsList.className = 'profile-accounts-list';
    accountsList.id = 'profileAccountsList';
    accountsList.innerHTML = '<div class="profile-switch-empty">Loading accounts...</div>';

    const addAccountLink = document.createElement('a');
    addAccountLink.className = 'view-all profile-add-account';
    addAccountLink.href = loginSwitchUrl;
    addAccountLink.textContent = '+ Sign in to another account';

    switchSection.append(switchHeader, accountsList, addAccountLink);
    let accountSwitchCsrf = '';

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

    pop.append(identity, switchSection, logout);
    profile.append(pop);

    if (window.updateUniStayThemeButtons) {
      window.updateUniStayThemeButtons(window.getUniStayTheme ? window.getUniStayTheme() : 'light');
    }

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
          // If trigger is an admin badge, update its .admin-avatar
          const adminAv = trigger.querySelector('.admin-avatar');
          if (adminAv) adminAv.textContent = userLetters;
          else trigger.textContent = userLetters;
        }
      }
      trigger.setAttribute('aria-label', p.name + ' — open profile menu');
    };

    const fetchAccounts = () => {
      accountsList.innerHTML = '<div class="profile-switch-empty">Loading accounts...</div>';
      fetch(switchApiUrl, { credentials: 'same-origin' })
        .then(async (res) => {
          const data = await res.json().catch(() => null);
          if (!res.ok) throw new Error((data && (data.error || data.message)) || 'Could not load accounts');
          return data;
        })
        .then((data) => {
          if (!data || !data.ok || !Array.isArray(data.accounts)) {
            throw new Error('Could not load accounts');
          }
          accountSwitchCsrf = typeof data.csrf_token === 'string' ? data.csrf_token : '';
          accountsList.innerHTML = '';
          const currentId = data.current_id;
          const currentRole = data.current_role;
          const otherAccounts = data.accounts.filter((a) => !a.is_current && !(a.id === currentId && a.role === currentRole));

          if (otherAccounts.length === 0) {
            accountsList.innerHTML = '<div class="profile-switch-empty">No other accounts available</div>';
            return;
          }

          // Display at most 3 accounts in the profile floating panel
          const displayAccounts = otherAccounts.slice(0, 3);

          displayAccounts.forEach((acc) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'profile-account-item';
            btn.setAttribute('aria-label', `Switch to ${acc.name} (${acc.role})`);

            const av = document.createElement('div');
            av.className = 'account-avatar';
            if (acc.photo) {
              const im = document.createElement('img');
              im.alt = '';
              im.referrerPolicy = 'no-referrer';
              im.src = acc.photo;
              im.addEventListener('error', () => {
                av.innerHTML = '';
                av.textContent = acc.initials || 'U';
              });
              av.append(im);
            } else {
              av.textContent = acc.initials || 'U';
            }

            const det = document.createElement('div');
            det.className = 'account-details';

            const line = document.createElement('div');
            line.className = 'account-name';
            const nameSpan = document.createElement('span');
            nameSpan.textContent = acc.name;
            const badge = document.createElement('span');
            badge.className = `account-role-badge role-${acc.role}`;
            badge.textContent = acc.role;
            line.append(nameSpan, badge);

            const emailDiv = document.createElement('div');
            emailDiv.className = 'account-email';
            emailDiv.textContent = acc.email;

            det.append(line, emailDiv);
            btn.append(av, det);

            btn.addEventListener('click', (e) => {
              e.preventDefault();
              e.stopPropagation();
              btn.classList.add('is-switching');
              nameSpan.textContent = 'Switching...';
              fetch(switchApiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ user_id: acc.id, role: acc.role, csrf_token: accountSwitchCsrf })
              })
                .then(async (r) => {
                  const payload = await r.json().catch(() => null);
                  if (!r.ok) throw new Error((payload && (payload.error || payload.message)) || 'Could not switch accounts');
                  return payload;
                })
                .then((res) => {
                  if (res && res.ok && res.redirect) {
                    window.location.href = res.redirect;
                  } else {
                    throw new Error('Could not switch accounts');
                  }
                })
                .catch(() => {
                  btn.classList.remove('is-switching');
                  nameSpan.textContent = acc.name;
                  accountsList.insertAdjacentHTML('beforeend', '<div class="profile-switch-empty">Could not switch accounts. Please try again.</div>');
                });
            });

            accountsList.append(btn);
          });
        })
        .catch(() => {
          accountSwitchCsrf = '';
          accountsList.innerHTML = '<div class="profile-switch-empty">Could not load accounts</div>';
        });
    };

    const close = (focus) => {
      trigger.setAttribute('aria-expanded', 'false');
      if (window.UniStayPopover) {
        window.UniStayPopover.hide(pop, focus ? trigger : null);
      } else {
        pop.hidden = true;
      }
    };

    const open = () => {
      apply(readProfile(trigger));
      fetchAccounts();
      if (window.UniStayPopover) {
        window.UniStayPopover.show(pop);
      } else {
        pop.hidden = false;
      }
      trigger.setAttribute('aria-expanded', 'true');
    };

    apply(readProfile(trigger));
    fetchAccounts();

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

    // Allow the server (or any page) to push details later.
    window.setTenantProfile = (p) => {
      const updated = Object.assign(readProfile(trigger), p);
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
