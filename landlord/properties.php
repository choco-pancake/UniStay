<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Add Property | Landlord</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
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
          <label class="text-sm">Near which university *
            <select name="university" required class="mt-1 w-full border rounded-lg px-3 py-2">
              <option value="">Select…</option><option>Universidad de Dagupan</option><option>University of Pangasinan</option><option>University of Luzon</option><option>Lyceum Northwestern University</option><option>Other</option>
            </select></label>
          <label class="text-sm md:col-span-2">Address / location *<input name="address" required class="mt-1 w-full border rounded-lg px-3 py-2" placeholder="Street, barangay, city"></label>
          <label class="text-sm md:col-span-2">Description<textarea name="description" rows="3" class="mt-1 w-full border rounded-lg px-3 py-2"></textarea></label>
          <div class="md:col-span-2">
            <p class="text-sm mb-1">Dorm photo *</p>
            <input id="photoInput" type="file" accept="image/*" class="text-sm">
            <img id="photoPreview" class="hidden mt-3 h-40 rounded-lg object-cover" alt="Preview">
          </div>
        </section>

        <!-- Map pin -->
        <section class="bg-white rounded-xl border p-5">
          <h2 class="font-medium mb-1">Pin location *</h2>
          <p class="text-sm text-slate-500 mb-3">Click the map to drop your property's pin.</p>
          <div id="pickMap" class="h-72 rounded-lg z-0"></div>
          <p id="coords" class="text-xs text-slate-500 mt-2">No location selected.</p>
        </section>

        <!-- Rooms -->
        <section class="bg-white rounded-xl border p-5 space-y-4">
          <div class="flex items-center justify-between">
            <h2 class="font-medium">Rooms</h2>
            <button type="button" id="addRoom" class="text-sm px-3 py-1.5 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">+ Add room</button>
          </div>
          <div id="rooms" class="space-y-4"></div>
        </section>

        <p id="error" class="text-sm text-red-600 hidden"></p>
        <button class="px-5 py-2.5 rounded-lg bg-indigo-600 text-white font-medium hover:bg-indigo-700">Save property</button>
      </form>

      <!-- My properties -->
      <section>
        <h2 class="font-medium mb-3">My properties</h2>
        <div id="myProps" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4"></div>
      </section>
    </main>
  </div>

  <div id="toast" class="fixed bottom-6 right-6 bg-slate-900 text-white text-sm px-4 py-2 rounded-lg hidden">Property saved!</div>

  <script src="../assets/js/store.js"></script>
  <script src="../assets/js/landlord-properties.js"></script>
</body>
</html>