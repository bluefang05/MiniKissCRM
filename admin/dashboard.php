<?php
// admin/dashboard.php
declare(strict_types=1);

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

/* ───────────────────────── 0. Seguridad básica en cabeceras ───────────────────────── */
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: no-referrer-when-downgrade");
/*
 - Mantenemos 'unsafe-inline' en scripts porque abajo hay JS embebido.
 - Ya NO usamos estilos inline ni <style>, así que retiramos 'unsafe-inline' de style-src.
*/
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self';");

/* ───────────────────────── 1. Auth + roles ───────────────────────── */
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}
$user  = Auth::user();
$roles = $user['roles'] ?? [];
$canView   = (bool)array_intersect($roles, ['owner','admin','viewer']);
$canWrite  = (bool)array_intersect($roles, ['owner','admin','lead_manager','sales']);
if (!$canView) {
    http_response_code(403);
    die('<h1>Access denied</h1>');
}

/* ───────────────────────── 2. DB + zona horaria ──────────────────── */
$pdo = getPDO();
// Ajusta a tu tz operativa (ej. America/Santo_Domingo = -04:00 sin DST)
$pdo->exec("SET time_zone = '-04:00'");

/* ───────────────────────── 3. Parámetros de fecha ─────────────────── */
function isYmd(string $s): bool { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s); }

$defaultStart7d = date('Y-m-d', strtotime('-7 days'));
$defaultEnd     = date('Y-m-d');

$start = $_GET['start'] ?? $defaultStart7d;
$end   = $_GET['end']   ?? $defaultEnd;
if (!isYmd($start)) $start = $defaultStart7d;
if (!isYmd($end))   $end   = $defaultEnd;

// por conveniencia, agregamos fin de día para filtros inclusivos si usamos BETWEEN con datetime
$startDt = $start . ' 00:00:00';
$endDt   = $end   . ' 23:59:59';

// Modo del ranking de agentes: MTD (mes en curso) o rango elegido
$modeAgents = $_GET['agents'] ?? 'range'; // 'mtd' | 'range'

/* ───────────────────────── 4. KPIs rápidos ───────────────────────── */
// Total leads activos (no DNC)
$totalLeads = (int)($pdo->query("SELECT COUNT(*) FROM leads WHERE do_not_call = 0")->fetchColumn() ?: 0);

// Llamadas hoy (tz ya fijada)
$callsToday = (int)($pdo->query("SELECT COUNT(*) FROM interactions WHERE DATE(interaction_time) = CURDATE()")->fetchColumn() ?: 0);

// Ventas cerradas en últimos 30 días (fijamos ventana relativa)
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM interactions i
    JOIN dispositions d ON d.id = i.disposition_id
    WHERE d.name = 'Service Sold'
      AND i.interaction_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stmt->execute();
$closedLeads = (int)($stmt->fetchColumn() ?: 0);

$conversionRate = $totalLeads ? round(($closedLeads / $totalLeads) * 100, 1) : 0.0;

// Usuarios “online” (locks vigentes)
$activeUsers = (int)($pdo->query("
    SELECT COUNT(DISTINCT user_id)
    FROM lead_locks
    WHERE expires_at > NOW()
")->fetchColumn() ?: 0);

// Duración promedio llamadas (últimos 7 días)
$avgSeconds = (float)($pdo->query("
    SELECT COALESCE(AVG(duration_seconds),0)
    FROM interactions
    WHERE interaction_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
      AND duration_seconds IS NOT NULL
")->fetchColumn() ?: 0.0);
$avgDurationFmt = $avgSeconds ? gmdate('i\m s\s', (int)$avgSeconds) : '0m 0s';

/* ───────────────────────── 5. Datos para gráficos ─────────────────── */
// Leads por interés (LEFT JOIN para no perder “sin asignar”)
$leadsByInterest = $pdo->query("
    SELECT COALESCE(ii.name,'Unassigned') AS name, COUNT(*) AS count
    FROM leads l
    LEFT JOIN insurance_interests ii ON ii.id = l.insurance_interest_id
    GROUP BY COALESCE(ii.name,'Unassigned')
    ORDER BY count DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Llamadas por día en rango (por defecto últimos 7 días)
$callsByDayStmt = $pdo->prepare("
    SELECT DATE(interaction_time) AS d, COUNT(*) AS count
    FROM interactions
    WHERE interaction_time BETWEEN :start AND :end
    GROUP BY DATE(interaction_time)
    ORDER BY DATE(interaction_time) ASC
");
$callsByDayStmt->execute([':start'=>$startDt, ':end'=>$endDt]);
$callsByDay = $callsByDayStmt->fetchAll(PDO::FETCH_ASSOC);

// Disposiciones en rango
$dispoStmt = $pdo->prepare("
    SELECT d.name, COUNT(*) AS cnt
    FROM interactions i
    JOIN dispositions d ON d.id = i.disposition_id
    WHERE i.interaction_time BETWEEN :start AND :end
    GROUP BY d.id, d.name
    ORDER BY cnt DESC
");
$dispoStmt->execute([':start'=>$startDt, ':end'=>$endDt]);
$dispoBreakdown = $dispoStmt->fetchAll(PDO::FETCH_ASSOC);

// Top agentes (MTD o rango)
if ($modeAgents === 'mtd') {
    $agentsStart = date('Y-m-01') . ' 00:00:00';
    $agentsEnd   = $endDt;
    $perfStmt = $pdo->prepare("
        SELECT u.name, COUNT(i.id) AS calls
        FROM interactions i
        JOIN users u ON u.id = i.user_id
        WHERE i.interaction_time BETWEEN :s AND :e
        GROUP BY u.id, u.name
        ORDER BY calls DESC
        LIMIT 5
    ");
    $perfStmt->execute([':s'=>$agentsStart, ':e'=>$agentsEnd]);
} else {
    $perfStmt = $pdo->prepare("
        SELECT u.name, COUNT(i.id) AS calls
        FROM interactions i
        JOIN users u ON u.id = i.user_id
        WHERE i.interaction_time BETWEEN :s AND :e
        GROUP BY u.id, u.name
        ORDER BY calls DESC
        LIMIT 5
    ");
    $perfStmt->execute([':s'=>$startDt, ':e'=>$endDt]);
}
$perfLabels = [];
$perfValues = [];
while ($row = $perfStmt->fetch(PDO::FETCH_ASSOC)) {
    $first = trim(explode(' ', $row['name'])[0] ?? $row['name']);
    $perfLabels[] = $first ?: 'User';
    $perfValues[] = (int)$row['calls'];
}

// Últimas interacciones (10)
$tblStmt = $pdo->query("
    SELECT 
        i.interaction_time,
        u.name AS agent,
        CONCAT(l.first_name,' ',l.last_name) AS lead,
        d.name AS disp,
        i.duration_seconds,
        i.notes
    FROM interactions i
    JOIN users u ON u.id = i.user_id
    JOIN leads l ON l.id = i.lead_id
    JOIN dispositions d ON d.id = i.disposition_id
    ORDER BY i.interaction_time DESC
    LIMIT 10
");
$recentCalls = $tblStmt->fetchAll(PDO::FETCH_ASSOC);

/* ───────────────────────── 6. Render ───────────────────────── */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cold Call Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Usa el CSS del dashboard trabajado -->
    <link rel="stylesheet" href="../assets/css/admin/dashboard.css">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
    <!-- Bootstrap Bundle (opcional si ya lo usas) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
</head>
<body>

<div class="dashboard-container">
    <!-- Header (Back to Leads dentro del header, tema unificado) -->
    <div class="header">
        <div>
            <h1 class="page-title">Cold Call Dashboard</h1>
            <p class="page-subtitle">Analytics and performance metrics</p>
        </div>
<div class="header-actions">
  <a href="../leads/list.php" class="btn btn-back">
    <span class="btn-icon" aria-hidden="true">←</span>
    Back to Leads
  </a>
</div>

    </div>

    <!-- Controles de rango + modo ranking agentes + auto-refresh -->
    <form class="controls" method="get" action="">
        <label>Start
            <input type="date" name="start" value="<?= htmlspecialchars($start) ?>">
        </label>
        <label>End
            <input type="date" name="end" value="<?= htmlspecialchars($end) ?>">
        </label>
        <label>Top Agents
            <select name="agents">
                <option value="range" <?= $modeAgents==='range'?'selected':''; ?>>Use date range</option>
                <option value="mtd"   <?= $modeAgents==='mtd'?'selected':''; ?>>Month-to-date</option>
            </select>
        </label>
        <button class="btn btn-primary" type="submit">Update</button>

        <span class="right switch">
            <input id="autorf" type="checkbox">
            <label for="autorf" class="small">Auto-refresh (60s)</label>
        </span>
    </form>

    <!-- KPIs -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <h4>Total Leads (No DNC)</h4>
            <p><?= number_format($totalLeads) ?></p>
        </div>
        <div class="kpi-card">
            <h4>Calls Today</h4>
            <p><?= number_format($callsToday) ?></p>
        </div>
        <div class="kpi-card">
            <h4>Sales Closed (30d)</h4>
            <p><?= number_format($closedLeads) ?></p>
        </div>
        <div class="kpi-card">
            <h4>Avg Duration (7d)</h4>
            <p><?= htmlspecialchars($avgDurationFmt) ?></p>
        </div>
        <div class="kpi-card">
            <h4>Conversion Rate</h4>
            <p><?= number_format($conversionRate, 1) ?>%</p>
        </div>
    </div>

    <!-- Charts -->
    <div class="chart-row">
        <div class="chart-box">
            <h4>📈 Leads by Interest</h4>
            <canvas id="leadsByInterestChart"></canvas>
        </div>
        <div class="chart-box">
            <h4>📞 Daily Call Volume (<?= htmlspecialchars($start) ?> → <?= htmlspecialchars($end) ?>)</h4>
            <canvas id="dailyCallChart"></canvas>
        </div>
    </div>

    <div class="chart-row">
        <div class="chart-box wide">
            <h4>📊 Disposition Breakdown (<?= htmlspecialchars($start) ?> → <?= htmlspecialchars($end) ?>)</h4>
            <canvas id="dispositionChart"></canvas>
        </div>
    </div>

    <!-- Últimas interacciones -->
    <div class="table-container mt-4">
        <h4>🕑 Latest Interactions</h4>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Agent</th>
                    <th>Lead</th>
                    <th>Disposition</th>
                    <th>Duration</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentCalls as $rc): ?>
                    <tr>
                        <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($rc['interaction_time']))) ?></td>
                        <td><?= htmlspecialchars($rc['agent']) ?></td>
                        <td><?= htmlspecialchars($rc['lead']) ?></td>
                        <td><span class="status-badge"><?= htmlspecialchars($rc['disp']) ?></span></td>
                        <td><?= gmdate('i\m s\s', (int)($rc['duration_seconds'] ?? 0)) ?></td>
                        <td><?= nl2br(htmlspecialchars(mb_strimwidth((string)$rc['notes'], 0, 80, '…'))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="mt-4">
            <a class="btn btn-secondary" href="../calls/list.php?order=desc">View all calls →</a>
        </div>
    </div>
</div>

<!-- JS de gráficos y auto-refresh -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle Auto-Refresh (60s)
    const chk = document.getElementById('autorf');
    chk?.addEventListener('change', e=>{
        if (e.target.checked) { window._rf = setInterval(()=>location.reload(), 60000); }
        else { clearInterval(window._rf); }
    });

    const C = {
        primary: '#1a365d',
        secondary: '#319795',
        success: '#38a169',
        warning: '#d69e2e',
        danger: '#e53e3e',
        info: '#3182ce'
    };

    // Datos desde PHP
    const leadsByInterestLabels = <?= json_encode(array_column($leadsByInterest, 'name'), JSON_UNESCAPED_UNICODE) ?>;
    const leadsByInterestValues = <?= json_encode(array_map('intval', array_column($leadsByInterest, 'count'))) ?>;

    const callsByDayLabels = <?= json_encode(array_column($callsByDay, 'd')) ?>;
    const callsByDayValues = <?= json_encode(array_map('intval', array_column($callsByDay, 'count'))) ?>;

    const dispoLabels = <?= json_encode(array_column($dispoBreakdown, 'name'), JSON_UNESCAPED_UNICODE) ?>;
    const dispoValues = <?= json_encode(array_map('intval', array_column($dispoBreakdown, 'cnt'))) ?>;

    const agentLabels = <?= json_encode($perfLabels, JSON_UNESCAPED_UNICODE) ?>;
    const agentValues = <?= json_encode(array_map('intval', $perfValues)) ?>;

    // Chart: Leads by Interest (Bar)
    new Chart(document.getElementById('leadsByInterestChart'), {
        type: 'bar',
        data: {
            labels: leadsByInterestLabels,
            datasets: [{
                label: 'Leads',
                backgroundColor: C.primary,
                data: leadsByInterestValues
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { autoSkip: false }, grid: { display: false } },
                y: { beginAtZero: true }
            }
        }
    });

    // Chart: Calls by Day (Line + Fill)
    new Chart(document.getElementById('dailyCallChart'), {
        type: 'line',
        data: {
            labels: callsByDayLabels,
            datasets: [{
                data: callsByDayValues,
                label: 'Calls per Day',
                borderColor: C.success,
                backgroundColor: C.success + '20',
                fill: true,
                tension: 0.35
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                x: { title: { display: true, text: 'Date' } },
                y: { title: { display: true, text: 'Calls' }, beginAtZero: true, precision: 0 }
            }
        }
    });

    // Chart: Dispositions (Pie)
    new Chart(document.getElementById('dispositionChart'), {
        type: 'pie',
        data: {
            labels: dispoLabels,
            datasets: [{
                data: dispoValues,
                backgroundColor: [C.warning, C.danger, C.info, C.secondary, C.success, C.primary]
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom' } },
            maintainAspectRatio: false
        }
    });

    // Chart: Top Agents (Horizontal Bar si hay muchos nombres)
    new Chart(document.getElementById('topAgentsChart'), {
        type: 'bar',
        data: {
            labels: agentLabels,
            datasets: [{
                label: 'Calls',
                data: agentValues,
                backgroundColor: C.info
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true } }
        }
    });
});
</script>
</body>
</html>
