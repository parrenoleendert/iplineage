<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <title>IP Family Lineage - Create Account</title>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f3f4f1; color: #262626; }
        .bg-custom-light { background-color: #f3f4f1; }
        .bg-card-white { background-color: #ffffff; }
        .btn-primary { background-color: #262626; color: #f3f4f1; }
        .btn-primary:hover { background-color: #404040; }
        .hero-image {
            background-image: linear-gradient(rgba(38, 38, 38, 0.2), rgba(38, 38, 38, 0.7)),
                              url('../img/image1.png');
            background-size: contain;
            background-position: center;
            background-repeat: no-repeat;
            background-color: #ffffff;
        }
    </style>
</head>
<body class="min-h-screen">

    <div class="flex flex-col md:flex-row min-h-screen w-full">
        
        <div class="w-full md:w-1/2 lg:w-[40%] p-8 md:p-16 lg:p-24 flex flex-col justify-center bg-white">
            <div class="max-w-md mx-auto w-full">
                
                <h1 class="text-4xl font-bold mb-2 text-[#262626]">Create an account</h1>
                
                <p class="text-gray-500 mb-10">
                    Already have an account? <a href="login.php" class="text-[#262626] font-bold hover:underline transition">Log in</a>
                </p>

                <form class="space-y-5">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-sm font-semibold text-[#262626]">First Name</label>
                            <input type="text" placeholder="Juan" class="w-full bg-[#f3f4f1] border border-[#dedede] rounded-xl px-4 py-3.5 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-[#262626]">
                        </div>
                        <div class="space-y-2">
                            <label class="text-sm font-semibold text-[#262626]">Last Name</label>
                            <input type="text" placeholder="Dela Cruz" class="w-full bg-[#f3f4f1] border border-[#dedede] rounded-xl px-4 py-3.5 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-[#262626]">
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-semibold text-[#262626]">Email Address</label>
                        <input type="email" placeholder="username@example.com" class="w-full bg-[#f3f4f1] border border-[#dedede] rounded-xl px-4 py-3.5 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-[#262626]">
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-semibold text-[#262626]">Password</label>
                        <div class="relative">
                            <input id="passwordInput" type="password" placeholder="••••••••" class="w-full bg-[#f3f4f1] border border-[#dedede] rounded-xl px-4 py-3.5 pr-12 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-[#262626]">
                            
                            <button type="button" id="togglePassword" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-[#262626] transition">
                                <i class="fa-solid fa-eye" id="eyeIcon"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="w-full btn-primary font-bold py-4 rounded-xl transition duration-300 shadow-lg shadow-black/10 mt-4">
                        Create Account
                    </button>
                </form>

                <div class="relative flex py-8 items-center">
                    <div class="flex-grow border-t border-[#dedede]"></div>
                    <span class="flex-shrink mx-4 text-gray-400 text-xs uppercase tracking-widest font-bold">Or</span>
                    <div class="flex-grow border-t border-[#dedede]"></div>
                </div>

                <button class="w-full flex items-center justify-center gap-3 bg-white border border-[#dedede] text-[#262626] font-semibold py-4 rounded-xl hover:bg-[#f3f4f1] transition">
                    <img src="../img/google.png" class="w-5 h-5" alt="Google">
                    Sign up with Google
                </button>
            </div>
        </div>

        <div class="hidden md:block md:w-1/2 lg:w-[60%] hero-image relative border-l border-[#dedede]">
            <div class="absolute top-12 left-12 flex items-center gap-3">
                <img src="../img/ip (1) 3.png" class="w-10 h-10 object-contain" alt="Logo">
                <span class="font-bold text-xl tracking-tight text-white">IP Lineage</span>
            </div>

            <div class="absolute inset-0 bg-gradient-to-t from-[#262626]/80 via-transparent to-transparent"></div>
            
            <div class="absolute bottom-16 left-16 right-16">
                <div class="inline-block px-4 py-1.5 rounded-full bg-white/10 border border-white/20 text-white text-xs font-bold uppercase tracking-widest mb-4 backdrop-blur-md">
                    Barangay Villafont
                </div>
                <h2 class="text-5xl font-bold leading-tight mb-4 text-white">Protecting Our <br>Ancestral Legacy.</h2>
                <p class="text-xl text-gray-200 max-w-lg">
                    Join the digital registry of the Indigenous People of Sibalom to ensure your family history is preserved for generations.
                </p>
            </div>
        </div>

    </div>

    <script>
        const passwordInput = document.getElementById('passwordInput');
        const toggleBtn = document.getElementById('togglePassword');
        const eyeIcon = document.getElementById('eyeIcon');

        toggleBtn.addEventListener('click', function() {
            // Toggle the type attribute
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // Toggle the eye / eye-slash icon
            eyeIcon.classList.toggle('fa-eye');
            eyeIcon.classList.toggle('fa-eye-slash');
        });
    </script>

</body>
</html>