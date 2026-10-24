(() => {
  'use strict';
  const params = new URLSearchParams(location.search);
  const pid = params.get('property');
  const rid = params.get('room');
  if (!pid || !rid) return;
  const dialog = document.getElementById('room-detail-dialog');
  const body = document.getElementById('room-detail-body');
  if (!dialog || !body) return;

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
  const fmt = (n) => Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const price = (min, max) => {
    const m = +min, M = max !== null && max !== undefined && max !== '' ? +max : null;
    if (M !== null && M > 0 && M !== m) return `₱${fmt(m)} – ₱${fmt(M)}`;
    return `₱${fmt(m)}`;
  };

  (async () => {
    try {
      if (!window.Store) {
        // fallback direct fetch
        const res = await fetch('../api/properties.php');
        if (!res.ok) throw new Error();
        var props = (await res.json()).map(p => ({...p, photo: p.photo ? '../'+p.photo : p.photo, photos: (p.photos||[]).map(x=>'../'+x), landlordPhoto: p.landlordPhoto ? '../'+p.landlordPhoto : p.landlordPhoto, rooms: (p.rooms||[]).map(r => ({...r, photo: r.photo ? '../'+r.photo : r.photo, photos: (r.photos||[]).map(x=>'../'+x)}))}));
      } else {
        var props = await Store.all();
      }
      const p = props.find(x => String(x.id) === String(pid));
      const r = p && (p.rooms || []).find(x => String(x.id) === String(rid));
      if (!r) return;
      const photos = r.photos && r.photos.length ? r.photos : (r.photo ? [r.photo] : []);
      const amenities = (r.amenities || []).length ? `<ul class="room-detail-amen">${r.amenities.map(a => `<li>${esc(a)}</li>`).join('')}</ul>` : '';
      body.innerHTML = `
        ${photos.length ? `<img class="room-detail-photo" src="${esc(photos[0])}" alt="">` : `<span class="room-detail-ph" aria-hidden="true"><svg><use href="#bed-icon"/></svg></span>`}
        <h3>${esc(r.name)}</h3>
        <p class="room-detail-meta">${esc(p.name)} · ${esc(p.university)}</p>
        <p>${esc(r.type)} · ${r.occupants}/${r.capacity} occupied</p>
        <p><strong>${price(r.rent, r.rentMax)}</strong> / month</p>
        ${Number(r.deposit)>0 ? `<p>Deposit: ${price(r.deposit, r.depositMax)}</p>` : ''}
        <p class="room-detail-status">${esc(r.status)}</p>
        ${amenities}`;
      if (typeof dialog.showModal === 'function') dialog.showModal();
    } catch (e) {}
  })();

  // close handlers
  dialog.querySelectorAll('[data-close]').forEach(btn => {
    btn.addEventListener('click', () => dialog.close());
  });
  dialog.addEventListener('click', (e) => {
    if (e.target === dialog) dialog.close();
  });
})();