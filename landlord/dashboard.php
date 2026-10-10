<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniStay - Landlord Dashboard</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Your Custom CSS -->
    <link rel="stylesheet" href="../assets/landlord(css)/dashboard.css">
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
            <a href="dashboard.php" class="nav-item active"><i class="fas fa-th-large"></i> Dashboard</a>
            <a href="properties.php" class="nav-item"><i class="fas fa-home"></i> Properties</a>
            <a href="rooms.php" class="nav-item"><i class="fas fa-door-open"></i> Rooms</a>
            <a href="../tenant/map.php" class="nav-item"><i class="fas fa-map"></i> Map</a>
            <a href="settings.php" class="nav-item"><i class="fas fa-cog"></i> Settings and profile</a>
        </nav>

        <div class="nav-menu bottom-nav">
            <a href="../auth/logout.php" class="nav-item logout"><i class="fas fa-sign-out-alt"></i> Log out</a>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">
        
        <!-- TOP HEADER -->
        <header class="top-header">
            <h1>Dashboard</h1>
            
            <div class="header-actions">
                <!-- Notifications -->
                <a href="notifications.php" class="bell-icon-wrapper" aria-label="Notifications">
                    <i class="far fa-bell notification-icon"></i>
                    <span class="notification-badge">3</span>
                </a>

                <!-- User Profile Info -->
                <div class="user-profile-header">
                    <div class="user-avatar-small">
                        <img src="data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='40' height='40'%3E%3Crect width='100%25' height='100%25' fill='%23d1d1d1'/%3E%3Ctext x='50%25' y='50%25' fill='%238b8079' font-family='sans-serif' font-size='12' text-anchor='middle' dy='.3em'%3EJD%3C/text%3E%3C/svg%3E" alt="Profile">
                    </div>
                    <div class="user-info">
                        <span class="user-name">Juan Dela Cruz</span>
                        <span class="user-role">Landlord</span>
                    </div>
                </div>

                <!-- Profile Dropdown Button -->
                <a href="settings.php" class="profile-btn">
                    Profile <i class="fas fa-chevron-down"></i>
                </a>
            </div>
        </header>

        <!-- WELCOME SECTION (UPDATED WITH DYNAMIC SPAN) -->
        <div class="welcome-section">
            <div class="welcome-text">
                <h2>Welcome back, <span id="userWelcomeName">Juan</span></h2>
                <p>Here's an overview of your rental properties.</p>
            </div>
            <a href="properties.php" class="btn-primary"><i class="fas fa-plus"></i> Add property</a>
        </div>

        <!-- STATS CARDS -->
        <div class="stats-grid">
            <div class="stat-card">
                <p class="stat-label">TOTAL PROPERTIES</p>
                <h3 class="stat-value">4</h3>
                <p class="stat-sub">Listed on UniStay</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">TOTAL ROOMS</p>
                <h3 class="stat-value">24</h3>
                <p class="stat-sub">Across all properties</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">OCCUPANCY RATE</p>
                <h3 class="stat-value">75%</h3>
                <p class="stat-sub">18 rooms occupied</p>
            </div>
            <div class="stat-card">
                <p class="stat-label">ACCOUNT STATUS</p>
                <h3 class="stat-value verified-text">Verified</h3>
                <p class="stat-sub">Profile and documents approved</p>
            </div>
        </div>

        <!-- DASHBOARD GRID (Properties & Bookings) -->
        <div class="dashboard-grid">
            
            <!-- Left Column: My Properties -->
            <div class="section-container">
                <div class="section-header">
                    <h2>My properties</h2>
                    <a href="properties.php" class="view-all">View all →</a>
                </div>
                
                <div class="list-container">
                    <!-- Property Item 1 -->
                    <div class="property-item">
                        <div class="item-img">
                            <img src="data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100'%3E%3Crect width='100%25' height='100%25' fill='%23d1d1d1'/%3E%3Ctext x='50%25' y='50%25' fill='%238b8079' font-family='sans-serif' font-size='12' text-anchor='middle' dy='.3em'%3EProp%3C/text%3E%3C/svg%3E" alt="Property">
                        </div>
                        <div class="item-info">
                            <h4>Sunrise Residences</h4>
                            <p class="item-sub">12 Rizal St, Daguapan City</p>
                            <p class="item-sub">Near University of Luzon • 8 rooms</p>
                        </div>
                    </div>

                    <!-- Property Item 2 -->
                    <div class="property-item">
                        <div class="item-img">
                            <img src="data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100'%3E%3Crect width='100%25' height='100%25' fill='%23d1d1d1'/%3E%3Ctext x='50%25' y='50%25' fill='%238b8079' font-family='sans-serif' font-size='12' text-anchor='middle' dy='.3em'%3EProp%3C/text%3E%3C/svg%3E" alt="Property">
                        </div>
                        <div class="item-info">
                            <h4>Greenview Dorm</h4>
                            <p class="item-sub">8 Bonuan Gueset Rd, Daguapan City</p>
                            <p class="item-sub">Near University of Pangasinan • 10 rooms</p>
                        </div>
                    </div>

                    <!-- Property Item 3 -->
                    <div class="property-item">
                        <div class="item-img">
                            <img src="data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100'%3E%3Crect width='100%25' height='100%25' fill='%23d1d1d1'/%3E%3Ctext x='50%25' y='50%25' fill='%238b8079' font-family='sans-serif' font-size='12' text-anchor='middle' dy='.3em'%3EProp%3C/text%3E%3C/svg%3E" alt="Property">
                        </div>
                        <div class="item-info">
                            <h4>The Study House</h4>
                            <p class="item-sub">35 Arellano St, Daguapan City</p>
                            <p class="item-sub">Near Universidad de Daguapan • 6 rooms</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Recent Bookings -->
            <div class="section-container">
                <div class="section-header">
                    <h2>Recent bookings</h2>
                    <a href="tenants.php" class="view-all">View all →</a>
                </div>
                
                <div class="list-container">
                    <!-- Booking Item 1 -->
                    <div class="booking-item-card">
                        <div class="item-img">
                            <img src="data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100'%3E%3Crect width='100%25' height='100%25' fill='%23d1d1d1'/%3E%3Ctext x='50%25' y='50%25' fill='%238b8079' font-family='sans-serif' font-size='12' text-anchor='middle' dy='.3em'%3ERoom%3C/text%3E%3C/svg%3E" alt="Room">
                        </div>
                        <div class="item-info">
                            <h4>Room 204 - Twin</h4>
                            <p class="item-sub">Sunrise Residences • Daguapan City</p>
                            <p class="item-sub">Near University of Luzon • 6 months</p>
                            <p class="item-tenant">Alex Mendoza • Tenant</p>
                            <a href="tenants.php" class="btn-outline">View details</a>
                        </div>
                    </div>

                    <!-- Booking Item 2 -->
                    <div class="booking-item-card">
                        <div class="item-img">
                            <img src="data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100'%3E%3Crect width='100%25' height='100%25' fill='%23d1d1d1'/%3E%3Ctext x='50%25' y='50%25' fill='%238b8079' font-family='sans-serif' font-size='12' text-anchor='middle' dy='.3em'%3ERoom%3C/text%3E%3C/svg%3E" alt="Room">
                        </div>
                        <div class="item-info">
                            <h4>Room 105 - Single</h4>
                            <p class="item-sub">Greenview Dorm • Daguapan City</p>
                            <p class="item-sub">Near University of Pangasinan • 4 months</p>
                            <p class="item-tenant">Sofia Lim • Tenant</p>
                            <a href="tenants.php" class="btn-outline">View details</a>
                        </div>
                    </div>

                    <!-- Booking Item 3 -->
                    <div class="booking-item-card">
                        <div class="item-img">
                            <img src="data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='100'%3E%3Crect width='100%25' height='100%25' fill='%23d1d1d1'/%3E%3Ctext x='50%25' y='50%25' fill='%238b8079' font-family='sans-serif' font-size='12' text-anchor='middle' dy='.3em'%3ERoom%3C/text%3E%3C/svg%3E" alt="Room">
                        </div>
                        <div class="item-info">
                            <h4>Room 302 - Quad</h4>
                            <p class="item-sub">The Study House • Daguapan City</p>
                            <p class="item-sub">Near Universidad de Daguapan • 1 year</p>
                            <p class="item-tenant">Miguel Reyes • Tenant</p>
                            <a href="tenants.php" class="btn-outline">View details</a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- Your Custom JS -->
<script src="../assets/landlord(js)/dashboard.js" defer></script>
</body>
</html>