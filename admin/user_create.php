<?php
// admin/user_create.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/User.php';
require_once __DIR__ . '/../lib/db.php';


if (!Auth::check() || !in_array('admin', Auth::user()['roles'] ?? [])) {
    die('<div class="container"><h1>Access Denied</h1></div>');
}

$pdo = getPDO();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    try {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $status   = $_POST['status'] ?? 'active';
        $roleIds  = $_POST['role_ids'] ?? [];

        if (!$name || !$email || !$password) {
            throw new Exception('Name, email and password are required');
        }

        $userId = User::create([
            'name'     => $name,
            'email'    => $email,
            'password' => $password,
            'status'   => $status
        ]);

        if ($roleIds) {
            $insert = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
            foreach ($roleIds as $rid) {
                $insert->execute([$userId, (int)$rid]);
            }
        }

        header('Location: users.php?msg=User created');
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// fetch roles
$roles = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Create User</title>
  <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>
  <div class="container">
    <h1>Create New User</h1>
    <?php if($error): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <div class="form-group">
        <label>Name</label>
        <input type="text" name="name" required>
      </div>
      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" required>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" required>
      </div>
      <div class="form-group">
        <label>Status</label>
        <select name="status">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      <div class="form-group">
        <label>Roles</label><br>
        <?php foreach($roles as $r): ?>
          <label class="checkbox-label">
            <input type="checkbox" name="role_ids[]" value="<?= $r['id'] ?>"> <?= htmlspecialchars($r['name']) ?>
          </label><br>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="btn">Create User</button>
      <a href="users.php" class="btn btn-secondary">Cancel</a>
    </form>
  </div>
</body>
</html>
