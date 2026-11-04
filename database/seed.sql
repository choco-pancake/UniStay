-- ==============================================================================
-- UniStay Database Seed Data
-- Initial reference dataset for demonstration, testing, and development
-- ==============================================================================

USE unistay;

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. Seed Initial Accounts (Admin, Landlord, Tenants)
-- Passwords default to PHP password_hash for 'Password123!' ($2y$10$wT0lVl8dG...)
-- ------------------------------------------------------------------------------
INSERT INTO users (id, first_name, middle_name, last_name, personal_email, phone_number, password_hash, role, verification_status, profile_completed)
VALUES
(1, 'Juan', 'Santos', 'Dela Cruz', 'landlord@unistay.ph', '09171234567', '$2y$10$eA32hKekzQh6h6g31j0pG.gZ9lQ67E7G69f8v11J3K9YqL39tK6O.', 'landlord', 'verified', 1),
(2, 'UniStay', 'Super', 'Administrator', 'admin@unistay.ph', '09189876543', '$2y$10$eA32hKekzQh6h6g31j0pG.gZ9lQ67E7G69f8v11J3K9YqL39tK6O.', 'admin', 'verified', 1)
ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), role = VALUES(role);

-- Ensure notification preferences exist for seeded users
INSERT INTO notification_preferences (user_id, tenant_alerts, announcement_emails, booking_alerts, verification_alerts, summary_emails)
VALUES
(1, 1, 1, 1, 1, 1),
(2, 1, 1, 1, 1, 1)
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id);

-- ------------------------------------------------------------------------------
-- 2. Seed Properties (Dagupan City University Accommodations)
-- ------------------------------------------------------------------------------
INSERT INTO properties (id, landlord_id, name, university, address, description, photo, proof_of_ownership, lat, lng, approval_status, common_school_pct)
VALUES
(
  101,
  1,
  'University Green Dorm',
  'University of Pangasinan',
  'Arellano Street, Downtown District, Dagupan City',
  'Modern and secure student residence situated within a 3-minute walking distance from PHINMA University of Pangasinan. Offers CCTV security, backup generator, biometric entry, and furnished air-conditioned units.',
  '["uploads/properties/st_albert_dorm.jpg"]',
  'uploads/properties/proof_101.pdf',
  16.043800,
  120.339200,
  'approved',
  88
),
(
  102,
  1,
  'Pangasinan Scholars Haven',
  'University of Pangasinan',
  'Perez Boulevard, Pogo Chico, Dagupan City',
  'Comfortable and budget-friendly student housing right across PHINMA University of Pangasinan. Features air-conditioned rooms, quiet study pods, and 24/7 building management.',
  '["uploads/properties/scholars_haven_dorm.jpg"]',
  'uploads/properties/proof_102.pdf',
  16.046200,
  120.343100,
  'approved',
  82
),
(
  103,
  1,
  'Tapuac Premier Suites',
  'University of Luzon',
  'Tapuac District, Tapuac, Dagupan City',
  'Premier student co-living space located in the heart of Tapuac near University of Luzon. Includes furnished units, en-suite bathrooms, and an open communal kitchen.',
  '["uploads/properties/tapuac_premier_dorm.jpg"]',
  'uploads/properties/proof_103.pdf',
  16.040500,
  120.336800,
  'approved',
  79
),
(
  104,
  1,
  'Lyceum Green Hall',
  'Lyceum Northwestern University',
  'Tapuac Road, Tapuac, Dagupan City',
  'Eco-friendly, spacious student dorm just steps away from Lyceum Northwestern University. Offers single and shared occupancy with personal study desks and high-speed WiFi.',
  '["uploads/properties/lyceum_green_dorm.jpg"]',
  'uploads/properties/proof_104.pdf',
  16.036200,
  120.331500,
  'approved',
  85
)
ON DUPLICATE KEY UPDATE name = VALUES(name), approval_status = VALUES(approval_status);

-- ------------------------------------------------------------------------------
-- 3. Seed Rooms
-- ------------------------------------------------------------------------------
INSERT INTO rooms (id, property_id, name, type, capacity, rent, rent_max, deposit, deposit_max, occupants, status, amenities, photo)
VALUES
-- Rooms for University Green Dorm (101)
(1, 101, 'Room 101 (Deluxe Single)', 'SINGLE', 1, 3500.00, NULL, 3500.00, NULL, 0, 'Available', '["Air Conditioning", "Private Bathroom", "WiFi", "Study Desk"]', '[]'),
(2, 101, 'Room 102 (Twin Sharing)', 'TWIN', 2, 2600.00, NULL, 2600.00, NULL, 1, 'Available', '["Air Conditioning", "Shared Bathroom", "WiFi", "Bunk Bed"]', '[]'),
(3, 101, 'Room 103 (Quad Dorm)', 'QUAD', 4, 1900.00, NULL, 1900.00, NULL, 2, 'Available', '["Ceiling Fan", "Shared Bathroom", "WiFi", "Lockers"]', '[]'),

-- Rooms for Pangasinan Scholars Haven (102)
(4, 102, 'Room 201 (Single Studio)', 'SINGLE', 1, 3800.00, NULL, 3800.00, NULL, 0, 'Available', '["Air Conditioning", "Private Bathroom", "WiFi", "Personal Refrigerator"]', '[]'),
(5, 102, 'Room 202 (Twin Bed)', 'TWIN', 2, 2750.00, NULL, 2750.00, NULL, 1, 'Available', '["Air Conditioning", "Shared Bathroom", "WiFi"]', '[]'),
(6, 102, 'Room 203 (Quad Suite)', 'QUAD', 4, 2000.00, NULL, 2000.00, NULL, 3, 'Available', '["Air Conditioning", "Shared Bathroom", "WiFi", "Study Area"]', '[]'),

-- Rooms for Tapuac Premier Suites (103)
(7, 103, 'Suite A (Solo Room)', 'SINGLE', 1, 4000.00, NULL, 4000.00, NULL, 0, 'Available', '["Air Conditioning", "Private Bathroom", "High Speed WiFi", "Workstation"]', '[]'),
(8, 103, 'Suite B (Twin Room)', 'TWIN', 2, 2800.00, NULL, 2800.00, NULL, 1, 'Available', '["Air Conditioning", "Private Bathroom", "High Speed WiFi"]', '[]'),
(9, 103, 'Suite C (Quad Sharing)', 'QUAD', 4, 2100.00, NULL, 2100.00, NULL, 4, 'Occupied', '["Air Conditioning", "Shared Bathroom", "High Speed WiFi"]', '[]'),

-- Rooms for Lyceum Green Hall (104)
(10, 104, 'Room G1 (Private Pod)', 'SINGLE', 1, 3600.00, NULL, 3600.00, NULL, 0, 'Available', '["Air Conditioning", "Shared Bathroom", "WiFi", "Desk Lamp"]', '[]'),
(11, 104, 'Room G2 (Twin Comfort)', 'TWIN', 2, 2500.00, NULL, 2500.00, NULL, 0, 'Available', '["Air Conditioning", "Shared Bathroom", "WiFi", "Wardrobe"]', '[]'),
(12, 104, 'Room G3 (Quad Standard)', 'QUAD', 4, 1850.00, NULL, 1850.00, NULL, 1, 'Available', '["Ceiling Fan", "Shared Bathroom", "WiFi", "Individual Lockers"]', '[]')
ON DUPLICATE KEY UPDATE name = VALUES(name), rent = VALUES(rent);

-- ------------------------------------------------------------------------------
-- 4. Seed Bookings
-- ------------------------------------------------------------------------------
INSERT INTO bookings (id, user_id, property_id, room_id, status)
VALUES
(1, 3, 101, 2, 'active'),
(2, 4, 102, 5, 'active'),
(3, 5, 103, 8, 'pending')
ON DUPLICATE KEY UPDATE status = VALUES(status);

SET FOREIGN_KEY_CHECKS = 1;

