<?php

require_once __DIR__ . '/auth/guards.php';
require_once __DIR__ . '/auth/auth_helpers.php';

// Only logged-in users can access document upload step.
// Also force unregistered IP members to finish personal step.
require_any_role(['admin', 'tribe_leader', 'ip_member']);

// Retrieve draft documents from session
$draftDocuments = $_SESSION['draft_documents'] ?? [];

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Upload Documents</title>
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
<div class="ml-auto">
      <a href="personal.php" class="text-gray-400 hover:text-gray-800 text-sm font-medium inline-flex items-center gap-1 transition">
        Back

        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </a>
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

        <li class="flex flex-col items-center relative flex-1 after:content-[''] after:w-full after:h-0.5 after:bg-[#CDD1D0] after:inline-block after:absolute after:top-5 after:left-1/2 last:after:hidden">
          <div class="z-10 flex items-center justify-center w-9 h-9 rounded-full bg-[#08161B] text-white text-sm font-bold ring-4 ring-gray-100 shadow-xs">
            2
          </div>
          <span class="mt-2 text-xs font-bold text-[#08161B] text-center">Documents</span>
        </li>

        <li class="flex flex-col items-center relative flex-1 after:content-[''] after:w-full after:h-0.5 after:bg-[#CDD1D0] after:inline-block after:absolute after:top-5 after:left-1/2 last:after:hidden">
          <div class="z-10 flex items-center justify-center w-9 h-9 rounded-full bg-white border-2 border-gray-200 text-gray-400 text-sm font-bold">
            3
          </div>
          <span class="mt-2 text-xs font-medium text-gray-400 text-center">Review</span>
        </li>

        <li class="flex flex-col items-center relative flex-1 after:content-[''] after:w-full after:h-0.5 after:bg-[#CDD1D0] after:inline-block after:absolute after:top-5 after:left-1/2 last:after:hidden">
          <div class="z-10 flex items-center justify-center w-9 h-9 rounded-full bg-white border-2 border-gray-200 text-gray-400 text-sm font-bold">
            4
          </div>
          <span class="mt-2 text-xs font-medium text-gray-400 text-center">Verify</span>
        </li>

      </ol>
    </div>

    <div class="w-full bg-white rounded-2xl border border-gray-100 p-6 md:p-10 shadow-sm">
      
      <div class="mb-8">
        <h2 class="text-xl font-bold text-gray-900 tracking-tight">Upload Documents</h2>
        <p class="text-xs text-gray-400 mt-0.5">Please upload clear copies of the following documents</p>
      </div>

      <form id="uploadForm" method="POST" action="documents_submit.php" enctype="multipart/form-data">

      <div class="text-gray-400 border-b border-gray-200 pb-1 uppercase text-xs font-bold tracking-wider mb-8">
        Fullname & Identity
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-10">
        
        <div class="relative p-6 border-2 border-dashed border-gray-200 rounded-2xl bg-white flex flex-col justify-between min-h-[220px]">
          <div class="flex justify-between items-start gap-4 mb-6">
            <div>
              <h4 class="text-base font-bold text-gray-800 mb-1">PSA Birth Certificate</h4>
              <p class="text-sm text-gray-400 font-normal leading-relaxed">
                Ensure the registry number is clearly visible.
              </p>
            </div>
            <div class="text-gray-800 shrink-0">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-7 h-7">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9zM9 13h6m-6 3.5h6" />
              </svg>
            </div>
          </div>
          <div>
            <div class="flex items-center gap-4 mb-3">
              <label class="cursor-pointer inline-block bg-black hover:bg-gray-800 text-white font-semibold text-sm py-2.5 px-6 rounded-xl transition duration-200 shadow-sm">
                Choose File
                <input type="file" name="birth_cert" class="hidden" accept=".jpg,.jpeg,.pdf" onchange="updateFileName(this)" <?= isset($draftDocuments['birth_cert']) ? '' : 'required' ?>>
              </label>
              <span class="text-sm text-gray-400 file-name-display"><?= htmlspecialchars($draftDocuments['birth_cert'] ?? 'No file chosen') ?></span>
            </div>
            <p class="text-[11px] text-gray-300 italic">Supported Format: JPG, PDF (MAX 10MB)</p>
          </div>
        </div>

        <div class="relative p-6 border-2 border-dashed border-gray-200 rounded-2xl bg-white flex flex-col justify-between min-h-[220px]">
          <div class="flex justify-between items-start gap-4 mb-6">
            <div>
              <h4 class="text-base font-bold text-gray-800 mb-1">Marriage Certificate</h4>
              <p class="text-sm text-gray-400 font-normal leading-relaxed">
                Only required if currently married or widowed.
              </p>
            </div>
            <div class="text-gray-800 shrink-0">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-7 h-7">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9zM9 13h6m-6 3.5h6" />
              </svg>
            </div>
          </div>
          <div>
            <div class="flex items-center gap-4 mb-3">
              <label class="cursor-pointer inline-block bg-black hover:bg-gray-800 text-white font-semibold text-sm py-2.5 px-6 rounded-xl transition duration-200 shadow-sm">
                Choose File
                <input type="file" name="marriage_cert" class="hidden" accept=".jpg,.jpeg,.pdf" onchange="updateFileName(this)" <?= isset($draftDocuments['marriage_cert']) ? '' : '' ?>>
              </label>
              <span class="text-sm text-gray-400 file-name-display"><?= htmlspecialchars($draftDocuments['marriage_cert'] ?? 'No file chosen') ?></span>
            </div>
            <p class="text-[11px] text-gray-300 italic">Supported Format: JPG, PDF (MAX 10MB)</p>
          </div>
        </div>

        <div class="relative p-6 border-2 border-dashed border-gray-200 rounded-2xl bg-white flex flex-col justify-between min-h-[220px]">
          <div class="flex justify-between items-start gap-4 mb-6">
            <div>
              <h4 class="text-base font-bold text-gray-800 mb-1">NCIP Genealogy Form</h4>
              <p class="text-sm text-gray-400 font-normal leading-relaxed">
                The certified copy from your local NCIP office.
              </p>
            </div>
            <div class="text-gray-800 shrink-0">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-7 h-7">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9zM9 13h6m-6 3.5h6" />
              </svg>
            </div>
          </div>
          <div>
            <div class="flex items-center gap-4 mb-3">
              <label class="cursor-pointer inline-block bg-black hover:bg-gray-800 text-white font-semibold text-sm py-2.5 px-6 rounded-xl transition duration-200 shadow-sm">
                Choose File
                <input type="file" name="ncip_form" class="hidden" accept=".jpg,.jpeg,.pdf" onchange="updateFileName(this)" <?= isset($draftDocuments['ncip_form']) ? '' : 'required' ?>>
              </label>
              <span class="text-sm text-gray-400 file-name-display"><?= htmlspecialchars($draftDocuments['ncip_form'] ?? 'No file chosen') ?></span>
            </div>
            <p class="text-[11px] text-gray-300 italic">Supported Format: JPG, PDF (MAX 10MB)</p>
          </div>
        </div>

        <div class="relative p-6 border-2 border-dashed border-gray-200 rounded-2xl bg-white flex flex-col justify-between min-h-[220px]">
          <div class="flex justify-between items-start gap-4 mb-6">
            <div>
              <h4 class="text-base font-bold text-gray-800 mb-1">Certificate of Indigency</h4>
              <p class="text-sm text-gray-400 font-normal leading-relaxed">
                Certificate copy from your local Barangay Captain
              </p>
            </div>
            <div class="text-gray-800 shrink-0">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-7 h-7">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9zM9 13h6m-6 3.5h6" />
              </svg>
            </div>
          </div>
          <div>
            <div class="flex items-center gap-4 mb-3">
              <label class="cursor-pointer inline-block bg-black hover:bg-gray-800 text-white font-semibold text-sm py-2.5 px-6 rounded-xl transition duration-200 shadow-sm">
                Choose File
                <input type="file" name="indigency_cert" class="hidden" accept=".jpg,.jpeg,.pdf" onchange="updateFileName(this)" <?= isset($draftDocuments['indigency_cert']) ? '' : 'required' ?>>
              </label>
              <span class="text-sm text-gray-400 file-name-display"><?= htmlspecialchars($draftDocuments['indigency_cert'] ?? 'No file chosen') ?></span>
            </div>
            <p class="text-[11px] text-gray-300 italic">Supported Format: JPG, PDF (MAX 10MB)</p>
          </div>
        </div>

      </div>

      <div class="mt-12 pt-6 border-t border-gray-100 flex justify-end">
        <button type="submit" id="submitBtn" class="bg-black hover:bg-gray-900 active:scale-98 text-white font-semibold py-3 px-8 rounded-xl shadow-xs text-sm transition duration-150 text-center min-w-[160px]">
          Save & Continue
        </button>
      </div>

      </form>

    </div>
  </div>

  <!-- Size Warning Modal -->
  <div id="sizeWarningModal" class="hidden fixed inset-0 z-[100] items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-xs" onclick="closeSizeWarningModal()"></div>
    <div class="relative z-10 w-full max-w-sm bg-white rounded-2xl shadow-[0_10px_30px_rgba(0,0,0,0.15)] border border-[#ececea] p-6 text-center">
      <div class="w-16 h-16 rounded-full bg-red-50 text-red-500 flex items-center justify-center mx-auto mb-4">
        <i data-lucide="alert-triangle" class="w-8 h-8"></i>
      </div>
      <h3 class="text-lg font-bold text-[#262626] mb-2">File Too Large</h3>
      <p class="text-sm text-gray-500 mb-6">The file you selected exceeds the 10MB limit. Please choose a smaller file or compress it before uploading.</p>
      <button type="button" onclick="closeSizeWarningModal()" class="w-full py-3 text-sm font-bold bg-[#262626] text-white rounded-xl hover:bg-black transition-all shadow-sm">Got it</button>
    </div>
  </div>

  <script>
    function closeSizeWarningModal() {
      const modal = document.getElementById('sizeWarningModal');
      modal.classList.add('hidden');
      modal.classList.remove('flex');
    }

    // Updates the text label next to the button when a file is selected
    function updateFileName(input) {
      const display = input.closest('div').querySelector('.file-name-display');
      const maxSizeBytes = 10 * 1024 * 1024; // 10MB
      
      if (input.files && input.files.length > 0) {
        const file = input.files[0];
        if (file.size > maxSizeBytes) {
          const modal = document.getElementById('sizeWarningModal');
          modal.classList.remove('hidden');
          modal.classList.add('flex');
          input.value = '';
          display.textContent = 'No file chosen';
          display.classList.remove('text-gray-900', 'font-medium');
          display.classList.add('text-gray-400');
        } else {
          display.textContent = file.name;
          display.classList.remove('text-gray-400');
          display.classList.add('text-gray-900', 'font-medium');
          input.removeAttribute('required');
        }
      } else if (display.textContent.trim() === 'No file chosen' || display.textContent.trim() === '') {
        display.textContent = 'No file chosen';
        display.classList.remove('text-gray-900', 'font-medium');
        display.classList.add('text-gray-400');
      }
    }

    // Handles the loading state sign when submitting
    document.getElementById('uploadForm').addEventListener('submit', function() {
      const btn = document.getElementById('submitBtn');
      btn.disabled = true;
      btn.classList.add('opacity-70', 'cursor-not-allowed');
      btn.innerHTML = `
        <svg class="animate-spin -ml-1 mr-3 h-4 w-4 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg> Uploading...`;
    });

    // Initialize file name displays on page load
    document.querySelectorAll('input[type="file"]').forEach(input => {
      const display = input.closest('div').querySelector('.file-name-display');
      // If PHP already populated a filename (not "No file chosen"), update styles
      if (display && display.textContent.trim() !== 'No file chosen' && display.textContent.trim() !== '') {
        display.classList.remove('text-gray-400');
        display.classList.add('text-gray-900', 'font-medium');
        input.removeAttribute('required');
      }
    });

    if (window.lucide) lucide.createIcons();
  </script>

</body>
</html>