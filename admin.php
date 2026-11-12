<?php
require_once 'config.php';
require_login();

$user_id = (int)$_SESSION['user_id'];
$sub_info = function_exists('get_user_subscription_info')
    ? get_user_subscription_info($pdo, $user_id)
    : ['is_admin' => ($user_id === 1)];

if (empty($sub_info['is_admin']) && $user_id !== 1) {
    http_response_code(403);
    die('<div style="font-family:tahoma;text-align:center;margin-top:60px;direction:rtl;">⛔ دسترسی محدود به مدیر کل سامانه است. <a href="index.php">بازگشت به داشبورد</a></div>');
}

// اطمینان از وجود جدول کدهای اشتراک
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `subscription_codes` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `code` varchar(32) NOT NULL UNIQUE,
          `plan_type` varchar(20) NOT NULL,
          `days` int(11) NOT NULL DEFAULT 30,
          `is_used` tinyint(1) DEFAULT 0,
          `used_by` int(11) DEFAULT NULL,
          `used_at` datetime DEFAULT NULL,
          `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
    ");
} catch (Exception $ignore) {}

// ====================================================
// پردازش درخواست‌های AJAX مدیریت کاربران و کدهای اشتراک
// ====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_POST['admin_ajax_action'];

    try {
        if ($act === 'charge_user_vip') {
            $target_uid = (int)($_POST['target_user_id'] ?? 0);
            $days       = (int)($_POST['days'] ?? 30);

            $stmt = $pdo->prepare("SELECT vip_expires_at FROM users WHERE id = ?");
            $stmt->execute([$target_uid]);
            $u = $stmt->fetch();
            if (!$u) {
                echo json_encode(['status' => 'error', 'message' => 'کاربر یافت نشد.']);
                exit;
            }

            if ($days <= 0) {
                $upd = $pdo->prepare("UPDATE users SET vip_expires_at = NULL WHERE id = ?");
                $upd->execute([$target_uid]);
                echo json_encode(['status' => 'success', 'message' => 'اشتراک ویژه کاربر لغو شد و به حالت عادی برگشت.']);
                exit;
            }

            $now_ts  = time();
            $cur_exp = !empty($u['vip_expires_at']) ? strtotime($u['vip_expires_at']) : 0;
            $base_ts = ($cur_exp > $now_ts) ? $cur_exp : $now_ts;
            $new_exp = date('Y-m-d H:i:s', $base_ts + ($days * 86400));

            $upd = $pdo->prepare("UPDATE users SET vip_expires_at = ? WHERE id = ?");
            $upd->execute([$new_exp, $target_uid]);

            echo json_encode(['status' => 'success', 'message' => "{$days} روز اشتراک ویژه به حساب کاربر اضافه شد."]);
            exit;
        }

        if ($act === 'generate_codes') {
            $plan_type = ($_POST['plan_type'] ?? '1m') === '3m' ? '3m' : '1m';
            $days      = ($plan_type === '3m') ? 90 : 30;
            $count     = max(1, min(50, (int)($_POST['count'] ?? 5)));
            $prefix    = ($plan_type === '3m') ? 'VIP90' : 'VIP30';

            $ins = $pdo->prepare("INSERT INTO subscription_codes (code, plan_type, days, is_used) VALUES (?, ?, ?, 0)");
            $generated = [];
            for ($i = 0; $i < $count; $i++) {
                $p1 = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
                $p2 = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
                $code = "{$prefix}-{$p1}-{$p2}";
                $ins->execute([$code, $plan_type, $days]);
                $generated[] = $code;
            }

            echo json_encode([
                'status'  => 'success',
                'codes'   => $generated,
                'message' => count($generated) . ' کد فعال‌سازی جدید ساخته شد.'
            ]);
            exit;
        }

        if ($act === 'delete_code') {
            $cid = (int)($_POST['code_id'] ?? 0);
            $pdo->prepare("DELETE FROM subscription_codes WHERE id = ?")->execute([$cid]);
            echo json_encode(['status' => 'success']);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

$users_list = [];
$codes_list = [];
$active_vips_count = 0;

try {
    $users_list = $pdo->query("
        SELECT u.*,
               (SELECT COUNT(*) FROM pdfs p WHERE p.user_id = u.id) AS pdf_count,
               (SELECT COUNT(*) FROM calendar_events e WHERE e.user_id = u.id) AS event_count
        FROM users u
        ORDER BY u.id DESC
    ")->fetchAll();

    $codes_list = $pdo->query("
        SELECT c.*, u.full_name AS used_by_name, u.username AS used_by_username
        FROM subscription_codes c
        LEFT JOIN users u ON u.id = c.used_by
        ORDER BY c.is_used ASC, c.id DESC
        LIMIT 100
    ")->fetchAll();

    foreach ($users_list as $ur) {
        if (!empty($ur['vip_expires_at']) && strtotime($ur['vip_expires_at']) > time()) {
            $active_vips_count++;
        }
    }
} catch (Exception $ignore) {}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پنل مدیریت کل و استودیو آزمون‌ساز هوشمند v4.3</title>

    <!-- ۱. بارگذاری فایل CSS پایه از سرور بدون اختلال jsDelivr (تضمین ۱۰۰٪ لود شدن استایل حتی در صورت مسدود بودن دامنه tailwindcss.com) -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <!-- ۲. بارگذاری موتور JIT در صورت در دسترس بودن -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- ۳. فونت وزیرمتن -->
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
    <!-- ۴. موتور PDF.js از jsDelivr و پشتیبان unpkg -->
    <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"
            onerror="this.onerror=null;this.src='https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.min.js';"></script>

    <!-- ۵. استایل‌های داخلی کامل (Built-in Fallback CSS) برای تضمین ظاهر عالی در تمام شبکه‌ها -->
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', Tahoma, sans-serif;
            background-color: #f1f5f9;
            color: #1e293b;
            margin: 0;
        }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        /* کلاس‌های تکمیلی Tailwind v3 برای زمانی که فقط فایل پایه jsDelivr لود شود */
        .bg-slate-50 { background-color: #f8fafc !important; }
        .bg-slate-100 { background-color: #f1f5f9 !important; }
        .bg-slate-200 { background-color: #e2e8f0 !important; }
        .bg-slate-700 { background-color: #334155 !important; }
        .bg-slate-800 { background-color: #1e293b !important; }
        .bg-slate-900 { background-color: #0f172a !important; }
        .bg-slate-950 { background-color: #020617 !important; }

        .text-slate-200 { color: #e2e8f0 !important; }
        .text-slate-300 { color: #cbd5e1 !important; }
        .text-slate-400 { color: #94a3b8 !important; }
        .text-slate-500 { color: #64748b !important; }
        .text-slate-600 { color: #475569 !important; }
        .text-slate-700 { color: #334155 !important; }
        .text-slate-800 { color: #1e293b !important; }
        .text-slate-900 { color: #0f172a !important; }
        .text-slate-950 { color: #020617 !important; }

        .border-slate-100 { border-color: #f1f5f9 !important; }
        .border-slate-200 { border-color: #e2e8f0 !important; }
        .border-slate-300 { border-color: #cbd5e1 !important; }
        .border-slate-700 { border-color: #334155 !important; }
        .border-slate-800 { border-color: #1e293b !important; }

        .bg-indigo-50 { background-color: #eef2ff !important; }
        .bg-indigo-100 { background-color: #e0e7ff !important; }
        .bg-indigo-600 { background-color: #4f46e5 !important; }
        .bg-indigo-700 { background-color: #4338ca !important; }
        .bg-indigo-950 { background-color: #1e1b4b !important; }
        .text-indigo-600 { color: #4f46e5 !important; }
        .text-indigo-700 { color: #4338ca !important; }
        .text-indigo-800 { color: #3730a3 !important; }
        .text-indigo-900 { color: #312e81 !important; }
        .text-indigo-950 { color: #1e1b4b !important; }
        .border-indigo-200 { border-color: #c7d2fe !important; }
        .border-indigo-500 { border-color: #6366f1 !important; }
        .border-indigo-800 { border-color: #3730a3 !important; }

        .bg-emerald-50 { background-color: #ecfdf5 !important; }
        .bg-emerald-100 { background-color: #d1fae5 !important; }
        .bg-emerald-500 { background-color: #10b981 !important; }
        .bg-emerald-600 { background-color: #059669 !important; }
        .bg-emerald-700 { background-color: #047857 !important; }
        .text-emerald-300 { color: #6ee7b7 !important; }
        .text-emerald-600 { color: #059669 !important; }
        .text-emerald-700 { color: #047857 !important; }
        .text-emerald-800 { color: #065f46 !important; }
        .text-emerald-900 { color: #064e3b !important; }
        .text-emerald-950 { color: #022c22 !important; }
        .border-emerald-200 { border-color: #a7f3d0 !important; }
        .border-emerald-400 { border-color: #34d399 !important; }

        .bg-amber-50 { background-color: #fffbeb !important; }
        .bg-amber-100 { background-color: #fef3c7 !important; }
        .bg-amber-400 { background-color: #fbbf24 !important; }
        .bg-amber-500 { background-color: #f59e0b !important; }
        .text-amber-200 { color: #fde68a !important; }
        .text-amber-300 { color: #fcd34d !important; }
        .text-amber-600 { color: #d97706 !important; }
        .text-amber-700 { color: #b45309 !important; }
        .text-amber-800 { color: #92400e !important; }
        .text-amber-900 { color: #78350f !important; }
        .text-amber-950 { color: #451a03 !important; }
        .border-amber-200 { border-color: #fde68a !important; }
        .border-amber-300 { border-color: #fcd34d !important; }

        .bg-rose-50 { background-color: #fff1f2 !important; }
        .bg-rose-100 { background-color: #ffe4e6 !important; }
        .bg-rose-600 { background-color: #e11d48 !important; }
        .text-rose-500 { color: #f43f5e !important; }
        .text-rose-600 { color: #e11d48 !important; }
        .text-rose-700 { color: #be123c !important; }
        .text-rose-800 { color: #9f1239 !important; }

        .rounded-xl { border-radius: 0.75rem !important; }
        .rounded-2xl { border-radius: 1rem !important; }
        .rounded-3xl { border-radius: 1.5rem !important; }
        .shrink-0 { flex-shrink: 0 !important; }
        .text-\[10px\] { font-size: 10px !important; }
        .text-\[11px\] { font-size: 11px !important; }
        .max-w-\[1600px\] { max-width: 1600px !important; }
        .min-h-\[420px\] { min-height: 420px !important; }
        .max-h-\[72vh\] { max-height: 72vh !important; }
        .max-h-\[520px\] { max-height: 520px !important; }
        .max-h-\[580px\] { max-height: 580px !important; }
        .max-h-\[560px\] { max-height: 560px !important; }
        .gap-1\.5 { gap: 0.375rem !important; }
        .gap-2\.5 { gap: 0.625rem !important; }
        .gap-3\.5 { gap: 0.875rem !important; }
        .p-2\.5 { padding: 0.625rem !important; }
        .p-3\.5 { padding: 0.875rem !important; }
        .px-2\.5 { padding-left: 0.625rem !important; padding-right: 0.625rem !important; }
        .px-3\.5 { padding-left: 0.875rem !important; padding-right: 0.875rem !important; }
        .py-1\.5 { padding-top: 0.375rem !important; padding-bottom: 0.375rem !important; }
        .py-2\.5 { padding-top: 0.625rem !important; padding-bottom: 0.625rem !important; }

        @media (min-width: 1024px) {
            .lg\:grid-cols-12 { grid-template-columns: repeat(12, minmax(0, 1fr)) !important; }
            .lg\:col-span-7 { grid-column: span 7 / span 7 !important; }
            .lg\:col-span-5 { grid-column: span 5 / span 5 !important; }
            .lg\:col-span-2 { grid-column: span 2 / span 2 !important; }
            .lg\:grid-cols-6 { grid-template-columns: repeat(6, minmax(0, 1fr)) !important; }
            .lg\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }
        }

        #source-canvas-wrapper {
            direction: ltr !important;
            text-align: left !important;
            unicode-bidi: isolate !important;
            position: relative;
            margin: 0 auto;
            user-select: none;
        }
        #source-render-canvas {
            direction: ltr !important;
            display: block;
            max-width: 100%;
            height: auto;
        }
        #source-crop-layer {
            direction: ltr !important;
            position: absolute;
            top: 0; right: 0; bottom: 0; left: 0;
            cursor: crosshair;
            touch-action: none;
        }
        #crop-selection-rect {
            position: absolute;
            border: 2px dashed #4f46e5;
            background-color: rgba(79, 70, 229, 0.16);
            box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.35);
            pointer-events: none;
            display: none;
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen flex flex-col">

    <!-- ==================================================== -->
    <!-- ۱. هدر پنل مدیریت با ۳ تب: آزمون‌ساز هوشمند | مانیتورینگ کلیدهای AI | کاربران و اشتراک‌ها -->
    <!-- ==================================================== -->
    <header class="bg-slate-900 text-white px-4 py-2.5 flex flex-wrap items-center justify-between gap-3 shadow-md sticky top-0 z-40">
        <div class="flex flex-wrap items-center gap-2.5">
            <span class="bg-amber-400 text-slate-950 px-2.5 py-1 rounded-xl font-black text-xs">🛠️ پنل مدیریت کل</span>

            <div class="flex bg-slate-800 p-1 rounded-xl border border-slate-700 text-xs">
                <button type="button" id="adm-tab-exams" onclick="switchAdminTab('exams')" class="px-3 py-1.5 rounded-lg font-extrabold bg-indigo-600 text-white transition flex items-center gap-1.5">
                    <span>📝 استودیو آزمون‌ساز</span>
                </button>
                <button type="button" id="adm-tab-aikeys" onclick="switchAdminTab('aikeys')" class="px-3 py-1.5 rounded-lg font-bold text-slate-300 hover:text-white transition flex items-center gap-1.5">
                    <span>🤖 کلیدهای هوش مصنوعی</span>
                    <span id="header-ai-keys-badge" class="bg-emerald-600 text-white px-2 py-0.5 rounded-full text-[10px] font-extrabold">0</span>
                </button>
                <button type="button" id="adm-tab-users" onclick="switchAdminTab('users')" class="px-3 py-1.5 rounded-lg font-bold text-slate-300 hover:text-white transition flex items-center gap-1.5">
                    <span>👥 کاربران و اشتراک‌ها</span>
                </button>
            </div>
        </div>

        <div class="flex items-center gap-2 text-xs font-bold">
            <a href="exam.php" class="bg-emerald-600 hover:bg-emerald-500 text-white px-3 py-1.5 rounded-xl transition flex items-center gap-1 shadow">
                <span>🎯 صفحه آزمون‌ها (نمای کاربر)</span>
            </a>
            <a href="index.php" class="bg-slate-800 hover:bg-slate-700 text-slate-200 px-3 py-1.5 rounded-xl border border-slate-700 transition">
                📅 تقویم و جزوه‌خوان
            </a>
            <a href="paper_reader.php" class="bg-slate-800 hover:bg-slate-700 text-slate-200 px-3 py-1.5 rounded-xl border border-slate-700 transition">
                🌐 مقاله‌خوان
            </a>
        </div>
    </header>

    <!-- ==================================================== -->
    <!-- ۲. تب اول: استودیو آزمون‌ساز هوشمند (خزنده چندصفحه‌ای + برش خودکار شکل + شبه‌کد) -->
    <!-- ==================================================== -->
    <main id="admin-section-exams" class="flex-1 p-3 md:p-5 max-w-[1600px] w-full mx-auto space-y-4">

        <!-- نوار انتخاب آزمون فعال + دکمه کلید پاسخنامه دسته‌جمعی -->
        <div class="bg-white rounded-2xl shadow border border-slate-200 p-3.5 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2 flex-1">
                <label class="font-extrabold text-xs text-slate-700 shrink-0">📂 آزمون فعال:</label>
                <select id="studio-exam-select" onchange="onSelectStudioExam(this.value)" class="flex-1 max-w-md px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-extrabold text-slate-800 focus:outline-none">
                    <option value="">-- در حال بارگذاری لیست آزمون‌ها... --</option>
                </select>
                <button type="button" onclick="openExamMetaModal(false)" id="btn-edit-exam-meta" class="hidden px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition">
                    ⚙️ تنظیمات آزمون
                </button>
                <button type="button" onclick="openBulkKeyModal()" id="btn-bulk-answer-key" class="hidden px-3 py-2 bg-amber-50 hover:bg-amber-100 text-amber-900 border border-amber-300 rounded-xl text-xs font-extrabold transition">
                    🔑 ثبت سریع کلید پاسخنامه
                </button>
                <button type="button" onclick="deleteActiveExam()" id="btn-delete-exam" class="hidden px-3 py-2 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-xl text-xs font-bold transition">
                    🗑️ حذف آزمون
                </button>
            </div>

            <div class="flex items-center gap-2">
                <button type="button" onclick="switchAdminTab('aikeys')" class="px-3 py-2 bg-slate-900 text-emerald-300 rounded-xl text-[11px] font-bold flex items-center gap-1.5">
                    <span>🤖 کلیدهای AI:</span>
                    <span id="studio-ai-summary-pill">...</span>
                </button>
                <button type="button" onclick="openExamMetaModal(true)" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-xl text-xs font-extrabold shadow transition flex items-center gap-1.5">
                    <span>➕ ساخت آزمون جدید</span>
                </button>
            </div>
        </div>

        <!-- پیام راهنما در صورت عدم انتخاب آزمون -->
        <div id="studio-empty-state" class="bg-white rounded-2xl border border-slate-200 p-10 text-center space-y-3">
            <div class="text-4xl">📝</div>
            <h2 class="font-extrabold text-base text-slate-800">استودیو طراحی آزمون و استخراج خودکار چندصفحه‌ای سوالات</h2>
            <p class="text-xs text-slate-500 max-w-lg mx-auto leading-relaxed">
                یک آزمون جدید بسازید یا از لیست بالا انتخاب نمایید؛ سپس فایل PDF سوالات را باز کنید تا هوش مصنوعی صفحه به صفحه جلو برود و سوالات، گزینه‌ها، شبه‌کدها و شکل‌های داخل صفحه را استخراج کند.
            </p>
            <button type="button" onclick="openExamMetaModal(true)" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-xl text-xs font-extrabold shadow">
                + ساخت اولین آزمون
            </button>
        </div>

        <!-- میز کار دو ستونه استودیو آزمون‌ساز -->
        <div id="studio-workspace" class="hidden grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">

            <!-- ========================================== -->
            <!-- ستون راست (۷ واحد): بارگذاری PDF/عکس + ربات استخراج چندصفحه‌ای + برش خودکار و دستی -->
            <!-- ========================================== -->
            <div class="lg:col-span-7 bg-white rounded-2xl shadow border border-slate-200 overflow-hidden flex flex-col">
                <div class="bg-slate-900 text-white px-4 py-3 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="text-base">📄</span>
                        <div>
                            <h3 class="font-extrabold text-xs md:text-sm">منبع سوالات (فایل PDF چندصفحه‌ای یا تصویر سوالات)</h3>
                            <p class="text-[10px] text-slate-400">پشتیبانی از استخراج خودکار صفحه به صفحه، تشخیص شبه‌کد (Pseudocode) و برش خودکار شکل‌ها</p>
                        </div>
                    </div>

                    <label class="bg-indigo-600 hover:bg-indigo-500 text-white px-3.5 py-1.5 rounded-xl text-xs font-extrabold cursor-pointer shadow transition flex items-center gap-1.5">
                        <span>📂 انتخاب فایل PDF یا عکس سوالات</span>
                        <input type="file" id="studio-source-file" accept="application/pdf,image/*" onchange="handleStudioSourceFile(event)" class="sr-only">
                    </label>
                </div>

                <!-- نوار اول: ورق زدن صفحه فعلی و استخراج تکی -->
                <div id="source-toolbar" class="hidden bg-slate-100 border-b border-slate-200 px-3 py-2 flex-wrap items-center justify-between gap-2 text-xs">
                    <div id="source-pdf-pagination" class="flex items-center gap-1">
                        <button type="button" onclick="changeSourcePdfPage(-1)" class="px-2.5 py-1 bg-white border border-slate-300 hover:bg-slate-50 rounded-lg font-bold">❮ قبلی</button>
                        <div class="flex items-center gap-1 bg-white px-2 py-1 rounded-lg border border-slate-300 font-bold">
                            <span>صفحه</span>
                            <input type="number" id="source-page-input" onchange="jumpSourcePdfPage(this.value)" min="1" value="1" class="w-12 text-center font-extrabold focus:outline-none">
                            <span>از</span>
                            <span id="source-total-pages">1</span>
                        </div>
                        <button type="button" onclick="changeSourcePdfPage(1)" class="px-2.5 py-1 bg-white border border-slate-300 hover:bg-slate-50 rounded-lg font-bold">بعدی ❯</button>
                    </div>

                    <div class="flex items-center gap-1.5 flex-1">
                        <input type="text" id="ai-ocr-hint" placeholder="راهنمایی اختیاری برای هوش مصنوعی (مثلاً: سوالات الگوریتم و شبه‌کد هستند)..." class="w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-xl text-[11px] focus:outline-none">
                    </div>

                    <button type="button" id="btn-ai-extract-page" onclick="runAiQuestionExtraction(false)" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-xl font-extrabold text-xs shadow transition flex items-center gap-1 shrink-0">
                        <span>🤖 استخراج همین صفحه</span>
                    </button>
                </div>

                <!-- نوار دوم: ربات خزنده خودکار چندصفحه‌ای (Multi-Page Auto-Crawler) -->
                <div id="multi-page-crawler-bar" class="hidden bg-slate-900 text-white px-3.5 py-2.5 border-b border-indigo-800 space-y-2 text-xs">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-extrabold text-amber-300 flex items-center gap-1">
                                <span>🚀 استخراج خودکار صفحه به صفحه:</span>
                            </span>
                            <div class="flex items-center gap-1 bg-slate-800 px-2 py-1 rounded-lg border border-slate-700 text-[11px]">
                                <span>از ص</span>
                                <input type="number" id="crawler-start-page" min="1" value="1" class="w-12 text-center bg-slate-950 text-white rounded font-extrabold">
                                <span>تا ص</span>
                                <input type="number" id="crawler-end-page" min="1" value="1" class="w-12 text-center bg-slate-950 text-white rounded font-extrabold">
                            </div>

                            <label class="flex items-center gap-1 cursor-pointer text-[11px] bg-slate-800 px-2 py-1 rounded-lg border border-slate-700" title="اگر شکلی در صفحه تشخیص داده شود، خودکار از روی صفحه برش خورده و به سوال پیوست می‌شود">
                                <input type="checkbox" id="crawler-auto-crop-fig" checked class="w-4 h-4 rounded">
                                <span>✂️ برش خودکار شکل‌ها</span>
                            </label>

                            <label class="flex items-center gap-1 cursor-pointer text-[11px] bg-slate-800 px-2 py-1 rounded-lg border border-slate-700" title="سوالات هر صفحه بلافاصله در بانک آزمون ذخیره شوند تا در فایل‌های طولانی چیزی از دست نرود">
                                <input type="checkbox" id="crawler-auto-save-db" checked class="w-4 h-4 rounded">
                                <span>💾 ذخیره خودکار در بانک آزمون</span>
                            </label>
                        </div>

                        <div class="flex items-center gap-1.5">
                            <button type="button" id="btn-crawler-start" onclick="startMultiPageCrawler()" class="bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-3.5 py-1.5 rounded-xl font-black text-xs shadow transition flex items-center gap-1">
                                <span>▶️ شروع استخراج کل بازه</span>
                            </button>
                            <button type="button" id="btn-crawler-stop" onclick="stopMultiPageCrawler()" class="hidden bg-rose-600 hover:bg-rose-500 text-white px-3 py-1.5 rounded-xl font-extrabold text-xs transition">
                                ⏹ توقف
                            </button>
                        </div>
                    </div>

                    <!-- وضعیت زنده و نوار پیشرفت خزنده چندصفحه‌ای -->
                    <div id="crawler-status-box" class="hidden space-y-1 pt-1 border-t border-slate-800">
                        <div class="flex items-center justify-between text-[11px]">
                            <span id="crawler-status-text" class="text-emerald-300 font-bold">در حال آماده‌سازی...</span>
                            <span id="crawler-progress-pct" class="font-mono font-bold text-amber-300">0%</span>
                        </div>
                        <div class="w-full h-2 bg-slate-800 rounded-full overflow-hidden">
                            <div id="crawler-progress-bar" class="h-full bg-emerald-500 transition-all duration-300" style="width: 0%;"></div>
                        </div>
                    </div>
                </div>

                <!-- نوار ابزار برش مستطیلی دستی با موس -->
                <div id="crop-actions-bar" class="hidden bg-amber-50 border-b border-amber-300 px-3.5 py-2 flex-wrap items-center justify-between gap-2 text-xs">
                    <span class="font-extrabold text-amber-950 text-[11px] flex items-center gap-1">
                        <span>✂️ ناحیه انتخاب شد:</span>
                    </span>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <button type="button" onclick="runAiQuestionExtraction(true)" class="bg-indigo-600 hover:bg-indigo-700 text-white px-2.5 py-1 rounded-lg font-extrabold text-[11px] shadow">
                            🤖 استخراج متن/شبه‌کد همین کادر (AI)
                        </button>
                        <button type="button" onclick="attachCropAsDiagramToEditor()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-2.5 py-1 rounded-lg font-extrabold text-[11px] shadow">
                            🖼️ قرار دادن به عنوان «شکلِ سوال»
                        </button>
                        <button type="button" onclick="createFullVisualQuestionFromCrop()" class="bg-amber-500 hover:bg-amber-600 text-slate-950 px-2.5 py-1 rounded-lg font-extrabold text-[11px] shadow">
                            ⚡ ثبت فوری به عنوان «سوال تمام‌تصویری»
                        </button>
                        <button type="button" onclick="clearCropSelection()" class="text-slate-500 hover:text-rose-600 font-bold px-1.5">لغو ✕</button>
                    </div>
                </div>

                <!-- بوم نمایش صفحه PDF یا عکس سوالات -->
                <div id="source-viewport" class="bg-slate-800 p-3 min-h-[420px] max-h-[72vh] overflow-auto custom-scroll flex items-start justify-center relative">
                    <div id="source-empty-placeholder" class="my-auto text-center text-slate-300 p-8 space-y-2">
                        <div class="text-4xl">📄</div>
                        <div class="font-bold text-xs">هنوز فایل PDF یا تصویر سوالات را انتخاب نکرده‌اید</div>
                        <div class="text-[11px] text-slate-400">از دکمه بالای همین کادر، فایل PDF آزمون را باز کنید تا استخراج خودکار صفحه به صفحه فعال شود.</div>
                    </div>

                    <div id="source-canvas-wrapper" dir="ltr" class="hidden shadow-2xl bg-white">
                        <canvas id="source-render-canvas" dir="ltr"></canvas>
                        <div id="source-crop-layer" dir="ltr">
                            <div id="crop-selection-rect"></div>
                        </div>
                    </div>
                </div>

                <!-- بخش پیش‌نمایش سوالات استخراج‌شده توسط هوش مصنوعی -->
                <div id="ai-staged-container" class="hidden border-t-2 border-indigo-500 bg-indigo-50 p-4 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-indigo-200 pb-2.5">
                        <div>
                            <h4 class="font-extrabold text-xs md:text-sm text-indigo-950 flex items-center gap-1.5">
                                <span>✨ پیش‌نمایش سوالات استخراج‌شده</span>
                                <span id="ai-staged-badge" class="bg-indigo-600 text-white px-2 py-0.5 rounded-full text-[10px]">0 سوال</span>
                            </h4>
                            <p class="text-[11px] text-slate-600 mt-0.5">شکل‌های شناسایی‌شده به صورت خودکار برش خورده‌اند و شبه‌کدها داخل بلوک ``` قرار گرفته‌اند:</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" onclick="saveAllStagedQuestions()" id="btn-save-all-staged" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl font-extrabold text-xs shadow transition">
                                ✅ تایید و افزودن همه به بانک آزمون
                            </button>
                            <button type="button" onclick="clearStagedQuestions()" class="bg-white border border-slate-300 text-slate-600 px-3 py-2 rounded-xl font-bold text-xs">
                                پاک کردن لیست
                            </button>
                        </div>
                    </div>

                    <div id="ai-staged-list" class="space-y-3 max-h-[520px] overflow-y-auto custom-scroll pr-1"></div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- ستون چپ (۵ واحد): فرم ثبت/ویرایش تکی سوال (با دکمه شبه‌کد) + بانک سوالات آزمون -->
            <!-- ========================================== -->
            <div class="lg:col-span-5 space-y-4">

                <!-- الف) فرم ویرایشگر دستی / تصویری / شبه‌کد -->
                <div class="bg-white rounded-2xl shadow border border-slate-200 overflow-hidden">
                    <div class="bg-slate-800 text-white px-4 py-2.5 flex items-center justify-between">
                        <h3 id="question-editor-title" class="font-extrabold text-xs md:text-sm">➕ افزودن / ویرایش سوال</h3>
                        <button type="button" onclick="resetQuestionEditor()" class="text-[11px] text-slate-300 hover:text-white">پاک کردن فرم</button>
                    </div>

                    <form id="question-editor-form" onsubmit="saveSingleQuestion(event)" class="p-4 space-y-3 text-xs">
                        <input type="hidden" id="qe-id" value="0">
                        <input type="hidden" id="qe-image-path" value="">

                        <div id="qe-image-preview-box" class="hidden bg-slate-50 p-2.5 rounded-xl border border-indigo-200 space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="font-extrabold text-indigo-900 text-[11px]">🖼️ تصویر پیوست / شکل سوال:</span>
                                <button type="button" onclick="removeEditorQuestionImage()" class="text-rose-600 hover:underline font-bold text-[11px]">✕ حذف تصویر</button>
                            </div>
                            <img id="qe-image-preview-el" src="" alt="Question Image" class="max-h-44 mx-auto rounded-lg border border-slate-300 bg-white p-1">
                        </div>

                        <div>
                            <div class="flex flex-wrap items-center justify-between gap-1 mb-1">
                                <label class="font-bold text-slate-700">صورت سوال (متن و شبه‌کد)</label>
                                <div class="flex items-center gap-2">
                                    <button type="button" onclick="insertPseudocodeBlockInEditor()" class="bg-slate-800 hover:bg-slate-700 text-emerald-300 px-2 py-0.5 rounded-lg text-[10px] font-bold">
                                        💻 + درج بلوک شبه‌کد / کد
                                    </button>
                                    <label class="text-[11px] text-indigo-600 hover:underline font-bold cursor-pointer">
                                        <span>📷 آپلود عکس</span>
                                        <input type="file" accept="image/*" onchange="handleManualQuestionImageUpload(event)" class="sr-only">
                                    </label>
                                </div>
                            </div>
                            <textarea id="qe-text" rows="4" placeholder="صورت سوال را بنویسید... (برای شبه‌کد یا الگوریتم، کد را بین ``` قرار دهید یا دکمه درج شبه‌کد بالا را بزنید)" class="w-full p-2.5 border border-slate-300 rounded-xl focus:outline-none leading-relaxed"></textarea>
                        </div>

                        <div class="space-y-2">
                            <label class="block font-bold text-slate-700">گزینه‌ها (دایره کنار گزینه صحیح را تیک بزنید):</label>
                            <?php for ($op = 1; $op <= 4; $op++): ?>
                            <div class="flex items-center gap-2">
                                <label class="flex items-center gap-1 cursor-pointer bg-emerald-50 border border-emerald-200 px-2.5 py-2 rounded-xl shrink-0">
                                    <input type="radio" name="qe_correct" value="<?php echo $op; ?>" <?php echo $op === 1 ? 'checked' : ''; ?> class="w-4 h-4">
                                    <span class="font-extrabold text-emerald-900 text-[11px]">گزینه <?php echo $op; ?></span>
                                </label>
                                <input type="text" id="qe-opt-<?php echo $op; ?>" required value="گزینه <?php echo $op; ?>" dir="auto" class="flex-1 px-3 py-2 border border-slate-300 rounded-xl focus:outline-none">
                            </div>
                            <?php endfor; ?>
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">پاسخ تشریحی و نکته آموزشی (اختیاری)</label>
                            <textarea id="qe-explanation" rows="2" placeholder="توضیح راه‌حل..." class="w-full p-2.5 border border-slate-300 rounded-xl focus:outline-none"></textarea>
                        </div>

                        <div class="flex justify-end gap-2 pt-2 border-t border-slate-200">
                            <button type="submit" id="btn-save-single-q" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-extrabold shadow transition">
                                💾 ذخیره سوال در آزمون
                            </button>
                        </div>
                    </form>
                </div>

                <!-- ب) لیست سوالات ثبت‌شده در این آزمون -->
                <div class="bg-white rounded-2xl shadow border border-slate-200 overflow-hidden">
                    <div class="bg-slate-50 border-b border-slate-200 px-4 py-3 flex items-center justify-between">
                        <h3 class="font-extrabold text-xs md:text-sm text-slate-800">
                            📚 بانک سوالات ثبت‌شده در این آزمون
                        </h3>
                        <span id="saved-questions-count-badge" class="bg-indigo-100 text-indigo-800 px-2.5 py-0.5 rounded-full text-xs font-extrabold">0 سوال</span>
                    </div>

                    <div id="saved-questions-list" class="p-3.5 space-y-3 max-h-[580px] overflow-y-auto custom-scroll text-xs"></div>
                </div>

            </div>

        </div>
    </main>

    <!-- ==================================================== -->
    <!-- ۳. تب دوم: داشبورد مانیتورینگ کلیدهای هوش مصنوعی و میزان مصرف -->
    <!-- ==================================================== -->
    <section id="admin-section-aikeys" class="hidden flex-1 p-3 md:p-6 max-w-6xl w-full mx-auto space-y-5">

        <div class="bg-slate-900 text-white rounded-3xl p-5 flex flex-wrap items-center justify-between gap-4 shadow-md">
            <div class="space-y-1">
                <h2 class="text-base md:text-lg font-black text-amber-300 flex items-center gap-2">
                    <span>🤖 مانیتورینگ استخر کلیدهای هوش مصنوعی (AI Keys Pool)</span>
                </h2>
                <p class="text-xs text-slate-300">
                    مشاهده تعداد کلیدهای تعریف‌شده در <code class="font-mono text-amber-200">config.php</code>، میزان مصرف در «مقاله‌خوان» و «آزمون‌ساز» و تست سلامت زنده هر کلید.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <button type="button" id="btn-ping-ai-keys" onclick="pingAllAiKeysHealth()" class="bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-4 py-2 rounded-xl font-black shadow transition flex items-center gap-1.5">
                    <span>⚡ تست سلامت و پینگ زنده کلیدها</span>
                </button>
                <button type="button" onclick="resetAllAiKeyStats()" class="bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 px-3.5 py-2 rounded-xl font-bold transition">
                    🧹 صفر کردن شمارنده‌ها
                </button>
            </div>
        </div>

        <!-- کارت‌های خلاصه مصرف کلیدها -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 text-center">
            <div class="bg-white p-3.5 rounded-2xl border border-slate-200 shadow-sm">
                <div class="text-[11px] text-slate-500 font-bold">🔑 تعداد کلیدهای فعال</div>
                <div id="aik-total-keys" class="text-2xl font-black text-indigo-600 mt-1">0</div>
            </div>
            <div class="bg-white p-3.5 rounded-2xl border border-amber-200 shadow-sm">
                <div class="text-[11px] text-amber-800 font-bold">📅 مصرف امروز</div>
                <div id="aik-today-calls" class="text-2xl font-black text-amber-600 mt-1">0</div>
            </div>
            <div class="bg-white p-3.5 rounded-2xl border border-slate-200 shadow-sm">
                <div class="text-[11px] text-slate-500 font-bold">📊 کل درخواست‌ها</div>
                <div id="aik-total-calls" class="text-2xl font-black text-slate-800 mt-1">0</div>
            </div>
            <div class="bg-white p-3.5 rounded-2xl border border-emerald-200 shadow-sm">
                <div class="text-[11px] text-emerald-700 font-bold">✅ پاسخ موفق (200)</div>
                <div id="aik-success-calls" class="text-2xl font-black text-emerald-600 mt-1">0</div>
            </div>
            <div class="bg-white p-3.5 rounded-2xl border border-indigo-200 shadow-sm">
                <div class="text-[11px] text-indigo-700 font-bold">🌐 سهم مقاله‌خوان</div>
                <div id="aik-translate-calls" class="text-2xl font-black text-indigo-700 mt-1">0</div>
            </div>
            <div class="bg-white p-3.5 rounded-2xl border border-slate-200 shadow-sm">
                <div class="text-[11px] text-purple-700 font-bold">📝 سهم آزمون‌ساز</div>
                <div id="aik-exam-calls" class="text-2xl font-black text-purple-700 mt-1">0</div>
            </div>
        </div>

        <!-- جدول تفکیکی هر کلید -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between text-xs">
                <span class="font-extrabold">📋 جزئیات مصرف و وضعیت تک‌تک کلیدهای موجود در استخر</span>
                <span id="aik-primary-model-badge" class="bg-indigo-600 px-2.5 py-0.5 rounded-lg font-mono text-[11px]"></span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-right text-xs">
                    <thead class="bg-slate-100 text-slate-600 border-b border-slate-200">
                        <tr>
                            <th class="p-3">#</th>
                            <th class="p-3">کلید (Mask شده)</th>
                            <th class="p-3">مصرف امروز</th>
                            <th class="p-3">کل مصرف (موفق / خطا)</th>
                            <th class="p-3">تفکیک (مقاله‌خوان / آزمون‌ساز)</th>
                            <th class="p-3">آخرین مدل و کد وضعیت</th>
                            <th class="p-3">نتیجه پینگ زنده</th>
                        </tr>
                    </thead>
                    <tbody id="ai-keys-table-tbody" class="divide-y divide-slate-200"></tbody>
                </table>
            </div>
        </div>

    </section>

    <!-- ==================================================== -->
    <!-- ۴. تب سوم: مدیریت کاربران و کدهای فعال‌سازی اشتراک ویژه -->
    <!-- ==================================================== -->
    <section id="admin-section-users" class="hidden flex-1 p-3 md:p-6 max-w-7xl w-full mx-auto space-y-6">

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3.5">
            <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                <div class="text-xs text-slate-500 font-bold">👥 کل کاربران ثبت‌نامی</div>
                <div class="text-2xl font-black text-slate-800 mt-1"><?php echo count($users_list); ?> نفر</div>
            </div>
            <div class="bg-white p-4 rounded-2xl border border-emerald-200 shadow-sm">
                <div class="text-xs text-emerald-700 font-bold">👑 مشترکین ویژه فعال</div>
                <div class="text-2xl font-black text-emerald-600 mt-1"><?php echo $active_vips_count; ?> نفر</div>
            </div>
            <div class="bg-white p-4 rounded-2xl border border-indigo-200 shadow-sm">
                <div class="text-xs text-indigo-700 font-bold">🎟️ کدهای اشتراک آماده فروش</div>
                <div class="text-2xl font-black text-indigo-600 mt-1">
                    <?php echo count(array_filter($codes_list, function($c) { return (int)$c['is_used'] === 0; })); ?> کد
                </div>
            </div>
            <div class="bg-white p-4 rounded-2xl border border-amber-200 shadow-sm">
                <div class="text-xs text-amber-700 font-bold">📚 کل فایل‌های PDF آپلودشده</div>
                <div class="text-2xl font-black text-amber-600 mt-1">
                    <?php echo array_sum(array_column($users_list, 'pdf_count')); ?> فایل
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="bg-slate-800 text-white px-4 py-3 font-extrabold text-xs md:text-sm">
                    🎟️ تولید کد فعال‌سازی اشتراک ویژه (VIP)
                </div>
                <div class="p-4 space-y-4 text-xs">
                    <form onsubmit="handleGenerateCodes(event)" class="space-y-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">نوع پلن اشتراک:</label>
                            <select id="gen-plan-type" class="w-full px-3 py-2 border border-slate-300 rounded-xl bg-white font-bold">
                                <option value="1m">💎 اشتراک ۱ ماهه (۳۰ روز کامل - VIP30)</option>
                                <option value="3m">👑 اشتراک ۳ ماهه (۹۰ روز کامل - VIP90)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">تعداد کد جهت تولید:</label>
                            <input type="number" id="gen-code-count" min="1" max="50" value="5" class="w-full px-3 py-2 border border-slate-300 rounded-xl text-center font-bold">
                        </div>
                        <button type="submit" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-extrabold shadow transition">
                            ⚡ تولید کدهای جدید
                        </button>
                    </form>

                    <div class="border-t border-slate-200 pt-3">
                        <div class="font-extrabold text-slate-700 mb-2">لیست کدهای فعال‌سازی:</div>
                        <div class="max-h-80 overflow-y-auto space-y-1.5 custom-scroll">
                            <?php foreach ($codes_list as $cd): ?>
                            <div class="p-2 rounded-xl border <?php echo (int)$cd['is_used'] === 1 ? 'bg-slate-50 border-slate-200 opacity-60' : 'bg-emerald-50 border-emerald-200'; ?> flex items-center justify-between gap-2">
                                <div>
                                    <span onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($cd['code']); ?>');alert('کد کپی شد');" class="font-mono font-black text-xs text-slate-900 cursor-pointer"><?php echo htmlspecialchars($cd['code']); ?></span>
                                    <div class="text-[10px] text-slate-500">
                                        <?php echo (int)$cd['days']; ?> روزه
                                        <?php if ((int)$cd['is_used'] === 1): ?>
                                            • استفاده شده توسط: <?php echo htmlspecialchars($cd['used_by_name'] ?: $cd['used_by_username']); ?>
                                        <?php else: ?>
                                            • <span class="text-emerald-700 font-bold">آزاد</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ((int)$cd['is_used'] === 0): ?>
                                <div class="flex items-center gap-1">
                                    <button type="button" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($cd['code']); ?>');alert('کد کپی شد');" class="px-2 py-1 bg-white border border-slate-300 rounded-lg text-[10px] font-bold">📋 کپی</button>
                                    <button type="button" onclick="deletePromoCode(<?php echo (int)$cd['id']; ?>)" class="text-rose-500 px-1">🗑️</button>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between">
                    <span class="font-extrabold text-xs md:text-sm">👥 لیست کاربران و شارژ مستقیم اشتراک</span>
                    <input type="text" id="admin-user-search" oninput="filterAdminUsersTable()" placeholder="🔎 جستجوی نام، نام کاربری یا موبایل..." class="px-3 py-1 rounded-xl bg-slate-700 text-white text-xs placeholder-slate-400 focus:outline-none">
                </div>

                <div class="overflow-x-auto max-h-[560px] custom-scroll">
                    <table class="w-full text-right text-xs">
                        <thead class="bg-slate-100 text-slate-600 border-b border-slate-200 sticky top-0">
                            <tr>
                                <th class="p-3">کاربر</th>
                                <th class="p-3">موبایل</th>
                                <th class="p-3">وضعیت اشتراک</th>
                                <th class="p-3">فایل‌ها / برنامه‌ها</th>
                                <th class="p-3">شارژ سریع اشتراک (VIP)</th>
                            </tr>
                        </thead>
                        <tbody id="admin-users-tbody" class="divide-y divide-slate-200">
                            <?php foreach ($users_list as $u):
                                $exp_ts = !empty($u['vip_expires_at']) ? strtotime($u['vip_expires_at']) : 0;
                                $is_u_vip = ($exp_ts > time());
                                $days_rem = $is_u_vip ? (int)ceil(($exp_ts - time()) / 86400) : 0;
                            ?>
                            <tr class="admin-user-row hover:bg-slate-50">
                                <td class="p-3">
                                    <div class="font-extrabold text-slate-800"><?php echo htmlspecialchars($u['full_name']); ?></div>
                                    <div class="text-[11px] text-slate-400 font-mono">@<?php echo htmlspecialchars($u['username']); ?></div>
                                </td>
                                <td class="p-3 font-mono text-slate-600" dir="ltr"><?php echo htmlspecialchars($u['phone'] ?: '---'); ?></td>
                                <td class="p-3">
                                    <?php if ($is_u_vip): ?>
                                        <span class="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-full font-extrabold text-[11px]">👑 ویژه (<?php echo $days_rem; ?> روز مانده)</span>
                                    <?php else: ?>
                                        <span class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full font-bold text-[11px]">عادی (رایگان)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 text-[11px] text-slate-600">
                                    📚 <?php echo (int)$u['pdf_count']; ?> فایل | 📅 <?php echo (int)$u['event_count']; ?> برنامه
                                </td>
                                <td class="p-3">
                                    <div class="flex flex-wrap items-center gap-1">
                                        <button type="button" onclick="chargeUserVip(<?php echo (int)$u['id']; ?>, 30)" class="px-2 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-bold text-[10px]">+۳۰ روز</button>
                                        <button type="button" onclick="chargeUserVip(<?php echo (int)$u['id']; ?>, 90)" class="px-2 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-bold text-[10px]">+۹۰ روز</button>
                                        <?php if ($is_u_vip): ?>
                                        <button type="button" onclick="chargeUserVip(<?php echo (int)$u['id']; ?>, 0)" class="px-2 py-1 bg-rose-100 hover:bg-rose-200 text-rose-700 rounded-lg font-bold text-[10px]">لغو VIP</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <!-- ==================================================== -->
    <!-- ۵. مودال ساخت یا ویرایش شناسنامه آزمون -->
    <!-- ==================================================== -->
    <div id="exam-meta-modal" class="fixed inset-0 bg-slate-900 bg-opacity-70 hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden text-xs">
            <div class="bg-slate-900 text-white px-4 py-3 flex items-center justify-between">
                <h3 id="exam-meta-modal-title" class="font-extrabold text-sm">➕ ساخت آزمون جدید</h3>
                <button type="button" onclick="closeExamMetaModal()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <form id="exam-meta-form" onsubmit="saveExamMeta(event)" class="p-4 space-y-3.5">
                <input type="hidden" id="em-id" value="0">

                <div>
                    <label class="block font-bold text-slate-700 mb-1">عنوان آزمون *</label>
                    <input type="text" id="em-title" required placeholder="مثلاً: آزمون جامع فصل ۱ و ۲" class="w-full px-3 py-2 border border-slate-300 rounded-xl focus:outline-none">
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">درس / دسته‌بندی</label>
                        <input type="text" id="em-category" value="زیست‌شناسی" placeholder="مثلاً: زیست، الگوریتم، ریاضی..." class="w-full px-3 py-2 border border-slate-300 rounded-xl">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">زمان آزمون (دقیقه) *</label>
                        <input type="number" id="em-duration" min="1" max="300" value="25" required class="w-full px-3 py-2 border border-slate-300 rounded-xl text-center font-extrabold">
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">توضیحات یا بودجه‌بندی آزمون (اختیاری)</label>
                    <textarea id="em-description" rows="2" placeholder="توضیحات کوتاه برای نمایش به دانشجو قبل از شروع آزمون..." class="w-full px-3 py-2 border border-slate-300 rounded-xl"></textarea>
                </div>

                <div class="bg-slate-50 p-3 rounded-xl border border-slate-200 space-y-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="em-negative" checked class="w-4 h-4 rounded">
                        <span class="font-bold text-slate-700">محاسبه درصد با نمره منفی استاندارد کنکور (هر ۳ غلط یک صحیح را حذف کند)</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="em-vip-only" class="w-4 h-4 rounded">
                        <span class="font-bold text-slate-700">👑 آزمون اختصاصی مشترکین ویژه (VIP) باشد</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="em-active" checked class="w-4 h-4 rounded">
                        <span class="font-bold text-slate-700">✅ منتشر و فعال در صفحه آزمون‌های سایت</span>
                    </label>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t">
                    <button type="button" onclick="closeExamMetaModal()" class="px-3.5 py-2 bg-slate-100 rounded-xl font-bold">انصراف</button>
                    <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-extrabold shadow">ذخیره آزمون</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==================================================== -->
    <!-- ۶. مودال ورود سریع کلید پاسخنامه -->
    <!-- ==================================================== -->
    <div id="bulk-key-modal" class="fixed inset-0 bg-slate-900 bg-opacity-70 hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden text-xs">
            <div class="bg-slate-900 text-white px-4 py-3 flex items-center justify-between">
                <h3 class="font-extrabold text-sm">🔑 ثبت یکجای کلید پاسخنامه سوالات</h3>
                <button type="button" onclick="closeBulkKeyModal()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <form onsubmit="handleBulkUpdateAnswerKey(event)" class="p-4 space-y-3.5">
                <p class="text-slate-600 leading-relaxed">
                    اگر کلید سوالات در صفحه آخر فایل PDF قرار دارد، می‌توانید شماره‌های گزینه‌های صحیح را یکجا وارد کنید تا گزینه‌های صحیح سوالات آزمون به ترتیب آپدیت شوند:
                </p>
                <div class="bg-amber-50 border border-amber-200 text-amber-900 p-2.5 rounded-xl text-[11px] space-y-1">
                    <div><b>فرمت ۱ (متوالی از سوال ۱ به بعد):</b> <code dir="ltr" class="font-mono">3 1 4 2 2 1 3</code></div>
                    <div><b>فرمت ۲ (شماره سوال - گزینه):</b> <code dir="ltr" class="font-mono">1-3, 2-1, 3-4, 4-2</code></div>
                </div>
                <textarea id="bulk-key-textarea" rows="4" required dir="ltr" placeholder="1-3, 2-1, 3-4, 4-2..." class="w-full p-3 border border-slate-300 rounded-xl font-mono text-left focus:outline-none"></textarea>
                <div class="flex justify-end gap-2 pt-2 border-t">
                    <button type="button" onclick="closeBulkKeyModal()" class="px-3.5 py-2 bg-slate-100 rounded-xl font-bold">انصراف</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-extrabold">اعمال کلیدها روی سوالات</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const PDFJS_CDN_LIST = [
            {
                lib: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js',
                worker: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js',
                cmaps: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/cmaps/',
                fonts: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/standard_fonts/'
            },
            {
                lib: 'https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.min.js',
                worker: 'https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.worker.min.js',
                cmaps: 'https://unpkg.com/pdfjs-dist@3.11.174/cmaps/',
                fonts: 'https://unpkg.com/pdfjs-dist@3.11.174/standard_fonts/'
            }
        ];
        let activeCdnConfig = PDFJS_CDN_LIST[0];

        if (typeof window.pdfjsLib !== 'undefined') {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = activeCdnConfig.worker;
        }

        function switchAdminTab(tab) {
            const exSec = document.getElementById('admin-section-exams');
            const aiSec = document.getElementById('admin-section-aikeys');
            const usSec = document.getElementById('admin-section-users');

            const btnEx = document.getElementById('adm-tab-exams');
            const btnAi = document.getElementById('adm-tab-aikeys');
            const btnUs = document.getElementById('adm-tab-users');

            exSec.classList.add('hidden');
            aiSec.classList.add('hidden');
            usSec.classList.add('hidden');

            const inactiveCls = 'px-3 py-1.5 rounded-lg font-bold text-slate-300 hover:text-white transition flex items-center gap-1.5';
            const activeCls   = 'px-3 py-1.5 rounded-lg font-extrabold bg-indigo-600 text-white transition flex items-center gap-1.5';

            btnEx.className = (tab === 'exams')  ? activeCls : inactiveCls;
            btnAi.className = (tab === 'aikeys') ? activeCls : inactiveCls;
            btnUs.className = (tab === 'users')  ? activeCls : inactiveCls;

            if (tab === 'exams') {
                exSec.classList.remove('hidden');
            } else if (tab === 'aikeys') {
                aiSec.classList.remove('hidden');
                fetchAiKeysStatus();
            } else {
                usSec.classList.remove('hidden');
            }
        }

        function formatRichQuestionHtml(rawText) {
            if (!rawText) return '';
            let safe = escapeHtml(rawText);

            safe = safe.replace(/```(?:[a-zA-Z0-9_+-]*\n)?([\s\S]*?)```/g, function(_, codeContent) {
                return `<pre dir="ltr" class="bg-slate-900 text-emerald-300 font-mono text-xs p-3 rounded-xl my-2 overflow-x-auto text-left leading-relaxed border border-slate-700">${codeContent.trim()}</pre>`;
            });

            safe = safe.replace(/`([^`\n]+)`/g, '<code dir="ltr" class="bg-slate-200 text-indigo-900 font-mono px-1.5 py-0.5 rounded text-[11px]">$1</code>');

            return safe;
        }

        // ====================================================
        // ۱. داشبورد مانیتورینگ کلیدهای هوش مصنوعی
        // ====================================================
        let lastPingResultsByIndex = {};

        async function fetchAiKeysStatus() {
            try {
                const res = await fetch('api_exam.php?action=admin_ai_keys_status');
                const data = await res.json();
                if (data.status !== 'success') return;

                const s = data.summary || {};
                document.getElementById('header-ai-keys-badge').textContent = s.keys_count || 0;
                document.getElementById('studio-ai-summary-pill').textContent = `${s.keys_count || 0} کلید فعال | امروز: ${s.today_calls || 0} درخواست`;

                document.getElementById('aik-total-keys').textContent      = s.keys_count || 0;
                document.getElementById('aik-today-calls').textContent     = s.today_calls || 0;
                document.getElementById('aik-total-calls').textContent     = s.total_calls || 0;
                document.getElementById('aik-success-calls').textContent   = s.success_calls || 0;
                document.getElementById('aik-translate-calls').textContent = s.translate_calls || 0;
                document.getElementById('aik-exam-calls').textContent      = s.exam_calls || 0;
                document.getElementById('aik-primary-model-badge').textContent = `مدل پایه: ${data.primary_model || 'gemini-3.7-flash'}`;

                const tbody = document.getElementById('ai-keys-table-tbody');
                const keys  = data.keys || [];

                if (keys.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="7" class="p-6 text-center text-slate-400">هیچ کلید فعالی در آرایه AI_API_KEYS داخل فایل config.php یافت نشد.</td></tr>`;
                    return;
                }

                tbody.innerHTML = keys.map(k => {
                    const ping = lastPingResultsByIndex[k.index];
                    let pingHtml = `<span class="text-slate-400 text-[11px]">تست نشده</span>`;
                    if (ping) {
                        pingHtml = ping.ok
                            ? `<span class="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-lg font-extrabold text-[11px]">🟢 سالم (${ping.latency_ms}ms)</span>`
                            : `<span class="bg-rose-100 text-rose-800 px-2 py-0.5 rounded-lg font-bold text-[11px]" title="${escapeHtml(ping.error)}">🔴 خطا (${ping.http_code})</span>`;
                    }

                    const codeBadge = k.last_http_code
                        ? `<span class="px-1.5 py-0.5 rounded text-[10px] font-mono font-bold ${k.last_http_code === 200 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}">HTTP ${k.last_http_code}</span>`
                        : '';

                    return `
                        <tr class="hover:bg-slate-50">
                            <td class="p-3 font-black text-slate-700">#${k.index}</td>
                            <td class="p-3 font-mono font-bold text-indigo-700" dir="ltr">${escapeHtml(k.key_masked)}</td>
                            <td class="p-3"><span class="bg-amber-100 text-amber-900 px-2.5 py-0.5 rounded-full font-black">${k.today_calls}</span></td>
                            <td class="p-3">
                                <span class="font-extrabold text-slate-800">${k.total_calls}</span>
                                <span class="text-[11px] text-slate-400 mr-1">(✅${k.success_calls} / ❌${k.error_calls})</span>
                            </td>
                            <td class="p-3 text-[11px]">
                                <span class="text-indigo-700 font-bold">🌐 مقاله: ${k.translate_calls}</span> |
                                <span class="text-purple-700 font-bold">📝 آزمون: ${k.exam_calls}</span>
                            </td>
                            <td class="p-3">
                                <span class="font-mono text-[11px] text-slate-700">${escapeHtml(k.last_model)}</span>
                                ${codeBadge}
                                ${k.last_error ? `<div class="text-[10px] text-rose-500 truncate" style="max-width:210px;" title="${escapeHtml(k.last_error)}">${escapeHtml(k.last_error)}</div>` : ''}
                            </td>
                            <td class="p-3">${pingHtml}</td>
                        </tr>
                    `;
                }).join('');
            } catch (e) {
                console.error('Error fetching AI keys status:', e);
            }
        }

        async function pingAllAiKeysHealth() {
            const btn = document.getElementById('btn-ping-ai-keys');
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span>⏳ در حال پینگ تک‌تک کلیدها...</span>';

            try {
                const res = await fetch('api_exam.php?action=admin_ping_ai_keys');
                const data = await res.json();
                if (data.status === 'success' && Array.isArray(data.results)) {
                    data.results.forEach(r => {
                        lastPingResultsByIndex[r.index] = r;
                    });
                    await fetchAiKeysStatus();
                }
            } catch (e) {
                alert('خطا در تست سلامت کلیدها.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        }

        async function resetAllAiKeyStats() {
            if (!confirm('آیا تمام شمارنده‌های مصرف کلیدهای هوش مصنوعی صفر شوند؟')) return;
            await fetch('api_exam.php?action=admin_reset_ai_stats');
            lastPingResultsByIndex = {};
            fetchAiKeysStatus();
        }

        // ====================================================
        // ۲. مدیریت آزمون‌ها و بانک سوالات در استودیو
        // ====================================================
        let adminExamsList    = [];
        let activeExamObj     = null;
        let savedQuestions    = [];
        let aiStagedQuestions = [];

        async function fetchAdminExams(selectExamId = null) {
            try {
                const res = await fetch('api_exam.php?action=admin_list_exams');
                const data = await res.json();
                if (data.status === 'success') {
                    adminExamsList = data.exams || [];
                    const selectEl = document.getElementById('studio-exam-select');

                    if (adminExamsList.length === 0) {
                        selectEl.innerHTML = '<option value="">-- هنوز آزمونی نساخته‌اید --</option>';
                        document.getElementById('studio-empty-state').classList.remove('hidden');
                        document.getElementById('studio-workspace').classList.add('hidden');
                        document.getElementById('btn-edit-exam-meta').classList.add('hidden');
                        document.getElementById('btn-bulk-answer-key').classList.add('hidden');
                        document.getElementById('btn-delete-exam').classList.add('hidden');
                        return;
                    }

                    let html = '<option value="">-- یک آزمون را انتخاب کنید --</option>';
                    adminExamsList.forEach(ex => {
                        const statusIcon = Number(ex.is_active) === 1 ? '🟢' : '⚪';
                        const vipIcon    = Number(ex.is_vip_only) === 1 ? '👑 ' : '';
                        html += `<option value="${ex.id}">${statusIcon} ${vipIcon}${escapeHtml(ex.title)} (${ex.question_count} سوال | ${ex.duration_mins} دقیقه)</option>`;
                    });
                    selectEl.innerHTML = html;

                    const targetId = selectExamId || (activeExamObj ? activeExamObj.id : adminExamsList[0].id);
                    if (targetId) {
                        selectEl.value = String(targetId);
                        await onSelectStudioExam(targetId);
                    }
                }
            } catch (e) {
                console.error('Error loading exams:', e);
            }
        }

        async function onSelectStudioExam(examId) {
            if (!examId) {
                activeExamObj = null;
                document.getElementById('studio-empty-state').classList.remove('hidden');
                document.getElementById('studio-workspace').classList.add('hidden');
                document.getElementById('btn-edit-exam-meta').classList.add('hidden');
                document.getElementById('btn-bulk-answer-key').classList.add('hidden');
                document.getElementById('btn-delete-exam').classList.add('hidden');
                return;
            }

            const res = await fetch(`api_exam.php?action=admin_get_exam&exam_id=${examId}`);
            const data = await res.json();
            if (data.status === 'success') {
                activeExamObj  = data.exam;
                savedQuestions = data.questions || [];

                document.getElementById('studio-empty-state').classList.add('hidden');
                document.getElementById('studio-workspace').classList.remove('hidden');
                document.getElementById('btn-edit-exam-meta').classList.remove('hidden');
                document.getElementById('btn-bulk-answer-key').classList.remove('hidden');
                document.getElementById('btn-delete-exam').classList.remove('hidden');

                renderSavedQuestionsBank();
            }
        }

        function openExamMetaModal(isNew = true) {
            const form = document.getElementById('exam-meta-form');
            form.reset();

            if (isNew || !activeExamObj) {
                document.getElementById('exam-meta-modal-title').textContent = '➕ ساخت آزمون جدید';
                document.getElementById('em-id').value = '0';
                document.getElementById('em-negative').checked = true;
                document.getElementById('em-vip-only').checked = false;
                document.getElementById('em-active').checked   = true;
            } else {
                document.getElementById('exam-meta-modal-title').textContent = '⚙️ ویرایش مشخصات آزمون';
                document.getElementById('em-id').value          = activeExamObj.id;
                document.getElementById('em-title').value       = activeExamObj.title;
                document.getElementById('em-category').value    = activeExamObj.category || 'عمومی';
                document.getElementById('em-duration').value    = activeExamObj.duration_mins || 30;
                document.getElementById('em-description').value = activeExamObj.description || '';
                document.getElementById('em-negative').checked  = Number(activeExamObj.negative_marking) === 1;
                document.getElementById('em-vip-only').checked  = Number(activeExamObj.is_vip_only) === 1;
                document.getElementById('em-active').checked    = Number(activeExamObj.is_active) === 1;
            }

            const m = document.getElementById('exam-meta-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeExamMetaModal() {
            const m = document.getElementById('exam-meta-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        async function saveExamMeta(e) {
            e.preventDefault();
            const fd = new FormData();
            fd.append('action', 'save_exam');
            fd.append('id', document.getElementById('em-id').value);
            fd.append('title', document.getElementById('em-title').value);
            fd.append('category', document.getElementById('em-category').value);
            fd.append('duration_mins', document.getElementById('em-duration').value);
            fd.append('description', document.getElementById('em-description').value);
            fd.append('negative_marking', document.getElementById('em-negative').checked ? '1' : '0');
            fd.append('is_vip_only', document.getElementById('em-vip-only').checked ? '1' : '0');
            fd.append('is_active', document.getElementById('em-active').checked ? '1' : '0');

            const res = await fetch('api_exam.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                closeExamMetaModal();
                await fetchAdminExams(data.exam_id);
            } else {
                alert(data.message || 'خطا در ذخیره آزمون');
            }
        }

        async function deleteActiveExam() {
            if (!activeExamObj || !confirm(`آیا از حذف کامل آزمون «${activeExamObj.title}» و تمام سوالات آن مطمئن هستید؟`)) return;
            const fd = new FormData();
            fd.append('action', 'delete_exam');
            fd.append('exam_id', activeExamObj.id);
            await fetch('api_exam.php', { method: 'POST', body: fd });
            activeExamObj = null;
            fetchAdminExams();
        }

        function openBulkKeyModal() {
            if (!activeExamObj) return;
            document.getElementById('bulk-key-textarea').value = '';
            const m = document.getElementById('bulk-key-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeBulkKeyModal() {
            const m = document.getElementById('bulk-key-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        async function handleBulkUpdateAnswerKey(e) {
            e.preventDefault();
            if (!activeExamObj) return;
            const keyStr = document.getElementById('bulk-key-textarea').value.trim();
            if (!keyStr) return;

            const fd = new FormData();
            fd.append('action', 'bulk_update_answer_key');
            fd.append('exam_id', activeExamObj.id);
            fd.append('answer_key_str', keyStr);

            const res = await fetch('api_exam.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message);
            if (data.status === 'success') {
                closeBulkKeyModal();
                await fetchAdminExams(activeExamObj.id);
            }
        }

        // ====================================================
        // ۳. بارگذاری فایل PDF یا عکس سوالات + ابزار برش خودکار و دستی
        // ====================================================
        let studioPdfDoc     = null;
        let studioPdfPageNum = 1;
        let cropStartPos     = null;
        let activeCropBox    = null;

        async function handleStudioSourceFile(e) {
            const file = e.target.files[0];
            if (!file) return;

            stopMultiPageCrawler();
            clearCropSelection();
            document.getElementById('source-empty-placeholder').classList.add('hidden');
            document.getElementById('source-canvas-wrapper').classList.remove('hidden');
            document.getElementById('source-toolbar').classList.remove('hidden');
            document.getElementById('source-toolbar').classList.add('flex');

            if (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')) {
                document.getElementById('source-pdf-pagination').classList.remove('hidden');
                document.getElementById('source-pdf-pagination').classList.add('flex');
                document.getElementById('multi-page-crawler-bar').classList.remove('hidden');

                const arrayBuffer = await file.arrayBuffer();
                const pdfBytes = new Uint8Array(arrayBuffer);

                if (studioPdfDoc && typeof studioPdfDoc.destroy === 'function') {
                    await studioPdfDoc.destroy();
                }

                studioPdfDoc = await window.pdfjsLib.getDocument({
                    data: pdfBytes,
                    cMapUrl: activeCdnConfig.cmaps,
                    cMapPacked: true,
                    standardFontDataUrl: activeCdnConfig.fonts
                }).promise;

                studioPdfPageNum = 1;
                const totalP = studioPdfDoc.numPages;
                document.getElementById('source-total-pages').textContent = totalP;
                document.getElementById('source-page-input').max = totalP;
                document.getElementById('crawler-start-page').value = 1;
                document.getElementById('crawler-start-page').max   = totalP;
                document.getElementById('crawler-end-page').value   = totalP;
                document.getElementById('crawler-end-page').max     = totalP;

                await renderStudioPdfPage(1);
            } else {
                studioPdfDoc = null;
                document.getElementById('source-pdf-pagination').classList.add('hidden');
                document.getElementById('multi-page-crawler-bar').classList.add('hidden');

                const reader = new FileReader();
                reader.onload = (ev) => {
                    const img = new Image();
                    img.onload = () => {
                        const canvas = document.getElementById('source-render-canvas');
                        const ctx    = canvas.getContext('2d');
                        canvas.width  = img.width;
                        canvas.height = img.height;
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, canvas.width, canvas.height);
                        ctx.drawImage(img, 0, 0);
                    };
                    img.src = ev.target.result;
                };
                reader.readAsDataURL(file);
            }
        }

        async function renderStudioPdfPage(pageNum) {
            if (!studioPdfDoc) return;
            clearCropSelection();

            const page = await studioPdfDoc.getPage(pageNum);
            const canvas = document.getElementById('source-render-canvas');
            const ctx    = canvas.getContext('2d');

            const viewport = page.getViewport({ scale: 2.0 });
            canvas.width  = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);

            ctx.save();
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.restore();

            ctx.direction = 'ltr';
            ctx.textAlign = 'left';

            await page.render({ canvasContext: ctx, viewport }).promise;
            document.getElementById('source-page-input').value = pageNum;
        }

        function changeSourcePdfPage(delta) {
            if (!studioPdfDoc) return;
            const next = studioPdfPageNum + delta;
            if (next >= 1 && next <= studioPdfDoc.numPages) {
                studioPdfPageNum = next;
                renderStudioPdfPage(studioPdfPageNum);
            }
        }

        function jumpSourcePdfPage(val) {
            if (!studioPdfDoc) return;
            studioPdfPageNum = Math.min(studioPdfDoc.numPages, Math.max(1, parseInt(val) || 1));
            renderStudioPdfPage(studioPdfPageNum);
        }

        (function initSourceCropInteractions() {
            const cropLayer = document.getElementById('source-crop-layer');
            const cropRect  = document.getElementById('crop-selection-rect');
            let isDraggingCrop = false;

            function getRelPos(e) {
                const r = cropLayer.getBoundingClientRect();
                const cx = (e.touches && e.touches.length > 0) ? e.touches[0].clientX : e.clientX;
                const cy = (e.touches && e.touches.length > 0) ? e.touches[0].clientY : e.clientY;
                return {
                    x: Math.max(0, Math.min(r.width, cx - r.left)),
                    y: Math.max(0, Math.min(r.height, cy - r.top)),
                    layerW: r.width,
                    layerH: r.height
                };
            }

            function startCrop(e) {
                if (e.button !== undefined && e.button !== 0) return;
                if (e.cancelable) e.preventDefault();
                isDraggingCrop = true;
                cropStartPos = getRelPos(e);
                cropRect.style.display = 'block';
                cropRect.style.left   = cropStartPos.x + 'px';
                cropRect.style.top    = cropStartPos.y + 'px';
                cropRect.style.width  = '0px';
                cropRect.style.height = '0px';
            }

            function moveCrop(e) {
                if (!isDraggingCrop || !cropStartPos) return;
                if (e.cancelable) e.preventDefault();
                const cur = getRelPos(e);
                const x = Math.min(cropStartPos.x, cur.x);
                const y = Math.min(cropStartPos.y, cur.y);
                const w = Math.abs(cur.x - cropStartPos.x);
                const h = Math.abs(cur.y - cropStartPos.y);

                cropRect.style.left   = x + 'px';
                cropRect.style.top    = y + 'px';
                cropRect.style.width  = w + 'px';
                cropRect.style.height = h + 'px';

                activeCropBox = { x, y, w, h, layerW: cur.layerW, layerH: cur.layerH };
            }

            function endCrop() {
                if (!isDraggingCrop) return;
                isDraggingCrop = false;
                if (!activeCropBox || activeCropBox.w < 18 || activeCropBox.h < 18) {
                    clearCropSelection();
                } else {
                    document.getElementById('crop-actions-bar').classList.remove('hidden');
                    document.getElementById('crop-actions-bar').classList.add('flex');
                }
            }

            cropLayer.addEventListener('mousedown', startCrop);
            window.addEventListener('mousemove', moveCrop);
            window.addEventListener('mouseup', endCrop);

            cropLayer.addEventListener('touchstart', startCrop, { passive: false });
            window.addEventListener('touchmove', moveCrop, { passive: false });
            window.addEventListener('touchend', endCrop);
        })();

        function clearCropSelection() {
            activeCropBox = null;
            const cropRect = document.getElementById('crop-selection-rect');
            if (cropRect) cropRect.style.display = 'none';
            const bar = document.getElementById('crop-actions-bar');
            if (bar) {
                bar.classList.add('hidden');
                bar.classList.remove('flex');
            }
        }

        function getCanvasDataUrl(onlyCropped = false) {
            const srcCanvas = document.getElementById('source-render-canvas');
            if (!onlyCropped || !activeCropBox || activeCropBox.w < 15) {
                return srcCanvas.toDataURL('image/jpeg', 0.88);
            }

            const scaleX = srcCanvas.width / Math.max(1, activeCropBox.layerW);
            const scaleY = srcCanvas.height / Math.max(1, activeCropBox.layerH);

            const sx = Math.max(0, Math.floor(activeCropBox.x * scaleX));
            const sy = Math.max(0, Math.floor(activeCropBox.y * scaleY));
            const sw = Math.min(srcCanvas.width - sx, Math.floor(activeCropBox.w * scaleX));
            const sh = Math.min(srcCanvas.height - sy, Math.floor(activeCropBox.h * scaleY));

            const tempCanvas = document.createElement('canvas');
            tempCanvas.width  = sw;
            tempCanvas.height = sh;
            const tCtx = tempCanvas.getContext('2d');
            tCtx.fillStyle = '#ffffff';
            tCtx.fillRect(0, 0, sw, sh);
            tCtx.drawImage(srcCanvas, sx, sy, sw, sh, 0, 0, sw, sh);

            return tempCanvas.toDataURL('image/png');
        }

        async function autoCropFigureBoxFromCanvas(figureBox) {
            if (!Array.isArray(figureBox) || figureBox.length !== 4) return '';
            const srcCanvas = document.getElementById('source-render-canvas');
            if (!srcCanvas || srcCanvas.width < 50) return '';

            const pad  = 10;
            const ymin = Math.max(0, Number(figureBox[0]) - pad);
            const xmin = Math.max(0, Number(figureBox[1]) - pad);
            const ymax = Math.min(1000, Number(figureBox[2]) + pad);
            const xmax = Math.min(1000, Number(figureBox[3]) + pad);

            const sx = Math.floor((xmin / 1000) * srcCanvas.width);
            const sy = Math.floor((ymin / 1000) * srcCanvas.height);
            const sw = Math.floor(((xmax - xmin) / 1000) * srcCanvas.width);
            const sh = Math.floor(((ymax - ymin) / 1000) * srcCanvas.height);

            if (sw < 24 || sh < 24) return '';

            const tempCanvas = document.createElement('canvas');
            tempCanvas.width  = sw;
            tempCanvas.height = sh;
            const tCtx = tempCanvas.getContext('2d');
            tCtx.fillStyle = '#ffffff';
            tCtx.fillRect(0, 0, sw, sh);
            tCtx.drawImage(srcCanvas, sx, sy, sw, sh, 0, 0, sw, sh);

            const base64 = tempCanvas.toDataURL('image/png');
            const fd = new FormData();
            fd.append('action', 'upload_question_image');
            fd.append('image_base64', base64);

            const res = await fetch('api_exam.php', { method: 'POST', body: fd });
            const data = await res.json();
            return data.status === 'success' ? (data.image_path || '') : '';
        }

        async function uploadCurrentCropToServer() {
            const base64 = getCanvasDataUrl(true);
            const fd = new FormData();
            fd.append('action', 'upload_question_image');
            fd.append('image_base64', base64);

            const res = await fetch('api_exam.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                return data.image_path;
            }
            throw new Error(data.message || 'خطا در آپلود تصویر برش‌خورده');
        }

        async function attachCropAsDiagramToEditor() {
            try {
                const imgPath = await uploadCurrentCropToServer();
                setEditorQuestionImage(imgPath);
                clearCropSelection();
                document.getElementById('question-editor-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
            } catch (e) {
                alert(e.message);
            }
        }

        async function createFullVisualQuestionFromCrop() {
            try {
                const imgPath = await uploadCurrentCropToServer();
                resetQuestionEditor();
                setEditorQuestionImage(imgPath);
                document.getElementById('qe-text').value  = '';
                document.getElementById('qe-opt-1').value = 'گزینه ۱';
                document.getElementById('qe-opt-2').value = 'گزینه ۲';
                document.getElementById('qe-opt-3').value = 'گزینه ۳';
                document.getElementById('qe-opt-4').value = 'گزینه ۴';
                clearCropSelection();
                document.getElementById('question-editor-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
            } catch (e) {
                alert(e.message);
            }
        }

        // ====================================================
        // ۴. استخراج هوشمند تک‌صفحه‌ای و چندصفحه‌ای خودکار
        // ====================================================
        let isCrawlerRunning = false;

        async function extractSingleCurrentPageWithAi(onlyCropped = false, pageIndexForRotation = 0, shouldAutoCropFigures = true) {
            const base64Img = getCanvasDataUrl(onlyCropped);
            const hint      = document.getElementById('ai-ocr-hint').value.trim();

            const fd = new FormData();
            fd.append('action', 'extract_questions_ai');
            fd.append('image_base64', base64Img);
            fd.append('extra_hint', hint);
            fd.append('page_index', pageIndexForRotation);

            const res = await fetch('api_exam.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.status !== 'success' || !Array.isArray(data.questions)) {
                throw new Error(data.message || 'خطا در استخراج سوالات این صفحه');
            }

            const processed = [];
            for (const q of data.questions) {
                let figPath = '';
                if (shouldAutoCropFigures && !onlyCropped && Array.isArray(q.figure_box) && q.figure_box.length === 4) {
                    try {
                        figPath = await autoCropFigureBoxFromCanvas(q.figure_box);
                    } catch (ignore) {}
                }
                processed.push({
                    ...q,
                    question_image: figPath
                });
            }

            fetchAiKeysStatus();
            return processed;
        }

        async function runAiQuestionExtraction(onlyCropped = false) {
            if (!activeExamObj) {
                alert('ابتدا یک آزمون را انتخاب کنید.');
                return;
            }

            const btn = document.getElementById('btn-ai-extract-page');
            const origHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span>⏳ در حال استخراج با هوش مصنوعی...</span>';

            try {
                const autoCrop = document.getElementById('crawler-auto-crop-fig')?.checked ?? true;
                const extracted = await extractSingleCurrentPageWithAi(onlyCropped, studioPdfPageNum, autoCrop);
                aiStagedQuestions = [...aiStagedQuestions, ...extracted];
                clearCropSelection();
                renderAiStagedQuestions();
                document.getElementById('ai-staged-container').scrollIntoView({ behavior: 'smooth', block: 'start' });
            } catch (err) {
                alert(err.message || 'خطا در ارتباط با سرور هوش مصنوعی.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = origHtml;
            }
        }

        async function startMultiPageCrawler() {
            if (!activeExamObj) {
                alert('لطفاً ابتدا یک آزمون را انتخاب کنید.');
                return;
            }
            if (!studioPdfDoc) {
                alert('لطفاً ابتدا یک فایل PDF سوالات را باز کنید.');
                return;
            }

            let startP = Math.max(1, parseInt(document.getElementById('crawler-start-page').value) || 1);
            let endP   = Math.min(studioPdfDoc.numPages, parseInt(document.getElementById('crawler-end-page').value) || studioPdfDoc.numPages);
            if (endP < startP) {
                const tmp = startP; startP = endP; endP = tmp;
            }

            const autoCropFigs = document.getElementById('crawler-auto-crop-fig').checked;
            const autoSaveDb   = document.getElementById('crawler-auto-save-db').checked;
            const totalPagesToCrawl = (endP - startP) + 1;

            isCrawlerRunning = true;
            document.getElementById('btn-crawler-start').classList.add('hidden');
            document.getElementById('btn-crawler-stop').classList.remove('hidden');
            document.getElementById('crawler-status-box').classList.remove('hidden');

            const statusTx = document.getElementById('crawler-status-text');
            const progPct  = document.getElementById('crawler-progress-pct');
            const progBar  = document.getElementById('crawler-progress-bar');

            let totalExtractedCount = 0;

            for (let p = startP; p <= endP; p++) {
                if (!isCrawlerRunning) break;

                const doneStep = p - startP;
                const pct = Math.round((doneStep / totalPagesToCrawl) * 100);
                progPct.textContent = `${pct}%`;
                progBar.style.width = `${pct}%`;
                statusTx.textContent = `📄 در حال رندر و استخراج هوشمند صفحه ${p} از ${endP} (تاکنون ${totalExtractedCount} سوال استخراج شده)...`;

                studioPdfPageNum = p;
                await renderStudioPdfPage(p);

                if (!isCrawlerRunning) break;

                try {
                    const pageQuestions = await extractSingleCurrentPageWithAi(false, p, autoCropFigs);
                    totalExtractedCount += pageQuestions.length;

                    if (autoSaveDb && pageQuestions.length > 0) {
                        const fd = new FormData();
                        fd.append('action', 'save_questions_batch');
                        fd.append('exam_id', activeExamObj.id);
                        fd.append('questions_json', JSON.stringify(pageQuestions));
                        await fetch('api_exam.php', { method: 'POST', body: fd });
                        await onSelectStudioExam(activeExamObj.id);
                    } else if (pageQuestions.length > 0) {
                        aiStagedQuestions = [...aiStagedQuestions, ...pageQuestions];
                        renderAiStagedQuestions();
                    }
                } catch (pageErr) {
                    statusTx.textContent = `⚠️ صفحه ${p}: ${pageErr.message} — ادامه به صفحه بعد...`;
                }

                if (p < endP && isCrawlerRunning) {
                    await new Promise(r => setTimeout(r, 1200));
                }
            }

            progPct.textContent = '100%';
            progBar.style.width = '100%';
            statusTx.textContent = isCrawlerRunning
                ? `🎉 استخراج خودکار صفحات ${startP} تا ${endP} کامل شد! مجموعاً ${totalExtractedCount} سوال استخراج گردید.`
                : `⏸ استخراج خودکار در صفحه ${studioPdfPageNum} متوقف شد (${totalExtractedCount} سوال استخراج شد).`;

            isCrawlerRunning = false;
            document.getElementById('btn-crawler-start').classList.remove('hidden');
            document.getElementById('btn-crawler-stop').classList.add('hidden');
        }

        function stopMultiPageCrawler() {
            isCrawlerRunning = false;
            document.getElementById('btn-crawler-start').classList.remove('hidden');
            document.getElementById('btn-crawler-stop').classList.add('hidden');
        }

        function renderAiStagedQuestions() {
            const container = document.getElementById('ai-staged-container');
            const listEl    = document.getElementById('ai-staged-list');
            const badge     = document.getElementById('ai-staged-badge');

            if (aiStagedQuestions.length === 0) {
                container.classList.add('hidden');
                return;
            }

            container.classList.remove('hidden');
            badge.textContent = `${aiStagedQuestions.length} سوال`;

            listEl.innerHTML = aiStagedQuestions.map((q, idx) => `
                <div class="bg-white p-3.5 rounded-2xl border border-indigo-200 shadow-sm space-y-2.5 text-xs">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                        <span class="font-extrabold text-indigo-900">سوال استخراجی #${idx + 1}</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button" onclick="attachCurrentCropToStagedQuestion(${idx})" class="bg-amber-100 hover:bg-amber-200 text-amber-900 px-2.5 py-1 rounded-lg font-bold text-[10px]">
                                ✂️ جایگزینی شکل با کادر انتخابی روی PDF
                            </button>
                            <button type="button" onclick="removeStagedQuestion(${idx})" class="text-rose-500 hover:text-rose-700 font-bold px-1.5">✕ حذف</button>
                        </div>
                    </div>

                    ${q.question_image ? `
                        <div class="bg-slate-50 p-2 rounded-xl border border-emerald-200 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <img src="${escapeHtml(q.question_image)}" class="max-h-28 rounded border bg-white p-0.5">
                                <span class="text-[10px] text-emerald-700 font-extrabold">✓ شکل سوال پیوست شد</span>
                            </div>
                            <button type="button" onclick="aiStagedQuestions[${idx}].question_image='';renderAiStagedQuestions();" class="text-rose-600 text-[11px] font-bold">حذف شکل</button>
                        </div>
                    ` : ''}

                    <textarea oninput="aiStagedQuestions[${idx}].question_text=this.value" rows="3" dir="auto" class="w-full p-2 bg-slate-50 border border-slate-300 rounded-xl font-bold text-slate-800 font-mono text-[11px]">${escapeHtml(q.question_text)}</textarea>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        ${[1, 2, 3, 4].map(op => `
                            <div class="flex items-center gap-1.5">
                                <input type="radio" name="staged_corr_${idx}" ${Number(q.correct_option) === op ? 'checked' : ''} onchange="aiStagedQuestions[${idx}].correct_option=${op}" class="w-4 h-4" title="گزینه صحیح">
                                <span class="font-bold text-slate-500">${op})</span>
                                <input type="text" dir="auto" value="${escapeHtml(q['option_' + op] || '')}" oninput="aiStagedQuestions[${idx}]['option_${op}']=this.value" class="flex-1 px-2.5 py-1.5 border border-slate-300 rounded-lg">
                            </div>
                        `).join('')}
                    </div>

                    <div>
                        <input type="text" value="${escapeHtml(q.explanation || '')}" oninput="aiStagedQuestions[${idx}].explanation=this.value" placeholder="پاسخ تشریحی..." class="w-full px-2.5 py-1.5 bg-amber-50 border border-amber-200 rounded-lg text-[11px]">
                    </div>
                </div>
            `).join('');
        }

        async function attachCurrentCropToStagedQuestion(idx) {
            if (!activeCropBox || activeCropBox.w < 18) {
                alert('لطفاً ابتدا با کشیدن موس روی صفحه PDF بالا، دور شکل یا نمودار مورد نظر کادر بکشید و سپس روی این دکمه بزنید.');
                return;
            }
            try {
                const imgPath = await uploadCurrentCropToServer();
                aiStagedQuestions[idx].question_image = imgPath;
                clearCropSelection();
                renderAiStagedQuestions();
            } catch (e) {
                alert(e.message);
            }
        }

        function removeStagedQuestion(idx) {
            aiStagedQuestions.splice(idx, 1);
            renderAiStagedQuestions();
        }

        function clearStagedQuestions() {
            aiStagedQuestions = [];
            renderAiStagedQuestions();
        }

        async function saveAllStagedQuestions() {
            if (!activeExamObj || aiStagedQuestions.length === 0) return;
            const btn = document.getElementById('btn-save-all-staged');
            btn.disabled = true;
            btn.textContent = '⏳ در حال ثبت در آزمون...';

            try {
                const fd = new FormData();
                fd.append('action', 'save_questions_batch');
                fd.append('exam_id', activeExamObj.id);
                fd.append('questions_json', JSON.stringify(aiStagedQuestions));

                const res = await fetch('api_exam.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    alert(data.message);
                    clearStagedQuestions();
                    await fetchAdminExams(activeExamObj.id);
                } else {
                    alert(data.message || 'خطا در ذخیره دسته‌جمعی سوالات');
                }
            } catch (e) {
                alert('خطا در ارتباط با سرور.');
            } finally {
                btn.disabled = false;
                btn.textContent = '✅ تایید و افزودن همه به بانک آزمون';
            }
        }

        // ====================================================
        // ۵. فرم ویرایشگر تکی سوال و بانک سوالات آزمون
        // ====================================================
        function insertPseudocodeBlockInEditor() {
            const ta = document.getElementById('qe-text');
            const start = ta.selectionStart || 0;
            const end   = ta.selectionEnd || 0;
            const selected = ta.value.substring(start, end);
            const snippet = selected
                ? `\n\`\`\`\n${selected}\n\`\`\`\n`
                : `\n\`\`\`\nfor i = 1 to n do:\n    print(i)\n\`\`\`\n`;
            ta.value = ta.value.substring(0, start) + snippet + ta.value.substring(end);
            ta.focus();
        }

        function setEditorQuestionImage(relPath) {
            document.getElementById('qe-image-path').value = relPath || '';
            const box = document.getElementById('qe-image-preview-box');
            const img = document.getElementById('qe-image-preview-el');
            if (relPath) {
                img.src = relPath;
                box.classList.remove('hidden');
            } else {
                img.src = '';
                box.classList.add('hidden');
            }
        }

        function removeEditorQuestionImage() {
            setEditorQuestionImage('');
        }

        async function handleManualQuestionImageUpload(e) {
            const file = e.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = async (ev) => {
                const fd = new FormData();
                fd.append('action', 'upload_question_image');
                fd.append('image_base64', ev.target.result);
                const res = await fetch('api_exam.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    setEditorQuestionImage(data.image_path);
                } else {
                    alert(data.message || 'خطا در آپلود تصویر');
                }
            };
            reader.readAsDataURL(file);
        }

        function resetQuestionEditor() {
            document.getElementById('question-editor-form').reset();
            document.getElementById('qe-id').value = '0';
            document.getElementById('question-editor-title').textContent = '➕ افزودن سوال جدید به آزمون';
            setEditorQuestionImage('');
            document.getElementById('qe-opt-1').value = 'گزینه ۱';
            document.getElementById('qe-opt-2').value = 'گزینه ۲';
            document.getElementById('qe-opt-3').value = 'گزینه ۳';
            document.getElementById('qe-opt-4').value = 'گزینه ۴';
        }

        function loadQuestionIntoEditor(qId) {
            const q = savedQuestions.find(x => Number(x.id) === Number(qId));
            if (!q) return;

            document.getElementById('question-editor-title').textContent = `✏️ ویرایش سوال #${q.sort_order}`;
            document.getElementById('qe-id').value          = q.id;
            document.getElementById('qe-text').value        = q.question_text || '';
            document.getElementById('qe-opt-1').value       = q.option_1 || 'گزینه ۱';
            document.getElementById('qe-opt-2').value       = q.option_2 || 'گزینه ۲';
            document.getElementById('qe-opt-3').value       = q.option_3 || 'گزینه ۳';
            document.getElementById('qe-opt-4').value       = q.option_4 || 'گزینه ۴';
            document.getElementById('qe-explanation').value = q.explanation || '';

            const radio = document.querySelector(`input[name="qe_correct"][value="${q.correct_option}"]`);
            if (radio) radio.checked = true;

            setEditorQuestionImage(q.question_image || '');
            document.getElementById('question-editor-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        async function saveSingleQuestion(e) {
            e.preventDefault();
            if (!activeExamObj) return;

            const corrRadio = document.querySelector('input[name="qe_correct"]:checked');
            const fd = new FormData();
            fd.append('action', 'save_question');
            fd.append('id', document.getElementById('qe-id').value);
            fd.append('exam_id', activeExamObj.id);
            fd.append('question_text', document.getElementById('qe-text').value);
            fd.append('question_image', document.getElementById('qe-image-path').value);
            fd.append('option_1', document.getElementById('qe-opt-1').value);
            fd.append('option_2', document.getElementById('qe-opt-2').value);
            fd.append('option_3', document.getElementById('qe-opt-3').value);
            fd.append('option_4', document.getElementById('qe-opt-4').value);
            fd.append('correct_option', corrRadio ? corrRadio.value : '1');
            fd.append('explanation', document.getElementById('qe-explanation').value);

            const res = await fetch('api_exam.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                resetQuestionEditor();
                await fetchAdminExams(activeExamObj.id);
            } else {
                alert(data.message || 'خطا در ذخیره سوال');
            }
        }

        async function deleteSavedQuestion(qId) {
            if (!confirm('این سوال از آزمون حذف شود؟')) return;
            const fd = new FormData();
            fd.append('action', 'delete_question');
            fd.append('question_id', qId);
            await fetch('api_exam.php', { method: 'POST', body: fd });
            if (activeExamObj) fetchAdminExams(activeExamObj.id);
        }

        function renderSavedQuestionsBank() {
            const listEl = document.getElementById('saved-questions-list');
            document.getElementById('saved-questions-count-badge').textContent = `${savedQuestions.length} سوال`;

            if (savedQuestions.length === 0) {
                listEl.innerHTML = `<div class="text-center text-slate-400 py-10">هنوز سوالی در این آزمون ذخیره نشده است.</div>`;
                return;
            }

            listEl.innerHTML = savedQuestions.map((q, idx) => {
                const corr = Number(q.correct_option);
                return `
                    <div class="p-3 bg-slate-50 rounded-2xl border border-slate-200 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="bg-slate-800 text-white px-2.5 py-0.5 rounded-lg font-extrabold text-[11px]">سوال ${idx + 1}</span>
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="loadQuestionIntoEditor(${q.id})" class="text-indigo-600 hover:underline font-bold text-[11px]">✏️ ویرایش</button>
                                <button type="button" onclick="deleteSavedQuestion(${q.id})" class="text-rose-500 hover:text-rose-700 font-bold text-[11px]">🗑️ حذف</button>
                            </div>
                        </div>

                        ${q.question_image ? `<img src="${escapeHtml(q.question_image)}" class="max-h-40 rounded-xl border border-slate-300 bg-white p-1 mx-auto">` : ''}
                        ${q.question_text ? `<div class="font-bold text-slate-800 leading-relaxed whitespace-pre-line">${formatRichQuestionHtml(q.question_text)}</div>` : ''}

                        <div class="grid grid-cols-2 gap-1.5 pt-1">
                            ${[1, 2, 3, 4].map(op => `
                                <div dir="auto" class="px-2.5 py-1.5 rounded-xl border text-[11px] ${corr === op ? 'bg-emerald-100 border-emerald-400 text-emerald-950 font-extrabold' : 'bg-white border-slate-200 text-slate-600'}">
                                    ${op}) ${formatRichQuestionHtml(q['option_' + op] || '')} ${corr === op ? '✅' : ''}
                                </div>
                            `).join('')}
                        </div>

                        ${q.explanation ? `<div class="text-[11px] bg-amber-50 border border-amber-200 text-amber-900 p-2 rounded-xl">💡 <b>پاسخ تشریحی:</b> ${formatRichQuestionHtml(q.explanation)}</div>` : ''}
                    </div>
                `;
            }).join('');
        }

        // ====================================================
        // ۶. توابع تب مدیریت کاربران و کدهای اشتراک
        // ====================================================
        async function chargeUserVip(targetUid, days) {
            const msg = days > 0 ? `آیا ${days} روز اشتراک ویژه به این کاربر اضافه شود؟` : 'آیا اشتراک ویژه این کاربر لغو شود؟';
            if (!confirm(msg)) return;

            const fd = new FormData();
            fd.append('admin_ajax_action', 'charge_user_vip');
            fd.append('target_user_id', targetUid);
            fd.append('days', days);

            const res = await fetch('admin.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message);
            if (data.status === 'success') location.reload();
        }

        async function handleGenerateCodes(e) {
            e.preventDefault();
            const fd = new FormData();
            fd.append('admin_ajax_action', 'generate_codes');
            fd.append('plan_type', document.getElementById('gen-plan-type').value);
            fd.append('count', document.getElementById('gen-code-count').value);

            const res = await fetch('admin.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message);
            if (data.status === 'success') location.reload();
        }

        async function deletePromoCode(cid) {
            if (!confirm('این کد حذف شود؟')) return;
            const fd = new FormData();
            fd.append('admin_ajax_action', 'delete_code');
            fd.append('code_id', cid);
            await fetch('admin.php', { method: 'POST', body: fd });
            location.reload();
        }

        function filterAdminUsersTable() {
            const q = document.getElementById('admin-user-search').value.trim().toLowerCase();
            document.querySelectorAll('.admin-user-row').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        }

        function escapeHtml(str) {
            return String(str || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
        }

        window.addEventListener('DOMContentLoaded', () => {
            fetchAdminExams();
            fetchAiKeysStatus();
        });
    </script>
</body>
</html>