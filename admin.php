<?php
require_once __DIR__ . '/config/config.php';
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
    <title>پنل مدیریت کل: آزمون‌ساز، دوره‌های ویدیویی و کاربران v4.4</title>

    <!-- ۱. بارگذاری اولویت اول از پوشه بومی هاست (assets) برای کارکرد ۱۰۰٪ آفلاین و ضدقطعی -->
    <link href="assets/css/tailwind-local.css" rel="stylesheet">
    <link href="assets/css/vazirmatn-local.css" rel="stylesheet">

    <!-- ۲. پشتیبان jsDelivr و Tailwind در صورت عدم اجرای setup_assets -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">

    <!-- ۳. بارگذاری موتور PDF.js از پوشه بومی هاست به همراه پشتیبان CDN -->
    <script src="assets/pdfjs/pdf.min.js"
            onerror="this.onerror=null;this.src='https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js';"></script>

    <!-- ۴. استایل‌های داخلی تضمین‌کننده ظاهر پنل در تمامی شرایط شبکه -->
    <link href="assets/css/admin.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/admin.css') ?: '4.4'; ?>" rel="stylesheet">
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen flex flex-col">

    <!-- ==================================================== -->
    <!-- ۱. هدر پنل مدیریت با ۴ تب: آزمون‌ساز | دوره‌های ویدیویی | کلیدهای AI | کاربران -->
    <!-- ==================================================== -->
    <header class="bg-slate-900 text-white px-4 py-2.5 flex flex-wrap items-center justify-between gap-3 shadow-md sticky top-0 z-40">
        <div class="flex flex-wrap items-center gap-2">
            <span class="bg-amber-400 text-slate-950 px-2.5 py-1 rounded-xl font-black text-xs">🛠️ مدیریت کل</span>

            <div class="flex flex-wrap bg-slate-800 p-1 rounded-xl border border-slate-700 text-xs gap-1">
                <button type="button" id="adm-tab-exams" onclick="switchAdminTab('exams')" class="px-3 py-1.5 rounded-lg font-extrabold bg-indigo-600 text-white transition flex items-center gap-1.5">
                    <span>📝 استودیو آزمون‌ساز</span>
                </button>
                <button type="button" id="adm-tab-courses" onclick="switchAdminTab('courses')" class="px-3 py-1.5 rounded-lg font-bold text-slate-300 hover:text-white transition flex items-center gap-1.5">
                    <span>🎬 دوره‌ها و ویدیوها</span>
                    <span id="header-courses-badge" class="bg-indigo-500 text-white px-1.5 py-0.5 rounded-full text-[10px] font-extrabold">0</span>
                </button>
                <button type="button" id="adm-tab-aikeys" onclick="switchAdminTab('aikeys')" class="px-3 py-1.5 rounded-lg font-bold text-slate-300 hover:text-white transition flex items-center gap-1.5">
                    <span>🤖 کلیدهای AI</span>
                    <span id="header-ai-keys-badge" class="bg-emerald-600 text-white px-1.5 py-0.5 rounded-full text-[10px] font-extrabold">0</span>
                </button>
                <button type="button" id="adm-tab-users" onclick="switchAdminTab('users')" class="px-3 py-1.5 rounded-lg font-bold text-slate-300 hover:text-white transition flex items-center gap-1.5">
                    <span>👥 کاربران و اشتراک</span>
                </button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-1.5 text-xs font-bold">
            <a href="courses.php" class="bg-indigo-600 hover:bg-indigo-500 text-white px-2.5 py-1.5 rounded-xl transition flex items-center gap-1 shadow">
                <span>🎬 آکادمی ویدیو</span>
            </a>
            <a href="exam.php" class="bg-emerald-600 hover:bg-emerald-500 text-white px-2.5 py-1.5 rounded-xl transition flex items-center gap-1 shadow">
                <span>🎯 آزمون‌ها</span>
            </a>
            <a href="index.php" class="bg-slate-800 hover:bg-slate-700 text-slate-200 px-2.5 py-1.5 rounded-xl border border-slate-700 transition">
                📅 تقویم و جزوه
            </a>
            <a href="paper_reader.php" class="bg-slate-800 hover:bg-slate-700 text-slate-200 px-2.5 py-1.5 rounded-xl border border-slate-700 transition">
                🌐 مقاله‌خوان
            </a>
        </div>
    </header>

    <!-- ==================================================== -->
    <!-- ۲. تب اول: استودیو آزمون‌ساز هوشمند (خزنده چندصفحه‌ای + برش خودکار شکل + شبه‌کد) -->
    <!-- ==================================================== -->
    <main id="admin-section-exams" class="flex-1 p-3 md:p-5 max-w-[1600px] w-full mx-auto space-y-4">

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

        <div id="studio-workspace" class="hidden grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">

            <!-- ستون راست آزمون‌ساز (۷ واحد) -->
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

                            <label class="flex items-center gap-1 cursor-pointer text-[11px] bg-slate-800 px-2 py-1 rounded-lg border border-slate-700">
                                <input type="checkbox" id="crawler-auto-crop-fig" checked class="w-4 h-4 rounded">
                                <span>✂️ برش خودکار شکل‌ها</span>
                            </label>

                            <label class="flex items-center gap-1 cursor-pointer text-[11px] bg-slate-800 px-2 py-1 rounded-lg border border-slate-700">
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

            <!-- ستون چپ آزمون‌ساز (۵ واحد) -->
            <div class="lg:col-span-5 space-y-4">
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
                            <textarea id="qe-text" rows="4" placeholder="صورت سوال را بنویسید... (برای شبه‌کد یا الگوریتم، کد را بین ``` قرار دهید)" class="w-full p-2.5 border border-slate-300 rounded-xl focus:outline-none leading-relaxed"></textarea>
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
    <!-- ۳. تب دوم (جدید): مدیریت دوره‌ها، سرفصل‌ها و ویدیوهای آموزشی (🎬 Courses Studio) -->
    <!-- ==================================================== -->
    <section id="admin-section-courses" class="hidden flex-1 p-3 md:p-5 max-w-[1600px] w-full mx-auto space-y-4">

        <!-- نوار انتخاب دوره فعال یا ساخت دوره جدید -->
        <div class="bg-white rounded-2xl shadow border border-slate-200 p-3.5 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2 flex-1">
                <label class="font-extrabold text-xs text-slate-700 shrink-0">🎬 دوره آموزشی فعال:</label>
                <select id="admin-course-select" onchange="onSelectAdminCourse(this.value)" class="flex-1 max-w-md px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl text-xs font-extrabold text-slate-800 focus:outline-none">
                    <option value="">-- در حال بارگذاری لیست دوره‌ها... --</option>
                </select>
                <button type="button" onclick="openCourseMetaModal(false)" id="btn-edit-course-meta" class="hidden px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition">
                    ⚙️ ویرایش مشخصات دوره
                </button>
                <button type="button" onclick="deleteActiveCourse()" id="btn-delete-course" class="hidden px-3 py-2 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-xl text-xs font-bold transition">
                    🗑️ حذف دوره
                </button>
            </div>

            <div class="flex items-center gap-2">
                <a href="courses.php" class="px-3.5 py-2 bg-slate-900 hover:bg-slate-800 text-amber-300 rounded-xl text-xs font-bold transition">
                    👁️ پیش‌نمایش در پلیر سایت
                </a>
                <button type="button" onclick="openCourseMetaModal(true)" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-xl text-xs font-extrabold shadow transition flex items-center gap-1.5">
                    <span>➕ ساخت دوره آموزشی جدید</span>
                </button>
            </div>
        </div>

        <!-- پیام خالی بودن در صورت عدم وجود دوره -->
        <div id="course-empty-state" class="bg-white rounded-2xl border border-slate-200 p-10 text-center space-y-3">
            <div class="text-4xl">🎬</div>
            <h2 class="font-extrabold text-base text-slate-800">استودیو مدیریت دوره‌های ویدیویی، زیرنویس‌ها و جزوات جلسات</h2>
            <p class="text-xs text-slate-500 max-w-lg mx-auto leading-relaxed">
                روی دکمه <b>«➕ ساخت دوره آموزشی جدید»</b> کلیک کنید تا اولین دوره خود را بسازید؛ سپس سرفصل‌ها، لینک ویدیوها، فایل زیرنویس (<code>.srt</code> / <code>.vtt</code>) و جزوه اختصاصی هر جلسه را اضافه نمایید.
            </p>
            <button type="button" onclick="openCourseMetaModal(true)" class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-xl text-xs font-extrabold shadow">
                + ساخت اولین دوره ویدیویی
            </button>
        </div>

        <!-- میز کار دو ستونه مدیریت فصل‌ها و جلسات ویدیویی -->
        <div id="course-workspace" class="hidden grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">

            <!-- ستون راست (۵ واحد): مدیریت درختی فصل‌ها (Chapters) و لیست جلسات هر فصل -->
            <div class="lg:col-span-5 bg-white rounded-2xl shadow border border-slate-200 overflow-hidden flex flex-col">
                <div class="bg-slate-900 text-white px-4 py-3 flex items-center justify-between">
                    <div>
                        <h3 class="font-extrabold text-xs md:text-sm" id="active-course-tree-title">📚 سرفصل‌ها و جلسات دوره</h3>
                        <p class="text-[10px] text-slate-400">ابتدا فصل‌ها را بسازید و سپس جلسات ویدیویی را به هر فصل اضافه کنید</p>
                    </div>
                    <span id="course-total-lessons-badge" class="bg-indigo-600 text-white px-2.5 py-0.5 rounded-full text-[11px] font-extrabold">0 جلسه</span>
                </div>

                <!-- فرم افزودن سریع فصل جدید -->
                <form onsubmit="saveCourseChapter(event)" class="p-3 bg-slate-100 border-b border-slate-200 flex items-center gap-2 text-xs">
                    <input type="text" id="new-chapter-title" required placeholder="نام فصل جدید (مثلاً: فصل دوم - ژنتیک و تولیدمثل)..." class="flex-1 px-3 py-2 bg-white border border-slate-300 rounded-xl font-bold focus:outline-none">
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3.5 py-2 rounded-xl font-extrabold shrink-0 shadow">
                        📁 + افزودن فصل
                    </button>
                </form>

                <!-- درخت فصل‌ها و جلسات زیرمجموعه -->
                <div id="course-chapters-list" class="p-3.5 space-y-3 max-h-[70vh] overflow-y-auto custom-scroll text-xs"></div>
            </div>

            <!-- ستون چپ (۷ واحد): فرم جامع افزودن / ویرایش جلسه ویدیویی (لینک ویدیو، زیرنویس، جزوه، آزمون) -->
            <div class="lg:col-span-7 bg-white rounded-2xl shadow border border-slate-200 overflow-hidden">
                <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between">
                    <h3 id="lesson-editor-header-title" class="font-extrabold text-xs md:text-sm">➕ افزودن جلسه ویدیویی جدید</h3>
                    <button type="button" onclick="resetLessonEditor()" class="text-xs text-slate-300 hover:text-white font-bold">🧹 پاک کردن و جلسه جدید</button>
                </div>

                <form id="lesson-editor-form" onsubmit="saveCourseLesson(event)" class="p-4 space-y-4 text-xs">
                    <input type="hidden" id="le-id" value="0">

                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                        <div class="sm:col-span-2">
                            <label class="block font-bold text-slate-700 mb-1">انتخاب فصل (سرفصل) *</label>
                            <select id="le-chapter-id" name="chapter_id" required class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl font-extrabold text-slate-800 focus:outline-none"></select>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">شماره ترتیب جلسه</label>
                            <input type="number" id="le-sort-order" name="sort_order" min="1" value="1" class="w-full px-3 py-2 border border-slate-300 rounded-xl text-center font-bold">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">مدت تقریبی (دقیقه)</label>
                            <input type="number" id="le-duration-mins" name="duration_mins" min="0" step="1" value="30" placeholder="مثلاً 45" class="w-full px-3 py-2 border border-slate-300 rounded-xl text-center font-bold">
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">عنوان جلسه آموزشی *</label>
                        <input type="text" id="le-title" name="title" required placeholder="مثلاً: جلسه ۱ — تحلیل خط‌به‌خط گفتار اول و حل تست‌های ترکیبی" class="w-full px-3 py-2 border border-slate-300 rounded-xl font-extrabold focus:outline-none">
                    </div>

                    <!-- بخش ۱: لینک‌های ویدیو (پشتیبانی از لینک مستقیم و لینک/کد امبد آپارات) -->
                    <div class="bg-indigo-50 p-3.5 rounded-2xl border border-indigo-200 space-y-3">
                        <div class="font-extrabold text-indigo-950 flex flex-wrap items-center justify-between gap-2">
                            <span>🎥 لینک ویدیو (لینک مستقیم MP4 یا لینک / کد امبد آپارات)</span>
                            <span class="text-[10px] bg-rose-100 text-rose-800 px-2 py-0.5 rounded-lg font-extrabold">🟣 پشتیبانی کامل از آپارات</span>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">آدرس ویدیو یا کد iframe آپارات *</label>
                            <div class="flex items-center gap-1.5">
                                <input type="text" id="le-video-url" name="video_url" required dir="ltr" placeholder="https://www.aparat.com/v/xxxxx یا لینک مستقیم MP4" class="flex-1 px-3 py-2 bg-white border border-slate-300 rounded-xl font-mono text-left focus:outline-none">
                                <button type="button" onclick="fetchAparatMetaForEditor()" id="btn-fetch-aparat" class="bg-rose-600 hover:bg-rose-500 text-white px-3 py-2 rounded-xl font-extrabold text-[11px] shrink-0 shadow transition">
                                    🟣 استعلام از آپارات
                                </button>
                            </div>
                            <div id="aparat-fetch-status" class="hidden text-[11px] font-bold text-emerald-700 mt-1"></div>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-600 mb-1">لینک کیفیت دوم یا سرور کمکی (اختیاری)</label>
                            <input type="text" id="le-video-backup" name="video_url_backup" dir="ltr" placeholder="https://..." class="w-full px-3 py-1.5 bg-white border border-slate-300 rounded-xl font-mono text-left text-[11px] focus:outline-none">
                        </div>
                    </div>

                    <!-- بخش ۲: زیرنویس جلسه (.srt / .vtt) -->
                    <div class="bg-emerald-50 p-3.5 rounded-2xl border border-emerald-200 space-y-2.5">
                        <div class="font-extrabold text-emerald-950 flex items-center justify-between">
                            <span>💬 زیرنویس هوشمند و متن تعاملی جلسه (اختیاری)</span>
                            <span class="text-[10px] text-emerald-800 font-bold">فرمت‌های مجاز: .srt و .vtt</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 items-end">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-700 mb-1">آدرس مستقیم یا مسیر فایل زیرنویس:</label>
                                <input type="text" id="le-subtitle-url" name="subtitle_url" dir="ltr" placeholder="uploads/courses/subtitles/... یا https://..." class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl font-mono text-left text-[11px]">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-700 mb-1">یا آپلود مستقیم فایل زیرنویس (.srt / .vtt):</label>
                                <input type="file" id="le-subtitle-file" name="subtitle_file" accept=".srt,.vtt,.txt" class="w-full bg-white border border-slate-300 rounded-xl p-1.5 text-[11px]">
                            </div>
                        </div>
                    </div>

                    <!-- بخش ۳: جزوه و فایل پیوست اختصاصی همین جلسه -->
                    <div class="bg-amber-50 p-3.5 rounded-2xl border border-amber-200 space-y-2.5">
                        <div class="font-extrabold text-amber-950 flex items-center justify-between">
                            <span>📥 جزوه اختصاصی و فایل پیوست این جلسه (اختیاری)</span>
                            <span class="text-[10px] text-amber-800 font-bold">PDF, ZIP, RAR, DOCX</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 items-end">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-700 mb-1">عنوان دکمه دانلود جزوه:</label>
                                <input type="text" id="le-booklet-title" name="booklet_title" value="جزوه و نکات جلسه" class="w-full px-2.5 py-2 bg-white border border-slate-300 rounded-xl font-bold">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-700 mb-1">لینک مستقیم جزوه:</label>
                                <input type="text" id="le-booklet-url" name="booklet_url" dir="ltr" placeholder="uploads/courses/booklets/... یا https://..." class="w-full px-2.5 py-2 bg-white border border-slate-300 rounded-xl font-mono text-left text-[11px]">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-700 mb-1">یا آپلود فایل جزوه (PDF/ZIP):</label>
                                <input type="file" id="le-booklet-file" name="booklet_file" accept=".pdf,.zip,.rar,.doc,.docx,.png,.jpg" class="w-full bg-white border border-slate-300 rounded-xl p-1.5 text-[11px]">
                            </div>
                        </div>
                    </div>

                    <!-- بخش ۴: اتصال جلسه به آزمون و توضیحات -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">📝 اتصال به آزمون پایان جلسه (اختیاری)</label>
                            <select id="le-linked-exam" name="linked_exam_id" class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-xl font-bold">
                                <option value="">-- بدون آزمون متصل --</option>
                            </select>
                        </div>
                        <div class="flex items-center pt-5">
                            <label class="flex items-center gap-2 cursor-pointer bg-slate-50 px-3 py-2 rounded-xl border border-slate-200 w-full">
                                <input type="checkbox" id="le-free-preview" name="is_free_preview" value="1" class="w-4 h-4 rounded">
                                <span class="font-extrabold text-emerald-800 text-[11px]">🎁 این جلسه «پیش‌نمایش رایگان» برای همه کاربران باشد</span>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-slate-700 mb-1">توضیحات، مباحث تدریس‌شده یا لینک‌های تکمیلی جلسه</label>
                        <textarea id="le-description" name="description" rows="2" placeholder="توضیحات و نکات مهم این جلسه برای نمایش زیر ویدیو..." class="w-full p-2.5 border border-slate-300 rounded-xl focus:outline-none"></textarea>
                    </div>

                    <div class="flex justify-end gap-2 pt-2 border-t border-slate-200">
                        <button type="submit" id="btn-save-lesson" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-extrabold shadow transition">
                            💾 ذخیره جلسه ویدیویی در دوره
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </section>

    <!-- ==================================================== -->
    <!-- ۴. تب سوم: داشبورد مانیتورینگ کلیدهای هوش مصنوعی و میزان مصرف -->
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
    <!-- ۵. تب چهارم: مدیریت کاربران و کدهای فعال‌سازی اشتراک ویژه -->
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
    <!-- ۶. مودال ساخت یا ویرایش شناسنامه دوره ویدیویی (🎬 Course Meta Modal) -->
    <!-- ==================================================== -->
    <div id="course-meta-modal" class="fixed inset-0 bg-slate-900 bg-opacity-70 hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden text-xs">
            <div class="bg-slate-900 text-white px-4 py-3 flex items-center justify-between">
                <h3 id="course-meta-modal-title" class="font-extrabold text-sm">➕ ساخت دوره آموزشی جدید</h3>
                <button type="button" onclick="closeCourseMetaModal()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <form id="course-meta-form" onsubmit="saveCourseMeta(event)" class="p-4 space-y-3.5">
                <input type="hidden" id="cm-id" name="id" value="0">

                <div>
                    <label class="block font-bold text-slate-700 mb-1">عنوان دوره آموزشی *</label>
                    <input type="text" id="cm-title" name="title" required placeholder="مثلاً: دوره جامع زیست‌شناسی کنکور و نهایی" class="w-full px-3 py-2 border border-slate-300 rounded-xl font-extrabold focus:outline-none">
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">نام استاد / مدرس</label>
                        <input type="text" id="cm-instructor" name="instructor" placeholder="مثلاً: دکتر ..." class="w-full px-3 py-2 border border-slate-300 rounded-xl">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">درس / دسته‌بندی</label>
                        <input type="text" id="cm-category" name="category" value="زیست‌شناسی" placeholder="مثلاً: زیست، ریاضی، شیمی..." class="w-full px-3 py-2 border border-slate-300 rounded-xl">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2.5 items-end">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">لینک تصویر کاور دوره (اختیاری)</label>
                        <input type="text" id="cm-cover-url" name="cover_image" dir="ltr" placeholder="https://..." class="w-full px-2.5 py-2 border border-slate-300 rounded-xl font-mono text-left text-[11px]">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">یا آپلود عکس کاور:</label>
                        <input type="file" id="cm-cover-file" name="cover_file" accept="image/*" class="w-full border border-slate-300 rounded-xl p-1.5 bg-slate-50 text-[11px]">
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">توضیحات دوره (اختیاری)</label>
                    <textarea id="cm-description" name="description" rows="2" placeholder="معرفی کوتاه مباحث و ویژگی‌های این دوره..." class="w-full px-3 py-2 border border-slate-300 rounded-xl"></textarea>
                </div>

                <div class="bg-slate-50 p-3 rounded-xl border border-slate-200 space-y-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="cm-vip-only" checked class="w-4 h-4 rounded">
                        <span class="font-bold text-slate-700">👑 دوره ویژه مشترکین VIP باشد (جلسات دارای تیک رایگان برای همه باز خواهند بود)</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="cm-active" checked class="w-4 h-4 rounded">
                        <span class="font-bold text-slate-700">✅ منتشر و فعال در صفحه دوره‌های سایت</span>
                    </label>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t">
                    <button type="button" onclick="closeCourseMetaModal()" class="px-3.5 py-2 bg-slate-100 rounded-xl font-bold">انصراف</button>
                    <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-extrabold shadow">ذخیره دوره</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==================================================== -->
    <!-- ۷. مودال ساخت یا ویرایش شناسنامه آزمون -->
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
    <!-- ۸. مودال ورود سریع کلید پاسخنامه -->
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

        <!-- Modular External JavaScript -->
    <script src="assets/js/admin.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/admin.js') ?: '4.4'; ?>"></script>
</body>
</html>