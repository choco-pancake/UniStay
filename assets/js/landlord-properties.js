const $ = (s) => document.querySelector(s);
const AMENITIES = ['WiFi', 'Aircon', 'Private CR', 'Study desk', 'Laundry', 'Kitchen'];
let photoFile = null, pin = null;

// ---- Header + sidebar ----
$('#hName').textContent = CURRENT_LANDLORD.name;
$('#hRole').textContent = CURRENT_LANDLORD.role;
$('#avatar').textContent = CURRENT_LANDLORD.name[0];
$('#toggleSb').onclick = () => {
  const sb = $('#sidebar'), collapsed = sb.classList.toggle('w-16');
  sb.classList.toggle('w-60', !collapsed);
  document.querySelectorAll('.sb-label').forEach(e => e.classList.toggle('hidden', collapsed));
};

// ---- Map picker ----
const map = L.map('pickMap').setView([18.1978, 120.5936], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);
let marker;
map.on('click', (e) => {
  pin = { lat: +e.latlng.lat.toFixed(6), lng: +e.latlng.lng.toFixed(6) };
  marker ? marker.setLatLng(e.latlng) : (marker = L.marker(e.latlng).addTo(map));
  $('#coords').textContent = `Selected: ${pin.lat}, ${pin.lng}`;
});

// ---- Photo (sent to PHP as a real file upload) ----
$('#photoInput').onchange = (e) => {
  photoFile = e.target.files[0] || null;
  if (!photoFile) return;
  $('#photoPreview').src = URL.createObjectURL(photoFile);
  $('#photoPreview').classList.remove('hidden');
};

// ---- Rooms ----
const inp = 'mt-1 w-full border rounded-lg px-3 py-2';
function addRoom() {
  const d = document.createElement('div');
  d.className = 'room border rounded-lg p-4 grid md:grid-cols-3 gap-3 relative';
  d.innerHTML = `
    <label class="text-sm">Room name *<input data-f="name" class="${inp}" placeholder="Room 101"></label>
    <label class="text-sm">Room type *<select data-f="type" class="${inp}"><option>Single</option><option>Double</option><option>Quad</option><option>Bedspace</option></select></label>
    <label class="text-sm">Max occupants *<input data-f="capacity" type="number" min="1" value="1" class="${inp}"></label>
    <label class="text-sm">Monthly payment (₱) *<input data-f="rent" type="number" min="0" class="${inp}"></label>
    <label class="text-sm">Deposit (₱)<input data-f="deposit" type="number" min="0" value="0" class="${inp}"></label>
    <button type="button" class="rm text-sm text-red-600 self-end text-left pb-2">Remove room</button>
    <div class="md:col-span-3 flex flex-wrap gap-3 text-sm">${AMENITIES.map(a => `<label class="flex items-center gap-1"><input type="checkbox" value="${a}">${a}</label>`).join('')}</div>`;
  d.querySelector('.rm').onclick = () => document.querySelectorAll('.room').length > 1 && d.remove();
  $('#rooms').appendChild(d);
}
$('#addRoom').onclick = addRoom;
addRoom();

// ---- Submit ----
const esc = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const showError = (m) => { $('#error').textContent = m; $('#error').classList.remove('hidden'); };
$('#propForm').onsubmit = async (e) => {
  e.preventDefault();
  const f = new FormData(e.target);   // name, university, address, description
  const rooms = [...document.querySelectorAll('.room')].map((r) => {
    const g = (k) => r.querySelector(`[data-f="${k}"]`).value.trim();
    return { name: g('name'), type: g('type'), capacity: +g('capacity'), rent: +g('rent'), deposit: +g('deposit') || 0, occupants: 0,
             status: 'Available', amenities: [...r.querySelectorAll('input[type=checkbox]:checked')].map(c => c.value) };
  });
  if (!f.get('name').trim() || !f.get('university') || !f.get('address').trim()) return showError('Please fill in all required dorm details.');
  if (!photoFile) return showError('Please upload a dorm photo.');
  if (!pin) return showError('Please click the map to pin your property.');
  if (rooms.some(r => !r.name || !r.rent || r.capacity < 1)) return showError('Each room needs a name, rent, and capacity.');

  f.append('photo', photoFile); f.append('lat', pin.lat); f.append('lng', pin.lng); f.append('rooms', JSON.stringify(rooms));
  try { await Store.add(f); } catch (err) { return showError(err.message); }

  $('#error').classList.add('hidden');
  e.target.reset(); $('#rooms').innerHTML = ''; addRoom();
  photoFile = null; pin = null; marker && (map.removeLayer(marker), marker = null);
  $('#photoPreview').classList.add('hidden'); $('#coords').textContent = 'No location selected.';
  $('#toast').classList.remove('hidden'); setTimeout(() => $('#toast').classList.add('hidden'), 2500);
  renderMine();
};

// ---- My properties list ----
async function renderMine() {
  let list = [];
  try { list = await Store.byLandlord(); } catch (err) { showError(err.message); }
  $('#myProps').innerHTML = list.length ? list.map(p => `
    <div class="bg-white border rounded-xl overflow-hidden">
      <img src="${esc(p.photo)}" class="h-32 w-full object-cover" alt="">
      <div class="p-4 text-sm">
        <p class="font-medium">${esc(p.name)}</p><p class="text-slate-500">${esc(p.address)}</p>
        <p class="text-slate-500">Near ${esc(p.university)} · ${p.rooms.length} room(s)</p>
        <button data-id="${p.id}" class="del mt-2 text-red-600">Delete</button>
      </div></div>`).join('') : '<p class="text-sm text-slate-500">No properties yet.</p>';
  document.querySelectorAll('.del').forEach(b => b.onclick = async () => {
    if (!confirm('Delete this property and all its rooms?')) return;
    try { await Store.remove(b.dataset.id); } catch (err) { return showError(err.message); }
    renderMine();
  });
}
renderMine();