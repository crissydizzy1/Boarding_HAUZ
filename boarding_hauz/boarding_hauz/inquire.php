<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/room_display.php';
require_once __DIR__ . '/includes/room_occupancy.php';

$roomIdValue = is_scalar($_GET['room_id'] ?? null)
    ? (string) $_GET['room_id']
    : (is_scalar($_POST['room_id'] ?? null) ? (string) $_POST['room_id'] : '');
$roomId = ctype_digit($roomIdValue) ? (int) $roomIdValue : 0;
$branchParameterProvided = array_key_exists('branch_id', $_GET) || array_key_exists('branch_id', $_POST);
$branchParameter = is_scalar($_GET['branch_id'] ?? $_POST['branch_id'] ?? null)
    ? (string) ($_GET['branch_id'] ?? $_POST['branch_id'])
    : '';
$requestedBranchId = 0;
$room = null;
$tenantProfile = null;
$roomError = '';
$errors = [];
$success = '';
$form = [
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'target_move_in_date' => '',
    'expected_stay_duration' => '',
    'message' => '',
];
$durationOptions = [
    '1-3 months',
    '3-6 months',
    '6-12 months',
    '1 year or more',
    'Not sure yet',
];

if (empty($_SESSION['room_inquiry_csrf'])) {
    $_SESSION['room_inquiry_csrf'] = bin2hex(random_bytes(32));
}

if ($branchParameterProvided && (!ctype_digit($branchParameter) || (int) $branchParameter <= 0)) {
    $roomError = 'Select a valid branch from the room directory.';
} elseif ($branchParameterProvided) {
    $requestedBranchId = (int) $branchParameter;
}

if ($roomError !== '') {
    $room = null;
} elseif ($roomId <= 0) {
    $roomError = 'Select a valid room from the room directory.';
} else {
    try {
        $stmt = $conn->prepare("
            SELECT r.id, r.branch_id, r.room_number, r.room_type, r.capacity, r.monthly_rate, r.status,
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
        if (!$room) {
            $roomError = 'The selected room could not be found.';
        } elseif ($requestedBranchId > 0 && $requestedBranchId !== (int) $room['branch_id']) {
            $room = null;
            $roomError = 'The selected branch does not match this room.';
        } else {
            $room = attachRoomOccupancy($conn, [$room])[0];
        }
    } catch (mysqli_sql_exception $exception) {
        error_log('Room inquiry room lookup failed: ' . $exception->getMessage());
        $roomError = 'Room details are unavailable right now. Please try again later.';
    }
}

if ($room && !isLoggedIn()) {
    $_SESSION['login_flash'] = 'Please login to send an inquiry for this room.';
    redirectTo('/boarding_hauz/login.php?redirect=' . rawurlencode('inquire.php?room_id=' . $roomId . '&branch_id=' . (int) $room['branch_id']) . '&inquiry=1');
}
if ($room && ($_SESSION['user_role'] ?? '') !== 'tenant') {
    redirectTo(dashboardPathForRole());
}

if ($room && isLoggedIn()) {
    try {
        $userId = (int) $_SESSION['user_id'];
        $stmt = $conn->prepare("
            SELECT t.full_name, t.contact_number, u.email
            FROM tenants t
            INNER JOIN users u ON u.id = t.user_id
            WHERE t.user_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $tenantProfile = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($tenantProfile) {
            $form['full_name'] = $tenantProfile['full_name'];
            $form['email'] = $tenantProfile['email'];
            $form['phone'] = (string) ($tenantProfile['contact_number'] ?? '');
        } else {
            $roomError = 'Your tenant profile could not be found. Please contact the administrator.';
        }
        $postedBranchValue = is_scalar($_POST['branch_id'] ?? null) ? (string) $_POST['branch_id'] : '';
        if ($postedBranchValue !== '' && (!ctype_digit($postedBranchValue) || (int) $postedBranchValue !== (int) $room['branch_id'])) {
            $errors[] = 'The selected branch does not match this room.';
        }
    } catch (mysqli_sql_exception $exception) {
        error_log('Room inquiry tenant profile lookup failed: ' . $exception->getMessage());
        $roomError = 'Your account details could not be loaded. Please try again later.';
    }
}

if (!empty($_SESSION['room_inquiry_success'])) {
    $success = (string) $_SESSION['room_inquiry_success'];
    unset($_SESSION['room_inquiry_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $room && $roomError === '') {
    foreach ($form as $field => $value) {
        $form[$field] = is_string($_POST[$field] ?? null)
            ? trim($_POST[$field])
            : '';
    }
    if ($tenantProfile) {
        $form['full_name'] = $tenantProfile['full_name'];
        $form['email'] = $tenantProfile['email'];
        $form['phone'] = (string) ($tenantProfile['contact_number'] ?? $form['phone']);
    }
    $postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';

    if (!hash_equals($_SESSION['room_inquiry_csrf'], $postedToken)) {
        $errors[] = 'Your session could not be verified. Refresh the page and try again.';
    }
    if ($form['full_name'] === '') {
        $errors[] = 'Full name is required.';
    } elseif (strlen($form['full_name']) > 100) {
        $errors[] = 'Full name must be 100 characters or fewer.';
    }
    if ($form['email'] === '' || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    } elseif (strlen($form['email']) > 255) {
        $errors[] = 'Email address must be 255 characters or fewer.';
    }
    if ($form['phone'] === '') {
        $errors[] = 'Phone number is required.';
    } elseif (strlen($form['phone']) > 20) {
        $errors[] = 'Phone number must be 20 characters or fewer.';
    }

    $moveInDate = DateTimeImmutable::createFromFormat('!Y-m-d', $form['target_move_in_date']);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$moveInDate
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $moveInDate->format('Y-m-d') !== $form['target_move_in_date']
        || $moveInDate < new DateTimeImmutable('today')) {
        $errors[] = 'Choose a valid move-in date today or later.';
    }
    if (!in_array($form['expected_stay_duration'], $durationOptions, true)) {
        $errors[] = 'Select an expected stay duration.';
    }
    if (strlen($form['message']) > 2000) {
        $errors[] = 'Additional notes must be 2,000 characters or fewer.';
    }

    if (!$errors) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO public_inquiries
                    (branch_id, full_name, email, contact_number, preferred_room_type, message, room_id, target_move_in_date, expected_stay_duration)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $preferredRoomType = $room['room_type'];
            $message = $form['message'];
            $inquiryBranchId = (int) $room['branch_id'];
            $stmt->bind_param(
                'isssssiss',
                $inquiryBranchId,
                $form['full_name'],
                $form['email'],
                $form['phone'],
                $preferredRoomType,
                $message,
                $roomId,
                $form['target_move_in_date'],
                $form['expected_stay_duration']
            );
            $stmt->execute();
            $stmt->close();

            $_SESSION['room_inquiry_success'] = 'Inquiry sent successfully! The Boarding Hauz team will review your request.';
            header('Location: /boarding_hauz/inquire.php?room_id=' . $roomId . '&branch_id=' . (int) $room['branch_id'] . '&submitted=1');
            exit;
        } catch (mysqli_sql_exception $exception) {
            error_log('Room inquiry submission failed: ' . $exception->getMessage());
            $errors[] = 'Your inquiry could not be submitted right now. Please try again later.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="room-inquiry-page">
    <div class="room-inquiry-container">
        <a class="room-inquiry-back" href="<?= $room ? 'room-details.php?id=' . (int) $room['id'] . '&amp;branch_id=' . (int) $room['branch_id'] : 'rooms.php'; ?>">← <?= $room ? 'Back to room details' : 'Browse rooms'; ?></a>

        <?php if ($roomError !== ''): ?>
            <section class="room-inquiry-card">
                <div class="room-detail-alert room-detail-alert-error" role="alert"><?= e($roomError); ?></div>
                <a class="public-room-button" href="rooms.php">Browse Available Rooms</a>
            </section>
        <?php else: ?>
            <section class="room-inquiry-card">
                <header class="room-inquiry-card-header">
                    <span class="room-details-eyebrow">ROOM INQUIRY</span>
                    <h1>Inquire for <?= e(roomDisplayTitle($room['room_number'])); ?></h1>
                    <p>Share your move-in plans and our team will follow up with you.</p>
                </header>

                <section class="room-inquiry-selected" aria-label="Selected unit">
                    <div class="room-inquiry-selected-heading">
                        <span class="room-details-eyebrow">SELECTED UNIT</span>
                        <span class="public-room-status public-room-status-<?= e(strtolower(str_replace(' ', '-', $room['occupancy_status']))); ?>"><?= e($room['occupancy_status']); ?></span>
                    </div>
                    <h2><?= e(roomDisplayTitle($room['room_number'])); ?></h2>
                    <p><?= e($room['room_type']); ?> · <?= (int) $room['current_occupants']; ?>/<?= (int) $room['capacity']; ?> occupants · <?= (int) $room['available_slots']; ?> available slots</p>
                    <p><?= e($room['branch_name']); ?> · <?= e($room['branch_location']); ?></p>
                    <strong>₱<?= number_format((float) $room['monthly_rate'], 2); ?> <small>/ month</small></strong>
                </section>

                <?php if ($success !== ''): ?>
                    <div class="room-detail-alert room-detail-alert-success" role="status"><?= e($success); ?></div>
                <?php endif; ?>
                <?php if ($errors): ?>
                    <div class="room-detail-alert room-detail-alert-error" role="alert">
                        <?php foreach ($errors as $error): ?>
                            <div><?= e($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form class="room-inquiry-form" method="post" action="inquire.php?room_id=<?= (int) $room['id']; ?>&amp;branch_id=<?= (int) $room['branch_id']; ?>">
                    <input type="hidden" name="room_id" value="<?= (int) $room['id']; ?>">
                    <input type="hidden" name="branch_id" value="<?= (int) $room['branch_id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['room_inquiry_csrf']); ?>">

                    <div class="room-inquiry-field-grid">
                        <div class="room-inquiry-field">
                            <label for="inquiry-full-name">Full Name</label>
                            <input id="inquiry-full-name" name="full_name" type="text" maxlength="100" autocomplete="name" value="<?= e($form['full_name']); ?>" <?= $tenantProfile ? 'readonly' : ''; ?> required>
                        </div>
                        <div class="room-inquiry-field">
                            <label for="inquiry-email">Email Address</label>
                            <input id="inquiry-email" name="email" type="email" maxlength="255" autocomplete="email" value="<?= e($form['email']); ?>" <?= $tenantProfile ? 'readonly' : ''; ?> required>
                        </div>
                        <div class="room-inquiry-field">
                            <label for="inquiry-phone">Phone Number</label>
                            <input id="inquiry-phone" name="phone" type="tel" maxlength="20" autocomplete="tel" value="<?= e($form['phone']); ?>" required>
                        </div>
                        <div class="room-inquiry-field">
                            <label for="inquiry-move-in">Target Move-in Date</label>
                            <input id="inquiry-move-in" name="target_move_in_date" type="date" min="<?= e(date('Y-m-d')); ?>" value="<?= e($form['target_move_in_date']); ?>" required>
                        </div>
                        <div class="room-inquiry-field room-inquiry-field-full">
                            <label for="inquiry-stay-duration">Expected Stay Duration</label>
                            <select id="inquiry-stay-duration" name="expected_stay_duration" required>
                                <option value="">Select expected stay duration</option>
                                <?php foreach ($durationOptions as $durationOption): ?>
                                    <option value="<?= e($durationOption); ?>" <?= $form['expected_stay_duration'] === $durationOption ? 'selected' : ''; ?>><?= e($durationOption); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="room-inquiry-field room-inquiry-field-full">
                            <label for="inquiry-message">Additional Notes / Message <span>(optional)</span></label>
                            <textarea id="inquiry-message" name="message" maxlength="2000" rows="4" placeholder="Tell us anything else we should know."><?= e($form['message']); ?></textarea>
                        </div>
                    </div>

                    <div class="room-inquiry-actions">
                        <a class="room-inquiry-cancel" href="room-details.php?id=<?= (int) $room['id']; ?>&amp;branch_id=<?= (int) $room['branch_id']; ?>">Cancel</a>
                        <button class="room-inquiry-submit" type="submit">Submit Inquiry</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
