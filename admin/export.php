<?php
require_once __DIR__.'/../lib/Auth.php';
require_once __DIR__.'/../lib/db.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$user_id = $user['id'];

$range = $_GET['range'] ?? 'today';
$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;

$pdo = getPDO();

$condition = '';
$bind = [];

if ($from && $to) {
    $fromDate = date('Y-m-d', strtotime($from));
    $toDate = date('Y-m-d', strtotime($to));
    $condition = "i.created_at BETWEEN '$fromDate 00:00:00' AND '$toDate 23:59:59'";
} else {
    $range = in_array($range, [1, 7, 30]) ? $range : 7;
    $condition = "i.created_at >= DATE_SUB(CURDATE(), INTERVAL $range DAY)";
}

$sql = "
    SELECT 
        i.created_at AS 'Call Date',
        CONCAT(l.first_name, ' ', l.last_name) AS 'Lead Name',
        d.name AS 'Disposition',
        i.duration_seconds AS 'Duration (Seconds)'
    FROM interactions i
    JOIN leads l ON l.id = i.lead_id
    JOIN dispositions d ON d.id = i.disposition_id
    WHERE i.user_id = ?
      AND $condition
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="call_metrics_export.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, array_keys($results[0] ?? []));
foreach ($results as $row) {
    fputcsv($output, array_values($row));
}
fclose($output);
exit;