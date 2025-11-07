<?php
// admin/user_crud.php - Self-Sustained Loginless User CRUD
// For emergency access only. Remove after use.

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$host     = '127.0.0.1';
$dbname   = 'aspierd1_smarttax';
$username = 'aspierd1_admin';              // keep your current value
$password = 'UnoDosTresCuatroCinco12345...'; // keep your current value
$charset  = 'utf8mb4';

/* ===== Optional emergency gate (uncomment to enable) =====
$EMERGENCY_SECRET = 'change-me-to-a-long-random-string';
if (!isset($_GET['key']) || $_GET['key'] !== $EMERGENCY_SECRET) {
    http_response_code(403);
    exit('Forbidden');
}
===== */

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=$charset", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

session_start();

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error   = '';
$editUser = null;

// Roles
$roles = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Helper: fetch admin role id (for 'last admin' protection)
function getAdminRoleId(PDO $pdo): ?int {
    $id = $pdo->query("SELECT id FROM roles WHERE name = 'admin' LIMIT 1")->fetchColumn();
    return $id ? (int)$id : null;
}
$ADMIN_ROLE_ID = getAdminRoleId($pdo);

// === Handle POST ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    // FIXED: removed extra parenthesis
    if (empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $_SESSION['error'] = 'Invalid or missing CSRF token.';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?ts=' . time());
        exit;
    }

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $name     = trim($_POST['name'] ?? '');
            $email    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
            $password = trim($_POST['password'] ?? '');
            $status   = in_array($_POST['status'] ?? 'active', ['active','inactive'], true) ? $_POST['status'] : 'active';
            $roleIds  = array_map('intval', $_POST['role_ids'] ?? []);

            // New fields
            $mc_agent     = trim($_POST['mc_agent'] ?? '');
            $mc_extension = trim($_POST['mc_extension'] ?? '');

            if ($mc_agent === '')     $mc_agent = null;
            if ($mc_extension === '') $mc_extension = null;

            if ($mc_extension !== null && !preg_match('/^\d{2,6}$/', $mc_extension)) {
                throw new Exception('Extension must be numeric (2–6 digits).');
            }
            if (!$name || !$email || !$password) {
                throw new Exception("Name, email, and password are required.");
            }

            // Email uniqueness
            $dup = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $dup->execute([$email]);
            if ($dup->fetchColumn()) {
                throw new Exception('Email is already in use.');
            }

            $hash = password_hash($password, PASSWORD_BCRYPT);

            $sql = "INSERT INTO users (name, email, password_hash, status, mc_agent, mc_extension)
                    VALUES (:name, :email, :hash, :status, :mc_agent, :mc_extension)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':name'         => $name,
                ':email'        => $email,
                ':hash'         => $hash,
                ':status'       => $status,
                ':mc_agent'     => $mc_agent,
                ':mc_extension' => $mc_extension,
            ]);
            $userId = (int)$pdo->lastInsertId();

            if ($roleIds) {
                $ins = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                foreach ($roleIds as $rid) { $ins->execute([$userId, $rid]); }
            }

            $_SESSION['message'] = "✅ User '".htmlspecialchars($name, ENT_QUOTES, 'UTF-8')."' created successfully.";
            $_SESSION['error']   = null;
            header('Location: ' . $_SERVER['PHP_SELF'] . '?ts=' . time());
            exit;
        }

        if ($action === 'update') {
            $userId   = (int)($_POST['user_id'] ?? 0);
            $name     = trim($_POST['name'] ?? '');
            $email    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
            $password = trim($_POST['password'] ?? '');
            $status   = in_array($_POST['status'] ?? 'active', ['active','inactive'], true) ? $_POST['status'] : 'active';
            $roleIds  = array_map('intval', $_POST['role_ids'] ?? []);

            // New fields
            $mc_agent     = trim($_POST['mc_agent'] ?? '');
            $mc_extension = trim($_POST['mc_extension'] ?? '');
            if ($mc_agent === '')     $mc_agent = null;
            if ($mc_extension === '') $mc_extension = null;

            if ($mc_extension !== null && !preg_match('/^\d{2,6}$/', $mc_extension)) {
                throw new Exception('Extension must be numeric (2–6 digits).');
            }
            if (!$name || !$email) {
                throw new Exception("Name and email are required.");
            }

            // Email uniqueness (excluding current user)
            $dup = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
            $dup->execute([$email, $userId]);
            if ($dup->fetchColumn()) {
                throw new Exception('Email is already in use.');
            }

            // Guard against removing the last admin
            if ($ADMIN_ROLE_ID !== null) {
                $hasAdminInNewSet = in_array($ADMIN_ROLE_ID, $roleIds, true);
                $wasAdminStmt = $pdo->prepare("SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ? LIMIT 1");
                $wasAdminStmt->execute([$userId, $ADMIN_ROLE_ID]);
                $wasAdmin = (bool)$wasAdminStmt->fetchColumn();

                if ($wasAdmin && !$hasAdminInNewSet) {
                    $countAdmins = (int)$pdo->query("SELECT COUNT(*) FROM user_roles WHERE role_id = ".(int)$ADMIN_ROLE_ID)->fetchColumn();
                    if ($countAdmins <= 1) {
                        throw new Exception('Cannot remove admin role: this is the last admin user.');
                    }
                }
            }

            $params = [
                ':name'         => $name,
                ':email'        => $email,
                ':status'       => $status,
                ':mc_agent'     => $mc_agent,
                ':mc_extension' => $mc_extension,
                ':id'           => $userId,
            ];

            if ($password !== '') {
                $sql = "UPDATE users
                        SET name=:name, email=:email, status=:status,
                            mc_agent=:mc_agent, mc_extension=:mc_extension,
                            password_hash=:hash
                        WHERE id=:id";
                $params[':hash'] = password_hash($password, PASSWORD_BCRYPT);
            } else {
                $sql = "UPDATE users
                        SET name=:name, email=:email, status=:status,
                            mc_agent=:mc_agent, mc_extension=:mc_extension
                        WHERE id=:id";
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // Roles (replace)
            $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$userId]);
            if ($roleIds) {
                $ins = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                foreach ($roleIds as $rid) { $ins->execute([$userId, $rid]); }
            }

            $_SESSION['message'] = "✅ User '".htmlspecialchars($name, ENT_QUOTES, 'UTF-8')."' updated.";
            $_SESSION['error']   = null;
            header('Location: ' . $_SERVER['PHP_SELF'] . '?ts=' . time());
            exit;
        }

        if ($action === 'delete') {
            $userId = (int)($_POST['user_id'] ?? 0);

            // Protect last admin
            if ($ADMIN_ROLE_ID !== null) {
                $isAdminStmt = $pdo->prepare("SELECT 1 FROM user_roles WHERE user_id=? AND role_id=? LIMIT 1");
                $isAdminStmt->execute([$userId, $ADMIN_ROLE_ID]);
                if ($isAdminStmt->fetchColumn()) {
                    $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM user_roles WHERE role_id=".(int)$ADMIN_ROLE_ID)->fetchColumn();
                    if ($adminCount <= 1) {
                        throw new Exception('Cannot delete the last admin user.');
                    }
                }
            }

            $stmt = $pdo->prepare("SELECT name FROM users WHERE id=?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Exception('User not found.'); }

            $pdo->prepare("DELETE FROM user_roles WHERE user_id=?")->execute([$userId]);
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$userId]);

            $_SESSION['message'] = "🗑️ User '".htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8')."' deleted.";
            $_SESSION['error']   = null;
            header('Location: ' . $_SERVER['PHP_SELF'] . '?ts=' . time());
            exit;
        }

        throw new Exception('Unknown action.');
    } catch (Exception $e) {
        $_SESSION['error']   = $e->getMessage();
        $_SESSION['message'] = null;
        header('Location: ' . $_SERVER['PHP_SELF'] . '?ts=' . time());
        exit;
    }
}

// Messages
if (isset($_SESSION['message'])) { $message = $_SESSION['message']; unset($_SESSION['message']); }
if (isset($_SESSION['error']))   { $error   = $_SESSION['error'];   unset($_SESSION['error']); }

// Edit mode
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT id, name, email, status, mc_agent, mc_extension FROM users WHERE id=?");
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editUser) {
        $r = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id=?");
        $r->execute([$editId]);
        $editUser['roles'] = array_map('intval', array_column($r->fetchAll(PDO::FETCH_ASSOC), 'role_id'));
    }
}

// Users list
$stmt = $pdo->query("
    SELECT u.id, u.name, u.email, u.status, u.mc_agent, u.mc_extension,
           GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') AS role_names
    FROM users u
    LEFT JOIN user_roles ur ON u.id = ur.user_id
    LEFT JOIN roles r ON ur.role_id = r.id
    GROUP BY u.id
    ORDER BY u.name
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>User CRUD (Emergency Access)</title>
  <style>
    body { font-family: Arial, sans-serif; margin:0; padding:20px; background:#f4f6f9; color:#333; }
    .container { max-width: 1100px; margin: 0 auto; }
    h1 { color:#c00; text-align:center; }
    .alert { padding:12px; margin:15px 0; border-radius:6px; font-size:14px; }
    .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    .alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
    .card { background:#fff; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,.1); padding:20px; margin-bottom:20px; }
    .form-group { margin-bottom:15px; }
    label { display:block; margin-bottom:5px; font-weight:bold; }
    input[type="text"], input[type="email"], input[type="password"], select { width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box; }
    .checkbox-label { display:inline-block; margin-right:15px; }
    button, .btn { padding:10px 15px; border:none; border-radius:4px; cursor:pointer; text-decoration:none; font-size:14px; }
    .btn { background:#6c757d; color:#fff; }
    .btn-primary { background:#007bff; color:#fff; }
    .btn-secondary { background:#6c757d; color:#fff; }
    .btn-danger { background:#dc3545; color:#fff; }
    .btn-sm { padding:5px 10px; font-size:12px; }
    table { width:100%; border-collapse:collapse; margin-top:20px; background:#fff; border-radius:8px; overflow:hidden; }
    th, td { padding:12px; text-align:left; border-bottom:1px solid #eee; vertical-align:top; }
    th { background:#f8f9fa; color:#495057; }
    .actions { display:flex; gap:5px; }
    .form-actions { margin-top:15px; }
    .warning { color:#721c24; background:#f8d7da; padding:15px; border-radius:4px; margin:20px 0; font-size:14px; }
    .footer { text-align:center; margin-top:40px; color:#666; font-size:12px; }
    @media (max-width: 600px) { .actions { flex-direction:column; } button { width:100%; } }
  </style>
</head>
<body>
  <div class="container">
    <h1>⚠️ Emergency User CRUD</h1>
    <p class="warning">This page bypasses login. For admin recovery only. Delete or secure this file after use!</p>

    <?php if ($message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><?= h($error)   ?></div><?php endif; ?>

    <div class="card">
      <h2><?= $editUser ? 'Edit User' : 'Create New User' ?></h2>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="<?= $editUser ? 'update' : 'create' ?>">
        <?php if ($editUser): ?>
          <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
        <?php endif; ?>

        <div class="form-group">
          <label>Name</label>
          <input type="text" name="name" value="<?= h($editUser['name'] ?? '') ?>" required>
        </div>

        <div class="form-group">
          <label>Email</label>
          <input type="email" name="email" value="<?= h($editUser['email'] ?? '') ?>" required>
        </div>

        <div class="form-group">
          <label>Password <?= $editUser ? '(leave blank to keep current)' : '(required)' ?></label>
          <input type="password" name="password" autocomplete="new-password" <?= $editUser ? '' : 'required' ?>>
        </div>

        <div class="form-group">
          <label>Status</label>
          <select name="status">
            <option value="active"  <?= ($editUser['status'] ?? '') === 'active'  ? 'selected' : '' ?>>Active</option>
            <option value="inactive"<?= ($editUser['status'] ?? '') === 'inactive'? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>

        <div class="form-group">
          <label>Agent (display name)</label>
          <input type="text" name="mc_agent" maxlength="100" placeholder="e.g., Maria Lopez"
                 value="<?= h($editUser['mc_agent'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label>Extension (MightyCall)</label>
          <input type="text" name="mc_extension" maxlength="20" pattern="\d{2,6}" title="Digits only, 2 to 6"
                 placeholder="e.g., 101"
                 value="<?= h($editUser['mc_extension'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label>Roles</label><br>
          <?php foreach ($roles as $role): ?>
            <label class="checkbox-label">
              <input type="checkbox" name="role_ids[]" value="<?= (int)$role['id'] ?>"
                     <?= in_array((int)$role['id'], $editUser['roles'] ?? [], true) ? 'checked' : '' ?>>
              <?= h($role['name']) ?>
            </label><br>
          <?php endforeach; ?>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><?= $editUser ? 'Update User' : 'Create User' ?></button>
          <a href="user_crud.php" class="btn btn-secondary">Cancel</a>
        </div>
      </form>
    </div>

    <h2>All Users</h2>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Name / Email</th>
          <th>Roles</th>
          <th>Status</th>
          <th>Agent</th>
          <th>Extension</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $user): ?>
        <tr>
          <td><?= (int)$user['id'] ?></td>
          <td>
            <strong><?= h($user['name']) ?></strong><br>
            <small><?= h($user['email']) ?></small>
          </td>
          <td><?= h($user['role_names'] ?: 'No Role') ?></td>
          <td><?= h(ucfirst($user['status'])) ?></td>
          <td><?= h($user['mc_agent'] ?? '') ?></td>
          <td><code><?= h($user['mc_extension'] ?? '') ?></code></td>
          <td class="actions">
            <a href="?edit=<?= (int)$user['id'] ?>" class="btn btn-sm btn-primary">Edit</a>
            <form method="post" style="display:inline;"
                  onsubmit="return confirm('Delete <?= h($user['name']) ?>?');">
              <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
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
