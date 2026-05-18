-- ============================================================
-- CCS Sit-in Monitoring System — COMBINED SETUP SQL
-- Generated from: setup.sql, setup_enhancements.sql,
--                 setup_lab_software.sql, add_feedback.sql,
--                 patch_security_fixes.sql
--
-- HOW TO USE:
--   1. Open phpMyAdmin
--   2. Click the "SQL" tab
--   3. Paste this entire file and click "Go"
--   Safe to run multiple times (uses IF NOT EXISTS / ADD COLUMN IF NOT EXISTS)
-- ============================================================


-- ============================================================
-- SECTION 1: BASE SETUP (setup.sql)
-- ============================================================

CREATE DATABASE IF NOT EXISTS ccs_sitin;
USE ccs_sitin;

-- =========================
-- ADMINS TABLE
-- =========================
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id VARCHAR(50) NOT NULL UNIQUE,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(200) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Default admin: admin@ccs.edu / password
INSERT IGNORE INTO admins (admin_id, username, email, password, full_name) VALUES 
('ADM001', 'admin', 'admin@ccs.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator');


-- =========================
-- STUDENTS TABLE
-- =========================
CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_number VARCHAR(50) NOT NULL UNIQUE,
    last_name VARCHAR(100) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100),
    course VARCHAR(100) NOT NULL,
    year_level VARCHAR(20) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    address TEXT NOT NULL,
    profile_picture VARCHAR(255) DEFAULT 'default.png',
    sessions INT DEFAULT 30,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


-- =========================
-- ANNOUNCEMENTS TABLE
-- =========================
CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_name VARCHAR(100) NOT NULL,
    announcement_date DATE NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO announcements (id, admin_name, announcement_date, message) VALUES 
(1, 'CCS Admin', '2026-02-11', 'Important Announcement: New system launched.'),
(2, 'CCS Admin', '2024-05-08', 'Welcome to the CCS Sit-in Monitoring System!');


-- =========================
-- SIT-IN TABLE
-- =========================
CREATE TABLE IF NOT EXISTS sit_in (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_number VARCHAR(50) NOT NULL,
    student_name VARCHAR(200) NOT NULL,
    purpose VARCHAR(100) NOT NULL,
    lab VARCHAR(50) NOT NULL,
    remaining_session INT NOT NULL,
    sit_in_date DATE NOT NULL,
    sit_in_time TIME NOT NULL,
    time_out TIME DEFAULT NULL,
    time_out_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


-- =========================
-- RESERVATIONS TABLE
-- =========================
CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_number VARCHAR(50) NOT NULL,
    student_name VARCHAR(200) NOT NULL,
    purpose VARCHAR(100) NOT NULL,
    lab VARCHAR(50) NOT NULL,
    reservation_date DATE NOT NULL,
    reservation_time TIME NOT NULL,
    status ENUM('pending','approved','rejected','expired','converted') DEFAULT 'pending',
    admin_note TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


-- =========================
-- NOTIFICATIONS TABLE
-- =========================
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info','success','warning','danger') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);


-- =========================
-- FEEDBACK TABLE
-- =========================
CREATE TABLE IF NOT EXISTS sit_in_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sit_in_id INT NOT NULL,
    student_id INT NOT NULL,
    id_number VARCHAR(50) NOT NULL,
    student_name VARCHAR(200) NOT NULL,
    lab VARCHAR(50) NOT NULL,
    rating TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    feedback_text TEXT DEFAULT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sitin_feedback (sit_in_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (sit_in_id) REFERENCES sit_in(id) ON DELETE CASCADE
);


-- =========================
-- LAB RULES TABLE
-- =========================
CREATE TABLE IF NOT EXISTS lab_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rule_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO lab_rules (id, rule_text) VALUES
(1, 'No food or drinks inside the laboratory.'),
(2, 'Maintain silence and proper behavior.'),
(3, 'Log out after using the computer.');


-- =========================
-- SIT-IN HISTORY TABLE
-- =========================
CREATE TABLE IF NOT EXISTS sit_in_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sit_in_id INT,
    student_id INT,
    action VARCHAR(100),
    action_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


-- ============================================================
-- SECTION 2: ENHANCEMENTS (setup_enhancements.sql)
-- ============================================================

-- =========================
-- LABS TABLE
-- =========================
CREATE TABLE IF NOT EXISTS labs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    lab_name    VARCHAR(50)  NOT NULL UNIQUE,
    description VARCHAR(200) DEFAULT NULL,
    `rows`      INT          NOT NULL DEFAULT 5,
    `cols`      INT          NOT NULL DEFAULT 8,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO labs (lab_name, description, `rows`, `cols`) VALUES
('524', 'Laboratory 524', 5, 8),
('526', 'Laboratory 526', 5, 8),
('528', 'Laboratory 528', 5, 8),
('530', 'Laboratory 530', 5, 8),
('542', 'Laboratory 542', 5, 8),
('544', 'Laboratory 544', 5, 8);


-- =========================
-- SEATS TABLE
-- =========================
CREATE TABLE IF NOT EXISTS seats (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    lab_id      INT         NOT NULL,
    seat_number INT         NOT NULL,
    row_pos     INT         NOT NULL,
    col_pos     INT         NOT NULL,
    is_active   TINYINT(1)  NOT NULL DEFAULT 1,
    created_at  TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lab_seat (lab_id, seat_number),
    FOREIGN KEY (lab_id) REFERENCES labs(id) ON DELETE CASCADE
);


-- =========================
-- SOFTWARE CATEGORIES TABLE
-- =========================
CREATE TABLE IF NOT EXISTS software_categories (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL UNIQUE,
    icon       VARCHAR(20)  DEFAULT '💿',
    sort_order INT          NOT NULL DEFAULT 0,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO software_categories (name, icon, sort_order) VALUES
('Web Browsers',        '🌐', 1),
('Programming IDEs',    '💻', 2),
('Database Management', '🗄', 3),
('Office Applications', '📄', 4),
('Media & Design',      '🎨', 5),
('Utilities',           '🔧', 6);


-- =========================
-- SOFTWARE TABLE
-- =========================
CREATE TABLE IF NOT EXISTS software (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT          NOT NULL,
    name        VARCHAR(150) NOT NULL,
    version     VARCHAR(50)  DEFAULT NULL,
    description VARCHAR(300) DEFAULT NULL,
    icon        VARCHAR(20)  DEFAULT '📦',
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES software_categories(id) ON DELETE CASCADE
);

INSERT IGNORE INTO software (category_id, name, version, icon) VALUES
(1, 'Google Chrome',        'Latest', '🌐'),
(1, 'Microsoft Edge',       'Latest', '🔵'),
(1, 'Mozilla Firefox',      'Latest', '🦊'),
(2, 'NetBeans IDE',         '21',     '🟠'),
(2, 'Visual Studio Code',   'Latest', '🔷'),
(2, 'Eclipse IDE',          'Latest', '🟣'),
(2, 'IntelliJ IDEA',        'Latest', '🧠'),
(3, 'MySQL Workbench',      '8.0',    '🐬'),
(3, 'phpMyAdmin',           'Latest', '🐘'),
(3, 'DBeaver',              'Latest', '🦦'),
(4, 'Microsoft Word',       '2021',   '📘'),
(4, 'Microsoft Excel',      '2021',   '📗'),
(4, 'Microsoft PowerPoint', '2021',   '📙'),
(4, 'LibreOffice',          'Latest', '📝');


-- =========================
-- LAB SOFTWARE TABLE
-- =========================
CREATE TABLE IF NOT EXISTS lab_software (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    lab_id      INT NOT NULL,
    software_id INT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lab_sw (lab_id, software_id),
    FOREIGN KEY (lab_id)      REFERENCES labs(id)     ON DELETE CASCADE,
    FOREIGN KEY (software_id) REFERENCES software(id) ON DELETE CASCADE
);


-- =========================
-- ADD COLUMNS TO RESERVATIONS
-- =========================
ALTER TABLE reservations
    ADD COLUMN IF NOT EXISTS seat_id     INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS seat_number INT DEFAULT NULL;


-- =========================
-- ADD SEAT_NUMBER TO SIT_IN
-- =========================
ALTER TABLE sit_in
    ADD COLUMN IF NOT EXISTS seat_number INT DEFAULT NULL;


-- ============================================================
-- SECTION 3: SECURITY & BUG-FIX PATCH (patch_security_fixes.sql)
-- ============================================================

-- Add reference_id to notifications (prevents duplicate expiry notices)
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS reference_id INT DEFAULT NULL;

-- Add seat_id to sit_in (for occupancy joins)
ALTER TABLE sit_in
    ADD COLUMN IF NOT EXISTS seat_id INT DEFAULT NULL;

-- Index on sit_in for fast active-session lookups
ALTER TABLE sit_in
    ADD INDEX IF NOT EXISTS idx_sit_in_active (id_number, time_out);

-- Index on notifications for dedup lookups
ALTER TABLE notifications
    ADD INDEX IF NOT EXISTS idx_notif_ref (student_id, title(50), reference_id);
