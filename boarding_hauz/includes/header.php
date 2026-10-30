<?php
$current_page = basename($_SERVER['PHP_SELF']);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$sessionRole = strtolower((string) ($_SESSION['user_role'] ?? $_SESSION['role'] ?? ''));
$hasAuthenticatedUser = isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boarding Hauz</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Plus+Jakarta+Sans:wght@600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/boarding_hauz/assets/css/style.css?v=<?php echo (int) filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
</head>
<body>

<header class="site-header<?= $current_page === 'index.php' ? ' home-site-header' : ''; ?>">
    <div class="header-container">
        <a href="/boarding_hauz/index.php" class="brand"><img class="brand-logo" src="/boarding_hauz/assets/images/boarding-hauz-icon.png" alt="">Boarding Hauz</a>

        <nav class="main-nav">
            <a href="/boarding_hauz/index.php" class="<?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">Home</a>
            <a href="/boarding_hauz/rooms.php" class="<?php echo in_array($current_page, ['rooms.php', 'room-details.php', 'inquire.php'], true) ? 'active' : ''; ?>"><?= $current_page === 'index.php' ? 'Available Rooms' : 'Room Directory'; ?></a>
            <a href="/boarding_hauz/about.php" class="<?php echo ($current_page == 'about.php') ? 'active' : ''; ?>">About Us</a>
            <a href="/boarding_hauz/contact.php" class="<?php echo ($current_page == 'contact.php') ? 'active' : ''; ?>">Contact</a>
        </nav>

        <div class="header-actions">
            <?php if ($hasAuthenticatedUser && in_array($sessionRole, ['admin', 'super_admin', 'landlord'], true)): ?>
                <a href="<?= e(dashboardPathForRole()); ?>" class="btn btn-outline"><?= hasRole('landlord') ? 'Landlord Dashboard' : 'Admin Dashboard'; ?></a>
                <a href="/boarding_hauz/logout.php" class="btn btn-primary">Logout</a>
            <?php elseif ($hasAuthenticatedUser && $sessionRole === 'tenant'): ?>
                <a href="/boarding_hauz/tenant_dashboard.php" class="btn btn-outline">Tenant Dashboard</a>
                <a href="/boarding_hauz/logout.php" class="btn btn-primary">Logout</a>
            <?php elseif (!$hasAuthenticatedUser): ?>
                <a href="/boarding_hauz/login.php" class="btn btn-outline">Login</a>
                <a href="/boarding_hauz/register.php" class="btn btn-primary">Register</a>
            <?php else: ?>
                <a href="/boarding_hauz/logout.php" class="btn btn-primary">Logout</a>
            <?php endif; ?>
        </div>
    </div>
</header>