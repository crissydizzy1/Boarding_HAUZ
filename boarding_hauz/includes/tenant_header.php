<?php
$tenant_page = basename($_SERVER['PHP_SELF']);
$tenant_user = currentUser();
$tenant_name = $tenant_user['username'] ?? ($_SESSION['user_name'] ?? 'Tenant');

// Optional: unread message count for the Messages nav badge.
// This is wrapped defensively so it never breaks the header if the
// messages table doesn't exist yet or $conn isn't set for some reason.
$tenant_unread_count = 0;
if (isset($conn) && !empty($_SESSION['user_id'])) {
    $tenant_uid_for_badge = (int) $_SESSION['user_id'];
    $unread_stmt = $conn->prepare(
        "SELECT COUNT(*) AS unread_count
         FROM messages
         WHERE receiver_id = ? AND is_read = 0"
    );
    if ($unread_stmt) {
        $unread_stmt->bind_param("i", $tenant_uid_for_badge);
        $unread_stmt->execute();
        $unread_row = $unread_stmt->get_result()->fetch_assoc();
        $tenant_unread_count = (int) ($unread_row['unread_count'] ?? 0);
        $unread_stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($tenant_title ?? 'Tenant Portal'); ?> | Boarding Hauz</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/boarding_hauz/assets/css/style.css?v=<?php echo (int) filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
</head>
<body class="tenant-portal">
    <header class="site-header tenant-site-header">
        <div class="header-container">
            <a href="/boarding_hauz/index.php" class="brand"><img class="brand-logo" src="/boarding_hauz/assets/images/boarding-hauz-icon.png" alt="">Boarding Hauz</a>

            <nav class="main-nav">
                <a href="/boarding_hauz/tenant_room.php" class="<?php echo $tenant_page === 'tenant_room.php' ? 'active' : ''; ?>">Room</a>
                <a href="/boarding_hauz/tenant_payments.php" class="<?php echo $tenant_page === 'tenant_payments.php' ? 'active' : ''; ?>">Payments</a>
                <a href="/boarding_hauz/tenant_maintenance.php" class="<?php echo $tenant_page === 'tenant_maintenance.php' ? 'active' : ''; ?>">Maintenance</a>
                <a href="/boarding_hauz/tenant_messages.php" class="<?php echo $tenant_page === 'tenant_messages.php' ? 'active' : ''; ?>">
                    Messages
                    <?php if ($tenant_unread_count > 0): ?>
                        <span style="display:inline-block; background-color:#135E54; color:#FFFFFF; font-size:0.7rem; font-weight:700; min-width:16px; padding:1px 5px; border-radius:999px; margin-left:4px; vertical-align:middle;"><?php echo $tenant_unread_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="/boarding_hauz/tenant_profile.php" class="<?php echo $tenant_page === 'tenant_profile.php' ? 'active' : ''; ?>">Profile</a>
            </nav>

            <div class="header-actions tenant-header-actions">
                <a href="/boarding_hauz/tenant_dashboard.php" class="btn btn-outline">Dashboard</a>
                <a href="/boarding_hauz/logout.php" class="btn btn-primary">Logout</a>
            </div>
        </div>
    </header>