<?php
// admin/users.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';


if (!Auth::check() || !in_array('admin', Auth::user()['roles'] ?? []) && !in_array('owner', Auth::user()['roles'] ?? [])) {
    die('<div class="container"><h1>Access Denied</h1></div>');
}

$pdo = getPDO();

// -- Build filters --
$where   = [];
$params  = [];

if (!empty($_GET['search'])) {
    $where[]  = "(u.name LIKE ? OR u.email LIKE ?)";
    $like     = '%'.$_GET['search'].'%';
    $params   = [$like, $like];
}
if (!empty($_GET['status'])) {
    $where[]  = "u.status = ?";
    $params[] = $_GET['status'];
}
if (!empty($_GET['role'])) {
    $where[]  = "ur.role_id = ?";
    $params[] = $_GET['role'];
}
$whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

// -- Fetch users with their roles --
$sql = "
    SELECT u.id, u.name, u.email, u.status, GROUP_CONCAT(r.name SEPARATOR ', ') AS roles
    FROM users u
    LEFT JOIN user_roles ur ON u.id = ur.user_id
    LEFT JOIN roles r ON ur.role_id = r.id
    $whereSql
    GROUP BY u.id
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
  <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>
  <div class="container">
    <div class="header-actions">
      <a href="../leads/list.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Leads
      </a>
      <h1>Users</h1>
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
          <option value="<?= $role['id'] ?>" <?= ($_GET['role'] ?? '')==$role['id'] ? 'selected' : '' ?>>
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
          <th>ID</th>
          <th>Name</th>
          <th>Email</th>
          <th>Status</th>
          <th>Roles</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
          <tr>
            <td colspan="6" style="text-align:center;">No users found.</td>
          </tr>
        <?php else: foreach ($users as $u): ?>
          <tr>
            <td><?= $u['id'] ?></td>
            <td><?= htmlspecialchars($u['name']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= ucfirst(htmlspecialchars($u['status'])) ?></td>
            <td><?= htmlspecialchars($u['roles']) ?></td>
            <td>
              <a href="user_edit.php?id=<?= $u['id'] ?>" class="btn btn-sm">
                <i class="fas fa-edit"></i> Edit
              </a>
              <!-- Optional: Add delete/status toggle -->
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</body>
</html>