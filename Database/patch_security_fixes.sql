-- ============================================================
-- CCS Sit-in — Security & Bug-fix Patch
-- Run this in phpMyAdmin AFTER your base setup.sql
-- Safe to run multiple times (uses IF NOT EXISTS / ADD COLUMN IF NOT EXISTS)
-- ============================================================
USE ccs_sitin;

-- 1. Add reference_id to notifications so expiry notices aren't duplicated
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS reference_id INT DEFAULT NULL;

-- 2. Add seat_id to sit_in so occupancy joins on ID, not lab-name + seat-number string
ALTER TABLE sit_in
    ADD COLUMN IF NOT EXISTS seat_id INT DEFAULT NULL;

-- 3. Expand reservations status enum to include expired + converted
ALTER TABLE reservations
    MODIFY COLUMN status ENUM('pending','approved','rejected','expired','converted') DEFAULT 'pending';

-- 4. Expand admin_sitin_feedback with lab column if missing
ALTER TABLE admin_sitin_feedback
    ADD COLUMN IF NOT EXISTS lab VARCHAR(50) NOT NULL DEFAULT '';

-- Backfill lab from sit_in for existing rows
UPDATE admin_sitin_feedback af
JOIN sit_in s ON s.id = af.sit_in_id
SET af.lab = s.lab
WHERE af.lab = '' OR af.lab IS NULL;

-- 5. Index on sit_in.time_out IS NULL for fast active-session lookups
-- (partial indexes not supported in MySQL < 8, so we index time_out column)
ALTER TABLE sit_in
    ADD INDEX IF NOT EXISTS idx_sit_in_active (id_number, time_out);

-- 6. Index on notifications reference_id for dedup lookups
ALTER TABLE notifications
    ADD INDEX IF NOT EXISTS idx_notif_ref (student_id, title(50), reference_id);
