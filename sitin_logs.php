<?php
session_start();
if(!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true){
    header("Location: login.php"); exit;
}
include 'Database/connect.php';

// Filters
$search    = trim($_GET['search'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');
$lab_f     = trim($_GET['lab'] ?? '');
$purpose_f = trim($_GET['purpose'] ?? '');

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

// Pagination
$per_page = 20;
$page   = max(1, intval($_GET['p'] ?? 1));
$offset = ($page - 1) * $per_page;

if(!empty($params)){
    $cs = $conn->prepare("SELECT COUNT(*) as c FROM sit_in $where");
    $cs->bind_param($types, ...$params);
    $cs->execute();
    $total = $cs->get_result()->fetch_assoc()['c'];
} else {
    $total = $conn->query("SELECT COUNT(*) as c FROM sit_in")->fetch_assoc()['c'];
}
$total_pages = ceil($total / $per_page);

$all_params = array_merge($params, [$per_page, $offset]);
$all_types  = $types . 'ii';
$s = $conn->prepare("SELECT * FROM sit_in $where ORDER BY sit_in_date DESC, sit_in_time DESC LIMIT ? OFFSET ?");
if(!empty($all_params)){ $s->bind_param($all_types, ...$all_params); }
$s->execute();
$logs = $s->get_result()->fetch_all(MYSQLI_ASSOC);

// Stats
$today_count  = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE sit_in_date = CURDATE()")->fetch_assoc()['c'];
$week_count   = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE sit_in_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
$month_count  = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE MONTH(sit_in_date) = MONTH(CURDATE()) AND YEAR(sit_in_date) = YEAR(CURDATE())")->fetch_assoc()['c'];
$total_all    = $conn->query("SELECT COUNT(*) as c FROM sit_in")->fetch_assoc()['c'];

// Distinct labs and purposes for filters
$labs     = $conn->query("SELECT DISTINCT lab FROM sit_in ORDER BY lab")->fetch_all(MYSQLI_ASSOC);
$purposes = $conn->query("SELECT DISTINCT purpose FROM sit_in ORDER BY purpose")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sit-in Logs — Admin</title>
<link rel="stylesheet" href="admin_shared.css">
<link rel="icon" type="image/png" href="pictures/uclogo.png">
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
            <div class="page-subtitle">Complete history of all laboratory sit-in sessions</div>
        </div>
        <button class="btn btn-primary no-print" onclick="window.print()">🖨 Print Logs</button>
    </div>

    <!-- Mini Stats -->
    <div class="mini-stats">
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
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-header-left">Session Records</div>
            <span style="font-size:13px;opacity:0.7"><?php echo $total; ?> record<?php echo $total != 1 ? 's' : ''; ?></span>
        </div>
        <div class="card-body">

            <!-- Filters -->
            <form method="GET" action="">
                <div class="filter-bar">
                    <input type="text" name="search" class="search-input" placeholder="Search by name or ID..." value="<?php echo htmlspecialchars($search); ?>">
                    <input type="date" name="date_from" class="filter-select" value="<?php echo htmlspecialchars($date_from); ?>" title="From date">
                    <input type="date" name="date_to"   class="filter-select" value="<?php echo htmlspecialchars($date_to); ?>" title="To date">
                    <select name="lab" class="filter-select">
                        <option value="">All Labs</option>
                        <?php foreach($labs as $l): ?>
                        <option value="<?php echo htmlspecialchars($l['lab']); ?>" <?php echo $lab_f === $l['lab'] ? 'selected' : ''; ?>>
                            Lab <?php echo htmlspecialchars($l['lab']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="purpose" class="filter-select">
                        <option value="">All Purposes</option>
                        <?php foreach($purposes as $p): ?>
                        <option value="<?php echo htmlspecialchars($p['purpose']); ?>" <?php echo $purpose_f === $p['purpose'] ? 'selected' : ''; ?>>
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
                            <th>Date</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($logs as $i => $log): ?>
                        <tr>
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
                            <td><?php echo date('M d, Y', strtotime($log['sit_in_date'])); ?></td>
                            <td><?php echo date('h:i A', strtotime($log['sit_in_time'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if($total_pages > 1): ?>
            <div class="pagination">
                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?p=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&lab=<?php echo urlencode($lab_f); ?>&purpose=<?php echo urlencode($purpose_f); ?>"
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
