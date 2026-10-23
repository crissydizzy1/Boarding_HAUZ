<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/room_occupancy.php';
requireRole('landlord');

$admin_title = 'Tenant Directory & Management';
$branchId = requireSelectedBranch();
$adminId = currentBranchLandlordId();
$tenantStatuses = ['pending', 'active', 'rejected', 'inactive'];
$successMessages = [
    'updated' => 'Tenant details have been updated.',
];
$successKey = $_GET['success'] ?? '';
$success = is_string($successKey) ? ($successMessages[$successKey] ?? '') : '';
$error = '';

if (empty($_SESSION['admin_tenants_csrf'])) {
    $_SESSION['admin_tenants_csrf'] = bin2hex(random_bytes(32));
}

$requestedEditId = $_GET['edit'] ?? null;
$editId = is_scalar($requestedEditId) ? (int) $requestedEditId : 0;
$requestedViewId = $_GET['view'] ?? null;
$viewId = is_scalar($requestedViewId) ? (int) $requestedViewId : 0;
$editValues = ['full_name' => '', 'contact_number' => '', 'status' => 'pending'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    $postedAction = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($postedAction === 'update_tenant') {
    $tenantId = is_scalar($_POST['tenant_id'] ?? null) ? (int) $_POST['tenant_id'] : 0;
    $editId = $tenantId;
    $editValues['full_name'] = is_string($_POST['full_name'] ?? null) ? trim($_POST['full_name']) : '';
    $editValues['contact_number'] = is_string($_POST['contact_number'] ?? null) ? trim($_POST['contact_number']) : '';
    $editValues['status'] = is_string($_POST['status'] ?? null) ? trim($_POST['status']) : '';

    if (!hash_equals($_SESSION['admin_tenants_csrf'], $postedToken)) {
        $error = 'Your session could not be verified. Refresh the page and try again.';
    } elseif ($tenantId <= 0) {
        $error = 'Invalid tenant update request.';
    } elseif ($editValues['full_name'] === '' || strlen($editValues['full_name']) > 100) {
        $error = 'Tenant name is required and must be 100 characters or fewer.';
    } elseif (strlen($editValues['contact_number']) > 20) {
        $error = 'Contact number must be 20 characters or fewer.';
    } elseif (!in_array($editValues['status'], $tenantStatuses, true)) {
        $error = 'Choose a valid tenant status.';
    } else {
        try {
            $conn->begin_transaction();
            $stmt = $conn->prepare("
                SELECT t.id
                FROM tenants t
                WHERE t.id = ?
                  AND (t.branch_id = ? OR EXISTS (
                      SELECT 1
                      FROM tenant_leases l
                      INNER JOIN rooms r ON r.id = l.room_id
                      WHERE l.tenant_id = t.id AND r.branch_id = ?
                  ))
                LIMIT 1 FOR UPDATE
            ");
            $stmt->bind_param('iii', $tenantId, $branchId, $branchId);
            $stmt->execute();
            $tenantToUpdate = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$tenantToUpdate) {
                throw new RuntimeException('The selected tenant could not be found for this branch.');
            }
            $contactNumber = $editValues['contact_number'] === '' ? null : $editValues['contact_number'];
            $stmt = $conn->prepare('UPDATE tenants SET full_name = ?, contact_number = ?, status = ? WHERE id = ? LIMIT 1');
            $stmt->bind_param('sssi', $editValues['full_name'], $contactNumber, $editValues['status'], $tenantId);
            $stmt->execute();
            $stmt->close();
            $conn->commit();
            header('Location: /boarding_hauz/landlord/tenants.php?success=updated');
            exit;
        } catch (RuntimeException $exception) {
            $conn->rollback();
            $error = $exception->getMessage();
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            error_log('Tenant directory update failed: ' . $exception->getMessage());
            $error = 'Tenant details could not be updated. Please try again.';
        } catch (Throwable $exception) {
            $conn->rollback();
            error_log('Tenant directory update failed: ' . $exception->getMessage());
            $error = 'Tenant details could not be updated. Please try again.';
        }
    }
    } else {
        $error = 'Invalid tenant action.';
    }
}

$search = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
$search = substr($search, 0, 80);
$filterStatus = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if (!in_array($filterStatus, $tenantStatuses, true)) {
    $filterStatus = '';
}
$roomFilter = is_string($_GET['room'] ?? null) ? $_GET['room'] : '';
if (!in_array($roomFilter, ['assigned', 'unassigned'], true)) {
    $roomFilter = '';
}

$tenantCounts = ['total' => 0, 'active' => 0, 'pending' => 0, 'unassigned' => 0];
$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT t.id) AS total,
        COUNT(DISTINCT CASE WHEN t.status = 'active' THEN t.id END) AS active,
        COUNT(DISTINCT CASE WHEN t.status = 'pending' THEN t.id END) AS pending,
        COUNT(DISTINCT CASE WHEN t.status = 'active' AND r.id IS NULL THEN t.id END) AS unassigned
    FROM tenants t
    LEFT JOIN tenant_leases l ON l.tenant_id = t.id AND l.status = 'active'
    LEFT JOIN rooms r ON r.id = l.room_id AND r.branch_id = ?
    WHERE t.branch_id = ? OR r.id IS NOT NULL
");
$stmt->bind_param('ii', $branchId, $branchId);
$stmt->execute();
$tenantCounts = $stmt->get_result()->fetch_assoc() ?: $tenantCounts;
$stmt->close();

$tenantSql = "
    SELECT t.id, t.full_name, t.contact_number,
        MIN(CASE WHEN l.id IS NOT NULL THEN l.room_id END) AS room_id,
        MAX(l.move_in_date) AS move_in_date,
        COUNT(DISTINCT l.id) AS branch_lease_count,
        GROUP_CONCAT(DISTINCT r.room_number ORDER BY r.room_number SEPARATOR ', ') AS room_number,
        GROUP_CONCAT(DISTINCT r.room_type ORDER BY r.room_type SEPARATOR ', ') AS room_type,
        MAX(l.monthly_rate) AS monthly_rate,
        MAX(r.capacity) AS capacity,
        MAX(r.status) AS room_status,
        t.status AS tenant_status, t.created_at,
        u.email
    FROM tenants t
    LEFT JOIN users u ON u.id = t.user_id
    LEFT JOIN tenant_leases l ON l.tenant_id = t.id AND l.status = 'active'
    LEFT JOIN rooms r ON r.id = l.room_id AND r.branch_id = ?
";
$conditions = ['(t.branch_id = ? OR r.id IS NOT NULL)'];
if ($search !== '') {
    $conditions[] = '(t.full_name LIKE ? OR t.contact_number LIKE ? OR r.room_number LIKE ? OR u.email LIKE ?)';
}
if ($filterStatus !== '') {
    $conditions[] = 't.status = ?';
}
if ($roomFilter === 'assigned') {
    $conditions[] = 'r.id IS NOT NULL';
} elseif ($roomFilter === 'unassigned') {
    $conditions[] = 'r.id IS NULL';
}
if ($conditions) {
    $tenantSql .= ' WHERE ' . implode(' AND ', $conditions);
}
$tenantSql .= ' GROUP BY t.id ORDER BY t.full_name, t.id';

$stmt = $conn->prepare($tenantSql);
$bindTypes = '';
$bindValues = [];
if ($search !== '' && $filterStatus !== '') {
    $searchPattern = '%' . $search . '%';
    $bindTypes .= 'iisssss';
    array_push($bindValues, $branchId, $branchId, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $filterStatus);
} else {
    $bindTypes .= 'ii';
    array_push($bindValues, $branchId, $branchId);
    if ($search !== '') {
        $searchPattern = '%' . $search . '%';
        $bindTypes .= 'ssss';
        array_push($bindValues, $searchPattern, $searchPattern, $searchPattern, $searchPattern);
    }
    if ($filterStatus !== '') {
        $bindTypes .= 's';
        $bindValues[] = $filterStatus;
    }
}
$bindParams = [$bindTypes];
foreach ($bindValues as $key => &$bindValue) {
    $bindParams[] = &$bindValue;
}
if ($bindValues) {
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
}
$stmt->execute();
$result = $stmt->get_result();
$tenants = attachRoomOccupancy($conn, $result->fetch_all(MYSQLI_ASSOC));
foreach ($tenants as &$tenant) {
    if ($tenant['room_id'] !== null) {
        $tenant['room_status'] = $tenant['occupancy_status'];
    }
}
unset($tenant);
$stmt->close();

$editTenant = null;
if ($editId > 0) {
    $stmt = $conn->prepare("
        SELECT t.id, t.full_name, t.contact_number, t.status
        FROM tenants t
        WHERE t.id = ?
          AND (t.branch_id = ? OR EXISTS (
              SELECT 1
              FROM tenant_leases l
              INNER JOIN rooms r ON r.id = l.room_id
              WHERE l.tenant_id = t.id AND r.branch_id = ?
          ))
        LIMIT 1
    ");
    $stmt->bind_param('iii', $editId, $branchId, $branchId);
    $stmt->execute();
    $editTenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($editTenant && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $editValues = [
            'full_name' => $editTenant['full_name'],
            'contact_number' => $editTenant['contact_number'] ?? '',
            'status' => $editTenant['status'],
        ];
    }
}

$viewTenant = null;
if ($viewId > 0) {
    $stmt = $conn->prepare("
        SELECT t.id, t.full_name, t.contact_number,
            MIN(CASE WHEN l.id IS NOT NULL THEN l.room_id END) AS room_id,
            MAX(l.move_in_date) AS move_in_date,
            COUNT(DISTINCT l.id) AS branch_lease_count,
            GROUP_CONCAT(DISTINCT r.room_number ORDER BY r.room_number SEPARATOR ', ') AS room_number,
            GROUP_CONCAT(DISTINCT r.room_type ORDER BY r.room_type SEPARATOR ', ') AS room_type,
            MAX(l.monthly_rate) AS monthly_rate, MAX(r.capacity) AS capacity, MAX(r.status) AS room_status,
            t.status AS tenant_status, t.created_at, u.email
        FROM tenants t
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN tenant_leases l ON l.tenant_id = t.id AND l.status = 'active'
        LEFT JOIN rooms r ON r.id = l.room_id AND r.branch_id = ?
        WHERE t.id = ? AND (t.branch_id = ? OR r.id IS NOT NULL)
        GROUP BY t.id
        LIMIT 1
    ");
    $stmt->bind_param('iii', $branchId, $viewId, $branchId);
    $stmt->execute();
    $viewTenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($viewTenant && $viewTenant['room_id'] !== null) {
        $viewTenant = attachRoomOccupancy($conn, [$viewTenant])[0];
        $viewTenant['room_status'] = $viewTenant['occupancy_status'];
    }
}

    include __DIR__ . '/../admin/admin_header.php';
    ?>
<style>
    .tenants-page{max-width:1200px;margin:0 auto;padding:32px 24px 56px}
    .tenants-heading{display:flex;justify-content:space-between;align-items:flex-end;gap:18px;margin-bottom:22px;padding:24px 28px;border:1px solid #236b67;border-radius:16px;background:#0d5b50}
    .tenants-heading h1{margin:0 0 5px;color:#fff;font:700 31px 'Playfair Display',serif}
    .tenants-heading p{margin:0;color:#dff3f7;font-size:13px}
    .tenant-overview{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:20px}
    .tenant-stat{padding:15px 17px;border:1px solid #d7e6ea;border-radius:9px;background:#fff}
    .tenant-stat span{display:block;color:#536466;font-size:12px;font-weight:700}
    .tenant-stat strong{display:block;margin-top:7px;color:#0d1c1e;font-size:23px}
    .tenant-panel{margin-bottom:18px;padding:20px;border:1px solid #d7e6ea;border-radius:9px;background:#fff}
    .tenant-panel h2{margin:0;color:#0d1c1e;font:700 22px 'Playfair Display',serif}
    .tenant-intro{margin:5px 0 16px;color:#667b7e;font-size:13px;line-height:1.55}
    .tenant-toolbar{display:grid;grid-template-columns:minmax(240px,1.4fr) repeat(2,minmax(150px,.8fr)) auto;gap:9px;margin-bottom:15px}
    .tenant-toolbar input,.tenant-toolbar select,.tenant-form input,.tenant-form select{min-height:40px;width:100%;padding:9px 11px;border:1px solid #d7e6ea;border-radius:6px;background:#fff;color:#0d1c1e;font:inherit;font-size:13px}
    .tenant-toolbar input:focus,.tenant-toolbar select:focus,.tenant-form input:focus{outline:2px solid #22b486;outline-offset:1px}
    .tenant-button{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:7px 11px;border:1px solid #d7e6ea;border-radius:6px;background:#fff;color:#236b67;text-decoration:none;font:inherit;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap}
    .tenant-button:hover{border-color:#22b486;background:#edf9fc}
    .tenant-button-primary{border-color:#22b486;background:#22b486;color:#fff}
    .tenant-button-primary:hover{background:#1aa27a;color:#fff}
    .tenant-table-wrap{overflow-x:auto;border:1px solid #d7e6ea;border-radius:7px}
    .tenant-table{width:100%;min-width:900px;border-collapse:collapse;text-align:left}
    .tenant-table th,.tenant-table td{padding:12px 13px;border-bottom:1px solid #d7e6ea;font-size:12px;vertical-align:middle}
    .tenant-table th{background:#edf9fc;color:#236b67;font-weight:700}
    .tenant-table td{color:#536466}
    .tenant-table tbody tr:last-child td{border-bottom:0}
    .tenant-name{color:#0d1c1e;font-weight:700}
    .tenant-subtext{display:block;margin-top:3px;color:#667b7e;font-size:11px;font-weight:400}
    .tenant-status{display:inline-block;padding:4px 8px;border-radius:20px;background:#edf2f3;color:#52676a;font-size:11px;font-weight:700;text-transform:capitalize;white-space:nowrap}
    .tenant-status-active{background:#e3f6ee;color:#18734f}
    .tenant-status-pending{background:#fff4d6;color:#866000}
    .tenant-status-rejected{background:#fff0f0;color:#8a1c1c}
    .tenant-status-inactive{background:#edf2f3;color:#52676a}
    .tenant-actions{display:flex;flex-wrap:wrap;gap:6px}
    .tenant-empty{padding:25px!important;color:#667b7e!important;text-align:center}
    .tenant-alert{margin-bottom:15px;padding:11px 13px;border:1px solid;border-radius:6px;font-size:13px;line-height:1.5}
    .tenant-alert-success{border-color:#b8e6d3;background:#eaf8f1;color:#18734f}
    .tenant-alert-error{border-color:#f0c7c7;background:#fff0f0;color:#8a1c1c}
    .tenant-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}
    .tenant-form label{display:block;margin-bottom:5px;color:#536466;font-size:12px;font-weight:700}
    .tenant-form-actions{display:flex;flex-wrap:wrap;gap:8px;grid-column:1/-1}
    .tenant-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:14px 0}
    .tenant-detail{padding:12px;border:1px solid #d7e6ea;border-radius:7px;background:#f7fcfd}
    .tenant-detail span{display:block;margin-bottom:4px;color:#667b7e;font-size:11px;font-weight:700}
    .tenant-detail strong{color:#0d1c1e;font-size:13px}
    .tenant-note{margin:0;color:#667b7e;font-size:11px;line-height:1.5}
    @media(max-width:850px){.tenant-overview{grid-template-columns:repeat(2,minmax(0,1fr))}.tenant-toolbar{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:560px){.tenants-page{padding:23px 15px 40px}.tenants-heading{align-items:flex-start;flex-direction:column}.tenant-panel{padding:15px}.tenant-toolbar,.tenant-form,.tenant-details{grid-template-columns:1fr}.tenant-form-actions{grid-column:auto}}
</style>

<main class="tenants-page">
    <header class="tenants-heading">
        <div>
            <h1>Tenant Directory &amp; Management</h1>
            <p>View tenant details, room assignments, and rental status in one place.</p>
        </div>
    </header>

    <?php if ($success !== ''): ?><div class="tenant-alert tenant-alert-success" role="status"><?= e($success); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="tenant-alert tenant-alert-error" role="alert"><?= e($error); ?></div><?php endif; ?>

    <section class="tenant-overview" aria-label="Tenant summary">
        <div class="tenant-stat"><span>Total Tenants</span><strong><?= (int) $tenantCounts['total']; ?></strong></div>
        <div class="tenant-stat"><span>Active Tenants</span><strong><?= (int) $tenantCounts['active']; ?></strong></div>
        <div class="tenant-stat"><span>Pending Tenants</span><strong><?= (int) $tenantCounts['pending']; ?></strong></div>
        <div class="tenant-stat"><span>Unassigned Tenants</span><strong><?= (int) $tenantCounts['unassigned']; ?></strong></div>
    </section>

    <?php if ($editId > 0): ?>
        <section class="tenant-panel" aria-labelledby="edit-tenant-heading">
            <?php if ($editTenant): ?>
                <h2 id="edit-tenant-heading">Edit Tenant Details</h2>
                <p class="tenant-intro">Update this tenant account profile. Changing account status does not end active leases; rental occupancy is managed separately.</p>
                <form method="post" class="tenant-form">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['admin_tenants_csrf']); ?>">
                    <input type="hidden" name="action" value="update_tenant">
                    <input type="hidden" name="tenant_id" value="<?= (int) $editTenant['id']; ?>">
                    <div><label for="full_name">Full Name</label><input id="full_name" name="full_name" maxlength="100" value="<?= e($editValues['full_name']); ?>" required></div>
                    <div><label for="contact_number">Contact Number</label><input id="contact_number" name="contact_number" maxlength="20" value="<?= e($editValues['contact_number']); ?>"></div>
                    <div>
                        <label for="tenant_status">Tenant Status</label>
                        <select id="tenant_status" name="status" required>
                            <?php foreach (['active', 'pending', 'inactive', 'rejected'] as $statusOption): ?>
                                <option value="<?= e($statusOption); ?>" <?= $editValues['status'] === $statusOption ? 'selected' : ''; ?>><?= e(ucfirst($statusOption)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tenant-form-actions"><button type="submit" class="tenant-button tenant-button-primary">Save Changes</button><a class="tenant-button" href="tenants.php">Cancel</a></div>
                </form>
            <?php else: ?>
                <h2 id="edit-tenant-heading">Tenant Not Found</h2>
                <p class="tenant-intro">The selected tenant could not be found.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($viewId > 0): ?>
        <section class="tenant-panel" aria-labelledby="tenant-profile-heading">
            <?php if ($viewTenant): ?>
                <h2 id="tenant-profile-heading"><?= e($viewTenant['full_name']); ?></h2>
                <p class="tenant-intro">Tenant profile and current rental information.</p>
                <div class="tenant-details">
                    <div class="tenant-detail"><span>Email</span><strong><?= e($viewTenant['email'] ?: 'Not provided'); ?></strong></div>
                    <div class="tenant-detail"><span>Contact Number</span><strong><?= e($viewTenant['contact_number'] ?: 'Not provided'); ?></strong></div>
                    <div class="tenant-detail"><span>Tenant Status</span><strong><?= e(ucfirst($viewTenant['tenant_status'])); ?></strong></div>
                    <div class="tenant-detail"><span>Assigned Room</span><strong><?= $viewTenant['room_number'] ? 'Room ' . e($viewTenant['room_number']) . ' · ' . e($viewTenant['room_type']) : 'Unassigned'; ?></strong></div>
                    <div class="tenant-detail"><span>Room Status</span><strong><?= e($viewTenant['room_status'] ?: 'Not applicable'); ?></strong></div>
                    <div class="tenant-detail"><span>Monthly Rate</span><strong><?= $viewTenant['monthly_rate'] !== null ? '₱' . number_format((float) $viewTenant['monthly_rate'], 2) : 'Not applicable'; ?></strong></div>
                    <div class="tenant-detail"><span>Move-in Date</span><strong><?= e($viewTenant['move_in_date'] ?: 'Not recorded'); ?></strong></div>
                </div>
                <p class="tenant-note">The current schema does not define billing periods or an outstanding rent balance. The amount above is the room's monthly rate, not an amount due.</p>
                <div class="tenant-actions" style="margin-top:14px"><a class="tenant-button" href="tenants.php?edit=<?= (int) $viewTenant['id']; ?>">Edit Details</a><a class="tenant-button" href="tenants.php">Close Profile</a></div>
            <?php else: ?>
                <h2 id="tenant-profile-heading">Tenant Not Found</h2>
                <p class="tenant-intro">The selected tenant could not be found.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="tenant-panel" aria-labelledby="tenant-directory-heading">
        <h2 id="tenant-directory-heading">Tenant Directory</h2>
        <p class="tenant-intro">Search tenant records and review their saved room and rental status. New accounts use the existing registration flow, while room assignment changes are handled in Room Management.</p>
        <form class="tenant-toolbar" method="get" action="tenants.php">
            <input type="search" name="search" value="<?= e($search); ?>" placeholder="Name, room number, contact, or email" aria-label="Search tenant directory">
            <select name="status" aria-label="Filter by tenant status">
                <option value="">All Tenant Statuses</option>
                <?php foreach ($tenantStatuses as $statusOption): ?>
                    <option value="<?= e($statusOption); ?>" <?= $filterStatus === $statusOption ? 'selected' : ''; ?>><?= e(ucfirst($statusOption)); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="room" aria-label="Filter by room assignment">
                <option value="">All Room Assignments</option>
                <option value="assigned" <?= $roomFilter === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                <option value="unassigned" <?= $roomFilter === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
            </select>
            <div style="display:flex;gap:7px"><button type="submit" class="tenant-button tenant-button-primary">Apply Filters</button><a href="tenants.php" class="tenant-button">Reset</a></div>
        </form>
        <div class="tenant-table-wrap">
            <table class="tenant-table">
                <thead>
                    <tr>
                        <th scope="col">Tenant Name</th>
                        <th scope="col">Assigned Room</th>
                        <th scope="col">Contact Number</th>
                        <th scope="col">Monthly Rate</th>
                        <th scope="col">Tenant / Room Status</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($tenants): ?>
                        <?php foreach ($tenants as $tenant): ?>
                            <tr>
                                <td><span class="tenant-name"><?= e($tenant['full_name']); ?></span><span class="tenant-subtext"><?= e($tenant['email'] ?: 'No email on record'); ?></span></td>
                                <td>
                                    <?php if ($tenant['room_id'] !== null): ?>
                                        <?= e($tenant['room_number'] ?: 'Room unavailable'); ?><span class="tenant-subtext"><?= (int) $tenant['branch_lease_count']; ?> active room(s) in this branch · <?= e($tenant['room_type'] ?: ''); ?></span>
                                    <?php else: ?>
                                        Unassigned
                                    <?php endif; ?>
                                </td>
                                <td><?= e($tenant['contact_number'] ?: 'Not provided'); ?></td>
                                <td><?= $tenant['monthly_rate'] !== null ? '₱' . number_format((float) $tenant['monthly_rate'], 2) : '—'; ?><span class="tenant-subtext"><?= $tenant['monthly_rate'] !== null ? 'Monthly Rate' : ''; ?></span></td>
                                <td>
                                    <span class="tenant-status tenant-status-<?= e($tenant['tenant_status']); ?>">Tenant: <?= e(ucfirst($tenant['tenant_status'])); ?></span>
                                    <?php if ($tenant['room_id'] !== null): ?><span class="tenant-subtext">Room: <?= e($tenant['room_status'] ?: 'Unknown'); ?></span><?php endif; ?>
                                </td>
                                <td>
                                    <div class="tenant-actions">
                                        <a class="tenant-button" href="tenants.php?view=<?= (int) $tenant['id']; ?>">View Profile</a>
                                        <a class="tenant-button" href="tenants.php?edit=<?= (int) $tenant['id']; ?>">Edit</a>
                                        <?php if ($tenant['tenant_status'] === 'active' && $tenant['room_id'] === null): ?>
                                            <a class="tenant-button tenant-button-primary" href="rooms.php">Manage Assignment</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td class="tenant-empty" colspan="6"><?= (int) $tenantCounts['total'] === 0 ? 'No tenants have been registered yet.' : 'No tenants match your search or filters.'; ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
