<?php
/**
 * dashboard.php
 *
 * Admin dashboard showing performance metrics for all users.
 * Allows filtering by user.
 *
 * Features:
 * - Switch between users
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
require_once __DIR__.'./../lib/Auth.php';
require_once __DIR__.'./../lib/db.php';

// Authentication check
if (!Auth::check()) {
    header('Location: ./../auth/login.php');
    exit;
}

$user = Auth::user();
$roles = $user['roles'] ?? [];

// Authorization check – only allow admin
if (!in_array('admin', $roles)) {
    header('Location: ./../leads/list.php');
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

        // Get user_id from query param
        $user_id = !empty($_GET['user']) ? (int)$_GET['user'] : null;

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
        $baseWhere = "WHERE ";
        if ($user_id) {
            $baseWhere .= "i.user_id = :user_id AND ";
        }
        $baseWhere .= $condition;

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
        $params = $user_id ? [':user_id' => $user_id] : [];
        $params = array_merge($params, $bind);
        $stmt->execute($params);
        $kpi = $stmt->fetch(PDO::FETCH_ASSOC);

        // Day-wise data
        $daySql = "
            SELECT DATE(i.created_at) AS d, COUNT(*) AS c 
            FROM interactions i
            $baseWhere
            GROUP BY d 
            ORDER BY d
        ";
        $dayStmt = $pdo->prepare($daySql);
        $dayStmt->execute($params);
        $dayData = $dayStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Hour-wise data
        $hourSql = "
            SELECT HOUR(i.created_at) AS h, COUNT(*) AS c
            FROM interactions i
            $baseWhere
            GROUP BY h
            ORDER BY h
        ";
        $hourStmt = $pdo->prepare($hourSql);
        $hourStmt->execute($params);
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
        $dispStmt->execute($params);
        $dispositions = $dispStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Latest calls table
        $latestCallsStmt = $pdo->prepare("
            SELECT i.created_at, CONCAT(l.first_name,' ',l.last_name) AS lead_name, 
                   d.name AS disposition, i.duration_seconds, u.name AS agent
            FROM interactions i
            JOIN leads l ON l.id = i.lead_id
            JOIN dispositions d ON d.id = i.disposition_id
            JOIN users u ON u.id = i.user_id
            $baseWhere
            ORDER BY i.created_at DESC
            LIMIT 6
        ");
        $latestCallsStmt->execute($params);
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
$stmt = $pdo->query("SELECT id, name FROM users ORDER BY name");
$users = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Team Dashboard – MiniKissCRM</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="./../assets/css/app.css">
<style>
.grid.cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.card {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    padding: 1.25rem;
    text-align: center;
}
.card h3 {
    margin-top: 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: #555;
}
.card p {
    font-size: 1.75rem;
    font-weight: 700;
    margin: 0.5rem 0;
}
.card .subtext {
    font-size: 0.85rem;
    color: #777;
}
.controls {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.range-box {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
}
.range-box button.rng {
    background: #f0f0f0;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 0.5rem 0.75rem;
    cursor: pointer;
    font-size: 0.85rem;
}
.range-box button.rng.active {
    background: #4a86e8;
    color: white;
    border-color: #4a86e8;
}
.range-box input {
    padding: 0.5rem;
    border: 1px solid #ddd;
    border-radius: 4px;
    width: 120px;
}
.range-box button#applyCustom {
    padding: 0.5rem 1rem;
    background: #4a86e8;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}
.charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}
.chart-container {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    padding: 1.25rem;
}
.chart-container h3 {
    margin-top: 0;
    margin-bottom: 1rem;
    font-size: 1.2rem;
}
.chart-container canvas {
    width: 100% !important;
    height: 300px !important;
}
.latest-calls-container {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    padding: 1.5rem;
    margin-bottom: 2rem;
}
.latest-calls-container h2 {
    margin-top: 0;
    margin-bottom: 1rem;
    font-size: 1.3rem;
}
table {
    width: 100%;
    border-collapse: collapse;
}
table th, table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid #eee;
}
table th {
    font-weight: 600;
    color: #555;
}
table tr:hover td {
    background-color: #f9f9f9;
}
.duration-cell {
    font-family: monospace;
}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script> 
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script> 
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css"> 
</head>
<body>
<div class="container">
  <div style="display:flex; align-items:center; flex-wrap:wrap; margin-bottom:1.5rem;">
    <a href="./../leads/list.php" class="btn">← Leads</a>
    <select id="userSelect" style="margin-left:auto; padding:.45rem .7rem; font-size:.9rem;">
      <option value="">All Users</option>
      <?php foreach ($users as $id => $name): ?>
        <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
      <?php endforeach ?>
    </select>
  </div>
  <h1>Team Performance Dashboard</h1>

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
      <button class="rng active" data-r="1">Today</button>
      <button class="rng" data-r="7">This Week</button>
      <button class="rng" data-r="30">This Month</button>
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
      <button id="exportBtn">Export Data</button>
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
          <th>Agent</th>
          <th>Disposition</th>
          <th>Duration</th>
        </tr>
      </thead>
      <tbody id="latest-calls">
        <tr>
          <td colspan="5" style="text-align:center; padding: 2rem;">Loading call data...</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- JavaScript -->
<script>
const Num = x => Intl.NumberFormat().format(x);
function formatDuration(seconds) {
    if (!seconds) return '–';
    if (seconds < 60) return `${seconds} sec`;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m} min ${s > 0 ? `${s} sec` : ''}`.trim();
}
let range = '1', autoRefresh = true;

// Initialize Charts
const cDay = new Chart(document.getElementById('cDay'), {
    type: 'line',
    data: {
        labels: [],
        datasets: [{
            label: 'Calls',
            data: [],
            borderColor: '#4a86e8',
            backgroundColor: 'rgba(74, 134, 232, 0.1)',
            tension: 0.3,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true }
        }
    }
});

const cHour = new Chart(document.getElementById('cHour'), {
    type: 'bar',
    data: {
        labels: Array.from({length: 24}, (_, i) => `${i}:00`),
        datasets: [{
            label: 'Calls',
            data: Array(24).fill(0),
            backgroundColor: '#4a86e8'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true }
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
                '#4a86e8', '#e69138', '#6aa84f', '#cc0000', 
                '#674ea7', '#3d85c6', '#f6b26b', '#b6d7a8'
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'right' }
        }
    }
});

function fetchData(user_id = null) {
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
    if (user_id !== null) {
        url.searchParams.set('user', user_id);
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
    if (!data.latest_calls || data.latest_calls.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" style="text-align:center; padding: 2rem;">
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
            <td>${row.lead_name || 'Unknown'}</td>
            <td>${row.agent || 'Unknown'}</td>
            <td>${row.disposition || 'Unknown'}</td>
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
        document.getElementById('pick-from').value = '';
        document.getElementById('pick-to').value = '';
        fetchData(document.getElementById('userSelect').value);
    };
});

// Date pickers
flatpickr("#pick-from", { dateFormat: "Y-m-d", altInput: true, altFormat: "M j, Y", allowInput: true });
flatpickr("#pick-to", { dateFormat: "Y-m-d", altInput: true, altFormat: "M j, Y", allowInput: true });
document.getElementById('applyCustom').onclick = () => {
    document.querySelectorAll('.rng').forEach(b => b.classList.remove('active'));
    range = 'custom';
    fetchData(document.getElementById('userSelect').value);
};

// User selection
document.getElementById('userSelect').onchange = () => {
    fetchData(document.getElementById('userSelect').value);
};

// Export button
document.getElementById('exportBtn').onclick = () => {
    const user = document.getElementById('userSelect').value;
    const from = document.getElementById('pick-from').value;
    const to = document.getElementById('pick-to').value;
    const url = `export.php?agent=${user}&range=${range}&from=${from}&to=${to}`;
    window.location.href = url;
};

// Initial load
fetchData();
setInterval(() => {
    if (autoRefresh) fetchData(document.getElementById('userSelect').value);
}, 15000);
</script>
</body>
</html>