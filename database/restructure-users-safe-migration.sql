USE unistay;

-- Safe migration: restructure users table based on current schema state.
-- This will drop legacy columns (name, email, messenger_link) and add facebook_link,
-- while preserving data where possible.

-- Add missing columns if they don't exist
SET @add_fb := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA='unistay' AND TABLE_NAME='users' AND COLUMN_NAME='facebook_link'
);
SET @stmt := IF(@add_fb = 0, 'ALTER TABLE users ADD COLUMN facebook_link VARCHAR(255) NULL AFTER phone_number', 'SELECT 1');
PREPARE s1 FROM @stmt; EXECUTE s1; DEALLOCATE PREPARE s1;

-- Move email -> personal_email if personal_email is empty and email exists
UPDATE users SET personal_email = email WHERE (personal_email IS NULL OR personal_email = '') AND email IS NOT NULL;

-- Move messenger_link -> facebook_link if facebook_link is empty and messenger_link exists
UPDATE users SET facebook_link = messenger_link WHERE (facebook_link IS NULL OR facebook_link = '') AND messenger_link IS NOT NULL;

-- Split name into first/middle/last if still mostly empty
UPDATE users
SET
  first_name = COALESCE(first_name, TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(name, ' ', 1), ' ', -1))),
  last_name = COALESCE(last_name, TRIM(SUBSTRING_INDEX(name, ' ', -1))),
  middle_name = COALESCE(middle_name,
    CASE
      WHEN (LENGTH(name) - LENGTH(REPLACE(name, ' ', ''))) >= 2
        THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(name, ' ', 3), ' ', -1))
      ELSE NULL
    END)
WHERE name IS NOT NULL AND name <> '';

-- Drop old columns if they exist
SET @has_name := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA='unistay' AND TABLE_NAME='users' AND COLUMN_NAME='name'
);
SET @stmt := IF(@has_name > 0, 'ALTER TABLE users DROP COLUMN name', 'SELECT 1');
PREPARE s1 FROM @stmt; EXECUTE s1; DEALLOCATE PREPARE s1;

SET @has_email := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA='unistay' AND TABLE_NAME='users' AND COLUMN_NAME='email'
);
SET @stmt := IF(@has_email > 0, 'ALTER TABLE users DROP COLUMN email', 'SELECT 1');
PREPARE s1 FROM @stmt; EXECUTE s1; DEALLOCATE PREPARE s1;

SET @has_msg := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA='unistay' AND TABLE_NAME='users' AND COLUMN_NAME='messenger_link'
);
SET @stmt := IF(@has_msg > 0, 'ALTER TABLE users DROP COLUMN messenger_link', 'SELECT 1');
PREPARE s1 FROM @stmt; EXECUTE s1; DEALLOCATE PREPARE s1;

-- Ensure unique index on personal_email
ALTER TABLE users ADD UNIQUE INDEX IF NOT EXISTS personal_email (personal_email);
