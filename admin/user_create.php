<?php
// admin/user_create.php — English UI + ONLY TWO new visible fields: MightyCall team member + Extension
// Keeps same MightyCall credentials you used in monitor_calls.php

// === MIGHTYCALL CREDS (same as monitor_calls.php) ===
putenv('MIGHTYCALL_API_KEY=0bb0f2ee-ff6f-4be5-8530-4d35a01e80cc');
putenv('MIGHTYCALL_SECRET_KEY=dc73c680f799');
putenv('MIGHTYCALL_REGION=US');
// putenv('MIGHTYCALL_BASE=https://api.mightycall.com/v4/api'); // optional override

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/User.php';
require_once __DIR__ . '/../lib/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

if (!Auth::check() || (!in_array('admin', Auth::user()['roles'] ?? []) && !in_array('owner', Auth::user()['roles'] ?? []))) {
    die('<div class="container"><h1>Access Denied</h1></div>');
}

$pdo   = getPDO();
$error = '';

/* ================= MightyCall helpers ================= */
function _mc_env(string $k, ?string $def=null): ?string {
    $v = getenv($k);
    return ($v === false || $v === '') ? $def : $v;
}
function _ensure_dir(string $p): void { if (!is_dir($p)) @mkdir($p, 0777, true); }

function mc_endpoints(): array {
    $base = rtrim((string)_mc_env('MIGHTYCALL_BASE', ''), '/');
    if ($base !== '') {
        $authBase = preg_replace('~/api/?$~', '', $base);
        return ['auth' => $authBase, 'api' => $base];
    }
    $region = strtoupper((string)_mc_env('MIGHTYCALL_REGION', 'US'));
    switch ($region) {
        case 'EU':
            return ['auth' => 'https://eu.api.mightycall.com/v4', 'api' => 'https://eu.api.mightycall.com/v4/api'];
        case 'SANDBOX':
            return ['auth' => 'https://sandbox.api.mightycall.com/v4', 'api' => 'https://sandbox.api.mightycall.com/v4/api'];
        default:
            return ['auth' => 'https://api.mightycall.com/v4', 'api' => 'https://api.mightycall.com/v4/api'];
    }
}
function mc_get_token(): ?array {
    $APIKEY = _mc_env('MIGHTYCALL_API_KEY', '');
    $SECRET = _mc_env('MIGHTYCALL_SECRET_KEY', '');
    if (!$APIKEY || !$SECRET) return null;

    $end     = mc_endpoints();
    $url     = rtrim($end['auth'], '/') . '/auth/token';
    $grant   = _mc_env('MIGHTYCALL_GRANT', 'client_credentials');
    $tokFile = __DIR__ . '/../logs/mc_token.json';
    _ensure_dir(dirname($tokFile));

    if (is_file($tokFile)) {
        $j = json_decode((string)@file_get_contents($tokFile), true);
        if ($j && time() < (int)($j['expires_at'] ?? 0) - 60) return $j;
    }

    $fields = http_build_query([
        'client_id'     => $APIKEY,
        'client_secret' => $SECRET,
        'grant_type'    => $grant
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'x-api-key: ' . $APIKEY],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code >= 400) return null;
    $auth = json_decode($raw, true);
    if (!is_array($auth) || empty($auth['access_token'])) return null;

    $ttl = (int)($auth['expires_in'] ?? 3600);
    $auth['expires_at'] = time() + max(0, $ttl);
    @file_put_contents($tokFile, json_encode($auth, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    return $auth;
}
function mc_get(string $path, array $qs = []): ?array {
    $APIKEY = _mc_env('MIGHTYCALL_API_KEY', '');
    if (!$APIKEY) return null;

    $tok = mc_get_token();
    if (!$tok) return null;
    $bearer = $tok['access_token'];

    $end = mc_endpoints();
    $url = rtrim($end['api'], '/') . '/' . ltrim($path, '/');
    if ($qs) $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($qs);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $bearer,
            'x-api-key: ' . $APIKEY,
            'Content-Type: application/json'
        ],
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code >= 400) return null;

    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}
function mc_extract_extension(array $u): ?string {
    $cands = [];
    foreach (['extensionNumber','extension','defaultExtension','userExtension','sipExtension'] as $k) {
        if (!empty($u[$k])) $cands[] = (string)$u[$k];
    }
    if (!empty($u['extensions']) && is_array($u['extensions'])) {
        foreach ($u['extensions'] as $ex) {
            if (is_array($ex)) {
                if (!empty($ex['number']))     $cands[] = (string)$ex['number'];
                if (!empty($ex['extension']))  $cands[] = (string)$ex['extension'];
            }
        }
    }
    if (!empty($u['phones']) && is_array($u['phones'])) {
        foreach ($u['phones'] as $ph) {
            if (is_array($ph) && !empty($ph['extension'])) $cands[] = (string)$ph['extension'];
        }
    }
    foreach ($cands as $c) {
        if (preg_match('/^\d{2,6}$/', $c)) return $c;
    }
    return null;
}
function mc_fetch_team_members_from_team(): array {
    $cacheFile = __DIR__ . '/../cache/mc_team_from_team.json';
    _ensure_dir(dirname($cacheFile));
    if (is_file($cacheFile)) {
        $j = json_decode((string)@file_get_contents($cacheFile), true);
        if ($j && time() < (int)($j['expires_at'] ?? 0)) return $j['items'] ?? [];
    }

    $res = mc_get('team');
    $users = [];
    if (isset($res['users']) && is_array($res['users'])) {
        $users = $res['users'];
    } elseif (isset($res['data']['users']) && is_array($res['data']['users'])) {
        $users = $res['data']['users'];
    }

    $out = [];
    foreach ($users as $u) {
        if (!is_array($u)) continue;
        $name  = $u['fullName'] ?? ($u['name'] ?? trim(($u['firstName'] ?? '') . ' ' . ($u['lastName'] ?? '')));
        $ext   = mc_extract_extension($u);
        $phone = null;
        if (!empty($u['phones'][0]['number'])) $phone = (string)$u['phones'][0]['number'];
        if (!$phone && !empty($u['phone']))    $phone = (string)$u['phone'];
        if (!$name) continue;
        $out[] = ['name' => $name, 'extension' => $ext, 'phone' => $phone];
    }

    $uniq = [];
    foreach ($out as $m) {
        $k = mb_strtolower(($m['name'] ?? '') . '|' . ($m['extension'] ?? ''));
        $uniq[$k] = $m;
    }
    $items = array_values($uniq);
    usort($items, fn($a,$b)=>strcoll($a['name'] ?? '', $b['name'] ?? ''));

    @file_put_contents($cacheFile, json_encode(['expires_at'=> time()+600, 'items'=>$items], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    return $items;
}
/* ====================================================== */

// roles & recruiters
$roles    = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$allUsers = $pdo->query("SELECT id, name FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// MightyCall team list
$mcMembers = [];
if (_mc_env('MIGHTYCALL_API_KEY') && _mc_env('MIGHTYCALL_SECRET_KEY')) {
    $mcMembers = mc_fetch_team_members_from_team();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    try {
        $name        = trim($_POST['name'] ?? '');
        $email       = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $password    = trim($_POST['password'] ?? '');
        $status      = (($_POST['status'] ?? 'active') === 'inactive') ? 'inactive' : 'active';
        $roleIds     = is_array($_POST['role_ids'] ?? null) ? array_map('intval', $_POST['role_ids']) : [];
        $refParentId = isset($_POST['ref_parent_id']) && $_POST['ref_parent_id'] !== '' ? (int)$_POST['ref_parent_id'] : null;

        // Two new fields (visible) + hidden mc_agent
        $mc_agent     = trim($_POST['mc_agent'] ?? '');       // hidden, set from select
        $mc_extension = trim($_POST['mc_extension'] ?? '');   // visible input
        if ($mc_agent === '')     $mc_agent = null;
        if ($mc_extension === '') $mc_extension = null;
        if ($mc_extension !== null && !preg_match('/^\d{2,6}$/', $mc_extension)) {
            throw new Exception('Extension must be numeric (2 to 6 digits).');
        }

        if (!$name || !$email || !$password) {
            throw new Exception('Name, email and password are required.');
        }

        $pdo->beginTransaction();

        $userId = User::create([
            'name'     => $name,
            'email'    => $email,
            'password' => $password,
            'status'   => $status
        ]);

        // Save mc_agent / mc_extension
        $upd = $pdo->prepare("UPDATE users SET mc_agent = :mc_agent, mc_extension = :mc_extension WHERE id = :id");
        $upd->execute([
            ':mc_agent'     => $mc_agent,
            ':mc_extension' => $mc_extension,
            ':id'           => $userId
        ]);

        if ($roleIds) {
            $insert = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
            foreach ($roleIds as $rid) $insert->execute([$userId, (int)$rid]);
        }

        if ($refParentId !== null) {
            if ($refParentId === (int)$userId) throw new Exception('A user cannot be their own recruiter.');
            $stmt = $pdo->prepare("INSERT INTO user_referrals (parent_id, child_id) VALUES (?, ?)");
            $stmt->execute([$refParentId, $userId]);
        }

        $pdo->commit();
        header('Location: users.php?msg=User created');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Create User</title>
  <link rel="stylesheet" href="../assets/css/admin/user_create.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    .container { max-width: 880px; margin: 24px auto; padding: 0 16px; }
    .form-group { display:flex; flex-direction:column; gap:.35rem; margin-bottom:12px; }
    input[type="text"], input[type="email"], input[type="password"], select { padding:.5rem .6rem; border:1px solid #e5e7eb; border-radius:.4rem; }
    .btn { display:inline-flex; align-items:center; gap:.35rem; padding:.45rem .7rem; border-radius:.4rem; background:#111827; color:#fff; text-decoration:none; }
    .btn:hover { opacity:.92; }
    .btn-secondary { background:#6b7280; }
    .error { background:#fee2e2; color:#991b1b; padding:.5rem .7rem; border-radius:.4rem; }
    .checkbox-label { display:inline-flex; align-items:center; gap:.35rem; margin-right:12px; }
    .muted { color:#6b7280; font-size:.9em; }
  </style>
</head>
<body>
  <div class="container">
    <h1>Create New User</h1>

    <?php if ($error): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

      <div class="form-group">
        <label>Name</label>
        <input type="text" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" required autocomplete="new-password">
      </div>

      <div class="form-group">
        <label>Status</label>
        <select name="status">
          <option value="active"   <?= (($_POST['status'] ?? 'active')==='active')   ? 'selected':'' ?>>Active</option>
          <option value="inactive" <?= (($_POST['status'] ?? '')==='inactive') ? 'selected':'' ?>>Inactive</option>
        </select>
      </div>

      <!-- ONLY TWO NEW VISIBLE FIELDS BELOW -->
      <?php if (!empty($mcMembers)): ?>
        <div class="form-group">
          <label>MightyCall team member</label>
          <select id="mc_member_select">
            <option value="">— Select a member —</option>
            <?php foreach ($mcMembers as $m): ?>
              <option
                value="<?= htmlspecialchars($m['name'] ?? '', ENT_QUOTES) ?>"
                data-name="<?= htmlspecialchars($m['name'] ?? '', ENT_QUOTES) ?>"
                data-ext="<?= htmlspecialchars($m['extension'] ?? '', ENT_QUOTES) ?>"
                data-phone="<?= htmlspecialchars($m['phone'] ?? '', ENT_QUOTES) ?>"
              >
                <?= htmlspecialchars($m['name'] ?? '') ?>
                <?= !empty($m['extension']) ? ' (ext '.$m['extension'].')' : '' ?>
                <?= (!empty($m['phone']) ? ' · '.$m['phone'] : '') ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small class="muted">Source: MightyCall /team (cached for 10 min).</small>
        </div>
      <?php else: ?>
        <div class="form-group">
          <label class="muted">MightyCall team member</label>
          <div class="muted">Could not load the team list (check MIGHTYCALL_*). You can still type the extension below.</div>
        </div>
      <?php endif; ?>

      <div class="form-group">
        <label>Extension</label>
        <input id="mc_extension" type="text" name="mc_extension" maxlength="20" pattern="\d{2,6}" title="Digits only, 2–6" placeholder="e.g., 101"
               value="<?= htmlspecialchars($_POST['mc_extension'] ?? '') ?>">
      </div>
      <!-- hidden agent name (filled from select) -->
      <input id="mc_agent" type="hidden" name="mc_agent" value="<?= htmlspecialchars($_POST['mc_agent'] ?? '') ?>">

      <div class="form-group">
        <label>Recruiter</label>
        <select name="ref_parent_id">
          <option value="">— None —</option>
          <?php foreach ($allUsers as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (isset($_POST['ref_parent_id']) && (int)$_POST['ref_parent_id'] === (int)$u['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($u['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small class="muted">Optional: assign the user who recruited this user.</small>
      </div>

      <div class="form-group">
        <label>Roles</label><br>
        <?php foreach ($roles as $r): ?>
          <label class="checkbox-label">
            <input
              type="checkbox"
              name="role_ids[]"
              value="<?= (int)$r['id'] ?>"
              <?= (isset($_POST['role_ids']) && in_array($r['id'], (array)$_POST['role_ids'])) ? 'checked' : '' ?>
            >
            <?= htmlspecialchars($r['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>

      <button type="submit" class="btn"><i class="fas fa-user-plus"></i> Create User</button>
      <a href="users.php" class="btn btn-secondary">Cancel</a>
    </form>
  </div>

  <script>
    (function(){
      const sel   = document.getElementById('mc_member_select');
      const iName = document.getElementById('mc_agent');     // hidden
      const iExt  = document.getElementById('mc_extension'); // visible
      if (!sel || !iName || !iExt) return;

      sel.addEventListener('change', () => {
        const opt  = sel.options[sel.selectedIndex];
        if (!opt) return;
        const name = opt.getAttribute('data-name') || '';
        const ext  = opt.getAttribute('data-ext')  || '';
        if (name) iName.value = name;           // hidden field
        if (ext && !iExt.value) iExt.value = ext; // prefill extension only if empty
      });
    })();
  </script>
</body>
</html>
