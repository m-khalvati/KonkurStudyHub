<?php
require_once dirname(__DIR__) . '/config/config.php';

@set_time_limit(90);

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'لطفاً ابتدا وارد حساب کاربری شوید.']);
    exit;
}

$user_id  = (int)$_SESSION['user_id'];
$action   = $_GET['action'] ?? ($_POST['action'] ?? '');
$sub_info = get_user_subscription_info($pdo, $user_id);

// اطمینان از وجود جدول آمار کلیدهای هوش مصنوعی
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ai_key_stats` (
          `key_hash` char(32) NOT NULL,
          `key_masked` varchar(40) NOT NULL,
          `total_calls` int(11) NOT NULL DEFAULT 0,
          `success_calls` int(11) NOT NULL DEFAULT 0,
          `error_calls` int(11) NOT NULL DEFAULT 0,
          `today_calls` int(11) NOT NULL DEFAULT 0,
          `last_call_date` date DEFAULT NULL,
          `translate_calls` int(11) NOT NULL DEFAULT 0,
          `exam_calls` int(11) NOT NULL DEFAULT 0,
          `last_http_code` int(11) DEFAULT NULL,
          `last_model` varchar(60) DEFAULT NULL,
          `last_error` varchar(255) DEFAULT NULL,
          `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`key_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
    ");
} catch (Exception $ignore) {}

// ایجاد خودکار پوشه اختصاصی مقالات (uploads/papers/)
$papers_dir = dirname(__DIR__) . '/uploads/papers/';
if (!is_dir($papers_dir)) {
    mkdir($papers_dir, 0755, true);
}
$htaccess_path = $papers_dir . '.htaccess';
if (!is_file($htaccess_path)) {
    $ht_content = "<IfModule mod_headers.c>\n    Header unset ETag\n    Header set Accept-Ranges \"none\"\n</IfModule>\nFileETag None\nSetEnv no-gzip 1\n";
    @file_put_contents($htaccess_path, $ht_content);
}

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
// اکشن استریم باینری فایل مقاله
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

    $full_path = dirname(__DIR__) . '/' . $row['file_path'];
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
// توابع پاکسازی متن، ثبت آمار کلیدها و موتورهای ترجمه
// ====================================================
function sanitize_pdf_english_text($text) {
    $text = trim($text);
    $text = preg_replace('/(\w)-\s+([a-z])/u', '$1$2', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function get_valid_ai_keys_pool() {
    $pool = [];
    if (defined('AI_API_KEYS') && is_array(AI_API_KEYS)) {
        foreach (AI_API_KEYS as $k) {
            $k = trim($k);
            if (!empty($k) && strpos($k, 'YOUR_GEMINI_API_KEY') === false && strpos($k, 'YOUR_AI_API_KEY') === false) {
                $pool[] = $k;
            }
        }
    }
    if (empty($pool) && defined('AI_API_KEY')) {
        $single = trim(AI_API_KEY);
        if (!empty($single) && strpos($single, 'YOUR_') === false) {
            $pool[] = $single;
        }
    }
    if (count($pool) > 1) {
        shuffle($pool);
    }
    return $pool;
}

function mask_translate_ai_key($key) {
    $key = trim($key);
    $len = strlen($key);
    if ($len <= 10) return str_repeat('*', $len);
    return substr($key, 0, 6) . '...' . substr($key, -4);
}

function log_translate_ai_key_stat($pdo, $api_key, $is_ok, $http_code, $model_name, $err_msg = '') {
    try {
        $hash      = md5(trim($api_key));
        $masked    = mask_translate_ai_key($api_key);
        $today     = date('Y-m-d');
        $succ      = $is_ok ? 1 : 0;
        $err       = $is_ok ? 0 : 1;
        $clean_err = $is_ok ? null : mb_substr(trim($err_msg), 0, 250, 'UTF-8');

        $sql = "
            INSERT INTO ai_key_stats
            (key_hash, key_masked, total_calls, success_calls, error_calls, today_calls, last_call_date, translate_calls, exam_calls, last_http_code, last_model, last_error)
            VALUES (?, ?, 1, ?, ?, 1, ?, 1, 0, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                key_masked      = VALUES(key_masked),
                total_calls     = total_calls + 1,
                success_calls   = success_calls + VALUES(success_calls),
                error_calls     = error_calls + VALUES(error_calls),
                today_calls     = IF(last_call_date = VALUES(last_call_date), today_calls + 1, 1),
                last_call_date  = VALUES(last_call_date),
                translate_calls = translate_calls + 1,
                last_http_code  = VALUES(last_http_code),
                last_model      = VALUES(last_model),
                last_error      = IF(VALUES(error_calls) > 0, VALUES(last_error), last_error)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$hash, $masked, $succ, $err, $today, (int)$http_code, $model_name, $clean_err]);
    } catch (Exception $ignore) {}
}

function extract_fallback_academic_keywords($english_text) {
    $stop_words = [
        'the','and','for','that','this','with','from','are','was','were','have','has','had',
        'not','but','what','all','when','where','who','will','more','about','which','their',
        'there','been','also','into','such','than','then','them','these','those','some','would',
        'could','should','over','after','before','between','under','while','during','through',
        'using','based','paper','study','results','method','methods','proposed','section','figure',
        'table','show','shown','shows','used','however','therefore','thus','where','each','both'
    ];

    preg_match_all('/\b[a-zA-Z]{6,18}\b/', $english_text, $matches);
    $words = $matches[0] ?? [];
    $candidates = [];

    foreach ($words as $w) {
        $lw = strtolower($w);
        if (!in_array($lw, $stop_words, true) && !in_array($lw, $candidates, true)) {
            $candidates[] = $w;
            if (count($candidates) >= 4) break;
        }
    }

    if (empty($candidates)) return [];

    $joined = implode(' | ', $candidates);
    $tr = translate_via_google_gtx($joined, 'en', 'fa');
    if (!$tr['ok']) return [];

    $fa_parts = array_map('trim', explode('|', $tr['text']));
    $kw_list = [];
    foreach ($candidates as $i => $en_w) {
        if (!empty($fa_parts[$i])) {
            $kw_list[] = ['en' => $en_w, 'fa' => $fa_parts[$i]];
        }
    }
    return $kw_list;
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
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
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
        ? ['ok' => true, 'text' => trim($translated), 'keywords' => []]
        : ['ok' => false, 'error' => 'ترجمه خالی برگشت داده شد'];
}

// ۲. موتور رایگان MyMemory API
function translate_via_mymemory($text, $source = 'en', $target = 'fa') {
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
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (AcademicPaperReader/4.3)'
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

    return ['ok' => true, 'text' => implode(' ', $final_translation), 'keywords' => []];
}

// ۳. ارسال درخواست به یک مدل و کلید مشخص هوش مصنوعی (همراه با ثبت مصرف در ai_key_stats)
function call_single_ai_key($pdo, $text, $task_mode, $provider, $api_key, $model_name, $openai_endpoint) {
    if ($task_mode === 'summarize') {
        $system_prompt = "تو یک استاد دانشگاه و دستیار پژوهشی هستی. "
            . "متن انگلیسی زیر را بخوان و «پیام کلیدی و نتیجه‌گیری علمی» آن را به زبان فارسی روان و خلاصه (در ۱ تا ۳ نکته کوتاه با علامت •) بنویس. "
            . "اصطلاحات مهم انگلیسی را داخل پرانتز بنویس. هیچ توضیح اضافه‌ای ننویس.\n"
            . "در انتهای پاسخ، در یک خط جدید دقیقاً عبارت |||KEYWORDS||| را بنویس و بعد از آن ۳ تا ۵ واژه تخصصی مهم متن را به فرمت English: فارسی (جداشده با علامت |) بنویس.";
    } else {
        $system_prompt = "تو یک مترجم برجسته و تخصصی مقالات علمی و دانشگاهی هستی. "
            . "متن انگلیسی زیر را به فارسی روان، دقیق و علمی ترجمه کن.\n"
            . "قوانین:\n"
            . "1. برای اصطلاحات تخصصی کلیدی، معادل فارسی را بنویس و اصطلاح انگلیسی را داخل پرانتز بیاور؛ مثلاً: مطالعه حذفی (Ablation Study).\n"
            . "2. فرمول‌ها، متغیرها و رفرنس‌ها مانند [12] را حفظ کن و هیچ جمله اضافه‌ای در اول یا آخر پیام ننویس.\n"
            . "3. در انتهای ترجمه، در یک خط جدید دقیقاً عبارت |||KEYWORDS||| را بنویس و بعد از آن ۳ تا ۵ واژه تخصصی مهم متن را به فرمت English Term: معادل فارسی (جداشده با علامت |) بنویس.";
    }

    if ($provider === 'gemini') {
        $model = !empty($model_name) ? trim($model_name) : 'gemini-3.7-flash';
        $model = preg_replace('#^models/#i', '', $model);

        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($model) . ":generateContent?key=" . urlencode($api_key);
        $combined_prompt = $system_prompt . "\n\nمتن انگلیسی جهت پردازش:\n" . $text;

        $payload = [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $combined_prompt]]
                ]
            ],
            'generationConfig' => [
                'temperature'     => 0.2,
                'maxOutputTokens' => 1024
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 7,
            CURLOPT_TIMEOUT        => 14,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Connection: close'
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE)
        ]);

        $res       = curl_exec($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($http_code === 0 || $res === false) {
            $err_str = 'Timeout (' . ($curl_err ?: 'سرور پاسخ نداد') . ')';
            log_translate_ai_key_stat($pdo, $api_key, false, 0, $model, $err_str);
            return [
                'ok'        => false,
                'http_code' => 0,
                'error'     => $err_str
            ];
        }

        $data = json_decode($res, true);
        if ($http_code === 200 && isset($data['candidates'][0]['content']['parts'])) {
            $parts_list = $data['candidates'][0]['content']['parts'];
            $collected_text = '';
            foreach ($parts_list as $p) {
                if (isset($p['thought']) && $p['thought'] === true) {
                    continue;
                }
                if (!empty($p['text'])) {
                    $collected_text .= $p['text'] . "\n";
                }
            }
            $collected_text = trim($collected_text);
            if (!empty($collected_text)) {
                log_translate_ai_key_stat($pdo, $api_key, true, 200, $model);
                return [
                    'ok'        => true,
                    'http_code' => 200,
                    'raw_text'  => $collected_text,
                    'model'     => $model
                ];
            }
        }

        $err_msg = $data['error']['message'] ?? "HTTP {$http_code}";
        log_translate_ai_key_stat($pdo, $api_key, false, $http_code, $model, $err_msg);
        return [
            'ok'        => false,
            'http_code' => $http_code,
            'error'     => $err_msg
        ];
    } else {
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
            CURLOPT_CONNECTTIMEOUT => 7,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
                'Connection: close'
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE)
        ]);

        $res       = curl_exec($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($http_code === 0 || $res === false) {
            $err_str = $curl_err ?: 'Timeout';
            log_translate_ai_key_stat($pdo, $api_key, false, 0, $model, $err_str);
            return ['ok' => false, 'http_code' => 0, 'error' => $err_str];
        }

        $data = json_decode($res, true);
        if ($http_code === 200 && isset($data['choices'][0]['message']['content'])) {
            log_translate_ai_key_stat($pdo, $api_key, true, 200, $model);
            return [
                'ok'        => true,
                'http_code' => 200,
                'raw_text'  => trim($data['choices'][0]['message']['content']),
                'model'     => $model
            ];
        }

        $err_msg = $data['error']['message'] ?? "HTTP {$http_code}";
        log_translate_ai_key_stat($pdo, $api_key, false, $http_code, $model, $err_msg);
        return [
            'ok'        => false,
            'http_code' => $http_code,
            'error'     => $err_msg
        ];
    }
}

// ۴. چرخش هوشمند بین مدل‌های فعال موجود در اکانت شما
function translate_via_ai_pool($pdo, $text, $task_mode = 'translate') {
    $keys_pool = get_valid_ai_keys_pool();
    if (empty($keys_pool)) {
        return ['ok' => false, 'error' => 'هیچ کلید API فعالی در config.php تنظیم نشده است.'];
    }

    $provider      = defined('AI_PROVIDER') ? AI_PROVIDER : 'gemini';
    $primary_model = defined('AI_MODEL_NAME') ? preg_replace('#^models/#i', '', trim(AI_MODEL_NAME)) : 'gemini-3.7-flash';
    $endpoint      = defined('AI_OPENAI_ENDPOINT') ? AI_OPENAI_ENDPOINT : '';

    $exact_account_models = [
        'gemini-3.7-flash',
        'gemini-3.6-flash',
        'gemini-3.5-flash',
        'gemini-3.5-flash-lite',
        'gemini-3.1-flash-lite',
        'gemma-4-31b-it',
        'gemini-3.8-flash'
    ];

    if ($provider === 'gemini') {
        if ($primary_model !== 'gemini-3.8-flash' && $primary_model !== 'gemini-2.5-flash') {
            array_unshift($exact_account_models, $primary_model);
        }
        $candidate_models = array_values(array_unique($exact_account_models));
    } else {
        $candidate_models = [$primary_model];
    }

    $last_error = '';

    foreach ($candidate_models as $current_model) {
        foreach ($keys_pool as $api_key) {
            $attempt = call_single_ai_key($pdo, $text, $task_mode, $provider, $api_key, $current_model, $endpoint);
            if ($attempt['ok']) {
                $raw_output = $attempt['raw_text'];
                $main_text  = $raw_output;
                $keywords   = [];

                if (strpos($raw_output, '|||KEYWORDS|||') !== false) {
                    $parts = explode('|||KEYWORDS|||', $raw_output, 2);
                    $main_text = trim($parts[0]);
                    $kw_raw = trim($parts[1]);

                    $kw_items = preg_split('/[\|\n,،]+/u', $kw_raw);
                    foreach ($kw_items as $item) {
                        $item = trim($item, " \t\n\r\0\x0B-*•");
                        if (mb_strlen($item, 'UTF-8') > 2 && strpos($item, ':') !== false) {
                            list($en_term, $fa_term) = array_map('trim', explode(':', $item, 2));
                            if (!empty($en_term) && !empty($fa_term)) {
                                $keywords[] = ['en' => $en_term, 'fa' => $fa_term];
                            }
                        }
                    }
                }

                return [
                    'ok'       => true,
                    'text'     => $main_text,
                    'keywords' => array_slice($keywords, 0, 6),
                    'model'    => $current_model
                ];
            }

            $last_error = $attempt['error'];
            $code = $attempt['http_code'];

            if ($code === 503 || $code === 404 || $code === 400 || $code === 0) {
                break;
            }
        }
    }

    return ['ok' => false, 'error' => $last_error];
}

try {
    // ====================================================
    // ۱. دریافت لیست مقالات، لیست جزوات و وضعیت هوش مصنوعی
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
            $item['is_locked'] = (!$sub_info['is_vip'] && $idx >= FREE_MAX_PDF_COUNT);
            if ((int)$item['is_paper'] === 1) {
                $papers[] = $item;
            } else {
                $booklets[] = $item;
            }
        }

        $valid_pool = get_valid_ai_keys_pool();

        echo json_encode([
            'status'       => 'success',
            'papers'       => $papers,
            'booklets'     => $booklets,
            'subscription' => $sub_info,
            'ai_config'    => [
                'enabled'    => defined('AI_TRANSLATION_ENABLED') ? (bool)AI_TRANSLATION_ENABLED : false,
                'vip_only'   => defined('AI_VIP_ONLY') ? (bool)AI_VIP_ONLY : true,
                'keys_count' => count($valid_pool)
            ]
        ]);
        exit;
    }

    // ====================================================
    // ۲. آپلود مقاله جدید در پوشه uploads/papers/
    // ====================================================
    if ($action === 'upload_paper') {
        $title = trim($_POST['title'] ?? '');
        if (empty($title)) {
            echo json_encode(['status' => 'error', 'message' => 'لطفاً عنوان مقاله را وارد کنید.']);
            exit;
        }

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
            'message' => 'مقاله با موفقیت آپلود شد.',
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
    // ۳. افزودن یک فایل از بخش «جزوه‌خوان» به «مقاله‌خوان»
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

        $src_full_path = dirname(__DIR__) . '/' . $src['file_path'];
        if (!is_file($src_full_path)) {
            echo json_encode(['status' => 'error', 'message' => 'فایل فیزیکی جزوه یافت نشد.']);
            exit;
        }

        $new_filename = 'paper_u' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
        $dest_path    = $papers_dir . $new_filename;
        $db_rel_path  = 'uploads/papers/' . $new_filename;

        if (!copy($src_full_path, $dest_path)) {
            echo json_encode(['status' => 'error', 'message' => 'خطا در کپی فایل به پوشه مقالات.']);
            exit;
        }

        if (!$sub_info['is_vip']) {
            @unlink($src_full_path);
            $upd = $pdo->prepare("UPDATE pdfs SET is_paper = 1, file_path = ?, category = 'مقاله علمی' WHERE id = ? AND user_id = ?");
            $upd->execute([$db_rel_path, $source_pdf_id, $user_id]);
            $target_id = $source_pdf_id;
        } else {
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
    // ۴. ترجمه، استخراج واژگان و خلاصه‌سازی هوشمند
    // ====================================================
    if ($action === 'translate') {
        $raw_text  = $_POST['text'] ?? '';
        $engine    = $_POST['engine'] ?? 'google';
        $task_mode = $_POST['task_mode'] ?? 'translate';

        $clean_text = sanitize_pdf_english_text($raw_text);
        if (empty($clean_text)) {
            echo json_encode(['status' => 'error', 'message' => 'متنی برای پردازش انتخاب نشده است.']);
            exit;
        }

        if (mb_strlen($clean_text, 'UTF-8') > 4500) {
            $clean_text = mb_substr($clean_text, 0, 4500, 'UTF-8');
        }

        $source_hash = md5(strtolower($clean_text));
        $cache_engine_key = ($task_mode === 'summarize') ? 'ai_summary' : $engine;

        $cacheStmt = $pdo->prepare("SELECT translated_text, keywords_json FROM translation_cache WHERE source_hash = ? AND engine = ? LIMIT 1");
        $cacheStmt->execute([$source_hash, $cache_engine_key]);
        $cached_row = $cacheStmt->fetch();

        if ($cached_row && !empty($cached_row['translated_text'])) {
            $cached_kw = !empty($cached_row['keywords_json']) ? json_decode($cached_row['keywords_json'], true) : [];
            echo json_encode([
                'status'          => 'success',
                'para_hash'       => $source_hash,
                'clean_source'    => $clean_text,
                'translated_text' => $cached_row['translated_text'],
                'keywords'        => is_array($cached_kw) ? $cached_kw : [],
                'engine_used'     => ($task_mode === 'summarize') ? 'خلاصه‌ساز هوش مصنوعی' : $engine,
                'cached'          => true
            ]);
            exit;
        }

        $used_fallback_due_to_overload = false;

        if ($task_mode === 'summarize' || $engine === 'ai') {
            $ai_enabled = defined('AI_TRANSLATION_ENABLED') ? (bool)AI_TRANSLATION_ENABLED : false;
            if (!$ai_enabled) {
                $result = translate_via_google_gtx($clean_text, 'en', 'fa');
                $engine_used = 'google';
            } else {
                if (defined('AI_VIP_ONLY') && AI_VIP_ONLY && !$sub_info['is_vip']) {
                    echo json_encode([
                        'status'   => 'error',
                        'need_vip' => true,
                        'message'  => 'استفاده از «هوش مصنوعی آکادمیک و خلاصه‌ساز مقاله» مخصوص مشترکین ویژه (VIP) است. برای ترجمه رایگان می‌توانید از موتور Google یا MyMemory استفاده کنید.'
                    ]);
                    exit;
                }

                $result = translate_via_ai_pool($pdo, $clean_text, $task_mode);
                if ($result['ok']) {
                    $active_m = $result['model'] ?? 'AI';
                    $engine_used = ($task_mode === 'summarize')
                        ? "خلاصه‌ساز هوش مصنوعی ({$active_m})"
                        : "هوش مصنوعی ({$active_m})";
                } else {
                    $used_fallback_due_to_overload = true;
                    $fallback_tr = translate_via_google_gtx($clean_text, 'en', 'fa');
                    if ($fallback_tr['ok']) {
                        $extracted_kw = extract_fallback_academic_keywords($clean_text);
                        if ($task_mode === 'summarize') {
                            $result = [
                                'ok'       => true,
                                'text'     => "• نکته کلیدی این بخش:\n" . $fallback_tr['text'],
                                'keywords' => $extracted_kw
                            ];
                            $engine_used = 'موتور پشتیبان (ترافیک بالای سرور AI)';
                        } else {
                            $result = [
                                'ok'       => true,
                                'text'     => $fallback_tr['text'],
                                'keywords' => $extracted_kw
                            ];
                            $engine_used = 'گوگل (پشتیبان در زمان شلوغی سرور AI)';
                        }
                    }
                }
            }
        } elseif ($engine === 'mymemory') {
            $result = translate_via_mymemory($clean_text, 'en', 'fa');
            $engine_used = 'mymemory';
            if (!$result['ok']) {
                $result = translate_via_google_gtx($clean_text, 'en', 'fa');
                $engine_used = 'گوگل (پشتیبان خودکار)';
            }
        } else {
            $result = translate_via_google_gtx($clean_text, 'en', 'fa');
            $engine_used = 'google';
            if (!$result['ok']) {
                $result = translate_via_mymemory($clean_text, 'en', 'fa');
                $engine_used = 'mymemory (پشتیبان خودکار)';
            }
        }

        if (!$result['ok']) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'خطا در پردازش: ' . ($result['error'] ?? 'ارتباط با سرور ترجمه برقرار نشد.')
            ]);
            exit;
        }

        $keywords = $result['keywords'] ?? [];

        if (!$used_fallback_due_to_overload) {
            try {
                $insCache = $pdo->prepare("
                    INSERT INTO translation_cache (source_hash, engine, source_text, translated_text, keywords_json)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text), keywords_json = VALUES(keywords_json)
                ");
                $insCache->execute([
                    $source_hash,
                    $cache_engine_key,
                    $clean_text,
                    $result['text'],
                    json_encode($keywords, JSON_UNESCAPED_UNICODE)
                ]);
            } catch (Exception $ignore) {}
        }

        echo json_encode([
            'status'          => 'success',
            'para_hash'       => $source_hash,
            'clean_source'    => $clean_text,
            'translated_text' => $result['text'],
            'keywords'        => $keywords,
            'engine_used'     => $engine_used,
            'cached'          => false
        ]);
        exit;
    }

    // ====================================================
    // ۵. مدیریت فیش‌های پژوهشی مقاله
    // ====================================================
    if ($action === 'list_notes') {
        $pdf_id = (int)($_GET['pdf_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT id, page_number, para_hash, original_text, translated_text, user_comment, created_at
            FROM paper_notes
            WHERE user_id = ? AND pdf_id = ?
            ORDER BY page_number ASC, id ASC
        ");
        $stmt->execute([$user_id, $pdf_id]);
        echo json_encode(['status' => 'success', 'notes' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'save_note') {
        $pdf_id          = (int)($_POST['pdf_id'] ?? 0);
        $page_number     = max(1, (int)($_POST['page_number'] ?? 1));
        $original_text   = sanitize_pdf_english_text($_POST['original_text'] ?? '');
        $translated_text = trim($_POST['translated_text'] ?? '');
        $user_comment    = trim($_POST['user_comment'] ?? '');
        $para_hash       = !empty($_POST['para_hash']) ? trim($_POST['para_hash']) : md5(strtolower($original_text));

        if ($pdf_id <= 0 || empty($translated_text)) {
            echo json_encode(['status' => 'error', 'message' => 'اطلاعات فیش ناقص است.']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO paper_notes (user_id, pdf_id, page_number, para_hash, original_text, translated_text, user_comment)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $pdf_id, $page_number, $para_hash, $original_text, $translated_text, $user_comment]);

        echo json_encode([
            'status'    => 'success',
            'message'   => 'در دفترچه فیش‌های این مقاله ذخیره شد.',
            'note_id'   => (int)$pdo->lastInsertId(),
            'para_hash' => $para_hash
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