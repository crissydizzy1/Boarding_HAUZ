<?php
require_once __DIR__ . '/../auth.php';
requireRole('landlord');
$branchId = requireSelectedBranch();

$admin_title = 'Property Inquiries';
$error = '';
$success = '';

if (empty($_SESSION['admin_inquiries_csrf'])) {
    $_SESSION['admin_inquiries_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    $inquiryIdValue = is_scalar($_POST['inquiry_id'] ?? null)
        ? (string) $_POST['inquiry_id']
        : '';
    $inquiryId = ctype_digit($inquiryIdValue) ? (int) $inquiryIdValue : 0;

    if (!hash_equals($_SESSION['admin_inquiries_csrf'], $postedToken)) {
        $error = 'Your session could not be verified. Please refresh the page and try again.';
    } elseif ($inquiryId <= 0) {
        $error = 'Select a valid inquiry.';
    } elseif ($action === 'toggle_inquiry_status') {
        $requestedStatus = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        if (!in_array($requestedStatus, ['Unread', 'Read'], true)) {
            $error = 'Select a valid inquiry status.';
        } else {
            try {
                $stmt = $conn->prepare("UPDATE public_inquiries SET status = ? WHERE id = ? AND branch_id = ? AND inquiry_type = 'property' LIMIT 1");
                $stmt->bind_param('sii', $requestedStatus, $inquiryId, $branchId);
                $stmt->execute();
                $changed = $stmt->affected_rows > 0;
                $stmt->close();

                if (!$changed) {
                    $stmt = $conn->prepare("SELECT id FROM public_inquiries WHERE id = ? AND branch_id = ? AND inquiry_type = 'property' LIMIT 1");
                    $stmt->bind_param('ii', $inquiryId, $branchId);
                    $stmt->execute();
                    $exists = (bool) $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$exists) {
                        throw new RuntimeException('The selected inquiry could not be found.');
                    }
                }

                header('Location: /boarding_hauz/landlord/inquiries.php?status_updated=1#inquiry-' . $inquiryId);
                exit;
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            } catch (mysqli_sql_exception $exception) {
                error_log('Public inquiry status update failed: ' . $exception->getMessage());
                $error = 'The inquiry status could not be updated. Please try again.';
            }
        }
    } else {
        $error = 'Invalid inquiry action.';
    }
}

if (isset($_GET['status_updated'])) {
    $success = 'Inquiry status updated.';
}

$publicInquiries = [];
$publicInquiryError = false;

try {
    $stmt = $conn->prepare("
        SELECT
            pi.id,
            pi.full_name,
            pi.email,
            pi.contact_number,
            pi.preferred_room_type,
            pi.message,
            pi.room_id,
            pi.target_move_in_date,
            pi.expected_stay_duration,
            r.room_number AS inquiry_room_number,
            r.room_type AS inquiry_room_type,
            r.monthly_rate AS inquiry_room_rate,
            pi.status,
            pi.created_at
        FROM public_inquiries pi
        LEFT JOIN rooms r ON r.id = pi.room_id AND r.branch_id = pi.branch_id
        WHERE pi.branch_id = ? AND pi.inquiry_type = 'property'
        ORDER BY
            CASE WHEN pi.status = 'Unread' THEN 0 ELSE 1 END,
            pi.created_at DESC,
            pi.id DESC
        LIMIT 100
    ");
    $stmt->bind_param('i', $branchId);
    $stmt->execute();
    $publicInquiries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Landlord property inquiry list failed: ' . $exception->getMessage());
    $publicInquiryError = true;
}

include __DIR__ . '/../admin/admin_header.php';
?>

<style>
    .inquiries-page { max-width: 1100px; margin: 0 auto; padding: 32px 22px 50px; }
    .page-header { margin-bottom: 22px; padding: 24px 28px; border: 1px solid #236b67; border-radius: 16px; background: #0d5b50; }
    .page-header h1 { margin: 0 0 5px; color: #fff; font-family: 'Playfair Display', serif; font-size: 31px; }
    .page-header p { margin: 0; color: #dff3f7; font-size: 13px; }
    .message-alert { margin-bottom: 15px; padding: 11px 14px; border-radius: 8px; font-size: 12px; }
    .message-alert.success { background: #dff4e9; color: #18734f; }
    .message-alert.error { background: #fde5e5; color: #ba1a1a; }
    .public-inquiries { overflow: hidden; margin-bottom: 22px; border: 1px solid #d7e6ea; border-radius: 14px; background: #fff; }
    .inquiries-heading { padding: 18px 20px; border-bottom: 1px solid #d7e6ea; }
    .inquiries-heading h2 { margin: 0; color: #0d1c1e; font-size: 19px; }
    .inquiries-heading p { margin: 5px 0 0; color: #667b7e; font-size: 12px; }
    .inquiry-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; padding: 16px; }
    .inquiry-card { min-width: 0; padding: 16px; border: 1px solid #d7e6ea; border-radius: 10px; }
    .inquiry-card h3 { margin: 0 0 8px; color: #0d1c1e; font-size: 16px; }
    .inquiry-meta { display: grid; gap: 3px; margin-bottom: 11px; overflow-wrap: anywhere; color: #536466; font-size: 12px; }
    .inquiry-message { margin: 0 0 12px; overflow-wrap: anywhere; color: #0d1c1e; font-size: 13px; white-space: pre-wrap; }
    .inquiry-bottom { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; color: #667b7e; font-size: 11px; }
    .inquiry-status { display: inline-block; padding: 4px 9px; border-radius: 999px; background: #fff4d8; color: #806300; font-weight: 700; }
    .inquiry-status.read { background: #e4f7ef; color: #176b59; }
    .inquiry-actions { display: flex; flex-wrap: wrap; gap: 7px; }
    .inquiry-action { padding: 7px 11px; border: 0; border-radius: 6px; background: #22b486; color: #fff; font: 600 12px 'Inter', sans-serif; cursor: pointer; }
    .inquiry-action:hover { background: #1aa27a; }
    .inquiry-empty { padding: 22px; color: #667b7e; font-size: 13px; text-align: center; }
    @media (max-width: 800px) { .inquiry-list { grid-template-columns: 1fr; } }
</style>

<main class="inquiries-page">
    <section class="page-header">
        <h1>Inquiries</h1>
        <p>Review property and room inquiries for this branch. Escalate platform-level issues when they need Admin attention.</p>
    </section>

    <?php if ($success !== ''): ?>
        <div class="message-alert success" role="status"><?= e($success); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="message-alert error" role="alert"><?= e($error); ?></div>
    <?php endif; ?>

    <section class="public-inquiries" id="public-inquiries">
        <div class="inquiries-heading">
            <h2>Public Inquiries</h2>
            <p>Property-related submissions assigned to this branch. Showing the latest 100.</p>
        </div>

        <?php if ($publicInquiryError): ?>
            <p class="inquiry-empty">Public inquiries are currently unavailable. Please try again later.</p>
        <?php elseif (!$publicInquiries): ?>
            <p class="inquiry-empty">No public inquiries have been submitted yet.</p>
        <?php else: ?>
            <div class="inquiry-list">
                <?php foreach ($publicInquiries as $inquiry): ?>
                    <?php $date = strtotime($inquiry['created_at']); ?>
                    <article class="inquiry-card" id="inquiry-<?= (int) $inquiry['id']; ?>">
                        <h3><?= e($inquiry['full_name']); ?></h3>
                        <div class="inquiry-meta">
                            <span><?= e($inquiry['email']); ?></span>
                            <span><?= e($inquiry['contact_number']); ?></span>
                            <span>Preferred Room: <?= e($inquiry['preferred_room_type'] ?: 'Not specified'); ?></span>
                        </div>
                        <?php if ($inquiry['room_id'] !== null || $inquiry['target_move_in_date'] !== null || $inquiry['expected_stay_duration'] !== null): ?>
                            <div class="inquiry-meta">
                                <?php if ($inquiry['room_id'] !== null): ?>
                                    <span>Selected Unit: <?= e($inquiry['inquiry_room_number'] ?: 'Room no longer listed'); ?><?= $inquiry['inquiry_room_type'] ? ' · ' . e($inquiry['inquiry_room_type']) : ''; ?></span>
                                    <?php if ($inquiry['inquiry_room_rate'] !== null): ?>
                                        <span>Listed Rate: ₱<?= number_format((float) $inquiry['inquiry_room_rate'], 2); ?> / month</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($inquiry['target_move_in_date'] !== null): ?>
                                    <span>Target Move-in: <?= e(date('F j, Y', strtotime($inquiry['target_move_in_date']))); ?></span>
                                <?php endif; ?>
                                <?php if ($inquiry['expected_stay_duration'] !== null): ?>
                                    <span>Expected Stay: <?= e($inquiry['expected_stay_duration']); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <p class="inquiry-message"><?= e($inquiry['message']); ?></p>
                        <div class="inquiry-bottom">
                            <span>Submitted: <?= $date ? e(date('F j, Y g:i A', $date)) : e($inquiry['created_at']); ?></span>
                            <span class="inquiry-status <?= $inquiry['status'] === 'Read' ? 'read' : ''; ?>"><?= e($inquiry['status']); ?></span>
                            <div class="inquiry-actions">
                                <form method="post" action="/boarding_hauz/landlord/inquiries.php#inquiry-<?= (int) $inquiry['id']; ?>">
                                    <input type="hidden" name="action" value="toggle_inquiry_status">
                                    <input type="hidden" name="inquiry_id" value="<?= (int) $inquiry['id']; ?>">
                                    <input type="hidden" name="status" value="<?= $inquiry['status'] === 'Read' ? 'Unread' : 'Read'; ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['admin_inquiries_csrf']); ?>">
                                    <button class="inquiry-action" type="submit"><?= $inquiry['status'] === 'Read' ? 'Mark as Unread' : 'Mark as Read'; ?></button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
