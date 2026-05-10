<?php
session_start();
require_once __DIR__ . '/csrf.php';
csrf_verify();

if (!isset($_SESSION['student_id'])) {
    header("Location: ../login.php");
    exit;
}

include 'connect.php';

$student_id  = (int) $_SESSION['student_id'];
$last_name   = mb_substr(trim($_POST['last_name']   ?? ''), 0, 100);
$first_name  = mb_substr(trim($_POST['first_name']  ?? ''), 0, 100);
$middle_name = mb_substr(trim($_POST['middle_name'] ?? ''), 0, 100);
$course      = mb_substr(trim($_POST['course']      ?? ''), 0, 100);
$year_level  = mb_substr(trim($_POST['year_level']  ?? ''), 0, 20);
$email       = mb_substr(trim($_POST['email']       ?? ''), 0, 100);
$address     = mb_substr(trim($_POST['address']     ?? ''), 0, 500);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: ../edit_profile.php?error=invalid_email");
    exit;
}

$current_picture = $_SESSION['profile_picture'] ?? 'default.png';

// ── File upload with real MIME validation ─────────────────────────────
if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
    $file        = $_FILES['profile_picture'];
    $max_size    = 2 * 1024 * 1024; // 2 MB

    if ($file['size'] > $max_size) {
        header("Location: ../edit_profile.php?error=file_too_large");
        exit;
    }

    // Read actual magic bytes — do NOT trust $_FILES['type'] (browser-supplied)
    $finfo     = new finfo(FILEINFO_MIME_TYPE);
    $mime      = $finfo->file($file['tmp_name']);
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $mime_ext_map  = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    if (!in_array($mime, $allowed_mimes, true)) {
        header("Location: ../edit_profile.php?error=invalid_file_type");
        exit;
    }

    // Generate a safe, unpredictable filename — never use the original name
    $ext         = $mime_ext_map[$mime];
    $newFileName = $student_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest        = '../profile_pictures/' . $newFileName;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        // Delete old picture (but never delete the shared default)
        if (
            $current_picture &&
            $current_picture !== 'default.png' &&
            file_exists('../profile_pictures/' . $current_picture)
        ) {
            unlink('../profile_pictures/' . $current_picture);
        }
        $current_picture = $newFileName;
    }
}

// ── Update DB ─────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "UPDATE students
     SET last_name=?, first_name=?, middle_name=?, course=?, year_level=?, email=?, address=?, profile_picture=?
     WHERE id=?"
);
$stmt->bind_param(
    "ssssssssi",
    $last_name, $first_name, $middle_name, $course,
    $year_level, $email, $address, $current_picture, $student_id
);

if ($stmt->execute()) {
    $_SESSION['last_name']       = $last_name;
    $_SESSION['first_name']      = $first_name;
    $_SESSION['middle_name']     = $middle_name;
    $_SESSION['course']          = $course;
    $_SESSION['year_level']      = $year_level;
    $_SESSION['email']           = $email;
    $_SESSION['address']         = $address;
    $_SESSION['profile_picture'] = $current_picture;

    $stmt->close();
    $conn->close();
    header("Location: ../userdb.php?success=1");
    exit;
} else {
    error_log('Profile update error: ' . $stmt->error);
    $stmt->close();
    $conn->close();
    header("Location: ../edit_profile.php?error=server");
    exit;
}
