<?php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Interaction.php';
require_once __DIR__ . '/../lib/LeadLock.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$leadId = (int)($_GET['id'] ?? 0);
$pdo = getPDO();

// Load lead details without status
$stmt = $pdo->prepare("
    SELECT
      l.*,
      so.name   AS source,
      ii.name   AS interest,
      ir.description AS income_desc,
      lc.description AS language_desc
    FROM leads l
    LEFT JOIN lead_sources        so ON l.source_id              = so.id
    LEFT JOIN insurance_interests ii ON l.insurance_interest_id  = ii.id
    LEFT JOIN income_ranges       ir ON l.income                 = ir.code
    LEFT JOIN language_codes      lc ON l.language               = lc.code
    WHERE l.id = ?
");
$stmt->execute([$leadId]);
$lead = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lead) {
    echo "<h1>Lead not found</h1>";
    exit;
}

$calls = Interaction::forLead($leadId);
$lock = LeadLock::check($leadId);
$isLockedByOther = $lock && $lock['user_id'] !== $user['id'];

// Load documents
$stmt = $pdo->prepare("SELECT * FROM lead_documents WHERE lead_id = ?");
$stmt->execute([$leadId]);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Lead #<?= $leadId ?> – Details</title>
  <link rel="stylesheet" href="./../assets/css/leads/view.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css ">
  <style>
    /* Custom styles */
    .section-title { 
      margin: 2rem 0 1rem; 
      font-size: 1.4rem; 
      border-bottom: 1px solid #ccc; 
      padding-bottom: .4rem; 
    }
    .alert-warning { 
      background-color: #fff3cd; 
      border-left: 5px solid #ffc107; 
      padding: 1rem; 
      border-radius: 6px; 
      margin-bottom: 2rem; 
    }
    .actions { 
      margin-top: 2rem; 
    }
    .actions .btn { 
      margin-right: .5rem; 
    }
    .flex-wrap {
      display: flex;
      flex-wrap: wrap;
      gap: 1rem;
    }
    .section {
      flex: 1 1 300px;
      background: #f9f9f9;
      border-radius: 8px;
      padding: 1rem;
      border-left: 4px solid #2c5d4a;
    }
    .documents {
      margin-top: 2rem;
      background: #f9f9f9;
      border-radius: 8px;
      padding: 1rem;
    }
    .documents h3 {
      margin-top: 0;
    }
    .document-item:hover {
      background-color: #f1f1f1;
    }
    .document-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0.5rem 0;
      border-bottom: 1px solid #eee;
    }
    .document-item:last-child {
      border-bottom: none;
    }
    .document-icon {
      font-size: 1.2rem;
      margin-right: 0.5rem;
      color: #dc3545;
    }
    .document-name {
      font-weight: 500;
    }
    .document-meta {
      font-size: 0.85rem;
      color: #666;
    }
    .download-btn {
      background-color: #e9ecef;
      border: none;
      padding: 0.4rem 0.8rem;
      border-radius: 5px;
      cursor: pointer;
    }
    .download-btn:hover {
      background-color: #ced4da;
    }
  </style>
</head>
<body>

<div class="container">
  <h1>📋 Lead #<?= $leadId ?> Overview</h1>

  <?php if ($isLockedByOther): ?>
    <div class="alert-warning">
      <i class="fas fa-lock"></i> This lead is currently locked by user <strong><?= htmlspecialchars($lock['user_id']) ?></strong>
      until <strong><?= htmlspecialchars($lock['expires_at']) ?></strong>.
    </div>
  <?php endif; ?>

  <div class="flex-wrap">
    <div class="section">
      <h3><i class="fas fa-user"></i> Personal Info</h3>
      <dl>
        <dt>FULL NAME:</dt>
        <dd><?= htmlspecialchars(trim("{$lead['first_name']} {$lead['last_name']}")) ?></dd>
        <dt>PHONE:</dt>
        <dd><?= htmlspecialchars($lead['phone']) ?></dd>
        <dt>EMAIL:</dt>
        <dd><?= htmlspecialchars($lead['email']) ?></dd>
        <dt>AGE:</dt>
        <dd><?= htmlspecialchars($lead['age']) ?></dd>
        <dt>INCOME:</dt>
        <dd><?= htmlspecialchars($lead['income_desc'] ?? '') ?></dd>
        <dt>LANGUAGE:</dt>
        <dd><?= htmlspecialchars($lead['language_desc'] ?? '') ?></dd>
        <dt>INTEREST:</dt>
        <dd><?= htmlspecialchars($lead['interest']) ?></dd>
        <dt>DO NOT CALL:</dt>
        <dd><?= $lead['do_not_call'] ? 'Yes' : 'No' ?></dd>
      </dl>
    </div>

    <div class="section">
      <h3><i class="fas fa-map-marker-alt"></i> Address</h3>
      <dl>
        <dt>SOURCE:</dt>
        <dd><?= htmlspecialchars($lead['source']) ?></dd>
        <dt>ADDRESS:</dt>
        <dd><?= htmlspecialchars($lead['address_line']) ?></dd>
        <dt>SUITE / APT:</dt>
        <dd><?= htmlspecialchars($lead['suite_apt']) ?></dd>
        <dt>CITY:</dt>
        <dd><?= htmlspecialchars($lead['city']) ?></dd>
        <dt>STATE:</dt>
        <dd><?= htmlspecialchars($lead['state']) ?></dd>
        <dt>ZIP5 / ZIP4:</dt>
        <dd><?= htmlspecialchars($lead['zip5']) ?> / <?= htmlspecialchars($lead['zip4']) ?></dd>
      </dl>
    </div>

    <div class="section">
      <h3><i class="fas fa-box"></i> Additional Info</h3>
      <dl>
        <dt>EXTERNAL ID:</dt>
        <dd><?= htmlspecialchars($lead['external_id']) ?></dd>
        <dt>CREATED AT:</dt>
        <dd><?= htmlspecialchars($lead['created_at']) ?></dd>
        <dt>UPDATED AT:</dt>
        <dd><?= htmlspecialchars($lead['updated_at']) ?></dd>
        <dt>DELIVERY BAR CODE:</dt>
        <dd><?= htmlspecialchars($lead['delivery_point_bar_code']) ?></dd>
        <dt>CARRIER ROUTE:</dt>
        <dd><?= htmlspecialchars($lead['carrier_route']) ?></dd>
        <dt>FIPS CODE / COUNTY:</dt>
        <dd><?= htmlspecialchars($lead['fips_county_code']) ?> – <?= htmlspecialchars($lead['county_name']) ?></dd>
      </dl>
    </div>
  </div>

  <h2 class="section-title">📞 Call History</h2>
  <table class="table">
    <thead>
      <tr>
        <th>Date</th>
        <th>Result</th>
        <th>Notes</th>
        <th>Duration (s)</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($calls)): ?>
        <tr><td colspan="4" style="text-align: center;">No call history available.</td></tr>
      <?php else: ?>
        <?php foreach ($calls as $c): ?>
          <tr>
            <td><?= htmlspecialchars($c['interaction_time']) ?></td>
            <td><?= htmlspecialchars($c['disposition']) ?></td>
            <td><?= nl2br(htmlspecialchars(mb_strimwidth($c['notes'], 0, 60, '…'))) ?></td>
            <td><?= htmlspecialchars($c['duration_seconds']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="actions">
    <a class="btn btn-secondary" href="edit.php?lead_id=<?= $leadId ?>"><i class="fas fa-edit"></i> Edit Lead</a>
    <?php if ($isLockedByOther): ?>
      <span class="btn disabled"><i class="fas fa-lock"></i> Log Call</span>
    <?php else: ?>
      <a class="btn" href="../calls/add.php?lead_id=<?= $leadId ?>"><i class="fas fa-phone"></i> Log Call</a>
    <?php endif; ?>
    <a class="btn btn-secondary" href="list.php"><i class="fas fa-arrow-left"></i> Back to List</a>
  </div>
</div>

<!-- Documents Section -->
<div class="documents mt-4">
  <h3><i class="fas fa-paperclip"></i> Attached Documents</h3>
  
  <?php if (empty($docs)): ?>
    <p class="text-muted">No documents attached yet.</p>
  <?php else: ?>
    <ul class="list-unstyled">
      <?php foreach ($docs as $doc): ?>
        <?php
          $diskName = basename($doc['file_path']);
          $url = './../uploads/lead_documents/' . $diskName;
        ?>
        <li class="document-item">
          <div>
            <i class="fas fa-file-pdf document-icon"></i>
            <span class="document-name">
              <a href="<?= htmlspecialchars($url) ?>" target="_blank"><?= htmlspecialchars($doc['title']) ?></a>
            </span>
            <div class="document-meta">
              <?= htmlspecialchars($doc['file_type']) ?> - <?= date('M j, Y', strtotime($doc['uploaded_at'])) ?>
            </div>
          </div>
          <a href="<?= htmlspecialchars($url) ?>" download class="download-btn">
            <i class="fas fa-download"></i>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap @5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>