     <?php
require_once __DIR__ . '/../auth.php';
requireRole('super_admin');

$admin_title = 'Landlord Management';
$error = '';
$success = isset($_GET['created'])
    ? 'Landlord account and branch were created.'
    : (isset($_GET['branch_created'])
        ? 'Branch was added to the landlord account.'
        : (isset($_GET['updated'])
            ? 'Landlord account was updated.'
            : (isset($_GET['suspended'])
                ? 'Landlord account was suspended.'
                : (isset($_GET['reactivated']) ? 'Landlord account was reactivated.' : ''))));
$form = [
    'username' => '',
    'email' => '',
    'branch_name' => '',
    'location' => '',
];

if (empty($_SESSION['super_admin_landlords_csrf'])) {
    $_SESSION['super_admin_landlords_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($form) as $field) {
        $form[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
    }
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';

    if (!hash_equals($_SESSION['super_admin_landlords_csrf'], $postedToken)) {
        $error = 'Your session could not be verified. Refresh the page and try again.';
    } elseif (in_array($_POST['action'] ?? '', ['create_landlord', 'update_landlord', 'set_landlord_status'], true)) {
        $error = 'Landlord accounts are managed as a single master account. Add or edit branches instead.';
    } elseif (($_POST['action'] ?? '') === 'update_branch') {
        $branchValue = is_scalar($_POST['branch_id'] ?? null) ? (string) $_POST['branch_id'] : '';
        $targetBranchId = ctype_digit($branchValue) ? (int) $branchValue : 0;
        $targetLandlordValue = is_scalar($_POST['landlord_id'] ?? null) ? (string) $_POST['landlord_id'] : '';
        $targetLandlordId = ctype_digit($targetLandlordValue) ? (int) $targetLandlordValue : 0;
        $branchName = is_string($_POST['branch_name'] ?? null) ? trim($_POST['branch_name']) : '';
        $branchLocation = is_string($_POST['location'] ?? null) ? trim($_POST['location']) : '';
        if ($targetBranchId <= 0 || $targetLandlordId <= 0
            || $branchName === '' || strlen($branchName) > 120
            || $branchLocation === '' || strlen($branchLocation) > 255) {
            $error = 'Enter a valid branch name and location.';
        } else {
            try {
                $landlordRole = 'landlord';
                $stmt = $conn->prepare("
                    SELECT b.id
                    FROM branches b
                    INNER JOIN users u ON u.id = b.landlord_id
                    WHERE b.id = ? AND b.landlord_id = ? AND u.role = ?
                      AND u.username = 'Landlord' AND u.email = 'landlord@boardinghauz.local'
                    LIMIT 1
                ");
                $stmt->bind_param('iis', $targetBranchId, $targetLandlordId, $landlordRole);
                $stmt->execute();
                $validBranch = (bool) $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$validBranch) {
                    throw new RuntimeException('The branch could not be updated. Refresh and try again.');
                }
                $conn->begin_transaction();
                $stmt = $conn->prepare('UPDATE branches SET branch_name = ?, location = ? WHERE id = ? AND landlord_id = ? LIMIT 1');
                $stmt->bind_param('ssii', $branchName, $branchLocation, $targetBranchId, $targetLandlordId);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
                header('Location: /boarding_hauz/admin/landlords.php?updated=1');
                exit;
            } catch (RuntimeException $exception) {
                $conn->rollback();
                $error = $exception->getMessage();
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                error_log('Super admin branch update failed: ' . $exception->getMessage());
                $error = 'The branch could not be updated. Please try again.';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'update_landlord' || ($_POST['action'] ?? '') === 'set_landlord_status') {
        $targetLandlordValue = is_scalar($_POST['landlord_id'] ?? null) ? (string) $_POST['landlord_id'] : '';
        $targetLandlordId = ctype_digit($targetLandlordValue) ? (int) $targetLandlordValue : 0;
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $username = is_string($_POST['edit_username'] ?? null) ? trim($_POST['edit_username']) : '';
        $email = is_string($_POST['edit_email'] ?? null) ? trim($_POST['edit_email']) : '';
        $password = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $status = is_string($_POST['landlord_status'] ?? null) ? $_POST['landlord_status'] : '';

        if ($targetLandlordId <= 0) {
            $error = 'Select a valid landlord account.';
        } elseif (!in_array($action, ['update_landlord', 'set_landlord_status'], true)) {
            $error = 'Invalid landlord management action.';
        } elseif ($action === 'update_landlord' && ($username === '' || strlen($username) > 100)) {
            $error = 'Enter a username of 1 to 100 characters.';
        } elseif ($action === 'update_landlord' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255)) {
            $error = 'Enter a valid email address no longer than 255 characters.';
        } elseif ($action === 'update_landlord' && $password !== '' && (strlen($password) < 12 || strlen($password) > 4096)) {
            $error = 'A replacement password must be at least 12 characters.';
        } elseif ($action === 'set_landlord_status' && !in_array($status, ['active', 'inactive'], true)) {
            $error = 'Choose a valid landlord account status.';
        } else {
            try {
                if ($action === 'update_landlord') {
                    $stmt = $conn->prepare('SELECT id FROM users WHERE id = ? AND role = ? LIMIT 1');
                    $landlordRole = 'landlord';
                    $stmt->bind_param('is', $targetLandlordId, $landlordRole);
                    $stmt->execute();
                    $validLandlord = (bool) $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$validLandlord) {
                        throw new RuntimeException('The selected landlord account could not be found.');
                    }

                    $conn->begin_transaction();
                    if ($password !== '') {
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        if (!is_string($passwordHash)) {
                            throw new RuntimeException('The replacement password could not be secured.');
                        }
                        $stmt = $conn->prepare('UPDATE users SET username = ?, email = ?, password = ? WHERE id = ? AND role = ? LIMIT 1');
                        $stmt->bind_param('sssis', $username, $email, $passwordHash, $targetLandlordId, $landlordRole);
                    } else {
                        $stmt = $conn->prepare('UPDATE users SET username = ?, email = ? WHERE id = ? AND role = ? LIMIT 1');
                        $stmt->bind_param('ssis', $username, $email, $targetLandlordId, $landlordRole);
                    }
                    $stmt->execute();
                    $stmt->close();
                    $conn->commit();
                    header('Location: /boarding_hauz/admin/landlords.php?updated=1');
                    exit;
                }

                $conn->begin_transaction();
                $stmt = $conn->prepare('UPDATE users SET status = ? WHERE id = ? AND role = ? LIMIT 1');
                $landlordRole = 'landlord';
                $stmt->bind_param('sis', $status, $targetLandlordId, $landlordRole);
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    $stmt->close();
                    throw new RuntimeException('The landlord account was not changed. It may already have that status.');
                }
                $stmt->close();
                $conn->commit();
                header('Location: /boarding_hauz/admin/landlords.php?' . ($status === 'inactive' ? 'suspended' : 'reactivated') . '=1');
                exit;
            } catch (RuntimeException $exception) {
                $conn->rollback();
                $error = $exception->getMessage();
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                error_log('Super admin landlord update failed: ' . $exception->getMessage());
                $error = (int) $exception->getCode() === 1062
                    ? 'That username or email is already in use.'
                    : 'The landlord account could not be updated. Please try again.';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'create_branch') {
        $targetLandlordValue = is_scalar($_POST['landlord_id'] ?? null) ? (string) $_POST['landlord_id'] : '';
        $targetLandlordId = ctype_digit($targetLandlordValue) ? (int) $targetLandlordValue : 0;
        $branchName = is_string($_POST['new_branch_name'] ?? null) ? trim($_POST['new_branch_name']) : '';
        $branchLocation = is_string($_POST['new_branch_location'] ?? null) ? trim($_POST['new_branch_location']) : '';
        if ($targetLandlordId <= 0 || $branchName === '' || strlen($branchName) > 120
            || $branchLocation === '' || strlen($branchLocation) > 255) {
            $error = 'Choose a landlord and enter a valid branch name and location.';
        } else {
            try {
                $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'landlord' AND status = 'active' AND username = 'Landlord' AND email = 'landlord@boardinghauz.local' LIMIT 1");
                $stmt->bind_param('i', $targetLandlordId);
                $stmt->execute();
                $validLandlord = (bool) $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$validLandlord) {
                    throw new RuntimeException('Select a valid landlord account.');
                }
                $conn->begin_transaction();
                $stmt = $conn->prepare('INSERT INTO branches (landlord_id, branch_name, location) VALUES (?, ?, ?)');
                $stmt->bind_param('iss', $targetLandlordId, $branchName, $branchLocation);
                $stmt->execute();
                $branchId = (int) $conn->insert_id;
                $stmt->close();
                $conn->commit();
                header('Location: /boarding_hauz/admin/landlords.php?branch_created=1');
                exit;
            } catch (RuntimeException $exception) {
                $conn->rollback();
                $error = $exception->getMessage();
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                error_log('Super admin branch creation failed: ' . $exception->getMessage());
                $error = 'The branch could not be created. Please try again.';
            }
        }
    } elseif (($_POST['action'] ?? '') !== 'create_landlord') {
        $error = 'Invalid landlord management action.';
    } elseif ($form['username'] === '' || strlen($form['username']) > 100) {
        $error = 'Enter a username of 1 to 100 characters.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || strlen($form['email']) > 255) {
        $error = 'Enter a valid email address no longer than 255 characters.';
    } elseif (strlen($password) < 12 || strlen($password) > 4096) {
        $error = 'The landlord password must be at least 12 characters.';
    } elseif ($form['branch_name'] === '' || strlen($form['branch_name']) > 120) {
        $error = 'Enter a branch name of 1 to 120 characters.';
    } elseif ($form['location'] === '' || strlen($form['location']) > 255) {
        $error = 'Enter a branch location of 1 to 255 characters.';
    } else {
        try {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($passwordHash)) {
                throw new RuntimeException('The password could not be secured.');
            }

            $conn->begin_transaction();
            $role = 'landlord';
            $status = 'active';
            $createdBy = (int) $_SESSION['user_id'];
            $stmt = $conn->prepare('INSERT INTO users (username, email, password, role, status, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sssssi', $form['username'], $form['email'], $passwordHash, $role, $status, $createdBy);
            $stmt->execute();
            $landlordId = (int) $conn->insert_id;
            $stmt->close();

            $stmt = $conn->prepare('INSERT INTO branches (landlord_id, branch_name, location) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $landlordId, $form['branch_name'], $form['location']);
            $stmt->execute();
            $branchId = (int) $conn->insert_id;
            $stmt->close();
            $conn->commit();

            header('Location: /boarding_hauz/admin/landlords.php?created=1');
            exit;
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            error_log('Super admin landlord creation failed: ' . $exception->getMessage());
            $error = (int) $exception->getCode() === 1062
                ? 'That username or email is already in use.'
                : 'The landlord account could not be created. Please try again.';
        } catch (RuntimeException $exception) {
            $conn->rollback();
            error_log('Super admin landlord creation failed: ' . $exception->getMessage());
            $error = 'The landlord account could not be created. Please try again.';
        }
    }
}

$landlordSearch = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
$landlordSearch = substr($landlordSearch, 0, 100);
$landlordStatusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if (!in_array($landlordStatusFilter, ['active', 'inactive'], true)) {
    $landlordStatusFilter = '';
}
$requestedPage = is_scalar($_GET['page'] ?? null) ? (string) $_GET['page'] : '1';
$requestedPage = ctype_digit($requestedPage) ? max(1, (int) $requestedPage) : 1;
$pageSize = 10;
$landlordSearchPattern = '%' . $landlordSearch . '%';
$landlordStatusParameter = $landlordStatusFilter;
$landlordSearchParameter = $landlordSearch;
$landlordCountStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM users u
    WHERE u.role = 'landlord'
      AND (? = '' OR u.status = ?)
      AND (
          ? = '' OR u.username LIKE ? OR u.email LIKE ?
          OR EXISTS (
              SELECT 1 FROM branches search_branch
              WHERE search_branch.landlord_id = u.id
                AND (search_branch.branch_name LIKE ? OR search_branch.location LIKE ?)
          )
      )
");
$landlordCountStmt->bind_param(
    'sssssss',
    $landlordStatusParameter,
    $landlordStatusParameter,
    $landlordSearchParameter,
    $landlordSearchPattern,
    $landlordSearchPattern,
    $landlordSearchPattern,
    $landlordSearchPattern
);
$landlordCountStmt->execute();
$landlordCount = (int) ($landlordCountStmt->get_result()->fetch_assoc()['total'] ?? 0);
$landlordCountStmt->close();
$totalPages = max(1, (int) ceil($landlordCount / $pageSize));
$page = min($requestedPage, $totalPages);
$offset = ($page - 1) * $pageSize;
$landlords = [];
$stmt = $conn->prepare("
    SELECT u.id, u.username, u.email, u.status, u.created_at
    FROM users u
    WHERE u.role = 'landlord'
      AND (? = '' OR u.status = ?)
      AND (
          ? = '' OR u.username LIKE ? OR u.email LIKE ?
          OR EXISTS (
              SELECT 1 FROM branches search_branch
              WHERE search_branch.landlord_id = u.id
                AND (search_branch.branch_name LIKE ? OR search_branch.location LIKE ?)
          )
      )
    ORDER BY u.created_at DESC, u.id DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param(
    'sssssssii',
    $landlordStatusParameter,
    $landlordStatusParameter,
    $landlordSearchParameter,
    $landlordSearchPattern,
    $landlordSearchPattern,
    $landlordSearchPattern,
    $landlordSearchPattern,
    $pageSize,
    $offset
);
$stmt->execute();
$landlords = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$branchesByLandlord = [];
if ($landlords) {
    $landlordIds = array_map(static fn(array $landlord): int => (int) $landlord['id'], $landlords);
    $placeholders = implode(',', array_fill(0, count($landlordIds), '?'));
    $branchStmt = $conn->prepare("
        SELECT id, landlord_id, branch_name, location
        FROM branches
        WHERE landlord_id IN ($placeholders)
        ORDER BY landlord_id, id
    ");
    $branchTypes = str_repeat('i', count($landlordIds));
    $branchParameters = [$branchTypes];
    foreach ($landlordIds as $index => $landlordId) {
        $branchParameters[] = &$landlordIds[$index];
    }
    call_user_func_array([$branchStmt, 'bind_param'], $branchParameters);
    $branchStmt->execute();
    foreach ($branchStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $branch) {
        $branchesByLandlord[(int) $branch['landlord_id']][] = $branch;
    }
    $branchStmt->close();
}
$landlordAccounts = [];
$stmt = $conn->prepare("SELECT id, username, email FROM users WHERE role = 'landlord' AND status = 'active' AND username = 'Landlord' AND email = 'landlord@boardinghauz.local' ORDER BY id");
$stmt->execute();
$landlordAccounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include __DIR__ . '/admin_header.php';
?>
<main class="admin-page">
    <header class="admin-page-header">
        <span class="admin-page-label">SUPER ADMIN</span>
        <h1>Landlords &amp; Branches</h1>
        <p>One master Landlord account manages the registered South Cotabato branches. Add branches here; branch access and property records stay isolated by the selected branch.</p>
    </header>

    <?php if ($success !== ''): ?>
        <p class="landlord-alert success" role="status"><?= e($success); ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="landlord-alert error" role="alert"><?= e($error); ?></p>
    <?php endif; ?>

    <section class="landlord-card">
        <h2>Add branch to the master Landlord account</h2>
        <form method="post" class="landlord-form">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['super_admin_landlords_csrf']); ?>">
            <input type="hidden" name="action" value="create_branch">
            <?php if ($landlordAccounts): ?>
                <input type="hidden" name="landlord_id" value="<?= (int) $landlordAccounts[0]['id']; ?>">
                <p class="full-width">New branch will belong to <?= e($landlordAccounts[0]['username']); ?>.</p>
            <?php else: ?>
                <p class="landlord-alert error full-width">The master Landlord account has not been seeded yet.</p>
            <?php endif; ?>
            <label>Branch name<input name="new_branch_name" maxlength="120" required></label>
            <label class="full-width">Branch location<input name="new_branch_location" maxlength="255" required></label>
            <button type="submit" <?= !$landlordAccounts ? 'disabled' : ''; ?>>Add branch</button>
        </form>
    </section>

    <section class="landlord-card">
        <h2>Existing landlords</h2>
        <form method="get" class="landlord-filter">
            <label>Search landlords
                <input type="search" name="search" maxlength="100" value="<?= e($landlordSearch); ?>" placeholder="Username, email, property, or location">
            </label>
            <label>Account status
                <select name="status">
                    <option value="" <?= $landlordStatusFilter === '' ? 'selected' : ''; ?>>All</option>
                    <option value="active" <?= $landlordStatusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?= $landlordStatusFilter === 'inactive' ? 'selected' : ''; ?>>Suspended</option>
                </select>
            </label>
            <button type="submit">Apply filters</button>
        </form>
        <p class="landlord-result-count"><?= number_format($landlordCount); ?> matching landlord<?= $landlordCount === 1 ? '' : 's'; ?> · Page <?= $page; ?> of <?= $totalPages; ?></p>
        <?php if (!$landlords): ?>
            <p class="landlord-empty"><?= $landlordSearch !== '' || $landlordStatusFilter !== '' ? 'No landlord accounts match those filters.' : 'No landlord accounts have been created.'; ?></p>
        <?php else: ?>
            <div class="landlord-table-wrap">
                <table class="landlord-table">
                    <thead><tr><th>Username and email</th><th>Status</th><th>Branches and locations</th><th>Created</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($landlords as $landlord): ?>
                        <?php $landlordBranches = $branchesByLandlord[(int) $landlord['id']] ?? []; ?>
                        <tr>
                            <td>
                                <strong><?= e($landlord['username']); ?></strong>
                                <span class="landlord-email"><?= e($landlord['email']); ?></span>
                            </td>
                            <td>
                                <span class="landlord-status <?= $landlord['status'] === 'active' ? 'is-active' : ($landlord['status'] === 'inactive' ? 'is-suspended' : 'is-pending'); ?>">
                                    <?= e($landlord['status'] === 'inactive' ? 'Suspended' : ucfirst($landlord['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($landlordBranches): ?>
                                    <ul class="landlord-branch-list">
                                        <?php foreach ($landlordBranches as $branch): ?>
                                            <li><strong><?= e($branch['branch_name']); ?></strong><span><?= e($branch['location']); ?></span></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <span class="landlord-email">No branch</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($landlord['created_at']); ?></td>
                            <td>
                                <details class="landlord-actions">
                                    <summary>Manage</summary>
                                    <div class="landlord-action-panel">
                                        <p class="landlord-email">Master account credentials are fixed and cannot be changed or suspended here.</p>
                                        <?php foreach ($landlordBranches as $branch): ?>
                                            <form method="post" class="landlord-row-form landlord-branch-form">
                                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['super_admin_landlords_csrf']); ?>">
                                                <input type="hidden" name="action" value="update_branch">
                                                <input type="hidden" name="landlord_id" value="<?= (int) $landlord['id']; ?>">
                                                <input type="hidden" name="branch_id" value="<?= (int) $branch['id']; ?>">
                                                <strong>Edit <?= e($branch['branch_name']); ?></strong>
                                                <label>Branch name<input name="branch_name" maxlength="120" value="<?= e($branch['branch_name']); ?>" required></label>
                                                <label>Branch location<input name="location" maxlength="255" value="<?= e($branch['location']); ?>" required></label>
                                                <button type="submit">Save branch</button>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <nav class="landlord-pagination" aria-label="Landlord result pages">
                <?php if ($page > 1): ?>
                    <a href="?<?= e(http_build_query(['search' => $landlordSearch, 'status' => $landlordStatusFilter, 'page' => $page - 1])); ?>">Previous</a>
                <?php else: ?>
                    <span class="disabled">Previous</span>
                <?php endif; ?>
                <span>Page <?= $page; ?> of <?= $totalPages; ?></span>
                <?php if ($page < $totalPages): ?>
                    <a href="?<?= e(http_build_query(['search' => $landlordSearch, 'status' => $landlordStatusFilter, 'page' => $page + 1])); ?>">Next</a>
                <?php else: ?>
                    <span class="disabled">Next</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>
<style>
    .landlord-card{margin:0 0 18px;padding:22px;border:1px solid #d7e6ea;border-radius:9px;background:#fff}
    .landlord-card h2{margin:0 0 16px;color:#0d1c1e;font:700 21px 'Playfair Display',serif}
    .landlord-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
    .landlord-filter{display:flex;align-items:end;flex-wrap:wrap;gap:10px;margin-bottom:16px}
    .landlord-filter label{display:grid;gap:6px;color:#536466;font-size:12px;font-weight:700}
    .landlord-filter input,.landlord-filter select{box-sizing:border-box;min-height:40px;padding:9px 11px;border:1px solid #d7e6ea;border-radius:6px;background:#fff;font:inherit;font-weight:400}
    .landlord-filter button{min-height:40px;padding:9px 13px;border:0;border-radius:6px;background:#236b67;color:#fff;font-weight:700;cursor:pointer}
    .landlord-form label{display:grid;gap:6px;color:#536466;font-size:12px;font-weight:700}
    .landlord-form input,.landlord-form select,.landlord-row-form input{box-sizing:border-box;min-height:40px;padding:9px 11px;border:1px solid #d7e6ea;border-radius:6px;background:#fff;font:inherit;font-weight:400}
    .landlord-form .full-width{grid-column:1/-1}
    .landlord-form button{justify-self:start;padding:10px 16px;border:0;border-radius:6px;background:#22b486;color:#fff;font-weight:700;cursor:pointer}
    .landlord-alert{padding:11px 13px;border:1px solid;border-radius:6px}
    .landlord-alert.success{border-color:#b8e6d3;background:#eaf8f1;color:#18734f}
    .landlord-alert.error{border-color:#f0c7c7;background:#fff0f0;color:#8a1c1c}
    .landlord-result-count,.landlord-empty{color:#667b7e;font-size:13px}
    .landlord-table-wrap{max-width:100%;overflow-x:auto}
    .landlord-card table{width:100%;min-width:760px;border-collapse:collapse;text-align:left;font-size:13px}
    .landlord-card th,.landlord-card td{padding:11px;border-bottom:1px solid #edf2f3;vertical-align:top}
    .landlord-card td:first-child{min-width:150px}
    .landlord-email{display:block;margin-top:4px;overflow-wrap:anywhere;color:#667b7e;font-size:12px}
    .landlord-status{display:inline-block;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700}
    .landlord-status.is-active{background:#eaf8f1;color:#18734f}
    .landlord-status.is-suspended{background:#fff0f0;color:#8a1c1c}
    .landlord-status.is-pending{background:#fff4d8;color:#806300}
    .landlord-branch-list{display:grid;gap:8px;margin:0;padding:0;list-style:none}
    .landlord-branch-list li{display:grid;gap:3px;overflow-wrap:anywhere}
    .landlord-branch-list li span{color:#667b7e;font-size:12px}
    .landlord-actions{min-width:110px}
    .landlord-actions summary{width:max-content;padding:7px 10px;border-radius:5px;background:#236b67;color:#fff;font-size:12px;font-weight:700;cursor:pointer}
    .landlord-action-panel{display:grid;gap:12px;min-width:240px;max-width:340px;padding:12px;border:1px solid #d7e6ea;border-radius:7px;background:#fff}
    .landlord-row-form{display:grid;gap:7px;padding-bottom:10px;border-bottom:1px solid #edf2f3}
    .landlord-row-form label{display:grid;gap:5px;color:#536466;font-size:12px;font-weight:700}
    .landlord-row-form input{box-sizing:border-box;width:100%;min-height:38px;padding:8px 10px;border:1px solid #d7e6ea;border-radius:6px;background:#fff;font:inherit;font-weight:400}
    .landlord-row-form button{justify-self:start;padding:8px 11px;border:0;border-radius:5px;background:#22b486;color:#fff;font-weight:700;cursor:pointer}
    .landlord-status-form{grid-template-columns:1fr;align-items:start;margin-top:8px}
    .landlord-status-form button{background:#536466}
    .landlord-branch-form>strong{color:#0d1c1e;font-size:12px}
    .landlord-pagination{display:flex;align-items:center;justify-content:center;gap:14px;margin-top:18px;color:#536466;font-size:13px}
    .landlord-pagination a,.landlord-pagination .disabled{padding:8px 11px;border-radius:5px}
    .landlord-pagination a{background:#edf9fc;color:#236b67;font-weight:700;text-decoration:none}
    .landlord-pagination .disabled{color:#9aa8aa}
    @media(max-width:650px){.landlord-form{grid-template-columns:1fr}.landlord-form .full-width{grid-column:auto}.landlord-filter{align-items:stretch;flex-direction:column}.landlord-filter label,.landlord-filter input,.landlord-filter select{width:100%}.landlord-action-panel{min-width:220px}}
</style>
