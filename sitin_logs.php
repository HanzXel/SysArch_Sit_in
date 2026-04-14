<?php
session_start();
if(!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true){
    header("Location: login.php"); exit;
}
include 'Database/connect.php';

// Auto-add time_out column if it doesn't exist yet
$conn->query("ALTER TABLE sit_in ADD COLUMN IF NOT EXISTS time_out TIME DEFAULT NULL");
$conn->query("ALTER TABLE sit_in ADD COLUMN IF NOT EXISTS time_out_date DATE DEFAULT NULL");

// Handle Time Out action
if(isset($_GET['timeout'])){
    $timeout_id = intval($_GET['timeout']);
    $now_time = date('H:i:s');
    $now_date = date('Y-m-d');

    // Get the student's id_number from this sit-in record
    $fetch = $conn->prepare("SELECT id_number FROM sit_in WHERE id = ? AND time_out IS NULL");
    $fetch->bind_param("i", $timeout_id);
    $fetch->execute();
    $row = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    if($row) {
        // Deduct one session from the student
        $upd = $conn->prepare("UPDATE students SET sessions = GREATEST(sessions - 1, 0) WHERE id_number = ?");
        $upd->bind_param("s", $row['id_number']);
        $upd->execute();
        $upd->close();

        // Get the new session count to update the sit-in log record
        $ses = $conn->prepare("SELECT sessions FROM students WHERE id_number = ?");
        $ses->bind_param("s", $row['id_number']);
        $ses->execute();
        $new_sessions = $ses->get_result()->fetch_assoc()['sessions'];
        $ses->close();

        // Record time-out and update remaining_session in the log
        $s = $conn->prepare("UPDATE sit_in SET time_out = ?, time_out_date = ?, remaining_session = ? WHERE id = ? AND time_out IS NULL");
        $s->bind_param("ssii", $now_time, $now_date, $new_sessions, $timeout_id);
        $s->execute();
        $s->close();
    }

    header("Location: sitin_logs.php?timed_out=1"); exit;
}

// Filters
$search    = trim($_GET['search'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');
$lab_f     = trim($_GET['lab'] ?? '');
$purpose_f = trim($_GET['purpose'] ?? '');
$status_f  = trim($_GET['status'] ?? ''); // 'active' | 'done' | ''

$where  = "WHERE 1=1";
$params = [];
$types  = '';

if($search !== ''){
    $like = '%' . $search . '%';
    $where .= " AND (student_name LIKE ? OR id_number LIKE ?)";
    $params = array_merge($params, [$like, $like]);
    $types .= 'ss';
}
if($date_from !== ''){
    $where .= " AND sit_in_date >= ?";
    $params[] = $date_from; $types .= 's';
}
if($date_to !== ''){
    $where .= " AND sit_in_date <= ?";
    $params[] = $date_to; $types .= 's';
}
if($lab_f !== ''){
    $where .= " AND lab = ?";
    $params[] = $lab_f; $types .= 's';
}
if($purpose_f !== ''){
    $where .= " AND purpose = ?";
    $params[] = $purpose_f; $types .= 's';
}
if($status_f === 'active'){
    $where .= " AND time_out IS NULL";
}
if($status_f === 'done'){
    $where .= " AND time_out IS NOT NULL";
}

// Pagination
$per_page = 20;
$page   = max(1, intval($_GET['p'] ?? 1));
$offset = ($page - 1) * $per_page;

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

// Stats
$today_count   = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE sit_in_date = CURDATE()")->fetch_assoc()['c'];
$week_count    = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE sit_in_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
$month_count   = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE MONTH(sit_in_date) = MONTH(CURDATE()) AND YEAR(sit_in_date) = YEAR(CURDATE())")->fetch_assoc()['c'];
$total_all     = $conn->query("SELECT COUNT(*) as c FROM sit_in")->fetch_assoc()['c'];
$active_count  = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE time_out IS NULL AND sit_in_date = CURDATE()")->fetch_assoc()['c'];

// Distinct labs and purposes for filters
$labs     = $conn->query("SELECT DISTINCT lab FROM sit_in ORDER BY lab")->fetch_all(MYSQLI_ASSOC);
$purposes = $conn->query("SELECT DISTINCT purpose FROM sit_in ORDER BY purpose")->fetch_all(MYSQLI_ASSOC);

$conn->close();

// Helper: compute duration string
function computeDuration($date_in, $time_in, $date_out, $time_out) {
    if(!$time_out) return null;
    $in  = strtotime($date_in  . ' ' . $time_in);
    $out = strtotime($date_out . ' ' . $time_out);
    $diff = max(0, $out - $in);
    $h = floor($diff / 3600);
    $m = floor(($diff % 3600) / 60);
    if($h > 0) return "{$h}h {$m}m";
    return "{$m}m";
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
/* ── Active pulse badge ── */
.badge-active {
    background: rgba(34,197,94,0.12);
    color: #15803d;
    border: 1px solid rgba(34,197,94,0.35);
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 12px;
    font-weight: 600;
}
.pulse-dot {
    width: 7px; height: 7px;
    background: #22c55e;
    border-radius: 50%;
    display: inline-block;
    animation: pulse 1.6s ease-in-out infinite;
}
@keyframes pulse {
    0%,100% { opacity: 1; transform: scale(1); }
    50%      { opacity: 0.4; transform: scale(0.7); }
}

/* ── Done badge ── */
.badge-done {
    background: var(--gray-100);
    color: var(--gray-500);
    border: 1px solid var(--gray-300);
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 12px;
    font-weight: 600;
}

/* ── Duration pill ── */
.duration-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: rgba(30,111,224,0.08);
    color: var(--blue);
    border: 1px solid rgba(30,111,224,0.18);
    padding: 3px 9px;
    border-radius: 100px;
    font-size: 12px;
    font-weight: 600;
}

/* ── Timeout button ── */
.btn-timeout {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 13px;
    background: rgba(239,68,68,0.08);
    color: #b91c1c;
    border: 1px solid rgba(239,68,68,0.25);
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-weight: 600;
    font-family: 'Outfit', sans-serif;
    cursor: pointer;
    transition: all var(--transition);
    text-decoration: none;
    white-space: nowrap;
}
.btn-timeout:hover {
    background: rgba(239,68,68,0.18);
    border-color: rgba(239,68,68,0.45);
    transform: translateY(-1px);
}

/* ── Active row highlight ── */
tbody tr.row-active {
    background: rgba(34,197,94,0.04);
}
tbody tr.row-active:hover {
    background: rgba(34,197,94,0.08);
}

/* ── Time columns ── */
.time-block {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.time-val {
    font-weight: 600;
    color: var(--navy);
    font-size: 13px;
}
.time-date {
    font-size: 11px;
    color: var(--gray-300);
}

/* ── Status filter tabs ── */
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
.status-tab.tab-active-green.active  { background: #16a34a; border-color: #16a34a; }
.status-tab.tab-active-green:hover   { border-color: #16a34a; color: #16a34a; }

/* ── Mini stat accent ── */
.mini-stat:nth-child(5) { border-left-color: #22c55e; }

@media print {
    .btn-timeout, .filter-bar, .dashboard-navbar,
    .status-tabs, .pagination, .no-print { display: none !important; }
    body { background: white; }
    .card { box-shadow: none; border: 1px solid #ddd; }
}
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

    <!-- Mini Stats -->
    <div class="mini-stats" style="grid-template-columns: repeat(auto-fit, minmax(140px,1fr));">
        <div class="mini-stat">
            <div class="mini-stat-value"><?php echo $today_count; ?></div>
            <div class="mini-stat-label">Today</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value"><?php echo $week_count; ?></div>
            <div class="mini-stat-label">This Week</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value"><?php echo $month_count; ?></div>
            <div class="mini-stat-label">This Month</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value"><?php echo $total_all; ?></div>
            <div class="mini-stat-label">All Time</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value" style="color:#16a34a"><?php echo $active_count; ?></div>
            <div class="mini-stat-label">Currently Inside</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-header-left">Session Records</div>
            <span style="font-size:13px;opacity:0.7"><?php echo $total; ?> record<?php echo $total != 1 ? 's' : ''; ?></span>
        </div>
        <div class="card-body">

            <!-- Status Tabs -->
            <div class="status-tabs">
                <?php
                // Build base query string without status
                $base_qs = http_build_query(array_filter([
                    'search'    => $search,
                    'date_from' => $date_from,
                    'date_to'   => $date_to,
                    'lab'       => $lab_f,
                    'purpose'   => $purpose_f,
                ]));
                ?>
                <a href="sitin_logs.php?<?php echo $base_qs; ?>"
                   class="status-tab <?php echo $status_f === '' ? 'active' : ''; ?>">
                    All
                </a>
                <a href="sitin_logs.php?<?php echo $base_qs; ?>&status=active"
                   class="status-tab tab-active-green <?php echo $status_f === 'active' ? 'active' : ''; ?>">
                    🟢 Currently Inside
                </a>
                <a href="sitin_logs.php?<?php echo $base_qs; ?>&status=done"
                   class="status-tab <?php echo $status_f === 'done' ? 'active' : ''; ?>">
                    ✓ Timed Out
                </a>
            </div>

            <!-- Filters -->
            <form method="GET" action="">
                <?php if($status_f !== ''): ?>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_f); ?>">
                <?php endif; ?>
                <div class="filter-bar">
                    <input type="text" name="search" class="search-input"
                           placeholder="Search by name or ID..."
                           value="<?php echo htmlspecialchars($search); ?>">
                    <input type="date" name="date_from" class="filter-select"
                           value="<?php echo htmlspecialchars($date_from); ?>" title="From date">
                    <input type="date" name="date_to" class="filter-select"
                           value="<?php echo htmlspecialchars($date_to); ?>" title="To date">
                    <select name="lab" class="filter-select">
                        <option value="">All Labs</option>
                        <?php foreach($labs as $l): ?>
                        <option value="<?php echo htmlspecialchars($l['lab']); ?>"
                            <?php echo $lab_f === $l['lab'] ? 'selected' : ''; ?>>
                            Lab <?php echo htmlspecialchars($l['lab']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="purpose" class="filter-select">
                        <option value="">All Purposes</option>
                        <?php foreach($purposes as $p): ?>
                        <option value="<?php echo htmlspecialchars($p['purpose']); ?>"
                            <?php echo $purpose_f === $p['purpose'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['purpose']); ?>
                        </option>
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
                            <th class="no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($logs as $i => $log):
                            $is_active = empty($log['time_out']);
                            $duration  = computeDuration(
                                $log['sit_in_date'],
                                $log['sit_in_time'],
                                $log['time_out_date'] ?? $log['sit_in_date'],
                                $log['time_out'] ?? null
                            );
                        ?>
                        <tr class="<?php echo $is_active ? 'row-active' : ''; ?>">
                            <td><?php echo $offset + $i + 1; ?></td>
                            <td class="td-bold"><?php echo htmlspecialchars($log['id_number']); ?></td>
                            <td class="td-bold"><?php echo htmlspecialchars($log['student_name']); ?></td>
                            <td><span class="badge badge-blue"><?php echo htmlspecialchars($log['purpose']); ?></span></td>
                            <td>Lab <?php echo htmlspecialchars($log['lab']); ?></td>
                            <td>
                                <?php $r = $log['remaining_session']; ?>
                                <span class="badge <?php echo $r > 10 ? 'badge-success' : ($r > 0 ? 'badge-warning' : 'badge-danger'); ?>">
                                    <?php echo $r; ?>
                                </span>
                            </td>

                            <!-- Time In -->
                            <td>
                                <div class="time-block">
                                    <span class="time-val"><?php echo date('h:i A', strtotime($log['sit_in_time'])); ?></span>
                                    <span class="time-date"><?php echo date('M d, Y', strtotime($log['sit_in_date'])); ?></span>
                                </div>
                            </td>

                            <!-- Time Out -->
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

                            <!-- Duration -->
                            <td>
                                <?php if($duration): ?>
                                <span class="duration-pill">⏱ <?php echo $duration; ?></span>
                                <?php else: ?>
                                <span id="live-<?php echo $log['id']; ?>" class="duration-pill"
                                      data-in="<?php echo strtotime($log['sit_in_date'].' '.$log['sit_in_time']); ?>">
                                    ⏱ …
                                </span>
                                <?php endif; ?>
                            </td>

                            <!-- Status -->
                            <td>
                                <?php if($is_active): ?>
                                <span class="badge-active"><span class="pulse-dot"></span> Active</span>
                                <?php else: ?>
                                <span class="badge-done">✓ Done</span>
                                <?php endif; ?>
                            </td>

                            <!-- Action -->
                            <td class="no-print">
                                <?php if($is_active): ?>
                                <a href="sitin_logs.php?timeout=<?php echo $log['id']; ?><?php
                                    echo '&search='.urlencode($search)
                                       .'&date_from='.urlencode($date_from)
                                       .'&date_to='.urlencode($date_to)
                                       .'&lab='.urlencode($lab_f)
                                       .'&purpose='.urlencode($purpose_f)
                                       .'&status='.urlencode($status_f)
                                       .'&p='.$page; ?>"
                                   class="btn-timeout"
                                   onclick="return confirm('Time out <?php echo htmlspecialchars(addslashes($log['student_name'])); ?>?')">
                                    🔴 Time Out
                                </a>
                                <?php else: ?>
                                <span style="color:var(--gray-300);font-size:12px;">Completed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if($total_pages > 1): ?>
            <div class="pagination">
                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?p=<?php echo $i;
                    echo '&search='.urlencode($search)
                       .'&date_from='.urlencode($date_from)
                       .'&date_to='.urlencode($date_to)
                       .'&lab='.urlencode($lab_f)
                       .'&purpose='.urlencode($purpose_f)
                       .'&status='.urlencode($status_f); ?>"
                   class="page-btn <?php echo $page == $i ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>

        </div>
    </div>
</div>

<script>
// Live elapsed timer for active sessions
function updateLiveTimers() {
    const now = Math.floor(Date.now() / 1000);
    document.querySelectorAll('[id^="live-"]').forEach(el => {
        const inTime = parseInt(el.dataset.in, 10);
        const diff   = Math.max(0, now - inTime);
        const h = Math.floor(diff / 3600);
        const m = Math.floor((diff % 3600) / 60);
        const s = diff % 60;
        if(h > 0) {
            el.textContent = `⏱ ${h}h ${m}m`;
        } else {
            el.textContent = `⏱ ${m}m ${s}s`;
        }
    });
}
updateLiveTimers();
setInterval(updateLiveTimers, 1000);
</script>

</body>
</html>