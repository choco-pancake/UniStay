-- Distinguish landlord-ended tenancies from tenant-cancelled stays.
-- This ALTER is safe to re-run because it declares the complete target enum.
ALTER TABLE bookings
  MODIFY COLUMN status ENUM('pending', 'approved', 'declined', 'cancelled', 'ended')
  NOT NULL DEFAULT 'pending';