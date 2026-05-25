<?php
// Database must be initialized FIRST before auth helpers and guards that might use it
$dbConfigPath = __DIR__ . '/../dbconfig.php';
if (!file_exists($dbConfigPath)) {
    die('Database configuration file not found: ' . $dbConfigPath);
}
require_once $dbConfigPath;

if (!isset($conn) || $conn === null) {
    die('Database connection failed: $conn is not initialized. Please check dbconfig.php');
}

require_once __DIR__ . '/../auth/guards.php';
require_once __DIR__ . '/../auth/auth_helpers.php';
require_any_role(['ip_member']);

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

$profileName = $displayName;
$profileSex = 'N/A';
$profileBirthdate = 'N/A';
$profileTribe = 'N/A';
$profileStatus = 'Single';
$profileAgeLabel = 'N/A';
$memberLineageUrl = '../family_lineage.php';
$leadershipRows = [];
$tribeTraditions = 'No traditions and rituals data available.';
$tribeArtsCrafts = 'No arts and crafts data available.';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$memberSql = "SELECT i.ip_member_id, i.tribe_clan, i.first_name, i.middle_name, i.last_name, i.birthdate, i.sex, t.tribe_name
              FROM ipmembers i
              LEFT JOIN tribes t ON i.tribe_clan = t.tribe_id
              WHERE i.user_id = ? OR TRIM(CONCAT_WS(' ', i.first_name, i.middle_name, i.last_name)) = ?
              ORDER BY (i.user_id = ?) DESC
              LIMIT 1";
$memberStmt = $conn->prepare($memberSql);
if ($memberStmt) {
    $memberStmt->bind_param('isi', $userId, $displayName, $userId);
    $memberStmt->execute();
    $memberResult = $memberStmt->get_result();
    $memberRow = $memberResult instanceof mysqli_result ? $memberResult->fetch_assoc() : null;
    $memberStmt->close();

    if ($memberRow) {
        $namePartsForProfile = [
            trim((string) ($memberRow['first_name'] ?? '')),
            trim((string) ($memberRow['middle_name'] ?? '')),
            trim((string) ($memberRow['last_name'] ?? '')),
        ];
        $combinedProfileName = trim(implode(' ', array_filter($namePartsForProfile, static function ($value) {
            return $value !== '';
        })));
        if ($combinedProfileName !== '') {
            $profileName = $combinedProfileName;
        }

        $profileSex = (string) ($memberRow['sex'] ?? 'N/A');
        $profileTribe = (string) ($memberRow['tribe_name'] ?? 'N/A');

        $birthdateValue = (string) ($memberRow['birthdate'] ?? '');
        if ($birthdateValue !== '') {
            $birthTimestamp = strtotime($birthdateValue);
            if ($birthTimestamp !== false) {
                $profileBirthdate = date('M d, Y', $birthTimestamp);
            }

            try {
                $birthDate = new DateTime($birthdateValue);
                $today = new DateTime();
                $profileAgeLabel = (string) $birthDate->diff($today)->y;
            } catch (Exception $e) {
                $profileAgeLabel = 'N/A';
            }
        }

        $memberPrimaryId = (int) ($memberRow['ip_member_id'] ?? 0);
        if ($memberPrimaryId > 0) {
            $memberLineageUrl = '../family_lineage.php?member_id=' . rawurlencode((string) $memberPrimaryId);
        }

        $memberTribeId = (int) ($memberRow['tribe_clan'] ?? 0);
        if ($memberTribeId > 0) {
            $tribeInfoSql = "SELECT traditions_rituals, arts_crafts FROM tribe_information WHERE tribe_id = ? LIMIT 1";
            $tribeInfoStmt = $conn->prepare($tribeInfoSql);
            if ($tribeInfoStmt) {
                $tribeInfoStmt->bind_param('i', $memberTribeId);
                $tribeInfoStmt->execute();
                $tribeInfoResult = $tribeInfoStmt->get_result();
                $tribeInfoRow = $tribeInfoResult instanceof mysqli_result ? $tribeInfoResult->fetch_assoc() : null;
                $tribeInfoStmt->close();

                if ($tribeInfoRow) {
                    $traditionsValue = trim((string) ($tribeInfoRow['traditions_rituals'] ?? ''));
                    $artsCraftsValue = trim((string) ($tribeInfoRow['arts_crafts'] ?? ''));

                    if ($traditionsValue !== '') {
                        $tribeTraditions = $traditionsValue;
                    }
                    if ($artsCraftsValue !== '') {
                        $tribeArtsCrafts = $artsCraftsValue;
                    }
                }
            }
        }

        if ($memberPrimaryId > 0) {
            $relationshipSql = "SELECT 1 FROM relationships WHERE relationship_type = 'spouse' AND (person_id = ? OR related_person_id = ?) LIMIT 1";
            $relationshipStmt = $conn->prepare($relationshipSql);
            if ($relationshipStmt) {
                $relationshipStmt->bind_param('ii', $memberPrimaryId, $memberPrimaryId);
                $relationshipStmt->execute();
                $relationshipResult = $relationshipStmt->get_result();
                if ($relationshipResult instanceof mysqli_result && $relationshipResult->fetch_assoc()) {
                    $profileStatus = 'Married';
                }
                $relationshipStmt->close();
            }
        }
    }
}

$leadershipSql = "SELECT official_name, designation, term FROM leadership_structure ORDER BY id ASC LIMIT 3";
$leadershipResult = mysqli_query($conn, $leadershipSql);
if ($leadershipResult instanceof mysqli_result) {
    while ($leadershipRow = mysqli_fetch_assoc($leadershipResult)) {
        $leadershipRows[] = $leadershipRow;
    }
}

$leadershipFallbackRows = [
    ['official_name' => 'Datu Ramon Salonga', 'designation' => 'Tribal Chieftain', 'term' => '2025 - 2028'],
    ['official_name' => 'Lita M. Dumalag', 'designation' => 'Elder Council Head', 'term' => '2025 - 2028'],
    ['official_name' => 'Jomar Dela Cruz', 'designation' => 'Youth Representative', 'term' => '2025 - 2028'],
];

$leadershipDisplayRows = $leadershipRows;
if (count($leadershipDisplayRows) < 3) {
    foreach ($leadershipFallbackRows as $fallbackRow) {
        if (count($leadershipDisplayRows) >= 3) {
            break;
        }
        $leadershipDisplayRows[] = $fallbackRow;
    }
}

if (count($leadershipDisplayRows) > 3) {
    $leadershipDisplayRows = array_slice($leadershipDisplayRows, 0, 3);
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
    <link rel="stylesheet" href="../../css/style.css">
    
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
    <?php $activeNav = 'dashboard'; include __DIR__ . '/../shared/sidebar.php'; ?>

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

            <section class="mb-8">
                <h1 class="text-2xl font-bold text-[#262626]">Dashboard</h1>
            </section>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-start mb-6 min-vh-150">
                <div class="bg-card-custom p-5 rounded-2xl shadow-sm border border-gray-100 md:row-span-2 min-h-[280px] transition-all duration-300 hover:-translate-y-1 hover:shadow-md hover:border-gray-300">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-lg text-[#262626]">Profile Summary</h3>
                        <span class="px-3 py-1 rounded-full bg-green-100 text-green-700 text-xs font-bold uppercase tracking-wider">
                            Verified
                        </span>
                    </div>

                    <div class="flex flex-col items-center text-center pb-4 border-b border-gray-100 mb-4">
                        <div class="w-[72px] h-[72px] rounded-full bg-[#262626] text-white flex items-center justify-center shadow-sm mb-3">
                            <i data-lucide="user-round" class="w-8 h-8"></i>
                        </div>
                        <h3 class="text-lg font-bold text-[#262626]"><?php echo htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p class="text-sm text-gray-500 font-medium"><?php echo htmlspecialchars($profileStatus, ENT_QUOTES, 'UTF-8'); ?> • <?php echo htmlspecialchars($profileAgeLabel, ENT_QUOTES, 'UTF-8'); ?> Years Old</p>
                    </div>

                    <div class="space-y-3">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500 font-medium">Sex</span>
                            <span class="text-[#262626] font-semibold"><?php echo htmlspecialchars($profileSex, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500 font-medium">Date of Birth</span>
                            <span class="text-[#262626] font-semibold"><?php echo htmlspecialchars($profileBirthdate, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500 font-medium">Tribe</span>
                            <span class="text-[#262626] font-semibold"><?php echo htmlspecialchars($profileTribe, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>
                </div>

                <div class="bg-card-custom p-4 rounded-2xl shadow-sm border border-gray-100 min-h-[160px] flex flex-col justify-between transition-all duration-300 hover:-translate-y-1 hover:shadow-md hover:border-gray-300">
                    <div>
                        <p class="text-[10px] uppercase tracking-widest text-gray-500 font-bold mb-2">Lineage</p>
                        <h3 class="text-sm font-bold text-[#262626]">View Lineage</h3>
                        <p class="text-xs text-gray-500 mt-1">Open your family tree record.</p>
                    </div>

                    <a href="<?php echo htmlspecialchars($memberLineageUrl, ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex items-center justify-center mt-4 px-3 py-2 rounded-lg bg-[#262626] text-white text-[10px] font-bold uppercase tracking-wider hover:bg-[#404040] transition">
                        View Lineage
                    </a>
                </div>

                <div class="bg-card-custom p-4 rounded-2xl shadow-sm border border-gray-100 min-h-[160px] flex flex-col justify-between transition-all duration-300 hover:-translate-y-1 hover:shadow-md hover:border-gray-300">
                    <div>
                        <p class="text-[10px] uppercase tracking-widest text-gray-500 font-bold mb-2">Announcement</p>
                        <h3 class="text-sm font-bold text-[#262626]">Scheduled Community Assembly</h3>
                        <p class="text-xs text-gray-500 mt-1">All IP members are requested to attend on April 20, 2026 at 9:00 AM in the Barangay Hall.</p>
                    </div>

                    <div class="inline-flex items-center gap-2 text-[11px] font-semibold text-[#262626] mt-4">
                        <i data-lucide="calendar-days" class="w-4 h-4"></i>
                        <span>Posted by Tribal Office</span>
                    </div>
                </div>
                <div class="bg-card-custom p-4 rounded-2xl shadow-sm border border-gray-100 h-[130px] min-h-[130px] max-h-[130px] min-w-[10px] md:col-span-2 transition-all duration-300 hover:-translate-y-1 hover:shadow-md hover:border-gray-300 overflow-hidden">
                    <div class="h-full flex flex-col min-h-0">
                        <div>
                            <p class="text-[10px] uppercase tracking-widest text-gray-500 font-bold mb-2">Leadership Structure</p>
                            
                            <?php if (!empty($leadershipDisplayRows)): ?>
                                <div class="mt-2 rounded-xl border border-[#dedede] overflow-hidden">
                                    <table class="w-full table-auto text-left text-xs text-gray-600">
                                        <thead class="bg-gray-50/70 text-[10px] uppercase tracking-widest text-gray-500 border-y border-[#dedede]">
                                            <tr>
                                                <th scope="col" class="px-4 py-2 font-bold">Position</th>
                                                <th scope="col" class="px-4 py-2 font-bold">Official Name</th>
                                                <th scope="col" class="px-4 py-2 font-bold">Term</th>
                                            </tr>
                                        </thead>
                                    </table>

                                    <div class="h-[44px] overflow-y-auto">
                                        <table class="w-full table-auto text-left text-xs text-gray-600">
                                            <tbody>
                                            <?php foreach ($leadershipDisplayRows as $leader): ?>
                                                <tr class="border-b border-[#dedede] hover:bg-gray-50 transition-colors duration-200">
                                                    <td class="px-4 py-2 font-semibold text-[#262626]">
                                                        <?php echo htmlspecialchars((string) ($leader['designation'] ?? 'Officer'), ENT_QUOTES, 'UTF-8'); ?>
                                                    </td>
                                                    <td class="px-4 py-2">
                                                        <?php echo htmlspecialchars((string) ($leader['official_name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>
                                                    </td>
                                                    <td class="px-4 py-2">
                                                        <?php echo htmlspecialchars((string) (($leader['term'] ?? '') !== '' ? $leader['term'] : 'N/A'), ENT_QUOTES, 'UTF-8'); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php else: ?>
                                <p class="mt-3 text-xs text-gray-500">No leadership records available.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-start mb-6">
                <div class="bg-card-custom p-4 rounded-2xl shadow-sm border border-gray-100 min-h-[160px] md:col-span-2 transition-all duration-300 hover:-translate-y-1 hover:shadow-md hover:border-gray-300">
                    <div>
                        <p class="text-[10px] uppercase tracking-widest text-gray-500 font-bold mb-2">Traditions and Cultural Heritage</p>
                        <h3 class="text-sm font-bold text-[#262626] mb-3"><?php echo htmlspecialchars($profileTribe, ENT_QUOTES, 'UTF-8'); ?></h3>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <p class="text-[10px] font-bold text-gray-400 mb-2 uppercase tracking-tight">Traditions & Rituals</p>
                            <p class="text-xs text-gray-600 leading-relaxed"><?php echo nl2br(htmlspecialchars($tribeTraditions, ENT_QUOTES, 'UTF-8')); ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold text-gray-400 mb-2 uppercase tracking-tight">Arts & Crafts</p>
                            <p class="text-xs text-gray-600 leading-relaxed"><?php echo nl2br(htmlspecialchars($tribeArtsCrafts, ENT_QUOTES, 'UTF-8')); ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-card-custom p-3 rounded-2xl shadow-sm border border-gray-100 h-[160px] min-h-[160px] flex flex-col overflow-hidden bg-gradient-to-br from-white to-[#f9faf9] transition-all duration-300 hover:-translate-y-1 hover:shadow-md hover:border-gray-300">
                    <div class="flex items-center justify-between">
                        <p class="text-[9px] uppercase tracking-widest text-gray-500 font-bold mb-1">Downloads</p>
                        <span class="text-[9px] font-bold text-[#262626] bg-[#262626]/10 px-1.5 py-0.5 rounded-md">3 Files</span>
                    </div>

                    <div class="mt-2 space-y-1.5 overflow-y-auto pr-1">
                        <div class="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 bg-white/90 hover:bg-white transition">
                            <div class="flex items-center gap-1.5 min-w-0">
                                <i data-lucide="shield-alert" class="w-3.5 h-3.5 text-[#262626]/70"></i>
                                <span class="text-[11px] font-medium text-[#262626] truncate">Barangay Emergency Hotline List</span>
                            </div>
                            <a
                                href="data:text/plain;charset=utf-8,Barangay%20Emergency%20Hotline%20List%0A%0ABarangay%20Hall%3A%200912-345-6789%0ABFP%20Fire%20Desk%3A%200998-111-2233%0APNP%20Assistance%3A%200917-444-5566%0ARural%20Health%20Unit%3A%200905-777-8899%0A"
                                download="barangay_emergency_hotlines.txt"
                                class="inline-flex items-center gap-1 rounded-md bg-[#262626] text-white px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide hover:bg-[#404040] transition"
                            >
                                <i data-lucide="download" class="w-3 h-3"></i> Download
                            </a>
                        </div>

                        <div class="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 bg-white/90 hover:bg-white transition">
                            <div class="flex items-center gap-1.5 min-w-0">
                                <i data-lucide="clipboard-check" class="w-3.5 h-3.5 text-[#262626]/70"></i>
                                <span class="text-[11px] font-medium text-[#262626] truncate">Disaster Preparedness Checklist</span>
                            </div>
                            <a
                                href="data:text/plain;charset=utf-8,Disaster%20Preparedness%20Checklist%0A%0A1.%20Prepare%20go-bag%20for%20each%20family%20member%0A2.%20Keep%20important%20documents%20in%20waterproof%20folder%0A3.%20Identify%20nearest%20evacuation%20site%0A4.%20Save%20barangay%20hotline%20numbers%0A"
                                download="disaster_preparedness_checklist.txt"
                                class="inline-flex items-center gap-1 rounded-md bg-[#262626] text-white px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide hover:bg-[#404040] transition"
                            >
                                <i data-lucide="download" class="w-3 h-3"></i> Download
                            </a>
                        </div>

                        <div class="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 bg-white/90 hover:bg-white transition">
                            <div class="flex items-center gap-1.5 min-w-0">
                                <i data-lucide="megaphone" class="w-3.5 h-3.5 text-[#262626]/70"></i>
                                <span class="text-[11px] font-medium text-[#262626] truncate">Community Assembly Notice</span>
                            </div>
                            <a
                                href="data:text/plain;charset=utf-8,Community%20Assembly%20Notice%0A%0ADate%3A%20April%2020%2C%202026%0ATime%3A%209%3A00%20AM%0AVenue%3A%20Barangay%20Hall%0A%0AAll%20IP%20members%20are%20requested%20to%20attend.%0A"
                                download="community_assembly_notice.txt"
                                class="inline-flex items-center gap-1 rounded-md bg-[#262626] text-white px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide hover:bg-[#404040] transition"
                            >
                                <i data-lucide="download" class="w-3 h-3"></i> Download
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
             
    <script>
        lucide.createIcons();
    </script>
</body>
</html>