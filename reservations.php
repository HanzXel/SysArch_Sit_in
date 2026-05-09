<?php
session_start();
if(!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true){
    header("Location: login.php"); exit;
}
include 'Database/connect.php';

$conn->query("CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_number VARCHAR(50) NOT NULL,
    student_name VARCHAR(200) NOT NULL,
    purpose VARCHAR(100) NOT NULL,
    lab VARCHAR(50) NOT NULL,
    reservation_date DATE NOT NULL,
    reservation_time TIME NOT NULL,
    status ENUM('pending','approved','rejected','expired','converted') DEFAULT 'pending',
    admin_note TEXT DEFAULT NULL,
    seat_id INT DEFAULT NULL,
    seat_number INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("ALTER TABLE reservations ADD COLUMN IF NOT EXISTS seat_id INT DEFAULT NULL");
$conn->query("ALTER TABLE reservations ADD COLUMN IF NOT EXISTS seat_number INT DEFAULT NULL");
// Add 'expired' and 'converted' to status enum safely
$conn->query("ALTER TABLE reservations MODIFY COLUMN status ENUM('pending','approved','rejected','expired','converted') DEFAULT 'pending'");

$conn->query("CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info','success','warning','danger') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS sit_in (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_number VARCHAR(50) NOT NULL,
    student_name VARCHAR(200) NOT NULL,
    purpose VARCHAR(100) NOT NULL,
    lab VARCHAR(50) NOT NULL,
    remaining_session INT NOT NULL,
    sit_in_date DATE NOT NULL,
    sit_in_time TIME NOT NULL,
    time_out TIME DEFAULT NULL,
    time_out_date DATE DEFAULT NULL,
    seat_number INT DEFAULT NULL,
    seat_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("ALTER TABLE sit_in ADD COLUMN IF NOT EXISTS seat_number INT DEFAULT NULL");
$conn->query("ALTER TABLE sit_in ADD COLUMN IF NOT EXISTS seat_id INT DEFAULT NULL");

// ── AUTO-EXPIRE: reservations past 10-min grace window ───────────────
// For approved reservations where date=today and time is more than 10 min ago
$conn->query("
    UPDATE reservations r
    JOIN students s ON s.id_number = r.id_number
    SET r.status = 'expired'
    WHERE r.status = 'approved'
      AND r.reservation_date = CURDATE()
      AND ADDTIME(r.reservation_time, '00:10:00') < CURTIME()
");
// Notify students whose reservation just expired
$expired_notif = $conn->query("
    SELECT r.*, s.id as student_db_id
    FROM reservations r
    JOIN students s ON s.id_number = r.id_number
    WHERE r.status = 'expired'
      AND r.reservation_date = CURDATE()
      AND NOT EXISTS (
          SELECT 1 FROM notifications n
          WHERE n.student_id = s.id
            AND n.title = 'Reservation Expired'
            AND DATE(n.created_at) = CURDATE()
            AND n.message LIKE CONCAT('%Lab ', r.lab, '%')
      )
    LIMIT 20
");
if($expired_notif){
    while($er = $expired_notif->fetch_assoc()){
        $msg = "Your reservation for Lab {$er['lab']} (Seat {$er['seat_number']}) on ".date('M d',strtotime($er['reservation_date']))." at ".date('h:i A',strtotime($er['reservation_time']))." has expired — you did not arrive within 10 minutes. The seat is now available.";
        $sid = $er['student_db_id'];
        $conn->query("INSERT INTO notifications (student_id,title,message,type) VALUES ($sid,'Reservation Expired','".mysqli_real_escape_string($conn,$msg)."','warning')");
    }
}

// ── CONVERT reservation to sit-in ────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['convert_sitin'])){
    $res_id = intval($_POST['res_id']);

    // Fetch reservation
    $r = $conn->prepare("SELECT r.*,s.id as sdb_id,s.sessions FROM reservations r JOIN students s ON s.id_number=r.id_number WHERE r.id=?");
    $r->bind_param("i",$res_id); $r->execute();
    $res = $r->get_result()->fetch_assoc(); $r->close();

    if($res && in_array($res['status'],['approved','pending'])){
        if($res['sessions']<=0){
            $error_msg = "Student has no remaining sessions.";
        } else {
            // Check not already active
            $dup = $conn->prepare("SELECT id FROM sit_in WHERE id_number=? AND sit_in_date=CURDATE() AND time_out IS NULL");
            $dup->bind_param("s",$res['id_number']); $dup->execute();
            if($dup->get_result()->num_rows>0){
                $error_msg = "Student already has an active sit-in today.";
            } else {
                $today = date('Y-m-d'); $now = date('H:i:s');
                $seat_id  = intval($res['seat_id']  ?? 0);
                $seat_num = intval($res['seat_number'] ?? 0);

                $ins = $conn->prepare("INSERT INTO sit_in (id_number,student_name,purpose,lab,remaining_session,sit_in_date,sit_in_time,seat_id,seat_number) VALUES (?,?,?,?,?,?,?,?,?)");
                $ins->bind_param("ssssissii",
                    $res['id_number'],$res['student_name'],$res['purpose'],$res['lab'],
                    $res['sessions'],$today,$now,$seat_id,$seat_num
                );
                $ins->execute(); $ins->close();

                // Mark reservation as converted
                $conn->query("UPDATE reservations SET status='converted' WHERE id=$res_id");

                // Notify student
                $msg = "Your reservation for Lab {$res['lab']} has been converted to an active sit-in session. Seat: {$seat_num}.";
                $sid = $res['sdb_id'];
                $conn->query("INSERT INTO notifications (student_id,title,message,type) VALUES ($sid,'Sit-in Started','".mysqli_real_escape_string($conn,$msg)."','success')");

                header("Location: reservations.php?converted=1"); exit;
            }
            $dup->close();
        }
    }
    header("Location: reservations.php?convert_err=1"); exit;
}

// ── APPROVE / REJECT ──────────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])){
    $res_id = intval($_POST['res_id']);
    $action = $_POST['action'];
    $note   = trim($_POST['admin_note'] ?? '');
    if(in_array($action,['approved','rejected'])){
        $s=$conn->prepare("UPDATE reservations SET status=?,admin_note=? WHERE id=?");
        $s->bind_param("ssi",$action,$note,$res_id); $s->execute(); $s->close();

        $r=$conn->prepare("SELECT r.*,s.id as sdb_id FROM reservations r JOIN students s ON s.id_number=r.id_number WHERE r.id=?");
        $r->bind_param("i",$res_id); $r->execute();
        $row=$r->get_result()->fetch_assoc(); $r->close();
        if($row){
            $sid=$row['sdb_id']; $lab=$row['lab'];
            $dt=date('M d, Y',strtotime($row['reservation_date']));
            $tm=date('h:i A',strtotime($row['reservation_time']));
            $sn=$row['seat_number']??"—";
            if($action==='approved'){
                $t="Reservation Approved"; $ty="success";
                $m="Your reservation for Lab $lab (Seat $sn) on $dt at $tm has been approved.".($note?" Note: $note":"");
            } else {
                $t="Reservation Rejected"; $ty="danger";
                $m="Your reservation for Lab $lab on $dt at $tm was not approved.".($note?" Reason: $note":"");
            }
            $conn->query("INSERT INTO notifications (student_id,title,message,type) VALUES ($sid,'$t','".mysqli_real_escape_string($conn,$m)."','$ty')");
        }
    }
    header("Location: reservations.php?updated=1"); exit;
}

// ── DELETE ────────────────────────────────────────────────────────────
if(isset($_GET['delete'])){
    $conn->query("DELETE FROM reservations WHERE id=".intval($_GET['delete']));
    header("Location: reservations.php?deleted=1"); exit;
}

// ── FILTERS & PAGINATION ──────────────────────────────────────────────
$status_f = trim($_GET['status'] ?? '');
$search   = trim($_GET['search'] ?? '');
$date_f   = trim($_GET['date']   ?? '');
$where="WHERE 1=1"; $params=[]; $types='';
if($status_f){ $where.=" AND status=?"; $params[]=$status_f; $types.='s'; }
if($search){   $like='%'.$search.'%'; $where.=" AND (student_name LIKE ? OR id_number LIKE ?)"; $params[]=$like; $params[]=$like; $types.='ss'; }
if($date_f){   $where.=" AND reservation_date=?"; $params[]=$date_f; $types.='s'; }

$per_page=15; $page=max(1,intval($_GET['p']??1)); $offset=($page-1)*$per_page;

$cs=$conn->prepare("SELECT COUNT(*) as c FROM reservations $where");
if($params){ $cs->bind_param($types,...$params); } $cs->execute();
$total=$cs->get_result()->fetch_assoc()['c']; $cs->close();
$total_pages=ceil($total/$per_page);

$s=$conn->prepare("SELECT * FROM reservations $where ORDER BY reservation_date ASC,reservation_time ASC LIMIT ? OFFSET ?");
$ap=array_merge($params,[$per_page,$offset]); $at=$types.'ii';
if($ap){ $s->bind_param($at,...$ap); } $s->execute();
$reservations=$s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();

// Stats
$pending_c  =$conn->query("SELECT COUNT(*) as c FROM reservations WHERE status='pending'")->fetch_assoc()['c'];
$approved_c =$conn->query("SELECT COUNT(*) as c FROM reservations WHERE status='approved'")->fetch_assoc()['c'];
$rejected_c =$conn->query("SELECT COUNT(*) as c FROM reservations WHERE status='rejected'")->fetch_assoc()['c'];
$expired_c  =$conn->query("SELECT COUNT(*) as c FROM reservations WHERE status='expired'")->fetch_assoc()['c'];
$converted_c=$conn->query("SELECT COUNT(*) as c FROM reservations WHERE status='converted'")->fetch_assoc()['c'];
$today_c    =$conn->query("SELECT COUNT(*) as c FROM reservations WHERE reservation_date=CURDATE()")->fetch_assoc()['c'];
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
<style>
.badge-converted{background:rgba(139,92,246,.1);color:#6d28d9;border:1px solid rgba(139,92,246,.25);padding:4px 10px;border-radius:100px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px;}
.badge-expired{background:rgba(107,114,128,.1);color:#374151;border:1px solid rgba(107,114,128,.25);padding:4px 10px;border-radius:100px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px;}
.btn-convert{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;background:rgba(34,197,94,.1);color:#15803d;border:1px solid rgba(34,197,94,.3);border-radius:var(--radius-sm);font-size:12px;font-weight:600;font-family:Outfit,sans-serif;cursor:pointer;transition:all var(--transition);white-space:nowrap;}
.btn-convert:hover{background:rgba(34,197,94,.2);transform:translateY(-1px);}
.expire-note{font-size:11px;color:var(--gray-300);margin-top:3px;}
</style>
</head>
<body>
<nav class="dashboard-navbar">
    <div class="dashboard-left"><img class="admin-logo" src="pictures/uclogo.png" alt=""><span class="admin-title">Admin Dashboard</span></div>
    <ul class="dashboard-right">
        <li><a href="admin_dashboard.php">Dashboard</a></li>
        <li><a href="manage_students.php">Manage Students</a></li>
        <li><a href="sitin_logs.php">Sit-in Logs</a></li>
        <li><a href="manage_labs.php">Manage Labs</a></li>
        <li><a href="manage_software.php">Manage Software</a></li>
        <li><a href="reservations.php" class="active">Reservations</a></li>
        <li><a href="reports.php">Reports</a></li>
        <li><a href="student_feedback.php">Feedback</a></li>
        <li><a href="logout.php" class="logout-btn">Log Out</a></li>
    </ul>
</nav>

<div class="page-container">
    <div class="page-header">
        <div><div class="page-title">Reservations</div><div class="page-subtitle">Manage lab reservations — convert to sit-in upon student arrival</div></div>
        <?php if($pending_c>0):?><span class="badge badge-warning" style="font-size:14px;padding:8px 16px;">⏳ <?php echo $pending_c;?> pending</span><?php endif;?>
    </div>

    <?php if(isset($_GET['updated'])): ?><div class="alert alert-success">✓ Reservation updated.</div><?php endif;?>
    <?php if(isset($_GET['deleted'])): ?><div class="alert alert-danger">✓ Reservation deleted.</div><?php endif;?>
    <?php if(isset($_GET['converted'])): ?><div class="alert alert-success">✓ Reservation converted to active sit-in session.</div><?php endif;?>
    <?php if(isset($_GET['convert_err'])): ?><div class="alert alert-danger">⚠ Could not convert — check student sessions or duplicate sit-in.</div><?php endif;?>

    <!-- Auto-expire notice -->
    <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:var(--radius-sm);padding:12px 16px;font-size:13px;color:#92400e;margin-bottom:20px;display:flex;align-items:center;gap:10px;">
        ⏱️ <strong>Auto-Expire Policy:</strong> Approved reservations are automatically marked as <em>Expired</em> if the student does not arrive within <strong>10 minutes</strong> of their scheduled time. The seat becomes available again immediately.
    </div>

    <div class="mini-stats">
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $pending_c;?></div><div class="mini-stat-label">Pending</div></div>
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $approved_c;?></div><div class="mini-stat-label">Approved</div></div>
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $converted_c;?></div><div class="mini-stat-label">Converted</div></div>
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $expired_c;?></div><div class="mini-stat-label">Expired</div></div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-header-left">Reservation Requests</div>
            <span style="font-size:13px;opacity:.7;"><?php echo $total;?> total</span>
        </div>
        <div class="card-body">
            <!-- Filters -->
            <form method="GET">
                <div class="filter-bar">
                    <input type="text" name="search" class="search-input" placeholder="Search by name or ID…" value="<?php echo htmlspecialchars($search);?>">
                    <input type="date" name="date" class="filter-select" value="<?php echo htmlspecialchars($date_f);?>">
                    <select name="status" class="filter-select">
                        <option value="">All Status</option>
                        <option value="pending"   <?php echo $status_f==='pending'   ?'selected':'';?>>Pending</option>
                        <option value="approved"  <?php echo $status_f==='approved'  ?'selected':'';?>>Approved</option>
                        <option value="rejected"  <?php echo $status_f==='rejected'  ?'selected':'';?>>Rejected</option>
                        <option value="converted" <?php echo $status_f==='converted' ?'selected':'';?>>Converted</option>
                        <option value="expired"   <?php echo $status_f==='expired'   ?'selected':'';?>>Expired</option>
                    </select>
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="reservations.php" class="btn btn-gray">Reset</a>
                </div>
            </form>

            <?php if(empty($reservations)):?>
            <div class="empty-state"><div class="empty-icon">📅</div><div class="empty-title">No reservations found</div><div class="empty-desc">Student reservations will appear here once submitted.</div></div>
            <?php else:?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>#</th><th>ID Number</th><th>Student</th>
                            <th>Purpose</th><th>Lab</th><th>Seat</th>
                            <th>Date</th><th>Time</th><th>Status</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($reservations as $i=>$r):
                        $badge=[
                            'pending'   =>'badge-warning',
                            'approved'  =>'badge-success',
                            'rejected'  =>'badge-danger',
                            'converted' =>'badge-converted',
                            'expired'   =>'badge-expired',
                        ];
                        $icons=[
                            'pending'=>'⏳','approved'=>'✓','rejected'=>'✕','converted'=>'🟢','expired'=>'⌛'
                        ];

                        // Is this today's approved reservation past grace window?
                        $is_today   = $r['reservation_date'] === date('Y-m-d');
                        $grace_over = $is_today && strtotime($r['reservation_time']) + 600 < time();
                    ?>
                    <tr>
                        <td><?php echo $offset+$i+1;?></td>
                        <td class="td-bold"><?php echo htmlspecialchars($r['id_number']);?></td>
                        <td class="td-bold"><?php echo htmlspecialchars($r['student_name']);?></td>
                        <td><span class="badge badge-blue"><?php echo htmlspecialchars($r['purpose']);?></span></td>
                        <td>Lab <?php echo htmlspecialchars($r['lab']);?></td>
                        <td>
                            <?php if($r['seat_number']):?>
                            <span class="badge badge-gray">Seat <?php echo $r['seat_number'];?></span>
                            <?php else:?>—<?php endif;?>
                        </td>
                        <td><?php echo date('M d, Y',strtotime($r['reservation_date']));?></td>
                        <td>
                            <?php echo date('h:i A',strtotime($r['reservation_time']));?>
                            <?php if($r['status']==='approved' && $is_today && !$grace_over):?>
                            <div class="expire-note">⏱ expires <?php echo date('h:i A',strtotime($r['reservation_time'])+600);?></div>
                            <?php endif;?>
                        </td>
                        <td>
                            <?php $bc=$badge[$r['status']]??'badge-gray'; $ic=$icons[$r['status']]??'?';?>
                            <span class="<?php echo $bc;?>"><?php echo $ic.' '.ucfirst($r['status']);?></span>
                        </td>
                        <td>
                            <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                <?php if(in_array($r['status'],['pending','approved'])):?>
                                    <!-- Convert to sit-in (only for today's reservations) -->
                                    <?php if($is_today && $r['status']==='approved'):?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="res_id" value="<?php echo $r['id'];?>">
                                        <button type="submit" name="convert_sitin" class="btn-convert"
                                            onclick="return confirm('Convert this reservation to an active sit-in for <?php echo htmlspecialchars(addslashes($r['student_name']));?>?')">
                                            🟢 Convert to Sit-in
                                        </button>
                                    </form>
                                    <?php endif;?>
                                    <!-- Approve / Reject -->
                                    <?php if($r['status']==='pending'):?>
                                    <button class="btn btn-success btn-sm" onclick="openAction(<?php echo $r['id'];?>,'approved','<?php echo htmlspecialchars(addslashes($r['student_name']));?>')">Approve</button>
                                    <button class="btn btn-danger btn-sm"  onclick="openAction(<?php echo $r['id'];?>,'rejected','<?php echo htmlspecialchars(addslashes($r['student_name']));?>')">Reject</button>
                                    <?php elseif($r['status']==='approved'):?>
                                    <button class="btn btn-gray btn-sm"    onclick="openAction(<?php echo $r['id'];?>,'rejected','<?php echo htmlspecialchars(addslashes($r['student_name']));?>')">Revoke</button>
                                    <?php endif;?>
                                <?php endif;?>
                                <a href="reservations.php?delete=<?php echo $r['id'];?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete?')">Delete</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach;?>
                    </tbody>
                </table>
            </div>
            <?php if($total_pages>1):?>
            <div class="pagination">
                <?php for($i=1;$i<=$total_pages;$i++):?>
                <a href="?p=<?php echo $i;?>&status=<?php echo urlencode($status_f);?>&search=<?php echo urlencode($search);?>&date=<?php echo urlencode($date_f);?>"
                   class="page-btn <?php echo $page==$i?'active':'';?>"><?php echo $i;?></a>
                <?php endfor;?>
            </div>
            <?php endif;?>
            <?php endif;?>
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
            <form method="POST">
                <input type="hidden" name="res_id"  id="modal_res_id">
                <input type="hidden" name="action"  id="modal_action">
                <p style="font-size:14px;color:var(--gray-500);margin-bottom:16px;">
                    Student: <strong id="modal_student_name" style="color:var(--navy)"></strong>
                </p>
                <div class="form-group">
                    <label class="form-label">Note to Student <span style="font-weight:400;color:var(--gray-300)">(optional)</span></label>
                    <textarea name="admin_note" id="modal_note" class="form-input" rows="3" placeholder="Add a note…"></textarea>
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
function openAction(id,action,name){
    document.getElementById('modal_res_id').value=id;
    document.getElementById('modal_action').value=action;
    document.getElementById('modal_student_name').textContent=name;
    document.getElementById('modal_note').value='';
    const btn=document.getElementById('modal_submit_btn');
    const title=document.getElementById('modal_title');
    if(action==='approved'){title.textContent='Approve Reservation';btn.textContent='✓ Approve';btn.className='btn btn-success';}
    else if(action==='rejected'){title.textContent='Reject Reservation';btn.textContent='✕ Reject';btn.className='btn btn-danger';}
    else{title.textContent='Update Reservation';btn.textContent='Save';btn.className='btn btn-primary';}
    document.getElementById('actionModal').classList.add('active');
}
function closeModal(){ document.getElementById('actionModal').classList.remove('active'); }
window.onclick=e=>{ if(e.target.id==='actionModal') closeModal(); };
</script>
</body>
</html>
