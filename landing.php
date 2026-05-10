<?php
include_once 'Database/connect.php';

$leaderboard = [];
// FIXED: INNER JOIN (was LEFT JOIN) so students with zero sit-ins are excluded.
// HAVING total_sitins > 0 is a belt-and-suspenders guard.
$res = $conn->query("
    SELECT s.id_number, s.first_name, s.last_name, s.course, s.profile_picture,
           COUNT(si.id) as total_sitins,
           IFNULL(SUM(
               TIMESTAMPDIFF(MINUTE,
                   CONCAT(si.sit_in_date,' ',si.sit_in_time),
                   CONCAT(si.time_out_date,' ',si.time_out)
               )
           ), 0) as total_min
    FROM students s
    INNER JOIN sit_in si
        ON si.id_number = s.id_number
        AND si.time_out IS NOT NULL
    GROUP BY s.id
    HAVING total_sitins > 0
    ORDER BY total_sitins DESC, total_min DESC
    LIMIT 10
");
if ($res) $leaderboard = $res->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CCS Sit-in Monitoring System</title>
<link rel="stylesheet" href="style.css">
<link rel="icon" type="image/png" href="pictures/uclogo.png">
<style>
.leaderboard-section{background:linear-gradient(180deg,var(--off-white) 0%,#e8edf8 100%);padding:80px 40px;}
.lb-container{max-width:900px;margin:0 auto;}
.lb-header{text-align:center;margin-bottom:48px;}
.lb-badge{display:inline-flex;align-items:center;gap:8px;background:rgba(30,111,224,.1);border:1px solid rgba(30,111,224,.3);color:var(--blue);font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:6px 14px;border-radius:100px;margin-bottom:18px;}
.lb-title{font-family:'Playfair Display',serif;font-size:clamp(28px,4vw,42px);font-weight:600;color:var(--navy);line-height:1.2;margin-bottom:12px;}
.lb-subtitle{font-size:15px;color:var(--gray-500);max-width:480px;margin:0 auto;}
.lb-grid{display:flex;flex-direction:column;gap:12px;}
.lb-item{background:var(--white);border-radius:16px;box-shadow:0 4px 24px rgba(10,22,40,.07);padding:18px 24px;display:flex;align-items:center;gap:20px;transition:all .25s cubic-bezier(.4,0,.2,1);animation:lbFadeIn .5s ease both;border:1.5px solid transparent;}
.lb-item:hover{transform:translateY(-3px);box-shadow:0 12px 40px rgba(10,22,40,.13);border-color:rgba(30,111,224,.15);}
.lb-item:nth-child(1){border-left:4px solid #f59e0b;}
.lb-item:nth-child(2){border-left:4px solid #94a3b8;}
.lb-item:nth-child(3){border-left:4px solid #b45309;}
.lb-rank{font-size:24px;font-weight:800;color:var(--gray-300);min-width:42px;text-align:center;line-height:1;}
.lb-item:nth-child(1) .lb-rank{color:#f59e0b;font-size:28px;}
.lb-item:nth-child(2) .lb-rank{color:#94a3b8;font-size:26px;}
.lb-item:nth-child(3) .lb-rank{color:#b45309;font-size:25px;}
.lb-avatar{width:56px;height:56px;border-radius:50%;object-fit:cover;border:3px solid var(--gray-100);flex-shrink:0;}
.lb-item:nth-child(1) .lb-avatar{border-color:#f59e0b;box-shadow:0 0 0 4px rgba(245,158,11,.15);}
.lb-item:nth-child(2) .lb-avatar{border-color:#94a3b8;box-shadow:0 0 0 4px rgba(148,163,184,.15);}
.lb-item:nth-child(3) .lb-avatar{border-color:#b45309;box-shadow:0 0 0 4px rgba(180,83,9,.12);}
.lb-info{flex:1;min-width:0;}
.lb-name{font-size:16px;font-weight:700;color:var(--navy);margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.lb-id{font-size:12px;color:var(--gray-300);margin-bottom:4px;}
.lb-course{display:inline-block;background:rgba(30,111,224,.08);color:var(--blue);font-size:11px;font-weight:600;padding:2px 9px;border-radius:100px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;}
.lb-stats{text-align:right;flex-shrink:0;}
.lb-count{font-size:22px;font-weight:700;color:var(--navy);line-height:1;margin-bottom:2px;}
.lb-count-lbl{font-size:11px;color:var(--gray-300);font-weight:600;text-transform:uppercase;letter-spacing:.04em;}
.lb-empty{text-align:center;padding:60px 20px;color:var(--gray-500);font-size:15px;}
@keyframes lbFadeIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:640px){.lb-item{padding:14px 16px;gap:14px;}.lb-avatar{width:44px;height:44px;}.lb-course{max-width:120px;}.leaderboard-section{padding:50px 20px;}}
</style>
</head>
<body>

<nav class="navbar">
    <div class="nav-left">
        <img class="logo_landing" src="pictures/uclogo.png" alt="UC Logo">
        CCS Sit-in Monitoring System
    </div>
    <ul class="nav-right">
        <li><a href="landing.php">Home</a></li>
        <li><a href="login.php">Login</a></li>
        <li><a href="registration.php">Register</a></li>
    </ul>
</nav>

<main class="hero">
    <div class="hero-text">
        <div class="hero-badge"><span>●</span> University of Cebu — CCS</div>
        <h1>Sit-in <span>Monitoring</span><br>System</h1>
        <p class="hero-subtitle">
            Track and manage computer laboratory usage seamlessly.
            Built for students and administrators of the College of Computer Studies.
        </p>
        <a href="login.php" class="start-btn">Get Started</a>
    </div>
</main>

<section class="leaderboard-section">
    <div class="lb-container">
        <div class="lb-header">
            <div class="lb-badge">🏆 Hall of Fame</div>
            <h2 class="lb-title">Top 10 Students Leaderboard</h2>
            <p class="lb-subtitle">Recognizing the most active and dedicated laboratory users this semester.</p>
        </div>

        <?php if (empty($leaderboard)): ?>
        <div class="lb-empty">
            <div style="font-size:48px;margin-bottom:16px;">🏆</div>
            <div style="font-size:17px;font-weight:600;color:var(--gray-700);margin-bottom:6px;">No data yet</div>
            <div>Leaderboard will populate as students complete lab sessions.</div>
        </div>
        <?php else: ?>
        <div class="lb-grid">
            <?php
            $medals = ['🥇','🥈','🥉'];
            foreach ($leaderboard as $idx => $student):
                $rank  = $idx + 1;
                $hours = $student['total_min'] ? round($student['total_min'] / 60, 1) : 0;
                $pic   = $student['profile_picture'] ?: 'default.png';
            ?>
            <div class="lb-item" style="animation-delay:<?php echo $idx * .07; ?>s">
                <div class="lb-rank">
                    <?php if ($rank <= 3): ?>
                        <span><?php echo $medals[$rank - 1]; ?></span>
                    <?php else: ?>
                        #<?php echo $rank; ?>
                    <?php endif; ?>
                </div>

                <img class="lb-avatar"
                     src="profile_pictures/<?php echo htmlspecialchars($pic); ?>"
                     alt="<?php echo htmlspecialchars($student['first_name']); ?>"
                     onerror="this.src='profile_pictures/default.png'">

                <div class="lb-info">
                    <div class="lb-name"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                    <div class="lb-id"><?php echo htmlspecialchars($student['id_number']); ?></div>
                    <div class="lb-course"><?php echo htmlspecialchars($student['course']); ?></div>
                </div>

                <div class="lb-stats">
                    <div class="lb-count"><?php echo $student['total_sitins']; ?></div>
                    <div class="lb-count-lbl">Sessions</div>
                    <?php if ($hours > 0): ?>
                    <div style="font-size:12px;color:var(--gray-300);margin-top:3px;"><?php echo $hours; ?>h total</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

</body>
</html>
