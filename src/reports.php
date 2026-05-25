<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <link href="../dist/output.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/style.css">
  <script src="../js/lucide.js"></script>
  <title>dashboard</title>
</head>

<body class="bg-[#E6E4E4]">
     <!--nav-->
     <div  class="sidebar fixed top-0 bottom-0 left-0 p-4 w-64  overflow-y-auto rounded-r-xl shadow-xl shadow-black  bg-[#0B1D30] overflow-hidden  border-r border-white/20">
        <div class="text-white text-sm font-normal">
        <!--logo-->
         <p class="mr-10 flex items-center text-lg">
            <img src="../img/ip (1) 3.png" class="w-12 h-auto object-contain" alt="logo"> 
          <span class="font-inter mr-5 text-sm">IP LINEAGE</span>
        </p>
          <div class="p-2.5 flex items-center">
            <a href="#" ></a>
    </div>
     <!--dashbboard nav-->
        <div class="mt-2 p-2.5 flex items-center rounded-md duration-300 cursor-pointer hover:bg-gray-800 text-white-400 hover:text-blue-400">
            <a href="dashboard.php" class="flex items-center w-full">
                <i data-lucide="layout-dashboard" class="w-5 h-5"></i>
                <span class="text-[12px] ml-2 font-inter">Dashboard</span>
            </a>
        </div>
         <!--user & role -->
       <div class="mt-2 p-2.5 flex items-center rounded-md duration-300 cursor-pointer hover:bg-gray-800 text-white-400 hover:text-blue-400"> 
            <a href="user&role.php" class="flex items-center w-full">
                <i data-lucide="user-cog" class="w-5 h-5"></i>
                <span class="text-[12px] ml-2  font-inter">User & Role Mangement</span>
            </a>
        </div>
        <!--family lineage-->
          <div class="mt-2 p-2.5 flex items-center rounded-md duration-300 cursor-pointer hover:bg-gray-800 text-white-400 hover:text-blue-400">
            <a href="family tree.php" class="flex items-center w-full">
                <i data-lucide="tree-pine" class="w-5 h-5"></i>
                <span class="text-[12px] ml-2  font-inter">Family Lineage</span>
            </a>
        </div>
        <!--Tribe Information-->
         <div class="mt-2 p-2.5 flex items-center rounded-md duration-300 cursor-pointer hover:bg-gray-800 text-white-400 hover:text-blue-400">
            <a href="tribeinfo.php" class="flex items-center w-full">
                <i data-lucide="users" class="w-5 h-5"></i>
                <span class="text-[12px] ml-2  font-inter">Tribe Information</span>
            </a>
        </div>
         <!--Doc & verify-->
        <div class="mt-2 p-2.5 flex items-center rounded-md duration-300 cursor-pointer hover:bg-gray-800 text-white-400 hover:text-blue-400">
            <a href="veriify&doc.php" class="flex items-center w-full">
                <i data-lucide="files" class="w-5 h-5"></i>
                <span class="text-[12px] ml-2  font-inter">Documents & Verification</span>
            </a>
        </div>
         <!--Reports & Analytics-->
         <div class="mt-2 p-2.5 flex items-center rounded-md duration-300 cursor-pointer bg-blue-400 text-white-400 hover:text-black">
             <a href="#" class="flex items-center w-full">
                <i data-lucide="file-chart-column" class="w-5 h-5"></i>
                <span class="text-[12px] ml-2  font-inter">Reports & Analytics</span>
            </a>
        </div>
         <hr class=" mt-4 my- text-gray">
            <p class="opacity-25"> System</p>
         <!--System  Settings-->
         <div class="mt-2 p-2.5 flex items-center rounded-md duration-300 cursor-pointer hover:bg-gray-800 text-white-400 hover:text-blue-400">
            <a href="#" class="flex items-center w-full">
                <i data-lucide="settings-2" class="w-5 h-5"></i>
                <span class="text-[12px] ml-2  font-inter">System Settings</span>
            </a>
        </div>
         <!--logout-->
          <div class="mt-2 p-2.5 flex items-center rounded-md duration-300 cursor-pointer hover:bg-gray-800 text-white-400 hover:text-blue-400">
            <a href="#" class="flex items-center w-full">
                <i data-lucide="log-out" class="w-5 h-5"></i>
                <span class="text-[12px] ml-2 font-medium font-inter">logout</span>
            </a>
        </div>
     </div>
</div>
<div class="ml-64 min-h-screen flex flex-col">
    <main>
        <!--search-->
         <div class="bg-white p-4 flex justify-between items-center sticky top-0 z-40 shadow-sm">
           <div class="bg-gray-400 px-4 rounded-full ">
            <div class="flex items-center gap-3">
             <i data-lucide="search" class="w-5 h-5 text-white"></i>
            <input type="search" class="w-70  placeholder:text-white placeholder:italic text-sm"
              placeholder="Search for anything..." 
                type="text"
                name="search"
                />
           </div>
        </div>
        <div class="flex items-center gap-6">
            <div class="flex items-center gap-2 border-r pr-4">
                <button class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                    <i data-lucide="moon" class="w-5 h-5 text-gray-600"></i>
                </button>
                <button class="relative p-2 flex items-center  justify-end hover:bg-gray-100 rounded-full transition-colors">
                    <i data-lucide="bell" class="w-5 h-5 text-gray-600"></i>
                    <span class="absolute top-2 w-2 h-2 bg-red-500 rounded-full border-2 border-white"></span>
                </button>
            </div>
            <div class="avatar-sm transition delay-150 duration-300 ease-in-out hover:scale-110 flex items-center  justify-end">
                <img
                        src="../img/cha.jpg"
                        alt="..."
                        class="avatar-img rounded-full w-8 h-8 "
                      />
                    </div>
                    <span class="profile-username">
                      <span class="op-7">Hi,</span>
                      <span class="fw-bold">Charles</span>
                      <p class="font-light text-xs opacity-50">Chalesgmail.com</p>
                    </span>
                </div>
            </div>
      <!--main section-->
      <div class="bg-white mx-4 mt-4 rounded-md p-2 shadow-md text-gray-500 font-bold text-sm flex flex-row justify-between  items-end">
        <div class="p-2">
            <span>Reports & Analytics</span>
        </div>
        <div class="flex flex-row gap-4">
            <div class="bg-blue-500 rounded-full w-20 h-6  my-2  text-white flex justify-center items-center font-semibold transition delay-150 duration-300 ease-in-out hover:scale-110">
            <a href="#">Report</a>
        </div>
        <div class=" border rounded-full w-20 h-6  my-2  text-black flex justify-center items-center font-semibold transition delay-150 duration-300 ease-in-out hover:scale-110">
            <a href="#">Progress</a>
        </div>
        <div class="bg-red-500 rounded-full w-20 h-6  my-2  text-white flex justify-center items-center font-semibold transition delay-150 duration-300 ease-in-out hover:scale-110">
            <a href="#">Export</a>
        </div>
    </div>
</div>
    <div class="bg-white mx-4 mt-4 rounded-lg shadow-md border-gray-100 ">
        <div class="flex flex-row px-6"></div>
        <!--statistics-->
        <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6 mx-8 mt-7">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-gray-600 font-bold text-lg">Verification Trends</h3>
    </div>

    <div class="relative h-64 w-full">
        <canvas id="ipChart"></canvas>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  
<script>
  // Wait for the page to load
  document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('ipChart').getContext('2d');

    new Chart(ctx, {
      type: 'line', 
      data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
        datasets: [{
          label: 'Registered IPs',
          data: [65, 59, 80, 81, 56, 55],
          fill: true,
          borderColor: '#3b82f6', // Tailwind Blue-500
          backgroundColor: 'rgba(59, 130, 246, 0.1)',
          tension: 0.4 // This makes the line curvy and smooth
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false, // Allows it to fill your h-64 container
        plugins: {
          legend: {
            display: false // Keeps it clean
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(0,0,0,0.05)' }
          },
          x: {
            grid: { display: false }
          }
        }
      }
    });
  });
</script>
<script>
  lucide.createIcons();
</script>
</body>
</html>