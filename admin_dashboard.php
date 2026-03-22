<?php
session_start();

if(!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true){
    header("Location: login.php");
    exit;
}

include 'Database/connect.php';

// Handle student ID lookup
if(isset($_GET['id_lookup'])) {
    $lookup_id = $_GET['id_lookup'];
    $stmt = $conn->prepare("SELECT id, first_name, last_name, sessions FROM students WHERE id_number = ?");
    $stmt->bind_param("s", $lookup_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if($row = $result->fetch_assoc()) {
        $student_name = $row['first_name'] . ' ' . $row['last_name'];
        echo '<div id="student-data" data-id="' . $row['id'] . '" data-name="' . htmlspecialchars($student_name) . '" data-sessions="' . $row['sessions'] . '"></div>';
    } else {
        echo '<div id="student-data"></div>';
    }
    $stmt->close();
    $conn->close();
    exit;
}

// Auto-create sit_in table
$conn->query("CREATE TABLE IF NOT EXISTS sit_in (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_number VARCHAR(50) NOT NULL,
    student_name VARCHAR(200) NOT NULL,
    purpose VARCHAR(100) NOT NULL,
    lab VARCHAR(50) NOT NULL,
    remaining_session INT NOT NULL,
    sit_in_date DATE NOT NULL,
    sit_in_time TIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Handle Sit-in form
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['id_number'])) {
    $id_number = $_POST['id_number'];
    $purpose   = $_POST['purpose'];
    $lab       = $_POST['lab'];

    $check = $conn->prepare("SELECT id, first_name, last_name, sessions FROM students WHERE id_number = ?");
    $check->bind_param("s", $id_number);
    $check->execute();
    $check_result = $check->get_result();

    if($check_result->num_rows == 0) {
        $sit_in_error = "Student ID not found in the system.";
    } else {
        $student_row = $check_result->fetch_assoc();
        if($student_row['sessions'] <= 0) {
            $sit_in_error = "Student has no remaining sessions.";
        } else {
            $new_sessions = $student_row['sessions'] - 1;
            $db_name      = $student_row['first_name'] . ' ' . $student_row['last_name'];

            // Update sessions
            $upd = $conn->prepare("UPDATE students SET sessions = ? WHERE id_number = ?");
            $upd->bind_param("is", $new_sessions, $id_number);
            $upd->execute();
            $upd->close();

            // Insert sit-in log
            $ins = $conn->prepare("INSERT INTO sit_in (id_number, student_name, purpose, lab, remaining_session, sit_in_date, sit_in_time) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $today = date('Y-m-d');
            $now   = date('H:i:s');
            $ins->bind_param("ssssiss", $id_number, $db_name, $purpose, $lab, $new_sessions, $today, $now);
            $ins->execute();
            $ins->close();

            $check->close();
            $conn->close();
            header("Location: admin_dashboard.php?sitin=1");
            exit;
        }
    }
    $check->close();
}

// Handle Announcement form
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['message'])) {
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

// Stats
$student_count      = $conn->query("SELECT COUNT(*) as c FROM students")->fetch_assoc()['c'];
$announcement_count = $conn->query("SELECT COUNT(*) as c FROM announcements")->fetch_assoc()['c'];
$today_sitin_count  = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE sit_in_date = CURDATE()")->fetch_assoc()['c'];

// Monthly data
$monthly_data = array_fill_keys(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'], 0);
$res = $conn->query("SELECT MONTH(created_at) as m, COUNT(*) as c FROM students GROUP BY MONTH(created_at)");
$months = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
while($row = $res->fetch_assoc()) $monthly_data[$months[$row['m']]] = $row['c'];

$max_val = max($monthly_data) ?: 1;
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
        <li><a href="reservations.php">Reservations</a></li>
        <li><a href="reports.php">Reports</a></li>
        <li><a href="#">Settings</a></li>
        <li><a href="logout.php" class="logout-btn">Log Out</a></li>
    </ul>
</nav>

<div class="dashboard-container">
<div class="dashboard-main">

    <!-- STATS -->
    <div class="stats-container">
        <div class="stat-card">
            <div class="stat-icon"><img src="pictures/uclogo.png" alt=""></div>
            <div class="stat-info">
                <h3><?php echo $student_count; ?></h3>
                <p>Total Students</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><img src="pictures/uclogo.png" alt=""></div>
            <div class="stat-info">
                <h3><?php echo $announcement_count; ?></h3>
                <p>Announcements</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><img src="pictures/uclogo.png" alt=""></div>
            <div class="stat-info">
                <h3>5</h3>
                <p>Computer Labs</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><img src="pictures/uclogo.png" alt=""></div>
            <div class="stat-info">
                <h3><?php echo $today_sitin_count; ?></h3>
                <p>Today's Sit-ins</p>
            </div>
        </div>
    </div>

    <!-- SUCCESS ALERT -->
    <?php if(isset($_GET['sitin'])): ?>
    <div style="padding:12px 16px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);color:#15803d;border-radius:10px;font-size:14px;">
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
                    <div class="bar" style="height: <?php echo ($count / $max_val * 100); ?>%;"></div>
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
                <button class="action-btn">
                    <span class="action-icon">📢</span>
                    <span class="action-text">Post Announcement</span>
                </button>
                <button class="action-btn">
                    <span class="action-icon">📋</span>
                    <span class="action-text">View Reports</span>
                </button>
                <button class="action-btn">
                    <span class="action-icon">⚙️</span>
                    <span class="action-text">Manage Labs</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ANNOUNCEMENTS FORM -->
    <div class="dashboard-card">
        <div class="card-header">Post Announcement</div>
        <div class="card-body">
            <?php if(isset($_GET['posted'])): ?>
            <div style="padding:12px 16px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);color:#15803d;border-radius:10px;font-size:14px;margin-bottom:16px;">
                ✓ Announcement posted successfully.
            </div>
            <?php endif; ?>
            <form class="announcement-form" method="POST" action="">
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

<!-- SIT-IN MODAL -->
<div class="modal-overlay" id="sitInModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>New Sit-in Entry</h2>
            <span class="close-btn" id="closeSitIn">&times;</span>
        </div>
        <div class="modal-body">
            <?php if(isset($sit_in_error)): ?>
            <div style="padding:10px 14px;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);color:#b91c1c;border-radius:8px;font-size:13px;margin-bottom:14px;">
                <?php echo htmlspecialchars($sit_in_error); ?>
            </div>
            <?php endif; ?>
            <form method="POST" action="">
                <div class="form-group">
                    <label>ID Number</label>
                    <input type="text" name="id_number" id="idNumber" placeholder="Enter student ID" onblur="fetchStudentInfo()" required>
                </div>
                <div class="form-group">
                    <label>Student Name</label>
                    <input type="text" name="student_name" id="studentName" placeholder="Auto-filled" readonly>
                </div>
                <div class="form-group">
                    <label>Purpose</label>
                    <select name="purpose" required>
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
                <div class="form-group">
                    <label>Laboratory</label>
                    <select name="lab" required>
                        <option value="">Select lab</option>
                        <option value="524">Lab 524</option>
                        <option value="525">Lab 525</option>
                        <option value="526">Lab 526</option>
                        <option value="527">Lab 527</option>
                        <option value="528">Lab 528</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Remaining Sessions</label>
                    <input type="number" name="remaining_session" id="remainingSession" placeholder="Auto-filled" readonly>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-close" id="closeSitIn2">Cancel</button>
                    <button type="submit" class="btn-submit">Confirm Sit-in</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const modal = document.getElementById('sitInModal');
document.getElementById('openSitIn').onclick = () => modal.classList.add('active');
document.getElementById('closeSitIn').onclick  = () => modal.classList.remove('active');
document.getElementById('closeSitIn2').onclick = () => modal.classList.remove('active');
window.onclick = e => { if(e.target === modal) modal.classList.remove('active'); };

<?php if(isset($sit_in_error)): ?>
modal.classList.add('active');
<?php endif; ?>

function fetchStudentInfo() {
    const id = document.getElementById('idNumber').value.trim();
    if(!id) return;
    fetch('admin_dashboard.php?id_lookup=' + encodeURIComponent(id))
        .then(r => r.text())
        .then(data => {
            const doc = new DOMParser().parseFromString(data, 'text/html');
            const el  = doc.getElementById('student-data');
            if(el && el.getAttribute('data-id')) {
                document.getElementById('studentName').value      = el.getAttribute('data-name');
                document.getElementById('remainingSession').value = el.getAttribute('data-sessions');
            } else {
                alert('Student not found. Please check the ID number.');
                document.getElementById('studentName').value      = '';
                document.getElementById('remainingSession').value = '';
            }
        });
}
</script>

</body>
</html>