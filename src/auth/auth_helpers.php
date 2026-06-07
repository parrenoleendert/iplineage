<?php

if (!function_exists('first_existing_column')) {
    /**
     * Finds the first column name that exists in the provided schema array.
     */
    function first_existing_column(array $columns, array $candidates): ?string {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        return null;
    }
}

/**
 * Returns standardized display name, initials, and role label for headers.
 */
function get_header_profile_data(array &$session): array {
    $displayName = trim((string)($session['name'] ?? 'User'));
    
    // Session Repair: If session name is numeric (e.g. user ID "2"), fetch real name from DB
    if (is_numeric($displayName)) {
        $conn = $GLOBALS['conn'] ?? null;
        if ($conn instanceof mysqli) {
            $userPk = (int)($session['user_id'] ?? 0);
            $nameRes = $conn->query("SELECT COALESCE(NULLIF(full_name, ''), username, 'Admin User') as real_name FROM users WHERE userid = $userPk OR user_id = $userPk LIMIT 1");
            if ($nameRes && $row = $nameRes->fetch_assoc()) {
                $displayName = $row['real_name'];
                $session['name'] = $displayName;
            }
        }
    }
    if ($displayName === '' || is_numeric($displayName)) $displayName = 'Admin User';
    
    $displayName = mb_convert_case($displayName, MB_CASE_TITLE, "UTF-8");
    
    $cleanName = preg_replace('/[^A-Za-z\s]/', '', $displayName);
    $nameParts = preg_split('/\s+/', trim($cleanName));
    $initials = strtoupper(substr($nameParts[0] ?? 'A', 0, 1));
    $initials .= (count($nameParts) > 1) ? strtoupper(substr(end($nameParts), 0, 1)) : 'U';

    $role = normalize_role((string)($session['role'] ?? ''));
    $roleLabel = ($role === 'admin') ? 'System Admin' : (($role === 'tribe_leader') ? 'Elder' : 'IP Member');
    
    return [$displayName, $initials, $roleLabel];
}

function normalize_role(string $role): string
{
    $value = strtolower(trim($role));

    if ($value === 'admin' || $value === 'system admin' || $value === 'system_admin') {
        return 'admin';
    }

    if ($value === 'tribe leader' || $value === 'tribe_leader' || $value === 'leader') {
        return 'tribe_leader';
    }

    if ($value === 'ip member' || $value === 'ip_member' || $value === 'member' || $value === 'ip memeber') {
        return 'ip_member';
    }

    return $value;
}

function get_role_dashboard_path(string $role): string
{
    $normalized = normalize_role($role);

    // Force unregistered IP members to the registration page.
    if ($normalized === 'ip_member') {
        $isComplete = (bool)($_SESSION['ip_registration_complete'] ?? false);
        
        if (!$isComplete) {
            require_once __DIR__ . '/../dbconfig.php';
            $conn = $GLOBALS['conn'] ?? null;
            $uid = (int)($_SESSION['user_id'] ?? 0);

            if ($conn && $uid > 0) {
                // Check database to see where they actually are in the process
                $sql = "SELECT a.status FROM ipmembers i 
                        LEFT JOIN applications a ON i.ip_member_id = a.ip_member_id 
                        WHERE i.user_id = ? ORDER BY a.application_id DESC LIMIT 1";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param('i', $uid);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $status = $row['status'] ?? '';
                    
                    // If they have an application but it's not approved, they belong in verify.php
                    if ($status !== '' && $status !== 'approved') {
                        return 'verify.php';
                    }
                }
            }
            return 'personal.php';
        }
    }

    if ($normalized === 'admin') {
        if (file_exists(__DIR__ . '/../dashboard.php')) {
            return 'dashboard.php';
        }

        return 'dashboard.php';
    }

    if ($normalized === 'tribe_leader') {
        if (file_exists(__DIR__ . '/..//dashboard.php')) {
            return 'dashboard.php';
        }

        return 'tribe_leader/dashboard.php';
    }

    if (file_exists(__DIR__ . '/../ip_member/dashboard.php')) {
        return 'ip_member/dashboard.php';
    }

    return 'ip_member/dashboard.html';
}

function redirect_to_role_dashboard(string $role): void
{
    header('Location: ' . get_role_dashboard_path($role));
    exit;
}

function touch_user_activity(int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    static $resolved = null;

    if ($resolved === null) {
        require_once __DIR__ . '/../dbconfig.php';

        if (!isset($conn) || !($conn instanceof mysqli)) {
            $resolved = false;
            return;
        }

        $columns = [];
        $columnResult = $conn->query('SHOW COLUMNS FROM users');
        if ($columnResult instanceof mysqli_result) {
            while ($column = $columnResult->fetch_assoc()) {
                $columns[] = (string) ($column['Field'] ?? '');
            }
        }

        $findFirst = static function (array $candidates) use ($columns): ?string {
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    return $candidate;
                }
            }

            return null;
        };

        $resolved = [
            'id' => $findFirst(['userid', 'user_id', 'id']),
            'last_active' => $findFirst(['last_active', 'updated_at', 'last_login', 'last_activity', 'last_seen', 'active_at', 'login_at']),
            'status' => $findFirst(['account_status', 'status', 'user_status', 'is_active', 'active', 'state']),
        ];
    }

    if ($resolved === false || empty($resolved['id']) || empty($resolved['last_active'])) {
        return;
    }

    require_once __DIR__ . '/../dbconfig.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        return;
    }

    // Safety: Check if user is disabled before setting them to online
    if (!empty($resolved['status'])) {
        $checkSql = "SELECT `{$resolved['status']}` FROM users WHERE `{$resolved['id']}` = ? LIMIT 1";
        $checkStmt = $conn->prepare($checkSql);
        if ($checkStmt) {
            $checkStmt->bind_param('i', $userId);
            $checkStmt->execute();
            $checkRow = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();
            if ($checkRow) {
                $currentStatusVal = strtolower(trim((string)($checkRow[$resolved['status']] ?? '')));
                // Only block if account is explicitly disabled or banned
                if (in_array($currentStatusVal, ['disabled', 'banned', 'inactive'], true)) {
                    return;
                }
            }
        }
    }


    $setParts = ["`{$resolved['last_active']}` = NOW()"];
    if (!empty($resolved['status'])) {
        $setParts[] = "`{$resolved['status']}` = 'online'";
    }

    $sql = "UPDATE users SET " . implode(', ', $setParts) . " WHERE `{$resolved['id']}` = ? LIMIT 1";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $ok = $stmt->execute();

        // Temporary debug: log resolved columns and what we set.
        // Remove after confirming the correct behavior.
        if (!$ok) {
            error_log('[touch_user_activity] UPDATE failed for userId=' . $userId . ' sql=' . $sql . ' err=' . $conn->error);
        } else {
            error_log('[touch_user_activity] UPDATED userId=' . $userId . ' set=' . implode(', ', $setParts) . ' resolved=' . json_encode($resolved));
        }

        $stmt->close();
    } else {
        error_log('[touch_user_activity] prepare failed for userId=' . $userId . ' sql=' . $sql . ' err=' . $conn->error);
    }
}


function mark_user_offline(int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    require_once __DIR__ . '/../dbconfig.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        return;
    }

    $columns = [];
    $columnResult = $conn->query('SHOW COLUMNS FROM users');
    if ($columnResult instanceof mysqli_result) {
        while ($column = $columnResult->fetch_assoc()) {
            $columns[] = (string) ($column['Field'] ?? '');
        }
    }

    $idColumn = first_existing_column($columns, ['userid', 'user_id', 'id']);
    $statusColumn = first_existing_column($columns, ['account_status', 'status', 'user_status', 'is_active', 'active', 'state']);
    $lastActiveColumn = first_existing_column($columns, ['last_active', 'updated_at', 'last_login', 'last_activity', 'last_seen', 'active_at', 'login_at']);

    if ($idColumn === null || $statusColumn === null) {
        return;
    }

    $setParts = ["`{$statusColumn}` = 'offline'"];
    if ($lastActiveColumn !== null) {
        $setParts[] = "`{$lastActiveColumn}` = NOW()";
    }

    $sql = "UPDATE users SET " . implode(', ', $setParts) . " WHERE `{$idColumn}` = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}