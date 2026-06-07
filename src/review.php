<?php
require_once __DIR__ . '/auth/guards.php';
require_once __DIR__ . '/auth/auth_helpers.php';

// Only logged-in users can review/submit registration
require_any_role(['admin', 'tribe_leader', 'ip_member']);

// Helper
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

// Draft sources
$draftPersonal  = $_SESSION['draft_personal'] ?? [];
$draftDocuments = $_SESSION['draft_documents'] ?? [];

// Database connection for tribe lookup and final submission
require_once __DIR__ . '/dbconfig.php';
$conn = $GLOBALS['conn'] ?? ($conn ?? null);
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection not established.');
}

// Resolve tribe name for display if it's an ID (like '1')
$displayTribe = $draftPersonal['tribe_clan'] ?? 'Ati Tribe';
if (is_numeric($displayTribe)) {
    $tId = (int)$displayTribe;
    $tStmt = $conn->prepare("SELECT tribe_name FROM tribes WHERE tribe_id = ?");
    if ($tStmt) {
        $tStmt->bind_param("i", $tId);
        $tStmt->execute();
        $tRes = $tStmt->get_result();
        if ($tRow = $tRes->fetch_assoc()) {
            $displayTribe = $tRow['tribe_name'];
        }
        $tStmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($draftPersonal) || !isset($draftPersonal['full_name'])) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['ok' => false, 'error' => 'Missing draft_personal.full_name.']));
    }
    function upsert_member_by_full_name(mysqli $conn, string $fullName, string $dob = ''): int {
        $fullName = trim($fullName);
        if ($fullName === '') return 0;

        // 1. Try matching by name AND birthdate if provided to ensure unique identity
        if ($dob !== '') {
            $sql = "SELECT i.ip_member_id FROM ipmembers i 
                    LEFT JOIN ip_member_details d ON i.ip_member_id = d.ip_member_id 
                    WHERE i.full_name = ? AND d.date_of_birth = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $fullName, $dob);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row) return (int)$row['ip_member_id'];
        }

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
            return (int)$row['ip_member_id'];
        }

        // Insert with user_id = NULL
        $insertSql = "INSERT INTO ipmembers (full_name, user_id) VALUES (?, NULL)";
        $insertStmt = $conn->prepare($insertSql);
        if (!$insertStmt) {
            throw new Exception('Prepare failed for ipmembers insert: ' . $conn->error);
        }
        $insertStmt->bind_param('s', $fullName);
        if (!$insertStmt->execute()) {
            throw new Exception('Insert into ipmembers failed: ' . $insertStmt->error);
        }
        $id = (int)$conn->insert_id;
        $insertStmt->close();

        // Initialize details record with birthdate if provided (useful for ghost records)
        if ($id > 0 && $dob !== '') {
            $dStmt = $conn->prepare("INSERT IGNORE INTO ip_member_details (ip_member_id, date_of_birth) VALUES (?, ?)");
            $dStmt->bind_param('is', $id, $dob);
            $dStmt->execute();
            $dStmt->close();
        }

        if ($id <= 0) {
            throw new Exception('Failed to obtain ip_member_id after insert.');
        }
        return $id;
    }

    try {
        $conn->begin_transaction();

        $uid = (int)($_SESSION['user_id'] ?? ($_SESSION['userid'] ?? 0));
        if ($uid <= 0) {
            throw new Exception('User session not found.');
        }

        // Detect if the users table primary key is 'userid', 'user_id' or 'id'
        $userPkCol = 'userid';
        $colCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'userid'");
        if ($colCheck && $colCheck->num_rows === 0) {
            $secondCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'user_id'");
            $userPkCol = ($secondCheck && $secondCheck->num_rows > 0) ? 'user_id' : 'id';
        }

        // ---- Ordered final submission block ----

        // 1) Match/Insert primary user into ipmembers by exact full_name
        $primaryFullName = trim((string)$draftPersonal['full_name']);
        $primaryDob = (string)($draftPersonal['birthdate'] ?? ($draftPersonal['date_of_birth'] ?? ''));
        $ipMemberId = upsert_member_by_full_name($conn, $primaryFullName, $primaryDob);
        if ($ipMemberId <= 0) {
            throw new Exception('Invalid primary ip_member_id.');
        }

        // 2) Instantly update ipmembers.user_id and users.ip_member_id (Handshake)
        // Note: SQL schema uses 'full_name' and 'user_id' only in ipmembers
        $updIp = $conn->prepare("UPDATE ipmembers SET user_id = ? WHERE ip_member_id = ?");
        if (!$updIp) throw new Exception('Prepare failed for ipmembers.user_id update: ' . $conn->error);
        $updIp->bind_param('ii', $uid, $ipMemberId);
        if (!$updIp->execute()) throw new Exception('Update ipmembers.user_id failed: ' . $updIp->error);
        $updIp->close();

        // 2b) Generate professional Official ID (IPVF-YEAR-00ID)
        $regYear = date('Y');
        $formattedOfficialId = "IPVF-" . $regYear . "-" . str_pad((string)$ipMemberId, 4, '0', STR_PAD_LEFT);

        $updMemberId = $conn->prepare("UPDATE ipmembers SET display_id = ? WHERE ip_member_id = ?");
        $updMemberId->bind_param('si', $formattedOfficialId, $ipMemberId);
        $updMemberId->execute();
        $updMemberId->close();

        $updUser = $conn->prepare("UPDATE users SET ip_member_id = ? WHERE $userPkCol = ?");
        if (!$updUser) throw new Exception('Prepare failed for users.ip_member_id update: ' . $conn->error);
        $updUser->bind_param('ii', $ipMemberId, $uid);
        if (!$updUser->execute()) throw new Exception('Update users.ip_member_id failed: ' . $updUser->error);
        $updUser->close();

        // 3) Insert demographics into ip_member_details (only primary)
        // Only insert if your ip_member_details columns still exist.
        // Use draftPersonal keys when present.
        $dateOfBirth = (string)($draftPersonal['birthdate'] ?? ($draftPersonal['date_of_birth'] ?? ''));
        $placeOfBirth = (string)($draftPersonal['place_of_birth'] ?? ($draftPersonal['placeOfBirth'] ?? ''));
        $tribe = (string)($draftPersonal['tribe_clan'] ?? ($draftPersonal['tribe'] ?? '1'));
        $mobile = (string)($draftPersonal['contact_information'] ?? ($draftPersonal['mobile_number'] ?? ''));
        $barangay = (string)($draftPersonal['barangay'] ?? ($draftPersonal['barangay_name'] ?? ''));
        $address = (string)($draftPersonal['current_address'] ?? ($draftPersonal['address'] ?? ''));
        
        $maritalStatus = (string)($draftPersonal['marital_status'] ?? 'single');
        $gender = (string)($draftPersonal['gender'] ?? 'male');
        $educ = (string)($draftPersonal['educational_attainment'] ?? ($draftPersonal['educational_level'] ?? ''));

        $maritalStatusVal = $maritalStatus;
        if (strtolower($maritalStatusVal) === 'married') $maritalStatusVal = 'Married';

        // Use UPSERT logic for details to handle cases where a ghost record already exists
        $detailsSql = "INSERT INTO ip_member_details (ip_member_id, date_of_birth, place_of_birth, tribe, mobile_number, barangay, specific_current_address, sex, marital_status, educational_attainment) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE 
                       date_of_birth = VALUES(date_of_birth), 
                       place_of_birth = VALUES(place_of_birth), 
                       tribe = VALUES(tribe), 
                       mobile_number = VALUES(mobile_number), 
                       barangay = VALUES(barangay), 
                       specific_current_address = VALUES(specific_current_address), 
                       sex = VALUES(sex), 
                       marital_status = VALUES(marital_status), 
                       educational_attainment = VALUES(educational_attainment)";
        $detailsStmt = $conn->prepare($detailsSql);
        if (!$detailsStmt) throw new Exception('Prepare failed for ip_member_details insert: ' . $conn->error);

        $detailsStmt->bind_param(
            'isssssssss',
            $ipMemberId,
            $dateOfBirth,
            $placeOfBirth,
            $tribe,
            $mobile,
            $barangay,
            $address,
            $gender,
            $maritalStatusVal,
            $educ
        );
        if (!$detailsStmt->execute()) throw new Exception('Insert ip_member_details failed: ' . $detailsStmt->error);
        $detailsStmt->close();

        // 5) Move uploaded files and insert into ip_member_documents
        $docMap = [
            'birth_cert' => 'PSA Birth Certificate',
            'marriage_cert' => 'Marriage Certificate',
            'ncip_form' => 'NCIP Genealogy Form',
            'indigency_cert' => 'Certificate of Indigency'
        ];

        $permDir = __DIR__ . '/uploads/' . $uid;
        if (!is_dir($permDir)) mkdir($permDir, 0755, true);
        
        $tmpDir = __DIR__ . '/tmp_uploads/' . $uid;
        foreach ($draftDocuments as $inputName => $filename) {
            $sourcePath = $tmpDir . '/' . $filename;
            $destPath = $permDir . '/' . $filename;
            if (file_exists($sourcePath)) {
                if (rename($sourcePath, $destPath)) {
                    $docType = $docMap[$inputName] ?? 'Other';
                    $stmtDoc = $conn->prepare("INSERT INTO ip_member_documents (ip_member_id, document_type, file_name) VALUES (?, ?, ?)");
                    $stmtDoc->bind_param('iss', $ipMemberId, $docType, $filename);
                    $stmtDoc->execute();
                    $stmtDoc->close();
                }
            }
        }

        // 6) Create Application Entry for Elder Verification
        // Status starts as 'pending_elder'
        $appSql = "INSERT INTO applications (ip_member_id, status, application_date) VALUES (?, 'pending_elder', NOW())";
        $appStmt = $conn->prepare($appSql);
        if (!$appStmt) throw new Exception('Prepare failed for applications entry: ' . $conn->error);
        $appStmt->bind_param('i', $ipMemberId);
        if (!$appStmt->execute()) throw new Exception('Application creation failed: ' . $appStmt->error);
        $appStmt->close();

        // Clear session drafts
        unset($_SESSION['draft_personal'], $_SESSION['draft_documents']);

        // Set registration complete flag
        $_SESSION['ip_registration_complete'] = true; 

        $conn->commit();

        // Redirect to standby verification page
        header('Location: verify.php');
        exit;
    } catch (Throwable $e) {
        if ($conn instanceof mysqli) {
            try { $conn->rollback(); } catch (Throwable $ignore) {}
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['ok' => false, 'error' => 'Final submission failed.', 'details' => $e->getMessage()]));
    }
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Review & Submit</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
   <link rel="stylesheet" href="./output.css">
  <meta name="view-transition" content="same-origin" />
  <style>
    body {
      font-family: 'Inter', sans-serif;
    }
  </style>
</head>
<body>
  <div class="min-h-screen bg-[#F9FAFB] flex flex-col antialiased animate-page-in"">
    
    <div class="flex flex-row justify-between items-center w-full sticky top-0 z-50 bg-white px-8 py-4 border-b border-gray-100 shadow-xs  animate-page-in">
    <div class="flex items-center gap-3 font-bold text-gray-900 text-lg tracking-tight">
      <div class="w-8 h-8 rounded-full bg-[#1A1A1A]"></div>
      Ankan
    </div>
    <div class="ml-auto flex items-center gap-4">
      <a href="landingpage.html" class="text-gray-400 hover:text-gray-800 text-sm font-medium inline-flex items-center gap-1 transition">
        Back
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </a>
    </div>
  </div>

    <div class="flex-1 max-w-5xl w-full mx-auto px-4 py-10 flex flex-col items-center">

      <div class="w-full max-w-3xl mx-auto px-4 py-8">
  <ol class="flex items-center w-full">
    
    <li class="flex flex-col items-center relative flex-1 after:content-[''] after:w-full after:h-0.5 after:bg-[#22C55E] after:inline-block after:absolute after:top-5 after:left-1/2 last:after:hidden">
      <div class="z-10 flex items-center justify-center w-9 h-9 rounded-full bg-[#22C55E] text-white text-sm font-bold shadow-xs">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="w-4 h-4">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
        </svg>
      </div>
      <span class="mt-2 text-xs font-semibold text-gray-500 text-center">
        Personal
      </span>
    </li>

    <li class="flex flex-col items-center relative flex-1 after:content-[''] after:w-full after:h-0.5 after:bg-[#22C55E] after:inline-block after:absolute after:top-5 after:left-1/2 last:after:hidden">
      <div class="z-10 flex items-center justify-center w-9 h-9 rounded-full bg-[#22C55E] text-white text-sm font-bold shadow-xs">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="w-4 h-4">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
        </svg>
      </div>
      <span class="mt-2 text-xs font-semibold text-gray-500 text-center">
        Documents
      </span>
    </li>

    <li class="flex flex-col items-center relative flex-1 after:content-[''] after:w-full after:h-0.5 after:bg-[#CDD1D0] after:inline-block after:absolute after:top-5 after:left-1/2 last:after:hidden">
      <div class="z-10 flex items-center justify-center w-9 h-9 rounded-full bg-[#08161B] text-white text-sm font-bold ring-4 ring-gray-100 shadow-xs">
        3
      </div>
      <span class="mt-2 text-xs font-bold text-[#08161B] text-center">
        Review
      </span>
    </li>

    <li class="flex flex-col items-center relative flex-1 after:content-[''] after:w-full after:h-0.5 after:bg-[#CDD1D0] after:inline-block after:absolute after:top-5 after:left-1/2 last:after:hidden">
      <div class="z-10 flex items-center justify-center w-9 h-9 rounded-full bg-white border-2 border-gray-200 text-gray-400 text-sm font-bold">
        4
      </div>
      <span class="mt-2 text-xs font-medium text-gray-400 text-center">
        Verify
      </span>
    </li>

  </ol>
</div>

      <div class="w-full bg-white border border-gray-100 rounded-2xl shadow-sm p-8 md:p-12">
        
        <div class="mb-8">
          <h2 class="text-2xl font-bold text-gray-900 tracking-tight mb-1">Review & Submit</h2>
          <p class="text-sm text-gray-400">Please double-check your information before final submission.</p>
        </div>

        <div class="flex flex-col gap-y-8">
          
          <div class="border border-gray-200 rounded-xl overflow-hidden bg-white">
            <div class="bg-[#2D2A2A] text-white px-5 py-3 flex justify-between items-center text-sm font-semibold">
              <span>Personal Details</span>
              <a href="personal.php" class="text-xs font-medium text-gray-300 hover:text-white transition cursor-pointer">Edit Details</a>
            </div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-y-6 gap-x-4">
              <div>
                <p class="text-[11px] text-gray-400 uppercase tracking-wider font-medium mb-1">Name</p>
                <p class="text-sm font-bold text-gray-800"><?= h($draftPersonal['full_name'] ?? 'Not provided') ?></p>
              </div>
              <div>
                <p class="text-[11px] text-gray-400 uppercase tracking-wider font-medium mb-1">Gender / Age</p>
                <p class="text-sm font-bold text-gray-800"><?= h(ucfirst($draftPersonal['gender'] ?? 'Not provided')) ?></p>
              </div>
              <div>
                <p class="text-[11px] text-gray-400 uppercase tracking-wider font-medium mb-1">Birthdate</p>
                <p class="text-sm font-bold text-gray-800"><?= h($draftPersonal['birthdate'] ?? 'Not provided') ?></p>
              </div>
              <div>
                <p class="text-[11px] text-gray-400 uppercase tracking-wider font-medium mb-1">Tribe</p>
                <p class="text-sm font-bold text-gray-800"><?= h($displayTribe) ?></p>
              </div>
              <div>
                <p class="text-[11px] text-gray-400 uppercase tracking-wider font-medium mb-1">Contact</p>
                <p class="text-sm font-bold text-gray-800"><?= h($draftPersonal['contact_information'] ?? 'Not provided') ?></p>
              </div>
              <div>
                <p class="text-[11px] text-gray-400 uppercase tracking-wider font-medium mb-1">Marital Status</p>
                <p class="text-sm font-bold text-gray-800"><?= h($draftPersonal['marital_status'] ?? 'Single') ?></p>
              </div>
              <div class="md:col-span-3">
                <p class="text-[11px] text-gray-400 uppercase tracking-wider font-medium mb-1">Current Address</p>
                <p class="text-sm font-bold text-gray-800"><?= h($draftPersonal['current_address'] ?? 'Not provided') ?></p>
              </div>
            </div>
          </div>

          <div class="border border-gray-200 rounded-xl overflow-hidden bg-white">
            <div class="bg-[#2D2A2A] text-white px-5 py-3 flex justify-between items-center text-sm font-semibold">
              <span>Uploaded Files</span>
              <a href="documents.php" class="text-xs font-medium text-gray-300 hover:text-white transition cursor-pointer">Manage Files</a>
            </div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
              
              <?php 
              $docMap = [
                  'birth_cert' => 'PSA Birth Certificate',
                  'marriage_cert' => 'Marriage Certificate',
                  'ncip_form' => 'NCIP Genealogy Form',
                  'indigency_cert' => 'Certificate of Indigency'
              ];
              foreach($docMap as $key => $label): 
                  $file = $draftDocuments[$key] ?? null;
                  if (!$file && $key === 'marriage_cert') continue; // Skip optional marriage cert if not uploaded
              ?>
                <div class="border border-gray-200 rounded-xl p-3.5 flex items-center justify-between bg-white">
                  <div class="flex items-center gap-3">
                    <div class="bg-gray-50 rounded-lg text-gray-400 font-bold text-[11px] p-2 tracking-wide border border-gray-100 uppercase">
                      <?= $file ? strtoupper(pathinfo($file, PATHINFO_EXTENSION)) : 'N/A' ?>
                    </div>
                    <div>
                      <p class="text-xs font-bold text-gray-800 truncate max-w-[200px]"><?= $file ? h($file) : 'No file uploaded' ?></p>
                      <p class="text-[10px] text-gray-400 mt-0.5"><?= $label ?></p>
                      <?php if ($file): ?>
                        <?php $uid = (int)($_SESSION['user_id'] ?? 0); ?>
                        <a href="tmp_uploads/<?= $uid ?>/<?= h($file) ?>" target="_blank" class="text-[10px] text-blue-600 hover:text-blue-800 font-medium underline mt-1 inline-block">View Document</a>
                      <?php endif; ?>
                    </div>
                  </div>
                  <?php if ($file): ?>
                    <div class="text-green-500">
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5">
                        <path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12zm13.36-1.814a.75.75 0 10-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 00-1.06 1.06l2.5 2.5a.75.75 0 001.14-.1l3.75-5.25z" clip-rule="evenodd" />
                      </svg>
                    </div>
                  <?php else: ?>
                    <div class="text-red-400">
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5">
                        <path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25zm-1.72 6.97a.75.75 0 10-1.06 1.06L10.94 12l-1.72 1.72a.75.75 0 101.06 1.06L12 13.06l1.72 1.72a.75.75 0 101.06-1.06L13.06 12l1.72-1.72a.75.75 0 10-1.06-1.06L12 10.94l-1.72-1.72z" clip-rule="evenodd" />
                      </svg>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>

            </div>
          </div>

        </div>

        <form method="POST">
         <div class="mt-12 pt-6 border-t border-gray-100 flex justify-end">
          <button type="submit" class="bg-black hover:bg-gray-900 active:scale-98 text-white font-semibold py-3 px-8 rounded-xl shadow-xs text-sm transition duration-150 text-center">
            Submit Application
          </button>
        </div>
        </form>

      </div>

    </div>
  </div> 
</body>
</html>