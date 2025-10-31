<?php
// admin/user_crud.php - Self-Sustained Loginless User CRUD
// For emergency access only. Remove after use.

$host = '127.0.0.1';
$dbname = 'aspierd1_smarttax';
$username = 'aspierd1_admin';  // Change if needed
$password = 'UnoDosTresCuatroCinco12345...';      // Change if needed
$charset = 'utf8mb4';


try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=$charset", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

session_start();

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';
$editUser = null;

// Fetch roles
$roles = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// === Handle POST Actions ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Invalid or missing CSRF token.';
    } else {
        try {
            $action = $_POST['action'] ?? '';

            if ($action === 'create') {
                $name = trim($_POST['name'] ?? '');
                $email = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
                $password = trim($_POST['password'] ?? '');
                $status = in_array($_POST['status'], ['active', 'inactive']) ? $_POST['status'] : 'active';
                $roleIds = array_map('intval', $_POST['role_ids'] ?? []);

                if (!$name || !$email || !$password) {
                    throw new Exception("Name, email, and password are required.");
                }

                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, status) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $email, $hash, $status]);
                $userId = $pdo->lastInsertId();

                if ($roleIds) {
                    $insertRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                    foreach ($roleIds as $rid) {
                        $insertRole->execute([$userId, $rid]);
                    }
                }

                $message = "✅ User '$name' created successfully.";
            }

            elseif ($action === 'update') {
                $userId = (int)$_POST['user_id'];
                $name = trim($_POST['name'] ?? '');
                $email = filter_var(trim($_POST['email']), FILTER_VALIDATE_EMAIL);
                $password = trim($_POST['password'] ?? '');
                $status = in_array($_POST['status'], ['active', 'inactive']) ? $_POST['status'] : 'active';
                $roleIds = array_map('intval', $_POST['role_ids'] ?? []);

                if (!$name || !$email) {
                    throw new Exception("Name and email are required.");
                }

                $sql = "UPDATE users SET name = ?, email = ?, status = ?";
                $params = [$name, $email, $status];

                if ($password) {
                    $sql .= ", password_hash = ?";
                    $params[] = password_hash($password, PASSWORD_BCRYPT);
                }
                $sql .= " WHERE id = ?";
                $params[] = $userId;

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$userId]);
                if ($roleIds) {
                    $insertRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                    foreach ($roleIds as $rid) {
                        $insertRole->execute([$userId, $rid]);
                    }
                }

                $message = "✅ User '$name' updated.";
            }

            elseif ($action === 'delete') {
                $userId = (int)$_POST['user_id'];
                $stmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();

                if (!$user) throw new Exception("User not found.");

                $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$userId]);
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);

                $message = "🗑️ User '{$user['name']}' deleted.";
            }

            // Clear POST data and show message on same page
            $_SESSION['message'] = $message;
            $_SESSION['error'] = null;
            header('Location: ' . $_SERVER['PHP_SELF'] . '?ts=' . time());
            exit;
        } catch (Exception $e) {
            $error = $e->getMessage();
            $_SESSION['error'] = $error;
            $_SESSION['message'] = null;
            header('Location: ' . $_SERVER['PHP_SELF'] . '?ts=' . time());
            exit;
        }
    }
}

// Display session messages (after redirect)
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Handle edit mode
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT id, name, email, status FROM users WHERE id = ?");
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch();

    if ($editUser) {
        $stmt = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
        $stmt->execute([$editId]);
        $editUser['roles'] = array_column($stmt->fetchAll(), 'role_id');
    }
}

// Load all users
$stmt = $pdo->query("
    SELECT u.id, u.name, u.email, u.status, GROUP_CONCAT(r.name) as role_names
    FROM users u
    LEFT JOIN user_roles ur ON u.id = ur.user_id
    LEFT JOIN roles r ON ur.role_id = r.id
    GROUP BY u.id
    ORDER BY u.name
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>User CRUD (Emergency Access)</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f4f6f9; color: #333; }
    .container { max-width: 1000px; margin: 0 auto; }
    h1 { color: #c00; text-align: center; }
    .alert { padding: 12px; margin: 15px 0; border-radius: 6px; font-size: 14px; }
    .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    .card { background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px; }
    .form-group { margin-bottom: 15px; }
    label { display: block; margin-bottom: 5px; font-weight: bold; }
    input[type="text"], input[type="email"], input[type="password"], select {
      width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;
    }
    .checkbox-label { display: inline-block; margin-right: 15px; }
    button, .btn {
      padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 14px;
    }
    .btn { background: #6c757d; color: white; }
    .btn-primary { background: #007bff; color: white; }
    .btn-secondary { background: #6c757d; color: white; }
    .btn-danger { background: #dc3545; color: white; }
    .btn-sm { padding: 5px 10px; font-size: 12px; }
    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
    th { background: #f8f9fa; color: #495057; }
    .actions { display: flex; gap: 5px; }
    .form-actions { margin-top: 15px; }
    .warning { color: #721c24; background: #f8d7da; padding: 15px; border-radius: 4px; margin: 20px 0; font-size: 14px; }
    .footer { text-align: center; margin-top: 40px; color: #666; font-size: 12px; }
    @media (max-width: 600px) {
      .actions { flex-direction: column; }
      button { width: 100%; }
    }
  </style>
</head>
<body>
  <div class="container">
    <h1>⚠️ Emergency User CRUD</h1>
    <p class="warning">
      This page bypasses login. For admin recovery only.<br>
      Delete or secure this file after use!
    </p>

    <?php if ($message): ?>
      <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Create/Edit Form -->
    <div class="card">
      <h2><?= $editUser ? 'Edit User' : 'Create New User' ?></h2>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="action" value="<?= $editUser ? 'update' : 'create' ?>">
        <?php if ($editUser): ?>
          <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
        <?php endif; ?>

        <div class="form-group">
          <label>Name</label>
          <input type="text" name="name" value="<?= htmlspecialchars($editUser['name'] ?? '') ?>" required>
        </div>

        <div class="form-group">
          <label>Email</label>
          <input type="email" name="email" value="<?= htmlspecialchars($editUser['email'] ?? '') ?>" required>
        </div>

        <div class="form-group">
          <label>Password <?= $editUser ? '(leave blank to keep current)' : '(required)' ?></label>
          <input type="password" name="password" autocomplete="off">
        </div>

        <div class="form-group">
          <label>Status</label>
          <select name="status">
            <option value="active" <?= ($editUser['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= ($editUser['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>

        <div class="form-group">
          <label>Roles</label><br>
          <?php foreach ($roles as $role): ?>
            <label class="checkbox-label">
              <input type="checkbox" 
                     name="role_ids[]" 
                     value="<?= $role['id'] ?>"
                     <?= in_array($role['id'], $editUser['roles'] ?? []) ? 'checked' : '' ?>>
              <?= htmlspecialchars($role['name']) ?>
            </label><br>
          <?php endforeach; ?>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary">
            <?= $editUser ? 'Update User' : 'Create User' ?>
          </button>
          <a href="user_crud.php" class="btn btn-secondary">Cancel</a>
        </div>
      </form>
    </div>

    <!-- Users List -->
    <h2>All Users</h2>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Name</th>
          <th>Email</th>
          <th>Roles</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user): ?>
          <tr>
            <td><?= $user['id'] ?></td>
            <td><?= htmlspecialchars($user['name']) ?></td>
            <td><?= htmlspecialchars($user['email']) ?></td>
            <td><?= htmlspecialchars($user['role_names'] ?? 'No Role') ?></td>
            <td><?= ucfirst($user['status']) ?></td>
            <td class="actions">
              <a href="?edit=<?= $user['id'] ?>" class="btn btn-sm btn-primary">Edit</a>
              <form method="post" style="display:inline;" 
                    onsubmit="return confirm('Delete <?= addslashes(htmlspecialchars($user['name'])) ?>?')">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="footer">
      <p>Self-sustained emergency tool • <?= date('Y-m-d H:i') ?></p>
    </div>
  </div>
</body>
</html>