(() => {
  'use strict';

  const form = document.getElementById('profileEditForm');
  const save = document.getElementById('profileSaveButton');
  const hint = document.getElementById('profileSaveHint');
  if (!form || !save) return;

  const requiredDocs = (form.dataset.requiredDocs || '')
    .split(',')
    .map((key) => key.trim())
    .filter(Boolean);

  function hasDocument(key) {
    const input = document.getElementById(`${key}_input`);
    const existing = form.querySelector(`[name="existing_${key}"]`);
    const remove = document.getElementById(`remove_${key}`);
    const selected = !!(input?.files?.length);
    const stored = !!(existing?.value.trim() && (!remove || remove.value !== '1'));
    return selected || stored;
  }

  function sync() {
    const complete = form.checkValidity() && requiredDocs.every(hasDocument);
    save.disabled = !complete;
    save.setAttribute('aria-disabled', complete ? 'false' : 'true');
    if (hint) hint.hidden = complete;
    return complete;
  }

  form.addEventListener('input', sync);
  form.addEventListener('change', sync);
  // Removal and custom dropzone controls update hidden fields in their own
  // click handlers, so recheck immediately after those handlers finish.
  form.addEventListener('click', () => setTimeout(sync, 0));
  form.addEventListener('submit', (event) => {
    if (sync()) return;
    event.preventDefault();
    form.reportValidity();
  });

  window.syncProfileSaveState = sync;
  sync();
})();
