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

$applicationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($applicationId <= 0) {
    die('Invalid application ID.');
}

$application = null;
$applicantDetails = null;
$applicantDocuments = [];
$errorMessage = '';

// Fetch all column names from 'users' to handle schema variations
$userColumns = [];
$resCols = $conn->query("SHOW COLUMNS FROM users");
if ($resCols) {
    while ($c = $resCols->fetch_assoc()) { $userColumns[] = $c['Field']; }
}

// Resolve Primary Key and Name Expression for users table
$userPkCol = in_array('userid', $userColumns) ? 'userid' : (in_array('user_id', $userColumns) ? 'user_id' : 'id');
$userNameExprElder = "''";
$userNameExprAdmin = "''";
if (in_array('full_name', $userColumns)) {
    $userNameExprElder = "u_elder.full_name"; $userNameExprAdmin = "u_admin.full_name";
} elseif (in_array('name', $userColumns)) {
    $userNameExprElder = "u_elder.name"; $userNameExprAdmin = "u_admin.name";
} elseif (in_array('first_name', $userColumns) && in_array('last_name', $userColumns)) {
    $userNameExprElder = "CONCAT(u_elder.first_name, ' ', u_elder.last_name)";
    $userNameExprAdmin = "CONCAT(u_admin.first_name, ' ', u_admin.last_name)";
}

// Fetch application details
$sql = "SELECT
            a.application_id,
            a.ip_member_id,
            a.status,
            a.application_date,
            a.rejection_remarks,
            $userNameExprElder AS verified_by_elder_name,
            $userNameExprAdmin AS approved_by_admin_name,
            i.full_name AS applicant_full_name,
            i.user_id AS applicant_user_id
        FROM applications a
        JOIN ipmembers i ON a.ip_member_id = i.ip_member_id
        LEFT JOIN users u_elder ON a.verified_by_elder = u_elder.$userPkCol
        LEFT JOIN users u_admin ON a.approved_by_admin = u_admin.$userPkCol
        WHERE a.application_id = ? LIMIT 1";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('i', $applicationId);
    $stmt->execute();
    $result = $stmt->get_result();
    $application = $result->fetch_assoc();
    $stmt->close();
} else {
    $errorMessage = "Failed to fetch application: " . $conn->error;
}

if (!$application && !empty($errorMessage)) {
    // If there was a SQL error, display it for debugging
    die("Database Error: " . htmlspecialchars($errorMessage));
}
if (!$application) {
    die('Application not found or you do not have permission to view it.');
}

// Fetch applicant's personal details
$ipMemberId = (int)$application['ip_member_id'];
$sqlDetails = "SELECT
                    d.date_of_birth,
                    d.place_of_birth,
                    COALESCE(t.tribe_name, d.tribe) AS tribe,
                    d.mobile_number,
                    d.barangay,
                    d.specific_current_address,
                    d.marital_status,
                    d.educational_attainment
                FROM ip_member_details d
                LEFT JOIN tribes t ON d.tribe = t.tribe_id
                WHERE d.ip_member_id = ? LIMIT 1";

$stmtDetails = $conn->prepare($sqlDetails);
if ($stmtDetails) {
    $stmtDetails->bind_param('i', $ipMemberId);
    $stmtDetails->execute();
    $resultDetails = $stmtDetails->get_result();
    $applicantDetails = $resultDetails->fetch_assoc();
    $stmtDetails->close();
} else {
    $errorMessage .= " Failed to fetch applicant details: " . $conn->error;
}

// Fetch applicant's documents
$sqlDocuments = "SELECT document_type, file_name FROM ip_member_documents WHERE ip_member_id = ?";
$stmtDocuments = $conn->prepare($sqlDocuments);
if ($stmtDocuments) {
    $stmtDocuments->bind_param('i', $ipMemberId);
    $stmtDocuments->execute();
    $resultDocuments = $stmtDocuments->get_result();
    while ($row = $resultDocuments->fetch_assoc()) {
        $applicantDocuments[] = $row;
    }
    $stmtDocuments->close();
} else {
    $errorMessage .= " Failed to fetch applicant documents: " . $conn->error;
}

// Determine current user's role and permissions
$currentRole = normalize_role((string)($_SESSION['role'] ?? ''));
$isElder = $currentRole === 'tribe_leader' || $currentRole === 'elder';
$isAdmin = $currentRole === 'admin';

// Handle actions (Verify/Approve/Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $userId = (int)$_SESSION['user_id'];

    try {
        $conn->begin_transaction();

        if ($action === 'verify' && $isElder && $application['status'] === 'pending_elder') {
            $updateSql = "UPDATE applications SET status = 'pending_admin', verified_by_elder = ? WHERE application_id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param('ii', $userId, $applicationId);
            $updateStmt->execute();
            $updateStmt->close();
            $_SESSION['success_message'] = "Application verified. It is now pending admin approval.";
        } elseif ($action === 'approve' && $isAdmin && $application['status'] === 'pending_admin') {
            $updateSql = "UPDATE applications SET status = 'approved', approved_by_admin = ? WHERE application_id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param('ii', $userId, $applicationId);
            $updateStmt->execute();
            $updateStmt->close();

            // Update user's role to IP MEMBER
            $updateUserRoleSql = "UPDATE users SET role = 'IP MEMBER' WHERE $userPkCol = (SELECT user_id FROM ipmembers WHERE ip_member_id = ?) AND role != 'IP MEMBER'";
            $updateUserRoleStmt = $conn->prepare($updateUserRoleSql);
            $updateUserRoleStmt->bind_param('i', $ipMemberId);
            $updateUserRoleStmt->execute();
            $updateUserRoleStmt->close();

            $_SESSION['success_message'] = "Application approved. User has been granted IP Member status.";
        } elseif ($action === 'reject' && ($isElder || $isAdmin) && ($application['status'] === 'pending_elder' || $application['status'] === 'pending_admin')) {
            $rejectionRemarks = trim((string)($_POST['rejection_remarks'] ?? ''));
            if ($rejectionRemarks === '') {
                throw new Exception("Rejection remarks are required.");
            }
            $updateSql = "UPDATE applications SET status = 'rejected', rejection_remarks = ? WHERE application_id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param('si', $rejectionRemarks, $applicationId);
            $updateStmt->execute();
            $updateStmt->close();
            $_SESSION['error_message'] = "Application rejected.";
        } else {
            throw new Exception("Unauthorized action or invalid application status.");
        }

        $conn->commit();
        $redirectPage = $isElder ? 'pending_verification.php' : 'pending_approval.php';
        header("Location: {$redirectPage}");
        exit;
    } catch (Throwable $e) {
        $conn->rollback();
        $errorMessage = "Action failed: " . $e->getMessage();
    }
}

// Display messages from session
$successMessage = $_SESSION['success_message'] ?? '';
$errorMessage = $_SESSION['error_message'] ?? $errorMessage;
unset($_SESSION['success_message'], $_SESSION['error_message']);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Application - <?php echo htmlspecialchars($application['applicant_full_name'] ?? 'N/A'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        body { background-color: #f3f4f1; font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <header class="bg-white border-b border-[#dedede] p-4 flex justify-between items-center z-10">
        <div class="flex items-center gap-4">
            <a href="<?php echo $isElder ? 'pending_verification.php' : 'pending_approval.php'; ?>" class="p-2 hover:bg-gray-100 rounded-lg transition">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-600"></i>
            </a>
            <div>
                <h1 class="text-lg font-bold text-[#262626]">Application Details</h1>
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

        <div class="bg-white rounded-2xl shadow-sm border border-[#dedede] p-6 mb-6">
            <h2 class="text-xl font-bold text-[#262626] mb-4">Applicant: <?php echo htmlspecialchars($application['applicant_full_name'] ?? 'N/A'); ?></h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div><span class="font-semibold">Application ID:</span> <?php echo htmlspecialchars($application['application_id'] ?? 'N/A'); ?></div>
                <div><span class="font-semibold">IP Member ID:</span> <?php echo htmlspecialchars($application['ip_member_id'] ?? 'N/A'); ?></div>
                <div><span class="font-semibold">Status:</span> <span class="px-2 py-0.5 rounded-full text-xs font-bold <?php
                    if ($application['status'] === 'pending_elder') echo 'bg-yellow-100 text-yellow-700';
                    else if ($application['status'] === 'pending_admin') echo 'bg-blue-100 text-blue-700';
                    else if ($application['status'] === 'approved') echo 'bg-green-100 text-green-700';
                    else if ($application['status'] === 'rejected') echo 'bg-red-100 text-red-700';
                    else echo 'bg-gray-100 text-gray-700';
                ?>"><?php echo htmlspecialchars(str_replace('_', ' ', $application['status'] ?? 'N/A')); ?></span></div>
                <div><span class="font-semibold">Application Date:</span> <?php echo htmlspecialchars(date('M d, Y', strtotime($application['application_date'] ?? ''))); ?></div>
                <?php if ($application['verified_by_elder_name']): ?>
                    <div><span class="font-semibold">Verified by Elder:</span> <?php echo htmlspecialchars($application['verified_by_elder_name']); ?></div>
                <?php endif; ?>
                <?php if ($application['approved_by_admin_name']): ?>
                    <div><span class="font-semibold">Approved by Admin:</span> <?php echo htmlspecialchars($application['approved_by_admin_name']); ?></div>
                <?php endif; ?>
                <?php if ($application['rejection_remarks']): ?>
                    <div class="md:col-span-2"><span class="font-semibold text-red-600">Rejection Remarks:</span> <?php echo htmlspecialchars($application['rejection_remarks']); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-[#dedede] p-6 mb-6">
            <h3 class="text-lg font-bold text-[#262626] mb-4">Personal Details</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div><span class="font-semibold">Date of Birth:</span> <?php echo htmlspecialchars($applicantDetails['date_of_birth'] ?? 'N/A'); ?></div>
                <div><span class="font-semibold">Place of Birth:</span> <?php echo htmlspecialchars($applicantDetails['place_of_birth'] ?? 'N/A'); ?></div>
                <div><span class="font-semibold">Tribe:</span> <?php echo htmlspecialchars($applicantDetails['tribe'] ?? 'N/A'); ?></div>
                <div><span class="font-semibold">Mobile Number:</span> <?php echo htmlspecialchars($applicantDetails['mobile_number'] ?? 'N/A'); ?></div>
                <div><span class="font-semibold">Barangay:</span> <?php echo htmlspecialchars($applicantDetails['barangay'] ?? 'N/A'); ?></div>
                <div><span class="font-semibold">Address:</span> <?php echo htmlspecialchars($applicantDetails['specific_current_address'] ?? 'N/A'); ?></div>
                <div><span class="font-semibold">Marital Status:</span> <?php echo htmlspecialchars($applicantDetails['marital_status'] ?? 'N/A'); ?></div>
                <div><span class="font-semibold">Educational Attainment:</span> <?php echo htmlspecialchars($applicantDetails['educational_attainment'] ?? 'N/A'); ?></div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-[#dedede] p-6 mb-6">
            <h3 class="text-lg font-bold text-[#262626] mb-4">Uploaded Documents</h3>
            <?php if (!empty($applicantDocuments)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($applicantDocuments as $doc): ?>
                        <div class="flex items-center justify-between border border-gray-200 rounded-lg p-3">
                            <div>
                                <p class="font-semibold text-sm"><?php echo htmlspecialchars($doc['document_type']); ?></p>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($doc['file_name']); ?></p>
                            </div>
                            <a href="uploads/<?php echo htmlspecialchars($application['applicant_user_id']); ?>/<?php echo htmlspecialchars($doc['file_name']); ?>" target="_blank" class="text-blue-600 hover:underline text-sm">View</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-sm text-gray-500">No documents uploaded.</p>
            <?php endif; ?>
        </div>

        <div class="flex justify-end gap-4 mt-6">
            <?php if ($application['status'] === 'pending_elder' && $isElder): ?>
                <button type="button" onclick="openVerificationModal()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-bold text-sm shadow-sm transition-all">Verify Application</button>
                <button onclick="openRejectionModal()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 font-bold text-sm">Reject Application</button>
            <?php elseif ($application['status'] === 'pending_admin' && $isAdmin): ?>
                <button type="button" onclick="openApprovalModal()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-bold text-sm shadow-sm transition-all">Approve Application</button>
                <button onclick="openRejectionModal()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 font-bold text-sm">Reject Application</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Rejection Modal -->
    <div id="rejectionModal" class="hidden fixed inset-0 z-[70] items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-xs" onclick="closeRejectionModal()"></div>
        <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-[0_10px_30px_rgba(0,0,0,0.15)] border border-[#ececea] p-6">
            <h3 class="text-base font-bold text-[#262626] mb-2 flex items-center gap-2">
                <span class="inline-block w-2.5 h-2.5 rounded-full bg-red-500"></span>
                Reject Applicant
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
                <input type="hidden" name="action" value="verify">
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeVerificationModal()" class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-xl hover:bg-gray-50 text-gray-600 transition-all">Cancel</button>
                    <button type="submit" class="px-4 py-2 text-xs font-bold bg-green-600 hover:bg-green-700 text-white rounded-xl transition-all shadow-sm">Confirm & Verify</button>
                </div>
            </form>
        </div>
    </div>

    <!-- APPROVAL MODAL CARD -->
    <div id="approvalModal" class="hidden fixed inset-0 z-[70] items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-xs" onclick="closeApprovalModal()"></div>
        <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-[0_10px_30px_rgba(0,0,0,0.15)] border border-[#ececea] p-6">
            <h3 class="text-base font-bold text-[#262626] mb-2 flex items-center gap-2">
                <span class="inline-block w-2.5 h-2.5 rounded-full bg-blue-600"></span>
                Confirm Final Approval
            </h3>
            <p class="text-xs text-gray-500 mb-6">Are you sure you want to approve the application for <span id="approveModalName" class="font-bold text-[#262626]">-</span>? This will finalize their membership and grant them the IP Member role.</p>
            
            <form id="asideApproveForm" method="POST">
                <input type="hidden" name="action" value="approve">
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeApprovalModal()" class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-xl hover:bg-gray-50 text-gray-600 transition-all">Cancel</button>
                    <button type="submit" class="px-4 py-2 text-xs font-bold bg-green-600 hover:bg-green-700 text-white rounded-xl transition-all shadow-sm">Confirm & Approve</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();

        function openRejectionModal() {
            const name = "<?php echo addslashes($application['applicant_full_name'] ?? 'Applicant'); ?>";
            const nameSpan = document.getElementById('rejectModalName');
            if (nameSpan) nameSpan.textContent = name;
            document.getElementById('rejectionModal').classList.remove('hidden');
            document.getElementById('rejectionModal').classList.add('flex');
        }

        function closeRejectionModal() {
            document.getElementById('rejectionModal').classList.add('hidden');
            document.getElementById('rejectionModal').classList.remove('flex');
        }

        function openVerificationModal() {
            const name = "<?php echo addslashes($application['applicant_full_name'] ?? 'Applicant'); ?>";
            const nameSpan = document.getElementById('verifyModalName');
            if (nameSpan) nameSpan.textContent = name;
            document.getElementById('verificationModal').classList.remove('hidden');
            document.getElementById('verificationModal').classList.add('flex');
        }

        function closeVerificationModal() {
            document.getElementById('verificationModal').classList.add('hidden');
            document.getElementById('verificationModal').classList.remove('flex');
        }

        function openApprovalModal() {
            const name = "<?php echo addslashes($application['applicant_full_name'] ?? 'Applicant'); ?>";
            const nameSpan = document.getElementById('approveModalName');
            if (nameSpan) nameSpan.textContent = name;
            document.getElementById('approvalModal').classList.remove('hidden');
            document.getElementById('approvalModal').classList.add('flex');
        }

        function closeApprovalModal() {
            document.getElementById('approvalModal').classList.add('hidden');
            document.getElementById('approvalModal').classList.remove('flex');
        }

        function submitRejectionModal() {
            const remarks = document.getElementById('rejectModalRemarks').value.trim();
            if (remarks === '') { alert('Please provide a reason for rejection.'); return; }
            document.getElementById('asideRejectForm').submit();
        }
    </script>
</body>
</html>