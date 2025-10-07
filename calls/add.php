<?php
// /calls/add.php

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/LeadLock.php';
require_once __DIR__ . '/../lib/Interaction.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $pageTitle = "Access Denied";
        $pageHeading = "Access Denied";
        $errorMessage = "Invalid CSRF token.";
    }
}

if (!Auth::check()) {
    header('Location: /auth/login.php');
    exit;
}

$user = Auth::user();
$pdo = getPDO();

$leadId = (int) ($_GET['lead_id'] ?? 0);

// Attempt to acquire lock for call (5 minutes)
if (!LeadLock::acquire($leadId, $user['id'], 5)) {
    $lockInfo = LeadLock::check($leadId);
    $expires = htmlspecialchars($lockInfo['expires_at']);
    $pageTitle = "Lead Locked";
    $pageHeading = "Lead Locked";
    $lockedView = true;
} else {
    $lockedView = false;

    // Load lead phone number
    $stmt = $pdo->prepare("SELECT phone FROM leads WHERE id = ?");
    $stmt->execute([$leadId]);
    $leadData = $stmt->fetch(PDO::FETCH_ASSOC);
    $phoneNumber = $leadData['phone'] ?? '';

    // Normalize phone number
    $cleaned = preg_replace('/\D+/', '', $phoneNumber);
    if (strlen($cleaned) === 10) {
        $internationalNumber = '+1' . $cleaned;
    } elseif (strlen($cleaned) === 11 && strpos($cleaned, '1') === 0) {
        $internationalNumber = '+' . $cleaned;
    } else {
        $internationalNumber = $phoneNumber;
    }

    // Load dispositions
    $stmt = $pdo->query("SELECT id, name FROM dispositions ORDER BY name");
    $dispositions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$dispositions) {
        $pageTitle = "Error";
        $pageHeading = "Error";
        $errorMessage = "No disposition types found.";
        $lockedView = true; // Show error inside main layout
    }

    // Load previous interactions
    $stmt = $pdo->prepare("
        SELECT 
            i.created_at,
            u.name AS user_name,
            d.name AS disposition,
            i.notes,
            i.duration_seconds
        FROM interactions i
        JOIN users u ON i.user_id = u.id
        JOIN dispositions d ON i.disposition_id = d.id
        WHERE i.lead_id = ?
        ORDER BY i.created_at DESC
    ");
    $stmt->execute([$leadId]);
    $interactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle form submission
    $successMessage = '';
    $formErrorMessage = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$lockedView) {
        $dispositionId = (int) ($_POST['disposition_id'] ?? 0);
        $duration = (int) ($_POST['duration_seconds'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $doNotCall = !empty($_POST['do_not_call']) ? 1 : 0;

        if ($dispositionId > 0 && $duration > 0) {
            Interaction::create([
                'lead_id' => $leadId,
                'user_id' => $user['id'],
                'disposition_id' => $dispositionId,
                'notes' => $notes,
                'duration_seconds' => $duration,
            ]);

            if ($doNotCall) {
                $pdo->prepare("UPDATE leads SET do_not_call = 1 WHERE id = ?")->execute([$leadId]);
            }

            LeadLock::release($leadId, $user['id']);
            header('Location: list.php?status=call_saved');
            exit;
        } else {
            $formErrorMessage = 'Please fill all required fields.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= $pageTitle ?? 'Register Call' ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"> 
  <link rel="stylesheet" href="../assets/css/calls/add.css">
</head>
<body>

<div class="container">

  <h1><i class="fas fa-phone"></i> <?= $pageHeading ?? "Register Call - Lead #$leadId" ?></h1>

  <?php if (!$lockedView): ?>
    <a href="../leads/list.php" class="back-button"><i class="fas fa-arrow-left"></i> Back to Leads</a>
  <?php endif; ?>

  <!-- Lock Message -->
  <?php if (isset($errorMessage)): ?>
    <div class="alert alert-error"><?= $errorMessage ?></div>
  <?php elseif (isset($expires) && $lockedView): ?>
    <div class="alert">This lead is currently being used by another user until <strong><?= $expires ?></strong>.</div>
  <?php elseif (isset($lockedView) && $lockedView && isset($formErrorMessage)): ?>
    <div class="alert alert-error"><?= $formErrorMessage ?></div>
  <?php endif; ?>

  <!-- If not locked, show full form -->
  <?php if (!$lockedView): ?>
    <!-- Phone Actions -->
    <?php if ($phoneNumber): ?>
      <div class="phone-actions">
        <div class="phone-number-container">
          <strong>Phone:</strong>
          <span id="phone-number"><?= htmlspecialchars($internationalNumber) ?></span>
        </div>
        <button type="button" class="call-button" onclick="copyPhoneNumber()">
          <i class="fas fa-copy"></i> Copy Number
        </button>
        <a href="https://panel.mightycall.com/WebPhoneApp/#!/separate_view?callto=<?= rawurlencode($internationalNumber) ?>" target="_blank" class="call-button"> 
          <i class="fas fa-phone"></i> Call with MightyCall
        </a>
      </div>
    <?php endif; ?>

    <!-- Call History -->
    <?php if ($interactions): ?>
      <div class="previous-calls">
        <h2><i class="fas fa-history"></i> Call History</h2>
        <ul class="interaction-list">
          <?php foreach ($interactions as $call): ?>
            <li class="interaction-item">
              <div class="interaction-meta">
                <span><?= htmlspecialchars($call['user_name']) ?></span>
                <span><?= htmlspecialchars($call['created_at']) ?></span>
              </div>
              <span class="call-status <?= strtolower(str_replace(' ', '-', $call['disposition'])) ?>">
                <?= htmlspecialchars($call['disposition']) ?>
              </span>
              <small>
                <?= nl2br(htmlspecialchars(mb_strimwidth($call['notes'], 0, 100, '…'))) ?>
              </small>
              <div class="interaction-duration">
                <i class="fas fa-stopwatch"></i> Duration: <?= floor($call['duration_seconds'] / 60) ?>m<?= $call['duration_seconds'] % 60 ?>s
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php else: ?>
      <div class="alert">No previous calls recorded.</div>
    <?php endif; ?>

    <!-- Call Form -->
    <form method="post" action="" class="call-form">
      <input type="hidden" name="lead_id" value="<?= $leadId ?>">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

      <div class="form-group">
        <label for="disposition_id"><i class="fas fa-check-circle"></i> Call Outcome</label>
        <select id="disposition_id" name="disposition_id" class="form-control" required>
          <option value="">Select Disposition</option>
          <?php foreach ($dispositions as $d): ?>
            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="note_template">Quick Notes</label>
        <select id="note_template" class="form-control" onchange="applyNoteTemplate()">
          <option value="">-- Select Quick Note --</option>
          <optgroup label="Health Insurance">
            <option value="Interested in quote sent.">Quote Sent</option>
            <option value="Has coverage already.">Already Covered</option>
          </optgroup>
          <optgroup label="Life Insurance">
            <option value="Expressed interest in life insurance.">Interested in Life</option>
            <option value="Do not call again requested.">Do Not Call</option>
          </optgroup>
          <optgroup label="General">
            <option value="Left voicemail explaining the benefits.">Leave Voicemail</option>
            <option value="Follow-up scheduled for tomorrow.">Schedule Follow-Up</option>
          </optgroup>
        </select>
      </div>

      <div class="form-group">
        <label for="duration_seconds"><i class="fas fa-stopwatch"></i> Duration (seconds)</label>
        <input type="number" id="duration_seconds" name="duration_seconds" class="form-control"
               placeholder="E.g.: 60" required>
      </div>

      <div class="form-group">
        <label for="notes"><i class="fas fa-sticky-note"></i> Additional Notes</label>
        <textarea id="notes" name="notes" rows="5" class="form-control"
                  placeholder="Call summary..."></textarea>
      </div>

      <div class="checkbox-group">
        <input type="checkbox" id="do_not_call" name="do_not_call" value="1">
        <label for="do_not_call">Mark this lead as Do Not Call</label>
      </div>

      <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save Call</button>
    </form>

  <?php endif; ?>

</div>

<script>
function copyPhoneNumber() {
  const el = document.getElementById('phone-number');
  let text = el.textContent.trim();
  if (!text.startsWith('+')) {
    text = '+1' + text.replace(/\D+/g, '');
  }

  navigator.clipboard.writeText(text).then(() => {
    el.classList.add('copied');
    setTimeout(() => el.classList.remove('copied'), 1000);
  }).catch(() => {
    el.classList.add('copy-error');
    setTimeout(() => el.classList.remove('copy-error'), 1000);
  });
}

function applyNoteTemplate() {
  const select = document.getElementById('note_template');
  const textarea = document.getElementById('notes');
  const selectedText = select.value;

  if (selectedText) {
    textarea.value += (textarea.value ? '\n\n' : '') + selectedText;
  }
}
</script>

</body>
</html>