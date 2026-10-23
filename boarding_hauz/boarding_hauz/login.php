<?php
require_once __DIR__ . '/auth.php';

function validatedRoomRedirect(string $requestedRedirect): ?string
{
    if (!preg_match('/^(inquire\.php\?room_id=|room-details\.php\?id=)([1-9][0-9]*)(?:&branch_id=([1-9][0-9]*))?$/', $requestedRedirect, $matches)) {
        return null;
    }

    $roomId = (int) $matches[2];
    $branchId = roomBranchId($roomId);
    if ($branchId === null || ($matches[3] !== '' && (int) $matches[3] !== $branchId)) {
        return null;
    }

    $page = str_starts_with($matches[1], 'inquire.php') ? 'inquire.php' : 'room-details.php?id';
    $roomQuery = $page === 'inquire.php' ? '?room_id=' . $roomId : '=' . $roomId;
    return '/boarding_hauz/' . $page . $roomQuery . '&branch_id=' . $branchId;
}

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
$requestedRedirect = is_scalar($_GET['redirect'] ?? null)
    ? (string) $_GET['redirect']
    : '';
$safeRedirectPath = null;
$safeRedirectPath = validatedRoomRedirect($requestedRedirect);

if (($_GET['inquiry'] ?? '') === '1') {
    $_SESSION['login_flash'] = 'Please login to send an inquiry for this room.';
}
$loginFlash = is_string($_SESSION['login_flash'] ?? null)
    ? $_SESSION['login_flash']
    : '';
unset($_SESSION['login_flash']);

if (empty($_SESSION['unified_login_csrf'])) {
    $_SESSION['unified_login_csrf'] = bin2hex(random_bytes(32));
}
$loginCsrf = $_SESSION['unified_login_csrf'];

if (isLoggedIn()) {
    if (($_SESSION['user_role'] ?? '') === 'tenant' && $safeRedirectPath !== null) {
        redirectTo($safeRedirectPath);
    }
    if (($_SESSION['user_role'] ?? '') === 'tenant' && $returnRoomPath !== null) {
        redirectTo($returnRoomPath);
    }
    if (hasRole(['landlord', 'super_admin'])) {
        redirectTo(dashboardPathForRole());
    }
    redirectTo('/boarding_hauz/tenant_dashboard.php');
}

$error = '';
$email = '';
$success = isset($_GET['registered']);
$sessionExpired = is_string($_GET['expired'] ?? null) && $_GET['expired'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $postedCsrf = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    $returnRoomId = is_scalar($_POST['return_room'] ?? null)
        ? (string) $_POST['return_room']
        : $returnRoomId;
    $returnBranchId = is_scalar($_POST['return_branch'] ?? null)
        ? (string) $_POST['return_branch']
        : $returnBranchId;
    $returnRoomPath = roomReturnPath($returnRoomId, $returnBranchId);
    $postedRedirect = is_string($_POST['redirect'] ?? null) ? $_POST['redirect'] : '';
    $requestedRedirect = $postedRedirect !== '' ? $postedRedirect : $requestedRedirect;
    $safeRedirectPath = validatedRoomRedirect($requestedRedirect);

    if (!hash_equals($loginCsrf, $postedCsrf)) {
        $error = 'Your session could not be verified. Refresh the page and try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Username/email and password are required.';
    } else {
        $tenantColumns = [];
        $tenantSchemaChecked = false;
        try {
            $columnResult = $conn->query('SHOW COLUMNS FROM tenants');
            while ($column = $columnResult->fetch_assoc()) {
                $tenantColumns[$column['Field']] = true;
            }
            $columnResult->free();
            $tenantSchemaChecked = true;
        } catch (mysqli_sql_exception $exception) {
            error_log('Boarding Hauz could not inspect tenant login schema: ' . $exception->getMessage());
        }

        $hasTenantUserId = isset($tenantColumns['user_id']);
        $tenantStatusSelect = $hasTenantUserId && isset($tenantColumns['status']) ? 't.status' : 'NULL';
        $tenantBranchSelect = $hasTenantUserId && isset($tenantColumns['branch_id']) ? 't.branch_id' : 'NULL';
        $tenantJoin = $hasTenantUserId ? 'LEFT JOIN tenants t ON t.user_id = u.id' : '';

        try {
            $stmt = $conn->prepare("
                SELECT u.id, u.username, u.email, u.password, u.role, u.status,
                    {$tenantStatusSelect} AS tenant_status, {$tenantBranchSelect} AS branch_id
                FROM users u
                {$tenantJoin}
                WHERE u.email = ? OR u.username = ?
                ORDER BY (u.email = ?) DESC
                LIMIT 2
            ");
            $stmt->bind_param('sss', $email, $email, $email);
            $stmt->execute();
            $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } catch (mysqli_sql_exception $exception) {
            error_log('Boarding Hauz login query failed: ' . $exception->getMessage());
            $users = [];
            $error = 'Unable to sign in right now because the account database could not be checked. Please contact the system administrator.';
        }
        $user = count($users) === 1 ? $users[0] : null;

        if ($error === '' && (!$user || !password_verify($password, $user['password']))) {
            $error = 'Invalid email or password.';
        } elseif ($error === '' && $user['status'] !== 'active') {
            $error = 'This account is inactive. Please contact the administrator.';
        } elseif ($error === '') {
            $destination = null;
            if (in_array($user['role'], ['admin', 'super_admin'], true)) {
                $destination = '/boarding_hauz/admin/platform_dashboard.php';
                $branchId = null;
            } elseif ($user['role'] === 'landlord') {
                $landlordId = (int) $user['id'];
                $branchStmt = $conn->prepare('SELECT id FROM branches WHERE landlord_id = ? ORDER BY id LIMIT 1');
                $branchStmt->bind_param('i', $landlordId);
                $branchStmt->execute();
                $branch = $branchStmt->get_result()->fetch_assoc();
                $branchStmt->close();
                if (!$branch) {
                    $error = 'No branch is assigned to this landlord account. Contact the system administrator.';
                } else {
                    $branchId = (int) $branch['id'];
                    $destination = '/boarding_hauz/landlord/dashboard.php';
                }
            } elseif ($user['role'] === 'tenant') {
                if (!$tenantSchemaChecked || !$hasTenantUserId
                    || !isset($tenantColumns['status']) || !isset($tenantColumns['branch_id'])) {
                    $error = 'Tenant sign-in is unavailable because the tenants table is missing required columns. Please apply the tenant database migration.';
                } elseif ($user['tenant_status'] === null) {
                    $error = 'No tenant profile is linked to this account. Contact the property landlord.';
                } elseif ($user['tenant_status'] === 'pending') {
                    $error = 'Your tenant registration is awaiting approval from the selected property landlord.';
                } elseif ($user['tenant_status'] !== 'active') {
                    $error = 'This tenant account is not active. Contact the property landlord.';
                } elseif (empty($user['branch_id'])) {
                    $error = 'No property is assigned to this tenant account. Contact the property landlord.';
                } else {
                    $branchId = (int) $user['branch_id'];
                    $destination = $safeRedirectPath ?? $returnRoomPath ?? '/boarding_hauz/tenant_dashboard.php';
                }
            } else {
                $error = 'This account has an unsupported role. Contact the system administrator.';
            }

            if ($destination !== null && $error === '') {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['user_name'] = $user['username'];
                $_SESSION['name'] = $user['username'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['role'] = $user['role'] === 'admin' ? 'super_admin' : $user['role'];
                $_SESSION['user_role'] = $_SESSION['role'];
                $_SESSION['branch_id'] = $branchId;
                if ($_SESSION['role'] === 'landlord' && $branchId !== null) {
                    $_SESSION['active_branch_id'] = $branchId;
                } else {
                    unset($_SESSION['active_branch_id']);
                }
                if ($_SESSION['role'] === 'tenant') {
                    $_SESSION['tenant_login_time'] = time();
                    $_SESSION['tenant_last_activity'] = $_SESSION['tenant_login_time'];
                } else {
                    unset($_SESSION['tenant_login_time'], $_SESSION['tenant_last_activity']);
                }
                redirectTo($destination);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | Boarding Hauz</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/boarding_hauz/assets/css/style.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/style.css'); ?>">
    <style>
        body.tenant-login-page {
            min-height: 100vh;
            background-color: #f8faf9;
            background-image: radial-gradient(#dce9e5 0.75px, transparent 0.75px);
            background-size: 18px 18px;
            color: #24332f;
            font-family: 'Inter', sans-serif;
        }

        .tenant-login-shell {
            display: flex;
            width: 100%;
            min-height: 100vh;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 36px 20px;
        }

        .tenant-login-topbar,
        .tenant-login-content {
            width: min(100%, 390px);
        }

        .tenant-login-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 17px;
            font-size: 12px;
        }

        .tenant-login-back {
            color: #53635d;
            font-weight: 600;
            text-decoration: none;
        }

        .tenant-login-back:hover {
            color: #0f5c4d;
        }

        .tenant-portal-label {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: #53635d;
            font-weight: 600;
            white-space: nowrap;
        }

        .tenant-portal-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #16866f;
        }

        .tenant-login-card {
            overflow: hidden;
            border: 1px solid rgba(15, 92, 77, .13);
            border-radius: 14px 14px 20px 20px;
            background: #fff;
            box-shadow: 0 22px 55px rgba(32, 66, 54, .13);
        }

        .tenant-login-main {
            position: relative;
            padding: 46px 32px 23px;
            background: #0f5c4d;
            color: #fff;
            clip-path: polygon(0 38px, 50% 0, 100% 38px, 100% 100%, 0 100%);
        }

        .tenant-login-brand {
            display: flex;
            justify-content: center;
            margin-bottom: 14px;
        }

        .tenant-login-brand-mark {
            display: grid;
            width: 56px;
            height: 56px;
            place-items: center;
            border-radius: 50%;
            background: #fff;
        }

        .tenant-login-brand-mark img {
            display: block;
            width: auto;
            height: 38px;
        }

        .tenant-login-heading {
            margin: 0 0 6px;
            color: #fff;
            font: 700 20px/1.2 'Inter', sans-serif;
            letter-spacing: .08em;
            text-align: center;
        }

        .tenant-login-intro {
            margin: 0 0 18px;
            color: #d6ebe5;
            font-size: 13px;
            line-height: 1.6;
            text-align: center;
        }

        .tenant-login-alerts {
            display: grid;
            gap: 8px;
            margin-bottom: 13px;
        }

        .tenant-login-alert {
            padding: 8px 11px;
            border: 1px solid transparent;
            border-radius: 7px;
            font-size: 12px;
            line-height: 1.5;
        }

        .tenant-login-alert.error {
            border-color: #f0c7c1;
            background: #fff0ed;
            color: #8a3026;
        }

        .tenant-login-alert.success {
            border-color: #b8e2cd;
            background: #eaf8f0;
            color: #176141;
        }

        .tenant-login-form {
            display: grid;
            gap: 12px;
        }

        .tenant-login-field label {
            display: block;
            margin: 0 0 6px;
            color: #f1f8f5;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .035em;
        }

        .tenant-login-field input {
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

        .tenant-login-field input::placeholder {
            color: #98a49f;
        }

        .tenant-login-field input:focus {
            border-color: #81cbb5;
            box-shadow: 0 0 0 3px rgba(204, 241, 227, .2);
        }

        .tenant-login-submit {
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

        .tenant-login-submit:hover {
            transform: translateY(-1px);
            background: #e8f6f1;
        }

        .tenant-login-submit:focus-visible,
        .tenant-login-back:focus-visible,
        .tenant-register-link:focus-visible {
            outline: 3px solid #83d2b8;
            outline-offset: 3px;
        }

        .tenant-login-security {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin: 14px 0 0;
            color: #c3ded5;
            font-size: 10px;
        }

        .tenant-login-register {
            display: flex;
            min-height: 60px;
            align-items: center;
            justify-content: center;
            padding: 12px 16px;
            color: #65736e;
            font-size: 12px;
            text-align: center;
        }

        .tenant-register-link {
            margin-left: 3px;
            color: #16866f;
            font-weight: 700;
            text-decoration: none;
        }

        .tenant-register-link:hover {
            color: #0f5c4d;
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .tenant-login-shell {
                justify-content: flex-start;
                padding: 24px 16px;
            }

            .tenant-login-topbar,
            .tenant-login-content {
                width: min(100%, 390px);
            }

            .tenant-login-topbar {
                margin-bottom: 14px;
                font-size: 11px;
            }

            .tenant-login-main {
                padding: 41px 22px 21px;
                clip-path: polygon(0 31px, 50% 0, 100% 31px, 100% 100%, 0 100%);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .tenant-login-field input,
            .tenant-login-submit {
                transition: none;
            }
        }
    </style>
</head>
<body class="tenant-login-page">
    <main class="tenant-login-shell">
        <div class="tenant-login-topbar">
            <a class="tenant-login-back" href="/boarding_hauz/index.php">← Back to Home</a>
            <span class="tenant-portal-label"><span class="tenant-portal-dot" aria-hidden="true"></span>Secure Account Access</span>
        </div>

        <div class="tenant-login-content">
            <section class="tenant-login-card" aria-labelledby="tenant-login-title">
                <div class="tenant-login-main">
                    <div class="tenant-login-brand">
                        <span class="tenant-login-brand-mark">
                            <img src="/boarding_hauz/assets/images/boarding-hauz-icon.png" alt="Boarding Hauz">
                        </span>
                    </div>
                    <h1 class="tenant-login-heading" id="tenant-login-title">WELCOME BACK!</h1>
                    <p class="tenant-login-intro">Sign in with your username or email. You will be taken to the dashboard for your account role.</p>

                    <?php if ($loginFlash !== '' || $success || $error !== '' || $sessionExpired): ?>
                        <div class="tenant-login-alerts" aria-live="polite">
                            <?php if ($sessionExpired): ?>
                                <div class="tenant-login-alert error" role="alert">Your session expired after reaching the 8-hour session limit. Please sign in again.</div>
                            <?php endif; ?>
                            <?php if ($loginFlash !== ''): ?>
                                <div class="tenant-login-alert error" role="alert"><?php echo e($loginFlash); ?></div>
                            <?php endif; ?>
                            <?php if ($success): ?>
                                <div class="tenant-login-alert success" role="status">Registration received. You can log in after your selected property landlord approves your tenant account.</div>
                            <?php endif; ?>
                            <?php if ($error !== ''): ?>
                                <div class="tenant-login-alert error" role="alert"><?php echo e($error); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="tenant-login-form">
                        <input type="hidden" name="csrf_token" value="<?php echo e($loginCsrf); ?>">
                        <?php if ($returnRoomPath !== null): ?>
                            <input type="hidden" name="return_room" value="<?php echo e($returnRoomId); ?>">
                            <input type="hidden" name="return_branch" value="<?php echo e((string) $returnBranchId); ?>">
                        <?php endif; ?>
                        <?php if ($safeRedirectPath !== null): ?>
                            <input type="hidden" name="redirect" value="<?php echo e($requestedRedirect); ?>">
                        <?php endif; ?>

                        <div class="tenant-login-field">
                            <label for="email">Username or Email Address</label>
                            <input id="email" name="email" type="text" maxlength="255" value="<?php echo e($email); ?>" placeholder="Enter your username or email" autocomplete="username" required>
                        </div>
                        <div class="tenant-login-field">
                            <label for="password">Password</label>
                            <input id="password" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" required>
                        </div>
                        <button class="tenant-login-submit" type="submit">LOGIN <span aria-hidden="true">→</span></button>
                    </form>

                    <p class="tenant-login-security"><span aria-hidden="true">🔒</span> Secure role-based session</p>
                </div>

                <div class="tenant-login-register">
                    Need a tenant account?
                    <a class="tenant-register-link" href="register.php<?php echo $returnRoomPath !== null ? '?return_room=' . rawurlencode($returnRoomId) . '&return_branch=' . rawurlencode((string) $returnBranchId) : ''; ?>">Register Now</a>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
