<?php
// leads/import_custom.php
// Advanced lead import with manual column mapping for custom file formats

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Lead.php';
require_once __DIR__ . '/../lib/AuditLog.php';
require_once __DIR__ . '/../lib/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$pdo  = getPDO();

//---------------------------------------------------
// Lead sources
//---------------------------------------------------
$sources = $pdo->query("
    SELECT id, name
      FROM lead_sources
     WHERE active = 1
  ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// Fallback (primer source activo) para respetar NOT NULL en leads.source_id
$fallbackSourceId = (int)($pdo->query("SELECT id FROM lead_sources WHERE active=1 ORDER BY id LIMIT 1")->fetchColumn() ?: 0);

//---------------------------------------------------
// Insurance interests
//---------------------------------------------------
$interestRows = $pdo->query("
    SELECT id, name
      FROM insurance_interests
     WHERE active = 1
  ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// Build a lookup map for CSV interest → interest_id
$interestMap = [];
foreach ($interestRows as $r) {
    $interestMap[strtolower($r['name'])] = $r['id'];
}

// Define system fields with metadata
$systemFields = [
    'prefix'      => ['label' => 'Prefix/Title', 'required' => false],
    'first_name'  => ['label' => 'First Name', 'required' => true],
    'mi'          => ['label' => 'Middle Initial', 'required' => false],
    'last_name'   => ['label' => 'Last Name', 'required' => true],
    'phone'       => ['label' => 'Phone', 'required' => true],
    'email'       => ['label' => 'Email', 'required' => false],
    'address_line'=> ['label' => 'Address', 'required' => false],
    'suite_apt'   => ['label' => 'Suite/Apt', 'required' => false],
    'city'        => ['label' => 'City', 'required' => false],
    'state'       => ['label' => 'State', 'required' => false],
    'zip5'        => ['label' => 'ZIP Code', 'required' => false],
    'zip4'        => ['label' => 'ZIP+4', 'required' => false],
    'delivery_point_bar_code' => ['label' => 'DPBC', 'required' => false],
    'carrier_route'=> ['label' => 'Carrier Route', 'required' => false],
    'fips_county_code' => ['label' => 'FIPS County Code', 'required' => false],
    'county_name' => ['label' => 'County Name', 'required' => false],
    'age'         => ['label' => 'Age', 'required' => false],
    'language'    => ['label' => 'Language', 'required' => false],
    'income'      => ['label' => 'Income', 'required' => false],
    'external_id' => ['label' => 'External ID', 'required' => false],
    'interest'    => ['label' => 'Insurance Interest', 'required' => false],
];

$error   = '';
$summary = null;
$step    = 1; // Step 1: Upload, Step 2: Map

// Cancel import
if (isset($_GET['cancel']) && !empty($_SESSION['import_temp'])) {
    if ($_SESSION['import_temp']['from_excel'] ?? false) {
        @unlink($_SESSION['import_temp']['file_path']);
    }
    unset($_SESSION['import_temp']);
    header('Location: import_custom.php');
    exit;
}

//---------------------------------------------------
// STEP 1: Upload & Detect Columns
//---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'detect_columns') {
    try {
        // AHORA OPCIONALES: source_id y default_interest_id (no lanzamos error)
        $chosenSourceId = (isset($_POST['source_id']) && $_POST['source_id'] !== '') ? (int)$_POST['source_id'] : null;
        $chosenDefaultInterestId = (isset($_POST['default_interest_id']) && $_POST['default_interest_id'] !== '') ? (int)$_POST['default_interest_id'] : null;

        // Handle file
        if (!empty($_POST['csv_data'])) {
            $csvData = $_POST['csv_data'];
            $tmpPath = tempnam(sys_get_temp_dir(), 'xlsx_');
            file_put_contents($tmpPath, $csvData);
            $filename = 'excel_import.csv';
            $fromExcel = true;
        } else {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Error uploading file.');
            }
            $tmpPath = $_FILES['file']['tmp_name'];
            $filename = $_FILES['file']['name'];
            $fromExcel = false;
        }

        // Read header
        $fh = fopen($tmpPath, 'r');
        if (!$fh) throw new Exception('Cannot open CSV file.');
        $firstLine = fgets($fh);
        if (strpos($firstLine, "\xEF\xBB\xBF") === 0) {
            $firstLine = substr($firstLine, 3);
        }
        $header = str_getcsv(rtrim($firstLine, "\r\n"));
        fclose($fh);

        if (!$header || count($header) === 0) {
            throw new Exception('CSV has no header row.');
        }

        // Auto-suggest mappings
        $fieldAliases = [
            'prefix'      => ['prefix', 'title', 'titulo', 'salutation'],
            'first_name'  => ['first_name', 'fname', 'name', 'nombre', 'first', 'firstname', 'given_name', 'primer_nombre'],
            'mi'          => ['mi', 'middle_initial', 'middle', 'segunda_letra', 'initial'],
            'last_name'   => ['last_name', 'lname', 'apellido', 'last', 'lastname', 'surname', 'family_name', 'apellidos'],
            'phone'       => ['phone', 'telefono', 'tel', 'mobile', 'cell', 'celular', 'phone_number', 'movil', 'whatsapp'],
            'email'       => ['email', 'correo', 'mail', 'e_mail', 'email_address', 'correo_electronico'],
            'address_line'=> ['address', 'direccion', 'address_line', 'street', 'calle', 'dir', 'domicilio'],
            'suite_apt'   => ['suite', 'apto', 'apartment', 'unit', 'depto', 'apartamento', 'interior', 'suite_apt'],
            'city'        => ['city', 'ciudad', 'town', 'municipio', 'localidad'],
            'state'       => ['state', 'estado', 'province', 'region', 'provincia', 'st'],
            'zip5'        => ['zip', 'zipcode', 'postal_code', 'cp', 'codigo_postal', 'zip5', 'postcode'],
            'zip4'        => ['zip4', 'plus4', 'codigo_postal4'],
            'delivery_point_bar_code' => ['delivery_point_bar_code', 'dpbc', 'barcode'],
            'carrier_route'=> ['carrier_route', 'route', 'ruta'],
            'fips_county_code' => ['fips_county_code', 'fips', 'county_code'],
            'county_name' => ['county_name', 'county', 'condado', 'municipio'],
            'age'         => ['age', 'edad', 'years'],
            'language'    => ['language', 'idioma', 'lang', 'lenguaje'],
            'income'      => ['income', 'ingresos', 'salary', 'renta', 'ingreso', 'sueldo'],
            'external_id' => ['id', 'lead_id', 'external_id', 'ref', 'reference', 'clave', 'folio'],
            'interest'    => ['interest', 'producto', 'insurance_type', 'tipo_seguro', 'producto_interes', 'seguro'],
        ];

        $normalizedHeader = array_map(fn($h) => strtolower(trim($h)), $header);
        $suggestedMapping = [];
        foreach ($fieldAliases as $systemField => $aliases) {
            foreach ($aliases as $alias) {
                $idx = array_search($alias, $normalizedHeader);
                if ($idx !== false) {
                    $suggestedMapping[$systemField] = $header[$idx];
                    break;
                }
            }
        }

        $_SESSION['import_temp'] = [
            'file_path' => $tmpPath,
            'filename' => $filename,
            'header' => $header,
            'source_id' => $chosenSourceId,                 // puede ser null
            'default_interest_id' => $chosenDefaultInterestId, // puede ser null
            'from_excel' => $fromExcel,
            'suggested_mapping' => $suggestedMapping,
        ];

        $step = 2;

    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}

//---------------------------------------------------
// STEP 2: Process with Mapping
//---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_import') {
    try {
        if (empty($_SESSION['import_temp'])) {
            throw new Exception('Import session expired. Please start over.');
        }

        $temp = $_SESSION['import_temp'];
        $tmpPath = $temp['file_path'];
        $header = $temp['header'];
        $sourceId = $temp['source_id'] ?? null; // opcional
        $defaultInterestId = $temp['default_interest_id'] ?? null; // opcional

        // Build mapping from POST
        $columnMapping = [];
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'map_') === 0 && !empty($value)) {
                $systemField = substr($key, 4);
                $columnMapping[$systemField] = $value;
            }
        }

        // Validate required fields
        $requiredFields = ['first_name', 'last_name', 'phone'];
        foreach ($requiredFields as $field) {
            if (empty($columnMapping[$field])) {
                throw new Exception("Required field '{$systemFields[$field]['label']}' must be mapped.");
            }
        }

        // Index columns
        $colIndex = array_flip(array_map('trim', $header));

        // Process file
        $fh = fopen($tmpPath, 'r');
        if (!$fh) throw new Exception('Cannot open CSV file.');
        fgets($fh); // Skip header

        $existsStmt = $pdo->prepare('SELECT id FROM leads WHERE external_id = ? LIMIT 1');
        $created = $updated = $skipped = 0;
        $pdo->beginTransaction();
        $generatedIds = [];

        while (($row = fgetcsv($fh)) !== false) {
            if (!$row || count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue;

            $data = [];
            foreach ($columnMapping as $systemField => $csvColumn) {
                $idx = $colIndex[$csvColumn] ?? null;
                $data[$systemField] = ($idx !== null && isset($row[$idx])) ? $row[$idx] : null;
            }

            // Clean & transform
            if (!empty($data['phone'])) {
                $data['phone'] = preg_replace('/\D+/', '', $data['phone']);
            }

            // interest: del CSV o default; si nada, queda NULL
            $interestFromCsv = !empty($data['interest']) ? ($interestMap[strtolower(trim($data['interest']))] ?? null) : null;
            $data['insurance_interest_id'] = $interestFromCsv ?? $defaultInterestId ?? null;

            $data['language'] = !empty($data['language']) ? trim($data['language']) : null;
            $data['income'] = !empty($data['income']) ? strtoupper(substr(trim($data['income']), 0, 1)) : null;

            // External ID
            $ext = !empty($data['external_id']) ? trim($data['external_id']) : '';
            if ($ext === '') {
                do { $ext = uniqid('lead_', true); } while (isset($generatedIds[$ext]));
                $generatedIds[$ext] = true;
            }
            $data['external_id'] = $ext;

            // Fixed fields
            // source_id obligatorio por esquema: usa el elegido; si no hay, usa fallback
            $data['source_id'] = $sourceId ?: $fallbackSourceId;
            $data['do_not_call'] = 0;
            $data['uploaded_by'] = $user['id'];

            // Whitelist
            $allowed = [
                'external_id','prefix','first_name','mi','last_name',
                'phone','email','address_line','suite_apt','city',
                'state','zip5','zip4','delivery_point_bar_code',
                'carrier_route','fips_county_code','county_name',
                'age','insurance_interest_id','language','income',
                'source_id','do_not_call','uploaded_by'
            ];
            $data = array_intersect_key($data, array_flip($allowed));

            // Upsert
            $existsStmt->execute([$ext]);
            $found = (bool) $existsStmt->fetch();
            try {
                Lead::upsertByExternalId($data);
                $found ? $updated++ : $created++;
            } catch (Throwable $e) {
                error_log("Import error: {$e->getMessage()} — " . json_encode($data));
                $skipped++;
            }
        }

        fclose($fh);
        if ($temp['from_excel']) unlink($tmpPath);
        $pdo->commit();

        // Audit
        $srcForLog = ($sourceId ?? 'NULL');
        $defIntForLog = ($defaultInterestId ?? 'NULL');
        AuditLog::log(
            $user['id'],
            'import_leads',
            "file={$temp['filename']} src={$srcForLog} default_interest={$defIntForLog} created={$created} updated={$updated} skipped={$skipped}"
        );

        $summary = compact('created', 'updated', 'skipped');
        unset($_SESSION['import_temp']);
        $step = 1;

    } catch (Exception $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $ex->getMessage();
        $step = 2;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Import Custom Leads</title>
  <link rel="stylesheet" href="./../assets/css/leads/import.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
  <script src="../assets/js/spreadsheet-importer.js"></script>
  <style>
    .step-indicator { display: flex; margin-bottom: 20px; font-weight: 500; }
    .step { padding: 8px 16px; }
    .step.active { background: #0d6efd; color: white; }
    .step.completed { background: #198754; color: white; }
    .step.pending { background: #e9ecef; color: #6c757d; }
    .mapping-container { max-width: 1000px; margin: 0 auto; }
    .mapping-row { display: grid; grid-template-columns: 250px 50px 1fr; gap: 15px; align-items: center; margin-bottom: 15px; padding: 12px; background: #f8f9fa; border-radius: 6px; border: 1px solid #e9ecef; }
    .mapping-row.required { background: #fff3cd; border-color: #ffeaa7; }
    .system-field { font-weight: 600; }
    .system-field.required::after { content: " *"; color: #dc3545; }
    .arrow { text-align: center; color: #6c757d; font-size: 20px; }
    .column-select { width: 100%; padding: 8px 12px; border: 1px solid #ced4da; border-radius: 6px; font-size: 14px; }
    .preview-box { background: #f1f3f5; padding: 16px; border-radius: 8px; margin-bottom: 24px; border-left: 4px solid #0d6efd; }
    .btn { padding: 8px 16px; margin-right: 8px; text-decoration: none; display: inline-block; }
    .btn-secondary { background: #6c757d; color: white; }
  </style>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      <?php if ($step === 1): ?>
      SpreadsheetImporter.init({
        formId: 'importForm',
        inputId: 'file',
        textareaId: 'csv_data'
      });
      <?php endif; ?>
    });
  </script>
</head>
<body>

  <div class="container">
    <h1><i class="fas fa-file-import"></i> Import Custom Leads</h1>

    <!-- Step Indicator -->
    <div class="step-indicator">
      <div class="step <?= $step >= 1 ? 'active' : 'completed' ?>">1. Upload File</div>
      <div class="step <?= $step === 2 ? 'active' : ($step > 2 ? 'completed' : 'pending') ?>">2. Map Columns</div>
    </div>

    <?php if ($error): ?>
      <div class="error summary" style="padding:12px;background:#f8d7da;color:#721c24;border:1px solid:#f5c6cb;border-radius:6px;margin:15px 0;">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($summary): ?>
      <div class="summary" style="padding:12px;background:#d1e7dd;color:#0f5132;border:1px solid:#badbcc;border-radius:6px;margin:15px 0;">
        <strong>Import completed successfully!</strong><br>
        New: <?= $summary['created'] ?> | Updated: <?= $summary['updated'] ?> | Skipped: <?= $summary['skipped'] ?>
      </div>
      <p>
        <a class="btn" href="list.php" style="background:#198754;color:white;">View Leads</a>
        <a class="btn btn-secondary" href="import_custom.php">Import Another</a>
      </p>
    <?php endif; ?>

    <?php if ($step === 1 && !$summary): ?>
      <p>Upload your CSV or Excel file. <strong>Lead Source</strong> y <strong>Default Interest</strong> son opcionales; si no eliges Source usaremos el primer source activo.</p>
      <form id="importForm" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="detect_columns">
        <textarea id="csv_data" name="csv_data" style="display:none;"></textarea>

        <div class="form-group" style="margin-bottom:16px;">
          <label for="source_id">Lead Source (optional)</label>
          <select id="source_id" name="source_id" class="column-select">
            <option value="">— None —</option>
            <?php foreach ($sources as $s): ?>
              <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <small style="color:#6c757d;">Si lo dejas vacío, se usará el primer source activo (ID <?= (int)$fallbackSourceId ?>).</small>
        </div>

        <div class="form-group" style="margin-bottom:16px;">
          <label for="default_interest_id">Default Interest (optional)</label>
          <select id="default_interest_id" name="default_interest_id" class="column-select">
            <option value="">— None —</option>
            <?php foreach ($interestRows as $i): ?>
              <option value="<?= $i['id'] ?>"><?= htmlspecialchars($i['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <small style="color:#6c757d;">Si lo dejas vacío y el CSV no trae una columna "interest", el lead quedará sin interés asignado.</small>
        </div>

        <div class="form-group" style="margin-bottom:16px;">
          <label for="file">File (CSV, XLS, XLSX)</label>
          <input type="file" id="file" name="file" accept=".csv,.xls,.xlsx" class="column-select" required>
          <small style="color:#6c757d;">Supported formats: .csv, .xls, .xlsx</small>
        </div>

        <button type="submit" class="btn" style="background:#0d6efd;color:white;">Next: Map Columns</button>
        <a class="btn btn-secondary" href="list.php">Cancel</a>
      </form>
    <?php endif; ?>

    <?php if ($step === 2 && !empty($_SESSION['import_temp'])): ?>
      <?php $temp = $_SESSION['import_temp']; ?>
      <div class="preview-box">
        <h3>File: <?= htmlspecialchars($temp['filename']) ?></h3>
        <p><strong>Detected columns:</strong> <?= implode(', ', array_map('htmlspecialchars', $temp['header'])) ?></p>
      </div>

      <p>Map your file columns to our system fields. <strong>Required fields are marked with *</strong>.</p>

      <form method="post" class="mapping-container">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="process_import">

        <?php foreach ($systemFields as $field => $meta): ?>
        <div class="mapping-row <?= $meta['required'] ? 'required' : '' ?>">
          <div class="system-field <?= $meta['required'] ? 'required' : '' ?>">
            <?= htmlspecialchars($meta['label']) ?>
          </div>
          <div class="arrow">→</div>
          <div>
            <select name="map_<?= $field ?>" class="column-select" <?= $meta['required'] ? 'required' : '' ?>>
              <option value="">— Not mapped —</option>
              <?php foreach ($temp['header'] as $col): ?>
                <option value="<?= htmlspecialchars($col) ?>"
                  <?= (isset($temp['suggested_mapping'][$field]) && $temp['suggested_mapping'][$field] === $col) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($col) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <?php endforeach; ?>

        <div style="margin-top: 24px;">
          <button type="submit" class="btn" style="background:#198754;color:white;">Process Import</button>
          <a href="?cancel=1" class="btn btn-secondary">Cancel</a>
        </div>
      </form>
    <?php endif; ?>

    <p style="margin-top: 24px; text-align: center;">
      <a class="btn btn-secondary" href="list.php">
        <i class="fas fa-arrow-left"></i> Back to List
      </a>
    </p>
  </div>

</body>
</html>
