<?php
// /calls/my_interactions.php

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Interaction.php';
require_once __DIR__ . '/../lib/db.php';

if (!Auth::check()) {
    header('Location: /auth/login.php');
    exit;
}

$user = Auth::user();
$pdo = getPDO();

// --- Load filter options ---
$dispositions = $pdo->query("SELECT id, name FROM dispositions ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$interests = $pdo->query("SELECT id, name FROM insurance_interests WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// --- Build query conditions from GET params ---
$conds = ["i.user_id = :uid"];
$params = [':uid' => $user['id']];

if (!empty($_GET['from'])) {
    $conds[] = "i.interaction_time >= :from";
    $params[':from'] = $_GET['from'];
}
if (!empty($_GET['to'])) {
    $conds[] = "i.interaction_time <= :to";
    $params[':to'] = $_GET['to'];
}
if (!empty($_GET['disposition'])) {
    $conds[] = "i.disposition_id = :disp";
    $params[':disp'] = (int)$_GET['disposition'];
}
if (!empty($_GET['lead_search'])) {
    $conds[] = "(l.id = :ls OR CONCAT(l.prefix,' ',l.first_name,' ',l.mi,' ',l.last_name) LIKE :lsn)";
    $params[':ls'] = $_GET['lead_search'];
    $params[':lsn'] = '%' . $_GET['lead_search'] . '%';
}
if (!empty($_GET['interest'])) {
    $conds[] = "l.insurance_interest_id = :int";
    $params[':int'] = (int)$_GET['interest'];
}

$where = implode(' AND ', $conds);

// --- Main query ---
$sql = "
  SELECT
    i.interaction_time,
    i.duration_seconds,
    i.notes,
    d.name AS disposition,
    l.id AS lead_id,
    CONCAT_WS(' ', l.prefix, l.first_name, l.mi, l.last_name) AS lead_name,
    l.phone
  FROM interactions i
  JOIN dispositions d ON i.disposition_id = d.id
  JOIN leads l ON i.lead_id = l.id
  WHERE $where
  ORDER BY i.interaction_time DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$calls = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>My Calls</title>
  <link rel="stylesheet" href="../assets/css/calls/my_interactions.css">
  <script defer src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js "></script>
</head>
<body>

<div class="container">
  <h1><i class="fas fa-phone"></i> My Calls</h1>

  <!-- Back button -->
  <p>
    <a href="../leads/list.php" class="btn btn-secondary">
      ← Back to Leads
    </a>
  </p>

  <!-- Filter Form -->
  <form method="get" class="filters">
    <div class="filter-row">
      <div class="form-group">
        <label for="from">From:</label>
        <input type="date" id="from" name="from" value="<?= htmlspecialchars($_GET['from'] ?? '') ?>" class="form-control">
      </div>

      <div class="form-group">
        <label for="to">To:</label>
        <input type="date" id="to" name="to" value="<?= htmlspecialchars($_GET['to'] ?? '') ?>" class="form-control">
      </div>

      <div class="form-group">
        <label for="disposition">Outcome:</label>
        <select id="disposition" name="disposition" class="form-control">
          <option value="">All</option>
          <?php foreach ($dispositions as $d): ?>
            <option value="<?= $d['id'] ?>" <?= ($_GET['disposition'] ?? '') == $d['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($d['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="lead_search">Lead (ID or name):</label>
        <input type="text" id="lead_search" name="lead_search"
               value="<?= htmlspecialchars($_GET['lead_search'] ?? '') ?>"
               placeholder="Search by ID or name"
               class="form-control">
      </div>

      <div class="form-group">
        <label for="interest">Interest:</label>
        <select id="interest" name="interest" class="form-control">
          <option value="">All</option>
          <?php foreach ($interests as $it): ?>
            <option value="<?= $it['id'] ?>" <?= ($_GET['interest'] ?? '') == $it['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($it['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>&nbsp;</label><br>
        <button type="submit" class="btn btn-primary">Filter</button>
      </div>

      <div class="form-group">
        <label>&nbsp;</label><br>
        <a href="my_interactions.php" class="btn btn-clear">Clear</a>
      </div>
    </div>
  </form>

  <!-- Table Container -->
  <div class="table-container">
    <table class="call-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Lead</th>
          <th>Phone</th>
          <th>Outcome</th>
          <th>Duration</th>
          <th>Notes</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($calls)): ?>
          <tr>
            <td colspan="7" style="text-align:center;">No calls found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($calls as $c): ?>
            <tr>
              <td><?= htmlspecialchars($c['interaction_time']) ?></td>
              <td>
                <a href="../leads/view.php?id=<?= $c['lead_id'] ?>">
                  <?= htmlspecialchars($c['lead_name']) ?>
                </a>
              </td>
              <td><?= htmlspecialchars($c['phone']) ?></td>
              <td><?= htmlspecialchars($c['disposition']) ?></td>
              <td><?= htmlspecialchars($c['duration_seconds']) ?> s</td>
              <td><?= nl2br(htmlspecialchars(mb_strimwidth($c['notes'], 0, 50, '…'))) ?></td>
              <td class="actions-cell">
                <a href="../leads/view.php?id=<?= $c['lead_id'] ?>" class="btn btn-view btn-sm">View Lead</a>
                <a href="../calls/add.php?lead_id=<?= $c['lead_id'] ?>" class="btn btn-register btn-sm">Register Call</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</body>
</html>