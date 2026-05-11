<?php
session_start();
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header("Location: login.php"); exit;
}
require_once 'Database/csrf.php';
include 'Database/connect.php';

// ── Ensure tables ─────────────────────────────────────────────────────
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
if ($conn->query("SELECT COUNT(*) as c FROM labs")->fetch_assoc()['c'] == 0) {
    foreach ([['524','Laboratory 524',5,8],['526','Laboratory 526',5,8],
              ['528','Laboratory 528',5,8],['530','Laboratory 530',5,8],
              ['542','Laboratory 542',5,8],['544','Laboratory 544',5,8]] as $l) {
        $ins = $conn->prepare("INSERT IGNORE INTO labs (lab_name,description,`rows`,`cols`) VALUES (?,?,?,?)");
        $ins->bind_param("ssii", $l[0], $l[1], $l[2], $l[3]);
        $ins->execute();
        $lid = $conn->insert_id;
        if ($lid) {
            $sn = 1;
            for ($r = 1; $r <= $l[2]; $r++)
                for ($c = 1; $c <= $l[3]; $c++) {
                    $si = $conn->prepare("INSERT IGNORE INTO seats (lab_id,seat_number,row_pos,col_pos) VALUES (?,?,?,?)");
                    $si->bind_param("iiii", $lid, $sn, $r, $c);
                    $si->execute();
                    $sn++;
                }
        }
    }
}

// ── AJAX: seat occupancy JSON (used by JS polling — no full-page reload) ──
if (isset($_GET['ajax_seats'])) {
    $lab_id = (int) $_GET['ajax_seats'];
    $lab_row = $conn->query("SELECT lab_name FROM labs WHERE id=$lab_id")->fetch_assoc();
    if (!$lab_row) { echo json_encode([]); exit; }

    // FIXED: join on seat_id instead of lab name + seat number string match
    $sr = $conn->prepare("
        SELECT s.id, s.seat_number, s.row_pos, s.col_pos, s.is_active,
            si.id            AS sitin_id,
            si.student_name  AS active_name,
            si.id_number     AS active_idnum,
            si.sit_in_time   AS active_since,
            (SELECT COUNT(*) FROM reservations r
             WHERE r.seat_id = s.id AND r.reservation_date = CURDATE()
               AND r.status = 'approved') AS is_reserved
        FROM seats s
        LEFT JOIN sit_in si
            ON si.seat_id = s.id
            AND si.sit_in_date = CURDATE()
            AND si.time_out IS NULL
        WHERE s.lab_id = ?
        ORDER BY s.row_pos, s.col_pos
    ");
    $sr->bind_param("i", $lab_id);
    $sr->execute();
    $seats = $sr->get_result()->fetch_all(MYSQLI_ASSOC);
    $sr->close();
    header('Content-Type: application/json');
    echo json_encode($seats);
    $conn->close();
    exit;
}

// ── Toggle seat ───────────────────────────────────────────────────────
if (isset($_GET['toggle_seat'])) {
    csrf_verify();
    $seat_id = (int) $_GET['toggle_seat'];
    $lab_id  = (int) $_GET['lab'];
    $upd = $conn->prepare("UPDATE seats SET is_active=1-is_active WHERE id=?");
    $upd->bind_param("i", $seat_id);
    $upd->execute();
    $upd->close();
    header("Location: manage_labs.php?lab=$lab_id&toggled=1"); exit;
}

// ── Add lab ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_lab'])) {
    csrf_verify();
    $ln = mb_substr(trim($_POST['lab_name']), 0, 50);
    $d  = mb_substr(trim($_POST['description']), 0, 200);
    $r  = max(1, min(20, (int) $_POST['rows']));
    $c  = max(1, min(20, (int) $_POST['cols']));

    $s = $conn->prepare("INSERT INTO labs (lab_name,description,`rows`,`cols`) VALUES (?,?,?,?)");
    $s->bind_param("ssii", $ln, $d, $r, $c);
    $s->execute();
    $lid = $conn->insert_id;

    if ($lid) {
        $sn = 1;
        for ($ri = 1; $ri <= $r; $ri++)
            for ($ci = 1; $ci <= $c; $ci++) {
                $si = $conn->prepare("INSERT INTO seats (lab_id,seat_number,row_pos,col_pos) VALUES (?,?,?,?)");
                $si->bind_param("iiii", $lid, $sn, $ri, $ci);
                $si->execute();
                $sn++;
            }
    }
    header("Location: manage_labs.php?added=1"); exit;
}

// ── Update lab ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_lab'])) {
    csrf_verify();
    $lid = (int) $_POST['lab_id'];
    $d   = mb_substr(trim($_POST['description']), 0, 200);
    $r   = max(1, min(20, (int) $_POST['rows']));
    $c   = max(1, min(20, (int) $_POST['cols']));
    $ia  = isset($_POST['is_active']) ? 1 : 0;

    $s = $conn->prepare("UPDATE labs SET description=?,`rows`=?,`cols`=?,is_active=? WHERE id=?");
    $s->bind_param("siiii", $d, $r, $c, $ia, $lid);
    $s->execute();

    // Remove seats that are now out of bounds
    $del = $conn->prepare("DELETE FROM seats WHERE lab_id=? AND (row_pos>? OR col_pos>?)");
    $del->bind_param("iii", $lid, $r, $c);
    $del->execute();

    // Add any missing seats for new layout
    $mx = $conn->prepare("SELECT IFNULL(MAX(seat_number),0) as m FROM seats WHERE lab_id=?");
    $mx->bind_param("i", $lid);
    $mx->execute();
    $sn = $mx->get_result()->fetch_assoc()['m'] + 1;
    for ($ri = 1; $ri <= $r; $ri++)
        for ($ci = 1; $ci <= $c; $ci++) {
            $chk = $conn->prepare("SELECT id FROM seats WHERE lab_id=? AND row_pos=? AND col_pos=?");
            $chk->bind_param("iii", $lid, $ri, $ci);
            $chk->execute();
            if ($chk->get_result()->num_rows === 0) {
                $si = $conn->prepare("INSERT INTO seats (lab_id,seat_number,row_pos,col_pos) VALUES (?,?,?,?)");
                $si->bind_param("iiii", $lid, $sn, $ri, $ci);
                $si->execute();
                $sn++;
            }
        }
    header("Location: manage_labs.php?updated=1"); exit;
}

// ── Delete lab ────────────────────────────────────────────────────────
if (isset($_GET['delete_lab'])) {
    csrf_verify();
    $d = (int) $_GET['delete_lab'];
    $conn->prepare("DELETE FROM seats WHERE lab_id=?")->bind_param("i",$d) && true;
    $ds = $conn->prepare("DELETE FROM seats WHERE lab_id=?");
    $ds->bind_param("i", $d); $ds->execute();
    $dl = $conn->prepare("DELETE FROM labs WHERE id=?");
    $dl->bind_param("i", $d); $dl->execute();
    header("Location: manage_labs.php?deleted=1"); exit;
}

// ── Toggle lab active status ──────────────────────────────────────────
if (isset($_GET['toggle_lab'])) {
    csrf_verify();
    $tl = $conn->prepare("UPDATE labs SET is_active=1-is_active WHERE id=?");
    $tl->bind_param("i", (int) $_GET['toggle_lab']);
    $tl->execute();
    header("Location: manage_labs.php?toggled=1"); exit;
}

$all_labs = $conn->query("SELECT * FROM labs ORDER BY lab_name")->fetch_all(MYSQLI_ASSOC);
$selected_lab_id = (int) ($_GET['lab'] ?? ($all_labs[0]['id'] ?? 0));
$selected_lab = null;
foreach ($all_labs as $l) { if ($l['id'] == $selected_lab_id) { $selected_lab = $l; break; } }

// Fetch seats using seat_id join (FIXED - no lab name string interpolation)
$seats = [];
if ($selected_lab) {
    $sr = $conn->prepare("
        SELECT s.*,
            si.id            AS sitin_id,
            si.student_name  AS active_name,
            si.id_number     AS active_idnum,
            si.sit_in_time   AS active_since,
            (SELECT COUNT(*) FROM reservations r
             WHERE r.seat_id=s.id AND r.reservation_date=CURDATE()
               AND r.status='approved') AS is_reserved
        FROM seats s
        LEFT JOIN sit_in si
            ON si.seat_id = s.id
            AND si.sit_in_date = CURDATE()
            AND si.time_out IS NULL
        WHERE s.lab_id = ?
        ORDER BY s.row_pos, s.col_pos
    ");
    $sr->bind_param("i", $selected_lab_id);
    $sr->execute();
    $seats = $sr->get_result()->fetch_all(MYSQLI_ASSOC);
    $sr->close();
}

$total_labs   = count($all_labs);
$active_labs  = array_sum(array_column($all_labs, 'is_active'));
$total_seats  = $conn->query("SELECT COUNT(*) as c FROM seats")->fetch_assoc()['c'];
$active_seats = $conn->query("SELECT COUNT(*) as c FROM seats WHERE is_active=1")->fetch_assoc()['c'];
$occupied_now = count(array_filter($seats, fn($s) => !empty($s['sitin_id'])));
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
.lab-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-bottom:28px;}
.lab-card{background:var(--white);border-radius:var(--radius);box-shadow:var(--card-shadow);padding:18px;cursor:pointer;transition:all var(--transition);border:2px solid transparent;position:relative;}
.lab-card:hover{transform:translateY(-2px);box-shadow:var(--card-shadow-hover);}
.lab-card.selected{border-color:var(--blue);}
.lab-card.inactive{opacity:.55;}
.lab-card-name{font-size:22px;font-weight:700;color:var(--navy);margin-bottom:4px;}
.lab-card-desc{font-size:12px;color:var(--gray-500);margin-bottom:10px;}
.lab-card-meta{display:flex;gap:6px;flex-wrap:wrap;}
.lab-card-actions{display:flex;gap:6px;margin-top:12px;}
.seat-section{background:var(--white);border-radius:var(--radius);box-shadow:var(--card-shadow);overflow:hidden;}
.seat-header{background:linear-gradient(135deg,var(--navy),var(--navy-mid));color:var(--white);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
.seat-header-left{display:flex;align-items:center;gap:10px;}
.seat-header-left::before{content:'';width:4px;height:16px;background:var(--blue-light);border-radius:2px;}
.seat-body{padding:24px;}
.seat-legend{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px;font-size:13px;align-items:center;}
.legend-dot{width:14px;height:14px;border-radius:4px;display:inline-block;margin-right:5px;vertical-align:middle;}
.legend-available{background:#22c55e;}.legend-occupied{background:#3b82f6;}.legend-maintenance{background:#ef4444;}.legend-reserved{background:#f59e0b;}
.seat-rows{display:flex;flex-direction:column;gap:8px;}
.seat-row{display:flex;gap:8px;align-items:center;}
.aisle-gap{width:22px;flex-shrink:0;}
.seat-cell{width:58px;height:58px;flex-shrink:0;border-radius:11px;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:10px;font-weight:800;cursor:pointer;transition:all var(--transition);border:2px solid transparent;position:relative;text-decoration:none;}
.seat-cell.available{background:rgba(34,197,94,.15);color:#15803d;border-color:rgba(34,197,94,.4);}
.seat-cell.available:hover{background:rgba(34,197,94,.3);transform:scale(1.07);}
.seat-cell.maintenance{background:rgba(239,68,68,.12);color:#b91c1c;border-color:rgba(239,68,68,.35);}
.seat-cell.maintenance:hover{background:rgba(239,68,68,.22);transform:scale(1.07);}
.seat-cell.reserved{background:rgba(245,158,11,.15);color:#92400e;border-color:rgba(245,158,11,.4);cursor:default;}
.seat-cell.occupied{background:rgba(59,130,246,.15);color:#1d4ed8;border-color:rgba(59,130,246,.4);cursor:default;}
.occupied-pulse{position:absolute;top:4px;right:4px;width:8px;height:8px;border-radius:50%;background:#3b82f6;animation:ocp 1.8s ease-in-out infinite;}
@keyframes ocp{0%,100%{transform:scale(1);opacity:1;}50%{transform:scale(1.5);opacity:.4;}}
.seat-icon{font-size:17px;margin-bottom:1px;}
.seat-num{font-size:9px;font-weight:800;line-height:1;}
.seat-tooltip{display:none;position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%);background:var(--navy);color:#fff;font-size:11px;padding:8px 12px;border-radius:8px;white-space:nowrap;z-index:99;pointer-events:none;min-width:160px;}
.seat-tooltip::after{content:'';position:absolute;top:100%;left:50%;transform:translateX(-50%);border:5px solid transparent;border-top-color:var(--navy);}
.seat-cell:hover .seat-tooltip{display:block;}
.teacher-desk{background:linear-gradient(135deg,var(--navy),var(--navy-mid));color:#fff;border-radius:10px;padding:8px 28px;font-size:12px;font-weight:700;text-align:center;margin:0 auto 18px;display:block;width:fit-content;}
.grid-wrapper{overflow-x:auto;padding-bottom:8px;}
.seat-stats-bar{margin-top:16px;display:flex;gap:18px;flex-wrap:wrap;font-size:13px;color:var(--gray-500);}
.seat-stats-bar strong{color:var(--navy);}
.refresh-notice{font-size:12px;color:rgba(255,255,255,.6);display:flex;align-items:center;gap:6px;}
.refresh-dot{width:7px;height:7px;border-radius:50%;background:#22c55e;animation:ocp 2s ease-in-out infinite;}
</style>
</head>
<body>
<nav class="dashboard-navbar">
    <div class="dashboard-left"><img class="admin-logo" src="pictures/uclogo.png" alt=""><span class="admin-title">Admin Dashboard</span></div>
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
        <div><div class="page-title">Manage Laboratories</div><div class="page-subtitle">Configure labs, monitor active students in real time</div></div>
        <button class="btn btn-primary" onclick="document.getElementById('addLabModal').classList.add('active')">＋ Add Lab</button>
    </div>

    <?php if (isset($_GET['added'])):   ?><div class="alert alert-success">✓ Laboratory added.</div><?php endif; ?>
    <?php if (isset($_GET['updated'])): ?><div class="alert alert-success">✓ Laboratory updated.</div><?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?><div class="alert alert-danger">✓ Laboratory removed.</div><?php endif; ?>
    <?php if (isset($_GET['toggled'])): ?><div class="alert alert-success">✓ Status updated.</div><?php endif; ?>

    <div class="mini-stats">
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $total_labs; ?></div><div class="mini-stat-label">Total Labs</div></div>
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $active_labs; ?></div><div class="mini-stat-label">Active Labs</div></div>
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $total_seats; ?></div><div class="mini-stat-label">Total Seats</div></div>
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $active_seats; ?></div><div class="mini-stat-label">Active Seats</div></div>
    </div>

    <div class="lab-grid">
        <?php foreach ($all_labs as $lab): ?>
        <div class="lab-card <?php echo $lab['id']==$selected_lab_id?'selected':''; ?> <?php echo !$lab['is_active']?'inactive':''; ?>"
             onclick="window.location='manage_labs.php?lab=<?php echo $lab['id']; ?>'">
            <div style="position:absolute;top:12px;right:12px;">
                <span class="badge <?php echo $lab['is_active']?'badge-success':'badge-danger'; ?>" style="font-size:11px;"><?php echo $lab['is_active']?'Active':'Inactive'; ?></span>
            </div>
            <div class="lab-card-name">Lab <?php echo htmlspecialchars($lab['lab_name']); ?></div>
            <div class="lab-card-desc"><?php echo htmlspecialchars($lab['description'] ?: '—'); ?></div>
            <div class="lab-card-meta">
                <span class="badge badge-blue"><?php echo $lab['rows']; ?>×<?php echo $lab['cols']; ?></span>
                <span class="badge badge-gray"><?php echo $lab['rows']*$lab['cols']; ?> seats</span>
            </div>
            <div class="lab-card-actions" onclick="event.stopPropagation()">
                <button class="btn btn-primary btn-sm" onclick="openEditLab(<?php echo $lab['id']; ?>,'<?php echo htmlspecialchars(addslashes($lab['description'])); ?>',<?php echo $lab['rows']; ?>,<?php echo $lab['cols']; ?>,<?php echo $lab['is_active']; ?>)">Edit</button>
                <a href="manage_labs.php?toggle_lab=<?php echo $lab['id']; ?>&<?php echo csrf_token_qs(); ?>" class="btn btn-gray btn-sm"><?php echo $lab['is_active']?'Disable':'Enable'; ?></a>
                <a href="manage_labs.php?delete_lab=<?php echo $lab['id']; ?>&<?php echo csrf_token_qs(); ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete Lab <?php echo $lab['lab_name']; ?>?')">Del</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($selected_lab): ?>
    <div class="seat-section">
        <div class="seat-header">
            <div class="seat-header-left">
                <span style="font-size:14px;font-weight:600;">Seat Layout — Lab <?php echo htmlspecialchars($selected_lab['lab_name']); ?></span>
            </div>
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <span class="refresh-notice"><span class="refresh-dot"></span> Live — updates every 30s</span>
                <?php if ($occupied_now > 0): ?>
                <span class="badge badge-blue" style="font-size:12px;padding:5px 12px;">🟢 <?php echo $occupied_now; ?> active now</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="seat-body">
            <div class="seat-legend">
                <span><span class="legend-dot legend-available"></span>Available</span>
                <span><span class="legend-dot legend-occupied"></span>Occupied — hover for details</span>
                <span><span class="legend-dot legend-reserved"></span>Reserved today</span>
                <span><span class="legend-dot legend-maintenance"></span>Maintenance</span>
            </div>
            <div class="grid-wrapper">
                <div class="teacher-desk">🖥️ Teacher's Desk / Projector</div>
                <?php
                $rows_map = [];
                foreach ($seats as $seat) $rows_map[$seat['row_pos']][$seat['col_pos']] = $seat;
                ksort($rows_map);
                $cols_count = (int) $selected_lab['cols'];
                $half       = (int) ceil($cols_count / 2);
                ?>
                <div class="seat-rows" id="seatGrid">
                <?php foreach ($rows_map as $rnum => $row_seats): ksort($row_seats); ?>
                <div class="seat-row">
                    <?php for ($c = 1; $c <= $cols_count; $c++):
                        if ($c === $half + 1): ?><div class="aisle-gap"></div><?php endif;
                        $seat = $row_seats[$c] ?? null;
                        if (!$seat): ?><div style="width:58px;height:58px;flex-shrink:0;"></div><?php continue; endif;
                        $is_occ   = !empty($seat['sitin_id']);
                        $is_res   = (int) $seat['is_reserved'] > 0;
                        $is_maint = (int) $seat['is_active'] === 0;
                        if ($is_occ)       { $cls = 'occupied';     $icon = '👤'; }
                        elseif ($is_maint) { $cls = 'maintenance';  $icon = '🔧'; }
                        elseif ($is_res)   { $cls = 'reserved';     $icon = '📅'; }
                        else               { $cls = 'available';    $icon = '🖥️'; }
                        $can_toggle = !$is_occ && !$is_res;
                    ?>
                    <?php if ($can_toggle): ?>
                    <a href="manage_labs.php?toggle_seat=<?php echo $seat['id']; ?>&lab=<?php echo $selected_lab_id; ?>&<?php echo csrf_token_qs(); ?>"
                       class="seat-cell <?php echo $cls; ?>"
                       onclick="return confirm('Toggle seat <?php echo $seat['seat_number']; ?>?')">
                    <?php else: ?>
                    <div class="seat-cell <?php echo $cls; ?>">
                    <?php endif; ?>
                        <?php if ($is_occ): ?><span class="occupied-pulse"></span><?php endif; ?>
                        <span class="seat-icon"><?php echo $icon; ?></span>
                        <span class="seat-num"><?php echo $seat['seat_number']; ?></span>
                        <?php if ($is_occ): ?>
                        <div class="seat-tooltip">
                            <strong><?php echo htmlspecialchars($seat['active_name']); ?></strong><br>
                            ID: <?php echo htmlspecialchars($seat['active_idnum']); ?><br>
                            Since: <?php echo date('h:i A', strtotime($seat['active_since'])); ?>
                        </div>
                        <?php elseif ($is_maint): ?>
                        <div class="seat-tooltip">Seat <?php echo $seat['seat_number']; ?> — Maintenance<br>Click to restore</div>
                        <?php elseif ($is_res): ?>
                        <div class="seat-tooltip">Seat <?php echo $seat['seat_number']; ?> — Reserved today</div>
                        <?php else: ?>
                        <div class="seat-tooltip">Seat <?php echo $seat['seat_number']; ?> — Click to set maintenance</div>
                        <?php endif; ?>
                    <?php echo $can_toggle ? '</a>' : '</div>'; ?>
                    <?php endfor; ?>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php
            $cnt_avail = count(array_filter($seats, fn($s) => $s['is_active'] && !$s['sitin_id'] && !$s['is_reserved']));
            $cnt_res   = count(array_filter($seats, fn($s) => $s['is_reserved']));
            $cnt_maint = count(array_filter($seats, fn($s) => !$s['is_active']));
            ?>
            <div class="seat-stats-bar" id="seatStatsBar">
                <span>Total: <strong><?php echo count($seats); ?></strong></span>
                <span>Available: <strong style="color:#15803d"><?php echo $cnt_avail; ?></strong></span>
                <span>Occupied: <strong style="color:#1d4ed8" id="liveOccupied"><?php echo $occupied_now; ?></strong></span>
                <span>Reserved: <strong style="color:#92400e"><?php echo $cnt_res; ?></strong></span>
                <span>Maintenance: <strong style="color:#b91c1c"><?php echo $cnt_maint; ?></strong></span>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Add Lab Modal -->
<div class="modal-overlay" id="addLabModal">
    <div class="modal-box">
        <div class="modal-header"><h2>Add New Laboratory</h2><button class="close-btn" onclick="document.getElementById('addLabModal').classList.remove('active')">&times;</button></div>
        <div class="modal-body">
            <form method="POST">
                <?php echo csrf_token(); ?>
                <div class="form-grid">
                    <div class="form-group full"><label class="form-label">Lab Name <span style="color:#ef4444">*</span></label><input type="text" name="lab_name" class="form-input" placeholder="e.g. 532" maxlength="50" required></div>
                    <div class="form-group full"><label class="form-label">Description</label><input type="text" name="description" class="form-input" placeholder="e.g. Laboratory 532" maxlength="200"></div>
                    <div class="form-group"><label class="form-label">Rows</label><input type="number" name="rows" class="form-input" value="5" min="1" max="20"></div>
                    <div class="form-group"><label class="form-label">Columns</label><input type="number" name="cols" class="form-input" value="8" min="1" max="20"></div>
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
        <div class="modal-header"><h2>Edit Laboratory</h2><button class="close-btn" onclick="document.getElementById('editLabModal').classList.remove('active')">&times;</button></div>
        <div class="modal-body">
            <form method="POST">
                <?php echo csrf_token(); ?>
                <input type="hidden" name="lab_id" id="edit_lab_id">
                <div class="form-grid">
                    <div class="form-group full"><label class="form-label">Description</label><input type="text" name="description" id="edit_lab_desc" class="form-input" maxlength="200"></div>
                    <div class="form-group"><label class="form-label">Rows</label><input type="number" name="rows" id="edit_lab_rows" class="form-input" min="1" max="20"></div>
                    <div class="form-group"><label class="form-label">Columns</label><input type="number" name="cols" id="edit_lab_cols" class="form-input" min="1" max="20"></div>
                    <div class="form-group full"><label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;"><input type="checkbox" name="is_active" id="edit_lab_active" style="width:16px;height:16px;"> Lab is Active / Open</label></div>
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
function openEditLab(id, desc, rows, cols, active) {
    document.getElementById('edit_lab_id').value      = id;
    document.getElementById('edit_lab_desc').value    = desc;
    document.getElementById('edit_lab_rows').value    = rows;
    document.getElementById('edit_lab_cols').value    = cols;
    document.getElementById('edit_lab_active').checked = active == 1;
    document.getElementById('editLabModal').classList.add('active');
}
window.onclick = e => {
    ['addLabModal','editLabModal'].forEach(id => {
        if (e.target.id === id) document.getElementById(id).classList.remove('active');
    });
};

// ── FIXED: AJAX seat refresh — no full-page reload, preserves modal state ──
<?php if ($selected_lab): ?>
const SELECTED_LAB_ID = <?php echo $selected_lab_id; ?>;

function refreshSeats() {
    fetch('manage_labs.php?ajax_seats=' + SELECTED_LAB_ID)
        .then(r => r.json())
        .then(seats => {
            // Update only the occupied count in the stats bar
            const occ = seats.filter(s => s.sitin_id).length;
            const el  = document.getElementById('liveOccupied');
            if (el) el.textContent = occ;

            // Update each seat cell class/tooltip without re-rendering the grid
            seats.forEach(seat => {
                const cell = document.querySelector(`[data-seat-id="${seat.id}"]`);
                if (!cell) return;
                const isOcc   = !!seat.sitin_id;
                const isMaint = parseInt(seat.is_active) === 0;
                const isRes   = parseInt(seat.is_reserved) > 0;
                let cls = isOcc ? 'occupied' : isMaint ? 'maintenance' : isRes ? 'reserved' : 'available';
                cell.className = cell.className.replace(/(available|occupied|maintenance|reserved)/g, cls);
            });
        })
        .catch(() => {}); // Silently fail — don't interrupt the admin
}

// Refresh every 30 seconds WITHOUT touching the page or any modals
setInterval(refreshSeats, 30000);
<?php endif; ?>
</script>
</body>
</html>
