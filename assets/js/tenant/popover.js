(() => {
  'use strict';

  const reduced = () =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function clearPending(el) {
    if (el && el._popoverOnEnd) {
      el.removeEventListener('animationend', el._popoverOnEnd);
      el._popoverOnEnd = null;
    }
  }

  function show(el) {
    if (!el) return;
    clearPending(el);
    el.classList.remove('is-closing');
    el.hidden = false;
  }

  function hide(el, focusEl) {
    if (!el || el.hidden || el.classList.contains('is-closing')) {
      if (focusEl) focusEl.focus();
      return;
    }

    const finish = () => {
      clearPending(el);
      el.classList.remove('is-closing');
      el.hidden = true;
      if (focusEl) focusEl.focus();
    };

    if (reduced()) {
      finish();
      return;
    }

    const onEnd = (e) => {
      if (e.animationName === 'popover-out') finish();
    };
    el._popoverOnEnd = onEnd;
    el.classList.add('is-closing');
    el.addEventListener('animationend', onEnd);
  }

  window.UniStayPopover = Object.freeze({ show, hide });
})();