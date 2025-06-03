<?php
// /leads/add.php
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

$user      = Auth::user();
$pdo       = getPDO();

$allowedRoles = ['admin', 'lead_manager'];
if (!Auth::check() || !array_intersect($allowedRoles, Auth::user()['roles'] ?? [])) {
    die('<div class="container"><h1>Acceso denegado</h1></div>');
}

// Cargar opciones del formulario
$sources   = $pdo->query("SELECT id, name FROM lead_sources WHERE active=1 ORDER BY name")->fetchAll();
$statuses  = $pdo->query("SELECT id, name FROM lead_statuses ORDER BY name")->fetchAll();
$interests = $pdo->query("SELECT id, name FROM insurance_interests WHERE active=1 ORDER BY name")->fetchAll();
$languages = $pdo->query("SELECT code, description FROM language_codes ORDER BY description")->fetchAll();
$incomes   = $pdo->query("SELECT code, description FROM income_ranges ORDER BY description")->fetchAll();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validar campos obligatorios
        $requiredFields = ['first_name', 'last_name', 'phone', 'insurance_interest_id', 'source_id', 'status_id'];
        foreach ($requiredFields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("The field '{$field}' is required.");
            }
        }

        // Preparar datos
        $data = [
            'external_id'           => trim($_POST['external_id']) ?: uniqid('lead_', true),
            'prefix'                => trim($_POST['prefix']) ?: null,
            'first_name'            => trim($_POST['first_name']),
            'mi'                    => trim($_POST['mi']) ?: null,
            'last_name'             => trim($_POST['last_name']),
            'phone'                 => preg_replace('/\D+/', '', $_POST['phone']),
            'email'                 => trim($_POST['email']) ?: null,
            'address_line'          => trim($_POST['address_line']) ?: null,
            'suite_apt'             => trim($_POST['suite_apt']) ?: null,
            'city'                  => trim($_POST['city']) ?: null,
            'state'                 => trim($_POST['state']) ?: null,
            'zip5'                  => trim($_POST['zip5']) ?: null,
            'zip4'                  => trim($_POST['zip4']) ?: null,
            'language'              => $_POST['language'] ?: null,
            'income'                => $_POST['income'] ?: null,
            'insurance_interest_id' => (int)$_POST['insurance_interest_id'],
            'source_id'             => (int)$_POST['source_id'],
            'status_id'             => (int)$_POST['status_id'],
            'do_not_call'           => !empty($_POST['do_not_call']) ? 1 : 0,
            'taken_by'              => null,
            'taken_at'              => null,
        ];

        // Crear Lead
        $newId = Lead::create($data);

        // Audit log
        AuditLog::log($user['id'], 'create_lead', "id={$newId}");

        // Redirigir a la vista del lead nuevo
        header("Location: view.php?id={$newId}");
        exit;

    } catch (Throwable $e) {
        $error = "Error saving the lead: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Add New Lead</title>
  <link rel="stylesheet" href="./../assets/css/app.css">
  <style>
    body {
      font-family: 'Segoe UI', sans-serif;
      background-color: #f5f7fa;
    }
    .container {
      max-width: 800px;
      margin: 40px auto;
      padding: 30px;
      background-color: #fff;
      border-radius: 10px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    h1, h2 {
      color: #2c3e50;
      margin-bottom: 15px;
    }
    .form-group {
      margin-bottom: 20px;
    }
    label {
      display: block;
      font-weight: bold;
      margin-bottom: 6px;
    }
    input[type="text"],
    input[type="email"],
    input[type="tel"],
    select,
    textarea {
      width: 100%;
      padding: 10px;
      border: 1px solid #ccc;
      border-radius: 6px;
      font-size: 1rem;
    }
    textarea {
      resize: vertical;
      min-height: 100px;
    }
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
    }
    .actions {
      margin-top: 20px;
    }
    button.btn {
      background-color: #007bff;
      color: white;
      padding: 10px 20px;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-size: 1rem;
    }
    a.btn-secondary {
      display: inline-block;
      margin-left: 10px;
      background-color: #6c757d;
      color: white;
      padding: 10px 20px;
      text-decoration: none;
      border-radius: 6px;
    }
    .error {
      background-color: #ffe6e6;
      color: #d90000;
      padding: 10px;
      border-left: 4px solid #d90000;
      margin-bottom: 20px;
    }
  </style>
</head>
<body>

<div class="container">
  <h1>Add New Lead</h1>

  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

    <!-- Personal Info -->
    <h2>Personal Information</h2>
    <div class="grid">
      <div class="form-group">
        <label for="prefix">Prefix</label>
        <input type="text" id="prefix" name="prefix" value="<?= htmlspecialchars($_POST['prefix'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="first_name">First Name *</label>
        <input type="text" id="first_name" name="first_name" required value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="mi">Middle Initial</label>
        <input type="text" id="mi" name="mi" value="<?= htmlspecialchars($_POST['mi'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="last_name">Last Name *</label>
        <input type="text" id="last_name" name="last_name" required value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
      </div>
    </div>

    <!-- Contact Info -->
    <h2>Contact Information</h2>
    <div class="grid">
      <div class="form-group">
        <label for="phone">Phone *</label>
        <input type="tel" id="phone" name="phone" required value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
    </div>

    <!-- Address -->
    <h2>Address</h2>
    <div class="grid">
      <div class="form-group">
        <label for="address_line">Street Address</label>
        <input type="text" id="address_line" name="address_line" value="<?= htmlspecialchars($_POST['address_line'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="suite_apt">Suite / Apt</label>
        <input type="text" id="suite_apt" name="suite_apt" value="<?= htmlspecialchars($_POST['suite_apt'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="zip5">ZIP Code</label>
        <input type="text" id="zip5" name="zip5" value="<?= htmlspecialchars($_POST['zip5'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="zip4">ZIP+4</label>
        <input type="text" id="zip4" name="zip4" value="<?= htmlspecialchars($_POST['zip4'] ?? '') ?>">
      </div>
    </div>

    <!-- Additional Info -->
    <h2>Additional Details</h2>
    <div class="grid">
      <div class="form-group">
        <label for="source_id">Source *</label>
        <select id="source_id" name="source_id" required>
          <option value="">Select Source</option>
          <?php foreach ($sources as $source): ?>
            <option value="<?= $source['id'] ?>" <?= (isset($_POST['source_id']) && $_POST['source_id'] == $source['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($source['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="status_id">Status *</label>
        <select id="status_id" name="status_id" required>
          <option value="">Select Status</option>
          <?php foreach ($statuses as $status): ?>
            <option value="<?= $status['id'] ?>" <?= (isset($_POST['status_id']) && $_POST['status_id'] == $status['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($status['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="insurance_interest_id">Insurance Interest *</label>
        <select id="insurance_interest_id" name="insurance_interest_id" required>
          <option value="">Select Interest</option>
          <?php foreach ($interests as $interest): ?>
            <option value="<?= $interest['id'] ?>" <?= (isset($_POST['insurance_interest_id']) && $_POST['insurance_interest_id'] == $interest['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($interest['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="external_id">External ID</label>
        <input type="text" id="external_id" name="external_id" value="<?= htmlspecialchars($_POST['external_id'] ?? '') ?>">
      </div>
    </div>

    <!-- Do Not Call -->
    <div class="grid">
      <div class="form-group">
        <label>
          <input type="checkbox" name="do_not_call" value="1" <?= !empty($_POST['do_not_call']) ? 'checked' : '' ?>>
          Do Not Call
        </label>
      </div>
    </div>

    <!-- Acciones -->
    <div class="actions">
      <button type="submit" class="btn">Save Lead</button>
      <a href="list.php" class="btn-secondary">Back to List</a>
    </div>
  </div>

</body>
</html>
