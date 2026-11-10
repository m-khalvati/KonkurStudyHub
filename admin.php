<?php
require_once 'config.php';
require_login();

$user_id  = (int)$_SESSION['user_id'];
$sub_info = get_user_subscription_info($pdo, $user_id);

if (!$sub_info['is_admin']) {
    die('<div style="font-family:tahoma;text-align:center;margin-top:50px;direction:rtl;">⛔ شما دسترسی به پنل مدیریت ندارید. <a href="index.php">بازگشت به داشبورد</a></div>');
}

// دریافت لیست تمام کاربران به همراه تعداد فایل‌های PDF آپلودشده هر کاربر
$usersStmt = $pdo->query("
    SELECT u.*, 
           (SELECT COUNT(*) FROM pdfs p WHERE p.user_id = u.id) AS pdf_count
    FROM users u
    ORDER BY u.id DESC
");
$all_users = $usersStmt->fetchAll();

// دریافت لیست کدهای اشتراک ساخته‌شده
$codesStmt = $pdo->query("
    SELECT c.*, u.username AS used_by_username, u.full_name AS used_by_name
    FROM subscription_codes c
    LEFT JOIN users u ON c.used_by = u.id
    ORDER BY c.id DESC
    LIMIT 50
");
$all_codes = $codesStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پنل مدیریت اشتراک‌ها و کاربران | کنکور</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
    <style>
        body { font-family: 'Vazirmatn', sans-serif; }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen">

    <!-- هدر پنل ادمین -->
    <header class="bg-slate-900 text-white px-4 py-3 flex flex-wrap items-center justify-between gap-3 shadow-md">
        <div class="flex items-center gap-2">
            <span class="bg-amber-500 text-slate-950 px-2.5 py-1 rounded-lg font-black text-xs">👑 پنل مدیریت کل</span>
            <h1 class="font-extrabold text-sm md:text-base">مدیریت اشتراک‌های ویژه و کاربران</h1>
        </div>
        <div class="flex items-center gap-3 text-xs">
            <span class="text-slate-400">آیدی تلگرام فروش: <b class="text-sky-400 dir-ltr">@<?php echo htmlspecialchars(TELEGRAM_SALES_ID); ?></b></span>
            <a href="index.php" class="bg-indigo-600 hover:bg-indigo-500 text-white px-3.5 py-1.5 rounded-lg font-bold transition">
                ⬅ بازگشت به داشبورد اصلی
            </a>
        </div>
    </header>

    <main class="max-w-6xl mx-auto p-4 space-y-6">

        <!-- بخش ۱: ساخت کد فعال‌سازی یک‌بارمصرف (برای ارسال سریع در تلگرام) -->
        <section class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-3 mb-4">
                <div>
                    <h2 class="font-extrabold text-sm md:text-base text-slate-800">🎟️ صدور کد فعال‌سازی اشتراک (برای خریداران تلگرام)</h2>
                    <p class="text-xs text-slate-500 mt-0.5">می‌توانید کد یک‌بارمصرف بسازید و در تلگرام برای کاربر بفرستید تا خودش وارد کند، یا از جدول پایین مستقیماً اکانتش را شارژ کنید.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="generateCode('1m')" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3.5 py-2 rounded-xl text-xs font-extrabold shadow-xs transition">
                        + ساخت کد ۱ ماهه (۵۰۰ هزار تومان)
                    </button>
                    <button onclick="generateCode('3m')" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3.5 py-2 rounded-xl text-xs font-extrabold shadow-xs transition">
                        + ساخت کد ۳ ماهه (۱,۲۰۰,۰۰۰ تومان)
                    </button>
                </div>
            </div>

            <!-- جدول کدهای صادر شده -->
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-right border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-slate-600 border-b border-slate-200">
                            <th class="py-2.5 px-3">کد فعال‌سازی</th>
                            <th class="py-2.5 px-3">نوع پلن</th>
                            <th class="py-2.5 px-3">وضعیت</th>
                            <th class="py-2.5 px-3">استفاده‌کننده</th>
                            <th class="py-2.5 px-3 text-left">عملیات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($all_codes)): ?>
                            <tr><td colspan="5" class="text-center py-6 text-slate-400">هنوز کدی صادر نشده است.</td></tr>
                        <?php else: ?>
                            <?php foreach ($all_codes as $c): ?>
                                <tr>
                                    <td class="py-2.5 px-3 font-mono font-black text-sm text-indigo-700 dir-ltr text-right">
                                        <?php echo htmlspecialchars($c['code']); ?>
                                    </td>
                                    <td class="py-2.5 px-3 font-bold">
                                        <?php if ($c['plan_type'] === '3m'): ?>
                                            <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 rounded-md">۳ ماهه (۹۰ روز)</span>
                                        <?php else: ?>
                                            <span class="bg-indigo-50 text-indigo-700 border border-indigo-200 px-2 py-0.5 rounded-md">۱ ماهه (۳۰ روز)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <?php if ((int)$c['is_used'] === 1): ?>
                                            <span class="text-slate-400 font-bold">✅ استفاده شده</span>
                                        <?php else: ?>
                                            <span class="text-amber-600 font-extrabold">🟢 آماده مصرف</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-slate-600">
                                        <?php echo $c['used_by_username'] ? htmlspecialchars($c['used_by_name'] . ' (' . $c['used_by_username'] . ')') : '---'; ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-left">
                                        <?php if ((int)$c['is_used'] === 0): ?>
                                            <button onclick="copyCodeMessage('<?php echo $c['code']; ?>', '<?php echo $c['days']; ?>')" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-2.5 py-1 rounded-lg font-bold ml-1">
                                                📋 کپی متن تلگرام
                                            </button>
                                            <button onclick="deleteCode(<?php echo $c['id']; ?>)" class="text-rose-500 hover:text-rose-700 font-bold px-1">🗑️</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- بخش ۲: لیست کاربران و فعال‌سازی/تمدید مستقیم اشتراک -->
        <section class="bg-white rounded-2xl shadow-sm border border-slate-200 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-3 mb-4">
                <div>
                    <h2 class="font-extrabold text-sm md:text-base text-slate-800">👥 لیست کاربران و تمدید مستقیم اشتراک</h2>
                    <p class="text-xs text-slate-500 mt-0.5">با زدن دکمه «+۱ ماه» یا «+۳ ماه»، روزهای جدید به صورت تجمعی به اعتبار فعلی کاربر اضافه می‌شود.</p>
                </div>
                <input type="text" id="user-search-input" oninput="filterUsersTable()" placeholder="🔍 جستجوی نام، نام کاربری یا موبایل..." class="px-3 py-1.5 border border-slate-300 rounded-xl text-xs w-64">
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-xs text-right border-collapse" id="users-table">
                    <thead>
                        <tr class="bg-slate-50 text-slate-600 border-b border-slate-200">
                            <th class="py-2.5 px-3">#</th>
                            <th class="py-2.5 px-3">نام و نام خانوادگی</th>
                            <th class="py-2.5 px-3">نام کاربری</th>
                            <th class="py-2.5 px-3">شماره موبایل</th>
                            <th class="py-2.5 px-3">تعداد PDF</th>
                            <th class="py-2.5 px-3">وضعیت اشتراک</th>
                            <th class="py-2.5 px-3 text-left">شارژ / تمدید تجمعی</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($all_users as $u): 
                            $u_sub = get_user_subscription_info($pdo, $u['id']);
                        ?>
                            <tr class="user-row hover:bg-slate-50/80 transition">
                                <td class="py-3 px-3 font-bold text-slate-400"><?php echo $u['id']; ?></td>
                                <td class="py-3 px-3 font-extrabold text-slate-800">
                                    <?php echo htmlspecialchars($u['full_name']); ?>
                                    <?php if ((int)$u['is_admin'] === 1): ?>
                                        <span class="bg-amber-100 text-amber-800 px-1.5 py-0.5 rounded text-[10px] mr-1">ادمین</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-3 font-mono text-indigo-600 font-bold dir-ltr text-right"><?php echo htmlspecialchars($u['username']); ?></td>
                                <td class="py-3 px-3 font-mono dir-ltr text-right"><?php echo htmlspecialchars($u['phone'] ?: '---'); ?></td>
                                <td class="py-3 px-3 font-bold"><?php echo (int)$u['pdf_count']; ?> فایل</td>
                                <td class="py-3 px-3">
                                    <?php if ($u_sub['is_vip']): ?>
                                        <span class="bg-emerald-100 text-emerald-800 border border-emerald-300 px-2 py-0.5 rounded-full font-extrabold">
                                            👑 ویژه (<?php echo $u_sub['days_left']; ?> روز مانده - تا <?php echo $u_sub['expires_j']; ?>)
                                        </span>
                                    <?php else: ?>
                                        <span class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full font-bold">
                                            👤 کاربر عادی (رایگان)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-3 text-left space-x-1 space-x-reverse">
                                    <button onclick="extendUserSub(<?php echo $u['id']; ?>, 30, '<?php echo htmlspecialchars($u['username']); ?>')" class="bg-indigo-600 hover:bg-indigo-700 text-white px-2.5 py-1.5 rounded-lg font-bold transition">
                                        ➕ ۱ ماه (+۳۰ روز)
                                    </button>
                                    <button onclick="extendUserSub(<?php echo $u['id']; ?>, 90, '<?php echo htmlspecialchars($u['username']); ?>')" class="bg-emerald-600 hover:bg-emerald-700 text-white px-2.5 py-1.5 rounded-lg font-bold transition">
                                        ➕ ۳ ماه (+۹۰ روز)
                                    </button>
                                    <?php if ($u_sub['is_vip']): ?>
                                        <button onclick="revokeUserSub(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['username']); ?>')" class="bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 px-2 py-1.5 rounded-lg font-bold transition" title="لغو اشتراک">
                                            ✕ لغو
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>

    <script>
        async function generateCode(planType) {
            const fd = new FormData();
            fd.append('action', 'admin_generate_code');
            fd.append('plan_type', planType);

            const res = await fetch('api_subscription.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                location.reload();
            } else {
                alert(data.message || 'خطا در ساخت کد');
            }
        }

        function copyCodeMessage(code, days) {
            const label = Number(days) >= 90 ? '۳ ماهه (۹۰ روزه)' : '۱ ماهه (۳۰ روزه)';
            const msg = `✅ کد فعال‌سازی اشتراک ویژه ${label} شما:\n\n${code}\n\n📌 راهنما: وارد حساب خود در سایت شوید، در بالای صفحه روی دکمه «👑 اشتراک ویژه» کلیک کنید و این کد را در کادر فعال‌سازی وارد نمایید.`;
            navigator.clipboard.writeText(msg).then(() => {
                alert('متن کامل به همراه کد اشتراک کپی شد! اکنون می‌توانید در تلگرام برای کاربر Paste کنید.');
            });
        }

        async function deleteCode(codeId) {
            if (!confirm('این کد حذف شود؟')) return;
            const fd = new FormData();
            fd.append('action', 'admin_delete_code');
            fd.append('code_id', codeId);
            await fetch('api_subscription.php', { method: 'POST', body: fd });
            location.reload();
        }

        async function extendUserSub(userId, days, username) {
            if (!confirm(`آیا مطمئن هستید که می‌خواهید ${days} روز به اشتراک ویژه کاربر «${username}» اضافه کنید؟`)) return;
            const fd = new FormData();
            fd.append('action', 'admin_extend_user');
            fd.append('target_user_id', userId);
            fd.append('days', days);

            const res = await fetch('api_subscription.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message);
            if (data.status === 'success') location.reload();
        }

        async function revokeUserSub(userId, username) {
            if (!confirm(`آیا اشتراک ویژه کاربر «${username}» لغو و به حالت عادی برگردد؟`)) return;
            const fd = new FormData();
            fd.append('action', 'admin_revoke_user');
            fd.append('target_user_id', userId);

            const res = await fetch('api_subscription.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message);
            if (data.status === 'success') location.reload();
        }

        function filterUsersTable() {
            const q = document.getElementById('user-search-input').value.toLowerCase();
            document.querySelectorAll('.user-row').forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none';
            });
        }
    </script>
</body>
</html>