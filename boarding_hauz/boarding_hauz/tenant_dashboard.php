<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/room_display.php';
require_once __DIR__ . '/includes/room_images.php';
require_once __DIR__ . '/includes/room_occupancy.php';
require_once __DIR__ . '/includes/payment_due.php';
requireRole('tenant');

$tenant_title = 'Dashboard';

$user = currentUser();
$userId = (int) $_SESSION['user_id'];

$tenant = null;
$maintenanceCount = 0;
$recentPayments = [];
$latestMaintenance = null;
$activeLeases = [];
$leaseHistory = [];


/* =========================================================
   GET TENANT + ROOM INFORMATION
   ========================================================= */

$stmt = $conn->prepare("
    SELECT
        t.id AS tenant_id,
        t.full_name,
        t.status AS tenant_status,
        l.id AS lease_id,
        l.move_in_date,
        l.monthly_rate AS lease_monthly_rate,
        r.id AS room_id,
        r.room_number,
        r.room_type,
        r.capacity,
        r.monthly_rate,
        r.status AS room_status,
        r.image_path,
        b.id AS branch_id,
        b.branch_name,
        b.location AS branch_location
    FROM tenants t
    LEFT JOIN tenant_leases l ON l.id = (
        SELECT active_lease.id
        FROM tenant_leases active_lease
        WHERE active_lease.tenant_id = t.id AND active_lease.status = 'active'
        ORDER BY active_lease.id DESC
        LIMIT 1
    )
    LEFT JOIN rooms r ON r.id = l.room_id
    LEFT JOIN branches b
        ON b.id = r.branch_id
    WHERE t.user_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $userId);
$stmt->execute();

$tenant = $stmt->get_result()->fetch_assoc();

$stmt->close();
if ($tenant && $tenant['room_id'] !== null) {
    $tenant = attachRoomOccupancy($conn, [$tenant])[0];
    $tenant['room_status'] = $tenant['occupancy_status'];
    $tenant['monthly_rate'] = (float) $tenant['lease_monthly_rate'];
}

if ($tenant) {
    $tenantId = (int) $tenant['tenant_id'];
    $stmt = $conn->prepare("
        SELECT l.id AS lease_id, l.move_in_date, l.monthly_rate,
            r.id AS room_id, r.room_number, r.room_type, r.capacity, r.status AS room_status,
            r.image_path, b.id AS branch_id, b.branch_name, b.location AS branch_location
        FROM tenant_leases l
        INNER JOIN rooms r ON r.id = l.room_id
        INNER JOIN branches b ON b.id = r.branch_id
        WHERE l.tenant_id = ? AND l.status = 'active'
        ORDER BY l.created_at DESC, l.id DESC
    ");
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $activeLeases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $activeLeases = attachRoomOccupancy($conn, $activeLeases);

    $stmt = $conn->prepare("
        SELECT l.id AS lease_id, l.move_in_date, l.ended_at, l.monthly_rate,
            r.room_number, r.room_type, b.branch_name
        FROM tenant_leases l
        INNER JOIN rooms r ON r.id = l.room_id
        INNER JOIN branches b ON b.id = r.branch_id
        WHERE l.tenant_id = ? AND l.status = 'ended'
        ORDER BY COALESCE(l.ended_at, l.created_at) DESC, l.id DESC
    ");
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $leaseHistory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$currentPaymentDue = null;
if ($tenant && !empty($tenant['room_id'])) {
    $currentPaymentDue = getCurrentMonthPaymentDue(
        $conn,
        (int) $tenant['tenant_id'],
        (float) ($tenant['monthly_rate'] ?? 0),
        $tenant['move_in_date'] ?? null,
        null,
        (int) ($tenant['lease_id'] ?? 0)
    );
}


/* =========================================================
   GET TENANT-RELATED INFORMATION
   ========================================================= */

if ($tenant) {
    $tenantId = (int) $tenant['tenant_id'];

    $stmt = $conn->prepare("
        SELECT
            p.amount_paid,
            p.payment_date,
            p.payment_method,
            p.status,
            b.branch_name,
            r.room_number
        FROM payments p
        LEFT JOIN tenant_leases l ON l.id = p.lease_id
        LEFT JOIN rooms r ON r.id = l.room_id
        LEFT JOIN branches b ON b.id = r.branch_id
        WHERE p.tenant_id = ?
        ORDER BY p.payment_date DESC, p.id DESC
        LIMIT 5
    ");
    $stmt->bind_param("i", $tenantId);
    $stmt->execute();
    $recentPayments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM maintenance_requests
        WHERE tenant_id = ?
          AND status NOT IN ('Resolved', 'Closed')
    ");
    $stmt->bind_param("i", $tenantId);
    $stmt->execute();
    $maintenanceResult = $stmt->get_result()->fetch_assoc();
    $maintenanceCount = (int) ($maintenanceResult['total'] ?? 0);
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT
            id,
            category,
            description,
            status,
            created_at
        FROM maintenance_requests
        WHERE tenant_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 3
    ");
    $stmt->bind_param("i", $tenantId);
    $stmt->execute();
    $latestMaintenance = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}


/* =========================================================
   HELPER FUNCTIONS
   ========================================================= */

function dashboardDate($date)
{
    if (!$date) {
        return '—';
    }

    return date('M d, Y', strtotime($date));
}


function dashboardPaymentStatus($status)
{
    switch (strtolower($status)) {

        case 'verified':
        case 'paid':
        case 'upcoming':
            return 'status-success';

        case 'pending':
            return 'status-pending';

        case 'rejected':
        case 'overdue':
            return 'status-danger';

        default:
            return 'status-neutral';
    }
}


function dashboardMaintenanceStatus($status)
{
    switch (strtolower($status)) {

        case 'resolved':
        case 'closed':
            return 'status-success';

        case 'in progress':
            return 'status-progress';

        case 'pending':
            return 'status-pending';

        default:
            return 'status-neutral';
    }
}


/* =========================================================
   HEADER
   ========================================================= */

include __DIR__ . '/includes/tenant_header.php';

$displayName = !empty($tenant['full_name'])
    ? $tenant['full_name']
    : ($user['username'] ?? 'Tenant');
$nameParts = preg_split('/\s+/', trim($displayName));
$firstName = $nameParts[0] ?? 'Tenant';
$roomTitle = $tenant && !empty($tenant['room_id'])
    ? roomDisplayTitle($tenant['room_number'])
    : 'No room assigned';
$roomImageUrl = $tenant && !empty($tenant['room_id'])
    ? getRoomImageUrlForDisplay((int) $tenant['room_id'], $tenant['image_path'])
    : null;
$roomOccupancy = (int) ($tenant['current_occupants'] ?? 0);
$tenantStatusLabel = $tenant ? ucfirst(strtolower($tenant['tenant_status'])) : 'Pending';
$dueCountdown = '';
if ($currentPaymentDue) {
    $daysUntilDue = (int) (new DateTimeImmutable('today'))
        ->diff($currentPaymentDue['due_date'])
        ->format('%r%a');
    $dueCountdown = $currentPaymentDue['status'] === 'Paid'
        ? 'Paid'
        : ($currentPaymentDue['status'] === 'Overdue'
        ? 'Overdue'
        : ($currentPaymentDue['status'] === 'Pending' && $currentPaymentDue['has_pending_submission']
            ? 'Awaiting verification'
            : ($daysUntilDue > 0
                ? 'Due in ' . $daysUntilDue . ' ' . ($daysUntilDue === 1 ? 'day' : 'days')
                : ($daysUntilDue === 0 ? 'Due today' : 'Pending'))));
}

?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap');

/* =========================================================
   TENANT DASHBOARD
   ========================================================= */

.tenant-dashboard {
    max-width: 1100px;
    margin: 0 auto;
    padding: 32px 22px 55px;
}


/* =========================================================
   HERO
   ========================================================= */

.dashboard-hero {
    background: #0d5b50;
    border: 1px solid #236b67;
    border-radius: 16px;
    padding: 30px;
    margin-bottom: 24px;

    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 25px;

    box-shadow: 0 8px 22px rgba(13, 91, 80, 0.12);
}

.dashboard-hero-content {
    min-width: 0;
}

.dashboard-label {
    display: inline-block;
    margin-bottom: 8px;

    color: #8ee2c5;

    font-family: 'Inter', sans-serif;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1px;
}

.dashboard-hero h1 {
    margin: 0 0 7px;

    color: #ffffff;

    font-family: 'Playfair Display', serif;
    font-size: 32px;
    line-height: 1.2;
}

.dashboard-hero p {
    margin: 0;

    color: #dff3f7;

    font-family: 'Inter', sans-serif;
    font-size: 14px;
}

.dashboard-hero-actions {
    display: flex;
    gap: 10px;
    flex-shrink: 0;
}

.btn-browse {
    display: inline-block;

    padding: 11px 17px;

    border: 1px solid rgba(255, 255, 255, 0.35);
    border-radius: 8px;

    background: transparent;
    color: #ffffff;

    text-decoration: none;

    font-family: 'Inter', sans-serif;
    font-size: 13px;
    font-weight: 700;

    transition: 0.2s ease;
}

.btn-browse:hover {
    background: rgba(255, 255, 255, 0.10);
}

.btn-help {
    display: inline-block;

    padding: 11px 17px;

    border-radius: 8px;

    background: #ffffff;
    color: #236b67;

    text-decoration: none;

    font-family: 'Inter', sans-serif;
    font-size: 13px;
    font-weight: 700;

    transition: 0.2s ease;
}

.btn-help:hover {
    background: #dff3f7;
}


/* =========================================================
   SECTION TITLE
   ========================================================= */

.dashboard-section {
    margin-bottom: 24px;
}

.section-label {
    display: block;

    margin-bottom: 5px;

    color: #1aa27a;

    font-family: 'Inter', sans-serif;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1px;
}

.section-title {
    margin: 0;

    color: #0d1c1e;

    font-family: 'Playfair Display', serif;
    font-size: 25px;
}


/* =========================================================
   MY ROOM
   ========================================================= */

.room-card {
    background: #ffffff;

    border: 1px solid #d7e6ea;
    border-radius: 16px;

    padding: 25px;

    margin-bottom: 24px;
}

.room-card-top {
    display: flex;

    align-items: flex-start;
    justify-content: space-between;

    gap: 20px;

    margin-bottom: 20px;
}

.room-number {
    margin: 0 0 5px;

    color: #0d1c1e;

    font-family: 'Playfair Display', serif;
    font-size: 27px;
}

.room-type {
    margin: 0;

    color: #667b7e;

    font-family: 'Inter', sans-serif;
    font-size: 13px;
}

.room-status {
    display: inline-block;

    padding: 6px 11px;

    border-radius: 20px;

    background: #dff4e9;
    color: #18734f;

    font-family: 'Inter', sans-serif;
    font-size: 11px;
    font-weight: 700;
}

.room-details {
    display: grid;

    grid-template-columns: repeat(3, 1fr);

    gap: 18px;

    padding: 18px 0;

    border-top: 1px solid #d7e6ea;
    border-bottom: 1px solid #d7e6ea;
}

.room-detail span {
    display: block;

    margin-bottom: 5px;

    color: #667b7e;

    font-family: 'Inter', sans-serif;
    font-size: 11px;
}

.room-detail strong {
    color: #0d1c1e;

    font-family: 'Inter', sans-serif;
    font-size: 14px;
}

.room-action {
    margin-top: 18px;
}

.tenant-dashboard .tenant-room-card {
    padding: 0;
    overflow: hidden;
    border-radius: 9px;
}

.tenant-room-image {
    height: 170px;
    background: linear-gradient(135deg, #dff3f7, #edf9fc);
}

.tenant-room-image img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.tenant-room-image-placeholder {
    display: grid;
    width: 100%;
    height: 100%;
    place-items: center;
    color: #236b67;
    font: 600 16px 'Playfair Display', serif;
}

.tenant-room-card-body {
    display: grid;
    gap: 11px;
    padding: 15px 16px;
}

.tenant-room-card-body > .section-label {
    margin: 0;
}

.tenant-room-card .room-card-top {
    align-items: flex-start;
    gap: 10px;
    margin: 0;
    padding: 0 0 12px;
    border-bottom: 1px solid #d7e6ea;
}

.tenant-room-card .room-number {
    margin: 0;
    font-size: 20px;
}

.tenant-room-card .room-type {
    margin-top: 4px;
    font-size: 12px;
}

.tenant-room-card .room-status {
    flex: 0 0 auto;
    padding: 5px 9px;
    font-size: 11px;
}

.tenant-room-status-available {
    background: #e3f6ee;
    color: #18734f;
}

.tenant-room-status-occupied {
    background: #dff3f7;
    color: #236b67;
}

.tenant-room-status-maintenance {
    background: #fff4d6;
    color: #866000;
}
.tenant-room-status-partially-occupied {
    background: #e5f0ff;
    color: #285a9f;
}
.tenant-room-status-fully-occupied {
    background: #fce8e8;
    color: #9b2c2c;
}
.tenant-room-status-unavailable {
    background: #f1f2f3;
    color: #536466;
}

.tenant-room-capacity {
    color: #667b7e;
    font-size: 12px;
}

.tenant-room-rate {
    color: #0d1c1e;
    font-size: 19px;
    font-weight: 700;
}

.tenant-room-rate small {
    color: #667b7e;
    font-size: 12px;
    font-weight: 500;
}

.tenant-room-info {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px 18px;
    padding-top: 10px;
    border-top: 1px solid #edf2f3;
}

.tenant-room-info .room-detail span {
    margin-bottom: 5px;
    font-size: 11px;
}

.tenant-room-info .room-detail strong {
    font-size: 13px;
}

.tenant-room-card .room-action {
    display: flex;
    margin: 0;
}

.tenant-room-card .room-action .btn-primary {
    display: inline-flex;
    width: 100%;
    min-height: 36px;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    padding: 7px 11px;
    border: 1px solid #22b486;
    border-radius: 6px;
    font-size: 12px;
}

.tenant-room-applications {
    margin: 0 0 24px;
    padding: 19px 20px;
    border: 1px solid #d7e6ea;
    border-radius: 9px;
    background: #fff;
}

.tenant-room-applications h2 {
    margin: 0 0 12px;
    color: #0d1c1e;
    font: 700 20px 'Playfair Display', serif;
}

.tenant-room-application {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 11px 0;
    border-top: 1px solid #edf2f3;
}

.tenant-room-application:first-of-type {
    border-top: 0;
}

.tenant-room-application-details strong,
.tenant-room-application-details span {
    display: block;
}

.tenant-room-application-details strong {
    color: #0d1c1e;
    font-size: 13px;
}

.tenant-room-application-details span {
    margin-top: 3px;
    color: #667b7e;
    font-size: 11px;
}

.tenant-room-application-status {
    flex: 0 0 auto;
    padding: 5px 9px;
    border-radius: 20px;
    background: #fff4d6;
    color: #866000;
    font-size: 11px;
    font-weight: 700;
}

.tenant-room-application-status.approved {
    background: #e3f6ee;
    color: #18734f;
}

.tenant-room-application-status.rejected {
    background: #fff0f0;
    color: #8a1c1c;
}

@media (max-width: 600px) {
    .tenant-dashboard .tenant-room-card {
        padding: 0;
    }

    .tenant-room-card .room-card-top {
        flex-direction: row;
    }

    .tenant-room-info {
        grid-template-columns: 1fr;
    }

    .tenant-room-application {
    align-items: flex-start;
    flex-direction: column;
    }
}

.btn-primary {
    display: inline-block;

    padding: 10px 16px;

    border-radius: 8px;

    background: #22b486;
    color: #ffffff;

    text-decoration: none;

    font-family: 'Inter', sans-serif;
    font-size: 13px;
    font-weight: 700;

    transition: 0.2s ease;
}

.btn-primary:hover {
    background: #1aa27a;
}


/* =========================================================
   NO ROOM
   ========================================================= */

.no-room {
    padding: 18px;

    border-radius: 10px;

    background: #edf9fc;
}

.no-room p {
    margin: 0 0 5px;

    color: #0d1c1e;

    font-family: 'Inter', sans-serif;
    font-size: 14px;
    font-weight: 600;
}

.no-room span {
    color: #667b7e;

    font-family: 'Inter', sans-serif;
    font-size: 13px;
}


/* =========================================================
   SUMMARY CARDS
   ========================================================= */

.summary-grid {
    display: grid;

    grid-template-columns: repeat(3, 1fr);

    gap: 18px;

    margin-bottom: 24px;
}

.summary-card {
    background: #ffffff;

    border: 1px solid #d7e6ea;
    border-radius: 14px;

    padding: 21px;
}

.summary-card-header {
    display: flex;

    align-items: center;
    justify-content: space-between;

    margin-bottom: 14px;
}

.summary-card-label {
    color: #667b7e;

    font-family: 'Inter', sans-serif;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.7px;
}

.summary-icon {
    display: flex;

    align-items: center;
    justify-content: center;

    width: 30px;
    height: 30px;

    border-radius: 50%;

    background: #dff3f7;
    color: #236b67;

    font-family: 'Inter', sans-serif;
    font-size: 12px;
    font-weight: 700;
}

.summary-card h3 {
    margin: 0 0 5px;

    color: #0d1c1e;

    font-family: 'Inter', sans-serif;
    font-size: 22px;
}

.summary-card p {
    margin: 0;

    min-height: 18px;

    color: #667b7e;

    font-family: 'Inter', sans-serif;
    font-size: 12px;
}

.summary-link {
    display: inline-block;

    margin-top: 17px;

    color: #1aa27a;

    text-decoration: none;

    font-family: 'Inter', sans-serif;
    font-size: 12px;
    font-weight: 700;
}

.summary-link:hover {
    color: #236b67;
}


/* =========================================================
   STATUS
   ========================================================= */

.status-badge {
    display: inline-block;

    padding: 4px 9px;

    border-radius: 20px;

    font-family: 'Inter', sans-serif;
    font-size: 10px;
    font-weight: 700;
}

.status-success {
    background: #dff4e9;
    color: #18734f;
}

.status-pending {
    background: #fff4d6;
    color: #866000;
}

.status-danger {
    background: #fde5e5;
    color: #ba1a1a;
}

.status-progress {
    background: #dff3f7;
    color: #236b67;
}

.status-neutral {
    background: #edf1f2;
    color: #536466;
}


/* =========================================================
   PAYMENT INFO
   ========================================================= */

.payment-info {
    background: #ffffff;

    border: 1px solid #d7e6ea;
    border-radius: 14px;

    padding: 22px;

    margin-bottom: 24px;
}

.payment-info-content {
    display: flex;

    align-items: center;
    justify-content: space-between;

    gap: 20px;
}

.payment-info h2 {
    margin: 0 0 6px;

    color: #0d1c1e;

    font-family: 'Playfair Display', serif;
    font-size: 21px;
}

.payment-info p {
    margin: 0;

    color: #667b7e;

    font-family: 'Inter', sans-serif;
    font-size: 13px;
}

.payment-amount {
    color: #236b67;

    font-family: 'Inter', sans-serif;
    font-size: 18px;
    font-weight: 700;
}


/* =========================================================
   RECENT ACTIVITY
   ========================================================= */

.activity-card {
    background: #ffffff;

    border: 1px solid #d7e6ea;
    border-radius: 16px;

    padding: 25px;
}

.activity-list {
    margin-top: 18px;

    border-top: 1px solid #d7e6ea;
}

.activity-item {
    display: flex;

    gap: 13px;

    padding: 16px 0;

    border-bottom: 1px solid #d7e6ea;
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-dot {
    width: 9px;
    height: 9px;

    min-width: 9px;

    margin-top: 6px;

    border-radius: 50%;

    background: #22b486;
}

.activity-dot.maintenance {
    background: #236b67;
}

.activity-content {
    flex: 1;
}

.activity-row {
    display: flex;

    align-items: center;
    justify-content: space-between;

    gap: 12px;
}

.activity-title {
    margin: 0;

    color: #0d1c1e;

    font-family: 'Inter', sans-serif;
    font-size: 13px;
    font-weight: 700;
}

.activity-description {
    margin: 4px 0;

    color: #536466;

    font-family: 'Inter', sans-serif;
    font-size: 12px;
}

.activity-date {
    color: #7b8d90;

    font-family: 'Inter', sans-serif;
    font-size: 10px;
}


/* =========================================================
   EMPTY ACTIVITY
   ========================================================= */

.activity-empty {
    margin-top: 18px;

    padding: 25px;

    text-align: center;

    border-radius: 10px;

    background: #edf9fc;
}

.activity-empty h3 {
    margin: 0 0 5px;

    color: #0d1c1e;

    font-family: 'Inter', sans-serif;
    font-size: 14px;
}

.activity-empty p {
    margin: 0;

    color: #667b7e;

    font-family: 'Inter', sans-serif;
    font-size: 12px;
}


/* =========================================================
   RESPONSIVE
   ========================================================= */

@media (max-width: 800px) {

    .dashboard-hero {
        flex-direction: column;
        align-items: flex-start;
    }

    .dashboard-hero-actions {
        width: 100%;
    }

    .btn-browse,
    .btn-help {
        flex: 1;
        text-align: center;
    }

    .room-details {
        grid-template-columns: 1fr;
    }

    .summary-grid {
        grid-template-columns: 1fr;
    }

    .payment-info-content {
        align-items: flex-start;
        flex-direction: column;
    }

}


@media (max-width: 520px) {

    .tenant-dashboard {
        padding: 22px 15px 45px;
    }

    .dashboard-hero {
        padding: 22px;
        border-radius: 13px;
    }

    .dashboard-hero h1 {
        font-size: 27px;
    }

    .dashboard-hero-actions {
        flex-direction: column;
    }

    .btn-browse,
    .btn-help {
        width: 100%;
        box-sizing: border-box;
    }

    .room-card,
    .activity-card,
    .payment-info {
        padding: 20px;
    }

    .room-card-top {
        flex-direction: column;
    }

    .activity-row {
        align-items: flex-start;
        flex-direction: column;
        gap: 7px;
    }

}


/* =========================================================
   END DASHBOARD
   ========================================================= */

.dashboard-legacy {
    display: none !important;
}

.dashboard-v2 {
    max-width: 1180px;
    padding: 28px 22px 56px;
    color: #173c32;
}

.dashboard-v2 .dashboard-hero {
    min-height: 176px;
    margin-bottom: 22px;
    padding: 30px 34px;
    border: 0;
    border-radius: 18px;
    background: linear-gradient(115deg, #0b4036 0%, #126550 58%, #168166 100%);
    box-shadow: 0 16px 34px rgba(11, 64, 54, .18);
}

.hero-copy {
    display: grid;
    gap: 11px;
}

.hero-verified {
    display: inline-flex;
    width: fit-content;
    align-items: center;
    gap: 7px;
    padding: 6px 10px;
    border: 1px solid rgba(198, 246, 220, .28);
    border-radius: 999px;
    background: rgba(221, 255, 238, .12);
    color: #c7f4dc;
    font-size: 11px;
    font-weight: 700;
}

.dashboard-v2 .dashboard-hero h1 {
    margin: 0;
    font-size: clamp(26px, 3vw, 36px);
}

.dashboard-v2 .dashboard-hero p {
    color: #dbf3e9;
}

.hero-actions {
    display: flex;
    gap: 10px;
}

.hero-actions a {
    display: inline-flex;
    min-height: 42px;
    align-items: center;
    justify-content: center;
    gap: 8px;
    box-sizing: border-box;
    padding: 0 16px;
    border: 1px solid rgba(255, 255, 255, .55);
    border-radius: 9px;
    color: #fff;
    text-decoration: none;
    font-size: 13px;
    font-weight: 700;
}

.hero-actions .quick-pay {
    border-color: #40d09b;
    background: #28b783;
    color: #092f27;
}

.hero-actions a:hover {
    filter: brightness(1.06);
}

.dashboard-v2 .summary-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}

.dashboard-v2 .summary-card {
    min-height: 142px;
    padding: 20px;
    border: 1px solid #e0eae5;
    border-radius: 14px;
    box-shadow: 0 5px 16px rgba(24, 65, 49, .045);
}

.dashboard-v2 .summary-card-header {
    margin-bottom: 12px;
}

.dashboard-v2 .summary-card-label {
    color: #658076;
}

.dashboard-v2 .summary-card h3 {
    margin-bottom: 8px;
    color: #123f32;
    font-size: 22px;
}

.summary-card-detail {
    margin-top: 8px !important;
}

.dashboard-v2 .summary-icon {
    background: #e7f5ed;
    color: #18734f;
}

.dashboard-content-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.65fr) minmax(270px, .9fr);
    align-items: start;
    gap: 20px;
}

.dashboard-main-column,
.dashboard-sidebar {
    display: grid;
    gap: 18px;
    min-width: 0;
}

.rent-callout,
.dashboard-panel {
    border: 1px solid #e0eae5;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 5px 16px rgba(24, 65, 49, .045);
}

.rent-callout {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 22px;
    border-color: #cce9d9;
    background: linear-gradient(110deg, #effaf3, #fff 80%);
}

.rent-callout h2 {
    margin: 0 0 7px;
    color: #123f32;
    font: 700 22px 'Playfair Display', serif;
}

.rent-callout p {
    margin: 0;
    color: #60786e;
    font-size: 13px;
}

.rent-callout-amount {
    display: block;
    margin-top: 10px;
    color: #146846;
    font-size: 20px;
    font-weight: 800;
}

.rent-callout .btn-primary {
    flex: 0 0 auto;
    padding: 12px 16px;
    border-radius: 8px;
    background: #16865e;
}

.dashboard-panel {
    padding: 20px;
}

.dashboard-panel-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 13px;
}

.dashboard-panel-heading h2 {
    margin: 0;
    color: #143e32;
    font: 700 20px 'Playfair Display', serif;
}

.dashboard-panel-heading a {
    color: #147451;
    text-decoration: none;
    font-size: 12px;
    font-weight: 700;
}

.payment-table-wrap {
    overflow-x: auto;
}

.payment-history-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}

.payment-history-table th {
    padding: 10px 8px;
    border-bottom: 1px solid #e4ece7;
    color: #789086;
    font-size: 10px;
    letter-spacing: .06em;
    text-transform: uppercase;
    white-space: nowrap;
}

.payment-history-table td {
    padding: 13px 8px;
    border-bottom: 1px solid #edf2ef;
    color: #29483c;
    font-size: 12px;
    white-space: nowrap;
}

.payment-history-table tr:last-child td {
    border-bottom: 0;
}

.method-label {
    display: inline-flex;
    align-items: center;
    gap: 7px;
}

.method-icon {
    display: inline-grid;
    width: 25px;
    height: 25px;
    place-items: center;
    border-radius: 7px;
    background: #edf7f1;
    color: #18734f;
    font-size: 11px;
    font-weight: 800;
}

.dashboard-v2 .status-badge {
    padding: 5px 9px;
    font-size: 10px;
}

.status-upcoming {
    background: #e3f6ee;
    color: #18734f;
}

.dashboard-empty {
    margin: 0;
    padding: 15px;
    border-radius: 9px;
    background: #f5f9f6;
    color: #71857b;
    font-size: 12px;
    line-height: 1.6;
}

.notice-meta {
    display: block;
    margin-top: 10px;
    color: #81938a;
    font-size: 11px;
}

.manager-profile {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 13px;
}

.manager-avatar {
    display: grid;
    width: 46px;
    height: 46px;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 50%;
    background: #e5f4eb;
    color: #16704c;
    font-size: 17px;
    font-weight: 800;
}

.manager-profile strong,
.manager-profile span {
    display: block;
}

.manager-profile strong {
    color: #173f32;
    font-size: 14px;
}

.manager-profile span {
    margin-top: 4px;
    color: #71857b;
    font-size: 11px;
}

.manager-contact-note {
    margin: 0 0 14px;
    color: #71857b;
    font-size: 11px;
    line-height: 1.5;
}

.manager-message {
    display: block;
    text-align: center;
}

.reminder-list {
    display: grid;
    gap: 12px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.reminder-list li {
    position: relative;
    padding-left: 21px;
    color: #526b60;
    font-size: 12px;
    line-height: 1.55;
}

.reminder-list li::before {
    position: absolute;
    top: 1px;
    left: 0;
    color: #16865e;
    content: "✓";
    font-weight: 800;
}

@media (max-width: 850px) {
    .dashboard-content-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {
    .dashboard-v2 .summary-grid {
        grid-template-columns: 1fr;
    }

    .dashboard-v2 .dashboard-hero {
        align-items: flex-start;
        flex-direction: column;
        padding: 24px;
    }

    .hero-actions {
        width: 100%;
    }

    .hero-actions a {
        flex: 1;
    }

    .rent-callout {
        align-items: flex-start;
        flex-direction: column;
    }

    .rent-callout .btn-primary {
        width: 100%;
        box-sizing: border-box;
        text-align: center;
    }
}

@media (max-width: 480px) {
    .dashboard-v2 {
        padding: 20px 14px 36px;
    }

    .hero-actions {
        flex-direction: column;
    }
}

body.tenant-portal {
    background: #c1f6ed;
    color: #02353c;
    font-family: 'Inter', sans-serif;
}

.dashboard-v2 {
    --dashboard-primary: #2eaf7d;
    --dashboard-accent: #3fd0c9;
    --dashboard-ink: #02353c;
    --dashboard-muted: #02353c;
    --dashboard-border: rgba(2, 53, 60, .1);
    --dashboard-shadow: 0 2px 5px rgba(2, 53, 60, .035), 0 10px 28px rgba(2, 53, 60, .075);
    color: var(--dashboard-ink);
    font-family: 'Inter', sans-serif;
}

.dashboard-v2 h1,
.dashboard-v2 h2,
.dashboard-v2 h3,
.dashboard-v2 h4,
.dashboard-v2 .section-title {
    font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
}

.dashboard-v2 .dashboard-hero {
    border: 1px solid rgba(255, 255, 255, .12);
    background: #02353c;
    box-shadow: 0 8px 22px rgba(2, 53, 60, .16);
}

.dashboard-v2 .hero-verified {
    border-color: rgba(63, 208, 201, .38);
    background: rgba(63, 208, 201, .13);
    color: #c1f6ed;
}

.dashboard-v2 .dashboard-hero h1 {
    color: #fff;
}

.dashboard-v2 .dashboard-hero p {
    color: #c1f6ed;
}

.dashboard-v2 .hero-actions a {
    border-color: rgba(255, 255, 255, .4);
    border-radius: 9px;
    transition: background-color .18s ease, border-color .18s ease, transform .18s ease;
}

.dashboard-v2 .hero-actions .quick-pay {
    border-color: var(--dashboard-primary);
    background: var(--dashboard-primary);
    color: #fff;
}

.dashboard-v2 .hero-actions .quick-pay:hover {
    background: #02353c;
}

.dashboard-v2 .hero-actions a:not(.quick-pay):hover {
    background: rgba(255, 255, 255, .12);
}

.dashboard-v2 .summary-card,
.dashboard-v2 .rent-callout,
.dashboard-v2 .dashboard-panel {
    border: 1px solid var(--dashboard-border);
    border-radius: 15px;
    background: #fff;
    box-shadow: var(--dashboard-shadow);
}

.dashboard-v2 .summary-card {
    min-height: 142px;
}

.dashboard-v2 .summary-card-label {
    color: var(--dashboard-muted);
}

.dashboard-v2 .summary-card h3,
.dashboard-v2 .dashboard-panel-heading h2 {
    color: var(--dashboard-ink);
}

.dashboard-v2 .summary-card p,
.dashboard-v2 .rent-callout p,
.dashboard-v2 .dashboard-empty,
.dashboard-v2 .manager-contact-note {
    color: var(--dashboard-muted);
}

.dashboard-v2 .summary-icon {
    background: #c1f6ed;
    color: var(--dashboard-primary);
}

.dashboard-v2 .status-badge {
    color: var(--dashboard-ink);
}

.dashboard-v2 .status-success,
.dashboard-v2 .status-upcoming {
    background: #c1f6ed;
    color: #02353c;
}

.dashboard-v2 .status-pending,
.dashboard-v2 .status-progress {
    background: var(--dashboard-accent);
    color: #02353c;
}

.dashboard-v2 .status-danger {
    background: #02353c;
    color: #fff;
}

.dashboard-v2 .status-neutral {
    background: rgba(2, 53, 60, .08);
    color: #02353c;
}

.dashboard-v2 .rent-callout {
    border-color: var(--dashboard-border);
    background: #fff;
}

.dashboard-v2 .rent-callout h2 {
    color: var(--dashboard-ink);
    font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
}

.dashboard-v2 .rent-callout-amount {
    color: var(--dashboard-primary);
    font-family: 'Inter', sans-serif;
}

.dashboard-v2 .rent-callout .btn-primary,
.dashboard-v2 .manager-message {
    border: 1px solid var(--dashboard-primary);
    background: var(--dashboard-primary);
    color: #fff;
    transition: background-color .18s ease, border-color .18s ease;
}

.dashboard-v2 .rent-callout .btn-primary:hover,
.dashboard-v2 .manager-message:hover {
    border-color: #02353c;
    background: #02353c;
}

.dashboard-v2 .dashboard-panel-heading a,
.dashboard-v2 .summary-link {
    color: var(--dashboard-primary);
}

.dashboard-v2 .dashboard-panel-heading a:hover,
.dashboard-v2 .summary-link:hover {
    color: #02353c;
}

.dashboard-v2 .payment-history-table th {
    border-color: var(--dashboard-border);
    color: var(--dashboard-muted);
    font-family: 'Inter', sans-serif;
}

.dashboard-v2 .payment-history-table td {
    border-color: rgba(2, 53, 60, .08);
    color: var(--dashboard-ink);
    font-family: 'Inter', sans-serif;
}

.dashboard-v2 .method-icon {
    background: #c1f6ed;
    color: var(--dashboard-primary);
}

.dashboard-v2 .dashboard-empty {
    background: rgba(193, 246, 237, .48);
}

.dashboard-v2 .notice-meta,
.dashboard-v2 .manager-profile span {
    color: var(--dashboard-muted);
}

.dashboard-v2 .manager-avatar {
    background: #c1f6ed;
    color: var(--dashboard-primary);
}

.dashboard-v2 .manager-profile strong {
    color: var(--dashboard-ink);
}

.dashboard-v2 .reminder-list li {
    color: var(--dashboard-ink);
}

.dashboard-v2 .reminder-list li::before {
    color: var(--dashboard-primary);
}

</style>


<style>
.dashboard-restored {
    max-width: 1180px;
    padding: 28px 22px 56px;
}

.dashboard-restored .dashboard-hero {
    min-height: 150px;
    border: 0;
    background: #02353c;
}

.dashboard-restored .dashboard-hero h1,
.dashboard-restored .dashboard-hero p {
    color: #fff;
}

.dashboard-restored .dashboard-hero p {
    opacity: .88;
}

.dashboard-restored .dashboard-hero .hero-verified {
    border-color: rgba(63, 208, 201, .38);
    background: rgba(63, 208, 201, .13);
    color: #c1f6ed;
}

.dashboard-restored .dashboard-hero .hero-actions .quick-pay {
    border-color: #2eaf7d;
    background: #2eaf7d;
    color: #fff;
}

.dashboard-restored .home-card,
.dashboard-restored .rent-card,
.dashboard-restored .maintenance-card,
.dashboard-restored .quick-action-card,
.dashboard-restored .dashboard-panel {
    border: 1px solid rgba(2, 53, 60, .08);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 2px 5px rgba(2, 53, 60, .025), 0 10px 30px rgba(2, 53, 60, .05);
}

.dashboard-restored .home-card {
    display: grid;
    grid-template-columns: minmax(220px, .9fr) minmax(0, 1.4fr);
    gap: 22px;
    overflow: hidden;
    margin-bottom: 22px;
    padding: 14px;
}

.dashboard-restored .home-room-image {
    min-height: 245px;
    overflow: hidden;
    border-radius: 12px;
    background: #c1f6ed;
}

.dashboard-restored .home-room-image img {
    display: block;
    width: 100%;
    height: 100%;
    min-height: 245px;
    border-radius: 12px;
    object-fit: cover;
}

.dashboard-restored .home-room-image-placeholder {
    display: grid;
    min-height: 245px;
    place-items: center;
    padding: 20px;
    border-radius: 12px;
    background: #c1f6ed;
    color: #02353c;
    font: 600 15px 'Inter', sans-serif;
    text-align: center;
}

.dashboard-restored .home-card-body {
    display: grid;
    align-content: center;
    gap: 13px;
    padding: 10px 8px;
}

.dashboard-restored .dashboard-eyebrow {
    margin: 0;
    color: #2eaf7d;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .1em;
    text-transform: uppercase;
}

.dashboard-restored .home-card-heading,
.dashboard-restored .dashboard-panel-heading h2,
.dashboard-restored .rent-card h2,
.dashboard-restored .maintenance-card h2,
.dashboard-restored .quick-actions-title {
    margin: 0;
    color: #02353c;
    font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
    font-weight: 700;
}

.dashboard-restored .home-card-heading {
    font-size: 24px;
}

.dashboard-restored .home-room-type,
.dashboard-restored .home-rate-caption,
.dashboard-restored .home-detail-label {
    color: #02353c;
    font-size: 12px;
}

.dashboard-restored .home-room-type,
.dashboard-restored .home-rate-caption {
    margin: 0;
}

.dashboard-restored .home-rate {
    color: #2eaf7d;
    font-size: 21px;
    font-weight: 800;
}

.dashboard-restored .home-details {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 9px;
}

.dashboard-restored .home-detail {
    min-width: 0;
    padding: 11px 12px;
    border: 1px solid rgba(2, 53, 60, .08);
    border-radius: 11px;
    background: #fff;
    box-shadow: 0 2px 5px rgba(2, 53, 60, .025), 0 6px 14px rgba(2, 53, 60, .035);
}

.dashboard-restored .home-detail strong,
.dashboard-restored .home-detail span {
    display: block;
}

.dashboard-restored .home-detail-label {
    color: #02353c;
    font-size: 10px;
    font-weight: 600;
}

.dashboard-restored .home-detail strong {
    margin-top: 4px;
    color: #02353c;
    font-size: 12px;
    overflow-wrap: anywhere;
}

.dashboard-restored .home-state-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
}

.dashboard-restored .home-state-tag,
.dashboard-restored .home-inclusion-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 27px;
    padding: 5px 9px;
    border-radius: 999px;
    background: #3fd0c9;
    color: #02353c;
    font-size: 10px;
    font-weight: 700;
}

.dashboard-restored .home-inclusions {
    display: grid;
    gap: 8px;
}

.dashboard-restored .home-inclusions-heading {
    margin: 0;
    color: #02353c;
    font-size: 12px;
    font-weight: 700;
}

.dashboard-restored .home-inclusion-list {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
}

.dashboard-restored .home-inclusion-tag {
    border: 1px solid rgba(46, 175, 125, .2);
    background: #fff;
    color: #02353c;
    font-weight: 600;
}

.dashboard-restored .home-inclusion-icon {
    color: #2eaf7d;
    font-size: 12px;
}

.dashboard-restored .home-inclusions-empty {
    margin: 0;
    color: #02353c;
    font-size: 11px;
}

.dashboard-restored .home-card .room-action {
    margin: 0;
}

.dashboard-restored .home-card-link {
    width: fit-content;
    color: #2eaf7d;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
}

.dashboard-restored .home-card-link:hover {
    color: #02353c;
    text-decoration: underline;
}

.dashboard-restored .home-card .btn-primary,
.dashboard-restored .rent-card .btn-primary,
.dashboard-restored .maintenance-card .btn-primary,
.dashboard-restored .quick-action-card {
    border: 1px solid #2eaf7d;
    background: #2eaf7d;
    color: #fff;
}

.dashboard-restored .home-card .btn-primary,
.dashboard-restored .rent-card .btn-primary,
.dashboard-restored .maintenance-card .btn-primary {
    display: inline-flex;
    min-height: 40px;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    padding: 9px 15px;
    border-radius: 8px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 700;
}

.dashboard-restored .billing-maintenance-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 22px;
}

.dashboard-restored .rent-card,
.dashboard-restored .maintenance-card {
    display: grid;
    align-content: start;
    gap: 12px;
    padding: 21px;
}

.dashboard-restored .rent-card h2,
.dashboard-restored .maintenance-card h2 {
    font-size: 17px;
}

.dashboard-restored .rent-card p,
.dashboard-restored .maintenance-card p {
    margin: 0;
    color: #02353c;
    font-size: 13px;
    line-height: 1.5;
}

.dashboard-restored .rent-card-amount {
    color: #2eaf7d;
    font-size: 26px;
    font-weight: 800;
}

.dashboard-restored .dashboard-status {
    display: inline-flex;
    width: fit-content;
    align-items: center;
    padding: 5px 10px;
    border-radius: 999px;
    background: #3fd0c9;
    color: #02353c;
    font-size: 11px;
    font-weight: 700;
}

.dashboard-restored .dashboard-status.is-success {
    background: #c1f6ed;
}

.dashboard-restored .dashboard-status.is-danger {
    background: #02353c;
    color: #fff;
}

.dashboard-restored .quick-actions-section {
    margin-bottom: 22px;
}

.dashboard-restored .quick-actions-title {
    margin-bottom: 12px;
    font-size: 19px;
}

.dashboard-restored .quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
}

.dashboard-restored .quick-action-card {
    display: flex;
    min-height: 78px;
    align-items: center;
    gap: 12px;
    padding: 16px;
    border-color: rgba(2, 53, 60, .1);
    background: #fff;
    box-shadow: none;
    color: #02353c;
    text-decoration: none;
    transition: border-color .16s ease, transform .16s ease;
}

.dashboard-restored .quick-action-card:hover {
    transform: translateY(-2px);
    border-color: #2eaf7d;
}

.dashboard-restored .rent-card-footer {
    display: grid;
    gap: 10px;
    margin-top: 2px;
}

.dashboard-restored .payment-history-link {
    display: inline-block;
    margin-top: 14px;
    color: #2eaf7d;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
}

.dashboard-restored .payment-history-link:hover {
    color: #02353c;
    text-decoration: underline;
}

.dashboard-restored .quick-action-icon {
    display: grid;
    width: 36px;
    height: 36px;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 10px;
    background: #c1f6ed;
    color: #2eaf7d;
    font-size: 16px;
    font-weight: 800;
}

.dashboard-restored .quick-action-card strong,
.dashboard-restored .quick-action-card span {
    display: block;
}

.dashboard-restored .quick-action-card strong {
    font: 700 13px 'Plus Jakarta Sans', 'Inter', sans-serif;
}

.dashboard-restored .quick-action-card span {
    margin-top: 3px;
    color: #02353c;
    font-size: 11px;
}

.dashboard-restored .dashboard-content-grid {
    grid-template-columns: minmax(0, 1.65fr) minmax(270px, .9fr);
}

.dashboard-restored .dashboard-main-column,
.dashboard-restored .dashboard-sidebar {
    display: grid;
    align-content: start;
    gap: 18px;
    min-width: 0;
}

.dashboard-restored .dashboard-panel {
    padding: 20px;
}

.dashboard-rental-list {
    display: grid;
    gap: 9px;
}

.dashboard-rental-item {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px;
    border: 1px solid rgba(2, 53, 60, .1);
    border-radius: 10px;
    background: rgba(255, 255, 255, .72);
}

.dashboard-rental-item strong,
.dashboard-rental-item span {
    display: block;
}

.dashboard-rental-item strong {
    color: #02353c;
    font-size: 12px;
}

.dashboard-rental-item span {
    margin-top: 3px;
    color: #315c5d;
    font-size: 11px;
}

.dashboard-rental-item a {
    color: #087f67;
    font-size: 11px;
    font-weight: 700;
}

.dashboard-rental-item.is-ended {
    opacity: .78;
}

.dashboard-history-heading {
    margin: 20px 0 10px;
    color: #02353c;
    font-size: 14px;
}

.dashboard-restored .dashboard-panel-heading {
    margin-bottom: 13px;
}

.dashboard-restored .dashboard-panel-heading h2 {
    font-size: 17px;
}

.dashboard-restored .dashboard-panel-heading a {
    color: #2eaf7d;
}

.dashboard-restored .payment-history-table th {
    border-color: rgba(2, 53, 60, .1);
    color: #02353c;
}

.dashboard-restored .payment-history-table td {
    border-color: rgba(2, 53, 60, .08);
    color: #02353c;
}

.dashboard-restored .method-icon {
    background: #c1f6ed;
    color: #2eaf7d;
}

.dashboard-restored .dashboard-empty {
    color: #02353c;
    background: rgba(193, 246, 237, .48);
}

.dashboard-restored .manager-profile strong,
.dashboard-restored .manager-profile span,
.dashboard-restored .manager-contact-note,
.dashboard-restored .notice-meta {
    color: #02353c;
}

.dashboard-restored .manager-avatar {
    background: #c1f6ed;
    color: #2eaf7d;
}

@media (max-width: 850px) {
    .dashboard-restored .dashboard-content-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {
    .dashboard-restored .home-card {
        grid-template-columns: 1fr;
        gap: 12px;
    }

    .dashboard-restored .home-room-image,
    .dashboard-restored .home-room-image img,
    .dashboard-restored .home-room-image-placeholder {
        min-height: 190px;
        max-height: 230px;
    }

    .dashboard-restored .billing-maintenance-grid,
    .dashboard-restored .quick-actions-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 520px) {
    .dashboard-restored {
        padding: 22px 15px 42px;
    }

    .dashboard-restored .dashboard-hero {
        padding: 22px;
    }

    .dashboard-restored .home-card-body,
    .dashboard-restored .rent-card,
    .dashboard-restored .maintenance-card,
    .dashboard-restored .dashboard-panel {
        padding: 18px;
    }

    .dashboard-restored .home-details {
        grid-template-columns: 1fr;
    }
}

@media (prefers-reduced-motion: reduce) {
    .dashboard-restored .hero-actions a,
    .dashboard-restored .quick-action-card {
        transition: none;
    }
}

.dashboard-restored .dashboard-hero {
    background: #02353c;
}

.dashboard-restored .quick-action-icon {
    display: grid;
    margin-top: 0;
    color: #2eaf7d;
    font-size: 16px;
}

.dashboard-restored .quick-action-card > span:last-child,
.dashboard-restored .quick-action-card > span:last-child span {
    display: block;
    margin-top: 0;
    color: #02353c;
    font-size: 11px;
}

.dashboard-restored .quick-action-card > span:last-child span {
    margin-top: 3px;
}

.dashboard-restored .dashboard-hero h1 {
    font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
}

.dashboard-restored .dashboard-hero .hero-copy {
    gap: 9px;
}

.dashboard-restored .hero-room-tag {
    display: inline-flex;
    width: fit-content;
    padding: 5px 9px;
    border: 1px solid rgba(193, 246, 237, .22);
    border-radius: 8px;
    color: #c1f6ed;
    font-size: 12px;
}

.dashboard-restored .home-room-image {
    position: relative;
}

.dashboard-restored .home-unit-badge {
    position: absolute;
    right: 12px;
    bottom: 12px;
    max-width: calc(100% - 24px);
    padding: 7px 10px;
    border: 1px solid rgba(2, 53, 60, .08);
    border-radius: 999px;
    background: #fff;
    color: #02353c;
    font-size: 11px;
    font-weight: 700;
}

.dashboard-restored .home-card-subtitle {
    margin: -7px 0 1px;
    color: #02353c;
    font-size: 12px;
    line-height: 1.5;
}

.dashboard-restored .home-detail {
    border-radius: 12px;
    box-shadow: 0 2px 5px rgba(2, 53, 60, .03), 0 6px 14px rgba(2, 53, 60, .045);
}

.dashboard-restored .home-inclusions-empty,
.dashboard-restored .data-not-configured {
    margin: 0;
    color: #02353c;
    font-size: 11px;
    line-height: 1.55;
}

.dashboard-restored .billing-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.dashboard-restored .billing-card-header .dashboard-eyebrow {
    color: #02353c;
}

.dashboard-restored .dashboard-status {
    flex: 0 0 auto;
}

.dashboard-restored .rent-payment-note,
.dashboard-restored .maintenance-meta {
    margin: 0;
    color: #02353c;
    font-size: 11px;
    line-height: 1.5;
}

.dashboard-restored .quick-actions-section {
    padding: 20px;
    border: 1px solid rgba(2, 53, 60, .08);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 10px 30px rgba(2, 53, 60, .05);
}

.dashboard-restored .quick-action-card {
    border-radius: 12px;
    box-shadow: none;
}

.dashboard-restored .quick-action-card:hover {
    background: #fff;
}

.dashboard-restored .payment-history-subtitle {
    margin: -5px 0 12px;
    color: #02353c;
    font-size: 11px;
    line-height: 1.5;
}

.dashboard-restored .payment-history-table th,
.dashboard-restored .payment-history-table td {
    padding-right: 10px;
    padding-left: 10px;
}

.dashboard-restored .dashboard-panel-heading h2 {
    font-size: 15px;
    letter-spacing: .04em;
    text-transform: uppercase;
}

.dashboard-restored .dashboard-panel-heading {
    margin-bottom: 10px;
}

.dashboard-restored .dashboard-sidebar .dashboard-panel {
    border-radius: 16px;
    box-shadow: 0 10px 30px rgba(2, 53, 60, .05);
}

.dashboard-restored .property-team-contact {
    display: grid;
    gap: 4px;
    margin: 0 0 14px;
    color: #02353c;
    font-size: 12px;
    line-height: 1.5;
}

.dashboard-restored .manager-message {
    min-height: 40px;
    padding: 10px 14px;
    border-radius: 8px;
    color: #fff;
    text-decoration: none;
    font-size: 12px;
    font-weight: 700;
}

@media (max-width: 700px) {
    .dashboard-restored .quick-actions-section {
        padding: 17px;
    }
}

body.tenant-portal {
    background-color: #c1f6ed;
    background-image:
        radial-gradient(ellipse at 12% 18%, rgba(255, 255, 255, .72), transparent 34%),
        radial-gradient(ellipse at 88% 48%, rgba(63, 208, 201, .18), transparent 32%),
        repeating-linear-gradient(135deg, transparent 0 118px, rgba(2, 53, 60, .025) 119px 120px);
    color: #02353c;
}

.tenant-site-header .main-nav {
    display: flex;
}

.tenant-site-header .tenant-header-actions .btn-outline {
    display: inline-block;
}

.tenant-site-header .main-nav a.active {
    background: #2eaf7d;
    color: #fff;
}

.dashboard-restored .home-card,
.dashboard-restored .home-detail,
.dashboard-restored .rent-card,
.dashboard-restored .maintenance-card,
.dashboard-restored .quick-actions-section,
.dashboard-restored .quick-action-card,
.dashboard-restored .dashboard-panel {
    border: 1px solid rgba(255, 255, 255, .4);
    background: rgba(255, 255, 255, .85);
    box-shadow: 0 3px 8px rgba(2, 53, 60, .035), 0 14px 34px rgba(2, 53, 60, .075);
    -webkit-backdrop-filter: blur(12px);
    backdrop-filter: blur(12px);
}

.dashboard-restored .dashboard-hero {
    background: #02353c;
}

.dashboard-restored .quick-action-card:hover {
    background: rgba(255, 255, 255, .94);
}

.dashboard-restored .dashboard-hero h1,
.dashboard-restored .dashboard-hero p,
.dashboard-restored .section-title,
.dashboard-restored .dashboard-panel-heading h2,
.dashboard-restored .rent-card h2,
.dashboard-restored .maintenance-card h2,
.dashboard-restored .quick-actions-title,
.dashboard-restored .home-card-heading {
    color: #02353c;
}

.dashboard-restored .dashboard-hero h1,
.dashboard-restored h2,
.dashboard-restored h3 {
    font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
}

.dashboard-restored .dashboard-hero h1 {
    color: #fff;
}

.dashboard-restored .dashboard-hero p {
    color: #c1f6ed;
}

.dashboard-restored .quick-action-card {
    min-width: 0;
    align-items: flex-start;
    color: #02353c;
}

.dashboard-restored .quick-action-card > span:last-child {
    min-width: 0;
    flex: 1 1 auto;
}

.dashboard-restored .quick-action-card strong,
.dashboard-restored .quick-action-card > span:last-child span {
    overflow-wrap: anywhere;
    line-height: 1.45;
}

.dashboard-restored .quick-action-card .quick-action-icon {
    margin-top: 1px;
}

@media (max-width: 900px) {
    .tenant-site-header .header-container {
        align-items: center;
    }

    .tenant-site-header .main-nav {
        order: 3;
        flex: 1 1 100%;
        justify-content: flex-start;
        flex-wrap: nowrap;
        overflow-x: auto;
        overscroll-behavior-x: contain;
        padding-bottom: 4px;
    }

    .tenant-site-header .main-nav a {
        flex: 0 0 auto;
        white-space: nowrap;
    }

    .tenant-site-header .tenant-header-actions {
        order: 2;
        margin-left: auto;
    }

    .tenant-site-header .tenant-header-actions .btn {
        width: auto;
        min-width: 0;
    }
}

@media (max-width: 520px) {
    .tenant-site-header {
        padding: 12px 14px;
    }

    .tenant-site-header .main-nav {
        gap: 2px;
    }

    .tenant-site-header .main-nav a {
        padding: 7px 10px;
        font-size: 13px;
    }

    .tenant-site-header .tenant-header-actions {
        gap: 6px;
    }

    .tenant-site-header .tenant-header-actions .btn {
        padding: 8px 10px;
        font-size: 12px;
    }
}
</style>

<main class="tenant-dashboard dashboard-restored">
    <section class="dashboard-hero" aria-labelledby="dashboard-title">
        <div class="dashboard-hero-content hero-copy">
            <span class="hero-verified"><span aria-hidden="true">✓</span> <?= e(strtoupper($tenantStatusLabel)); ?> TENANT</span>
            <h1 id="dashboard-title">Good morning, <?= e($firstName); ?>!</h1>
            <p>Welcome back to your Boarding Hauz home.</p>
            <?php if ($tenant && !empty($tenant['room_id'])): ?>
                <span class="hero-room-tag"><?= e($roomTitle); ?> - <?= e($tenant['room_type']); ?></span>
            <?php endif; ?>
        </div>
        <div class="hero-actions">
            <a class="quick-pay" href="/boarding_hauz/index.php#rooms">
                <svg aria-hidden="true" viewBox="0 0 20 20" width="16" height="16" focusable="false">
                    <path d="M2.5 9.1 10 3l7.5 6.1M4.5 8.2v8.3h11V8.2M8 16.5v-5h4v5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"/>
                </svg>
                Browse Rooms
            </a>
            <a href="tenant_maintenance.php">Need Help?</a>
        </div>
    </section>

    <?php if (!$tenant): ?>
        <section class="dashboard-panel">
            <p class="dashboard-empty">Your tenant information is not available yet. Please contact management through Messages for assistance.</p>
        </section>
    <?php else: ?>
        <section class="home-card" aria-labelledby="home-card-title">
            <div class="home-room-image">
                <?php if ($roomImageUrl !== null): ?>
                    <img src="<?= e($roomImageUrl); ?>" alt="Photo of <?= e($roomTitle); ?>" loading="lazy">
                <?php else: ?>
                    <div class="home-room-image-placeholder">
                        <?= !empty($tenant['room_id']) ? 'Room photo is not available.' : 'No room assigned yet.'; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($tenant['room_id'])): ?>
                    <span class="home-unit-badge"><?= e($roomTitle); ?></span>
                <?php endif; ?>
            </div>
            <div class="home-card-body">
                <p class="dashboard-eyebrow">YOUR HOME</p>
                <p class="home-card-subtitle">Your currently assigned quarters and living space</p>
                <?php if (!empty($tenant['room_id'])): ?>
                    <h2 class="home-card-heading" id="home-card-title"><?= e($roomTitle); ?></h2>
                    <p class="home-room-type"><?= e($tenant['room_type']); ?></p>
                    <div class="home-state-tags" aria-label="Room and lease status">
                        <span class="home-state-tag"><?= e(ucfirst(strtolower($tenant['room_status']))); ?></span>
                        <span class="home-state-tag"><?= e($tenantStatusLabel); ?> Tenant</span>
                    </div>
                    <div class="home-details">
                        <div class="home-detail">
                            <span class="home-detail-label">Monthly Rate</span>
                            <strong>₱<?= number_format((float) $tenant['monthly_rate'], 2); ?> / month</strong>
                        </div>
                        <div class="home-detail">
                            <span class="home-detail-label">Resident Since</span>
                            <strong><?= e(dashboardDate($tenant['move_in_date'])); ?></strong>
                            <span class="home-detail-label"><?= e($displayName); ?></span>
                        </div>
                        <div class="home-detail">
                            <span class="home-detail-label">Occupancy</span>
                            <strong><?= $roomOccupancy; ?> <?= $roomOccupancy === 1 ? 'Tenant' : 'Tenants'; ?> (<?= (int) $tenant['capacity'] === 1 ? 'Solo Max' : 'Max ' . (int) $tenant['capacity']; ?>)</strong>
                            <span class="home-detail-label"><?= (int) $tenant['available_slots']; ?> available <?= (int) $tenant['available_slots'] === 1 ? 'slot' : 'slots'; ?></span>
                        </div>
                    </div>
                    <div class="home-inclusions">
                        <p class="home-inclusions-heading">Included in Your Unit</p>
                        <p class="home-inclusions-empty">Unit inclusions have not been recorded for this room.</p>
                    </div>
                    <a class="home-card-link" href="tenant_room.php">View Room Details →</a>
                <?php else: ?>
                    <h2 class="home-card-heading" id="home-card-title">No room assigned yet</h2>
                    <p class="home-room-type">Browse available rooms or contact management for assistance.</p>
                    <div class="room-action"><a href="rooms.php" class="btn-primary">Browse Rooms</a></div>
                <?php endif; ?>
            </div>
        </section>

        <section class="dashboard-panel" aria-labelledby="rental-overview-title">
            <div class="dashboard-panel-heading">
                <div>
                    <span class="section-label">MULTI-BRANCH RENTALS</span>
                    <h2 class="section-title" id="rental-overview-title">My Active Rooms</h2>
                </div>
                <a href="rooms.php" class="summary-link">Browse all branches →</a>
            </div>
            <?php if ($activeLeases): ?>
                <div class="dashboard-rental-list">
                    <?php foreach ($activeLeases as $lease): ?>
                        <article class="dashboard-rental-item">
                            <div>
                                <strong><?= e(roomDisplayTitle($lease['room_number'])); ?> · <?= e($lease['branch_name']); ?></strong>
                                <span><?= e($lease['room_type']); ?> · <?= e($lease['branch_location']); ?></span>
                            </div>
                            <div>
                                <strong>₱<?= number_format((float) $lease['monthly_rate'], 2); ?>/month</strong>
                                <span><?= (int) $lease['current_occupants']; ?>/<?= (int) $lease['capacity']; ?> occupants</span>
                            </div>
                            <a href="tenant_room.php?lease_id=<?= (int) $lease['lease_id']; ?>">View room</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="dashboard-empty">No active rentals yet. Browse rooms at any available branch.</p>
            <?php endif; ?>

            <?php if ($leaseHistory): ?>
                <h3 class="dashboard-history-heading">Rental History</h3>
                <div class="dashboard-rental-list">
                    <?php foreach ($leaseHistory as $lease): ?>
                        <article class="dashboard-rental-item is-ended">
                            <div>
                                <strong><?= e(roomDisplayTitle($lease['room_number'])); ?> · <?= e($lease['branch_name']); ?></strong>
                                <span><?= e($lease['room_type']); ?> · Ended <?= e($lease['ended_at'] ? dashboardDate($lease['ended_at']) : 'date not recorded'); ?></span>
                            </div>
                            <strong>₱<?= number_format((float) $lease['monthly_rate'], 2); ?>/month</strong>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="billing-maintenance-grid" aria-label="Rent and maintenance">
            <article class="rent-card">
                <div class="billing-card-header">
                    <p class="dashboard-eyebrow">YOUR RENT</p>
                    <?php if ($currentPaymentDue): ?>
                        <span class="dashboard-status <?= $currentPaymentDue['status'] === 'Paid' ? 'is-success' : ($currentPaymentDue['status'] === 'Overdue' ? 'is-danger' : ''); ?>"><?= e($dueCountdown); ?></span>
                    <?php endif; ?>
                </div>
                <h2>Upcoming billing amount</h2>
                <?php if ($currentPaymentDue): ?>
                    <strong class="rent-card-amount">₱<?= number_format((float) $currentPaymentDue['amount_due'], 2); ?></strong>
                    <p>Due <?= e($currentPaymentDue['due_date']->format('F j, Y')); ?></p>
                    <div class="rent-card-footer">
                        <p class="rent-payment-note">View available payment methods and submit payment in Payments.</p>
                        <a href="tenant_payments.php" class="btn-primary">Pay Rent Now →</a>
                    </div>
                <?php else: ?>
                    <p>Rent details will appear once a room is assigned.</p>
                <?php endif; ?>
            </article>

            <article class="maintenance-card">
                <div class="billing-card-header">
                    <p class="dashboard-eyebrow">YOUR MAINTENANCE</p>
                    <span class="dashboard-status"><?= $maintenanceCount; ?> Active <?= $maintenanceCount === 1 ? 'Ticket' : 'Tickets'; ?></span>
                </div>
                <?php if ($latestMaintenance): ?>
                    <h2><?= e($latestMaintenance['category']); ?></h2>
                    <p><?= e($latestMaintenance['description']); ?></p>
                    <p class="maintenance-meta">
                        Request #<?= (int) $latestMaintenance['id']; ?>
                        · Reported <?= e(dashboardDate($latestMaintenance['created_at'])); ?>
                    </p>
                    <span class="dashboard-status <?= dashboardMaintenanceStatus($latestMaintenance['status']) === 'status-success' ? 'is-success' : ''; ?>"><?= e($latestMaintenance['status']); ?></span>
                <?php else: ?>
                    <h2>No recent maintenance request</h2>
                    <p>No active maintenance requests.</p>
                <?php endif; ?>
                <div class="rent-card-footer">
                    <p class="maintenance-meta">Request updates are available in Maintenance.</p>
                    <a href="tenant_maintenance.php" class="btn-primary">View Maintenance Tickets →</a>
                </div>
            </article>
        </section>

        <section class="quick-actions-section" aria-labelledby="quick-actions-title">
            <h2 class="quick-actions-title" id="quick-actions-title">WHAT DO YOU NEED?</h2>
            <div class="quick-actions-grid">
                <a class="quick-action-card" href="tenant_payments.php">
                    <span class="quick-action-icon" aria-hidden="true">₱</span>
                    <span><strong>Pay Rent</strong><span>Submit receipt or digital payment</span></span>
                </a>
                <a class="quick-action-card" href="tenant_maintenance.php">
                    <span class="quick-action-icon" aria-hidden="true">+</span>
                    <span><strong>Report Maintenance Issue</strong><span>Create a request for repairs and checks</span></span>
                </a>
                <a class="quick-action-card" href="tenant_messages.php">
                    <span class="quick-action-icon" aria-hidden="true">✉</span>
                    <span><strong>Message Management</strong><span>Contact management for assistance</span></span>
                </a>
            </div>
        </section>

        <div class="dashboard-content-grid">
            <div class="dashboard-main-column">
                <section class="dashboard-panel" aria-labelledby="payment-history-title">
                    <div class="dashboard-panel-heading">
                        <h2 id="payment-history-title">Recent Payments</h2>
                    </div>
                    <p class="payment-history-subtitle">Your rent history and transaction records</p>
                    <?php if ($recentPayments): ?>
                        <div class="payment-table-wrap">
                            <table class="payment-history-table">
                                <thead><tr><th>Date</th><th>Description</th><th>Amount</th><th>Method</th><th>Status</th></tr></thead>
                                <tbody>
                                    <?php foreach ($recentPayments as $payment): ?>
                                        <?php
                                        $paymentStatus = in_array($payment['status'], ['Paid', 'Verified'], true) ? 'Paid' : $payment['status'];
                                        $methodIcon = $payment['payment_method'] === 'GCash' ? 'G' : ($payment['payment_method'] === 'Maya' ? 'M' : ($payment['payment_method'] === 'Bank Transfer' ? 'B' : '₱'));
                                        $methodLabel = $payment['payment_method'] === 'Bank Transfer' ? 'Bank (BPI)' : $payment['payment_method'];
                                        ?>
                                        <tr>
                                            <td><?= e(dashboardDate($payment['payment_date'])); ?></td>
                                            <td>Rent payment</td>
                                            <td>₱<?= number_format((float) $payment['amount_paid'], 2); ?></td>
                                            <td><span class="method-label"><span class="method-icon" aria-hidden="true"><?= e($methodIcon); ?></span><?= e($methodLabel); ?></span></td>
                                            <td><span class="status-badge <?= dashboardPaymentStatus($paymentStatus); ?>"><?= $paymentStatus === 'Paid' ? 'Verified Paid' : e($paymentStatus); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="dashboard-empty">No payments have been recorded yet.</p>
                    <?php endif; ?>
                    <a class="payment-history-link" href="tenant_payments.php">View All Payment Receipts →</a>
                </section>
            </div>

            <aside class="dashboard-sidebar" aria-label="Building notice and property team">
                <section class="dashboard-panel">
                    <div class="dashboard-panel-heading"><h2>Building Notice</h2></div>
                    <p class="dashboard-empty">There are no published building notices at this time.</p>
                    <small class="notice-meta">New notices will appear here when management publishes them.</small>
                </section>

                <section class="dashboard-panel">
                    <div class="dashboard-panel-heading"><h2>Your Property Team</h2></div>
                    <div class="manager-profile">
                        <div class="manager-avatar" aria-hidden="true">BH</div>
                        <div><strong>Property Management</strong><span>Boarding Hauz Team</span></div>
                    </div>
                    <p class="property-team-contact">Manager profile and phone number are not configured.</p>
                    <a href="tenant_messages.php" class="btn-primary manager-message">Send a Message →</a>
                </section>
            </aside>
        </div>
    <?php endif; ?>
</main>

<?php if (false): ?>
<main class="tenant-dashboard">


    <!-- =====================================================
         WELCOME HERO
         ===================================================== -->

    <section class="dashboard-hero">

        <div class="dashboard-hero-content">

            <span class="dashboard-label">
                TENANT PORTAL
            </span>

            <h1>
                Welcome back, <?= e($displayName); ?>!
            </h1>

            <p>

                <?php if ($tenant && !empty($tenant['room_id'])): ?>

                    <?= e(roomDisplayTitle($tenant['room_number'])); ?>
                    · <?= e($tenant['room_type']); ?>

                <?php else: ?>

                    Welcome to your Boarding Hauz tenant portal.

                <?php endif; ?>

            </p>

        </div>


        <div class="dashboard-hero-actions">

            <!-- Goes to Available Rooms section of landing page -->
            <a
                href="index.php#rooms"
                class="btn-browse"
            >
                Browse Rooms
            </a>


            <!-- Goes to Messages -->
            <a
                href="tenant_messages.php"
                class="btn-help"
            >
                Get Help
            </a>

        </div>

    </section>



    <?php if (!$tenant): ?>

        <!-- =================================================
             TENANT RECORD NOT FOUND
             ================================================= -->

        <section class="room-card">

            <div class="no-room">

                <p>
                    Your tenant information is not available yet.
                </p>

                <span>
                    Please contact the administrator through
                    Messages for assistance.
                </span>

                <div class="room-action">

                    <a
                        href="tenant_messages.php"
                        class="btn-primary"
                    >
                        Contact Administrator
                    </a>

                </div>

            </div>

        </section>


    <?php else: ?>


        <!-- =================================================
             MY ROOM
             ================================================= -->

        <section class="room-card tenant-room-card">
            <?php if (!empty($tenant['room_id'])): ?>

                <div class="tenant-room-image">
                    <?php if (!empty($tenant['room_image_url'])): ?>
                        <img src="<?= e($tenant['room_image_url']); ?>" alt="Photo of <?= e(roomDisplayTitle($tenant['room_number'])); ?>" loading="lazy">
                    <?php else: ?>
                        <div class="tenant-room-image-placeholder"><?= e(roomDisplayTitle($tenant['room_number'])); ?></div>
                    <?php endif; ?>
                </div>

                <div class="tenant-room-card-body">
                    <span class="section-label">MY ROOM</span>

                    <div class="room-card-top">
                        <div>
                            <h2 class="room-number"><?= e(roomDisplayTitle($tenant['room_number'])); ?></h2>
                            <p class="room-type"><?= e($tenant['room_type']); ?></p>
                            <span class="branch-indicator"><span class="branch-indicator-label">Branch:</span> <?= e($tenant['branch_name'] ?? 'Branch unavailable'); ?></span>
                        </div>
                        <span class="room-status tenant-room-status-<?= e(strtolower(str_replace(' ', '-', $tenant['room_status']))); ?>">
                            <?= e($tenant['room_status']); ?>
                        </span>
                    </div>

                    <div class="tenant-room-capacity">
                        Occupancy: <?= (int) $tenant['current_occupants']; ?>/<?= (int) $tenant['capacity']; ?> · <?= (int) $tenant['available_slots']; ?> available slots
                    </div>
                    <div class="tenant-room-rate">
                        ₱<?= number_format((float) $tenant['monthly_rate'], 2); ?> <small>/ month</small>
                    </div>

                    <div class="tenant-room-info">
                        <div class="room-detail">
                            <span>Move-in Date</span>
                            <strong><?= dashboardDate($tenant['move_in_date']); ?></strong>
                        </div>
                        <div class="room-detail">
                            <span>Tenant Status</span>
                            <strong><?= e(ucfirst(strtolower($tenant['tenant_status']))); ?></strong>
                        </div>
                    </div>

                    <div class="room-action">
                        <a href="tenant_room.php" class="btn-primary">View My Room</a>
                    </div>
                </div>

            <?php else: ?>

                <div class="tenant-room-card-body">
                    <span class="section-label">MY ROOM</span>
                    <div class="no-room">

                        <p>
                            You do not have an assigned room yet.
                        </p>

                        <span>
                            You can browse the available rooms or
                            contact the administrator for assistance.
                        </span>

                        <div class="room-action">

                            <a
                                href="index.php#rooms"
                                class="btn-primary"
                            >
                                Browse Available Rooms
                            </a>

                        </div>
                    </div>
                </div>

            <?php endif; ?>

        </section>



        <?php if ($roomApplications): ?>
            <section class="tenant-room-applications" aria-labelledby="room-application-heading">
                <h2 id="room-application-heading">Room Application</h2>
                <?php foreach ($roomApplications as $roomApplication): ?>
                    <?php
                    $applicationTimestamp = strtotime($roomApplication['created_at']);
                    $applicationStatusClass = strtolower($roomApplication['status']);
                    ?>
                    <div class="tenant-room-application">
                        <div class="tenant-room-application-details">
                            <strong><?= e(roomDisplayTitle($roomApplication['room_number'])); ?> - <?= e($roomApplication['room_type']); ?></strong>
                            <span>₱<?= number_format((float) $roomApplication['monthly_rate'], 2); ?>/month<?= $applicationTimestamp ? ' · Applied ' . e(date('F j, Y', $applicationTimestamp)) : ''; ?></span>
                            <?php if ($roomApplication['status'] === 'Approved'): ?>
                                <span>Your assigned room is now <?= e(roomDisplayTitle($roomApplication['room_number'])); ?>.</span>
                            <?php endif; ?>
                        </div>
                        <span class="tenant-room-application-status <?= e($applicationStatusClass); ?>">Status: <?= e($roomApplication['status']); ?></span>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>



        <!-- =================================================
             SUMMARY
             ================================================= -->

        <section class="summary-grid">


            <!-- PAYMENT -->

            <article class="summary-card">

                <div class="summary-card-header">

                    <span class="summary-card-label">
                        LATEST PAYMENT
                    </span>

                    <span class="summary-icon">
                        ₱
                    </span>

                </div>


                <?php if ($latestPayment): ?>

                    <h3>
                        ₱<?= number_format(
                            (float) $latestPayment['amount_paid'],
                            2
                        ); ?>
                    </h3>

                    <span class="status-badge <?= dashboardPaymentStatus($latestPayment['status']); ?>">
                        <?= e($latestPayment['status']); ?>
                    </span>

                    <p style="margin-top:7px;">
                        <?= dashboardDate(
                            $latestPayment['payment_date']
                        ); ?>
                    </p>

                <?php else: ?>

                    <h3 style="font-size:17px;">
                        No payment yet
                    </h3>

                    <p>
                        No payment record available.
                    </p>

                <?php endif; ?>


                <a
                    href="tenant_payments.php"
                    class="summary-link"
                >
                    View Payments →
                </a>

            </article>

            <?php if ($currentPaymentDue): ?>
                <article class="summary-card">
                    <div class="summary-card-header">
                        <span class="summary-card-label">NEXT PAYMENT DUE</span>
                        <span class="summary-icon">₱</span>
                    </div>
                    <h3><?= e($currentPaymentDue['due_date']->format('F j, Y')); ?></h3>
                    <p>Amount due: ₱<?= number_format((float) $currentPaymentDue['amount_due'], 2); ?></p>
                    <span class="status-badge <?= dashboardPaymentStatus($currentPaymentDue['status']); ?>">
                        <?= e($currentPaymentDue['status']); ?>
                    </span>
                    <?php if ($currentPaymentDue['has_pending_submission'] && $currentPaymentDue['status'] !== 'Paid'): ?>
                        <p>Payment submitted and awaiting verification.</p>
                    <?php endif; ?>
                    <a href="tenant_payments.php" class="summary-link">View Payments →</a>
                </article>
            <?php endif; ?>



            <!-- MAINTENANCE -->

            <article class="summary-card">

                <div class="summary-card-header">

                    <span class="summary-card-label">
                        MAINTENANCE
                    </span>

                    <span class="summary-icon">
                        ✓
                    </span>

                </div>


                <h3>
                    <?= $maintenanceCount; ?>
                </h3>

                <p>

                    <?php if ($maintenanceCount === 1): ?>

                        Active request

                    <?php else: ?>

                        Active requests

                    <?php endif; ?>

                </p>


                <a
                    href="tenant_maintenance.php"
                    class="summary-link"
                >
                    View Requests →
                </a>

            </article>



            <!-- MESSAGES -->

            <article class="summary-card">

                <div class="summary-card-header">

                    <span class="summary-card-label">
                        MESSAGES
                    </span>

                    <span class="summary-icon">
                        ✉
                    </span>

                </div>


                <h3>
                    <?= $unreadMessages; ?>
                </h3>


                <p>

                    <?php if ($unreadMessages === 1): ?>

                        Unread message

                    <?php else: ?>

                        Unread messages

                    <?php endif; ?>

                </p>


                <a
                    href="tenant_messages.php"
                    class="summary-link"
                >
                    Open Messages →
                </a>

            </article>

        </section>



        <!-- =================================================
             PAYMENT INFORMATION
             ================================================= -->

        <?php if (!empty($tenant['room_id'])): ?>

            <section class="payment-info">

                <div class="payment-info-content">

                    <div>

                        <h2>
                            Monthly Rent
                        </h2>

                        <p>
                            Your room's current monthly rate.
                            View Payments to submit or check
                            your payment records.
                        </p>

                    </div>


                    <div>

                        <div class="payment-amount">
                            ₱<?= number_format(
                                (float) $tenant['monthly_rate'],
                                2
                            ); ?>
                        </div>

                        <a
                            href="tenant_payments.php"
                            class="summary-link"
                        >
                            Go to Payments →
                        </a>

                    </div>

                </div>

            </section>

        <?php endif; ?>



        <!-- =================================================
             RECENT ACTIVITY
             ================================================= -->

        <section class="activity-card">

            <span class="section-label">
                RECENT ACTIVITY
            </span>

            <h2 class="section-title">
                Latest Updates
            </h2>


            <?php if (!empty($recentActivities)): ?>

                <div class="activity-list">

                    <?php foreach ($recentActivities as $activity): ?>

                        <div class="activity-item">

                            <div
                                class="activity-dot <?= $activity['type'] === 'maintenance'
                                    ? 'maintenance'
                                    : ''; ?>"
                            ></div>


                            <div class="activity-content">

                                <div class="activity-row">

                                    <h3 class="activity-title">
                                        <?= e($activity['title']); ?>
                                    </h3>


                                    <?php if ($activity['type'] === 'payment'): ?>

                                        <span class="status-badge <?= dashboardPaymentStatus($activity['status']); ?>">
                                            <?= e($activity['status']); ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="status-badge <?= dashboardMaintenanceStatus($activity['status']); ?>">
                                            <?= e($activity['status']); ?>
                                        </span>

                                    <?php endif; ?>

                                </div>


                                <p class="activity-description">
                                    <?= e($activity['description']); ?>
                                </p>


                                <small class="activity-date">
                                    <?= dashboardDate(
                                        $activity['date']
                                    ); ?>
                                </small>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>


            <?php else: ?>

                <div class="activity-empty">

                    <h3>
                        No recent activity
                    </h3>

                    <p>
                        Your payment and maintenance updates
                        will appear here.
                    </p>

                </div>

            <?php endif; ?>

        </section>


    <?php endif; ?>

</main>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>