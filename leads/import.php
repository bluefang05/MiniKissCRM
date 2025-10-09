<?php

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

$error   = '';
$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 1) Validate lead source
        if (empty($_POST['source_id'])) {
            throw new Exception('You must select a lead source.');
        }
        $sourceId = (int) $_POST['source_id'];

        // 2) Validate default interest
        if (empty($_POST['default_interest_id'])) {
            throw new Exception('You must select a default interest.');
        }
        $defaultInterestId = (int) $_POST['default_interest_id'];

        // 3) Handle file upload (CSV or XLSX via JS)
        if (!empty($_POST['csv_data'])) {
            $csvData = $_POST['csv_data'];
            $tmpPath = tempnam(sys_get_temp_dir(), 'xlsx_');
            file_put_contents($tmpPath, $csvData);
        } else {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Error uploading file.');
            }
            $tmpPath = $_FILES['file']['tmp_name'];
            if (!is_uploaded_file($tmpPath)) {
                throw new Exception('Temporary upload file is missing.');
            }
        }

        // 4) Open CSV and read header
        $fh = fopen($tmpPath, 'r');
        if (!$fh) {
            throw new Exception('Cannot open CSV file.');
        }
        $firstLine = fgets($fh);
        // Remove BOM if present
        if (strpos($firstLine, "\xEF\xBB\xBF") === 0) {
            $firstLine = substr($firstLine, 3);
        }
        $header = str_getcsv(rtrim($firstLine, "\r\n"));
        if (!$header) {
            throw new Exception('CSV has no header row.');
        }

        // ====== 5) SMART COLUMN MAPPING WITH ALIASES ======
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

        // Normalize header
        $normalizedHeader = array_map(fn($h) => strtolower(trim($h)), $header);
        $colIndex = array_flip($normalizedHeader);

        // Build map: system field → CSV column name
        $mapByField = [];
        foreach ($fieldAliases as $systemField => $aliases) {
            foreach ($aliases as $alias) {
                if (in_array($alias, $normalizedHeader)) {
                    $mapByField[$systemField] = $alias;
                    break;
                }
            }
        }

        // Require minimal columns
        $requiredFields = ['first_name', 'last_name', 'phone'];
        foreach ($requiredFields as $field) {
            if (!isset($mapByField[$field])) {
                throw new Exception("Missing required column for: $field. Detected columns: " . implode(', ', $normalizedHeader));
            }
        }

        $hasCsvInterest = isset($mapByField['interest']);
        $hasCsvLanguage = isset($mapByField['language']);
        $hasCsvIncome   = isset($mapByField['income']);
        $hasExtId       = isset($mapByField['external_id']);

        // 6) Prepare upsert
        $existsStmt = $pdo->prepare('SELECT id FROM leads WHERE external_id = ? LIMIT 1');
        $created    = $updated = $skipped = 0;
        $pdo->beginTransaction();
        $generatedIds = [];

        // 7) Process each row
        while (($row = fgetcsv($fh)) !== false) {
            if (!$row || count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
                continue;
            }

            // Helper to get value by system field name
            $get = function(string $field) use ($mapByField, $colIndex, $row) {
                $csvCol = $mapByField[$field] ?? null;
                if ($csvCol === null) return null;
                $i = $colIndex[$csvCol] ?? null;
                return ($i === null) ? null : ($row[$i] ?? null);
            };

            $data = [
                'prefix'      => $get('prefix'),
                'first_name'  => $get('first_name'),
                'mi'          => $get('mi'),
                'last_name'   => $get('last_name'),
                'phone'       => $get('phone'),
                'email'       => $get('email'),
                'address_line'=> $get('address_line'),
                'suite_apt'   => $get('suite_apt'),
                'city'        => $get('city'),
                'state'       => $get('state'),
                'zip5'        => $get('zip5'),
                'zip4'        => $get('zip4'),
                'delivery_point_bar_code' => $get('delivery_point_bar_code'),
                'carrier_route'=> $get('carrier_route'),
                'fips_county_code'=> $get('fips_county_code'),
                'county_name' => $get('county_name'),
                'age'         => $get('age'),
                'language'    => $get('language'),
                'income'      => $get('income'),
                'external_id' => $get('external_id'),
                'interest'    => $get('interest'),
            ];

            // Clean phone
            if (!empty($data['phone'])) {
                $data['phone'] = preg_replace('/\D+/', '', $data['phone']);
            }

            // Map interest
            if ($hasCsvInterest && !empty($data['interest'])) {
                $key = strtolower(trim($data['interest']));
                $data['insurance_interest_id'] = $interestMap[$key] ?? $defaultInterestId;
            } else {
                $data['insurance_interest_id'] = $defaultInterestId;
            }

            // Map language
            $data['language'] = $hasCsvLanguage
                ? trim((string) $data['language'])
                : null;

            // Map income
            $data['income'] = ($hasCsvIncome && !empty($data['income']))
                ? strtoupper(substr(trim($data['income']), 0, 1))
                : null;

            // Generate external_id if missing
            $ext = $hasExtId ? trim((string)$data['external_id']) : '';
            if ($ext === '') {
                do {
                    $ext = uniqid('lead_', true);
                } while (isset($generatedIds[$ext]));
                $generatedIds[$ext] = true;
            }
            $data['external_id'] = $ext;

            // Required fixed fields
            $data['source_id']   = $sourceId;
            $data['do_not_call'] = 0;
            $data['uploaded_by'] = $user['id'];

            // Whitelist columns
            $allowed = [
                'external_id','prefix','first_name','mi','last_name',
                'phone','email','address_line','suite_apt','city',
                'state','zip5','zip4','delivery_point_bar_code',
                'carrier_route','fips_county_code','county_name',
                'age','insurance_interest_id',
                'language','income',
                'source_id','do_not_call',
                'uploaded_by'
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
        if (!empty($_POST['csv_data'])) {
            unlink($tmpPath);
        }
        $pdo->commit();

        // 8) Audit
        AuditLog::log(
            $user['id'],
            'import_leads',
            sprintf(
                'file=%s src=%d default_interest=%d created=%d updated=%d skipped=%d',
                !empty($_POST['csv_data']) ? 'excel_import' : $_FILES['file']['name'],
                $sourceId,
                $defaultInterestId,
                $created,
                $updated,
                $skipped
            )
        );

        $summary = compact('created', 'updated', 'skipped');

    } catch (Exception $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $ex->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Import Leads</title>
  <link rel="stylesheet" href="./../assets/css/leads/import.css">
  <!-- SheetJS -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
  <!-- Spreadsheet Importer -->
  <script src="../assets/js/spreadsheet-importer.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      SpreadsheetImporter.init({
        formId: 'importForm',
        inputId: 'file',
        textareaId: 'csv_data'
      });
    });
  </script>
</head>
<body>

  <div class="container">
    <h1>Import Leads</h1>

    <?php if ($error): ?>
      <div class="error summary">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($summary): ?>
      <div class="summary">
        <strong>Import summary:</strong><br>
        New: <?= $summary['created'] ?><br>
        Updated: <?= $summary['updated'] ?><br>
        Skipped: <?= $summary['skipped'] ?>
      </div>
    <?php endif; ?>

    <form id="importForm" method="post" enctype="multipart/form-data" class="mb-4">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <textarea id="csv_data" name="csv_data" style="display:none;"></textarea>

      <div class="form-group">
        <label for="source_id">Lead Source</label>
        <select id="source_id" name="source_id" class="form-control" required>
          <option value="">— select —</option>
          <?php foreach ($sources as $s): ?>
            <option value="<?= $s['id'] ?>"
                <?= (isset($_POST['source_id']) && $_POST['source_id'] == $s['id']) ? ' selected' : '' ?>>
              <?= htmlspecialchars($s['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="default_interest_id">Default Interest</label>
        <select id="default_interest_id" name="default_interest_id" class="form-control" required>
          <option value="">— select —</option>
          <?php foreach ($interestRows as $i): ?>
            <option value="<?= $i['id'] ?>"
                <?= (isset($_POST['default_interest_id']) && $_POST['default_interest_id'] == $i['id']) ? ' selected' : '' ?>>
              <?= htmlspecialchars($i['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="file">File</label>
        <input type="file" id="file" name="file" accept=".csv,.xls,.xlsx" class="form-control" required>
        <small class="text-muted">Supported formats: .csv, .xls, .xlsx</small>
      </div>

      <button type="submit" class="btn">Import Leads</button>
    </form>

    <p><a class="btn btn-secondary" href="list.php">« Back to List</a></p>
  </div>

</body>
</html>