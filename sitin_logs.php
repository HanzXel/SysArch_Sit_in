<?php
session_start();
if(!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true){
    header("Location: login.php"); exit;
}
include 'Database/connect.php';

// Auto-add time_out columns if missing
$conn->query("ALTER TABLE sit_in ADD COLUMN IF NOT EXISTS time_out TIME DEFAULT NULL");
$conn->query("ALTER TABLE sit_in ADD COLUMN IF NOT EXISTS time_out_date DATE DEFAULT NULL");

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

// Auto-create admin feedback table (with lab column)
$conn->query("CREATE TABLE IF NOT EXISTS admin_sitin_feedback (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    sit_in_id    INT NOT NULL,
    student_id   INT NOT NULL,
    id_number    VARCHAR(50) NOT NULL,
    student_name VARCHAR(200) NOT NULL,
    admin_name   VARCHAR(100) NOT NULL,
    lab          VARCHAR(50) NOT NULL DEFAULT '',
    rating       TINYINT(1) NOT NULL,
    feedback_text TEXT DEFAULT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admin_sitin_fb (sit_in_id)
)");
// Patch existing table if lab column was missing (safe to run every time)
$conn->query("ALTER TABLE admin_sitin_feedback ADD COLUMN IF NOT EXISTS lab VARCHAR(50) NOT NULL DEFAULT ''");
$conn->query("UPDATE admin_sitin_feedback af JOIN sit_in s ON s.id = af.sit_in_id SET af.lab = s.lab WHERE af.lab = '' OR af.lab IS NULL");

// ── Handle Time Out ──────────────────────────────────────────────────
if(isset($_GET['timeout'])){
    $timeout_id = intval($_GET['timeout']);
    $now_time   = date('H:i:s');
    $now_date   = date('Y-m-d');

    $fetch = $conn->prepare("SELECT id_number FROM sit_in WHERE id = ? AND time_out IS NULL");
    $fetch->bind_param("i", $timeout_id);
    $fetch->execute();
    $row = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    if($row){
        $upd = $conn->prepare("UPDATE students SET sessions = GREATEST(sessions - 1, 0) WHERE id_number = ?");
        $upd->bind_param("s", $row['id_number']);
        $upd->execute();
        $upd->close();

        $ses = $conn->prepare("SELECT sessions FROM students WHERE id_number = ?");
        $ses->bind_param("s", $row['id_number']);
        $ses->execute();
        $new_sessions = $ses->get_result()->fetch_assoc()['sessions'];
        $ses->close();

        $s = $conn->prepare("UPDATE sit_in SET time_out = ?, time_out_date = ?, remaining_session = ? WHERE id = ? AND time_out IS NULL");
        $s->bind_param("ssii", $now_time, $now_date, $new_sessions, $timeout_id);
        $s->execute();
        $s->close();
    }
    header("Location: sitin_logs.php?timed_out=1"); exit;
}

// ── Handle Admin Feedback submission ────────────────────────────────
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_admin_feedback'])){
    $sit_in_id    = intval($_POST['sit_in_id']);
    $rating       = intval($_POST['admin_rating']);
    $feedback_txt = trim($_POST['admin_feedback_text']);
    $admin_name   = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Admin';

    // Fetch sit-in details
    $r = $conn->prepare("SELECT s.*, st.id as student_db_id FROM sit_in s JOIN students st ON st.id_number = s.id_number WHERE s.id = ?");
    $r->bind_param("i", $sit_in_id);
    $r->execute();
    $sitin_row = $r->get_result()->fetch_assoc();
    $r->close();

    if($sitin_row && $rating >= 1 && $rating <= 5){
        $student_db_id = $sitin_row['student_db_id'];
        $id_number     = $sitin_row['id_number'];
        $student_name  = $sitin_row['student_name'];
        $lab           = $sitin_row['lab'];

        // Insert admin feedback (ignore duplicate)
        $ins = $conn->prepare(
            "INSERT IGNORE INTO admin_sitin_feedback
                (sit_in_id, student_id, id_number, student_name, admin_name, lab, rating, feedback_text)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        // types: i i s s s s i s  → "iissssis"
        $ins->bind_param("iissssis",
            $sit_in_id,
            $student_db_id,
            $id_number,
            $student_name,
            $admin_name,
            $lab,
            $rating,
            $feedback_txt
        );
        $ins->execute();
        $ins->close();

        // Star label for notification
        $star_labels = [1=>'Very Poor',2=>'Poor',3=>'Satisfactory',4=>'Good',5=>'Excellent'];
        $star_str    = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
        $label       = $star_labels[$rating] ?? '';

        // Build notification message
        $date_str = date('M d, Y', strtotime($sitin_row['sit_in_date']));
        $notif_msg = "Your session in Lab {$lab} on {$date_str} has been reviewed by {$admin_name}. "
                   . "Rating: {$star_str} ({$label}).";
        if(!empty($feedback_txt)){
            $notif_msg .= " Feedback: \"{$feedback_txt}\"";
        }

        $notif = $conn->prepare(
            "INSERT INTO notifications (student_id, title, message, type) VALUES (?, ?, ?, 'info')"
        );
        $notif_title = "Admin Session Feedback";
        $notif->bind_param("iss", $student_db_id, $notif_title, $notif_msg);
        $notif->execute();
        $notif->close();
    }
    header("Location: sitin_logs.php?fb_sent=1"); exit;
}

// ── Filters ──────────────────────────────────────────────────────────
$search    = trim($_GET['search'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');
$lab_f     = trim($_GET['lab'] ?? '');
$purpose_f = trim($_GET['purpose'] ?? '');
$status_f  = trim($_GET['status'] ?? '');

$where  = "WHERE 1=1";
$params = [];
$types  = '';

if($search !== ''){
    $like = '%' . $search . '%';
    $where .= " AND (student_name LIKE ? OR id_number LIKE ?)";
    $params = array_merge($params, [$like, $like]);
    $types .= 'ss';
}
if($date_from !== ''){ $where .= " AND sit_in_date >= ?"; $params[] = $date_from; $types .= 's'; }
if($date_to   !== ''){ $where .= " AND sit_in_date <= ?"; $params[] = $date_to;   $types .= 's'; }
if($lab_f     !== ''){ $where .= " AND lab = ?";          $params[] = $lab_f;     $types .= 's'; }
if($purpose_f !== ''){ $where .= " AND purpose = ?";      $params[] = $purpose_f; $types .= 's'; }
if($status_f === 'active'){ $where .= " AND time_out IS NULL"; }
if($status_f === 'done')  { $where .= " AND time_out IS NOT NULL"; }

// Pagination
$per_page = 20;
$page     = max(1, intval($_GET['p'] ?? 1));
$offset   = ($page - 1) * $per_page;

if(!empty($params)){
    $cs = $conn->prepare("SELECT COUNT(*) as c FROM sit_in $where");
    $cs->bind_param($types, ...$params);
    $cs->execute();
    $total = $cs->get_result()->fetch_assoc()['c'];
    $cs->close();
} else {
    $total = $conn->query("SELECT COUNT(*) as c FROM sit_in")->fetch_assoc()['c'];
}
$total_pages = max(1, ceil($total / $per_page));

$all_params = array_merge($params, [$per_page, $offset]);
$all_types  = $types . 'ii';
$s = $conn->prepare("SELECT * FROM sit_in $where ORDER BY sit_in_date DESC, sit_in_time DESC LIMIT ? OFFSET ?");
if(!empty($all_params)){ $s->bind_param($all_types, ...$all_params); }
$s->execute();
$logs = $s->get_result()->fetch_all(MYSQLI_ASSOC);
$s->close();

// Collect sit_in IDs that already have admin feedback
$log_ids = array_column($logs, 'id');
$admin_fb_ids = [];
if(!empty($log_ids)){
    $ph  = implode(',', array_fill(0, count($log_ids), '?'));
    $tph = str_repeat('i', count($log_ids));
    $af  = $conn->prepare("SELECT sit_in_id FROM admin_sitin_feedback WHERE sit_in_id IN ($ph)");
    $af->bind_param($tph, ...$log_ids);
    $af->execute();
    $admin_fb_ids = array_column($af->get_result()->fetch_all(MYSQLI_ASSOC), 'sit_in_id');
    $af->close();
}

// Stats
$today_count  = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE sit_in_date = CURDATE()")->fetch_assoc()['c'];
$week_count   = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE sit_in_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
$month_count  = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE MONTH(sit_in_date)=MONTH(CURDATE()) AND YEAR(sit_in_date)=YEAR(CURDATE())")->fetch_assoc()['c'];
$total_all    = $conn->query("SELECT COUNT(*) as c FROM sit_in")->fetch_assoc()['c'];
$active_count = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE time_out IS NULL AND sit_in_date = CURDATE()")->fetch_assoc()['c'];

$labs     = $conn->query("SELECT DISTINCT lab FROM sit_in ORDER BY lab")->fetch_all(MYSQLI_ASSOC);
$purposes = $conn->query("SELECT DISTINCT purpose FROM sit_in ORDER BY purpose")->fetch_all(MYSQLI_ASSOC);

$conn->close();

function computeDuration($date_in, $time_in, $date_out, $time_out){
    if(!$time_out) return null;
    $diff = max(0, strtotime($date_out.' '.$time_out) - strtotime($date_in.' '.$time_in));
    $h = floor($diff/3600); $m = floor(($diff%3600)/60);
    return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sit-in Logs — Admin</title>
<link rel="stylesheet" href="admin_shared.css">
<link rel="icon" type="image/png" href="pictures/uclogo.png">
<style>
/* ── Active pulse ── */
.badge-active{background:rgba(34,197,94,.12);color:#15803d;border:1px solid rgba(34,197,94,.35);display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:100px;font-size:12px;font-weight:600;}
.pulse-dot{width:7px;height:7px;background:#22c55e;border-radius:50%;display:inline-block;animation:pulse 1.6s ease-in-out infinite;}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.7)}}
.badge-done{background:var(--gray-100);color:var(--gray-500);border:1px solid var(--gray-300);padding:4px 10px;border-radius:100px;font-size:12px;font-weight:600;}
.duration-pill{display:inline-flex;align-items:center;gap:4px;background:rgba(30,111,224,.08);color:var(--blue);border:1px solid rgba(30,111,224,.18);padding:3px 9px;border-radius:100px;font-size:12px;font-weight:600;}

/* ── Time-out btn ── */
.btn-timeout{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;background:rgba(239,68,68,.08);color:#b91c1c;border:1px solid rgba(239,68,68,.25);border-radius:var(--radius-sm);font-size:12px;font-weight:600;font-family:'Outfit',sans-serif;cursor:pointer;transition:all var(--transition);text-decoration:none;white-space:nowrap;}
.btn-timeout:hover{background:rgba(239,68,68,.18);transform:translateY(-1px);}

/* ── Admin feedback btn ── */
.btn-admin-fb{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;background:rgba(139,92,246,.1);color:#6d28d9;border:1px solid rgba(139,92,246,.25);border-radius:var(--radius-sm);font-size:12px;font-weight:600;font-family:'Outfit',sans-serif;cursor:pointer;transition:all var(--transition);white-space:nowrap;}
.btn-admin-fb:hover{background:rgba(139,92,246,.2);transform:translateY(-1px);}
.btn-admin-fb-done{display:inline-flex;align-items:center;gap:4px;padding:6px 12px;background:var(--gray-100);color:var(--gray-500);border:1px solid var(--gray-300);border-radius:var(--radius-sm);font-size:12px;font-weight:600;white-space:nowrap;cursor:default;}

/* ── Active row ── */
tbody tr.row-active{background:rgba(34,197,94,.04);}
tbody tr.row-active:hover{background:rgba(34,197,94,.08);}

.time-block{display:flex;flex-direction:column;gap:2px;}
.time-val{font-weight:600;color:var(--navy);font-size:13px;}
.time-date{font-size:11px;color:var(--gray-300);}

/* ── Status filter tabs ── */
.status-tabs{display:flex;gap:6px;margin-bottom:18px;flex-wrap:wrap;}
.status-tab{padding:7px 16px;border-radius:100px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;border:1.5px solid var(--gray-100);background:var(--white);color:var(--gray-500);transition:all var(--transition);}
.status-tab:hover{border-color:var(--blue);color:var(--blue);}
.status-tab.active{background:var(--blue);border-color:var(--blue);color:var(--white);}
.status-tab.tab-green.active{background:#16a34a;border-color:#16a34a;}
.status-tab.tab-green:hover{border-color:#16a34a;color:#16a34a;}
.mini-stat:nth-child(5){border-left-color:#22c55e;}

/* ── Star rating widget inside modal ── */
.modal-star-row{display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:6px;margin-bottom:8px;}
.modal-star-row input[type=radio]{display:none;}
.modal-star-row label{font-size:34px;color:var(--gray-100);cursor:pointer;transition:color .12s,transform .12s;line-height:1;user-select:none;}
.modal-star-row label:hover,
.modal-star-row label:hover ~ label,
.modal-star-row input:checked ~ label{color:#f59e0b;}
.modal-star-row label:hover{transform:scale(1.15);}
.modal-star-hint{font-size:12px;color:var(--gray-300);min-height:16px;margin-bottom:14px;}

@media print{.btn-timeout,.btn-admin-fb,.btn-admin-fb-done,.filter-bar,.dashboard-navbar,.status-tabs,.pagination,.no-print{display:none !important;}body{background:white;}.card{box-shadow:none;border:1px solid #ddd;}}
</style>
</head>
<body>

<nav class="dashboard-navbar">
    <div class="dashboard-left">
        <img class="admin-logo" src="pictures/uclogo.png" alt="Logo">
        <span class="admin-title">Admin Dashboard</span>
    </div>
    <ul class="dashboard-right">
        <li><a href="admin_dashboard.php">Dashboard</a></li>
        <li><a href="manage_students.php">Manage Students</a></li>
        <li><a href="sitin_logs.php" class="active">Sit-in Logs</a></li>
        <li><a href="reservations.php">Reservations</a></li>
        <li><a href="reports.php">Reports</a></li>
        <li><a href="student_feedback.php">Feedback</a></li>
        <li><a href="logout.php" class="logout-btn">Log Out</a></li>
    </ul>
</nav>

<div class="page-container">

    <div class="page-header">
        <div>
            <div class="page-title">Sit-in Logs</div>
            <div class="page-subtitle">Monitor active sessions and complete history of laboratory sit-ins</div>
        </div>
        <button class="btn btn-primary no-print" onclick="window.print()">🖨 Print Logs</button>
    </div>

    <?php if(isset($_GET['timed_out'])): ?>
    <div class="alert alert-success">✓ Student has been timed out successfully.</div>
    <?php endif; ?>
    <?php if(isset($_GET['fb_sent'])): ?>
    <div class="alert alert-success">✓ Feedback sent — student has been notified.</div>
    <?php endif; ?>

    <!-- Mini Stats -->
    <div class="mini-stats" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));">
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $today_count; ?></div><div class="mini-stat-label">Today</div></div>
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $week_count; ?></div><div class="mini-stat-label">This Week</div></div>
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $month_count; ?></div><div class="mini-stat-label">This Month</div></div>
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $total_all; ?></div><div class="mini-stat-label">All Time</div></div>
        <div class="mini-stat"><div class="mini-stat-value" style="color:#16a34a"><?php echo $active_count; ?></div><div class="mini-stat-label">Currently Inside</div></div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-header-left">Session Records</div>
            <span style="font-size:13px;opacity:.7"><?php echo $total; ?> record<?php echo $total!=1?'s':''; ?></span>
        </div>
        <div class="card-body">

            <!-- Status Tabs -->
            <div class="status-tabs">
                <?php $bqs = http_build_query(array_filter(['search'=>$search,'date_from'=>$date_from,'date_to'=>$date_to,'lab'=>$lab_f,'purpose'=>$purpose_f])); ?>
                <a href="sitin_logs.php?<?php echo $bqs; ?>" class="status-tab <?php echo $status_f==='' ? 'active':''; ?>">All</a>
                <a href="sitin_logs.php?<?php echo $bqs; ?>&status=active" class="status-tab tab-green <?php echo $status_f==='active'?'active':''; ?>">🟢 Currently Inside</a>
                <a href="sitin_logs.php?<?php echo $bqs; ?>&status=done" class="status-tab <?php echo $status_f==='done'?'active':''; ?>">✓ Timed Out</a>
            </div>

            <!-- Filters -->
            <form method="GET" action="">
                <?php if($status_f!==''): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status_f); ?>"><?php endif; ?>
                <div class="filter-bar">
                    <input type="text" name="search" class="search-input" placeholder="Search by name or ID…" value="<?php echo htmlspecialchars($search); ?>">
                    <input type="date" name="date_from" class="filter-select" value="<?php echo htmlspecialchars($date_from); ?>">
                    <input type="date" name="date_to"   class="filter-select" value="<?php echo htmlspecialchars($date_to); ?>">
                    <select name="lab" class="filter-select">
                        <option value="">All Labs</option>
                        <?php foreach($labs as $l): ?>
                        <option value="<?php echo htmlspecialchars($l['lab']); ?>" <?php echo $lab_f===$l['lab']?'selected':''; ?>>Lab <?php echo htmlspecialchars($l['lab']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="purpose" class="filter-select">
                        <option value="">All Purposes</option>
                        <?php foreach($purposes as $p): ?>
                        <option value="<?php echo htmlspecialchars($p['purpose']); ?>" <?php echo $purpose_f===$p['purpose']?'selected':''; ?>><?php echo htmlspecialchars($p['purpose']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="sitin_logs.php" class="btn btn-gray">Reset</a>
                </div>
            </form>

            <?php if(empty($logs)): ?>
            <div class="empty-state">
                <div class="empty-icon">📋</div>
                <div class="empty-title">No sit-in records found</div>
                <div class="empty-desc">Try adjusting the filters above.</div>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>ID Number</th>
                            <th>Student Name</th>
                            <th>Purpose</th>
                            <th>Lab</th>
                            <th>Sessions Left</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <th class="no-print">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($logs as $i => $log):
                        $is_active  = empty($log['time_out']);
                        $duration   = computeDuration($log['sit_in_date'], $log['sit_in_time'], $log['time_out_date'] ?? $log['sit_in_date'], $log['time_out'] ?? null);
                        $has_admin_fb = in_array($log['id'], $admin_fb_ids);
                    ?>
                    <tr class="<?php echo $is_active ? 'row-active' : ''; ?>">
                        <td><?php echo $offset + $i + 1; ?></td>
                        <td class="td-bold"><?php echo htmlspecialchars($log['id_number']); ?></td>
                        <td class="td-bold"><?php echo htmlspecialchars($log['student_name']); ?></td>
                        <td><span class="badge badge-blue"><?php echo htmlspecialchars($log['purpose']); ?></span></td>
                        <td>Lab <?php echo htmlspecialchars($log['lab']); ?></td>
                        <td>
                            <?php $r = $log['remaining_session']; ?>
                            <span class="badge <?php echo $r>10?'badge-success':($r>0?'badge-warning':'badge-danger'); ?>"><?php echo $r; ?></span>
                        </td>
                        <td>
                            <div class="time-block">
                                <span class="time-val"><?php echo date('h:i A', strtotime($log['sit_in_time'])); ?></span>
                                <span class="time-date"><?php echo date('M d, Y', strtotime($log['sit_in_date'])); ?></span>
                            </div>
                        </td>
                        <td>
                            <?php if(!$is_active): ?>
                            <div class="time-block">
                                <span class="time-val"><?php echo date('h:i A', strtotime($log['time_out'])); ?></span>
                                <span class="time-date"><?php echo date('M d, Y', strtotime($log['time_out_date'])); ?></span>
                            </div>
                            <?php else: ?>
                            <span style="color:var(--gray-300);font-size:13px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($duration): ?>
                            <span class="duration-pill">⏱ <?php echo $duration; ?></span>
                            <?php else: ?>
                            <span id="live-<?php echo $log['id']; ?>" class="duration-pill" data-in="<?php echo strtotime($log['sit_in_date'].' '.$log['sit_in_time']); ?>">⏱ …</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($is_active): ?>
                            <span class="badge-active"><span class="pulse-dot"></span> Active</span>
                            <?php else: ?>
                            <span class="badge-done">✓ Done</span>
                            <?php endif; ?>
                        </td>
                        <td class="no-print">
                            <div style="display:flex;flex-direction:column;gap:5px;">
                                <?php if($is_active): ?>
                                <!-- Time Out button -->
                                <a href="sitin_logs.php?timeout=<?php echo $log['id'];
                                    echo '&search='.urlencode($search).'&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to).'&lab='.urlencode($lab_f).'&purpose='.urlencode($purpose_f).'&status='.urlencode($status_f).'&p='.$page; ?>"
                                   class="btn-timeout"
                                   onclick="return confirm('Time out <?php echo htmlspecialchars(addslashes($log['student_name'])); ?>?')">
                                    🔴 Time Out
                                </a>
                                <?php else: ?>
                                <!-- Admin Feedback button (only for timed-out sessions) -->
                                <?php if($has_admin_fb): ?>
                                <span class="btn-admin-fb-done">✓ Feedback Sent</span>
                                <?php else: ?>
                                <button class="btn-admin-fb"
                                    onclick="openAdminFb(
                                        <?php echo $log['id']; ?>,
                                        '<?php echo htmlspecialchars(addslashes($log['student_name'])); ?>',
                                        '<?php echo htmlspecialchars(addslashes($log['lab'])); ?>',
                                        '<?php echo date('M d, Y', strtotime($log['sit_in_date'])); ?>'
                                    )">
                                    📝 Give Feedback
                                </button>
                                <?php endif; ?>
                                <span style="color:var(--gray-300);font-size:12px;">Completed</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if($total_pages > 1): ?>
            <div class="pagination">
                <?php for($i=1;$i<=$total_pages;$i++): ?>
                <a href="?p=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&lab=<?php echo urlencode($lab_f); ?>&purpose=<?php echo urlencode($purpose_f); ?>&status=<?php echo urlencode($status_f); ?>"
                   class="page-btn <?php echo $page==$i?'active':''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- ── Admin Feedback Modal ── -->
<div class="modal-overlay" id="adminFbModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Give Session Feedback</h2>
            <button class="close-btn" onclick="closeAdminFb()">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="">
                <input type="hidden" name="submit_admin_feedback" value="1">
                <input type="hidden" name="sit_in_id" id="fb_sit_in_id">

                <p style="font-size:14px;color:var(--gray-500);margin-bottom:6px;">
                    Student: <strong id="fb_student_name" style="color:var(--navy)"></strong>
                </p>
                <p style="font-size:13px;color:var(--gray-500);margin-bottom:18px;">
                    Lab <span id="fb_lab" style="font-weight:600;color:var(--navy)"></span> &nbsp;·&nbsp;
                    <span id="fb_date"></span>
                </p>

                <!-- Star Rating -->
                <div class="form-group">
                    <label class="form-label">Rating <span style="color:#ef4444">*</span></label>
                    <div class="modal-star-row">
                        <input type="radio" name="admin_rating" id="as5" value="5" required>
                        <label for="as5" title="Excellent">★</label>
                        <input type="radio" name="admin_rating" id="as4" value="4">
                        <label for="as4" title="Good">★</label>
                        <input type="radio" name="admin_rating" id="as3" value="3">
                        <label for="as3" title="Satisfactory">★</label>
                        <input type="radio" name="admin_rating" id="as2" value="2">
                        <label for="as2" title="Poor">★</label>
                        <input type="radio" name="admin_rating" id="as1" value="1">
                        <label for="as1" title="Very Poor">★</label>
                    </div>
                    <div class="modal-star-hint" id="adminStarHint">Click a star to rate</div>
                </div>

                <!-- Comment -->
                <div class="form-group">
                    <label class="form-label">
                        Feedback / Comment
                        <span style="font-weight:400;color:var(--gray-300)">(optional — sent to student)</span>
                    </label>
                    <textarea name="admin_feedback_text"
                              class="form-input form-textarea"
                              rows="3"
                              maxlength="500"
                              placeholder="e.g. Great focus today! Remember to log out properly next time."></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeAdminFb()">Cancel</button>
                    <button type="submit" class="btn btn-primary">📨 Send Feedback</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ── Live timers ──
function updateLiveTimers(){
    const now = Math.floor(Date.now()/1000);
    document.querySelectorAll('[id^="live-"]').forEach(el=>{
        const diff = Math.max(0, now - parseInt(el.dataset.in,10));
        const h=Math.floor(diff/3600), m=Math.floor((diff%3600)/60), s=diff%60;
        el.textContent = h>0 ? `⏱ ${h}h ${m}m` : `⏱ ${m}m ${s}s`;
    });
}
updateLiveTimers();
setInterval(updateLiveTimers, 1000);

// ── Admin Feedback Modal ──
const fbModal = document.getElementById('adminFbModal');

function openAdminFb(id, name, lab, date){
    document.getElementById('fb_sit_in_id').value    = id;
    document.getElementById('fb_student_name').textContent = name;
    document.getElementById('fb_lab').textContent    = lab;
    document.getElementById('fb_date').textContent   = date;
    // Reset stars & textarea
    document.querySelectorAll('.modal-star-row input').forEach(r=>r.checked=false);
    document.querySelector('[name="admin_feedback_text"]').value = '';
    document.getElementById('adminStarHint').textContent = 'Click a star to rate';
    fbModal.classList.add('active');
}
function closeAdminFb(){ fbModal.classList.remove('active'); }
window.addEventListener('click', e=>{ if(e.target===fbModal) closeAdminFb(); });

// Star hints inside modal
const adminHints = {1:'Very Poor',2:'Poor',3:'Satisfactory',4:'Good',5:'Excellent'};
const adminHintEl = document.getElementById('adminStarHint');
document.querySelectorAll('.modal-star-row label').forEach(lbl=>{
    function val(l){ const i=l.previousElementSibling; return i?parseInt(i.value):0; }
    lbl.addEventListener('mouseenter',()=>{ if(adminHintEl) adminHintEl.textContent=adminHints[val(lbl)]||''; });
    lbl.addEventListener('mouseleave',()=>{
        const checked=document.querySelector('.modal-star-row input:checked');
        if(adminHintEl) adminHintEl.textContent=checked?(adminHints[checked.value]||''):'Click a star to rate';
    });
});
</script>

</body>
</html>