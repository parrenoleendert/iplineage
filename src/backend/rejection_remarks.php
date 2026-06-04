<?php
require_once __DIR__ . '/../auth/guards.php';
require_once __DIR__ . '/../auth/auth_helpers.php';
require_any_role(['admin', 'tribe_leader']);

require_once __DIR__ . '/../dbconfig.php';
$conn = $GLOBALS['conn'] ?? ($conn ?? null);
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection not established.']);
    exit;
}

function first_existing_column(array $columns, array $candidates): ?string {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

$approvalId = isset($_GET['approval_id']) ? (int) $_GET['approval_id'] : 0;
if ($approvalId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing approval ID.']);
    exit;
}

$columns = [];
$columnResult = $conn->query('SHOW COLUMNS FROM pending_approvals');
if ($columnResult instanceof mysqli_result) {
    while ($columnRow = $columnResult->fetch_assoc()) {
        $columns[] = strtolower((string) ($columnRow['Field'] ?? ''));
    }
}

$idColumn = first_existing_column($columns, ['id', 'pending_approval_id', 'approval_id']);
$remarksColumn = first_existing_column($columns, ['rejected_remarks', 'rejection_remarks', 'remarks', 'rejection_reason', 'reason', 'comment', 'comments']);
$nameColumn = first_existing_column($columns, ['applicant_name', 'name']);

if ($idColumn === null) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Approval ID column not found.']);
    exit;
}

$selectParts = [];
$selectParts[] = $nameColumn !== null ? "{$nameColumn} AS applicant_name" : "'' AS applicant_name";
$selectParts[] = $remarksColumn !== null ? "{$remarksColumn} AS remarks" : "'' AS remarks";

$sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM pending_approvals WHERE ' . $idColumn . ' = ? LIMIT 1';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Failed to prepare query.']);
    exit;
}

$stmt->bind_param('i', $approvalId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
$stmt->close();

header('Content-Type: application/json');
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Record not found.']);
    exit;
}

echo json_encode([
    'success' => true,
    'full_name' => (string) ($row['applicant_name'] ?? ''),
    'remarks' => trim((string) ($row['remarks'] ?? '')),
]);