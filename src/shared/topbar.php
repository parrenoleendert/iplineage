<?php
// Get database connection
require_once __DIR__ . '/../dbconfig.php';
$conn = $GLOBALS['conn'] ?? ($conn ?? null);

/**
 * Helper function to find the first existing column from a list of candidates.
 */
if (!function_exists('first_existing_column')) {
    function first_existing_column(array $columns, array $candidates): ?string {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        return null;
    }
}

// Initialize variables with safe defaults
$initials = $initials ?? 'U';
$displayName = $displayName ?? 'User';
$roleLabel = $roleLabel ?? 'Member';
$statusClass = $statusClass ?? 'inline-flex items-center gap-1.5 px-3 py-1 text-[10px] font-bold uppercase tracking-wider rounded-lg bg-emerald-50 text-emerald-700';
$status = $status ?? 'online';
$parentsLabel = $parentsLabel ?? 'N/A';
$spousesLabel = $spousesLabel ?? 'N/A';
$childrenLabel = $childrenLabel ?? 'N/A';
$email = $email ?? '';
$jurisdiction = $jurisdiction ?? '';
$lastActive = $lastActive ?? 'N/A';
$isEditMode = $isEditMode ?? false;
$firstName = $firstName ?? '';
$middleName = $middleName ?? '';
$lastName = $lastName ?? '';
$errorMessage = '';
$successMessage = '';
$userId = (int) ($_SESSION['user_id'] ?? 0);
$sessionRole = strtolower(trim((string) ($_SESSION['role'] ?? 'ip_member')));

// Initialize database column name variables to prevent "undefined variable" errors
$idColumn = null;
$nameColumn = null;
$emailColumn = null;
$firstNameColumn = null;
$middleNameColumn = null;
$lastNameColumn = null;
$roleColumn = null;
$jurisdictionColumn = null;
$statusColumn = null;
$lastActiveColumn = null;
$passwordColumn = null;

// Check if edit mode is requested via URL
if (isset($_GET['edit']) && $_GET['edit'] === '1') {
    $isEditMode = true;
}

// Fetch user data from database
if ($userId > 0 && $conn instanceof mysqli) {
    $userColumns = [];
    $columnResult = $conn->query('SHOW COLUMNS FROM users');
    if ($columnResult instanceof mysqli_result) {
        while ($columnRow = $columnResult->fetch_assoc()) {
            $userColumns[] = $columnRow['Field'];
        }
    }

    $idColumn = first_existing_column($userColumns, ['user_id', 'id']);
    $nameColumn = first_existing_column($userColumns, ['full_name', 'name']);
    $emailColumn = first_existing_column($userColumns, ['email']);
    $firstNameColumn = first_existing_column($userColumns, ['first_name']);
    $middleNameColumn = first_existing_column($userColumns, ['middle_name', 'middle_initial']);
    $lastNameColumn = first_existing_column($userColumns, ['last_name']);
    $roleColumn = first_existing_column($userColumns, ['role']);
    $jurisdictionColumn = first_existing_column($userColumns, ['jurisdiction']);
    $statusColumn = first_existing_column($userColumns, ['status']);
    $lastActiveColumn = first_existing_column($userColumns, ['last_active', 'updated_at']);
    $passwordColumn = first_existing_column($userColumns, ['password_hash', 'password']);

    // Fetch user data
    $selectParts = [];
    $selectParts[] = $nameColumn !== null ? $nameColumn . ' AS full_name' : "'' AS full_name";
    $selectParts[] = $emailColumn !== null ? $emailColumn . ' AS email' : "'' AS email";
    $selectParts[] = $firstNameColumn !== null ? $firstNameColumn . ' AS first_name' : "'' AS first_name";
    $selectParts[] = $middleNameColumn !== null ? $middleNameColumn . ' AS middle_name' : "'' AS middle_name";
    $selectParts[] = $lastNameColumn !== null ? $lastNameColumn . ' AS last_name' : "'' AS last_name";
    $selectParts[] = $roleColumn !== null ? $roleColumn . ' AS role' : "'' AS role";
    $selectParts[] = $jurisdictionColumn !== null ? $jurisdictionColumn . ' AS jurisdiction' : "'' AS jurisdiction";
    $selectParts[] = $statusColumn !== null ? $statusColumn . ' AS status' : "'' AS status";
    $selectParts[] = $lastActiveColumn !== null ? $lastActiveColumn . ' AS last_active' : "'' AS last_active";

    $sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM users WHERE ' . $idColumn . ' = ? LIMIT 1';
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            $rowName = trim((string) ($row['full_name'] ?? ''));
            $rowEmail = trim((string) ($row['email'] ?? ''));
            $rowFirstName = trim((string) ($row['first_name'] ?? ''));
            $rowMiddleName = trim((string) ($row['middle_name'] ?? ''));
            $rowLastName = trim((string) ($row['last_name'] ?? ''));
            $rowJurisdiction = trim((string) ($row['jurisdiction'] ?? ''));
            $rowStatus = trim((string) ($row['status'] ?? ''));
            $rowLastActive = trim((string) ($row['last_active'] ?? ''));

            if ($rowFirstName !== '') $firstName = $rowFirstName;
            if ($rowMiddleName !== '') $middleName = $rowMiddleName;
            if ($rowLastName !== '') $lastName = $rowLastName;
            if ($rowName !== '') $displayName = $rowName;
            if ($rowEmail !== '') $email = $rowEmail;
            if ($rowJurisdiction !== '') $jurisdiction = $rowJurisdiction;
            if ($rowStatus !== '') $status = $rowStatus;
            
            if ($rowLastActive !== '') {
                $timestamp = strtotime($rowLastActive);
                if ($timestamp !== false) {
                    $lastActive = date('M d, Y h:i A', $timestamp);
                } else {
                    $lastActive = $rowLastActive;
                }
            }
        }
    }
}

// Handle profile save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['profile_action']) && $_POST['profile_action'] === 'save_profile' && $conn instanceof mysqli) {
    $postedFirstName = isset($_POST['first_name']) ? trim((string) $_POST['first_name']) : null;
    $postedMiddleName = isset($_POST['middle_name']) ? trim((string) $_POST['middle_name']) : null;
    $postedLastName = isset($_POST['last_name']) ? trim((string) $_POST['last_name']) : null;
    $postedEmail = isset($_POST['email']) ? trim((string) $_POST['email']) : null;
    $postedCurrentPassword = (string) ($_POST['current_password'] ?? '');
    $postedNewPassword = (string) ($_POST['new_password'] ?? '');
    $postedConfirmPassword = (string) ($_POST['confirm_password'] ?? '');

    $combinedPostedName = trim(implode(' ', array_filter([$postedFirstName, $postedMiddleName, $postedLastName], static function ($value) {
        return $value !== '';
    })));

    if (($postedFirstName !== null && $postedFirstName === '') || ($postedLastName !== null && $postedLastName === '')) {
        $errorMessage = 'First Name and Last Name are required.';
        $isEditMode = true;
    } elseif ($postedEmail !== '' && filter_var($postedEmail, FILTER_VALIDATE_EMAIL) === false) {
        $errorMessage = 'Please enter a valid email address.';
        $isEditMode = true;
    } else {
        $updates = [];
        $params = [];
        $types = '';

        if ($firstNameColumn !== null && $postedFirstName !== null) {
            $updates[] = $firstNameColumn . ' = ?';
            $params[] = $postedFirstName;
            $types .= 's';
        }
        if ($middleNameColumn !== null && $postedMiddleName !== null) {
            $updates[] = $middleNameColumn . ' = ?';
            $params[] = $postedMiddleName;
            $types .= 's';
        }
        if ($lastNameColumn !== null && $postedLastName !== null) {
            $updates[] = $lastNameColumn . ' = ?';
            $params[] = $postedLastName;
            $types .= 's';
        }
        if ($nameColumn !== null && ($postedFirstName !== null || $postedLastName !== null)) {
            $updates[] = $nameColumn . ' = ?';
            $params[] = $combinedPostedName;
            $types .= 's';
        }
        if ($emailColumn !== null && $postedEmail !== null) {
            $updates[] = $emailColumn . ' = ?';
            $params[] = $postedEmail;
            $types .= 's';
        }

        if (!empty($updates)) {
            $updateSql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE ' . $idColumn . ' = ? LIMIT 1';
            $updateStmt = $conn->prepare($updateSql);

            if ($updateStmt) {
                $params[] = $userId;
                $types .= 'i';
                $updateStmt->bind_param($types, ...$params);
                $updateStmt->execute();
                $updateStmt->close();

                $_SESSION['name'] = $combinedPostedName;
                if ($postedEmail !== '') {
                    $_SESSION['email'] = $postedEmail;
                }

                $successMessage = 'Profile updated successfully.';
                $isEditMode = false;
                
                // Refresh data
                $firstName = $postedFirstName;
                $middleName = $postedMiddleName;
                $lastName = $postedLastName;
                $displayName = $combinedPostedName;
                $email = $postedEmail;
            }
        }
    }
}
?>

<!-- ACCOUNT REGISTRY DETAILS -->
<div id="account-registry-drawer" class="fixed inset-y-0 right-0 w-full max-w-md bg-white shadow-2xl border-l border-[#dedede] z-[100] transform translate-x-full transition-transform duration-300 ease-in-out flex flex-col h-full">
    
    <!-- Drawer Header -->
    <div class="sticky top-0 bg-white border-b border-[#dedede] p-4 flex justify-between items-center z-20 flex-shrink-0">
        <h2 class="text-sm font-bold text-[#262626]">Account Details</h2>
        <button id="close-drawer-btn" class="p-2 hover:bg-gray-100 rounded-lg text-gray-400 hover:text-[#262626] transition" aria-label="Close panel">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>
    </div>

    <!-- Drawer Content -->
    <div class="flex-1 overflow-y-auto">

        <div class="relative bg-gradient-to-br from-[#262626] to-[#1a1a1a] p-6 pt-8 pb-8 overflow-hidden flex items-center gap-4">
            <div class="absolute inset-0 opacity-10">
                <div class="absolute -bottom-12 -right-12 w-48 h-48 bg-white rounded-full blur-3xl"></div>
            </div>
            
            <!-- User avatar -->
            <div class="relative z-10 w-16 h-16 rounded-xl bg-white/10 text-white flex items-center justify-center text-xl font-bold uppercase border border-white/20 flex-shrink-0">
                <?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div class="relative z-10">
                <h2 class="text-base font-bold text-white tracking-tight"><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="text-[11px] font-medium text-gray-400 mt-0.5 tracking-wider uppercase"><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>

        <div class="px-6 py-6">
            <div class="space-y-4">
                
                <?php if ($successMessage !== ''): ?>
                    <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-sm text-green-700">
                        <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <?php if ($errorMessage !== ''): ?>
                    <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-sm text-red-700">
                        <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <!-- Edit Form -->
                <form method="post" id="editProfileForm" class="space-y-4 bg-neutral-50/40 border border-neutral-200 rounded-xl p-5 shadow-sm hidden">
                    <input type="hidden" name="profile_action" value="save_profile">

                    <div class="flex items-center gap-2 pb-3 border-b border-neutral-200">
                        <i data-lucide="user-cog" class="w-4 h-4 text-neutral-800"></i>
                        <h3 class="text-xs font-bold uppercase tracking-wider text-neutral-800">Edit Profile</h3>
                    </div>

                    <div class="grid grid-cols-1 gap-3">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">First Name</label>
                            <input name="first_name" type="text" value="<?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-lg py-2 px-3 focus:outline-none focus:border-neutral-400 focus:ring-1 focus:ring-neutral-400 text-sm" required>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Middle Name</label>
                            <input name="middle_name" type="text" value="<?php echo htmlspecialchars($middleName, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-lg py-2 px-3 focus:outline-none focus:border-neutral-400 focus:ring-1 focus:ring-neutral-400 text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Last Name</label>
                            <input name="last_name" type="text" value="<?php echo htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-lg py-2 px-3 focus:outline-none focus:border-neutral-400 focus:ring-1 focus:ring-neutral-400 text-sm" required>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Email Address</label>
                            <input name="email" type="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-lg py-2 px-3 focus:outline-none focus:border-neutral-400 focus:ring-1 focus:ring-neutral-400 text-sm">
                        </div>
                    </div>

                    <div class="pt-4 border-neutral-200">
                        <div class="flex items-center gap-2 mb-3">
                            <i data-lucide="shield-check" class="w-4 h-4 text-neutral-400"></i>
                            <h4 class="text-[11px] font-bold uppercase tracking-wider text-neutral-500">Security Credentials</h4>
                        </div>
                        
                        <div class="space-y-3.5 mb-4">
                            <div>
                                <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Current Password</label>
                                <input name="current_password" type="password" class="w-full bg-white border border-neutral-200 rounded-lg py-2 px-3 focus:outline-none focus:border-neutral-400 focus:ring-1 focus:ring-neutral-400 text-sm" autocomplete="current-password" placeholder="••••••••">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">New Password</label>
                                <input name="new_password" type="password" class="w-full bg-white border border-neutral-200 rounded-lg py-2 px-3 focus:outline-none focus:border-neutral-400 focus:ring-1 focus:ring-neutral-400 text-sm" autocomplete="new-password" placeholder="At least 8 characters">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Confirm New Password</label>
                                <input name="confirm_password" type="password" class="w-full bg-white border border-neutral-200 rounded-lg py-2 px-3 focus:outline-none focus:border-neutral-400 focus:ring-1 focus:ring-neutral-400 text-sm" autocomplete="new-password" placeholder="Re-enter new password">
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 pt-4 border-t border-neutral-200 mt-4">
                        <button type="button" onclick="toggleEditMode()" class="flex-1 px-3 py-2 text-xs font-bold uppercase text-center rounded-lg border border-neutral-200 text-neutral-700 hover:bg-neutral-50 transition">Cancel</button>
                        <button type="submit" class="flex-1 px-3 py-2 text-xs font-bold uppercase text-center rounded-lg bg-neutral-900 text-white hover:bg-neutral-800 transition">Save Changes</button>
                    </div>
                </form>
            
                <!-- View Mode - Account Registry Details -->
                <div id="viewProfileMode" class="bg-white border border-neutral-200 rounded-xl p-5 shadow-sm">
                    <div class="flex items-center justify-between pb-3 mb-5 border-b border-neutral-100">
                        <div class="flex items-center gap-2">
                            <i data-lucide="layout-grid" class="w-4 h-4 text-neutral-800"></i>
                            <h3 class="text-xs font-bold uppercase tracking-wider text-neutral-800">Account Registry Details</h3>
                        </div>
                        <button type="button" onclick="toggleEditMode()" class="inline-flex items-center gap-1.5 px-2 py-1 text-[10px] font-bold uppercase tracking-wider rounded-lg border border-neutral-200 text-neutral-700 hover:bg-neutral-50 shadow-sm transition">
                            <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                            Edit
                        </button>
                    </div>

                    <div class="grid grid-cols-1 gap-4 text-xs">
                        <div class="bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3">
                            <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-1">Full Legal Name</p>
                            <p class="font-semibold text-neutral-800 text-sm"><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                        <div class="bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3">
                            <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-1">System Privilege Level</p>
                            <p class="font-semibold text-neutral-800 text-sm"><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                        <div class="bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3">
                            <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-1">Email Address</p>
                            <p class="font-semibold text-neutral-800 text-sm truncate"><?php echo htmlspecialchars($email !== '' ? $email : 'Not set', ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                        <div class="bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3">
                            <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-1">Assigned Jurisdiction</p>
                            <p class="font-semibold text-neutral-800 text-sm"><?php echo htmlspecialchars($jurisdiction !== '' ? $jurisdiction : 'Not set', ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                        <div class="bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3 flex items-center justify-between">
                            <div>
                                <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-0.5">Last System Activity Timestamp</p>
                                <p class="font-semibold text-neutral-700 text-xs"><?php echo htmlspecialchars($lastActive, ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <i data-lucide="calendar" class="w-4 h-4 text-neutral-300 mr-1.5"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- STICKY BOTTOM BAR -->
    <div class="sticky bottom-0 bg-white border-t border-[#dedede] p-4 flex-shrink-0 z-20 shadow-[0_-4px_12px_rgba(0,0,0,0.03)]">
        <button type="button" onclick="document.getElementById('drawer-overlay').click()" class="w-full px-4 py-2.5 text-xs font-bold uppercase text-center rounded-lg bg-[#262626] text-white hover:bg-gray-800 transition shadow-sm">Close Panel</button>
    </div>

</div>

<!-- Backdrop Overlay dimming filter layer -->
<div id="drawer-overlay" class="fixed inset-0 bg-black/20 backdrop-blur-xs z-[95] hidden transition-all"></div>

<!-- Interactive Toggle Logic Trigger script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const drawerTrigger = document.getElementById('profile-drawer-trigger');
    const registryDrawer = document.getElementById('account-registry-drawer');
    const drawerOverlay = document.getElementById('drawer-overlay');
    const closeDrawerBtn = document.getElementById('close-drawer-btn');

    function openRegistryDrawer() {
        if (registryDrawer && drawerOverlay) {
            registryDrawer.classList.remove('translate-x-full');
            drawerOverlay.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }
    }

    function closeRegistryDrawer() {
        if (registryDrawer && drawerOverlay) {
            registryDrawer.classList.add('translate-x-full');
            drawerOverlay.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
    }

    if (drawerTrigger) drawerTrigger.addEventListener('click', openRegistryDrawer);
    if (closeDrawerBtn) closeDrawerBtn.addEventListener('click', closeRegistryDrawer);
    if (drawerOverlay) drawerOverlay.addEventListener('click', closeRegistryDrawer);
});

// Toggle edit mode smoothly without page refresh
function toggleEditMode() {
    const editForm = document.getElementById('editProfileForm');
    const viewMode = document.getElementById('viewProfileMode');
    
    if (editForm && viewMode) {
        editForm.classList.toggle('hidden');
        viewMode.classList.toggle('hidden');
    }
}
</script>