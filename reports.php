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
$week_sitins     = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE sit_in_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
$month_sitins    = $conn->query("SELECT COUNT(*) as c FROM sit_in WHERE MONTH(sit_in_date)=MONTH(CURDATE()) AND YEAR(sit_in_date)=YEAR(CURDATE())")->fetch_assoc()['c'];
$zero_sessions   = $conn->query("SELECT COUNT(*) as c FROM students WHERE sessions = 0")->fetch_assoc()['c'];

// Sit-ins per lab
$lab_data = $conn->query("SELECT lab, COUNT(*) as c FROM sit_in GROUP BY lab ORDER BY c DESC")->fetch_all(MYSQLI_ASSOC);

// Sit-ins per purpose
$purpose_data = $conn->query("SELECT purpose, COUNT(*) as c FROM sit_in GROUP BY purpose ORDER BY c DESC")->fetch_all(MYSQLI_ASSOC);

// Daily sit-ins for last 14 days
$daily_data = $conn->query("SELECT sit_in_date, COUNT(*) as c FROM sit_in WHERE sit_in_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY sit_in_date ORDER BY sit_in_date ASC")->fetch_all(MYSQLI_ASSOC);

// Top 10 students by sit-in count
$top_students = $conn->query("SELECT student_name, id_number, COUNT(*) as c FROM sit_in GROUP BY id_number, student_name ORDER BY c DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);

// Monthly sit-ins for current year
$monthly_sitins = array_fill_keys(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'], 0);
$months_map = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
$res = $conn->query("SELECT MONTH(sit_in_date) as m, COUNT(*) as c FROM sit_in WHERE YEAR(sit_in_date)=YEAR(CURDATE()) GROUP BY MONTH(sit_in_date)");
while($row = $res->fetch_assoc()) $monthly_sitins[$months_map[$row['m']]] = $row['c'];

$conn->close();

$lab_labels    = json_encode(array_column($lab_data, 'lab'));
$lab_values    = json_encode(array_column($lab_data, 'c'));
$purpose_labels = json_encode(array_column($purpose_data, 'purpose'));
$purpose_values = json_encode(array_column($purpose_data, 'c'));
$monthly_labels = json_encode(array_keys($monthly_sitins));
$monthly_values = json_encode(array_values($monthly_sitins));

// Daily chart
$daily_labels = []; $daily_values = [];
foreach($daily_data as $d){
    $daily_labels[] = date('M d', strtotime($d['sit_in_date']));
    $daily_values[] = $d['c'];
}
$daily_labels_json = json_encode($daily_labels);
$daily_values_json = json_encode($daily_values);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports — Admin</title>
<link rel="stylesheet" href="admin_shared.css">
<link rel="icon" type="image/png" href="pictures/uclogo.png">
<style>
.chart-box { position: relative; height: 260px; }
.charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
@media(max-width:768px){ .charts-grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>

<nav class="dashboard-navbar">
    <div class="dashboard-left">
        <img class="admin-logo" src="pictures/uclogo.png" alt="Logo">
        <span class="admin-title">Admin Dashboard</span>
    </div>
    <ul class="dashboard-right">
        <li><a href="admin_dashboard.php">Dashboard</a></li>
        <li><a href="manage_students.php">Manage Students</a></li>
        <li><a href="sitin_logs.php">Sit-in Logs</a></li>
        <li><a href="reservations.php">Reservations</a></li>
        <li><a href="reports.php" class="active">Reports</a></li>
        <li><a href="student_feedback.php">Feedback</a></li>
        <li><a href="logout.php" class="logout-btn">Log Out</a></li>
    </ul>
</nav>

<div class="page-container">

    <div class="page-header">
        <div>
            <div class="page-title">Reports</div>
            <div class="page-subtitle">Analytics and summary of laboratory usage</div>
        </div>
        <button class="btn btn-primary no-print" onclick="window.print()">🖨 Print Report</button>
    </div>

    <!-- Summary Stats -->
    <div class="mini-stats">
        <div class="mini-stat">
            <div class="mini-stat-value"><?php echo $total_students; ?></div>
            <div class="mini-stat-label">Total Students</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value"><?php echo $total_sitins; ?></div>
            <div class="mini-stat-label">Total Sit-ins</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value"><?php echo $month_sitins; ?></div>
            <div class="mini-stat-label">This Month</div>
        </div>
        <div class="mini-stat">
            <div class="mini-stat-value"><?php echo $zero_sessions; ?></div>
            <div class="mini-stat-label">No Sessions Left</div>
        </div>
    </div>

    <!-- Charts Grid -->
    <div class="charts-grid">

        <!-- Monthly Sit-ins -->
        <div class="card">
            <div class="card-header"><div class="card-header-left">Monthly Sit-ins (<?php echo date('Y'); ?>)</div></div>
            <div class="card-body">
                <div class="chart-box"><canvas id="monthlyChart"></canvas></div>
            </div>
        </div>

        <!-- Daily Sit-ins -->
        <div class="card">
            <div class="card-header"><div class="card-header-left">Daily Sit-ins (Last 14 Days)</div></div>
            <div class="card-body">
                <div class="chart-box"><canvas id="dailyChart"></canvas></div>
            </div>
        </div>

        <!-- Sit-ins per Lab -->
        <div class="card">
            <div class="card-header"><div class="card-header-left">Sit-ins Per Laboratory</div></div>
            <div class="card-body">
                <div class="chart-box"><canvas id="labChart"></canvas></div>
            </div>
        </div>

        <!-- Sit-ins per Purpose -->
        <div class="card">
            <div class="card-header"><div class="card-header-left">Sit-ins By Purpose</div></div>
            <div class="card-body">
                <div class="chart-box"><canvas id="purposeChart"></canvas></div>
            </div>
        </div>

    </div>

    <!-- Top Students Table -->
    <div class="card">
        <div class="card-header"><div class="card-header-left">Top 10 Most Active Students</div></div>
        <div class="card-body">
            <?php if(empty($top_students)): ?>
            <div class="empty-state">
                <div class="empty-icon">📊</div>
                <div class="empty-title">No sit-in data yet</div>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>ID Number</th>
                            <th>Student Name</th>
                            <th>Total Sit-ins</th>
                            <th>Activity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $max_c = $top_students[0]['c'] ?? 1; ?>
                        <?php foreach($top_students as $i => $ts): ?>
                        <tr>
                            <td><span class="badge <?php echo $i === 0 ? 'badge-warning' : 'badge-gray'; ?>">#<?php echo $i+1; ?></span></td>
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

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
const navy = '#0a1628';
const blue = '#1e6fe0';
const blueLight = '#3b82f6';
const accent = '#64b5f6';

const defaults = {
    plugins: { legend: { display: false } },
    scales: {
        x: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { family: 'Outfit', size: 12 }, color: '#6b7a96' } },
        y: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { family: 'Outfit', size: 12 }, color: '#6b7a96' }, beginAtZero: true }
    },
    responsive: true,
    maintainAspectRatio: false
};

new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: {
        labels: <?php echo $monthly_labels; ?>,
        datasets: [{
            data: <?php echo $monthly_values; ?>,
            backgroundColor: blue,
            borderRadius: 6,
            borderSkipped: false
        }]
    },
    options: defaults
});

new Chart(document.getElementById('dailyChart'), {
    type: 'line',
    data: {
        labels: <?php echo $daily_labels_json; ?>,
        datasets: [{
            data: <?php echo $daily_values_json; ?>,
            borderColor: blue,
            backgroundColor: 'rgba(30,111,224,0.08)',
            fill: true,
            tension: 0.4,
            pointBackgroundColor: blue,
            pointRadius: 4
        }]
    },
    options: defaults
});

new Chart(document.getElementById('labChart'), {
    type: 'bar',
    data: {
        labels: <?php echo $lab_labels; ?>.map(l => 'Lab ' + l),
        datasets: [{
            data: <?php echo $lab_values; ?>,
            backgroundColor: [blue, blueLight, accent, '#3b82f6', '#0ea5e9'],
            borderRadius: 6,
            borderSkipped: false
        }]
    },
    options: { ...defaults, indexAxis: 'y' }
});

new Chart(document.getElementById('purposeChart'), {
    type: 'doughnut',
    data: {
        labels: <?php echo $purpose_labels; ?>,
        datasets: [{
            data: <?php echo $purpose_values; ?>,
            backgroundColor: ['#1e6fe0','#3b82f6','#64b5f6','#0ea5e9','#0284c7','#0369a1','#075985','#0c4a6e','#172554'],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'right', labels: { font: { family: 'Outfit', size: 12 }, color: '#374357', padding: 14 } } }
    }
});
</script>

</body>
</html>
