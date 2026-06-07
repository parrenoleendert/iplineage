<?php

if (!function_exists('normalize_role')) {
    require_once __DIR__ . '/../auth/auth_helpers.php';
}

$currentRole = normalize_role((string) ($_SESSION['role'] ?? ''));
$isAdmin = $currentRole === 'admin';
$isIpMember = $currentRole === 'ip_member';
$activeNav = isset($activeNav) ? (string) $activeNav : 'dashboard';

$isIpMemberPage = strpos((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/ip_member/') !== false;
$basePath = $isIpMemberPage ? '../' : '';

$logoSrc = $isIpMemberPage ? '../../img/ip (1) 3.png' : '../img/ip (1) 3.png';
$logoutHref = $isIpMemberPage ? '../logout.php' : 'logout.php';
$tribeProfileHref = $isIpMemberPage ? '../tribe_information.php' : 'tribe_information.php';
$profileHref = $isIpMemberPage ? '../profile.php' : 'profile.php';

$dashboardHref = ($isAdmin || $currentRole === 'tribe_leader') ? $basePath . 'dashboard.php' : ($isIpMemberPage ? 'dashboard.php' : 'ip_member/dashboard.php');
$userMgmtHref = $basePath . 'user_management.php';
$lineageHref = ($isAdmin || $currentRole === 'tribe_leader') ? $basePath . 'ip_members.php' : ($isIpMemberPage ? 'family_lineage.php' : 'ip_member/family_lineage.php');
$reportsHref = $basePath . 'reports.php';
$settingsHref = $basePath . 'settings.php';

$navClass = static function (string $key) use ($activeNav): string {
    if ($key === $activeNav) {
        return 'sidebar-item-active flex items-center gap-3 p-3 rounded-xl transition shadow-sm';
    }

    return 'flex items-center gap-3 p-3 text-muted hover:bg-gray-100 hover:text-[#262626] rounded-xl transition';
};
?>

<aside class="sidebar fixed top-0 bottom-0 left-0 p-4 w-64 overflow-y-auto bg-sidebar z-50">
    <div class="flex items-center gap-3 px-2 mb-10">
        <img src="<?php echo htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8'); ?>" class="w-10 h-10 object-contain" alt="logo">
        <span class="font-bold text-lg tracking-tight text-[#262626]">IP LINEAGE</span>
    </div>

    <nav class="space-y-1">
        <a href="<?php echo htmlspecialchars($dashboardHref, ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $navClass('dashboard'); ?>">
            <i data-lucide="layout-dashboard" class="w-5 h-5"></i>
            <span class="text-sm font-semibold">Dashboard</span>
        </a>

        <a href="<?php echo htmlspecialchars($profileHref, ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $navClass('profile'); ?>">
            <i data-lucide="user-round" class="w-5 h-5"></i>
            <span class="text-sm font-medium">Profile</span>
        </a>

        <?php if ($isAdmin): ?>
        <a href="<?php echo htmlspecialchars($userMgmtHref, ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $navClass('user_management'); ?>">
            <i data-lucide="user-cog" class="w-5 h-5"></i>
            <span class="text-sm font-medium">User Management</span>
        </a>

        <a href="<?php echo htmlspecialchars($lineageHref, ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $navClass('ip_members'); ?>">
            <i data-lucide="users" class="w-5 h-5"></i>
            <span class="text-sm font-medium">IP Members</span>
        </a>
        <?php endif; ?>

        <?php if (!$isAdmin): ?>
        <a href="<?php echo htmlspecialchars($lineageHref, ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $navClass('lineage_management'); ?>">
            <i data-lucide="users" class="w-5 h-5"></i>
            <span class="text-sm font-medium">Lineage Management</span>
        </a>
        <?php endif; ?>

        <a href="<?php echo htmlspecialchars($tribeProfileHref, ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $navClass('tribe_information'); ?>">
            <i data-lucide="map-pin" class="w-5 h-5"></i>
            <span class="text-sm font-medium">Tribe Information</span>
        </a>

        <?php if ($isAdmin || $currentRole === 'tribe_leader'): ?>
        <a href="<?php echo htmlspecialchars($reportsHref, ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $navClass('reports'); ?>">
            <i data-lucide="layers" class="w-5 h-5"></i>
            <span class="text-sm">Reports</span>
        </a>
        <?php endif; ?>

        <div class="pt-10 pb-2 px-3 text-[10px] uppercase tracking-widest text-gray-400 font-bold">System</div>

        <a href="<?php echo htmlspecialchars($settingsHref, ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $navClass('settings'); ?>">
            <i data-lucide="settings" class="w-5 h-5"></i>
            <span class="text-sm font-medium">Settings</span>
        </a>

        <a href="<?php echo htmlspecialchars($logoutHref, ENT_QUOTES, 'UTF-8'); ?>" class="flex items-center gap-3 p-3 text-red-600 hover:bg-red-50 rounded-xl transition mt-10">
            <i data-lucide="log-out" class="w-5 h-5"></i>
            <span class="text-sm font-medium">Logout</span>
        </a>
    </nav>
</aside>
