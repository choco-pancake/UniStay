<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>UniStay - Tenants (UI Preview)</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  :root{
    --brown-900:#3f302b;
    --brown-800:#5c3327;
    --brown-700:#7a4b3a;
    --border:#eadfd4;
    --bg:#fdf9f3;
    --card:#ffffff;
    --muted:#8b7a70;
    --green:#2f7d5d;   --green-bg:#e8f5ee;
    --amber:#a86b1f;   --amber-bg:#fdf3e2;
    --rose:#a8482f;    --rose-bg:#fdeee9;
  }
  *{box-sizing:border-box;}
  body{
    margin:0;
    font-family:'Poppins',"Segoe UI",Arial,sans-serif;
    background:var(--bg);
    color:var(--brown-900);
    -webkit-font-smoothing:antialiased;
  }
  a{color:var(--brown-700);text-decoration:none;}

  /* ---------- LAYOUT ---------- */
  .app-container{display:flex;min-height:100vh;}

  /* ---------- SIDEBAR ---------- */
  .sidebar{
    width:252px;flex:0 0 252px;
    background:#fff;border-right:1px solid var(--border);
    padding:22px 16px;display:flex;flex-direction:column;gap:6px;
    position:sticky;top:0;height:100vh;
  }
  .logo{display:flex;align-items:center;gap:10px;padding:0 8px 22px;}
  .logo-icon{
    width:38px;height:38px;border-radius:11px;background:#fbf4ee;color:var(--brown-700);
    display:grid;place-items:center;font-size:16px;flex-shrink:0;
  }
  .logo h2{font-family:'Playfair Display',serif;font-size:19px;margin:0;color:var(--brown-800);letter-spacing:.2px;}
  .nav-menu{display:flex;flex-direction:column;gap:4px;}
  .nav-item{
    display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:10px;
    color:#6b584d;font-size:13.5px;font-weight:500;transition:background .18s,color .18s;
  }
  .nav-item i{width:18px;text-align:center;font-size:14px;}
  .nav-item:hover{background:#fbf8f5;color:var(--brown-800);}
  .nav-item.active{background:#fbf4ee;color:var(--brown-800);font-weight:600;}
  .bottom-nav{margin-top:auto;border-top:1px solid var(--border);padding-top:12px;}
  .nav-item.logout:hover{background:#fdeee9;color:var(--rose);}

  /* ---------- MAIN ---------- */
  .main-content{flex:1;min-width:0;padding:26px clamp(18px,3vw,40px) 56px;}

  .top-header{
    display:flex;align-items:flex-start;justify-content:space-between;
    gap:20px;flex-wrap:wrap;margin-bottom:24px;
  }
  .page-title h1{
    font-family:'Playfair Display',serif;font-size:27px;margin:0 0 4px;color:var(--brown-800);
  }
  .page-title p{margin:0;font-size:13.5px;color:var(--muted);}
  .header-actions{display:flex;align-items:center;gap:16px;}
  .btn-primary{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    padding:10px 20px;border-radius:10px;border:none;cursor:pointer;
    background:linear-gradient(135deg,#7a4b3a 0%,#633b2c 100%);
    color:#fff;font-family:inherit;font-size:13px;font-weight:600;
    box-shadow:0 4px 12px rgba(122,75,58,.25);transition:filter .18s;
  }
  .btn-primary:hover{filter:brightness(1.07);}

  /* ---------- TOOLBAR ---------- */
  .toolbar{
    background:var(--card);border:1px solid var(--border);border-radius:16px;
    padding:16px 18px;display:flex;align-items:center;justify-content:space-between;
    gap:16px;flex-wrap:wrap;
  }
  .filter-chips{display:flex;gap:8px;flex-wrap:wrap;}
  .chip{
    display:inline-flex;align-items:center;gap:8px;
    padding:8px 15px;border-radius:999px;cursor:pointer;
    border:1px solid var(--border);background:#fff;
    font-family:inherit;font-size:12.5px;font-weight:500;color:#6b584d;
    transition:all .18s ease;
  }
  .chip:hover{border-color:#c9b3a2;color:var(--brown-800);}
  .chip.is-active{
    background:linear-gradient(135deg,#7a4b3a 0%,#633b2c 100%);
    border-color:transparent;color:#fff;font-weight:600;
    box-shadow:0 4px 12px rgba(122,75,58,.22);
  }
  .chip-count{
    display:inline-grid;place-items:center;min-width:20px;height:20px;
    padding:0 6px;border-radius:999px;background:#f2e9e1;
    font-size:11px;font-weight:600;color:var(--brown-700);
  }
  .chip.is-active .chip-count{background:rgba(255,255,255,.22);color:#fff;}

  .toolbar-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
  .search-box{position:relative;}
  .search-box i{
    position:absolute;left:13px;top:50%;transform:translateY(-50%);
    font-size:12.5px;color:#b3a29a;pointer-events:none;
  }
  .search-box input{
    width:230px;padding:9px 14px 9px 34px;
    border:1px solid var(--border);border-radius:10px;background:#fdfbf9;
    font-family:inherit;font-size:13px;color:var(--brown-900);outline:none;
    transition:border-color .18s,box-shadow .18s;
  }
  .search-box input::placeholder{color:#b3a29a;}
  .search-box input:focus{border-color:#c9b3a2;box-shadow:0 0 0 3px rgba(122,75,58,.08);background:#fff;}

  .select-wrap select{
    padding:9px 32px 9px 13px;border:1px solid var(--border);border-radius:10px;
    background:#fdfbf9;font-family:inherit;font-size:13px;color:var(--brown-900);
    outline:none;cursor:pointer;appearance:none;
    background-image:url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%238b7a70' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 12px center;
  }
  .select-wrap select:focus{border-color:#c9b3a2;box-shadow:0 0 0 3px rgba(122,75,58,.08);background-color:#fff;}

  /* ---------- RESULT META ---------- */
  .result-meta{
    display:flex;align-items:center;justify-content:space-between;
    gap:12px;margin:22px 2px 12px;font-size:12.5px;color:var(--muted);
  }
  .link-btn{
    background:none;border:none;padding:0;cursor:pointer;
    font-family:inherit;font-size:12.5px;font-weight:600;color:var(--brown-700);
  }
  .link-btn:hover{text-decoration:underline;}

  /* ---------- TENANT LIST ---------- */
  .tenant-list{display:flex;flex-direction:column;gap:12px;}

  .tenant-card{
    display:flex;align-items:flex-start;gap:18px;
    background:var(--card);border:1px solid var(--border);border-radius:16px;
    padding:20px 22px;transition:box-shadow .2s ease,transform .2s ease,border-color .2s ease;
  }
  .tenant-card:hover{
    border-color:#e0cfc1;
    box-shadow:0 12px 28px rgba(60,30,20,.07);
    transform:translateY(-1px);
  }

  .tenant-avatar{
    width:52px;height:52px;flex-shrink:0;border-radius:14px;
    display:grid;place-items:center;
    background:#fbf4ee;color:var(--brown-700);
    font-size:15px;font-weight:600;letter-spacing:.5px;
  }

  .tenant-main{flex:1;min-width:0;}

  .tenant-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:3px;}
  .tenant-head h3{
    margin:0;font-size:15.5px;font-weight:600;color:var(--brown-900);
  }
  .badge{
    display:inline-flex;align-items:center;gap:6px;
    padding:3px 10px;border-radius:999px;
    font-size:10.5px;font-weight:600;letter-spacing:.3px;text-transform:uppercase;
  }
  .badge::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor;}
  .badge--present{background:var(--green-bg);color:var(--green);}
  .badge--leaving{background:var(--rose-bg);color:var(--rose);}
  .badge--upcoming{background:var(--amber-bg);color:var(--amber);}

  .tenant-sub{
    margin:0;font-size:12.5px;color:var(--muted);
    display:flex;align-items:center;gap:7px;flex-wrap:wrap;
  }
  .tenant-sub i{font-size:11.5px;color:#b3a29a;}
  .tenant-sub .dot{color:#cbbbb0;}

  .tenant-meta{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
    gap:12px 22px;margin-top:14px;padding-top:14px;
    border-top:1px dashed #eee3da;
  }
  .meta{display:flex;flex-direction:column;gap:2px;min-width:0;}
  .meta-label{
    font-size:10px;font-weight:600;letter-spacing:.6px;
    text-transform:uppercase;color:#b3a29a;
  }
  .meta-value{font-size:12.5px;font-weight:500;color:#5f4b41;}

  .tenant-actions{
    display:flex;flex-direction:column;gap:8px;flex-shrink:0;
    align-self:center;min-width:132px;
  }
  .btn-outline{
    display:inline-flex;align-items:center;justify-content:center;
    padding:8px 16px;border-radius:10px;cursor:pointer;
    border:1px solid #d8c3b4;background:#fff;
    font-family:inherit;font-size:12.5px;font-weight:600;color:#6b584d;
    transition:all .18s ease;white-space:nowrap;
  }
  .btn-outline:hover{background:#fbf8f5;border-color:#bfa593;color:var(--brown-800);}

  /* ---------- EMPTY STATE ---------- */
  .empty-state{
    display:none;flex-direction:column;align-items:center;text-align:center;
    padding:64px 24px;background:var(--card);
    border:1px dashed #e2d3c6;border-radius:16px;
  }
  .empty-state.is-visible{display:flex;}
  .empty-icon{
    width:64px;height:64px;border-radius:18px;background:#fbf4ee;color:#c2a795;
    display:grid;place-items:center;font-size:24px;margin-bottom:16px;
  }
  .empty-state h3{margin:0 0 6px;font-size:15px;color:var(--brown-800);}
  .empty-state p{margin:0;font-size:13px;color:var(--muted);max-width:340px;line-height:1.5;}

  /* ---------- DRAWER ---------- */
  .drawer-overlay{
    position:fixed;inset:0;background:rgba(47,32,26,.38);
    opacity:0;pointer-events:none;transition:opacity .25s ease;z-index:90;
  }
  .drawer-overlay.is-open{opacity:1;pointer-events:auto;}

  .drawer{
    position:fixed;top:0;right:0;bottom:0;width:min(520px,100%);
    background:#fdfbf8;box-shadow:-18px 0 44px rgba(60,30,20,.14);
    transform:translateX(100%);transition:transform .32s cubic-bezier(.16,1,.3,1);
    z-index:100;display:flex;flex-direction:column;
  }
  .drawer.is-open{transform:translateX(0);}

  .drawer-header{
    display:flex;align-items:center;justify-content:space-between;
    padding:16px 22px;border-bottom:1px solid var(--border);
    flex-shrink:0;background:#fff;
  }
  .drawer-header h2{
    margin:0;font-family:'Playfair Display',serif;font-size:18px;color:var(--brown-800);
  }
  .drawer-close{
    background:none;border:none;font-size:26px;line-height:1;cursor:pointer;
    color:#9a8c84;padding:0 4px;transition:color .18s;border-radius:8px;
  }
  .drawer-close:hover{color:var(--brown-800);background:#fbf4ee;}

  .drawer-body{
    flex:1;overflow-y:auto;padding:20px 22px;
    display:flex;flex-direction:column;gap:14px;
  }

  /* --- Hero card --- */
  .drawer-hero{
    background:linear-gradient(135deg,#fbf4ee 0%,#fdfaf6 100%);
    border:1px solid #f0e5db;border-radius:16px;
    padding:18px 18px 16px;
  }
  .hero-top{display:flex;align-items:center;gap:14px;margin-bottom:14px;}
  .tenant-avatar.large{
    width:56px;height:56px;border-radius:16px;font-size:17px;
    background:#fff;border:1px solid #f0e2d6;
    box-shadow:0 2px 8px rgba(122,75,58,.08);
  }
  .hero-info h3{
    margin:0 0 6px;font-size:17px;font-weight:600;color:var(--brown-900);
  }
  .hero-meta{
    display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;
  }
  .hero-chip{
    display:inline-flex;align-items:center;gap:6px;
    padding:5px 11px;border-radius:999px;
    background:rgba(255,255,255,.85);border:1px solid #efe4da;
    font-size:11.5px;font-weight:500;color:#6b584d;
  }
  .hero-chip i{font-size:10.5px;color:#b3a29a;}

  .hero-stats{
    display:grid;grid-template-columns:repeat(3,1fr);
    gap:10px;padding-top:14px;border-top:1px dashed #e6d5c6;
  }
  .hero-stat{display:flex;flex-direction:column;gap:3px;min-width:0;}
  .hero-stat-label{
    font-size:9.5px;font-weight:600;letter-spacing:.6px;
    text-transform:uppercase;color:#b3a29a;
  }
  .hero-stat-value{
    font-size:12.5px;font-weight:600;color:var(--brown-900);
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
  }

  /* --- Detail sections --- */
  .detail-section{
    background:#fff;border:1px solid var(--border);border-radius:14px;
    padding:16px 18px;
  }
  .detail-section-head{
    display:flex;align-items:center;gap:10px;margin-bottom:14px;
  }
  .detail-section-icon{
    width:30px;height:30px;border-radius:9px;
    background:#fbf4ee;color:var(--brown-700);
    display:grid;place-items:center;font-size:12px;flex-shrink:0;
  }
  .detail-section-head h4{
    margin:0;font-size:13px;font-weight:600;color:#4d3a30;letter-spacing:.2px;
  }

  .detail-grid{
    display:grid;grid-template-columns:1fr 1fr;gap:14px 18px;
  }
  .detail-item{display:flex;flex-direction:column;gap:3px;min-width:0;}
  .detail-label{
    font-size:10px;font-weight:600;letter-spacing:.5px;
    text-transform:uppercase;color:#b3a29a;
  }
  .detail-value{
    font-size:12.5px;font-weight:500;color:#3f302b;
    word-break:break-word;line-height:1.35;
  }
  .detail-item.full{grid-column:1 / -1;}

  /* --- Footer actions --- */
  .drawer-footer{
    flex-shrink:0;display:flex;gap:10px;
    padding:16px 22px;border-top:1px solid var(--border);background:#fff;
  }
  .drawer-footer .btn-primary,
  .drawer-footer .btn-outline{flex:1;min-width:0;}

  /* ---------- RESPONSIVE ---------- */
  @media (max-width:900px){
    .app-container{flex-direction:column;}
    .sidebar{
      width:100%;flex:none;height:auto;position:static;
      flex-direction:row;align-items:center;gap:18px;
      overflow-x:auto;border-right:none;border-bottom:1px solid var(--border);
      padding:12px 16px;
    }
    .logo{padding:0;}
    .logo h2{display:none;}
    .nav-menu{flex-direction:row;gap:6px;}
    .nav-item{white-space:nowrap;padding:9px 12px;}
    .bottom-nav{margin:0;border:none;padding:0;}
    .main-content{padding:20px 16px 48px;}
  }
  @media (max-width:700px){
    .tenant-card{flex-wrap:wrap;padding:18px;}
    .tenant-actions{
      flex-direction:row;align-self:stretch;width:100%;min-width:0;
      border-top:1px dashed #eee3da;padding-top:14px;
    }
    .tenant-actions .btn-outline{flex:1;}
    .search-box input{width:100%;}
    .toolbar-right{width:100%;}
    .select-wrap,
    .select-wrap select{width:100%;}
  }
  @media (max-width:420px){
    .detail-grid{grid-template-columns:1fr;}
    .hero-stats{grid-template-columns:repeat(2,1fr);}
  }
</style>
</head>
<body>

<div class="app-container">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="logo">
      <div class="logo-icon"><i class="fas fa-university"></i></div>
      <h2>UniStay</h2>
    </div>

    <nav class="nav-menu">
      <a href="#" class="nav-item"><i class="fas fa-th-large"></i> Dashboard</a>
      <a href="#" class="nav-item"><i class="fas fa-home"></i> Properties</a>
      <a href="#" class="nav-item"><i class="fas fa-door-open"></i> Rooms</a>
      <a href="#" class="nav-item active"><i class="fas fa-users"></i> Tenants</a>
      <a href="#" class="nav-item"><i class="fas fa-map"></i> Map</a>
      <a href="#" class="nav-item"><i class="fas fa-cog"></i> Settings and profile</a>
    </nav>

    <div class="nav-menu bottom-nav">
      <a href="#" class="nav-item logout"><i class="fas fa-sign-out-alt"></i> Log out</a>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="main-content">

    <header class="top-header">
      <div class="page-title">
        <h1>Tenants</h1>
        <p>Manage the students currently staying in your properties.</p>
      </div>
      <div class="header-actions">
        <button type="button" class="btn-primary"><i class="fas fa-plus"></i> Add tenant</button>
      </div>
    </header>

    <!-- TOOLBAR -->
    <section class="toolbar">
      <div class="filter-chips" role="tablist" aria-label="Filter tenants by status">
        <button type="button" class="chip is-active" data-filter="all" role="tab" aria-selected="true">
          All <span class="chip-count" data-count="all">0</span>
        </button>
        <button type="button" class="chip" data-filter="present" role="tab" aria-selected="false">
          Present <span class="chip-count" data-count="present">0</span>
        </button>
        <button type="button" class="chip" data-filter="upcoming" role="tab" aria-selected="false">
          Upcoming <span class="chip-count" data-count="upcoming">0</span>
        </button>
        <button type="button" class="chip" data-filter="leaving" role="tab" aria-selected="false">
          Leaving <span class="chip-count" data-count="leaving">0</span>
        </button>
      </div>

      <div class="toolbar-right">
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input type="search" id="tenantSearch" placeholder="Search name, room, property..." autocomplete="off">
        </div>
        <div class="select-wrap">
          <select id="propertyFilter" aria-label="Filter by property">
            <option value="all">All properties</option>
            <option value="Sunrise Residences">Sunrise Residences</option>
            <option value="Greenview Dorm">Greenview Dorm</option>
            <option value="The Study House">The Study House</option>
          </select>
        </div>
      </div>
    </section>

    <!-- RESULT META -->
    <div class="result-meta">
      <span id="resultCount">Showing 0 tenants</span>
      <button type="button" class="link-btn" id="clearFilters">Clear filters</button>
    </div>

    <!-- LIST -->
    <section class="tenant-list" id="tenantList" aria-live="polite"></section>

    <!-- EMPTY STATE -->
    <div class="empty-state" id="emptyState">
      <div class="empty-icon"><i class="fas fa-user-slash"></i></div>
      <h3>No tenants found</h3>
      <p>Try a different filter or search term. Tenants you add will show up here.</p>
    </div>

  </main>
</div>

<!-- DRAWER -->
<div class="drawer-overlay" id="drawerOverlay"></div>
<aside class="drawer" id="tenantDrawer" aria-hidden="true">
  <div class="drawer-header">
    <h2>Tenant details</h2>
    <button type="button" class="drawer-close" id="drawerClose" aria-label="Close">&times;</button>
  </div>
  <div class="drawer-body" id="drawerBody"></div>
  <div class="drawer-footer">
    <button type="button" class="btn-primary">Renew lease</button>
    <button type="button" class="btn-outline">Mark as leaving</button>
  </div>
</aside>

<script>
(function () {
  'use strict';

  /* ---------------- MOCK DATA (UI ONLY) ---------------- */
  var tenants = [
    {
      name:'Alex Mendoza', room:'204', type:'Twin', property:'Sunrise Residences',
      status:'present', moveInOffset:-130, leaseEndOffset:120, phone:'+63 917 204 1188',
      email:'alex.mendoza@example.com', studentId:'2021-00123', school:'University of Luzon',
      course:'BS Computer Science', emergencyContactName:'Maria Mendoza',
      emergencyContactPhone:'+63 917 000 1111', monthlyRent:4500, depositAmount:9000,
      depositStatus:'Paid', leaseStatus:'Active', renewalStatus:'Not yet discussed',
      amenities:'Wi-Fi, Aircon, Study desk, Private CR', floor:'2nd Floor',
      buildingNotes:'Near fire exit, quiet side', capacity:2, occupancy:'2 of 2'
    },
    {
      name:'Sofia Lim', room:'105', type:'Single', property:'Greenview Dorm',
      status:'present', moveInOffset:-260, leaseEndOffset:95, phone:'+63 918 552 0043',
      email:'sofia.lim@example.com', studentId:'2020-00456', school:'University of Pangasinan',
      course:'BS Nursing', emergencyContactName:'Robert Lim',
      emergencyContactPhone:'+63 918 000 2222', monthlyRent:5500, depositAmount:11000,
      depositStatus:'Paid', leaseStatus:'Active', renewalStatus:'Renewal requested',
      amenities:'Wi-Fi, Aircon, Study desk, Private CR, Cabinet', floor:'1st Floor',
      buildingNotes:'Near lobby', capacity:1, occupancy:'1 of 1'
    },
    {
      name:'Miguel Reyes', room:'302', type:'Quad', property:'The Study House',
      status:'present', moveInOffset:-75, leaseEndOffset:280, phone:'+63 915 883 7712',
      email:'miguel.reyes@example.com', studentId:'2022-00789', school:'Universidad de Daguapan',
      course:'BS Architecture', emergencyContactName:'Anna Reyes',
      emergencyContactPhone:'+63 915 000 3333', monthlyRent:3200, depositAmount:6400,
      depositStatus:'Paid', leaseStatus:'Active', renewalStatus:'Not yet discussed',
      amenities:'Wi-Fi, Fan, Study desk, Shared CR', floor:'3rd Floor',
      buildingNotes:'Corner unit', capacity:4, occupancy:'3 of 4'
    },
    {
      name:'Kim Alvarez', room:'206', type:'Twin', property:'Sunrise Residences',
      status:'present', moveInOffset:-45, leaseEndOffset:320, phone:'+63 917 662 3310',
      email:'kim.alvarez@example.com', studentId:'2023-00234', school:'University of Luzon',
      course:'BS Accountancy', emergencyContactName:'Liza Alvarez',
      emergencyContactPhone:'+63 917 000 4444', monthlyRent:4500, depositAmount:9000,
      depositStatus:'Paid', leaseStatus:'Active', renewalStatus:'Not yet discussed',
      amenities:'Wi-Fi, Aircon, Study desk, Private CR', floor:'2nd Floor',
      buildingNotes:'Near stairs', capacity:2, occupancy:'2 of 2'
    },
    {
      name:'Hannah Cruz', room:'201', type:'Twin', property:'Sunrise Residences',
      status:'leaving', moveInOffset:-300, leaseEndOffset:18, phone:'+63 917 441 2277',
      email:'hannah.cruz@example.com', studentId:'2019-00567', school:'University of Luzon',
      course:'BS Education', emergencyContactName:'Pedro Cruz',
      emergencyContactPhone:'+63 917 000 5555', monthlyRent:4500, depositAmount:9000,
      depositStatus:'Refundable', leaseStatus:'Ending soon', renewalStatus:'Leaving',
      amenities:'Wi-Fi, Aircon, Study desk, Private CR', floor:'2nd Floor',
      buildingNotes:'Near fire exit', capacity:2, occupancy:'2 of 2'
    },
    {
      name:'Paolo Villanueva', room:'108', type:'Single', property:'Greenview Dorm',
      status:'leaving', moveInOffset:-410, leaseEndOffset:9, phone:'+63 919 300 8845',
      email:'paolo.villanueva@example.com', studentId:'2018-00890', school:'University of Pangasinan',
      course:'BS Civil Engineering', emergencyContactName:'Rosa Villanueva',
      emergencyContactPhone:'+63 919 000 6666', monthlyRent:5500, depositAmount:11000,
      depositStatus:'Refundable', leaseStatus:'Ending soon', renewalStatus:'Leaving',
      amenities:'Wi-Fi, Aircon, Study desk, Private CR, Cabinet', floor:'1st Floor',
      buildingNotes:'Near lobby', capacity:1, occupancy:'1 of 1'
    },
    {
      name:'Bea Santos', room:'110', type:'Single', property:'Greenview Dorm',
      status:'upcoming', moveInOffset:12, leaseEndOffset:372, phone:'+63 916 774 5521',
      email:'bea.santos@example.com', studentId:'2024-00111', school:'University of Pangasinan',
      course:'BS Pharmacy', emergencyContactName:'Carlo Santos',
      emergencyContactPhone:'+63 916 000 7777', monthlyRent:5500, depositAmount:11000,
      depositStatus:'Pending', leaseStatus:'Upcoming', renewalStatus:'Not yet discussed',
      amenities:'Wi-Fi, Aircon, Study desk, Private CR, Cabinet', floor:'1st Floor',
      buildingNotes:'Near lobby', capacity:1, occupancy:'1 of 1 (reserved)'
    },
    {
      name:'Jared Tan', room:'305', type:'Quad', property:'The Study House',
      status:'upcoming', moveInOffset:26, leaseEndOffset:391, phone:'+63 920 118 9034',
      email:'jared.tan@example.com', studentId:'2024-00222', school:'Universidad de Daguapan',
      course:'BS Information Technology', emergencyContactName:'Grace Tan',
      emergencyContactPhone:'+63 920 000 8888', monthlyRent:3200, depositAmount:6400,
      depositStatus:'Pending', leaseStatus:'Upcoming', renewalStatus:'Not yet discussed',
      amenities:'Wi-Fi, Fan, Study desk, Shared CR', floor:'3rd Floor',
      buildingNotes:'Corner unit', capacity:4, occupancy:'1 of 4 (reserved)'
    }
  ];

  var STATUS_META = {
    present:  { label:'Present',  cls:'present'  },
    leaving:  { label:'Leaving',  cls:'leaving'  },
    upcoming: { label:'Upcoming', cls:'upcoming' }
  };

  /* ---------------- HELPERS ---------------- */
  var DAY = 86400000;
  var today = new Date();
  today.setHours(0, 0, 0, 0);

  function addDays(n) {
    var d = new Date(today);
    d.setDate(d.getDate() + n);
    return d;
  }

  function formatDate(d) {
    return d.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
  }

  function formatDateShort(d) {
    return d.toLocaleDateString('en-US', { month:'short', day:'numeric' });
  }

  function monthsBetween(from, to) {
    return Math.max(1, Math.round((to - from) / (DAY * 30.44)));
  }

  function initials(name) {
    return name.split(' ').filter(Boolean).slice(0, 2)
      .map(function (p) { return p[0].toUpperCase(); }).join('');
  }

  function durationText(t) {
    if (t.status === 'upcoming') {
      return 'Starts in ' + Math.abs(t.moveInOffset) + ' days';
    }
    var moveIn = addDays(t.moveInOffset);
    var m = monthsBetween(moveIn, today);
    var label = m + (m === 1 ? ' month' : ' months');
    if (t.status === 'leaving') {
      return label + ' \u2022 leaves in ' + t.leaseEndOffset + ' days';
    }
    return label + ' and counting';
  }

  function durationShort(t) {
    if (t.status === 'upcoming') {
      return 'In ' + Math.abs(t.moveInOffset) + ' days';
    }
    var m = monthsBetween(addDays(t.moveInOffset), today);
    var base = m + (m === 1 ? ' mo' : ' mos');
    if (t.status === 'leaving') return base + ' \u00B7 leaving';
    return base;
  }

  /* ---------------- ELEMENTS ---------------- */
  var listEl     = document.getElementById('tenantList');
  var emptyEl    = document.getElementById('emptyState');
  var countEl    = document.getElementById('resultCount');
  var searchEl   = document.getElementById('tenantSearch');
  var propEl     = document.getElementById('propertyFilter');
  var clearBtn   = document.getElementById('clearFilters');
  var chipEls    = Array.prototype.slice.call(document.querySelectorAll('.chip'));
  var countEls   = Array.prototype.slice.call(document.querySelectorAll('.chip-count'));

  var drawerOverlay = document.getElementById('drawerOverlay');
  var drawer        = document.getElementById('tenantDrawer');
  var drawerBody    = document.getElementById('drawerBody');
  var drawerClose   = document.getElementById('drawerClose');

  var activeFilter = 'all';

  /* ---------------- CARD TEMPLATE ---------------- */
  function cardHTML(t, index) {
    var meta  = STATUS_META[t.status];
    var moveIn = addDays(t.moveInOffset);
    var end    = addDays(t.leaseEndOffset);

    return '' +
      '<article class="tenant-card" data-index="' + index + '">' +
        '<div class="tenant-avatar" aria-hidden="true">' + initials(t.name) + '</div>' +

        '<div class="tenant-main">' +
          '<div class="tenant-head">' +
            '<h3>' + t.name + '</h3>' +
            '<span class="badge badge--' + meta.cls + '">' + meta.label + '</span>' +
          '</div>' +

          '<p class="tenant-sub">' +
            '<i class="fas fa-door-open"></i> Room ' + t.room + ' \u00B7 ' + t.type +
            '<span class="dot">\u2022</span>' +
            '<i class="fas fa-building"></i> ' + t.property +
          '</p>' +

          '<div class="tenant-meta">' +
            '<div class="meta"><span class="meta-label">Duration of stay</span>' +
              '<span class="meta-value">' + durationText(t) + '</span></div>' +
            '<div class="meta"><span class="meta-label">Move-in</span>' +
              '<span class="meta-value">' + formatDate(moveIn) + '</span></div>' +
            '<div class="meta"><span class="meta-label">Lease ends</span>' +
              '<span class="meta-value">' + formatDate(end) + '</span></div>' +
            '<div class="meta"><span class="meta-label">Contact</span>' +
              '<span class="meta-value">' + t.phone + '</span></div>' +
          '</div>' +
        '</div>' +

        '<div class="tenant-actions">' +
          '<button type="button" class="btn-outline view-details" data-index="' + index + '">View details</button>' +
        '</div>' +
      '</article>';
  }

  /* ---------------- DRAWER TEMPLATE ---------------- */
  function sectionHTML(icon, title, items) {
    var cells = items.map(function (pair) {
      var cls = pair[2] ? ' detail-item full' : 'detail-item';
      return '<div class="' + cls.trim() + '">' +
        '<span class="detail-label">' + pair[0] + '</span>' +
        '<span class="detail-value">' + pair[1] + '</span>' +
      '</div>';
    }).join('');
    return '<div class="detail-section">' +
      '<div class="detail-section-head">' +
        '<span class="detail-section-icon"><i class="fas fa-' + icon + '"></i></span>' +
        '<h4>' + title + '</h4>' +
      '</div>' +
      '<div class="detail-grid">' + cells + '</div>' +
    '</div>';
  }

  function drawerHTML(t) {
    var meta   = STATUS_META[t.status];
    var moveIn = addDays(t.moveInOffset);
    var end    = addDays(t.leaseEndOffset);

    return '' +
      '<div class="drawer-hero">' +
        '<div class="hero-top">' +
          '<div class="tenant-avatar large" aria-hidden="true">' + initials(t.name) + '</div>' +
          '<div class="hero-info">' +
            '<h3>' + t.name + '</h3>' +
            '<span class="badge badge--' + meta.cls + '">' + meta.label + '</span>' +
          '</div>' +
        '</div>' +

        '<div class="hero-meta">' +
          '<span class="hero-chip"><i class="fas fa-door-open"></i> Room ' + t.room + ' \u00B7 ' + t.type + '</span>' +
          '<span class="hero-chip"><i class="fas fa-building"></i> ' + t.property + '</span>' +
        '</div>' +

        '<div class="hero-stats">' +
          '<div class="hero-stat">' +
            '<span class="hero-stat-label">Duration</span>' +
            '<span class="hero-stat-value">' + durationShort(t) + '</span>' +
          '</div>' +
          '<div class="hero-stat">' +
            '<span class="hero-stat-label">Rent</span>' +
            '<span class="hero-stat-value">\u20B1' + t.monthlyRent.toLocaleString() + '</span>' +
          '</div>' +
          '<div class="hero-stat">' +
            '<span class="hero-stat-label">Lease ends</span>' +
            '<span class="hero-stat-value">' + formatDateShort(end) + '</span>' +
          '</div>' +
        '</div>' +
      '</div>' +

      sectionHTML('user', 'Personal information', [
        ['Full name', t.name],
        ['Phone', t.phone],
        ['Email', t.email],
        ['Student ID', t.studentId],
        ['School', t.school],
        ['Course', t.course],
        ['Emergency contact', t.emergencyContactName],
        ['Emergency phone', t.emergencyContactPhone]
      ]) +

      sectionHTML('file-signature', 'Lease & stay', [
        ['Move-in', formatDate(moveIn)],
        ['Lease end', formatDate(end)],
        ['Monthly rent', '\u20B1' + t.monthlyRent.toLocaleString()],
        ['Deposit', '\u20B1' + t.depositAmount.toLocaleString()],
        ['Deposit status', t.depositStatus],
        ['Lease status', t.leaseStatus],
        ['Renewal', t.renewalStatus]
      ]) +

      sectionHTML('door-open', 'Room & property', [
        ['Property', t.property],
        ['Room', 'Room ' + t.room + ' \u00B7 ' + t.type],
        ['Occupancy', t.occupancy + ' \u00B7 cap ' + t.capacity],
        ['Floor', t.floor],
        ['Amenities', t.amenities, true],
        ['Notes', t.buildingNotes, true]
      ]);
  }

  /* ---------------- RENDER ---------------- */
  function getFiltered() {
    var q = searchEl.value.trim().toLowerCase();
    var prop = propEl.value;

    return tenants.map(function (t, i) { return { t: t, i: i }; }).filter(function (item) {
      var t = item.t;
      var matchesStatus = (activeFilter === 'all') || (t.status === activeFilter);
      var matchesProp   = (prop === 'all') || (t.property === prop);
      var haystack = (t.name + ' ' + t.room + ' ' + t.property + ' ' + t.phone).toLowerCase();
      var matchesSearch = !q || haystack.indexOf(q) !== -1;
      return matchesStatus && matchesProp && matchesSearch;
    });
  }

  function updateCounts() {
    var prop = propEl.value;
    var q = searchEl.value.trim().toLowerCase();

    var base = tenants.filter(function (t) {
      var matchesProp = (prop === 'all') || (t.property === prop);
      var haystack = (t.name + ' ' + t.room + ' ' + t.property).toLowerCase();
      return matchesProp && (!q || haystack.indexOf(q) !== -1);
    });

    countEls.forEach(function (el) {
      var key = el.getAttribute('data-count');
      el.textContent = (key === 'all')
        ? base.length
        : base.filter(function (t) { return t.status === key; }).length;
    });
  }

  function render() {
    var list = getFiltered();

    listEl.innerHTML = list.map(function (item) { return cardHTML(item.t, item.i); }).join('');
    emptyEl.classList.toggle('is-visible', list.length === 0);

    countEl.textContent = 'Showing ' + list.length + ' tenant' + (list.length === 1 ? '' : 's');

    updateCounts();
  }

  /* ---------------- DRAWER LOGIC ---------------- */
  function openDrawer(index) {
    var t = tenants[index];
    if (!t) return;
    drawerBody.innerHTML = drawerHTML(t);
    drawerBody.scrollTop = 0;
    drawer.classList.add('is-open');
    drawerOverlay.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeDrawer() {
    drawer.classList.remove('is-open');
    drawerOverlay.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  /* ---------------- EVENTS ---------------- */
  chipEls.forEach(function (chip) {
    chip.addEventListener('click', function () {
      chipEls.forEach(function (c) {
        c.classList.remove('is-active');
        c.setAttribute('aria-selected', 'false');
      });
      chip.classList.add('is-active');
      chip.setAttribute('aria-selected', 'true');
      activeFilter = chip.getAttribute('data-filter');
      render();
    });
  });

  searchEl.addEventListener('input', render);
  propEl.addEventListener('change', render);

  clearBtn.addEventListener('click', function () {
    searchEl.value = '';
    propEl.value = 'all';
    activeFilter = 'all';
    chipEls.forEach(function (c) {
      var isAll = c.getAttribute('data-filter') === 'all';
      c.classList.toggle('is-active', isAll);
      c.setAttribute('aria-selected', isAll ? 'true' : 'false');
    });
    render();
  });

  listEl.addEventListener('click', function (e) {
    var btn = e.target.closest('.view-details');
    if (!btn) return;
    var idx = parseInt(btn.getAttribute('data-index'), 10);
    openDrawer(idx);
  });

  drawerClose.addEventListener('click', closeDrawer);
  drawerOverlay.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && drawer.classList.contains('is-open')) {
      closeDrawer();
    }
  });

  render();
})();
</script>
</body>
</html>