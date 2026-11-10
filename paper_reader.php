<?php
require_once 'config.php';
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
    <title>مقاله‌خوان هوشمند و مترجم آکادمیک v3.6</title>
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

        /* قفل کردن جهت LTR روی بوم PDF و لایه جملات برای حفظ کامل ساختار مقالات انگلیسی */
        #paper-canvas-wrapper,
        #paper-render-canvas,
        #paper-paragraphs-layer {
            direction: ltr !important;
            text-align: left !important;
            unicode-bidi: isolate !important;
            font-family: sans-serif !important;
        }

        /* استایل قطعات هر جمله/بند روی صفحه PDF */
        .seg-box {
            position: absolute;
            border-radius: 3px;
            border: 1px solid transparent;
            background-color: transparent;
            cursor: pointer;
            transition: background-color 0.12s ease, border-color 0.12s ease;
        }
        /* وقتی موس روی یکی از قطعات یک جمله می‌رود، کل آن جمله (از نقطه تا نقطه) هایلایت می‌شود */
        .seg-box.hover-seg {
            border-color: rgba(79, 70, 229, 0.65);
            background-color: rgba(99, 102, 241, 0.18);
        }
        /* ۱. جمله خوانده‌شده در این جلسه */
        .seg-box.read-seg {
            background-color: rgba(56, 189, 248, 0.10);
            border-bottom: 1px dashed rgba(2, 132, 199, 0.35);
        }
        /* ۲. جمله دارای فیش ذخیره‌شده (سبز زمردی) */
        .seg-box.noted-seg {
            border-color: rgba(16, 185, 129, 0.85) !important;
            background-color: rgba(16, 185, 129, 0.20) !important;
        }
        .seg-box.noted-seg-first::after {
            content: '📌';
            position: absolute;
            top: -8px;
            left: -8px;
            font-size: 10px;
            background: #ecfdf5;
            border: 1px solid #10b981;
            border-radius: 999px;
            width: 16px;
            height: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 2px rgba(0,0,0,0.2);
            z-index: 20;
        }
        /* ۳. جمله فعال در حال ترجمه */
        .seg-box.active-seg {
            border: 1.5px solid #4f46e5 !important;
            background-color: rgba(79, 70, 229, 0.26) !important;
            z-index: 15;
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
                <span class="bg-emerald-600 text-white px-2 py-1 rounded-lg font-extrabold text-xs">🌐 مقاله‌خوان</span>
                <span class="font-bold text-xs md:text-sm truncate max-w-[90px] sm:max-w-none"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
            </div>

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
    <!-- ۲. بدنه اصلی مقاله‌خوان -->
    <!-- ==================================================== -->
    <main class="flex-1 flex overflow-hidden relative bg-slate-900">

        <div id="paper-sidebar-backdrop" onclick="closePaperSidebarMobile()" class="fixed inset-0 bg-black/60 z-30 hidden lg:hidden"></div>

        <!-- الف) سایدبار کتابخانه مقالات -->
        <aside id="paper-sidebar" class="w-72 max-w-[85vw] bg-white border-l border-slate-200 flex flex-col shrink-0 z-40 fixed lg:static inset-y-0 right-0 transform translate-x-full lg:translate-x-0 transition-transform duration-200 shadow-2xl lg:shadow-none">
            <div class="p-3 border-b border-slate-200 flex items-center justify-between bg-slate-50">
                <h2 class="font-extrabold text-xs md:text-sm text-slate-800">📄 کتابخانه مقالات من</h2>
                <button type="button" onclick="closePaperSidebarMobile()" class="lg:hidden text-slate-400 hover:text-slate-700 text-xl font-bold leading-none">&times;</button>
            </div>

            <div class="p-2.5 border-b border-slate-200 bg-slate-50/60 grid grid-cols-2 gap-1.5 text-[11px]">
                <button type="button" onclick="openUploadPaperModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white py-2 px-2 rounded-xl font-bold shadow-2xs transition flex items-center justify-center gap-1">
                    <span>+ آپلود مقاله</span>
                </button>
                <button type="button" onclick="openImportBookletModal()" class="bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 py-2 px-2 rounded-xl font-bold transition flex items-center justify-center gap-1" title="افزودن فایل از بخش جزوه‌خوان">
                    <span>📥 از جزوه‌خوان</span>
                </button>
            </div>

            <div id="paper-quota-banner" class="px-3 py-1.5 text-[11px] border-b border-amber-200 bg-amber-50 text-amber-900 font-bold flex items-center justify-between"></div>

            <div id="papers-list-container" class="flex-1 overflow-y-auto p-2.5 space-y-2 custom-scroll"></div>
        </aside>

        <!-- ب) بخش میانی: نمایشگر مقاله انگلیسی با لایه تشخیص جمله‌به‌جمله (از نقطه تا نقطه) -->
        <section class="flex-1 flex flex-col overflow-hidden relative bg-slate-800">
            
            <div class="bg-slate-900/95 text-white border-b border-slate-700 px-2.5 py-1.5 flex flex-wrap items-center justify-between gap-2 shrink-0 z-20">
                <div class="flex items-center gap-1.5 min-w-0">
                    <button type="button" onclick="togglePaperSidebar()" class="px-2.5 py-1 bg-indigo-600 hover:bg-indigo-500 rounded-lg text-xs font-bold text-white shrink-0">
                        ☰ مقالات
                    </button>
                    <span id="active-paper-title" class="text-xs font-bold text-slate-200 truncate max-w-[120px] sm:max-w-xs">
                        مقاله‌ای انتخاب نشده است
                    </span>
                </div>

                <div id="paper-page-controls" class="hidden items-center gap-1 shrink-0">
                    <button type="button" onclick="changePaperPage(-1)" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 rounded-lg text-xs font-bold" title="صفحه قبل">❮</button>
                    <div class="flex items-center gap-1 text-xs font-bold bg-slate-800 px-2 py-0.5 rounded-lg border border-slate-700">
                        <input type="number" id="paper-page-input" onchange="jumpToPaperPage(this.value)" min="1" value="1" class="w-10 text-center bg-transparent text-white font-extrabold focus:outline-none">
                        <span class="text-slate-400">/</span>
                        <span id="paper-total-pages" class="text-slate-300">1</span>
                    </div>
                    <button type="button" onclick="changePaperPage(1)" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 rounded-lg text-xs font-bold" title="صفحه بعد">❯</button>
                </div>

                <!-- سوئیچ حالت انتخاب (نقطه تا نقطه vs پاراگراف) + زوم -->
                <div class="flex items-center gap-1 shrink-0">
                    <div class="flex bg-slate-800 p-0.5 rounded-lg border border-slate-700 text-[10px]">
                        <button type="button" id="mode-btn-sentence" onclick="setSelectionGranularity('sentence')" class="px-2 py-1 rounded-md font-extrabold bg-indigo-600 text-white transition" title="انتخاب از نقطه قبلی تا نقطه بعدی (جمله‌به‌جمله)">
                            🎯 نقطه تا نقطه
                        </button>
                        <button type="button" id="mode-btn-paragraph" onclick="setSelectionGranularity('paragraph')" class="px-2 py-1 rounded-md font-bold text-slate-300 hover:text-white transition" title="انتخاب کل پاراگراف هم‌ستون">
                            📄 پاراگراف
                        </button>
                    </div>

                    <div id="paper-zoom-controls" class="hidden sm:flex items-center gap-1">
                        <button type="button" onclick="adjustPaperZoom(-0.2)" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 rounded-lg text-xs font-bold" title="کوچک‌نمایی">➖</button>
                        <button type="button" onclick="setPaperFitWidth()" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 text-indigo-300 rounded-lg text-[11px] font-bold">↔ عرض</button>
                        <button type="button" onclick="adjustPaperZoom(0.2)" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 rounded-lg text-xs font-bold" title="بزرگ‌نمایی">➕</button>
                    </div>
                    <button type="button" onclick="togglePaperDarkMode()" id="btn-paper-dark" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 text-amber-300 rounded-lg text-xs font-bold" title="حالت شب">🌙</button>
                    <button type="button" onclick="openMobileTranslatorSheet()" class="md:hidden px-2 py-1 bg-emerald-600 text-white rounded-lg text-xs font-bold">
                        🌐 ترجمه
                    </button>
                </div>
            </div>

            <!-- راهنمای نوار باریک بالای بوم مقاله -->
            <div id="paper-hint-bar" class="hidden bg-indigo-950/90 text-indigo-200 px-3 py-1 text-[11px] font-bold border-b border-indigo-800/60 flex-wrap items-center justify-between gap-2 shrink-0">
                <div class="flex items-center gap-3">
                    <span id="granularity-hint-text">🎯 حالت نقطه تا نقطه: روی هر جمله کلیک کنید تا از نقطه قبلی تا نقطه بعدی ترجمه شود (با تفکیک خودکار ستون چپ و راست).</span>
                </div>
                <span id="detected-blocks-badge" class="text-[10px] bg-indigo-800/80 text-white px-2 py-0.5 rounded-full shrink-0">0 جمله</span>
            </div>

            <!-- ناحیه اسکرول و رندر بوم PDF مقاله -->
            <div id="paper-viewport-container" class="flex-1 overflow-auto p-1.5 sm:p-4 flex justify-center items-start custom-scroll relative">
                
                <div id="paper-loading-overlay" class="hidden my-auto text-center bg-slate-900/95 text-white p-6 rounded-2xl shadow-xl border border-slate-700 z-30">
                    <div class="text-3xl mb-2 animate-bounce">⏳</div>
                    <div class="text-xs font-bold text-indigo-300">در حال بارگذاری مقاله و تفکیک ستون‌ها و جملات...</div>
                </div>

                <div id="paper-empty-state" class="my-auto text-center bg-slate-900/90 text-white p-6 rounded-2xl shadow-xl max-w-sm border border-slate-700">
                    <div class="text-4xl mb-2">🌐</div>
                    <h3 class="font-extrabold text-sm mb-1">محيط مقاله‌خوان و مترجم هوشمند</h3>
                    <p class="text-slate-400 text-xs mb-4 leading-relaxed">
                        یک مقاله انگلیسی (PDF) آپلود کنید یا از بخش جزوه‌خوان وارد نمایید؛ سپس با کلیک روی هر جمله (از نقطه تا نقطه)، ترجمه فارسی، پیام کلیدی و تلفظ صوتی آن را دریافت کنید.
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

                <!-- بوم رندر PDF + لایه باکس‌های تعاملی جملات -->
                <div id="paper-canvas-wrapper" dir="ltr" class="hidden relative shadow-2xl bg-white">
                    <canvas id="paper-render-canvas" dir="ltr" class="block"></canvas>
                    <div id="paper-paragraphs-layer" dir="ltr" class="absolute inset-0"></div>
                </div>
            </div>
        </section>

        <!-- ج) پنل ترجمه، پلیر صوتی و فیش‌برداری -->
        <aside id="translator-panel" class="w-full md:w-96 lg:w-[440px] bg-white border-r border-slate-200 flex flex-col shrink-0 z-40 fixed md:static inset-x-0 bottom-0 max-h-[85vh] md:max-h-none rounded-t-3xl md:rounded-none transform translate-y-full md:translate-y-0 transition-transform duration-200 shadow-2xl md:shadow-none">
            
            <div class="bg-slate-900 text-white px-3.5 py-2.5 flex items-center justify-between border-b border-slate-800 rounded-t-3xl md:rounded-none shrink-0">
                <div class="flex bg-slate-800 p-0.5 rounded-xl border border-slate-700 text-xs">
                    <button type="button" id="tab-btn-translate" onclick="switchTranslatorTab('translate')" class="px-3 py-1 rounded-lg font-extrabold bg-indigo-600 text-white transition">
                        🌐 ترجمه و تحلیل
                    </button>
                    <button type="button" id="tab-btn-notes" onclick="switchTranslatorTab('notes')" class="px-3 py-1 rounded-lg font-bold text-slate-300 hover:text-white transition flex items-center gap-1">
                        <span>📌 فیش‌های مقاله</span>
                        <span id="notes-count-badge" class="bg-slate-700 text-amber-300 px-1.5 py-0.2 rounded-full text-[10px]">0</span>
                    </button>
                </div>

                <button type="button" onclick="closeMobileTranslatorSheet()" class="md:hidden text-slate-400 hover:text-white text-xl font-bold px-1 leading-none">&times;</button>
            </div>

            <!-- ========================================== -->
            <!-- تب ۱: مترجم زنده، پیمایش جملات، پلیر صوتی و خلاصه‌ساز -->
            <!-- ========================================== -->
            <div id="panel-tab-translate" class="flex-1 flex flex-col overflow-y-auto p-3.5 space-y-3 custom-scroll text-xs">
                
                <!-- انتخاب موتور ترجمه -->
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

                <!-- کادر متن انگلیسی + دکمه‌های جمله قبل/بعد + پلیر صوتی پیشرفته -->
                <div class="bg-slate-50 rounded-2xl p-3 border border-slate-200 space-y-2">
                    
                    <!-- نوار پیمایش ترتیبی جملات (اول کل ستون چپ، بعد کل ستون راست) -->
                    <div class="flex items-center justify-between bg-white px-2.5 py-1.5 rounded-xl border border-slate-200">
                        <button type="button" onclick="stepSegment(-1)" class="px-2.5 py-1 bg-slate-100 hover:bg-indigo-50 text-slate-700 hover:text-indigo-700 rounded-lg font-extrabold text-[11px] transition">
                            ❯ جمله / بند قبلی
                        </button>
                        <span id="current-seg-Position-label" class="text-[10px] font-extrabold text-indigo-700">---</span>
                        <button type="button" onclick="stepSegment(1)" class="px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg font-extrabold text-[11px] transition">
                            جمله / بند بعدی ❮
                        </button>
                    </div>

                    <!-- نوار کنترل صوتی پیشرفته (TTS Player Bar) -->
                    <div class="bg-slate-200/75 p-2 rounded-xl flex flex-wrap items-center justify-between gap-1.5">
                        <div class="flex items-center gap-1">
                            <button type="button" onclick="playOrResumeSpeech()" id="btn-tts-play" class="px-2.5 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-extrabold text-[11px] flex items-center gap-1 transition shadow-2xs" title="شروع خواندن متن">
                                <span>▶</span><span>خواندن</span>
                            </button>
                            <button type="button" onclick="pauseSpeech()" id="btn-tts-pause" class="hidden px-2 py-1 bg-amber-500 hover:bg-amber-600 text-white rounded-lg font-bold text-[11px]" title="مکث موقت">
                                ⏸
                            </button>
                            <button type="button" onclick="stopSpeech()" id="btn-tts-stop" class="hidden px-2 py-1 bg-rose-600 hover:bg-rose-700 text-white rounded-lg font-bold text-[11px]" title="توقف کامل">
                                ⏹
                            </button>
                        </div>

                        <div class="flex items-center gap-1 flex-1 justify-end min-w-[170px]">
                            <select id="tts-voice-select" onchange="saveTtsPreferences()" dir="ltr" class="max-w-[135px] px-1.5 py-1 bg-white border border-slate-300 rounded-lg text-[10px] font-bold text-slate-700 truncate" title="انتخاب اپراتور و لهجه گوینده">
                                <option value="">🎙️ گوینده پیش‌فرض</option>
                            </select>
                            <select id="tts-rate-select" onchange="saveTtsPreferences()" dir="ltr" class="px-1.5 py-1 bg-white border border-slate-300 rounded-lg text-[10px] font-extrabold text-indigo-700" title="سرعت خواندن">
                                <option value="0.5">0.5x</option>
                                <option value="0.75">0.75x</option>
                                <option value="1" selected>1.0x</option>
                                <option value="1.25">1.25x</option>
                                <option value="1.5">1.5x</option>
                            </select>
                        </div>
                    </div>

                    <textarea id="source-english-textarea" onmouseup="checkSubSelectionInTextarea()" onkeyup="checkSubSelectionInTextarea()" touchend="checkSubSelectionInTextarea()" dir="ltr" rows="3" placeholder="روی یکی از جملات مقاله کلیک کنید یا هر عبارت انگلیسی را اینجا بنویسید..." class="w-full p-2.5 bg-white border border-slate-200 rounded-xl text-left font-sans text-xs text-slate-800 leading-relaxed focus:outline-none focus:ring-2 focus:ring-indigo-500 select-text"></textarea>

                    <!-- دکمه شناور ترجمه فوری تک‌کلمه یا عبارت انتخاب‌شده در داخل کادر -->
                    <div id="sub-selection-bar" class="hidden bg-amber-50 border border-amber-300 rounded-xl p-2 flex items-center justify-between gap-2">
                        <div class="truncate text-[11px] font-bold text-amber-950">
                            <span>🔍 عبارت انتخابی: </span>
                            <span id="sub-selection-term" class="font-mono text-indigo-700 dir-ltr"></span>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            <button type="button" onclick="speakSelectedSubstring()" class="px-2 py-1 bg-white border border-amber-300 rounded-lg text-[10px] font-bold" title="تلفظ همین واژه">🔊</button>
                            <button type="button" onclick="translateSelectedSubstring()" class="px-2.5 py-1 bg-amber-500 hover:bg-amber-600 text-slate-950 rounded-lg text-[10px] font-extrabold">معنی فوری</button>
                        </div>
                    </div>

                    <div id="sub-selection-result" class="hidden bg-indigo-950 text-white p-2.5 rounded-xl text-[11px] flex items-center justify-between gap-2">
                        <div id="sub-selection-meaning" class="font-bold leading-relaxed"></div>
                        <button type="button" onclick="document.getElementById('sub-selection-result').classList.add('hidden')" class="text-slate-400 hover:text-white font-bold">&times;</button>
                    </div>

                    <div class="grid grid-cols-2 gap-1.5 pt-0.5">
                        <button type="button" onclick="runTranslationTask('translate')" class="py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-extrabold text-[11px] transition shadow-2xs">
                            🔄 ترجمه روان
                        </button>
                        <button type="button" onclick="runTranslationTask('summarize')" class="py-2 bg-amber-500/15 hover:bg-amber-500/25 text-amber-900 border border-amber-400/60 rounded-xl font-extrabold text-[11px] transition">
                            ✨ پیام کلیدی و خلاصه
                        </button>
                    </div>
                </div>

                <!-- کادر خروجی ترجمه فارسی + واژگان تخصصی -->
                <div class="flex-1 flex flex-col bg-indigo-50/50 rounded-2xl p-3.5 border border-indigo-100 min-h-[180px]">
                    <div class="flex items-center justify-between border-b border-indigo-100 pb-2 mb-2.5">
                        <span class="font-extrabold text-indigo-950 text-xs flex items-center gap-1">
                            <span id="output-box-title">🇮🇷 ترجمه فارسی:</span>
                            <span id="translation-meta-badge" class="text-[10px] font-bold bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full hidden"></span>
                        </span>
                        <button type="button" onclick="copyTranslatedText()" class="text-[11px] text-indigo-600 hover:underline font-bold">
                            📋 کپی متن
                        </button>
                    </div>

                    <div id="translation-loading" class="hidden my-auto text-center py-6 space-y-2">
                        <div class="inline-block w-6 h-6 border-2 border-indigo-600 border-t-transparent rounded-full animate-spin"></div>
                        <div class="text-indigo-700 font-bold text-xs">در حال پردازش علمی متن...</div>
                    </div>

                    <div id="translated-persian-output" class="flex-1 text-slate-800 text-xs md:text-sm leading-7 font-medium whitespace-pre-line select-text">
                        روی هر جمله از مقاله کلیک کنید تا از نقطه قبلی تا نقطه بعدی استخراج شده و ترجمه فارسی آن اینجا نمایش داده شود.
                    </div>

                    <div id="extracted-keywords-box" class="hidden mt-3 pt-2.5 border-t border-indigo-200/60">
                        <div class="text-[10px] font-extrabold text-indigo-900 mb-1.5">📖 واژگان و اصطلاحات تخصصی (برای تلفظ کلیک کنید):</div>
                        <div id="extracted-keywords-list" class="flex flex-wrap gap-1.5"></div>
                    </div>

                    <div id="save-note-action-bar" class="hidden pt-3 mt-3 border-t border-indigo-200/60 space-y-2">
                        <input type="text" id="note-user-comment" placeholder="یادداشت شخصی شما درباره این نکته (اختیاری)..." class="w-full px-2.5 py-1.5 bg-white border border-indigo-200 rounded-xl text-xs">
                        <button type="button" onclick="saveCurrentTranslationAsNote()" class="w-full py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-extrabold text-xs shadow-xs transition flex items-center justify-center gap-1">
                            <span>📌 ذخیره در فیش‌های مقاله</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- تب ۲: دفترچه فیش‌های مقاله + خروجی Word و PDF -->
            <!-- ========================================== -->
            <div id="panel-tab-notes" class="hidden flex-1 flex-col overflow-hidden p-3.5 text-xs">
                <div class="bg-slate-100 p-2.5 rounded-2xl border border-slate-200 mb-2.5 space-y-2 shrink-0">
                    <div class="flex items-center justify-between">
                        <span class="font-extrabold text-slate-800 text-[11px]">📥 دانلود و خروجی فیش‌های مقاله:</span>
                        <button type="button" onclick="copyAllPaperNotes()" class="text-indigo-600 hover:underline font-bold text-[11px]">📋 کپی متنی</button>
                    </div>
                    <div class="grid grid-cols-2 gap-1.5">
                        <button type="button" onclick="exportNotesToWord()" class="py-2 px-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-extrabold text-[11px] flex items-center justify-center gap-1 shadow-2xs transition">
                            <span>📘 دانلود فایل Word</span>
                        </button>
                        <button type="button" onclick="exportNotesToPdfPrint()" class="py-2 px-2 bg-rose-600 hover:bg-rose-700 text-white rounded-xl font-extrabold text-[11px] flex items-center justify-center gap-1 shadow-2xs transition">
                            <span>📕 خروجی PDF / چاپ</span>
                        </button>
                    </div>
                </div>

                <div class="mb-2.5 shrink-0">
                    <input type="text" id="notes-search-input" oninput="renderPaperNotesList()" placeholder="🔎 جستجو در متن انگلیسی، ترجمه یا یادداشت فیش‌ها..." class="w-full px-3 py-2 bg-white border border-slate-300 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>

                <div id="paper-notes-list" class="flex-1 overflow-y-auto space-y-2.5 custom-scroll"></div>
            </div>

        </aside>

    </main>

    <!-- ==================================================== -->
    <!-- ۳. مودال آپلود مقاله انگلیسی جدید -->
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
                    می‌توانید هر یک از فایل‌های PDF موجود در بخش «جزوه‌خوان» را با یک کلیک به محیط «مقاله‌خوان» اضافه کنید:
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

        // حالت انتخاب متن: 'sentence' (از نقطه تا نقطه - پیش‌فرض) یا 'paragraph' (پاراگراف هم‌ستون)
        let selectionGranularity = 'sentence';
        let pageSegmentsList     = []; // لیست مرتب تمام جملات/بندهای صفحه فعلی (اول ستون چپ، بعد ستون راست)
        let activeSegmentIndex   = -1;

        let selectedEngine      = 'google'; // 'google', 'mymemory', 'ai'
        let lastTranslatedObj   = null;     // { original, translated, para_hash }
        let visitedSegNorms     = new Set();
        let paperNotes          = [];

        function normalizeClientEnglishText(str) {
            return String(str || '')
                .trim()
                .replace(/(\w)-\s+([a-z])/g, '$1$2')
                .replace(/\s+/g, ' ')
                .toLowerCase();
        }

        // تغییر حالت بین «نقطه تا نقطه (جمله)» و «پاراگراف»
        function setSelectionGranularity(mode) {
            selectionGranularity = mode;
            const btnSent = document.getElementById('mode-btn-sentence');
            const btnPara = document.getElementById('mode-btn-paragraph');
            const hintEl  = document.getElementById('granularity-hint-text');

            if (mode === 'sentence') {
                btnSent.className = 'px-2 py-1 rounded-md font-extrabold bg-indigo-600 text-white transition';
                btnPara.className = 'px-2 py-1 rounded-md font-bold text-slate-300 hover:text-white transition';
                hintEl.textContent = '🎯 حالت نقطه تا نقطه: روی هر جمله کلیک کنید تا از نقطه قبلی تا نقطه بعدی ترجمه شود (با تفکیک ستون چپ و راست).';
            } else {
                btnPara.className = 'px-2 py-1 rounded-md font-extrabold bg-indigo-600 text-white transition';
                btnSent.className = 'px-2 py-1 rounded-md font-bold text-slate-300 hover:text-white transition';
                hintEl.textContent = '📄 حالت پاراگراف: با کلیک روی هر بند، کل آن پاراگراف (با رعایت مرز ستون چپ و راست) انتخاب می‌شود.';
            }

            if (paperDocInstance) {
                renderPaperPage(currentPaperPage);
            }
        }

        // ====================================================
        // پلیر صوتی پیشرفته چندگوینده (Multi-Voice & Multi-Speed TTS)
        // ====================================================
        let availableEnglishVoices = [];

        function populateVoiceList() {
            if (!('speechSynthesis' in window)) return;
            const allVoices = window.speechSynthesis.getVoices() || [];
            availableEnglishVoices = allVoices.filter(v => /^en(-|_|$)/i.test(v.lang));
            if (availableEnglishVoices.length === 0) {
                availableEnglishVoices = allVoices;
            }

            const selectEl = document.getElementById('tts-voice-select');
            if (!selectEl) return;

            const savedVoiceURI = localStorage.getItem('paper_tts_voice_uri') || '';
            let html = '<option value="">🎙️ گوینده پیش‌فرض سیستم</option>';

            availableEnglishVoices.forEach(v => {
                const cleanName = v.name.replace(/Microsoft |Google |Apple /g, '');
                const selectedAttr = (v.voiceURI === savedVoiceURI) ? 'selected' : '';
                html += `<option value="${escapeHtml(v.voiceURI)}" ${selectedAttr}>🎙️ ${escapeHtml(cleanName)} (${escapeHtml(v.lang)})</option>`;
            });
            selectEl.innerHTML = html;

            const savedRate = localStorage.getItem('paper_tts_rate') || '1';
            const rateEl = document.getElementById('tts-rate-select');
            if (rateEl) rateEl.value = savedRate;
        }

        if ('speechSynthesis' in window) {
            populateVoiceList();
            if (speechSynthesis.onvoiceschanged !== undefined) {
                speechSynthesis.onvoiceschanged = populateVoiceList;
            }
        }

        function saveTtsPreferences() {
            const vUri = document.getElementById('tts-voice-select').value;
            const rate = document.getElementById('tts-rate-select').value;
            localStorage.setItem('paper_tts_voice_uri', vUri);
            localStorage.setItem('paper_tts_rate', rate);

            if (window.speechSynthesis && window.speechSynthesis.speaking) {
                stopSpeech();
                playOrResumeSpeech();
            }
        }

        function updateTtsButtonsUI(state) {
            const btnPlay  = document.getElementById('btn-tts-play');
            const btnPause = document.getElementById('btn-tts-pause');
            const btnStop  = document.getElementById('btn-tts-stop');

            if (state === 'playing') {
                btnPlay.innerHTML = '<span>🔊 در حال خواندن...</span>';
                btnPause.classList.remove('hidden');
                btnStop.classList.remove('hidden');
            } else if (state === 'paused') {
                btnPlay.innerHTML = '<span>▶ ادامه</span>';
                btnPause.classList.add('hidden');
                btnStop.classList.remove('hidden');
            } else {
                btnPlay.innerHTML = '<span>▶</span><span>خواندن</span>';
                btnPause.classList.add('hidden');
                btnStop.classList.add('hidden');
            }
        }

        function speakTextWithCurrentSettings(textToSpeak) {
            if (!textToSpeak) return;
            if (!('speechSynthesis' in window)) {
                alert('مرورگر شما از موتور صوتی پشتیبانی نمی‌کند.');
                return;
            }

            window.speechSynthesis.cancel();
            const utter = new SpeechSynthesisUtterance(textToSpeak);
            utter.lang = 'en-US';

            const selectedUri = document.getElementById('tts-voice-select')?.value || '';
            if (selectedUri && availableEnglishVoices.length > 0) {
                const matchedVoice = availableEnglishVoices.find(v => v.voiceURI === selectedUri);
                if (matchedVoice) {
                    utter.voice = matchedVoice;
                    utter.lang  = matchedVoice.lang || 'en-US';
                }
            }

            const rateVal = parseFloat(document.getElementById('tts-rate-select')?.value || '1') || 1.0;
            utter.rate = rateVal;

            utter.onstart = () => updateTtsButtonsUI('playing');
            utter.onend   = () => updateTtsButtonsUI('idle');
            utter.onerror = () => updateTtsButtonsUI('idle');

            window.speechSynthesis.speak(utter);
        }

        function playOrResumeSpeech() {
            if (!('speechSynthesis' in window)) return;
            if (window.speechSynthesis.paused) {
                window.speechSynthesis.resume();
                updateTtsButtonsUI('playing');
                return;
            }
            const text = document.getElementById('source-english-textarea').value.trim();
            if (!text) {
                alert('ابتدا یک جمله از مقاله را انتخاب کنید.');
                return;
            }
            speakTextWithCurrentSettings(text);
        }

        function pauseSpeech() {
            if ('speechSynthesis' in window && window.speechSynthesis.speaking) {
                window.speechSynthesis.pause();
                updateTtsButtonsUI('paused');
            }
        }

        function stopSpeech() {
            if ('speechSynthesis' in window) {
                window.speechSynthesis.cancel();
                updateTtsButtonsUI('idle');
            }
        }

        // ====================================================
        // تشخیص انتخاب تک‌کلمه یا عبارت کوتاه در کادر انگلیسی
        // ====================================================
        let selectedSubstringText = '';

        function checkSubSelectionInTextarea() {
            const ta = document.getElementById('source-english-textarea');
            const bar = document.getElementById('sub-selection-bar');
            if (!ta || !bar) return;

            const start = ta.selectionStart;
            const end   = ta.selectionEnd;
            const sub   = ta.value.substring(start, end).trim();

            if (sub.length >= 2 && sub.length <= 80 && sub.length < ta.value.trim().length) {
                selectedSubstringText = sub;
                document.getElementById('sub-selection-term').textContent = sub;
                bar.classList.remove('hidden');
            } else {
                bar.classList.add('hidden');
            }
        }

        function speakSelectedSubstring() {
            if (selectedSubstringText) {
                speakTextWithCurrentSettings(selectedSubstringText);
            }
        }

        async function translateSelectedSubstring() {
            if (!selectedSubstringText) return;
            const resBox = document.getElementById('sub-selection-result');
            const meanEl = document.getElementById('sub-selection-meaning');
            resBox.classList.remove('hidden');
            meanEl.textContent = `⏳ در حال ترجمه «${selectedSubstringText}»...`;

            try {
                const fd = new FormData();
                fd.append('action', 'translate');
                fd.append('text', selectedSubstringText);
                fd.append('engine', selectedEngine);
                fd.append('task_mode', 'translate');

                const res = await fetch('api_translate.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    meanEl.innerHTML = `<span class="text-amber-300 font-mono dir-ltr">${escapeHtml(selectedSubstringText)}</span>: ${escapeHtml(data.translated_text)}`;
                } else {
                    meanEl.textContent = data.message || 'خطا در ترجمه عبارت.';
                }
            } catch (e) {
                meanEl.textContent = 'خطا در ارتباط با سرور.';
            }
        }

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

            const aiBtn = document.getElementById('eng-btn-ai');
            if (aiBtn) {
                if (!aiConfig.enabled) {
                    aiBtn.classList.add('opacity-50');
                    document.getElementById('ai-engine-sublabel').textContent = 'غیرفعال';
                } else {
                    aiBtn.classList.remove('opacity-50');
                    document.getElementById('ai-engine-sublabel').textContent = userSub.is_vip ? '✨ فعال (VIP)' : '🔒 ویژه VIP';
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
        // بارگذاری باینری مقاله
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
            visitedSegNorms.clear();
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
                await fetchPaperNotes(activePaperMeta.id);
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

                await buildInteractiveSegments(page, viewport);
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
        // موتور جدید: تفکیک قطعی ستون چپ/راست + برش هوشمند «از نقطه تا نقطه» (Dot-to-Dot)
        // ====================================================

        // تشخیص اینکه آیا یک نقطه (.) واقعاً پایان جمله است یا بخشی از اختصارات علمی (et al., Fig., Eq., 3.14)
        function isTrueSentenceEnd(lineText, dotIndex) {
            const ch = lineText[dotIndex];
            if (ch === '?' || ch === '!') return true;

            const afterChar = lineText[dotIndex + 1] || '';
            // اگر بلافاصله بعد از نقطه عدد یا حرف بدون فاصله باشد (مثل 3.14 یا e.g.)، پایان جمله نیست
            if (afterChar && !/[\s"'\)\]\}]/.test(afterChar)) {
                return false;
            }

            // استخراج کلمه قبل از نقطه
            const beforeStr = lineText.slice(0, dotIndex).trimEnd();
            const wordMatch = beforeStr.match(/([a-zA-Z0-9\.]+)$/);
            if (!wordMatch) return true;

            const lastWord = wordMatch[1];
            const cleanWord = lastWord.replace(/\./g, '').toLowerCase();

            // لیست اختصارات رایج در مقالات علمی که نقطه آن‌ها نباید جمله را بشکند
            const academicAbbrevs = new Set([
                'fig', 'figs', 'eq', 'eqs', 'sec', 'secs', 'tab', 'tabs', 'ref', 'refs',
                'al', 'eg', 'ie', 'vs', 'etc', 'dr', 'prof', 'mr', 'mrs', 'ms', 'inc',
                'ltd', 'co', 'no', 'nos', 'vol', 'vols', 'pp', 'ch', 'st', 'ave', 'approx',
                'dept', 'univ', 'assn', 'proc', 'trans', 'conf', 'symp', 'intl', 'jan',
                'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sep', 'sept', 'oct', 'nov', 'dec'
            ]);

            if (academicAbbrevs.has(cleanWord)) return false;

            // اگر حرف تکی بزرگ باشد (مثل حرف اول اسم در رفرنس‌ها: J. Smith)
            if (/^[A-Z]$/.test(lastWord)) return false;

            // اگر شامل نقطه درونی باشد مثل e.g یا i.e
            if (lastWord.includes('.')) return false;

            return true;
        }

        // شکستن یک سطر به قطعات جمله‌ای بر اساس محل دقیق نقطه‌ها
        function splitLineIntoSentencePieces(ln) {
            const text = ln.text;
            const len  = text.length;
            if (len === 0) return [];

            const splitIndices = [];
            for (let i = 0; i < len; i++) {
                const c = text[i];
                if (c === '.' || c === '?' || c === '!') {
                    if (isTrueSentenceEnd(text, i)) {
                        // شامل کردن پرانتز یا گیومه یا رفرنس بسته بعد از نقطه مثل .[12] یا .)
                        let endPos = i + 1;
                        while (endPos < len && /[\)"'\]\}]/.test(text[endPos])) {
                            endPos++;
                        }
                        splitIndices.push(endPos);
                        i = endPos - 1;
                    }
                }
            }

            const pieces = [];
            let startIdx = 0;
            const totalW = Math.max(10, ln.right - ln.x);

            splitIndices.forEach(endIdx => {
                const subStr = text.slice(startIdx, endIdx).trim();
                if (subStr.length > 0) {
                    const ratioStart = startIdx / len;
                    const ratioEnd   = endIdx / len;
                    pieces.push({
                        text: subStr,
                        endsSentence: true,
                        x: ln.x + (totalW * ratioStart),
                        y: ln.y,
                        w: Math.max(12, totalW * (ratioEnd - ratioStart)),
                        h: Math.max(10, ln.bottom - ln.y)
                    });
                }
                startIdx = endIdx;
                while (startIdx < len && text[startIdx] === ' ') startIdx++;
            });

            if (startIdx < len) {
                const remStr = text.slice(startIdx).trim();
                if (remStr.length > 0) {
                    const ratioStart = startIdx / len;
                    pieces.push({
                        text: remStr,
                        endsSentence: false,
                        x: ln.x + (totalW * ratioStart),
                        y: ln.y,
                        w: Math.max(12, totalW * (1 - ratioStart)),
                        h: Math.max(10, ln.bottom - ln.y)
                    });
                }
            }

            return pieces;
        }

        async function buildInteractiveSegments(page, viewport) {
            const paraLayer = document.getElementById('paper-paragraphs-layer');
            paraLayer.innerHTML = '';
            pageSegmentsList = [];
            activeSegmentIndex = -1;

            const textContent = await page.getTextContent();
            const rawItems = textContent.items || [];
            const pageW = viewport.width;
            const pageH = viewport.height;
            const centerX = pageW * 0.50;

            // گام ۱: استخراج قطعات خام متن؛ اگر یک آیتم PDF خودش شامل فاصله بزرگ افقی در وسط صفحه بود، از ورود به شکاف جلوگیری شود
            const fragments = [];
            rawItems.forEach(item => {
                const str = (item.str || '').trim();
                if (!str) return;

                const tx = window.pdfjsLib.Util.transform(viewport.transform, item.transform);
                const fontHeight = Math.max(7.5, Math.hypot(tx[2], tx[3]));
                const x = tx[4];
                const y = tx[5] - fontHeight;
                const w = Math.max(fontHeight * 0.4, (item.width || 0) * viewport.scale);
                const h = fontHeight * 1.18;

                if (x >= -10 && y >= -10 && x <= pageW + 10 && y <= pageH + 10) {
                    fragments.push({
                        str: item.str,
                        x: x,
                        y: y,
                        right: x + w,
                        bottom: y + h,
                        fontH: fontHeight
                    });
                }
            });

            if (fragments.length === 0) {
                document.getElementById('detected-blocks-badge').textContent = 'صفحه تصویری';
                return;
            }

            // مرتب‌سازی اولیه قطعات بر اساس سطر (Y) و سپس چپ‌به‌راست (X)
            fragments.sort((a, b) => (Math.abs(a.y - b.y) <= Math.min(a.fontH, b.fontH) * 0.42) ? (a.x - b.x) : (a.y - b.y));

            // گام ۲: تشکیل سطرها با قانون سخت‌گیرانه عدم عبور از شکاف وسط صفحه (Gutter)
            // فاصله مجاز بین دو کلمه در یک سطر حداکثر 1.45 برابر ارتفاع فونت است (هرگز دو ستون چپ و راست به هم نمی‌چسبند!)
            const lines = [];
            fragments.forEach(frag => {
                let added = false;
                for (let i = lines.length - 1; i >= Math.max(0, lines.length - 8); i--) {
                    const ln = lines[i];
                    const avgFont = (ln.fontH + frag.fontH) * 0.5;
                    const sameVertical = Math.abs(frag.y - ln.y) <= avgFont * 0.50;
                    const horizGap = frag.x - ln.right;

                    // شرط طلایی جلوگیری از اتصال ستون چپ به ستون راست:
                    // ۱. فاصله افقی نباید بیشتر از 1.45 برابر سایز فونت باشد
                    // ۲. اگر قطعه قبلی در سمت چپ خط وسط صفحه تمام شده و قطعه جدید در سمت راست خط وسط شروع شده و بینشان بیش از 0.65*avgFont فاصله است، قطعاً ستون جدید است!
                    const crossesCenterGutter = (ln.right <= pageW * 0.515 && frag.x >= pageW * 0.485 && horizGap > avgFont * 0.65);

                    if (sameVertical && !crossesCenterGutter && horizGap >= -12 && horizGap <= avgFont * 1.45) {
                        const needSpace = (horizGap > avgFont * 0.12 && !ln.text.endsWith(' ') && !frag.str.startsWith(' '));
                        ln.text += (needSpace ? ' ' : '') + frag.str;
                        ln.x = Math.min(ln.x, frag.x);
                        ln.y = Math.min(ln.y, frag.y);
                        ln.right = Math.max(ln.right, frag.right);
                        ln.bottom = Math.max(ln.bottom, frag.bottom);
                        ln.fontH = avgFont;
                        added = true;
                        break;
                    }
                }

                if (!added) {
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

            // گام ۳: تشخیص ناحیه هر سطر (تمام‌عرض بالا/چکیده، ستون چپ، ستون راست)
            // یک سطر فقط زمانی «تمام‌عرض (Full-Width)» است که واقعاً از چپ صفحه شروع شده و از وسط صفحه به اندازه قابل توجهی رد شده باشد (مثل عنوان مقاله یا چکیده تک‌ستونه)
            lines.forEach(ln => {
                const lineW = ln.right - ln.x;
                const spansAcrossCenter = (ln.x < pageW * 0.38 && ln.right > pageW * 0.62 && lineW > pageW * 0.44);
                if (spansAcrossCenter) {
                    ln.colZone = 'full';
                } else {
                    const lineMidX = (ln.x + ln.right) * 0.5;
                    ln.colZone = (lineMidX < centerX) ? 'left' : 'right';
                }
            });

            // مرتب‌سازی سطرها به ترتیب صحیح مطالعه مقاله علمی:
            // ۱. بخش تمام‌عرض بالای صفحه (عنوان و چکیده بالای ستون‌ها)
            // ۲. کل ستون سمت چپ (از بالا به پایین)
            // ۳. کل ستون سمت راست (از بالا به پایین)
            // ۴. بخش تمام‌عرض پایین صفحه (در صورت وجود پاورقی سرتاسری)
            const splitColumnLines = lines.filter(l => l.colZone === 'left' || l.colZone === 'right');
            const firstTwoColY = splitColumnLines.length > 0 ? Math.min(...splitColumnLines.map(l => l.y)) : pageH;

            function getReadingZoneOrder(ln) {
                if (ln.colZone === 'full') {
                    return (ln.y < firstTwoColY + 25) ? 0 : 3;
                }
                return (ln.colZone === 'left') ? 1 : 2;
            }

            lines.sort((a, b) => {
                const zA = getReadingZoneOrder(a);
                const zB = getReadingZoneOrder(b);
                if (zA !== zB) return zA - zB;
                return a.y - b.y;
            });

            // گام ۴: تشکیل پاراگراف‌ها در داخل هر ستون به صورت کاملاً ایزوله
            const paragraphs = [];
            lines.forEach(ln => {
                const cleanTxt = ln.text.trim();
                if (!cleanTxt) return;

                let merged = false;
                if (paragraphs.length > 0) {
                    const prev = paragraphs[paragraphs.length - 1];
                    const lastLine = prev.lines[prev.lines.length - 1];

                    // دو سطر فقط اگر در یک ستون یکسان باشند اجازه بررسی ادغام دارند!
                    if (prev.colZone === ln.colZone) {
                        const vertGap = ln.y - lastLine.bottom;
                        const maxFont = Math.max(lastLine.fontH, ln.fontH);
                        const tightVert = (vertGap >= -5 && vertGap <= maxFont * 0.68);
                        const sameFont  = Math.abs(lastLine.fontH - ln.fontH) <= 1.6;

                        // تشخیص پایان پاراگراف قبلی (اگر سطر قبلی کوتاه‌تر از انتهای ستون تمام شده و نقطه دارد)
                        const prevLineW = lastLine.right - lastLine.x;
                        const paraMaxW  = Math.max(prevLineW, prev.maxRight - prev.minLeft);
                        const prevEndedShort = (paraMaxW > maxFont * 8) && (lastLine.right < prev.maxRight - maxFont * 2.2) && /[.?!:]$/.test(lastLine.text.trim());

                        // تشخیص تورفتگی سطر اول پاراگراف جدید (First-line indent)
                        const hasNewIndent = (prev.lines.length >= 2) && (ln.x > prev.minLeft + maxFont * 0.85);

                        // تشخیص سرتیترهای شماره‌دار مثل "1. Introduction" یا "II. METHODOLOGY"
                        const isSectionHeader = /^(\d+(\.\d+)*|[IVX]+\.)\s+[A-Z]/.test(cleanTxt) && cleanTxt.length < 65;

                        if (tightVert && sameFont && !prevEndedShort && !hasNewIndent && !isSectionHeader) {
                            prev.lines.push(ln);
                            prev.minLeft  = Math.min(prev.minLeft, ln.x);
                            prev.maxRight = Math.max(prev.maxRight, ln.right);
                            merged = true;
                        }
                    }
                }

                if (!merged) {
                    paragraphs.push({
                        colZone: ln.colZone,
                        minLeft: ln.x,
                        maxRight: ln.right,
                        lines: [ln]
                    });
                }
            });

            // گام ۵: ساخت واحدهای نهایی قابل کلیک بر اساس حالت انتخابی کاربر (نقطه تا نقطه vs پاراگراف)
            if (selectionGranularity === 'paragraph') {
                paragraphs.forEach(para => {
                    let combinedText = '';
                    const boxes = [];
                    para.lines.forEach(ln => {
                        const t = ln.text.trim();
                        if (combinedText.endsWith('-')) {
                            combinedText = combinedText.slice(0, -1) + t;
                        } else {
                            combinedText += (combinedText ? ' ' : '') + t;
                        }
                        boxes.push({
                            x: ln.x,
                            y: ln.y,
                            w: Math.max(14, ln.right - ln.x),
                            h: Math.max(10, ln.bottom - ln.y)
                        });
                    });
                    if (combinedText.length >= 3) {
                        pageSegmentsList.push({
                            text: combinedText,
                            colZone: para.colZone,
                            boxes: boxes
                        });
                    }
                });
            } else {
                // حالت پیش‌فرض: از نقطه قبلی تا نقطه بعدی (Sentence-by-Sentence)
                paragraphs.forEach(para => {
                    let currentSentText = '';
                    let currentSentBoxes = [];

                    para.lines.forEach(ln => {
                        const pieces = splitLineIntoSentencePieces(ln);
                        pieces.forEach(pc => {
                            if (currentSentText.endsWith('-')) {
                                currentSentText = currentSentText.slice(0, -1) + pc.text;
                            } else {
                                currentSentText += (currentSentText ? ' ' : '') + pc.text;
                            }
                            currentSentBoxes.push({
                                x: pc.x,
                                y: pc.y,
                                w: pc.w,
                                h: pc.h
                            });

                            if (pc.endsSentence) {
                                if (currentSentText.trim().length >= 3) {
                                    pageSegmentsList.push({
                                        text: currentSentText.trim(),
                                        colZone: para.colZone,
                                        boxes: currentSentBoxes
                                    });
                                }
                                currentSentText = '';
                                currentSentBoxes = [];
                            }
                        });
                    });

                    // اگر در انتهای پاراگراف متنی باقی مانده بود (مثلاً عنوان بدون نقطه)
                    if (currentSentText.trim().length >= 3 && currentSentBoxes.length > 0) {
                        pageSegmentsList.push({
                            text: currentSentText.trim(),
                            colZone: para.colZone,
                            boxes: currentSentBoxes
                        });
                    }
                });
            }

            // گام ۶: رندر باکس‌های تعاملی روی صفحه PDF
            pageSegmentsList.forEach((seg, segIdx) => {
                const normStr = normalizeClientEnglishText(seg.text);
                seg.normText = normStr;

                seg.boxes.forEach((bx, bIdx) => {
                    const div = document.createElement('div');
                    div.className = 'seg-box';
                    div.dataset.segIdx = String(segIdx);
                    if (bIdx === 0) div.dataset.isFirstBox = '1';

                    div.style.left   = Math.max(0, Math.floor(bx.x - 1)) + 'px';
                    div.style.top    = Math.max(0, Math.floor(bx.y - 1)) + 'px';
                    div.style.width  = Math.floor(bx.w + 2) + 'px';
                    div.style.height = Math.floor(bx.h + 2) + 'px';

                    if (visitedSegNorms.has(normStr)) {
                        div.classList.add('read-seg');
                    }

                    // وقتی موس روی هر تکه از یک جمله می‌رود، تمام تکه‌های همان جمله هایلایت شوند
                    div.onmouseenter = () => {
                        document.querySelectorAll(`.seg-box[data-seg-idx="${segIdx}"]`).forEach(el => el.classList.add('hover-seg'));
                    };
                    div.onmouseleave = () => {
                        document.querySelectorAll(`.seg-box[data-seg-idx="${segIdx}"]`).forEach(el => el.classList.remove('hover-seg'));
                    };

                    div.onclick = (e) => {
                        e.stopPropagation();
                        activateSegmentByIndex(segIdx);
                    };

                    paraLayer.appendChild(div);
                });
            });

            refreshNotedSegmentsHighlight();
            const unitLabel = (selectionGranularity === 'sentence') ? 'جمله (از نقطه تا نقطه)' : 'پاراگراف';
            document.getElementById('detected-blocks-badge').textContent = `${pageSegmentsList.length} ${unitLabel}`;
            updateSegmentPositionLabel();
        }

        // فعال‌سازی و ترجمه یک جمله/بند با شماره ایندکس (پشتیبانی از کلیک و دکمه‌های جمله قبل/بعد)
        function activateSegmentByIndex(segIdx) {
            if (segIdx < 0 || segIdx >= pageSegmentsList.length) return;
            activeSegmentIndex = segIdx;
            const seg = pageSegmentsList[segIdx];

            document.querySelectorAll('.seg-box').forEach(el => el.classList.remove('active-seg'));
            const matchingBoxes = document.querySelectorAll(`.seg-box[data-seg-idx="${segIdx}"]`);
            matchingBoxes.forEach(el => {
                el.classList.add('active-seg', 'read-seg');
            });

            if (matchingBoxes.length > 0) {
                matchingBoxes[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            visitedSegNorms.add(seg.normText);
            updateSegmentPositionLabel();
            onSegmentSelected(seg.text);
        }

        function stepSegment(delta) {
            if (pageSegmentsList.length === 0) return;
            let nextIdx = activeSegmentIndex + delta;
            if (activeSegmentIndex === -1) nextIdx = 0;
            if (nextIdx >= 0 && nextIdx < pageSegmentsList.length) {
                activateSegmentByIndex(nextIdx);
            }
        }

        function updateSegmentPositionLabel() {
            const lbl = document.getElementById('current-seg-Position-label');
            if (!lbl) return;
            if (pageSegmentsList.length === 0 || activeSegmentIndex === -1) {
                lbl.textContent = `0 از ${pageSegmentsList.length}`;
                return;
            }
            const seg = pageSegmentsList[activeSegmentIndex];
            const zoneTitle = seg.colZone === 'left' ? 'ستون چپ' : (seg.colZone === 'right' ? 'ستون راست' : 'تک‌ستون');
            lbl.textContent = `جمله ${activeSegmentIndex + 1} از ${pageSegmentsList.length} (${zoneTitle})`;
        }

        function refreshNotedSegmentsHighlight() {
            const pageNotesNorms = new Set(
                paperNotes
                    .filter(n => Number(n.page_number) === Number(currentPaperPage))
                    .map(n => normalizeClientEnglishText(n.original_text))
            );

            document.querySelectorAll('.seg-box').forEach(el => {
                const idx = Number(el.dataset.segIdx);
                const seg = pageSegmentsList[idx];
                const norm = seg ? seg.normText : '';
                if (norm && pageNotesNorms.has(norm)) {
                    el.classList.add('noted-seg');
                    if (el.dataset.isFirstBox === '1') el.classList.add('noted-seg-first');
                } else {
                    el.classList.remove('noted-seg', 'noted-seg-first');
                }
            });
        }

        // ====================================================
        // اجرای ترجمه یا خلاصه‌سازی علمی جمله انتخاب‌شده
        // ====================================================
        function onSegmentSelected(englishText) {
            stopSpeech();
            const textarea = document.getElementById('source-english-textarea');
            textarea.value = englishText;
            document.getElementById('sub-selection-bar').classList.add('hidden');
            document.getElementById('sub-selection-result').classList.add('hidden');

            switchTranslatorTab('translate');
            if (window.innerWidth < 768) {
                openMobileTranslatorSheet();
            }

            runTranslationTask('translate');
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

            const currentTxt = document.getElementById('source-english-textarea').value.trim();
            if (currentTxt) {
                runTranslationTask('translate');
            }
        }

        async function runTranslationTask(taskMode = 'translate') {
            const sourceText = document.getElementById('source-english-textarea').value.trim();
            if (!sourceText) {
                alert('لطفاً ابتدا روی یکی از جملات مقاله کلیک کنید یا متنی را در کادر انگلیسی بنویسید.');
                return;
            }

            const loadingEl = document.getElementById('translation-loading');
            const outputEl  = document.getElementById('translated-persian-output');
            const titleEl   = document.getElementById('output-box-title');
            const metaBadge = document.getElementById('translation-meta-badge');
            const kwBox     = document.getElementById('extracted-keywords-box');
            const kwList    = document.getElementById('extracted-keywords-list');
            const saveBar   = document.getElementById('save-note-action-bar');

            titleEl.textContent = (taskMode === 'summarize') ? '✨ پیام کلیدی و خلاصه علمی:' : '🇮🇷 ترجمه فارسی:';
            loadingEl.classList.remove('hidden');
            outputEl.classList.add('hidden');
            kwBox.classList.add('hidden');
            saveBar.classList.add('hidden');
            metaBadge.classList.add('hidden');

            try {
                const fd = new FormData();
                fd.append('action', 'translate');
                fd.append('text', sourceText);
                fd.append('engine', selectedEngine);
                fd.append('task_mode', taskMode);

                const res = await fetch('api_translate.php', { method: 'POST', body: fd });
                const data = await res.json();

                loadingEl.classList.add('hidden');
                outputEl.classList.remove('hidden');

                if (data.status === 'success') {
                    outputEl.textContent = data.translated_text;
                    lastTranslatedObj = {
                        original: data.clean_source || sourceText,
                        translated: data.translated_text,
                        para_hash: data.para_hash || ''
                    };

                    metaBadge.textContent = `${data.engine_used}${data.cached ? ' • کش فوری ⚡' : ''}`;
                    metaBadge.classList.remove('hidden');

                    if (Array.isArray(data.keywords) && data.keywords.length > 0) {
                        kwList.innerHTML = data.keywords.map(kw => `
                            <button type="button" onclick="speakTextWithCurrentSettings('${escapeHtml(kw.en)}')" class="px-2 py-1 bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg text-[10px] font-bold text-slate-800 flex items-center gap-1 transition shadow-2xs" title="کلیک برای تلفظ صوتی">
                                <span class="text-indigo-700 font-mono dir-ltr">${escapeHtml(kw.en)}</span>
                                <span class="text-slate-400">:</span>
                                <span>${escapeHtml(kw.fa)}</span>
                                <span class="text-[9px] opacity-70">🔊</span>
                            </button>
                        `).join('');
                        kwBox.classList.remove('hidden');
                    }

                    if (activePaperMeta) {
                        saveBar.classList.remove('hidden');
                    }
                } else if (data.need_vip) {
                    outputEl.textContent = data.message;
                    openSubscriptionModal(data.message);
                } else {
                    outputEl.textContent = '⚠️ ' + (data.message || 'خطا در پردازش متن.');
                }
            } catch (err) {
                loadingEl.classList.add('hidden');
                outputEl.classList.remove('hidden');
                outputEl.textContent = 'خطا در ارتباط با سرور ترجمه.';
            }
        }

        function copyTranslatedText() {
            const text = document.getElementById('translated-persian-output').textContent.trim();
            if (!text) return;
            navigator.clipboard.writeText(text).then(() => {
                alert('متن فارسی کپی شد.');
            });
        }

        // ====================================================
        // مدیریت فیش‌های پژوهشی مقاله + خروجی Word (.doc) و PDF
        // ====================================================
        async function fetchPaperNotes(pdfId) {
            if (!pdfId) return;
            const res = await fetch(`api_translate.php?action=list_notes&pdf_id=${pdfId}`);
            const data = await res.json();
            if (data.status === 'success') {
                paperNotes = data.notes || [];
                document.getElementById('notes-count-badge').textContent = paperNotes.length;
                renderPaperNotesList();
                refreshNotedSegmentsHighlight();
            }
        }

        async function saveCurrentTranslationAsNote() {
            if (!activePaperMeta || !lastTranslatedObj) return;
            const comment = document.getElementById('note-user-comment').value.trim();

            const fd = new FormData();
            fd.append('action', 'save_note');
            fd.append('pdf_id', activePaperMeta.id);
            fd.append('page_number', currentPaperPage);
            fd.append('para_hash', lastTranslatedObj.para_hash || '');
            fd.append('original_text', lastTranslatedObj.original);
            fd.append('translated_text', lastTranslatedObj.translated);
            fd.append('user_comment', comment);

            const res = await fetch('api_translate.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'success') {
                document.getElementById('note-user-comment').value = '';
                await fetchPaperNotes(activePaperMeta.id);
                refreshNotedSegmentsHighlight();
                alert('📌 این جمله با موفقیت در فیش‌های مقاله ذخیره شد و روی صفحه با رنگ سبز علامت خورد.');
            } else {
                alert(data.message || 'خطا در ذخیره فیش');
            }
        }

        function renderPaperNotesList() {
            const listEl = document.getElementById('paper-notes-list');
            const query  = (document.getElementById('notes-search-input')?.value || '').trim().toLowerCase();

            if (paperNotes.length === 0) {
                listEl.innerHTML = `<div class="text-center text-slate-400 py-10">هنوز فیشی برای این مقاله ذخیره نکرده‌اید.</div>`;
                return;
            }

            const filtered = paperNotes.filter(n => {
                if (!query) return true;
                return String(n.original_text).toLowerCase().includes(query) ||
                       String(n.translated_text).toLowerCase().includes(query) ||
                       String(n.user_comment || '').toLowerCase().includes(query);
            });

            if (filtered.length === 0) {
                listEl.innerHTML = `<div class="text-center text-slate-400 py-8">هیچ فیشی با عبارت جستجو شده مطابقت ندارد.</div>`;
                return;
            }

            listEl.innerHTML = filtered.map((n, idx) => `
                <div class="p-3 bg-slate-50 rounded-2xl border border-slate-200 space-y-1.5">
                    <div class="flex items-center justify-between">
                        <button type="button" onclick="jumpToPaperPage(${n.page_number})" class="bg-indigo-100 hover:bg-indigo-200 text-indigo-800 px-2 py-0.5 rounded-md font-extrabold text-[10px] transition">
                            📄 فیش #${idx + 1} • صفحه ${n.page_number} (پرش به صفحه)
                        </button>
                        <button type="button" onclick="deletePaperNote(${n.id})" class="text-slate-400 hover:text-rose-600 text-xs" title="حذف فیش">🗑️</button>
                    </div>
                    <div class="text-[11px] text-slate-500 font-sans dir-ltr text-left leading-relaxed bg-white p-2 rounded-lg border border-slate-100 select-text">${escapeHtml(n.original_text)}</div>
                    <div class="text-xs text-slate-800 font-bold leading-6 select-text">${escapeHtml(n.translated_text)}</div>
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
            if (paperNotes.length === 0) {
                alert('فیشی برای کپی کردن وجود ندارد.');
                return;
            }
            const text = paperNotes.map((n, i) =>
                `[${i + 1}] صفحه ${n.page_number}:\n🇬🇧 ${n.original_text}\n🇮🇷 ${n.translated_text}${n.user_comment ? '\n💡 نکته: ' + n.user_comment : ''}`
            ).join('\n\n------------------------\n\n');

            navigator.clipboard.writeText(text).then(() => {
                alert('تمام فیش‌های این مقاله در کلیپ‌بورد کپی شدند!');
            });
        }

        function exportNotesToWord() {
            if (!activePaperMeta || paperNotes.length === 0) {
                alert('ابتدا حداقل یک فیش از مقاله ذخیره کنید تا فایل Word آن تولید شود.');
                return;
            }

            const paperTitle = escapeHtml(activePaperMeta.title);
            let rowsHtml = '';

            paperNotes.forEach((n, i) => {
                rowsHtml += `
                    <div style="border:1px solid #cbd5e1; border-radius:8px; padding:12px; margin-bottom:14px; background:#f8fafc;">
                        <div style="font-weight:bold; color:#312e81; margin-bottom:6px; font-size:11pt;">
                            📌 فیش شماره ${i + 1} (صفحه ${n.page_number} مقاله)
                        </div>
                        <div dir="ltr" style="text-align:left; font-family:Calibri, Arial, sans-serif; font-size:10.5pt; color:#334155; background:#ffffff; padding:8px; border:1px solid #e2e8f0; margin-bottom:8px;">
                            ${escapeHtml(n.original_text)}
                        </div>
                        <div dir="rtl" style="text-align:right; font-family:Tahoma, Arial, sans-serif; font-size:11pt; color:#0f172a; font-weight:bold; line-height:1.7;">
                            🇮🇷 ترجمه: ${escapeHtml(n.translated_text)}
                        </div>
                        ${n.user_comment ? `<div dir="rtl" style="margin-top:6px; color:#92400e; background:#fef3c7; padding:6px; font-size:10pt;">💡 یادداشت پژوهشی: ${escapeHtml(n.user_comment)}</div>` : ''}
                    </div>
                `;
            });

            const wordHtml = `
                <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40" dir="rtl">
                <head>
                    <meta charset="utf-8">
                    <title>فیش‌های پژوهشی مقاله - ${paperTitle}</title>
                </head>
                <body style="font-family:Tahoma, Arial, sans-serif; direction:rtl; text-align:right; padding:20px;">
                    <h2 style="color:#1e1b4b; border-bottom:2px solid #4f46e5; padding-bottom:8px;">📑 دفترچه فیش‌های پژوهشی و ترجمه مقاله</h2>
                    <p style="font-size:11pt; color:#475569;"><b>عنوان مقاله:</b> ${paperTitle} | <b>تعداد فیش‌ها:</b> ${paperNotes.length}</p>
                    <hr style="margin:15px 0;">
                    ${rowsHtml}
                </body>
                </html>
            `;

            const blob = new Blob(['\ufeff', wordHtml], { type: 'application/msword;charset=utf-8' });
            const url  = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            const safeFileName = activePaperMeta.title.replace(/[^a-zA-Z0-9آ-ی_\-]/g, '_');
            link.download = `Notes_${safeFileName}.doc`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }

        function exportNotesToPdfPrint() {
            if (!activePaperMeta || paperNotes.length === 0) {
                alert('ابتدا حداقل یک فیش از مقاله ذخیره کنید.');
                return;
            }

            const paperTitle = escapeHtml(activePaperMeta.title);
            const itemsHtml = paperNotes.map((n, i) => `
                <div class="note-card">
                    <div class="note-header">
                        <span>📌 فیش شماره ${i + 1}</span>
                        <span>صفحه ${n.page_number} مقاله</span>
                    </div>
                    <div class="en-box" dir="ltr">${escapeHtml(n.original_text)}</div>
                    <div class="fa-box" dir="rtl">${escapeHtml(n.translated_text)}</div>
                    ${n.user_comment ? `<div class="comment-box" dir="rtl">💡 یادداشت شخصی: ${escapeHtml(n.user_comment)}</div>` : ''}
                </div>
            `).join('');

            const printWin = window.open('', '_blank');
            if (!printWin) {
                alert('لطفاً اجازه باز شدن پنجره (Pop-up) را در مرورگر بدهید.');
                return;
            }

            printWin.document.write(`
                <!DOCTYPE html>
                <html lang="fa" dir="rtl">
                <head>
                    <meta charset="UTF-8">
                    <title>فیش‌های پژوهشی - ${paperTitle}</title>
                    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
                    <style>
                        body { font-family: 'Vazirmatn', Tahoma, sans-serif; color: #0f172a; padding: 24px; max-width: 850px; margin: 0 auto; }
                        .top-header { border-bottom: 2px solid #4f46e5; padding-bottom: 12px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
                        .top-header h1 { font-size: 18px; margin: 0; color: #1e1b4b; }
                        .note-card { border: 1px solid #cbd5e1; border-radius: 12px; padding: 14px; margin-bottom: 14px; page-break-inside: avoid; background: #fff; }
                        .note-header { display: flex; justify-content: space-between; font-weight: bold; font-size: 12px; color: #4338ca; margin-bottom: 8px; }
                        .en-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; font-family: sans-serif; font-size: 12px; color: #334155; text-align: left; line-height: 1.5; margin-bottom: 8px; }
                        .fa-box { font-size: 13px; font-weight: bold; line-height: 1.8; color: #0f172a; }
                        .comment-box { margin-top: 8px; background: #fef3c7; border: 1px solid #fde68a; border-radius: 8px; padding: 8px; font-size: 11px; color: #92400e; }
                        @media print { .no-print { display: none; } body { padding: 0; } }
                    </style>
                </head>
                <body>
                    <div class="no-print" style="margin-bottom:16px; text-align:left;">
                        <button onclick="window.print()" style="background:#4f46e5;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-family:inherit;font-weight:bold;cursor:pointer;">
                            🖨️ ذخیره به عنوان PDF / چاپ
                        </button>
                    </div>
                    <div class="top-header">
                        <div>
                            <h1>📑 فیش‌های پژوهشی مقاله: ${paperTitle}</h1>
                            <div style="font-size:12px;color:#64748b;margin-top:4px;">پژوهشگر: <?php echo htmlspecialchars($_SESSION['full_name']); ?> | تعداد فیش‌ها: ${paperNotes.length}</div>
                        </div>
                    </div>
                    ${itemsHtml}
                    <script>
                        window.onload = function() { setTimeout(function() { window.print(); }, 400); };
                    <\/script>
                </body>
                </html>
            `);
            printWin.document.close();
        }

        // ====================================================
        // کنترل‌های صفحه، زوم، حالت شب و آپلود/ایمپورت مقاله
        // ====================================================
        function changePaperPage(delta) {
            if (!paperDocInstance) return;
            const next = currentPaperPage + delta;
            if (next >= 1 && next <= paperDocInstance.numPages) {
                stopSpeech();
                currentPaperPage = next;
                renderPaperPage(currentPaperPage);
                document.getElementById('paper-viewport-container').scrollTop = 0;
            }
        }

        function jumpToPaperPage(val) {
            if (!paperDocInstance) return;
            stopSpeech();
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