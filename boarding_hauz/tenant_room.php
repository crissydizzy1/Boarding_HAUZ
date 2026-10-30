<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/room_images.php';
require_once __DIR__ . '/includes/room_display.php';
require_once __DIR__ . '/includes/room_occupancy.php';
requireRole('tenant');
$roomPolicies = require __DIR__ . '/includes/room_policies.php';

$tenant_title = 'My Room';
$userId = (int) $_SESSION['user_id'];
$stmt = $conn->prepare("
    SELECT
        t.id AS tenant_id,
        t.full_name,
        t.move_in_date,
        t.status AS tenant_status,
        r.id AS room_id,
        r.room_number,
        r.room_type,
        r.capacity,
        r.monthly_rate,
        r.status AS room_status,
        r.image_path,
        b.branch_name
    FROM tenants t
    LEFT JOIN rooms r ON r.id = t.room_id
    LEFT JOIN branches b ON b.id = r.branch_id
    WHERE t.user_id = ?
    LIMIT 1
");
if (!$stmt) {
    throw new mysqli_sql_exception($conn->error);
}
$stmt->bind_param('i', $userId);
$stmt->execute();
$tenant = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($tenant && $tenant['room_id'] !== null) {
    $tenant = attachRoomOccupancy($conn, [$tenant])[0];
    $tenant['room_status'] = $tenant['occupancy_status'];
}

$hasRoom = $tenant && !empty($tenant['room_id']);
$roomImageUrl = $hasRoom
    ? getRoomImageUrlForDisplay((int) $tenant['room_id'], $tenant['image_path'])
    : null;
$tenantStatus = $tenant
    ? ucfirst(strtolower((string) $tenant['tenant_status']))
    : 'Pending';
$roomTitle = $hasRoom
    ? roomDisplayTitle((string) $tenant['room_number'])
    : '';
$monthlyRate = $hasRoom ? (float) $tenant['monthly_rate'] : 0.0;
$moveInDate = null;
if ($hasRoom && !empty($tenant['move_in_date'])) {
    $moveInDateValue = (string) $tenant['move_in_date'];
    $parsedMoveInDate = DateTimeImmutable::createFromFormat('!Y-m-d', $moveInDateValue);
    if ($parsedMoveInDate instanceof DateTimeImmutable
        && $parsedMoveInDate->format('Y-m-d') === $moveInDateValue) {
        $moveInDate = $parsedMoveInDate;
    }
}
$capacity = $hasRoom ? max(0, (int) $tenant['capacity']) : 0;
$roomPoliciesList = [];

function parseRoomAmenities(string $roomType): array
{
    $features = preg_split('/\s*(?:,|\bat\b)\s*/iu', $roomType) ?: [];
    $amenities = [];
    $seen = [];

    foreach ($features as $feature) {
        $feature = trim($feature);
        if ($feature === '') {
            continue;
        }

        $key = strtolower($feature);
        if (!isset($seen[$key])) {
            $amenities[] = $feature;
            $seen[$key] = true;
        }
    }

    return $amenities;
}

$roomAmenities = $hasRoom
    ? parseRoomAmenities((string) $tenant['room_type'])
    : [];
if ($hasRoom && !$roomPoliciesList) {
    $roomPoliciesList = array_values($roomPolicies['rental_terms'] ?? []);
}
$roomType = $hasRoom ? (string) $tenant['room_type'] : '';
$standardPaymentTerms = (string) ($roomPolicies['rental_terms']['Payment'] ?? '');
$standardLeaseTerm = '';

include __DIR__ . '/includes/tenant_header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap');

.my-room-page {
    min-height: calc(100vh - 80px);
    padding: 34px 20px 52px;
    background: #f2f8f4;
    color: #173c32;
}

.my-room-container {
    width: min(1120px, 100%);
    margin: 0 auto;
}

.my-room-heading {
    margin: 0 0 20px;
}

.my-room-heading h1 {
    margin: 0 0 6px;
    color: #123f32;
    font: 700 31px/1.2 'Playfair Display', Georgia, serif;
}

.my-room-heading p {
    margin: 0;
    color: #698078;
    font-size: 14px;
}

.my-room-hero {
    display: grid;
    grid-template-columns: minmax(270px, .9fr) minmax(0, 1.4fr);
    overflow: hidden;
    margin-bottom: 22px;
    border: 1px solid #dce9e0;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 8px 24px rgba(25, 72, 52, .07);
}

.my-room-photo {
    position: relative;
    min-height: 310px;
    background: linear-gradient(145deg, #d6eee1, #a9d7c1);
}

.my-room-photo img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.my-room-photo-placeholder {
    display: grid;
    width: 100%;
    height: 100%;
    min-height: 310px;
    place-items: center;
    color: #1a6549;
    font: 700 24px 'Playfair Display', Georgia, serif;
}

.my-room-hero-body {
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 28px 32px;
}

.my-room-eyebrow {
    margin: 0 0 9px;
    color: #218057;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .09em;
    text-transform: uppercase;
}

.my-room-title-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 17px;
}

.my-room-title-row h2 {
    margin: 0 0 5px;
    color: #123f32;
    font: 700 27px/1.2 'Playfair Display', Georgia, serif;
}

.my-room-title-row p {
    margin: 0;
    color: #6d8178;
    font-size: 13px;
}

.my-room-lease-status {
    display: inline-flex;
    align-items: center;
    flex: 0 0 auto;
    gap: 6px;
    padding: 6px 10px;
    border-radius: 999px;
    background: #e3f5e9;
    color: #18734f;
    font-size: 11px;
    font-weight: 800;
}

.room-stat-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 18px;
}

.room-stat {
    min-width: 0;
    padding: 12px;
    border: 1px solid #e0ebe4;
    border-radius: 10px;
    background: #f8fbf9;
}

.room-stat-label,
.room-stat-value {
    display: block;
}

.room-stat-label {
    margin-bottom: 5px;
    color: #768980;
    font-size: 10px;
}

.room-stat-value {
    overflow-wrap: anywhere;
    color: #174b38;
    font-size: 14px;
    font-weight: 800;
}

.room-stat-value small {
    color: #72857c;
    font-size: 10px;
    font-weight: 500;
}

.my-room-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.my-room-button {
    display: inline-flex;
    min-height: 40px;
    align-items: center;
    justify-content: center;
    padding: 0 15px;
    border: 1px solid #16865e;
    border-radius: 8px;
    background: #16865e;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
}

.my-room-button:hover {
    background: #116f4c;
}

.my-room-button.secondary {
    border-color: #b9d9c7;
    background: #fff;
    color: #176847;
}

.my-room-columns {
    display: grid;
    grid-template-columns: minmax(0, 1.5fr) minmax(275px, .9fr);
    align-items: start;
    gap: 20px;
}

.my-room-panel {
    padding: 20px;
    border: 1px solid #dce9e0;
    border-radius: 13px;
    background: #fff;
    box-shadow: 0 5px 17px rgba(25, 72, 52, .04);
}

.my-room-panel-heading {
    margin: 0 0 14px;
    color: #173f32;
    font: 700 19px 'Playfair Display', Georgia, serif;
}

.my-room-panel-subtitle {
    margin: -8px 0 14px;
    color: #788a81;
    font-size: 11px;
    line-height: 1.5;
}

.stay-list {
    display: grid;
}

.stay-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    padding: 11px 0;
    border-bottom: 1px solid #edf2ee;
    font-size: 12px;
}

.stay-row:last-child {
    border-bottom: 0;
}

.stay-row span {
    color: #788a81;
}

.stay-row strong {
    color: #24493a;
    text-align: right;
}

.rules-list {
    display: grid;
    gap: 11px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.rules-list li {
    position: relative;
    padding-left: 19px;
    color: #536b5f;
    font-size: 12px;
    line-height: 1.5;
}

.rules-list li::before {
    position: absolute;
    left: 0;
    color: #188154;
    content: "✓";
    font-weight: 800;
}

.my-room-empty {
    padding: 42px 24px;
    border: 1px solid #dce9e0;
    border-radius: 15px;
    background: #fff;
    text-align: center;
    box-shadow: 0 8px 24px rgba(25, 72, 52, .05);
}

.my-room-empty h2 {
    margin: 0 0 8px;
    color: #123f32;
    font: 700 24px 'Playfair Display', Georgia, serif;
}

.my-room-empty p {
    max-width: 500px;
    margin: 0 auto 18px;
    color: #6d8178;
    font-size: 13px;
    line-height: 1.6;
}

@media (max-width: 820px) {
    .my-room-columns {
        grid-template-columns: 1fr;
    }

    .my-room-hero {
        grid-template-columns: 1fr;
    }

    .my-room-photo,
    .my-room-photo-placeholder {
        min-height: 230px;
    }
}

@media (max-width: 560px) {
    .my-room-page {
        padding: 25px 14px 38px;
    }

    .my-room-hero-body {
        padding: 22px 18px;
    }

    .my-room-title-row {
        flex-direction: column;
    }

    .my-room-title-row h2 {
        font-size: 23px;
    }

    .room-stat-grid {
        grid-template-columns: 1fr;
    }

    .my-room-actions {
        display: grid;
    }

    .my-room-actions .my-room-button {
        width: 100%;
        box-sizing: border-box;
    }
}

body.tenant-portal {
    background-color: #f8fafc;
    background-image: none;
    color: #02353c;
    font-family: 'Inter', sans-serif;
}

.my-room-page {
    min-height: calc(100vh - 80px);
    padding: 30px 20px 48px;
    background: transparent;
    color: #02353c;
}

.my-room-container {
    width: min(1180px, 100%);
}

.room-page-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    width: fit-content;
    padding: 5px 9px;
    border-radius: 999px;
    background: #3fd0c9;
    color: #02353c;
    font-size: 10px;
    font-weight: 800;
}

.my-room-hero,
.my-room-panel,
.my-room-empty {
    border: 1px solid rgba(255, 255, 255, .4);
    border-radius: 16px;
    background: rgba(255, 255, 255, .85);
    box-shadow: 0 3px 8px rgba(2, 53, 60, .035), 0 14px 34px rgba(2, 53, 60, .075);
    -webkit-backdrop-filter: blur(12px);
    backdrop-filter: blur(12px);
}

.my-room-hero {
    grid-template-columns: minmax(260px, .85fr) minmax(0, 1.4fr);
    gap: 20px;
    margin-bottom: 20px;
    padding: 14px;
}

.my-room-photo {
    min-height: 300px;
    overflow: hidden;
    border-radius: 12px;
    background: linear-gradient(145deg, #dff8f1, #a6e5d8);
}

.my-room-photo img {
    border-radius: 12px;
}

.my-room-photo-placeholder {
    min-height: 300px;
    color: #02353c;
    font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
}

.room-photo-badge {
    position: absolute;
    right: 12px;
    bottom: 12px;
    max-width: calc(100% - 24px);
    padding: 7px 10px;
    border: 1px solid rgba(255, 255, 255, .55);
    border-radius: 999px;
    background: rgba(255, 255, 255, .9);
    color: #02353c;
    font-size: 11px;
    font-weight: 700;
    backdrop-filter: blur(8px);
}

.my-room-hero-body {
    min-width: 0;
    padding: 18px 16px;
}

.my-room-eyebrow {
    color: #2eaf7d;
}

.my-room-title-row {
    margin-bottom: 14px;
}

.my-room-title-row h2,
.my-room-panel-heading,
.my-room-empty h2 {
    color: #02353c;
    font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
}

.my-room-title-row p {
    color: #315c5d;
}

.my-room-lease-status {
    background: #3fd0c9;
    color: #02353c;
}

.room-stat-grid {
    margin-bottom: 14px;
}

.room-stat {
    border-color: rgba(2, 53, 60, .08);
    border-radius: 12px;
    background: rgba(255, 255, 255, .66);
}

.room-stat-label {
    color: #315c5d;
}

.room-stat-value,
.stay-row strong {
    color: #02353c;
}

.room-stat:first-child .room-stat-value {
    color: #2eaf7d;
}

.my-room-columns {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.my-room-panel {
    min-width: 0;
    padding: 20px;
}

.my-room-panel-heading {
    margin-bottom: 12px;
    font-size: 17px;
    line-height: 1.3;
}

.my-room-panel-subtitle,
.rules-list li {
    color: #315c5d;
}

.stay-row {
    border-color: rgba(2, 53, 60, .09);
}

.stay-row span {
    color: #315c5d;
}

.rules-list li::before {
    color: #2eaf7d;
}

.room-amenity-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.room-amenity-tag {
    display: inline-flex;
    align-items: center;
    box-sizing: border-box;
    max-width: 100%;
    min-width: 0;
    gap: 7px;
    min-height: 35px;
    padding: 8px 11px;
    border: 1px solid rgba(46, 175, 125, .22);
    border-radius: 999px;
    background: rgba(193, 246, 237, .5);
    color: #02353c;
    font-size: 11px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.room-amenity-tag::before {
    color: #2eaf7d;
    content: "✓";
    font-weight: 800;
}

.room-lower-columns.without-amenities {
    grid-template-columns: 1fr;
}

.room-support-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    margin-top: 0;
    padding: 20px 23px;
    border: 1px solid rgba(255, 255, 255, .15);
    border-radius: 16px;
    background: #02353c;
    box-shadow: 0 10px 28px rgba(2, 53, 60, .14);
}

.room-support-card h2 {
    margin: 0 0 4px;
    color: #fff;
    font: 700 17px 'Plus Jakarta Sans', 'Inter', sans-serif;
}

.room-support-card p {
    margin: 0;
    color: #c1f6ed;
    font-size: 12px;
}

.room-support-card .my-room-button.secondary {
    border-color: rgba(193, 246, 237, .4);
    background: transparent;
    color: #fff;
}

.room-support-actions {
    display: flex;
    flex: 0 0 auto;
    flex-wrap: wrap;
    gap: 8px;
}

.my-room-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 20px;
    padding: 20px 23px;
    border: 1px solid rgba(255, 255, 255, .4);
    border-radius: 16px;
    background: #0d5b50;
    box-shadow: 0 3px 8px rgba(2, 53, 60, .035), 0 14px 34px rgba(2, 53, 60, .075);
    -webkit-backdrop-filter: blur(12px);
    backdrop-filter: blur(12px);
}

.my-room-heading h1 {
    margin: 0 0 4px;
    color: #ffffff;
    font: 700 25px/1.2 'Plus Jakarta Sans', 'Inter', sans-serif;
}

.my-room-heading p {
    margin: 0;
    color: #dff3f7;
    font-size: 14px;
}

.my-room-heading-status {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 7px;
}

.my-room-heading .room-page-status {
    white-space: nowrap;
}

.my-room-columns.room-main-columns {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: stretch;
    margin-bottom: 18px;
}

.my-room-columns.room-main-columns > .my-room-panel,
.room-lower-columns > .my-room-panel {
    height: 100%;
    box-sizing: border-box;
}

.stay-list {
    gap: 0;
}

.stay-row {
    min-height: 42px;
    align-items: center;
}

.stay-row strong {
    max-width: 66%;
    overflow-wrap: anywhere;
}

.room-detail-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 9px;
    margin: 0 0 14px;
}

.room-detail-item {
    min-width: 0;
    padding: 11px 12px;
    border: 1px solid rgba(2, 53, 60, .08);
    border-radius: 11px;
    background: rgba(255, 255, 255, .66);
}

.room-detail-item span,
.room-detail-item strong {
    display: block;
}

.room-detail-item span {
    margin-bottom: 4px;
    color: #315c5d;
    font-size: 10px;
}

.room-detail-item strong {
    color: #02353c;
    font-size: 12px;
    overflow-wrap: anywhere;
}

.room-lower-columns {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    align-items: stretch;
    gap: 18px;
}

.room-lower-columns > .my-room-panel {
    display: flex;
    flex-direction: column;
}

.room-lower-columns .rules-list {
    flex: 1;
}

.room-support-card {
    margin-top: 18px;
}

.my-room-title-row h2 {
    color: #02353c;
    font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
}

.room-stat:nth-child(2) .room-stat-value {
    color: #02353c;
}

.room-photo-badge {
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
}

.my-room-button {
    border-color: #2eaf7d;
    background: #2eaf7d;
}

.my-room-button:hover {
    background: #238f65;
}

.my-room-button.secondary {
    border-color: rgba(2, 53, 60, .16);
    background: rgba(255, 255, 255, .7);
    color: #02353c;
}

.my-room-button.secondary:hover {
    background: #fff;
}

.my-room-empty {
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 3px 8px rgba(2, 53, 60, .035), 0 14px 34px rgba(2, 53, 60, .075);
    -webkit-backdrop-filter: blur(12px);
    backdrop-filter: blur(12px);
    padding: 38px 24px;
}

.my-room-empty h2 {
    color: #02353c;
    font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
}

.my-room-empty p {
    color: #315c5d;
}

@media (max-width: 820px) {
    .my-room-hero {
        grid-template-columns: 1fr;
    }

    .my-room-columns {
        grid-template-columns: 1fr;
    }

    .room-lower-columns {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 560px) {
    .my-room-heading {
        align-items: flex-start;
        flex-direction: column;
        padding: 18px;
    }

    .my-room-heading-status {
        justify-content: flex-start;
    }

    .my-room-hero {
        padding: 10px;
    }

    .my-room-hero-body {
        padding: 15px 8px;
    }

    .room-detail-list {
        grid-template-columns: 1fr;
    }

    .room-stat-grid {
        grid-template-columns: 1fr;
    }

    .room-support-card {
        align-items: stretch;
        flex-direction: column;
        padding: 19px;
    }

    .room-support-actions {
        display: grid;
        width: 100%;
    }
}

@media (prefers-reduced-motion: reduce) {
    .my-room-button {
        transition: none;
    }
}
</style>

<main class="my-room-page">
    <div class="my-room-container">
        <header class="my-room-heading">
            <div>
                <h1>My Room</h1>
                <p>View your assigned room and current accommodation details.</p>
            </div>
            <?php if ($tenant): ?>
                <div class="my-room-heading-status" aria-label="Tenant status">
                    <span class="room-page-status"><?= e($tenantStatus); ?> Tenant</span>
                </div>
            <?php endif; ?>
        </header>

        <?php if (!$tenant || !$hasRoom): ?>
            <section class="my-room-empty" aria-labelledby="no-room-title">
                <h2 id="no-room-title">No room assigned yet</h2>
                <p>Your room details will appear here after management assigns a room to your tenant account.</p>
                <a class="my-room-button" href="tenant_messages.php">Message Admin</a>
            </section>
        <?php else: ?>
            <?php
            $tenantStatusLabel = ucfirst(strtolower((string) $tenant['tenant_status']));
            ?>
            <section class="my-room-hero" aria-labelledby="my-room-title">
                <div class="my-room-photo">
                    <?php if ($roomImageUrl !== null): ?>
                        <img src="<?= e($roomImageUrl); ?>" alt="<?= e($roomTitle . ' room photo'); ?>">
                    <?php else: ?>
                        <div class="my-room-photo-placeholder"><?= e($roomTitle); ?></div>
                    <?php endif; ?>
                    <span class="room-photo-badge"><?= e($roomTitle); ?></span>
                </div>

                <div class="my-room-hero-body">
                    <div class="my-room-title-row">
                        <p class="my-room-eyebrow">MY CURRENT ROOM</p>
                        <span class="my-room-lease-status"><?= e($tenantStatusLabel); ?> Tenant</span>
                    </div>
                    <div class="my-room-title-row">
                        <div>
                            <h2 id="my-room-title"><?= e($roomTitle); ?></h2>
                            <p><?= e($roomType); ?></p>
                            <span class="branch-indicator"><span class="branch-indicator-label">Branch:</span> <?= e($tenant['branch_name'] ?? 'Branch unavailable'); ?></span>
                        </div>
                    </div>

                    <div class="room-stat-grid" aria-label="Room summary">
                        <div class="room-stat">
                            <span class="room-stat-label">Monthly Rate</span>
                            <span class="room-stat-value">₱<?= number_format($monthlyRate, 2); ?> <small>/ month</small></span>
                        </div>
                        <div class="room-stat">
                            <span class="room-stat-label">Room Type</span>
                            <span class="room-stat-value"><?= e($roomType); ?></span>
                        </div>
                        <div class="room-stat">
                            <span class="room-stat-label">Occupancy</span>
                            <span class="room-stat-value"><?= (int) $tenant['current_occupants']; ?>/<?= $capacity; ?> · <?= (int) $tenant['available_slots']; ?> available</span>
                        </div>
                    </div>

                    <div class="my-room-actions">
                        <a class="my-room-button" href="tenant_maintenance.php">Need room service?</a>
                        <a class="my-room-button secondary" href="tenant_payments.php">View Payment History</a>
                    </div>
                </div>
            </section>

            <div class="my-room-columns room-main-columns">
                <section class="my-room-panel" aria-labelledby="room-details-heading">
                    <h2 class="my-room-panel-heading" id="room-details-heading">Room Details</h2>
                    <div class="room-detail-list">
                        <div class="room-detail-item"><span>Room Number</span><strong><?= e($roomTitle); ?></strong></div>
                        <div class="room-detail-item"><span>Room Type</span><strong><?= e($roomType); ?></strong></div>
                        <div class="room-detail-item"><span>Capacity</span><strong><?= $capacity; ?></strong></div>
                        <div class="room-detail-item"><span>Room Status</span><strong><?= e($tenant['room_status']); ?></strong></div>
                        <div class="room-detail-item"><span>Available Slots</span><strong><?= (int) $tenant['available_slots']; ?></strong></div>
                    </div>
                    <div class="room-detail-item">
                        <span>Assigned Tenant</span>
                        <strong><?= e($tenant['full_name']); ?></strong>
                    </div>
                </section>

                <section class="my-room-panel" id="lease-info" aria-labelledby="stay-heading">
                    <h2 class="my-room-panel-heading" id="stay-heading">Stay Information</h2>
                    <div class="stay-list">
                        <div class="stay-row">
                            <span>Tenant Status</span>
                            <strong><span class="my-room-lease-status"><?= e($tenantStatusLabel); ?></span></strong>
                        </div>
                        <div class="stay-row">
                            <span>Move-in Date</span>
                            <strong><?= $moveInDate ? e($moveInDate->format('F j, Y')) : 'Not set'; ?></strong>
                        </div>
                        <?php if ($standardLeaseTerm !== ''): ?>
                            <div class="stay-row">
                                <span>Lease Term</span>
                                <strong><?= e($standardLeaseTerm); ?></strong>
                            </div>
                        <?php endif; ?>
                        <div class="stay-row">
                            <span>Monthly Rental</span>
                            <strong>₱<?= number_format($monthlyRate, 2); ?></strong>
                        </div>
                        <?php if ($standardPaymentTerms !== ''): ?>
                            <div class="stay-row">
                                <span>Standard Rental Terms</span>
                                <strong><?= e($standardPaymentTerms); ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <div class="room-lower-columns">
                <section class="my-room-panel" aria-labelledby="amenities-heading">
                    <h2 class="my-room-panel-heading" id="amenities-heading">Included Amenities</h2>
                    <ul class="room-amenity-grid">
                        <?php foreach ($roomAmenities as $amenity): ?>
                            <li class="room-amenity-tag"><?= e($amenity); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>

                <section class="my-room-panel" aria-labelledby="guidelines-heading">
                    <h2 class="my-room-panel-heading" id="guidelines-heading">House Guidelines</h2>
                    <ul class="rules-list">
                        <?php foreach ($roomPoliciesList as $guideline): ?>
                            <li><?= e($guideline); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            </div>

            <section class="room-support-card" aria-labelledby="room-support-heading">
                <div>
                    <h2 id="room-support-heading">Need help with your room?</h2>
                    <p>Contact management through the tenant portal.</p>
                </div>
                <div class="room-support-actions">
                    <a class="my-room-button secondary" href="tenant_messages.php">Message Admin</a>
                    <a class="my-room-button" href="tenant_maintenance.php">Report a Problem</a>
                </div>
            </section>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
