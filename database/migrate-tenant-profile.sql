-- Migration: tenant profile / onboarding columns on the users table.
-- Run ONCE on existing databases (fresh installs already get these from schema.sql).

ALTER TABLE users
  ADD COLUMN first_name VARCHAR(100) NULL AFTER name,
  ADD COLUMN middle_name VARCHAR(100) NULL AFTER first_name,
  ADD COLUMN last_name VARCHAR(100) NULL AFTER middle_name,
  ADD COLUMN phone_number VARCHAR(30) NULL AFTER last_name,
  ADD COLUMN messenger_link VARCHAR(255) NULL AFTER phone_number,
  ADD COLUMN personal_email VARCHAR(190) NULL AFTER messenger_link,
  ADD COLUMN date_of_birth DATE NULL AFTER personal_email,
  ADD COLUMN gender VARCHAR(20) NULL AFTER date_of_birth,
  ADD COLUMN school VARCHAR(150) NULL AFTER gender,
  ADD COLUMN emergency_name VARCHAR(150) NULL AFTER school,
  ADD COLUMN emergency_relationship VARCHAR(50) NULL AFTER emergency_name,
  ADD COLUMN emergency_phone VARCHAR(30) NULL AFTER emergency_relationship,
  ADD COLUMN profile_photo VARCHAR(255) NULL AFTER emergency_phone,
  ADD COLUMN school_id_file VARCHAR(255) NULL AFTER profile_photo,
  ADD COLUMN gov_id_file VARCHAR(255) NULL AFTER school_id_file,
  ADD COLUMN profile_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER gov_id_file;
