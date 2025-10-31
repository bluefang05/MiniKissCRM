<?php
// /leads/release.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/LeadLock.php';

if (!Auth::check() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit;
}

$user   = Auth::user();
$leadId = (int)($_POST['lead_id'] ?? 0);
LeadLock::release($leadId, $user['id']);
http_response_code(200);
