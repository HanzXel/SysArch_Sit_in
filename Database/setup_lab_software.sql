-- ============================================================
-- CCS Sit-in — Software per Lab Enhancement
-- Run this in phpMyAdmin AFTER setup_enhancements_v2.sql
-- ============================================================
USE ccs_sitin;

-- Links software items to specific labs
CREATE TABLE IF NOT EXISTS lab_software (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    lab_id      INT NOT NULL,
    software_id INT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lab_sw (lab_id, software_id),
    FOREIGN KEY (lab_id)      REFERENCES labs(id)     ON DELETE CASCADE,
    FOREIGN KEY (software_id) REFERENCES software(id) ON DELETE CASCADE
);
