<?php 
require_once __DIR__ . '/auth/guards.php';
require_any_role(['admin', 'tribe_leader']);

require_once __DIR__ . '/dbconfig.php';
$conn = $GLOBALS['conn'] ?? ($conn ?? null);
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection not established. Check src/dbconfig.php and MySQL service.');
}

// --- Dynamic Column Detection for ipmembers ---
$ipColumns = [];
$resCols = $conn->query("SHOW COLUMNS FROM ipmembers");
if ($resCols) { while($c = $resCols->fetch_assoc()) $ipColumns[] = $c['Field']; }

// --- Dynamic Column Detection for ip_member_details ---
$detailColumns = [];
$resD = $conn->query("SHOW COLUMNS FROM ip_member_details");
if ($resD) { while($c = $resD->fetch_assoc()) $detailColumns[] = $c['Field']; }

$ipPkCol = first_existing_column($ipColumns, ['ip_member_id', 'id', 'member_id']) ?? 'ip_member_id';
$tribeClanCol = first_existing_column($ipColumns, ['tribe_clan', 'tribe_id', 'tribe']);

$popJoin = $tribeClanCol 
    ? "LEFT JOIN ipmembers i ON t.tribe_id = i.`{$tribeClanCol}`" 
    : "LEFT JOIN ip_member_details d ON t.tribe_id = d.`" . (first_existing_column($detailColumns, ['tribe', 'tribe_id', 'tribe_clan']) ?? 'tribe') . "` 
       LEFT JOIN ipmembers i ON d.ip_member_id = i.`{$ipPkCol}`";

$perPage = 5;
$currentPage = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($currentPage < 1) {
    $currentPage = 1;
}
$totalRecords = 0;
$totalPages = 1;

$countSql = "SELECT COUNT(*) AS total FROM tribes";
$countResult = mysqli_query($conn, $countSql);
if ($countResult instanceof mysqli_result) {
    $countRow = mysqli_fetch_assoc($countResult);
    $totalRecords = (int) ($countRow['total'] ?? 0);
}

$totalPages = max(1, (int) ceil($totalRecords / $perPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $perPage;

$sql = "SELECT 
    t.tribe_id,
    t.tribe_name,
    t.language,
    COUNT(i.`{$ipPkCol}`) AS population,
    t.location
FROM tribes t
{$popJoin}
GROUP BY t.tribe_id
ORDER BY t.tribe_name ASC
LIMIT {$perPage} OFFSET {$offset}";

$result = mysqli_query($conn, $sql);

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
                <h1 class="text-lg font-bold text-[#262626]">Active Tribes</h1>
            </div>
        </div>

    </header>

    <div class="p-4 md:p-10">
        <div class="flex flex-col md:flex-row items-center justify-between gap-4 mb-6">
            <div class="relative w-full md:w-96">
                <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400"></i>
                <input type="text" placeholder="Search tribe name..." 
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
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-[#dedede] overflow-hidden">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-[10px] uppercase text-gray-400 bg-gray-50/50 border-b border-[#dedede]">
                        <th class="w-[10%] px-6 py-4 font-bold text-left">Tribe Name</th>
                        <th class="w-[20%] px-4 py-4 font-bold text-center">Language</th>
                        <th class="w-[25%] px-4 py-4 font-bold text-center">Population</th>
                        <th class="w-[20%] px-6 py-4 font-bold text-left">Location</th>
                        <th class="w-[20%] px-6 py-4 font-bold text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="text-sm divide-y divide-[#dedede]">
                    <?php if ($result instanceof mysqli_result && mysqli_num_rows($result) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($result)): ?>
                            <tr class="hover:bg-gray-50/50 transition">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div>
                                            <p class="font-bold text-[#262626]"><?php echo htmlspecialchars($row['tribe_name'] ?? 'N/A'); ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4 font-medium text-gray-600 text-xs text-center"><?php echo htmlspecialchars($row['language'] ?? 'N/A'); ?></td>
                                <td class="px-4 py-4 font-semibold text-[#262626] text-xs text-center"><?php echo htmlspecialchars($row['population'] ?? 0); ?> Members</td>
                                <td class="px-6 py-4 font-medium text-gray-500 text-xs leading-relaxed"><?php echo htmlspecialchars($row['location'] ?? 'N/A'); ?></td>
                                <td class="px-6 py-4 text-right">
                                    <div class="relative inline-block text-left">
                                        <button type="button" class="action-menu-toggle p-2 hover:bg-gray-100 rounded-lg transition text-gray-400">
                                            <i data-lucide="more-horizontal" class="w-4 h-4"></i>
                                        </button>
                                        <div class="action-menu hidden absolute right-0 mt-1 w-44 bg-white border border-[#dedede] rounded-xl shadow-lg z-50 p-1.5">
                                            <a href="tribe_information.php?tribe_id=<?php echo (int)$row['tribe_id']; ?>" 
                                               class="w-full text-left px-3 py-2 text-xs font-bold text-gray-700 hover:bg-gray-50 rounded-md flex items-center gap-2 transition">
                                                <i data-lucide="eye" class="w-3.5 h-3.5 text-gray-400"></i> 
                                                View Tribe Info
                                            </a>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="px-6 py-4 text-center text-gray-500 text-sm">No tribes available.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div class="p-6 border-t border-[#dedede] flex justify-between items-center bg-gray-50/30">
                <p class="text-[10px] font-bold text-gray-400 uppercase">Showing <?php echo isset($result) && $result instanceof mysqli_result ? mysqli_num_rows($result) : 0; ?> of <?php echo (int) $totalRecords; ?> Tribes</p>
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

    <script>
        lucide.createIcons();

        // Action Menu Logic
        document.addEventListener('click', (e) => {
            const toggle = e.target.closest('.action-menu-toggle');
            const menu = e.target.closest('.action-menu');
            
            if (toggle) {
                const targetMenu = toggle.nextElementSibling;
                // Close all other open menus
                document.querySelectorAll('.action-menu').forEach(m => {
                    if (m !== targetMenu) m.classList.add('hidden');
                });
                targetMenu.classList.toggle('hidden');
            } else if (!menu) {
                // Clicked outside, close all menus
                document.querySelectorAll('.action-menu').forEach(m => m.classList.add('hidden'));
            }
        });
    </script>
</body>
</html>