-- Run this in phpMyAdmin to add the feedback feature
-- Database: ccs_sitin

USE ccs_sitin;

CREATE TABLE IF NOT EXISTS sit_in_feedback (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    sit_in_id       INT NOT NULL,
    student_id      INT NOT NULL,
    id_number       VARCHAR(50) NOT NULL,
    student_name    VARCHAR(200) NOT NULL,
    lab             VARCHAR(50) NOT NULL,
    rating          TINYINT(1) NOT NULL CHECK (rating BETWEEN 1 AND 5),
    feedback_text   TEXT DEFAULT NULL,
    submitted_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sitin_feedback (sit_in_id)   -- one feedback per sit-in
);
