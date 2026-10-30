<?php
require_once __DIR__ . '/../auth.php';
requireRole('landlord');

$admin_title = 'Maintenance Management';
$branchId = requireSelectedBranch();
$statuses = ['Pending', 'In Progress', 'Resolved', 'Closed'];
$successMessages = [
    'updated' => 'Maintenance request status was updated successfully.',
];
$successKey = $_GET['success'] ?? '';
$success = is_string($successKey) ? ($successMessages[$successKey] ?? '') : '';
$error = '';

if (empty($_SESSION['admin_maintenance_csrf'])) {
    $_SESSION['admin_maintenance_csrf'] = bin2hex(random_bytes(32));
}

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
");
$tableName = 'maintenance_requests';
$stmt->bind_param('s', $tableName);
$stmt->execute();
$hasMaintenanceTable = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
$stmt->close();

$search = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
$search = substr($search, 0, 100);
$filterStatus = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if (!in_array($filterStatus, $statuses, true)) {
    $filterStatus = '';
}
$filterCategory = is_string($_GET['category'] ?? null) ? trim($_GET['category']) : '';
$filterCategory = substr($filterCategory, 0, 50);
$viewRequestId = is_scalar($_GET['view'] ?? null) ? (int) $_GET['view'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasMaintenanceTable) {
    $postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    $requestIdValue = is_scalar($_POST['request_id'] ?? null) ? (string) $_POST['request_id'] : '';
    $requestId = ctype_digit($requestIdValue) ? (int) $requestIdValue : 0;
    $newStatus = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
    $postSearch = is_string($_POST['search'] ?? null) ? substr(trim($_POST['search']), 0, 100) : '';
    $postStatus = is_string($_POST['filter_status'] ?? null) ? $_POST['filter_status'] : '';
    $postCategory = is_string($_POST['filter_category'] ?? null) ? substr(trim($_POST['filter_category']), 0, 50) : '';

    $redirectParameters = [];
    if ($postSearch !== '') {
        $redirectParameters['search'] = $postSearch;
    }
    if (in_array($postStatus, $statuses, true)) {
        $redirectParameters['status'] = $postStatus;
    }
    if ($postCategory !== '') {
        $redirectParameters['category'] = $postCategory;
    }

    if (!hash_equals($_SESSION['admin_maintenance_csrf'], $postedToken)) {
        $error = 'Your session could not be verified. Refresh the page and try again.';
    } elseif ($requestId <= 0 || !in_array($newStatus, $statuses, true)) {
        $error = 'Select a valid maintenance request and status.';
    } else {
        try {
            $stmt = $conn->prepare("UPDATE maintenance_requests SET status = ? WHERE id = ? AND branch_id = ? LIMIT 1");
            $stmt->bind_param('sii', $newStatus, $requestId, $branchId);
            $stmt->execute();
            $affectedRows = $stmt->affected_rows;
            $stmt->close();

            if ($affectedRows === 0) {
                $stmt = $conn->prepare("SELECT id, status FROM maintenance_requests WHERE id = ? AND branch_id = ? LIMIT 1");
                $stmt->bind_param('ii', $requestId, $branchId);
                $stmt->execute();
                $existingRequest = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$existingRequest) {
                    $error = 'The selected maintenance request could not be found.';
                } elseif ($existingRequest['status'] !== $newStatus) {
                    $error = 'The maintenance request status was not changed. Please refresh and try again.';
                } else {
                    $error = 'The request already has that status.';
                }
            } else {
                $redirectParameters['success'] = 'updated';
                $redirectUrl = '/boarding_hauz/landlord/maintenance.php';
                if ($redirectParameters) {
                    $redirectUrl .= '?' . http_build_query($redirectParameters);
                }
                header('Location: ' . $redirectUrl);
                exit;
            }
        } catch (mysqli_sql_exception $exception) {
            error_log('Maintenance status update failed: ' . $exception->getMessage());
            $error = 'The maintenance request status could not be updated. Please try again.';
        }
    }
}

$summary = ['total' => 0, 'pending' => 0, 'in_progress' => 0, 'resolved' => 0];
$requests = [];
$categories = [];
$viewRequest = null;

if ($hasMaintenanceTable) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total,
            COALESCE(SUM(mr.status = 'Pending'), 0) AS pending,
            COALESCE(SUM(mr.status = 'In Progress'), 0) AS in_progress,
            COALESCE(SUM(mr.status = 'Resolved'), 0) AS resolved
        FROM maintenance_requests mr
        WHERE mr.branch_id = ?
    ");
    $stmt->bind_param('i', $branchId);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc() ?: $summary;
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT DISTINCT category
        FROM maintenance_requests mr
        LEFT JOIN tenants t ON t.id = mr.tenant_id AND t.branch_id = mr.branch_id
        LEFT JOIN rooms r ON r.id = mr.room_id AND r.branch_id = mr.branch_id
        WHERE mr.branch_id = ? AND mr.category IS NOT NULL AND mr.category <> ''
        ORDER BY mr.category
    ");
    $stmt->bind_param('i', $branchId);
    $stmt->execute();
    $categoryResult = $stmt->get_result();
    while ($categoryRow = $categoryResult->fetch_assoc()) {
        $categories[] = $categoryRow['category'];
    }
    $stmt->close();

    if ($filterCategory !== '' && !in_array($filterCategory, $categories, true)) {
        $filterCategory = '';
    }

    $requestSql = "
        SELECT mr.id, mr.category, mr.description, mr.status, mr.created_at,
            mr.tenant_id, mr.room_id,
            t.full_name, t.contact_number,
            u.username, u.email,
            r.room_number, r.room_type
        FROM maintenance_requests mr
        LEFT JOIN tenants t ON t.id = mr.tenant_id AND t.branch_id = mr.branch_id
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN rooms r ON r.id = mr.room_id AND r.branch_id = mr.branch_id
    ";
    $conditions = ['mr.branch_id = ?'];
    $requestBindTypes = 'i';
    $requestBindValues = [$branchId];
    if ($search !== '') {
        $conditions[] = '(t.full_name LIKE ? OR r.room_number LIKE ?)';
        $searchPattern = '%' . $search . '%';
        $requestBindTypes .= 'ss';
        array_push($requestBindValues, $searchPattern, $searchPattern);
    }
    if ($filterStatus !== '') {
        $conditions[] = 'mr.status = ?';
        $requestBindTypes .= 's';
        $requestBindValues[] = $filterStatus;
    }
    if ($filterCategory !== '') {
        $conditions[] = 'mr.category = ?';
        $requestBindTypes .= 's';
        $requestBindValues[] = $filterCategory;
    }
    if ($conditions) {
        $requestSql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $requestSql .= "
        ORDER BY
            CASE mr.status
                WHEN 'Pending' THEN 1
                WHEN 'In Progress' THEN 2
                WHEN 'Resolved' THEN 3
                ELSE 4
            END,
            mr.created_at DESC,
            mr.id DESC
    ";

    $stmt = $conn->prepare($requestSql);
    $requestBindParams = [$requestBindTypes];
    foreach ($requestBindValues as $key => &$requestBindValue) {
        $requestBindParams[] = &$requestBindValue;
    }
    if ($requestBindValues) {
        call_user_func_array([$stmt, 'bind_param'], $requestBindParams);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
    $stmt->close();

    if ($viewRequestId > 0) {
        $stmt = $conn->prepare("
            SELECT mr.id, mr.category, mr.description, mr.status, mr.created_at, mr.updated_at,
                mr.tenant_id, mr.room_id,
                t.full_name, t.contact_number,
                u.username, u.email,
                r.room_number, r.room_type
            FROM maintenance_requests mr
            LEFT JOIN tenants t ON t.id = mr.tenant_id AND t.branch_id = mr.branch_id
            LEFT JOIN users u ON u.id = t.user_id
            LEFT JOIN rooms r ON r.id = mr.room_id AND r.branch_id = mr.branch_id
            WHERE mr.id = ? AND mr.branch_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $viewRequestId, $branchId);
        $stmt->execute();
        $viewRequest = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

function formatMaintenanceDate(?string $dateValue): string
{
    if ($dateValue === null || $dateValue === '') {
        return 'Not recorded';
    }
    $timestamp = strtotime($dateValue);
    return $timestamp === false ? $dateValue : date('M j, Y · g:i A', $timestamp);
}

function maintenanceStatusClass(string $status): string
{
    $classes = [
        'Pending' => 'pending',
        'In Progress' => 'in-progress',
        'Resolved' => 'resolved',
        'Closed' => 'closed',
    ];
    return $classes[$status] ?? 'other';
}

include __DIR__ . '/../admin/admin_header.php';
?>

<style>
    .maintenance-page{max-width:1200px;margin:0 auto;padding:32px 24px 56px}
    .maintenance-heading{margin-bottom:22px;padding:24px 28px;border:1px solid #236b67;border-radius:16px;background:#0d5b50}
    .maintenance-heading h1{margin:0 0 5px;color:#fff;font:700 31px 'Playfair Display',serif}
    .maintenance-heading p{margin:0;color:#dff3f7;font-size:13px}
    .maintenance-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:20px}
    .maintenance-stat{padding:16px 18px;border:1px solid #d7e6ea;border-radius:9px;background:#fff}
    .maintenance-stat span{color:#536466;font-size:12px;font-weight:700}
    .maintenance-stat strong{display:block;margin-top:6px;color:#0d1c1e;font-size:24px}
    .maintenance-panel{margin-bottom:18px;border:1px solid #d7e6ea;border-radius:9px;background:#fff;overflow:hidden}
    .maintenance-panel-heading{padding:17px 19px;border-bottom:1px solid #d7e6ea}
    .maintenance-panel-heading h2{margin:0;color:#0d1c1e;font:700 21px 'Playfair Display',serif}
    .maintenance-panel-heading p{margin:5px 0 0;color:#667b7e;font-size:12px}
    .maintenance-panel-body{padding:18px 19px}
    .maintenance-alert{margin-bottom:15px;padding:11px 13px;border:1px solid;border-radius:6px;font-size:13px;line-height:1.5}
    .maintenance-success{border-color:#b8e6d3;background:#eaf8f1;color:#18734f}
    .maintenance-error{border-color:#f0c7c7;background:#fff0f0;color:#ba1a1a}
    .maintenance-toolbar{display:grid;grid-template-columns:minmax(220px,1fr) minmax(150px,.55fr) minmax(150px,.65fr) auto;gap:9px;margin-bottom:14px}
    .maintenance-toolbar input,.maintenance-toolbar select,.maintenance-table select{min-height:39px;width:100%;padding:8px 10px;border:1px solid #d7e6ea;border-radius:6px;background:#fff;color:#0d1c1e;font:inherit;font-size:12px}
    .maintenance-toolbar input:focus,.maintenance-toolbar select:focus,.maintenance-table select:focus{outline:2px solid #22b486;outline-offset:1px}
    .maintenance-button{display:inline-flex;align-items:center;justify-content:center;min-height:35px;padding:7px 11px;border:1px solid #d7e6ea;border-radius:6px;background:#fff;color:#236b67;text-decoration:none;font:inherit;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap}
    .maintenance-button:hover{border-color:#22b486;background:#edf9fc}
    .maintenance-button-primary{border-color:#22b486;background:#22b486;color:#fff}
    .maintenance-button-primary:hover{background:#1aa27a;color:#fff}
    .maintenance-table-wrap{overflow-x:auto;border:1px solid #d7e6ea;border-radius:7px}
    .maintenance-table{width:100%;min-width:840px;border-collapse:collapse;text-align:left}
    .maintenance-table th,.maintenance-table td{padding:12px 13px;border-bottom:1px solid #d7e6ea;font-size:12px;vertical-align:middle}
    .maintenance-table th{background:#edf9fc;color:#236b67;font-weight:700}
    .maintenance-table td{color:#536466}
    .maintenance-table tbody tr:last-child td{border-bottom:0}
    .maintenance-tenant{color:#0d1c1e;font-weight:700}
    .maintenance-subtext{display:block;margin-top:3px;color:#667b7e;font-size:11px}
    .maintenance-status{display:inline-flex;padding:5px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
    .maintenance-status-pending{background:#fff4d6;color:#866000}
    .maintenance-status-in-progress{background:#dff3f7;color:#236b67}
    .maintenance-status-resolved{background:#e3f6ee;color:#18734f}
    .maintenance-status-closed{background:#edf2f3;color:#52676a}
    .maintenance-actions{display:flex;flex-wrap:wrap;align-items:center;gap:7px}
    .maintenance-update{display:flex;align-items:center;gap:6px}
    .maintenance-empty{padding:34px 18px!important;color:#667b7e!important;text-align:center}
    .maintenance-empty strong{display:block;margin-bottom:5px;color:#236b67;font:700 18px 'Playfair Display',serif}
    .maintenance-detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
    .maintenance-detail{padding:12px;border:1px solid #d7e6ea;border-radius:7px;background:#f7fcfd}
    .maintenance-detail span{display:block;margin-bottom:4px;color:#667b7e;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
    .maintenance-detail strong{color:#0d1c1e;font-size:13px;word-break:break-word}
    .maintenance-description{grid-column:1/-1}
    .maintenance-description p{margin:0;color:#0d1c1e;font-size:13px;line-height:1.65;white-space:pre-wrap;overflow-wrap:anywhere}
    .maintenance-setup{padding:16px;border:1px solid #d7e6ea;border-radius:7px;background:#f7fcfd;color:#536466;font-size:13px;line-height:1.6}
    @media(max-width:900px){.maintenance-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.maintenance-toolbar{grid-template-columns:repeat(2,minmax(0,1fr))}.maintenance-detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:600px){.maintenance-page{padding:23px 15px 40px}.maintenance-panel-body{padding:14px}.maintenance-toolbar,.maintenance-detail-grid{grid-template-columns:1fr}.maintenance-summary{gap:8px}.maintenance-stat{padding:13px}.maintenance-description{grid-column:auto}.maintenance-update{align-items:stretch;flex-direction:column}.maintenance-update .maintenance-button{width:100%}}
</style>

<main class="maintenance-page">
    <header class="maintenance-heading">
        <h1>Maintenance Management</h1>
        <p>Review tenant repair requests and update their status as work progresses.</p>
    </header>

    <?php if ($success !== ''): ?><div class="maintenance-alert maintenance-success" role="status"><?= e($success); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="maintenance-alert maintenance-error" role="alert"><?= e($error); ?></div><?php endif; ?>

    <?php if (!$hasMaintenanceTable): ?>
        <p class="maintenance-setup">Maintenance request storage is not configured. Apply <code>database/maintenance_requests.sql</code> before managing tenant requests.</p>
    <?php else: ?>
        <section class="maintenance-summary" aria-label="Maintenance request summary">
            <div class="maintenance-stat"><span>Total Requests</span><strong><?= (int) $summary['total']; ?></strong></div>
            <div class="maintenance-stat"><span>Pending</span><strong><?= (int) $summary['pending']; ?></strong></div>
            <div class="maintenance-stat"><span>In Progress</span><strong><?= (int) $summary['in_progress']; ?></strong></div>
            <div class="maintenance-stat"><span>Resolved</span><strong><?= (int) $summary['resolved']; ?></strong></div>
        </section>

        <?php if ($viewRequestId > 0): ?>
            <section class="maintenance-panel" aria-labelledby="maintenance-detail-heading">
                <div class="maintenance-panel-heading"><h2 id="maintenance-detail-heading">Request Details</h2></div>
                <div class="maintenance-panel-body">
                    <?php if ($viewRequest): ?>
                        <div class="maintenance-detail-grid">
                            <div class="maintenance-detail"><span>Tenant</span><strong><?= e($viewRequest['full_name'] ?: $viewRequest['username'] ?: 'Tenant record unavailable'); ?></strong></div>
                            <div class="maintenance-detail"><span>Assigned Room</span><strong><?= $viewRequest['room_number'] !== null ? 'Room ' . e($viewRequest['room_number']) . ($viewRequest['room_type'] ? ' · ' . e($viewRequest['room_type']) : '') : 'Unassigned'; ?></strong></div>
                            <div class="maintenance-detail"><span>Problem Type</span><strong><?= e($viewRequest['category']); ?></strong></div>
                            <div class="maintenance-detail"><span>Date Submitted</span><strong><?= e(formatMaintenanceDate($viewRequest['created_at'])); ?></strong></div>
                            <div class="maintenance-detail"><span>Current Status</span><strong><span class="maintenance-status maintenance-status-<?= e(maintenanceStatusClass($viewRequest['status'])); ?>"><?= e($viewRequest['status']); ?></span></strong></div>
                            <div class="maintenance-detail"><span>Last Updated</span><strong><?= e(formatMaintenanceDate($viewRequest['updated_at'])); ?></strong></div>
                            <div class="maintenance-detail maintenance-description"><span>Description</span><p><?= e($viewRequest['description']); ?></p></div>
                        </div>
                    <?php else: ?>
                        <p class="maintenance-setup">The selected maintenance request could not be found.</p>
                    <?php endif; ?>
                    <p style="margin-top:14px"><a class="maintenance-button" href="maintenance.php<?= $search !== '' || $filterStatus !== '' || $filterCategory !== '' ? '?' . e(http_build_query(array_filter(['search' => $search, 'status' => $filterStatus, 'category' => $filterCategory]))) : ''; ?>">Back to Requests</a></p>
                </div>
            </section>
        <?php endif; ?>

        <section class="maintenance-panel" aria-labelledby="maintenance-requests-heading">
            <div class="maintenance-panel-heading">
                <h2 id="maintenance-requests-heading">Tenant Requests</h2>
                <p>Update only the request status. Tenant and room records are not changed.</p>
            </div>
            <div class="maintenance-panel-body">
                <form class="maintenance-toolbar" method="get" action="maintenance.php">
                    <input type="search" name="search" value="<?= e($search); ?>" placeholder="Search tenant or room number" aria-label="Search tenant name or room number">
                    <select name="status" aria-label="Filter by maintenance status">
                        <option value="">All Statuses</option>
                        <?php foreach ($statuses as $statusOption): ?>
                            <option value="<?= e($statusOption); ?>" <?= $filterStatus === $statusOption ? 'selected' : ''; ?>><?= e($statusOption); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="category" aria-label="Filter by problem category">
                        <option value="">All Problem Types</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= e($category); ?>" <?= $filterCategory === $category ? 'selected' : ''; ?>><?= e($category); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div style="display:flex;gap:7px"><button class="maintenance-button maintenance-button-primary" type="submit">Apply Filters</button><a class="maintenance-button" href="maintenance.php">Reset</a></div>
                </form>

                <div class="maintenance-table-wrap">
                    <table class="maintenance-table">
                        <thead>
                            <tr>
                                <th scope="col">Tenant Name</th>
                                <th scope="col">Assigned Room</th>
                                <th scope="col">Problem Type</th>
                                <th scope="col">Date Submitted</th>
                                <th scope="col">Status</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($requests): ?>
                                <?php foreach ($requests as $request): ?>
                                    <tr>
                                        <td><span class="maintenance-tenant"><?= e($request['full_name'] ?: $request['username'] ?: 'Tenant record unavailable'); ?></span><?php if ($request['contact_number']): ?><span class="maintenance-subtext"><?= e($request['contact_number']); ?></span><?php endif; ?></td>
                                        <td><?= $request['room_number'] !== null ? 'Room ' . e($request['room_number']) : 'Unassigned'; ?></td>
                                        <td><?= e($request['category']); ?></td>
                                        <td><?= e(formatMaintenanceDate($request['created_at'])); ?></td>
                                        <td><span class="maintenance-status maintenance-status-<?= e(maintenanceStatusClass($request['status'])); ?>"><?= e($request['status']); ?></span></td>
                                        <td>
                                            <div class="maintenance-actions">
                                                <a class="maintenance-button" href="maintenance.php?view=<?= (int) $request['id']; ?><?= $search !== '' ? '&amp;search=' . rawurlencode($search) : ''; ?><?= $filterStatus !== '' ? '&amp;status=' . rawurlencode($filterStatus) : ''; ?><?= $filterCategory !== '' ? '&amp;category=' . rawurlencode($filterCategory) : ''; ?>">View Details</a>
                                                <form method="post" class="maintenance-update">
                                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['admin_maintenance_csrf']); ?>">
                                                    <input type="hidden" name="request_id" value="<?= (int) $request['id']; ?>">
                                                    <input type="hidden" name="search" value="<?= e($search); ?>">
                                                    <input type="hidden" name="filter_status" value="<?= e($filterStatus); ?>">
                                                    <input type="hidden" name="filter_category" value="<?= e($filterCategory); ?>">
                                                    <label class="visually-hidden" for="request-status-<?= (int) $request['id']; ?>">New status for request <?= (int) $request['id']; ?></label>
                                                    <select id="request-status-<?= (int) $request['id']; ?>" name="status" required>
                                                        <?php foreach ($statuses as $statusOption): ?>
                                                            <option value="<?= e($statusOption); ?>" <?= $request['status'] === $statusOption ? 'selected' : ''; ?>><?= e($statusOption); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button class="maintenance-button maintenance-button-primary" type="submit">Update Status</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td class="maintenance-empty" colspan="6"><strong><?= (int) $summary['total'] === 0 ? 'No maintenance requests yet' : 'No requests match these filters'; ?></strong><?= (int) $summary['total'] === 0 ? 'Tenant submissions will appear here.' : 'Try another tenant name, room, category, or status.'; ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
