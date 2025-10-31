<?php
// admin/agent_detail.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ './db.php';

if (!Auth::check() || !array_intersect(['owner', 'admin'], Auth::user()['roles'] ?? [])) {
    http_response_code(403);
    die('<div class="container"><h1>Acceso Denegado</h1></div>');
}

$agentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$agentId) {
    header('Location: owner_dashboard.php');
    exit;
}

$pdo = getPDO();
date_default_timezone_set('America/Santo_Domingo');
$today      = (new DateTime())->format('Y-m-d');
$monthStart = (new DateTime('first day of this month'))->format('Y-m-d 00:00:00');

/* ----------  REGISTRO DEL AGENTE ---------- */
$stmt = $pdo->prepare("SELECT id, name, email, created_at FROM users WHERE id=?");
$stmt->execute([$agentId]);
$agent = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$agent) {
    header('Location: owner_dashboard.php');
    exit;
}

/* ----------  KPIs ---------- */
$stats = [];

// Llamadas hoy
$q = $pdo->prepare("SELECT COUNT(*) FROM interactions WHERE user_id=? AND DATE(interaction_time)=?");
$q->execute([$agentId, $today]);
$stats['calls_today'] = (int)$q->fetchColumn();

// Llamadas este mes
$q = $pdo->prepare("SELECT COUNT(*) FROM interactions WHERE user_id=? AND interaction_time>=?");
$q->execute([$agentId, $monthStart]);
$stats['calls_month'] = (int)$q->fetchColumn();

// Ventas este mes
$q = $pdo->prepare("
    SELECT COUNT(*) FROM interactions i
    JOIN dispositions d ON d.id=i.disposition_id
    WHERE d.name='Service Sold' AND i.user_id=? AND i.interaction_time>=?
");
$q->execute([$agentId, $monthStart]);
$stats['sales_month'] = (int)$q->fetchColumn();

// Duración promedio de llamadas
$q = $pdo->prepare("SELECT AVG(duration_seconds) FROM interactions WHERE user_id=?");
$q->execute([$agentId]);
$stats['avg_duration'] = round((float)$q->fetchColumn(), 1);

// Leads subidos por el agente
$q = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE uploaded_by=?");
$q->execute([$agentId]);
$stats['total_leads'] = (int)$q->fetchColumn();

/* ----------  DESGLOSE POR DISPOSICIÓN (últimos 30 días) ---------- */
$dispos = $pdo->prepare("
    SELECT d.name, COUNT(*) AS cnt
    FROM interactions i
    JOIN dispositions d ON d.id=i.disposition_id
    WHERE i.user_id=? AND i.interaction_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY d.name
    ORDER BY cnt DESC
");
$dispos->execute([$agentId]);
$dispos = $dispos->fetchAll(PDO::FETCH_ASSOC);

/* ----------  ÚLTIMAS INTERACCIONES ---------- */
$recent = $pdo->prepare("
    SELECT i.id, l.first_name, l.last_name, d.name AS dispo,
           i.duration_seconds,
           DATE_FORMAT(i.interaction_time,'%Y-%m-%d %H:%i') AS t,
           LEFT(i.notes, 120) AS short_notes
    FROM interactions i
    JOIN leads l ON l.id = i.lead_id
    JOIN dispositions d ON d.id = i.disposition_id
    WHERE i.user_id=?
    ORDER BY i.interaction_time DESC
    LIMIT 20
");
$recent->execute([$agentId]);
$recent = $recent->fetchAll(PDO::FETCH_ASSOC);

/* ----------  LEADS SUBIDOS POR EL AGENTE ---------- */
$leadsList = $pdo->prepare("
    SELECT first_name, last_name, phone, created_at
    FROM leads
    WHERE uploaded_by = ?
    ORDER BY created_at DESC
    LIMIT 10
");
$leadsList->execute([$agentId]);
$leadsList = $leadsList->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Detalle del Agente • <?= htmlspecialchars($agent['name']) ?></title>

    <!-- CSS -->
    <link rel="stylesheet" href="/assets/css/app.css">

    <!-- Bootstrap -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap @5.3.3/dist/css/bootstrap.min.css">
</head>
<body>
<div class="container my-4">
    <a href="owner_dashboard.php" class="btn btn-sm btn-secondary mb-3">← Volver al Dashboard</a>

    <h1 class="mb-3"><?= htmlspecialchars($agent['name']) ?> <small class="text-muted">(#<?= $agent['id'] ?>)</small></h1>

    <!-- KPIs -->
    <div class="row g-3 mb-4">
        <?php
        $labels = [
            'calls_today' => 'Llamadas Hoy',
            'calls_month' => 'Llamadas Este Mes',
            'sales_month' => 'Ventas Cerradas',
            'avg_duration' => 'Duración Promedio (seg)',
            'total_leads' => 'Leads Subidos'
        ];
        foreach ($labels as $key => $label): ?>
            <div class="col-6 col-lg-2">
                <div class="card text-center shadow-sm h-100">
                    <div class="card-body p-2">
                        <h6 class="card-title small"><?= $label ?></h6>
                        <p class="h4 mb-0"><?= $stats[$key] ?? 0 ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- DISPOSICIONES -->
    <div class="col-lg-6">
        <h4>Resultados de Llamadas (Últimos 30 días)</h4>
        <table class="table table-sm table-striped">
            <thead class="table-light">
                <tr><th>Resultado</th><th class="text-end">Cantidad</th></tr>
            </thead>
            <tbody>
                <?php foreach ($dispos as $d): ?>
                    <tr>
                        <td><?= htmlspecialchars($d['name']) ?></td>
                        <td class="text-end"><?= $d['cnt'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ÚLTIMAS INTERACCIONES -->
<h4 class="mt-4">🕑 Últimas 20 Interacciones</h4>
<div class="table-responsive">
    <table class="table table-sm table-bordered">
        <thead class="table-light">
            <tr>
                <th>ID</th>
                <th>Lead</th>
                <th>Resultado</th>
                <th class="text-end">Segundos</th>
                <th>Fecha y Hora</th>
                <th>Notas</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td><?= $r['id'] ?></td>
                    <td>
                        <a href="../leads/view.php?id=<?= $r['id'] ?>" class="text-decoration-none">
                            <?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($r['dispo']) ?></td>
                    <td class="text-end"><?= $r['duration_seconds'] ?: '-' ?></td>
                    <td><?= $r['t'] ?></td>
                    <td><?= htmlspecialchars($r['short_notes']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- LEADS SUBIDOS -->
<h4 class="mt-4">📥 Leads Subidos por <?= htmlspecialchars($agent['name']) ?></h4>
<table class="table table-sm table-striped">
    <thead class="table-light">
        <tr><th>Nombre</th><th>Teléfono</th><th>Fecha de carga</th></tr>
    </thead>
    <tbody>
        <?php foreach ($leadsList as $l): ?>
            <tr>
                <td><?= htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) ?></td>
                <td><?= htmlspecialchars($l['phone']) ?></td>
                <td><?= date('M j, Y', strtotime($l['created_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<p class="text-muted small mt-4">Usuario creado: <?= date('M d Y', strtotime($agent['created_at'])) ?></p>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap @5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>