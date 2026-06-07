<?php
require_once __DIR__ . '/auth/guards.php';
require_once __DIR__ . '/auth/auth_helpers.php';
require_once __DIR__ . '/dbconfig.php';

require_authenticated_user();

$uid = (int)$_SESSION['user_id'];
$status = 'pending_elder'; // Default

// Fetch the actual status from the database
$sql = "SELECT a.status FROM ipmembers i 
        JOIN applications a ON i.ip_member_id = a.ip_member_id 
        WHERE i.user_id = ? ORDER BY a.application_id DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $uid);
$stmt->execute();
$res = $stmt->get_result();
$app = $res->fetch_assoc();

if ($app) {
    $status = $app['status'];
}

// If they were approved while on this page, let them go to the dashboard
if ($status === 'approved') {
    $_SESSION['ip_registration_complete'] = true;
    header('Location: ip_member/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Application Submitted</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
   <link rel="stylesheet" href="./output.css">
  <meta name="view-transition" content="same-origin">
  <style>
    body {
      font-family: 'Inter', sans-serif;
    }
  </style>
</head>
<body class="font-sans antialiased bg-[#fafaf9] animate-page-in"">

  <div class="flex flex-row justify-between items-center w-full sticky top-0 z-50 bg-white px-8 py-4 border-b border-gray-100 shadow-xs  animate-page-in">
    <div class="flex items-center gap-3 font-bold text-gray-900 text-lg tracking-tight">
      <div class="w-8 h-8 rounded-full bg-[#1A1A1A]"></div>
      Ankan
    </div>
  </div>

  <div class="flex-1 max-w-4xl w-full mx-auto px-4 py-10 flex flex-col items-center">
      
    <div class="w-full max-w-3xl mb-10 px-4">
      <ol class="flex items-center w-full">
        
        <li class="flex flex-col items-center relative flex-1 after:content-[''] after:w-full after:h-0.5 after:bg-[#22C55E] after:inline-block after:absolute after:top-5 after:left-1/2 last:after:hidden">
          <div class="z-10 flex items-center justify-center w-9 h-9 rounded-full bg-[#22C55E] text-white text-sm font-bold shadow-xs">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
          </div>
          <span class="mt-2 text-xs font-semibold text-gray-500 text-center">Personal</span>
        </li>

        <li class="flex flex-col items-center relative flex-1 after:content-[''] after:w-full after:h-0.5 after:bg-[#22C55E] after:inline-block after:absolute after:top-5 after:left-1/2 last:after:hidden">
          <div class="z-10 flex items-center justify-center w-9 h-9 rounded-full bg-[#22C55E] text-white text-sm font-bold shadow-xs">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
          </div>
          <span class="mt-2 text-xs font-semibold text-gray-500 text-center">Documents</span>
        </li>

        <li class="flex flex-col items-center relative flex-1 after:content-[''] after:w-full after:h-0.5 after:bg-[#22C55E] after:inline-block after:absolute after:top-5 after:left-1/2 last:after:hidden">
          <div class="z-10 flex items-center justify-center w-9 h-9 rounded-full bg-[#22C55E] text-white text-sm font-bold shadow-xs">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="w-4 h-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
          </div>
          <span class="mt-2 text-xs font-semibold text-gray-500 text-center">Review</span>
        </li>

        <li class="flex flex-col items-center relative flex-1 last:after:hidden">
          <div class="z-10 flex items-center justify-center w-9 h-9 rounded-full bg-[#08161B] text-white text-sm font-bold ring-4 ring-gray-100 shadow-xs">
            4
          </div>
          <span class="mt-2 text-xs font-bold text-[#08161B] text-center">Verify</span>
        </li>

      </ol>
    </div>

    <div class="w-full max-w-2xl bg-white rounded-2xl border border-gray-100 p-8 md:p-12 shadow-sm flex flex-col items-center">
      
      <div class="w-16 h-16 rounded-full bg-[#DCFCE7] text-[#22C55E] flex items-center justify-center mb-6">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="w-8 h-8">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
        </svg>
      </div>

      <h2 class="text-2xl font-bold text-gray-900 tracking-tight text-center mb-2">Application Submitted!</h2>
      <p class="text-sm text-gray-500 font-normal text-center max-w-md leading-relaxed mb-8">
        Your registration for the <strong class="text-gray-800 font-semibold">Ankan Community Registry</strong> has been received.
      </p>

      <div class="w-full border-b border-gray-100 mb-8"></div>

      <div class="w-full text-center text-[11px] font-bold tracking-widest text-gray-400 uppercase mb-8">
        Application Progress Tracker
      </div>

      <div class="w-full max-w-md mx-auto mb-12 flex flex-col gap-y-0">
        
        <div class="flex gap-x-4 relative pb-8">
          <div class="absolute top-6 left-[13px] bottom-0 w-0.5 bg-[#22C55E]"></div>
          <div class="relative z-10 w-7 h-7 rounded-full bg-[#22C55E] text-white flex items-center justify-center shrink-0 shadow-xs">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="w-3.5 h-3.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
          </div>
          <div class="flex flex-col pt-0.5">
            <h4 class="text-sm font-bold text-gray-800">Registration Completed</h4>
            <p class="text-xs text-gray-400 mt-0.5 leading-normal">Your identity and document uploads have been received.</p>
          </div>
        </div>

        <div class="flex gap-x-4 relative pb-8">
          <div class="absolute top-6 left-[13px] bottom-0 w-0.5 <?= ($status === 'pending_admin') ? 'bg-[#22C55E]' : 'bg-gray-200' ?>"></div>
          <div class="relative z-10 w-7 h-7 rounded-full <?= ($status === 'pending_admin') ? 'bg-[#22C55E] text-white' : 'bg-white border-2 border-[#F97316]' ?> flex items-center justify-center shrink-0 shadow-xs">
            <?= ($status === 'pending_admin') ? '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>' : '<div class="w-2 h-2 rounded-full bg-[#F97316]"></div>' ?>
          </div>
          <div class="flex flex-col pt-0.5">
            <h4 class="text-sm font-bold text-gray-800">Elder Verification</h4>
            <p class="text-xs text-gray-400 mt-0.5 leading-normal">Tribal Elders are verifying your genealogical information.</p>
          </div>
        </div>

        <div class="flex gap-x-4 relative">
          <div class="relative z-10 w-7 h-7 rounded-full bg-white border-2 <?= ($status === 'pending_admin') ? 'border-[#F97316]' : 'border-gray-200' ?> flex items-center justify-center shrink-0">
            <div class="w-2 h-2 rounded-full <?= ($status === 'pending_admin') ? 'bg-[#F97316]' : 'bg-gray-200' ?>"></div>
          </div>
          <div class="flex flex-col pt-0.5 <?= ($status === 'pending_admin') ? '' : 'opacity-60' ?>">
            <h4 class="text-sm font-semibold text-gray-800">Admin Approval</h4>
            <p class="text-xs text-gray-400 mt-0.5 leading-normal">System Administrators are performing the final review.</p>
          </div>
        </div>

      </div>

      <div class="w-full flex justify-end mt-4">
        <a href="logout.php" class="bg-[#333333] hover:bg-black active:scale-98 text-white font-semibold py-3 px-8 rounded-xl shadow-xs text-sm transition duration-150 text-center">
          Back to Home
        </a>
      </div>

    </div>
  </div>

</body>
</html>