<?php
// /leads/edit.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';                // ← Fixed here
require_once __DIR__ . '/../lib/Lead.php';
require_once __DIR__ . '/../lib/LeadLock.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
}

if (!Auth::check()) {
    header('Location: /auth/login.php');
    exit;
}

$user   = Auth::user();
$leadId = (int)($_GET['lead_id'] ?? 0);

if (!$lead = Lead::find($leadId)) {
    die("Lead not found");
}

if (!LeadLock::acquire($leadId, $user['id'])) {
    $lock = LeadLock::check($leadId);
    echo '
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <title>Lead Locked</title>
      <link rel="stylesheet" href="./../assets/css/app.css">
      <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css ">
    </head>
    <body>
      <div class="container">
        <h1><i class="fas fa-lock"></i> Lead Locked</h1>
        <div class="alert">
          This lead is currently being used by another user until 
          ' . htmlspecialchars($lock['expires_at']) . '.
        </div>
        <p><a class="btn btn-secondary" href="../leads/list.php"><i class="fas fa-arrow-left"></i> Back to Leads</a></p>
      </div>
    </body>
    </html>';
    exit;
}

// --- Load attached documents ---
$pdo = getPDO();
$stmt = $pdo->prepare("SELECT * FROM lead_documents WHERE lead_id = ?");
$stmt->execute([$leadId]);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit Lead #<?= $leadId ?></title>
  <link rel="stylesheet" href="./../assets/css/app.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css ">
</head>
<body>

  <div class="container">

    <h1>Edit Lead #<?= $leadId ?></h1>

    <form method="post" action="save.php" class="mb-4">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="id" value="<?= $leadId ?>">

      <div class="form-group">
        <label for="external_id"><i class="fas fa-external-link-alt"></i> External ID</label>
        <input type="text" id="external_id" name="external_id"
          value="<?= htmlspecialchars($lead['external_id'] ?? '') ?>" class="form-control" required>
      </div>

      <div class="form-group">
        <label for="first_name"><i class="fas fa-user"></i> First Name</label>
        <input type="text" id="first_name" name="first_name"
          value="<?= htmlspecialchars($lead['first_name'] ?? '') ?>" class="form-control" required>
      </div>

      <div class="form-group">
        <label for="last_name"><i class="fas fa-user-tag"></i> Last Name</label>
        <input type="text" id="last_name" name="last_name"
          value="<?= htmlspecialchars($lead['last_name'] ?? '') ?>" class="form-control" required>
      </div>

      <div class="form-group">
        <label for="phone"><i class="fas fa-phone"></i> Phone</label>
        <input type="tel" id="phone" name="phone"
          value="<?= htmlspecialchars($lead['phone'] ?? '') ?>" class="form-control" required>
      </div>

      <div class="form-group">
        <label for="email"><i class="fas fa-envelope"></i> Email</label>
        <input type="email" id="email" name="email"
          value="<?= htmlspecialchars($lead['email'] ?? '') ?>" class="form-control">
      </div>

      <!-- Notes Field -->
      <div class="form-group">
        <label for="notes"><i class="fas fa-sticky-note"></i> Notes</label>
        <textarea id="notes" name="notes" class="form-control"><?= htmlspecialchars($lead['notes'] ?? '') ?></textarea>
      </div>

      <!-- Do Not Call Field -->
      <div class="form-group form-check">
        <input type="checkbox" id="do_not_call" name="do_not_call" class="form-check-input"
          <?= $lead['do_not_call'] ? 'checked' : '' ?>>
        <label for="do_not_call" class="form-check-label">Do Not Call</label>
      </div>

      <button type="submit" class="btn"><i class="fas fa-save"></i> Save Lead</button>
    </form>

    <div class="actions">
      <a class="btn btn-secondary" href="view.php?id=<?= $leadId ?>"><i class="fas fa-eye"></i> View Lead</a>
      <a class="btn btn-secondary" href="list.php"><i class="fas fa-list"></i> Back to List</a>
    </div>

    <script>
      window.addEventListener('beforeunload', () => {
        navigator.sendBeacon('release.php', new URLSearchParams({ lead_id: <?= $leadId ?> }));
      });
    </script>

    <!-- Documents Section -->
    <div class="documents mt-4">
      <h3><i class="fas fa-paperclip"></i> Attached Documents</h3>

      <!-- Upload Form -->
      <form method="post" action="../documents/upload.php" enctype="multipart/form-data" class="mb-3">
        <input type="hidden" name="lead_id" value="<?= $leadId ?>">
        <div class="form-group">
          <label for="document"><i class="fas fa-upload"></i> Choose File</label>
          <input type="file" name="document" id="document" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-cloud-upload-alt"></i> Upload</button>
      </form>

      <!-- Document List -->
      <?php if (empty($docs)): ?>
        <p class="text-muted">No documents attached yet.</p>
      <?php else: ?>
        <ul class="list-unstyled">
          <?php foreach ($docs as $doc): ?>
            <?php
              $diskName = basename($doc['file_path']);
              $url      = './../uploads/lead_documents/' . $diskName;
            ?>
            <li class="document-item d-flex align-items-center justify-content-between p-2 border rounded mb-2 bg-light">
              <div class="d-flex align-items-center">
                <i class="fas fa-file-pdf fa-lg text-danger mr-3"></i>
                <div>
                  <a href="<?= htmlspecialchars($url) ?>" target="_blank" class="font-weight-bold">
                    <?= htmlspecialchars($doc['title']) ?>
                  </a>
                  <small class="d-block text-muted">
                    <?= htmlspecialchars($doc['file_type']) ?> – 
                    <?= date('M j, Y', strtotime($doc['uploaded_at'])) ?>
                  </small>
                </div>
              </div>
              <a href="<?= htmlspecialchars($url) ?>" download class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-download"></i>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

    </div>

  </div>

</body>
</html> 