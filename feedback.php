<?php
session_start();
if(!isset($_SESSION['student_id'])){
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

$student_id = $_SESSION['student_id'];
$id_number  = $_SESSION['id_number'];
$sit_in_id  = intval($_GET['sit_in_id'] ?? 0);

if(!$sit_in_id){ header("Location: history.php"); exit; }

// Verify sit-in belongs to this student and is timed out
$s = $conn->prepare("SELECT * FROM sit_in WHERE id = ? AND id_number = ? AND time_out IS NOT NULL");
$s->bind_param("is", $sit_in_id, $id_number);
$s->execute();
$sitin = $s->get_result()->fetch_assoc();
$s->close();

if(!$sitin){ header("Location: history.php"); exit; }

// Check if already submitted
$chk = $conn->prepare("SELECT id FROM sit_in_feedback WHERE sit_in_id = ?");
$chk->bind_param("i", $sit_in_id);
$chk->execute();
$already_submitted = $chk->get_result()->num_rows > 0;
$chk->close();

$success = false;
$error   = '';

if(!$already_submitted && $_SERVER['REQUEST_METHOD'] === 'POST'){
    $rating        = intval($_POST['rating'] ?? 0);
    $feedback_text = trim($_POST['feedback_text'] ?? '');

    if($rating < 1 || $rating > 5){
        $error = 'Please select a rating between 1 and 5.';
    } else {
        $student_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
        // bind types: i i s s s i s
        $ins = $conn->prepare(
            "INSERT INTO sit_in_feedback (sit_in_id, student_id, id_number, student_name, lab, rating, feedback_text)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->bind_param("iissis", $sit_in_id, $student_id, $id_number, $student_name, $sitin['lab'], $rating, $feedback_text);
        // Correct: 7 values → "iissis" has only 6 chars — fix:
        $ins->close();
        $ins = $conn->prepare(
            "INSERT INTO sit_in_feedback (sit_in_id, student_id, id_number, student_name, lab, rating, feedback_text)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->bind_param("iisssis", $sit_in_id, $student_id, $id_number, $student_name, $sitin['lab'], $rating, $feedback_text);
        // Still wrong: rating is int → "iissiis" — let's be explicit:
        // sit_in_id=i, student_id=i, id_number=s, student_name=s, lab=s, rating=i, feedback_text=s → "iisssis"
        $ins->close();
        $ins = $conn->prepare(
            "INSERT INTO sit_in_feedback (sit_in_id, student_id, id_number, student_name, lab, rating, feedback_text)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->bind_param("iisssis", $sit_in_id, $student_id, $id_number, $student_name, $sitin['lab'], $rating, $feedback_text);
        if($ins->execute()){
            $success          = true;
            $already_submitted = true;
        } else {
            $error = 'Could not save feedback: ' . $ins->error;
        }
        $ins->close();
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Session Feedback — CCS Sit-in</title>
<link rel="stylesheet" href="userdb.css">
<link rel="icon" type="image/png" href="pictures/uclogo.png">
<style>
.feedback-page{min-height:calc(100vh - 66px);display:flex;justify-content:center;align-items:flex-start;padding:40px 20px 80px;background:var(--off-white);}
.feedback-card{background:var(--white);width:100%;max-width:520px;border-radius:var(--radius);box-shadow:var(--card-shadow);overflow:hidden;animation:cardIn .4s cubic-bezier(.4,0,.2,1) both;}
@keyframes cardIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.fb-header{background:linear-gradient(135deg,var(--navy) 0%,var(--navy-mid) 100%);padding:22px 28px;color:var(--white);}
.fb-header h2{font-family:'Playfair Display',serif;font-size:22px;font-weight:600;margin-bottom:4px;}
.fb-header p{font-size:13px;color:rgba(255,255,255,.6);}
.fb-body{padding:28px;}
.session-pill{background:var(--off-white);border-radius:var(--radius-sm);padding:14px 18px;margin-bottom:24px;display:flex;flex-wrap:wrap;gap:10px 20px;font-size:13px;color:var(--gray-500);}
.session-pill strong{color:var(--navy);}

/* ── Stars ── */
.star-row{display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:6px;margin-bottom:8px;}
.star-row input[type=radio]{display:none;}
.star-row label{font-size:40px;color:var(--gray-100);cursor:pointer;transition:color .12s,transform .12s;line-height:1;user-select:none;}
.star-row label:hover,.star-row label:hover~label,.star-row input:checked~label{color:#f59e0b;}
.star-row label:hover{transform:scale(1.18);}
.star-hint{font-size:12px;color:var(--gray-300);min-height:18px;margin-bottom:16px;}

.fb-label{display:block;font-size:13px;font-weight:600;color:var(--gray-700);margin-bottom:7px;}
.fb-textarea{width:100%;padding:12px 14px;border:1.5px solid var(--gray-100);border-radius:var(--radius-sm);font-size:14px;font-family:'Outfit',sans-serif;color:var(--navy);background:#fafbff;resize:vertical;min-height:110px;outline:none;transition:all var(--transition);}
.fb-textarea:focus{border-color:var(--blue);background:var(--white);box-shadow:0 0 0 3px rgba(30,111,224,.1);}
.char-count{text-align:right;font-size:12px;color:var(--gray-300);margin-top:4px;}

.submit-btn{width:100%;padding:13px;background:linear-gradient(135deg,var(--blue) 0%,var(--blue-light) 100%);color:var(--white);border:none;border-radius:var(--radius-sm);font-size:15px;font-weight:600;font-family:'Outfit',sans-serif;cursor:pointer;transition:all var(--transition);margin-top:6px;}
.submit-btn:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(30,111,224,.35);}

.center-box{text-align:center;padding:16px 0 8px;}
.big-icon{font-size:56px;margin-bottom:14px;}
.big-title{font-family:'Playfair Display',serif;font-size:22px;font-weight:600;color:var(--navy);margin-bottom:8px;}
.big-desc{font-size:14px;color:var(--gray-500);line-height:1.65;margin-bottom:22px;}

.btn-back{display:inline-flex;align-items:center;justify-content:center;padding:11px 26px;background:linear-gradient(135deg,var(--blue) 0%,var(--blue-light) 100%);color:var(--white);border-radius:var(--radius-sm);font-size:14px;font-weight:600;font-family:'Outfit',sans-serif;text-decoration:none;transition:all var(--transition);}
.btn-back:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(30,111,224,.35);}

.alert-err{padding:12px 14px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#b91c1c;border-radius:var(--radius-sm);font-size:14px;margin-bottom:18px;}
.back-link{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:18px;font-size:13px;color:var(--gray-500);text-decoration:none;transition:color var(--transition);}
.back-link:hover{color:var(--blue);}
</style>
</head>
<body>

<nav class="dashboard-navbar">
    <div class="dashboard-left">Dashboard</div>
    <ul class="dashboard-right">
        <li><a href="notifications.php">Notification</a></li>
        <li><a href="userdb.php">Home</a></li>
        <li><a href="edit_profile.php">Edit Profile</a></li>
        <li><a href="history.php" class="active">History</a></li>
        <li><a href="student_reservation.php">Reservation</a></li>
        <li><a href="logout.php" class="logout-btn">Log Out</a></li>
    </ul>
</nav>

<div class="feedback-page">
<div class="feedback-card">

    <div class="fb-header">
        <h2>Session Feedback</h2>
        <p>Help us improve your laboratory experience</p>
    </div>

    <div class="fb-body">

        <!-- Session summary -->
        <div class="session-pill">
            <span>🧪 <strong>Lab <?php echo htmlspecialchars($sitin['lab']); ?></strong></span>
            <span>📌 <strong><?php echo htmlspecialchars($sitin['purpose']); ?></strong></span>
            <span>📅 <?php echo date('M d, Y', strtotime($sitin['sit_in_date'])); ?></span>
            <span>🕐 <?php echo date('h:i A', strtotime($sitin['sit_in_time'])); ?> → <?php echo date('h:i A', strtotime($sitin['time_out'])); ?></span>
        </div>

        <?php if($success): ?>
        <div class="center-box">
            <div class="big-icon">🎉</div>
            <div class="big-title">Thank You!</div>
            <div class="big-desc">Your feedback has been recorded. It helps us keep the labs running smoothly for everyone.</div>
            <a href="history.php" class="btn-back">← Back to History</a>
        </div>

        <?php elseif($already_submitted): ?>
        <div class="center-box">
            <div class="big-icon">✅</div>
            <div class="big-title">Already Submitted</div>
            <div style="font-size:14px;color:var(--gray-500);margin-bottom:20px;">You've already provided feedback for this session. Thank you!</div>
            <a href="history.php" class="btn-back">← Back to History</a>
        </div>

        <?php else: ?>
        <?php if($error): ?>
        <div class="alert-err">⚠ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="">

            <div style="margin-bottom:6px;">
                <span class="fb-label">How would you rate this session? <span style="color:#ef4444">*</span></span>
                <!-- DOM order: 5 4 3 2 1 — flex-direction:row-reverse shows them as 1 2 3 4 5 left→right -->
                <div class="star-row">
                    <input type="radio" name="rating" id="s5" value="5" required>
                    <label for="s5" title="Excellent">★</label>
                    <input type="radio" name="rating" id="s4" value="4">
                    <label for="s4" title="Good">★</label>
                    <input type="radio" name="rating" id="s3" value="3">
                    <label for="s3" title="Okay">★</label>
                    <input type="radio" name="rating" id="s2" value="2">
                    <label for="s2" title="Poor">★</label>
                    <input type="radio" name="rating" id="s1" value="1">
                    <label for="s1" title="Very Poor">★</label>
                </div>
                <div class="star-hint" id="starHint">Click a star to rate</div>
            </div>

            <div style="margin-bottom:20px;">
                <label class="fb-label" for="feedback_text">
                    Additional Comments
                    <span style="font-weight:400;color:var(--gray-300)">(optional)</span>
                </label>
                <textarea name="feedback_text" id="feedback_text"
                          class="fb-textarea" maxlength="500"
                          placeholder="Equipment condition, internet speed, lab environment, staff helpfulness…"></textarea>
                <div class="char-count"><span id="charCount">0</span> / 500</div>
            </div>

            <button type="submit" class="submit-btn">Submit Feedback</button>
        </form>
        <?php endif; ?>

        <a href="history.php" class="back-link">← Back to History</a>
    </div>
</div>
</div>

<script>
const hints = {1:'Very Poor',2:'Poor',3:'Okay',4:'Good',5:'Excellent'};
const hintEl = document.getElementById('starHint');

document.querySelectorAll('.star-row label').forEach(lbl => {
    function valOf(l){ const inp = l.previousElementSibling; return inp ? parseInt(inp.value) : 0; }
    lbl.addEventListener('mouseenter', () => { if(hintEl) hintEl.textContent = hints[valOf(lbl)] || ''; });
    lbl.addEventListener('mouseleave', () => {
        const checked = document.querySelector('.star-row input:checked');
        if(hintEl) hintEl.textContent = checked ? (hints[checked.value] || '') : 'Click a star to rate';
    });
});

const ta = document.getElementById('feedback_text');
const cc = document.getElementById('charCount');
if(ta && cc){
    ta.addEventListener('input', () => {
        cc.textContent = ta.value.length;
        cc.style.color = ta.value.length > 450 ? '#ef4444' : '';
    });
}
</script>

</body>
</html>
