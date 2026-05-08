<?php
session_start();
if(!isset($_SESSION['student_id'])){
    header("Location: login.php"); exit;
}
include 'Database/connect.php';

// Ensure tables exist
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

// Fetch all active labs
$labs = $conn->query("SELECT * FROM labs WHERE is_active=1 ORDER BY lab_name")->fetch_all(MYSQLI_ASSOC);

// Selected lab
$selected_lab_id = intval($_GET['lab'] ?? 0);
if(!$selected_lab_id && !empty($labs)) $selected_lab_id = $labs[0]['id'];
$selected_lab = null;
foreach($labs as $l){ if($l['id']==$selected_lab_id){ $selected_lab=$l; break; } }

// Fetch categories
$categories = $conn->query("SELECT * FROM software_categories ORDER BY sort_order,name")->fetch_all(MYSQLI_ASSOC);

// Fetch software for selected lab grouped by category
$lab_sw_by_cat = [];
$total_for_lab = 0;
if($selected_lab_id){
    $sr = $conn->query("SELECT s.*,c.name as cat_name,c.icon as cat_icon,c.id as cid
        FROM lab_software ls
        JOIN software s ON s.id=ls.software_id
        JOIN software_categories c ON c.id=s.category_id
        WHERE ls.lab_id=$selected_lab_id
        ORDER BY c.sort_order,s.name");
    while($row=$sr->fetch_assoc()){
        $lab_sw_by_cat[$row['cid']][] = $row;
        $total_for_lab++;
    }
}

// For "All Labs" overview: fetch per-lab software counts
$all_labs_overview = [];
foreach($labs as $lab){
    $res = $conn->query("SELECT s.*,c.name as cat_name,c.icon as cat_icon,c.id as cid
        FROM lab_software ls
        JOIN software s ON s.id=ls.software_id
        JOIN software_categories c ON c.id=s.category_id
        WHERE ls.lab_id={$lab['id']}
        ORDER BY c.sort_order,s.name");
    $by_cat = [];
    while($row=$res->fetch_assoc()) $by_cat[$row['cid']][] = $row;
    $all_labs_overview[$lab['id']] = ['lab'=>$lab,'by_cat'=>$by_cat,'total'=>$res->num_rows];
}
// Re-fetch counts correctly
foreach($labs as $lab){
    $cnt = $conn->query("SELECT COUNT(*) as c FROM lab_software WHERE lab_id={$lab['id']}")->fetch_assoc()['c'];
    $all_labs_overview[$lab['id']]['total'] = $cnt;
}

$view = $_GET['view'] ?? 'lab'; // 'lab' or 'all'

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Available Software — CCS Sit-in</title>
<link rel="stylesheet" href="userdb.css">
<link rel="icon" type="image/png" href="pictures/uclogo.png">
<style>
.sw-page{padding:28px 32px;max-width:1100px;margin:0 auto;}
.page-title{font-family:'Playfair Display',serif;font-size:26px;font-weight:600;color:var(--navy);}
.page-subtitle{font-size:14px;color:var(--gray-500);margin-top:3px;margin-bottom:24px;}

/* View toggle */
.view-tabs{display:flex;gap:8px;margin-bottom:22px;}
.view-tab{padding:9px 20px;border-radius:100px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;border:1.5px solid var(--gray-100);background:var(--white);color:var(--gray-500);transition:all var(--transition);}
.view-tab:hover{border-color:var(--blue);color:var(--blue);}
.view-tab.active{background:var(--blue);border-color:var(--blue);color:var(--white);}

/* ── Lab selector tabs (horizontal scroll) ── */
.lab-tabs-wrap{overflow-x:auto;margin-bottom:20px;padding-bottom:4px;}
.lab-tabs{display:flex;gap:8px;min-width:max-content;}
.lab-tab-btn{display:flex;flex-direction:column;align-items:center;gap:4px;padding:12px 20px;border-radius:var(--radius);background:var(--white);border:1.5px solid var(--gray-100);cursor:pointer;text-decoration:none;transition:all var(--transition);min-width:100px;}
.lab-tab-btn:hover{border-color:var(--blue);transform:translateY(-2px);box-shadow:var(--card-shadow);}
.lab-tab-btn.active{background:var(--navy);border-color:var(--navy);color:var(--white);}
.lab-tab-btn.active .lab-tab-name{color:var(--white);}
.lab-tab-btn.active .lab-tab-cnt{background:rgba(255,255,255,.2);color:var(--white);}
.lab-tab-icon{font-size:22px;}
.lab-tab-name{font-size:13px;font-weight:700;color:var(--navy);}
.lab-tab-cnt{font-size:11px;font-weight:700;background:var(--gray-100);color:var(--gray-500);padding:2px 8px;border-radius:100px;}

/* ── Search ── */
.search-wrap{position:relative;max-width:360px;margin-bottom:22px;}
.search-wrap input{width:100%;padding:10px 14px 10px 38px;border:1.5px solid var(--gray-100);border-radius:var(--radius-sm);font-size:14px;font-family:'Outfit',sans-serif;color:var(--navy);background:var(--white);outline:none;transition:all var(--transition);}
.search-wrap input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(30,111,224,.1);}
.search-wrap::before{content:'🔍';position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:14px;}

/* ── Category sections ── */
.cat-block{background:var(--white);border-radius:var(--radius);box-shadow:var(--card-shadow);overflow:hidden;margin-bottom:16px;transition:box-shadow var(--transition);}
.cat-block:hover{box-shadow:var(--card-shadow-hover);}
.cat-block-header{background:linear-gradient(135deg,var(--navy),var(--navy-mid));color:var(--white);padding:14px 22px;display:flex;align-items:center;gap:12px;}
.cat-block-title{font-size:14px;font-weight:600;flex:1;}
.cat-block-count{font-size:12px;opacity:.7;}
.cat-block-body{padding:18px 20px;}

/* ── Software cards ── */
.sw-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(165px,1fr));gap:12px;}
.sw-card{background:var(--off-white);border-radius:var(--radius-sm);padding:16px 14px;display:flex;flex-direction:column;align-items:center;text-align:center;gap:7px;transition:all var(--transition);border:1.5px solid transparent;}
.sw-card:hover{background:var(--white);border-color:rgba(30,111,224,.2);box-shadow:0 4px 14px rgba(10,22,40,.08);transform:translateY(-2px);}
.sw-card-icon{font-size:34px;}
.sw-card-name{font-size:13px;font-weight:700;color:var(--navy);line-height:1.3;}
.sw-card-ver{font-size:11px;color:var(--gray-300);}
.sw-card-desc{font-size:11px;color:var(--gray-500);line-height:1.5;}
.no-sw{text-align:center;padding:24px;color:var(--gray-300);font-size:14px;}

/* ── All labs overview ── */
.overview-grid{display:flex;flex-direction:column;gap:22px;}
.overview-lab-card{background:var(--white);border-radius:var(--radius);box-shadow:var(--card-shadow);overflow:hidden;}
.overview-lab-header{background:linear-gradient(135deg,var(--navy),var(--navy-mid));color:var(--white);padding:15px 22px;display:flex;align-items:center;justify-content:space-between;}
.overview-lab-title{font-size:15px;font-weight:700;display:flex;align-items:center;gap:10px;}
.overview-lab-title::before{content:'';width:4px;height:16px;background:var(--blue-light);border-radius:2px;}
.overview-lab-body{padding:18px 20px;}
.overview-cat-label{font-size:11px;font-weight:700;color:var(--gray-500);letter-spacing:.05em;text-transform:uppercase;margin:12px 0 8px;display:flex;align-items:center;gap:6px;}
.overview-cat-label:first-child{margin-top:0;}
.overview-sw-chips{display:flex;flex-wrap:wrap;gap:7px;}
.sw-chip{display:inline-flex;align-items:center;gap:6px;background:var(--off-white);border:1.5px solid var(--gray-100);border-radius:8px;padding:6px 12px;font-size:13px;font-weight:500;color:var(--navy);transition:all var(--transition);}
.sw-chip:hover{background:rgba(30,111,224,.07);border-color:rgba(30,111,224,.2);}
.sw-chip-icon{font-size:16px;}
.sw-chip-ver{font-size:11px;color:var(--gray-300);}
.overview-empty{text-align:center;padding:24px;color:var(--gray-300);font-size:13px;font-style:italic;}

/* Empty state */
.empty-state{text-align:center;padding:60px 20px;}
.empty-icon{font-size:48px;margin-bottom:14px;}
.empty-title{font-size:17px;font-weight:600;color:var(--gray-700);margin-bottom:6px;}
.empty-desc{font-size:14px;color:var(--gray-500);}

@media(max-width:768px){.sw-page{padding:16px;}}
</style>
</head>
<body>

<nav class="dashboard-navbar">
    <div class="dashboard-left">Dashboard</div>
    <ul class="dashboard-right">
        <li><a href="notifications.php">Notifications</a></li>
        <li><a href="userdb.php">Home</a></li>
        <li><a href="software.php" class="active">Software</a></li>
        <li><a href="edit_profile.php">Edit Profile</a></li>
        <li><a href="history.php">History</a></li>
        <li><a href="student_reservation.php">Reservation</a></li>
        <li><a href="logout.php" class="logout-btn">Log Out</a></li>
    </ul>
</nav>

<div class="sw-page">

    <div class="page-title">Available Software</div>
    <div class="page-subtitle">Browse software and tools available in each computer laboratory</div>

    <!-- View Toggle -->
    <div class="view-tabs">
        <a href="software.php?view=lab&lab=<?php echo $selected_lab_id; ?>"
           class="view-tab <?php echo $view==='lab'?'active':''; ?>">
            🖥️ Browse by Lab
        </a>
        <a href="software.php?view=all"
           class="view-tab <?php echo $view==='all'?'active':''; ?>">
            📋 All Labs Overview
        </a>
    </div>

    <?php if($view === 'lab'): ?>
    <!-- ══════════════════════════════════════════
         VIEW: Browse by Lab
    ══════════════════════════════════════════ -->

    <?php if(empty($labs)): ?>
    <div class="empty-state">
        <div class="empty-icon">🏫</div>
        <div class="empty-title">No active laboratories</div>
        <div class="empty-desc">Labs will appear here once activated by the admin.</div>
    </div>
    <?php else: ?>

    <!-- Lab selector tabs -->
    <div class="lab-tabs-wrap">
        <div class="lab-tabs">
            <?php foreach($labs as $lab):
                $cnt = $all_labs_overview[$lab['id']]['total'] ?? 0;
            ?>
            <a href="software.php?view=lab&lab=<?php echo $lab['id']; ?>"
               class="lab-tab-btn <?php echo $lab['id']==$selected_lab_id?'active':''; ?>">
                <span class="lab-tab-icon">🖥️</span>
                <span class="lab-tab-name">Lab <?php echo htmlspecialchars($lab['lab_name']); ?></span>
                <span class="lab-tab-cnt"><?php echo $cnt; ?> apps</span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Search -->
    <div class="search-wrap">
        <input type="text" id="swSearch" placeholder="Search software in Lab <?php echo htmlspecialchars($selected_lab ? $selected_lab['lab_name'] : ''); ?>…" oninput="filterSoftware(this.value)">
    </div>

    <!-- Software categories for selected lab -->
    <?php if(empty($lab_sw_by_cat)): ?>
    <div class="empty-state">
        <div class="empty-icon">💿</div>
        <div class="empty-title">No software listed for Lab <?php echo htmlspecialchars($selected_lab['lab_name'] ?? ''); ?></div>
        <div class="empty-desc">The administrator has not assigned any software to this lab yet.</div>
    </div>
    <?php else: ?>
    <div id="swContainer">
        <?php foreach($categories as $cat):
            $sw_list = $lab_sw_by_cat[$cat['id']] ?? [];
            if(empty($sw_list)) continue;
        ?>
        <div class="cat-block" data-cat="<?php echo $cat['id']; ?>">
            <div class="cat-block-header">
                <span style="font-size:22px;"><?php echo $cat['icon']; ?></span>
                <span class="cat-block-title"><?php echo htmlspecialchars($cat['name']); ?></span>
                <span class="cat-block-count"><?php echo count($sw_list); ?> apps</span>
            </div>
            <div class="cat-block-body">
                <div class="sw-grid">
                    <?php foreach($sw_list as $sw): ?>
                    <div class="sw-card" data-name="<?php echo strtolower(htmlspecialchars($sw['name'])); ?>">
                        <span class="sw-card-icon"><?php echo $sw['icon']; ?></span>
                        <div class="sw-card-name"><?php echo htmlspecialchars($sw['name']); ?></div>
                        <?php if($sw['version']): ?>
                        <div class="sw-card-ver">v<?php echo htmlspecialchars($sw['version']); ?></div>
                        <?php endif; ?>
                        <?php if($sw['description']): ?>
                        <div class="sw-card-desc"><?php echo htmlspecialchars($sw['description']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div id="noResult" style="display:none;" class="empty-state">
        <div class="empty-icon">🔍</div>
        <div class="empty-title">No results found</div>
        <div class="empty-desc">Try a different search term.</div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php else: ?>
    <!-- ══════════════════════════════════════════
         VIEW: All Labs Overview
    ══════════════════════════════════════════ -->

    <?php if(empty($labs)): ?>
    <div class="empty-state">
        <div class="empty-icon">🏫</div>
        <div class="empty-title">No active laboratories</div>
        <div class="empty-desc">Labs will appear here once activated by the admin.</div>
    </div>
    <?php else: ?>

    <!-- Search -->
    <div class="search-wrap">
        <input type="text" id="swSearchAll" placeholder="Search software across all labs…" oninput="filterAll(this.value)">
    </div>

    <div class="overview-grid" id="overviewContainer">
        <?php foreach($all_labs_overview as $lab_id => $data):
            $lab     = $data['lab'];
            $by_cat  = $data['by_cat'];
            $total   = $data['total'];
        ?>
        <div class="overview-lab-card" data-labid="<?php echo $lab_id; ?>">
            <div class="overview-lab-header">
                <div class="overview-lab-title">
                    🖥️ Lab <?php echo htmlspecialchars($lab['lab_name']); ?>
                    <?php if($lab['description']): ?>
                    <span style="font-size:12px;opacity:.7;font-weight:400;"><?php echo htmlspecialchars($lab['description']); ?></span>
                    <?php endif; ?>
                </div>
                <span style="font-size:13px;opacity:.7;"><?php echo $total; ?> apps</span>
            </div>
            <div class="overview-lab-body">
                <?php if(empty($by_cat)): ?>
                <div class="overview-empty">No software assigned to this lab yet.</div>
                <?php else: ?>
                    <?php foreach($categories as $cat):
                        $sw_list = $by_cat[$cat['id']] ?? [];
                        if(empty($sw_list)) continue;
                    ?>
                    <div class="overview-cat-label">
                        <?php echo $cat['icon']; ?> <?php echo htmlspecialchars($cat['name']); ?>
                    </div>
                    <div class="overview-sw-chips" style="margin-bottom:6px;">
                        <?php foreach($sw_list as $sw): ?>
                        <span class="sw-chip" data-name="<?php echo strtolower(htmlspecialchars($sw['name'])); ?>">
                            <span class="sw-chip-icon"><?php echo $sw['icon']; ?></span>
                            <span><?php echo htmlspecialchars($sw['name']); ?></span>
                            <?php if($sw['version']): ?>
                            <span class="sw-chip-ver">v<?php echo htmlspecialchars($sw['version']); ?></span>
                            <?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

</div>

<script>
// ── Browse by Lab search ──────────────────────────────────────────────
function filterSoftware(q){
    q = q.toLowerCase().trim();
    let any = false;
    document.querySelectorAll('.cat-block').forEach(block => {
        let catVisible = false;
        block.querySelectorAll('.sw-card').forEach(card => {
            const show = !q || card.dataset.name.includes(q);
            card.style.display = show ? '' : 'none';
            if(show) catVisible = true;
        });
        block.style.display = (catVisible || !q) ? '' : 'none';
        if(catVisible) any = true;
    });
    const nr = document.getElementById('noResult');
    if(nr) nr.style.display = (!any && q) ? '' : 'none';
}

// ── All Labs overview search ──────────────────────────────────────────
function filterAll(q){
    q = q.toLowerCase().trim();
    document.querySelectorAll('.overview-lab-card').forEach(card => {
        if(!q){ card.style.display=''; return; }
        let found = false;
        card.querySelectorAll('.sw-chip').forEach(chip => {
            const show = chip.dataset.name.includes(q);
            chip.style.display = show ? '' : 'none';
            if(show) found = true;
        });
        card.style.display = found ? '' : 'none';
    });
}
</script>
</body>
</html>