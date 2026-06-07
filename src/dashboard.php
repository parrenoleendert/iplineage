<?php
require_once __DIR__ . '/auth/guards.php';
require_once __DIR__ . '/auth/auth_helpers.php';
require_any_role(['admin', 'tribe_leader']);

require_once __DIR__ . '/dbconfig.php';
$conn = $GLOBALS['conn'] ?? ($conn ?? null);
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection not established. Check src/dbconfig.php and MySQL service.');
}

function first_existing_column(array $columns, array $candidates): ?string {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

$currentRole = normalize_role((string) ($_SESSION['role'] ?? ''));
$isAdmin = $currentRole === 'admin';
$statsGridClass = $isAdmin ? 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10' : 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mb-10';
$dashboardOuterClass = 'ml-64 p-8';
$dashboardInnerClass = '';

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

$sql = "SELECT 
    t.tribe_name,
    t.language,
    COUNT(i.member_id) AS population,
    t.location
FROM tribes t
LEFT JOIN ipmembers i ON t.tribe_id = i.tribe_clan
GROUP BY t.tribe_id";

$population_result = mysqli_query($conn, $sql);

$pendingveri = [];
$pendingveri_sql = "SELECT * FROM applications WHERE status = 'pending'";
$pendingveri_result = mysqli_query($conn, $pendingveri_sql);
if ($pendingveri_result && mysqli_num_rows($pendingveri_result) > 0) {
    while ($row = mysqli_fetch_assoc($pendingveri_result)) {
        $pendingveri[] = $row;
    }
}

$pendingapprovalscount = [];
$pendingapprovalscount_sql = "SELECT * FROM pending_approvals WHERE approval_status = 'pending_approval'";
$pendingapprovalscount_result = mysqli_query($conn, $pendingapprovalscount_sql);
if ($pendingapprovalscount_result && mysqli_num_rows($pendingapprovalscount_result) > 0) {
    while ($row = mysqli_fetch_assoc($pendingapprovalscount_result)) {
        $pendingapprovalscount[] = $row;
    }
}

$tribes = [];
$tribe_sql = "SELECT * FROM tribes";
$tribe_result = mysqli_query($conn, $tribe_sql);
if ($tribe_result && mysqli_num_rows($tribe_result) > 0) {
    while ($row = mysqli_fetch_assoc($tribe_result)) {
        $tribes[] = $row;
    }
}

$pendingApprovalColumns = [];
$pendingApprovalColumnsResult = mysqli_query($conn, "SHOW COLUMNS FROM pending_approvals");
if ($pendingApprovalColumnsResult) {
    while ($columnRow = mysqli_fetch_assoc($pendingApprovalColumnsResult)) {
        $pendingApprovalColumns[] = strtolower((string) ($columnRow['Field'] ?? ''));
    }
}

$approvalIdColumn = first_existing_column($pendingApprovalColumns, ['id', 'pending_approval_id', 'approval_id']);
$remarksColumn = first_existing_column($pendingApprovalColumns, ['rejected_remarks', 'rejection_remarks', 'remarks', 'rejection_reason', 'reason', 'comment', 'comments']);

// --- PAGINATION FOR REJECTED HISTORY ---
$limit = 2; // Number of rows per page
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Get total count for calculating total pages
$totalRowsSql = "SELECT COUNT(*) AS total FROM pending_approvals WHERE approval_status = 'rejected'";
$totalRowsResult = mysqli_query($conn, $totalRowsSql);
$totalRows = mysqli_fetch_assoc($totalRowsResult)['total'] ?? 0;
$totalPages = ceil($totalRows / $limit);

$approvalHistory = [];
$approvalHistorySql = "SELECT " . ($approvalIdColumn !== null ? "{$approvalIdColumn} AS approval_id, " : "0 AS approval_id, ") . "applicant_name, COALESCE(approve_reject_date, verification_date) AS activity_date, approval_status" . ($remarksColumn !== null ? ", {$remarksColumn} AS remarks_text" : ", '' AS remarks_text") . "
FROM pending_approvals
WHERE approval_status = 'rejected'
ORDER BY activity_date DESC
LIMIT $limit OFFSET $offset";

$approvalHistoryResult = mysqli_query($conn, $approvalHistorySql);
if ($approvalHistoryResult && mysqli_num_rows($approvalHistoryResult) > 0) {
    while ($row = mysqli_fetch_assoc($approvalHistoryResult)) {
        $approvalHistory[] = $row;
    }
}



$chartLabels = [];
$chartValues = [];

for ($i = 5; $i >= 0; $i--) {
    $chartLabels[] = date('M', strtotime("-{$i} months"));
    $chartValues[] = 0;
}

$applicationsDateColumn = null;
$applicationsColumnsResult = mysqli_query($conn, "SHOW COLUMNS FROM applications");
if ($applicationsColumnsResult) {
    $availableColumns = [];
    while ($columnRow = mysqli_fetch_assoc($applicationsColumnsResult)) {
        $availableColumns[] = strtolower((string) ($columnRow['Field'] ?? ''));
    }

    $dateColumnCandidates = ['submitted_at', 'application_date', 'created_at', 'date_submitted', 'verification_date'];
    foreach ($dateColumnCandidates as $candidate) {
        if (in_array($candidate, $availableColumns, true)) {
            $applicationsDateColumn = $candidate;
            break;
        }
    }
}

if ($applicationsDateColumn !== null) {
    $monthMap = [];
    for ($i = 5; $i >= 0; $i--) {
        $monthKey = date('Y-m', strtotime("-{$i} months"));
        $monthMap[$monthKey] = count($monthMap);
    }

    $trendSql = "SELECT DATE_FORMAT(`{$applicationsDateColumn}`, '%Y-%m') AS month_key, COUNT(*) AS total
    FROM applications
    WHERE `{$applicationsDateColumn}` >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
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
                <h3 class="text-2xl font-bold text-[#262626]"><?php echo htmlspecialchars($population_result && mysqli_num_rows($population_result) > 0 ? mysqli_fetch_assoc($population_result)['population'] : 0); ?></h3>
            </a>

            <a href="<?php echo $isAdmin ? 'active_tribe.php' : 'tribe_leader/active_tribe.html'; ?>" class="bg-card-custom p-6 rounded-2xl shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-md hover:border-gray-300">
                <div class="flex justify-between items-start mb-4">
                    <div class="p-2 bg-[#262626]/5 rounded-lg text-[#262626]"><i data-lucide="map" class="w-5 h-5"></i></div>
                </div>
                <p class="text-gray-500 text-xs font-semibold uppercase tracking-wider"><?php echo $isAdmin ? 'Active Tribes' : 'Active Members'; ?></p>
                <h3 class="text-2xl font-bold text-[#262626]"><?php echo count($tribes); ?></h3>
            </a>

            <a href="pending_verification.php" class="bg-card-custom p-6 rounded-2xl shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-md hover:border-gray-300">
                <div class="flex justify-between items-start mb-4">
                    <div class="p-2 bg-[#262626]/5 rounded-lg text-[#262626]"><i data-lucide="shield-check" class="w-5 h-5"></i></div>
                </div>
                <p class="text-gray-500 text-xs font-semibold uppercase tracking-wider">Pending Verification</p>
                <h3 class="text-2xl font-bold text-[#262626]"><?php echo count($pendingveri); ?></h3>
            </a>

            <?php if ($isAdmin): ?>
            <a href="pending_approval.php" class="bg-card-custom p-6 rounded-2xl shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-md hover:border-gray-300">
                <div class="flex justify-between items-start mb-4">
                    <div class="p-2 bg-[#262626]/5 rounded-lg text-[#262626]"><i data-lucide="clock" class="w-5 h-5"></i></div>
                </div>
                <p class="text-gray-500 text-xs font-semibold uppercase tracking-wider">Pending Approval</p>
                <h3 class="text-2xl font-bold text-[#262626]"><?php echo count($pendingapprovalscount); ?></h3>
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
                        $recentApprovedSql = "SELECT applicant_name, COALESCE(approve_reject_date, verification_date) AS activity_date 
                                              FROM pending_approvals WHERE approval_status = 'approved' ORDER BY activity_date DESC LIMIT 4";
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

            <aside id="floatingMemberCard" class="hidden fixed inset-0 z-[60] items-center justify-center p-4 sm:p-6">
                <div id="floatingMemberBackdrop" class="absolute inset-0 bg-black/40"></div>
                <div class="relative z-10 w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white p-5 shadow-[0_0_20px_rgba(0,0,0,0.3)]">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-sm font-bold uppercase tracking-wider text-[#262626]">Rejection Remarks</h3>
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
                <div class="text-md font-bold text-[#262626] mb-2">
                    Remarks:
                </div>
                <div id="floatingRemarks" class="text-sm text-gray-700">
                    No remarks available for this record.
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

                                $timestamp = strtotime((string) ($historyRow['activity_date'] ?? ''));
                                if ($timestamp !== false) {
                                    $verificationDateLabel = date('M d, Y', $timestamp);
                                }

                                $statusLabel = $status === 'approved' ? 'Approved' : 'Rejected';
                                $statusClass = $status === 'approved'
                                    ? 'bg-green-100 text-green-700 border border-green-200'
                                    : 'bg-red-100 text-red-700 border border-red-200';
                            ?>
                            <tr class="member-row cursor-pointer hover:bg-gray-100 transition-colors duration-200" data-approval-id="<?php echo htmlspecialchars((string) ($historyRow['approval_id'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>" data-full-name="<?php echo htmlspecialchars($applicantName, ENT_QUOTES, 'UTF-8'); ?>">
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
                                        <button type="button" class="p-2 hover:bg-gray-100 rounded-xl transition text-gray-400 hover:text-[#262626]" aria-label="More actions"><i data-lucide="more-horizontal" class="w-6 h-6"></i></button>
                                    </div>

                                    <div class="action-menu hidden absolute right-15 top-0 mt-2 w-40 bg-white border border-[#dedede] rounded-lg shadow-sm p-2 z-50">
                                        <button type="button" class="font-bold w-full text-left px-2 py-2 rounded hover:bg-gray-50 menu-undo text-red-600 ">Undo</button>
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

            <!-- Undo confirmation modal -->
            <div id="confirmUndoModal" class="hidden fixed inset-0 z-[70] items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/40"></div>
                <div class="relative z-10 w-full max-w-md rounded-lg bg-white p-6 shadow-lg">
                    <h4 class="text-lg font-bold mb-2">Confirm Undo</h4>
                    <p class="text-sm text-gray-600 mb-4">This will move the record back to pending and clear any rejection remarks. Continue?</p>
                    <div class="flex justify-end gap-2">
                        <button id="cancelUndoBtn" class="px-4 py-2 rounded-lg border border-[#dedede] bg-white">Cancel</button>
                        <button id="confirmUndoBtn" class="px-4 py-2 rounded-lg bg-red-600 text-white">Confirm Undo</button>
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
            row.addEventListener('click', () => {
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
        const confirmUndoModal = document.getElementById('confirmUndoModal');
        const cancelUndoBtn = document.getElementById('cancelUndoBtn');
        const confirmUndoBtn = document.getElementById('confirmUndoBtn');

        document.querySelectorAll('.undo-btn').forEach((btn) => {
            btn.addEventListener('click', (ev) => {
                ev.stopPropagation();
                undoTargetApprovalId = btn.dataset.approvalId || null;
                if (confirmUndoModal) {
                    confirmUndoModal.classList.remove('hidden');
                    confirmUndoModal.classList.add('flex');
                } else {
                    if (!undoTargetApprovalId) return;
                    doUndo(undoTargetApprovalId);
                }
            });
        });

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
    </script>
</body>
</html>