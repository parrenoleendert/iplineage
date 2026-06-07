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

$personId = (int)($_POST['person_id'] ?? 0);
$spouseName = mb_convert_case(trim((string)($_POST['spouse_name'] ?? '')), MB_CASE_TITLE, "UTF-8");
$spouseDob = trim((string)($_POST['spouse_dob'] ?? ''));
$spouseSex = trim((string)($_POST['spouse_sex'] ?? 'female'));
$requesterId = (int)($_SESSION['user_id'] ?? 0);

if ($personId <= 0 || $spouseName === '') {
    echo json_encode(['ok' => false, 'error' => 'Invalid input data.']);
    exit;
}

try {
    $conn->begin_transaction();

    // 1. Create the ghost member for the spouse
    $ghostId = upsert_member_by_full_name($conn, $spouseName, $spouseSex, $spouseDob);
    if ($ghostId <= 0) {
        throw new Exception("Failed to create member record for " . $spouseName);
    }

    // 2. Link them as a spouse
    link_person_relation($conn, $personId, $ghostId, 'spouse');

    // 3. Create the request tracking entry
    $sql = "INSERT INTO lineage_requests (child_id, ghost_ip_id, proposed_name, proposed_dob, proposed_sex, requested_by, status) 
            VALUES (?, ?, ?, ?, ?, ?, 'pending')";
    $stmt = $conn->prepare($sql);
    $dob = ($spouseDob === '') ? null : $spouseDob;
    $stmt->bind_param('iisssi', $personId, $ghostId, $spouseName, $dob, $spouseSex, $requesterId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) $conn->rollback();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}