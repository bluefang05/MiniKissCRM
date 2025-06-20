<?php
// Start session early
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

if (!Auth::check()) {
  header('Location: ./../auth/login.php');
  exit;
}



$user = Auth::user();
$roles = $user['roles'] ?? [];

// Capabilities
$canCreate = (bool) array_intersect($roles, ['admin', 'lead_manager']);
$canImport = $canCreate;
$canViewCalls = (bool) array_intersect($roles, ['admin', 'sales']);
$canManageUsers = in_array('admin', $roles, true);
$canEdit = $canCreate;
$canCall = (bool) array_intersect($roles, ['admin', 'sales']);
$canViewDashboard = (bool) array_intersect($roles, ['admin', 'viewer']);
$canViewOwnerDashboard = (bool) array_intersect($roles, ['admin', 'owner']);

$pdo = getPDO();

//---------------------------------------------
// Pagination
//---------------------------------------------
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 20);
$perPage = in_array($perPage, [10, 20, 50, 100]) ? $perPage : 20;
$offset = ($page - 1) * $perPage;

//---------------------------------------------
// Look-ups
//---------------------------------------------
$sources = $pdo->query("SELECT id, name FROM lead_sources WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$interests = $pdo->query("SELECT id, name FROM insurance_interests WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$languages = $pdo->query("SELECT code, description FROM language_codes ORDER BY description")->fetchAll(PDO::FETCH_ASSOC);
$incomes = $pdo->query("SELECT code, description FROM income_ranges ORDER BY description")->fetchAll(PDO::FETCH_ASSOC);
$dispositions = $pdo->query("SELECT id, name FROM dispositions ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

//---------------------------------------------
// Build WHERE according to filters
//---------------------------------------------
$conds = ["1=1"];
$params = [];

$filterMap = [
  'source' => ['l.source_id', PDO::PARAM_INT],
  'interest' => ['l.insurance_interest_id', PDO::PARAM_INT],
  'language' => ['l.language', PDO::PARAM_STR],
  'income' => ['l.income', PDO::PARAM_STR],
  'age_min' => ['l.age >=', PDO::PARAM_INT],
  'age_max' => ['l.age <=', PDO::PARAM_INT],
  'disposition' => ['d.id', PDO::PARAM_INT],
];

foreach ($filterMap as $key => [$expr, $type]) {
  if (!empty($_GET[$key])) {
    // Handle operators like >= or <= correctly
    if (preg_match('/[><=]/', $expr)) {
      $conds[] = "$expr ?";
    } else {
      $conds[] = "$expr = ?";
    }
    $params[] = $_GET[$key];
  }
}

if (!empty($_GET['search'])) {
  $search = "%{$_GET['search']}%";
  $conds[] = "(l.first_name LIKE ? OR l.last_name LIKE ? OR l.phone LIKE ?)";
  array_push($params, $search, $search, $search);
}

//---------------------------------------------
// Exclude leads locked by other users
//---------------------------------------------
$lockedStmt = $pdo->prepare(
  "SELECT lead_id FROM lead_locks WHERE expires_at >= NOW() AND user_id <> ?"
);
$lockedStmt->execute([$user['id']]);
$lockedIds = $lockedStmt->fetchAll(PDO::FETCH_COLUMN);
if ($lockedIds) {
  $placeholders = implode(',', array_fill(0, count($lockedIds), '?'));
  $conds[] = "l.id NOT IN ($placeholders)";
  $params = array_merge($params, $lockedIds);
}
$where = implode(' AND ', $conds);

//---------------------------------------------
// Totals & Pages
//---------------------------------------------
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM leads l
    LEFT JOIN (
        SELECT lead_id, disposition_id
        FROM interactions
        WHERE id IN (
            SELECT MAX(id) FROM interactions GROUP BY lead_id
        )
    ) latest_interactions ON l.id = latest_interactions.lead_id
    LEFT JOIN dispositions d ON latest_interactions.disposition_id = d.id
    WHERE $where");
$countStmt->execute($params);
$totalLeads = (int) $countStmt->fetchColumn();
$totalPages = (int) ceil($totalLeads / $perPage);

//---------------------------------------------
// Main query
//---------------------------------------------
$sql = "
    SELECT
        l.id,
        CONCAT_WS(' ', l.prefix, l.first_name, l.mi, l.last_name) AS full_name,
        l.phone,
        lc.description AS language_desc,
        ir.description AS income_desc,
        ii.name AS interest,
        d.name AS disposition,
        l.age,
        l.do_not_call,
        ll.user_id AS locked_by,
        ll.expires_at AS lock_expires
    FROM leads l
    LEFT JOIN language_codes lc ON l.language = lc.code
    LEFT JOIN income_ranges ir ON l.income = ir.code
    LEFT JOIN insurance_interests ii ON l.insurance_interest_id = ii.id
    LEFT JOIN lead_locks ll ON l.id = ll.lead_id AND ll.expires_at >= NOW()
    LEFT JOIN (
        SELECT lead_id, disposition_id
        FROM interactions
        WHERE id IN (
            SELECT MAX(id) FROM interactions GROUP BY lead_id
        )
    ) latest_interactions ON l.id = latest_interactions.lead_id
    LEFT JOIN dispositions d ON latest_interactions.disposition_id = d.id
    WHERE $where
    ORDER BY l.id DESC
    LIMIT $offset, $perPage";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

//---------------------------------------------
// Base URL for pagination links
//---------------------------------------------
$query = $_GET;
unset($query['page']);
$baseUrl = 'list.php' . (count($query) ? '?' . http_build_query($query) . '&' : '?');
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Leads List</title>
  <link rel="stylesheet" href="../assets/css/app.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    .badge {
      padding: 2px 6px;
      border-radius: 4px;
      font-size: .75rem;
      color: #fff;
    }

    .badge-green {
      background: #2c5d4a;
    }

    .badge-orange {
      background: #f57c00;
    }

    .badge-red {
      background: #d32f2f;
    }

    .row-actions a {
      margin-right: 4px;
    }

    form.filters-form select,
    form.filters-form input {
      margin: 0 6px 10px 0;
    }
  </style>
</head>

<body>
  <div class="container">
    <h1><i class="fas fa-address-book"></i> Leads List</h1>

    <!-- Actions -->
    <div class="actions">
      <?php if ($canViewDashboard): ?>
        <!-- Dashboard for users who can view metrics -->
        <a class="btn-slide" href="../admin/dashboard.php"><i class="fas fa-tachometer-alt"></i><span>
            Dashboard</span></a>
        <a class="btn-slide" href="../admin/my_metrics.php"><i class="fas fa-chart-line"></i><span> My Metrics</span></a>
      <?php endif; ?>

      <!-- Special dashboard only visible to admin or owner -->
      <?php if ($canViewOwnerDashboard): ?>
        <a class="btn-slide" href="./../admin/owner_dashboard.php"><i class="fas fa-crown"></i><span> Owner
            View</span></a>
      <?php endif; ?>

      <?php if ($canManageUsers): ?>
        <!-- Admin-level analytics -->
        <a class="btn-slide" href="../admin/all_metrics.php"><i class="fas fa-chart-pie"></i><span> All Metrics</span></a>
      <?php endif; ?>

      <?php if ($canViewCalls): ?>
        <!-- Call-related actions -->
        <a class="btn-slide" href="../calls/my_interactions.php"><i class="fas fa-phone"></i><span> My Calls</span></a>
        <a class="btn-slide" href="../calls/list.php"><i class="fas fa-phone-alt"></i><span> All Calls</span></a>
      <?php endif; ?>

      <?php if ($canCreate): ?>
        <!-- Lead creation -->
        <a class="btn-slide" href="add.php"><i class="fas fa-plus-circle"></i><span> New Lead</span></a>
      <?php endif; ?>

      <?php if ($canImport): ?>
        <!-- Import leads -->
        <a class="btn-slide" href="import.php"><i class="fas fa-file-import"></i><span> Import Leads</span></a>
      <?php endif; ?>

      <?php if ($canManageUsers): ?>
        <!-- User & document management -->
        <a class="btn-slide" href="../admin/users.php"><i class="fas fa-users-cog"></i><span> User Management</span></a>
        <a class="btn-slide" href="../admin/documents.php"><i class="fas fa-folder-open"></i><span> Documents</span></a>
      <?php endif; ?>

      <!-- Logout link always visible -->
      <a class="btn-slide btn-secondary" href="../auth/logout.php"><i class="fas fa-right-from-bracket"></i><span>
          Exit</span></a>
    </div>

    <!-- Filters Form -->
    <form method="get" class="filters-form">
      <input type="text" name="search" placeholder="Search..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">

      <select name="source">
        <option value="">Source</option>
        <?php foreach ($sources as $s): ?>
          <option value="<?= $s['id'] ?>" <?= ($_GET['source'] ?? '') == $s['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($s['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="interest">
        <option value="">Interest</option>
        <?php foreach ($interests as $i): ?>
          <option value="<?= $i['id'] ?>" <?= ($_GET['interest'] ?? '') == $i['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($i['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="disposition">
        <option value="">Disposition</option>
        <?php foreach ($dispositions as $d): ?>
          <option value="<?= $d['id'] ?>" <?= ($_GET['disposition'] ?? '') == $d['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($d['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="language">
        <option value="">Language</option>
        <?php foreach ($languages as $l): ?>
          <option value="<?= $l['code'] ?>" <?= ($_GET['language'] ?? '') == $l['code'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($l['description']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="income">
        <option value="">Income</option>
        <?php foreach ($incomes as $i): ?>
          <option value="<?= $i['code'] ?>" <?= ($_GET['income'] ?? '') == $i['code'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($i['description']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <input type="number" name="age_min" placeholder="Min Age" value="<?= htmlspecialchars($_GET['age_min'] ?? '') ?>">
      <input type="number" name="age_max" placeholder="Max Age" value="<?= htmlspecialchars($_GET['age_max'] ?? '') ?>">

      <button type="submit" class="btn"><i class="fas fa-filter"></i> Filter</button>
      <a href="list.php" class="btn btn-secondary">Clear Filters</a>
    </form>

    <!-- Leads Table -->
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Name</th>
          <th>Phone</th>
          <th>Age</th>
          <th>Income</th>
          <th>Language</th>
          <th>Interest</th>
          <th>Disposition</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($leads)): ?>
          <tr>
            <td colspan="9" style="text-align:center;">No leads found.</td>
          </tr>
        <?php else:
          foreach ($leads as $lead): ?>
            <tr>
              <td><?= $lead['id'] ?></td>
              <td><?= htmlspecialchars($lead['full_name']) ?></td>
              <td><?= htmlspecialchars($lead['phone']) ?></td>
              <td><?= $lead['age'] ?: '-' ?></td>
              <td><?= htmlspecialchars($lead['income_desc']) ?></td>
              <td><?= htmlspecialchars($lead['language_desc']) ?></td>
              <td><?= htmlspecialchars($lead['interest']) ?></td>
              <td>
                <?php
                $dsp = strtolower($lead['disposition'] ?? '');
                switch ($dsp) {
                  case 'interested':
                    $cls = 'badge-green';
                    break;
                  case 'follow up':
                    $cls = 'badge-orange';
                    break;
                  case 'not interested':
                    $cls = 'badge-red';
                    break;
                  default:
                    $cls = 'badge-orange';
                }
                ?>
                <span class="badge <?= $cls ?>"><?= htmlspecialchars($lead['disposition'] ?? 'No call') ?></span>
              </td>
              <td class="row-actions">
                <a class="btn btn-sm btn-secondary" title="View" href="view.php?id=<?= $lead['id'] ?>"><i
                    class="fas fa-eye"></i></a>
                <?php if ($canEdit): ?>
                  <a class="btn btn-sm" title="Edit" style="background:#007bff" href="edit.php?lead_id=<?= $lead['id'] ?>"><i
                      class="fas fa-pen"></i></a>
                <?php endif; ?>
                <?php if ($canCall): ?>
                  <a class="btn btn-sm" title="Call" style="background:#28a745"
                    href="../calls/add.php?lead_id=<?= $lead['id'] ?>"><i class="fas fa-phone"></i></a>
                <?php endif; ?>
                <?php if ($lead['do_not_call']): ?>
                  <i class="fas fa-ban" title="Do Not Call" style="color:#d32f2f; margin-left:4px;"></i>
                <?php endif; ?>
                <?php if (!empty($lead['locked_by'])): ?>
                  <?php if ((int) $lead['locked_by'] === (int) $user['id']): ?>
                    <i class="fas fa-lock-open" title="Locked by you until <?= $lead['lock_expires'] ?>"
                      style="color:#2c5d4a;"></i>
                  <?php else: ?>
                    <i class="fas fa-lock" title="Locked by another user until <?= $lead['lock_expires'] ?>"
                      style="color:#d32f2f;"></i>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
      </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php if ($page > 1): ?>
          <a href="<?= $baseUrl ?>page=<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i> Previous</a>
        <?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
          <a class="<?= $i === $page ? 'active' : '' ?>" href="<?= $baseUrl ?>page=<?= $i ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="<?= $baseUrl ?>page=<?= $page + 1 ?>">Next <i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</body>

</html>