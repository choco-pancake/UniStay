USE unistay;

-- Migration: restructure users table (split name into first/middle/last,
-- move email to personal_email, replace messenger_link with facebook_link,
-- drop name and email columns).
-- Run ONCE on existing databases.

ALTER TABLE users
  ADD COLUMN first_name VARCHAR(100) NULL AFTER password_hash,
  ADD COLUMN middle_name VARCHAR(100) NULL AFTER first_name,
  ADD COLUMN last_name VARCHAR(100) NULL AFTER middle_name,
  ADD COLUMN phone_number VARCHAR(30) NULL AFTER last_name,
  ADD COLUMN facebook_link VARCHAR(255) NULL AFTER phone_number,
  ADD COLUMN personal_email VARCHAR(190) NULL AFTER facebook_link;

UPDATE users SET 
  first_name = TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(name, ' ', 1), ' ', -1)),
  last_name = TRIM(SUBSTRING_INDEX(name, ' ', -1)),
  middle_name = CASE 
    WHEN (LENGTH(name) - LENGTH(REPLACE(name, ' ', ''))) >= 2 
    THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(name, ' ', 3), ' ', -1))
    ELSE NULL
  END,
  personal_email = email,
  phone_number = phone_number
WHERE (first_name IS NULL OR first_name = '');

UPDATE users SET facebook_link = messenger_link WHERE facebook_link IS NULL AND messenger_link IS NOT NULL;

ALTER TABLE users 
  DROP INDEX email,
  DROP COLUMN name,
  DROP COLUMN email,
  DROP COLUMN messenger_link;

ALTER TABLE users ADD UNIQUE INDEX (personal_email);
