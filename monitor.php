<?php
/* ==== Runtime hardening (evita timeout/memoria) ==== */
ini_set('max_execution_time', '0'); // sin límite PHP
ignore_user_abort(true);
ini_set('memory_limit', '1024M');

/**
 * monitor_calls.php — MightyCall Monitor (estilo CRM claro)
 *
 * - Presets: today | this_week | last_week | 1m | 3m
 * - Default preset = '1m' (~30 días)
 * - Cache por rango (120s)
 * - Cortacircuito: max páginas + límite de tiempo
 * - Métricas, gráfico, tabla con paginación, export CSV
 */

/* =======================
   CONFIG
   ======================= */
$config = [
    'api_key'         => '0bb0f2ee-ff6f-4be5-8530-4d35a01e80cc',
    'client_secret'   => 'dc73c680f799',
    'region'          => 'US', // 'US' | 'EU' | 'SANDBOX'
    'timezone'        => 'America/Santo_Domingo',
    'page_size'       => 50,     // filas visibles por página (default)
    'api_page_size'   => 1000,   // registros por página en la API MightyCall
    'api_max_pages'   => 600,    // tope de páginas API
    'hard_wall_secs'  => 25,     // tope de segundos de descarga
    'cache_ttl_secs'  => 120     // seg de cache por mismo rango
];

date_default_timezone_set($config['timezone']);

/* Overrides opcionales vía query para cosas "internas" */
if (isset($_GET['api_page_size'])) $config['api_page_size'] = max(1, min((int)$_GET['api_page_size'], 1000));
if (isset($_GET['max_pages']))     $config['api_max_pages'] = max(1, min((int)$_GET['max_pages'], 100000));
if (isset($_GET['wall']))          $config['hard_wall_secs'] = max(5, min((int)$_GET['wall'], 120)); // 5-120

/* =======================
   ENDPOINTS SEGÚN REGIÓN
   ======================= */
function getEndpoints(string $region): array {
    switch (strtoupper(trim($region))) {
        case 'EU':
            return [
                'auth' => 'https://eu.api.mightycall.com/v4',
                'api'  => 'https://eu.api.mightycall.com/v4/api'
            ];
        case 'SANDBOX':
            return [
                'auth' => 'https://sandbox.api.mightycall.com/v4',
                'api'  => 'https://sandbox.api.mightycall.com/v4/api'
            ];
        default:
            return [
                'auth' => 'https://api.mightycall.com/v4',
                'api'  => 'https://api.mightycall.com/v4/api'
            ];
    }
}

/* =======================
   CLIENTE MIGHTYCALL
   ======================= */
class MightyCallClient {
    private string $apiKey;
    private string $clientSecret;
    private string $authBase;
    private string $apiBase;
    private ?string $token = null;
    private ?int $tokenExp = null;

    public function __construct(string $apiKey, string $clientSecret, string $authBase, string $apiBase) {
        $this->apiKey       = $apiKey;
        $this->clientSecret = $clientSecret;
        $this->authBase     = $authBase;
        $this->apiBase      = $apiBase;
    }

    public function ensureAuth(): void {
        if ($this->token && $this->tokenExp && time() < $this->tokenExp - 15) return;

        $url  = $this->authBase . '/auth/token';
        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->apiKey,
            'client_secret' => $this->clientSecret
        ]);

        $resp = $this->http('POST', $url, $body, [
            'x-api-key: ' . $this->apiKey,
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        if (!$resp['ok'] || empty($resp['data']->access_token)) {
            throw new RuntimeException("Auth failed ({$resp['code']}): " . ($resp['raw'] ?: $resp['err']));
        }

        $this->token    = $resp['data']->access_token;
        $this->tokenExp = time() + (int)($resp['data']->expires_in ?? 3600);
    }

    /** Devuelve arreglo de usuarios del team */
    public function getTeam(): array {
        $data = $this->api('GET', '/team');
        if (isset($data->users) && is_array($data->users)) return $data->users;
        if (isset($data->data->users) && is_array($data->data->users)) return $data->data->users;
        return [];
    }

    /**
     * Descarga llamadas paginando con límite de páginas y límite de tiempo de pared.
     * Reintento básico con backoff progresivo.
     *
     * Retorna: ['calls'=>[], 'pages'=>int, 'truncated'=>bool]
     */
    public function getCallsAllWithBreaker(
        int $pageSize,
        ?string $startDate,
        ?string $endDate,
        int $maxPages,
        float $wallStart,
        int $wallSeconds
    ): array {
        $pageSize   = max(1, min($pageSize, 1000));
        $all        = [];
        $page       = 1;
        $truncated  = false;

        do {
            // breaker por tiempo total
            if ((microtime(true) - $wallStart) >= $wallSeconds) {
                $truncated = true;
                break;
            }

            $params = ['pageSize' => $pageSize, 'page' => $page];
            if ($startDate) $params['startUtc'] = $startDate . 'T00:00:00';
            if ($endDate)   $params['endUtc']   = $endDate   . 'T23:59:59';
            $qs   = http_build_query($params);

            // Reintentos con backoff
            $attempts    = 0;
            $maxAttempts = 4;
            $data        = null;
            $ok          = false;

            do {
                try {
                    $data = $this->api('GET', '/calls?' . $qs);
                    $ok   = true;
                } catch (\Throwable $e) {
                    $attempts++;
                    usleep(250000 * $attempts); // 250/500/750/1000 ms
                    if ($attempts >= $maxAttempts) throw $e;
                }
            } while (!$ok);

            $batch = [];
            if (isset($data->data->calls) && is_array($data->data->calls)) {
                $batch = $data->data->calls;
            } elseif (isset($data->calls) && is_array($data->calls)) {
                $batch = $data->calls;
            }

            if ($batch) {
                foreach ($batch as $item) $all[] = $item;
            }

            $page++;
        } while (!empty($batch) && $page <= $maxPages);

        if ($page > $maxPages) {
            $truncated = true;
        }

        return [
            'calls'     => $all,
            'pages'     => $page - 1,
            'truncated' => $truncated
        ];
    }

    private function api(string $method, string $path) {
        $this->ensureAuth();
        $resp = $this->http($method, $this->apiBase . $path, null, [
            'x-api-key: ' . $this->apiKey,
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json'
        ]);
        if (!$resp['ok']) {
            throw new RuntimeException("API {$method} {$path} failed ({$resp['code']}): " . ($resp['raw'] ?: $resp['err']));
        }
        return $resp['data'];
    }

    private function http(string $method, string $url, ?string $body = null, array $headers = []): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        return [
            'ok'   => $code >= 200 && $code < 300,
            'code' => $code,
            'raw'  => $raw,
            'data' => json_decode((string)$raw),
            'err'  => $err
        ];
    }
}

/* =======================
   HELPERS
   ======================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatDurationSeconds(int $seconds): string {
    $seconds = max(0, $seconds);
    return gmdate('i:s', $seconds);
}

function normalizeDurationToSeconds($val): int {
    $n = (int)($val ?? 0);
    // si parece milisegundos (ridículamente alto), convierto a s
    if ($n > 3600) {
        $n = (int)ceil($n / 1000);
    }
    return max(0, $n);
}

function directionText($dir): string {
    $d = strtolower((string)$dir);
    if ($d === 'outgoing' || $d === 'outbound') return 'Outgoing';
    if ($d === 'incoming' || $d === 'inbound')  return 'Incoming';
    return 'Unknown';
}

/** normalizador para comparar nombres (case-insensitive, trim, colapsa espacios) */
function norm($s): string {
    $t = is_string($s) ? $s : '';
    $t = preg_replace('/\s+/u', ' ', $t); // colapsa tabs, dobles espacios, etc.
    return mb_strtolower(trim($t), 'UTF-8');
}

/**
 * Dado el objeto de llamada $c, devuelve [$from, $to] de forma segura.
 */
function resolveEndpointsForCall($c): array {
    $from = '—';
    if (isset($c->caller) && is_object($c->caller)) {
        if (!empty($c->caller->name)) {
            $from = $c->caller->name;
        } elseif (!empty($c->caller->phone)) {
            $from = $c->caller->phone;
        } elseif (!empty($c->caller->extension)) {
            $from = $c->caller->extension;
        }
    }

    $to = '—';
    if (!empty($c->called) && is_array($c->called)) {
        $first = $c->called[0] ?? null;
        if ($first && is_object($first)) {
            if (!empty($first->name)) {
                $to = $first->name;
            } elseif (!empty($first->phone)) {
                $to = $first->phone;
            } elseif (!empty($first->extension)) {
                $to = $first->extension;
            }
        }
    }

    return [$from, $to];
}

/* ======== De-dup helpers ======== */
/** Build a stable key for a call. Prefer native IDs; fallback to composite fingerprint. */
function callKey($c): string {
    if (isset($c->id))            return 'id:' . $c->id;
    if (isset($c->callId))        return 'callId:' . $c->callId;
    if (isset($c->uuid))          return 'uuid:' . $c->uuid;
    if (isset($c->interactionId)) return 'ix:' . $c->interactionId;

    $caller = '';
    if (isset($c->caller) && is_object($c->caller)) {
        $caller = $c->caller->phone ?? $c->caller->extension ?? $c->caller->name ?? '';
    }
    $to = '';
    if (!empty($c->called) && is_array($c->called)) {
        $first = $c->called[0] ?? null;
        if ($first && is_object($first)) {
            $to = $first->phone ?? $first->extension ?? $first->name ?? '';
        }
    }
    return implode('|', [
        (string)($c->dateTimeUtc ?? ''),
        strtolower((string)($c->direction ?? '')),
        $caller,
        $to,
        (string)($c->duration ?? ''),
        strtolower((string)($c->callStatus ?? ''))
    ]);
}

/** Remove duplicate calls; returns [uniqueItems, removedCount] */
function dedupeCalls(array $items): array {
    $seen = [];
    $out  = [];
    $dup  = 0;
    foreach ($items as $c) {
        $k = callKey($c);
        if (isset($seen[$k])) { $dup++; continue; }
        $seen[$k] = true;
        $out[] = $c;
    }
    return [$out, $dup];
}

/**
 * Convierte preset → [startDate,endDate] en UTC YYYY-MM-DD
 *
 * '1m' = últimos ~30 días
 * default = '1m'
 */
function resolvePreset(?string $preset): array {
    $tzUTC = new DateTimeZone('UTC');
    $today = new DateTime('now', $tzUTC);
    $end   = clone $today;
    $start = clone $today;

    switch ($preset) {
        case 'today':
            // start/end = hoy
            break;

        case 'this_week':
            $start = new DateTime('monday this week', $tzUTC);
            break;

        case 'last_week':
            $start = new DateTime('monday last week', $tzUTC);
            $end   = new DateTime('sunday last week', $tzUTC);
            break;

        case '3m':
            $start = (clone $today)->modify('-89 days');
            break;

        case '1m':
        default:
            $preset = '1m';
            $start  = (clone $today)->modify('-29 days');
            break;
    }

    return [
        'preset'    => $preset,
        'startDate' => $start->format('Y-m-d'),
        'endDate'   => $end->format('Y-m-d')
    ];
}

/* =======================
   INPUTS (GET)
   ======================= */
$page        = max(1, (int)($_GET['page'] ?? 1));
$autoRefresh = (int)($_GET['auto_refresh'] ?? 0);
$memberKey   = $_GET['member'] ?? 'all'; // 'all' o nombre exacto del agente
$preset      = $_GET['preset'] ?? null;
$startDate   = $_GET['start_date'] ?? null;
$endDate     = $_GET['end_date']   ?? null;

/* page_size dinámico del selector Rows / page
   - límite entre 10 y 500 para tabla HTML */
$userPageSize = isset($_GET['page_size'])
    ? max(10, min((int)$_GET['page_size'], 500))
    : (int)$config['page_size'];

$memberKeyNorm = norm($memberKey);

/**
 * LÓGICA DE FECHAS:
 * Default preset = último mes ("1m").
 */
if (!$startDate || !$endDate) {
    $resolved  = resolvePreset($preset ?: '1m');
    $preset    = $resolved['preset'];
    $startDate = $resolved['startDate'];
    $endDate   = $resolved['endDate'];
}

/* =======================
   EJECUCIÓN (API + cache)
   ======================= */
$endpoints        = getEndpoints($config['region']);
$error            = null;
$team             = [];
$callsRaw         = [];
$truncated        = false;
$pagesPulled      = 0;
$dataSource       = 'Live'; // 'Live' o 'Cache'
$dedupRemoved     = 0;
$rawCountFetched  = 0;
$rawCountUnique   = 0;

try {
    $client   = new MightyCallClient(
        $config['api_key'],
        $config['client_secret'],
        $endpoints['auth'],
        $endpoints['api']
    );

    $team     = $client->getTeam();

    // Cache por rango (luego filtramos miembro en runtime)
    $cacheDir  = sys_get_temp_dir();
    $cacheKey  = 'mcalls_v2_objfmt_' . md5(json_encode([
        $startDate,
        $endDate,
        $config['api_page_size'],
        $config['api_max_pages']
    ]));
    $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.json';

    $useCache = is_file($cacheFile)
        && (time() - filemtime($cacheFile) <= $config['cache_ttl_secs']);

    if ($useCache) {
        $buf = file_get_contents($cacheFile);

        // 1) Intento leer como OBJETOS (formato correcto)
        $cachedObj = json_decode($buf);
        if (is_object($cachedObj) && isset($cachedObj->calls) && is_array($cachedObj->calls)) {
            $callsRaw     = $cachedObj->calls;
            $truncated    = (bool)($cachedObj->truncated ?? false);
            $pagesPulled  = (int)($cachedObj->pages ?? 0);
            $dataSource   = 'Cache';
        } else {
            // 2) Tal vez viejo cache como ARREGLOS => convertir a objetos
            $cachedArr = json_decode($buf, true);
            if (is_array($cachedArr) && isset($cachedArr['calls'])) {
                $cachedObj   = json_decode(json_encode($cachedArr)); // coerción
                $callsRaw    = $cachedObj->calls ?? [];
                $truncated   = (bool)($cachedObj->truncated ?? false);
                $pagesPulled = (int)($cachedObj->pages ?? 0);
                $dataSource  = 'Cache';
            } else {
                // cache corrupto
                $useCache = false;
            }
        }
    }

    if (!$useCache) {
        $wallStart = microtime(true);
        $res = $client->getCallsAllWithBreaker(
            $config['api_page_size'],
            $startDate,
            $endDate,
            $config['api_max_pages'],
            $wallStart,
            $config['hard_wall_secs']
        );

        $callsRaw    = $res['calls'];
        $truncated   = $res['truncated'];
        $pagesPulled = $res['pages'];
        $dataSource  = 'Live';

        // intentar guardar cache (se guarda como OBJETOS)
        @file_put_contents(
            $cacheFile,
            json_encode(
                [
                    'calls'     => $callsRaw,
                    'truncated' => $truncated,
                    'pages'     => $pagesPulled
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );
    }

    // Conteo original y de-dup
    $rawCountFetched = count($callsRaw);
    list($callsRaw, $dedupRemoved) = dedupeCalls($callsRaw);
    $rawCountUnique  = count($callsRaw);

} catch (Throwable $e) {
    $error = $e->getMessage();
}

/* =======================
   LISTA DE AGENTES PARA UI
   ======================= */
$teamNames = [];
foreach ($team as $a) {
    if (!empty($a->fullName)) $teamNames[] = $a->fullName;
}
sort($teamNames, SORT_NATURAL | SORT_FLAG_CASE);

/* =======================
   FILTRO POR AGENTE (tolerante a espacios/mayúsculas)
   ======================= */
$callsFiltered = [];
foreach ($callsRaw as $c) {
    // si es "all" no filtramos
    $belongs = ($memberKeyNorm === 'all');

    if (!$belongs) {
        // caller
        $callerNameNorm = (isset($c->caller) && is_object($c->caller) && !empty($c->caller->name))
            ? norm($c->caller->name)
            : null;

        if ($callerNameNorm && $callerNameNorm === $memberKeyNorm) {
            $belongs = true;
        } else {
            // cualquier callee por nombre
            if (!empty($c->called) && is_array($c->called)) {
                foreach ($c->called as $r) {
                    if (!empty($r->name) && norm($r->name) === $memberKeyNorm) {
                        $belongs = true;
                        break;
                    }
                }
            }
        }
    }

    if ($belongs) {
        $callsFiltered[] = $c;
    }
}

/* === ORDENAR por fecha/hora descendente antes de paginar === */
usort($callsFiltered, function($a, $b) {
    $ta = isset($a->dateTimeUtc) ? strtotime($a->dateTimeUtc) : 0;
    $tb = isset($b->dateTimeUtc) ? strtotime($b->dateTimeUtc) : 0;
    // más reciente primero
    if ($tb === $ta) return 0;
    return ($tb < $ta) ? -1 : 1;
});

/* =======================
   STATS
   ======================= */
$stats = [
    'total'    => 0,
    'answered' => 0,
    'avg'      => 0,
    'incoming' => 0,
    'outgoing' => 0
];
$totalDuration = 0;

foreach ($callsFiltered as $c) {
    $stats['total']++;

    $dur = normalizeDurationToSeconds($c->duration ?? 0);
    $totalDuration += $dur;

    if (strtolower($c->callStatus ?? '') === 'connected') {
        $stats['answered']++;
    }

    $dir = strtolower($c->direction ?? 'inbound');
    if ($dir === 'outgoing' || $dir === 'outbound') {
        $stats['outgoing']++;
    } else {
        $stats['incoming']++;
    }
}
$stats['avg'] = $stats['total'] > 0
    ? (int)round($totalDuration / $stats['total'])
    : 0;

/* =======================
   DATA PARA GRÁFICO (llamadas por día / agente)
   ======================= */
$labels    = [];
$cursorUTC = new DateTime($startDate, new DateTimeZone('UTC'));
$endUTC    = new DateTime($endDate,   new DateTimeZone('UTC'));
while ($cursorUTC <= $endUTC) {
    $labels[] = $cursorUTC->format('Y-m-d');
    $cursorUTC->modify('+1 day');
}

// matriz agente -> fecha -> count
$agentSet = [];
if (!empty($teamNames)) {
    foreach ($teamNames as $n) {
        $agentSet[$n] = true;
    }
} else {
    $agentSet['Unknown'] = true;
}

// Inicializamos matriz por agente visible en la UI
$seriesMatrix = [];
foreach (array_keys($agentSet) as $name) {
    $seriesMatrix[$name] = array_fill_keys($labels, 0);
}

/**
 * Dado un nombre detectado en una llamada, esta función intenta
 * mapearlo a la "key real" del seriesMatrix (normalizando).
 */
function resolveAgentKeyForSeries(array $seriesMatrix, ?string $rawName): ?string {
    if (!$rawName) return null;
    $needle = norm($rawName);
    foreach (array_keys($seriesMatrix) as $candidate) {
        if (norm($candidate) === $needle) {
            return $candidate;
        }
    }
    // fallback
    if (isset($seriesMatrix['Unknown'])) return 'Unknown';
    return null;
}

// poblar matriz
foreach ($callsFiltered as $c) {
    $d = isset($c->dateTimeUtc) ? gmdate('Y-m-d', strtotime($c->dateTimeUtc)) : null;
    if (!$d || !in_array($d, $labels, true)) continue;

    // tratar de identificar agente responsable
    $agentNameRaw = (isset($c->caller) && is_object($c->caller) && !empty($c->caller->name))
        ? $c->caller->name
        : null;

    $dir = strtolower($c->direction ?? '');
    if (($dir === 'incoming' || $dir === 'inbound') && !empty($c->called) && is_array($c->called)) {
        // preferimos el callee conectado si es inbound
        foreach ($c->called as $r) {
            if (!empty($r->isConnected) && !empty($r->name)) {
                $agentNameRaw = $r->name;
                break;
            }
        }
    }

    // si todavía no tenemos agente y hay llamados->called con nombre, tomamos el primero que matchee
    if (!$agentNameRaw && !empty($c->called) && is_array($c->called)) {
        foreach ($c->called as $r) {
            if (!empty($r->name)) {
                $agentNameRaw = $r->name;
                break;
            }
        }
    }

    $agentKey = resolveAgentKeyForSeries($seriesMatrix, $agentNameRaw);
    if (!$agentKey || !isset($seriesMatrix[$agentKey])) continue;

    $seriesMatrix[$agentKey][$d] += 1;
}

// colores para líneas del chart
$colorPool = [
    '#60a5fa','#34d399','#f87171','#fbbf24',
    '#a78bfa','#f472b6','#22d3ee','#fb7185',
    '#c084fc','#4ade80'
];

$datasets  = [];
$ci = 0;
foreach ($seriesMatrix as $agent => $counts) {
    // si estoy filtrando por un agente específico, muestro solo ese en el gráfico
    if ($memberKeyNorm !== 'all' && norm($agent) !== $memberKeyNorm) continue;

    $color = $colorPool[$ci % count($colorPool)];
    $ci++;

    $datasets[] = [
        'label'                => $agent,
        'data'                 => array_values($counts),
        'borderColor'          => $color,
        'backgroundColor'      => 'transparent',
        'pointBackgroundColor' => $color,
        'pointBorderColor'     => $color,
        'tension'              => 0.25,
        'pointRadius'          => 3,
        'borderWidth'          => 2
    ];
}

$chartLabels   = $labels;
$chartDatasets = $datasets;

/* =======================
   EXPORT CSV
   ======================= */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="mightycall_calls_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date/Time (UTC)', 'Direction', 'From', 'To', 'Duration', 'Status']);

    foreach ($callsFiltered as $c) {
        $dirTxt = directionText($c->direction ?? '');
        $durSec = normalizeDurationToSeconds($c->duration ?? 0);

        [$from, $to] = resolveEndpointsForCall($c);

        fputcsv($out, [
            gmdate('d/m/Y H:i', strtotime($c->dateTimeUtc ?? 'now')),
            $dirTxt,
            $from,
            $to,
            formatDurationSeconds($durSec),
            ucfirst($c->callStatus ?? 'N/A')
        ]);
    }

    fclose($out);
    exit;
}

/* =======================
   PAGINACIÓN
   ======================= */
$pageSize   = $userPageSize; // usamos el valor elegido por el usuario
$totalRows  = count($callsFiltered);
$totalPages = max(1, (int)ceil($totalRows / $pageSize));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $pageSize;
$callsPage  = array_slice($callsFiltered, $offset, $pageSize);

// texto info rango
$rangeText = (new DateTime($startDate))->format('d M Y')
          . ' → '
          . (new DateTime($endDate))->format('d M Y');

// badges superiores de estado API
$badgesRight = '';
if ($truncated) {
    $badgesRight .= '<span class="badge badge-red">TRUNCATED</span> ';
}
// Indicador de fuente de datos
$badgesRight .= '<span class="badge '.($dataSource === 'Cache' ? 'badge-orange' : 'badge-green').'">Source: '.$dataSource.'</span> ';
$badgesRight .= '<span class="badge badge-green">API pages: ' . (int)$pagesPulled . '</span> ';
$badgesRight .= '<span class="badge badge-green">Fetched: '    . (int)$rawCountFetched   . '</span> ';
$badgesRight .= '<span class="badge badge-green">Unique: '     . (int)$rawCountUnique    . '</span> ';
if ($dedupRemoved > 0) {
    $badgesRight .= '<span class="badge badge-orange">De-duped: ' . (int)$dedupRemoved . '</span> ';
}

// base URL para la paginación
$query = $_GET;
unset($query['page']);
$baseUrl = basename(__FILE__) . (count($query) ? '?' . http_build_query($query) . '&' : '?');

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>MightyCall Monitor</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="monitor_calls.css?v=20251027">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body>

<div class="container">

    <h1 class="page-title">
        <i class="fas fa-phone-volume"></i>
        MightyCall Monitor
    </h1>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <strong>Error:</strong> <?= h($error) ?>
        </div>
    <?php endif; ?>

    <!-- Info rango + estado API -->
    <div class="range-row">
        <div class="range-left">
            <strong>Range:</strong> <?= h($rangeText) ?>
        </div>
        <div class="range-right"><?= $badgesRight ?></div>
        <div class="range-now">
            <?= h(gmdate('d/m/Y H:i:s')) ?> UTC
        </div>
    </div>

    <!-- FILTROS -->
    <form method="get" class="filters-form">
        <input type="hidden" name="page" value="1">
        <input type="hidden" name="preset" id="presetInput" value="<?= h($preset) ?>">

        <div class="filters-grid">

            <div class="filter-group">
                <label>Start date</label>
                <input type="date" name="start_date" value="<?= h($startDate) ?>">
            </div>

            <div class="filter-group">
                <label>End date</label>
                <input type="date" name="end_date" value="<?= h($endDate) ?>">
            </div>

            <div class="filter-group">
                <label>Team member</label>
                <select name="member">
                    <option value="all" <?= $memberKeyNorm === 'all' ? 'selected' : '' ?>>All</option>
                    <?php foreach ($teamNames as $n): ?>
                        <option value="<?= h($n) ?>" <?= norm($n) === $memberKeyNorm ? 'selected' : '' ?>>
                            <?= h($n) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Auto-refresh</label>
                <select name="auto_refresh">
                    <option value="0"   <?= $autoRefresh === 0   ? 'selected' : '' ?>>Off</option>
                    <option value="30"  <?= $autoRefresh === 30  ? 'selected' : '' ?>>30s</option>
                    <option value="60"  <?= $autoRefresh === 60  ? 'selected' : '' ?>>60s</option>
                    <option value="120" <?= $autoRefresh === 120 ? 'selected' : '' ?>>120s</option>
                </select>
            </div>

            <div class="filter-group">
                <label>Rows / page</label>
                <select name="page_size">
                    <?php
                    $pageSizeOptions = [25,50,100,200,500];
                    foreach ($pageSizeOptions as $opt) {
                        $sel = ($userPageSize === $opt) ? 'selected' : '';
                        echo '<option value="'.(int)$opt.'" '.$sel.'>'.(int)$opt.'</option>';
                    }
                    ?>
                </select>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a class="btn btn-secondary"
                   href="<?= basename(__FILE__) ?>?preset=1m">
                    Clear
                </a>
                <a class="btn btn-outline-secondary"
                   href="<?= basename(__FILE__) ?>?export=csv&start_date=<?= h($startDate) ?>&end_date=<?= h($endDate) ?>&member=<?= h($memberKey) ?>">
                    <i class="fas fa-file-csv"></i> CSV
                </a>
            </div>

        </div>

        <!-- PRESETS -->
        <div class="preset-row">
            <?php
            $presetLabels = [
                '1m'        => 'Last 30d',
                'today'     => 'Today',
                'this_week' => 'This Week',
                'last_week' => 'Last Week',
                '3m'        => 'Last 3m'
            ];
            foreach ($presetLabels as $key => $label):
            ?>
            <a
                class="btn-mini-outline <?= $preset === $key ? 'active' : '' ?>"
                href="<?= basename(__FILE__) ?>?preset=<?= h($key) ?>&member=<?= urlencode($memberKey) ?>&auto_refresh=<?= (int)$autoRefresh ?>&page_size=<?= (int)$userPageSize ?>"
            >
                <?= h($label) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </form>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-card-title">Total Calls</div>
            <div class="stat-card-value"><?= (int)$stats['total'] ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-card-title">Answered</div>
            <div class="stat-card-value"><?= (int)$stats['answered'] ?></div>
            <div class="stat-card-sub">
                Rate:
                <?= $stats['total']>0
                    ? (int)round($stats['answered']*100/$stats['total'])
                    : 0 ?>%
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-card-title">Avg Duration</div>
            <div class="stat-card-value"><?= formatDurationSeconds((int)$stats['avg']) ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-card-title">Incoming</div>
            <div class="stat-card-value"><?= (int)$stats['incoming'] ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-card-title">Outgoing</div>
            <div class="stat-card-value"><?= (int)$stats['outgoing'] ?></div>
        </div>
    </div>

    <!-- CHART -->
    <div class="chart-card">
        <div class="chart-header">
            <h3><i class="fas fa-chart-line"></i> Calls by Agent</h3>
        </div>
        <div class="chart-wrapper">
            <canvas id="callsByAgent"></canvas>
        </div>
    </div>

    <!-- TABLA -->
    <table class="table">
        <thead>
            <tr>
                <th>Date/Time (UTC)</th>
                <th>Direction</th>
                <th>From</th>
                <th>To</th>
                <th>Duration</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($callsPage)): ?>
                <tr>
                    <td colspan="6" style="text-align:center;">No records for the selected period.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($callsPage as $c): ?>
                    <?php
                        $dirTxt = directionText($c->direction ?? '');
                        $durSec = normalizeDurationToSeconds($c->duration ?? 0);
                        [$from, $to] = resolveEndpointsForCall($c);

                        $statusRaw = ucfirst($c->callStatus ?? 'N/A');
                        $statusLow = strtolower($statusRaw);
                        switch ($statusLow) {
                            case 'connected':
                                $statusClass = 'badge-green';
                                break;
                            case 'missed':
                            case 'failed':
                                $statusClass = 'badge-red';
                                break;
                            default:
                                $statusClass = 'badge-orange';
                                break;
                        }
                    ?>
                    <tr>
                        <td><?= h(gmdate('d/m/Y H:i', strtotime($c->dateTimeUtc ?? 'now'))) ?></td>
                        <td><?= h($dirTxt) ?></td>
                        <td><?= h($from) ?></td>
                        <td><?= h($to) ?></td>
                        <td><?= formatDurationSeconds($durSec) ?></td>
                        <td><span class="badge <?= $statusClass ?>"><?= h($statusRaw) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- PAGINACIÓN -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?= $baseUrl ?>page=<?= $page - 1 ?>" class="page-link">
                    <i class="fas fa-chevron-left"></i> Prev
                </a>
            <?php endif; ?>

            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <a class="page-link <?= $i === $page ? 'active' : '' ?>"
                   href="<?= $baseUrl ?>page=<?= $i ?>"><?= $i ?></a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?= $baseUrl ?>page=<?= $page + 1 ?>" class="page-link">
                    Next <i class="fas fa-chevron-right"></i>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div><!-- .container -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1"></script>
<script>
// Auto-refresh
<?php if ($autoRefresh > 0): ?>
    setTimeout(() => { window.location.reload(); }, <?= (int)$autoRefresh * 1000 ?>);
<?php endif; ?>

// Chart.js - tema claro
(() => {
    const labels   = <?= json_encode($chartLabels) ?>;
    const datasets = <?= json_encode($chartDatasets) ?>;

    const ctx = document.getElementById('callsByAgent').getContext('2d');
    if (window.callsByAgentChart) window.callsByAgentChart.destroy();

    window.callsByAgentChart = new Chart(ctx, {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        color: '#333',
                        boxWidth: 12,
                        usePointStyle: true,
                        pointStyle: 'circle',
                        font: { size: 11 }
                    }
                },
                tooltip: {
                    callbacks: {
                        title: items => `Date: ${items[0].label}`,
                        label: item  => `${item.dataset.label}: ${item.formattedValue} call(s)`
                    }
                }
            },
            scales: {
                x: {
                    grid:  { color: 'rgba(0,0,0,0.05)' },
                    ticks: {
                        color: '#555',
                        maxRotation: 0,
                        autoSkip: true,
                        font: { size: 10 }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid:  { color: 'rgba(0,0,0,0.05)' },
                    ticks: {
                        color: '#555',
                        precision: 0,
                        font: { size: 10 }
                    }
                }
            }
        }
    });
})();
</script>
</body>
</html>
