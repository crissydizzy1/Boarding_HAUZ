<?php
require_once __DIR__ . '/auth.php';
requireRole('tenant');

$userId = (int) $_SESSION['user_id'];
$stmt = $conn->prepare("
    SELECT id
    FROM tenants
    WHERE user_id = ?
    ORDER BY (LOWER(status) = 'active') DESC, id DESC
    LIMIT 1
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$tenant = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tenant) {
    http_response_code(404);
    exit('Tenant record was not found.');
}

$tenantId = (int) $tenant['id'];
$stmt = $conn->prepare('
    SELECT payment_date, amount_paid, payment_method, status
    FROM payments
    WHERE tenant_id = ?
    ORDER BY payment_date DESC, id DESC
');
$stmt->bind_param('i', $tenantId);
$stmt->execute();
$result = $stmt->get_result();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="boarding-hauz-payment-statement.csv"');
header('Cache-Control: private, no-store');

$output = fopen('php://output', 'wb');
if ($output === false) {
    $stmt->close();
    http_response_code(500);
    exit('Could not generate the payment statement.');
}

fputcsv($output, ['Payment date', 'Amount (PHP)', 'Payment method', 'Status']);
$csvText = static function (string $value): string {
    return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) ? "'" . $value : $value;
};
while ($payment = $result->fetch_assoc()) {
    $amount = number_format((float) $payment['amount_paid'], 2, '.', '');
    fputcsv($output, [
        $csvText((string) $payment['payment_date']),
        $amount,
        $csvText((string) $payment['payment_method']),
        $csvText((string) $payment['status']),
    ]);
}

fclose($output);
$stmt->close();
