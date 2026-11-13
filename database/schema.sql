CREATE DATABASE IF NOT EXISTS unistay CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE unistay;

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 0. ID counters
-- Replaces AUTO_INCREMENT for the prefixed account IDs (A1, L1, T1, ...).
-- Next ID: UPDATE id_counters SET last_value = LAST_INSERT_ID(last_value + 1) WHERE name = 'tenants';
--          then SELECT LAST_INSERT_ID(); and prefix it with the letter.
-- This is atomic, so two sign-ups at the same time can never get the same ID.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS id_counters (
  name VARCHAR(30) NOT NULL PRIMARY KEY,
  last_value INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO id_counters (name, last_value) VALUES ('admins', 0), ('landlords', 0), ('tenants', 0);

-- ------------------------------------------------------------------------------
-- 1. Admins Table (admin_id: A1, A2, ...)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
  admin_id VARCHAR(20) NOT NULL PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  middle_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone_number VARCHAR(12) NULL,
  password_hash VARCHAR(255) NOT NULL,
  profile_photo TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2. Tenants Table (tenant_id: T1, T2, ...)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenants (
  tenant_id VARCHAR(20) NOT NULL PRIMARY KEY,
  first_name VARCHAR(100) NULL,
  middle_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  phone_number VARCHAR(12) NULL,
  facebook_link VARCHAR(255) NULL,
  university ENUM(
    'Universidad de Dagupan',
    'University of Pangasinan',
    'University of Luzon',
    'Lyceum Northwestern University'
  ) NULL,
  emergency_name VARCHAR(150) NULL,
  emergency_relationship VARCHAR(50) NULL,
  emergency_phone VARCHAR(12) NULL,
  profile_photo TEXT NULL,
  school_id TEXT NULL,
  gov_id TEXT NULL,
  profile_completed TINYINT(1) NOT NULL DEFAULT 0,
  verification_status ENUM('unverified', 'pending', 'verified', 'declined') NOT NULL DEFAULT 'unverified',
  account_status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
  feedback TEXT NULL,
  suspension_reason TEXT NULL,
  suspended_at DATETIME NULL,
  verified_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tenants_university (university),
  INDEX idx_tenants_verification (verification_status),
  INDEX idx_tenants_account_status (account_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 3. Landlords Table (landlord_id: L1, L2, ...)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS landlords (
  landlord_id VARCHAR(20) NOT NULL PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  middle_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NULL,          -- NULL until the approved landlord sets a password (or signs in with Google)
  activation_token VARCHAR(64) NULL,        -- used by auth/landlord-register.php
  phone_number VARCHAR(12) NOT NULL,
  facebook_link VARCHAR(255) NULL,
  residential_address VARCHAR(255) NOT NULL,
  experience_summary TEXT NOT NULL,
  gov_id TEXT NULL,
  profile_photo TEXT NULL,
  status ENUM('pending', 'approved', 'declined', 'suspended') NOT NULL DEFAULT 'pending',
  approved_by VARCHAR(150) NULL,
  feedback TEXT NULL,
  suspension_reason TEXT NULL,
  suspended_at DATETIME NULL,
  decided_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_landlords_status (status),
  INDEX idx_landlords_approved_by (approved_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 4. Dorms Table
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dorms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  landlord_id VARCHAR(20) NOT NULL,
  name VARCHAR(150) NOT NULL,
  nearest_uni ENUM(
    'Universidad de Dagupan',
    'University of Pangasinan',
    'University of Luzon',
    'Lyceum Northwestern University'
  ) NOT NULL,
  address VARCHAR(255) NOT NULL,
  description TEXT NULL,
  photo TEXT NOT NULL,                   -- JSON array of file paths
  proof_of_ownership TEXT NULL,          -- Document file path (PDF/Image)
  business_permit TEXT NULL,             -- Business / Mayor's permit file path (PDF/Image)
  lat DECIMAL(9,6) NOT NULL,
  lng DECIMAL(9,6) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  approval_status ENUM('pending', 'approved', 'declined') NOT NULL DEFAULT 'approved',
  approved_by VARCHAR(150) NULL,
  feedback TEXT NULL,
  common_school_pct INT NOT NULL DEFAULT 0,
  FOREIGN KEY (landlord_id) REFERENCES landlords(landlord_id) ON DELETE CASCADE,
  INDEX idx_dorms_nearest_uni (nearest_uni),
  INDEX idx_dorms_approval (approval_status),
  INDEX idx_dorms_approved_by (approved_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 5. Rooms Table
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dorm_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  occupancy SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  total_rent DECIMAL(10,2) NOT NULL,
  rent_per_head DECIMAL(10,2) NOT NULL,
  deposit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status ENUM('available', 'occupied') NOT NULL DEFAULT 'available',
  amenities LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL CHECK (json_valid(amenities)),
  photo TEXT NULL,                       -- JSON array of room images
  FOREIGN KEY (dorm_id) REFERENCES dorms(id) ON DELETE CASCADE,
  INDEX idx_rooms_dorm (dorm_id),
  INDEX idx_rooms_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 6. Bookings Table
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bookings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id VARCHAR(20) NOT NULL,
  room_id INT NOT NULL,
  status ENUM('pending', 'approved', 'declined', 'cancelled') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
  FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
  INDEX idx_booking_tenant (tenant_id),
  INDEX idx_booking_room (room_id),
  INDEX idx_booking_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 7. Notifications Table
-- user_id holds the prefixed ID of whichever role the notification is for.
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role ENUM('tenant', 'landlord', 'admin') NOT NULL DEFAULT 'tenant',
  user_id VARCHAR(20) NOT NULL,
  booking_id INT NULL,
  type ENUM('booking', 'approval', 'cancellation', 'system') NOT NULL DEFAULT 'booking',
  title VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notifications_role_user_read (role, user_id, is_read),
  INDEX idx_notifications_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 8. Notification Preferences Table
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_preferences (
  role ENUM('tenant', 'landlord') NOT NULL DEFAULT 'tenant',
  user_id VARCHAR(20) NOT NULL,
  tenant_alerts TINYINT(1) NOT NULL DEFAULT 1,
  announcement_emails TINYINT(1) NOT NULL DEFAULT 1,
  booking_alerts TINYINT(1) NOT NULL DEFAULT 1,
  verification_alerts TINYINT(1) NOT NULL DEFAULT 1,
  summary_emails TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (role, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 9. Password Resets Table
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role ENUM('tenant', 'landlord', 'admin') NOT NULL DEFAULT 'tenant',
  user_id VARCHAR(20) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_password_resets_role_user (role, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 10. Subscribers Table
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscribers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  status ENUM('pending','active','unsubscribed','bounced') NOT NULL DEFAULT 'pending',
  source VARCHAR(40) NOT NULL DEFAULT 'landing_footer',
  confirm_token CHAR(64) NULL,
  confirm_expires_at DATETIME NULL,
  unsubscribe_token CHAR(64) NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  consent_at DATETIME NULL,
  confirmed_at DATETIME NULL,
  unsubscribed_at DATETIME NULL,
  last_sent_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_subscribers_status (status),
  UNIQUE INDEX uniq_subscribers_unsubscribe_token (unsubscribe_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;