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

$applications = [];
$errorMessage = '';
$sort = isset($_GET['sort']) ? strtolower(trim((string) $_GET['sort'])) : 'newest';
$perPage = 5;
$currentPage = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($currentPage < 1) {
    $currentPage = 1;
}
$totalRecords = 0;
$totalPages = 1;

// --- Handle Verification/Rejection Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['application_id'])) {
    $appId = (int)$_POST['application_id'];
    $action = $_POST['action'];
    $elderId = (int)($_SESSION['user_id'] ?? 0);

    if ($action === 'verify') {
        $sql = "UPDATE applications SET status = 'pending_admin', verified_by_elder = ? WHERE application_id = ? AND status = 'pending_elder'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $elderId, $appId);
        $stmt->execute();
        $stmt->close();
        $_SESSION['success_message'] = "Application verified. It is now pending admin approval.";
    } elseif ($action === 'reject') {
        $remarks = trim((string)($_POST['rejection_remarks'] ?? ''));
        $sql = "UPDATE applications SET status = 'rejected', rejection_remarks = ? WHERE application_id = ? AND status = 'pending_elder'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $remarks, $appId);
        $stmt->execute();
        $stmt->close();
        $_SESSION['error_message'] = "Application rejected.";
    }
    
    header('Location: pending_verification.php?sort=' . urlencode($sort) . '&page=' . $currentPage);
    exit;
}

// Define the base query for applications
$baseSelect = "SELECT a.application_id, i.full_name AS applicant_name, COALESCE(t.tribe_name, d.tribe) AS target_tribe, a.application_date, a.status, a.ip_member_id, d.date_of_birth, d.place_of_birth, d.mobile_number, d.barangay, d.specific_current_address, d.marital_status, d.educational_attainment";
$baseFrom = "FROM applications a JOIN ipmembers i ON a.ip_member_id = i.ip_member_id LEFT JOIN ip_member_details d ON a.ip_member_id = d.ip_member_id LEFT JOIN tribes t ON d.tribe = t.tribe_id";
$baseWhere = "WHERE a.status = 'pending_elder'";

$orderByClause = "a.application_date DESC";
    if ($sort === 'oldest') {
        $orderByClause = "a.application_date ASC";
    } elseif ($sort === 'name_asc') {
        $orderByClause = "applicant_name ASC";
    } elseif ($sort === 'name_desc') {
        $orderByClause = "applicant_name DESC";
    }

    $countSql = "SELECT COUNT(*) AS total {$baseFrom} {$baseWhere}";
    $countResult = $conn->query($countSql); // No need for prepare if no dynamic params
    if ($countResult instanceof mysqli_result) {
        $countRow = $countResult->fetch_assoc();
        $totalRecords = (int) ($countRow['total'] ?? 0);
    }

    $totalPages = max(1, (int) ceil($totalRecords / $perPage));
    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }

    $offset = ($currentPage - 1) * $perPage;

    $sql = "{$baseSelect} {$baseFrom} {$baseWhere} ORDER BY {$orderByClause} LIMIT {$perPage} OFFSET {$offset}";
    $result = $conn->query($sql);

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $applications[] = $row;
        }
    } else {
        $errorMessage = 'Unable to load applications: ' . $conn->error;
    }

// Capture session messages for display after redirection
$successMessage = $_SESSION['success_message'] ?? '';
$errorMessage = $_SESSION['error_message'] ?? $errorMessage;
unset($_SESSION['success_message'], $_SESSION['error_message']);
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
    <title>IP Lineage - Visual Tree</title>
    <style>
        body { background-color: #f3f4f1; font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <header class="bg-white border-b border-[#dedede] p-4 flex justify-between items-center z-10">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="p-2 hover:bg-gray-100 rounded-lg transition">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-600"></i>
            </a>
            <div>
                <h1 class="text-lg font-bold text-[#262626]">Pending Verification</h1>
            </div>
        </div>
    </header>

    <div class="p-4 md:p-10">
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
                        <option>Ati Tribe</option>
                    </select>
                    <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400">
                        <i data-lucide="chevron-down" class="w-4 h-4"></i>
                    </div>
                </div>
                <div class="relative min-w-[160px]">
                    <form method="get" action="pending_verification.php">
                    <input type="hidden" name="page" value="1">
                    <select name="sort" onchange="this.form.submit()" class="appearance-none w-full bg-white border border-[#dedede] rounded-xl pl-4 pr-10 py-3 text-xs font-bold uppercase text-gray-500 outline-none focus:ring-2 focus:ring-[#262626]/10 transition cursor-pointer shadow-sm">
                        <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Sort By: Newest</option>
                        <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Sort By: Oldest</option>
                        <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Sort By: Name A-Z</option>
                        <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Sort By: Name Z-A</option>
                    </select>
                    </form>
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
                        <th class="px-6 py-4 font-bold">Applicant Name</th>
                        <th class="px-6 py-4 font-bold">Target Tribe</th>
                        <th class="px-6 py-4 font-bold">Application Date</th>
                        <th class="px-6 py-4 font-bold">Days Pending</th>
                        <th class="px-6 py-4 font-bold text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="text-sm divide-y divide-[#dedede]">
                    <?php if (!empty($applications)): ?>
                        <?php foreach ($applications as $application): ?>
                            <?php
                                $applicantName = (string) ($application['applicant_name'] ?? 'N/A');
                                $targetTribe = (string) ($application['target_tribe'] ?? 'N/A');
                                $applicationDate = (string) ($application['application_date'] ?? '');
                                $applicationId = (int) ($application['application_id'] ?? 0);

                                $applicationDateLabel = 'N/A';
                                $daysPendingLabel = 'N/A';
                                $daysPendingClass = 'text-gray-500';

                                $timestamp = strtotime($applicationDate);
                                if ($timestamp !== false) {
                                    $applicationDateLabel = date('M d, Y', $timestamp);
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

                                // Split full_name into parts for the popup display
                                $nameParts = explode(' ', trim($applicantName));
                                $firstNameVal = $nameParts[0] ?? 'N/A';
                                $lastNameVal = count($nameParts) > 1 ? end($nameParts) : 'N/A';
                                $middleNameVal = count($nameParts) > 2 ? implode(' ', array_slice($nameParts, 1, -1)) : '';
                            ?>
                            <tr class="hover:bg-gray-100 transition-colors duration-200 cursor-pointer"
                                onclick="openVerificationPopup(this)"
                                data-application-id="<?php echo htmlspecialchars((string)$applicationId, ENT_QUOTES, 'UTF-8'); ?>"
                                data-applicant-name="<?php echo htmlspecialchars($applicantName, ENT_QUOTES, 'UTF-8'); ?>"
                                data-target-tribe="<?php echo htmlspecialchars($targetTribe, ENT_QUOTES, 'UTF-8'); ?>"
                                data-application-date="<?php echo htmlspecialchars($applicationDateLabel, ENT_QUOTES, 'UTF-8'); ?>"
                                data-raw-date="<?php echo htmlspecialchars($applicationDate, ENT_QUOTES, 'UTF-8'); ?>"
                                data-first-name="<?php echo htmlspecialchars($firstNameVal, ENT_QUOTES, 'UTF-8'); ?>"
                                data-middle-name="<?php echo htmlspecialchars($middleNameVal, ENT_QUOTES, 'UTF-8'); ?>"
                                data-last-name="<?php echo htmlspecialchars($lastNameVal, ENT_QUOTES, 'UTF-8'); ?>"
                                data-mobile="<?php echo htmlspecialchars((string)($application['mobile_number'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>"
                                data-address="<?php echo htmlspecialchars((string)($application['specific_current_address'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>"
                                data-ip-member-id="<?php echo htmlspecialchars((string)$application['ip_member_id'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-dob="<?php echo htmlspecialchars((string)($application['date_of_birth'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>"
                                data-pob="<?php echo htmlspecialchars((string)($application['place_of_birth'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>"
                                data-marital="<?php echo htmlspecialchars((string)($application['marital_status'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>"
                                data-education="<?php echo htmlspecialchars((string)($application['educational_attainment'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>"
                                data-days-pending="<?php echo htmlspecialchars($daysPendingLabel, ENT_QUOTES, 'UTF-8'); ?>"
                            >
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-[#262626]"><?php echo htmlspecialchars($applicantName, ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="text-gray-700 text-[10px] font-bold uppercase bg-gray-100 px-2 py-1 rounded"><?php echo htmlspecialchars($targetTribe, ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td class="px-8 py-4">
                                    <div class="text-xs font-semibold text-[#262626]"><?php echo htmlspecialchars($applicationDateLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td class="px-10 py-4">
                                    <span class="text-xs font-bold <?php echo $daysPendingClass; ?>"><?php echo htmlspecialchars($daysPendingLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <button onclick="window.location.href='view_application.php?id=<?php echo $applicationId; ?>'"
                                            class="row-action px-3 py-1.5 text-xs font-bold bg-[#262626] text-white rounded-lg hover:bg-[#404040] transition shadow-sm">
                                        <i data-lucide="eye" class="w-3.5 h-3.5 inline-block mr-1"></i>
                                        View Details
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-gray-400">No pending applications found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div class="p-6 border-t border-[#dedede] flex justify-between items-center bg-gray-50/30">
                <p class="text-[10px] font-bold text-gray-400 uppercase">Showing <?php echo count($applications); ?> of <?php echo (int) $totalRecords; ?> Result<?php echo $totalRecords === 1 ? '' : 's'; ?></p>
                <div class="flex gap-2">
                    <?php if ($currentPage > 1): ?>
                        <a href="?sort=<?php echo urlencode($sort); ?>&page=<?php echo $currentPage - 1; ?>" class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-lg hover:bg-white transition">Previous</a>
                    <?php else: ?>
                        <span class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-lg text-gray-300 cursor-not-allowed">Previous</span>
                    <?php endif; ?>

                    <span class="px-3 py-2 text-xs font-bold text-gray-500">Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>

                    <?php if ($currentPage < $totalPages): ?>
                        <a href="?sort=<?php echo urlencode($sort); ?>&page=<?php echo $currentPage + 1; ?>" class="px-4 py-2 text-xs font-bold bg-[#262626] text-white rounded-lg hover:bg-[#404040] transition">Next</a>
                    <?php else: ?>
                        <span class="px-4 py-2 text-xs font-bold bg-[#262626]/30 text-white rounded-lg cursor-not-allowed">Next</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- FLOATING MEMBER CARD OVERLAY (MIRRORED FROM APPROVAL) -->
    <aside id="floatingMemberCard" class="hidden fixed inset-0 z-[60] items-center justify-center p-4 sm:p-6">
        <div id="floatingMemberBackdrop" class="absolute inset-0 bg-black/40 backdrop-blur-xs"></div>
        
        <div class="relative z-10 w-full max-w-4xl max-h-[90vh] rounded-2xl bg-white shadow-[0_10px_30px_rgba(0,0,0,0.15)] border border-[#ececea] flex flex-col overflow-hidden">
            
            <!-- Card Header Layout -->
            <div class="px-6 py-4 border-b border-[#ececea] flex items-center justify-between bg-gray-50/50 shrink-0">
                <div class="flex items-center gap-2">
                    <span class="inline-block w-2 h-2 rounded-full bg-[#f59e0b] animate-pulse"></span>
                    <h3 class="text-xs font-bold uppercase tracking-wider text-[#262626]">Application Verification Hub</h3>
                </div>
                <button id="closeFloatingMemberCard" onclick="closeMemberCard()" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-[#262626] transition-all duration-200">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>

            <!-- Main Content Area - Scrollable -->
            <div class="flex-1 overflow-y-auto">
                <div class="grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-[#ececea]">
                
                    <!-- Left Sidebar Profile Panel -->
                    <div class="p-8 bg-gradient-to-b from-gray-50/30 to-white flex flex-col items-center text-center col-span-1">
                        <div class="flex items-center justify-center h-28 w-28 shrink-0 mb-4">
                            <div id="popupInitials" class="h-28 w-28 rounded-full bg-[#262626] text-white flex items-center justify-center shadow-lg font-bold text-3xl tracking-wide uppercase border-4 border-white ring-1 ring-gray-200 aspect-square object-cover">
                                --
                            </div>
                        </div>
                        <h2 id="popupName" class="text-xl font-bold text-[#262626] tracking-tight mb-1">No applicant selected</h2>
                        <p id="popupTribe" class="text-xs font-bold text-amber-700 uppercase tracking-widest mb-4">--</p>
                        
                        <div class="w-full space-y-2 mt-4 pt-4 border-t border-gray-100">
                            <div class="flex justify-between items-center text-[10px] uppercase font-bold text-gray-400">
                                <span>Application ID</span>
                                <span id="popupAppId" class="text-[#262626]">-</span>
                            </div>
                            <div class="flex justify-between items-center text-[10px] uppercase font-bold text-gray-400">
                                <span>Status</span>
                                <span class="px-2 py-0.5 rounded-md bg-blue-50 text-blue-600">Pending Elder</span>
                            </div>
                        </div>
                    </div>

                    <!-- Right Structured Information Fields -->
                    <div class="p-8 col-span-2 space-y-8">
                    
                    <!-- Core Identity Block -->
                    <div>
                        <h4 class="mb-3 text-[11px] font-bold uppercase tracking-wider text-gray-400">Core Identity</h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="bg-gray-50/60 p-3 rounded-xl border border-gray-100">
                                <span class="block text-[11px] font-medium text-gray-400 uppercase">Applicant Name</span>
                                <span id="displayFullName" class="text-xs font-bold text-[#262626] mt-0.5 block">-</span>
                            </div>
                            <div class="bg-gray-50/60 p-3 rounded-xl border border-gray-100">
                                <span class="block text-[11px] font-medium text-gray-400 uppercase">IP Member ID</span>
                                <span id="popupIpMemberId" class="text-xs font-bold text-[#262626] mt-0.5 block">-</span>
                            </div>
                        </div>
                    </div>

                    <!-- Personal Details Block -->
                    <div>
                        <h4 class="mb-3 text-[11px] font-bold uppercase tracking-wider text-gray-400">Personal Information</h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
                            <div class="flex items-center justify-between py-2 border-b border-[#ececea]">
                                <span class="text-xs font-medium text-gray-500">Date of Birth</span>
                                <span id="popupDob" class="text-xs font-bold text-[#262626]">-</span>
                            </div>
                            <div class="flex items-center justify-between py-2 border-b border-[#ececea]">
                                <span class="text-xs font-medium text-gray-500">Place of Birth</span>
                                <span id="popupPob" class="text-xs font-bold text-[#262626]">-</span>
                            </div>
                            <div class="flex items-center justify-between py-2 border-b border-[#ececea]">
                                <span class="text-xs font-medium text-gray-500">Marital Status</span>
                                <span id="popupMarital" class="text-xs font-bold text-[#262626]">-</span>
                            </div>
                            <div class="flex items-center justify-between py-2 border-b border-[#ececea]">
                                <span class="text-xs font-medium text-gray-500">Education</span>
                                <span id="popupEducation" class="text-xs font-bold text-[#262626]">-</span>
                            </div>
                            <div class="flex items-center justify-between py-2 border-b border-[#ececea]">
                                <span class="text-xs font-medium text-gray-500">Mobile Number</span>
                                <span id="popupMobile" class="text-xs font-bold text-[#262626]">-</span>
                            </div>
                            <div class="flex items-center justify-between py-2 border-b border-[#ececea]">
                                <span class="text-xs font-medium text-gray-500">Address</span>
                                <span id="popupAddress" class="text-xs font-bold text-[#262626] truncate max-w-[150px]">-</span>
                            </div>
                        </div>
                    </div>

                    <!-- Documents Section -->
                    <div>
                        <h4 class="mb-3 text-[11px] font-bold uppercase tracking-wider text-gray-400">Supporting Evidence</h4>
                        <div id="popupDocuments" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="flex items-center justify-between p-3 border border-gray-100 rounded-xl bg-gray-50/30">
                                <div class="flex items-center gap-2">
                                    <i data-lucide="file-text" class="w-4 h-4 text-gray-400"></i>
                                    <span class="text-[10px] font-bold text-gray-600">Birth Certificate</span>
                                </div>
                                <span class="text-[10px] text-gray-400">Uploaded</span>
                            </div>
                            <div class="flex items-center justify-between p-3 border border-gray-100 rounded-xl bg-gray-50/30">
                                <div class="flex items-center gap-2">
                                    <i data-lucide="file-text" class="w-4 h-4 text-gray-400"></i>
                                    <span class="text-[10px] font-bold text-gray-600">NCIP Form</span>
                                </div>
                                <span class="text-[10px] text-gray-400">Uploaded</span>
                            </div>
                        </div>
                        
                        <div class="mt-6 grid grid-cols-2 gap-4">
                             <div class="flex items-center justify-between py-2 border-b border-[#ececea]">
                                <span class="text-xs font-medium text-gray-500">Submitted On</span>
                                <span id="popupDate" class="text-xs font-bold text-[#262626]">-</span>
                            </div>
                            <div class="flex items-center justify-between py-2 border-b border-[#ececea]">
                                <span class="text-xs font-medium text-gray-500">Days Pending</span>
                                <span id="popupDays" class="text-xs font-bold text-orange-600">-</span>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Bottom Footer Actions Block -->

            <div class="px-8 py-5 bg-gray-50 border-t border-[#ececea] flex justify-end items-center shrink-0">
                <div class="flex gap-3">
                    <button type="button" onclick="openRejectionModal()" class="px-4 py-2 text-xs font-bold border border-[#dedede] text-gray-500 hover:bg-gray-50 rounded-xl transition-all">Reject</button>
                    <button type="button" onclick="openVerificationModal()" class="bg-[#262626] text-white px-4 py-2 rounded-xl text-xs font-bold hover:bg-black transition-all shadow-sm flex items-center justify-center">Verify Lineage</button>
                    <a id="popupViewLink" href="#" class="bg-green-600 text-white px-4 py-2 rounded-xl text-xs font-bold hover:bg-black transition-all shadow-sm flex items-center justify-center">View Full Details</a>
                </div>
            </div>

        </div>
    </aside>

    <!-- REJECTION MODAL CARD (MIRRORED FROM APPROVAL) -->
    <div id="rejectionModal" class="hidden fixed inset-0 z-[70] items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-xs" onclick="closeRejectionModal()"></div>
        <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-[0_10px_30px_rgba(0,0,0,0.15)] border border-[#ececea] p-6">
            <h3 class="text-base font-bold text-[#262626] mb-2 flex items-center gap-2">
                <span class="inline-block w-2.5 h-2.5 rounded-full bg-red-500"></span>
                Reject Application
            </h3>
            <p class="text-xs text-gray-500 mb-4">Are you sure you want to reject <span id="rejectModalName" class="font-bold text-[#262626]">-</span>? Please provide the reason why this applicant does not belong to the tribe.</p>
            
            <form id="asideRejectForm" method="POST">
                <input type="hidden" name="application_id" id="rejectInputId">
                <input type="hidden" name="action" value="reject">
                <textarea id="rejectModalRemarks" name="rejection_remarks" rows="4" class="w-full bg-gray-50 border border-[#dedede] rounded-xl p-3 text-sm focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition mb-4 resize-none" placeholder="Reason for rejection..." required></textarea>
                
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeRejectionModal()" class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-xl hover:bg-gray-50 text-gray-600 transition-all">Cancel</button>
                    <button type="button" onclick="submitRejectionModal()" class="px-4 py-2 text-xs font-bold bg-red-600 hover:bg-red-700 text-white rounded-xl transition-all shadow-sm">Confirm Reject</button>
                </div>
            </form>
        </div>
    </div>

    <!-- VERIFICATION MODAL CARD -->
    <div id="verificationModal" class="hidden fixed inset-0 z-[70] items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-xs" onclick="closeVerificationModal()"></div>
        <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-[0_10px_30px_rgba(0,0,0,0.15)] border border-[#ececea] p-6">
            <h3 class="text-base font-bold text-[#262626] mb-2 flex items-center gap-2">
                <span class="inline-block w-2.5 h-2.5 rounded-full bg-green-500"></span>
                Confirm Lineage Verification
            </h3>
            <p class="text-xs text-gray-500 mb-6">Are you sure you want to verify the lineage for <span id="verifyModalName" class="font-bold text-[#262626]">-</span>? This will advance the application to the final approval stage with the System Admin.</p>
            
            <form id="asideVerifyForm" method="POST">
                <input type="hidden" name="application_id" id="verifyModalInputId">
                <input type="hidden" name="action" value="verify">
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeVerificationModal()" class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-xl hover:bg-gray-50 text-gray-600 transition-all">Cancel</button>
                    <button type="submit" class="px-4 py-2 text-xs font-bold bg-green-600 hover:bg-green-700 text-white rounded-xl transition-all shadow-sm">Confirm & Verify</button>
                </div>
            </form>
        </div>
    </div>

    <script>

        const card = document.getElementById('floatingMemberCard');
        const rejectModal = document.getElementById('rejectionModal');
        const verificationModal = document.getElementById('verificationModal');
        const floatingMemberBackdrop = document.getElementById('floatingMemberBackdrop');

        const popupInitials = document.getElementById('popupInitials');
        const popupName = document.getElementById('popupName');
        const popupAppId = document.getElementById('popupAppId');
        const displayFullName = document.getElementById('displayFullName');
        const popupTribe = document.getElementById('popupTribe');
        const popupDate = document.getElementById('popupDate');
        const popupDays = document.getElementById('popupDays');
        const popupIpMemberId = document.getElementById('popupIpMemberId');
        const popupDob = document.getElementById('popupDob');
        const popupPob = document.getElementById('popupPob');
        const popupMarital = document.getElementById('popupMarital');
        const popupEducation = document.getElementById('popupEducation');
        const popupMobile = document.getElementById('popupMobile');
        const popupAddress = document.getElementById('popupAddress');

        const rejectModalName = document.getElementById('rejectModalName');
        const rejectModalRemarks = document.getElementById('rejectModalRemarks');
        const verifyModalName = document.getElementById('verifyModalName');

        function getInitials(name) {
            const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
            if (parts.length === 0) return '--';
            const first = parts[0].charAt(0).toUpperCase();
            const second = parts.length > 1 ? parts[parts.length - 1].charAt(0).toUpperCase() : '';
            return first + second;
        }

        window.openVerificationPopup = (row) => {
            const id = row.dataset.applicationId;
            const name = row.dataset.applicantName;
            const tribe = row.dataset.targetTribe;
            const date = row.dataset.applicationDate;
            const days = row.dataset.daysPending;
            const ipMemberId = row.dataset.ipMemberId;
            const dob = row.dataset.dob;
            const pob = row.dataset.pob;
            const marital = row.dataset.marital;
            const education = row.dataset.education;
            const mobile = row.dataset.mobile;
            const address = row.dataset.address;

            // Safe Assignment Logic
            if(popupName) popupName.textContent = name;
            if(popupAppId) popupAppId.textContent = id;
            if(displayFullName) displayFullName.textContent = name;
            if(popupTribe) popupTribe.textContent = tribe;
            if(popupDate) popupDate.textContent = date;
            if(popupDays) popupDays.textContent = days;
            if(popupIpMemberId) popupIpMemberId.textContent = ipMemberId;
            if(popupDob) popupDob.textContent = dob;
            if(popupPob) popupPob.textContent = pob;
            if(popupMarital) popupMarital.textContent = marital;
            if(popupEducation) popupEducation.textContent = education;
            if(popupMobile) popupMobile.textContent = mobile;
            if(popupAddress) popupAddress.textContent = address;
            
            const viewLink = document.getElementById('popupViewLink');
            if(viewLink) viewLink.href = `view_application.php?id=${id}`;
            
            // Forms
            const vId = document.getElementById('verifyModalInputId');
            const rId = document.getElementById('rejectInputId');
            if(vId) vId.value = id;
            if(rId) rId.value = id;

            // Initials Logic
            if(popupInitials) popupInitials.textContent = getInitials(name);

            card.classList.remove('hidden');
            card.classList.add('flex');
            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';

            // Refresh icons inside the popup
            if (window.lucide) lucide.createIcons();
        };

        window.closeMemberCard = () => {
            if(card) {
                card.classList.add('hidden');
                card.classList.remove('flex');
            }
            document.body.style.overflow = '';
            document.documentElement.style.overflow = '';
        };
        if (floatingMemberBackdrop) {
            floatingMemberBackdrop.addEventListener('click', closeMemberCard);
        }

        window.openRejectionModal = () => {
            if (!rejectModal) return;
            const applicantName = document.getElementById('popupName')?.textContent || 'Applicant';
            if (rejectModalName) rejectModalName.textContent = applicantName;
            if (rejectModalRemarks) rejectModalRemarks.value = '';

            rejectModal.classList.remove('hidden');
            rejectModal.classList.add('flex');
        };

        window.closeRejectionModal = () => {
            if (!rejectModal) return;
            rejectModal.classList.add('hidden');
            rejectModal.classList.remove('flex');
        };

        window.openVerificationModal = () => {
            if (!verificationModal) return;
            const applicantName = popupName?.textContent || 'Applicant';
            if (verifyModalName) verifyModalName.textContent = applicantName;

            verificationModal.classList.remove('hidden');
            verificationModal.classList.add('flex');
        };

        window.closeVerificationModal = () => {
            if (!verificationModal) return;
            verificationModal.classList.add('hidden');
            verificationModal.classList.remove('flex');
        };

        window.submitRejectionModal = () => {
            const remarks = rejectModalRemarks ? rejectModalRemarks.value.trim() : '';
            if (remarks === '') {
                alert('Please enter rejection remarks.');
                return;
            }
            document.getElementById('asideRejectForm')?.submit();
        };

        // Ensure clicking buttons inside the row doesn't trigger the row's click twice
        document.querySelectorAll('.row-action').forEach(btn => {
            btn.addEventListener('click', (e) => e.stopPropagation());
        });

        // Initialize Icons
        if (window.lucide) lucide.createIcons();
    </script>
</body>
</html>