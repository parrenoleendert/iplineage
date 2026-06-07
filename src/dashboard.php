<?php
require_once __DIR__ . '/auth/guards.php';
require_once __DIR__ . '/auth/auth_helpers.php';
require_any_role(['admin', 'tribe_leader']);

// Extra protection: if an ip_member is not yet registered, redirect to personal.php.
// This prevents using browser back navigation to access protected pages.
if (isset($_SESSION['role']) && normalize_role((string)($_SESSION['role'] ?? '')) === 'ip_member') {
    require_ip_registration_complete();
}

// Block unregistered ip_members from reaching dashboard routes via back button/navigation.
// (dashboard.php itself is not accessible to ip_member, but keep consistent guard behavior)
if (isset($_SESSION['role']) && normalize_role((string)($_SESSION['role'] ?? '')) === 'ip_member') {
    require_ip_registration_complete();
}

require_once __DIR__ . '/dbconfig.php';
$conn = $GLOBALS['conn'] ?? ($conn ?? null);
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection not established. Check src/dbconfig.php and MySQL service.');
}

$currentRole = normalize_role((string) ($_SESSION['role'] ?? ''));
$isAdmin = $currentRole === 'admin';
$statsGridClass = $isAdmin ? 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10' : 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mb-10';
$dashboardOuterClass = 'ml-64 p-8';
$dashboardInnerClass = '';

$displayName = trim((string) ($_SESSION['name'] ?? 'User'));

// Safety Check: If session name is numeric (e.g. "2"), fetch real name from DB
if (is_numeric($displayName) && isset($conn)) {
    $userPk = (int)($_SESSION['user_id'] ?? 0);
    $nameRes = $conn->query("SELECT COALESCE(NULLIF(full_name, ''), username, 'Admin User') as real_name FROM users WHERE userid = $userPk OR user_id = $userPk LIMIT 1");
    if ($nameRes && $row = $nameRes->fetch_assoc()) {
        $displayName = $row['real_name'];
        $_SESSION['name'] = $displayName; // Update session for other pages
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

// Detect status column in applications to handle schema variations
$appColumns = [];
$resApp = $conn->query("SHOW COLUMNS FROM applications");
if ($resApp instanceof mysqli_result) { while($c = $resApp->fetch_assoc()) $appColumns[] = $c['Field']; }
$appStatusColumn = (in_array('approval_status', $appColumns, true)) ? 'approval_status' : 'status';

$ipColumns = [];
$resIp = $conn->query("SHOW COLUMNS FROM ipmembers");
if ($resIp instanceof mysqli_result) { while($c = $resIp->fetch_assoc()) $ipColumns[] = $c['Field']; }
$ipNameCol = (in_array('full_name', $ipColumns, true)) ? 'full_name' : 'member_name';

// Calculate Total Population directly from approved applications to ensure accuracy regardless of tribe grouping
$totalPopulation = 0;
$popSql = "SELECT COUNT(*) as total FROM applications WHERE `{$appStatusColumn}` = 'approved'";
$popRes = mysqli_query($conn, $popSql);
if ($popRes) { $totalPopulation = (int)(mysqli_fetch_assoc($popRes)['total'] ?? 0); }

$sql = "SELECT 
    t.tribe_name,
    t.language,
    COUNT(a.ip_member_id) AS population,
    t.location
FROM tribes t
LEFT JOIN ipmembers i ON t.tribe_id = i.tribe_clan
LEFT JOIN applications a ON i.ip_member_id = a.ip_member_id AND a.{$appStatusColumn} = 'approved'
GROUP BY t.tribe_id";

$population_result = mysqli_query($conn, $sql);
$pendingElderCount = 0;
$pendingAdminCount = 0;

$pendingElderQuery = "SELECT COUNT(*) as total FROM applications WHERE {$appStatusColumn} = 'pending_elder'";
$resElder = mysqli_query($conn, $pendingElderQuery);
if ($resElder) { $pendingElderCount = (int)(mysqli_fetch_assoc($resElder)['total'] ?? 0); }

$pendingAdminQuery = "SELECT COUNT(*) as total FROM applications WHERE {$appStatusColumn} = 'pending_admin'";
$resAdmin = mysqli_query($conn, $pendingAdminQuery);
if ($resAdmin) { $pendingAdminCount = (int)(mysqli_fetch_assoc($resAdmin)['total'] ?? 0); }

$totalPendingProfiles = $pendingElderCount + $pendingAdminCount;

$tribes = [];
$tribe_sql = "SELECT * FROM tribes";
$tribe_result = mysqli_query($conn, $tribe_sql);
if ($tribe_result && mysqli_num_rows($tribe_result) > 0) {
    while ($row = mysqli_fetch_assoc($tribe_result)) {
        $tribes[] = $row;
    }
}

// --- PAGINATION FOR REJECTED HISTORY ---
$limit = 2; // Number of rows per page
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Get total count for calculating total pages
$totalRowsSql = "SELECT COUNT(*) AS total FROM applications WHERE {$appStatusColumn} = 'rejected'";
$totalRowsResult = mysqli_query($conn, $totalRowsSql);
$totalRows = mysqli_fetch_assoc($totalRowsResult)['total'] ?? 0;
$totalPages = ceil($totalRows / $limit);

$approvalHistory = [];
$approvalHistorySql = "SELECT a.application_id, i.full_name AS applicant_name, a.application_date AS activity_date, a.{$appStatusColumn} AS approval_status, a.rejection_remarks AS remarks_text
FROM applications a
JOIN ipmembers i ON a.ip_member_id = i.ip_member_id
WHERE a.{$appStatusColumn} = 'rejected'
ORDER BY a.application_date DESC
LIMIT $limit OFFSET $offset";

$approvalHistoryResult = mysqli_query($conn, $approvalHistorySql);
if ($approvalHistoryResult && mysqli_num_rows($approvalHistoryResult) > 0) {
    while ($row = mysqli_fetch_assoc($approvalHistoryResult)) {
        $approvalHistory[] = $row;
    }
}



$chartLabels = [];
$chartValues = [];

// For the chart, we need to count all applications (pending, approved, rejected)
// that were created in the last 6 months.
$applicationsDateColumn = 'application_date'; // This column is guaranteed to exist in the applications table

for ($i = 5; $i >= 0; $i--) { // Last 6 months including current
    $chartLabels[] = date('M Y', strtotime("-{$i} months"));
    $chartValues[] = 0; // Initialize counts for each month
}

if ($applicationsDateColumn) {
    $monthMap = [];
    for ($i = 5; $i >= 0; $i--) {
        $monthKey = date('Y-m', strtotime("-{$i} months"));
        $monthMap[$monthKey] = 5 - $i; // Correctly map month key to array index 0-5
    }

    $trendSql = "SELECT DATE_FORMAT(`{$applicationsDateColumn}`, '%Y-%m') AS month_key, COUNT(*) AS total
    FROM applications
    WHERE `{$applicationsDateColumn}` >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
    GROUP BY DATE_FORMAT(`{$applicationsDateColumn}`, '%Y-%m')";
    $trendResult = mysqli_query($conn, $trendSql);

    if ($trendResult && mysqli_num_rows($trendResult) > 0) {
        while ($trendRow = mysqli_fetch_assoc($trendResult)) {
            $monthKey = (string) ($trendRow['month_key'] ?? '');
            if (isset($monthMap[$monthKey])) {
                $chartValues[$monthMap[$monthKey]] = (int) ($trendRow['total'] ?? 0);
            }
        }
    }
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
    <title>IP Lineage - Dashboard</title>
    <style>
        body { background-color: #f3f4f1; color: #262626; font-family: 'Plus Jakarta Sans', sans-serif; }
        .bg-sidebar { background-color: #ffffff; border-right: 1px solid #dedede; }
        .bg-card-custom { background-color: #ffffff; border: 1px solid #dedede; }
        .sidebar-item-active { background-color: #262626; color: #ffffff; }
        .text-muted { color: #666666; }
        .border-line { border-bottom: 1px solid #dedede; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #f3f4f1; }
        ::-webkit-scrollbar-thumb { background: #dedede; border-radius: 10px; }
    </style>
</head>

<body class="min-h-screen">

    <?php $activeNav = 'dashboard'; include __DIR__ . '/shared/sidebar.php'; ?>
    <?php include __DIR__ . '/shared/topbar.php'; ?>

    <div class="<?php echo $dashboardOuterClass; ?>">
        <div class="<?php echo $dashboardInnerClass; ?>">
        
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
                <button id="profile-drawer-trigger" class="flex items-center gap-3 bg-white border border-[#dedede] p-1.5 pr-4 rounded-xl shadow-sm hover:border-gray-400 transition cursor-pointer">
                    <div class="w-8 h-8 rounded-lg bg-[#262626] text-[#f3f4f1] flex items-center justify-center font-bold text-xs uppercase"><?php echo htmlspecialchars($initials); ?></div>
                    <div>
                        <p class="text-xs font-bold leading-none text-[#262626]"><?php echo htmlspecialchars($displayName); ?></p>
                        <p class="text-[10px] text-gray-400 uppercase tracking-tighter"><?php echo htmlspecialchars($roleLabel); ?></p>
                    </div>
                </button>
            </div>
        </header>

        <section class="mb-8">
            <h1 class="text-2xl font-bold text-[#262626]">Dashboard</h1>
        </section>

        <div class="<?php echo $statsGridClass; ?>">
            <a href="total_members.php" class="bg-card-custom p-6 rounded-2xl shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-md hover:border-gray-300">
                <div class="flex justify-between items-start mb-4">
                    <div class="p-2 bg-[#262626]/5 rounded-lg text-[#262626]"><i data-lucide="users" class="w-5 h-5"></i></div>
                    <span class="text-[10px] font-bold text-green-600 bg-green-50 px-2 py-1 rounded-md">+12.5%</span>
                </div>
                <p class="text-gray-500 text-xs font-semibold uppercase tracking-wider"><?php echo $isAdmin ? 'Total Members' : 'Tribe Population'; ?></p>
                <h3 class="text-2xl font-bold text-[#262626]"><?php echo $totalPopulation; ?></h3>
            </a>

            <a href="<?php echo $isAdmin ? 'active_tribe.php' : 'pending_lineage.php'; ?>" class="bg-card-custom p-6 rounded-2xl shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-md hover:border-gray-300">
                <div class="flex justify-between items-start mb-4">
                    <div class="p-2 bg-[#262626]/5 rounded-lg text-[#262626]"><i data-lucide="map" class="w-5 h-5"></i></div>
                </div>
                <p class="text-gray-500 text-xs font-semibold uppercase tracking-wider"><?php echo $isAdmin ? 'Active Tribes' : 'Pending Lineage'; ?></p>
                <h3 class="text-2xl font-bold text-[#262626]"><?php echo count($tribes); ?></h3>
            </a>

            <a href="pending_verification.php" class="bg-card-custom p-6 rounded-2xl shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-md hover:border-gray-300">
                <div class="flex justify-between items-start mb-4">
                    <div class="p-2 bg-[#262626]/5 rounded-lg text-[#262626]"><i data-lucide="shield-check" class="w-5 h-5"></i></div>
                </div>
                <p class="text-gray-500 text-xs font-semibold uppercase tracking-wider">Pending Verification</p>
                <h3 class="text-2xl font-bold text-[#262626]"><?php echo $pendingElderCount; ?></h3>
            </a>

            <?php if ($isAdmin): ?>
            <a href="pending_approval.php" class="bg-card-custom p-6 rounded-2xl shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-md hover:border-gray-300">
                <div class="flex justify-between items-start mb-4">
                    <div class="p-2 bg-[#262626]/5 rounded-lg text-[#262626]"><i data-lucide="clock" class="w-5 h-5"></i></div>
                </div>
                <p class="text-gray-500 text-xs font-semibold uppercase tracking-wider">Pending Admin Approval</p>
                <h3 class="text-2xl font-bold text-[#262626]"><?php echo $pendingAdminCount; ?></h3>
            </a>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <div class="lg:col-span-8 bg-card-custom p-6 rounded-2xl shadow-sm">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="font-bold text-[#262626]">Registration Trends</h3>
                    <select class="text-xs bg-transparent border border-[#dedede] rounded-md px-2 py-1 outline-none">
                        <option>Last 6 Months</option>
                    </select>
                </div>
                <div class="h-64">
                    <canvas id="ipChart"></canvas>
                </div>
            </div>

            <div class="lg:col-span-4 bg-card-custom p-6 rounded-2xl shadow-sm">
                <h3 class="font-bold text-[#262626] mb-6">Recently Approved</h3>
                <div class="space-y-5">
                    <?php
                        // Filter directly from database or reuse global values safely
                        $recentApprovedSql = "SELECT i.{$ipNameCol} AS applicant_name, a.application_date AS activity_date
                                              FROM applications a
                                              JOIN ipmembers i ON a.ip_member_id = i.ip_member_id WHERE a.`{$appStatusColumn}` = 'approved' ORDER BY a.application_date DESC LIMIT 4";
                        $recentApprovedResult = mysqli_query($conn, $recentApprovedSql);
                        $hasRecentApproved = $recentApprovedResult && mysqli_num_rows($recentApprovedResult) > 0;
                    ?>

                    <?php if ($hasRecentApproved): ?>
                        <?php while ($approvedRow = mysqli_fetch_assoc($recentApprovedResult)): ?>
                            <?php
                                $approvedName = (string) ($approvedRow['applicant_name'] ?? 'N/A');
                                $approvedDateLabel = 'N/A';
                                $approvedTimestamp = strtotime((string) ($approvedRow['activity_date'] ?? ''));
                                if ($approvedTimestamp !== false) {
                                    $approvedDateLabel = date('M d, Y', $approvedTimestamp);
                                }
                                $approvedInitials = strtoupper(substr(trim($approvedName) !== '' ? $approvedName : 'NA', 0, 2));
                            ?>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-full bg-gray-100 text-[#262626] flex items-center justify-center font-bold text-xs border border-[#dedede]">
                                        <?php echo htmlspecialchars($approvedInitials, ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-[#262626]"><?php echo htmlspecialchars($approvedName, ENT_QUOTES, 'UTF-8'); ?></p>
                                        <p class="text-[10px] text-gray-400 uppercase">Approved <?php echo htmlspecialchars($approvedDateLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                </div>
                                <span class="text-[10px] font-bold uppercase px-2 py-1 rounded-md bg-green-100 text-green-700 border border-green-200">Approved</span>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p class="text-sm text-gray-400">No recently approved records found.</p>
                    <?php endif; ?>
                </div>
                <form method="post" action="total_members.php">
                    <button type="submit" class="w-full mt-10 py-3 text-xs font-bold text-[#262626] hover:bg-gray-50 border border-[#dedede] rounded-xl transition">View All Members</button>
                </form>
            </div>
        </div>

        <div class="mt-8 bg-card-custom rounded-2xl overflow-hidden shadow-sm">
            <div class="p-6 border-line flex justify-between items-center bg-gray-50/50">
                <div>
                    <h3 class="font-bold uppercase tracking-widest text-[10px] text-gray-500">Rejected History</h3>
                </div>
            </div>
            <div class="relative">

            <!-- REFINED REJECTION REMARKS POPUP -->
            <aside id="floatingMemberCard" class="hidden fixed inset-0 z-[110] items-center justify-center p-4">
                <div id="floatingMemberBackdrop" class="absolute inset-0 bg-black/40 backdrop-blur-xs" onclick="hideFloatingMemberCard()"></div>
                <div class="relative z-10 w-full max-w-lg bg-white rounded-2xl shadow-[0_10px_30px_rgba(0,0,0,0.15)] border border-[#ececea] flex flex-col overflow-hidden">
                    <div class="px-6 py-4 border-b border-[#ececea] flex items-center justify-between bg-gray-50/50">
                        <div class="flex items-center gap-2">
                            <span class="inline-block w-2 h-2 rounded-full bg-red-500"></span>
                            <h3 class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Application Rejection Details</h3>
                        </div>
                        <button onclick="hideFloatingMemberCard()" class="rounded-lg p-1 text-gray-400 hover:bg-gray-100 transition-all">
                            <i data-lucide="x" class="w-4 h-4"></i>
                        </button>
                    </div>
                    
                    <div class="p-8">
                        <div class="flex items-center gap-4 mb-8 pb-6 border-b border-gray-100">
                            <div id="floatingInitials" class="h-14 w-14 rounded-xl bg-[#262626] text-white flex items-center justify-center font-bold text-lg shadow-sm uppercase">--</div>
                            <div>
                                <h2 id="floatingFullName" class="text-base font-bold text-[#262626] tracking-tight">No applicant selected</h2>
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Rejected Status</p>
                            </div>
                        </div>
                        
                        <div class="bg-red-50/50 border border-red-100 rounded-xl p-5">
                            <p class="text-[10px] font-bold text-red-400 uppercase tracking-wider mb-2">Official Remarks</p>
                            <p id="floatingRemarks" class="text-xs font-semibold text-red-700 leading-relaxed italic">No remarks available.</p>
                        </div>
                    </div>
                    
                    <div class="px-6 py-4 bg-gray-50 border-t border-[#ececea] flex justify-end">
                        <button onclick="hideFloatingMemberCard()" class="px-5 py-2 text-xs font-bold bg-[#262626] text-white rounded-xl hover:bg-black transition-all shadow-sm">Dismiss</button>
                    </div>
                </div>
            </aside>

            <table class="w-full text-left">
                <thead>
                    <tr class="text-[10px] uppercase text-gray-400 border-line bg-gray-50/30">
                        <th class="px-6 py-4">Full Name</th>
                        <th class="px-10 py-4">Date</th>
                        <th class="px-10 py-4">Status</th>
                        <th class="px-6 py-4 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="text-sm divide-y divide-[#dedede]">
                    <?php if (!empty($approvalHistory)): ?>
                        <?php foreach ($approvalHistory as $historyRow): ?>
                            <?php
                                $applicantName = (string) ($historyRow['applicant_name'] ?? 'N/A');
                                $status = strtolower(trim((string) ($historyRow['approval_status'] ?? '')));
                                $verificationDateLabel = 'N/A';

                                $timestamp = strtotime((string) ($historyRow['activity_date'] ?? $historyRow['application_date'] ?? ''));
                                if ($timestamp !== false) {
                                    $verificationDateLabel = date('M d, Y', $timestamp);
                                }

                                $statusLabel = $status === 'approved' ? 'Approved' : 'Rejected';
                                $statusClass = $status === 'approved'
                                    ? 'bg-green-100 text-green-700 border border-green-200'
                                    : 'bg-red-100 text-red-700 border border-red-200';
                            ?>
                            <tr class="member-row cursor-pointer hover:bg-gray-100 transition-colors duration-200" data-approval-id="<?php echo htmlspecialchars((string) ($historyRow['application_id'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>" data-full-name="<?php echo htmlspecialchars($applicantName, ENT_QUOTES, 'UTF-8'); ?>">
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-[#262626]"><?php echo htmlspecialchars($applicantName, ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-[#262626]"><?php echo htmlspecialchars($verificationDateLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-3 py-1 text-[10px] font-bold uppercase rounded-md <?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td class="px-6 py-4 text-right relative">
                                    <div class="inline-flex items-center gap-2">
                                        <button type="button" class="view-remarks-btn p-2 hover:bg-gray-100 rounded-xl transition text-gray-400 hover:text-[#262626]" aria-label="More actions"><i data-lucide="more-horizontal" class="w-6 h-6"></i></button>
                                    </div>

                                    <!-- REFINED ACTION MENU -->
                                    <div class="action-menu hidden absolute right-0 top-1 mt-1 w-44 bg-white border border-[#ececea] rounded-xl shadow-[0_4px_20px_rgba(0,0,0,0.1)] p-1.5 z-[100]">
                                        <button type="button" class="w-full text-left px-3 py-2 rounded-lg hover:bg-gray-50 menu-view-remarks text-xs font-bold text-gray-700 flex items-center gap-2 transition-all">
                                            <i data-lucide="eye" class="w-3.5 h-3.5 text-gray-400"></i> View Remarks
                                        </button>
                                        <button type="button" class="w-full text-left px-3 py-2 rounded-lg hover:bg-red-50 menu-undo text-xs font-bold text-red-600 flex items-center gap-2 transition-all mt-1">
                                            <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i> Undo Rejection
                                        </button>
                                    </div>
                                </td>
                                
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="px-6 py-8 text-center text-gray-400">No rejected records found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- REFINED UNDO CONFIRMATION MODAL -->
            <div id="confirmUndoModal" class="hidden fixed inset-0 z-[120] items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/40 backdrop-blur-xs" onclick="closeUndoModal()"></div>
                <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-[0_10px_30px_rgba(0,0,0,0.15)] border border-[#ececea] p-6">
                    <h3 class="text-base font-bold text-[#262626] mb-2 flex items-center gap-2">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-red-500"></span>
                        Undo Rejection
                    </h3>
                    <p class="text-xs text-gray-500 mb-6">Are you sure you want to revert the rejection for <span id="undoModalName" class="font-bold text-[#262626]">-</span>? The application will be moved back to <b>Pending Elder</b> status and the rejection reason will be deleted.</p>
                    
                    <div class="flex justify-end gap-3">
                        <button id="cancelUndoBtn" class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-xl hover:bg-gray-50 text-gray-600 transition-all">Cancel</button>
                        <button id="confirmUndoBtn" class="px-4 py-2 text-xs font-bold bg-red-600 hover:bg-red-700 text-white rounded-xl transition-all shadow-sm">Revert Rejection</button>
                    </div>
                </div>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="p-4 bg-gray-50/50 border-t border-[#dedede] flex justify-between items-center text-xs text-gray-500 font-semibold">
                <div>
                    Showing page <?php echo $page; ?> of <?php echo $totalPages; ?>
                </div>
                <div class="flex gap-2">
                    <a href="?page=<?php echo max(1, $page - 1); ?>" 
                       class="px-4 py-2 border border-[#dedede] rounded-lg bg-white hover:bg-gray-50 text-[#262626] transition flex items-center gap-1 <?php if($page <= 1) echo 'opacity-50 pointer-events-none'; ?>">
                        <i data-lucide="chevron-left" class="w-3.5 h-3.5"></i> Previous
                    </a>
                    <a href="?page=<?php echo min($totalPages, $page + 1); ?>" 
                       class="px-4 py-2 border border-[#dedede] rounded-lg bg-white hover:bg-gray-50 text-[#262626] transition flex items-center gap-1 <?php if($page >= $totalPages) echo 'opacity-50 pointer-events-none'; ?>">
                        Next <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        lucide.createIcons();

        const floatingMemberCard = document.getElementById('floatingMemberCard');
        const floatingBackdrop = document.getElementById('floatingMemberBackdrop');
        const closeFloatingMemberCard = document.getElementById('closeFloatingMemberCard');
        const floatingFullName = document.getElementById('floatingFullName');
        const floatingRemarks = document.getElementById('floatingRemarks');

        const hideFloatingMemberCard = () => {
            if (floatingMemberCard) {
                floatingMemberCard.classList.add('hidden');
                floatingMemberCard.classList.remove('flex');
            }
        };
        
        window.closeUndoModal = () => {
            if (confirmUndoModal) {
                confirmUndoModal.classList.add('hidden');
                confirmUndoModal.classList.remove('flex');
            }
        };

        const showFloatingMemberCard = (fullName, remarks) => {
            if (floatingFullName) {
                floatingFullName.textContent = fullName || 'No member selected';
            }
            if (floatingRemarks) {
                floatingRemarks.textContent = remarks || 'No remarks available for this record.';
            }
            if (floatingMemberCard) {
                floatingMemberCard.classList.remove('hidden');
                floatingMemberCard.classList.add('flex');
            }
        };

        document.querySelectorAll('.member-row').forEach((row) => {
            row.addEventListener('click', (e) => {
                if (e.target.closest('.view-remarks-btn') || e.target.closest('.action-menu')) return;
                const approvalId = row.dataset.approvalId || '';
                const applicantName = row.dataset.fullName || '';

                if (!approvalId || approvalId === '0') {
                    showFloatingMemberCard(applicantName, 'No remarks available for this record.');
                    return;
                }

                showFloatingMemberCard(applicantName, 'Loading remarks...');

                fetch('backend/rejection_remarks.php?approval_id=' + encodeURIComponent(approvalId), {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                    .then((response) => response.json())
                    .then((payload) => {
                        if (payload && payload.success) {
                            showFloatingMemberCard(payload.full_name || applicantName, payload.remarks || 'No remarks available for this record.');
                            return;
                        }

                        showFloatingMemberCard(applicantName, 'No remarks available for this record.');
                    })
                    .catch(() => {
                        showFloatingMemberCard(applicantName, 'No remarks available for this record.');
                    });
            });
        });

        // Undo button handling
        let undoTargetApprovalId = null;
        const undoModalName = document.getElementById('undoModalName');
        const confirmUndoModal = document.getElementById('confirmUndoModal');
        const cancelUndoBtn = document.getElementById('cancelUndoBtn');
        const confirmUndoBtn = document.getElementById('confirmUndoBtn');

        if (cancelUndoBtn) {
            cancelUndoBtn.addEventListener('click', () => {
                undoTargetApprovalId = null;
                if (confirmUndoModal) {
                    confirmUndoModal.classList.add('hidden');
                    confirmUndoModal.classList.remove('flex');
                }
            });
        }

        if (confirmUndoBtn) {
            confirmUndoBtn.addEventListener('click', () => {
                if (!undoTargetApprovalId) return;
                doUndo(undoTargetApprovalId);
            });
        }

        function doUndo(approvalId) {
            fetch('backend/undo_rejection.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'approval_id=' + encodeURIComponent(approvalId)
            })
                .then((r) => r.json())
                .then((payload) => {
                    if (payload && payload.success) {
                        // remove row from table
                        const row = document.querySelector('tr.member-row[data-approval-id="' + approvalId + '"]');
                        if (row && row.parentNode) row.parentNode.removeChild(row);
                        // hide modal
                        if (confirmUndoModal) {
                            confirmUndoModal.classList.add('hidden');
                            confirmUndoModal.classList.remove('flex');
                        }
                        undoTargetApprovalId = null;
                        return;
                    }
                    alert('Unable to undo rejection.');
                })
                .catch(() => {
                    alert('Unable to undo rejection.');
                });
        }

        // Action menu handling for three-dot button
        function closeAllActionMenus() {
            document.querySelectorAll('.action-menu').forEach(m => {
                m.classList.add('hidden');
            });
        }

        document.querySelectorAll('.view-remarks-btn').forEach((btn) => {
            btn.addEventListener('click', (ev) => {
                ev.stopPropagation();
                closeAllActionMenus();
                const cell = btn.closest('td');
                if (!cell) return;
                const menu = cell.querySelector('.action-menu');
                if (!menu) return;
                menu.classList.toggle('hidden');
                // store approval id on menu for later reference
                const row = btn.closest('tr.member-row');
                const approvalId = row ? (row.dataset.approvalId || '') : '';
                menu.dataset.approvalId = approvalId;
            });
        });

        // menu actions
        document.querySelectorAll('.menu-view-remarks').forEach((mBtn) => {
            mBtn.addEventListener('click', (ev) => {
                ev.stopPropagation();
                const menu = mBtn.closest('.action-menu');
                if (!menu) return;
                const approvalId = menu.dataset.approvalId || '';
                const row = mBtn.closest('tr.member-row');
                const applicantName = row ? (row.dataset.fullName || '') : '';
                closeAllActionMenus();
                if (!approvalId || approvalId === '0') {
                    showFloatingMemberCard(applicantName, 'No remarks available for this record.');
                    return;
                }
                showFloatingMemberCard(applicantName, 'Loading remarks...');
                fetch('backend/rejection_remarks.php?approval_id=' + encodeURIComponent(approvalId), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(r => r.json())
                    .then(payload => {
                        if (payload && payload.success) {
                            showFloatingMemberCard(payload.full_name || applicantName, payload.remarks || 'No remarks available for this record.');
                            return;
                        }
                        showFloatingMemberCard(applicantName, 'No remarks available for this record.');
                    })
                    .catch(() => {
                        showFloatingMemberCard(applicantName, 'No remarks available for this record.');
                    });
            });
        });

        document.querySelectorAll('.menu-undo').forEach((mBtn) => {
            mBtn.addEventListener('click', (ev) => {
                ev.stopPropagation();
                const menu = mBtn.closest('.action-menu');
                if (!menu) return;
                const approvalId = menu.dataset.approvalId || null;
                closeAllActionMenus();
                if (!approvalId) return;
                undoTargetApprovalId = approvalId;

                const row = mBtn.closest('tr.member-row');
                const applicantName = row ? (row.dataset.fullName || 'Applicant') : 'Applicant';
                if (undoModalName) undoModalName.textContent = applicantName;

                if (confirmUndoModal) {
                    confirmUndoModal.classList.remove('hidden');
                    confirmUndoModal.classList.add('flex');
                } else {
                    doUndo(approvalId);
                }
            });
        });

        // close menus when clicking outside
        document.addEventListener('click', (ev) => {
            closeAllActionMenus();
        });

        if (closeFloatingMemberCard) {
            closeFloatingMemberCard.addEventListener('click', hideFloatingMemberCard);
        }

        if (floatingBackdrop) {
            floatingBackdrop.addEventListener('click', hideFloatingMemberCard);
        }

        const ctx = document.getElementById('ipChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chartLabels, JSON_UNESCAPED_SLASHES); ?>,
                datasets: [{
                    label: 'Registrations',
                    data: <?php echo json_encode($chartValues, JSON_UNESCAPED_SLASHES); ?>,
                    borderColor: '#262626',
                    borderWidth: 2,
                    pointBackgroundColor: '#262626',
                    backgroundColor: 'rgba(38, 38, 38, 0.05)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { 
                        grid: { color: '#dedede', drawTicks: false }, 
                        border: { display: false },
                        ticks: { color: '#999', font: { size: 10 } } 
                    },
                    x: { 
                        grid: { display: false }, 
                        ticks: { color: '#999', font: { size: 10 } } 
                    }
                }
            }
        });

        // --- LIGHTWEIGHT REAL-TIME POLLING ---
        function updateDashboardStats() {
            fetch('backend/get_live_stats.php')
                .then(response => response.json())
                .then(data => {
                    // Update the UI elements if they exist
                    const elderEl = document.querySelector('[href="pending_verification.php"] h3');
                    const adminEl = document.querySelector('[href="pending_approval.php"] h3');
                    const popEl = document.querySelector('[href="total_members.php"] h3');
                    
                    if (elderEl) elderEl.textContent = data.pending_elder;
                    if (adminEl) adminEl.textContent = data.pending_admin;
                    if (popEl) popEl.textContent = data.total_population;
                    
                    // Optional: Notification if a new member is approved
                    // compare data.latest_member with a local variable to trigger a toast
                })
                .catch(err => console.error('Polling error:', err));
        }

        // Poll every 30 seconds
        setInterval(updateDashboardStats, 30000);
    </script>
</body>
</html>