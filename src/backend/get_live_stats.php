<?php
require_once __DIR__ . '/../auth/guards.php';
require_once __DIR__ . '/../dbconfig.php';

// Only admins/leaders should poll this
require_any_role(['admin', 'tribe_leader']);

header('Content-Type: application/json');

$stats = [
    'pending_elder' => 0,
    'pending_admin' => 0,
    'total_population' => 0
];

// Get counts
$res = $conn->query("SELECT status, COUNT(*) as count FROM applications GROUP BY status");
while ($row = $res->fetch_assoc()) {
    if ($row['status'] === 'pending_elder') $stats['pending_elder'] = (int)$row['count'];
    if ($row['status'] === 'pending_admin') $stats['pending_admin'] = (int)$row['count'];
    if ($row['status'] === 'approved') $stats['total_population'] = (int)$row['count'];
}

// Quick check for the very last approved name
$recent = $conn->query("SELECT i.full_name FROM applications a JOIN ipmembers i ON a.ip_member_id = i.ip_member_id WHERE a.status = 'approved' ORDER BY a.application_id DESC LIMIT 1");
$stats['latest_member'] = ($recent && $r = $recent->fetch_assoc()) ? $r['full_name'] : '';

echo json_encode($stats);
exit;