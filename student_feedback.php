<?php
session_start();
if(!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true){
    header("Location: login.php"); exit;
}
include 'Database/connect.php';

// Auto-create feedback table
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

// Filters
$search   = trim($_GET['search'] ?? '');
$lab_f    = trim($_GET['lab'] ?? '');
$rating_f = trim($_GET['rating'] ?? '');
$date_f   = trim($_GET['date'] ?? '');

$where  = "WHERE 1=1";
$params = [];
$types  = '';

if($search !== ''){
    $like = '%' . $search . '%';
    $where .= " AND (f.student_name LIKE ? OR f.id_number LIKE ?)";
    $params = array_merge($params, [$like, $like]);
    $types .= 'ss';
}
if($lab_f !== ''){
    $where .= " AND f.lab = ?";
    $params[] = $lab_f; $types .= 's';
}
if($rating_f !== ''){
    $where .= " AND f.rating = ?";
    $params[] = intval($rating_f); $types .= 'i';
}
if($date_f !== ''){
    $where .= " AND DATE(f.submitted_at) = ?";
    $params[] = $date_f; $types .= 's';
}

// Pagination
$per_page = 15;
$page   = max(1, intval($_GET['p'] ?? 1));
$offset = ($page - 1) * $per_page;

$count_sql = "SELECT COUNT(*) as c FROM sit_in_feedback f $where";
if(!empty($params)){
    $cs = $conn->prepare($count_sql);
    $cs->bind_param($types, ...$params);
    $cs->execute();
    $total = $cs->get_result()->fetch_assoc()['c'];
    $cs->close();
} else {
    $total = $conn->query("SELECT COUNT(*) as c FROM sit_in_feedback")->fetch_assoc()['c'];
}
$total_pages = max(1, ceil($total / $per_page));

$all_params = array_merge($params, [$per_page, $offset]);
$all_types  = $types . 'ii';
$s = $conn->prepare(
    "SELECT f.*, s.sit_in_date, s.sit_in_time, s.purpose
     FROM sit_in_feedback f
     LEFT JOIN sit_in s ON s.id = f.sit_in_id
     $where
     ORDER BY f.submitted_at DESC
     LIMIT ? OFFSET ?"
);
if(!empty($all_params)){ $s->bind_param($all_types, ...$all_params); }
$s->execute();
$feedbacks = $s->get_result()->fetch_all(MYSQLI_ASSOC);
$s->close();

// Aggregate stats
$stats = $conn->query("SELECT
    COUNT(*) as total,
    ROUND(AVG(rating),2) as avg_rating,
    SUM(rating=5) as five_star,
    SUM(rating=4) as four_star,
    SUM(rating=3) as three_star,
    SUM(rating=2) as two_star,
    SUM(rating=1) as one_star
    FROM sit_in_feedback")->fetch_assoc();

// Distinct labs for filter
$labs = $conn->query("SELECT DISTINCT lab FROM sit_in_feedback ORDER BY lab")->fetch_all(MYSQLI_ASSOC);

$conn->close();

function stars($n){
    return str_repeat('★', $n) . str_repeat('☆', 5 - $n);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Feedback — Admin</title>
<link rel="stylesheet" href="admin_shared.css">
<link rel="icon" type="image/png" href="pictures/uclogo.png">
<style>
/* ── Rating bar ── */
.rating-bars { display: flex; flex-direction: column; gap: 8px; }

.rating-row {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
}

.rating-row-label {
    width: 60px;
    color: #f59e0b;
    font-weight: 600;
    flex-shrink: 0;
    font-size: 13px;
}

.rating-bar-track {
    flex: 1;
    background: var(--gray-100);
    border-radius: 100px;
    height: 10px;
    overflow: hidden;
}

.rating-bar-fill {
    height: 100%;
    border-radius: 100px;
    background: linear-gradient(90deg, #f59e0b, #fbbf24);
    transition: width 0.6s cubic-bezier(0.4,0,0.2,1);
}

.rating-bar-count {
    width: 28px;
    text-align: right;
    font-size: 12px;
    color: var(--gray-500);
    font-weight: 600;
}

/* ── Big average ── */
.avg-score {
    font-size: 52px;
    font-weight: 700;
    color: var(--navy);
    line-height: 1;
    margin-bottom: 6px;
}

.avg-stars {
    font-size: 22px;
    color: #f59e0b;
    letter-spacing: 2px;
    margin-bottom: 4px;
}

.avg-label {
    font-size: 13px;
    color: var(--gray-500);
}

/* ── Summary card ── */
.summary-grid {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 28px;
    align-items: center;
}

@media(max-width:600px){ .summary-grid { grid-template-columns: 1fr; } }

/* ── Feedback item ── */
.feedback-item {
    padding: 18px 20px;
    border: 1.5px solid var(--gray-100);
    border-radius: var(--radius-sm);
    transition: all var(--transition);
    margin-bottom: 0;
}

.feedback-item:hover {
    border-color: rgba(30,111,224,0.18);
    background: #fafcff;
}

.fi-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 8px;
}

.fi-student { font-size: 15px; font-weight: 700; color: var(--navy); }
.fi-id      { font-size: 12px; color: var(--gray-300); margin-top: 2px; }

.fi-stars {
    font-size: 18px;
    color: #f59e0b;
    letter-spacing: 1px;
}

.fi-meta {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    font-size: 13px;
    color: var(--gray-500);
    margin-bottom: 10px;
}

.fi-comment {
    font-size: 14px;
    color: var(--gray-700);
    background: var(--off-white);
    padding: 10px 14px;
    border-radius: 8px;
    border-left: 3px solid var(--blue);
    line-height: 1.65;
    font-style: italic;
}

.fi-no-comment {
    font-size: 13px;
    color: var(--gray-300);
    font-style: italic;
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
        <li><a href="sitin_logs.php">Sit-in Logs</a></li>
        <li><a href="reservations.php">Reservations</a></li>
        <li><a href="reports.php">Reports</a></li>
        <li><a href="student_feedback.php" class="active">Feedback</a></li>
        <li><a href="logout.php" class="logout-btn">Log Out</a></li>
    </ul>
</nav>

<div class="page-container">

    <div class="page-header">
        <div>
            <div class="page-title">Student Feedback</div>
            <div class="page-subtitle">Ratings and comments submitted after each sit-in session</div>
        </div>
        <button class="btn btn-primary no-print" onclick="window.print()">🖨 Print</button>
    </div>

    <!-- Summary Card -->
    <?php if($stats['total'] > 0): ?>
    <div class="card" style="margin-bottom:22px;">
        <div class="card-header"><div class="card-header-left">Overall Rating Summary</div></div>
        <div class="card-body">
            <div class="summary-grid">
                <!-- Average score -->
                <div style="text-align:center;padding:8px 24px 8px 8px;border-right:1.5px solid var(--gray-100);">
                    <div class="avg-score"><?php echo number_format($stats['avg_rating'],1); ?></div>
                    <div class="avg-stars">
                        <?php
                        $avg = round($stats['avg_rating']);
                        echo str_repeat('★', $avg) . str_repeat('☆', 5 - $avg);
                        ?>
                    </div>
                    <div class="avg-label">out of 5 · <?php echo $stats['total']; ?> review<?php echo $stats['total'] != 1 ? 's' : ''; ?></div>
                </div>

                <!-- Bar breakdown -->
                <div class="rating-bars">
                    <?php
                    $breakdown = [5=>$stats['five_star'],4=>$stats['four_star'],3=>$stats['three_star'],2=>$stats['two_star'],1=>$stats['one_star']];
                    foreach($breakdown as $star => $count):
                        $pct = $stats['total'] > 0 ? round($count / $stats['total'] * 100) : 0;
                    ?>
                    <div class="rating-row">
                        <span class="rating-row-label"><?php echo str_repeat('★', $star); ?></span>
                        <div class="rating-bar-track">
                            <div class="rating-bar-fill" style="width:<?php echo $pct; ?>%"></div>
                        </div>
                        <span class="rating-bar-count"><?php echo $count; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Feedback list -->
    <div class="card">
        <div class="card-header">
            <div class="card-header-left">All Feedback</div>
            <span style="font-size:13px;opacity:0.7"><?php echo $total; ?> entr<?php echo $total != 1 ? 'ies' : 'y'; ?></span>
        </div>
        <div class="card-body">

            <!-- Filters -->
            <form method="GET" action="">
                <div class="filter-bar">
                    <input type="text" name="search" class="search-input"
                           placeholder="Search by student name or ID..."
                           value="<?php echo htmlspecialchars($search); ?>">

                    <select name="lab" class="filter-select">
                        <option value="">All Labs</option>
                        <?php foreach($labs as $l): ?>
                        <option value="<?php echo htmlspecialchars($l['lab']); ?>"
                            <?php echo $lab_f === $l['lab'] ? 'selected' : ''; ?>>
                            Lab <?php echo htmlspecialchars($l['lab']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="rating" class="filter-select">
                        <option value="">All Ratings</option>
                        <?php for($i = 5; $i >= 1; $i--): ?>
                        <option value="<?php echo $i; ?>" <?php echo $rating_f == $i ? 'selected' : ''; ?>>
                            <?php echo str_repeat('★', $i); ?> (<?php echo $i; ?> star<?php echo $i > 1 ? 's' : ''; ?>)
                        </option>
                        <?php endfor; ?>
                    </select>

                    <input type="date" name="date" class="filter-select"
                           value="<?php echo htmlspecialchars($date_f); ?>"
                           title="Filter by submission date">

                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="student_feedback.php" class="btn btn-gray">Reset</a>
                </div>
            </form>

            <?php if(empty($feedbacks)): ?>
            <div class="empty-state">
                <div class="empty-icon">⭐</div>
                <div class="empty-title">No feedback yet</div>
                <div class="empty-desc">Students can submit feedback from their History page after a session ends.</div>
            </div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <?php foreach($feedbacks as $f): ?>
                <div class="feedback-item">
                    <div class="fi-top">
                        <div>
                            <div class="fi-student"><?php echo htmlspecialchars($f['student_name']); ?></div>
                            <div class="fi-id"><?php echo htmlspecialchars($f['id_number']); ?></div>
                        </div>
                        <div style="text-align:right">
                            <div class="fi-stars"><?php echo stars($f['rating']); ?></div>
                            <div style="font-size:11px;color:var(--gray-300);margin-top:2px;">
                                <?php echo date('M d, Y · h:i A', strtotime($f['submitted_at'])); ?>
                            </div>
                        </div>
                    </div>
                    <div class="fi-meta">
                        <span>🧪 Lab <?php echo htmlspecialchars($f['lab']); ?></span>
                        <?php if(!empty($f['purpose'])): ?>
                        <span>📌 <?php echo htmlspecialchars($f['purpose']); ?></span>
                        <?php endif; ?>
                        <?php if(!empty($f['sit_in_date'])): ?>
                        <span>📅 <?php echo date('M d, Y', strtotime($f['sit_in_date'])); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if(!empty(trim($f['feedback_text']))): ?>
                    <div class="fi-comment">"<?php echo htmlspecialchars($f['feedback_text']); ?>"</div>
                    <?php else: ?>
                    <div class="fi-no-comment">No comment provided.</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if($total_pages > 1): ?>
            <div class="pagination">
                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?p=<?php echo $i;
                    echo '&search='.urlencode($search)
                       .'&lab='.urlencode($lab_f)
                       .'&rating='.urlencode($rating_f)
                       .'&date='.urlencode($date_f); ?>"
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
