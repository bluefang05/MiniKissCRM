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
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $pageTitle   = "Access Denied";
        $pageHeading = "Access Denied";
        $errorMessage = "Invalid CSRF token.";
    }
}

if (!Auth::check()) {
    header('Location: /auth/login.php');
    exit;
}

$user = Auth::user();
$pdo  = getPDO();

$leadId = (int)($_GET['lead_id'] ?? 0);

// Attempt to acquire lock for call (5 minutes)
if (!LeadLock::acquire($leadId, $user['id'], 5)) {
    $lockInfo    = LeadLock::check($leadId);
    $expires     = htmlspecialchars($lockInfo['expires_at'] ?? '');
    $pageTitle   = "Lead Locked";
    $pageHeading = "Lead Locked";
    $lockedView  = true;
} else {
    $lockedView = false;

    // Load lead phone number
    $stmt = $pdo->prepare("SELECT phone FROM leads WHERE id = ?");
    $stmt->execute([$leadId]);
    $leadData    = $stmt->fetch(PDO::FETCH_ASSOC);
    $phoneNumber = $leadData['phone'] ?? '';

    // Normalize phone number to E.164-ish
    $cleaned = preg_replace('/\D+/', '', (string)$phoneNumber);
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
        $pageTitle   = "Error";
        $pageHeading = "Error";
        $errorMessage = "No disposition types found.";
        $lockedView   = true; // Show error inside main layout
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
    $successMessage   = '';
    $formErrorMessage = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$lockedView) {
        $dispositionId = (int)($_POST['disposition_id'] ?? 0);
        $duration      = (int)($_POST['duration_seconds'] ?? 0);
        $notes         = trim($_POST['notes'] ?? '');
        $doNotCall     = !empty($_POST['do_not_call']) ? 1 : 0;

        // New: Source + MC optional fields
        $source          = ($_POST['source'] ?? 'self') === 'mc' ? 'mc' : 'self';
        $mc_call_id      = trim($_POST['mc_call_id'] ?? '');
        $mc_recording    = trim($_POST['mc_recording_url'] ?? '');
        $mc_status       = trim($_POST['mc_status'] ?? '');
        $mc_direction    = trim($_POST['mc_direction'] ?? '');
        $mc_started_utc  = trim($_POST['mc_started_utc'] ?? '');

        // Validate source when MC selected
        if ($source === 'mc') {
            if ($mc_call_id === '') {
                $formErrorMessage = 'Select a MightyCall entry or switch Source to Self-reported.';
            } else {
                // If user didn’t touch duration, we still require it; but we’ll accept MC duration via hidden field
                if ($duration <= 0 && isset($_POST['duration_seconds'])) {
                    // leave as is (could be filled by JS)
                }
            }
        }

        if (!$formErrorMessage && $dispositionId > 0 && $duration > 0) {
            // Append MC metadata to notes so we keep linkage even if DB lacks columns
            $metaBits = [];
            if ($source === 'mc') {
                if ($mc_call_id)     $metaBits[] = "MC#{$mc_call_id}";
                if ($mc_status)      $metaBits[] = "status={$mc_status}";
                if ($mc_direction)   $metaBits[] = "dir={$mc_direction}";
                if ($mc_started_utc) $metaBits[] = "at={$mc_started_utc}";
                if ($mc_recording)   $metaBits[] = "rec={$mc_recording}";
            }
            if ($metaBits) {
                $notes .= ($notes ? "\n\n" : "") . "[MightyCall] " . implode(' | ', $metaBits);
            }

            // Create Interaction (your existing helper)
            $insertId = Interaction::create([
                'lead_id'          => $leadId,
                'user_id'          => $user['id'],
                'disposition_id'   => $dispositionId,
                'notes'            => $notes,
                'duration_seconds' => $duration,
            ]);

            // Try to persist mc_* columns if the schema already has them (ignore failures)
            if ($source === 'mc' && $insertId) {
                try {
                    $upd = $pdo->prepare("
                        UPDATE interactions
                        SET mc_call_id = :cid,
                            mc_recording_url = :rec,
                            mc_status = :st,
                            mc_direction = :dir,
                            mc_started_utc = :ts
                        WHERE id = :id
                    ");
                    $upd->execute([
                        ':cid' => $mc_call_id ?: null,
                        ':rec' => $mc_recording ?: null,
                        ':st'  => $mc_status   ?: null,
                        ':dir' => $mc_direction?: null,
                        ':ts'  => $mc_started_utc ?: null,
                        ':id'  => $insertId
                    ]);
                } catch (\Throwable $e) {
                    // schema might not have these columns yet — ignore
                }
            }

            if ($doNotCall) {
                $pdo->prepare("UPDATE leads SET do_not_call = 1 WHERE id = ?")->execute([$leadId]);
            }

            LeadLock::release($leadId, $user['id']);
            header('Location: list.php?status=call_saved');
            exit;
        } elseif (!$formErrorMessage) {
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
  <style>
    .mc-box { background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; padding:12px; margin:10px 0; }
    .mc-head { display:flex; align-items:center; justify-content:space-between; gap:.5rem; }
    .mc-list { margin-top:10px; max-height:260px; overflow:auto; border-top:1px dashed #e5e7eb; padding-top:8px; }
    .mc-item { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px; border-bottom:1px solid #eef2f7; }
    .mc-item:last-child { border-bottom:none; }
    .mc-meta { font-size:.92rem; color:#374151; }
    .mc-use { white-space:nowrap; }
    .hidden { display:none; }
    .source-row { display:flex; gap:18px; align-items:center; }
    .muted { color:#6b7280; font-size:.9em; }
  </style>
</head>
<body>

<div class="container">

  <h1><i class="fas fa-phone"></i> <?= $pageHeading ?? "Register Call - Lead #$leadId" ?></h1>

  <?php if (!$lockedView): ?>
    <a href="../leads/list.php" class="back-button"><i class="fas fa-arrow-left"></i> Back to Leads</a>
  <?php endif; ?>

  <!-- Lock / Error messages -->
  <?php if (isset($errorMessage)): ?>
    <div class="alert alert-error"><?= $errorMessage ?></div>
  <?php elseif (isset($expires) && $lockedView): ?>
    <div class="alert">This lead is currently being used by another user until <strong><?= $expires ?></strong>.</div>
  <?php elseif (isset($lockedView) && $lockedView && isset($formErrorMessage)): ?>
    <div class="alert alert-error"><?= $formErrorMessage ?></div>
  <?php endif; ?>

  <?php if (!$lockedView): ?>

    <!-- Phone Actions -->
    <?php if (!empty($phoneNumber)): ?>
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

      <!-- MightyCall recent matches -->
      <div class="mc-box" id="mc-box">
        <div class="mc-head">
          <div>
            <strong><i class="fas fa-link"></i> Match recent MightyCall calls</strong>
            <div class="muted">Auto-fill from the last 7 days for this phone.</div>
          </div>
          <button type="button" class="call-button" id="mc-load-btn">
            <i class="fas fa-sync"></i> Load
          </button>
        </div>
        <div id="mc-list" class="mc-list hidden"></div>
        <div id="mc-empty" class="muted hidden">No recent calls found.</div>
      </div>
    <?php endif; ?>

    <!-- Call History -->
    <?php if (!empty($interactions)): ?>
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
              <small><?= nl2br(htmlspecialchars(mb_strimwidth($call['notes'], 0, 100, '…'))) ?></small>
              <div class="interaction-duration">
                <i class="fas fa-stopwatch"></i> Duration: <?= floor(($call['duration_seconds'] ?? 0) / 60) ?>m<?= ($call['duration_seconds'] ?? 0) % 60 ?>s
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php else: ?>
      <div class="alert">No previous calls recorded.</div>
    <?php endif; ?>

    <!-- Call Form -->
    <form method="post" action="" class="call-form" id="call-form">
      <input type="hidden" name="lead_id" value="<?= $leadId ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

      <!-- Source selector -->
      <div class="form-group">
        <label><i class="fas fa-database"></i> Source</label>
        <div class="source-row">
          <label><input type="radio" name="source" value="self" id="source-self" checked> Self-reported</label>
          <label><input type="radio" name="source" value="mc" id="source-mc"> MightyCall</label>
        </div>
        <small class="muted">Choose MightyCall if you selected a recent call above; otherwise leave Self-reported.</small>
      </div>

      <!-- Hidden MC fields (populated via JS when picking an item) -->
      <input type="hidden" name="mc_call_id" id="mc_call_id">
      <input type="hidden" name="mc_recording_url" id="mc_recording_url">
      <input type="hidden" name="mc_status" id="mc_status">
      <input type="hidden" name="mc_direction" id="mc_direction">
      <input type="hidden" name="mc_started_utc" id="mc_started_utc">

      <div class="form-group">
        <label for="disposition_id"><i class="fas fa-check-circle"></i> Call Outcome</label>
        <select id="disposition_id" name="disposition_id" class="form-control" required>
          <option value="">Select Disposition</option>
          <?php foreach ($dispositions as $d): ?>
            <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
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
        <small class="muted" id="duration_hint" style="display:none;">Filled from MightyCall.</small>
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
const leadPhoneE164 = <?= json_encode($internationalNumber ?? '') ?>;

function copyPhoneNumber() {
  const el = document.getElementById('phone-number');
  if (!el) return;
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

// MightyCall recent calls loader
(function(){
  const btn   = document.getElementById('mc-load-btn');
  const list  = document.getElementById('mc-list');
  const empty = document.getElementById('mc-empty');
  const dur   = document.getElementById('duration_seconds');
  const durHint = document.getElementById('duration_hint');

  const srcSelf = document.getElementById('source-self');
  const srcMC   = document.getElementById('source-mc');

  function setSource(to) {
    if (to === 'mc') {
      srcMC.checked = true;
      // If we already filled duration via MC, show hint
      if (dur.dataset.filledFromMc === '1') {
        durHint.style.display = 'inline';
      }
    } else {
      srcSelf.checked = true;
      dur.removeAttribute('readonly');
      dur.dataset.filledFromMc = '';
      durHint.style.display = 'none';
      // Clear hidden mc fields
      ['mc_call_id','mc_recording_url','mc_status','mc_direction','mc_started_utc'].forEach(id=>{
        const el=document.getElementById(id); if (el) el.value='';
      });
    }
  }

  if (srcSelf) srcSelf.addEventListener('change', ()=> setSource('self'));
  if (srcMC)   srcMC.addEventListener('change',  ()=> setSource('mc'));

  if (!btn || !list) return;

  btn.addEventListener('click', async () => {
    list.innerHTML = '';
    list.classList.add('hidden');
    empty.classList.add('hidden');
    btn.disabled = true; btn.textContent = 'Loading...';

    try {
      const digits = (leadPhoneE164 || '').replace(/\D+/g, '');
      const url = '/api/mc_recent.php?phone=' + encodeURIComponent(digits) + '&days=7&limit=20';
      const res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const j = await res.json();

      const items = (j && j.items) || [];
      if (!items.length) {
        empty.classList.remove('hidden');
        return;
      }

      const fmt = (n)=> String(n).padStart(2,'0');

      items.forEach(it => {
        // Normalize fields: support both snake/camel just in case
        const id   = it.id || it.callId || it.uuid || '';
        const at   = it.dateTimeUtc || it.startedUtc || it.started_at || it.date || it.time || '';
        const dir  = (it.direction || '').toLowerCase();
        const st   = it.status || it.callStatus || '';
        const durS = Number(it.durationSeconds ?? it.duration_sec ?? it.duration ?? 0);
        const rec  = it.recordingUrl || it.recording_url || '';

        const from = it.from || (it.caller && (it.caller.name || it.caller.phone)) || '';
        const to   = it.to   || (Array.isArray(it.called) && it.called[0] && (it.called[0].name || it.called[0].phone)) || '';

        const row = document.createElement('div');
        row.className = 'mc-item';
        row.innerHTML = `
          <div class="mc-meta">
            <div><strong>${at ? new Date(at).toISOString().replace('T',' ').slice(0,16) : '-'}</strong></div>
            <div>${dir ? dir[0].toUpperCase()+dir.slice(1) : ''} • ${st || ''} • ${durS}s</div>
            <div class="muted">${from ? ('From: '+from) : ''} ${to ? ('→ '+to) : ''}</div>
          </div>
          <div class="mc-use">
            ${rec ? `<a href="${rec}" target="_blank" class="btn btn-secondary" style="margin-right:6px;"><i class="fas fa-play"></i></a>` : ''}
            <button type="button" class="call-button">Use</button>
          </div>
        `;

        row.querySelector('button.call-button').addEventListener('click', () => {
          // Fill hidden fields
          document.getElementById('mc_call_id').value = id || '';
          document.getElementById('mc_recording_url').value = rec || '';
          document.getElementById('mc_status').value = st || '';
          document.getElementById('mc_direction').value = dir || '';
          document.getElementById('mc_started_utc').value = at || '';

          // Fill duration + UI
          if (durS > 0) {
            dur.value = String(durS);
            dur.setAttribute('readonly','readonly');
            dur.dataset.filledFromMc = '1';
            durHint.style.display = 'inline';
          }
          setSource('mc');
          // Optional: add a tiny note tag if empty
          const notes = document.getElementById('notes');
          if (notes && !notes.value.trim()) {
            notes.value = 'Linked to MightyCall recent call.';
          }
        });

        list.appendChild(row);
      });

      list.classList.remove('hidden');
    } catch (e) {
      empty.textContent = 'Failed to load recent calls.';
      empty.classList.remove('hidden');
    } finally {
      btn.disabled = false; btn.innerHTML = '<i class="fas fa-sync"></i> Load';
    }
  });
})();
</script>

</body>
</html>
