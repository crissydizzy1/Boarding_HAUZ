<?php
require_once __DIR__ . '/../auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Remove all session variables (admin id, role, etc.).
$_SESSION = [];

// Also destroy the session cookie itself, not just the server-side data.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session data on the server.
session_destroy();

// Prevent a cached admin page from being shown if the admin
// presses the browser Back button after logging out.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Always return to the ADMIN login page, never the public site.
header('Location: /boarding_hauz/login.php');
exit;
