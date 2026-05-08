<?php
session_start();
if(!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true){
    header("Location: login.php"); exit;
}
include 'Database/connect.php';

// ── Ensure tables ────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS labs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lab_name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(200) DEFAULT NULL,
    `rows` INT NOT NULL DEFAULT 5,
    `cols` INT NOT NULL DEFAULT 8,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS software_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    icon VARCHAR(20) DEFAULT '💿',
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS software (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    version VARCHAR(50) DEFAULT NULL,
    description VARCHAR(300) DEFAULT NULL,
    icon VARCHAR(20) DEFAULT '📦',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES software_categories(id) ON DELETE CASCADE
)");
$conn->query("CREATE TABLE IF NOT EXISTS lab_software (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lab_id INT NOT NULL,
    software_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lab_sw (lab_id, software_id),
    FOREIGN KEY (lab_id) REFERENCES labs(id) ON DELETE CASCADE,
    FOREIGN KEY (software_id) REFERENCES software(id) ON DELETE CASCADE
)");

// Seed default categories
if($conn->query("SELECT COUNT(*) as c FROM software_categories")->fetch_assoc()['c'] == 0){
    foreach([['Web Browsers','🌐',1],['Programming IDEs','💻',2],
              ['Database Management','🗄',3],['Office Applications','📄',4],
              ['Media & Design','🎨',5],['Utilities','🔧',6]] as $d){
        $conn->query("INSERT IGNORE INTO software_categories (name,icon,sort_order) VALUES ('{$d[0]}','{$d[1]}',{$d[2]})");
    }
}
// Seed default software pool
if($conn->query("SELECT COUNT(*) as c FROM software")->fetch_assoc()['c'] == 0){
    foreach([
        [1,'Google Chrome','Latest','🌐'],[1,'Microsoft Edge','Latest','🔵'],[1,'Mozilla Firefox','Latest','🦊'],
        [2,'NetBeans IDE','21','🟠'],[2,'Visual Studio Code','Latest','🔷'],[2,'Eclipse IDE','Latest','🟣'],[2,'IntelliJ IDEA','Latest','🧠'],
        [3,'MySQL Workbench','8.0','🐬'],[3,'phpMyAdmin','Latest','🐘'],[3,'DBeaver','Latest','🦦'],
        [4,'Microsoft Word','2021','📘'],[4,'Microsoft Excel','2021','📗'],[4,'Microsoft PowerPoint','2021','📙'],[4,'LibreOffice','Latest','📝'],
    ] as $s){
        $conn->query("INSERT IGNORE INTO software (category_id,name,version,icon) VALUES ({$s[0]},'{$s[1]}','{$s[2]}','{$s[3]}')");
    }
}

// ── Active lab tab ────────────────────────────────────────────────────
$active_lab_id = intval($_GET['lab'] ?? 0);

// ── Actions ──────────────────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_software'])){
    $cat_id = intval($_POST['category_id']);
    $name   = trim($_POST['name']);
    $ver    = trim($_POST['version']);
    $desc   = trim($_POST['description']);
    $icon   = trim($_POST['icon']) ?: '📦';
    $stmt   = $conn->prepare("INSERT INTO software (category_id,name,version,description,icon) VALUES (?,?,?,?,?)");
    $stmt->bind_param("issss",$cat_id,$name,$ver,$desc,$icon);
    $stmt->execute();
    header("Location: manage_software.php?lab=$active_lab_id&added=1"); exit;
}

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_category'])){
    $cname = trim($_POST['cat_name']);
    $cicon = trim($_POST['cat_icon']) ?: '💿';
    $stmt  = $conn->prepare("INSERT INTO software_categories (name,icon) VALUES (?,?)");
    $stmt->bind_param("ss",$cname,$cicon);
    $stmt->execute();
    header("Location: manage_software.php?lab=$active_lab_id&cat_added=1"); exit;
}

if(isset($_GET['assign'])){
    $lid = intval($_GET['lab']); $sid = intval($_GET['sw']);
    $conn->query("INSERT IGNORE INTO lab_software (lab_id,software_id) VALUES ($lid,$sid)");
    header("Location: manage_software.php?lab=$lid&assigned=1"); exit;
}

if(isset($_GET['unassign'])){
    $lid = intval($_GET['lab']); $sid = intval($_GET['sw']);
    $conn->query("DELETE FROM lab_software WHERE lab_id=$lid AND software_id=$sid");
    header("Location: manage_software.php?lab=$lid&unassigned=1"); exit;
}

if(isset($_GET['delete_sw'])){
    $conn->query("DELETE FROM software WHERE id=".intval($_GET['delete_sw']));
    header("Location: manage_software.php?lab=$active_lab_id&deleted=1"); exit;
}

if(isset($_GET['delete_cat'])){
    $did = intval($_GET['delete_cat']);
    $conn->query("DELETE FROM software WHERE category_id=$did");
    $conn->query("DELETE FROM software_categories WHERE id=$did");
    header("Location: manage_software.php?lab=$active_lab_id&cat_deleted=1"); exit;
}

// ── Fetch ─────────────────────────────────────────────────────────────
$labs       = $conn->query("SELECT * FROM labs ORDER BY lab_name")->fetch_all(MYSQLI_ASSOC);
$categories = $conn->query("SELECT * FROM software_categories ORDER BY sort_order,name")->fetch_all(MYSQLI_ASSOC);
$all_sw     = $conn->query("SELECT s.*,c.name as cat_name,c.icon as cat_icon FROM software s JOIN software_categories c ON c.id=s.category_id ORDER BY c.sort_order,s.name")->fetch_all(MYSQLI_ASSOC);

if(!$active_lab_id && !empty($labs)) $active_lab_id = $labs[0]['id'];
$active_lab = null;
foreach($labs as $l){ if($l['id']==$active_lab_id){ $active_lab=$l; break; } }

// Assigned to active lab
$assigned_ids    = [];
$assigned_by_cat = [];
if($active_lab_id){
    $ar = $conn->query("SELECT software_id FROM lab_software WHERE lab_id=$active_lab_id");
    while($row=$ar->fetch_assoc()) $assigned_ids[] = $row['software_id'];

    $ar2 = $conn->query("SELECT s.*,c.name as cat_name,c.icon as cat_icon
        FROM lab_software ls
        JOIN software s ON s.id=ls.software_id
        JOIN software_categories c ON c.id=s.category_id
        WHERE ls.lab_id=$active_lab_id
        ORDER BY c.sort_order,s.name");
    while($row=$ar2->fetch_assoc()) $assigned_by_cat[$row['category_id']][] = $row;
}

// Count assigned per lab for sidebar badges
$lab_sw_counts = [];
$lc = $conn->query("SELECT lab_id, COUNT(*) as c FROM lab_software GROUP BY lab_id");
while($row=$lc->fetch_assoc()) $lab_sw_counts[$row['lab_id']] = $row['c'];

// Group all software by category
$all_by_cat = [];
foreach($all_sw as $sw) $all_by_cat[$sw['category_id']][] = $sw;

$total_sw  = count($all_sw);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Software — Admin</title>
<link rel="stylesheet" href="admin_shared.css">
<link rel="icon" type="image/png" href="pictures/uclogo.png">
<style>
.sw-layout{display:grid;grid-template-columns:230px 1fr;gap:22px;align-items:start;}
@media(max-width:900px){.sw-layout{grid-template-columns:1fr;}}

/* Lab sidebar */
.lab-sidebar{background:var(--white);border-radius:var(--radius);box-shadow:var(--card-shadow);overflow:hidden;position:sticky;top:88px;}
.sidebar-header{background:linear-gradient(135deg,var(--navy),var(--navy-mid));color:var(--white);padding:14px 18px;font-size:13px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;display:flex;align-items:center;gap:8px;}
.sidebar-header::before{content:'';width:4px;height:14px;background:var(--blue-light);border-radius:2px;}
.lab-tab{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--gray-100);cursor:pointer;text-decoration:none;transition:background var(--transition);}
.lab-tab:hover{background:var(--off-white);}
.lab-tab.active{background:rgba(30,111,224,.08);border-left:3px solid var(--blue);}
.lab-tab-name{font-size:14px;font-weight:600;color:var(--navy);}
.lab-tab-count{font-size:11px;font-weight:700;background:var(--gray-100);color:var(--gray-500);padding:2px 7px;border-radius:100px;}
.lab-tab.active .lab-tab-count{background:rgba(30,111,224,.15);color:var(--blue);}

.sw-main{display:flex;flex-direction:column;gap:18px;}

/* Assigned card */
.panel-card{background:var(--white);border-radius:var(--radius);box-shadow:var(--card-shadow);overflow:hidden;}
.panel-head{background:linear-gradient(135deg,var(--navy),var(--navy-mid));color:var(--white);padding:14px 22px;display:flex;align-items:center;justify-content:space-between;}
.panel-head-left{display:flex;align-items:center;gap:10px;font-size:14px;font-weight:600;}
.panel-head-left::before{content:'';width:4px;height:14px;border-radius:2px;}
.panel-head-left.blue::before{background:var(--blue-light);}
.panel-head-left.amber::before{background:#f59e0b;}
.panel-body{padding:20px;}

/* Category sections */
.cat-section{margin-bottom:18px;}
.cat-section:last-child{margin-bottom:0;}
.cat-label{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;color:var(--gray-700);letter-spacing:.05em;text-transform:uppercase;margin-bottom:10px;padding-bottom:8px;border-bottom:1.5px solid var(--gray-100);}
.sw-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:10px;}
.sw-card{background:var(--off-white);border-radius:var(--radius-sm);padding:12px;display:flex;align-items:center;gap:10px;transition:all var(--transition);border:1.5px solid transparent;}
.sw-card:hover{background:var(--white);border-color:rgba(239,68,68,.25);box-shadow:0 4px 12px rgba(10,22,40,.07);}
.sw-icon{font-size:24px;flex-shrink:0;}
.sw-info{flex:1;min-width:0;}
.sw-name{font-size:13px;font-weight:600;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sw-ver{font-size:11px;color:var(--gray-300);margin-top:1px;}
.sw-del{font-size:15px;cursor:pointer;color:var(--gray-300);transition:color var(--transition);flex-shrink:0;text-decoration:none;}
.sw-del:hover{color:#ef4444;}

/* Pool */
.pool-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(195px,1fr));gap:10px;margin-bottom:12px;}
.pool-card-item{background:var(--off-white);border-radius:var(--radius-sm);padding:11px 13px;display:flex;align-items:center;gap:10px;border:1.5px solid transparent;transition:all var(--transition);}
.pool-card-item:hover{background:var(--white);border-color:rgba(30,111,224,.2);}
.pool-card-item.already{opacity:.4;pointer-events:none;}
.pool-info{flex:1;min-width:0;}
.pool-name{font-size:13px;font-weight:600;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.pool-ver{font-size:11px;color:var(--gray-300);}
.btn-add{padding:4px 10px;background:rgba(30,111,224,.1);color:var(--blue);border:1px solid rgba(30,111,224,.25);border-radius:6px;font-size:11px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;text-decoration:none;white-space:nowrap;transition:all var(--transition);}
.btn-add:hover{background:rgba(30,111,224,.2);}
.btn-added{padding:4px 10px;background:rgba(34,197,94,.1);color:#15803d;border:1px solid rgba(34,197,94,.3);border-radius:6px;font-size:11px;font-weight:700;white-space:nowrap;}
.pool-cat-lbl{font-size:12px;font-weight:700;color:var(--gray-500);letter-spacing:.05em;text-transform:uppercase;margin:14px 0 8px;display:flex;align-items:center;gap:6px;}
.pool-cat-lbl:first-child{margin-top:0;}

/* Delete pool chips */
.pool-del-section{margin-top:18px;padding-top:16px;border-top:1px solid var(--gray-100);}
.pool-del-title{font-size:11px;font-weight:700;color:var(--gray-300);letter-spacing:.05em;text-transform:uppercase;margin-bottom:10px;}
.pool-chips{display:flex;flex-wrap:wrap;gap:7px;}
.pool-chip{display:inline-flex;align-items:center;gap:5px;background:var(--off-white);border:1px solid var(--gray-100);border-radius:8px;padding:5px 10px;font-size:12px;}
.pool-chip-name{color:var(--navy);font-weight:500;}
.pool-chip-del{color:var(--gray-300);text-decoration:none;font-size:13px;transition:color var(--transition);}
.pool-chip-del:hover{color:#ef4444;}
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
        <li><a href="manage_labs.php">Manage Labs</a></li>
        <li><a href="manage_software.php" class="active">Manage Software</a></li>
        <li><a href="reservations.php">Reservations</a></li>
        <li><a href="reports.php">Reports</a></li>
        <li><a href="student_feedback.php">Feedback</a></li>
        <li><a href="logout.php" class="logout-btn">Log Out</a></li>
    </ul>
</nav>

<div class="page-container">

    <div class="page-header">
        <div>
            <div class="page-title">Manage Software</div>
            <div class="page-subtitle">Assign software to each laboratory individually</div>
        </div>
        <div style="display:flex;gap:10px;">
            <button class="btn btn-gray" onclick="document.getElementById('addCatModal').classList.add('active')">＋ Add Category</button>
            <button class="btn btn-primary" onclick="document.getElementById('addSwModal').classList.add('active')">＋ Add Software</button>
        </div>
    </div>

    <?php if(isset($_GET['assigned'])): ?><div class="alert alert-success">✓ Software added to this lab.</div><?php endif; ?>
    <?php if(isset($_GET['unassigned'])): ?><div class="alert alert-danger">✓ Software removed from this lab.</div><?php endif; ?>
    <?php if(isset($_GET['added'])): ?><div class="alert alert-success">✓ Software added to pool.</div><?php endif; ?>
    <?php if(isset($_GET['deleted'])): ?><div class="alert alert-danger">✓ Software deleted from pool.</div><?php endif; ?>
    <?php if(isset($_GET['cat_added'])): ?><div class="alert alert-success">✓ Category added.</div><?php endif; ?>
    <?php if(isset($_GET['cat_deleted'])): ?><div class="alert alert-danger">✓ Category removed.</div><?php endif; ?>

    <div class="sw-layout">

        <!-- ── Lab Sidebar ── -->
        <div class="lab-sidebar">
            <div class="sidebar-header">Laboratories</div>
            <?php foreach($labs as $lab): ?>
            <a href="manage_software.php?lab=<?php echo $lab['id']; ?>"
               class="lab-tab <?php echo $lab['id']==$active_lab_id?'active':''; ?>">
                <span class="lab-tab-name">Lab <?php echo htmlspecialchars($lab['lab_name']); ?></span>
                <span class="lab-tab-count"><?php echo $lab_sw_counts[$lab['id']] ?? 0; ?></span>
            </a>
            <?php endforeach; ?>
            <?php if(empty($labs)): ?>
            <div style="padding:20px;text-align:center;color:var(--gray-300);font-size:13px;">
                No labs yet.<br><a href="manage_labs.php" style="color:var(--blue);">Add a lab →</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Main panel ── -->
        <div class="sw-main">

        <?php if($active_lab): ?>

            <!-- Installed software for this lab -->
            <div class="panel-card">
                <div class="panel-head">
                    <div class="panel-head-left blue">
                        🖥️ Lab <?php echo htmlspecialchars($active_lab['lab_name']); ?> — Installed Software
                    </div>
                    <span style="font-size:13px;opacity:.7"><?php echo count($assigned_ids); ?> apps</span>
                </div>
                <div class="panel-body">
                    <?php if(empty($assigned_ids)): ?>
                    <div style="text-align:center;padding:32px;color:var(--gray-300);font-size:14px;">
                        No software assigned yet.<br>
                        <span style="font-size:13px;">Use the pool below to add software to this lab.</span>
                    </div>
                    <?php else: ?>
                        <?php foreach($categories as $cat):
                            $sw_list = $assigned_by_cat[$cat['id']] ?? [];
                            if(empty($sw_list)) continue;
                        ?>
                        <div class="cat-section">
                            <div class="cat-label">
                                <span><?php echo $cat['icon']; ?></span>
                                <?php echo htmlspecialchars($cat['name']); ?>
                                <span style="font-size:11px;font-weight:600;background:var(--gray-100);color:var(--gray-500);padding:1px 7px;border-radius:100px;"><?php echo count($sw_list); ?></span>
                            </div>
                            <div class="sw-grid">
                                <?php foreach($sw_list as $sw): ?>
                                <div class="sw-card">
                                    <span class="sw-icon"><?php echo $sw['icon']; ?></span>
                                    <div class="sw-info">
                                        <div class="sw-name" title="<?php echo htmlspecialchars($sw['name']); ?>"><?php echo htmlspecialchars($sw['name']); ?></div>
                                        <div class="sw-ver"><?php echo htmlspecialchars($sw['version'] ?: ''); ?></div>
                                    </div>
                                    <a href="manage_software.php?unassign=1&lab=<?php echo $active_lab_id; ?>&sw=<?php echo $sw['id']; ?>"
                                       class="sw-del" title="Remove from this lab"
                                       onclick="return confirm('Remove <?php echo htmlspecialchars(addslashes($sw['name'])); ?> from Lab <?php echo htmlspecialchars($active_lab['lab_name']); ?>?')">✕</a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Software Pool -->
            <div class="panel-card">
                <div class="panel-head">
                    <div class="panel-head-left amber">
                        📦 Software Pool — Add to Lab <?php echo htmlspecialchars($active_lab['lab_name']); ?>
                    </div>
                    <span style="font-size:13px;opacity:.7"><?php echo $total_sw; ?> total</span>
                </div>
                <div class="panel-body">
                    <?php if(empty($all_sw)): ?>
                    <div style="text-align:center;padding:24px;color:var(--gray-300);font-size:14px;">No software in pool yet. Use "＋ Add Software" above.</div>
                    <?php else: ?>
                        <?php foreach($categories as $cat):
                            $pool_list = $all_by_cat[$cat['id']] ?? [];
                            if(empty($pool_list)) continue;
                        ?>
                        <div class="pool-cat-lbl">
                            <?php echo $cat['icon']; ?> <?php echo htmlspecialchars($cat['name']); ?>
                        </div>
                        <div class="pool-grid">
                            <?php foreach($pool_list as $sw):
                                $is_assigned = in_array($sw['id'], $assigned_ids);
                            ?>
                            <div class="pool-card-item <?php echo $is_assigned?'already':''; ?>">
                                <span class="sw-icon" style="font-size:22px;"><?php echo $sw['icon']; ?></span>
                                <div class="pool-info">
                                    <div class="pool-name"><?php echo htmlspecialchars($sw['name']); ?></div>
                                    <div class="pool-ver"><?php echo htmlspecialchars($sw['version'] ?: 'No version'); ?></div>
                                </div>
                                <?php if($is_assigned): ?>
                                <span class="btn-added">✓</span>
                                <?php else: ?>
                                <a href="manage_software.php?assign=1&lab=<?php echo $active_lab_id; ?>&sw=<?php echo $sw['id']; ?>" class="btn-add">＋ Add</a>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>

                        <!-- Delete from pool -->
                        <div class="pool-del-section">
                            <div class="pool-del-title">Delete from Global Pool</div>
                            <div class="pool-chips">
                                <?php foreach($all_sw as $sw): ?>
                                <span class="pool-chip">
                                    <span><?php echo $sw['icon']; ?></span>
                                    <span class="pool-chip-name"><?php echo htmlspecialchars($sw['name']); ?></span>
                                    <a href="manage_software.php?delete_sw=<?php echo $sw['id']; ?>&lab=<?php echo $active_lab_id; ?>"
                                       class="pool-chip-del"
                                       onclick="return confirm('Delete <?php echo htmlspecialchars(addslashes($sw['name'])); ?> from ALL labs?')"
                                       title="Delete from pool">✕</a>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <div style="background:var(--white);border-radius:var(--radius);box-shadow:var(--card-shadow);padding:60px;text-align:center;color:var(--gray-500);">
                <div style="font-size:48px;margin-bottom:16px;">🖥️</div>
                <div style="font-size:17px;font-weight:600;color:var(--gray-700);margin-bottom:8px;">No laboratories found</div>
                <div style="font-size:14px;margin-bottom:20px;">Add laboratories first before managing software.</div>
                <a href="manage_labs.php" class="btn btn-primary">Go to Manage Labs →</a>
            </div>
        <?php endif; ?>

        </div><!-- /.sw-main -->
    </div><!-- /.sw-layout -->
</div><!-- /.page-container -->

<!-- Add Software Modal -->
<div class="modal-overlay" id="addSwModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Add Software to Pool</h2>
            <button class="close-btn" onclick="document.getElementById('addSwModal').classList.remove('active')">&times;</button>
        </div>
        <div class="modal-body">
            <p style="font-size:13px;color:var(--gray-500);margin-bottom:16px;">Software is added to the global pool first, then assigned to specific labs.</p>
            <form method="POST">
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label">Category <span style="color:#ef4444">*</span></label>
                    <select name="category_id" class="form-select" required>
                        <option value="">— Select Category —</option>
                        <?php foreach($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo $cat['icon']; ?> <?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label">Software Name <span style="color:#ef4444">*</span></label>
                    <input type="text" name="name" class="form-input" placeholder="e.g. Google Chrome" required>
                </div>
                <div class="form-grid" style="margin-bottom:14px;">
                    <div class="form-group">
                        <label class="form-label">Version</label>
                        <input type="text" name="version" class="form-input" placeholder="e.g. Latest">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Icon (emoji)</label>
                        <input type="text" name="icon" class="form-input" placeholder="e.g. 🌐" maxlength="5">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label">Description <span style="color:var(--gray-300)">(optional)</span></label>
                    <input type="text" name="description" class="form-input" placeholder="Brief description">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="document.getElementById('addSwModal').classList.remove('active')">Cancel</button>
                    <button type="submit" name="add_software" class="btn btn-primary">Add to Pool</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Category Modal -->
<div class="modal-overlay" id="addCatModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Add Category</h2>
            <button class="close-btn" onclick="document.getElementById('addCatModal').classList.remove('active')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label">Category Name <span style="color:#ef4444">*</span></label>
                    <input type="text" name="cat_name" class="form-input" placeholder="e.g. Multimedia Tools" required>
                </div>
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label">Icon (emoji)</label>
                    <input type="text" name="cat_icon" class="form-input" placeholder="e.g. 🎬" maxlength="5">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="document.getElementById('addCatModal').classList.remove('active')">Cancel</button>
                    <button type="submit" name="add_category" class="btn btn-primary">Add Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
window.onclick = e => {
    ['addSwModal','addCatModal'].forEach(id=>{
        if(e.target.id===id) document.getElementById(id).classList.remove('active');
    });
};
</script>
</body>
</html>