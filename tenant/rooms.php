<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniStay - Bookings Filter</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Your Custom CSS -->
    <link rel="stylesheet" href="../assets/css/roomsstyle.css">
</head>
<body>

<div class="app-container">
    
    <!-- SIDEBAR MENU -->
    <aside class="sidebar">
        <div class="logo">
            <div class="logo-icon"><i class="fas fa-university"></i></div>
            <h2>UniStay</h2>
        </div>
        
        <nav class="nav-menu">
            <a href="#" class="nav-item"><i class="fas fa-th-large"></i> Dashboard</a>
            <a href="#" class="nav-item"><i class="fas fa-map"></i> Map</a>
            <a href="#" class="nav-item active"><i class="fas fa-building"></i> Rooms</a>
            <a href="#" class="nav-item"><i class="fas fa-cog"></i> Settings</a>
        </nav>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">
        
        <!-- Top Header -->
        <header class="top-header">
            <h1>Bookings</h1>
            <div class="header-actions">
                <div class="bell-icon-wrapper">
                    <i class="far fa-bell notification-icon"></i>
                    <span class="notification-dot"></span>
                </div>
                <div class="user-avatar"></div>
            </div>
        </header>

        <!-- Filter and Search Bar -->
        <div class="filter-section">
            <div class="tabs">
                <!-- Added type="button" to prevent form submission behavior -->
                <button type="button" class="tab active">All Bookings <span class="count">0</span></button>
                <button type="button" class="tab">Active Stays <span class="count">0</span></button>
                <button type="button" class="tab">Pending Approval <span class="count">0</span></button>
                <button type="button" class="tab">Saved <span class="count">0</span></button>
            </div>
            
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search...">
            </div>
        </div>

    </main>
</div>

<script src="../assets/css/js/roomscript.js" defer></script>
</body>
</html>