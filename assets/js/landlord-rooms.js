const $ = (s) => document.querySelector(s);
const AMENITIES = ['WiFi', 'Aircon', 'Private CR', 'Study desk', 'Laundry', 'Kitchen'];
const BADGE = { 'Available': 'bg-emerald-50 text-emerald-700 border-emerald-200', 'Occupied': 'bg-rose-50 text-rose-700 border-rose-200', 'Under Maintenance': 'bg-amber-50 text-amber-700 border-amber-200' };
const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// ---- Header + sidebar ----
$('#hName').textContent = CURRENT_LANDLORD.name;
$('#hRole').textContent = CURRENT_LANDLORD.role;
$('#avatar').textContent = CURRENT_LANDLORD.name[0];
$('#toggleSb').onclick = () => {
  const sb = $('#sidebar'), collapsed = sb.classList.toggle('w-16');
  sb.classList.toggle('w-60', !collapsed);
  document.querySelectorAll('.sb-label').forEach(e => e.classList.toggle('hidden', collapsed));
};

const toast = (m) => { const t = $('#toast'); t.textContent = m; t.classList.remove('hidden'); setTimeout(() => t.classList.add('hidden'), 2500); };
const showError = (m) => { $('#error').textContent = m; $('#error').classList.remove('hidden'); };

$('#amenities').innerHTML = AMENITIES.map(a => `<label class="flex items-center gap-1"><input type="checkbox" value="${a}">${a}</label>`).join('');

// ---- Property dropdown ----
async function loadProperties() {
  const props = await Store.byLandlord();
  $('#noProps').classList.toggle('hidden', props.length > 0);
  const sel = $('#roomForm [name=property]'), keep = sel.value;
  sel.innerHTML = props.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
  if (keep) sel.value = keep;
  return props;
}

// ---- Render rooms ----
async function render() {
  const props = await loadProperties();
  const rooms = props.flatMap(p => p.rooms.map(r => ({ ...r, propertyId: p.id, propertyName: p.name })));
  $('#count').textContent = rooms.length;
  $('#roomList').innerHTML = rooms.length ? rooms.map(r => {
    const status = r.status || 'Available';
    return `<div class="bg-white border rounded-xl p-5 flex flex-col justify-between">
      <div>
        <div class="flex justify-between items-start gap-2 mb-2">
          <div><h3 class="font-semibold text-lg">${esc(r.name)}</h3><p class="text-xs text-slate-500">${esc(r.propertyName)}</p></div>
          <span class="text-xs px-2.5 py-0.5 rounded-full font-medium border ${BADGE[status] || ''}">${esc(status)}</span>
        </div>
        <div class="text-sm space-y-1 my-3 text-slate-600">
          <p><b class="text-slate-700">Type:</b> ${esc(r.type)}</p>
          <p><b class="text-slate-700">Occupants:</b> ${r.occupants || 0} / ${r.capacity}</p>
          <p><b class="text-slate-700">Rent:</b> ₱${Number(r.rent).toLocaleString('en-PH', { minimumFractionDigits: 2 })} / mo</p>
          <p><b class="text-slate-700">Deposit:</b> ₱${Number(r.deposit || 0).toLocaleString('en-PH')}</p>
        </div>
        ${r.amenities?.length ? `<div class="flex flex-wrap gap-1.5 pt-2 border-t">${r.amenities.map(a => `<span class="text-[11px] bg-slate-100 text-slate-600 px-2 py-0.5 rounded-md font-medium">${esc(a)}</span>`).join('')}</div>` : ''}
      </div>
      <div class="mt-4 pt-3 border-t flex justify-end">
        <button data-p="${r.propertyId}" data-r="${r.id}" class="del text-xs text-rose-600 hover:bg-rose-50 font-medium px-2 py-1 rounded">Delete Room</button>
      </div></div>`;
  }).join('') : '<div class="sm:col-span-2 lg:col-span-3 p-8 text-center bg-white border border-dashed rounded-xl text-slate-400">No rooms yet. Use the form above to add one.</div>';
}

$('#roomList').onclick = async (e) => {
  const b = e.target.closest('.del');
  if (b && confirm('Remove this room?')) {
    try { await Store.removeRoom(b.dataset.p, b.dataset.r); } catch (err) { return toast(err.message); }
    await render(); toast('Room removed.');
  }
};

// ---- Submit ----
$('#roomForm').onsubmit = async (e) => {
  e.preventDefault();
  const f = new FormData(e.target);
  const room = {
    name: f.get('name').trim(), type: f.get('type'), capacity: +f.get('capacity'), rent: +f.get('rent'),
    deposit: +f.get('deposit') || 0, occupants: +f.get('occupants') || 0, status: f.get('status'),
    amenities: [...e.target.querySelectorAll('#amenities input:checked')].map(c => c.value)
  };
  if (!f.get('property')) return showError('Add a property first.');
  if (!room.name || !(room.rent > 0) || room.capacity < 1) return showError('Please fill in the room name, rent, and capacity.');
  if (room.occupants > room.capacity) return showError('Current occupants cannot exceed max occupants.');
  try { await Store.addRoom(f.get('property'), room); } catch (err) { return showError(err.message); }
  $('#error').classList.add('hidden');
  e.target.reset(); await render(); toast(`Room '${room.name}' added!`);
};

render();