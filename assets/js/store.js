// Photos a landlord may attach to one room — mirrors MAX_ROOM_PHOTOS in api/db.php
const MAX_ROOM_PHOTOS = 3;
const Store = (() => {
  const API = '../api/';
  const call = async (url, opts) => {
    const res = await fetch(API + url, opts);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
  };
  const fix = (p) => ({                              // DB stores paths relative to the project root
    ...p,
    photo: p.photo ? '../' + p.photo : p.photo,
    photos: (p.photos || []).map(x => '../' + x),
    rooms: (p.rooms || []).map(r => ({
      ...r,
      photo: r.photo ? '../' + r.photo : null,
      photos: (r.photos || []).map(x => '../' + x),
    })),
  });
  // Accepts one File, a list of them, or nothing -> a de-duplicated list capped at MAX_ROOM_PHOTOS
  const photoList = (photos) => {
    const list = Array.isArray(photos) ? photos : [photos].filter(Boolean);
    return list.filter((f, i) => list.indexOf(f) === i).slice(0, MAX_ROOM_PHOTOS);
  };
  return {
    all: async () => (await call('properties.php')).map(fix),
    byLandlord: async () => (await call('properties.php?mine=1')).map(fix),
    add: (formData) => call('properties.php', { method: 'POST', body: formData }),
    remove: (id) => call('properties.php?id=' + id, { method: 'DELETE' }),
    addRoom: (propertyId, room, photos) => {
      const fd = new FormData();
      fd.append('property_id', propertyId);
      fd.append('room', JSON.stringify(room));
      photoList(photos).forEach(p => fd.append('photos[]', p));
      return call('rooms.php', { method: 'POST', body: fd });
    },
    removeRoom: (propertyId, roomId) => call('rooms.php?id=' + roomId, { method: 'DELETE' })
  };
})();
// Display-only placeholder until login exists (the server uses landlord_id() in api/db.php)
const CURRENT_LANDLORD = { id: 1, name: 'Juan Dela Cruz', role: 'Landlord' };
// Room types and their (derived) capacity — single source of truth for both landlord pages
const ROOM_TYPES = { SINGLE: 1, TWIN: 2, QUAD: 4, QUINTUPLE: 5, SEXTUPLE: 6, OCTUPLE: 8, DECUPLE: 10 };

// ---- Drag & drop helpers (photo upload zones) ----
// Stop the browser from opening files dropped outside a drop zone
window.addEventListener('dragover', (e) => e.preventDefault());
window.addEventListener('drop', (e) => e.preventDefault());

// Turns `zone` into a drop target for `input`: highlight while dragging, drop -> files,
// click (anywhere except the input itself) -> opens the file picker.
function attachDropZone(zone, input, onFiles) {
  const over = (e) => { e.preventDefault(); zone.classList.add('dz-over'); };
  zone.addEventListener('dragenter', over);
  zone.addEventListener('dragover', over);
  zone.addEventListener('dragleave', (e) => { if (!zone.contains(e.relatedTarget)) zone.classList.remove('dz-over'); });
  zone.addEventListener('drop', (e) => {
    e.preventDefault();
    zone.classList.remove('dz-over');
    const files = [...(e.dataTransfer?.files || [])];
    if (files.length) onFiles(files);
  });
  zone.addEventListener('click', (e) => { if (e.target !== input) input.click(); });
}
// Current files for a (possibly drop-assigned) input; falls back to the side channel
// used when the browser refuses `input.files = dataTransfer.files`.
const curFiles = (input) => (input.files && input.files.length ? [...input.files] : (input._dropped || []));
function setDroppedFiles(input, files) {
  input._dropped = files;
  const dt = new DataTransfer();
  files.forEach(f => dt.items.add(f));
  try { input.files = dt.files; } catch { /* keep the _dropped side channel */ }
}

// A capped photo picker over a drop zone + hidden file input: one removable thumbnail per
// selected file, a "N of max" counter, duplicates skipped, extra files reported as skipped.
// Keeps the input in sync so the form can read curFiles(input) at submit time.
function attachPhotoPicker(zone, input, preview, count, max = MAX_ROOM_PHOTOS) {
  const files = [];
  const left = () => max - files.length;
  const render = (skipped = 0) => {
    preview.innerHTML = files.map((f, i) => `
      <div class="relative">
        <img src="${URL.createObjectURL(f)}" class="w-full h-24 rounded-lg object-cover" alt="Photo ${i + 1}">
        <button type="button" data-rm="${i}" class="absolute -top-1.5 -right-1.5 w-5 h-5 rounded-full bg-slate-900/70 text-white text-xs leading-none hover:bg-slate-900" aria-label="Remove photo ${i + 1}">✕</button>
      </div>`).join('');
    preview.classList.toggle('hidden', !files.length);
    if (count) {
      count.textContent = files.length
        ? `${files.length} of ${max} selected${left() ? ` — ${left()} slot${left() > 1 ? 's' : ''} left` : ' ✓'}${skipped ? ` · ${skipped} skipped (images under 5 MB only)` : ''}`
        : (skipped ? `${skipped} file(s) skipped (images under 5 MB only).` : '');
      count.classList.toggle('hidden', !files.length && !skipped);
    }
    preview.querySelectorAll('[data-rm]').forEach(b => b.onclick = () => { files.splice(+b.dataset.rm, 1); render(); });
    setDroppedFiles(input, files);
  };
  const add = (incoming) => {
    let skipped = 0;
    for (const f of incoming) {
      if (files.length >= max || !f.type.startsWith('image/') || f.size > 5 * 1024 * 1024) { skipped++; continue; }
      if (!files.some(p => p.name === f.name && p.size === f.size)) files.push(f);
    }
    render(skipped);
  };
  input.onchange = (e) => { const picked = [...e.target.files]; e.target.value = ''; add(picked); };
  attachDropZone(zone, input, add);
  render();
  return { files, clear: () => { files.length = 0; input.value = ''; input._dropped = null; render(); } };
}

// Price formatting: "₱3,500.00" (fixed) or "₱3,500.00 – ₱5,000.00" (range)
const fmtPrice = (min, max) => {
  const f = (n) => Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return max !== null && max !== undefined && max !== '' && +max > 0 && +max !== +min ? `₱${f(min)} – ₱${f(max)}` : `₱${f(min)}`;
};

// Fixed/Range toggle for a [data-price] field group (markup: [data-fixed] + [data-range] + button[data-v])
function setPriceMode(w, v) {
  w.dataset.mode = v;
  w.querySelector('[data-fixed]').classList.toggle('hidden', v !== 'fixed');
  w.querySelector('[data-range]').classList.toggle('hidden', v !== 'range');
  w.querySelectorAll('button[data-v]').forEach(b => {
    const on = b.dataset.v === v;
    b.classList.toggle('bg-indigo-600', on);
    b.classList.toggle('text-white', on);
    b.classList.toggle('text-slate-600', !on);
    b.classList.toggle('hover:bg-slate-50', !on);
  });
}