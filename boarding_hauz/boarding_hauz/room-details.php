<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/room_images.php';
require_once __DIR__ . '/includes/room_display.php';
require_once __DIR__ . '/includes/room_occupancy.php';
$roomPolicies = require __DIR__ . '/includes/room_policies.php';

$roomIdValue = is_scalar($_GET['id'] ?? null) ? (string) $_GET['id'] : '';
$roomId = ctype_digit($roomIdValue) ? (int) $roomIdValue : 0;
$room = null;
$images = [];
$roomAmenities = [];
$roomGuidelines = [];
$currentTenant = null;
$tenantRequests = [];
$applicationError = '';
$applicationSuccess = false;
$roomQueryError = false;
$branchParameterProvided = array_key_exists('branch_id', $_GET);
$branchParameter = is_scalar($_GET['branch_id'] ?? null) ? (string) $_GET['branch_id'] : '';
$requestedBranchId = 0;
$branchSelectionError = '';
if ($branchParameterProvided) {
    if (!ctype_digit($branchParameter) || (int) $branchParameter <= 0) {
        $branchSelectionError = 'The selected branch is invalid.';
    } else {
        $requestedBranchId = (int) $branchParameter;
    }
}

if (empty($_SESSION['room_application_csrf'])) {
    $_SESSION['room_application_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_for_room') {
    $postedRoomIdValue = is_scalar($_POST['room_id'] ?? null) ? (string) $_POST['room_id'] : '';
    $postedRoomId = ctype_digit($postedRoomIdValue) ? (int) $postedRoomIdValue : 0;
    $postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';

    if ($branchSelectionError !== '') {
        $applicationError = $branchSelectionError;
    } elseif (!hash_equals($_SESSION['room_application_csrf'], $postedToken)) {
        $applicationError = 'Your session could not be verified. Refresh the page and try again.';
    } elseif (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'tenant') {
        $applicationError = 'Sign in with a tenant account to apply for a room.';
    } elseif ($roomId <= 0 || $postedRoomId !== $roomId) {
        $applicationError = 'Select a valid room.';
    } else {
        $conn->begin_transaction();
        try {
            $userId = (int) $_SESSION['user_id'];
            $stmt = $conn->prepare("
                SELECT r.id, r.branch_id, r.capacity, r.status
                FROM rooms r
                INNER JOIN branches b ON b.id = r.branch_id
                INNER JOIN users u ON u.id = b.landlord_id AND u.role = 'landlord' AND u.status = 'active'
                WHERE r.id = ?
                LIMIT 1 FOR UPDATE
            ");
            $stmt->bind_param('i', $roomId);
            $stmt->execute();
            $requestedRoom = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$requestedRoom) {
                throw new RuntimeException('The selected room could not be found.');
            }
            $postedBranchValue = is_scalar($_POST['branch_id'] ?? null) ? (string) $_POST['branch_id'] : '';
            if (($requestedBranchId > 0 && $requestedBranchId !== (int) $requestedRoom['branch_id'])
                || ($postedBranchValue !== '' && (!ctype_digit($postedBranchValue) || (int) $postedBranchValue !== (int) $requestedRoom['branch_id']))) {
                throw new RuntimeException('The selected branch does not match this room.');
            }
            if (in_array($requestedRoom['status'], ['Maintenance', 'Occupied', 'Full'], true)) {
                throw new RuntimeException('This room is not accepting applications.');
            }

            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM tenant_leases WHERE room_id = ? AND status = 'active'");
            $stmt->bind_param('i', $roomId);
            $stmt->execute();
            $currentOccupants = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
            if ($currentOccupants >= (int) $requestedRoom['capacity']) {
                throw new RuntimeException('This room is already at full capacity. No available slots remain.');
            }

            $stmt = $conn->prepare('SELECT id, status FROM tenants WHERE user_id = ? LIMIT 1 FOR UPDATE');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $tenantRecord = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$tenantRecord) {
                throw new RuntimeException('Your tenant profile could not be found.');
            }
            if (in_array($tenantRecord['status'], ['rejected', 'inactive'], true)) {
                throw new RuntimeException('Your tenant account is not eligible to apply for a room.');
            }

            $tenantId = (int) $tenantRecord['id'];
            $stmt = $conn->prepare("
                SELECT id, room_id
                FROM room_requests
                WHERE tenant_id = ? AND room_id = ? AND status = 'Pending'
                LIMIT 1
            ");
            $stmt->bind_param('ii', $tenantId, $roomId);
            $stmt->execute();
            $pendingRequest = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($pendingRequest) {
                throw new RuntimeException('You already have a pending request for this room.');
            }

            $stmt = $conn->prepare("INSERT INTO room_requests (tenant_id, room_id, status) VALUES (?, ?, 'Pending')");
            $stmt->bind_param('ii', $tenantId, $roomId);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            header('Location: /boarding_hauz/room-details.php?id=' . $roomId . '&branch_id=' . (int) $requestedRoom['branch_id'] . '&application=submitted#room-application');
            exit;
        } catch (RuntimeException $exception) {
            $conn->rollback();
            $applicationError = $exception->getMessage();
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            error_log('Room application submission failed: ' . $exception->getMessage());
            $applicationError = 'Your application could not be submitted right now. Please try again.';
        }
    }
}

if ($roomId > 0 && $branchSelectionError === '') {
    try {
        $stmt = $conn->prepare("
            SELECT r.id, r.branch_id, r.room_number, r.room_type, r.capacity, r.monthly_rate, r.status, r.image_path,
                   b.branch_name, b.location AS branch_location
            FROM rooms r
            INNER JOIN branches b ON b.id = r.branch_id
            INNER JOIN users u ON u.id = b.landlord_id AND u.role = 'landlord' AND u.status = 'active'
            WHERE r.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $roomId);
        $stmt->execute();
        $room = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($room && $requestedBranchId > 0 && $requestedBranchId !== (int) $room['branch_id']) {
            $room = null;
            $branchSelectionError = 'The selected branch does not match this room.';
        }
        if ($room) {
            $room = attachRoomOccupancy($conn, [$room])[0];
            $images = getRoomImageUrlsForDisplay((int) $room['id'], $room['image_path']);
            $roomTypeText = strtolower((string) $room['room_type']);
            $amenityKeywords = [
                'wifi' => 'Wi-Fi',
                'wi-fi' => 'Wi-Fi',
                'air conditioning' => 'Air Conditioning',
                'air-conditioned' => 'Air Conditioning',
                'aircon' => 'Air Conditioning',
                'private bathroom' => 'Private Bathroom',
                'shared bathroom' => 'Shared Bathroom',
                'furnished' => 'Furnished',
            ];
            foreach ($amenityKeywords as $keyword => $amenity) {
                if (str_contains($roomTypeText, $keyword) && !in_array($amenity, $roomAmenities, true)) {
                    $roomAmenities[] = $amenity;
                }
            }
            $roomGuidelines = array_values($roomPolicies['rental_terms'] ?? []);
        }
    } catch (mysqli_sql_exception $exception) {
        error_log('Room detail query failed: ' . $exception->getMessage());
        $roomQueryError = true;
    }
}

if ($room && isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'tenant') {
    try {
        $userId = (int) $_SESSION['user_id'];
        $stmt = $conn->prepare('SELECT id, status FROM tenants WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $currentTenant = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($currentTenant) {
            $tenantId = (int) $currentTenant['id'];
            $stmt = $conn->prepare("
                SELECT id, room_id, status, created_at
                FROM room_requests
                WHERE tenant_id = ?
                ORDER BY created_at DESC, id DESC
            ");
            $stmt->bind_param('i', $tenantId);
            $stmt->execute();
            $tenantRequests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($tenantRequests as $request) {
                if ((int) $request['room_id'] === $roomId && $request['status'] === 'Pending') {
                    $applicationSuccess = ($_GET['application'] ?? '') === 'submitted';
                    break;
                }
            }
        }
    } catch (mysqli_sql_exception $exception) {
        error_log('Tenant room request lookup failed: ' . $exception->getMessage());
        $applicationError = 'Your application status could not be loaded right now.';
    }
}

$latestTenantRequest = null;
foreach ($tenantRequests as $request) {
    if ((int) $request['room_id'] === $roomId) {
        $latestTenantRequest = $request;
        break;
    }
}
$statusClass = $room ? strtolower(str_replace(' ', '-', (string) $room['occupancy_status'])) : '';
$requestStatusClass = [
    'Pending' => 'pending',
    'Approved' => 'approved',
    'Rejected' => 'rejected',
][$latestTenantRequest['status'] ?? ''] ?? 'pending';

require_once __DIR__ . '/includes/header.php';
?>

<main class="room-details-page">
    <div class="section-container">
        <p class="room-details-breadcrumb"><a href="<?= $requestedBranchId > 0 ? 'rooms.php?branch_id=' . $requestedBranchId : 'rooms.php'; ?>">Room Directory</a> / Room Details</p>

        <?php if ($roomQueryError): ?>
            <div class="room-detail-alert room-detail-alert-error" role="alert">Room details could not be loaded. Please try again later.</div>
        <?php elseif (!$room): ?>
            <section class="room-detail-alert room-detail-alert-error" role="alert">
                <?= e($branchSelectionError !== '' ? $branchSelectionError : 'The selected room could not be found.'); ?>
                <a href="<?= $requestedBranchId > 0 ? 'rooms.php?branch_id=' . $requestedBranchId : 'rooms.php'; ?>">Return to the room directory.</a>
            </section>
        <?php else: ?>
            <section class="room-details-card" aria-labelledby="room-title">
                <div class="room-details-gallery">
                    <?php if ($images): ?>
                        <div class="room-details-main-image">
                            <img id="room-main-image" src="<?= e($images[0]); ?>" alt="<?= e(roomDisplayTitle($room['room_number'])); ?> photo 1">
                        </div>
                        <?php if (count($images) > 1): ?>
                            <div class="room-details-thumbnails" aria-label="More photos of <?= e(roomDisplayTitle($room['room_number'])); ?>">
                                <?php foreach ($images as $imageIndex => $imageUrl): ?>
                                    <button type="button" class="room-details-thumbnail<?= $imageIndex === 0 ? ' is-active' : ''; ?>" data-image="<?= e($imageUrl); ?>" data-alt="<?= e(roomDisplayTitle($room['room_number'])); ?> photo <?= $imageIndex + 1; ?>" aria-label="Show room photo <?= $imageIndex + 1; ?>" aria-pressed="<?= $imageIndex === 0 ? 'true' : 'false'; ?>">
                                        <img src="<?= e($imageUrl); ?>" alt="" loading="lazy">
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="room-details-main-image room-detail-image-placeholder"><?= e(roomDisplayTitle($room['room_number'])); ?></div>
                    <?php endif; ?>
                </div>

                <div class="room-details-body">
                    <div class="room-details-heading">
                        <div>
                            <span class="room-details-eyebrow"><?= e($room['room_type']); ?></span>
                            <h1 id="room-title"><?= e(roomDisplayTitle($room['room_number'])); ?></h1>
                            <p class="room-details-branch"><?= e($room['branch_name']); ?> · <?= e($room['branch_location']); ?></p>
                        </div>
                        <div class="room-details-badges">
                            <span class="public-room-status public-room-status-<?= e($statusClass); ?>"><?= e($room['occupancy_status']); ?></span>
                            <span class="room-details-capacity"><?= (int) $room['capacity']; ?> <?= (int) $room['capacity'] === 1 ? 'person' : 'persons'; ?></span>
                        </div>
                    </div>

                    <p class="room-details-price">₱<?= number_format((float) $room['monthly_rate'], 2); ?> <small>/ month</small></p>

                    <div class="room-details-information">
                        <section class="room-information-panel" aria-labelledby="room-specifications">
                            <h2 id="room-specifications">Room Specifications &amp; Amenities</h2>
                            <dl class="room-specification-list">
                                <div><dt>Room number</dt><dd><?= e($room['room_number']); ?></dd></div>
                                <div><dt>Room type</dt><dd><?= e($room['room_type']); ?></dd></div>
                                <div><dt>Capacity</dt><dd><?= (int) $room['capacity']; ?> <?= (int) $room['capacity'] === 1 ? 'person' : 'persons'; ?></dd></div>
                                <div><dt>Current occupants</dt><dd><?= (int) $room['current_occupants']; ?>/<?= (int) $room['capacity']; ?></dd></div>
                                <div><dt>Available slots</dt><dd><?= (int) $room['available_slots']; ?></dd></div>
                                <div><dt>Amenities</dt><dd><?= $roomAmenities ? e(implode(', ', $roomAmenities)) : 'No amenities have been listed for this room yet.'; ?></dd></div>
                            </dl>
                        </section>

                        <section class="room-information-panel" aria-labelledby="room-house-rules">
                            <h2 id="room-house-rules">House Rules &amp; Rental Terms</h2>
                            <?php if ($roomGuidelines): ?>
                                <dl class="room-specification-list"><?php foreach ($roomGuidelines as $guideline): ?><div><dt>Guideline</dt><dd><?= e($guideline); ?></dd></div><?php endforeach; ?></dl>
                            <?php else: ?>
                                <p class="room-policy-note">Rental terms are not currently available.</p>
                            <?php endif; ?>
                        </section>
                    </div>

                    <?php if ($applicationError !== ''): ?>
                        <div class="room-detail-alert room-detail-alert-error" role="alert"><?= e($applicationError); ?></div>
                    <?php endif; ?>
                    <?php if ($applicationSuccess): ?>
                        <div class="room-detail-alert room-detail-alert-success" role="status">Your room application has been submitted. Its status is Pending.</div>
                    <?php endif; ?>
                    <?php if ($latestTenantRequest): ?>
                        <div class="room-application-status">
                            <strong>Your application status:</strong>
                            <span class="application-status-badge application-status-<?= e($requestStatusClass); ?>"><?= e($latestTenantRequest['status']); ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="room-details-actions">
                        <?php if (!$room['accepts_tenants']): ?>
                            <span class="public-room-button public-room-button-disabled room-inquiry-button" aria-disabled="true"><?= $room['occupancy_status'] === 'Fully Occupied' ? 'Fully Occupied' : 'Unavailable'; ?></span>
                        <?php elseif (isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'tenant'): ?>
                            <a class="public-room-button room-inquiry-button" href="inquire.php?room_id=<?= (int) $room['id']; ?>&amp;branch_id=<?= (int) $room['branch_id']; ?>">Send Inquiry for this Room</a>
                        <?php elseif (!isLoggedIn()): ?>
                            <?php $inquiryRedirect = 'inquire.php?room_id=' . (int) $room['id'] . '&branch_id=' . (int) $room['branch_id']; ?>
                            <a class="public-room-button room-inquiry-button" href="login.php?redirect=<?= rawurlencode($inquiryRedirect); ?>&amp;inquiry=1">Send Inquiry for this Room</a>
                        <?php else: ?>
                            <span class="room-application-note">Sign in with a tenant account to send an inquiry for this room.</span>
                        <?php endif; ?>
                        <?php if ($room['accepts_tenants']): ?>
                            <div class="room-application-panel" id="room-application">
                                <?php if (!isLoggedIn()): ?>
                                    <span>Already a tenant? Apply using your account.</span>
                                    <div class="room-login-actions">
                                        <a class="public-room-button" href="login.php?return_room=<?= (int) $room['id']; ?>&amp;return_branch=<?= (int) $room['branch_id']; ?>">Login</a>
                                        <a class="public-room-button room-login-secondary" href="register.php?return_room=<?= (int) $room['id']; ?>&amp;return_branch=<?= (int) $room['branch_id']; ?>">Create Account</a>
                                    </div>
                                <?php elseif (($_SESSION['user_role'] ?? '') !== 'tenant'): ?>
                                    <span>Room applications are available to tenant accounts.</span>
                                <?php elseif (!$currentTenant): ?>
                                    <span>Your tenant profile could not be found. Please contact the administrator.</span>
                                <?php elseif (in_array($currentTenant['status'], ['rejected', 'inactive'], true)): ?>
                                    <span>Your tenant account is not eligible to apply for a room.</span>
                                <?php elseif ($latestTenantRequest && $latestTenantRequest['status'] === 'Pending'): ?>
                                    <span>You already have a pending request for this room.</span>
                                <?php else: ?>
                                    <form method="post" action="room-details.php?id=<?= (int) $room['id']; ?>&amp;branch_id=<?= (int) $room['branch_id']; ?>#room-application" class="room-application-form">
                                        <input type="hidden" name="action" value="apply_for_room">
                                        <input type="hidden" name="room_id" value="<?= (int) $room['id']; ?>">
                                        <input type="hidden" name="branch_id" value="<?= (int) $room['branch_id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['room_application_csrf']); ?>">
                                        <button class="room-application-button" type="submit">Apply for this Room</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script>
document.querySelectorAll('.room-details-thumbnail').forEach(function (thumbnail) {
    thumbnail.addEventListener('click', function () {
        var mainImage = document.getElementById('room-main-image');
        if (!mainImage) {
            return;
        }
        mainImage.src = thumbnail.dataset.image;
        mainImage.alt = thumbnail.dataset.alt;
        document.querySelectorAll('.room-details-thumbnail').forEach(function (item) {
            var active = item === thumbnail;
            item.classList.toggle('is-active', active);
            item.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    });
});
</script>
