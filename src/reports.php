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

$currentRole = normalize_role((string) ($_SESSION['role'] ?? ''));
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

// --- DYNAMIC COLUMN DETECTION ---
$ipColumns = [];
$resC = $conn->query("SHOW COLUMNS FROM ipmembers");
if ($resC) { while($c = $resC->fetch_assoc()) $ipColumns[] = $c['Field']; }

$detailColumns = [];
$resD = $conn->query("SHOW COLUMNS FROM ip_member_details");
if ($resD) { while($c = $resD->fetch_assoc()) $detailColumns[] = $c['Field']; }

$userColumns = [];
$resU = $conn->query("SHOW COLUMNS FROM users");
if ($resU) { while($c = $resU->fetch_assoc()) $userColumns[] = $c['Field']; }

$ipPkCol = first_existing_column($ipColumns, ['ip_member_id', 'id']) ?? 'ip_member_id';
$userPkCol = first_existing_column($userColumns, ['userid', 'user_id', 'id']) ?? 'userid';
$ipNameCol = first_existing_column($ipColumns, ['full_name', 'member_name', 'name']) ?? 'id';
$ipLastNameCol = first_existing_column($ipColumns, ['last_name', 'surname']);

$sexExpr = ($c = first_existing_column($ipColumns, ['sex', 'gender'])) ? "i.`$c`" : (($c = first_existing_column($detailColumns, ['sex', 'gender', 'gender_sex'])) ? "d.`$c`" : (($c = first_existing_column($userColumns, ['sex', 'gender'])) ? "u.`$c`" : "NULL"));
$birthExpr = ($c = first_existing_column($ipColumns, ['birthdate', 'date_of_birth'])) ? "i.`$c`" : (($c = first_existing_column($detailColumns, ['birthdate', 'date_of_birth'])) ? "d.`$c`" : "NULL");
$barangayExpr = ($c = first_existing_column($ipColumns, ['barangay', 'location'])) ? "i.`$c`" : (($c = first_existing_column($detailColumns, ['barangay', 'location'])) ? "d.`$c`" : "NULL");
$tribeExpr = ($c = first_existing_column($ipColumns, ['tribe_clan', 'tribe', 'tribe_id'])) ? "i.`$c`" : (($c = first_existing_column($detailColumns, ['tribe', 'tribe_clan', 'tribe_id'])) ? "d.`$c`" : "NULL");
$regDateExpr = ($c = first_existing_column($ipColumns, ['registration_date', 'created_at', 'date_registered'])) ? "i.`$c`" : "i.`$ipPkCol`";

// Safe Age Calculation Expression: Use reg date if birthdate is null
$ageCalcExpr = ($birthExpr === 'NULL') ? $regDateExpr : $birthExpr;

$fromClause = "FROM ipmembers i 
               LEFT JOIN ip_member_details d ON i.`{$ipPkCol}` = d.ip_member_id
               LEFT JOIN users u ON i.user_id = u.{$userPkCol}";

// --- GET MULTI-FILTER PARAMETERS ---
$selectedBarangay = isset($_GET['barangay']) ? trim($_GET['barangay']) : '';
$selectedTribe = isset($_GET['tribe']) ? trim($_GET['tribe']) : '';
$selectedRegStatus = isset($_GET['reg_status']) ? trim($_GET['reg_status']) : '';
$selectedGender = isset($_GET['gender']) ? trim($_GET['gender']) : '';
$selectedAge = isset($_GET['age_group']) ? trim($_GET['age_group']) : '';
$selectedEducation = isset($_GET['education']) ? trim($_GET['education']) : '';
$startDate = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$endDate = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

// --- BUILD DYNAMIC WHERE CLAUSE FOR IPMEMBERS ---
$filterConditions = [];

if ($selectedBarangay !== '') {
    $filterConditions[] = "{$barangayExpr} = '" . mysqli_real_escape_string($conn, $selectedBarangay) . "'";
}
if ($selectedTribe !== '') {
    $filterConditions[] = "{$tribeExpr} = " . (int)$selectedTribe;
}
if ($selectedRegStatus === 'registered') {
    $filterConditions[] = "i.user_id IS NOT NULL";
} elseif ($selectedRegStatus === 'unregistered') {
    $filterConditions[] = "i.user_id IS NULL";
}
if ($selectedGender !== '') {
    $filterConditions[] = "{$sexExpr} = '" . mysqli_real_escape_string($conn, $selectedGender) . "'";
}
if ($startDate !== '' && $endDate !== '') {
    $filterConditions[] = "{$regDateExpr} BETWEEN '" . mysqli_real_escape_string($conn, $startDate) . "' AND '" . mysqli_real_escape_string($conn, $endDate) . "'";
}

if ($selectedAge !== '') {
    switch ($selectedAge) {
        case '0-5':   $filterConditions[] = "TIMESTAMPDIFF(YEAR, {$ageCalcExpr}, CURDATE()) BETWEEN 0 AND 5"; break;
        case '6-12':  $filterConditions[] = "TIMESTAMPDIFF(YEAR, {$ageCalcExpr}, CURDATE()) BETWEEN 6 AND 12"; break;
        case '13-19': $filterConditions[] = "TIMESTAMPDIFF(YEAR, {$ageCalcExpr}, CURDATE()) BETWEEN 13 AND 19"; break;
        case '20-59': $filterConditions[] = "TIMESTAMPDIFF(YEAR, {$ageCalcExpr}, CURDATE()) BETWEEN 20 AND 59"; break;
        case '60+':   $filterConditions[] = "TIMESTAMPDIFF(YEAR, {$ageCalcExpr}, CURDATE()) >= 60"; break;
    }
}

$whereClause = !empty($filterConditions) ? " WHERE " . implode(" AND ", $filterConditions) : "";

// --- EXECUTE SQL ANALYTICS ---

// 1. Total Filtered IP Members (Accepted Users)
$activeCountQuery = "SELECT COUNT(*) as total $fromClause " . $whereClause;
$activeCountResult = mysqli_query($conn, $activeCountQuery);
$totalActiveMembers = $activeCountResult ? (int)(mysqli_fetch_assoc($activeCountResult)['total'] ?? 0) : 0;

// 2. Status Tracking & Pending Profiles Aggregation
$pendingElder = 0;
$pendingAdmin = 0;

$pendingElderQuery = "SELECT COUNT(*) as total FROM applications WHERE status = 'pending_elder'";
$pendingElderResult = mysqli_query($conn, $pendingElderQuery);
if ($pendingElderResult) { $pendingElder = (int)(mysqli_fetch_assoc($pendingElderResult)['total'] ?? 0); }
$pendingAdminQuery = "SELECT COUNT(*) as total FROM applications WHERE status = 'pending_admin'";
$pendingAdminResult = mysqli_query($conn, $pendingAdminQuery);
if ($pendingAdminResult) { $pendingAdmin = (int)(mysqli_fetch_assoc($pendingAdminResult)['total'] ?? 0); }
$totalPendingProfiles = $pendingElder + $pendingAdmin;

// 3. Rejected Users Dynamic Analytical Fetch
$totalRejectedUsers = 0;
$rejectedQuery = "SELECT COUNT(*) as total FROM applications WHERE status = 'rejected'";
$rejectedResult = mysqli_query($conn, $rejectedQuery);
if ($rejectedResult) {
    $totalRejectedUsers = (int)(mysqli_fetch_assoc($rejectedResult)['total'] ?? 0);
}

// 4. Sex Breakdown Distribution Matrix
$sexData = ['Male' => 0, 'Female' => 0]; 
$sexQuery = "SELECT {$sexExpr} as sex, COUNT(*) as count $fromClause " . $whereClause . " GROUP BY sex";
$sexResult = mysqli_query($conn, $sexQuery);
if ($sexResult) {
    while($row = mysqli_fetch_assoc($sexResult)) {
        $s = strtolower(trim((string)$row['sex']));
        if ($s === 'male' || $s === 'm') {
            $sexData['Male'] += (int)$row['count'];
        } elseif ($s === 'female' || $s === 'f') {
            $sexData['Female'] += (int)$row['count'];
        }
    }
}

// 5. Dynamic Age Demographics Metrics Map
$ageGroups = ['0-5' => 0, '6-12' => 0, '13-19' => 0, '20-59' => 0, '60+' => 0];
$ageQuery = "SELECT 
    SUM(CASE WHEN TIMESTAMPDIFF(YEAR, {$ageCalcExpr}, CURDATE()) BETWEEN 0 AND 5 THEN 1 ELSE 0 END) as g1,
    SUM(CASE WHEN TIMESTAMPDIFF(YEAR, {$ageCalcExpr}, CURDATE()) BETWEEN 6 AND 12 THEN 1 ELSE 0 END) as g2,
    SUM(CASE WHEN TIMESTAMPDIFF(YEAR, {$ageCalcExpr}, CURDATE()) BETWEEN 13 AND 19 THEN 1 ELSE 0 END) as g3,
    SUM(CASE WHEN TIMESTAMPDIFF(YEAR, {$ageCalcExpr}, CURDATE()) BETWEEN 20 AND 59 THEN 1 ELSE 0 END) as g4,
    SUM(CASE WHEN TIMESTAMPDIFF(YEAR, {$ageCalcExpr}, CURDATE()) >= 60 THEN 1 ELSE 0 END) as g5
    $fromClause " . $whereClause;
$ageResult = mysqli_query($conn, $ageQuery);
if ($ageResult && $row = mysqli_fetch_assoc($ageResult)) {
    $ageGroups = [
        '0-5' => (int)($row['g1'] ?? 0),
        '6-12' => (int)($row['g2'] ?? 0),
        '13-19' => (int)($row['g3'] ?? 0),
        '20-59' => (int)($row['g4'] ?? 0),
        '60+' => (int)($row['g5'] ?? 0)
    ];
}

// 6. Clan Lineage Distribution
$clanList = [];
$clanNameSource = $ipLastNameCol ? "i.`$ipLastNameCol`" : ($ipNameCol ? "i.`$ipNameCol`" : "''");

// Use suffix matching to handle compound surnames like 'Dela Cruz' from full_name if necessary
if ($ipLastNameCol) {
    $matchCondition = "i.`{$ipLastNameCol}` = REPLACE(f.family_name, ' Family', '')";
} else {
    $matchCondition = "i.`{$ipNameCol}` LIKE CONCAT('%', REPLACE(f.family_name, ' Family', ''))";
}

$clanJoinConditions = [$matchCondition];
if ($selectedBarangay !== '') {
    $clanJoinConditions[] = "{$barangayExpr} = '" . mysqli_real_escape_string($conn, $selectedBarangay) . "'";
}
if ($selectedTribe !== '') {
    $clanJoinConditions[] = "{$tribeExpr} = " . (int)$selectedTribe;
}
if ($selectedRegStatus === 'registered') {
    $clanJoinConditions[] = "i.user_id IS NOT NULL";
} elseif ($selectedRegStatus === 'unregistered') {
    $clanJoinConditions[] = "i.user_id IS NULL";
}
if ($selectedGender !== '') {
    $clanJoinConditions[] = "{$sexExpr} = '" . mysqli_real_escape_string($conn, $selectedGender) . "'";
}
if ($startDate !== '' && $endDate !== '') {
    $clanJoinConditions[] = "{$regDateExpr} BETWEEN '" . mysqli_real_escape_string($conn, $startDate) . "' AND '" . mysqli_real_escape_string($conn, $endDate) . "'";
}
if ($selectedAge !== '') {
    switch ($selectedAge) {
        case '0-5':   $clanJoinConditions[] = "TIMESTAMPDIFF(YEAR, {$ageCalcExpr}, CURDATE()) BETWEEN 0 AND 5"; break;
        case '6-12':  $clanJoinConditions[] = "TIMESTAMPDIFF(YEAR, {$ageCalcExpr}, CURDATE()) BETWEEN 6 AND 12"; break;
        case '13-19': $clanJoinConditions[] = "TIMESTAMPDIFF(YEAR, {$ageCalcExpr}, CURDATE()) BETWEEN 13 AND 19"; break;
        case '20-59': $clanJoinConditions[] = "TIMESTAMPDIFF(YEAR, {$ageCalcExpr}, CURDATE()) BETWEEN 20 AND 59"; break;
        case '60+':   $clanJoinConditions[] = "TIMESTAMPDIFF(YEAR, {$ageCalcExpr}, CURDATE()) >= 60"; break;
    }
}

$clanQuery = "SELECT f.family_name, COUNT(i.ip_member_id) as count 
              FROM families f 
              LEFT JOIN ipmembers i ON " . implode(" AND ", $clanJoinConditions) . "
              LEFT JOIN ip_member_details d ON i.`{$ipPkCol}` = d.ip_member_id
              LEFT JOIN users u ON i.user_id = u.{$userPkCol}
              GROUP BY f.family_id LIMIT 6";
$clanResult = mysqli_query($conn, $clanQuery);
if ($clanResult && mysqli_num_rows($clanResult) > 0) {
    while($row = mysqli_fetch_assoc($clanResult)) { $clanList[] = $row; }
} else {
    // Dynamic Fallback: If families table is empty, group by detected last name/word
    if ($ipLastNameCol) {
        $topSurQuery = "SELECT i.`{$ipLastNameCol}` as family_name, COUNT(*) as count $fromClause $whereClause GROUP BY family_name ORDER BY count DESC LIMIT 6";
    } else {
        $topSurQuery = "SELECT SUBSTRING_INDEX(i.`{$ipNameCol}`, ' ', -1) as family_name, COUNT(*) as count $fromClause $whereClause GROUP BY family_name ORDER BY count DESC LIMIT 6";
    }
    $surRes = mysqli_query($conn, $topSurQuery);
    if ($surRes) {
        while($sRow = mysqli_fetch_assoc($surRes)) {
            $n = trim((string)$sRow['family_name']);
            if ($n !== '') {
                $clanList[] = ['family_name' => $n . ' Family', 'count' => (int)$sRow['count']];
            }
        }
    }
}

// 7. Educational Attainment Metrics Matrix
$eduData = ['None' => 0, 'Elementary' => 0, 'High School' => 0, 'College' => 0, 'Postgraduate' => 0];
$eduQuery = "SELECT d.educational_attainment as edu, COUNT(*) as count 
             $fromClause 
             $whereClause 
             GROUP BY edu";
$eduRes = mysqli_query($conn, $eduQuery);
if ($eduRes) {
    while ($eRow = mysqli_fetch_assoc($eduRes)) {
        $e = strtolower(trim((string)$eRow['edu']));
        if ($e === '') continue;
        
        if (strpos($e, 'elementary') !== false) $eduData['Elementary'] += (int)$eRow['count'];
        elseif (strpos($e, 'high') !== false) $eduData['High School'] += (int)$eRow['count'];
        elseif (strpos($e, 'college') !== false) $eduData['College'] += (int)$eRow['count'];
        elseif (strpos($e, 'post') !== false || strpos($e, 'grad') !== false) $eduData['Postgraduate'] += (int)$eRow['count'];
        elseif ($e !== 'none') $eduData['None'] += (int)$eRow['count'];
    }
}

// 8. Timeline Registration Tracking Based on Registration Dates
$timelineLabels = [];
$timelineValues = [];
for ($i = 5; $i >= 0; $i--) {
    $timelineLabels[] = date('M Y', strtotime("-{$i} months"));
    $timelineValues[] = 0;
}
$monthMap = [];
for ($i = 5; $i >= 0; $i--) {
    $monthKey = date('Y-m', strtotime("-{$i} months"));
    $monthMap[$monthKey] = count($monthMap);
}

$regTrendSql = "SELECT DATE_FORMAT({$regDateExpr}, '%Y-%m') AS month_key, COUNT(*) AS total 
                $fromClause 
                " . ($whereClause !== '' ? $whereClause . " AND " : " WHERE ") . " {$regDateExpr} >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) 
                GROUP BY month_key";
$regTrendResult = mysqli_query($conn, $regTrendSql);
if ($regTrendResult) {
    while ($tRow = mysqli_fetch_assoc($regTrendResult)) {
        $mKey = (string)$tRow['month_key'];
        if (isset($monthMap[$mKey])) { $timelineValues[$monthMap[$mKey]] = (int)$tRow['total']; }
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
    <title>IP Lineage - Reports & Analytics</title>
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
<body class="min-h-screen text-neutral-800 antialiased selection:bg-neutral-900 selection:text-white">

    <?php $activeNav = 'reports'; include __DIR__ . '/shared/sidebar.php'; ?>

    <div class="ml-64 p-6 md:p-8 min-h-screen">
        
        <!-- Header Framework -->
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

        <!-- Subheader & Filter Control Hub -->
        <section class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-[#262626]">Reports and Analytics</h1>
            </div>
            
            <div class="bg-white border border-neutral-200 p-1.5 rounded-xl flex items-center gap-2 shadow-sm flex-wrap ml-auto lg:ml-0">
                <form method="GET" action="" class="flex items-center gap-2 flex-wrap">
                    <select name="barangay" onchange="this.form.submit()" class="bg-white border border-neutral-200 rounded-lg px-2.5 py-1.5 text-xs font-medium text-neutral-600 outline-none focus:border-neutral-400 transition">
                        <option value="">All Barangays</option>
                        <option value="Villafont" <?php echo $selectedBarangay === 'Villafont' ? 'selected' : ''; ?>>Villafont</option>
                    </select>
                    
                    <select name="tribe" onchange="this.form.submit()" class="bg-white border border-neutral-200 rounded-lg px-2.5 py-1.5 text-xs font-medium text-neutral-600 outline-none focus:border-neutral-400 transition">
                        <option value="">All Tribes</option>
                        <option value="1" <?php echo $selectedTribe === '1' ? 'selected' : ''; ?>>Ati Tribe</option>
                    </select>

                    <select name="reg_status" onchange="this.form.submit()" class="bg-white border border-neutral-200 rounded-lg px-2.5 py-1.5 text-xs font-medium text-neutral-600 outline-none focus:border-neutral-400 transition">
                        <option value="">All Status</option>
                        <option value="registered" <?php echo $selectedRegStatus === 'registered' ? 'selected' : ''; ?>>Registered</option>
                        <option value="unregistered" <?php echo $selectedRegStatus === 'unregistered' ? 'selected' : ''; ?>>Not Registered</option>
                    </select>

                    <select name="gender" onchange="this.form.submit()" class="bg-white border border-neutral-200 rounded-lg px-2.5 py-1.5 text-xs font-medium text-neutral-600 outline-none focus:border-neutral-400 transition">
                        <option value="">All Genders</option>
                        <option value="Male" <?php echo $selectedGender === 'Male' ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo $selectedGender === 'Female' ? 'selected' : ''; ?>>Female</option>
                    </select>

                    <select name="age_group" onchange="this.form.submit()" class="bg-white border border-neutral-200 rounded-lg px-2.5 py-1.5 text-xs font-medium text-neutral-600 outline-none focus:border-neutral-400 transition">
                        <option value="">All Ages</option>
                        <option value="0-5" <?php echo $selectedAge === '0-5' ? 'selected' : ''; ?>>0-5 Yrs</option>
                        <option value="6-12" <?php echo $selectedAge === '6-12' ? 'selected' : ''; ?>>6-12 Yrs</option>
                        <option value="13-19" <?php echo $selectedAge === '13-19' ? 'selected' : ''; ?>>13-19 Yrs</option>
                        <option value="20-59" <?php echo $selectedAge === '20-59' ? 'selected' : ''; ?>>20-59 Yrs</option>
                        <option value="60+" <?php echo $selectedAge === '60+' ? 'selected' : ''; ?>>60+ Yrs</option>
                    </select>

                    <select name="education" onchange="this.form.submit()" class="bg-white border border-neutral-200 rounded-lg px-2.5 py-1.5 text-xs font-medium text-neutral-600 outline-none focus:border-neutral-400 transition">
                        <option value="">All Education</option>
                        <option value="None" <?php echo $selectedEducation === 'None' ? 'selected' : ''; ?>>None</option>
                        <option value="Elementary" <?php echo $selectedEducation === 'Elementary' ? 'selected' : ''; ?>>Elementary</option>
                        <option value="High School" <?php echo $selectedEducation === 'High School' ? 'selected' : ''; ?>>High School</option>
                        <option value="College" <?php echo $selectedEducation === 'College' ? 'selected' : ''; ?>>College</option>
                        <option value="Postgraduate" <?php echo $selectedEducation === 'Postgraduate' ? 'selected' : ''; ?>>Postgraduate</option>
                    </select>
                </form>

                <a href="reports.php" class="text-neutral-400 hover:text-neutral-600 p-1.5 transition flex items-center justify-center bg-white border border-neutral-200 rounded-lg h-8 w-8" title="Clear Filters">
                    <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
                </a>

                <button onclick="window.print()" class="bg-neutral-900 text-white text-xs font-bold px-3.5 py-1.5 h-8 rounded-lg transition hover:bg-neutral-800 shadow-sm flex items-center gap-1.5">
                    <i data-lucide="download" class="w-3.5 h-3.5"></i> Export
                </button>
            </div>
        </section>

        <!-- Dynamic Grid Workspaces -->
        <main class="grid grid-cols-1 md:grid-cols-12 gap-6">
            
            <!-- Summary Information Blocks -->
            <div class="bg-white border border-neutral-200 p-5 rounded-2xl shadow-[0_2px_8px_-3px_rgba(0,0,0,0.05)] md:col-span-7 flex flex-col justify-between">
                <div>
                    <div class="flex justify-between items-center border-b border-neutral-100 pb-3 mb-4">
                        <h3 class="font-bold text-[11px] uppercase tracking-wider text-neutral-400">Demographic Information Metrics</h3>
                        <button class="text-neutral-300 hover:text-neutral-500 transition">
                            <i data-lucide="info" class="w-4 h-4"></i>
                        </button>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="bg-neutral-50/50 p-4 rounded-xl border border-neutral-200/60">
                            <span class="text-[10px] text-neutral-400 font-bold uppercase tracking-wider block">Total IP Members</span>
                            <b class="text-2xl font-bold text-neutral-900 mt-1 block"><?php echo number_format($totalActiveMembers); ?></b>
                        </div>
                        
                        <div class="bg-neutral-50/50 p-4 rounded-xl border border-neutral-200/60">
                            <span class="text-[10px] text-neutral-400 font-bold uppercase tracking-wider block">Pending Profiles</span>
                            <b class="text-2xl font-bold text-amber-600 mt-1 block"><?php echo number_format($totalPendingProfiles); ?></b>
                        </div>
                        
                        <div class="bg-neutral-50/50 p-4 rounded-xl border border-neutral-200/60">
                            <span class="text-[10px] text-neutral-400 font-bold uppercase tracking-wider block">Total Male Records</span>
                            <b class="text-2xl font-bold text-neutral-800 mt-1 block"><?php echo number_format($sexData['Male']); ?></b>
                        </div>
                        
                        <div class="bg-neutral-50/50 p-4 rounded-xl border border-neutral-200/60">
                            <span class="text-[10px] text-neutral-400 font-bold uppercase tracking-wider block">Total Female Records</span>
                            <b class="text-2xl font-bold text-neutral-800 mt-1 block"><?php echo number_format($sexData['Female']); ?></b>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Lifecycle Tracking Pie Matrix -->
            <div class="bg-white border border-neutral-200 p-5 rounded-2xl shadow-[0_2px_8px_-3px_rgba(0,0,0,0.05)] md:col-span-5 flex flex-col justify-between">
                <div>
                    <div class="flex justify-between items-center border-b border-neutral-100 pb-3 mb-3">
                        <h3 class="font-bold text-[11px] uppercase tracking-wider text-neutral-400">User Classification Breakdown</h3>
                        <i data-lucide="pie-chart" class="w-4 h-4 text-neutral-400"></i>
                    </div>
                    
                    <div class="relative flex items-center justify-center h-44 my-2">
                        <canvas id="statusPieChart"></canvas>
                    </div>
                </div>

                <div class="grid grid-cols-3 text-center gap-1 mt-2 pt-2 border-t border-neutral-100">
                    <div>
                        <span class="text-[10px] font-bold text-neutral-800 block">Accepted</span>
                        <span class="text-xs font-semibold text-neutral-500"><?php echo $totalActiveMembers; ?></span>
                    </div>
                    <div>
                        <span class="text-[10px] font-bold text-amber-600 block">Pending</span>
                        <span class="text-xs font-semibold text-neutral-500"><?php echo $totalPendingProfiles; ?></span>
                    </div>
                    <div>
                        <span class="text-[10px] font-bold text-neutral-400 block">Rejected</span>
                        <span class="text-xs font-semibold text-neutral-500"><?php echo $totalRejectedUsers; ?></span>
                    </div>
                </div>
            </div>

            <!-- LOWER MATRIX ANALYTICS MODULES -->
            <div class="bg-white border border-neutral-200 p-5 rounded-2xl shadow-[0_2px_8px_-3px_rgba(0,0,0,0.05)] md:col-span-4">
                <h3 class="font-bold text-[11px] uppercase tracking-wider text-neutral-400 mb-4">Members by Family / Clan</h3>
                <div class="space-y-3 max-h-[190px] overflow-y-auto pr-2">
                    <?php foreach ($clanList as $clan): ?>
                        <div class="flex justify-between items-center text-xs border-b border-neutral-100 pb-2">
                            <span class="font-medium text-neutral-700 flex items-center gap-2">
                                <i data-lucide="git-merge" class="w-3.5 h-3.5 text-neutral-400"></i>
                                <?php echo htmlspecialchars($clan['family_name']); ?>
                            </span>
                            <b class="bg-neutral-50 border border-neutral-200/60 px-2.5 py-0.5 rounded-full text-neutral-600 text-[11px] font-bold"><?php echo $clan['count']; ?> members </b>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bg-white border border-neutral-200 p-5 rounded-2xl shadow-[0_2px_8px_-3px_rgba(0,0,0,0.05)] md:col-span-4">
                <h3 class="font-bold text-[11px] uppercase tracking-wider text-neutral-400 mb-3">Members by Age Distribution</h3>
                <div class="h-44">
                    <canvas id="ageChart"></canvas>
                </div>
            </div>

            <div class="bg-white border border-neutral-200 p-5 rounded-2xl shadow-[0_2px_8px_-3px_rgba(0,0,0,0.05)] md:col-span-4">
                <h3 class="font-bold text-[11px] uppercase tracking-wider text-neutral-400 mb-3">Registration History Timeline</h3>
                <div class="h-44">
                    <canvas id="timelineChart"></canvas>
                </div>
            </div>

            <!-- EDUCATIONAL ATTAINMENT MATRIX BLOCK -->
            <div class="bg-white border border-neutral-200 p-5 rounded-2xl shadow-[0_2px_8px_-3px_rgba(0,0,0,0.05)] md:col-span-12">
                <h3 class="font-bold text-[11px] uppercase tracking-wider text-neutral-400 mb-3">Members by Educational Attainment Matrix</h3>
                <div class="h-48">
                    <canvas id="educationChart"></canvas>
                </div>
            </div>

        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        lucide.createIcons();

        // 1. Status Tracking Pie Chart
        const statusCtx = document.getElementById('statusPieChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'pie',
            data: {
                labels: ['Accepted Members', 'Pending Requests', 'Rejected Profiles'],
                datasets: [{
                    data: [
                        <?php echo $totalActiveMembers; ?>, 
                        <?php echo $totalPendingProfiles; ?>, 
                        <?php echo $totalRejectedUsers; ?>
                    ],
                    backgroundColor: ['#1e1e1e', '#d97706', '#a3a3a3'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 10,
                            font: { size: 10, family: 'Plus Jakarta Sans', weight: '500' },
                            padding: 12
                        }
                    }
                }
            }
        });

        // 2. Members by Age Group Chart
        const ageCtx = document.getElementById('ageChart').getContext('2d');
        new Chart(ageCtx, {
            type: 'bar',
            data: {
                labels: ['0–5 Yrs', '6–12 Yrs', '13–19 Yrs', '20–59 Yrs', '60+ Yrs'],
                datasets: [{
                    data: <?php echo json_encode(array_values($ageGroups)); ?>,
                    backgroundColor: '#1e1e1e',
                    borderRadius: 6,
                    barThickness: 16
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { grid: { color: '#f1f1ef', drawTicks: false }, border: { display: false }, ticks: { font: { size: 9, family: 'Plus Jakarta Sans' } } },
                    x: { grid: { display: false }, ticks: { font: { size: 9, family: 'Plus Jakarta Sans' } } }
                }
            }
        });

        // 3. Registration Trend Logs Chart
        const timeCtx = document.getElementById('timelineChart').getContext('2d');
        new Chart(timeCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($timelineLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode($timelineValues); ?>,
                    borderColor: '#1e1e1e',
                    borderWidth: 2,
                    pointBackgroundColor: '#1e1e1e',
                    backgroundColor: 'rgba(30, 30, 30, 0.03)',
                    fill: true,
                    tension: 0.35
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { grid: { color: '#f1f1ef', drawTicks: false }, border: { display: false }, ticks: { font: { size: 9, family: 'Plus Jakarta Sans' }, stepSize: 1 } },
                    x: { grid: { display: false }, ticks: { font: { size: 9, family: 'Plus Jakarta Sans' } } }
                }
            }
        });

        // 4. Educational Attainment Level Chart
        const eduCtx = document.getElementById('educationChart').getContext('2d');
        new Chart(eduCtx, {
            type: 'bar',
            data: {
                labels: ['None', 'Elementary', 'High School', 'College', 'Postgraduate'],
                datasets: [{
                    data: <?php echo json_encode(array_values($eduData)); ?>,
                    // Multi-tiered neutral tones matching registration tracker component card architecture
                    backgroundColor: [
                        '#e5e5e3', // None
                        '#d4d4d2', // Elementary
                        '#a3a3a3', // High School
                        '#525252', // College
                        '#1e1e1e'  // Postgraduate
                    ],
                    hoverBackgroundColor: [
                        '#dcdcdc',
                        '#c8c8c6',
                        '#8a8a8a',
                        '#404040',
                        '#0a0a0a'
                    ],
                    borderRadius: 6,
                    barThickness: 18
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: '#f1f1ef' }, border: { display: false }, ticks: { font: { size: 9, family: 'Plus Jakarta Sans' }, stepSize: 1 } },
                    y: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 10, family: 'Plus Jakarta Sans', weight: '500' } } }
                }
            }
        });
    </script>
</body>
</html>