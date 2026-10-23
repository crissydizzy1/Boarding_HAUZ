<?php
require_once __DIR__ . '/../auth.php';
requireRole('landlord');

$admin_title = 'Payment Management';
$branchId = requireSelectedBranch();
$adminId = currentBranchLandlordId();
$paymentStatuses = ['Pending Verification', 'Approved', 'Rejected', 'Pending', 'Verified', 'Paid', 'Overdue'];
$reviewStatuses = ['Approved', 'Rejected'];
$error = '';
$successMessages = [
    'reviewed' => 'Payment proof was reviewed successfully.',
    'channels_saved' => 'Your payment channel details were saved.',
];
$successKey = is_string($_GET['success'] ?? null) ? $_GET['success'] : '';
$success = $successMessages[$successKey] ?? '';
$search = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
$search = substr($search, 0, 100);
$filterStatus = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if (!in_array($filterStatus, $paymentStatuses, true)) {
    $filterStatus = '';
}
$viewIdValue = is_scalar($_GET['view'] ?? null) ? (string) $_GET['view'] : '';
$viewId = ctype_digit($viewIdValue) ? (int) $viewIdValue : 0;

if (empty($_SESSION['admin_payments_csrf'])) {
    $_SESSION['admin_payments_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedAction = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'review_payment';
    $postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    if ($postedAction === 'save_payment_channels') {
        $channelValues = [
            'gcash_account_name' => trim(is_string($_POST['gcash_account_name'] ?? null) ? $_POST['gcash_account_name'] : ''),
            'gcash_number' => trim(is_string($_POST['gcash_number'] ?? null) ? $_POST['gcash_number'] : ''),
            'maya_account_name' => trim(is_string($_POST['maya_account_name'] ?? null) ? $_POST['maya_account_name'] : ''),
            'maya_number' => trim(is_string($_POST['maya_number'] ?? null) ? $_POST['maya_number'] : ''),
            'bank_name' => trim(is_string($_POST['bank_name'] ?? null) ? $_POST['bank_name'] : ''),
            'bank_account_name' => trim(is_string($_POST['bank_account_name'] ?? null) ? $_POST['bank_account_name'] : ''),
            'bank_account_number' => trim(is_string($_POST['bank_account_number'] ?? null) ? $_POST['bank_account_number'] : ''),
        ];
        $channelLengths = [
            'gcash_account_name' => 100,
            'gcash_number' => 32,
            'maya_account_name' => 100,
            'maya_number' => 32,
            'bank_name' => 80,
            'bank_account_name' => 100,
            'bank_account_number' => 40,
        ];
        $channelPairsComplete = static function (string $name, string $number) use ($channelValues): bool {
            return ($channelValues[$name] === '') === ($channelValues[$number] === '');
        };

        if (!hash_equals($_SESSION['admin_payments_csrf'], $postedToken)) {
            $error = 'Your session could not be verified. Refresh the page and try again.';
        } elseif (!$channelPairsComplete('gcash_account_name', 'gcash_number')
            || !$channelPairsComplete('maya_account_name', 'maya_number')
            || !$channelPairsComplete('bank_account_name', 'bank_account_number')) {
            $error = 'Provide both the account name and account number for each channel, or leave both fields blank.';
        } else {
            foreach ($channelValues as $field => $value) {
                if (strlen($value) > $channelLengths[$field]) {
                    $error = 'Payment channel details exceed the allowed field length.';
                    break;
                }
                $channelValues[$field] = $value === '' ? null : $value;
            }
        }

        if ($error === '') {
            try {
                $stmt = $conn->prepare("
                    UPDATE users
                    SET gcash_account_name = ?, gcash_number = ?,
                        maya_account_name = ?, maya_number = ?,
                        bank_name = ?, bank_account_name = ?, bank_account_number = ?
                    WHERE id = ? AND role = 'landlord'
                    LIMIT 1
                ");
                $stmt->bind_param(
                    'sssssssi',
                    $channelValues['gcash_account_name'],
                    $channelValues['gcash_number'],
                    $channelValues['maya_account_name'],
                    $channelValues['maya_number'],
                    $channelValues['bank_name'],
                    $channelValues['bank_account_name'],
                    $channelValues['bank_account_number'],
                    $adminId
                );
                $stmt->execute();
                $saved = $stmt->affected_rows >= 0;
                $stmt->close();
                if (!$saved) {
                    $error = 'Payment channel details could not be saved.';
                } else {
                    header('Location: /boarding_hauz/landlord/payments.php?success=channels_saved');
                    exit;
                }
            } catch (mysqli_sql_exception $exception) {
                error_log('Payment channel settings update failed: ' . $exception->getMessage());
                $error = 'Payment channel details could not be saved. Confirm the landlord payment channel migration is installed.';
            }
        }
    } else {
    $paymentIdValue = is_scalar($_POST['payment_id'] ?? null) ? (string) $_POST['payment_id'] : '';
    $paymentId = ctype_digit($paymentIdValue) ? (int) $paymentIdValue : 0;
    $newStatus = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
    $postSearch = is_string($_POST['search'] ?? null) ? substr(trim($_POST['search']), 0, 100) : '';
    $postStatus = is_string($_POST['filter_status'] ?? null) ? $_POST['filter_status'] : '';
    $redirectParameters = [];
    if ($postSearch !== '') {
        $redirectParameters['search'] = $postSearch;
    }
    if (in_array($postStatus, $paymentStatuses, true)) {
        $redirectParameters['status'] = $postStatus;
    }

    if (!hash_equals($_SESSION['admin_payments_csrf'], $postedToken)) {
        $error = 'Your session could not be verified. Refresh the page and try again.';
    } elseif ($paymentId <= 0 || !in_array($newStatus, $reviewStatuses, true)) {
        $error = 'Select a valid payment and review status.';
    } else {
        $transactionStarted = false;
        try {
            $conn->begin_transaction();
            $transactionStarted = true;
            $stmt = $conn->prepare("
                SELECT p.id, p.lease_id, p.tenant_id, p.status, l.id AS matching_lease_id
                FROM payments p
                LEFT JOIN tenant_leases l ON l.id = p.lease_id AND l.tenant_id = p.tenant_id
                WHERE p.id = ? AND p.branch_id = ?
                LIMIT 1 FOR UPDATE
            ");
            $stmt->bind_param('ii', $paymentId, $branchId);
            $stmt->execute();
            $existingPayment = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$existingPayment) {
                throw new RuntimeException('The selected payment could not be found.');
            }
            if (!in_array($existingPayment['status'], ['Pending Verification', 'Pending'], true)) {
                throw new RuntimeException('Only payments awaiting verification can be reviewed.');
            }
            if (empty($existingPayment['matching_lease_id'])) {
                throw new RuntimeException('This payment is not linked to a valid rental and cannot be approved.');
            }

            $oldStatus = (string) $existingPayment['status'];
            $stmt = $conn->prepare("
                UPDATE payments
                SET status = ?
                WHERE id = ? AND branch_id = ? AND status = ?
                LIMIT 1
            ");
            $stmt->bind_param('siis', $newStatus, $paymentId, $branchId, $oldStatus);
            $stmt->execute();
            $updated = $stmt->affected_rows === 1;
            $stmt->close();
            if (!$updated) {
                throw new RuntimeException('The payment status changed before this review could be saved.');
            }

            if ($newStatus === 'Approved') {
                $leaseId = (int) $existingPayment['lease_id'];
                $stmt = $conn->prepare("
                    UPDATE tenant_leases l
                    INNER JOIN payments p ON p.lease_id = l.id AND p.tenant_id = l.tenant_id
                    SET l.paid_until = DATE_SUB(
                        DATE_ADD(
                            GREATEST(
                                COALESCE(l.paid_until, DATE_SUB(COALESCE(l.move_in_date, p.payment_date), INTERVAL 1 DAY)),
                                p.payment_date
                            ),
                            INTERVAL 1 MONTH
                        ),
                        INTERVAL 1 DAY
                    )
                    WHERE p.id = ? AND p.lease_id = ?
                ");
                $stmt->bind_param('ii', $paymentId, $leaseId);
                $stmt->execute();
                $paidUntilUpdated = $stmt->affected_rows === 1;
                $stmt->close();
                if (!$paidUntilUpdated) {
                    throw new RuntimeException('The rental paid-through date could not be updated; payment approval was cancelled.');
                }
            }

            $conn->commit();
            $transactionStarted = false;
            $redirectParameters['success'] = 'reviewed';
            $redirectUrl = '/boarding_hauz/landlord/payments.php';
            if ($redirectParameters) {
                $redirectUrl .= '?' . http_build_query($redirectParameters);
            }
            header('Location: ' . $redirectUrl);
            exit;
        } catch (mysqli_sql_exception $exception) {
            if ($transactionStarted) {
                $conn->rollback();
            }
            error_log('Payment review update failed: ' . $exception->getMessage());
            $error = 'The payment status could not be updated. Please try again.';
        } catch (RuntimeException $exception) {
            if ($transactionStarted) {
                $conn->rollback();
            }
            $error = $exception->getMessage();
        }
    }
    }
}

$summary = ['total' => 0, 'pending' => 0, 'verified' => 0, 'rejected' => 0];
$payments = [];
$viewPayment = null;
$channelSettings = [
    'gcash_account_name' => '',
    'gcash_number' => '',
    'maya_account_name' => '',
    'maya_number' => '',
    'bank_name' => '',
    'bank_account_name' => '',
    'bank_account_number' => '',
];

try {
    $summaryStmt = $conn->prepare("
        SELECT COUNT(*) AS total,
            COALESCE(SUM(pay.status IN ('Pending Verification', 'Pending')), 0) AS pending,
            COALESCE(SUM(pay.status IN ('Approved', 'Verified', 'Paid')), 0) AS verified,
            COALESCE(SUM(pay.status = 'Rejected'), 0) AS rejected
        FROM payments pay
        WHERE pay.branch_id = ?
    ");
    $summaryStmt->bind_param('i', $branchId);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc() ?: $summary;
    $summaryStmt->close();

    $settingsStmt = $conn->prepare("
        SELECT gcash_account_name, gcash_number, maya_account_name, maya_number,
               bank_name, bank_account_name, bank_account_number
        FROM users
        WHERE id = ? AND role = 'landlord'
        LIMIT 1
    ");
    $settingsStmt->bind_param('i', $adminId);
    $settingsStmt->execute();
    $channelSettings = $settingsStmt->get_result()->fetch_assoc() ?: $channelSettings;
    $settingsStmt->close();

    $searchLike = '%' . $search . '%';
    $listStmt = $conn->prepare("
        SELECT p.id, p.lease_id, p.amount_paid, p.payment_date, p.payment_method,
               p.reference_number, p.proof_path, p.status,
               t.full_name, l.room_id, u.username, r.room_number
        FROM payments p
        LEFT JOIN tenants t ON t.id = p.tenant_id
        LEFT JOIN tenant_leases l ON l.id = p.lease_id AND l.tenant_id = p.tenant_id
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN rooms r ON r.id = l.room_id AND r.branch_id = p.branch_id
        WHERE p.branch_id = ?
          AND (? = '' OR t.full_name LIKE ? OR u.username LIKE ? OR r.room_number LIKE ?)
          AND (? = '' OR p.status = ?)
        ORDER BY p.payment_date DESC, p.id DESC
    ");
    $listStmt->bind_param(
        'issssss',
        $branchId,
        $search,
        $searchLike,
        $searchLike,
        $searchLike,
        $filterStatus,
        $filterStatus
    );
    $listStmt->execute();
    $payments = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $listStmt->close();

    if ($viewId > 0) {
        $viewStmt = $conn->prepare("
            SELECT p.id, p.lease_id, p.amount_paid, p.payment_date, p.payment_method,
                   p.reference_number, p.proof_path, p.status,
                   t.full_name, l.room_id, u.username, r.room_number
            FROM payments p
            LEFT JOIN tenants t ON t.id = p.tenant_id
            LEFT JOIN tenant_leases l ON l.id = p.lease_id AND l.tenant_id = p.tenant_id
            LEFT JOIN users u ON u.id = t.user_id
            LEFT JOIN rooms r ON r.id = l.room_id AND r.branch_id = p.branch_id
            WHERE p.id = ? AND p.branch_id = ?
            LIMIT 1
        ");
        $viewStmt->bind_param('ii', $viewId, $branchId);
        $viewStmt->execute();
        $viewPayment = $viewStmt->get_result()->fetch_assoc();
        $viewStmt->close();
        if (!$viewPayment) {
            $error = 'The selected payment could not be found.';
        }
    }
} catch (mysqli_sql_exception $exception) {
    error_log('Payment management query failed: ' . $exception->getMessage());
    $error = 'Payment records could not be loaded. Please try again later.';
}

include __DIR__ . '/../admin/admin_header.php';
?>

<style>
.payments-admin-page{max-width:1200px;margin:0 auto;padding:32px 24px 56px}
.payments-heading{margin-bottom:22px;padding:24px 28px;border:1px solid #236b67;border-radius:16px;background:#0d5b50}.payments-heading h1{margin:0 0 5px;color:#fff;font:700 31px 'Playfair Display',serif}
.payments-heading p{margin:0;color:#dff3f7;font-size:13px}
.payment-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:22px}
.payment-stat,.payment-panel{background:#fff;border:1px solid #d7e6ea;border-radius:8px}
.payment-stat{padding:17px}.payment-stat span{display:block;color:#536466;font-size:12px;font-weight:700}
.payment-stat strong{display:block;margin-top:8px;color:#0d1c1e;font-size:27px}
.payment-panel{padding:20px;margin-bottom:18px}.payment-panel h2{margin:0 0 14px;color:#0d1c1e;font:700 21px 'Playfair Display',serif}
.payment-alert{margin-bottom:16px;padding:11px 14px;border-radius:7px;font-size:13px}
.payment-alert.success{background:#e4f7ef;color:#176b59}.payment-alert.error{background:#fff0f0;color:#8a1c1c}
.payment-filters{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:16px}
.payment-filters input,.payment-filters select{min-height:40px;padding:8px 12px;border:1px solid #d7e6ea;border-radius:6px;font:13px 'Inter',sans-serif}
.payment-filters input{flex:1 1 240px}.payment-filters select{flex:0 1 190px}
.payment-button{display:inline-flex;align-items:center;justify-content:center;min-height:37px;padding:8px 12px;border:0;border-radius:6px;background:#22b486;color:#fff;text-decoration:none;font:700 12px 'Inter',sans-serif;cursor:pointer}
.payment-button:hover{background:#1aa27a}.payment-button.secondary{background:#fff;color:#236b67;border:1px solid #d7e6ea}
.payment-button.reject{background:#ba1a1a}.payment-button.reject:hover{background:#941616}
.payment-table-wrap{overflow-x:auto;border:1px solid #d7e6ea;border-radius:6px}
.payment-table{width:100%;min-width:850px;border-collapse:collapse;text-align:left}
.payment-table th,.payment-table td{padding:12px 13px;border-bottom:1px solid #d7e6ea;font-size:12px}
.payment-table th{background:#edf9fc;color:#236b67;font-weight:700}.payment-table td{color:#536466}
.payment-table tr:last-child td{border-bottom:0}.payment-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.payment-status{display:inline-block;padding:4px 8px;border-radius:999px;background:#edf9fc;color:#236b67;font-size:11px;font-weight:700}
.payment-status.pending{background:#fff4d8;color:#806300}.payment-status.verified{background:#e4f7ef;color:#176b59}
.payment-status.rejected{background:#fff0f0;color:#ba1a1a}
.payment-review-form{display:inline-flex;gap:5px}.payment-review-form input,.payment-review-form select{display:none}
.payment-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.payment-detail{padding:12px;border:1px solid #d7e6ea;border-radius:6px}.payment-detail span{display:block;color:#667b7e;font-size:11px}
.payment-detail strong{display:block;margin-top:5px;color:#0d1c1e;font-size:14px;overflow-wrap:anywhere}
.payment-empty{padding:24px;text-align:center;color:#667b7e;font-size:13px}
@media(max-width:760px){.payment-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.payments-admin-page{padding:24px 15px 42px}.payment-panel{padding:15px}.payment-detail-grid{grid-template-columns:1fr}}
</style>

<main class="payments-admin-page">
    <section class="payments-heading">
        <h1>Payment Management</h1>
        <p>Review tenant-submitted payment proofs for this branch.</p>
    </section>

    <?php if ($success !== ''): ?><div class="payment-alert success"><?= e($success); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="payment-alert error"><?= e($error); ?></div><?php endif; ?>

    <section class="payment-panel" aria-labelledby="payment-channels-heading">
        <h2 id="payment-channels-heading">Your Tenant Payment Channels</h2>
        <p style="margin:0 0 16px;color:#536466;font-size:13px">
            These account details are shown only to tenants assigned to rooms owned by your admin account. Leave a channel blank to hide it.
        </p>
        <form method="post" action="payments.php">
            <input type="hidden" name="action" value="save_payment_channels">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['admin_payments_csrf']); ?>">
            <div class="payment-detail-grid">
                <label class="payment-detail">GCash account name
                    <input name="gcash_account_name" maxlength="100" value="<?= e($channelSettings['gcash_account_name'] ?? ''); ?>" autocomplete="off">
                </label>
                <label class="payment-detail">GCash number
                    <input name="gcash_number" maxlength="32" value="<?= e($channelSettings['gcash_number'] ?? ''); ?>" autocomplete="off">
                </label>
                <label class="payment-detail">Maya account name
                    <input name="maya_account_name" maxlength="100" value="<?= e($channelSettings['maya_account_name'] ?? ''); ?>" autocomplete="off">
                </label>
                <label class="payment-detail">Maya number
                    <input name="maya_number" maxlength="32" value="<?= e($channelSettings['maya_number'] ?? ''); ?>" autocomplete="off">
                </label>
                <label class="payment-detail">Bank name
                    <input name="bank_name" maxlength="80" value="<?= e($channelSettings['bank_name'] ?? ''); ?>" autocomplete="off">
                </label>
                <label class="payment-detail">Bank account name
                    <input name="bank_account_name" maxlength="100" value="<?= e($channelSettings['bank_account_name'] ?? ''); ?>" autocomplete="off">
                </label>
                <label class="payment-detail">Bank account number
                    <input name="bank_account_number" maxlength="40" value="<?= e($channelSettings['bank_account_number'] ?? ''); ?>" autocomplete="off">
                </label>
            </div>
            <button class="payment-button" style="margin-top:14px" type="submit">Save payment channels</button>
        </form>
    </section>

    <section class="payment-summary" aria-label="Payment summary">
        <article class="payment-stat"><span>Total Payments</span><strong><?= (int) $summary['total']; ?></strong></article>
        <article class="payment-stat"><span>Pending Verification</span><strong><?= (int) $summary['pending']; ?></strong></article>
        <article class="payment-stat"><span>Approved Payments</span><strong><?= (int) $summary['verified']; ?></strong></article>
        <article class="payment-stat"><span>Rejected Payments</span><strong><?= (int) $summary['rejected']; ?></strong></article>
    </section>

    <?php if ($viewPayment): ?>
        <section class="payment-panel">
            <h2>Payment Details</h2>
            <div class="payment-detail-grid">
                <div class="payment-detail"><span>Tenant</span><strong><?= e($viewPayment['full_name'] ?: $viewPayment['username'] ?: 'Tenant record unavailable'); ?></strong></div>
                <div class="payment-detail"><span>Assigned Room</span><strong><?= $viewPayment['room_number'] !== null ? 'Room ' . e($viewPayment['room_number']) : 'No room assigned'; ?></strong></div>
                <div class="payment-detail"><span>Amount</span><strong>₱<?= number_format((float) $viewPayment['amount_paid'], 2); ?></strong></div>
                <div class="payment-detail"><span>Payment Date</span><strong><?= e($viewPayment['payment_date']); ?></strong></div>
                <div class="payment-detail"><span>Payment Method</span><strong><?= e($viewPayment['payment_method'] ?? 'Not specified'); ?></strong></div>
                <div class="payment-detail"><span>Reference Number</span><strong><?= e($viewPayment['reference_number'] ?: 'Not provided'); ?></strong></div>
                <div class="payment-detail"><span>Status</span><strong><?= e($viewPayment['status']); ?></strong></div>
            </div>
            <?php if (!empty($viewPayment['proof_path'])): ?>
                <p style="margin:14px 0 0"><a class="payment-button secondary" href="payment-proof.php?id=<?= (int) $viewPayment['id']; ?>">View payment proof</a></p>
            <?php else: ?>
                <p style="margin:14px 0 0;color:#667b7e;font-size:12px">No proof image was attached to this payment.</p>
            <?php endif; ?>
            <p style="margin:14px 0 0"><a class="payment-button secondary" href="payments.php">Back to Payments</a></p>
        </section>
    <?php endif; ?>

    <section class="payment-panel">
        <h2>Payment Records</h2>
        <form class="payment-filters" method="get" action="payments.php">
            <input type="search" name="search" value="<?= e($search); ?>" placeholder="Search tenant name or room number" aria-label="Search tenant name or room number">
            <select name="status" aria-label="Filter by payment status">
                <option value="">All statuses</option>
                <?php foreach ($paymentStatuses as $status): ?>
                    <option value="<?= e($status); ?>" <?= $filterStatus === $status ? 'selected' : ''; ?>><?= e($status); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="payment-button" type="submit">Filter</button>
            <a class="payment-button secondary" href="payments.php">Reset</a>
        </form>

        <?php if (!$payments): ?>
            <p class="payment-empty">No payment records match this search or filter.</p>
        <?php else: ?>
            <div class="payment-table-wrap">
                <table class="payment-table">
                    <thead>
                        <tr>
                            <th>Tenant Name</th>
                            <th>Assigned Room</th>
                            <th>Payment Date</th>
                            <th>Payment Method</th>
                            <th>Reference No.</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <?php $statusClass = strtolower($payment['status']); ?>
                            <tr>
                                <td><?= e($payment['full_name'] ?: $payment['username'] ?: 'Tenant record unavailable'); ?></td>
                                <td><?= $payment['room_number'] !== null ? 'Room ' . e($payment['room_number']) : 'No room assigned'; ?></td>
                                <td><?= e($payment['payment_date']); ?></td>
                                <td><?= e($payment['payment_method'] ?? 'Not specified'); ?></td>
                                <td><?= e($payment['reference_number'] ?: '—'); ?></td>
                                <td>₱<?= number_format((float) $payment['amount_paid'], 2); ?></td>
                                <td><span class="payment-status <?= e($statusClass); ?>"><?= e($payment['status']); ?></span></td>
                                <td>
                                    <div class="payment-actions">
                                        <a class="payment-button secondary" href="payments.php?view=<?= (int) $payment['id']; ?>">View</a>
                                        <?php if (in_array($payment['status'], ['Pending Verification', 'Pending'], true)): ?>
                                            <form class="payment-review-form" method="post" action="payments.php" onsubmit="return confirm('Approve this payment proof?');">
                                                <input type="hidden" name="payment_id" value="<?= (int) $payment['id']; ?>">
                                                <input type="hidden" name="status" value="Approved">
                                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['admin_payments_csrf']); ?>">
                                                <input type="hidden" name="search" value="<?= e($search); ?>">
                                                <input type="hidden" name="filter_status" value="<?= e($filterStatus); ?>">
                                                <button class="payment-button" type="submit">Approve</button>
                                            </form>
                                            <form class="payment-review-form" method="post" action="payments.php" onsubmit="return confirm('Reject this pending payment?');">
                                                <input type="hidden" name="payment_id" value="<?= (int) $payment['id']; ?>">
                                                <input type="hidden" name="status" value="Rejected">
                                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['admin_payments_csrf']); ?>">
                                                <input type="hidden" name="search" value="<?= e($search); ?>">
                                                <input type="hidden" name="filter_status" value="<?= e($filterStatus); ?>">
                                                <button class="payment-button reject" type="submit">Reject</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>