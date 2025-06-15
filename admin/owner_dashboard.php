<?php
// owner_dashboard.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

// Get current user and roles
$user = Auth::user();
$roles = $user['roles'] ?? [];

// Authorization check - allow both owner and admin
if (!in_array('owner', $roles) && !in_array('admin', $roles)) {
    header('Location: ./../leads/list.php');
    exit;
}

// Get PDO instance
$pdo = getPDO();

// Get all agents (sales role)
$stmt = $pdo->prepare("
    SELECT u.id, u.name, COUNT(l.id) AS client_count, MIN(l.taken_at) AS oldest_client
    FROM users u
    LEFT JOIN leads l ON u.id = l.taken_by
    WHERE u.id IN (
        SELECT user_id 
        FROM user_roles 
        WHERE role_id = (SELECT id FROM roles WHERE name = 'sales')
    )
    GROUP BY u.id
    ORDER BY u.name
");
$stmt->execute();
$agents = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Agent Client Dashboard</title>
    <!-- Load Custom CSS -->
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        body {
            background-color: #f5f5f5;
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 1000px;
            margin: auto;
        }
        .back-button {
            display: inline-block;
            margin-bottom: 1.5em;
            padding: 0.6em 1em;
            background-color: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 0.95em;
            transition: background-color 0.3s ease;
        }
        .back-button:hover {
            background-color: #2980b9;
        }
        h1 {
            font-size: 1.8em;
            margin-bottom: 1em;
            color: #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background-color: white;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            border-radius: 6px;
            overflow: hidden;
        }
        th, td {
            padding: 0.75em;
            text-align: left;
            vertical-align: middle;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #ecf0f1;
            font-weight: bold;
        }
        tr:hover {
            background-color: #f9f9f9;
        }
        .badge {
            padding: 0.4em 0.6em;
            font-size: 0.9em;
            border-radius: 4px;
            color: white;
            background-color: #3498db;
        }
        .text-muted {
            color: #777;
        }
        footer {
            margin-top: 3em;
            text-align: center;
            color: #aaa;
            font-size: 0.9em;
        }
    </style>
</head>
<body>

<div class="container">

    <!-- Back to Leads Button -->
    <a href="../leads/list.php" class="back-button">← Back to Leads</a>

    <!-- Page Title -->
    <h1>Agent Client Dashboard</h1>

    <!-- Agent Stats Table -->
    <table>
        <thead>
            <tr>
                <th>Agent</th>
                <th class="text-center">Total Clients</th>
                <th>Oldest Client Since</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($agents) > 0): ?>
                <?php foreach ($agents as $agent): ?>
                    <tr>
                        <td>
                            <a href="agent_detail.php?id=<?= htmlspecialchars($agent['id']) ?>">
                                <?= htmlspecialchars($agent['name']) ?>
                            </a>
                        </td>
                        <td class="text-center">
                            <span class="badge">
                                <?= (int)$agent['client_count'] ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($agent['oldest_client']): ?>
                                <?= date('M d, Y', strtotime($agent['oldest_client'])) ?>
                            <?php else: ?>
                                <span class="text-muted">No clients</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="3" class="text-center py-4">
                        <div class="text-muted">No sales agents found</div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <footer>
        <p>Last updated: <?= date('M d, Y H:i') ?></p>
    </footer>

</div>

</body>
</html>