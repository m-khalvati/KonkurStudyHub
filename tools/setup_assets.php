<?php
require_once dirname(__DIR__) . '/config/config.php';
require_login();

@set_time_limit(300);

$user_id = (int)$_SESSION['user_id'];
$sub_info = function_exists('get_user_subscription_info')
    ? get_user_subscription_info($pdo, $user_id)
    : ['is_admin' => ($user_id === 1)];

if (empty($sub_info['is_admin']) && $user_id !== 1) {
    http_response_code(403);
    die('<div style="font-family:tahoma;text-align:center;margin-top:60px;direction:rtl;">⛔ فقط مدیر کل سامانه مجاز به اجرای بومی‌ساز دارایی‌های هاست است.</div>');
}

$base_dir   = dirname(__DIR__) . '/assets';
$css_dir    = $base_dir . '/css';
$fonts_dir  = $base_dir . '/fonts';
$pdfjs_dir  = $base_dir . '/pdfjs';

foreach ([$base_dir, $css_dir, $fonts_dir, $pdfjs_dir] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// تابع دانلود فایل از چند سرور پشتیبان با cURL
function download_remote_asset(array $urls, $dest_path) {
    foreach ($urls as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (LocalAssetInstaller/4.3)'
        ]);
        $data = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && !empty($data) && strlen($data) > 500) {
            if (file_put_contents($dest_path, $data) !== false) {
                return ['ok' => true, 'url' => $url, 'size' => strlen($data)];
            }
        }
    }
    return ['ok' => false];
}

// لایه کامل کلاس‌های Tailwind v3 و کلاس‌های سفارشی پروژه برای کارکرد ۱۰۰٪ آفلاین و بدون CDN
function get_project_tailwind_v3_shim_css() {
    return <<<'CSS'
/* ==========================================================================
   Project Local Tailwind v3 & Custom Utilities Layer (100% Offline Ready)
   ========================================================================== */
*, *::before, *::after { box-sizing: border-box; }
body { font-family: 'Vazirmatn', Tahoma, sans-serif; }

/* Slate Palette */
.bg-slate-50 { background-color: #f8fafc !important; }
.bg-slate-100 { background-color: #f1f5f9 !important; }
.bg-slate-200 { background-color: #e2e8f0 !important; }
.bg-slate-300 { background-color: #cbd5e1 !important; }
.bg-slate-700 { background-color: #334155 !important; }
.bg-slate-800 { background-color: #1e293b !important; }
.bg-slate-900 { background-color: #0f172a !important; }
.bg-slate-950 { background-color: #020617 !important; }
.hover\:bg-slate-50:hover { background-color: #f8fafc !important; }
.hover\:bg-slate-100:hover { background-color: #f1f5f9 !important; }
.hover\:bg-slate-200:hover { background-color: #e2e8f0 !important; }
.hover\:bg-slate-600:hover { background-color: #475569 !important; }
.hover\:bg-slate-700:hover { background-color: #334155 !important; }

.text-slate-200 { color: #e2e8f0 !important; }
.text-slate-300 { color: #cbd5e1 !important; }
.text-slate-400 { color: #94a3b8 !important; }
.text-slate-500 { color: #64748b !important; }
.text-slate-600 { color: #475569 !important; }
.text-slate-700 { color: #334155 !important; }
.text-slate-800 { color: #1e293b !important; }
.text-slate-900 { color: #0f172a !important; }
.text-slate-950 { color: #020617 !important; }
.hover\:text-slate-700:hover { color: #334155 !important; }

.border-slate-100 { border-color: #f1f5f9 !important; }
.border-slate-200 { border-color: #e2e8f0 !important; }
.border-slate-300 { border-color: #cbd5e1 !important; }
.border-slate-400 { border-color: #94a3b8 !important; }
.border-slate-500 { border-color: #64748b !important; }
.border-slate-600 { border-color: #475569 !important; }
.border-slate-700 { border-color: #334155 !important; }
.border-slate-800 { border-color: #1e293b !important; }

/* Indigo Extensions */
.bg-indigo-50 { background-color: #eef2ff !important; }
.bg-indigo-100 { background-color: #e0e7ff !important; }
.bg-indigo-600 { background-color: #4f46e5 !important; }
.bg-indigo-700 { background-color: #4338ca !important; }
.bg-indigo-950 { background-color: #1e1b4b !important; }
.hover\:bg-indigo-50:hover { background-color: #eef2ff !important; }
.hover\:bg-indigo-100:hover { background-color: #e0e7ff !important; }
.hover\:bg-indigo-200:hover { background-color: #c7d2fe !important; }
.hover\:bg-indigo-500:hover { background-color: #6366f1 !important; }
.hover\:bg-indigo-700:hover { background-color: #4338ca !important; }
.text-indigo-200 { color: #c7d2fe !important; }
.text-indigo-300 { color: #a5b4fc !important; }
.text-indigo-600 { color: #4f46e5 !important; }
.text-indigo-700 { color: #4338ca !important; }
.text-indigo-800 { color: #3730a3 !important; }
.text-indigo-900 { color: #312e81 !important; }
.text-indigo-950 { color: #1e1b4b !important; }
.border-indigo-100 { border-color: #e0e7ff !important; }
.border-indigo-200 { border-color: #c7d2fe !important; }
.border-indigo-300 { border-color: #a5b4fc !important; }
.border-indigo-400 { border-color: #818cf8 !important; }
.border-indigo-500 { border-color: #6366f1 !important; }
.border-indigo-600 { border-color: #4f46e5 !important; }
.border-indigo-700 { border-color: #4338ca !important; }
.border-indigo-800 { border-color: #3730a3 !important; }
.hover\:border-indigo-300:hover { border-color: #a5b4fc !important; }
.hover\:border-indigo-400:hover { border-color: #818cf8 !important; }
.hover\:border-indigo-500:hover { border-color: #6366f1 !important; }

/* Emerald Palette */
.bg-emerald-50 { background-color: #ecfdf5 !important; }
.bg-emerald-100 { background-color: #d1fae5 !important; }
.bg-emerald-400 { background-color: #34d399 !important; }
.bg-emerald-500 { background-color: #10b981 !important; }
.bg-emerald-600 { background-color: #059669 !important; }
.bg-emerald-700 { background-color: #047857 !important; }
.bg-emerald-900 { background-color: #064e3b !important; }
.bg-emerald-950 { background-color: #022c22 !important; }
.hover\:bg-emerald-100:hover { background-color: #d1fae5 !important; }
.hover\:bg-emerald-400:hover { background-color: #34d399 !important; }
.hover\:bg-emerald-500:hover { background-color: #10b981 !important; }
.hover\:bg-emerald-700:hover { background-color: #047857 !important; }
.text-emerald-200 { color: #a7f3d0 !important; }
.text-emerald-300 { color: #6ee7b7 !important; }
.text-emerald-400 { color: #34d399 !important; }
.text-emerald-600 { color: #059669 !important; }
.text-emerald-700 { color: #047857 !important; }
.text-emerald-800 { color: #065f46 !important; }
.text-emerald-900 { color: #064e3b !important; }
.text-emerald-950 { color: #022c22 !important; }
.border-emerald-100 { border-color: #d1fae5 !important; }
.border-emerald-200 { border-color: #a7f3d0 !important; }
.border-emerald-300 { border-color: #6ee7b7 !important; }
.border-emerald-400 { border-color: #34d399 !important; }
.border-emerald-500 { border-color: #10b981 !important; }
.border-emerald-700 { border-color: #047857 !important; }

/* Amber Palette */
.bg-amber-50 { background-color: #fffbeb !important; }
.bg-amber-100 { background-color: #fef3c7 !important; }
.bg-amber-200 { background-color: #fde68a !important; }
.bg-amber-300 { background-color: #fcd34d !important; }
.bg-amber-400 { background-color: #fbbf24 !important; }
.bg-amber-500 { background-color: #f59e0b !important; }
.bg-amber-600 { background-color: #d97706 !important; }
.hover\:bg-amber-50:hover { background-color: #fffbeb !important; }
.hover\:bg-amber-100:hover { background-color: #fef3c7 !important; }
.hover\:bg-amber-200:hover { background-color: #fde68a !important; }
.hover\:bg-amber-300:hover { background-color: #fcd34d !important; }
.hover\:bg-amber-500:hover { background-color: #f59e0b !important; }
.hover\:bg-amber-600:hover { background-color: #d97706 !important; }
.text-amber-200 { color: #fde68a !important; }
.text-amber-300 { color: #fcd34d !important; }
.text-amber-500 { color: #f59e0b !important; }
.text-amber-600 { color: #d97706 !important; }
.text-amber-700 { color: #b45309 !important; }
.text-amber-800 { color: #92400e !important; }
.text-amber-900 { color: #78350f !important; }
.text-amber-950 { color: #451a03 !important; }
.border-amber-100 { border-color: #fef3c7 !important; }
.border-amber-200 { border-color: #fde68a !important; }
.border-amber-300 { border-color: #fcd34d !important; }
.border-amber-400 { border-color: #fbbf24 !important; }
.border-amber-500 { border-color: #f59e0b !important; }

/* Rose & Sky & Purple Palette */
.bg-rose-50 { background-color: #fff1f2 !important; }
.bg-rose-100 { background-color: #ffe4e6 !important; }
.bg-rose-400 { background-color: #fb7185 !important; }
.bg-rose-500 { background-color: #f43f5e !important; }
.bg-rose-600 { background-color: #e11d48 !important; }
.bg-rose-950 { background-color: #4c0519 !important; }
.hover\:bg-rose-50:hover { background-color: #fff1f2 !important; }
.hover\:bg-rose-100:hover { background-color: #ffe4e6 !important; }
.hover\:bg-rose-200:hover { background-color: #fecdd3 !important; }
.hover\:bg-rose-500:hover { background-color: #f43f5e !important; }
.hover\:bg-rose-600:hover { background-color: #e11d48 !important; }
.hover\:bg-rose-700:hover { background-color: #be123c !important; }
.text-rose-200 { color: #fecdd3 !important; }
.text-rose-300 { color: #fda4af !important; }
.text-rose-400 { color: #fb7185 !important; }
.text-rose-500 { color: #f43f5e !important; }
.text-rose-600 { color: #e11d48 !important; }
.text-rose-700 { color: #be123c !important; }
.text-rose-800 { color: #9f1239 !important; }
.text-rose-900 { color: #881337 !important; }
.hover\:text-rose-400:hover { color: #fb7185 !important; }
.hover\:text-rose-600:hover { color: #e11d48 !important; }
.hover\:text-rose-700:hover { color: #be123c !important; }
.border-rose-200 { border-color: #fecdd3 !important; }
.border-rose-300 { border-color: #fda4af !important; }
.border-rose-400 { border-color: #fb7185 !important; }
.border-rose-500 { border-color: #f43f5e !important; }

.bg-sky-50 { background-color: #f0f9ff !important; }
.bg-sky-400 { background-color: #38bdf8 !important; }
.hover\:bg-sky-100:hover { background-color: #e0f2fe !important; }
.text-sky-300 { color: #7dd3fc !important; }
.text-sky-700 { color: #0369a1 !important; }
.border-sky-200 { border-color: #bae6fd !important; }
.bg-purple-950 { background-color: #3b0764 !important; }

/* Gradients */
.bg-gradient-to-l { background-image: linear-gradient(to left, #0f172a, #1e1b4b, #0f172a) !important; }
.bg-gradient-to-r.from-amber-500 { background-image: linear-gradient(to right, #f59e0b, #facc15) !important; }
.bg-gradient-to-r.from-slate-900 { background-image: linear-gradient(to right, #0f172a, #1e1b4b, #0f172a) !important; }
.bg-gradient-to-r.from-indigo-600 { background-image: linear-gradient(to right, #4f46e5, #9333ea) !important; }

/* Arbitrary Font Sizes & Dimensions */
.text-\[9px\] { font-size: 9px !important; line-height: 1.2 !important; }
.text-\[10px\] { font-size: 10px !important; line-height: 1.35 !important; }
.text-\[11px\] { font-size: 11px !important; line-height: 1.45 !important; }
.h-\[42vh\] { height: 42vh !important; }
.h-\[78vh\] { height: 78vh !important; }
.h-\[46px\] { height: 46px !important; }
.max-h-\[94vh\] { max-height: 94vh !important; }
.max-h-\[92vh\] { max-height: 92vh !important; }
.max-h-\[82vh\] { max-height: 82vh !important; }
.max-h-\[80vh\] { max-height: 80vh !important; }
.max-h-\[75vh\] { max-height: 75vh !important; }
.max-h-\[72vh\] { max-height: 72vh !important; }
.max-h-\[580px\] { max-height: 580px !important; }
.max-h-\[560px\] { max-height: 560px !important; }
.max-h-\[550px\] { max-height: 550px !important; }
.max-h-\[520px\] { max-height: 520px !important; }
.max-h-\[500px\] { max-height: 500px !important; }
.min-h-\[420px\] { min-height: 420px !important; }
.max-w-\[85vw\] { max-width: 85vw !important; }
.max-w-\[86vw\] { max-width: 86vw !important; }
.max-w-\[94vw\] { max-width: 94vw !important; }
.max-w-\[1600px\] { max-width: 1600px !important; }
.max-w-\[220px\] { max-width: 220px !important; }
.max-w-\[140px\] { max-width: 140px !important; }
.max-w-\[125px\] { max-width: 125px !important; }
.max-w-\[90px\] { max-width: 90px !important; }
.max-w-\[85px\] { max-width: 85px !important; }
.min-w-\[260px\] { min-width: 260px !important; }
.min-w-\[200px\] { min-width: 200px !important; }
.min-w-\[180px\] { min-width: 180px !important; }
.min-w-\[48px\] { min-width: 48px !important; }

/* Spacing, Borders & Layout Shims */
.shrink-0 { flex-shrink: 0 !important; }
.rounded-xl { border-radius: 0.75rem !important; }
.rounded-2xl { border-radius: 1rem !important; }
.rounded-3xl { border-radius: 1.5rem !important; }
.rounded-t-2xl { border-top-left-radius: 1rem !important; border-top-right-radius: 1rem !important; }
.rounded-t-3xl { border-top-left-radius: 1.5rem !important; border-top-right-radius: 1.5rem !important; }
.gap-0\.5 { gap: 0.125rem !important; }
.gap-1\.5 { gap: 0.375rem !important; }
.gap-2\.5 { gap: 0.625rem !important; }
.gap-3\.5 { gap: 0.875rem !important; }
.p-0\.5 { padding: 0.125rem !important; }
.p-1\.5 { padding: 0.375rem !important; }
.p-2\.5 { padding: 0.625rem !important; }
.p-3\.5 { padding: 0.875rem !important; }
.px-1\.5 { padding-left: 0.375rem !important; padding-right: 0.375rem !important; }
.px-2\.5 { padding-left: 0.625rem !important; padding-right: 0.625rem !important; }
.px-3\.5 { padding-left: 0.875rem !important; padding-right: 0.875rem !important; }
.py-0\.5 { padding-top: 0.125rem !important; padding-bottom: 0.125rem !important; }
.py-1\.5 { padding-top: 0.375rem !important; padding-bottom: 0.375rem !important; }
.py-2\.5 { padding-top: 0.625rem !important; padding-bottom: 0.625rem !important; }
.pt-1\.5 { padding-top: 0.375rem !important; }
.pb-2\.5 { padding-bottom: 0.625rem !important; }
.inset-0 { top: 0 !important; right: 0 !important; bottom: 0 !important; left: 0 !important; }
.inset-x-0 { left: 0 !important; right: 0 !important; }
.inset-y-0 { top: 0 !important; bottom: 0 !important; }
.shadow-xs, .shadow-2xs { box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05) !important; }
.backdrop-blur-xs, .backdrop-blur-md { backdrop-filter: blur(6px); }
.bg-slate-900\/60, .bg-slate-900\/65, .bg-slate-900\/75, .bg-slate-950\/65, .bg-black\/60, .bg-black\/85 {
    background-color: rgba(15, 23, 42, 0.72) !important;
}

@media (min-width: 768px) {
    .md\:h-auto { height: auto !important; }
    .md\:w-96 { width: 24rem !important; }
    .md\:rounded-none { border-radius: 0 !important; }
}
@media (min-width: 1024px) {
    .lg\:w-\[430px\] { width: 430px !important; }
    .lg\:grid-cols-12 { grid-template-columns: repeat(12, minmax(0, 1fr)) !important; }
    .lg\:col-span-7 { grid-column: span 7 / span 7 !important; }
    .lg\:col-span-5 { grid-column: span 5 / span 5 !important; }
    .lg\:col-span-2 { grid-column: span 2 / span 2 !important; }
    .lg\:grid-cols-6 { grid-template-columns: repeat(6, minmax(0, 1fr)) !important; }
    .lg\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; }
}
CSS;
}

$logs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_localization'])) {
    // ۱. دانلود و ساخت فایل استایل کامل محلی (assets/css/tailwind-local.css)
    $tw_path = $css_dir . '/tailwind.base.css';
    $dl_tw = download_remote_asset([
        'https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css',
        'https://unpkg.com/tailwindcss@2.2.19/dist/tailwind.min.css',
        'https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css'
    ], $tw_path);

    $base_tw_content = $dl_tw['ok'] ? file_get_contents($tw_path) : '';
    $combined_css = $base_tw_content . "\n" . get_project_tailwind_v3_shim_css();
    file_put_contents($css_dir . '/tailwind-local.css', $combined_css);
    $logs[] = $dl_tw['ok']
        ? "✅ فایل استایل محلی (assets/css/tailwind-local.css) با حجم " . round(strlen($combined_css) / 1024) . " کیلوبایت روی هاست ذخیره شد."
        : "⚠️ هسته خارجی دانلود نشد اما لایه استایل اضطراری در assets/css/tailwind-local.css ساخته شد.";

    // ۲. دانلود فونت‌های فارسی وزیرمتن (.woff2) روی هاست
    $font_files = [
        'Vazirmatn-Regular.woff2'   => 400,
        'Vazirmatn-Medium.woff2'    => 500,
        'Vazirmatn-Bold.woff2'      => 700,
        'Vazirmatn-ExtraBold.woff2' => 800,
        'Vazirmatn-Black.woff2'     => 900,
    ];
    $downloaded_fonts = 0;
    foreach ($font_files as $fname => $weight) {
        $f_res = download_remote_asset([
            "https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/fonts/webfonts/{$fname}",
            "https://unpkg.com/vazirmatn@33.003/fonts/webfonts/{$fname}"
        ], $fonts_dir . '/' . $fname);
        if ($f_res['ok']) $downloaded_fonts++;
    }

    $font_css = '';
    foreach ($font_files as $fname => $weight) {
        if (is_file($fonts_dir . '/' . $fname)) {
            $font_css .= "@font-face {\n  font-family: 'Vazirmatn';\n  src: url('../fonts/{$fname}') format('woff2');\n  font-weight: {$weight};\n  font-style: normal;\n  font-display: swap;\n}\n";
        }
    }
    file_put_contents($css_dir . '/vazirmatn-local.css', $font_css);
    $logs[] = "✅ {$downloaded_fonts} وزن از فونت فارسی وزیرمتن (Vazirmatn) در پوشه assets/fonts/ ذخیره شد.";

    // ۳. دانلود موتور PDF.js و Worker آن روی هاست (assets/pdfjs/)
    $dl_pdf = download_remote_asset([
        'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js',
        'https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.min.js',
        'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js'
    ], $pdfjs_dir . '/pdf.min.js');

    $dl_worker = download_remote_asset([
        'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js',
        'https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.worker.min.js',
        'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js'
    ], $pdfjs_dir . '/pdf.worker.min.js');

    if ($dl_pdf['ok'] && $dl_worker['ok']) {
        $logs[] = "✅ موتور نمایشگر PDF (pdf.min.js و pdf.worker.min.js) به صورت کامل روی هاست شما در پوشه assets/pdfjs/ ذخیره شد.";
    } else {
        $logs[] = "⚠️ یکی از فایل‌های PDF.js دانلود نشد؛ لطفاً دوباره دکمه را بزنید.";
    }

    // ۴. بروزرسانی خودکار فایل‌های پروژه برای خواندن اولویت اول از روی هاست خودتان
    $target_php_files = ['index.php', 'paper_reader.php', 'exam.php', 'admin.php', 'auth.php'];
    $patched_count = 0;

    foreach ($target_php_files as $php_file) {
        $full_p = dirname(__DIR__) . '/' . $php_file;
        if (!is_file($full_p)) continue;

        $content = file_get_contents($full_p);
        $orig_content = $content;

        // الف) تزریق استایل و فونت محلی در بالای تگ <head>
        $local_head_tags = "<!-- Local Host Assets (Offline-First) -->\n    <link href=\"assets/css/tailwind-local.css\" rel=\"stylesheet\">\n    <link href=\"assets/css/vazirmatn-local.css\" rel=\"stylesheet\">";
        if (strpos($content, 'assets/css/tailwind-local.css') === false) {
            $content = preg_replace(
                '#<script\s+src="https://cdn\.tailwindcss\.com"></script>#i',
                $local_head_tags . "\n    <script src=\"https://cdn.tailwindcss.com\"></script>",
                $content,
                1
            );
        }

        // ب) تغییر تگ اسکریپت PDF.js به نسخه داخل هاست
        $content = preg_replace(
            '#<script\s+src="https://cdn\.jsdelivr\.net/npm/pdfjs-dist@3\.11\.174/build/pdf\.min\.js"[^>]*></script>#i',
            '<script src="assets/pdfjs/pdf.min.js" onerror="this.onerror=null;this.src=\'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js\';"></script>',
            $content
        );

        // ج) افزودن مسیر محلی assets/pdfjs/ به عنوان اولویت اول آرایه PDFJS_CDN_LIST در جاوااسکریپت
        if (strpos($content, 'const PDFJS_CDN_LIST = [') !== false && strpos($content, "lib: 'assets/pdfjs/pdf.min.js'") === false) {
            $local_cdn_entry = "const PDFJS_CDN_LIST = [\n            {\n                lib: 'assets/pdfjs/pdf.min.js',\n                worker: 'assets/pdfjs/pdf.worker.min.js',\n                cmaps: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/cmaps/',\n                fonts: 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/standard_fonts/'\n            },";
            $content = str_replace('const PDFJS_CDN_LIST = [', $local_cdn_entry, $content);
        }

        if ($content !== $orig_content) {
            file_put_contents($full_p, $content);
            $patched_count++;
        }
    }

    $logs[] = "🎉 {$patched_count} فایل اصلی پروژه (index.php, paper_reader.php, exam.php, admin.php, auth.php) به صورت خودکار به پوشه assets هاست متصل شدند!";
}

// بررسی وضعیت فعلی فایل‌ها روی هاست
$status_items = [
    'استایل کامل Tailwind محلی' => is_file($css_dir . '/tailwind-local.css'),
    'فونت فارسی وزیرمتن محلی'  => is_file($css_dir . '/vazirmatn-local.css') && is_file($fonts_dir . '/Vazirmatn-Bold.woff2'),
    'موتور اصلی PDF.js محلی'   => is_file($pdfjs_dir . '/pdf.min.js'),
    'پردازشگر Worker PDF محلی' => is_file($pdfjs_dir . '/pdf.worker.min.js'),
];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بومی‌ساز خودکار استایل‌ها، فونت و PDF.js روی هاست</title>
    <style>
        body { font-family: Tahoma, sans-serif; background: #0f172a; color: #f8fafc; margin: 0; padding: 24px; direction: rtl; }
        .card { max-width: 680px; margin: 20px auto; background: #1e293b; border: 1px solid #334155; border-radius: 18px; padding: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.4); }
        h1 { font-size: 18px; color: #fbbf24; margin-top: 0; }
        p { font-size: 13px; color: #cbd5e1; line-height: 1.8; }
        .status-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 18px 0; }
        .status-box { background: #0f172a; border: 1px solid #334155; padding: 12px; border-radius: 12px; font-size: 12px; display: flex; justify-content: space-between; align-items: center; }
        .badge-ok { background: #065f46; color: #6ee7b7; padding: 3px 8px; border-radius: 6px; font-weight: bold; }
        .badge-missing { background: #7f1d1d; color: #fca5a5; padding: 3px 8px; border-radius: 6px; font-weight: bold; }
        .btn-run { width: 100%; background: #4f46e5; color: #ffffff; border: none; padding: 14px; border-radius: 12px; font-size: 14px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .btn-run:hover { background: #4338ca; }
        .log-box { background: #022c22; border: 1px solid #059669; color: #a7f3d0; padding: 14px; border-radius: 12px; font-size: 12px; line-height: 1.9; margin-bottom: 16px; }
        .nav-links { display: flex; gap: 10px; margin-top: 18px; padding-top: 14px; border-top: 1px solid #334155; }
        .nav-links a { flex: 1; text-align: center; background: #334155; color: #fff; text-decoration: none; padding: 10px; border-radius: 10px; font-size: 12px; font-weight: bold; }
        .nav-links a:hover { background: #475569; }
    </style>
</head>
<body>
    <div class="card">
        <h1>🛡️ بومی‌ساز ۱۰۰٪ ضدقطعی استایل‌ها، فونت و PDF.js روی هاست</h1>
        <p>
            با زدن دکمه زیر، تمام فایل‌های استایل (Tailwind CSS)، فونت‌های فارسی (Vazirmatn) و موتور نمایشگر جزوه و مقاله (PDF.js) مستقیم از طریق سرور دانلود شده و در پوشه <code>assets/</code> داخل هاست خودت ذخیره می‌شوند و تمام صفحات سایت به آن‌ها متصل می‌گردند.
        </p>

        <?php if (!empty($logs)): ?>
            <div class="log-box">
                <?php foreach ($logs as $l): ?>
                    <div><?php echo htmlspecialchars($l); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="status-grid">
            <?php foreach ($status_items as $label => $is_ready): ?>
                <div class="status-box">
                    <span><?php echo htmlspecialchars($label); ?></span>
                    <?php if ($is_ready): ?>
                        <span class="badge-ok">✓ نصب روی هاست</span>
                    <?php else: ?>
                        <span class="badge-missing">نصب نشده</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="POST">
            <input type="hidden" name="run_localization" value="1">
            <button type="submit" class="btn-run">
                🚀 دانلود فایل‌ها روی هاست و بومی‌سازی خودکار کل صفحات
            </button>
        </form>

        <div class="nav-links">
            <a href="admin.php">🛠️ پنل مدیریت</a>
            <a href="exam.php">📝 صفحه آزمون‌ها</a>
            <a href="index.php">📅 تقویم و جزوه‌خوان</a>
            <a href="paper_reader.php">🌐 مقاله‌خوان</a>
        </div>
    </div>
</body>
</html>