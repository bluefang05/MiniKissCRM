<?php
// admin/users.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

if (!Auth::check() || (!in_array('admin', Auth::user()['roles'] ?? []) && !in_array('owner', Auth::user()['roles'] ?? []))) {
    die('<div class="container"><h1>Access Denied</h1></div>');
}

$pdo = getPDO();

/* --------------------
   Filters (GET)
-------------------- */
$where  = [];
$params = [];

// Free search: name, email, mc_agent, mc_extension
if (isset($_GET['search']) && $_GET['search'] !== '') {
    $like = '%'.$_GET['search'].'%';
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR u.mc_agent LIKE ? OR u.mc_extension LIKE ?)";
    $params[] = $like; // name
    $params[] = $like; // email
    $params[] = $like; // mc_agent
    $params[] = $like; // mc_extension
}

// Exact status
if (!empty($_GET['status'])) {
    $where[]  = "u.status = ?";
    $params[] = $_GET['status'];
}

// Role filter (via LEFT JOIN)
if (!empty($_GET['role'])) {
    $where[]  = "ur.role_id = ?";
    $params[] = (int)$_GET['role'];
}

// Dedicated MC filters
if (isset($_GET['mc_agent']) && $_GET['mc_agent'] !== '') {
    $where[]  = "u.mc_agent LIKE ?";
    $params[] = '%'.$_GET['mc_agent'].'%';
}
if (isset($_GET['mc_extension']) && $_GET['mc_extension'] !== '') {
    $where[]  = "u.mc_extension = ?";
    $params[] = $_GET['mc_extension'];
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* --------------------
   Sorting (whitelist)
-------------------- */
$sortMap = [
    'id'        => 'u.id',
    'name'      => 'u.name',
    'email'     => 'u.email',
    'agent'     => 'u.mc_agent',
    'ext'       => 'u.mc_extension',
    'status'    => 'u.status',
    'roles'     => 'roles',            // alias from SELECT
    'referrals' => 'recruits_count'    // alias from SELECT
];
$sortKey = strtolower($_GET['sort'] ?? 'name');
$orderByCol = $sortMap[$sortKey] ?? $sortMap['name'];

$dir = strtolower($_GET['dir'] ?? 'asc');
$orderDir = in_array($dir, ['asc','desc'], true) ? $dir : 'asc';

$orderSql = "ORDER BY $orderByCol $orderDir";

/* --------------------
   Pagination
-------------------- */
$perChoices = [25,50,100,200,500];
$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, $perChoices, true)) $perPage = 50;

$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Count (DISTINCT u.id because of roles join)
$countSql = "
    SELECT COUNT(DISTINCT u.id)
    FROM users u
    LEFT JOIN user_roles ur ON u.id = ur.user_id
    $whereSql
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Main query
$sql = "
    SELECT
      u.id,
      u.name,
      u.email,
      u.status,
      u.mc_agent,
      u.mc_extension,
      GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') AS roles,

      -- recruiter name (if any)
      (
        SELECT p.name
        FROM user_referrals urf
        JOIN users p ON p.id = urf.parent_id
        WHERE urf.child_id = u.id
        LIMIT 1
      ) AS recruiter_name,

      -- direct referrals count
      (
        SELECT COUNT(*)
        FROM user_referrals urc
        WHERE urc.parent_id = u.id
      ) AS recruits_count

    FROM users u
    LEFT JOIN user_roles ur ON u.id = ur.user_id
    LEFT JOIN roles r       ON ur.role_id = r.id
    $whereSql
    GROUP BY u.id, u.name, u.email, u.status, u.mc_agent, u.mc_extension
    $orderSql
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);

// bind filters
$bindIndex = 1;
foreach ($params as $p) {
    $stmt->bindValue($bindIndex++, $p);
}
// bind limit/offset as INT
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);

$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// roles for filter
$roles = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* --------------------
   Helpers
-------------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function nextDir($current, $col){
    $isSame = (strtolower($_GET['sort'] ?? '') === $col);
    $dir = strtolower($_GET['dir'] ?? 'asc');
    if (!$isSame) return 'asc';
    return ($dir === 'asc') ? 'desc' : 'asc';
}

// base URL w/o page
$query = $_GET;
unset($query['page']);
$baseUrl = basename(__FILE__) . (count($query) ? '?' . http_build_query($query) . '&' : '?');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>User Management</title>
  <link rel="stylesheet" href="../assets/css/admin/users.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    .container { max-width: 1200px; margin: 0 auto; padding: 1rem; }
    .table { width:100%; border-collapse: collapse; }
    .table th, .table td { padding: .6rem; border-bottom: 1px solid #e5e7eb; text-align:left; white-space: nowrap; }
    .header-actions { display:flex; align-items:center; gap:.75rem; justify-content:space-between; margin:1rem 0; }
    .btn { display:inline-flex; align-items:center; gap:.35rem; padding:.45rem .7rem; border-radius:.4rem; background:#111827; color:#fff; text-decoration:none; }
    .btn:hover { opacity:.9; }
    .btn-secondary { background:#6b7280; }
    .btn-sm { padding:.3rem .5rem; font-size:.9rem; }
    .filters-form { display:flex; flex-wrap:wrap; gap:.5rem; margin:1rem 0; align-items: center; }
    input[type="text"], select { padding:.4rem .5rem; border:1px solid #e5e7eb; border-radius:.35rem; }
    .muted { color:#6b7280; }
    .badge { background:#eef2ff; color:#3730a3; padding:.15rem .4rem; border-radius:.35rem; font-size:.8rem; }
    .pagination { display:flex; gap:.4rem; align-items:center; margin-top: 1rem; flex-wrap: wrap; }
    .page-link { padding:.35rem .55rem; border:1px solid #e5e7eb; border-radius:.35rem; text-decoration:none; color:#111827; }
    .page-link.active, .page-link:hover { background:#111827; color:#fff; border-color:#111827; }
    .th-sort a { color: inherit; text-decoration: none; }
    .th-sort .dir { opacity:.6; font-size:.85em; margin-left:.25rem; }
    @media (max-width: 900px) { .hide-md { display:none; } }
  </style>
</head>
<body>
  <div class="container">
    <div class="header-actions">
      <a href="../leads/list.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Leads
      </a>
      <h1 style="margin:0;">Users</h1>
      <div style="display:flex; gap:.5rem;">
        <a href="user_create.php" class="btn">
          <i class="fas fa-user-plus"></i> New User
        </a>
      </div>
    </div>

    <form method="get" class="filters-form" autocomplete="off">
      <input
        type="text"
        name="search"
        placeholder="Name, email, agent or ext…"
        value="<?= h($_GET['search'] ?? '') ?>"
      >
      <input
        type="text"
        name="mc_agent"
        placeholder="Agent name (contains)…"
        value="<?= h($_GET['mc_agent'] ?? '') ?>"
      >
      <input
        type="text"
        name="mc_extension"
        placeholder="Ext (exact)…"
        value="<?= h($_GET['mc_extension'] ?? '') ?>"
      >
      <select name="status">
        <option value="">Any status</option>
        <option value="active"   <?= (($_GET['status'] ?? '')==='active')   ? 'selected':'' ?>>Active</option>
        <option value="inactive" <?= (($_GET['status'] ?? '')==='inactive') ? 'selected':'' ?>>Inactive</option>
      </select>
      <select name="role">
        <option value="">Any role</option>
        <?php foreach ($roles as $role): ?>
          <option value="<?= (int)$role['id'] ?>" <?= (($_GET['role'] ?? '')==(string)$role['id']) ? 'selected' : '' ?>>
            <?= h($role['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="per_page" title="Rows per page">
        <?php foreach ($perChoices as $opt): ?>
          <option value="<?= (int)$opt ?>" <?= $perPage===$opt?'selected':'' ?>><?= (int)$opt ?>/page</option>
        <?php endforeach; ?>
      </select>

      <button type="submit" class="btn">
        <i class="fas fa-filter"></i> Filter
      </button>
      <a href="users.php" class="btn btn-secondary">Clear</a>
    </form>

    <table class="table">
      <thead>
        <tr>
          <?php
            $cols = [
              ['key'=>'id',        'label'=>'ID',          'style'=>'width:60px;'],
              ['key'=>'name',      'label'=>'Name'],
              ['key'=>'email',     'label'=>'Email'],
              ['key'=>'agent',     'label'=>'Migthycall name',       'class'=>'hide-md'],
              ['key'=>'ext',       'label'=>'Ext',         'style'=>'width:90px;'],
              ['key'=>'status',    'label'=>'Status',      'style'=>'width:100px;'],
              ['key'=>'roles',     'label'=>'Roles'],
              ['key'=>'recruiter', 'label'=>'Recruiter',   'class'=>'hide-md'], // not sortable alias
              ['key'=>'referrals', 'label'=>'# Referrals', 'style'=>'width:115px;'],
              ['key'=>null,        'label'=>'Actions',     'style'=>'width:120px;'],
            ];
            $currentSort = strtolower($_GET['sort'] ?? 'name');
            $currentDir  = strtolower($_GET['dir'] ?? 'asc');
          ?>
          <?php foreach ($cols as $c): ?>
            <?php if ($c['key'] && isset($sortMap[$c['key']])): ?>
              <?php $nd = nextDir($currentSort, $c['key']); ?>
              <th class="th-sort <?= $c['class'] ?? '' ?>" style="<?= $c['style'] ?? '' ?>">
                <a href="<?= htmlspecialchars(basename(__FILE__) . '?' . http_build_query(array_merge($_GET, ['sort'=>$c['key'],'dir'=>$nd,'page'=>1])), ENT_QUOTES) ?>">
                  <?= h($c['label']) ?>
                  <?php if ($currentSort === $c['key']): ?>
                    <span class="dir"><?= $currentDir === 'asc' ? '▲' : '▼' ?></span>
                  <?php endif; ?>
                </a>
              </th>
            <?php else: ?>
              <th class="<?= $c['class'] ?? '' ?>" style="<?= $c['style'] ?? '' ?>"><?= h($c['label']) ?></th>
            <?php endif; ?>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
          <tr>
            <td colspan="10" style="text-align:center;">No users found.</td>
          </tr>
        <?php else: foreach ($users as $u): ?>
          <tr>
            <td><?= (int)$u['id'] ?></td>
            <td><?= h($u['name'] ?? '') ?></td>
            <td><?= h($u['email'] ?? '') ?></td>
            <td class="hide-md"><?= ($u['mc_agent'] ?? '') !== '' ? h($u['mc_agent']) : '<span class="muted">—</span>' ?></td>
            <td><?= ($u['mc_extension'] ?? '') !== '' ? h($u['mc_extension']) : '<span class="muted">—</span>' ?></td>
            <td><?= ucfirst(h($u['status'] ?? '')) ?></td>
            <td><?= h($u['roles'] ?? '') ?></td>
            <td class="hide-md"><?= ($u['recruiter_name'] ?? '') !== '' ? h($u['recruiter_name']) : '<span class="muted">—</span>' ?></td>
            <td><span class="badge"><?= (int)($u['recruits_count'] ?? 0) ?></span></td>
            <td>
              <a href="user_edit.php?id=<?= (int)$u['id'] ?>" class="btn btn-sm">
                <i class="fas fa-edit"></i> Edit
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php if ($page > 1): ?>
          <a href="<?= $baseUrl ?>page=<?= $page-1 ?>" class="page-link"><i class="fas fa-chevron-left"></i> Prev</a>
        <?php endif; ?>

        <?php
          $from = max(1, $page-2);
          $to   = min($totalPages, $page+2);
          for ($i=$from; $i<=$to; $i++):
        ?>
          <a class="page-link <?= $i===$page?'active':'' ?>" href="<?= $baseUrl ?>page=<?= $i ?>"><?= $i ?></a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
          <a href="<?= $baseUrl ?>page=<?= $page+1 ?>" class="page-link">Next <i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>

        <span class="muted" style="margin-left:.5rem;">Showing <?= ($offset+1) ?>–<?= min($offset+$perPage, $totalRows) ?> of <?= $totalRows ?></span>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
