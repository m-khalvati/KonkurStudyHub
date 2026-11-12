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
    <title>آکادمی ویدیویی و پلیر هوشمند آموزشی v4.4</title>

    <!-- ۱. بارگذاری اولویت اول از پوشه بومی هاست (assets) برای کارکرد ۱۰۰٪ آفلاین و ضدقطعی -->
    <link href="assets/css/tailwind-local.css" rel="stylesheet">
    <link href="assets/css/vazirmatn-local.css" rel="stylesheet">

    <!-- ۲. پشتیبان jsDelivr و Tailwind در صورت عدم اجرای setup_assets -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">

    <!-- ۳. استایل‌های داخلی تضمین‌کننده ظاهر صفحه و پلیر ویدیو در تمامی شرایط شبکه -->
    <link href="assets/css/courses.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/courses.css') ?: '4.4'; ?>" rel="stylesheet">
</head>
<body class="bg-slate-100 text-slate-800 h-screen flex flex-col overflow-hidden select-none">

    <!-- ==================================================== -->
    <!-- ۱. هدر اصلی سامانه -->
    <!-- ==================================================== -->
    <?php $active_nav = 'courses'; require __DIR__ . '/includes/navbar.php'; ?>

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
    <?php require __DIR__ . '/includes/subscription_modal.php'; ?>

    <!-- PHP-to-JS Configuration Bridge -->
    <script>
const TELEGRAM_SALES_ID = "<?php echo htmlspecialchars(TELEGRAM_SALES_ID); ?>";
        let userSub = <?php echo json_encode($initial_sub, JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <!-- Modular External JavaScript -->
    <script src="assets/js/courses.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/courses.js') ?: '4.4'; ?>"></script>
</body>
</html>