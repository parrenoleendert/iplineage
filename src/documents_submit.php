<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/guards.php';
require_once __DIR__ . '/auth/auth_helpers.php';
require_once __DIR__ . '/dbconfig.php';


// Only logged-in users can submit
require_any_role(['admin', 'tribe_leader', 'ip_member']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed.';
    exit;
}

$conn = $GLOBALS['conn'] ?? ($conn ?? null);
if (!($conn instanceof mysqli)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Database connection not established.';
    exit;
}

$uid = (int) (($_SESSION['user_id'] ?? ($_SESSION['userid'] ?? 0)));
if ($uid <= 0) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'User session not found.';
    exit;
}

// Temporary storage: do NOT move to permanent uploads/ until review final click.
$uploadDir = __DIR__ . '/tmp_uploads';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Failed to create temporary uploads directory.';
        exit;
    }
}
// User-scoped temp folder so drafts can't mix across users.
$userTempDir = $uploadDir . '/' . $uid;
if (!is_dir($userTempDir)) {
    if (!mkdir($userTempDir, 0755, true) && !is_dir($userTempDir)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Failed to create user temporary uploads directory.';
        exit;
    }
}


function safe_document_type(string $s): ?string {
    $s = trim($s);
    $allowed = [
        'PSA Birth Certificate',
        'Marriage Certificate',
        'NCIP Genealogy Form',
        'Certificate of Indigency'
    ];
    return in_array($s, $allowed, true) ? $s : null;
}

function allowed_upload_extension(string $ext): bool {
    $ext = strtolower($ext);
    return in_array($ext, ['jpg', 'jpeg', 'pdf'], true);
}

function guess_extension_from_mime(string $mime): ?string {
    $mime = strtolower(trim($mime));
    // getimagesize checks will give us actual content validation
    if ($mime === 'application/pdf') return 'pdf';
    if ($mime === 'image/jpeg') return 'jpg';
    // Some servers may report jpg/jpeg differently
    return null;
}

function ext_and_size_ok(array $file): array {
    // returns [bool ok, string error, string ext]
    if (!isset($file['error'], $file['size'], $file['name'], $file['tmp_name'])) {
        return [false, 'Invalid upload payload.', ''];
    }

    if ((int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return [true, '', '']; // treat as skipped
    }

    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        return [false, 'Upload error code: ' . (int)$file['error'], ''];
    }

    $size = (int) $file['size'];
    $maxBytes = 10 * 1024 * 1024; // 10MB
    if ($size <= 0 || $size > $maxBytes) {
        return [false, 'File exceeds 10MB.', ''];
    }

    $origName = (string)$file['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!allowed_upload_extension($ext)) {
        return [false, 'Invalid file extension.', ''];
    }

    // Content-based validation
    $tmp = (string)$file['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        return [false, 'Potential file upload attack detected.', ''];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    if (!is_string($mime) || $mime === '') {
        return [false, 'Unable to detect MIME type.', ''];
    }

    $guessed = guess_extension_from_mime($mime);
    if ($guessed === null) {
        return [false, 'Invalid MIME type.', ''];
    }

    // Normalize jpeg to jpg for filename suffix
    if ($guessed === 'jpg' && $ext === 'jpeg') {
        $ext = 'jpg';
    }

    // For PDF, ensure ext is pdf too
    if ($guessed !== $ext) {
        // allow jpeg/jpg normalization but other mismatches are invalid
        if (!($guessed === 'jpg' && $ext === 'jpeg')) {
            return [false, 'File content does not match allowed type.', ''];
        }
    }

    return [true, '', $ext];
}

$filesToDocTypes = [
    'birth_cert' => 'PSA Birth Certificate',
    'marriage_cert' => 'Marriage Certificate',
    'ncip_form' => 'NCIP Genealogy Form',
    'indigency_cert' => 'Certificate of Indigency',
];

// PAGE 2 must be draft-only: no SQL INSERT/UPDATE at all.
// Still validate and move to temp folder, then store renamed filenames in session.
try {
    $timestamp = (new DateTimeImmutable('now'))->format('Ymd_His_u');

    // Initialize if not exists, but do NOT wipe existing draft data
    if (!isset($_SESSION['draft_documents']) || !is_array($_SESSION['draft_documents'])) {
        $_SESSION['draft_documents'] = [];
    }

    foreach ($filesToDocTypes as $inputName => $docTypeText) {
        if (!isset($_FILES[$inputName])) {
            continue;
        }

        $file = $_FILES[$inputName];
        [$ok, $err, $ext] = ext_and_size_ok($file);
        if (!$ok) {
            // If marriage certificate is blank, skip safely
            if ($inputName === 'marriage_cert' && ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
                continue;
            }
            throw new Exception($err !== '' ? $err : ('Invalid upload for ' . $docTypeText));
        }

        // UPLOAD_ERR_NO_FILE returns ok=true with empty ext (treated as skipped)
        if ($ext === '' || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        // Unique filename to prevent collisions
        $unique = $uid . '_' . $timestamp . '_' . bin2hex(random_bytes(6));
        $safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower(str_replace(' ', '_', $docTypeText)));
        $finalFileName = $safeBase . '_' . $unique . '.' . $ext;

        $destPath = $userTempDir . '/' . $finalFileName;

        // Move upload into temp storage
        if (!move_uploaded_file((string)$file['tmp_name'], $destPath)) {
            throw new Exception('Failed to move uploaded file for ' . $docTypeText);
        }

        // Store the renamed file in session draft_documents using required slot keys.
        $_SESSION['draft_documents'][$inputName] = $finalFileName;
    }

    // Redirect to review endpoint (final step)
    header('Location: review.php');
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Document upload failed: ' . $e->getMessage();
    exit;
}
