<?php
// signup.php
require_once __DIR__ . '/dbconfig.php';
$conn = $GLOBALS['conn'] ?? ($conn ?? null);
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    die('Database connection not established. Check src/dbconfig.php and MySQL service.');
}

$sql = "SELECT * FROM users";
$result = mysqli_query($conn, $sql);

$signupError = '';
$signupSuccess = '';
$firstName = '';
$lastName = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = mb_convert_case(trim((string) ($_POST['first_name'] ?? '')), MB_CASE_TITLE, "UTF-8");
    $lastName = mb_convert_case(trim((string) ($_POST['last_name'] ?? '')), MB_CASE_TITLE, "UTF-8");
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($firstName === '' || $lastName === '' || $email === '' || $password === '' || $confirmPassword === '') {
        $signupError = 'All fields are required.';
    } elseif ($password !== $confirmPassword) {
        $signupError = 'Passwords do not match.';
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $signupError = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $signupError = 'Password must be at least 8 characters long.';
    } else {

        $columns = [];
        $columnResult = $conn->query('SHOW COLUMNS FROM users');
        if ($columnResult instanceof mysqli_result) {
            while ($column = $columnResult->fetch_assoc()) {
                $columns[] = (string) ($column['Field'] ?? '');
            }
        }

        $hasColumn = static function (string $candidate) use ($columns): bool {
            return in_array($candidate, $columns, true);
        };

        $emailColumn = $hasColumn('email') ? 'email' : null;
        $passwordColumn = $hasColumn('password_hash') ? 'password_hash' : ($hasColumn('password') ? 'password' : null);
        $firstNameColumn = $hasColumn('first_name') ? 'first_name' : ($hasColumn('firstname') ? 'firstname' : null);
        $lastNameColumn = $hasColumn('last_name') ? 'last_name' : ($hasColumn('lastname') ? 'lastname' : null);
        $jurisdictionColumn = $hasColumn('jurisdiction') ? 'jurisdiction' : ($hasColumn('juresdiction') ? 'juresdiction' : null);

        if ($emailColumn === null || $passwordColumn === null || $firstNameColumn === null || $lastNameColumn === null) {
            $signupError = 'Signup is not properly configured. Missing email/password/name columns in users table.';
        } else {
            // Check if email already exists using a prepared statement
            $checkSql = "SELECT 1 FROM users WHERE `{$emailColumn}` = ? LIMIT 1";
            $checkStmt = $conn->prepare($checkSql);
            if ($checkStmt) {
                $checkStmt->bind_param('s', $email);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                $exists = $checkResult instanceof mysqli_result ? $checkResult->fetch_assoc() : null;
                $checkStmt->close();

                if ($exists) {
                    $signupError = 'An account with this email already exists.';
                } else {
                    // Prepare dynamic insert statement
                    $insertCols = [];
                    $placeholders = [];
                    $types = '';
                    $params = [];

                    // Add email
                    $insertCols[] = "`{$emailColumn}`";
                    $placeholders[] = '?';
                    $types .= 's';
                    $params[] = $email;

                    // Add password (hashed)
                    $insertCols[] = "`{$passwordColumn}`";
                    $placeholders[] = '?';
                    $types .= 's';
                    $params[] = password_hash($password, PASSWORD_DEFAULT);

                    // Add first name if column exists
                    if ($firstNameColumn !== null) {
                        $insertCols[] = "`{$firstNameColumn}`";
                        $placeholders[] = '?';
                        $types .= 's';
                        $params[] = $firstName;
                    }

                    // Add last name if column exists
                    if ($lastNameColumn !== null) {
                        $insertCols[] = "`{$lastNameColumn}`";
                        $placeholders[] = '?';
                        $types .= 's';
                        $params[] = $lastName;
                    }

                    // Add default avatar if column exists (matches your schema default path)
                    if ($hasColumn('avatar')) {
                        $insertCols[] = "`avatar`";
                        $placeholders[] = '?';
                        $types .= 's';
                        $params[] = '/avatars/default.png';
                    }

                    // Add default role if column exists
                    if ($hasColumn('role')) {
                        $insertCols[] = "`role`";
                        $placeholders[] = '?';
                        $types .= 's';
                        $params[] = 'IP MEMBER';
                    }
                    if ($jurisdictionColumn !== null) {
                        $insertCols[] = "`{$jurisdictionColumn}`";
                        $placeholders[] = '?';
                        $types .= 's';
                        $params[] = 'Personal Data Only';
                    }

                    // Add status if column exists (enum('Online','Offline') in your schema)
                    if ($hasColumn('status')) {
                        $insertCols[] = "`status`";
                        $placeholders[] = '?';
                        $types .= 's';
                        $params[] = 'Offline';
                    }


                    // Construct SQL
                    $insertSql = "INSERT INTO users (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
                    $insertStmt = $conn->prepare($insertSql);

                    if ($insertStmt) {
                        $insertStmt->bind_param($types, ...$params);
                        if ($insertStmt->execute()) {
                            $signupSuccess = 'Account created successfully! You can now log in.';
                            // Clear form values
                            $firstName = '';
                            $lastName = '';
                            $email = '';
                        } else {
                            // Show the real MySQL error while debugging
                            $signupError = 'Failed to create your account. ' . htmlspecialchars($insertStmt->error, ENT_QUOTES, 'UTF-8');
                        }
                        $insertStmt->close();
                    } else {
                        $signupError = 'An error occurred while preparing your registration. Please try again.';
                    }
                }
            } else {
                $signupError = 'Unable to process signup request right now.';
            }
        }
    }
}
?>

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

                <?php if ($signupError !== ''): ?>
                    <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-xl flex items-start gap-3">
                        <i class="fa-solid fa-circle-exclamation text-red-500 mt-0.5"></i>
                        <p class="text-sm text-red-700 font-medium"><?php echo htmlspecialchars($signupError, ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($signupSuccess !== ''): ?>
                    <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6 rounded-xl flex items-start gap-3">
                        <i class="fa-solid fa-circle-check text-green-500 mt-0.5"></i>
                        <p class="text-sm text-green-700 font-medium"><?php echo htmlspecialchars($signupSuccess, ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                <?php endif; ?>

                <form method="POST" action="signup.php" class="space-y-5">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-sm font-semibold text-[#262626]">First Name</label>
                            <input type="text" name="first_name" placeholder="Juan" value="<?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-[#f3f4f1] border border-[#dedede] rounded-xl px-4 py-3.5 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-[#262626]">
                        </div>
                        <div class="space-y-2">
                            <label class="text-sm font-semibold text-[#262626]">Last Name</label>
                            <input type="text" name="last_name" placeholder="Dela Cruz" value="<?php echo htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-[#f3f4f1] border border-[#dedede] rounded-xl px-4 py-3.5 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-[#262626]">
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-semibold text-[#262626]">Email Address</label>
                        <input type="email" name="email" placeholder="username@example.com" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-[#f3f4f1] border border-[#dedede] rounded-xl px-4 py-3.5 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-[#262626]">
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-semibold text-[#262626]">Password</label>
                        <div class="relative">
                            <input id="passwordInput" type="password" name="password" placeholder="••••••••" class="w-full bg-[#f3f4f1] border border-[#dedede] rounded-xl px-4 py-3.5 pr-12 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-[#262626]">
                            
                            <button type="button" id="togglePassword" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-[#262626] transition">
                                <i class="fa-solid fa-eye" id="eyeIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-semibold text-[#262626]">Confirm Password</label>
                        <div class="relative">
                            <input id="confirmPasswordInput" type="password" name="confirm_password" placeholder="••••••••" class="w-full bg-[#f3f4f1] border border-[#dedede] rounded-xl px-4 py-3.5 pr-12 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-[#262626]">
                            
                            <button type="button" id="toggleConfirmPassword" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-[#262626] transition">
                                <i class="fa-solid fa-eye" id="confirmEyeIcon"></i>
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
        const confirmPasswordInput = document.getElementById('confirmPasswordInput');
        const toggleBtn = document.getElementById('togglePassword');
        const toggleConfirmBtn = document.getElementById('toggleConfirmPassword');
        const eyeIcon = document.getElementById('eyeIcon');
        const confirmEyeIcon = document.getElementById('confirmEyeIcon');

        function setupToggle(input, btn, icon) {
            if (!input || !btn || !icon) return;
            btn.addEventListener('click', function() {
                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            });
        }

        setupToggle(passwordInput, toggleBtn, eyeIcon);
        setupToggle(confirmPasswordInput, toggleConfirmBtn, confirmEyeIcon);
    </script>

</body>
</html>