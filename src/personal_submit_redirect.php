<?php


// Compatibility redirect endpoint for Personal submission.
// It calls the existing personal_submit.php logic by including it,
// but after success it navigates to genealogy.php.

require_once __DIR__ . '/auth/guards.php';
require_once __DIR__ . '/auth/auth_helpers.php';
require_any_role(['admin', 'tribe_leader', 'ip_member']);

// Run the existing handler.
// NOTE: personal_submit.php outputs JSON and exits, so we cannot simply include it.
// Instead, we implement a small re-run of the draft logic (copied from personal_submit.php)
// and then redirect.

require_once __DIR__ . '/dbconfig.php';
$conn = $GLOBALS['conn'] ?? ($conn ?? null);
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection not established. Check src/dbconfig.php and MySQL service.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed.');
}

$uid = (int)($_SESSION['user_id'] ?? ($_SESSION['userid'] ?? 0));
if ($uid <= 0) {
    http_response_code(401);
    die('User session not found.');
}

$payload = $_POST;

function respondAndRedirectError(string $msg): void {
    http_response_code(400);
    // Keep it simple: show error and stop.
    die($msg);
}

function normalize_full_name(string $name): string {
    $name = trim($name);
    $name = preg_replace('/\s+/', ' ', $name);
    return $name;
}

$firstName = trim((string)($payload['first_name'] ?? ''));
$middleName = trim((string)($payload['middle_name'] ?? ''));
$lastName = trim((string)($payload['last_name'] ?? ''));

$fullName = normalize_full_name(trim($firstName . ' ' . $middleName . ' ' . $lastName));
if ($fullName === '') {
    respondAndRedirectError('Full name is required.');
}

$spouseFullName = normalize_full_name((string)($payload['spouse_name'] ?? ($payload['spouse_full_name'] ?? '')));

$placeOfBirth = trim((string)($payload['place_of_birth'] ?? ''));
$tribe = trim((string)($payload['tribe_clan'] ?? ($payload['tribe'] ?? '')));
$mobile = trim((string)($payload['contact_information'] ?? ''));
$barangay = trim((string)($payload['barangay'] ?? ''));
$address = trim((string)($payload['current_address'] ?? ''));
$maritalStatus = trim((string)($payload['marital_status'] ?? ''));
$educAttain = trim((string)($payload['educational_attainment'] ?? ($payload['educational_level'] ?? '')));

if ($maritalStatus === '') {
    $maritalStatus = trim((string)($payload['maritalStatus'] ?? ''));
}
if ($maritalStatus === '') {
    $maritalStatus = trim((string)($payload['marital'] ?? ''));
}

if ($educAttain === '') {
    $educAttain = trim((string)($payload['educational_attainment'] ?? ($payload['educational_level'] ?? ($payload['educationalAttainment'] ?? ''))));
}

$maritalStatusVal = $maritalStatus;
if (strtolower($maritalStatusVal) === 'married') {
    $maritalStatusVal = 'Married';
}

$_SESSION['draft_personal'] = [
    'full_name' => $fullName,
    'birthdate' => trim((string)($payload['birthdate'] ?? ($payload['date_of_birth'] ?? ''))),
    'place_of_birth' => $placeOfBirth,
    'tribe_clan' => $tribe,
    'contact_information' => $mobile,
    'barangay' => $barangay,
    'current_address' => $address,
    'marital_status' => $maritalStatusVal,
    'educational_attainment' => $educAttain,
    'spouse_full_name' => $maritalStatusVal === 'Married' ? $spouseFullName : '',
    'spouse_name' => $maritalStatusVal === 'Married' ? $spouseFullName : ''
];

// Directly go to genealogy.
header('Location: genealogy.php');
exit;

?>


