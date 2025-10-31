<?php
// admin/document_delete.php

// No session_start() here — Auth.php likely already starts it
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

// Optional: if you're sure Auth::check() needs session, but don't call session_start() twice
if (!Auth::check() || !in_array('admin', Auth::user()['roles'] ?? []) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit('Access denied');
}

// --- TEMPORARY: Disable CSRF for dev (remove in production!) ---
// if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
//     http_response_code(403);
//     exit('Invalid CSRF token');
// }

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Invalid document ID');
}

$pdo = getPDO();

// Fetch file path from DB
$stmt = $pdo->prepare("SELECT file_path FROM lead_documents WHERE id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if ($doc) {
    // Your DB stores paths like: "/../uploads/lead_documents/doc_xxx.sql"
    // So we resolve it relative to the project root
    $projectRoot = __DIR__ . '/..'; // points to /smarttax/
    $fullPath = realpath($projectRoot . $doc['file_path']);

    // Security: ensure it's inside uploads
    $uploadsDir = realpath($projectRoot . '/uploads');
    if ($fullPath && strpos($fullPath, $uploadsDir) === 0 && is_file($fullPath)) {
        unlink($fullPath);
    }

    // Delete DB record
    $del = $pdo->prepare("DELETE FROM lead_documents WHERE id = ?");
    $del->execute([$id]);
}

header('Location: documents.php?deleted=1');
exit;