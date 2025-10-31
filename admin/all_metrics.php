<?php
/**
 * admin/all_metrics.php – Extended version
 * Team-wide / per-agent KPI dashboard (admins/owners)
 */
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

if (!Auth::check()) { header('Location: ../auth/login.php'); exit; }

$user  = Auth::user();
$roles = $user['roles'] ?? [];
if (!in_array('admin', $roles, true) && !in_array('owner', $roles, true)) {
    header('Location: ../index.php'); exit;
}

/* 1) Helper: sales agents */
function getSalesAgents(PDO $pdo): array {
    $sql = "
        SELECT u.id, u.name
        FROM users u
        JOIN user_roles ur ON ur.user_id = u.id
        JOIN roles r       ON r.id  = ur.role_id
        WHERE r.name = 'sales'
        ORDER BY u.name
    ";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/* 2) JSON API */
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $res = ['error'=>null,'data'=>[]];

    try {
        $pdo = getPDO();
        $salesUsers = getSalesAgents($pdo);
        $salesIds   = array_map('intval', array_column($salesUsers, 'id'));

        if (empty($salesIds)) {
            $res['data'] = [
                'totals' => ['calls_tot'=>0,'avg_duration'=>0,'total_talk_time'=>0,'conv_rate'=>0],
                'users'=>[],
                'dispositions'=>[],
                'trend_day'=>[],
                'trend_hour'=>array_fill_keys(array_map(fn($h)=>str_pad((string)$h,2,'0',STR_PAD_LEFT), range(0,23)), 0),
                'latest_calls'=>[],
                'sales_agents'=>$salesUsers,
            ];
            echo json_encode($res); exit;
        }

        $rangeOpt = $_GET['range']   ?? 'week';   // week|month|year|all
        $agentOpt = $_GET['user_id'] ?? 'all';    // numeric | all
        $agentId  = ctype_digit((string)$agentOpt) ? (int)$agentOpt : 'all';
        if ($agentId !== 'all' && !in_array($agentId, $salesIds, true)) {
            throw new RuntimeException('Invalid agent id');
        }

        switch ($rangeOpt) {
            case 'week':
                $dateSql = "i.created_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)";
                break;
            case 'month':
                $dateSql = "i.created_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01')";
                break;
            case 'year':
                $dateSql = "i.created_at >= DATE_FORMAT(CURDATE(),'%Y-01-01')";
                break;
            case 'all':
            default:
                $dateSql = "1";
        }

        $bind = [];
        if ($agentId === 'all') {
            $userSql = 'i.user_id IN (' . implode(',', $salesIds) . ')';
        } else {
            $userSql = 'i.user_id = :uid';
            $bind[':uid'] = $agentId;
        }
        $baseWhere = "WHERE $dateSql AND $userSql";

        // KPIs
        $kpiStmt = $pdo->prepare("
            SELECT
              COUNT(*)                                         AS calls_tot,
              IFNULL(AVG(i.duration_seconds),0)                AS avg_duration,
              SUM(IFNULL(i.duration_seconds),0)                AS total_talk_time,
              SUM(CASE WHEN d.name = 'Interested' THEN 1 END)  AS calls_conv
            FROM interactions i
            JOIN dispositions d ON d.id = i.disposition_id
            $baseWhere
        ");
        $kpiStmt->execute($bind);
        $kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: ['calls_tot'=>0,'avg_duration'=>0,'total_talk_time'=>0,'calls_conv'=>0];

        // Calls per user
        $userStmt = $pdo->prepare("
            SELECT u.name,
                   COUNT(*)                           AS count,
                   IFNULL(AVG(i.duration_seconds),0) AS avg_duration
            FROM interactions i
            JOIN users u ON u.id = i.user_id
            $baseWhere
            GROUP BY u.id
            ORDER BY count DESC
        ");
        $userStmt->execute($bind);
        $usersData = $userStmt->fetchAll(PDO::FETCH_ASSOC);

        // Dispositions
        $dispStmt = $pdo->prepare("
            SELECT d.name, COUNT(*) AS cnt
            FROM interactions i
            JOIN dispositions d ON d.id = i.disposition_id
            $baseWhere
            GROUP BY d.id
        ");
        $dispStmt->execute($bind);
        $dispositions = $dispStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        // Daily trend
        $dayStmt = $pdo->prepare("
            SELECT DATE(i.created_at) d, COUNT(*) c
            FROM interactions i
            $baseWhere
            GROUP BY d
            ORDER BY d
        ");
        $dayStmt->execute($bind);
        $trendDay = $dayStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        // Hourly trend (00..23, con ceros)
        $hourStmt = $pdo->prepare("
            SELECT LPAD(HOUR(i.created_at),2,'0') h, COUNT(*) c
            FROM interactions i
            $baseWhere
            GROUP BY h
            ORDER BY h
        ");
        $hourStmt->execute($bind);
        $hoursRaw = $hourStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        $trendHour = [];
        for ($h=0; $h<24; $h++) {
            $k = str_pad((string)$h, 2, '0', STR_PAD_LEFT);
            $trendHour[$k] = isset($hoursRaw[$k]) ? (int)$hoursRaw[$k] : 0;
        }

        // Latest calls
        $latestStmt = $pdo->prepare("
            SELECT i.created_at,
                   CONCAT(l.first_name,' ',l.last_name) AS lead_name,
                   d.name  AS disposition,
                   u.name  AS user_name,
                   i.duration_seconds
            FROM interactions i
            JOIN leads        l ON l.id = i.lead_id
            JOIN dispositions d ON d.id = i.disposition_id
            JOIN users        u ON u.id = i.user_id
            $baseWhere
            ORDER BY i.created_at DESC
            LIMIT 10
        ");
        $latestStmt->execute($bind);
        $latestCalls = $latestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $convRate = $kpi['calls_tot'] ? round($kpi['calls_conv'] / $kpi['calls_tot'] * 100, 2) : 0;

        $res['data'] = [
            'totals'        => [
                'calls_tot'       => (int)$kpi['calls_tot'],
                'avg_duration'    => (float)$kpi['avg_duration'],
                'total_talk_time' => (int)$kpi['total_talk_time'],
                'conv_rate'       => $convRate,
            ],
            'users'         => $usersData,
            'dispositions'  => $dispositions,
            'trend_day'     => $trendDay,
            'trend_hour'    => $trendHour,
            'latest_calls'  => $latestCalls,
            'sales_agents'  => $salesUsers,
        ];

    } catch (Throwable $e) {
        error_log('Metrics API: ' . $e->getMessage());
        $res['error'] = 'Internal error';
    }

    echo json_encode($res);
    exit;
}

/* 3) HTML render */
$pdo = getPDO();
$salesAgents = getSalesAgents($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Team Metrics – MiniKissCRM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="../assets/css/admin/all_metrics.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="page-container">

    <!-- Header con Back to Leads -->
    <div class="header-bar">
        <a href="../leads/list.php" class="btn-back" aria-label="Back to Leads">
            <span class="btn-icon" aria-hidden="true">←</span>
            Back to Leads
        </a>
        <h1 class="page-title">📊 Team Call Metrics</h1>
    </div>

    <!-- Filtros -->
    <div class="filters">
        <select id="range-select" class="select">
            <option value="week">This Week</option>
            <option value="month" selected>This Month</option>
            <option value="year">This Year</option>
            <option value="all">All Time</option>
        </select>

        <select id="user-select" class="select">
            <option value="all">All Sales Agents</option>
            <?php foreach ($salesAgents as $a): ?>
                <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars($a['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <button class="btn" onclick="fetchData()">↻ Refresh</button>
    </div>

    <!-- KPI Cards -->
    <div class="stats-grid">
        <div class="stat-card"><h3>Total Calls</h3><p id="cTotal">0</p></div>
        <div class="stat-card"><h3>Avg Duration</h3><p id="cAvgDur">0s</p></div>
        <div class="stat-card"><h3>Total Talk Time</h3><p id="cTalkTime">0s</p></div>
        <div class="stat-card"><h3>Conversion Rate</h3><p id="cConvRate">0%</p></div>
    </div>

    <!-- Calls per Agent -->
    <section class="metric-section">
        <h2>Calls per Agent</h2>
        <table class="data-table">
            <thead><tr><th>User</th><th>Calls</th><th>Avg Duration</th></tr></thead>
            <tbody id="userTableBody"></tbody>
        </table>
    </section>

    <!-- Dispositions -->
    <section class="metric-section">
        <h2>Disposition Distribution</h2>
        <canvas id="chart-dispositions"></canvas>
    </section>

    <!-- Trends -->
    <section class="metric-section">
        <h2>Daily & Hourly Call Trends</h2>
        <div class="charts-wrap">
            <canvas id="chart-day-trend"></canvas>
            <canvas id="chart-hour-trend"></canvas>
        </div>
    </section>

    <!-- Latest Calls -->
    <section class="metric-section">
        <h2>Latest Calls</h2>
        <table class="data-table">
            <thead>
                <tr><th>Agent</th><th>Lead</th><th>Disposition</th><th>Duration</th><th>Time</th></tr>
            </thead>
            <tbody id="latestCallTableBody"></tbody>
        </table>
    </section>

</div><!-- /.page-container -->

<script>
let dispChart, dayChart, hourChart;

// Helpers
const durFmt = s => { s = Math.round(s); return `${Math.floor(s/60)}m ${s%60}s`; };
const makeChart = (ctx, cfg) => new Chart(ctx, cfg);
const esc = (s='') => String(s)
  .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
  .replace(/"/g,'&quot;').replace(/'/g,'&#39;');

function fillTable(tbody, rowsHtml) {
  tbody.innerHTML = '';
  tbody.insertAdjacentHTML('beforeend', rowsHtml);
}

function updateCharts(data) {
  [dispChart, dayChart, hourChart].filter(Boolean).forEach(c => c.destroy());

  // Dispositions doughnut
  dispChart = makeChart(document.getElementById('chart-dispositions'), {
    type: 'doughnut',
    data: {
      labels: Object.keys(data.dispositions),
      datasets: [{ data: Object.values(data.dispositions), borderWidth: 0 }]
    },
    options: { plugins: { legend: { position: 'right' } }, responsive: true }
  });

  // Day trend line
  dayChart = makeChart(document.getElementById('chart-day-trend'), {
    type: 'line',
    data: {
      labels: Object.keys(data.trend_day),
      datasets: [{
        data: Object.values(data.trend_day),
        tension: 0.3,
        fill: true
      }]
    },
    options: { scales: { y: { beginAtZero: true } }, plugins: { legend: { display: false } }, responsive: true }
  });

  // Hour trend bar (00..23 en orden ya viene del API)
  hourChart = makeChart(document.getElementById('chart-hour-trend'), {
    type: 'bar',
    data: {
      labels: Object.keys(data.trend_hour),
      datasets: [{ data: Object.values(data.trend_hour) }]
    },
    options: { scales: { y: { beginAtZero: true } }, plugins: { legend: { display: false } }, responsive: true }
  });
}

function fetchData() {
  const range = document.getElementById('range-select').value;
  const uid   = document.getElementById('user-select').value;

  const url = new URL(window.location.href);
  url.search = '';
  url.searchParams.set('api','1');
  url.searchParams.set('range', range);
  url.searchParams.set('user_id', uid);

  fetch(url)
    .then(r => r.json())
    .then(j => {
      if (j.error) { console.error(j.error); return; }
      const d = j.data;

      // KPI cards
      document.getElementById('cTotal').textContent   = d.totals.calls_tot;
      document.getElementById('cAvgDur').textContent  = durFmt(d.totals.avg_duration);
      document.getElementById('cTalkTime').textContent= durFmt(d.totals.total_talk_time);
      document.getElementById('cConvRate').textContent= d.totals.conv_rate + '%';

      // Calls per user
      fillTable(
        document.getElementById('userTableBody'),
        d.users.map(r => `
          <tr>
            <td>${esc(r.name)}</td>
            <td>${r.count}</td>
            <td>${esc(durFmt(r.avg_duration))}</td>
          </tr>
        `).join('')
      );

      // Latest calls
      fillTable(
        document.getElementById('latestCallTableBody'),
        d.latest_calls.map(c => `
          <tr>
            <td>${esc(c.user_name)}</td>
            <td>${esc(c.lead_name)}</td>
            <td>${esc(c.disposition)}</td>
            <td>${esc(durFmt(c.duration_seconds))}</td>
            <td>${esc(new Date(c.created_at).toLocaleString())}</td>
          </tr>
        `).join('')
      );

      // Charts
      updateCharts(d);
    })
    .catch(err => console.error('Fetch error:', err));
}

window.addEventListener('load', fetchData);
</script>
</body>
</html>
