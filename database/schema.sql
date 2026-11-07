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
  email VARCHAR(190) NOT NULL UNIQUE,
  phone_number VARCHAR(20) NULL,
  password_hash VARCHAR(255) NOT NULL,
  profile_photo TEXT NULL,
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
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  phone_number VARCHAR(16) NULL,
  facebook_link VARCHAR(255) NULL,
  university ENUM(
    'Universidad de Dagupan',
    'University of Pangasinan',
    'University of Luzon',
    'Lyceum Northwestern University'
  ) NULL,
  emergency_name VARCHAR(150) NULL,
  emergency_relationship VARCHAR(50) NULL,
  emergency_phone VARCHAR(30) NULL,
  profile_photo TEXT NULL,
  school_id TEXT NULL,
  gov_id TEXT NULL,
  profile_completed TINYINT(1) NOT NULL DEFAULT 0,
  verification_status ENUM('unverified', 'pending', 'verified', 'rejected') NOT NULL DEFAULT 'unverified',
  feedback TEXT NULL,
  verified_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  phone_number VARCHAR(20) NOT NULL,
  facebook_link VARCHAR(255) NULL,
  residential_address VARCHAR(255) NOT NULL,
  experience_summary TEXT NOT NULL,
  gov_id TEXT NULL,
  profile_photo TEXT NULL,
  status ENUM('pending', 'approved', 'declined') NOT NULL DEFAULT 'pending',
  feedback TEXT NULL,
  decided_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_landlords_status (status)
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
  proof_of_ownership TEXT NULL,          -- Document file path (PDF/Image)
  business_permit TEXT NULL,             -- Business / Mayor's permit file path (PDF/Image)
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
  occupancy ENUM('1', '2', '4', '6', '8', '10') NOT NULL DEFAULT '1',
  rent DECIMAL(10,2) NOT NULL,
  deposit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status ENUM('Available', 'Occupied') NOT NULL DEFAULT 'Available',
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
  status ENUM('pending', 'approved', 'cancelled', 'rejected') NOT NULL DEFAULT 'pending',
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
-- Newsletter and platform updates mailing list (independent of user accounts)
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

-- ------------------------------------------------------------------------------
-- 11. Subscribe Attempts Table
-- Small per-IP counter used to rate limit the public subscribe endpoint
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscribe_attempts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_subscribe_attempts_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 12. Email Queue Table
-- Every subscriber mail is queued here and sent by tools/send-mail-queue.php
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_queue (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  subscriber_id INT NULL,
  campaign_id INT NULL,
  template VARCHAR(32) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
  attempts TINYINT NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  provider_message_id VARCHAR(191) NULL,
  scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email_queue_status (status, scheduled_at),
  INDEX idx_email_queue_campaign (campaign_id),
  INDEX idx_email_queue_subscriber (subscriber_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 13. Email Logs Table
-- Delivery history for every queued message
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  subscriber_id INT NULL,
  email VARCHAR(190) NOT NULL,
  template VARCHAR(32) NOT NULL,
  status VARCHAR(16) NOT NULL,
  provider_message_id VARCHAR(191) NULL,
  error VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email_logs_email (email),
  INDEX idx_email_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 14. Campaigns Table
-- Promotional emails composed from the admin Subscribers page
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS campaigns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  heading VARCHAR(255) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  cta_text VARCHAR(80) NOT NULL DEFAULT 'Explore UniStay',
  cta_url VARCHAR(255) NOT NULL DEFAULT '',
  status ENUM('draft','sending','sent') NOT NULL DEFAULT 'draft',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
