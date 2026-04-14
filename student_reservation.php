<?php
session_start();
if(!isset($_SESSION['student_id'])){
    header("Location: login.php"); exit;
}
include 'Database/connect.php';

// Ensure reservations table exists
$conn->query("CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_number VARCHAR(50) NOT NULL,
    student_name VARCHAR(200) NOT NULL,
    purpose VARCHAR(100) NOT NULL,
    lab VARCHAR(50) NOT NULL,
    reservation_date DATE NOT NULL,
    reservation_time TIME NOT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    admin_note TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Also ensure notifications table exists
$conn->query("CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info','success','warning','danger') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$success_msg = '';
$error_msg   = '';

// Handle new reservation submission
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_reservation'])){
    $purpose  = trim($_POST['purpose']);
    $lab      = trim($_POST['lab']);
    $res_date = trim($_POST['reservation_date']);
    $res_time = trim($_POST['reservation_time']);
    $id_number    = $_SESSION['id_number'];
    $student_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];

    // Validate date is not in the past
    $res_hour = (int)date('H', strtotime($res_time));
    if($res_hour < 7 || $res_hour >= 20){
        $error_msg = 'Reservation time must be between 7:00 AM and 8:00 PM.';

    } else {
        // Check for duplicate reservation same day + lab
        $dup = $conn->prepare("SELECT id FROM reservations WHERE id_number = ? AND lab = ? AND reservation_date = ? AND status != 'rejected'");
        $dup->bind_param("sss", $id_number, $lab, $res_date);
        $dup->execute();
        if($dup->get_result()->num_rows > 0){
            $error_msg = 'You already have a reservation for this lab on that date.';
        } else {
            $ins = $conn->prepare("INSERT INTO reservations (id_number, student_name, purpose, lab, reservation_date, reservation_time) VALUES (?, ?, ?, ?, ?, ?)");
            $ins->bind_param("ssssss", $id_number, $student_name, $purpose, $lab, $res_date, $res_time);
            if($ins->execute()){
                // Create a notification for the student
                $notif_msg = "Your reservation for Lab $lab on " . date('M d, Y', strtotime($res_date)) . " at " . date('h:i A', strtotime($res_time)) . " has been submitted and is pending approval.";
                $notif = $conn->prepare("INSERT INTO notifications (student_id, title, message, type) VALUES (?, 'Reservation Submitted', ?, 'info')");
                $notif->bind_param("is", $_SESSION['student_id'], $notif_msg);
                $notif->execute();
                $success_msg = 'Reservation submitted successfully! Please wait for admin approval.';
            } else {
                $error_msg = 'Something went wrong. Please try again.';
            }
        }
    }
}

// Handle cancel reservation
if(isset($_GET['cancel'])){
    $cancel_id = intval($_GET['cancel']);
    $s = $conn->prepare("DELETE FROM reservations WHERE id = ? AND id_number = ? AND status = 'pending'");
    $s->bind_param("is", $cancel_id, $_SESSION['id_number']);
    $s->execute();
    header("Location: student_reservation.php?cancelled=1"); exit;
}

// Fetch student's reservations
$filter_status = trim($_GET['status'] ?? '');
$where = "WHERE id_number = ?";
$params = [$_SESSION['id_number']];
if(in_array($filter_status, ['pending','approved','rejected'])){
    $where .= " AND status = ?";
    $params[] = $filter_status;
}

$s = $conn->prepare("SELECT * FROM reservations $where ORDER BY reservation_date DESC, reservation_time DESC");
$s->bind_param(str_repeat('s', count($params)), ...$params);
$s->execute();
$reservations = $s->get_result()->fetch_all(MYSQLI_ASSOC);

// Counts
$pending_c  = 0; $approved_c = 0; $rejected_c = 0;
foreach($reservations as $r){
    if($r['status'] === 'pending')  $pending_c++;
    if($r['status'] === 'approved') $approved_c++;
    if($r['status'] === 'rejected') $rejected_c++;
}

$conn->close();

$min_date = date('Y-m-d');
$max_date = date('Y-m-d', strtotime('+30 days'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reservation — CCS Sit-in</title>
<link rel="stylesheet" href="userdb.css">
<link rel="icon" type="image/png" href="pictures/uclogo.png">
<style>
.res-page { padding: 28px 32px; max-width: 1100px; margin: 0 auto; }

.page-title {
    font-family: 'Playfair Display', serif;
    font-size: 26px;
    font-weight: 600;
    color: var(--navy);
}
.page-subtitle { font-size: 14px; color: var(--gray-500); margin-top: 3px; margin-bottom: 24px; }

.res-layout { display: grid; grid-template-columns: 360px 1fr; gap: 22px; align-items: start; }
@media(max-width:900px){ .res-layout { grid-template-columns: 1fr; } }

/* Form Card */
.form-card {
    background: var(--white);
    border-radius: var(--radius);
    box-shadow: var(--card-shadow);
    overflow: hidden;
    position: sticky;
    top: 88px;
}

.form-card-header {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 100%);
    color: var(--white);
    padding: 16px 24px;
    font-size: 14px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-card-header::before {
    content: '';
    width: 4px; height: 16px;
    background: var(--blue-light);
    border-radius: 2px;
}

.form-card-body { padding: 24px; }

.form-group { margin-bottom: 16px; }

.form-label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--gray-700);
    margin-bottom: 7px;
}

.form-input, .form-select {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid var(--gray-100);
    border-radius: var(--radius-sm);
    font-size: 14px;
    font-family: 'Outfit', sans-serif;
    color: var(--navy);
    background: #fafbff;
    outline: none;
    transition: all var(--transition);
}

.form-input:focus, .form-select:focus {
    border-color: var(--blue);
    background: var(--white);
    box-shadow: 0 0 0 3px rgba(30,111,224,0.1);
}

.submit-btn {
    width: 100%;
    padding: 12px;
    background: linear-gradient(135deg, var(--blue) 0%, var(--blue-light) 100%);
    color: var(--white);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 15px;
    font-weight: 600;
    font-family: 'Outfit', sans-serif;
    cursor: pointer;
    transition: all var(--transition);
    margin-top: 4px;
}

.submit-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(30,111,224,0.35);
}

/* Reservations List Card */
.list-card {
    background: var(--white);
    border-radius: var(--radius);
    box-shadow: var(--card-shadow);
    overflow: hidden;
}

.list-card-header {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 100%);
    color: var(--white);
    padding: 16px 24px;
    font-size: 14px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.list-card-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
}

.list-card-header-left::before {
    content: '';
    width: 4px; height: 16px;
    background: var(--blue-light);
    border-radius: 2px;
}

.list-card-body { padding: 20px; }

/* Status tabs */
.status-tabs {
    display: flex;
    gap: 6px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}

.status-tab {
    padding: 7px 16px;
    border-radius: 100px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    border: 1.5px solid var(--gray-100);
    background: var(--white);
    color: var(--gray-500);
    transition: all var(--transition);
}

.status-tab:hover { border-color: var(--blue); color: var(--blue); }
.status-tab.active { background: var(--blue); border-color: var(--blue); color: var(--white); }

/* Reservation Items */
.res-list { display: flex; flex-direction: column; gap: 10px; }

.res-item {
    border: 1.5px solid var(--gray-100);
    border-radius: var(--radius-sm);
    padding: 16px 18px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 14px;
    transition: all var(--transition);
    animation: fadeUp 0.3s ease both;
}

.res-item:hover { border-color: rgba(30,111,224,0.2); background: #fafcff; }
.res-item.status-approved { border-left: 3px solid #22c55e; }
.res-item.status-rejected { border-left: 3px solid var(--danger); opacity: 0.75; }
.res-item.status-pending  { border-left: 3px solid #f59e0b; }

.res-item-info { flex: 1; min-width: 0; }

.res-item-top {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 6px;
}

.res-lab {
    font-size: 15px;
    font-weight: 700;
    color: var(--navy);
}

.badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 10px;
    border-radius: 100px;
    font-size: 12px;
    font-weight: 600;
}

.badge-blue    { background: rgba(30,111,224,0.1); color: var(--blue); border: 1px solid rgba(30,111,224,0.2); }
.badge-success { background: rgba(34,197,94,0.1); color: #15803d; border: 1px solid rgba(34,197,94,0.3); }
.badge-warning { background: rgba(245,158,11,0.1); color: #92400e; border: 1px solid rgba(245,158,11,0.3); }
.badge-danger  { background: rgba(239,68,68,0.08); color: #b91c1c; border: 1px solid rgba(239,68,68,0.2); }

.res-meta {
    font-size: 13px;
    color: var(--gray-500);
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
}

.res-admin-note {
    margin-top: 8px;
    font-size: 13px;
    color: var(--gray-500);
    background: var(--off-white);
    border-radius: 6px;
    padding: 8px 12px;
    border-left: 3px solid var(--gray-300);
}

.cancel-btn {
    padding: 6px 14px;
    background: rgba(239,68,68,0.08);
    color: #b91c1c;
    border: 1px solid rgba(239,68,68,0.2);
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-weight: 600;
    font-family: 'Outfit', sans-serif;
    cursor: pointer;
    transition: all var(--transition);
    text-decoration: none;
    white-space: nowrap;
    flex-shrink: 0;
}

.cancel-btn:hover { background: rgba(239,68,68,0.16); }

.alert {
    padding: 12px 16px;
    border-radius: var(--radius-sm);
    font-size: 14px;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 10px;
    animation: fadeUp 0.3s ease both;
}
.alert-success { background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.3); color: #15803d; }
.alert-danger  { background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.2); color: #b91c1c; }

.empty-state { text-align: center; padding: 50px 20px; }
.empty-icon  { font-size: 40px; margin-bottom: 14px; }
.empty-title { font-size: 15px; font-weight: 600; color: var(--gray-700); margin-bottom: 6px; }
.empty-desc  { font-size: 14px; color: var(--gray-500); }

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>
</head>
<body>

<nav class="dashboard-navbar">
    <div class="dashboard-left">Dashboard</div>
    <ul class="dashboard-right">
        <li><a href="notifications.php">Notification</a></li>
        <li><a href="userdb.php">Home</a></li>
        <li><a href="edit_profile.php">Edit Profile</a></li>
        <li><a href="history.php">History</a></li>
        <li><a href="student_reservation.php" class="active">Reservation</a></li>
        <li><a href="logout.php" class="logout-btn">Log Out</a></li>
    </ul>
</nav>

<div class="res-page">

    <div class="page-title">Laboratory Reservation</div>
    <div class="page-subtitle">Book a computer laboratory slot in advance</div>

    <?php if($success_msg): ?>
    <div class="alert alert-success">✓ <?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if($error_msg): ?>
    <div class="alert alert-danger">⚠ <?php echo $error_msg; ?></div>
    <?php endif; ?>
    <?php if(isset($_GET['cancelled'])): ?>
    <div class="alert alert-success">✓ Reservation cancelled successfully.</div>
    <?php endif; ?>

    <div class="res-layout">

        <!-- LEFT: Reservation Form -->
        <div class="form-card">
            <div class="form-card-header">New Reservation</div>
            <div class="form-card-body">
                <form method="POST" action="">
                    <div class="form-group">
                        <label class="form-label">Purpose</label>
                        <select name="purpose" class="form-select" required>
                            <option value="">— Select Purpose —</option>
                            <option>C Programming</option>
                            <option>Java Programming</option>
                            <option>Python Programming</option>
                            <option>Web Development</option>
                            <option>Database</option>
                            <option>Research</option>
                            <option>Assignment</option>
                            <option>Examination</option>
                            <option>Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Laboratory</label>
                        <select name="lab" class="form-select" required>
                            <option value="">— Select Lab —</option>
                            <option value="524">Lab 524</option>
                            <option value="525">Lab 526</option>
                            <option value="526">Lab 528</option>
                            <option value="527">Lab 530</option>
                            <option value="528">Lab 544</option>
                            <option value="529">Lab 542</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Preferred Date</label>
                        <input type="date" name="reservation_date" class="form-input"
                               min="<?php echo $min_date; ?>"
                               max="<?php echo $max_date; ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Preferred Time</label>
                        <input type="time" name="reservation_time" class="form-input"
                                min="07:00" max="20:00" required>
                        <small style="color:var(--gray-500);font-size:12px;">Lab hours: 7:00 AM – 8:00 PM</small>
                    </div>
                    <div style="background:var(--off-white);border-radius:var(--radius-sm);padding:12px 14px;margin-bottom:16px;font-size:13px;color:var(--gray-500);line-height:1.6;">
                        📋 Reservations are subject to admin approval. You will be notified once your request is reviewed.
                    </div>
                    <button type="submit" name="submit_reservation" class="submit-btn">Submit Reservation</button>
                </form>
            </div>
        </div>

        <!-- RIGHT: My Reservations -->
        <div class="list-card">
            <div class="list-card-header">
                <div class="list-card-header-left">My Reservations</div>
                <span style="font-size:13px;opacity:0.7"><?php echo count($reservations); ?> total</span>
            </div>
            <div class="list-card-body">

                <!-- Status Tabs -->
                <div class="status-tabs">
                    <a href="student_reservation.php" class="status-tab <?php echo $filter_status === '' ? 'active' : ''; ?>">
                        All (<?php echo count($reservations); ?>)
                    </a>
                    <a href="student_reservation.php?status=pending" class="status-tab <?php echo $filter_status === 'pending' ? 'active' : ''; ?>">
                        Pending (<?php echo $pending_c; ?>)
                    </a>
                    <a href="student_reservation.php?status=approved" class="status-tab <?php echo $filter_status === 'approved' ? 'active' : ''; ?>">
                        Approved (<?php echo $approved_c; ?>)
                    </a>
                    <a href="student_reservation.php?status=rejected" class="status-tab <?php echo $filter_status === 'rejected' ? 'active' : ''; ?>">
                        Rejected (<?php echo $rejected_c; ?>)
                    </a>
                </div>

                <?php if(empty($reservations)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📅</div>
                    <div class="empty-title">No reservations yet</div>
                    <div class="empty-desc">Use the form to book a laboratory slot.</div>
                </div>
                <?php else: ?>
                <div class="res-list">
                    <?php
                    $status_badge = ['pending'=>'badge-warning','approved'=>'badge-success','rejected'=>'badge-danger'];
                    $status_icon  = ['pending'=>'⏳','approved'=>'✓','rejected'=>'✕'];
                    foreach($reservations as $idx => $r):
                    ?>
                    <div class="res-item status-<?php echo $r['status']; ?>" style="animation-delay:<?php echo $idx*0.05; ?>s">
                        <div class="res-item-info">
                            <div class="res-item-top">
                                <span class="res-lab">Lab <?php echo htmlspecialchars($r['lab']); ?></span>
                                <span class="badge badge-blue"><?php echo htmlspecialchars($r['purpose']); ?></span>
                                <span class="badge <?php echo $status_badge[$r['status']]; ?>">
                                    <?php echo $status_icon[$r['status']] . ' ' . ucfirst($r['status']); ?>
                                </span>
                            </div>
                            <div class="res-meta">
                                <span>📅 <?php echo date('M d, Y', strtotime($r['reservation_date'])); ?></span>
                                <span>🕐 <?php echo date('h:i A', strtotime($r['reservation_time'])); ?></span>
                                <span>Submitted <?php echo date('M d', strtotime($r['created_at'])); ?></span>
                            </div>
                            <?php if($r['admin_note']): ?>
                            <div class="res-admin-note">
                                💬 Admin note: <?php echo htmlspecialchars($r['admin_note']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if($r['status'] === 'pending'): ?>
                        <a href="student_reservation.php?cancel=<?php echo $r['id']; ?>"
                           class="cancel-btn"
                           onclick="return confirm('Cancel this reservation?')">Cancel</a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </div>
</div>

</body>
</html>
