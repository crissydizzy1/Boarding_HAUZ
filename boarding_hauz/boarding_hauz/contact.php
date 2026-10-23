<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/room_display.php';
$roomPolicies = require __DIR__ . '/includes/room_policies.php';

$errors = [];
$success_message = '';
$roomTypes = [];
$inquiryBranches = [];
$roomTypeQueryError = false;
$inquiryBranchQueryError = false;
$roomSelectionError = false;
$inquiryRoom = null;
$roomSelectionRequested = array_key_exists('room_id', $_GET) || array_key_exists('room_id', $_POST);
$roomSelectionValue = is_scalar($_GET['room_id'] ?? null)
    ? (string) $_GET['room_id']
    : (is_scalar($_POST['room_id'] ?? null) ? (string) $_POST['room_id'] : '');
$inquiryRoomId = ctype_digit($roomSelectionValue) ? (int) $roomSelectionValue : 0;
$form = [
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'room_type' => '',
    'inquiry_type' => 'property',
    'branch_id' => '',
    'message' => '',
];

if (empty($_SESSION['contact_inquiry_csrf'])) {
    $_SESSION['contact_inquiry_csrf'] = bin2hex(random_bytes(32));
}

if ($roomSelectionRequested) {
    if ($inquiryRoomId <= 0) {
        $roomSelectionError = true;
    } else {
        try {
            $stmt = $conn->prepare('SELECT r.id, r.branch_id, r.room_number, r.room_type, r.capacity, r.monthly_rate, r.status, b.branch_name FROM rooms r LEFT JOIN branches b ON b.id = r.branch_id WHERE r.id = ? LIMIT 1');
            $stmt->bind_param('i', $inquiryRoomId);
            $stmt->execute();
            $inquiryRoom = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$inquiryRoom) {
                $roomSelectionError = true;
            } else {
                $form['room_type'] = $inquiryRoom['room_type'];
                $form['branch_id'] = (string) $inquiryRoom['branch_id'];
            }
        } catch (mysqli_sql_exception $exception) {
            error_log('Contact form selected-room lookup failed: ' . $exception->getMessage());
            $roomSelectionError = true;
        }
    }
}

try {
    $branchResult = $conn->query("
        SELECT b.id, b.branch_name, b.location
        FROM branches b
        INNER JOIN users u ON u.id = b.landlord_id
        WHERE u.role = 'landlord' AND u.status = 'active'
        ORDER BY b.branch_name, b.location, b.id
    ");
    $inquiryBranches = $branchResult->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $exception) {
    error_log('Contact form branch options query failed: ' . $exception->getMessage());
    $inquiryBranchQueryError = true;
}

try {
    $roomTypeResult = $conn->query("
        SELECT DISTINCT room_type
        FROM rooms
        WHERE room_type IS NOT NULL AND room_type <> ''
        ORDER BY room_type
    ");
    while ($roomType = $roomTypeResult->fetch_assoc()) {
        $roomTypes[] = $roomType['room_type'];
    }
} catch (mysqli_sql_exception $exception) {
    error_log('Contact form room type query failed: ' . $exception->getMessage());
    $roomTypeQueryError = true;
}

if (!empty($_SESSION['contact_inquiry_success'])) {
    $success_message = (string) $_SESSION['contact_inquiry_success'];
    unset($_SESSION['contact_inquiry_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $field => $value) {
        $form[$field] = is_string($_POST[$field] ?? null)
            ? trim($_POST[$field])
            : '';
    }
    if ($inquiryRoom) {
        $form['room_type'] = $inquiryRoom['room_type'];
        $form['inquiry_type'] = 'property';
        $form['branch_id'] = (string) $inquiryRoom['branch_id'];
    }
    $postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';

    if ($roomSelectionError) {
        $errors[] = 'The selected room could not be verified. Please choose a room again.';
    } elseif (!hash_equals($_SESSION['contact_inquiry_csrf'], $postedToken)) {
        $errors[] = 'Your session could not be verified. Refresh the page and try again.';
    } elseif ($form['full_name'] === '') {
        $errors[] = 'Full name is required.';
    } elseif (strlen($form['full_name']) > 100) {
        $errors[] = 'Full name must be 100 characters or fewer.';
    }

    if ($form['email'] === '' || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    } elseif (strlen($form['email']) > 255) {
        $errors[] = 'Email address must be 255 characters or fewer.';
    }

    if ($form['phone'] === '') {
        $errors[] = 'Contact number is required.';
    } elseif (strlen($form['phone']) > 20) {
        $errors[] = 'Contact number must be 20 characters or fewer.';
    }

    if (!in_array($form['inquiry_type'], ['property', 'platform'], true)) {
        $errors[] = 'Choose whether your inquiry is about a property or the Boarding Hauz platform.';
    }

    $inquiryBranchId = null;
    if ($form['inquiry_type'] === 'property') {
        if ($inquiryRoom) {
            $inquiryBranchId = (int) $inquiryRoom['branch_id'];
        } else {
            foreach ($inquiryBranches as $branch) {
                if ((string) $branch['id'] === $form['branch_id']) {
                    $inquiryBranchId = (int) $branch['id'];
                    break;
                }
            }
            if ($inquiryBranchId === null) {
                $errors[] = $inquiryBranchQueryError
                    ? 'Property choices could not be loaded. Please try again later.'
                    : 'Choose the property this inquiry is about.';
            }
        }
    } else {
        $form['branch_id'] = '';
    }

    if ($form['inquiry_type'] === 'property' && $roomTypes && $form['room_type'] === '') {
        $errors[] = 'Please select a preferred room type.';
    } elseif ($form['inquiry_type'] === 'property' && $form['room_type'] !== ''
        && (!in_array($form['room_type'], $roomTypes, true) || strlen($form['room_type']) > 50)) {
        $errors[] = 'Please select a valid room type.';
    }

    if ($form['message'] === '') {
        $errors[] = 'Please write your inquiry or viewing request.';
    } elseif (strlen($form['message']) > 2000) {
        $errors[] = 'Your message must be 2,000 characters or fewer.';
    }

    if (!$errors) {
        $preferredRoomType = $inquiryRoom
            ? $inquiryRoom['room_type']
            : ($form['inquiry_type'] !== 'property' || $form['room_type'] === '' ? null : $form['room_type']);
        $inquiryRoomId = $inquiryRoom ? (int) $inquiryRoom['id'] : null;
        $inquiryMessage = $form['message'];
        if ($inquiryRoom) {
            $inquiryMessage = sprintf(
                "Room inquiry details\nRoom: %s\nRoom type / details: %s\nPrice: PHP %s / month\n\nGuest message:\n%s",
                roomDisplayTitle($inquiryRoom['room_number']),
                $inquiryRoom['room_type'],
                number_format((float) $inquiryRoom['monthly_rate'], 2),
                $form['message']
            );
        }
        if (!$errors) {
            try {
                $stmt = $conn->prepare("
                INSERT INTO public_inquiries
                    (branch_id, room_id, inquiry_type, full_name, email, contact_number, preferred_room_type, message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    'iissssss',
                    $inquiryBranchId,
                    $inquiryRoomId,
                    $form['inquiry_type'],
                    $form['full_name'],
                    $form['email'],
                    $form['phone'],
                    $preferredRoomType,
                    $inquiryMessage
                );
                $stmt->execute();
                $stmt->close();

                $_SESSION['contact_inquiry_success'] =
                    'Thank you, ' . $form['full_name'] . '. Your inquiry has been received and will be reviewed by the Boarding Hauz team.';
                header('Location: /boarding_hauz/contact.php' . ($inquiryRoom ? '?room_id=' . (int) $inquiryRoom['id'] : ''));
                exit;
            } catch (mysqli_sql_exception $exception) {
                error_log('Public inquiry submission failed: ' . $exception->getMessage());
                $errors[] = 'Your inquiry could not be submitted right now. Please try again later.';
            }
        }
    }
}

if ($roomSelectionRequested && $roomSelectionError && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $errors[] = 'The selected room could not be found. Please choose a room from the room directory.';
}
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<style>
    :root {
        --color-primary: #22b486;
        --color-primary-dark: #1aa27a;
        --color-primary-container: #2ec49b;
        --color-secondary: #236b67;
        --color-secondary-container: #1f7e79;
        --color-background: #edf9fc;
        --color-surface: #ffffff;
        --color-text: #0d1c1e;
        --color-outline: #d7e6ea;
        --color-inverse-surface: #1f4e4d;
        --color-error: #ba1a1a;
        --color-neutral: #122a2c;
        --color-tertiary: #dff3f7;
        --font-heading: 'Playfair Display', Georgia, serif;
        --font-body: 'Inter', 'Segoe UI', sans-serif;
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        background: var(--color-background);
        color: var(--color-text);
        font-family: var(--font-body);
        line-height: 1.6;
    }

    h1, h2, h3, h4 {
        margin: 0;
        font-family: var(--font-heading);
        font-weight: 700;
        line-height: 1.15;
    }

    a {
        text-decoration: none;
        color: inherit;
    }

    .contact-hero {
        background-color: var(--color-inverse-surface);
        padding: 0.2rem 3.2px;
    }

    .contact-hero-inner {
        text-align: center;
        padding-top: 4.5rem;
        padding-bottom: 4.5rem;
    }

    .eyebrow {
        display: inline-block;
        margin-bottom: 1rem;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        font-size: 0.8rem;
        font-weight: 700;
        color: rgba(255,255,255,0.8);
    }

    .contact-hero h1 {
        font-size: clamp(2.4rem, 4vw, 4rem);
        color: #22b486;
    }

    .contact-hero p {
        max-width: 760px;
        margin: 1rem auto 0;
        color: rgba(255,255,255,0.8);
        font-size: 1.12rem;
    }

    .contact-main {
        padding: 0.2;
        background: var(--color-background);
    }

    .contact-grid {
        display: grid;
        grid-template-columns: 0.9fr 1.1fr;
        gap: 1.7rem;
        align-items: start;
    }

    .contact-card,
    .form-card,
    .map-card {
        background: var(--color-surface);
        border: 1px solid rgba(18, 42, 44, 0.08);
        border-radius: 1.25rem;
        box-shadow: 0 18px 40px rgba(14, 30, 34, 0.05);
    }

    .contact-card,
    .form-card {
        padding: 2rem;
    }

    .section-tag {
        display: inline-block;
        margin-bottom: 0.8rem;
        color: var(--color-primary);
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        font-size: 0.72rem;
    }

    .contact-card h2,
    .form-card h2,
    .map-card h2 {
        margin-bottom: 0.5rem;
        font-size: clamp(2rem, 2.8vw, 2.7rem);
        color: var(--color-text);
    }

    .contact-subtitle,
    .form-subtitle,
    .map-subtitle {
        margin: 0 0 1.4rem;
        color: #4f5d63;
        font-size: 1rem;
    }

    .contact-list {
        display: flex;
        flex-direction: column;
        gap: 1.2rem;
        margin-top: 1.3rem;
    }

    .contact-item {
        display: flex;
        align-items: flex-start;
        gap: 0.9rem;
    }

    .contact-icon {
        width: 42px;
        height: 42px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: rgba(34, 180, 134, 0.12);
        color: var(--color-primary-dark);
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    .contact-item strong {
        display: block;
        margin-bottom: 0.2rem;
        color: var(--color-text);
        font-size: 0.84rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .contact-item span,
    .contact-item a {
        color: var(--color-neutral);
        font-size: 0.96rem;
    }

    .contact-note {
        margin-top: 1.6rem;
        background: rgba(34, 180, 134, 0.09);
        border: 1px solid rgba(34, 180, 134, 0.18);
        border-radius: 1rem;
        padding: 1rem 1.1rem;
    }

    .contact-note strong {
        display: block;
        margin-bottom: 0.3rem;
        color: var(--color-secondary);
        font-size: 0.96rem;
    }

    .contact-note p {
        margin: 0;
        color: var(--color-neutral);
        font-size: 0.94rem;
    }

    form {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .field-row {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
    }

    .field {
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
    }

    .property-inquiry-field[hidden] {
        display: none;
    }

    .field label {
        color: var(--color-text);
        font-weight: 600;
        font-size: 0.9rem;
    }

    .field input,
    .field select,
    .field textarea {
        width: 100%;
        border: 1px solid rgba(18, 42, 44, 0.12);
        background: #f3fbfd;
        border-radius: 0.9rem;
        padding: 0.9rem 1rem;
        font: inherit;
        color: var(--color-text);
        outline: none;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .field input:focus,
    .field select:focus,
    .field textarea:focus {
        border-color: var(--color-primary);
        box-shadow: 0 0 0 3px rgba(34, 180, 134, 0.12);
    }

    .field textarea {
        min-height: 140px;
        resize: vertical;
    }

    .btn-primary {
        display: inline-flex;
        justify-content: center;
        align-items: center;
        gap: 0.5rem;
        background: linear-gradient(180deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
        color: #ffffff;
        border: none;
        border-radius: 9999px;
        padding: 1rem 1.5rem;
        font-weight: 700;
        font-size: 0.9rem;
        letter-spacing: 0.04em;
        cursor: pointer;
        transition: all 0.2s ease;
        box-shadow: 0 10px 20px rgba(34, 180, 134, 0.2);
    }

    .btn-primary:hover {
        background: linear-gradient(180deg, var(--color-primary-dark) 0%, var(--color-secondary) 100%);
    }

    .alert {
        border-radius: 0.9rem;
        padding: 0.85rem 1rem;
        font-size: 0.94rem;
        font-weight: 600;
    }

    .alert-error {
        background: rgba(186, 26, 26, 0.08);
        border: 1px solid rgba(186, 26, 26, 0.2);
        color: #7a1919;
    }

    .alert-success {
        background: rgba(34, 180, 134, 0.09);
        border: 1px solid rgba(34, 180, 134, 0.22);
        color: #0b5a41;
    }

    .map-section {
        padding: 0 0 4rem;
        background: var(--color-background);
    }

    .map-card {
        padding: 2rem;
    }

    .map-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
    }

    .map-badge {
        background: rgba(34, 180, 134, 0.12);
        color: var(--color-secondary);
        border: 1px solid rgba(34, 180, 134, 0.12);
        border-radius: 9999px;
        padding: 0.65rem 0.95rem;
        font-size: 0.8rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
    }

    .map-box {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 320px;
        background: linear-gradient(135deg, rgba(34, 180, 134, 0.10), rgba(35, 107, 103, 0.12));
        border: 1px solid rgba(18, 42, 44, 0.08);
        border-radius: 1rem;
        color: var(--color-secondary);
        text-align: center;
        padding: 1.5rem;
        position: relative;
        overflow: hidden;
    }

    .map-box::before {
        content: "";
        position: absolute;
        inset: 0;
        background-image: linear-gradient(rgba(18, 42, 44, 0.05) 1px, transparent 1px), linear-gradient(90deg, rgba(18, 42, 44, 0.05) 1px, transparent 1px);
        background-size: 22px 22px;
    }

    .map-content {
        position: relative;
        z-index: 1;
        max-width: 480px;
    }

    .map-content strong {
        display: block;
        font-size: 1.3rem;
        margin-bottom: 0.6rem;
    }

    .map-content span {
        color: var(--color-neutral);
        font-size: 0.97rem;
    }

    @media (max-width: 900px) {
        .contact-grid { grid-template-columns: 1fr; }
    }

    @media (max-width: 600px) {
        .section-container {
            padding-left: 1rem;
            padding-right: 1rem;
        }

        .field-row { grid-template-columns: 1fr; }

        .contact-card,
        .form-card,
        .map-card {
            padding: 1.3rem;
        }

        .map-header { align-items: flex-start; }

        .btn-primary { width: 100%; }
    }
</style>

<section class="contact-hero">
    <div class="section-container contact-hero-inner">
        <span class="eyebrow">SUPPORT &amp; INQUIRIES</span>
        <h1>GET IN TOUCH WITH US</h1>
        <p>Have questions or want to schedule a viewing? We are here to help you find your next comfortable home.</p>
    </div>
</section>

<main class="contact-main">
    <div class="section-container">
        <div class="contact-grid">
            <aside class="contact-card">
                <span class="section-tag">Contact Details</span>
                <h2>Contact Details</h2>
                <p class="contact-subtitle">Reach out through any of our official channels.</p>

                <div class="contact-list">
                    <div class="contact-item">
                        <div class="contact-icon">📍</div>
                        <div>
                            <strong>Address</strong>
                            <span>South Cotabato, Philippines</span>
                        </div>
                    </div>

                    <div class="contact-item">
                        <div class="contact-icon">☎</div>
                        <div>
                            <strong>Phone</strong>
                            <span>0917-123-4567 / (084) 123-4567</span>
                        </div>
                    </div>

                    <div class="contact-item">
                        <div class="contact-icon">✉</div>
                        <div>
                            <strong>Email</strong>
                            <a href="mailto:admin@boardinghauz.com">admin@boardinghauz.com</a>
                        </div>
                    </div>

                    <div class="contact-item">
                        <div class="contact-icon">◷</div>
                        <div>
                            <strong>Office Hours</strong>
                            <span>Mon – Sat: 8:00 AM – 6:00 PM</span>
                        </div>
                    </div>
                </div>

                <div class="contact-note">
                    <strong>Prefer instant replies?</strong>
                    <p>Send us a direct message on our social media channels or call our mobile contact number for rapid assistance.</p>
                </div>
            </aside>

            <section class="form-card">
                <span class="section-tag">Inquire</span>
                <h2>Send Us a Message</h2>
                <p class="form-subtitle">Fill out the form below and the Boarding Hauz team will review your inquiry.</p>

                <?php if ($inquiryRoom): ?>
                    <section class="inquiry-room-summary" aria-label="Selected room">
                        <div>
                            <span>Selected room</span>
                            <strong><?= e(roomDisplayTitle($inquiryRoom['room_number'])); ?> · <?= e($inquiryRoom['room_type']); ?></strong>
                        </div>
                        <div>
                            <span>Branch</span>
                            <strong><?= e($roomPolicies['branch']); ?></strong>
                        </div>
                        <div>
                            <span>Price</span>
                            <strong>₱<?= number_format((float) $inquiryRoom['monthly_rate'], 2); ?> / month</strong>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error">
                        <?php foreach ($errors as $error): ?>
                            <div><?php echo e($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($success_message !== ''): ?>
                    <div class="alert alert-success"><?php echo e($success_message); ?></div>
                <?php endif; ?>

                <form method="POST" action="contact.php<?= $inquiryRoom ? '?room_id=' . (int) $inquiryRoom['id'] : ''; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['contact_inquiry_csrf']); ?>">
                    <?php if ($inquiryRoom): ?>
                        <input type="hidden" name="room_id" value="<?= (int) $inquiryRoom['id']; ?>">
                        <input type="hidden" name="inquiry_type" value="property">
                        <p class="form-subtitle">Property inquiry for <?= e($inquiryRoom['branch_name'] ?? 'the selected property'); ?> · <?= e(roomDisplayTitle($inquiryRoom['room_number'])); ?></p>
                    <?php else: ?>
                        <div class="field">
                            <label for="inquiryType">What is your inquiry about?</label>
                            <select id="inquiryType" name="inquiry_type" required>
                                <option value="property" <?= $form['inquiry_type'] === 'property' ? 'selected' : ''; ?>>A property or room</option>
                                <option value="platform" <?= $form['inquiry_type'] === 'platform' ? 'selected' : ''; ?>>Boarding Hauz account or platform support</option>
                            </select>
                        </div>
                        <div class="field property-inquiry-field">
                            <label for="inquiryBranch">Property</label>
                            <select id="inquiryBranch" name="branch_id">
                                <option value="">Choose a property</option>
                                <?php foreach ($inquiryBranches as $branch): ?>
                                    <option value="<?= (int) $branch['id']; ?>" <?= $form['branch_id'] === (string) $branch['id'] ? 'selected' : ''; ?>>
                                        <?= e($branch['branch_name'] . ' · ' . $branch['location']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ($inquiryBranchQueryError): ?>
                                    <option value="" disabled>Property choices could not be loaded</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="field-row">
                        <div class="field">
                            <label for="full_name">Full Name</label>
                            <input id="full_name" name="full_name" type="text" maxlength="100" placeholder="e.g. Juan Dela Cruz" required value="<?php echo e($form['full_name']); ?>">
                        </div>

                        <div class="field">
                            <label for="email">Email Address</label>
                            <input id="email" name="email" type="email" maxlength="255" placeholder="e.g. juan@example.com" required value="<?php echo e($form['email']); ?>">
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label for="phone">Contact Number</label>
                            <input id="phone" name="phone" type="tel" maxlength="20" placeholder="e.g. 0917XXXXXXX" required value="<?php echo e($form['phone']); ?>">
                        </div>

                        <?php if ($inquiryRoom): ?>
                            <div class="field">
                                <label for="room_type">Room Type</label>
                                <input id="room_type" type="text" value="<?= e($inquiryRoom['room_type']); ?>" disabled>
                            </div>
                        <?php else: ?>
                            <div class="field property-inquiry-field">
                                <label for="room_type">Preferred Room Type</label>
                                <select id="room_type" name="room_type">
                                    <?php if ($roomTypes): ?>
                                        <option value="">Select a room type</option>
                                        <?php foreach ($roomTypes as $roomType): ?>
                                            <option value="<?php echo e($roomType); ?>" <?php echo $form['room_type'] === $roomType ? 'selected' : ''; ?>><?php echo e($roomType); ?></option>
                                        <?php endforeach; ?>
                                    <?php elseif ($roomTypeQueryError): ?>
                                        <option value="">Room type options could not be loaded</option>
                                    <?php else: ?>
                                        <option value="">No room types currently available</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="message">Message</label>
                        <textarea id="message" name="message" maxlength="2000" placeholder="Write your inquiry or viewing request here..." required><?php echo e($form['message']); ?></textarea>
                    </div>

                    <button type="submit" class="btn-primary">➤ SUBMIT INQUIRY</button>
                </form>
                <?php if (!$inquiryRoom): ?>
                    <script>
                        const inquiryType = document.getElementById('inquiryType');
                        const propertyFields = document.querySelectorAll('.property-inquiry-field');
                        const inquiryBranch = document.getElementById('inquiryBranch');
                        const preferredRoomType = document.getElementById('room_type');
                        const syncInquiryFields = function () {
                            const isPropertyInquiry = inquiryType.value === 'property';
                            propertyFields.forEach(function (field) {
                                field.hidden = !isPropertyInquiry;
                            });
                            inquiryBranch.disabled = !isPropertyInquiry;
                            inquiryBranch.required = isPropertyInquiry;
                            preferredRoomType.disabled = !isPropertyInquiry;
                            preferredRoomType.required = isPropertyInquiry && preferredRoomType.options.length > 1;
                        };
                        inquiryType.addEventListener('change', syncInquiryFields);
                        syncInquiryFields();
                    </script>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>

<section class="map-section">
    <div class="section-container">
        <div class="map-card">
            <div class="map-header">
                <div>
                    <span class="section-tag">Location</span>
                    <h2>Our Locations Across South Cotabato</h2>
                    <p class="map-subtitle">Visit our main leasing office and view available rooms directly.</p>
                </div>
                <span class="map-badge">📍 South Cotabato</span>
            </div>

            <div class="map-box" aria-label="Location map placeholder">
                <div class="map-content">
                    <strong>[ LOCATION MAP ]</strong>
                    <span>📍 | INTERACTIVE MAP EMBED - SURALLAH |</span><br>
                    <span>South Cotabato, Philippines</span>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>