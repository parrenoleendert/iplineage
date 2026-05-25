<?php
// Database must be initialized FIRST before any guards that might use it
$dbConfigPath = __DIR__ . '/../dbconfig.php';
if (!file_exists($dbConfigPath)) {
    die('Database configuration file not found: ' . $dbConfigPath);
}
require_once $dbConfigPath;

if (!isset($conn) || $conn === null) {
    die('Database connection failed: $conn is not initialized. Please check dbconfig.php');
}

require_once __DIR__ . '/../auth/guards.php';
require_any_role(['ip_member']);

$displayName = trim((string) ($_SESSION['name'] ?? ''));
$userId = (int) ($_SESSION['user_id'] ?? 0);
$targetUrl = '../family_lineage.php';

$memberSql = "SELECT i.ip_member_id
              FROM ipmembers i
              WHERE i.user_id = ? OR TRIM(CONCAT_WS(' ', i.first_name, i.middle_name, i.last_name)) = ?
              ORDER BY (i.user_id = ?) DESC
              LIMIT 1";

$memberStmt = $conn->prepare($memberSql);
if ($memberStmt) {
    $memberStmt->bind_param('isi', $userId, $displayName, $userId);
    $memberStmt->execute();
    $memberResult = $memberStmt->get_result();
    $memberRow = $memberResult instanceof mysqli_result ? $memberResult->fetch_assoc() : null;
    $memberStmt->close();

    $memberPrimaryId = (int) ($memberRow['ip_member_id'] ?? 0);
    if ($memberPrimaryId > 0) {
        $targetUrl = '../family_lineage.php?member_id=' . rawurlencode((string) $memberPrimaryId);
    }
}

header('Location: ' . $targetUrl);
exit;
