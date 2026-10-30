<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/room_occupancy.php';
requireRole('landlord');

$admin_title = 'Dashboard';
$user = currentUser();
$branchId = currentBranchId();

$adminName = $user['username'] ?? $_SESSION['user_name'] ?? 'Admin';
$totalRooms = 0;
$availableRooms = 0;
$occupiedRooms = 0;
$totalCapacity = 0;
$totalOccupants = 0;
$totalTenants = 0;
$pendingPayments = 0;
$pendingMaintenance = 0;
$pendingRoomApplications = 0;
$hasRoomRequestsTable = false;
$hasMaintenanceTable = false;
$unreadPublicInquiries = 0;
$recentPublicInquiries = [];
$hasPublicInquiriesTable = true;

$stmt = $conn->prepare('SELECT id, capacity, status FROM rooms WHERE (? IS NULL OR branch_id = ?)');
$stmt->bind_param('ii', $branchId, $branchId);
$stmt->execute();
$rooms = attachRoomOccupancy($conn, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
$stmt->close();
foreach ($rooms as $room) {
    $totalRooms++;
    $totalCapacity += max(0, (int) $room['capacity']);
    $totalOccupants += (int) $room['current_occupants'];
    if ($room['accepts_tenants']) {
        $availableRooms++;
    }
    if ($room['occupancy_status'] === 'Fully Occupied') {
        $occupiedRooms++;
    }
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM tenants WHERE (? IS NULL OR branch_id = ?)");
$stmt->bind_param('ii', $branchId, $branchId);
$stmt->execute();
$totalTenants = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM payments WHERE status = 'Pending' AND (? IS NULL OR branch_id = ?)");
$stmt->bind_param('ii', $branchId, $branchId);
$stmt->execute();
$pendingPayments = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
$maintenanceTable = 'maintenance_requests';
$stmt->bind_param('s', $maintenanceTable);
$stmt->execute();
$hasMaintenanceTable = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
$stmt->close();

if ($hasMaintenanceTable) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM maintenance_requests WHERE status = 'Pending' AND (? IS NULL OR branch_id = ?)");
    $stmt->bind_param('ii', $branchId, $branchId);
    $stmt->execute();
    $pendingMaintenance = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
$roomRequestsTable = 'room_requests';
$stmt->bind_param('s', $roomRequestsTable);
$stmt->execute();
$hasRoomRequestsTable = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
$stmt->close();

if ($hasRoomRequestsTable) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM room_requests rr INNER JOIN rooms r ON r.id = rr.room_id WHERE rr.status = 'Pending' AND (? IS NULL OR r.branch_id = ?)");
    $stmt->bind_param('ii', $branchId, $branchId);
    $stmt->execute();
    $pendingRoomApplications = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}

try {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM public_inquiries WHERE status = 'Unread' AND (? IS NULL OR branch_id = ?)");
    $stmt->bind_param('ii', $branchId, $branchId);
    $stmt->execute();
    $unreadPublicInquiries = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT id, full_name, email, contact_number, preferred_room_type, status, created_at
        FROM public_inquiries
        WHERE (? IS NULL OR branch_id = ?)
        ORDER BY created_at DESC, id DESC
        LIMIT 5
    ");
    $stmt->bind_param('ii', $branchId, $branchId);
    $stmt->execute();
    $recentPublicInquiries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Admin dashboard public inquiry summary failed: ' . $exception->getMessage());
    $hasPublicInquiriesTable = false;
}

$occupancyPercent = $totalCapacity > 0
    ? min(100, (int) round(($totalOccupants / $totalCapacity) * 100))
    : 0;

include __DIR__ . '/../admin/admin_header.php';
?>

<style>
    .admin-page {
        max-width: 1200px;
        margin: 0 auto;
        padding: 32px 24px 56px;
    }

    .admin-page-header {
        margin-bottom: 24px;
    }

    .admin-page-header h1 {
        margin: 0 0 7px;
        color: #0d1c1e;
        font-family: 'Playfair Display', serif;
        font-size: 30px;
    }

    .admin-page-header p {
        margin: 0;
        color: #536466;
        font-size: 14px;
    }

    .admin-page-label {
        display: block;
        margin-bottom: 8px;
        color: #236b67;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .08em;
    }

    .admin-summary {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 24px;
    }

    .admin-stat,
    .admin-card {
        background: #ffffff;
        border: 1px solid #d7e6ea;
        border-radius: 8px;
    }

    .admin-stat {
        display: block;
        min-height: 136px;
        padding: 19px;
        color: inherit;
        text-decoration: none;
    }

    a.admin-stat:hover,
    a.admin-stat:focus-visible {
        border-color: #22b486;
        outline: 2px solid #22b486;
        outline-offset: 2px;
    }

    .admin-stat-label {
        color: #536466;
        font-size: 12px;
        font-weight: 700;
    }

    .admin-stat-value {
        margin-top: 12px;
        color: #0d1c1e;
        font-size: 29px;
        font-weight: 700;
    }

    .admin-stat-note {
        margin-top: 5px;
        color: #667b7e;
        font-size: 12px;
    }

    .admin-card {
        padding: 22px;
        margin-bottom: 18px;
    }

    .admin-card h2 {
        margin: 0 0 6px;
        color: #0d1c1e;
        font-family: 'Playfair Display', serif;
        font-size: 22px;
    }

    .admin-card-description {
        margin: 0;
        color: #667b7e;
        font-size: 13px;
    }

    .room-overview {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 12px;
        margin-top: 18px;
    }

    .room-overview-item {
        display: block;
        padding: 14px 16px;
        border: 1px solid #d7e6ea;
        border-radius: 6px;
        background: #edf9fc;
        color: inherit;
        text-decoration: none;
    }

    a.room-overview-item:hover,
    a.room-overview-item:focus-visible {
        border-color: #22b486;
        outline: 2px solid #22b486;
        outline-offset: 1px;
    }

    .room-overview-item span,
    .room-overview-item strong {
        display: block;
    }

    .room-overview-item span {
        margin-bottom: 5px;
        color: #667b7e;
        font-size: 12px;
        font-weight: 600;
    }

    .room-overview-item strong {
        color: #0d1c1e;
        font-size: 22px;
    }

    .occupancy-summary {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        margin: 18px 0 8px;
        color: #536466;
        font-size: 12px;
    }

    .occupancy-track {
        height: 9px;
        overflow: hidden;
        border-radius: 8px;
        background: #dff3f7;
    }

    .occupancy-fill {
        height: 100%;
        border-radius: inherit;
        background: #22b486;
    }

    .quick-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 18px;
    }

    .admin-button {
        display: inline-flex;
        align-items: center;
        min-height: 40px;
        padding: 9px 14px;
        border: 1px solid transparent;
        border-radius: 6px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 700;
    }

    .admin-button-primary {
        background: #22b486;
        color: #ffffff;
    }

    .admin-button-primary:hover {
        background: #1aa27a;
    }

    .admin-button-secondary {
        background: #ffffff;
        color: #236b67;
        border: 1px solid #d7e6ea;
    }

    .admin-button-secondary:hover {
        background: #dff3f7;
    }

    .inquiry-table-wrap {
        margin-top: 18px;
        overflow-x: auto;
        border: 1px solid #d7e6ea;
        border-radius: 6px;
    }

    .inquiry-table {
        width: 100%;
        min-width: 620px;
        border-collapse: collapse;
        text-align: left;
    }

    .inquiry-table th,
    .inquiry-table td {
        padding: 12px 14px;
        border-bottom: 1px solid #d7e6ea;
        font-size: 12px;
    }

    .inquiry-table th {
        background: #edf9fc;
        color: #236b67;
        font-weight: 700;
    }

    .inquiry-table td {
        color: #536466;
    }

    .inquiry-table tr:last-child td {
        border-bottom: 0;
    }

    .inquiry-action-link {
        color: #236b67;
        font-weight: 700;
        text-decoration: underline;
    }

    .inquiry-empty {
        padding: 22px 14px !important;
        text-align: center;
        line-height: 1.6;
    }

    .maintenance-note {
        margin: 10px 0 0;
        color: #667b7e;
        font-size: 11px;
        line-height: 1.5;
    }

    @media (max-width: 820px) {
        .admin-summary {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 600px) {
        .admin-page {
            padding: 24px 15px 42px;
        }

        .admin-page-header h1 {
            font-size: 26px;
        }

        .admin-stat {
            min-height: 124px;
            padding: 15px;
        }

        .room-overview {
            gap: 8px;
        }

        .admin-card {
            padding: 17px;
        }

        .room-overview-item {
            padding: 12px;
        }

        .room-overview-item strong {
            font-size: 19px;
        }
    }
</style>

<main class="admin-page">

    <!-- ============ HEADER ============ -->
    <section class="admin-page-header">
        <div>
            <span class="admin-page-label">LANDLORD PORTAL</span>
            <h1>Dashboard</h1>
            <p>Welcome, <?= e($adminName); ?>. Review current room, tenant, and payment records.</p>
        </div>
    </section>

    <section class="admin-summary">
        <a class="admin-stat" href="tenants.php" aria-label="View all <?= $totalTenants; ?> tenant records">
            <div class="admin-stat-label">Total Tenants</div>
            <div class="admin-stat-value"><?= $totalTenants; ?></div>
            <div class="admin-stat-note">View tenant records</div>
        </a>

        <a class="admin-stat" href="rooms.php" aria-label="View <?= $availableRooms; ?> available rooms">
            <div class="admin-stat-label">Available Rooms</div>
            <div class="admin-stat-value"><?= $availableRooms; ?></div>
            <div class="admin-stat-note">View room records</div>
        </a>

        <a class="admin-stat" href="payments.php" aria-label="Review <?= $pendingPayments; ?> pending payments">
            <div class="admin-stat-label">Pending Payments</div>
            <div class="admin-stat-value"><?= $pendingPayments; ?></div>
            <div class="admin-stat-note">Review payment records</div>
        </a>

        <article class="admin-stat" aria-label="<?= $pendingMaintenance; ?> pending maintenance requests">
            <div class="admin-stat-label">Pending Maintenance Requests</div>
            <div class="admin-stat-value"><?= $pendingMaintenance; ?></div>
            <div class="admin-stat-note">Review maintenance requests</div>
        </article>

        <a class="admin-stat" href="rooms.php#room-applications" aria-label="Review <?= $pendingRoomApplications; ?> pending room applications">
            <div class="admin-stat-label">Pending Room Applications</div>
            <div class="admin-stat-value"><?= $pendingRoomApplications; ?></div>
            <div class="admin-stat-note"><?= $hasRoomRequestsTable ? 'Review room requests' : 'Apply room request migration'; ?></div>
        </a>
    </section>

    <section class="admin-card">
        <h2>Room Overview</h2>
        <p class="admin-card-description">Current room availability and occupancy levels.</p>

        <div class="room-overview">
            <a class="room-overview-item" href="rooms.php" aria-label="Review all <?= $totalRooms; ?> rooms">
                <span>Total Rooms</span>
                <strong><?= $totalRooms; ?></strong>
            </a>
            <a class="room-overview-item" href="rooms.php" aria-label="Review <?= $availableRooms; ?> available rooms">
                <span>Available</span>
                <strong><?= $availableRooms; ?></strong>
            </a>
            <a class="room-overview-item" href="rooms.php" aria-label="Review <?= $occupiedRooms; ?> fully occupied rooms">
                <span>Fully Occupied</span>
                <strong><?= $occupiedRooms; ?></strong>
            </a>
        </div>

        <div class="occupancy-summary">
            <span>Room occupancy</span>
            <span><?= $occupancyPercent; ?>%</span>
        </div>
        <div class="occupancy-track" role="progressbar" aria-label="Room occupancy" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $occupancyPercent; ?>">
            <div class="occupancy-fill" style="width: <?= $occupancyPercent; ?>%"></div>
        </div>

        <div class="quick-actions">
            <a href="rooms.php" class="admin-button admin-button-primary">Manage Rooms</a>
            <a href="tenants.php" class="admin-button admin-button-secondary">View Tenants</a>
        </div>
    </section>

    <section class="admin-card">
        <h2>Recent Public Inquiries</h2>
        <p class="admin-card-description">
            <?php if ($hasPublicInquiriesTable): ?>
                <?= $unreadPublicInquiries; ?> unread guest <?= $unreadPublicInquiries === 1 ? 'inquiry' : 'inquiries'; ?>.
                <a class="inquiry-action-link" href="inquiries.php#public-inquiries">Open Inquiries</a>
            <?php else: ?>
                Public inquiry storage is not available. Apply the public inquiry database migration.
            <?php endif; ?>
        </p>

        <div class="inquiry-table-wrap">
            <table class="inquiry-table">
                <thead>
                    <tr>
                        <th scope="col">Guest</th>
                        <th scope="col">Contact</th>
                        <th scope="col">Preferred Room</th>
                        <th scope="col">Submitted</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$hasPublicInquiriesTable): ?>
                        <tr><td class="inquiry-empty" colspan="5">Public inquiries could not be loaded.</td></tr>
                    <?php elseif (!$recentPublicInquiries): ?>
                        <tr><td class="inquiry-empty" colspan="5">No public inquiries have been submitted yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentPublicInquiries as $inquiry): ?>
                            <?php $inquiryTimestamp = strtotime($inquiry['created_at']); ?>
                            <tr>
                                <td><?= e($inquiry['full_name']); ?></td>
                                <td><?= e($inquiry['email']); ?><br><?= e($inquiry['contact_number']); ?></td>
                                <td><?= e($inquiry['preferred_room_type'] ?: 'Not specified'); ?></td>
                                <td><?= $inquiryTimestamp ? e(date('M j, Y g:i A', $inquiryTimestamp)) : e($inquiry['created_at']); ?></td>
                                <td>
                                    <?= e($inquiry['status']); ?><br>
                                    <a class="inquiry-action-link" href="inquiries.php#inquiry-<?= (int) $inquiry['id']; ?>">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if (!$hasMaintenanceTable): ?>
        <p class="maintenance-note">Maintenance request records are not configured in the current database. The provided maintenance schema must be applied before requests can be counted.</p>
    <?php else: ?>
        <p class="maintenance-note">Maintenance records are counted, but this project has no admin maintenance management page yet.</p>
    <?php endif; ?>

</main>

</body>
</html>
