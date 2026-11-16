async function initDormMarkers(map, onSelect, universityFilter = '') {
  const layer = L.layerGroup().addTo(map);
  let properties = await Store.all();

  // Draws the pins for one university (or all) + an optional dorm-name query,
  // and returns the list that is now visible.
  const draw = (uni = universityFilter, query = '') => {
    layer.clearLayers();
    const q = String(query || '').trim().toLowerCase();
    const selectedCampus = (window.UNISTAY_TENANT_UNIVERSITIES || []).find(campus => campus.name === uni);
    const list = properties.filter(p =>
      (!uni || (selectedCampus && window.TenantMapSuggestions
        ? TenantMapSuggestions.validLocation(p) && TenantMapSuggestions.distance(p, selectedCampus) <= 1 + 1e-9
        : p.university === uni)) &&
      (!q || String(p.name || '').toLowerCase().includes(q) || String(p.address || '').toLowerCase().includes(q)));
    list.filter(p => window.TenantMapSuggestions ? TenantMapSuggestions.validLocation(p) : Number.isFinite(Number(p.lat)) && Number.isFinite(Number(p.lng))).forEach(p => {
      // Default Leaflet marker (same as the landlord map). Tooltip text is passed as an
      // element so landlord-entered names can't inject markup (Leaflet treats strings as HTML).
      const tip = document.createElement('span');
      tip.textContent = p.name;
      L.marker([Number(p.lat), Number(p.lng)], {bubblingMouseEvents:false}).bindTooltip(tip).on('click', () => onSelect(p)).addTo(layer);
    });
    return list;
  };
  draw();

  return {
    get properties() { return properties; },
    show: (uni, query) => draw(uni, query),                                          // redraw from data already loaded
    refresh: async (uni, query) => { properties = await Store.all(); return draw(uni, query); }  // re-fetch from the API
  };
}
