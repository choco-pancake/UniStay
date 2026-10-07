// Shared data layer. Same method names as before, but now every call is async and hits the PHP/MySQL API.
// Pages live in /landlord or /tenant, so the API is one level up.
const Store = (() => {
  const API = '../api/';
  const call = async (url, opts) => {
    const res = await fetch(API + url, opts);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
  };
  const fix = (p) => ({ ...p, photo: '../' + p.photo });   // DB stores path from project root
  const json = (body) => ({ method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  return {
    all: async () => (await call('properties.php')).map(fix),
    byLandlord: async () => (await call('properties.php?mine=1')).map(fix),
    add: (formData) => call('properties.php', { method: 'POST', body: formData }),
    remove: (id) => call('properties.php?id=' + id, { method: 'DELETE' }),
    addRoom: (propertyId, room) => call('rooms.php', json({ property_id: propertyId, ...room })),
    removeRoom: (propertyId, roomId) => call('rooms.php?id=' + roomId, { method: 'DELETE' })
  };
})();
// Display-only placeholder until login exists (the server uses landlord_id() in api/db.php)
const CURRENT_LANDLORD = { id: 1, name: 'Juan Dela Cruz', role: 'Landlord' };