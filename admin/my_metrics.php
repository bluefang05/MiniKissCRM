<?php
/**
 * my_metrics.php
 *
 * Performance metrics dashboard for sales agents.
 * Shows call stats, graphs, and latest interactions.
 *
 * Features:
 * - Calls Today
 * - Avg Call Duration
 * - Conversion Rate
 * - Total Talk Time
 * - Disposition breakdown (doughnut chart)
 * - Daily/hourly trends (line/bar charts)
 * - Export to Excel/CSV
 *
 * @package MiniKissCRM
 * @author  Enmanuel Domínguez
 */
require_once __DIR__.'/../lib/Auth.php';
require_once __DIR__.'/../lib/db.php';

// Authentication check
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$user_id = $user['id'];
$roles = $user['roles'] ?? [];

// Authorization check – allow admin, viewer, and sales
if (!array_intersect($roles, ['admin', 'viewer', 'sales'])) {
    header('Location: ../leads/list.php');
    exit;
}

/* ------------------------------------------------------------------ */
/* Tiny JSON API for Ajax requests                                   */
/* ------------------------------------------------------------------ */
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $response = ['error' => null, 'data' => []];
    try {
        $pdo = getPDO();

        // Date filter
        $condition = '';
        $bind = [];
        if (!empty($_GET['from']) && !empty($_GET['to'])) {
            $from = date('Y-m-d', strtotime($_GET['from']));
            $to   = date('Y-m-d', strtotime($_GET['to']));
            $condition = "i.created_at BETWEEN :from AND :to";
            $bind = [':from' => "$from 00:00:00", ':to' => "$to 23:59:59"];
        } else {
            $range = (int) ($_GET['range'] ?? 7);
            $range = in_array($range, [1, 7, 30], true) ? $range : 7;
            $condition = "i.created_at >= DATE_SUB(CURDATE(), INTERVAL $range DAY)";
        }

        // Base WHERE clause
        $baseWhere = "WHERE i.user_id = :user_id AND $condition";

        // KPI Query
        $sql = "
            SELECT 
                COUNT(DISTINCT CASE WHEN DATE(i.created_at)=CURDATE() THEN i.id END) AS calls_today,
                IFNULL(AVG(i.duration_seconds),0) AS avg_duration,
                COUNT(CASE WHEN d.name='Interested' THEN 1 END) AS calls_conv,
                COUNT(i.id) AS calls_tot,
                SUM(i.duration_seconds) AS total_talk_time
            FROM interactions i
            JOIN dispositions d ON d.id = i.disposition_id
            $baseWhere
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id] + $bind);
        $kpi = $stmt->fetch(PDO::FETCH_ASSOC);

        // Helper function
        $fn = function(string $q) use ($pdo, $baseWhere, $bind, $user_id) {
            $q = str_replace(':user_id', '?', $q);
            $q = str_replace('WHERE 1=1', $baseWhere, $q);
            $stmt = $pdo->prepare($q);
            $stmt->execute(array_values($bind));
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        };

        // Time-series data
        $dayData = $fn("SELECT DATE(i.created_at) AS d, COUNT(*) AS c FROM interactions i GROUP BY d ORDER BY d");
        $hourStmt = $pdo->query("
            SELECT HOUR(created_at) h, COUNT(*) c 
            FROM interactions 
            WHERE user_id = $user_id AND DATE(created_at)=CURDATE()
            GROUP BY h
        ");
        $hourRows = $hourStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $hourData = array_replace(array_fill(0, 24, 0), $hourRows); // Ensure all hours are present

        // Conversion rate
        $convRate = $kpi['calls_tot']
            ? round($kpi['calls_conv'] / $kpi['calls_tot'] * 100, 2)
            : 0;

        // Disposition breakdown
        $dispStmt = $pdo->prepare("
            SELECT d.name, COUNT(*) AS cnt
            FROM interactions i
            JOIN dispositions d ON i.disposition_id = d.id
            $baseWhere
            GROUP BY d.name
        ");
        $dispStmt->execute([':user_id' => $user_id] + $bind);
        $dispositions = $dispStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Latest calls table
        $latestCallsStmt = $pdo->prepare("
            SELECT i.created_at, CONCAT(l.first_name,' ',l.last_name) AS lead_name, d.name AS disposition, i.duration_seconds
            FROM interactions i
            JOIN leads l ON l.id = i.lead_id
            JOIN dispositions d ON d.id = i.disposition_id
            WHERE i.user_id = ?
            ORDER BY i.created_at DESC
            LIMIT 6
        ");
        $latestCallsStmt->execute([$user_id]);
        $latest_calls = $latestCallsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Build response
        $response['data'] = [
            'totals' => [
                'today' => (int)$kpi['calls_today'],
                'avg' => round((float)$kpi['avg_duration'], 1),
                'talk_time' => (int)$kpi['total_talk_time'],
                'rate' => $convRate,
            ],
            'day' => $dayData,
            'hour' => $hourData,
            'dispositions' => $dispositions,
            'latest_calls' => $latest_calls
        ];
    } catch (PDOException $e) {
        error_log('Database Error: ' . $e->getMessage());
        $response['error'] = 'Database Error: ' . $e->getMessage();
    } catch (Exception $e) {
        error_log('General Error: ' . $e->getMessage());
        $response['error'] = 'General Error: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}
/* ------------------------------------------------------------------ */
/* Fetch latest calls statically (for initial HTML load)               */
/* ------------------------------------------------------------------ */
$pdo = getPDO();
$stmt = $pdo->prepare("
    SELECT i.created_at, CONCAT(l.first_name,' ',l.last_name) AS lead_name, d.name AS disposition, i.duration_seconds
    FROM interactions i
    JOIN leads l ON l.id = i.lead_id
    JOIN dispositions d ON d.id = i.disposition_id
    WHERE i.user_id = ?
    ORDER BY i.created_at DESC
    LIMIT 6
");
$stmt->execute([$user_id]);
$latest = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>My Metrics – MiniKissCRM</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="./../assets/css/app.css">
<style>
:root {
    --c-bg: #fff;
    --c-fg: #222;
    --c-brand: #2c5d4a;
    --c-brand-light: #3d7a63;
    --c-sub: #6c757d;
    --c-surface: #f8faf9;
    --c-border: #e0e6e3;
    --c-card-shadow: rgba(0, 0, 0, 0.05);
    --c-success: #28a745;
    --c-warning: #ffc107;
    --c-danger: #dc3545;
    --c-info: #17a2b8;
}

body {
    margin: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: var(--c-surface);
    color: var(--c-fg);
    line-height: 1.6;
}

.container {
    max-width: 1400px;
    margin: auto;
    padding: 1.5rem;
}

h1, h2, h3 {
    margin: 0 0 .8rem;
    font-weight: 600;
    color: var(--c-brand);
}

h1 {
    font-size: 1.8rem;
}

h2 {
    font-size: 1.4rem;
    margin-bottom: 1rem;
}

h3 {
    font-size: 1.1rem;
    color: var(--c-sub);
    margin-bottom: 0.5rem;
}

a.btn {
    display: inline-flex;
    align-items: center;
    padding: .5rem 1rem;
    border-radius: 6px;
    background: var(--c-brand);
    color: #fff;
    text-decoration: none;
    font-weight: 500;
    transition: all 0.2s ease;
    margin-right: 0.5rem;
    margin-bottom: 1rem;
    border: 1px solid var(--c-brand-light);
}

a.btn:hover {
    background: var(--c-brand-light);
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.grid {
    display: grid;
    gap: 1.5rem;
}

.cards {
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
}

.card {
    background: var(--c-bg);
    padding: 1.2rem 1.5rem;
    border-radius: 10px;
    box-shadow: 0 4px 6px var(--c-card-shadow);
    text-align: center;
    border: 1px solid var(--c-border);
    transition: transform 0.3s ease;
}

.card:hover {
    transform: translateY(-5px);
}

.card h3 {
    margin: 0 0 .5rem;
    font-size: 1rem;
    color: var(--c-sub);
    font-weight: 500;
}

.card p {
    margin: 0;
    font-size: 2rem;
    font-weight: 700;
    color: var(--c-brand);
}

.card .subtext {
    font-size: 0.9rem;
    color: var(--c-sub);
    margin-top: 0.3rem;
}

.chart-container {
    background: var(--c-bg);
    border-radius: 10px;
    padding: 1.2rem;
    box-shadow: 0 4px 6px var(--c-card-shadow);
    border: 1px solid var(--c-border);
    height: 300px;
    position: relative;
}

.chart-container h3 {
    position: absolute;
    top: 1rem;
    left: 1.5rem;
    z-index: 10;
    background: var(--c-bg);
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-size: 0.9rem;
    color: var(--c-sub);
}

.controls {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
    margin: 1.5rem 0;
    padding: 0.8rem 1rem;
    background: var(--c-bg);
    border-radius: 8px;
    box-shadow: 0 2px 4px var(--c-card-shadow);
    border: 1px solid var(--c-border);
}

.range-box {
    display: flex;
    align-items: center;
    gap: .5rem;
    flex-wrap: wrap;
}

.range-box input {
    padding: .45rem .7rem;
    border-radius: 6px;
    border: 1px solid var(--c-border);
    font-size: 0.9rem;
}

.controls button {
    padding: .45rem .8rem;
    border-radius: 6px;
    background: var(--c-surface);
    color: var(--c-sub);
    border: 1px solid var(--c-border);
    cursor: pointer;
    font-size: 0.9rem;
    transition: all 0.2s ease;
}

.controls button.active, .controls button:hover {
    background: var(--c-brand);
    color: white;
    border-color: var(--c-brand-light);
}

#applyCustom {
    background: var(--c-brand);
    color: white;
    border-color: var(--c-brand-light);
}

#applyCustom:hover {
    background: var(--c-brand-light);
}

.badge-online {
    background: var(--c-success);
    color: #fff;
    padding: .15rem .5rem;
    border-radius: 4px;
    margin-left: .4rem;
    font-size: .8rem;
}

.export-buttons {
    display: flex;
    gap: 0.5rem;
    margin-top: 0;
    margin-left: auto;
}

.export-buttons button {
    background: var(--c-info);
    color: white;
    border: none;
    padding: 0.45rem 0.8rem;
}

.export-buttons button:hover {
    background: #138496;
}

.charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.latest-calls-container {
    background: var(--c-bg);
    border-radius: 10px;
    padding: 1.5rem;
    box-shadow: 0 4px 6px var(--c-card-shadow);
    border: 1px solid var(--c-border);
    overflow: hidden;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 0.5rem;
}

table th {
    background: var(--c-surface);
    text-align: left;
    padding: 0.8rem 1rem;
    font-weight: 600;
    color: var(--c-sub);
    font-size: 0.9rem;
    border-bottom: 1px solid var(--c-border);
}

table td {
    padding: 0.8rem 1rem;
    border-bottom: 1px solid var(--c-border);
    font-size: 0.95rem;
}

table tr:last-child td {
    border-bottom: none;
}

table tr:hover td {
    background: rgba(44, 93, 74, 0.03);
}

.duration-cell {
    font-weight: 500;
    color: var(--c-brand);
}

@media screen and (max-width: 1100px) {
    .charts-grid {
        grid-template-columns: 1fr;
    }
    
    .chart-container {
        height: 280px;
    }
}

@media screen and (max-width: 768px) {
    .container {
        padding: 1rem;
    }
    
    .cards {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    }
    
    .controls {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .export-buttons {
        margin-left: 0;
        width: 100%;
        justify-content: flex-end;
    }
    
    .chart-container {
        height: 250px;
        padding: 1rem;
    }
    
    .chart-container h3 {
        left: 1rem;
    }
}

@media screen and (max-width: 480px) {
    .cards {
        grid-template-columns: 1fr;
    }
    
    .card {
        padding: 1rem;
    }
    
    table th, table td {
        padding: 0.6rem;
        font-size: 0.85rem;
    }
}
</style>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script> 
<!-- Flatpickr for date range picker -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script> 
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css"> 
</head>
<body>
<div class="container">

  <div style="display:flex; align-items:center; flex-wrap:wrap; margin-bottom:1.5rem;">
    <a href="../leads/list.php" class="btn">← Leads</a>
    <?php if (in_array($roles[0], ['admin'])): ?>
      <a href="dashboard.php" class="btn">📊 Team Dashboard</a>
    <?php endif; ?>
    <div style="margin-left:auto; display:flex; align-items:center;">
      <span style="font-size:0.9rem; color:var(--c-sub);">Logged in as: <strong><?= htmlspecialchars($user['name']) ?></strong></span>
    </div>
  </div>

  <h1>My Performance Metrics</h1>
  
  <!-- Summary Cards -->
  <div class="grid cards">
    <div class="card">
      <h3 title="Number of calls made today.">Calls Today</h3>
      <p id="cToday">…</p>
      <div class="subtext">vs. yesterday</div>
    </div>
    <div class="card">
      <h3 title="Average duration of completed calls.">Avg Call Duration</h3>
      <p id="cAvgDur">…</p>
      <div class="subtext">per call</div>
    </div>
    <div class="card">
      <h3 title="Percentage of successful calls out of total interactions.">Conversion Rate</h3>
      <p id="cRate">… %</p>
      <div class="subtext">successful calls</div>
    </div>
    <div class="card">
      <h3 title="Total time spent on calls.">Total Talk Time</h3>
      <p id="cTalkTime">…</p>
      <div class="subtext">this period</div>
    </div>
  </div>

  <!-- Controls -->
  <div class="controls">
    <div class="range-box">
      <button class="rng active" data-r="today">Today</button>
      <button class="rng" data-r="week">This Week</button>
      <button class="rng" data-r="month">This Month</button>
      <button class="rng" data-r="7">7 Days</button>
      <button class="rng" data-r="30">30 Days</button>
      <div style="display:flex; align-items:center; gap:.5rem;">
        <input id="pick-from" type="text" placeholder="From">
        <span>→</span>
        <input id="pick-to" type="text" placeholder="To">
        <button id="applyCustom">Apply</button>
      </div>
    </div>
    <div class="export-buttons">
      <button onclick="window.location.href='export.php?agent=<?= $user_id ?>&range='+range+'&from='+document.getElementById('pick-from').value+'&to='+document.getElementById('pick-to').value;">
        Export Data
      </button>
    </div>
  </div>
  
  <!-- Charts Grid -->
  <div class="charts-grid">
    <div class="chart-container">
      <h3>Calls by Day</h3>
      <canvas id="cDay"></canvas>
    </div>
    <div class="chart-container">
      <h3>Calls by Hour</h3>
      <canvas id="cHour"></canvas>
    </div>
    <div class="chart-container">
      <h3>Disposition Breakdown</h3>
      <canvas id="cDisposition"></canvas>
    </div>
  </div>

  <!-- Latest Calls Table -->
  <div class="latest-calls-container">
    <h2>Latest Calls</h2>
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Lead</th>
          <th>Disposition</th>
          <th>Duration</th>
        </tr>
      </thead>
      <tbody id="latest-calls">
        <tr>
          <td colspan="4" style="text-align:center; padding: 2rem;">Loading call data...</td>
        </tr>
      </tbody>
    </table>
  </div>

</div>

<!-- JavaScript -->
<script>
const Num = x => Intl.NumberFormat().format(x);
function formatDuration(seconds) {
    if (seconds < 60) return `${seconds} sec`;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m} min ${s > 0 ? `${s} sec` : ''}`;
}

let range = 'today', autoRefresh = true;

// Chart Instances
const cDay = new Chart(document.getElementById('cDay'), {
    type: 'line',
    data: { 
        labels: [], 
        datasets: [{ 
            label: 'Calls',
            data: [],
            borderColor: '#2c5d4a',
            backgroundColor: 'rgba(44, 93, 74, 0.1)',
            borderWidth: 2,
            pointBackgroundColor: '#fff',
            pointBorderColor: '#2c5d4a',
            pointRadius: 4,
            tension: 0.3,
            fill: true
        }] 
    },
    options: { 
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(0, 0, 0, 0.05)' }
            },
            x: {
                grid: { display: false }
            }
        }
    }
});

const cHour = new Chart(document.getElementById('cHour'), {
    type: 'bar',
    data: {
        labels: [...Array(24).keys()].map(h => String(h).padStart(2, '0')),
        datasets: [{ 
            label: 'Calls per Hour', 
            data: [],
            backgroundColor: 'rgba(44, 93, 74, 0.7)',
            borderColor: '#2c5d4a',
            borderWidth: 1
        }]
    },
    options: { 
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(0, 0, 0, 0.05)' }
            },
            x: {
                grid: { display: false }
            }
        }
    }
});

const cDisposition = new Chart(document.getElementById('cDisposition'), {
    type: 'doughnut',
    data: { 
        labels: [], 
        datasets: [{ 
            data: [],
            backgroundColor: [
                '#2c5d4a',
                '#3d7a63',
                '#4e967c',
                '#5fb395',
                '#70d0ae'
            ],
            borderWidth: 1
        }] 
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { 
                position: 'right',
                labels: {
                    padding: 15,
                    usePointStyle: true,
                    pointStyle: 'circle'
                }
            }
        },
        cutout: '60%'
    }
});

function fetchData() {
    const url = new URL(window.location.pathname, window.location.origin);
    url.searchParams.set('api', '1');

    const from = document.getElementById('pick-from').value;
    const to = document.getElementById('pick-to').value;

    if (from && to) {
        url.searchParams.set('from', from);
        url.searchParams.set('to', to);
    } else {
        url.searchParams.set('range', range);
    }

    fetch(url)
        .then(r => r.json())
        .then(d => {
            if (d.error) {
                console.error('API Error:', d.error);
                document.getElementById('cToday').textContent = 'Error!';
            } else {
                updateUI(d.data);
            }
        })
        .catch(e => {
            console.error('Fetch error:', e);
            document.getElementById('cToday').textContent = 'Network Error!';
        });
}

function updateUI(data) {
    // Update summary cards
    document.getElementById('cToday').textContent = Num(data.totals.today);
    document.getElementById('cAvgDur').textContent = formatDuration(Math.round(data.totals.avg));
    document.getElementById('cRate').textContent = data.totals.rate + '%';
    document.getElementById('cTalkTime').textContent = formatDuration(Math.round(data.totals.talk_time));

    // Update charts
    cDay.data.labels = Object.keys(data.day);
    cDay.data.datasets[0].data = Object.values(data.day);
    cDay.update();

    cHour.data.datasets[0].data = Object.values(data.hour);
    cHour.update();

    cDisposition.data.labels = Object.keys(data.dispositions);
    cDisposition.data.datasets[0].data = Object.values(data.dispositions);
    cDisposition.update();

    // Latest calls table
    const tbody = document.getElementById('latest-calls');
    tbody.innerHTML = '';
    
    if (data.latest_calls.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="4" style="text-align:center; padding: 2rem;">
                    No calls found in the selected period
                </td>
            </tr>
        `;
        return;
    }
    
    data.latest_calls.forEach(row => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${new Date(row.created_at).toLocaleString()}</td>
            <td>${row.lead_name}</td>
            <td>${row.disposition}</td>
            <td class="duration-cell">${row.duration_seconds ? formatDuration(row.duration_seconds) : '–'}</td>
        `;
        tbody.appendChild(tr);
    });
}

// Range buttons
document.querySelectorAll('.rng').forEach(btn => {
    btn.onclick = () => {
        document.querySelectorAll('.rng').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        range = btn.dataset.r;
        fetchData();
    };
});

// Date pickers
flatpickr("#pick-from", { 
    dateFormat: "Y-m-d",
    altInput: true,
    altFormat: "M j, Y",
    allowInput: true
});

flatpickr("#pick-to", { 
    dateFormat: "Y-m-d",
    altInput: true,
    altFormat: "M j, Y",
    allowInput: true
});

document.getElementById('applyCustom').onclick = () => {
    document.querySelectorAll('.rng').forEach(b => b.classList.remove('active'));
    range = 'custom';
    fetchData();
};

// Initial load
fetchData();
setInterval(() => {
    if (autoRefresh) fetchData();
}, 15000);
</script>
</body>
</html>