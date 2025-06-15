<?php
// agent_detail.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

// Get current user and roles
$user = Auth::user();
$roles = $user['roles'] ?? [];

// Authorization check - allow both owner and admin
if (!in_array('owner', $roles) && !in_array('admin', $roles)) {
    header('Location: /leads/list.php');
    exit;
}

// Get agent ID from URL
$agent_id = isset($_GET['id']) ? intval($_GET['id']) : null;

// Validate agent ID
if (!$agent_id) {
    header('Location: /admin/owner_dashboard.php');
    exit;
}

// Get PDO instance
$pdo = getPDO();

// Fetch agent details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$agent_id]);
$agent = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch agent's leads
$stmt = $pdo->prepare("
    SELECT l.*, i.disposition_id, d.name AS disposition_name
    FROM leads l
    LEFT JOIN interactions i ON l.id = i.lead_id
    LEFT JOIN dispositions d ON i.disposition_id = d.id
    WHERE l.taken_by = ?
");
$stmt->execute([$agent_id]);
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Agent Details: <?= htmlspecialchars($agent['name']) ?></title>
    <!-- Load Custom CSS -->
    <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>

<div class="container">

    <!-- Back Button -->
    <a href="owner_dashboard.php" class="back-button">← Back to Agent Dashboard</a>

    <!-- Page Title -->
    <h1>Agent Details: <?= htmlspecialchars($agent['name']) ?></h1>

    <!-- Agent Leads Table -->
    <div class="card shadow-sm">
        <div class="card-header">
            Agent Leads
        </div>
        <div class="card-body">
            <?php if (count($leads) > 0): ?>
                <table class="table table-hover mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>Lead Name</th>
                            <th>Contacted On</th>
                            <th>Disposition</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leads as $lead): ?>
                            <tr>
                                <td>
                                    <a href="../leads/view.php?id=<?= $lead['id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($lead['first_name'] . ' ' . $lead['last_name']) ?>
                                    </a>
                                </td>
                                <td><?= $lead['taken_at'] ? date('M d, Y H:i', strtotime($lead['taken_at'])) : 'N/A' ?></td>
                                <td><?= htmlspecialchars($lead['disposition_name'] ?? 'Not Contacted') ?></td>
                                <td><?= htmlspecialchars($lead['status_id'] ? 'Active' : 'Closed') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="text-muted text-center py-4">No leads assigned to this agent.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Footer -->
    <footer>
        <p>Last updated: <?= date('M d, Y H:i') ?></p>
    </footer>

</div>

</body>
</html>