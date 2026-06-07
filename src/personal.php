<?php
require_once __DIR__ . '/auth/guards.php';
require_once __DIR__ . '/auth/auth_helpers.php';
require_any_role(['admin', 'tribe_leader', 'ip_member']);

// If ip_registration_complete is explicitly false, keep user on this page.
// Also block direct navigation back to dashboard for unregistered users.
if (isset($_SESSION['role']) && normalize_role((string)($_SESSION['role'] ?? '')) === 'ip_member') {
    $isComplete = (bool)($_SESSION['ip_registration_complete'] ?? false);
    if ($isComplete) {
        header('Location: ip_member/dashboard.php');
        exit;
    }
}

$_SESSION['ip_registration_complete'] = (bool)($_SESSION['ip_registration_complete'] ?? false);

require_once __DIR__ . '/dbconfig.php';
$conn = $GLOBALS['conn'] ?? ($conn ?? null);
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection not established. Check src/dbconfig.php and MySQL service.');
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Registration</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="./output.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
  <script src="./personal.js"></script>
  <meta name="view-transition" content="same-origin" />
  <style>
    body { font-family: 'Inter', sans-serif; }
  </style>
</head>
<body class="font-sans antialiased bg-[#fafaf9] animate-page-in">

  <div class="flex flex-row justify-between items-center w-full sticky top-0 z-50 bg-white px-8 py-4 border-b border-gray-100 shadow-xs  animate-page-in">
    <div class="flex items-center gap-3 font-bold text-gray-900 text-lg tracking-tight">
      <div class="w-8 h-8 rounded-full bg-[#1A1A1A]"></div>
      Ankan
    </div>
    <div class="ml-auto">
      <a href="logout.php" class="text-gray-400 hover:text-gray-800 text-sm font-medium inline-flex items-center gap-1 transition">
        Back
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </a>
    </div>
  </div>

  <div class="max-w-4xl w-full mx-auto px-4 py-10 flex flex-col items-center" >
    <div class="w-full max-w-3xl mb-10 px-4 items-center text-center">
      <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Complete Your Registration</h1>
      <p class="text-sm text-gray-400 mt-0.5">Please provide the following information to complete your registration.</p>
    </div>

    <div class="w-full max-w-3xl mb-10 px-4">
      <ol class="flex items-center w-full">
        <li class="flex flex-col items-center relative flex-1 after:content-[''] after:w-full after:h-0.5 after:bg-[#CDD1D0] after:inline-block after:absolute after:top-5 after:left-1/2 last:after:hidden">
          <div class="z-10 flex items-center justify-center w-9 h-9 rounded-full bg-[#08161B] text-white text-sm font-bold ring-4 ring-gray-100 shadow-xs">1</div>
          <span class="mt-2 text-xs font-bold text-[#08161B] text-center">Personal</span>
        </li>
        <li class="flex flex-col items-center relative flex-1 after:content-[''] after:w-full after:h-0.5 after:bg-[#CDD1D0] after:inline-block after:absolute after:top-5 after:left-1/2 last:after:hidden">
          <div class="z-10 flex items-center justify-center w-9 h-9 rounded-full bg-white border-2 border-gray-200 text-gray-400 text-sm font-bold">2</div>
          <span class="mt-2 text-xs font-medium text-gray-400 text-center">Documents</span>
        </li>
        <li class="flex flex-col items-center relative flex-1 after:content-[''] after:w-full after:h-0.5 after:bg-[#CDD1D0] after:inline-block after:absolute after:top-5 after:left-1/2 last:after:hidden">
          <div class="z-10 flex items-center justify-center w-9 h-9 rounded-full bg-white border-2 border-gray-200 text-gray-400 text-sm font-bold">3</div>
          <span class="mt-2 text-xs font-medium text-gray-400 text-center">Review</span>
        </li>
        <li class="flex flex-col items-center relative flex-1 after:content-[''] after:w-full after:h-0.5 after:bg-[#CDD1D0] after:inline-block after:absolute after:top-5 after:left-1/2 last:after:hidden">
          <div class="z-10 flex items-center justify-center w-9 h-9 rounded-full bg-white border-2 border-gray-200 text-gray-400 text-sm font-bold">4</div>
          <span class="mt-2 text-xs font-medium text-gray-400 text-center">Verify</span>
        </li>
      </ol>
    </div>

    <div class="w-full bg-white rounded-2xl border border-gray-100 p-6 md:p-10 shadow-sm">

<?php
$draftPersonal = $_SESSION['draft_personal'] ?? [];
$draftMarital = (string)($draftPersonal['marital_status'] ?? '');
$draftSpouse = (string)($draftPersonal['spouse_name'] ?? ($draftPersonal['spouse_full_name'] ?? ''));
$draftSpouseBirthdate = (string)($draftPersonal['spouse_birthdate'] ?? '');
$draftFullNameParts = explode(' ', (string)($draftPersonal['full_name'] ?? ''));
$draftFirstName = (string)($draftPersonal['first_name'] ?? ($draftFullNameParts[0] ?? ''));
$draftMiddleName = (string)($draftPersonal['middle_name'] ?? '');
$draftLastName = (string)($draftPersonal['last_name'] ?? ($draftFullNameParts[count($draftFullNameParts)-1] ?? ''));
$draftBirthdate = (string)($draftPersonal['birthdate'] ?? '');
$draftPlaceOfBirth = (string)($draftPersonal['place_of_birth'] ?? '');
$draftTribe = (string)($draftPersonal['tribe_clan'] ?? ($draftPersonal['tribe'] ?? ''));
$draftMobile = (string)($draftPersonal['contact_information'] ?? '');
$draftBarangay = (string)($draftPersonal['barangay'] ?? '');
$draftCurrentAddress = (string)($draftPersonal['current_address'] ?? '');
$draftEduc = (string)($draftPersonal['educational_attainment'] ?? '');

$maritalLower = strtolower(trim($draftMarital));
?>

<form method="POST" action="personal_submit.php">

      <div class="mb-8">
        <h2 class="text-xl font-bold text-gray-900 tracking-tight">Personal Information</h2>
        <p class="text-xs text-gray-400 mt-0.5">Please ensure your details match your government ID's</p>
      </div>

      <div class="text-gray-400 border-b border-gray-200 pb-1 uppercase text-xs font-bold tracking-wider mb-6">Fullname & Identity</div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
        <div>
          <label class="block mb-1.5 font-semibold text-gray-800 text-sm">First Name</label>
<input type="text" name="first_name" required value="<?php echo htmlspecialchars($draftFirstName, ENT_QUOTES, 'UTF-8'); ?>" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="Enter First Name">
        </div>

        <div>
          <label class="block mb-1.5 font-semibold text-gray-800 text-sm">Middle Name</label>
<input type="text" name="middle_name" value="<?php echo htmlspecialchars($draftMiddleName, ENT_QUOTES, 'UTF-8'); ?>" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="Enter Middle Name">
        </div>

        <div>
          <label class="block mb-1.5 font-semibold text-gray-800 text-sm">Last Name</label>
<input type="text" name="last_name" required value="<?php echo htmlspecialchars($draftLastName, ENT_QUOTES, 'UTF-8'); ?>" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="Enter Last Name">
        </div>

        <div>
          <label class="block mb-1.5 font-semibold text-gray-800 text-sm">Gender</label>
          <div class="relative">
            <select name="gender" required class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 bg-white outline-none focus:border-black appearance-none transition pr-10">
              <option value="" disabled <?php echo empty($draftPersonal['gender']) ? 'selected' : ''; ?>>Select gender</option>
              <option value="male" <?php echo (isset($draftPersonal['gender']) && strtolower($draftPersonal['gender']) === 'male') ? 'selected' : ''; ?>>Male</option>
              <option value="female" <?php echo (isset($draftPersonal['gender']) && strtolower($draftPersonal['gender']) === 'female') ? 'selected' : ''; ?>>Female</option>
            </select>
          </div>
        </div>

        <div>
          <label class="block mb-1.5 font-semibold text-gray-800 text-sm">Date of Birth</label>
<input type="date" name="birthdate" value="<?php echo htmlspecialchars($draftBirthdate, ENT_QUOTES, 'UTF-8'); ?>" class="w-full text-gray-500 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black uppercase text-sm transition">
        </div>

        <div>
          <label class="block mb-1.5 font-semibold text-gray-800 text-sm">Place of Birth</label>
<input type="text" name="place_of_birth" required value="<?php echo htmlspecialchars($draftPlaceOfBirth, ENT_QUOTES, 'UTF-8'); ?>" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="City/Municipality, Province">
        </div>

        <div>
          <label class="block mb-1.5 font-semibold text-gray-800 text-sm">Tribe</label>
          <div class="relative">
<select name="tribe_clan" required class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 bg-white outline-none focus:border-black appearance-none transition pr-10">
              <option value="" disabled <?php echo $draftTribe===''?'selected':''; ?>>Select Tribe</option>
              <option value="1" <?php echo ((string)$draftTribe==='1')?'selected':''; ?>>Ati Tribe</option>
            </select>
          </div>
        </div>
      </div>

      <div class="text-gray-400 border-b border-gray-200 pb-1 uppercase text-xs font-bold tracking-wider mb-6">Address & Contact</div>

      <div class="flex flex-col gap-y-6 mb-10">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label class="block mb-1.5 font-semibold text-gray-800 text-sm">Mobile Number</label>
<input type="text" name="contact_information" required value="<?php echo htmlspecialchars($draftMobile, ENT_QUOTES, 'UTF-8'); ?>" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="+63 9XX XXX XXXX">
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div>
            <label class="block mb-1.5 font-semibold text-gray-800 text-sm">Province</label>
            <select class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 bg-white outline-none focus:border-black appearance-none transition pr-10" disabled>
              <option value="antique">Antique</option>
            </select>
          </div>

          <div>
            <label class="block mb-1.5 font-semibold text-gray-800 text-sm">Municipality</label>
            <select class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 bg-white outline-none focus:border-black appearance-none transition pr-10" disabled>
              <option value="sibalom">Sibalom</option>
            </select>
          </div>

          <div>
            <label class="block mb-1.5 font-semibold text-gray-800 text-sm">Barangay</label>
<select name="barangay" required class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 bg-white outline-none focus:border-black appearance-none transition pr-10">
              <option value="" disabled <?php echo $draftBarangay===''?'selected':''; ?>>Select Barangay</option>
              <option value="Villafont" <?php echo ((string)$draftBarangay==='Villafont')?'selected':''; ?>>Villafont</option>
            </select>
          </div>
        </div>

        <div>
          <label class="block mb-1.5 font-semibold text-gray-800 text-sm">Specific Current Address</label>
<input type="text" name="current_address" value="<?php echo htmlspecialchars($draftCurrentAddress, ENT_QUOTES, 'UTF-8'); ?>" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="House No., Street Name, etc">
        </div>
      </div>

      <div class="text-gray-400 border-b border-gray-200 pb-1 uppercase text-xs font-bold tracking-wider mb-6">Background</div>

      <div class="flex flex-col gap-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <label class="block mb-1.5 font-semibold text-gray-800 text-sm">Marital Status</label>
<select name="marital_status" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 bg-white outline-none focus:border-black appearance-none transition pr-10" enabled>
              <option value="single" <?php echo $draftMarital==='Single'?'selected':''; ?>>Single</option>
              <option value="married" <?php echo $draftMarital==='Married'?'selected':''; ?>>Married</option>
              <option value="divorced" <?php echo $draftMarital==='Divorced'?'selected':''; ?>>Divorced</option>
              <option value="widowed" <?php echo $draftMarital==='Widowed'?'selected':''; ?>>Widowed</option>
            </select>
          </div>

          <div>
            <label class="block mb-1.5 font-semibold text-gray-800 text-sm">Educational Attainment</label>
            <select name="educational_attainment" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 bg-white outline-none focus:border-black appearance-none transition pr-10" enabled>
              <option value="" disabled <?php echo $draftEduc === '' ? 'selected' : ''; ?>>Select level</option>
              <option value="elementary" <?php echo strtolower($draftEduc) === 'elementary' ? 'selected' : ''; ?>>Elementary</option>
              <option value="highschool" <?php echo strtolower($draftEduc) === 'highschool' ? 'selected' : ''; ?>>High School</option>
              <option value="college" <?php echo strtolower($draftEduc) === 'college' ? 'selected' : ''; ?>>College Graduate</option>
            </select>
          </div>
        </div>

        <div class="mt-6 pt-6 border-t border-gray-100 flex justify-end">
          <button type="submit" class="bg-black hover:bg-gray-900 active:scale-98 text-white font-semibold py-3 px-8 rounded-xl shadow-xs text-sm transition duration-150 text-center">
            Save & Continue
          </button>
        </div>
      </div>

      </form>

    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      flatpickr("input[type='date']", {
        dateFormat: "Y-m-d",
        altInput: true,
        altFormat: "F j, Y",
        monthSelectorType: "dropdown",
        yearSelectorType: "static"
      });
    });
  </script>
</body>
</html>
