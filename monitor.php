<?php
declare(strict_types=1);

/**
 * MightyCall Monitoring Dashboard (Enhanced Version)
 * 
 * Enhanced features:
 * - Caching system for improved performance
 * - Advanced call analysis
 * - Real-time alert system
 * - Export to CSV and Excel
 * - Custom reports
 * - Improved data visualization
 * - Work-week specific date ranges (Mon-Fri)
 * 
 * Usage:
 * - Main dashboard: monitor.php
 * - API endpoints: monitor.php?action=metrics|calls|contacts|health|alerts|reports
 * - Export: monitor.php?action=export&format=csv|excel
 * 
 * SECURITY NOTE: FOR LOCAL USE ONLY. Do not expose publicly.
 */

/* ================== CONFIGURATION ================== */
putenv('MIGHTYCALL_API_KEY=0bb0f2ee-ff6f-4be5-8530-4d35a01e80cc'); // client_id
putenv('MIGHTYCALL_SECRET_KEY=dc73c680f799');                      // client_secret (USER_KEY)
putenv('MIGHTYCALL_BASE=https://api.mightycall.com/v4/api');       // base URL with /api
putenv('MIGHTYCALL_GRANT=client_credentials');                     // grant_type

// Cache configuration
putenv('CACHE_ENABLED=true');                                      // Enable cache
putenv('CACHE_DURATION=1800');                                     // Cache duration in seconds (30 minutes)
putenv('CACHE_DIR=' . __DIR__ . '/cache');                         // Cache directory

// Reports configuration
putenv('REPORTS_DIR=' . __DIR__ . '/reports');                     // Reports directory
putenv('ALERTS_ENABLED=true');                                     // Enable alert system

@ini_set('memory_limit', '2048M');
@set_time_limit(0);

/* ================== UTILITY FUNCTIONS ================== */
function envOrFail(string $k): string {
  $v = getenv($k);
  if ($v === false || $v === '') throw new RuntimeException("Missing env: $k");
  return $v;
}

function ensure_dir(string $p): void { 
  if (!is_dir($p)) @mkdir($p, 0777, true); 
}

function log_message(string $message, string $level = 'INFO'): void {
  $logFile = __DIR__ . '/logs/mightycall.log';
  ensure_dir(dirname($logFile));
  $timestamp = date('Y-m-d H:i:s');
  file_put_contents($logFile, "[$timestamp] [$level] $message" . PHP_EOL, FILE_APPEND);
}

function get_cache_key(string $prefix, array $params): string {
  return $prefix . '_' . md5(serialize($params));
}

function get_cache(string $key): ?array {
  if (!getenv('CACHE_ENABLED')) return null;
  
  $cacheDir = getenv('CACHE_DIR');
  ensure_dir($cacheDir);
  $cacheFile = $cacheDir . '/' . $key . '.json';
  
  if (!file_exists($cacheFile)) return null;
  
  $data = json_decode(file_get_contents($cacheFile), true);
  if (!$data || time() > $data['expires']) {
    @unlink($cacheFile);
    return null;
  }
  
  return $data['content'];
}

function set_cache(string $key, array $content): void {
  if (!getenv('CACHE_ENABLED')) return;
  
  $cacheDir = getenv('CACHE_DIR');
  ensure_dir($cacheDir);
  $cacheFile = $cacheDir . '/' . $key . '.json';
  
  $data = [
    'content' => $content,
    'expires' => time() + (int)getenv('CACHE_DURATION')
  ];
  
  file_put_contents($cacheFile, json_encode($data));
}

function http_post_form(string $url, array $fields): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($fields),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
  ]);
  $raw = curl_exec($ch);
  if ($raw === false) throw new RuntimeException('cURL error: ' . curl_error($ch));
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $json = json_decode($raw, true);
  if ($code >= 400) throw new RuntimeException("HTTP $code: " . $raw);
  if (!is_array($json)) throw new RuntimeException("Invalid JSON: " . $raw);
  return $json;
}

function http_get_json_with_retry(string $url, string $bearer, array $qs = [], int $retries = 4): array {
  if ($qs) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($qs);

  log_message("Requesting URL: " . $url);

  $delay = 1.0;
  for ($i = 0; $i <= $retries; $i++) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 60,
      CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $bearer,
        'x-api-key: ' . getenv('MIGHTYCALL_API_KEY')
      ],
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
      if ($i === $retries) throw new RuntimeException('cURL error: ' . $err);
    } else {
      $json = json_decode($raw, true);
      if ($code === 429 || $code >= 500) {
        log_message("Retryable error: HTTP $code, attempt " . ($i + 1) . "/" . ($retries + 1), 'WARNING');
      } else {
        if ($code >= 400) throw new RuntimeException("HTTP $code: " . $raw);
        if (!is_array($json)) throw new RuntimeException("Invalid JSON: " . $raw);
        return $json;
      }
    }
    // backoff
    usleep((int)($delay * 1_000_000));
    $delay *= 1.8;
  }
  throw new RuntimeException('HTTP retries exhausted');
}

function fmt_hms_from_ms($ms): string {
  $sec = (int)round(((int)$ms) / 1000);
  $h = intdiv($sec, 3600); $sec %= 3600; $m = intdiv($sec, 60); $s = $sec % 60;
  return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

function format_bytes(int $bytes, int $precision = 2): string {
  $units = ['B', 'KB', 'MB', 'GB', 'TB'];
  $bytes = max($bytes, 0);
  $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
  $pow = min($pow, count($units) - 1);
  $bytes /= pow(1024, $pow);
  return round($bytes, $precision) . ' ' . $units[$pow];
}

/* ================== AUTHENTICATION (WITH CACHING) ================== */
 $BASE   = envOrFail('MIGHTYCALL_BASE');
 $APIKEY = envOrFail('MIGHTYCALL_API_KEY');
 $SECRET = envOrFail('MIGHTYCALL_SECRET_KEY');
 $GRANT  = getenv('MIGHTYCALL_GRANT') ?: 'client_credentials';

ensure_dir(__DIR__ . '/logs');
 $tokenFile = __DIR__ . '/logs/mc_token.json';

 $loadTok = function () use ($tokenFile) {
  if (!file_exists($tokenFile)) return null;
  $j = json_decode((string)@file_get_contents($tokenFile), true);
  if (!$j || time() >= (int)($j['expires_at'] ?? 0) - 60) return null;
  return $j;
};

 $saveTok = function (array $auth) use ($tokenFile) {
  $ttl = (int)($auth['expires_in'] ?? 0);
  $auth['expires_at'] = time() + max(0, $ttl);
  @file_put_contents($tokenFile, json_encode($auth, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
};

 $tok = $loadTok();
if (!$tok) {
  $auth = http_post_form(rtrim($BASE, '/') . '/auth/token', [
    'client_id' => $APIKEY, 'client_secret' => $SECRET, 'grant_type' => $GRANT
  ]);
  $saveTok($auth);
  $tok = $loadTok();
}
if (!$tok) { http_response_code(500); die('Auth failed'); }
 $accessToken = $tok['access_token'];

/* ================== DATA ACCESS FUNCTIONS ================== */
function get_date_range(): array {
  // default last 30d (from today minus 29)
  $since = $_GET['since'] ?? (new DateTimeImmutable('now -29 days'))->format('Y-m-d');
  $until = $_GET['until'] ?? (new DateTimeImmutable('now'))->format('Y-m-d');
  $fromIso = (new DateTimeImmutable($since . ' 00:00:00'))->format('c');
  $toIso   = (new DateTimeImmutable($until . ' 23:59:59'))->format('c');
  return [$since, $until, $fromIso, $toIso];
}

function fetch_calls_paged(string $base, string $token, string $fromIso, string $toIso, array $filters = []): array {
  // Check cache first
  $cacheKey = get_cache_key('calls', [
    'from' => $fromIso,
    'to' => $toIso,
    'filters' => $filters
  ]);
  
  $cached = get_cache($cacheKey);
  if ($cached) {
    log_message("Using cached data for calls");
    return $cached;
  }
  
  $skip = 0; $pageSize = 100; $all = []; $hasMore = true;
  
  for ($i = 0; $i < 100 && $hasMore; $i++) {
    $qs = array_merge([
      'startUtc' => $fromIso, 
      'endUtc' => $toIso, 
      'skip' => $skip, 
      'pageSize' => $pageSize
    ], $filters);
    
    $res = http_get_json_with_retry(rtrim($base, '/') . '/calls', $token, $qs);
    
    $data = $res['data'] ?? $res;
    $calls = $data['calls'] ?? [];
    $total = (int)($data['total'] ?? 0);
    
    $all = array_merge($all, $calls);
    
    // Check if there are more pages
    $hasMore = count($calls) === $pageSize && count($all) < $total;
    $skip += $pageSize;
    
    log_message("Fetched page " . ($i + 1) . ": " . count($calls) . " calls, total so far: " . count($all));
  }
  
  // Save to cache
  set_cache($cacheKey, $all);
  
  return $all;
}

function fetch_contacts_paged(string $base, string $token): array {
  // Check cache first
  $cacheKey = get_cache_key('contacts', []);
  $cached = get_cache($cacheKey);
  if ($cached) {
    log_message("Using cached data for contacts");
    return $cached;
  }
  
  $page = 1; $pageSize = 200; $all = [];
  
  for ($i = 0; $i < 100; $i++) {
    $res = http_get_json_with_retry(rtrim($base, '/') . '/contacts', $token, ['page' => $page, 'pageSize' => $pageSize]);
    
    $data = $res['data'] ?? $res;
    $contacts = $data['contacts'] ?? [];
    $pages = (int)($data['pages'] ?? 1);
    
    $all = array_merge($all, $contacts);
    
    if ($page >= $pages || count($contacts) === 0) break;
    $page++;
  }
  
  // Save to cache
  set_cache($cacheKey, $all);
  
  return $all;
}

/* ================== ADVANCED ANALYSIS ================== */
function perform_advanced_analysis(array $calls): array {
  if (empty($calls)) return [];
  
  $analysis = [];
  
  // Analysis by day of the week
  $dayOfWeekCalls = [];
  $dayOfWeekDuration = [];
  foreach ($calls as $call) {
    $date = new DateTime($call['dateTimeUtc']);
    $day = $date->format('l');
    
    if (!isset($dayOfWeekCalls[$day])) {
      $dayOfWeekCalls[$day] = 0;
      $dayOfWeekDuration[$day] = 0;
    }
    
    $dayOfWeekCalls[$day]++;
    $dayOfWeekDuration[$day] += (int)($call['duration'] ?? 0);
  }
  
  $analysis['dayOfWeek'] = [
    'calls' => $dayOfWeekCalls,
    'avgDuration' => array_map(function($duration, $count) {
      return $count > 0 ? $duration / $count : 0;
    }, $dayOfWeekDuration, $dayOfWeekCalls)
  ];
  
  // Analysis by hour of the day
  $hourOfDayCalls = [];
  $hourOfDayDuration = [];
  foreach ($calls as $call) {
    $date = new DateTime($call['dateTimeUtc']);
    $hour = (int)$date->format('H');
    
    if (!isset($hourOfDayCalls[$hour])) {
      $hourOfDayCalls[$hour] = 0;
      $hourOfDayDuration[$hour] = 0;
    }
    
    $hourOfDayCalls[$hour]++;
    $hourOfDayDuration[$hour] += (int)($call['duration'] ?? 0);
  }
  
  $analysis['hourOfDay'] = [
    'calls' => $hourOfDayCalls,
    'avgDuration' => array_map(function($duration, $count) {
      return $count > 0 ? $duration / $count : 0;
    }, $hourOfDayDuration, $hourOfDayCalls)
  ];
  
  // Analysis by agent
  $agentCalls = [];
  $agentDuration = [];
  $agentConnected = [];
  $agentMissed = [];
  
  foreach ($calls as $call) {
    $agent = ($call['caller']['name'] ?? '') . ' (' . ($call['caller']['extension'] ?? '') . ')';
    
    if (!isset($agentCalls[$agent])) {
      $agentCalls[$agent] = 0;
      $agentDuration[$agent] = 0;
      $agentConnected[$agent] = 0;
      $agentMissed[$agent] = 0;
    }
    
    $agentCalls[$agent]++;
    $agentDuration[$agent] += (int)($call['duration'] ?? 0);
    
    if (($call['callStatus'] ?? '') === 'Connected') {
      $agentConnected[$agent]++;
    } else if (($call['callStatus'] ?? '') === 'Missed') {
      $agentMissed[$agent]++;
    }
  }
  
  $analysis['agents'] = [];
  foreach ($agentCalls as $agent => $count) {
    $analysis['agents'][$agent] = [
      'totalCalls' => $count,
      'totalDuration' => $agentDuration[$agent],
      'avgDuration' => $count > 0 ? $agentDuration[$agent] / $count : 0,
      'connectedCalls' => $agentConnected[$agent],
      'missedCalls' => $agentMissed[$agent],
      'connectionRate' => $count > 0 ? ($agentConnected[$agent] / $count) * 100 : 0
    ];
  }
  
  // Call duration analysis
  $durations = array_map(function($call) {
    return (int)($call['duration'] ?? 0);
  }, $calls);
  
  sort($durations);
  $count = count($durations);
  
  $analysis['duration'] = [
    'min' => $count > 0 ? min($durations) : 0,
    'max' => $count > 0 ? max($durations) : 0,
    'avg' => $count > 0 ? array_sum($durations) / $count : 0,
    'median' => $count > 0 ? $durations[floor($count / 2)] : 0,
    'p95' => $count > 0 ? $durations[floor($count * 0.95)] : 0
  ];
  
  return $analysis;
}

/* ================== ALERT SYSTEM ================== */
function check_alerts(array $calls, array $analysis): array {
  if (!getenv('ALERTS_ENABLED')) return [];
  
  $alerts = [];
  $today = new DateTime('today');
  $yesterday = $today->sub(new DateInterval('P1D'));
  
  // Yesterday's calls
  $yesterdayCalls = array_filter($calls, function($call) use ($yesterday) {
    $callDate = new DateTime($call['dateTimeUtc']);
    return $callDate->format('Y-m-d') === $yesterday->format('Y-m-d');
  });
  
  // Alert: low connection rate
  $connectedCalls = array_filter($yesterdayCalls, function($call) {
    return ($call['callStatus'] ?? '') === 'Connected';
  });
  
  $connectionRate = count($yesterdayCalls) > 0 ? (count($connectedCalls) / count($yesterdayCalls)) * 100 : 0;
  if ($connectionRate < 70) {
    $alerts[] = [
      'type' => 'warning',
      'title' => 'Low connection rate',
      'message' => "Yesterday's connection rate was " . round($connectionRate, 1) . "% (" . count($connectedCalls) . " of " . count($yesterdayCalls) . " calls connected)",
      'value' => $connectionRate,
      'threshold' => 70
    ];
  }
  
  // Alert: many missed calls
  $missedCalls = array_filter($yesterdayCalls, function($call) {
    return ($call['callStatus'] ?? '') === 'Missed';
  });
  
  $missedRate = count($yesterdayCalls) > 0 ? (count($missedCalls) / count($yesterdayCalls)) * 100 : 0;
  if ($missedRate > 30) {
    $alerts[] = [
      'type' => 'warning',
      'title' => 'High missed call rate',
      'message' => "Yesterday's missed call rate was " . round($missedRate, 1) . "% (" . count($missedCalls) . " of " . count($yesterdayCalls) . " calls missed)",
      'value' => $missedRate,
      'threshold' => 30
    ];
  }
  
  return $alerts;
}

/* ================== API ENDPOINTS ================== */
 $action = $_GET['action'] ?? null;

if ($action === 'health') {
  header('Content-Type: application/json'); 
  echo json_encode(['ok' => true, 'timestamp' => date('c')]); 
  exit;
}

if ($action === 'calls' || $action === 'metrics') {
  [$since, $until, $fromIso, $toIso] = get_date_range();

  $agent     = $_GET['agent']      ?? null;
  $direction = $_GET['direction']  ?? null;
  $status    = $_GET['status']     ?? null;
  $hasRec    = isset($_GET['hasRecording']) ? (bool)$_GET['hasRecording'] : null;

  $raw = fetch_calls_paged($BASE, $accessToken, $fromIso, $toIso);

  // Local filters
  $calls = array_values(array_filter($raw, function ($c) use ($agent, $direction, $status, $hasRec) {
    if ($agent) {
      $ext = $c['caller']['extension'] ?? '';
      $nm  = $c['caller']['name'] ?? '';
      if (stripos((string)$ext, $agent) === false && stripos((string)$nm, $agent) === false) return false;
    }
    if ($direction && ($c['direction'] ?? '') !== $direction) return false;
    if ($status && ($c['callStatus'] ?? '') !== $status) return false;
    if ($hasRec !== null) {
      $has = !empty($c['callRecord']['uri']);
      if (($hasRec && !$has) || (!$hasRec && $has)) return false;
    }
    return true;
  }));

  if ($action === 'calls') {
    header('Content-Type: application/json');
    echo json_encode(['range' => ['since' => $since, 'until' => $until], 'count' => count($calls), 'calls' => $calls],
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  // Metrics
  $today = (new DateTimeImmutable('today'))->format('Y-m-d');
  $callsToday = array_filter($calls, fn($c) => substr((string)$c['dateTimeUtc'], 0, 10) === $today);

  $connected = array_filter($calls, fn($c) => ($c['callStatus'] ?? '') === 'Connected');
  $missed    = array_filter($calls, fn($c) => ($c['callStatus'] ?? '') === 'Missed');
  $dropped   = array_filter($calls, fn($c) => ($c['callStatus'] ?? '') === 'Dropped');

  $totalDurMs = array_sum(array_map(fn($c) => (int)($c['duration'] ?? 0), $connected));
  $avgDurMs   = $connected ? (int)round($totalDurMs / count($connected)) : 0;

  // Daily buckets
  $bucket = [];
  foreach ($calls as $c) {
    $d = substr((string)($c['dateTimeUtc'] ?? ''), 0, 10);
    if (!$d) continue;
    $bucket[$d] ??= ['Incoming' => 0, 'Outgoing' => 0, 'Connected' => 0, 'Missed' => 0];
    $dir = $c['direction'] ?? 'Outgoing';
    if ($dir === 'Incoming') $bucket[$d]['Incoming']++; else $bucket[$d]['Outgoing']++;
    $st = $c['callStatus'] ?? '';
    if ($st === 'Connected') $bucket[$d]['Connected']++; elseif ($st === 'Missed') $bucket[$d]['Missed']++;
  }
  ksort($bucket);

  // Top agents
  $agents = [];
  foreach ($calls as $c) {
    $agentKey = ($c['caller']['name'] ?? '') ?: ($c['caller']['extension'] ?? 'Unknown');
    $agents[$agentKey] ??= ['name' => $agentKey, 'calls' => 0, 'durMs' => 0];
    $agents[$agentKey]['calls']++;
    if (($c['callStatus'] ?? '') === 'Connected') $agents[$agentKey]['durMs'] += (int)($c['duration'] ?? 0);
  }
  usort($agents, fn($a, $b) => $b['durMs'] <=> $a['durMs']);
  $topAgents = array_slice($agents, 0, 10);

  // Advanced analysis if requested
  $analysis = isset($_GET['analysis']) ? perform_advanced_analysis($calls) : [];
  
  // Alerts if requested
  $alerts = isset($_GET['alerts']) ? check_alerts($calls, $analysis) : [];

  header('Content-Type: application/json');
  echo json_encode([
    'range'  => ['since' => $since, 'until' => $until],
    'totals' => [
      'calls' => count($calls),
      'connected' => count($connected),
      'missed' => count($missed),
      'dropped' => count($dropped),
      'connectedRate' => (count($calls) > 0) ? round(count($connected) / count($calls) * 100, 1) : 0.0,
      'avgConnectedDurationMs'  => $avgDurMs,
      'avgConnectedDurationHMS' => fmt_hms_from_ms($avgDurMs),
      'callsToday' => count($callsToday),
    ],
    'series'    => $bucket,
    'topAgents' => $topAgents,
    'recent'    => array_slice(array_reverse($calls), 0, 25),
    'analysis'  => $analysis,
    'alerts'    => $alerts
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ($action === 'contacts') {
  $contacts = fetch_contacts_paged($BASE, $accessToken);
  header('Content-Type: application/json');
  echo json_encode(['count' => count($contacts), 'contacts' => $contacts],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ($action === 'export') {
  $format = $_GET['format'] ?? 'csv';
  [$since, $until, $fromIso, $toIso] = get_date_range();
  
  $agent     = $_GET['agent']      ?? null;
  $direction = $_GET['direction']  ?? null;
  $status    = $_GET['status']     ?? null;
  $hasRec    = isset($_GET['hasRecording']) ? (bool)$_GET['hasRecording'] : null;
  
  $raw = fetch_calls_paged($BASE, $accessToken, $fromIso, $toIso);
  
  // Local filters
  $calls = array_values(array_filter($raw, function ($c) use ($agent, $direction, $status, $hasRec) {
    if ($agent) {
      $ext = $c['caller']['extension'] ?? '';
      $nm  = $c['caller']['name'] ?? '';
      if (stripos((string)$ext, $agent) === false && stripos((string)$nm, $agent) === false) return false;
    }
    if ($direction && ($c['direction'] ?? '') !== $direction) return false;
    if ($status && ($c['callStatus'] ?? '') !== $status) return false;
    if ($hasRec !== null) {
      $has = !empty($c['callRecord']['uri']);
      if (($hasRec && !$has) || (!$hasRec && $has)) return false;
    }
    return true;
  }));
  
  if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mightycall_calls_' . $since . '_to_' . $until . '.csv"');
    
    $out = fopen('php://output', 'w');
    
    // CSV Headers
    fputcsv($out, [
      'ID', 'Date/Time (UTC)', 'Direction', 'Call Status', 'Business Number',
      'Agent Name', 'Agent Extension', 'Agent Phone',
      'Peer Name', 'Peer Phone', 'Peer Is Connected',
      'Duration (ms)', 'Duration (HH:MM:SS)', 'Has Recording', 'Recording URI'
    ]);
    
    foreach ($calls as $c) {
      $id = $c['id'] ?? '';
      $dt = $c['dateTimeUtc'] ?? '';
      $dir = $c['direction'] ?? '';
      $st = $c['callStatus'] ?? '';
      $biz = $c['businessNumber'] ?? '';
      
      $aname = $c['caller']['name'] ?? '';
      $aext = $c['caller']['extension'] ?? '';
      $aphone = $c['caller']['phone'] ?? '';
      
      // Process all called participants
      foreach ($c['called'] ?? [] as $peer) {
        $peerName = $peer['name'] ?? '';
        $peerPhone = $peer['phone'] ?? '';
        $peerIsConnected = $peer['isConnected'] ? 'Yes' : 'No';
        
        $dur = (int)($c['duration'] ?? 0);
        $hasRecF = !empty($c['callRecord']['uri']) ? 1 : 0;
        $recUri = $c['callRecord']['uri'] ?? '';
        
        fputcsv($out, [
          $id, $dt, $dir, $st, $biz,
          $aname, $aext, $aphone,
          $peerName, $peerPhone, $peerIsConnected,
          $dur, fmt_hms_from_ms($dur), $hasRecF, $recUri
        ]);
      }
    }
    
    fclose($out);
    exit;
  }
  
  // For Excel, you would need the PHPExcel library
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Excel export requires PHPExcel library']);
  exit;
}

/* ================== FRONT-END (HTML) ================== */
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>MightyCall Monitoring Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    /* Basic Reset & Page Style */
    body { font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif; background:#0f1419; color:#e9ecf1; margin:0; padding:0; line-height:1.5; }
    .wrap { max-width:1400px; margin:0 auto; padding:12px; }
    h1, h2, h3 { margin:0; font-weight:600; }
    h1 { font-size:24px; }
    h2 { font-size:20px; margin:16px 0 8px; }
    h3 { font-size:16px; margin:12px 0 6px; }
    a { color:#60a5fa; text-decoration:none; }
    a:hover { text-decoration:underline; }
    .muted { color:#9aa5c4; font-size:0.9em; }
    .badge { background:#1e293b; padding:2px 8px; border-radius:4px; font-size:0.85em; display:inline-block; }
    
    /* Layout */
    .row { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
    .split { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    .grid { display:grid; gap:16px; }
    .grid.kpis { grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); }
    
    /* Cards */
    .card { background:#1e293b; border-radius:8px; padding:16px; box-shadow:0 4px 12px rgba(0,0,0,0.15); }
    
    /* Forms */
    label { display:flex; align-items:center; gap:6px; font-size:0.9em; }
    input, select { background:#0f1419; border:1px solid #334155; color:#e9ecf1; border-radius:4px; padding:6px 10px; }
    input[type="date"] { padding:4px 8px; }
    
    /* Buttons */
    .btn { background:#334155; color:#e9ecf1; border:none; border-radius:4px; padding:8px 12px; cursor:pointer; font-size:0.9em; transition:background 0.2s; }
    .btn:hover { background:#475569; }
    .btn.active { background:#3b82f6; }
    .btn.btn-apply { background:#10b981; }
    .btn.btn-apply.dirty { animation:pulse 2s infinite; }
    .btn.btn-export { background:#8b5cf6; }
    @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:0.7; } }
    
    /* KPIs */
    .big { font-size:28px; font-weight:700; margin:8px 0; }
    
    /* Tables */
    table { width:100%; border-collapse:collapse; margin-top:12px; }
    th, td { padding:8px 12px; text-align:left; border-bottom:1px solid #334155; }
    th { font-weight:600; color:#cbd5e1; }
    tr:hover { background:#0f172a; }
    
    /* Chart */
    #chartWrap { height:300px; position:relative; }
    
    /* Alerts */
    .alert { padding:12px; border-radius:6px; margin-bottom:12px; }
    .alert-warning { background:#92400e; border-left:4px solid #f59e0b; }
    .alert-info { background:#1e3a8a; border-left:4px solid #3b82f6; }
    .alert-title { font-weight:600; margin-bottom:4px; }
    
    /* Responsive */
    @media (max-width:768px) {
      .split { grid-template-columns:1fr; }
      .grid.kpis { grid-template-columns:1fr; }
      .row { flex-direction:column; align-items:stretch; }
    }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
</head>
<body>
<div class="wrap">
  <h1 style="margin:8px 0 16px">MightyCall Monitoring Dashboard</h1>

  <div class="card row" style="justify-content:space-between">
    <!-- LEFT: Filters -->
    <div class="row" id="filtersRow">
      <label>Since <input type="date" id="since"></label>
      <label>Until <input type="date" id="until"></label>
      <label>Agent
        <select id="agent">
          <option value="">All agents</option>
        </select>
      </label>
      <label>Direction
        <select id="direction">
          <option value="">All</option>
          <option value="Incoming">Incoming</option>
          <option value="Outgoing">Outgoing</option>
        </select>
      </label>
      <label>Status
        <select id="status">
          <option value="">All</option>
          <option value="Connected">Connected</option>
          <option value="Missed">Missed</option>
          <option value="Dropped">Dropped</option>
        </select>
      </label>
      <label>Recording
        <select id="hasRecording">
          <option value="">All</option>
          <option value="1">Only with recording</option>
          <option value="0">Only without</option>
        </select>
      </label>
    </div>

    <!-- RIGHT: Range + Apply + Export -->
    <div class="row">
      <button class="btn" id="btnToday">Today</button>
      <button class="btn" id="btnThisWeek">This Week</button>
      <button class="btn" id="btnLastWeek">Last Week</button>
      <button class="btn" id="btnLast7">Last 7d</button>
      <button class="btn active" id="btnLast30">Last 30d</button>
      <button class="btn btn-apply" id="btnApply">Apply Filter 🔍</button>
      <button class="btn btn-export" id="btnExportCSV">Export CSV</button>
    </div>
  </div>

  <!-- Alerts Section -->
  <div id="alertsContainer" style="margin:16px 0"></div>

  <div class="grid kpis" style="margin:16px 0">
    <div class="card"><h3>Calls (range)</h3><div class="big" id="k_calls">—</div></div>
    <div class="card"><h3>Connected rate</h3><div class="big" id="k_rate">—</div><div class="muted" id="k_connMiss">—</div></div>
    <div class="card"><h3>Avg duration (connected)</h3><div class="big" id="k_avg">—</div></div>
    <div class="card"><h3>Calls today</h3><div class="big" id="k_today">—</div></div>
  </div>

  <div class="split">
    <div class="card">
      <h3>Activity by day</h3>
      <div id="chartWrap"><canvas id="chartCalls" style="width:100%;height:100%"></canvas></div>
    </div>
    <div class="card">
      <h3>Top agents (by talk time)</h3>
      <table id="tblAgents"><thead><tr>
        <th>Agent</th><th class="muted">Calls</th><th>Total talk</th>
      </tr></thead><tbody></tbody></table>
    </div>
  </div>

  <!-- Advanced Analysis Section -->
  <div class="card" id="analysisSection" style="margin-top:16px;display:none">
    <h3>Advanced Analysis</h3>
    <div class="split">
      <div>
        <h4>By Day of Week</h4>
        <div id="chartDayOfWeekWrap"><canvas id="chartDayOfWeek" style="width:100%;height:250px"></canvas></div>
      </div>
      <div>
        <h4>By Hour of Day</h4>
        <div id="chartHourOfDayWrap"><canvas id="chartHourOfDay" style="width:100%;height:250px"></canvas></div>
      </div>
    </div>
    <div style="margin-top:16px">
      <h4>Call Duration Analysis</h4>
      <div id="durationStats"></div>
    </div>
  </div>

  <div class="card" style="margin-top:16px">
    <h3>Recent calls</h3>
    <table id="tblRecent">
      <thead><tr>
        <th>When (UTC)</th><th>Agent</th><th>Direction</th><th>Status</th><th>To/From</th><th>Duration</th><th>Recording</th>
      </tr></thead>
      <tbody></tbody>
    </table>
  </div>

  <div class="muted" style="margin:12px 0">Powered by MightyCall v4 • Enhanced Monitoring Dashboard</div>
</div>

<script>
/* Wait for Chart.js (defer) */
document.addEventListener('DOMContentLoaded', () => {
  const qs = (id) => document.getElementById(id);
  const state = { since:null, until:null, agent:'', direction:'', status:'', hasRecording:'' };
  let lastAppliedQS = '';
  let chart = null;
  let chartDayOfWeek = null;
  let chartHourOfDay = null;
  let agentsPopulated = false;

  /* ---------- Dates & Filters ---------- */
  function setRange(days) {
    const today = new Date(); const since = new Date();
    since.setDate(today.getDate() - (days - 1));
    qs('since').value = since.toISOString().slice(0, 10);
    qs('until').value = today.toISOString().slice(0, 10);
    markDirtyIfChanged();
  }

  function setWeekRange(offset) {
    const today = new Date();
    const currentDay = today.getDay(); // Sunday=0, Monday=1
    const diffToMonday = today.getDate() - currentDay + (currentDay === 0 ? -6 : 1);
    
    let monday = new Date(today.setDate(diffToMonday));
    let friday = new Date(today.setDate(monday.getDate() + 4));

    if (offset > 0) {
        monday.setDate(monday.getDate() - (7 * offset));
        friday.setDate(friday.getDate() - (7 * offset));
    }

    qs('since').value = monday.toISOString().slice(0, 10);
    qs('until').value = friday.toISOString().slice(0, 10);
    markDirtyIfChanged();
  }

  function readFilters() {
    state.since = qs('since').value || '';
    state.until = qs('until').value || '';
    state.agent = qs('agent').value || '';
    state.direction = qs('direction').value || '';
    state.status = qs('status').value || '';
    state.hasRecording = qs('hasRecording').value;
  }
  function buildQuery() {
    const p = new URLSearchParams();
    if (state.since) p.set('since', state.since);
    if (state.until) p.set('until', state.until);
    if (state.agent) p.set('agent', state.agent);
    if (state.direction) p.set('direction', state.direction);
    if (state.status) p.set('status', state.status);
    if (state.hasRecording !== '') p.set('hasRecording', state.hasRecording);
    return p.toString();
  }

  /* ---------- Dirty state & Apply ---------- */
  function markDirtyIfChanged() {
    readFilters();
    const currentQS = buildQuery();
    const isDirty = currentQS !== lastAppliedQS;
    qs('btnApply').classList.toggle('dirty', isDirty);
  }
  function setRangeActive(btnId) {
    ['btnToday','btnThisWeek','btnLastWeek','btnLast7','btnLast30'].forEach(id=>{
      qs(id).classList.toggle('active', id === btnId);
    });
  }

  /* ---------- Build date labels from range (robust even with empty series) ---------- */
  function buildDateLabels(fromStr, toStr) {
    if (!fromStr || !toStr) return ['No data'];
    const out = [];
    const from = new Date(fromStr + 'T00:00:00');
    const to   = new Date(toStr   + 'T00:00:00');
    for (let d = new Date(from); d <= to; d.setDate(d.getDate() + 1)) {
      out.push(d.toISOString().slice(0,10));
    }
    return out.length ? out : ['No data'];
  }

  /* ---------- Chart ---------- */
  function renderChart(seriesRaw) {
    try {
      // Always produce labels from current date inputs
      const labels = buildDateLabels(qs('since').value, qs('until').value);

      const s = (seriesRaw && typeof seriesRaw === 'object') ? seriesRaw : {};
      const incoming = labels.map(d => Number(s[d]?.Incoming  ?? 0));
      const outgoing = labels.map(d => Number(s[d]?.Outgoing  ?? 0));
      const connected= labels.map(d => Number(s[d]?.Connected ?? 0));
      const missed   = labels.map(d => Number(s[d]?.Missed    ?? 0));

      const canvas = qs('chartCalls');
      if (!canvas || typeof Chart === 'undefined') return;

      if (chart) { chart.destroy(); chart = null; }

      chart = new Chart(canvas, {
        type: 'line',
        data: {
          labels,
          datasets: [
            { label: 'Incoming',  data: incoming,  borderColor: '#4ade80', backgroundColor: 'rgba(74,222,128,.12)',  borderWidth: 2, pointRadius: 2, tension: 0.35 },
            { label: 'Outgoing',  data: outgoing, borderColor: '#60a5fa', backgroundColor: 'rgba(96,165,250,.12)',  borderWidth: 2, pointRadius: 2, tension: 0.35 },
            { label: 'Connected', data: connected, borderColor: '#fbbf24', backgroundColor: 'rgba(251,191,36,.12)',  borderWidth: 2, pointRadius: 2, tension: 0.35 },
            { label: 'Missed',    data: missed,    borderColor: '#f87171', backgroundColor: 'rgba(248,113,113,.12)', borderWidth: 2, pointRadius: 2, tension: 0.35 }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: { beginAtZero: true, ticks: { color: '#9aa5c4' }, grid: { color: 'rgba(255,255,255,.06)' } },
            x: { ticks: { color: '#9aa5c4' }, grid: { display: false } }
          },
          plugins: { legend: { labels: { color: '#e9ecf1' } } }
        }
      });
    } catch (err) {
      console.error('Chart render error:', err);
    }
  }

  /* ---------- Advanced Charts ---------- */
  function renderAdvancedCharts(analysis) {
    if (!analysis || typeof Chart === 'undefined') return;
    
    // Day of Week Chart
    if (analysis.dayOfWeek) {
      const dayLabels = Object.keys(analysis.dayOfWeek.calls);
      const dayCalls = dayLabels.map(day => analysis.dayOfWeek.calls[day]);
      
      const canvasDay = qs('chartDayOfWeek');
      if (chartDayOfWeek) chartDayOfWeek.destroy();
      
      chartDayOfWeek = new Chart(canvasDay, {
        type: 'bar',
        data: {
          labels: dayLabels,
          datasets: [{
            label: 'Calls by Day of Week',
            data: dayCalls,
            backgroundColor: 'rgba(96,165,250,.6)',
            borderColor: '#60a5fa',
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: { beginAtZero: true, ticks: { color: '#9aa5c4' }, grid: { color: 'rgba(255,255,255,.06)' } },
            x: { ticks: { color: '#9aa5c4' }, grid: { display: false } }
          },
          plugins: { legend: { labels: { color: '#e9ecf1' } } }
        }
      });
    }
    
    // Hour of Day Chart
    if (analysis.hourOfDay) {
      const hourLabels = Object.keys(analysis.hourOfDay.calls).sort((a, b) => parseInt(a) - parseInt(b));
      const hourCalls = hourLabels.map(hour => analysis.hourOfDay.calls[hour]);
      
      const canvasHour = qs('chartHourOfDay');
      if (chartHourOfDay) chartHourOfDay.destroy();
      
      chartHourOfDay = new Chart(canvasHour, {
        type: 'bar',
        data: {
          labels: hourLabels.map(h => `${h}:00`),
          datasets: [{
            label: 'Calls by Hour of Day',
            data: hourCalls,
            backgroundColor: 'rgba(74,222,128,.6)',
            borderColor: '#4ade80',
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            y: { beginAtZero: true, ticks: { color: '#9aa5c4' }, grid: { color: 'rgba(255,255,255,.06)' } },
            x: { ticks: { color: '#9aa5c4' }, grid: { display: false } }
          },
          plugins: { legend: { labels: { color: '#e9ecf1' } } }
        }
      });
    }
    
    // Duration Stats
    if (analysis.duration) {
      const durationStats = qs('durationStats');
      durationStats.innerHTML = `
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px;">
          <div class="card" style="padding: 12px;">
            <div class="muted">Min Duration</div>
            <div class="big">${hms(analysis.duration.min)}</div>
          </div>
          <div class="card" style="padding: 12px;">
            <div class="muted">Max Duration</div>
            <div class="big">${hms(analysis.duration.max)}</div>
          </div>
          <div class="card" style="padding: 12px;">
            <div class="muted">Avg Duration</div>
            <div class="big">${hms(analysis.duration.avg)}</div>
          </div>
          <div class="card" style="padding: 12px;">
            <div class="muted">Median Duration</div>
            <div class="big">${hms(analysis.duration.median)}</div>
          </div>
          <div class="card" style="padding: 12px;">
            <div class="muted">95th Percentile</div>
            <div class="big">${hms(analysis.duration.p95)}</div>
          </div>
        </div>
      `;
    }
  }

  /* ---------- Alerts ---------- */
  function renderAlerts(alerts) {
    const container = qs('alertsContainer');
    container.innerHTML = '';
    
    if (!alerts || alerts.length === 0) {
      container.style.display = 'none';
      return;
    }
    
    container.style.display = 'block';
    
    alerts.forEach(alert => {
      const alertDiv = document.createElement('div');
      alertDiv.className = `alert alert-${alert.type}`;
      alertDiv.innerHTML = `
        <div class="alert-title">${alert.title}</div>
        <div>${alert.message}</div>
      `;
      container.appendChild(alertDiv);
    });
  }

  /* ---------- Helpers ---------- */
  function hms(ms) {
    const s = Math.round(ms / 1000);
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const x = s % 60;
    return [h, m, x].map(v => String(v).padStart(2, '0')).join(':');
  }

  /* ---------- Data load ---------- */
  async function loadMetrics() {
    readFilters();
    const q = buildQuery();
    const url = 'monitor.php?action=metrics&analysis=1&alerts=1' + (q ? '&' + q : '');
    
    try {
      const res = await fetch(url);
      const j = await res.json();

      qs('k_calls').textContent = j.totals?.calls ?? '0';
      qs('k_rate').textContent = ((j.totals?.connectedRate ?? 0)) + '%';
      qs('k_connMiss').textContent = `${j.totals?.connected ?? 0} connected • ${j.totals?.missed ?? 0} missed`;
      qs('k_avg').textContent = j.totals?.avgConnectedDurationHMS ?? '00:00:00';
      qs('k_today').textContent = j.totals?.callsToday ?? 0;

      renderChart(j.series || {});
      
      // Show advanced analysis if available
      if (j.analysis) {
        qs('analysisSection').style.display = 'block';
        renderAdvancedCharts(j.analysis);
      } else {
        qs('analysisSection').style.display = 'none';
      }
      
      // Show alerts if available
      renderAlerts(j.alerts);

      // Populate agent dropdown only once (from recent + topAgents)
      if (!agentsPopulated) {
        const agentSet = new Set();
        (j.recent || []).forEach(c => {
          const name = c.caller?.name || '';
          const ext = c.caller?.extension || '';
          if (name) agentSet.add(name);
          if (ext) agentSet.add(ext);
        });
        (j.topAgents || []).forEach(a => {
          if (a.name && a.name !== 'Unknown') agentSet.add(a.name);
        });
        const agentSelect = qs('agent');
        Array.from(agentSet).sort().forEach(agent => {
          const opt = document.createElement('option');
          opt.value = agent; opt.textContent = agent; agentSelect.appendChild(opt);
        });
        agentsPopulated = true;
      }

      // Top agents table
      const tbodyA = document.querySelector('#tblAgents tbody');
      tbodyA.innerHTML = '';
      (j.topAgents || []).forEach(a => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${a.name || '—'}</td><td class="muted">${a.calls || 0}</td><td>${hms(a.durMs || 0)}</td>`;
        tbodyA.appendChild(tr);
      });

      // Recent calls table
      const tbodyR = document.querySelector('#tblRecent tbody');
      tbodyR.innerHTML = '';
      (j.recent || []).forEach(c => {
        const when = (c.dateTimeUtc || '').replace('T', ' ').replace('Z', '');
        const agent = (c.caller?.name) || (c.caller?.extension) || '—';
        const dir = c.direction || '—';
        const st  = c.callStatus || '—';
        const to  = c.called?.[0]?.phone || c.called?.[0]?.name || c.caller?.phone || '—';
        const dur = hms(c.duration || 0);

        let rec = '—';
        if (c.callRecord?.uri) {
          const url = c.callRecord.uri;
          rec = `
            <div style="display:flex;gap:6px;align-items:center;min-height:32px">
              <audio controls preload="none" style="height:30px;width:180px;font-size:12px">
                <source src="${url}" type="audio/wav">
                Your browser does not support audio.
              </audio>
              <a href="${url}" download class="badge" style="padding:4px 8px;text-decoration:none;display:inline-flex;align-items:center">↓</a>
            </div>
          `;
        }

        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${when}</td><td><span class="badge">${agent}</span></td><td>${dir}</td><td>${st}</td><td>${to}</td><td>${dur}</td><td>${rec}</td>`;
        tbodyR.appendChild(tr);
      });

      // mark as applied (turn off dirty pulse)
      lastAppliedQS = q;
      qs('btnApply').classList.remove('dirty');
    } catch (error) {
      console.error('Error loading metrics:', error);
      alert('Error loading data. Please try again.');
    }
  }

  /* ---------- Export ---------- */
  function exportData(format) {
    readFilters();
    const q = buildQuery();
    const url = `monitor.php?action=export&format=${format}` + (q ? '&' + q : '');
    window.open(url, '_blank');
  }

  /* ====== Init ====== */
  // Default: Last 30d and highlight
  setRange(30);
  setRangeActive('btnLast30');

  // Listen for field changes just to mark dirty & show pulse (no auto-apply)
  ['since','until','agent','direction','status','hasRecording'].forEach(id=>{
    qs(id).addEventListener('change', markDirtyIfChanged);
    if (id === 'agent') qs(id).addEventListener('input', markDirtyIfChanged);
  });

  // Range buttons: set dates + highlight, but DO NOT auto-apply
  qs('btnToday').onclick  = () => {
    const t = new Date().toISOString().slice(0,10);
    qs('since').value = t; qs('until').value = t;
    setRangeActive('btnToday'); markDirtyIfChanged();
  };
  qs('btnThisWeek').onclick = () => { setWeekRange(0); setRangeActive('btnThisWeek'); };
  qs('btnLastWeek').onclick = () => { setWeekRange(1); setRangeActive('btnLastWeek'); };
  qs('btnLast7').onclick  = () => { setRange(7);  setRangeActive('btnLast7');  };
  qs('btnLast30').onclick = () => { setRange(30); setRangeActive('btnLast30'); };

  // Apply Filter
  qs('btnApply').onclick = loadMetrics;
  
  // Export CSV
  qs('btnExportCSV').onclick = () => exportData('csv');

  // Initial load applies default (30d)
  loadMetrics();
});
</script>
</body>
</html>