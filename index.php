<?php
require_once 'config.php';
require_login();

$user_id = (int)$_SESSION['user_id'];

// دریافت لیست جزوات کاربر برای نمایش در منوی انتخاب جزوه داخل تقویم
$pdfStmt = $pdo->prepare("SELECT id, title, total_pages, last_page FROM pdfs WHERE user_id = ? ORDER BY id DESC");
$pdfStmt->execute([$user_id]);
$user_pdfs = $pdfStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>داشبورد آموزشی و تقویم کنکور</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
    <style>
        body { font-family: 'Vazirmatn', sans-serif; }
        /* تنظیم دقیق ارتفاع هر ساعت = 60px (هر دقیقه = 1px) */
        .hour-row { height: 60px; }
        .calendar-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .calendar-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .locked-day-pattern {
            background-image: repeating-linear-gradient(45deg, #f8fafc 0, #f8fafc 10px, #f1f5f9 10px, #f1f5f9 20px);
        }
    </style>
<!-- کتابخانه رسمی PDF.js موزیلا -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    </script>
    <style>
        /* فیلتر حالت مطالعه شب روی بوم PDF */
        .pdf-dark-mode {
            filter: invert(0.92) hue-rotate(180deg) contrast(1.05);
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 h-screen flex flex-col overflow-hidden">

    <!-- ۱. نوار بالایی داشبورد (Header) -->
    <header class="bg-slate-900 text-white px-4 py-2.5 flex flex-wrap items-center justify-between gap-3 shadow-md z-20 shrink-0">
        <div class="flex items-center gap-4">
            <div class="flex items-center gap-2">
                <span class="bg-indigo-600 text-white p-1.5 rounded-lg font-bold text-sm">🎯 کنکور</span>
                <span class="font-bold text-sm md:text-base"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
            </div>

            <!-- دکمه‌های جابجایی بین تقویم و جزوه‌خوان -->
            <nav class="flex bg-slate-800 p-1 rounded-xl">
                <button id="nav-calendar" onclick="switchMainSection('calendar')" class="px-3 py-1.5 rounded-lg text-xs md:text-sm font-bold bg-indigo-600 text-white transition">
                    📅 تقویم مطالعاتی
                </button>
                <button id="nav-pdf" onclick="switchMainSection('pdf')" class="px-3 py-1.5 rounded-lg text-xs md:text-sm font-bold text-slate-300 hover:text-white transition">
                    📚 جزوه‌خوان PDF
                </button>
            </nav>
        </div>

        <!-- وضعیت روزشمار ۱۶ بهمن و درصد پیشرفت -->
        <div class="flex items-center gap-4 text-xs md:text-sm">
            <div class="hidden sm:flex items-center gap-2 bg-slate-800 px-3 py-1.5 rounded-lg border border-slate-700">
                <span>پیشرفت برنامه‌ها:</span>
                <span id="stat-progress-text" class="font-bold text-emerald-400">0%</span>
            </div>

            <div class="bg-rose-600/20 border border-rose-500/40 text-rose-200 px-3 py-1.5 rounded-lg font-bold">
                ⏳ <?php echo DAYS_TO_KONKUR; ?> روز تا ۱۶ بهمن
            </div>

            <a href="auth.php?logout=1" class="text-slate-400 hover:text-rose-400 text-xs font-bold px-2 py-1">
                خروج
            </a>
        </div>
    </header>

    <!-- ========================================== -->
    <!-- ۲. بخش اول: تقویم هفتگی و روزانه گوگل کلندر -->
    <!-- ========================================== -->
    <main id="section-calendar" class="flex-1 flex flex-col overflow-hidden">
        
        <!-- نوار ابزار کنترل تقویم -->
        <div class="bg-white border-b border-slate-200 px-4 py-2.5 flex flex-wrap items-center justify-between gap-2 shrink-0">
            <div class="flex items-center gap-2">
                <button onclick="navigateCalendar(-1)" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-xs font-bold transition">
                    ❮ قبلی
                </button>
                <button onclick="goToToday()" class="px-3 py-1.5 bg-indigo-50 text-indigo-600 hover:bg-indigo-100 rounded-lg text-xs font-bold transition">
                    امروز
                </button>
                <button id="btn-next-period" onclick="navigateCalendar(1)" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-xs font-bold transition">
                    بعدی ❯
                </button>
                <h2 id="calendar-range-title" class="text-sm md:text-base font-extrabold text-slate-800 mr-2"></h2>
            </div>

            <div class="flex items-center gap-2">
                <!-- تغییر نمای هفتگی / روزانه -->
                <div class="flex bg-slate-100 p-1 rounded-lg border border-slate-200">
                    <button id="btn-view-week" onclick="setViewMode('week')" class="px-3 py-1 rounded-md text-xs font-bold bg-white shadow-sm text-indigo-600">
                        هفتگی
                    </button>
                    <button id="btn-view-day" onclick="setViewMode('day')" class="px-3 py-1 rounded-md text-xs font-bold text-slate-600">
                        روزانه
                    </button>
                </div>

                <button onclick="openEventModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3.5 py-1.5 rounded-lg text-xs font-bold shadow-sm transition flex items-center gap-1">
                    <span>+ ثبت برنامه</span>
                </button>
            </div>
        </div>

        <!-- جدول تقویم (هدر روزها + گرید ساعتی) -->
        <div class="flex-1 flex flex-col overflow-hidden bg-white">
            <!-- ردیف هدر روزهای هفته -->
            <div class="flex border-b border-slate-200 bg-slate-50 shrink-0 pr-14" id="calendar-days-header">
                <!-- با جاوااسکریپت ساخته می‌شود -->
            </div>

            <!-- ناحیه اسکرول ساعتی (۰۰:۰۰ تا ۲۳:۵۹) -->
            <div id="calendar-scroll-area" class="flex-1 overflow-y-auto calendar-scroll relative flex">
                <!-- ستون ساعت‌ها در سمت راست -->
                <div class="w-14 shrink-0 border-l border-slate-200 bg-slate-50 select-none text-[11px] text-slate-400 font-medium text-center" id="time-labels-col">
                    <!-- ساعت‌ها با جاوااسکریپت رندر می‌شوند -->
                </div>

                <!-- ستون‌های روزها -->
                <div class="flex-1 flex relative min-w-[650px] md:min-w-0" id="calendar-grid-cols" style="height: 1440px;">
                    <!-- ستون‌های ساعتی هر روز و رویدادها اینجا رندر می‌شوند -->
                </div>
            </div>
        </div>
    </main>

<!-- ========================================== -->
    <!-- ۳. بخش دوم: جزوه‌خوان ریسپانسیو PDF -->
    <!-- ========================================== -->
    <section id="section-pdf" class="hidden flex-1 flex overflow-hidden relative bg-slate-200">
        
        <!-- سایدبار لیست جزوات (در موبایل به صورت کشویی و در دسکتاپ ثابت) -->
        <aside id="pdf-sidebar" class="w-72 bg-white border-l border-slate-200 flex flex-col shrink-0 z-30 fixed md:static inset-y-0 right-0 transform translate-x-0 transition-transform duration-200 shadow-xl md:shadow-none">
            <div class="p-3.5 border-b border-slate-200 flex items-center justify-between bg-slate-50">
                <h3 class="font-extrabold text-sm text-slate-800 flex items-center gap-1.5">
                    <span>📚 کتابخانه جزوات من</span>
                </h3>
                <div class="flex items-center gap-1">
                    <button onclick="openUploadModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-2.5 py-1.5 rounded-lg text-xs font-bold transition">
                        + آپلود جزوه
                    </button>
                    <button onclick="togglePdfSidebar()" class="md:hidden text-slate-500 hover:text-slate-800 px-2 py-1 text-lg">&times;</button>
                </div>
            </div>

            <!-- لیست جزوات کاربر -->
            <div id="pdf-list-container" class="flex-1 overflow-y-auto p-3 space-y-2.5 calendar-scroll">
                <!-- با جاوااسکریپت پر می‌شود -->
            </div>
        </aside>

        <!-- محیط اصلی مطالعه PDF -->
        <div class="flex-1 flex flex-col overflow-hidden">
            
            <!-- نوار ابزار بالای PDF (کنترل صفحه، زوم، حالت شب و سایدبار) -->
            <div class="bg-white border-b border-slate-200 px-3 py-2 flex flex-wrap items-center justify-between gap-2 shrink-0 z-20 shadow-xs">
                
                <!-- دکمه منوی جزوات و عنوان جزوه فعال -->
                <div class="flex items-center gap-2">
                    <button onclick="togglePdfSidebar()" class="p-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-xs font-bold text-slate-700 transition" title="نمایش/مخفی‌سازی لیست جزوات">
                        ☰ جزوات
                    </button>
                    <span id="active-pdf-title" class="text-xs md:text-sm font-extrabold text-slate-800 truncate max-w-[160px] sm:max-w-xs">
                        جزوه‌ای انتخاب نشده است
                    </span>
                </div>

                <!-- کنترل‌های ورق زدن صفحه -->
                <div id="pdf-controls-group" class="hidden items-center gap-1.5 bg-slate-100 px-2 py-1 rounded-xl border border-slate-200">
                    <button onclick="changePdfPage(-1)" class="px-2 py-1 bg-white hover:bg-indigo-50 text-slate-700 rounded-lg text-xs font-bold shadow-2xs transition">
                        قبلی
                    </button>
                    <div class="flex items-center gap-1 text-xs font-bold px-1">
                        <span>صفحه</span>
                        <input type="number" id="pdf-page-input" onchange="jumpToInputPage(this.value)" min="1" value="1" class="w-12 text-center border border-slate-300 rounded-md py-0.5 bg-white text-xs">
                        <span>از</span>
                        <span id="pdf-total-pages">1</span>
                    </div>
                    <button onclick="changePdfPage(1)" class="px-2 py-1 bg-white hover:bg-indigo-50 text-slate-700 rounded-lg text-xs font-bold shadow-2xs transition">
                        بعدی
                    </button>
                </div>

                <!-- کنترل‌های بزرگنمایی و حالت مطالعه شب -->
                <div id="pdf-zoom-group" class="hidden items-center gap-1.5">
                    <button onclick="adjustZoom(-0.2)" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 rounded-lg text-xs font-bold" title="کوچک‌نمایی">➖</button>
                    <button onclick="setFitWidthZoom()" id="btn-fit-width" class="px-2.5 py-1 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 rounded-lg text-xs font-bold" title="تطبیق کامل با عرض صفحه">
                        ↔ عرض صفحه
                    </button>
                    <button onclick="adjustZoom(0.2)" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 rounded-lg text-xs font-bold" title="بزرگ‌نمایی">➕</button>
                    
                    <button onclick="togglePdfDarkMode()" id="btn-pdf-dark" class="px-2.5 py-1 bg-slate-800 text-amber-300 hover:bg-slate-700 rounded-lg text-xs font-bold transition" title="حالت مطالعه شب">
                        🌙 شب
                    </button>
                </div>
            </div>

<!-- نوار ابزار علامت‌گذاری روی PDF (ماژیک، قلم، یادداشت، پاک‌کن) -->
            <div id="annotation-toolbar" class="hidden bg-slate-800 text-white px-3 py-2 flex-wrap items-center justify-between gap-2 shrink-0 z-20 shadow-inner text-xs">
                
                <!-- ابزارهای ترسیم -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <button onclick="setAnnotTool('pan')" id="tool-pan" class="annot-tool-btn px-2.5 py-1.5 rounded-lg font-bold bg-indigo-600 text-white transition flex items-center gap-1" title="حالت مطالعه و اسکرول عادی">
                        <span>✋ مطالعه / اسکرول</span>
                    </button>
                    <button onclick="setAnnotTool('highlight')" id="tool-highlight" class="annot-tool-btn px-2.5 py-1.5 rounded-lg font-bold bg-slate-700 hover:bg-slate-600 text-slate-200 transition flex items-center gap-1" title="ماژیک هایلایت روی متن">
                        <span>🖍️ هایلایت</span>
                    </button>
                    <button onclick="setAnnotTool('pen')" id="tool-pen" class="annot-tool-btn px-2.5 py-1.5 rounded-lg font-bold bg-slate-700 hover:bg-slate-600 text-slate-200 transition flex items-center gap-1" title="قلم آزاد برای نوشتن">
                        <span>✏️ قلم نوری/آزاد</span>
                    </button>
                    <button onclick="setAnnotTool('note')" id="tool-note" class="annot-tool-btn px-2.5 py-1.5 rounded-lg font-bold bg-slate-700 hover:bg-slate-600 text-slate-200 transition flex items-center gap-1" title="ثبت نکته متنی روی صفحه">
                        <span>📌 یادداشت</span>
                    </button>
                    <button onclick="setAnnotTool('eraser')" id="tool-eraser" class="annot-tool-btn px-2.5 py-1.5 rounded-lg font-bold bg-slate-700 hover:bg-slate-600 text-slate-200 transition flex items-center gap-1" title="حذف یک علامت با کلیک روی آن">
                        <span>🧽 پاک‌کن</span>
                    </button>
                </div>

                <!-- انتخاب رنگ و عملیات واگرد/ذخیره -->
                <div class="flex items-center gap-3">
                    <!-- پالت رنگ -->
                    <div class="flex items-center gap-1.5 bg-slate-900/60 px-2.5 py-1 rounded-lg">
                        <button onclick="setAnnotColor('#facc15')" data-color="#facc15" class="annot-color-btn w-5 h-5 rounded-full bg-yellow-400 ring-2 ring-white scale-110 transition" title="زرد"></button>
                        <button onclick="setAnnotColor('#4ade80')" data-color="#4ade80" class="annot-color-btn w-5 h-5 rounded-full bg-green-400 opacity-70 hover:opacity-100 transition" title="سبز"></button>
                        <button onclick="setAnnotColor('#38bdf8')" data-color="#38bdf8" class="annot-color-btn w-5 h-5 rounded-full bg-sky-400 opacity-70 hover:opacity-100 transition" title="آبی"></button>
                        <button onclick="setAnnotColor('#fb7185')" data-color="#fb7185" class="annot-color-btn w-5 h-5 rounded-full bg-rose-400 opacity-70 hover:opacity-100 transition" title="قرمز/صورتی"></button>
                    </div>

                    <!-- دکمه‌های واگرد (Undo) و پاکسازی کل صفحه -->
                    <div class="flex items-center gap-1">
                        <button onclick="undoLastAnnotation()" class="px-2.5 py-1.5 bg-slate-700 hover:bg-slate-600 rounded-lg font-bold text-slate-200 transition" title="بازگشت به عقب (Undo)">
                            ↩ واگرد
                        </button>
                        <button onclick="clearAllPageAnnotations()" class="px-2.5 py-1.5 bg-rose-600/30 hover:bg-rose-600 text-rose-200 hover:text-white rounded-lg font-bold transition" title="پاک کردن تمام علامت‌های این صفحه">
                            🗑️ پاکسازی صفحه
                        </button>
                    </div>

                    <!-- وضعیت ذخیره خودکار -->
                    <span id="annot-save-status" class="text-[11px] font-bold text-emerald-400 min-w-[75px] text-left">
                        ✓ ذخیره شد
                    </span>
                </div>
            </div>

            <!-- ناحیه اسکرول و رندر بوم PDF -->
            <div id="pdf-viewport-container" class="flex-1 overflow-auto p-2 sm:p-4 flex justify-center items-start calendar-scroll relative">
                
                <!-- پیام پیش‌فرض قبل از باز کردن جزوه -->
                <div id="pdf-empty-state" class="my-auto text-center bg-white p-8 rounded-2xl shadow-sm max-w-sm border border-slate-200">
                    <div class="text-4xl mb-2">📖</div>
                    <h4 class="font-bold text-slate-800 text-sm mb-1">جزوه‌ای برای نمایش باز نیست</h4>
                    <p class="text-slate-500 text-xs mb-4">یک جزوه از ستون سمت راست انتخاب کنید یا فایل PDF جدیدی آپلود نمایید.</p>
                    <button onclick="openUploadModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-xs font-bold">
                        + آپلود اولین جزوه
                    </button>
                </div>

                <!-- ظرف نگهدارنده صفحه PDF + لایه علامت‌گذاری (آماده برای فاز ۴) -->
                <div id="pdf-canvas-wrapper" class="hidden relative shadow-2xl rounded-xs bg-white transition-all">
                    <canvas id="pdf-render-canvas" class="block"></canvas>
                    <!-- لایه دوم مخصوص علامت‌گذاری و هایلایت که در فاز ۴ فعال می‌شود -->
                    <canvas id="pdf-annot-canvas" class="absolute inset-0 pointer-events-none"></canvas>
                </div>
            </div>
        </div>
    </section>

    <!-- مودال آپلود جزوه PDF جدید -->
    <div id="upload-pdf-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
            <div class="bg-slate-800 text-white px-5 py-3.5 flex items-center justify-between">
                <h3 class="font-bold text-sm">آپلود جزوه PDF جدید</h3>
                <button onclick="closeUploadModal()" class="text-slate-400 hover:text-white text-lg">&times;</button>
            </div>
            <form id="upload-pdf-form" onsubmit="handlePdfUpload(event)" class="p-5 space-y-4 text-xs md:text-sm">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">عنوان جزوه *</label>
                    <input type="text" name="title" required placeholder="مثلاً: جزوه جمع‌بندی زیست دهم" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">دسته‌بندی / نام درس</label>
                    <select name="category" class="w-full px-3 py-2 border border-slate-300 rounded-lg bg-white">
                        <option value="تخصصی">تخصصی</option>
                        <option value="عمومی">عمومی</option>
                        <option value="خلاصه‌نویسی و مرور">خلاصه‌نویسی و مرور</option>
                        <option value="آزمون و تست">آزمون و تست</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">انتخاب فایل PDF *</label>
                    <input type="file" name="pdf_file" accept="application/pdf" required class="w-full text-xs border border-slate-300 rounded-lg p-2 bg-slate-50">
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t border-slate-200">
                    <button type="button" onclick="closeUploadModal()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-bold text-xs">
                        انصراف
                    </button>
                    <button type="submit" id="btn-submit-upload" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-bold text-xs shadow">
                        آپلود و ذخیره
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- ۴. مودال ثبت و ویرایش برنامه در تقویم -->
    <!-- ========================================== -->
    <div id="event-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
            <div class="bg-slate-800 text-white px-5 py-3.5 flex items-center justify-between">
                <h3 id="modal-title" class="font-bold text-sm">ثبت برنامه مطالعاتی</h3>
                <button onclick="closeEventModal()" class="text-slate-400 hover:text-white text-lg">&times;</button>
            </div>

            <form id="event-form" onsubmit="saveEvent(event)" class="p-5 space-y-3.5 text-xs md:text-sm">
                <input type="hidden" id="ev-id" name="id">

                <div>
                    <label class="block font-bold text-slate-700 mb-1">عنوان برنامه / مبحث درسی *</label>
                    <input type="text" id="ev-title" name="title" required placeholder="مثلاً: مطالعه فصل ۲ زیست + ۴۰ تست" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <div class="col-span-1">
                        <label class="block font-bold text-slate-700 mb-1">تاریخ *</label>
                        <select id="ev-date" name="g_date" required class="w-full px-2 py-2 border border-slate-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-xs"></select>
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">از ساعت *</label>
                        <input type="time" id="ev-start" name="start_time" required class="w-full px-2 py-2 border border-slate-300 rounded-lg text-center dir-ltr">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">تا ساعت *</label>
                        <input type="time" id="ev-end" name="end_time" required class="w-full px-2 py-2 border border-slate-300 rounded-lg text-center dir-ltr">
                    </div>
                </div>

                <!-- انتخاب رنگ بر اساس نوع برنامه -->
                <div>
                    <label class="block font-bold text-slate-700 mb-1">رنگ و نوع برنامه</label>
                    <div class="flex items-center gap-2">
                        <label class="flex items-center gap-1 cursor-pointer bg-slate-50 px-2.5 py-1.5 rounded-lg border">
                            <input type="radio" name="color" value="#4f46e5" checked>
                            <span class="w-3 h-3 rounded-full bg-indigo-600 inline-block"></span>
                            <span class="text-xs">تخصصی</span>
                        </label>
                        <label class="flex items-center gap-1 cursor-pointer bg-slate-50 px-2.5 py-1.5 rounded-lg border">
                            <input type="radio" name="color" value="#059669">
                            <span class="w-3 h-3 rounded-full bg-emerald-600 inline-block"></span>
                            <span class="text-xs">مرور</span>
                        </label>
                        <label class="flex items-center gap-1 cursor-pointer bg-slate-50 px-2.5 py-1.5 rounded-lg border">
                            <input type="radio" name="color" value="#d97706">
                            <span class="w-3 h-3 rounded-full bg-amber-600 inline-block"></span>
                            <span class="text-xs">تست‌زنی</span>
                        </label>
                        <label class="flex items-center gap-1 cursor-pointer bg-slate-50 px-2.5 py-1.5 rounded-lg border">
                            <input type="radio" name="color" value="#e11d48">
                            <span class="w-3 h-3 rounded-full bg-rose-600 inline-block"></span>
                            <span class="text-xs">آزمون</span>
                        </label>
                    </div>
                </div>

                <!-- اتصال مستقیم به جزوه PDF و صفحه -->
                <div class="bg-indigo-50/60 p-3 rounded-xl border border-indigo-100 space-y-2">
                    <label class="block font-bold text-indigo-900 text-xs">📎 اتصال به جزوه PDF (اختیاری)</label>
                    <div class="grid grid-cols-3 gap-2">
                        <div class="col-span-2">
                            <select id="ev-pdf-id" name="pdf_id" class="w-full px-2 py-1.5 border border-slate-300 rounded-lg bg-white text-xs">
                                <option value="">-- بدون جزوه --</option>
                                <?php foreach ($user_pdfs as $pdf): ?>
                                    <option value="<?php echo $pdf['id']; ?>">
                                        <?php echo htmlspecialchars($pdf['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <input type="number" id="ev-pdf-page" name="pdf_page" min="1" value="1" placeholder="صفحه" class="w-full px-2 py-1.5 border border-slate-300 rounded-lg text-center text-xs" title="شماره صفحه شروع">
                        </div>
                    </div>
                    <!-- دکمه پرش مستقیم به جزوه (هنگام مشاهده رویداد دارای PDF فعال می‌شود) -->
                    <button type="button" id="btn-open-linked-pdf" class="hidden w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-1.5 rounded-lg text-xs transition">
                        📖 باز کردن مستقیم این جزوه در صفحه مشخص‌شده
                    </button>
                </div>

                <div>
                    <label class="block font-bold text-slate-700 mb-1">توضیحات / جزئیات تست‌ها</label>
                    <textarea id="ev-desc" name="description" rows="2" placeholder="نکات مهم یا شماره تست‌ها..." class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                </div>

                <div class="flex items-center justify-between pt-2 border-t border-slate-200">
                    <div class="flex items-center gap-2">
                        <button type="button" id="btn-delete-event" onclick="deleteCurrentEvent()" class="hidden px-3 py-2 bg-rose-50 text-rose-600 hover:bg-rose-100 rounded-lg font-bold text-xs">
                            حذف
                        </button>
                        <button type="button" id="btn-toggle-complete" onclick="toggleCurrentEventComplete()" class="hidden px-3 py-2 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 rounded-lg font-bold text-xs">
                            ✓ تغییر وضعیت انجام
                        </button>
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="button" onclick="closeEventModal()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg font-bold text-xs">
                            انصراف
                        </button>
                        <button type="submit" id="btn-save-event" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-bold text-xs shadow">
                            ذخیره برنامه
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ==========================================
        // متغیرهای پایه از سمت سرور (PHP)
        // ==========================================
        const TODAY_GREGORIAN  = "<?php echo date('Y-m-d'); ?>";
        const KONKUR_GREGORIAN = "<?php echo KONKUR_GREGORIAN_DATE; ?>";
        const KONKUR_JALALI    = "<?php echo KONKUR_JALALI_DATE; ?>";

        let viewMode = 'week'; // 'week' یا 'day'
        let anchorDate = new Date(TODAY_GREGORIAN + 'T00:00:00');
        let loadedEvents = [];
        let currentVisibleDays = [];

        const JALALI_MONTHS = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
        const WEEKDAY_NAMES = ['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنج‌شنبه','جمعه'];

        // تبدیل تاریخ میلادی به شمسی در جاوااسکریپت (کاملاً منطبق با تابع PHP)
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
            const y = dateObj.getFullYear();
            const m = String(dateObj.getMonth() + 1).padStart(2, '0');
            const d = String(dateObj.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        }

        function getJalaliInfo(dateObj) {
            const [jy, jm, jd] = gregorianToJalali(dateObj.getFullYear(), dateObj.getMonth() + 1, dateObj.getDate());
            // تبدیل اندیس روز هفته میلادی به ایرانی (شنبه = 0 ... جمعه = 6)
            const iranianDayIdx = (dateObj.getDay() + 1) % 7;
            return {
                jy, jm, jd,
                jString: `${jy}-${String(jm).padStart(2,'0')}-${String(jd).padStart(2,'0')}`,
                dayName: WEEKDAY_NAMES[iranianDayIdx],
                monthName: JALALI_MONTHS[jm - 1]
            };
        }

        // محاسبه روزهای قابل نمایش بر اساس حالت هفتگی یا روزانه
        function computeVisibleDays() {
            const days = [];
            if (viewMode === 'day') {
                days.push(new Date(anchorDate));
            } else {
                // پیدا کردن شنبه همین هفته
                const d = new Date(anchorDate);
                const diffToSaturday = (d.getDay() + 1) % 7;
                d.setDate(d.getDate() - diffToSaturday);
                for (let i = 0; i < 7; i++) {
                    const clone = new Date(d);
                    clone.setDate(d.getDate() + i);
                    days.push(clone);
                }
            }
            currentVisibleDays = days;
            return days;
        }

        // ساخت برچسب‌های ساعت در ستون سمت راست (۰۰:۰۰ تا ۲۳:۰۰)
        function initTimeLabels() {
            const col = document.getElementById('time-labels-col');
            let html = '';
            for (let h = 0; h < 24; h++) {
                const label = String(h).padStart(2, '0') + ':00';
                html += `<div class="hour-row border-b border-slate-100 flex items-start justify-center pt-1">${label}</div>`;
            }
            col.innerHTML = html;
        }

        // رندر کامل ساختار تقویم و دریافت رویدادها از سرور
        async function renderCalendar() {
            const days = computeVisibleDays();
            const headerEl = document.getElementById('calendar-days-header');
            const gridEl = document.getElementById('calendar-grid-cols');

            // به‌روزرسانی عنوان بازه تاریخ در نوار ابزار
            const firstJ = getJalaliInfo(days[0]);
            const lastJ  = getJalaliInfo(days[days.length - 1]);
            if (viewMode === 'day') {
                document.getElementById('calendar-range-title').textContent =
                    `${firstJ.dayName} ${firstJ.jd} ${firstJ.monthName} ${firstJ.jy}`;
            } else {
                document.getElementById('calendar-range-title').textContent =
                    `${firstJ.jd} ${firstJ.monthName} تا ${lastJ.jd} ${lastJ.monthName} ${lastJ.jy}`;
            }

            // ساخت هدر روزها و ستون‌های ساعتی
            let headerHtml = '';
            let gridHtml = '';
            const selectDateEl = document.getElementById('ev-date');
            selectDateEl.innerHTML = '';

            days.forEach((dObj) => {
                const gStr = formatGDate(dObj);
                const jInfo = getJalaliInfo(dObj);
                const isToday = (gStr === TODAY_GREGORIAN);
                const isKonkurDay = (gStr === KONKUR_GREGORIAN);
                const isLocked = (gStr > KONKUR_GREGORIAN);

                // پر کردن لیست تاریخ‌های مجاز داخل مودال
                if (!isLocked) {
                    const opt = document.createElement('option');
                    opt.value = gStr;
                    opt.textContent = `${jInfo.dayName} ${jInfo.jd} ${jInfo.monthName}`;
                    selectDateEl.appendChild(opt);
                }

                // استایل هدر هر روز
                let badgeHtml = '';
                if (isKonkurDay) {
                    badgeHtml = `<span class="block text-[10px] bg-rose-600 text-white px-1.5 py-0.5 rounded-full mt-0.5">🎯 روز کنکور</span>`;
                } else if (isLocked) {
                    badgeHtml = `<span class="block text-[10px] text-slate-400 mt-0.5">🔒 پایان تقویم</span>`;
                }

                headerHtml += `
                    <div onclick="selectDayForDailyView('${gStr}')" class="flex-1 py-2 px-1 text-center border-l border-slate-200 cursor-pointer hover:bg-slate-100 transition ${isToday ? 'bg-indigo-50/80' : ''} ${isKonkurDay ? 'bg-rose-50' : ''}">
                        <div class="text-xs font-bold ${isToday ? 'text-indigo-600' : 'text-slate-600'}">${jInfo.dayName}</div>
                        <div class="inline-flex items-center justify-center w-7 h-7 rounded-full text-sm font-extrabold mt-0.5 ${isToday ? 'bg-indigo-600 text-white' : 'text-slate-800'}">
                            ${jInfo.jd}
                        </div>
                        ${badgeHtml}
                    </div>
                `;

                // ساخت ۲۴ ردیف ساعتی برای هر ستون روز
                let hoursCells = '';
                for (let h = 0; h < 24; h++) {
                    const startH = String(h).padStart(2, '0') + ':00';
                    const endH   = String(Math.min(23, h + 1)).padStart(2, '0') + (h === 23 ? ':59' : ':00');
                    const clickHandler = isLocked ? '' : `onclick="openEventModal('${gStr}', '${startH}', '${endH}')"`;
                    hoursCells += `<div ${clickHandler} class="hour-row border-b border-slate-100 ${isLocked ? '' : 'hover:bg-indigo-50/40 cursor-pointer'} transition"></div>`;
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

            // دریافت رویدادها از سرور
            const startG = formatGDate(days[0]);
            const endG   = formatGDate(days[days.length - 1]);
            const res = await fetch(`api_calendar.php?action=list&start=${startG}&end=${endG}`);
            const data = await res.json();

            if (data.status === 'success') {
                loadedEvents = data.events;
                drawEventsOnGrid();
                updateProgressBar(data.stats);
            }

            drawCurrentTimeIndicator();
        }

        // ترسیم باکس‌های رنگی هر برنامه روی ساعت دقیق خودش
        function drawEventsOnGrid() {
            loadedEvents.forEach(ev => {
                const dayCol = document.querySelector(`[data-gdate="${ev.g_date}"] .events-layer`);
                if (!dayCol) return;

                const [sh, sm] = ev.start_time.split(':').map(Number);
                const [eh, em] = ev.end_time.split(':').map(Number);
                const startMinutes = (sh * 60) + sm;
                const endMinutes   = (eh * 60) + em;
                const duration     = Math.max(25, endMinutes - startMinutes);

                const card = document.createElement('div');
                card.className = `pointer-events-auto absolute right-1 left-1 rounded-lg p-1.5 text-white shadow-md cursor-pointer overflow-hidden transition hover:brightness-95 flex flex-col justify-between border border-white/20`;
                card.style.top = `${startMinutes}px`;
                card.style.height = `${duration}px`;
                card.style.backgroundColor = ev.color || '#4f46e5';
                if (Number(ev.is_completed) === 1) {
                    card.style.opacity = '0.65';
                }

                card.onclick = (e) => {
                    e.stopPropagation();
                    openEventModalForEdit(ev);
                };

                const timeRangeStr = `${ev.start_time.slice(0,5)} - ${ev.end_time.slice(0,5)}`;
                const checkBadge = Number(ev.is_completed) === 1 ? '✅ ' : '';
                const pdfBadge = ev.pdf_id ? `<span class="bg-black/25 px-1.5 py-0.5 rounded text-[10px] inline-block mt-0.5">📖 ص ${ev.pdf_page}</span>` : '';

                card.innerHTML = `
                    <div>
                        <div class="text-[11px] font-extrabold leading-tight ${Number(ev.is_completed) === 1 ? 'line-through' : ''}">
                            ${checkBadge}${escapeHtml(ev.title)}
                        </div>
                        <div class="text-[10px] opacity-90 dir-ltr text-right">${timeRangeStr}</div>
                    </div>
                    ${pdfBadge}
                `;

                dayCol.appendChild(card);
            });
        }

        // رسم خط قرمز ساعت فعلی روی ستون امروز (مشابه گوگل کلندر)
        function drawCurrentTimeIndicator() {
            const todayCol = document.querySelector(`[data-gdate="${TODAY_GREGORIAN}"] .events-layer`);
            if (!todayCol) return;

            const now = new Date();
            const minutesNow = (now.getHours() * 60) + now.getMinutes();

            const line = document.createElement('div');
            line.className = 'absolute right-0 left-0 border-t-2 border-rose-500 z-10 pointer-events-none flex items-center';
            line.style.top = `${minutesNow}px`;
            line.innerHTML = `<span class="w-2.5 h-2.5 rounded-full bg-rose-500 -mr-1.5"></span>`;
            todayCol.appendChild(line);
        }

        // به‌روزرسانی درصد پیشرفت کل برنامه‌ها در نوار بالا
        function updateProgressBar(stats) {
            const pct = stats.total > 0 ? Math.round((stats.completed / stats.total) * 100) : 0;
            document.getElementById('stat-progress-text').textContent = `${pct}% (${stats.completed} از ${stats.total})`;
        }

        // جابجایی بین هفته/روز قبل و بعد با رعایت سقف ۱۶ بهمن
        function navigateCalendar(dir) {
            const step = (viewMode === 'week') ? 7 : 1;
            const nextDate = new Date(anchorDate);
            nextDate.setDate(nextDate.getDate() + (dir * step));

            // اگر کاربر می‌خواهد به بعد از هفته‌ی کنکور برود، جلوگیری شود
            if (dir > 0 && formatGDate(nextDate) > KONKUR_GREGORIAN && formatGDate(anchorDate) >= KONKUR_GREGORIAN) {
                alert('تقویم مطالعاتی در تاریخ ۱۶ بهمن (روز کنکور) به پایان می‌رسد.');
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
            const btnWeek = document.getElementById('btn-view-week');
            const btnDay  = document.getElementById('btn-view-day');
            if (mode === 'week') {
                btnWeek.className = 'px-3 py-1 rounded-md text-xs font-bold bg-white shadow-sm text-indigo-600';
                btnDay.className  = 'px-3 py-1 rounded-md text-xs font-bold text-slate-600';
            } else {
                btnDay.className  = 'px-3 py-1 rounded-md text-xs font-bold bg-white shadow-sm text-indigo-600';
                btnWeek.className = 'px-3 py-1 rounded-md text-xs font-bold text-slate-600';
            }
            renderCalendar();
        }

        function selectDayForDailyView(gDateStr) {
            anchorDate = new Date(gDateStr + 'T00:00:00');
            setViewMode('day');
        }

        // ==========================================
        // مدیریت مودال ثبت و ویرایش رویداد
        // ==========================================
        function openEventModal(gDate = null, startTime = '08:00', endTime = '09:30') {
            document.getElementById('event-form').reset();
            document.getElementById('ev-id').value = '';
            document.getElementById('modal-title').textContent = 'ثبت برنامه جدید در تقویم';

            const targetDate = gDate || formatGDate(anchorDate);
            if (targetDate > KONKUR_GREGORIAN) {
                alert('امکان ثبت برنامه پس از تاریخ ۱۶ بهمن وجود ندارد.');
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

            const colorRadio = document.querySelector(`input[name="color"][value="${ev.color}"]`);
            if (colorRadio) colorRadio.checked = true;

            document.getElementById('modal-title').textContent = 'ویرایش برنامه مطالعاتی';
            document.getElementById('btn-delete-event').classList.remove('hidden');
            document.getElementById('btn-toggle-complete').classList.remove('hidden');

            // اگر این برنامه به جزوه PDF متصل است، دکمه باز کردن مستقیم جزوه نمایش داده شود
            const btnOpenPdf = document.getElementById('btn-open-linked-pdf');
            if (ev.pdf_id) {
                btnOpenPdf.classList.remove('hidden');
                btnOpenPdf.onclick = () => openPdfFromCalendar(ev.pdf_id, ev.pdf_page, ev.pdf_title);
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

        // سوییچ بین تب تقویم و تب جزوه‌خوان
        function switchMainSection(sec) {
            const calSec = document.getElementById('section-calendar');
            const pdfSec = document.getElementById('section-pdf');
            const navCal = document.getElementById('nav-calendar');
            const navPdf = document.getElementById('nav-pdf');

            if (sec === 'calendar') {
                calSec.classList.remove('hidden');
                pdfSec.classList.add('hidden');
                navCal.className = 'px-3 py-1.5 rounded-lg text-xs md:text-sm font-bold bg-indigo-600 text-white transition';
                navPdf.className = 'px-3 py-1.5 rounded-lg text-xs md:text-sm font-bold text-slate-300 hover:text-white transition';
            } else {
                calSec.classList.add('hidden');
                pdfSec.classList.remove('hidden');
                navPdf.className = 'px-3 py-1.5 rounded-lg text-xs md:text-sm font-bold bg-indigo-600 text-white transition';
                navCal.className = 'px-3 py-1.5 rounded-lg text-xs md:text-sm font-bold text-slate-300 hover:text-white transition';
            }
        }

// ==========================================
        // موتور مدیریت جزوات و رندرینگ ریسپانسیو PDF.js
        // ==========================================
        let pdfList = [];
        let activePdfMeta = null;   // اطلاعات رکورد دیتابیس جزوه فعال
        let pdfDocInstance = null;  // شیء بارگذاری‌شده توسط PDF.js
        let currentPageNum = 1;
        let currentZoomMode = 'fit-width'; // 'fit-width' یا عدد مقیاس مثل 1.2
        let customScale = 1.0;
        let isRenderingPage = false;
        let pendingPageNum = null;
        let isPdfDarkMode = false;

        // دریافت لیست جزوات از سرور و به‌روزرسانی همزمان سایدبار و منوی تقویم
        async function fetchUserPdfs() {
            const res = await fetch('api_pdf.php?action=list');
            const data = await res.json();
            if (data.status === 'success') {
                pdfList = data.pdfs;
                renderPdfSidebarList();
                syncCalendarPdfDropdown();
            }
        }

        function renderPdfSidebarList() {
            const container = document.getElementById('pdf-list-container');
            if (pdfList.length === 0) {
                container.innerHTML = `<div class="text-center text-slate-400 text-xs py-8">هنوز جزوه‌ای آپلود نکرده‌اید.</div>`;
                return;
            }

            let html = '';
            pdfList.forEach(item => {
                const isActive = activePdfMeta && Number(activePdfMeta.id) === Number(item.id);
                const total = Math.max(1, Number(item.total_pages));
                const last  = Math.min(total, Math.max(1, Number(item.last_page)));
                const pct   = Math.round((last / total) * 100);

                html += `
                    <div onclick="loadAndOpenPdf(${item.id}, ${last})" class="p-3 rounded-xl border transition cursor-pointer ${isActive ? 'border-indigo-600 bg-indigo-50/70 shadow-xs' : 'border-slate-200 hover:border-indigo-300 bg-white'}">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <span class="inline-block text-[10px] font-bold px-2 py-0.5 rounded-md bg-slate-100 text-slate-600 mb-1">${escapeHtml(item.category)}</span>
                                <h4 class="text-xs font-extrabold text-slate-800 leading-snug">${escapeHtml(item.title)}</h4>
                            </div>
                            <button onclick="deletePdfItem(event, ${item.id})" class="text-slate-400 hover:text-rose-600 text-xs px-1" title="حذف جزوه">🗑️</button>
                        </div>
                        <div class="mt-2.5">
                            <div class="flex justify-between text-[10px] text-slate-500 mb-1">
                                <span>آخرین مطالعه: ص ${last} از ${total}</span>
                                <span class="font-bold text-indigo-600">${pct}%</span>
                            </div>
                            <div class="w-full h-1.5 bg-slate-200 rounded-full overflow-hidden">
                                <div class="h-full bg-indigo-600 rounded-full" style="width: ${pct}%"></div>
                            </div>
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
        }

        // هماهنگ‌سازی لیست کشویی انتخاب جزوه داخل مودال تقویم
        function syncCalendarPdfDropdown() {
            const select = document.getElementById('ev-pdf-id');
            if (!select) return;
            const currentVal = select.value;
            let html = '<option value="">-- بدون جزوه --</option>';
            pdfList.forEach(p => {
                html += `<option value="${p.id}">${escapeHtml(p.title)}</option>`;
            });
            select.innerHTML = html;
            select.value = currentVal;
        }

        // بارگذاری و باز کردن یک جزوه در صفحه مشخص
        async function loadAndOpenPdf(pdfId, targetPage = null) {
            const item = pdfList.find(p => Number(p.id) === Number(pdfId));
            if (!item) {
                await fetchUserPdfs();
            }
            activePdfMeta = pdfList.find(p => Number(p.id) === Number(pdfId));
            if (!activePdfMeta) {
                alert('جزوه مورد نظر یافت نشد.');
                return;
            }

            // در موبایل پس از انتخاب جزوه، سایدبار بسته شود تا تمام صفحه به جزوه اختصاص یابد
            if (window.innerWidth < 768) {
                document.getElementById('pdf-sidebar').classList.add('translate-x-full');
            }

            document.getElementById('active-pdf-title').textContent = activePdfMeta.title;
            document.getElementById('pdf-empty-state').classList.add('hidden');
            document.getElementById('pdf-canvas-wrapper').classList.remove('hidden');
            document.getElementById('pdf-controls-group').classList.remove('hidden');
            document.getElementById('pdf-controls-group').classList.add('flex');
            document.getElementById('pdf-zoom-group').classList.remove('hidden');
            document.getElementById('pdf-zoom-group').classList.add('flex');
			document.getElementById('annotation-toolbar').classList.remove('hidden');
            document.getElementById('annotation-toolbar').classList.add('flex');

            try {
                const loadingTask = pdfjsLib.getDocument(activePdfMeta.file_path);
                pdfDocInstance = await loadingTask.promise;

                const totalPages = pdfDocInstance.numPages;
                activePdfMeta.total_pages = totalPages;
                document.getElementById('pdf-total-pages').textContent = totalPages;
                document.getElementById('pdf-page-input').max = totalPages;

                const startPage = targetPage ? Math.min(totalPages, Math.max(1, Number(targetPage))) : Math.min(totalPages, Math.max(1, Number(activePdfMeta.last_page)));
                currentPageNum = startPage;

                renderPdfSidebarList();
                await renderPdfPage(currentPageNum);
            } catch (err) {
                alert('خطا در باز کردن فایل PDF: ' + err.message);
            }
        }

        // رندر صفحه PDF با وضوح بالا (Retina/Mobile High-DPI) و کاملاً سازگار با عرض صفحه
        async function renderPdfPage(num) {
            if (!pdfDocInstance) return;
            if (isRenderingPage) {
                pendingPageNum = num;
                return;
            }
            isRenderingPage = true;

            const page = await pdfDocInstance.getPage(num);
            const container = document.getElementById('pdf-viewport-container');
            const canvas = document.getElementById('pdf-render-canvas');
            const annotCanvas = document.getElementById('pdf-annot-canvas');
            const ctx = canvas.getContext('2d');

            // محاسبه مقیاس متناسب با عرض صفحه نمایشگر کاربر
            const unscaledViewport = page.getViewport({ scale: 1 });
            let scale = customScale;
            if (currentZoomMode === 'fit-width') {
                const availableWidth = Math.max(280, container.clientWidth - 28);
                scale = availableWidth / unscaledViewport.width;
                // در دسکتاپ‌های خیلی عریض، بیش از حد بزرگ نشود
                scale = Math.min(scale, 1.8);
                customScale = scale;
            }

            const viewport = page.getViewport({ scale: scale });
            const outputScale = window.devicePixelRatio || 1;

            // تنظیم ابعاد واقعی بوم برای شفافیت کامل متن در موبایل و تبلت
            canvas.width  = Math.floor(viewport.width * outputScale);
            canvas.height = Math.floor(viewport.height * outputScale);
            canvas.style.width  = Math.floor(viewport.width) + "px";
            canvas.style.height = Math.floor(viewport.height) + "px";

            // هماهنگ‌سازی لایه علامت‌گذاری (برای فاز ۴)
            annotCanvas.width  = Math.floor(viewport.width);
            annotCanvas.height = Math.floor(viewport.height);
            annotCanvas.style.width  = Math.floor(viewport.width) + "px";
            annotCanvas.style.height = Math.floor(viewport.height) + "px";

            const transform = outputScale !== 1 ? [outputScale, 0, 0, outputScale, 0, 0] : null;

            await page.render({
                canvasContext: ctx,
                transform: transform,
                viewport: viewport
            }).promise;

            isRenderingPage = false;
            document.getElementById('pdf-page-input').value = num;

            // اگر تابع بارگذاری علامت‌گذاری‌های فاز ۴ تعریف شده باشد، اجرا شود
            if (typeof loadPageAnnotations === 'function') {
                loadPageAnnotations(activePdfMeta.id, num);
            }

            // ذخیره خودکار آخرین صفحه مطالعه‌شده در دیتابیس
            saveReadingProgress(activePdfMeta.id, num, pdfDocInstance.numPages);

            if (pendingPageNum !== null) {
                const next = pendingPageNum;
                pendingPageNum = null;
                renderPdfPage(next);
            }
        }

        function changePdfPage(delta) {
            if (!pdfDocInstance) return;
            const next = currentPageNum + delta;
            if (next >= 1 && next <= pdfDocInstance.numPages) {
                currentPageNum = next;
                renderPdfPage(currentPageNum);
            }
        }

        function jumpToInputPage(val) {
            if (!pdfDocInstance) return;
            const page = Math.min(pdfDocInstance.numPages, Math.max(1, parseInt(val) || 1));
            currentPageNum = page;
            renderPdfPage(currentPageNum);
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
                btn.className = 'px-2.5 py-1 bg-amber-400 text-slate-900 hover:bg-amber-300 rounded-lg text-xs font-bold transition';
            } else {
                canvas.classList.remove('pdf-dark-mode');
                btn.textContent = '🌙 شب';
                btn.className = 'px-2.5 py-1 bg-slate-800 text-amber-300 hover:bg-slate-700 rounded-lg text-xs font-bold transition';
            }
        }

        function togglePdfSidebar() {
            const sb = document.getElementById('pdf-sidebar');
            if (window.innerWidth < 768) {
                sb.classList.toggle('translate-x-full');
            } else {
                sb.classList.toggle('hidden');
                if (pdfDocInstance && currentZoomMode === 'fit-width') {
                    setTimeout(() => renderPdfPage(currentPageNum), 50);
                }
            }
        }

        async function saveReadingProgress(pdfId, lastPage, totalPages) {
            if (activePdfMeta) {
                activePdfMeta.last_page = lastPage;
                activePdfMeta.total_pages = totalPages;
                renderPdfSidebarList();
            }
            const fd = new FormData();
            fd.append('action', 'save_progress');
            fd.append('pdf_id', pdfId);
            fd.append('last_page', lastPage);
            fd.append('total_pages', totalPages);
            await fetch('api_pdf.php', { method: 'POST', body: fd });
        }

        // آپلود جزوه جدید
        function openUploadModal() {
            document.getElementById('upload-pdf-form').reset();
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

            const fd = new FormData(document.getElementById('upload-pdf-form'));
            fd.append('action', 'upload');

            const res = await fetch('api_pdf.php', { method: 'POST', body: fd });
            const data = await res.json();

            btn.disabled = false;
            btn.textContent = 'آپلود و ذخیره';

            if (data.status === 'success') {
                closeUploadModal();
                await fetchUserPdfs();
                loadAndOpenPdf(data.pdf.id, 1);
            } else {
                alert(data.message || 'خطا در آپلود فایل');
            }
        }

        async function deletePdfItem(e, pdfId) {
            e.stopPropagation();
            if (!confirm('آیا از حذف این جزوه و علامت‌گذاری‌های آن مطمئن هستید؟')) return;

            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('pdf_id', pdfId);

            await fetch('api_pdf.php', { method: 'POST', body: fd });
            if (activePdfMeta && Number(activePdfMeta.id) === Number(pdfId)) {
                activePdfMeta = null;
                pdfDocInstance = null;
                document.getElementById('pdf-canvas-wrapper').classList.add('hidden');
                document.getElementById('pdf-empty-state').classList.remove('hidden');
                document.getElementById('pdf-controls-group').classList.add('hidden');
                document.getElementById('pdf-zoom-group').classList.add('hidden');
                document.getElementById('active-pdf-title').textContent = 'جزوه‌ای انتخاب نشده است';
            }
            fetchUserPdfs();
        }

        // پرش مستقیم از برنامه تقویم به صفحه مشخص‌شده در جزوه PDF
        async function openPdfFromCalendar(pdfId, pageNumber) {
            closeEventModal();
            switchMainSection('pdf');
            if (pdfList.length === 0) {
                await fetchUserPdfs();
            }
            loadAndOpenPdf(pdfId, pageNumber);
        }

        // تطبیق خودکار عرض PDF هنگام چرخش صفحه موبایل یا تغییر سایز پنجره
        window.addEventListener('resize', () => {
            if (pdfDocInstance && currentZoomMode === 'fit-width' && !document.getElementById('section-pdf').classList.contains('hidden')) {
                renderPdfPage(currentPageNum);
            }
        });

        // بارگذاری اولیه لیست جزوات هنگام ورود به داشبورد
        window.addEventListener('DOMContentLoaded', () => {
            fetchUserPdfs();
        });

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
        }

        // راه‌اندازی اولیه تقویم و اسکرول خودکار روی ساعت ۰۷:۰۰ صبح
        window.addEventListener('DOMContentLoaded', () => {
            initTimeLabels();
            renderCalendar();
            document.getElementById('calendar-scroll-area').scrollTop = 7 * 60;
        });

		// ==========================================
        // موتور علامت‌گذاری روی PDF (هایلایت، قلم، یادداشت و پاک‌کن)
        // ==========================================
        let currentTool = 'pan'; // 'pan', 'highlight', 'pen', 'note', 'eraser'
        let currentColor = '#facc15';
        let pageAnnotations = []; // لیست علامت‌های صفحه فعلی
        let isDrawingAnnot = false;
        let currentStroke = null;
        let saveAnnotTimeout = null;

        const annotCanvas = document.getElementById('pdf-annot-canvas');
        const annotCtx = annotCanvas.getContext('2d');

        // تغییر ابزار فعال
        function setAnnotTool(tool) {
            currentTool = tool;
            document.querySelectorAll('.annot-tool-btn').forEach(btn => {
                btn.className = 'annot-tool-btn px-2.5 py-1.5 rounded-lg font-bold bg-slate-700 hover:bg-slate-600 text-slate-200 transition flex items-center gap-1';
            });
            const activeBtn = document.getElementById('tool-' + tool);
            if (activeBtn) {
                activeBtn.className = 'annot-tool-btn px-2.5 py-1.5 rounded-lg font-bold bg-indigo-600 text-white transition flex items-center gap-1 shadow-xs';
            }

            // اگر حالت مطالعه فعال باشد، لایه علامت‌گذاری اجازه اسکرول عادی صفحه را می‌دهد
            if (tool === 'pan') {
                annotCanvas.classList.add('pointer-events-none');
                annotCanvas.style.cursor = 'default';
                annotCanvas.style.touchAction = 'auto';
            } else {
                annotCanvas.classList.remove('pointer-events-none');
                annotCanvas.style.cursor = (tool === 'eraser' || tool === 'note') ? 'pointer' : 'crosshair';
                // جلوگیری از اسکرول ناخواسته صفحه هنگام کشیدن خط با انگشت یا قلم در موبایل/تبلت
                annotCanvas.style.touchAction = 'none';
            }
        }

        // تغییر رنگ ابزار
        function setAnnotColor(color) {
            currentColor = color;
            document.querySelectorAll('.annot-color-btn').forEach(btn => {
                if (btn.dataset.color === color) {
                    btn.className = `annot-color-btn w-5 h-5 rounded-full ring-2 ring-white scale-110 transition`;
                } else {
                    btn.className = `annot-color-btn w-5 h-5 rounded-full opacity-60 hover:opacity-100 transition`;
                }
                btn.style.backgroundColor = btn.dataset.color;
            });
        }

        // دریافت مختصات نسبی (بین 0 و 1) مستقل از زوم و اندازه صفحه
        function getNormalizedPointerPos(e) {
            const rect = annotCanvas.getBoundingClientRect();
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            const clientY = e.touches ? e.touches[0].clientY : e.clientY;
            return {
                x: Math.min(1, Math.max(0, (clientX - rect.left) / rect.width)),
                y: Math.min(1, Math.max(0, (clientY - rect.top) / rect.height))
            };
        }

        // بارگذاری علامت‌گذاری‌های صفحه از دیتابیس
        async function loadPageAnnotations(pdfId, pageNum) {
            const res = await fetch(`api_pdf.php?action=get_annotations&pdf_id=${pdfId}&page_number=${pageNum}`);
            const json = await res.json();
            if (json.status === 'success' && Array.isArray(json.data)) {
                pageAnnotations = json.data;
            } else {
                pageAnnotations = [];
            }
            redrawAllAnnotations();
        }

        // بازترسیم تمام علامت‌ها روی بوم صفحه فعلی
        function redrawAllAnnotations() {
            const w = annotCanvas.width;
            const h = annotCanvas.height;
            annotCtx.clearRect(0, 0, w, h);

            pageAnnotations.forEach(item => {
                drawSingleAnnotation(item, w, h);
            });

            if (currentStroke) {
                drawSingleAnnotation(currentStroke, w, h);
            }
        }

        function drawSingleAnnotation(item, w, h) {
            annotCtx.save();

            if (item.type === 'highlight' || item.type === 'pen') {
                if (!item.points || item.points.length === 0) {
                    annotCtx.restore();
                    return;
                }
                annotCtx.beginPath();
                annotCtx.lineCap = 'round';
                annotCtx.lineJoin = 'round';
                annotCtx.strokeStyle = item.color || '#facc15';

                if (item.type === 'highlight') {
                    annotCtx.globalAlpha = 0.38;
                    annotCtx.lineWidth = Math.max(14, w * 0.024); // ضخامت متناسب با عرض جزوه
                } else {
                    annotCtx.globalAlpha = 0.95;
                    annotCtx.lineWidth = Math.max(2, w * 0.0032);
                }

                const firstPt = item.points[0];
                annotCtx.moveTo(firstPt.x * w, firstPt.y * h);
                for (let i = 1; i < item.points.length; i++) {
                    annotCtx.lineTo(item.points[i].x * w, item.points[i].y * h);
                }
                annotCtx.stroke();
            } else if (item.type === 'note') {
                const nx = item.x * w;
                const ny = item.y * h;
                const boxSize = Math.max(22, w * 0.032);

                // رسم آیکون یادداشت چسبان روی صفحه
                annotCtx.fillStyle = item.color || '#facc15';
                annotCtx.strokeStyle = '#1e293b';
                annotCtx.lineWidth = 1.5;

                annotCtx.beginPath();
                annotCtx.roundRect(nx - boxSize / 2, ny - boxSize / 2, boxSize, boxSize, 6);
                annotCtx.fill();
                annotCtx.stroke();

                annotCtx.fillStyle = '#0f172a';
                annotCtx.font = `bold ${Math.round(boxSize * 0.55)}px Vazirmatn, sans-serif`;
                annotCtx.textAlign = 'center';
                annotCtx.textBaseline = 'middle';
                annotCtx.fillText('📌', nx, ny);
            }

            annotCtx.restore();
        }

        // شروع ترسیم یا کلیک ابزارها
        function handlePointerStart(e) {
            if (currentTool === 'pan' || !activePdfMeta) return;
            if (e.cancelable) e.preventDefault();

            const pos = getNormalizedPointerPos(e);

            // ۱. ابزار ثبت یا مشاهده یادداشت متنی
            if (currentTool === 'note') {
                // بررسی اینکه آیا روی یک یادداشت موجود کلیک شده است؟
                const existingIdx = findAnnotationAtPos(pos.x, pos.y, 'note');
                if (existingIdx !== -1) {
                    const noteObj = pageAnnotations[existingIdx];
                    const updatedText = prompt('متن یادداشت (برای حذف، متن را خالی بگذارید):', noteObj.text);
                    if (updatedText === null) return;
                    if (updatedText.trim() === '') {
                        pageAnnotations.splice(existingIdx, 1);
                    } else {
                        noteObj.text = updatedText.trim();
                    }
                    redrawAllAnnotations();
                    triggerAutoSaveAnnotations();
                    return;
                }

                // ثبت یادداشت جدید
                const text = prompt('متن نکته یا یادداشت خود را برای این بخش از صفحه وارد کنید:');
                if (text && text.trim() !== '') {
                    pageAnnotations.push({
                        type: 'note',
                        color: currentColor,
                        x: pos.x,
                        y: pos.y,
                        text: text.trim()
                    });
                    redrawAllAnnotations();
                    triggerAutoSaveAnnotations();
                }
                return;
            }

            // ۲. ابزار پاک‌کن (حذف علامت یا یادداشت با کلیک یا کشیدن روی آن)
            if (currentTool === 'eraser') {
                isDrawingAnnot = true;
                eraseAnnotationNear(pos.x, pos.y);
                return;
            }

            // ۳. ابزارهای ماژیک هایلایت و قلم آزاد
            if (currentTool === 'highlight' || currentTool === 'pen') {
                isDrawingAnnot = true;
                currentStroke = {
                    type: currentTool,
                    color: currentColor,
                    points: [pos]
                };
                redrawAllAnnotations();
            }
        }

        function handlePointerMove(e) {
            if (!isDrawingAnnot || currentTool === 'pan') return;
            if (e.cancelable) e.preventDefault();

            const pos = getNormalizedPointerPos(e);

            if (currentTool === 'eraser') {
                eraseAnnotationNear(pos.x, pos.y);
                return;
            }

            if (currentStroke) {
                currentStroke.points.push(pos);
                redrawAllAnnotations();
            }
        }

        function handlePointerEnd(e) {
            if (!isDrawingAnnot) return;
            isDrawingAnnot = false;

            if (currentStroke && currentStroke.points.length > 0) {
                pageAnnotations.push(currentStroke);
                currentStroke = null;
                redrawAllAnnotations();
                triggerAutoSaveAnnotations();
            }
        }

        // پیدا کردن یا حذف علامت در نزدیکی محل کلیک/پاک‌کن
        function findAnnotationAtPos(x, y, filterType = null) {
            const threshold = 0.035; // شعاع حساسیت نسبی
            for (let i = pageAnnotations.length - 1; i >= 0; i--) {
                const item = pageAnnotations[i];
                if (filterType && item.type !== filterType) continue;

                if (item.type === 'note') {
                    const dist = Math.hypot(item.x - x, item.y - y);
                    if (dist <= threshold) return i;
                } else if (item.points) {
                    for (const pt of item.points) {
                        if (Math.hypot(pt.x - x, pt.y - y) <= threshold) {
                            return i;
                        }
                    }
                }
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

        // بازگشت به عقب (Undo)
        function undoLastAnnotation() {
            if (pageAnnotations.length === 0) return;
            pageAnnotations.pop();
            redrawAllAnnotations();
            triggerAutoSaveAnnotations();
        }

        // پاکسازی تمام علامت‌های صفحه فعلی
        function clearAllPageAnnotations() {
            if (pageAnnotations.length === 0) return;
            if (!confirm('آیا تمام هایلایت‌ها و یادداشت‌های این صفحه پاک شوند؟')) return;
            pageAnnotations = [];
            redrawAllAnnotations();
            triggerAutoSaveAnnotations();
        }

        // ذخیره خودکار در دیتابیس با تاخیر کوتاه (Debounce) برای جلوگیری از فشار به سرور
        function triggerAutoSaveAnnotations() {
            if (!activePdfMeta) return;
            const statusEl = document.getElementById('annot-save-status');
            statusEl.textContent = '⏳ در حال ذخیره...';
            statusEl.className = 'text-[11px] font-bold text-amber-400 min-w-[75px] text-left';

            if (saveAnnotTimeout) clearTimeout(saveAnnotTimeout);
            const pdfIdToSave = activePdfMeta.id;
            const pageToSave  = currentPageNum;
            const dataToSave  = JSON.stringify(pageAnnotations);

            saveAnnotTimeout = setTimeout(async () => {
                const fd = new FormData();
                fd.append('action', 'save_annotations');
                fd.append('pdf_id', pdfIdToSave);
                fd.append('page_number', pageToSave);
                fd.append('annotation_data', dataToSave);

                const res = await fetch('api_pdf.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'success') {
                    statusEl.textContent = '✓ ذخیره شد';
                    statusEl.className = 'text-[11px] font-bold text-emerald-400 min-w-[75px] text-left';
                } else {
                    statusEl.textContent = '❌ خطا در ذخیره';
                    statusEl.className = 'text-[11px] font-bold text-rose-400 min-w-[75px] text-left';
                }
            }, 400);
        }

        // اتصال رویدادهای موس و لمس (موبایل/تبلت/قلم نوری) به لایه علامت‌گذاری
        annotCanvas.addEventListener('mousedown', handlePointerStart);
        annotCanvas.addEventListener('mousemove', handlePointerMove);
        window.addEventListener('mouseup', handlePointerEnd);

        annotCanvas.addEventListener('touchstart', handlePointerStart, { passive: false });
        annotCanvas.addEventListener('touchmove', handlePointerMove, { passive: false });
        window.addEventListener('touchend', handlePointerEnd);
		
    </script>
</body>
</html>