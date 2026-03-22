<?php
session_start();
if(!isset($_SESSION['student_id'])){
    header("Location: login.php");
    exit;
}

include 'Database/connect.php';

// Fetch latest announcements from DB
$announcements = [];
$stmt = $conn->prepare("SELECT * FROM announcements ORDER BY created_at DESC LIMIT 5");
$stmt->execute();
$result = $stmt->get_result();
while($row = $result->fetch_assoc()){
    $announcements[] = $row;
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — CCS Sit-in Monitoring</title>
<link rel="stylesheet" href="userdb.css">
<link rel="icon" type="image/png" href="pictures/uclogo.png">
</head>
<body>

<nav class="dashboard-navbar">
    <div class="dashboard-left">Dashboard</div>
    <ul class="dashboard-right">
        <li><a href="notifications.php">Notification</a></li>
        <li><a href="userdb.php">Home</a></li>
        <li><a href="edit_profile.php">Edit Profile</a></li>
        <li><a href="history.php">History</a></li>
        <li><a href="student_reservation.php">Reservation</a></li>
        <li><a href="logout.php" class="logout-btn">Log Out</a></li>
    </ul>
</nav>

<div class="dashboard-container">

    <!-- LEFT PANEL -->
    <aside class="student-info">
        <div class="student-header">Student Information</div>

        <div class="student-profile">
            <img src="profile_pictures/<?php echo $_SESSION['profile_picture'] ?? 'default.png'; ?>" alt="Profile Picture">
            <div class="student-name"><?php echo $_SESSION['first_name'] . ' ' . $_SESSION['last_name']; ?></div>
            <div class="student-course-tag"><?php echo $_SESSION['course']; ?></div>
        </div>

        <div class="student-details">
            <div class="detail-row">
                <span class="detail-label">ID Number</span>
                <span class="detail-value"><?php echo $_SESSION['id_number']; ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Full Name</span>
                <span class="detail-value"><?php echo $_SESSION['first_name'] . ' ' . $_SESSION['middle_name'] . ' ' . $_SESSION['last_name']; ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Year Level</span>
                <span class="detail-value"><?php echo $_SESSION['year_level']; ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Email</span>
                <span class="detail-value"><?php echo $_SESSION['email']; ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Address</span>
                <span class="detail-value"><?php echo $_SESSION['address']; ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Remaining Sessions</span>
                <span class="detail-value">
                    <span class="sessions-badge"><?php echo $_SESSION['sessions'] ?? 30; ?> sessions</span>
                </span>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="dashboard-main">

        <?php if(isset($_GET['success'])): ?>
        <div style="padding:14px 20px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);color:#15803d;border-radius:10px;font-size:14px;font-weight:500;">
            ✓ Profile updated successfully.
        </div>
        <?php endif; ?>

        <!-- Rules -->
        <div class="dashboard-card">
            <div class="card-header">Rules and Regulations</div>
            <div class="card-body">
                <div class="rules-content">
                    <h3>University of Cebu</h3>
                    <h4>College of Information &amp; Computer Studies</h4>
                    <div class="rules-title">Laboratory Rules and Regulations</div>
                    <p>To avoid embarrassment and maintain camaraderie with your friends and superiors at our laboratories, please observe the following:</p>
                    <ol>
                        <li>Maintain silence, proper decorum and discipline inside the laboratory. Mobile phones, walkmans and other personal items of equipment must be switched off.</li>
                        <li>Games are not allowed inside the lab. This includes computer-related games, card games and other games that may disturb the operation of the lab.</li>
                        <li>Surfing the Internet is allowed only with the permission of the instructor. Downloading and installing of software are strictly prohibited.</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Announcements -->
        <div class="dashboard-card">
            <div class="card-header">Announcements</div>
            <div class="card-body">
                <?php if(empty($announcements)): ?>
                    <p style="color:var(--gray-500);font-size:14px;">No announcements at this time.</p>
                <?php else: ?>
                    <?php foreach($announcements as $ann): ?>
                    <div class="announcement-item">
                        <div class="announcement-meta">
                            <?php echo htmlspecialchars($ann['admin_name']); ?>
                            &nbsp;·&nbsp;
                            <?php echo date('M d, Y', strtotime($ann['announcement_date'])); ?>
                        </div>
                        <div class="announcement-text"><?php echo htmlspecialchars($ann['message']); ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

</body>
</html>
