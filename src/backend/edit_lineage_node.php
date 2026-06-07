<?php
require_once __DIR__ . '/../auth/guards.php';
require_once __DIR__ . '/../dbconfig.php';

require_any_role(['ip_member', 'admin', 'tribe_leader']);

$conn = $GLOBALS['conn'];
header('Content-Type: application/json');

$requestId = (int)($_POST['request_id'] ?? 0);
$name = mb_convert_case(trim((string)($_POST['proposed_name'] ?? '')), MB_CASE_TITLE, "UTF-8");
$dob = trim((string)($_POST['proposed_dob'] ?? ''));

if ($requestId <= 0 || $name === '') {
    echo json_encode(['ok' => false, 'error' => 'Invalid data provided.']);
    exit;
}

try {
    $conn->begin_transaction();
    
    $res = $conn->query("SELECT ghost_ip_id FROM lineage_requests WHERE request_id = $requestId LIMIT 1");
    $req = $res->fetch_assoc();
    if (!$req) throw new Exception("Request record not found.");
    
    $ghostId = (int)$req['ghost_ip_id'];
    
    // Update Ghost Identity and Reset Request Status
    $conn->query("UPDATE ipmembers SET full_name = '".$conn->real_escape_string($name)."' WHERE ip_member_id = $ghostId");
    $conn->query("UPDATE ip_member_details SET date_of_birth = ".($dob === '' ? "NULL" : "'".$conn->real_escape_string($dob)."'")." WHERE ip_member_id = $ghostId");
    $conn->query("UPDATE lineage_requests SET proposed_name = '".$conn->real_escape_string($name)."', proposed_dob = ".($dob === '' ? "NULL" : "'".$conn->real_escape_string($dob)."'").", status = 'pending', rejection_remarks = NULL WHERE request_id = $requestId");
    
    $conn->commit();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    if ($conn) $conn->rollback();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;