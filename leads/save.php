<?php
// /leads/save.php
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Lead.php';
require_once __DIR__ . '/../lib/LeadLock.php';

if (!Auth::check()) {
    header('Location: /auth/login.php');
    exit;
}

$user   = Auth::user();
$leadId = (int)($_POST['id'] ?? 0);

// Actualizar datos
Lead::update($leadId, $_POST);

// Liberar el lock
LeadLock::release($leadId, $user['id']);

header('Location: view.php?id=' . $leadId);
exit;
