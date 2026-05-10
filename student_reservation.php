<?php
session_start();
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php"); exit;
}
require_once 'Database/csrf.php';
include 'Database/connect.php';

// ── Ensure tables ─────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS labs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lab_name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(200) DEFAULT NULL,
    `rows` INT NOT NULL DEFAULT 5,
    `cols` INT NOT NULL DEFAULT 8,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS seats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lab_id INT NOT NULL,
    seat_number INT NOT NULL,
    row_pos INT NOT NULL,
    col_pos INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lab_seat (lab_id, seat_number),
    FOREIGN KEY (lab_id) REFERENCES labs(id) ON DELETE CASCADE
)");
$conn->query("CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_number VARCHAR(50) NOT NULL,
    student_name VARCHAR(200) NOT NULL,
    purpose VARCHAR(100) NOT NULL,
    lab VARCHAR(50) NOT NULL,
    reservation_date DATE NOT NULL,
    reservation_time TIME NOT NULL,
    status ENUM('pending','approved','rejected','expired','converted') DEFAULT 'pending',
    admin_note TEXT DEFAULT NULL,
    seat_id INT DEFAULT NULL,
    seat_number INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info','success','warning','danger') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    reference_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS reference_id INT DEFAULT NULL");

// Seed default labs if empty
if ($conn->query("SELECT COUNT(*) as c FROM labs")->fetch_assoc()['c'] == 0) {
    foreach ([['524',5,8],['526',5,8],['528',5,8],['530',5,8],['542',5,8],['544',5,8]] as $l) {
        $conn->query("INSERT IGNORE INTO labs (lab_name,description,`rows`,`cols`) VALUES ('{$l[0]}','Laboratory {$l[0]}',{$l[1]},{$l[2]})");
        $lid = $conn->insert_id;
        if ($lid) {
            $sn = 1;
            for ($r = 1; $r <= $l[1]; $r++)
                for ($c = 1; $c <= $l[2]; $c++) {
                    $conn->query("INSERT IGNORE INTO seats (lab_id,seat_number,row_pos,col_pos) VALUES ($lid,$sn,$r,$c)");
                    $sn++;
                }
        }
    }
}

$success_msg = '';
$error_msg   = '';

// ── Handle reservation submission ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_reservation'])) {
    csrf_verify();

    $purpose  = mb_substr(trim($_POST['purpose']          ?? ''), 0, 100);
    $lab_name = mb_substr(trim($_POST['lab']              ?? ''), 0, 50);
    $res_date = trim($_POST['reservation_date']           ?? '');
    $res_time = trim($_POST['reservation_time']           ?? '');
    $seat_id  = (int) ($_POST['seat_id']                 ?? 0);
    $id_number    = $_SESSION['id_number'];
    $student_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];

    // Validate date format and range
    $min_date = date('Y-m-d');
    $max_date = date('Y-m-d', strtotime('+30 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $res_date) || $res_date < $min_date || $res_date > $max_date) {
        $error_msg = 'Please choose a valid reservation date (today up to 30 days ahead).';
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $res_time)) {
        $error_msg = 'Invalid time format.';
    } else {
        $res_hour = (int) date('H', strtotime($res_time));
        if ($res_hour < 7 || $res_hour >= 20) {
            $error_msg = 'Reservation time must be between 7:00 AM and 8:00 PM.';
        } elseif (!$seat_id) {
            $error_msg = 'Please select a seat from the layout below.';
        } else {
            // Check duplicate reservation for this student on this date+lab
            $dup = $conn->prepare("SELECT id FROM reservations WHERE id_number=? AND lab=? AND reservation_date=? AND status NOT IN ('rejected','expired','converted')");
            $dup->bind_param("sss", $id_number, $lab_name, $res_date);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0) {
                $error_msg = 'You already have a reservation for this lab on that date.';
            } else {
                // Check seat not taken on the SELECTED date (not just today)
                $seat_dup = $conn->prepare("SELECT id FROM reservations WHERE seat_id=? AND reservation_date=? AND status NOT IN ('rejected','expired','converted')");
                $seat_dup->bind_param("is", $seat_id, $res_date);
                $seat_dup->execute();
                if ($seat_dup->get_result()->num_rows > 0) {
                    $error_msg = 'That seat is already reserved on this date. Please choose another.';
                } else {
                    $srow = $conn->prepare("SELECT seat_number FROM seats WHERE id=?");
                    $srow->bind_param("i", $seat_id);
                    $srow->execute();
                    $sdata    = $srow->get_result()->fetch_assoc();
                    $seat_num = $sdata ? $sdata['seat_number'] : 0;
                    $srow->close();

                    $ins = $conn->prepare("INSERT INTO reservations (id_number,student_name,purpose,lab,reservation_date,reservation_time,seat_id,seat_number) VALUES (?,?,?,?,?,?,?,?)");
                    $ins->bind_param("ssssssii", $id_number, $student_name, $purpose, $lab_name, $res_date, $res_time, $seat_id, $seat_num);
                    if ($ins->execute()) {
                        $res_id = $conn->insert_id;
                        $notif_msg = "Your reservation for Lab $lab_name (Seat $seat_num) on " . date('M d, Y', strtotime($res_date)) . " at " . date('h:i A', strtotime($res_time)) . " has been submitted and is awaiting admin approval.";
                        $sid = $_SESSION['student_id'];
                        $notif = $conn->prepare("INSERT INTO notifications (student_id,title,message,type,reference_id) VALUES (?,'Reservation Submitted',?,'info',?)");
                        $notif->bind_param("isi", $sid, $notif_msg, $res_id);
                        $notif->execute();
                        $notif->close();
                        $success_msg = "Reservation submitted! Seat $seat_num reserved for " . date('M d, Y', strtotime($res_date)) . ". Awaiting admin approval.";
                    } else {
                        $error_msg = 'Something went wrong. Please try again.';
                    }
                    $ins->close();
                }
                $seat_dup->close();
            }
            $dup->close();
        }
    }
}

// Handle cancel
if (isset($_GET['cancel'])) {
    $cid = (int) $_GET['cancel'];
    $s   = $conn->prepare("DELETE FROM reservations WHERE id=? AND id_number=? AND status='pending'");
    $s->bind_param("is", $cid, $_SESSION['id_number']);
    $s->execute();
    header("Location: student_reservation.php?cancelled=1"); exit;
}

// ── AJAX: seats for a given lab + date ───────────────────────────────
// FIXED: seat availability is checked against the requested date, not CURDATE()
if (isset($_GET['ajax_seats'])) {
    $lab_id   = (int) $_GET['ajax_seats'];
    $req_date = trim($_GET['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $req_date)) {
        $req_date = date('Y-m-d');
    }

    $sr = $conn->prepare("
        SELECT s.*,
            (SELECT COUNT(*) FROM reservations r
             WHERE r.seat_id = s.id
               AND r.reservation_date = ?
               AND r.status NOT IN ('rejected','expired','converted')) AS is_taken
        FROM seats s
        WHERE s.lab_id = ?
        ORDER BY s.row_pos, s.col_pos
    ");
    $sr->bind_param("si", $req_date, $lab_id);
    $sr->execute();
    $seats = $sr->get_result()->fetch_all(MYSQLI_ASSOC);
    $sr->close();
    header('Content-Type: application/json');
    echo json_encode($seats);
    $conn->close();
    exit;
}

// Fetch labs
$labs = $conn->query("SELECT * FROM labs WHERE is_active=1 ORDER BY lab_name")->fetch_all(MYSQLI_ASSOC);

// Build lab info (rows/cols) for JS seat renderer
$labs_info = [];
foreach ($labs as $lab) {
    $labs_info[$lab['lab_name']] = [
        'id'   => $lab['id'],
        'rows' => (int) $lab['rows'],
        'cols' => (int) $lab['cols'],
    ];
}

// Fetch student's reservations
$filter_status = trim($_GET['status'] ?? '');
$where  = "WHERE id_number=?";
$params = [$_SESSION['id_number']];
if (in_array($filter_status, ['pending','approved','rejected','expired','converted'])) {
    $where .= " AND status=?";
    $params[] = $filter_status;
}
$s = $conn->prepare("SELECT * FROM reservations $where ORDER BY reservation_date DESC, reservation_time DESC");
$s->bind_param(str_repeat('s', count($params)), ...$params);
$s->execute();
$reservations = $s->get_result()->fetch_all(MYSQLI_ASSOC);

$pending_c  = 0; $approved_c = 0; $rejected_c = 0;
foreach ($reservations as $r) {
    if ($r['status'] === 'pending')  $pending_c++;
    if ($r['status'] === 'approved') $approved_c++;
    if ($r['status'] === 'rejected') $rejected_c++;
}

$min_date = date('Y-m-d');
$max_date = date('Y-m-d', strtotime('+30 days'));
$conn->close();
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
.res-page{padding:28px 32px;max-width:1200px;margin:0 auto;}
.page-title{font-family:'Playfair Display',serif;font-size:26px;font-weight:600;color:var(--navy);}
.page-subtitle{font-size:14px;color:var(--gray-500);margin-top:3px;margin-bottom:24px;}
.res-layout{display:grid;grid-template-columns:340px 1fr;gap:22px;align-items:start;}
@media(max-width:960px){.res-layout{grid-template-columns:1fr;}}
.form-card{background:var(--white);border-radius:var(--radius);box-shadow:var(--card-shadow);overflow:hidden;position:sticky;top:88px;}
.form-card-header{background:linear-gradient(135deg,var(--navy),var(--navy-mid));color:var(--white);padding:16px 24px;font-size:14px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;}
.form-card-body{padding:22px;}
.form-group{margin-bottom:14px;}
.form-label{display:block;font-size:13px;font-weight:600;color:var(--gray-700);margin-bottom:6px;}
.form-input,.form-select{width:100%;padding:10px 14px;border:1.5px solid var(--gray-100);border-radius:var(--radius-sm);font-size:14px;font-family:'Outfit',sans-serif;color:var(--navy);background:#fafbff;outline:none;transition:all var(--transition);}
.form-input:focus,.form-select:focus{border-color:var(--blue);background:var(--white);box-shadow:0 0 0 3px rgba(30,111,224,.1);}
.submit-btn{width:100%;padding:12px;background:linear-gradient(135deg,var(--blue),var(--blue-light));color:var(--white);border:none;border-radius:var(--radius-sm);font-size:15px;font-weight:600;font-family:'Outfit',sans-serif;cursor:pointer;transition:all var(--transition);margin-top:4px;}
.submit-btn:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(30,111,224,.35);}
.seat-picker{background:var(--off-white);border-radius:var(--radius-sm);padding:16px;margin-top:14px;border:1.5px solid var(--gray-100);}
.seat-picker-title{font-size:13px;font-weight:600;color:var(--gray-700);margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;}
.seat-legend{display:flex;gap:12px;flex-wrap:wrap;font-size:11px;margin-bottom:12px;}
.leg-dot{width:12px;height:12px;border-radius:3px;display:inline-block;margin-right:4px;vertical-align:middle;}
.leg-green{background:#22c55e;}.leg-red{background:#ef4444;}.leg-amber{background:#f59e0b;}.leg-blue{background:var(--blue);}
.seat-grid-wrapper{overflow-x:auto;}
.seat-grid{display:flex;flex-direction:column;gap:5px;}
.seat-row-wrap{display:flex;gap:4px;align-items:center;}
.aisle-gap{width:14px;flex-shrink:0;}
.seat-btn{width:42px;height:42px;border-radius:8px;border:2px solid transparent;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:9px;font-weight:800;cursor:pointer;transition:all var(--transition);font-family:'Outfit',sans-serif;flex-shrink:0;}
.seat-btn.available{background:rgba(34,197,94,.18);color:#15803d;border-color:rgba(34,197,94,.4);}
.seat-btn.available:hover{background:rgba(34,197,94,.35);transform:scale(1.1);}
.seat-btn.taken{background:rgba(239,68,68,.12);color:#b91c1c;border-color:rgba(239,68,68,.35);cursor:not-allowed;}
.seat-btn.selected{background:var(--blue);color:var(--white);border-color:var(--blue);transform:scale(1.1);}
.seat-icon{font-size:14px;margin-bottom:1px;}
.teacher-bar{background:linear-gradient(135deg,var(--navy),var(--navy-mid));color:var(--white);border-radius:8px;padding:6px 18px;font-size:11px;font-weight:700;text-align:center;margin:0 auto 12px;display:block;width:fit-content;}
.selected-seat-badge{background:rgba(30,111,224,.1);border:1px solid rgba(30,111,224,.25);color:var(--blue);border-radius:var(--radius-sm);padding:8px 14px;font-size:13px;font-weight:600;margin-top:10px;display:none;align-items:center;gap:8px;}
.selected-seat-badge.show{display:flex;}
.loading-seats{text-align:center;padding:16px;color:var(--gray-300);font-size:13px;}
.list-card{background:var(--white);border-radius:var(--radius);box-shadow:var(--card-shadow);overflow:hidden;}
.list-card-header{background:linear-gradient(135deg,var(--navy),var(--navy-mid));color:var(--white);padding:16px 24px;font-size:14px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;display:flex;justify-content:space-between;align-items:center;}
.list-card-body{padding:20px;}
.status-tabs{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap;}
.status-tab{padding:7px 14px;border-radius:100px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;border:1.5px solid var(--gray-100);background:var(--white);color:var(--gray-500);transition:all var(--transition);}
.status-tab:hover{border-color:var(--blue);color:var(--blue);}
.status-tab.active{background:var(--blue);border-color:var(--blue);color:var(--white);}
.res-list{display:flex;flex-direction:column;gap:10px;}
.res-item{border:1.5px solid var(--gray-100);border-radius:var(--radius-sm);padding:14px 16px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;transition:all var(--transition);}
.res-item:hover{border-color:rgba(30,111,224,.2);background:#fafcff;}
.res-item.status-approved{border-left:3px solid #22c55e;}
.res-item.status-rejected{border-left:3px solid var(--danger);opacity:.75;}
.res-item.status-pending{border-left:3px solid #f59e0b;}
.res-lab{font-size:14px;font-weight:700;color:var(--navy);}
.res-meta{font-size:13px;color:var(--gray-500);display:flex;gap:12px;flex-wrap:wrap;margin-top:5px;}
.res-admin-note{margin-top:7px;font-size:13px;color:var(--gray-500);background:var(--off-white);border-radius:6px;padding:7px 11px;border-left:3px solid var(--gray-300);}
.cancel-btn{padding:5px 12px;background:rgba(239,68,68,.08);color:#b91c1c;border:1px solid rgba(239,68,68,.2);border-radius:var(--radius-sm);font-size:12px;font-weight:600;font-family:'Outfit',sans-serif;cursor:pointer;transition:all var(--transition);text-decoration:none;white-space:nowrap;flex-shrink:0;}
.cancel-btn:hover{background:rgba(239,68,68,.16);}
.badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:100px;font-size:12px;font-weight:600;}
.badge-blue{background:rgba(30,111,224,.1);color:var(--blue);border:1px solid rgba(30,111,224,.2);}
.badge-success{background:rgba(34,197,94,.1);color:#15803d;border:1px solid rgba(34,197,94,.3);}
.badge-warning{background:rgba(245,158,11,.1);color:#92400e;border:1px solid rgba(245,158,11,.3);}
.badge-danger{background:rgba(239,68,68,.08);color:#b91c1c;border:1px solid rgba(239,68,68,.2);}
.alert{padding:12px 16px;border-radius:var(--radius-sm);font-size:14px;margin-bottom:16px;display:flex;align-items:center;gap:10px;}
.alert-success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#15803d;}
.alert-danger{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#b91c1c;}
.empty-state{text-align:center;padding:40px 20px;}
.empty-icon{font-size:36px;margin-bottom:12px;}
.empty-title{font-size:15px;font-weight:600;color:var(--gray-700);margin-bottom:4px;}
.empty-desc{font-size:14px;color:var(--gray-500);}
@media(max-width:768px){.res-page{padding:16px;}}
</style>
</head>
<body>

<nav class="dashboard-navbar">
    <div class="dashboard-left">Dashboard</div>
    <ul class="dashboard-right">
        <li><a href="notifications.php">Notifications</a></li>
        <li><a href="userdb.php">Home</a></li>
        <li><a href="software.php">Software</a></li>
        <li><a href="edit_profile.php">Edit Profile</a></li>
        <li><a href="history.php">History</a></li>
        <li><a href="student_reservation.php" class="active">Reservation</a></li>
        <li><a href="logout.php" class="logout-btn">Log Out</a></li>
    </ul>
</nav>

<div class="res-page">
    <div class="page-title">Laboratory Reservation</div>
    <div class="page-subtitle">Book a computer laboratory slot and choose your seat</div>

    <?php if ($success_msg): ?><div class="alert alert-success">✓ <?php echo htmlspecialchars($success_msg); ?></div><?php endif; ?>
    <?php if ($error_msg):   ?><div class="alert alert-danger">⚠ <?php echo htmlspecialchars($error_msg); ?></div><?php endif; ?>
    <?php if (isset($_GET['cancelled'])): ?><div class="alert alert-success">✓ Reservation cancelled.</div><?php endif; ?>

    <div class="res-layout">

        <!-- Form -->
        <div class="form-card">
            <div class="form-card-header">New Reservation</div>
            <div class="form-card-body">
                <form method="POST" id="resForm">
                    <?php echo csrf_token(); ?>
                    <input type="hidden" name="seat_id" id="selectedSeatId" value="0">

                    <div class="form-group">
                        <label class="form-label">Purpose</label>
                        <select name="purpose" class="form-select" required>
                            <option value="">— Select Purpose —</option>
                            <option>C Programming</option><option>Java Programming</option>
                            <option>Python Programming</option><option>Web Development</option>
                            <option>Database</option><option>Research</option>
                            <option>Assignment</option><option>Examination</option><option>Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Laboratory</label>
                        <select name="lab" class="form-select" id="labSelect" required onchange="onLabOrDateChange()">
                            <option value="">— Select Lab —</option>
                            <?php foreach ($labs as $lab): ?>
                            <option value="<?php echo htmlspecialchars($lab['lab_name']); ?>">Lab <?php echo htmlspecialchars($lab['lab_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Preferred Date</label>
                        <!-- FIXED: onchange triggers seat reload for selected date -->
                        <input type="date" name="reservation_date" class="form-input" id="resDate"
                               min="<?php echo $min_date; ?>" max="<?php echo $max_date; ?>"
                               value="<?php echo $min_date; ?>"
                               required onchange="onLabOrDateChange()">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Preferred Time</label>
                        <input type="time" name="reservation_time" class="form-input" min="07:00" max="20:00" required>
                        <small style="color:var(--gray-500);font-size:12px;">Lab hours: 7:00 AM – 8:00 PM</small>
                    </div>

                    <!-- Seat Picker — FIXED: loads seats for the chosen date via AJAX -->
                    <div class="seat-picker" id="seatPicker" style="display:none;">
                        <div class="seat-picker-title">
                            <span>🖥️ Select Your Seat</span>
                            <span id="seatPickerInfo" style="font-size:11px;color:var(--gray-300);"></span>
                        </div>
                        <div class="seat-legend">
                            <span><span class="leg-dot leg-green"></span>Available</span>
                            <span><span class="leg-dot leg-amber"></span>Reserved</span>
                            <span><span class="leg-dot leg-red"></span>Maintenance</span>
                            <span><span class="leg-dot leg-blue"></span>Your Selection</span>
                        </div>
                        <div class="seat-grid-wrapper">
                            <div class="teacher-bar">🎓 Teacher's Desk</div>
                            <div id="seatGridEl" class="seat-grid"></div>
                        </div>
                        <div class="selected-seat-badge" id="selectedBadge">
                            🎯 Seat <span id="selectedSeatNum">—</span> selected
                        </div>
                    </div>

                    <div style="background:var(--off-white);border-radius:var(--radius-sm);padding:11px 13px;margin:14px 0;font-size:13px;color:var(--gray-500);line-height:1.6;">
                        📋 Reservations require admin approval. You will be notified once reviewed.
                    </div>

                    <button type="submit" name="submit_reservation" class="submit-btn">Submit Reservation</button>
                </form>
            </div>
        </div>

        <!-- My Reservations -->
        <div class="list-card">
            <div class="list-card-header">
                <span>My Reservations</span>
                <span style="font-size:13px;opacity:.7"><?php echo count($reservations); ?> total</span>
            </div>
            <div class="list-card-body">
                <div class="status-tabs">
                    <a href="student_reservation.php" class="status-tab <?php echo $filter_status===''?'active':''; ?>">All (<?php echo count($reservations); ?>)</a>
                    <a href="student_reservation.php?status=pending"  class="status-tab <?php echo $filter_status==='pending' ?'active':''; ?>">Pending (<?php echo $pending_c; ?>)</a>
                    <a href="student_reservation.php?status=approved" class="status-tab <?php echo $filter_status==='approved'?'active':''; ?>">Approved (<?php echo $approved_c; ?>)</a>
                    <a href="student_reservation.php?status=rejected" class="status-tab <?php echo $filter_status==='rejected'?'active':''; ?>">Rejected (<?php echo $rejected_c; ?>)</a>
                </div>

                <?php if (empty($reservations)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📅</div>
                    <div class="empty-title">No reservations yet</div>
                    <div class="empty-desc">Use the form to book a laboratory slot.</div>
                </div>
                <?php else: ?>
                <div class="res-list">
                    <?php
                    $sbadge = ['pending'=>'badge-warning','approved'=>'badge-success','rejected'=>'badge-danger','expired'=>'badge-danger','converted'=>'badge-success'];
                    $sicon  = ['pending'=>'⏳','approved'=>'✓','rejected'=>'✕','expired'=>'⌛','converted'=>'🟢'];
                    foreach ($reservations as $r):
                    ?>
                    <div class="res-item status-<?php echo $r['status']; ?>">
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:5px;">
                                <span class="res-lab">Lab <?php echo htmlspecialchars($r['lab']); ?></span>
                                <?php if ($r['seat_number']): ?><span class="badge badge-blue">Seat <?php echo $r['seat_number']; ?></span><?php endif; ?>
                                <span class="badge badge-blue"><?php echo htmlspecialchars($r['purpose']); ?></span>
                                <span class="badge <?php echo $sbadge[$r['status']] ?? 'badge-blue'; ?>"><?php echo ($sicon[$r['status']] ?? '') . ' ' . ucfirst($r['status']); ?></span>
                            </div>
                            <div class="res-meta">
                                <span>📅 <?php echo date('M d, Y', strtotime($r['reservation_date'])); ?></span>
                                <span>🕐 <?php echo date('h:i A', strtotime($r['reservation_time'])); ?></span>
                                <span>Submitted <?php echo date('M d', strtotime($r['created_at'])); ?></span>
                            </div>
                            <?php if ($r['admin_note']): ?>
                            <div class="res-admin-note">💬 <?php echo htmlspecialchars($r['admin_note']); ?></div>
                            <?php endif; ?>
                        </div>
                        <?php if ($r['status'] === 'pending'): ?>
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

<script>
// Lab info (rows/cols) — seats are loaded via AJAX per date+lab
const LABS_INFO = <?php echo json_encode($labs_info); ?>;
let selectedSeatId = 0;

// Called when lab OR date changes — reload seats via AJAX for the selected date
function onLabOrDateChange() {
    const labName = document.getElementById('labSelect').value;
    const date    = document.getElementById('resDate').value;
    resetSeatPicker();
    if (!labName || !date || !LABS_INFO[labName]) {
        document.getElementById('seatPicker').style.display = 'none';
        return;
    }
    document.getElementById('seatPicker').style.display = '';
    document.getElementById('seatGridEl').innerHTML = '<div class="loading-seats">Loading seats…</div>';

    const lab = LABS_INFO[labName];
    fetch(`student_reservation.php?ajax_seats=${lab.id}&date=${encodeURIComponent(date)}`)
        .then(r => r.json())
        .then(seats => renderSeats(seats, lab.cols))
        .catch(() => {
            document.getElementById('seatGridEl').innerHTML = '<div class="loading-seats">Could not load seats. Please refresh.</div>';
        });
}

function resetSeatPicker() {
    selectedSeatId = 0;
    document.getElementById('selectedSeatId').value = '0';
    document.getElementById('selectedBadge').classList.remove('show');
    document.getElementById('seatGridEl').innerHTML = '';
    document.getElementById('seatPickerInfo').textContent = '';
}

function renderSeats(seats, cols) {
    const gridEl = document.getElementById('seatGridEl');
    gridEl.innerHTML = '';
    cols = parseInt(cols);
    const half   = Math.ceil(cols / 2);
    const rowMap = {};
    seats.forEach(s => {
        const r = parseInt(s.row_pos);
        if (!rowMap[r]) rowMap[r] = {};
        rowMap[r][parseInt(s.col_pos)] = s;
    });

    let avail = 0, taken = 0, maint = 0;
    Object.keys(rowMap).map(Number).sort((a,b)=>a-b).forEach(rnum => {
        const rowDiv = document.createElement('div');
        rowDiv.className = 'seat-row-wrap';
        const cm = rowMap[rnum];
        for (let c = 1; c <= cols; c++) {
            if (c === half + 1) {
                const gap = document.createElement('div');
                gap.className = 'aisle-gap';
                rowDiv.appendChild(gap);
            }
            const seat = cm[c];
            if (!seat) {
                const ph = document.createElement('div');
                ph.style.cssText = 'width:42px;height:42px;flex-shrink:0;';
                rowDiv.appendChild(ph); continue;
            }
            const isInactive = parseInt(seat.is_active) === 0;
            const isTaken    = parseInt(seat.is_taken) > 0;
            const btn = document.createElement('button');
            btn.type = 'button';
            if (isInactive) {
                btn.className = 'seat-btn taken';
                btn.innerHTML = '<span class="seat-icon">🔧</span>' + seat.seat_number;
                btn.disabled  = true; btn.title = `Seat ${seat.seat_number} — Maintenance`;
                maint++;
            } else if (isTaken) {
                btn.className = 'seat-btn taken';
                btn.innerHTML = '<span class="seat-icon">👤</span>' + seat.seat_number;
                btn.disabled  = true; btn.title = `Seat ${seat.seat_number} — Already Reserved`;
                taken++;
            } else {
                btn.className = 'seat-btn available';
                btn.innerHTML = '<span class="seat-icon">🖥️</span>' + seat.seat_number;
                btn.title     = `Seat ${seat.seat_number} — Available`;
                btn.onclick   = () => selectSeat(seat.id, seat.seat_number, btn);
                avail++;
            }
            rowDiv.appendChild(btn);
        }
        gridEl.appendChild(rowDiv);
    });

    let parts = [`${avail} available`];
    if (taken) parts.push(`${taken} reserved`);
    if (maint) parts.push(`${maint} maintenance`);
    document.getElementById('seatPickerInfo').textContent = parts.join(' · ');
}

function selectSeat(seatId, seatNum, btn) {
    document.querySelectorAll('#seatGridEl .seat-btn').forEach(b => {
        if (b.dataset.selected === '1') {
            b.dataset.selected = '0';
            b.className = b.className.replace('selected','available');
        }
    });
    btn.dataset.selected = '1';
    btn.className = btn.className.replace('available','selected');
    selectedSeatId = seatId;
    document.getElementById('selectedSeatId').value       = seatId;
    document.getElementById('selectedSeatNum').textContent = seatNum;
    document.getElementById('selectedBadge').classList.add('show');
}
</script>
</body>
</html>
