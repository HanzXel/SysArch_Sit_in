<?php
session_start();
if(!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true){
    header("Location: login.php"); exit;
}
include 'Database/connect.php';

// Overall stats
$total_students  = $conn->query("SELECT COUNT(*) as c FROM students")->fetch_assoc()['c'];
$total_sitins    = $conn->query("SELECT COUNT(*) as c FROM sit_in")->fetch_assoc()['c'];
$today_sitins    = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE sit_in_date = CURDATE()")->fetch_assoc()['c'];
$month_sitins    = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE MONTH(sit_in_date)=MONTH(CURDATE()) AND YEAR(sit_in_date)=YEAR(CURDATE())")->fetch_assoc()['c'];
$zero_sessions   = $conn->query("SELECT COUNT(*) as c FROM students WHERE sessions = 0")->fetch_assoc()['c'];

// Charts data
$lab_data       = $conn->query("SELECT lab, COUNT(*) as c FROM sit_in GROUP BY lab ORDER BY c DESC")->fetch_all(MYSQLI_ASSOC);
$purpose_data   = $conn->query("SELECT purpose, COUNT(*) as c FROM sit_in GROUP BY purpose ORDER BY c DESC")->fetch_all(MYSQLI_ASSOC);
$top_students   = $conn->query("SELECT student_name, id_number, COUNT(*) as c FROM sit_in GROUP BY id_number, student_name ORDER BY c DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);

$monthly_sitins = array_fill_keys(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'], 0);
$months_map     = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
$res = $conn->query("SELECT MONTH(sit_in_date) as m, COUNT(*) as c FROM sit_in WHERE YEAR(sit_in_date)=YEAR(CURDATE()) GROUP BY MONTH(sit_in_date)");
while($row=$res->fetch_assoc()) $monthly_sitins[$months_map[$row['m']]]=$row['c'];

$daily_data = $conn->query("SELECT sit_in_date, COUNT(*) as c FROM sit_in WHERE sit_in_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY sit_in_date ORDER BY sit_in_date ASC")->fetch_all(MYSQLI_ASSOC);

// ── PDF Report Data ──────────────────────────────────────────────────
// Student list for PDF
$pdf_students = $conn->query("SELECT id_number, CONCAT(first_name,' ',last_name) as full_name, course, year_level, email, sessions, created_at FROM students ORDER BY last_name, first_name")->fetch_all(MYSQLI_ASSOC);

// Sit-in logs for PDF (with optional date range)
$pdf_from = trim($_GET['pdf_from'] ?? '');
$pdf_to   = trim($_GET['pdf_to']   ?? '');
$pdf_where = "WHERE 1=1";
if($pdf_from) $pdf_where .= " AND sit_in_date >= '$pdf_from'";
if($pdf_to)   $pdf_where .= " AND sit_in_date <= '$pdf_to'";
$pdf_logs = $conn->query("SELECT id_number, student_name, purpose, lab, 
    IFNULL(seat_number,'—') as seat_number,
    remaining_session, sit_in_date, sit_in_time, 
    time_out, time_out_date,
    CASE WHEN time_out IS NOT NULL THEN
        CONCAT(FLOOR(TIMESTAMPDIFF(MINUTE,CONCAT(sit_in_date,' ',sit_in_time),CONCAT(time_out_date,' ',time_out))/60),'h ',
               MOD(TIMESTAMPDIFF(MINUTE,CONCAT(sit_in_date,' ',sit_in_time),CONCAT(time_out_date,' ',time_out)),60),'m')
    ELSE '—' END as duration
    FROM sit_in $pdf_where ORDER BY sit_in_date DESC, sit_in_time DESC LIMIT 500")->fetch_all(MYSQLI_ASSOC);

$conn->close();

$lab_labels     = json_encode(array_column($lab_data,'lab'));
$lab_values     = json_encode(array_column($lab_data,'c'));
$purpose_labels = json_encode(array_column($purpose_data,'purpose'));
$purpose_values = json_encode(array_column($purpose_data,'c'));
$monthly_labels = json_encode(array_keys($monthly_sitins));
$monthly_values = json_encode(array_values($monthly_sitins));
$daily_labels   = json_encode(array_map(fn($d)=>date('M d',strtotime($d['sit_in_date'])),$daily_data));
$daily_values   = json_encode(array_column($daily_data,'c'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports — Admin</title>
<link rel="stylesheet" href="admin_shared.css">
<link rel="icon" type="image/png" href="pictures/uclogo.png">
<!-- jsPDF + AutoTable for PDF generation -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<style>
.chart-box{position:relative;height:260px;}
.charts-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
@media(max-width:768px){.charts-grid{grid-template-columns:1fr;}}

/* PDF export cards */
.export-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:24px;}
@media(max-width:768px){.export-grid{grid-template-columns:1fr;}}
.export-card{background:var(--white);border-radius:var(--radius);box-shadow:var(--card-shadow);padding:24px;border-top:4px solid var(--blue);transition:all var(--transition);}
.export-card:hover{box-shadow:var(--card-shadow-hover);transform:translateY(-2px);}
.export-card:nth-child(2){border-top-color:#8b5cf6;}
.export-card-icon{font-size:36px;margin-bottom:12px;}
.export-card-title{font-size:17px;font-weight:700;color:var(--navy);margin-bottom:6px;}
.export-card-desc{font-size:13px;color:var(--gray-500);line-height:1.6;margin-bottom:16px;}
.export-card-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
.export-date-row{display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap;}
.export-date-row label{font-size:12px;font-weight:600;color:var(--gray-700);}
.export-date-row input{padding:7px 10px;border:1.5px solid var(--gray-100);border-radius:var(--radius-sm);font-size:13px;font-family:'Outfit',sans-serif;color:var(--navy);background:#fafbff;outline:none;transition:all var(--transition);}
.export-date-row input:focus{border-color:var(--blue);}

.btn-pdf{
    display:inline-flex;align-items:center;gap:8px;padding:10px 20px;
    background:linear-gradient(135deg,#ef4444,#dc2626);
    color:var(--white);border:none;border-radius:var(--radius-sm);
    font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;
    cursor:pointer;transition:all var(--transition);
}
.btn-pdf:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(239,68,68,.35);}
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
        <li><a href="manage_software.php">Manage Software</a></li>
        <li><a href="reservations.php">Reservations</a></li>
        <li><a href="reports.php" class="active">Reports</a></li>
        <li><a href="student_feedback.php">Feedback</a></li>
        <li><a href="logout.php" class="logout-btn">Log Out</a></li>
    </ul>
</nav>

<div class="page-container">

    <div class="page-header">
        <div>
            <div class="page-title">Reports &amp; Analytics</div>
            <div class="page-subtitle">Analytics summary and PDF report generation</div>
        </div>
        <button class="btn btn-primary no-print" onclick="window.print()">🖨 Print Page</button>
    </div>

    <!-- Summary Stats -->
    <div class="mini-stats">
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $total_students; ?></div><div class="mini-stat-label">Total Students</div></div>
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $total_sitins; ?></div><div class="mini-stat-label">Total Sit-ins</div></div>
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $month_sitins; ?></div><div class="mini-stat-label">This Month</div></div>
        <div class="mini-stat"><div class="mini-stat-value"><?php echo $zero_sessions; ?></div><div class="mini-stat-label">No Sessions Left</div></div>
    </div>

    <!-- ── PDF Export Section ── -->
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header"><div class="card-header-left">📄 Generate PDF Reports</div></div>
        <div class="card-body">
            <div class="export-grid">

                <!-- Student List Report -->
                <div class="export-card">
                    <div class="export-card-icon">👥</div>
                    <div class="export-card-title">Student List Report</div>
                    <div class="export-card-desc">
                        Export a complete list of all registered students including their ID number, full name, course, year level, email, and remaining sessions.
                    </div>
                    <div class="export-card-actions">
                        <button class="btn-pdf" onclick="generateStudentPDF()">
                            📥 Download PDF
                        </button>
                        <span style="font-size:12px;color:var(--gray-300);"><?php echo $total_students; ?> students</span>
                    </div>
                </div>

                <!-- Sit-in Logs Report -->
                <div class="export-card">
                    <div class="export-card-icon">📋</div>
                    <div class="export-card-title">Sit-in Logs Report</div>
                    <div class="export-card-desc">
                        Export sit-in session records with date, time, duration, lab, seat number and student details. Filter by date range.
                    </div>
                    <div class="export-date-row">
                        <label>From:</label>
                        <input type="date" id="logFrom">
                        <label>To:</label>
                        <input type="date" id="logTo" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="export-card-actions">
                        <button class="btn-pdf" onclick="generateLogsPDF()">
                            📥 Download PDF
                        </button>
                        <span style="font-size:12px;color:var(--gray-300);"><?php echo $total_sitins; ?> total records</span>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Charts -->
    <div class="charts-grid">
        <div class="card">
            <div class="card-header"><div class="card-header-left">Monthly Sit-ins (<?php echo date('Y'); ?>)</div></div>
            <div class="card-body"><div class="chart-box"><canvas id="monthlyChart"></canvas></div></div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-header-left">Daily Sit-ins (Last 14 Days)</div></div>
            <div class="card-body"><div class="chart-box"><canvas id="dailyChart"></canvas></div></div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-header-left">Sit-ins Per Laboratory</div></div>
            <div class="card-body"><div class="chart-box"><canvas id="labChart"></canvas></div></div>
        </div>
        <div class="card">
            <div class="card-header"><div class="card-header-left">Sit-ins By Purpose</div></div>
            <div class="card-body"><div class="chart-box"><canvas id="purposeChart"></canvas></div></div>
        </div>
    </div>

    <!-- Top Students -->
    <div class="card">
        <div class="card-header"><div class="card-header-left">Top 10 Most Active Students</div></div>
        <div class="card-body">
            <?php if(empty($top_students)): ?>
            <div class="empty-state"><div class="empty-icon">📊</div><div class="empty-title">No sit-in data yet</div></div>
            <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Rank</th><th>ID Number</th><th>Student Name</th><th>Total Sit-ins</th><th>Activity</th></tr></thead>
                    <tbody>
                        <?php $max_c=$top_students[0]['c']??1; ?>
                        <?php foreach($top_students as $i=>$ts): ?>
                        <tr>
                            <td><span class="badge <?php echo $i===0?'badge-warning':'badge-gray'; ?>">#<?php echo $i+1; ?></span></td>
                            <td class="td-bold"><?php echo htmlspecialchars($ts['id_number']); ?></td>
                            <td class="td-bold"><?php echo htmlspecialchars($ts['student_name']); ?></td>
                            <td><?php echo $ts['c']; ?> sessions</td>
                            <td>
                                <div style="background:var(--gray-100);border-radius:100px;height:8px;width:120px;overflow:hidden;">
                                    <div style="background:var(--blue);height:100%;width:<?php echo round($ts['c']/$max_c*100); ?>%;border-radius:100px;"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Hidden data tables for PDF generation -->
<div id="pdfData" style="display:none;">

    <!-- Student data -->
    <table id="studentTableData">
        <thead><tr><th>ID Number</th><th>Full Name</th><th>Course</th><th>Year</th><th>Email</th><th>Sessions Left</th><th>Registered</th></tr></thead>
        <tbody>
        <?php foreach($pdf_students as $st): ?>
        <tr>
            <td><?php echo htmlspecialchars($st['id_number']); ?></td>
            <td><?php echo htmlspecialchars($st['full_name']); ?></td>
            <td><?php echo htmlspecialchars($st['course']); ?></td>
            <td><?php echo htmlspecialchars($st['year_level']); ?></td>
            <td><?php echo htmlspecialchars($st['email']); ?></td>
            <td><?php echo $st['sessions']; ?></td>
            <td><?php echo date('M d, Y',strtotime($st['created_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Sit-in logs data -->
    <table id="logsTableData">
        <thead><tr><th>ID Number</th><th>Student Name</th><th>Purpose</th><th>Lab</th><th>Seat</th><th>Sessions</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Duration</th></tr></thead>
        <tbody>
        <?php foreach($pdf_logs as $lg): ?>
        <tr>
            <td><?php echo htmlspecialchars($lg['id_number']); ?></td>
            <td><?php echo htmlspecialchars($lg['student_name']); ?></td>
            <td><?php echo htmlspecialchars($lg['purpose']); ?></td>
            <td>Lab <?php echo htmlspecialchars($lg['lab']); ?></td>
            <td><?php echo htmlspecialchars($lg['seat_number']); ?></td>
            <td><?php echo $lg['remaining_session']; ?></td>
            <td><?php echo date('M d, Y',strtotime($lg['sit_in_date'])); ?></td>
            <td><?php echo date('h:i A',strtotime($lg['sit_in_time'])); ?></td>
            <td><?php echo $lg['time_out'] ? date('h:i A',strtotime($lg['time_out'])) : '—'; ?></td>
            <td><?php echo htmlspecialchars($lg['duration']); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
// ── Charts ────────────────────────────────────────────────────────────
const navy='#0a1628',blue='#1e6fe0',blueLight='#3b82f6',accent='#64b5f6';
const defaults={
    plugins:{legend:{display:false}},
    scales:{
        x:{grid:{color:'rgba(0,0,0,0.04)'},ticks:{font:{family:'Outfit',size:12},color:'#6b7a96'}},
        y:{grid:{color:'rgba(0,0,0,0.04)'},ticks:{font:{family:'Outfit',size:12},color:'#6b7a96'},beginAtZero:true}
    },responsive:true,maintainAspectRatio:false
};
new Chart(document.getElementById('monthlyChart'),{type:'bar',data:{labels:<?php echo $monthly_labels;?>,datasets:[{data:<?php echo $monthly_values;?>,backgroundColor:blue,borderRadius:6,borderSkipped:false}]},options:defaults});
new Chart(document.getElementById('dailyChart'),{type:'line',data:{labels:<?php echo $daily_labels;?>,datasets:[{data:<?php echo $daily_values;?>,borderColor:blue,backgroundColor:'rgba(30,111,224,0.08)',fill:true,tension:0.4,pointBackgroundColor:blue,pointRadius:4}]},options:defaults});
new Chart(document.getElementById('labChart'),{type:'bar',data:{labels:<?php echo $lab_labels;?>.map(l=>'Lab '+l),datasets:[{data:<?php echo $lab_values;?>,backgroundColor:[blue,blueLight,accent,'#3b82f6','#0ea5e9'],borderRadius:6,borderSkipped:false}]},options:{...defaults,indexAxis:'y'}});
new Chart(document.getElementById('purposeChart'),{type:'doughnut',data:{labels:<?php echo $purpose_labels;?>,datasets:[{data:<?php echo $purpose_values;?>,backgroundColor:['#1e6fe0','#3b82f6','#64b5f6','#0ea5e9','#0284c7','#0369a1','#075985','#0c4a6e','#172554'],borderWidth:2,borderColor:'#fff'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'right',labels:{font:{family:'Outfit',size:12},color:'#374357',padding:14}}}}});

// ── PDF Helpers ───────────────────────────────────────────────────────
function pdfHeader(doc, title, subtitle){
    const pageW = doc.internal.pageSize.getWidth();
    // Navy header bar
    doc.setFillColor(10,22,40);
    doc.rect(0,0,pageW,28,'F');
    // Title
    doc.setTextColor(255,255,255);
    doc.setFontSize(14); doc.setFont('helvetica','bold');
    doc.text('University of Cebu — CCS Sit-in Monitoring System', pageW/2, 11, {align:'center'});
    doc.setFontSize(10); doc.setFont('helvetica','normal');
    doc.text(title, pageW/2, 19, {align:'center'});
    // Subtitle / date
    doc.setFillColor(240,244,255);
    doc.rect(0,28,pageW,10,'F');
    doc.setTextColor(107,122,150); doc.setFontSize(9);
    doc.text(subtitle, 14, 35);
    doc.text('Generated: ' + new Date().toLocaleDateString('en-PH',{year:'numeric',month:'long',day:'numeric',hour:'2-digit',minute:'2-digit'}), pageW-14, 35, {align:'right'});
    doc.setTextColor(0,0,0);
    return 42; // y-start for content
}

function pdfFooter(doc){
    const pageCount = doc.internal.getNumberOfPages();
    const pageW = doc.internal.pageSize.getWidth();
    const pageH = doc.internal.pageSize.getHeight();
    for(let i=1;i<=pageCount;i++){
        doc.setPage(i);
        doc.setFillColor(10,22,40);
        doc.rect(0,pageH-10,pageW,10,'F');
        doc.setFontSize(8); doc.setTextColor(255,255,255);
        doc.text('CCS Sit-in Monitoring System — Confidential', 14, pageH-3.5);
        doc.text(`Page ${i} of ${pageCount}`, pageW-14, pageH-3.5, {align:'right'});
    }
}

function extractTableData(tableId){
    const tbl  = document.getElementById(tableId);
    const head = Array.from(tbl.querySelectorAll('thead th')).map(th=>th.textContent.trim());
    const body = Array.from(tbl.querySelectorAll('tbody tr')).map(tr=>
        Array.from(tr.querySelectorAll('td')).map(td=>td.textContent.trim())
    );
    return {head,body};
}

// ── Generate Student List PDF ─────────────────────────────────────────
function generateStudentPDF(){
    const {jsPDF} = window.jspdf;
    const doc = new jsPDF({orientation:'landscape',unit:'mm',format:'a4'});
    const y = pdfHeader(doc, 'STUDENT LIST REPORT', `Total Students: <?php echo $total_students; ?>`);
    const {head,body} = extractTableData('studentTableData');
    doc.autoTable({
        head:[head], body,
        startY: y,
        styles:{font:'helvetica',fontSize:8,cellPadding:3,lineColor:[232,237,245],lineWidth:.3},
        headStyles:{fillColor:[10,22,40],textColor:255,fontStyle:'bold',fontSize:8,cellPadding:4},
        alternateRowStyles:{fillColor:[247,249,255]},
        columnStyles:{
            0:{cellWidth:28},1:{cellWidth:44},2:{cellWidth:52},
            3:{cellWidth:18},4:{cellWidth:56},5:{cellWidth:20},6:{cellWidth:24}
        },
        margin:{left:10,right:10},
        didDrawPage: ()=>{}
    });
    pdfFooter(doc);
    doc.save(`CCS_Student_List_${new Date().toISOString().slice(0,10)}.pdf`);
}

// ── Generate Sit-in Logs PDF ──────────────────────────────────────────
function generateLogsPDF(){
    const fromVal = document.getElementById('logFrom').value;
    const toVal   = document.getElementById('logTo').value;

    const {jsPDF} = window.jspdf;
    const doc = new jsPDF({orientation:'landscape',unit:'mm',format:'a4'});

    let subtitle = 'Sit-in Session Records';
    if(fromVal || toVal){
        subtitle += ` | Period: ${fromVal||'All'} to ${toVal||'All'}`;
    }
    subtitle += ` | Total Records: <?php echo $total_sitins; ?>`;

    const y = pdfHeader(doc, 'SIT-IN LOGS REPORT', subtitle);
    const {head,body} = extractTableData('logsTableData');

    // Filter rows by date if set (client-side filter on visible data)
    let filteredBody = body;
    if(fromVal || toVal){
        const from = fromVal ? new Date(fromVal) : null;
        const to   = toVal   ? new Date(toVal)   : null;
        filteredBody = body.filter(row=>{
            // row[6] is date column "Mon DD, YYYY"
            const d = new Date(row[6]);
            if(isNaN(d.getTime())) return true;
            if(from && d < from) return false;
            if(to   && d > to)   return false;
            return true;
        });
    }

    doc.autoTable({
        head:[head], body: filteredBody,
        startY: y,
        styles:{font:'helvetica',fontSize:7,cellPadding:2.5,lineColor:[232,237,245],lineWidth:.3},
        headStyles:{fillColor:[10,22,40],textColor:255,fontStyle:'bold',fontSize:7.5,cellPadding:3.5},
        alternateRowStyles:{fillColor:[247,249,255]},
        columnStyles:{
            0:{cellWidth:24},1:{cellWidth:36},2:{cellWidth:28},3:{cellWidth:14},
            4:{cellWidth:12},5:{cellWidth:14},6:{cellWidth:22},7:{cellWidth:18},
            8:{cellWidth:18},9:{cellWidth:16}
        },
        margin:{left:8,right:8},
        didDrawPage:()=>{}
    });
    pdfFooter(doc);
    doc.save(`CCS_Sitin_Logs_${new Date().toISOString().slice(0,10)}.pdf`);
}
</script>
</body>
</html>
