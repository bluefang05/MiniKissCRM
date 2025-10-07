<?php
// admin/user_create.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/User.php';
require_once __DIR__ . '/../lib/db.php';

if (!Auth::check() || (!in_array('admin', Auth::user()['roles'] ?? []) && !in_array('owner', Auth::user()['roles'] ?? []))) {
    die('<div class="container"><h1>Access Denied</h1></div>');
}

$pdo   = getPDO();
$error = '';

// fetch roles (for checkboxes)
$roles = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// fetch possible recruiters (all existing users)
$allUsers = $pdo->query("SELECT id, name FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    try {
        $name        = trim($_POST['name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $password    = trim($_POST['password'] ?? '');
        $status      = $_POST['status'] ?? 'active';
        $roleIds     = $_POST['role_ids'] ?? [];
        $refParentId = isset($_POST['ref_parent_id']) && $_POST['ref_parent_id'] !== '' ? (int)$_POST['ref_parent_id'] : null;

        if (!$name || !$email || !$password) {
            throw new Exception('Name, email and password are required');
        }

        $pdo->beginTransaction();

        // Create user (returns new ID)
        $userId = User::create([
            'name'     => $name,
            'email'    => $email,
            'password' => $password,
            'status'   => $status
        ]);

        // Assign roles
        if (!empty($roleIds) && is_array($roleIds)) {
            $insert = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
            foreach ($roleIds as $rid) {
                $insert->execute([$userId, (int)$rid]);
            }
        }

        // Create referral relation (recruiter -> new user)
        if ($refParentId !== null) {
            // (por seguridad, aunque no debería coincidir)
            if ($refParentId === (int)$userId) {
                throw new Exception('A user cannot be their own recruiter');
            }
            $stmt = $pdo->prepare("INSERT INTO user_referrals (parent_id, child_id) VALUES (?, ?)");
            $stmt->execute([$refParentId, $userId]);
        }

        $pdo->commit();

        header('Location: users.php?msg=User created');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Create User</title>
  <link rel="stylesheet" href="../assets/css/admin/user_create.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    .container { max-width: 880px; margin: 24px auto; padding: 0 16px; }
    .form-group { display:flex; flex-direction:column; gap:.35rem; margin-bottom:12px; }
    input[type="text"], input[type="email"], input[type="password"], select { padding:.5rem .6rem; border:1px solid #e5e7eb; border-radius:.4rem; }
    .btn { display:inline-flex; align-items:center; gap:.35rem; padding:.45rem .7rem; border-radius:.4rem; background:#111827; color:#fff; text-decoration:none; }
    .btn:hover { opacity:.92; }
    .btn-secondary { background:#6b7280; }
    .error { background:#fee2e2; color:#991b1b; padding:.5rem .7rem; border-radius:.4rem; }
    .checkbox-label { display:inline-flex; align-items:center; gap:.35rem; margin-right:12px; }
  </style>
</head>
<body>
  <div class="container">
    <h1>Create New User</h1>

    <?php if ($error): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

      <div class="form-group">
        <label>Name</label>
        <input type="text" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" required>
      </div>

      <div class="form-group">
        <label>Status</label>
        <select name="status">
          <option value="active"   <?= (($_POST['status'] ?? 'active')==='active')   ? 'selected':'' ?>>Active</option>
          <option value="inactive" <?= (($_POST['status'] ?? '')==='inactive') ? 'selected':'' ?>>Inactive</option>
        </select>
      </div>

      <div class="form-group">
        <label>Reclutador</label>
        <select name="ref_parent_id">
          <option value="">— Ninguno —</option>
          <?php foreach ($allUsers as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (isset($_POST['ref_parent_id']) && (int)$_POST['ref_parent_id'] === (int)$u['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($u['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small class="muted">Opcional: asigna el usuario que lo reclutó.</small>
      </div>

      <div class="form-group">
        <label>Roles</label><br>
        <?php foreach ($roles as $r): ?>
          <label class="checkbox-label">
            <input
              type="checkbox"
              name="role_ids[]"
              value="<?= (int)$r['id'] ?>"
              <?= (isset($_POST['role_ids']) && in_array($r['id'], (array)$_POST['role_ids'])) ? 'checked' : '' ?>
            >
            <?= htmlspecialchars($r['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>

      <button type="submit" class="btn"><i class="fas fa-user-plus"></i> Create User</button>
      <a href="users.php" class="btn btn-secondary">Cancel</a>
    </form>
  </div>
</body>
</html>
