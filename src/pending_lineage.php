<?php
require_once __DIR__ . '/auth/guards.php';
require_once __DIR__ . '/auth/auth_helpers.php';
require_any_role(['admin', 'tribe_leader']);
require_once __DIR__ . '/dbconfig.php';
require_once __DIR__ . '/backend/family_lineage_service.php';

$conn = $GLOBALS['conn'] ?? ($conn ?? null);

// --- Dynamic Column Detection ---
$userColumns = [];
$resU = $conn->query("SHOW COLUMNS FROM users");
if ($resU) { while($c = $resU->fetch_assoc()) $userColumns[] = $c['Field']; }

$userPkCol = first_existing_column($userColumns, ['userid', 'user_id', 'id']) ?? 'userid';
$uName = first_existing_column($userColumns, ['full_name', 'name', 'display_name']);
$uFirst = first_existing_column($userColumns, ['first_name', 'firstname']);
$uLast = first_existing_column($userColumns, ['last_name', 'lastname']);
$uUser = first_existing_column($userColumns, ['username']);
$uMail = first_existing_column($userColumns, ['email']);

$ipColumns = [];
$resI = $conn->query("SHOW COLUMNS FROM ipmembers");
if ($resI) { while($c = $resI->fetch_assoc()) $ipColumns[] = $c['Field']; }
$ipPkCol = first_existing_column($ipColumns, ['ip_member_id', 'id']) ?? 'ip_member_id';
$ipUserCol = first_existing_column($ipColumns, ['user_id', 'userid']) ?? 'user_id';

// --- Handle Post Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['request_id'])) {
    $reqId = (int)$_POST['request_id'];
    $action = $_POST['action'];

    if ($action === 'approve') {
        try {
            $conn->begin_transaction();
            $res = $conn->query("SELECT * FROM lineage_requests WHERE request_id = $reqId LIMIT 1");
            $req = ($res instanceof mysqli_result) ? $res->fetch_assoc() : null;

            if ($req && $req['status'] === 'pending') {
                // Since ghost was already created during submission, we just link it officially now
                // We find the relationship type based on the proposed_sex
                $relType = ($req['proposed_sex'] === 'M') ? 'father' : 'mother';
                link_person_relation($conn, (int)$req['child_id'], (int)$req['ghost_ip_id'], $relType);
                
                $conn->query("UPDATE lineage_requests SET status = 'approved' WHERE request_id = $reqId");
            }
            $conn->commit();
            $_SESSION['success_message'] = "Lineage update approved and synced to tree.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
    } elseif ($action === 'reject') {
        $remarks = trim((string)$_POST['remarks']);
        
        // RECURSIVE AUTO-CANCEL LOGIC
        $cancelList = [$reqId];
        $toCheck = [$reqId];
        
        while (!empty($toCheck)) {
            $current = array_shift($toCheck);
            $res = $conn->query("SELECT request_id FROM lineage_requests WHERE depends_on_request_id = $current");
            while ($row = $res->fetch_assoc()) {
                $cancelList[] = $row['request_id'];
                $toCheck[] = $row['request_id'];
            }
        }
        
        $ids = implode(',', $cancelList);
        $conn->query("UPDATE lineage_requests SET status = 'rejected', rejection_remarks = '" . $conn->real_escape_string($remarks) . "' WHERE request_id = $reqId");
        $conn->query("UPDATE lineage_requests SET status = 'cancelled', rejection_remarks = 'Ancestor path rejected' WHERE request_id IN ($ids) AND request_id != $reqId");

        $_SESSION['error_message'] = "Lineage update rejected.";
    }
    header("Location: pending_lineage.php");
    exit;
}

// Fetch pending requests
$requests = [];
$nameExpr = "COALESCE(" . 
    ($uName ? "NULLIF(u.`$uName`, ''), " : "") . 
    ($uUser ? "NULLIF(u.`$uUser`, ''), " : "") . 
    ($uMail ? "NULLIF(u.`$uMail`, ''), " : "") . 
    "'User #' + CAST(u.{$userPkCol} AS CHAR))";

$nameCandidates = [];
if ($uName) $nameCandidates[] = "NULLIF(u.`$uName`, '')";
if ($uFirst && $uLast) $nameCandidates[] = "NULLIF(TRIM(CONCAT_WS(' ', u.`$uFirst`, u.`$uLast`)), '')";
elseif ($uFirst) $nameCandidates[] = "NULLIF(u.`$uFirst`, '')";
elseif ($uLast) $nameCandidates[] = "NULLIF(u.`$uLast`, '')";
if ($uUser) $nameCandidates[] = "NULLIF(u.`$uUser`, '')";
if ($uMail) $nameCandidates[] = "NULLIF(u.`$uMail`, '')";

$nameExpr = "COALESCE(" . implode(", ", $nameCandidates) . ", CONCAT('User #', u.{$userPkCol}))";

$sql = "SELECT {$nameExpr} as requester_name, 
               (COUNT(DISTINCT lr.ghost_ip_id) + (SELECT COUNT(*) FROM applications a2 JOIN ipmembers i2 ON a2.ip_member_id = i2.ip_member_id WHERE i2.user_id = u.{$userPkCol} AND a2.status = 'pending_elder')) as total_added, 
               MAX(lr.created_at) as last_activity,
               MIN(lr.child_id) as target_member_id
        FROM lineage_requests lr
        JOIN users u ON lr.requested_by = u.{$userPkCol}
        WHERE lr.status = 'pending'
        GROUP BY lr.requested_by
        ORDER BY last_activity DESC";

$res = $conn->query($sql);
if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) $requests[] = $row;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>IP Lineage - Lineage Verification</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { background-color: #f3f4f1; font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="min-h-screen flex flex-col">
    <header class="bg-white border-b border-[#dedede] p-4 flex justify-between items-center z-10">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="p-2 hover:bg-gray-100 rounded-lg transition">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-600"></i>
            </a>
            <div>
                <h1 class="text-lg font-bold text-[#262626]">Pending Lineage Verification</h1>
            </div>
        </div>

    </header>

    <div class="p-4 md:p-10">
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-sm border border-[#dedede] overflow-hidden">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-[10px] uppercase text-gray-400 bg-gray-50/50 border-b border-[#dedede]">
                        <th class="px-6 py-4 font-bold">Requester Name</th>
                        <th class="px-6 py-4 font-bold text-center">Total Nodes Added</th>
                        <th class="px-6 py-4 font-bold">Last Activity</th>
                        <th class="px-6 py-4 font-bold text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="text-sm divide-y divide-[#dedede]">
                    <?php foreach ($requests as $r): ?>
                    <tr class="hover:bg-gray-50/50 transition cursor-pointer" onclick="window.location.href='family_lineage.php?member_id=<?php echo $r['target_member_id']; ?>'">
                        <td class="px-6 py-4 font-bold text-[#262626]"><?php echo htmlspecialchars($r['requester_name']); ?></td>
                        <td class="px-6 py-4 text-center">
                            <span class="bg-emerald-50 text-emerald-700 px-2.5 py-1 rounded-lg text-xs font-bold"><?php echo $r['total_added']; ?> PENDING NODES</span>
                        </td>
                        <td class="px-6 py-4 text-gray-500 text-xs"><?php echo date('M d, Y h:i A', strtotime($r['last_activity'])); ?></td>
                        <td class="px-6 py-4 text-right">
                            <button class="bg-[#262626] text-white px-4 py-2 rounded-lg text-[10px] font-bold uppercase hover:bg-black transition">Review Tree</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($requests)): ?>
                        <tr><td colspan="4" class="px-6 py-12 text-center text-gray-400 text-sm italic">No pending lineage updates to verify.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Rejection Modal -->
    <div id="RejectModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeRejectModal()"></div>
        <div class="relative z-10 w-full max-w-sm bg-white rounded-2xl p-8 shadow-2xl border border-gray-100">
            <h3 class="text-lg font-bold text-[#262626] mb-2">Reject Update</h3>
            <p class="text-xs text-gray-500 mb-6 leading-relaxed">Please provide a reason for rejecting the lineage update for <span id="RejectTarget" class="font-bold text-neutral-900"></span>.</p>
            <form method="POST">
                <input type="hidden" name="request_id" id="RejectId">
                <input type="hidden" name="action" value="reject">
                <textarea name="remarks" required class="w-full bg-neutral-50 border border-neutral-200 rounded-xl p-3 text-xs focus:outline-none focus:border-neutral-400 mb-6" rows="4" placeholder="Conflicting records, incorrect spelling, etc."></textarea>
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeRejectModal()" class="text-xs font-bold text-gray-500">Cancel</button>
                    <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded-xl text-xs font-bold shadow-lg shadow-red-200">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();
        function openRejectModal(id, name) {
            document.getElementById('RejectId').value = id;
            document.getElementById('RejectTarget').textContent = name;
            document.getElementById('RejectModal').classList.remove('hidden');
        }
        function closeRejectModal() {
            document.getElementById('RejectModal').classList.add('hidden');
        }
    </script>
</body>
</html>