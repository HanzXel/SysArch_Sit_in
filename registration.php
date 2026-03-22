<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — CCS Sit-in Monitoring System</title>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/png" href="pictures/uclogo.png">
</head>
<body class="register-page">

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
    <div class="form-container">
        <h2>Create Account</h2>
        <p class="auth-subtitle">Fill in your details to register</p>

        <form action="Database/register.php" method="POST" onsubmit="return validateForm()">

            <label>ID Number</label>
            <input type="text" name="id_number" placeholder="Enter your ID number" required>

            <label>Last Name</label>
            <input type="text" name="last_name" placeholder="Enter last name" required>

            <label>First Name</label>
            <input type="text" name="first_name" placeholder="Enter first name" required>

            <label>Middle Name <span style="color:var(--gray-300);font-weight:400">(optional)</span></label>
            <input type="text" name="middle_name" placeholder="Enter middle name">

            <label>Course</label>
            <select name="course" required>
                <option value="">— Select Course —</option>
                <option value="BS Accountancy">BS Accountancy</option>
                <option value="BS Business Administration">BS Business Administration</option>
                <option value="BS Computer Science">BS Computer Science</option>
                <option value="BS Information Technology">BS Information Technology</option>
                <option value="BS Computer Engineering">BS Computer Engineering</option>
                <option value="BS Criminology">BS Criminology</option>
                <option value="BS Civil Engineering">BS Civil Engineering</option>
                <option value="BS Electrical Engineering">BS Electrical Engineering</option>
                <option value="BS Mechanical Engineering">BS Mechanical Engineering</option>
                <option value="BS Industrial Engineering">BS Industrial Engineering</option>
                <option value="BS Commerce">BS Commerce</option>
                <option value="BS Hotel & Restaurant Management">BS Hotel & Restaurant Management</option>
                <option value="BS Tourism Management">BS Tourism Management</option>
                <option value="BS Elementary Education">BS Elementary Education</option>
                <option value="BS Secondary Education">BS Secondary Education</option>
                <option value="BS Customs Administration">BS Customs Administration</option>
                <option value="BS Industrial Psychology">BS Industrial Psychology</option>
                <option value="BS Real Estate Management">BS Real Estate Management</option>
                <option value="BS Office Administration">BS Office Administration</option>
            </select>

            <label>Year Level</label>
            <select name="year_level" required>
                <option value="">— Select Year —</option>
                <option>1st Year</option>
                <option>2nd Year</option>
                <option>3rd Year</option>
                <option>4th Year</option>
            </select>

            <label>Email Address</label>
            <input type="email" name="email" placeholder="example@gmail.com" required>

            <label>Password</label>
            <input type="password" name="password" required>

            <label>Confirm Password</label>
            <input type="password" name="confirm_password" required>

            <label>Address</label>
            <textarea rows="3" name="address" placeholder="Enter your address" required></textarea>

            <button type="submit">Create Account</button>

            <div id="successMessage" class="success-message">
                Account created successfully! Redirecting to login...
            </div>
        </form>

        <p class="auth-footer">
            Already have an account? <a href="login.php">Sign in</a>
        </p>
    </div>
</div>

<script>
function validateForm() {
    const password = document.getElementsByName('password')[0].value;
    const confirm  = document.getElementsByName('confirm_password')[0].value;
    if (password !== confirm) {
        alert('Passwords do not match!');
        return false;
    }
    return true;
}
</script>

</body>
</html>
