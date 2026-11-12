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

// ارتقای خودکار جداول دیتابیس
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
        $pdo->exec("ALTER TABLE `calendar_events` ADD COLUMN `send_sms` tinyint(1) DEFAULT 0 AFTER `is_completed`");
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
    <title>داشبورد هوشمند مطالعه، تقویم تعاملی و جزوه‌خوان کنکور v4.2</title>
    <!-- Local Host Assets (Offline-First) -->
    <link href="assets/css/tailwind-local.css" rel="stylesheet">
    <link href="assets/css/vazirmatn-local.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
    
    <!-- بارگذاری نسخه 3.11.174 از سرور بدون اختلال jsDelivr به همراه پشتیبان خودکار unpkg -->
    <script src="assets/pdfjs/pdf.min.js" onerror="this.onerror=null;this.src='https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js';"></script>

    <link href="assets/css/index.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/index.css') ?: '4.4'; ?>" rel="stylesheet">
</head>
<body class="bg-slate-100 text-slate-800 h-screen flex flex-col overflow-hidden select-none">

    <!-- ==================================================== -->
    <!-- ۱. هدر اصلی سامانه -->
    <!-- ==================================================== -->
    <header id="main-app-header" class="bg-slate-900 text-white px-3 md:px-4 py-2 flex flex-wrap items-center justify-between gap-2 shadow-md z-30 shrink-0">
        <div class="flex items-center gap-2 md:gap-3">
            <div class="flex items-center gap-1.5">
                <span class="bg-indigo-600 text-white px-2 py-1 rounded-lg font-extrabold text-xs">🎯 کنکور</span>
                <span class="font-bold text-xs md:text-sm truncate max-w-[85px] sm:max-w-none"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
            </div>

            <nav class="flex bg-slate-800 p-0.5 rounded-xl border border-slate-700">
                <button type="button" id="nav-calendar" onclick="switchMainSection('calendar')" class="px-2.5 md:px-3.5 py-1.5 rounded-lg text-xs font-bold bg-indigo-600 text-white transition">
                    📅 تقویم
                </button>
                <button type="button" id="nav-pdf" onclick="switchMainSection('pdf')" class="px-2.5 md:px-3.5 py-1.5 rounded-lg text-xs font-bold text-slate-300 hover:text-white transition">
                    📚 جزوه‌خوان
                </button>
                <a href="paper_reader.php" class="px-2.5 md:px-3.5 py-1.5 rounded-lg text-xs font-bold text-emerald-300 hover:text-white transition">
                    🌐 مقاله‌خوان
                </a>
				<a href="exam.php" class="px-2.5 md:px-3.5 py-1.5 rounded-lg text-xs font-bold text-rose-300 hover:text-white transition">
				    📝 آزمون‌ها
				</a>
                <a href="courses.php" class="px-2.5 md:px-3.5 py-1.5 rounded-lg text-xs font-bold text-amber-300 hover:text-white transition">
                    🎬 ویدیوها
                </a>
            </nav>
        </div>

        <div class="flex items-center gap-1.5 md:gap-2 text-xs">
            <button type="button" id="header-sub-btn" onclick="openSubscriptionModal()" class="<?php echo $initial_sub['is_vip'] ? 'bg-gradient-to-r from-amber-500 to-yellow-400 text-slate-950' : 'bg-indigo-600 hover:bg-indigo-500 text-white border border-indigo-400'; ?> px-2.5 py-1.5 rounded-lg font-extrabold flex items-center gap-1 shadow-xs transition">
                <?php if ($initial_sub['is_vip']): ?>
                    <span>👑 ویژه (<?php echo $initial_sub['days_left']; ?> روز | +تمدید)</span>
                <?php else: ?>
                    <span>⭐ ارتقا به اشتراک ویژه</span>
                <?php endif; ?>
            </button>

            <?php if (!empty($initial_sub['is_admin'])): ?>
                <a href="admin.php" class="bg-slate-800 hover:bg-slate-700 border border-amber-500/50 text-amber-300 px-2.5 py-1.5 rounded-lg font-bold flex items-center gap-1 transition" title="ورود به پنل مدیریت">
                    <span>🛠️</span>
                    <span class="hidden lg:inline">مدیریت</span>
                </a>
            <?php endif; ?>

            <button type="button" onclick="togglePomodoroWidget()" class="bg-slate-800 hover:bg-slate-700 border border-slate-700 text-amber-300 px-2 py-1.5 rounded-lg font-bold flex items-center gap-1 transition" title="تایمر پومودورو و کرنومتر مطالعه">
                <span>⏱️</span>
                <span id="header-timer-badge" class="hidden xl:inline">تایمر</span>
            </button>

            <button type="button" onclick="openPhoneModal()" class="bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sky-300 px-2 py-1.5 rounded-lg font-bold flex items-center gap-1 transition" title="تنظیمات سامانه پیامکی یادآوری">
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
    <!-- ۲. بخش اول: تقویم هفتگی و روزانه تعاملی (Drag & Drop + Drag-to-Create) -->
    <!-- ==================================================== -->
    <main id="section-calendar" class="flex-1 flex flex-col overflow-hidden">
        
        <!-- نوار آمار + دکمه‌های کارنامه هفته و برنامه‌های عقب‌افتاده -->
        <div class="bg-slate-800 text-slate-200 px-3 py-1.5 flex items-center justify-between gap-2 overflow-x-auto text-[11px] md:text-xs shrink-0 border-b border-slate-700">
            <div class="flex items-center gap-2 md:gap-4 shrink-0">
                <div class="flex items-center gap-1 bg-slate-900/70 px-2 py-0.5 rounded-md border border-slate-700">
                    <span class="text-slate-400">📚 برنامه درسی:</span>
                    <span id="stat-planned-hours" class="font-extrabold text-white">0 ساعت</span>
                </div>
                <div class="flex items-center gap-1 bg-emerald-950/60 px-2 py-0.5 rounded-md border border-emerald-700/50">
                    <span class="text-emerald-300">✅ مطالعه واقعی:</span>
                    <span id="stat-completed-hours" class="font-extrabold text-emerald-400">0 ساعت</span>
                </div>
                <div class="flex items-center gap-1 bg-purple-950/60 px-2 py-0.5 rounded-md border border-purple-700/50">
                    <span class="text-purple-300">🧩 متفرقه:</span>
                    <span id="stat-misc-hours" class="font-extrabold text-purple-200">0 از 0 ساعت</span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="text-slate-400">🎯 میانگین تست:</span>
                    <span id="stat-avg-percent" class="font-extrabold text-amber-300">---</span>
                </div>
            </div>

            <div class="flex items-center gap-1.5 shrink-0">
                <button type="button" onclick="openOverdueModal()" id="btn-overdue-backlog" class="hidden items-center gap-1 bg-rose-600/90 hover:bg-rose-600 text-white px-2 py-0.5 rounded-md font-extrabold text-[11px] transition shadow-2xs">
                    <span>⚠️ عقب‌افتاده:</span>
                    <span id="overdue-count-badge">0</span>
                </button>
                <button type="button" onclick="openAnalyticsModal()" class="bg-indigo-600/90 hover:bg-indigo-500 text-white px-2.5 py-0.5 rounded-md font-extrabold text-[11px] flex items-center gap-1 transition">
                    <span>📊 کارنامه هفته</span>
                </button>
                <span id="stat-progress-text" class="font-extrabold text-indigo-300 bg-indigo-950/80 px-2 py-0.5 rounded-md border border-indigo-700">0%</span>
            </div>
        </div>

        <!-- نوار کنترل تقویم -->
        <div class="bg-white border-b border-slate-200 px-3 py-2 flex flex-wrap items-center justify-between gap-2 shrink-0">
            <div class="flex items-center gap-1.5">
                <button type="button" onclick="navigateCalendar(-1)" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-xs font-bold transition">❮</button>
                <button type="button" onclick="goToToday()" class="px-2.5 py-1.5 bg-indigo-50 text-indigo-600 hover:bg-indigo-100 rounded-lg text-xs font-bold transition">امروز</button>
                <button type="button" onclick="navigateCalendar(1)" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-xs font-bold transition">❯</button>
                <h2 id="calendar-range-title" class="text-xs md:text-sm font-extrabold text-slate-800 mr-1"></h2>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
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

                <button type="button" id="btn-grid-zoom" onclick="toggleCalendarRowZoom()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-2 py-1.5 rounded-lg text-xs font-bold transition" title="تغییر ارتفاع ساعات تقویم برای برنامه‌های کوتاه">
                    🔍 <span class="hidden lg:inline">نمای باز</span>
                </button>

                <button type="button" onclick="openCopyDayModal()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-2.5 py-1.5 rounded-lg text-xs font-bold transition" title="کپی کردن برنامه‌های یک روز به روزی دیگر">
                    📋 <span class="hidden sm:inline">کپی روز</span>
                </button>

                <button type="button" onclick="openEventModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg text-xs font-bold shadow-xs transition">
                    + ثبت برنامه
                </button>
            </div>
        </div>

        <!-- راهنمای باریک تعاملی -->
        <div class="bg-indigo-50/80 border-b border-indigo-100 px-3 py-1 text-[10px] md:text-[11px] text-indigo-900 font-bold flex items-center justify-between shrink-0">
            <span>💡 <b>تعامل سریع:</b> روی فضای خالی بکشید تا بازه زمانی ساخته شود؛ یا هر برنامه را با موس/نگه‌داشتن انگشت به ساعت و روز دلخواه بکشید!</span>
            <span id="live-drag-status-pill" class="hidden bg-indigo-600 text-white px-2 py-0.5 rounded-full text-[10px] font-extrabold animate-pulse"></span>
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
    <!-- ۳. بخش دوم: جزوه‌خوان حرفه‌ای (وسط‌چین عمودی و افقی + زوم قفل‌شده روی انگشتان + قلم مات) -->
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

            <div id="pdf-quota-banner" class="px-3 py-2 text-[11px] border-b border-slate-200 flex items-center justify-between bg-amber-50 text-amber-900"></div>

            <div id="pdf-list-container" class="flex-1 overflow-y-auto p-2.5 space-y-2.5 calendar-scroll bg-slate-50/60"></div>
        </aside>

        <div class="flex-1 flex flex-col overflow-hidden relative bg-slate-800">
            
            <div id="pdf-top-bar" class="bg-slate-900/95 text-white border-b border-slate-700 px-2.5 py-1.5 flex items-center justify-between gap-2 shrink-0 z-20">
                <div class="flex items-center gap-1.5 min-w-0">
                    <button type="button" onclick="togglePdfSidebar()" class="px-2.5 py-1 bg-indigo-600 hover:bg-indigo-500 rounded-lg text-xs font-bold text-white shrink-0 shadow-xs">
                        ☰ پوشه‌ها
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

                    <button type="button" id="btn-star-page" onclick="toggleStarCurrentPage()" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 rounded-lg text-xs font-bold text-slate-400 transition" title="نشان کردن صفحه مهم">
                        ☆
                    </button>
                    <button type="button" onclick="openStarredModal()" class="px-2 py-1 bg-amber-500/20 text-amber-300 border border-amber-500/30 rounded-lg text-[11px] font-bold" title="مشاهده صفحات ستاره‌دار">
                        ⭐ <span id="starred-count-badge">0</span>
                    </button>
                </div>

                <button type="button" onclick="toggleFullScreenReader()" id="btn-fullscreen-reader" class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-xs font-extrabold shrink-0 shadow-2xs transition" title="حالت کاملاً فول‌صفحه (اندازه کل صفحه نمایش)">
                    ⛶ فول‌صفحه
                </button>
            </div>

            <button type="button" id="floating-exit-fullscreen-btn" onclick="toggleFullScreenReader()" class="hidden fixed top-3 right-3 z-50 bg-rose-600/90 hover:bg-rose-600 text-white px-3 py-1.5 rounded-full shadow-2xl border border-white/30 text-xs font-extrabold items-center gap-1 backdrop-blur-xs transition">
                <span>✕ خروج از فول‌صفحه</span>
            </button>

            <!-- ناحیه نمایش بوم جزوه (وسط‌چین عمودی و افقی با فضای تنفس برای زوم راحت) -->
            <div id="pdf-viewport-container" class="flex-1 overflow-auto py-6 px-1 sm:p-4 flex calendar-scroll relative">
                
                <div id="pdf-loading-overlay" class="hidden m-auto text-center bg-slate-900/95 text-white p-6 rounded-2xl shadow-xl border border-slate-700 z-30">
                    <div class="text-3xl mb-2 animate-bounce">⏳</div>
                    <div id="pdf-loading-text" class="text-xs font-bold text-indigo-300">در حال بارگذاری و آماده‌سازی صفحات جزوه...</div>
                </div>

                <div id="pdf-empty-state" class="m-auto text-center bg-slate-900/90 text-white p-6 rounded-2xl shadow-xl max-w-xs border border-slate-700">
                    <div class="text-4xl mb-2">📖</div>
                    <h4 class="font-bold text-sm mb-1">جزوه‌ای باز نیست</h4>
                    <p class="text-slate-400 text-xs mb-4 leading-relaxed">از منوی «پوشه‌ها» یک درس را انتخاب کنید یا فایل PDF جدیدی آپلود نمایید.</p>
                    <div class="flex flex-wrap gap-2 justify-center">
                        <button type="button" onclick="togglePdfSidebar()" class="bg-slate-700 hover:bg-slate-600 text-white px-3 py-2 rounded-lg text-xs font-bold">
                            ☰ باز کردن پوشه‌ها
                        </button>
                        <button type="button" onclick="openUploadModal('')" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-lg text-xs font-bold">
                            + آپلود PDF
                        </button>
                    </div>
                </div>

                <div id="pdf-canvas-wrapper" dir="ltr" class="hidden relative shadow-2xl bg-white shrink-0">
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
                <span>ابزارها و قلم‌ها</span>
                <span id="fab-color-dot" class="w-3 h-3 rounded-full border border-white" style="background-color: #facc15;"></span>
            </button>

            <!-- پاپ‌آپ جعبه ابزار پیشرفته جزوه‌خوان -->
            <div id="pdf-tools-popup" class="hidden fixed bottom-16 left-4 z-40 bg-slate-900/95 text-white p-3.5 rounded-2xl shadow-2xl border border-slate-700 w-80 max-w-[94vw] max-h-[82vh] overflow-y-auto calendar-scroll backdrop-blur-md space-y-3 text-xs">
                <div class="flex items-center justify-between border-b border-slate-700 pb-2">
                    <span class="font-extrabold text-slate-200">🛠️ استودیو حاشیه‌نویسی جزوه</span>
                    <div class="flex items-center gap-2">
                        <span id="annot-save-status" class="text-[10px] font-bold text-emerald-400">✓ آماده</span>
                        <button type="button" onclick="toggleToolsPopup()" class="text-slate-400 hover:text-white text-lg font-bold leading-none">&times;</button>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-1.5">
                    <button type="button" onclick="setAnnotTool('pan', true)" id="tool-pan" class="annot-tool-btn px-2.5 py-2 rounded-xl font-bold bg-indigo-600 text-white flex items-center gap-1.5">
                        <span>✋</span><span>مطالعه / زوم</span>
                    </button>
                    <button type="button" onclick="setAnnotTool('pen', true)" id="tool-pen" class="annot-tool-btn px-2.5 py-2 rounded-xl font-bold bg-slate-800 hover:bg-slate-700 text-slate-200 flex items-center gap-1.5">
                        <span>✏️</span><span>قلم خودکاری (مات)</span>
                    </button>
                    <button type="button" onclick="setAnnotTool('highlight', true)" id="tool-highlight" class="annot-tool-btn px-2.5 py-2 rounded-xl font-bold bg-slate-800 hover:bg-slate-700 text-slate-200 flex items-center gap-1.5">
                        <span>🖍️</span><span>ماژیک هایلایت</span>
                    </button>
                    <button type="button" onclick="setAnnotTool('line', true)" id="tool-line" class="annot-tool-btn px-2.5 py-2 rounded-xl font-bold bg-slate-800 hover:bg-slate-700 text-slate-200 flex items-center gap-1.5">
                        <span>📏</span><span>خط‌کش (خط صاف)</span>
                    </button>
                    <button type="button" onclick="setAnnotTool('rect', true)" id="tool-rect" class="annot-tool-btn px-2.5 py-2 rounded-xl font-bold bg-slate-800 hover:bg-slate-700 text-slate-200 flex items-center gap-1.5">
                        <span>⬜</span><span>کادر دور فرمول</span>
                    </button>
                    <button type="button" onclick="setAnnotTool('note', true)" id="tool-note" class="annot-tool-btn px-2.5 py-2 rounded-xl font-bold bg-slate-800 hover:bg-slate-700 text-slate-200 flex items-center gap-1.5">
                        <span>📌</span><span>سنجاق یادداشت</span>
                    </button>
                    <button type="button" onclick="setAnnotTool('eraser', true)" id="tool-eraser" class="col-span-2 annot-tool-btn px-2.5 py-1.5 rounded-xl font-bold bg-slate-800 hover:bg-slate-700 text-slate-200 flex items-center justify-center gap-1.5">
                        <span>🧽</span><span>پاک‌کن (لمس روی خط، کادر یا یادداشت)</span>
                    </button>
                </div>

                <div class="bg-slate-800 p-2.5 rounded-xl space-y-2">
                    <div class="flex items-center justify-between text-[11px] text-slate-300 font-bold">
                        <span>🎨 رنگ قلم / ماژیک:</span>
                        <span class="text-[10px] text-amber-300">⬜ سفید = غلط‌گیر پوشش جواب</span>
                    </div>
                    <div class="grid grid-cols-6 gap-2 pt-0.5 items-center justify-items-center">
                        <button type="button" onclick="setAnnotColor('#0f172a')" data-color="#0f172a" title="مشکی ذغالی (مات)" class="annot-color-btn w-6 h-6 rounded-full bg-slate-950 border border-slate-500 opacity-75 hover:opacity-100 transition"></button>
                        <button type="button" onclick="setAnnotColor('#1d4ed8')" data-color="#1d4ed8" title="آبی خودکاری (مات)" class="annot-color-btn w-6 h-6 rounded-full bg-blue-700 opacity-75 hover:opacity-100 transition"></button>
                        <button type="button" onclick="setAnnotColor('#dc2626')" data-color="#dc2626" title="قرمز خودکاری (مات)" class="annot-color-btn w-6 h-6 rounded-full bg-red-600 opacity-75 hover:opacity-100 transition"></button>
                        <button type="button" onclick="setAnnotColor('#15803d')" data-color="#15803d" title="سبز تیره (مات)" class="annot-color-btn w-6 h-6 rounded-full bg-green-700 opacity-75 hover:opacity-100 transition"></button>
                        <button type="button" onclick="setAnnotColor('#7e22ce')" data-color="#7e22ce" title="بنفش (مات)" class="annot-color-btn w-6 h-6 rounded-full bg-purple-700 opacity-75 hover:opacity-100 transition"></button>
                        <button type="button" onclick="setAnnotColor('#ffffff')" data-color="#ffffff" title="سفید غلط‌گیر (پوشاندن جواب تست)" class="annot-color-btn w-6 h-6 rounded-full bg-white border-2 border-slate-400 opacity-85 hover:opacity-100 transition"></button>

                        <button type="button" onclick="setAnnotColor('#facc15')" data-color="#facc15" title="زرد طلایی" class="annot-color-btn w-6 h-6 rounded-full bg-yellow-400 ring-2 ring-white scale-110 transition"></button>
                        <button type="button" onclick="setAnnotColor('#4ade80')" data-color="#4ade80" title="سبز فسفری" class="annot-color-btn w-6 h-6 rounded-full bg-green-400 opacity-75 hover:opacity-100 transition"></button>
                        <button type="button" onclick="setAnnotColor('#38bdf8')" data-color="#38bdf8" title="آبی آسمانی" class="annot-color-btn w-6 h-6 rounded-full bg-sky-400 opacity-75 hover:opacity-100 transition"></button>
                        <button type="button" onclick="setAnnotColor('#fb7185')" data-color="#fb7185" title="صورتی / مرجانی" class="annot-color-btn w-6 h-6 rounded-full bg-rose-400 opacity-75 hover:opacity-100 transition"></button>
                        <button type="button" onclick="setAnnotColor('#ea580c')" data-color="#ea580c" title="نارنجی" class="annot-color-btn w-6 h-6 rounded-full bg-orange-600 opacity-75 hover:opacity-100 transition"></button>
                        
                        <label title="انتخاب هر رنگ دلخواه" class="w-6 h-6 rounded-full bg-gradient-to-tr from-pink-500 via-amber-400 to-cyan-400 flex items-center justify-center cursor-pointer border border-white/60">
                            <input type="color" id="custom-annot-color-picker" onchange="setAnnotColor(this.value)" class="sr-only">
                            <span class="text-[10px]">🎨</span>
                        </label>
                    </div>
                </div>

                <div class="bg-slate-800 p-2 rounded-xl flex items-center justify-between">
                    <span class="text-[11px] text-slate-300 font-bold">📏 ضخامت قلم:</span>
                    <div class="flex items-center gap-1">
                        <button type="button" onclick="setAnnotSize(1)" id="size-btn-1" class="annot-size-btn px-2 py-1 rounded-lg text-[10px] font-bold bg-slate-700 text-slate-300">ظریف</button>
                        <button type="button" onclick="setAnnotSize(2)" id="size-btn-2" class="annot-size-btn px-2 py-1 rounded-lg text-[10px] font-extrabold bg-indigo-600 text-white">معمولی</button>
                        <button type="button" onclick="setAnnotSize(3)" id="size-btn-3" class="annot-size-btn px-2 py-1 rounded-lg text-[10px] font-bold bg-slate-700 text-slate-300">ضخیم</button>
                        <button type="button" onclick="setAnnotSize(4)" id="size-btn-4" class="annot-size-btn px-2 py-1 rounded-lg text-[10px] font-bold bg-slate-700 text-slate-300">خیلی ضخیم</button>
                    </div>
                </div>

                <div class="grid grid-cols-4 gap-1.5 pt-1 border-t border-slate-700">
                    <button type="button" onclick="adjustZoom(-0.2)" class="py-1.5 bg-slate-800 hover:bg-slate-700 rounded-lg font-bold text-center">➖ زوم</button>
                    <button type="button" onclick="setFitWidthZoom()" class="py-1.5 bg-indigo-950 text-indigo-300 border border-indigo-700 rounded-lg font-bold text-center">↔ عرض</button>
                    <button type="button" onclick="adjustZoom(0.2)" class="py-1.5 bg-slate-800 hover:bg-slate-700 rounded-lg font-bold text-center">➕ زوم</button>
                    <button type="button" onclick="togglePdfDarkMode()" id="btn-pdf-dark" class="py-1.5 bg-slate-800 text-amber-300 hover:bg-slate-700 rounded-lg font-bold text-center">🌙 شب</button>
                </div>

                <div class="grid grid-cols-3 gap-1.5 pt-1 border-t border-slate-700">
                    <button type="button" onclick="undoLastAnnotation()" class="py-1.5 bg-slate-800 hover:bg-slate-700 rounded-lg font-bold text-slate-200 text-center">↩ واگرد</button>
                    <button type="button" onclick="downloadAnnotatedPageImage()" class="py-1.5 bg-emerald-900/80 hover:bg-emerald-700 text-emerald-200 rounded-lg font-bold text-center">📸 عکس صفحه</button>
                    <button type="button" onclick="clearAllPageAnnotations()" class="py-1.5 bg-rose-950/80 hover:bg-rose-700 text-rose-200 rounded-lg font-bold text-center">🗑️ پاکسازی</button>
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
    <!-- ۵. مودال ثبت و ویرایش برنامه تقویم (با تیک پیامک پیش‌فرض غیرفعال و دکمه کپی تسک) -->
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
                            <span class="block text-[10px] font-bold text-slate-600 mb-1">انتخاب رنگ اختصاصی:</span>
                            <div id="custom-color-palette" class="flex flex-wrap items-center gap-2"></div>
                        </div>
                        <div class="flex justify-end">
                            <button type="button" onclick="saveNewCustomCategory()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3.5 py-1.5 rounded-lg font-bold text-[11px]">
                                + ذخیره و انتخاب این نوع
                            </button>
                        </div>
                    </div>
                </div>

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
                            <!-- تیک پیامک یادآوری به صورت پیش‌فرض غیرفعال است -->
                            <input type="checkbox" id="ev-send-sms" name="send_sms" value="1" class="w-4 h-4 accent-indigo-600 rounded">
                            <span class="text-slate-700 font-bold text-[11px]">📱 ارسال پیامک یادآوری عدم انجام</span>
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

                <!-- دکمه‌های عملیات سریع در حالت ویرایش (کپی تسک، انتقال به فردا، تایمر) -->
                <div id="edit-extra-actions" class="hidden grid grid-cols-3 gap-1.5 pt-1">
                    <button type="button" onclick="duplicateCurrentEvent()" class="py-1.5 px-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 rounded-lg font-bold text-[11px] flex items-center justify-center gap-1">
                        <span>📑 کپی برنامه</span>
                    </button>
                    <button type="button" onclick="moveCurrentEventToTomorrow()" class="py-1.5 px-2 bg-sky-50 hover:bg-sky-100 text-sky-700 border border-sky-200 rounded-lg font-bold text-[11px] flex items-center justify-center gap-1">
                        <span>⏭️ انتقال به فردا</span>
                    </button>
                    <button type="button" onclick="startPomodoroForCurrentEvent()" class="py-1.5 px-2 bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-200 rounded-lg font-bold text-[11px] flex items-center justify-center gap-1">
                        <span>▶️ شروع تایمر</span>
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
    <!-- ۶. مودال جدید: کارنامه و نمودار تحلیلی هفته (📊 Weekly Analytics) -->
    <!-- ==================================================== -->
    <div id="analytics-modal" class="fixed inset-0 bg-slate-900/65 backdrop-blur-xs hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden text-xs">
            <div class="bg-slate-900 text-white px-4 py-3 flex items-center justify-between">
                <h3 class="font-extrabold text-sm flex items-center gap-1.5">📊 کارنامه و نمودار تحلیلی این هفته</h3>
                <button type="button" onclick="closeAnalyticsModal()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <div class="p-4 space-y-4 max-h-[82vh] overflow-y-auto calendar-scroll">
                <div class="grid grid-cols-3 gap-2 text-center">
                    <div class="bg-indigo-50 border border-indigo-100 p-2.5 rounded-xl">
                        <div class="text-[10px] text-indigo-600 font-bold">کل برنامه درسی هفته</div>
                        <div id="an-total-planned" class="text-base font-black text-indigo-950 mt-0.5">0 ساعت</div>
                    </div>
                    <div class="bg-emerald-50 border border-emerald-100 p-2.5 rounded-xl">
                        <div class="text-[10px] text-emerald-700 font-bold">مطالعه واقعی انجام‌شده</div>
                        <div id="an-total-done" class="text-base font-black text-emerald-700 mt-0.5">0 ساعت</div>
                    </div>
                    <div class="bg-amber-50 border border-amber-100 p-2.5 rounded-xl">
                        <div class="text-[10px] text-amber-800 font-bold">بهره‌وری اجرای برنامه</div>
                        <div id="an-efficiency" class="text-base font-black text-amber-900 mt-0.5">0%</div>
                    </div>
                </div>

                <!-- نمودار میله‌ای روزهای شنبه تا جمعه -->
                <div class="bg-slate-50 p-3.5 rounded-2xl border border-slate-200">
                    <div class="font-extrabold text-slate-700 mb-3 text-[11px]">📅 نمودار ساعات مطالعه روزهای هفته (سبز = انجام‌شده | خاکستری = برنامه‌ریزی‌شده):</div>
                    <div id="an-daily-bars" class="grid grid-cols-7 gap-2 items-end h-36 pt-4 border-b border-slate-300 px-1"></div>
                </div>

                <!-- تفکیک ساعات بر اساس نوع درس / دسته‌بندی -->
                <div class="bg-slate-50 p-3.5 rounded-2xl border border-slate-200 space-y-2">
                    <div class="font-extrabold text-slate-700 text-[11px]">🗂️ تفکیک ساعات مطالعه بر اساس نوع برنامه:</div>
                    <div id="an-category-breakdown" class="space-y-2"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================================================== -->
    <!-- ۷. مودال جدید: لیست برنامه‌های عقب‌افتاده (⚠️ Overdue Backlog) -->
    <!-- ==================================================== -->
    <div id="overdue-modal" class="fixed inset-0 bg-slate-900/65 backdrop-blur-xs hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden text-xs">
            <div class="bg-rose-950 text-white px-4 py-3 flex items-center justify-between">
                <h3 class="font-extrabold text-sm flex items-center gap-1.5">⚠️ برنامه‌های درسی عقب‌افتاده (تیک‌نخورده)</h3>
                <button type="button" onclick="closeOverdueModal()" class="text-rose-200 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <div class="p-4 space-y-3">
                <p class="text-slate-600 text-[11px] leading-relaxed">
                    زمان این برنامه‌های درسی گذشته اما هنوز تیک انجام آن‌ها زده نشده است. می‌توانید با یک کلیک آن‌ها را تیک بزنید یا به «امروز» / «فردا» منتقل کنید:
                </p>
                <div id="overdue-list-container" class="max-h-80 overflow-y-auto space-y-2 calendar-scroll"></div>
            </div>
        </div>
    </div>

    <!-- ==================================================== -->
    <!-- ۸. مودال خرید و تمدید اشتراک ویژه (VIP) - بدون ذکر نام سرویس‌دهنده پیامک -->
    <!-- ==================================================== -->
    <div id="subscription-modal" class="fixed inset-0 bg-slate-900/75 backdrop-blur-xs hidden items-end sm:items-center justify-center sm:p-4 z-50">
        <div class="bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl w-full max-w-2xl max-h-[94vh] flex flex-col overflow-hidden">
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
                <div id="sub-modal-reason-alert" class="hidden bg-rose-50 border border-rose-200 text-rose-800 px-3.5 py-2.5 rounded-xl font-bold leading-relaxed"></div>

                <div class="bg-slate-50 rounded-2xl p-3.5 border border-slate-200">
                    <h4 class="font-extrabold text-slate-800 mb-2.5 text-xs">✨ با تهیه اشتراک ویژه چه امکاناتی برای شما باز می‌شود؟</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                        <div class="bg-white p-2.5 rounded-xl border border-slate-200 flex items-start gap-2">
                            <span class="text-base">📚</span>
                            <div>
                                <div class="font-extrabold text-slate-800">آپلود نامحدود جزوات و مقالات</div>
                                <div class="text-[11px] text-slate-500 mt-0.5">بدون محدودیت ۱ فایل! تمام کتاب‌ها، جزوات و مقالات را ذخیره کنید.</div>
                            </div>
                        </div>
                        <div class="bg-white p-2.5 rounded-xl border border-slate-200 flex items-start gap-2">
                            <span class="text-base">🚀</span>
                            <div>
                                <div class="font-extrabold text-slate-800">حجم هر فایل تا <?php echo VIP_MAX_PDF_MB; ?> مگابایت</div>
                                <div class="text-[11px] text-slate-500 mt-0.5">حذف محدودیت ۳ مگابایت کاربر عادی و آپلود جزوات حجیم.</div>
                            </div>
                        </div>
                        <div class="bg-white p-2.5 rounded-xl border border-slate-200 flex items-start gap-2">
                            <span class="text-base">📱</span>
                            <div>
                                <div class="font-extrabold text-slate-800">سامانه پیامکی هوشمند پیگیری برنامه</div>
                                <div class="text-[11px] text-slate-500 mt-0.5">ارسال خودکار پیامک یادآوری به موبایل شما در صورت تیک نخوردن برنامه.</div>
                            </div>
                        </div>
                        <div class="bg-white p-2.5 rounded-xl border border-slate-200 flex items-start gap-2">
                            <span class="text-base">🧠</span>
                            <div>
                                <div class="font-extrabold text-slate-800">مترجم هوش مصنوعی در مقاله‌خوان</div>
                                <div class="text-[11px] text-slate-500 mt-0.5">ترجمه تخصصی و استخراج پیام کلیدی مقالات انگلیسی با هوش مصنوعی.</div>
                            </div>
                        </div>
                    </div>
                </div>

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
                                <li>✓ آپلود نامحدود فایل PDF (تا <?php echo VIP_MAX_PDF_MB; ?> مگابایت)</li>
                                <li>✓ فعال‌سازی سامانه پیامکی یادآوری تقویم</li>
                                <li>✓ دسترسی کامل به هوش مصنوعی مقاله‌خوان</li>
                            </ul>
                        </div>
                        <a id="tg-link-1m" href="#" target="_blank" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-extrabold text-center shadow-sm transition flex items-center justify-center gap-1.5">
                            <span>✈️ سفارش ۱ ماهه در تلگرام</span>
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
                                <li>✓ آپلود نامحدود فایل PDF + تمام پوشه‌ها</li>
                                <li>✓ سامانه پیامکی یادآوری + هوش مصنوعی مقاله‌خوان</li>
                            </ul>
                        </div>
                        <a id="tg-link-3m" href="#" target="_blank" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-extrabold text-center shadow-sm transition flex items-center justify-center gap-1.5">
                            <span>✈️ سفارش ۳ ماهه در تلگرام</span>
                        </a>
                    </div>
                </div>

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
    <!-- ۹. سایر مودال‌ها (کپی روز، ساخت پوشه، انتقال جزوه، آپلود، صفحات ستاره‌دار و تنظیم شماره موبایل بدون نام سرویس‌دهنده) -->
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

    <div id="starred-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
            <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between">
                <h3 class="font-bold text-xs md:text-sm">⭐ صفحات ستاره‌دار (نکات مهم جزوه)</h3>
                <button type="button" onclick="closeStarredModal()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <div id="starred-pages-list" class="p-4 max-h-72 overflow-y-auto space-y-2 text-xs"></div>
        </div>
    </div>

    <!-- مودال تنظیم شماره موبایل (بدون ذکر هیچ نامی از شرکت یا سرویس‌دهنده پیامک) -->
    <div id="phone-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
            <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between">
                <h3 class="font-bold text-xs md:text-sm">📱 تنظیمات شماره موبایل و یادآور پیامکی</h3>
                <button type="button" onclick="closePhoneModal()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <form onsubmit="handleUpdatePhone(event)" class="p-4 space-y-3.5 text-xs">
                <div id="phone-modal-vip-notice" class="hidden bg-amber-50 border border-amber-300 text-amber-900 p-2.5 rounded-xl text-[11px] font-bold leading-relaxed">
                    🔒 ارسال خودکار پیامک یادآوری مخصوص مشترکین ویژه است.
                    <button type="button" onclick="closePhoneModal(); openSubscriptionModal('برای فعال‌سازی ارسال خودکار پیامک یادآوری، اشتراک ویژه تهیه کنید.');" class="text-indigo-600 underline mr-1 font-extrabold">مشاهده پلن‌های اشتراک</button>
                </div>
                <p class="text-slate-600 leading-relaxed">
                    برای برنامه‌هایی که گزینه «ارسال پیامک یادآوری» را در آن‌ها فعال کرده باشید، در صورت گذشتن زمان پایان و تیک نخوردن برنامه، یک پیامک هشدار به شماره موبایل زیر ارسال می‌شود:
                </p>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">شماره موبایل دریافت‌کننده *</label>
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

        <!-- PHP-to-JS Configuration Bridge -->
    <script>
        const TODAY_GREGORIAN   = "<?php echo date('Y-m-d'); ?>";
        const KONKUR_GREGORIAN  = "<?php echo KONKUR_GREGORIAN_DATE; ?>";
        const TELEGRAM_SALES_ID = "<?php echo htmlspecialchars(TELEGRAM_SALES_ID); ?>";
        const FREE_MAX_PDF_CNT  = <?php echo (int)FREE_MAX_PDF_COUNT; ?>;
        const FREE_MAX_PDF_MB   = <?php echo (int)FREE_MAX_PDF_MB; ?>;
        const VIP_MAX_PDF_MB    = <?php echo (int)VIP_MAX_PDF_MB; ?>;
        let userSub = <?php echo json_encode($initial_sub, JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <!-- Modular External JavaScript -->
    <script src="assets/js/index.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/index.js') ?: '4.4'; ?>"></script>
</body>
</html>