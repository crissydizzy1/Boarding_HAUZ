<?php
require_once __DIR__ . '/../auth.php';
requireRole('landlord');

$legacyRoutes = [
    'admin_dashboard.php' => 'dashboard.php',
    'dashboard.php' => 'dashboard.php',
    'rooms.php' => 'rooms.php',
    'tenants.php' => 'tenants.php',
    'payments.php' => 'payments.php',
    'payment-proof.php' => 'payment-proof.php',
    'maintenance.php' => 'maintenance.php',
    'messages.php' => 'messages.php',
    'inquiries.php' => 'inquiries.php',
    'branch-switch.php' => 'branch-switch.php',
];
$legacyPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$landlordPage = $legacyRoutes[$legacyPage] ?? null;

if ($landlordPage === null) {
    http_response_code(404);
    exit('Page not found.');
}

$queryString = is_string($_SERVER['QUERY_STRING'] ?? null) ? $_SERVER['QUERY_STRING'] : '';
$destination = '/boarding_hauz/landlord/' . $landlordPage;
if ($queryString !== '') {
    $destination .= '?' . $queryString;
}

header('Location: ' . $destination, true, 307);
exit;
