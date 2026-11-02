(() => {
  'use strict';
  const dataNode = document.getElementById('landlord-onboarding-data');
  if (!dataNode || dataNode.dataset.initialized) return;
  dataNode.dataset.initialized = 'true';
  const data = JSON.parse(dataNode.textContent);
  const guide = document.getElementById('landlord-guide-modal');
  const completion = document.getElementById('landlord-completion-modal');
  const launcher = document.getElementById('landlord-guide-launcher');
  const next = document.getElementById('landlord-guide-next');
  const back = document.getElementById('landlord-guide-back');
  const dismissedKey = `unistay:landlord:${data.userId}:completion-dismissed`;
  const viewedKey = `unistay:landlord:${data.userId}:guide-viewed`;
  const storage = (store, method, key, value) => {
    try { return window[store][method](key, value); } catch (_) { return null; }
  };
  let step = 1;
  let automatic = false;
  let closing = false;
  let previousOverflow = null;
  const lock = () => {
    if (previousOverflow === null) previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
  };
  const unlock = () => {
    if (previousOverflow !== null) document.body.style.overflow = previousOverflow;
    previousOverflow = null;
    launcher?.focus({preventScroll: true});
  };
  function showStep(value, focus = true) {
    step = Math.max(1, Math.min(value, 4));
    guide.querySelectorAll('.guide-slide').forEach(slide => {
      slide.hidden = Number(slide.dataset.step) !== step;
      slide.classList.toggle('active', !slide.hidden);
    });
    guide.querySelectorAll('[data-goto]').forEach(dot => {
      const active = Number(dot.dataset.goto) === step;
      dot.classList.toggle('active', active);
      dot.setAttribute('aria-current', String(active));
    });
    guide.setAttribute('aria-labelledby', `landlord-guide-title-${step}`);
    document.getElementById('landlord-guide-step').textContent = `Step ${step} of 4`;
    back.hidden = step === 1;
    next.textContent = step === 4 ? "Let's get started" : 'Next →';
    next.classList.toggle('btn-guide-finish', step === 4);
    if (focus) document.getElementById(`landlord-guide-title-${step}`).focus();
  }
  function closeAnimated(dialog, done) {
    if (closing || !dialog.open) return;
    closing = true;
    let timer;
    const finish = () => {
      clearTimeout(timer);
      dialog.removeEventListener('animationend', onEnd);
      dialog.close();
      dialog.classList.remove('is-closing');
      closing = false;
      done();
    };
    const onEnd = event => {
      if (event.target === dialog && event.animationName === 'modal-dismiss-center') finish();
    };
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { finish(); return; }
    dialog.classList.add('is-closing');
    dialog.addEventListener('animationend', onEnd);
    timer = setTimeout(finish, 260);
  }
  function showCompletion() {
    if (!data.incomplete || completion.open) { unlock(); return; }
    lock();
    completion.showModal();
  }
  function finishGuide() {
    const followUp = automatic && data.incomplete;
    closeAnimated(guide, () => {
      storage('localStorage', 'setItem', viewedKey, '1');
      automatic = false;
      if (followUp) showCompletion(); else unlock();
    });
  }
  function dismissCompletion() {
    storage('sessionStorage', 'setItem', dismissedKey, '1');
    closeAnimated(completion, unlock);
  }
  function openGuide(isAutomatic = false) {
    if (closing || guide.open || completion.open) return;
    automatic = isAutomatic;
    showStep(1, false);
    lock();
    guide.showModal();
  }
  next.addEventListener('click', () => { if (!closing) step < 4 ? showStep(step + 1) : finishGuide(); });
  back.addEventListener('click', () => { if (!closing) showStep(step - 1); });
  guide.querySelectorAll('[data-goto]').forEach(dot => dot.addEventListener('click', () => {
    if (!closing) showStep(Number(dot.dataset.goto));
  }));
  document.getElementById('landlord-guide-skip').addEventListener('click', finishGuide);
  document.getElementById('landlord-completion-later').addEventListener('click', dismissCompletion);
  document.getElementById('landlord-completion-now').addEventListener('click', () => {
    storage('sessionStorage', 'setItem', dismissedKey, '1');
  });
  launcher?.addEventListener('click', () => openGuide());
  for (const [dialog, dismiss] of [[guide, finishGuide], [completion, dismissCompletion]]) {
    dialog.addEventListener('cancel', event => { event.preventDefault(); dismiss(); });
    dialog.addEventListener('click', event => {
      const rect = dialog.getBoundingClientRect();
      if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) dismiss();
    });
  }
  guide.addEventListener('keydown', event => {
    if (closing) return;
    if (event.key === 'ArrowRight' && step < 4) { event.preventDefault(); showStep(step + 1); }
    if (event.key === 'ArrowLeft' && step > 1) { event.preventDefault(); showStep(step - 1); }
  });
  if (data.isNew) {
    storage('sessionStorage', 'removeItem', dismissedKey);
    openGuide(true);
  } else if (data.incomplete && !storage('sessionStorage', 'getItem', dismissedKey)) showCompletion();
})();
