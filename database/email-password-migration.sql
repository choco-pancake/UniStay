USE unistay;

-- Migration: add the account columns used by the email/password sign-in flow.
-- Run ONCE on existing databases (fresh installs already get these from schema.sql).
-- Note: the legacy `email` column was later replaced by `personal_email`; see
-- database/restructure-users-safe-migration.sql.

ALTER TABLE users
  ADD COLUMN personal_email VARCHAR(190) NULL UNIQUE,
  ADD COLUMN password_hash VARCHAR(255) NULL;
