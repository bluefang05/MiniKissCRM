<?php
// admin/referrals.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

if (!Auth::check() || !array_intersect(['admin','owner'], Auth::user()['roles'] ?? [])) {
  die('<div class="container"><h1>Access Denied</h1></div>');
}

$pdo = getPDO();

/** Filtros */
$mode      = $_GET['mode']      ?? 'direct';           // direct | stats | tree
$recruiter = isset($_GET['recruiter']) ? (int)$_GET['recruiter'] : 0;
$search    = trim($_GET['search'] ?? '');
$minKids   = isset($_GET['min_kids']) ? max(0, (int)$_GET['min_kids']) : 0;

/** Para selector de usuarios */
$allUsers = $pdo->query("SELECT id, name FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/** Datos segun modo */
$rows = [];

switch ($mode) {
  case 'stats':
    // Estadísticas por reclutador (hijos directos)
    $sql = "
      SELECT
        p.id                             AS recruiter_id,
        p.name                           AS recruiter_name,
        p.email                          AS recruiter_email,
        COUNT(ur.child_id)               AS direct_children,
        COALESCE(SUM(c.status='active'),0) AS active_children,
        MIN(ur.created_at)               AS first_referral_at,
        MAX(ur.created_at)               AS last_referral_at
      FROM users p
      LEFT JOIN user_referrals ur ON ur.parent_id = p.id
      LEFT JOIN users c           ON c.id = ur.child_id
      WHERE 1=1
    ";
    $p   = [];
    if ($recruiter) { $sql .= " AND p.id = ?"; $p[] = $recruiter; }
    if ($search !== '') {
      $sql .= " AND (p.name LIKE ? OR p.email LIKE ?)";
      $p[] = "%$search%"; $p[] = "%$search%";
    }
    $sql .= " GROUP BY p.id";
    if ($minKids) { $sql .= " HAVING direct_children >= ?"; $p[] = $minKids; }
    $sql .= " ORDER BY direct_children DESC, recruiter_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($p);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    break;

  case 'tree':
    // Árbol completo desde un reclutador (incluye el root como depth 0)
    if ($recruiter) {
      $stmt = $pdo->prepare("
        WITH RECURSIVE tree AS (
          SELECT
            p.id AS user_id,
            p.id AS parent_id,
            0     AS depth,
            CAST(LPAD(p.id,10,'0') AS CHAR(255)) AS path
          FROM users p
          WHERE p.id = ?

          UNION ALL

          SELECT
            ur.child_id AS user_id,
            ur.parent_id,
            t.depth + 1 AS depth,
            CONCAT(t.path,'/', LPAD(ur.child_id,10,'0')) AS path
          FROM user_referrals ur
          JOIN tree t ON ur.parent_id = t.user_id
        )
        SELECT
          t.depth,
          u.id      AS user_id,
          u.name    AS user_name,
          u.email   AS user_email,
          u.status  AS user_status,
          t.path
        FROM tree t
        JOIN users u ON u.id = t.user_id
        ORDER BY t.path
      ");
      $stmt->execute([$recruiter]);
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
      $rows = [];
    }
    break;

  case 'direct':
  default:
    // Listado de relaciones directas (parent -> child)
    $sql = "
      SELECT
        ur.parent_id,
        p.name   AS parent_name,
        ur.child_id,
        c.name   AS child_name,
        c.email  AS child_email,
        c.status AS child_status,
        ur.created_at AS referred_at
      FROM user_referrals ur
      JOIN users p ON p.id = ur.parent_id
      JOIN users c ON c.id = ur.child_id
      WHERE 1=1
    ";
    $p   = [];
    if ($recruiter) { $sql .= " AND ur.parent_id = ?"; $p[] = $recruiter; }
    if ($search !== '') {
      $sql .= " AND (c.name LIKE ? OR c.email LIKE ? OR p.name LIKE ?)";
      $p[] = "%$search%"; $p[] = "%$search%"; $p[] = "%$search%";
    }
    $sql .= " ORDER BY ur.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($p);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    break;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Referrals</title>
  <link rel="stylesheet" href="../assets/css/app.css">
  <style>
    .filters-form { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; margin-bottom:1rem; }
    .filters-form input[type="text"], .filters-form input[type="number"], .filters-form select { min-width:180px; }
    .indent-1 { padding-left: 1rem; }
    .indent-2 { padding-left: 2rem; }
    .indent-3 { padding-left: 3rem; }
    .muted { color:#666; font-size:.9em; }
    .header-actions { display:flex; align-items:center; gap:1rem; justify-content:space-between; margin-bottom:1rem; }
    .header-actions h1 { margin:0; }
    .table td small { color:#666; }
  </style>
</head>
<body>
<div class="container">

  <div class="header-actions">
    <a href="../leads/list.php" class="btn btn-secondary">&larr; Back to leads</a>
    <h1>Referrals</h1>
    <div></div>
  </div>

  <form class="filters-form" method="get">
    <select name="mode">
      <option value="direct" <?= $mode==='direct'?'selected':''?>>Direct (parent → child)</option>
      <option value="stats"  <?= $mode==='stats'?'selected':''?>>Stats (per recruiter)</option>
      <option value="tree"   <?= $mode==='tree'?'selected':''?>>Tree (hierarchy)</option>
    </select>

    <select name="recruiter">
      <option value="0">Any recruiter</option>
      <?php foreach ($allUsers as $u): ?>
        <option value="<?= $u['id'] ?>" <?= $recruiter==$u['id']?'selected':''?>><?= htmlspecialchars($u['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <?php if ($mode==='stats'): ?>
      <input type="number" name="min_kids" min="0" value="<?= $minKids ?>" placeholder="Min. children">
    <?php endif; ?>

    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name/email">
    <button class="btn" type="submit">Filter</button>
    <a class="btn btn-secondary" href="referrals.php">Clear</a>
  </form>

  <?php if ($mode==='stats'): ?>

    <table class="table">
      <thead>
        <tr>
          <th>Recruiter</th>
          <th>Email</th>
          <th># Direct</th>
          <th># Active</th>
          <th>First</th>
          <th>Last</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" style="text-align:center">No data.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['recruiter_name']) ?></td>
            <td><?= htmlspecialchars($r['recruiter_email']) ?></td>
            <td><?= (int)$r['direct_children'] ?></td>
            <td><?= (int)$r['active_children'] ?></td>
            <td class="muted"><?= htmlspecialchars($r['first_referral_at'] ?? '') ?></td>
            <td class="muted"><?= htmlspecialchars($r['last_referral_at'] ?? '') ?></td>
            <td>
              <?php if (!empty($r['recruiter_id'])): ?>
                <a class="btn btn-sm" href="referrals.php?mode=tree&recruiter=<?= (int)$r['recruiter_id'] ?>">See tree</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

  <?php elseif ($mode==='tree'): ?>

    <?php if (!$recruiter): ?>
      <p class="muted">Choose a recruiter to see their hierarchy.</p>
    <?php endif; ?>

    <table class="table">
      <thead>
        <tr>
          <th>Depth</th>
          <th>User</th>
          <th>Email</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($recruiter && !$rows): ?>
          <tr><td colspan="4" style="text-align:center">No descendants.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['depth'] ?></td>
            <td class="indent-<?= min(3,(int)$r['depth']) ?>">
              <?= htmlspecialchars($r['user_name']) ?> <small>(ID <?= (int)$r['user_id'] ?>)</small>
            </td>
            <td><?= htmlspecialchars($r['user_email']) ?></td>
            <td><?= htmlspecialchars(ucfirst($r['user_status'])) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

  <?php else: /* direct */ ?>

    <table class="table">
      <thead>
        <tr>
          <th>Recruiter</th>
          <th>Recruit</th>
          <th>Recruit Email</th>
          <th>Status</th>
          <th>Referred At</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="5" style="text-align:center">No referrals.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['parent_name']) ?> <small>(ID <?= (int)$r['parent_id'] ?>)</small></td>
            <td><?= htmlspecialchars($r['child_name']) ?> <small>(ID <?= (int)$r['child_id'] ?>)</small></td>
            <td><?= htmlspecialchars($r['child_email']) ?></td>
            <td><?= htmlspecialchars(ucfirst($r['child_status'])) ?></td>
            <td class="muted"><?= htmlspecialchars($r['referred_at']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

  <?php endif; ?>

</div>
</body>
</html>
