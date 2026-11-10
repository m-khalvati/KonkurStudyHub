<?php
require_once 'config.php';
require_login();

$user_id = (int)$_SESSION['user_id'];

// مقادیر پیش‌فرض ایمن در صورتی که ثوابت در config.php تعریف نشده باشند
if (!defined('TELEGRAM_SALES_ID'))  define('TELEGRAM_SALES_ID', 'YourTelegramID');
if (!defined('FREE_MAX_PDF_COUNT')) define('FREE_MAX_PDF_COUNT', 1);
if (!defined('FREE_MAX_PDF_MB'))    define('FREE_MAX_PDF_MB', 3);
if (!defined('VIP_MAX_PDF_MB'))     define('VIP_MAX_PDF_MB', 64);
if (!defined('PLAN_1M_PRICE'))      define('PLAN_1M_PRICE', '۵۰۰,۰۰۰');
if (!defined('PLAN_3M_PRICE'))      define('PLAN_3M_PRICE', '۱,۲۰۰,۰۰۰');

// ارتقای خودکار جداول دیتابیس (بدون نیاز به اجرای دستی SQL در phpMyAdmin)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `calendar_categories` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `name` varchar(60) NOT NULL,
          `color` varchar(20) NOT NULL,
          `is_study` tinyint(1) DEFAULT 1,
          `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_user_cat` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
    ");

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

    $uCols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('phone', $uCols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `phone` varchar(15) DEFAULT NULL AFTER `username`");
    }
    if (!in_array('is_admin', $uCols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `is_admin` tinyint(1) DEFAULT 0 AFTER `password_hash`");
        $pdo->exec("UPDATE `users` SET `is_admin` = 1 WHERE `id` = 1");
    }
    if (!in_array('vip_expires_at', $uCols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `vip_expires_at` datetime DEFAULT NULL AFTER `is_admin`");
    }

    $eCols = $pdo->query("SHOW COLUMNS FROM `calendar_events`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('category_type', $eCols)) {
        $pdo->exec("ALTER TABLE `calendar_events` ADD COLUMN `category_type` varchar(30) DEFAULT 'specialized' AFTER `color`");
    }
    if (!in_array('category_label', $eCols)) {
        $pdo->exec("ALTER TABLE `calendar_events` ADD COLUMN `category_label` varchar(60) DEFAULT 'تخصصی' AFTER `category_type`");
    }
    if (!in_array('is_study', $eCols)) {
        $pdo->exec("ALTER TABLE `calendar_events` ADD COLUMN `is_study` tinyint(1) DEFAULT 1 AFTER `category_label`");
        $pdo->exec("UPDATE `calendar_events` SET `is_study` = 0, `category_label` = 'متفرقه' WHERE `category_type` = 'misc' OR `color` = '#7c3aed'");
    }
    if (!in_array('send_sms', $eCols)) {
        $pdo->exec("ALTER TABLE `calendar_events` ADD COLUMN `send_sms` tinyint(1) DEFAULT 1 AFTER `is_completed`");
    }
    if (!in_array('sms_sent', $eCols)) {
        $pdo->exec("ALTER TABLE `calendar_events` ADD COLUMN `sms_sent` tinyint(1) DEFAULT 0 AFTER `send_sms`");
    }
    if (!in_array('test_correct', $eCols)) {
        $pdo->exec("
            ALTER TABLE `calendar_events`
            ADD COLUMN `test_correct` int(11) DEFAULT NULL AFTER `pdf_page`,
            ADD COLUMN `test_wrong` int(11) DEFAULT NULL AFTER `test_correct`,
            ADD COLUMN `test_blank` int(11) DEFAULT NULL AFTER `test_wrong`,
            ADD COLUMN `test_percent` decimal(5,2) DEFAULT NULL AFTER `test_blank`
        ");
    }
} catch (Exception $ignore) {}

// دریافت وضعیت اشتراک کاربر در لحظه بارگذاری صفحه
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
    <title>داشبورد هوشمند مطالعه و تقویم کنکور v3.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
    
    <!-- بارگذاری نسخه 3.11.174 از سرور بدون اختلال jsDelivr (به همراه پشتیبان خودکار unpkg) -->
    <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"
            onerror="this.onerror=null;this.src='https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.min.js';"></script>

    <style>
        body { font-family: 'Vazirmatn', sans-serif; -webkit-tap-highlight-color: transparent; }
        .hour-row { height: 60px; }
        .calendar-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
        .calendar-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .locked-day-pattern {
            background-image: repeating-linear-gradient(45deg, #f8fafc 0, #f8fafc 10px, #f1f5f9 10px, #f1f5f9 20px);
        }
        .pdf-dark-mode {
            filter: invert(0.92) hue-rotate(180deg) contrast(1.05);
        }
        @keyframes pageFadeIn {
            from { opacity: 0.45; transform: scale(0.99); }
            to { opacity: 1; transform: scale(1); }
        }
        .page-flip-anim { animation: pageFadeIn 0.18s ease-out; }

        .cal-event-card:hover {
            z-index: 35 !important;
            box-shadow: 0 10px 20px -5px rgba(0, 0, 0, 0.35);
        }

        /* قفل کردن جهت LTR روی بوم PDF برای جلوگیری ۱۰۰٪ از به هم ریختن فونت‌های فارسی و انگلیسی */
        #pdf-canvas-wrapper,
        #pdf-render-canvas,
        #pdf-annot-canvas {
            direction: ltr !important;
            text-align: left !important;
            unicode-bidi: isolate !important;
            font-family: sans-serif !important;
            letter-spacing: normal !important;
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 h-screen flex flex-col overflow-hidden select-none">

    <!-- ==================================================== -->
    <!-- ۱. هدر اصلی سامانه (شامل نشانگر وضعیت اشتراک و دکمه پنل ادمین) -->
    <!-- ==================================================== -->
    <header id="main-app-header" class="bg-slate-900 text-white px-3 md:px-4 py-2 flex flex-wrap items-center justify-between gap-2 shadow-md z-30 shrink-0">
        <div class="flex items-center gap-2 md:gap-3">
            <div class="flex items-center gap-1.5">
                <span class="bg-indigo-600 text-white px-2 py-1 rounded-lg font-extrabold text-xs">🎯 کنکور</span>
                <span class="font-bold text-xs md:text-sm truncate max-w-[90px] sm:max-w-none"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
            </div>

            <nav class="flex bg-slate-800 p-0.5 rounded-xl border border-slate-700">
                <button type="button" id="nav-calendar" onclick="switchMainSection('calendar')" class="px-2.5 md:px-3.5 py-1.5 rounded-lg text-xs font-bold bg-indigo-600 text-white transition">
                    📅 تقویم
                </button>
                <button type="button" id="nav-pdf" onclick="switchMainSection('pdf')" class="px-2.5 md:px-3.5 py-1.5 rounded-lg text-xs font-bold text-slate-300 hover:text-white transition">
                    📚 جزوه‌خوان
                </button>
            </nav>
        </div>

        <div class="flex items-center gap-1.5 md:gap-2 text-xs">
            <!-- دکمه وضعیت اشتراک (عادی یا ویژه) -->
            <button type="button" id="header-sub-btn" onclick="openSubscriptionModal()" class="<?php echo $initial_sub['is_vip'] ? 'bg-gradient-to-r from-amber-500 to-yellow-400 text-slate-950' : 'bg-indigo-600 hover:bg-indigo-500 text-white border border-indigo-400'; ?> px-2.5 py-1.5 rounded-lg font-extrabold flex items-center gap-1 shadow-xs transition">
                <?php if ($initial_sub['is_vip']): ?>
                    <span>👑 ویژه (<?php echo $initial_sub['days_left']; ?> روز | +تمدید)</span>
                <?php else: ?>
                    <span>⭐ ارتقا به اشتراک ویژه</span>
                <?php endif; ?>
            </button>

            <!-- دکمه ورود به پنل ادمین (فقط برای مدیر سایت) -->
            <?php if (!empty($initial_sub['is_admin'])): ?>
                <a href="admin.php" class="bg-slate-800 hover:bg-slate-700 border border-amber-500/50 text-amber-300 px-2.5 py-1.5 rounded-lg font-bold flex items-center gap-1 transition" title="ورود به پنل مدیریت کاربران و اشتراک‌ها">
                    <span>🛠️</span>
                    <span class="hidden lg:inline">مدیریت</span>
                </a>
            <?php endif; ?>

            <button type="button" onclick="togglePomodoroWidget()" class="bg-slate-800 hover:bg-slate-700 border border-slate-700 text-amber-300 px-2 py-1.5 rounded-lg font-bold flex items-center gap-1 transition" title="تایمر پومودورو و کرنومتر مطالعه">
                <span>⏱️</span>
                <span id="header-timer-badge" class="hidden xl:inline">تایمر</span>
            </button>

            <button type="button" onclick="openPhoneModal()" class="bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sky-300 px-2 py-1.5 rounded-lg font-bold flex items-center gap-1 transition" title="تنظیمات پیامک یادآوری sms.ir">
                <span>📱</span>
                <span class="hidden xl:inline">پیامک</span>
            </button>

            <div class="hidden sm:block bg-rose-600/20 border border-rose-500/40 text-rose-200 px-2.5 py-1.5 rounded-lg font-bold">
                ⏳ <?php echo DAYS_TO_KONKUR; ?> روز تا ۱۶ بهمن
            </div>

            <a href="auth.php?logout=1" class="text-slate-400 hover:text-rose-400 font-bold px-1.5 py-1">خروج</a>
        </div>
    </header>

    <!-- ==================================================== -->
    <!-- ۲. بخش اول: تقویم هفتگی و روزانه هوشمند -->
    <!-- ==================================================== -->
    <main id="section-calendar" class="flex-1 flex flex-col overflow-hidden">
        
        <!-- نوار آمار تفکیک‌شده (مطالعه درسی خالص در برابر کارهای متفرقه) -->
        <div class="bg-slate-800 text-slate-200 px-3 py-1.5 flex items-center justify-between gap-3 overflow-x-auto text-[11px] md:text-xs shrink-0 border-b border-slate-700">
            <div class="flex items-center gap-3 md:gap-5 shrink-0">
                <div class="flex items-center gap-1 bg-slate-900/70 px-2 py-0.5 rounded-md border border-slate-700">
                    <span class="text-slate-400">📚 برنامه درسی:</span>
                    <span id="stat-planned-hours" class="font-extrabold text-white">0 ساعت</span>
                </div>
                <div class="flex items-center gap-1 bg-emerald-950/60 px-2 py-0.5 rounded-md border border-emerald-700/50">
                    <span class="text-emerald-300">✅ مطالعه واقعی:</span>
                    <span id="stat-completed-hours" class="font-extrabold text-emerald-400">0 ساعت</span>
                </div>
                <div class="flex items-center gap-1 bg-purple-950/60 px-2 py-0.5 rounded-md border border-purple-700/50" title="کارهای متفرقه جدا از ساعت مطالعه کنکور محاسبه می‌شوند">
                    <span class="text-purple-300">🧩 کارهای متفرقه:</span>
                    <span id="stat-misc-hours" class="font-extrabold text-purple-200">0 از 0 ساعت</span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="text-slate-400">🎯 میانگین تست:</span>
                    <span id="stat-avg-percent" class="font-extrabold text-amber-300">---</span>
                </div>
            </div>
            <div class="flex items-center gap-1 shrink-0">
                <span class="text-slate-400 hidden lg:inline">پیشرفت درسی کل:</span>
                <span id="stat-progress-text" class="font-extrabold text-indigo-300 bg-indigo-950/80 px-2 py-0.5 rounded-md border border-indigo-700">0%</span>
            </div>
        </div>

        <!-- نوار ابزار کنترل تقویم + فیلتر نمایش + کپی روز -->
        <div class="bg-white border-b border-slate-200 px-3 py-2 flex flex-wrap items-center justify-between gap-2 shrink-0">
            <div class="flex items-center gap-1.5">
                <button type="button" onclick="navigateCalendar(-1)" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-xs font-bold transition">❮</button>
                <button type="button" onclick="goToToday()" class="px-2.5 py-1.5 bg-indigo-50 text-indigo-600 hover:bg-indigo-100 rounded-lg text-xs font-bold transition">امروز</button>
                <button type="button" onclick="navigateCalendar(1)" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-xs font-bold transition">❯</button>
                <h2 id="calendar-range-title" class="text-xs md:text-sm font-extrabold text-slate-800 mr-1"></h2>
            </div>

            <div class="flex flex-wrap items-center gap-1.5 md:gap-2">
                <div class="flex bg-slate-100 p-0.5 rounded-lg border border-slate-200 text-[11px]">
                    <button type="button" id="filter-all" onclick="setCalendarFilter('all')" class="px-2 py-1 rounded-md font-bold bg-white shadow-2xs text-slate-800">همه</button>
                    <button type="button" id="filter-study" onclick="setCalendarFilter('study')" class="px-2 py-1 rounded-md font-bold text-slate-600">📚 درسی</button>
                    <button type="button" id="filter-misc" onclick="setCalendarFilter('misc')" class="px-2 py-1 rounded-md font-bold text-slate-600">🧩 متفرقه</button>
                </div>

                <div class="flex bg-slate-100 p-0.5 rounded-lg border border-slate-200">
                    <button type="button" id="btn-view-week" onclick="setViewMode('week')" class="px-2.5 py-1 rounded-md text-xs font-bold bg-white shadow-2xs text-indigo-600">
                        هفتگی
                    </button>
                    <button type="button" id="btn-view-day" onclick="setViewMode('day')" class="px-2.5 py-1 rounded-md text-xs font-bold text-slate-600">
                        روزانه
                    </button>
                </div>

                <button type="button" onclick="openCopyDayModal()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-2.5 py-1.5 rounded-lg text-xs font-bold transition" title="کپی کردن برنامه‌های یک روز به روزی دیگر">
                    📋 <span class="hidden sm:inline">کپی روز</span>
                </button>

                <button type="button" onclick="openEventModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg text-xs font-bold shadow-xs transition">
                    + ثبت برنامه
                </button>
            </div>
        </div>

        <div id="mobile-week-strip" class="hidden bg-slate-50 border-b border-slate-200 px-2 py-1.5 grid-cols-7 gap-1 shrink-0"></div>

        <div class="flex-1 flex flex-col overflow-hidden bg-white">
            <div class="flex border-b border-slate-200 bg-slate-50 shrink-0 pr-12 md:pr-14" id="calendar-days-header"></div>

            <div id="calendar-scroll-area" class="flex-1 overflow-y-auto calendar-scroll relative flex">
                <div class="w-12 md:w-14 shrink-0 border-l border-slate-200 bg-slate-50 text-[10px] md:text-[11px] text-slate-400 font-medium text-center" id="time-labels-col"></div>
                <div class="flex-1 flex relative" id="calendar-grid-cols" style="height: 1440px;"></div>
            </div>
        </div>
    </main>

    <!-- ==================================================== -->
    <!-- ۳. بخش دوم: جزوه‌خوان تمام‌صفحه با پوشه‌بندی آکاردئونی و ورق زدن لمسی -->
    <!-- ==================================================== -->
    <section id="section-pdf" class="hidden flex-1 flex overflow-hidden relative bg-slate-900">
        
        <div id="pdf-sidebar-backdrop" onclick="closePdfSidebarMobile()" class="fixed inset-0 bg-black/60 z-30 hidden md:hidden"></div>

        <aside id="pdf-sidebar" class="w-80 max-w-[86vw] bg-white border-l border-slate-200 flex flex-col shrink-0 z-40 fixed md:static inset-y-0 right-0 transform translate-x-full md:translate-x-0 transition-transform duration-200 shadow-2xl md:shadow-none">
            <div class="p-3 border-b border-slate-200 flex items-center justify-between bg-slate-50">
                <h3 class="font-extrabold text-xs md:text-sm text-slate-800">📚 پوشه‌ها و جزوات من</h3>
                <div class="flex items-center gap-1">
                    <button type="button" onclick="openFolderModal()" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 px-2 py-1.5 rounded-lg text-[11px] font-bold transition">
                        📁+ پوشه
                    </button>
                    <button type="button" onclick="openUploadModal('')" class="bg-indigo-600 hover:bg-indigo-700 text-white px-2.5 py-1.5 rounded-lg text-[11px] font-bold transition">
                        + آپلود PDF
                    </button>
                    <button type="button" onclick="closePdfSidebarMobile()" class="md:hidden text-slate-400 hover:text-slate-700 px-1.5 text-xl font-bold leading-none">&times;</button>
                </div>
            </div>

            <!-- بنر وضعیت سهمیه آپلود PDF (عادی در برابر ویژه) -->
            <div id="pdf-quota-banner" class="px-3 py-2 text-[11px] border-b border-slate-200 flex items-center justify-between bg-amber-50 text-amber-900">
                <!-- توسط جاوااسکریپت بروزرسانی می‌شود -->
            </div>

            <div id="pdf-list-container" class="flex-1 overflow-y-auto p-2.5 space-y-2.5 calendar-scroll bg-slate-50/60"></div>
        </aside>

        <div class="flex-1 flex flex-col overflow-hidden relative bg-slate-800">
            
            <div id="pdf-top-bar" class="bg-slate-900/95 text-white border-b border-slate-700 px-2.5 py-1.5 flex items-center justify-between gap-2 shrink-0 z-20">
                <div class="flex items-center gap-1.5 min-w-0">
                    <button type="button" onclick="togglePdfSidebar()" class="px-2.5 py-1 bg-indigo-600 hover:bg-indigo-500 rounded-lg text-xs font-bold text-white shrink-0 shadow-xs">
                        ☰ پوشه‌ها و جزوات
                    </button>
                    <span id="active-pdf-title" class="text-xs font-bold text-slate-200 truncate">
                        جزوه‌ای انتخاب نشده
                    </span>
                </div>

                <div id="pdf-controls-group" class="hidden items-center gap-1 shrink-0">
                    <button type="button" onclick="changePdfPage(-1)" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 rounded-lg text-xs font-bold" title="صفحه قبل">❮</button>
                    <div class="flex items-center gap-1 text-xs font-bold bg-slate-800 px-2 py-0.5 rounded-lg border border-slate-700">
                        <input type="number" id="pdf-page-input" onchange="jumpToInputPage(this.value)" min="1" value="1" class="w-10 text-center bg-transparent text-white font-extrabold focus:outline-none">
                        <span class="text-slate-400">/</span>
                        <span id="pdf-total-pages" class="text-slate-300">1</span>
                    </div>
                    <button type="button" onclick="changePdfPage(1)" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 rounded-lg text-xs font-bold" title="صفحه بعد">❯</button>

                    <button type="button" id="btn-star-page" onclick="toggleStarCurrentPage()" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 rounded-lg text-xs font-bold text-slate-400 transition" title="نشان کردن این صفحه به عنوان صفحه مهم">
                        ☆
                    </button>
                    <button type="button" onclick="openStarredModal()" class="px-2 py-1 bg-amber-500/20 text-amber-300 border border-amber-500/30 rounded-lg text-[11px] font-bold" title="مشاهده صفحات مهم این جزوه">
                        ⭐ <span id="starred-count-badge">0</span>
                    </button>
                </div>

                <button type="button" onclick="toggleFullScreenReader()" id="btn-fullscreen-reader" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg text-xs font-bold shrink-0" title="حالت تمام‌صفحه">
                    ⛶ تمام‌صفحه
                </button>
            </div>

            <div id="pdf-viewport-container" class="flex-1 overflow-auto p-1 sm:p-3 flex justify-center items-start calendar-scroll relative">
                
                <div id="pdf-loading-overlay" class="hidden my-auto text-center bg-slate-900/95 text-white p-6 rounded-2xl shadow-xl border border-slate-700 z-30">
                    <div class="text-3xl mb-2 animate-bounce">⏳</div>
                    <div id="pdf-loading-text" class="text-xs font-bold text-indigo-300">در حال بارگذاری و آماده‌سازی تمام صفحات جزوه...</div>
                </div>

                <div id="pdf-empty-state" class="my-auto text-center bg-slate-900/90 text-white p-6 rounded-2xl shadow-xl max-w-xs border border-slate-700">
                    <div class="text-4xl mb-2">📖</div>
                    <h4 class="font-bold text-sm mb-1">جزوه‌ای باز نیست</h4>
                    <p class="text-slate-400 text-xs mb-4 leading-relaxed">از منوی «پوشه‌ها و جزوات» یک درس را انتخاب کنید یا فایل PDF جدیدی آپلود نمایید.</p>
                    <div class="flex flex-wrap gap-2 justify-center">
                        <button type="button" onclick="togglePdfSidebar()" class="bg-slate-700 hover:bg-slate-600 text-white px-3 py-2 rounded-lg text-xs font-bold">
                            ☰ باز کردن پوشه‌ها
                        </button>
                        <button type="button" onclick="openUploadModal('')" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-lg text-xs font-bold">
                            + آپلود PDF
                        </button>
                    </div>
                </div>

                <!-- بوم رندر PDF + لایه علامت‌گذاری (ایزوله‌شده با dir="ltr") -->
                <div id="pdf-canvas-wrapper" dir="ltr" class="hidden relative shadow-2xl bg-white">
                    <canvas id="pdf-render-canvas" dir="ltr" class="block"></canvas>
                    <canvas id="pdf-annot-canvas" dir="ltr" class="absolute inset-0 pointer-events-none"></canvas>
                </div>
            </div>

            <div id="pdf-floating-bottom-bar" class="hidden fixed bottom-4 right-4 z-30 items-center gap-1.5">
                <button type="button" onclick="changePdfPage(-1)" class="w-9 h-9 rounded-full bg-slate-900/90 text-white shadow-lg border border-slate-700 font-bold text-sm flex items-center justify-center">❮</button>
                <span id="floating-page-pill" class="bg-slate-900/90 text-white px-3 py-1.5 rounded-full text-xs font-bold shadow-lg border border-slate-700">
                    ص 1
                </span>
                <button type="button" onclick="changePdfPage(1)" class="w-9 h-9 rounded-full bg-slate-900/90 text-white shadow-lg border border-slate-700 font-bold text-sm flex items-center justify-center">❯</button>
            </div>

            <button type="button" id="pdf-fab-btn" onclick="toggleToolsPopup()" class="hidden fixed bottom-4 left-4 z-40 bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-2.5 rounded-full shadow-2xl font-extrabold text-xs items-center gap-1.5 border-2 border-white/20 transition transform active:scale-95">
                <span id="fab-active-icon">✋</span>
                <span>ابزارها و علامت‌گذاری</span>
            </button>

            <div id="pdf-tools-popup" class="hidden fixed bottom-16 left-4 z-40 bg-slate-900/95 text-white p-3.5 rounded-2xl shadow-2xl border border-slate-700 w-72 max-w-[92vw] backdrop-blur-md space-y-3 text-xs">
                <div class="flex items-center justify-between border-b border-slate-700 pb-2">
                    <span class="font-extrabold text-slate-200">🛠️ جعبه ابزار جزوه‌خوان</span>
                    <div class="flex items-center gap-2">
                        <span id="annot-save-status" class="text-[10px] font-bold text-emerald-400">✓ آماده</span>
                        <button type="button" onclick="toggleToolsPopup()" class="text-slate-400 hover:text-white text-lg font-bold leading-none">&times;</button>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-1.5">
                    <button type="button" onclick="setAnnotTool('pan', true)" id="tool-pan" class="annot-tool-btn px-2.5 py-2 rounded-xl font-bold bg-indigo-600 text-white flex items-center gap-1.5">
                        <span>✋</span><span>مطالعه / ورق‌زدن</span>
                    </button>
                    <button type="button" onclick="setAnnotTool('highlight', true)" id="tool-highlight" class="annot-tool-btn px-2.5 py-2 rounded-xl font-bold bg-slate-800 hover:bg-slate-700 text-slate-200 flex items-center gap-1.5">
                        <span>🖍️</span><span>ماژیک هایلایت</span>
                    </button>
                    <button type="button" onclick="setAnnotTool('pen', true)" id="tool-pen" class="annot-tool-btn px-2.5 py-2 rounded-xl font-bold bg-slate-800 hover:bg-slate-700 text-slate-200 flex items-center gap-1.5">
                        <span>✏️</span><span>قلم نوری / دست‌خط</span>
                    </button>
                    <button type="button" onclick="setAnnotTool('note', true)" id="tool-note" class="annot-tool-btn px-2.5 py-2 rounded-xl font-bold bg-slate-800 hover:bg-slate-700 text-slate-200 flex items-center gap-1.5">
                        <span>📌</span><span>ثبت یادداشت</span>
                    </button>
                    <button type="button" onclick="setAnnotTool('eraser', true)" id="tool-eraser" class="col-span-2 annot-tool-btn px-2.5 py-1.5 rounded-xl font-bold bg-slate-800 hover:bg-slate-700 text-slate-200 flex items-center justify-center gap-1.5">
                        <span>🧽</span><span>پاک‌کن (لمس روی خط یا یادداشت)</span>
                    </button>
                </div>

                <div class="flex items-center justify-between bg-slate-800 p-2 rounded-xl">
                    <span class="text-[11px] text-slate-300 font-bold">رنگ قلم/ماژیک:</span>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="setAnnotColor('#facc15')" data-color="#facc15" class="annot-color-btn w-6 h-6 rounded-full bg-yellow-400 ring-2 ring-white scale-110 transition"></button>
                        <button type="button" onclick="setAnnotColor('#4ade80')" data-color="#4ade80" class="annot-color-btn w-6 h-6 rounded-full bg-green-400 opacity-60 hover:opacity-100 transition"></button>
                        <button type="button" onclick="setAnnotColor('#38bdf8')" data-color="#38bdf8" class="annot-color-btn w-6 h-6 rounded-full bg-sky-400 opacity-60 hover:opacity-100 transition"></button>
                        <button type="button" onclick="setAnnotColor('#fb7185')" data-color="#fb7185" class="annot-color-btn w-6 h-6 rounded-full bg-rose-400 opacity-60 hover:opacity-100 transition"></button>
                    </div>
                </div>

                <div class="grid grid-cols-4 gap-1.5 pt-1 border-t border-slate-700">
                    <button type="button" onclick="adjustZoom(-0.2)" class="py-1.5 bg-slate-800 hover:bg-slate-700 rounded-lg font-bold text-center">➖ زوم</button>
                    <button type="button" onclick="setFitWidthZoom()" class="py-1.5 bg-indigo-950 text-indigo-300 border border-indigo-700 rounded-lg font-bold text-center">↔ عرض</button>
                    <button type="button" onclick="adjustZoom(0.2)" class="py-1.5 bg-slate-800 hover:bg-slate-700 rounded-lg font-bold text-center">➕ زوم</button>
                    <button type="button" onclick="togglePdfDarkMode()" id="btn-pdf-dark" class="py-1.5 bg-slate-800 text-amber-300 hover:bg-slate-700 rounded-lg font-bold text-center">🌙 شب</button>
                </div>

                <div class="flex gap-1.5 pt-1 border-t border-slate-700">
                    <button type="button" onclick="undoLastAnnotation()" class="flex-1 py-1.5 bg-slate-800 hover:bg-slate-700 rounded-lg font-bold text-slate-200">↩ واگرد (Undo)</button>
                    <button type="button" onclick="clearAllPageAnnotations()" class="flex-1 py-1.5 bg-rose-950/80 hover:bg-rose-700 text-rose-200 rounded-lg font-bold">🗑️ پاکسازی صفحه</button>
                </div>
            </div>
        </div>
    </section>

    <!-- ==================================================== -->
    <!-- ۴. ویجت شناور تایمر پومودورو -->
    <!-- ==================================================== -->
    <div id="pomodoro-widget" class="hidden fixed top-14 left-4 z-50 bg-slate-900 text-white rounded-2xl shadow-2xl border border-slate-700 w-68 p-3.5 text-xs">
        <div class="flex items-center justify-between border-b border-slate-800 pb-2 mb-2">
            <span class="font-extrabold text-amber-300 flex items-center gap-1">⏱️ تایمر مطالعه کنکور</span>
            <button type="button" onclick="togglePomodoroWidget()" class="text-slate-400 hover:text-white text-base">&times;</button>
        </div>

        <div id="pomodoro-linked-task-box" class="hidden bg-indigo-950/80 border border-indigo-700/60 rounded-xl p-2 mb-2.5">
            <div class="text-[10px] text-indigo-300 mb-0.5">در حال مطالعه برنامه:</div>
            <div id="pomodoro-linked-title" class="font-extrabold text-white truncate"></div>
            <button type="button" id="btn-pomodoro-complete-task" class="mt-1.5 w-full py-1 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg font-bold text-[11px] transition">
                ✓ ثبت پایان و تیک زدن این برنامه
            </button>
        </div>

        <div class="flex bg-slate-800 p-0.5 rounded-lg mb-3 text-[11px] font-bold">
            <button type="button" onclick="setTimerMode('pomodoro')" id="tmode-pomodoro" class="flex-1 py-1 rounded-md bg-indigo-600 text-white">۲۵ دقیقه</button>
            <button type="button" onclick="setTimerMode('break')" id="tmode-break" class="flex-1 py-1 rounded-md text-slate-400">استراحت ۵</button>
            <button type="button" onclick="setTimerMode('stopwatch')" id="tmode-stopwatch" class="flex-1 py-1 rounded-md text-slate-400">کرنومتر</button>
        </div>
        <div id="timer-display" class="text-3xl font-black text-center tracking-widest py-2 font-mono text-white bg-slate-950 rounded-xl border border-slate-800 mb-3">
            25:00
        </div>
        <div class="flex gap-2">
            <button type="button" onclick="startPauseTimer()" id="btn-timer-toggle" class="flex-1 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-extrabold rounded-xl transition">
                شروع
            </button>
            <button type="button" onclick="resetTimer()" class="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold rounded-xl">
                ریست
            </button>
        </div>
    </div>

    <!-- ==================================================== -->
    <!-- ۵. مودال ثبت و ویرایش برنامه تقویم (با قفل پیامک برای کاربران عادی) -->
    <!-- ==================================================== -->
    <div id="event-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-end sm:items-center justify-center sm:p-4 z-50">
        <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl w-full max-w-lg max-h-[94vh] flex flex-col overflow-hidden">
            <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between shrink-0">
                <h3 id="modal-title" class="font-bold text-xs md:text-sm">ثبت برنامه مطالعاتی</h3>
                <button type="button" onclick="closeEventModal()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>

            <form id="event-form" onsubmit="saveEvent(event)" class="p-4 space-y-3 overflow-y-auto calendar-scroll text-xs">
                <input type="hidden" id="ev-id" name="id">

                <div>
                    <label class="block font-bold text-slate-700 mb-1">عنوان برنامه / مبحث یا کار *</label>
                    <input type="text" id="ev-title" name="title" required placeholder="مثلاً: مطالعه فصل ۲ زیست + ۴۰ تست" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">تاریخ *</label>
                        <select id="ev-date" name="g_date" onchange="checkLiveOverlapWarning()" required class="w-full px-2 py-2 border border-slate-300 rounded-lg bg-white text-[11px]"></select>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">از ساعت *</label>
                        <input type="time" id="ev-start" name="start_time" oninput="checkLiveOverlapWarning()" required class="w-full px-2 py-2 border border-slate-300 rounded-lg text-center">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">تا ساعت *</label>
                        <input type="time" id="ev-end" name="end_time" oninput="checkLiveOverlapWarning()" required class="w-full px-2 py-2 border border-slate-300 rounded-lg text-center">
                    </div>
                </div>

                <div id="overlap-warning-box" class="hidden bg-amber-50 border border-amber-300 text-amber-900 px-3 py-2 rounded-xl text-[11px] font-bold"></div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <label class="font-bold text-slate-700">نوع و رنگ برنامه</label>
                        <button type="button" onclick="toggleNewCategoryBox()" class="text-[11px] bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 px-2.5 py-1 rounded-lg font-bold transition">
                            🎨 + تعریف نوع و رنگ جدید
                        </button>
                    </div>

                    <div id="event-categories-grid" class="grid grid-cols-3 sm:grid-cols-4 gap-1.5"></div>

                    <div id="new-category-box" class="hidden bg-slate-100 p-3 rounded-xl border border-slate-300 space-y-2.5">
                        <div class="flex items-center justify-between">
                            <span class="font-extrabold text-slate-800 text-[11px]">افزودن نوع برنامه سفارشی</span>
                            <button type="button" onclick="toggleNewCategoryBox()" class="text-slate-400 hover:text-slate-700 text-sm font-bold">&times;</button>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <input type="text" id="new-cat-name" placeholder="نام نوع (مثلاً: کلاس آنلاین، ورزش...)" class="px-2.5 py-1.5 border border-slate-300 rounded-lg bg-white text-xs">
                            <select id="new-cat-is-study" class="px-2 py-1.5 border border-slate-300 rounded-lg bg-white text-xs font-bold">
                                <option value="1">📚 جزو ساعات مطالعه درسی حساب شود</option>
                                <option value="0">🧩 جزو کارهای متفرقه حساب شود</option>
                            </select>
                        </div>
                        <div>
                            <span class="block text-[10px] font-bold text-slate-600 mb-1">انتخاب رنگ اختصاصی (متمایز از ۵ رنگ اصلی):</span>
                            <div id="custom-color-palette" class="flex flex-wrap items-center gap-2"></div>
                        </div>
                        <div class="flex justify-end">
                            <button type="button" onclick="saveNewCustomCategory()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3.5 py-1.5 rounded-lg font-bold text-[11px]">
                                + ذخیره و انتخاب این نوع
                            </button>
                        </div>
                    </div>
                </div>

                <!-- تکرار روتین + پیامک یادآوری (قفل برای کاربران عادی) -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 bg-slate-50 p-2.5 rounded-xl border border-slate-200">
                    <div id="repeat-mode-wrapper">
                        <label class="block font-bold text-slate-700 mb-1">🔁 تکرار روتین در این هفته</label>
                        <select id="ev-repeat" name="repeat_mode" class="w-full px-2 py-1.5 border border-slate-300 rounded-lg bg-white text-xs">
                            <option value="none">فقط در همین روز</option>
                            <option value="week_daily">تکرار هر روز تا پایان همین هفته</option>
                            <option value="week_alternate">تکرار یک‌روز در میان در این هفته</option>
                        </select>
                    </div>
                    <div class="flex items-center pt-2 sm:pt-4">
                        <label onclick="handleSmsCheckboxClick(event)" class="flex items-center gap-1.5 cursor-pointer select-none">
                            <input type="checkbox" id="ev-send-sms" name="send_sms" value="1" class="w-4 h-4 accent-indigo-600 rounded">
                            <span class="text-slate-700 font-bold text-[11px]">📱 پیامک یادآوری عدم انجام</span>
                            <span id="sms-vip-lock-badge" class="hidden bg-amber-100 text-amber-800 border border-amber-300 px-1.5 py-0.5 rounded text-[9px] font-extrabold">🔒 ویژه</span>
                        </label>
                    </div>
                </div>

                <div class="bg-indigo-50/70 p-2.5 rounded-xl border border-indigo-100 space-y-2">
                    <label class="block font-bold text-indigo-900">📎 اتصال به جزوه PDF و شماره صفحه (اختیاری)</label>
                    <div class="grid grid-cols-3 gap-2">
                        <div class="col-span-2">
                            <select id="ev-pdf-id" name="pdf_id" class="w-full px-2 py-1.5 border border-slate-300 rounded-lg bg-white text-xs">
                                <option value="">-- بدون جزوه --</option>
                            </select>
                        </div>
                        <div>
                            <input type="number" id="ev-pdf-page" name="pdf_page" min="1" value="1" placeholder="صفحه" class="w-full px-2 py-1.5 border border-slate-300 rounded-lg text-center">
                        </div>
                    </div>
                    <button type="button" id="btn-open-linked-pdf" class="hidden w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 rounded-lg text-xs transition shadow-xs">
                        📖 باز کردن مستقیم این جزوه در صفحه مشخص‌شده
                    </button>
                </div>

                <div class="bg-amber-50/70 p-2.5 rounded-xl border border-amber-200/80 space-y-2">
                    <div class="flex items-center justify-between">
                        <label class="font-bold text-amber-900">📊 ثبت عملکرد تست‌زنی (محاسبه با نمره منفی)</label>
                        <span id="live-test-percent-badge" class="font-extrabold text-xs px-2 py-0.5 rounded-md bg-amber-200/80 text-amber-900">---</span>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <input type="number" id="ev-test-correct" name="test_correct" min="0" oninput="updateLiveTestPercent()" placeholder="✅ تعداد صحیح" class="w-full px-2 py-1.5 border border-emerald-300 rounded-lg text-center bg-white">
                        </div>
                        <div>
                            <input type="number" id="ev-test-wrong" name="test_wrong" min="0" oninput="updateLiveTestPercent()" placeholder="❌ تعداد غلط" class="w-full px-2 py-1.5 border border-rose-300 rounded-lg text-center bg-white">
                        </div>
                        <div>
                            <input type="number" id="ev-test-blank" name="test_blank" min="0" oninput="updateLiveTestPercent()" placeholder="⚪ نزده" class="w-full px-2 py-1.5 border border-slate-300 rounded-lg text-center bg-white">
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">توضیحات تکمیلی</label>
                    <textarea id="ev-desc" name="description" rows="2" placeholder="شماره تست‌ها یا نکات..." class="w-full px-3 py-1.5 border border-slate-300 rounded-lg"></textarea>
                </div>

                <div id="edit-extra-actions" class="hidden grid grid-cols-2 gap-2 pt-1">
                    <button type="button" onclick="moveCurrentEventToTomorrow()" class="py-1.5 px-2 bg-sky-50 hover:bg-sky-100 text-sky-700 border border-sky-200 rounded-lg font-bold text-[11px] flex items-center justify-center gap-1">
                        <span>⏭️ انتقال برنامه به فردا</span>
                    </button>
                    <button type="button" onclick="startPomodoroForCurrentEvent()" class="py-1.5 px-2 bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-200 rounded-lg font-bold text-[11px] flex items-center justify-center gap-1">
                        <span>▶️ شروع تایمر برای این برنامه</span>
                    </button>
                </div>

                <div class="flex items-center justify-between pt-2 border-t border-slate-200">
                    <div class="flex items-center gap-1.5">
                        <button type="button" id="btn-delete-event" onclick="deleteCurrentEvent()" class="hidden px-2.5 py-2 bg-rose-50 text-rose-600 hover:bg-rose-100 rounded-lg font-bold">
                            حذف
                        </button>
                        <button type="button" id="btn-toggle-complete" onclick="toggleCurrentEventComplete()" class="hidden px-3 py-2 bg-emerald-600 text-white hover:bg-emerald-700 rounded-lg font-bold shadow-xs">
                            ✓ ثبت انجام کار
                        </button>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <button type="button" onclick="closeEventModal()" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-bold">
                            انصراف
                        </button>
                        <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-bold shadow">
                            ذخیره
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ==================================================== -->
    <!-- ۶. مودال خرید و تمدید اشتراک ویژه (VIP) + ارجاع هوشمند به تلگرام -->
    <!-- ==================================================== -->
    <div id="subscription-modal" class="fixed inset-0 bg-slate-900/75 backdrop-blur-xs hidden items-end sm:items-center justify-center sm:p-4 z-50">
        <div class="bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl w-full max-w-2xl max-h-[94vh] flex flex-col overflow-hidden">
            <!-- هدر طلایی/سرمه‌ای اشتراک -->
            <div class="bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white px-5 py-4 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-2.5">
                    <span class="text-2xl">👑</span>
                    <div>
                        <h3 class="font-extrabold text-sm md:text-base text-amber-300">اشتراک ویژه (VIP) سامانه مطالعه و تقویم کنکور</h3>
                        <p id="sub-modal-current-status" class="text-[11px] text-slate-300 mt-0.5"></p>
                    </div>
                </div>
                <button type="button" onclick="closeSubscriptionModal()" class="text-slate-400 hover:text-white text-2xl leading-none">&times;</button>
            </div>

            <div class="p-4 sm:p-5 overflow-y-auto calendar-scroll space-y-4 text-xs">
                
                <!-- پیام هشدار در صورتی که کاربر به خاطر محدودیت ۳ مگابایت یا ۱ فایل یا پیامک به این پنجره هدایت شده باشد -->
                <div id="sub-modal-reason-alert" class="hidden bg-rose-50 border border-rose-200 text-rose-800 px-3.5 py-2.5 rounded-xl font-bold leading-relaxed"></div>

                <!-- مقایسه امکانات کاربر عادی و کاربر ویژه -->
                <div class="bg-slate-50 rounded-2xl p-3.5 border border-slate-200">
                    <h4 class="font-extrabold text-slate-800 mb-2.5 text-xs">✨ با تهیه اشتراک ویژه چه امکاناتی برای شما باز می‌شود؟</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <div class="bg-white p-2.5 rounded-xl border border-slate-200 flex items-start gap-2">
                            <span class="text-base">📚</span>
                            <div>
                                <div class="font-extrabold text-slate-800">آپلود نامحدود جزوات و کتاب‌ها</div>
                                <div class="text-[11px] text-slate-500 mt-0.5">بدون محدودیت ۱ فایل! تمام کتاب‌ها و جزوات هر درس را در پوشه‌های مجزا ذخیره کنید.</div>
                            </div>
                        </div>
                        <div class="bg-white p-2.5 rounded-xl border border-slate-200 flex items-start gap-2">
                            <span class="text-base">🚀</span>
                            <div>
                                <div class="font-extrabold text-slate-800">حجم هر فایل تا <?php echo VIP_MAX_PDF_MB; ?> مگابایت</div>
                                <div class="text-[11px] text-slate-500 mt-0.5">حذف محدودیت ۳ مگابایت کاربر عادی و امکان آپلود PDFهای سنگین و اسکن‌شده.</div>
                            </div>
                        </div>
                        <div class="bg-white p-2.5 rounded-xl border border-slate-200 flex items-start gap-2">
                            <span class="text-base">📱</span>
                            <div>
                                <div class="font-extrabold text-slate-800">پیامک هوشمند پیگیری برنامه (sms.ir)</div>
                                <div class="text-[11px] text-slate-500 mt-0.5">ارسال خودکار پیامک خدماتی در صورت گذشتن زمان برنامه و تیک نخوردن آن در تقویم.</div>
                            </div>
                        </div>
                        <div class="bg-white p-2.5 rounded-xl border border-slate-200 flex items-start gap-2">
                            <span class="text-base">🔄</span>
                            <div>
                                <div class="font-extrabold text-slate-800">قابلیت تمدید زودهنگام تجمعی</div>
                                <div class="text-[11px] text-slate-500 mt-0.5">در صورت تمدید قبل از اتمام اشتراک، روزهای جدید دقیقاً به ادامه اعتبار قبلی شما اضافه می‌شود.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- کارت‌های انتخاب پلن ۱ ماهه و ۳ ماهه -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                    
                    <!-- پلن ۱ ماهه -->
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
                                <li>✓ آپلود نامحدود فایل PDF (تا <?php echo VIP_MAX_PDF_MB; ?> مگابایت)</li>
                                <li>✓ فعال‌سازی پنل پیامک یادآوری تقویم</li>
                                <li>✓ قابلیت تمدید قبل از اتمام مهلت</li>
                            </ul>
                        </div>
                        <a id="tg-link-1m" href="#" target="_blank" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-extrabold text-center shadow-sm transition flex items-center justify-center gap-1.5">
                            <span>✈️ سفارش ۱ ماهه در تلگرام</span>
                        </a>
                    </div>

                    <!-- پلن ۳ ماهه (پیشنهاد ویژه) -->
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
                                <li>✓ آپلود نامحدود فایل PDF + تمام پوشه‌ها</li>
                                <li>✓ پیامک یادآوری بدون محدودیت تا روز کنکور</li>
                            </ul>
                        </div>
                        <a id="tg-link-3m" href="#" target="_blank" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-extrabold text-center shadow-sm transition flex items-center justify-center gap-1.5">
                            <span>✈️ سفارش ۳ ماهه در تلگرام</span>
                        </a>
                    </div>
                </div>

                <!-- بخش ورود کد فعال‌سازی آنی -->
                <div class="bg-slate-900 text-white p-3.5 rounded-2xl flex flex-col sm:flex-row items-center justify-between gap-3">
                    <div class="text-center sm:text-right">
                        <div class="font-extrabold text-amber-300 text-xs">🎟️ کد فعال‌سازی اشتراک دریافت کرده‌اید؟</div>
                        <div class="text-[11px] text-slate-300 mt-0.5">کدی که پشتیبانی در تلگرام برایتان ارسال کرده را وارد کنید تا درجا فعال شود:</div>
                    </div>
                    <form onsubmit="handleRedeemCode(event)" class="flex items-center gap-1.5 w-full sm:w-auto">
                        <input type="text" id="redeem-code-input" required dir="ltr" placeholder="VIP90-XXXX-XXXX" class="flex-1 sm:w-44 px-3 py-2 rounded-xl bg-slate-800 border border-slate-600 text-white font-mono text-center font-bold focus:outline-none focus:border-amber-400">
                        <button type="submit" id="btn-redeem-submit" class="bg-amber-400 hover:bg-amber-300 text-slate-950 font-black px-3.5 py-2 rounded-xl shrink-0 transition">
                            فعال‌سازی
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </div>

    <!-- ==================================================== -->
    <!-- ۷. مودال کپی کردن برنامه‌های یک روز به روزی دیگر -->
    <!-- ==================================================== -->
    <div id="copy-day-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
            <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between">
                <h3 class="font-bold text-xs md:text-sm">📋 کپی برنامه‌های یک روز به روز دیگر</h3>
                <button type="button" onclick="closeCopyDayModal()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <form onsubmit="handleCopyDay(event)" class="p-4 space-y-3.5 text-xs">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">کپی برنامه‌ها از روز (مبدا):</label>
                    <select id="copy-source-date" required class="w-full px-3 py-2 border border-slate-300 rounded-lg bg-white"></select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">به روز (مقصد):</label>
                    <select id="copy-target-date" required class="w-full px-3 py-2 border border-slate-300 rounded-lg bg-white"></select>
                </div>
                <label class="flex items-center gap-2 cursor-pointer pt-1">
                    <input type="checkbox" id="copy-only-study" value="1" checked class="w-4 h-4 accent-indigo-600 rounded">
                    <span class="font-bold text-slate-700">فقط برنامه‌های درسی کپی شوند (حذف متفرقه‌ها)</span>
                </label>
                <div class="flex justify-end gap-2 pt-2 border-t">
                    <button type="button" onclick="closeCopyDayModal()" class="px-3 py-1.5 bg-slate-100 rounded-lg font-bold">انصراف</button>
                    <button type="submit" class="px-4 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-bold">کپی برنامه‌ها</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==================================================== -->
    <!-- ۸. مودال ساخت پوشه درسی جدید -->
    <!-- ==================================================== -->
    <div id="folder-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
            <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between">
                <h3 class="font-bold text-xs md:text-sm">📁 ساخت پوشه درسی جدید</h3>
                <button type="button" onclick="closeFolderModal()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <form id="folder-form" onsubmit="handleCreateFolder(event)" class="p-4 space-y-3.5 text-xs">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">نام درس / پوشه *</label>
                    <input type="text" id="folder-name-input" name="name" required placeholder="مثلاً: زیست‌شناسی، شیمی، ریاضی..." class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">رنگ پوشه</label>
                    <div class="flex items-center gap-2">
                        <input type="color" name="color" value="#4f46e5" class="w-10 h-8 rounded cursor-pointer border border-slate-300">
                        <span class="text-slate-500 text-[11px]">یک رنگ مشخص برای تفکیک این درس انتخاب کنید</span>
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t">
                    <button type="button" onclick="closeFolderModal()" class="px-3 py-1.5 bg-slate-100 rounded-lg font-bold">انصراف</button>
                    <button type="submit" id="btn-submit-folder" class="px-4 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-bold">ساخت پوشه</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==================================================== -->
    <!-- ۹. مودال انتقال جزوه به پوشه دیگر -->
    <!-- ==================================================== -->
    <div id="move-pdf-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
            <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between">
                <h3 class="font-bold text-xs md:text-sm">📁 انتقال جزوه به پوشه درسی</h3>
                <button type="button" onclick="closeMovePdfModal()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <form onsubmit="handleMovePdf(event)" class="p-4 space-y-3.5 text-xs">
                <input type="hidden" id="move-pdf-id">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">انتخاب پوشه مقصد:</label>
                    <select id="move-folder-select" class="w-full px-3 py-2 border border-slate-300 rounded-lg bg-white">
                        <option value="">📂 عمومی (بدون پوشه)</option>
                    </select>
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t">
                    <button type="button" onclick="closeMovePdfModal()" class="px-3 py-1.5 bg-slate-100 rounded-lg font-bold">انصراف</button>
                    <button type="submit" class="px-4 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-bold">انتقال جزوه</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==================================================== -->
    <!-- ۱۰. مودال آپلود جزوه PDF (با نمایش سقف حجم مجاز کاربر) -->
    <!-- ==================================================== -->
    <div id="upload-pdf-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
            <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between">
                <h3 class="font-bold text-xs md:text-sm">آپلود جزوه PDF جدید</h3>
                <button type="button" onclick="closeUploadModal()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <form id="upload-pdf-form" onsubmit="handlePdfUpload(event)" class="p-4 space-y-3.5 text-xs">
                <div id="upload-modal-tier-hint" class="p-2.5 rounded-xl bg-slate-100 text-slate-700 text-[11px] font-bold"></div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">عنوان جزوه *</label>
                    <input type="text" name="title" required placeholder="مثلاً: جزوه جمع‌بندی فصل ۳ زیست" class="w-full px-3 py-2 border border-slate-300 rounded-lg">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">انتخاب پوشه درسی</label>
                    <select id="upload-folder-select" name="folder_id" class="w-full px-3 py-2 border border-slate-300 rounded-lg bg-white">
                        <option value="">📂 عمومی (بدون پوشه)</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">فایل PDF *</label>
                    <input type="file" id="upload-pdf-file-input" name="pdf_file" accept="application/pdf" required class="w-full border border-slate-300 rounded-lg p-2 bg-slate-50">
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t">
                    <button type="button" onclick="closeUploadModal()" class="px-3.5 py-2 bg-slate-100 rounded-lg font-bold">انصراف</button>
                    <button type="submit" id="btn-submit-upload" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-bold">آپلود و ذخیره</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==================================================== -->
    <!-- ۱۱. مودال نمایش صفحات ستاره‌دار جزوه فعال -->
    <!-- ==================================================== -->
    <div id="starred-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
            <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between">
                <h3 class="font-bold text-xs md:text-sm">⭐ صفحات ستاره‌دار (نکات مهم جزوه)</h3>
                <button type="button" onclick="closeStarredModal()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <div id="starred-pages-list" class="p-4 max-h-72 overflow-y-auto space-y-2 text-xs"></div>
        </div>
    </div>

    <!-- ==================================================== -->
    <!-- ۱۲. مودال تنظیمات شماره موبایل و پیامک یادآوری sms.ir -->
    <!-- ==================================================== -->
    <div id="phone-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
            <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between">
                <h3 class="font-bold text-xs md:text-sm">📱 تنظیمات پیامک یادآوری (sms.ir)</h3>
                <button type="button" onclick="closePhoneModal()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <form onsubmit="handleUpdatePhone(event)" class="p-4 space-y-3.5 text-xs">
                <div id="phone-modal-vip-notice" class="hidden bg-amber-50 border border-amber-300 text-amber-900 p-2.5 rounded-xl text-[11px] font-bold leading-relaxed">
                    🔒 ارسال خودکار پیامک یادآوری مخصوص مشترکین ویژه است.
                    <button type="button" onclick="closePhoneModal(); openSubscriptionModal('برای فعال‌سازی ارسال خودکار پیامک یادآوری، اشتراک ویژه تهیه کنید.');" class="text-indigo-600 underline mr-1 font-extrabold">مشاهده پلن‌های اشتراک</button>
                </div>
                <p class="text-slate-600 leading-relaxed">
                    اگر زمان پایان یک برنامه بگذرد و تیک انجام آن را نزده باشید، سیستم به صورت خودکار از طریق خط خدماتی <b>sms.ir</b> به شماره زیر پیامک یادآوری می‌فرستد:
                </p>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">شماره موبایل شما *</label>
                    <input type="tel" id="user-phone-input" required dir="ltr" pattern="09[0-9]{9}" maxlength="11" placeholder="09123456789" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-left font-bold">
                </div>
                <div class="flex justify-between items-center pt-2 border-t">
                    <button type="button" onclick="triggerSmsCheckNow(true)" class="text-indigo-600 hover:underline font-bold">🔄 بررسی آنی برنامه‌ها</button>
                    <div class="flex gap-1.5">
                        <button type="button" onclick="closePhoneModal()" class="px-3 py-1.5 bg-slate-100 rounded-lg font-bold">بستن</button>
                        <button type="submit" class="px-4 py-1.5 bg-indigo-600 text-white rounded-lg font-bold">ذخیره شماره</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ====================================================
        // ۰. تضمین بارگذاری صددرصدی PDF.js در تمام مرورگرها
        // ====================================================
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
            },
            {
                lib: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@2.16.105/build/pdf.min.js',
                worker: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@2.16.105/build/pdf.worker.min.js',
                cmaps: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@2.16.105/cmaps/',
                fonts: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@2.16.105/standard_fonts/'
            }
        ];
        let activeCdnConfig = PDFJS_CDN_LIST[0];

        function loadScriptDynamic(src) {
            return new Promise((resolve, reject) => {
                const s = document.createElement('script');
                s.src = src;
                s.async = true;
                s.onload = () => resolve();
                s.onerror = () => reject(new Error('Failed to load ' + src));
                document.head.appendChild(s);
            });
        }

        async function ensurePdfJsReady() {
            if (typeof window.pdfjsLib !== 'undefined') {
                window.pdfjsLib.GlobalWorkerOptions.workerSrc = activeCdnConfig.worker;
                return window.pdfjsLib;
            }
            for (const cdn of PDFJS_CDN_LIST) {
                try {
                    await loadScriptDynamic(cdn.lib);
                    if (typeof window.pdfjsLib !== 'undefined') {
                        activeCdnConfig = cdn;
                        window.pdfjsLib.GlobalWorkerOptions.workerSrc = cdn.worker;
                        return window.pdfjsLib;
                    }
                } catch (e) {}
            }
            throw new Error('موتور نمایشگر PDF به دلیل اختلال اینترنت بارگذاری نشد. لطفاً صفحه را رفرش کنید.');
        }

        if (typeof window.pdfjsLib !== 'undefined') {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = activeCdnConfig.worker;
        }

        // ====================================================
        // متغیرهای پایه، وضعیت اشتراک کاربر و تنظیمات فروش تلگرام
        // ====================================================
        const TODAY_GREGORIAN   = "<?php echo date('Y-m-d'); ?>";
        const KONKUR_GREGORIAN  = "<?php echo KONKUR_GREGORIAN_DATE; ?>";
        const TELEGRAM_SALES_ID = "<?php echo htmlspecialchars(TELEGRAM_SALES_ID); ?>";
        const FREE_MAX_PDF_CNT  = <?php echo (int)FREE_MAX_PDF_COUNT; ?>;
        const FREE_MAX_PDF_MB   = <?php echo (int)FREE_MAX_PDF_MB; ?>;
        const VIP_MAX_PDF_MB    = <?php echo (int)VIP_MAX_PDF_MB; ?>;

        let userSub = <?php echo json_encode($initial_sub, JSON_UNESCAPED_UNICODE); ?>;

        let viewMode = window.innerWidth < 768 ? 'day' : 'week';
        let calendarFilter = 'all';
        let anchorDate = new Date(TODAY_GREGORIAN + 'T00:00:00');
        let loadedEvents = [];
        let customCategories = [];

        const DEFAULT_CATEGORIES = [
            { id: 'def_1', name: 'تخصصی',  color: '#4f46e5', is_study: 1, isDefault: true },
            { id: 'def_2', name: 'مرور',   color: '#059669', is_study: 1, isDefault: true },
            { id: 'def_3', name: 'تست‌زنی', color: '#d97706', is_study: 1, isDefault: true },
            { id: 'def_4', name: 'آزمون',  color: '#e11d48', is_study: 1, isDefault: true },
            { id: 'def_5', name: 'متفرقه', color: '#7c3aed', is_study: 0, isDefault: true }
        ];

        const CUSTOM_ALLOWED_COLORS = [
            { hex: '#0891b2', title: 'فیروزه‌ای' },
            { hex: '#db2777', title: 'سرخابی' },
            { hex: '#ea580c', title: 'نارنجی' },
            { hex: '#65a30d', title: 'سبز لیمویی' },
            { hex: '#0284c7', title: 'آبی آسمانی' },
            { hex: '#854d0e', title: 'قهوه‌ای برنزی' },
            { hex: '#334155', title: 'سرمه‌ای تیره' },
            { hex: '#f43f5e', title: 'مرجانی' },
            { hex: '#0d9488', title: 'سبز دریایی' },
            { hex: '#c026d3', title: 'ارغوانی' }
        ];
        let selectedNewCatColor = CUSTOM_ALLOWED_COLORS[0].hex;

        const JALALI_MONTHS = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
        const WEEKDAY_NAMES = ['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنج‌شنبه','جمعه'];

        // ====================================================
        // مدیریت رابط کاربری اشتراک ویژه، لینک هوشمند تلگرام و کد فعال‌سازی
        // ====================================================
        function refreshSubscriptionUI() {
            const headerBtn = document.getElementById('header-sub-btn');
            if (headerBtn) {
                if (userSub.is_vip) {
                    headerBtn.className = 'bg-gradient-to-r from-amber-500 to-yellow-400 text-slate-950 px-2.5 py-1.5 rounded-lg font-extrabold flex items-center gap-1 shadow-xs transition';
                    headerBtn.innerHTML = `<span>👑 ویژه (${userSub.days_left} روز | +تمدید)</span>`;
                } else {
                    headerBtn.className = 'bg-indigo-600 hover:bg-indigo-500 text-white border border-indigo-400 px-2.5 py-1.5 rounded-lg font-extrabold flex items-center gap-1 shadow-xs transition';
                    headerBtn.innerHTML = `<span>⭐ ارتقا به اشتراک ویژه</span>`;
                }
            }

            // بنر بالای سایدبار PDF
            const quotaBanner = document.getElementById('pdf-quota-banner');
            if (quotaBanner) {
                if (userSub.is_vip) {
                    quotaBanner.className = 'px-3 py-2 text-[11px] border-b border-emerald-200 flex items-center justify-between bg-emerald-50 text-emerald-900 font-bold';
                    quotaBanner.innerHTML = `
                        <span>👑 اشتراک ویژه فعال (${userSub.days_left} روز مانده)</span>
                        <button type="button" onclick="openSubscriptionModal()" class="text-indigo-700 underline font-extrabold">+ تمدید</button>
                    `;
                } else {
                    quotaBanner.className = 'px-3 py-2 text-[11px] border-b border-amber-200 flex items-center justify-between bg-amber-50 text-amber-900 font-bold';
                    quotaBanner.innerHTML = `
                        <span>👤 سهمیه عادی: ${pdfList.length} از ${FREE_MAX_PDF_CNT} فایل (سقف ${FREE_MAX_PDF_MB}MB)</span>
                        <button type="button" onclick="openSubscriptionModal()" class="bg-indigo-600 text-white px-2 py-0.5 rounded-md font-extrabold text-[10px]">ارتقا 👑</button>
                    `;
                }
            }

            // نشان قفل کنار تیک پیامک در مودال تقویم
            const smsLockBadge = document.getElementById('sms-vip-lock-badge');
            const smsCheckbox  = document.getElementById('ev-send-sms');
            if (smsLockBadge && smsCheckbox) {
                if (userSub.is_vip) {
                    smsLockBadge.classList.add('hidden');
                } else {
                    smsLockBadge.classList.remove('hidden');
                    smsCheckbox.checked = false;
                }
            }

            // بنر داخل مودال تنظیمات شماره موبایل
            const phoneVipNotice = document.getElementById('phone-modal-vip-notice');
            if (phoneVipNotice) {
                if (userSub.is_vip) phoneVipNotice.classList.add('hidden');
                else phoneVipNotice.classList.remove('hidden');
            }
        }

        function openSubscriptionModal(reasonMessage = '') {
            refreshSubscriptionUI();

            const statusEl = document.getElementById('sub-modal-current-status');
            if (userSub.is_vip) {
                statusEl.innerHTML = `وضعیت فعلی شما: <b class="text-emerald-300">مشترک ویژه فعال (${userSub.days_left} روز باقی‌مانده - تا ${userSub.expires_j})</b> — با خرید مجدد، روزها به اعتبار فعلی شما اضافه می‌شوند.`;
            } else {
                statusEl.innerHTML = `وضعیت فعلی شما: <b class="text-amber-200">کاربر عادی (رایگان)</b> — حداکثر ۱ فایل PDF تا سقف ۳ مگابایت و بدون پیامک یادآوری.`;
            }

            const reasonBox = document.getElementById('sub-modal-reason-alert');
            if (reasonMessage) {
                reasonBox.textContent = '⚠️ ' + reasonMessage;
                reasonBox.classList.remove('hidden');
            } else {
                reasonBox.classList.add('hidden');
            }

            // ساخت لینک‌های هوشمند تلگرام با متن آماده خودکار
            const actionType = userSub.is_vip ? 'تمدید اشتراک' : 'خرید اشتراک';
            const uName      = userSub.full_name || '';
            const uId        = userSub.username || '';
            const uPhone     = userSub.phone || document.getElementById('user-phone-input')?.value || 'ثبت‌نشده';

            const msg1m = `سلام وقت بخیر، درخواست ${actionType} ویژه ۱ ماهه (۵۰۰,۰۰۰ تومان) را دارم.\n👤 نام: ${uName}\n🆔 نام کاربری در سایت: ${uId}\n📱 موبایل: ${uPhone}`;
            const msg3m = `سلام وقت بخیر، درخواست ${actionType} ویژه ۳ ماهه (۱,۲۰۰,۰۰۰ تومان - با تخفیف) را دارم.\n👤 نام: ${uName}\n🆔 نام کاربری در سایت: ${uId}\n📱 موبایل: ${uPhone}`;

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
            const codeInput = document.getElementById('redeem-code-input');
            const btn = document.getElementById('btn-redeem-submit');
            const code = codeInput.value.trim();
            if (!code) return;

            btn.disabled = true;
            btn.textContent = '...';

            try {
                const fd = new FormData();
                fd.append('action', 'redeem_code');
                fd.append('code', code);

                const res = await fetch('api_subscription.php', { method: 'POST', body: fd });
                const data = await res.json();
                alert(data.message);

                if (data.status === 'success') {
                    userSub = data.subscription;
                    codeInput.value = '';
                    refreshSubscriptionUI();
                    closeSubscriptionModal();
                    fetchUserPdfs();
                }
            } catch (err) {
                alert('خطا در ارتباط با سرور.');
            } finally {
                btn.disabled = false;
                btn.textContent = 'فعال‌سازی';
            }
        }

        function handleSmsCheckboxClick(e) {
            if (!userSub.is_vip) {
                e.preventDefault();
                document.getElementById('ev-send-sms').checked = false;
                openSubscriptionModal('قابلیت ارسال پیامک یادآوری خودکار (از طریق sms.ir) مخصوص کاربران دارای اشتراک ویژه است.');
            }
        }

        // ====================================================
        // توابع تاریخ شمسی و تقویم
        // ====================================================
        function gregorianToJalali(gy, gm, gd) {
            const g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
            const gy2 = (gm > 2) ? (gy + 1) : gy;
            let days = 355666 + (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) + gd + g_d_m[gm - 1];
            let jy = -1595 + (33 * Math.floor(days / 12053));
            days %= 12053;
            jy += 4 * Math.floor(days / 1461);
            days %= 1461;
            if (days > 365) {
                jy += Math.floor((days - 1) / 365);
                days = (days - 1) % 365;
            }
            const jm = (days < 186) ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
            const jd = (days < 186) ? 1 + (days % 31) : 1 + ((days - 186) % 30);
            return [jy, jm, jd];
        }

        function formatGDate(dateObj) {
            return `${dateObj.getFullYear()}-${String(dateObj.getMonth() + 1).padStart(2, '0')}-${String(dateObj.getDate()).padStart(2, '0')}`;
        }

        function getJalaliInfo(dateObj) {
            const [jy, jm, jd] = gregorianToJalali(dateObj.getFullYear(), dateObj.getMonth() + 1, dateObj.getDate());
            const iranianDayIdx = (dateObj.getDay() + 1) % 7;
            return {
                jy, jm, jd,
                dayName: WEEKDAY_NAMES[iranianDayIdx],
                monthName: JALALI_MONTHS[jm - 1]
            };
        }

        function getFullWeekDays(refDate) {
            const d = new Date(refDate);
            const diffToSat = (d.getDay() + 1) % 7;
            d.setDate(d.getDate() - diffToSat);
            const week = [];
            for (let i = 0; i < 7; i++) {
                const clone = new Date(d);
                clone.setDate(d.getDate() + i);
                week.push(clone);
            }
            return week;
        }

        function computeVisibleDays() {
            return viewMode === 'day' ? [new Date(anchorDate)] : getFullWeekDays(anchorDate);
        }

        function initTimeLabels() {
            const col = document.getElementById('time-labels-col');
            let html = '';
            for (let h = 0; h < 24; h++) {
                html += `<div class="hour-row border-b border-slate-100 flex items-start justify-center pt-1">${String(h).padStart(2, '0')}:00</div>`;
            }
            col.innerHTML = html;
        }

        function isEventStudyType(ev) {
            if (ev.is_study !== undefined && ev.is_study !== null) {
                return Number(ev.is_study) === 1;
            }
            return ev.category_type !== 'misc' && String(ev.color).toLowerCase() !== '#7c3aed';
        }

        // ====================================================
        // مدیریت دسته‌ها و رنگ‌های سفارشی کاربر
        // ====================================================
        function initCustomColorPalette() {
            const container = document.getElementById('custom-color-palette');
            container.innerHTML = CUSTOM_ALLOWED_COLORS.map(c => `
                <button type="button" onclick="selectCustomPaletteColor('${c.hex}')" data-hex="${c.hex}" title="${c.title}"
                    class="custom-pal-btn w-6 h-6 rounded-full transition ${c.hex === selectedNewCatColor ? 'ring-2 ring-slate-900 scale-110' : 'opacity-65 hover:opacity-100'}"
                    style="background-color:${c.hex}"></button>
            `).join('');
        }

        function selectCustomPaletteColor(hex) {
            selectedNewCatColor = hex;
            initCustomColorPalette();
        }

        function toggleNewCategoryBox() {
            const box = document.getElementById('new-category-box');
            box.classList.toggle('hidden');
            if (!box.classList.contains('hidden')) {
                initCustomColorPalette();
                document.getElementById('new-cat-name').focus();
            }
        }

        function renderCategoryOptionsInModal(selectedColor = '#4f46e5') {
            const grid = document.getElementById('event-categories-grid');
            const normSelected = String(selectedColor).toLowerCase();
            const allCats = [...DEFAULT_CATEGORIES, ...customCategories];

            grid.innerHTML = allCats.map(cat => {
                const isChecked = String(cat.color).toLowerCase() === normSelected;
                const studyBadge = Number(cat.is_study) === 1 ? '📚' : '🧩';
                const delBtn = cat.isDefault ? '' : `
                    <span onclick="deleteCustomCategory(event, ${cat.id})" class="text-slate-400 hover:text-rose-600 font-extrabold px-1 mr-auto" title="حذف این نوع">&times;</span>
                `;
                return `
                    <label class="flex items-center gap-1 cursor-pointer bg-slate-50 px-2 py-1.5 rounded-lg border hover:border-indigo-400 transition">
                        <input type="radio" name="color" value="${cat.color}" ${isChecked ? 'checked' : ''}>
                        <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color:${cat.color}"></span>
                        <span class="text-[11px] font-bold truncate" title="${escapeHtml(cat.name)}">${escapeHtml(cat.name)}</span>
                        <span class="text-[9px] opacity-75" title="${Number(cat.is_study) === 1 ? 'درسی' : 'متفرقه'}">${studyBadge}</span>
                        ${delBtn}
                    </label>
                `;
            }).join('');
        }

        async function saveNewCustomCategory() {
            const nameInput = document.getElementById('new-cat-name');
            const name = nameInput.value.trim();
            const isStudy = document.getElementById('new-cat-is-study').value;

            if (!name) {
                alert('لطفاً نام نوع برنامه جدید را وارد کنید.');
                return;
            }

            const fd = new FormData();
            fd.append('action', 'create_category');
            fd.append('name', name);
            fd.append('color', selectedNewCatColor);
            fd.append('is_study', isStudy);

            const res = await fetch('api_calendar.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                customCategories.push(data.category);
                nameInput.value = '';
                document.getElementById('new-category-box').classList.add('hidden');
                renderCategoryOptionsInModal(data.category.color);
            } else {
                alert(data.message || 'خطا در ثبت نوع جدید');
            }
        }

        async function deleteCustomCategory(e, catId) {
            e.preventDefault();
            e.stopPropagation();
            if (!confirm('آیا این نوع سفارشی از لیست شما حذف شود؟')) return;

            const fd = new FormData();
            fd.append('action', 'delete_category');
            fd.append('category_id', catId);

            const res = await fetch('api_calendar.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                customCategories = customCategories.filter(c => Number(c.id) !== Number(catId));
                renderCategoryOptionsInModal('#4f46e5');
            }
        }

        // ====================================================
        // رندر تقویم + چیدمان کنار هم (Side-by-Side)
        // ====================================================
        async function renderCalendar() {
            const days = computeVisibleDays();
            const fullWeek = getFullWeekDays(anchorDate);
            const headerEl = document.getElementById('calendar-days-header');
            const gridEl   = document.getElementById('calendar-grid-cols');
            const stripEl  = document.getElementById('mobile-week-strip');

            document.getElementById('btn-view-week').className = viewMode === 'week'
                ? 'px-2.5 py-1 rounded-md text-xs font-bold bg-white shadow-2xs text-indigo-600'
                : 'px-2.5 py-1 rounded-md text-xs font-bold text-slate-600';
            document.getElementById('btn-view-day').className = viewMode === 'day'
                ? 'px-2.5 py-1 rounded-md text-xs font-bold bg-white shadow-2xs text-indigo-600'
                : 'px-2.5 py-1 rounded-md text-xs font-bold text-slate-600';

            if (viewMode === 'day') {
                stripEl.classList.remove('hidden');
                stripEl.classList.add('grid');
                const activeG = formatGDate(anchorDate);
                stripEl.innerHTML = fullWeek.map(wd => {
                    const gStr = formatGDate(wd);
                    const jInf = getJalaliInfo(wd);
                    const isSelected = (gStr === activeG);
                    const isLocked = (gStr > KONKUR_GREGORIAN);
                    return `
                        <button type="button" onclick="${isLocked ? '' : `selectDayForDailyView('${gStr}')`}" class="py-1.5 rounded-xl flex flex-col items-center justify-center transition ${isSelected ? 'bg-indigo-600 text-white shadow-xs' : 'bg-white text-slate-700 border border-slate-200'} ${isLocked ? 'opacity-40' : ''}">
                            <span class="text-[10px] font-bold">${jInf.dayName.slice(0,1)}</span>
                            <span class="text-xs font-extrabold mt-0.5">${jInf.jd}</span>
                        </button>
                    `;
                }).join('');
            } else {
                stripEl.classList.add('hidden');
                stripEl.classList.remove('grid');
            }

            const firstJ = getJalaliInfo(days[0]);
            const lastJ  = getJalaliInfo(days[days.length - 1]);
            document.getElementById('calendar-range-title').textContent = (viewMode === 'day')
                ? `${firstJ.dayName} ${firstJ.jd} ${firstJ.monthName} ${firstJ.jy}`
                : `${firstJ.jd} ${firstJ.monthName} تا ${lastJ.jd} ${lastJ.monthName}`;

            const selectDateEl = document.getElementById('ev-date');
            const copySrcEl    = document.getElementById('copy-source-date');
            const copyDstEl    = document.getElementById('copy-target-date');
            let dateOptsHtml = '';
            fullWeek.forEach(wd => {
                const gStr = formatGDate(wd);
                if (gStr <= KONKUR_GREGORIAN) {
                    const jInf = getJalaliInfo(wd);
                    dateOptsHtml += `<option value="${gStr}">${jInf.dayName} ${jInf.jd} ${jInf.monthName}</option>`;
                }
            });
            selectDateEl.innerHTML = dateOptsHtml;
            if (copySrcEl) copySrcEl.innerHTML = dateOptsHtml;
            if (copyDstEl) copyDstEl.innerHTML = dateOptsHtml;

            try {
                const startG = formatGDate(fullWeek[0]);
                const endG   = formatGDate(fullWeek[6]);
                const res    = await fetch(`api_calendar.php?action=list&start=${startG}&end=${endG}`);
                const data   = await res.json();

                if (data.status === 'success') {
                    loadedEvents = data.events || [];
                    customCategories = data.custom_categories || [];
                    if (data.subscription) {
                        userSub = data.subscription;
                        refreshSubscriptionUI();
                    }
                    if (data.user_phone) {
                        document.getElementById('user-phone-input').value = data.user_phone;
                    }
                    updateCalendarStatsBar(data.stats || {});
                }
            } catch (err) {
                console.error('Calendar fetch error:', err);
            }

            let headerHtml = '';
            let gridHtml   = '';

            days.forEach(dObj => {
                const gStr = formatGDate(dObj);
                const jInfo = getJalaliInfo(dObj);
                const isToday = (gStr === TODAY_GREGORIAN);
                const isKonkurDay = (gStr === KONKUR_GREGORIAN);
                const isLocked = (gStr > KONKUR_GREGORIAN);

                const dayStudyEvents = loadedEvents.filter(e => e.g_date === gStr && isEventStudyType(e));
                let dayPlannedMins = 0;
                let dayDoneMins = 0;
                dayStudyEvents.forEach(e => {
                    const [sh, sm] = e.start_time.split(':').map(Number);
                    const [eh, em] = e.end_time.split(':').map(Number);
                    const dur = Math.max(0, ((eh * 60) + em) - ((sh * 60) + sm));
                    dayPlannedMins += dur;
                    if (Number(e.is_completed) === 1) dayDoneMins += dur;
                });
                const dayPlannedH = +(dayPlannedMins / 60).toFixed(1);
                const dayDoneH    = +(dayDoneMins / 60).toFixed(1);
                const dayPct      = dayPlannedMins > 0 ? Math.round((dayDoneMins / dayPlannedMins) * 100) : 0;

                let badgeHtml = '';
                if (isKonkurDay) {
                    badgeHtml = `<span class="block text-[9px] bg-rose-600 text-white px-1 rounded-full mt-0.5">🎯 کنکور</span>`;
                } else if (isLocked) {
                    badgeHtml = `<span class="block text-[9px] text-slate-400 mt-0.5">🔒 قفل</span>`;
                } else if (dayPlannedH > 0) {
                    badgeHtml = `
                        <div class="mt-1 px-1">
                            <div class="text-[9px] font-bold text-slate-600 truncate">📚${dayPlannedH}س | <span class="text-emerald-600">✅${dayDoneH}س</span></div>
                            <div class="w-full h-1 bg-slate-200 rounded-full overflow-hidden mt-0.5">
                                <div class="h-full bg-emerald-500" style="width:${dayPct}%"></div>
                            </div>
                        </div>
                    `;
                }

                headerHtml += `
                    <div onclick="selectDayForDailyView('${gStr}')" class="flex-1 py-1.5 px-1 text-center border-l border-slate-200 cursor-pointer hover:bg-slate-100 transition ${isToday ? 'bg-indigo-50/80' : ''}">
                        <div class="text-[11px] font-bold ${isToday ? 'text-indigo-600' : 'text-slate-600'}">${jInfo.dayName}</div>
                        <div class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-extrabold mt-0.5 ${isToday ? 'bg-indigo-600 text-white' : 'text-slate-800'}">
                            ${jInfo.jd}
                        </div>
                        ${badgeHtml}
                    </div>
                `;

                let hoursCells = '';
                for (let h = 0; h < 24; h++) {
                    const startH = String(h).padStart(2, '0') + ':00';
                    const endH   = String(Math.min(23, h + 1)).padStart(2, '0') + (h === 23 ? ':59' : ':00');
                    const clickAttr = isLocked ? '' : `onclick="openEventModal('${gStr}', '${startH}', '${endH}')"`;
                    hoursCells += `<div ${clickAttr} class="hour-row border-b border-slate-100 ${isLocked ? '' : 'hover:bg-indigo-50/40 cursor-pointer'}"></div>`;
                }

                gridHtml += `
                    <div class="flex-1 border-l border-slate-200 relative ${isLocked ? 'locked-day-pattern' : ''}" data-gdate="${gStr}">
                        ${hoursCells}
                        <div class="events-layer absolute inset-0 pointer-events-none"></div>
                    </div>
                `;
            });

            headerEl.innerHTML = headerHtml;
            gridEl.innerHTML   = gridHtml;

            drawEventsOnGrid();
            drawCurrentTimeIndicator();
        }

        function computeSideBySideLayout(dayEvents) {
            const items = dayEvents.map(ev => {
                const [sh, sm] = ev.start_time.split(':').map(Number);
                const [eh, em] = ev.end_time.split(':').map(Number);
                const startMins = (sh * 60) + sm;
                const endMins   = Math.max(startMins + 25, (eh * 60) + em);
                return { ev, startMins, endMins, colIdx: 0, totalCols: 1 };
            });

            items.sort((a, b) => (a.startMins - b.startMins) || (b.endMins - a.endMins));

            let clusters = [];
            let currentCluster = [];
            let clusterMaxEnd = -1;

            items.forEach(item => {
                if (currentCluster.length === 0) {
                    currentCluster.push(item);
                    clusterMaxEnd = item.endMins;
                } else if (item.startMins < clusterMaxEnd) {
                    currentCluster.push(item);
                    clusterMaxEnd = Math.max(clusterMaxEnd, item.endMins);
                } else {
                    clusters.push(currentCluster);
                    currentCluster = [item];
                    clusterMaxEnd = item.endMins;
                }
            });
            if (currentCluster.length > 0) clusters.push(currentCluster);

            clusters.forEach(cluster => {
                const colLastEnd = [];
                cluster.forEach(item => {
                    let placedCol = -1;
                    for (let c = 0; c < colLastEnd.length; c++) {
                        if (colLastEnd[c] <= item.startMins) {
                            placedCol = c;
                            colLastEnd[c] = item.endMins;
                            break;
                        }
                    }
                    if (placedCol === -1) {
                        placedCol = colLastEnd.length;
                        colLastEnd.push(item.endMins);
                    }
                    item.colIdx = placedCol;
                });

                const totalCols = Math.max(1, colLastEnd.length);
                cluster.forEach(item => {
                    item.totalCols = totalCols;
                });
            });

            return items;
        }

        function drawEventsOnGrid() {
            const filtered = loadedEvents.filter(ev => {
                if (calendarFilter === 'study') return isEventStudyType(ev);
                if (calendarFilter === 'misc')  return !isEventStudyType(ev);
                return true;
            });

            const byDate = {};
            filtered.forEach(ev => {
                if (!byDate[ev.g_date]) byDate[ev.g_date] = [];
                byDate[ev.g_date].push(ev);
            });

            Object.keys(byDate).forEach(gDate => {
                const dayCol = document.querySelector(`[data-gdate="${gDate}"] .events-layer`);
                if (!dayCol) return;

                const positionedItems = computeSideBySideLayout(byDate[gDate]);

                positionedItems.forEach(({ ev, startMins, endMins, colIdx, totalCols }) => {
                    const duration = Math.max(26, endMins - startMins);
                    const widthPct = 100 / totalCols;
                    const rightPct = colIdx * widthPct;

                    const card = document.createElement('div');
                    card.className = 'cal-event-card pointer-events-auto absolute rounded-lg p-1.5 text-white shadow-md cursor-pointer overflow-hidden transition flex flex-col justify-between border border-white/25';
                    card.style.top    = `${startMins}px`;
                    card.style.height = `${duration}px`;
                    card.style.right  = `calc(${rightPct}% + 2px)`;
                    card.style.width  = `calc(${widthPct}% - 4px)`;
                    card.style.zIndex = String(10 + colIdx);
                    card.style.backgroundColor = ev.color || '#4f46e5';

                    if (Number(ev.is_completed) === 1) {
                        card.style.opacity = '0.68';
                    }

                    card.onclick = (e) => {
                        e.stopPropagation();
                        openEventModalForEdit(ev);
                    };

                    const checkBadge = Number(ev.is_completed) === 1 ? '✅ ' : '';
                    const catLabel = ev.category_label ? `<span class="opacity-85 text-[9px]">[${escapeHtml(ev.category_label)}]</span> ` : '';

                    let badgesHtml = '<div class="flex flex-wrap gap-1 mt-0.5">';
                    if (ev.pdf_id) {
                        badgesHtml += `<span class="bg-black/30 px-1 py-0.5 rounded text-[9px] font-bold">📖 ص${ev.pdf_page}</span>`;
                    }
                    if (ev.test_percent !== null && ev.test_percent !== undefined && ev.test_percent !== '') {
                        badgesHtml += `<span class="bg-amber-300 text-slate-900 px-1 py-0.5 rounded text-[9px] font-extrabold">🎯${ev.test_percent}%</span>`;
                    }
                    badgesHtml += '</div>';

                    card.innerHTML = `
                        <div class="min-w-0">
                            <div class="text-[10px] md:text-[11px] font-extrabold leading-tight truncate ${Number(ev.is_completed) === 1 ? 'line-through' : ''}" title="${escapeHtml(ev.title)}">
                                ${checkBadge}${catLabel}${escapeHtml(ev.title)}
                            </div>
                            <div class="text-[9px] opacity-90">${ev.start_time.slice(0,5)} - ${ev.end_time.slice(0,5)}</div>
                        </div>
                        ${badgesHtml}
                    `;
                    dayCol.appendChild(card);
                });
            });
        }

        function setCalendarFilter(filter) {
            calendarFilter = filter;
            ['all', 'study', 'misc'].forEach(f => {
                const btn = document.getElementById('filter-' + f);
                if (btn) {
                    btn.className = (f === filter)
                        ? 'px-2 py-1 rounded-md font-bold bg-white shadow-2xs text-indigo-600'
                        : 'px-2 py-1 rounded-md font-bold text-slate-600';
                }
            });
            document.querySelectorAll('.events-layer').forEach(el => el.innerHTML = '');
            drawEventsOnGrid();
            drawCurrentTimeIndicator();
        }

        function drawCurrentTimeIndicator() {
            const todayCol = document.querySelector(`[data-gdate="${TODAY_GREGORIAN}"] .events-layer`);
            if (!todayCol) return;
            const now = new Date();
            const mins = (now.getHours() * 60) + now.getMinutes();
            const line = document.createElement('div');
            line.className = 'absolute right-0 left-0 border-t-2 border-rose-500 z-20 pointer-events-none flex items-center';
            line.style.top = `${mins}px`;
            line.innerHTML = `<span class="w-2.5 h-2.5 rounded-full bg-rose-500 -mr-1.5"></span>`;
            todayCol.appendChild(line);
        }

        function updateCalendarStatsBar(stats) {
            const studyTotal = stats.study_total !== undefined ? stats.study_total : (stats.total || 0);
            const studyComp  = stats.study_completed !== undefined ? stats.study_completed : (stats.completed || 0);
            const pct        = studyTotal > 0 ? Math.round((studyComp / studyTotal) * 100) : 0;

            document.getElementById('stat-progress-text').textContent = `${pct}% (${studyComp}/${studyTotal})`;
            document.getElementById('stat-planned-hours').textContent = `${stats.planned_hours || 0} ساعت`;
            document.getElementById('stat-completed-hours').textContent = `${stats.completed_hours || 0} ساعت`;
            document.getElementById('stat-misc-hours').textContent = `${stats.misc_completed_hours || 0} از ${stats.misc_planned_hours || 0} ساعت`;
            document.getElementById('stat-avg-percent').textContent = (stats.avg_test_percent !== null && stats.avg_test_percent !== undefined) ? `${stats.avg_test_percent}%` : '---';
        }

        function navigateCalendar(dir) {
            const step = (viewMode === 'week') ? 7 : 1;
            const nextDate = new Date(anchorDate);
            nextDate.setDate(nextDate.getDate() + (dir * step));
            if (dir > 0 && formatGDate(nextDate) > KONKUR_GREGORIAN && formatGDate(anchorDate) >= KONKUR_GREGORIAN) {
                alert('تقویم در تاریخ ۱۶ بهمن (روز کنکور) به پایان می‌رسد.');
                return;
            }
            anchorDate = nextDate;
            renderCalendar();
        }

        function goToToday() {
            anchorDate = new Date(TODAY_GREGORIAN + 'T00:00:00');
            renderCalendar();
        }

        function setViewMode(mode) {
            viewMode = mode;
            renderCalendar();
        }

        function selectDayForDailyView(gDateStr) {
            anchorDate = new Date(gDateStr + 'T00:00:00');
            viewMode = 'day';
            renderCalendar();
        }

        function updateLiveTestPercent() {
            const cVal = document.getElementById('ev-test-correct').value;
            const wVal = document.getElementById('ev-test-wrong').value;
            const bVal = document.getElementById('ev-test-blank').value;
            const badge = document.getElementById('live-test-percent-badge');

            if (cVal === '' && wVal === '' && bVal === '') {
                badge.textContent = '---';
                return;
            }
            const c = Math.max(0, parseInt(cVal) || 0);
            const w = Math.max(0, parseInt(wVal) || 0);
            const b = Math.max(0, parseInt(bVal) || 0);
            const total = c + w + b;
            if (total <= 0) {
                badge.textContent = '---';
                return;
            }
            const pct = (((c * 3) - w) / (total * 3)) * 100;
            badge.textContent = `درصد: ${pct.toFixed(1)}%`;
        }

        function checkLiveOverlapWarning() {
            const warnBox = document.getElementById('overlap-warning-box');
            const gDate   = document.getElementById('ev-date').value;
            const start   = document.getElementById('ev-start').value;
            const end     = document.getElementById('ev-end').value;
            const curId   = Number(document.getElementById('ev-id').value || 0);

            if (!gDate || !start || !end || end <= start) {
                warnBox.classList.add('hidden');
                return;
            }

            const [sh, sm] = start.split(':').map(Number);
            const [eh, em] = end.split(':').map(Number);
            const sMins = (sh * 60) + sm;
            const eMins = (eh * 60) + em;

            const conflicts = loadedEvents.filter(ev => {
                if (ev.g_date !== gDate || Number(ev.id) === curId) return false;
                const [esh, esm] = ev.start_time.split(':').map(Number);
                const [eeh, eem] = ev.end_time.split(':').map(Number);
                const evS = (esh * 60) + esm;
                const evE = (eeh * 60) + eem;
                return (sMins < evE && eMins > evS);
            });

            if (conflicts.length > 0) {
                const names = conflicts.map(c => `«${c.title} (${c.start_time.slice(0,5)}-${c.end_time.slice(0,5)})»`).join(' و ');
                warnBox.textContent = `💡 توجه: این ساعت با برنامه ${names} همپوشانی دارد (در تقویم کنار هم نمایش داده می‌شوند).`;
                warnBox.classList.remove('hidden');
            } else {
                warnBox.classList.add('hidden');
            }
        }

        function openEventModal(gDate = null, startTime = '08:00', endTime = '09:30') {
            document.getElementById('event-form').reset();
            document.getElementById('ev-id').value = '';
            document.getElementById('modal-title').textContent = 'ثبت برنامه جدید در تقویم';
            document.getElementById('repeat-mode-wrapper').classList.remove('hidden');
            document.getElementById('edit-extra-actions').classList.add('hidden');
            document.getElementById('new-category-box').classList.add('hidden');
            document.getElementById('live-test-percent-badge').textContent = '---';

            const targetDate = gDate || formatGDate(anchorDate);
            if (targetDate > KONKUR_GREGORIAN) {
                alert('امکان ثبت برنامه پس از ۱۶ بهمن وجود ندارد.');
                return;
            }
            document.getElementById('ev-date').value = targetDate;
            document.getElementById('ev-start').value = startTime;
            document.getElementById('ev-end').value = endTime;

            // تنظیم وضعیت تیک پیامک بر اساس اشتراک کاربر
            document.getElementById('ev-send-sms').checked = !!userSub.is_vip;
            refreshSubscriptionUI();

            renderCategoryOptionsInModal('#4f46e5');
            checkLiveOverlapWarning();

            document.getElementById('btn-delete-event').classList.add('hidden');
            document.getElementById('btn-toggle-complete').classList.add('hidden');
            document.getElementById('btn-open-linked-pdf').classList.add('hidden');

            const modal = document.getElementById('event-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function openEventModalForEdit(ev) {
            document.getElementById('ev-id').value = ev.id;
            document.getElementById('ev-title').value = ev.title;
            document.getElementById('ev-date').value = ev.g_date;
            document.getElementById('ev-start').value = ev.start_time.slice(0, 5);
            document.getElementById('ev-end').value = ev.end_time.slice(0, 5);
            document.getElementById('ev-desc').value = ev.description || '';
            document.getElementById('ev-pdf-id').value = ev.pdf_id || '';
            document.getElementById('ev-pdf-page').value = ev.pdf_page || 1;
            document.getElementById('ev-send-sms').checked = userSub.is_vip && (Number(ev.send_sms ?? 1) === 1);
            refreshSubscriptionUI();

            document.getElementById('ev-test-correct').value = (ev.test_correct !== null && ev.test_correct !== undefined) ? ev.test_correct : '';
            document.getElementById('ev-test-wrong').value   = (ev.test_wrong !== null && ev.test_wrong !== undefined) ? ev.test_wrong : '';
            document.getElementById('ev-test-blank').value   = (ev.test_blank !== null && ev.test_blank !== undefined) ? ev.test_blank : '';
            updateLiveTestPercent();

            document.getElementById('repeat-mode-wrapper').classList.add('hidden');
            document.getElementById('new-category-box').classList.add('hidden');
            document.getElementById('edit-extra-actions').classList.remove('hidden');

            renderCategoryOptionsInModal(ev.color || '#4f46e5');
            checkLiveOverlapWarning();

            document.getElementById('modal-title').textContent = 'ویرایش برنامه و ثبت عملکرد';
            document.getElementById('btn-delete-event').classList.remove('hidden');
            const btnComplete = document.getElementById('btn-toggle-complete');
            btnComplete.classList.remove('hidden');
            btnComplete.textContent = Number(ev.is_completed) === 1 ? '↩ لغو تیک انجام' : '✓ ثبت انجام کار';

            const btnOpenPdf = document.getElementById('btn-open-linked-pdf');
            if (ev.pdf_id) {
                btnOpenPdf.classList.remove('hidden');
                btnOpenPdf.onclick = () => openPdfFromCalendar(ev.pdf_id, ev.pdf_page);
            } else {
                btnOpenPdf.classList.add('hidden');
            }

            const modal = document.getElementById('event-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeEventModal() {
            const modal = document.getElementById('event-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        async function saveEvent(e) {
            e.preventDefault();
            const formData = new FormData(document.getElementById('event-form'));
            formData.append('action', 'save');
            if (!document.getElementById('ev-send-sms').checked || !userSub.is_vip) {
                formData.set('send_sms', '0');
            }
            const res = await fetch('api_calendar.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.status === 'success') {
                closeEventModal();
                renderCalendar();
            } else {
                alert(data.message || 'خطا در ذخیره برنامه');
            }
        }

        async function moveCurrentEventToTomorrow() {
            const id = document.getElementById('ev-id').value;
            if (!id) return;
            const fd = new FormData();
            fd.append('action', 'move_to_tomorrow');
            fd.append('id', id);

            const res = await fetch('api_calendar.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                closeEventModal();
                renderCalendar();
            } else {
                alert(data.message || 'خطا در انتقال برنامه');
            }
        }

        function startPomodoroForCurrentEvent() {
            const id    = document.getElementById('ev-id').value;
            const title = document.getElementById('ev-title').value;
            if (!id) return;

            closeEventModal();
            const widget = document.getElementById('pomodoro-widget');
            widget.classList.remove('hidden');

            const box = document.getElementById('pomodoro-linked-task-box');
            box.classList.remove('hidden');
            document.getElementById('pomodoro-linked-title').textContent = title;
            document.getElementById('btn-pomodoro-complete-task').onclick = async () => {
                const fd = new FormData();
                fd.append('action', 'toggle_complete');
                fd.append('id', id);
                await fetch('api_calendar.php', { method: 'POST', body: fd });
                pauseTimer();
                box.classList.add('hidden');
                renderCalendar();
            };

            setTimerMode('pomodoro');
            startPauseTimer();
        }

        function openCopyDayModal() {
            const m = document.getElementById('copy-day-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeCopyDayModal() {
            const m = document.getElementById('copy-day-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        async function handleCopyDay(e) {
            e.preventDefault();
            const fd = new FormData();
            fd.append('action', 'copy_day');
            fd.append('source_date', document.getElementById('copy-source-date').value);
            fd.append('target_date', document.getElementById('copy-target-date').value);
            fd.append('only_study', document.getElementById('copy-only-study').checked ? '1' : '0');

            const res = await fetch('api_calendar.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message);
            if (data.status === 'success') {
                closeCopyDayModal();
                renderCalendar();
            }
        }

        async function toggleCurrentEventComplete() {
            const id = document.getElementById('ev-id').value;
            if (!id) return;
            const fd = new FormData();
            fd.append('action', 'toggle_complete');
            fd.append('id', id);

            const tc = document.getElementById('ev-test-correct').value;
            const tw = document.getElementById('ev-test-wrong').value;
            const tb = document.getElementById('ev-test-blank').value;
            if (tc !== '' || tw !== '' || tb !== '') {
                fd.append('test_correct', tc || 0);
                fd.append('test_wrong', tw || 0);
                fd.append('test_blank', tb || 0);
            }

            await fetch('api_calendar.php', { method: 'POST', body: fd });
            closeEventModal();
            renderCalendar();
        }

        async function deleteCurrentEvent() {
            const id = document.getElementById('ev-id').value;
            if (!id || !confirm('آیا از حذف این برنامه مطمئن هستید؟')) return;
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('id', id);
            await fetch('api_calendar.php', { method: 'POST', body: fd });
            closeEventModal();
            renderCalendar();
        }

        // ====================================================
        // مدیریت پوشه‌بندی، محدودیت‌های کاربر عادی و موتور PDFخوان
        // ====================================================
        let pdfFolders = [];
        let pdfList = [];
        let openFolderIds = { 'unassigned': true };
        let activePdfMeta = null;
        let pdfDocInstance = null;
        let activeRenderTask = null;
        let currentPageNum = 1;
        let currentZoomMode = 'fit-width';
        let customScale = 1.0;
        let isRenderingPage = false;
        let pendingPageNum = null;
        let isPdfDarkMode = false;
        let isReaderFullScreen = false;

        async function fetchUserPdfs() {
            try {
                const res = await fetch('api_pdf.php?action=list');
                const data = await res.json();
                if (data.status === 'success') {
                    pdfFolders = data.folders || [];
                    pdfList    = data.pdfs || [];
                    if (data.subscription) {
                        userSub = data.subscription;
                    }
                    pdfFolders.forEach(f => {
                        if (openFolderIds[f.id] === undefined) {
                            openFolderIds[f.id] = true;
                        }
                    });
                    refreshSubscriptionUI();
                    renderFolderAccordionList();
                    syncDropdowns();
                }
            } catch (err) {
                console.error('Error loading PDFs:', err);
            }
        }

        function syncDropdowns() {
            const evSelect = document.getElementById('ev-pdf-id');
            if (evSelect) {
                const cur = evSelect.value;
                let html = '<option value="">-- بدون جزوه --</option>';
                pdfList.forEach(p => {
                    const folderPrefix = p.folder_name ? `[${p.folder_name}] ` : '';
                    const lockPrefix   = p.is_locked ? '🔒 ' : '';
                    html += `<option value="${p.id}">${escapeHtml(lockPrefix + folderPrefix + p.title)}</option>`;
                });
                evSelect.innerHTML = html;
                evSelect.value = cur;
            }

            const folderOptionsHtml = '<option value="">📂 عمومی (بدون پوشه)</option>' +
                pdfFolders.map(f => `<option value="${f.id}">📁 ${escapeHtml(f.name)}</option>`).join('');

            const upSelect = document.getElementById('upload-folder-select');
            if (upSelect) upSelect.innerHTML = folderOptionsHtml;

            const moveSelect = document.getElementById('move-folder-select');
            if (moveSelect) moveSelect.innerHTML = folderOptionsHtml;
        }

        function toggleFolderOpen(folderKey) {
            openFolderIds[folderKey] = !openFolderIds[folderKey];
            renderFolderAccordionList();
        }

        function renderFolderAccordionList() {
            const container = document.getElementById('pdf-list-container');
            if (pdfFolders.length === 0 && pdfList.length === 0) {
                container.innerHTML = `
                    <div class="text-center text-slate-500 text-xs py-10 px-4 space-y-3">
                        <div>هنوز پوشه یا جزوه‌ای نساخته‌اید.</div>
                        <button type="button" onclick="openFolderModal()" class="bg-indigo-50 text-indigo-700 border border-indigo-200 px-3 py-1.5 rounded-lg font-bold">
                            📁+ ساخت اولین پوشه درسی
                        </button>
                    </div>
                `;
                return;
            }

            let html = '';

            pdfFolders.forEach(folder => {
                const items = pdfList.filter(p => Number(p.folder_id) === Number(folder.id));
                const isOpen = !!openFolderIds[folder.id];
                const arrowIcon = isOpen ? '▼' : '◄';

                html += `
                    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-2xs">
                        <div onclick="toggleFolderOpen(${folder.id})" class="px-3 py-2.5 bg-slate-100/90 hover:bg-slate-200/70 flex items-center justify-between cursor-pointer transition select-none">
                            <div class="flex items-center gap-1.5 font-extrabold text-xs text-slate-800 min-w-0">
                                <span class="text-[10px] text-slate-500 w-3">${arrowIcon}</span>
                                <span class="w-3 h-3 rounded-full shrink-0" style="background:${folder.color}"></span>
                                <span class="truncate">📁 ${escapeHtml(folder.name)}</span>
                                <span class="text-[10px] bg-white px-1.5 py-0.5 rounded-full text-slate-600 border">${items.length}</span>
                            </div>
                            <div class="flex items-center gap-1 shrink-0" onclick="event.stopPropagation()">
                                <button type="button" onclick="openUploadModal(${folder.id})" class="text-[10px] bg-indigo-600 hover:bg-indigo-700 text-white px-2 py-0.5 rounded-md font-bold" title="آپلود مستقیم جزوه در این پوشه">+ PDF</button>
                                <button type="button" onclick="deleteFolder(event, ${folder.id})" class="text-slate-400 hover:text-rose-600 text-xs px-1" title="حذف پوشه">🗑️</button>
                            </div>
                        </div>
                        <div class="${isOpen ? 'block' : 'hidden'} p-2 space-y-2 border-t border-slate-200 bg-white">
                            ${items.length > 0
                                ? items.map(renderSinglePdfCard).join('')
                                : `<div class="text-[11px] text-slate-400 text-center py-3">این پوشه خالی است. روی <b>+ PDF</b> بزنید یا جزوات قبلی را با دکمه 📁 به اینجا منتقل کنید.</div>`
                            }
                        </div>
                    </div>
                `;
            });

            const unassigned = pdfList.filter(p => !p.folder_id);
            if (unassigned.length > 0 || pdfFolders.length === 0) {
                const isUnassignedOpen = openFolderIds['unassigned'] !== false;
                const arrowIcon = isUnassignedOpen ? '▼' : '◄';
                html += `
                    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-2xs">
                        <div onclick="toggleFolderOpen('unassigned')" class="px-3 py-2.5 bg-slate-100/90 hover:bg-slate-200/70 flex items-center justify-between cursor-pointer transition select-none">
                            <div class="flex items-center gap-1.5 font-extrabold text-xs text-slate-700">
                                <span class="text-[10px] text-slate-500 w-3">${arrowIcon}</span>
                                <span>📂 عمومی / بدون پوشه</span>
                                <span class="text-[10px] bg-white px-1.5 py-0.5 rounded-full text-slate-600 border">${unassigned.length}</span>
                            </div>
                        </div>
                        <div class="${isUnassignedOpen ? 'block' : 'hidden'} p-2 space-y-2 border-t border-slate-200 bg-white">
                            ${unassigned.map(renderSinglePdfCard).join('')}
                        </div>
                    </div>
                `;
            }

            container.innerHTML = html;
        }

        function renderSinglePdfCard(item) {
            const isActive = activePdfMeta && Number(activePdfMeta.id) === Number(item.id);
            const total = Math.max(1, Number(item.total_pages));
            const last  = Math.min(total, Math.max(1, Number(item.last_page)));
            const pct   = Math.round((last / total) * 100);
            const starCount = Array.isArray(item.starred_pages) ? item.starred_pages.length : 0;
            const isLocked  = !!item.is_locked;

            return `
                <div onclick="loadAndOpenPdf(${item.id}, ${last})" class="p-2.5 rounded-xl border transition cursor-pointer ${isLocked ? 'border-amber-300 bg-amber-50/40 opacity-80' : (isActive ? 'border-indigo-600 bg-indigo-50/80 ring-1 ring-indigo-500' : 'border-slate-200 hover:border-indigo-300 bg-slate-50/40')}">
                    <div class="flex items-start justify-between gap-1">
                        <div class="min-w-0">
                            <h4 class="text-xs font-extrabold text-slate-800 leading-snug truncate">${isLocked ? '🔒 ' : ''}${escapeHtml(item.title)}</h4>
                            ${isLocked ? `<span class="inline-block mt-1 text-[10px] bg-amber-200/80 text-amber-950 px-1.5 py-0.5 rounded font-bold">نیاز به تمدید اشتراک ویژه</span>` : ''}
                        </div>
                        <div class="flex items-center gap-1 shrink-0" onclick="event.stopPropagation()">
                            ${starCount > 0 ? `<span class="text-[10px] bg-amber-100 text-amber-800 px-1.5 py-0.5 rounded font-bold">⭐${starCount}</span>` : ''}
                            <button type="button" onclick="openMovePdfModal(event, ${item.id}, '${item.folder_id || ''}')" class="text-slate-500 hover:text-indigo-600 text-xs px-1" title="انتقال به پوشه دیگر">📁</button>
                            <button type="button" onclick="deletePdfItem(event, ${item.id})" class="text-slate-400 hover:text-rose-600 text-xs px-1" title="حذف جزوه">🗑️</button>
                        </div>
                    </div>
                    <div class="mt-2">
                        <div class="flex justify-between text-[10px] text-slate-500 mb-1">
                            <span>مطالعه تا ص ${last} از ${total}</span>
                            <span class="font-bold text-indigo-600">${pct}%</span>
                        </div>
                        <div class="w-full h-1.5 bg-slate-200 rounded-full overflow-hidden">
                            <div class="h-full bg-indigo-600 rounded-full" style="width:${pct}%"></div>
                        </div>
                    </div>
                </div>
            `;
        }

        function openFolderModal() {
            document.getElementById('folder-form').reset();
            const m = document.getElementById('folder-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
            setTimeout(() => document.getElementById('folder-name-input').focus(), 50);
        }

        function closeFolderModal() {
            const m = document.getElementById('folder-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        async function handleCreateFolder(e) {
            e.preventDefault();
            const btn = document.getElementById('btn-submit-folder');
            btn.disabled = true;
            btn.textContent = 'در حال ساخت...';

            try {
                const fd = new FormData(document.getElementById('folder-form'));
                fd.append('action', 'create_folder');
                const res = await fetch('api_pdf.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    if (data.folder && data.folder.id) {
                        openFolderIds[data.folder.id] = true;
                    }
                    closeFolderModal();
                    await fetchUserPdfs();
                } else {
                    alert(data.message || 'خطا در ساخت پوشه');
                }
            } catch (err) {
                alert('خطا در ارتباط با سرور هنگام ساخت پوشه.');
            } finally {
                btn.disabled = false;
                btn.textContent = 'ساخت پوشه';
            }
        }

        async function deleteFolder(e, folderId) {
            if (e) e.stopPropagation();
            if (!confirm('آیا این پوشه حذف شود؟ (جزوات داخل آن پاک نمی‌شوند و به بخش عمومی منتقل می‌گردند)')) return;
            const fd = new FormData();
            fd.append('action', 'delete_folder');
            fd.append('folder_id', folderId);
            await fetch('api_pdf.php', { method: 'POST', body: fd });
            fetchUserPdfs();
        }

        function openMovePdfModal(e, pdfId, currentFolderId) {
            if (e) e.stopPropagation();
            document.getElementById('move-pdf-id').value = pdfId;
            document.getElementById('move-folder-select').value = currentFolderId || '';
            const m = document.getElementById('move-pdf-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeMovePdfModal() {
            const m = document.getElementById('move-pdf-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        async function handleMovePdf(e) {
            e.preventDefault();
            const pdfId = document.getElementById('move-pdf-id').value;
            const folderId = document.getElementById('move-folder-select').value;

            const fd = new FormData();
            fd.append('action', 'move_pdf');
            fd.append('pdf_id', pdfId);
            fd.append('folder_id', folderId);

            const res = await fetch('api_pdf.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                if (folderId) openFolderIds[folderId] = true;
                closeMovePdfModal();
                fetchUserPdfs();
            } else {
                alert(data.message || 'خطا در انتقال جزوه');
            }
        }

        // بررسی سهمیه کاربر عادی هنگام کلیک روی دکمه آپلود PDF
        function openUploadModal(defaultFolderId = '') {
            if (!userSub.is_vip && pdfList.length >= FREE_MAX_PDF_CNT) {
                openSubscriptionModal(`شما کاربر عادی هستید و سهمیه ${FREE_MAX_PDF_CNT} فایل رایگان شما پر شده است. برای آپلود فایل جدید، لطفاً فایل قبلی را حذف کنید یا اشتراک ویژه تهیه نمایید.`);
                return;
            }

            document.getElementById('upload-pdf-form').reset();
            syncDropdowns();
            if (defaultFolderId !== undefined && defaultFolderId !== null) {
                document.getElementById('upload-folder-select').value = String(defaultFolderId);
            }

            const hintEl = document.getElementById('upload-modal-tier-hint');
            if (userSub.is_vip) {
                hintEl.className = 'p-2.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-900 text-[11px] font-bold';
                hintEl.textContent = `👑 اشتراک ویژه فعال: امکان آپلود نامحدود جزوه تا سقف ${VIP_MAX_PDF_MB} مگابایت برای هر فایل.`;
            } else {
                hintEl.className = 'p-2.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-[11px] font-bold';
                hintEl.textContent = `👤 حساب عادی: حداکثر ${FREE_MAX_PDF_CNT} فایل PDF با حداکثر حجم ${FREE_MAX_PDF_MB} مگابایت مجاز است.`;
            }

            const m = document.getElementById('upload-pdf-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeUploadModal() {
            const m = document.getElementById('upload-pdf-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        async function handlePdfUpload(e) {
            e.preventDefault();
            const fileInput = document.getElementById('upload-pdf-file-input');
            const file = fileInput.files[0];
            if (!file) return;

            // بررسی آنی حجم فایل در مرورگر قبل از ارسال به سرور
            const maxAllowedMB = userSub.is_vip ? VIP_MAX_PDF_MB : FREE_MAX_PDF_MB;
            const fileSizeMB   = +(file.size / (1024 * 1024)).toFixed(1);

            if (file.size > maxAllowedMB * 1024 * 1024) {
                if (!userSub.is_vip) {
                    closeUploadModal();
                    openSubscriptionModal(`حجم فایل انتخابی شما (${fileSizeMB} مگابایت) بیشتر از سقف مجاز کاربران عادی (${FREE_MAX_PDF_MB} مگابایت) است. با تهیه اشتراک ویژه، فایل‌های تا ${VIP_MAX_PDF_MB} مگابایت را بدون محدودیت آپلود کنید.`);
                } else {
                    alert(`حجم فایل (${fileSizeMB} مگابایت) بیش از سقف مجاز سرور (${VIP_MAX_PDF_MB} مگابایت) است.`);
                }
                return;
            }

            const btn = document.getElementById('btn-submit-upload');
            btn.disabled = true;
            btn.textContent = 'در حال آپلود...';

            try {
                const fd = new FormData(document.getElementById('upload-pdf-form'));
                fd.append('action', 'upload');
                const res = await fetch('api_pdf.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.status === 'success') {
                    if (data.pdf.folder_id) {
                        openFolderIds[data.pdf.folder_id] = true;
                    }
                    closeUploadModal();
                    await fetchUserPdfs();
                    loadAndOpenPdf(data.pdf.id, 1);
                } else if (data.need_vip) {
                    closeUploadModal();
                    openSubscriptionModal(data.message);
                } else {
                    alert(data.message || 'خطا در آپلود');
                }
            } catch (err) {
                alert('خطا در آپلود فایل PDF.');
            } finally {
                btn.disabled = false;
                btn.textContent = 'آپلود و ذخیره';
            }
        }

        async function deletePdfItem(e, pdfId) {
            if (e) e.stopPropagation();
            if (!confirm('آیا از حذف کامل این جزوه مطمئن هستید؟')) return;
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('pdf_id', pdfId);
            await fetch('api_pdf.php', { method: 'POST', body: fd });
            if (activePdfMeta && Number(activePdfMeta.id) === Number(pdfId)) {
                location.reload();
            } else {
                fetchUserPdfs();
            }
        }

        // ====================================================
        // بارگذاری باینری و بدون خطای PDF (همراه با بررسی قفل اشتراک)
        // ====================================================
        async function loadAndOpenPdf(pdfId, targetPage = null) {
            if (pdfList.length === 0) await fetchUserPdfs();
            const targetItem = pdfList.find(p => Number(p.id) === Number(pdfId));
            if (!targetItem) return;

            if (targetItem.is_locked) {
                openSubscriptionModal(`جزوه «${targetItem.title}» به دلیل اتمام مهلت اشتراک ویژه قفل شده است. برای دسترسی مجدد به تمام جزوات و هایلایت‌های خود، اشتراک را تمدید نمایید (یا جزوات دیگر را حذف کنید تا فقط ۱ جزوه باقی بماند).`);
                return;
            }

            activePdfMeta = targetItem;
            if (activePdfMeta.folder_id) {
                openFolderIds[activePdfMeta.folder_id] = true;
            }
            closePdfSidebarMobile();

            document.getElementById('active-pdf-title').textContent = activePdfMeta.title;
            document.getElementById('pdf-empty-state').classList.add('hidden');
            document.getElementById('pdf-canvas-wrapper').classList.add('hidden');
            document.getElementById('pdf-loading-overlay').classList.remove('hidden');

            try {
                const pdfjs = await ensurePdfJsReady();

                const rawResponse = await fetch(`api_pdf.php?action=raw&pdf_id=${activePdfMeta.id}&t=${Date.now()}`, {
                    cache: 'no-store',
                    credentials: 'same-origin'
                });

                if (rawResponse.status === 403) {
                    document.getElementById('pdf-loading-overlay').classList.add('hidden');
                    document.getElementById('pdf-empty-state').classList.remove('hidden');
                    openSubscriptionModal('این جزوه نیازمند تمدید اشتراک ویژه است.');
                    return;
                }

                if (!rawResponse.ok) {
                    throw new Error(`خطای سرور در دریافت فایل (کد ${rawResponse.status})`);
                }

                const arrayBuffer = await rawResponse.arrayBuffer();
                const pdfBytes = new Uint8Array(arrayBuffer);

                if (pdfDocInstance && typeof pdfDocInstance.destroy === 'function') {
                    await pdfDocInstance.destroy();
                }

                const loadingTask = pdfjs.getDocument({
                    data: pdfBytes,
                    cMapUrl: activeCdnConfig.cmaps,
                    cMapPacked: true,
                    standardFontDataUrl: activeCdnConfig.fonts,
                    isEvalSupported: true,
                    disableFontFace: false,
                    useSystemFonts: true,
                    disableAutoFetch: true,
                    disableStream: true,
                    disableRange: true
                });

                pdfDocInstance = await loadingTask.promise;

                const totalPages = pdfDocInstance.numPages;
                activePdfMeta.total_pages = totalPages;
                document.getElementById('pdf-total-pages').textContent = totalPages;
                document.getElementById('pdf-page-input').max = totalPages;

                currentPageNum = targetPage
                    ? Math.min(totalPages, Math.max(1, Number(targetPage)))
                    : Math.min(totalPages, Math.max(1, Number(activePdfMeta.last_page)));

                document.getElementById('pdf-loading-overlay').classList.add('hidden');
                document.getElementById('pdf-canvas-wrapper').classList.remove('hidden');
                document.getElementById('pdf-controls-group').classList.remove('hidden');
                document.getElementById('pdf-controls-group').classList.add('flex');
                document.getElementById('pdf-fab-btn').classList.remove('hidden');
                document.getElementById('pdf-fab-btn').classList.add('flex');
                document.getElementById('pdf-floating-bottom-bar').classList.remove('hidden');
                document.getElementById('pdf-floating-bottom-bar').classList.add('flex');

                renderFolderAccordionList();
                await renderPdfPage(currentPageNum);
            } catch (err) {
                document.getElementById('pdf-loading-overlay').classList.add('hidden');
                document.getElementById('pdf-empty-state').classList.remove('hidden');
                alert('خطا در باز کردن فایل PDF: ' + err.message);
            }
        }

        async function renderPdfPage(num) {
            if (!pdfDocInstance) return;

            if (isRenderingPage) {
                pendingPageNum = num;
                if (activeRenderTask && typeof activeRenderTask.cancel === 'function') {
                    try { activeRenderTask.cancel(); } catch (e) {}
                }
                return;
            }
            isRenderingPage = true;

            try {
                const page = await pdfDocInstance.getPage(num);
                const container = document.getElementById('pdf-viewport-container');
                const wrapper = document.getElementById('pdf-canvas-wrapper');
                const canvas = document.getElementById('pdf-render-canvas');
                const annotCanvas = document.getElementById('pdf-annot-canvas');
                const ctx = canvas.getContext('2d');

                wrapper.classList.remove('page-flip-anim');
                void wrapper.offsetWidth;
                wrapper.classList.add('page-flip-anim');

                const unscaledViewport = page.getViewport({ scale: 1 });
                let scale = customScale;
                if (currentZoomMode === 'fit-width') {
                    const pad = window.innerWidth < 640 ? 6 : 24;
                    const availableWidth = Math.max(280, container.clientWidth - pad);
                    scale = Math.min(availableWidth / unscaledViewport.width, 1.85);
                    customScale = scale;
                }

                const viewport = page.getViewport({ scale: scale });

                const maxSafeDpr = window.innerWidth < 768 ? 1.75 : 2.0;
                let outputScale = Math.min(window.devicePixelRatio || 1, maxSafeDpr);

                const maxCanvasDim = 3800;
                if (viewport.width * outputScale > maxCanvasDim || viewport.height * outputScale > maxCanvasDim) {
                    outputScale = Math.min(maxCanvasDim / viewport.width, maxCanvasDim / viewport.height);
                }

                canvas.width  = Math.floor(viewport.width * outputScale);
                canvas.height = Math.floor(viewport.height * outputScale);
                canvas.style.width  = Math.floor(viewport.width) + "px";
                canvas.style.height = Math.floor(viewport.height) + "px";

                annotCanvas.width  = Math.floor(viewport.width);
                annotCanvas.height = Math.floor(viewport.height);
                annotCanvas.style.width  = Math.floor(viewport.width) + "px";
                annotCanvas.style.height = Math.floor(viewport.height) + "px";

                ctx.save();
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.restore();

                ctx.direction = 'ltr';
                ctx.textAlign = 'left';

                const transform = outputScale !== 1 ? [outputScale, 0, 0, outputScale, 0, 0] : null;
                activeRenderTask = page.render({ canvasContext: ctx, transform, viewport });
                await activeRenderTask.promise;
                activeRenderTask = null;

                document.getElementById('pdf-page-input').value = num;
                document.getElementById('floating-page-pill').textContent = `ص ${num} از ${pdfDocInstance.numPages}`;

                updateStarButtonUI();
                loadPageAnnotations(activePdfMeta.id, num);
                saveReadingProgress(activePdfMeta.id, num, pdfDocInstance.numPages);
            } catch (err) {
                if (err && err.name !== 'RenderingCancelledException') {
                    console.error('Page render error:', err);
                }
            } finally {
                isRenderingPage = false;
                if (pendingPageNum !== null) {
                    const next = pendingPageNum;
                    pendingPageNum = null;
                    renderPdfPage(next);
                }
            }
        }

        function updateStarButtonUI() {
            if (!activePdfMeta) return;
            const stars = Array.isArray(activePdfMeta.starred_pages) ? activePdfMeta.starred_pages : [];
            const isStarred = stars.includes(Number(currentPageNum));
            const btn = document.getElementById('btn-star-page');
            btn.textContent = isStarred ? '⭐' : '☆';
            btn.className = isStarred
                ? 'px-2 py-1 bg-amber-500/20 text-amber-300 border border-amber-400/40 rounded-lg text-xs font-bold transition'
                : 'px-2 py-1 bg-slate-800 hover:bg-slate-700 rounded-lg text-xs font-bold text-slate-400 transition';
            document.getElementById('starred-count-badge').textContent = stars.length;
        }

        async function toggleStarCurrentPage() {
            if (!activePdfMeta) return;
            const fd = new FormData();
            fd.append('action', 'toggle_star_page');
            fd.append('pdf_id', activePdfMeta.id);
            fd.append('page_number', currentPageNum);

            const res = await fetch('api_pdf.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                activePdfMeta.starred_pages = data.starred_pages;
                updateStarButtonUI();
                renderFolderAccordionList();
            }
        }

        function openStarredModal() {
            if (!activePdfMeta) return;
            const stars = Array.isArray(activePdfMeta.starred_pages) ? activePdfMeta.starred_pages : [];
            const listEl = document.getElementById('starred-pages-list');
            if (stars.length === 0) {
                listEl.innerHTML = `<div class="text-center text-slate-400 py-6">هنوز صفحه‌ای از این جزوه را ستاره‌دار (☆) نکرده‌اید.</div>`;
            } else {
                listEl.innerHTML = stars.map(p => `
                    <div onclick="jumpToInputPage(${p}); closeStarredModal();" class="p-2.5 bg-amber-50 hover:bg-amber-100 border border-amber-200 rounded-xl flex items-center justify-between cursor-pointer transition">
                        <span class="font-extrabold text-amber-900">⭐ صفحه ${p} جزوه</span>
                        <span class="text-indigo-600 font-bold text-[11px]">پرش به صفحه ❮</span>
                    </div>
                `).join('');
            }
            const m = document.getElementById('starred-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeStarredModal() {
            const m = document.getElementById('starred-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        let touchSwipeStartX = 0;
        let touchSwipeStartY = 0;
        const pdfViewportEl = document.getElementById('pdf-viewport-container');

        pdfViewportEl.addEventListener('touchstart', (e) => {
            if (currentTool !== 'pan' || e.touches.length !== 1) return;
            touchSwipeStartX = e.touches[0].clientX;
            touchSwipeStartY = e.touches[0].clientY;
        }, { passive: true });

        pdfViewportEl.addEventListener('touchend', (e) => {
            if (currentTool !== 'pan' || !pdfDocInstance || e.changedTouches.length !== 1) return;
            if (pdfViewportEl.scrollWidth > pdfViewportEl.clientWidth + 15) return;

            const dx = e.changedTouches[0].clientX - touchSwipeStartX;
            const dy = e.changedTouches[0].clientY - touchSwipeStartY;

            if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) {
                if (dx > 0) {
                    changePdfPage(1);
                } else {
                    changePdfPage(-1);
                }
            }
        }, { passive: true });

        function changePdfPage(delta) {
            if (!pdfDocInstance) return;
            const next = currentPageNum + delta;
            if (next >= 1 && next <= pdfDocInstance.numPages) {
                currentPageNum = next;
                renderPdfPage(currentPageNum);
                pdfViewportEl.scrollTop = 0;
            }
        }

        function jumpToInputPage(val) {
            if (!pdfDocInstance) return;
            currentPageNum = Math.min(pdfDocInstance.numPages, Math.max(1, parseInt(val) || 1));
            renderPdfPage(currentPageNum);
            pdfViewportEl.scrollTop = 0;
        }

        function adjustZoom(delta) {
            if (!pdfDocInstance) return;
            currentZoomMode = 'custom';
            customScale = Math.min(3.0, Math.max(0.5, customScale + delta));
            renderPdfPage(currentPageNum);
        }

        function setFitWidthZoom() {
            if (!pdfDocInstance) return;
            currentZoomMode = 'fit-width';
            renderPdfPage(currentPageNum);
        }

        function togglePdfDarkMode() {
            isPdfDarkMode = !isPdfDarkMode;
            const canvas = document.getElementById('pdf-render-canvas');
            const btn = document.getElementById('btn-pdf-dark');
            if (isPdfDarkMode) {
                canvas.classList.add('pdf-dark-mode');
                btn.textContent = '☀️ روز';
            } else {
                canvas.classList.remove('pdf-dark-mode');
                btn.textContent = '🌙 شب';
            }
        }

        function toggleFullScreenReader() {
            isReaderFullScreen = !isReaderFullScreen;
            const header = document.getElementById('main-app-header');
            const btn = document.getElementById('btn-fullscreen-reader');
            if (isReaderFullScreen) {
                header.classList.add('hidden');
                btn.textContent = '✕ خروج تمام‌صفحه';
                btn.className = 'px-2 py-1 bg-rose-600 text-white rounded-lg text-xs font-bold shrink-0';
            } else {
                header.classList.remove('hidden');
                btn.textContent = '⛶ تمام‌صفحه';
                btn.className = 'px-2 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg text-xs font-bold shrink-0';
            }
            if (pdfDocInstance && currentZoomMode === 'fit-width') {
                setTimeout(() => renderPdfPage(currentPageNum), 50);
            }
        }

        function togglePdfSidebar() {
            const sb = document.getElementById('pdf-sidebar');
            const bd = document.getElementById('pdf-sidebar-backdrop');
            if (window.innerWidth < 768) {
                const isClosed = sb.classList.contains('translate-x-full');
                if (isClosed) {
                    sb.classList.remove('translate-x-full');
                    bd.classList.remove('hidden');
                } else {
                    closePdfSidebarMobile();
                }
            } else {
                sb.classList.toggle('hidden');
                if (pdfDocInstance && currentZoomMode === 'fit-width') {
                    setTimeout(() => renderPdfPage(currentPageNum), 50);
                }
            }
        }

        function closePdfSidebarMobile() {
            document.getElementById('pdf-sidebar').classList.add('translate-x-full');
            document.getElementById('pdf-sidebar-backdrop').classList.add('hidden');
        }

        function toggleToolsPopup() {
            document.getElementById('pdf-tools-popup').classList.toggle('hidden');
        }

        async function saveReadingProgress(pdfId, lastPage, totalPages) {
            if (activePdfMeta) {
                activePdfMeta.last_page = lastPage;
                activePdfMeta.total_pages = totalPages;
                renderFolderAccordionList();
            }
            const fd = new FormData();
            fd.append('action', 'save_progress');
            fd.append('pdf_id', pdfId);
            fd.append('last_page', lastPage);
            fd.append('total_pages', totalPages);
            await fetch('api_pdf.php', { method: 'POST', body: fd });
        }

        async function openPdfFromCalendar(pdfId, pageNumber) {
            closeEventModal();
            switchMainSection('pdf');
            await fetchUserPdfs();
            loadAndOpenPdf(pdfId, pageNumber);
        }

        function switchMainSection(sec) {
            const calSec = document.getElementById('section-calendar');
            const pdfSec = document.getElementById('section-pdf');
            const navCal = document.getElementById('nav-calendar');
            const navPdf = document.getElementById('nav-pdf');

            if (sec === 'calendar') {
                calSec.classList.remove('hidden');
                pdfSec.classList.add('hidden');
                navCal.className = 'px-2.5 md:px-3.5 py-1.5 rounded-lg text-xs font-bold bg-indigo-600 text-white transition';
                navPdf.className = 'px-2.5 md:px-3.5 py-1.5 rounded-lg text-xs font-bold text-slate-300 hover:text-white transition';
            } else {
                calSec.classList.add('hidden');
                pdfSec.classList.remove('hidden');
                navPdf.className = 'px-2.5 md:px-3.5 py-1.5 rounded-lg text-xs font-bold bg-indigo-600 text-white transition';
                navCal.className = 'px-2.5 md:px-3.5 py-1.5 rounded-lg text-xs font-bold text-slate-300 hover:text-white transition';
                if (pdfDocInstance && currentZoomMode === 'fit-width') {
                    setTimeout(() => renderPdfPage(currentPageNum), 50);
                }
            }
        }

        // ====================================================
        // موتور علامت‌گذاری روی PDF
        // ====================================================
        let currentTool = 'pan';
        let currentColor = '#facc15';
        let pageAnnotations = [];
        let isDrawingAnnot = false;
        let currentStroke = null;
        let saveAnnotTimeout = null;

        const annotCanvas = document.getElementById('pdf-annot-canvas');
        const annotCtx = annotCanvas.getContext('2d');
        const TOOL_ICONS = { pan: '✋', highlight: '🖍️', pen: '✏️', note: '📌', eraser: '🧽' };

        function setAnnotTool(tool, autoCloseOnMobile = false) {
            currentTool = tool;
            document.getElementById('fab-active-icon').textContent = TOOL_ICONS[tool] || '✋';

            document.querySelectorAll('.annot-tool-btn').forEach(btn => {
                btn.classList.remove('bg-indigo-600', 'text-white');
                btn.classList.add('bg-slate-800', 'text-slate-200');
            });
            const activeBtn = document.getElementById('tool-' + tool);
            if (activeBtn) {
                activeBtn.classList.remove('bg-slate-800', 'text-slate-200');
                activeBtn.classList.add('bg-indigo-600', 'text-white');
            }

            if (tool === 'pan') {
                annotCanvas.classList.add('pointer-events-none');
                annotCanvas.style.touchAction = 'auto';
            } else {
                annotCanvas.classList.remove('pointer-events-none');
                annotCanvas.style.touchAction = 'none';
            }

            if (autoCloseOnMobile && window.innerWidth < 768) {
                document.getElementById('pdf-tools-popup').classList.add('hidden');
            }
        }

        function setAnnotColor(color) {
            currentColor = color;
            document.querySelectorAll('.annot-color-btn').forEach(btn => {
                btn.className = (btn.dataset.color === color)
                    ? 'annot-color-btn w-6 h-6 rounded-full ring-2 ring-white scale-110 transition'
                    : 'annot-color-btn w-6 h-6 rounded-full opacity-60 hover:opacity-100 transition';
                btn.style.backgroundColor = btn.dataset.color;
            });
        }

        function getNormalizedPointerPos(e) {
            const rect = annotCanvas.getBoundingClientRect();
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            const clientY = e.touches ? e.touches[0].clientY : e.clientY;
            return {
                x: Math.min(1, Math.max(0, (clientX - rect.left) / rect.width)),
                y: Math.min(1, Math.max(0, (clientY - rect.top) / rect.height))
            };
        }

        async function loadPageAnnotations(pdfId, pageNum) {
            try {
                const res = await fetch(`api_pdf.php?action=get_annotations&pdf_id=${pdfId}&page_number=${pageNum}`);
                const json = await res.json();
                pageAnnotations = (json.status === 'success' && Array.isArray(json.data)) ? json.data : [];
                redrawAllAnnotations();
            } catch (e) {
                pageAnnotations = [];
            }
        }

        function redrawAllAnnotations() {
            const w = annotCanvas.width;
            const h = annotCanvas.height;
            annotCtx.clearRect(0, 0, w, h);
            pageAnnotations.forEach(item => drawSingleAnnotation(item, w, h));
            if (currentStroke) drawSingleAnnotation(currentStroke, w, h);
        }

        function drawSingleAnnotation(item, w, h) {
            annotCtx.save();
            annotCtx.direction = 'ltr';
            if (item.type === 'highlight' || item.type === 'pen') {
                if (!item.points || item.points.length === 0) { annotCtx.restore(); return; }
                annotCtx.beginPath();
                annotCtx.lineCap = 'round';
                annotCtx.lineJoin = 'round';
                annotCtx.strokeStyle = item.color || '#facc15';
                annotCtx.globalAlpha = (item.type === 'highlight') ? 0.38 : 0.95;
                annotCtx.lineWidth = (item.type === 'highlight') ? Math.max(14, w * 0.024) : Math.max(2, w * 0.0032);

                annotCtx.moveTo(item.points[0].x * w, item.points[0].y * h);
                for (let i = 1; i < item.points.length; i++) {
                    annotCtx.lineTo(item.points[i].x * w, item.points[i].y * h);
                }
                annotCtx.stroke();
            } else if (item.type === 'note') {
                const nx = item.x * w;
                const ny = item.y * h;
                const boxSize = Math.max(22, w * 0.032);
                annotCtx.fillStyle = item.color || '#facc15';
                annotCtx.strokeStyle = '#1e293b';
                annotCtx.lineWidth = 1.5;
                annotCtx.fillRect(nx - boxSize / 2, ny - boxSize / 2, boxSize, boxSize);
                annotCtx.strokeRect(nx - boxSize / 2, ny - boxSize / 2, boxSize, boxSize);
                annotCtx.font = `bold ${Math.round(boxSize * 0.55)}px sans-serif`;
                annotCtx.textAlign = 'center';
                annotCtx.textBaseline = 'middle';
                annotCtx.fillText('📌', nx, ny);
            }
            annotCtx.restore();
        }

        function handlePointerStart(e) {
            if (currentTool === 'pan' || !activePdfMeta) return;
            if (e.cancelable) e.preventDefault();
            const pos = getNormalizedPointerPos(e);

            if (currentTool === 'note') {
                const existingIdx = findAnnotationAtPos(pos.x, pos.y, 'note');
                if (existingIdx !== -1) {
                    const updated = prompt('متن یادداشت (برای حذف، خالی بگذارید):', pageAnnotations[existingIdx].text);
                    if (updated === null) return;
                    if (updated.trim() === '') pageAnnotations.splice(existingIdx, 1);
                    else pageAnnotations[existingIdx].text = updated.trim();
                    redrawAllAnnotations();
                    triggerAutoSaveAnnotations();
                    return;
                }
                const text = prompt('متن نکته یا یادداشت خود را وارد کنید:');
                if (text && text.trim() !== '') {
                    pageAnnotations.push({ type: 'note', color: currentColor, x: pos.x, y: pos.y, text: text.trim() });
                    redrawAllAnnotations();
                    triggerAutoSaveAnnotations();
                }
                return;
            }

            if (currentTool === 'eraser') {
                isDrawingAnnot = true;
                eraseAnnotationNear(pos.x, pos.y);
                return;
            }

            if (currentTool === 'highlight' || currentTool === 'pen') {
                isDrawingAnnot = true;
                currentStroke = { type: currentTool, color: currentColor, points: [pos] };
                redrawAllAnnotations();
            }
        }

        function handlePointerMove(e) {
            if (!isDrawingAnnot || currentTool === 'pan') return;
            if (e.cancelable) e.preventDefault();
            const pos = getNormalizedPointerPos(e);
            if (currentTool === 'eraser') { eraseAnnotationNear(pos.x, pos.y); return; }
            if (currentStroke) { currentStroke.points.push(pos); redrawAllAnnotations(); }
        }

        function handlePointerEnd() {
            if (!isDrawingAnnot) return;
            isDrawingAnnot = false;
            if (currentStroke && currentStroke.points.length > 0) {
                pageAnnotations.push(currentStroke);
                currentStroke = null;
                redrawAllAnnotations();
                triggerAutoSaveAnnotations();
            }
        }

        function findAnnotationAtPos(x, y, filterType = null) {
            const threshold = 0.035;
            for (let i = pageAnnotations.length - 1; i >= 0; i--) {
                const item = pageAnnotations[i];
                if (filterType && item.type !== filterType) continue;
                if (item.type === 'note' && Math.hypot(item.x - x, item.y - y) <= threshold) return i;
                if (item.points && item.points.some(pt => Math.hypot(pt.x - x, pt.y - y) <= threshold)) return i;
            }
            return -1;
        }

        function eraseAnnotationNear(x, y) {
            const idx = findAnnotationAtPos(x, y);
            if (idx !== -1) {
                pageAnnotations.splice(idx, 1);
                redrawAllAnnotations();
                triggerAutoSaveAnnotations();
            }
        }

        function undoLastAnnotation() {
            if (pageAnnotations.length === 0) return;
            pageAnnotations.pop();
            redrawAllAnnotations();
            triggerAutoSaveAnnotations();
        }

        function clearAllPageAnnotations() {
            if (pageAnnotations.length === 0 || !confirm('تمام علامت‌های این صفحه پاک شوند؟')) return;
            pageAnnotations = [];
            redrawAllAnnotations();
            triggerAutoSaveAnnotations();
        }

        function triggerAutoSaveAnnotations() {
            if (!activePdfMeta) return;
            const statusEl = document.getElementById('annot-save-status');
            statusEl.textContent = '⏳ ذخیره...';
            if (saveAnnotTimeout) clearTimeout(saveAnnotTimeout);
            const pdfId = activePdfMeta.id;
            const page  = currentPageNum;
            const data  = JSON.stringify(pageAnnotations);

            saveAnnotTimeout = setTimeout(async () => {
                const fd = new FormData();
                fd.append('action', 'save_annotations');
                fd.append('pdf_id', pdfId);
                fd.append('page_number', page);
                fd.append('annotation_data', data);
                const res = await fetch('api_pdf.php', { method: 'POST', body: fd });
                const json = await res.json();
                statusEl.textContent = json.status === 'success' ? '✓ ذخیره شد' : '❌ خطا';
            }, 350);
        }

        annotCanvas.addEventListener('mousedown', handlePointerStart);
        annotCanvas.addEventListener('mousemove', handlePointerMove);
        window.addEventListener('mouseup', handlePointerEnd);
        annotCanvas.addEventListener('touchstart', handlePointerStart, { passive: false });
        annotCanvas.addEventListener('touchmove', handlePointerMove, { passive: false });
        window.addEventListener('touchend', handlePointerEnd);

        // ====================================================
        // تایمر پومودورو و کرنومتر مطالعه
        // ====================================================
        let timerMode = 'pomodoro';
        let timerSeconds = 25 * 60;
        let timerInterval = null;
        let isTimerRunning = false;

        function togglePomodoroWidget() {
            document.getElementById('pomodoro-widget').classList.toggle('hidden');
        }

        function setTimerMode(mode) {
            pauseTimer();
            timerMode = mode;
            ['pomodoro', 'break', 'stopwatch'].forEach(m => {
                document.getElementById('tmode-' + m).className = (m === mode)
                    ? 'flex-1 py-1 rounded-md bg-indigo-600 text-white'
                    : 'flex-1 py-1 rounded-md text-slate-400';
            });
            timerSeconds = (mode === 'pomodoro') ? 25 * 60 : (mode === 'break' ? 5 * 60 : 0);
            updateTimerDisplay();
        }

        function updateTimerDisplay() {
            const m = String(Math.floor(timerSeconds / 60)).padStart(2, '0');
            const s = String(timerSeconds % 60).padStart(2, '0');
            const formatted = `${m}:${s}`;
            document.getElementById('timer-display').textContent = formatted;
            document.getElementById('header-timer-badge').textContent = isTimerRunning ? formatted : 'تایمر';
        }

        function startPauseTimer() {
            if (isTimerRunning) {
                pauseTimer();
            } else {
                isTimerRunning = true;
                document.getElementById('btn-timer-toggle').textContent = 'توقف';
                document.getElementById('btn-timer-toggle').className = 'flex-1 py-2 bg-amber-600 hover:bg-amber-500 text-white font-extrabold rounded-xl transition';
                timerInterval = setInterval(() => {
                    if (timerMode === 'stopwatch') {
                        timerSeconds++;
                    } else {
                        if (timerSeconds > 0) {
                            timerSeconds--;
                        } else {
                            pauseTimer();
                            playBeepSound();
                            alert('⏱️ زمان تایمر به پایان رسید! خسته نباشی.');
                        }
                    }
                    updateTimerDisplay();
                }, 1000);
            }
        }

        function pauseTimer() {
            isTimerRunning = false;
            if (timerInterval) clearInterval(timerInterval);
            document.getElementById('btn-timer-toggle').textContent = 'شروع';
            document.getElementById('btn-timer-toggle').className = 'flex-1 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-extrabold rounded-xl transition';
            updateTimerDisplay();
        }

        function resetTimer() {
            setTimerMode(timerMode);
        }

        function playBeepSound() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(880, ctx.currentTime);
                osc.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.5);
            } catch (e) {}
        }

        // ====================================================
        // مدیریت شماره موبایل و پیامک یادآوری sms.ir
        // ====================================================
        function openPhoneModal() {
            refreshSubscriptionUI();
            const m = document.getElementById('phone-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closePhoneModal() {
            const m = document.getElementById('phone-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        async function handleUpdatePhone(e) {
            e.preventDefault();
            const newPhone = document.getElementById('user-phone-input').value;
            const fd = new FormData();
            fd.append('action', 'update_phone');
            fd.append('phone', newPhone);
            const res = await fetch('api_calendar.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message);
            if (data.status === 'success') {
                userSub.phone = newPhone;
                closePhoneModal();
            }
        }

        async function triggerSmsCheckNow(showAlert = false) {
            try {
                const res = await fetch('cron_sms.php');
                const data = await res.json();
                if (showAlert) {
                    alert(`بررسی انجام شد.\nبرنامه‌های عقب‌افتاده کاربران ویژه: ${data.overdue_found}\nپیامک ارسال‌شده: ${data.sms_sent}`);
                }
            } catch (e) {}
        }

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
        }

        window.addEventListener('resize', () => {
            if (pdfDocInstance && currentZoomMode === 'fit-width' && !document.getElementById('section-pdf').classList.contains('hidden')) {
                renderPdfPage(currentPageNum);
            }
        });

        window.addEventListener('DOMContentLoaded', () => {
            refreshSubscriptionUI();
            initTimeLabels();
            renderCalendar();
            fetchUserPdfs();
            document.getElementById('calendar-scroll-area').scrollTop = 7 * 60;

            triggerSmsCheckNow(false);
            setInterval(() => triggerSmsCheckNow(false), 180000);
        });
    </script>
</body>
</html>