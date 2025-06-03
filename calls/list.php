<?php
// /calls/list.php

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

if (!Auth::check()) {
  header('Location: /auth/login.php');
  exit;
}

$pdo = getPDO();
$user = Auth::user();

// Configuración de paginación
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 20);
$perPage = in_array($perPage, [10, 20, 50, 100]) ? $perPage : 20;
$offset = ($page - 1) * $perPage;

// Cargar opciones de filtro
$usersList = $pdo->query("SELECT id, name FROM users ORDER BY name")->fetchAll();
$leadsList = $pdo->query("SELECT id, first_name, last_name FROM leads ORDER BY first_name")->fetchAll();
$dispositions = $pdo->query("SELECT id, name FROM dispositions ORDER BY name")->fetchAll();

// Construir condiciones según filtros GET
$conds = [];
$params = [];

if (!empty($_GET['user'])) {
  $conds[] = "i.user_id = ?";
  $params[] = $_GET['user'];
}

if (!empty($_GET['lead'])) {
  $conds[] = "i.lead_id = ?";
  $params[] = $_GET['lead'];
}

if (!empty($_GET['disposition'])) {
  $conds[] = "i.disposition_id = ?";
  $params[] = $_GET['disposition'];
}

if (!empty($_GET['date_from'])) {
  $conds[] = "DATE(i.created_at) >= ?";
  $params[] = $_GET['date_from'];
}

if (!empty($_GET['date_to'])) {
  $conds[] = "DATE(i.created_at) <= ?";
  $params[] = $_GET['date_to'];
}

$where = !empty($conds) ? "WHERE " . implode(" AND ", $conds) : "";

// Contar llamadas filtradas
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM interactions i
    JOIN users u ON i.user_id = u.id
    JOIN leads l ON i.lead_id = l.id
    $where
");
$countStmt->execute($params);
$totalCalls = (int) $countStmt->fetchColumn();
$totalPages = (int) ceil($totalCalls / $perPage);

// Cargar llamadas para esta página (CORREGIDO)
$stmt = $pdo->prepare("
    SELECT 
        i.id,
        i.created_at,
        i.duration_seconds,
        d.name AS disposition,
        u.name AS user,
        l.id AS lead_id,
        CONCAT(l.first_name, ' ', l.last_name) AS lead_name,
        l.phone
    FROM interactions i
    JOIN users u ON i.user_id = u.id
    JOIN leads l ON i.lead_id = l.id
    JOIN dispositions d ON i.disposition_id = d.id
    $where
    ORDER BY i.created_at DESC
    LIMIT $offset, $perPage
");
$stmt->execute($params);
$calls = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Call History</title>
  <link rel="stylesheet" href="./../assets/css/app.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    .filters-form .form-group {
      margin-bottom: 1rem;
    }

    .filters-form label {
      display: block;
      font-weight: bold;
      margin-bottom: 0.5rem;
    }

    .filters-form input[type="text"],
    .filters-form input[type="date"],
    .filters-form select {
      width: 100%;
      padding: 0.4rem;
      border-radius: 4px;
      border: 1px solid #ccc;
    }

    .actions {
      margin-bottom: 1rem;
    }

    .btn-group {
      display: flex;
      gap: 0.5rem;
    }
  </style>
</head>

<body>

  <div class="container">

    <h1><i class="fas fa-phone"></i> Call History</h1>

    <div class="actions">
      <div class="btn-group">
        <a class="btn" href="../leads/list.php"><i class="fas fa-address-book"></i> Leads</a>
        <a class="btn btn-secondary" href="../auth/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
      </div>
    </div>

    <form method="get" class="filters-form mb-4">
      <div style="display: flex; flex-wrap: wrap; gap: 1rem;">
        <div class="form-group">
          <label for="user">User</label>
          <select id="user" name="user" class="form-control">
            <option value="">All Users</option>
            <?php foreach ($usersList as $u): ?>
              <option value="<?= $u['id'] ?>" <?= ($_GET['user'] ?? '') == $u['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($u['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label for="lead">Lead</label>
          <select id="lead" name="lead" class="form-control">
            <option value="">All Leads</option>
            <?php foreach ($leadsList as $l): ?>
              <option value="<?= $l['id'] ?>" <?= ($_GET['lead'] ?? '') == $l['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars(trim("{$l['first_name']} {$l['last_name']}")) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label for="disposition">Disposition</label>
          <select id="disposition" name="disposition" class="form-control">
            <option value="">All Dispositions</option>
            <?php foreach ($dispositions as $d): ?>
              <option value="<?= $d['id'] ?>" <?= ($_GET['disposition'] ?? '') == $d['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label for="date_from">Date From</label>
          <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label for="date_to">Date To</label>
          <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
        </div>
      </div>

      <div style="margin-top: 1rem;">
        <button type="submit" class="btn"><i class="fas fa-filter"></i> Apply Filters</button>
        <a href="list.php" class="btn btn-secondary">Clear Filters</a>
      </div>
    </form>

    <table class="table">
      <thead>
        <tr>
          <th>Date</th>
          <th>User</th>
          <th>Lead</th>
          <th>Phone</th>
          <th>Duration</th>
          <th>Disposition</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$calls): ?>
          <tr>
            <td colspan="7" style="text-align:center;">No calls found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($calls as $call): ?>
            <tr>
              <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($call['created_at']))) ?></td>
              <td><?= htmlspecialchars($call['user']) ?></td>
              <td><?= htmlspecialchars($call['lead_name']) ?></td>
              <td><?= htmlspecialchars($call['phone']) ?></td>
              <td><?= $call['duration_seconds'] ? gmdate('H:i:s', $call['duration_seconds']) : '-' ?></td>
              <td><?= htmlspecialchars($call['disposition']) ?></td>
              <td>
                <a href="../leads/view.php?id=<?= htmlspecialchars($call['lead_id']) ?>">
                  <i class="fas fa-eye"></i> View Lead
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <a href="?page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>

  </div>

</body>
</html>