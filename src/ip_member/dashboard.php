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

            <!-- Blinking Attention Effect -->
            <style>
                @keyframes attention-blink {
                    0%, 100% {
                        border-color: #dedede;
                        box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
                    }
                    50% {
                        border-color: #262626;
                        box-shadow: 0 0 8px rgba(38, 38, 38, 0.2);
                    }
                }
                .animate-attention-pulse {
                    animation: attention-blink 1.8s infinite ease-in-out;
                }
            </style>

            <!-- Main Dashboard Layout Content Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start mb-6">
                
                <!-- LEFT COLUMN: PROFILE SUMMARY -->
                <div class="bg-white p-6 rounded-2xl border border-[#dedede] shadow-xs min-h-[460px] flex flex-col justify-between">
                    <div>
                        <div class="mb-6">
                            <h3 class="text-xs uppercase tracking-widest text-gray-400 font-bold">Profile Summary</h3>
                        </div>

                        <!-- Profile Avatar Blocks Layout -->
                        <div class="flex items-center gap-4 pb-6 border-b border-[#ececea] mb-6">
                            <div class="w-16 h-16 rounded-xl bg-[#262626] text-white flex items-center justify-center font-bold text-lg uppercase tracking-wider shadow-xs shrink-0 select-none">
                                <?php 
                                    $words = explode(" ", trim($profileName));
                                    $initials = "";
                                    foreach ($words as $w) {
                                        $initials .= mb_substr($w, 0, 1, "UTF-8");
                                    }
                                    echo htmlspecialchars(mb_strtoupper(mb_substr($initials, 0, 2, "UTF-8")), ENT_QUOTES, 'UTF-8');
                                ?>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-xl font-bold text-[#262626] tracking-tight truncate"><?php echo htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8'); ?></h3>
                                <p class="text-sm text-gray-500 font-semibold mt-1 tracking-wide">
                                    <?php echo htmlspecialchars(($profileRole ?? 'IP Member'), ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            </div>
                        </div>

                        <div class="space-y-4 text-sm">
                            <div class="flex justify-between items-center border-b border-dashed border-[#ececea] pb-2.5">
                                <span class="text-gray-400 font-medium">Sex / Gender</span>
                                <span class="text-[#262626] font-semibold"><?php echo htmlspecialchars($profileSex, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="flex justify-between items-center border-b border-dashed border-[#ececea] pb-2.5">
                                <span class="text-gray-400 font-medium">Date of Birth</span>
                                <span class="text-[#262626] font-semibold"><?php echo htmlspecialchars($profileBirthdate, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="flex justify-between items-center border-b border-dashed border-[#ececea] pb-2.5">
                                <span class="text-gray-400 font-medium">Registered Tribe</span>
                                <span class="text-[#262626] font-semibold"><?php echo htmlspecialchars($profileTribe, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="flex justify-between items-center border-b border-dashed border-[#ececea] pb-2.5">
                                <span class="text-gray-400 font-medium">Email Address</span>
                                <span class="text-[#262626] font-semibold truncate max-w-[180px]"><?php echo htmlspecialchars(($profileEmail ?? 'leendert.parreno@gmail.com'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="flex justify-between items-center border-b border-dashed border-[#ececea] pb-2.5">
                                <span class="text-gray-400 font-medium">Contact Number</span>
                                <span class="text-[#262626] font-semibold"><?php echo htmlspecialchars(($profileContactNum ?? '+63 912 123 4567'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="flex justify-between items-center pb-1">
                                <span class="text-gray-400 font-medium">Address</span>
                                <span class="text-[#262626] font-semibold"><?php echo htmlspecialchars(($profileAddress ?? 'Hamtic, Antique'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- MIDDLE COLUMN: PERSONAL LINEAGE & LEADERSHIP STRUCTURE -->
                <div class="lg:col-span-2 space-y-6 flex flex-col justify-between min-h-[460px]">
                    
                    <!-- PERSONAL LINEAGE CARD -->
                    <div class="bg-white p-6 rounded-2xl border border-[#dedede] shadow-xs flex flex-col sm:flex-row justify-between sm:items-center gap-4 animate-attention-pulse">
                        <div class="space-y-1">
                            <h3 class="text-base font-bold text-[#262626]">Personal Ancestral Lineage</h3>
                            <p class="text-sm text-gray-500 max-w-xl">View your structural multi-generational family tree, manage lineage records, and track verified indigenous ancestral nodes.</p>
                        </div>
                        <div class="shrink-0">
                            <a href="<?php echo htmlspecialchars($memberLineageUrl, ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-[#262626] text-white text-sm font-bold hover:bg-[#404040] transition shadow-xs">
                                <span>Open Family Tree</span>
                                <i data-lucide="arrow-right" class="w-4 h-4"></i>
                            </a>
                        </div>
                    </div>

                    <!-- LEADERSHIP STRUCTURE CARD -->
                    <div class="bg-white p-6 rounded-2xl border border-[#dedede] shadow-xs flex-1 flex flex-col justify-between mt-auto">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-base font-bold text-[#262626]">Leadership Structure</h3>
                            <i data-lucide="users" class="w-4 h-4 text-gray-300"></i>
                        </div>
                        
                        <?php if (!empty($leadershipDisplayRows)): ?>
                            <div class="rounded-xl border border-[#ececea] overflow-hidden bg-white flex-1">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="text-xs uppercase tracking-wider text-gray-400 bg-gray-50/70 border-b border-[#ececea]">
                                            <th scope="col" class="px-5 py-3.5 font-bold">Designated Position</th>
                                            <th scope="col" class="px-5 py-3.5 font-bold">Official Name</th>
                                            <th scope="col" class="px-5 py-3.5 font-bold">Term Coverage</th>
                                        </tr>
                                    </thead>
                                    <tbody class="text-sm text-gray-600 divide-y divide-[#ececea]">
                                        <?php foreach ($leadershipDisplayRows as $leader): ?>
                                            <tr class="hover:bg-gray-50/50 transition-colors">
                                                <td class="px-5 py-3.5 font-bold text-[#262626]">
                                                    <?php echo htmlspecialchars((string) ($leader['designation'] ?? 'Council Officer'), ENT_QUOTES, 'UTF-8'); ?>
                                                </td>
                                                <td class="px-5 py-3.5 font-medium">
                                                    <?php echo htmlspecialchars((string) ($leader['official_name'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>
                                                </td>
                                                <td class="px-5 py-3.5 text-gray-400 font-semibold">
                                                    <?php echo htmlspecialchars((string) (($leader['term'] ?? '') !== '' ? $leader['term'] : 'Active Tenure'), ENT_QUOTES, 'UTF-8'); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="py-12 text-center border border-dashed border-[#dedede] rounded-xl bg-gray-50/50 flex-1 flex flex-col items-center justify-center">
                                <p class="text-sm text-gray-400 font-medium">No system leadership records established at this time.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- LOWER LAYOUT ROW BLOCK -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start mb-6">
                
                <!-- LEFT: CULTURAL IDENTITY -->
                <div class="lg:col-span-2 bg-white p-6 rounded-2xl border border-[#dedede] shadow-xs min-h-[250px] flex flex-col justify-between">
                    <div class="mb-6 pb-3 border-b border-[#ececea] flex items-center justify-between">
                        <h3 class="text-xs uppercase tracking-widest text-gray-400 font-bold">Cultural Identity</h3>
                        <i data-lucide="fingerprint" class="w-4 h-4 text-gray-300"></i>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 flex-1">
                        <div class="space-y-2">
                            <h4 class="text-xs font-bold text-gray-400 uppercase tracking-wider">Traditions & Ritual Protocols</h4>
                            <p class="text-sm text-gray-600 leading-relaxed font-medium"><?php echo nl2br(htmlspecialchars($tribeTraditions, ENT_QUOTES, 'UTF-8')); ?></p>
                        </div>
                        <div class="space-y-2">
                            <h4 class="text-xs font-bold text-gray-400 uppercase tracking-wider">Indigenous Arts & Crafts</h4>
                            <p class="text-sm text-gray-600 leading-relaxed font-medium"><?php echo nl2br(htmlspecialchars($tribeArtsCrafts, ENT_QUOTES, 'UTF-8')); ?></p>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: UPLOADED DOCUMENTS -->
                <div class="bg-white p-6 rounded-2xl border border-[#dedede] shadow-xs min-h-[250px] flex flex-col justify-between">
                    <div>
                        <div class="mb-4 pb-3 border-b border-[#ececea] flex items-center justify-between">
                            <h3 class="text-xs uppercase tracking-widest text-gray-400 font-bold">Uploaded Documents</h3>
                            <i data-lucide="folder-open" class="w-4 h-4 text-gray-300"></i>
                        </div>

                        <div class="space-y-2.5">
                            <!-- Document 1: PSA Birth Certificate -->
                            <div class="flex items-center justify-between gap-4 p-2.5 rounded-xl bg-gray-50/50 hover:bg-gray-100/50 border border-[#ececea] transition">
                                <span class="text-sm font-semibold text-[#262626] truncate">PSA Birth Certificate</span>
                                <a href="#" class="inline-flex items-center gap-1.5 bg-[#262626] text-white px-3 py-1.5 rounded-lg text-xs font-bold uppercase tracking-wider hover:bg-[#404040] transition shrink-0">
                                    <i data-lucide="download" class="w-3.5 h-3.5"></i> Download
                                </a>
                            </div>

                            <!-- Document 2: NCIP Genealogy Form -->
                            <div class="flex items-center justify-between gap-4 p-2.5 rounded-xl bg-gray-50/50 hover:bg-gray-100/50 border border-[#ececea] transition">
                                <span class="text-sm font-semibold text-[#262626] truncate">NCIP Genealogy Form</span>
                                <a href="#" class="inline-flex items-center gap-1.5 bg-[#262626] text-white px-3 py-1.5 rounded-lg text-xs font-bold uppercase tracking-wider hover:bg-[#404040] transition shrink-0">
                                    <i data-lucide="download" class="w-3.5 h-3.5"></i> Download
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="pt-3 flex items-center justify-between border-t border-[#ececea] mt-4">
                        <a href="#" class="w-full text-center py-2 px-3 border border-[#262626] text-[#262626] rounded-xl text-xs font-bold hover:bg-gray-50 transition">
                            View All Documents
                        </a>
                    </div>
                </div>
            </div>
             
    <script>
        lucide.createIcons();
    </script>
</body>
</html>