<?php

function normalize_role(string $role): string
{
    $value = strtolower(trim($role));

    if ($value === 'admin' || $value === 'system admin' || $value === 'system_admin') {
        return 'admin';
    }

    if ($value === 'tribe leader' || $value === 'tribe_leader' || $value === 'leader') {
        return 'tribe_leader';
    }

    if ($value === 'ip member' || $value === 'ip_member' || $value === 'member') {
        return 'ip_member';
    }

    return $value;
}

function get_role_dashboard_path(string $role): string
{
    $normalized = normalize_role($role);

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
            'id' => $findFirst(['user_id', 'id']),
            'last_active' => $findFirst(['last_active']),
            'status' => $findFirst(['status']),
        ];
    }

    if ($resolved === false || empty($resolved['id']) || empty($resolved['last_active'])) {
        return;
    }

    require_once __DIR__ . '/../dbconfig.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        return;
    }

    $setParts = ["`{$resolved['last_active']}` = NOW()"];
    if (!empty($resolved['status'])) {
        $setParts[] = "`{$resolved['status']}` = 'online'";
    }

    $sql = "UPDATE users SET " . implode(', ', $setParts) . " WHERE `{$resolved['id']}` = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
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

    $idColumn = in_array('user_id', $columns, true) ? 'user_id' : (in_array('id', $columns, true) ? 'id' : null);
    $statusColumn = in_array('status', $columns, true) ? 'status' : null;
    $lastActiveColumn = in_array('last_active', $columns, true) ? 'last_active' : null;

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