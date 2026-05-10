<?php
session_start();
require_once __DIR__ . '/csrf.php';
csrf_verify();

include 'connect.php';

$email    = trim($_POST['email']    ?? '');
$password = $_POST['password'] ?? '';

// ── Brute-force protection ────────────────────────────────────────────
// Track failed attempts in session; lock for 15 min after 5 failures
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_locked_until'] = 0;
}

if (time() < $_SESSION['login_locked_until']) {
    $wait = ceil(($_SESSION['login_locked_until'] - time()) / 60);
    header("Location: ../login.php?error=locked&wait=$wait");
    exit;
}

// ── Check admin ───────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM admins WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row && password_verify($password, $row['password'])) {
    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);
    $_SESSION['login_attempts']     = 0;
    $_SESSION['login_locked_until'] = 0;

    $_SESSION['admin_id']          = $row['id'];
    $_SESSION['admin_id_number']   = $row['admin_id'];
    $_SESSION['admin_username']    = $row['username'];
    $_SESSION['admin_full_name']   = $row['full_name'];
    $_SESSION['admin_email']       = $row['email'];
    $_SESSION['is_admin']          = true;

    $conn->close();
    header("Location: ../admin_dashboard.php");
    exit;
}

// ── Check student ─────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM students WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row && password_verify($password, $row['password'])) {
    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);
    $_SESSION['login_attempts']     = 0;
    $_SESSION['login_locked_until'] = 0;

    $_SESSION['student_id']      = $row['id'];
    $_SESSION['id_number']       = $row['id_number'];
    $_SESSION['last_name']       = $row['last_name'];
    $_SESSION['first_name']      = $row['first_name'];
    $_SESSION['middle_name']     = $row['middle_name'];
    $_SESSION['course']          = $row['course'];
    $_SESSION['year_level']      = $row['year_level'];
    $_SESSION['email']           = $row['email'];
    $_SESSION['address']         = $row['address'];
    $_SESSION['profile_picture'] = $row['profile_picture'];
    $_SESSION['sessions']        = $row['sessions'] ?? 30;
    $_SESSION['is_admin']        = false;

    $conn->close();
    header("Location: ../userdb.php");
    exit;
}

// ── Failed login ──────────────────────────────────────────────────────
$_SESSION['login_attempts']++;
if ($_SESSION['login_attempts'] >= 5) {
    $_SESSION['login_locked_until'] = time() + (15 * 60); // 15 min lockout
    $_SESSION['login_attempts']     = 0;
    header("Location: ../login.php?error=locked&wait=15");
    exit;
}

$conn->close();
header("Location: ../login.php?error=1");
exit;
