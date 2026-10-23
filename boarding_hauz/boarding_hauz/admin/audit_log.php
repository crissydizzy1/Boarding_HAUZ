<?php
require_once __DIR__ . '/../auth.php';
requireRole('super_admin');

header('Location: /boarding_hauz/admin/platform_dashboard.php', true, 302);
exit;
