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

$columns = [];
$columnResult = $conn->query("SHOW COLUMNS FROM {$sourceTable}");
if ($columnResult instanceof mysqli_result) {
    while ($columnRow = $columnResult->fetch_assoc()) {
        $columns[] = $columnRow['Field'];
    }
}

$idColumn = first_existing_column($columns, ['id', 'pending_approval_id', 'approval_id']);
$approveRejectDateColumn = first_existing_column($columns, ['approve_reject_date', 'approved_at', 'updated_at']);

$verifiedByColumn = first_existing_column($columns, ['verified_by', 'verifier_name']);
$proposedRoleColumn = first_existing_column($columns, ['proposed_role', 'target_role']);
$targetTribeColumn = first_existing_column($columns, ['target_tribe', 'tribe_clan', 'tribe', 'tribe_name']);
$verificationDateColumn = first_existing_column($columns, ['verification_date', 'created_at']);
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

    $updateSql = "UPDATE {$sourceTable} SET {$statusColumn} = ?";
    if ($approveRejectDateColumn !== null) {
        $updateSql .= ", {$approveRejectDateColumn} = CURDATE()";
    }
    $updateSql .= " WHERE {$idColumn} = ?";
    $updateStmt = $conn->prepare($updateSql);
    if ($updateStmt) {
        $updateStmt->bind_param('si', $newStatus, $approvalId);
        $updateStmt->execute();
        $updateStmt->close();
    } else {
        $errorMessage = 'Unable to update approval status: ' . $conn->error;
    }

    header('Location: pending_approval.php?sort=' . urlencode($sort) . '&page=' . $currentPage);
    exit;
}

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

if ($targetTribeColumn === null || $verificationDateColumn === null || $statusColumn === null) {
    $errorMessage = 'Missing required columns in ' . $sourceTable . ' table (applicant_name, target_tribe, verification_date, status).';
} else {
    $orderByClause = "{$verificationDateColumn} DESC";
    if ($sort === 'oldest') {
        $orderByClause = "{$verificationDateColumn} ASC";
    } elseif ($sort === 'name_asc') {
        $orderByClause = "{$nameExpression} ASC";
    } elseif ($sort === 'name_desc') {
        $orderByClause = "{$nameExpression} DESC";
    }

    $statusFilter = "";
    if ($statusColumn === 'approval_status') {
        $statusFilter = " WHERE {$statusColumn} = 'pending_approval'";
    } elseif ($statusColumn === 'status') {
        $statusFilter = " WHERE {$statusColumn} = 'pending'";
    }

    $countSql = "SELECT COUNT(*) AS total FROM {$sourceTable}{$statusFilter}";
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

    $idSelectExpression = $idColumn !== null ? "{$idColumn} AS approval_id" : "0 AS approval_id";
    $sql = "SELECT {$idSelectExpression}, {$nameExpression} AS applicant_name, {$verifiedByExpression} AS verified_by, {$targetTribeColumn} AS target_tribe, {$proposedRoleExpression} AS proposed_role, {$verificationDateColumn} AS verification_date, {$statusColumn} AS approval_status FROM {$sourceTable}{$statusFilter} ORDER BY {$orderByClause} LIMIT {$perPage} OFFSET {$offset}";
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
                <tbody class="text-sm divide-y divide-[#dedede]">
                    <?php if (!empty($pending_approval)): ?>
                        <?php foreach ($pending_approval as $approvalRow): ?>
                            <?php
                                $applicantName = (string) ($approvalRow['applicant_name'] ?? 'N/A');
                                $verifiedBy = (string) ($approvalRow['verified_by'] ?? 'N/A');
                                $proposedRole = (string) ($approvalRow['proposed_role'] ?? 'N/A');
                                $targetTribe = (string) ($approvalRow['target_tribe'] ?? 'N/A');
                                $verificationDate = (string) ($approvalRow['verification_date'] ?? '');

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
                            <tr class="hover:bg-gray-50/50 transition">
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
                                        <form method="post" action="pending_approval.php?sort=<?php echo htmlspecialchars($sort, ENT_QUOTES, 'UTF-8'); ?>&page=<?php echo (int) $currentPage; ?>" class="inline-block">
                                            <input type="hidden" name="approval_id" value="<?php echo htmlspecialchars((string) ($approvalRow['approval_id'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="approval_action" value="reject">
                                            <button type="submit" class="text-red-500 hover:text-red-700 font-bold text-[10px] uppercase px-2 py-2">Reject</button>
                                        </form>
                                        <form method="post" action="pending_approval.php?sort=<?php echo htmlspecialchars($sort, ENT_QUOTES, 'UTF-8'); ?>&page=<?php echo (int) $currentPage; ?>" class="inline-block">
                                            <input type="hidden" name="approval_id" value="<?php echo htmlspecialchars((string) ($approvalRow['approval_id'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="approval_action" value="approve">
                                            <button type="submit" class="bg-green-600 text-white px-3 py-2 rounded-lg text-[10px] font-bold uppercase hover:bg-black transition">Approve</button>
                                        </form>
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

    <script>
        lucide.createIcons();
        function showDetails(name) {
            console.log("Details for: " + name);
            // Link your side panel function here
        }
    </script>
</body>
</html>