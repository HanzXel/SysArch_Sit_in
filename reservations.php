<?php
session_start();
if(!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true){
    header("Location: login.php"); exit;
}
include 'Database/connect.php';

// Auto-create reservations table
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

// Handle approve / reject
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])){
    $res_id = intval($_POST['res_id']);
    $action = $_POST['action'];
    $note   = trim($_POST['admin_note'] ?? '');

    if(in_array($action, ['approved', 'rejected'])){
    // Update the reservation
    $s = $conn->prepare("UPDATE reservations SET status = ?, admin_note = ? WHERE id = ?");
    $s->bind_param("ssi", $action, $note, $res_id);
    $s->execute();
    $s->close();

    // Fetch reservation + student details to build notification
    $r = $conn->prepare("SELECT r.*, s.id as student_db_id
                         FROM reservations r
                         JOIN students s ON s.id_number = r.id_number
                         WHERE r.id = ?");
    $r->bind_param("i", $res_id);
    $r->execute();
    $res_row = $r->get_result()->fetch_assoc();
    $r->close();

    if($res_row){
        $student_db_id = $res_row['student_db_id'];
        $lab           = $res_row['lab'];
        $res_date      = date('M d, Y', strtotime($res_row['reservation_date']));
        $res_time      = date('h:i A',  strtotime($res_row['reservation_time']));

        if($action === 'approved'){
            $notif_title = "Reservation Approved";
            $notif_msg   = "Your reservation for Lab $lab on $res_date at $res_time has been approved.";
            if($note) $notif_msg .= " Admin note: $note";
            $notif_type  = "success";
        } else {
            $notif_title = "Reservation Rejected";
            $notif_msg   = "Your reservation for Lab $lab on $res_date at $res_time was not approved.";
            if($note) $notif_msg .= " Reason: $note";
            $notif_type  = "danger";
        }

        // Auto-create notifications table if missing
        $conn->query("CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            title VARCHAR(200) NOT NULL,
            message TEXT NOT NULL,
            type ENUM('info','success','warning','danger') DEFAULT 'info',
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $n = $conn->prepare("INSERT INTO notifications (student_id, title, message, type) VALUES (?, ?, ?, ?)");
        $n->bind_param("isss", $student_db_id, $notif_title, $notif_msg, $notif_type);
        $n->execute();
        $n->close();
    }
}
header("Location: reservations.php?updated=1"); exit;
}

// Handle delete
if(isset($_GET['delete'])){
    $del = intval($_GET['delete']);
    $s = $conn->prepare("DELETE FROM reservations WHERE id = ?");
    $s->bind_param("i", $del);
    $s->execute();
    header("Location: reservations.php?deleted=1"); exit;
}

// Filters
$status_f  = trim($_GET['status'] ?? '');
$search    = trim($_GET['search'] ?? '');
$date_f    = trim($_GET['date'] ?? '');

$where  = "WHERE 1=1";
$params = [];
$types  = '';

if($status_f !== ''){
    $where .= " AND status = ?";
    $params[] = $status_f; $types .= 's';
}
if($search !== ''){
    $like = '%' . $search . '%';
    $where .= " AND (student_name LIKE ? OR id_number LIKE ?)";
    $params = array_merge($params, [$like, $like]);
    $types .= 'ss';
}
if($date_f !== ''){
    $where .= " AND reservation_date = ?";
    $params[] = $date_f; $types .= 's';
}

// Pagination
$per_page = 15;
$page   = max(1, intval($_GET['p'] ?? 1));
$offset = ($page - 1) * $per_page;

if(!empty($params)){
    $cs = $conn->prepare("SELECT COUNT(*) as c FROM reservations $where");
    $cs->bind_param($types, ...$params);
    $cs->execute();
    $total = $cs->get_result()->fetch_assoc()['c'];
} else {
    $total = $conn->query("SELECT COUNT(*) as c FROM reservations")->fetch_assoc()['c'];
}
$total_pages = ceil($total / $per_page);

$all_params = array_merge($params, [$per_page, $offset]);
$all_types  = $types . 'ii';
$s = $conn->prepare("SELECT * FROM reservations $where ORDER BY reservation_date ASC, reservation_time ASC LIMIT ? OFFSET ?");
if(!empty($all_params)){ $s->bind_param($all_types, ...$all_params); }
$s->execute();
$reservations = $s->get_result()->fetch_all(MYSQLI_ASSOC);

// Stats
$pending_c  = $conn->query("SELECT COUNT(*) as c FROM reservations WHERE status='pending'")->fetch_assoc()['c'];
$approved_c = $conn->query("SELECT COUNT(*) as c FROM reservations WHERE status='approved'")->fetch_assoc()['c'];
$rejected_c = $conn->query("SELECT COUNT(*) as c FROM reservations WHERE status='rejected'")->fetch_assoc()['c'];
$today_c    = $conn->query("SELECT COUNT(*) as c FROM reservations WHERE reservation_date=CURDATE()")->fetch_assoc()['c'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reservations — Admin</title>
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
        <li><a href="sitin_logs.php">Sit-in Logs</a></li>
        <li><a href="reservations.php" class="active">Reservations</a></li>
        <li><a href="reports.php">Reports</a></li>
        <li><a href="student_feedback.php">Feedback</a></li>
        <li><a href="logout.php" class="logout-btn">Log Out</a></li>
    </ul>
</nav>

<div class="page-container">

    <div class="page-header">
        <div>
            <div class="page-title">Reservations</div>
            <div class="page-subtitle">Review and manage student laboratory reservation requests</div>
        </div>
        <?php if($pending_c > 0): ?>
        <span class="badge badge-warning" style="font-size:14px;padding:8px 16px;">
            ⏳ <?php echo $pending_c; ?> pending approval
        </span>
        <?php endif; ?>
    </div>

    <?php if(isset($_GET['updated'])): ?>
    <div class="alert alert-success">✓ Reservation status updated successfully.</div>
    <?php endif; ?>
    <?php if(isset($_GET['deleted'])): ?>
    <div class="alert alert-danger">✓ Reservation deleted.</div>
    <?php endif; ?>

    <!-- Mini Stats -->
    <div class="mini-stats">
        <div class="mini-stat">
            <div class="mini-stat-value"><?php echo $pending_c; ?></div>
            <div class="mini-stat-label">Pending</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value"><?php echo $approved_c; ?></div>
            <div class="mini-stat-label">Approved</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value"><?php echo $rejected_c; ?></div>
            <div class="mini-stat-label">Rejected</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value"><?php echo $today_c; ?></div>
            <div class="mini-stat-label">Today's Reservations</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-header-left">Reservation Requests</div>
            <span style="font-size:13px;opacity:0.7"><?php echo $total; ?> total</span>
        </div>
        <div class="card-body">

            <!-- Filters -->
            <form method="GET" action="">
                <div class="filter-bar">
                    <input type="text" name="search" class="search-input" placeholder="Search by name or ID..." value="<?php echo htmlspecialchars($search); ?>">
                    <input type="date" name="date" class="filter-select" value="<?php echo htmlspecialchars($date_f); ?>">
                    <select name="status" class="filter-select">
                        <option value="">All Status</option>
                        <option value="pending"  <?php echo $status_f === 'pending'  ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $status_f === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status_f === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="reservations.php" class="btn btn-gray">Reset</a>
                </div>
            </form>

            <?php if(empty($reservations)): ?>
            <div class="empty-state">
                <div class="empty-icon">📅</div>
                <div class="empty-title">No reservations found</div>
                <div class="empty-desc">Student reservations will appear here once submitted.</div>
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
                            <th>Date</th>
                            <th>Time</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($reservations as $i => $r): ?>
                        <tr>
                            <td><?php echo $offset + $i + 1; ?></td>
                            <td class="td-bold"><?php echo htmlspecialchars($r['id_number']); ?></td>
                            <td class="td-bold"><?php echo htmlspecialchars($r['student_name']); ?></td>
                            <td><span class="badge badge-blue"><?php echo htmlspecialchars($r['purpose']); ?></span></td>
                            <td>Lab <?php echo htmlspecialchars($r['lab']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($r['reservation_date'])); ?></td>
                            <td><?php echo date('h:i A', strtotime($r['reservation_time'])); ?></td>
                            <td>
                                <?php
                                $badge = ['pending'=>'badge-warning','approved'=>'badge-success','rejected'=>'badge-danger'];
                                $icons = ['pending'=>'⏳','approved'=>'✓','rejected'=>'✕'];
                                ?>
                                <span class="badge <?php echo $badge[$r['status']]; ?>">
                                    <?php echo $icons[$r['status']] . ' ' . ucfirst($r['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap">
                                    <?php if($r['status'] === 'pending'): ?>
                                    <button class="btn btn-success btn-sm" onclick="openAction(<?php echo $r['id']; ?>,'approved','<?php echo htmlspecialchars($r['student_name']); ?>')">Approve</button>
                                    <button class="btn btn-danger btn-sm"  onclick="openAction(<?php echo $r['id']; ?>,'rejected','<?php echo htmlspecialchars($r['student_name']); ?>')">Reject</button>
                                    <?php else: ?>
                                    <button class="btn btn-gray btn-sm" onclick="openAction(<?php echo $r['id']; ?>,'<?php echo $r['status']; ?>','<?php echo htmlspecialchars($r['student_name']); ?>')">Update</button>
                                    <?php endif; ?>
                                    <a href="reservations.php?delete=<?php echo $r['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this reservation?')">Delete</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if($total_pages > 1): ?>
            <div class="pagination">
                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?p=<?php echo $i; ?>&status=<?php echo urlencode($status_f); ?>&search=<?php echo urlencode($search); ?>&date=<?php echo urlencode($date_f); ?>"
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

<!-- Action Modal -->
<div class="modal-overlay" id="actionModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="modal_title">Update Reservation</h2>
            <button class="close-btn" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="">
                <input type="hidden" name="res_id" id="modal_res_id">
                <input type="hidden" name="action" id="modal_action">
                <p style="font-size:14px;color:var(--gray-500);margin-bottom:18px;">
                    Student: <strong id="modal_student_name" style="color:var(--navy)"></strong>
                </p>
                <div class="form-group">
                    <label class="form-label">Note to Student <span style="font-weight:400;color:var(--gray-300)">(optional)</span></label>
                    <textarea name="admin_note" id="modal_note" class="form-input" rows="3" placeholder="Add a note for the student..."></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal()">Cancel</button>
                    <button type="submit" id="modal_submit_btn" class="btn btn-primary">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAction(id, action, name){
    document.getElementById('modal_res_id').value = id;
    document.getElementById('modal_action').value = action;
    document.getElementById('modal_student_name').textContent = name;
    document.getElementById('modal_note').value = '';

    const btn = document.getElementById('modal_submit_btn');
    const title = document.getElementById('modal_title');

    if(action === 'approved'){
        title.textContent = 'Approve Reservation';
        btn.textContent = '✓ Approve';
        btn.className = 'btn btn-success';
    } else if(action === 'rejected'){
        title.textContent = 'Reject Reservation';
        btn.textContent = '✕ Reject';
        btn.className = 'btn btn-danger';
    } else {
        title.textContent = 'Update Reservation';
        btn.textContent = 'Save Changes';
        btn.className = 'btn btn-primary';
    }

    document.getElementById('actionModal').classList.add('active');
}

function closeModal(){
    document.getElementById('actionModal').classList.remove('active');
}

window.onclick = e => { if(e.target.id === 'actionModal') closeModal(); };
</script>

</body>
</html>
