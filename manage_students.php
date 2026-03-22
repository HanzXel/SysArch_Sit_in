<?php
session_start();
if(!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true){
    header("Location: login.php"); exit;
}
include 'Database/connect.php';

// Handle delete
if(isset($_GET['delete'])){
    $del_id = intval($_GET['delete']);
    $s = $conn->prepare("DELETE FROM students WHERE id = ?");
    $s->bind_param("i", $del_id);
    $s->execute();
    $s->close();
    header("Location: manage_students.php?deleted=1"); exit;
}

// Handle session update
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_sessions'])){
    $upd_id  = intval($_POST['student_id']);
    $new_ses = intval($_POST['sessions']);
    $s = $conn->prepare("UPDATE students SET sessions = ? WHERE id = ?");
    $s->bind_param("ii", $new_ses, $upd_id);
    $s->execute();
    $s->close();
    header("Location: manage_students.php?updated=1"); exit;
}

// Search / filter values
$search   = isset($_GET['search']) ? trim($_GET['search']) : '';
$course_f = isset($_GET['course']) ? trim($_GET['course']) : '';
$year_f   = isset($_GET['year'])   ? trim($_GET['year'])   : '';

// Build WHERE clause and params
$conditions = [];
$params = [];
$types  = '';

if($search !== ''){
    $like = '%' . $search . '%';
    $conditions[] = "(first_name LIKE ? OR last_name LIKE ? OR id_number LIKE ? OR email LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'ssss';
}
if($course_f !== ''){
    $conditions[] = "course = ?";
    $params[] = $course_f;
    $types .= 's';
}
if($year_f !== ''){
    $conditions[] = "year_level = ?";
    $params[] = $year_f;
    $types .= 's';
}

$where = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Count total matching rows
$count_sql = "SELECT COUNT(*) as c FROM students $where";
$cs = $conn->prepare($count_sql);
if(!empty($params)){
    $cs->bind_param($types, ...$params);
}
$cs->execute();
$total = $cs->get_result()->fetch_assoc()['c'];
$cs->close();

// Pagination
$per_page = 15;
$page     = max(1, intval($_GET['p'] ?? 1));
$offset   = ($page - 1) * $per_page;
$total_pages = max(1, ceil($total / $per_page));

// Fetch students
$data_sql    = "SELECT * FROM students $where ORDER BY created_at DESC LIMIT ? OFFSET ?";
$data_params = array_merge($params, [$per_page, $offset]);
$data_types  = $types . 'ii';
$s = $conn->prepare($data_sql);
$s->bind_param($data_types, ...$data_params);
$s->execute();
$students = $s->get_result()->fetch_all(MYSQLI_ASSOC);
$s->close();

// Distinct courses for filter dropdown
$courses = $conn->query("SELECT DISTINCT course FROM students ORDER BY course")->fetch_all(MYSQLI_ASSOC);

// Stats
$total_students = $conn->query("SELECT COUNT(*) as c FROM students")->fetch_assoc()['c'];
$avg_sessions   = $conn->query("SELECT AVG(sessions) as a FROM students")->fetch_assoc()['a'];
$zero_sessions  = $conn->query("SELECT COUNT(*) as c FROM students WHERE sessions = 0")->fetch_assoc()['c'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Students — Admin</title>
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
        <li><a href="manage_students.php" class="active">Manage Students</a></li>
        <li><a href="sitin_logs.php">Sit-in Logs</a></li>
        <li><a href="reservations.php">Reservations</a></li>
        <li><a href="reports.php">Reports</a></li>
        <li><a href="logout.php" class="logout-btn">Log Out</a></li>
    </ul>
</nav>

<div class="page-container">

    <div class="page-header">
        <div>
            <div class="page-title">Manage Students</div>
            <div class="page-subtitle">View, search and manage all registered students</div>
        </div>
    </div>

    <?php if(isset($_GET['deleted'])): ?>
    <div class="alert alert-danger">✓ Student record deleted successfully.</div>
    <?php endif; ?>
    <?php if(isset($_GET['updated'])): ?>
    <div class="alert alert-success">✓ Sessions updated successfully.</div>
    <?php endif; ?>

    <!-- Mini Stats -->
    <div class="mini-stats">
        <div class="mini-stat">
            <div class="mini-stat-value"><?php echo $total_students; ?></div>
            <div class="mini-stat-label">Total Students</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value"><?php echo round($avg_sessions ?? 0); ?></div>
            <div class="mini-stat-label">Avg. Sessions Left</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value"><?php echo $zero_sessions; ?></div>
            <div class="mini-stat-label">No Sessions Left</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value"><?php echo count($courses); ?></div>
            <div class="mini-stat-label">Courses Enrolled</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-header-left">Student Records</div>
            <span style="font-size:13px;opacity:0.7"><?php echo $total; ?> student<?php echo $total != 1 ? 's' : ''; ?> found</span>
        </div>
        <div class="card-body">

            <!-- Filters -->
            <form method="GET" action="">
                <div class="filter-bar">
                    <input type="text" name="search" class="search-input"
                           placeholder="Search by name, ID, or email..."
                           value="<?php echo htmlspecialchars($search); ?>">

                    <select name="course" class="filter-select">
                        <option value="">All Courses</option>
                        <?php foreach($courses as $c): ?>
                        <option value="<?php echo htmlspecialchars($c['course']); ?>"
                            <?php echo $course_f === $c['course'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['course']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="year" class="filter-select">
                        <option value="">All Years</option>
                        <option value="1st Year" <?php echo $year_f === '1st Year' ? 'selected' : ''; ?>>1st Year</option>
                        <option value="2nd Year" <?php echo $year_f === '2nd Year' ? 'selected' : ''; ?>>2nd Year</option>
                        <option value="3rd Year" <?php echo $year_f === '3rd Year' ? 'selected' : ''; ?>>3rd Year</option>
                        <option value="4th Year" <?php echo $year_f === '4th Year' ? 'selected' : ''; ?>>4th Year</option>
                    </select>

                    <button type="submit" class="btn btn-primary">Search</button>
                    <a href="manage_students.php" class="btn btn-gray">Reset</a>
                </div>
            </form>

            <?php if(empty($students)): ?>
            <div class="empty-state">
                <div class="empty-icon">👤</div>
                <div class="empty-title">No students found</div>
                <div class="empty-desc">Try adjusting your search or filter.</div>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>ID Number</th>
                            <th>Name</th>
                            <th>Course</th>
                            <th>Year</th>
                            <th>Email</th>
                            <th>Sessions</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($students as $i => $st): ?>
                        <tr>
                            <td><?php echo $offset + $i + 1; ?></td>
                            <td class="td-bold"><?php echo htmlspecialchars($st['id_number']); ?></td>
                            <td class="td-bold"><?php echo htmlspecialchars($st['first_name'] . ' ' . $st['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($st['course']); ?></td>
                            <td><?php echo htmlspecialchars($st['year_level']); ?></td>
                            <td><?php echo htmlspecialchars($st['email']); ?></td>
                            <td>
                                <?php $ses = $st['sessions'] ?? 0; ?>
                                <span class="badge <?php echo $ses > 10 ? 'badge-success' : ($ses > 0 ? 'badge-warning' : 'badge-danger'); ?>">
                                    <?php echo $ses; ?>
                                </span>
                            </td>
                            <td>
                                <div style="display:flex;gap:6px;flex-wrap:wrap">
                                    <button class="btn btn-primary btn-sm"
                                        onclick="openEditSessions(
                                            <?php echo $st['id']; ?>,
                                            '<?php echo htmlspecialchars(addslashes($st['first_name'] . ' ' . $st['last_name'])); ?>',
                                            <?php echo $ses; ?>
                                        )">
                                        Edit Sessions
                                    </button>
                                    <a href="manage_students.php?delete=<?php echo $st['id']; ?>"
                                       class="btn btn-danger btn-sm"
                                       onclick="return confirm('Delete this student? This cannot be undone.')">
                                        Delete
                                    </a>
                                </div>
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
                <a href="?p=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&course=<?php echo urlencode($course_f); ?>&year=<?php echo urlencode($year_f); ?>"
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

<!-- Edit Sessions Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Edit Sessions</h2>
            <button class="close-btn" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="">
                <input type="hidden" name="student_id" id="edit_student_id">
                <p style="font-size:14px;color:var(--gray-500);margin-bottom:18px;">
                    Updating sessions for: <strong id="edit_student_name" style="color:var(--navy)"></strong>
                </p>
                <div class="form-group">
                    <label class="form-label">Number of Sessions</label>
                    <input type="number" name="sessions" id="edit_sessions"
                           min="0" max="100" class="form-input" required>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal()">Cancel</button>
                    <button type="submit" name="update_sessions" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditSessions(id, name, sessions){
    document.getElementById('edit_student_id').value = id;
    document.getElementById('edit_student_name').textContent = name;
    document.getElementById('edit_sessions').value = sessions;
    document.getElementById('editModal').classList.add('active');
}
function closeModal(){
    document.getElementById('editModal').classList.remove('active');
}
window.onclick = e => { if(e.target.id === 'editModal') closeModal(); };
</script>

</body>
</html>
