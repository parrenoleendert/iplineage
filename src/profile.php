<?php
require_once __DIR__ . '/auth/guards.php';
require_any_role(['admin', 'tribe_leader', 'ip_member']);

require_once __DIR__ . '/dbconfig.php';
$conn = $GLOBALS['conn'] ?? ($conn ?? null);
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection not established. Check src/dbconfig.php and MySQL service.');
}

function first_existing_column(array $columns, array $candidates): ?string {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

$currentRole = normalize_role((string) ($_SESSION['role'] ?? ''));

$dashboardOuterClass = 'ml-64 p-8';
$dashboardInnerClass = '';

$displayName = trim((string) ($_SESSION['name'] ?? 'User'));
if ($displayName === '') {
    $displayName = 'User';
}

$email = trim((string) ($_SESSION['email'] ?? ''));
$sessionRole = strtolower(trim((string) ($_SESSION['role'] ?? 'ip_member')));
$userId = (int) ($_SESSION['user_id'] ?? 0);

$roleLabel = 'IP Member';
if ($sessionRole === 'admin') {
    $roleLabel = 'System Admin';
} elseif ($sessionRole === 'tribe_leader') {
    $roleLabel = 'Tribe Leader';
}

$jurisdiction = '';
$status = 'Offline';
$lastActive = 'N/A';
$firstName = '';
$middleName = '';
$lastName = '';
$familyParents = [];
$familySpouses = [];
$familyChildren = [];
$errorMessage = '';
$successMessage = '';
$isEditMode = isset($_GET['edit']) && $_GET['edit'] === '1';

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

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['profile_action'])
    && $_POST['profile_action'] === 'save_profile'
) {
    if ($userId <= 0 || $idColumn === null) {
        $errorMessage = 'Unable to update profile right now.';
    } else {
        $postedFirstName = trim((string) ($_POST['first_name'] ?? ''));
        $postedMiddleName = trim((string) ($_POST['middle_name'] ?? ''));
        $postedLastName = trim((string) ($_POST['last_name'] ?? ''));
        $postedEmail = trim((string) ($_POST['email'] ?? ''));
        $postedJurisdiction = trim((string) ($_POST['jurisdiction'] ?? ''));
        $postedCurrentPassword = (string) ($_POST['current_password'] ?? '');
        $postedNewPassword = (string) ($_POST['new_password'] ?? '');
        $postedConfirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $combinedPostedName = trim(implode(' ', array_filter([$postedFirstName, $postedMiddleName, $postedLastName], static function ($value) {
            return $value !== '';
        })));
        $wantsPasswordChange = $postedCurrentPassword !== '' || $postedNewPassword !== '' || $postedConfirmPassword !== '';

        if ($postedFirstName === '' || $postedLastName === '') {
            $errorMessage = 'First Name and Last Name are required.';
            $isEditMode = true;
        } elseif ($postedEmail !== '' && filter_var($postedEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errorMessage = 'Please enter a valid email address.';
            $isEditMode = true;
        } elseif ($wantsPasswordChange && $passwordColumn === null) {
            $errorMessage = 'Password updates are not available in this setup.';
            $isEditMode = true;
        } elseif ($wantsPasswordChange && ($postedCurrentPassword === '' || $postedNewPassword === '' || $postedConfirmPassword === '')) {
            $errorMessage = 'To change password, fill in current, new, and confirm password fields.';
            $isEditMode = true;
        } elseif ($wantsPasswordChange && $postedNewPassword !== $postedConfirmPassword) {
            $errorMessage = 'New password and confirm password do not match.';
            $isEditMode = true;
        } elseif ($wantsPasswordChange && strlen($postedNewPassword) < 8) {
            $errorMessage = 'New password must be at least 8 characters.';
            $isEditMode = true;
        } else {
            $updates = [];
            $params = [];
            $types = '';

            if ($firstNameColumn !== null) {
                $updates[] = $firstNameColumn . ' = ?';
                $params[] = $postedFirstName;
                $types .= 's';
            }
            if ($middleNameColumn !== null) {
                $updates[] = $middleNameColumn . ' = ?';
                $params[] = $postedMiddleName;
                $types .= 's';
            }
            if ($lastNameColumn !== null) {
                $updates[] = $lastNameColumn . ' = ?';
                $params[] = $postedLastName;
                $types .= 's';
            }
            if ($nameColumn !== null) {
                $updates[] = $nameColumn . ' = ?';
                $params[] = $combinedPostedName;
                $types .= 's';
            }
            if ($emailColumn !== null) {
                $updates[] = $emailColumn . ' = ?';
                $params[] = $postedEmail;
                $types .= 's';
            }
            if ($jurisdictionColumn !== null) {
                $updates[] = $jurisdictionColumn . ' = ?';
                $params[] = $postedJurisdiction;
                $types .= 's';
            }

            if ($wantsPasswordChange) {
                $existingPassword = '';
                $passwordSql = 'SELECT ' . $passwordColumn . ' AS password_value FROM users WHERE ' . $idColumn . ' = ? LIMIT 1';
                $passwordStmt = $conn->prepare($passwordSql);
                if ($passwordStmt) {
                    $passwordStmt->bind_param('i', $userId);
                    $passwordStmt->execute();
                    $passwordResult = $passwordStmt->get_result();
                    $passwordRow = $passwordResult instanceof mysqli_result ? $passwordResult->fetch_assoc() : null;
                    $passwordStmt->close();
                    if ($passwordRow) {
                        $existingPassword = (string) ($passwordRow['password_value'] ?? '');
                    }
                }

                $currentPasswordValid = $existingPassword === ''
                    || password_verify($postedCurrentPassword, $existingPassword)
                    || hash_equals($existingPassword, $postedCurrentPassword);

                if (!$currentPasswordValid) {
                    $errorMessage = 'Current password is incorrect.';
                    $isEditMode = true;
                } else {
                    $updates[] = $passwordColumn . ' = ?';
                    $params[] = password_hash($postedNewPassword, PASSWORD_DEFAULT);
                    $types .= 's';
                }
            }

            if ($errorMessage === '' && empty($updates)) {
                $errorMessage = 'No editable profile fields are available in the users table.';
            } elseif ($errorMessage === '') {
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
                } else {
                    $errorMessage = 'Failed to save profile changes.';
                    $isEditMode = true;
                }
            }
        }
    }
}

if ($userId > 0 && $idColumn !== null) {
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
            $rowRole = strtolower(trim((string) ($row['role'] ?? '')));
            $rowJurisdiction = trim((string) ($row['jurisdiction'] ?? ''));
            $rowStatus = trim((string) ($row['status'] ?? ''));
            $rowLastActive = trim((string) ($row['last_active'] ?? ''));

            if ($rowFirstName !== '') $firstName = $rowFirstName;
            if ($rowMiddleName !== '') $middleName = $rowMiddleName;
            if ($rowLastName !== '') $lastName = $rowLastName;
            if ($rowName !== '') $displayName = $rowName;
            
            if ($displayName === '' && ($firstName !== '' || $middleName !== '' || $lastName !== '')) {
                $displayName = trim(implode(' ', array_filter([$firstName, $middleName, $lastName], static function ($value) {
                    return $value !== '';
                })));
            }
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

            if ($rowRole === 'admin' || $rowRole === 'system admin') {
                $roleLabel = 'System Admin';
            } elseif ($rowRole === 'tribe_leader' || $rowRole === 'tribe leader') {
                $roleLabel = 'Tribe Leader';
            } elseif ($rowRole !== '') {
                $roleLabel = ucwords(str_replace('_', ' ', $rowRole));
            }
        }
    } else {
        $errorMessage = 'Unable to load profile details from users table.';
    }
}

if ($firstName === '' || $lastName === '') {
    $namePartsFromDisplay = preg_split('/\s+/', trim($displayName));
    if ($firstName === '') $firstName = (string) ($namePartsFromDisplay[0] ?? '');
    if ($middleName === '' && count($namePartsFromDisplay) > 2) {
        $middleName = trim(implode(' ', array_slice($namePartsFromDisplay, 1, -1)));
    }
    if ($lastName === '' && count($namePartsFromDisplay) > 1) {
        $lastName = (string) $namePartsFromDisplay[count($namePartsFromDisplay) - 1];
    }
}

$ipMemberColumns = [];
$ipMemberColumnsResult = $conn->query('SHOW COLUMNS FROM ipmembers');
if ($ipMemberColumnsResult instanceof mysqli_result) {
    while ($ipColumn = $ipMemberColumnsResult->fetch_assoc()) {
        $ipMemberColumns[] = $ipColumn['Field'];
    }
}

$relationshipColumns = [];
$relationshipColumnsResult = $conn->query('SHOW COLUMNS FROM relationships');
if ($relationshipColumnsResult instanceof mysqli_result) {
    while ($relColumn = $relationshipColumnsResult->fetch_assoc()) {
        $relationshipColumns[] = $relColumn['Field'];
    }
}

$ipKeyColumn = first_existing_column($ipMemberColumns, ['ip_member_id', 'member_id', 'id']);
$ipUserColumn = first_existing_column($ipMemberColumns, ['user_id']);
$ipFirstNameColumn = first_existing_column($ipMemberColumns, ['first_name']);
$ipMiddleNameColumn = first_existing_column($ipMemberColumns, ['middle_name']);
$ipLastNameColumn = first_existing_column($ipMemberColumns, ['last_name']);
$ipLegacyNameColumn = first_existing_column($ipMemberColumns, ['member_name', 'full_name', 'name']);

$relMemberColumn = first_existing_column($relationshipColumns, ['ip_member_id', 'member_id', 'person_id']);
$relRelatedColumn = first_existing_column($relationshipColumns, ['related_person_id', 'related_member_id', 'relative_id']);
$relTypeColumn = first_existing_column($relationshipColumns, ['relationship_type', 'type']);

if ($ipKeyColumn !== null && $relMemberColumn !== null && $relRelatedColumn !== null && $relTypeColumn !== null) {
    $memberNameMap = [];
    $memberSelectParts = ["`{$ipKeyColumn}` AS member_key"];
    if ($ipFirstNameColumn !== null) $memberSelectParts[] = "`{$ipFirstNameColumn}` AS first_name";
    if ($ipMiddleNameColumn !== null) $memberSelectParts[] = "`{$ipMiddleNameColumn}` AS middle_name";
    if ($ipLastNameColumn !== null) $memberSelectParts[] = "`{$ipLastNameColumn}` AS last_name";
    if ($ipLegacyNameColumn !== null) $memberSelectParts[] = "`{$ipLegacyNameColumn}` AS legacy_name";

    $allMembersSql = 'SELECT ' . implode(', ', $memberSelectParts) . ' FROM ipmembers';
    $allMembersResult = $conn->query($allMembersSql);
    if ($allMembersResult instanceof mysqli_result) {
        while ($memberRow = $allMembersResult->fetch_assoc()) {
            $memberKey = (string) ($memberRow['member_key'] ?? '');
            if ($memberKey === '') continue;

            $memberFirst = trim((string) ($memberRow['first_name'] ?? ''));
            $memberMiddle = trim((string) ($memberRow['middle_name'] ?? ''));
            $memberLast = trim((string) ($memberRow['last_name'] ?? ''));
            $memberLegacy = trim((string) ($memberRow['legacy_name'] ?? ''));
            $resolvedName = trim(implode(' ', array_filter([$memberFirst, $memberMiddle, $memberLast], static function ($value) {
                return $value !== '';
            })));
            if ($resolvedName === '') {
                $resolvedName = $memberLegacy !== '' ? $memberLegacy : ('Member #' . $memberKey);
            }
            $memberNameMap[$memberKey] = $resolvedName;
        }
    }

    $currentMemberKey = '';
    if ($ipUserColumn !== null && $userId > 0) {
        $findMemberSql = "SELECT `{$ipKeyColumn}` AS member_key FROM ipmembers WHERE `{$ipUserColumn}` = ? LIMIT 1";
        $findMemberStmt = $conn->prepare($findMemberSql);
        if ($findMemberStmt) {
            $findMemberStmt->bind_param('i', $userId);
            $findMemberStmt->execute();
            $findMemberResult = $findMemberStmt->get_result();
            $findMemberRow = $findMemberResult instanceof mysqli_result ? $findMemberResult->fetch_assoc() : null;
            $findMemberStmt->close();

            if ($findMemberRow) {
                $currentMemberKey = (string) ($findMemberRow['member_key'] ?? '');
            }
        }
    }

    if ($currentMemberKey === '' && $displayName !== '') {
        $findByNameWhere = [];
        if ($ipLegacyNameColumn !== null) $findByNameWhere[] = "TRIM(`{$ipLegacyNameColumn}`) = ?";
        if ($ipFirstNameColumn !== null || $ipLastNameColumn !== null) {
            $firstExpr = $ipFirstNameColumn !== null ? "`{$ipFirstNameColumn}`" : "''";
            $middleExpr = $ipMiddleNameColumn !== null ? "`{$ipMiddleNameColumn}`" : "''";
            $lastExpr = $ipLastNameColumn !== null ? "`{$ipLastNameColumn}`" : "''";
            $findByNameWhere[] = "TRIM(CONCAT_WS(' ', {$firstExpr}, {$middleExpr}, {$lastExpr})) = ?";
        }

        if (!empty($findByNameWhere)) {
            $findByNameSql = "SELECT `{$ipKeyColumn}` AS member_key FROM ipmembers WHERE " . implode(' OR ', $findByNameWhere) . ' LIMIT 1';
            $findByNameStmt = $conn->prepare($findByNameSql);
            if ($findByNameStmt) {
                if (count($findByNameWhere) === 1) {
                    $findByNameStmt->bind_param('s', $displayName);
                } else {
                    $findByNameStmt->bind_param('ss', $displayName, $displayName);
                }
                $findByNameStmt->execute();
                $findByNameResult = $findByNameStmt->get_result();
                $findByNameRow = $findByNameResult instanceof mysqli_result ? $findByNameResult->fetch_assoc() : null;
                $findByNameStmt->close();

                if ($findByNameRow) {
                    $currentMemberKey = (string) ($findByNameRow['member_key'] ?? '');
                }
            }
        }
    }

    if ($currentMemberKey !== '') {
        $familySql = "SELECT `{$relMemberColumn}` AS source_id, `{$relRelatedColumn}` AS target_id, `{$relTypeColumn}` AS rel_type FROM relationships WHERE `{$relMemberColumn}` = ? OR `{$relRelatedColumn}` = ?";
        $familyStmt = $conn->prepare($familySql);
        if ($familyStmt) {
            $familyStmt->bind_param('ss', $currentMemberKey, $currentMemberKey);
            $familyStmt->execute();
            $familyResult = $familyStmt->get_result();

            $addUniqueName = static function (array &$bucket, string $name): void {
                if ($name === '') return;
                if (!in_array($name, $bucket, true)) {
                    $bucket[] = $name;
                }
            };

            while ($familyRow = $familyResult instanceof mysqli_result ? $familyResult->fetch_assoc() : null) {
                $sourceId = (string) ($familyRow['source_id'] ?? '');
                $targetId = (string) ($familyRow['target_id'] ?? '');
                $relType = strtolower(trim((string) ($familyRow['rel_type'] ?? '')));

                if ($sourceId === '' || $targetId === '') continue;

                $sourceName = (string) ($memberNameMap[$sourceId] ?? ('Member #' . $sourceId));
                $targetName = (string) ($memberNameMap[$targetId] ?? ('Member #' . $targetId));

                if (in_array($relType, ['parent', 'father', 'mother'], true)) {
                    if ($sourceId === $currentMemberKey) {
                        $addUniqueName($familyParents, $targetName);
                    } elseif ($targetId === $currentMemberKey) {
                        $addUniqueName($familyChildren, $sourceName);
                    }
                } elseif ($relType === 'child') {
                    if ($sourceId === $currentMemberKey) {
                        $addUniqueName($familyChildren, $targetName);
                    } elseif ($targetId === $currentMemberKey) {
                        $addUniqueName($familyParents, $sourceName);
                    }
                } elseif ($relType === 'spouse') {
                    if ($sourceId === $currentMemberKey) {
                        $addUniqueName($familySpouses, $targetName);
                    } elseif ($targetId === $currentMemberKey) {
                        $addUniqueName($familySpouses, $sourceName);
                    }
                }
            }
            $familyStmt->close();
        }
    }
}

$parentsLabel = !empty($familyParents) ? implode(', ', $familyParents) : 'None listed';
$spousesLabel = !empty($familySpouses) ? implode(', ', $familySpouses) : 'None listed';
$childrenLabel = !empty($familyChildren) ? implode(', ', $familyChildren) : 'None listed';

$nameParts = preg_split('/\s+/', $displayName);
$initials = strtoupper(substr((string) ($nameParts[0] ?? 'U'), 0, 1));
if (!empty($nameParts[1])) {
    $initials .= strtoupper(substr((string) $nameParts[1], 0, 1));
}

$statusClass = 'bg-neutral-100 text-neutral-600 border border-neutral-200/60';
if (strtolower($status) === 'online') {
    $statusClass = 'bg-emerald-50 text-emerald-700 border border-emerald-200/50';
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
    <title>IP Lineage - My Profile</title>
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

    <?php $activeNav = 'profile'; include __DIR__ . '/shared/sidebar.php'; ?>

    <div class="<?php echo $dashboardOuterClass; ?>">
        <div class="<?php echo $dashboardInnerClass; ?>">
        
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

            <!-- Notification Messages -->
            <?php if ($errorMessage !== ''): ?>
                <div class="mb-6 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50/60 p-4 text-sm text-red-800 backdrop-blur-sm animate-fade-in">
                    <i data-lucide="alert-circle" class="w-4 h-4 mt-0.5 flex-shrink-0 text-red-600"></i>
                    <div><span class="font-semibold">Action Required:</span> <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($successMessage !== ''): ?>
                <div class="mb-6 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50/60 p-4 text-sm text-emerald-800 backdrop-blur-sm animate-fade-in">
                    <i data-lucide="check-circle-2" class="w-4 h-4 mt-0.5 flex-shrink-0 text-emerald-600"></i>
                    <div><span class="font-semibold">Success:</span> <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            <?php endif; ?>

            <!-- Main Canvas Workspace Container -->
            <main class="bg-white border border-neutral-200 rounded-2xl shadow-[0_2px_8px_-3px_rgba(0,0,0,0.05)] overflow-hidden">

                <div class="h-44 md:h-52 bg-gradient-to-tr from-neutral-900 via-neutral-800 to-neutral-700 relative overflow-hidden">
                    <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-neutral-600/20 via-transparent to-transparent"></div>
                    <div class="absolute -bottom-16 -left-16 w-44 h-44 bg-white/5 rounded-full blur-xl"></div>
                </div>

                <div class="px-6 md:px-8 pb-8">
                    <!-- Identity Frame Layout (Fixed Overlap & Added z-10) -->
                    <div class="relative z-10 -mt-10 md:-mt-12 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 border-b border-neutral-100 pb-6">
                        <div class="flex items-end gap-4">
                            <div class="w-24 h-24 md:w-28 md:h-28 rounded-2xl border-4 border-white bg-neutral-900 text-neutral-100 flex items-center justify-center text-3xl font-bold uppercase shadow-md tracking-wider flex-shrink-0">
                                <?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div class="pb-1">
                                <h2 class="text-xl md:text-2xl font-bold text-neutral-900 tracking-tight leading-7"><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></h2>
                                <p class="text-xs font-medium text-neutral-500 mt-0.5"><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                        </div>

                        <div class="sm:pb-1">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 text-[10px] font-bold uppercase tracking-wider rounded-lg <?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?php echo strtolower($status) === 'online' ? 'bg-emerald-500' : 'bg-neutral-400'; ?>"></span>
                                <?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Split Profile Context Workspace -->
                    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                        
                        <!-- Left Wing Block (Sidebar Details) -->
                        <div class="space-y-4">
                            <!-- Lineage Panel Card -->
                            <div class="bg-neutral-50/60 border border-neutral-200/80 rounded-xl p-4 shadow-[inset_0_1px_2px_rgba(0,0,0,0.01)]">
                                <div class="flex items-center gap-2 pb-3 mb-3 border-b border-neutral-200/60">
                                    <i data-lucide="git-branch" class="w-3.5 h-3.5 text-neutral-500"></i>
                                    <h3 class="text-[11px] font-bold uppercase tracking-wider text-neutral-500">Family Lineage</h3>
                                </div>

                                <div class="space-y-3.5 text-xs">
                                    <div>
                                        <p class="text-[10px] font-medium text-neutral-400 uppercase tracking-wide">Parents</p>
                                        <p class="text-neutral-800 font-semibold mt-0.5"><?php echo htmlspecialchars($parentsLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-medium text-neutral-400 uppercase tracking-wide">Spouse</p>
                                        <p class="text-neutral-800 font-semibold mt-0.5"><?php echo htmlspecialchars($spousesLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-medium text-neutral-400 uppercase tracking-wide">Children</p>
                                        <p class="text-neutral-800 font-semibold mt-0.5"><?php echo htmlspecialchars($childrenLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- System Metadata Card -->
                            <div class="bg-white border border-neutral-200 rounded-xl p-4 shadow-sm space-y-3 text-xs">
                                <div class="flex items-center gap-2.5 text-neutral-600 hover:text-neutral-900 transition">
                                    <i data-lucide="mail" class="w-4 h-4 text-neutral-400 flex-shrink-0"></i>
                                    <span class="truncate font-medium"><?php echo htmlspecialchars($email !== '' ? $email : 'N/A', ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <div class="flex items-center gap-2.5 text-neutral-600 hover:text-neutral-900 transition">
                                    <i data-lucide="map-pin" class="w-4 h-4 text-neutral-400 flex-shrink-0"></i>
                                    <span class="font-medium"><?php echo htmlspecialchars($jurisdiction !== '' ? $jurisdiction : 'N/A', ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <div class="flex items-center gap-2.5 text-neutral-600 border-t border-neutral-100 pt-2.5 mt-1 text-[11px]">
                                    <i data-lucide="clock" class="w-3.5 h-3.5 text-neutral-400 flex-shrink-0"></i>
                                    <span class="text-neutral-400">Last Active: <span class="font-medium text-neutral-700"><?php echo htmlspecialchars($lastActive, ENT_QUOTES, 'UTF-8'); ?></span></span>
                                </div>
                            </div>
                        </div>

                        <!-- Right Wing Block (Form Context/View Setup) -->
                        <div class="lg:col-span-2">
                            
                            <?php if ($isEditMode): ?>
                                <!-- Form Mutation Layout Container -->
                                <form method="post" class="space-y-5 bg-neutral-50/40 border border-neutral-200 rounded-xl p-5 shadow-sm">
                                    <input type="hidden" name="profile_action" value="save_profile">

                                    <div class="flex items-center gap-2 pb-2 border-b border-neutral-200/80 mb-1">
                                        <i data-lucide="user-cog" class="w-4 h-4 text-neutral-800"></i>
                                        <h3 class="text-xs font-bold uppercase tracking-wider text-neutral-800">Modify Personal Details</h3>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div>
                                            <label for="first_name" class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">First Name</label>
                                            <input id="first_name" name="first_name" type="text" value="<?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 focus:outline-none focus:border-neutral-400 focus:ring-2 focus:ring-neutral-950/5 transition text-sm text-neutral-800 font-medium" required>
                                        </div>
                                        <div>
                                            <label for="middle_name" class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Middle Name</label>
                                            <input id="middle_name" name="middle_name" type="text" value="<?php echo htmlspecialchars($middleName, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 focus:outline-none focus:border-neutral-400 focus:ring-2 focus:ring-neutral-950/5 transition text-sm text-neutral-800 font-medium">
                                        </div>
                                        <div>
                                            <label for="last_name" class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Last Name</label>
                                            <input id="last_name" name="last_name" type="text" value="<?php echo htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 focus:outline-none focus:border-neutral-400 focus:ring-2 focus:ring-neutral-950/5 transition text-sm text-neutral-800 font-medium" required>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label for="email" class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Email Address</label>
                                            <input id="email" name="email" type="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 focus:outline-none focus:border-neutral-400 focus:ring-2 focus:ring-neutral-950/5 transition text-sm text-neutral-800 font-medium">
                                        </div>
                                        <div>
                                            <label for="jurisdiction" class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Jurisdiction Area</label>
                                            <input id="jurisdiction" name="jurisdiction" type="text" value="<?php echo htmlspecialchars($jurisdiction, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 focus:outline-none focus:border-neutral-400 focus:ring-2 focus:ring-neutral-950/5 transition text-sm text-neutral-800 font-medium">
                                        </div>
                                    </div>

                                    <div class="pt-4 border-t border-neutral-200">
                                        <div class="flex items-center gap-2 mb-3">
                                            <i data-lucide="shield-check" class="w-4 h-4 text-neutral-400"></i>
                                            <h4 class="text-[11px] font-bold uppercase tracking-wider text-neutral-500">Security Credentials</h4>
                                        </div>
                                        
                                        <div class="space-y-3.5">
                                            <div>
                                                <label for="current_password" class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Current Password</label>
                                                <input id="current_password" name="current_password" type="password" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 focus:outline-none focus:border-neutral-400 focus:ring-2 focus:ring-neutral-950/5 transition text-sm" autocomplete="current-password" placeholder="••••••••">
                                            </div>
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                <div>
                                                    <label for="new_password" class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">New Password</label>
                                                    <input id="new_password" name="new_password" type="password" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 focus:outline-none focus:border-neutral-400 focus:ring-2 focus:ring-neutral-950/5 transition text-sm" autocomplete="new-password" placeholder="At least 8 characters">
                                                </div>
                                                <div>
                                                    <label for="confirm_password" class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Confirm New Password</label>
                                                    <input id="confirm_password" name="confirm_password" type="password" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 focus:outline-none focus:border-neutral-400 focus:ring-2 focus:ring-neutral-950/5 transition text-sm" autocomplete="new-password" placeholder="Re-enter new password">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Action Elements Panel -->
                                    <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-neutral-200">
                                        <a href="profile.php" class="px-4 py-2 text-xs font-bold uppercase tracking-wider rounded-xl border border-neutral-200 text-neutral-700 bg-white hover:bg-neutral-50 shadow-sm transition">Cancel</a>
                                        <button type="submit" class="px-4 py-2 text-xs font-bold uppercase tracking-wider rounded-xl bg-neutral-900 text-white hover:bg-neutral-800 shadow-sm transition">Save Modifications</button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <!-- High Fidelity View Mode Grid Panel -->
                                <div class="bg-white border border-neutral-200 rounded-xl p-5 shadow-sm">
                                    <div class="flex items-center justify-between pb-3 mb-5 border-b border-neutral-100">
                                        <div class="flex items-center gap-2">
                                            <i data-lucide="layout-grid" class="w-4 h-4 text-neutral-800"></i>
                                            <h3 class="text-xs font-bold uppercase tracking-wider text-neutral-800">Account Registry Details</h3>
                                        </div>
                                        <a href="?edit=1" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider rounded-xl border border-neutral-200 text-neutral-700 hover:bg-neutral-50 shadow-sm transition">
                                            <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                                            Edit Profile
                                        </a>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-5 gap-x-4 text-xs">
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
                                            <p class="font-semibold text-neutral-800 text-sm truncate"><?php echo htmlspecialchars($email !== '' ? $email : 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <div class="bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3">
                                            <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-1">Assigned Jurisdiction</p>
                                            <p class="font-semibold text-neutral-800 text-sm"><?php echo htmlspecialchars($jurisdiction !== '' ? $jurisdiction : 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <div class="sm:col-span-2 bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3 flex items-center justify-between">
                                            <div>
                                                <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-0.5">Last System Activity Timestamp</p>
                                                <p class="font-semibold text-neutral-700"><?php echo htmlspecialchars($lastActive, ENT_QUOTES, 'UTF-8'); ?></p>
                                            </div>
                                            <i data-lucide="calendar" class="w-4 h-4 text-neutral-300 mr-1.5"></i>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>

                </div>
            </main>

        </div>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>