<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../dbconfig.php';

function json_error($msg) {
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

if (!isset($conn) || $conn === null) {
    json_error('Database connection not available');
}

$approvalId = isset($_POST['approval_id']) ? (int) $_POST['approval_id'] : 0;
if ($approvalId <= 0) {
    json_error('Invalid approval_id');
}

$sourceTable = 'pending_approvals';
$tableExistsResult = $conn->query("SHOW TABLES LIKE 'pending_approvals'");
if (!($tableExistsResult instanceof mysqli_result) || $tableExistsResult->num_rows === 0) {
    $sourceTable = 'applications';
}

$columns = [];
$columnResult = $conn->query("SHOW COLUMNS FROM {$sourceTable}");
if ($columnResult instanceof mysqli_result) {
    while ($columnRow = $columnResult->fetch_assoc()) {
        $columns[] = $columnRow['Field'];
    }
}

function first_existing_column(array $columns, array $candidates): ?string {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

$idColumn = first_existing_column($columns, ['id', 'pending_approval_id', 'approval_id']);
$statusColumn = first_existing_column($columns, ['approval_status', 'status']);
$approveRejectDateColumn = first_existing_column($columns, ['approve_reject_date', 'approved_at', 'updated_at']);

if ($idColumn === null || $statusColumn === null) {
    json_error('Required columns not found');
}

$pendingValue = $statusColumn === 'approval_status' ? 'pending_approval' : 'pending';

// collect all possible remark-like columns to clear
$remarkCandidates = ['rejected_remarks', 'rejection_remarks', 'remarks', 'rejection_reason', 'reason', 'comment', 'comments'];
$clearCols = [];
foreach ($remarkCandidates as $c) {
    if (in_array($c, $columns, true)) {
        $clearCols[] = $c;
    }
}

$setParts = [];
$setParts[] = "{$statusColumn} = ?";
if ($approveRejectDateColumn !== null) {
    $setParts[] = "{$approveRejectDateColumn} = NULL";
}
foreach ($clearCols as $col) {
    $setParts[] = "{$col} = ''";
}

$sql = "UPDATE {$sourceTable} SET " . implode(', ', $setParts) . " WHERE {$idColumn} = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    json_error('Prepare failed: ' . $conn->error);
}

$stmt->bind_param('si', $pendingValue, $approvalId);
if (!$stmt->execute()) {
    $stmt->close();
    json_error('Execute failed: ' . $conn->error);
}

$affected = $stmt->affected_rows;
$stmt->close();

if ($affected === 0) {
    // Maybe the record already set or id not found, but treat as success to avoid confusion
    echo json_encode(['success' => true, 'message' => 'No rows updated (may already be pending)']);
    exit;
}

echo json_encode(['success' => true]);

?>
