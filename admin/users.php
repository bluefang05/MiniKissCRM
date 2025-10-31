<?php
// admin/users.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

if (!Auth::check() || (!in_array('admin', Auth::user()['roles'] ?? []) && !in_array('owner', Auth::user()['roles'] ?? []))) {
    die('<div class="container"><h1>Access Denied</h1></div>');
}

$pdo = getPDO();

// -- Build filters --
$where  = [];
$params = [];

if (!empty($_GET['search'])) {
    $where[] = "(u.name LIKE ? OR u.email LIKE ?)";
    $like    = '%'.$_GET['search'].'%';
    $params  = [$like, $like];
}
if (!empty($_GET['status'])) {
    $where[]  = "u.status = ?";
    $params[] = $_GET['status'];
}
if (!empty($_GET['role'])) {
    // nota: filtramos por rol usando el LEFT JOIN a user_roles
    $where[]  = "ur.role_id = ?";
    $params[] = $_GET['role'];
}

$whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

// -- Fetch users with roles + recruiter + recruits count --
//   recruiter_name: nombre del padre si existe
//   recruits_count: cantidad de hijos directos
$sql = "
    SELECT
      u.id,
      u.name,
      u.email,
      u.status,
      GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') AS roles,

      -- nombre del reclutador (si existe)
      (
        SELECT p.name
        FROM user_referrals urf
        JOIN users p ON p.id = urf.parent_id
        WHERE urf.child_id = u.id
        LIMIT 1
      ) AS recruiter_name,

      -- cantidad de referidos directos
      (
        SELECT COUNT(*)
        FROM user_referrals urc
        WHERE urc.parent_id = u.id
      ) AS recruits_count

    FROM users u
    LEFT JOIN user_roles ur ON u.id = ur.user_id
    LEFT JOIN roles r       ON ur.role_id = r.id
    $whereSql
    GROUP BY u.id, u.name, u.email, u.status
    ORDER BY u.name
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// -- Fetch roles for filter --
$roleStmt = $pdo->query("SELECT id, name FROM roles ORDER BY name");
$roles = $roleStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>User Management</title>
  <link rel="stylesheet" href="../assets/css/admin/users.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    .table { width:100%; border-collapse: collapse; }
    .table th, .table td { padding: .6rem; border-bottom: 1px solid #e5e7eb; text-align:left; }
    .header-actions { display:flex; align-items:center; gap:.75rem; justify-content:space-between; margin:1rem 0; }
    .btn { display:inline-flex; align-items:center; gap:.35rem; padding:.45rem .7rem; border-radius:.4rem; background:#111827; color:#fff; text-decoration:none; }
    .btn:hover { opacity:.9; }
    .btn-secondary { background:#6b7280; }
    .btn-sm { padding:.3rem .5rem; font-size:.9rem; }
    .filters-form { display:flex; gap:.5rem; margin:1rem 0; }
    input[type="text"], select { padding:.4rem .5rem; }
    .muted { color:#6b7280; }
    .badge { background:#eef2ff; color:#3730a3; padding:.15rem .4rem; border-radius:.35rem; font-size:.8rem; }
  </style>
</head>
<body>
  <div class="container">
    <div class="header-actions">
      <a href="../leads/list.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Leads
      </a>
      <h1 style="margin:0;">Users</h1>
      <a href="user_create.php" class="btn">
        <i class="fas fa-user-plus"></i> New User
      </a>
    </div>

    <form method="get" class="filters-form">
      <input
        type="text"
        name="search"
        placeholder="Name or email…"
        value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
      >
      <select name="status">
        <option value="">Any status</option>
        <option value="active"   <?= ($_GET['status'] ?? '')==='active'   ? 'selected':'' ?>>Active</option>
        <option value="inactive" <?= ($_GET['status'] ?? '')==='inactive' ? 'selected':'' ?>>Inactive</option>
      </select>
      <select name="role">
        <option value="">Any role</option>
        <?php foreach ($roles as $role): ?>
          <option value="<?= (int)$role['id'] ?>" <?= (($_GET['role'] ?? '')==(string)$role['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($role['name']) ?>
          </option>
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
          <th style="width:60px;">ID</th>
          <th>Name</th>
          <th>Email</th>
          <th style="width:100px;">Status</th>
          <th>Roles</th>
          <th>Recruiter</th>
          <th style="width:115px;"># Referidos</th>
          <th style="width:120px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
          <tr>
            <td colspan="8" style="text-align:center;">No users found.</td>
          </tr>
        <?php else: foreach ($users as $u): ?>
          <tr>
            <td><?= (int)$u['id'] ?></td>
            <td><?= htmlspecialchars($u['name'] ?? '') ?></td>
            <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
            <td><?= ucfirst(htmlspecialchars($u['status'] ?? '')) ?></td>
            <td><?= htmlspecialchars($u['roles'] ?? '') ?></td>
            <td><?= htmlspecialchars($u['recruiter_name'] ?? '') ?: '<span class="muted">—</span>' ?></td>
            <td><span class="badge"><?= (int)($u['recruits_count'] ?? 0) ?></span></td>
            <td>
              <a href="user_edit.php?id=<?= (int)$u['id'] ?>" class="btn btn-sm">
                <i class="fas fa-edit"></i> Edit
              </a>
              <!-- Optional: delete / toggle -->
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
