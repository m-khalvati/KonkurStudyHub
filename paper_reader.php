<?php
require_once __DIR__ . '/config/config.php';
require_login();

$user_id = (int)$_SESSION['user_id'];

if (!defined('TELEGRAM_SALES_ID'))      define('TELEGRAM_SALES_ID', 'YourTelegramID');
if (!defined('FREE_MAX_PDF_COUNT'))     define('FREE_MAX_PDF_COUNT', 1);
if (!defined('FREE_MAX_PDF_MB'))        define('FREE_MAX_PDF_MB', 3);
if (!defined('VIP_MAX_PDF_MB'))         define('VIP_MAX_PDF_MB', 64);
if (!defined('PLAN_1M_PRICE'))          define('PLAN_1M_PRICE', '۵۰۰,۰۰۰');
if (!defined('PLAN_3M_PRICE'))          define('PLAN_3M_PRICE', '۱,۲۰۰,۰۰۰');
if (!defined('AI_TRANSLATION_ENABLED')) define('AI_TRANSLATION_ENABLED', true);
if (!defined('AI_VIP_ONLY'))            define('AI_VIP_ONLY', true);

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
    <title>مقاله‌خوان هوشمند و مترجم آکادمیک v4.1</title>
    <!-- Local Host Assets (Offline-First) -->
    <link href="assets/css/tailwind-local.css" rel="stylesheet">
    <link href="assets/css/vazirmatn-local.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">

    <!-- بارگذاری نسخه 3.11.174 کتابخانه PDF.js از سرور بدون اختلال jsDelivr به همراه پشتیبان unpkg -->
    <script src="assets/pdfjs/pdf.min.js" onerror="this.onerror=null;this.src='https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js';"></script>

    <link href="assets/css/paper_reader.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/paper_reader.css') ?: '4.4'; ?>" rel="stylesheet">
</head>
<body class="bg-slate-100 text-slate-800 h-screen flex flex-col overflow-hidden select-none">

    <!-- ==================================================== -->
    <!-- ۱. هدر دسکتاپ (در موبایل مخفی است تا مقاله ۱۰۰٪ تمام‌صفحه باشد) -->
    <!-- ==================================================== -->
    <header id="main-app-header" class="hidden md:flex bg-slate-900 text-white px-4 py-2 items-center justify-between gap-2 shadow-md z-30 shrink-0">
        <div class="flex items-center gap-3">
            <div class="flex items-center gap-1.5">
                <span class="bg-emerald-600 text-white px-2 py-0.5 rounded-lg font-extrabold text-xs">🌐 مقاله‌خوان</span>
                <span class="font-bold text-xs truncate max-w-[140px]"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
            </div>

            <nav class="flex bg-slate-800 p-0.5 rounded-xl border border-slate-700">
                <a href="index.php" class="px-3 py-1 rounded-lg text-xs font-bold text-slate-300 hover:text-white transition">
                    📅 تقویم
                </a>
                <a href="index.php?tab=pdf" class="px-3 py-1 rounded-lg text-xs font-bold text-slate-300 hover:text-white transition">
                    📚 جزوه‌خوان
                </a>
                <span class="px-3.5 py-1 rounded-lg text-xs font-bold bg-indigo-600 text-white shadow-xs">
                    🌐 مقاله‌خوان
                </span>
                <a href="exam.php" class="px-2.5 md:px-3.5 py-1.5 rounded-lg text-xs font-bold text-rose-300 hover:text-white transition">
                    📝 آزمون‌ها
                </a>
                <a href="courses.php" class="px-2.5 md:px-3.5 py-1.5 rounded-lg text-xs font-bold text-amber-300 hover:text-white transition">
                    🎬 ویدیوها
                </a>
            </nav>
        </div>

        <div class="flex items-center gap-2 text-xs">
            <button type="button" id="header-sub-btn" onclick="openSubscriptionModal()" class="<?php echo $initial_sub['is_vip'] ? 'bg-gradient-to-r from-amber-500 to-yellow-400 text-slate-950' : 'bg-indigo-600 hover:bg-indigo-500 text-white border border-indigo-400'; ?> px-2.5 py-1 rounded-lg font-extrabold flex items-center gap-1 shadow-xs transition">
                <?php if ($initial_sub['is_vip']): ?>
                    <span>👑 ویژه (<?php echo $initial_sub['days_left']; ?> روز | +تمدید)</span>
                <?php else: ?>
                    <span>⭐ ارتقا به اشتراک ویژه</span>
                <?php endif; ?>
            </button>

            <?php if (!empty($initial_sub['is_admin'])): ?>
                <a href="admin.php" class="bg-slate-800 hover:bg-slate-700 border border-amber-500/50 text-amber-300 px-2.5 py-1 rounded-lg font-bold flex items-center gap-1 transition">
                    <span>🛠️ مدیریت</span>
                </a>
            <?php endif; ?>

            <a href="auth.php?logout=1" class="text-slate-400 hover:text-rose-400 font-bold px-1.5">خروج</a>
        </div>
    </header>

    <!-- ==================================================== -->
    <!-- ۲. بدنه اصلی مقاله‌خوان -->
    <!-- ==================================================== -->
    <main class="flex-1 flex overflow-hidden relative bg-slate-900">

        <div id="paper-sidebar-backdrop" onclick="closePaperSidebarMobile()" class="fixed inset-0 bg-black/60 z-40 hidden lg:hidden"></div>

        <!-- الف) سایدبار کتابخانه مقالات -->
        <aside id="paper-sidebar" class="w-72 max-w-[85vw] bg-white border-l border-slate-200 flex flex-col shrink-0 z-50 fixed lg:static inset-y-0 right-0 transform translate-x-full lg:translate-x-0 transition-transform duration-200 shadow-2xl lg:shadow-none">
            <div class="p-3 border-b border-slate-200 flex items-center justify-between bg-slate-50">
                <h2 class="font-extrabold text-xs md:text-sm text-slate-800">📄 کتابخانه مقالات من</h2>
                <button type="button" onclick="closePaperSidebarMobile()" class="lg:hidden text-slate-400 hover:text-slate-700 text-xl font-bold leading-none">&times;</button>
            </div>

            <!-- لینک‌های ناوبری سریع در موبایل داخل سایدبار -->
            <div class="md:hidden px-2.5 py-2 bg-slate-900 text-white flex items-center justify-between text-[11px] font-bold">
                <div class="flex items-center gap-1.5">
                    <a href="index.php" class="px-2 py-1 bg-slate-800 rounded-lg text-slate-200">📅 تقویم</a>
                    <a href="index.php?tab=pdf" class="px-2 py-1 bg-slate-800 rounded-lg text-slate-200">📚 جزوه‌خوان</a>
                </div>
                <button type="button" onclick="closePaperSidebarMobile(); openSubscriptionModal();" class="px-2 py-1 bg-amber-400 text-slate-950 rounded-lg font-extrabold">
                    👑 اشتراک
                </button>
            </div>

            <div class="p-2.5 border-b border-slate-200 bg-slate-50/60 grid grid-cols-2 gap-1.5 text-[11px]">
                <button type="button" onclick="openUploadPaperModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white py-2 px-2 rounded-xl font-bold shadow-2xs transition flex items-center justify-center gap-1">
                    <span>+ آپلود مقاله</span>
                </button>
                <button type="button" onclick="openImportBookletModal()" class="bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 py-2 px-2 rounded-xl font-bold transition flex items-center justify-center gap-1">
                    <span>📥 از جزوه‌خوان</span>
                </button>
            </div>

            <div id="paper-quota-banner" class="px-3 py-1.5 text-[11px] border-b border-amber-200 bg-amber-50 text-amber-900 font-bold flex items-center justify-between"></div>

            <div id="papers-list-container" class="flex-1 overflow-y-auto p-2.5 space-y-2 custom-scroll"></div>
        </aside>

        <!-- ب) بخش میانی: نمایشگر تمام‌صفحه مقاله انگلیسی -->
        <section class="flex-1 flex flex-col overflow-hidden relative bg-slate-800">
            
            <!-- نوار بالای فوق‌فشرده و خلوت (مخصوص موبایل و دسکتاپ) -->
            <div class="bg-slate-900/95 text-white border-b border-slate-700/80 px-2 py-1.5 flex items-center justify-between gap-1.5 shrink-0 z-20">
                <div class="flex items-center gap-1.5 min-w-0">
                    <button type="button" onclick="togglePaperSidebar()" class="px-2.5 py-1 bg-indigo-600 hover:bg-indigo-500 rounded-lg text-[11px] font-bold text-white shrink-0 shadow-2xs">
                        ☰ مقالات
                    </button>
                    <span id="active-paper-title" class="text-[11px] font-bold text-slate-200 truncate max-w-[85px] sm:max-w-[220px]">
                        مقاله
                    </span>
                </div>

                <!-- ورق زدن صفحه -->
                <div id="paper-page-controls" class="hidden items-center gap-1 shrink-0">
                    <button type="button" onclick="changePaperPage(-1)" class="w-7 h-7 bg-slate-800 hover:bg-slate-700 rounded-lg text-xs font-bold flex items-center justify-center">❮</button>
                    <div class="flex items-center gap-0.5 text-[11px] font-bold bg-slate-800 px-1.5 py-0.5 rounded-lg border border-slate-700">
                        <input type="number" id="paper-page-input" onchange="jumpToPaperPage(this.value)" min="1" value="1" class="w-7 text-center bg-transparent text-white font-extrabold focus:outline-none">
                        <span class="text-slate-400">/</span>
                        <span id="paper-total-pages" class="text-slate-300">1</span>
                    </div>
                    <button type="button" onclick="changePaperPage(1)" class="w-7 h-7 bg-slate-800 hover:bg-slate-700 rounded-lg text-xs font-bold flex items-center justify-center">❯</button>
                </div>

                <!-- دکمه‌های سمت چپ: شروع سریع پیشروی خودکار + دکمه کشوی تنظیمات و ابزارها (⚙️) -->
                <div class="flex items-center gap-1 shrink-0">
                    <button type="button" id="btn-top-auto-advance" onclick="toggleAutoAdvance()" class="hidden px-2.5 py-1 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-[11px] font-extrabold items-center gap-1 shadow-2xs transition">
                        <span>▶️</span><span id="top-auto-label">خودکار</span>
                    </button>

                    <!-- دکمه‌های زوم سریع فقط در دسکتاپ (در موبایل داخل منوی ⚙️ و زوم دو انگشتی هستند) -->
                    <div class="hidden lg:flex items-center gap-1">
                        <button type="button" onclick="adjustPaperZoom(-0.2)" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 rounded-lg text-xs font-bold">➖</button>
                        <button type="button" onclick="setPaperFitWidth()" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 text-indigo-300 rounded-lg text-[11px] font-bold">↔ عرض</button>
                        <button type="button" onclick="adjustPaperZoom(0.2)" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 rounded-lg text-xs font-bold">➕</button>
                    </div>

                    <button type="button" onclick="openToolsDrawer()" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-amber-300 border border-slate-700 rounded-lg text-[11px] font-extrabold flex items-center gap-1 transition" title="ابزارها، سرعت پیشروی و تنظیمات نمایش">
                        <span>⚙️</span><span class="hidden sm:inline">ابزارها</span>
                    </button>

                    <button type="button" onclick="setMobileSheetState('half')" class="md:hidden px-2 py-1 bg-indigo-600/90 text-white rounded-lg text-[11px] font-bold">
                        🌐
                    </button>
                </div>
            </div>

            <!-- نوار باریک وضعیت پیشروی خودکار (فقط وقتی پیشروی خودکار روشن است نمایش داده می‌شود) -->
            <div id="auto-advance-slim-bar" class="hidden bg-slate-950 text-white px-2.5 py-1 border-b border-slate-800 items-center justify-between gap-2 text-[10px] shrink-0 z-10">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping shrink-0"></span>
                    <span id="auto-advance-status-text" class="text-emerald-300 font-bold truncate">در حال پیشروی خودکار...</span>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <button type="button" onclick="openToolsDrawer()" class="text-amber-300 underline font-bold">سرعت: <span id="slim-speed-badge">8s</span></button>
                    <button type="button" onclick="stopAutoAdvance()" class="bg-rose-600 text-white px-2 py-0.5 rounded font-bold">⏹ توقف</button>
                </div>
            </div>
            <div id="auto-advance-progress-wrap" class="hidden w-full h-1 bg-slate-900 shrink-0">
                <div id="auto-advance-progress-bar" class="h-full bg-emerald-400 transition-all duration-100" style="width: 0%;"></div>
            </div>

            <!-- ناحیه اسکرول، وسط‌چین خودکار عمودی و افقی و رندر بوم PDF مقاله -->
            <div id="paper-viewport-container" class="flex-1 overflow-auto py-6 px-1 sm:p-4 flex custom-scroll relative">
                
                <div id="paper-loading-overlay" class="hidden m-auto text-center bg-slate-900/95 text-white p-6 rounded-2xl shadow-xl border border-slate-700 z-30">
                    <div class="text-3xl mb-2 animate-bounce">⏳</div>
                    <div class="text-xs font-bold text-indigo-300">در حال بارگذاری مقاله و تنظیم دقیق لایه جملات...</div>
                </div>

                <div id="paper-empty-state" class="m-auto text-center bg-slate-900/90 text-white p-6 rounded-2xl shadow-xl max-w-sm border border-slate-700">
                    <div class="text-4xl mb-2">🌐</div>
                    <h3 class="font-extrabold text-sm mb-1">محيط مقاله‌خوان و مترجم هوشمند</h3>
                    <p class="text-slate-400 text-xs mb-4 leading-relaxed">
                        مقاله انگلیسی خود را باز کنید؛ با دو انگشت روی هر نقطه از صفحه زوم نمایید و روی هر جمله کلیک کنید یا «▶️ خودکار» را بزنید تا بندبه‌بند ترجمه شود.
                    </p>
                    <div class="flex flex-wrap gap-2 justify-center">
                        <button type="button" onclick="openUploadPaperModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3.5 py-2 rounded-xl text-xs font-bold">
                            + آپلود مقاله انگلیسی
                        </button>
                        <button type="button" onclick="openImportBookletModal()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3.5 py-2 rounded-xl text-xs font-bold">
                            📥 انتخاب از جزوه‌خوان
                        </button>
                    </div>
                </div>

                <!-- بوم رندر PDF + لایه درصدی دقیق باکس‌های جملات (وسط‌چین عمودی و افقی با margin: auto) -->
                <div id="paper-canvas-wrapper" dir="ltr" class="hidden relative shadow-2xl bg-white shrink-0">
                    <canvas id="paper-render-canvas" dir="ltr" class="block"></canvas>
                    <div id="paper-paragraphs-layer" dir="ltr" class="absolute inset-0"></div>
                </div>
            </div>
        </section>

        <!-- ==================================================== -->
        <!-- ج) پنل ترجمه ۳ حالته (در موبایل: نیم‌صفحه 42vh / مینیمایز کف صفحه / بزرگ 78vh | در دسکتاپ: ستون کناری) -->
        <!-- ==================================================== -->
        <aside id="translator-panel" class="w-full md:w-96 lg:w-[430px] bg-white border-t-2 md:border-t-0 md:border-r border-indigo-500/50 md:border-slate-200 flex flex-col shrink-0 z-30 fixed md:static inset-x-0 bottom-0 h-[42vh] md:h-auto rounded-t-2xl md:rounded-none transform translate-y-full md:translate-y-0 transition-all duration-200 shadow-[0_-12px_28px_rgba(0,0,0,0.4)] md:shadow-none">
            
            <!-- دستگیره لمسی (Swipe Handle) بالای پنل موبایل برای کشیدن به بالا یا پایین -->
            <div id="mobile-sheet-handle" class="md:hidden bg-slate-900 pt-1.5 pb-0.5 flex justify-center rounded-t-2xl cursor-grab">
                <div class="w-10 h-1 bg-slate-600 rounded-full"></div>
            </div>

            <!-- نوار هدر پنل ترجمه -->
            <div class="bg-slate-900 text-white px-3 py-1.5 md:py-2.5 flex items-center justify-between border-b border-slate-800 md:rounded-none shrink-0">
                <div class="flex items-center gap-1.5">
                    <div class="flex bg-slate-800 p-0.5 rounded-xl border border-slate-700 text-[11px]">
                        <button type="button" id="tab-btn-translate" onclick="switchTranslatorTab('translate')" class="px-2.5 py-0.5 md:py-1 rounded-lg font-extrabold bg-indigo-600 text-white transition">
                            🌐 ترجمه
                        </button>
                        <button type="button" id="tab-btn-notes" onclick="switchTranslatorTab('notes')" class="px-2.5 py-0.5 md:py-1 rounded-lg font-bold text-slate-300 hover:text-white transition flex items-center gap-1">
                            <span>📌 فیش‌ها</span>
                            <span id="notes-count-badge" class="bg-slate-700 text-amber-300 px-1.5 rounded-full text-[10px]">0</span>
                        </button>
                    </div>

                    <!-- پیمایش سریع جمله قبلی و بعدی -->
                    <div class="flex items-center gap-0.5 bg-slate-800 px-1 py-0.5 rounded-lg border border-slate-700">
                        <button type="button" onclick="stepSegment(-1)" class="px-1.5 py-0.5 hover:bg-slate-700 text-slate-200 rounded text-[11px] font-bold" title="بند قبلی">❯</button>
                        <span id="current-seg-Position-label" class="text-[10px] font-extrabold text-amber-300 px-1">---</span>
                        <button type="button" onclick="stepSegment(1)" class="px-1.5 py-0.5 hover:bg-slate-700 text-indigo-300 rounded text-[11px] font-bold" title="بند بعدی">❮</button>
                    </div>
                </div>

                <!-- دکمه‌های ۳ حالته پنل در موبایل: مینیمایز (➖) | تغییر سایز نیم‌صفحه/بزرگ (⬆️) | بستن (✕) -->
                <div class="flex items-center gap-1 md:hidden">
                    <button type="button" onclick="setMobileSheetState('minimized')" class="px-2 py-0.5 bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 rounded text-[10px] font-bold" title="جمع کردن در کف صفحه">
                        ➖
                    </button>
                    <button type="button" id="btn-mobile-sheet-size" onclick="toggleMobileExpandHalf()" class="px-2 py-0.5 bg-slate-800 hover:bg-slate-700 text-sky-300 border border-slate-700 rounded text-[10px] font-bold">
                        ⬆️
                    </button>
                    <button type="button" onclick="setMobileSheetState('closed')" class="text-slate-400 hover:text-white text-base font-bold px-1 leading-none">&times;</button>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- تب ۱: ترجمه زنده، پلیر صوتی و کنترل‌ها -->
            <!-- ========================================== -->
            <div id="panel-tab-translate" class="flex-1 flex flex-col overflow-y-auto p-2.5 md:p-3.5 space-y-2.5 custom-scroll text-xs">
                
                <!-- ۱. کادر خروجی ترجمه فارسی (در بالاترین نقطه پنل برای دید فوری در نمای نیم‌صفحه موبایل) -->
                <div class="flex flex-col bg-indigo-50/60 rounded-2xl p-2.5 md:p-3.5 border border-indigo-100">
                    <div class="flex items-center justify-between border-b border-indigo-100 pb-1.5 mb-1.5">
                        <span class="font-extrabold text-indigo-950 text-[11px] md:text-xs flex items-center gap-1 flex-wrap">
                            <span id="output-box-title">🇮🇷 ترجمه فارسی:</span>
                            <span id="translation-meta-badge" class="text-[9px] md:text-[10px] font-bold bg-indigo-100 text-indigo-700 px-1.5 py-0.5 rounded-full hidden"></span>
                        </span>
                        <div class="flex items-center gap-1 shrink-0">
                            <button type="button" onclick="runTranslationTask('summarize')" class="px-2 py-0.5 bg-amber-500/20 hover:bg-amber-500/30 text-amber-900 border border-amber-400/60 rounded-lg font-extrabold text-[10px] transition">
                                ✨ پیام کلیدی
                            </button>
                            <button type="button" onclick="copyTranslatedText()" class="text-[10px] text-indigo-600 hover:underline font-bold px-1">
                                📋 کپی
                            </button>
                        </div>
                    </div>

                    <div id="translation-loading" class="hidden my-auto text-center py-3 space-y-1">
                        <div class="inline-block w-5 h-5 border-2 border-indigo-600 border-t-transparent rounded-full animate-spin"></div>
                        <div id="translation-loading-text" class="text-indigo-700 font-bold text-[11px]">در حال ترجمه...</div>
                    </div>

                    <div id="translated-persian-output" class="text-slate-800 text-xs md:text-sm leading-6 md:leading-7 font-medium whitespace-pre-line select-text">
                        روی هر جمله از مقاله کلیک کنید تا ترجمه فارسی اینجا نمایش داده شود.
                    </div>

                    <div id="extracted-keywords-box" class="hidden mt-2 pt-2 border-t border-indigo-200/60">
                        <div class="text-[10px] font-extrabold text-indigo-900 mb-1">📖 واژگان تخصصی (کلیک = تلفظ):</div>
                        <div id="extracted-keywords-list" class="flex flex-wrap gap-1"></div>
                    </div>

                    <div id="save-note-action-bar" class="hidden pt-2 mt-2 border-t border-indigo-200/60 flex items-center gap-1.5">
                        <input type="text" id="note-user-comment" placeholder="یادداشت شخصی (اختیاری)..." class="flex-1 px-2.5 py-1 bg-white border border-indigo-200 rounded-xl text-[11px]">
                        <button type="button" onclick="saveCurrentTranslationAsNote()" class="px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-extrabold text-[11px] shadow-xs shrink-0 transition">
                            📌 ذخیره فیش
                        </button>
                    </div>
                </div>

                <!-- ۲. انتخاب موتور ترجمه -->
                <div class="grid grid-cols-3 gap-1.5">
                    <button type="button" onclick="selectTranslationEngine('google')" id="eng-btn-google" class="eng-btn py-1.5 px-2 rounded-xl font-extrabold border text-[11px] bg-indigo-600 text-white border-indigo-600 shadow-2xs transition">
                        ⚡ Google
                    </button>
                    <button type="button" onclick="selectTranslationEngine('mymemory')" id="eng-btn-mymemory" class="eng-btn py-1.5 px-2 rounded-xl font-bold border text-[11px] bg-slate-50 text-slate-700 border-slate-200 hover:border-indigo-300 transition">
                        🌐 MyMemory
                    </button>
                    <button type="button" onclick="selectTranslationEngine('ai')" id="eng-btn-ai" class="eng-btn py-1.5 px-2 rounded-xl font-bold border text-[11px] bg-slate-50 text-slate-700 border-slate-200 hover:border-indigo-300 transition relative">
                        🧠 هوش مصنوعی <span id="ai-engine-sublabel" class="text-[9px] font-extrabold text-amber-500">VIP</span>
                    </button>
                </div>

                <!-- ۳. پلیر صوتی چندگوینده و متن انگلیسی انتخاب‌شده -->
                <div class="bg-slate-50 rounded-2xl p-2.5 border border-slate-200 space-y-2">
                    <div class="bg-slate-200/75 p-1.5 rounded-xl flex flex-wrap items-center justify-between gap-1">
                        <div class="flex items-center gap-1">
                            <button type="button" onclick="playOrResumeSpeech()" id="btn-tts-play" class="px-2 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-extrabold text-[10px] flex items-center gap-1 transition">
                                <span>▶</span><span>خواندن</span>
                            </button>
                            <button type="button" onclick="pauseSpeech()" id="btn-tts-pause" class="hidden px-2 py-1 bg-amber-500 text-white rounded-lg font-bold text-[10px]">⏸</button>
                            <button type="button" onclick="stopSpeech()" id="btn-tts-stop" class="hidden px-2 py-1 bg-rose-600 text-white rounded-lg font-bold text-[10px]">⏹</button>
                        </div>

                        <div class="flex items-center gap-1 flex-1 justify-end">
                            <select id="tts-voice-select" onchange="saveTtsPreferences()" dir="ltr" class="max-w-[125px] px-1.5 py-0.5 bg-white border border-slate-300 rounded-lg text-[10px] font-bold text-slate-700 truncate">
                                <option value="">🎙️ گوینده پیش‌فرض</option>
                            </select>
                            <select id="tts-rate-select" onchange="saveTtsPreferences()" dir="ltr" class="px-1 py-0.5 bg-white border border-slate-300 rounded-lg text-[10px] font-extrabold text-indigo-700">
                                <option value="0.5">0.5x</option>
                                <option value="0.75">0.75x</option>
                                <option value="1" selected>1.0x</option>
                                <option value="1.25">1.25x</option>
                                <option value="1.5">1.5x</option>
                            </select>
                        </div>
                    </div>

                    <textarea id="source-english-textarea" onmouseup="checkSubSelectionInTextarea()" onkeyup="checkSubSelectionInTextarea()" touchend="checkSubSelectionInTextarea()" dir="ltr" rows="2" placeholder="متن انگلیسی انتخاب شده..." class="w-full p-2 bg-white border border-slate-200 rounded-xl text-left font-sans text-[11px] text-slate-700 leading-relaxed focus:outline-none focus:ring-1 focus:ring-indigo-500 select-text"></textarea>

                    <div id="sub-selection-bar" class="hidden bg-amber-50 border border-amber-300 rounded-xl p-1.5 flex items-center justify-between gap-2">
                        <div class="truncate text-[10px] font-bold text-amber-950">
                            <span>🔍 واژه: </span>
                            <span id="sub-selection-term" class="font-mono text-indigo-700 dir-ltr"></span>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            <button type="button" onclick="speakSelectedSubstring()" class="px-1.5 py-0.5 bg-white border border-amber-300 rounded text-[10px]">🔊</button>
                            <button type="button" onclick="translateSelectedSubstring()" class="px-2 py-0.5 bg-amber-500 text-slate-950 rounded text-[10px] font-extrabold">معنی فوری</button>
                        </div>
                    </div>

                    <div id="sub-selection-result" class="hidden bg-indigo-950 text-white p-2 rounded-xl text-[11px] flex items-center justify-between gap-2">
                        <div id="sub-selection-meaning" class="font-bold leading-relaxed"></div>
                        <button type="button" onclick="document.getElementById('sub-selection-result').classList.add('hidden')" class="text-slate-400 hover:text-white font-bold">&times;</button>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- تب ۲: دفترچه فیش‌های مقاله + خروجی Word و PDF -->
            <!-- ========================================== -->
            <div id="panel-tab-notes" class="hidden flex-1 flex-col overflow-hidden p-3 text-xs">
                <div class="bg-slate-100 p-2 rounded-2xl border border-slate-200 mb-2 space-y-1.5 shrink-0">
                    <div class="flex items-center justify-between">
                        <span class="font-extrabold text-slate-800 text-[11px]">📥 خروجی گرفتن از فیش‌ها:</span>
                        <button type="button" onclick="copyAllPaperNotes()" class="text-indigo-600 hover:underline font-bold text-[11px]">📋 کپی متنی</button>
                    </div>
                    <div class="grid grid-cols-2 gap-1.5">
                        <button type="button" onclick="exportNotesToWord()" class="py-1.5 px-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-extrabold text-[11px] flex items-center justify-center gap-1">
                            <span>📘 دانلود Word</span>
                        </button>
                        <button type="button" onclick="exportNotesToPdfPrint()" class="py-1.5 px-2 bg-rose-600 hover:bg-rose-700 text-white rounded-xl font-extrabold text-[11px] flex items-center justify-center gap-1">
                            <span>📕 خروجی PDF</span>
                        </button>
                    </div>
                </div>

                <div class="mb-2 shrink-0">
                    <input type="text" id="notes-search-input" oninput="renderPaperNotesList()" placeholder="🔎 جستجو در فیش‌ها..." class="w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-xl text-xs focus:outline-none">
                </div>

                <div id="paper-notes-list" class="flex-1 overflow-y-auto space-y-2 custom-scroll"></div>
            </div>

        </aside>

    </main>

    <!-- ==================================================== -->
    <!-- ۳. پنجره کشویی تنظیمات و ابزارهای مقاله‌خوان (⚙️ Tools Drawer) -->
    <!-- ==================================================== -->
    <div id="tools-drawer-modal" onclick="closeToolsDrawer()" class="fixed inset-0 bg-slate-950/65 backdrop-blur-xs hidden items-end sm:items-center justify-center sm:p-4 z-50">
        <div onclick="event.stopPropagation()" class="bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl w-full max-w-md overflow-hidden text-xs">
            <div class="bg-slate-900 text-white px-4 py-3 flex items-center justify-between">
                <span class="font-extrabold text-sm flex items-center gap-1.5">⚙️ ابزارها و تنظیمات پیشروی</span>
                <button type="button" onclick="closeToolsDrawer()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>

            <div class="p-4 space-y-4 max-h-[82vh] overflow-y-auto custom-scroll">
                
                <!-- ۱. تنظیمات پیشروی خودکار (اسلایدر حداقل ۳ تا حداکثر ۲۵ ثانیه) -->
                <div class="bg-slate-50 p-3.5 rounded-2xl border border-slate-200 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="font-extrabold text-slate-800">▶️ پیشروی خودکار بندبه‌بند</span>
                        <button type="button" id="drawer-btn-auto-toggle" onclick="toggleAutoAdvance()" class="px-3 py-1 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl font-extrabold text-[11px] transition">
                            شروع پیشروی
                        </button>
                    </div>

                    <div>
                        <div class="flex items-center justify-between text-[11px] font-bold text-slate-700 mb-1.5">
                            <span>⏱️ زمان مطالعه شما برای هر بند:</span>
                            <span id="auto-speed-slider-label" class="bg-indigo-100 text-indigo-800 px-2 py-0.5 rounded-lg font-extrabold">۸ ثانیه</span>
                        </div>
                        <input type="range" id="auto-advance-speed" min="3" max="25" step="1" value="8" oninput="onAutoSpeedSliderInput(this.value)" class="w-full accent-indigo-600 cursor-pointer">
                        <div class="flex justify-between text-[10px] text-slate-400 font-bold mt-0.5">
                            <span>حداقل (۳ ثانیه - تند)</span>
                            <span>متوسط (۱۰ ثانیه)</span>
                            <span>حداکثر (۲۵ ثانیه - آرام)</span>
                        </div>
                    </div>

                    <label class="flex items-center gap-2 pt-1 cursor-pointer select-none">
                        <input type="checkbox" id="auto-advance-tts-sync" class="w-4 h-4 accent-indigo-600 rounded">
                        <span class="font-bold text-slate-700 text-[11px]">🔊 روخوانی صوتی انگلیسی همزمان با پیشروی خودکار</span>
                    </label>

                    <div class="text-[10px] text-slate-500 leading-relaxed bg-white p-2 rounded-xl border border-slate-200/80">
                        💡 <b>هوشمندسازی با هوش مصنوعی:</b> زمان بالا صرفاً زمان مطالعه شماست. در صورت انتخاب موتور «🧠 هوش مصنوعی»، سیستم بند بعدی را از قبل در پس‌زمینه آماده می‌کند و زمان پردازش سرور را از وقت مطالعه شما کم نمی‌کند.
                    </div>
                </div>

                <!-- ۲. نحوه انتخاب متن روی مقاله (جمله‌ای در برابر پاراگرافی) -->
                <div class="bg-slate-50 p-3 rounded-2xl border border-slate-200 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="font-extrabold text-slate-800">🎯 نحوه انتخاب باکس‌های مقاله:</span>
                        <span id="detected-blocks-badge" class="text-[10px] bg-indigo-100 text-indigo-800 px-2 py-0.5 rounded-full font-bold">0 جمله</span>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" id="mode-btn-sentence" onclick="setSelectionGranularity('sentence')" class="py-2 px-2 rounded-xl font-extrabold bg-indigo-600 text-white transition">
                            🎯 از نقطه تا نقطه (جمله)
                        </button>
                        <button type="button" id="mode-btn-paragraph" onclick="setSelectionGranularity('paragraph')" class="py-2 px-2 rounded-xl font-bold bg-white text-slate-700 border border-slate-200 transition">
                            📄 کل بند (پاراگراف)
                        </button>
                    </div>
                </div>

                <!-- ۳. بزرگ‌نمایی و حالت شب -->
                <div class="bg-slate-50 p-3 rounded-2xl border border-slate-200 space-y-2">
                    <span class="block font-extrabold text-slate-800">🔍 بزرگ‌نمایی و نمایش (یا با دو انگشت روی صفحه زوم کنید):</span>
                    <div class="grid grid-cols-4 gap-1.5">
                        <button type="button" onclick="adjustPaperZoom(-0.22)" class="py-2 bg-white border border-slate-200 hover:bg-slate-100 rounded-xl font-bold text-center">➖ کوچک</button>
                        <button type="button" onclick="setPaperFitWidth()" class="py-2 bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-xl font-extrabold text-center">↔ عرض صفحه</button>
                        <button type="button" onclick="adjustPaperZoom(0.22)" class="py-2 bg-white border border-slate-200 hover:bg-slate-100 rounded-xl font-bold text-center">➕ بزرگ</button>
                        <button type="button" onclick="togglePaperDarkMode()" id="btn-paper-dark" class="py-2 bg-slate-800 text-amber-300 rounded-xl font-bold text-center">🌙 شب</button>
                    </div>
                </div>

                <!-- ۴. ناوبری سریع بین بخش‌های سایت (ویژه موبایل) -->
                <div class="grid grid-cols-3 gap-2 pt-1 border-t border-slate-200">
                    <a href="index.php" class="py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-bold text-center">📅 تقویم کنکور</a>
                    <a href="index.php?tab=pdf" class="py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-bold text-center">📚 جزوه‌خوان</a>
                    <button type="button" onclick="closeToolsDrawer(); openSubscriptionModal();" class="py-2 bg-amber-400/90 text-slate-950 rounded-xl font-extrabold text-center">👑 اشتراک ویژه</button>
                </div>

            </div>
        </div>
    </div>

    <!-- ==================================================== -->
    <!-- ۴. مودال آپلود مقاله انگلیسی جدید -->
    <!-- ==================================================== -->
    <div id="upload-paper-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
            <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between">
                <h3 class="font-bold text-xs md:text-sm">📄 آپلود مقاله انگلیسی جدید (PDF)</h3>
                <button type="button" onclick="closeUploadPaperModal()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <form id="upload-paper-form" onsubmit="handlePaperUpload(event)" class="p-4 space-y-3.5 text-xs">
                <div id="upload-paper-tier-hint" class="p-2.5 rounded-xl bg-slate-100 text-slate-700 text-[11px] font-bold"></div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">عنوان مقاله *</label>
                    <input type="text" name="title" required placeholder="مثلاً: مقاله GMAN-TCN Traffic Prediction" class="w-full px-3 py-2 border border-slate-300 rounded-lg">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">فایل PDF مقاله *</label>
                    <input type="file" id="upload-paper-file-input" name="pdf_file" accept="application/pdf" required class="w-full border border-slate-300 rounded-lg p-2 bg-slate-50">
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t">
                    <button type="button" onclick="closeUploadPaperModal()" class="px-3.5 py-2 bg-slate-100 rounded-lg font-bold">انصراف</button>
                    <button type="submit" id="btn-submit-paper-upload" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-bold">آپلود مقاله</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==================================================== -->
    <!-- ۵. مودال افزودن فایل از بخش «جزوه‌خوان» به «مقاله‌خوان» -->
    <!-- ==================================================== -->
    <div id="import-booklet-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
            <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between">
                <h3 class="font-bold text-xs md:text-sm">📥 انتخاب فایل از بخش جزوه‌خوان</h3>
                <button type="button" onclick="closeImportBookletModal()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <div class="p-4 space-y-3 text-xs">
                <p class="text-slate-600 leading-relaxed">
                    می‌توانید هر یک از فایل‌های PDF موجود در بخش «جزوه‌خوان» را با یک کلیک به محیط «مقاله‌خوان» اضافه کنید:
                </p>
                <div id="booklets-import-list" class="max-h-64 overflow-y-auto space-y-2 custom-scroll"></div>
            </div>
        </div>
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
                        <h3 class="font-extrabold text-sm md:text-base text-amber-300">اشتراک ویژه (VIP) سامانه مطالعه و مقاله‌خوان</h3>
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
                                <li>✓ آپلود نامحدود مقاله و جزوه (تا <?php echo VIP_MAX_PDF_MB; ?>MB)</li>
                                <li>✓ دسترسی به مترجم تخصصی هوش مصنوعی و خلاصه‌ساز</li>
                                <li>✓ فعال‌سازی پیامک یادآوری تقویم کنکور</li>
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
                                <li>✓ مترجم تخصصی هوش مصنوعی + استخراج واژگان و فیش</li>
                                <li>✓ آپلود نامحدود PDF + پیامک یادآوری تقویم</li>
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

        <!-- PHP-to-JS Configuration Bridge -->
    <script>
        const TELEGRAM_SALES_ID = "<?php echo htmlspecialchars(TELEGRAM_SALES_ID); ?>";
        const FREE_MAX_PDF_CNT  = <?php echo (int)FREE_MAX_PDF_COUNT; ?>;
        const FREE_MAX_PDF_MB   = <?php echo (int)FREE_MAX_PDF_MB; ?>;
        const VIP_MAX_PDF_MB    = <?php echo (int)VIP_MAX_PDF_MB; ?>;
        let userSub      = <?php echo json_encode($initial_sub, JSON_UNESCAPED_UNICODE); ?>;
        let aiConfig     = { enabled: <?php echo AI_TRANSLATION_ENABLED ? 'true' : 'false'; ?>, vip_only: <?php echo AI_VIP_ONLY ? 'true' : 'false'; ?> };
                            <div style="font-size:12px;color:#64748b;margin-top:4px;">پژوهشگر: <?php echo htmlspecialchars($_SESSION['full_name']); ?> | تعداد فیش‌ها: ${paperNotes.length}</div>
    </script>
    <!-- Modular External JavaScript -->
    <script src="assets/js/paper_reader.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/paper_reader.js') ?: '4.4'; ?>"></script>
</body>
</html>