<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Add Property | Landlord</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <style>
    /* Drag & drop highlight for photo zones */
    .dz-over { border-color: #6366f1 !important; background-color: #eef2ff; }
    /* Floating property panel: smooth fade + scale in/out */
    #propModal { opacity: 0; transition: opacity .2s ease; }
    #propModal.is-open { opacity: 1; }
    #propModalCard { transform: scale(.95); opacity: 0; transition: transform .2s ease, opacity .2s ease; }
    #propModal.is-open #propModalCard { transform: scale(1); opacity: 1; }
    @media (prefers-reduced-motion: reduce) {
      #propModal, #propModalCard { transition: none !important; }
      #propModalCard { transform: none !important; }
    }

    .um-map .leaflet-tile-pane { filter: saturate(1.6) contrast(1.05); }
    .um-map .leaflet-bar { border-radius: 9999px; overflow: hidden; }

    .um-map, .um-map * { cursor: default; }                          /* arrow by default */
    .um-map.um-pick, .um-map.um-pick * { cursor: pointer; }          /* placing a pin / picking a university */
    .um-map .leaflet-interactive { cursor: pointer; }                /* pins and university dots */
    .um-map.um-zoom-in,  .um-map.um-zoom-in *  { cursor: zoom-in; }  /* magnifier + */
    .um-map.um-zoom-out, .um-map.um-zoom-out * { cursor: zoom-out; } /* magnifier − */
    .um-map .leaflet-control, .um-map .leaflet-control *, .um-map .leaflet-popup button { cursor: pointer; }
    body.leaflet-dragging .um-map, body.leaflet-dragging .um-map * { cursor: grabbing; }
  </style>
</head>
<body class="bg-slate-50 text-slate-800 flex min-h-screen">

  <!-- Collapsible sidebar -->
  <aside id="sidebar" class="w-60 bg-white border-r flex flex-col transition-all duration-200 shrink-0">
    <div class="flex items-center gap-2 p-4 border-b">
      <div class="w-8 h-8 rounded-lg bg-indigo-600 text-white grid place-items-center font-bold">D</div>
      <span class="sb-label font-semibold">DormFinder</span>
      <button id="toggleSb" class="ml-auto text-slate-400 hover:text-slate-700" aria-label="Toggle sidebar">☰</button>
    </div>
    <nav class="p-3 space-y-1 text-sm">
      <a class="flex items-center gap-3 px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700 font-medium" href="properties.php">🏠 <span class="sb-label">Properties</span></a>
      <a class="flex items-center gap-3 px-3 py-2 rounded-lg text-slate-600 hover:bg-slate-100 font-medium" href="rooms.php">🚪 <span class="sb-label">Rooms</span></a>
    </nav>
  </aside>

  <div class="flex-1 min-w-0">
    <!-- Header -->
    <header class="bg-white border-b px-6 py-3 flex items-center justify-end gap-4">
      <button class="relative text-xl" aria-label="Notifications">🔔</button>
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-full bg-indigo-100 text-indigo-700 grid place-items-center font-semibold" id="avatar"></div>
        <div class="text-sm leading-tight"><p class="font-medium" id="hName"></p><p class="text-slate-500" id="hRole"></p></div>
      </div>
    </header>

    <main class="p-6 max-w-5xl mx-auto space-y-8">
      <div>
        <h1 class="text-2xl font-semibold">Add a Property</h1>
        <p class="text-slate-500 text-sm">Once saved, your dorm appears as a pin on the tenant map.</p>
      </div>

      <form id="propForm" class="space-y-6" novalidate>
        <!-- Dorm details -->
        <section class="bg-white rounded-xl border p-5 grid md:grid-cols-2 gap-4">
          <h2 class="md:col-span-2 font-medium">Dorm details</h2>
          <label class="text-sm">Dorm name *<input name="name" required class="mt-1 w-full border rounded-lg px-3 py-2" placeholder="e.g. Sunrise Residences"></label>
          <div class="space-y-2">
            <div>
              <p class="text-sm mb-1">Near which university *</p>
              <div class="inline-flex rounded-lg border overflow-hidden text-sm" role="radiogroup" aria-label="University input method">
                <label class="px-4 py-2 cursor-pointer has-[:checked]:bg-indigo-600 has-[:checked]:text-white">
                  <input type="radio" name="uniMode" value="list" class="sr-only" checked> ✏️ Choose manually
                </label>
                <label class="px-4 py-2 cursor-pointer border-l has-[:checked]:bg-indigo-600 has-[:checked]:text-white">
                  <input type="radio" name="uniMode" value="map" class="sr-only"> 🗺️ Pick on the map
                </label>
              </div>
            </div>
            <select id="university" name="university" required class="w-full border rounded-lg px-3 py-2 bg-white">
              <option id="uniPlaceholder" value="">Select nearest university</option><option>Universidad de Dagupan</option><option>University of Pangasinan</option><option>University of Luzon</option><option>Lyceum Northwestern University</option>
            </select>
            <p id="uniHint" class="text-xs text-slate-500">Pick a university from the list, or set the location and the nearest one will be selected automatically.</p>
          </div>

          <!-- Address + location (choose one way to set it) -->
          <div class="md:col-span-2 space-y-3">
            <div>
              <p class="text-sm mb-1">How do you want to set the location? *</p>
              <div class="inline-flex rounded-lg border overflow-hidden text-sm" role="radiogroup" aria-label="Location input method">
                <label class="px-4 py-2 cursor-pointer has-[:checked]:bg-indigo-600 has-[:checked]:text-white">
                  <input type="radio" name="locMode" value="type" class="sr-only" checked> ⌨️ Type the address
                </label>
                <label class="px-4 py-2 cursor-pointer border-l has-[:checked]:bg-indigo-600 has-[:checked]:text-white">
                  <input type="radio" name="locMode" value="map" class="sr-only"> 📍 Pin on the map
                </label>
              </div>
            </div>

            <label class="text-sm block">Address / location *
              <input id="addressInput" name="address" required autocomplete="off"
                     class="mt-1 w-full border rounded-lg px-3 py-2 read-only:bg-slate-100 read-only:text-slate-600"
                     placeholder="Street, Barangay, City  (e.g. 123 Rizal St, Poblacion Oeste, Dagupan City)">
            </label>
            <p id="addrHint" class="text-xs text-slate-500">Required format: <b>Street, Barangay, City</b>. The pin will be placed on the map automatically.</p>

            <!-- Map directly below the address -->
            <div>
              <div id="pickMap" class="um-map h-72 rounded-2xl z-0"></div>
            </div>
          </div>

          <label class="text-sm md:col-span-2">Description<textarea name="description" rows="3" class="mt-1 w-full border rounded-lg px-3 py-2"></textarea></label>
          <div class="md:col-span-2">
            <p class="text-sm mb-1">Dorm photos * <span class="text-slate-500">(at least 3 — drag &amp; drop or click to browse)</span></p>
            <div id="photoZone" class="border-2 border-dashed border-slate-300 rounded-xl px-4 py-7 text-center cursor-pointer transition hover:border-indigo-400 hover:bg-indigo-50/40">
              <div class="text-3xl">📷</div>
              <p class="text-sm font-medium text-slate-600 mt-1">Drag &amp; drop photos here</p>
              <p class="text-xs text-slate-500 mt-0.5">JPG, PNG or WebP, up to 5 MB each</p>
              <input id="photoInput" type="file" accept="image/*" multiple class="hidden">
            </div>
            <p id="photoCount" class="hidden mt-2 text-xs text-slate-500"></p>
            <div id="photoPreview" class="hidden mt-3 flex flex-wrap gap-2"></div>
          </div>
        </section>

        <!-- Rooms -->
        <section class="bg-white rounded-xl border p-5 space-y-4">
          <h2 class="font-medium">Rooms</h2>
          <div id="rooms" class="space-y-4"></div>
          <div class="flex justify-end">
            <button type="button" id="addRoom" class="text-sm px-4 py-1.5 rounded-full bg-indigo-600 text-white hover:bg-indigo-700">+ Add another room</button>
          </div>
        </section>

        <p id="error" class="text-sm text-red-600 hidden"></p>
        <button class="px-6 py-2.5 rounded-full bg-indigo-600 text-white font-medium hover:bg-indigo-700">Save property</button>
      </form>

      <!-- My properties -->
      <section>
        <h2 class="font-medium mb-3">My properties</h2>
        <div id="myProps" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4"></div>
      </section>
    </main>
  </div>

  <!-- Floating property detail panel (opened by clicking a "My properties" card) -->
  <div id="propModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">
    <div id="propModalBackdrop" class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm"></div>
    <div id="propModalCard" class="relative bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto"></div>
  </div>

  <div id="toast" class="fixed bottom-6 right-6 bg-slate-900 text-white text-sm px-4 py-2 rounded-lg hidden">Property saved!</div>

  <script src="../assets/js/store.js"></script>
  <script src="../assets/js/landlord-properties.js"></script>
</body>
</html>