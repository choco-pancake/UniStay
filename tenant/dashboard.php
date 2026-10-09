<?php 
// --- Mock Data (Simulating a Backend/Database) ---

$user_data = [
    "name" => "Alex Johnson"
];

// Data for the four top cards
$dashboard_stats = [
    [
        "label" => "CURRENT ROOM",
        "main_value" => "Room Name",
        "sub_value" => "Building Name • Floor Number",
        "sub_value_2" => null,
        "icon" => "home"
    ],
    [
        "label" => "MONTHLY RENT",
        "main_value" => "$500",
        "sub_value" => "/month",
        "sub_value_2" => "Inclusions", // Image shows "Inclusions" below the price
        "icon" => "credit-card"
    ],
    [
        "label" => "NEXT PAYMENT",
        "main_value" => "Date",
        "sub_value" => "Payment Status",
        "sub_value_2" => null,
        "icon" => "calendar"
    ],
    [
        "label" => "ACCOUNT STATUS",
        "main_value" => "Standing Status",
        "sub_value" => "Lease Validity Date",
        "sub_value_2" => null,
        "icon" => "settings"
    ]
];

$current_booking = [
    "status" => "active-stay",
    "status_label" => "Active Stay",
    "room_name" => "Room Name",
    "room_type" => "Room Type",
    "dorm_name" => "Dorm Name",
    "location" => "Location",
    "near_university" => "University Name",
    "duration" => "Stay duration",
    "monthly_payment" => "₱— / month",
    "landlord_name" => "Landlord Name"
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniStay Dashboard</title>
    <link rel="stylesheet" href="../assets/css/dashboardstyle.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/dashboardstyle.css'); ?>">
    <script src="https://unpkg.com/feather-icons"></script>
</head>
<body>

    <div class="app-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="logo-area">
                <!-- Simple circle placeholder for the logo -->
                <div class="logo-circle">
                    <i data-feather="home"></i>
                </div>
                <h1>UniStay</h1>
            </div>

            <nav class="nav-menu">
                <a href="dashboard.php" class="nav-item active" aria-current="page">
                    <i data-feather="grid"></i>
                    <span>Dashboard</span>
                </a>
                <a href="map.php" class="nav-item">
                    <i data-feather="map"></i>
                    <span>Map</span>
                </a>
                <a href="rooms.php" class="nav-item">
                    <i data-feather="book-open"></i>
                    <span>Bookings</span>
                </a>
                <a href="settings.php" class="nav-item">
                    <i data-feather="settings"></i>
                    <span>Settings</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">
            
            <!-- Top Header -->
            <header class="top-header">
                <h2>Dashboard</h2>
                <div class="header-actions">
                    <a href="notifications.php" class="icon-btn notification-btn" aria-label="Notifications">
                        <i data-feather="bell"></i>
                        <span class="status-dot"></span>
                    </a>
                    <a href="profile.php" class="user-avatar" aria-label="Open profile" title="Profile"></a>
                </div>
            </header>

            <!-- Status Cards and Current Booking -->
            <section class="dashboard-overview">
                <div class="stats-grid">
                    <?php foreach ($dashboard_stats as $stat): ?>
                    <div class="stat-card">
                        <div class="card-top">
                            <span class="card-label"><?php echo htmlspecialchars($stat['label']); ?></span>
                            <i data-feather="<?php echo htmlspecialchars($stat['icon']); ?>" class="card-icon"></i>
                        </div>
                        <div class="card-middle">
                            <h3 class="card-value"><?php echo htmlspecialchars($stat['main_value']); ?></h3>
                            <?php if ($stat['label'] === 'MONTHLY RENT'): ?>
                                <span class="card-suffix">/month</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-bottom">
                            <p class="card-subtext">
                                <?php
                                echo htmlspecialchars($stat['sub_value_2'] ?? $stat['sub_value']);
                                ?>
                            </p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <section class="current-booking">
                    <div class="booking-heading">
                        <h3 class="section-title">Current Booking</h3>
                        <label class="booking-filter-label">
                            <span>Filter</span>
                            <select id="booking-status-filter" aria-label="Filter bookings by status">
                                <option value="all">All statuses</option>
                                <option value="active-stay" selected>Active Stay</option>
                                <option value="pending-approval">Pending Approval</option>
                            </select>
                        </label>
                    </div>

                    <article class="booking-card" data-status="<?php echo htmlspecialchars($current_booking['status']); ?>">
                        <div class="booking-photo">
                            <img src="https://images.unsplash.com/photo-1616486338812-3dadae4b4ace?auto=format&amp;fit=crop&amp;w=900&amp;q=80" alt="Sample room interior">
                        </div>
                        <div class="booking-information">
                            <span class="booking-status <?php echo htmlspecialchars($current_booking['status']); ?>">
                                <?php echo htmlspecialchars($current_booking['status_label']); ?>
                            </span>
                            <h4 class="booking-room-name"><?php echo htmlspecialchars($current_booking['room_name']); ?></h4>
                            <p class="booking-dorm-name"><?php echo htmlspecialchars($current_booking['dorm_name']); ?></p>

                            <dl class="booking-facts">
                                <div>
                                    <dt>Room type</dt>
                                    <dd><?php echo htmlspecialchars($current_booking['room_type']); ?></dd>
                                </div>
                                <div>
                                    <dt>Location</dt>
                                    <dd><?php echo htmlspecialchars($current_booking['location']); ?></dd>
                                </div>
                                <div>
                                    <dt>Near university</dt>
                                    <dd><?php echo htmlspecialchars($current_booking['near_university']); ?></dd>
                                </div>
                                <div>
                                    <dt>Duration of stay</dt>
                                    <dd><?php echo htmlspecialchars($current_booking['duration']); ?></dd>
                                </div>
                                <div>
                                    <dt>Monthly payment</dt>
                                    <dd><?php echo htmlspecialchars($current_booking['monthly_payment']); ?></dd>
                                </div>
                                <div>
                                    <dt>Landlord</dt>
                                    <dd><?php echo htmlspecialchars($current_booking['landlord_name']); ?></dd>
                                </div>
                            </dl>
                        </div>
                        <a class="details-button" href="rooms.php">View Booking</a>
                    </article>
                </section>
            </section>

        </main>
    </div>
    <script>
        feather.replace();
    </script>
</body>
</html>
 