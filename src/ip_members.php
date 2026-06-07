<?php
// Database must be initialized FIRST before any guards that might use it
$dbConfigPath = __DIR__ . '/dbconfig.php';
if (!file_exists($dbConfigPath)) {
    die('Database configuration file not found: ' . $dbConfigPath);
}
require_once $dbConfigPath;

if (!isset($conn) || $conn === null) {
    die('Database connection failed: $conn is not initialized. Please check dbconfig.php');
}

require_once __DIR__ . '/auth/guards.php';
require_any_role(['admin', 'tribe_leader']);

// Enable error display for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$searchQuery = isset($_GET['query']) ? trim($_GET['query']) : '';
$selectedTribe = isset($_GET['tribe']) ? trim($_GET['tribe']) : '';
$selectedBarangay = isset($_GET['barangay']) ? trim($_GET['barangay']) : '';

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

$ipmembers = [];

// --- Handle Account Disabling Logic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['target_user_id'])) {
    $targetUserId = (int)$_POST['target_user_id'];
    $action = $_POST['action'];

    if ($action === 'toggle_status' && $targetUserId > 0) {
        // Dynamic column detection for the update
        $userCols = [];
        $res = $conn->query("SHOW COLUMNS FROM users");
        while($c = $res->fetch_assoc()) $userCols[] = $c['Field'];
        
        $statusCol = first_existing_column($userCols, ['account_status', 'status', 'user_status', 'is_active']);
        $pkCol = first_existing_column($userCols, ['userid', 'user_id', 'id']);

        if ($statusCol && $pkCol) {
            $currentStatus = $_POST['current_status'] ?? '';
            $newStatus = (strtolower(trim($currentStatus)) === 'disabled') ? 'Offline' : 'Disabled';
            
            $updateStmt = $conn->prepare("UPDATE users SET `{$statusCol}` = ? WHERE `{$pkCol}` = ? LIMIT 1");
            if ($updateStmt) {
                $updateStmt->bind_param('si', $newStatus, $targetUserId);
                if ($updateStmt->execute()) {
                    $_SESSION['success_message'] = "Associated user account " . ($newStatus === 'Disabled' ? 'disabled' : 'enabled') . " successfully.";
                    header("Location: ip_members.php?query=" . urlencode($searchQuery) . "&page=" . $currentPage);
                    exit;
                }
                $updateStmt->close();
            }
        }
    }
}

// --- Dynamic Column Detection for ipmembers ---
$ipColumns = [];
$res = $conn->query("SHOW COLUMNS FROM ipmembers");
if ($res) { while($c = $res->fetch_assoc()) $ipColumns[] = $c['Field']; }

// --- Detect status column in applications ---
$appColumns = [];
$resApp = $conn->query("SHOW COLUMNS FROM applications");
if ($resApp instanceof mysqli_result) { while($c = $resApp->fetch_assoc()) $appColumns[] = $c['Field']; }
$appStatusCol = (in_array('approval_status', $appColumns, true)) ? 'approval_status' : 'status';

// --- Dynamic Column Detection for ip_member_details ---
$detailColumns = [];
$resD = $conn->query("SHOW COLUMNS FROM ip_member_details");
if ($resD) { while($c = $resD->fetch_assoc()) $detailColumns[] = $c['Field']; }

// --- Dynamic Column Detection for users ---
$userColsForSex = [];
$resU = $conn->query("SHOW COLUMNS FROM users");
if ($resU) { while($c = $resU->fetch_assoc()) $userColsForSex[] = $c['Field']; }

$ipPkCol = first_existing_column($ipColumns, ['ip_member_id', 'id']) ?? 'ip_member_id';
$userPkColForJoin = first_existing_column($userColsForSex, ['userid', 'user_id', 'id']) ?? 'userid';
$ipNameCol = first_existing_column($ipColumns, ['full_name', 'name', 'member_name']);
if ($ipNameCol) {
    $ipNameExpr = "i.`{$ipNameCol}`";
} elseif (in_array('first_name', $ipColumns) && in_array('last_name', $ipColumns)) {
    $ipNameExpr = "TRIM(CONCAT_WS(' ', i.first_name, i.middle_name, i.last_name))";
} else {
    $ipNameExpr = "'Unknown Member'";
}

$regDateExpr = ($c = first_existing_column($ipColumns, ['registration_date', 'created_at', 'date_registered'])) ? "i.`$c`" : "a.application_date";
$dobExpr = ($c = first_existing_column($ipColumns, ['birthdate', 'date_of_birth'])) ? "i.`$c`" : (($c = first_existing_column($detailColumns, ['birthdate', 'date_of_birth'])) ? "d.`$c`" : "NULL");
$pobExpr = ($c = first_existing_column($ipColumns, ['place_of_birth', 'birth_place'])) ? "i.`$c`" : (($c = first_existing_column($detailColumns, ['place_of_birth', 'birth_place'])) ? "d.`$c`" : "NULL");
$addrExpr = ($c = first_existing_column($ipColumns, ['current_address', 'address'])) ? "i.`$c`" : (($c = first_existing_column($detailColumns, ['current_address', 'address', 'specific_current_address'])) ? "d.`$c`" : "NULL");
$contactExpr = ($c = first_existing_column($ipColumns, ['contact_information', 'phone_number', 'mobile'])) ? "i.`$c`" : (($c = first_existing_column($detailColumns, ['contact_information', 'phone_number', 'mobile', 'mobile_number'])) ? "d.`$c`" : "NULL");
$memberIdStrExpr = ($c = first_existing_column($ipColumns, ['display_id', 'member_id'])) ? "i.`$c`" : "NULL";

$rawSexSource = ($c = first_existing_column($ipColumns, ['sex', 'gender'])) ? "i.`$c`" : (($c = first_existing_column($detailColumns, ['sex', 'gender'])) ? "d.`$c`" : (($c = first_existing_column($userColsForSex, ['sex', 'gender'])) ? "u.`$c`" : "NULL"));
$sexExpr = "CASE 
    WHEN LOWER(TRIM($rawSexSource)) IN ('m', 'male') THEN 'Male' 
    WHEN LOWER(TRIM($rawSexSource)) IN ('f', 'female') THEN 'Female' 
    ELSE 'N/A' END";

$barangayExpr = ($c = first_existing_column($ipColumns, ['barangay', 'location'])) ? "i.`$c`" : (($c = first_existing_column($detailColumns, ['barangay', 'location'])) ? "d.`$c`" : "NULL");
$tribeIdExpr = ($c = first_existing_column($ipColumns, ['tribe_clan', 'tribe_id'])) ? "i.`$c`" : (($c = first_existing_column($detailColumns, ['tribe', 'tribe_clan', 'tribe_id'])) ? "d.`$c`" : "NULL");

// Build SQL query with filters
$selectSql = "SELECT i.{$ipPkCol} AS ip_member_id, i.user_id, 
    {$ipNameExpr} AS full_name, 
    {$dobExpr} AS birthdate, {$pobExpr} AS place_of_birth, 
    {$addrExpr} AS current_address, {$contactExpr} AS contact_information, 
    {$memberIdStrExpr} AS member_id, {$sexExpr} AS sex, 
    {$tribeIdExpr} AS tribe_clan, t.tribe_name, {$barangayExpr} AS barangay, 
    {$regDateExpr} AS registration_date, u.account_status AS user_account_status
    FROM ipmembers i
    INNER JOIN applications a ON i.{$ipPkCol} = a.ip_member_id
    LEFT JOIN ip_member_details d ON i.{$ipPkCol} = d.ip_member_id
    LEFT JOIN tribes t ON {$tribeIdExpr} = t.tribe_id
    LEFT JOIN users u ON i.user_id = u.{$userPkColForJoin}";

$countSql = "SELECT COUNT(*) AS total
    FROM ipmembers i
    INNER JOIN applications a ON i.{$ipPkCol} = a.ip_member_id
    LEFT JOIN ip_member_details d ON i.{$ipPkCol} = d.ip_member_id
    LEFT JOIN tribes t ON {$tribeIdExpr} = t.tribe_id";
$whereClauses = ["i.user_id IS NOT NULL", "a.{$appStatusCol} = 'approved'"];
$params = [];
$types = '';


$currentRole = normalize_role((string) ($_SESSION['role'] ?? ''));
$isAdmin = $currentRole === 'admin';

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



$roleLabel = 'IP Member';
if ($currentRole === 'admin') {
    $roleLabel = 'System Admin';
} elseif ($currentRole === 'tribe_leader') {
    $roleLabel = 'Elder';
}



// Add search filter
if ($searchQuery !== '') {
    $searchFields = [$memberIdStrExpr, $ipNameExpr, "t.tribe_name", $barangayExpr];
    if (in_array('first_name', $ipColumns)) $searchFields[] = "i.first_name";
    if (in_array('last_name', $ipColumns)) $searchFields[] = "i.last_name";
    
    $whereClauses[] = "(" . implode(" LIKE ? OR ", $searchFields) . " LIKE ?)";
    
    $searchParam = '%' . $searchQuery . '%';
    for($i=0; $i<count($searchFields); $i++) { $params[] = $searchParam; $types .= 's'; }
}

// Add Tribe filter
if ($selectedTribe !== '' && $selectedTribe !== 'All Tribes') {
    $whereClauses[] = "t.tribe_name = ?";
    $params[] = $selectedTribe; $types .= 's';
}

// Add Barangay filter
if ($selectedBarangay !== '' && $selectedBarangay !== 'All Barangays') {
    $whereClauses[] = "{$barangayExpr} = ?";
    $params[] = $selectedBarangay; $types .= 's';
}

// Append WHERE clause if needed
if (!empty($whereClauses)) {
    $whereClause = ' WHERE ' . implode(' AND ', $whereClauses);
    $selectSql .= $whereClause;
    $countSql .= $whereClause;
}

$countStmt = $conn->prepare($countSql);
if ($countStmt) {
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    if ($countResult instanceof mysqli_result) {
        $countRow = $countResult->fetch_assoc();
        $totalRecords = (int) ($countRow['total'] ?? 0);
    }
    $countStmt->close();
}

$totalPages = max(1, (int) ceil($totalRecords / $perPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $perPage;
$sql = $selectSql . " ORDER BY {$regDateExpr} DESC LIMIT ? OFFSET ?";

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
                $ipmembers[] = $row;
            }
        }
        $stmt->close();
    } else {
        $errorMessage = "Prepare failed: " . $conn->error;
    }
} else {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ii', $perPage, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $ipmembers[] = $row;
            }
        }
        $stmt->close();
    } else {
        $errorMessage = "Prepare failed: " . $conn->error;
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
        return 'Online Now';
    }

    if (empty($lastActive)) {
        return 'No activity';
    }

    $timestamp = strtotime((string) $lastActive);
    if ($timestamp === false) {
        return (string) $lastActive;
    }

    return date('M d, Y h:i A', $timestamp);
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
    <link rel="stylesheet" href="../css/style.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <title>IP Lineage - IP Members</title>
    <style>
        body { background-color: #f3f4f1; color: #262626; font-family: 'Plus Jakarta Sans', sans-serif; }
        .bg-sidebar { background-color: #ffffff; border-right: 1px solid #dedede; }
        .bg-card-custom { background-color: #ffffff; border: 1px solid #dedede; }
        .sidebar-item-active { background-color: #262626; color: #ffffff; }
        .text-muted { color: #666666; }
        .border-line { border-bottom: 1px solid #dedede; }
        .status-badge-verified { background-color: #dcfce7; color: #15803d; }
        .status-badge-pending { background-color: #fef3c7; color: #b45309; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-thumb { background: #dedede; border-radius: 10px; }
    </style>
</head>

<body class="min-h-screen">

    <?php $activeNav = 'ip_members'; include __DIR__ . '/shared/sidebar.php'; ?>

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

        <?php if ($successMessage !== ''): ?>
            <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMessage)): ?>
            <div class="bg-red-50 border border-red-200 text-red-800 px-6 py-4 rounded-xl mb-6">
                <p class="font-bold">Database Error:</p>
                <p class="text-sm"><?php echo escape_html($errorMessage); ?></p>
                <p class="text-xs mt-2">Check the connection and schema mapping.</p>
            </div>
        <?php endif; ?>

        <section class="mb-8">
            <h1 class="text-2xl font-bold text-[#262626]">IP Members</h1>
        </section>

        <form action="ip_members.php" method="get" class="flex flex-col md:flex-row items-center justify-between gap-4 mb-6">
            <div class="relative w-full md:w-96">
                <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none z-10"></i>
                <input type="text" name="query" value="<?php echo escape_html($searchQuery); ?>" placeholder="Search by ID, name, or tribe..." 
                    class="w-full bg-white border border-[#dedede] rounded-xl py-3 pl-12 pr-4 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-sm">
            </div>

            <div class="flex items-center gap-3 ml-auto">
                <div class="relative min-w-[160px]">
                    <select name="tribe" onchange="this.form.submit()" class="appearance-none w-full bg-white border border-[#dedede] rounded-xl pl-4 pr-10 py-3 text-sm font-semibold text-[#262626] outline-none focus:ring-2 focus:ring-[#262626]/10 transition cursor-pointer">
                        <option value="">All Tribes</option>
                        <option value="Iraynon-Bukidnon" <?php echo $selectedTribe === 'Iraynon-Bukidnon' ? 'selected' : ''; ?>>Iraynon-Bukidnon</option>
                        <option value="Ati Tribe" <?php echo $selectedTribe === 'Ati Tribe' ? 'selected' : ''; ?>>Ati Tribe</option>
                    </select>
        
                    <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400">
                        <i data-lucide="chevron-down" class="w-4 h-4"></i>
                    </div>
                </div>
                <div class="relative min-w-[160px]">
                    <select name="barangay" onchange="this.form.submit()" class="appearance-none w-full bg-white border border-[#dedede] rounded-xl pl-4 pr-10 py-3 text-sm font-semibold text-[#262626] outline-none focus:ring-2 focus:ring-[#262626]/10 transition cursor-pointer">
                        <option value="">All Barangays</option>
                        <option value="Villafont" <?php echo $selectedBarangay === 'Villafont' ? 'selected' : ''; ?>>Villafont</option>
                        <option value="Hamtic" <?php echo $selectedBarangay === 'Hamtic' ? 'selected' : ''; ?>>Hamtic</option>
                    </select>
        
                    <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400">
                        <i data-lucide="chevron-down" class="w-4 h-4"></i>
                    </div>
                </div>
            </div>
        </form>

        <div class="relative">

        <!-- FLOATING MEMBER CARD OVERLAY -->
        <aside id="floatingMemberCard" class="hidden fixed inset-0 z-[60] items-center justify-center p-4 sm:p-6">
            <div id="floatingMemberBackdrop" class="absolute inset-0 bg-black/40 backdrop-blur-xs"></div>
            
            <div class="relative z-10 w-full max-w-4xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-[0_10px_30px_rgba(0,0,0,0.15)] border border-[#ececea] flex flex-col">
                
                <!-- Card Header Layout -->
                <div class="px-6 py-5 border-b border-[#ececea] flex items-center justify-between bg-gray-50/50">
                    <div class="flex items-center gap-2">
                        <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <h3 class="text-xs font-bold uppercase tracking-wider text-[#262626]">Verified Member Record</h3>
                    </div>
                    <button id="closeFloatingMemberCard" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-[#262626] transition-all duration-200">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>

                <!-- Main Content Area Split -->
                <div class="grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-[#ececea]">
                    
                    <!-- Left Sidebar Profile Panel -->
                    <div class="p-6 bg-gradient-to-b from-gray-50/30 to-white flex flex-col items-center text-center justify-center col-span-1 min-w-[240px]">
                        <div class="flex items-center justify-center h-28 w-28 shrink-0 balance-profile-container">
                            <div id="floatingInitials" class="h-28 w-28 rounded-full bg-gray-100 text-[#262626] flex items-center justify-center shadow-md font-bold text-3xl tracking-wide uppercase border-4 border-white ring-1 ring-gray-200 aspect-square object-cover border-[#dedede]">
                                --
                            </div>
                        </div>
                        <h2 id="floatingFullName" class="mt-4 text-xl font-bold text-[#262626] tracking-tight">No member selected</h2>
                        <div class="mt-2 inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-700 border border-gray-200">
                            ID: <span id="floatingMemberId" class="ml-1 font-bold text-[#262626]">-</span>
                        </div>
                    </div>

                    <!-- Right Structured Information Fields -->
                    <div class="p-6 col-span-2 space-y-6">
                        
                        <!-- Core Identity Block -->
                        <div>
                            <h4 class="mb-3 text-[11px] font-bold uppercase tracking-wider text-gray-400">Core Identity</h4>
                        <div class="grid grid-cols-1 gap-4">
                            <div class="bg-gray-50/60 p-4 rounded-xl border border-gray-100">
                                <span class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest">Full Registered Name</span>
                                <span id="floatingCoreFullName" class="text-base font-bold text-[#262626] mt-1 block">-</span>
                            </div>
                            </div>
                        </div>

                        <!-- Vital & Heritage Details Block -->
                        <div>
                            <h4 class="mb-3 text-[11px] font-bold uppercase tracking-wider text-gray-400">Vital & Heritage Information</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
                                <!-- Row 1: Birth Info -->
                                <div class="flex items-center justify-between py-2.5 border-b border-[#ececea]">
                                    <span class="text-xs font-medium text-gray-500">Birthdate</span>
                                    <span id="floatingBirthdate" class="text-xs font-bold text-[#262626]">-</span>
                                </div>
                                <div class="flex items-center justify-between py-2.5 border-b border-[#ececea]">
                                    <span class="text-xs font-medium text-gray-500">Sex / Gender</span>
                                    <span id="floatingSexText" class="text-xs font-bold text-[#262626]">-</span>
                                </div>
                                <div class="flex items-center justify-between py-2.5 border-b border-[#ececea]">
                                    <span class="text-xs font-medium text-gray-500">Place of Birth</span>
                                    <span id="floatingPlaceOfBirth" class="text-xs font-bold text-[#262626] max-w-[180px] text-right truncate" title="-">-</span>
                                </div>
                                <!-- Row 2: Cultural / Local Identity -->
                                <div class="flex items-center justify-between py-2.5 border-b border-[#ececea] sm:border-b-0">
                                    <span class="text-xs font-medium text-gray-500">Tribe / Clan</span>
                                    <span id="floatingTribeClan" class="text-xs font-bold text-amber-800 bg-amber-50 border border-amber-200/70 px-2.5 py-0.5 rounded-md">-</span>
                                </div>
                                <div class="flex items-center justify-between py-2.5 border-b border-[#ececea] sm:border-b-0">
                                    <span class="text-xs font-medium text-gray-500">Barangay</span>
                                    <span id="floatingBarangay" class="text-xs font-bold text-[#262626]">-</span>
                                </div>
                            </div>
                        </div>

                        <!-- Contact & Locality Block -->
                        <div class="pt-2 border-t border-dashed border-[#ececea]">
                            <h4 class="mb-3 text-[11px] font-bold uppercase tracking-wider text-gray-400">Contact & Address</h4>
                            <div class="space-y-3">
                                <div class="flex items-start gap-4">
                                    <div class="w-20 text-[11px] font-medium text-gray-400 uppercase mt-0.5 shrink-0">Contact</div>
                                    <div id="floatingContactInformation" class="text-xs font-semibold text-[#262626] bg-gray-50/80 px-3 py-1.5 rounded-lg w-full">-</div>
                                </div>
                                <div class="flex items-start gap-4">
                                    <div class="w-20 text-[11px] font-medium text-gray-400 uppercase mt-0.5 shrink-0">Address</div>
                                    <div id="floatingCurrentAddress" class="text-xs font-semibold text-[#262626] bg-gray-50/80 px-3 py-1.5 rounded-lg w-full">-</div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Bottom Footer Actions Block -->
                <div class="px-6 py-4 bg-gray-50/50 border-t border-[#ececea] flex justify-end gap-3">
                    <button type="button" onclick="closeMemberCard()" class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-xl hover:bg-white text-gray-600 transition-all">
                        Close View
                    </button>
                    <a id="floatingFamilyTreeLink" href="family_lineage.php" class="inline-flex items-center gap-2 bg-[#262626] text-white px-4 py-2 rounded-xl text-xs font-bold hover:bg-[#404040] transition-all shadow-sm">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="3" x2="6" y2="15"></line><circle cx="18" cy="6" r="3"></circle><circle cx="6" cy="18" r="3"></circle><path d="M18 9a9 9 0 0 1-9 9"></path></svg>
                        View Full Details
                    </a>
                </div>

            </div>
        </aside>

        <div class="bg-card-custom rounded-2xl overflow-hidden shadow-sm">
            <table class="w-full text-left">
                <thead class="text-left">
                    <tr class="text-[11px] uppercase text-gray-400 border-line bg-gray-50/50">
                        <th class="px-6 py-4 font-bold tracking-wider">Member Details</th>
                        <th class="px-6 py-4 font-bold tracking-wider">Tribe / Clan</th>
                        <th class="px-6 py-4 font-bold tracking-wider">Barangay</th>
                        <th class="px-6 py-4 font-bold tracking-wider">Registration Date</th>
                        <th class="px-8 py-4 font-bold tracking-wider">Family Lineage</th>
                        <th class="px-6 py-4 font-bold tracking-wider text-right">Actions</th>
                    </tr>
                </thead>
                <tbody id="memberTableBody" class="text-sm divide-y divide-[#dedede]">
                    <?php if (!empty($ipmembers)): ?>
                        <?php foreach ($ipmembers as $ipmember): ?>
                            <?php $fullName = $ipmember['full_name'] ?? 'Unknown Member'; ?>
                            <?php
                                $tribeLabel = trim((string) ($ipmember['tribe_name'] ?? ''));
                                if ($tribeLabel === '') {
                                    $tribeLabel = !empty($ipmember['tribe_clan']) ? 'Tribe ID ' . $ipmember['tribe_clan'] : 'Not registered yet';
                                }
                                $sexValue = strtolower(trim((string) ($ipmember['sex'] ?? '')));
                                $initialClass = 'bg-gray-100 text-[#262626] border-[#dedede]';
                                if ($sexValue === 'male' || $sexValue === 'm') {
                                    $initialClass = 'bg-[#18181b] text-[#e4e4e7] border-[#3f3f46]';
                                } elseif ($sexValue === 'female' || $sexValue === 'f') {
                                    $initialClass = 'bg-[#e4e4e7] text-[#18181b] border-[#a1a1aa]';
                                }

                                $registrationLabel = 'Not registered yet';
                                $regTimestamp = !empty($ipmember['registration_date']) ? strtotime((string)$ipmember['registration_date']) : false;
                                if ($regTimestamp && $regTimestamp > 0) {
                                    $registrationLabel = date('M d, Y', $regTimestamp);
                                }
                            ?>
                            <?php 
                                $associatedUserId = (int)($ipmember['user_id'] ?? 0);
                                $accountStatus = strtolower(trim((string)($ipmember['user_account_status'] ?? 'active')));
                                $isAccountDisabled = $accountStatus === 'disabled';
                            ?>
                            <tr class="member-row cursor-pointer hover:bg-gray-100 transition-colors duration-200"
                                data-full-name="<?php echo escape_html($fullName !== '' ? $fullName : 'Not registered yet'); ?>"
                                data-initials="<?php echo escape_html(get_initials($fullName)); ?>"
                                data-ip-member-id="<?php echo escape_html($ipmember['ip_member_id'] ?? 'Not registered yet'); ?>"
                                data-first-name="<?php echo escape_html($ipmember['first_name'] ?? 'Not registered yet'); ?>"
                                data-middle-name="<?php echo escape_html($ipmember['middle_name'] ?? 'Not registered yet'); ?>"
                                data-last-name="<?php echo escape_html($ipmember['last_name'] ?? 'Not registered yet'); ?>"
                                data-birthdate="<?php echo escape_html($ipmember['birthdate'] ?? 'Not registered yet'); ?>"
                                data-place-of-birth="<?php echo escape_html($ipmember['place_of_birth'] ?? 'Not registered yet'); ?>"
                                data-current-address="<?php echo escape_html($ipmember['current_address'] ?? 'Not registered yet'); ?>"
                                data-contact-information="<?php echo escape_html($ipmember['contact_information'] ?? 'Not registered yet'); ?>"
                                data-member-id="<?php echo escape_html($ipmember['member_id'] ?? 'Not Registered'); ?>"
                                data-sex="<?php echo escape_html($ipmember['sex'] ?? ''); ?>"
                                data-tribe-clan="<?php echo escape_html($tribeLabel); ?>"
                                data-barangay="<?php echo escape_html($ipmember['barangay'] ?? 'Not registered yet'); ?>">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-xs border <?php echo escape_html($initialClass); ?>">
                                            <?php echo escape_html(get_initials($fullName)); ?>
                                        </div>
                                        <div>
                                            <p class="font-bold text-[#262626]"><?php echo escape_html($fullName !== '' ? $fullName : 'Not registered yet'); ?></p>
                                            <?php if (!empty($ipmember['member_id'])): ?>
                                                <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider"><?php echo escape_html($ipmember['member_id']); ?></p>
                                            <?php else: ?>
                                                <span class="inline-block mt-1 bg-gray-100 text-gray-500 text-[9px] font-bold px-1.5 py-0.5 rounded border border-gray-200 uppercase">Not Registered</span>
                                            <?php endif; ?>
                                            <?php if ($isAccountDisabled): ?>
                                                <span class="inline-block mt-1 bg-red-50 text-red-600 text-[9px] font-bold px-1.5 py-0.5 rounded border border-red-100 uppercase">Account Blocked</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-[#262626] font-semibold text-xs">
                                    <?php echo escape_html($tribeLabel); ?>
                                </td>
                                <td class="px-6 py-4 text-[#262626] font-semibold text-xs">
                                    <?php echo escape_html($ipmember['barangay'] ?? 'Not registered yet'); ?>
                                </td>
                                <td class="px-6 py-4 text-gray-600 text-xs">
                                    <?php 
                                        echo escape_html($registrationLabel);
                                    ?>
                                </td>
                                <td class="px-8 py-3 text-left">
                                    <?php
                                        $treeMemberKey = !empty($ipmember['ip_member_id'])
                                            ? (string) ((int) $ipmember['ip_member_id'])
                                            : (string) ($ipmember['member_id'] ?? '');
                                    ?>
                                    <?php if ($treeMemberKey !== ''): ?>
                                        <a href="verified_lineage.php?member_id=<?php echo rawurlencode($treeMemberKey); ?>" class="row-action inline-flex items-center gap-2 bg-blue-50 text-blue-600 px-3 py-2 rounded-lg text-xs font-bold hover:bg-blue-100 transition">
                                            <i data-lucide="git-branch" class="w-4 h-4"></i>
                                            <span class="text-xs font-semibold">View Tree</span>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">No tree key</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <?php if ($associatedUserId > 0): ?>
                                    <div class="relative inline-block">
                                        <button type="button" class="action-menu-toggle row-action p-2 hover:bg-gray-100 rounded-lg transition" title="More actions">
                                            <i data-lucide="more-horizontal" class="w-4 h-4 text-gray-400"></i>
                                        </button>
                                        <div class="action-menu hidden absolute right-0 mt-1 w-52 bg-white border border-[#dedede] rounded-xl shadow-lg z-50 p-1.5">
                                            <button type="button" 
                                                    class="status-toggle-trigger w-full text-left px-3 py-2 text-xs <?php echo $isAccountDisabled ? 'text-emerald-700 hover:bg-emerald-50' : 'text-red-700 hover:bg-red-50'; ?> transition-all rounded-md font-bold flex items-center gap-2"
                                                    data-user-id="<?php echo $associatedUserId; ?>"
                                                    data-full-name="<?php echo escape_html($fullName); ?>"
                                                    data-action-type="<?php echo $isAccountDisabled ? 'enable' : 'disable'; ?>"
                                                    data-current-status="<?php echo $accountStatus; ?>">
                                                <i data-lucide="<?php echo $isAccountDisabled ? 'check-circle' : 'ban'; ?>" class="w-3.5 h-3.5"></i>
                                                <?php echo $isAccountDisabled ? 'Enable Account' : 'Disable Account'; ?>
                                            </button>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr class="hover:bg-gray-100 transition-colors duration-200">
                            <td colspan="6" class="px-6 py-12 text-center">
                                <i data-lucide="users-x" class="w-12 h-12 mx-auto mb-3 text-gray-300"></i>
                                <p class="text-sm font-semibold text-gray-500 mb-1">No members found</p>
                                <p class="text-xs text-gray-400">
                                    <?php if (!empty($searchQuery)): ?>
                                        Try adjusting your search criteria
                                    <?php else: ?>
                                        The table "ipmembers" may be empty or doesn't exist yet
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="p-6 border-t border-[#dedede] flex justify-between items-center bg-gray-50/30">
                <p class="text-[10px] font-bold text-gray-400 uppercase">Showing <?php echo count($ipmembers); ?> of <?php echo (int) $totalRecords; ?> Members</p>
                <div class="flex gap-2">
                    <?php if ($currentPage > 1): ?>
                        <a href="?query=<?php echo urlencode($searchQuery); ?>&tribe=<?php echo urlencode($selectedTribe); ?>&barangay=<?php echo urlencode($selectedBarangay); ?>&page=<?php echo $currentPage - 1; ?>" class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-lg hover:bg-white transition">Previous</a>
                    <?php else: ?>
                        <span class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-lg text-gray-300 cursor-not-allowed">Previous</span>
                    <?php endif; ?>

                    <span class="px-3 py-2 text-xs font-bold text-gray-500">Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>

                    <?php if ($currentPage < $totalPages): ?>
                        <a href="?query=<?php echo urlencode($searchQuery); ?>&tribe=<?php echo urlencode($selectedTribe); ?>&barangay=<?php echo urlencode($selectedBarangay); ?>&page=<?php echo $currentPage + 1; ?>" class="px-4 py-2 text-xs font-bold bg-[#262626] text-white rounded-lg hover:bg-[#404040] transition">Next</a>
                    <?php else: ?>
                        <span class="px-4 py-2 text-xs font-bold bg-[#262626]/30 text-white rounded-lg cursor-not-allowed">Next</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        </div>
    </div>

    <!-- Status Toggle Confirmation Modal -->
    <div id="statusToggleModal" class="hidden fixed inset-0 z-[70] items-center justify-center p-4">
        <div id="modalBackdrop" class="absolute inset-0 bg-black/40 backdrop-blur-xs"></div>
        <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-[0_10px_30px_rgba(0,0,0,0.15)] border border-[#ececea] p-6">
            <h3 id="modalTitle" class="text-base font-bold text-[#262626] mb-2 flex items-center gap-2"></h3>
            <p id="modalDescription" class="text-xs text-gray-500 mb-6 leading-relaxed"></p>
            
            <form id="statusModalForm" method="POST">
                <input type="hidden" name="target_user_id" id="modalUserId">
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

        const memberTableBody = document.getElementById('memberTableBody');
        const floatingMemberCard = document.getElementById('floatingMemberCard');
        const closeFloatingMemberCard = document.getElementById('closeFloatingMemberCard');
        const floatingMemberBackdrop = document.getElementById('floatingMemberBackdrop');

        const openMemberCard = () => {
            if (!floatingMemberCard) {
                return;
            }

            floatingMemberCard.classList.remove('hidden');
            floatingMemberCard.classList.add('flex');
            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';
        };

        const closeMemberCard = () => {
            if (!floatingMemberCard) {
                return;
            }

            floatingMemberCard.classList.add('hidden');
            floatingMemberCard.classList.remove('flex');
            document.body.style.overflow = '';
            document.documentElement.style.overflow = '';
        };

        if (memberTableBody && floatingMemberCard) {
            const floatingInitials = document.getElementById('floatingInitials');
            const floatingFullName = document.getElementById('floatingFullName');
            const floatingCoreFullName = document.getElementById('floatingCoreFullName');
            const floatingBirthdate = document.getElementById('floatingBirthdate');
            const floatingPlaceOfBirth = document.getElementById('floatingPlaceOfBirth');
            const floatingCurrentAddress = document.getElementById('floatingCurrentAddress');
            const floatingContactInformation = document.getElementById('floatingContactInformation');
            const floatingMemberId = document.getElementById('floatingMemberId');
            const floatingTribeClan = document.getElementById('floatingTribeClan');
            const floatingBarangay = document.getElementById('floatingBarangay');
            const floatingParents = document.getElementById('floatingParents');
            const floatingSiblings = document.getElementById('floatingSiblings');
            const floatingSpouse = document.getElementById('floatingSpouse');
            const floatingChildren = document.getElementById('floatingChildren');
            const floatingFamilyTreeLink = document.getElementById('floatingFamilyTreeLink');

            memberTableBody.addEventListener('click', function (event) {
                if (event.target.closest('.row-action')) {
                    return;
                }

                const row = event.target.closest('tr.member-row');
                if (!row) {
                    return;
                }

                // Inside your existing click event function:
                floatingFullName.textContent = row.dataset.fullName || 'Not registered yet';
                if (floatingCoreFullName) floatingCoreFullName.textContent = row.dataset.fullName || 'Not registered yet';
                floatingBirthdate.textContent = row.dataset.birthdate || 'Not registered yet';
                floatingPlaceOfBirth.textContent = row.dataset.placeOfBirth || 'Not registered yet';
                floatingCurrentAddress.textContent = row.dataset.currentAddress || 'Not registered yet';
                floatingContactInformation.textContent = row.dataset.contactInformation || 'Not registered yet';
                floatingMemberId.textContent = row.dataset.memberId || 'Not Registered';
                floatingTribeClan.textContent = row.dataset.tribeClan || 'Not registered yet';
                floatingBarangay.textContent = row.dataset.barangay || 'Not registered yet';

                const rawSex = (row.dataset.sex || '').toLowerCase();
                const displaySex = (rawSex === 'm' || rawSex === 'male') ? 'Male' : ((rawSex === 'f' || rawSex === 'female') ? 'Female' : 'N/A');
                document.getElementById('floatingSexText').textContent = displaySex;

                // Handle dynamic profile context letter badge
                if (floatingInitials) {
                    floatingInitials.textContent = row.dataset.initials || '--';

                    const sex = (row.dataset.sex || '').toLowerCase();
                    floatingInitials.className = 'h-28 w-28 rounded-full flex items-center justify-center shadow-md font-bold text-3xl tracking-wide uppercase border-4 border-white ring-1 ring-gray-200 aspect-square object-cover';
                    if (sex === 'male' || sex === 'm') {
                        floatingInitials.classList.add('bg-[#18181b]', 'text-[#e4e4e7]', 'border-[#3f3f46]');
                    } else if (sex === 'female' || sex === 'f') {
                        floatingInitials.classList.add('bg-[#e4e4e7]', 'text-[#18181b]', 'border-[#a1a1aa]');
                    } else {
                        floatingInitials.classList.add('bg-gray-100', 'text-[#262626]', 'border-[#dedede]');
                    }
                }

                if (floatingFamilyTreeLink) {
                    const selectedMemberId = row.dataset.ipMemberId || row.dataset.memberId || '';
                    if (selectedMemberId && selectedMemberId !== 'Not registered yet') {
                        // Direct matching to family lineage viewer dashboard
                        floatingFamilyTreeLink.href = 'family_lineage.php?member_id=' + encodeURIComponent(selectedMemberId);
                    } else {
                        floatingFamilyTreeLink.href = 'family_lineage.php';
                    }
                }

                openMemberCard();
            });
        }

        if (closeFloatingMemberCard && floatingMemberCard) {
            closeFloatingMemberCard.addEventListener('click', function () {
                closeMemberCard();
            });
        }

        if (floatingMemberBackdrop) {
            floatingMemberBackdrop.addEventListener('click', function () {
                closeMemberCard();
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && floatingMemberCard && !floatingMemberCard.classList.contains('hidden')) {
                closeMemberCard();
            }
        });

        // --- Account Status Toggle Modal Logic ---
        function openStatusModal(userId, fullName, actionType, currentStatus) {
            const modal = document.getElementById('statusToggleModal');
            const title = document.getElementById('modalTitle');
            const description = document.getElementById('modalDescription');
            const confirmBtn = document.getElementById('modalConfirmBtn');
            
            document.getElementById('modalUserId').value = userId;
            document.getElementById('modalCurrentStatus').value = currentStatus;

            if (actionType === 'disable') {
                title.innerHTML = '<span class="inline-block w-2.5 h-2.5 rounded-full bg-red-500"></span> Confirm Deactivation';
                description.innerHTML = `Are you sure you want to <b>DISABLE</b> the associated login account for <b>${fullName}</b>? <br><br>The user will be immediately logged out and blocked from the system.`;
                confirmBtn.className = 'px-4 py-2 text-xs font-bold bg-red-600 hover:bg-red-700 text-white rounded-xl transition-all shadow-sm';
                confirmBtn.textContent = 'Confirm Disable';
            } else {
                title.innerHTML = '<span class="inline-block w-2.5 h-2.5 rounded-full bg-emerald-500"></span> Restore Access';
                description.innerHTML = `Are you sure you want to <b>re-enable</b> the login account for <b>${fullName}</b>?`;
                confirmBtn.className = 'px-4 py-2 text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl transition-all shadow-sm';
                confirmBtn.textContent = 'Confirm Enable';
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }

        function closeStatusModal() {
            const modal = document.getElementById('statusToggleModal');
            if (modal) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                document.body.style.overflow = '';
            }
        }

        document.getElementById('modalBackdrop')?.addEventListener('click', closeStatusModal);

        document.addEventListener('click', (e) => {
            const toggle = e.target.closest('.action-menu-toggle');
            const statusTrigger = e.target.closest('.status-toggle-trigger');
            const menu = e.target.closest('.action-menu');
            
            if (statusTrigger) {
                const { userId, fullName, actionType, currentStatus } = statusTrigger.dataset;
                openStatusModal(userId, fullName, actionType, currentStatus);
                if (menu) menu.classList.add('hidden');
            } else if (toggle) {
                const targetMenu = toggle.nextElementSibling;
                document.querySelectorAll('.action-menu').forEach(m => { if (m !== targetMenu) m.classList.add('hidden'); });
                targetMenu.classList.toggle('hidden');
            } else if (!menu) {
                document.querySelectorAll('.action-menu').forEach(m => m.classList.add('hidden'));
            }
        });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeStatusModal(); });
    </script>
</body>
</html>