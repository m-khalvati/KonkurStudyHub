<?php
require_once __DIR__ . '/config/config.php';

// تشخیص درخواست از سمت اپلیکیشن موبایل (Flutter)
$is_mobile_api = (
    (isset($_SERVER['HTTP_X_CLIENT_PLATFORM']) && strtolower($_SERVER['HTTP_X_CLIENT_PLATFORM']) === 'flutter') ||
    (isset($_GET['client']) && $_GET['client'] === 'flutter') ||
    (isset($_POST['client']) && $_POST['client'] === 'flutter') ||
    (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

// توابع محافظت در برابر حملات Brute-Force
function check_ip_rate_limit(PDO $pdo): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        $stmt = $pdo->prepare("SELECT attempts, locked_until FROM auth_rate_limits WHERE ip_address = ? LIMIT 1");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        if ($row && !empty($row['locked_until']) && strtotime($row['locked_until']) > time()) {
            return false; // IP مسدود است
        }
    } catch (Exception $ignore) {}
    return true;
}

function record_failed_login_attempt(PDO $pdo): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        $stmt = $pdo->prepare("SELECT attempts FROM auth_rate_limits WHERE ip_address = ? LIMIT 1");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        $attempts = $row ? ((int)$row['attempts'] + 1) : 1;
        $locked_until = ($attempts >= 7) ? date('Y-m-d H:i:s', time() + 900) : null; // ۱۵ دقیقه قفل پس از ۷ خطا

        $ins = $pdo->prepare("
            INSERT INTO auth_rate_limits (ip_address, attempts, locked_until)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), locked_until = VALUES(locked_until)
        ");
        $ins->execute([$ip, $attempts, $locked_until]);
    } catch (Exception $ignore) {}
}

function clear_login_attempts(PDO $pdo): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        $pdo->prepare("DELETE FROM auth_rate_limits WHERE ip_address = ?")->execute([$ip]);
    } catch (Exception $ignore) {}
}

// تابع تولید یا دریافت توکن امن کاربر و ارسال پاسخ JSON به فلاتر
function send_flutter_auth_response(PDO $pdo, array $user, string $message = 'عملیات با موفقیت انجام شد.', bool $force_new_token = false) {
    $raw_token = get_request_api_token();

    if ($force_new_token || empty($user['api_token']) || empty($raw_token)) {
        $raw_token = bin2hex(random_bytes(32));
        $upd = $pdo->prepare("UPDATE users SET api_token = ? WHERE id = ?");
        $upd->execute([$raw_token, (int)$user['id']]);
    } else {
        $raw_token = $user['api_token'];
    }

    $sub_info = get_user_subscription_info($pdo, (int)$user['id']);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'       => 'success',
        'message'      => $message,
        'token'        => $raw_token,
        'user'         => [
            'id'        => (int)$user['id'],
            'full_name' => $user['full_name'],
            'username'  => $user['username'],
            'phone'     => $user['phone'] ?? ''
        ],
        'subscription' => $sub_info,
        'app_config'   => [
            'days_to_konkur'     => defined('DAYS_TO_KONKUR') ? DAYS_TO_KONKUR : 0,
            'konkur_jalali_date' => defined('KONKUR_JALALI_DATE') ? KONKUR_JALALI_DATE : '',
            'telegram_sales_id'  => defined('TELEGRAM_SALES_ID') ? TELEGRAM_SALES_ID : '',
            'plan_1m_price'      => defined('PLAN_1M_PRICE') ? PLAN_1M_PRICE : '',
            'plan_3m_price'      => defined('PLAN_3M_PRICE') ? PLAN_3M_PRICE : ''
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================
// مدیریت درخواست‌های اختصاصی اپلیکیشن فلاتر (بررسی نشست و خروج)
// ==========================================
if ($is_mobile_api) {
    $req_action = $_GET['action'] ?? ($_POST['action'] ?? '');

    if ($req_action === 'me') {
        header('Content-Type: application/json; charset=utf-8');
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'نشست کاربری منقضی شده است. لطفاً مجدداً وارد شوید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $current_user = $stmt->fetch();
        if (!$current_user) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'کاربر یافت نشد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        send_flutter_auth_response($pdo, $current_user, 'اطلاعات حساب همگام‌سازی شد.', false);
    }

    if ($req_action === 'logout' || isset($_GET['logout'])) {
        if (isset($_SESSION['user_id'])) {
            $pdo->prepare("UPDATE users SET api_token = NULL WHERE id = ?")->execute([(int)$_SESSION['user_id']]);
        }
        $_SESSION = [];
        session_destroy();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'success', 'message' => 'از حساب کاربری خارج شدید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ==========================================
// روند عادی خروج و ریدایرکت در نسخه وب
// ==========================================
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header("Location: auth.php");
    exit;
}

if (!$is_mobile_api && isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!check_ip_rate_limit($pdo)) {
        $error = 'تعداد تلاش‌های ناموفق شما بیش از حد مجاز بوده است. لطفاً ۱۵ دقیقه دیگر مجدداً تلاش کنید.';
    } elseif ($action === 'register') {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');

        if (empty($full_name) || empty($username) || empty($phone) || empty($password)) {
            $error = 'لطفاً تمامی فیلدها از جمله شماره موبایل را پر کنید.';
        } elseif (!preg_match('/^09[0-9]{9}$/', $phone)) {
            $error = 'شماره موبایل معتبر نیست (فرمت صحیح: 09123456789).';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/', $username)) {
            $error = 'نام کاربری فقط باید شامل حروف انگلیسی، اعداد و خط تیره باشد (حداقل ۳ کاراکتر).';
        } elseif (strlen($password) < 6) {
            $error = 'رمز عبور باید حداقل ۶ کاراکتر باشد.';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'این نام کاربری قبلاً ثبت شده است.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $api_token = bin2hex(random_bytes(32));
                $stmt = $pdo->prepare("INSERT INTO users (full_name, username, phone, password_hash, api_token) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$full_name, $username, $phone, $hash, $api_token]);
                $new_user_id = (int)$pdo->lastInsertId();

                if ($is_mobile_api) {
                    $new_user = [
                        'id'        => $new_user_id,
                        'full_name' => $full_name,
                        'username'  => $username,
                        'phone'     => $phone,
                        'api_token' => $api_token
                    ];
                    send_flutter_auth_response($pdo, $new_user, 'ثبت‌نام با موفقیت انجام شد و وارد حساب شدید!', false);
                }

                $success = 'ثبت‌نام با موفقیت انجام شد! اکنون وارد شوید.';
            }
        }
    } elseif ($action === 'login') {
        if (empty($username) || empty($password)) {
            $error = 'نام کاربری و رمز عبور را وارد کنید.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                clear_login_attempts($pdo);
                session_regenerate_id(true); // جلوگیری از حمله Session Fixation

                $_SESSION['user_id']   = (int)$user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['phone']     = $user['phone'] ?? '';
                $_SESSION['is_admin']  = (int)($user['is_admin'] ?? 0);

                if ($is_mobile_api) {
                    send_flutter_auth_response($pdo, $user, 'ورود با موفقیت انجام شد.', false);
                }

                header("Location: index.php");
                exit;
            } else {
                record_failed_login_attempt($pdo);
                $error = 'نام کاربری یا رمز عبور اشتباه است.';
            }
        }
    }

    if ($is_mobile_api && !empty($error)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode([
            'status'  => 'error',
            'message' => $error
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود به محیط آموزشی و برنامه‌ریزی کنکور</title>
    <link href="assets/css/tailwind-local.css" rel="stylesheet">
    <link href="assets/css/vazirmatn-local.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
    <style>
        body { font-family: 'Vazirmatn', sans-serif; }
    </style>
</head>
<body class="bg-slate-900 min-h-screen flex items-center justify-center p-4">

    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
        <div class="bg-indigo-600 p-6 text-center text-white">
            <h1 class="text-xl font-bold mb-1">سامانه هوشمند مطالعه و تقویم کنکور</h1>
            <p class="text-indigo-100 text-sm">
                ⏱️ <?php echo DAYS_TO_KONKUR; ?> روز تا کنکور (۱۶ بهمن) باقی مانده است
            </p>
        </div>

        <div class="flex border-b border-gray-200">
            <button id="tab-login" onclick="switchTab('login')" class="w-1/2 py-3 text-sm font-bold text-indigo-600 border-b-2 border-indigo-600 bg-indigo-50/50">
                ورود به حساب
            </button>
            <button id="tab-register" onclick="switchTab('register')" class="w-1/2 py-3 text-sm font-bold text-gray-500 hover:text-gray-700">
                ثبت‌نام داوطلب جدید
            </button>
        </div>

        <div class="p-6">
            <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-600 text-sm rounded-lg p-3 mb-4">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-600 text-sm rounded-lg p-3 mb-4">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <form id="form-login" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="login">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">نام کاربری (انگلیسی)</label>
                    <input type="text" name="username" required dir="ltr" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-left">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">رمز عبور</label>
                    <input type="password" name="password" required dir="ltr" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-left">
                </div>
                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 rounded-lg transition">
                    ورود به محیط آموزشی
                </button>
            </form>

            <form id="form-register" method="POST" class="space-y-3.5 hidden">
                <input type="hidden" name="action" value="register">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">نام و نام خانوادگی</label>
                    <input type="text" name="full_name" required placeholder="مثلاً: علی محمدی" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">شماره موبایل (جهت دریافت پیامک پیگیری برنامه)</label>
                    <input type="tel" name="phone" required dir="ltr" pattern="09[0-9]{9}" maxlength="11" placeholder="09123456789" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-left">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">نام کاربری (انگلیسی)</label>
                    <input type="text" name="username" required dir="ltr" placeholder="ali_1405" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-left">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">رمز عبور (حداقل ۶ کاراکتر)</label>
                    <input type="password" name="password" required dir="ltr" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-left">
                </div>
                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 rounded-lg transition">
                    ساخت حساب کاربری
                </button>
            </form>
        </div>
    </div>

    <script>
        function switchTab(tab) {
            const loginForm = document.getElementById('form-login');
            const regForm = document.getElementById('form-register');
            const tabLogin = document.getElementById('tab-login');
            const tabReg = document.getElementById('tab-register');

            if (tab === 'login') {
                loginForm.classList.remove('hidden');
                regForm.classList.add('hidden');
                tabLogin.className = 'w-1/2 py-3 text-sm font-bold text-indigo-600 border-b-2 border-indigo-600 bg-indigo-50/50';
                tabReg.className = 'w-1/2 py-3 text-sm font-bold text-gray-500 hover:text-gray-700';
            } else {
                loginForm.classList.add('hidden');
                regForm.classList.remove('hidden');
                tabReg.className = 'w-1/2 py-3 text-sm font-bold text-indigo-600 border-b-2 border-indigo-600 bg-indigo-50/50';
                tabLogin.className = 'w-1/2 py-3 text-sm font-bold text-gray-500 hover:text-gray-700';
            }
        }
    </script>
</body>
</html>