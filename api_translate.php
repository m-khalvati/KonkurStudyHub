<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'لطفاً ابتدا وارد حساب کاربری شوید.']);
    exit;
}

$user_id  = (int)$_SESSION['user_id'];
$action   = $_GET['action'] ?? ($_POST['action'] ?? '');
$sub_info = get_user_subscription_info($pdo, $user_id);

// ====================================================
// ایجاد خودکار پوشه اختصاصی مقالات (uploads/papers/)
// ====================================================
$papers_dir = __DIR__ . '/uploads/papers/';
if (!is_dir($papers_dir)) {
    mkdir($papers_dir, 0755, true);
}
$htaccess_path = $papers_dir . '.htaccess';
if (!is_file($htaccess_path)) {
    $ht_content = "<IfModule mod_headers.c>\n    Header unset ETag\n    Header set Accept-Ranges \"none\"\n</IfModule>\nFileETag None\nSetEnv no-gzip 1\n";
    @file_put_contents($htaccess_path, $ht_content);
}

// تابع کمکی: دریافت لیست شناسه‌های PDF مجاز برای کاربر (عادی = ۱ فایل، ویژه = نامحدود)
function get_allowed_pdf_ids($pdo, $user_id, $is_vip) {
    $stmt = $pdo->prepare("SELECT id FROM pdfs WHERE user_id = ? ORDER BY id DESC");
    $stmt->execute([$user_id]);
    $all_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($is_vip) {
        return array_map('intval', $all_ids);
    }
    return array_map('intval', array_slice($all_ids, 0, FREE_MAX_PDF_COUNT));
}

// ====================================================
// اکشن استریم باینری فایل مقاله (بدون خطای 412 وب‌سرور)
// ====================================================
if ($action === 'raw') {
    $pdf_id = (int)($_GET['pdf_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, file_path FROM pdfs WHERE id = ? AND user_id = ?");
    $stmt->execute([$pdf_id, $user_id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        exit('Paper not found');
    }

    $allowed_ids = get_allowed_pdf_ids($pdo, $user_id, $sub_info['is_vip']);
    if (!in_array((int)$row['id'], $allowed_ids, true)) {
        http_response_code(403);
        exit('Subscription required');
    }

    $full_path = __DIR__ . '/' . $row['file_path'];
    if (!is_file($full_path)) {
        http_response_code(404);
        exit('Physical file missing');
    }

    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($full_path));
    header('Accept-Ranges: none');
    header('Cache-Control: private, no-transform, no-store, must-revalidate');
    header('Pragma: no-cache');
    readfile($full_path);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// ====================================================
// توابع موتورهای ترجمه (Google GTX + MyMemory + AI Gemini/OpenAI)
// ====================================================

// پاکسازی متن انگلیسی استخراج‌شده از PDF (حذف خط تیره شکستگی سطرها و فاصله‌های اضافی)
function sanitize_pdf_english_text($text) {
    $text = trim($text);
    // چسباندن کلماتی که در انتهای سطر با خط تیره شکسته شده‌اند (مثلاً spatio-\ntemporal)
    $text = preg_replace('/(\w)-\s+([a-z])/u', '$1$2', $text);
    // تبدیل اینترهای داخل یک پاراگراف به فاصله استاندارد
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

// ۱. موتور رایگان Google Translate (GTX)
function translate_via_google_gtx($text, $source = 'en', $target = 'fa') {
    $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=' . urlencode($source) . '&tl=' . urlencode($target) . '&dt=t';
    $post_data = http_build_query(['q' => $text]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post_data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8']
    ]);

    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || empty($res)) {
        return ['ok' => false, 'error' => 'عدم پاسخ از سرور گوگل'];
    }

    $decoded = json_decode($res, true);
    if (!is_array($decoded) || !isset($decoded[0]) || !is_array($decoded[0])) {
        return ['ok' => false, 'error' => 'ساختار پاسخ گوگل نامعتبر بود'];
    }

    $translated = '';
    foreach ($decoded[0] as $segment) {
        if (isset($segment[0])) {
            $translated .= $segment[0];
        }
    }

    return !empty(trim($translated))
        ? ['ok' => true, 'text' => trim($translated)]
        : ['ok' => false, 'error' => 'ترجمه خالی برگشت داده شد'];
}

// ۲. موتور رایگان MyMemory API
function translate_via_mymemory($text, $source = 'en', $target = 'fa') {
    // محدودیت هر درخواست رایگان MyMemory حدود 500 کاراکتر است؛ برای متون بلندتر تکه‌بندی می‌کنیم
    $chunks = [];
    if (mb_strlen($text, 'UTF-8') <= 480) {
        $chunks[] = $text;
    } else {
        $sentences = preg_split('/(?<=[.?!])\s+/', $text);
        $current = '';
        foreach ($sentences as $s) {
            if (mb_strlen($current . ' ' . $s, 'UTF-8') > 450) {
                if (!empty($current)) $chunks[] = trim($current);
                $current = $s;
            } else {
                $current .= ($current === '' ? '' : ' ') . $s;
            }
        }
        if (!empty($current)) $chunks[] = trim($current);
    }

    $final_translation = [];
    foreach ($chunks as $chunk) {
        $url = 'https://api.mymemory.translated.net/get?q=' . urlencode($chunk) . '&langpair=' . urlencode($source . '|' . $target);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (AcademicPaperReader/3.0)'
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || empty($res)) {
            return ['ok' => false, 'error' => 'خطا در ارتباط با سرویس MyMemory'];
        }
        $data = json_decode($res, true);
        if (!empty($data['responseData']['translatedText'])) {
            $final_translation[] = html_entity_decode($data['responseData']['translatedText'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            return ['ok' => false, 'error' => 'محدودیت کاراکتر در سرویس MyMemory'];
        }
    }

    return ['ok' => true, 'text' => implode(' ', $final_translation)];
}

// ۳. موتور تخصصی هوش مصنوعی (پشتیبانی از Gemini و OpenAI/OpenRouter/DeepSeek)
function translate_via_ai_engine($text, $provider, $api_key, $model_name, $openai_endpoint) {
    $system_prompt = "تو یک مترجم برجسته و تخصصی مقالات علمی و دانشگاهی (Academic Paper Translator) هستی. "
        . "متن انگلیسی زیر را به فارسی روان، دقیق و علمی ترجمه کن. "
        . "قوانین مهم:\n"
        . "1. برای اصطلاحات تخصصی کلیدی، معادل فارسی را بنویس و اصطلاح اصلی انگلیسی را داخل پرانتز بیاور؛ مثلاً: مطالعه حذفی (Ablation Study).\n"
        . "2. فرمول‌ها، متغیرهای ریاضی و شماره رفرنس‌ها مانند [12] را بدون تغییر حفظ کن.\n"
        . "3. اگر ورودی فقط یک کلمه یا عبارت کوتاه است، معنی دقیق علمی و عمومی آن را به همراه یک توضیح کوتاه بنویس.\n"
        . "4. هیچ جمله‌ی اضافه‌ای در ابتدا یا انتهای پیام ننویس و مستقیماً فقط متن ترجمه شده را برگردان.";

    if ($provider === 'gemini') {
        $model = !empty($model_name) ? $model_name : 'gemini-2.5-flash';
        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($model) . ":generateContent?key=" . urlencode($api_key);

        $payload = [
            'system_instruction' => [
                'parts' => [['text' => $system_prompt]]
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => "متن جهت ترجمه تخصصی به فارسی:\n\n" . $text]]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.2
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE)
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($res, true);
        if ($code === 200 && isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            return ['ok' => true, 'text' => trim($data['candidates'][0]['content']['parts'][0]['text'])];
        }
        $err_msg = $data['error']['message'] ?? 'خطا در پاسخ وب‌سرویس Gemini';
        return ['ok' => false, 'error' => $err_msg];
    } else {
        // استاندارد OpenAI / OpenRouter / DeepSeek / Groq
        $endpoint = !empty($openai_endpoint) ? $openai_endpoint : 'https://api.openai.com/v1/chat/completions';
        $model    = !empty($model_name) ? $model_name : 'gpt-4o-mini';

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user',   'content' => $text]
            ],
            'temperature' => 0.2
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE)
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($res, true);
        if ($code === 200 && isset($data['choices'][0]['message']['content'])) {
            return ['ok' => true, 'text' => trim($data['choices'][0]['message']['content'])];
        }
        $err_msg = $data['error']['message'] ?? 'خطا در وب‌سرویس هوش مصنوعی';
        return ['ok' => false, 'error' => $err_msg];
    }
}

try {
    // ====================================================
    // ۱. دریافت لیست مقالات، لیست جزوات (جهت ایمپورت) و تنظیمات هوش مصنوعی
    // ====================================================
    if ($action === 'list_papers') {
        $stmt = $pdo->prepare("
            SELECT id, folder_id, is_paper, title, category, file_path, total_pages, last_page, created_at
            FROM pdfs
            WHERE user_id = ?
            ORDER BY id DESC
        ");
        $stmt->execute([$user_id]);
        $all_pdfs = $stmt->fetchAll();

        $papers   = [];
        $booklets = [];

        foreach ($all_pdfs as $idx => $item) {
            // اعمال قانون قفل اشتراک روی فایل‌های مازاد کاربر عادی
            $item['is_locked'] = (!$sub_info['is_vip'] && $idx >= FREE_MAX_PDF_COUNT);
            if ((int)$item['is_paper'] === 1) {
                $papers[] = $item;
            } else {
                $booklets[] = $item;
            }
        }

        $has_server_ai_key = (defined('AI_API_KEY') && !empty(AI_API_KEY) && AI_API_KEY !== 'YOUR_AI_API_KEY');

        echo json_encode([
            'status'       => 'success',
            'papers'       => $papers,
            'booklets'     => $booklets,
            'subscription' => $sub_info,
            'ai_config'    => [
                'enabled'        => defined('AI_TRANSLATION_ENABLED') ? (bool)AI_TRANSLATION_ENABLED : false,
                'vip_only'       => defined('AI_VIP_ONLY') ? (bool)AI_VIP_ONLY : true,
                'provider'       => defined('AI_PROVIDER') ? AI_PROVIDER : 'gemini',
                'has_server_key' => $has_server_ai_key
            ]
        ]);
        exit;
    }

    // ====================================================
    // ۲. آپلود مقاله جدید مستقیماً در پوشه مجزای uploads/papers/
    // ====================================================
    if ($action === 'upload_paper') {
        $title = trim($_POST['title'] ?? '');
        if (empty($title)) {
            echo json_encode(['status' => 'error', 'message' => 'لطفاً عنوان مقاله را وارد کنید.']);
            exit;
        }

        // بررسی محدودیت تعداد فایل برای کاربران عادی
        if (!$sub_info['is_vip']) {
            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM pdfs WHERE user_id = ?");
            $cntStmt->execute([$user_id]);
            if ((int)$cntStmt->fetchColumn() >= FREE_MAX_PDF_COUNT) {
                echo json_encode([
                    'status'   => 'error',
                    'need_vip' => true,
                    'message'  => 'سهمیه ۱ فایل رایگان شما پر شده است. برای آپلود مقالات بیشتر، فایل قبلی را حذف کنید یا اشتراک ویژه تهیه نمایید.'
                ]);
                exit;
            }
        }

        if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error', 'message' => 'خطا در دریافت فایل PDF مقاله.']);
            exit;
        }

        $file = $_FILES['pdf_file'];
        $max_allowed_bytes = $sub_info['max_pdf_mb'] * 1024 * 1024;
        if ($file['size'] > $max_allowed_bytes) {
            $file_mb = round($file['size'] / (1024 * 1024), 1);
            echo json_encode([
                'status'   => 'error',
                'need_vip' => !$sub_info['is_vip'],
                'message'  => "حجم فایل مقاله ({$file_mb} مگابایت) بیشتر از سقف مجاز حساب شما ({$sub_info['max_pdf_mb']} مگابایت) است."
            ]);
            exit;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if ($ext !== 'pdf' || $mime !== 'application/pdf') {
            echo json_encode(['status' => 'error', 'message' => 'فقط فایل‌های استاندارد PDF مجاز هستند.']);
            exit;
        }

        // ذخیره در پوشه مستقل uploads/papers/
        $new_filename = 'paper_u' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
        $dest_path    = $papers_dir . $new_filename;
        $db_rel_path  = 'uploads/papers/' . $new_filename;

        if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
            echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی نوشتن در پوشه uploads/papers هاست.']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO pdfs (user_id, folder_id, is_paper, title, category, file_path, total_pages, last_page, starred_pages)
            VALUES (?, NULL, 1, ?, 'مقاله علمی', ?, 1, 1, '[]')
        ");
        $stmt->execute([$user_id, $title, $db_rel_path]);
        $new_id = (int)$pdo->lastInsertId();

        echo json_encode([
            'status'  => 'success',
            'message' => 'مقاله با موفقیت در پوشه مقالات آپلود شد.',
            'paper'   => [
                'id'          => $new_id,
                'title'       => $title,
                'file_path'   => $db_rel_path,
                'total_pages' => 1,
                'last_page'   => 1,
                'is_locked'   => false
            ]
        ]);
        exit;
    }

    // ====================================================
    // ۳. افزودن/انتقال یک فایل از بخش «جزوه‌خوان» به پوشه «مقاله‌خوان»
    // ====================================================
    if ($action === 'import_from_booklets') {
        $source_pdf_id = (int)($_POST['pdf_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM pdfs WHERE id = ? AND user_id = ?");
        $stmt->execute([$source_pdf_id, $user_id]);
        $src = $stmt->fetch();

        if (!$src) {
            echo json_encode(['status' => 'error', 'message' => 'فایل مورد نظر در جزوه‌خوان یافت نشد.']);
            exit;
        }

        $src_full_path = __DIR__ . '/' . $src['file_path'];
        if (!is_file($src_full_path)) {
            echo json_encode(['status' => 'error', 'message' => 'فایل فیزیکی جزوه یافت نشد.']);
            exit;
        }

        // اگر کاربر عادی است، چون سقف ۱ فایل دارد، همان فایل را به پوشه مقالات منتقل می‌کنیم تا به سقف نخورد
        // اگر کاربر ویژه است، یک نسخه مستقل در پوشه uploads/papers/ برایش کپی می‌کنیم
        $new_filename = 'paper_u' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
        $dest_path    = $papers_dir . $new_filename;
        $db_rel_path  = 'uploads/papers/' . $new_filename;

        if (!copy($src_full_path, $dest_path)) {
            echo json_encode(['status' => 'error', 'message' => 'خطا در کپی فایل به پوشه مقالات.']);
            exit;
        }

        if (!$sub_info['is_vip']) {
            // تبدیل رکورد فعلی به مقاله تا سهمیه ۱ فایل کاربر عادی حفظ شود
            @unlink($src_full_path);
            $upd = $pdo->prepare("UPDATE pdfs SET is_paper = 1, file_path = ?, category = 'مقاله علمی' WHERE id = ? AND user_id = ?");
            $upd->execute([$db_rel_path, $source_pdf_id, $user_id]);
            $target_id = $source_pdf_id;
        } else {
            // ثبت رکورد جدید در لیست مقالات برای کاربر ویژه
            $ins = $pdo->prepare("
                INSERT INTO pdfs (user_id, folder_id, is_paper, title, category, file_path, total_pages, last_page, starred_pages)
                VALUES (?, NULL, 1, ?, 'مقاله علمی', ?, ?, 1, '[]')
            ");
            $ins->execute([$user_id, $src['title'], $db_rel_path, (int)$src['total_pages']]);
            $target_id = (int)$pdo->lastInsertId();
        }

        echo json_encode([
            'status'   => 'success',
            'message'  => 'فایل با موفقیت به بخش مقاله‌خوان اضافه شد.',
            'paper_id' => $target_id
        ]);
        exit;
    }

    // ====================================================
    // ۴. ترجمه هوشمند متن انتخاب‌شده (همراه با کش دیتابیس و انتخاب موتور)
    // ====================================================
    if ($action === 'translate') {
        $raw_text       = $_POST['text'] ?? '';
        $engine         = $_POST['engine'] ?? 'google'; // 'google', 'mymemory', 'ai'
        $custom_api_key = trim($_POST['custom_api_key'] ?? '');
        $custom_prov    = trim($_POST['custom_provider'] ?? '');

        $clean_text = sanitize_pdf_english_text($raw_text);
        if (empty($clean_text)) {
            echo json_encode(['status' => 'error', 'message' => 'متنی برای ترجمه انتخاب نشده است.']);
            exit;
        }

        if (mb_strlen($clean_text, 'UTF-8') > 4500) {
            $clean_text = mb_substr($clean_text, 0, 4500, 'UTF-8');
        }

        // ۱. بررسی کش دیتابیس (اگر قبلاً همین متن با همین موتور ترجمه شده باشد، فوری برگردانده شود)
        $source_hash = md5(strtolower($clean_text));
        $cacheStmt = $pdo->prepare("SELECT translated_text FROM translation_cache WHERE source_hash = ? AND engine = ? LIMIT 1");
        $cacheStmt->execute([$source_hash, $engine]);
        $cached_row = $cacheStmt->fetch();

        if ($cached_row && !empty($cached_row['translated_text'])) {
            echo json_encode([
                'status'          => 'success',
                'clean_source'    => $clean_text,
                'translated_text' => $cached_row['translated_text'],
                'engine_used'     => $engine,
                'cached'          => true
            ]);
            exit;
        }

        // ۲. اجرای ترجمه بر اساس موتور انتخابی کاربر
        $result = ['ok' => false, 'error' => 'موتور نامعتبر'];
        $engine_used = $engine;

        if ($engine === 'ai') {
            // بررسی اینکه آیا موتور هوش مصنوعی در config.php فعال است یا کاربر کلید شخصی داده است
            $ai_master_enabled = defined('AI_TRANSLATION_ENABLED') ? (bool)AI_TRANSLATION_ENABLED : false;
            if (!$ai_master_enabled && empty($custom_api_key)) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'موتور هوش مصنوعی سرور در حال حاضر غیرفعال است. لطفاً از موتور گوگل/MyMemory استفاده کنید یا کلید API شخصی خود را وارد نمایید.'
                ]);
                exit;
            }

            // اگر کاربر کلید شخصی نداده و می‌خواهد از کلید سرور استفاده کند، بررسی VIP بودن
            if (empty($custom_api_key)) {
                if (defined('AI_VIP_ONLY') && AI_VIP_ONLY && !$sub_info['is_vip']) {
                    echo json_encode([
                        'status'   => 'error',
                        'need_vip' => true,
                        'message'  => 'استفاده از «مترجم تخصصی هوش مصنوعی (Academic AI)» مخصوص مشترکین ویژه است. برای استفاده رایگان می‌توانید از موتور Google یا MyMemory استفاده کنید و یا اشتراک ویژه تهیه نمایید.'
                    ]);
                    exit;
                }

                $active_key      = defined('AI_API_KEY') ? AI_API_KEY : '';
                $active_provider = defined('AI_PROVIDER') ? AI_PROVIDER : 'gemini';
                $active_model    = defined('AI_MODEL_NAME') ? AI_MODEL_NAME : 'gemini-2.5-flash';
                $active_endpoint = defined('AI_OPENAI_ENDPOINT') ? AI_OPENAI_ENDPOINT : '';

                if (empty($active_key) || $active_key === 'YOUR_AI_API_KEY') {
                    echo json_encode([
                        'status'  => 'error',
                        'message' => 'کلید API هوش مصنوعی در تنظیمات سرور ثبت نشده است. می‌توانید در بخش تنظیمات بالای پنل، کلید رایگان Gemini خود را وارد کنید.'
                    ]);
                    exit;
                }
            } else {
                // استفاده از کلید شخصی وارد شده توسط خود کاربر (BYOK)
                $active_key      = $custom_api_key;
                $active_provider = in_array($custom_prov, ['gemini', 'openai']) ? $custom_prov : 'gemini';
                $active_model    = ($active_provider === 'gemini') ? 'gemini-2.5-flash' : 'gpt-4o-mini';
                $active_endpoint = 'https://api.openai.com/v1/chat/completions';
            }

            $result = translate_via_ai_engine($clean_text, $active_provider, $active_key, $active_model, $active_endpoint);
        } elseif ($engine === 'mymemory') {
            $result = translate_via_mymemory($clean_text, 'en', 'fa');
            // اگر MyMemory به سقف روزانه خورد، خودکار از Google GTX کمک بگیرد
            if (!$result['ok']) {
                $result = translate_via_google_gtx($clean_text, 'en', 'fa');
                $engine_used = 'google (پشتیبان خودکار)';
            }
        } else {
            // موتور پیش‌فرض: Google Translate GTX
            $result = translate_via_google_gtx($clean_text, 'en', 'fa');
            // اگر گوگل پاسخ نداد، خودکار به MyMemory سوییچ کند
            if (!$result['ok']) {
                $result = translate_via_mymemory($clean_text, 'en', 'fa');
                $engine_used = 'mymemory (پشتیبان خودکار)';
            }
        }

        if (!$result['ok']) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'خطا در ترجمه: ' . ($result['error'] ?? 'ارتباط با سرور ترجمه برقرار نشد.')
            ]);
            exit;
        }

        // ۳. ذخیره در کش دیتابیس برای استفاده‌های بعدی
        try {
            $insCache = $pdo->prepare("
                INSERT INTO translation_cache (source_hash, engine, source_text, translated_text)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text)
            ");
            $insCache->execute([$source_hash, $engine, $clean_text, $result['text']]);
        } catch (Exception $ignore) {}

        echo json_encode([
            'status'          => 'success',
            'clean_source'    => $clean_text,
            'translated_text' => $result['text'],
            'engine_used'     => $engine_used,
            'cached'          => false
        ]);
        exit;
    }

    // ====================================================
    // ۵. مدیریت فیش‌های پژوهشی مقاله (ذخیره، لیست و حذف فیش‌های ترجمه‌شده)
    // ====================================================
    if ($action === 'list_notes') {
        $pdf_id = (int)($_GET['pdf_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT id, page_number, original_text, translated_text, user_comment, created_at
            FROM paper_notes
            WHERE user_id = ? AND pdf_id = ?
            ORDER BY page_number ASC, id DESC
        ");
        $stmt->execute([$user_id, $pdf_id]);
        echo json_encode(['status' => 'success', 'notes' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'save_note') {
        $pdf_id          = (int)($_POST['pdf_id'] ?? 0);
        $page_number     = max(1, (int)($_POST['page_number'] ?? 1));
        $original_text   = trim($_POST['original_text'] ?? '');
        $translated_text = trim($_POST['translated_text'] ?? '');
        $user_comment    = trim($_POST['user_comment'] ?? '');

        if ($pdf_id <= 0 || empty($translated_text)) {
            echo json_encode(['status' => 'error', 'message' => 'اطلاعات فیش ناقص است.']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO paper_notes (user_id, pdf_id, page_number, original_text, translated_text, user_comment)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $pdf_id, $page_number, $original_text, $translated_text, $user_comment]);

        echo json_encode([
            'status'  => 'success',
            'message' => 'در دفترچه فیش‌های این مقاله ذخیره شد.',
            'note_id' => (int)$pdo->lastInsertId()
        ]);
        exit;
    }

    if ($action === 'delete_note') {
        $note_id = (int)($_POST['note_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM paper_notes WHERE id = ? AND user_id = ?");
        $stmt->execute([$note_id, $user_id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'درخواست نامعتبر است.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'خطای سرور: ' . $e->getMessage()]);
}