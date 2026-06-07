<?php
require_once __DIR__ . '/auth/guards.php';
require_any_role(['admin']);

require_once __DIR__ . '/dbconfig.php';
$conn = $GLOBALS['conn'] ?? ($conn ?? null);

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
    if (isset($conn) && $conn->connect_errno) {
        die('Database connection error: ' . $conn->connect_error);
    }
    die('Database connection error: Please check src/dbconfig.php');
}

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

$errorMessage = '';
$successMessage = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);

$users = [];

$displayName = trim((string) ($_SESSION['name'] ?? 'User'));

if (is_numeric($displayName) && isset($conn)) {
    $userPk = (int)($_SESSION['user_id'] ?? 0);
    $nameRes = $conn->query("SELECT COALESCE(NULLIF(full_name, ''), username, 'Admin User') as real_name FROM users WHERE userid = $userPk OR user_id = $userPk LIMIT 1");
    if ($nameRes && $row = $nameRes->fetch_assoc()) {
        $displayName = $row['real_name'];
        $_SESSION['name'] = $displayName;
    }
}
if ($displayName === '' || is_numeric($displayName)) $displayName = 'Admin User';

$cleanName = preg_replace('/[^A-Za-z\s]/', '', $displayName);
$nameParts = preg_split('/\s+/', trim($cleanName));
$firstNamePart = $nameParts[0] ?? '';
$initials = ($firstNamePart !== '') ? strtoupper(substr($firstNamePart, 0, 1)) : 'A';
$initials .= (count($nameParts) > 1 && end($nameParts) !== '') ? strtoupper(substr(end($nameParts), 0, 1)) : 'U';



$currentRole = normalize_role((string) ($_SESSION['role'] ?? ''));
$roleLabel = 'IP Member';
if ($currentRole === 'admin') {
    $roleLabel = 'System Admin';
} elseif ($currentRole === 'tribe_leader') {
    $roleLabel = 'Elder';
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

if (!function_exists('first_existing_column')) {
    function first_existing_column($columns, $candidates) {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }
}

$userColumns = get_table_columns($conn, 'users');

// 1. Detect Primary Key (Crucial for fallbacks)
$pkCol = first_existing_column($userColumns, ['userid', 'user_id', 'id']) ?? 'id';

// 2. Robust Name Detection
$nameCol = first_existing_column($userColumns, ['full_name', 'name', 'username', 'display_name']);
if ($nameCol !== null) {
    $nameExpression = "`{$nameCol}`";
} elseif (in_array('first_name', $userColumns, true) && in_array('last_name', $userColumns, true)) {
    $nameExpression = "TRIM(CONCAT_WS(' ', first_name, last_name))";
} elseif (in_array('first_name', $userColumns, true)) {
    $nameExpression = "`first_name`";
} else {
    $nameExpression = "'User'";
}

// 3. Map Other Columns with Safe Fallbacks
$emailCol = first_existing_column($userColumns, ['email', 'email_address', 'user_email', 'email_addr']) ?? 'email';
$roleCol  = first_existing_column($userColumns, ['role', 'user_role', 'role_id', 'privilege', 'user_level']) ?? 'role';
$lastActiveCol = first_existing_column($userColumns, ['last_active', 'updated_at', 'last_login', 'last_activity', 'last_seen', 'active_at', 'login_at']) ?? $pkCol;
$statusCol = first_existing_column($userColumns, ['account_status', 'status', 'user_status', 'is_active', 'active', 'state']) ?? $pkCol;
$sexCol    = first_existing_column($userColumns, ['sex', 'gender']);

$avatarExpr = in_array('avatar', $userColumns, true) ? '`avatar`' : "'' AS `avatar`";
$jurisdictionCol = first_existing_column($userColumns, ['juresdiction', 'jurisdiction', 'area', 'region', 'assignment', 'location', 'jurisdiction_area']);
$jurisdictionExpr = ($jurisdictionCol !== null) ? "`{$jurisdictionCol}` AS `jurisdiction`" : "'' AS `jurisdiction`";

// --- Handle Account Disabling ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['user_id'])) {
    $targetUserId = (int)$_POST['user_id'];
    $action = $_POST['action'];

    if ($action === 'toggle_status' && $statusCol !== $pkCol) {
        $currentStatus = $_POST['current_status'] ?? '';
        $isCurrentlyDisabled = strtolower(trim($currentStatus)) === 'disabled';
        $newStatus = $isCurrentlyDisabled ? 'Offline' : 'Disabled';
        
        $updateSql = "UPDATE users SET `{$statusCol}` = ? WHERE `{$pkCol}` = ? LIMIT 1";
        $stmt = $conn->prepare($updateSql);
        if ($stmt) {
            $stmt->bind_param('si', $newStatus, $targetUserId);
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "User account " . ($newStatus === 'Disabled' ? 'disabled' : 'enabled') . " successfully.";
                header("Location: user_management.php?query=" . urlencode($searchQuery) . "&role=" . urlencode($roleFilter) . "&page=" . $currentPage);
                exit;
            }
            $stmt->close();
        }
    }
}

// Build SQL query with filters
$selectColumns = [
    "`{$pkCol}` AS `user_id` ",
    $avatarExpr,
    "{$nameExpression} AS `full_name`",
    "`{$emailCol}` AS `email`",
    "`{$roleCol}` AS `role`",
    $jurisdictionExpr,
    ($lastActiveCol !== $pkCol ? "`{$lastActiveCol}`" : "NULL") . " AS `last_active`" ,
    "`{$statusCol}` AS `status`"
];

if ($sexCol !== null) {
    $selectColumns[] = "`{$sexCol}` AS `sex`";
} else { $selectColumns[] = "'' AS `sex`"; }

$baseSql = 'FROM users';
$whereClauses = [];
$params = [];
$types = '';

// Add search filter
if ($searchQuery !== '') {
    $whereClauses[] = "({$nameExpression} LIKE ? OR `{$emailCol}` LIKE ? OR `{$roleCol}` LIKE ?)";
    $searchParam = '%' . $searchQuery . '%';
    array_push($params, $searchParam, $searchParam, $searchParam);
    $types .= 'sss';
}

// Add role filter
if ($roleFilter !== '' && $roleFilter !== 'All Roles') {
    $whereClauses[] = "`{$roleCol}` = ?";
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
    } else {
        $errorMessage = "Error preparing user count: " . $conn->error;
    }
} else {
    $countResult = $conn->query($countSql);
    if ($countResult instanceof mysqli_result) {
        $countRow = $countResult->fetch_assoc();
        $totalRecords = (int) ($countRow['total'] ?? 0);
    } else {
        $errorMessage = "Error counting users: " . $conn->error;
    }
}

$totalPages = max(1, (int) ceil($totalRecords / $perPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $perPage;

$sql = 'SELECT ' . implode(', ', $selectColumns) . ' ' . $baseSql . " ORDER BY `{$lastActiveCol}` DESC LIMIT ? OFFSET ?";

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
        } else {
            $errorMessage = "Error fetching users: " . $conn->error;
        }
        $stmt->close();
    } else {
        $errorMessage = "Error preparing user query: " . $conn->error;
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
        } else {
            $errorMessage = "Error fetching users: " . $conn->error;
        }
        $stmt->close();
    } else {
        $errorMessage = "Error preparing user query: " . $conn->error;
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

    if ($normalized === 'tribe leader' || $normalized === 'leader' || $normalized === 'elder') {
        return 'role-badge-leader';
    }

    return 'role-badge-member border border-[#dedede]';
}

function get_last_active_label($lastActive, $status) {
    $status = strtolower(trim((string) $status));
    if ($status === 'online') {
        return 'Active now';
    }

    if (empty($lastActive)) {
        return 'No recent activity';
    }

    $timestamp = strtotime((string) $lastActive);
    if ($timestamp === false) {
        return 'No recent activity';
    }

    $secondsAgo = max(0, time() - $timestamp);
    $daysAgo = (int) floor($secondsAgo / 86400);

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
                        <p class="text-xs font-bold leading-none text-[#262626]"><?php echo htmlspecialchars($displayName); ?></p>
                        <p class="text-[10px] text-gray-400 uppercase tracking-tighter"><?php echo htmlspecialchars($roleLabel); ?></p>
                    </div>
                </div>
            </div>
        </header>

        <section class="mb-8">
            <h1 class="text-2xl font-bold text-[#262626]">User Management</h1>
        </section>

        <?php if ($successMessage !== ''): ?>
            <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

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
                    <option value="Tribe Leader" <?php echo $roleFilter === 'Tribe Leader' ? 'selected' : ''; ?>>Elder</option>
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
                        <th class="px-6 py-4">Status</th>
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
                                $isActiveToday = $lastActiveLabel === 'Active today';
                                $userStatusRaw = strtolower(trim((string) ($user['status'] ?? '')));
                                $isDisabled = $userStatusRaw === 'disabled';
                                
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
                                    <span class="<?php echo escape_html($badgeClass); ?> px-4 py-1 rounded-md text-[11px] font-bold uppercase w-fit">
                                        <?php echo escape_html($user['role'] ?? 'IP Member'); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-[#262626] font-semibold text-xs"><?php echo escape_html($user['jurisdiction'] ?? 'N/A'); ?></td>
                                <td class="px-6 py-4">
                                    <?php if ($isDisabled): ?>
                                        <span class="bg-red-50 text-red-700 px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase border border-red-100">
                                            Disabled
                                        </span>
                                    <?php else: ?>
                                        <span class="bg-emerald-50 text-emerald-700 px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase border border-emerald-100">
                                            Active
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 <?php echo ($isOnline || $isActiveToday) ? 'text-green-600 font-semibold' : 'text-gray-400'; ?> text-xs">
                                    <?php echo escape_html($lastActiveLabel); ?>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="relative inline-block">
                                        <button type="button" class="action-menu-toggle p-2 hover:bg-gray-100 rounded-lg transition" title="More actions">
                                            <i data-lucide="more-horizontal" class="w-4 h-4 text-gray-400"></i>
                                        </button>
                                        <div class="action-menu hidden absolute right-0 mt-1 w-56 bg-white border border-[#dedede] rounded-lg shadow-lg z-50">
                                            <button type="button" 
                                                    class="status-toggle-trigger w-full text-left px-4 py-2 text-sm <?php echo $isDisabled ? 'text-emerald-700 hover:bg-emerald-50' : 'text-red-700 hover:bg-red-50'; ?> transition font-medium"
                                                    data-user-id="<?php echo (int)($user['user_id'] ?? 0); ?>"
                                                    data-full-name="<?php echo escape_html($user['full_name'] ?? 'N/A'); ?>"
                                                    data-action-type="<?php echo $isDisabled ? 'enable' : 'disable'; ?>"
                                                    data-current-status="<?php echo escape_html($user['status'] ?? ''); ?>">
                                                <i data-lucide="<?php echo $isDisabled ? 'check-circle' : 'ban'; ?>" class="w-4 h-4 inline-block mr-2"></i>
                                                <?php echo $isDisabled ? 'Enable Account' : 'Disable Account'; ?>
                                            </button>
                                        </div>
                                    </div>
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

    <!-- Status Toggle Confirmation Modal -->
    <div id="statusToggleModal" class="hidden fixed inset-0 z-[70] items-center justify-center p-4">
        <div id="modalBackdrop" class="absolute inset-0 bg-black/40 backdrop-blur-xs"></div>
        <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-[0_10px_30px_rgba(0,0,0,0.15)] border border-[#ececea] p-6">
            <h3 id="modalTitle" class="text-base font-bold text-[#262626] mb-2 flex items-center gap-2">
                <!-- Injected via JS -->
            </h3>
            <p id="modalDescription" class="text-xs text-gray-500 mb-6 leading-relaxed">
                <!-- Injected via JS -->
            </p>
            
            <form id="statusModalForm" method="POST">
                <input type="hidden" name="user_id" id="modalUserId">
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="current_status" id="modalCurrentStatus">
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeStatusModal()" class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-xl hover:bg-gray-50 text-gray-600 transition-all">Cancel</button>
                    <button type="submit" id="modalConfirmBtn" class="px-4 py-2 text-xs font-bold text-white rounded-xl transition-all shadow-sm">
                        Confirm
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();

        function openStatusModal(userId, fullName, actionType, currentStatus) {
            const modal = document.getElementById('statusToggleModal');
            const title = document.getElementById('modalTitle');
            const description = document.getElementById('modalDescription');
            const confirmBtn = document.getElementById('modalConfirmBtn');
            
            document.getElementById('modalUserId').value = userId;
            document.getElementById('modalCurrentStatus').value = currentStatus;

            if (actionType === 'disable') {
                title.innerHTML = '<span class="inline-block w-2.5 h-2.5 rounded-full bg-red-500"></span> Confirm Account Deactivation';
                description.innerHTML = `Are you sure you want to <b>DISABLE</b> the account for <b>${fullName}</b>? <br><br>The user will be immediately logged out and blocked from accessing the system until their account is re-enabled by an administrator.`;
                confirmBtn.className = 'px-4 py-2 text-xs font-bold bg-red-600 hover:bg-red-700 text-white rounded-xl transition-all shadow-sm';
                confirmBtn.textContent = 'Confirm Disable';
            } else {
                title.innerHTML = '<span class="inline-block w-2.5 h-2.5 rounded-full bg-emerald-500"></span> Restore Account Access';
                description.innerHTML = `Are you sure you want to <b>re-enable</b> the account for <b>${fullName}</b>? <br><br>Access will be restored immediately, allowing the user to log back into their dashboard.`;
                confirmBtn.className = 'px-4 py-2 text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl transition-all shadow-sm';
                confirmBtn.textContent = 'Confirm Enable';
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }

        function closeStatusModal() {
            const modal = document.getElementById('statusToggleModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.style.overflow = '';
        }

        // Event listener for backdrop
        document.getElementById('modalBackdrop')?.addEventListener('click', closeStatusModal);

        // Handle clickable action menus
        document.addEventListener('click', (e) => {
            const toggle = e.target.closest('.action-menu-toggle');
            const statusTrigger = e.target.closest('.status-toggle-trigger');
            const menu = e.target.closest('.action-menu');
            
            if (statusTrigger) {
                const { userId, fullName, actionType, currentStatus } = statusTrigger.dataset;
                openStatusModal(userId, fullName, actionType, currentStatus);
                if (menu) menu.classList.add('hidden'); // Close the dropdown menu
            } else if (toggle) {
                const targetMenu = toggle.nextElementSibling;
                // Close all other menus
                document.querySelectorAll('.action-menu').forEach(m => {
                    if (m !== targetMenu) m.classList.add('hidden');
                });
                targetMenu.classList.toggle('hidden');
            } else if (!menu) {
                // Clicked outside, close all open menus
                document.querySelectorAll('.action-menu').forEach(m => m.classList.add('hidden'));
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeStatusModal();
        });
    </script>
</body>
</html>