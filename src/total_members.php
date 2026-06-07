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
$ipmembers = [];
$errorMessage = '';

$searchQuery = isset($_GET['query']) ? trim((string) $_GET['query']) : '';
$selectedTribe = isset($_GET['tribe']) ? trim((string) $_GET['tribe']) : '';
$selectedBarangay = isset($_GET['barangay']) ? trim((string) $_GET['barangay']) : '';

$perPage = 5;
$currentPage = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($currentPage < 1) {
    $currentPage = 1;
}
$totalRecords = 0;
$totalPages = 1;

function first_existing_column(array $columns, array $candidates): ?string {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function get_initials(string $name): string {
    $cleanName = trim($name);
    if ($cleanName === '') {
        return 'NA';
    }

    $parts = preg_split('/\s+/', $cleanName);
    $first = strtoupper(substr((string) ($parts[0] ?? ''), 0, 1));
    $second = strtoupper(substr((string) ($parts[1] ?? ''), 0, 1));

    $initials = $first . $second;
    return $initials !== '' ? $initials : 'NA';
}

function get_initial_badge_class(string $sex): string {
    $sexValue = strtolower(trim($sex));

    if ($sexValue === 'male' || $sexValue === 'm') {
        return 'bg-[#18181b] text-[#e4e4e7] border-[#3f3f46]';
    }

    if ($sexValue === 'female' || $sexValue === 'f') {
        return 'bg-[#e4e4e7] text-[#18181b] border-[#a1a1aa]';
    }

    return 'bg-gray-100 text-[#262626] border-[#dedede]';
}

$columns = [];
$columnResult = $conn->query("SHOW COLUMNS FROM ipmembers");
if ($columnResult instanceof mysqli_result) {
    while ($columnRow = $columnResult->fetch_assoc()) {
        $columns[] = $columnRow['Field'];
    }
}

// Also check joined tables as some information might be in ip_member_details or applications
// Also check joined tables as some information might be in ip_member_details or applications
$detailColumns = [];
$resDetails = $conn->query("SHOW COLUMNS FROM ip_member_details");
if ($resDetails) { while($c = $resDetails->fetch_assoc()) $detailColumns[] = $c['Field']; }

$appColumns = [];
$resApp = $conn->query("SHOW COLUMNS FROM applications");
if ($resApp instanceof mysqli_result) { while($c = $resApp->fetch_assoc()) $appColumns[] = $c['Field']; }

$appStatusColumn = first_existing_column($appColumns, ['approval_status', 'status']) ?? 'status';

$memberIdColumn = first_existing_column($columns, ['member_id', 'ip_member_id']);
$displayIdCol = first_existing_column($columns, ['display_id', 'member_id']);
$ipMemberIdColumn = first_existing_column($columns, ['ip_member_id', 'id']);

$tribeColumn = first_existing_column($columns, ['tribe_clan', 'tribe']);
$tribeSource = (in_array($tribeColumn, $columns, true)) ? 'i' : 'd';
if ($tribeColumn === null) {
    $tribeColumn = first_existing_column($detailColumns, ['tribe', 'tribe_id', 'tribe_clan']);
    $tribeSource = 'd';
}

$barangayColumn = first_existing_column($columns, ['barangay']);
$barangaySource = (in_array($barangayColumn, $columns, true)) ? 'i' : 'd';
if ($barangayColumn === null) {
    $barangayColumn = first_existing_column($detailColumns, ['barangay']);
    $barangaySource = 'd';
}

$registrationColumn = first_existing_column($columns, ['registration_date', 'created_at']);
$regSource = (in_array($registrationColumn, $columns, true)) ? 'i' : 'a';
if ($registrationColumn === null) {
    $registrationColumn = first_existing_column($appColumns, ['application_date', 'created_at', 'registration_date']) ?? 'application_date';
    $regSource = 'a';
}

$firstNameColumn = first_existing_column($columns, ['first_name']);
$middleNameColumn = first_existing_column($columns, ['middle_name']);
$lastNameColumn = first_existing_column($columns, ['last_name']);

$birthdateColumn = first_existing_column($columns, ['birthdate', 'date_of_birth']);
$birthSource = (in_array($birthdateColumn, $columns, true)) ? 'i' : 'd';
if ($birthdateColumn === null) {
    $birthdateColumn = first_existing_column($detailColumns, ['date_of_birth', 'birthdate']);
    $birthSource = 'd';
}

$placeOfBirthColumn = first_existing_column($columns, ['place_of_birth', 'birth_place']);
$pobSource = (in_array($placeOfBirthColumn, $columns, true)) ? 'i' : 'd';
if ($placeOfBirthColumn === null) {
    $placeOfBirthColumn = first_existing_column($detailColumns, ['place_of_birth', 'birth_place']);
    $pobSource = 'd';
}

$currentAddressColumn = first_existing_column($columns, ['current_address', 'address', 'specific_current_address']);
$addressSource = (in_array($currentAddressColumn, $columns, true)) ? 'i' : 'd';
if ($currentAddressColumn === null) {
    $currentAddressColumn = first_existing_column($detailColumns, ['specific_current_address', 'current_address', 'address']);
    $addressSource = 'd';
}

$contactInformationColumn = first_existing_column($columns, ['contact_information', 'contact_number', 'contact_no', 'phone_number', 'mobile_number']);
$contactSource = (in_array($contactInformationColumn, $columns, true)) ? 'i' : 'd';
if ($contactInformationColumn === null) {
    $contactInformationColumn = first_existing_column($detailColumns, ['mobile_number', 'contact_information', 'phone_number']);
    $contactSource = 'd';
}

$nameExpression = "'N/A'";
if (in_array('member_name', $columns, true)) {
    $nameExpression = 'i.member_name';
} elseif (in_array('full_name', $columns, true)) {
    $nameExpression = 'i.full_name';
} elseif (in_array('first_name', $columns, true) || in_array('last_name', $columns, true)) {
    $firstNameExpr = in_array('first_name', $columns, true) ? 'i.first_name' : "''";
    $middleNameExpr = in_array('middle_name', $columns, true) ? 'i.middle_name' : "''";
    $lastNameExpr = in_array('last_name', $columns, true) ? 'i.last_name' : "''";
    $nameExpression = "TRIM(CONCAT_WS(' ', {$firstNameExpr}, {$middleNameExpr}, {$lastNameExpr}))";
}

/**
 * Build Dynamic Search/Filter WHERE clause
 * We only want to show members who have been APPROVED.
 */
$whereClauses = ["a.`{$appStatusColumn}` = 'approved'"];
$params = [];
$types = '';

if ($searchQuery !== '') {
    $whereClauses[] = "(i.{$memberIdColumn} LIKE ? OR {$nameExpression} LIKE ? OR t.tribe_name LIKE ? OR {$barangaySource}.{$barangayColumn} LIKE ?)";
    $searchParam = '%' . $searchQuery . '%';
    array_push($params, $searchParam, $searchParam, $searchParam, $searchParam);
    $types .= 'ssss';
}

if ($selectedTribe !== '' && $selectedTribe !== 'All Tribes') {
    $whereClauses[] = "t.tribe_name = ?"; $params[] = $selectedTribe; $types .= 's';
}

if ($selectedBarangay !== '' && $selectedBarangay !== 'All Barangays') {
    $whereClauses[] = "{$barangaySource}.{$barangayColumn} = ?"; $params[] = $selectedBarangay; $types .= 's';
}

$whereClauseSql = " WHERE " . implode(' AND ', $whereClauses);

if ($memberIdColumn === null || $tribeColumn === null || $barangayColumn === null || $registrationColumn === null) {
    $errorMessage = 'Missing required columns in system tables (member_id, tribe_clan, barangay, registration_date).';
} else {
    $countSql = "SELECT COUNT(*) AS total FROM ipmembers i JOIN applications a ON i.ip_member_id = a.ip_member_id LEFT JOIN ip_member_details d ON i.ip_member_id = d.ip_member_id LEFT JOIN tribes t ON {$tribeSource}.{$tribeColumn} = t.tribe_id" . $whereClauseSql;
    $countStmt = $conn->prepare($countSql);
    if ($countStmt) {
        if (!empty($params)) $countStmt->bind_param($types, ...$params);
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

    $rawSexSource = in_array('sex', $columns, true) ? 'i.sex' : "d.sex";
    $sexExpression = "CASE 
        WHEN LOWER(TRIM($rawSexSource)) IN ('m', 'male') THEN 'Male' 
        WHEN LOWER(TRIM($rawSexSource)) IN ('f', 'female') THEN 'Female' 
        ELSE 'N/A' END";

    $ipMemberIdExpression = $ipMemberIdColumn !== null ? "i.{$ipMemberIdColumn}" : "i.{$memberIdColumn}";
    $firstNameExpression = $firstNameColumn !== null ? "i.{$firstNameColumn}" : "''";
    $middleNameExpression = $middleNameColumn !== null ? "i.{$middleNameColumn}" : "''";
    $lastNameExpression = $lastNameColumn !== null ? "i.{$lastNameColumn}" : "''";
    $birthdateExpression = $birthdateColumn !== null ? "{$birthSource}.{$birthdateColumn}" : "''";
    $placeOfBirthExpression = $placeOfBirthColumn !== null ? "{$pobSource}.{$placeOfBirthColumn}" : "''";
    $currentAddressExpression = $currentAddressColumn !== null ? "{$addressSource}.{$currentAddressColumn}" : "''";
    $contactInformationExpression = $contactInformationColumn !== null ? "{$contactSource}.{$contactInformationColumn}" : "''";

    $sql = "SELECT {$nameExpression} AS member_name,
                   {$ipMemberIdExpression} AS ip_member_id,
                   " . ($displayIdCol ? "i.`$displayIdCol`" : "NULL") . " AS member_id,
                   COALESCE(t.tribe_name, CAST({$tribeSource}.{$tribeColumn} AS CHAR)) AS tribe_clan,
                   {$barangaySource}.{$barangayColumn} AS barangay,
                   {$regSource}.{$registrationColumn} AS registration_date,
                   {$firstNameExpression} AS first_name,
                   {$middleNameExpression} AS middle_name,
                   {$lastNameExpression} AS last_name,
                   {$birthdateExpression} AS birthdate,
                   {$placeOfBirthExpression} AS place_of_birth,
                   {$currentAddressExpression} AS current_address,
                   {$contactInformationExpression} AS contact_information,
                   {$sexExpression} AS sex
            FROM ipmembers i
            INNER JOIN applications a ON i.ip_member_id = a.ip_member_id
            LEFT JOIN ip_member_details d ON i.ip_member_id = d.ip_member_id
            LEFT JOIN tribes t ON {$tribeSource}.{$tribeColumn} = t.tribe_id
                 " . $whereClauseSql . "
                 ORDER BY {$regSource}.{$registrationColumn} DESC, i.ip_member_id DESC
                 LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $queryParams = array_merge($params, [$perPage, $offset]);
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
        $errorMessage = 'Unable to load member records: ' . $conn->error;
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
                <h1 class="text-lg font-bold text-[#262626]">Total Members</h1>
            </div>
        </div>
    </header>

    <div class="p-4 md:p-10">
        <?php if ($errorMessage !== ''): ?>
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div class="flex flex-col md:flex-row items-center justify-between gap-4 mb-6">
            <form action="total_members.php" method="get" class="relative w-full md:w-96">
                <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"></i>
                <input type="text" name="query" value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search by ID, name, or tribe..." 
                    class="w-full bg-white border border-[#dedede] rounded-xl py-3 pl-12 pr-4 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-sm shadow-sm">
            </form>

            <div class="flex items-center gap-3 ml-auto">
                <div class="relative min-w-[160px]">
                    <select onchange="window.location.href='total_members.php?query=<?php echo urlencode($searchQuery); ?>&barangay=<?php echo urlencode($selectedBarangay); ?>&tribe=' + encodeURIComponent(this.value)" class="appearance-none w-full bg-white border border-[#dedede] rounded-xl pl-4 pr-10 py-3 text-xs font-bold uppercase text-gray-500 outline-none focus:ring-2 focus:ring-[#262626]/10 transition cursor-pointer shadow-sm">
                        <option value="">All Tribes</option>
                        <option value="Iraynon-Bukidnon" <?php echo $selectedTribe === 'Iraynon-Bukidnon' ? 'selected' : ''; ?>>Iraynon-Bukidnon</option>
                        <option value="Ati Tribe" <?php echo $selectedTribe === 'Ati Tribe' ? 'selected' : ''; ?>>Ati Tribe</option>
                    </select>
                    <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400">
                        <i data-lucide="chevron-down" class="w-4 h-4"></i>
                    </div>
                </div>
                <div class="relative min-w-[160px]">
                    <select onchange="window.location.href='total_members.php?query=<?php echo urlencode($searchQuery); ?>&tribe=<?php echo urlencode($selectedTribe); ?>&barangay=' + encodeURIComponent(this.value)" class="appearance-none w-full bg-white border border-[#dedede] rounded-xl pl-4 pr-10 py-3 text-xs font-bold uppercase text-gray-500 outline-none focus:ring-2 focus:ring-[#262626]/10 transition cursor-pointer shadow-sm">
                        <option value="">All Barangays</option>
                        <option value="Villafont" <?php echo $selectedBarangay === 'Villafont' ? 'selected' : ''; ?>>Villafont</option>
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
                        <th class="px-6 py-4 font-bold">Member Details</th>
                        <th class="px-6 py-4 font-bold">Tribe / Clan</th>
                        <th class="px-6 py-4 font-bold">Barangay</th>
                        <th class="px-6 py-4 font-bold">Registration Date</th>
                        <th class="px-6 py-4 font-bold text-right">Action</th>
                    </tr>
                </thead>
                <tbody id="memberTableBody" class="text-sm divide-y divide-[#dedede]">
                    <?php if (!empty($ipmembers)): ?>
                        <?php foreach ($ipmembers as $ipmember): ?>
                            <?php
                                $badgeClass = get_initial_badge_class((string) ($ipmember['sex'] ?? ''));
                                $registrationLabel = 'N/A';
                                $regTimestamp = !empty($ipmember['registration_date']) ? strtotime((string)$ipmember['registration_date']) : false;
                                if ($regTimestamp && $regTimestamp > 0) {
                                    $registrationLabel = date('M d, Y', $regTimestamp);
                                }

                                $fullName = trim((string) ($ipmember['member_name'] ?? ''));
                                $nameParts = preg_split('/\s+/', $fullName);
                                $derivedFirstName = trim((string) ($nameParts[0] ?? ''));
                                $derivedLastName = trim((string) (count($nameParts) > 1 ? $nameParts[count($nameParts) - 1] : ''));

                                $firstNameValue = trim((string) ($ipmember['first_name'] ?? ''));
                                if ($firstNameValue === '') {
                                    $firstNameValue = $derivedFirstName;
                                }

                                $middleNameValue = trim((string) ($ipmember['middle_name'] ?? ''));
                                $lastNameValue = trim((string) ($ipmember['last_name'] ?? ''));
                                if ($lastNameValue === '') {
                                    $lastNameValue = $derivedLastName;
                                }

                                $treeMemberId = trim((string) ($ipmember['ip_member_id'] ?? ''));
                                if ($treeMemberId === '') {
                                    $treeMemberId = trim((string) ($ipmember['member_id'] ?? ''));
                                }
                            ?>
                            <tr
                                class="member-row hover:bg-gray-100 transition-colors duration-200 cursor-pointer"
                                data-full-name="<?php echo htmlspecialchars((string) ($ipmember['member_name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>"
                                data-member-id="<?php echo htmlspecialchars((string) ($ipmember['member_id'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>"
                                data-tree-member-id="<?php echo htmlspecialchars($treeMemberId !== '' ? $treeMemberId : 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                                data-first-name="<?php echo htmlspecialchars($firstNameValue !== '' ? $firstNameValue : 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                                data-middle-name="<?php echo htmlspecialchars($middleNameValue !== '' ? $middleNameValue : 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                                data-last-name="<?php echo htmlspecialchars($lastNameValue !== '' ? $lastNameValue : 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                                data-birthdate="<?php echo htmlspecialchars(trim((string) ($ipmember['birthdate'] ?? '')) !== '' ? (string) $ipmember['birthdate'] : 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                                data-place-of-birth="<?php echo htmlspecialchars(trim((string) ($ipmember['place_of_birth'] ?? '')) !== '' ? (string) $ipmember['place_of_birth'] : 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                                data-current-address="<?php echo htmlspecialchars(trim((string) ($ipmember['current_address'] ?? '')) !== '' ? (string) $ipmember['current_address'] : 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                                data-contact-information="<?php echo htmlspecialchars(trim((string) ($ipmember['contact_information'] ?? '')) !== '' ? (string) $ipmember['contact_information'] : 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                                data-tribe-clan="<?php echo htmlspecialchars((string) ($ipmember['tribe_clan'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>"
                                data-barangay="<?php echo htmlspecialchars((string) ($ipmember['barangay'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>"
                                data-registration-date="<?php echo htmlspecialchars((string) $registrationLabel, ENT_QUOTES, 'UTF-8'); ?>"
                                data-sex="<?php echo htmlspecialchars((string) ($ipmember['sex'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>"
                            >
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-xs border <?php echo htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars(get_initials((string) ($ipmember['member_name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <div class="pointer-events-none">
                                            <p class="font-bold text-[#262626]"><?php echo htmlspecialchars($ipmember['member_name'] ?? 'N/A'); ?></p>
                                            <p class="text-[11px] text-gray-400"><?php echo htmlspecialchars($ipmember['member_id'] ?? 'N/A'); ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-semibold text-[#262626] text-xs"><?php echo htmlspecialchars($ipmember['tribe_clan'] ?? 'N/A'); ?></td>
                                <td class="px-6 py-4 font-semibold text-[#262626] text-xs"><?php echo htmlspecialchars($ipmember['barangay'] ?? 'N/A'); ?></td>
                                <td class="px-6 py-4 text-gray-600 text-xs"><?php echo htmlspecialchars($registrationLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="px-6 py-4 text-right">
                                    <button
                                        type="button"
                                        class="row-action p-2 hover:bg-gray-100 rounded-lg transition js-open-mini-profile"
                                    >
                                        <i data-lucide="more-horizontal" class="w-4 h-4 text-gray-400"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-center text-gray-400">No members found.</td>
                            </tr>
                        <?php endif; ?>
                </tbody>
            </table>
            
            <div class="p-6 border-t border-[#dedede] flex justify-between items-center bg-gray-50/30">
                <p class="text-[10px] font-bold text-gray-400 uppercase">Showing <?php echo count($ipmembers); ?> of <?php echo (int) $totalRecords; ?></p>
                <div class="flex gap-2">
                    <?php if ($currentPage > 1): ?>
                        <a href="?page=<?php echo $currentPage - 1; ?>&query=<?php echo urlencode($searchQuery); ?>&tribe=<?php echo urlencode($selectedTribe); ?>&barangay=<?php echo urlencode($selectedBarangay); ?>" class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-lg hover:bg-white transition">Previous</a>
                    <?php else: ?>
                        <span class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-lg text-gray-300 cursor-not-allowed">Previous</span>
                    <?php endif; ?>

                    <span class="px-3 py-2 text-xs font-bold text-gray-500">Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>

                    <?php if ($currentPage < $totalPages): ?>
                        <a href="?page=<?php echo $currentPage + 1; ?>&query=<?php echo urlencode($searchQuery); ?>&tribe=<?php echo urlencode($selectedTribe); ?>&barangay=<?php echo urlencode($selectedBarangay); ?>" class="px-4 py-2 text-xs font-bold bg-[#262626] text-white rounded-lg hover:bg-[#404040] transition">Next</a>
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
            
            <!-- Card Header Layout -->
            <div class="px-6 py-5 border-b border-[#ececea] flex items-center justify-between bg-gray-50/50">
                <div class="flex items-center gap-2">
                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <h3 class="text-xs font-bold uppercase tracking-wider text-[#262626]">Verified Member Record</h3>
                </div>
                <button id="closeFloatingMemberCard" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-[#262626] transition-all duration-200">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>

            <!-- Main Content Area Split -->
            <div class="grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-[#ececea]">
                
                <!-- Left Sidebar Profile Panel -->
                <div class="p-6 bg-gradient-to-b from-gray-50/30 to-white flex flex-col items-center text-center justify-center col-span-1 min-w-[240px]">
                    <div class="flex items-center justify-center h-28 w-28 shrink-0 balance-profile-container">
                        <div id="floatingInitials" class="h-28 w-28 rounded-full bg-gray-100 text-[#262626] flex items-center justify-center shadow-md font-bold text-3xl tracking-wide uppercase border-4 border-white ring-1 ring-gray-200 aspect-square object-cover border-[#dedede]">
                            --
                        </div>
                    </div>
                    <h2 id="floatingFullName" class="mt-4 text-xl font-bold text-[#262626] tracking-tight">No member selected</h2>
                    <div class="mt-2 inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-700 border border-gray-200">
                        ID: <span id="floatingMemberId" class="ml-1 font-bold text-[#262626]">-</span>
                    </div>
                </div>

                <!-- Right Structured Information Fields -->
                <div class="p-6 col-span-2 space-y-6">
                    
                    <!-- Core Identity Block -->
                    <div>
                        <h4 class="mb-3 text-[11px] font-bold uppercase tracking-wider text-gray-400">Core Identity</h4>
                        <div class="grid grid-cols-1 gap-4">
                            <div class="bg-gray-50/60 p-3 rounded-xl border border-gray-100">
                                <span class="block text-[11px] font-medium text-gray-400 uppercase">Full Name</span>
                                <span id="floatingCoreFullName" class="text-sm font-semibold text-[#262626] mt-0.5 block">-</span>
                            </div>
                        </div>
                    </div>

                    <!-- Vital & Heritage Details Block -->
                    <div>
                        <h4 class="mb-3 text-[11px] font-bold uppercase tracking-wider text-gray-400">Vital & Heritage Information</h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
                            <!-- Row 1: Birth Info -->
                            <div class="flex items-center justify-between py-2.5 border-b border-[#ececea]">
                                <span class="text-xs font-medium text-gray-500">Birthdate</span>
                                <span id="floatingBirthdate" class="text-xs font-bold text-[#262626]">-</span>
                            </div>
                            <div class="flex items-center justify-between py-2.5 border-b border-[#ececea]">
                                <span class="text-xs font-medium text-gray-500">Sex / Gender</span>
                                <span id="floatingSexText" class="text-xs font-bold text-[#262626]">-</span>
                            </div>
                            <div class="flex items-center justify-between py-2.5 border-b border-[#ececea]">
                                <span class="text-xs font-medium text-gray-500">Place of Birth</span>
                                <span id="floatingPlaceOfBirth" class="text-xs font-bold text-[#262626] max-w-[180px] text-right truncate" title="-">-</span>
                            </div>
                            <!-- Row 2: Cultural / Local Identity -->
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

                    <!-- Contact & Locality Block -->
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

            <!-- Bottom Footer Actions Block -->
            <div class="px-6 py-4 bg-gray-50/50 border-t border-[#ececea] flex justify-end gap-3">
                <button type="button" onclick="closeMemberCard()" class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-xl hover:bg-white text-gray-600 transition-all">
                    Close View
                </button>
                <a id="floatingFamilyTreeLink" href="family_lineage.php" class="inline-flex items-center gap-2 bg-[#262626] text-white px-4 py-2 rounded-xl text-xs font-bold hover:bg-[#404040] transition-all shadow-sm">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="3" x2="6" y2="15"></line><circle cx="18" cy="6" r="3"></circle><circle cx="6" cy="18" r="3"></circle><path d="M18 9a9 9 0 0 1-9 9"></path></svg>
                    View Full Family Lineage
                </a>
            </div>

        </div>
    </aside>

    <script>
        lucide.createIcons();
        const memberTableBody = document.getElementById('memberTableBody');
        const floatingMemberCard = document.getElementById('floatingMemberCard');
        const closeFloatingMemberCard = document.getElementById('closeFloatingMemberCard');
        const floatingMemberBackdrop = document.getElementById('floatingMemberBackdrop');

        function getInitials(name) {
            const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
            if (parts.length === 0) {
                return 'NA';
            }

            const first = parts[0].charAt(0).toUpperCase();
            const second = (parts[1] || '').charAt(0).toUpperCase();
            return (first + second) || 'NA';
        }

        function normalizeSexLabel(value) {
            const sex = String(value || '').trim().toLowerCase();
            if (sex === 'm' || sex === 'male') {
                return 'Male';
            }
            if (sex === 'f' || sex === 'female') {
                return 'Female';
            }
            return value || 'N/A';
        }

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
            const floatingCoreFullName = document.getElementById('floatingCoreFullName');
            const floatingBirthdate = document.getElementById('floatingBirthdate');
            const floatingPlaceOfBirth = document.getElementById('floatingPlaceOfBirth');
            const floatingCurrentAddress = document.getElementById('floatingCurrentAddress');
            const floatingContactInformation = document.getElementById('floatingContactInformation');
            const floatingMemberId = document.getElementById('floatingMemberId');
            const floatingTribeClan = document.getElementById('floatingTribeClan');
            const floatingBarangay = document.getElementById('floatingBarangay');
            const floatingFamilyTreeLink = document.getElementById('floatingFamilyTreeLink');

            memberTableBody.addEventListener('click', function (event) {
                if (event.target.closest('.row-action')) {
                    return;
                }

                const row = event.target.closest('tr.member-row');
                if (!row) {
                    return;
                }

                const fullName = row.dataset.fullName || 'N/A';
                const memberId = row.dataset.memberId || 'N/A';
                const treeMemberId = row.dataset.treeMemberId || memberId;

                floatingInitials.textContent = getInitials(fullName);
                floatingFullName.textContent = fullName;
                if (floatingCoreFullName) floatingCoreFullName.textContent = fullName;
                floatingBirthdate.textContent = row.dataset.birthdate || 'N/A';
                floatingPlaceOfBirth.textContent = row.dataset.placeOfBirth || 'N/A';
                floatingCurrentAddress.textContent = row.dataset.currentAddress || 'N/A';
                floatingContactInformation.textContent = row.dataset.contactInformation || 'N/A';
                floatingMemberId.textContent = memberId;
                floatingTribeClan.textContent = row.dataset.tribeClan || 'N/A';

                const rawSex = (row.dataset.sex || '').toLowerCase();
                const displaySex = (rawSex === 'm' || rawSex === 'male') ? 'Male' : ((rawSex === 'f' || rawSex === 'female') ? 'Female' : 'N/A');
                document.getElementById('floatingSexText').textContent = displaySex;
                
                // Apply sex-based coloring
                const sex = (row.dataset.sex || '').toLowerCase();
                floatingInitials.className = 'h-28 w-28 rounded-full flex items-center justify-center shadow-md font-bold text-3xl tracking-wide uppercase border-4 border-white ring-1 ring-gray-200 aspect-square object-cover';
                if (sex === 'male' || sex === 'm') {
                    floatingInitials.classList.add('bg-[#18181b]', 'text-[#e4e4e7]', 'border-[#3f3f46]');
                } else if (sex === 'female' || sex === 'f') {
                    floatingInitials.classList.add('bg-[#e4e4e7]', 'text-[#18181b]', 'border-[#a1a1aa]');
                } else {
                    floatingInitials.classList.add('bg-gray-100', 'text-[#262626]', 'border-[#dedede]');
                }
                floatingBarangay.textContent = row.dataset.barangay || 'N/A';

                if (floatingFamilyTreeLink) {
                    if (treeMemberId && treeMemberId !== 'N/A') {
                        floatingFamilyTreeLink.href = 'verified_lineage.php?member_id=' + encodeURIComponent(treeMemberId);
                    } else {
                        floatingFamilyTreeLink.href = 'family_lineage.php';
                    }
                }

                openMemberCard();
            });
        }

        document.querySelectorAll('.js-open-mini-profile').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.stopPropagation();
                const row = button.closest('tr.member-row');
                if (row) {
                    row.click();
                }
            });
        });

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

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && floatingMemberCard && !floatingMemberCard.classList.contains('hidden')) {
                closeMemberCard();
            }
        });
    </script>
</body>
</html>