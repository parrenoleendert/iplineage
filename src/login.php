<?php
// Database must be initialized FIRST before auth helpers that might use it
$dbConfigPath = __DIR__ . '/dbconfig.php';
if (!file_exists($dbConfigPath)) {
    die('Database configuration file not found: ' . $dbConfigPath);
}
require_once $dbConfigPath;

if (!isset($conn) || $conn === null) {
    die('Database connection failed: $conn is not initialized. Please check dbconfig.php');
}

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/auth/auth_helpers.php';

if (!empty($_SESSION['role'])) {
    redirect_to_role_dashboard((string) $_SESSION['role']);
}

$loginError = '';
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($identifier === '' || $password === '') {
        $loginError = 'Please enter your username/email and password.';
    } else {
        $columns = [];
        $columnResult = $conn->query('SHOW COLUMNS FROM users');

        if ($columnResult instanceof mysqli_result) {
            while ($column = $columnResult->fetch_assoc()) {
                $columns[] = $column['Field'];
            }
        }

        $findFirstColumn = static function (array $candidates) use ($columns): ?string {
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    return $candidate;
                }
            }

            return null;
        };

        $idColumn = $findFirstColumn(['user_id', 'id', 'userid']);
        $roleColumn = $findFirstColumn(['role', 'user_role']);
        $nameColumn = $findFirstColumn(['full_name', 'name', 'username']);
        $emailColumn = $findFirstColumn(['email']);
        $usernameColumn = $findFirstColumn(['username']);
        $firstNameCol = $findFirstColumn(['first_name', 'firstname']);
        $lastNameCol = $findFirstColumn(['last_name', 'lastname']);
        $statusColumn = $findFirstColumn(['account_status', 'status', 'user_status', 'is_active', 'active', 'state']);
        $passwordColumn = $findFirstColumn(['password_hash', 'password']);

        if ($idColumn === null || $roleColumn === null || $passwordColumn === null || ($emailColumn === null && $usernameColumn === null)) {
            $loginError = 'Login setup is incomplete. Make sure users table has role, password, and email/username columns.';
        } else {
            $identifierConditions = [];
            $params = [];
            $types = '';

            if ($emailColumn !== null) {
                $identifierConditions[] = "{$emailColumn} = ?";
                $params[] = $identifier;
                $types .= 's';
            }

            if ($usernameColumn !== null) {
                $identifierConditions[] = "{$usernameColumn} = ?";
                $params[] = $identifier;
                $types .= 's';
            }

            $nameCandidates = [];
            if ($nameColumn) $nameCandidates[] = "NULLIF(`$nameColumn`, '')";
            if ($firstNameCol && $lastNameCol) $nameCandidates[] = "NULLIF(TRIM(CONCAT_WS(' ', `$firstNameCol`, `$lastNameCol`)), '')";
            elseif ($firstNameCol) $nameCandidates[] = "NULLIF(`$firstNameCol`, '')";
            elseif ($lastNameCol) $nameCandidates[] = "NULLIF(`$lastNameCol`, '')";
            if ($usernameColumn) $nameCandidates[] = "NULLIF(`$usernameColumn`, '')";
            if ($emailColumn) $nameCandidates[] = "NULLIF(`$emailColumn`, '')";
            
            $selectedName = !empty($nameCandidates) 
                ? "COALESCE(" . implode(', ', $nameCandidates) . ", 'User')" 
                : "'User'";
            $statusExpr = $statusColumn !== null ? "{$statusColumn} AS status" : "'Active' AS status";
            $sql = "SELECT {$idColumn} AS user_id, {$selectedName} AS display_name, `{$roleColumn}` AS role, `{$passwordColumn}` AS password_value, {$statusExpr} FROM users WHERE " . implode(' OR ', $identifierConditions) . ' LIMIT 1';
            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                $loginError = 'Unable to process login request right now.';
            } else {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
                $stmt->close();

                if (!$user) {
                    $loginError = 'Invalid login credentials.';
                } else {
                    $storedValue = (string) ($user['password_value'] ?? '');
                    $passwordVerified = false;

                    if ($storedValue !== '') {
                        $passwordVerified = password_verify($password, $storedValue);

                        // Backward compatibility for legacy plaintext passwords.
                        if (!$passwordVerified && hash_equals($storedValue, $password)) {
                            $passwordVerified = true;
                        }
                    }

                    if (!$passwordVerified) {
                        $loginError = 'Invalid login credentials.';
                    } elseif (isset($user['status']) && strtolower(trim((string)$user['status'])) === 'disabled') {
                        $loginError = 'Your account has been disabled. Please contact the administrator.';
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $user['user_id'];
                        $_SESSION['name'] = (string) ($user['display_name'] ?? 'User');
                        $_SESSION['role'] = normalize_role((string) ($user['role'] ?? 'ip_member'));

                        // Mark user as Online in the database upon successful login
                        touch_user_activity((int)$user['user_id']);

                        // If IP Member has no ipmembers record yet (ip_member_id is NULL/0), send them to personal registration.
                        if (normalize_role((string) $_SESSION['role']) === 'ip_member') {
                            $userId = (int) ($_SESSION['user_id'] ?? 0);
                            $hasIpMemberRecord = false;
                            $isApproved = false;

                            if ($userId > 0) {
                                // Check both the member record and the application status
                                $ipMemberCheckSql = "SELECT i.ip_member_id, a.status 
                                                     FROM ipmembers i 
                                                     LEFT JOIN applications a ON i.ip_member_id = a.ip_member_id 
                                                     WHERE i.user_id = ? 
                                                     ORDER BY a.application_id DESC LIMIT 1";
                                $ipStmt = $conn->prepare($ipMemberCheckSql);
                                if ($ipStmt) {
                                    $ipStmt->bind_param('i', $userId);
                                    $ipStmt->execute();
                                    $res = $ipStmt->get_result();
                                    $row = $res instanceof mysqli_result ? $res->fetch_assoc() : null;
                                    $ipStmt->close();

                                    $ipMemberId = (int) ($row['ip_member_id'] ?? 0);
                                    $status = (string) ($row['status'] ?? '');
                                    $hasIpMemberRecord = $ipMemberId > 0;
                                    $isApproved = ($status === 'approved');
                                }
                            }

                            if (!$hasIpMemberRecord) {
                                // Mark session so other pages can block navigation until registration is complete.
                                $_SESSION['ip_registration_complete'] = false;
                                header('Location: personal.php');
                                exit;
                            }

                            if (!$isApproved) {
                                // Record exists but not yet approved - send to standby
                                $_SESSION['ip_registration_complete'] = false;
                                header('Location: verify.php');
                                exit;
                            }
                        }

                        $_SESSION['ip_registration_complete'] = true;
                        redirect_to_role_dashboard((string) $_SESSION['role']);
                    }
                }
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
    <title>IP Family Lineage - Login</title>
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
        
        <div class="hidden md:block md:w-1/2 lg:w-[60%] hero-image relative">
            <div class="absolute top-12 left-12 flex items-center gap-3">
                <img src="../img/ip (1) 3.png" class="w-10 h-10 object-contain" alt="Logo">
                <span class="font-bold text-xl tracking-tight text-white">IP Lineage</span>
            </div>

            <div class="absolute inset-0 bg-gradient-to-t from-[#262626]/80 via-transparent to-transparent"></div>
            
            <div class="absolute bottom-16 left-16 right-16">
                <div class="inline-block px-4 py-1.5 rounded-full bg-white/10 border border-white/20 text-white text-xs font-bold uppercase tracking-widest mb-4 backdrop-blur-md">
                    Barangay Villafont
                </div>
                <h2 class="text-5xl font-bold leading-tight mb-4 text-white">Welcome Back to <br>Your Roots.</h2>
                <p class="text-xl text-gray-200 max-w-lg">
                    Access the ancestral portal to continue managing and exploring your indigenous family lineage.
                </p>
            </div>
        </div>

        <div class="w-full md:w-1/2 lg:w-[40%] p-8 md:p-16 lg:p-24 flex flex-col justify-center bg-white border-l border-[#dedede]">
            <div class="max-w-md mx-auto w-full">
                
                <h1 class="text-4xl font-bold mb-2 text-[#262626]">Log in</h1>
                
                <p class="text-gray-500 mb-10">
                    Don't have an account? <a href="signup.php" class="text-[#262626] font-bold hover:underline transition">Sign up</a>
                </p>

                <?php if ($loginError !== ''): ?>
                    <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        <?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <form class="space-y-6" method="post" action="login.php" autocomplete="off">
                    <div class="space-y-2">
                        <label class="text-sm font-semibold text-[#262626]">Username or Email</label>
                        <input type="text" name="identifier" value="<?php echo htmlspecialchars($identifier, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter your username" 
                            class="w-full bg-[#f3f4f1] border border-[#dedede] rounded-xl px-4 py-4 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-[#262626]">
                    </div>

                    <div class="space-y-2">
                        <label class="text-sm font-semibold text-[#262626]">Password</label>
                        <div class="relative">
                            <input id="passwordInput" name="password" type="password" placeholder="••••••••" 
                                class="w-full bg-[#f3f4f1] border border-[#dedede] rounded-xl px-4 py-4 pr-12 focus:outline-none focus:ring-2 focus:ring-[#262626]/10 transition text-[#262626]">

                        </div>
                        
                        <div class="flex justify-end pt-1 px-1">
                            <a href="#" class="text-sm text-gray-500 hover:text-[#262626] transition font-medium">Forgot password?</a>
                        </div>
                    </div>

                    <button type="submit" class="w-full btn-primary font-bold py-4 rounded-xl transition duration-300 shadow-lg shadow-black/10 mt-4">
                        Sign In
                    </button>
                </form>

                <div class="relative flex py-8 items-center">
                    <div class="flex-grow border-t border-[#dedede]"></div>
                    <span class="flex-shrink mx-4 text-gray-400 text-xs uppercase tracking-widest font-bold">Or</span>
                    <div class="flex-grow border-t border-[#dedede]"></div>
                </div>

                <button class="w-full flex items-center justify-center gap-3 bg-white border border-[#dedede] text-[#262626] font-semibold py-4 rounded-xl hover:bg-[#f3f4f1] transition">
                    <img src="../img/google.png" class="w-5 h-5" alt="Google">
                    Log in with Google
                </button>
            </div>
        </div>

    </div>

    <script>
        const passwordInput = document.getElementById('passwordInput');
        const toggleBtn = document.getElementById('togglePassword');
        const eyeIcon = document.getElementById('eyeIcon');

        toggleBtn.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            eyeIcon.classList.toggle('fa-eye');
            eyeIcon.classList.toggle('fa-eye-slash');
        });
    </script>

</body>
</html>