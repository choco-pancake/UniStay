-- ==============================================================================
-- UniStay Database Schema
-- Complete and authoritative database schema for UniStay
-- ==============================================================================

CREATE DATABASE IF NOT EXISTS unistay CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE unistay;

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. Users Table
-- Supports Tenants, Landlords, and Administrators
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  -- Authentication & Account Info
  first_name VARCHAR(100) NULL,
  middle_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NULL,
  phone_number VARCHAR(16) NULL,
  facebook_link VARCHAR(255) NULL,
  personal_email VARCHAR(190) NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  
  -- Student & Tenant Profile Information
  date_of_birth DATE NULL,
  gender VARCHAR(20) NULL,
  school VARCHAR(150) NULL,
  emergency_name VARCHAR(150) NULL,
  emergency_relationship VARCHAR(50) NULL,
  emergency_phone VARCHAR(30) NULL,
  profile_photo VARCHAR(255) NULL,
  school_id_file VARCHAR(255) NULL,
  gov_id_file VARCHAR(255) NULL,
  proof_of_ownership_file VARCHAR(255) NULL,
  profile_completed TINYINT(1) NOT NULL DEFAULT 0,
  
  -- Role & System Access
  role ENUM('tenant', 'landlord', 'admin') NOT NULL DEFAULT 'tenant',
  
  -- Verification Accreditation
  verification_status ENUM('unverified', 'pending', 'verified', 'rejected') NOT NULL DEFAULT 'unverified',
  verification_notes TEXT NULL,
  verified_at DATETIME NULL,
  
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  photo VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2. Password Resets Table
-- Manages secure time-limited password recovery tokens
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 3. Properties Table
-- Dormitories and student housing accommodations
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS properties (
  id INT AUTO_INCREMENT PRIMARY KEY,
  landlord_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  university VARCHAR(150) NOT NULL,
  address VARCHAR(255) NOT NULL,
  description TEXT NULL,
  photo TEXT NOT NULL,                   -- JSON array of file paths e.g. ["uploads/properties/a.jpg"]
  proof_of_ownership VARCHAR(255) NULL,  -- Document file path (PDF/Image)
  lat DECIMAL(9,6) NOT NULL,
  lng DECIMAL(9,6) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  approval_status ENUM('approved', 'pending', 'rejected') NOT NULL DEFAULT 'approved',
  common_school_pct INT NOT NULL DEFAULT 0,
  FOREIGN KEY (landlord_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_properties_university (university),
  INDEX idx_properties_approval (approval_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 4. Rooms Table
-- Individual rooms and units within a property
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  type ENUM('SINGLE', 'TWIN', 'QUAD', 'QUINTUPLE', 'SEXTUPLE', 'OCTUPLE', 'DECUPLE') NOT NULL,
  capacity TINYINT UNSIGNED NOT NULL,
  rent DECIMAL(10,2) NOT NULL,
  rent_max DECIMAL(10,2) NULL,
  deposit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  deposit_max DECIMAL(10,2) NULL,
  occupants TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('Available', 'Occupied', 'Under Maintenance') NOT NULL DEFAULT 'Available',
  amenities LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL CHECK (json_valid(amenities)),
  photo TEXT NULL,                       -- JSON array of room images
  FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
  INDEX idx_rooms_property (property_id),
  INDEX idx_rooms_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 5. Bookings Table
-- Reservations and resident tenancies
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bookings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  property_id INT NOT NULL,
  room_id INT NULL,
  status ENUM('pending', 'active', 'approved', 'upcoming', 'cancelled', 'rejected') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
  FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
  INDEX idx_booking_property (property_id),
  INDEX idx_booking_user (user_id),
  INDEX idx_booking_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 6. Landlord Applications Table
-- Applications submitted by prospective landlords for accreditation
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS landlord_applications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) DEFAULT NULL,
  middle_name VARCHAR(100) DEFAULT NULL,
  last_name VARCHAR(100) DEFAULT NULL,
  full_name VARCHAR(120) DEFAULT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  residential_address VARCHAR(255) DEFAULT NULL,
  experience_summary TEXT DEFAULT NULL,
  government_id_file VARCHAR(255) DEFAULT NULL,
  responsibility_acknowledged_at TIMESTAMP NULL DEFAULT NULL,
  property_name VARCHAR(150) DEFAULT NULL,
  property_address VARCHAR(255) DEFAULT NULL,
  room_count SMALLINT DEFAULT NULL,
  message TEXT DEFAULT NULL,
  status ENUM('pending', 'approved', 'declined') NOT NULL DEFAULT 'pending',
  decision_note VARCHAR(500) DEFAULT NULL,
  invitation_token_hash CHAR(64) DEFAULT NULL,
  user_id INT DEFAULT NULL,
  token_hash CHAR(64) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  decided_at TIMESTAMP NULL DEFAULT NULL,
  registered_at TIMESTAMP NULL DEFAULT NULL,
  admin_email_status ENUM('unconfigured', 'sent', 'failed') NOT NULL DEFAULT 'unconfigured',
  admin_email_sent_at TIMESTAMP NULL DEFAULT NULL,
  admin_review_token_hash CHAR(64) DEFAULT NULL,
  admin_review_token_expires_at DATETIME DEFAULT NULL,
  admin_review_token_used_at DATETIME DEFAULT NULL,
  UNIQUE KEY uq_landlord_application_token (token_hash),
  UNIQUE KEY uq_landlord_application_invite (invitation_token_hash),
  UNIQUE KEY uq_landlord_application_admin_review (admin_review_token_hash),
  INDEX idx_landlord_application_email (email),
  INDEX idx_landlord_application_status (status),
  INDEX idx_landlord_application_user (user_id),
  CONSTRAINT fk_landlord_application_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 7. Notifications Table
-- Real-time in-app alerts for tenants, landlords, and administrators
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  booking_id INT NULL,
  type ENUM('booking', 'approval', 'cancellation', 'message', 'system') NOT NULL DEFAULT 'booking',
  title VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_notifications_user_read (user_id, is_read),
  INDEX idx_notifications_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 8. Notification Preferences Table
-- User-configurable email and alert settings
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_preferences (
  user_id INT NOT NULL PRIMARY KEY,
  tenant_alerts TINYINT(1) NOT NULL DEFAULT 1,
  announcement_emails TINYINT(1) NOT NULL DEFAULT 1,
  booking_alerts TINYINT(1) NOT NULL DEFAULT 1,
  verification_alerts TINYINT(1) NOT NULL DEFAULT 1,
  summary_emails TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 9. Subscribers Table
-- Newsletter and platform updates mailing list
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscribers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
