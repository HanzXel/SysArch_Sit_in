<?php
date_default_timezone_set('Asia/Manila');

// Load credentials from config file (outside web root ideally, or same folder)
$cfg_path = __DIR__ . '/config.php';
if (!file_exists($cfg_path)) {
    // Fallback for local dev — copy config.example.php to config.php
    die('Database config file not found. Copy Database/config.example.php to Database/config.php and fill in your credentials.');
}
$cfg = require $cfg_path;

$conn = new mysqli($cfg['host'], $cfg['username'], $cfg['password'], $cfg['dbname']);

if ($conn->connect_error) {
    // Don't expose the raw error to the browser in production
    error_log('DB connect error: ' . $conn->connect_error);
    die('Database connection failed. Please contact the administrator.');
}

// Set charset to utf8mb4 to handle all unicode characters safely
$conn->set_charset($cfg['charset'] ?? 'utf8mb4');
