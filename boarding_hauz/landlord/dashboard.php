<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/room_occupancy.php';
requireRole('landlord');

$admin_title = 'Dashboard';
$user = currentUser();
$branchId = requireSelectedBranch();
$landlordId = (int) $_SESSION['user_id'];

$adminName = $user['username'] ?? $_SESSION['user_name'] ?? 'Admin';
if (empty($_SESSION['admin_rooms_csrf'])) {
    $_SESSION['admin_rooms_csrf'] = bin2hex(random_bytes(32));
}
$totalRooms = 0;
$availableRooms = 0;
$occupiedRooms = 0;
$totalCapacity = 0;
$totalOccupants = 0;
$totalTenants = 0;
$pendingPayments = 0;
$pendingMaintenance = 0;
$pendingRoomApplications = 0;
$recentRoomApplications = [];
$hasRoomRequestsTable = false;
$roomRequestsError = false;
$hasMaintenanceTable = false;
$unreadPublicInquiries = 0;
$recentPublicInquiries = [];
$hasPublicInquiriesTable = true;

$stmt = $conn->prepare('SELECT id, room_number, room_type, capacity, monthly_rate, status FROM rooms WHERE branch_id = ? ORDER BY room_number, id');
$stmt->bind_param('i', $branchId);
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
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM room_requests rr INNER JOIN rooms r ON r.id = rr.room_id WHERE rr.status = 'Pending' AND r.branch_id = ?");
        $stmt->bind_param('i', $branchId);
        $stmt->execute();
        $pendingRoomApplications = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT
                rr.id,
                rr.status,
                rr.created_at,
                t.full_name,
                t.contact_number,
                u.email,
                r.room_number,
                r.room_type
            FROM room_requests rr
            INNER JOIN rooms r ON r.id = rr.room_id
            INNER JOIN tenants t ON t.id = rr.tenant_id
            LEFT JOIN users u ON u.id = t.user_id
            WHERE r.branch_id = ?
            ORDER BY
                CASE WHEN rr.status = 'Pending' THEN 0 ELSE 1 END,
                rr.created_at DESC,
                rr.id DESC
            LIMIT 5
        ");
        $stmt->bind_param('i', $branchId);
        $stmt->execute();
        $recentRoomApplications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (mysqli_sql_exception $exception) {
        error_log('Landlord dashboard room applications failed: ' . $exception->getMessage());
        $roomRequestsError = true;
        $hasRoomRequestsTable = false;
        $pendingRoomApplications = 0;
        $recentRoomApplications = [];
    }
}

$publicInquirySummaryError = false;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM public_inquiries WHERE status = 'Unread' AND inquiry_type = 'property' AND branch_id = ?");
    $stmt->bind_param('i', $branchId);
    $stmt->execute();
    $unreadPublicInquiries = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT id, full_name, email, contact_number, preferred_room_type, status, created_at
        FROM public_inquiries
        WHERE branch_id = ? AND inquiry_type = 'property'
        ORDER BY created_at DESC, id DESC
        LIMIT 5
    ");
    $stmt->bind_param('i', $branchId);
    $stmt->execute();
    $recentPublicInquiries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Admin dashboard public inquiry summary failed: ' . $exception->getMessage());
    $publicInquirySummaryError = true;
    $hasPublicInquiriesTable = false;
}

$activeInquiries = $unreadPublicInquiries + $pendingRoomApplications;
$activeInquiriesAvailable = !$publicInquirySummaryError;
$unreadMessages = 0;
$recentConversations = [];
$messageSummaryError = false;
try {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM messages m
        INNER JOIN tenants t ON t.user_id = m.sender_id
        WHERE m.receiver_id = ?
          AND m.is_read = 0
          AND t.branch_id = ?
    ");
    $stmt->bind_param('ii', $landlordId, $branchId);
    $stmt->execute();
    $unreadMessages = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT
            u.id AS tenant_user_id,
            t.full_name,
            latest.message,
            latest.sender_id,
            latest.created_at,
            (
                SELECT COUNT(*)
                FROM messages unread
                WHERE unread.sender_id = u.id
                  AND unread.receiver_id = ?
                  AND unread.is_read = 0
            ) AS unread_count
        FROM tenants t
        INNER JOIN users u ON u.id = t.user_id
        INNER JOIN messages latest ON latest.id = (
            SELECT m.id
            FROM messages m
            WHERE (m.sender_id = u.id AND m.receiver_id = ?)
               OR (m.sender_id = ? AND m.receiver_id = u.id)
            ORDER BY m.created_at DESC, m.id DESC
            LIMIT 1
        )
        WHERE t.branch_id = ?
        ORDER BY latest.created_at DESC, latest.id DESC
        LIMIT 5
    ");
    $stmt->bind_param('iiii', $landlordId, $landlordId, $landlordId, $branchId);
    $stmt->execute();
    $recentConversations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Landlord dashboard messages summary failed: ' . $exception->getMessage());
    $messageSummaryError = true;
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
        grid-template-columns: repeat(4, minmax(0, 1fr));
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

    .dashboard-room-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
        margin-top: 18px;
    }

    .dashboard-room-card,
    .dashboard-feed-item {
        min-width: 0;
        padding: 15px;
        border: 1px solid #d7e6ea;
        border-radius: 8px;
        background: #ffffff;
    }

    .dashboard-room-heading,
    .dashboard-feed-heading {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
    }

    .dashboard-room-heading h3,
    .dashboard-feed-heading h3 {
        margin: 0;
        color: #0d1c1e;
        font-family: 'Inter', sans-serif;
        font-size: 15px;
    }

    .dashboard-room-type,
    .dashboard-feed-meta {
        margin-top: 4px;
        color: #667b7e;
        font-size: 11px;
    }

    .dashboard-room-status,
    .dashboard-request-status,
    .dashboard-unread-badge {
        flex: 0 0 auto;
        padding: 4px 8px;
        border-radius: 999px;
        background: #e4f7ef;
        color: #176b59;
        font-size: 10px;
        font-weight: 700;
    }

    .dashboard-room-status.fully-occupied,
    .dashboard-request-status.rejected {
        background: #fde5e5;
        color: #a52b2b;
    }

    .dashboard-room-status.partially-occupied,
    .dashboard-request-status.pending {
        background: #fff4d8;
        color: #806300;
    }

    .dashboard-room-status.maintenance {
        background: #edf0f2;
        color: #536466;
    }

    .dashboard-room-facts {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        margin-top: 14px;
        color: #536466;
        font-size: 11px;
    }

    .dashboard-room-facts strong {
        display: block;
        color: #0d1c1e;
        font-size: 13px;
    }

    .dashboard-room-actions,
    .dashboard-request-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 14px;
    }

    .dashboard-room-actions a,
    .dashboard-request-actions button {
        padding: 7px 10px;
        border: 1px solid #d7e6ea;
        border-radius: 6px;
        background: #ffffff;
        color: #236b67;
        font: 700 11px 'Inter', sans-serif;
        text-decoration: none;
        cursor: pointer;
    }

    .dashboard-room-actions a:hover,
    .dashboard-request-actions button:hover {
        border-color: #22b486;
        background: #edf9fc;
    }

    .dashboard-workspace-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
    }

    .dashboard-workspace-grid .admin-card {
        min-width: 0;
    }

    .admin-card .section-header-row {
        flex-wrap: wrap;
        gap: 10px;
    }

    .dashboard-feed-list {
        display: grid;
        gap: 10px;
        margin-top: 16px;
    }

    .dashboard-feed-item p {
        margin: 9px 0 0;
        overflow-wrap: anywhere;
        color: #536466;
        font-size: 12px;
    }

    .dashboard-request-actions form {
        margin: 0;
    }

    .dashboard-request-actions .approve-button {
        border-color: #22b486;
        background: #22b486;
        color: #ffffff;
    }

    .dashboard-request-actions .reject-button {
        border-color: #f0d1d1;
        color: #a52b2b;
    }

    .dashboard-message-link {
        display: block;
        color: inherit;
        text-decoration: none;
    }

    .dashboard-message-link:hover h3 {
        color: #236b67;
    }

    .dashboard-message-snippet {
        display: block;
        margin-top: 9px;
        overflow-wrap: anywhere;
        color: #536466;
        font-size: 12px;
    }

    .dashboard-unread-badge {
        background: #22b486;
        color: #ffffff;
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

        .dashboard-room-grid {
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

        .dashboard-room-grid,
        .dashboard-workspace-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<main class="admin-page">

    <!-- ============ HEADER ============ -->
    <section class="admin-page-header">
        <div>
            <span class="admin-page-label"><?= hasRole('super_landlord') ? 'SUPER LANDLORD PORTAL' : 'LANDLORD PORTAL'; ?></span>
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

        <a class="admin-stat" href="rooms.php" aria-label="Manage all <?= $totalRooms; ?> rooms">
            <div class="admin-stat-label">Total Rooms</div>
            <div class="admin-stat-value"><?= $totalRooms; ?></div>
            <div class="admin-stat-note">Manage specs and room photos</div>
        </a>

        <a class="admin-stat" href="inquiries.php#public-inquiries" aria-label="<?= $activeInquiriesAvailable ? 'Review ' . $activeInquiries . ' active inquiries and applications' : 'Inquiry summary unavailable'; ?>">
            <div class="admin-stat-label">Active Inquiries</div>
            <div class="admin-stat-value"><?= $activeInquiriesAvailable ? $activeInquiries : '—'; ?></div>
            <div class="admin-stat-note">
                <?php if (!$activeInquiriesAvailable): ?>
                    Inquiry summary unavailable; check the required database migrations
                <?php elseif (!$hasRoomRequestsTable || $roomRequestsError): ?>
                    <?= $unreadPublicInquiries; ?> unread · room application summary unavailable
                <?php else: ?>
                    <?= $unreadPublicInquiries; ?> unread · <?= $pendingRoomApplications; ?> applications pending
                <?php endif; ?>
            </div>
        </a>

        <a class="admin-stat" href="messages.php" aria-label="<?= $messageSummaryError ? 'Message summary unavailable' : 'Open messages with ' . $unreadMessages . ' unread messages'; ?>">
            <div class="admin-stat-label">Unread Messages</div>
            <div class="admin-stat-value"><?= $messageSummaryError ? '—' : $unreadMessages; ?></div>
            <div class="admin-stat-note"><?= $messageSummaryError ? 'Message summary unavailable' : 'Open tenant conversations'; ?></div>
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

    <section class="admin-card" aria-labelledby="dashboard-rooms-heading">
        <div class="section-header-row">
            <div>
                <h2 id="dashboard-rooms-heading">Rooms at a glance</h2>
                <p class="admin-card-description">Capacity, occupancy, pricing, and shortcuts to room details and photo management.</p>
            </div>
            <a class="inquiry-action-link" href="rooms.php">Manage rooms</a>
        </div>

        <?php if (!$rooms): ?>
            <p class="inquiry-empty">No rooms are registered for this branch yet.</p>
        <?php else: ?>
            <div class="dashboard-room-grid">
                <?php foreach (array_slice($rooms, 0, 6) as $room): ?>
                    <?php $roomStatusClass = strtolower(str_replace(' ', '-', (string) $room['occupancy_status'])); ?>
                    <article class="dashboard-room-card">
                        <div class="dashboard-room-heading">
                            <div>
                                <h3>Room <?= e($room['room_number']); ?></h3>
                                <div class="dashboard-room-type"><?= e($room['room_type']); ?></div>
                            </div>
                            <span class="dashboard-room-status <?= e($roomStatusClass); ?>"><?= e($room['occupancy_status']); ?></span>
                        </div>
                        <div class="dashboard-room-facts">
                            <div><strong><?= (int) $room['current_occupants']; ?>/<?= (int) $room['capacity']; ?></strong>Occupants / capacity</div>
                            <div><strong>₱<?= number_format((float) $room['monthly_rate'], 2); ?></strong>Monthly rate</div>
                        </div>
                        <div class="dashboard-room-actions">
                            <a href="/boarding_hauz/room-details.php?id=<?= (int) $room['id']; ?>&amp;branch_id=<?= $branchId; ?>">View details</a>
                            <a href="rooms.php?edit=<?= (int) $room['id']; ?>">Edit / photos</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="dashboard-workspace-grid">
        <section class="admin-card" id="room-applications" aria-labelledby="dashboard-applications-heading">
            <div class="section-header-row">
                <div>
                    <h2 id="dashboard-applications-heading">Room inquiries &amp; applications</h2>
                    <p class="admin-card-description">Review applicant contact details and approve or reject pending room applications.</p>
                </div>
                <a class="inquiry-action-link" href="rooms.php#room-applications">View all</a>
            </div>

            <?php if ($roomRequestsError): ?>
                <p class="inquiry-empty">Room applications could not be loaded. Please try again later.</p>
            <?php elseif (!$hasRoomRequestsTable): ?>
                <p class="inquiry-empty">Room application tracking is unavailable. Apply the room requests database migration.</p>
            <?php elseif (!$recentRoomApplications): ?>
                <p class="inquiry-empty">No room applications have been submitted yet.</p>
            <?php else: ?>
                <div class="dashboard-feed-list">
                    <?php foreach ($recentRoomApplications as $application): ?>
                        <?php $applicationDate = strtotime($application['created_at']); ?>
                        <article class="dashboard-feed-item">
                            <div class="dashboard-feed-heading">
                                <div>
                                    <h3><?= e($application['full_name']); ?></h3>
                                    <div class="dashboard-feed-meta">Room <?= e($application['room_number']); ?> · <?= e($application['room_type']); ?></div>
                                </div>
                                <span class="dashboard-request-status <?= strtolower(e($application['status'])); ?>"><?= e($application['status']); ?></span>
                            </div>
                            <p>
                                <?= e($application['email'] ?: 'Email not provided'); ?>
                                <?php if (!empty($application['contact_number'])): ?> · <?= e($application['contact_number']); ?><?php endif; ?>
                            </p>
                            <p class="dashboard-feed-meta"><?= $applicationDate ? e(date('M j, Y g:i A', $applicationDate)) : e($application['created_at']); ?></p>
                            <?php if ($application['status'] === 'Pending'): ?>
                                <div class="dashboard-request-actions">
                                    <form method="post" action="/boarding_hauz/landlord/rooms.php#room-applications">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['admin_rooms_csrf']); ?>">
                                        <input type="hidden" name="action" value="approve_room_request">
                                        <input type="hidden" name="request_id" value="<?= (int) $application['id']; ?>">
                                        <button class="approve-button" type="submit">Approve</button>
                                    </form>
                                    <form method="post" action="/boarding_hauz/landlord/rooms.php#room-applications">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['admin_rooms_csrf']); ?>">
                                        <input type="hidden" name="action" value="reject_room_request">
                                        <input type="hidden" name="request_id" value="<?= (int) $application['id']; ?>">
                                        <button class="reject-button" type="submit">Reject</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="admin-card" aria-labelledby="dashboard-messages-heading">
            <div class="section-header-row">
                <div>
                    <h2 id="dashboard-messages-heading">Recent messages</h2>
                    <p class="admin-card-description"><?= $unreadMessages; ?> unread message<?= $unreadMessages === 1 ? '' : 's'; ?> from tenants in this branch.</p>
                </div>
                <a class="inquiry-action-link" href="messages.php">Open inbox</a>
            </div>

            <?php if ($messageSummaryError): ?>
                <p class="inquiry-empty">Messages are temporarily unavailable. Please open the inbox later.</p>
            <?php elseif (!$recentConversations): ?>
                <p class="inquiry-empty">No tenant conversations yet.</p>
            <?php else: ?>
                <div class="dashboard-feed-list">
                    <?php foreach ($recentConversations as $conversation): ?>
                        <?php
                        $messageDate = strtotime($conversation['created_at']);
                        $messagePreview = ((int) $conversation['sender_id'] === $landlordId ? 'You: ' : '')
                            . mb_strimwidth((string) $conversation['message'], 0, 90, '…', 'UTF-8');
                        ?>
                        <a class="dashboard-feed-item dashboard-message-link" href="messages.php?tenant_id=<?= (int) $conversation['tenant_user_id']; ?>#tenant-messages">
                            <div class="dashboard-feed-heading">
                                <div>
                                    <h3><?= e($conversation['full_name']); ?></h3>
                                    <div class="dashboard-feed-meta"><?= $messageDate ? e(date('M j, Y g:i A', $messageDate)) : e($conversation['created_at']); ?></div>
                                </div>
                                <?php if ((int) $conversation['unread_count'] > 0): ?>
                                    <span class="dashboard-unread-badge"><?= (int) $conversation['unread_count']; ?> unread</span>
                                <?php endif; ?>
                            </div>
                            <span class="dashboard-message-snippet"><?= e($messagePreview); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

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
