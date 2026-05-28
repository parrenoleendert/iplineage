<?php
require_once __DIR__ . '/src/auth/session.php';
require_once __DIR__ . '/src/auth/auth_helpers.php';

if (!empty($_SESSION['role'])) {
    redirect_to_role_dashboard((string) $_SESSION['role']);
}

header('Location: src/login.php');
exit;