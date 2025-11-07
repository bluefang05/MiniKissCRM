<?php
// /api/mc_recent.php
declare(strict_types=1);

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$pdo = getPDO();
$user = Auth::user();
$userId = (int)($user['id'] ?? 0);

// Try to read user's MightyCall extension (optional filter)
$mcExt = null;
try {
    $stmt = $pdo->prepare("SELECT mc_extension FROM users WHERE id=?");
    $stmt->execute([$userId]);
    $mcExt = (string)($stmt->fetchColumn() ?: '');
    if ($mcExt === '') $mcExt = null;
} catch (\Throwable $e) {
    // ignore; extension filter is optional
}

/* ===== Helpers (inline to avoid extra files) ===== */
function _env(string $k, ?string $def=null): ?string {
    $v = getenv($k);
    return ($v === false || $v === '') ? $def : $v;
}
function endpoints(): array {
    $base = rtrim((string)_env('MIGHTYCALL_BASE', ''), '/');
    if ($base !== '') {
        $authBase = preg_replace('~/api/?$~', '', $base);
        return ['auth' => $authBase, 'api' => $base];
    }
    switch (strtoupper((string)_env('MIGHTYCALL_REGION', 'US'))) {
        case 'EU':
            return ['auth' => 'https://eu.api.mightycall.com/v4', 'api' => 'https://eu.api.mightycall.com/v4/api'];
        case 'SANDBOX':
            return ['auth' => 'https://sandbox.api.mightycall.com/v4', 'api' => 'https://sandbox.api.mightycall.com/v4/api'];
        default:
            return ['auth' => 'https://api.mightycall.com/v4', 'api' => 'https://api.mightycall.com/v4/api'];
    }
}
function http_json(string $method, string $url, array $headers=[], ?string $body=null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['ok' => $code >= 200 && $code < 300, 'code'=>$code, 'raw'=>$raw, 'err'=>$err, 'json'=>json_decode((string)$raw, true)];
}
function get_token(): ?array {
    $API = _env('MIGHTYCALL_API_KEY', '');
    $SEC = _env('MIGHTYCALL_SECRET_KEY', '');
    if (!$API || !$SEC) return null;

    $end = endpoints();
    $url = rtrim($end['auth'], '/') . '/auth/token';

    // Lightweight cache in temp
    $tokFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mc_token.json';
    if (is_file($tokFile)) {
        $j = json_decode((string)@file_get_contents($tokFile), true);
        if ($j && time() < (int)($j['expires_at'] ?? 0) - 60) return $j;
    }

    $fields = http_build_query([
        'grant_type'    => _env('MIGHTYCALL_GRANT', 'client_credentials'),
        'client_id'     => $API,
        'client_secret' => $SEC
    ]);
    $r = http_json('POST', $url, [
        'Content-Type: application/x-www-form-urlencoded',
        'x-api-key: ' . $API
    ], $fields);
    if (!$r['ok'] || empty($r['json']['access_token'])) return null;

    $ttl = (int)($r['json']['expires_in'] ?? 3600);
    $r['json']['expires_at'] = time() + max(0, $ttl);
    @file_put_contents($tokFile, json_encode($r['json'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    return $r['json'];
}
function api_get(string $path, array $qs=[]): ?array {
    $API = _env('MIGHTYCALL_API_KEY', '');
    $tok = get_token();
    if (!$API || !$tok) return null;

    $end = endpoints();
    $url = rtrim($end['api'], '/') . '/' . ltrim($path, '/');
    if ($qs) $url .= (strpos($url,'?')!==false ? '&' : '?') . http_build_query($qs);

    $r = http_json('GET', $url, [
        'Authorization: Bearer ' . $tok['access_token'],
        'x-api-key: ' . $API,
        'Content-Type: application/json'
    ]);
    return $r['ok'] ? ($r['json'] ?? null) : null;
}
function norm_duration($v): int {
    $n = (int)($v ?? 0);
    // Heuristic: if looks like ms, convert to s
    if ($n > 3600) $n = (int)ceil($n / 1000);
    return max(0, $n);
}
function first_recording_url(array $c): ?string {
    // common locations
    if (!empty($c['recordingUrl'])) return (string)$c['recordingUrl'];
    if (!empty($c['recording_url'])) return (string)$c['recording_url'];
    if (!empty($c['recordings']) && is_array($c['recordings'])) {
        foreach ($c['recordings'] as $r) {
            if (is_array($r)) {
                if (!empty($r['url'])) return (string)$r['url'];
                if (!empty($r['downloadUrl'])) return (string)$r['downloadUrl'];
            }
        }
    }
    return null;
}
function call_uid(array $c): ?string {
    foreach (['id','callId','uuid','interactionId'] as $k) {
        if (!empty($c[$k])) return (string)$c[$k];
    }
    return null;
}
function call_from(array $c): ?string {
    if (!empty($c['caller']) && is_array($c['caller'])) {
        foreach (['phone','extension','name'] as $k) if (!empty($c['caller'][$k])) return (string)$c['caller'][$k];
    }
    if (!empty($c['from'])) return (string)$c['from'];
    return null;
}
function call_to(array $c): ?string {
    if (!empty($c['called']) && is_array($c['called'])) {
        $r = $c['called'][0] ?? null;
        if (is_array($r)) {
            foreach (['name','phone','extension'] as $k) if (!empty($r[$k])) return (string)$r[$k];
        }
    }
    if (!empty($c['to'])) return (string)$c['to'];
    return null;
}
function call_time_utc(array $c): ?string {
    foreach (['dateTimeUtc','datetimeUtc','date','startTime'] as $k) {
        if (!empty($c[$k])) return (string)$c[$k];
    }
    return null;
}
function involves_extension(array $c, string $ext): bool {
    $ext = trim($ext);
    if ($ext === '') return false;
    // caller
    if (!empty($c['caller']['extension']) && (string)$c['caller']['extension'] === $ext) return true;
    // called array
    if (!empty($c['called']) && is_array($c['called'])) {
        foreach ($c['called'] as $r) {
            if (is_array($r) && !empty($r['extension']) && (string)$r['extension'] === $ext) return true;
        }
    }
    return false;
}

/* ===== Pull last 7 days, page with safety limits ===== */
try {
    $pageSize = 1000;
    $maxPages = 5;  // hard cap
    $endUtc   = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');
    $startUtc = (new DateTime('-6 days', new DateTimeZone('UTC')))->format('Y-m-d');

    $items = [];
    for ($page=1; $page <= $maxPages; $page++) {
        $res = api_get('calls', [
            'pageSize' => $pageSize,
            'page'     => $page,
            'startUtc' => $startUtc.'T00:00:00',
            'endUtc'   => $endUtc.'T23:59:59'
        ]);
        if (!$res) break;

        // tolerate different envelopes
        $batch = [];
        if (!empty($res['data']['calls']) && is_array($res['data']['calls'])) $batch = $res['data']['calls'];
        elseif (!empty($res['calls']) && is_array($res['calls']))             $batch = $res['calls'];

        if (!$batch) break;

        foreach ($batch as $c) {
            if (!is_array($c)) continue;
            if ($mcExt && !involves_extension($c, $mcExt)) {
                // keep, but you may uncomment next line to strictly filter by agent extension
                // continue;
            }
            $items[] = [
                'mc_call_uid'  => call_uid($c),
                'dateTimeUtc'  => call_time_utc($c),
                'direction'    => $c['direction'] ?? ($c['dir'] ?? ''),
                'from'         => call_from($c),
                'to'           => call_to($c),
                'duration'     => norm_duration($c['duration'] ?? ($c['talkTime'] ?? 0)),
                'recording_url'=> first_recording_url($c),
            ];
        }
        if (count($batch) < $pageSize) break; // last page
    }

    // Sort by time desc
    usort($items, function($a,$b){
        $ta = strtotime($a['dateTimeUtc'] ?? '') ?: 0;
        $tb = strtotime($b['dateTimeUtc'] ?? '') ?: 0;
        return $tb <=> $ta;
    });

    echo json_encode(['ok'=>true, 'items'=>$items], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
