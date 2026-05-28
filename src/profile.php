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

            if ($rowFirstName !== '') {
                $firstName = $rowFirstName;
            }
            if ($rowMiddleName !== '') {
                $middleName = $rowMiddleName;
            }
            if ($rowLastName !== '') {
                $lastName = $rowLastName;
            }

            if ($rowName !== '') {
                $displayName = $rowName;
            }
            if ($displayName === '' && ($firstName !== '' || $middleName !== '' || $lastName !== '')) {
                $displayName = trim(implode(' ', array_filter([$firstName, $middleName, $lastName], static function ($value) {
                    return $value !== '';
                })));
            }
            if ($rowEmail !== '') {
                $email = $rowEmail;
            }
            if ($rowJurisdiction !== '') {
                $jurisdiction = $rowJurisdiction;
            }
            if ($rowStatus !== '') {
                $status = $rowStatus;
            }
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
    if ($firstName === '') {
        $firstName = (string) ($namePartsFromDisplay[0] ?? '');
    }
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
    if ($ipFirstNameColumn !== null) {
        $memberSelectParts[] = "`{$ipFirstNameColumn}` AS first_name";
    }
    if ($ipMiddleNameColumn !== null) {
        $memberSelectParts[] = "`{$ipMiddleNameColumn}` AS middle_name";
    }
    if ($ipLastNameColumn !== null) {
        $memberSelectParts[] = "`{$ipLastNameColumn}` AS last_name";
    }
    if ($ipLegacyNameColumn !== null) {
        $memberSelectParts[] = "`{$ipLegacyNameColumn}` AS legacy_name";
    }

    $allMembersSql = 'SELECT ' . implode(', ', $memberSelectParts) . ' FROM ipmembers';
    $allMembersResult = $conn->query($allMembersSql);
    if ($allMembersResult instanceof mysqli_result) {
        while ($memberRow = $allMembersResult->fetch_assoc()) {
            $memberKey = (string) ($memberRow['member_key'] ?? '');
            if ($memberKey === '') {
                continue;
            }

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
        if ($ipLegacyNameColumn !== null) {
            $findByNameWhere[] = "TRIM(`{$ipLegacyNameColumn}`) = ?";
        }
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
                if ($name === '') {
                    return;
                }
                if (!in_array($name, $bucket, true)) {
                    $bucket[] = $name;
                }
            };

            while ($familyRow = $familyResult instanceof mysqli_result ? $familyResult->fetch_assoc() : null) {
                if (!$familyRow) {
                    break;
                }

                $sourceId = (string) ($familyRow['source_id'] ?? '');
                $targetId = (string) ($familyRow['target_id'] ?? '');
                $relType = strtolower(trim((string) ($familyRow['rel_type'] ?? '')));

                if ($sourceId === '' || $targetId === '') {
                    continue;
                }

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

$statusClass = 'bg-gray-100 text-gray-600';
if (strtolower($status) === 'online') {
    $statusClass = 'bg-green-100 text-green-700';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <title>IP Lineage - Profile</title>
    <style>
        body { background-color: #f3f4f1; font-family: 'Plus Jakarta Sans', sans-serif; }
        .cover-gradient {
            background: linear-gradient(120deg, #262626 0%, #525252 45%, #a3a3a3 100%);
        }
    </style>
</head>
<body class="min-h-screen">
    <header class="bg-white border-b border-[#dedede] p-4 flex justify-between items-center">
        <div class="flex items-center gap-4">
            <a href="dashboard.php" class="p-2 hover:bg-gray-100 rounded-lg transition">
                <i data-lucide="arrow-left" class="w-5 h-5 text-gray-600"></i>
            </a>
            <h1 class="text-lg font-bold text-[#262626]">My Profile</h1>
        </div>
        <a href="?edit=1" class="inline-flex items-center gap-2 px-3 py-2 text-xs font-bold uppercase tracking-wider rounded-lg border border-[#dedede] text-[#262626] hover:bg-gray-50 transition">
            <i data-lucide="pencil" class="w-4 h-4"></i>
            Edit Profile
        </a>
    </header>

    <main class="p-4 md:p-10">
        <?php if ($errorMessage !== ''): ?>
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($successMessage !== ''): ?>
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <section class="max-w-5xl mx-auto bg-white border border-[#dedede] rounded-2xl shadow-sm overflow-hidden">
            <div class="h-48 md:h-64 cover-gradient"></div>

            <div class="px-6 md:px-10 pb-8">
                <div class="-mt-14 md:-mt-16 flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                    <div class="flex items-end gap-4">
                        <div class="w-28 h-28 md:w-32 md:h-32 rounded-full border-4 border-white bg-[#262626] text-white flex items-center justify-center text-3xl font-bold uppercase shadow-md">
                            <?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <div class="pb-2">
                            <h2 class="text-2xl font-bold text-[#262626]"><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></h2>
                            <p class="text-sm text-gray-500"><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                    </div>

                    <div class="pb-2">
                        <span class="inline-flex items-center px-3 py-1 text-xs font-bold uppercase rounded-full <?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                </div>

                <div class="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <aside class="lg:col-span-1 bg-[#f8f8f7] border border-[#dedede] rounded-xl p-5">
                        <h3 class="text-sm font-bold uppercase tracking-wide text-[#262626] mb-4">Family</h3>

                        <div class="space-y-4 text-sm mb-5">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-wide text-gray-400 mb-1">Parents</p>
                                <p class="text-gray-700 font-semibold"><?php echo htmlspecialchars($parentsLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-wide text-gray-400 mb-1">Spouse</p>
                                <p class="text-gray-700 font-semibold"><?php echo htmlspecialchars($spousesLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-wide text-gray-400 mb-1">Children</p>
                                <p class="text-gray-700 font-semibold"><?php echo htmlspecialchars($childrenLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                        </div>

                        <div class="space-y-3 text-sm">
                            <div class="flex items-start gap-2 text-gray-600">
                                <i data-lucide="mail" class="w-4 h-4 mt-0.5"></i>
                                <span><?php echo htmlspecialchars($email !== '' ? $email : 'N/A', ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="flex items-start gap-2 text-gray-600">
                                <i data-lucide="map-pin" class="w-4 h-4 mt-0.5"></i>
                                <span><?php echo htmlspecialchars($jurisdiction !== '' ? $jurisdiction : 'N/A', ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="flex items-start gap-2 text-gray-600">
                                <i data-lucide="clock-3" class="w-4 h-4 mt-0.5"></i>
                                <span><?php echo htmlspecialchars($lastActive, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                    </aside>

                    <section class="lg:col-span-2 bg-white border border-[#dedede] rounded-xl p-5">
                        <div class="flex items-center justify-between mb-5">
                            <h3 class="text-sm font-bold uppercase tracking-wide text-[#262626]">Profile Details</h3>
                            <?php if (!$isEditMode): ?>
                                <a href="?edit=1" class="inline-flex items-center gap-2 px-3 py-2 text-xs font-bold uppercase tracking-wider rounded-lg border border-[#dedede] text-[#262626] hover:bg-gray-50 transition">
                                    <i data-lucide="pencil" class="w-4 h-4"></i>
                                    Edit
                                </a>
                            <?php endif; ?>
                        </div>

                        <?php if ($isEditMode): ?>
                            <form method="post" class="space-y-4">
                                <input type="hidden" name="profile_action" value="save_profile">

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <label for="first_name" class="block text-[11px] font-bold uppercase tracking-wide text-gray-500 mb-1">First Name</label>
                                        <input id="first_name" name="first_name" type="text" value="<?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-[#dedede] rounded-xl py-3 px-4 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-sm" required>
                                    </div>
                                    <div>
                                        <label for="middle_name" class="block text-[11px] font-bold uppercase tracking-wide text-gray-500 mb-1">Middle Name</label>
                                        <input id="middle_name" name="middle_name" type="text" value="<?php echo htmlspecialchars($middleName, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-[#dedede] rounded-xl py-3 px-4 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-sm">
                                    </div>
                                    <div>
                                        <label for="last_name" class="block text-[11px] font-bold uppercase tracking-wide text-gray-500 mb-1">Last Name</label>
                                        <input id="last_name" name="last_name" type="text" value="<?php echo htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-[#dedede] rounded-xl py-3 px-4 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-sm" required>
                                    </div>
                                </div>

                                <div>
                                    <label for="email" class="block text-[11px] font-bold uppercase tracking-wide text-gray-500 mb-1">Email</label>
                                    <input id="email" name="email" type="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-[#dedede] rounded-xl py-3 px-4 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-sm">
                                </div>

                                <div>
                                    <label for="jurisdiction" class="block text-[11px] font-bold uppercase tracking-wide text-gray-500 mb-1">Jurisdiction</label>
                                    <input id="jurisdiction" name="jurisdiction" type="text" value="<?php echo htmlspecialchars($jurisdiction, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-white border border-[#dedede] rounded-xl py-3 px-4 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-sm">
                                </div>

                                <div class="pt-2 border-t border-[#dedede]">
                                    <h4 class="text-[11px] font-bold uppercase tracking-wide text-gray-500 mb-3">Change Password</h4>
                                    <div class="space-y-3">
                                        <div>
                                            <label for="current_password" class="block text-[11px] font-bold uppercase tracking-wide text-gray-500 mb-1">Current Password</label>
                                            <input id="current_password" name="current_password" type="password" class="w-full bg-white border border-[#dedede] rounded-xl py-3 px-4 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-sm" autocomplete="current-password">
                                        </div>
                                        <div>
                                            <label for="new_password" class="block text-[11px] font-bold uppercase tracking-wide text-gray-500 mb-1">New Password</label>
                                            <input id="new_password" name="new_password" type="password" class="w-full bg-white border border-[#dedede] rounded-xl py-3 px-4 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-sm" autocomplete="new-password">
                                        </div>
                                        <div>
                                            <label for="confirm_password" class="block text-[11px] font-bold uppercase tracking-wide text-gray-500 mb-1">Confirm New Password</label>
                                            <input id="confirm_password" name="confirm_password" type="password" class="w-full bg-white border border-[#dedede] rounded-xl py-3 px-4 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-sm" autocomplete="new-password">
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center gap-3 pt-2">
                                    <button type="submit" class="px-4 py-2 text-xs font-bold uppercase tracking-wider rounded-lg bg-[#262626] text-white hover:bg-[#404040] transition">Save Changes</button>
                                    <a href="profile.php" class="px-4 py-2 text-xs font-bold uppercase tracking-wider rounded-lg border border-[#dedede] text-[#262626] hover:bg-gray-50 transition">Cancel</a>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 text-sm">
                                <div>
                                    <p class="text-gray-400 uppercase text-[10px] font-bold tracking-wide mb-1">Name</p>
                                    <p class="font-semibold text-[#262626]"><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                                <div>
                                    <p class="text-gray-400 uppercase text-[10px] font-bold tracking-wide mb-1">Role</p>
                                    <p class="font-semibold text-[#262626]"><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                                <div>
                                    <p class="text-gray-400 uppercase text-[10px] font-bold tracking-wide mb-1">Email</p>
                                    <p class="font-semibold text-[#262626]"><?php echo htmlspecialchars($email !== '' ? $email : 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                                <div>
                                    <p class="text-gray-400 uppercase text-[10px] font-bold tracking-wide mb-1">Jurisdiction</p>
                                    <p class="font-semibold text-[#262626]"><?php echo htmlspecialchars($jurisdiction !== '' ? $jurisdiction : 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                                <div class="md:col-span-2">
                                    <p class="text-gray-400 uppercase text-[10px] font-bold tracking-wide mb-1">Last Active</p>
                                    <p class="font-semibold text-[#262626]"><?php echo htmlspecialchars($lastActive, ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
        </section>
    </main>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>