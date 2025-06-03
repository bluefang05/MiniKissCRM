<?php
// calls/add.php

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/LeadLock.php';
require_once __DIR__ . '/../lib/Interaction.php';

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

$user   = Auth::user();
$pdo    = getPDO();
$leadId = (int) ($_GET['lead_id'] ?? 0);

// Attempt to acquire lock for call (5 minutes)
if (!LeadLock::acquire($leadId, $user['id'], 5)) {
    $expires = htmlspecialchars(LeadLock::check($leadId)['expires_at']);
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <title>Lead Locked</title>
      <link rel="stylesheet" href="./../assets/css/app.css">
      <style>.container {max-width: 600px; margin: 2rem auto;}</style>
    </head>
    <body>
      <div class="container">
        <h1><i class="fas fa-lock"></i> Lead Locked</h1>
        <div class="alert">
          This lead is currently being used by another user until {$expires}.
        </div>
        <p><a class="btn" href="list.php"><i class="fas fa-arrow-left"></i> Back to Leads</a></p>
      </div>
    </body>
    </html>
    HTML;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Interaction::create([
        'lead_id'          => $_POST['lead_id'],
        'user_id'          => $user['id'],
        'disposition_id'   => $_POST['disposition_id'],
        'notes'            => $_POST['notes'] ?? null,
        'duration_seconds' => $_POST['duration_seconds'] ?? null,
    ]);

    // Release lock after saving the call
    LeadLock::release($leadId, $user['id']);

    header('Location: list.php');
    exit;
}

// Get lead's phone number
$stmt = $pdo->prepare("SELECT phone FROM leads WHERE id = ?");
$stmt->execute([$leadId]);
$leadData    = $stmt->fetch(PDO::FETCH_ASSOC);
$phoneNumber = $leadData['phone'] ?? '';

// Normalize to international format (+1...)
$cleaned = preg_replace('/\D+/', '', $phoneNumber);
if (strlen($cleaned) === 10) {
    $internationalNumber = '+1' . $cleaned;
} elseif (strlen($cleaned) === 11 && strpos($cleaned, '1') === 0) {
    $internationalNumber = '+' . $cleaned;
} else {
    $internationalNumber = '+' . $cleaned;
}

// Load dispositions
$dispositions = $pdo
    ->query("SELECT id, name FROM dispositions ORDER BY name")
    ->fetchAll(PDO::FETCH_ASSOC);

// Load previous interactions
$stmt = $pdo->prepare("
    SELECT
      i.created_at,
      i.notes,
      i.duration_seconds,
      d.name AS disposition,
      u.name AS user
    FROM interactions i
    JOIN dispositions d ON i.disposition_id = d.id
    JOIN users u        ON i.user_id        = u.id
    WHERE i.lead_id = :lead_id
    ORDER BY i.created_at DESC
");
$stmt->execute(['lead_id' => $leadId]);
$interactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Register Call &mdash; Lead #<?= htmlspecialchars($leadId) ?></title>
  <link rel="stylesheet" href="./../assets/css/app.css">
  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
  >

</head>
<body>
  <div class="container">
    <h1><i class="fas fa-phone"></i> Register Call for Lead #<?= htmlspecialchars($leadId) ?></h1>

    <?php if ($phoneNumber !== ''): ?>
      <div class="phone-actions">
        <div>
          <strong>Phone:</strong>
          <span id="phone-number"><?= htmlspecialchars($phoneNumber) ?></span>
        </div>
        <button
          type="button"
          class="btn"
          style="background-color:#4caf50;color:white;margin-right:10px;"
          onclick="copyToClipboard()"
        >
          <i class="fas fa-copy"></i> Copy Number
        </button>
        <a
          href="https://panel.mightycall.com/WebPhoneApp/#!/separate_view?callto=<?= rawurlencode($internationalNumber) ?>"
          target="_blank"
          class="btn"
          style="background-color:#2196f3;color:white;"
        >
          <i class="fas fa-phone"></i> MightyCall
        </a>
      </div>
    <?php endif; ?>

    <?php if ($interactions): ?>
      <div class="previous-calls">
        <h2><i class="fas fa-history"></i> Call History</h2>
        <ul class="call-history">
          <?php foreach ($interactions as $call): ?>
            <li>
              <div class="call-meta">
                <span><i class="fas fa-user"></i> <?= htmlspecialchars($call['user']) ?></span>
                <span><i class="fas fa-clock"></i> <?= htmlspecialchars($call['created_at']) ?></span>
              </div>
              <strong><?= htmlspecialchars($call['disposition']) ?></strong>
              <div style="margin-top:.5rem;">
                <?= nl2br(htmlspecialchars($call['notes'])) ?>
              </div>
              <?php if ($call['duration_seconds']): ?>
                <small>
                  <i class="fas fa-stopwatch"></i>
                  Duration:
                  <?= floor($call['duration_seconds']/60) ?>m
                  <?= $call['duration_seconds']%60 ?>s
                </small>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php else: ?>
      <div class="alert">No previous calls recorded.</div>
    <?php endif; ?>

    <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

      <input type="hidden" name="lead_id" value="<?= htmlspecialchars($leadId) ?>">
      <div class="form-group">
        <label for="disposition_id"><i class="fas fa-check-circle"></i> Call Outcome</label>
        <select id="disposition_id" name="disposition_id" class="form-control" required>
          <?php foreach ($dispositions as $d): ?>
            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="duration_seconds"><i class="fas fa-stopwatch"></i> Duration (seconds)</label>
        <input
          required
          type="number"
          id="duration_seconds"
          name="duration_seconds"
          class="form-control"
          placeholder="E.g.: 60"
        >
      </div>
      <div class="form-group">
        <label for="notes"><i class="fas fa-sticky-note"></i> Additional Notes</label>
        <textarea
          required
          id="notes"
          name="notes"
          rows="5"
          class="form-control"
          placeholder="Conversation details..."
        ></textarea>
      </div>
      <button type="submit" class="btn"><i class="fas fa-save"></i> Save Call</button>
    </form>

    <p>
      <a class="btn btn-secondary" href="list.php">
        <i class="fas fa-arrow-left"></i> « Back to Leads
      </a>
    </p>
  </div>

  <script>
  function copyToClipboard() {
    const el     = document.getElementById('phone-number');
    let text      = el.textContent.trim();
    // If it doesn’t start with +1, prepend it
    if (!text.startsWith('+1')) {
      text = '+1' + text;
    }

    navigator.clipboard.writeText(text)
      .then(() => {
        el.classList.add('copied');
        setTimeout(() => el.classList.remove('copied'), 1000);
      })
      .catch(() => {
        el.classList.add('copy-error');
        setTimeout(() => el.classList.remove('copy-error'), 1000);
      });
  }
</script>

</body>
</html>