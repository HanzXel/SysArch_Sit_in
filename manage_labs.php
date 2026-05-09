<?php
session_start();
if(!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true){
    header("Location: login.php"); exit;
}
include 'Database/connect.php';

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
if($conn->query("SELECT COUNT(*) as c FROM labs")->fetch_assoc()['c'] == 0){
    foreach([['524','Laboratory 524',5,8],['526','Laboratory 526',5,8],
             ['528','Laboratory 528',5,8],['530','Laboratory 530',5,8],
             ['542','Laboratory 542',5,8],['544','Laboratory 544',5,8]] as $l){
        $conn->query("INSERT IGNORE INTO labs (lab_name,description,`rows`,`cols`) VALUES ('$l[0]','$l[1]',$l[2],$l[3])");
        $lid=$conn->insert_id;
        if($lid){ $sn=1; for($r=1;$r<=$l[2];$r++) for($c=1;$c<=$l[3];$c++){
            $conn->query("INSERT IGNORE INTO seats (lab_id,seat_number,row_pos,col_pos) VALUES ($lid,$sn,$r,$c)"); $sn++;
        }}
    }
}
// Auto-seed seats for labs missing them
foreach($conn->query("SELECT * FROM labs")->fetch_all(MYSQLI_ASSOC) as $lab){
    if($conn->query("SELECT COUNT(*) as c FROM seats WHERE lab_id={$lab['id']}")->fetch_assoc()['c']==0){
        $sn=1; for($r=1;$r<=$lab['rows'];$r++) for($c=1;$c<=$lab['cols'];$c++){
            $conn->query("INSERT IGNORE INTO seats (lab_id,seat_number,row_pos,col_pos) VALUES ({$lab['id']},$sn,$r,$c)"); $sn++;
        }
    }
}

// Actions
if(isset($_GET['toggle_seat'])){
    $conn->query("UPDATE seats SET is_active=1-is_active WHERE id=".intval($_GET['toggle_seat']));
    header("Location: manage_labs.php?lab=".intval($_GET['lab'])."&toggled=1"); exit;
}
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_lab'])){
    $ln=trim($_POST['lab_name']); $d=trim($_POST['description']);
    $r=max(1,min(20,intval($_POST['rows']))); $c=max(1,min(20,intval($_POST['cols'])));
    $s=$conn->prepare("INSERT INTO labs (lab_name,description,`rows`,`cols`) VALUES (?,?,?,?)");
    $s->bind_param("ssii",$ln,$d,$r,$c); $s->execute();
    $lid=$conn->insert_id; $sn=1;
    for($ri=1;$ri<=$r;$ri++) for($ci=1;$ci<=$c;$ci++){
        $conn->query("INSERT INTO seats (lab_id,seat_number,row_pos,col_pos) VALUES ($lid,$sn,$ri,$ci)"); $sn++;
    }
    header("Location: manage_labs.php?added=1"); exit;
}
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_lab'])){
    $lid=intval($_POST['lab_id']); $d=trim($_POST['description']);
    $r=max(1,min(20,intval($_POST['rows']))); $c=max(1,min(20,intval($_POST['cols'])));
    $ia=isset($_POST['is_active'])?1:0;
    $s=$conn->prepare("UPDATE labs SET description=?,`rows`=?,`cols`=?,is_active=? WHERE id=?");
    $s->bind_param("siiii",$d,$r,$c,$ia,$lid); $s->execute();
    $conn->query("DELETE FROM seats WHERE lab_id=$lid AND (row_pos>$r OR col_pos>$c)");
    $mx=$conn->query("SELECT IFNULL(MAX(seat_number),0) as m FROM seats WHERE lab_id=$lid")->fetch_assoc()['m'];
    $sn=$mx+1;
    for($ri=1;$ri<=$r;$ri++) for($ci=1;$ci<=$c;$ci++){
        if(!$conn->query("SELECT id FROM seats WHERE lab_id=$lid AND row_pos=$ri AND col_pos=$ci")->num_rows){
            $conn->query("INSERT INTO seats (lab_id,seat_number,row_pos,col_pos) VALUES ($lid,$sn,$ri,$ci)"); $sn++;
        }
    }
    header("Location: manage_labs.php?updated=1"); exit;
}
if(isset($_GET['delete_lab'])){
    $d=intval($_GET['delete_lab']);
    $conn->query("DELETE FROM seats WHERE lab_id=$d");
    $conn->query("DELETE FROM labs WHERE id=$d");
    header("Location: manage_labs.php?deleted=1"); exit;
}
if(isset($_GET['toggle_lab'])){
    $conn->query("UPDATE labs SET is_active=1-is_active WHERE id=".intval($_GET['toggle_lab']));
    header("Location: manage_labs.php?toggled=1"); exit;
}

$all_labs = $conn->query("SELECT * FROM labs ORDER BY lab_name")->fetch_all(MYSQLI_ASSOC);
$selected_lab_id = intval($_GET['lab'] ?? ($all_labs[0]['id'] ?? 0));
$selected_lab = null;
foreach($all_labs as $l){ if($l['id']==$selected_lab_id){ $selected_lab=$l; break; } }

// Fetch seats WITH active sit-in student info
$seats = [];
if($selected_lab){
    $lab_name_esc = mysqli_real_escape_string($conn, $selected_lab['lab_name']);
    $sr = $conn->query("
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
            ON si.lab='$lab_name_esc'
            AND si.seat_number=s.seat_number
            AND si.sit_in_date=CURDATE()
            AND si.time_out IS NULL
        WHERE s.lab_id=$selected_lab_id
        ORDER BY s.row_pos, s.col_pos
    ");
    $seats = $sr->fetch_all(MYSQLI_ASSOC);
}

$total_labs   = count($all_labs);
$active_labs  = array_sum(array_column($all_labs,'is_active'));
$total_seats  = $conn->query("SELECT COUNT(*) as c FROM seats")->fetch_assoc()['c'];
$active_seats = $conn->query("SELECT COUNT(*) as c FROM seats WHERE is_active=1")->fetch_assoc()['c'];
$occupied_now = count(array_filter($seats, fn($s)=>!empty($s['sitin_id'])));
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
.legend-available{background:#22c55e;} .legend-occupied{background:#3b82f6;}
.legend-maintenance{background:#ef4444;} .legend-reserved{background:#f59e0b;}
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
/* Tooltip */
.seat-tooltip{display:none;position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%);background:var(--navy);color:#fff;font-size:11px;font-weight:500;padding:8px 12px;border-radius:8px;white-space:nowrap;z-index:99;box-shadow:0 4px 16px rgba(10,22,40,.3);line-height:1.6;pointer-events:none;min-width:160px;text-align:left;}
.seat-tooltip::after{content:'';position:absolute;top:100%;left:50%;transform:translateX(-50%);border:5px solid transparent;border-top-color:var(--navy);}
.seat-cell:hover .seat-tooltip{display:block;}
.teacher-desk{background:linear-gradient(135deg,var(--navy),var(--navy-mid));color:#fff;border-radius:10px;padding:8px 28px;font-size:12px;font-weight:700;letter-spacing:.05em;text-align:center;margin:0 auto 18px;display:block;width:fit-content;}
.grid-wrapper{overflow-x:auto;padding-bottom:8px;}
.seat-stats-bar{margin-top:16px;display:flex;gap:18px;flex-wrap:wrap;font-size:13px;color:var(--gray-500);}
.seat-stats-bar strong{color:var(--navy);}
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

    <?php if(isset($_GET['added'])): ?><div class="alert alert-success">✓ Laboratory added.</div><?php endif; ?>
    <?php if(isset($_GET['updated'])): ?><div class="alert alert-success">✓ Laboratory updated.</div><?php endif; ?>
    <?php if(isset($_GET['deleted'])): ?><div class="alert alert-danger">✓ Laboratory removed.</div><?php endif; ?>
    <?php if(isset($_GET['toggled'])): ?><div class="alert alert-success">✓ Status updated.</div><?php endif; ?>

    <div class="mini-stats">
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $total_labs;?></div><div class="mini-stat-label">Total Labs</div></div>
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $active_labs;?></div><div class="mini-stat-label">Active Labs</div></div>
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $total_seats;?></div><div class="mini-stat-label">Total Seats</div></div>
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $active_seats;?></div><div class="mini-stat-label">Active Seats</div></div>
    </div>

    <div class="lab-grid">
        <?php foreach($all_labs as $lab): ?>
        <div class="lab-card <?php echo $lab['id']==$selected_lab_id?'selected':''; ?> <?php echo !$lab['is_active']?'inactive':''; ?>"
             onclick="window.location='manage_labs.php?lab=<?php echo $lab['id'];?>'">
            <div style="position:absolute;top:12px;right:12px;">
                <span class="badge <?php echo $lab['is_active']?'badge-success':'badge-danger';?>" style="font-size:11px;"><?php echo $lab['is_active']?'Active':'Inactive';?></span>
            </div>
            <div class="lab-card-name">Lab <?php echo htmlspecialchars($lab['lab_name']);?></div>
            <div class="lab-card-desc"><?php echo htmlspecialchars($lab['description']?:'—');?></div>
            <div class="lab-card-meta">
                <span class="badge badge-blue"><?php echo $lab['rows'];?>×<?php echo $lab['cols'];?></span>
                <span class="badge badge-gray"><?php echo $lab['rows']*$lab['cols'];?> seats</span>
            </div>
            <div class="lab-card-actions" onclick="event.stopPropagation()">
                <button class="btn btn-primary btn-sm" onclick="openEditLab(<?php echo $lab['id'];?>,'<?php echo htmlspecialchars(addslashes($lab['description']));?>',<?php echo $lab['rows'];?>,<?php echo $lab['cols'];?>,<?php echo $lab['is_active'];?>)">Edit</button>
                <a href="manage_labs.php?toggle_lab=<?php echo $lab['id'];?>" class="btn btn-gray btn-sm"><?php echo $lab['is_active']?'Disable':'Enable';?></a>
                <a href="manage_labs.php?delete_lab=<?php echo $lab['id'];?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete Lab <?php echo $lab['lab_name'];?>?')">Del</a>
            </div>
        </div>
        <?php endforeach;?>
    </div>

    <?php if($selected_lab): ?>
    <div class="seat-section">
        <div class="seat-header">
            <div class="seat-header-left">
                <span style="font-size:14px;font-weight:600;">Seat Layout — Lab <?php echo htmlspecialchars($selected_lab['lab_name']);?></span>
            </div>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <?php if($occupied_now>0):?>
                <span class="badge badge-blue" style="font-size:12px;padding:5px 12px;">🟢 <?php echo $occupied_now;?> active now</span>
                <?php endif;?>
                <span style="font-size:12px;opacity:.7;">Click available/maintenance seat to toggle</span>
            </div>
        </div>
        <div class="seat-body">
            <div class="seat-legend">
                <span><span class="legend-dot legend-available"></span>Available</span>
                <span><span class="legend-dot legend-occupied"></span>Occupied — hover to see student</span>
                <span><span class="legend-dot legend-reserved"></span>Reserved today</span>
                <span><span class="legend-dot legend-maintenance"></span>Maintenance</span>
            </div>
            <div class="grid-wrapper">
                <div class="teacher-desk">🖥️ Teacher's Desk / Projector</div>
                <?php
                $rows_map=[];
                foreach($seats as $seat) $rows_map[$seat['row_pos']][$seat['col_pos']]=$seat;
                ksort($rows_map);
                $cols_count=(int)$selected_lab['cols'];
                $half=(int)ceil($cols_count/2);
                ?>
                <div class="seat-rows">
                <?php foreach($rows_map as $rnum=>$row_seats): ksort($row_seats); ?>
                <div class="seat-row">
                    <?php for($c=1;$c<=$cols_count;$c++):
                        if($c===$half+1): ?><div class="aisle-gap"></div><?php endif;
                        $seat=$row_seats[$c]??null;
                        if(!$seat): ?><div style="width:58px;height:58px;flex-shrink:0;"></div><?php continue; endif;
                        $is_occ  = !empty($seat['sitin_id']);
                        $is_res  = intval($seat['is_reserved'])>0;
                        $is_maint= intval($seat['is_active'])===0;
                        if($is_occ)        { $cls='occupied';    $icon='👤'; }
                        elseif($is_maint)  { $cls='maintenance'; $icon='🔧'; }
                        elseif($is_res)    { $cls='reserved';    $icon='📅'; }
                        else               { $cls='available';   $icon='🖥️'; }
                        $can_toggle = !$is_occ && !$is_res;
                        $toggle_url = "manage_labs.php?toggle_seat={$seat['id']}&lab=$selected_lab_id";
                    ?>
                    <?php if($can_toggle): ?>
                    <a href="<?php echo $toggle_url;?>" class="seat-cell <?php echo $cls;?>"
                       onclick="return confirm('Toggle seat <?php echo $seat['seat_number'];?>?')">
                    <?php else: ?>
                    <div class="seat-cell <?php echo $cls;?>">
                    <?php endif;?>
                        <?php if($is_occ):?><span class="occupied-pulse"></span><?php endif;?>
                        <span class="seat-icon"><?php echo $icon;?></span>
                        <span class="seat-num"><?php echo $seat['seat_number'];?></span>
                        <?php if($is_occ):?>
                        <div class="seat-tooltip">
                            <strong><?php echo htmlspecialchars($seat['active_name']);?></strong><br>
                            ID: <?php echo htmlspecialchars($seat['active_idnum']);?><br>
                            Since: <?php echo date('h:i A',strtotime($seat['active_since']));?>
                        </div>
                        <?php elseif($is_maint):?>
                        <div class="seat-tooltip">Seat <?php echo $seat['seat_number'];?><br>Under Maintenance<br>Click to restore</div>
                        <?php elseif($is_res):?>
                        <div class="seat-tooltip">Seat <?php echo $seat['seat_number'];?><br>Reserved today</div>
                        <?php else:?>
                        <div class="seat-tooltip">Seat <?php echo $seat['seat_number'];?><br>Click to set maintenance</div>
                        <?php endif;?>
                    <?php echo $can_toggle?'</a>':'</div>';?>
                    <?php endfor;?>
                </div>
                <?php endforeach;?>
                </div>
            </div>
            <div class="seat-stats-bar">
                <?php
                $cnt_avail = count(array_filter($seats,fn($s)=>$s['is_active']&&!$s['sitin_id']&&!$s['is_reserved']));
                $cnt_res   = count(array_filter($seats,fn($s)=>$s['is_reserved']));
                $cnt_maint = count(array_filter($seats,fn($s)=>!$s['is_active']));
                ?>
                <span>Total: <strong><?php echo count($seats);?></strong></span>
                <span>Available: <strong style="color:#15803d"><?php echo $cnt_avail;?></strong></span>
                <span>Occupied: <strong style="color:#1d4ed8"><?php echo $occupied_now;?></strong></span>
                <span>Reserved: <strong style="color:#92400e"><?php echo $cnt_res;?></strong></span>
                <span>Maintenance: <strong style="color:#b91c1c"><?php echo $cnt_maint;?></strong></span>
            </div>
        </div>
    </div>
    <?php endif;?>
</div>

<!-- Add Lab Modal -->
<div class="modal-overlay" id="addLabModal">
    <div class="modal-box">
        <div class="modal-header"><h2>Add New Laboratory</h2><button class="close-btn" onclick="document.getElementById('addLabModal').classList.remove('active')">&times;</button></div>
        <div class="modal-body">
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group full"><label class="form-label">Lab Name <span style="color:#ef4444">*</span></label><input type="text" name="lab_name" class="form-input" placeholder="e.g. 532" required></div>
                    <div class="form-group full"><label class="form-label">Description</label><input type="text" name="description" class="form-input" placeholder="e.g. Laboratory 532"></div>
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
                <input type="hidden" name="lab_id" id="edit_lab_id">
                <div class="form-grid">
                    <div class="form-group full"><label class="form-label">Description</label><input type="text" name="description" id="edit_lab_desc" class="form-input"></div>
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
function openEditLab(id,desc,rows,cols,active){
    document.getElementById('edit_lab_id').value=id;
    document.getElementById('edit_lab_desc').value=desc;
    document.getElementById('edit_lab_rows').value=rows;
    document.getElementById('edit_lab_cols').value=cols;
    document.getElementById('edit_lab_active').checked=active==1;
    document.getElementById('editLabModal').classList.add('active');
}
window.onclick=e=>{['addLabModal','editLabModal'].forEach(id=>{if(e.target.id===id)document.getElementById(id).classList.remove('active');});};
// Auto-refresh every 30 seconds to keep student occupancy live
setTimeout(()=>location.reload(),30000);
</script>
</body>
</html>
