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
    <title>آکادمی ویدیویی و پلیر هوشمند آموزشی v4.4</title>

    <!-- ۱. بارگذاری اولویت اول از پوشه بومی هاست (assets) برای کارکرد ۱۰۰٪ آفلاین و ضدقطعی -->
    <link href="assets/css/tailwind-local.css" rel="stylesheet">
    <link href="assets/css/vazirmatn-local.css" rel="stylesheet">

    <!-- ۲. پشتیبان jsDelivr و Tailwind در صورت عدم اجرای setup_assets -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">

    <!-- ۳. استایل‌های داخلی تضمین‌کننده ظاهر صفحه و پلیر ویدیو در تمامی شرایط شبکه -->
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', Tahoma, sans-serif;
            background-color: #f1f5f9;
            color: #1e293b;
            margin: 0;
            -webkit-tap-highlight-color: transparent;
        }
        .custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        /* کلاس‌های پایه ضدقطعی */
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
        .border-slate-200 { border-color: #e2e8f0 !important; }
        .border-slate-300 { border-color: #cbd5e1 !important; }
        .border-slate-700 { border-color: #334155 !important; }
        .border-slate-800 { border-color: #1e293b !important; }

        .bg-indigo-50 { background-color: #eef2ff !important; }
        .bg-indigo-100 { background-color: #e0e7ff !important; }
        .bg-indigo-600 { background-color: #4f46e5 !important; }
        .bg-indigo-700 { background-color: #4338ca !important; }
        .text-indigo-300 { color: #a5b4fc !important; }
        .text-indigo-600 { color: #4f46e5 !important; }
        .text-indigo-700 { color: #4338ca !important; }
        .text-indigo-800 { color: #3730a3 !important; }
        .text-indigo-950 { color: #1e1b4b !important; }

        .bg-emerald-50 { background-color: #ecfdf5 !important; }
        .bg-emerald-100 { background-color: #d1fae5 !important; }
        .bg-emerald-500 { background-color: #10b981 !important; }
        .bg-emerald-600 { background-color: #059669 !important; }
        .text-emerald-300 { color: #6ee7b7 !important; }
        .text-emerald-400 { color: #34d399 !important; }
        .text-emerald-700 { color: #047857 !important; }
        .text-emerald-800 { color: #065f46 !important; }

        .bg-amber-50 { background-color: #fffbeb !important; }
        .bg-amber-100 { background-color: #fef3c7 !important; }
        .bg-amber-400 { background-color: #fbbf24 !important; }
        .bg-amber-500 { background-color: #f59e0b !important; }
        .text-amber-300 { color: #fcd34d !important; }
        .text-amber-800 { color: #92400e !important; }
        .text-amber-900 { color: #78350f !important; }

        .bg-rose-50 { background-color: #fff1f2 !important; }
        .bg-rose-600 { background-color: #e11d48 !important; }
        .text-rose-500 { color: #f43f5e !important; }
        .text-rose-600 { color: #e11d48 !important; }

        .rounded-xl { border-radius: 0.75rem !important; }
        .rounded-2xl { border-radius: 1rem !important; }
        .rounded-3xl { border-radius: 1.5rem !important; }
        .shrink-0 { flex-shrink: 0 !important; }
        .text-\[10px\] { font-size: 10px !important; }
        .text-\[11px\] { font-size: 11px !important; }

        @media (min-width: 1024px) {
            .lg\:grid-cols-12 { grid-template-columns: repeat(12, minmax(0, 1fr)) !important; }
            .lg\:col-span-8 { grid-column: span 8 / span 8 !important; }
            .lg\:col-span-4 { grid-column: span 4 / span 4 !important; }
            .lg\:col-span-12 { grid-column: span 12 / span 12 !important; }
        }

        /* استایل اختصاصی کانتینر ویدیو و لایه زیرنویس هوشمند */
        #video-stage-wrapper {
            position: relative;
            background-color: #000000;
            width: 100%;
            overflow: hidden;
            border-radius: 1rem;
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.5);
        }
        #main-video-element {
            width: 100%;
            max-height: 70vh;
            display: block;
            margin: 0 auto;
            background-color: #000;
        }
        #custom-subtitle-overlay {
            position: absolute;
            bottom: 54px;
            left: 6%;
            right: 6%;
            text-align: center;
            pointer-events: none;
            z-index: 25;
            transition: opacity 0.12s ease;
        }
        #custom-subtitle-text {
            display: inline-block;
            background-color: rgba(2, 6, 23, 0.82);
            color: #ffffff;
            padding: 6px 14px;
            border-radius: 10px;
            font-weight: 700;
            line-height: 1.65;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.12);
            max-width: 100%;
            white-space: pre-line;
        }
        .cue-item.active-cue {
            background-color: #eef2ff !important;
            border-color: #6366f1 !important;
            color: #1e1b4b !important;
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 h-screen flex flex-col overflow-hidden select-none">

    <!-- ==================================================== -->
    <!-- ۱. هدر اصلی سامانه -->
    <!-- ==================================================== -->
    <header id="main-app-header" class="bg-slate-900 text-white px-3 md:px-4 py-2 flex flex-wrap items-center justify-between gap-2 shadow-md z-30 shrink-0">
        <div class="flex items-center gap-2 md:gap-3">
            <div class="flex items-center gap-1.5">
                <span class="bg-indigo-600 text-white px-2 py-1 rounded-lg font-extrabold text-xs">🎬 آکادمی</span>
                <span class="font-bold text-xs md:text-sm truncate max-w-[90px] sm:max-w-none"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
            </div>

            <nav class="flex bg-slate-800 p-0.5 rounded-xl border border-slate-700 overflow-x-auto">
                <a href="index.php" class="px-2 md:px-3 py-1.5 rounded-lg text-xs font-bold text-slate-300 hover:text-white transition shrink-0">
                    📅 تقویم
                </a>
                <a href="index.php?tab=pdf" class="px-2 md:px-3 py-1.5 rounded-lg text-xs font-bold text-slate-300 hover:text-white transition shrink-0">
                    📚 جزوه‌خوان
                </a>
                <a href="paper_reader.php" class="px-2 md:px-3 py-1.5 rounded-lg text-xs font-bold text-slate-300 hover:text-white transition shrink-0">
                    🌐 مقاله‌خوان
                </a>
                <a href="exam.php" class="px-2 md:px-3 py-1.5 rounded-lg text-xs font-bold text-slate-300 hover:text-white transition shrink-0">
                    📝 آزمون‌ها
                </a>
                <span class="px-2.5 md:px-3.5 py-1.5 rounded-lg text-xs font-extrabold bg-indigo-600 text-white shadow shrink-0">
                    🎬 ویدیوها
                </span>
            </nav>
        </div>

        <div class="flex items-center gap-1.5 md:gap-2 text-xs">
            <button type="button" id="header-sub-btn" onclick="openSubscriptionModal()" class="<?php echo $initial_sub['is_vip'] ? 'bg-amber-400 text-slate-950' : 'bg-indigo-600 hover:bg-indigo-500 text-white border border-indigo-400'; ?> px-2.5 py-1.5 rounded-lg font-extrabold flex items-center gap-1 shadow transition">
                <?php if ($initial_sub['is_vip']): ?>
                    <span>👑 ویژه (<?php echo $initial_sub['days_left']; ?> روز)</span>
                <?php else: ?>
                    <span>⭐ ارتقا به اشتراک ویژه</span>
                <?php endif; ?>
            </button>

            <?php if (!empty($initial_sub['is_admin'])): ?>
                <a href="admin.php?tab=courses" class="bg-slate-800 hover:bg-slate-700 border border-amber-500 text-amber-300 px-2.5 py-1.5 rounded-lg font-bold flex items-center gap-1 transition">
                    <span>🛠️</span>
                    <span class="hidden lg:inline">مدیریت دوره‌ها</span>
                </a>
            <?php endif; ?>

            <a href="auth.php?logout=1" class="text-slate-400 hover:text-rose-400 font-bold px-1.5 py-1">خروج</a>
        </div>
    </header>

    <!-- ==================================================== -->
    <!-- ۲. نمای اول: ویترین دوره‌های آموزشی + بنر ادامه آخرین ویدیو -->
    <!-- ==================================================== -->
    <main id="view-courses-lobby" class="flex-1 overflow-y-auto custom-scroll p-3 md:p-6">
        <div class="max-w-6xl mx-auto space-y-6 pb-12">

            <!-- بنر هدر آکادمی ویدیویی -->
            <div class="bg-slate-900 text-white rounded-3xl p-5 md:p-6 shadow-lg flex flex-col md:flex-row items-start md:items-center justify-between gap-4 border border-slate-800">
                <div class="space-y-1.5">
                    <h1 class="text-base md:text-lg font-black text-amber-300 flex items-center gap-2">
                        <span>🎬 آکادمی ویدیویی، کلاس‌های درس و جزوات جلسات</span>
                    </h1>
                    <p class="text-xs text-slate-300 leading-relaxed max-w-2xl">
                        هر جلسه را دقیقاً از همان ثانیه‌ای که قبلاً بسته بودید ادامه دهید، زیرنویس فارسی/انگلیسی و متن تعاملی جلسه را فعال کنید، جزوه همان جلسه را دانلود یا مستقیم وارد «جزوه‌خوان» نمایید و در لحظات مهم ویدیو یادداشت زمان‌دار ثبت کنید.
                    </p>
                </div>

                <?php if (!empty($initial_sub['is_admin'])): ?>
                <a href="admin.php?tab=courses" class="bg-amber-400 hover:bg-amber-300 text-slate-950 px-4 py-2.5 rounded-2xl font-black text-xs shrink-0 shadow transition">
                    ➕ افزودن دوره و ویدیو جدید (پنل ادمین)
                </a>
                <?php endif; ?>
            </div>

            <!-- بنر هوشمند «ادامه تماشای آخرین جلسه» (در صورتی که کاربر قبلاً ویدیویی را نیمه‌کاره گذاشته باشد) -->
            <div id="global-resume-banner" class="hidden bg-indigo-50 border-2 border-indigo-400 rounded-2xl p-4 flex flex-wrap items-center justify-between gap-3 shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-2xl bg-indigo-600 text-white flex items-center justify-center text-xl font-bold shrink-0">
                        ▶️
                    </div>
                    <div>
                        <div class="text-[11px] font-extrabold text-indigo-700">ادامه آخرین جلسه در حال تماشا:</div>
                        <div id="global-resume-title" class="text-xs md:text-sm font-black text-slate-900 mt-0.5"></div>
                        <div id="global-resume-time" class="text-[11px] text-slate-600 font-bold mt-0.5"></div>
                    </div>
                </div>
                <button type="button" id="btn-global-resume" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-xl text-xs font-extrabold shadow transition shrink-0">
                    🚀 ادامه پخش از همان ثانیه ❮
                </button>
            </div>

            <!-- فیلتر دسته‌بندی دوره‌ها -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-1.5 overflow-x-auto pb-1 text-xs" id="courses-category-pills"></div>
                <div class="text-xs text-slate-500 font-bold" id="lobby-courses-count"></div>
            </div>

            <!-- شبکه کارت‌های دوره‌های آموزشی -->
            <div id="lobby-courses-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5"></div>

        </div>
    </main>

    <!-- ==================================================== -->
    <!-- ۳. نمای دوم: استودیو پخش ویدیو، سرفصل‌ها، زیرنویس تعاملی و یادداشت زمان‌دار -->
    <!-- ==================================================== -->
    <section id="view-course-player" class="hidden flex-1 overflow-y-auto custom-scroll bg-slate-100 p-2.5 md:p-5">
        <div class="max-w-[1600px] mx-auto space-y-3.5 pb-16">

            <!-- نوار بالای صفحه پلیر (بازگشت، عنوان دوره، درصد پیشرفت کل دوره و حالت سینمایی) -->
            <div class="bg-white rounded-2xl border border-slate-200 px-3.5 py-2.5 flex flex-wrap items-center justify-between gap-2 shadow-sm text-xs">
                <div class="flex items-center gap-2 min-w-0">
                    <button type="button" onclick="returnToCoursesLobby()" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-extrabold shrink-0 transition">
                        ❯ همه دوره‌ها
                    </button>
                    <h2 id="player-course-title" class="font-black text-slate-800 text-xs md:text-sm truncate"></h2>
                </div>

                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2 bg-slate-50 px-3 py-1 rounded-xl border border-slate-200">
                        <span class="text-[11px] font-bold text-slate-600">پیشرفت دوره:</span>
                        <div class="w-20 sm:w-28 h-2 bg-slate-200 rounded-full overflow-hidden">
                            <div id="player-course-progress-bar" class="h-full bg-emerald-500 transition-all duration-300" style="width: 0%;"></div>
                        </div>
                        <span id="player-course-progress-text" class="font-mono font-extrabold text-emerald-700 text-[11px]">0%</span>
                    </div>

                    <button type="button" onclick="toggleTheaterMode()" id="btn-theater-mode" class="hidden lg:flex items-center gap-1 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-white rounded-xl font-bold text-[11px] transition" title="حالت سینمایی (تمام‌عرض کردن ویدیو)">
                        <span>↔ نمای سینمایی</span>
                    </button>
                </div>
            </div>

            <!-- چیدمان دو ستونه: ستون ویدیو و ابزارها (۸ واحد) + ستون سرفصل‌ها/زیرنویس/یادداشت‌ها (۴ واحد) -->
            <div id="player-main-grid" class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">

                <!-- ========================================== -->
                <!-- الف) ستون راست/اصلی: پلیر ویدیو، کنترل‌های آموزشی، جزوه و ثبت یادداشت -->
                <!-- ========================================== -->
                <div id="player-video-column" class="lg:col-span-8 space-y-3.5">

                    <!-- ۱. صحنه ویدیو + لایه زیرنویس سفارشی + نشانگرهای لمسی -->
                    <div id="video-stage-wrapper">

                        <!-- بنر کوچک اطلاع‌رسانی پرش خودکار به ثانیه قبلی (Auto-Resume Toast) -->
                        <div id="auto-resume-toast" class="hidden absolute top-3 right-3 left-3 sm:left-auto z-30 bg-slate-900 bg-opacity-90 text-white px-3.5 py-2 rounded-xl border border-indigo-400 shadow-xl flex items-center justify-between gap-3 text-xs">
                            <span id="auto-resume-toast-text" class="font-bold text-amber-300">▶️ ادامه پخش خودکار از دقیقه 00:00</span>
                            <div class="flex items-center gap-1.5 shrink-0">
                                <button type="button" onclick="restartVideoFromBeginning()" class="bg-indigo-600 hover:bg-indigo-500 text-white px-2.5 py-1 rounded-lg font-extrabold text-[11px]">
                                    🔄 پخش از اول
                                </button>
                                <button type="button" onclick="hideAutoResumeToast()" class="text-slate-400 hover:text-white px-1 font-bold">&times;</button>
                            </div>
                        </div>

                        <!-- نشانگر پخش سریع 2x هنگام نگه‌داشتن انگشت یا موس (Long-Press 2x) -->
                        <div id="speed-hold-indicator" class="hidden absolute top-4 left-1/2 transform -translate-x-1/2 z-30 bg-black bg-opacity-80 text-amber-300 px-3.5 py-1 rounded-full font-extrabold text-xs border border-amber-400 pointer-events-none">
                            ⏩ پخش سریع 2.0x
                        </div>

                        <!-- نشانگر پرش ۱۰ ثانیه‌ای با دو بار ضربه (Double-Tap Seek Feedback) -->
                        <div id="seek-feedback-badge" class="hidden absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 z-30 bg-black bg-opacity-75 text-white px-4 py-2 rounded-2xl font-black text-sm pointer-events-none">
                            ⏩ +10 ثانیه
                        </div>

                        <!-- تگ اصلی ویدیو -->
                        <video id="main-video-element" controls playsinline preload="metadata"></video>

                        <!-- لایه زیرنویس سفارشی (پشتیبانی از SRT و VTT با فونت وزیرمتن) -->
                        <div id="custom-subtitle-overlay" class="hidden">
                            <div id="custom-subtitle-text" dir="auto" style="font-size: 16px;"></div>
                        </div>
                    </div>

                    <!-- ۲. نوار کنترل‌های پیشرفته آموزشی (سرعت پخش، تکرار بازه A-B، زیرنویس، کیفیت، تصویر در تصویر) -->
                    <div class="bg-slate-900 text-white p-2.5 md:p-3 rounded-2xl shadow flex flex-wrap items-center justify-between gap-2 text-xs">
                        <!-- عقب و جلو بردن سریع + سرعت پخش -->
                        <div class="flex flex-wrap items-center gap-1.5">
                            <button type="button" onclick="seekVideoRelative(-10)" class="px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 rounded-xl font-bold text-[11px] flex items-center gap-1" title="۱۰ ثانیه به عقب">
                                <span>⏪ ۱۰- ثانیه</span>
                            </button>
                            <button type="button" onclick="seekVideoRelative(10)" class="px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 rounded-xl font-bold text-[11px] flex items-center gap-1" title="۱۰ ثانیه به جلو">
                                <span>۱۰+ ثانیه ⏩</span>
                            </button>

                            <div class="flex items-center gap-1 bg-slate-800 px-2 py-1 rounded-xl border border-slate-700">
                                <span class="text-[10px] text-slate-400">سرعت:</span>
                                <select id="player-speed-select" onchange="setVideoPlaybackSpeed(this.value)" dir="ltr" class="bg-transparent text-amber-300 font-extrabold text-xs focus:outline-none cursor-pointer">
                                    <option value="0.5" class="bg-slate-900">0.5x</option>
                                    <option value="0.75" class="bg-slate-900">0.75x</option>
                                    <option value="1" selected class="bg-slate-900">1.0x (عادی)</option>
                                    <option value="1.25" class="bg-slate-900">1.25x</option>
                                    <option value="1.5" class="bg-slate-900">1.5x</option>
                                    <option value="1.75" class="bg-slate-900">1.75x</option>
                                    <option value="2" class="bg-slate-900">2.0x</option>
                                    <option value="2.5" class="bg-slate-900">2.5x</option>
                                </select>
                            </div>
                        </div>

                        <!-- حلقه تکرار بازه A-B (برای مرور چندباره یک فرمول یا مبحث) -->
                        <div class="flex items-center gap-1 bg-slate-800 px-2.5 py-1 rounded-xl border border-slate-700 text-[11px]">
                            <span class="text-slate-400 font-bold">🔁 تکرار بازه:</span>
                            <button type="button" onclick="setAbLoopPoint('A')" id="btn-loop-a" class="px-2 py-0.5 bg-slate-700 hover:bg-indigo-600 rounded font-mono font-bold">A</button>
                            <button type="button" onclick="setAbLoopPoint('B')" id="btn-loop-b" class="px-2 py-0.5 bg-slate-700 hover:bg-indigo-600 rounded font-mono font-bold">B</button>
                            <button type="button" onclick="clearAbLoop()" id="btn-loop-clear" class="hidden text-rose-400 hover:text-rose-300 px-1 font-bold" title="لغو تکرار">✕</button>
                        </div>

                        <!-- کنترل‌های زیرنویس، تغییر سرور/کیفیت و تصویر در تصویر -->
                        <div class="flex flex-wrap items-center gap-1.5">
                            <button type="button" id="btn-switch-quality" onclick="switchVideoSourceQuality()" class="hidden px-2.5 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl font-bold text-[11px]">
                                🔄 سرور / کیفیت دوم
                            </button>

                            <div id="subtitle-controls-group" class="hidden items-center gap-1 bg-slate-800 px-2 py-1 rounded-xl border border-slate-700">
                                <button type="button" id="btn-toggle-sub" onclick="toggleSubtitleVisibility()" class="text-emerald-300 font-extrabold text-[11px]">
                                    💬 زیرنویس: روشن
                                </button>
                                <button type="button" onclick="changeSubtitleFontSize(2)" class="px-1.5 text-slate-300 hover:text-white font-bold" title="بزرگ کردن فونت زیرنویس">A+</button>
                                <button type="button" onclick="changeSubtitleFontSize(-2)" class="px-1.5 text-slate-300 hover:text-white font-bold" title="کوچک کردن فونت زیرنویس">A-</button>
                            </div>

                            <button type="button" onclick="togglePictureInPicture()" class="px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl font-bold text-[11px]" title="تصویر در تصویر شناور (PiP)">
                                🖼️ PiP
                            </button>
                        </div>
                    </div>

                    <!-- ۳. شناسنامه جلسه، دکمه دریافت جزوه، انتقال جزوه به جزوه‌خوان، آزمون جلسه و ثبت در تقویم -->
                    <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm space-y-3.5">
                        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 pb-3">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <span id="active-lesson-chapter-badge" class="bg-indigo-50 text-indigo-700 px-2.5 py-0.5 rounded-lg text-[11px] font-extrabold border border-indigo-100"></span>
                                    <span id="active-lesson-save-status" class="text-[11px] font-bold text-emerald-600">✓ ذخیره خودکار ثانیه پخش فعال</span>
                                </div>
                                <h1 id="active-lesson-title" class="text-sm md:text-base font-black text-slate-900 leading-snug"></h1>
                            </div>

                            <!-- دکمه‌های جلسه قبلی / تکمیل جلسه / جلسه بعدی -->
                            <div class="flex items-center gap-1.5 text-xs shrink-0">
                                <button type="button" onclick="navigateAdjacentLesson(-1)" id="btn-prev-lesson" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-bold transition">
                                    ❯ جلسه قبل
                                </button>
                                <button type="button" onclick="toggleActiveLessonCompleted()" id="btn-complete-lesson" class="px-3.5 py-1.5 bg-slate-100 hover:bg-emerald-50 text-slate-700 border border-slate-300 rounded-xl font-extrabold transition">
                                    ○ ثبت تکمیل جلسه
                                </button>
                                <button type="button" onclick="navigateAdjacentLesson(1)" id="btn-next-lesson" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-bold transition">
                                    جلسه بعد ❮
                                </button>
                            </div>
                        </div>

                        <!-- نوار طلایی دریافت جزوه جلسه، انتقال به جزوه‌خوان، شرکت در آزمون و ثبت در تقویم -->
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <!-- دکمه دانلود مستقیم جزوه همین جلسه -->
                            <a id="btn-download-lesson-booklet" href="#" target="_blank" download class="hidden bg-amber-400 hover:bg-amber-300 text-slate-950 px-4 py-2 rounded-xl font-black shadow-sm transition items-center gap-1.5">
                                <span>📥</span>
                                <span id="lbl-download-booklet">دانلود جزوه این جلسه</span>
                            </a>

                            <!-- دکمه انتقال یک‌کلیکی جزوه PDF این جلسه به بخش جزوه‌خوان برای هایلایت و حاشیه‌نویسی -->
                            <button type="button" id="btn-import-booklet-to-reader" onclick="importCurrentLessonBookletToReader()" class="hidden bg-indigo-50 hover:bg-indigo-100 text-indigo-800 border border-indigo-200 px-3.5 py-2 rounded-xl font-extrabold transition items-center gap-1.5">
                                <span>🖍️ افزودن جزوه به «جزوه‌خوان» برای هایلایت</span>
                            </button>

                            <!-- دکمه آزمون متصل به این جلسه -->
                            <a id="btn-lesson-linked-exam" href="#" class="hidden bg-rose-600 hover:bg-rose-500 text-white px-4 py-2 rounded-xl font-extrabold shadow-sm transition items-center gap-1.5">
                                <span>📝 شرکت در آزمونِ این جلسه</span>
                            </a>

                            <!-- دکمه ثبت تماشای این جلسه در تقویم مطالعاتی امروز -->
                            <button type="button" onclick="logCurrentLessonToCalendar()" class="bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 px-3.5 py-2 rounded-xl font-extrabold transition flex items-center gap-1.5 mr-auto">
                                <span>📅 ثبت در تقویم مطالعاتی امروز</span>
                            </button>
                        </div>

                        <!-- توضیحات جلسه -->
                        <div id="active-lesson-description" class="hidden text-xs text-slate-600 bg-slate-50 p-3 rounded-xl border border-slate-200 leading-relaxed whitespace-pre-line select-text"></div>

                        <!-- کادر ثبت سریع یادداشت زمان‌دار در حین تماشای ویدیو -->
                        <form onsubmit="saveTimestampedVideoNote(event)" class="pt-2 border-t border-slate-100 flex flex-col sm:flex-row items-stretch sm:items-center gap-2 text-xs">
                            <div class="flex items-center gap-1.5 flex-1 bg-slate-50 border border-slate-300 rounded-xl px-3 py-1.5">
                                <span id="live-note-time-badge" class="bg-indigo-600 text-white font-mono font-bold px-2 py-0.5 rounded text-[11px] shrink-0" dir="ltr">00:00</span>
                                <input type="text" id="quick-video-note-input" placeholder="نکته مهم این لحظه از ویدیو را بنویسید تا با ثانیه دقیق ذخیره شود..." class="w-full bg-transparent focus:outline-none text-slate-800 font-bold">
                            </div>
                            <button type="submit" class="bg-slate-900 hover:bg-slate-800 text-amber-300 px-4 py-2.5 rounded-xl font-extrabold shrink-0 transition">
                                📌 ثبت نکته در این ثانیه
                            </button>
                        </form>
                    </div>

                </div>

                <!-- ========================================== -->
                <!-- ب) ستون چپ: پنل ۳ تب (📚 سرفصل‌ها و جلسات | 📜 متن تعاملی زیرنویس | 📌 یادداشت‌های زمان‌دار) -->
                <!-- ========================================== -->
                <aside id="player-sidebar-column" class="lg:col-span-4 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">

                    <!-- تب‌های بالای سایدبار -->
                    <div class="bg-slate-900 text-white p-2 grid grid-cols-3 gap-1 text-[11px] font-bold">
                        <button type="button" id="stab-btn-lessons" onclick="switchPlayerSidebarTab('lessons')" class="py-2 px-2 rounded-xl bg-indigo-600 text-white font-extrabold transition text-center">
                            📚 جلسات دوره
                        </button>
                        <button type="button" id="stab-btn-transcript" onclick="switchPlayerSidebarTab('transcript')" class="py-2 px-2 rounded-xl text-slate-300 hover:text-white transition text-center">
                            📜 متن زیرنویس
                        </button>
                        <button type="button" id="stab-btn-notes" onclick="switchPlayerSidebarTab('notes')" class="py-2 px-2 rounded-xl text-slate-300 hover:text-white transition text-center">
                            📌 یادداشت‌ها (<span id="sidebar-notes-count">0</span>)
                        </button>
                    </div>

                    <!-- تب ۱: لیست درختی فصل‌ها و جلسات دوره (به همراه دکمه دانلود جزوه روی هر جلسه) -->
                    <div id="stab-content-lessons" class="p-3 space-y-3 max-h-[72vh] overflow-y-auto custom-scroll text-xs"></div>

                    <!-- تب ۲: متن تعاملی و قابل جستجوی زیرنویس (Interactive Live Transcript) -->
                    <div id="stab-content-transcript" class="hidden flex-col max-h-[72vh] text-xs">
                        <div class="p-2.5 bg-slate-50 border-b border-slate-200">
                            <input type="text" id="transcript-search-input" oninput="renderInteractiveTranscriptList()" placeholder="🔎 جستجوی کلمه در متن صحبت‌های استاد..." class="w-full px-3 py-1.5 bg-white border border-slate-300 rounded-xl text-xs focus:outline-none">
                        </div>
                        <div id="transcript-cues-list" class="p-3 space-y-1.5 overflow-y-auto custom-scroll flex-1 max-h-[62vh]"></div>
                    </div>

                    <!-- تب ۳: دفترچه یادداشت‌های زمان‌دار دانشجو روی این جلسه + خروجی Word -->
                    <div id="stab-content-notes" class="hidden flex-col max-h-[72vh] p-3 space-y-3 text-xs">
                        <div class="flex items-center justify-between bg-slate-50 p-2.5 rounded-xl border border-slate-200">
                            <span class="font-extrabold text-slate-700 text-[11px]">با کلیک روی زمان هر یادداشت، ویدیو به همان لحظه می‌پرد:</span>
                            <button type="button" onclick="exportVideoNotesToWord()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-2.5 py-1 rounded-lg font-bold text-[10px] shrink-0">
                                📘 دانلود Word
                            </button>
                        </div>
                        <div id="lesson-notes-list" class="space-y-2 overflow-y-auto custom-scroll max-h-[58vh]"></div>
                    </div>

                </aside>

            </div>

        </div>
    </section>

    <!-- ==================================================== -->
    <!-- ۴. مودال خرید و تمدید اشتراک ویژه (VIP) -->
    <!-- ==================================================== -->
    <div id="subscription-modal" class="fixed inset-0 bg-slate-900 bg-opacity-75 hidden items-end sm:items-center justify-center sm:p-4 z-50">
        <div class="bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl w-full max-w-2xl max-h-[94vh] flex flex-col overflow-hidden">
            <div class="bg-slate-900 text-white px-5 py-4 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-2.5">
                    <span class="text-2xl">👑</span>
                    <div>
                        <h3 class="font-extrabold text-sm md:text-base text-amber-300">اشتراک ویژه (VIP) سامانه مطالعه و آکادمی ویدیویی</h3>
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
                                <li>✓ تماشای نامحدود تمام دوره‌های ویدیویی و دانلود جزوات جلسات</li>
                                <li>✓ شرکت در تمام آزمون‌های شبیه‌ساز + آپلود نامحدود PDF</li>
                                <li>✓ مترجم تخصصی هوش مصنوعی + یادآور پیامکی تقویم</li>
                            </ul>
                        </div>
                        <a id="tg-link-1m" href="#" target="_blank" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-extrabold text-center shadow transition block">
                            ✈️ سفارش ۱ ماهه در تلگرام
                        </a>
                    </div>

                    <div class="border-2 border-emerald-500 rounded-2xl p-4 flex flex-col justify-between bg-emerald-50 relative shadow-md">
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
                                <li>✓ دسترسی کامل به تمام ویدیوها، جزوات، آزمون‌ها و مقالات</li>
                                <li>✓ آپلود نامحدود PDF + هوش مصنوعی + یادآور پیامکی</li>
                            </ul>
                        </div>
                        <a id="tg-link-3m" href="#" target="_blank" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-extrabold text-center shadow transition block">
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

        // متغیرهای لابی دوره‌ها
        let allCoursesList       = [];
        let selectedCourseCat    = 'all';
        let globalLastWatchedObj = null;

        // متغیرهای دوره و جلسه فعال در پلیر
        let activeCourseData     = null;
        let activeChaptersTree   = [];
        let flatLessonsOrder     = [];
        let activeLessonObj      = null;
        let activeLessonNotes    = [];
        let isUsingBackupVideo   = false;
        let isTheaterMode        = false;

        // متغیرهای پلیر، ذخیره خودکار ثانیه (Auto-Resume) و حلقه تکرار A-B
        const videoEl            = document.getElementById('main-video-element');
        let lastSavedSecond      = -1;
        let lastSaveTimestampMs  = 0;
        let pendingResumeSec     = 0;
        let loopPointA           = null;
        let loopPointB           = null;

        // متغیرهای موتور زیرنویس (.srt / .vtt)
        let parsedSubtitleCues   = [];
        let isSubtitleVisible    = true;
        let subtitleFontSizePx   = 16;
        let activeCueIndex       = -1;

        function formatSecondsToClock(sec) {
            const s = Math.max(0, Math.floor(Number(sec) || 0));
            const h = Math.floor(s / 3600);
            const m = Math.floor((s % 3600) / 60);
            const r = s % 60;
            if (h > 0) {
                return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(r).padStart(2, '0')}`;
            }
            return `${String(m).padStart(2, '0')}:${String(r).padStart(2, '0')}`;
        }

        // ====================================================
        // ۱. بارگذاری ویترین دوره‌ها و بنر ادامه آخرین جلسه
        // ====================================================
        async function fetchLobbyCourses() {
            try {
                const res = await fetch('api_courses.php?action=list_courses');
                const data = await res.json();
                if (data.status === 'success') {
                    allCoursesList       = data.courses || [];
                    globalLastWatchedObj = data.global_last_watched || null;
                    if (data.subscription) userSub = data.subscription;

                    renderGlobalResumeBanner();
                    renderCourseCategoryPills();
                    renderLobbyCoursesGrid();
                }
            } catch (e) {
                console.error('Error loading courses:', e);
            }
        }

        function renderGlobalResumeBanner() {
            const banner = document.getElementById('global-resume-banner');
            if (!globalLastWatchedObj || !globalLastWatchedObj.course_id || !globalLastWatchedObj.lesson_id) {
                banner.classList.add('hidden');
                return;
            }
            const cObj = allCoursesList.find(c => Number(c.id) === Number(globalLastWatchedObj.course_id));
            if (!cObj) {
                banner.classList.add('hidden');
                return;
            }

            document.getElementById('global-resume-title').textContent = `${cObj.title} — ${globalLastWatchedObj.lesson_title}`;
            document.getElementById('global-resume-time').textContent  = `توقف قبلی شما در دقیقه ${formatSecondsToClock(globalLastWatchedObj.current_time_sec)}`;
            document.getElementById('btn-global-resume').onclick = () => {
                openCourseStudio(globalLastWatchedObj.course_id, globalLastWatchedObj.lesson_id);
            };
            banner.classList.remove('hidden');
        }

        function renderCourseCategoryPills() {
            const cats = Array.from(new Set(allCoursesList.map(c => c.category || 'عمومی')));
            const container = document.getElementById('courses-category-pills');

            let html = `
                <button type="button" onclick="filterCoursesByCategory('all')" class="px-3.5 py-1.5 rounded-xl font-extrabold transition ${selectedCourseCat === 'all' ? 'bg-indigo-600 text-white shadow' : 'bg-white text-slate-700 border border-slate-200'}">
                    همه دوره‌ها (${allCoursesList.length})
                </button>
            `;
            cats.forEach(cat => {
                const isSel = (selectedCourseCat === cat);
                html += `
                    <button type="button" onclick="filterCoursesByCategory('${escapeHtml(cat)}')" class="px-3.5 py-1.5 rounded-xl font-bold transition ${isSel ? 'bg-indigo-600 text-white shadow' : 'bg-white text-slate-700 border border-slate-200'}">
                        🎬 ${escapeHtml(cat)}
                    </button>
                `;
            });
            container.innerHTML = html;
        }

        function filterCoursesByCategory(cat) {
            selectedCourseCat = cat;
            renderCourseCategoryPills();
            renderLobbyCoursesGrid();
        }

        function renderLobbyCoursesGrid() {
            const gridEl = document.getElementById('lobby-courses-grid');
            const filtered = allCoursesList.filter(c => selectedCourseCat === 'all' || (c.category || 'عمومی') === selectedCourseCat);
            document.getElementById('lobby-courses-count').textContent = `${filtered.length} دوره آموزشی فعال`;

            if (filtered.length === 0) {
                gridEl.innerHTML = `
                    <div class="col-span-full bg-white rounded-2xl border border-slate-200 p-10 text-center text-slate-400 text-xs">
                        هنوز دوره ویدیویی در این دسته منتشر نشده است.
                    </div>
                `;
                return;
            }

            gridEl.innerHTML = filtered.map(c => {
                const isVip      = Number(c.is_vip_only) === 1;
                const totalHours = c.total_duration_sec > 0 ? Math.max(0.5, +(c.total_duration_sec / 3600).toFixed(1)) + ' ساعت' : `${c.lessons_count} جلسه`;
                const pct        = Number(c.progress_percent) || 0;
                const lastW      = c.last_watched;

                const coverHtml = c.cover_image
                    ? `<img src="${escapeHtml(c.cover_image)}" alt="${escapeHtml(c.title)}" class="w-full h-44 object-cover">`
                    : `<div class="w-full h-44 bg-slate-900 flex flex-col items-center justify-center text-white p-4 text-center">
                           <span class="text-4xl mb-2">🎬</span>
                           <span class="font-extrabold text-xs text-amber-300 line-clamp-2">${escapeHtml(c.title)}</span>
                       </div>`;

                return `
                    <div onclick="openCourseStudio(${c.id}, ${lastW ? lastW.lesson_id : 'null'})" class="bg-white rounded-3xl border border-slate-200 hover:border-indigo-400 overflow-hidden shadow-sm flex flex-col justify-between cursor-pointer transition">
                        <div>
                            <div class="relative">
                                ${coverHtml}
                                <span class="absolute top-3 right-3 bg-slate-900 bg-opacity-85 text-white px-2.5 py-1 rounded-xl text-[10px] font-extrabold">
                                    📚 ${escapeHtml(c.category || 'عمومی')}
                                </span>
                                ${isVip
                                    ? `<span class="absolute top-3 left-3 bg-amber-400 text-slate-950 px-2.5 py-1 rounded-xl text-[10px] font-black shadow">👑 ویژه VIP</span>`
                                    : `<span class="absolute top-3 left-3 bg-emerald-500 text-white px-2.5 py-1 rounded-xl text-[10px] font-black shadow">🎁 رایگان</span>`
                                }
                            </div>

                            <div class="p-4 space-y-2.5">
                                <h3 class="font-black text-sm text-slate-900 leading-snug">${escapeHtml(c.title)}</h3>
                                ${c.instructor ? `<div class="text-[11px] font-bold text-indigo-700">👨‍🏫 مدرس: ${escapeHtml(c.instructor)}</div>` : ''}
                                ${c.description ? `<p class="text-[11px] text-slate-500 leading-relaxed line-clamp-2">${escapeHtml(c.description)}</p>` : ''}

                                <div class="grid grid-cols-3 gap-1.5 bg-slate-50 p-2 rounded-xl border border-slate-200 text-center text-[10px] font-bold text-slate-600">
                                    <div>📁 ${c.chapters_count} فصل</div>
                                    <div>🎥 ${c.lessons_count} جلسه</div>
                                    <div>⏱️ ${totalHours}</div>
                                </div>
                            </div>
                        </div>

                        <div class="px-4 pb-4 space-y-2.5">
                            <div>
                                <div class="flex justify-between text-[10px] font-bold text-slate-500 mb-1">
                                    <span>پیشرفت شما (${c.completed_lessons} از ${c.lessons_count} جلسه)</span>
                                    <span class="text-emerald-700 font-extrabold">${pct}%</span>
                                </div>
                                <div class="w-full h-1.5 bg-slate-200 rounded-full overflow-hidden">
                                    <div class="h-full bg-emerald-500 rounded-full" style="width:${pct}%"></div>
                                </div>
                            </div>

                            <button type="button" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-extrabold text-xs shadow transition flex items-center justify-center gap-1.5">
                                <span>${lastW ? '▶️ ادامه تماشای دوره' : '🚀 ورود به جلسات دوره'}</span>
                            </button>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // ====================================================
        // ۲. باز کردن استودیوی پخش دوره و درخت سرفصل‌ها / جلسات
        // ====================================================
        async function openCourseStudio(courseId, targetLessonId = null) {
            try {
                const res = await fetch(`api_courses.php?action=get_course_details&course_id=${courseId}`);
                const data = await res.json();
                if (data.status !== 'success') {
                    alert(data.message || 'خطا در بارگذاری دوره');
                    return;
                }

                activeCourseData   = data.course;
                activeChaptersTree = data.chapters || [];
                if (data.subscription) userSub = data.subscription;

                flatLessonsOrder = [];
                activeChaptersTree.forEach(ch => {
                    (ch.lessons || []).forEach(ls => {
                        flatLessonsOrder.push(ls);
                    });
                });

                document.getElementById('player-course-title').textContent = activeCourseData.title;
                updateCourseOverallProgressUI(data.progress_percent || 0);

                document.getElementById('view-courses-lobby').classList.add('hidden');
                document.getElementById('view-course-player').classList.remove('hidden');

                renderPlayerChaptersTree();

                const startLid = targetLessonId || data.resume_lesson_id || (flatLessonsOrder[0] ? flatLessonsOrder[0].id : null);
                if (startLid) {
                    await loadAndPlayLesson(startLid);
                }
            } catch (e) {
                alert('خطا در ارتباط با سرور.');
            }
        }

        function updateCourseOverallProgressUI(pct) {
            document.getElementById('player-course-progress-bar').style.width = `${pct}%`;
            document.getElementById('player-course-progress-text').textContent = `${pct}%`;
        }

        function renderPlayerChaptersTree() {
            const container = document.getElementById('stab-content-lessons');
            if (activeChaptersTree.length === 0) {
                container.innerHTML = `<div class="text-center text-slate-400 py-8">هنوز جلسه‌ای در این دوره ثبت نشده است.</div>`;
                return;
            }

            container.innerHTML = activeChaptersTree.map((ch, cIdx) => {
                const lessons = ch.lessons || [];
                const lessonsHtml = lessons.map((ls, lIdx) => {
                    const isActive  = activeLessonObj && Number(activeLessonObj.id) === Number(ls.id);
                    const isLocked  = !!ls.is_locked;
                    const prog      = ls.user_progress || {};
                    const isDone    = !!prog.is_completed;
                    const curSec    = Number(prog.current_time_sec) || 0;
                    const durSec    = Number(prog.duration_sec) || Number(ls.duration_sec) || 0;
                    const watchPct  = (durSec > 0 && curSec > 0) ? Math.min(100, Math.round((curSec / durSec) * 100)) : 0;

                    return `
                        <div onclick="loadAndPlayLesson(${ls.id})" class="p-2.5 rounded-xl border transition cursor-pointer space-y-1.5 ${isActive ? 'bg-indigo-50 border-indigo-500 ring-1 ring-indigo-500' : 'bg-white border-slate-200 hover:border-indigo-300'} ${isLocked ? 'opacity-80' : ''}">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex items-start gap-1.5 min-w-0">
                                    <span class="mt-0.5 text-xs shrink-0">${isLocked ? '🔒' : (isDone ? '✅' : (isActive ? '▶️' : '🎥'))}</span>
                                    <div class="font-extrabold text-slate-800 leading-snug text-xs">
                                        ${lIdx + 1}. ${escapeHtml(ls.title)}
                                    </div>
                                </div>
                                ${durSec > 0 ? `<span class="text-[10px] font-mono text-slate-500 shrink-0" dir="ltr">${formatSecondsToClock(durSec)}</span>` : ''}
                            </div>

                            <!-- برچسب‌های جزوه، زیرنویس و نوار پیشرفت هر جلسه -->
                            <div class="flex flex-wrap items-center justify-between gap-1 pt-0.5">
                                <div class="flex flex-wrap items-center gap-1 text-[10px] font-bold">
                                    ${Number(ls.is_free_preview) === 1 ? `<span class="bg-emerald-100 text-emerald-800 px-1.5 py-0.5 rounded">🎁 رایگان</span>` : ''}
                                    ${!isLocked && ls.subtitle_url ? `<span class="bg-indigo-100 text-indigo-800 px-1.5 py-0.5 rounded">💬 زیرنویس</span>` : ''}
                                    ${!isLocked && ls.booklet_url ? `
                                        <a href="${escapeHtml(ls.booklet_url)}" target="_blank" download onclick="event.stopPropagation()" class="bg-amber-400 hover:bg-amber-300 text-slate-950 px-2 py-0.5 rounded font-extrabold flex items-center gap-0.5">
                                            <span>📥 جزوه جلسه</span>
                                        </a>
                                    ` : ''}
                                </div>
                                ${curSec > 5 && !isDone ? `<span class="text-[10px] text-indigo-700 font-bold">ادامه از ${formatSecondsToClock(curSec)}</span>` : ''}
                            </div>

                            ${watchPct > 0 ? `
                                <div class="w-full h-1 bg-slate-200 rounded-full overflow-hidden">
                                    <div class="h-full ${isDone ? 'bg-emerald-500' : 'bg-indigo-600'}" style="width:${isDone ? 100 : watchPct}%"></div>
                                </div>
                            ` : ''}
                        </div>
                    `;
                }).join('');

                return `
                    <div class="bg-slate-50 rounded-2xl border border-slate-200 overflow-hidden">
                        <div class="bg-slate-200 px-3 py-2 font-extrabold text-slate-800 flex items-center justify-between">
                            <span>📁 فصل ${cIdx + 1}: ${escapeHtml(ch.title)}</span>
                            <span class="text-[10px] bg-white text-slate-600 px-2 py-0.5 rounded-full">${lessons.length} جلسه</span>
                        </div>
                        <div class="p-2 space-y-2">
                            ${lessonsHtml}
                        </div>
                    </div>
                `;
            }).join('');
        }

        // ====================================================
        // ۳. بارگذاری جلسه ویدیویی، ادامه خودکار از ثانیه قبلی (Auto-Resume) و راه‌اندازی زیرنویس
        // ====================================================
        async function loadAndPlayLesson(lessonId) {
            // قبل از تعویض جلسه، ثانیه فعلی جلسه قبلی را ذخیره کن
            await flushCurrentVideoProgress(true);

            try {
                const res = await fetch(`api_courses.php?action=get_lesson_data&lesson_id=${lessonId}`);
                const data = await res.json();

                if (data.status !== 'success') {
                    if (data.need_vip) {
                        openSubscriptionModal(data.message);
                    } else {
                        alert(data.message || 'خطا در بارگذاری جلسه');
                    }
                    return;
                }

                activeLessonObj    = data.lesson;
                activeLessonNotes  = data.notes || [];
                isUsingBackupVideo = false;
                clearAbLoop();

                // بروزرسانی اطلاعات ظاهری جلسه
                document.getElementById('active-lesson-chapter-badge').textContent = `📁 ${activeLessonObj.chapter_title}`;
                document.getElementById('active-lesson-title').textContent         = activeLessonObj.title;

                const descEl = document.getElementById('active-lesson-description');
                if (activeLessonObj.description && activeLessonObj.description.trim() !== '') {
                    descEl.textContent = activeLessonObj.description;
                    descEl.classList.remove('hidden');
                } else {
                    descEl.classList.add('hidden');
                }

                // دکمه کیفیت/سرور دوم
                const btnQuality = document.getElementById('btn-switch-quality');
                if (activeLessonObj.video_url_backup && activeLessonObj.video_url_backup.trim() !== '') {
                    btnQuality.classList.remove('hidden');
                    btnQuality.textContent = '🔄 سوییچ به سرور / کیفیت دوم';
                } else {
                    btnQuality.classList.add('hidden');
                }

                // دکمه‌های دانلود جزوه جلسه و انتقال به جزوه‌خوان
                const btnDlBooklet  = document.getElementById('btn-download-lesson-booklet');
                const btnImpBooklet = document.getElementById('btn-import-booklet-to-reader');
                if (activeLessonObj.booklet_url && activeLessonObj.booklet_url.trim() !== '') {
                    btnDlBooklet.href = activeLessonObj.booklet_url;
                    document.getElementById('lbl-download-booklet').textContent = activeLessonObj.booklet_title || 'دانلود جزوه این جلسه';
                    btnDlBooklet.classList.remove('hidden');
                    btnDlBooklet.classList.add('inline-flex');

                    if (activeLessonObj.booklet_url.toLowerCase().endsWith('.pdf')) {
                        btnImpBooklet.classList.remove('hidden');
                        btnImpBooklet.classList.add('inline-flex');
                    } else {
                        btnImpBooklet.classList.add('hidden');
                    }
                } else {
                    btnDlBooklet.classList.add('hidden');
                    btnDlBooklet.classList.remove('inline-flex');
                    btnImpBooklet.classList.add('hidden');
                    btnImpBooklet.classList.remove('inline-flex');
                }

                // دکمه آزمون متصل به این جلسه
                const btnExam = document.getElementById('btn-lesson-linked-exam');
                if (Number(activeLessonObj.linked_exam_id) > 0) {
                    btnExam.href = `exam.php?exam_id=${activeLessonObj.linked_exam_id}`;
                    btnExam.classList.remove('hidden');
                    btnExam.classList.add('inline-flex');
                } else {
                    btnExam.classList.add('hidden');
                    btnExam.classList.remove('inline-flex');
                }

                // وضعیت دکمه تکمیل جلسه و دکمه‌های قبلی/بعدی
                updateLessonCompleteButtonUI(data.progress?.is_completed || false);
                updateAdjacentLessonButtonsUI();
                renderPlayerChaptersTree();
                renderLessonNotesList();

                // تنظیم منبع ویدیو و پرش خودکار به ثانیه ذخیره‌شده قبلی (Auto-Resume)
                const savedSec = Number(data.progress?.current_time_sec || 0);
                pendingResumeSec = savedSec > 5 ? savedSec : 0;
                lastSavedSecond  = savedSec;

                videoEl.src = activeLessonObj.video_url;
                videoEl.load();

                const savedSpeed = parseFloat(localStorage.getItem('academy_video_speed') || '1') || 1;
                document.getElementById('player-speed-select').value = String(savedSpeed);
                videoEl.playbackRate = savedSpeed;

                // بارگذاری زیرنویس جلسه (در صورت وجود)
                await loadLessonSubtitles(activeLessonObj.id, activeLessonObj.subtitle_url);
            } catch (e) {
                console.error('Error loading lesson:', e);
            }
        }

        // وقتی متادیتای ویدیو لود شد، اگر کاربر قبلاً این ویدیو را تا ثانیه خاصی دیده بود، دقیقاً به همان ثانیه بپرد!
        videoEl.addEventListener('loadedmetadata', () => {
            if (pendingResumeSec > 5 && pendingResumeSec < (videoEl.duration - 8)) {
                videoEl.currentTime = pendingResumeSec;
                const toast = document.getElementById('auto-resume-toast');
                document.getElementById('auto-resume-toast-text').textContent = `▶️ ادامه پخش خودکار از دقیقه ${formatSecondsToClock(pendingResumeSec)}`;
                toast.classList.remove('hidden');
            } else {
                hideAutoResumeToast();
            }
        });

        function restartVideoFromBeginning() {
            pendingResumeSec = 0;
            videoEl.currentTime = 0;
            hideAutoResumeToast();
            videoEl.play().catch(() => {});
        }

        function hideAutoResumeToast() {
            document.getElementById('auto-resume-toast').classList.add('hidden');
        }

        // ====================================================
        // ۴. ذخیره خودکار ثانیه ویدیو در دیتابیس (هر ۵ ثانیه + هنگام مکث + هنگام بستن تب مرورگر)
        // ====================================================
        async function flushCurrentVideoProgress(forceNow = false) {
            if (!activeLessonObj || !activeCourseData) return;
            const curSec = Math.floor(videoEl.currentTime || 0);
            const durSec = Math.floor(videoEl.duration || activeLessonObj.duration_sec || 0);

            if (curSec <= 2) return;
            if (!forceNow && Math.abs(curSec - lastSavedSecond) < 5) return;

            lastSavedSecond     = curSec;
            lastSaveTimestampMs = Date.now();

            // بروزرسانی در حافظه محلی لیست جلسات
            const flatItem = flatLessonsOrder.find(x => Number(x.id) === Number(activeLessonObj.id));
            if (flatItem && flatItem.user_progress) {
                flatItem.user_progress.current_time_sec = curSec;
                if (durSec > 0) flatItem.user_progress.duration_sec = durSec;
            }

            const statusEl = document.getElementById('active-lesson-save-status');
            if (statusEl) statusEl.textContent = `✓ ذخیره خودکار در ${formatSecondsToClock(curSec)}`;

            try {
                const fd = new FormData();
                fd.append('action', 'save_progress');
                fd.append('course_id', activeCourseData.id);
                fd.append('lesson_id', activeLessonObj.id);
                fd.append('current_time_sec', curSec);
                fd.append('duration_sec', durSec);

                const res = await fetch('api_courses.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success' && data.is_completed) {
                    updateLessonCompleteButtonUI(true);
                    if (flatItem && flatItem.user_progress) {
                        flatItem.user_progress.is_completed = true;
                    }
                    renderPlayerChaptersTree();
                }
            } catch (ignore) {}
        }

        // ذخیره فوری هنگام بستن تب مرورگر یا رفرش صفحه با استفاده از sendBeacon
        function beaconSaveOnUnload() {
            if (!activeLessonObj || !activeCourseData) return;
            const curSec = Math.floor(videoEl.currentTime || 0);
            const durSec = Math.floor(videoEl.duration || activeLessonObj.duration_sec || 0);
            if (curSec <= 2) return;

            const fd = new FormData();
            fd.append('action', 'save_progress');
            fd.append('course_id', activeCourseData.id);
            fd.append('lesson_id', activeLessonObj.id);
            fd.append('current_time_sec', curSec);
            fd.append('duration_sec', durSec);

            if (navigator.sendBeacon) {
                navigator.sendBeacon('api_courses.php', fd);
            }
        }

        window.addEventListener('beforeunload', beaconSaveOnUnload);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') {
                beaconSaveOnUnload();
            }
        });

        videoEl.addEventListener('pause', () => {
            flushCurrentVideoProgress(true);
        });

        videoEl.addEventListener('ended', () => {
            flushCurrentVideoProgress(true);
        });

        // ====================================================
        // ۵. موتور زیرنویس‌خوان اختصاصی (.srt و .vtt) + متن تعاملی زیرنویس
        // ====================================================
        function parseTimecodeToSeconds(tcStr) {
            const clean = tcStr.trim().replace(',', '.');
            const parts = clean.split(':');
            if (parts.length === 3) {
                return (parseFloat(parts[0]) * 3600) + (parseFloat(parts[1]) * 60) + parseFloat(parts[2]);
            } else if (parts.length === 2) {
                return (parseFloat(parts[0]) * 60) + parseFloat(parts[1]);
            }
            return 0;
        }

        function parseSrtOrVttContent(rawText) {
            const normalized = String(rawText || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const blocks = normalized.split(/\n\s*\n/);
            const cues = [];

            blocks.forEach(block => {
                const lines = block.trim().split('\n');
                if (lines.length === 0) return;

                let timeLineIdx = -1;
                for (let i = 0; i < lines.length; i++) {
                    if (lines[i].includes('-->')) {
                        timeLineIdx = i;
                        break;
                    }
                }
                if (timeLineIdx === -1) return;

                const timeParts = lines[timeLineIdx].split('-->');
                if (timeParts.length < 2) return;

                const startSec = parseTimecodeToSeconds(timeParts[0]);
                const endRaw   = timeParts[1].trim().split(/\s+/)[0];
                const endSec   = parseTimecodeToSeconds(endRaw);

                const textLines = lines.slice(timeLineIdx + 1)
                    .map(l => l.replace(/<[^>]+>/g, '').trim())
                    .filter(Boolean);

                if (textLines.length > 0 && endSec > startSec) {
                    cues.push({
                        start: startSec,
                        end: endSec,
                        text: textLines.join('\n')
                    });
                }
            });

            return cues;
        }

        async function loadLessonSubtitles(lessonId, subtitleUrl) {
            parsedSubtitleCues = [];
            activeCueIndex     = -1;
            const overlay      = document.getElementById('custom-subtitle-overlay');
            const ctrlGroup    = document.getElementById('subtitle-controls-group');
            const cuesListEl   = document.getElementById('transcript-cues-list');

            overlay.classList.add('hidden');

            if (!subtitleUrl || String(subtitleUrl).trim() === '') {
                ctrlGroup.classList.add('hidden');
                ctrlGroup.classList.remove('flex');
                cuesListEl.innerHTML = `<div class="text-center text-slate-400 py-10">برای این جلسه فایل زیرنویسی ثبت نشده است.</div>`;
                return;
            }

            try {
                const res = await fetch(`api_courses.php?action=get_subtitle_raw&lesson_id=${lessonId}`);
                const data = await res.json();
                if (data.status === 'success' && data.content) {
                    parsedSubtitleCues = parseSrtOrVttContent(data.content);
                }
            } catch (e) {
                console.error('Subtitle load error:', e);
            }

            if (parsedSubtitleCues.length > 0) {
                ctrlGroup.classList.remove('hidden');
                ctrlGroup.classList.add('flex');
                renderInteractiveTranscriptList();
            } else {
                ctrlGroup.classList.add('hidden');
                ctrlGroup.classList.remove('flex');
                cuesListEl.innerHTML = `<div class="text-center text-slate-400 py-10">زیرنویسی در فایل یافت نشد.</div>`;
            }
        }

        function renderInteractiveTranscriptList() {
            const listEl = document.getElementById('transcript-cues-list');
            const query  = (document.getElementById('transcript-search-input')?.value || '').trim().toLowerCase();

            if (parsedSubtitleCues.length === 0) {
                listEl.innerHTML = `<div class="text-center text-slate-400 py-10">متن زیرنویس برای این جلسه موجود نیست.</div>`;
                return;
            }

            const html = parsedSubtitleCues.map((cue, idx) => {
                if (query && !cue.text.toLowerCase().includes(query)) {
                    return '';
                }
                const isAct = (idx === activeCueIndex);
                return `
                    <div id="transcript-cue-${idx}" onclick="jumpVideoToSecond(${cue.start})" class="cue-item p-2.5 rounded-xl border border-slate-200 hover:border-indigo-300 bg-slate-50 cursor-pointer transition flex items-start gap-2 ${isAct ? 'active-cue font-extrabold' : 'text-slate-700'}">
                        <span class="bg-indigo-100 text-indigo-800 font-mono text-[10px] font-bold px-1.5 py-0.5 rounded shrink-0" dir="ltr">${formatSecondsToClock(cue.start)}</span>
                        <span dir="auto" class="leading-relaxed flex-1">${escapeHtml(cue.text)}</span>
                    </div>
                `;
            }).join('');

            listEl.innerHTML = html || `<div class="text-center text-slate-400 py-8">عبارت مورد نظر در زیرنویس یافت نشد.</div>`;
        }

        function toggleSubtitleVisibility() {
            isSubtitleVisible = !isSubtitleVisible;
            const btn = document.getElementById('btn-toggle-sub');
            const overlay = document.getElementById('custom-subtitle-overlay');
            if (isSubtitleVisible) {
                btn.textContent = '💬 زیرنویس: روشن';
                btn.className   = 'text-emerald-300 font-extrabold text-[11px]';
            } else {
                btn.textContent = '💬 زیرنویس: خاموش';
                btn.className   = 'text-slate-400 font-bold text-[11px]';
                overlay.classList.add('hidden');
            }
        }

        function changeSubtitleFontSize(delta) {
            subtitleFontSizePx = Math.max(12, Math.min(28, subtitleFontSizePx + delta));
            document.getElementById('custom-subtitle-text').style.fontSize = subtitleFontSizePx + 'px';
        }

        // رویداد لحظه‌ای پخش ویدیو: بروزرسانی زیرنویس، حلقه تکرار A-B، نشانگر زمان یادداشت و ذخیره خودکار هر ۵ ثانیه
        videoEl.addEventListener('timeupdate', () => {
            const curTime = videoEl.currentTime || 0;

            // ۱. بروزرسانی نشانگر ثانیه کنار کادر ثبت یادداشت
            const noteBadge = document.getElementById('live-note-time-badge');
            if (noteBadge) noteBadge.textContent = formatSecondsToClock(curTime);

            // ۲. بررسی حلقه تکرار A-B
            if (loopPointA !== null && loopPointB !== null && curTime >= loopPointB) {
                videoEl.currentTime = loopPointA;
                return;
            }

            // ۳. نمایش زیرنویس سفارشی روی ویدیو و هایلایت در تب متن زیرنویس
            if (parsedSubtitleCues.length > 0) {
                const foundIdx = parsedSubtitleCues.findIndex(c => curTime >= c.start && curTime <= c.end);
                const overlay  = document.getElementById('custom-subtitle-overlay');
                const subText  = document.getElementById('custom-subtitle-text');

                if (foundIdx !== -1 && isSubtitleVisible) {
                    subText.textContent = parsedSubtitleCues[foundIdx].text;
                    overlay.classList.remove('hidden');
                } else {
                    overlay.classList.add('hidden');
                }

                if (foundIdx !== activeCueIndex) {
                    activeCueIndex = foundIdx;
                    document.querySelectorAll('.cue-item').forEach(el => el.classList.remove('active-cue', 'font-extrabold'));
                    if (foundIdx !== -1) {
                        const activeEl = document.getElementById('transcript-cue-' + foundIdx);
                        if (activeEl) {
                            activeEl.classList.add('active-cue', 'font-extrabold');
                            if (!document.getElementById('stab-content-transcript').classList.contains('hidden')) {
                                activeEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                            }
                        }
                    }
                }
            }

            // ۴. ذخیره خودکار در دیتابیس هر ۵ ثانیه
            if (Date.now() - lastSaveTimestampMs > 5000) {
                flushCurrentVideoProgress(false);
            }
        });

        // ====================================================
        // ۶. کنترل‌های پلیر آموزشی، ژست‌های لمسی موبایل (2x Hold / Double-Tap) و کلیدهای میانبر
        // ====================================================
        function seekVideoRelative(deltaSec) {
            if (!videoEl.duration) return;
            videoEl.currentTime = Math.max(0, Math.min(videoEl.duration, (videoEl.currentTime || 0) + deltaSec));
            showSeekFeedbackBadge(deltaSec > 0 ? `⏩ +${deltaSec} ثانیه` : `⏪ ${deltaSec} ثانیه`);
        }

        function jumpVideoToSecond(sec) {
            videoEl.currentTime = Math.max(0, Number(sec) || 0);
            videoEl.play().catch(() => {});
        }

        function showSeekFeedbackBadge(text) {
            const b = document.getElementById('seek-feedback-badge');
            b.textContent = text;
            b.classList.remove('hidden');
            setTimeout(() => b.classList.add('hidden'), 650);
        }

        function setVideoPlaybackSpeed(val) {
            const rate = parseFloat(val) || 1.0;
            videoEl.playbackRate = rate;
            localStorage.setItem('academy_video_speed', String(rate));
        }

        function setAbLoopPoint(point) {
            const cur = Math.floor(videoEl.currentTime || 0);
            if (point === 'A') {
                loopPointA = cur;
                document.getElementById('btn-loop-a').textContent = `A: ${formatSecondsToClock(cur)}`;
                document.getElementById('btn-loop-a').className   = 'px-2 py-0.5 bg-indigo-600 text-white rounded font-mono font-bold';
            } else {
                if (loopPointA === null) loopPointA = Math.max(0, cur - 10);
                if (cur <= loopPointA) {
                    alert('نقطه پایان (B) باید بعد از نقطه شروع (A) باشد.');
                    return;
                }
                loopPointB = cur;
                document.getElementById('btn-loop-b').textContent = `B: ${formatSecondsToClock(cur)}`;
                document.getElementById('btn-loop-b').className   = 'px-2 py-0.5 bg-indigo-600 text-white rounded font-mono font-bold';
                document.getElementById('btn-loop-clear').classList.remove('hidden');
            }
        }

        function clearAbLoop() {
            loopPointA = null;
            loopPointB = null;
            document.getElementById('btn-loop-a').textContent = 'A';
            document.getElementById('btn-loop-a').className   = 'px-2 py-0.5 bg-slate-700 hover:bg-indigo-600 rounded font-mono font-bold';
            document.getElementById('btn-loop-b').textContent = 'B';
            document.getElementById('btn-loop-b').className   = 'px-2 py-0.5 bg-slate-700 hover:bg-indigo-600 rounded font-mono font-bold';
            document.getElementById('btn-loop-clear').classList.add('hidden');
        }

        function switchVideoSourceQuality() {
            if (!activeLessonObj || !activeLessonObj.video_url_backup) return;
            const curSec    = videoEl.currentTime || 0;
            const wasPaused = videoEl.paused;

            isUsingBackupVideo = !isUsingBackupVideo;
            const btn = document.getElementById('btn-switch-quality');
            btn.textContent = isUsingBackupVideo ? '🔄 بازگشت به سرور اصلی' : '🔄 سوییچ به سرور / کیفیت دوم';

            pendingResumeSec = curSec;
            videoEl.src = isUsingBackupVideo ? activeLessonObj.video_url_backup : activeLessonObj.video_url;
            videoEl.load();
            if (!wasPaused) {
                videoEl.play().catch(() => {});
            }
        }

        async function togglePictureInPicture() {
            try {
                if (document.pictureInPictureElement) {
                    await document.exitPictureInPicture();
                } else if (videoEl.requestPictureInPicture) {
                    await videoEl.requestPictureInPicture();
                }
            } catch (e) {
                alert('مرورگر شما از حالت تصویر در تصویر پشتیبانی نمی‌کند.');
            }
        }

        function toggleTheaterMode() {
            isTheaterMode = !isTheaterMode;
            const vidCol  = document.getElementById('player-video-column');
            const sideCol = document.getElementById('player-sidebar-column');
            const btn     = document.getElementById('btn-theater-mode');

            if (isTheaterMode) {
                vidCol.className  = 'lg:col-span-12 space-y-3.5';
                sideCol.className = 'lg:col-span-12 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden flex flex-col';
                btn.innerHTML     = '<span>🗗 نمای عادی</span>';
            } else {
                vidCol.className  = 'lg:col-span-8 space-y-3.5';
                sideCol.className = 'lg:col-span-4 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden flex flex-col';
                btn.innerHTML     = '<span>↔ نمای سینمایی</span>';
            }
        }

        // ژست‌های لمسی روی ویدیو (نگه‌داشتن انگشت = سرعت 2x | دو بار ضربه در چپ یا راست = پرش ۱۰ ثانیه)
        (function initVideoTouchGestures() {
            const stage = document.getElementById('video-stage-wrapper');
            const holdBadge = document.getElementById('speed-hold-indicator');
            let holdTimer = null;
            let savedRateBeforeHold = 1.0;
            let isHolding2x = false;
            let lastTapTime = 0;

            stage.addEventListener('touchstart', (e) => {
                if (e.touches.length !== 1) return;
                const touchX = e.touches[0].clientX;
                const rect   = stage.getBoundingClientRect();

                // بررسی دو بار ضربه سریع (Double-Tap) در یک‌سوم چپ یا راست کادر ویدیو
                const now = Date.now();
                if (now - lastTapTime < 280) {
                    const relX = (touchX - rect.left) / Math.max(1, rect.width);
                    if (relX < 0.35) {
                        seekVideoRelative(-10);
                        lastTapTime = 0;
                        return;
                    } else if (relX > 0.65) {
                        seekVideoRelative(10);
                        lastTapTime = 0;
                        return;
                    }
                }
                lastTapTime = now;

                // نگه‌داشتن انگشت بیش از 450ms برای پخش با سرعت 2x
                holdTimer = setTimeout(() => {
                    if (!videoEl.paused) {
                        isHolding2x = true;
                        savedRateBeforeHold = videoEl.playbackRate;
                        videoEl.playbackRate = 2.0;
                        holdBadge.classList.remove('hidden');
                    }
                }, 450);
            }, { passive: true });

            function endHoldGesture() {
                if (holdTimer) {
                    clearTimeout(holdTimer);
                    holdTimer = null;
                }
                if (isHolding2x) {
                    isHolding2x = false;
                    videoEl.playbackRate = savedRateBeforeHold;
                    holdBadge.classList.add('hidden');
                }
            }

            stage.addEventListener('touchend', endHoldGesture, { passive: true });
            stage.addEventListener('touchcancel', endHoldGesture, { passive: true });
        })();

        // کلیدهای میانبر کیبورد برای پلیر ویدیو
        window.addEventListener('keydown', (e) => {
            if (document.getElementById('view-course-player').classList.contains('hidden')) return;
            const tag = (e.target.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

            if (e.code === 'Space') {
                e.preventDefault();
                if (videoEl.paused) videoEl.play();
                else videoEl.pause();
            } else if (e.code === 'ArrowLeft') {
                e.preventDefault();
                seekVideoRelative(-10);
            } else if (e.code === 'ArrowRight') {
                e.preventDefault();
                seekVideoRelative(10);
            }
        });

        // ====================================================
        // ۷. تکمیل جلسه، انتقال جزوه به جزوه‌خوان، ثبت در تقویم و یادداشت‌های زمان‌دار
        // ====================================================
        function updateLessonCompleteButtonUI(isDone) {
            const btn = document.getElementById('btn-complete-lesson');
            if (isDone) {
                btn.className   = 'px-3.5 py-1.5 bg-emerald-600 text-white rounded-xl font-extrabold shadow transition';
                btn.textContent = '✅ جلسه تکمیل شد';
            } else {
                btn.className   = 'px-3.5 py-1.5 bg-slate-100 hover:bg-emerald-50 text-slate-700 border border-slate-300 rounded-xl font-extrabold transition';
                btn.textContent = '○ ثبت تکمیل جلسه';
            }
        }

        async function toggleActiveLessonCompleted() {
            if (!activeLessonObj || !activeCourseData) return;
            const fd = new FormData();
            fd.append('action', 'toggle_lesson_complete');
            fd.append('course_id', activeCourseData.id);
            fd.append('lesson_id', activeLessonObj.id);

            const res = await fetch('api_courses.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                updateLessonCompleteButtonUI(data.is_completed);
                const flatItem = flatLessonsOrder.find(x => Number(x.id) === Number(activeLessonObj.id));
                if (flatItem && flatItem.user_progress) {
                    flatItem.user_progress.is_completed = data.is_completed;
                }
                const doneCount = flatLessonsOrder.filter(x => x.user_progress && x.user_progress.is_completed).length;
                const pct = flatLessonsOrder.length > 0 ? Math.round((doneCount / flatLessonsOrder.length) * 100) : 0;
                updateCourseOverallProgressUI(pct);
                renderPlayerChaptersTree();
            }
        }

        function updateAdjacentLessonButtonsUI() {
            if (!activeLessonObj) return;
            const curIdx = flatLessonsOrder.findIndex(x => Number(x.id) === Number(activeLessonObj.id));
            document.getElementById('btn-prev-lesson').disabled = (curIdx <= 0);
            document.getElementById('btn-next-lesson').disabled = (curIdx === -1 || curIdx >= flatLessonsOrder.length - 1);
        }

        function navigateAdjacentLesson(delta) {
            if (!activeLessonObj) return;
            const curIdx = flatLessonsOrder.findIndex(x => Number(x.id) === Number(activeLessonObj.id));
            const nextItem = flatLessonsOrder[curIdx + delta];
            if (nextItem) {
                loadAndPlayLesson(nextItem.id);
            }
        }

        async function importCurrentLessonBookletToReader() {
            if (!activeLessonObj) return;
            const btn = document.getElementById('btn-import-booklet-to-reader');
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span>⏳ در حال انتقال به جزوه‌خوان...</span>';

            try {
                const fd = new FormData();
                fd.append('action', 'import_booklet_to_reader');
                fd.append('lesson_id', activeLessonObj.id);

                const res = await fetch('api_courses.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    if (confirm(data.message + '\n\nآیا می‌خواهید همین الان وارد جزوه‌خوان شوید؟')) {
                        window.location.href = 'index.php?tab=pdf';
                    }
                } else if (data.need_vip) {
                    openSubscriptionModal(data.message);
                } else {
                    alert(data.message || 'خطا در انتقال جزوه');
                }
            } catch (e) {
                alert('خطا در ارتباط با سرور.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        }

        async function logCurrentLessonToCalendar() {
            if (!activeLessonObj) return;
            const fd = new FormData();
            fd.append('action', 'log_to_calendar');
            fd.append('lesson_id', activeLessonObj.id);

            const res = await fetch('api_courses.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message || 'در تقویم ثبت شد.');
        }

        async function saveTimestampedVideoNote(e) {
            e.preventDefault();
            if (!activeLessonObj || !activeCourseData) return;
            const inputEl = document.getElementById('quick-video-note-input');
            const text = inputEl.value.trim();
            if (!text) return;

            const curSec = Math.floor(videoEl.currentTime || 0);
            const fd = new FormData();
            fd.append('action', 'save_note');
            fd.append('course_id', activeCourseData.id);
            fd.append('lesson_id', activeLessonObj.id);
            fd.append('timestamp_sec', curSec);
            fd.append('note_text', text);

            const res = await fetch('api_courses.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                inputEl.value = '';
                activeLessonNotes.push({
                    id: data.note_id,
                    timestamp_sec: curSec,
                    note_text: text
                });
                activeLessonNotes.sort((a, b) => Number(a.timestamp_sec) - Number(b.timestamp_sec));
                renderLessonNotesList();
                switchPlayerSidebarTab('notes');
            } else {
                alert(data.message || 'خطا در ثبت یادداشت');
            }
        }

        function renderLessonNotesList() {
            document.getElementById('sidebar-notes-count').textContent = activeLessonNotes.length;
            const listEl = document.getElementById('lesson-notes-list');

            if (activeLessonNotes.length === 0) {
                listEl.innerHTML = `<div class="text-center text-slate-400 py-8">هنوز یادداشتی برای این جلسه ثبت نکرده‌اید. از کادر زیر ویدیو می‌توانید نکات مهم را با ثانیه دقیق ذخیره کنید.</div>`;
                return;
            }

            listEl.innerHTML = activeLessonNotes.map(n => `
                <div class="p-3 bg-slate-50 rounded-2xl border border-slate-200 space-y-1.5">
                    <div class="flex items-center justify-between">
                        <button type="button" onclick="jumpVideoToSecond(${n.timestamp_sec})" class="bg-indigo-600 hover:bg-indigo-700 text-white font-mono text-[11px] font-extrabold px-2.5 py-0.5 rounded-lg transition" dir="ltr">
                            ▶️ ${formatSecondsToClock(n.timestamp_sec)}
                        </button>
                        <button type="button" onclick="deleteVideoNote(${n.id})" class="text-slate-400 hover:text-rose-600 text-xs">🗑️</button>
                    </div>
                    <div class="text-xs font-bold text-slate-800 leading-relaxed whitespace-pre-line select-text">${escapeHtml(n.note_text)}</div>
                </div>
            `).join('');
        }

        async function deleteVideoNote(noteId) {
            if (!confirm('این یادداشت حذف شود؟')) return;
            const fd = new FormData();
            fd.append('action', 'delete_note');
            fd.append('note_id', noteId);
            await fetch('api_courses.php', { method: 'POST', body: fd });
            activeLessonNotes = activeLessonNotes.filter(x => Number(x.id) !== Number(noteId));
            renderLessonNotesList();
        }

        function exportVideoNotesToWord() {
            if (!activeLessonObj || activeLessonNotes.length === 0) {
                alert('یادداشتی برای خروجی گرفتن وجود ندارد.');
                return;
            }
            const title = escapeHtml(activeLessonObj.title);
            const rows = activeLessonNotes.map((n, idx) => `
                <div style="border:1px solid #cbd5e1;border-radius:8px;padding:10px;margin-bottom:10px;">
                    <div style="font-weight:bold;color:#4f46e5;margin-bottom:4px;">📌 نکته #${idx + 1} — دقیقه [${formatSecondsToClock(n.timestamp_sec)}]</div>
                    <div style="font-size:11pt;line-height:1.7;">${escapeHtml(n.note_text)}</div>
                </div>
            `).join('');

            const html = `<html dir="rtl"><head><meta charset="utf-8"></head><body style="font-family:Tahoma,sans-serif;direction:rtl;padding:20px;"><h2>🎬 یادداشت‌های جلسه: ${title}</h2><hr>${rows}</body></html>`;
            const blob = new Blob(['\ufeff', html], { type: 'application/msword;charset=utf-8' });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href     = url;
            a.download = `Video_Notes_${activeLessonObj.id}.doc`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }

        function switchPlayerSidebarTab(tab) {
            const tabs = ['lessons', 'transcript', 'notes'];
            tabs.forEach(t => {
                const btn     = document.getElementById('stab-btn-' + t);
                const content = document.getElementById('stab-content-' + t);
                if (t === tab) {
                    btn.className = 'py-2 px-2 rounded-xl bg-indigo-600 text-white font-extrabold transition text-center';
                    content.classList.remove('hidden');
                    if (t !== 'lessons') content.classList.add('flex');
                } else {
                    btn.className = 'py-2 px-2 rounded-xl text-slate-300 hover:text-white transition text-center';
                    content.classList.add('hidden');
                    content.classList.remove('flex');
                }
            });
        }

        async function returnToCoursesLobby() {
            await flushCurrentVideoProgress(true);
            videoEl.pause();
            videoEl.removeAttribute('src');
            videoEl.load();
            document.getElementById('view-course-player').classList.add('hidden');
            document.getElementById('view-courses-lobby').classList.remove('hidden');
            fetchLobbyCourses();
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
                fetchLobbyCourses();
            }
        }

        function escapeHtml(str) {
            return String(str || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
        }

        window.addEventListener('DOMContentLoaded', () => {
            fetchLobbyCourses();
        });
    </script>
</body>
</html>