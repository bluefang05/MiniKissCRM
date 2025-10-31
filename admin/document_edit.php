<?php
// admin/document_edit.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!Auth::check() || !in_array('admin', Auth::user()['roles'] ?? [])) {
    die('<div class="container"><h1>Access Denied</h1></div>');
}

$pdo = getPDO();
$error = '';
$id    = (int)($_GET['id'] ?? 0);

// fetch existing
$stmt = $pdo->prepare("SELECT id, title FROM lead_documents WHERE id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc) {
    die('<div class="container"><h1>Document not found</h1></div>');
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    $newTitle = trim($_POST['title'] ?? '');
    if (!$newTitle) {
        $error = 'Title cannot be empty.';
    } else {
        $upd = $pdo->prepare("UPDATE lead_documents SET title = ? WHERE id = ?");
        $upd->execute([$newTitle, $id]);
        header('Location: documents.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Rename Document #<?= $id ?></title>
  <link rel="stylesheet" href="../assets/css/admin/document_edit.css">
</head>
<body>
  <div class="container">
    <h1>Rename Document #<?= $id ?></h1>
    <?php if ($error): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <div class="form-group">
        <label>Title</label>
        <input type="text" name="title" required value="<?= htmlspecialchars($doc['title']) ?>">
      </div>
      <button class="btn"><i class="fas fa-save"></i> Save</button>
      <a href="documents.php" class="btn btn-secondary">Cancel</a>
    </form>
  </div>
</body>
</html>
