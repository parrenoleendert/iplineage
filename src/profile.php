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

// Replace these with actual database fetches once columns are added to your users or ipmembers table
$gender = 'Male';
$dob = 'May 12, 1998';
$tribe = 'Ati';

$mobileNumber = '+63 917 123 4567';
$province = 'Antique';
$municipality = 'Hamtic';
$barangay = 'Poblacion';
$currentAddress = 'Sitio Sunflower, Barangay Poblacion';

$civilStatus = 'Married';
$educationalAttainment = 'College Graduate (BS Information Technology)';

// --- DATABASE COLUMN DETECTIONS ---
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

// --- POST METHOD FORM SAVING ---
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['profile_action'])
    && $_POST['profile_action'] === 'save_profile'
) {
    if ($userId <= 0 || $idColumn === null) {
        $errorMessage = 'Unable to update profile right now.';
    } else {
        $postedFirstName = isset($_POST['first_name']) ? trim((string) $_POST['first_name']) : null;
        $postedMiddleName = isset($_POST['middle_name']) ? trim((string) $_POST['middle_name']) : null;
        $postedLastName = isset($_POST['last_name']) ? trim((string) $_POST['last_name']) : null;
        $postedEmail = isset($_POST['email']) ? trim((string) $_POST['email']) : null;
        $postedCurrentPassword = (string) ($_POST['current_password'] ?? '');
        $postedNewPassword = (string) ($_POST['new_password'] ?? '');
        $postedConfirmPassword = (string) ($_POST['confirm_password'] ?? '');

        // Gather additional registration details from post request
        $gender = isset($_POST['gender']) ? trim((string) $_POST['gender']) : $gender;
        $dob = isset($_POST['dob']) ? trim((string) $_POST['dob']) : $dob;
        $tribe = isset($_POST['tribe']) ? trim((string) $_POST['tribe']) : $tribe;
        $mobileNumber = isset($_POST['mobile_number']) ? trim((string) $_POST['mobile_number']) : $mobileNumber;
        $province = isset($_POST['province']) ? trim((string) $_POST['province']) : $province;
        $municipality = isset($_POST['municipality']) ? trim((string) $_POST['municipality']) : $municipality;
        $barangay = isset($_POST['barangay']) ? trim((string) $_POST['barangay']) : $barangay;
        $currentAddress = isset($_POST['current_address']) ? trim((string) $_POST['current_address']) : $currentAddress;
        $civilStatus = isset($_POST['civil_status']) ? trim((string) $_POST['civil_status']) : $civilStatus;
        $educationalAttainment = isset($_POST['educational_attainment']) ? trim((string) $_POST['educational_attainment']) : $educationalAttainment;

        $combinedPostedName = trim(implode(' ', array_filter([$postedFirstName, $postedMiddleName, $postedLastName], static function ($value) {
            return $value !== '';
        })));
        $wantsPasswordChange = $postedCurrentPassword !== '' || $postedNewPassword !== '' || $postedConfirmPassword !== '';

        if (($postedFirstName !== null && $postedFirstName === '') || ($postedLastName !== null && $postedLastName === '')) {
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
                $successMessage = 'Profile options simulated and updated successfully.';
                $isEditMode = false;
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

// --- RE-FETCH USER BASE INFO ---
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
        }
    }
}

if ($firstName === '' || $lastName === '') {
    $namePartsFromDisplay = preg_split('/\s+/', trim($displayName));
    if ($firstName === '') $firstName = (string) ($namePartsFromDisplay[0] ?? '');
    if ($lastName === '' && count($namePartsFromDisplay) > 1) {
        $lastName = (string) $namePartsFromDisplay[count($namePartsFromDisplay) - 1];
    }
}

// --- FETCH LINEAGE / RELATIONSHIPS ---
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

$parentsLabel = !empty($familyParents) ? implode(', ', $familyParents) : 'Roberto Parreño, Elena Parreño';
$spousesLabel = !empty($familySpouses) ? implode(', ', $familySpouses) : 'Joralyn Millan';
$childrenLabel = !empty($familyChildren) ? implode(', ', $familyChildren) : 'Leonlyn Parreño, Ranz Parreño';

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
    <?php include __DIR__ . '/shared/topbar.php'; ?>

    <div class="<?php echo $dashboardOuterClass; ?>">
        <div class="<?php echo $dashboardInnerClass; ?>">
        
            <!-- Header Navigation Bar -->
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
                    <button id="profile-drawer-trigger" class="flex items-center gap-3 bg-white border border-[#dedede] p-1.5 pr-4 rounded-xl shadow-sm hover:border-gray-400 transition cursor-pointer">
                        <div class="w-8 h-8 rounded-lg bg-[#262626] text-[#f3f4f1] flex items-center justify-center font-bold text-xs uppercase"><?php echo htmlspecialchars($initials); ?></div>
                        <div>
                            <p class="text-xs font-bold leading-none text-[#262626]"><?php echo htmlspecialchars($displayName); ?></p>
                            <p class="text-[10px] text-gray-400 uppercase tracking-tighter"><?php echo htmlspecialchars($roleLabel); ?></p>
                        </div>
                    </button>
                </div>
            </header>

            <!-- Alert Notification Modals -->
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

            <!-- Main Profile Frame Canvas Layout -->
            <main class="bg-white border border-neutral-200 rounded-2xl shadow-[0_2px_8px_-3px_rgba(0,0,0,0.05)] overflow-hidden">

                <!-- Decorative Minimal Top Banner Section -->
                <div class="h-44 md:h-52 bg-gradient-to-tr from-neutral-900 via-neutral-800 to-neutral-700 relative overflow-hidden">
                    <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-neutral-600/20 via-transparent to-transparent"></div>
                    <div class="absolute -bottom-16 -left-16 w-44 h-44 bg-white/5 rounded-full blur-xl"></div>
                </div>

                <div class="px-6 md:px-8 pb-8">
                    
                    <!-- Identity Overlay Header Line -->
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

                        <div class="flex sm:pb-1 gap-2">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 text-[10px] font-bold uppercase tracking-wider rounded-lg <?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?php echo strtolower($status) === 'online' ? 'bg-emerald-500' : 'bg-neutral-400'; ?>"></span>
                                <?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Unified Submission Form Core Wrapper -->
                    <form method="post" class="mt-6">
                        <input type="hidden" name="profile_action" value="save_profile">

                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                            
                            <!-- Left Wing Sidebar Modules -->
                            <div class="space-y-6">
                                <!-- Family Lineage Block -->
                                <div class="bg-white border border-neutral-200 rounded-xl p-5 shadow-sm">
                                    <div class="flex items-center gap-2 pb-3 mb-4 border-b border-neutral-100">
                                        <i data-lucide="git-branch" class="w-4 h-4 text-neutral-800"></i>
                                        <h3 class="text-xs font-bold uppercase tracking-wider text-neutral-800">Family Lineage</h3>
                                    </div>
                                    <div class="space-y-4 text-xs">
                                        <div class="bg-neutral-50/60 rounded-xl p-3 border border-neutral-100">
                                            <p class="text-[9px] font-bold text-neutral-400 uppercase tracking-wide">Parents</p>
                                            <p class="text-neutral-800 font-semibold mt-0.5"><?php echo htmlspecialchars($parentsLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <div class="bg-neutral-50/60 rounded-xl p-3 border border-neutral-100">
                                            <p class="text-[9px] font-bold text-neutral-400 uppercase tracking-wide">Spouse</p>
                                            <p class="text-neutral-800 font-semibold mt-0.5"><?php echo htmlspecialchars($spousesLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <div class="bg-neutral-50/60 rounded-xl p-3 border border-neutral-100">
                                            <p class="text-[9px] font-bold text-neutral-400 uppercase tracking-wide">Children</p>
                                            <p class="text-neutral-800 font-semibold mt-0.5"><?php echo htmlspecialchars($childrenLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                    </div>
                                </div>

                                <!-- System Metadata Tracking Row -->
                                <div class="bg-white border border-neutral-200 rounded-xl p-4 shadow-sm text-xs space-y-2.5">
                                    <div class="flex items-center gap-2 text-neutral-500 text-[11px]">
                                        <i data-lucide="clock" class="w-3.5 h-3.5 text-neutral-400"></i>
                                        <span>Last Active: <span class="font-semibold text-neutral-700"><?php echo htmlspecialchars($lastActive, ENT_QUOTES, 'UTF-8'); ?></span></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Right Wing Columns: Primary Structural Data Units -->
                            <div class="lg:col-span-2 space-y-6">

                                <!-- CONTAINER 1: Full Name & Identity Details -->
                                <div class="bg-white border border-neutral-200 rounded-xl p-5 shadow-sm transition-all duration-200" id="container-identity">
                                    <div class="flex items-center justify-between pb-3 mb-4 border-b border-neutral-100">
                                        <div class="flex items-center gap-2">
                                            <i data-lucide="user" class="w-4 h-4 text-neutral-800"></i>
                                            <h3 class="text-xs font-bold uppercase tracking-wider text-neutral-800">Full Name & Identity</h3>
                                        </div>
                                        
                                        <!-- Action triggers managed via JavaScript -->
                                        <div class="flex items-center gap-2">
                                            <button type="button" onclick="toggleBlockEdit('identity')" class="btn-edit inline-flex items-center gap-1 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-neutral-500 hover:text-neutral-900 border border-neutral-200 rounded-lg bg-neutral-50 hover:bg-neutral-100 transition shadow-sm">
                                                <i data-lucide="edit-3" class="w-3 h-3"></i> Edit
                                            </button>
                                            <div class="btn-actions hidden flex items-center gap-1.5">
                                                <button type="button" onclick="toggleBlockEdit('identity')" class="px-2 py-1 text-[10px] font-bold uppercase tracking-wider rounded-lg border border-neutral-200 text-neutral-700 bg-white hover:bg-neutral-50 transition">Cancel</button>
                                                <button type="submit" class="px-2 py-1 text-[10px] font-bold uppercase tracking-wider rounded-lg bg-neutral-900 text-white hover:bg-neutral-800 transition">Save Identity</button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Read-Only Mode Block -->
                                    <div class="view-mode grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                                        <div class="bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3">
                                            <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-0.5">Full Legal Name</p>
                                            <p class="font-semibold text-neutral-800 text-sm"><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <div class="bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3">
                                            <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-0.5">Gender Assignment</p>
                                            <p class="font-semibold text-neutral-800 text-sm"><?php echo htmlspecialchars($gender, ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <div class="bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3">
                                            <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-0.5">Date of Birth</p>
                                            <p class="font-semibold text-neutral-800 text-sm"><?php echo htmlspecialchars($dob, ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <div class="bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3">
                                            <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-0.5">Indigenous Affiliated Tribe</p>
                                            <p class="font-semibold text-neutral-800 text-sm"><?php echo htmlspecialchars($tribe, ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                    </div>

                                    <!-- Form Input Edit Mode Block -->
                                    <div class="edit-mode hidden space-y-4">
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                            <div>
                                                <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">First Name</label>
                                                <input name="first_name" type="text" value="<?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 text-xs text-neutral-800 focus:outline-none focus:ring-2 focus:ring-neutral-900/10">
                                            </div>
                                            <div>
                                                <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Middle Name</label>
                                                <input name="middle_name" type="text" value="<?php echo htmlspecialchars($middleName, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 text-xs text-neutral-800 focus:outline-none focus:ring-2 focus:ring-neutral-900/10">
                                            </div>
                                            <div>
                                                <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Last Name</label>
                                                <input name="last_name" type="text" value="<?php echo htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 text-xs text-neutral-800 focus:outline-none focus:ring-2 focus:ring-neutral-900/10">
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                            <div>
                                                <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Gender Assignment</label>
                                                <select name="gender" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 text-xs text-neutral-800 focus:outline-none focus:ring-2 focus:ring-neutral-900/10">
                                                    <option value="Male" <?php echo $gender === 'Male' ? 'selected' : ''; ?>>Male</option>
                                                    <option value="Female" <?php echo $gender === 'Female' ? 'selected' : ''; ?>>Female</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Date of Birth</label>
                                                <input name="dob" type="text" value="<?php echo htmlspecialchars($dob, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 text-xs text-neutral-800 focus:outline-none focus:ring-2 focus:ring-neutral-900/10">
                                            </div>
                                            <div>
                                                <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Indigenous Tribe</label>
                                                <input name="tribe" type="text" value="<?php echo htmlspecialchars($tribe, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 text-xs text-neutral-800 focus:outline-none focus:ring-2 focus:ring-neutral-900/10">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- CONTAINER 2: Address & Contact Framework -->
                                <div class="bg-white border border-neutral-200 rounded-xl p-5 shadow-sm transition-all duration-200" id="container-address">
                                    <div class="flex items-center justify-between pb-3 mb-4 border-b border-neutral-100">
                                        <div class="flex items-center gap-2">
                                            <i data-lucide="map-pin" class="w-4 h-4 text-neutral-800"></i>
                                            <h3 class="text-xs font-bold uppercase tracking-wider text-neutral-800">Address & Contact Details</h3>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <button type="button" onclick="toggleBlockEdit('address')" class="btn-edit inline-flex items-center gap-1 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-neutral-500 hover:text-neutral-900 border border-neutral-200 rounded-lg bg-neutral-50 hover:bg-neutral-100 transition shadow-sm">
                                                <i data-lucide="edit-3" class="w-3 h-3"></i> Edit
                                            </button>
                                            <div class="btn-actions hidden flex items-center gap-1.5">
                                                <button type="button" onclick="toggleBlockEdit('address')" class="px-2 py-1 text-[10px] font-bold uppercase tracking-wider rounded-lg border border-neutral-200 text-neutral-700 bg-white hover:bg-neutral-50 transition">Cancel</button>
                                                <button type="submit" class="px-2 py-1 text-[10px] font-bold uppercase tracking-wider rounded-lg bg-neutral-900 text-white hover:bg-neutral-800 transition">Save Address</button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="view-mode grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                                        <div class="bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3">
                                            <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-0.5">Mobile Phone Number</p>
                                            <p class="font-semibold text-neutral-800 text-sm"><?php echo htmlspecialchars($mobileNumber, ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <div class="bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3">
                                            <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-0.5">Email Address</p>
                                            <p class="font-semibold text-neutral-800 text-sm truncate"><?php echo htmlspecialchars($email !== '' ? $email : 'None Listed', ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <div class="bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3">
                                            <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-0.5">Province</p>
                                            <p class="font-semibold text-neutral-800 text-sm"><?php echo htmlspecialchars($province, ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <div class="bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3">
                                            <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-0.5">Municipality / City</p>
                                            <p class="font-semibold text-neutral-800 text-sm"><?php echo htmlspecialchars($municipality, ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <div class="bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3">
                                            <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-0.5">Barangay Sector</p>
                                            <p class="font-semibold text-neutral-800 text-sm"><?php echo htmlspecialchars($barangay, ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <div class="bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3">
                                            <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-0.5">Specific Home Address</p>
                                            <p class="font-semibold text-neutral-800 text-sm truncate"><?php echo htmlspecialchars($currentAddress, ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                    </div>

                                    <div class="edit-mode hidden space-y-4">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Mobile Number</label>
                                                <input name="mobile_number" type="text" value="<?php echo htmlspecialchars($mobileNumber, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 text-xs text-neutral-800 focus:outline-none focus:ring-2 focus:ring-neutral-900/10">
                                            </div>
                                            <div>
                                                <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Email Address</label>
                                                <input name="email" type="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 text-xs text-neutral-800 focus:outline-none focus:ring-2 focus:ring-neutral-900/10">
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                            <div>
                                                <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Province</label>
                                                <input name="province" type="text" value="<?php echo htmlspecialchars($province, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 text-xs text-neutral-800 focus:outline-none focus:ring-2 focus:ring-neutral-900/10">
                                            </div>
                                            <div>
                                                <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Municipality</label>
                                                <input name="municipality" type="text" value="<?php echo htmlspecialchars($municipality, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 text-xs text-neutral-800 focus:outline-none focus:ring-2 focus:ring-neutral-900/10">
                                            </div>
                                            <div>
                                                <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Barangay</label>
                                                <input name="barangay" type="text" value="<?php echo htmlspecialchars($barangay, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 text-xs text-neutral-800 focus:outline-none focus:ring-2 focus:ring-neutral-900/10">
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Specific Current Address</label>
                                            <input name="current_address" type="text" value="<?php echo htmlspecialchars($currentAddress, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 text-xs text-neutral-800 focus:outline-none focus:ring-2 focus:ring-neutral-900/10">
                                        </div>
                                    </div>
                                </div>

                                <!-- CONTAINER 3: Background & Attainment Context -->
                                <div class="bg-white border border-neutral-200 rounded-xl p-5 shadow-sm transition-all duration-200" id="container-background">
                                    <div class="flex items-center justify-between pb-3 mb-4 border-b border-neutral-100">
                                        <div class="flex items-center gap-2">
                                            <i data-lucide="file-text" class="w-4 h-4 text-neutral-800"></i>
                                            <h3 class="text-xs font-bold uppercase tracking-wider text-neutral-800">Background Information</h3>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <button type="button" onclick="toggleBlockEdit('background')" class="btn-edit inline-flex items-center gap-1 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-neutral-500 hover:text-neutral-900 border border-neutral-200 rounded-lg bg-neutral-50 hover:bg-neutral-100 transition shadow-sm">
                                                <i data-lucide="edit-3" class="w-3 h-3"></i> Edit
                                            </button>
                                            <div class="btn-actions hidden flex items-center gap-1.5">
                                                <button type="button" onclick="toggleBlockEdit('background')" class="px-2 py-1 text-[10px] font-bold uppercase tracking-wider rounded-lg border border-neutral-200 text-neutral-700 bg-white hover:bg-neutral-50 transition">Cancel</button>
                                                <button type="submit" class="px-2 py-1 text-[10px] font-bold uppercase tracking-wider rounded-lg bg-neutral-900 text-white hover:bg-neutral-800 transition">Save Info</button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="view-mode grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                                        <div class="bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3">
                                            <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-0.5">Civil Status</p>
                                            <p class="font-semibold text-neutral-800 text-sm"><?php echo htmlspecialchars($civilStatus, ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <div class="bg-neutral-50/40 border border-neutral-200/40 rounded-xl p-3">
                                            <p class="text-neutral-400 uppercase text-[9px] font-bold tracking-wider mb-0.5">Educational Attainment</p>
                                            <p class="font-semibold text-neutral-800 text-sm"><?php echo htmlspecialchars($educationalAttainment, ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                    </div>

                                    <div class="edit-mode hidden grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Civil Status</label>
                                            <input name="civil_status" type="text" value="<?php echo htmlspecialchars($civilStatus, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 text-xs text-neutral-800 focus:outline-none focus:ring-2 focus:ring-neutral-900/10">
                                        </div>
                                        <div>
                                            <label class="block text-[10px] font-bold uppercase tracking-wider text-neutral-500 mb-1.5">Educational Attainment</label>
                                            <input name="educational_attainment" type="text" value="<?php echo htmlspecialchars($educationalAttainment, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-neutral-200 rounded-xl py-2 px-3.5 text-xs text-neutral-800 focus:outline-none focus:ring-2 focus:ring-neutral-900/10">
                                        </div>
                                    </div>
                                </div>

                                <!-- CONTAINER 4: Registered Documents & Requirements Attachments -->
                                <div class="bg-white border border-neutral-200 rounded-xl p-5 shadow-sm transition-all duration-200" id="container-documents">
                                    <div class="flex items-center justify-between pb-3 mb-4 border-b border-neutral-100">
                                        <div class="flex items-center gap-2">
                                            <i data-lucide="folder-open" class="w-4 h-4 text-neutral-800"></i>
                                            <h3 class="text-xs font-bold uppercase tracking-wider text-neutral-800">Attached Registry Documents</h3>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <button type="button" onclick="toggleBlockEdit('documents')" class="btn-edit inline-flex items-center gap-1 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-neutral-500 hover:text-neutral-900 border border-neutral-200 rounded-lg bg-neutral-50 hover:bg-neutral-100 transition shadow-sm">
                                                <i data-lucide="edit-3" class="w-3 h-3"></i> Edit Docs
                                            </button>
                                            <div class="btn-actions hidden flex items-center gap-1.5">
                                                <button type="button" onclick="toggleBlockEdit('documents')" class="px-2 py-1 text-[10px] font-bold uppercase tracking-wider rounded-lg border border-neutral-200 text-neutral-700 bg-white hover:bg-neutral-50 transition">Cancel</button>
                                                <button type="submit" class="px-2 py-1 text-[10px] font-bold uppercase tracking-wider rounded-lg bg-neutral-900 text-white hover:bg-neutral-800 transition">Save Docs</button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                                        <!-- NCIP Form Attachment Field -->
                                        <div class="p-3 border border-neutral-200 rounded-xl hover:bg-neutral-50/50 transition flex flex-col justify-center min-h-[62px]">
                                            <div class="edit-mode hidden">
                                                <label class="block text-[9px] font-bold uppercase tracking-wider text-neutral-400 mb-1">Update NCIP Registration Form</label>
                                                <input type="file" name="ncip_form" class="w-full text-xs text-neutral-500 file:mr-2 file:py-1 file:px-2 file:rounded-md file:border-0 file:text-[10px] file:font-bold file:uppercase file:bg-neutral-100 file:text-neutral-700 hover:file:bg-neutral-200">
                                            </div>
                                            <div class="view-mode flex items-center justify-between w-full">
                                                <div class="flex items-center gap-3 min-w-0">
                                                    <div class="w-8 h-8 rounded-lg bg-neutral-100 text-neutral-600 flex items-center justify-center flex-shrink-0">
                                                        <i data-lucide="file-check" class="w-4 h-4"></i>
                                                    </div>
                                                    <div class="truncate">
                                                        <p class="font-semibold text-neutral-800 leading-tight truncate">NCIP_Registration_Form.pdf</p>
                                                        <p class="text-[10px] text-neutral-400 mt-0.5">Verified Official Form</p>
                                                    </div>
                                                </div>
                                                <button type="button" class="p-1.5 hover:bg-neutral-200/60 text-neutral-500 rounded-lg transition" title="Download Document">
                                                    <i data-lucide="download" class="w-4 h-4"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Birth Certificate Attachment Field -->
                                        <div class="p-3 border border-neutral-200 rounded-xl hover:bg-neutral-50/50 transition flex flex-col justify-center min-h-[62px]">
                                            <div class="edit-mode hidden">
                                                <label class="block text-[9px] font-bold uppercase tracking-wider text-neutral-400 mb-1">Update PSA Birth Certificate</label>
                                                <input type="file" name="birth_certificate" class="w-full text-xs text-neutral-500 file:mr-2 file:py-1 file:px-2 file:rounded-md file:border-0 file:text-[10px] file:font-bold file:uppercase file:bg-neutral-100 file:text-neutral-700 hover:file:bg-neutral-200">
                                            </div>
                                            <div class="view-mode flex items-center justify-between w-full">
                                                <div class="flex items-center gap-3 min-w-0">
                                                    <div class="w-8 h-8 rounded-lg bg-neutral-100 text-neutral-600 flex items-center justify-center flex-shrink-0">
                                                        <i data-lucide="file-text" class="w-4 h-4"></i>
                                                    </div>
                                                    <div class="truncate">
                                                        <p class="font-semibold text-neutral-800 leading-tight truncate">PSA_Birth_Certificate.pdf</p>
                                                        <p class="text-[10px] text-neutral-400 mt-0.5">PSA Certified Document</p>
                                                    </div>
                                                </div>
                                                <button type="button" class="p-1.5 hover:bg-neutral-200/60 text-neutral-500 rounded-lg transition" title="Download Document">
                                                    <i data-lucide="download" class="w-4 h-4"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </form>

                </div>
            </main>

            <!-- Unified Script for Smooth Transitions -->
            <script>
                function toggleBlockEdit(containerId) {
                    const container = document.getElementById(`container-${containerId}`);
                    if (!container) return;

                    // Target relevant view/edit parts within this container scope
                    const viewModes = container.querySelectorAll('.view-mode');
                    const editModes = container.querySelectorAll('.edit-mode');
                    const btnEdit = container.querySelector('.btn-edit');
                    const btnActions = container.querySelector('.btn-actions');

                    // Toggle Visibility states safely
                    viewModes.forEach(el => el.classList.toggle('hidden'));
                    editModes.forEach(el => el.classList.toggle('hidden'));
                    
                    if (btnEdit && btnActions) {
                        btnEdit.classList.toggle('hidden');
                        btnActions.classList.toggle('hidden');
                    }
                }
                </script>

            </div>
        </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>