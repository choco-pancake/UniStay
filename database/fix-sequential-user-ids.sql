-- Migration: Renumber existing users to be continuous (1..5) and reset AUTO_INCREMENT to 6
-- UniStay Database Migration

USE unistay;

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Update dependent child table foreign keys
-- Properties landlord_id updates
UPDATE properties SET landlord_id = 1 WHERE landlord_id = 10;
UPDATE properties SET landlord_id = 4 WHERE landlord_id = 28;

-- Bookings user_id updates
UPDATE bookings SET user_id = 3 WHERE user_id = 27;

-- 2. Update users table primary keys
UPDATE users SET id = 1 WHERE id = 10;
UPDATE users SET id = 2 WHERE id = 24;
UPDATE users SET id = 3 WHERE id = 27;
UPDATE users SET id = 4 WHERE id = 28;
UPDATE users SET id = 5 WHERE id = 29;

-- 3. Reset auto-increment counter so future registrations increment from 6 sequentially
ALTER TABLE users AUTO_INCREMENT = 6;

SET FOREIGN_KEY_CHECKS = 1;
