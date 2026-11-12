<?php
require_once 'config.php';
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
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">

    <style>
        body { font-family: 'Vazirmatn', sans-serif; -webkit-tap-highlight-color: transparent; }
        .custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        /* استایل حباب‌های پاسخبرگ کنکوری */
        .bubble-cell {
            width: 26px;
            height: 18px;
            border-radius: 999px;
            border: 1.5px solid #94a3b8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 800;
            color: #475569;
            cursor: pointer;
            transition: all 0.12s ease;
            user-select: none;
        }
        .bubble-cell:hover {
            border-color: #4f46e5;
            color: #4f46e5;
        }
        .bubble-cell.filled {
            background-color: #312e81;
            border-color: #312e81;
            color: #ffffff;
        }

        @media print {
            .no-print { display: none !important; }
            body { background: #ffffff !important; height: auto !important; overflow: visible !important; }
            #view-report-card { overflow: visible !important; height: auto !important; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 h-screen flex flex-col overflow-hidden select-none">

    <!-- ==================================================== -->
    <!-- ۱. هدر اصلی سامانه (مشترک بین تقویم، جزوه‌خوان، مقاله‌خوان و آزمون‌ها) -->
    <!-- ==================================================== -->
    <header id="main-app-header" class="no-print bg-slate-900 text-white px-3 md:px-4 py-2 flex flex-wrap items-center justify-between gap-2 shadow-md z-30 shrink-0">
        <div class="flex items-center gap-2 md:gap-3">
            <div class="flex items-center gap-1.5">
                <span class="bg-rose-600 text-white px-2 py-1 rounded-lg font-extrabold text-xs">📝 آزمون‌ساز</span>
                <span class="font-bold text-xs md:text-sm truncate max-w-[90px] sm:max-w-none"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
            </div>

            <nav class="flex bg-slate-800 p-0.5 rounded-xl border border-slate-700">
                <a href="index.php" class="px-2 md:px-3 py-1.5 rounded-lg text-xs font-bold text-slate-300 hover:text-white transition">
                    📅 تقویم
                </a>
                <a href="index.php?tab=pdf" class="px-2 md:px-3 py-1.5 rounded-lg text-xs font-bold text-slate-300 hover:text-white transition">
                    📚 جزوه‌خوان
                </a>
                <a href="paper_reader.php" class="px-2 md:px-3 py-1.5 rounded-lg text-xs font-bold text-slate-300 hover:text-white transition">
                    🌐 مقاله‌خوان
                </a>
                <span class="px-2.5 md:px-3.5 py-1.5 rounded-lg text-xs font-bold bg-indigo-600 text-white shadow-xs">
                    📝 آزمون‌ها
                </span>
            </nav>
        </div>

        <div class="flex items-center gap-1.5 md:gap-2 text-xs">
            <button type="button" id="header-sub-btn" onclick="openSubscriptionModal()" class="<?php echo $initial_sub['is_vip'] ? 'bg-gradient-to-r from-amber-500 to-yellow-400 text-slate-950' : 'bg-indigo-600 hover:bg-indigo-500 text-white border border-indigo-400'; ?> px-2.5 py-1.5 rounded-lg font-extrabold flex items-center gap-1 shadow-xs transition">
                <?php if ($initial_sub['is_vip']): ?>
                    <span>👑 ویژه (<?php echo $initial_sub['days_left']; ?> روز)</span>
                <?php else: ?>
                    <span>⭐ ارتقا به اشتراک ویژه</span>
                <?php endif; ?>
            </button>

            <?php if (!empty($initial_sub['is_admin'])): ?>
                <a href="admin.php" class="bg-slate-800 hover:bg-slate-700 border border-amber-500/50 text-amber-300 px-2.5 py-1.5 rounded-lg font-bold flex items-center gap-1 transition">
                    <span>🛠️</span>
                    <span class="hidden lg:inline">مدیریت آزمون‌ها</span>
                </a>
            <?php endif; ?>

            <a href="auth.php?logout=1" class="text-slate-400 hover:text-rose-400 font-bold px-1.5 py-1">خروج</a>
        </div>
    </header>

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
    <div id="subscription-modal" class="fixed inset-0 bg-slate-900/75 backdrop-blur-xs hidden items-end sm:items-center justify-center sm:p-4 z-50">
        <div class="bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl w-full max-w-2xl max-h-[94vh] flex flex-col overflow-hidden">
            <div class="bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white px-5 py-4 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-2.5">
                    <span class="text-2xl">👑</span>
                    <div>
                        <h3 class="font-extrabold text-sm md:text-base text-amber-300">اشتراک ویژه (VIP) سامانه مطالعه و آزمون</h3>
                        <p id="sub-modal-current-status" class="text-[11px] text-slate-300 mt-0.5"></p>
                    </div>
                </div>
                <button type="button" onclick="closeSubscriptionModal()" class="text-slate-400 hover:text-white text-2xl leading-none">&times;</button>
            </div>

            <div class="p-4 sm:p-5 overflow-y-auto custom-scroll space-y-4 text-xs">
                <div id="sub-modal-reason-alert" class="hidden bg-rose-50 border border-rose-200 text-rose-800 px-3.5 py-2.5 rounded-xl font-bold leading-relaxed"></div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    <div class="border-2 border-slate-200 hover:border-indigo-500 rounded-2xl p-4 flex flex-col justify-between bg-white transition">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-extrabold text-sm text-slate-800">💎 اشتراک ۱ ماهه</span>
                                <span class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded-md text-[10px] font-bold">۳۰ روز کامل</span>
                            </div>
                            <div class="my-3">
                                <span class="text-2xl font-black text-indigo-600"><?php echo PLAN_1M_PRICE; ?></span>
                                <span class="text-slate-500 font-bold">تومان</span>
                            </div>
                            <ul class="space-y-1.5 text-[11px] text-slate-600 mb-4">
                                <li>✓ شرکت در تمام آزمون‌های ویژه VIP و شبیه‌ساز کنکور</li>
                                <li>✓ آپلود نامحدود جزوه و مقاله (تا <?php echo VIP_MAX_PDF_MB; ?>MB)</li>
                                <li>✓ مترجم تخصصی هوش مصنوعی + سامانه پیامکی تقویم</li>
                            </ul>
                        </div>
                        <a id="tg-link-1m" href="#" target="_blank" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-extrabold text-center shadow-sm transition block">
                            ✈️ سفارش ۱ ماهه در تلگرام
                        </a>
                    </div>

                    <div class="border-2 border-emerald-500 rounded-2xl p-4 flex flex-col justify-between bg-emerald-50/30 relative shadow-md">
                        <span class="absolute -top-2.5 left-4 bg-emerald-600 text-white text-[10px] font-extrabold px-2.5 py-0.5 rounded-full shadow">
                            🔥 ۳۰۰,۰۰۰ تومان تخفیف ویژه
                        </span>
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-extrabold text-sm text-emerald-950">👑 اشتراک ۳ ماهه</span>
                                <span class="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-md text-[10px] font-bold">۹۰ روز کامل</span>
                            </div>
                            <div class="my-3 flex items-baseline gap-2">
                                <span class="text-2xl font-black text-emerald-600"><?php echo PLAN_3M_PRICE; ?></span>
                                <span class="text-slate-500 font-bold">تومان</span>
                                <span class="text-slate-400 line-through text-[11px]">۱,۵۰۰,۰۰۰</span>
                            </div>
                            <ul class="space-y-1.5 text-[11px] text-slate-700 mb-4">
                                <li>✓ <b>۲۰٪ به‌صرفه‌تر</b> (ماهیانه فقط ۴۰۰ هزار تومان)</li>
                                <li>✓ دسترسی نامحدود به تمام آزمون‌های ویژه و پاسخنامه‌ها</li>
                                <li>✓ آپلود نامحدود PDF + هوش مصنوعی + یادآور پیامکی</li>
                            </ul>
                        </div>
                        <a id="tg-link-3m" href="#" target="_blank" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-extrabold text-center shadow-sm transition block">
                            ✈️ سفارش ۳ ماهه در تلگرام
                        </a>
                    </div>
                </div>

                <div class="bg-slate-900 text-white p-3.5 rounded-2xl flex flex-col sm:flex-row items-center justify-between gap-3">
                    <div class="text-center sm:text-right">
                        <div class="font-extrabold text-amber-300 text-xs">🎟️ کد فعال‌سازی اشتراک دریافت کرده‌اید؟</div>
                        <div class="text-[11px] text-slate-300 mt-0.5">کد دریافتی از پشتیبانی تلگرام را وارد کنید تا درجا فعال شود:</div>
                    </div>
                    <form onsubmit="handleRedeemCode(event)" class="flex items-center gap-1.5 w-full sm:w-auto">
                        <input type="text" id="redeem-code-input" required dir="ltr" placeholder="VIP90-XXXX-XXXX" class="flex-1 sm:w-44 px-3 py-2 rounded-xl bg-slate-800 border border-slate-600 text-white font-mono text-center font-bold">
                        <button type="submit" id="btn-redeem-submit" class="bg-amber-400 hover:bg-amber-300 text-slate-950 font-black px-3.5 py-2 rounded-xl shrink-0">
                            فعال‌سازی
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        const TELEGRAM_SALES_ID = "<?php echo htmlspecialchars(TELEGRAM_SALES_ID); ?>";
        let userSub = <?php echo json_encode($initial_sub, JSON_UNESCAPED_UNICODE); ?>;

        // متغیرهای لابی آزمون‌ها
        let allLobbyExams    = [];
        let selectedCategory = 'all';

        // متغیرهای جلسه آزمون فعال
        let activeExamMeta      = null;
        let activeExamQuestions = [];
        let userAnswersMap      = {}; // { question_id: 1..4 }
        let flaggedQuestionsSet = new Set();
        let currentSingleQIndex = 0;
        let runnerViewMode      = 'single'; // 'single' | 'booklet'
        let examTimerInterval   = null;
        let remainingSeconds    = 0;
        let totalSpentSeconds   = 0;

        // متغیرهای کارنامه و پاسخنامه تشریحی
        let currentReviewQuestions = [];
        let activeReviewFilter     = 'all';

        // ====================================================
        // ۱. بارگذاری لابی آزمون‌ها و سوابق کارنامه‌ها
        // ====================================================
        async function fetchLobbyExams() {
            try {
                const res = await fetch('api_exam.php?action=list_exams');
                const data = await res.json();
                if (data.status === 'success') {
                    allLobbyExams = data.exams || [];
                    if (data.subscription) userSub = data.subscription;

                    renderCategoryPills();
                    renderLobbyExamsGrid();
                    renderRecentHistoryTable(data.recent_history || []);
                }
            } catch (e) {
                console.error('Error loading exams:', e);
            }
        }

        function renderCategoryPills() {
            const cats = Array.from(new Set(allLobbyExams.map(x => x.category || 'عمومی')));
            const container = document.getElementById('exam-categories-pills');

            let html = `
                <button type="button" onclick="filterLobbyByCategory('all')" class="px-3.5 py-1.5 rounded-xl font-extrabold transition ${selectedCategory === 'all' ? 'bg-indigo-600 text-white shadow-2xs' : 'bg-white text-slate-700 border border-slate-200 hover:border-indigo-300'}">
                    همه دروس (${allLobbyExams.length})
                </button>
            `;
            cats.forEach(cat => {
                const isSel = (selectedCategory === cat);
                html += `
                    <button type="button" onclick="filterLobbyByCategory('${escapeHtml(cat)}')" class="px-3.5 py-1.5 rounded-xl font-bold transition ${isSel ? 'bg-indigo-600 text-white shadow-2xs' : 'bg-white text-slate-700 border border-slate-200 hover:border-indigo-300'}">
                        📚 ${escapeHtml(cat)}
                    </button>
                `;
            });
            container.innerHTML = html;
        }

        function filterLobbyByCategory(cat) {
            selectedCategory = cat;
            renderCategoryPills();
            renderLobbyExamsGrid();
        }

        function renderLobbyExamsGrid() {
            const gridEl = document.getElementById('lobby-exams-grid');
            const filtered = allLobbyExams.filter(ex => selectedCategory === 'all' || (ex.category || 'عمومی') === selectedCategory);
            document.getElementById('lobby-exams-count-label').textContent = `${filtered.length} آزمون آماده برگزاری`;

            if (filtered.length === 0) {
                gridEl.innerHTML = `
                    <div class="col-span-full bg-white rounded-2xl border border-slate-200 p-10 text-center text-slate-400 text-xs">
                        هنوز آزمونی در این دسته‌بندی منتشر نشده است.
                    </div>
                `;
                return;
            }

            gridEl.innerHTML = filtered.map(ex => {
                const isLocked = !!ex.is_locked;
                const isVip    = Number(ex.is_vip_only) === 1;
                const hasNeg   = Number(ex.negative_marking) === 1;
                const bestPct  = ex.best_score !== null && ex.best_score !== undefined ? `${ex.best_score}%` : null;

                return `
                    <div class="bg-white rounded-2xl border ${isLocked ? 'border-amber-300 bg-amber-50/20' : 'border-slate-200 hover:border-indigo-400'} p-4 shadow-2xs flex flex-col justify-between transition">
                        <div>
                            <div class="flex items-center justify-between gap-2 mb-2">
                                <span class="bg-indigo-50 text-indigo-800 px-2.5 py-0.5 rounded-lg text-[11px] font-extrabold border border-indigo-100">
                                    📚 ${escapeHtml(ex.category || 'عمومی')}
                                </span>
                                ${isVip ? `<span class="bg-amber-100 text-amber-900 border border-amber-300 px-2 py-0.5 rounded-full text-[10px] font-extrabold">👑 ویژه VIP</span>` : `<span class="bg-emerald-50 text-emerald-700 px-2 py-0.5 rounded-full text-[10px] font-bold">عمومی و رایگان</span>`}
                            </div>

                            <h3 class="font-extrabold text-sm text-slate-900 leading-snug mb-1.5">${escapeHtml(ex.title)}</h3>
                            ${ex.description ? `<p class="text-[11px] text-slate-500 leading-relaxed mb-3">${escapeHtml(ex.description)}</p>` : ''}

                            <div class="grid grid-cols-3 gap-1.5 bg-slate-50 p-2.5 rounded-xl border border-slate-200/80 text-center text-[11px] mb-3">
                                <div>
                                    <div class="text-slate-400 text-[10px]">تعداد سوال</div>
                                    <div class="font-extrabold text-slate-800">${ex.question_count} تست</div>
                                </div>
                                <div>
                                    <div class="text-slate-400 text-[10px]">زمان آزمون</div>
                                    <div class="font-extrabold text-slate-800">${ex.duration_mins} دقیقه</div>
                                </div>
                                <div>
                                    <div class="text-slate-400 text-[10px]">نمره منفی</div>
                                    <div class="font-extrabold ${hasNeg ? 'text-rose-600' : 'text-emerald-600'}">${hasNeg ? 'دارد (۱ به ۳)' : 'ندارد'}</div>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-2 pt-2 border-t border-slate-100">
                            ${bestPct !== null ? `
                                <div class="flex items-center justify-between text-[11px] bg-emerald-50/70 border border-emerald-200 px-2.5 py-1 rounded-lg">
                                    <span class="text-emerald-800 font-bold">🏆 بالاترین درصد قبلی شما:</span>
                                    <span class="font-black text-emerald-700 font-mono" dir="ltr">${bestPct}</span>
                                </div>
                            ` : ''}

                            <button type="button" onclick="startExamSession(${ex.id})" class="w-full py-2.5 rounded-xl font-extrabold text-xs shadow-xs transition flex items-center justify-center gap-1.5 ${isLocked ? 'bg-amber-400 hover:bg-amber-300 text-slate-950' : 'bg-indigo-600 hover:bg-indigo-700 text-white'}">
                                ${isLocked ? '🔒 نیازمند اشتراک ویژه (مشاهده پلن‌ها)' : '🚀 شروع آزمون آنلاین'}
                            </button>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function renderRecentHistoryTable(historyList) {
            const secEl = document.getElementById('lobby-history-section');
            const tbody = document.getElementById('lobby-history-tbody');

            if (!historyList || historyList.length === 0) {
                secEl.classList.add('hidden');
                return;
            }
            secEl.classList.remove('hidden');

            tbody.innerHTML = historyList.map(att => {
                const examObj = allLobbyExams.find(x => Number(x.id) === Number(att.exam_id));
                const title   = examObj ? examObj.title : `آزمون #${att.exam_id}`;
                const pct     = Number(att.score_percent);
                const pctColor = pct >= 50 ? 'text-emerald-600' : (pct >= 0 ? 'text-amber-600' : 'text-rose-600');

                return `
                    <tr class="hover:bg-slate-50">
                        <td class="p-3 font-extrabold text-slate-800">${escapeHtml(title)}</td>
                        <td class="p-3 text-slate-500 font-mono text-[11px]">${escapeHtml(att.created_j || '')}</td>
                        <td class="p-3">
                            <span class="text-emerald-600 font-bold">✅ ${att.correct_count}</span> |
                            <span class="text-rose-600 font-bold">❌ ${att.wrong_count}</span> |
                            <span class="text-slate-500 font-bold">⚪ ${att.blank_count}</span>
                        </td>
                        <td class="p-3 font-black font-mono text-sm ${pctColor}" dir="ltr">${pct}%</td>
                        <td class="p-3">
                            <button type="button" onclick="openAttemptReview(${att.id})" class="px-3 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 rounded-lg font-extrabold text-[11px] transition">
                                📖 کارنامه و پاسخنامه
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // ====================================================
        // ۲. شروع جلسه آزمون آنلاین و مدیریت پاسخبرگ حبابی
        // ====================================================
        async function startExamSession(examId) {
            try {
                const res = await fetch(`api_exam.php?action=start_exam&exam_id=${examId}`);
                const data = await res.json();

                if (data.status === 'success') {
                    activeExamMeta      = data.exam;
                    activeExamQuestions = data.questions || [];
                    userAnswersMap      = {};
                    flaggedQuestionsSet.clear();
                    currentSingleQIndex = 0;

                    document.getElementById('runner-exam-title').textContent = activeExamMeta.title;
                    document.getElementById('view-exam-lobby').classList.add('hidden');
                    document.getElementById('view-report-card').classList.add('hidden');
                    document.getElementById('view-exam-runner').classList.remove('hidden');

                    renderBubbleSheetRows();
                    renderRunnerQuestions();
                    startExamCountdown(Number(activeExamMeta.duration_mins) * 60);
                } else if (data.need_vip) {
                    openSubscriptionModal(data.message);
                } else {
                    alert(data.message || 'خطا در شروع آزمون');
                }
            } catch (e) {
                alert('خطا در ارتباط با سرور آزمون.');
            }
        }

        function startExamCountdown(durationSec) {
            if (examTimerInterval) clearInterval(examTimerInterval);
            remainingSeconds  = durationSec;
            totalSpentSeconds = 0;
            updateTimerUI();

            examTimerInterval = setInterval(() => {
                remainingSeconds--;
                totalSpentSeconds++;
                updateTimerUI();

                if (remainingSeconds <= 0) {
                    clearInterval(examTimerInterval);
                    examTimerInterval = null;
                    alert('⏰ زمان قانونی آزمون به پایان رسید! پاسخبرگ شما به صورت خودکار ثبت و تصحیح می‌شود.');
                    submitActiveExamNow();
                }
            }, 1000);
        }

        function updateTimerUI() {
            const mins = Math.max(0, Math.floor(remainingSeconds / 60));
            const secs = Math.max(0, remainingSeconds % 60);
            const str  = `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
            const disp = document.getElementById('runner-timer-display');
            const box  = document.getElementById('runner-timer-box');

            disp.textContent = str;
            if (remainingSeconds <= 120) {
                box.className = 'bg-rose-950 border border-rose-500 px-3.5 py-1 rounded-xl flex items-center gap-2 font-mono text-sm md:text-base font-black text-rose-300 animate-pulse';
            } else {
                box.className = 'bg-slate-950 border border-slate-700 px-3.5 py-1 rounded-xl flex items-center gap-2 font-mono text-sm md:text-base font-black text-emerald-400';
            }
        }

        function setRunnerViewMode(mode) {
            runnerViewMode = mode;
            const btnSingle  = document.getElementById('btn-mode-single');
            const btnBooklet = document.getElementById('btn-mode-booklet');

            if (mode === 'single') {
                btnSingle.className  = 'px-2.5 py-1 rounded-lg font-extrabold bg-indigo-600 text-white transition';
                btnBooklet.className = 'px-2.5 py-1 rounded-lg font-bold text-slate-300 hover:text-white transition';
            } else {
                btnBooklet.className = 'px-2.5 py-1 rounded-lg font-extrabold bg-indigo-600 text-white transition';
                btnSingle.className  = 'px-2.5 py-1 rounded-lg font-bold text-slate-300 hover:text-white transition';
            }
            renderRunnerQuestions();
        }

        function renderRunnerQuestions() {
            const container = document.getElementById('runner-questions-container');
            if (activeExamQuestions.length === 0) return;

            if (runnerViewMode === 'single') {
                const q = activeExamQuestions[currentSingleQIndex];
                container.innerHTML = renderSingleQuestionCardHtml(q, currentSingleQIndex, true);
            } else {
                container.innerHTML = activeExamQuestions
                    .map((q, idx) => renderSingleQuestionCardHtml(q, idx, false))
                    .join('');
            }
        }

        function renderSingleQuestionCardHtml(q, idx, showNavFooter) {
            const qId       = Number(q.id);
            const chosenOp  = userAnswersMap[qId] || 0;
            const isFlagged = flaggedQuestionsSet.has(qId);
            const totalQ    = activeExamQuestions.length;

            return `
                <div id="runner-q-card-${idx}" class="bg-white rounded-3xl border ${isFlagged ? 'border-amber-400 ring-2 ring-amber-200' : 'border-slate-200'} p-4 md:p-6 shadow-xs space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                        <div class="flex items-center gap-2">
                            <span class="bg-indigo-600 text-white px-3 py-1 rounded-xl font-black text-xs">
                                سوال ${idx + 1} از ${totalQ}
                            </span>
                            ${isFlagged ? `<span class="bg-amber-100 text-amber-900 px-2 py-0.5 rounded-lg text-[11px] font-extrabold">🚩 علامت‌دار</span>` : ''}
                        </div>

                        <div class="flex items-center gap-1.5 text-xs">
                            ${chosenOp > 0 ? `
                                <button type="button" onclick="selectQuestionOption(${qId}, 0, ${idx})" class="px-2.5 py-1 bg-slate-100 hover:bg-rose-50 text-slate-600 hover:text-rose-600 rounded-xl font-bold text-[11px] transition">
                                    🧹 پاک کردن گزینه
                                </button>
                            ` : ''}
                            <button type="button" onclick="toggleFlagQuestion(${qId}, ${idx})" class="px-2.5 py-1 rounded-xl font-bold text-[11px] border transition ${isFlagged ? 'bg-amber-400 text-slate-950 border-amber-500' : 'bg-slate-50 text-slate-600 border-slate-200 hover:bg-amber-50'}">
                                🚩 ${isFlagged ? 'حذف علامت' : 'سوال شک‌دار'}
                            </button>
                        </div>
                    </div>

                    ${q.question_image ? `
                        <div class="bg-slate-50 p-2 rounded-2xl border border-slate-200 text-center">
                            <img src="${escapeHtml(q.question_image)}" onclick="openImageLightbox('${escapeHtml(q.question_image)}')" class="max-h-72 mx-auto rounded-xl cursor-zoom-in" title="برای بزرگ‌نمایی کلیک کنید">
                            <span class="block text-[10px] text-slate-400 mt-1">🔍 برای بزرگ‌نمایی روی تصویر کلیک کنید</span>
                        </div>
                    ` : ''}

                    ${q.question_text ? `
                        <div class="text-sm md:text-base font-extrabold text-slate-800 leading-8 whitespace-pre-line select-text">
                            ${escapeHtml(q.question_text)}
                        </div>
                    ` : ''}

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 pt-2">
                        ${[1, 2, 3, 4].map(op => {
                            const isChosen = (chosenOp === op);
                            return `
                                <button type="button" onclick="selectQuestionOption(${qId}, ${isChosen ? 0 : op}, ${idx})"
                                    class="p-3.5 rounded-2xl border-2 text-right transition flex items-center gap-3 ${isChosen ? 'border-indigo-600 bg-indigo-50/90 text-indigo-950 shadow-xs' : 'border-slate-200 hover:border-indigo-300 bg-white text-slate-700'}">
                                    <span class="w-7 h-7 rounded-full flex items-center justify-center font-black text-xs shrink-0 ${isChosen ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600'}">
                                        ${op}
                                    </span>
                                    <span class="text-xs md:text-sm font-bold leading-relaxed">${escapeHtml(q['option_' + op] || `گزینه ${op}`)}</span>
                                </button>
                            `;
                        }).join('')}
                    </div>

                    ${showNavFooter ? `
                        <div class="flex items-center justify-between pt-4 border-t border-slate-100 text-xs font-extrabold">
                            <button type="button" onclick="stepSingleQuestion(-1)" ${idx === 0 ? 'disabled' : ''} class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 disabled:opacity-40 transition">
                                ❯ سوال قبلی
                            </button>
                            <span class="text-slate-400 text-[11px]">کلیدهای ۱ تا ۴ کیبورد هم فعال هستند</span>
                            ${idx < totalQ - 1 ? `
                                <button type="button" onclick="stepSingleQuestion(1)" class="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white shadow-xs transition">
                                    سوال بعدی ❮
                                </button>
                            ` : `
                                <button type="button" onclick="confirmAndSubmitExam()" class="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white shadow-xs transition">
                                    🏁 پایان و ثبت آزمون
                                </button>
                            `}
                        </div>
                    ` : ''}
                </div>
            `;
        }

        function selectQuestionOption(qId, opNum, qIdx) {
            if (opNum === 0) {
                delete userAnswersMap[qId];
            } else {
                userAnswersMap[qId] = opNum;
            }
            renderRunnerQuestions();
            renderBubbleSheetRows();
        }

        function toggleFlagQuestion(qId, qIdx) {
            if (flaggedQuestionsSet.has(qId)) {
                flaggedQuestionsSet.delete(qId);
            } else {
                flaggedQuestionsSet.add(qId);
            }
            renderRunnerQuestions();
            renderBubbleSheetRows();
        }

        function stepSingleQuestion(delta) {
            const next = currentSingleQIndex + delta;
            if (next >= 0 && next < activeExamQuestions.length) {
                currentSingleQIndex = next;
                renderRunnerQuestions();
                document.getElementById('runner-questions-viewport').scrollTop = 0;
            }
        }

        function jumpToQuestionFromBubble(idx) {
            currentSingleQIndex = idx;
            if (runnerViewMode === 'single') {
                renderRunnerQuestions();
                document.getElementById('runner-questions-viewport').scrollTop = 0;
            } else {
                const card = document.getElementById('runner-q-card-' + idx);
                if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            if (window.innerWidth < 1024) {
                toggleMobileBubbleSheet();
            }
        }

        function renderBubbleSheetRows() {
            const container = document.getElementById('bubble-rows-container');
            const totalQ    = activeExamQuestions.length;
            const answered  = Object.keys(userAnswersMap).length;
            const blank     = Math.max(0, totalQ - answered);
            const flagged   = flaggedQuestionsSet.size;

            document.getElementById('bs-stat-answered').textContent = answered;
            document.getElementById('bs-stat-blank').textContent    = blank;
            document.getElementById('bs-stat-flagged').textContent  = flagged;

            container.innerHTML = activeExamQuestions.map((q, idx) => {
                const qId       = Number(q.id);
                const chosenOp  = userAnswersMap[qId] || 0;
                const isFlagged = flaggedQuestionsSet.has(qId);
                const isCurrent = (runnerViewMode === 'single' && currentSingleQIndex === idx);

                return `
                    <div class="flex items-center justify-between px-2.5 py-1.5 rounded-xl border ${isCurrent ? 'bg-indigo-50/80 border-indigo-300' : 'bg-white border-slate-200/80'}">
                        <button type="button" onclick="jumpToQuestionFromBubble(${idx})" class="text-xs font-black text-slate-700 hover:text-indigo-600 flex items-center gap-1 min-w-[48px] text-right">
                            <span>${idx + 1}.</span>
                            ${isFlagged ? `<span class="text-[10px]">🚩</span>` : ''}
                        </button>
                        <div class="flex items-center gap-1.5" dir="ltr">
                            ${[1, 2, 3, 4].map(op => `
                                <span onclick="selectQuestionOption(${qId}, ${chosenOp === op ? 0 : op}, ${idx})"
                                      class="bubble-cell ${chosenOp === op ? 'filled' : ''}">
                                    ${op}
                                </span>
                            `).join('')}
                        </div>
                    </div>
                `;
            }).join('');
        }

        function toggleMobileBubbleSheet() {
            const sheet = document.getElementById('runner-bubble-sheet');
            const bd    = document.getElementById('mobile-bubbles-backdrop');
            if (sheet.classList.contains('translate-y-full')) {
                sheet.classList.remove('translate-y-full');
                bd.classList.remove('hidden');
            } else {
                sheet.classList.add('translate-y-full');
                bd.classList.add('hidden');
            }
        }

        function confirmExitActiveExam() {
            if (!confirm('آیا مطمئن هستید که می‌خواهید بدون ثبت کارنامه از جلسه آزمون خارج شوید؟')) return;
            if (examTimerInterval) clearInterval(examTimerInterval);
            returnToExamLobby();
        }

        function confirmAndSubmitExam() {
            const totalQ   = activeExamQuestions.length;
            const answered = Object.keys(userAnswersMap).length;
            const blank    = totalQ - answered;

            if (!confirm(`آیا از پایان آزمون و ثبت پاسخبرگ مطمئن هستید؟\n\n✅ پاسخ داده‌شده: ${answered} سوال\n⚪ نزده: ${blank} سوال`)) {
                return;
            }
            submitActiveExamNow();
        }

        // ====================================================
        // ۳. ارسال پاسخبرگ به سرور و نمایش کارنامه و پاسخنامه تشریحی
        // ====================================================
        async function submitActiveExamNow() {
            if (examTimerInterval) {
                clearInterval(examTimerInterval);
                examTimerInterval = null;
            }

            const btn = document.getElementById('btn-submit-exam');
            btn.disabled = true;
            btn.textContent = '⏳ در حال تصحیح...';

            try {
                const fd = new FormData();
                fd.append('action', 'submit_exam');
                fd.append('exam_id', activeExamMeta.id);
                fd.append('time_spent_sec', totalSpentSeconds);
                fd.append('answers_json', JSON.stringify(userAnswersMap));
                fd.append('add_to_calendar', document.getElementById('runner-add-to-calendar').checked ? '1' : '0');

                const res = await fetch('api_exam.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.status === 'success') {
                    displayReportCardView(data.exam_title, data.report_card, data.review_questions);
                } else {
                    alert(data.message || 'خطا در تصحیح آزمون');
                }
            } catch (e) {
                alert('خطا در ارتباط با سرور.');
            } finally {
                btn.disabled = false;
                btn.textContent = '🏁 ثبت و تصحیح آزمون';
            }
        }

        async function openAttemptReview(attemptId) {
            try {
                const res = await fetch(`api_exam.php?action=get_attempt_review&attempt_id=${attemptId}`);
                const data = await res.json();
                if (data.status === 'success') {
                    displayReportCardView(data.exam_title, data.report_card, data.review_questions);
                } else {
                    alert(data.message || 'خطا در دریافت کارنامه');
                }
            } catch (e) {
                alert('خطا در ارتباط با سرور.');
            }
        }

        function displayReportCardView(examTitle, rc, reviewQuestions) {
            document.getElementById('view-exam-lobby').classList.add('hidden');
            document.getElementById('view-exam-runner').classList.add('hidden');
            document.getElementById('view-report-card').classList.remove('hidden');

            document.getElementById('rc-exam-title').textContent = examTitle;
            document.getElementById('rc-formula-hint').textContent = Number(rc.negative_marking) === 1
                ? 'محاسبه‌شده با فرمول نمره منفی استاندارد کنکور (هر ۳ غلط یک صحیح را حذف می‌کند)'
                : 'محاسبه‌شده بدون نمره منفی';

            const pctEl = document.getElementById('rc-score-percent');
            pctEl.textContent = `${rc.score_percent}%`;
            pctEl.className = Number(rc.score_percent) >= 50
                ? 'text-3xl md:text-4xl font-black font-mono text-emerald-400'
                : (Number(rc.score_percent) >= 0 ? 'text-3xl md:text-4xl font-black font-mono text-amber-300' : 'text-3xl md:text-4xl font-black font-mono text-rose-400');

            document.getElementById('rc-total-q').textContent   = rc.total_questions;
            document.getElementById('rc-correct-q').textContent = rc.correct_count;
            document.getElementById('rc-wrong-q').textContent   = rc.wrong_count;
            document.getElementById('rc-blank-q').textContent   = rc.blank_count;

            currentReviewQuestions = reviewQuestions || [];
            filterReviewQuestions('all');
            document.getElementById('view-report-card').scrollTop = 0;
        }

        function filterReviewQuestions(filter) {
            activeReviewFilter = filter;
            ['all', 'wrong', 'blank', 'correct'].forEach(f => {
                const btn = document.getElementById('rev-filter-' + f);
                if (!btn) return;
                if (f === filter) {
                    btn.className = 'px-3 py-1.5 rounded-xl font-extrabold bg-indigo-600 text-white shadow-2xs';
                } else {
                    btn.className = 'px-3 py-1.5 rounded-xl font-bold bg-slate-100 text-slate-700 hover:bg-slate-200';
                }
            });

            const listEl = document.getElementById('rc-review-questions-list');
            const filtered = currentReviewQuestions.filter(q => filter === 'all' || q.status === filter);

            if (filtered.length === 0) {
                listEl.innerHTML = `<div class="bg-white rounded-2xl p-8 text-center text-slate-400 text-xs border border-slate-200">سوالی در این دسته وجود ندارد.</div>`;
                return;
            }

            listEl.innerHTML = filtered.map(q => {
                const userOp = Number(q.user_option);
                const corrOp = Number(q.correct_option);

                let statusBadge = '';
                if (q.status === 'correct') {
                    statusBadge = `<span class="bg-emerald-100 text-emerald-800 border border-emerald-300 px-2.5 py-0.5 rounded-lg text-xs font-extrabold">✅ پاسخ صحیح</span>`;
                } else if (q.status === 'wrong') {
                    statusBadge = `<span class="bg-rose-100 text-rose-800 border border-rose-300 px-2.5 py-0.5 rounded-lg text-xs font-extrabold">❌ پاسخ غلط (انتخاب شما: گزینه ${userOp})</span>`;
                } else {
                    statusBadge = `<span class="bg-slate-100 text-slate-700 border border-slate-300 px-2.5 py-0.5 rounded-lg text-xs font-extrabold">⚪ نزده (گزینه صحیح: ${corrOp})</span>`;
                }

                return `
                    <div class="bg-white rounded-3xl border border-slate-200 p-4 md:p-6 shadow-2xs space-y-4">
                        <div class="flex items-center justify-between border-b border-slate-100 pb-2.5">
                            <span class="bg-slate-800 text-white px-3 py-1 rounded-xl font-black text-xs">سوال ${q.question_number}</span>
                            ${statusBadge}
                        </div>

                        ${q.question_image ? `
                            <div class="bg-slate-50 p-2 rounded-2xl border border-slate-200 text-center">
                                <img src="${escapeHtml(q.question_image)}" onclick="openImageLightbox('${escapeHtml(q.question_image)}')" class="max-h-64 mx-auto rounded-xl cursor-zoom-in">
                            </div>
                        ` : ''}

                        ${q.question_text ? `
                            <div class="text-sm font-extrabold text-slate-800 leading-7 whitespace-pre-line">${escapeHtml(q.question_text)}</div>
                        ` : ''}

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                            ${[1, 2, 3, 4].map(op => {
                                const isCorr = (corrOp === op);
                                const isUserWrong = (userOp === op && !isCorr);

                                let boxClass = 'border-slate-200 bg-slate-50/50 text-slate-600';
                                let iconStr  = '';
                                if (isCorr) {
                                    boxClass = 'border-emerald-500 bg-emerald-50 text-emerald-950 font-extrabold ring-1 ring-emerald-400';
                                    iconStr  = ' ✅ (پاسخ صحیح)';
                                } else if (isUserWrong) {
                                    boxClass = 'border-rose-400 bg-rose-50 text-rose-900 font-bold line-through';
                                    iconStr  = ' ❌ (انتخاب شما)';
                                }

                                return `
                                    <div class="p-3 rounded-2xl border ${boxClass} flex items-center justify-between gap-2">
                                        <span>${op}) ${escapeHtml(q['option_' + op] || '')}</span>
                                        <span class="text-[10px] shrink-0">${iconStr}</span>
                                    </div>
                                `;
                            }).join('')}
                        </div>

                        ${q.explanation ? `
                            <div class="bg-amber-50/90 border border-amber-200 text-amber-950 p-3.5 rounded-2xl text-xs leading-7">
                                <div class="font-extrabold text-amber-900 mb-1">💡 پاسخنامه تشریحی و نکته آموزشی:</div>
                                <div class="whitespace-pre-line">${escapeHtml(q.explanation)}</div>
                            </div>
                        ` : ''}
                    </div>
                `;
            }).join('');
        }

        function returnToExamLobby() {
            document.getElementById('view-exam-runner').classList.add('hidden');
            document.getElementById('view-report-card').classList.add('hidden');
            document.getElementById('view-exam-lobby').classList.remove('hidden');
            fetchLobbyExams();
        }

        // پشتیبانی از کلیدهای کیبورد (1 تا 4 برای زدن گزینه و فلش‌های چپ/راست برای سوال بعد و قبل)
        window.addEventListener('keydown', (e) => {
            if (document.getElementById('view-exam-runner').classList.contains('hidden')) return;
            if (runnerViewMode !== 'single' || activeExamQuestions.length === 0) return;

            const curQ = activeExamQuestions[currentSingleQIndex];
            if (!curQ) return;

            if (['1', '2', '3', '4'].includes(e.key)) {
                const op = parseInt(e.key, 10);
                const curChosen = userAnswersMap[curQ.id] || 0;
                selectQuestionOption(Number(curQ.id), curChosen === op ? 0 : op, currentSingleQIndex);
            } else if (e.key === 'ArrowLeft') {
                stepSingleQuestion(1);
            } else if (e.key === 'ArrowRight') {
                stepSingleQuestion(-1);
            }
        });

        function openImageLightbox(src) {
            const m = document.getElementById('image-lightbox-modal');
            document.getElementById('lightbox-img-el').src = src;
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeImageLightbox() {
            const m = document.getElementById('image-lightbox-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        function openSubscriptionModal(reasonMessage = '') {
            const statusEl = document.getElementById('sub-modal-current-status');
            statusEl.innerHTML = userSub.is_vip
                ? `وضعیت فعلی: <b class="text-emerald-300">مشترک ویژه (${userSub.days_left} روز مانده)</b>`
                : `وضعیت فعلی: <b class="text-amber-200">کاربر عادی (رایگان)</b>`;

            const reasonBox = document.getElementById('sub-modal-reason-alert');
            if (reasonMessage) {
                reasonBox.textContent = '⚠️ ' + reasonMessage;
                reasonBox.classList.remove('hidden');
            } else {
                reasonBox.classList.add('hidden');
            }

            const actionType = userSub.is_vip ? 'تمدید اشتراک' : 'خرید اشتراک';
            const msg1m = `سلام وقت بخیر، درخواست ${actionType} ویژه ۱ ماهه (۵۰۰,۰۰۰ تومان) را دارم.\n👤 نام: ${userSub.full_name}\n🆔 نام کاربری: ${userSub.username}`;
            const msg3m = `سلام وقت بخیر، درخواست ${actionType} ویژه ۳ ماهه (۱,۲۰۰,۰۰۰ تومان) را دارم.\n👤 نام: ${userSub.full_name}\n🆔 نام کاربری: ${userSub.username}`;

            document.getElementById('tg-link-1m').href = `https://t.me/${TELEGRAM_SALES_ID}?text=${encodeURIComponent(msg1m)}`;
            document.getElementById('tg-link-3m').href = `https://t.me/${TELEGRAM_SALES_ID}?text=${encodeURIComponent(msg3m)}`;

            const m = document.getElementById('subscription-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeSubscriptionModal() {
            const m = document.getElementById('subscription-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        async function handleRedeemCode(e) {
            e.preventDefault();
            const code = document.getElementById('redeem-code-input').value.trim();
            if (!code) return;

            const fd = new FormData();
            fd.append('action', 'redeem_code');
            fd.append('code', code);

            const res = await fetch('api_subscription.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message);
            if (data.status === 'success') {
                userSub = data.subscription;
                closeSubscriptionModal();
                fetchLobbyExams();
            }
        }

        function escapeHtml(str) {
            return String(str || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
        }

        window.addEventListener('DOMContentLoaded', () => {
            fetchLobbyExams();
        });
    </script>
</body>
</html>