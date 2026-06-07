<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/guards.php';
require_any_role(['admin', 'tribe_leader']);
require_once __DIR__ . '/../dbconfig.php';

header('Content-Type: application/json; charset=utf-8');

$conn = $GLOBALS['conn'] ?? ($conn ?? null);

$approvalId = isset($_GET['approval_id']) ? (int)$_GET['approval_id'] : 0;

if ($approvalId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

$sql = "SELECT a.rejection_remarks, i.full_name 
        FROM applications a 
        JOIN ipmembers i ON a.ip_member_id = i.ip_member_id 
        WHERE a.application_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('i', $approvalId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    echo json_encode(['success' => !!$row, 'remarks' => $row['rejection_remarks'] ?? '', 'full_name' => $row['full_name'] ?? '']);
    $stmt->close();
}
?>