-- ==============================================================================
-- UniStay Database Schema
-- Complete and authoritative database schema for UniStay
-- ==============================================================================

CREATE DATABASE IF NOT EXISTS unistay CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE unistay;

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. Admins Table
-- Dedicated administrative accounts
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  middle_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NOT NULL,
  personal_email VARCHAR(190) NOT NULL UNIQUE,
  phone_number VARCHAR(20) NULL,
  password_hash VARCHAR(255) NOT NULL,
  photo VARCHAR(255) NULL,
  profile_photo VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2. Tenants Table
-- Student and tenant accounts with profile completion and verification
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NULL,
  middle_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NULL,
  personal_email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  phone_number VARCHAR(16) NULL,
  facebook_link VARCHAR(255) NULL,
  date_of_birth DATE NULL,
  gender VARCHAR(20) NULL,
  university ENUM(
    'Universidad de Dagupan',
    'University of Pangasinan',
    'University of Luzon',
    'Lyceum Northwestern University'
  ) NULL,
  emergency_name VARCHAR(150) NULL,
  emergency_relationship VARCHAR(50) NULL,
  emergency_phone VARCHAR(30) NULL,
  profile_photo VARCHAR(255) NULL,
  school_id_file VARCHAR(255) NULL,
  gov_id_file VARCHAR(255) NULL,
  profile_completed TINYINT(1) NOT NULL DEFAULT 0,
  verification_status ENUM('unverified', 'pending', 'verified', 'rejected') NOT NULL DEFAULT 'unverified',
  feedback TEXT NULL,
  verified_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  photo VARCHAR(255) NULL,
  INDEX idx_tenants_university (university),
  INDEX idx_tenants_verification (verification_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 3. Landlords Table
-- Landlord accounts with consolidated application details & single-stage approval
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS landlords (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  middle_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NOT NULL,
  personal_email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  phone_number VARCHAR(20) NOT NULL,
  facebook_link VARCHAR(255) NULL,
  residential_address VARCHAR(255) NOT NULL,
  experience_summary TEXT NOT NULL,
  gov_id_file VARCHAR(255) NOT NULL,
  photo VARCHAR(255) NULL,
  profile_photo VARCHAR(255) NULL,
  status ENUM('pending', 'approved', 'declined') NOT NULL DEFAULT 'pending',
  feedback VARCHAR(500) NULL,
  decided_at TIMESTAMP NULL,
  responsibility_acknowledged_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_landlords_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 3.1 Landlord Applications Table
-- Applications submitted from the landing page by prospective landlords
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS landlord_applications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  middle_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NULL,
  phone VARCHAR(30) NOT NULL,
  facebook_link VARCHAR(255) NULL,
  residential_address VARCHAR(255) NOT NULL,
  experience_summary TEXT NOT NULL,
  government_id_file VARCHAR(255) NOT NULL,
  responsibility_acknowledged_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('pending', 'approved', 'declined') NOT NULL DEFAULT 'pending',
  feedback VARCHAR(500) NULL,
  invitation_token_hash CHAR(64) NULL,
  user_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  decided_at TIMESTAMP NULL,
  registered_at TIMESTAMP NULL,
  admin_email_status ENUM('unconfigured', 'sent', 'failed') NOT NULL DEFAULT 'unconfigured',
  admin_email_sent_at TIMESTAMP NULL,
  admin_review_token_hash CHAR(64) NULL,
  admin_review_token_expires_at DATETIME NULL,
  admin_review_token_used_at DATETIME NULL,
  UNIQUE KEY uq_landlord_application_invite (invitation_token_hash),
  UNIQUE KEY uq_landlord_application_admin_review (admin_review_token_hash),
  KEY idx_landlord_application_email (email),
  KEY idx_landlord_application_status (status),
  KEY idx_landlord_application_user (user_id),
  CONSTRAINT fk_landlord_application_user FOREIGN KEY (user_id) REFERENCES landlords(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 4. Dorms Table (formerly Properties)
-- Dormitories and student housing accommodations
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dorms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  landlord_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  university ENUM(
    'Universidad de Dagupan',
    'University of Pangasinan',
    'University of Luzon',
    'Lyceum Northwestern University'
  ) NOT NULL,
  address VARCHAR(255) NOT NULL,
  description TEXT NULL,
  photo TEXT NOT NULL,                   -- JSON array of file paths
  proof_of_ownership VARCHAR(255) NULL,  -- Document file path (PDF/Image)
  lat DECIMAL(9,6) NOT NULL,
  lng DECIMAL(9,6) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  approval_status ENUM('approved', 'pending', 'rejected') NOT NULL DEFAULT 'approved',
  common_school_pct INT NOT NULL DEFAULT 0,
  FOREIGN KEY (landlord_id) REFERENCES landlords(id) ON DELETE CASCADE,
  INDEX idx_dorms_university (university),
  INDEX idx_dorms_approval (approval_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 5. Rooms Table
-- Individual rooms and units within a dorm
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dorm_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  type ENUM('1', '2', '4', '6', '8', '10') NOT NULL,
  capacity TINYINT UNSIGNED NOT NULL,
  rent DECIMAL(10,2) NOT NULL,
  deposit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  occupants TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('Available', 'Occupied', 'Under Maintenance') NOT NULL DEFAULT 'Available',
  amenities LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL CHECK (json_valid(amenities)),
  photo TEXT NULL,                       -- JSON array of room images
  FOREIGN KEY (dorm_id) REFERENCES dorms(id) ON DELETE CASCADE,
  INDEX idx_rooms_dorm (dorm_id),
  INDEX idx_rooms_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 6. Bookings Table
-- Reservations and resident tenancies
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bookings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT NOT NULL,
  dorm_id INT NOT NULL,
  room_id INT NULL,
  status ENUM('pending', 'active', 'approved', 'upcoming', 'cancelled', 'rejected') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (dorm_id) REFERENCES dorms(id) ON DELETE CASCADE,
  FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
  INDEX idx_booking_dorm (dorm_id),
  INDEX idx_booking_tenant (tenant_id),
  INDEX idx_booking_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 7. Notifications Table
-- Real-time in-app alerts for tenants, landlords, and administrators
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role ENUM('tenant', 'landlord', 'admin') NOT NULL DEFAULT 'tenant',
  user_id INT NOT NULL,
  booking_id INT NULL,
  type ENUM('booking', 'approval', 'cancellation', 'message', 'system') NOT NULL DEFAULT 'booking',
  title VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notifications_role_user_read (role, user_id, is_read),
  INDEX idx_notifications_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 8. Notification Preferences Table
-- User-configurable email and alert settings
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_preferences (
  role ENUM('tenant', 'landlord') NOT NULL DEFAULT 'tenant',
  user_id INT NOT NULL,
  tenant_alerts TINYINT(1) NOT NULL DEFAULT 1,
  announcement_emails TINYINT(1) NOT NULL DEFAULT 1,
  booking_alerts TINYINT(1) NOT NULL DEFAULT 1,
  verification_alerts TINYINT(1) NOT NULL DEFAULT 1,
  summary_emails TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (role, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 9. Password Resets Table
-- Manages secure time-limited password recovery tokens
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role ENUM('tenant', 'landlord', 'admin') NOT NULL DEFAULT 'tenant',
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_password_resets_role_user (role, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 10. Subscribers Table
-- Newsletter and platform updates mailing list
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscribers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
