(function () {
  'use strict';

  var THEME_KEY = 'unistay-theme';
  var COOKIE_NAME = 'unistay_theme';

  function getCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
  }

  function setCookie(name, val) {
    document.cookie = name + '=' + encodeURIComponent(val) + ';path=/;max-age=31536000;SameSite=Lax';
  }

  function getSavedTheme() {
    try {
      var saved = localStorage.getItem(THEME_KEY);
      if (saved === 'dark' || saved === 'light') return saved;
    } catch (e) {}

    var cookieVal = getCookie(COOKIE_NAME);
    if (cookieVal === 'dark' || cookieVal === 'light') return cookieVal;

    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      return 'dark';
    }
    return 'light';
  }

  function saveTheme(theme) {
    try {
      localStorage.setItem(THEME_KEY, theme);
    } catch (e) {}
    setCookie(COOKIE_NAME, theme);
  }

  function updateToggleButtons(theme) {
    var isDark = theme === 'dark';
    var sunSvg = '<svg width="27" height="27" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';
    var moonSvg = '<svg width="27" height="27" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';

    var btns = document.querySelectorAll('[data-theme-toggle]');
    btns.forEach(function (btn) {
      btn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
      btn.setAttribute('title', isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode');
      btn.setAttribute('aria-label', isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode');
      var icon = btn.querySelector('.theme-toggle-icon');
      if (icon) {
        icon.innerHTML = isDark ? sunSvg : moonSvg;
      } else {
        btn.innerHTML = isDark ? sunSvg : moonSvg;
      }
      var text = btn.querySelector('.theme-toggle-text');
      if (text) {
        text.textContent = isDark ? 'Light Mode' : 'Dark Mode';
      }
    });

    // Sync sliding pill switches
    var switches = document.querySelectorAll('[data-theme-switch]');
    switches.forEach(function (sw) {
      sw.checked = isDark;
    });
  }

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.classList.toggle('dark-theme', theme === 'dark');
    updateToggleButtons(theme);
    saveTheme(theme);
  }

  // Apply immediately in head before first paint
  var currentTheme = getSavedTheme();
  document.documentElement.setAttribute('data-theme', currentTheme);
  if (currentTheme === 'dark') document.documentElement.classList.add('dark-theme');

  window.toggleUniStayTheme = function () {
    var next = (document.documentElement.getAttribute('data-theme') === 'dark') ? 'light' : 'dark';
    applyTheme(next);
    return next;
  };

  window.getUniStayTheme = function () {
    return document.documentElement.getAttribute('data-theme') || 'light';
  };

  window.updateUniStayThemeButtons = updateToggleButtons;

  // Global pure white text styling for logout modals in dark mode
  var logoutStyle = document.createElement('style');
  logoutStyle.id = 'unistay-dark-logout-modal-fix';
  logoutStyle.textContent = `
    html[data-theme="dark"] .logout-modal-card,
    html[data-theme="dark"] .logout-modal-card *,
    [data-theme="dark"] .logout-modal-card,
    [data-theme="dark"] .logout-modal-card * {
      color: #ffffff !important;
    }
    html[data-theme="dark"] .logout-modal-title,
    [data-theme="dark"] .logout-modal-title {
      color: #ffffff !important;
    }
    html[data-theme="dark"] .logout-modal-desc,
    [data-theme="dark"] .logout-modal-desc {
      color: #ffffff !important;
    }
    html[data-theme="dark"] .btn-logout-cancel,
    [data-theme="dark"] .btn-logout-cancel {
      color: #ffffff !important;
      background: #382921 !important;
      border-color: #563e33 !important;
    }
    html[data-theme="dark"] .btn-logout-cancel:hover,
    [data-theme="dark"] .btn-logout-cancel:hover {
      background: #4a372c !important;
      color: #ffffff !important;
    }
    html[data-theme="dark"] .btn-logout-confirm,
    [data-theme="dark"] .btn-logout-confirm {
      color: #ffffff !important;
    }
  `;
  if (document.head) {
    document.head.appendChild(logoutStyle);
  } else {
    document.addEventListener('DOMContentLoaded', function () {
      if (document.head) document.head.appendChild(logoutStyle);
    });
  }

  // Global autofill neutralizer to eliminate browser blue background on inputs
  var autofillStyle = document.createElement('style');
  autofillStyle.id = 'unistay-autofill-neutralizer';
  autofillStyle.textContent = `
    /* Light Mode Autofill */
    input:-webkit-autofill,
    input:-webkit-autofill:hover, 
    input:-webkit-autofill:focus,
    input:-webkit-autofill:active,
    textarea:-webkit-autofill,
    textarea:-webkit-autofill:hover,
    textarea:-webkit-autofill:focus,
    textarea:-webkit-autofill:active,
    select:-webkit-autofill,
    select:-webkit-autofill:hover,
    select:-webkit-autofill:focus,
    select:-webkit-autofill:active {
      -webkit-box-shadow: 0 0 0 1000px #ffffff inset !important;
      box-shadow: 0 0 0 1000px #ffffff inset !important;
      -webkit-text-fill-color: #3b2820 !important;
      caret-color: #3b2820 !important;
      transition: background-color 5000s ease-in-out 0s !important;
    }

    /* Dark Mode Autofill */
    html[data-theme="dark"] input:-webkit-autofill,
    html[data-theme="dark"] input:-webkit-autofill:hover, 
    html[data-theme="dark"] input:-webkit-autofill:focus,
    html[data-theme="dark"] input:-webkit-autofill:active,
    html[data-theme="dark"] textarea:-webkit-autofill,
    html[data-theme="dark"] textarea:-webkit-autofill:hover,
    html[data-theme="dark"] textarea:-webkit-autofill:focus,
    html[data-theme="dark"] textarea:-webkit-autofill:active,
    html[data-theme="dark"] select:-webkit-autofill,
    html[data-theme="dark"] select:-webkit-autofill:hover,
    html[data-theme="dark"] select:-webkit-autofill:focus,
    html[data-theme="dark"] select:-webkit-autofill:active,
    [data-theme="dark"] input:-webkit-autofill,
    [data-theme="dark"] input:-webkit-autofill:hover, 
    [data-theme="dark"] input:-webkit-autofill:focus,
    [data-theme="dark"] input:-webkit-autofill:active,
    [data-theme="dark"] textarea:-webkit-autofill,
    [data-theme="dark"] textarea:-webkit-autofill:hover,
    [data-theme="dark"] textarea:-webkit-autofill:focus,
    [data-theme="dark"] textarea:-webkit-autofill:active,
    [data-theme="dark"] select:-webkit-autofill,
    [data-theme="dark"] select:-webkit-autofill:hover,
    [data-theme="dark"] select:-webkit-autofill:focus,
    [data-theme="dark"] select:-webkit-autofill:active {
      -webkit-box-shadow: 0 0 0 1000px #231a14 inset !important;
      box-shadow: 0 0 0 1000px #231a14 inset !important;
      -webkit-text-fill-color: #fff7f2 !important;
      caret-color: #fff7f2 !important;
      transition: background-color 5000s ease-in-out 0s !important;
    }

    /* Form-specific field backgrounds */
    #landlord-form .la-input:-webkit-autofill,
    #landlord-form .la-input:-webkit-autofill:hover,
    #landlord-form .la-input:-webkit-autofill:focus {
      -webkit-box-shadow: 0 0 0 1000px #ffffff inset !important;
      box-shadow: 0 0 0 1000px #ffffff inset !important;
      -webkit-text-fill-color: #3b2820 !important;
    }
    html[data-theme="dark"] #landlord-form .la-input:-webkit-autofill,
    html[data-theme="dark"] #landlord-form .la-input:-webkit-autofill:hover,
    html[data-theme="dark"] #landlord-form .la-input:-webkit-autofill:focus {
      -webkit-box-shadow: 0 0 0 1000px #231a14 inset !important;
      box-shadow: 0 0 0 1000px #231a14 inset !important;
      -webkit-text-fill-color: #fff7f2 !important;
    }
  `;
  if (document.head) {
    document.head.appendChild(autofillStyle);
  } else {
    document.addEventListener('DOMContentLoaded', function () {
      if (document.head) document.head.appendChild(autofillStyle);
    });
  }

  // Global helper to show the Profile Success Modal on any page
  window.showProfileSuccessModal = function (msg) {
    var existing = document.getElementById('profileSuccessModal');
    if (existing) {
      var msgEl = existing.querySelector('#psMsg');
      if (msgEl) msgEl.textContent = msg || 'Profile updated successfully!';
      if (typeof existing.showModal === 'function') existing.showModal();
      return;
    }
    var dialog = document.createElement('dialog');
    dialog.id = 'profileSuccessModal';
    dialog.className = 'ps-modal';
    dialog.setAttribute('aria-labelledby', 'psTitle');
    dialog.setAttribute('aria-describedby', 'psMsg');
    dialog.innerHTML = `
      <div class="ps-card">
        <div class="ps-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" style="width:30px;height:30px;stroke-width:2.2;fill:none;stroke:currentColor"><path d="m5 12 5 5 9-10"/></svg>
        </div>
        <h2 id="psTitle" class="ps-title">Profile Updated</h2>
        <p id="psMsg" class="ps-msg">${msg || 'Profile updated successfully!'}</p>
        <div class="ps-actions">
          <button type="button" id="psClose" class="ps-btn">Continue</button>
        </div>
      </div>
    `;
    function close() {
      dialog.close();
      dialog.remove();
    }
    dialog.addEventListener('click', function (e) {
      if (e.target === dialog) close();
    });
    var btn = dialog.querySelector('#psClose');
    if (btn) btn.addEventListener('click', close);
    document.body.appendChild(dialog);
    if (typeof dialog.showModal === 'function') dialog.showModal();
  };

  document.addEventListener('DOMContentLoaded', function () {
    updateToggleButtons(window.getUniStayTheme());

    // Check for cross-page profile updated flash
    var flashMsg = getCookie('unistay_profile_success');
    if (flashMsg) {
      document.cookie = 'unistay_profile_success=;path=/;max-age=0';
      setTimeout(function () {
        window.showProfileSuccessModal(flashMsg);
      }, 50);
    }
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-theme-toggle]');
      if (btn) {
        e.preventDefault();
        e.stopPropagation();
        window.toggleUniStayTheme();
      }
    });
    document.addEventListener('change', function (e) {
      var sw = e.target.closest('[data-theme-switch]');
      if (sw) {
        var desired = sw.checked ? 'dark' : 'light';
        try {
          localStorage.setItem(THEME_KEY, desired);
        } catch (err) {}
        applyTheme(desired);
      }
    });
  });
})();
