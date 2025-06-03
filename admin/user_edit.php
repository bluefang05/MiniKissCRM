<?php
// admin/user_edit.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/User.php';
require_once __DIR__ . '/../lib/db.php';


if (!Auth::check() || !in_array('admin', Auth::user()['roles'] ?? [])) {
    die('<div class="container"><h1>Access Denied</h1></div>');
}

$pdo = getPDO();
$error = '';

// 1) Get & validate user ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user = User::find($id);
if (!$user) {
    die('<div class="container"><h1>User not found</h1></div>');
}

// 2) Fetch all roles & assigned
$allRoles     = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$assignedStmt = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
$assignedStmt->execute([$id]);
$assignedIds  = $assignedStmt->fetchAll(PDO::FETCH_COLUMN);

// 3) Handle POST (update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) 
     || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    try {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $status   = $_POST['status'] ?? 'active';
        $roleIds  = $_POST['role_ids'] ?? [];

        if (!$name || !$email) {
            throw new Exception('Name and email are required');
        }

        // 3a) update core fields
        User::update($id, array_filter([
            'name'     => $name,
            'email'    => $email,
            'password' => $password ?: null,
            'status'   => $status
        ], fn($v)=>$v !== null));

        // 3b) roles: delete old, insert new
        $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$id]);
        if ($roleIds) {
            $ins = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
            foreach ($roleIds as $rid) {
                $ins->execute([$id, (int)$rid]);
            }
        }

        header('Location: users.php?msg=User updated');
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit User #<?= $id ?></title>
  <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>
  <div class="container">
    <div class="header-actions">
      <a href="users.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Users
      </a>
      <h1>Edit User #<?= $id ?></h1>
    </div>
    <?php if($error): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <div class="form-group">
        <label>Name</label>
        <input type="text" name="name" required value="<?= htmlspecialchars($user['name']) ?>">
      </div>
      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" required value="<?= htmlspecialchars($user['email']) ?>">
      </div>
      <div class="form-group">
        <label>Password <small>(leave blank to keep)</small></label>
        <input type="password" name="password">
      </div>
      <div class="form-group">
        <label>Status</label>
        <select name="status">
          <option value="active"   <?= $user['status']==='active'   ? 'selected':'' ?>>Active</option>
          <option value="inactive" <?= $user['status']==='inactive' ? 'selected':'' ?>>Inactive</option>
        </select>
      </div>
      <div class="form-group">
        <label>Roles</label><br>
        <?php foreach ($allRoles as $r): ?>
          <label class="checkbox-label">
            <input
              type="checkbox"
              name="role_ids[]"
              value="<?= $r['id'] ?>"
              <?= in_array($r['id'], $assignedIds) ? 'checked':'' ?>
            >
            <?= htmlspecialchars($r['name']) ?>
          </label><br>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="btn"><i class="fas fa-save"></i> Save Changes</button>
      <a href="users.php" class="btn btn-secondary">Cancel</a>
    </form>
  </div>
</body>
</html>
