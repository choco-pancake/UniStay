USE unistay;

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. Seed Initial Accounts (Admin, Landlord, Tenant)
-- IDs are the prefixed account IDs: A1, L1..L4, T1.
-- Passwords default to PHP password_hash for 'Password123!'
-- ------------------------------------------------------------------------------
INSERT INTO admins (admin_id, first_name, last_name, email, password_hash)
VALUES
('A1', 'UniStay', 'Administrator', 'unistaysads@gmail.com', '$2y$10$BR/Qd1p9kpSJyIETAEYVj.bV6nGBpTB1rW3Arj..Y5mNL4aivt1uK')
ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), email = VALUES(email);

-- Keep the ID counters in step with the seeded accounts
UPDATE id_counters SET `last_value` = GREATEST(`last_value`, 1) WHERE name = 'admins';
UPDATE id_counters SET `last_value` = GREATEST(`last_value`, 4) WHERE name = 'landlords';
UPDATE id_counters SET `last_value` = GREATEST(`last_value`, 1) WHERE name = 'tenants';

-- Ensure notification preferences exist for seeded users
INSERT INTO notification_preferences (role, user_id, tenant_alerts, announcement_emails, booking_alerts, verification_alerts, summary_emails)
VALUES
('landlord', 'L1', 1, 1, 1, 1, 1),
('tenant', 'T1', 1, 1, 1, 1, 1)
ON DUPLICATE KEY UPDATE tenant_alerts = VALUES(tenant_alerts);

-- ------------------------------------------------------------------------------
-- 2. Seed Dorms (Dagupan City University Accommodations)
-- ------------------------------------------------------------------------------
INSERT INTO dorms (id, landlord_id, name, nearest_uni, address, description, photo, proof_of_ownership, lat, lng, approval_status, common_school_pct)
VALUES
(
  101,
  'L1',
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
  'L1',
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
  'L1',
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
  'L1',
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
INSERT INTO rooms (id, dorm_id, name, occupancy, total_rent, rent_per_head, deposit, status, amenities, photo)
VALUES
(22, 102, 'Room 101', 1, 9000.00, 9000.00, 9000.00, 'available', '["WiFi","Aircon","Private CR","Study desk","Laundry"]', '["uploads/rooms/b4623227c8dd803c.jpg"]'),
(23, 102, 'Room 202', 2, 14000.00, 7000.00, 7000.00, 'available', '["WiFi","Aircon","Private CR","Study desk"]', '["uploads/rooms/7b762479244f9a99.jpg","uploads/rooms/75ecb844dced54b8.jpg"]'),
(24, 102, 'Room 203', 2, 12000.00, 6000.00, 18000.00, 'available', '["WiFi","Aircon","Private CR","Study desk"]', '["uploads/rooms/028b219489f73ba8.jpg","uploads/rooms/d9a96aeaaebc66c5.jpg"]'),
(25, 102, 'Room 204', 2, 13000.00, 6500.00, 6500.00, 'available', '["WiFi","Aircon","Private CR","Study desk","Laundry"]', '["uploads/rooms/b2a7abc25844b674.jpg"]'),
(26, 103, 'Suite A', 1, 10500.00, 10500.00, 10500.00, 'available', '["WiFi","Aircon","Study desk","Laundry"]', '["uploads/rooms/b565b06c72932ce9.jpg"]'),
(27, 103, 'Suite B', 1, 8500.00, 8500.00, 8500.00, 'available', '["WiFi","Aircon","Study desk","Laundry"]', '["uploads/rooms/12babcb0df82caeb.jpg"]'),
(28, 103, 'Suite C', 1, 10500.00, 10500.00, 10500.00, 'available', '["WiFi","Aircon","Study desk","Private CR"]', '["uploads/rooms/78dfaa68a9fc485c.jpg"]'),
(29, 104, 'Room G1', 2, 14000.00, 7000.00, 7000.00, 'available', '["WiFi","Aircon","Study desk"]', '["uploads/rooms/d8376e3364f7b448.jpg"]'),
(30, 104, 'Room G2', 6, 15000.00, 2500.00, 3000.00, 'available', '["WiFi","Aircon","Private CR","Study desk"]', '["uploads/rooms/021235b0b2e88258.jpg"]'),
(31, 104, 'Room G3', 8, 16000.00, 2000.00, 6000.00, 'available', '["WiFi","Aircon","Private CR","Study desk","Laundry"]', '["uploads/rooms/065b706c88ddaa42.jpg"]')
ON DUPLICATE KEY UPDATE name = VALUES(name), total_rent = VALUES(total_rent), rent_per_head = VALUES(rent_per_head), deposit = VALUES(deposit);

-- ------------------------------------------------------------------------------
-- 4. Seed Bookings
-- ------------------------------------------------------------------------------
INSERT INTO bookings (id, tenant_id, room_id, status)
VALUES
(1, 'T1', 22, 'approved')
ON DUPLICATE KEY UPDATE status = VALUES(status);

SET FOREIGN_KEY_CHECKS = 1;
