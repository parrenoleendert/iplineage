<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/guards.php';
require_any_role(['admin', 'tribe_leader']);
require_once __DIR__ . '/../dbconfig.php';

header('Content-Type: application/json; charset=utf-8');

$conn = $GLOBALS['conn'] ?? ($conn ?? null);

$approvalId = isset($_POST['approval_id']) ? (int)$_POST['approval_id'] : 0;

if ($approvalId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid application ID']);
    exit;
}

// Reset status to pending_elder and clear rejection remarks
$sql = "UPDATE applications SET status = 'pending_elder', rejection_remarks = NULL WHERE application_id = ? AND status = 'rejected'";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('i', $approvalId);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'No changes made or record not found.']);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
}
?>