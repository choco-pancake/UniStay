async function initDormMarkers(map, onSelect, universityFilter = '') {
  const layer = L.layerGroup().addTo(map);
  let properties = await Store.all();

  // Draws the pins for one university (or all) and returns the list that is now visible.
  const draw = (uni = universityFilter) => {
    layer.clearLayers();
    const list = properties.filter(p => !uni || p.university === uni);
    list.forEach(p => {
      // Leaflet treats string tooltips as HTML, so pass a text node-based element
      // to keep landlord-entered names from injecting markup.
      const tip = document.createElement('span');
      tip.textContent = p.name;
      L.marker([p.lat, p.lng]).bindTooltip(tip).on('click', () => onSelect(p)).addTo(layer);
    });
    return list;
  };
  draw();

  return {
    get properties() { return properties; },
    show: (uni) => draw(uni),                                          // redraw from data already loaded
    refresh: async (uni) => { properties = await Store.all(); return draw(uni); }  // re-fetch from the API
  };
}