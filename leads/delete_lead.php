<?php
// leads/delete_lead.php
declare(strict_types=1);

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/db.php';

header('Content-Type: application/json');

try {
    if (!Auth::check()) {
        throw new Exception('Access denied.', 403);
    }

    $user = Auth::user();
    $roles = $user['roles'] ?? [];
    $canDelete = in_array('admin', $roles, true) || in_array('owner', $roles, true);

    if (!$canDelete) {
        throw new Exception('Permission denied.', 403);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed.', 405);
    }

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        throw new Exception('Invalid CSRF token.', 403);
    }

    $leadId = (int)($_POST['lead_id'] ?? 0);
    if ($leadId <= 0) {
        throw new Exception('Invalid lead ID.', 400);
    }

    $pdo = getPDO();

    // Optional: Verify lead exists (not required but safe)
    $exists = $pdo->prepare("SELECT 1 FROM leads WHERE id = ?");
    $exists->execute([$leadId]);
    if (!$exists->fetch()) {
        throw new Exception('Lead not found.', 404);
    }

    // Delete lead and associated documents
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM lead_documents WHERE lead_id = ?")->execute([$leadId]);
    $pdo->prepare("DELETE FROM leads WHERE id = ?")->execute([$leadId]);
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Lead deleted successfully.']);
} catch (Exception $e) {
    $code = $e->getCode() ?: 500;
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}