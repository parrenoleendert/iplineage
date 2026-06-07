<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/guards.php';
require_any_role(['admin', 'tribe_leader', 'ip_member']);
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/family_lineage_service.php';

$conn = $GLOBALS['conn'] ?? ($conn ?? null);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$childId = (int)($_POST['child_id'] ?? 0);
$fatherName = mb_convert_case(trim((string)($_POST['father_name'] ?? '')), MB_CASE_TITLE, "UTF-8");
$fatherDob = trim((string)($_POST['father_dob'] ?? ''));
$motherName = mb_convert_case(trim((string)($_POST['mother_name'] ?? '')), MB_CASE_TITLE, "UTF-8");
$motherDob = trim((string)($_POST['mother_dob'] ?? ''));
$dependsOn = isset($_POST['depends_on_request_id']) ? (int)$_POST['depends_on_request_id'] : null;
$requesterId = (int)($_SESSION['user_id'] ?? 0);

if ($childId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid target member.']);
    exit;
}

try {
    $conn->begin_transaction();

    $parents = [
        ['name' => $fatherName, 'dob' => $fatherDob, 'sex' => 'male'],
        ['name' => $motherName, 'dob' => $motherDob, 'sex' => 'female']
    ];

    foreach ($parents as $p) {
        if ($p['name'] === '') continue;

        // 1. Create the ghost member so they appear in the tree (unverified)
        $ghostId = upsert_member_by_full_name($conn, $p['name'], $p['sex'], $p['dob']);
        if ($ghostId <= 0) {
            throw new Exception("Failed to create member record for " . $p['name']);
        }

        $relType = (strtolower($p['sex']) === 'male' || strtolower($p['sex']) === 'm') ? 'father' : 'mother';
        link_person_relation($conn, $childId, $ghostId, $relType);

        // 2. Create the request tracking entry
        $sql = "INSERT INTO lineage_requests (child_id, ghost_ip_id, proposed_name, proposed_dob, proposed_sex, requested_by, depends_on_request_id, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";
        $stmt = $conn->prepare($sql);
        
        $dob = ($p['dob'] === '') ? null : $p['dob'];
        $depId = ($dependsOn === 0 || $dependsOn === null) ? null : $dependsOn;
        $shortSex = (strtolower($p['sex']) === 'male' || strtolower($p['sex']) === 'm') ? 'M' : 'F';

        $stmt->bind_param('iisssii', $childId, $ghostId, $p['name'], $dob, $shortSex, $requesterId, $depId);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) $conn->rollback();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}