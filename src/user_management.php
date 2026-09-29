<?php
require_once __DIR__ . '/auth/guards.php';
require_any_role(['admin']);
include 'dbconfig.php';

// Get search parameters
$searchQuery = isset($_GET['query']) ? trim($_GET['query']) : '';
$roleFilter = isset($_GET['role']) ? trim($_GET['role']) : '';
$perPage = 5;
$currentPage = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($currentPage < 1) {
    $currentPage = 1;
}
$totalRecords = 0;
$totalPages = 1;

$users = [];

$displayName = trim((string) ($_SESSION['name'] ?? 'User'));
if ($displayName === '') {
    $displayName = 'User';
}

$nameParts = preg_split('/\s+/', $displayName);
$initials = strtoupper(substr((string) ($nameParts[0] ?? 'U'), 0, 1));
if (!empty($nameParts[1])) {
    $initials .= strtoupper(substr((string) $nameParts[1], 0, 1));
}


$currentRole = normalize_role((string) ($_SESSION['role'] ?? ''));
$roleLabel = 'IP Member';
if ($currentRole === 'admin') {
    $roleLabel = 'System Admin';
} elseif ($currentRole === 'tribe_leader') {
    $roleLabel = 'Tribe Leader';
}


function get_table_columns($conn, $tableName) {
    $columns = [];
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}`");

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }

    return $columns;
}

function first_existing_column($columns, $candidates) {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

$userColumns = get_table_columns($conn, 'users');
$userSexColumn = first_existing_column($userColumns, ['sex', 'gender']);

// Build SQL query with filters
$selectColumns = ['avatar', 'full_name', 'email', 'role', 'jurisdiction', 'last_active', 'status'];
if ($userSexColumn !== null) {
    $selectColumns[] = "`{$userSexColumn}` AS sex";
} else {
    $selectColumns[] = "'' AS sex";
}

$baseSql = 'FROM users';
$whereClauses = [];
$params = [];
$types = '';

// Add search filter
if ($searchQuery !== '') {
    $whereClauses[] = "(full_name LIKE ? OR email LIKE ? OR role LIKE ?)";
    $searchParam = '%' . $searchQuery . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= 'sss';
}

// Add role filter
if ($roleFilter !== '' && $roleFilter !== 'All Roles') {
    $whereClauses[] = "role = ?";
    $params[] = $roleFilter;
    $types .= 's';
}

// Append WHERE clause if needed
if (!empty($whereClauses)) {
    $baseSql .= ' WHERE ' . implode(' AND ', $whereClauses);
}

$countSql = 'SELECT COUNT(*) AS total ' . $baseSql;
if (!empty($params)) {
    $countStmt = $conn->prepare($countSql);
    if ($countStmt) {
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        if ($countResult instanceof mysqli_result) {
            $countRow = $countResult->fetch_assoc();
            $totalRecords = (int) ($countRow['total'] ?? 0);
        }
        $countStmt->close();
    }
} else {
    $countResult = $conn->query($countSql);
    if ($countResult instanceof mysqli_result) {
        $countRow = $countResult->fetch_assoc();
        $totalRecords = (int) ($countRow['total'] ?? 0);
    }
}

$totalPages = max(1, (int) ceil($totalRecords / $perPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $perPage;

$sql = 'SELECT ' . implode(', ', $selectColumns) . ' ' . $baseSql . " ORDER BY last_active DESC LIMIT ? OFFSET ?";

// Execute query
if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $queryParams = $params;
        $queryParams[] = $perPage;
        $queryParams[] = $offset;
        $stmt->bind_param($types . 'ii', ...$queryParams);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
        }
        $stmt->close();
    }
} else {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ii', $perPage, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
        }
        $stmt->close();
    }
}

function escape_html($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function get_initials($name) {
    $name = trim((string) $name);
    if ($name === '') {
        return 'NA';
    }

    $parts = preg_split('/\s+/', $name);
    $first = strtoupper(substr($parts[0], 0, 1));
    $second = isset($parts[1]) ? strtoupper(substr($parts[1], 0, 1)) : '';

    return $first . $second;
}

function get_role_badge_class($role) {
    $normalized = strtolower(trim((string) $role));

    if ($normalized === 'system admin' || $normalized === 'admin') {
        return 'role-badge-admin';
    }

    if ($normalized === 'tribe leader' || $normalized === 'leader') {
        return 'role-badge-leader';
    }

    return 'role-badge-member border border-[#dedede]';
}

function get_days_since($dateString) {
    $timestamp = strtotime($dateString);
    if ($timestamp === false) {
        return null;
    }
    $secondsAgo = max(0, time() - $timestamp);
    return (int) floor($secondsAgo / 86400);
}

function get_last_active_label($lastActive, $status) {
    $status = strtolower(trim((string) $status));
    if ($status === 'online') {
        return 'Active now';
    }
    if (empty($lastActive)) {
        return 'No recent activity';
    }
    $daysAgo = get_days_since($lastActive);
    if ($daysAgo === null) {
        return 'No recent activity';
    }
    if ($daysAgo <= 0) {
        return 'Active today';
    }
    if ($daysAgo > 7) {
        $daysAgo = 7;
    }
    $dayLabel = $daysAgo === 1 ? 'day' : 'days';
    return "Active {$daysAgo} {$dayLabel} ago";
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="../css/style.css">
    <title>IP Lineage - User Management</title>
    <style>
        body { background-color: #f3f4f1; color: #262626; font-family: 'Plus Jakarta Sans', sans-serif; }
        .bg-sidebar { background-color: #ffffff; border-right: 1px solid #dedede; }
        .bg-card-custom { background-color: #ffffff; border: 1px solid #dedede; }
        .sidebar-item-active { background-color: #262626; color: #ffffff; }
        .text-muted { color: #666666; }
        .border-line { border-bottom: 1px solid #dedede; }
        .role-badge-admin { background-color: #e0e7ff; color: #4338ca; }
        .role-badge-leader { background-color: #fef3c7; color: #b45309; }
        .role-badge-member { background-color: #f3f4f1; color: #262626; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-thumb { background: #dedede; border-radius: 10px; }
    </style>
</head>

<body class="min-h-screen">

    <?php $activeNav = 'user_management'; include __DIR__ . '/shared/sidebar.php'; ?>

    <div class="ml-64 p-8">
        

        <header class="flex justify-between items-center pb-6 border-line mb-5">
            <div class="relative w-96">
                <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"></i>
                <input type="text" placeholder="Search lineage or documents..." 
                    class="w-full bg-white border border-[#dedede] rounded-xl py-3 pl-12 pr-4 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-sm">
            </div>

            <div class="flex items-center gap-4">
                <button class="p-2 text-gray-400 hover:text-[#262626] transition relative">
                    <i data-lucide="bell" class="w-5 h-5"></i>
                    <span class="absolute top-2 right-2 w-2 h-2 bg-[#262626] rounded-full border-2 border-[#f3f4f1]"></span>
                </button>
                <div class="flex items-center gap-3 bg-white border border-[#dedede] p-1.5 pr-4 rounded-xl shadow-sm">
                    <div class="w-8 h-8 rounded-lg bg-[#262626] text-[#f3f4f1] flex items-center justify-center font-bold text-xs uppercase"><?php echo htmlspecialchars($initials); ?></div>
                    <div>
                        <div>
                        <p class="text-xs font-bold leading-none text-[#262626]"><?php echo htmlspecialchars($displayName); ?></p>
                        <p class="text-[10px] text-gray-400 uppercase tracking-tighter"><?php echo htmlspecialchars($roleLabel); ?></p>
                    </div>
                    </div>
                </div>
            </div>
        </header>

        <section class="mb-8">
            <h1 class="text-2xl font-bold text-[#262626]">User Management</h1>
        </section>

        <form action="user_management.php" method="get" class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div class="relative w-full md:w-96">
                <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none z-10"></i>
                <input type="text" name="query" value="<?php echo escape_html($searchQuery); ?>" placeholder="Search by name, email, or role..." 
                    class="w-full bg-white border border-[#dedede] rounded-xl py-3 pl-12 pr-4 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-sm">
            </div>

            <div class="relative">
                <select name="role" onchange="this.form.submit()" class="appearance-none bg-white border border-[#dedede] rounded-xl pl-4 pr-10 py-3 text-sm font-semibold text-[#262626] outline-none focus:ring-2 focus:ring-[#262626]/10 transition cursor-pointer w-full">
                    <option value="" <?php echo $roleFilter === '' ? 'selected' : ''; ?>>All Roles</option>
                    <option value="Admin" <?php echo $roleFilter === 'Admin' ? 'selected' : ''; ?>>Admin</option>
                    <option value="System Admin" <?php echo $roleFilter === 'System Admin' ? 'selected' : ''; ?>>System Admin</option>
                    <option value="Tribe Leader" <?php echo $roleFilter === 'Tribe Leader' ? 'selected' : ''; ?>>Tribe Leader</option>
                    <option value="IP Member" <?php echo $roleFilter === 'IP Member' ? 'selected' : ''; ?>>IP Member</option>
                </select>
                
                <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400">
                    <i data-lucide="chevron-down" class="w-4 h-4"></i>
                </div>
            </div>
        </form>

        <div class="bg-card-custom rounded-2xl overflow-hidden shadow-sm">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-[12px] uppercase text-gray-400 border-line bg-gray-50/50">
                        <th class="px-6 py-4">User Details</th>
                        <th class="px-7 py-4">Role</th>
                        <th class="px-6 py-4">Jurisdiction</th>
                        <th class="px-6 py-4">Last Active</th>
                        <th class="px-6 py-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="text-sm divide-y divide-[#dedede]">
                    <?php if (!empty($users)): ?>
                        <?php foreach ($users as $user): ?>
                            <?php
                                $badgeClass = get_role_badge_class($user['role'] ?? '');
                                $lastActiveLabel = get_last_active_label($user['last_active'] ?? '', $user['status'] ?? '');
                                $isOnline = strtolower(trim((string) ($user['status'] ?? ''))) === 'online';
                                $sexValue = strtolower(trim((string) ($user['sex'] ?? '')));
                                $initialClass = 'bg-gray-100 text-[#262626] border-[#dedede]';
                                if ($sexValue === 'male' || $sexValue === 'm') {
                                    $initialClass = 'bg-[#18181b] text-[#a1a1aa] border-[#3f3f46]';
                                } elseif ($sexValue === 'female' || $sexValue === 'f') {
                                    $initialClass = 'bg-[#e4e4e7] text-[#18181b] border-[#a1a1aa]';
                                }
                            ?>
                            <tr class="hover:bg-gray-100 transition-colors duration-200">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-xs border <?php echo escape_html($initialClass); ?>">
                                            <?php echo escape_html(get_initials($user['full_name'] ?? '')); ?>
                                        </div>
                                        <div>
                                            <p class="font-bold text-[#262626]"><?php echo escape_html($user['full_name'] ?? 'N/A'); ?></p>
                                            <p class="text-[11px] text-gray-400"><?php echo escape_html($user['email'] ?? ''); ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="<?php echo escape_html($badgeClass); ?> px-4 py-1 rounded-md text-[11px] font-bold uppercase">
                                        <?php echo escape_html($user['role'] ?? 'IP Member'); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-[#262626] font-semibold text-xs"><?php echo escape_html($user['jurisdiction'] ?? 'N/A'); ?></td>
                                <td class="px-6 py-4 <?php echo $isOnline ? 'text-green-600 font-semibold' : 'text-gray-400'; ?> text-xs">
                                    <?php echo escape_html($lastActiveLabel); ?>
                                </td>
                                <td class="px-6 py-4 text-right space-x-2">
                                    <button title="Edit Connections" class="p-2 hover:bg-gray-100 text-gray-400 rounded-lg transition">
                                        <i data-lucide="edit-3" class="w-4 h-4"></i>
                                    </button>
                                    <button class="p-2 hover:bg-gray-100 rounded-lg transition"><i data-lucide="more-horizontal" class="w-4 h-4 text-gray-400"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-400">No users found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="p-6 border-t border-[#dedede] flex justify-between items-center bg-gray-50/30">
                <p class="text-[10px] font-bold text-gray-400 uppercase">Showing <?php echo count($users); ?> of <?php echo (int) $totalRecords; ?> Users</p>
                <div class="flex gap-2">
                    <?php if ($currentPage > 1): ?>
                        <a href="?query=<?php echo urlencode($searchQuery); ?>&role=<?php echo urlencode($roleFilter); ?>&page=<?php echo $currentPage - 1; ?>" class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-lg hover:bg-white transition">Previous</a>
                    <?php else: ?>
                        <span class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-lg text-gray-300 cursor-not-allowed">Previous</span>
                    <?php endif; ?>

                    <span class="px-3 py-2 text-xs font-bold text-gray-500">Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>

                    <?php if ($currentPage < $totalPages): ?>
                        <a href="?query=<?php echo urlencode($searchQuery); ?>&role=<?php echo urlencode($roleFilter); ?>&page=<?php echo $currentPage + 1; ?>" class="px-4 py-2 text-xs font-bold bg-[#262626] text-white rounded-lg hover:bg-[#404040] transition">Next</a>
                    <?php else: ?>
                        <span class="px-4 py-2 text-xs font-bold bg-[#262626]/30 text-white rounded-lg cursor-not-allowed">Next</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
