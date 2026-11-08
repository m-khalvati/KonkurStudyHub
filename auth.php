<?php
require_once 'config.php';

// خروج از حساب کاربری
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: auth.php");
    exit;
}

// اگر کاربر قبلاً لاگین کرده، مستقیم به داشبورد برود
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($action === 'register') {
        $full_name = trim($_POST['full_name'] ?? '');
        if (empty($full_name) || empty($username) || empty($password)) {
            $error = 'لطفاً تمامی فیلدها را پر کنید.';
        } elseif (strlen($password) < 5) {
            $error = 'رمز عبور باید حداقل ۵ کاراکتر باشد.';
        } else {
            // بررسی تکراری نبودن نام کاربری
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = 'این نام کاربری قبلاً ثبت شده است.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (full_name, username, password_hash) VALUES (?, ?, ?)");
                $stmt->execute([$full_name, $username, $hash]);
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
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['username'] = $user['username'];
                header("Location: index.php");
                exit;
            } else {
                $error = 'نام کاربری یا رمز عبور اشتباه است.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود به محیط آموزشی و برنامه‌ریزی کنکور</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
    <style>
        body { font-family: 'Vazirmatn', sans-serif; }
    </style>
</head>
<body class="bg-slate-900 min-h-screen flex items-center justify-center p-4">

    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
        <!-- هدر باکس ورود -->
        <div class="bg-indigo-600 p-6 text-center text-white">
            <h1 class="text-xl font-bold mb-1">سامانه مطالعه و تقویم کنکور</h1>
            <p class="text-indigo-100 text-sm">
                ⏱️ <?php echo DAYS_TO_KONKUR; ?> روز تا کنکور (۱۶ بهمن) باقی مانده است
            </p>
        </div>

        <!-- دکمه‌های تغییر تب ورود / ثبت‌نام -->
        <div class="flex border-b border-gray-200">
            <button id="tab-login" onclick="switchTab('login')" class="w-1/2 py-3 text-sm font-bold text-indigo-600 border-b-2 border-indigo-600 bg-indigo-50/50">
                ورود به حساب
            </button>
            <button id="tab-register" onclick="switchTab('register')" class="w-1/2 py-3 text-sm font-bold text-gray-500 hover:text-gray-700">
                ثبت‌نام کاربر جدید
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

            <!-- فرم ورود -->
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

            <!-- فرم ثبت‌نام -->
            <form id="form-register" method="POST" class="space-y-4 hidden">
                <input type="hidden" name="action" value="register">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">نام و نام خانوادگی</label>
                    <input type="text" name="full_name" required placeholder="مثلاً: علی محمدی" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">نام کاربری (انگلیسی)</label>
                    <input type="text" name="username" required dir="ltr" placeholder="ali_1405" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-left">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">رمز عبور (حداقل ۵ کاراکتر)</label>
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