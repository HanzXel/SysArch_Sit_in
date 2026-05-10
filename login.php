<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — CCS Sit-in Monitoring System</title>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/png" href="pictures/uclogo.png">
</head>
<body class="login-page">

<?php
session_start();
require_once 'Database/csrf.php';

// Map error codes to human-readable messages
$error_messages = [
    '1'              => 'Invalid email or password. Please try again.',
    'locked'         => 'Too many failed attempts. Please wait ' . (intval($_GET['wait'] ?? 15)) . ' minute(s) before trying again.',
    'password_mismatch' => 'Passwords do not match.',
    'password_short' => 'Password must be at least 8 characters.',
    'invalid_email'  => 'Please enter a valid email address.',
    'id_exists'      => 'That ID number is already registered.',
    'email_exists'   => 'That email address is already registered.',
    'server'         => 'A server error occurred. Please try again.',
];
$error_code = $_GET['error'] ?? '';
$error_msg  = $error_messages[$error_code] ?? '';
?>

<nav class="navbar">
    <div class="nav-left">
        <img class="logo_landing" src="pictures/uclogo.png" alt="UC Logo">
        CCS Sit-in Monitoring System
    </div>
    <ul class="nav-right">
        <li><a href="landing.php">Home</a></li>
        <li><a href="login.php">Login</a></li>
        <li><a href="registration.php">Register</a></li>
    </ul>
</nav>

<div class="content">
    <div class="login-container">
        <h2>Welcome Back</h2>
        <p class="auth-subtitle">Sign in to your account to continue</p>

        <?php if ($error_msg): ?>
        <div class="error-message"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['registered'])): ?>
        <div class="success-message" style="display:block">
            Account created successfully! You can now sign in.
        </div>
        <?php endif; ?>

        <form action="Database/login.php" method="POST">
            <?php echo csrf_token(); ?>

            <label>Email Address</label>
            <input type="email" name="email" placeholder="example@gmail.com"
                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                   required autocomplete="email">

            <label>Password</label>
            <input type="password" name="password" placeholder="Enter your password"
                   required autocomplete="current-password">

            <button type="submit">Sign In</button>
        </form>

        <p class="auth-footer">
            Don't have an account? <a href="registration.php">Register here</a>
        </p>
    </div>
</div>

</body>
</html>
