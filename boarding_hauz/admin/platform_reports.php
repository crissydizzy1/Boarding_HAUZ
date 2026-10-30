<?php
require_once __DIR__ . '/../auth.php';
requireRole('super_admin');

$admin_title = 'Platform Reports';
$queryError = false;
$registrationCounts = [];
$accountCounts = [];
$branchSummaries = [];
$inquiryCounts = [];

try {
    $stmt = $conn->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_key, COUNT(*) AS total
        FROM users
        WHERE role = 'landlord'
          AND created_at >= DATE_FORMAT(DATE_SUB(CURRENT_DATE, INTERVAL 5 MONTH), '%Y-%m-01')
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month_key
    ");
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $registrationCounts[$row['month_key']] = (int) $row['total'];
    }
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT role, status, COUNT(*) AS total
        FROM users
        GROUP BY role, status
        ORDER BY role, status
    ");
    $stmt->execute();
    $accountCounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT b.id, b.branch_name, b.location, u.username AS landlord_name,
            COALESCE(r.total_rooms, 0) AS total_rooms,
            COALESCE(r.total_capacity, 0) AS total_capacity,
            COALESCE(r.maintenance_rooms, 0) AS maintenance_rooms,
            COALESCE(t.active_tenants, 0) AS active_tenants
        FROM branches b
        INNER JOIN users u ON u.id = b.landlord_id
        LEFT JOIN (
            SELECT branch_id, COUNT(*) AS total_rooms, SUM(capacity) AS total_capacity,
                SUM(status = 'Maintenance') AS maintenance_rooms
            FROM rooms
            GROUP BY branch_id
        ) r ON r.branch_id = b.id
        LEFT JOIN (
            SELECT branch_id, COUNT(*) AS active_tenants
            FROM tenants
            WHERE status = 'active'
            GROUP BY branch_id
        ) t ON t.branch_id = b.id
        ORDER BY b.branch_name, b.id
    ");
    $stmt->execute();
    $branchSummaries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT inquiry_type, status, COUNT(*) AS total
        FROM public_inquiries
        GROUP BY inquiry_type, status
        ORDER BY inquiry_type, status
    ");
    $stmt->execute();
    $inquiryCounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Platform reports query failed: ' . $exception->getMessage());
    $queryError = true;
}

$monthLabels = [];
$monthCursor = new DateTimeImmutable('first day of this month');
for ($monthOffset = 5; $monthOffset >= 0; $monthOffset--) {
    $month = $monthCursor->modify('-' . $monthOffset . ' months');
    $monthKey = $month->format('Y-m');
    $monthLabels[] = [
        'label' => $month->format('M Y'),
        'total' => $registrationCounts[$monthKey] ?? 0,
    ];
}
$maxRegistrations = max(1, ...array_column($monthLabels, 'total'));

include __DIR__ . '/admin_header.php';
?>
<style>
    .reports-page{max-width:1200px;margin:0 auto;padding:32px 24px 56px}
    .reports-heading{margin-bottom:22px;padding:24px 28px;border:1px solid #236b67;border-radius:16px;background:#0d5b50}
    .reports-heading h1{margin:0 0 7px;color:#fff;font:700 30px 'Playfair Display',serif}
    .reports-heading p{margin:0;color:#dff3f7;font-size:13px;line-height:1.55}
    .reports-panel{margin-bottom:18px;padding:20px;border:1px solid #d7e6ea;border-radius:9px;background:#fff}
    .reports-panel h2{margin:0 0 8px;color:#0d1c1e;font:700 21px 'Playfair Display',serif}
    .reports-panel>p{margin:0 0 14px;color:#667b7e;font-size:12px;line-height:1.55}
    .reports-warning{margin-bottom:16px;padding:12px 15px;border-radius:7px;background:#fff4d8;color:#806300;font-size:13px}
    .reports-table-wrap{overflow-x:auto}
    .reports-table{width:100%;min-width:600px;border-collapse:collapse;text-align:left;font-size:13px}
    .reports-table th,.reports-table td{padding:10px;border-bottom:1px solid #edf2f3;vertical-align:top}
    .reports-table th{color:#536466}
    .registration-bars{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));align-items:end;gap:12px;min-height:180px}
    .registration-month{display:grid;grid-template-rows:1fr auto;gap:8px;height:180px;text-align:center}
    .registration-bar-wrap{display:flex;align-items:end;justify-content:center;height:140px}
    .registration-bar{width:min(48px,70%);min-height:3px;border-radius:5px 5px 0 0;background:#22b486}
    .registration-month strong{color:#0d1c1e;font-size:13px}
    .registration-month span{color:#667b7e;font-size:11px}
    @media(max-width:650px){.reports-page{padding:22px 14px}.registration-bars{gap:5px}.registration-month{font-size:10px}}
</style>
<main class="reports-page">
    <header class="reports-heading">
        <h1>Platform Reports</h1>
        <p>Read-only summaries of account registrations, branch occupancy, and public inquiry status. Property operations remain with each landlord.</p>
    </header>

    <?php if ($queryError): ?>
        <p class="reports-warning" role="alert">Some report data could not be loaded. Check the application log.</p>
    <?php endif; ?>

    <section class="reports-panel">
        <h2>Landlord registrations</h2>
        <p>New landlord accounts created per month during the last six calendar months.</p>
        <div class="registration-bars" role="img" aria-label="Landlord registrations over six months">
            <?php foreach ($monthLabels as $month): ?>
                <div class="registration-month" aria-label="<?= e($month['label']); ?>: <?= (int) $month['total']; ?> registrations">
                    <div class="registration-bar-wrap">
                        <div class="registration-bar" style="height:<?= max(3, (int) round(((int) $month['total'] / $maxRegistrations) * 100)); ?>%"></div>
                    </div>
                    <div><strong><?= (int) $month['total']; ?></strong><span><?= e($month['label']); ?></span></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="reports-panel">
        <h2>Registered users by role and status</h2>
        <?php if ($accountCounts): ?>
            <div class="reports-table-wrap"><table class="reports-table">
                <thead><tr><th>Role</th><th>Account status</th><th>Accounts</th></tr></thead>
                <tbody><?php foreach ($accountCounts as $account): ?>
                    <tr>
                        <td><?= e(ucwords(str_replace('_', ' ', $account['role']))); ?></td>
                        <td><?= e(ucfirst($account['status'])); ?></td>
                        <td><?= (int) $account['total']; ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php else: ?>
            <p>No account records are available.</p>
        <?php endif; ?>
    </section>

    <section class="reports-panel">
        <h2>Property and occupancy summary</h2>
        <?php if ($branchSummaries): ?>
            <div class="reports-table-wrap"><table class="reports-table">
                <thead><tr><th>Property</th><th>Landlord</th><th>Rooms</th><th>Active tenants / Capacity</th><th>Maintenance rooms</th></tr></thead>
                <tbody><?php foreach ($branchSummaries as $branch): ?>
                    <tr>
                        <td><?= e($branch['branch_name']); ?><br><span><?= e($branch['location']); ?></span></td>
                        <td><?= e($branch['landlord_name']); ?></td>
                        <td><?= (int) $branch['total_rooms']; ?></td>
                        <td><?= (int) $branch['active_tenants']; ?> / <?= (int) $branch['total_capacity']; ?></td>
                        <td><?= (int) $branch['maintenance_rooms']; ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php else: ?>
            <p>No property records are available.</p>
        <?php endif; ?>
    </section>

    <section class="reports-panel">
        <h2>Public inquiry volume</h2>
        <p>Contact Us submissions grouped by routing type and read status. This report does not expose inquiry message contents.</p>
        <?php if ($inquiryCounts): ?>
            <div class="reports-table-wrap"><table class="reports-table">
                <thead><tr><th>Inquiry type</th><th>Status</th><th>Submissions</th></tr></thead>
                <tbody><?php foreach ($inquiryCounts as $inquiry): ?>
                    <tr>
                        <td><?= e(ucfirst($inquiry['inquiry_type'])); ?></td>
                        <td><?= e($inquiry['status']); ?></td>
                        <td><?= (int) $inquiry['total']; ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php else: ?>
            <p>No inquiry records are available.</p>
        <?php endif; ?>
    </section>
</main>
