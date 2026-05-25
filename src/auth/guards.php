<?php

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/auth_helpers.php';

function require_authenticated_user(): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        header('Location: login.php');
        exit;
    }

    touch_user_activity((int) $_SESSION['user_id']);
}

function require_any_role(array $allowedRoles): void
{
    require_authenticated_user();

    $normalizedAllowed = array_map('normalize_role', $allowedRoles);
    $currentRole = normalize_role((string) ($_SESSION['role'] ?? ''));

    if (!in_array($currentRole, $normalizedAllowed, true)) {
        redirect_to_role_dashboard($currentRole);
    }
}

function get_current_role(): string
{
    return normalize_role((string) ($_SESSION['role'] ?? ''));
}
