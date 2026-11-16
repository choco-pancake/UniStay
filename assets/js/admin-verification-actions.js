(() => {
  'use strict';
  let pending = false;
  const originals = new Map();
  function setBusy(active, label = 'Processing request...') {
    const overlay = document.getElementById('verification-busy-overlay');
    pending = active;
    if (!overlay) return;
    overlay.querySelector('span').textContent = label;
    overlay.hidden = !active;
    document.querySelectorAll('body > :not(#verification-busy-overlay)').forEach(node => {
      if (active) node.setAttribute('inert', '');
      else node.removeAttribute('inert');
    });
    document.body.setAttribute('aria-busy', active ? 'true' : 'false');
  }
  window.setVerificationBusy = setBusy;
  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
    if (pending) { event.preventDefault(); return; }
    const action = form.querySelector('input[name="action"]')?.value;
    const approving = /^(approve_(user|landlord|tenant|property|application)|bulk_approve)$/.test(action || '');
    const declining = /^(reject_(user|landlord|tenant)|decline_(property|application)|bulk_decline)$/.test(action || '');
    if (!approving && !declining) return;
    const button = event.submitter || form.querySelector('button[type="submit"]');
    if (!button) return;
    setBusy(true, approving ? 'Approving request...' : 'Declining request...');
    form.dataset.submitting = '1';
    form.setAttribute('aria-busy', 'true');
    originals.set(button, {text: button.textContent, disabled: button.disabled});
    button.disabled = true;
    button.textContent = approving ? 'Approving...' : 'Declining...';
    // Action, user, role and reason are hidden inputs/textarea and remain submitted.
  });
  window.addEventListener('pageshow', event => {
    if (!event.persisted) return;
    originals.forEach((original, control) => {
      control.textContent = original.text;
      control.disabled = original.disabled;
    });
    originals.clear(); setBusy(false);
    document.querySelectorAll('form[data-submitting]').forEach(form => {
      delete form.dataset.submitting;
      form.removeAttribute('aria-busy');
    });
    // Reload current verification statuses instead of displaying a stale review card.
    window.location.reload();
  });
})();
