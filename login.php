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

<nav class="navbar">
    <div class="nav-left">
        <img class="logo_landing" src="pictures/uclogo.png" alt="UC Logo">
        CCS Sit-in Monitoring System
    </div>
    <ul class="nav-right">
        <li><a href="landing.php">Home</a></li>
        <li class="dropdown">
            <a href="#" class="dropbtn">Community ▾</a>
            <div class="dropdown-content">
                <a href="#">Announcements</a>
                <a href="#">Events</a>
                <a href="#">Forums</a>
                <a href="#">Guidelines</a>
            </div>
        </li>
        <li><a href="#">About</a></li>
        <li><a href="login.php">Login</a></li>
        <li><a href="registration.php">Register</a></li>
    </ul>
</nav>

<div class="content">
    <div class="login-container">
        <h2>Welcome Back</h2>
        <p class="auth-subtitle">Sign in to your account to continue</p>

        <?php if(isset($_GET['error'])): ?>
        <div class="error-message">Invalid email or password. Please try again.</div>
        <?php endif; ?>

        <form action="Database/login.php" method="POST">
            <label>Email Address</label>
            <input type="email" name="email" placeholder="example@gmail.com" required>

            <label>Password</label>
            <input type="password" name="password" placeholder="Enter your password" required>

            <button type="submit">Sign In</button>
        </form>

        <p class="auth-footer">
            Don't have an account? <a href="registration.php">Register here</a>
        </p>
    </div>
</div>

</body>
</html>
