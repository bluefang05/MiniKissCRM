<?php
// admin/user_edit.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/User.php';
require_once __DIR__ . '/../lib/db.php';

if (!Auth::check() || (!in_array('admin', Auth::user()['roles'] ?? []) && !in_array('owner', Auth::user()['roles'] ?? []))) {
    die('<div class="container"><h1>Access Denied</h1></div>');
}

$pdo   = getPDO();
$error = '';

// 1) Get & validate user ID
$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user = User::find($id);
if (!$user) {
    die('<div class="container"><h1>User not found</h1></div>');
}

$currentUserId = Auth::user()['id'] ?? 0;

// 2) Fetch roles (all & assigned)
$allRoles     = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$assignedStmt = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
$assignedStmt->execute([$id]);
$assignedIds  = $assignedStmt->fetchAll(PDO::FETCH_COLUMN);

// 3) Recruiter data
$allUsersStmt = $pdo->prepare("SELECT id, name FROM users WHERE id <> ? ORDER BY name");
$allUsersStmt->execute([$id]);
$allUsers = $allUsersStmt->fetchAll(PDO::FETCH_ASSOC);

$currentParentStmt = $pdo->prepare("SELECT parent_id FROM user_referrals WHERE child_id = ?");
$currentParentStmt->execute([$id]);
$currentParentId = $currentParentStmt->fetchColumn();

// 4) Handle POST (update or delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        // --- DELETE USER ---
        if ($id === $currentUserId) {
            $error = 'You cannot delete your own account.';
        } else {
            try {
                // Check if user is the last admin
                $isAdmin = in_array(1, $assignedIds); // assuming role_id=1 is 'admin'
                if ($isAdmin) {
                    $adminCount = $pdo->query("SELECT COUNT(*) FROM user_roles WHERE role_id = 1")->fetchColumn();
                    if ($adminCount <= 1) {
                        throw new Exception('Cannot delete the last admin user.');
                    }
                }

                $pdo->beginTransaction();

                // Delete related records (foreign keys)
                $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM user_referrals WHERE parent_id = ? OR child_id = ?")->execute([$id, $id]);
                $pdo->prepare("DELETE FROM interactions WHERE user_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM leads WHERE taken_by = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM leads WHERE uploaded_by = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM audit_logs WHERE user_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM lead_documents WHERE uploaded_by = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM lead_locks WHERE user_id = ?")->execute([$id]);

                // Finally delete the user
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);

                $pdo->commit();

                header('Location: users.php?msg=User+deleted');
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = $e->getMessage();
            }
        }
    } else {
        // --- UPDATE USER ---
        try {
            $name     = trim($_POST['name'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $status   = $_POST['status'] ?? 'active';
            $roleIds  = $_POST['role_ids'] ?? [];

            $refParentId = isset($_POST['ref_parent_id']) && $_POST['ref_parent_id'] !== ''
                ? (int)$_POST['ref_parent_id']
                : null;

            if (!$name || !$email) {
                throw new Exception('Name and email are required');
            }
            if ($refParentId !== null && $refParentId === $id) {
                throw new Exception('A user cannot be their own recruiter');
            }

            $pdo->beginTransaction();

            User::update($id, array_filter([
                'name'     => $name,
                'email'    => $email,
                'password' => $password ?: null,
                'status'   => $status
            ], fn($v) => $v !== null));

            $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$id]);
            if (!empty($roleIds) && is_array($roleIds)) {
                $ins = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                foreach ($roleIds as $rid) {
                    $ins->execute([$id, (int)$rid]);
                }
            }

            $pdo->prepare("DELETE FROM user_referrals WHERE child_id = ?")->execute([$id]);
            if ($refParentId !== null) {
                $pdo->prepare("INSERT INTO user_referrals (parent_id, child_id) VALUES (?, ?)")
                    ->execute([$refParentId, $id]);
            }

            $pdo->commit();

            header('Location: users.php?msg=User+updated');
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit User #<?= (int)$id ?></title>
  <link rel="stylesheet" href="../assets/css/admin/user_edit.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    .container { max-width: 880px; margin: 24px auto; padding: 0 16px; }
    .header-actions { display:flex; align-items:center; gap:.75rem; justify-content:space-between; margin:1rem 0; }
    .btn { display:inline-flex; align-items:center; gap:.35rem; padding:.45rem .7rem; border-radius:.4rem; background:#111827; color:#fff; text-decoration:none; }
    .btn:hover { opacity:.92; }
    .btn-secondary { background:#6b7280; }
    .btn-danger { background:#dc2626; }
    .form-group { display:flex; flex-direction:column; gap:.35rem; margin-bottom:12px; }
    input[type="text"], input[type="email"], input[type="password"], select { padding:.5rem .6rem; border:1px solid #e5e7eb; border-radius:.4rem; }
    .error { background:#fee2e2; color:#991b1b; padding:.5rem .7rem; border-radius:.4rem; }
    .checkbox-label { display:inline-flex; align-items:center; gap:.35rem; margin-right:12px; }
    .danger-zone { margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid #eee; }
    .danger-zone h3 { margin: 0 0 0.75rem; color: #dc2626; }
  </style>
</head>
<body>
  <div class="container">
    <div class="header-actions">
      <a href="users.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Users
      </a>
      <h1 style="margin:0;">Edit User #<?= (int)$id ?></h1>
    </div>

    <?php if ($error): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

      <div class="form-group">
        <label>Name</label>
        <input type="text" name="name" required value="<?= htmlspecialchars($user['name'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" required value="<?= htmlspecialchars($user['email'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label>Password <small>(leave blank to keep)</small></label>
        <input type="password" name="password">
      </div>

      <div class="form-group">
        <label>Status</label>
        <select name="status">
          <option value="active"   <?= ($user['status'] ?? '')==='active'   ? 'selected':'' ?>>Active</option>
          <option value="inactive" <?= ($user['status'] ?? '')==='inactive' ? 'selected':'' ?>>Inactive</option>
        </select>
      </div>

      <div class="form-group">
        <label>Recruiter</label>
        <select name="ref_parent_id">
          <option value="">— None —</option>
          <?php foreach ($allUsers as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= ($currentParentId && (int)$currentParentId === (int)$u['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($u['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small class="muted">A user can have one recruiter; a recruiter can have many referrals.</small>
      </div>

      <div class="form-group">
        <label>Roles</label><br>
        <?php foreach ($allRoles as $r): ?>
          <label class="checkbox-label">
            <input
              type="checkbox"
              name="role_ids[]"
              value="<?= (int)$r['id'] ?>"
              <?= in_array($r['id'], $assignedIds) ? 'checked' : '' ?>
            >
            <?= htmlspecialchars($r['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>

      <button type="submit" class="btn"><i class="fas fa-save"></i> Save Changes</button>
      <a href="users.php" class="btn btn-secondary">Cancel</a>
    </form>

    <?php if ($id !== $currentUserId): ?>
      <div class="danger-zone">
        <h3>Danger Zone</h3>
        <p><strong>Permanently delete this user?</strong> This action cannot be undone and will delete all associated data.</p>
        <form method="post" style="display:inline;" onsubmit="return confirm('Are you absolutely sure? This will permanently delete the user and all their data.')">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
          <label class="checkbox-label" style="color:#dc2626;">
            <input type="checkbox" name="confirm_delete" value="1" required>
            I understand this action is irreversible.
          </label>
          <button type="submit" name="action" value="delete" class="btn btn-danger" style="margin-top:0.5rem;">
            <i class="fas fa-trash"></i> Delete User
          </button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>