-- Migration: Add approval_status to properties table for UniStay
USE unistay;

ALTER TABLE properties 
ADD COLUMN IF NOT EXISTS approval_status ENUM('approved', 'pending', 'rejected') NOT NULL DEFAULT 'approved';

-- Set some sample properties to different statuses for realistic testing
-- E.g. dorm 105 as 'pending', or one as 'rejected'
UPDATE properties SET approval_status = 'approved' WHERE id IN (101, 102);
UPDATE properties SET approval_status = 'pending' WHERE id = 103;
UPDATE properties SET approval_status = 'rejected' WHERE id = 104;
UPDATE properties SET approval_status = 'pending' WHERE id = 105;
