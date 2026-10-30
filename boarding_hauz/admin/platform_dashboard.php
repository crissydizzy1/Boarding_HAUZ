<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/room_occupancy.php';
requireRole('super_admin');

$admin_title = 'Platform Overview';
$adminUser = currentUser();
$queryError = false;
$landlordCounts = ['total' => 0, 'active' => 0, 'inactive' => 0];
$tenantCounts = ['total' => 0, 'active' => 0];
$branchCount = 0;
$totalRooms = 0;
$totalCapacity = 0;
$totalOccupants = 0;
$availableSlots = 0;
$maintenanceRooms = 0;
$pendingPlatformCases = 0;

try {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total,
            SUM(status = 'active') AS active,
            SUM(status = 'inactive') AS inactive
        FROM users
        WHERE role = 'landlord'
    ");
    $stmt->execute();
    $landlordCounts = $stmt->get_result()->fetch_assoc() ?: $landlordCounts;
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total, SUM(status = 'active') AS active
        FROM users
        WHERE role = 'tenant'
    ");
    $stmt->execute();
    $tenantCounts = $stmt->get_result()->fetch_assoc() ?: $tenantCounts;
    $stmt->close();

    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM branches');
    $stmt->execute();
    $branchCount = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $stmt = $conn->prepare('SELECT id, capacity, status FROM rooms');
    $stmt->execute();
    $rooms = attachRoomOccupancy($conn, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    foreach ($rooms as $room) {
        $totalRooms++;
        $totalCapacity += max(0, (int) $room['capacity']);
        $totalOccupants += (int) $room['current_occupants'];
        if ($room['accepts_tenants']) {
            $availableSlots += (int) $room['available_slots'];
        }
        if ($room['occupancy_status'] === 'Maintenance') {
            $maintenanceRooms++;
        }
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM public_inquiries
        WHERE inquiry_type = 'platform' AND status = 'Unread'
    ");
    $stmt->execute();
    $pendingPlatformCases = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Platform dashboard data query failed: ' . $exception->getMessage());
    $queryError = true;
}

$occupancyPercent = $totalCapacity > 0
    ? min(100, (int) round(($totalOccupants / $totalCapacity) * 100))
    : 0;

include __DIR__ . '/admin_header.php';
?>
<style>
    .platform-dashboard{max-width:1200px;margin:0 auto;padding:32px 24px 56px}
    .platform-dashboard-header{margin-bottom:24px;padding:24px 28px;border:1px solid #236b67;border-radius:16px;background:#0d5b50}
    .platform-dashboard-header span{display:block;margin-bottom:6px;color:#a6e4d1;font-size:11px;font-weight:700;letter-spacing:.1em}
    .platform-dashboard-header h1{margin:0 0 7px;color:#fff;font:700 30px 'Playfair Display',serif}
    .platform-dashboard-header p{margin:0;color:#dff3f7;font-size:13px;line-height:1.55}
    .platform-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px;margin-bottom:20px}
    .platform-metric,.platform-panel{border:1px solid #d7e6ea;border-radius:9px;background:#fff}
    .platform-metric{padding:17px}
    .platform-metric span{display:block;color:#536466;font-size:12px;font-weight:700}
    .platform-metric strong{display:block;margin:8px 0 4px;color:#0d1c1e;font-size:25px}
    .platform-metric small{color:#667b7e;font-size:11px}
    .platform-panel{margin-bottom:18px;padding:20px}
    .platform-panel h2{margin:0 0 7px;color:#0d1c1e;font:700 21px 'Playfair Display',serif}
    .platform-panel p{margin:0 0 13px;color:#667b7e;font-size:12px;line-height:1.55}
    .platform-progress{width:100%;height:10px;accent-color:#22b486}
    .platform-links{display:flex;flex-wrap:wrap;gap:8px}
    .platform-links a{display:inline-flex;align-items:center;min-height:36px;padding:8px 12px;border-radius:6px;background:#edf9fc;color:#236b67;font-size:12px;font-weight:700;text-decoration:none}
    .platform-warning{margin-bottom:16px;padding:12px 15px;border-radius:7px;background:#fff4d8;color:#806300;font-size:13px}
    @media(max-width:850px){.platform-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:520px){.platform-dashboard{padding:22px 14px}.platform-metrics{grid-template-columns:1fr}}
</style>
<main class="platform-dashboard">
    <header class="platform-dashboard-header">
        <span>PLATFORM ADMINISTRATION</span>
        <h1>Platform Overview</h1>
        <p>Welcome, <?= e($adminUser['username'] ?? 'Admin'); ?>. This overview is read-only for property operations; landlords remain responsible for their own rooms, tenants, payments, and maintenance.</p>
    </header>

    <?php if ($queryError): ?>
        <p class="platform-warning" role="alert">Some platform summary data could not be loaded. Check the application log.</p>
    <?php endif; ?>

    <section class="platform-metrics" aria-label="Platform summary">
        <article class="platform-metric">
            <span>Landlord Accounts</span>
            <strong><?= (int) $landlordCounts['total']; ?></strong>
            <small><?= (int) $landlordCounts['active']; ?> active · <?= (int) $landlordCounts['inactive']; ?> suspended</small>
        </article>
        <article class="platform-metric">
            <span>Tenant Accounts</span>
            <strong><?= (int) $tenantCounts['total']; ?></strong>
            <small><?= (int) $tenantCounts['active']; ?> active across the platform</small>
        </article>
        <article class="platform-metric">
            <span>Properties / Branches</span>
            <strong><?= $branchCount; ?></strong>
            <small><?= $totalRooms; ?> rooms across all landlords</small>
        </article>
        <article class="platform-metric">
            <span>Unread Platform Inquiries</span>
            <strong><?= $pendingPlatformCases; ?></strong>
            <small>Contact Us account and platform concerns</small>
        </article>
    </section>

    <section class="platform-panel">
        <h2>Platform-wide occupancy</h2>
        <p><?= $totalOccupants; ?> active occupants across <?= $totalCapacity; ?> configured spaces; <?= $availableSlots; ?> spaces remain. <?= $maintenanceRooms; ?> rooms are marked for maintenance.</p>
        <progress class="platform-progress" max="100" value="<?= $occupancyPercent; ?>" aria-label="Platform room occupancy <?= $occupancyPercent; ?> percent"></progress>
        <p><?= $occupancyPercent; ?>% capacity occupied · <?= $totalRooms; ?> rooms total</p>
    </section>

    <section class="platform-panel">
        <h2>Administrative tools</h2>
        <p>Manage landlord accounts, review platform support inquiries, and monitor aggregate platform activity.</p>
        <div class="platform-links">
            <a href="/boarding_hauz/admin/landlords.php">Manage Landlords</a>
            <a href="/boarding_hauz/admin/public_inquiries.php">Review Inquiries</a>
            <a href="/boarding_hauz/admin/platform_reports.php">Platform Reports</a>
        </div>
    </section>
</main>
