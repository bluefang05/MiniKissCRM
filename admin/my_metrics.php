<?php
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

// Authorization check (only allow 'admin' or 'viewer')
if (!array_intersect($roles, ['admin','viewer'])) {
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
                COUNT(DISTINCT CASE WHEN DATE(i.created_at)=CURDATE() THEN i.id END) AS calls_today,
                IFNULL(AVG(i.duration_seconds),0) AS avg_duration,
                COUNT(CASE WHEN d.name='Interested' THEN 1 END) AS calls_conv,
                COUNT(i.id) AS calls_tot,
                SUM(i.duration_seconds) AS total_talk_time
            FROM interactions i
            JOIN dispositions d ON d.id = i.disposition_id
            WHERE i.user_id = :user_id AND $condition
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([':user_id' => $user_id], $bind));
        $kpi = $stmt->fetch(PDO::FETCH_ASSOC);

        /* ---- Helper function for repeated queries ---------------------- */
        $fn = function (string $q) use ($pdo, $condition, $bind, $user_id) {
            $q = str_replace(':user_id', '?', $q);
            $q = str_replace('WHERE 1=1', "WHERE i.user_id = $user_id AND $condition", $q);
            $stmt = $pdo->prepare($q);
            $stmt->execute(array_values($bind));
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        };

        /* ---- Time-series data ------------------------------------------ */
        $dayData    = $fn("SELECT DATE(i.created_at) AS d, COUNT(*) AS c FROM interactions i WHERE 1=1 GROUP BY d ORDER BY d");
        $hourStmt = $pdo->query("
            SELECT HOUR(created_at) h, COUNT(*) c 
            FROM interactions 
            WHERE user_id = $user_id AND DATE(created_at)=CURDATE()
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
                'today' => (int)$kpi['calls_today'],
                'avg' => round((float)$kpi['avg_duration'], 1),
                'talk_time' => (int)$kpi['total_talk_time'],
                'rate' => $convRate,
            ],
            'day' => $dayData,
            'hour' => $hourData
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
$stmt = $pdo->prepare("
    SELECT i.created_at, u.name as user, CONCAT(l.first_name,' ',l.last_name) as lead_name, d.name as disposition, i.duration_seconds
    FROM interactions i
    JOIN users u ON u.id = i.user_id
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
<title>My Metrics – Cold Call CRM</title>
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
    <a href="dashboard.php" class="btn">📊 Dashboard</a>
  </div>
  <h1>My Performance Metrics</h1>

  <div class="grid cards">
    <div class="card">
      <h2 title="Number of calls you made today.">Calls Today</h2>
      <p id="cToday">…</p>
    </div>
    <div class="card">
      <h2 title="Average duration of your completed calls.">Avg Call Duration</h2>
      <p id="cAvgDur">…</p>
    </div>
    <div class="card">
      <h2 title="Percentage of successful calls out of total interactions.">Conversion Rate</h2>
      <p id="cRate">… %</p>
    </div>
    <div class="card">
      <h2 title="Total time spent on calls.">Total Talk Time</h2>
      <p id="cTalkTime">…</p>
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

  <canvas id="cDay" height="90"></canvas>
  <canvas id="cHour" height="90"></canvas>

  <h2 style="margin:2rem 0 .5rem">Latest Calls</h2>
  <table>
    <thead>
      <tr>
        <th>Date</th><th>Lead</th><th>Disposition</th><th>Duration</th>
      </tr>
    </thead>
    <tbody>
    <?php if(!$latest): ?>
      <tr><td colspan="4" style="text-align:center">No data</td></tr>
    <?php else: foreach($latest as $row): ?>
      <tr>
        <td><?= htmlspecialchars(date('M j H:i', strtotime($row['created_at']))) ?></td>
        <td><?= htmlspecialchars($row['lead_name']) ?></td>
        <td><?= htmlspecialchars($row['disposition']) ?></td>
        <td><?= $row['duration_seconds'] ? (int)$row['duration_seconds'].' s' : '–' ?></td>
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

function updateUI(d) {
    document.getElementById('cToday').textContent = Num(d.totals.today);
    document.getElementById('cAvgDur').textContent = formatDuration(Math.round(d.totals.avg));
    document.getElementById('cRate').textContent = d.totals.rate + ' %';
    document.getElementById('cTalkTime').textContent = formatDuration(Math.round(d.totals.talk_time));

    // Update charts
    cDay.data.labels = Object.keys(d.day);
    cDay.data.datasets = [{ label: 'Calls', data: Object.values(d.day), fill: true }];
    cDay.update();

    cHour.data.labels = [...Array(24).keys()].map(h => String(h).padStart(2, '0'));
    cHour.data.datasets = [{ label: 'Calls/hour', data: d.hour }];
    cHour.update();
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

// Initial load
fetchData();
setInterval(() => {
    if (autoRefresh) fetchData();
}, 15000);
</script>
</body>
</html>