-- ============================================================
-- CCS Sit-in Monitoring System — Enhancement SQL (FIXED v2)
-- Run this in phpMyAdmin after the base setup.sql
-- ============================================================
USE ccs_sitin;

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
-- SEAT COLUMNS ON RESERVATIONS
-- =========================
ALTER TABLE reservations
    ADD COLUMN IF NOT EXISTS seat_id     INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS seat_number INT DEFAULT NULL;

-- =========================
-- SEAT COLUMN ON SIT_IN
-- =========================
ALTER TABLE sit_in
    ADD COLUMN IF NOT EXISTS seat_number INT DEFAULT NULL;

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

CREATE TABLE IF NOT EXISTS lab_software (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    lab_id      INT NOT NULL,
    software_id INT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lab_sw (lab_id, software_id),
    FOREIGN KEY (lab_id)      REFERENCES labs(id)     ON DELETE CASCADE,
    FOREIGN KEY (software_id) REFERENCES software(id) ON DELETE CASCADE
);