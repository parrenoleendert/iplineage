<?php

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/registration_guard.php';

function require_authenticated_user(): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        header('Location: login.php');
        exit;
    }

    // Real-time Disabling Guard: Verify account is still active on each page load
    require_once __DIR__ . '/../dbconfig.php';
    $conn = $GLOBALS['conn'] ?? null;
    if ($conn instanceof mysqli) {
        $columns = [];
        $res = $conn->query('SHOW COLUMNS FROM users');
        if ($res) { while($c = $res->fetch_assoc()) $columns[] = $c['Field']; }
        
        $idCol = first_existing_column($columns, ['userid', 'user_id', 'id']);
        $statusCol = first_existing_column($columns, ['account_status', 'status', 'user_status', 'is_active']);
        
        if ($idCol && $statusCol) {
            $stmt = $conn->prepare("SELECT `{$statusCol}` FROM users WHERE `{$idCol}` = ? LIMIT 1");
            if ($stmt) {
                $uid = (int)$_SESSION['user_id'];
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                if ($row && strtolower(trim((string)$row[$statusCol])) === 'disabled') {
                    // Account was disabled mid-session: force immediate logout
                    header('Location: logout.php');
                    exit;
                }
            }
        }
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

    // Ensure IP members have completed registration before accessing protected pages.
    if ($currentRole === 'ip_member') {
        require_ip_registration_complete();
    }
}

function get_current_role(): string
{
    return normalize_role((string) ($_SESSION['role'] ?? ''));
}
