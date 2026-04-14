-- Create database in XAMPP MySQL
-- Run this in phpMyAdmin (http://localhost/phpmyadmin)

CREATE DATABASE IF NOT EXISTS ccs_sitin;

USE ccs_sitin;

-- Create admin table
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id VARCHAR(50) NOT NULL UNIQUE,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(200) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO admins (admin_id, username, email, password, full_name) VALUES 
('ADM001', 'admin', 'admin@ccs.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator');

-- Create students table
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

-- Create announcements table
CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_name VARCHAR(100) NOT NULL,
    announcement_date DATE NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert sample announcements
INSERT INTO announcements (admin_name, announcement_date, message) VALUES 
('CCS Admin', '2026-02-11', 'Important Announcement: We are excited to announce the launch of our new website. Explore our latest products and services now.'),
('CCS Admin', '2024-05-08', 'Welcome to the CCS Sit-in Monitoring System! Please remember to log your sit-in sessions regularly.');

-- Create sit_in table
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

-- Create reservations table
CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_number VARCHAR(50) NOT NULL,
    student_name VARCHAR(200) NOT NULL,
    purpose VARCHAR(100) NOT NULL,
    lab VARCHAR(50) NOT NULL,
    reservation_date DATE NOT NULL,
    reservation_time TIME NOT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    admin_note TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create notifications table
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info','success','warning','danger') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE sit_in ADD COLUMN time_out TIMESTAMP NULL;
CREATE INDEX idx_sit_in_active ON sit_in(id_number, sit_in_date, time_out);