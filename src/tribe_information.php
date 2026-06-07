<?php
require_once __DIR__ . '/auth/guards.php';
require_any_role(['admin', 'tribe_leader', 'ip_member']);
#add
require_once __DIR__ . '/dbconfig.php';
$conn = $GLOBALS['conn'] ?? ($conn ?? null);

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die("Database connection not established. Check src/dbconfig.php and MySQL service.");
}
?>

<?php

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
$canEditTribeInfo = in_array($currentRole, ['admin', 'tribe_leader'], true);
$isIdentityEditMode = $canEditTribeInfo && isset($_GET['edit_identity']) && $_GET['edit_identity'] === '1';
$roleLabel = 'IP Member';
if ($currentRole === 'admin') {
    $roleLabel = 'System Admin';
} elseif ($currentRole === 'tribe_leader') {
    $roleLabel = 'Elder';
}


// --- Dynamic Column Detection for ipmembers ---
$ipColumns = [];
$resCols = $conn->query("SHOW COLUMNS FROM ipmembers");
if ($resCols) { while($c = $resCols->fetch_assoc()) $ipColumns[] = $c['Field']; }

$ipPkCol = first_existing_column($ipColumns, ['ip_member_id', 'id']) ?? 'ip_member_id';
$ipNameCol = first_existing_column($ipColumns, ['full_name', 'name', 'member_name']);

// --- Dynamic Column Detection for ip_member_details ---
$detailColumns = [];
$resD = $conn->query("SHOW COLUMNS FROM ip_member_details");
if ($resD) { while($c = $resD->fetch_assoc()) $detailColumns[] = $c['Field']; }

if ($ipNameCol) {
    $ipNameExpr = "i.`{$ipNameCol}`";
} elseif (in_array('first_name', $ipColumns) && in_array('last_name', $ipColumns)) {
    $ipNameExpr = "TRIM(CONCAT_WS(' ', i.first_name, i.middle_name, i.last_name))";
} else {
    $ipNameExpr = "'Unknown Member'";
}

$tribeClanCol = first_existing_column($ipColumns, ['tribe_clan', 'tribe', 'tribe_id']);
$barangayCol = first_existing_column($ipColumns, ['barangay', 'location']) ?? 'barangay';
$regDateCol = first_existing_column($ipColumns, ['registration_date', 'created_at']) ?? $ipPkCol;

// Determine how to count population based on where the tribe reference is stored
if ($tribeClanCol) {
    $popJoin = "LEFT JOIN ipmembers i ON t.tribe_id = i.`{$tribeClanCol}`";
} else {
    // Join via details table if tribe ID isn't in main ipmembers table
    $detailTribeCol = first_existing_column($detailColumns, ['tribe', 'tribe_id', 'tribe_clan']) ?? 'tribe';
    $popJoin = "LEFT JOIN ip_member_details d ON t.tribe_id = d.`{$detailTribeCol}` 
                LEFT JOIN ipmembers i ON d.ip_member_id = i.`{$ipPkCol}`";
}

$ipmembers = [];
$sql = "SELECT {$ipNameExpr} AS member_name, i.{$ipPkCol}, " . ($tribeClanCol ? "i.{$tribeClanCol}" : "NULL") . " AS tribe_clan, i.{$barangayCol}, i.{$regDateCol} FROM ipmembers i";
$ip_result = mysqli_query($conn, $sql);

if ($ip_result instanceof mysqli_result) {
    while ($row = mysqli_fetch_assoc($ip_result)) {
        $ipmembers[] = $row;
    }
}

$sql = "SELECT 
    t.tribe_id,
    t.tribe_name,
    t.language,
    COUNT(i.{$ipPkCol}) AS population,
    t.location
FROM tribes t
{$popJoin}
GROUP BY t.tribe_id";

$result = mysqli_query($conn, $sql);
$tribeRow = ($result instanceof mysqli_result) ? mysqli_fetch_assoc($result) : [];

$traditionsRituals = '';
$artsCrafts = '';
$historicalBackground = '';

$tribeId = (int) ($tribeRow['tribe_id'] ?? 0);
$tribeName = trim((string) ($tribeRow['tribe_name'] ?? ''));
$tribeLanguage = trim((string) ($tribeRow['language'] ?? ''));
$tribePopulation = (int) ($tribeRow['population'] ?? 0);
$tribeLocation = trim((string) ($tribeRow['location'] ?? ''));

// Fetch leadership structure for the specific tribe being viewed
$leader_sql = "SELECT official_name, designation, term FROM leadership_structure WHERE tribe_id = $tribeId";
$leader_result = mysqli_query($conn, $leader_sql);

if ($tribeId > 0) {
    $infoSql = "SELECT traditions_rituals, arts_crafts, historical_background
                FROM tribe_information
                WHERE tribe_id = ?
                LIMIT 1";
    $infoStmt = mysqli_prepare($conn, $infoSql);
    if ($infoStmt) {
        mysqli_stmt_bind_param($infoStmt, 'i', $tribeId);
        mysqli_stmt_execute($infoStmt);
        $infoResult = mysqli_stmt_get_result($infoStmt);
        if ($infoResult) {
            $infoRow = mysqli_fetch_assoc($infoResult);
            if ($infoRow) {
                $traditionsRituals = (string) ($infoRow['traditions_rituals'] ?? '');
                $artsCrafts = (string) ($infoRow['arts_crafts'] ?? '');
                $historicalBackground = (string) ($infoRow['historical_background'] ?? '');
            }
        }
        mysqli_stmt_close($infoStmt);
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['save_tribe_identity'])
    && $tribeId > 0
    && $canEditTribeInfo
) {
    $newTribeName = trim((string) ($_POST['tribe_name'] ?? ''));
    $newTribeLocation = trim((string) ($_POST['tribe_location'] ?? ''));

    if ($newTribeName !== '') {
        $identitySql = "UPDATE tribes SET tribe_name = ?, location = ? WHERE tribe_id = ? LIMIT 1";
        $identityStmt = mysqli_prepare($conn, $identitySql);

        if ($identityStmt) {
            mysqli_stmt_bind_param($identityStmt, 'ssi', $newTribeName, $newTribeLocation, $tribeId);
            mysqli_stmt_execute($identityStmt);
            mysqli_stmt_close($identityStmt);

            $tribeName = $newTribeName;
            $tribeLocation = $newTribeLocation;
        }
    }

    header('Location: tribe_information.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tribe_information']) && $tribeId > 0 && $canEditTribeInfo) {
    $newTraditionsRituals = trim((string) ($_POST['traditions_rituals'] ?? ''));
    $newArtsCrafts = trim((string) ($_POST['arts_crafts'] ?? ''));
    $newHistoricalBackground = trim((string) ($_POST['historical_background'] ?? ''));

    $checkSql = "SELECT tribe_info_id FROM tribe_information WHERE tribe_id = ? LIMIT 1";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    $hasExisting = false;

    if ($checkStmt) {
        mysqli_stmt_bind_param($checkStmt, 'i', $tribeId);
        mysqli_stmt_execute($checkStmt);
        $checkResult = mysqli_stmt_get_result($checkStmt);
        $hasExisting = $checkResult && mysqli_fetch_assoc($checkResult);
        mysqli_stmt_close($checkStmt);
    }

    if ($hasExisting) {
        $updateSql = "UPDATE tribe_information
                      SET traditions_rituals = ?, arts_crafts = ?, historical_background = ?
                      WHERE tribe_id = ?";
        $updateStmt = mysqli_prepare($conn, $updateSql);
        if ($updateStmt) {
            mysqli_stmt_bind_param($updateStmt, 'sssi', $newTraditionsRituals, $newArtsCrafts, $newHistoricalBackground, $tribeId);
            mysqli_stmt_execute($updateStmt);
            mysqli_stmt_close($updateStmt);
        }
    } else {
        $insertSql = "INSERT INTO tribe_information (tribe_id, traditions_rituals, arts_crafts, historical_background)
                      VALUES (?, ?, ?, ?)";
        $insertStmt = mysqli_prepare($conn, $insertSql);
        if ($insertStmt) {
            mysqli_stmt_bind_param($insertStmt, 'isss', $tribeId, $newTraditionsRituals, $newArtsCrafts, $newHistoricalBackground);
            mysqli_stmt_execute($insertStmt);
            mysqli_stmt_close($insertStmt);
        }
    }

    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'traditions_rituals' => $newTraditionsRituals,
            'arts_crafts' => $newArtsCrafts,
            'historical_background' => $newHistoricalBackground
        ]);
        exit;
    }

    header('Location: tribe_information.php');
    exit;
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
    <title>IP Lineage - Tribe Information</title>
    <style>
        body { background-color: #f3f4f1; color: #262626; font-family: 'Plus Jakarta Sans', sans-serif; }
        .bg-sidebar { background-color: #ffffff; border-right: 1px solid #dedede; }
        .bg-card-custom { background-color: #ffffff; border: 1px solid #dedede; }
        .sidebar-item-active { background-color: #262626; color: #ffffff; }
        .text-muted { color: #666666; }
        .border-line { border-bottom: 1px solid #dedede; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-thumb { background: #dedede; border-radius: 10px; }
    </style>
</head>

<body class="min-h-screen">

    <?php $activeNav = 'tribe_information'; include __DIR__ . '/shared/sidebar.php'; ?>

    <div class="ml-64 p-8">
        <div class="w-full">
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

        <div class="flex flex-col md:flex-row items-center justify-between gap-4 mb-8">
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold text-[#262626]">Tribe Information</h1>
            </div>

            <div class="flex items-center gap-3">
                <div class="relative w-64">
                    <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400"></i>
                    <input type="text" placeholder="Search tribe name..." 
                        class="w-full bg-white border border-[#dedede] rounded-xl py-2.5 pl-10 pr-4 focus:outline-none focus:ring-2 focus:ring-[#262626]/5 transition text-xs">
                </div>
                
                <div class="relative">
                    <select class="appearance-none bg-white border border-[#dedede] rounded-xl px-4 py-2.5 pr-10 text-xs font-bold focus:outline-none focus:ring-2 focus:ring-[#262626]/5 transition cursor-pointer">
                        <option><?php echo htmlspecialchars($tribeName); ?></option>
                    </select>
                    <i data-lucide="chevron-down" class="absolute right-3 top-1/2 -translate-y-1/2 w-3 h-3 text-gray-400 pointer-events-none"></i>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            
            <div class="bg-card-custom rounded-2xl overflow-hidden shadow-sm">
                <div class="bg-gray-50/50 border-line py-3 px-6 flex justify-between items-center">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Tribe Identity</span>
                    <button class="text-gray-400 hover:text-[#262626] transition"><i data-lucide="more-horizontal" class="w-4 h-4"></i></button>
                </div>
                <div class="p-8 flex flex-col lg:flex-row justify-between items-start gap-10">
                    <div class="flex-1">
                        <h2 class="text-3xl font-bold text-[#262626] mb-6"><?php echo htmlspecialchars($tribeName); ?></h2>
                        
                        <?php if ($canEditTribeInfo && $isIdentityEditMode): ?>
                            <form method="POST" class="space-y-4">
                                <input type="hidden" name="save_tribe_identity" value="1">
                                <div>
                                    <label for="tribe_name" class="block text-[10px] font-bold text-gray-400 mb-2 uppercase tracking-tight">Tribe Name</label>
                                    <input id="tribe_name" name="tribe_name" type="text" value="<?php echo htmlspecialchars($tribeName); ?>" class="w-full border border-[#dedede] rounded-xl px-4 py-2.5 text-sm text-[#262626] focus:outline-none focus:ring-2 focus:ring-[#262626]/10" required>
                                </div>
                                <div>
                                    <label for="tribe_location" class="block text-[10px] font-bold text-gray-400 mb-2 uppercase tracking-tight">Location</label>
                                    <input id="tribe_location" name="tribe_location" type="text" value="<?php echo htmlspecialchars($tribeLocation); ?>" class="w-full border border-[#dedede] rounded-xl px-4 py-2.5 text-sm text-[#262626] focus:outline-none focus:ring-2 focus:ring-[#262626]/10">
                                </div>

                                <div class="flex gap-3 pt-2">
                                    <button type="submit" class="bg-[#262626] text-white px-5 py-2.5 rounded-xl text-xs font-bold hover:bg-[#404040] transition shadow-md flex items-center gap-2">
                                        <i data-lucide="save" class="w-3.5 h-3.5"></i> Save Changes
                                    </button>
                                    <a href="tribe_information.php" class="border border-[#dedede] bg-white text-[#262626] px-5 py-2.5 rounded-xl text-xs font-bold hover:bg-gray-50 transition flex items-center gap-2">
                                        <i data-lucide="x" class="w-3.5 h-3.5"></i> Cancel
                                    </a>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="space-y-3">
                                <div class="flex text-sm"><span class="w-40 font-bold text-gray-400 uppercase text-[10px] tracking-tight">Tribe Name</span> <span class="font-medium text-[#262626]"><?php echo htmlspecialchars($tribeName); ?></span></div>
                                <div class="flex text-sm"><span class="w-40 font-bold text-gray-400 uppercase text-[10px] tracking-tight">Language</span> <span class="font-medium text-[#262626]"><?php echo htmlspecialchars($tribeLanguage); ?></span></div>
                                <div class="flex text-sm"><span class="w-40 font-bold text-gray-400 uppercase text-[10px] tracking-tight">Population</span> <span class="font-medium text-[#262626]"><?php echo htmlspecialchars((string) $tribePopulation); ?></span></div>
                                <div class="flex text-sm"><span class="w-40 font-bold text-gray-400 uppercase text-[10px] tracking-tight">Location</span> <span class="font-medium text-[#262626]"><?php echo htmlspecialchars($tribeLocation); ?></span></div>
                            </div>
                        <?php endif; ?>

                        <div class="mt-8 flex gap-3">
                            <?php if ($canEditTribeInfo && !$isIdentityEditMode): ?>
                            <a href="?edit_identity=1" class="bg-[#262626] text-white px-5 py-2.5 rounded-xl text-xs font-bold hover:bg-[#404040] transition shadow-md flex items-center gap-2">
                                <i data-lucide="edit-3" class="w-3.5 h-3.5"></i> Edit Profile
                            </a>
                            <?php endif; ?>
                            <button class="border border-[#dedede] bg-white text-[#262626] px-5 py-2.5 rounded-xl text-xs font-bold hover:bg-gray-50 transition flex items-center gap-2">
                                <i data-lucide="file-text" class="w-3.5 h-3.5"></i> Export PDF
                            </button>
                        </div>
                    </div>
                    
                    <div class="w-full lg:w-96 h-52 rounded-2xl overflow-hidden border border-[#dedede] grayscale hover:grayscale-0 transition-all duration-500">
                        <img src="https://images.unsplash.com/photo-1500382017468-9049fed747ef?auto=format&fit=crop&q=80&w=1000" alt="Terrain" class="w-full h-full object-cover">
                    </div>
                </div>
            </div>

            <div class="bg-card-custom rounded-2xl overflow-hidden shadow-sm">
                <div class="p-6 border-line flex justify-between items-center">
                    <h3 class="text-xs font-bold uppercase tracking-widest text-gray-400">Leadership Structure</h3>
                    <?php if ($canEditTribeInfo): ?>
                    <button class="text-[11px] text-[#262626] font-bold bg-gray-100 hover:bg-gray-200 px-4 py-2 rounded-lg transition flex items-center gap-2">
                        <i data-lucide="plus" class="w-3.5 h-3.5"></i> ADD OFFICIAL
                    </button>
                    <?php endif; ?>
                </div>
                <table class="w-full text-left">
                    <thead class="bg-gray-50/50 border-line">
                        <tr class="text-[10px] uppercase text-gray-400 bg-gray-50/50">
                            <th class="px-8 py-4 font-bold">Official Name</th>
                            <th class="px-8 py-4 font-bold">Designation</th>
                            <th class="px-8 py-4 font-bold">Term</th>
                            <?php if ($canEditTribeInfo): ?>
                            <th class="px-8 py-4 font-bold text-right">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#dedede] text-sm text-[#262626]">
                        <?php
                        if ($leader_result && mysqli_num_rows($leader_result) > 0) {
                            while ($row = mysqli_fetch_assoc($leader_result)) {
                        ?>
                        <tr class="hover:bg-gray-100 transition-colors duration-200">
                            <td class="px-8 py-4 font-bold"><?php echo htmlspecialchars($row['official_name'] ?? 'N/A'); ?></td>
                            <td class="px-8 py-4 text-xs"><?php echo htmlspecialchars($row['designation']); ?></td>
                            <td class="px-8 py-4 text-xs text-gray-500"><?php echo htmlspecialchars($row['term']); ?></td>
                            <?php if ($canEditTribeInfo): ?>
                            <td class="px-8 py-4 text-right">
                                <button class="p-2 hover:bg-white rounded-lg transition text-gray-400 hover:text-[#262626]"><i data-lucide="edit-2" class="w-4 h-4"></i></button>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php
                            }
                        } else {
                        ?>
                                <tr>
                                    <td colspan="<?php echo $canEditTribeInfo ? '4' : '3'; ?>" class="px-8 py-4 text-center text-gray-400">
                                        No officials found.
                                    </td>
                                </tr>
                        <?php
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <form id="tribeInfoForm" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-card-custom rounded-2xl p-8 shadow-sm relative group">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Cultural Heritage</h3>
                        <?php if ($canEditTribeInfo): ?>
                        <button type="button" class="edit-info-trigger opacity-0 group-hover:opacity-100 p-2 bg-gray-50 rounded-lg text-[#262626] transition flex items-center gap-1 text-[10px] font-bold">
                            <i data-lucide="edit-3" class="w-3 h-3"></i> EDIT
                        </button>
                        <?php endif; ?>
                    </div>
                    <div id="infoViewCultural" class="space-y-6">
                        <div>
                            <p class="text-[10px] font-bold text-gray-400 mb-2 uppercase tracking-tight">Traditions & Rituals</p>
                            <p id="traditionsViewText" class="text-sm text-[#262626] leading-relaxed font-medium"><?php echo nl2br(htmlspecialchars($traditionsRituals !== '' ? $traditionsRituals : 'No traditions and rituals data available.')); ?></p>
                        </div>
                        <div class="pt-4 border-t border-dashed border-[#dedede]">
                            <p class="text-[10px] font-bold text-gray-400 mb-2 uppercase tracking-tight">Arts & Craft</p>
                            <p id="artsViewText" class="text-sm text-[#262626] leading-relaxed font-medium"><?php echo nl2br(htmlspecialchars($artsCrafts !== '' ? $artsCrafts : 'No arts and crafts data available.')); ?></p>
                        </div>
                    </div>
                    <div id="infoEditCultural" class="space-y-6 hidden">
                        <div>
                            <p class="text-[10px] font-bold text-gray-400 mb-2 uppercase tracking-tight">Traditions & Rituals</p>
                            <textarea name="traditions_rituals" rows="4" class="w-full border border-[#dedede] rounded-xl p-3 text-sm text-[#262626] focus:outline-none focus:ring-2 focus:ring-[#262626]/10"><?php echo htmlspecialchars($traditionsRituals); ?></textarea>
                        </div>
                        <div class="pt-4 border-t border-dashed border-[#dedede]">
                            <p class="text-[10px] font-bold text-gray-400 mb-2 uppercase tracking-tight">Arts & Craft</p>
                            <textarea name="arts_crafts" rows="4" class="w-full border border-[#dedede] rounded-xl p-3 text-sm text-[#262626] focus:outline-none focus:ring-2 focus:ring-[#262626]/10"><?php echo htmlspecialchars($artsCrafts); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="bg-card-custom rounded-2xl p-8 shadow-sm relative group">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Historical Background</h3>
                        <?php if ($canEditTribeInfo): ?>
                        <div class="flex gap-2">
                             <button class="opacity-0 group-hover:opacity-100 p-2 bg-gray-50 rounded-lg text-[#262626] transition flex items-center gap-1 text-[10px] font-bold">
                                <i data-lucide="eye" class="w-3 h-3"></i> VIEW ALL
                            </button>
                            <button type="button" class="edit-info-trigger opacity-0 group-hover:opacity-100 p-2 bg-gray-50 rounded-lg text-[#262626] transition flex items-center gap-1 text-[10px] font-bold">
                                <i data-lucide="edit-3" class="w-3 h-3"></i> EDIT
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div id="infoViewHistorical">
                        <p id="historicalViewText" class="text-sm text-[#262626] leading-relaxed mb-6 font-medium"><?php echo nl2br(htmlspecialchars($historicalBackground !== '' ? $historicalBackground : 'No historical background data available.')); ?></p>
                    </div>
                    <div id="infoEditHistorical" class="hidden mb-6">
                            <textarea name="historical_background" rows="8" class="w-full border border-[#dedede] rounded-xl p-3 text-sm text-[#262626] focus:outline-none focus:ring-2 focus:ring-[#262626]/10"><?php echo htmlspecialchars($historicalBackground); ?></textarea>
                            <div class="mt-3 flex gap-2">
                                <button type="submit" name="save_tribe_information" value="1" class="bg-[#262626] text-white px-4 py-2 rounded-lg text-[11px] font-bold">Save</button>
                                <button type="button" id="cancelInfoEdit" class="border border-[#dedede] bg-white text-[#262626] px-4 py-2 rounded-lg text-[11px] font-bold">Cancel</button>
                            </div>
                    </div>
                    <div class="flex items-center gap-2 text-[10px] font-bold text-gray-400">
                        <i data-lucide="clock" class="w-3 h-3"></i> LAST UPDATED: FEB 23, 2026
                    </div>
                </div>
            </form>

        </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
        const canEditTribeInfo = <?php echo $canEditTribeInfo ? 'true' : 'false'; ?>;

        const form = document.getElementById('tribeInfoForm');
        const editButtons = document.querySelectorAll('.edit-info-trigger');
        const cancelButton = document.getElementById('cancelInfoEdit');
        const viewBlocks = [
            document.getElementById('infoViewCultural'),
            document.getElementById('infoViewHistorical')
        ];
        const editBlocks = [
            document.getElementById('infoEditCultural'),
            document.getElementById('infoEditHistorical')
        ];

        function setEditMode(isEdit) {
            viewBlocks.forEach((el) => el && el.classList.toggle('hidden', isEdit));
            editBlocks.forEach((el) => el && el.classList.toggle('hidden', !isEdit));
        }

        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function textToHtml(str, fallbackText) {
            const trimmed = String(str || '').trim();
            const value = trimmed !== '' ? str : fallbackText;
            return escapeHtml(value).replace(/\n/g, '<br>');
        }

        if (canEditTribeInfo) {
            editButtons.forEach((btn) => {
                btn.addEventListener('click', () => setEditMode(true));
            });
        }

        if (canEditTribeInfo && cancelButton) {
            cancelButton.addEventListener('click', () => setEditMode(false));
        }

        if (canEditTribeInfo && form) {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();

                const formData = new FormData(form);
                formData.set('save_tribe_information', '1');

                const response = await fetch('tribe_information.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });

                const data = await response.json();
                if (!data || !data.success) {
                    return;
                }

                const traditionsView = document.getElementById('traditionsViewText');
                const artsView = document.getElementById('artsViewText');
                const historicalView = document.getElementById('historicalViewText');

                if (traditionsView) {
                    traditionsView.innerHTML = textToHtml(data.traditions_rituals, 'No traditions and rituals data available.');
                }
                if (artsView) {
                    artsView.innerHTML = textToHtml(data.arts_crafts, 'No arts and crafts data available.');
                }
                if (historicalView) {
                    historicalView.innerHTML = textToHtml(data.historical_background, 'No historical background data available.');
                }

                setEditMode(false);
            });
        }
    </script>
</body>
</html>