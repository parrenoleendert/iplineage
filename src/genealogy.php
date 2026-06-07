<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Family Lineage</title>
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

  <div class="max-w-4xl w-full mx-auto px-4 py-10 flex flex-col items-center">
    
    <div class="w-full max-w-3xl mb-10 px-4">
      <ol class="flex items-center w-full">
        
        <li class="flex flex-col items-center relative flex-1 after:content-[''] after:w-full after:h-0.5 after:bg-[#CDD1D0] after:inline-block after:absolute after:top-5 after:left-1/2 last:after:hidden">
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
          <span class="mt-2 text-xs font-bold text-[#08161B] text-center">Genealogy</span>
        </li>

        <li class="flex flex-col items-center relative flex-1 after:content-[''] after:w-full after:h-0.5 after:bg-[#CDD1D0] after:inline-block after:absolute after:top-5 after:left-1/2 last:after:hidden">
          <div class="z-10 flex items-center justify-center w-9 h-9 rounded-full bg-white border-2 border-gray-200 text-gray-400 text-sm font-bold">
            3
          </div>
          <span class="mt-2 text-xs font-medium text-gray-400 text-center">Documents</span>
        </li>

        <li class="flex flex-col items-center relative flex-1 after:content-[''] after:w-full after:h-0.5 after:bg-[#CDD1D0] after:inline-block after:absolute after:top-5 after:left-1/2 last:after:hidden">
          <div class="z-10 flex items-center justify-center w-9 h-9 rounded-full bg-white border-2 border-gray-200 text-gray-400 text-sm font-bold">
            4
          </div>
          <span class="mt-2 text-xs font-medium text-gray-400 text-center">Review</span>
        </li>

        <li class="flex flex-col items-center relative flex-1 after:content-[''] after:w-full after:h-0.5 after:bg-[#CDD1D0] after:inline-block after:absolute after:top-5 after:left-1/2 last:after:hidden">
          <div class="z-10 flex items-center justify-center w-9 h-9 rounded-full bg-white border-2 border-gray-200 text-gray-400 text-sm font-bold">
            5
          </div>
          <span class="mt-2 text-xs font-medium text-gray-400 text-center">Verify</span>
        </li>

      </ol>
    </div>

    <div class="w-full bg-white rounded-2xl border border-gray-100 p-6 md:p-10 shadow-sm">
      
      <div class="mb-8">
        <h2 class="text-xl font-bold text-gray-900 tracking-tight">Family Lineage</h2>
        <p class="text-xs text-gray-400 mt-0.5">Please provide full name of your ancestors</p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-10">
        
        <div class="flex flex-col gap-y-6">
          <div class="text-xs font-bold text-gray-800 tracking-wider uppercase border-b border-gray-200 pb-2 mb-2">
            Paternal Lineage
          </div>

<form method="POST" action="genealogy_submit.php">
        <input type="hidden" name="draft_step" value="lineage">

          <div>
            <label class="block mb-2 font-semibold text-gray-700 text-xs">Father</label>
            <input type="text" name="father_name" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="Enter Father's Name">
          </div>

          <div class="flex flex-col gap-y-3">
            <label class="block font-semibold text-gray-700 text-xs">Paternal Grandfather</label>
            <input type="text" name="pat_grandfather_name" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="Enter Grandfather's Name">
            <input type="text" name="pat_gf_father" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="Great-Grandfather">
            <input type="text" name="pat_gf_mother_father" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="Great-Grandmother">
          </div>

          <div class="flex flex-col gap-y-3">
            <label class="block font-semibold text-gray-700 text-xs">Paternal Grandmother</label>
            <input type="text" name="pat_grandmother_name" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="Enter Grandmother's Name (Maiden Name)">
            <input type="text" name="pat_gm_father" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="Great-Grandfather">
            <input type="text" name="pat_gm_mother_father" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="Great-Grandmother">
          </div>
        </div>

        <div class="flex flex-col gap-y-6">
          <div class="text-xs font-bold text-gray-800 tracking-wider uppercase border-b border-gray-200 pb-2 mb-2">
            Maternal Lineage
          </div>

          <div>
            <label class="block mb-2 font-semibold text-gray-700 text-xs">Mother</label>
            <input type="text" name="mother_name" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="Enter Mother's Name (Maiden Name)">
          </div>

          <div class="flex flex-col gap-y-3">
            <label class="block font-semibold text-gray-700 text-xs">Maternal Grandfather</label>
            <input type="text" name="mat_grandfather_name" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="Enter Grandfather's Name">
            <input type="text" name="mat_gf_father" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="Great-Grandfather">
            <input type="text" name="mat_gf_mother_father" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="Great-Grandmother">
          </div>

          <div class="flex flex-col gap-y-3">
            <label class="block font-semibold text-gray-700 text-xs">Maternal Grandmother</label>
            <input type="text" name="mat_grandmother_name" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="Enter Grandmother's Name (Maiden Name)">
            <input type="text" name="mat_gm_father" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="Great-Grandfather">
            <input type="text" name="mat_gm_mother_father" class="w-full text-gray-600 border border-gray-300 rounded-lg p-2.5 outline-none focus:border-black transition" placeholder="Great-Grandmother">
          </div>
        </div>

      </div>

      <div class="mt-12 pt-6 border-t border-gray-100 flex justify-end">
        <button type="submit" class="bg-black hover:bg-gray-900 active:scale-98 text-white font-semibold py-3 px-8 rounded-xl shadow-xs text-sm transition duration-150 text-center">
          Save & Continue
        </button>
      </div>
      </form>

    </div>
  </div>

</body>
</html>