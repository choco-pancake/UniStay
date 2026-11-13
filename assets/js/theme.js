(function () {
  'use strict';

  var THEME_KEY = 'unistay-theme';

  function getSavedTheme() {
    try {
      var saved = localStorage.getItem(THEME_KEY);
      if (saved === 'dark' || saved === 'light') return saved;
    } catch (e) {}
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      return 'dark';
    }
    return 'light';
  }

  function updateToggleButtons(theme) {
    var isDark = theme === 'dark';
    var sunSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';
    var moonSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';

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
  }

  // Apply immediately in head before first paint
  var currentTheme = getSavedTheme();
  document.documentElement.setAttribute('data-theme', currentTheme);
  if (currentTheme === 'dark') document.documentElement.classList.add('dark-theme');

  window.toggleUniStayTheme = function () {
    var next = (document.documentElement.getAttribute('data-theme') === 'dark') ? 'light' : 'dark';
    try {
      localStorage.setItem(THEME_KEY, next);
    } catch (e) {}
    applyTheme(next);
    return next;
  };

  window.getUniStayTheme = function () {
    return document.documentElement.getAttribute('data-theme') || 'light';
  };

  window.updateUniStayThemeButtons = updateToggleButtons;

  document.addEventListener('DOMContentLoaded', function () {
    updateToggleButtons(window.getUniStayTheme());
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
