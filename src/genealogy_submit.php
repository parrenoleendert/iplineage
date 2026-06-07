<?php
declare(strict_types=1);
// Direct backend processing for genealogy form submission.
// Inserts/links ipmembers + relationships in a strict parent-to-parent chain.




require_once __DIR__ . '/auth/guards.php';
require_once __DIR__ . '/auth/auth_helpers.php';

require_any_role(['admin', 'tribe_leader', 'ip_member']);

require_once __DIR__ . '/dbconfig.php';
$conn = $GLOBALS['conn'] ?? ($conn ?? null);
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['ok' => false, 'error' => 'Database connection not established.']));
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['ok' => false, 'error' => 'Method not allowed.']));
}

$uid = (int)($_SESSION['user_id'] ?? ($_SESSION['userid'] ?? 0));
if ($uid <= 0) {
    http_response_code(401);
    die(json_encode(['ok' => false, 'error' => 'User session not found.']));
}

function respondOk(int $statusCode, array $data): void {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

function respondErr(int $statusCode, string $message, array $extra = []): void {
    http_response_code($statusCode);
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra));
    exit;
}

function normalize_full_name(?string $name): string {
    $name = trim((string) $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return mb_convert_case($name, MB_CASE_TITLE, "UTF-8");
}

function normalize_rel_input($v): string {
    $v = trim((string) $v);
    if ($v === '') return '';
    $v = preg_replace('/\s+/', ' ', $v);
    return mb_convert_case($v, MB_CASE_TITLE, "UTF-8");
}

function upsert_member_by_full_name(mysqli $conn, string $fullName): int {
    $fullName = normalize_full_name($fullName);
    if ($fullName === '') return 0;

    $selectSql = "SELECT ip_member_id FROM ipmembers WHERE full_name = ? LIMIT 1";
    $selectStmt = $conn->prepare($selectSql);
    if (!$selectStmt) {
        throw new Exception('Prepare failed for ipmembers match: ' . $conn->error);
    }
    $selectStmt->bind_param('s', $fullName);
    $selectStmt->execute();
    $res = $selectStmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $selectStmt->close();

    if ($row && isset($row['ip_member_id'])) {
        return (int) $row['ip_member_id'];
    }

    $insertSql = "INSERT INTO ipmembers (full_name, user_id) VALUES (?, NULL)";
    $insertStmt = $conn->prepare($insertSql);
    if (!$insertStmt) {
        throw new Exception('Prepare failed for ipmembers insert: ' . $conn->error);
    }
    $insertStmt->bind_param('s', $fullName);

    if (!$insertStmt->execute()) {
        throw new Exception('Insert into ipmembers failed: ' . $insertStmt->error);
    }

    $id = (int) $conn->insert_id;
    $insertStmt->close();

    if ($id <= 0) {
        throw new Exception('Failed to obtain ip_member_id after insert.');
    }

    return $id;
}

function link_parent_child(mysqli $conn, int $personId, int $parentId, string $relationshipType): void {
    $relationshipType = strtolower(trim($relationshipType));
    if (!in_array($relationshipType, ['father', 'mother', 'spouse'], true)) {
        throw new Exception('Invalid relationship_type: ' . $relationshipType);
    }
    if ($personId <= 0 || $parentId <= 0) return;

    $sql = "INSERT IGNORE INTO relationships (person_id, related_person_id, relationship_type) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed for relationships insert: ' . $conn->error);
    }
    $stmt->bind_param('iis', $personId, $parentId, $relationshipType);
    if (!$stmt->execute()) {
        throw new Exception('relationships insert failed: ' . $stmt->error);
    }
    $stmt->close();
}

function create_lineage_request(mysqli $conn, int $childId, int $ghostId, string $name, string $sex, int $requesterId, ?int $dependsOn = null): int {
    // Ensure every ghost node added has a corresponding request for the audit trail
    $sql = "INSERT INTO lineage_requests (child_id, ghost_ip_id, proposed_name, proposed_sex, requested_by, depends_on_request_id, status) 
            VALUES (?, ?, ?, ?, ?, ?, 'pending')";
    $stmt = $conn->prepare($sql);
    $pSex = (strtolower($sex) === 'male' || strtolower($sex) === 'm') ? 'M' : 'F';
    $stmt->bind_param('iisssi', $childId, $ghostId, $name, $pSex, $requesterId, $dependsOn);
    $stmt->execute();
    $requestId = (int)$conn->insert_id;
    $stmt->close();
    return $requestId;
}

// ---- INPUT MAPPING (from genealogy.php frontend) ----
$fatherName = normalize_rel_input($_POST['father_name'] ?? '');
$patGFName = normalize_rel_input($_POST['pat_grandfather_name'] ?? '');
$patGMName = normalize_rel_input($_POST['pat_grandmother_name'] ?? '');

$motherName = normalize_rel_input($_POST['mother_name'] ?? '');
$matGFName = normalize_rel_input($_POST['mat_grandfather_name'] ?? '');
$matGMName = normalize_rel_input($_POST['mat_grandmother_name'] ?? '');

// Great-grandfathers
$patGFather = normalize_rel_input($_POST['pat_gf_father'] ?? '');
$patGMotherFather = normalize_rel_input($_POST['pat_gm_father'] ?? '');
$matGFather = normalize_rel_input($_POST['mat_gf_father'] ?? '');
$matGMotherFather = normalize_rel_input($_POST['mat_gm_father'] ?? '');

// Great-grandmothers
$patGFatherMother = normalize_rel_input($_POST['pat_gf_mother_father'] ?? '');
$patGMotherMother = normalize_rel_input($_POST['pat_gm_mother_father'] ?? '');
$matGFotherMother = normalize_rel_input($_POST['mat_gf_mother_father'] ?? '');
$matGMotherMother = normalize_rel_input($_POST['mat_gm_mother_father'] ?? '');

// ---- PRIMARY USER PROFILE ----
// genealogy.php does not submit full name for the primary user.
// So we try to fetch it from users table.
$userFullName = '';
try {
    $q = $conn->prepare('SELECT full_name FROM users WHERE user_id = ? LIMIT 1');
    if ($q) {
        $q->bind_param('i', $uid);
        $q->execute();
        $r = $q->get_result();
        $row = $r ? $r->fetch_assoc() : null;
        $userFullName = normalize_full_name($row['full_name'] ?? '');
        $q->close();
    }
} catch (Throwable $ignore) {}

if ($userFullName === '') {
    try {
        $q = $conn->prepare('SELECT first_name, middle_name, last_name FROM users WHERE user_id = ? LIMIT 1');
        if ($q) {
            $q->bind_param('i', $uid);
            $q->execute();
            $r = $q->get_result();
            $row = $r ? $r->fetch_assoc() : null;
            if ($row) {
                $fn = normalize_full_name($row['first_name'] ?? '');
                $mn = trim((string) ($row['middle_name'] ?? ''));
                $ln = normalize_full_name($row['last_name'] ?? '');
                $userFullName = normalize_full_name(trim($fn . ' ' . $mn . ' ' . $ln));
            }
            $q->close();
        }
    } catch (Throwable $ignore) {}
}

if ($userFullName === '') {
    respondErr(422, 'Missing primary user full name. Cannot insert into ipmembers without it.');
}

try {
    $conn->begin_transaction();

    // 1) Primary profile: check/insert primary into ipmembers; insert demographics (best-effort)
    $primaryIpMemberId = upsert_member_by_full_name($conn, $userFullName);
    if ($primaryIpMemberId <= 0) {
        throw new Exception('Invalid primary ip_member_id.');
    }

    // Insert demographics into ip_member_details (optional/best-effort)
    $dob = null;
    $pob = null;
    $tribe = null;
    $mobile = null;
    $barangay = null;
    $address = null;
    $marital = null;
    $educ = null;

    try {
        $q = $conn->prepare('SELECT date_of_birth, place_of_birth, tribe, mobile_number, barangay, specific_current_address, marital_status, educational_attainment FROM ip_member_details_view WHERE user_id = ? LIMIT 1');
        if ($q) {
            $q->bind_param('i', $uid);
            $q->execute();
            $res = $q->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $q->close();

            if ($row) {
                $dob = $row['date_of_birth'] ?? null;
                $pob = $row['place_of_birth'] ?? null;
                $tribe = $row['tribe'] ?? null;
                $mobile = $row['mobile_number'] ?? null;
                $barangay = $row['barangay'] ?? null;
                $address = $row['specific_current_address'] ?? null;
                $marital = $row['marital_status'] ?? null;
                $educ = $row['educational_attainment'] ?? null;
            }
        }
    } catch (Throwable $ignore) {
        // view/table might not exist
    }

    $hasAnyDetails = $dob || $pob || $tribe || $mobile || $barangay || $address || $marital || $educ;
    if ($hasAnyDetails) {
        $detailsSql = 'INSERT INTO ip_member_details (ip_member_id, date_of_birth, place_of_birth, tribe, mobile_number, barangay, specific_current_address, marital_status, educational_attainment) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $detailsStmt = $conn->prepare($detailsSql);
        if (!$detailsStmt) {
            throw new Exception('Prepare failed for ip_member_details insert: ' . $conn->error);
        }
        $dobStr = (string) ($dob ?? '');
        $pobStr = (string) ($pob ?? '');
        $tribeStr = (string) ($tribe ?? '');
        $mobileStr = (string) ($mobile ?? '');
        $barangayStr = (string) ($barangay ?? '');
        $addressStr = (string) ($address ?? '');
        $maritalStr = (string) ($marital ?? '');
        $educStr = (string) ($educ ?? '');

        $detailsStmt->bind_param(
            'issssssss',
            $primaryIpMemberId,
            $dobStr,
            $pobStr,
            $tribeStr,
            $mobileStr,
            $barangayStr,
            $addressStr,
            $maritalStr,
            $educStr
        );

        $detailsStmt->execute();
        $detailsStmt->close();
    }

    // 1b) Twin updates to link ipmembers.user_id and users.ip_member_id
    $updIp = $conn->prepare('UPDATE ipmembers SET user_id = ? WHERE ip_member_id = ?');
    if (!$updIp) throw new Exception('Prepare failed for ipmembers.user_id update: ' . $conn->error);
    $updIp->bind_param('ii', $uid, $primaryIpMemberId);
    if (!$updIp->execute()) throw new Exception('Update ipmembers.user_id failed: ' . $updIp->error);
    $updIp->close();

    $updUser = $conn->prepare('UPDATE users SET ip_member_id = ? WHERE user_id = ?');
    if (!$updUser) throw new Exception('Prepare failed for users.ip_member_id update: ' . $conn->error);
    $updUser->bind_param('ii', $primaryIpMemberId, $uid);
    if (!$updUser->execute()) throw new Exception('Update users.ip_member_id failed: ' . $updUser->error);
    $updUser->close();

    // 3) Backend insert pipeline
    $fatherId = 0;
    $motherId = 0;

    if ($fatherName !== '') {
        $fatherId = upsert_member_by_full_name($conn, $fatherName);
        link_parent_child($conn, $primaryIpMemberId, $fatherId, 'father');
        $fatherReqId = create_lineage_request($conn, $primaryIpMemberId, $fatherId, $fatherName, 'male', $uid);
    }

    if ($motherName !== '') {
        $motherId = upsert_member_by_full_name($conn, $motherName);
        link_parent_child($conn, $primaryIpMemberId, $motherId, 'mother');
        $motherReqId = create_lineage_request($conn, $primaryIpMemberId, $motherId, $motherName, 'female', $uid);
    }

    $patGFId = 0;
    $patGMId = 0;

    if ($patGFName !== '') {
        $patGFId = upsert_member_by_full_name($conn, $patGFName);
        if ($fatherId > 0) {
            link_parent_child($conn, $fatherId, $patGFId, 'father');
            $patGfReqId = create_lineage_request($conn, $fatherId, $patGFId, $patGFName, 'male', $uid, $fatherReqId ?? null);
        }
    }

    if ($patGMName !== '') {
        $patGMId = upsert_member_by_full_name($conn, $patGMName);
        if ($fatherId > 0) {
            link_parent_child($conn, $fatherId, $patGMId, 'mother');
            $patGmReqId = create_lineage_request($conn, $fatherId, $patGMId, $patGMName, 'female', $uid, $fatherReqId ?? null);
        }
    }

    $matGFId = 0;
    $matGMId = 0;

    if ($matGFName !== '') {
        $matGFId = upsert_member_by_full_name($conn, $matGFName);
        if ($motherId > 0) {
            link_parent_child($conn, $motherId, $matGFId, 'father');
            $matGfReqId = create_lineage_request($conn, $motherId, $matGFId, $matGFName, 'male', $uid, $motherReqId ?? null);
        }
    }

    if ($matGMName !== '') {
        $matGMId = upsert_member_by_full_name($conn, $matGMName);
        if ($motherId > 0) {
            link_parent_child($conn, $motherId, $matGMId, 'mother');
            $matGmReqId = create_lineage_request($conn, $motherId, $matGMId, $matGMName, 'female', $uid, $motherReqId ?? null);
        }
    }

    // Great-grandfathers: link each one as father connected to respective grandparent's ip_member_id
    if ($patGFather !== '' && $patGFId > 0) {
        $patGfFatherId = upsert_member_by_full_name($conn, $patGFather);
        link_parent_child($conn, $patGFId, $patGfFatherId, 'father');
        create_lineage_request($conn, $patGFId, $patGfFatherId, $patGFather, 'male', $uid, $patGfReqId ?? null);
    }

    if ($patGMotherFather !== '' && $patGMId > 0) {
        $patGmFatherId = upsert_member_by_full_name($conn, $patGMotherFather);
        link_parent_child($conn, $patGMId, $patGmFatherId, 'father');
        create_lineage_request($conn, $patGMId, $patGmFatherId, $patGMotherFather, 'male', $uid, $patGmReqId ?? null);
    }

    if ($matGFather !== '' && $matGFId > 0) {
        $matGfFatherId = upsert_member_by_full_name($conn, $matGFather);
        link_parent_child($conn, $matGFId, $matGfFatherId, 'father');
        create_lineage_request($conn, $matGFId, $matGfFatherId, $matGFather, 'male', $uid, $matGfReqId ?? null);
    }

    if ($matGMotherFather !== '' && $matGMId > 0) {
        $matGmFatherId = upsert_member_by_full_name($conn, $matGMotherFather);
        link_parent_child($conn, $matGMId, $matGmFatherId, 'father');
        create_lineage_request($conn, $matGMId, $matGmFatherId, $matGMotherFather, 'male', $uid, $matGmReqId ?? null);
    }

    // Great-grandmothers: link each one as mother connected to respective grandparent's ip_member_id
    if ($patGFatherMother !== '' && $patGFId > 0) {
        $id = upsert_member_by_full_name($conn, $patGFatherMother);
        link_parent_child($conn, $patGFId, $id, 'mother');
        create_lineage_request($conn, $patGFId, $id, $patGFatherMother, 'female', $uid, $patGfReqId ?? null);
    }

    if ($patGMotherMother !== '' && $patGMId > 0) {
        $id = upsert_member_by_full_name($conn, $patGMotherMother);
        link_parent_child($conn, $patGMId, $id, 'mother');
        create_lineage_request($conn, $patGMId, $id, $patGMotherMother, 'female', $uid, $patGmReqId ?? null);
    }

    if ($matGFotherMother !== '' && $matGFId > 0) {
        $id = upsert_member_by_full_name($conn, $matGFotherMother);
        link_parent_child($conn, $matGFId, $id, 'mother');
        create_lineage_request($conn, $matGFId, $id, $matGFotherMother, 'female', $uid, $matGfReqId ?? null);
    }

    if ($matGMotherMother !== '' && $matGMId > 0) {
        $id = upsert_member_by_full_name($conn, $matGMotherMother);
        link_parent_child($conn, $matGMId, $id, 'mother');
        create_lineage_request($conn, $matGMId, $id, $matGMotherMother, 'female', $uid, $matGmReqId ?? null);
    }

    $conn->commit();

    respondOk(200, [
        'ok' => true,
        'message' => 'Genealogy saved successfully.',
        'primary_ip_member_id' => $primaryIpMemberId,
        'father_id' => $fatherId,
        'mother_id' => $motherId,
        'pat_grandfather_id' => $patGFId,
        'pat_grandmother_id' => $patGMId,
        'mat_grandfather_id' => $matGFId,
        'mat_grandmother_id' => $matGMId
    ]);
} catch (Throwable $e) {
    if ($conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $ignore) {}
    }
    respondErr(500, 'Genealogy processing failed.', ['details' => $e->getMessage()]);
}

?>
