<?php
session_start();
if(!isset($_SESSION['student_id'])){
    header("Location: login.php"); exit;
}
include 'Database/connect.php';

// Refresh sessions count
$s = $conn->prepare("SELECT sessions FROM students WHERE id=?");
$s->bind_param("i",$_SESSION['student_id']);
$s->execute();
$row = $s->get_result()->fetch_assoc();
$s->close();
if($row) $_SESSION['sessions']=$row['sessions'];

// Fetch announcements
$announcements = [];
$stmt=$conn->prepare("SELECT * FROM announcements ORDER BY created_at DESC LIMIT 5");
$stmt->execute();
$res=$stmt->get_result();
while($row=$res->fetch_assoc()) $announcements[]=$row;
$stmt->close();

// ── Sit-in Summary ────────────────────────────────────────────────────
$id_number = $_SESSION['id_number'];

// Total sessions used
$r=$conn->prepare("SELECT COUNT(*) as c FROM sit_in WHERE id_number=?");
$r->bind_param("s",$id_number); $r->execute();
$total_used = $r->get_result()->fetch_assoc()['c'];

// Total hours (sum of duration for completed sessions)
$r=$conn->prepare("SELECT 
    SUM(TIMESTAMPDIFF(MINUTE,
        CONCAT(sit_in_date,' ',sit_in_time),
        CONCAT(IFNULL(time_out_date,sit_in_date),' ',IFNULL(time_out,'00:00:00'))
    )) as total_min
    FROM sit_in WHERE id_number=? AND time_out IS NOT NULL");
$r->bind_param("s",$id_number); $r->execute();
$total_min = $r->get_result()->fetch_assoc()['total_min'] ?? 0;
$total_hours = $total_min ? round($total_min/60,1) : 0;

// Average session duration (minutes)
$r=$conn->prepare("SELECT 
    AVG(TIMESTAMPDIFF(MINUTE,
        CONCAT(sit_in_date,' ',sit_in_time),
        CONCAT(IFNULL(time_out_date,sit_in_date),' ',IFNULL(time_out,'00:00:00'))
    )) as avg_min
    FROM sit_in WHERE id_number=? AND time_out IS NOT NULL");
$r->bind_param("s",$id_number); $r->execute();
$avg_min = $r->get_result()->fetch_assoc()['avg_min'] ?? 0;
$avg_duration = $avg_min ? round($avg_min) : 0;

// Longest session
$r=$conn->prepare("SELECT 
    MAX(TIMESTAMPDIFF(MINUTE,
        CONCAT(sit_in_date,' ',sit_in_time),
        CONCAT(IFNULL(time_out_date,sit_in_date),' ',IFNULL(time_out,'00:00:00'))
    )) as max_min
    FROM sit_in WHERE id_number=? AND time_out IS NOT NULL");
$r->bind_param("s",$id_number); $r->execute();
$max_min = $r->get_result()->fetch_assoc()['max_min'] ?? 0;
$longest_h = $max_min ? floor($max_min/60).'h '.($max_min%60).'m' : '—';

// Format avg
$avg_fmt = $avg_duration ? (floor($avg_duration/60)>0 ? floor($avg_duration/60).'h '.($avg_duration%60).'m' : $avg_duration.'m') : '—';

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — CCS Sit-in</title>
<link rel="stylesheet" href="userdb.css">
<link rel="icon" type="image/png" href="pictures/uclogo.png">
<style>
/* ── Sit-in Summary ── */
.sitin-summary{border-top:1px solid var(--gray-100);padding:14px 16px;}
.sitin-summary-title{font-size:11px;font-weight:700;color:var(--gray-300);letter-spacing:.08em;text-transform:uppercase;margin-bottom:12px;display:flex;align-items:center;gap:6px;}
.sitin-summary-title::before{content:'';flex:1;height:1px;background:var(--gray-100);}
.summary-stats{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.summary-tile{background:var(--off-white);border-radius:var(--radius-sm);padding:10px 12px;text-align:center;transition:all var(--transition);}
.summary-tile:hover{background:rgba(30,111,224,.07);}
.summary-tile-val{font-size:18px;font-weight:700;color:var(--navy);line-height:1;margin-bottom:3px;}
.summary-tile-lbl{font-size:10px;color:var(--gray-300);font-weight:600;text-transform:uppercase;letter-spacing:.04em;line-height:1.3;}
</style>
</head>
<body>

<nav class="dashboard-navbar">
    <div class="dashboard-left">Dashboard</div>
    <ul class="dashboard-right">
        <li><a href="notifications.php">Notifications</a></li>
        <li><a href="userdb.php" class="active">Home</a></li>
        <li><a href="software.php">Software</a></li>
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
            <img src="profile_pictures/<?php echo $_SESSION['profile_picture'] ?? 'default.png'; ?>" alt="Profile">
            <div class="student-name"><?php echo $_SESSION['first_name'].' '.$_SESSION['last_name']; ?></div>
            <div class="student-course-tag"><?php echo $_SESSION['course']; ?></div>
        </div>

        <div class="student-details">
            <div class="detail-row">
                <span class="detail-label">ID Number</span>
                <span class="detail-value"><?php echo $_SESSION['id_number']; ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Full Name</span>
                <span class="detail-value"><?php echo $_SESSION['first_name'].' '.$_SESSION['middle_name'].' '.$_SESSION['last_name']; ?></span>
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
                    <span class="sessions-badge"><?php echo $_SESSION['sessions']; ?> sessions</span>
                </span>
            </div>
        </div>

        <!-- Sit-in Summary -->
        <div class="sitin-summary">
            <div class="sitin-summary-title">Sit-in Summary</div>
            <div class="summary-stats">
                <div class="summary-tile">
                    <div class="summary-tile-val"><?php echo $total_hours; ?>h</div>
                    <div class="summary-tile-lbl">Total Hours</div>
                </div>
                <div class="summary-tile">
                    <div class="summary-tile-val"><?php echo $total_used; ?></div>
                    <div class="summary-tile-lbl">Sessions Used</div>
                </div>
                <div class="summary-tile">
                    <div class="summary-tile-val" style="font-size:14px;"><?php echo $avg_fmt; ?></div>
                    <div class="summary-tile-lbl">Avg Duration</div>
                </div>
                <div class="summary-tile">
                    <div class="summary-tile-val" style="font-size:14px;"><?php echo $longest_h; ?></div>
                    <div class="summary-tile-lbl">Longest Session</div>
                </div>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="dashboard-main">

        <?php if(isset($_GET['success'])): ?>
        <div style="padding:14px 20px;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#15803d;border-radius:10px;font-size:14px;font-weight:500;">
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
                        <li>Deleting computer files, changing the computer settings or moving/rearranging equipment is prohibited.</li>
                        <li>Students must log out of all applications when leaving the laboratory.</li>
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
                        <?php echo htmlspecialchars($ann['admin_name']); ?> &nbsp;·&nbsp;
                        <?php echo date('M d, Y',strtotime($ann['announcement_date'])); ?>
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
