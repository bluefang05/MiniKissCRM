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

// Sanitize and prepare lead data
$leadData = [
    'external_id' => trim($_POST['external_id'] ?? ''),
    'prefix'      => trim($_POST['prefix'] ?? ''),
    'first_name'  => trim($_POST['first_name'] ?? ''),
    'mi'          => trim($_POST['mi'] ?? ''),
    'last_name'   => trim($_POST['last_name'] ?? ''),
    'phone'       => trim($_POST['phone'] ?? ''),
    'email'       => trim($_POST['email'] ?? ''),
    'address_line'=> trim($_POST['address_line'] ?? ''),
    'suite_apt'   => trim($_POST['suite_apt'] ?? ''),
    'city'        => trim($_POST['city'] ?? ''),
    'state'       => trim($_POST['state'] ?? ''),
    'zip5'        => trim($_POST['zip5'] ?? ''),
    'zip4'        => trim($_POST['zip4'] ?? ''),
    'delivery_point_bar_code' => trim($_POST['delivery_point_bar_code'] ?? ''),
    'carrier_route'           => trim($_POST['carrier_route'] ?? ''),
    'fips_county_code'        => trim($_POST['fips_county_code'] ?? ''),
    'county_name'             => trim($_POST['county_name'] ?? ''),
    'age'                     => (int)($_POST['age'] ?? null),
    'insurance_interest_id'   => (int)($_POST['insurance_interest_id'] ?? null),
    'source_id'               => (int)($_POST['source_id'] ?? 0),
    'status_id'               => (int)($_POST['status_id'] ?? 1),
    // Set do_not_call based on checkbox
    'do_not_call'             => !empty($_POST['do_not_call']) ? 1 : 0,
    'notes'                   => trim($_POST['notes'] ?? '')
];

// Save updated lead
Lead::update($leadId, $leadData);

// Release lock
LeadLock::release($leadId, $user['id']);

header('Location: view.php?id=' . $leadId);
exit;