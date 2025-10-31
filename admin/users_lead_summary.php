<?php
// admin/users_lead_summary.php
// Unified view: per-user summary + filters (date/user) + selected user detail + CSV export
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}
$me    = Auth::user();
$roles = $me['roles'] ?? [];

// If Auth::user() didn't include roles, backfill from DB
if (!$roles) {
    $pdoTmp = getPDO();
    $stmt = $pdoTmp->prepare("
        SELECT r.name
        FROM user_roles ur
        JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = ?
    ");
    $stmt->execute([(int)$me['id']]);
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Access: admin or owner
if (!in_array('admin', $roles, true) && !in_array('owner', $roles, true)) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Access denied.';
    exit;
}

$pdo = getPDO();
@$pdo->query("SET SESSION group_concat_max_len = 8192");

// Dispositions (per your dump)
const DISP_SOLD_ID = 5; // Service Sold
const DISP_DNC_ID  = 6; // Do Not Call Again

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// --------- Filters ---------
$today = (new DateTime('today'))->format('Y-m-d');
$defaultFrom = (new DateTime('today -30 days'))->format('Y-m-d');

$from = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) ? $_GET['from'] : $defaultFrom;
$to   = (isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']))   ? $_GET['to']   : $today;
$userFilter = isset($_GET['user_id']) ? max(0, (int)$_GET['user_id']) : 0;

// Users list for select
$users = $pdo->query("SELECT id, name FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// WHERE for interactions in range
$conds = [];
$params = [];
if ($from) { $conds[] = "i.interaction_time >= ?"; $params[] = $from.' 00:00:00'; }
if ($to)   { $conds[] = "i.interaction_time <= ?"; $params[] = $to  .' 23:59:59'; }
$where = $conds ? 'WHERE '.implode(' AND ', $conds) : '';

// --------- Per-user summary ---------
$sqlSummary = "
SELECT
  u.id                                      AS user_id,
  u.name                                    AS username,
  COUNT(i.id)                               AS total_interactions,
  COUNT(DISTINCT i.lead_id)                 AS total_leads_interacted,
  SUM(CASE WHEN i.disposition_id = ".DISP_SOLD_ID." THEN 1 ELSE 0 END) AS closed_sales,
  SUM(CASE WHEN i.disposition_id = ".DISP_DNC_ID."  THEN 1 ELSE 0 END) AS dnc_marks,
  AVG(i.duration_seconds)                   AS avg_duration_seconds,
  GROUP_CONCAT(
    DISTINCT CASE WHEN i.disposition_id = ".DISP_SOLD_ID."
      THEN CONCAT(l.first_name, ' ', l.last_name, ' (#', l.id, ')')
    END ORDER BY l.last_name SEPARATOR ', '
  ) AS clients_sold
FROM interactions i
JOIN users u ON u.id = i.user_id
JOIN leads l ON l.id = i.lead_id
{$where}
GROUP BY u.id, u.name
HAVING total_interactions > 0
ORDER BY closed_sales DESC, total_interactions DESC
";
$st = $pdo->prepare($sqlSummary);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Global KPIs
$totUsers        = count($rows);
$totInteractions = array_sum(array_map(fn($r)=>(int)$r['total_interactions'], $rows));
$totSales        = array_sum(array_map(fn($r)=>(int)$r['closed_sales'], $rows));
$globalConv      = $totInteractions > 0 ? round(($totSales * 100.0) / $totInteractions) : 0;

// --------- CSV export (summary / detail) ---------
if (isset($_GET['export']) && $_GET['export'] === 'summary') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="users_summary_'.$from.'_to_'.$to.'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['User', 'Interactions', 'Leads touched', 'Sales', 'DNC', 'Avg duration (s)']);
    foreach ($rows as $r) {
        $avg = $r['avg_duration_seconds'] !== null ? round((float)$r['avg_duration_seconds']) : 0; // cast fix
        fputcsv($out, [
            $r['username'],
            (int)$r['total_interactions'],
            (int)$r['total_leads_interacted'],
            (int)$r['closed_sales'],
            (int)$r['dnc_marks'],
            $avg,
        ]);
    }
    fclose($out);
    exit;
}

// If a user is selected: details
$detail = null;
$detailDisps = $detailTimeline = $detailClients = $detailLast = [];
if ($userFilter > 0) {
    $condsDetail = ["i.user_id = ?"];
    $paramsDetail = [$userFilter];
    if ($from) { $condsDetail[] = "i.interaction_time >= ?"; $paramsDetail[] = $from.' 00:00:00'; }
    if ($to)   { $condsDetail[] = "i.interaction_time <= ?"; $paramsDetail[] = $to  .' 23:59:59'; }
    $whereD = 'WHERE '.implode(' AND ', $condsDetail);

    // User name
    $qName = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $qName->execute([$userFilter]);
    $detailName = (string)($qName->fetchColumn() ?: 'User #'.$userFilter);

    // Detail KPIs
    $sqlKpi = "
      SELECT
        COUNT(i.id) AS interactions,
        COUNT(DISTINCT i.lead_id) AS leads_touched,
        SUM(CASE WHEN i.disposition_id = ".DISP_SOLD_ID." THEN 1 ELSE 0 END) AS sales,
        SUM(CASE WHEN i.disposition_id = ".DISP_DNC_ID."  THEN 1 ELSE 0 END) AS dnc,
        AVG(i.duration_seconds) AS avg_seconds,
        MIN(i.interaction_time) AS first_at,
        MAX(i.interaction_time) AS last_at
      FROM interactions i
      {$whereD}
    ";
    $qk = $pdo->prepare($sqlKpi); $qk->execute($paramsDetail); $detail = $qk->fetch(PDO::FETCH_ASSOC) ?: [];

    // Dispositions
    $sqlDisp = "
      SELECT d.name, COUNT(*) AS c
      FROM interactions i
      JOIN dispositions d ON d.id = i.disposition_id
      {$whereD}
      GROUP BY d.id, d.name
      ORDER BY c DESC
    ";
    $qd = $pdo->prepare($sqlDisp); $qd->execute($paramsDetail); $detailDisps = $qd->fetchAll(PDO::FETCH_ASSOC);

    // Daily timeline
    $sqlTimeline = "
      SELECT DATE(i.interaction_time) AS d,
             COUNT(*) AS total,
             SUM(CASE WHEN i.disposition_id = ".DISP_SOLD_ID." THEN 1 ELSE 0 END) AS sold
      FROM interactions i
      {$whereD}
      GROUP BY DATE(i.interaction_time)
      ORDER BY d
    ";
    $qt = $pdo->prepare($sqlTimeline); $qt->execute($paramsDetail); $detailTimeline = $qt->fetchAll(PDO::FETCH_ASSOC);

    // Clients sold by user in range
    $sqlClients = "
      SELECT DISTINCT l.id, l.first_name, l.last_name
      FROM interactions i
      JOIN leads l ON l.id = i.lead_id
      {$whereD} AND i.disposition_id = ".DISP_SOLD_ID."
      ORDER BY l.last_name, l.first_name
    ";
    $qc = $pdo->prepare($sqlClients); $qc->execute($paramsDetail); $detailClients = $qc->fetchAll(PDO::FETCH_ASSOC);

    // Latest interaction per lead (for user in range)
    $sqlLast = "
      SELECT i2.interaction_time, i2.lead_id, l.first_name, l.last_name, d.name AS disposition
      FROM (
        SELECT i.lead_id, MAX(i.id) AS last_id
        FROM interactions i
        {$whereD}
        GROUP BY i.lead_id
      ) t
      JOIN interactions i2 ON i2.id = t.last_id
      JOIN leads l ON l.id = i2.lead_id
      JOIN dispositions d ON d.id = i2.disposition_id
      ORDER BY i2.interaction_time DESC
    ";
    $ql = $pdo->prepare($sqlLast); $ql->execute($paramsDetail); $detailLast = $ql->fetchAll(PDO::FETCH_ASSOC);

    // Detail export (raw interactions)
    if (isset($_GET['export']) && $_GET['export'] === 'detail') {
        $sqlRaw = "
          SELECT i.interaction_time, l.first_name, l.last_name, d.name AS disposition, i.duration_seconds, i.notes
          FROM interactions i
          JOIN leads l ON l.id = i.lead_id
          JOIN dispositions d ON d.id = i.disposition_id
          {$whereD}
          ORDER BY i.interaction_time
        ";
        $qr = $pdo->prepare($sqlRaw); $qr->execute($paramsDetail); $raw = $qr->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="user_'.$userFilter.'_detail_'.$from.'_to_'.$to.'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date/Time','Lead','Disposition','Duration(s)','Notes']);
        foreach ($raw as $r) {
            $leadName = trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? ''));
            fputcsv($out, [
                $r['interaction_time'],
                $leadName,
                $r['disposition'],
                (int)($r['duration_seconds'] ?? 0),
                preg_replace('/\s+/', ' ', (string)($r['notes'] ?? ''))
            ]);
        }
        fclose($out);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Leads Sales Performance</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <style>
    :root{--bg:#f8fafc;--card:#fff;--br:#e5e7eb;--tx:#0f172a;--muted:#64748b;--primary:#0d6efd;--ok:#16a34a;--warn:#f59e0b;--chip:#eef2ff}
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--tx);font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial}
    .container{max-width:1200px;margin:24px auto;padding:0 16px}
    .actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
    .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;border:0;text-decoration:none;color:#fff;background:var(--primary);cursor:pointer}
    .btn.secondary{background:#6c757d}
    .btn.outline{background:transparent;color:var(--primary);border:1px solid var(--primary)}
    .card{background:var(--card);border:1px solid var(--br);border-radius:12px;padding:16px;margin-bottom:12px}
    .grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
    .kpi{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:12px;padding:16px;text-align:center}
    .muted{color:var(--muted)}
    .filters{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
    .input,select{padding:8px 10px;border:1px solid var(--br);border-radius:10px;background:#fff}
    table{width:100%;border-collapse:collapse;background:#fff}
    th,td{padding:10px;border-bottom:1px solid #eef2f7;text-align:left;vertical-align:top}
    .chip{display:inline-flex;align-items:center;gap:6px;background:var(--chip);border-radius:999px;padding:6px 10px}
    .avatar{width:32px;height:32px;border-radius:999px;background:#e2e8f0;color:#334155;display:inline-flex;align-items:center;justify-content:center;font-weight:700;margin-right:8px}
    .progress{height:8px;background:#f1f5f9;border-radius:999px;overflow:hidden}
    .fill{height:8px;background:var(--ok)}
    .table-wrap{overflow:auto}
    @media (max-width:900px){.grid4{grid-template-columns:1fr 1fr}}
    @media (max-width:600px){.grid4{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <div class="container">
    <div class="actions">
      <a class="btn secondary" href="../leads/list.php"><i class="fas fa-arrow-left"></i> Back to Leads</a>
      <a class="btn outline" href="?from=<?=h($from)?>&to=<?=h($to)?>&export=summary"><i class="fas fa-file-export"></i> Export summary CSV</a>
      <?php if ($userFilter>0): ?>
        <a class="btn outline" href="?from=<?=h($from)?>&to=<?=h($to)?>&user_id=<?=$userFilter?>&export=detail"><i class="fas fa-file-export"></i> Export detail CSV</a>
      <?php endif; ?>
    </div>

    <h1><i class="fas fa-users"></i> Leads Sales Performance</h1>
    <p class="muted">Default range: last 30 days. Adjust the filters.</p>

    <!-- Filters -->
    <form class="card filters" method="get">
      <div>
        <label>From</label><br>
        <input class="input" type="date" name="from" value="<?=h($from)?>">
      </div>
      <div>
        <label>To</label><br>
        <input class="input" type="date" name="to" value="<?=h($to)?>">
      </div>
      <div style="min-width:220px;">
        <label>User</label><br>
        <select class="input" name="user_id">
          <option value="0">All</option>
          <?php foreach ($users as $u): ?>
            <option value="<?=$u['id']?>" <?=$userFilter===(int)$u['id']?'selected':''?>><?=h($u['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <button class="btn" type="submit"><i class="fas fa-filter"></i> Filter</button>
        <a class="btn secondary" href="users_lead_summary.php"><i class="fas fa-rotate"></i> Reset</a>
      </div>
      <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;">
        <?php
          $p7 = (new DateTime('today -6 days'))->format('Y-m-d');
          $p30= (new DateTime('today -29 days'))->format('Y-m-d');
          $p90= (new DateTime('today -89 days'))->format('Y-m-d');
        ?>
        <a class="btn outline" href="?from=<?=$p7?>&to=<?=$today?>&user_id=<?=$userFilter?>">Last 7d</a>
        <a class="btn outline" href="?from=<?=$p30?>&to=<?=$today?>&user_id=<?=$userFilter?>">Last 30d</a>
        <a class="btn outline" href="?from=<?=$p90?>&to=<?=$today?>&user_id=<?=$userFilter?>">Last 90d</a>
      </div>
    </form>

    <!-- Global KPIs -->
    <div class="grid4">
      <div class="kpi"><i class="fas fa-user"></i><h3><?=$totUsers?></h3><div>Users (with activity)</div></div>
      <div class="kpi"><i class="fas fa-phone"></i><h3><?=$totInteractions?></h3><div>Interactions</div></div>
      <div class="kpi"><i class="fas fa-trophy"></i><h3><?=$totSales?></h3><div>Sales</div></div>
      <div class="kpi"><i class="fas fa-chart-line"></i><h3><?=$globalConv?>%</h3><div>Conversion (sales/interactions)</div></div>
    </div>

    <!-- Per-user summary table -->
    <div class="card table-wrap">
      <table>
        <thead>
          <tr>
            <th>User</th>
            <th>Interactions</th>
            <th>Leads touched</th>
            <th>Sales</th>
            <th>DNC</th>
            <th>Avg. duration</th>
            <th>Clients sold</th>
            <th>View</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" style="text-align:center;">No data in the selected range.</td></tr>
        <?php else:
          foreach ($rows as $r):
            $avgSeconds = $r['avg_duration_seconds'] !== null ? (float)$r['avg_duration_seconds'] : 0.0; // cast fix
            $avg = $avgSeconds > 0 ? (int)round($avgSeconds) : 0;                                       // cast fix
            $m = intdiv($avg,60); $s=$avg%60;
            $initials = strtoupper(implode('', array_map(fn($w)=>$w!==''?mb_substr($w,0,1):'', preg_split('/\s+/', (string)$r['username']))));
            $link = '?from='.rawurlencode($from).'&to='.rawurlencode($to).'&user_id='.$r['user_id'].'#details';
        ?>
          <tr>
            <td><span class="avatar"><?=h($initials)?></span><?=h($r['username'])?></td>
            <td><span class="chip"><i class="fas fa-phone"></i><?= (int)$r['total_interactions']?></span></td>
            <td><span class="chip"><i class="fas fa-users"></i><?= (int)$r['total_leads_interacted']?></span></td>
            <td><span class="chip"><i class="fas fa-trophy"></i><?= (int)$r['closed_sales']?></span></td>
            <td><span class="chip"><i class="fas fa-ban"></i><?= (int)$r['dnc_marks']?></span></td>
            <td><?= $m ?>m <?= $s ?>s</td>
            <td style="max-width:320px;"><?= h($r['clients_sold'] ?: '—') ?></td>
            <td><a class="btn" href="<?=$link?>"><i class="fas fa-chart-bar"></i> Details</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Selected user details -->
    <?php if ($userFilter>0): 
      $detailInteractions = (int)($detail['interactions'] ?? 0);
      $conv = $detailInteractions > 0 ? round(((int)$detail['sales'] * 100.0) / $detailInteractions) : 0;
      $avgSFloat = $detail['avg_seconds'] !== null ? (float)$detail['avg_seconds'] : 0.0; // cast fix
      $avgS = (int)round($avgSFloat);                                                     // cast fix
      $mm=intdiv($avgS,60); $ss=$avgS%60;

      // Pretty name
      $detailUserName = '';
      foreach ($users as $u) { if ((int)$u['id']===$userFilter) {$detailUserName = $u['name']; break;} }
      if ($detailUserName==='') $detailUserName = 'User #'.$userFilter;
    ?>
      <div id="details"></div>
      <h2><i class="fas fa-user"></i> Report for <?=h($detailUserName)?></h2>
      <p class="muted">Range: <?=h($from)?> to <?=h($to)?></p>

      <div class="grid4">
        <div class="kpi"><i class="fas fa-phone"></i><h3><?= $detailInteractions ?></h3><div>Interactions</div></div>
        <div class="kpi"><i class="fas fa-users"></i><h3><?= (int)($detail['leads_touched'] ?? 0) ?></h3><div>Leads touched</div></div>
        <div class="kpi"><i class="fas fa-trophy"></i><h3><?= (int)($detail['sales'] ?? 0) ?></h3><div>Sales</div></div>
        <div class="kpi"><i class="fas fa-chart-line"></i><h3><?=$conv?>%</h3><div>Conversion</div></div>
      </div>

      <div class="card">
        <strong>Average duration:</strong> <?=$mm?>m <?=$ss?>s
        <span class="muted"> | First contact: <?=h($detail['first_at'] ?? '—')?> — Last: <?=h($detail['last_at'] ?? '—')?></span>
        <span class="chip" style="margin-left:8px;"><i class="fas fa-ban"></i> DNC: <?= (int)($detail['dnc'] ?? 0) ?></span>
      </div>

      <div class="grid4">
        <div class="card" style="grid-column:1 / -1;">
          <h3><i class="fas fa-list"></i> Dispositions</h3>
          <div class="table-wrap">
          <table>
            <thead><tr><th>Disposition</th><th>Count</th></tr></thead>
            <tbody>
            <?php if (!$detailDisps): ?>
              <tr><td colspan="2">No data.</td></tr>
            <?php else: foreach ($detailDisps as $d): ?>
              <tr><td><?=h($d['name'])?></td><td><?= (int)$d['c'] ?></td></tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
          </div>
        </div>

        <div class="card" style="grid-column:1 / -1;">
          <h3><i class="fas fa-calendar-alt"></i> Daily timeline</h3>
          <?php if (!$detailTimeline): ?>
            <p class="muted">No activity in the selected range.</p>
          <?php else: ?>
            <div class="table-wrap">
            <table>
              <thead><tr><th>Date</th><th>Total</th><th>Sales</th><th>Daily conversion</th></tr></thead>
              <tbody>
              <?php foreach ($detailTimeline as $row):
                $totalDay = (int)$row['total'];
                $soldDay  = (int)$row['sold'];
                $dayRate = $totalDay>0 ? round(($soldDay*100.0)/$totalDay) : 0;
              ?>
                <tr>
                  <td><?= h($row['d']) ?></td>
                  <td><?= $totalDay ?></td>
                  <td><?= $soldDay ?></td>
                  <td>
                    <div class="progress" title="<?=$dayRate?>%">
                      <div class="fill" style="width: <?=$dayRate?>%"></div>
                    </div>
                    <small class="muted"><?=$dayRate?>%</small>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="card">
          <h3><i class="fas fa-star"></i> Clients sold</h3>
          <?php if (!$detailClients): ?>
            <p class="muted">No sales in the selected range.</p>
          <?php else: ?>
            <ul>
              <?php foreach ($detailClients as $c): ?>
                <li>
                  <?= h($c['last_name'].', '.$c['first_name']) ?>
                  — <a class="btn" style="padding:6px 10px;" href="../leads/view.php?id=<?= (int)$c['id'] ?>"><i class="fas fa-eye"></i> View lead</a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <div class="card" style="grid-column:1 / -1;">
          <h3><i class="fas fa-clock-rotate-left"></i> Latest status per lead (for user)</h3>
          <div class="table-wrap">
          <table>
            <thead><tr><th>Date/Time</th><th>Lead</th><th>Disposition</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$detailLast): ?>
              <tr><td colspan="4">No data.</td></tr>
            <?php else: foreach ($detailLast as $r): ?>
              <tr>
                <td><?= h($r['interaction_time']) ?></td>
                <td><?= h(trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? ''))) ?></td>
                <td><span class="chip"><?= h($r['disposition']) ?></span></td>
                <td>
                  <a class="btn" style="padding:6px 10px;" href="../leads/view.php?id=<?= (int)$r['lead_id'] ?>"><i class="fas fa-eye"></i> View</a>
                  <a class="btn" style="padding:6px 10px;background:#10b981" href="../calls/add.php?lead_id=<?= (int)$r['lead_id'] ?>"><i class="fas fa-phone"></i> Register call</a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
