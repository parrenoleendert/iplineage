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
        return 'bg-[#18181b] text-[#a1a1aa] border-[#3f3f46]';
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

$memberIdColumn = first_existing_column($columns, ['member_id']);
$ipMemberIdColumn = first_existing_column($columns, ['ip_member_id', 'id']);
$tribeColumn = first_existing_column($columns, ['tribe_clan']);
$barangayColumn = first_existing_column($columns, ['barangay']);
$registrationColumn = first_existing_column($columns, ['registration_date']);
$firstNameColumn = first_existing_column($columns, ['first_name']);
$middleNameColumn = first_existing_column($columns, ['middle_name']);
$lastNameColumn = first_existing_column($columns, ['last_name']);
$birthdateColumn = first_existing_column($columns, ['birthdate', 'date_of_birth']);
$placeOfBirthColumn = first_existing_column($columns, ['place_of_birth', 'birth_place']);
$currentAddressColumn = first_existing_column($columns, ['current_address', 'address']);
$contactInformationColumn = first_existing_column($columns, ['contact_information', 'contact_number', 'contact_no', 'phone_number']);

$nameExpression = "'N/A'";
if (in_array('member_name', $columns, true)) {
    $nameExpression = 'member_name';
} elseif (in_array('first_name', $columns, true) || in_array('last_name', $columns, true)) {
    $firstNameExpr = in_array('first_name', $columns, true) ? 'first_name' : "''";
    $middleNameExpr = in_array('middle_name', $columns, true) ? 'middle_name' : "''";
    $lastNameExpr = in_array('last_name', $columns, true) ? 'last_name' : "''";
    $nameExpression = "TRIM(CONCAT_WS(' ', {$firstNameExpr}, {$middleNameExpr}, {$lastNameExpr}))";
}

if ($memberIdColumn === null || $tribeColumn === null || $barangayColumn === null || $registrationColumn === null) {
    $errorMessage = 'Missing required columns in ipmembers table (member_id, tribe_clan, barangay, registration_date).';
} else {
    $countSql = "SELECT COUNT(*) AS total FROM ipmembers";
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

    $sexExpression = in_array('sex', $columns, true) ? 'i.sex' : "''";
    $ipMemberIdExpression = $ipMemberIdColumn !== null ? "i.{$ipMemberIdColumn}" : "i.{$memberIdColumn}";
    $firstNameExpression = $firstNameColumn !== null ? "i.{$firstNameColumn}" : "''";
    $middleNameExpression = $middleNameColumn !== null ? "i.{$middleNameColumn}" : "''";
    $lastNameExpression = $lastNameColumn !== null ? "i.{$lastNameColumn}" : "''";
    $birthdateExpression = $birthdateColumn !== null ? "i.{$birthdateColumn}" : "''";
    $placeOfBirthExpression = $placeOfBirthColumn !== null ? "i.{$placeOfBirthColumn}" : "''";
    $currentAddressExpression = $currentAddressColumn !== null ? "i.{$currentAddressColumn}" : "''";
    $contactInformationExpression = $contactInformationColumn !== null ? "i.{$contactInformationColumn}" : "''";

    $sql = "SELECT {$nameExpression} AS member_name,
                   {$ipMemberIdExpression} AS ip_member_id,
                   i.{$memberIdColumn} AS member_id,
                   COALESCE(t.tribe_name, CAST(i.{$tribeColumn} AS CHAR)) AS tribe_clan,
                   i.{$barangayColumn} AS barangay,
                   i.{$registrationColumn} AS registration_date,
                   {$firstNameExpression} AS first_name,
                   {$middleNameExpression} AS middle_name,
                   {$lastNameExpression} AS last_name,
                   {$birthdateExpression} AS birthdate,
                   {$placeOfBirthExpression} AS place_of_birth,
                   {$currentAddressExpression} AS current_address,
                   {$contactInformationExpression} AS contact_information,
                   {$sexExpression} AS sex
            FROM ipmembers i
            LEFT JOIN tribes t ON i.{$tribeColumn} = t.tribe_id
                 ORDER BY i.{$registrationColumn} DESC
                 LIMIT {$perPage} OFFSET {$offset}";
    $result = $conn->query($sql);

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $ipmembers[] = $row;
        }
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
            <div class="relative w-full md:w-96">
                <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"></i>
                <input type="text" placeholder="Search by ID, name, or tribe..." 
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
                    <select class="appearance-none w-full bg-white border border-[#dedede] rounded-xl pl-4 pr-10 py-3 text-xs font-bold uppercase text-gray-500 outline-none focus:ring-2 focus:ring-[#262626]/10 transition cursor-pointer shadow-sm">
                        <option>All Barangays</option>
                        <option>Villafont</option>
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
                                if (!empty($ipmember['registration_date'])) {
                                    $registrationLabel = date('M d, Y', strtotime((string) $ipmember['registration_date']));
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
                                    <button title="Edit Connections" class="row-action p-2 hover:bg-gray-100 text-gray-400 rounded-lg transition">
                                        <i data-lucide="edit-3" class="w-4 h-4"></i>
                                    </button>
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
                        <a href="?page=<?php echo $currentPage - 1; ?>" class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-lg hover:bg-white transition">Previous</a>
                    <?php else: ?>
                        <span class="px-4 py-2 text-xs font-bold border border-[#dedede] rounded-lg text-gray-300 cursor-not-allowed">Previous</span>
                    <?php endif; ?>

                    <span class="px-3 py-2 text-xs font-bold text-gray-500">Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>

                    <?php if ($currentPage < $totalPages): ?>
                        <a href="?page=<?php echo $currentPage + 1; ?>" class="px-4 py-2 text-xs font-bold bg-[#262626] text-white rounded-lg hover:bg-[#404040] transition">Next</a>
                    <?php else: ?>
                        <span class="px-4 py-2 text-xs font-bold bg-[#262626]/30 text-white rounded-lg cursor-not-allowed">Next</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <aside id="floatingMemberCard" class="hidden fixed inset-0 z-[60] items-center justify-center p-4 sm:p-6">
        <div id="floatingMemberBackdrop" class="absolute inset-0 bg-black/40"></div>
        <div class="relative z-10 w-full max-w-4xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white p-5 shadow-[0_0_20px_rgba(0,0,0,0.3)]">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-sm font-bold uppercase tracking-wider text-[#262626]">Member Profile</h2>
                <button id="closeFloatingMemberCard" type="button" class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-[#262626] transition">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <div class="mb-6 flex items-center gap-4 p-4">
                <div id="floatingInitials" class="h-24 w-24 flex-shrink-0 rounded-full bg-[#262626] text-white flex items-center justify-center text-2xl font-bold">NA</div>
                <div>
                    <p id="floatingFullName" class="text-lg font-bold text-[#262626]">No member selected</p>
                    <p id="floatingMemberIdTop" class="text-sm text-gray-500">N/A</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <section class="rounded-xl bg-[#f8f8f7] p-4">
                    <h4 class="mb-3 text-xs font-bold uppercase tracking-wide text-[#262626]">Personal Info</h4>
                    <div class="space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">First Name</span><span id="floatingFirstName" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Middle Name</span><span id="floatingMiddleName" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Last Name</span><span id="floatingLastName" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Member ID</span><span id="floatingMemberId" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Birthdate</span><span id="floatingBirthdate" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Place of Birth</span><span id="floatingPlaceOfBirth" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Current Address</span><span id="floatingCurrentAddress" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Contact Information</span><span id="floatingContactInformation" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Tribe / Clan</span><span id="floatingTribeClan" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Barangay</span><span id="floatingBarangay" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Registration Date</span><span id="floatingRegistrationDate" class="font-semibold text-[#262626]">-</span></div>
                        <div class="flex items-center justify-between gap-3"><span class="text-gray-500">Sex</span><span id="floatingSex" class="font-semibold text-[#262626]">-</span></div>
                    </div>
                </section>

                <section class="rounded-xl bg-[#f8f8f7] p-4">
                    <h4 class="mb-3 text-xs font-bold uppercase tracking-wide text-[#262626]">Family Members</h4>
                    <div class="space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Parents</span><span id="floatingParents" class="font-semibold text-[#262626]">See Family Tree</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Siblings</span><span id="floatingSiblings" class="font-semibold text-[#262626]">See Family Tree</span></div>
                        <div class="flex items-center justify-between gap-3 border-b border-[#ececea] pb-2"><span class="text-gray-500">Spouse</span><span id="floatingSpouse" class="font-semibold text-[#262626]">See Family Tree</span></div>
                        <div class="flex items-center justify-between gap-3"><span class="text-gray-500">Children</span><span id="floatingChildren" class="font-semibold text-[#262626]">See Family Tree</span></div>
                        <a id="floatingFamilyTreeLink" href="family_lineage.php" class="inline-flex items-center justify-center mt-3 px-3 py-2 rounded-lg bg-[#262626] text-white text-[10px] font-bold uppercase tracking-wider hover:bg-[#404040] transition">View Family Lineage</a>
                    </div>
                </section>
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
            const floatingMemberIdTop = document.getElementById('floatingMemberIdTop');
            const floatingFirstName = document.getElementById('floatingFirstName');
            const floatingMiddleName = document.getElementById('floatingMiddleName');
            const floatingLastName = document.getElementById('floatingLastName');
            const floatingBirthdate = document.getElementById('floatingBirthdate');
            const floatingPlaceOfBirth = document.getElementById('floatingPlaceOfBirth');
            const floatingCurrentAddress = document.getElementById('floatingCurrentAddress');
            const floatingContactInformation = document.getElementById('floatingContactInformation');
            const floatingMemberId = document.getElementById('floatingMemberId');
            const floatingTribeClan = document.getElementById('floatingTribeClan');
            const floatingBarangay = document.getElementById('floatingBarangay');
            const floatingRegistrationDate = document.getElementById('floatingRegistrationDate');
            const floatingSex = document.getElementById('floatingSex');
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
                floatingMemberIdTop.textContent = memberId;
                floatingFirstName.textContent = row.dataset.firstName || 'N/A';
                floatingMiddleName.textContent = row.dataset.middleName || 'N/A';
                floatingLastName.textContent = row.dataset.lastName || 'N/A';
                floatingBirthdate.textContent = row.dataset.birthdate || 'N/A';
                floatingPlaceOfBirth.textContent = row.dataset.placeOfBirth || 'N/A';
                floatingCurrentAddress.textContent = row.dataset.currentAddress || 'N/A';
                floatingContactInformation.textContent = row.dataset.contactInformation || 'N/A';
                floatingMemberId.textContent = memberId;
                floatingTribeClan.textContent = row.dataset.tribeClan || 'N/A';
                floatingBarangay.textContent = row.dataset.barangay || 'N/A';
                floatingRegistrationDate.textContent = row.dataset.registrationDate || 'N/A';
                floatingSex.textContent = normalizeSexLabel(row.dataset.sex || 'N/A');

                if (floatingFamilyTreeLink) {
                    if (treeMemberId && treeMemberId !== 'N/A') {
                        floatingFamilyTreeLink.href = 'family_lineage.php?member_id=' + encodeURIComponent(treeMemberId);
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