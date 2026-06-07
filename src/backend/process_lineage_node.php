<?php
require_once __DIR__ . '/../auth/guards.php';
require_any_role(['admin', 'tribe_leader']);
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/family_lineage_service.php';

$conn = $GLOBALS['conn'];
header('Content-Type: application/json');

$requestId = (int)($_POST['request_id'] ?? 0);
$action = $_POST['action'] ?? '';
$remarks = $_POST['remarks'] ?? '';

if ($action === 'approve') {
    $res = $conn->query("SELECT * FROM lineage_requests WHERE request_id = $requestId LIMIT 1");
    if ($req = $res->fetch_assoc()) {
        $relType = (strtolower($req['proposed_sex']) === 'male' || strtolower($req['proposed_sex']) === 'm') ? 'father' : 'mother';
        link_person_relation($conn, (int)$req['child_id'], (int)$req['ghost_ip_id'], $relType);
        $conn->query("UPDATE lineage_requests SET status = 'approved' WHERE request_id = $requestId");
        echo json_encode(['ok' => true]);
    }
} elseif ($action === 'reject') {
    // Recursive cancel logic here (previously implemented in pending_lineage.php)
    $conn->query("UPDATE lineage_requests SET status = 'rejected', rejection_remarks = '".$conn->real_escape_string($remarks)."' WHERE request_id = $requestId");
    // Cancel dependents...
    $conn->query("UPDATE lineage_requests SET status = 'cancelled' WHERE depends_on_request_id = $requestId");
    echo json_encode(['ok' => true]);
}
exit;