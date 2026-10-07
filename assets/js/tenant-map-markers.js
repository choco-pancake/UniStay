// Tenant map: load after store.js and Leaflet.
//   const map = L.map('map').setView([18.1978, 120.5936], 12);
//   const markers = await initDormMarkers(map, (property) => openDetailsPanel(property));
//   markers.refresh('Mariano Marcos State University');   // from your filter dropdown
async function initDormMarkers(map, onSelect, universityFilter = '') {
  const layer = L.layerGroup().addTo(map);
  let properties = await Store.all();
  const draw = (uni = universityFilter) => {
    layer.clearLayers();
    properties.filter(p => !uni || p.university === uni)
      .forEach(p => L.marker([p.lat, p.lng]).bindTooltip(p.name).on('click', () => onSelect(p)).addTo(layer));
  };
  draw();
  return { refresh: async (uni) => { properties = await Store.all(); draw(uni); } };
}