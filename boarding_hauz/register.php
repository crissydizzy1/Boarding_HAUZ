<?php
require_once __DIR__ . '/auth.php';

$returnRoomId = is_scalar($_GET['return_room'] ?? null)
    ? (string) $_GET['return_room']
    : '';
$returnBranchId = is_scalar($_GET['return_branch'] ?? null)
    ? (string) $_GET['return_branch']
    : null;
if ($returnBranchId === null && $returnRoomId !== '') {
    $actualReturnBranchId = roomBranchId($returnRoomId);
    $returnBranchId = $actualReturnBranchId === null ? null : (string) $actualReturnBranchId;
}
$returnRoomPath = roomReturnPath($returnRoomId, $returnBranchId);

if (isLoggedIn()) {
    if (($_SESSION['user_role'] ?? '') === 'tenant' && $returnRoomPath !== null) {
        redirectTo($returnRoomPath);
    }
    redirectTo(dashboardPathForRole());
}

$errors = [];
$availableBranches = [];
try {
    $branchStmt = $conn->prepare("
        SELECT b.id, b.branch_name, b.location
        FROM branches b
        INNER JOIN users u ON u.id = b.landlord_id
        WHERE u.role IN ('landlord', 'admin') AND u.status = 'active'
        ORDER BY b.branch_name, b.location, b.id
    ");
    $branchStmt->execute();
    $availableBranches = $branchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $branchStmt->close();
} catch (mysqli_sql_exception $exception) {
    error_log('Tenant registration could not load available branches: ' . $exception->getMessage());
    $errors[] = 'Tenant registration is temporarily unavailable because property data is not ready. Please contact the system administrator.';
}

if (empty($_SESSION['tenant_register_csrf'])) {
    $_SESSION['tenant_register_csrf'] = bin2hex(random_bytes(32));
}

$form = [
    'full_name' => '',
    'contact_number' => '',
    'username' => '',
    'email' => '',
    'branch_id' => '',
];
$success = isset($_GET['registered']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['full_name'] = is_string($_POST['full_name'] ?? null) ? trim($_POST['full_name']) : '';
    $form['contact_number'] = is_string($_POST['contact_number'] ?? null) ? trim($_POST['contact_number']) : '';
    $form['username'] = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $form['email'] = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
    $form['branch_id'] = is_string($_POST['branch_id'] ?? null) ? trim($_POST['branch_id']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
    $postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';

    if (!hash_equals($_SESSION['tenant_register_csrf'], $postedToken)) {
        $errors[] = 'Your session could not be verified. Refresh the page and try again.';
    }
    if ($form['full_name'] === '') {
        $errors[] = 'Full name is required.';
    } elseif (strlen($form['full_name']) > 100) {
        $errors[] = 'Full name must be 100 characters or fewer.';
    }
    if (strlen($form['contact_number']) > 20) {
        $errors[] = 'Contact number must be 20 characters or fewer.';
    }
    if ($form['username'] === '') {
        $errors[] = 'Username is required.';
    } elseif (strlen($form['username']) > 50) {
        $errors[] = 'Username must be 50 characters or fewer.';
    }
    if ($form['email'] === '' || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    } elseif (strlen($form['email']) > 255) {
        $errors[] = 'Email address must be 255 characters or fewer.';
    }
    $selectedBranch = null;
    if (ctype_digit($form['branch_id'])) {
        foreach ($availableBranches as $availableBranch) {
            if ((int) $availableBranch['id'] === (int) $form['branch_id']) {
                $selectedBranch = $availableBranch;
                break;
            }
        }
    }
    if (!$selectedBranch) {
        $errors[] = $availableBranches
            ? 'Choose a valid property to manage your registration.'
            : 'Tenant registration is temporarily unavailable because no properties are accepting applications.';
    }
    if ($password === '') {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        try {
            $check = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
            $check->bind_param('ss', $form['username'], $form['email']);
            $check->execute();
            $duplicate = $check->get_result()->fetch_assoc();
            $check->close();

            if ($duplicate) {
                $errors[] = 'That username or email is already registered.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                if (!is_string($hash)) {
                    throw new RuntimeException('Your account could not be secured. Please try again.');
                }
                $role = 'tenant';
                $status = 'active';
                $stmt = $conn->prepare('INSERT INTO users (username, password, role, email, status) VALUES (?, ?, ?, ?, ?)');
                $stmt->bind_param('sssss', $form['username'], $hash, $role, $form['email'], $status);
                $stmt->execute();
                $newUserId = (int) $conn->insert_id;
                $stmt->close();

                try {
                    $branchId = (int) $selectedBranch['id'];
                    $tenantContactNumber = $form['contact_number'] === '' ? null : $form['contact_number'];
                    $tenantStatus = 'pending';
                    $tenantStmt = $conn->prepare("
                        INSERT INTO tenants (branch_id, user_id, full_name, contact_number, status)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $tenantStmt->bind_param(
                        'iisss',
                        $branchId,
                        $newUserId,
                        $form['full_name'],
                        $tenantContactNumber,
                        $tenantStatus
                    );
                    $tenantStmt->execute();
                    $tenantStmt->close();
                } catch (mysqli_sql_exception $tenantException) {
                    try {
                        $cleanupStmt = $conn->prepare('DELETE FROM users WHERE id = ? LIMIT 1');
                        $cleanupStmt->bind_param('i', $newUserId);
                        $cleanupStmt->execute();
                        $cleanupStmt->close();
                    } catch (mysqli_sql_exception $cleanupException) {
                        error_log('Failed to clean up unlinked tenant account: ' . $cleanupException->getMessage());
                    }
                    throw $tenantException;
                }

                redirectTo('/boarding_hauz/login.php?registered=1' . ($returnRoomPath !== null
                    ? '&return_room=' . rawurlencode($returnRoomId) . '&return_branch=' . rawurlencode((string) $returnBranchId)
                    : ''));
            }
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        } catch (mysqli_sql_exception $exception) {
            error_log('Tenant registration failed: ' . $exception->getMessage());
            $errors[] = (int) $exception->getCode() === 1062
                ? 'That username or email is already registered.'
                : 'Registration could not be completed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Tenant Account | Boarding Hauz</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/boarding_hauz/assets/css/style.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/style.css'); ?>">
    <style>
        body.tenant-register-page,
        body.admin-login-page {
            min-height: 100vh;
            background-color: #f8faf9;
            background-image: radial-gradient(#dce9e5 0.75px, transparent 0.75px);
            background-size: 18px 18px;
            color: #24332f;
            font-family: 'Inter', sans-serif;
        }

        .tenant-register-shell {
            display: flex;
            width: 100%;
            min-height: 100vh;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 36px 20px;
        }

        .tenant-register-topbar,
        .tenant-register-content {
            width: min(100%, 390px);
        }

        .tenant-register-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 17px;
            font-size: 12px;
        }

        .tenant-register-back,
        .tenant-register-portal {
            color: #53635d;
            font-weight: 600;
            text-decoration: none;
        }

        .tenant-register-back:hover {
            color: #0f5c4d;
        }

        .tenant-register-portal {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            white-space: nowrap;
        }

        .tenant-register-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #16866f;
        }

        .tenant-register-card {
            overflow: hidden;
            border: 1px solid rgba(15, 92, 77, .13);
            border-radius: 14px 14px 20px 20px;
            background: #fff;
            box-shadow: 0 22px 55px rgba(32, 66, 54, .13);
        }

        .tenant-register-main {
            position: relative;
            padding: 46px 32px 23px;
            background: #0f5c4d;
            color: #fff;
            clip-path: polygon(0 38px, 50% 0, 100% 38px, 100% 100%, 0 100%);
        }

        .tenant-register-brand {
            display: flex;
            justify-content: center;
            margin-bottom: 14px;
        }

        .tenant-register-brand-mark {
            display: grid;
            width: 56px;
            height: 56px;
            place-items: center;
            border-radius: 50%;
            background: #fff;
        }

        .tenant-register-brand-mark img {
            display: block;
            width: auto;
            height: 38px;
        }

        .tenant-register-card h1 {
            margin: 0 0 6px;
            color: #fff;
            font: 700 20px/1.2 'Inter', sans-serif;
            letter-spacing: .025em;
            text-align: center;
        }

        .tenant-register-intro {
            margin: 0 0 18px;
            color: #d6ebe5;
            font-size: 13px;
            line-height: 1.6;
            text-align: center;
        }

        .tenant-register-form {
            display: grid;
            gap: 12px;
        }

        .tenant-register-field label {
            display: block;
            margin: 0 0 6px;
            color: #f1f8f5;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .035em;
        }

        .tenant-register-field label span {
            color: #d6ebe5;
            font-weight: 400;
        }

        .tenant-register-field input {
            display: block;
            width: 100%;
            height: 45px;
            padding: 0 13px;
            border: 1px solid #dce9e5;
            border-radius: 7px;
            outline: none;
            background: #fff;
            color: #24332f;
            font: 400 13px 'Inter', sans-serif;
            transition: border-color .16s ease, box-shadow .16s ease;
        }

        .tenant-register-field input::placeholder {
            color: #98a49f;
        }

        .tenant-register-field input:focus {
            border-color: #81cbb5;
            box-shadow: 0 0 0 3px rgba(204, 241, 227, .2);
        }

        .tenant-register-submit {
            width: 100%;
            min-height: 46px;
            margin-top: 1px;
            border: 0;
            border-radius: 7px;
            background: #fff;
            box-shadow: none;
            color: #0f5c4d;
            cursor: pointer;
            font: 700 13px 'Inter', sans-serif;
            letter-spacing: .035em;
            transition: background-color .16s ease, transform .16s ease;
        }

        .tenant-register-submit:hover {
            transform: translateY(-1px);
            background: #e8f6f1;
        }

        .tenant-register-submit:focus-visible,
        .tenant-register-link:focus-visible,
        .tenant-register-back:focus-visible {
            outline: 3px solid #83d2b8;
            outline-offset: 3px;
        }

        .tenant-register-alert {
            margin-bottom: 13px;
            padding: 8px 11px;
            border: 1px solid #f0c7c1;
            border-radius: 7px;
            background: #fff0ed;
            color: #8a3026;
            font-size: 12px;
            line-height: 1.5;
        }

        .tenant-register-alert div + div {
            margin-top: 4px;
        }

        .tenant-register-login {
            display: flex;
            min-height: 60px;
            align-items: center;
            justify-content: center;
            gap: 3px;
            margin: 0;
            padding: 12px 16px;
            color: #65736e;
            font-size: 12px;
            text-align: center;
        }

        .tenant-register-link {
            color: #16866f;
            font-weight: 700;
            text-decoration: none;
        }

        .tenant-register-link:hover {
            color: #0f5c4d;
            text-decoration: underline;
        }

        @media (max-width: 600px) {
            .tenant-register-shell {
                justify-content: flex-start;
                padding: 24px 16px;
            }

            .tenant-register-topbar {
                margin-bottom: 14px;
                font-size: 11px;
            }

            .tenant-register-main {
                padding: 41px 22px 21px;
                clip-path: polygon(0 31px, 50% 0, 100% 31px, 100% 100%, 0 100%);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .tenant-register-field input,
            .tenant-register-submit {
                transition: none;
            }
        }
    </style>
</head>
<body class="tenant-register-page">
    <main class="tenant-register-shell">
        <div class="tenant-register-topbar">
            <a class="tenant-register-back" href="/boarding_hauz/index.php">← Back to Home</a>
            <span class="tenant-register-portal"><span class="tenant-register-dot" aria-hidden="true"></span>Tenant Portal</span>
        </div>
        <div class="tenant-register-content">
            <section class="tenant-register-card" aria-labelledby="tenant-register-title">
                <div class="tenant-register-main">
                    <div class="tenant-register-brand">
                        <span class="tenant-register-brand-mark">
                            <img src="/boarding_hauz/assets/images/boarding-hauz-icon.png" alt="Boarding Hauz">
                        </span>
                    </div>
                    <h1 id="tenant-register-title">Create Tenant Account</h1>
                    <p class="tenant-register-intro">Choose a property so its landlord can review your tenant registration.</p>

                    <?php if ($errors): ?>
                        <div class="tenant-register-alert" role="alert" aria-live="polite">
                            <?php foreach ($errors as $error): ?>
                                <div><?php echo e($error); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="tenant-register-form">
                        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['tenant_register_csrf']); ?>">
                        <?php if ($returnRoomPath !== null): ?>
                            <input type="hidden" name="return_room" value="<?php echo e($returnRoomId); ?>">
                            <input type="hidden" name="return_branch" value="<?php echo e((string) $returnBranchId); ?>">
                        <?php endif; ?>
                        <div class="tenant-register-field">
                            <label for="full_name">Full Name</label>
                            <input id="full_name" name="full_name" maxlength="100" value="<?php echo e($form['full_name']); ?>" autocomplete="name" required>
                        </div>
                        <div class="tenant-register-field">
                            <label for="contact_number">Contact Number <span>(optional)</span></label>
                            <input id="contact_number" name="contact_number" type="tel" maxlength="20" value="<?php echo e($form['contact_number']); ?>" autocomplete="tel">
                        </div>
                        <div class="tenant-register-field">
                            <label for="username">Username</label>
                            <input id="username" name="username" maxlength="50" value="<?php echo e($form['username']); ?>" autocomplete="username" required>
                        </div>
                        <div class="tenant-register-field">
                            <label for="email">Email Address</label>
                            <input id="email" name="email" type="email" maxlength="255" value="<?php echo e($form['email']); ?>" autocomplete="email" required>
                        </div>
                        <div class="tenant-register-field">
                            <label for="branch_id">Property / Landlord</label>
                            <select id="branch_id" name="branch_id" required <?php echo !$availableBranches ? 'disabled' : ''; ?>>
                                <option value="">Choose a property</option>
                                <?php foreach ($availableBranches as $availableBranch): ?>
                                    <option value="<?php echo (int) $availableBranch['id']; ?>" <?php echo $form['branch_id'] === (string) $availableBranch['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($availableBranch['branch_name'] . ' — ' . $availableBranch['location']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$availableBranches): ?>
                                <span>No properties are currently accepting tenant registrations.</span>
                            <?php endif; ?>
                        </div>
                        <div class="tenant-register-field">
                            <label for="password">Password</label>
                            <input id="password" name="password" type="password" minlength="8" autocomplete="new-password" required>
                        </div>
                        <div class="tenant-register-field">
                            <label for="confirm_password">Confirm Password</label>
                            <input id="confirm_password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required>
                        </div>
                        <button class="tenant-register-submit" type="submit">REGISTER AS TENANT</button>
                    </form>
                </div>

                <div class="tenant-register-login">
                    <span>Already registered?</span>
                    <a class="tenant-register-link" href="login.php<?php echo $returnRoomPath !== null ? '?return_room=' . rawurlencode($returnRoomId) . '&return_branch=' . rawurlencode((string) $returnBranchId) : ''; ?>">Sign In</a>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
