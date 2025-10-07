<?php
// /calls/list.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

if (!Auth::check()) {
    header('Location: /auth/login.php'); exit;
}

$pdo  = getPDO();
$user = Auth::user();

/* ---------- Pagination ---------- */
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 20);
$perPage = in_array($perPage, [10,20,50,100], true) ? $perPage : 20;
$offset  = ($page - 1) * $perPage;

/* ---------- Filters (safe) ---------- */
$conds  = [];
$params = [];

if (!empty($_GET['user']) && ctype_digit((string)$_GET['user'])) {
    $conds[]  = "i.user_id = ?";
    $params[] = (int)$_GET['user'];
}
if (!empty($_GET['lead']) && ctype_digit((string)$_GET['lead'])) {
    $conds[]  = "i.lead_id = ?";
    $params[] = (int)$_GET['lead'];
}
if (!empty($_GET['disposition']) && ctype_digit((string)$_GET['disposition'])) {
    $conds[]  = "i.disposition_id = ?";
    $params[] = (int)$_GET['disposition'];
}
if (!empty($_GET['date_from'])) {
    $dateFrom = date('Y-m-d', strtotime($_GET['date_from']));
    $conds[]  = "DATE(i.created_at) >= ?";
    $params[] = $dateFrom;
}
if (!empty($_GET['date_to'])) {
    $dateTo   = date('Y-m-d', strtotime($_GET['date_to']));
    $conds[]  = "DATE(i.created_at) <= ?";
    $params[] = $dateTo;
}

$where = $conds ? "WHERE " . implode(" AND ", $conds) : "";

/* ---------- Options for selects ---------- */
$usersList     = $pdo->query("SELECT id, name FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$leadsList     = $pdo->query("SELECT id, first_name, last_name FROM leads ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);
$dispositions  = $pdo->query("SELECT id, name FROM dispositions ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Count + Fetch ---------- */
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM interactions i
    JOIN users u ON i.user_id = u.id
    JOIN leads l ON i.lead_id = l.id
    $where
");
$countStmt->execute($params);
$totalCalls = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCalls / $perPage));

$stmt = $pdo->prepare("
    SELECT 
        i.id,
        i.created_at,
        i.duration_seconds,
        d.name AS disposition,
        u.name AS user,
        l.id   AS lead_id,
        CONCAT(l.first_name,' ',l.last_name) AS lead_name,
        l.phone
    FROM interactions i
    JOIN users u        ON i.user_id = u.id
    JOIN leads l        ON i.lead_id = l.id
    JOIN dispositions d ON i.disposition_id = d.id
    $where
    ORDER BY i.created_at DESC
    LIMIT $offset, $perPage
");
$stmt->execute($params);
$calls = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dur_mmss(?int $s): string {
    if (!$s) return '-';
    $m = floor($s/60);
    $r = $s%60;
    return sprintf('%02d:%02d', $m, $r);
}
/** Preserve current filters in links */
function query_keep(array $extra = []): string {
    $keep = [
        'user'        => $_GET['user']        ?? '',
        'lead'        => $_GET['lead']        ?? '',
        'disposition' => $_GET['disposition'] ?? '',
        'date_from'   => $_GET['date_from']   ?? '',
        'date_to'     => $_GET['date_to']     ?? '',
        'per_page'    => $_GET['per_page']    ?? '',
    ];
    $q = array_filter($keep, fn($v)=>$v!=='');
    $q = array_merge($q, $extra);
    return http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Call History</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"> 
  <link rel="stylesheet" href="../assets/css/calls/list.css">
</head>
<body>

<div class="container">

  <h1><i class="fas fa-phone"></i> Call History</h1>

  <!-- Navigation -->
  <nav class="nav">
    <a href="../leads/list.php" class="leads-btn"><i class="fas fa-address-book"></i> Leads</a>
    <a href="../auth/logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
  </nav>

  <!-- Filters -->
  <form method="get" class="filters mb-4" novalidate>
    <div class="filter-row">
      <div class="form-group">
        <label for="user">User</label>
        <select id="user" name="user" class="form-control">
          <option value="">All Users</option>
          <?php foreach ($usersList as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (($_GET['user'] ?? '') == $u['id']) ? 'selected' : '' ?>>
              <?= h($u['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="lead">Lead</label>
        <select id="lead" name="lead" class="form-control">
          <option value="">All Leads</option>
          <?php foreach ($leadsList as $l): ?>
            <option value="<?= (int)$l['id'] ?>" <?= (($_GET['lead'] ?? '') == $l['id']) ? 'selected' : '' ?>>
              <?= h(trim("{$l['first_name']} {$l['last_name']}")) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="disposition">Disposition</label>
        <select id="disposition" name="disposition" class="form-control">
          <option value="">All Dispositions</option>
          <?php foreach ($dispositions as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= (($_GET['disposition'] ?? '') == $d['id']) ? 'selected' : '' ?>>
              <?= h($d['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="date_from">Date From</label>
        <input type="date" id="date_from" name="date_from" value="<?= h($_GET['date_from'] ?? '') ?>" class="form-control">
      </div>

      <div class="form-group">
        <label for="date_to">Date To</label>
        <input type="date" id="date_to" name="date_to" value="<?= h($_GET['date_to'] ?? '') ?>" class="form-control">
      </div>

      <div class="form-group">
        <label for="per_page">Per Page</label>
        <select id="per_page" name="per_page" class="form-control">
          <?php foreach ([10,20,50,100] as $opt): ?>
            <option value="<?= $opt ?>" <?= $perPage===$opt?'selected':''; ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
        <a href="list.php" class="btn btn-secondary">Clear</a>
      </div>
    </div>
  </form>

  <!-- Call Table -->
  <div class="table-container">
    <table class="call-table">
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
          <tr><td colspan="7" class="empty">No calls found.</td></tr>
        <?php else: foreach ($calls as $call): ?>
          <tr>
            <td><?= h(date('Y-m-d H:i', strtotime($call['created_at']))) ?></td>
            <td><?= h($call['user']) ?></td>
            <td><?= h($call['lead_name']) ?></td>
            <td>
              <?php $ph = preg_replace('/\s+/', '', (string)$call['phone']); ?>
              <a href="tel:<?= h($ph) ?>"><?= h($call['phone']) ?></a>
            </td>
            <td><?= dur_mmss($call['duration_seconds'] ? (int)$call['duration_seconds'] : null) ?></td>
            <td><?= h($call['disposition']) ?></td>
            <td class="actions-cell">
              <a href="../leads/view.php?id=<?= (int)$call['lead_id'] ?>" class="btn btn-view">
                <i class="fas fa-eye"></i> View Lead
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): 
    $qsFirst = query_keep(['page'=>1]);
    $qsPrev  = query_keep(['page'=>max(1,$page-1)]);
    $qsNext  = query_keep(['page'=>min($totalPages,$page+1)]);
    $qsLast  = query_keep(['page'=>$totalPages]);
  ?>
    <nav class="pagination" aria-label="Page navigation">
      <a class="page-link" href="?<?= $qsFirst ?>">First</a>
      <a class="page-link" href="?<?= $qsPrev ?>">Prev</a>
      <?php for ($i=1; $i<=$totalPages; $i++): ?>
        <a class="page-link <?= $i===$page?'active':'' ?>" href="?<?= query_keep(['page'=>$i]) ?>"><?= $i ?></a>
      <?php endfor; ?>
      <a class="page-link" href="?<?= $qsNext ?>">Next</a>
      <a class="page-link" href="?<?= $qsLast ?>">Last</a>
    </nav>
  <?php endif; ?>

</div>
</body>
</html>
