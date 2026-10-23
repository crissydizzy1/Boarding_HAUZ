<?php
require_once __DIR__ . '/../auth.php';
requireRole('landlord');

$paymentIdValue = is_scalar($_GET['id'] ?? null) ? (string) $_GET['id'] : '';
$paymentId = ctype_digit($paymentIdValue) ? (int) $paymentIdValue : 0;
if ($paymentId <= 0) {
    http_response_code(400);
    exit('Invalid payment proof request.');
}

$stmt = $conn->prepare('
    SELECT pay.proof_path
    FROM payments pay
    WHERE pay.id = ? AND pay.branch_id = ?
    LIMIT 1
');
$branchId = requireSelectedBranch();
$stmt->bind_param('ii', $paymentId, $branchId);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

$storedPath = is_array($payment) && is_string($payment['proof_path'] ?? null) ? $payment['proof_path'] : '';
if (!preg_match('#^uploads/payment-proofs/payment-[0-9]+-[a-f0-9]{32}\.(?:svg|png|jpe?g|gif|webp|pdf)$#', $storedPath)) {
    http_response_code(404);
    exit('Payment proof was not found.');
}

$proofDirectory = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'payment-proofs');
$proofPath = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath));
if ($proofDirectory === false || $proofPath === false || !str_starts_with($proofPath, $proofDirectory . DIRECTORY_SEPARATOR) || !is_file($proofPath)) {
    http_response_code(404);
    exit('Payment proof was not found.');
}

$extension = strtolower(pathinfo($proofPath, PATHINFO_EXTENSION));
$mimeTypes = [
    'svg' => 'image/svg+xml',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'pdf' => 'application/pdf',
];
header('Content-Type: ' . $mimeTypes[$extension]);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Security-Policy: default-src \'none\'; sandbox');
header('Content-Disposition: ' . ($extension === 'svg' ? 'attachment' : 'inline') . '; filename="' . basename($proofPath) . '"');
header('Content-Length: ' . (string) filesize($proofPath));
readfile($proofPath);
