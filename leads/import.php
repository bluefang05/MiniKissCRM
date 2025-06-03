<?php

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Lead.php';
require_once __DIR__ . '/../lib/AuditLog.php';
require_once __DIR__ . '/../lib/db.php';


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

        // 3) Validate CSV upload
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Error uploading CSV file.');
        }
        $tmpPath = $_FILES['file']['tmp_name'];
        if (!is_uploaded_file($tmpPath)) {
            throw new Exception('Temporary upload file is missing.');
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

        // 5) Normalize headers
        $normalized = [];
        foreach ($header as $h) {
            $col = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($h)));
            // adjust only if your CSV headers differ; otherwise skip
            if ($col === 'address')         $col = 'address_line';
            if ($col === 'suite_apt')       $col = 'suite_apt';
            if ($col === 'delivery_po')     $col = 'delivery_point_bar_code';
            if ($col === 'carrier_rou')     $col = 'carrier_route';
            if ($col === 'fips_county_cod') $col = 'fips_county_code';
            if ($col === 'county_nam')      $col = 'county_name';
            $normalized[] = $col;
        }
        $map = array_flip($normalized);

        // 6) Require minimal columns
        foreach (['first_name', 'last_name', 'phone'] as $c) {
            if (!isset($map[$c])) {
                throw new Exception("Missing required CSV column: $c");
            }
        }

        $hasCsvInterest = isset($map['interest']);
        $hasCsvLanguage = isset($map['language']);
        $hasCsvIncome   = isset($map['income']);
        $hasExtId       = isset($map['external_id']);

        // 7) Prepare upsert
        $existsStmt = $pdo->prepare('SELECT id FROM leads WHERE external_id = ? LIMIT 1');
        $created    = $updated = $skipped = 0;
        $pdo->beginTransaction();
        $generatedIds = [];

        // 8) Process each row
        while (($row = fgetcsv($fh)) !== false) {
            // Skip empty rows
            if (!$row || count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
                continue;
            }

            // Build data array from CSV
            $data = [];
            foreach ($map as $col => $i) {
                $data[$col] = $row[$i] ?? null;
            }

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

            // Required lead fields
            $data['source_id']   = $sourceId;
            $data['status_id']   = 1;   // New
            $data['do_not_call'] = 0;

            // Whitelist columns
            $allowed = [
                'external_id', 'prefix', 'first_name', 'mi', 'last_name',
                'phone', 'email', 'address_line', 'suite_apt', 'city',
                'state', 'zip5', 'zip4', 'delivery_point_bar_code',
                'carrier_route', 'fips_county_code', 'county_name',
                'age', 'insurance_interest_id',
                'language', 'income',
                'source_id', 'status_id', 'do_not_call'
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
        $pdo->commit();

        // 9) Audit
        AuditLog::log(
            $user['id'],
            'import_leads',
            sprintf(
                'file=%s src=%d default_interest=%d created=%d updated=%d skipped=%d',
                $_FILES['file']['name'],
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
  <link rel="stylesheet" href="./../assets/css/app.css">
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

    <form method="post" enctype="multipart/form-data" class="mb-4">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

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
        <label for="file">CSV File</label>
        <input type="file" id="file" name="file" accept=".csv" class="form-control" required>
      </div>

      <button type="submit" class="btn">Import Leads</button>
    </form>

    <p><a class="btn btn-secondary" href="list.php">« Back to List</a></p>
  </div>

</body>
</html>
