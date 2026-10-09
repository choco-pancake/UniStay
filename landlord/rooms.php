<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Manage Rooms | Landlord</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    /* Drag & drop highlight for the photo zone */
    .dz-over { border-color: #6366f1 !important; background-color: #eef2ff; }
  </style>
</head>
<body class="bg-slate-50 text-slate-800 flex min-h-screen">

  <aside id="sidebar" class="w-60 bg-white border-r flex flex-col transition-all duration-200 shrink-0">
    <div class="flex items-center gap-2 p-4 border-b">
      <div class="w-8 h-8 rounded-lg bg-indigo-600 text-white grid place-items-center font-bold">D</div>
      <span class="sb-label font-semibold">DormFinder</span>
      <button id="toggleSb" class="ml-auto text-slate-400 hover:text-slate-700" aria-label="Toggle sidebar">☰</button>
    </div>
    <nav class="p-3 space-y-1 text-sm">
      <a class="flex items-center gap-3 px-3 py-2 rounded-lg text-slate-600 hover:bg-slate-100 font-medium" href="properties.php">🏠 <span class="sb-label">Properties</span></a>
      <a class="flex items-center gap-3 px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700 font-medium" href="rooms.php">🚪 <span class="sb-label">Rooms</span></a>
    </nav>
  </aside>

  <div class="flex-1 min-w-0">
    <header class="bg-white border-b px-6 py-3 flex items-center justify-between">
      <h2 class="font-semibold text-lg">Room Inventory</h2>
      <div class="flex items-center gap-4">
        <button class="text-xl" aria-label="Notifications">🔔</button>
        <div class="w-9 h-9 rounded-full bg-indigo-100 text-indigo-700 grid place-items-center font-semibold" id="avatar"></div>
        <div class="text-sm leading-tight"><p class="font-medium" id="hName"></p><p class="text-slate-500" id="hRole"></p></div>
      </div>
    </header>

    <main class="p-6 max-w-6xl mx-auto space-y-8">
      <div id="noProps" class="hidden p-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg text-sm">
        You need a property first. <a href="properties.php" class="underline font-medium">Add a property</a> to start adding rooms.
      </div>

      <section class="bg-white border rounded-xl p-6">
        <h2 class="text-lg font-semibold mb-1">Add a Room</h2>
        <p class="text-sm text-slate-500 mb-5">Rooms are saved to the selected property and show up in the tenant map.</p>
        <form id="roomForm" class="space-y-4" novalidate>
          <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <label class="text-sm">Property *<select name="property" required class="mt-1 w-full border rounded-lg px-3 py-2 bg-white"></select></label>
            <label class="text-sm">Room name / number *<input name="name" required class="mt-1 w-full border rounded-lg px-3 py-2" placeholder="e.g. Room 302-B"></label>
            <label class="text-sm">Room type *
              <select name="type" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white"><option>SINGLE</option><option>TWIN</option><option>QUAD</option><option>QUINTUPLE</option><option>SEXTUPLE</option><option>OCTUPLE</option><option>DECUPLE</option></select></label>
            <div class="text-sm" data-price="rent" data-mode="fixed">
              <div class="flex items-center justify-between gap-2 mb-1">
                <span>Monthly payment (₱) *</span>
                <span class="inline-flex rounded-md border overflow-hidden text-xs shrink-0">
                  <button type="button" data-v="fixed" class="px-2 py-0.5 bg-indigo-600 text-white">Fixed</button>
                  <button type="button" data-v="range" class="px-2 py-0.5 border-l text-slate-600 hover:bg-slate-50">Range</button>
                </span>
              </div>
              <div data-fixed><input name="rent" type="number" min="1" step="0.01" class="w-full border rounded-lg px-3 py-2" placeholder="3500"></div>
              <div data-range class="hidden grid grid-cols-2 gap-2">
                <input name="rentMin" type="number" min="1" step="0.01" class="w-full border rounded-lg px-3 py-2" placeholder="Min">
                <input name="rentMax" type="number" min="1" step="0.01" class="w-full border rounded-lg px-3 py-2" placeholder="Max">
              </div>
            </div>
            <div class="text-sm" data-price="deposit" data-mode="fixed">
              <div class="flex items-center justify-between gap-2 mb-1">
                <span>Deposit (₱)</span>
                <span class="inline-flex rounded-md border overflow-hidden text-xs shrink-0">
                  <button type="button" data-v="fixed" class="px-2 py-0.5 bg-indigo-600 text-white">Fixed</button>
                  <button type="button" data-v="range" class="px-2 py-0.5 border-l text-slate-600 hover:bg-slate-50">Range</button>
                </span>
              </div>
              <div data-fixed><input name="deposit" type="number" min="0" value="0" class="w-full border rounded-lg px-3 py-2"></div>
              <div data-range class="hidden grid grid-cols-2 gap-2">
                <input name="depositMin" type="number" min="0" class="w-full border rounded-lg px-3 py-2" placeholder="Min">
                <input name="depositMax" type="number" min="0" class="w-full border rounded-lg px-3 py-2" placeholder="Max">
              </div>
            </div>
            <label class="text-sm">Current occupants<input name="occupants" type="number" min="0" value="0" class="mt-1 w-full border rounded-lg px-3 py-2"></label>
            <label class="text-sm">Status
              <select name="status" class="mt-1 w-full border rounded-lg px-3 py-2 bg-white"><option>Available</option><option>Occupied</option><option>Under Maintenance</option></select></label>
            <div class="text-sm">
              <span class="mb-1 block">Room photo</span>
              <div id="photoZone" class="border-2 border-dashed border-slate-300 rounded-lg px-3 py-3 text-center cursor-pointer text-xs text-slate-500 transition hover:border-indigo-400 hover:bg-indigo-50/40">Drag &amp; drop or click to browse
                <input name="photo" type="file" accept="image/*" class="hidden">
              </div>
              <div id="photoPreview" class="hidden relative w-28 mt-2">
                <img class="w-28 h-24 rounded-lg object-cover" alt="Room photo preview">
                <button type="button" data-rm class="absolute -top-1.5 -right-1.5 w-5 h-5 rounded-full bg-slate-900/70 text-white text-xs leading-none hover:bg-slate-900" aria-label="Remove photo">✕</button>
              </div>
            </div>
          </div>
          <div id="amenities" class="flex flex-wrap gap-3 text-sm"></div>
          <p id="error" class="text-sm text-rose-600 hidden"></p>
          <div class="flex justify-end"><button class="px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700">+ Save Room</button></div>
        </form>
      </section>

      <section class="space-y-4">
        <h2 class="text-xl font-semibold">Your Registered Rooms (<span id="count">0</span>)</h2>
        <div id="roomList" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5"></div>
      </section>
    </main>
  </div>

  <div id="toast" class="fixed bottom-6 right-6 bg-slate-900 text-white text-sm px-4 py-2 rounded-lg hidden"></div>

  <script src="../assets/js/store.js"></script>
  <script src="../assets/js/landlord-rooms.js"></script>
</body>
</html>