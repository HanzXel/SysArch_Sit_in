<?php
session_start();
if(!isset($_SESSION['student_id'])){
    header("Location: login.php"); exit;
}
include 'Database/connect.php';

// Auto-create feedback table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS sit_in_feedback (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    sit_in_id       INT NOT NULL,
    student_id      INT NOT NULL,
    id_number       VARCHAR(50) NOT NULL,
    student_name    VARCHAR(200) NOT NULL,
    lab             VARCHAR(50) NOT NULL,
    rating          TINYINT(1) NOT NULL,
    feedback_text   TEXT DEFAULT NULL,
    submitted_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sitin_feedback (sit_in_id)
)");

$id_number = $_SESSION['id_number'];

// Filters
$filter_month   = trim($_GET['month'] ?? '');
$filter_purpose = trim($_GET['purpose'] ?? '');
$search         = trim($_GET['search'] ?? '');

$where  = "WHERE id_number = ?";
$params = [$id_number];
$types  = 's';

if($filter_month !== ''){
    $where .= " AND DATE_FORMAT(sit_in_date, '%Y-%m') = ?";
    $params[] = $filter_month; $types .= 's';
}
if($filter_purpose !== ''){
    $where .= " AND purpose = ?";
    $params[] = $filter_purpose; $types .= 's';
}
if($search !== ''){
    $where .= " AND (purpose LIKE ? OR lab LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like;
    $types .= 'ss';
}

// Pagination
$per_page = 10;
$page   = max(1, intval($_GET['p'] ?? 1));
$offset = ($page - 1) * $per_page;

$cs = $conn->prepare("SELECT COUNT(*) as c FROM sit_in $where");
$cs->bind_param($types, ...$params);
$cs->execute();
$total = $cs->get_result()->fetch_assoc()['c'];
$total_pages = max(1, ceil($total / $per_page));

$all_params = array_merge($params, [$per_page, $offset]);
$s = $conn->prepare("SELECT * FROM sit_in $where ORDER BY sit_in_date DESC, sit_in_time DESC LIMIT ? OFFSET ?");
$s->bind_param($types . 'ii', ...$all_params);
$s->execute();
$history = $s->get_result()->fetch_all(MYSQLI_ASSOC);

// Collect sit-in IDs that already have feedback
$sit_in_ids = array_column($history, 'id');
$feedback_submitted_ids = [];
if(!empty($sit_in_ids)){
    $placeholders = implode(',', array_fill(0, count($sit_in_ids), '?'));
    $types_fb     = str_repeat('i', count($sit_in_ids));
    $fb = $conn->prepare("SELECT sit_in_id FROM sit_in_feedback WHERE sit_in_id IN ($placeholders)");
    $fb->bind_param($types_fb, ...$sit_in_ids);
    $fb->execute();
    $fb_rows = $fb->get_result()->fetch_all(MYSQLI_ASSOC);
    $feedback_submitted_ids = array_column($fb_rows, 'sit_in_id');
    $fb->close();
}

// Stats for this student
$total_sessions_used = $conn->prepare("SELECT COUNT(*) as c FROM sit_in WHERE id_number = ?");
$total_sessions_used->bind_param("s", $id_number);
$total_sessions_used->execute();
$used = $total_sessions_used->get_result()->fetch_assoc()['c'];

$month_used_s = $conn->prepare("SELECT COUNT(*) as c FROM sit_in WHERE id_number = ? AND MONTH(sit_in_date)=MONTH(CURDATE()) AND YEAR(sit_in_date)=YEAR(CURDATE())");
$month_used_s->bind_param("s", $id_number);
$month_used_s->execute();
$month_used = $month_used_s->get_result()->fetch_assoc()['c'];

$fav_purpose_s = $conn->prepare("SELECT purpose, COUNT(*) as c FROM sit_in WHERE id_number = ? GROUP BY purpose ORDER BY c DESC LIMIT 1");
$fav_purpose_s->bind_param("s", $id_number);
$fav_purpose_s->execute();
$fav_purpose_row = $fav_purpose_s->get_result()->fetch_assoc();
$fav_purpose = $fav_purpose_row ? $fav_purpose_row['purpose'] : '—';

// Distinct purposes for filter
$purposes_s = $conn->prepare("SELECT DISTINCT purpose FROM sit_in WHERE id_number = ? ORDER BY purpose");
$purposes_s->bind_param("s", $id_number);
$purposes_s->execute();
$purposes = $purposes_s->get_result()->fetch_all(MYSQLI_ASSOC);

$remaining = $_SESSION['sessions'] ?? 30;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sit-in History — CCS Sit-in</title>
<link rel="stylesheet" href="userdb.css">
<link rel="icon" type="image/png" href="pictures/uclogo.png">
<style>
.history-page { padding: 28px 32px; max-width: 1100px; margin: 0 auto; }

.page-title {
    font-family: 'Playfair Display', serif;
    font-size: 26px;
    font-weight: 600;
    color: var(--navy);
}
.page-subtitle { font-size: 14px; color: var(--gray-500); margin-top: 3px; margin-bottom: 24px; }

.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.stat-tile {
    background: var(--white);
    border-radius: var(--radius);
    box-shadow: var(--card-shadow);
    padding: 20px;
    border-top: 3px solid var(--blue);
    transition: all var(--transition);
}

.stat-tile:hover { transform: translateY(-2px); box-shadow: var(--card-shadow-hover); }
.stat-tile:nth-child(2) { border-top-color: #22c55e; }
.stat-tile:nth-child(3) { border-top-color: #f59e0b; }
.stat-tile:nth-child(4) { border-top-color: #8b5cf6; }

.stat-value { font-size: 28px; font-weight: 700; color: var(--navy); line-height: 1; margin-bottom: 5px; }
.stat-label { font-size: 13px; color: var(--gray-500); font-weight: 500; }

.history-card {
    background: var(--white);
    border-radius: var(--radius);
    box-shadow: var(--card-shadow);
    overflow: hidden;
}

.history-card-header {
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

.history-card-header::before {
    content: '';
    width: 4px; height: 16px;
    background: var(--blue-light);
    border-radius: 2px;
}

.history-card-body { padding: 24px; }

.filter-bar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.filter-input {
    padding: 9px 14px;
    border: 1.5px solid var(--gray-100);
    border-radius: var(--radius-sm);
    font-size: 14px;
    font-family: 'Outfit', sans-serif;
    color: var(--navy);
    background: var(--white);
    outline: none;
    transition: all var(--transition);
}

.filter-input:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(30,111,224,0.1);
}

.filter-btn {
    padding: 9px 18px;
    background: var(--blue);
    color: var(--white);
    border: none;
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-weight: 600;
    font-family: 'Outfit', sans-serif;
    cursor: pointer;
    transition: all var(--transition);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
}

.filter-btn:hover { background: var(--blue-light); }
.filter-btn.reset { background: var(--gray-100); color: var(--gray-700); }
.filter-btn.reset:hover { background: var(--gray-300); }

.history-table { width: 100%; border-collapse: collapse; font-size: 14px; }

.history-table thead tr { background: var(--navy); color: var(--white); }
.history-table thead th {
    padding: 12px 16px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}
.history-table thead th:first-child { border-radius: var(--radius-sm) 0 0 0; }
.history-table thead th:last-child  { border-radius: 0 var(--radius-sm) 0 0; }

.history-table tbody tr {
    border-bottom: 1px solid var(--gray-100);
    transition: background var(--transition);
}
.history-table tbody tr:last-child { border-bottom: none; }
.history-table tbody tr:hover { background: #f7f9ff; }
.history-table tbody td { padding: 13px 16px; color: var(--gray-700); vertical-align: middle; }

.td-bold { font-weight: 600; color: var(--navy); }

.badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 100px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
}
.badge-blue    { background: rgba(30,111,224,0.1); color: var(--blue); border: 1px solid rgba(30,111,224,0.2); }
.badge-success { background: rgba(34,197,94,0.1); color: #15803d; border: 1px solid rgba(34,197,94,0.3); }
.badge-warning { background: rgba(245,158,11,0.1); color: #92400e; border: 1px solid rgba(245,158,11,0.3); }
.badge-danger  { background: rgba(239,68,68,0.08); color: #b91c1c; border: 1px solid rgba(239,68,68,0.2); }

/* Status: active session (no timeout yet) */
.badge-active {
    background: rgba(34,197,94,0.12);
    color: #15803d;
    border: 1px solid rgba(34,197,94,0.3);
}

/* Feedback button */
.btn-feedback {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 13px;
    background: rgba(139,92,246,0.1);
    color: #6d28d9;
    border: 1px solid rgba(139,92,246,0.25);
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-weight: 600;
    font-family: 'Outfit', sans-serif;
    cursor: pointer;
    transition: all var(--transition);
    text-decoration: none;
    white-space: nowrap;
}

.btn-feedback:hover {
    background: rgba(139,92,246,0.2);
    transform: translateY(-1px);
}

.btn-feedback-done {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 13px;
    background: var(--gray-100);
    color: var(--gray-500);
    border: 1px solid var(--gray-300);
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    cursor: default;
}

/* Star display (read-only) */
.stars-display { color: #f59e0b; font-size: 14px; letter-spacing: 1px; }

.empty-state { text-align: center; padding: 60px 20px; }
.empty-icon  { font-size: 44px; margin-bottom: 14px; }
.empty-title { font-size: 16px; font-weight: 600; color: var(--gray-700); margin-bottom: 6px; }
.empty-desc  { font-size: 14px; color: var(--gray-500); }

.pagination {
    display: flex;
    gap: 6px;
    justify-content: center;
    margin-top: 20px;
    flex-wrap: wrap;
}
.page-btn {
    width: 36px; height: 36px;
    border-radius: var(--radius-sm);
    border: 1.5px solid var(--gray-100);
    background: var(--white);
    color: var(--gray-700);
    font-size: 13px;
    font-weight: 600;
    font-family: 'Outfit', sans-serif;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    text-decoration: none;
    transition: all var(--transition);
}
.page-btn:hover, .page-btn.active {
    background: var(--blue);
    border-color: var(--blue);
    color: var(--white);
}

@media(max-width:768px){
    .history-page { padding: 16px; }
    .stats-row { grid-template-columns: 1fr 1fr; }
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
        <li><a href="history.php" class="active">History</a></li>
        <li><a href="student_reservation.php">Reservation</a></li>
        <li><a href="logout.php" class="logout-btn">Log Out</a></li>
    </ul>
</nav>

<div class="history-page">

    <div class="page-title">Sit-in History</div>
    <div class="page-subtitle">A complete record of your laboratory sessions</div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-tile">
            <div class="stat-value"><?php echo $used; ?></div>
            <div class="stat-label">Total Sessions Used</div>
        </div>
        <div class="stat-tile">
            <div class="stat-value"><?php echo $remaining; ?></div>
            <div class="stat-label">Sessions Remaining</div>
        </div>
        <div class="stat-tile">
            <div class="stat-value"><?php echo $month_used; ?></div>
            <div class="stat-label">This Month</div>
        </div>
        <div class="stat-tile">
            <div class="stat-value" style="font-size:16px;padding-top:4px"><?php echo $fav_purpose; ?></div>
            <div class="stat-label">Most Used Purpose</div>
        </div>
    </div>

    <!-- History Table -->
    <div class="history-card">
        <div class="history-card-header">Session Records</div>
        <div class="history-card-body">

            <form method="GET" action="">
                <div class="filter-bar">
                    <input type="month" name="month" class="filter-input" value="<?php echo htmlspecialchars($filter_month); ?>" title="Filter by month">
                    <select name="purpose" class="filter-input">
                        <option value="">All Purposes</option>
                        <?php foreach($purposes as $p): ?>
                        <option value="<?php echo htmlspecialchars($p['purpose']); ?>" <?php echo $filter_purpose === $p['purpose'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['purpose']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="filter-btn">Filter</button>
                    <a href="history.php" class="filter-btn reset">Reset</a>
                </div>
            </form>

            <?php if(empty($history)): ?>
            <div class="empty-state">
                <div class="empty-icon">📭</div>
                <div class="empty-title">No records found</div>
                <div class="empty-desc">Your sit-in sessions will appear here once recorded.</div>
            </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Purpose</th>
                            <th>Laboratory</th>
                            <th>Sessions Left</th>
                            <th>Date</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Status</th>
                            <th>Feedback</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($history as $i => $h):
                            $is_active       = empty($h['time_out']);
                            $has_feedback    = in_array($h['id'], $feedback_submitted_ids);
                        ?>
                        <tr>
                            <td><?php echo $offset + $i + 1; ?></td>
                            <td><span class="badge badge-blue"><?php echo htmlspecialchars($h['purpose']); ?></span></td>
                            <td class="td-bold">Lab <?php echo htmlspecialchars($h['lab']); ?></td>
                            <td>
                                <?php $r = $h['remaining_session']; ?>
                                <span class="badge <?php echo $r > 10 ? 'badge-success' : ($r > 0 ? 'badge-warning' : 'badge-danger'); ?>">
                                    <?php echo $r; ?> left
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($h['sit_in_date'])); ?></td>
                            <td><?php echo date('h:i A', strtotime($h['sit_in_time'])); ?></td>
                            <td>
                                <?php if(!$is_active): ?>
                                    <?php echo date('h:i A', strtotime($h['time_out'])); ?>
                                <?php else: ?>
                                    <span class="badge badge-active">Active</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($is_active): ?>
                                    <span class="badge badge-active">🟢 In Progress</span>
                                <?php else: ?>
                                    <span class="badge badge-success">✓ Completed</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($is_active): ?>
                                    <span style="color:var(--gray-300);font-size:12px;">—</span>
                                <?php elseif($has_feedback): ?>
                                    <span class="btn-feedback-done">✓ Submitted</span>
                                <?php else: ?>
                                    <a href="feedback.php?sit_in_id=<?php echo $h['id']; ?>" class="btn-feedback">
                                        ⭐ Give Feedback
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if($total_pages > 1): ?>
            <div class="pagination">
                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?p=<?php echo $i; ?>&month=<?php echo urlencode($filter_month); ?>&purpose=<?php echo urlencode($filter_purpose); ?>"
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

</body>
</html>
