<?php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';
if (!Auth::check() || !array_intersect(['owner', 'admin'], Auth::user()['roles'] ?? [])) {
    http_response_code(403);
    header('Location: ../auth/login.php');
    exit;
}
$pdo = getPDO();
date_default_timezone_set('America/Santo_Domingo');
$today      = (new DateTime())->format('Y-m-d');
$monthStart = (new DateTime('first day of this month'))->format('Y-m-d 00:00:00');
/* ---------- KPI BLOCKS ---------- */
$stats = [];
// Total Leads
$stats['total_leads'] = (int)$pdo->query("SELECT COUNT(*) FROM leads")->fetchColumn();
// Leads today
$stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE DATE(created_at)=?");
$stmt->execute([$today]);
$stats['new_leads_today'] = (int)$stmt->fetchColumn();
// Calls today
$stmt = $pdo->prepare("SELECT COUNT(*) FROM interactions WHERE DATE(interaction_time)=?");
$stmt->execute([$today]);
$stats['calls_today'] = (int)$stmt->fetchColumn();
// Sales this month
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM interactions i
    JOIN dispositions d ON d.id = i.disposition_id
    WHERE d.name = 'Service Sold' AND i.interaction_time >= ?
");
$stmt->execute([$monthStart]);
$stats['sales_month'] = (int)$stmt->fetchColumn();
/* ---------- LEADS UPLOADED BY USER ---------- */
$subidosPorUsuario = $pdo->query("
    SELECT u.id, u.name, COUNT(l.id) AS total
    FROM leads l
    JOIN users u ON u.id = l.uploaded_by
    GROUP BY l.uploaded_by
")->fetchAll(PDO::FETCH_ASSOC);
/* ---------- TOP CALLERS & SALES (this month) ---------- */
$topCallers = $pdo->prepare("
    SELECT u.id, u.name,
           COUNT(*) AS calls,
           SUM(CASE WHEN d.name = 'Service Sold' THEN 1 ELSE 0 END) AS sales
    FROM interactions i
    JOIN users u ON u.id = i.user_id
    JOIN dispositions d ON d.id = i.disposition_id
    WHERE i.interaction_time >= ?
    GROUP BY i.user_id
    ORDER BY calls DESC
    LIMIT 5
");
$topCallers->execute([$monthStart]);
$topCallers = $topCallers->fetchAll(PDO::FETCH_ASSOC);
/* ---------- LEADS WITHOUT INTERACTION ---------- */
$leadsSinInteraccion = $pdo->query("
    SELECT l.id, l.first_name, l.last_name, u.name AS uploaded_by
    FROM leads l
    LEFT JOIN interactions i ON i.lead_id = l.id
    JOIN users u ON u.id = l.uploaded_by
    WHERE i.id IS NULL
    ORDER BY l.created_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
/* ---------- RECENT INTERACTIONS ---------- */
$recentInteractions = $pdo->query("
    SELECT i.id,
           CONCAT(l.first_name, ' ', l.last_name) AS lead,
           u.name AS agent,
           d.name AS disposition,
           DATE_FORMAT(i.interaction_time, '%Y-%m-%d %H:%i') AS happened_at
    FROM interactions i
    JOIN leads l ON l.id = i.lead_id
    JOIN users u ON u.id = i.user_id
    JOIN dispositions d ON d.id = i.disposition_id
    ORDER BY i.interaction_time DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
/* ---------- RECENT ACTIVITY (logs) ---------- */
$auditLogs = $pdo->query("
    SELECT a.action, a.description, u.name AS user, a.created_at
    FROM audit_logs a
    JOIN users u ON u.id = a.user_id
    ORDER BY a.created_at DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Owner Dashboard • MiniKissCRM</title>
    <link rel="stylesheet" href="./../assets/css/app.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap @5.3.3/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js "></script>
    <style>
        .card-title { font-size: 1rem; }
        .display-6 { font-size: 2rem; }
    </style>
</head>
<body>
<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="mb-0">👑 Owner Dashboard</h1>
        <a href="../leads/list.php" class="btn btn-sm btn-outline-secondary">← Back to Leads</a>
    </div>
    <!-- KPIs -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-start border-primary border-4">
                <div class="card-body">
                    <h5 class="card-title text-muted">Total Leads</h5>
                    <p class="display-6 mb-0"><?= $stats['total_leads'] ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-start border-success border-4">
                <div class="card-body">
                    <h5 class="card-title text-muted">Leads Today</h5>
                    <p class="display-6 mb-0"><?= $stats['new_leads_today'] ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-start border-warning border-4">
                <div class="card-body">
                    <h5 class="card-title text-muted">Calls Today</h5>
                    <p class="display-6 mb-0"><?= $stats['calls_today'] ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-start border-danger border-4">
                <div class="card-body">
                    <h5 class="card-title text-muted">Sales This Month</h5>
                    <p class="display-6 mb-0"><?= $stats['sales_month'] ?></p>
                </div>
            </div>
        </div>
    </div>
    <!-- Charts -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <h4>📞 Calls by Agent (<?= (new DateTime())->format('F Y'); ?>)</h4>
            <canvas id="callersChart" height="100"></canvas>
        </div>
        <div class="col-lg-6">
            <h4>🏆 Sales by Agent (<?= (new DateTime())->format('F Y'); ?>)</h4>
            <canvas id="salesChart" height="100"></canvas>
        </div>
    </div>
    <!-- Leads uploaded by user -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <h4>📥 Leads Uploaded by Seller</h4>
            <table class="table table-striped table-sm">
                <thead class="table-light">
                    <tr><th>Seller</th><th>Leads Uploaded</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($subidosPorUsuario as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td class="text-end"><?= $row['total'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Leads without interaction -->
        <div class="col-lg-6">
            <h4>⚠️ Leads Without Interaction</h4>
            <table class="table table-sm table-hover">
                <thead class="table-light">
                    <tr><th>Lead</th><th>Uploaded by</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($leadsSinInteraccion as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></td>
                            <td><?= htmlspecialchars($row['uploaded_by']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <!-- Bar charts -->
    <div class="row g-4 mb-4">
        <div class="col-lg-12">
            <h4>📊 Leads by Uploader</h4>
            <canvas id="leadsByUploaderChart" height="50"></canvas>
        </div>
    </div>
    <!-- Recent interactions -->
    <div class="row mb-4">
        <div class="col">
            <h4>🕑 Latest 10 Interactions</h4>
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Lead</th>
                            <th>Agent</th>
                            <th>Disposition</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentInteractions as $row): ?>
                            <tr>
                                <td><?= $row['id'] ?></td>
                                <td><?= htmlspecialchars($row['lead']) ?></td>
                                <td><?= htmlspecialchars($row['agent']) ?></td>
                                <td><?= htmlspecialchars($row['disposition']) ?></td>
                                <td><?= $row['happened_at'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <!-- Audit logs -->
    <div class="row mb-4">
        <div class="col">
            <h4>📋 Recent Activity</h4>
            <table class="table table-sm table-bordered">
                <thead class="table-light">
                    <tr><th>User</th><th>Action</th><th>Description</th><th>Date</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($auditLogs as $log): ?>
                        <tr>
                            <td><?= htmlspecialchars($log['user']) ?></td>
                            <td><?= htmlspecialchars($log['action']) ?></td>
                            <td><?= htmlspecialchars($log['description']) ?></td>
                            <td><?= $log['created_at'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<!-- Chart.js -->
<script>
const ctx1 = document.getElementById('callersChart').getContext('2d');
const callersChart = new Chart(ctx1, {
    type: 'bar',
    data: {
        labels: [<?= "'".implode("','", array_column($topCallers, 'name'))."'" ?>],
        datasets: [{
            label: 'Calls Made',
            backgroundColor: '#4e73df',
            data: [<?= implode(',', array_column($topCallers, 'calls')) ?>]
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false }, tooltip: { enabled: true } },
        scales: { x: { ticks: { autoSkip: false } }, y: { beginAtZero: true } }
    }
});
const ctx2 = document.getElementById('salesChart').getContext('2d');
const salesChart = new Chart(ctx2, {
    type: 'bar',
    data: {
        labels: [<?= "'".implode("','", array_column($topCallers, 'name'))."'" ?>],
        datasets: [{
            label: 'Sales Closed',
            backgroundColor: '#1cc88a',
            data: [<?= implode(',', array_column($topCallers, 'sales')) ?>]
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false }, tooltip: { enabled: true } },
        scales: { x: { ticks: { autoSkip: false } }, y: { beginAtZero: true } }
    }
});
const ctx3 = document.getElementById('leadsByUploaderChart').getContext('2d');
const leadsByUploaderChart = new Chart(ctx3, {
    type: 'bar',
    data: {
        labels: [<?= "'".implode("','", array_column($subidosPorUsuario, 'name'))."'" ?>],
        datasets: [{
            label: 'Leads Uploaded',
            backgroundColor: '#f6c23e',
            data: [<?= implode(',', array_column($subidosPorUsuario, 'total')) ?>]
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false }, tooltip: { enabled: true } },
        scales: { x: { ticks: { autoSkip: false } }, y: { beginAtZero: true } }
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap @5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>