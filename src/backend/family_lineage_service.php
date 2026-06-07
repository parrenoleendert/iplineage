<?php

function get_table_columns($conn, $tableName) {
    $columns = [];
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}`");

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }

    return $columns;
}

if (!function_exists('first_existing_column')) {
    function first_existing_column($columns, $candidates) {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }
}

/**
 * Finds or creates a member by their full name.
 */
function upsert_member_by_full_name(mysqli $conn, string $fullName, string $sex = 'U', string $birthdate = ''): int {
    $fullName = mb_convert_case(trim($fullName), MB_CASE_TITLE, "UTF-8");
    if ($fullName === '') return 0;

    $cols = get_table_columns($conn, 'ipmembers');
    $pkCol = first_existing_column($cols, ['ip_member_id', 'id', 'member_id']) ?? 'ip_member_id';
    $nameCol = first_existing_column($cols, ['full_name', 'name', 'member_name']) ?? 'full_name';

    // Match by name AND birthdate if provided to ensure unique "ghost" records
    $sql = "SELECT i.`{$pkCol}` FROM ipmembers i 
            LEFT JOIN ip_member_details d ON i.`{$pkCol}` = d.ip_member_id 
            WHERE i.`{$nameCol}` = ?";
    
    $detailCols = get_table_columns($conn, 'ip_member_details');
    $dobCol = first_existing_column($detailCols, ['date_of_birth', 'birthdate']) ?? 'date_of_birth';

    if ($birthdate !== '') { $sql .= " AND d.`{$dobCol}` = ?"; }

    $stmt = $conn->prepare($sql . " LIMIT 1");
    if (!$stmt) return 0;
    
    if ($birthdate !== '') $stmt->bind_param('ss', $fullName, $birthdate);
    else $stmt->bind_param('s', $fullName);
    
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if ($row) return (int)$row[$pkCol];

    // Only insert the name into the main ipmembers table
    $insertStmt = $conn->prepare("INSERT INTO ipmembers (`{$nameCol}`) VALUES (?)");
    if (!$insertStmt) return 0;
    $insertStmt->bind_param('s', $fullName);
    if ($insertStmt->execute()) {
        $id = (int)$conn->insert_id;
        $insertStmt->close();
        
        // Detect columns for details table
        $sexDetailCol = first_existing_column($detailCols, ['sex', 'gender']) ?? 'sex';
        
        // Check if details record already exists (safety)
        $checkDetail = $conn->query("SELECT 1 FROM ip_member_details WHERE ip_member_id = $id");
        if ($checkDetail && $checkDetail->num_rows === 0) {
            // Initialize details with birthdate for future matching/merging
            $detailSql = "INSERT INTO ip_member_details (ip_member_id, `{$sexDetailCol}`, `{$dobCol}`) VALUES (?, ?, ?)";
        $dStmt = $conn->prepare($detailSql);
        if ($dStmt) {
            $dStmt->bind_param('iss', $id, $sex, $birthdate);
            $dStmt->execute();
            $dStmt->close();
            }
        }
        return $id;
    }
    $insertStmt->close();
    return 0;
}

/**
 * Links two people with a parent-child relationship.
 */
function link_person_relation(mysqli $conn, int $childId, int $parentId, string $type): bool {
    if ($childId <= 0 || $parentId <= 0) return false;

    $cols = get_table_columns($conn, 'relationships');
    $srcCol = first_existing_column($cols, ['person_id', 'ip_member_id', 'member_id']) ?? 'person_id';
    $targetCol = first_existing_column($cols, ['related_person_id', 'related_member_id', 'relative_id']) ?? 'related_person_id';
    $typeCol = first_existing_column($cols, ['relationship_type', 'type']) ?? 'relationship_type';

    $check = $conn->prepare("SELECT 1 FROM relationships WHERE `{$srcCol}` = ? AND `{$targetCol}` = ? AND `{$typeCol}` = ?");
    $check->bind_param('iis', $childId, $parentId, $type);
    $check->execute();
    if ($check->get_result()->num_rows > 0) { $check->close(); return true; }
    $check->close();

    $stmt = $conn->prepare("INSERT INTO relationships (`{$srcCol}`, `{$targetCol}`, `{$typeCol}`) VALUES (?, ?, ?)");
    if (!$stmt) return false;
    $stmt->bind_param('iis', $childId, $parentId, $type);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function ensure_relationship_bucket(&$relationships, $id) {
    if (!isset($relationships[$id])) {
        $relationships[$id] = ['parents' => [], 'spouses' => [], 'children' => []];
    }
}

function add_unique_relation(&$list, $value) {
    if ($value === null || $value === '') {
        return;
    }

    $value = (string) $value;
    if (!in_array($value, $list, true)) {
        $list[] = $value;
    }
}

function resolve_selected_member_id($members, $requestedMemberId) {
    if ($requestedMemberId === '') {
        return '';
    }

    if (isset($members[$requestedMemberId])) {
        return (string) $requestedMemberId;
    }

    foreach (array_keys($members) as $memberKey) {
        if ((string) $memberKey === (string) $requestedMemberId) {
            return (string) $memberKey;
        }
    }

    return '';
}

function filter_connected_family_component($members, $relationships, $selectedMemberId) {
    if ($selectedMemberId === '') {
        return [$members, $relationships];
    }

    $adj = [];
    foreach ($relationships as $memberKey => $rel) {
        $allNeighbors = array_merge($rel['parents'], $rel['spouses'], $rel['children']);
        foreach ($allNeighbors as $neighbor) {
            $memberKey = (string) $memberKey;
            $neighbor = (string) $neighbor;

            if (!isset($adj[$memberKey])) {
                $adj[$memberKey] = [];
            }
            if (!isset($adj[$neighbor])) {
                $adj[$neighbor] = [];
            }

            $adj[$memberKey][$neighbor] = true;
            $adj[$neighbor][$memberKey] = true;
        }
    }

    $visited = [];
    $dfs = function ($current) use (&$dfs, &$visited, $adj) {
        $current = (string) $current;
        if (isset($visited[$current])) {
            return;
        }

        $visited[$current] = true;
        $neighbors = isset($adj[$current]) ? array_keys($adj[$current]) : [];

        foreach ($neighbors as $neighbor) {
            if (!isset($visited[(string) $neighbor])) {
                $dfs((string) $neighbor);
            }
        }
    };

    $dfs((string) $selectedMemberId);

    $members = array_filter(
        $members,
        function ($unusedValue, $key) use ($visited) {
            return isset($visited[(string) $key]);
        },
        ARRAY_FILTER_USE_BOTH
    );

    $relationships = array_filter(
        $relationships,
        function ($unusedValue, $key) use ($visited) {
            return isset($visited[(string) $key]);
        },
        ARRAY_FILTER_USE_BOTH
    );

    return [$members, $relationships];
}

function build_family_chart_data($members, $relationships, $selectedMemberId) {
    $familyData = [];
    $conn = $GLOBALS['conn'];

    // Map ip_member_ids to their active pending request IDs
    $proposalMap = [];
    // Order by ID ASC so that the latest request for a ghost record overwrites older ones in the map
    $propRes = $conn->query("SELECT request_id, ghost_ip_id, status, rejection_remarks FROM lineage_requests ORDER BY request_id ASC");
    if ($propRes) {
        while($p = $propRes->fetch_assoc()) {
            $proposalMap[$p['ghost_ip_id']] = $p;
        }
    }

    foreach ($members as $id => $member) {
        $fullName = trim(implode(' ', array_filter([
            $member['first_name'] ?? '',
            $member['middle_name'] ?? '',
            $member['last_name'] ?? ''
        ])));

        if ($fullName === '') {
            $fullName = trim((string) ($member['legacy_name'] ?? ''));
        }

        $names = explode(' ', $fullName);
        $firstName = $names[0] ?? '';
        $lastName = isset($names[1]) ? implode(' ', array_slice($names, 1)) : '';

        $parents = isset($relationships[$id]['parents']) ? array_map('strval', $relationships[$id]['parents']) : [];
        $spouses = isset($relationships[$id]['spouses']) ? array_map('strval', $relationships[$id]['spouses']) : [];
        $children = isset($relationships[$id]['children']) ? array_map('strval', $relationships[$id]['children']) : [];

        $parents = array_values(array_filter($parents, function ($relId) use ($members) {
            return isset($members[(string) $relId]);
        }));
        $spouses = array_values(array_filter($spouses, function ($relId) use ($members) {
            return isset($members[(string) $relId]);
        }));
        $children = array_values(array_filter($children, function ($relId) use ($members) {
            return isset($members[(string) $relId]);
        }));

        // Robust Status Detection:
        // 1. Use status from lineage_requests if it exists.
        // 2. If no request exists, Registered Users (with user_id) are 'approved'.
        // 3. Unregistered ghosts without a request default to 'pending' to require Elder review.
        $isRegistered = !empty($member['user_id']);
        $detectedStatus = $proposalMap[$id]['status'] ?? ($isRegistered ? 'approved' : 'pending');

        $familyData[] = [
            'id' => (string) $id,
            'data' => [
                'first name' => $firstName,
                'last name' => $lastName,
                'birthdate' => $member['birthdate'] ?? '',
                'avatar' => '',
                'gender' => strtoupper((string) ($member['sex'] ?? 'U')),
                'is_ghost' => empty($member['user_id']),
                'display_id' => $member['display_id'] ?? null,
                // Add verification metadata for the UI
                'request_id' => $proposalMap[$id]['request_id'] ?? null,
                'request_status' => $detectedStatus,
                'is_verified' => $detectedStatus === 'approved',
                'rejection_remarks' => $proposalMap[$id]['rejection_remarks'] ?? ''
            ],
            'rels' => [
                'parents' => $parents,
                'spouses' => $spouses,
                'children' => $children
            ]
        ];
    }

    if ($selectedMemberId !== '' && !empty($familyData)) {
        usort($familyData, function ($a, $b) use ($selectedMemberId) {
            if ((string) $a['id'] === (string) $selectedMemberId) {
                return -1;
            }
            if ((string) $b['id'] === (string) $selectedMemberId) {
                return 1;
            }
            return 0;
        });
    }

    return $familyData;
}

function build_family_lineage_payload($conn, $requestedMemberId = '', $forceWholeTree = false) {
    $ipMemberColumns = get_table_columns($conn, 'ipmembers');
    $relationshipColumns = get_table_columns($conn, 'relationships');
    $detailColumns = get_table_columns($conn, 'ip_member_details');

    $memberIdColumn = first_existing_column($ipMemberColumns, ['ip_member_id', 'member_id', 'id']);
    $displayIdColumn = first_existing_column($ipMemberColumns, ['display_id', 'member_id', 'ip_member_id']);
    $firstNameColumn = first_existing_column($ipMemberColumns, ['first_name']);
    $middleNameColumn = first_existing_column($ipMemberColumns, ['middle_name']);
    $lastNameColumn = first_existing_column($ipMemberColumns, ['last_name']);
    $legacyNameColumn = first_existing_column($ipMemberColumns, ['member_name', 'full_name', 'name']);
    
    // Detect sex and birthdate from both potential sources
    $sexColIp = first_existing_column($ipMemberColumns, ['sex', 'gender']);
    $sexColDet = first_existing_column($detailColumns, ['sex', 'gender']);
    
    $dobColIp = first_existing_column($ipMemberColumns, ['birthdate', 'date_of_birth']);
    $dobColDet = first_existing_column($detailColumns, ['date_of_birth', 'birthdate']);

    $relMemberColumn = first_existing_column($relationshipColumns, ['ip_member_id', 'member_id', 'person_id']);
    $relRelatedColumn = first_existing_column($relationshipColumns, ['related_person_id', 'related_member_id', 'relative_id']);
    $relTypeColumn = first_existing_column($relationshipColumns, ['relationship_type', 'type']);

    if ($memberIdColumn === null) {
        throw new RuntimeException('SQL Error: Could not find member key column in ipmembers table.');
    }

    if ($relMemberColumn === null || $relRelatedColumn === null || $relTypeColumn === null) {
        throw new RuntimeException('SQL Error: Could not find required columns in relationships table.');
    }

    $memberSelect = ["i.`{$memberIdColumn}` AS member_key", "i.user_id", ($displayIdColumn ? "i.`{$displayIdColumn}`" : "NULL") . " AS display_id"];

    if ($firstNameColumn !== null) {
        $memberSelect[] = "`{$firstNameColumn}` AS first_name";
    }
    if ($middleNameColumn !== null) {
        $memberSelect[] = "`{$middleNameColumn}` AS middle_name";
    }
    if ($lastNameColumn !== null) {
        $memberSelect[] = "`{$lastNameColumn}` AS last_name";
    }
    if ($legacyNameColumn !== null) {
        $memberSelect[] = "`{$legacyNameColumn}` AS legacy_name";
    }

    // Build robust expressions to pull from details table if main table is empty
    $sexExpr = "COALESCE(" . ($sexColIp ? "i.`$sexColIp`" : "NULL") . ", " . ($sexColDet ? "d.`$sexColDet`" : "NULL") . ", 'U') AS sex";
    $dobExpr = "COALESCE(" . ($dobColIp ? "i.`$dobColIp`" : "NULL") . ", " . ($dobColDet ? "d.`$dobColDet`" : "NULL") . ") AS birthdate";
    
    $memberSelect[] = $sexExpr;
    $memberSelect[] = $dobExpr;

    $sqlMembers = 'SELECT ' . implode(', ', $memberSelect) . " FROM ipmembers i LEFT JOIN ip_member_details d ON i.`$memberIdColumn` = d.ip_member_id";
    $resultMembers = $conn->query($sqlMembers);

    if (!$resultMembers) {
        throw new RuntimeException('SQL Error: ' . $conn->error);
    }

    $members = [];
    while ($row = $resultMembers->fetch_assoc()) {
        $members[(string) $row['member_key']] = $row;
    }

    $sqlRelationships = "SELECT `{$relMemberColumn}` AS source_id, `{$relRelatedColumn}` AS target_id, `{$relTypeColumn}` AS rel_type FROM relationships";
    $resultRelationships = $conn->query($sqlRelationships);

    if (!$resultRelationships) {
        throw new RuntimeException('SQL Error: ' . $conn->error);
    }

    $relationships = [];
    while ($row = $resultRelationships->fetch_assoc()) {
        $sourceId = isset($row['source_id']) ? (string) $row['source_id'] : '';
        $targetId = isset($row['target_id']) ? (string) $row['target_id'] : '';
        $type = strtolower(trim((string) ($row['rel_type'] ?? '')));

        if ($sourceId === '' || $targetId === '') {
            continue;
        }

        ensure_relationship_bucket($relationships, $sourceId);
        ensure_relationship_bucket($relationships, $targetId);

        if (in_array($type, ['parent', 'father', 'mother'], true)) {
            add_unique_relation($relationships[$sourceId]['parents'], $targetId);
            add_unique_relation($relationships[$targetId]['children'], $sourceId);
        } elseif ($type === 'child') {
            add_unique_relation($relationships[$sourceId]['children'], $targetId);
            add_unique_relation($relationships[$targetId]['parents'], $sourceId);
        } elseif ($type === 'spouse') {
            add_unique_relation($relationships[$sourceId]['spouses'], $targetId);
            add_unique_relation($relationships[$targetId]['spouses'], $sourceId);
        }
    }

    $selectedMemberId = resolve_selected_member_id($members, (string) $requestedMemberId);

    if (!$forceWholeTree) {
        list($members, $relationships) = filter_connected_family_component($members, $relationships, $selectedMemberId);
    }

    $familyData = build_family_chart_data($members, $relationships, $selectedMemberId);

    return [
        'family_data' => $familyData,
        'selected_member_id' => $selectedMemberId
    ];
}
