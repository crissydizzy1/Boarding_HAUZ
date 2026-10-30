<?php
require_once __DIR__ . '/../auth.php';
requireRole('landlord');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
$branchIdValue = is_scalar($_POST['branch_id'] ?? null) ? (string) $_POST['branch_id'] : '';
$branchId = ctype_digit($branchIdValue) ? (int) $branchIdValue : 0;
if (!hash_equals($_SESSION['branch_switch_csrf'] ?? '', $postedToken) || $branchId <= 0) {
    http_response_code(400);
    exit('The branch selection could not be verified.');
}

$landlordId = (int) $_SESSION['user_id'];
$stmt = $conn->prepare('SELECT id FROM branches WHERE id = ? AND landlord_id = ? LIMIT 1');
$stmt->bind_param('ii', $branchId, $landlordId);
$stmt->execute();
$branch = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$branch) {
    http_response_code(403);
    exit('You are not authorized to access that branch.');
}

$_SESSION['branch_id'] = $branchId;
$_SESSION['branch_switch_csrf'] = bin2hex(random_bytes(32));
redirectTo(dashboardPathForRole());
