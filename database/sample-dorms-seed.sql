USE unistay;

-- Seed Landlord User if not exists
INSERT INTO users (id, first_name, last_name, personal_email, role, profile_completed)
VALUES (10, 'Juan', 'Dela Cruz', '6ix9inepgx@gmail.com', 'landlord', 1)
ON DUPLICATE KEY UPDATE first_name = 'Juan', last_name = 'Dela Cruz', role = 'landlord';

-- Clear existing sample properties (if any with these names)
DELETE FROM properties WHERE name IN (
  'St. Albert Student Suites',
  'Pangasinan Scholars Haven',
  'Tapuac Premier Suites',
  'Lyceum Green Hall'
);

-- 1. St. Albert Student Suites (Near Universidad de Dagupan)
INSERT INTO properties (id, landlord_id, name, university, address, description, photo, lat, lng, common_school_pct)
VALUES (
  101,
  10,
  'St. Albert Student Suites',
  'Universidad de Dagupan',
  'Arellano Street, Pantal, Dagupan City',
  'Modern and secure student residence situated just a 2-minute walk from Universidad de Dagupan. Equipped with high-speed fiber internet, study lounge, CCTV security, and biometric entry.',
  '["uploads/properties/st_albert_dorm.jpg"]',
  16.051500,
  120.341500,
  88
);

-- Rooms for St. Albert
INSERT INTO rooms (property_id, name, type, capacity, rent, rent_max, deposit, deposit_max, occupants, status, amenities, photo)
VALUES
(101, 'Room 101 (Deluxe Single)', 'SINGLE', 1, 3500.00, NULL, 3500.00, NULL, 0, 'Available', '["Air Conditioning", "Private Bathroom", "WiFi", "Study Desk"]', '[]'),
(101, 'Room 102 (Twin Sharing)', 'TWIN', 2, 2600.00, NULL, 2600.00, NULL, 1, 'Available', '["Air Conditioning", "Shared Bathroom", "WiFi", "Bunk Bed"]', '[]'),
(101, 'Room 103 (Quad Dorm)', 'QUAD', 4, 1900.00, NULL, 1900.00, NULL, 2, 'Available', '["Ceiling Fan", "Shared Bathroom", "WiFi", "Lockers"]', '[]');

-- 2. Pangasinan Scholars Haven (Near University of Pangasinan)
INSERT INTO properties (id, landlord_id, name, university, address, description, photo, lat, lng, common_school_pct)
VALUES (
  102,
  10,
  'Pangasinan Scholars Haven',
  'University of Pangasinan',
  'Perez Boulevard, Pogo Chico, Dagupan City',
  'Comfortable and budget-friendly student housing right across PHINMA University of Pangasinan. Features air-conditioned rooms, quiet study pods, and 24/7 building management.',
  '["uploads/properties/scholars_haven_dorm.jpg"]',
  16.046200,
  120.343100,
  82
);

-- Rooms for Pangasinan Scholars Haven
INSERT INTO rooms (property_id, name, type, capacity, rent, rent_max, deposit, deposit_max, occupants, status, amenities, photo)
VALUES
(102, 'Room 201 (Single Studio)', 'SINGLE', 1, 3800.00, NULL, 3800.00, NULL, 0, 'Available', '["Air Conditioning", "Private Bathroom", "WiFi", "Personal Refrigerator"]', '[]'),
(102, 'Room 202 (Twin Bed)', 'TWIN', 2, 2750.00, NULL, 2750.00, NULL, 0, 'Available', '["Air Conditioning", "Shared Bathroom", "WiFi"]', '[]'),
(102, 'Room 203 (Quad Suite)', 'QUAD', 4, 2000.00, NULL, 2000.00, NULL, 3, 'Available', '["Air Conditioning", "Shared Bathroom", "WiFi", "Study Area"]', '[]');

-- 3. Tapuac Premier Suites (Near University of Luzon)
INSERT INTO properties (id, landlord_id, name, university, address, description, photo, lat, lng, common_school_pct)
VALUES (
  103,
  10,
  'Tapuac Premier Suites',
  'University of Luzon',
  'Tapuac District, Tapuac, Dagupan City',
  'Premier student co-living space located in the heart of Tapuac near University of Luzon. Includes furnished units, en-suite bathrooms, and an open communal kitchen.',
  '["uploads/properties/tapuac_premier_dorm.jpg"]',
  16.040500,
  120.336800,
  79
);

-- Rooms for Tapuac Premier Suites
INSERT INTO rooms (property_id, name, type, capacity, rent, rent_max, deposit, deposit_max, occupants, status, amenities, photo)
VALUES
(103, 'Suite A (Solo Room)', 'SINGLE', 1, 4000.00, NULL, 4000.00, NULL, 0, 'Available', '["Air Conditioning", "Private Bathroom", "High Speed WiFi", "Workstation"]', '[]'),
(103, 'Suite B (Twin Room)', 'TWIN', 2, 2800.00, NULL, 2800.00, NULL, 1, 'Available', '["Air Conditioning", "Private Bathroom", "High Speed WiFi"]', '[]'),
(103, 'Suite C (Quad Sharing)', 'QUAD', 4, 2100.00, NULL, 2100.00, NULL, 4, 'Occupied', '["Air Conditioning", "Shared Bathroom", "High Speed WiFi"]', '[]');

-- 4. Lyceum Green Hall (Near Lyceum Northwestern University)
INSERT INTO properties (id, landlord_id, name, university, address, description, photo, lat, lng, common_school_pct)
VALUES (
  104,
  10,
  'Lyceum Green Hall',
  'Lyceum Northwestern University',
  'Tapuac Road, Tapuac, Dagupan City',
  'Eco-friendly, spacious student dorm just steps away from Lyceum Northwestern University. Offers single and shared occupancy with personal study desks and high-speed WiFi.',
  '["uploads/properties/lyceum_green_dorm.jpg"]',
  16.036200,
  120.331500,
  85
);

-- Rooms for Lyceum Green Hall
INSERT INTO rooms (property_id, name, type, capacity, rent, rent_max, deposit, deposit_max, occupants, status, amenities, photo)
VALUES
(104, 'Room G1 (Private Pod)', 'SINGLE', 1, 3600.00, NULL, 3600.00, NULL, 0, 'Available', '["Air Conditioning", "Shared Bathroom", "WiFi", "Desk Lamp"]', '[]'),
(104, 'Room G2 (Twin Comfort)', 'TWIN', 2, 2500.00, NULL, 2500.00, NULL, 0, 'Available', '["Air Conditioning", "Shared Bathroom", "WiFi", "Wardrobe"]', '[]'),
(104, 'Room G3 (Quad Standard)', 'QUAD', 4, 1850.00, NULL, 1850.00, NULL, 1, 'Available', '["Ceiling Fan", "Shared Bathroom", "WiFi", "Individual Lockers"]', '[]');

-- Seed sample tenants and bookings for Landlord 10
INSERT INTO users (id, first_name, last_name, personal_email, role, profile_completed)
VALUES 
(21, 'Alex', 'Mendoza', 'alex.mendoza@example.com', 'tenant', 1),
(22, 'Sofia', 'Lim', 'sofia.lim@example.com', 'tenant', 1),
(23, 'Miguel', 'Reyes', 'miguel.reyes@example.com', 'tenant', 1)
ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name);

INSERT INTO bookings (id, user_id, property_id, room_id, status)
VALUES
(1, 21, 101, 2, 'active'),
(2, 22, 102, 5, 'active'),
(3, 23, 103, 8, 'pending')
ON DUPLICATE KEY UPDATE status = VALUES(status);

