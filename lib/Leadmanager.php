<?php
// /leads/import.php

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/LeadManager.php';
require_once __DIR__ . '/../lib/db.php';

session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (
    empty($_POST['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
  ) {
    http_response_code(403);
    exit('Invalid CSRF token');
  }
}


if (!Auth::check()) {
    header('Location: /auth/login.php');
    exit;
}

$user    = Auth::user();
$sources = getPDO()
    ->query("SELECT id, name FROM lead_sources WHERE active = 1 ORDER BY name")
    ->fetchAll();

$error   = null;
$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validar selección de fuente
        if (empty($_POST['source_id'])) {
            throw new Exception('Debes seleccionar la fuente de estos leads.');
        }
        $sourceId = (int) $_POST['source_id'];

        // Ejecutar importación
        $summary = LeadManager::runUpload($user['id'], $sourceId);

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Importar Leads</title>
  <link rel="stylesheet" href="./../assets/css/app.css">
</head>
<body>
  <h1>Importar Leads</h1>

  <?php if ($summary): ?>
    <div class="summary">
      <strong>Resumen de importación:</strong><br>
      Nuevos: <?= $summary['created'] ?>, 
      Actualizados: <?= $summary['updated'] ?>, 
      Omitidos: <?= $summary['skipped'] ?>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

    <label>
      Fuente de estos leads:<br>
      <select name="source_id" required>
        <option value="">— selecciona —</option>
        <?php foreach ($sources as $s): ?>
          <option 
            value="<?= $s['id'] ?>" 
            <?= (isset($_POST['source_id']) && $_POST['source_id']==$s['id']) ? 'selected' : '' ?>
          >
            <?= htmlspecialchars($s['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>
      Archivo CSV:<br>
      <input type="file" name="file" accept=".csv" required>
    </label>

    <button type="submit">Importar</button>
  </form>

  <p><a href="list.php">« Volver a listado</a></p>
</body>
</html>
