<?php
// admin/documents.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

if (!Auth::check() || !in_array('admin', Auth::user()['roles'] ?? [])) {
    die('<div class="container"><h1>Access Denied</h1></div>');
}

$pdo = getPDO();

// ---- filtros ----
$filters = [];
$params  = [];

if (!empty($_GET['lead_name'])) {
    $like = '%' . $_GET['lead_name'] . '%';
    $filters[] = "(l.first_name LIKE ? OR l.last_name LIKE ?)";
    $params[] = $like; $params[] = $like;
}
if (!empty($_GET['title'])) {
    $filters[] = "d.title LIKE ?";
    $params[] = '%' . $_GET['title'] . '%';
}
if (!empty($_GET['file_type'])) {
    $filters[] = "d.file_type = ?";
    $params[] = $_GET['file_type'];
}
if (!empty($_GET['date_from'])) {
    $filters[] = "d.uploaded_at >= ?";
    $params[] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $filters[] = "d.uploaded_at <= ?";
    $params[] = $_GET['date_to'];
}

$where = $filters ? 'WHERE ' . implode(' AND ', $filters) : '';

$stmt = $pdo->prepare("
  SELECT d.id, d.title, d.file_name, d.file_type, d.uploaded_at,
         d.file_path, l.first_name, l.last_name
    FROM lead_documents d
    JOIN leads l ON l.id = d.lead_id
    $where
   ORDER BY d.uploaded_at DESC
");
$stmt->execute($params);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Corrige la ruta de los archivos para salir del directorio /admin
 * y evitar el problema de admin/uploads/... apareciendo en el enlace.
 */
function doc_href(string $fp): string {
    $rel = preg_replace('#^(\.\./|/)+#', '', $fp); // elimina prefijos ../ o /
    return '../' . $rel; // sube un nivel desde /admin/
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin: Documents</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="../assets/css/admin/documents.css">
  <style>
    .btn[title]{position:relative}
    .btn[title]:hover::after{content:attr(title);position:absolute;bottom:100%;left:50%;transform:translateX(-50%);background:#333;color:#fff;padding:4px 8px;border-radius:4px;font-size:.75rem;white-space:nowrap;z-index:10}
  </style>
</head>
<body>
  <div class="container">
    <div class="header-actions d-flex justify-content-between align-items-center">
      <h1>All Lead Documents</h1>
      <a href="../leads/list.php" class="btn btn-secondary" title="Back to Leads List">
        <i class="fas fa-arrow-left"></i>
      </a>
    </div>

    <!-- Filter Form -->
    <form method="get" class="filters-form mb-4">
      <div class="form-row">
        <div class="form-group col-md-3">
          <label>Lead Name</label>
          <input type="text" name="lead_name" class="form-control"
                 value="<?= htmlspecialchars($_GET['lead_name'] ?? '') ?>"
                 placeholder="Search by name">
        </div>
        <div class="form-group col-md-3">
          <label>Document Title</label>
          <input type="text" name="title" class="form-control"
                 value="<?= htmlspecialchars($_GET['title'] ?? '') ?>"
                 placeholder="Filter by title">
        </div>
        <div class="form-group col-md-2">
          <label>File Type</label>
          <select name="file_type" class="form-control">
            <option value="">All Types</option>
            <option value="application/pdf" <?= ($_GET['file_type'] ?? '')=='application/pdf'?'selected':'' ?>>PDF</option>
            <option value="image/jpeg" <?= ($_GET['file_type'] ?? '')=='image/jpeg'?'selected':'' ?>>JPEG</option>
            <option value="image/png" <?= ($_GET['file_type'] ?? '')=='image/png'?'selected':'' ?>>PNG</option>
            <option value="application/vnd.openxmlformats-officedocument.wordprocessingml.document" <?= ($_GET['file_type'] ?? '')=='application/vnd.openxmlformats-officedocument.wordprocessingml.document'?'selected':'' ?>>DOCX</option>
            <option value="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" <?= ($_GET['file_type'] ?? '')=='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'?'selected':'' ?>>XLSX</option>
          </select>
        </div>
        <div class="form-group col-md-2">
          <label>Date From</label>
          <input type="date" name="date_from" class="form-control"
                 value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
        </div>
        <div class="form-group col-md-2">
          <label>Date To</label>
          <input type="date" name="date_to" class="form-control"
                 value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
        </div>
        <div class="form-group col-md-2 d-flex align-items-end">
          <button type="submit" class="btn btn-primary mr-2" title="Apply Filters">
            <i class="fas fa-filter"></i>
          </button>
          <a href="documents.php" class="btn btn-secondary" title="Clear Filters">
            <i class="fas fa-times"></i>
          </a>
        </div>
      </div>
    </form>

    <!-- Documents Table -->
    <table class="table">
      <thead>
        <tr>
          <th>ID</th><th>Lead</th><th>Title</th><th>File</th><th>Type</th><th>Uploaded</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$docs): ?>
          <tr><td colspan="7" style="text-align:center">No documents found.</td></tr>
        <?php else: foreach ($docs as $d): ?>
          <tr>
            <td><?= (int)$d['id'] ?></td>
            <td><?= htmlspecialchars($d['first_name'].' '.$d['last_name']) ?></td>
            <td><?= htmlspecialchars($d['title']) ?></td>
            <td>
              <a href="<?= htmlspecialchars(doc_href($d['file_path']), ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                <?= htmlspecialchars($d['file_name']) ?>
              </a>
            </td>
            <td><?= htmlspecialchars($d['file_type']) ?></td>
            <td><?= htmlspecialchars($d['uploaded_at']) ?></td>
            <td>
              <!-- Download -->
              <a class="btn btn-sm btn-outline-secondary"
                 href="<?= htmlspecialchars(doc_href($d['file_path']), ENT_QUOTES, 'UTF-8') ?>"
                 download
                 title="Download File">
                 <i class="fas fa-download"></i>
              </a>

              <!-- Rename -->
              <a class="btn btn-sm btn-outline-secondary"
                 href="document_edit.php?id=<?= (int)$d['id'] ?>"
                 title="Rename Document">
                 <i class="fas fa-edit"></i>
              </a>

              <!-- Delete -->
              <form method="post"
                    action="document_delete.php"
                    style="display:inline"
                    onsubmit="return confirm('Delete “<?= htmlspecialchars($d['title']) ?>”?');">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger" title="Delete Document">
                    <i class="fas fa-trash"></i>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
