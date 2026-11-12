<?php
require_once __DIR__ . '/config/config.php';
require_login();

$user_id = (int)$_SESSION['user_id'];

if (!defined('TELEGRAM_SALES_ID'))  define('TELEGRAM_SALES_ID', 'YourTelegramID');
if (!defined('FREE_MAX_PDF_COUNT')) define('FREE_MAX_PDF_COUNT', 1);
if (!defined('FREE_MAX_PDF_MB'))    define('FREE_MAX_PDF_MB', 3);
if (!defined('VIP_MAX_PDF_MB'))     define('VIP_MAX_PDF_MB', 64);
if (!defined('PLAN_1M_PRICE'))      define('PLAN_1M_PRICE', '۵۰۰,۰۰۰');
if (!defined('PLAN_3M_PRICE'))      define('PLAN_3M_PRICE', '۱,۲۰۰,۰۰۰');

$initial_sub = function_exists('get_user_subscription_info')
    ? get_user_subscription_info($pdo, $user_id)
    : [
        'is_vip'      => false,
        'is_admin'    => ($user_id === 1),
        'days_left'   => 0,
        'expires_j'   => 'ندارد',
        'max_pdf_mb'  => FREE_MAX_PDF_MB,
        'max_pdf_cnt' => FREE_MAX_PDF_COUNT,
        'username'    => $_SESSION['username'] ?? '',
        'full_name'   => $_SESSION['full_name'] ?? '',
        'phone'       => $_SESSION['phone'] ?? ''
    ];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>سامانه آزمون آنلاین و کارنامه تحلیلی کنکور v4.2</title>
    <!-- Local Host Assets (Offline-First) -->
    <link href="assets/css/tailwind-local.css" rel="stylesheet">
    <link href="assets/css/vazirmatn-local.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">

    <link href="assets/css/exam.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/exam.css') ?: '4.4'; ?>" rel="stylesheet">
</head>
<body class="bg-slate-100 text-slate-800 h-screen flex flex-col overflow-hidden select-none">

    <!-- ==================================================== -->
    <!-- ۱. هدر اصلی سامانه (مشترک بین تقویم، جزوه‌خوان، مقاله‌خوان و آزمون‌ها) -->
    <!-- ==================================================== -->
    <?php $active_nav = 'exam'; require __DIR__ . '/includes/navbar.php'; ?>

    <!-- ==================================================== -->
    <!-- ۲. نمای اول: لابی لیست آزمون‌ها و سوابق کارنامه‌های کاربر -->
    <!-- ==================================================== -->
    <main id="view-exam-lobby" class="flex-1 overflow-y-auto custom-scroll p-3 md:p-6">
        <div class="max-w-6xl mx-auto space-y-6">

            <!-- بنر خوش‌آمدگویی و فیلتر درس‌ها -->
            <div class="bg-gradient-to-l from-slate-900 via-indigo-950 to-slate-900 text-white rounded-3xl p-5 md:p-6 shadow-lg flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                <div class="space-y-1.5">
                    <h1 class="text-base md:text-lg font-black text-amber-300 flex items-center gap-2">
                        <span>🎯 مرکز آزمون‌های شبیه‌ساز و مبحثی کنکور</span>
                    </h1>
                    <p class="text-xs text-slate-300 leading-relaxed max-w-2xl">
                        آزمون مورد نظر خود را انتخاب کنید، با پاسخبرگ حبابی و تایمر استاندارد به سوالات پاسخ دهید و بلافاصله پس از اتمام، کارنامه تحلیلی (با محاسبه نمره منفی) و پاسخنامه تشریحی را دریافت کرده و درصد خود را در تقویم مطالعاتی ثبت نمایید.
                    </p>
                </div>

                <?php if (!empty($initial_sub['is_admin'])): ?>
                <a href="admin.php" class="bg-amber-400 hover:bg-amber-300 text-slate-950 px-4 py-2.5 rounded-2xl font-black text-xs shrink-0 shadow transition">
                    ➕ طراحی و افزودن آزمون جدید (پنل ادمین)
                </a>
                <?php endif; ?>
            </div>

            <!-- فیلتر دسته‌بندی آزمون‌ها -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-1.5 overflow-x-auto pb-1 text-xs" id="exam-categories-pills">
                    <button type="button" onclick="filterLobbyByCategory('all')" class="px-3.5 py-1.5 rounded-xl font-extrabold bg-indigo-600 text-white shadow-2xs">
                        همه دروس
                    </button>
                </div>
                <div class="text-xs text-slate-500 font-bold" id="lobby-exams-count-label">در حال بارگذاری...</div>
            </div>

            <!-- لیست کارت‌های آزمون‌ها -->
            <div id="lobby-exams-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4"></div>

            <!-- بخش سوابق و کارنامه‌های قبلی دانشجو -->
            <div id="lobby-history-section" class="hidden bg-white rounded-2xl border border-slate-200 shadow-2xs overflow-hidden">
                <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between">
                    <h3 class="font-extrabold text-xs md:text-sm flex items-center gap-1.5">
                        <span>📊 کارنامه‌ها و سوابق آزمون‌های قبلی شما</span>
                    </h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-xs">
                        <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
                            <tr>
                                <th class="p-3">عنوان آزمون</th>
                                <th class="p-3">تاریخ</th>
                                <th class="p-3">عملکرد (صحیح / غلط / نزده)</th>
                                <th class="p-3">درصد نهایی</th>
                                <th class="p-3">پاسخنامه تشریحی</th>
                            </tr>
                        </thead>
                        <tbody id="lobby-history-tbody" class="divide-y divide-slate-200"></tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <!-- ==================================================== -->
    <!-- ۳. نمای دوم: محیط برگزاری آزمون زنده + پاسخبرگ حبابی -->
    <!-- ==================================================== -->
    <section id="view-exam-runner" class="hidden flex-1 flex flex-col overflow-hidden bg-slate-100">

        <!-- نوار بالای جلسه آزمون (تایمر شمارش معکوس، تغییر حالت نمایش، پایان آزمون) -->
        <div class="bg-slate-900 text-white px-3 md:px-5 py-2 flex flex-wrap items-center justify-between gap-2 border-b border-slate-800 shrink-0 z-20">
            <div class="flex items-center gap-2 min-w-0">
                <button type="button" onclick="confirmExitActiveExam()" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg text-xs font-bold">
                    ✕ انصراف
                </button>
                <h2 id="runner-exam-title" class="font-extrabold text-xs md:text-sm text-white truncate max-w-[140px] sm:max-w-md"></h2>
            </div>

            <!-- تایمر شمارش معکوس جلسه آزمون -->
            <div id="runner-timer-box" class="bg-slate-950 border border-slate-700 px-3.5 py-1 rounded-xl flex items-center gap-2 font-mono text-sm md:text-base font-black text-emerald-400 shadow-inner">
                <span>⏱️</span>
                <span id="runner-timer-display">00:00</span>
            </div>

            <!-- سوئیچ نمایش تک‌سوالی / دفترچه‌ای + دکمه ثبت نهایی -->
            <div class="flex items-center gap-1.5">
                <div class="flex bg-slate-800 p-0.5 rounded-xl border border-slate-700 text-[11px]">
                    <button type="button" id="btn-mode-single" onclick="setRunnerViewMode('single')" class="px-2.5 py-1 rounded-lg font-extrabold bg-indigo-600 text-white transition">
                        🎯 تک‌سوالی
                    </button>
                    <button type="button" id="btn-mode-booklet" onclick="setRunnerViewMode('booklet')" class="px-2.5 py-1 rounded-lg font-bold text-slate-300 hover:text-white transition">
                        📜 دفترچه‌ای
                    </button>
                </div>

                <button type="button" onclick="toggleMobileBubbleSheet()" class="lg:hidden px-2.5 py-1.5 bg-slate-800 text-amber-300 border border-slate-700 rounded-xl text-xs font-extrabold">
                    📋 پاسخبرگ
                </button>

                <button type="button" onclick="confirmAndSubmitExam()" id="btn-submit-exam" class="bg-emerald-600 hover:bg-emerald-500 text-white px-3.5 py-1.5 rounded-xl text-xs font-extrabold shadow-sm transition">
                    🏁 ثبت و تصحیح آزمون
                </button>
            </div>
        </div>

        <!-- بدنه اصلی جلسه آزمون (سوالات + ستون پاسخبرگ حبابی) -->
        <div class="flex-1 flex overflow-hidden relative">

            <!-- الف) ناحیه نمایش سوالات آزمون -->
            <div id="runner-questions-viewport" class="flex-1 overflow-y-auto p-3 md:p-6 custom-scroll">
                <div id="runner-questions-container" class="max-w-3xl mx-auto space-y-5 pb-20"></div>
            </div>

            <!-- بک‌دراپ پاسخبرگ در موبایل -->
            <div id="mobile-bubbles-backdrop" onclick="toggleMobileBubbleSheet()" class="fixed inset-0 bg-black/60 z-30 hidden lg:hidden"></div>

            <!-- ب) پاسخبرگ حبابی هوشمند (در دسکتاپ ستون کناری، در موبایل کشوی پایین صفحه) -->
            <aside id="runner-bubble-sheet" class="w-full lg:w-80 bg-white border-t-2 lg:border-t-0 lg:border-r border-slate-200 flex flex-col shrink-0 z-40 fixed lg:static inset-x-0 bottom-0 max-h-[80vh] lg:max-h-none rounded-t-3xl lg:rounded-none transform translate-y-full lg:translate-y-0 transition-transform duration-200 shadow-2xl lg:shadow-none">
                
                <div class="bg-slate-900 text-white px-4 py-3 flex items-center justify-between rounded-t-3xl lg:rounded-none shrink-0">
                    <span class="font-extrabold text-xs flex items-center gap-1.5">📋 پاسخبرگ حبابی هوشمند</span>
                    <button type="button" onclick="toggleMobileBubbleSheet()" class="lg:hidden text-slate-400 hover:text-white text-xl leading-none">&times;</button>
                </div>

                <!-- آمار لحظه‌ای پاسخبرگ -->
                <div class="grid grid-cols-3 gap-1.5 p-2.5 bg-slate-50 border-b border-slate-200 text-center text-[11px] font-bold shrink-0">
                    <div class="bg-indigo-50 text-indigo-800 py-1 rounded-lg border border-indigo-100">
                        زده: <span id="bs-stat-answered" class="font-black">0</span>
                    </div>
                    <div class="bg-slate-100 text-slate-700 py-1 rounded-lg border border-slate-200">
                        نزده: <span id="bs-stat-blank" class="font-black">0</span>
                    </div>
                    <div class="bg-amber-50 text-amber-800 py-1 rounded-lg border border-amber-200">
                        🚩 شک‌دار: <span id="bs-stat-flagged" class="font-black">0</span>
                    </div>
                </div>

                <!-- ردیف‌های حبابی سوالات -->
                <div id="bubble-rows-container" class="flex-1 overflow-y-auto p-3 space-y-1.5 custom-scroll"></div>

                <!-- فوتر پاسخبرگ: تیک ثبت خودکار در تقویم + دکمه پایان آزمون -->
                <div class="p-3 bg-slate-50 border-t border-slate-200 space-y-2.5 text-xs shrink-0">
                    <label class="flex items-center gap-2 cursor-pointer select-none">
                        <input type="checkbox" id="runner-add-to-calendar" checked class="w-4 h-4 accent-indigo-600 rounded">
                        <span class="font-bold text-slate-700 text-[11px]">📅 ثبت خودکار درصد این آزمون در تقویم امروز</span>
                    </label>
                    <button type="button" onclick="confirmAndSubmitExam()" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-extrabold shadow-sm transition">
                        🏁 پایان آزمون و مشاهده کارنامه
                    </button>
                </div>
            </aside>

        </div>
    </section>

    <!-- ==================================================== -->
    <!-- ۴. نمای سوم: کارنامه تحلیلی و پاسخنامه تشریحی -->
    <!-- ==================================================== -->
    <section id="view-report-card" class="hidden flex-1 overflow-y-auto custom-scroll p-3 md:p-6 bg-slate-100">
        <div class="max-w-4xl mx-auto space-y-5 pb-16">

            <!-- نوار بالای کارنامه -->
            <div class="no-print flex flex-wrap items-center justify-between gap-2 bg-white p-3.5 rounded-2xl border border-slate-200 shadow-2xs">
                <button type="button" onclick="returnToExamLobby()" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-extrabold transition">
                    ❯ بازگشت به لیست آزمون‌ها
                </button>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="window.print()" class="px-3.5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-extrabold shadow-xs transition">
                        🖨️ چاپ / ذخیره PDF کارنامه و پاسخنامه
                    </button>
                    <a href="index.php" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-extrabold shadow-xs transition">
                        📅 مشاهده در تقویم کنکور
                    </a>
                </div>
            </div>

            <!-- کارت اصلی نتیجه و درصد کارنامه -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="bg-gradient-to-l from-slate-900 via-indigo-950 to-slate-900 text-white p-5 md:p-6 flex flex-col md:flex-row items-center justify-between gap-4">
                    <div class="space-y-1 text-center md:text-right">
                        <span class="text-[11px] bg-indigo-500/30 border border-indigo-400/40 text-indigo-200 px-2.5 py-0.5 rounded-full font-bold">📊 کارنامه رسمی آزمون</span>
                        <h2 id="rc-exam-title" class="text-base md:text-xl font-black text-white pt-1"></h2>
                        <p id="rc-formula-hint" class="text-[11px] text-slate-300"></p>
                    </div>

                    <div class="bg-white/10 border border-white/20 rounded-2xl px-6 py-3 text-center backdrop-blur-xs shrink-0">
                        <div class="text-[11px] text-amber-300 font-bold mb-0.5">درصد نهایی کسب‌شده</div>
                        <div id="rc-score-percent" class="text-3xl md:text-4xl font-black font-mono" dir="ltr">0%</div>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 divide-y sm:divide-y-0 sm:divide-x sm:divide-x-reverse divide-slate-200 text-center p-4 bg-slate-50 text-xs">
                    <div class="p-2">
                        <div class="text-slate-500 font-bold mb-1">کل سوالات</div>
                        <div id="rc-total-q" class="text-lg font-black text-slate-800">0</div>
                    </div>
                    <div class="p-2">
                        <div class="text-emerald-700 font-bold mb-1">✅ پاسخ صحیح</div>
                        <div id="rc-correct-q" class="text-lg font-black text-emerald-600">0</div>
                    </div>
                    <div class="p-2">
                        <div class="text-rose-700 font-bold mb-1">❌ پاسخ غلط</div>
                        <div id="rc-wrong-q" class="text-lg font-black text-rose-600">0</div>
                    </div>
                    <div class="p-2">
                        <div class="text-slate-600 font-bold mb-1">⚪ نزده (سفید)</div>
                        <div id="rc-blank-q" class="text-lg font-black text-slate-700">0</div>
                    </div>
                </div>
            </div>

            <!-- فیلتر پاسخنامه تشریحی -->
            <div class="no-print bg-white p-3 rounded-2xl border border-slate-200 flex flex-wrap items-center justify-between gap-2 text-xs">
                <span class="font-extrabold text-slate-800">📖 بررسی پاسخنامه تشریحی سوالات:</span>
                <div class="flex flex-wrap items-center gap-1.5">
                    <button type="button" onclick="filterReviewQuestions('all')" id="rev-filter-all" class="px-3 py-1.5 rounded-xl font-extrabold bg-indigo-600 text-white">همه سوالات</button>
                    <button type="button" onclick="filterReviewQuestions('wrong')" id="rev-filter-wrong" class="px-3 py-1.5 rounded-xl font-bold bg-rose-50 text-rose-700 border border-rose-200">❌ فقط غلط‌ها</button>
                    <button type="button" onclick="filterReviewQuestions('blank')" id="rev-filter-blank" class="px-3 py-1.5 rounded-xl font-bold bg-slate-100 text-slate-700">⚪ فقط نزده‌ها</button>
                    <button type="button" onclick="filterReviewQuestions('correct')" id="rev-filter-correct" class="px-3 py-1.5 rounded-xl font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">✅ فقط صحیح‌ها</button>
                </div>
            </div>

            <!-- لیست کارت‌های پاسخنامه تشریحی -->
            <div id="rc-review-questions-list" class="space-y-4"></div>

        </div>
    </section>

    <!-- ==================================================== -->
    <!-- ۵. مودال بزرگ‌نمایی تصاویر و نمودارهای سوال (Image Lightbox) -->
    <!-- ==================================================== -->
    <div id="image-lightbox-modal" onclick="closeImageLightbox()" class="fixed inset-0 bg-black/85 backdrop-blur-xs hidden items-center justify-center p-3 z-50 cursor-zoom-out">
        <img id="lightbox-img-el" src="" alt="Full Image" class="max-w-full max-h-[92vh] rounded-xl shadow-2xl bg-white p-1">
    </div>

    <!-- ==================================================== -->
    <!-- ۶. مودال خرید و تمدید اشتراک ویژه (VIP) -->
    <!-- ==================================================== -->
    <?php require __DIR__ . '/includes/subscription_modal.php'; ?>

    <!-- PHP-to-JS Configuration Bridge -->
    <script>
const TELEGRAM_SALES_ID = "<?php echo htmlspecialchars(TELEGRAM_SALES_ID); ?>";
        let userSub = <?php echo json_encode($initial_sub, JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <!-- Modular External JavaScript -->
    <script src="assets/js/exam.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/exam.js') ?: '4.4'; ?>"></script>
</body>
</html>