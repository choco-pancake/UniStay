(() => {
  'use strict';

  const scrollLocks = new Set();
  let savedPaddingRight = '';
  let pageLocked = false;

  function syncScrollLock() {
    const locked = scrollLocks.size > 0;
    document.documentElement.classList.toggle('unistay-scroll-locked', locked);
    document.body?.classList.toggle('unistay-scroll-locked', locked);
    if (locked && !pageLocked) {
      savedPaddingRight = document.body?.style.paddingRight || '';
      const gutter = Math.max(0, window.innerWidth - document.documentElement.clientWidth);
      if (document.body && gutter) document.body.style.paddingRight = `${gutter}px`;
      pageLocked = true;
    } else if (!locked && pageLocked && document.body) {
      document.body.style.paddingRight = savedPaddingRight;
      savedPaddingRight = '';
      pageLocked = false;
    }
  }

  function lock(token) {
    if (!token || scrollLocks.has(token)) return;
    scrollLocks.add(token);
    syncScrollLock();
  }

  function unlock(token) {
    if (!token || !scrollLocks.delete(token)) return;
    syncScrollLock();
  }

  function syncModalLocks() {
    const active = new Set([
      ...document.querySelectorAll('dialog[open], .settings-modal-overlay.is-open, .cropper-modal-overlay.is-open, .id-lightbox-overlay.is-open, .modal-overlay.is-open')
    ]);
    [...scrollLocks].forEach(token => {
      if (token instanceof Element && token.dataset.unistayObservedLock === '1' && !active.has(token)) unlock(token);
    });
    active.forEach(element => {
      element.dataset.unistayObservedLock = '1';
      lock(element);
    });
  }

  function observeFloatingPanels() {
    syncModalLocks();
    new MutationObserver(syncModalLocks).observe(document.body, {
      subtree: true,
      childList: true,
      attributes: true,
      attributeFilter: ['open', 'class', 'hidden']
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', observeFloatingPanels, {once: true});
  else observeFloatingPanels();

  window.UniStayScrollLock = Object.freeze({ lock, unlock, isLocked: () => scrollLocks.size > 0 });

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
    lock(el);
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
      unlock(el);
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
