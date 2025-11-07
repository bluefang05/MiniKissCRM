<?php
// calls/my_interactions.php
declare(strict_types=1);

/* ========= Auth guard ========= */
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';
if (!Auth::check()) {
  header('Location: ../auth/login.php', true, 302);
  exit;
}
$user = Auth::user();
$pdo  = getPDO();

/* ========= App config (reusa lo de monitor.php) =========
   Puedes omitir estos putenv si ya cargas desde Apache/ENV */
putenv('MIGHTYCALL_API_KEY=' . (getenv('MIGHTYCALL_API_KEY') ?: '0bb0f2ee-ff6f-4be5-8530-4d35a01e80cc'));
putenv('MIGHTYCALL_SECRET_KEY=' . (getenv('MIGHTYCALL_SECRET_KEY') ?: 'dc73c680f799'));
putenv('MIGHTYCALL_BASE=' . (getenv('MIGHTYCALL_BASE') ?: 'https://api.mightycall.com/v4/api'));
putenv('MIGHTYCALL_GRANT=' . (getenv('MIGHTYCALL_GRANT') ?: 'client_credentials'));

@ini_set('memory_limit', '1024M');
@set_time_limit(0);

/* ========= Helpers ========= */
function _env_req(string $k): string {
  $v = getenv($k);
  if ($v === false || $v === '') throw new RuntimeException("Missing env: $k");
  return $v;
}
function _ensure_dir(string $p): void { if (!is_dir($p)) @mkdir($p, 0777, true); }
function _log(string $msg): void {
  $f = __DIR__ . '/../logs/mightycall.log';
  _ensure_dir(dirname($f));
  file_put_contents($f, '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}
function _hms_from_ms(int $ms): string {
  $s = (int)round($ms / 1000);
  $h = intdiv($s, 3600); $s %= 3600;
  $m = intdiv($s, 60);   $s %= 60;
  return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

/* ========= Who am I (DB) ========= */
$stmt = $pdo->prepare("SELECT id, name, mc_agent, mc_extension FROM users WHERE id = :id");
$stmt->execute([':id' => (int)$user['id']]);
$me = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['name'=>$user['name'] ?? '—','mc_agent'=>null,'mc_extension'=>null];
$myName      = (string)($me['name'] ?? '');
$myMcAgent   = $me['mc_agent'] ?? null;        // p.ej. “Enmanuel Dominguez”
$myExtension = $me['mc_extension'] ?? null;    // p.ej. “101”

/* ========= Token cache ========= */
$BASE      = _env_req('MIGHTYCALL_BASE');                  // https://api.mightycall.com/v4/api
$AUTH_BASE = preg_replace('~/api/?$~', '', rtrim($BASE,'/'));
$APIKEY    = _env_req('MIGHTYCALL_API_KEY');
$SECRET    = _env_req('MIGHTYCALL_SECRET_KEY');
$GRANT     = getenv('MIGHTYCALL_GRANT') ?: 'client_credentials';

$tokFile = __DIR__ . '/../logs/mc_token.json';
_ensure_dir(dirname($tokFile));

$loadTok = function() use($tokFile) {
  if (!is_file($tokFile)) return null;
  $j = json_decode((string)@file_get_contents($tokFile), true);
  if (!$j) return null;
  if (time() >= (int)($j['expires_at'] ?? 0) - 60) return null;
  return $j;
};
$saveTok = function(array $auth) use($tokFile) {
  $ttl = (int)($auth['expires_in'] ?? 3600);
  $auth['expires_at'] = time() + max(0, $ttl);
  @file_put_contents($tokFile, json_encode($auth, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
};

/* ========= Actions (API for the same file) ========= */
$action = $_GET['action'] ?? null;

/** Fetch bearer */
function mc_bearer(): string {
  global $loadTok, $saveTok, $AUTH_BASE, $APIKEY, $SECRET, $GRANT;
  $tok = $loadTok();
  if (!$tok) {
    $url = rtrim($AUTH_BASE, '/') . '/auth/token';
    $ch  = curl_init($url);
    $post = http_build_query([
      'client_id'     => $APIKEY,
      'client_secret' => $SECRET,
      'grant_type'    => $GRANT
    ]);
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => $post,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 60,
      CURLOPT_HTTPHEADER     => [
        'Content-Type: application/x-www-form-urlencoded',
        'x-api-key: ' . $APIKEY
      ],
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400 || $raw === false) throw new RuntimeException("Auth failed ($code)");
    $auth = json_decode($raw, true);
    if (!is_array($auth) || empty($auth['access_token'])) throw new RuntimeException('Bad token response');
    $saveTok($auth);
    $tok = $loadTok();
  }
  return (string)$tok['access_token'];
}

/** GET wrapper */
function mc_get(string $path, array $qs = []): array {
  global $BASE, $APIKEY;
  $bearer = mc_bearer();
  $url = rtrim($BASE, '/') . '/' . ltrim($path, '/');
  if ($qs) $url .= (strpos($url,'?') !== false ? '&' : '?') . http_build_query($qs);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
      'Authorization: Bearer ' . $bearer,
      'x-api-key: ' . $APIKEY,
      'Content-Type: application/json'
    ],
  ]);
  $raw  = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code >= 400 || $raw === false) throw new RuntimeException("HTTP $code on $path");
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

/* Small utility to restrict to my agent */
function match_my_agent(array $c, ?string $agentName, ?string $ext): bool {
  $nm  = strtolower((string)($c['caller']['name'] ?? ''));
  $ex  = (string)($c['caller']['extension'] ?? '');
  $okName = $agentName ? (strpos($nm, strtolower($agentName)) !== false) : true;
  $okExt  = $ext ? ($ex === $ext) : true;
  return $okName && $okExt;
}

/* ===== JSON endpoints ===== */
if ($action === 'whoami') {
  header('Content-Type: application/json; charset=utf-8');
  $mcAgent = '—';
  try {
    $team = mc_get('team', []);
    $users = $team['users'] ?? ($team['data']['users'] ?? []);
    if (is_array($users)) {
      foreach ($users as $u) {
        $name = $u['fullName'] ?? ($u['name'] ?? trim(($u['firstName'] ?? '') . ' ' . ($u['lastName'] ?? '')));
        if ($name && $myMcAgent && mb_strtolower($name) === mb_strtolower($myMcAgent)) {
          $mcAgent = $name;
          break;
        }
      }
    }
  } catch (\Throwable $e) {
    // ignore, just report no team
  }
  echo json_encode([
    'logged'  => $myName,
    'dbAgent' => $myMcAgent,
    'mcAgent' => $mcAgent,
    'base'    => $BASE,
    'auth'    => rtrim($AUTH_BASE, '/') . '/auth/token',
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'calls') {
  // rango
  $since = $_GET['since'] ?? (new DateTimeImmutable('-29 days'))->format('Y-m-d');
  $until = $_GET['until'] ?? (new DateTimeImmutable('now'))->format('Y-m-d');
  $fromIso = (new DateTimeImmutable($since . ' 00:00:00'))->format('c');
  $toIso   = (new DateTimeImmutable($until . ' 23:59:59'))->format('c');

  // paginación simple
  $page    = max(1, (int)($_GET['page'] ?? 1));
  $perPage = min(100, max(10, (int)($_GET['perPage'] ?? 25)));

  // trae todo el rango (paginando backend) y filtra por mi agente
  $all = [];
  $skip = 0; $pageSize = 100;
  for ($i=0; $i<100; $i++) {
    $res = mc_get('calls', [
      'startUtc' => $fromIso,
      'endUtc'   => $toIso,
      'skip'     => $skip,
      'pageSize' => $pageSize
    ]);
    $data  = $res['data'] ?? $res;
    $calls = $data['calls'] ?? [];
    if (!$calls) break;
    foreach ($calls as $c) {
      if (match_my_agent($c, $myMcAgent, $myExtension)) $all[] = $c;
    }
    $skip += $pageSize;
    $total = (int)($data['total'] ?? 0);
    if ($skip >= $total) break;
  }

  // ordenar recientes primero + pagina
  $sorted = array_values(array_reverse($all));
  $total  = count($sorted);
  $pages  = max(1, (int)ceil($total / $perPage));
  if ($page > $pages) $page = $pages;
  $slice  = array_slice($sorted, ($page-1)*$perPage, $perPage);

  // KPIs
  $connected = array_filter($sorted, fn($c)=>($c['callStatus']??'')==='Connected');
  $missed    = array_filter($sorted, fn($c)=>($c['callStatus']??'')==='Missed');
  $durMsTot  = array_sum(array_map(fn($c)=>(int)($c['duration']??0), $connected));
  $avgMs     = $connected ? (int)round($durMsTot / count($connected)) : 0;

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'range' => ['since'=>$since,'until'=>$until],
    'kpi'   => [
      'calls' => $total,
      'connected' => count($connected),
      'missed' => count($missed),
      'rate' => $total ? round(count($connected)/$total*100,1) : 0,
      'avg'  => _hms_from_ms($avgMs),
    ],
    'page' => $page,
    'pages'=> $pages,
    'perPage' => $perPage,
    'rows' => array_map(function($c){
      return [
        'when'   => (string)($c['dateTimeUtc'] ?? ''),
        'agent'  => trim((($c['caller']['name'] ?? '') ?: '') . (isset($c['caller']['extension']) ? ' ('.$c['caller']['extension'].')':'')),
        'dir'    => (string)($c['direction'] ?? ''),
        'status' => (string)($c['callStatus'] ?? ''),
        'peer'   => (string)($c['called'][0]['phone'] ?? ($c['called'][0]['name'] ?? ($c['caller']['phone'] ?? ''))),
        'dur'    => _hms_from_ms((int)($c['duration'] ?? 0)),
        'rec'    => (string)($c['callRecord']['uri'] ?? ''),
      ];
    }, $slice)
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>My Interactions (CRM + MightyCall)</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<style>
  :root{
    --bg:#0f1419; --panel:#1e293b; --muted:#9aa5c4; --line:#334155;
    --btn:#334155; --btn-h:#475569; --accent:#10b981; --accent2:#60a5fa;
    --danger:#ef4444; --warn:#f59e0b;
  }
  *{box-sizing:border-box}
  body{margin:0;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,"Helvetica Neue",Arial;background:var(--bg);color:#e9ecf1}
  .wrap{max-width:1280px;margin:0 auto;padding:12px}
  a{color:#a5b4fc;}
  .header{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:6px 0 12px}
  .back{display:inline-flex;align-items:center;gap:8px;background:#1f2937;color:#e9ecf1;border:none;padding:8px 12px;border-radius:8px;text-decoration:none}
  .back:hover{background:#374151}
  h1{margin:0;font-size:24px;font-weight:700}
  .card{background:var(--panel);border-radius:10px;padding:14px;box-shadow:0 6px 16px rgba(0,0,0,.2);margin-bottom:12px}
  .row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
  label{font-size:.9rem;color:var(--muted)}
  input,select{background:var(--bg);border:1px solid var(--line);color:#e9ecf1;border-radius:8px;padding:6px 10px}
  .btn{background:var(--btn);border:none;color:#e9ecf1;border-radius:8px;padding:8px 12px;cursor:pointer}
  .btn:hover{background:var(--btn-h)}
  .btn.primary{background:var(--accent)}
  .btn.blue{background:#3b82f6}
  .pill{display:inline-block;background:#0b1220;border:1px solid var(--line);padding:2px 8px;border-radius:999px;color:var(--muted);font-size:.85rem}
  .warn{background:#3b2d0b;border-left:4px solid var(--warn);padding:10px;border-radius:8px}
  .danger{background:#3b0f0f;border-left:4px solid var(--danger);padding:10px;border-radius:8px}
  .grid{display:grid;gap:12px}
  .kpis{grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
  .kpis .big{font-size:28px;font-weight:800;margin-top:6px}
  table{width:100%;border-collapse:collapse}
  th,td{border-bottom:1px solid var(--line);padding:8px 10px;text-align:left}
  th{color:#cbd5e1}
  tr:hover{background:#0f172a}
  .right{margin-left:auto}
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <a class="back" href="../leads/list.php">← Back to Leads</a>
    <h1>My Interactions <span class="pill">CRM + MightyCall</span></h1>
    <span class="pill" id="who">—</span>
    <span class="pill" id="base">—</span>
  </div>

  <?php if (!$myMcAgent): ?>
    <div class="card warn">No MightyCall team assigned for your user (users.mc_agent is empty). Please contact the administrator.</div>
  <?php endif; ?>

  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div class="row">
        <label>Since <input type="date" id="since"></label>
        <label>Until <input type="date" id="until"></label>
      </div>
      <div class="row">
        <button class="btn" id="btn7">Last 7d</button>
        <button class="btn" id="btn30">Last 30d</button>
        <button class="btn primary" id="apply">Apply</button>
        <button class="btn blue" id="csv">Export CSV</button>
      </div>
    </div>
  </div>

  <div class="grid kpis">
    <div class="card"><div>Calls (MC)</div><div class="big" id="k_calls">—</div></div>
    <div class="card"><div>Connection rate</div><div class="big" id="k_rate">—</div><div id="k_connMiss" style="color:var(--muted)"></div></div>
    <div class="card"><div>Avg (connected)</div><div class="big" id="k_avg">—</div></div>
  </div>

  <div class="card">
    <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:6px">
      <div style="font-weight:600">MightyCall — Recent calls (only your agent)</div>
      <div class="row">
        <label>Rows<input type="number" id="perPage" min="10" max="100" value="25" style="width:80px"></label>
        <button class="btn" id="prev">‹ Prev</button>
        <span class="pill" id="pageInfo">—</span>
        <button class="btn" id="next">Next ›</button>
      </div>
    </div>
    <table id="tbl">
      <thead><tr>
        <th>When (UTC)</th><th>Agent</th><th>Direction</th><th>Status</th><th>To/From</th><th>Duration</th><th>Recording</th>
      </tr></thead>
      <tbody></tbody>
    </table>
  </div>

  <div class="card" style="opacity:.85">
    <div style="font-weight:600;margin-bottom:6px">CRM Interactions</div>
    <div style="color:var(--muted)">(Opcional) Aquí puedes integrar tus interacciones del CRM si deseas. De momento, esta vista se enfoca en MightyCall filtrado por tu <code>users.mc_agent</code> y <code>users.mc_extension</code>.</div>
  </div>

</div>

<script>
(function(){
  const el = id => document.getElementById(id);
  const state = {page:1, pages:1, perPage:25};

  function setRange(days){
    const t = new Date(); const s = new Date();
    s.setDate(t.getDate() - (days-1));
    el('since').value = s.toISOString().slice(0,10);
    el('until').value = t.toISOString().slice(0,10);
  }

  async function who(){
    try{
      const r = await fetch('my_interactions.php?action=whoami');
      const j = await r.json();
      el('who').textContent = `Logged: ${j.logged} • DB: ${j.dbAgent} • MC: ${j.mcAgent || '—'}`;
      el('base').textContent = `Using: ${j.base.replace('/api','')} · /auth/token`;
    }catch(e){/* ignore */}
  }

  function qs(){
    const p = new URLSearchParams();
    if(el('since').value) p.set('since', el('since').value);
    if(el('until').value) p.set('until', el('until').value);
    p.set('page', state.page);
    p.set('perPage', state.perPage);
    return p.toString();
  }

  async function load(){
    const r = await fetch('my_interactions.php?action=calls&' + qs());
    const j = await r.json();

    // KPIs
    el('k_calls').textContent = j.kpi?.calls ?? 0;
    el('k_rate').textContent  = (j.kpi?.rate ?? 0) + '%';
    el('k_connMiss').textContent = `${j.kpi?.connected ?? 0} connected • ${j.kpi?.missed ?? 0} missed`;
    el('k_avg').textContent   = j.kpi?.avg ?? '00:00:00';

    // tabla
    const tb = el('tbl').querySelector('tbody');
    tb.innerHTML = '';
    (j.rows||[]).forEach(row=>{
      const tr = document.createElement('tr');
      const when = (row.when||'').replace('T',' ').replace('Z','');
      const rec = row.rec ? `
        <div style="display:flex;gap:6px;align-items:center;min-height:32px">
          <audio controls preload="none" style="height:30px;width:200px">
            <source src="${row.rec}" type="audio/wav">
          </audio>
          <a class="pill" href="${row.rec}" download>Download</a>
        </div>` : '—';
      tr.innerHTML = `
        <td>${when}</td>
        <td>${row.agent||'—'}</td>
        <td>${row.dir||'—'}</td>
        <td>${row.status||'—'}</td>
        <td>${row.peer||'—'}</td>
        <td>${row.dur||'00:00:00'}</td>
        <td>${rec}</td>`;
      tb.appendChild(tr);
    });

    // paginación
    state.page  = j.page || 1;
    state.pages = j.pages || 1;
    el('pageInfo').textContent = `Page ${state.page} of ${state.pages}`;
    el('prev').disabled = state.page <= 1;
    el('next').disabled = state.page >= state.pages;
  }

  function exportCSV(){
    const p = new URLSearchParams(qs());
    window.open('my_interactions.php?action=calls&' + p.toString(), '_blank');
    // (tip: si quieres CSV real, crea un action=export como en monitor.php)
  }

  // init
  setRange(30);
  who();
  load();

  // events
  el('btn7').onclick = ()=>{ setRange(7); state.page=1; load(); };
  el('btn30').onclick= ()=>{ setRange(30); state.page=1; load(); };
  el('apply').onclick= ()=>{ state.page=1; load(); };
  el('csv').onclick  = exportCSV;
  el('perPage').addEventListener('change', ()=>{
    let v = parseInt(el('perPage').value||'25',10);
    if (isNaN(v)||v<10) v=25; if (v>100) v=100;
    state.perPage=v; state.page=1; load();
  });
  el('prev').onclick = ()=>{ if(state.page>1){ state.page--; load(); } };
  el('next').onclick = ()=>{ if(state.page<state.pages){ state.page++; load(); } };
})();
</script>
</body>
</html>
