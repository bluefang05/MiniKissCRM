<?php
// leads/edit.php
// Edita los datos del lead + gestiona subida/listado de documentos
declare(strict_types=1);

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

if (!Auth::check()) {
  header('Location: /auth/login.php');
  exit;
}

$user = Auth::user();
$pdo  = getPDO();

// -------- Helpers CSRF --------
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_check(string $token): bool {
  return isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $token);
}
$csrf = (string)$_SESSION['csrf_token'];

// -------- Helpers Archivos --------
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

// -------- ID lead --------
$leadId = (int)($_GET['id'] ?? $_GET['lead_id'] ?? 0);
if ($leadId <= 0) {
  http_response_code(400);
  exit('Lead inválido.');
}

// -------- Lookups para selects --------
$sources      = $pdo->query("SELECT id, name FROM lead_sources WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$interests    = $pdo->query("SELECT id, name FROM insurance_interests WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$languages    = $pdo->query("SELECT code, description FROM language_codes ORDER BY description")->fetchAll(PDO::FETCH_ASSOC);
$incomes      = $pdo->query("SELECT code, description FROM income_ranges ORDER BY description")->fetchAll(PDO::FETCH_ASSOC);

// -------- Traer lead --------
$stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
$stmt->execute([$leadId]);
$lead = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$lead) {
  http_response_code(404);
  exit('Lead no encontrado.');
}

// -------- Saber si está locked por otro (para avisar) --------
$lockStmt = $pdo->prepare("SELECT user_id, expires_at FROM lead_locks WHERE lead_id=? AND expires_at>=NOW()");
$lockStmt->execute([$leadId]);
$lock = $lockStmt->fetch(PDO::FETCH_ASSOC);
$isLockedByOther = $lock && (int)$lock['user_id'] !== (int)$user['id'];
$lockExpires     = $lock['expires_at'] ?? null;

// -------- Manejo POST (dos acciones): guardar lead / subir doc --------
$flash = null;       // mensajes del formulario de lead
$uploadMessage = null; // mensajes del formulario de documentos

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('CSRF inválido');
  }

  $action = (string)($_POST['__action'] ?? '');

  // --- 1) Guardar datos del lead ---
  if ($action === 'save_lead') {
    // Campos editables (usa los que tienes en la tabla)
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
    $source_id   = $_POST['source_id'] !== '' ? (int)$_POST['source_id'] : $lead['source_id']; // conserva si no se manda
    $income      = trim((string)($_POST['income'] ?? ''));
    $language    = trim((string)($_POST['language'] ?? ''));
    $notes       = trim((string)($_POST['notes'] ?? ''));
    $do_not_call = isset($_POST['do_not_call']) ? 1 : 0;

    // Validaciones simples
    $errors = [];
    if ($first_name === '' || $last_name === '') $errors[] = 'Nombre y apellido son requeridos.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email inválido.';
    if ($state !== '' && strlen($state) !== 2) $errors[] = 'State debe ser de 2 letras.';
    if ($zip5 !== '' && strlen($zip5) !== 5) $errors[] = 'ZIP5 debe ser de 5 dígitos.';

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

      // Refrescar $lead
      $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
      $stmt->execute([$leadId]);
      $lead = $stmt->fetch(PDO::FETCH_ASSOC);

      $flash = ['type' => 'success', 'text' => 'Lead actualizado correctamente.'];
    }
  }

  // --- 2) Subir documento ---
  if ($action === 'upload_doc') {
    $title = trim((string)($_POST['title'] ?? ''));
    $file  = $_FILES['file'] ?? null;

    if ($title === '' || mb_strlen($title) > 255) {
      $uploadMessage = ['type' => 'error', 'text' => 'Título inválido.'];
    } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK) {
      $uploadMessage = ['type' => 'error', 'text' => 'Archivo faltante o con error.'];
    } elseif ($file['size'] <= 0 || $file['size'] > MAX_FILE_BYTES) {
      $uploadMessage = ['type' => 'error', 'text' => 'Archivo vacío o supera 10MB.'];
    } else {
      $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, $ALLOWED_EXT, true)) {
        $uploadMessage = ['type' => 'error', 'text' => 'Extensión no permitida.'];
      } else {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
        if (!in_array($mime, $ALLOWED_MIME, true)) {
          $uploadMessage = ['type' => 'error', 'text' => 'Tipo de archivo no permitido.'];
        } else {
          $storedName = uuidv4() . '.' . $ext;
          $storageDir = __DIR__ . '/../storage/lead_documents/';
          if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            $uploadMessage = ['type' => 'error', 'text' => 'No se pudo crear la carpeta de almacenamiento.'];
          } else {
            $destPath = $storageDir . $storedName;
            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
              $uploadMessage = ['type' => 'error', 'text' => 'No se pudo guardar el archivo.'];
            } else {
              $ins = $pdo->prepare("
                INSERT INTO lead_documents (lead_id, title, file_name, file_path, file_type, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?)
              ");
              $ins->execute([
                $leadId,
                mb_substr($title, 0, 255),
                $storedName,
                'storage/lead_documents/',
                $mime,
                (int)$user['id'],
              ]);
              $uploadMessage = ['type' => 'success', 'text' => 'Documento subido correctamente.'];
            }
          }
        }
      }
    }
  }
}

// -------- Documentos del lead --------
$docsStmt = $pdo->prepare("
  SELECT id, title, file_name, file_type, uploaded_at
  FROM lead_documents
  WHERE lead_id = ?
  ORDER BY uploaded_at DESC
");
$docsStmt->execute([$leadId]);
$docs = $docsStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
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
    .row{display:flex;gap:8px;}
  </style>
</head>
<body>
  <div class="container">
    <h1><i class="fas fa-user-edit"></i> Edit Lead #<?= htmlspecialchars((string)$leadId, ENT_QUOTES, 'UTF-8') ?></h1>

    <?php if ($isLockedByOther): ?>
      <div class="alert alert-warn">
        <i class="fas fa-lock"></i> Este lead está siendo usado por otro usuario hasta
        <strong><?= htmlspecialchars((string)$lockExpires, ENT_QUOTES, 'UTF-8') ?></strong>. Puedes revisar la información, pero **mejor evita guardar cambios** para no pisarlos.
      </div>
    <?php endif; ?>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['type']==='success' ? 'alert-success' : 'alert-error' ?>">
        <?= htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <div class="grid">
      <!-- Columna izquierda: Form de edición -->
      <div class="card">
        <h2><i class="fas fa-id-card"></i> Datos del Lead</h2>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="__action" value="save_lead">

          <div class="row">
            <div class="form-group" style="flex:1;">
              <label for="prefix">Prefijo</label>
              <input class="form-control" type="text" id="prefix" name="prefix" value="<?= htmlspecialchars((string)($lead['prefix'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group" style="flex:2;">
              <label for="first_name">Nombre *</label>
              <input class="form-control" type="text" id="first_name" name="first_name" required value="<?= htmlspecialchars((string)$lead['first_name'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group" style="flex:1;">
              <label for="mi">MI</label>
              <input class="form-control" type="text" id="mi" name="mi" value="<?= htmlspecialchars((string)($lead['mi'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group" style="flex:2;">
              <label for="last_name">Apellido *</label>
              <input class="form-control" type="text" id="last_name" name="last_name" required value="<?= htmlspecialchars((string)$lead['last_name'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
          </div>

          <div class="row">
            <div class="form-group" style="flex:2;">
              <label for="phone"><i class="fas fa-phone"></i> Teléfono</label>
              <input class="form-control" type="text" id="phone" name="phone" value="<?= htmlspecialchars((string)$lead['phone'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group" style="flex:3;">
              <label for="email"><i class="fas fa-envelope"></i> Email</label>
              <input class="form-control" type="email" id="email" name="email" value="<?= htmlspecialchars((string)($lead['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group" style="flex:1;">
              <label for="age"><i class="fas fa-user"></i> Edad</label>
              <input class="form-control" type="number" id="age" name="age" value="<?= htmlspecialchars((string)($lead['age'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
          </div>

          <div class="form-group">
            <label for="address_line"><i class="fas fa-map-marker-alt"></i> Dirección</label>
            <input class="form-control" type="text" id="address_line" name="address_line" value="<?= htmlspecialchars((string)($lead['address_line'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
          </div>

          <div class="row">
            <div class="form-group" style="flex:1;">
              <label for="suite_apt">Apto/Suite</label>
              <input class="form-control" type="text" id="suite_apt" name="suite_apt" value="<?= htmlspecialchars((string)($lead['suite_apt'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group" style="flex:2;">
              <label for="city">Ciudad</label>
              <input class="form-control" type="text" id="city" name="city" value="<?= htmlspecialchars((string)($lead['city'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group" style="flex:1;">
              <label for="state">Estado (2)</label>
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
              <label for="insurance_interest_id">Interés</label>
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
              <label for="source_id">Fuente</label>
              <select class="form-control" id="source_id" name="source_id">
                <?php foreach ($sources as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= ((int)$lead['source_id'] === (int)$s['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string)$s['name'], ENT_QUOTES, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="flex:1;">
              <label for="income">Ingreso</label>
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
              <label for="language">Idioma</label>
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
            <label for="notes"><i class="fas fa-sticky-note"></i> Notas</label>
            <textarea class="form-control" id="notes" name="notes" rows="4"><?= htmlspecialchars((string)($lead['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
          </div>

          <div class="form-group">
            <label>
              <input type="checkbox" name="do_not_call" value="1" <?= ((int)$lead['do_not_call'] === 1) ? 'checked' : '' ?>>
              <strong>No llamar (DNC)</strong>
            </label>
          </div>

          <button type="submit" class="btn" <?= $isLockedByOther ? 'disabled' : '' ?>>
            <i class="fas fa-save"></i> Guardar cambios
          </button>
          <a class="btn btn-secondary" href="list.php"><i class="fas fa-arrow-left"></i> Volver</a>
        </form>
      </div>

      <!-- Columna derecha: Documentos -->
      <div class="card">
        <h2><i class="fas fa-paperclip"></i> Documentos del Lead</h2>

        <?php if ($uploadMessage): ?>
          <div class="alert <?= $uploadMessage['type']==='success' ? 'alert-success' : 'alert-error' ?>">
            <?= htmlspecialchars($uploadMessage['text'], ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <?php if (!$docs): ?>
          <p class="muted">No hay documentos aún.</p>
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
                  <a class="btn" href="/download.php?id=<?= (int)$d['id'] ?>"><i class="fa-solid fa-download"></i> Descargar</a>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <hr style="margin:14px 0; border:none; border-top:1px solid #eee;">
        <h3>Subir nuevo documento</h3>
        <form method="post" enctype="multipart/form-data" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="__action" value="upload_doc">

          <div class="form-group">
            <label for="title"><i class="fa-regular fa-rectangle-list"></i> Título</label>
            <input class="form-control" type="text" id="title" name="title" maxlength="255" required>
          </div>

          <div class="form-group">
            <label for="file"><i class="fa-regular fa-file"></i> Archivo</label>
            <input class="form-control" type="file" id="file" name="file" required>
            <small style="color:#6b7280;">Permitidos: PDF, PNG, JPG, DOCX, XLSX. Máx: 10MB.</small>
          </div>

          <button type="submit" class="btn"><i class="fa-solid fa-upload"></i> Subir</button>
        </form>
      </div>
    </div>

    <p style="margin-top:14px;">
      <a class="btn btn-secondary" href="list.php"><i class="fas fa-arrow-left"></i> Volver a la lista</a>
    </p>
  </div>
</body>
</html>
