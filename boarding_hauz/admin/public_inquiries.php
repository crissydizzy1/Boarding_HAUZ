<?php
require_once __DIR__ . '/../auth.php';
requireRole('super_admin');

$admin_title = 'Inquiries';
$error = '';
$success = '';
$activeTab = is_string($_GET['tab'] ?? null) && $_GET['tab'] === 'history' ? 'history' : 'unread';
$search = $activeTab === 'history' && is_string($_GET['search'] ?? null)
    ? substr(trim($_GET['search']), 0, 100)
    : '';
$viewValue = is_scalar($_GET['view'] ?? null) ? (string) $_GET['view'] : '';
$viewId = ctype_digit($viewValue) ? (int) $viewValue : 0;
$pageValue = is_scalar($_GET['page'] ?? null) ? (string) $_GET['page'] : '1';
$requestedPage = ctype_digit($pageValue) ? max(1, (int) $pageValue) : 1;
$pageSize = 25;
$offset = 0;
$unreadCount = 0;
$readCount = 0;
$historyCount = 0;
$selectedInquiry = null;
$inquiries = [];
$queryError = false;

if (empty($_SESSION['public_inquiries_csrf'])) {
    $_SESSION['public_inquiries_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $inquiryIdValue = is_scalar($_POST['inquiry_id'] ?? null) ? (string) $_POST['inquiry_id'] : '';
    $inquiryId = ctype_digit($inquiryIdValue) ? (int) $inquiryIdValue : 0;

    if (!hash_equals($_SESSION['public_inquiries_csrf'], $postedToken)) {
        $error = 'Your session could not be verified. Refresh the page and try again.';
    } elseif ($action !== 'mark_read' || $inquiryId <= 0) {
        $error = 'Select a valid inquiry action.';
    } else {
        try {
            $conn->begin_transaction();
            $stmt = $conn->prepare("SELECT status FROM public_inquiries WHERE id = ? AND inquiry_type = 'platform' LIMIT 1 FOR UPDATE");
            $stmt->bind_param('i', $inquiryId);
            $stmt->execute();
            $inquiry = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$inquiry) {
                throw new RuntimeException('The selected platform inquiry could not be found.');
            }

            if ($inquiry['status'] === 'Unread') {
                $stmt = $conn->prepare("UPDATE public_inquiries SET status = 'Read' WHERE id = ? AND inquiry_type = 'platform' AND status = 'Unread' LIMIT 1");
                $stmt->bind_param('i', $inquiryId);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            $_SESSION['public_inquiries_notice'] = 'Inquiry marked as read and moved to History.';
            header('Location: /boarding_hauz/admin/public_inquiries.php?tab=history&view=' . $inquiryId);
            exit;
        } catch (RuntimeException $exception) {
            $conn->rollback();
            $error = $exception->getMessage();
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            error_log('Public platform inquiry read update failed: ' . $exception->getMessage());
            $error = 'The inquiry could not be marked as read. Please try again.';
        }
    }
}

if (!empty($_SESSION['public_inquiries_notice'])) {
    $success = (string) $_SESSION['public_inquiries_notice'];
    unset($_SESSION['public_inquiries_notice']);
}

try {
    $result = $conn->query("SELECT COUNT(*) AS total FROM public_inquiries WHERE inquiry_type = 'platform' AND status = 'Unread'");
    $unreadCount = (int) ($result->fetch_assoc()['total'] ?? 0);
    $result = $conn->query("SELECT COUNT(*) AS total FROM public_inquiries WHERE inquiry_type = 'platform' AND status = 'Read'");
    $readCount = (int) ($result->fetch_assoc()['total'] ?? 0);

    if ($viewId > 0) {
        $stmt = $conn->prepare("
            SELECT id, full_name, email, contact_number, preferred_room_type, message, status, created_at
            FROM public_inquiries
            WHERE id = ? AND inquiry_type = 'platform'
            LIMIT 1
        ");
        $stmt->bind_param('i', $viewId);
        $stmt->execute();
        $selectedInquiry = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$selectedInquiry) {
            $error = 'The selected platform inquiry could not be found.';
        }
    } else {
        $searchPattern = '%' . $search . '%';
        if ($activeTab === 'unread') {
            $historyCount = $unreadCount;
            $totalPages = max(1, (int) ceil($historyCount / $pageSize));
            $page = min($requestedPage, $totalPages);
            $offset = ($page - 1) * $pageSize;
            $stmt = $conn->prepare("
                SELECT id, full_name, email, contact_number, message, status, created_at
                FROM public_inquiries
                WHERE inquiry_type = 'platform' AND status = 'Unread'
                ORDER BY created_at DESC, id DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bind_param('ii', $pageSize, $offset);
        } else {
            $countStmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM public_inquiries
                WHERE inquiry_type = 'platform' AND status = 'Read'
                  AND (? = '' OR full_name LIKE ? OR email LIKE ? OR message LIKE ?)
            ");
            $countStmt->bind_param('ssss', $search, $searchPattern, $searchPattern, $searchPattern);
            $countStmt->execute();
            $historyCount = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
            $countStmt->close();
            $totalPages = max(1, (int) ceil($historyCount / $pageSize));
            $page = min($requestedPage, $totalPages);
            $offset = ($page - 1) * $pageSize;
            $stmt = $conn->prepare("
                SELECT id, full_name, email, contact_number, message, status, created_at
                FROM public_inquiries
                WHERE inquiry_type = 'platform' AND status = 'Read'
                  AND (? = '' OR full_name LIKE ? OR email LIKE ? OR message LIKE ?)
                ORDER BY created_at DESC, id DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->bind_param('ssssii', $search, $searchPattern, $searchPattern, $searchPattern, $pageSize, $offset);
        }
        $stmt->execute();
        $inquiries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (mysqli_sql_exception $exception) {
    error_log('Admin public inquiries query failed: ' . $exception->getMessage());
    $queryError = true;
}

include __DIR__ . '/admin_header.php';
?>
<style>
    .platform-page{max-width:1160px;margin:0 auto;padding:32px 24px 56px}
    .platform-heading{margin-bottom:22px;padding:24px 28px;border:1px solid #236b67;border-radius:16px;background:#0d5b50}
    .platform-heading h1{margin:0 0 7px;color:#fff;font:700 30px 'Playfair Display',serif}
    .platform-heading p{margin:0;color:#dff3f7;font-size:13px;line-height:1.6}
    .platform-alert{margin-bottom:16px;padding:12px 15px;border-radius:7px;font-size:13px}
    .platform-alert.success{background:#dff4e9;color:#18734f}
    .platform-alert.error,.platform-alert.warning{background:#fde5e5;color:#8a1c1c}
    .inquiry-tabs{display:flex;gap:8px;margin:0 0 16px;border-bottom:1px solid #d7e6ea}
    .inquiry-tabs a{padding:10px 14px;color:#536466;font-size:13px;font-weight:700;text-decoration:none;border-bottom:3px solid transparent}
    .inquiry-tabs a.active{color:#0d5b50;border-color:#22b486}
    .inquiry-search{display:flex;gap:8px;margin:0 0 16px}
    .inquiry-search input{box-sizing:border-box;flex:1;min-width:0;padding:9px 11px;border:1px solid #d7e6ea;border-radius:6px;font:inherit}
    .inquiry-search button,.inquiry-link,.inquiry-mark-read,.inquiry-back{display:inline-block;padding:9px 13px;border:0;border-radius:6px;background:#22b486;color:#fff;font-size:13px;font-weight:700;text-decoration:none;cursor:pointer}
    .inquiry-link,.inquiry-back{background:#236b67}
    .platform-case{margin-bottom:14px;padding:20px;border:1px solid #d7e6ea;border-radius:10px;background:#fff}
    .platform-case-header{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}
    .platform-case h2{margin:0 0 8px;color:#0d1c1e;font:700 18px 'Playfair Display',serif;overflow-wrap:anywhere}
    .platform-meta{display:flex;flex-wrap:wrap;gap:6px 14px;color:#667b7e;font-size:12px;overflow-wrap:anywhere}
    .platform-message{margin:14px 0 0;color:#263638;font-size:13px;line-height:1.6;white-space:pre-wrap;overflow-wrap:anywhere}
    .platform-status{flex:none;padding:5px 9px;border-radius:999px;background:#fff4d8;color:#806300;font-size:11px;font-weight:700}
    .platform-status.read{background:#eaf8f1;color:#18734f}
    .platform-actions{display:flex;gap:8px;margin-top:16px}
    .platform-pagination{display:flex;justify-content:center;align-items:center;gap:14px;margin-top:18px;color:#536466;font-size:13px}
    .platform-pagination a{padding:8px 11px;border-radius:5px;background:#edf9fc;color:#236b67;font-weight:700;text-decoration:none}
    .platform-empty{padding:18px;border:1px solid #d7e6ea;border-radius:9px;background:#fff;color:#667b7e;font-size:13px}
    @media(max-width:650px){.platform-page{padding:22px 14px}.platform-case-header{flex-direction:column}.inquiry-search{flex-direction:column}.inquiry-search button{align-self:flex-start}}
</style>
<main class="platform-page">
    <header class="platform-heading">
        <h1>Inquiries</h1>
        <p>Review platform and account-support submissions from Contact Us. Property and room inquiries remain with the responsible landlord.</p>
    </header>

    <?php if ($success !== ''): ?><p class="platform-alert success" role="status"><?= e($success); ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="platform-alert error" role="alert"><?= e($error); ?></p><?php endif; ?>
    <?php if ($queryError): ?><p class="platform-alert warning" role="alert">Inquiry data could not be loaded. Check the application log.</p><?php endif; ?>

    <nav class="inquiry-tabs" aria-label="Inquiry status">
        <a href="/boarding_hauz/admin/public_inquiries.php?tab=unread" class="<?= $activeTab === 'unread' ? 'active' : ''; ?>" <?= $activeTab === 'unread' ? 'aria-current="page"' : ''; ?>>Unread (<?= $unreadCount; ?>)</a>
        <a href="/boarding_hauz/admin/public_inquiries.php?tab=history" class="<?= $activeTab === 'history' ? 'active' : ''; ?>" <?= $activeTab === 'history' ? 'aria-current="page"' : ''; ?>>History (<?= $readCount; ?>)</a>
    </nav>

    <?php if ($viewId > 0 && $selectedInquiry): ?>
        <article class="platform-case">
            <header class="platform-case-header">
                <div>
                    <h2><?= e($selectedInquiry['full_name']); ?></h2>
                    <div class="platform-meta">
                        <span><?= e($selectedInquiry['email']); ?></span>
                        <span><?= e($selectedInquiry['contact_number']); ?></span>
                        <span><?= e($selectedInquiry['created_at']); ?></span>
                    </div>
                </div>
                <span class="platform-status <?= $selectedInquiry['status'] === 'Read' ? 'read' : ''; ?>"><?= e($selectedInquiry['status']); ?></span>
            </header>
            <?php if (!empty($selectedInquiry['preferred_room_type'])): ?>
                <p class="platform-message"><strong>Room type:</strong> <?= e($selectedInquiry['preferred_room_type']); ?></p>
            <?php endif; ?>
            <p class="platform-message"><?= e($selectedInquiry['message']); ?></p>
            <div class="platform-actions">
                <a class="inquiry-back" href="/boarding_hauz/admin/public_inquiries.php?tab=<?= $selectedInquiry['status'] === 'Read' ? 'history' : 'unread'; ?>">Back to <?= $selectedInquiry['status'] === 'Read' ? 'History' : 'Unread'; ?></a>
                <?php if ($selectedInquiry['status'] === 'Unread'): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['public_inquiries_csrf']); ?>">
                        <input type="hidden" name="action" value="mark_read">
                        <input type="hidden" name="inquiry_id" value="<?= (int) $selectedInquiry['id']; ?>">
                        <button class="inquiry-mark-read" type="submit">Mark as Read</button>
                    </form>
                <?php endif; ?>
            </div>
        </article>
    <?php elseif ($viewId === 0): ?>
        <?php if ($activeTab === 'history'): ?>
            <form method="get" class="inquiry-search">
                <input type="hidden" name="tab" value="history">
                <label class="visually-hidden" for="inquiry-search">Search inquiry history</label>
                <input id="inquiry-search" type="search" name="search" maxlength="100" value="<?= e($search); ?>" placeholder="Search name, email, or message">
                <button type="submit">Search</button>
            </form>
        <?php endif; ?>

        <?php if ($inquiries): ?>
            <?php foreach ($inquiries as $inquiry): ?>
                <article class="platform-case">
                    <header class="platform-case-header">
                        <div>
                            <h2><?= e($inquiry['full_name']); ?></h2>
                            <div class="platform-meta">
                                <span><?= e($inquiry['email']); ?></span>
                                <span><?= e($inquiry['contact_number']); ?></span>
                                <span><?= e($inquiry['created_at']); ?></span>
                            </div>
                        </div>
                        <span class="platform-status <?= $inquiry['status'] === 'Read' ? 'read' : ''; ?>"><?= e($inquiry['status']); ?></span>
                    </header>
                    <p class="platform-message"><?= e($inquiry['message']); ?></p>
                    <div class="platform-actions">
                        <a class="inquiry-link" href="/boarding_hauz/admin/public_inquiries.php?tab=<?= e($activeTab); ?>&amp;view=<?= (int) $inquiry['id']; ?><?= $activeTab === 'history' ? '&amp;search=' . rawurlencode($search) : ''; ?>">Open Inquiry</a>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php
            $totalPages = max(1, (int) ceil($historyCount / $pageSize));
            $pageQuery = ['tab' => $activeTab];
            if ($activeTab === 'history' && $search !== '') {
                $pageQuery['search'] = $search;
            }
            ?>
            <nav class="platform-pagination" aria-label="Inquiry result pages">
                <?php if ($page > 1): $pageQuery['page'] = $page - 1; ?>
                    <a href="?<?= e(http_build_query($pageQuery)); ?>">Previous</a>
                <?php endif; ?>
                <span>Page <?= $page; ?> of <?= $totalPages; ?></span>
                <?php if ($page < $totalPages): $pageQuery['page'] = $page + 1; ?>
                    <a href="?<?= e(http_build_query($pageQuery)); ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php else: ?>
            <p class="platform-empty"><?= $activeTab === 'unread' ? 'There are no unread platform inquiries.' : 'No read platform inquiries match your search.'; ?></p>
        <?php endif; ?>
    <?php endif; ?>
</main>
