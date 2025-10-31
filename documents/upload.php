<?php
// documents/upload.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user   = Auth::user();
$leadId = (int)($_POST['lead_id'] ?? 0);

// 1) Validate
if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    die('Upload error');
}

// 2) Ensure uploads dir exists
$uploadDir = __DIR__ . '/../uploads/lead_documents/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// 3) Move file
$originalName = basename($_FILES['document']['name']);
$ext          = pathinfo($originalName, PATHINFO_EXTENSION);
$safeName     = uniqid('doc_') . '.' . $ext;
$targetPath   = $uploadDir . $safeName;
if (!move_uploaded_file($_FILES['document']['tmp_name'], $targetPath)) {
    die('Failed to move uploaded file');
}

// 4) Insert DB record
$pdo = getPDO();
$stmt = $pdo->prepare("
    INSERT INTO lead_documents
      (lead_id, title, file_name, file_path, file_type, uploaded_by)
    VALUES
      (?,       ?,     ?,         ?,         ?,         ?)
");
$title = pathinfo($originalName, PATHINFO_FILENAME);  // or pull from a form field
$stmt->execute([
    $leadId,
    $title,
    $originalName,
    '/../uploads/lead_documents/' . $safeName,
    $_FILES['document']['type'],
    $user['id']
]);

// 5) Redirect back
header("Location: ../leads/view.php?id={$leadId}");
exit;
