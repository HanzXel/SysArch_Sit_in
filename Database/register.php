<?php
session_start();
require_once __DIR__ . '/csrf.php';
csrf_verify();           // blocks forged POST requests

include 'connect.php';

// ── Sanitize & trim all inputs ────────────────────────────────────────
$id_number      = mb_substr(trim($_POST['id_number']      ?? ''), 0, 50);
$last_name      = mb_substr(trim($_POST['last_name']      ?? ''), 0, 100);
$first_name     = mb_substr(trim($_POST['first_name']     ?? ''), 0, 100);
$middle_name    = mb_substr(trim($_POST['middle_name']    ?? ''), 0, 100);
$course         = mb_substr(trim($_POST['course']         ?? ''), 0, 100);
$year_level     = mb_substr(trim($_POST['year_level']     ?? ''), 0, 20);
$email          = mb_substr(trim($_POST['email']          ?? ''), 0, 100);
$password       = $_POST['password']         ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';
$address        = mb_substr(trim($_POST['address']        ?? ''), 0, 500);

// ── Basic validation ──────────────────────────────────────────────────
if ($password !== $confirm_password) {
    header("Location: ../registration.php?error=password_mismatch");
    exit;
}

if (strlen($password) < 8) {
    header("Location: ../registration.php?error=password_short");
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: ../registration.php?error=invalid_email");
    exit;
}

// ── Check duplicate ID number ─────────────────────────────────────────
$stmt = $conn->prepare("SELECT id FROM students WHERE id_number = ?");
$stmt->bind_param("s", $id_number);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    $stmt->close();
    $conn->close();
    header("Location: ../registration.php?error=id_exists");
    exit;
}
$stmt->close();

// ── Check duplicate email ─────────────────────────────────────────────
$stmt = $conn->prepare("SELECT id FROM students WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    $stmt->close();
    $conn->close();
    header("Location: ../registration.php?error=email_exists");
    exit;
}
$stmt->close();

// ── Hash password and insert ──────────────────────────────────────────
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare(
    "INSERT INTO students (id_number, last_name, first_name, middle_name, course, year_level, email, password, address, sessions)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 30)"
);
$stmt->bind_param(
    "sssssssss",
    $id_number, $last_name, $first_name, $middle_name,
    $course, $year_level, $email, $hashed_password, $address
);

if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    header("Location: ../login.php?registered=1");
    exit;
} else {
    error_log('Register error: ' . $stmt->error);
    $stmt->close();
    $conn->close();
    header("Location: ../registration.php?error=server");
    exit;
}
