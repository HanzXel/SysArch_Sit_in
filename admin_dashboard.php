<?php
session_start();
if(!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true){
    header("Location: login.php"); exit;
}
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
$conn->query("CREATE TABLE IF NOT EXISTS sit_in (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_number VARCHAR(50) NOT NULL,
    student_name VARCHAR(200) NOT NULL,
    purpose VARCHAR(100) NOT NULL,
    lab VARCHAR(50) NOT NULL,
    remaining_session INT NOT NULL,
    sit_in_date DATE NOT NULL,
    sit_in_time TIME NOT NULL,
    time_out TIME DEFAULT NULL,
    time_out_date DATE DEFAULT NULL,
    seat_number INT DEFAULT NULL,
    seat_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("ALTER TABLE sit_in ADD COLUMN IF NOT EXISTS seat_number INT DEFAULT NULL");
$conn->query("ALTER TABLE sit_in ADD COLUMN IF NOT EXISTS seat_id INT DEFAULT NULL");

// Seed default labs
if($conn->query("SELECT COUNT(*) as c FROM labs")->fetch_assoc()['c']==0){
    foreach([['524',5,8],['526',5,8],['528',5,8],['530',5,8],['542',5,8],['544',5,8]] as $l){
        $conn->query("INSERT IGNORE INTO labs (lab_name,description,`rows`,`cols`) VALUES ('{$l[0]}','Laboratory {$l[0]}',{$l[1]},{$l[2]})");
        $lid=$conn->insert_id;
        if($lid){ $sn=1; for($r=1;$r<=$l[1];$r++) for($c=1;$c<=$l[2];$c++){
            $conn->query("INSERT IGNORE INTO seats (lab_id,seat_number,row_pos,col_pos) VALUES ($lid,$sn,$r,$c)"); $sn++;
        }}
    }
}

// ── AJAX: student ID lookup ───────────────────────────────────────────
if(isset($_GET['id_lookup'])){
    $id = $_GET['id_lookup'];
    $s  = $conn->prepare("SELECT id,first_name,last_name,sessions FROM students WHERE id_number=?");
    $s->bind_param("s",$id); $s->execute();
    $row = $s->get_result()->fetch_assoc();
    if($row){
        echo '<div id="student-data" data-id="'.$row['id'].'" data-name="'.htmlspecialchars($row['first_name'].' '.$row['last_name']).'" data-sessions="'.$row['sessions'].'"></div>';
    } else { echo '<div id="student-data"></div>'; }
    $conn->close(); exit;
}

// ── Handle: Walk-in sit-in submission ────────────────────────────────
$sit_in_error = "";
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['walkin_submit'])){
    $id_number = trim($_POST['id_number']);
    $purpose   = $_POST['purpose'];
    $lab       = $_POST['lab'];
    $seat_id   = intval($_POST['seat_id'] ?? 0);
    $seat_num  = intval($_POST['seat_number_val'] ?? 0);

    $s = $conn->prepare("SELECT id,first_name,last_name,sessions FROM students WHERE id_number=?");
    $s->bind_param("s",$id_number); $s->execute();
    $st = $s->get_result()->fetch_assoc(); $s->close();

    if(!$st){ $sit_in_error="Student ID not found."; }
    elseif($st['sessions']<=0){ $sit_in_error="Student has no remaining sessions."; }
    else {
        $dup=$conn->prepare("SELECT id FROM sit_in WHERE id_number=? AND sit_in_date=CURDATE() AND time_out IS NULL");
        $dup->bind_param("s",$id_number); $dup->execute();
        if($dup->get_result()->num_rows>0){ $sit_in_error="Student already has an active sit-in today."; }
        elseif($seat_id>0){
            $sc=$conn->prepare("SELECT id FROM sit_in WHERE seat_id=? AND sit_in_date=CURDATE() AND time_out IS NULL");
            $sc->bind_param("i",$seat_id); $sc->execute();
            if($sc->get_result()->num_rows>0) $sit_in_error="That seat is already occupied.";
            $sc->close();
        }
        $dup->close();
        if(!$sit_in_error){
            $name=$st['first_name'].' '.$st['last_name'];
            $today=date('Y-m-d'); $now=date('H:i:s');
            $ins=$conn->prepare("INSERT INTO sit_in (id_number,student_name,purpose,lab,remaining_session,sit_in_date,sit_in_time,seat_id,seat_number) VALUES (?,?,?,?,?,?,?,?,?)");
            $ins->bind_param("ssssissii",$id_number,$name,$purpose,$lab,$st['sessions'],$today,$now,$seat_id,$seat_num);
            $ins->execute(); $ins->close();
            $conn->close(); header("Location: admin_dashboard.php?sitin=1"); exit;
        }
    }
}

// ── Handle: Reserved student check-in ───────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['reserved_submit'])){
    $res_id = intval($_POST['reservation_id']);

    $r=$conn->prepare("SELECT r.*,s.id as sdb_id,s.sessions FROM reservations r JOIN students s ON s.id_number=r.id_number WHERE r.id=? AND r.status='approved'");
    $r->bind_param("i",$res_id); $r->execute();
    $res=$r->get_result()->fetch_assoc(); $r->close();

    if(!$res){ $sit_in_error="Reservation not found or not approved."; }
    elseif($res['sessions']<=0){ $sit_in_error="Student has no remaining sessions."; }
    else {
        $dup=$conn->prepare("SELECT id FROM sit_in WHERE id_number=? AND sit_in_date=CURDATE() AND time_out IS NULL");
        $dup->bind_param("s",$res['id_number']); $dup->execute();
        if($dup->get_result()->num_rows>0){ $sit_in_error="Student already has an active sit-in today."; }
        $dup->close();

        if(!$sit_in_error){
            $today=date('Y-m-d'); $now=date('H:i:s');
            $seat_id  = intval($res['seat_id'] ?? 0);
            $seat_num = intval($res['seat_number'] ?? 0);

            $ins=$conn->prepare("INSERT INTO sit_in (id_number,student_name,purpose,lab,remaining_session,sit_in_date,sit_in_time,seat_id,seat_number) VALUES (?,?,?,?,?,?,?,?,?)");
            $ins->bind_param("ssssissii",
                $res['id_number'],$res['student_name'],$res['purpose'],$res['lab'],
                $res['sessions'],$today,$now,$seat_id,$seat_num
            );
            $ins->execute(); $ins->close();

            // Mark reservation as converted/checked-in
            $conn->query("UPDATE reservations SET status='converted' WHERE id=$res_id");

            // Notify student
            $msg="Your reservation for Lab {$res['lab']} (Seat {$seat_num}) has been checked in. Your sit-in session is now active.";
            $sid=$res['sdb_id'];
            $conn->query("INSERT INTO notifications (student_id,title,message,type) VALUES ($sid,'Checked In','".mysqli_real_escape_string($conn,$msg)."','success')");

            $conn->close(); header("Location: admin_dashboard.php?sitin=1"); exit;
        }
    }
}

// ── Handle: Announcement ─────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['message'])){
    $an=$_SESSION['admin_username']; $ad=$_POST['announcement_date']; $m=$_POST['message'];
    $s=$conn->prepare("INSERT INTO announcements (admin_name,announcement_date,message) VALUES (?,?,?)");
    $s->bind_param("sss",$an,$ad,$m); $s->execute(); $s->close();
    header("Location: admin_dashboard.php?posted=1"); exit;
}

// ── Stats ─────────────────────────────────────────────────────────────
$student_count      = $conn->query("SELECT COUNT(*) as c FROM students")->fetch_assoc()['c'];
$announcement_count = $conn->query("SELECT COUNT(*) as c FROM announcements")->fetch_assoc()['c'];
$today_sitin_count  = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE sit_in_date=CURDATE()")->fetch_assoc()['c'];
$active_labs_count  = $conn->query("SELECT COUNT(*) as c FROM labs WHERE is_active=1")->fetch_assoc()['c'];

$monthly_data=array_fill_keys(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],0);
$res=$conn->query("SELECT MONTH(created_at) as m,COUNT(*) as c FROM students GROUP BY MONTH(created_at)");
$months=[1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
while($row=$res->fetch_assoc()) $monthly_data[$months[$row['m']]]=$row['c'];
$max_val=max($monthly_data)?:1;

// ── Labs and seats JSON ───────────────────────────────────────────────
$active_labs=$conn->query("SELECT * FROM labs WHERE is_active=1 ORDER BY lab_name")->fetch_all(MYSQLI_ASSOC);
$labs_seats_json=[];
foreach($active_labs as $lab){
    $sr=$conn->query("SELECT s.*,
        (SELECT COUNT(*) FROM sit_in si WHERE si.lab='{$lab['lab_name']}' AND si.seat_number=s.seat_number AND si.sit_in_date=CURDATE() AND si.time_out IS NULL) as is_occupied
        FROM seats s WHERE s.lab_id={$lab['id']} ORDER BY s.row_pos,s.col_pos");
    $labs_seats_json[$lab['lab_name']]=['id'=>$lab['id'],'rows'=>(int)$lab['rows'],'cols'=>(int)$lab['cols'],'seats'=>$sr->fetch_all(MYSQLI_ASSOC)];
}

// ── Today's approved reservations (for Reserved Student panel) ────────
$conn->query("ALTER TABLE reservations MODIFY COLUMN status ENUM('pending','approved','rejected','expired','converted') DEFAULT 'pending'");
// Auto-expire grace-exceeded reservations
$conn->query("UPDATE reservations SET status='expired' WHERE status='approved' AND reservation_date=CURDATE() AND ADDTIME(reservation_time,'00:10:00') < CURTIME()");

$approved_today = $conn->query("
    SELECT r.*, s.sessions, s.profile_picture
    FROM reservations r
    JOIN students s ON s.id_number=r.id_number
    WHERE r.status='approved'
      AND r.reservation_date=CURDATE()
    ORDER BY r.reservation_time ASC
")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — CCS Sit-in</title>
<link rel="stylesheet" href="admin_dashboard.css">
<link rel="icon" type="image/png" href="pictures/uclogo.png">
<style>
/* ── Sit-in type selector ── */
.sitin-type-tabs{display:flex;gap:0;border-radius:var(--radius-sm);overflow:hidden;border:1.5px solid var(--gray-100);margin-bottom:18px;}
.sitin-type-tab{flex:1;padding:10px 6px;text-align:center;font-size:13px;font-weight:700;cursor:pointer;background:var(--white);color:var(--gray-500);border:none;font-family:Outfit,sans-serif;transition:all .2s;border-right:1.5px solid var(--gray-100);}
.sitin-type-tab:last-child{border-right:none;}
.sitin-type-tab.active{background:var(--navy);color:#fff;}
.sitin-type-tab:hover:not(.active){background:var(--off-white);}

/* ── Walk-in / Reserved panels ── */
.sitin-panel{display:none;}
.sitin-panel.show{display:block;}

/* ── Reserved student list ── */
.res-list-wrap{display:flex;flex-direction:column;gap:8px;max-height:360px;overflow-y:auto;padding-right:2px;}
.res-row{background:var(--off-white);border-radius:var(--radius-sm);padding:12px 14px;display:flex;align-items:center;gap:14px;border:1.5px solid transparent;cursor:pointer;transition:all .2s;}
.res-row:hover{border-color:rgba(30,111,224,.3);background:var(--white);}
.res-row.selected-res{border-color:var(--blue);background:rgba(30,111,224,.06);}
.res-avatar{width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid var(--gray-100);flex-shrink:0;}
.res-info{flex:1;min-width:0;}
.res-name{font-size:14px;font-weight:700;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.res-meta{font-size:12px;color:var(--gray-500);margin-top:2px;display:flex;gap:10px;flex-wrap:wrap;}
.res-badges{display:flex;gap:5px;flex-wrap:wrap;margin-top:5px;}
.res-sel-indicator{width:20px;height:20px;border-radius:50%;border:2px solid var(--gray-100);flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:11px;transition:all .2s;}
.res-row.selected-res .res-sel-indicator{background:var(--blue);border-color:var(--blue);color:#fff;}
.no-reserved{text-align:center;padding:32px 20px;color:var(--gray-300);}
.no-reserved-icon{font-size:36px;margin-bottom:10px;}

/* ── Seat picker (shared) ── */
.seat-picker-wrap{background:#f0f4ff;border-radius:10px;padding:14px;margin-top:10px;}
.seat-picker-title{font-size:12px;font-weight:700;color:#374357;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;}
.seat-legend-sm{display:flex;gap:12px;font-size:11px;margin-bottom:10px;flex-wrap:wrap;}
.leg-dot{width:11px;height:11px;border-radius:3px;display:inline-block;margin-right:3px;vertical-align:middle;}
.leg-green{background:#22c55e;} .leg-amber{background:#f59e0b;} .leg-red{background:#ef4444;} .leg-blue{background:#1e6fe0;}
.seat-grid-outer{overflow-x:auto;}
.teacher-bar-sm{background:linear-gradient(135deg,#0a1628,#112240);color:#fff;border-radius:7px;padding:5px 16px;font-size:10px;font-weight:700;text-align:center;margin:0 auto 10px;display:block;width:fit-content;}
.seat-rows-wrap{display:flex;flex-direction:column;gap:5px;}
.seat-row-sm{display:flex;gap:4px;align-items:center;}
.seat-btn-sm{width:40px;height:40px;flex-shrink:0;border-radius:8px;border:2px solid transparent;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:8px;font-weight:800;font-family:Outfit,sans-serif;cursor:pointer;transition:all .18s;}
.seat-btn-sm.avail{background:rgba(34,197,94,.15);border-color:rgba(34,197,94,.4);color:#15803d;}
.seat-btn-sm.avail:hover{background:rgba(34,197,94,.3);transform:scale(1.08);}
.seat-btn-sm.occupied{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.35);color:#92400e;cursor:not-allowed;}
.seat-btn-sm.maintenance{background:rgba(239,68,68,.10);border-color:rgba(239,68,68,.30);color:#b91c1c;cursor:not-allowed;}
.seat-btn-sm.selected-seat{background:#1e6fe0;border-color:#1e6fe0;color:#fff;}
.seat-btn-sm.reserved-seat{background:rgba(139,92,246,.15);border-color:rgba(139,92,246,.4);color:#6d28d9;cursor:not-allowed;}
.aisle-gap-sm{width:14px;flex-shrink:0;}
.seat-icon-sm{font-size:13px;margin-bottom:1px;}
.selected-seat-info{margin-top:10px;background:rgba(30,111,224,.08);border:1px solid rgba(30,111,224,.25);border-radius:8px;padding:8px 12px;font-size:13px;color:#1e6fe0;font-weight:600;display:none;}
.selected-seat-info.show{display:block;}

/* modal scroll */
.modal-scroll{max-height:82vh;overflow-y:auto;}

/* form helpers */
.form-row-modal{margin-bottom:12px;}
.form-row-modal label{display:block;font-size:13px;font-weight:600;color:#374357;margin-bottom:5px;}
.form-input-modal{width:100%;padding:10px 14px;border:1.5px solid #e8edf5;border-radius:10px;font-size:14px;font-family:Outfit,sans-serif;color:#0a1628;outline:none;background:#fafbff;transition:all .2s;}
.form-input-modal:focus{border-color:#1e6fe0;box-shadow:0 0 0 3px rgba(30,111,224,.1);}
.form-input-modal[readonly]{background:#f0f4ff;color:#6b7a96;}
</style>
</head>
<body>

<nav class="dashboard-navbar">
    <div class="dashboard-left">
        <img class="admin-logo" src="pictures/uclogo.png" alt="">
        <span class="admin-title">Admin Dashboard</span>
    </div>
    <ul class="dashboard-right">
        <li><a href="admin_dashboard.php" class="active">Dashboard</a></li>
        <li><a href="manage_students.php">Manage Students</a></li>
        <li><a href="sitin_logs.php">Sit-in Logs</a></li>
        <li><a href="manage_labs.php">Manage Labs</a></li>
        <li><a href="manage_software.php">Manage Software</a></li>
        <li><a href="reservations.php">Reservations</a></li>
        <li><a href="reports.php">Reports</a></li>
        <li><a href="student_feedback.php">Feedback</a></li>
        <li><a href="logout.php" class="logout-btn">Log Out</a></li>
    </ul>
</nav>

<div class="dashboard-container">
<div class="dashboard-main">

    <!-- STATS -->
    <div class="stats-container">
        <div class="stat-card"><div class="stat-icon"><img src="pictures/uclogo.png" alt=""></div><div class="stat-info"><h3><?php echo $student_count;?></h3><p>Total Students</p></div></div>
        <div class="stat-card"><div class="stat-icon"><img src="pictures/uclogo.png" alt=""></div><div class="stat-info"><h3><?php echo $announcement_count;?></h3><p>Announcements</p></div></div>
        <div class="stat-card"><div class="stat-icon"><img src="pictures/uclogo.png" alt=""></div><div class="stat-info"><h3><?php echo $active_labs_count;?></h3><p>Active Labs</p></div></div>
        <div class="stat-card"><div class="stat-icon"><img src="pictures/uclogo.png" alt=""></div><div class="stat-info"><h3><?php echo $today_sitin_count;?></h3><p>Today's Sit-ins</p></div></div>
    </div>

    <?php if(isset($_GET['sitin'])): ?>
    <div style="padding:12px 16px;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#15803d;border-radius:10px;font-size:14px;">✓ Sit-in recorded successfully.</div>
    <?php endif;?>

    <!-- CHART -->
    <div class="dashboard-card chart-card">
        <div class="card-header">Student Registration Statistics</div>
        <div class="card-body">
            <div class="y-axis-label">Number of Students</div>
            <div class="bar-chart">
                <?php foreach($monthly_data as $month=>$count):?>
                <div class="bar-wrapper">
                    <span class="bar-count"><?php echo $count;?></span>
                    <div class="bar" style="height:<?php echo ($count/$max_val*100);?>%;"></div>
                </div>
                <?php endforeach;?>
            </div>
            <div class="chart-bottom">
                <?php foreach(array_keys($monthly_data) as $m):?><span class="month-label"><?php echo $m;?></span><?php endforeach;?>
            </div>
        </div>
    </div>

    <!-- QUICK ACTIONS -->
    <div class="dashboard-card">
        <div class="card-header">Quick Actions</div>
        <div class="card-body">
            <div class="actions-grid">
                <button class="action-btn" id="openSitIn"><span class="action-icon">👤</span><span class="action-text">+ Sit-in</span></button>
                <button class="action-btn" onclick="document.querySelector('.announcement-form textarea').scrollIntoView({behavior:'smooth'});document.querySelector('.announcement-form textarea').focus();"><span class="action-icon">📢</span><span class="action-text">Post Announcement</span></button>
                <button class="action-btn" onclick="window.location.href='reports.php'"><span class="action-icon">📋</span><span class="action-text">View Reports</span></button>
                <button class="action-btn" onclick="window.location.href='manage_students.php'"><span class="action-icon">⚙️</span><span class="action-text">Manage Students</span></button>
            </div>
        </div>
    </div>

    <!-- ANNOUNCEMENT FORM -->
    <div class="dashboard-card">
        <div class="card-header">Post Announcement</div>
        <div class="card-body">
            <?php if(isset($_GET['posted'])):?><div style="padding:12px 16px;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#15803d;border-radius:10px;font-size:14px;margin-bottom:16px;">✓ Announcement posted.</div><?php endif;?>
            <form class="announcement-form" method="POST">
                <div class="form-group"><label>Admin Name</label><input type="text" name="admin_name" value="<?php echo htmlspecialchars($_SESSION['admin_username']);?>" readonly></div>
                <div class="form-group"><label>Date</label><input type="date" name="announcement_date" required></div>
                <div class="form-group"><label>Message</label><textarea name="message" rows="4" placeholder="Write your announcement here..." required></textarea></div>
                <button type="submit" class="submit-btn">Post Announcement</button>
            </form>
        </div>
    </div>

</div>
</div>

<!-- ════════════════════════════════════════════════
     SIT-IN MODAL — Walk-in | Reserved
════════════════════════════════════════════════ -->
<div class="modal-overlay" id="sitInModal">
    <div class="modal-box" style="max-width:540px;">
        <div class="modal-header">
            <h2>New Sit-in Entry</h2>
            <span class="close-btn" id="closeSitIn">&times;</span>
        </div>
        <div class="modal-body modal-scroll">

            <?php if($sit_in_error):?>
            <div style="padding:10px 14px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#b91c1c;border-radius:8px;font-size:13px;margin-bottom:14px;">
                ⚠ <?php echo htmlspecialchars($sit_in_error);?>
            </div>
            <?php endif;?>

            <!-- Type selector tabs -->
            <div class="sitin-type-tabs">
                <button class="sitin-type-tab active" id="tab-walkin" onclick="switchTab('walkin')">🚶 Walk-in Student</button>
                <button class="sitin-type-tab" id="tab-reserved" onclick="switchTab('reserved')">
                    📅 Reserved Student
                    <?php if(count($approved_today)>0):?>
                    <span style="background:#1e6fe0;color:#fff;border-radius:100px;font-size:10px;padding:1px 6px;margin-left:4px;"><?php echo count($approved_today);?></span>
                    <?php endif;?>
                </button>
            </div>

            <!-- ══ WALK-IN PANEL ══ -->
            <div class="sitin-panel show" id="panel-walkin">
                <form method="POST" id="walkinForm">
                    <input type="hidden" name="seat_id"         id="wi_seat_id"  value="0">
                    <input type="hidden" name="seat_number_val" id="wi_seat_num" value="0">

                    <div class="form-row-modal">
                        <label>Student ID Number</label>
                        <input type="text" name="id_number" id="wi_idnum" class="form-input-modal"
                               placeholder="Enter student ID" onblur="fetchStudent()" required>
                    </div>
                    <div class="form-row-modal">
                        <label>Student Name</label>
                        <input type="text" id="wi_name" class="form-input-modal" placeholder="Auto-filled" readonly>
                    </div>
                    <div class="form-row-modal">
                        <label>Purpose</label>
                        <select name="purpose" class="form-input-modal" required>
                            <option value="">— Select Purpose —</option>
                            <option>C Programming</option><option>Java Programming</option>
                            <option>Python Programming</option><option>Web Development</option>
                            <option>Database</option><option>Research</option>
                            <option>Assignment</option><option>Examination</option><option>Other</option>
                        </select>
                    </div>
                    <div class="form-row-modal">
                        <label>Laboratory</label>
                        <select name="lab" id="wi_lab" class="form-input-modal" required onchange="loadWiSeats(this.value)">
                            <option value="">— Select Laboratory —</option>
                            <?php foreach($active_labs as $lab):?>
                            <option value="<?php echo htmlspecialchars($lab['lab_name']);?>">
                                Lab <?php echo htmlspecialchars($lab['lab_name']);?>
                                <?php if($lab['description']):?> — <?php echo htmlspecialchars($lab['description']);?><?php endif;?>
                            </option>
                            <?php endforeach;?>
                        </select>
                    </div>
                    <div class="form-row-modal">
                        <label>Remaining Sessions</label>
                        <input type="number" id="wi_sessions" class="form-input-modal" placeholder="Auto-filled" readonly>
                    </div>

                    <!-- Seat picker for walk-in -->
                    <div id="wi_seat_picker" style="display:none;">
                        <div class="seat-picker-wrap">
                            <div class="seat-picker-title">
                                🖥️ Select PC / Seat
                                <span id="wi_seat_info" style="font-size:11px;color:#b0bdd0;"></span>
                            </div>
                            <div class="seat-legend-sm">
                                <span><span class="leg-dot leg-green"></span>Available</span>
                                <span><span class="leg-dot leg-amber"></span>Occupied</span>
                                <span><span class="leg-dot leg-red"></span>Maintenance</span>
                                <span><span class="leg-dot leg-blue"></span>Selected</span>
                            </div>
                            <div class="seat-grid-outer">
                                <div class="teacher-bar-sm">🎓 Teacher's Desk</div>
                                <div class="seat-rows-wrap" id="wi_seat_rows"></div>
                            </div>
                            <div class="selected-seat-info" id="wi_sel_info">🎯 Seat <span id="wi_sel_num">—</span> selected</div>
                        </div>
                    </div>

                    <div class="modal-actions" style="margin-top:16px;padding-top:14px;border-top:1px solid #e8edf5;display:flex;justify-content:flex-end;gap:10px;">
                        <button type="button" class="btn-close" id="closeSitIn2">Cancel</button>
                        <button type="submit" name="walkin_submit" class="btn-submit">✓ Check In</button>
                    </div>
                </form>
            </div>

            <!-- ══ RESERVED PANEL ══ -->
            <div class="sitin-panel" id="panel-reserved">
                <?php if(empty($approved_today)):?>
                <div class="no-reserved">
                    <div class="no-reserved-icon">📅</div>
                    <div style="font-size:14px;font-weight:600;color:#374357;margin-bottom:6px;">No approved reservations for today</div>
                    <div style="font-size:13px;color:#b0bdd0;">Approved reservations for today will appear here.</div>
                </div>
                <?php else:?>
                <div style="font-size:13px;color:#6b7a96;margin-bottom:12px;">
                    Select a student with an approved reservation to check them in.
                </div>
                <form method="POST" id="reservedForm">
                    <input type="hidden" name="reservation_id" id="sel_res_id" value="0">

                    <div class="res-list-wrap">
                        <?php foreach($approved_today as $idx=>$res):
                            $pic=$res['profile_picture']?:'default.png';
                            $seat_label=$res['seat_number']?'Seat '.$res['seat_number']:'No seat assigned';
                            // Grace window countdown
                            $grace_end=strtotime($res['reservation_time'])+600;
                            $mins_left=ceil(($grace_end-time())/60);
                            $grace_str=$mins_left>0?"⏱ {$mins_left}m left":"⏰ Grace period over";
                        ?>
                        <div class="res-row" id="resrow_<?php echo $res['id'];?>"
                             onclick="selectReservation(<?php echo $res['id'];?>,this)">
                            <img class="res-avatar"
                                 src="profile_pictures/<?php echo htmlspecialchars($pic);?>"
                                 onerror="this.src='profile_pictures/default.png'" alt="">
                            <div class="res-info">
                                <div class="res-name"><?php echo htmlspecialchars($res['student_name']);?></div>
                                <div class="res-meta">
                                    <span>🪪 <?php echo htmlspecialchars($res['id_number']);?></span>
                                    <span>🕐 <?php echo date('h:i A',strtotime($res['reservation_time']));?></span>
                                </div>
                                <div class="res-badges">
                                    <span style="background:rgba(30,111,224,.1);color:#1e6fe0;border:1px solid rgba(30,111,224,.2);padding:2px 8px;border-radius:100px;font-size:11px;font-weight:600;">Lab <?php echo htmlspecialchars($res['lab']);?></span>
                                    <span style="background:rgba(34,197,94,.1);color:#15803d;border:1px solid rgba(34,197,94,.3);padding:2px 8px;border-radius:100px;font-size:11px;font-weight:600;"><?php echo $seat_label;?></span>
                                    <span style="background:rgba(245,158,11,.1);color:#92400e;border:1px solid rgba(245,158,11,.3);padding:2px 8px;border-radius:100px;font-size:11px;font-weight:600;"><?php echo htmlspecialchars($res['purpose']);?></span>
                                    <span style="background:rgba(107,114,128,.1);color:#374151;border:1px solid rgba(107,114,128,.2);padding:2px 8px;border-radius:100px;font-size:11px;font-weight:500;"><?php echo $grace_str;?></span>
                                </div>
                            </div>
                            <div class="res-sel-indicator" id="ressel_<?php echo $res['id'];?>">✓</div>
                        </div>
                        <?php endforeach;?>
                    </div>

                    <!-- Confirm panel (shown after selection) -->
                    <div id="res_confirm_panel" style="display:none;margin-top:14px;background:rgba(34,197,94,.06);border:1.5px solid rgba(34,197,94,.3);border-radius:10px;padding:14px 16px;">
                        <div style="font-size:13px;font-weight:700;color:#0a1628;margin-bottom:6px;">✓ Ready to Check In</div>
                        <div id="res_confirm_text" style="font-size:13px;color:#6b7a96;line-height:1.7;"></div>
                    </div>

                    <div class="modal-actions" style="margin-top:16px;padding-top:14px;border-top:1px solid #e8edf5;display:flex;justify-content:flex-end;gap:10px;">
                        <button type="button" class="btn-close" onclick="closeModal()">Cancel</button>
                        <button type="submit" name="reserved_submit" id="res_submit_btn" class="btn-submit" disabled
                                style="opacity:.5;cursor:not-allowed;">
                            🟢 Check In Student
                        </button>
                    </div>
                </form>
                <?php endif;?>
            </div>

        </div>
    </div>
</div>

<script>
const LABS_SEATS=<?php echo json_encode($labs_seats_json);?>;

// ── Modal open/close ──────────────────────────────────────────────────
const modal=document.getElementById('sitInModal');
document.getElementById('openSitIn').onclick=()=>modal.classList.add('active');
document.getElementById('closeSitIn').onclick=closeModal;
document.getElementById('closeSitIn2').onclick=closeModal;
window.onclick=e=>{ if(e.target===modal) closeModal(); };
function closeModal(){ modal.classList.remove('active'); }

<?php if($sit_in_error): ?>modal.classList.add('active');<?php endif;?>

// ── Tab switcher ──────────────────────────────────────────────────────
function switchTab(tab){
    document.querySelectorAll('.sitin-type-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.sitin-panel').forEach(p=>p.classList.remove('show'));
    document.getElementById('tab-'+tab).classList.add('active');
    document.getElementById('panel-'+tab).classList.add('show');
}

// ── Walk-in: fetch student info ───────────────────────────────────────
function fetchStudent(){
    const id=document.getElementById('wi_idnum').value.trim();
    if(!id) return;
    fetch('admin_dashboard.php?id_lookup='+encodeURIComponent(id))
        .then(r=>r.text()).then(data=>{
            const doc=new DOMParser().parseFromString(data,'text/html');
            const el=doc.getElementById('student-data');
            if(el&&el.getAttribute('data-id')){
                document.getElementById('wi_name').value    =el.getAttribute('data-name');
                document.getElementById('wi_sessions').value=el.getAttribute('data-sessions');
            } else {
                alert('Student not found.'); 
                document.getElementById('wi_name').value=''; 
                document.getElementById('wi_sessions').value='';
            }
        });
}

// ── Walk-in: seat picker ──────────────────────────────────────────────
let wiActiveSeat=null;
function loadWiSeats(labName){
    const picker=document.getElementById('wi_seat_picker');
    const rowsWrap=document.getElementById('wi_seat_rows');
    document.getElementById('wi_seat_id').value='0';
    document.getElementById('wi_seat_num').value='0';
    document.getElementById('wi_sel_info').classList.remove('show');
    wiActiveSeat=null;
    if(!labName||!LABS_SEATS[labName]){picker.style.display='none';return;}
    picker.style.display='';
    renderSeatGrid(LABS_SEATS[labName], rowsWrap, 'wi', document.getElementById('wi_seat_info'));
}
function renderSeatGrid(lab, container, prefix, infoEl){
    container.innerHTML='';
    const cols=parseInt(lab.cols), half=Math.ceil(cols/2);
    const rowMap={};
    lab.seats.forEach(s=>{ const r=parseInt(s.row_pos); if(!rowMap[r]) rowMap[r]={}; rowMap[r][parseInt(s.col_pos)]=s; });
    let avail=0,occ=0,maint=0;
    Object.keys(rowMap).map(Number).sort((a,b)=>a-b).forEach(rnum=>{
        const rowDiv=document.createElement('div'); rowDiv.className='seat-row-sm';
        const cm=rowMap[rnum];
        for(let c=1;c<=cols;c++){
            if(c===half+1){ const a=document.createElement('div'); a.className='aisle-gap-sm'; rowDiv.appendChild(a); }
            const seat=cm[c];
            if(!seat){ const ph=document.createElement('div'); ph.style.cssText='width:40px;height:40px;flex-shrink:0;'; rowDiv.appendChild(ph); continue; }
            const isMaint=parseInt(seat.is_active)===0, isOcc=parseInt(seat.is_occupied)>0;
            const btn=document.createElement('button'); btn.type='button';
            let cls,icon;
            if(isMaint){cls='seat-btn-sm maintenance';icon='🔧';btn.disabled=true;maint++;}
            else if(isOcc){cls='seat-btn-sm occupied';icon='👤';btn.disabled=true;occ++;}
            else{cls='seat-btn-sm avail';icon='🖥️';btn.onclick=()=>selectWiSeat(btn,seat.id,seat.seat_number,prefix);avail++;}
            btn.className=cls; btn.title=`Seat ${seat.seat_number}`;
            btn.innerHTML=`<span class="seat-icon-sm">${icon}</span>${seat.seat_number}`;
            rowDiv.appendChild(btn);
        }
        container.appendChild(rowDiv);
    });
    if(infoEl) infoEl.textContent=`${avail} available · ${occ} occupied · ${maint} maintenance`;
}
function selectWiSeat(btn,seatId,seatNum,prefix){
    document.querySelectorAll('#wi_seat_rows .selected-seat').forEach(b=>{ b.className=b.className.replace('selected-seat','avail'); });
    btn.className=btn.className.replace('avail','selected-seat');
    document.getElementById('wi_seat_id').value=seatId;
    document.getElementById('wi_seat_num').value=seatNum;
    document.getElementById('wi_sel_num').textContent=seatNum;
    document.getElementById('wi_sel_info').classList.add('show');
}

// ── Reserved: select reservation ─────────────────────────────────────
let selectedResId=0;
function selectReservation(resId, rowEl){
    // Deselect all
    document.querySelectorAll('.res-row').forEach(r=>{ r.classList.remove('selected-res'); });
    document.querySelectorAll('[id^="ressel_"]').forEach(i=>{ i.style.background=''; i.style.borderColor=''; i.style.color=''; });
    // Select this
    rowEl.classList.add('selected-res');
    document.getElementById('ressel_'+resId).style.cssText='background:#1e6fe0;border-color:#1e6fe0;color:#fff;';
    selectedResId=resId;
    document.getElementById('sel_res_id').value=resId;
    // Show confirm panel
    const row=rowEl;
    const name=row.querySelector('.res-name').textContent;
    const badges=Array.from(row.querySelectorAll('.res-badges span')).map(s=>s.textContent.trim());
    document.getElementById('res_confirm_text').innerHTML=
        `<strong>${name}</strong> will be checked in to <strong>${badges[0]}</strong>, <strong>${badges[1]}</strong> for <strong>${badges[2]}</strong>.`;
    document.getElementById('res_confirm_panel').style.display='';
    const btn=document.getElementById('res_submit_btn');
    btn.disabled=false; btn.style.opacity='1'; btn.style.cursor='pointer';
}
</script>

</body>
</html>