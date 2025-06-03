<?php
// admin/document_delete.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

session_start();
if (!Auth::check() || !in_array('admin', Auth::user()['roles'] ?? []) || $_SERVER['REQUEST_METHOD']!=='POST') {
    http_response_code(403);
    exit;
}

if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

$id = (int)($_POST['id'] ?? 0);
$pdo = getPDO();

// fetch file path
$stmt = $pdo->prepare("SELECT file_path FROM lead_documents WHERE id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if ($doc) {
    // attempt unlink
    $full = __DIR__ . '/../uploads/lead_documents/' . basename($doc['file_path']);
    if (is_file($full)) {
        @unlink($full);
    }
    // delete row
    $del = $pdo->prepare("DELETE FROM lead_documents WHERE id = ?");
    $del->execute([$id]);
}

header('Location: documents.php');
exit;
