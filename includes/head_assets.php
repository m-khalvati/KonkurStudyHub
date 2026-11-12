<?php
/**
 * Shared Head Assets Loader (Local-First CSS, Vazirmatn Font & Page Module CSS)
 * Variables used: $page_title, $page_slug, $needs_pdfjs
 */
$page_title  = $page_title ?? 'سامانه هوشمند مطالعه، پژوهش و آموزش';
$page_slug   = $page_slug ?? 'index';
$needs_pdfjs = $needs_pdfjs ?? false;
$css_ver     = is_file(__DIR__ . "/../assets/css/{$page_slug}.css") ? filemtime(__DIR__ . "/../assets/css/{$page_slug}.css") : '4.4';
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

<?php if (is_file(__DIR__ . "/../assets/css/{$page_slug}.css")): ?>
    <!-- 4. Page-Specific Modular Stylesheet -->
    <link href="assets/css/<?php echo htmlspecialchars($page_slug); ?>.css?v=<?php echo $css_ver; ?>" rel="stylesheet">
<?php endif; ?>