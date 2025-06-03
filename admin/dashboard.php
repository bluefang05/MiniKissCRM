<?php
// admin/dashboard.php – rev-4, enhanced & secure
require_once __DIR__.'/../lib/Auth.php';
require_once __DIR__.'/../lib/db.php';
// Authentication check
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}
$user = Auth::user();
$roles = $user['roles'] ?? [];
// Authorization check
if (!array_intersect($roles, ['admin','viewer'])) {
    header('Location: ../leads/list.php');
    exit;
}
/* ------------------------------------------------------------------ */
/* Tiny JSON API for Ajax requests                                   */
/* ------------------------------------------------------------------ */
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $response = ['error' => null, 'data' => []]; // Initialize response structure
    try {
        $pdo = getPDO();
        /* ---- Date filter (either custom or preset range) --------------- */
        $condition = '';
        $bind      = [];
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
        /* ---- KPI Query ------------------------------------------------- */
        $sql = "
            SELECT 
                COUNT(DISTINCT l.id)                                        AS leads_tot,
                COUNT(DISTINCT CASE WHEN DATE(i.created_at)=CURDATE() THEN i.id END)    AS calls_today,
                COUNT(DISTINCT CASE WHEN u.status='active' THEN u.id END)       AS users_act,
                COUNT(i.id)                                                 AS calls_tot,
                COUNT(CASE WHEN d.name='Interested' THEN 1 END)                 AS calls_conv,
                ROUND(IFNULL(AVG(i.duration_seconds), 0))                               AS avg_duration,
                MAX(i.duration_seconds)                                         AS max_duration,
                MIN(i.duration_seconds)                                         AS min_duration,
                COUNT(DISTINCT CASE WHEN i.created_at>=NOW()-INTERVAL 15 MINUTE 
                                     THEN u.id END)                             AS online_users
            FROM interactions i
            JOIN users u ON u.id = i.user_id
            JOIN leads l ON l.id = i.lead_id
            JOIN dispositions d ON d.id = i.disposition_id
            WHERE $condition
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $kpi = $stmt->fetch(PDO::FETCH_ASSOC);
        /* ---- Helper function for repeated queries ---------------------- */
        $fn = function (string $q) use ($pdo, $condition, $bind) {
            if (stripos($q, 'WHERE') === false) {
                $q = preg_replace(
                    '/\s+(GROUP|ORDER|LIMIT)\s+/i',
                    " WHERE $condition \$1 ",
                    $q,
                    1,
                    $count
                );
                if ($count === 0) {
                    $q .= " WHERE $condition";
                }
            } else {
                $q = str_ireplace('WHERE 1=1', "WHERE $condition", $q);
            }
            $stmt = $pdo->prepare($q);
            $stmt->execute($bind);
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        };
        /* ---- Time-series data ------------------------------------------ */
        $dayData    = $fn("SELECT DATE(i.created_at) AS d, COUNT(*) AS c FROM interactions i GROUP BY d ORDER BY d");
        $dispData   = $fn("SELECT d.name, COUNT(*) FROM interactions i JOIN dispositions d ON d.id=i.disposition_id GROUP BY d.id ORDER BY 2 DESC LIMIT 6");
        $userData   = $fn("SELECT u.name, COUNT(*) FROM interactions i JOIN users u ON u.id=i.user_id GROUP BY u.id ORDER BY 2 DESC LIMIT 8");
        $topLeads   = $fn("SELECT CONCAT(l.first_name,' ',l.last_name), COUNT(*) FROM interactions i JOIN leads l ON l.id=i.lead_id GROUP BY l.id ORDER BY 2 DESC LIMIT 6");
        /* ---- Funnel data ----------------------------------------------- */
        $funnelRaw = $fn("SELECT d.name, COUNT(*) FROM interactions i JOIN dispositions d ON d.id=i.disposition_id WHERE d.name IN ('Interested','Qualified','Closed') GROUP BY d.name");
        $funnel = [
            'interested' => $funnelRaw['Interested'] ?? 0,
            'qualified' => $funnelRaw['Qualified'] ?? 0,
            'closed' => $funnelRaw['Closed'] ?? 0,
        ];
        /* ---- Hourly calls ---------------------------------------------- */
        $hourStmt = $pdo->query("
            SELECT HOUR(created_at) h, COUNT(*) c 
            FROM interactions 
            WHERE DATE(created_at)=CURDATE()
            GROUP BY h
        ");
        $hourRows = $hourStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $hourData = array_replace(array_fill(0, 24, 0), $hourRows); // Ensure all hours are present
        /* ---- Conversion rate ------------------------------------------- */
        $convRate = $kpi['calls_tot']
            ? round($kpi['calls_conv'] / $kpi['calls_tot'] * 100, 2)
            : 0;
        $response['data'] = [
            'totals' => [
                'leads' => (int)$kpi['leads_tot'],
                'today' => (int)$kpi['calls_today'],
                'users' => (int)$kpi['users_act'],
                'rate' => $convRate,
                'avg' => round((float)$kpi['avg_duration'], 1),
                'max' => (int)$kpi['max_duration'],
                'min' => (int)$kpi['min_duration'],
                'online' => (int)$kpi['online_users'],
            ],
            'day' => $dayData,
            'disp' => $dispData,
            'users' => $userData,
            'hour' => $hourData,
            'leads' => $topLeads,
            'funnel' => $funnel
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
/* Latest calls table                                                */
/* ------------------------------------------------------------------ */
$pdo = getPDO();
$latest = $pdo->query("
    SELECT i.created_at,
           u.name                                  AS user,
           CONCAT(l.first_name,' ',l.last_name)    AS lead_name,
           d.name                                  AS disposition,
           i.duration_seconds
      FROM interactions i
      JOIN users u ON u.id = i.user_id
      JOIN leads l ON l.id = i.lead_id
      JOIN dispositions d ON d.id = i.disposition_id
  ORDER BY i.created_at DESC
     LIMIT 6
")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Dashboard – Cold Call CRM</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="./../assets/css/app.css">
<style>
:root{
    --c-bg:#fff; --c-fg:#222; --c-brand:#2c5d4a; --c-sub:#6c757d; --c-surface:#f2f8f5;
}
body{margin:0;font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--c-surface);color:var(--c-fg);}
.container{max-width:1400px;margin:auto;padding:1.5rem;}
h1,h2{margin:0 0 .8rem 0;font-weight:600;}
a.btn{display:inline-block;padding:.5rem 1rem;border-radius:6px;background:var(--c-brand);color:#fff;text-decoration:none;margin-bottom:1rem}
.grid{display:grid;gap:1.5rem}
.cards{grid-template-columns:repeat(auto-fit,minmax(230px,1fr))}
.card{background:var(--c-bg);padding:1rem 1.25rem;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,.05);text-align:center}
.card h2{margin:.2rem 0 .3rem;font-size:1.1rem;color:var(--c-sub)}
.card p{margin:0;font-size:1.8rem;font-weight:700}
canvas{background:var(--c-bg);border-radius:8px;padding:.4rem;margin-top:1.5rem}
.controls{display:flex;flex-wrap:wrap;gap:.5rem;margin:1rem 0}
.range-box{display:flex;gap:.5rem;align-items:center}
.range-box input{padding:.35rem .6rem;border-radius:6px;border:1px solid #ccc}
.badge-online{background:#28a745;color:#fff;padding:.15rem .5rem;border-radius:4px;margin-left:.4rem;font-size:.8rem}
.export-buttons { display: flex; gap: 0.5rem; margin-top: 1rem; }
.export-buttons button { padding: 0.4rem 0.8rem; border-radius: 6px; background: #007bff; color: #fff; border: none; cursor: pointer; }
#chartDisp, #chartUser { height: 300px !important; }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>    
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>    
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">    
</head>
<body>
<div class="container">
  <div style="display:flex;align-items:center;flex-wrap:wrap">
    <a href="../leads/list.php" class="btn">← Leads</a>
    <span id="online" class="badge-online">● 0 online</span>
  </div>
  <h1>Cold Calling Dashboard</h1>
<div class="grid cards">
  <div class="card">
    <h2 title="Total number of leads tracked in the system.">Total Leads</h2>
    <p id="cLeads">…</p>
  </div>
  <div class="card">
    <h2 title="Number of calls made today by all agents.">Calls Today</h2>
    <p id="cToday">…</p>
  </div>
  <div class="card">
    <h2 title="Percentage of successful calls out of total interactions.">Conversion Rate</h2>
    <p id="cRate">… %</p>
  </div>
  <div class="card">
    <h2 title="Number of users currently marked as active in the system.">Active Users</h2>
    <p id="cUsers">…</p>
  </div>
  <div class="card">
    <h2 title="Average duration of completed calls.">Avg Call Duration</h2>
    <p id="cAvgDur">…</p>
  </div>
  <div class="card">
    <h2 title="Longest call duration recorded.">Max Call Duration</h2>
    <p id="cMaxDur">…</p>
  </div>
  <div class="card">
    <h2 title="Shortest call duration recorded.">Min Call Duration</h2>
    <p id="cMinDur">…</p>
  </div>
  <div class="card">
    <h2 title="Number of agents who have interacted within the last 15 minutes.">Agents Online</h2>
    <p id="cOnline">…</p>
  </div>
</div>
  <div class="controls">
    <button class="rng active" data-r="1">Today</button>
    <button class="rng" data-r="7">7 Days</button>
    <button class="rng" data-r="30">30 Days</button>
    <div class="range-box">
      <input id="pick-from" type="text" placeholder="From">
      <span>→</span>
      <input id="pick-to" type="text" placeholder="To">
      <button id="applyCustom">Apply</button>
    </div>
  </div>
  <div class="export-buttons">
    <button onclick="exportToCSV()">📥 Export CSV</button>
    <button onclick="printPage()">🖨️ Print</button>
    <button onclick="pauseAutoRefresh()">⏸ Pause Refresh</button>
  </div>
  <canvas id="cDay" height="90"></canvas>
  <canvas id="cHour" height="90"></canvas>
  <canvas id="cDisp" height="300"></canvas>
  <canvas id="cUser" height="300"></canvas>
  <canvas id="cFunnel" height="120"></canvas>
  <h2 style="margin:2rem 0 .5rem">Latest Calls</h2>
  <table>
    <thead>
      <tr>
        <th>Date</th><th>User</th><th>Lead</th><th>Disposition</th><th>Duration</th>
      </tr>
    </thead>
    <tbody>
    <?php if(!$latest): ?>
      <tr><td colspan="5" style="text-align:center">No data</td></tr>
    <?php else: foreach($latest as $row): ?>
      <tr>
        <td><?= htmlspecialchars(date('M j H:i', strtotime($row['created_at']))) ?></td>
        <td><?= htmlspecialchars($row['user']) ?></td>
        <td><?= htmlspecialchars($row['lead_name']) ?></td>
        <td><?= htmlspecialchars($row['disposition']) ?></td>
        <td><?= $row['duration_seconds'] ? '' : '–' ?></td>
      </tr>
    <?php endforeach; endif;?>
    </tbody>
  </table>
</div>
<!-- JavaScript -->
<script>
const Num = x => Intl.NumberFormat().format(x);

function formatDuration(seconds) {
    if (seconds < 60) return `${seconds} s`;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m} min ${s} s`;
}

let range = 7, autoRefresh = true;

// Chart Instances
const cDay = new Chart(document.getElementById('cDay'), {type: 'line', data: {labels: [], datasets: []}, options: {responsive: true}});
const cHour = new Chart(document.getElementById('cHour'), {type: 'bar', data: {labels: [...Array(24).keys()].map(h => String(h).padStart(2, '0')), datasets: []}, options: {responsive: true}});
const cDisp = new Chart(document.getElementById('cDisp'), {type: 'bar', data: {labels: [], datasets: []}, options: {indexAxis: 'y', responsive: true}});
const cUser = new Chart(document.getElementById('cUser'), {type: 'bar', data: {labels: [], datasets: []}, options: {indexAxis: 'y', responsive: true}});
const cFun = new Chart(document.getElementById('cFunnel'), {
    type: 'bar',
    data: { labels: ['Interested', 'Qualified', 'Closed'], datasets: [{ data: [0, 0, 0], backgroundColor: ['#ffc107', '#17a2b8', '#28a745'] }] },
    options: { plugins: { legend: { display: false } }, responsive: true, indexAxis: 'y' }
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
                document.getElementById('cLeads').textContent = 'Error!';
                document.getElementById('online').textContent = '● Error!';
            } else {
                updateUI(d.data);
            }
        })
        .catch(e => {
            console.error('Fetch error:', e);
            document.getElementById('cLeads').textContent = 'Network Error!';
            document.getElementById('online').textContent = '● Network Error!';
        });
}

function updateUI(d) {
    document.getElementById('cLeads').textContent = Num(d.totals.leads);
    document.getElementById('cToday').textContent = Num(d.totals.today);
    document.getElementById('cUsers').textContent = Num(d.totals.users);
    document.getElementById('cRate').textContent = d.totals.rate + ' %';
    document.getElementById('cAvgDur').textContent = formatDuration(d.totals.avg);
    document.getElementById('cMaxDur').textContent = formatDuration(d.totals.max);
    document.getElementById('cMinDur').textContent = formatDuration(d.totals.min);
    document.getElementById('cOnline').textContent = Num(d.totals.online);
    document.getElementById('online').textContent = '● ' + Num(d.totals.online) + ' online';

    // Update latest calls table
    const tbody = document.querySelector('#latest-calls-table tbody');
    if (tbody) {
        tbody.innerHTML = '';
        d.latest_calls.forEach(row => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${row.date}</td>
                <td>${row.user}</td>
                <td>${row.lead}</td>
                <td>${row.disposition}</td>
                <td>${row.duration}</td>
            `;
            tbody.appendChild(tr);
        });
    }

    // Update charts
    cDay.data.labels = Object.keys(d.day);
    cDay.data.datasets = [{ label: 'Calls', data: Object.values(d.day), fill: true }];
    cDay.update();
    cHour.data.datasets = [{ label: 'Calls/hour', data: Object.values(d.hour) }];
    cHour.update();
    cDisp.data.labels = Object.keys(d.disp);
    cDisp.data.datasets = [{ label: 'Calls', data: Object.values(d.disp) }];
    cDisp.update();
    cUser.data.labels = Object.keys(d.users);
    cUser.data.datasets = [{ label: 'Calls', data: Object.values(d.users) }];
    cUser.update();
    cFun.data.datasets[0].data = [d.funnel.interested, d.funnel.qualified, d.funnel.closed];
    cFun.update();
}

// Range buttons
document.querySelectorAll('.rng').forEach(btn => {
    btn.onclick = () => {
        document.querySelectorAll('.rng').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        range = parseInt(btn.dataset.r);
        fetchData();
    };
});

// Date pickers
flatpickr("#pick-from", { dateFormat: "Y-m-d" });
flatpickr("#pick-to", { dateFormat: "Y-m-d" });
document.getElementById('applyCustom').onclick = () => {
    document.querySelectorAll('.rng').forEach(b => b.classList.remove('active'));
    fetchData();
};

// Export CSV
function exportToCSV() {
    const csvRows = [];
    const headers = ['Date', 'User', 'Lead', 'Disposition', 'Duration'];
    csvRows.push(headers.join(','));
    document.querySelectorAll('table tbody tr').forEach(tr => {
        const tds = Array.from(tr.children).map(td => td.textContent.trim());
        csvRows.push(tds.join(','));
    });
    const blob = new Blob([csvRows.join('\n')], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'dashboard_export.csv';
    a.click();
    URL.revokeObjectURL(url);
}

// Print Page
function printPage() {
    window.print();
}

// Pause auto-refresh
function pauseAutoRefresh() {
    autoRefresh = false;
}

// Initial load
fetchData();
setInterval(() => {
    if (autoRefresh) fetchData();
}, 15000);
</script>
</body>
</html>