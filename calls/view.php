<?php
require_once __DIR__ . './../lib/Auth.php';
require_once __DIR__ . './../lib/db.php';

if (!Auth::check()) {
    header('Location: /auth/login.php');
    exit;
}

$leadId = (int) ($_GET['lead_id'] ?? 0);
$pdo = getPDO();

// Validar que el lead existe
$stmt = $pdo->prepare("SELECT id FROM leads WHERE id = ?");
$stmt->execute([$leadId]);
if (!$stmt->fetch()) {
    die("Lead no encontrado.");
}

// Obtener llamadas del lead
$stmt = $pdo->prepare("SELECT i.*, u.name AS user
                       FROM interactions i
                       JOIN users u ON i.user_id = u.id
                       WHERE i.lead_id = ?
                       ORDER BY i.created_at DESC");
$stmt->execute([$leadId]);
$calls = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Llamadas para Lead #<?= $leadId ?></title>
    <link rel="stylesheet" href="./../assets/css/calls/calls.css">
</head>
<body>
<div class="container">
    <h1>Llamadas para Lead #<?= $leadId ?></h1>

    <table class="table">
        <thead>
        <tr>
            <th>Fecha</th>
            <th>Usuario</th>
            <th>Duración (seg)</th>
            <th>Resultado</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($calls as $call): ?>
            <tr>
                <td><?= date('d/m/Y H:i', strtotime($call['created_at'])) ?></td>
                <td><?= htmlspecialchars($call['user']) ?></td>
                <td><?= $call['duration_seconds'] ?: '-' ?></td>
                <td><?= htmlspecialchars($call['disposition'] ?? '-') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <a href="/leads/view.php?id=<?= $leadId ?>" class="btn">Volver al Lead</a>
</div>
</body>
</html>