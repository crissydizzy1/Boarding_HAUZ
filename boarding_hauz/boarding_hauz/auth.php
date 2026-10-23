<?php
/** Shared authentication, authorization, and session helpers. */
const TENANT_SESSION_TIMEOUT_SECONDS = 28800;

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookieParams['path'] ?: '/',
        'domain' => $cookieParams['domain'],
        'secure' => (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/includes/db_connect.php';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id'])
        && is_numeric($_SESSION['user_id'])
        && (int) $_SESSION['user_id'] > 0
        && is_string($_SESSION['role'] ?? $_SESSION['user_role'] ?? null);
}

function hasRole($roles): bool
{
    $roles = is_array($roles) ? $roles : [$roles];
    $role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? null;
    if ($role === 'admin') {
        $role = 'super_admin';
    }
    return is_string($role) && in_array($role, $roles, true);
}

function redirectTo(string $path): never
{
    // Only application-relative paths should be passed to this helper.
    if ($path === '' || preg_match('/[\r\n]/', $path) || !str_starts_with($path, '/')) {
        $path = '/boarding_hauz/index.php';
    }
    header('Location: ' . $path, true, 302);
    exit;
}

function currentBranchId(): ?int
{
    $sessionRole = $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';
    if ($sessionRole !== 'landlord' || !isLoggedIn()) {
        $branchId = $_SESSION['active_branch_id'] ?? $_SESSION['branch_id'] ?? null;
        return is_numeric($branchId) && (int) $branchId > 0 ? (int) $branchId : null;
    }

    global $conn;
    $landlordId = (int) $_SESSION['user_id'];
    $requestedBranchId = $_SESSION['active_branch_id'] ?? $_SESSION['branch_id'] ?? null;
    if (is_numeric($requestedBranchId) && (int) $requestedBranchId > 0) {
        $branchId = (int) $requestedBranchId;
        $stmt = $conn->prepare('SELECT id FROM branches WHERE id = ? AND landlord_id = ? LIMIT 1');
        $stmt->bind_param('ii', $branchId, $landlordId);
        $stmt->execute();
        $authorizedBranch = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($authorizedBranch) {
            $_SESSION['active_branch_id'] = $branchId;
            $_SESSION['branch_id'] = $branchId;
            return $branchId;
        }
    }

    $stmt = $conn->prepare('SELECT id FROM branches WHERE landlord_id = ? ORDER BY id LIMIT 1');
    $stmt->bind_param('i', $landlordId);
    $stmt->execute();
    $firstBranch = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$firstBranch) {
        unset($_SESSION['active_branch_id'], $_SESSION['branch_id']);
        return null;
    }

    $branchId = (int) $firstBranch['id'];
    $_SESSION['active_branch_id'] = $branchId;
    $_SESSION['branch_id'] = $branchId;
    return $branchId;
}

function currentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }
    global $conn;
    $userId = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare('SELECT id, username, email, role, status FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

function dashboardPathForRole(?string $role = null): string
{
    $role = $role ?? ($_SESSION['role'] ?? $_SESSION['user_role'] ?? '');
    if ($role === 'super_admin' || $role === 'admin') {
        return '/boarding_hauz/admin/platform_dashboard.php';
    }
    if ($role === 'landlord') {
        return '/boarding_hauz/landlord/dashboard.php';
    }
    if ($role === 'tenant') {
        return '/boarding_hauz/tenant_dashboard.php';
    }
    return '/boarding_hauz/index.php';
}

function requireBranchAccess($branchId): void
{
    if (!isLoggedIn() || !hasRole('landlord') || !is_numeric($branchId) || (int) $branchId <= 0) {
        http_response_code(403);
        exit('You are not authorized to access this branch.');
    }

    global $conn;
    $branchId = (int) $branchId;
    if (currentBranchId() !== $branchId) {
        http_response_code(403);
        exit('You are not authorized to access this branch.');
    }
    $userId = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare('SELECT id FROM branches WHERE id = ? AND landlord_id = ? LIMIT 1');
    $stmt->bind_param('ii', $branchId, $userId);
    $stmt->execute();
    $authorized = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$authorized) {
        http_response_code(403);
        exit('You are not authorized to access this branch.');
    }
}

function requireSelectedBranch(): int
{
    if (!isLoggedIn() || !hasRole('landlord')) {
        http_response_code(403);
        exit('Only landlords can access property-management pages.');
    }
    $branchId = currentBranchId();
    if ($branchId === null) {
        http_response_code(400);
        exit('Select a branch before opening branch management pages.');
    }
    requireBranchAccess($branchId);
    return $branchId;
}

function currentBranchLandlordId(): int
{
    $branchId = requireSelectedBranch();
    global $conn;
    $stmt = $conn->prepare('SELECT landlord_id FROM branches WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $branchId);
    $stmt->execute();
    $branch = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$branch) {
        http_response_code(404);
        exit('The selected branch could not be found.');
    }
    return (int) $branch['landlord_id'];
}

function roomBranchId($roomId): ?int
{
    if (!is_scalar($roomId) || !ctype_digit((string) $roomId) || (int) $roomId <= 0) {
        return null;
    }
    global $conn;
    $id = (int) $roomId;
    $stmt = $conn->prepare("
        SELECT r.branch_id
        FROM rooms r
        INNER JOIN branches b ON b.id = r.branch_id
        INNER JOIN users u ON u.id = b.landlord_id AND u.role = 'landlord' AND u.status = 'active'
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $room = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $room && (int) $room['branch_id'] > 0 ? (int) $room['branch_id'] : null;
}

function roomReturnPath($roomId, $branchId = null): ?string
{
    if ($branchId !== null && (!is_scalar($branchId) || !ctype_digit((string) $branchId) || (int) $branchId <= 0)) {
        return null;
    }
    $actualBranchId = roomBranchId($roomId);
    if ($actualBranchId === null || ($branchId !== null && (int) $branchId !== $actualBranchId)) {
        return null;
    }

    return '/boarding_hauz/room-details.php?id=' . (int) $roomId . '&branch_id=' . $actualBranchId;
}

function requireRole($roles): void
{
    $roles = is_array($roles) ? $roles : [$roles];
    if (!isLoggedIn()) {
        redirectTo('/boarding_hauz/login.php');
    }

    if (!hasRole($roles)) {
        redirectTo(dashboardPathForRole());
    }

    $sessionRole = $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';
    if ($sessionRole === 'tenant') {
        $now = time();
        $loginTime = $_SESSION['tenant_login_time'] ?? null;
        $lastActivity = $_SESSION['tenant_last_activity'] ?? null;
        if (!is_int($loginTime) || !is_int($lastActivity)
            || $loginTime > $now || $lastActivity > $now
            || ($now - $loginTime) >= TENANT_SESSION_TIMEOUT_SECONDS
            || ($now - $lastActivity) >= TENANT_SESSION_TIMEOUT_SECONDS) {
            logOut(true);
        }
        $_SESSION['tenant_last_activity'] = $now;
    }

    $user = currentUser();
    $storedRole = $user['role'] ?? '';
    $roleMatchesSession = $storedRole === $sessionRole
        || ($storedRole === 'admin' && $sessionRole === 'super_admin');
    if (!$user || ($user['status'] ?? '') !== 'active' || !$roleMatchesSession) {
        logOut();
    }

    if ($sessionRole === 'landlord') {
        $branchId = currentBranchId();
        if ($branchId === null) {
            http_response_code(403);
            exit('No branch is assigned to this landlord account.');
        }
        requireBranchAccess($branchId);
    } elseif ($sessionRole === 'tenant') {
        global $conn;
        $userId = (int) $_SESSION['user_id'];
        $stmt = $conn->prepare('SELECT status FROM tenants WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $tenant = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$tenant || $tenant['status'] !== 'active') {
            logOut();
        }
    }
}

function logOut(bool $sessionExpired = false): never
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
    redirectTo($sessionExpired
        ? '/boarding_hauz/login.php?expired=1'
        : '/boarding_hauz/login.php');
}
