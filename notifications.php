<?php
session_start();
if(!isset($_SESSION['student_id'])){
    header("Location: login.php"); exit;
}
include 'Database/connect.php';

// Auto-create notifications table
$conn->query("CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info','success','warning','danger') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$student_id = $_SESSION['student_id'];

// Mark all as read when page is opened
$mark = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE student_id = ? AND is_read = 0");
$mark->bind_param("i", $student_id);
$mark->execute();
$mark->close();

// Fetch notifications
$filter = trim($_GET['filter'] ?? 'all');
$where  = "WHERE student_id = ?";
if($filter === 'unread') $where .= " AND is_read = 0";
if($filter === 'read')   $where .= " AND is_read = 1";

$s = $conn->prepare("SELECT * FROM notifications $where ORDER BY created_at DESC");
$s->bind_param("i", $student_id);
$s->execute();
$notifications = $s->get_result()->fetch_all(MYSQLI_ASSOC);
$s->close();

// Also fetch announcements
$announcements = $conn->query("SELECT * FROM announcements ORDER BY created_at DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications — CCS Sit-in</title>
<link rel="stylesheet" href="userdb.css">
<link rel="icon" type="image/png" href="pictures/uclogo.png">
<style>
.notif-page { padding: 28px 32px; max-width: 860px; margin: 0 auto; }
.page-title { font-family: 'Playfair Display', serif; font-size: 26px; font-weight: 600; color: var(--navy); }
.page-subtitle { font-size: 14px; color: var(--gray-500); margin-top: 3px; margin-bottom: 24px; }

.filter-tabs { display: flex; gap: 6px; margin-bottom: 20px; flex-wrap: wrap; }
.filter-tab {
    padding: 8px 18px; border-radius: 100px; font-size: 13px; font-weight: 600;
    cursor: pointer; text-decoration: none; border: 1.5px solid var(--gray-100);
    background: var(--white); color: var(--gray-500); transition: all var(--transition);
}
.filter-tab:hover { border-color: var(--blue); color: var(--blue); }
.filter-tab.active { background: var(--blue); border-color: var(--blue); color: var(--white); }

.notif-list { display: flex; flex-direction: column; gap: 10px; }
.notif-item {
    background: var(--white); border-radius: var(--radius); box-shadow: var(--card-shadow);
    padding: 18px 20px; display: flex; gap: 16px; align-items: flex-start;
    transition: all var(--transition); border-left: 4px solid transparent;
    animation: fadeSlideIn 0.3s ease both;
}
.notif-item:hover { box-shadow: var(--card-shadow-hover); transform: translateX(3px); }
.notif-item.type-success  { border-left-color: #22c55e; }
.notif-item.type-warning  { border-left-color: #f59e0b; }
.notif-item.type-danger   { border-left-color: var(--danger); }
.notif-item.type-info     { border-left-color: var(--blue); }
.notif-item.type-announce { border-left-color: #8b5cf6; }

.notif-icon {
    width: 42px; height: 42px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}
.icon-info     { background: rgba(30,111,224,0.1); }
.icon-success  { background: rgba(34,197,94,0.1); }
.icon-warning  { background: rgba(245,158,11,0.1); }
.icon-danger   { background: rgba(239,68,68,0.08); }
.icon-announce { background: rgba(139,92,246,0.1); }

.notif-content { flex: 1; min-width: 0; }
.notif-title   { font-size: 15px; font-weight: 600; color: var(--navy); margin-bottom: 4px; }
.notif-message { font-size: 14px; color: var(--gray-500); line-height: 1.6; }
.notif-time    { font-size: 12px; color: var(--gray-300); margin-top: 6px; font-weight: 500; }

.section-label {
    font-size: 12px; font-weight: 700; letter-spacing: 0.08em;
    text-transform: uppercase; color: var(--gray-300); margin: 24px 0 10px; padding-left: 4px;
}

.empty-state { text-align: center; padding: 70px 20px; color: var(--gray-500); }
.empty-icon  { font-size: 48px; margin-bottom: 16px; }
.empty-title { font-size: 17px; font-weight: 600; color: var(--gray-700); margin-bottom: 6px; }
.empty-desc  { font-size: 14px; }

@keyframes fadeSlideIn {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>
</head>
<body>

<nav class="dashboard-navbar">
    <div class="dashboard-left">Dashboard</div>
    <ul class="dashboard-right">
        <li><a href="notifications.php" class="active">Notifications</a></li>
        <li><a href="userdb.php">Home</a></li>
        <li><a href="edit_profile.php">Edit Profile</a></li>
        <li><a href="history.php">History</a></li>
        <li><a href="student_reservation.php">Reservation</a></li>
        <li><a href="logout.php" class="logout-btn">Log Out</a></li>
    </ul>
</nav>

<div class="notif-page">

    <div class="page-title">Notifications</div>
    <div class="page-subtitle">Your alerts, updates, and announcements</div>

    <div class="filter-tabs">
        <a href="notifications.php?filter=all"    class="filter-tab <?php echo $filter === 'all'    ? 'active' : ''; ?>">All</a>
        <a href="notifications.php?filter=unread" class="filter-tab <?php echo $filter === 'unread' ? 'active' : ''; ?>">Unread</a>
        <a href="notifications.php?filter=read"   class="filter-tab <?php echo $filter === 'read'   ? 'active' : ''; ?>">Read</a>
    </div>

    <?php if(!empty($notifications)): ?>
    <div class="section-label">Your Notifications</div>
    <div class="notif-list">
        <?php
        $icons = ['info'=>'🔔','success'=>'✅','warning'=>'⚠️','danger'=>'❌'];
        foreach($notifications as $idx => $n):
        ?>
        <div class="notif-item type-<?php echo htmlspecialchars($n['type']); ?>" style="animation-delay:<?php echo $idx*0.05; ?>s">
            <div class="notif-icon icon-<?php echo htmlspecialchars($n['type']); ?>">
                <?php echo $icons[$n['type']] ?? '🔔'; ?>
            </div>
            <div class="notif-content">
                <div class="notif-title"><?php echo htmlspecialchars($n['title']); ?></div>
                <div class="notif-message"><?php echo htmlspecialchars($n['message']); ?></div>
                <div class="notif-time"><?php echo date('M d, Y · h:i A', strtotime($n['created_at'])); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if(!empty($announcements) && $filter !== 'unread'): ?>
    <div class="section-label">Announcements</div>
    <div class="notif-list">
        <?php foreach($announcements as $idx => $ann): ?>
        <div class="notif-item type-announce" style="animation-delay:<?php echo $idx*0.05; ?>s">
            <div class="notif-icon icon-announce">📢</div>
            <div class="notif-content">
                <div class="notif-title"><?php echo htmlspecialchars($ann['admin_name']); ?></div>
                <div class="notif-message"><?php echo htmlspecialchars($ann['message']); ?></div>
                <div class="notif-time"><?php echo date('M d, Y', strtotime($ann['announcement_date'])); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if(empty($notifications) && (empty($announcements) || $filter === 'unread')): ?>
    <div class="empty-state">
        <div class="empty-icon"><?php echo $filter === 'unread' ? '✅' : '🔕'; ?></div>
        <div class="empty-title"><?php echo $filter === 'unread' ? 'All caught up!' : 'No notifications yet'; ?></div>
        <div class="empty-desc"><?php echo $filter === 'unread' ? 'No unread notifications.' : 'Check back later for updates.'; ?></div>
    </div>
    <?php endif; ?>

</div>
</body>
</html>
