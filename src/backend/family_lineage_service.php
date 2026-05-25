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

function first_existing_column($columns, $candidates) {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
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

        $familyData[] = [
            'id' => (string) $id,
            'data' => [
                'first name' => $firstName,
                'last name' => $lastName,
                'birthdate' => $member['birthdate'] ?? '',
                'avatar' => '',
                'gender' => strtoupper((string) ($member['sex'] ?? 'U')),
                'display_id' => $member['display_id'] ?? (string) $id
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

function build_family_lineage_payload($conn, $requestedMemberId = '') {
    $ipMemberColumns = get_table_columns($conn, 'ipmembers');
    $relationshipColumns = get_table_columns($conn, 'relationships');

    $memberIdColumn = first_existing_column($ipMemberColumns, ['ip_member_id', 'member_id', 'id']);
    $displayIdColumn = first_existing_column($ipMemberColumns, ['member_id', 'ip_member_id']);
    $firstNameColumn = first_existing_column($ipMemberColumns, ['first_name']);
    $middleNameColumn = first_existing_column($ipMemberColumns, ['middle_name']);
    $lastNameColumn = first_existing_column($ipMemberColumns, ['last_name']);
    $legacyNameColumn = first_existing_column($ipMemberColumns, ['member_name', 'full_name', 'name']);
    $sexColumn = first_existing_column($ipMemberColumns, ['sex', 'gender']);
    $dateColumn = first_existing_column($ipMemberColumns, ['birthdate']);

    $relMemberColumn = first_existing_column($relationshipColumns, ['ip_member_id', 'member_id', 'person_id']);
    $relRelatedColumn = first_existing_column($relationshipColumns, ['related_person_id', 'related_member_id', 'relative_id']);
    $relTypeColumn = first_existing_column($relationshipColumns, ['relationship_type', 'type']);

    if ($memberIdColumn === null) {
        throw new RuntimeException('SQL Error: Could not find member key column in ipmembers table.');
    }

    if ($relMemberColumn === null || $relRelatedColumn === null || $relTypeColumn === null) {
        throw new RuntimeException('SQL Error: Could not find required columns in relationships table.');
    }

    $memberSelect = ["`{$memberIdColumn}` AS member_key"];
    if ($displayIdColumn !== null && $displayIdColumn !== $memberIdColumn) {
        $memberSelect[] = "`{$displayIdColumn}` AS display_id";
    } else if ($displayIdColumn !== null) {
        $memberSelect[] = 'null AS display_id';
    }
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
    if ($sexColumn !== null) {
        $memberSelect[] = "`{$sexColumn}` AS sex";
    }
    if ($dateColumn !== null) {
        $memberSelect[] = "`{$dateColumn}` AS birthdate";
    }

    $sqlMembers = 'SELECT ' . implode(', ', $memberSelect) . ' FROM ipmembers';
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

    list($members, $relationships) = filter_connected_family_component($members, $relationships, $selectedMemberId);

    $familyData = build_family_chart_data($members, $relationships, $selectedMemberId);

    return [
        'family_data' => $familyData,
        'selected_member_id' => $selectedMemberId
    ];
}
