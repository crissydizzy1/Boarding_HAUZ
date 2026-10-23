<?php
/*
 * Prevent browsers from serving a cached copy of admin pages.
 * Without this, clicking the Back button after Logout can show
 * a stale admin page from the browser's cache.
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$admin_page = basename($_SERVER['PHP_SELF']);
$admin_user = currentUser();
$admin_name = $admin_user['username'] ?? ($_SESSION['user_name'] ?? 'Admin');
$admin_branches = [];
if (hasRole('landlord')) {
    $adminLandlordId = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare('SELECT id, branch_name FROM branches WHERE landlord_id = ? ORDER BY branch_name, id');
    $stmt->bind_param('i', $adminLandlordId);
    $stmt->execute();
    $admin_branches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
if (hasRole('landlord')) {
    if (empty($_SESSION['branch_switch_csrf'])) {
        $_SESSION['branch_switch_csrf'] = bin2hex(random_bytes(32));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($admin_title ?? 'Admin'); ?> | Boarding Hauz</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/boarding_hauz/assets/css/style.css?v=<?php echo (int) filemtime(__DIR__ . '/../assets/css/style.css'); ?>">

    <style>
        /* =========================================================
           ADMIN NAVIGATION
           Boarding Hauz admin-only nav. Stays inside /admin/ at all
           times except Logout, which returns to the unified login page.
           ========================================================= */

        :root {
            --admin-primary: #22b486;
            --admin-primary-dark: #1aa27a;
            --admin-secondary: #236b67;
            --admin-background: #edf9fc;
            --admin-text: #0d1c1e;
            --admin-outline: #d7e6ea;
        }

        .admin-branch-switch {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .admin-branch-switch select,
        .admin-branch-switch button {
            min-height: 34px;
            padding: 6px 8px;
            border: 1px solid var(--admin-outline);
            border-radius: 6px;
            background: #fff;
            color: var(--admin-text);
            font: 600 12px 'Inter', sans-serif;
        }

        .admin-branch-switch button {
            cursor: pointer;
        }

        .admin-branch-switch .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        body {
            margin: 0;
            background: var(--admin-background);
            color: var(--admin-text);
            font-family: 'Inter', sans-serif;
        }

        .admin-header {
            background: #ffffff;
            border-bottom: 1px solid var(--admin-outline);
        }

        .admin-nav {
            max-width: 1200px;
            margin: 0 auto;
            min-height: 68px;
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
        }

        /* ---------- Logo ---------- */

        .admin-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--admin-text);
            text-decoration: none;
            white-space: nowrap;
        }

        /* ---------- Center nav links ---------- */

        .admin-nav-links {
            display: flex;
            align-items: center;
            gap: 4px;
            flex: 1;
            justify-content: center;
        }

        .admin-nav-links a {
            padding: 8px 14px;
            border-radius: 20px;
            color: var(--admin-text);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 600;
            white-space: nowrap;
            transition: background-color 0.15s ease, color 0.15s ease;
        }

        .admin-nav-links a:hover {
            background: var(--admin-background);
            color: var(--admin-secondary);
        }

        .admin-nav-links a.active {
            background: var(--admin-background);
            color: var(--admin-secondary);
        }

        /* ---------- Right side: Dashboard + Logout ---------- */

        .admin-nav-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .admin-dashboard-link {
            padding: 8px 14px;
            border-radius: 20px;
            color: var(--admin-text);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 600;
            white-space: nowrap;
        }

        .admin-dashboard-link:hover,
        .admin-dashboard-link.active {
            background: var(--admin-background);
            color: var(--admin-secondary);
        }

        .admin-logout {
            padding: 9px 18px;
            border-radius: 20px;
            background: var(--admin-primary);
            color: #ffffff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
            transition: background-color 0.15s ease;
        }

        .admin-logout:hover {
            background: var(--admin-primary-dark);
        }

        /* ---------- Responsive ---------- */

        @media (max-width: 900px) {
            .admin-nav {
                flex-wrap: wrap;
                padding-top: 12px;
                padding-bottom: 12px;
            }

            .admin-nav-links {
                order: 3;
                width: 100%;
                justify-content: flex-start;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body class="admin-portal">

<header class="admin-header">
    <nav class="admin-nav">

        <!-- The shared navigation routes users to the dashboard for their role. -->
        <?php $dashboardPath = dashboardPathForRole(); ?>
        <a href="<?php echo e($dashboardPath); ?>" class="admin-brand">
            <img class="brand-logo" src="/boarding_hauz/assets/images/boarding-hauz-icon.png" alt="">
            <span>Boarding Hauz</span>
        </a>

        <div class="admin-nav-links">
            <?php if (hasRole('super_admin')): ?>
                <a href="/boarding_hauz/admin/landlords.php" class="<?php echo $admin_page === 'landlords.php' ? 'active' : ''; ?>">Landlords</a>
                <a href="/boarding_hauz/admin/public_inquiries.php" class="<?php echo $admin_page === 'public_inquiries.php' ? 'active' : ''; ?>">Inquiries</a>
                <a href="/boarding_hauz/admin/platform_reports.php" class="<?php echo $admin_page === 'platform_reports.php' ? 'active' : ''; ?>">Reports</a>
            <?php endif; ?>
            <?php if (hasRole('landlord')): ?>
            <a href="/boarding_hauz/landlord/tenants.php" class="<?php echo $admin_page === 'tenants.php' ? 'active' : ''; ?>">Tenants</a>
            <a href="/boarding_hauz/landlord/rooms.php" class="<?php echo $admin_page === 'rooms.php' ? 'active' : ''; ?>">Rooms</a>
            <a href="/boarding_hauz/landlord/payments.php" class="<?php echo $admin_page === 'payments.php' ? 'active' : ''; ?>">Payments</a>
            <a href="/boarding_hauz/landlord/maintenance.php" class="<?php echo $admin_page === 'maintenance.php' ? 'active' : ''; ?>">Maintenance</a>
            <a href="/boarding_hauz/landlord/inquiries.php" class="<?php echo $admin_page === 'inquiries.php' ? 'active' : ''; ?>">Inquiries</a>
            <a href="/boarding_hauz/landlord/messages.php" class="<?php echo $admin_page === 'messages.php' ? 'active' : ''; ?>">Messages</a>
            <?php endif; ?>
        </div>

        <div class="admin-nav-right">
            <?php if (hasRole('landlord') && count($admin_branches) > 1): ?>
                <form method="post" action="/boarding_hauz/landlord/branch-switch.php" class="admin-branch-switch">
                    <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['branch_switch_csrf']); ?>">
                    <label class="visually-hidden" for="admin-branch-select">Active branch</label>
                    <select id="admin-branch-select" name="branch_id" onchange="this.form.submit()">
                        <?php foreach ($admin_branches as $branch): ?>
                            <option value="<?php echo (int) $branch['id']; ?>" <?php echo currentBranchId() === (int) $branch['id'] ? 'selected' : ''; ?>>
                                <?php echo e($branch['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit">Switch</button></noscript>
                </form>
            <?php endif; ?>
            <a href="<?php echo e($dashboardPath); ?>" class="admin-dashboard-link <?php echo in_array($admin_page, ['dashboard.php', 'platform_dashboard.php'], true) ? 'active' : ''; ?>">Dashboard</a>

              <!-- LOGOUT: goes through admin/admin_logout.php, which destroys the session. -->
              <a href="/boarding_hauz/admin/admin_logout.php" class="admin-logout">Logout</a>
        </div>

    </nav>
</header>
