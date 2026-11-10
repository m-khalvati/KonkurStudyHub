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
if (!defined('AI_TRANSLATION_ENABLED')) define('AI_TRANSLATION_ENABLED', true);
if (!defined('AI_VIP_ONLY'))        define('AI_VIP_ONLY', true);

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
    <title>مقاله‌خوان هوشمند و مترجم آکادمیک | کنکور و پژوهش</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">

    <!-- بارگذاری نسخه 3.11.174 کتابخانه PDF.js از سرور بدون اختلال jsDelivr به همراه پشتیبان unpkg -->
    <script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"
            onerror="this.onerror=null;this.src='https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.min.js';"></script>

    <style>
        body { font-family: 'Vazirmatn', sans-serif; -webkit-tap-highlight-color: transparent; }
        .custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .pdf-dark-mode { filter: invert(0.92) hue-rotate(180deg) contrast(1.05); }

        /* قفل کردن جهت LTR روی بوم PDF و لایه پاراگراف‌ها برای حفظ کامل ساختار مقالات انگلیسی */
        #paper-canvas-wrapper,
        #paper-render-canvas,
        #paper-paragraphs-layer {
            direction: ltr !important;
            text-align: left !important;
            unicode-bidi: isolate !important;
            font-family: sans-serif !important;
        }

        /* استایل باکس‌های تعاملی هر بند (پاراگراف) از مقاله */
        .para-hotspot {
            position: absolute;
            border-radius: 5px;
            border: 1.5px solid transparent;
            background-color: transparent;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .para-hotspot:hover {
            border-color: rgba(79, 70, 229, 0.65);
            background-color: rgba(99, 102, 241, 0.14);
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.12);
        }
        .para-hotspot.active-para {
            border: 2px solid #4f46e5 !important;
            background-color: rgba(79, 70, 229, 0.22) !important;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 h-screen flex flex-col overflow-hidden select-none">

    <!-- ==================================================== -->
    <!-- ۱. هدر اصلی سامانه (مشترک و هماهنگ با داشبورد اصلی) -->
    <!-- ==================================================== -->
    <header id="main-app-header" class="bg-slate-900 text-white px-3 md:px-4 py-2 flex flex-wrap items-center justify-between gap-2 shadow-md z-30 shrink-0">
        <div class="flex items-center gap-2 md:gap-3">
            <div class="flex items-center gap-1.5">
                <span class="bg-emerald-600 text-white px-2 py-1 rounded-lg font-extrabold text-xs">🌐 مقاله‌خوان</span>
                <span class="font-bold text-xs md:text-sm truncate max-w-[90px] sm:max-w-none"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
            </div>

            <!-- منوی ناوبری بین تقویم، جزوه‌خوان و مقاله‌خوان -->
            <nav class="flex bg-slate-800 p-0.5 rounded-xl border border-slate-700">
                <a href="index.php" class="px-2.5 md:px-3 py-1.5 rounded-lg text-xs font-bold text-slate-300 hover:text-white transition">
                    📅 تقویم
                </a>
                <a href="index.php?tab=pdf" class="px-2.5 md:px-3 py-1.5 rounded-lg text-xs font-bold text-slate-300 hover:text-white transition">
                    📚 جزوه‌خوان
                </a>
                <span class="px-2.5 md:px-3.5 py-1.5 rounded-lg text-xs font-bold bg-indigo-600 text-white shadow-xs">
                    🌐 مقاله‌خوان
                </span>
            </nav>
        </div>

        <div class="flex items-center gap-1.5 md:gap-2 text-xs">
            <!-- دکمه وضعیت اشتراک -->
            <button type="button" id="header-sub-btn" onclick="openSubscriptionModal()" class="<?php echo $initial_sub['is_vip'] ? 'bg-gradient-to-r from-amber-500 to-yellow-400 text-slate-950' : 'bg-indigo-600 hover:bg-indigo-500 text-white border border-indigo-400'; ?> px-2.5 py-1.5 rounded-lg font-extrabold flex items-center gap-1 shadow-xs transition">
                <?php if ($initial_sub['is_vip']): ?>
                    <span>👑 ویژه (<?php echo $initial_sub['days_left']; ?> روز | +تمدید)</span>
                <?php else: ?>
                    <span>⭐ ارتقا به اشتراک ویژه</span>
                <?php endif; ?>
            </button>

            <?php if (!empty($initial_sub['is_admin'])): ?>
                <a href="admin.php" class="bg-slate-800 hover:bg-slate-700 border border-amber-500/50 text-amber-300 px-2.5 py-1.5 rounded-lg font-bold flex items-center gap-1 transition">
                    <span>🛠️</span>
                    <span class="hidden lg:inline">مدیریت</span>
                </a>
            <?php endif; ?>

            <a href="auth.php?logout=1" class="text-slate-400 hover:text-rose-400 font-bold px-1.5 py-1">خروج</a>
        </div>
    </header>

    <!-- ==================================================== -->
    <!-- ۲. بدنه اصلی مقاله‌خوان (سایدبار مقالات + نمایشگر PDF + پنل ترجمه) -->
    <!-- ==================================================== -->
    <main class="flex-1 flex overflow-hidden relative bg-slate-900">

        <!-- پس‌زمینه تیره سایدبار در موبایل -->
        <div id="paper-sidebar-backdrop" onclick="closePaperSidebarMobile()" class="fixed inset-0 bg-black/60 z-30 hidden lg:hidden"></div>

        <!-- الف) سایدبار کتابخانه مقالات (پوشه مجزای uploads/papers + ایمپورت از جزوه‌خوان) -->
        <aside id="paper-sidebar" class="w-72 max-w-[85vw] bg-white border-l border-slate-200 flex flex-col shrink-0 z-40 fixed lg:static inset-y-0 right-0 transform translate-x-full lg:translate-x-0 transition-transform duration-200 shadow-2xl lg:shadow-none">
            <div class="p-3 border-b border-slate-200 flex items-center justify-between bg-slate-50">
                <h2 class="font-extrabold text-xs md:text-sm text-slate-800">📄 کتابخانه مقالات من</h2>
                <button type="button" onclick="closePaperSidebarMobile()" class="lg:hidden text-slate-400 hover:text-slate-700 text-xl font-bold leading-none">&times;</button>
            </div>

            <!-- دکمه‌های آپلود مقاله جدید و ایمپورت از جزوه‌خوان -->
            <div class="p-2.5 border-b border-slate-200 bg-slate-50/60 grid grid-cols-2 gap-1.5 text-[11px]">
                <button type="button" onclick="openUploadPaperModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white py-2 px-2 rounded-xl font-bold shadow-2xs transition flex items-center justify-center gap-1">
                    <span>+ آپلود مقاله</span>
                </button>
                <button type="button" onclick="openImportBookletModal()" class="bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 py-2 px-2 rounded-xl font-bold transition flex items-center justify-center gap-1" title="افزودن فایل از بخش جزوه‌خوان">
                    <span>📥 از جزوه‌خوان</span>
                </button>
            </div>

            <!-- بنر سهمیه آپلود -->
            <div id="paper-quota-banner" class="px-3 py-1.5 text-[11px] border-b border-amber-200 bg-amber-50 text-amber-900 font-bold flex items-center justify-between"></div>

            <!-- لیست مقالات -->
            <div id="papers-list-container" class="flex-1 overflow-y-auto p-2.5 space-y-2 custom-scroll"></div>
        </aside>

        <!-- ب) بخش میانی: نمایشگر مقاله انگلیسی با لایه تشخیص هوشمند بندها -->
        <section class="flex-1 flex flex-col overflow-hidden relative bg-slate-800">
            
            <!-- نوار ابزار بالای مقاله -->
            <div class="bg-slate-900/95 text-white border-b border-slate-700 px-2.5 py-1.5 flex flex-wrap items-center justify-between gap-2 shrink-0 z-20">
                <div class="flex items-center gap-1.5 min-w-0">
                    <button type="button" onclick="togglePaperSidebar()" class="px-2.5 py-1 bg-indigo-600 hover:bg-indigo-500 rounded-lg text-xs font-bold text-white shrink-0">
                        ☰ مقالات
                    </button>
                    <span id="active-paper-title" class="text-xs font-bold text-slate-200 truncate max-w-[140px] sm:max-w-xs">
                        مقاله‌ای انتخاب نشده است
                    </span>
                </div>

                <!-- کنترل ورق زدن صفحه -->
                <div id="paper-page-controls" class="hidden items-center gap-1 shrink-0">
                    <button type="button" onclick="changePaperPage(-1)" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 rounded-lg text-xs font-bold" title="صفحه قبل">❮</button>
                    <div class="flex items-center gap-1 text-xs font-bold bg-slate-800 px-2 py-0.5 rounded-lg border border-slate-700">
                        <input type="number" id="paper-page-input" onchange="jumpToPaperPage(this.value)" min="1" value="1" class="w-10 text-center bg-transparent text-white font-extrabold focus:outline-none">
                        <span class="text-slate-400">/</span>
                        <span id="paper-total-pages" class="text-slate-300">1</span>
                    </div>
                    <button type="button" onclick="changePaperPage(1)" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 rounded-lg text-xs font-bold" title="صفحه بعد">❯</button>
                </div>

                <!-- کنترل زوم، حالت شب و نمایش پنل ترجمه در موبایل -->
                <div class="flex items-center gap-1 shrink-0">
                    <div id="paper-zoom-controls" class="hidden sm:flex items-center gap-1">
                        <button type="button" onclick="adjustPaperZoom(-0.2)" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 rounded-lg text-xs font-bold" title="کوچک‌نمایی">➖</button>
                        <button type="button" onclick="setPaperFitWidth()" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 text-indigo-300 rounded-lg text-[11px] font-bold">↔ عرض</button>
                        <button type="button" onclick="adjustPaperZoom(0.2)" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 rounded-lg text-xs font-bold" title="بزرگ‌نمایی">➕</button>
                    </div>
                    <button type="button" onclick="togglePaperDarkMode()" id="btn-paper-dark" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 text-amber-300 rounded-lg text-xs font-bold" title="حالت شب">🌙</button>
                    <button type="button" onclick="openMobileTranslatorSheet()" class="md:hidden px-2.5 py-1 bg-emerald-600 text-white rounded-lg text-xs font-bold">
                        🌐 پنل ترجمه
                    </button>
                </div>
            </div>

            <!-- راهنمای نوار باریک بالای بوم مقاله -->
            <div id="paper-hint-bar" class="hidden bg-indigo-950/90 text-indigo-200 px-3 py-1 text-[11px] font-bold border-b border-indigo-800/60 items-center justify-between shrink-0">
                <span>💡 روی هر بند (پاراگراف) یا عنوان از مقاله کلیک کنید تا بلافاصله در ستون کناری به فارسی ترجمه شود.</span>
                <span id="detected-blocks-badge" class="text-[10px] bg-indigo-800/80 text-white px-2 py-0.5 rounded-full">0 بند تشخیص داده شد</span>
            </div>

            <!-- ناحیه اسکرول و رندر بوم PDF مقاله -->
            <div id="paper-viewport-container" class="flex-1 overflow-auto p-1.5 sm:p-4 flex justify-center items-start custom-scroll relative">
                
                <!-- لودینگ بارگذاری مقاله -->
                <div id="paper-loading-overlay" class="hidden my-auto text-center bg-slate-900/95 text-white p-6 rounded-2xl shadow-xl border border-slate-700 z-30">
                    <div class="text-3xl mb-2 animate-bounce">⏳</div>
                    <div class="text-xs font-bold text-indigo-300">در حال بارگذاری مقاله و استخراج هوشمند بندهای متن...</div>
                </div>

                <!-- وضعیت خالی قبل از انتخاب مقاله -->
                <div id="paper-empty-state" class="my-auto text-center bg-slate-900/90 text-white p-6 rounded-2xl shadow-xl max-w-sm border border-slate-700">
                    <div class="text-4xl mb-2">🌐</div>
                    <h3 class="font-extrabold text-sm mb-1">محيط مقاله‌خوان و مترجم هوشمند</h3>
                    <p class="text-slate-400 text-xs mb-4 leading-relaxed">
                        یک مقاله انگلیسی (PDF) آپلود کنید یا از بخش جزوه‌خوان وارد نمایید؛ سپس با کلیک روی هر پاراگراف، ترجمه فارسی روان و علمی آن را ببینید.
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

                <!-- بوم رندر PDF + لایه باکس‌های تعاملی پاراگراف‌ها (کاملاً ایزوله با dir="ltr") -->
                <div id="paper-canvas-wrapper" dir="ltr" class="hidden relative shadow-2xl bg-white">
                    <canvas id="paper-render-canvas" dir="ltr" class="block"></canvas>
                    <!-- لایه شفاف تشخیص و کلیک روی بندهای مقاله -->
                    <div id="paper-paragraphs-layer" dir="ltr" class="absolute inset-0"></div>
                </div>
            </div>
        </section>

        <!-- ج) پنل ترجمه و فیش‌برداری (ستون ثابت در دسکتاپ + پنجره کشویی Bottom-Sheet در موبایل) -->
        <aside id="translator-panel" class="w-full md:w-96 lg:w-[420px] bg-white border-r border-slate-200 flex flex-col shrink-0 z-40 fixed md:static inset-x-0 bottom-0 max-h-[82vh] md:max-h-none rounded-t-3xl md:rounded-none transform translate-y-full md:translate-y-0 transition-transform duration-200 shadow-2xl md:shadow-none">
            
            <!-- هدر پنل ترجمه و تب‌ها (مترجم زنده / دفترچه فیش‌ها) -->
            <div class="bg-slate-900 text-white px-3.5 py-2.5 flex items-center justify-between border-b border-slate-800 rounded-t-3xl md:rounded-none shrink-0">
                <div class="flex bg-slate-800 p-0.5 rounded-xl border border-slate-700 text-xs">
                    <button type="button" id="tab-btn-translate" onclick="switchTranslatorTab('translate')" class="px-3 py-1 rounded-lg font-extrabold bg-indigo-600 text-white transition">
                        🌐 ترجمه زنده
                    </button>
                    <button type="button" id="tab-btn-notes" onclick="switchTranslatorTab('notes')" class="px-3 py-1 rounded-lg font-bold text-slate-300 hover:text-white transition flex items-center gap-1">
                        <span>📌 فیش‌های مقاله</span>
                        <span id="notes-count-badge" class="bg-slate-700 text-amber-300 px-1.5 py-0.2 rounded-full text-[10px]">0</span>
                    </button>
                </div>

                <div class="flex items-center gap-1.5">
                    <button type="button" onclick="toggleByokBox()" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 text-amber-300 border border-slate-700 rounded-lg text-[11px] font-bold" title="تنظیم کلید API هوش مصنوعی شخصی">
                        🔑 کلید AI
                    </button>
                    <button type="button" onclick="closeMobileTranslatorSheet()" class="md:hidden text-slate-400 hover:text-white text-xl font-bold px-1 leading-none">&times;</button>
                </div>
            </div>

            <!-- باکس تنظیم کلید API شخصی کاربر (BYOK - ذخیره در مرورگر کاربر) -->
            <div id="byok-settings-box" class="hidden bg-slate-800 text-white p-3 border-b border-slate-700 text-xs space-y-2 shrink-0">
                <div class="flex items-center justify-between">
                    <span class="font-extrabold text-amber-300 text-[11px]">🔑 کلید API هوش مصنوعی شخصی (اختیاری)</span>
                    <button type="button" onclick="toggleByokBox()" class="text-slate-400 hover:text-white">&times;</button>
                </div>
                <p class="text-[10px] text-slate-300 leading-relaxed">
                    می‌توانید کلید رایگان Google Gemini یا OpenAI خود را وارد کنید تا بدون محدودیت از ترجمه تخصصی هوش مصنوعی استفاده نمایید:
                </p>
                <div class="flex gap-1.5">
                    <select id="user-custom-provider" class="px-2 py-1.5 rounded-lg bg-slate-900 border border-slate-600 text-xs font-bold">
                        <option value="gemini">Gemini</option>
                        <option value="openai">OpenAI</option>
                    </select>
                    <input type="password" id="user-custom-api-key" dir="ltr" placeholder="AIzaSy... یا sk-..." class="flex-1 px-2.5 py-1.5 rounded-lg bg-slate-900 border border-slate-600 text-left font-mono text-xs">
                    <button type="button" onclick="saveUserCustomApiKey()" class="bg-emerald-600 hover:bg-emerald-500 text-white px-3 py-1.5 rounded-lg font-bold">ذخیره</button>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- تب ۱: مترجم زنده بندهای مقاله -->
            <!-- ========================================== -->
            <div id="panel-tab-translate" class="flex-1 flex flex-col overflow-y-auto p-3.5 space-y-3 custom-scroll text-xs">
                
                <!-- انتخاب موتور ترجمه (Google / MyMemory / هوش مصنوعی VIP) -->
                <div>
                    <label class="block font-extrabold text-slate-700 mb-1.5 text-[11px]">⚙️ انتخاب موتور مترجم:</label>
                    <div class="grid grid-cols-3 gap-1.5">
                        <button type="button" onclick="selectTranslationEngine('google')" id="eng-btn-google" class="eng-btn py-2 px-2 rounded-xl font-extrabold border text-[11px] bg-indigo-600 text-white border-indigo-600 shadow-2xs transition">
                            ⚡ Google
                            <span class="block text-[9px] font-normal opacity-90">سریع و رایگان</span>
                        </button>
                        <button type="button" onclick="selectTranslationEngine('mymemory')" id="eng-btn-mymemory" class="eng-btn py-2 px-2 rounded-xl font-bold border text-[11px] bg-slate-50 text-slate-700 border-slate-200 hover:border-indigo-300 transition">
                            🌐 MyMemory
                            <span class="block text-[9px] font-normal text-slate-500">رایگان عمومی</span>
                        </button>
                        <button type="button" onclick="selectTranslationEngine('ai')" id="eng-btn-ai" class="eng-btn py-2 px-2 rounded-xl font-bold border text-[11px] bg-slate-50 text-slate-700 border-slate-200 hover:border-indigo-300 transition relative">
                            🧠 هوش مصنوعی
                            <span id="ai-engine-sublabel" class="block text-[9px] font-extrabold text-amber-600">👑 آکادمیک VIP</span>
                        </button>
                    </div>
                </div>

                <!-- کادر متن انگلیسی انتخاب شده (همراه با تلفظ صوتی و امکان ویرایش/تایپ دستی) -->
                <div class="bg-slate-50 rounded-2xl p-3 border border-slate-200 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="font-extrabold text-slate-700 text-[11px]">🇬🇧 متن انگلیسی انتخاب‌شده:</span>
                        <div class="flex items-center gap-1">
                            <button type="button" onclick="speakEnglishText()" class="px-2 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg font-bold text-[11px] flex items-center gap-1 transition" title="تلفظ صوتی متن انگلیسی">
                                <span>🔊 تلفظ</span>
                            </button>
                            <button type="button" onclick="translateCurrentSourceText()" class="px-2.5 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-bold text-[11px] transition">
                                🔄 ترجمه مجدد
                            </button>
                        </div>
                    </div>
                    <textarea id="source-english-textarea" dir="ltr" rows="4" placeholder="روی یکی از بندهای مقاله کلیک کنید یا هر عبارت/لغت انگلیسی را اینجا بنویسید..." class="w-full p-2.5 bg-white border border-slate-200 rounded-xl text-left font-sans text-xs text-slate-800 leading-relaxed focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                </div>

                <!-- کادر خروجی ترجمه فارسی -->
                <div class="flex-1 flex flex-col bg-indigo-50/50 rounded-2xl p-3.5 border border-indigo-100 min-h-[180px]">
                    <div class="flex items-center justify-between border-b border-indigo-100 pb-2 mb-2.5">
                        <span class="font-extrabold text-indigo-950 text-xs flex items-center gap-1">
                            <span>🇮🇷 ترجمه فارسی:</span>
                            <span id="translation-meta-badge" class="text-[10px] font-bold bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full hidden"></span>
                        </span>
                        <button type="button" onclick="copyTranslatedText()" class="text-[11px] text-indigo-600 hover:underline font-bold">
                            📋 کپی ترجمه
                        </button>
                    </div>

                    <!-- لودینگ ترجمه -->
                    <div id="translation-loading" class="hidden my-auto text-center py-6 space-y-2">
                        <div class="inline-block w-6 h-6 border-2 border-indigo-600 border-t-transparent rounded-full animate-spin"></div>
                        <div class="text-indigo-700 font-bold text-xs">در حال ترجمه بند انتخابی...</div>
                    </div>

                    <!-- متن ترجمه‌شده -->
                    <div id="translated-persian-output" class="flex-1 text-slate-800 text-xs md:text-sm leading-7 font-medium whitespace-pre-line select-text">
                        برای شروع، روی هر پاراگراف یا جمله‌ای از مقاله در صفحه مقابل کلیک کنید تا ترجمه فارسی آن اینجا نمایش داده شود.
                    </div>

                    <!-- دکمه ذخیره در فیش‌های مقاله -->
                    <div id="save-note-action-bar" class="hidden pt-3 mt-3 border-t border-indigo-200/60 space-y-2">
                        <input type="text" id="note-user-comment" placeholder="یادداشت شخصی شما درباره این بند (اختیاری)..." class="w-full px-2.5 py-1.5 bg-white border border-indigo-200 rounded-xl text-xs">
                        <button type="button" onclick="saveCurrentTranslationAsNote()" class="w-full py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-extrabold text-xs shadow-xs transition flex items-center justify-center gap-1">
                            <span>📌 ذخیره این بند و ترجمه در فیش‌های مقاله</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- تب ۲: دفترچه فیش‌های ذخیره‌شده مقاله -->
            <!-- ========================================== -->
            <div id="panel-tab-notes" class="hidden flex-1 flex-col overflow-hidden p-3.5 text-xs">
                <div class="flex items-center justify-between pb-2.5 mb-2.5 border-b border-slate-200 shrink-0">
                    <span class="font-extrabold text-slate-800">📑 فیش‌های پژوهشی این مقاله</span>
                    <button type="button" onclick="copyAllPaperNotes()" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 px-2.5 py-1 rounded-lg font-bold text-[11px]">
                        📋 کپی یکجای تمام فیش‌ها
                    </button>
                </div>
                <div id="paper-notes-list" class="flex-1 overflow-y-auto space-y-2.5 custom-scroll"></div>
            </div>

        </aside>

    </main>

    <!-- ==================================================== -->
    <!-- ۳. مودال آپلود مقاله انگلیسی جدید (در پوشه uploads/papers) -->
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
    <!-- ۴. مودال افزودن فایل از بخش «جزوه‌خوان» به «مقاله‌خوان» -->
    <!-- ==================================================== -->
    <div id="import-booklet-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center p-4 z-50">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
            <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between">
                <h3 class="font-bold text-xs md:text-sm">📥 انتخاب فایل از بخش جزوه‌خوان</h3>
                <button type="button" onclick="closeImportBookletModal()" class="text-slate-400 hover:text-white text-xl leading-none">&times;</button>
            </div>
            <div class="p-4 space-y-3 text-xs">
                <p class="text-slate-600 leading-relaxed">
                    می‌توانید هر یک از فایل‌های PDF که قبلاً در بخش «جزوه‌خوان» آپلود کرده‌اید را با یک کلیک به محیط «مقاله‌خوان» اضافه کنید:
                </p>
                <div id="booklets-import-list" class="max-h-64 overflow-y-auto space-y-2 custom-scroll"></div>
            </div>
        </div>
    </div>

    <!-- ==================================================== -->
    <!-- ۵. مودال خرید و تمدید اشتراک ویژه (VIP) -->
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
                                <li>✓ دسترسی به مترجم تخصصی هوش مصنوعی (Academic AI)</li>
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
                                <li>✓ مترجم تخصصی هوش مصنوعی + فیش‌برداری نامحدود</li>
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

    <script>
        // ====================================================
        // ۰. بارگذاری تضمین‌شده موتور PDF.js با پشتیبان چندگانه
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
            throw new Error('موتور نمایشگر PDF بارگذاری نشد. لطفاً صفحه را رفرش کنید.');
        }

        if (typeof window.pdfjsLib !== 'undefined') {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = activeCdnConfig.worker;
        }

        // ====================================================
        // متغیرهای پایه مقاله‌خوان و وضعیت اشتراک
        // ====================================================
        const TELEGRAM_SALES_ID = "<?php echo htmlspecialchars(TELEGRAM_SALES_ID); ?>";
        const FREE_MAX_PDF_CNT  = <?php echo (int)FREE_MAX_PDF_COUNT; ?>;
        const FREE_MAX_PDF_MB   = <?php echo (int)FREE_MAX_PDF_MB; ?>;
        const VIP_MAX_PDF_MB    = <?php echo (int)VIP_MAX_PDF_MB; ?>;

        let userSub      = <?php echo json_encode($initial_sub, JSON_UNESCAPED_UNICODE); ?>;
        let aiConfig     = { enabled: <?php echo AI_TRANSLATION_ENABLED ? 'true' : 'false'; ?>, vip_only: <?php echo AI_VIP_ONLY ? 'true' : 'false'; ?> };
        let papersList   = [];
        let bookletsList = [];

        let activePaperMeta   = null;
        let paperDocInstance  = null;
        let activeRenderTask  = null;
        let currentPaperPage  = 1;
        let paperZoomMode     = 'fit-width';
        let paperCustomScale  = 1.0;
        let isRenderingPaper  = false;
        let pendingPaperPage  = null;
        let isPaperDarkMode   = false;

        let selectedEngine    = 'google'; // 'google', 'mymemory', 'ai'
        let lastTranslatedObj = null;     // { original, translated }
        let paperNotes        = [];

        // ====================================================
        // دریافت لیست مقالات و جزوات از سرور
        // ====================================================
        async function fetchPapersAndConfig() {
            try {
                const res = await fetch('api_translate.php?action=list_papers');
                const data = await res.json();
                if (data.status === 'success') {
                    papersList   = data.papers || [];
                    bookletsList = data.booklets || [];
                    if (data.subscription) userSub = data.subscription;
                    if (data.ai_config)    aiConfig = data.ai_config;

                    refreshSubscriptionUI();
                    renderPapersSidebar();
                }
            } catch (err) {
                console.error('Error fetching papers:', err);
            }
        }

        function refreshSubscriptionUI() {
            const totalUserFiles = papersList.length + bookletsList.length;
            const banner = document.getElementById('paper-quota-banner');
            if (banner) {
                if (userSub.is_vip) {
                    banner.className = 'px-3 py-1.5 text-[11px] border-b border-emerald-200 bg-emerald-50 text-emerald-900 font-bold flex items-center justify-between';
                    banner.innerHTML = `<span>👑 اشتراک ویژه فعال (${userSub.days_left} روز)</span><button onclick="openSubscriptionModal()" class="text-indigo-700 underline">+تمدید</button>`;
                } else {
                    banner.className = 'px-3 py-1.5 text-[11px] border-b border-amber-200 bg-amber-50 text-amber-900 font-bold flex items-center justify-between';
                    banner.innerHTML = `<span>👤 سهمیه رایگان: ${totalUserFiles} از ${FREE_MAX_PDF_CNT} فایل</span><button onclick="openSubscriptionModal()" class="bg-indigo-600 text-white px-2 py-0.5 rounded text-[10px]">ارتقا 👑</button>`;
                }
            }

            // اگر موتور هوش مصنوعی در config.php غیرفعال شده باشد و کاربر کلید شخصی هم نداده باشد، دکمه AI مخفی یا اطلاع‌رسانی شود
            const aiBtn = document.getElementById('eng-btn-ai');
            const customKey = localStorage.getItem('user_custom_ai_key') || '';
            if (aiBtn) {
                if (!aiConfig.enabled && !customKey) {
                    aiBtn.classList.add('opacity-50');
                    document.getElementById('ai-engine-sublabel').textContent = 'نیاز به کلید 🔑';
                } else {
                    aiBtn.classList.remove('opacity-50');
                    document.getElementById('ai-engine-sublabel').textContent = customKey ? '🔑 کلید شخصی' : (userSub.is_vip ? '✨ فعال (VIP)' : '🔒 ویژه VIP');
                }
            }
        }

        function renderPapersSidebar() {
            const container = document.getElementById('papers-list-container');
            if (papersList.length === 0) {
                container.innerHTML = `
                    <div class="text-center text-slate-400 text-xs py-10 px-3 space-y-2">
                        <div>هنوز مقاله‌ای در پوشه مقالات ندارید.</div>
                        <div class="text-[11px]">می‌توانید مقاله جدید آپلود کنید یا از فایل‌های جزوه‌خوان وارد نمایید.</div>
                    </div>
                `;
                return;
            }

            container.innerHTML = papersList.map(item => {
                const isActive = activePaperMeta && Number(activePaperMeta.id) === Number(item.id);
                const total = Math.max(1, Number(item.total_pages));
                const last  = Math.min(total, Math.max(1, Number(item.last_page)));
                const isLocked = !!item.is_locked;

                return `
                    <div onclick="loadAndOpenPaper(${item.id}, ${last})" class="p-3 rounded-xl border transition cursor-pointer ${isLocked ? 'border-amber-300 bg-amber-50/50 opacity-80' : (isActive ? 'border-indigo-600 bg-indigo-50/80 ring-1 ring-indigo-500' : 'border-slate-200 hover:border-indigo-300 bg-white')}">
                        <div class="flex items-start justify-between gap-1.5">
                            <div class="min-w-0">
                                <h4 class="text-xs font-extrabold text-slate-800 leading-snug truncate" dir="auto">${isLocked ? '🔒 ' : '📄 '}${escapeHtml(item.title)}</h4>
                                ${isLocked ? `<span class="inline-block mt-1 text-[10px] bg-amber-200 text-amber-950 px-1.5 py-0.5 rounded font-bold">نیاز به تمدید اشتراک</span>` : ''}
                            </div>
                            <button type="button" onclick="deletePaperItem(event, ${item.id})" class="text-slate-400 hover:text-rose-600 text-xs px-1 shrink-0" title="حذف مقاله">🗑️</button>
                        </div>
                        <div class="mt-2 flex justify-between text-[10px] text-slate-500">
                            <span>صفحه ${last} از ${total}</span>
                            <span class="text-indigo-600 font-bold">باز کردن ❮</span>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // ====================================================
        // بارگذاری باینری مقاله و استخراج هوشمند بلوک‌های پاراگراف
        // ====================================================
        async function loadAndOpenPaper(pdfId, targetPage = null) {
            if (papersList.length === 0) await fetchPapersAndConfig();
            const target = papersList.find(p => Number(p.id) === Number(pdfId));
            if (!target) return;

            if (target.is_locked) {
                openSubscriptionModal(`مقاله «${target.title}» به دلیل اتمام مهلت اشتراک ویژه قفل شده است. لطفاً اشتراک خود را تمدید کنید.`);
                return;
            }

            activePaperMeta = target;
            closePaperSidebarMobile();

            document.getElementById('active-paper-title').textContent = activePaperMeta.title;
            document.getElementById('paper-empty-state').classList.add('hidden');
            document.getElementById('paper-canvas-wrapper').classList.add('hidden');
            document.getElementById('paper-loading-overlay').classList.remove('hidden');

            try {
                const pdfjs = await ensurePdfJsReady();

                const rawResponse = await fetch(`api_translate.php?action=raw&pdf_id=${activePaperMeta.id}&t=${Date.now()}`, {
                    cache: 'no-store',
                    credentials: 'same-origin'
                });

                if (rawResponse.status === 403) {
                    document.getElementById('paper-loading-overlay').classList.add('hidden');
                    document.getElementById('paper-empty-state').classList.remove('hidden');
                    openSubscriptionModal('این مقاله نیازمند اشتراک ویژه است.');
                    return;
                }

                if (!rawResponse.ok) {
                    throw new Error(`خطا در دریافت فایل مقاله (کد ${rawResponse.status})`);
                }

                const arrayBuffer = await rawResponse.arrayBuffer();
                const pdfBytes = new Uint8Array(arrayBuffer);

                if (paperDocInstance && typeof paperDocInstance.destroy === 'function') {
                    await paperDocInstance.destroy();
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

                paperDocInstance = await loadingTask.promise;

                const totalPages = paperDocInstance.numPages;
                activePaperMeta.total_pages = totalPages;
                document.getElementById('paper-total-pages').textContent = totalPages;
                document.getElementById('paper-page-input').max = totalPages;

                currentPaperPage = targetPage
                    ? Math.min(totalPages, Math.max(1, Number(targetPage)))
                    : Math.min(totalPages, Math.max(1, Number(activePaperMeta.last_page)));

                document.getElementById('paper-loading-overlay').classList.add('hidden');
                document.getElementById('paper-canvas-wrapper').classList.remove('hidden');
                document.getElementById('paper-page-controls').classList.remove('hidden');
                document.getElementById('paper-page-controls').classList.add('flex');
                document.getElementById('paper-hint-bar').classList.remove('hidden');
                document.getElementById('paper-hint-bar').classList.add('flex');

                renderPapersSidebar();
                fetchPaperNotes(activePaperMeta.id);
                await renderPaperPage(currentPaperPage);
            } catch (err) {
                document.getElementById('paper-loading-overlay').classList.add('hidden');
                document.getElementById('paper-empty-state').classList.remove('hidden');
                alert('خطا در باز کردن مقاله: ' + err.message);
            }
        }

        async function renderPaperPage(num) {
            if (!paperDocInstance) return;

            if (isRenderingPaper) {
                pendingPaperPage = num;
                if (activeRenderTask && typeof activeRenderTask.cancel === 'function') {
                    try { activeRenderTask.cancel(); } catch (e) {}
                }
                return;
            }
            isRenderingPaper = true;

            try {
                const page = await paperDocInstance.getPage(num);
                const container = document.getElementById('paper-viewport-container');
                const canvas = document.getElementById('paper-render-canvas');
                const paraLayer = document.getElementById('paper-paragraphs-layer');
                const ctx = canvas.getContext('2d');

                const unscaledViewport = page.getViewport({ scale: 1 });
                let scale = paperCustomScale;
                if (paperZoomMode === 'fit-width') {
                    const pad = window.innerWidth < 640 ? 10 : 32;
                    const availableWidth = Math.max(280, container.clientWidth - pad);
                    scale = Math.min(availableWidth / unscaledViewport.width, 1.85);
                    paperCustomScale = scale;
                }

                const viewport = page.getViewport({ scale: scale });
                const maxSafeDpr = window.innerWidth < 768 ? 1.75 : 2.0;
                let outputScale = Math.min(window.devicePixelRatio || 1, maxSafeDpr);

                const maxCanvasDim = 3800;
                if (viewport.width * outputScale > maxCanvasDim || viewport.height * outputScale > maxCanvasDim) {
                    outputScale = Math.min(maxCanvasDim / viewport.width, maxCanvasDim / viewport.height);
                }

                const cssWidth  = Math.floor(viewport.width);
                const cssHeight = Math.floor(viewport.height);

                canvas.width  = Math.floor(viewport.width * outputScale);
                canvas.height = Math.floor(viewport.height * outputScale);
                canvas.style.width  = cssWidth + "px";
                canvas.style.height = cssHeight + "px";

                paraLayer.style.width  = cssWidth + "px";
                paraLayer.style.height = cssHeight + "px";
                paraLayer.innerHTML    = '';

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

                document.getElementById('paper-page-input').value = num;

                // استخراج متن و ساخت باکس‌های تعاملی پاراگراف‌ها روی صفحه
                await buildInteractiveParagraphBlocks(page, viewport);

                // ذخیره آخرین صفحه مطالعه‌شده در دیتابیس
                savePaperProgress(activePaperMeta.id, num, paperDocInstance.numPages);
            } catch (err) {
                if (err && err.name !== 'RenderingCancelledException') {
                    console.error('Paper render error:', err);
                }
            } finally {
                isRenderingPaper = false;
                if (pendingPaperPage !== null) {
                    const next = pendingPaperPage;
                    pendingPaperPage = null;
                    renderPaperPage(next);
                }
            }
        }

        // ====================================================
        // الگوریتم خوشه‌بندی هوشمند سطرها به پاراگراف (پشتیبانی از مقالات ۱ و ۲ ستونه)
        // ====================================================
        async function buildInteractiveParagraphBlocks(page, viewport) {
            const paraLayer = document.getElementById('paper-paragraphs-layer');
            paraLayer.innerHTML = '';

            const textContent = await page.getTextContent();
            const rawItems = textContent.items || [];
            const fragments = [];

            rawItems.forEach(item => {
                const str = (item.str || '').trim();
                if (!str) return;

                const tx = window.pdfjsLib.Util.transform(viewport.transform, item.transform);
                const fontHeight = Math.max(8, Math.hypot(tx[2], tx[3]));
                const x = tx[4];
                const y = tx[5] - fontHeight;
                const w = Math.max(fontHeight * 0.5, (item.width || 0) * viewport.scale);
                const h = fontHeight * 1.18;

                if (x >= -10 && y >= -10 && x <= viewport.width + 10 && y <= viewport.height + 10) {
                    fragments.push({
                        str: item.str,
                        x: x,
                        y: y,
                        right: x + w,
                        bottom: y + h,
                        w: w,
                        h: h,
                        fontH: fontHeight
                    });
                }
            });

            if (fragments.length === 0) {
                document.getElementById('detected-blocks-badge').textContent = 'صفحه تصویری (اسکن‌شده)';
                return;
            }

            // گام ۱: مرتب‌سازی قطعات متن از بالا به پایین و از چپ به راست
            fragments.sort((a, b) => (Math.abs(a.y - b.y) <= Math.min(a.fontH, b.fontH) * 0.45) ? (a.x - b.x) : (a.y - b.y));

            // گام ۲: ترکیب قطعات هم‌تراز در یک سطر (با رعایت فاصله افقی برای عدم ادغام دو ستون مقاله!)
            const lines = [];
            const maxHorizGapInLine = viewport.width * 0.055;

            fragments.forEach(frag => {
                let addedToLine = false;
                for (let i = lines.length - 1; i >= Math.max(0, lines.length - 6); i--) {
                    const ln = lines[i];
                    const sameVertical = Math.abs(frag.y - ln.y) <= Math.min(frag.fontH, ln.fontH) * 0.55;
                    const horizGap = frag.x - ln.right;

                    if (sameVertical && horizGap >= -15 && horizGap <= maxHorizGapInLine) {
                        const needSpace = (horizGap > ln.fontH * 0.12 && !ln.text.endsWith(' ') && !frag.str.startsWith(' '));
                        ln.text += (needSpace ? ' ' : '') + frag.str;
                        ln.x = Math.min(ln.x, frag.x);
                        ln.y = Math.min(ln.y, frag.y);
                        ln.right = Math.max(ln.right, frag.right);
                        ln.bottom = Math.max(ln.bottom, frag.bottom);
                        ln.fontH = (ln.fontH + frag.fontH) / 2;
                        addedToLine = true;
                        break;
                    }
                }

                if (!addedToLine) {
                    lines.push({
                        text: frag.str,
                        x: frag.x,
                        y: frag.y,
                        right: frag.right,
                        bottom: frag.bottom,
                        fontH: frag.fontH
                    });
                }
            });

            // گام ۳: گروه‌بندی سطرهای هم‌ستون در «بلوک‌های پاراگراف»
            lines.sort((a, b) => {
                // تفکیک ستون چپ و راست در مقالات دو ستونه
                const colA = (a.x > viewport.width * 0.46) ? 1 : 0;
                const colB = (b.x > viewport.width * 0.46) ? 1 : 0;
                if (colA !== colB) return colA - colB;
                return a.y - b.y;
            });

            const paragraphs = [];
            lines.forEach(ln => {
                const cleanLineText = ln.text.trim();
                if (!cleanLineText) return;

                let merged = false;
                if (paragraphs.length > 0) {
                    const prev = paragraphs[paragraphs.length - 1];
                    const verticalGap = ln.y - prev.bottom;
                    const maxAllowedVertGap = Math.max(prev.fontH, ln.fontH) * 0.85;

                    // بررسی هم‌ستون بودن و مشابه بودن سایز فونت (جلوگیری از ادغام تیتر با پاراگراف)
                    const overlapLeft  = Math.max(prev.x, ln.x);
                    const overlapRight = Math.min(prev.right, ln.right);
                    const hasHorizOverlap = (overlapRight - overlapLeft) > Math.min(prev.right - prev.x, ln.right - ln.x) * 0.25;
                    const leftAlignedClose = Math.abs(prev.x - ln.x) <= viewport.width * 0.08;
                    const similarFont = Math.abs(prev.fontH - ln.fontH) <= 2.2;

                    if (verticalGap >= -6 && verticalGap <= maxAllowedVertGap && (hasHorizOverlap || leftAlignedClose) && similarFont) {
                        // اگر سطر قبلی با خط تیره تمام شده، خط تیره را حذف و کلمه را بچسبان
                        if (prev.text.endsWith('-')) {
                            prev.text = prev.text.slice(0, -1) + cleanLineText;
                        } else {
                            prev.text += ' ' + cleanLineText;
                        }
                        prev.x = Math.min(prev.x, ln.x);
                        prev.y = Math.min(prev.y, ln.y);
                        prev.right = Math.max(prev.right, ln.right);
                        prev.bottom = Math.max(prev.bottom, ln.bottom);
                        merged = true;
                    }
                }

                if (!merged) {
                    paragraphs.push({
                        text: cleanLineText,
                        x: ln.x,
                        y: ln.y,
                        right: ln.right,
                        bottom: ln.bottom,
                        fontH: ln.fontH
                    });
                }
            });

            // گام ۴: رندر باکس‌های قابل کلیک روی صفحه مقاله
            let validCount = 0;
            paragraphs.forEach((p, idx) => {
                if (p.text.length < 3) return;
                validCount++;

                const div = document.createElement('div');
                div.className = 'para-hotspot';
                const pad = 3;
                div.style.left   = Math.max(0, Math.floor(p.x - pad)) + 'px';
                div.style.top    = Math.max(0, Math.floor(p.y - pad)) + 'px';
                div.style.width  = Math.floor((p.right - p.x) + (pad * 2)) + 'px';
                div.style.height = Math.floor((p.bottom - p.y) + (pad * 2)) + 'px';
                div.title = 'کلیک برای ترجمه فارسی این بند';

                div.onclick = (e) => {
                    e.stopPropagation();
                    document.querySelectorAll('.para-hotspot').forEach(el => el.classList.remove('active-para'));
                    div.classList.add('active-para');
                    onParagraphSelected(p.text);
                };

                paraLayer.appendChild(div);
            });

            document.getElementById('detected-blocks-badge').textContent = `${validCount} بند قابل کلیک`;
        }

        // ====================================================
        // اجرای ترجمه هنگام کلیک روی هر بند از مقاله
        // ====================================================
        function onParagraphSelected(englishText) {
            const textarea = document.getElementById('source-english-textarea');
            textarea.value = englishText;

            switchTranslatorTab('translate');
            if (window.innerWidth < 768) {
                openMobileTranslatorSheet();
            }

            translateCurrentSourceText();
        }

        function selectTranslationEngine(engine) {
            selectedEngine = engine;
            ['google', 'mymemory', 'ai'].forEach(eng => {
                const btn = document.getElementById('eng-btn-' + eng);
                if (!btn) return;
                if (eng === engine) {
                    btn.className = 'eng-btn py-2 px-2 rounded-xl font-extrabold border text-[11px] bg-indigo-600 text-white border-indigo-600 shadow-2xs transition relative';
                } else {
                    btn.className = 'eng-btn py-2 px-2 rounded-xl font-bold border text-[11px] bg-slate-50 text-slate-700 border-slate-200 hover:border-indigo-300 transition relative';
                }
            });

            // اگر متنی در کادر وجود دارد، بلافاصله با موتور جدید ترجمه شود
            const currentTxt = document.getElementById('source-english-textarea').value.trim();
            if (currentTxt) {
                translateCurrentSourceText();
            }
        }

        async function translateCurrentSourceText() {
            const sourceText = document.getElementById('source-english-textarea').value.trim();
            if (!sourceText) {
                alert('لطفاً ابتدا روی یکی از بندهای مقاله کلیک کنید یا متنی را در کادر انگلیسی بنویسید.');
                return;
            }

            const loadingEl = document.getElementById('translation-loading');
            const outputEl  = document.getElementById('translated-persian-output');
            const metaBadge = document.getElementById('translation-meta-badge');
            const saveBar   = document.getElementById('save-note-action-bar');

            loadingEl.classList.remove('hidden');
            outputEl.classList.add('hidden');
            saveBar.classList.add('hidden');
            metaBadge.classList.add('hidden');

            try {
                const fd = new FormData();
                fd.append('action', 'translate');
                fd.append('text', sourceText);
                fd.append('engine', selectedEngine);

                // ارسال کلید شخصی کاربر در صورتی که در مرورگرش ذخیره کرده باشد
                const customKey  = localStorage.getItem('user_custom_ai_key') || '';
                const customProv = localStorage.getItem('user_custom_ai_provider') || 'gemini';
                if (customKey) {
                    fd.append('custom_api_key', customKey);
                    fd.append('custom_provider', customProv);
                }

                const res = await fetch('api_translate.php', { method: 'POST', body: fd });
                const data = await res.json();

                loadingEl.classList.add('hidden');
                outputEl.classList.remove('hidden');

                if (data.status === 'success') {
                    outputEl.textContent = data.translated_text;
                    lastTranslatedObj = {
                        original: data.clean_source || sourceText,
                        translated: data.translated_text
                    };

                    metaBadge.textContent = `${data.engine_used}${data.cached ? ' • کش فوری ⚡' : ''}`;
                    metaBadge.classList.remove('hidden');
                    if (activePaperMeta) {
                        saveBar.classList.remove('hidden');
                    }
                } else if (data.need_vip) {
                    outputEl.textContent = data.message;
                    openSubscriptionModal(data.message);
                } else {
                    outputEl.textContent = '⚠️ ' + (data.message || 'خطا در ترجمه متن.');
                }
            } catch (err) {
                loadingEl.classList.add('hidden');
                outputEl.classList.remove('hidden');
                outputEl.textContent = 'خطا در ارتباط با سرور ترجمه.';
            }
        }

        // تلفظ صوتی متن انگلیسی با موتور داخلی مرورگر (Web Speech API)
        function speakEnglishText() {
            const text = document.getElementById('source-english-textarea').value.trim();
            if (!text) return;
            if (!('speechSynthesis' in window)) {
                alert('مرورگر شما از تلفظ صوتی پشتیبانی نمی‌کند.');
                return;
            }
            window.speechSynthesis.cancel();
            const utter = new SpeechSynthesisUtterance(text);
            utter.lang = 'en-US';
            utter.rate = 0.92;
            window.speechSynthesis.speak(utter);
        }

        function copyTranslatedText() {
            const text = document.getElementById('translated-persian-output').textContent.trim();
            if (!text) return;
            navigator.clipboard.writeText(text).then(() => {
                alert('ترجمه فارسی کپی شد.');
            });
        }

        // ====================================================
        // مدیریت فیش‌های پژوهشی مقاله (ذخیره، مشاهده و کپی یکجا)
        // ====================================================
        async function fetchPaperNotes(pdfId) {
            if (!pdfId) return;
            const res = await fetch(`api_translate.php?action=list_notes&pdf_id=${pdfId}`);
            const data = await res.json();
            if (data.status === 'success') {
                paperNotes = data.notes || [];
                document.getElementById('notes-count-badge').textContent = paperNotes.length;
                renderPaperNotesList();
            }
        }

        async function saveCurrentTranslationAsNote() {
            if (!activePaperMeta || !lastTranslatedObj) return;
            const comment = document.getElementById('note-user-comment').value.trim();

            const fd = new FormData();
            fd.append('action', 'save_note');
            fd.append('pdf_id', activePaperMeta.id);
            fd.append('page_number', currentPaperPage);
            fd.append('original_text', lastTranslatedObj.original);
            fd.append('translated_text', lastTranslatedObj.translated);
            fd.append('user_comment', comment);

            const res = await fetch('api_translate.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                document.getElementById('note-user-comment').value = '';
                await fetchPaperNotes(activePaperMeta.id);
                alert('📌 این بند با موفقیت در فیش‌های مقاله ذخیره شد.');
            } else {
                alert(data.message || 'خطا در ذخیره فیش');
            }
        }

        function renderPaperNotesList() {
            const listEl = document.getElementById('paper-notes-list');
            if (paperNotes.length === 0) {
                listEl.innerHTML = `<div class="text-center text-slate-400 py-10">هنوز فیشی برای این مقاله ذخیره نکرده‌اید.</div>`;
                return;
            }

            listEl.innerHTML = paperNotes.map(n => `
                <div class="p-3 bg-slate-50 rounded-2xl border border-slate-200 space-y-1.5">
                    <div class="flex items-center justify-between">
                        <button type="button" onclick="jumpToPaperPage(${n.page_number})" class="bg-indigo-100 text-indigo-800 px-2 py-0.5 rounded-md font-extrabold text-[10px]">
                            📄 صفحه ${n.page_number} (پرش به صفحه)
                        </button>
                        <button type="button" onclick="deletePaperNote(${n.id})" class="text-slate-400 hover:text-rose-600 text-xs">🗑️</button>
                    </div>
                    <div class="text-[11px] text-slate-500 font-sans dir-ltr text-left line-clamp-2">${escapeHtml(n.original_text)}</div>
                    <div class="text-xs text-slate-800 font-bold leading-6">${escapeHtml(n.translated_text)}</div>
                    ${n.user_comment ? `<div class="text-[11px] bg-amber-50 border border-amber-200 text-amber-900 p-1.5 rounded-lg">💡 یادداشت: ${escapeHtml(n.user_comment)}</div>` : ''}
                </div>
            `).join('');
        }

        async function deletePaperNote(noteId) {
            if (!confirm('این فیش حذف شود؟')) return;
            const fd = new FormData();
            fd.append('action', 'delete_note');
            fd.append('note_id', noteId);
            await fetch('api_translate.php', { method: 'POST', body: fd });
            if (activePaperMeta) fetchPaperNotes(activePaperMeta.id);
        }

        function copyAllPaperNotes() {
            if (paperNotes.length === 0) return;
            const text = paperNotes.map((n, i) =>
                `[${i + 1}] صفحه ${n.page_number}:\n🇬🇧 ${n.original_text}\n🇮🇷 ${n.translated_text}${n.user_comment ? '\n💡 نکته: ' + n.user_comment : ''}`
            ).join('\n\n------------------------\n\n');

            navigator.clipboard.writeText(text).then(() => {
                alert('تمام فیش‌های این مقاله کپی شدند!');
            });
        }

        // ====================================================
        // کنترل‌های صفحه، زوم، حالت شب و آپلود/ایمپورت مقاله
        // ====================================================
        function changePaperPage(delta) {
            if (!paperDocInstance) return;
            const next = currentPaperPage + delta;
            if (next >= 1 && next <= paperDocInstance.numPages) {
                currentPaperPage = next;
                renderPaperPage(currentPaperPage);
                document.getElementById('paper-viewport-container').scrollTop = 0;
            }
        }

        function jumpToPaperPage(val) {
            if (!paperDocInstance) return;
            currentPaperPage = Math.min(paperDocInstance.numPages, Math.max(1, parseInt(val) || 1));
            renderPaperPage(currentPaperPage);
            document.getElementById('paper-viewport-container').scrollTop = 0;
        }

        function adjustPaperZoom(delta) {
            if (!paperDocInstance) return;
            paperZoomMode = 'custom';
            paperCustomScale = Math.min(3.0, Math.max(0.5, paperCustomScale + delta));
            renderPaperPage(currentPaperPage);
        }

        function setPaperFitWidth() {
            if (!paperDocInstance) return;
            paperZoomMode = 'fit-width';
            renderPaperPage(currentPaperPage);
        }

        function togglePaperDarkMode() {
            isPaperDarkMode = !isPaperDarkMode;
            const canvas = document.getElementById('paper-render-canvas');
            const btn = document.getElementById('btn-paper-dark');
            if (isPaperDarkMode) {
                canvas.classList.add('pdf-dark-mode');
                btn.textContent = '☀️';
            } else {
                canvas.classList.remove('pdf-dark-mode');
                btn.textContent = '🌙';
            }
        }

        async function savePaperProgress(pdfId, lastPage, totalPages) {
            if (activePaperMeta) {
                activePaperMeta.last_page = lastPage;
                activePaperMeta.total_pages = totalPages;
                renderPapersSidebar();
            }
            const fd = new FormData();
            fd.append('action', 'save_progress');
            fd.append('pdf_id', pdfId);
            fd.append('last_page', lastPage);
            fd.append('total_pages', totalPages);
            await fetch('api_pdf.php', { method: 'POST', body: fd });
        }

        // آپلود مقاله جدید
        function openUploadPaperModal() {
            const totalFiles = papersList.length + bookletsList.length;
            if (!userSub.is_vip && totalFiles >= FREE_MAX_PDF_CNT) {
                openSubscriptionModal(`سهمیه ${FREE_MAX_PDF_CNT} فایل رایگان شما پر شده است. برای آپلود مقالات بیشتر، فایل قبلی را حذف کنید یا اشتراک ویژه تهیه نمایید.`);
                return;
            }
            document.getElementById('upload-paper-form').reset();
            const hint = document.getElementById('upload-paper-tier-hint');
            hint.textContent = userSub.is_vip
                ? `👑 اشتراک ویژه فعال: ذخیره در پوشه اختصاصی مقالات (سقف ${VIP_MAX_PDF_MB} مگابایت)`
                : `👤 حساب عادی: حداکثر ۱ فایل تا سقف ${FREE_MAX_PDF_MB} مگابایت`;

            const m = document.getElementById('upload-paper-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeUploadPaperModal() {
            const m = document.getElementById('upload-paper-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        async function handlePaperUpload(e) {
            e.preventDefault();
            const file = document.getElementById('upload-paper-file-input').files[0];
            if (!file) return;

            const maxMB = userSub.is_vip ? VIP_MAX_PDF_MB : FREE_MAX_PDF_MB;
            if (file.size > maxMB * 1024 * 1024) {
                closeUploadPaperModal();
                openSubscriptionModal(`حجم فایل انتخابی بیش از سقف مجاز (${maxMB} مگابایت) است.`);
                return;
            }

            const btn = document.getElementById('btn-submit-paper-upload');
            btn.disabled = true;
            btn.textContent = 'در حال آپلود...';

            try {
                const fd = new FormData(document.getElementById('upload-paper-form'));
                fd.append('action', 'upload_paper');
                const res = await fetch('api_translate.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.status === 'success') {
                    closeUploadPaperModal();
                    await fetchPapersAndConfig();
                    loadAndOpenPaper(data.paper.id, 1);
                } else if (data.need_vip) {
                    closeUploadPaperModal();
                    openSubscriptionModal(data.message);
                } else {
                    alert(data.message || 'خطا در آپلود مقاله');
                }
            } catch (err) {
                alert('خطا در ارتباط با سرور.');
            } finally {
                btn.disabled = false;
                btn.textContent = 'آپلود مقاله';
            }
        }

        // ایمپورت فایل از بخش جزوه‌خوان به مقاله‌خوان
        function openImportBookletModal() {
            const listEl = document.getElementById('booklets-import-list');
            if (bookletsList.length === 0) {
                listEl.innerHTML = `<div class="text-center text-slate-400 py-6">هیچ فایلی در بخش جزوه‌خوان یافت نشد.</div>`;
            } else {
                listEl.innerHTML = bookletsList.map(b => `
                    <div class="p-2.5 bg-slate-50 border border-slate-200 rounded-xl flex items-center justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-extrabold text-slate-800 truncate">${escapeHtml(b.title)}</div>
                            <div class="text-[10px] text-slate-500">${b.total_pages} صفحه</div>
                        </div>
                        <button type="button" onclick="importBookletToPapers(${b.id})" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg font-bold text-[11px] shrink-0">
                            📥 افزودن به مقالات
                        </button>
                    </div>
                `).join('');
            }
            const m = document.getElementById('import-booklet-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }

        function closeImportBookletModal() {
            const m = document.getElementById('import-booklet-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        async function importBookletToPapers(pdfId) {
            const fd = new FormData();
            fd.append('action', 'import_from_booklets');
            fd.append('pdf_id', pdfId);

            const res = await fetch('api_translate.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                closeImportBookletModal();
                await fetchPapersAndConfig();
                loadAndOpenPaper(data.paper_id, 1);
            } else {
                alert(data.message || 'خطا در افزودن فایل');
            }
        }

        async function deletePaperItem(e, pdfId) {
            e.stopPropagation();
            if (!confirm('آیا از حذف این مقاله مطمئن هستید؟')) return;
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('pdf_id', pdfId);
            await fetch('api_pdf.php', { method: 'POST', body: fd });
            if (activePaperMeta && Number(activePaperMeta.id) === Number(pdfId)) {
                location.reload();
            } else {
                fetchPapersAndConfig();
            }
        }

        // ====================================================
        // مدیریت پنل‌های موبایل، تب‌ها، کلید شخصی و مودال اشتراک
        // ====================================================
        function switchTranslatorTab(tab) {
            const trPanel = document.getElementById('panel-tab-translate');
            const ntPanel = document.getElementById('panel-tab-notes');
            const btnTr   = document.getElementById('tab-btn-translate');
            const btnNt   = document.getElementById('tab-btn-notes');

            if (tab === 'translate') {
                trPanel.classList.remove('hidden');
                ntPanel.classList.add('hidden');
                ntPanel.classList.remove('flex');
                btnTr.className = 'px-3 py-1 rounded-lg font-extrabold bg-indigo-600 text-white transition';
                btnNt.className = 'px-3 py-1 rounded-lg font-bold text-slate-300 hover:text-white transition flex items-center gap-1';
            } else {
                trPanel.classList.add('hidden');
                ntPanel.classList.remove('hidden');
                ntPanel.classList.add('flex');
                btnNt.className = 'px-3 py-1 rounded-lg font-extrabold bg-indigo-600 text-white transition flex items-center gap-1';
                btnTr.className = 'px-3 py-1 rounded-lg font-bold text-slate-300 hover:text-white transition';
            }
        }

        function openMobileTranslatorSheet() {
            document.getElementById('translator-panel').classList.remove('translate-y-full');
        }

        function closeMobileTranslatorSheet() {
            document.getElementById('translator-panel').classList.add('translate-y-full');
        }

        function togglePaperSidebar() {
            const sb = document.getElementById('paper-sidebar');
            const bd = document.getElementById('paper-sidebar-backdrop');
            if (window.innerWidth < 1024) {
                if (sb.classList.contains('translate-x-full')) {
                    sb.classList.remove('translate-x-full');
                    bd.classList.remove('hidden');
                } else {
                    closePaperSidebarMobile();
                }
            } else {
                sb.classList.toggle('hidden');
                if (paperDocInstance && paperZoomMode === 'fit-width') {
                    setTimeout(() => renderPaperPage(currentPaperPage), 50);
                }
            }
        }

        function closePaperSidebarMobile() {
            document.getElementById('paper-sidebar').classList.add('translate-x-full');
            document.getElementById('paper-sidebar-backdrop').classList.add('hidden');
        }

        function toggleByokBox() {
            const box = document.getElementById('byok-settings-box');
            box.classList.toggle('hidden');
            if (!box.classList.contains('hidden')) {
                document.getElementById('user-custom-api-key').value = localStorage.getItem('user_custom_ai_key') || '';
                document.getElementById('user-custom-provider').value = localStorage.getItem('user_custom_ai_provider') || 'gemini';
            }
        }

        function saveUserCustomApiKey() {
            const key  = document.getElementById('user-custom-api-key').value.trim();
            const prov = document.getElementById('user-custom-provider').value;
            if (key) {
                localStorage.setItem('user_custom_ai_key', key);
                localStorage.setItem('user_custom_ai_provider', prov);
                alert('✅ کلید API شخصی شما در مرورگر ذخیره شد.');
            } else {
                localStorage.removeItem('user_custom_ai_key');
                alert('کلید شخصی حذف شد.');
            }
            document.getElementById('byok-settings-box').classList.add('hidden');
            refreshSubscriptionUI();
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
                fetchPapersAndConfig();
            }
        }

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
        }

        window.addEventListener('resize', () => {
            if (paperDocInstance && paperZoomMode === 'fit-width') {
                renderPaperPage(currentPaperPage);
            }
        });

        window.addEventListener('DOMContentLoaded', () => {
            fetchPapersAndConfig();
        });
    </script>
</body>
</html>