<?php
// leads/edit.php
// Edit lead data + manage document upload/listing
declare(strict_types=1);

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

if (!Auth::check()) {
  header('Location: /auth/login.php');
  exit;
}

$user = Auth::user();
$pdo  = getPDO();

// -------- Helper para URLs de archivos (sube desde /leads a la raíz del proyecto) --------
function file_href(string $fp): string {
    // elimina prefijos peligrosos o redundantes (../ o /)
    $rel = preg_replace('#^(\.\./|/)+#', '', $fp);
    return '../' . $rel; // desde /leads/* hacia /uploads/...
}

// -------- CSRF Helpers --------
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_check(string $token): bool {
  return isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $token);
}
$csrf = (string)$_SESSION['csrf_token'];

// -------- File Helpers --------
const MAX_FILE_BYTES = 10 * 1024 * 1024; // 10MB
$ALLOWED_EXT  = ['pdf','png','jpg','jpeg','docx','xlsx'];
$ALLOWED_MIME = [
  'application/pdf',
  'image/png',
  'image/jpeg',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];

function uuidv4(): string {
  $data = random_bytes(16);
  $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
  $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// -------- Lead ID --------
$leadId = (int)($_GET['id'] ?? $_GET['lead_id'] ?? 0);
if ($leadId <= 0) {
  http_response_code(400);
  exit('Invalid lead.');
}

// -------- Lookups for selects --------
$sources      = $pdo->query("SELECT id, name FROM lead_sources WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$interests    = $pdo->query("SELECT id, name FROM insurance_interests WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$languages    = $pdo->query("SELECT code, description FROM language_codes ORDER BY description")->fetchAll(PDO::FETCH_ASSOC);
$incomes      = $pdo->query("SELECT code, description FROM income_ranges ORDER BY description")->fetchAll(PDO::FETCH_ASSOC);

// -------- Fetch lead --------
$stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
$stmt->execute([$leadId]);
$lead = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$lead) {
  http_response_code(404);
  exit('Lead not found.');
}

// -------- Check if locked by another user (for warning) --------
$lockStmt = $pdo->prepare("SELECT user_id, expires_at FROM lead_locks WHERE lead_id=? AND expires_at>=NOW()");
$lockStmt->execute([$leadId]);
$lock = $lockStmt->fetch(PDO::FETCH_ASSOC);
$isLockedByOther = $lock && (int)$lock['user_id'] !== (int)$user['id'];
$lockExpires     = $lock['expires_at'] ?? null;

// -------- Handle POST (three actions): save lead / upload doc / delete lead --------
$flash = null;
$uploadMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }

  $action = (string)($_POST['__action'] ?? '');

  // --- 1) Save lead data ---
  if ($action === 'save_lead') {
    $prefix     = trim((string)($_POST['prefix'] ?? ''));
    $first_name = trim((string)($_POST['first_name'] ?? ''));
    $mi         = trim((string)($_POST['mi'] ?? ''));
    $last_name  = trim((string)($_POST['last_name'] ?? ''));
    $phone      = trim((string)($_POST['phone'] ?? ''));
    $email      = trim((string)($_POST['email'] ?? ''));
    $address    = trim((string)($_POST['address_line'] ?? ''));
    $suite_apt  = trim((string)($_POST['suite_apt'] ?? ''));
    $city       = trim((string)($_POST['city'] ?? ''));
    $state      = strtoupper(trim((string)($_POST['state'] ?? '')));
    $zip5       = trim((string)($_POST['zip5'] ?? ''));
    $zip4       = trim((string)($_POST['zip4'] ?? ''));
    $age        = $_POST['age'] !== '' ? (int)$_POST['age'] : null;

    $interest_id = $_POST['insurance_interest_id'] !== '' ? (int)$_POST['insurance_interest_id'] : null;
    $source_id   = $_POST['source_id'] !== '' ? (int)$_POST['source_id'] : $lead['source_id'];
    $income      = trim((string)($_POST['income'] ?? ''));
    $language    = trim((string)($_POST['language'] ?? ''));
    $notes       = trim((string)($_POST['notes'] ?? ''));
    $do_not_call = isset($_POST['do_not_call']) ? 1 : 0;

    $errors = [];
    if ($first_name === '' || $last_name === '') $errors[] = 'First and last name are required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';
    if ($state !== '' && strlen($state) !== 2) $errors[] = 'State must be 2 letters.';
    if ($zip5 !== '' && strlen($zip5) !== 5) $errors[] = 'ZIP5 must be 5 digits';

    if ($errors) {
      $flash = ['type' => 'error', 'text' => implode(' ', $errors)];
    } else {
      $upd = $pdo->prepare("
        UPDATE leads SET
          prefix = ?, first_name = ?, mi = ?, last_name = ?, phone = ?, email = ?,
          address_line = ?, suite_apt = ?, city = ?, state = ?, zip5 = ?, zip4 = ?,
          age = ?, insurance_interest_id = ?, source_id = ?, income = ?, language = ?,
          notes = ?, do_not_call = ?
        WHERE id = ?
      ");
      $upd->execute([
        $prefix !== '' ? $prefix : null,
        $first_name,
        $mi !== '' ? $mi : null,
        $last_name,
        $phone,
        $email !== '' ? $email : null,
        $address !== '' ? $address : null,
        $suite_apt !== '' ? $suite_apt : null,
        $city !== '' ? $city : null,
        $state !== '' ? $state : null,
        $zip5 !== '' ? $zip5 : null,
        $zip4 !== '' ? $zip4 : null,
        $age,
        $interest_id,
        $source_id,
        $income !== '' ? $income : null,
        $language !== '' ? $language : null,
        $notes !== '' ? $notes : null,
        $do_not_call,
        $leadId
      ]);

      $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
      $stmt->execute([$leadId]);
      $lead = $stmt->fetch(PDO::FETCH_ASSOC);

      $flash = ['type' => 'success', 'text' => 'Lead updated successfully.'];
    }
  }

  // --- 2) Upload document ---
  if ($action === 'upload_doc') {
    $title = trim((string)($_POST['title'] ?? ''));
    $file  = $_FILES['file'] ?? null;

    if ($title === '' || mb_strlen($title) > 255) {
      $uploadMessage = ['type' => 'error', 'text' => 'Invalid title.'];
    } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK) {
      $uploadMessage = ['type' => 'error', 'text' => 'File missing or upload error.'];
    } elseif ($file['size'] <= 0 || $file['size'] > MAX_FILE_BYTES) {
      $uploadMessage = ['type' => 'error', 'text' => 'File is empty or exceeds 10MB.'];
    } else {
      $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, $ALLOWED_EXT, true)) {
        $uploadMessage = ['type' => 'error', 'text' => 'File extension not allowed.'];
      } else {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
        if (!in_array($mime, $ALLOWED_MIME, true)) {
          $uploadMessage = ['type' => 'error', 'text' => 'File type not allowed.'];
        } else {
          $storedName = uuidv4() . '.' . $ext;
          $uploadDir = __DIR__ . '/../uploads/lead_documents/';
          if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            $uploadMessage = ['type' => 'error', 'text' => 'Could not create uploads folder.'];
          } else {
            $destPath = $uploadDir . $storedName;
            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
              $uploadMessage = ['type' => 'error', 'text' => 'Could not save the file.'];
            } else {
              // Guardamos RUTA RELATIVA SIN BARRA INICIAL
              $relativePath = 'uploads/lead_documents/' . $storedName;

              $ins = $pdo->prepare("
                INSERT INTO lead_documents (lead_id, title, file_name, file_path, file_type, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?)
              ");
              $ins->execute([
                $leadId,
                mb_substr($title, 0, 255),
                $file['name'],
                $relativePath,
                $mime,
                (int)$user['id'],
              ]);
              $uploadMessage = ['type' => 'success', 'text' => 'Document uploaded successfully.'];
            }
          }
        }
      }
    }
  }

  // --- 3) Delete lead ---
  if ($action === 'delete_lead') {
    $del = $pdo->prepare("DELETE FROM leads WHERE id = ?");
    $del->execute([$leadId]);
    header('Location: list.php?deleted=1');
    exit;
  }
}

// -------- Lead documents --------
$docsStmt = $pdo->prepare("
  SELECT id, title, file_name, file_path, file_type, uploaded_at
  FROM lead_documents
  WHERE lead_id = ?
  ORDER BY uploaded_at DESC
");
$docsStmt->execute([$leadId]);
$docs = $docsStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit Lead #<?= htmlspecialchars((string)$leadId, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="../assets/css/leads/edit.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    body {font-family: system-ui,-apple-system,Segoe UI,Roboto,Arial;}
    .container{max-width:1000px;margin:24px auto;padding:0 16px;}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
    .card{border:1px solid #eee;border-radius:12px;padding:16px;background:#fff;}
    .alert{padding:12px 16px;border-radius:8px;margin:10px 0;}
    .alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}
    .alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
    .alert-warn{background:#fff3cd;color:#664d03;border:1px solid #ffe69c;}
    .form-group{margin-bottom:12px;}
    .form-control{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;}
    label i{margin-right:6px;}
    .btn{display:inline-block;padding:10px 14px;border-radius:10px;background:#0d6efd;color:#fff;text-decoration:none;border:0;cursor:pointer;}
    .btn-secondary{background:#6c757d;}
    .btn-danger{background:#dc3545;}
    .row{display:flex;gap:8px;}
  </style>
</head>
<body>
  <div class="container">
    <h1><i class="fas fa-user-edit"></i> Edit Lead #<?= htmlspecialchars((string)$leadId, ENT_QUOTES, 'UTF-8') ?></h1>

    <?php if ($isLockedByOther): ?>
      <div class="alert alert-warn">
        <i class="fas fa-lock"></i> This lead is currently being edited by another user until
        <strong><?= htmlspecialchars((string)$lockExpires, ENT_QUOTES, 'UTF-8') ?></strong>. You can view the information, but <strong>please avoid saving changes</strong> to prevent overwriting their work.
      </div>
    <?php endif; ?>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['type']==='success' ? 'alert-success' : 'alert-error' ?>">
        <?= htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <div class="grid">
      <!-- Left column: Edit form -->
      <div class="card">
        <h2><i class="fas fa-id-card"></i> Lead Details</h2>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="__action" value="save_lead">

          <div class="row">
            <div class="form-group" style="flex:1;">
              <label for="prefix">Prefix</label>
              <input class="form-control" type="text" id="prefix" name="prefix" value="<?= htmlspecialchars((string)($lead['prefix'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group" style="flex:2;">
              <label for="first_name">First Name *</label>
              <input class="form-control" type="text" id="first_name" name="first_name" required value="<?= htmlspecialchars((string)$lead['first_name'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group" style="flex:1;">
              <label for="mi">MI</label>
              <input class="form-control" type="text" id="mi" name="mi" value="<?= htmlspecialchars((string)($lead['mi'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group" style="flex:2;">
              <label for="last_name">Last Name *</label>
              <input class="form-control" type="text" id="last_name" name="last_name" required value="<?= htmlspecialchars((string)$lead['last_name'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
          </div>

          <div class="row">
            <div class="form-group" style="flex:2;">
              <label for="phone"><i class="fas fa-phone"></i> Phone</label>
              <input class="form-control" type="text" id="phone" name="phone" value="<?= htmlspecialchars((string)$lead['phone'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group" style="flex:3;">
              <label for="email"><i class="fas fa-envelope"></i> Email</label>
              <input class="form-control" type="email" id="email" name="email" value="<?= htmlspecialchars((string)($lead['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group" style="flex:1;">
              <label for="age"><i class="fas fa-user"></i> Age</label>
              <input class="form-control" type="number" id="age" name="age" value="<?= htmlspecialchars((string)($lead['age'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
          </div>

          <div class="form-group">
            <label for="address_line"><i class="fas fa-map-marker-alt"></i> Address</label>
            <input class="form-control" type="text" id="address_line" name="address_line" value="<?= htmlspecialchars((string)($lead['address_line'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>

          <div class="row">
            <div class="form-group" style="flex:1;">
              <label for="suite_apt">Apt/Suite</label>
              <input class="form-control" type="text" id="suite_apt" name="suite_apt" value="<?= htmlspecialchars((string)($lead['suite_apt'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group" style="flex:2;">
              <label for="city">City</label>
              <input class="form-control" type="text" id="city" name="city" value="<?= htmlspecialchars((string)($lead['city'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group" style="flex:1;">
              <label for="state">State (2)</label>
              <input class="form-control" type="text" id="state" name="state" maxlength="2" value="<?= htmlspecialchars((string)($lead['state'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group" style="flex:1;">
              <label for="zip5">ZIP5</label>
              <input class="form-control" type="text" id="zip5" name="zip5" maxlength="5" value="<?= htmlspecialchars((string)($lead['zip5'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group" style="flex:1;">
              <label for="zip4">ZIP4</label>
              <input class="form-control" type="text" id="zip4" name="zip4" maxlength="4" value="<?= htmlspecialchars((string)($lead['zip4'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
          </div>

          <div class="row">
            <div class="form-group" style="flex:1;">
              <label for="insurance_interest_id">Interest</label>
              <select class="form-control" id="insurance_interest_id" name="insurance_interest_id">
                <option value="">—</option>
                <?php foreach ($interests as $i): ?>
                  <option value="<?= (int)$i['id'] ?>" <?= ((int)($lead['insurance_interest_id'] ?? 0) === (int)$i['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string)$i['name'], ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="flex:1;">
              <label for="source_id">Source</label>
              <select class="form-control" id="source_id" name="source_id">
                <?php foreach ($sources as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= ((int)$lead['source_id'] === (int)$s['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string)$s['name'], ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="flex:1;">
              <label for="income">Income</label>
              <select class="form-control" id="income" name="income">
                <option value="">—</option>
                <?php foreach ($incomes as $i): ?>
                  <option value="<?= htmlspecialchars((string)$i['code'], ENT_QUOTES, 'UTF-8') ?>" <?= (($lead['income'] ?? '') === $i['code']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string)$i['description'], ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="flex:1;">
              <label for="language">Language</label>
              <select class="form-control" id="language" name="language">
                <option value="">—</option>
                <?php foreach ($languages as $l): ?>
                  <option value="<?= htmlspecialchars((string)$l['code'], ENT_QUOTES, 'UTF-8') ?>" <?= (($lead['language'] ?? '') === $l['code']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string)$l['description'], ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label for="notes"><i class="fas fa-sticky-note"></i> Notes</label>
            <textarea class="form-control" id="notes" name="notes" rows="4"><?= htmlspecialchars((string)($lead['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
          </div>

          <div class="form-group">
            <label>
              <input type="checkbox" name="do_not_call" value="1" <?= ((int)$lead['do_not_call'] === 1) ? 'checked' : '' ?>>
              <strong>Do Not Call (DNC)</strong>
            </label>
          </div>

          <button type="submit" class="btn" <?= $isLockedByOther ? 'disabled' : '' ?>>
            <i class="fas fa-save"></i> Save Changes
          </button>
          <?php if (!$isLockedByOther): ?>
            <button type="button" class="btn btn-danger" onclick="confirmDelete()">
              <i class="fas fa-trash"></i> Delete Lead
            </button>
          <?php endif; ?>
          <a class="btn btn-secondary" href="list.php"><i class="fas fa-arrow-left"></i> Back</a>
        </form>
      </div>

      <!-- Right column: Documents -->
      <div class="card">
        <h2><i class="fas fa-paperclip"></i> Lead Documents</h2>

        <?php if ($uploadMessage): ?>
          <div class="alert <?= $uploadMessage['type']==='success' ? 'alert-success' : 'alert-error' ?>">
            <?= htmlspecialchars($uploadMessage['text'], ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <?php if (!$docs): ?>
          <p class="muted">No documents yet.</p>
        <?php else: ?>
          <ul style="list-style:none;padding:0;margin:0;">
            <?php foreach ($docs as $d): ?>
              <li style="border:1px solid #f1f5f9;border-radius:8px;padding:10px;margin:8px 0;">
                <strong><?= htmlspecialchars((string)$d['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                <div style="color:#6b7280;font-size:.9rem;">
                  <?= htmlspecialchars((string)$d['file_type'], ENT_QUOTES, 'UTF-8') ?> —
                  <?= htmlspecialchars((string)$d['uploaded_at'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div style="margin-top:6px;">
                  <a class="btn" href="<?= htmlspecialchars(file_href($d['file_path']), ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                    <i class="fa-solid fa-download"></i> Download
                  </a>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <hr style="margin:14px 0; border:none; border-top:1px solid #eee;">
        <h3>Upload New Document</h3>
        <form method="post" enctype="multipart/form-data" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="__action" value="upload_doc">

          <div class="form-group">
            <label for="title"><i class="fa-regular fa-rectangle-list"></i> Title</label>
            <input class="form-control" type="text" id="title" name="title" maxlength="255" required>
          </div>

          <div class="form-group">
            <label for="file"><i class="fa-regular fa-file"></i> File</label>
            <input class="form-control" type="file" id="file" name="file" required>
            <small style="color:#6b7280;">Allowed: PDF, PNG, JPG, DOCX, XLSX. Max: 10MB.</small>
          </div>

          <button type="submit" class="btn"><i class="fa-solid fa-upload"></i> Upload</button>
        </form>
      </div>
    </div>

    <p style="margin-top:14px;">
      <a class="btn btn-secondary" href="list.php"><i class="fas fa-arrow-left"></i> Back to List</a>
    </p>
  </div>

  <script>
  function confirmDelete() {
    if (confirm('Are you sure you want to delete this lead? This action cannot be undone.')) {
      const form = document.createElement('form');
      form.method = 'POST';
      form.style.display = 'none';

      const csrf = document.createElement('input');
      csrf.type = 'hidden';
      csrf.name = 'csrf_token';
      csrf.value = '<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>';
      form.appendChild(csrf);

      const action = document.createElement('input');
      action.type = 'hidden';
      action.name = '__action';
      action.value = 'delete_lead';
      form.appendChild(action);

      document.body.appendChild(form);
      form.submit();
    }
  }
  </script>
</body>
</html>
