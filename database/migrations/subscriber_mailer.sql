-- ------------------------------------------------------------------------------
-- Subscriber mailer (newsletter welcome email, unsubscribe, queue, campaigns)
--
-- Run this once on an existing UniStay database:
--   C:\xampp\mysql\bin\mysql.exe -uroot unistay < database\migrations\2026_11_06_subscriber_mailer.sql
--
-- The application also upgrades the subscribers table automatically on the
-- first subscribe/admin visit (config/subscribers.php), so this file is only
-- needed if you want the columns and tables created ahead of time.
-- Fresh installs already get everything from database/schema.sql.
-- ------------------------------------------------------------------------------

ALTER TABLE subscribers
  ADD COLUMN status ENUM('pending','active','unsubscribed','bounced') NOT NULL DEFAULT 'active' AFTER email,
  ADD COLUMN source VARCHAR(40) NOT NULL DEFAULT 'landing_footer',
  ADD COLUMN confirm_token CHAR(64) NULL,
  ADD COLUMN confirm_expires_at DATETIME NULL,
  ADD COLUMN unsubscribe_token CHAR(64) NULL,
  ADD COLUMN ip_address VARCHAR(45) NULL,
  ADD COLUMN user_agent VARCHAR(255) NULL,
  ADD COLUMN consent_at DATETIME NULL,
  ADD COLUMN confirmed_at DATETIME NULL,
  ADD COLUMN unsubscribed_at DATETIME NULL,
  ADD COLUMN last_sent_at DATETIME NULL;

-- People who subscribed before double opt-in existed stay subscribed.
ALTER TABLE subscribers ALTER COLUMN status SET DEFAULT 'pending';
UPDATE subscribers SET confirmed_at = created_at WHERE status = 'active' AND confirmed_at IS NULL;

ALTER TABLE subscribers
  ADD INDEX idx_subscribers_status (status),
  ADD UNIQUE INDEX uniq_subscribers_unsubscribe_token (unsubscribe_token);

CREATE TABLE IF NOT EXISTS subscribe_attempts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_subscribe_attempts_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
