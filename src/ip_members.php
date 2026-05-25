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
$perPage = 5;
$currentPage = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($currentPage < 1) {
    $currentPage = 1;
}
$totalRecords = 0;
$totalPages = 1;

$ipmembers = [];
$error_message = '';

// Build SQL query with filters
$selectSql = "SELECT i.ip_member_id, i.first_name, i.middle_name, i.last_name, i.birthdate, i.place_of_birth, i.current_address, i.contact_information, i.member_id, i.sex, i.tribe_clan, t.tribe_name, i.barangay, i.registration_date
    FROM ipmembers i
    LEFT JOIN tribes t ON i.tribe_clan = t.tribe_id";
$countSql = "SELECT COUNT(*) AS total
    FROM ipmembers i
    LEFT JOIN tribes t ON i.tribe_clan = t.tribe_id";
$whereClauses = [];
$params = [];
$types = '';


$currentRole = normalize_role((string) ($_SESSION['role'] ?? ''));
$isAdmin = $currentRole === 'admin';

$displayName = trim((string) ($_SESSION['name'] ?? 'User'));
if ($displayName === '') {
    $displayName = 'User';
}

$nameParts = preg_split('/\s+/', $displayName);
$initials = strtoupper(substr((string) ($nameParts[0] ?? 'U'), 0, 1));
if (!empty($nameParts[1])) {
    $initials .= strtoupper(substr((string) $nameParts[1], 0, 1));
}


$roleLabel = 'IP Member';
if ($currentRole === 'admin') {
    $roleLabel = 'System Admin';
} elseif ($currentRole === 'tribe_leader') {
    $roleLabel = 'Tribe Leader';
}



// Add search filter
if ($searchQuery !== '') {
    $whereClauses[] = "(i.member_id LIKE ? OR i.first_name LIKE ? OR i.middle_name LIKE ? OR i.last_name LIKE ? OR CAST(i.tribe_clan AS CHAR) LIKE ? OR t.tribe_name LIKE ? OR i.barangay LIKE ?)";
    $searchParam = '%' . $searchQuery . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= 'sssssss';
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
$sql = $selectSql . " ORDER BY i.registration_date DESC LIMIT ? OFFSET ?";

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
        $error_message = "Prepare failed: " . $conn->error;
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
        $error_message = "Prepare failed: " . $conn->error;
    }
}

function escape_html($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function build_full_name($firstName, $middleName, $lastName) {
    $parts = [];

    foreach ([$firstName, $middleName, $lastName] as $part) {
        $part = trim((string) $part);
        if ($part !== '') {
            $parts[] = $part;
        }
    }

    return implode(' ', $parts);
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

        <?php if (!empty($error_message)): ?>
            <div class="bg-red-50 border border-red-200 text-red-800 px-6 py-4 rounded-xl mb-6">
                <p class="font-bold">Database Error:</p>
                <p class="text-sm"><?php echo escape_html($error_message); ?></p>
                <p class="text-xs mt-2">Query: <?php echo escape_html($sql); ?></p>
            </div>
        <?php endif; ?>

        <section class="mb-8">
            <h1 class="text-2xl font-bold text-[#262626]"><?php if ($isAdmin): ?>IP Members<?php else: ?>Lineage Management<?php endif; ?></h1>
        </section>

        <form action="ip_members.php" method="get" class="flex flex-col md:flex-row items-center justify-between gap-4 mb-6">
            <div class="relative w-full md:w-96">
                <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none z-10"></i>
                <input type="text" name="query" value="<?php echo escape_html($searchQuery); ?>" placeholder="Search by ID, name, or tribe..." 
                    class="w-full bg-white border border-[#dedede] rounded-xl py-3 pl-12 pr-4 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-sm">
            </div>

            <div class="flex items-center gap-3 ml-auto">
                <div class="relative min-w-[160px]">
                    <select class="appearance-none w-full bg-white border border-[#dedede] rounded-xl pl-4 pr-10 py-3 text-sm font-semibold text-[#262626] outline-none focus:ring-2 focus:ring-[#262626]/10 transition cursor-pointer">
                        <option>All Tribes</option>
                        <option>Iraynon-Bukidnon</option>
                    </select>
        
                    <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400">
                        <i data-lucide="chevron-down" class="w-4 h-4"></i>
                    </div>
                </div>
                <div class="relative min-w-[160px]">
                    <select class="appearance-none w-full bg-white border border-[#dedede] rounded-xl pl-4 pr-10 py-3 text-sm font-semibold text-[#262626] outline-none focus:ring-2 focus:ring-[#262626]/10 transition cursor-pointer">
                        <option>All Barangays</option>
                        <option>Villafont</option>
                    </select>
        
                    <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400">
                        <i data-lucide="chevron-down" class="w-4 h-4"></i>
                    </div>
                </div>
            </div>
        </form>

        <div class="relative">

        <aside id="floatingMemberCard" class="hidden fixed inset-0 z-[60] items-center justify-center p-4 sm:p-6">
            <div id="floatingMemberBackdrop" class="absolute inset-0 bg-black/40"></div>
            <div class="relative z-10 w-full max-w-4xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white p-5 shadow-[0_0_20px_rgba(0,0,0,0.3)]">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-sm font-bold uppercase tracking-wider text-[#262626]">Member Profile</h3>
                <button id="closeFloatingMemberCard" class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-[#262626] transition">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>

            <div class="mb-6 flex items-center gap-4 p-4">
                <div id="floatingInitials" class="h-24 w-24 flex-shrink-0 rounded-full bg-[#262626] text-white flex items-center justify-center">
                    <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                </div>
                <div>
                    <p id="floatingFullName" class="text-lg font-bold text-[#262626]">No member selected</p>
                    <p class="text-sm text-gray-500">examplemail@example.com</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <section class="rounded-xl bg-[#f8f8f7] p-4">
                    <h4 class="mb-3 text-xs font-bold uppercase tracking-wide text-[#262626]">Personal Info</h4>
                    <div class="space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">First Name</span><span id="floatingFirstName" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Middle Name</span><span id="floatingMiddleName" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Last Name</span><span id="floatingLastName" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Member ID</span><span id="floatingMemberId" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Birthdate</span><span id="floatingBirthdate" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Place of Birth</span><span id="floatingPlaceOfBirth" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Current Address</span><span id="floatingCurrentAddress" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Contact Information</span><span id="floatingContactInformation" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Tribe / Clan</span><span id="floatingTribeClan" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3"><span class="text-gray-500">Barangay</span><span id="floatingBarangay" class="font-semibold text-[#262626]">-</span></div>
                    </div>
                </section>

                <section class="rounded-xl bg-[#f8f8f7] p-4">
                    <h4 class="mb-3 text-xs font-bold uppercase tracking-wide text-[#262626]">Family Members</h4>
                    <div class="space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Parents</span><span id="floatingParents" class="font-semibold text-[#262626]">Open Family Tree</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Siblings</span><span id="floatingSiblings" class="font-semibold text-[#262626]">Open Family Tree</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Spouse</span><span id="floatingSpouse" class="font-semibold text-[#262626]">Open Family Tree</span></div>
                        <div class="flex items-center justify-between gap-3"><span class="text-gray-500">Children</span><span id="floatingChildren" class="font-semibold text-[#262626]">Open Family Tree</span></div>
                    </div>

                </section>
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
                            <?php $fullName = build_full_name($ipmember['first_name'] ?? '', $ipmember['middle_name'] ?? '', $ipmember['last_name'] ?? ''); ?>
                            <?php
                                $tribeLabel = trim((string) ($ipmember['tribe_name'] ?? ''));
                                if ($tribeLabel === '') {
                                    $tribeLabel = !empty($ipmember['tribe_clan']) ? 'Tribe ID ' . $ipmember['tribe_clan'] : 'N/A';
                                }
                                $sexValue = strtolower(trim((string) ($ipmember['sex'] ?? '')));
                                $initialClass = 'bg-gray-100 text-[#262626] border-[#dedede]';
                                if ($sexValue === 'male' || $sexValue === 'm') {
                                    $initialClass = 'bg-[#18181b] text-[#a1a1aa] border-[#3f3f46]';
                                } elseif ($sexValue === 'female' || $sexValue === 'f') {
                                    $initialClass = 'bg-[#e4e4e7] text-[#18181b] border-[#a1a1aa]';
                                }

                                $registrationLabel = 'N/A';
                                if (!empty($ipmember['registration_date'])) {
                                    $registrationLabel = date('M d, Y', strtotime($ipmember['registration_date']));
                                }
                            ?>
                            <tr class="member-row cursor-pointer hover:bg-gray-100 transition-colors duration-200"
                                data-full-name="<?php echo escape_html($fullName !== '' ? $fullName : 'N/A'); ?>"
                                data-initials="<?php echo escape_html(get_initials($fullName)); ?>"
                                data-ip-member-id="<?php echo escape_html($ipmember['ip_member_id'] ?? 'N/A'); ?>"
                                data-first-name="<?php echo escape_html($ipmember['first_name'] ?? 'N/A'); ?>"
                                data-middle-name="<?php echo escape_html($ipmember['middle_name'] ?? 'N/A'); ?>"
                                data-last-name="<?php echo escape_html($ipmember['last_name'] ?? 'N/A'); ?>"
                                data-birthdate="<?php echo escape_html($ipmember['birthdate'] ?? 'N/A'); ?>"
                                data-place-of-birth="<?php echo escape_html($ipmember['place_of_birth'] ?? 'N/A'); ?>"
                                data-current-address="<?php echo escape_html($ipmember['current_address'] ?? 'N/A'); ?>"
                                data-contact-information="<?php echo escape_html($ipmember['contact_information'] ?? 'N/A'); ?>"
                                data-member-id="<?php echo escape_html($ipmember['member_id'] ?? 'N/A'); ?>"
                                data-tribe-clan="<?php echo escape_html($tribeLabel); ?>"
                                data-barangay="<?php echo escape_html($ipmember['barangay'] ?? 'N/A'); ?>">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-xs border <?php echo escape_html($initialClass); ?>">
                                            <?php echo escape_html(get_initials($fullName)); ?>
                                        </div>
                                        <div>
                                            <p class="font-bold text-[#262626]"><?php echo escape_html($fullName !== '' ? $fullName : 'N/A'); ?></p>
                                            <p class="text-[11px] text-gray-400"><?php echo escape_html($ipmember['member_id'] ?? ''); ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-[#262626] font-semibold text-xs">
                                    <?php echo escape_html($tribeLabel); ?>
                                </td>
                                <td class="px-6 py-4 text-[#262626] font-semibold text-xs">
                                    <?php echo escape_html($ipmember['barangay'] ?? 'N/A'); ?>
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
                                        <a href="family_tree.php?member_id=<?php echo rawurlencode($treeMemberKey); ?>" class="row-action inline-flex items-center gap-2 bg-blue-50 text-blue-600 px-3 py-2 rounded-lg text-xs font-bold hover:bg-blue-100 transition">
                                            <i data-lucide="git-branch" class="w-4 h-4"></i>
                                            <span class="text-xs font-semibold">View Tree</span>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">No tree key</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-right space-x-2">
                                    <button title="Edit Connections" class="row-action p-2 hover:bg-gray-100 text-gray-400 rounded-lg transition">
                                        <i data-lucide="edit-3" class="w-4 h-4"></i>
                                    </button>
                                    <button class="row-action p-2 hover:bg-gray-100 rounded-lg transition"><i data-lucide="more-horizontal" class="w-4 h-4 text-gray-400"></i></button>
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
                        <a href="?query=<?php echo urlencode($searchQuery); ?>&page=<?php echo $currentPage - 1; ?>" class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-lg hover:bg-white transition">Previous</a>
                    <?php else: ?>
                        <span class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-lg text-gray-300 cursor-not-allowed">Previous</span>
                    <?php endif; ?>

                    <span class="px-3 py-2 text-xs font-bold text-gray-500">Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>

                    <?php if ($currentPage < $totalPages): ?>
                        <a href="?query=<?php echo urlencode($searchQuery); ?>&page=<?php echo $currentPage + 1; ?>" class="px-4 py-2 text-xs font-bold bg-[#262626] text-white rounded-lg hover:bg-[#404040] transition">Next</a>
                    <?php else: ?>
                        <span class="px-4 py-2 text-xs font-bold bg-[#262626]/30 text-white rounded-lg cursor-not-allowed">Next</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
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
            const floatingFirstName = document.getElementById('floatingFirstName');
            const floatingMiddleName = document.getElementById('floatingMiddleName');
            const floatingLastName = document.getElementById('floatingLastName');
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

                floatingFullName.textContent = row.dataset.fullName || 'N/A';
                floatingFirstName.textContent = row.dataset.firstName || 'N/A';
                floatingMiddleName.textContent = row.dataset.middleName || 'N/A';
                floatingLastName.textContent = row.dataset.lastName || 'N/A';
                floatingBirthdate.textContent = row.dataset.birthdate || 'N/A';
                floatingPlaceOfBirth.textContent = row.dataset.placeOfBirth || 'N/A';
                floatingCurrentAddress.textContent = row.dataset.currentAddress || 'N/A';
                floatingContactInformation.textContent = row.dataset.contactInformation || 'N/A';
                floatingMemberId.textContent = row.dataset.memberId || 'N/A';
                floatingTribeClan.textContent = row.dataset.tribeClan || 'N/A';
                floatingBarangay.textContent = row.dataset.barangay || 'N/A';

                if (floatingParents) floatingParents.textContent = 'See Family Tree';
                if (floatingSiblings) floatingSiblings.textContent = 'See Family Tree';
                if (floatingSpouse) floatingSpouse.textContent = 'See Family Tree';
                if (floatingChildren) floatingChildren.textContent = 'See Family Tree';

                if (floatingFamilyTreeLink) {
                    const selectedMemberId = row.dataset.memberId || '';
                    if (selectedMemberId && selectedMemberId !== 'N/A') {
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
    </script>
</body>
</html>