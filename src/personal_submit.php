<?php
require_once __DIR__ . '/auth/guards.php';
require_once __DIR__ . '/auth/auth_helpers.php';

// Ensure only logged-in users can submit
require_any_role(['admin', 'tribe_leader', 'ip_member']);

require_once __DIR__ . '/dbconfig.php';
$conn = $GLOBALS['conn'] ?? ($conn ?? null);
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode([
        'ok' => false,
        'error' => 'Database connection not established.'
    ]));
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode([
        'ok' => false,
        'error' => 'Method not allowed.'
    ]));
}

$uid = (int)($_SESSION['user_id'] ?? ($_SESSION['userid'] ?? 0));
if ($uid <= 0) {
    http_response_code(401);
    die(json_encode([
        'ok' => false,
        'error' => 'User session not found.'
    ]));
}


$payload = $_POST;

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

function normalize_string(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    $s = preg_replace('/\s+/', ' ', $s);
    return mb_convert_case($s, MB_CASE_TITLE, "UTF-8");
}

// Primary (logged-in) user fields
$firstName = normalize_string((string)($payload['first_name'] ?? ''));
$middleName = normalize_string((string)($payload['middle_name'] ?? ''));
$lastName = normalize_string((string)($payload['last_name'] ?? ''));

$fullName = normalize_string(trim($firstName . ' ' . $middleName . ' ' . $lastName));
if ($fullName === '') {
    http_response_code(422);
    die(json_encode([
        'ok' => false,
        'error' => 'Full name is required.'
    ]));
}

$placeOfBirth = normalize_string((string)($payload['place_of_birth'] ?? ''));
$tribe = trim((string)($payload['tribe_clan'] ?? ($payload['tribe'] ?? '')));
$mobile = trim((string)($payload['contact_information'] ?? ''));
$barangay = normalize_string((string)($payload['barangay'] ?? ''));
$address = normalize_string((string)($payload['current_address'] ?? ''));
$maritalStatus = trim((string)($payload['marital_status'] ?? ''));
$educAttain = trim((string)($payload['educational_attainment'] ?? ($payload['educational_level'] ?? '')));

// Backward compatible mapping
if ($maritalStatus === '') {
    $maritalStatus = trim((string)($payload['maritalStatus'] ?? ''));
}
if ($maritalStatus === '') {
    $maritalStatus = trim((string)($payload['marital'] ?? ''));
}

if ($educAttain === '') {
    // Defensive fallback: educational attainment might arrive under different keys depending on legacy pages.
    $educAttain = trim((string)($payload['educational_attainment'] ?? ($payload['educational_level'] ?? ($payload['educationalAttainment'] ?? ''))));
}

// For ip_member_details (primary user only) - keep using existing posted fields
$dateOfBirthRaw = trim((string)($payload['birthdate'] ?? ($payload['date_of_birth'] ?? '')));

try {
    // PAGE 1 should only save drafts to session.
    // No DB writes here.

    $maritalStatusVal = $maritalStatus;
    if (strtolower($maritalStatusVal) === 'married') {
        $maritalStatusVal = 'Married';
    } elseif (strtolower($maritalStatusVal) === 'widowed') {
        $maritalStatusVal = 'Widowed';
    }

    $spouseAllowed = in_array($maritalStatusVal, ['Married', 'Widowed'], true);

    $_SESSION['draft_personal'] = [
        'full_name' => $fullName,
        'birthdate' => $dateOfBirthRaw,
        'place_of_birth' => $placeOfBirth,
        'tribe_clan' => $tribe,
        'contact_information' => $mobile,
        'barangay' => $barangay,
        'current_address' => $address,
        'marital_status' => $maritalStatusVal,
        'gender' => trim((string)($payload['gender'] ?? 'male')),
        'educational_attainment' => $educAttain
    ];

    // Draft lineage will be captured from genealogy.php -> review.php

    // Hard redirect so browser always navigates to the next step.
    header('Location: documents.php');
    exit;

} catch (Throwable $e) {
    respondErr(500, 'Draft personal saving failed.', [
        'details' => $e->getMessage()
    ]);
}
