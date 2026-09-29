<?php

require_once 'family_lineage_service.php';

$members = [
    '1' => [
        'first_name' => 'John',
        'middle_name' => '',
        'last_name' => 'Smith',
        'birthdate' => '1980-01-01',
        'sex' => 'M',
        'display_id' => 'M001'
    ],
    '2' => [
        'first_name' => 'Jane',
        'middle_name' => '',
        'last_name' => 'Smith',
        'birthdate' => '1982-02-02',
        'sex' => 'F',
        'display_id' => 'M002'
    ],
    '3' => [
        'first_name' => 'Mark',
        'middle_name' => '',
        'last_name' => 'Smith',
        'birthdate' => '2010-03-03',
        'sex' => 'M',
        'display_id' => 'M003'
    ]
];

$relationships = [
    '1' => [
        'parents' => [],
        'spouses' => ['2'],
        'children' => ['3', '99']
    ],
    '2' => [
        'parents' => [],
        'spouses' => ['1'],
        'children' => ['3']
    ],
    '3' => [
        'parents' => ['1', '2'],
        'spouses' => [],
        'children' => []
    ]
];

$result = build_family_chart_data($members, $relationships, '1');


// Test 1: Family relationships are included
$test1 =
    in_array('2', $result[0]['rels']['spouses'], true) &&
    in_array('3', $result[0]['rels']['children'], true);

echo "Test 1 - Family relationships: "
    . ($test1 ? "PASS" : "FAIL") . PHP_EOL;


// Test 2: Selected member is first
$resultSelected = build_family_chart_data($members, $relationships, '3');
$test2 = $resultSelected[0]['id'] === '3';

echo "Test 2 - Selected member is first: "
    . ($test2 ? "PASS" : "FAIL") . PHP_EOL;


// Test 3: Invalid relationship is removed
$test3 = !in_array('99', $result[0]['rels']['children'], true); 

echo "Test 3 - Invalid relationship removed: "
    . ($test3 ? "PASS" : "FAIL") . PHP_EOL;