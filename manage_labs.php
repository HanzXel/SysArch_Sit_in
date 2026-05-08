<?php
session_start();
if(!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true){
    header("Location: login.php"); exit;
}
include 'Database/connect.php';

// ── Ensure tables exist ──────────────────────────────────────────────
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

// Seed default labs if empty
$labCount = $conn->query("SELECT COUNT(*) as c FROM labs")->fetch_assoc()['c'];
if($labCount == 0){
    $defaultLabs = [
        ['524','Laboratory 524',5,8],
        ['526','Laboratory 526',5,8],
        ['528','Laboratory 528',5,8],
        ['530','Laboratory 530',5,8],
        ['542','Laboratory 542',5,8],
        ['544','Laboratory 544',5,8],
    ];
    foreach($defaultLabs as $l){
        $conn->query("INSERT IGNORE INTO labs (lab_name,description,`rows`,`cols`) VALUES ('$l[0]','$l[1]',$l[2],$l[3])");
    }
}

// Auto-seed seats for labs that have none
$labs_res = $conn->query("SELECT * FROM labs ORDER BY lab_name");
$all_labs = $labs_res->fetch_all(MYSQLI_ASSOC);
foreach($all_labs as $lab){
    $sc = $conn->query("SELECT COUNT(*) as c FROM seats WHERE lab_id={$lab['id']}")->fetch_assoc()['c'];
    if($sc == 0){
        $seatNum = 1;
        for($r=1; $r<=$lab['rows']; $r++){
            for($c=1; $c<=$lab['cols']; $c++){
                $conn->query("INSERT IGNORE INTO seats (lab_id,seat_number,row_pos,col_pos) VALUES ({$lab['id']},$seatNum,$r,$c)");
                $seatNum++;
            }
        }
    }
}

$msg = '';
$msg_type = 'success';

// ── Handle: Toggle seat active/inactive ─────────────────────────────
if(isset($_GET['toggle_seat'])){
    $seat_id = intval($_GET['toggle_seat']);
    $conn->query("UPDATE seats SET is_active = 1 - is_active WHERE id = $seat_id");
    header("Location: manage_labs.php?lab=" . intval($_GET['lab']) . "&toggled=1"); exit;
}

// ── Handle: Add new lab ─────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_lab'])){
    $lab_name   = trim($_POST['lab_name']);
    $desc       = trim($_POST['description']);
    $rows       = max(1, min(20, intval($_POST['rows'])));
    $cols       = max(1, min(20, intval($_POST['cols'])));
    $stmt = $conn->prepare("INSERT INTO labs (lab_name,description,`rows`,`cols`) VALUES (?,?,?,?)");
    $stmt->bind_param("ssii", $lab_name, $desc, $rows, $cols);
    if($stmt->execute()){
        $new_lab_id = $conn->insert_id;
        $seatNum = 1;
        for($r=1;$r<=$rows;$r++) for($c=1;$c<=$cols;$c++){
            $conn->query("INSERT INTO seats (lab_id,seat_number,row_pos,col_pos) VALUES ($new_lab_id,$seatNum,$r,$c)");
            $seatNum++;
        }
        $msg = "Laboratory {$lab_name} added successfully with " . ($rows*$cols) . " seats.";
    } else {
        $msg = "Error: Lab name already exists."; $msg_type='danger';
    }
    header("Location: manage_labs.php?added=1"); exit;
}

// ── Handle: Update lab config ────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_lab'])){
    $lab_id   = intval($_POST['lab_id']);
    $desc     = trim($_POST['description']);
    $rows     = max(1, min(20, intval($_POST['rows'])));
    $cols     = max(1, min(20, intval($_POST['cols'])));
    $is_active= isset($_POST['is_active']) ? 1 : 0;
    $stmt = $conn->prepare("UPDATE labs SET description=?,`rows`=?,`cols`=?,is_active=? WHERE id=?");
    $stmt->bind_param("siiii", $desc,$rows,$cols,$is_active,$lab_id);
    $stmt->execute();
    // Rebuild seats if layout changed
    $old = $conn->query("SELECT `rows`,`cols` FROM labs WHERE id=$lab_id")->fetch_assoc();
    // Delete seats beyond new grid, add missing ones
    $conn->query("DELETE FROM seats WHERE lab_id=$lab_id AND (row_pos > $rows OR col_pos > $cols)");
    $maxSeat = $conn->query("SELECT IFNULL(MAX(seat_number),0) as m FROM seats WHERE lab_id=$lab_id")->fetch_assoc()['m'];
    $seatNum = $maxSeat + 1;
    for($r=1;$r<=$rows;$r++) for($c=1;$c<=$cols;$c++){
        $exists = $conn->query("SELECT id FROM seats WHERE lab_id=$lab_id AND row_pos=$r AND col_pos=$c")->num_rows;
        if(!$exists){
            $conn->query("INSERT INTO seats (lab_id,seat_number,row_pos,col_pos) VALUES ($lab_id,$seatNum,$r,$c)");
            $seatNum++;
        }
    }
    header("Location: manage_labs.php?updated=1"); exit;
}

// ── Handle: Delete lab ───────────────────────────────────────────────
if(isset($_GET['delete_lab'])){
    $del_id = intval($_GET['delete_lab']);
    $conn->query("DELETE FROM seats WHERE lab_id=$del_id");
    $conn->query("DELETE FROM labs WHERE id=$del_id");
    header("Location: manage_labs.php?deleted=1"); exit;
}

// ── Handle: Toggle lab active ────────────────────────────────────────
if(isset($_GET['toggle_lab'])){
    $tid = intval($_GET['toggle_lab']);
    $conn->query("UPDATE labs SET is_active = 1-is_active WHERE id=$tid");
    header("Location: manage_labs.php?toggled=1"); exit;
}

// Reload labs
$labs_res = $conn->query("SELECT * FROM labs ORDER BY lab_name");
$all_labs = $labs_res->fetch_all(MYSQLI_ASSOC);

// Which lab to show seat layout for
$selected_lab_id = intval($_GET['lab'] ?? ($all_labs[0]['id'] ?? 0));
$selected_lab = null;
$seats = [];
foreach($all_labs as $l){ if($l['id']==$selected_lab_id){ $selected_lab=$l; break; } }

if($selected_lab){
    $sr = $conn->query("SELECT s.*, 
        (SELECT COUNT(*) FROM reservations r 
         WHERE r.seat_id=s.id AND r.reservation_date=CURDATE() AND r.status='approved') as is_reserved
        FROM seats s WHERE lab_id=$selected_lab_id ORDER BY row_pos,col_pos");
    $seats = $sr->fetch_all(MYSQLI_ASSOC);
}

// Stats
$total_labs   = count($all_labs);
$active_labs  = array_sum(array_column($all_labs,'is_active'));
$total_seats  = $conn->query("SELECT COUNT(*) as c FROM seats")->fetch_assoc()['c'];
$active_seats = $conn->query("SELECT COUNT(*) as c FROM seats WHERE is_active=1")->fetch_assoc()['c'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Labs — Admin</title>
<link rel="stylesheet" href="admin_shared.css">
<link rel="icon" type="image/png" href="pictures/uclogo.png">
<style>
/* ── Lab cards ── */
.lab-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-bottom:28px;}
.lab-card{background:var(--white);border-radius:var(--radius);box-shadow:var(--card-shadow);padding:18px;cursor:pointer;transition:all var(--transition);border:2px solid transparent;position:relative;}
.lab-card:hover{transform:translateY(-2px);box-shadow:var(--card-shadow-hover);}
.lab-card.selected{border-color:var(--blue);}
.lab-card.inactive{opacity:.55;}
.lab-card-name{font-size:22px;font-weight:700;color:var(--navy);margin-bottom:4px;}
.lab-card-desc{font-size:12px;color:var(--gray-500);margin-bottom:10px;}
.lab-card-meta{display:flex;gap:6px;flex-wrap:wrap;}
.lab-card-actions{display:flex;gap:6px;margin-top:12px;}

/* ── Seat grid ── */
.seat-section{background:var(--white);border-radius:var(--radius);box-shadow:var(--card-shadow);overflow:hidden;}
.seat-header{background:linear-gradient(135deg,var(--navy),var(--navy-mid));color:var(--white);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;}
.seat-header-left{display:flex;align-items:center;gap:10px;}
.seat-header-left::before{content:'';width:4px;height:16px;background:var(--blue-light);border-radius:2px;}
.seat-body{padding:24px;}

.seat-legend{display:flex;gap:18px;flex-wrap:wrap;margin-bottom:20px;font-size:13px;}
.legend-dot{width:14px;height:14px;border-radius:4px;display:inline-block;margin-right:5px;vertical-align:middle;}
.legend-available{background:#22c55e;}
.legend-maintenance{background:#ef4444;}

.seat-grid{display:inline-grid;gap:8px;margin:0 auto;}
.seat-cell{width:52px;height:52px;border-radius:10px;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:11px;font-weight:700;cursor:pointer;transition:all var(--transition);border:2px solid transparent;position:relative;}
.seat-cell.available{background:rgba(34,197,94,.15);color:#15803d;border-color:rgba(34,197,94,.4);}
.seat-cell.available:hover{background:rgba(34,197,94,.3);transform:scale(1.08);}
.seat-cell.maintenance{background:rgba(239,68,68,.12);color:#b91c1c;border-color:rgba(239,68,68,.35);}
.seat-cell.maintenance:hover{background:rgba(239,68,68,.22);transform:scale(1.08);}
.seat-cell.reserved{background:rgba(245,158,11,.15);color:#92400e;border-color:rgba(245,158,11,.4);cursor:default;}
.seat-icon{font-size:16px;margin-bottom:1px;}
.seat-num{font-size:10px;font-weight:800;}

.teacher-desk{background:linear-gradient(135deg,var(--navy),var(--navy-mid));color:var(--white);border-radius:10px;padding:8px 24px;font-size:12px;font-weight:700;letter-spacing:.05em;text-align:center;margin:0 auto 18px;display:block;width:fit-content;}

.aisle{width:20px;}
.grid-wrapper{overflow-x:auto;padding-bottom:8px;}
</style>
</head>
<body>

<nav class="dashboard-navbar">
    <div class="dashboard-left">
        <img class="admin-logo" src="pictures/uclogo.png" alt="">
        <span class="admin-title">Admin Dashboard</span>
    </div>
    <ul class="dashboard-right">
        <li><a href="admin_dashboard.php">Dashboard</a></li>
        <li><a href="manage_students.php">Manage Students</a></li>
        <li><a href="sitin_logs.php">Sit-in Logs</a></li>
        <li><a href="manage_labs.php" class="active">Manage Labs</a></li>
        <li><a href="manage_software.php">Manage Software</a></li>
        <li><a href="reservations.php">Reservations</a></li>
        <li><a href="reports.php">Reports</a></li>
        <li><a href="student_feedback.php">Feedback</a></li>
        <li><a href="logout.php" class="logout-btn">Log Out</a></li>
    </ul>
</nav>

<div class="page-container">

    <div class="page-header">
        <div>
            <div class="page-title">Manage Laboratories</div>
            <div class="page-subtitle">Configure labs, seat layouts and availability</div>
        </div>
        <button class="btn btn-primary" onclick="document.getElementById('addLabModal').classList.add('active')">＋ Add Lab</button>
    </div>

    <?php if(isset($_GET['added'])): ?><div class="alert alert-success">✓ Laboratory added successfully.</div><?php endif; ?>
    <?php if(isset($_GET['updated'])): ?><div class="alert alert-success">✓ Laboratory updated.</div><?php endif; ?>
    <?php if(isset($_GET['deleted'])): ?><div class="alert alert-danger">✓ Laboratory removed.</div><?php endif; ?>
    <?php if(isset($_GET['toggled'])): ?><div class="alert alert-success">✓ Status updated.</div><?php endif; ?>

    <!-- Stats -->
    <div class="mini-stats">
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $total_labs; ?></div><div class="mini-stat-label">Total Labs</div></div>
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $active_labs; ?></div><div class="mini-stat-label">Active Labs</div></div>
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $total_seats; ?></div><div class="mini-stat-label">Total Seats</div></div>
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $active_seats; ?></div><div class="mini-stat-label">Active Seats</div></div>
    </div>

    <!-- Lab selector cards -->
    <div class="lab-grid">
        <?php foreach($all_labs as $lab):
            $seat_count = 0; $active_s = 0;
            // Quick count (already closed conn, use what we have)
        ?>
        <div class="lab-card <?php echo $lab['id']==$selected_lab_id?'selected':''; ?> <?php echo !$lab['is_active']?'inactive':''; ?>"
             onclick="window.location='manage_labs.php?lab=<?php echo $lab['id']; ?>'">
            <div style="position:absolute;top:12px;right:12px;">
                <?php if($lab['is_active']): ?>
                <span class="badge badge-success" style="font-size:11px;">Active</span>
                <?php else: ?>
                <span class="badge badge-danger" style="font-size:11px;">Inactive</span>
                <?php endif; ?>
            </div>
            <div class="lab-card-name">Lab <?php echo htmlspecialchars($lab['lab_name']); ?></div>
            <div class="lab-card-desc"><?php echo htmlspecialchars($lab['description'] ?: '—'); ?></div>
            <div class="lab-card-meta">
                <span class="badge badge-blue"><?php echo $lab['rows']; ?>×<?php echo $lab['cols']; ?> grid</span>
                <span class="badge badge-gray"><?php echo $lab['rows']*$lab['cols']; ?> seats</span>
            </div>
            <div class="lab-card-actions" onclick="event.stopPropagation()">
                <button class="btn btn-primary btn-sm"
                    onclick="openEditLab(<?php echo $lab['id']; ?>,'<?php echo htmlspecialchars(addslashes($lab['description'])); ?>',<?php echo $lab['rows']; ?>,<?php echo $lab['cols']; ?>,<?php echo $lab['is_active']; ?>)">
                    Edit
                </button>
                <a href="manage_labs.php?toggle_lab=<?php echo $lab['id']; ?>" class="btn btn-gray btn-sm">
                    <?php echo $lab['is_active']?'Disable':'Enable'; ?>
                </a>
                <a href="manage_labs.php?delete_lab=<?php echo $lab['id']; ?>" class="btn btn-danger btn-sm"
                   onclick="return confirm('Delete Lab <?php echo $lab['lab_name']; ?>? All seats will be removed.')">Del</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Seat Layout -->
    <?php if($selected_lab): ?>
    <div class="seat-section">
        <div class="seat-header">
            <div class="seat-header-left">
                <span style="font-size:14px;font-weight:600;">Seat Layout — Lab <?php echo htmlspecialchars($selected_lab['lab_name']); ?></span>
            </div>
            <span style="font-size:13px;opacity:.7">Click a seat to toggle availability</span>
        </div>
        <div class="seat-body">

            <div class="seat-legend">
                <span><span class="legend-dot legend-available"></span> Available</span>
                <span><span class="legend-dot legend-maintenance"></span> Under Maintenance</span>
                <span><span class="legend-dot" style="background:#f59e0b;"></span> Reserved Today</span>
            </div>

            <div class="grid-wrapper">
                <div class="teacher-desk">🖥️ Teacher's Desk / Projector</div>

                <?php
                // Group seats by row
                $rows_map = [];
                foreach($seats as $seat){
                    $rows_map[$seat['row_pos']][$seat['col_pos']] = $seat;
                }
                ksort($rows_map);
                $cols_count = $selected_lab['cols'];
                $half = ceil($cols_count / 2);
                ?>

                <div class="seat-grid" style="grid-template-columns: repeat(<?php echo $half; ?>, 52px) 20px repeat(<?php echo $cols_count - $half; ?>, 52px);">
                    <?php foreach($rows_map as $rnum => $row_seats):
                        ksort($row_seats);
                        $col_idx = 0;
                        foreach($row_seats as $cnum => $seat):
                            $col_idx++;
                            // Insert aisle in the middle
                            if($col_idx == $half + 1): ?>
                                <div class="aisle"></div>
                            <?php endif;
                            $cls = 'available';
                            $icon = '🖥️';
                            if($seat['is_reserved']){ $cls='reserved'; $icon='👤'; }
                            elseif(!$seat['is_active']){ $cls='maintenance'; $icon='🔧'; }
                        ?>
                        <a href="manage_labs.php?toggle_seat=<?php echo $seat['id']; ?>&lab=<?php echo $selected_lab_id; ?>"
                           class="seat-cell <?php echo $cls; ?>"
                           <?php if($seat['is_reserved']): ?>onclick="return false;" title="Reserved today"<?php else: ?>
                           onclick="return confirm('Toggle seat <?php echo $seat['seat_number']; ?>?')"
                           title="<?php echo $seat['is_active']?'Click to set maintenance':'Click to restore'; ?>"
                           <?php endif; ?>>
                            <span class="seat-icon"><?php echo $icon; ?></span>
                            <span class="seat-num"><?php echo $seat['seat_number']; ?></span>
                        </a>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="margin-top:18px;font-size:13px;color:var(--gray-500);">
                Total seats: <strong><?php echo count($seats); ?></strong> &nbsp;|&nbsp;
                Active: <strong style="color:#15803d"><?php echo array_sum(array_column($seats,'is_active')); ?></strong> &nbsp;|&nbsp;
                Maintenance: <strong style="color:#b91c1c"><?php echo count($seats)-array_sum(array_column($seats,'is_active')); ?></strong>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Add Lab Modal -->
<div class="modal-overlay" id="addLabModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Add New Laboratory</h2>
            <button class="close-btn" onclick="document.getElementById('addLabModal').classList.remove('active')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group full">
                        <label class="form-label">Lab Name / Number <span style="color:#ef4444">*</span></label>
                        <input type="text" name="lab_name" class="form-input" placeholder="e.g. 532" required>
                    </div>
                    <div class="form-group full">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-input" placeholder="e.g. Laboratory 532">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Rows <span style="color:var(--gray-300)">(1–20)</span></label>
                        <input type="number" name="rows" class="form-input" value="5" min="1" max="20">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Columns <span style="color:var(--gray-300)">(1–20)</span></label>
                        <input type="number" name="cols" class="form-input" value="8" min="1" max="20">
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="document.getElementById('addLabModal').classList.remove('active')">Cancel</button>
                    <button type="submit" name="add_lab" class="btn btn-primary">Add Laboratory</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Lab Modal -->
<div class="modal-overlay" id="editLabModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Edit Laboratory</h2>
            <button class="close-btn" onclick="document.getElementById('editLabModal').classList.remove('active')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="lab_id" id="edit_lab_id">
                <div class="form-grid">
                    <div class="form-group full">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" id="edit_lab_desc" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Rows</label>
                        <input type="number" name="rows" id="edit_lab_rows" class="form-input" min="1" max="20">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Columns</label>
                        <input type="number" name="cols" id="edit_lab_cols" class="form-input" min="1" max="20">
                    </div>
                    <div class="form-group full">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;">
                            <input type="checkbox" name="is_active" id="edit_lab_active" style="width:16px;height:16px;">
                            Lab is Active / Open
                        </label>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="document.getElementById('editLabModal').classList.remove('active')">Cancel</button>
                    <button type="submit" name="update_lab" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditLab(id, desc, rows, cols, active){
    document.getElementById('edit_lab_id').value   = id;
    document.getElementById('edit_lab_desc').value  = desc;
    document.getElementById('edit_lab_rows').value  = rows;
    document.getElementById('edit_lab_cols').value  = cols;
    document.getElementById('edit_lab_active').checked = active==1;
    document.getElementById('editLabModal').classList.add('active');
}
window.onclick = e => {
    ['addLabModal','editLabModal'].forEach(id=>{
        if(e.target.id===id) document.getElementById(id).classList.remove('active');
    });
};
</script>
</body>
</html>