<?php
require_once 'config.php';
require_login();

$user_id = (int)$_SESSION['user_id'];

// اطمینان از وجود ستون‌های نسخه ۲ در جدول تقویم و کاربران
try {
    $uCols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('phone', $uCols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `phone` varchar(15) DEFAULT NULL AFTER `username`");
    }

    $eCols = $pdo->query("SHOW COLUMNS FROM `calendar_events`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('category_type', $eCols)) {
        $pdo->exec("ALTER TABLE `calendar_events` ADD COLUMN `category_type` varchar(30) DEFAULT 'specialized' AFTER `color`");
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
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>داشبورد هوشمند مطالعه و تقویم کنکور v2.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
    
    <!-- بارگذاری نسخه Universal/Legacy کتابخانه PDF.js (سازگار با ۱۰۰٪ مرورگرهای موبایل و دسکتاپ) با ۳ سرور پشتیبان -->
    <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@2.16.105/build/pdf.min.js"
            onerror="this.onerror=null;this.src='https://unpkg.com/pdfjs-dist@2.16.105/build/pdf.min.js';"></script>

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
    </style>
</head>
<body class="bg-slate-100 text-slate-800 h-screen flex flex-col overflow-hidden select-none">

    <!-- ==================================================== -->
    <!-- ۱. هدر اصلی سامانه -->
    <!-- ==================================================== -->
    <header id="main-app-header" class="bg-slate-900 text-white px-3 md:px-4 py-2 flex flex-wrap items-center justify-between gap-2 shadow-md z-30 shrink-0">
        <div class="flex items-center gap-2 md:gap-4">
            <div class="flex items-center gap-1.5">
                <span class="bg-indigo-600 text-white px-2 py-1 rounded-lg font-extrabold text-xs">🎯 کنکور</span>
                <span class="font-bold text-xs md:text-sm truncate max-w-[100px] sm:max-w-none"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
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

        <div class="flex items-center gap-1.5 md:gap-2.5 text-xs">
            <button type="button" onclick="togglePomodoroWidget()" class="bg-slate-800 hover:bg-slate-700 border border-slate-700 text-amber-300 px-2.5 py-1.5 rounded-lg font-bold flex items-center gap-1 transition" title="تایمر پومودورو و کرنومتر مطالعه">
                <span>⏱️</span>
                <span id="header-timer-badge" class="hidden sm:inline">تایمر</span>
            </button>

            <button type="button" onclick="openPhoneModal()" class="bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sky-300 px-2.5 py-1.5 rounded-lg font-bold flex items-center gap-1 transition" title="تنظیمات پیامک یادآوری sms.ir">
                <span>📱</span>
                <span class="hidden md:inline">پیامک</span>
            </button>

            <div class="bg-rose-600/20 border border-rose-500/40 text-rose-200 px-2.5 py-1.5 rounded-lg font-bold">
                ⏳ <?php echo DAYS_TO_KONKUR; ?> روز تا ۱۶ بهمن
            </div>

            <a href="auth.php?logout=1" class="text-slate-400 hover:text-rose-400 font-bold px-1.5 py-1">خروج</a>
        </div>
    </header>

    <!-- ==================================================== -->
    <!-- ۲. بخش اول: تقویم هفتگی و روزانه هوشمند -->
    <!-- ==================================================== -->
    <main id="section-calendar" class="flex-1 flex flex-col overflow-hidden">
        
        <div class="bg-slate-800 text-slate-200 px-3 py-1.5 flex items-center justify-between gap-2 overflow-x-auto text-[11px] md:text-xs shrink-0 border-b border-slate-700">
            <div class="flex items-center gap-3 md:gap-6 shrink-0">
                <div class="flex items-center gap-1">
                    <span class="text-slate-400">برنامه هفته:</span>
                    <span id="stat-planned-hours" class="font-extrabold text-white">0 ساعت</span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="text-slate-400">مطالعه واقعی:</span>
                    <span id="stat-completed-hours" class="font-extrabold text-emerald-400">0 ساعت</span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="text-slate-400">میانگین تست:</span>
                    <span id="stat-avg-percent" class="font-extrabold text-amber-300">---</span>
                </div>
            </div>
            <div class="flex items-center gap-1 shrink-0">
                <span class="text-slate-400 hidden sm:inline">پیشرفت کل:</span>
                <span id="stat-progress-text" class="font-extrabold text-indigo-300 bg-indigo-950/80 px-2 py-0.5 rounded-md border border-indigo-700">0%</span>
            </div>
        </div>

        <div class="bg-white border-b border-slate-200 px-3 py-2 flex flex-wrap items-center justify-between gap-2 shrink-0">
            <div class="flex items-center gap-1.5">
                <button type="button" onclick="navigateCalendar(-1)" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-xs font-bold transition">❮</button>
                <button type="button" onclick="goToToday()" class="px-2.5 py-1.5 bg-indigo-50 text-indigo-600 hover:bg-indigo-100 rounded-lg text-xs font-bold transition">امروز</button>
                <button type="button" onclick="navigateCalendar(1)" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-xs font-bold transition">❯</button>
                <h2 id="calendar-range-title" class="text-xs md:text-sm font-extrabold text-slate-800 mr-1"></h2>
            </div>

            <div class="flex items-center gap-2">
                <div class="flex bg-slate-100 p-0.5 rounded-lg border border-slate-200">
                    <button type="button" id="btn-view-week" onclick="setViewMode('week')" class="px-2.5 py-1 rounded-md text-xs font-bold bg-white shadow-2xs text-indigo-600">
                        هفتگی
                    </button>
                    <button type="button" id="btn-view-day" onclick="setViewMode('day')" class="px-2.5 py-1 rounded-md text-xs font-bold text-slate-600">
                        روزانه
                    </button>
                </div>

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

        <!-- سایدبار پوشه‌بندی و کتابخانه جزوات -->
        <aside id="pdf-sidebar" class="w-80 max-w-[86vw] bg-white border-l border-slate-200 flex flex-col shrink-0 z-40 fixed md:static inset-y-0 right-0 transform translate-x-full md:translate-x-0 transition-transform duration-200 shadow-2xl md:shadow-none">
            <div class="p-3 border-b border-slate-200 flex items-center justify-between bg-slate-50">
                <h3 class="font-extrabold text-xs md:text-sm text-slate-800">📚 پوشه‌ها و جزوات من</h3>
                <div class="flex items-center gap-1">
                    <button type="button" onclick="openFolderModal()" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 px-2 py-1.5 rounded-lg text-[11px] font-bold transition">
                        📁+ پوشه جدید
                    </button>
                    <button type="button" onclick="openUploadModal('')" class="bg-indigo-600 hover:bg-indigo-700 text-white px-2.5 py-1.5 rounded-lg text-[11px] font-bold transition">
                        + آپلود PDF
                    </button>
                    <button type="button" onclick="closePdfSidebarMobile()" class="md:hidden text-slate-400 hover:text-slate-700 px-1.5 text-xl font-bold leading-none">&times;</button>
                </div>
            </div>

            <div class="bg-slate-100 px-3 py-1.5 text-[11px] text-slate-500 border-b border-slate-200 flex items-center justify-between">
                <span>برای باز/بسته شدن هر پوشه روی نام آن بزنید</span>
            </div>

            <div id="pdf-list-container" class="flex-1 overflow-y-auto p-2.5 space-y-2.5 calendar-scroll bg-slate-50/60"></div>
        </aside>

        <!-- محیط اصلی مطالعه PDF -->
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

            <!-- ناحیه اسکرول و رندر بوم PDF -->
            <div id="pdf-viewport-container" class="flex-1 overflow-auto p-1 sm:p-3 flex justify-center items-start calendar-scroll relative">
                
                <!-- نشانگر بارگذاری فایل PDF -->
                <div id="pdf-loading-overlay" class="hidden my-auto text-center bg-slate-900/95 text-white p-6 rounded-2xl shadow-xl border border-slate-700 z-30">
                    <div class="text-3xl mb-2 animate-bounce">⏳</div>
                    <div id="pdf-loading-text" class="text-xs font-bold text-indigo-300">در حال بارگذاری و آماده‌سازی تمام صفحات جزوه...</div>
                </div>

                <!-- پیام پیش‌فرض قبل از انتخاب جزوه -->
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

                <!-- بوم رندر PDF + لایه علامت‌گذاری -->
                <div id="pdf-canvas-wrapper" class="hidden relative shadow-2xl bg-white">
                    <canvas id="pdf-render-canvas" class="block"></canvas>
                    <canvas id="pdf-annot-canvas" class="absolute inset-0 pointer-events-none"></canvas>
                </div>
            </div>

            <!-- کنترل شناور ورق زدن سریع در پایین صفحه -->
            <div id="pdf-floating-bottom-bar" class="hidden fixed bottom-4 right-4 z-30 items-center gap-1.5">
                <button type="button" onclick="changePdfPage(-1)" class="w-9 h-9 rounded-full bg-slate-900/90 text-white shadow-lg border border-slate-700 font-bold text-sm flex items-center justify-center">❮</button>
                <span id="floating-page-pill" class="bg-slate-900/90 text-white px-3 py-1.5 rounded-full text-xs font-bold shadow-lg border border-slate-700">
                    ص 1
                </span>
                <button type="button" onclick="changePdfPage(1)" class="w-9 h-9 rounded-full bg-slate-900/90 text-white shadow-lg border border-slate-700 font-bold text-sm flex items-center justify-center">❯</button>
            </div>

            <!-- دکمه شناور باز کردن جعبه ابزار علامت‌گذاری (FAB) -->
            <button type="button" id="pdf-fab-btn" onclick="toggleToolsPopup()" class="hidden fixed bottom-4 left-4 z-40 bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-2.5 rounded-full shadow-2xl font-extrabold text-xs items-center gap-1.5 border-2 border-white/20 transition transform active:scale-95">
                <span id="fab-active-icon">✋</span>
                <span>ابزارها و علامت‌گذاری</span>
            </button>

            <!-- پنجره پاپ‌آپ شناور ابزارهای PDF -->
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
    <!-- ۴. ویجت شناور تایمر پومودورو و کرنومتر مطالعه -->
    <!-- ==================================================== -->
    <div id="pomodoro-widget" class="hidden fixed top-14 left-4 z-50 bg-slate-900 text-white rounded-2xl shadow-2xl border border-slate-700 w-64 p-3.5 text-xs">
        <div class="flex items-center justify-between border-b border-slate-800 pb-2 mb-2.5">
            <span class="font-extrabold text-amber-300 flex items-center gap-1">⏱️ تایمر مطالعه کنکور</span>
            <button type="button" onclick="togglePomodoroWidget()" class="text-slate-400 hover:text-white text-base">&times;</button>
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
    <!-- ۵. مودال ثبت و ویرایش برنامه تقویم -->
    <!-- ==================================================== -->
    <div id="event-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-end sm:items-center justify-center sm:p-4 z-50">
        <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl w-full max-w-lg max-h-[92vh] flex flex-col overflow-hidden">
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
                        <select id="ev-date" name="g_date" required class="w-full px-2 py-2 border border-slate-300 rounded-lg bg-white text-[11px]"></select>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">از ساعت *</label>
                        <input type="time" id="ev-start" name="start_time" required class="w-full px-2 py-2 border border-slate-300 rounded-lg text-center">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">تا ساعت *</label>
                        <input type="time" id="ev-end" name="end_time" required class="w-full px-2 py-2 border border-slate-300 rounded-lg text-center">
                    </div>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">نوع و رنگ برنامه</label>
                    <div class="grid grid-cols-3 sm:grid-cols-5 gap-1.5">
                        <label class="flex items-center gap-1 cursor-pointer bg-slate-50 px-2 py-1.5 rounded-lg border hover:border-indigo-400">
                            <input type="radio" name="color" value="#4f46e5" checked>
                            <span class="w-2.5 h-2.5 rounded-full bg-indigo-600 shrink-0"></span>
                            <span class="text-[11px] font-bold">تخصصی</span>
                        </label>
                        <label class="flex items-center gap-1 cursor-pointer bg-slate-50 px-2 py-1.5 rounded-lg border hover:border-emerald-400">
                            <input type="radio" name="color" value="#059669">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-600 shrink-0"></span>
                            <span class="text-[11px] font-bold">مرور</span>
                        </label>
                        <label class="flex items-center gap-1 cursor-pointer bg-slate-50 px-2 py-1.5 rounded-lg border hover:border-amber-400">
                            <input type="radio" name="color" value="#d97706">
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-600 shrink-0"></span>
                            <span class="text-[11px] font-bold">تست‌زنی</span>
                        </label>
                        <label class="flex items-center gap-1 cursor-pointer bg-slate-50 px-2 py-1.5 rounded-lg border hover:border-rose-400">
                            <input type="radio" name="color" value="#e11d48">
                            <span class="w-2.5 h-2.5 rounded-full bg-rose-600 shrink-0"></span>
                            <span class="text-[11px] font-bold">آزمون</span>
                        </label>
                        <label class="flex items-center gap-1 cursor-pointer bg-slate-50 px-2 py-1.5 rounded-lg border hover:border-purple-400 col-span-2 sm:col-span-1">
                            <input type="radio" name="color" value="#7c3aed">
                            <span class="w-2.5 h-2.5 rounded-full bg-purple-600 shrink-0"></span>
                            <span class="text-[11px] font-bold">متفرقه</span>
                        </label>
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
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <input type="checkbox" id="ev-send-sms" name="send_sms" value="1" checked class="w-4 h-4 accent-indigo-600 rounded">
                            <span class="text-slate-700 font-bold text-[11px]">📱 ارسال پیامک در صورت عدم انجام</span>
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
    <!-- ۶. مودال ساخت پوشه درسی جدید -->
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
    <!-- ۷. مودال انتقال جزوه به پوشه دیگر -->
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
    <!-- ۸. مودال آپلود جزوه PDF -->
    <!-- ==================================================== -->
    <div id="upload-pdf-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
            <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between">
                <h3 class="font-bold text-xs md:text-sm">آپلود جزوه PDF جدید</h3>
                <button type="button" onclick="closeUploadModal()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <form id="upload-pdf-form" onsubmit="handlePdfUpload(event)" class="p-4 space-y-3.5 text-xs">
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
                    <input type="file" name="pdf_file" accept="application/pdf" required class="w-full border border-slate-300 rounded-lg p-2 bg-slate-50">
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t">
                    <button type="button" onclick="closeUploadModal()" class="px-3.5 py-2 bg-slate-100 rounded-lg font-bold">انصراف</button>
                    <button type="submit" id="btn-submit-upload" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-bold">آپلود و ذخیره</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==================================================== -->
    <!-- ۹. مودال نمایش صفحات ستاره‌دار جزوه فعال -->
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
    <!-- ۱۰. مودال تنظیمات شماره موبایل و پیامک یادآوری sms.ir -->
    <!-- ==================================================== -->
    <div id="phone-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
            <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between">
                <h3 class="font-bold text-xs md:text-sm">📱 تنظیمات پیامک یادآوری (sms.ir)</h3>
                <button type="button" onclick="closePhoneModal()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <form onsubmit="handleUpdatePhone(event)" class="p-4 space-y-3.5 text-xs">
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
        // ۰. تضمین بارگذاری صددرصدی PDF.js در تمام مرورگرها و اینترنت‌های موبایل
        // ====================================================
        const PDFJS_CDN_LIST = [
            {
                lib: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@2.16.105/build/pdf.min.js',
                worker: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@2.16.105/build/pdf.worker.min.js',
                cmaps: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@2.16.105/cmaps/',
                fonts: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@2.16.105/standard_fonts/'
            },
            {
                lib: 'https://unpkg.com/pdfjs-dist@2.16.105/build/pdf.min.js',
                worker: 'https://unpkg.com/pdfjs-dist@2.16.105/build/pdf.worker.min.js',
                cmaps: 'https://unpkg.com/pdfjs-dist@2.16.105/cmaps/',
                fonts: 'https://unpkg.com/pdfjs-dist@2.16.105/standard_fonts/'
            },
            {
                lib: 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js',
                worker: 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js',
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

        // تنظیم اولیه Worker در صورت لود شدن از تگ head
        if (typeof window.pdfjsLib !== 'undefined') {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = activeCdnConfig.worker;
        }

        // ====================================================
        // متغیرهای پایه و تبدیل تاریخ شمسی
        // ====================================================
        const TODAY_GREGORIAN  = "<?php echo date('Y-m-d'); ?>";
        const KONKUR_GREGORIAN = "<?php echo KONKUR_GREGORIAN_DATE; ?>";

        let viewMode = window.innerWidth < 768 ? 'day' : 'week';
        let anchorDate = new Date(TODAY_GREGORIAN + 'T00:00:00');
        let loadedEvents = [];

        const JALALI_MONTHS = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
        const WEEKDAY_NAMES = ['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنج‌شنبه','جمعه'];

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

        // ====================================================
        // رندر تقویم
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
                let stripHtml = '';
                fullWeek.forEach(wd => {
                    const gStr = formatGDate(wd);
                    const jInf = getJalaliInfo(wd);
                    const isSelected = (gStr === activeG);
                    const isLocked = (gStr > KONKUR_GREGORIAN);
                    stripHtml += `
                        <button type="button" onclick="${isLocked ? '' : `selectDayForDailyView('${gStr}')`}" class="py-1.5 rounded-xl flex flex-col items-center justify-center transition ${isSelected ? 'bg-indigo-600 text-white shadow-xs' : 'bg-white text-slate-700 border border-slate-200'} ${isLocked ? 'opacity-40' : ''}">
                            <span class="text-[10px] font-bold">${jInf.dayName.slice(0,1)}</span>
                            <span class="text-xs font-extrabold mt-0.5">${jInf.jd}</span>
                        </button>
                    `;
                });
                stripEl.innerHTML = stripHtml;
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
            selectDateEl.innerHTML = '';
            fullWeek.forEach(wd => {
                const gStr = formatGDate(wd);
                if (gStr <= KONKUR_GREGORIAN) {
                    const jInf = getJalaliInfo(wd);
                    const opt = document.createElement('option');
                    opt.value = gStr;
                    opt.textContent = `${jInf.dayName} ${jInf.jd} ${jInf.monthName}`;
                    selectDateEl.appendChild(opt);
                }
            });

            let headerHtml = '';
            let gridHtml   = '';

            days.forEach(dObj => {
                const gStr = formatGDate(dObj);
                const jInfo = getJalaliInfo(dObj);
                const isToday = (gStr === TODAY_GREGORIAN);
                const isKonkurDay = (gStr === KONKUR_GREGORIAN);
                const isLocked = (gStr > KONKUR_GREGORIAN);

                let badgeHtml = '';
                if (isKonkurDay) badgeHtml = `<span class="block text-[9px] bg-rose-600 text-white px-1 rounded-full mt-0.5">🎯 کنکور</span>`;
                else if (isLocked) badgeHtml = `<span class="block text-[9px] text-slate-400 mt-0.5">🔒 قفل</span>`;

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

            try {
                const startG = formatGDate(fullWeek[0]);
                const endG   = formatGDate(fullWeek[6]);
                const res    = await fetch(`api_calendar.php?action=list&start=${startG}&end=${endG}`);
                const data   = await res.json();

                if (data.status === 'success') {
                    loadedEvents = data.events || [];
                    if (data.user_phone) {
                        document.getElementById('user-phone-input').value = data.user_phone;
                    }
                    drawEventsOnGrid();
                    updateCalendarStatsBar(data.stats || {});
                }
            } catch (err) {
                console.error('Calendar fetch error:', err);
            }

            drawCurrentTimeIndicator();
        }

        function drawEventsOnGrid() {
            loadedEvents.forEach(ev => {
                const dayCol = document.querySelector(`[data-gdate="${ev.g_date}"] .events-layer`);
                if (!dayCol) return;

                const [sh, sm] = ev.start_time.split(':').map(Number);
                const [eh, em] = ev.end_time.split(':').map(Number);
                const startMinutes = (sh * 60) + sm;
                const endMinutes   = (eh * 60) + em;
                const duration     = Math.max(28, endMinutes - startMinutes);

                const card = document.createElement('div');
                card.className = 'pointer-events-auto absolute right-1 left-1 rounded-lg p-1.5 text-white shadow-md cursor-pointer overflow-hidden transition hover:brightness-95 flex flex-col justify-between border border-white/20';
                card.style.top = `${startMinutes}px`;
                card.style.height = `${duration}px`;
                card.style.backgroundColor = ev.color || '#4f46e5';
                if (Number(ev.is_completed) === 1) card.style.opacity = '0.68';

                card.onclick = (e) => {
                    e.stopPropagation();
                    openEventModalForEdit(ev);
                };

                const checkBadge = Number(ev.is_completed) === 1 ? '✅ ' : '';
                let badgesHtml = '<div class="flex flex-wrap gap-1 mt-0.5">';
                if (ev.pdf_id) {
                    badgesHtml += `<span class="bg-black/30 px-1.5 py-0.5 rounded text-[9px] font-bold">📖 ص ${ev.pdf_page}</span>`;
                }
                if (ev.test_percent !== null && ev.test_percent !== undefined && ev.test_percent !== '') {
                    badgesHtml += `<span class="bg-amber-300 text-slate-900 px-1.5 py-0.5 rounded text-[9px] font-extrabold">🎯 ${ev.test_percent}%</span>`;
                }
                badgesHtml += '</div>';

                card.innerHTML = `
                    <div>
                        <div class="text-[11px] font-extrabold leading-tight ${Number(ev.is_completed) === 1 ? 'line-through' : ''}">
                            ${checkBadge}${escapeHtml(ev.title)}
                        </div>
                        <div class="text-[9px] opacity-90">${ev.start_time.slice(0,5)} - ${ev.end_time.slice(0,5)}</div>
                    </div>
                    ${badgesHtml}
                `;
                dayCol.appendChild(card);
            });
        }

        function drawCurrentTimeIndicator() {
            const todayCol = document.querySelector(`[data-gdate="${TODAY_GREGORIAN}"] .events-layer`);
            if (!todayCol) return;
            const now = new Date();
            const mins = (now.getHours() * 60) + now.getMinutes();
            const line = document.createElement('div');
            line.className = 'absolute right-0 left-0 border-t-2 border-rose-500 z-10 pointer-events-none flex items-center';
            line.style.top = `${mins}px`;
            line.innerHTML = `<span class="w-2.5 h-2.5 rounded-full bg-rose-500 -mr-1.5"></span>`;
            todayCol.appendChild(line);
        }

        function updateCalendarStatsBar(stats) {
            const total = stats.total || 0;
            const comp  = stats.completed || 0;
            const pct   = total > 0 ? Math.round((comp / total) * 100) : 0;
            document.getElementById('stat-progress-text').textContent = `${pct}% (${comp}/${total})`;
            document.getElementById('stat-planned-hours').textContent = `${stats.planned_hours || 0} ساعت`;
            document.getElementById('stat-completed-hours').textContent = `${stats.completed_hours || 0} ساعت`;
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

        function openEventModal(gDate = null, startTime = '08:00', endTime = '09:30') {
            document.getElementById('event-form').reset();
            document.getElementById('ev-id').value = '';
            document.getElementById('modal-title').textContent = 'ثبت برنامه جدید در تقویم';
            document.getElementById('repeat-mode-wrapper').classList.remove('hidden');
            document.getElementById('live-test-percent-badge').textContent = '---';

            const targetDate = gDate || formatGDate(anchorDate);
            if (targetDate > KONKUR_GREGORIAN) {
                alert('امکان ثبت برنامه پس از ۱۶ بهمن وجود ندارد.');
                return;
            }
            document.getElementById('ev-date').value = targetDate;
            document.getElementById('ev-start').value = startTime;
            document.getElementById('ev-end').value = endTime;

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
            document.getElementById('ev-send-sms').checked = Number(ev.send_sms ?? 1) === 1;

            document.getElementById('ev-test-correct').value = (ev.test_correct !== null && ev.test_correct !== undefined) ? ev.test_correct : '';
            document.getElementById('ev-test-wrong').value   = (ev.test_wrong !== null && ev.test_wrong !== undefined) ? ev.test_wrong : '';
            document.getElementById('ev-test-blank').value   = (ev.test_blank !== null && ev.test_blank !== undefined) ? ev.test_blank : '';
            updateLiveTestPercent();

            document.getElementById('repeat-mode-wrapper').classList.add('hidden');

            const colorRadio = document.querySelector(`input[name="color"][value="${ev.color}"]`);
            if (colorRadio) colorRadio.checked = true;

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
            if (!document.getElementById('ev-send-sms').checked) {
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
        // مدیریت پوشه‌بندی آکاردئونی، انتقال جزوه و موتور ضدخطای PDFخوان
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
                    pdfFolders.forEach(f => {
                        if (openFolderIds[f.id] === undefined) {
                            openFolderIds[f.id] = true;
                        }
                    });
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
                    html += `<option value="${p.id}">${escapeHtml(folderPrefix + p.title)}</option>`;
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

            return `
                <div onclick="loadAndOpenPdf(${item.id}, ${last})" class="p-2.5 rounded-xl border transition cursor-pointer ${isActive ? 'border-indigo-600 bg-indigo-50/80 ring-1 ring-indigo-500' : 'border-slate-200 hover:border-indigo-300 bg-slate-50/40'}">
                    <div class="flex items-start justify-between gap-1">
                        <h4 class="text-xs font-extrabold text-slate-800 leading-snug">${escapeHtml(item.title)}</h4>
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

        function openUploadModal(defaultFolderId = '') {
            document.getElementById('upload-pdf-form').reset();
            syncDropdowns();
            if (defaultFolderId !== undefined && defaultFolderId !== null) {
                document.getElementById('upload-folder-select').value = String(defaultFolderId);
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
        // بارگذاری باینری و بدون خطای PDF (حذف کامل ارور 412 و ارور pdfjsLib)
        // ====================================================
        async function loadAndOpenPdf(pdfId, targetPage = null) {
            if (pdfList.length === 0) await fetchUserPdfs();
            activePdfMeta = pdfList.find(p => Number(p.id) === Number(pdfId));
            if (!activePdfMeta) return;

            if (activePdfMeta.folder_id) {
                openFolderIds[activePdfMeta.folder_id] = true;
            }
            closePdfSidebarMobile();

            document.getElementById('active-pdf-title').textContent = activePdfMeta.title;
            document.getElementById('pdf-empty-state').classList.add('hidden');
            document.getElementById('pdf-canvas-wrapper').classList.add('hidden');
            document.getElementById('pdf-loading-overlay').classList.remove('hidden');

            try {
                // ۱. اطمینان از آماده بودن کتابخانه PDF.js در مرورگر موبایل/دسکتاپ
                const pdfjs = await ensurePdfJsReady();

                // ۲. دریافت کل فایل به صورت ArrayBuffer از طریق اکشن raw در PHP (جلوگیری ۱۰۰٪ از خطای 412 وب‌سرور)
                const rawResponse = await fetch(`api_pdf.php?action=raw&pdf_id=${activePdfMeta.id}&t=${Date.now()}`, {
                    cache: 'no-store',
                    credentials: 'same-origin'
                });

                if (!rawResponse.ok) {
                    throw new Error(`خطای سرور در دریافت فایل (کد ${rawResponse.status})`);
                }

                const arrayBuffer = await rawResponse.arrayBuffer();
                const pdfBytes = new Uint8Array(arrayBuffer);

                if (pdfDocInstance && typeof pdfDocInstance.destroy === 'function') {
                    await pdfDocInstance.destroy();
                }

                // ۳. ساخت سند PDF در حافظه همراه با پشتیبانی کامل از فونت‌های فارسی (CMap)
                const loadingTask = pdfjs.getDocument({
                    data: pdfBytes,
                    cMapUrl: activeCdnConfig.cmaps,
                    cMapPacked: true,
                    standardFontDataUrl: activeCdnConfig.fonts,
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

        // رندر ایمن هر صفحه با مدیریت حافظه بوم در موبایل
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

                // محدود کردن هوشمند ضریب پیکسل در موبایل برای جلوگیری از سفید شدن بوم در صفحات سنگین
                const maxSafeDpr = window.innerWidth < 768 ? 1.65 : 2.0;
                let outputScale = Math.min(window.devicePixelRatio || 1, maxSafeDpr);

                // جلوگیری از عبور ابعاد بوم از سقف حافظه مرورگرهای موبایل (حداکثر 3800 پیکسل در هر ضلع)
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
                annotCtx.font = `bold ${Math.round(boxSize * 0.55)}px Vazirmatn, sans-serif`;
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
            const fd = new FormData();
            fd.append('action', 'update_phone');
            fd.append('phone', document.getElementById('user-phone-input').value);
            const res = await fetch('api_calendar.php', { method: 'POST', body: fd });
            const data = await res.json();
            alert(data.message);
            if (data.status === 'success') closePhoneModal();
        }

        async function triggerSmsCheckNow(showAlert = false) {
            try {
                const res = await fetch('cron_sms.php');
                const data = await res.json();
                if (showAlert) {
                    alert(`بررسی انجام شد.\nبرنامه‌های عقب‌افتاده: ${data.overdue_found}\nپیامک ارسال‌شده: ${data.sms_sent}`);
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