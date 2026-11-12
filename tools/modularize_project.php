<?php
require_once dirname(__DIR__) . '/config/config.php';
require_login();

@set_time_limit(180);

$user_id = (int)$_SESSION['user_id'];
$sub_info = function_exists('get_user_subscription_info')
    ? get_user_subscription_info($pdo, $user_id)
    : ['is_admin' => ($user_id === 1)];

if (empty($sub_info['is_admin']) && $user_id !== 1) {
    http_response_code(403);
    die('<div style="font-family:tahoma;text-align:center;margin-top:60px;direction:rtl;">⛔ فقط مدیر کل سامانه مجاز به اجرای ماژولارساز کدهای پروژه است.</div>');
}

$root_dir    = dirname(__DIR__);
$inc_dir     = $root_dir . '/includes';
$css_dir     = $root_dir . '/assets/css';
$js_dir      = $root_dir . '/assets/js';
$backup_dir  = $root_dir . '/backups_monolith';

foreach ([$inc_dir, $css_dir, $js_dir, $backup_dir] as $d) {
    if (!is_dir($d)) {
        @mkdir($d, 0755, true);
    }
}

$target_pages = ['index', 'paper_reader', 'exam', 'courses', 'admin'];
$logs = [];

// ====================================================
// ۱. ساخت فایل‌های مشترک در پوشه includes/
// ====================================================
function build_shared_includes($inc_dir) {
    // الف) فایل هدر مشترک بارگذاری استایل‌ها و فونت‌ها (includes/head_assets.php)
    $head_assets_code = <<<'PHP'
<?php
/**
 * Shared Head Assets Loader (Local-First CSS, Vazirmatn Font & Page Module CSS)
 * Variables used: $page_title, $page_slug, $needs_pdfjs
 */
$page_title  = $page_title ?? 'سامانه هوشمند مطالعه، پژوهش و آموزش';
$page_slug   = $page_slug ?? 'index';
$needs_pdfjs = $needs_pdfjs ?? false;
$css_ver     = is_file(dirname(__DIR__) . "/../assets/css/{$page_slug}.css") ? filemtime(dirname(__DIR__) . "/../assets/css/{$page_slug}.css") : '4.4';
?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($page_title); ?></title>

    <!-- 1. Local Offline-First Assets -->
    <link href="assets/css/tailwind-local.css" rel="stylesheet">
    <link href="assets/css/vazirmatn-local.css" rel="stylesheet">

    <!-- 2. CDN Fallbacks -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">

<?php if ($needs_pdfjs): ?>
    <!-- 3. Local-First PDF.js Engine -->
    <script src="assets/pdfjs/pdf.min.js"
            onerror="this.onerror=null;this.src='https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js';"></script>
<?php endif; ?>

<?php if (is_file(dirname(__DIR__) . "/../assets/css/{$page_slug}.css")): ?>
    <!-- 4. Page-Specific Modular Stylesheet -->
    <link href="assets/css/<?php echo htmlspecialchars($page_slug); ?>.css?v=<?php echo $css_ver; ?>" rel="stylesheet">
<?php endif; ?>
PHP;
    file_put_contents($inc_dir . '/head_assets.php', $head_assets_code);

    // ب) فایل نوار ناوبری مشترک بالای سایت (includes/navbar.php)
    $navbar_code = <<<'PHP'
<?php
/**
 * Shared Top Navigation Bar (includes/navbar.php)
 * Expects: $active_nav ('calendar' | 'paper' | 'exam' | 'courses'), $initial_sub
 */
$active_nav = $active_nav ?? 'calendar';
$badge_map = [
    'calendar' => ['icon' => '📅', 'label' => 'برنامه‌ریز', 'cls' => 'bg-indigo-600'],
    'paper'    => ['icon' => '🌐', 'label' => 'مقاله‌خوان', 'cls' => 'bg-emerald-600'],
    'exam'     => ['icon' => '📝', 'label' => 'آزمون‌ساز',  'cls' => 'bg-rose-600'],
    'courses'  => ['icon' => '🎬', 'label' => 'آکادمی',     'cls' => 'bg-indigo-600'],
];
$cur_badge = $badge_map[$active_nav] ?? $badge_map['calendar'];
?>
<header id="main-app-header" class="no-print bg-slate-900 text-white px-3 md:px-4 py-2 flex flex-wrap items-center justify-between gap-2 shadow-md z-30 shrink-0">
    <div class="flex items-center gap-2 md:gap-3">
        <div class="flex items-center gap-1.5">
            <span class="<?php echo $cur_badge['cls']; ?> text-white px-2 py-1 rounded-lg font-extrabold text-xs">
                <?php echo $cur_badge['icon'] . ' ' . $cur_badge['label']; ?>
            </span>
            <span class="font-bold text-xs md:text-sm truncate max-w-[90px] sm:max-w-none">
                <?php echo htmlspecialchars($_SESSION['full_name'] ?? ''); ?>
            </span>
        </div>

        <nav class="flex bg-slate-800 p-0.5 rounded-xl border border-slate-700 overflow-x-auto">
            <a href="index.php" class="px-2 md:px-3 py-1.5 rounded-lg text-xs font-bold transition shrink-0 <?php echo $active_nav === 'calendar' ? 'bg-indigo-600 text-white shadow' : 'text-slate-300 hover:text-white'; ?>">
                📅 تقویم
            </a>
            <a href="index.php?tab=pdf" class="px-2 md:px-3 py-1.5 rounded-lg text-xs font-bold text-slate-300 hover:text-white transition shrink-0">
                📚 جزوه‌خوان
            </a>
            <a href="paper_reader.php" class="px-2 md:px-3 py-1.5 rounded-lg text-xs font-bold transition shrink-0 <?php echo $active_nav === 'paper' ? 'bg-indigo-600 text-white shadow' : 'text-slate-300 hover:text-white'; ?>">
                🌐 مقاله‌خوان
            </a>
            <a href="exam.php" class="px-2 md:px-3 py-1.5 rounded-lg text-xs font-bold transition shrink-0 <?php echo $active_nav === 'exam' ? 'bg-indigo-600 text-white shadow' : 'text-slate-300 hover:text-white'; ?>">
                📝 آزمون‌ها
            </a>
            <a href="courses.php" class="px-2 md:px-3 py-1.5 rounded-lg text-xs font-bold transition shrink-0 <?php echo $active_nav === 'courses' ? 'bg-indigo-600 text-white shadow' : 'text-slate-300 hover:text-white'; ?>">
                🎬 ویدیوها
            </a>
        </nav>
    </div>

    <div class="flex items-center gap-1.5 md:gap-2 text-xs">
        <button type="button" id="header-sub-btn" onclick="openSubscriptionModal()" class="<?php echo !empty($initial_sub['is_vip']) ? 'bg-amber-400 text-slate-950' : 'bg-indigo-600 hover:bg-indigo-500 text-white border border-indigo-400'; ?> px-2.5 py-1.5 rounded-lg font-extrabold flex items-center gap-1 shadow transition">
            <?php if (!empty($initial_sub['is_vip'])): ?>
                <span>👑 ویژه (<?php echo (int)$initial_sub['days_left']; ?> روز)</span>
            <?php else: ?>
                <span>⭐ ارتقا به اشتراک ویژه</span>
            <?php endif; ?>
        </button>

        <?php if (!empty($initial_sub['is_admin'])): ?>
            <a href="admin.php" class="bg-slate-800 hover:bg-slate-700 border border-amber-500 text-amber-300 px-2.5 py-1.5 rounded-lg font-bold flex items-center gap-1 transition">
                <span>🛠️</span>
                <span class="hidden lg:inline">پنل مدیریت</span>
            </a>
        <?php endif; ?>

        <a href="auth.php?logout=1" class="text-slate-400 hover:text-rose-400 font-bold px-1.5 py-1">خروج</a>
    </div>
</header>
PHP;
    file_put_contents($inc_dir . '/navbar.php', $navbar_code);

    // ج) فایل مودال مشترک اشتراک ویژه (includes/subscription_modal.php)
    $sub_modal_code = <<<'PHP'
<?php
/**
 * Shared VIP Subscription & Promo Code Modal (includes/subscription_modal.php)
 */
if (!defined('VIP_MAX_PDF_MB')) define('VIP_MAX_PDF_MB', 64);
if (!defined('PLAN_1M_PRICE'))  define('PLAN_1M_PRICE', '۵۰۰,۰۰۰');
if (!defined('PLAN_3M_PRICE'))  define('PLAN_3M_PRICE', '۱,۲۰۰,۰۰۰');
?>
<div id="subscription-modal" class="fixed inset-0 bg-slate-900 bg-opacity-75 hidden items-end sm:items-center justify-center sm:p-4 z-50">
    <div class="bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl w-full max-w-2xl max-h-[94vh] flex flex-col overflow-hidden">
        <div class="bg-slate-900 text-white px-5 py-4 flex items-center justify-between shrink-0">
            <div class="flex items-center gap-2.5">
                <span class="text-2xl">👑</span>
                <div>
                    <h3 class="font-extrabold text-sm md:text-base text-amber-300">اشتراک ویژه (VIP) سامانه جامع مطالعه و آموزش</h3>
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
                            <li>✓ دسترسی کامل به دوره‌های ویدیویی، جزوات و آزمون‌های VIP</li>
                            <li>✓ آپلود نامحدود جزوه و مقاله (تا <?php echo VIP_MAX_PDF_MB; ?>MB)</li>
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
                            <li>✓ دسترسی نامحدود به تمام ویدیوها، آزمون‌ها و جزوات</li>
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
PHP;
    file_put_contents($inc_dir . '/subscription_modal.php', $sub_modal_code);
}

// ====================================================
// ۲. تابع تفکیک خودکار CSS و JS از یک فایل PHP بدون آسیب به متغیرهای PHP
// ====================================================
function modularize_single_php_file($slug, $root_dir, $css_dir, $js_dir, $backup_dir, &$logs) {
    $php_path = $root_dir . '/' . $slug . '.php';
    if (!is_file($php_path)) {
        $logs[] = "⚠️ فایل {$slug}.php یافت نشد (رد شد).";
        return;
    }

    $content = file_get_contents($php_path);

    // اگر قبلاً ماژولار شده باشد، از روی بکاپ اصلی بازخوانی شود تا تمیز پردازش گردد
    $backup_path = $backup_dir . '/' . $slug . '.php.bak';
    if (!is_file($backup_path)) {
        file_put_contents($backup_path, $content);
        $logs[] = "📦 نسخه پشتیبان از {$slug}.php در پوشه backups_monolith ذخیره شد.";
    } else {
        $content = file_get_contents($backup_path);
    }

    // الف) استخراج تگ <style>...</style> به فایل assets/css/{slug}.css
    if (preg_match('#<style[^>]*>([\s\S]*?)</style>#i', $content, $style_match)) {
        $raw_css = trim($style_match[1]);
        $css_file_path = $css_dir . '/' . $slug . '.css';
        file_put_contents($css_file_path, "/* Modular Stylesheet for {$slug}.php */\n" . $raw_css . "\n");
        $css_link_tag = "<link href=\"assets/css/{$slug}.css?v=<?php echo @filemtime(dirname(__DIR__) . '/assets/css/{$slug}.css') ?: '4.4'; ?>\" rel=\"stylesheet\">";
        $content = preg_replace('#<style[^>]*>[\s\S]*?</style>#i', $css_link_tag, $content, 1);
        $logs[] = "🎨 استایل‌های {$slug}.php به فایل مجزای assets/css/{$slug}.css منتقل شد.";
    }

    // ب) استخراج تگ پایانی <script>...</script> (بدون src) به فایل assets/js/{slug}.js
    if (preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)</script>#i', $content, $script_matches, PREG_SET_ORDER)) {
        $last_script = end($script_matches);
        $full_script_tag = $last_script[0];
        $raw_js = trim($last_script[1]);

        // جداسازی خطوطی که حاوی تگ <?php هستند (پل متغیرهای PHP به JS) از بدنه خالص جاوااسکریپت
        $js_lines = explode("\n", $raw_js);
        $php_bridge_lines = [];
        $pure_js_lines    = [];

        foreach ($js_lines as $line) {
            if (stripos($line, '<?php') !== false || stripos($line, '<?=') !== false) {
                $php_bridge_lines[] = $line;
            } else {
                $pure_js_lines[] = $line;
            }
        }

        $pure_js_code = trim(implode("\n", $pure_js_lines));
        $js_file_path = $js_dir . '/' . $slug . '.js';
        file_put_contents($js_file_path, "/**\n * Modular Frontend Logic for {$slug}.php\n */\n" . $pure_js_code . "\n");

        $replacement_script = '';
        if (!empty($php_bridge_lines)) {
            $replacement_script .= "    <!-- PHP-to-JS Configuration Bridge -->\n    <script>\n" . implode("\n", $php_bridge_lines) . "\n    </script>\n";
        }
        $replacement_script .= "    <!-- Modular External JavaScript -->\n    <script src=\"assets/js/{$slug}.js?v=<?php echo @filemtime(dirname(__DIR__) . '/assets/js/{$slug}.js') ?: '4.4'; ?>\"></script>";

        // جایگزینی آخرین تگ <script> با پل تنظیمات + لینک فایل خارجی .js
        $pos = strrpos($content, $full_script_tag);
        if ($pos !== false) {
            $content = substr_replace($content, $replacement_script, $pos, strlen($full_script_tag));
            $logs[] = "⚡ کدهای جاوااسکریپت {$slug}.php (" . count($pure_js_lines) . " خط) به فایل مجزای assets/js/{$slug}.js منتقل شد.";
        }
    }

    // ج) جایگزینی مودال اشتراک ویژه تکراری با فایل مشترک includes/subscription_modal.php در صفحات کاربری
    if (in_array($slug, ['exam', 'courses'], true)) {
        $nav_map = ['exam' => 'exam', 'courses' => 'courses'];
        $nav_key = $nav_map[$slug];

        // جایگزینی هدر مشترک
        $content = preg_replace(
            '#<header\s+id="main-app-header"[\s\S]*?</header>#i',
            "<?php \$active_nav = '{$nav_key}'; require dirname(__DIR__) . '/includes/navbar.php'; ?>",
            $content,
            1
        );

        // جایگزینی مودال اشتراک مشترک
        $content = preg_replace(
            '#<div\s+id="subscription-modal"[\s\S]*?</div>\s*</div>\s*</div>\s*(?=<script|<!--)#i',
            "<?php require dirname(__DIR__) . '/includes/subscription_modal.php'; ?>\n\n    ",
            $content,
            1
        );
    }

    file_put_contents($php_path, $content);
}

// اجرای عملیات ماژولارسازی
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    $act = $_POST['action_type'];

    if ($act === 'modularize') {
        build_shared_includes($inc_dir);
        $logs[] = "✅ فایل‌های مشترک در پوشه includes/ (navbar.php, head_assets.php, subscription_modal.php) ساخته شدند.";

        foreach ($target_pages as $slug) {
            modularize_single_php_file($slug, $root_dir, $css_dir, $js_dir, $backup_dir, $logs);
        }
        $logs[] = "🎉 کل پروژه با موفقیت به معماری ماژولار استاندارد (جداشدن CSS، JS و کامپوننت‌های مشترک) تبدیل شد!";
    } elseif ($act === 'restore_backups') {
        $restored = 0;
        foreach ($target_pages as $slug) {
            $bak = $backup_dir . '/' . $slug . '.php.bak';
            if (is_file($bak)) {
                copy($bak, $root_dir . '/' . $slug . '.php');
                $restored++;
            }
        }
        $logs[] = "🔄 {$restored} فایل اصلی از پوشه پشتیبان (backups_monolith) به حالت اولیه بازگردانی شدند.";
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>معمارساز و ماژولارکننده خودکار پروژه PHP v4.4</title>
    <link href="assets/css/tailwind-local.css" rel="stylesheet">
    <link href="assets/css/vazirmatn-local.css" rel="stylesheet">
    <style>
        body { font-family: 'Vazirmatn', Tahoma, sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 24px; direction: rtl; }
        .card { max-width: 780px; margin: 20px auto; background: #1e293b; border: 1px solid #334155; border-radius: 20px; padding: 26px; box-shadow: 0 20px 45px rgba(0,0,0,0.45); }
        h1 { font-size: 18px; color: #fbbf24; margin-top: 0; }
        p { font-size: 13px; color: #cbd5e1; line-height: 1.85; }
        .grid-files { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; margin: 18px 0; }
        .file-item { background: #0f172a; border: 1px solid #334155; padding: 12px; border-radius: 12px; font-size: 12px; }
        .ok { color: #34d399; font-weight: bold; }
        .wait { color: #94a3b8; }
        .btn-main { width: 100%; background: #4f46e5; color: #fff; border: none; padding: 14px; border-radius: 12px; font-size: 14px; font-weight: bold; cursor: pointer; margin-top: 8px; }
        .btn-main:hover { background: #4338ca; }
        .btn-restore { width: 100%; background: #334155; color: #cbd5e1; border: 1px solid #475569; padding: 10px; border-radius: 12px; font-size: 12px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .btn-restore:hover { background: #475569; color: #fff; }
        .log-box { background: #022c22; border: 1px solid #059669; color: #a7f3d0; padding: 14px; border-radius: 12px; font-size: 12px; line-height: 2; margin-bottom: 16px; }
        .links { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 18px; padding-top: 14px; border-top: 1px solid #334155; }
        .links a { flex: 1; text-align: center; background: #0f172a; border: 1px solid #334155; color: #e2e8f0; text-decoration: none; padding: 10px; border-radius: 10px; font-size: 12px; font-weight: bold; }
        .links a:hover { border-color: #6366f1; color: #fff; }
    </style>
</head>
<body>
    <div class="card">
        <h1>🏗️ تبدیل خودکار پروژه به معماری ماژولار استاندارد (MVC / Asset Separation)</h1>
        <p>
            با اجرای این ابزار، ابتدا از تمام فایل‌های فعلی شما در پوشه <code>backups_monolith/</code> نسخه پشتیبان تهیه می‌شود؛ سپس تمام تگ‌های <code>&lt;style&gt;</code> به پوشه <code>assets/css/</code>، کدهای جاوااسکریپت <code>&lt;script&gt;</code> به پوشه <code>assets/js/</code> و هدر و مودال‌های مشترک به پوشه <code>includes/</code> منتقل می‌شوند.
        </p>

        <?php if (!empty($logs)): ?>
            <div class="log-box">
                <?php foreach ($logs as $l): ?>
                    <div><?php echo htmlspecialchars($l); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="grid-files">
            <?php foreach ($target_pages as $slug):
                $has_js  = is_file($js_dir . '/' . $slug . '.js');
                $has_css = is_file($css_dir . '/' . $slug . '.css');
                $js_kb   = $has_js ? round(filesize($js_dir . '/' . $slug . '.js') / 1024, 1) . ' KB' : '---';
            ?>
            <div class="file-item">
                <div style="font-weight:bold;color:#fff;margin-bottom:6px;">📄 <?php echo htmlspecialchars($slug . '.php'); ?></div>
                <div class="<?php echo $has_css ? 'ok' : 'wait'; ?>">
                    <?php echo $has_css ? "✓ assets/css/{$slug}.css" : "○ استایل جدا نشده"; ?>
                </div>
                <div class="<?php echo $has_js ? 'ok' : 'wait'; ?>" style="margin-top:3px;">
                    <?php echo $has_js ? "✓ assets/js/{$slug}.js ({$js_kb})" : "○ جاوااسکریپت جدا نشده"; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <form method="POST">
            <input type="hidden" name="action_type" value="modularize">
            <button type="submit" class="btn-main">
                🚀 اجرای جداسازی خودکار CSS، JS و کامپوننت‌های مشترک (Modularize)
            </button>
        </form>

        <form method="POST" onsubmit="return confirm('آیا می‌خواهید فایل‌ها از نسخه پشتیبان به حالت تک‌فایلی قبلی بازگردانی شوند؟');">
            <input type="hidden" name="action_type" value="restore_backups">
            <button type="submit" class="btn-restore">
                🔄 بازگردانی فایل‌ها به حالت تک‌فایلی اولیه (Restore Backup)
            </button>
        </form>

        <div class="links">
            <a href="index.php">📅 تقویم و جزوه‌خوان</a>
            <a href="paper_reader.php">🌐 مقاله‌خوان</a>
            <a href="exam.php">📝 آزمون‌ها</a>
            <a href="courses.php">🎬 ویدیوها</a>
            <a href="admin.php">🛠️ پنل مدیریت</a>
        </div>
    </div>
</body>
</html>