<?php
// calls/add.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/LeadLock.php';
require_once __DIR__ . '/../lib/Interaction.php';

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
    header('Location: /auth/login.php');
    exit;
}

$user = Auth::user();
$pdo = getPDO();
$leadId = (int) ($_GET['lead_id'] ?? 0);

// Check if this lead is marked do_not_call
$stmt = $pdo->prepare("SELECT do_not_call FROM leads WHERE id = ?");
$stmt->execute([$leadId]);
$lead = $stmt->fetch(PDO::FETCH_ASSOC);
$isDoNotCall = $lead && $lead['do_not_call'];

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
    // Save interaction
    Interaction::create([
        'lead_id' => $_POST['lead_id'],
        'user_id' => $user['id'],
        'disposition_id' => $_POST['disposition_id'],
        'notes' => $_POST['notes'] ?? null,
        'duration_seconds' => $_POST['duration_seconds'] ?? null,
    ]);

    // Check if the note indicates "Do Not Call"
    $note = strtolower($_POST['notes'] ?? '');
    $doNotCallKeywords = [
        'removed from future calls',
        'do not call again',
        'no further contact',
        'please stop calling'
    ];
    $isDoNotCall = false;

    foreach ($doNotCallKeywords as $keyword) {
        if (stripos($note, $keyword) !== false) {
            $isDoNotCall = true;
            break;
        }
    }

    // Also check if disposition ID is "Do Not Call Requested"
    if ($_POST['disposition_id'] == 4) {
        $isDoNotCall = true;
    }

    // Update leads table if Do Not Call is requested
    if ($isDoNotCall) {
        $stmt = $pdo->prepare("UPDATE leads SET do_not_call = 1 WHERE id = ?");
        $stmt->execute([$leadId]);
    }

    // Release lock after saving the call
    LeadLock::release($leadId, $user['id']);

    header('Location: list.php');
    exit;
}

// Get lead's phone number
$stmt = $pdo->prepare("SELECT phone FROM leads WHERE id = ?");
$stmt->execute([$leadId]);
$leadData = $stmt->fetch(PDO::FETCH_ASSOC);
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
    JOIN users u ON i.user_id = u.id
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">  
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-phone"></i> Register Call for Lead #<?= htmlspecialchars($leadId) ?></h1>

        <?php if ($isDoNotCall): ?>
            <div class="alert alert-danger">
                ⚠️ This lead has opted out of future contact. Please respect their wishes unless you have explicit permission to proceed.
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
                                    <?= floor($call['duration_seconds'] / 60) ?>m
                                    <?= $call['duration_seconds'] % 60 ?>s
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
                <label for="note_template"><i class="fas fa-memo"></i> Quick Notes</label>
                <select id="note_template" class="form-control" onchange="applyNoteTemplate()">
                    <option value="">-- Select a quick note --</option>
                    <!-- Health Insurance -->
                    <optgroup label="🩺 Health Insurance">
                        <option value="Lead was interested in health insurance options.">Interested</option>
                        <option value="Lead not interested in health insurance at this time.">Not Interested</option>
                        <option value="Sent over a quote for health coverage.">Asked for Quote</option>
                        <option value="Assisted lead with enrollment process.">Needs Help Enrolling</option>
                        <option value="Answered questions about deductible and out-of-pocket costs.">Coverage Questions</option>
                    </optgroup>
                    <!-- Life Insurance -->
                    <optgroup label="🧬 Life Insurance">
                        <option value="Lead expressed interest in term life insurance.">Interested</option>
                        <option value="Lead is not considering life insurance right now.">Not Interested</option>
                        <option value="Looking for coverage to protect family financially.">Family Protection Interest</option>
                        <option value="Compared several policies and pricing options.">Policy Comparison</option>
                    </optgroup>
                    <!-- General Follow-Up -->
                    <optgroup label="🔁 Follow-Up">
                        <option value="Will follow up again next week.">Schedule Call Back</option>
                        <option value="Left a voicemail explaining the benefits.">Left Voicemail</option>
                        <option value="Lead was busy; asked to call back later.">Busy / Not Available</option>
                        <option value="Lead requested to be removed from future calls.">Do Not Call Requested</option>
                    </optgroup>
                </select>
            </div>

            <div class="form-group">
                <label for="duration_seconds"><i class="fas fa-stopwatch"></i> Duration (seconds)</label>
                <input required type="number" id="duration_seconds" name="duration_seconds" class="form-control" placeholder="E.g.: 60">
            </div>

            <div class="form-group">
                <label for="notes"><i class="fas fa-sticky-note"></i> Additional Notes</label>
                <textarea required id="notes" name="notes" rows="5" class="form-control" placeholder="Conversation details..."></textarea>
            </div>

            <?php if ($isDoNotCall): ?>
                <button type="submit" class="btn btn-warning" onclick="return confirm('Are you sure you want to continue contacting this lead? They have requested not to be called again.')">
                    <i class="fas fa-save"></i> Override and Save Call
                </button>
            <?php else: ?>
                <button type="submit" class="btn"><i class="fas fa-save"></i> Save Call</button>
            <?php endif; ?>

        </form>

        <p>
            <a class="btn btn-secondary" href="list.php">
                <i class="fas fa-arrow-left"></i> « Back to Leads
            </a>
        </p>
    </div>

    <script>
        function applyNoteTemplate() {
            const select = document.getElementById('note_template');
            const textarea = document.getElementById('notes');
            const selectedText = select.value;
            if (selectedText) {
                textarea.value += (textarea.value ? '\n' : '') + selectedText;
            }
        }
    </script>
</body>
</html>