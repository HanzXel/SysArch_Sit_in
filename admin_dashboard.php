<?php
session_start();

if(!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true){
    header("Location: login.php");
    exit;
}

include 'Database/connect.php';

// ── Ensure labs / seats tables exist ────────────────────────────────
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

// Seed default labs if none exist yet
if($conn->query("SELECT COUNT(*) as c FROM labs")->fetch_assoc()['c'] == 0){
    foreach([['524',5,8],['526',5,8],['528',5,8],['530',5,8],['542',5,8],['544',5,8]] as $l){
        $conn->query("INSERT IGNORE INTO labs (lab_name,description,`rows`,`cols`) VALUES ('{$l[0]}','Laboratory {$l[0]}',{$l[1]},{$l[2]})");
        $lid = $conn->insert_id;
        if($lid){ $sn=1; for($r=1;$r<=$l[1];$r++) for($c=1;$c<=$l[2];$c++){
            $conn->query("INSERT IGNORE INTO seats (lab_id,seat_number,row_pos,col_pos) VALUES ($lid,$sn,$r,$c)");
            $sn++;
        }}
    }
}

// ── AJAX: student ID lookup ──────────────────────────────────────────
if(isset($_GET['id_lookup'])){
    $lookup_id = $_GET['id_lookup'];
    $stmt = $conn->prepare("SELECT id, first_name, last_name, sessions FROM students WHERE id_number = ?");
    $stmt->bind_param("s", $lookup_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if($row = $result->fetch_assoc()){
        $student_name = $row['first_name'].' '.$row['last_name'];
        echo '<div id="student-data" data-id="'.$row['id'].'" data-name="'.htmlspecialchars($student_name).'" data-sessions="'.$row['sessions'].'"></div>';
    } else {
        echo '<div id="student-data"></div>';
    }
    $stmt->close(); $conn->close(); exit;
}

// ── AJAX: seats for a lab ────────────────────────────────────────────
if(isset($_GET['seats_for_lab'])){
    $lab_name = trim($_GET['seats_for_lab']);
    $date     = trim($_GET['date'] ?? date('Y-m-d'));

    $lab_row = $conn->query("SELECT * FROM labs WHERE lab_name='".mysqli_real_escape_string($conn,$lab_name)."' AND is_active=1")->fetch_assoc();
    if(!$lab_row){ echo json_encode(['rows'=>0,'cols'=>0,'seats'=>[]]); $conn->close(); exit; }

    $lid  = $lab_row['id'];
    $rows = $lab_row['rows'];
    $cols = $lab_row['cols'];

    // All seats for this lab — include inactive so we can mark maintenance
    $sr = $conn->query("SELECT s.*,
        (SELECT COUNT(*) FROM sit_in si
         WHERE si.lab='{$lab_row['lab_name']}' AND si.seat_number=s.seat_number
           AND si.sit_in_date='$date' AND si.time_out IS NULL) as is_occupied
        FROM seats s WHERE s.lab_id=$lid ORDER BY s.row_pos, s.col_pos");
    $seats = $sr->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['rows'=>(int)$rows,'cols'=>(int)$cols,'seats'=>$seats]);
    $conn->close(); exit;
}

// ── Auto-create sit_in table ─────────────────────────────────────────
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
// Patch existing table if seat columns missing
$conn->query("ALTER TABLE sit_in ADD COLUMN IF NOT EXISTS seat_number INT DEFAULT NULL");
$conn->query("ALTER TABLE sit_in ADD COLUMN IF NOT EXISTS seat_id INT DEFAULT NULL");

$sit_in_error = "";

// ── Handle Sit-in form submission ────────────────────────────────────
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['id_number'])){
    $id_number  = $_POST['id_number'];
    $purpose    = $_POST['purpose'];
    $lab        = $_POST['lab'];
    $seat_id    = intval($_POST['seat_id'] ?? 0);
    $seat_num   = intval($_POST['seat_number_val'] ?? 0);

    $check = $conn->prepare("SELECT id, first_name, last_name, sessions FROM students WHERE id_number = ?");
    $check->bind_param("s", $id_number);
    $check->execute();
    $check_result = $check->get_result();

    if($check_result->num_rows == 0){
        $sit_in_error = "Student ID not found in the system.";
    } else {
        $student_row = $check_result->fetch_assoc();
        if($student_row['sessions'] <= 0){
            $sit_in_error = "Student has no remaining sessions.";
        } else {
            // Check already active sit-in today
            $dup = $conn->prepare("SELECT id FROM sit_in WHERE id_number=? AND sit_in_date=CURDATE() AND time_out IS NULL");
            $dup->bind_param("s", $id_number);
            $dup->execute();
            if($dup->get_result()->num_rows > 0){
                $sit_in_error = "This student already has an active sit-in session today.";
            } else {
                // Check seat not already occupied (if seat was selected)
                if($seat_id > 0){
                    $sc = $conn->prepare("SELECT id FROM sit_in WHERE seat_id=? AND sit_in_date=CURDATE() AND time_out IS NULL");
                    $sc->bind_param("i", $seat_id);
                    $sc->execute();
                    if($sc->get_result()->num_rows > 0){
                        $sit_in_error = "That seat is already occupied. Please select a different seat.";
                    }
                    $sc->close();
                }

                if(!$sit_in_error){
                    $db_name  = $student_row['first_name'].' '.$student_row['last_name'];
                    $today    = date('Y-m-d');
                    $now      = date('H:i:s');

                    $ins = $conn->prepare("INSERT INTO sit_in (id_number,student_name,purpose,lab,remaining_session,sit_in_date,sit_in_time,seat_id,seat_number) VALUES (?,?,?,?,?,?,?,?,?)");
                    $ins->bind_param("ssssissis",
                        $id_number, $db_name, $purpose, $lab,
                        $student_row['sessions'], $today, $now,
                        $seat_id, $seat_num
                    );
                    // Fix type string: i s s s i s s i i = 9 params
                    $ins->close();

                    $ins2 = $conn->prepare("INSERT INTO sit_in (id_number,student_name,purpose,lab,remaining_session,sit_in_date,sit_in_time,seat_id,seat_number) VALUES (?,?,?,?,?,?,?,?,?)");
                    $ins2->bind_param("ssssissii",
                        $id_number, $db_name, $purpose, $lab,
                        $student_row['sessions'], $today, $now,
                        $seat_id, $seat_num
                    );
                    $ins2->execute();
                    $ins2->close();
                    $check->close();
                    $conn->close();
                    header("Location: admin_dashboard.php?sitin=1");
                    exit;
                }
            }
            $dup->close();
        }
    }
    $check->close();
}

// ── Handle Announcement form ─────────────────────────────────────────
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['message'])){
    $admin_name = $_SESSION['admin_username'];
    $ann_date   = $_POST['announcement_date'];
    $message    = $_POST['message'];
    $stmt = $conn->prepare("INSERT INTO announcements (admin_name, announcement_date, message) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $admin_name, $ann_date, $message);
    $stmt->execute();
    $stmt->close();
    header("Location: admin_dashboard.php?posted=1");
    exit;
}

// ── Stats ─────────────────────────────────────────────────────────────
$student_count      = $conn->query("SELECT COUNT(*) as c FROM students")->fetch_assoc()['c'];
$announcement_count = $conn->query("SELECT COUNT(*) as c FROM announcements")->fetch_assoc()['c'];
$today_sitin_count  = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE sit_in_date = CURDATE()")->fetch_assoc()['c'];
$active_labs_count  = $conn->query("SELECT COUNT(*) as c FROM labs WHERE is_active=1")->fetch_assoc()['c'];

// Monthly data
$monthly_data = array_fill_keys(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'], 0);
$res = $conn->query("SELECT MONTH(created_at) as m, COUNT(*) as c FROM students GROUP BY MONTH(created_at)");
$months = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
while($row = $res->fetch_assoc()) $monthly_data[$months[$row['m']]] = $row['c'];
$max_val = max($monthly_data) ?: 1;

// ── Fetch ALL active labs dynamically for modal ──────────────────────
$active_labs = $conn->query("SELECT * FROM labs WHERE is_active=1 ORDER BY lab_name")->fetch_all(MYSQLI_ASSOC);

// Build seats JSON for all active labs (for JS seat picker)
$labs_seats_json = [];
foreach($active_labs as $lab){
    $sr = $conn->query("SELECT s.*,
        (SELECT COUNT(*) FROM sit_in si
         WHERE si.lab='{$lab['lab_name']}' AND si.seat_number=s.seat_number
           AND si.sit_in_date=CURDATE() AND si.time_out IS NULL) as is_occupied
        FROM seats s WHERE s.lab_id={$lab['id']} ORDER BY s.row_pos, s.col_pos");
    $sdata = $sr->fetch_all(MYSQLI_ASSOC);
    $labs_seats_json[$lab['lab_name']] = [
        'id'    => $lab['id'],
        'rows'  => (int)$lab['rows'],
        'cols'  => (int)$lab['cols'],
        'seats' => $sdata,
    ];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — CCS Sit-in Monitoring</title>
<link rel="stylesheet" href="admin_dashboard.css">
<link rel="icon" type="image/png" href="pictures/uclogo.png">
<style>
/* ── Seat picker inside modal ── */
.seat-picker-wrap{background:#f0f4ff;border-radius:10px;padding:14px;margin-top:10px;}
.seat-picker-title{font-size:12px;font-weight:700;color:#374357;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;}
.seat-legend-sm{display:flex;gap:12px;font-size:11px;margin-bottom:10px;flex-wrap:wrap;}
.leg-dot{width:11px;height:11px;border-radius:3px;display:inline-block;margin-right:3px;vertical-align:middle;}
.leg-green{background:#22c55e;}.leg-amber{background:#f59e0b;}.leg-red{background:#ef4444;}.leg-blue{background:#1e6fe0;}
.seat-grid-outer{overflow-x:auto;}
.teacher-bar-sm{background:linear-gradient(135deg,#0a1628,#112240);color:#fff;border-radius:7px;padding:5px 16px;font-size:10px;font-weight:700;text-align:center;margin:0 auto 10px;display:block;width:fit-content;letter-spacing:.04em;}
.seat-rows-wrap{display:flex;flex-direction:column;gap:5px;}
.seat-row{display:flex;gap:4px;align-items:center;}
.seat-btn-sm{width:40px;height:40px;flex-shrink:0;border-radius:8px;border:2px solid transparent;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:8px;font-weight:800;font-family:Outfit,sans-serif;cursor:pointer;transition:all .18s;}
.seat-btn-sm.avail{background:rgba(34,197,94,.15);border-color:rgba(34,197,94,.4);color:#15803d;}
.seat-btn-sm.avail:hover{background:rgba(34,197,94,.3);transform:scale(1.08);}
.seat-btn-sm.occupied{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.35);color:#92400e;cursor:not-allowed;}
.seat-btn-sm.maintenance{background:rgba(239,68,68,.10);border-color:rgba(239,68,68,.30);color:#b91c1c;cursor:not-allowed;}
.seat-btn-sm.selected-seat{background:#1e6fe0;border-color:#1e6fe0;color:#fff;}
.aisle-gap-sm{width:14px;flex-shrink:0;}
.seat-icon-sm{font-size:13px;margin-bottom:1px;}
.selected-seat-info{margin-top:10px;background:rgba(30,111,224,.08);border:1px solid rgba(30,111,224,.25);border-radius:8px;padding:8px 12px;font-size:13px;color:#1e6fe0;font-weight:600;display:none;}
.selected-seat-info.show{display:block;}
</style>
</head>
<body>

<nav class="dashboard-navbar">
    <div class="dashboard-left">
        <img class="admin-logo" src="pictures/uclogo.png" alt="Logo">
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
        <div class="stat-card">
            <div class="stat-icon"><img src="pictures/uclogo.png" alt=""></div>
            <div class="stat-info"><h3><?php echo $student_count; ?></h3><p>Total Students</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><img src="pictures/uclogo.png" alt=""></div>
            <div class="stat-info"><h3><?php echo $announcement_count; ?></h3><p>Announcements</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><img src="pictures/uclogo.png" alt=""></div>
            <div class="stat-info"><h3><?php echo $active_labs_count; ?></h3><p>Active Labs</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><img src="pictures/uclogo.png" alt=""></div>
            <div class="stat-info"><h3><?php echo $today_sitin_count; ?></h3><p>Today's Sit-ins</p></div>
        </div>
    </div>

    <?php if(isset($_GET['sitin'])): ?>
    <div style="padding:12px 16px;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#15803d;border-radius:10px;font-size:14px;">
        ✓ Sit-in recorded successfully.
    </div>
    <?php endif; ?>

    <!-- CHART -->
    <div class="dashboard-card chart-card">
        <div class="card-header">Student Registration Statistics</div>
        <div class="card-body">
            <div class="y-axis-label">Number of Students</div>
            <div class="bar-chart">
                <?php foreach($monthly_data as $month => $count): ?>
                <div class="bar-wrapper">
                    <span class="bar-count"><?php echo $count; ?></span>
                    <div class="bar" style="height:<?php echo ($count/$max_val*100); ?>%;"></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="chart-bottom">
                <?php foreach(array_keys($monthly_data) as $month): ?>
                <span class="month-label"><?php echo $month; ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- QUICK ACTIONS -->
    <div class="dashboard-card">
        <div class="card-header">Quick Actions</div>
        <div class="card-body">
            <div class="actions-grid">
                <button class="action-btn" id="openSitIn">
                    <span class="action-icon">👤</span>
                    <span class="action-text">+ Sit-in</span>
                </button>
                <button class="action-btn" onclick="document.querySelector('.announcement-form textarea').scrollIntoView({behavior:'smooth'});document.querySelector('.announcement-form textarea').focus();">
                    <span class="action-icon">📢</span>
                    <span class="action-text">Post Announcement</span>
                </button>
                <button class="action-btn" onclick="window.location.href='reports.php'">
                    <span class="action-icon">📋</span>
                    <span class="action-text">View Reports</span>
                </button>
                <button class="action-btn" onclick="window.location.href='manage_students.php'">
                    <span class="action-icon">⚙️</span>
                    <span class="action-text">Manage Students</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ANNOUNCEMENT FORM -->
    <div class="dashboard-card">
        <div class="card-header">Post Announcement</div>
        <div class="card-body">
            <?php if(isset($_GET['posted'])): ?>
            <div style="padding:12px 16px;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#15803d;border-radius:10px;font-size:14px;margin-bottom:16px;">
                ✓ Announcement posted successfully.
            </div>
            <?php endif; ?>
            <form class="announcement-form" method="POST">
                <div class="form-group">
                    <label>Admin Name</label>
                    <input type="text" name="admin_name" value="<?php echo htmlspecialchars($_SESSION['admin_username']); ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="announcement_date" required>
                </div>
                <div class="form-group">
                    <label>Message</label>
                    <textarea name="message" rows="4" placeholder="Write your announcement here..." required></textarea>
                </div>
                <button type="submit" class="submit-btn">Post Announcement</button>
            </form>
        </div>
    </div>

</div>
</div>

<!-- ══════════════════════════════════════════
     SIT-IN MODAL  (dynamic labs + seat picker)
══════════════════════════════════════════ -->
<div class="modal-overlay" id="sitInModal">
    <div class="modal-box" style="max-width:520px;">
        <div class="modal-header">
            <h2>New Sit-in Entry</h2>
            <span class="close-btn" id="closeSitIn">&times;</span>
        </div>
        <div class="modal-body" style="max-height:80vh;overflow-y:auto;">

            <?php if(isset($sit_in_error) && $sit_in_error): ?>
            <div style="padding:10px 14px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#b91c1c;border-radius:8px;font-size:13px;margin-bottom:14px;">
                <?php echo htmlspecialchars($sit_in_error); ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="sitInForm">
                <!-- Hidden seat fields -->
                <input type="hidden" name="seat_id"         id="hiddenSeatId"  value="0">
                <input type="hidden" name="seat_number_val" id="hiddenSeatNum" value="0">

                <div class="form-group" style="margin-bottom:12px;">
                    <label>ID Number</label>
                    <input type="text" name="id_number" id="idNumber"
                           placeholder="Enter student ID"
                           onblur="fetchStudentInfo()" required
                           style="width:100%;padding:10px 14px;border:1.5px solid #e8edf5;border-radius:10px;font-size:14px;font-family:Outfit,sans-serif;outline:none;">
                </div>
                <div class="form-group" style="margin-bottom:12px;">
                    <label>Student Name</label>
                    <input type="text" id="studentName" placeholder="Auto-filled" readonly
                           style="width:100%;padding:10px 14px;border:1.5px solid #e8edf5;border-radius:10px;font-size:14px;font-family:Outfit,sans-serif;background:#f0f4ff;color:#6b7a96;">
                </div>
                <div class="form-group" style="margin-bottom:12px;">
                    <label>Purpose</label>
                    <select name="purpose" required
                            style="width:100%;padding:10px 14px;border:1.5px solid #e8edf5;border-radius:10px;font-size:14px;font-family:Outfit,sans-serif;outline:none;background:#fafbff;">
                        <option value="">Select purpose</option>
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

                <!-- Dynamic lab list from DB -->
                <div class="form-group" style="margin-bottom:12px;">
                    <label>Laboratory</label>
                    <select name="lab" id="labSelectModal" required
                            onchange="loadModalSeats(this.value)"
                            style="width:100%;padding:10px 14px;border:1.5px solid #e8edf5;border-radius:10px;font-size:14px;font-family:Outfit,sans-serif;outline:none;background:#fafbff;">
                        <option value="">— Select Laboratory —</option>
                        <?php foreach($active_labs as $lab): ?>
                        <option value="<?php echo htmlspecialchars($lab['lab_name']); ?>">
                            Lab <?php echo htmlspecialchars($lab['lab_name']); ?>
                            <?php if($lab['description']): ?>
                            — <?php echo htmlspecialchars($lab['description']); ?>
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                        <?php if(empty($active_labs)): ?>
                        <option disabled>No active labs — add labs first</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:12px;">
                    <label>Remaining Sessions</label>
                    <input type="number" id="remainingSession" placeholder="Auto-filled" readonly
                           style="width:100%;padding:10px 14px;border:1.5px solid #e8edf5;border-radius:10px;font-size:14px;font-family:Outfit,sans-serif;background:#f0f4ff;color:#6b7a96;">
                </div>

                <!-- Seat Picker -->
                <div id="modalSeatPicker" style="display:none;">
                    <div class="seat-picker-wrap">
                        <div class="seat-picker-title">
                            🖥️ Select PC / Seat
                            <span id="modalSeatInfo" style="font-size:11px;color:#b0bdd0;font-weight:500;"></span>
                        </div>
                        <div class="seat-legend-sm">
                            <span><span class="leg-dot leg-green"></span>Available</span>
                            <span><span class="leg-dot leg-amber"></span>Occupied</span>
                            <span><span class="leg-dot leg-red"></span>Maintenance</span>
                            <span><span class="leg-dot leg-blue"></span>Selected</span>
                        </div>
                        <div class="seat-grid-outer">
                            <div class="teacher-bar-sm">🎓 Teacher's Desk</div>
                            <div class="seat-rows-wrap" id="modalSeatRows"></div>
                        </div>
                        <div class="selected-seat-info" id="selectedSeatInfo">
                            🎯 Seat <span id="selectedSeatLabel">—</span> selected
                        </div>
                    </div>
                </div>

                <div class="modal-actions" style="margin-top:16px;padding-top:14px;border-top:1px solid #e8edf5;display:flex;justify-content:flex-end;gap:10px;">
                    <button type="button" class="btn-close" id="closeSitIn2">Cancel</button>
                    <button type="submit" class="btn-submit">Confirm Sit-in</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ── Pre-load all seat data from PHP (avoids AJAX for speed) ───────────
const LABS_SEATS = <?php echo json_encode($labs_seats_json); ?>;

// ── Modal open/close ──────────────────────────────────────────────────
const modal = document.getElementById('sitInModal');
document.getElementById('openSitIn').onclick   = () => modal.classList.add('active');
document.getElementById('closeSitIn').onclick  = () => modal.classList.remove('active');
document.getElementById('closeSitIn2').onclick = () => modal.classList.remove('active');
window.onclick = e => { if(e.target === modal) modal.classList.remove('active'); };

<?php if(isset($sit_in_error) && $sit_in_error): ?>
modal.classList.add('active');
<?php endif; ?>

// ── Student ID auto-fill ──────────────────────────────────────────────
function fetchStudentInfo(){
    const id = document.getElementById('idNumber').value.trim();
    if(!id) return;
    fetch('admin_dashboard.php?id_lookup=' + encodeURIComponent(id))
        .then(r => r.text())
        .then(data => {
            const doc = new DOMParser().parseFromString(data, 'text/html');
            const el  = doc.getElementById('student-data');
            if(el && el.getAttribute('data-id')){
                document.getElementById('studentName').value      = el.getAttribute('data-name');
                document.getElementById('remainingSession').value = el.getAttribute('data-sessions');
            } else {
                alert('Student not found. Please check the ID number.');
                document.getElementById('studentName').value      = '';
                document.getElementById('remainingSession').value = '';
            }
        });
}

// ── Seat picker ────────────────────────────────────────────────────────
let activeSeatBtn = null;

function loadModalSeats(labName){
    const picker   = document.getElementById('modalSeatPicker');
    const rowsWrap = document.getElementById('modalSeatRows');
    const info     = document.getElementById('modalSeatInfo');
    const selInfo  = document.getElementById('selectedSeatInfo');

    // Reset selection
    document.getElementById('hiddenSeatId').value  = '0';
    document.getElementById('hiddenSeatNum').value = '0';
    selInfo.classList.remove('show');
    activeSeatBtn = null;

    if(!labName || !LABS_SEATS[labName]){
        picker.style.display = 'none'; return;
    }

    picker.style.display = '';
    const lab  = LABS_SEATS[labName];
    const cols = parseInt(lab.cols);
    const half = Math.ceil(cols / 2);

    rowsWrap.innerHTML = '';

    // Build row-keyed map
    const rowMap = {};
    lab.seats.forEach(s => {
        const r = parseInt(s.row_pos);
        if(!rowMap[r]) rowMap[r] = {};
        rowMap[r][parseInt(s.col_pos)] = s;
    });

    let avail = 0, occ = 0, maint = 0;

    Object.keys(rowMap).map(Number).sort((a,b)=>a-b).forEach(rnum => {
        const rowDiv = document.createElement('div');
        rowDiv.className = 'seat-row';

        const colMap = rowMap[rnum];
        for(let c = 1; c <= cols; c++){
            // Aisle spacer
            if(c === half + 1){
                const aisle = document.createElement('div');
                aisle.className = 'aisle-gap-sm';
                rowDiv.appendChild(aisle);
            }

            const seat = colMap[c];
            if(!seat){
                // Placeholder to keep alignment
                const ph = document.createElement('div');
                ph.style.cssText = 'width:40px;height:40px;flex-shrink:0;';
                rowDiv.appendChild(ph);
                continue;
            }

            const isInactive  = parseInt(seat.is_active)  === 0;
            const isOccupied  = parseInt(seat.is_occupied) >  0;

            const btn = document.createElement('button');
            btn.type  = 'button';

            let cls, icon, title;
            if(isInactive){
                cls   = 'seat-btn-sm maintenance';
                icon  = '🔧';
                title = `Seat ${seat.seat_number} — Under Maintenance`;
                btn.disabled = true;
                maint++;
            } else if(isOccupied){
                cls   = 'seat-btn-sm occupied';
                icon  = '👤';
                title = `Seat ${seat.seat_number} — Currently Occupied`;
                btn.disabled = true;
                occ++;
            } else {
                cls   = 'seat-btn-sm avail';
                icon  = '🖥️';
                title = `Seat ${seat.seat_number} — Available`;
                btn.onclick = () => selectModalSeat(btn, seat.id, seat.seat_number);
                avail++;
            }

            btn.className = cls;
            btn.title     = title;
            btn.innerHTML = `<span class="seat-icon-sm">${icon}</span>${seat.seat_number}`;
            rowDiv.appendChild(btn);
        }
        rowsWrap.appendChild(rowDiv);
    });

    let parts = [`${avail} available`];
    if(occ)   parts.push(`${occ} occupied`);
    if(maint) parts.push(`${maint} maintenance`);
    info.textContent = parts.join(' · ');
}

function selectModalSeat(btn, seatId, seatNum){
    // Deselect previous
    if(activeSeatBtn){
        activeSeatBtn.className = activeSeatBtn.className.replace('selected-seat','avail');
    }
    // Select new
    btn.className = btn.className.replace('avail','selected-seat');
    activeSeatBtn = btn;

    document.getElementById('hiddenSeatId').value  = seatId;
    document.getElementById('hiddenSeatNum').value = seatNum;
    document.getElementById('selectedSeatLabel').textContent = seatNum;
    document.getElementById('selectedSeatInfo').classList.add('show');
}
</script>

</body>
</html>