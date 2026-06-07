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
require_any_role(['admin']);

$pending_approval = [];
$errorMessage = '';
$sort = isset($_GET['sort']) ? strtolower(trim((string) $_GET['sort'])) : 'newest';
$perPage = 5;
$currentPage = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($currentPage < 1) {
    $currentPage = 1;
}
$totalRecords = 0;
$totalPages = 1;
$sourceTable = 'pending_approvals';

function first_existing_column(array $columns, array $candidates): ?string {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

$tableExistsResult = $conn->query("SHOW TABLES LIKE 'pending_approvals'");
if (!($tableExistsResult instanceof mysqli_result) || $tableExistsResult->num_rows === 0) {
    $sourceTable = 'applications';
}

// Check columns for joined tables to resolve names correctly
$ipColumns = [];
$resIp = $conn->query("SHOW COLUMNS FROM ipmembers");
if ($resIp) { while($c = $resIp->fetch_assoc()) $ipColumns[] = $c['Field']; }

$userColumns = [];
$resUser = $conn->query("SHOW COLUMNS FROM users");
if ($resUser) { while($c = $resUser->fetch_assoc()) $userColumns[] = $c['Field']; }

$columns = [];
$columnResult = $conn->query("SHOW COLUMNS FROM {$sourceTable}");
if ($columnResult instanceof mysqli_result) {
    while ($columnRow = $columnResult->fetch_assoc()) {
        $columns[] = $columnRow['Field'];
    }
}

$idColumn = first_existing_column($columns, ['application_id', 'id', 'pending_approval_id', 'approval_id']);
$approveRejectDateColumn = first_existing_column($columns, ['approve_reject_date', 'approved_at', 'updated_at']);
$remarksColumn = first_existing_column($columns, ['rejection_remarks', 'rejected_remarks', 'remarks', 'rejection_reason', 'reason', 'comment', 'comments']);

if ($sourceTable === 'pending_approvals' && $remarksColumn === null) {
    $alterRemarksSql = "ALTER TABLE pending_approvals ADD COLUMN rejection_remarks TEXT NULL AFTER approval_status";
    if ($conn->query($alterRemarksSql)) {
        $remarksColumn = 'rejection_remarks';
        $columns[] = 'rejection_remarks';
    }
}

$verifiedByColumn = first_existing_column($columns, ['verified_by', 'verifier_name']);
$proposedRoleColumn = first_existing_column($columns, ['proposed_role', 'target_role']);
// Your `applications` table does not store tribe directly (it only has ip_member_id + status fields).
// Keep target_tribe as null so we can fall back to joining tribe from ipmember details when needed.
$targetTribeColumn = first_existing_column($columns, ['target_tribe', 'tribe_clan', 'tribe', 'tribe_name', 'application_tribe', 'tribe_id']);
// Your `applications` table stores application_date (not verification_date)
$verificationDateColumn = first_existing_column($columns, ['verification_date', 'created_at', 'application_date']);
$statusColumn = first_existing_column($columns, ['approval_status', 'status']);

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['approval_id'], $_POST['approval_action'])
    && $idColumn !== null
    && $statusColumn !== null
) {
    $approvalId = (int) $_POST['approval_id'];
    $approvalAction = strtolower(trim((string) $_POST['approval_action']));
    $newStatus = $approvalAction === 'reject' ? 'rejected' : 'approved';
    $postedRemarks = trim((string) ($_POST['rejection_remarks'] ?? $_POST['remarks'] ?? ''));

    // If approving in 'applications', update the user's role to IP MEMBER as well
    if ($sourceTable === 'applications' && $newStatus === 'approved') {
        $userPkCol = in_array('userid', $userColumns) ? 'userid' : (in_array('user_id', $userColumns) ? 'user_id' : 'id');
        $updateRoleSql = "UPDATE users SET role = 'IP MEMBER' 
                         WHERE $userPkCol = (SELECT user_id FROM ipmembers i 
                                            JOIN applications a ON i.ip_member_id = a.ip_member_id 
                                            WHERE a.application_id = ?)";
        $updRoleStmt = $conn->prepare($updateRoleSql);
        $updRoleStmt->bind_param('i', $approvalId);
        $updRoleStmt->execute();
    }

    $updateSql = "UPDATE {$sourceTable} SET {$statusColumn} = ?";
    if ($approveRejectDateColumn !== null) {
        $updateSql .= ", {$approveRejectDateColumn} = CURDATE()";
    }
    if ($newStatus === 'rejected' && $remarksColumn !== null) {
        $updateSql .= ", {$remarksColumn} = ?";
    }
    $updateSql .= " WHERE {$idColumn} = ?";
    $updateStmt = $conn->prepare($updateSql);
    if ($updateStmt) {
        if ($newStatus === 'rejected' && $remarksColumn !== null) {
            $updateStmt->bind_param('ssi', $newStatus, $postedRemarks, $approvalId);
            $_SESSION['error_message'] = "Application rejected.";
        } else {
            $updateStmt->bind_param('si', $newStatus, $approvalId);
            $_SESSION['success_message'] = "Application approved successfully.";
        }
        $updateStmt->execute();
        $updateStmt->close();
    } else {
        $errorMessage = 'Unable to update approval status: ' . $conn->error;
    }

    header('Location: pending_approval.php?sort=' . urlencode($sort) . '&page=' . $currentPage);
    exit;
}

if ($sourceTable === 'applications') {
    $userPkCol = in_array('userid', $userColumns) ? 'userid' : (in_array('user_id', $userColumns) ? 'user_id' : 'id');
    
    // For applications, resolve names from joined tables with fallback
    if (in_array('full_name', $ipColumns)) {
        $nameExpression = "COALESCE(NULLIF(i.full_name, ''), 'N/A')";
    } else {
        $fn = in_array('first_name', $ipColumns) ? 'i.first_name' : "''";
        $ln = in_array('last_name', $ipColumns) ? 'i.last_name' : "''";
        $nameExpression = "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', $fn, $ln)), ''), 'N/A')";
    }

    if (in_array('full_name', $userColumns)) {
        $uNameCol = 'u_elder.full_name';
    } elseif (in_array('name', $userColumns)) {
        $uNameCol = 'u_elder.name';
    } elseif (in_array('first_name', $userColumns) && in_array('last_name', $userColumns)) {
        $uNameCol = "TRIM(CONCAT_WS(' ', u_elder.first_name, u_elder.last_name))";
    } else {
        $uNameCol = "'System'";
    }
    $verifiedByExpression = "COALESCE($uNameCol, 'N/A')";
    $proposedRoleExpression = "'IP Member'";
} else {
    $verifiedByExpression = $verifiedByColumn !== null ? $verifiedByColumn : "'N/A'";
    $proposedRoleExpression = $proposedRoleColumn !== null ? $proposedRoleColumn : "'N/A'";

    $nameExpression = "'N/A'";
    if (in_array('applicant_name', $columns, true)) {
        $nameExpression = 'applicant_name';
    } elseif (in_array('first_name', $columns, true) || in_array('last_name', $columns, true)) {
        $firstNameExpr = in_array('first_name', $columns, true) ? 'first_name' : "''";
        $middleNameExpr = in_array('middle_name', $columns, true) ? 'middle_name' : "''";
        $lastNameExpr = in_array('last_name', $columns, true) ? 'last_name' : "''";
        $nameExpression = "TRIM(CONCAT_WS(' ', {$firstNameExpr}, {$middleNameExpr}, {$lastNameExpr}))";
    }
}

// Helpful debug details when columns are missing
if ($verificationDateColumn === null || $statusColumn === null) {
    $missing = [];
    if ($verificationDateColumn === null) $missing[] = 'application_date/verification_date';
    if ($statusColumn === null) $missing[] = 'status';

    $errorMessage = 'Missing required columns in ' . $sourceTable . ' table: ' . implode(', ', $missing) . ". Available columns: " . implode(', ', $columns);
} else {


    $tablePrefix = ($sourceTable === 'applications') ? "a." : "";
    $orderByClause = "{$tablePrefix}{$verificationDateColumn} DESC";
    if ($sort === 'oldest') {
        $orderByClause = "{$tablePrefix}{$verificationDateColumn} ASC";
    } elseif ($sort === 'name_asc') {
        $orderByClause = "{$nameExpression} ASC";
    } elseif ($sort === 'name_desc') {
        $orderByClause = "{$nameExpression} DESC";
    }

    $statusFilter = "";
    if ($statusColumn === 'approval_status') {
        $statusFilter = " WHERE {$statusColumn} = 'pending_approval'";
    } elseif ($statusColumn === 'status') {
        // Your applications.status pending value is `pending_admin`
        $statusFilter = " WHERE {$tablePrefix}{$statusColumn} = 'pending_admin'";
    }


    // When using applications, we need tribe/applicant info via ipmember_details + tribes.
    // applications table has ip_member_id and status; tribe is stored in ipmember_details.tribe (via tribe_id in tribes).
    $countSql = "SELECT COUNT(*) AS total FROM {$sourceTable} " . ($sourceTable === 'applications' ? 'a' : '') . " {$statusFilter}";
    $countResult = $conn->query($countSql);

    if ($countResult instanceof mysqli_result) {
        $countRow = $countResult->fetch_assoc();
        $totalRecords = (int) ($countRow['total'] ?? 0);
    }

    $totalPages = max(1, (int) ceil($totalRecords / $perPage));
    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }

    $offset = ($currentPage - 1) * $perPage;

    $idSelectExpression = $idColumn !== null ? "{$tablePrefix}{$idColumn} AS approval_id" : "0 AS approval_id";

    // When using applications, fetch tribe via joins.
    // ip_member_details.tribe stores tribe_id, and tribes.tribe_name holds the display name.
    if ($sourceTable === 'applications') {
        $userPkCol = in_array('userid', $userColumns) ? 'userid' : (in_array('user_id', $userColumns) ? 'user_id' : 'id');
        $fnField = in_array('first_name', $ipColumns) ? 'i.first_name' : "'' AS first_name";
        $mnField = in_array('middle_name', $ipColumns) ? 'i.middle_name' : "'' AS middle_name";
        $lnField = in_array('last_name', $ipColumns) ? 'i.last_name' : "'' AS last_name";

        $sql = "SELECT {$idSelectExpression}, {$nameExpression} AS applicant_name,
                       {$verifiedByExpression} AS verified_by,
                       COALESCE(tr.tribe_name, 'N/A') AS target_tribe,
                       {$proposedRoleExpression} AS proposed_role,
                       {$tablePrefix}{$verificationDateColumn} AS verification_date,
                       {$tablePrefix}{$statusColumn} AS approval_status,
                       a.ip_member_id,
                       {$fnField}, {$mnField}, {$lnField},
                       d.date_of_birth, d.place_of_birth, d.barangay, d.mobile_number, d.specific_current_address
                FROM {$sourceTable} a
                LEFT JOIN ipmembers i ON a.ip_member_id = i.ip_member_id
                LEFT JOIN ip_member_details d ON a.ip_member_id = d.ip_member_id
                LEFT JOIN tribes tr ON d.tribe = tr.tribe_id
                LEFT JOIN users u_elder ON a.verified_by_elder = u_elder.$userPkCol
                {$statusFilter}
                ORDER BY {$orderByClause} LIMIT {$perPage} OFFSET {$offset}";
    } else {
        // pending_approvals case (existing behavior)
        $targetTribeSelect = $targetTribeColumn !== null ? "{$targetTribeColumn} AS target_tribe" : "'N/A' AS target_tribe";
        $sql = "SELECT {$idSelectExpression}, {$nameExpression} AS applicant_name, {$verifiedByExpression} AS verified_by, {$targetTribeSelect}, {$proposedRoleExpression} AS proposed_role, {$verificationDateColumn} AS verification_date, {$statusColumn} AS approval_status FROM {$sourceTable}{$statusFilter} ORDER BY {$orderByClause} LIMIT {$perPage} OFFSET {$offset}";
    }


    $result = $conn->query($sql);

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $pending_approval[] = $row;
        }
    } else {
        $errorMessage = 'Unable to load pending approvals: ' . $conn->error;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="../css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <title>IP Lineage - Pending Approval</title>
    <style>
        body { background-color: #f3f4f1; font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <header class="bg-white border-b border-[#dedede] p-4 flex justify-between items-center z-10 sticky top-0">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="p-2 hover:bg-gray-100 rounded-lg transition">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-600"></i>
            </a>
            <div>
                <h1 class="text-lg font-bold text-[#262626]">Pending Approval</h1>
            </div>
        </div>
    </header>

    <div class="p-4 md:p-10">
        <?php 
        $successMessage = $_SESSION['success_message'] ?? '';
        $errorMessage = $_SESSION['error_message'] ?? $errorMessage;
        unset($_SESSION['success_message'], $_SESSION['error_message']);
        ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($successMessage !== ''): ?>
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage === '' && $successMessage === '' && !empty($debugBanner)): ?>
            <div class="mb-6 rounded-xl border border-[#dedede] bg-gray-50/30 px-4 py-3 text-sm text-gray-700">
                <?php echo htmlspecialchars($debugBanner, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>


        <div class="flex flex-col md:flex-row items-center justify-between gap-4 mb-6">

            <div class="relative w-full md:w-96">
                <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"></i>
                <input type="text" placeholder="Search applicant name..." 
                    class="w-full bg-white border border-[#dedede] rounded-xl py-3 pl-12 pr-4 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-sm shadow-sm">
            </div>

            <div class="flex items-center gap-3 ml-auto">
                <div class="relative min-w-[160px]">
                    <select class="appearance-none w-full bg-white border border-[#dedede] rounded-xl pl-4 pr-10 py-3 text-xs font-bold uppercase text-gray-500 outline-none focus:ring-2 focus:ring-[#262626]/10 transition cursor-pointer shadow-sm">
                        <option>All Tribes</option>
                        <option>Iraynon-Bukidnon</option>
                    </select>
                    <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400">
                        <i data-lucide="chevron-down" class="w-4 h-4"></i>
                    </div>
                </div>
                <div class="relative min-w-[160px]">
                    <select name="sort" onchange="window.location.href='pending_approval.php?sort=' + encodeURIComponent(this.value) + '&page=1'" class="appearance-none w-full bg-white border border-[#dedede] rounded-xl pl-4 pr-10 py-3 text-xs font-bold uppercase text-gray-500 outline-none focus:ring-2 focus:ring-[#262626]/10 transition cursor-pointer shadow-sm">
                        <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Sort By: Newest</option>
                        <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Sort By: Oldest</option>
                        <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Sort By: Name A-Z</option>
                        <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Sort By: Name Z-A</option>
                    </select>
                    <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400">
                        <i data-lucide="chevron-down" class="w-4 h-4"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-[#dedede] overflow-hidden">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-[10px] uppercase text-gray-400 bg-gray-50/50 border-b border-[#dedede]">
                        <th class="w-[16%] px-6 py-4 font-bold text-left">Applicant Name</th>
                        <th class="w-[14%] px-6 py-4 font-bold text-left">Verified by</th>
                        <th class="w-[14%] px-6 py-4 font-bold text-left">Proposed Role</th>
                        <th class="w-[16%] px-6 py-4 font-bold text-left">Target Tribe</th>
                        <th class="w-[15%] px-6 py-4 font-bold text-left whitespace-nowrap">Verification Date</th>
                        <th class="w-[15%] px-6 py-4 font-bold text-left whitespace-nowrap">Days Pending</th>
                        <th class="w-[10%] px-6 py-4 font-bold text-center">Action</th>
                    </tr>
                </thead>
                <tbody id="pendingApprovalTableBody" class="text-sm divide-y divide-[#dedede]">
                    <?php if (!empty($pending_approval)): ?>
                        <?php foreach ($pending_approval as $approvalRow): ?>
                            <?php
                                $applicantName = (string) ($approvalRow['applicant_name'] ?? 'N/A');
                                $verifiedBy = (string) ($approvalRow['verified_by'] ?? 'N/A');
                                $proposedRole = (string) ($approvalRow['proposed_role'] ?? 'N/A');
                                $targetTribe = (string) ($approvalRow['target_tribe'] ?? 'N/A');
                                $verificationDate = (string) ($approvalRow['verification_date'] ?? '');

                                // Prefer explicit applicant/user id if available; otherwise fallback to approval_id
                                $applicantId = (string) ($approvalRow['ip_member_id'] ?? $approvalRow['applicant_user_id'] ?? $approvalRow['member_id'] ?? $approvalRow['application_id'] ?? $approvalRow['approval_id'] ?? '');
                                if ($applicantId === '') { $applicantId = '-'; }


                                $verificationDateLabel = 'N/A';
                                $daysPendingLabel = 'N/A';
                                $daysPendingClass = 'text-gray-500';

                                $timestamp = strtotime($verificationDate);
                                if ($timestamp !== false) {
                                    $verificationDateLabel = date('M d, Y', $timestamp);
                                    $daysPending = max(0, (int) floor((time() - $timestamp) / 86400));
                                    $daysPendingLabel = $daysPending . ' Day' . ($daysPending === 1 ? '' : 's');

                                    if ($daysPending >= 7) {
                                        $daysPendingClass = 'text-red-500';
                                    } elseif ($daysPending >= 3) {
                                        $daysPendingClass = 'text-orange-500';
                                    } else {
                                        $daysPendingClass = 'text-green-600';
                                    }
                                }
                            ?>
                            <tr class="pending-approval-row hover:bg-gray-50/50 transition cursor-pointer"
                                data-approval-id="<?php echo htmlspecialchars((string) ($approvalRow['approval_id'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"
                                data-applicant-name="<?php echo htmlspecialchars($applicantName, ENT_QUOTES, 'UTF-8'); ?>"
                                data-verified-by="<?php echo htmlspecialchars($verifiedBy, ENT_QUOTES, 'UTF-8'); ?>"
                                data-proposed-role="<?php echo htmlspecialchars($proposedRole, ENT_QUOTES, 'UTF-8'); ?>"
                                data-target-tribe="<?php echo htmlspecialchars($targetTribe, ENT_QUOTES, 'UTF-8'); ?>"
                                data-verification-date="<?php echo htmlspecialchars($verificationDateLabel, ENT_QUOTES, 'UTF-8'); ?>"
                                data-days-pending="<?php echo htmlspecialchars($daysPendingLabel, ENT_QUOTES, 'UTF-8'); ?>"
                                data-applicant-id="<?php echo htmlspecialchars($applicantId, ENT_QUOTES, 'UTF-8'); ?>"
                                data-ip-member-id="<?php echo htmlspecialchars((string)($approvalRow['ip_member_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-first-name="<?php echo htmlspecialchars((string)($approvalRow['first_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-middle-name="<?php echo htmlspecialchars((string)($approvalRow['middle_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-last-name="<?php echo htmlspecialchars((string)($approvalRow['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-birthdate="<?php echo htmlspecialchars((string)($approvalRow['date_of_birth'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-pob="<?php echo htmlspecialchars((string)($approvalRow['place_of_birth'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-barangay="<?php echo htmlspecialchars((string)($approvalRow['barangay'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-mobile="<?php echo htmlspecialchars((string)($approvalRow['mobile_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-address="<?php echo htmlspecialchars((string)($approvalRow['specific_current_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            >


                                <td class="px-6 py-4"> 
                                    <div class="font-semibold text-[#262626]"><?php echo htmlspecialchars($applicantName, ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-[#262626]"><?php echo htmlspecialchars($verifiedBy, ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-[#262626]"><?php echo htmlspecialchars($proposedRole, ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="text-gray-700 text-[10px] font-bold uppercase bg-gray-100 px-2 py-1 rounded"><?php echo htmlspecialchars($targetTribe, ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-xs font-semibold text-[#262626]"><?php echo htmlspecialchars($verificationDateLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="text-xs font-bold <?php echo $daysPendingClass; ?>"><?php echo htmlspecialchars($daysPendingLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <div class="flex items-center justify-center gap-2 whitespace-nowrap">
                                        <button type="button" 
                                                onclick="event.stopPropagation(); openRejectionModal(this)"
                                                data-id="<?php echo htmlspecialchars((string) ($approvalRow['approval_id'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-name="<?php echo htmlspecialchars($applicantName, ENT_QUOTES, 'UTF-8'); ?>"
                                                class="text-red-500 hover:text-red-700 font-bold text-[10px] uppercase px-2 py-2">Reject</button>
                                        
                                        <button type="button" 
                                                onclick="event.stopPropagation(); openApprovalModal(this)"
                                                data-id="<?php echo htmlspecialchars((string) ($approvalRow['approval_id'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-name="<?php echo htmlspecialchars($applicantName, ENT_QUOTES, 'UTF-8'); ?>"
                                                class="bg-green-600 text-white px-3 py-2 rounded-lg text-[10px] font-bold uppercase hover:bg-black transition">Approve</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                                <td colspan="7" class="px-6 py-8 text-center text-gray-400">No pending approvals found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div class="p-6 border-t border-[#dedede] flex justify-between items-center bg-gray-50/30">
                <p class="text-[10px] font-bold text-gray-400 uppercase">Showing <?php echo count($pending_approval); ?> of <?php echo (int) $totalRecords; ?> Results</p>
                <div class="flex gap-2">
                    <?php if ($currentPage > 1): ?>
                        <a href="?sort=<?php echo urlencode($sort); ?>&page=<?php echo $currentPage - 1; ?>" class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-lg hover:bg-white transition">Previous</a>
                    <?php else: ?>
                        <span class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-lg text-gray-300 cursor-not-allowed">Previous</span>
                    <?php endif; ?>

                    <span class="px-3 py-2 text-xs font-bold text-gray-500">Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>

                    <?php if ($currentPage < $totalPages): ?>
                        <a href="?sort=<?php echo urlencode($sort); ?>&page=<?php echo $currentPage + 1; ?>" class="px-4 py-2 text-xs font-bold bg-[#262626] text-white rounded-lg hover:bg-black transition shadow-sm">Next</a>
                    <?php else: ?>
                        <span class="px-4 py-2 text-xs font-bold bg-[#262626]/30 text-white rounded-lg cursor-not-allowed">Next</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- FLOATING MEMBER CARD OVERLAY -->
    <aside id="floatingMemberCard" class="hidden fixed inset-0 z-[60] items-center justify-center p-4 sm:p-6">
        <div id="floatingMemberBackdrop" class="absolute inset-0 bg-black/40 backdrop-blur-xs"></div>
        <div class="relative z-10 w-full max-w-4xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white shadow-[0_10px_30px_rgba(0,0,0,0.15)] border border-[#ececea] flex flex-col">
            <div class="px-6 py-5 border-b border-[#ececea] flex items-center justify-between bg-gray-50/50">
                <div class="flex items-center gap-2">
                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <h3 class="text-xs font-bold uppercase tracking-wider text-[#262626]">Verified Member Record</h3>
                </div>
                <button id="closeFloatingMemberCard" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-[#262626] transition-all duration-200">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-[#ececea]">
                <div class="p-6 bg-gradient-to-b from-gray-50/30 to-white flex flex-col items-center text-center justify-center col-span-1 min-w-[240px]">
                    <div class="flex items-center justify-center h-28 w-28 shrink-0 balance-profile-container">
                        <div id="floatingInitials" class="h-28 w-28 rounded-full bg-gray-100 text-[#262626] flex items-center justify-center shadow-md font-bold text-3xl tracking-wide uppercase border-4 border-white ring-1 ring-gray-200 aspect-square object-cover border-[#dedede]">--</div>
                    </div>
                    <h2 id="floatingFullName" class="mt-4 text-xl font-bold text-[#262626] tracking-tight">No applicant selected</h2>
                    <div class="mt-2 inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-700 border border-gray-200">
                        ID: <span id="floatingMemberId" class="ml-1 font-bold text-[#262626]">-</span>
                    </div>
                </div>

                <div class="p-6 col-span-2 space-y-6">
                    <div>
                        <h4 class="mb-3 text-[11px] font-bold uppercase tracking-wider text-gray-400">Core Identity</h4>
                        <div class="grid grid-cols-1 gap-4">
                            <div class="bg-gray-50/60 p-3 rounded-xl border border-gray-100">
                                <span class="block text-[11px] font-medium text-gray-400 uppercase">Full Name</span>
                                <span id="floatingCoreFullName" class="text-sm font-semibold text-[#262626] mt-0.5 block">-</span>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h4 class="mb-3 text-[11px] font-bold uppercase tracking-wider text-gray-400">Vital & Heritage Information</h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
                            <div class="flex items-center justify-between py-2.5 border-b border-[#ececea]">
                                <span class="text-xs font-medium text-gray-500">Birthdate</span>
                                <span id="floatingBirthdate" class="text-xs font-bold text-[#262626]">-</span>
                            </div>
                            <div class="flex items-center justify-between py-2.5 border-b border-[#ececea]">
                                <span class="text-xs font-medium text-gray-500">Place of Birth</span>
                                <span id="floatingPlaceOfBirth" class="text-xs font-bold text-[#262626] max-w-[180px] text-right truncate" title="-">-</span>
                            </div>
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

            <div class="px-6 py-4 bg-gray-50/50 border-t border-[#ececea] flex justify-end gap-3">
                <button type="button" onclick="closeMemberCard()" class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-xl hover:bg-white text-gray-600 transition-all">Close View</button>
            </div>
        </div>
    </aside>

    <!-- Rejection Modal -->
    <div id="rejectionModal" class="hidden fixed inset-0 z-[70] items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-xs" onclick="closeRejectionModal()"></div>
        <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-[0_10px_30px_rgba(0,0,0,0.15)] border border-[#ececea] p-6">
            <h3 class="text-base font-bold text-[#262626] mb-2 flex items-center gap-2">
                <span class="inline-block w-2.5 h-2.5 rounded-full bg-red-500"></span>
                Reject Application
            </h3>
            <p class="text-xs text-gray-500 mb-4">Are you sure you want to reject <span id="rejectModalName" class="font-bold text-[#262626]">-</span>? Please provide the reason for rejection.</p>
            
            <form method="POST" action="pending_approval.php?sort=<?php echo htmlspecialchars($sort, ENT_QUOTES, 'UTF-8'); ?>&page=<?php echo (int) $currentPage; ?>">
                <input type="hidden" name="approval_id" id="rejectInputId">
                <input type="hidden" name="approval_action" value="reject">
                <textarea id="rejectModalRemarks" name="rejection_remarks" rows="4" class="w-full bg-gray-50 border border-[#dedede] rounded-xl p-3 text-sm focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition mb-4 resize-none" placeholder="Reason for rejection..." required></textarea>
                
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeRejectionModal()" class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-xl hover:bg-gray-50 text-gray-600 transition-all">Cancel</button>
                    <button type="submit" class="px-4 py-2 text-xs font-bold bg-red-600 hover:bg-red-700 text-white rounded-xl transition-all shadow-sm">Confirm Reject</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Approval Modal -->
    <div id="approvalModal" class="hidden fixed inset-0 z-[70] items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-xs" onclick="closeApprovalModal()"></div>
        <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-[0_10px_30px_rgba(0,0,0,0.15)] border border-[#ececea] p-6">
            <h3 class="text-base font-bold text-[#262626] mb-2 flex items-center gap-2">
                <span class="inline-block w-2.5 h-2.5 rounded-full bg-green-500"></span>
                Confirm Final Approval
            </h3>
            <p class="text-xs text-gray-500 mb-6">Are you sure you want to approve the application for <span id="approveModalName" class="font-bold text-[#262626]">-</span>? This will grant them the IP Member role.</p>
            
            <form method="POST" action="pending_approval.php?sort=<?php echo htmlspecialchars($sort, ENT_QUOTES, 'UTF-8'); ?>&page=<?php echo (int) $currentPage; ?>">
                <input type="hidden" name="approval_id" id="approveInputId">
                <input type="hidden" name="approval_action" value="approve">
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeApprovalModal()" class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-xl hover:bg-gray-50 text-gray-600 transition-all">Cancel</button>
                    <button type="submit" class="px-4 py-2 text-xs font-bold bg-green-600 hover:bg-green-700 text-white rounded-xl transition-all shadow-sm">Confirm & Approve</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openMemberCard() {
            const floatingMemberCard = document.getElementById('floatingMemberCard');
            if (!floatingMemberCard) return;
            floatingMemberCard.classList.remove('hidden');
            floatingMemberCard.classList.add('flex');
            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';
        }

        function closeMemberCard() {
            const floatingMemberCard = document.getElementById('floatingMemberCard');
            if (!floatingMemberCard) return;
            floatingMemberCard.classList.add('hidden');
            floatingMemberCard.classList.remove('flex');
            document.body.style.overflow = '';
            document.documentElement.style.overflow = '';
        }

        window.openRejectionModal = function(btn) {
            const id = btn.dataset.id;
            const name = btn.dataset.name;
            document.getElementById('rejectInputId').value = id;
            document.getElementById('rejectModalName').textContent = name;
            document.getElementById('rejectModalRemarks').value = '';
            const modal = document.getElementById('rejectionModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        };

        window.closeRejectionModal = function() {
            document.getElementById('rejectionModal').classList.add('hidden');
            document.getElementById('rejectionModal').classList.remove('flex');
            document.body.style.overflow = '';
        };

        window.openApprovalModal = function(btn) {
            const id = btn.dataset.id;
            const name = btn.dataset.name;
            document.getElementById('approveInputId').value = id;
            document.getElementById('approveModalName').textContent = name;
            const modal = document.getElementById('approvalModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        };

        window.closeApprovalModal = function() {
            document.getElementById('approvalModal').classList.add('hidden');
            document.getElementById('approvalModal').classList.remove('flex');
            document.body.style.overflow = '';
        };

        lucide.createIcons();

        const tableBody = document.getElementById('pendingApprovalTableBody');
        const floatingMemberCard = document.getElementById('floatingMemberCard');
        const closeFloatingMemberCard = document.getElementById('closeFloatingMemberCard');
        const floatingMemberBackdrop = document.getElementById('floatingMemberBackdrop');

        if (tableBody && floatingMemberCard) {
            const floatingInitials = document.getElementById('floatingInitials');
            const floatingFullName = document.getElementById('floatingFullName');
            const floatingCoreFullName = document.getElementById('floatingCoreFullName');
            const floatingBirthdate = document.getElementById('floatingBirthdate');
            const floatingPlaceOfBirth = document.getElementById('floatingPlaceOfBirth');
            const floatingTribeClan = document.getElementById('floatingTribeClan');
            const floatingBarangay = document.getElementById('floatingBarangay');
            const floatingContactInformation = document.getElementById('floatingContactInformation');
            const floatingCurrentAddress = document.getElementById('floatingCurrentAddress');
            const floatingMemberId = document.getElementById('floatingMemberId');
            const floatingFamilyTreeLink = document.getElementById('floatingFamilyTreeLink');

            function getInitials(name) {
                const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
                if (parts.length === 0) return '--';
                const first = parts[0].charAt(0).toUpperCase();
                const second = (parts[1] || '').charAt(0).toUpperCase();
                return (first + second) || '--';
            }

            tableBody.addEventListener('click', function(event) {
                if (event.target.closest('button')) return; // don't open when clicking action buttons

                const row = event.target.closest('tr.pending-approval-row');
                if (!row) return;

                const fullName = row.dataset.applicantName || 'N/A';
                const tribe = row.dataset.targetTribe || 'N/A';
                const verifiedBy = row.dataset.verifiedBy || 'N/A';
                const daysPending = row.dataset.daysPending || 'N/A';

                if (floatingInitials) floatingInitials.textContent = getInitials(fullName);
                if (floatingFullName) floatingFullName.textContent = fullName;
                if (floatingCoreFullName) floatingCoreFullName.textContent = fullName;


                if (floatingBirthdate) floatingBirthdate.textContent = row.dataset.birthdate || 'N/A';
                if (floatingPlaceOfBirth) floatingPlaceOfBirth.textContent = row.dataset.pob || 'N/A';
                if (floatingTribeClan) floatingTribeClan.textContent = tribe;
                if (floatingBarangay) floatingBarangay.textContent = row.dataset.barangay || 'N/A';
                
                if (floatingContactInformation) floatingContactInformation.textContent = row.dataset.mobile || 'N/A';
                if (floatingCurrentAddress) floatingCurrentAddress.textContent = row.dataset.address || 'N/A';

                if (floatingMemberId) floatingMemberId.textContent = row.dataset.ipMemberId || row.dataset.applicantId || '-';


                if (floatingFamilyTreeLink) {
                    // No member_id in this query; keep default
                    floatingFamilyTreeLink.href = 'family_lineage.php';
                }

                openMemberCard();
            });
        }

        if (closeFloatingMemberCard && floatingMemberCard) {
            closeFloatingMemberCard.addEventListener('click', closeMemberCard);
        }

        if (floatingMemberBackdrop) {
            floatingMemberBackdrop.addEventListener('click', closeMemberCard);
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const floatingMemberCard = document.getElementById('floatingMemberCard');
                if (floatingMemberCard && !floatingMemberCard.classList.contains('hidden')) {
                    closeMemberCard();
                }
                closeRejectionModal();
                closeApprovalModal();
            }
        });
    </script>
    </body>
</html>