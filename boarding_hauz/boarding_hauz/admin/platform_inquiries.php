<?php
require_once __DIR__ . '/../auth.php';
requireRole('super_admin');
header('Location: /boarding_hauz/admin/public_inquiries.php', true, 302);
exit;
