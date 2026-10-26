USE unistay;

-- Migration: Ensure users.last_name is nullable and defaults to NULL.
-- Supports users signing up via Google or elsewhere who only provide a single name (mononym).

ALTER TABLE users
  MODIFY COLUMN last_name VARCHAR(100) NULL DEFAULT NULL;
