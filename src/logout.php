<?php
require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/auth/auth_helpers.php';

$logoutUserId = (int) ($_SESSION['user_id'] ?? 0);
if ($logoutUserId > 0) {
    mark_user_offline($logoutUserId);
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
}

session_destroy();

header('Location: login.php');
exit;
