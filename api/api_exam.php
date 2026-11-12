<?php
require_once dirname(__DIR__) . '/config/config.php';

@set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'لطفاً ابتدا وارد حساب کاربری خود شوید.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$action  = $_GET['action'] ?? ($_POST['action'] ?? '');

$sub_info = function_exists('get_user_subscription_info')
    ? get_user_subscription_info($pdo, $user_id)
    : [
        'is_vip'      => false,
        'is_admin'    => ($user_id === 1),
        'days_left'   => 0,
        'expires_j'   => 'ندارد',
        'username'    => $_SESSION['username'] ?? '',
        'full_name'   => $_SESSION['full_name'] ?? '',
        'phone'       => $_SESSION['phone'] ?? ''
    ];

$is_admin = !empty($sub_info['is_admin']) || ($user_id === 1);

// ====================================================
// ۱. ساخت خودکار پوشه تصاویر آزمون و جداول دیتابیس (شامل جدول آمار کلیدهای AI)
// ====================================================
$exams_upload_dir = dirname(__DIR__) . '/uploads/exams/';
if (!is_dir($exams_upload_dir)) {
    @mkdir($exams_upload_dir, 0755, true);
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `exams` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `title` varchar(255) NOT NULL,
          `category` varchar(100) DEFAULT 'عمومی',
          `description` text DEFAULT NULL,
          `duration_mins` int(11) NOT NULL DEFAULT 30,
          `negative_marking` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=با نمره منفی کنکور, 0=بدون نمره منفی',
          `is_vip_only` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=ویژه مشترکین VIP, 0=عمومی',
          `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=منتشرشده در سایت, 0=پیش‌نویس مخفی',
          `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `exam_questions` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `exam_id` int(11) NOT NULL,
          `sort_order` int(11) NOT NULL DEFAULT 1,
          `question_text` text DEFAULT NULL,
          `question_image` varchar(255) DEFAULT NULL COMMENT 'تصویر نمودار پیوست یا برش کامل سوال',
          `option_1` text DEFAULT NULL,
          `option_2` text DEFAULT NULL,
          `option_3` text DEFAULT NULL,
          `option_4` text DEFAULT NULL,
          `correct_option` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'گزینه صحیح از 1 تا 4',
          `explanation` text DEFAULT NULL COMMENT 'پاسخ تشریحی',
          `explanation_image` varchar(255) DEFAULT NULL,
          `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_exam_q` (`exam_id`, `sort_order`),
          CONSTRAINT `fk_eq_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `exam_attempts` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `exam_id` int(11) NOT NULL,
          `total_questions` int(11) NOT NULL DEFAULT 0,
          `correct_count` int(11) NOT NULL DEFAULT 0,
          `wrong_count` int(11) NOT NULL DEFAULT 0,
          `blank_count` int(11) NOT NULL DEFAULT 0,
          `score_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
          `time_spent_sec` int(11) NOT NULL DEFAULT 0,
          `answers_json` longtext DEFAULT NULL,
          `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_user_exam_att` (`user_id`, `exam_id`),
          CONSTRAINT `fk_att_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_att_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
    ");

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

// ====================================================
// ۲. توابع مدیریت استخر کلیدها، ثبت آمار مصرف و تاریخ شمسی
// ====================================================
function exam_gdate_to_jalali($g_date) {
    $parts = explode('-', trim($g_date));
    if (count($parts) !== 3) return $g_date;
    $gy = (int)$parts[0];
    $gm = (int)$parts[1];
    $gd = (int)$parts[2];

    if (function_exists('format_jalali')) {
        return format_jalali($gy, $gm, $gd);
    }

    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $jm = ($days < 186) ? 1 + intdiv($days, 31) : 7 + intdiv($days - 186, 30);
    $jd = ($days < 186) ? 1 + ($days % 31) : 1 + (($days - 186) % 30);

    return sprintf('%04d-%02d-%02d', $jy, $jm, $jd);
}

function get_ordered_ai_keys_list() {
    $pool = [];
    if (defined('AI_API_KEYS') && is_array(AI_API_KEYS)) {
        foreach (AI_API_KEYS as $k) {
            $k = trim($k);
            if (!empty($k) && strpos($k, 'YOUR_') === false) {
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
    return array_values(array_unique($pool));
}

function mask_ai_key_string($key) {
    $key = trim($key);
    $len = strlen($key);
    if ($len <= 10) return str_repeat('*', $len);
    return substr($key, 0, 6) . '...' . substr($key, -4);
}

function record_ai_key_stat($pdo, $api_key, $module, $is_ok, $http_code, $model_name, $err_msg = '') {
    try {
        $hash   = md5(trim($api_key));
        $masked = mask_ai_key_string($api_key);
        $today  = date('Y-m-d');
        $succ   = $is_ok ? 1 : 0;
        $err    = $is_ok ? 0 : 1;
        $tr_inc = ($module === 'translate') ? 1 : 0;
        $ex_inc = ($module === 'exam') ? 1 : 0;
        $clean_err = $is_ok ? null : mb_substr(trim($err_msg), 0, 250, 'UTF-8');

        $sql = "
            INSERT INTO ai_key_stats
            (key_hash, key_masked, total_calls, success_calls, error_calls, today_calls, last_call_date, translate_calls, exam_calls, last_http_code, last_model, last_error)
            VALUES (?, ?, 1, ?, ?, 1, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                key_masked      = VALUES(key_masked),
                total_calls     = total_calls + 1,
                success_calls   = success_calls + VALUES(success_calls),
                error_calls     = error_calls + VALUES(error_calls),
                today_calls     = IF(last_call_date = VALUES(last_call_date), today_calls + 1, 1),
                last_call_date  = VALUES(last_call_date),
                translate_calls = translate_calls + VALUES(translate_calls),
                exam_calls      = exam_calls + VALUES(exam_calls),
                last_http_code  = VALUES(last_http_code),
                last_model      = VALUES(last_model),
                last_error      = IF(VALUES(error_calls) > 0, VALUES(last_error), last_error)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$hash, $masked, $succ, $err, $today, $tr_inc, $ex_inc, (int)$http_code, $model_name, $clean_err]);
    } catch (Exception $ignore) {}
}

function save_base64_exam_image($base64_str, $exams_upload_dir) {
    if (empty($base64_str)) return null;
    if (preg_match('/^data:image\/(\w+);base64,/', $base64_str, $type_match)) {
        $ext = strtolower($type_match[1]);
        if ($ext === 'jpeg') $ext = 'jpg';
        if (!in_array($ext, ['jpg', 'png', 'webp', 'gif'], true)) {
            $ext = 'png';
        }
        $encoded = substr($base64_str, strpos($base64_str, ',') + 1);
    } else {
        $ext = 'png';
        $encoded = $base64_str;
    }

    $decoded = base64_decode(str_replace(' ', '+', $encoded));
    if ($decoded === false || strlen($decoded) < 64) {
        return null;
    }

    $filename = 'qimg_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $full_path = $exams_upload_dir . $filename;
    if (file_put_contents($full_path, $decoded) !== false) {
        return 'uploads/exams/' . $filename;
    }
    return null;
}

// ====================================================
// ۳. استخراج هوشمند سوالات، شبه‌کدها و مختصات شکل‌ها (figure_box) با Gemini Vision
// ====================================================
function extract_questions_from_image_via_gemini($pdo, $mime_type, $base64_raw, $extra_hint = '', $page_index = 0) {
    $ordered_keys = get_ordered_ai_keys_list();
    if (empty($ordered_keys)) {
        return ['ok' => false, 'error' => 'کلید هوش مصنوعی در config.php تنظیم نشده است.'];
    }

    // چرخش نوبتی (Round-Robin) روی کلیدها بر اساس شماره صفحه در استخراج چندصفحه‌ای
    $num_keys = count($ordered_keys);
    $rotated_keys = [];
    $start_offset = abs((int)$page_index) % $num_keys;
    for ($i = 0; $i < $num_keys; $i++) {
        $rotated_keys[] = $ordered_keys[($start_offset + $i) % $num_keys];
    }

    $primary_model = defined('AI_MODEL_NAME') ? preg_replace('#^models/#i', '', trim(AI_MODEL_NAME)) : 'gemini-3.7-flash';
    $candidate_models = array_values(array_unique([
        $primary_model,
        'gemini-3.7-flash',
        'gemini-3.6-flash',
        'gemini-3.5-flash',
        'gemini-3.5-flash-lite',
        'gemini-3.8-flash'
    ]));

    $prompt = "تو یک سیستم فوق‌دقیق استخراج سوالات آزمون، کنکور و المپیاد (OCR & Visual Question Parser) هستی.\n"
        . "تصویر ارسالی را به دقت تحلیل کن و تمام سوالات چهارگزینه‌ای موجود در تصویر را استخراج نما.\n"
        . (!empty($extra_hint) ? "راهنمایی ادمین درباره این صفحه: {$extra_hint}\n" : "")
        . "قوانین بسیار مهم خروجی:\n"
        . "1. خروجی تو باید **فقط و فقط یک آرایه JSON معتبر** باشد و هیچ توضیح اضافه‌ای قبل یا بعد از آن ننویسی.\n"
        . "2. **شبه‌کدها و تکه کدهای برنامه‌نویسی (Pseudocode / Code):** اگر سوال یا گزینه‌ای شامل شبه‌کد، الگوریتم، فلوچارت متنی، دستورات برنامه‌نویسی (مانند Python, C++, Java, SQL) یا ساختارهای تورفته است، حتماً کل آن شبه‌کد را با حفظ کامل تورفتگی‌ها و خطوط جدید داخل بلوک سه بک‌تیک ``` ... ``` در فیلد `question_text` قرار بده تا به صورت کادر کد چپ‌چین نمایش داده شود.\n"
        . "3. **تشخیص شکل، نمودار، جدول یا تصویر (figure_box):** اگر سوالی دارای شکل، نمودار، مدار، ساختار شیمیایی، جدول تصویری یا کادر تصویری خاصی است که باید همراه سوال دیده شود، مختصات مستطیل دورِ آن شکل را در مقیاس 0 تا 1000 به صورت آرایه 4 عددی `[ymin, xmin, ymax, xmax]` در فیلد `\"figure_box\"` برگردان (فقط کادر دورِ خود شکل/نمودار/جدول، بدون صورت سوال و گزینه‌ها). اگر سوال هیچ شکل یا تصویر پیوستی ندارد، مقدار `\"figure_box\": null` بگذار.\n"
        . "4. ساختار دقیق هر آیتم در آرایه JSON:\n"
        . "[\n"
        . "  {\n"
        . "    \"question_text\": \"صورت کامل سوال (در صورت وجود شبه‌کد داخل ``` قرار گیرد)\",\n"
        . "    \"option_1\": \"متن گزینه ۱ (بدون عدد ۱ در اول آن)\",\n"
        . "    \"option_2\": \"متن گزینه ۲\",\n"
        . "    \"option_3\": \"متن گزینه ۳\",\n"
        . "    \"option_4\": \"متن گزینه ۴\",\n"
        . "    \"correct_option\": 1,\n"
        . "    \"explanation\": \"پاسخ تشریحی علمی و دقیق برای حل این تست\",\n"
        . "    \"figure_box\": [250, 120, 480, 880]\n"
        . "  }\n"
        . "]\n"
        . "5. اگر گزینه صحیح در تصویر علامت خورده است، شماره آن (1 تا 4) را در `correct_option` بگذار؛ در غیر این صورت خودت سوال را حل کن و گزینه صحیح (1 تا 4) و راه‌حل تشریحی را در `explanation` بنویس.";

    $last_error = '';

    foreach ($candidate_models as $model) {
        foreach ($rotated_keys as $api_key) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($model) . ":generateContent?key=" . urlencode($api_key);

            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $prompt],
                            [
                                'inline_data' => [
                                    'mime_type' => $mime_type,
                                    'data'      => $base64_raw
                                ]
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature'     => 0.1,
                    'maxOutputTokens' => 4096
                ]
            ];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 50,
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
                $err_str = 'Timeout (' . ($curl_err ?: 'عدم پاسخ سرور هوش مصنوعی') . ')';
                record_ai_key_stat($pdo, $api_key, 'exam', false, 0, $model, $err_str);
                $last_error = $err_str;
                break;
            }

            $data = json_decode($res, true);
            if ($http_code === 200 && isset($data['candidates'][0]['content']['parts'])) {
                record_ai_key_stat($pdo, $api_key, 'exam', true, 200, $model);

                $raw_text = '';
                foreach ($data['candidates'][0]['content']['parts'] as $part) {
                    if (isset($part['thought']) && $part['thought'] === true) continue;
                    if (!empty($part['text'])) {
                        $raw_text .= $part['text'] . "\n";
                    }
                }
                $raw_text = trim($raw_text);

                $first_bracket = strpos($raw_text, '[');
                $last_bracket  = strrpos($raw_text, ']');
                $cleaned = $raw_text;
                if ($first_bracket !== false && $last_bracket !== false && $last_bracket > $first_bracket) {
                    $cleaned = substr($raw_text, $first_bracket, $last_bracket - $first_bracket + 1);
                }

                $parsed_questions = json_decode($cleaned, true);
                if (is_array($parsed_questions) && count($parsed_questions) > 0) {
                    $normalized = [];
                    foreach ($parsed_questions as $q) {
                        if (!is_array($q)) continue;
                        $q_text = trim($q['question_text'] ?? '');
                        $opt1   = trim($q['option_1'] ?? '');
                        $opt2   = trim($q['option_2'] ?? '');
                        $opt3   = trim($q['option_3'] ?? '');
                        $opt4   = trim($q['option_4'] ?? '');
                        $corr   = (int)($q['correct_option'] ?? 1);
                        if ($corr < 1 || $corr > 4) $corr = 1;

                        // اعتبارسنجی مختصات شکل (figure_box: [ymin, xmin, ymax, xmax] در بازه 0 تا 1000)
                        $valid_box = null;
                        if (!empty($q['figure_box']) && is_array($q['figure_box']) && count($q['figure_box']) === 4) {
                            $ymin = max(0, min(1000, (int)$q['figure_box'][0]));
                            $xmin = max(0, min(1000, (int)$q['figure_box'][1]));
                            $ymax = max(0, min(1000, (int)$q['figure_box'][2]));
                            $xmax = max(0, min(1000, (int)$q['figure_box'][3]));
                            if (($ymax - $ymin) >= 25 && ($xmax - $xmin) >= 25) {
                                $valid_box = [$ymin, $xmin, $ymax, $xmax];
                            }
                        }

                        if ($q_text !== '' || $opt1 !== '' || $valid_box !== null) {
                            $normalized[] = [
                                'question_text'  => $q_text,
                                'option_1'       => $opt1 !== '' ? $opt1 : 'گزینه ۱',
                                'option_2'       => $opt2 !== '' ? $opt2 : 'گزینه ۲',
                                'option_3'       => $opt3 !== '' ? $opt3 : 'گزینه ۳',
                                'option_4'       => $opt4 !== '' ? $opt4 : 'گزینه ۴',
                                'correct_option' => $corr,
                                'explanation'    => trim($q['explanation'] ?? ''),
                                'figure_box'     => $valid_box
                            ];
                        }
                    }

                    if (count($normalized) > 0) {
                        return [
                            'ok'        => true,
                            'questions' => $normalized,
                            'model'     => $model,
                            'key_used'  => mask_ai_key_string($api_key)
                        ];
                    }
                }
                $last_error = 'در این صفحه سوال چهارگزینه‌ای استانداردی تشخیص داده نشد.';
            } else {
                $err_msg = $data['error']['message'] ?? "HTTP {$http_code}";
                record_ai_key_stat($pdo, $api_key, 'exam', false, $http_code, $model, $err_msg);
                $last_error = $err_msg;
            }

            if ($http_code === 503 || $http_code === 404 || $http_code === 400) {
                break;
            }
        }
    }

    return ['ok' => false, 'error' => $last_error ?: 'خطا در ارتباط با هوش مصنوعی'];
}

try {
    // ====================================================
    // الف) اکشن‌های عمومی کاربران (دانشجویان)
    // ====================================================

    // ۱. لیست تمام آزمون‌های فعال برای نمایش در صفحه exam.php به همراه سوابق کاربر
    if ($action === 'list_exams') {
        $stmt = $pdo->query("
            SELECT e.*,
                   (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id = e.id) AS question_count
            FROM exams e
            WHERE e.is_active = 1
            ORDER BY e.id DESC
        ");
        $exams = $stmt->fetchAll();

        $attStmt = $pdo->prepare("
            SELECT id, exam_id, total_questions, correct_count, wrong_count, blank_count, score_percent, time_spent_sec, created_at
            FROM exam_attempts
            WHERE user_id = ?
            ORDER BY id DESC
        ");
        $attStmt->execute([$user_id]);
        $user_attempts = $attStmt->fetchAll();

        $attempts_by_exam = [];
        foreach ($user_attempts as $att) {
            $eid = (int)$att['exam_id'];
            $att['created_j'] = exam_gdate_to_jalali(substr($att['created_at'], 0, 10));
            if (!isset($attempts_by_exam[$eid])) {
                $attempts_by_exam[$eid] = [];
            }
            $attempts_by_exam[$eid][] = $att;
        }

        foreach ($exams as &$ex) {
            $eid = (int)$ex['id'];
            $ex['question_count'] = (int)$ex['question_count'];
            $ex['is_locked']      = ((int)$ex['is_vip_only'] === 1 && !$sub_info['is_vip']);
            $ex['user_attempts']  = $attempts_by_exam[$eid] ?? [];
            $ex['best_score']     = !empty($ex['user_attempts'])
                ? max(array_map(function($a) { return (float)$a['score_percent']; }, $ex['user_attempts']))
                : null;
        }
        unset($ex);

        echo json_encode([
            'status'         => 'success',
            'exams'          => $exams,
            'recent_history' => array_slice($user_attempts, 0, 15),
            'subscription'   => $sub_info
        ]);
        exit;
    }

    // ۲. شروع آزمون و دریافت سوالات
    if ($action === 'start_exam') {
        $exam_id = (int)($_GET['exam_id'] ?? ($_POST['exam_id'] ?? 0));
        $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ? AND (is_active = 1 OR ? = 1)");
        $stmt->execute([$exam_id, $is_admin ? 1 : 0]);
        $exam = $stmt->fetch();

        if (!$exam) {
            echo json_encode(['status' => 'error', 'message' => 'آزمون مورد نظر یافت نشد یا غیرفعال است.']);
            exit;
        }

        if ((int)$exam['is_vip_only'] === 1 && !$sub_info['is_vip'] && !$is_admin) {
            echo json_encode([
                'status'   => 'error',
                'need_vip' => true,
                'message'  => 'شرکت در این آزمون ویژه مشترکین VIP است. لطفاً اشتراک ویژه تهیه نمایید.'
            ]);
            exit;
        }

        $qStmt = $pdo->prepare("
            SELECT id, exam_id, sort_order, question_text, question_image, option_1, option_2, option_3, option_4
            FROM exam_questions
            WHERE exam_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $qStmt->execute([$exam_id]);
        $questions = $qStmt->fetchAll();

        if (empty($questions)) {
            echo json_encode(['status' => 'error', 'message' => 'هنوز سوالی برای این آزمون ثبت نشده است.']);
            exit;
        }

        echo json_encode([
            'status'    => 'success',
            'exam'      => $exam,
            'questions' => $questions
        ]);
        exit;
    }

    // ۳. ثبت پاسخبرگ آزمون، تصحیح خودکار و ثبت در تقویم
    if ($action === 'submit_exam') {
        $exam_id         = (int)($_POST['exam_id'] ?? 0);
        $time_spent_sec  = max(0, (int)($_POST['time_spent_sec'] ?? 0));
        $answers_raw     = $_POST['answers_json'] ?? '{}';
        $add_to_calendar = (int)($_POST['add_to_calendar'] ?? 1) === 1;

        $user_answers = json_decode($answers_raw, true);
        if (!is_array($user_answers)) {
            $user_answers = [];
        }

        $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
        $stmt->execute([$exam_id]);
        $exam = $stmt->fetch();

        if (!$exam) {
            echo json_encode(['status' => 'error', 'message' => 'آزمون یافت نشد.']);
            exit;
        }

        $qStmt = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id = ? ORDER BY sort_order ASC, id ASC");
        $qStmt->execute([$exam_id]);
        $questions = $qStmt->fetchAll();

        $total_q = count($questions);
        if ($total_q === 0) {
            echo json_encode(['status' => 'error', 'message' => 'سوالی در این آزمون وجود ندارد.']);
            exit;
        }

        $correct_cnt = 0;
        $wrong_cnt   = 0;
        $blank_cnt   = 0;
        $review_list = [];

        foreach ($questions as $idx => $q) {
            $qid        = (int)$q['id'];
            $correct_op = (int)$q['correct_option'];
            $user_op    = isset($user_answers[$qid]) ? (int)$user_answers[$qid] : 0;

            if ($user_op < 1 || $user_op > 4) {
                $user_op = 0;
                $blank_cnt++;
                $status_str = 'blank';
            } elseif ($user_op === $correct_op) {
                $correct_cnt++;
                $status_str = 'correct';
            } else {
                $wrong_cnt++;
                $status_str = 'wrong';
            }

            $review_list[] = [
                'question_number'   => $idx + 1,
                'id'                => $qid,
                'question_text'     => $q['question_text'],
                'question_image'    => $q['question_image'],
                'option_1'          => $q['option_1'],
                'option_2'          => $q['option_2'],
                'option_3'          => $q['option_3'],
                'option_4'          => $q['option_4'],
                'user_option'       => $user_op,
                'correct_option'    => $correct_op,
                'status'            => $status_str,
                'explanation'       => $q['explanation'],
                'explanation_image' => $q['explanation_image']
            ];
        }

        if ((int)$exam['negative_marking'] === 1) {
            $raw_pct = ((($correct_cnt * 3) - $wrong_cnt) / ($total_q * 3)) * 100;
        } else {
            $raw_pct = ($correct_cnt / $total_q) * 100;
        }
        $score_percent = round(max(-33.33, min(100, $raw_pct)), 2);

        $insAtt = $pdo->prepare("
            INSERT INTO exam_attempts
            (user_id, exam_id, total_questions, correct_count, wrong_count, blank_count, score_percent, time_spent_sec, answers_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insAtt->execute([
            $user_id,
            $exam_id,
            $total_q,
            $correct_cnt,
            $wrong_cnt,
            $blank_cnt,
            $score_percent,
            $time_spent_sec,
            json_encode($user_answers)
        ]);
        $attempt_id = (int)$pdo->lastInsertId();

        if ($add_to_calendar) {
            try {
                $today_g = date('Y-m-d');
                $today_j = exam_gdate_to_jalali($today_g);
                $now_ts  = time();
                $dur_sec = max(900, min(10800, $time_spent_sec > 60 ? $time_spent_sec : ((int)$exam['duration_mins'] * 60)));
                $end_time_str   = date('H:i:00', $now_ts);
                $start_time_str = date('H:i:00', max(strtotime($today_g . ' 00:00:00'), $now_ts - $dur_sec));
                if ($end_time_str <= $start_time_str) {
                    $start_time_str = '08:00:00';
                    $end_time_str   = '08:30:00';
                }

                $calTitle = '📝 آزمون: ' . $exam['title'];
                $calDesc  = "نتیجه آزمون آنلاین — کل سوالات: {$total_q} | صحیح: {$correct_cnt} | غلط: {$wrong_cnt} | نزده: {$blank_cnt} | درصد: {$score_percent}%";

                $calIns = $pdo->prepare("
                    INSERT INTO calendar_events
                    (user_id, title, description, g_date, j_date, start_time, end_time, color, category_type, category_label, is_study, is_completed, send_sms, sms_sent, test_correct, test_wrong, test_blank, test_percent)
                    VALUES (?, ?, ?, ?, ?, ?, ?, '#e11d48', 'exam', 'آزمون', 1, 1, 0, 0, ?, ?, ?, ?)
                ");
                $calIns->execute([
                    $user_id, $calTitle, $calDesc, $today_g, $today_j, $start_time_str, $end_time_str,
                    $correct_cnt, $wrong_cnt, $blank_cnt, $score_percent
                ]);
            } catch (Exception $ignore) {}
        }

        echo json_encode([
            'status'      => 'success',
            'attempt_id'  => $attempt_id,
            'exam_title'  => $exam['title'],
            'report_card' => [
                'total_questions'  => $total_q,
                'correct_count'    => $correct_cnt,
                'wrong_count'      => $wrong_cnt,
                'blank_count'      => $blank_cnt,
                'score_percent'    => $score_percent,
                'time_spent_sec'   => $time_spent_sec,
                'negative_marking' => (int)$exam['negative_marking']
            ],
            'review_questions' => $review_list
        ]);
        exit;
    }

    // ۴. مشاهده کارنامه و پاسخنامه تشریحی یکی از آزمون‌های قبلی کاربر
    if ($action === 'get_attempt_review') {
        $attempt_id = (int)($_GET['attempt_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT a.*, e.title AS exam_title, e.negative_marking
            FROM exam_attempts a
            INNER JOIN exams e ON e.id = a.exam_id
            WHERE a.id = ? AND (a.user_id = ? OR ? = 1)
        ");
        $stmt->execute([$attempt_id, $user_id, $is_admin ? 1 : 0]);
        $attempt = $stmt->fetch();

        if (!$attempt) {
            echo json_encode(['status' => 'error', 'message' => 'کارنامه مورد نظر یافت نشد.']);
            exit;
        }

        $user_answers = json_decode($attempt['answers_json'] ?? '{}', true) ?: [];

        $qStmt = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id = ? ORDER BY sort_order ASC, id ASC");
        $qStmt->execute([(int)$attempt['exam_id']]);
        $questions = $qStmt->fetchAll();

        $review_list = [];
        foreach ($questions as $idx => $q) {
            $qid        = (int)$q['id'];
            $correct_op = (int)$q['correct_option'];
            $user_op    = isset($user_answers[$qid]) ? (int)$user_answers[$qid] : 0;

            if ($user_op < 1 || $user_op > 4) {
                $user_op = 0;
                $status_str = 'blank';
            } elseif ($user_op === $correct_op) {
                $status_str = 'correct';
            } else {
                $status_str = 'wrong';
            }

            $review_list[] = [
                'question_number'   => $idx + 1,
                'id'                => $qid,
                'question_text'     => $q['question_text'],
                'question_image'    => $q['question_image'],
                'option_1'          => $q['option_1'],
                'option_2'          => $q['option_2'],
                'option_3'          => $q['option_3'],
                'option_4'          => $q['option_4'],
                'user_option'       => $user_op,
                'correct_option'    => $correct_op,
                'status'            => $status_str,
                'explanation'       => $q['explanation'],
                'explanation_image' => $q['explanation_image']
            ];
        }

        echo json_encode([
            'status'      => 'success',
            'exam_title'  => $attempt['exam_title'],
            'report_card' => [
                'total_questions'  => (int)$attempt['total_questions'],
                'correct_count'    => (int)$attempt['correct_count'],
                'wrong_count'      => (int)$attempt['wrong_count'],
                'blank_count'      => (int)$attempt['blank_count'],
                'score_percent'    => (float)$attempt['score_percent'],
                'time_spent_sec'   => (int)$attempt['time_spent_sec'],
                'negative_marking' => (int)$attempt['negative_marking']
            ],
            'review_questions' => $review_list
        ]);
        exit;
    }

    // ====================================================
    // ب) اکشن‌های اختصاصی پنل مدیریت ادمین (نیازمند دسترسی ادمین)
    // ====================================================
    if (!$is_admin) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'دسترسی محدود به مدیر کل سامانه است.']);
        exit;
    }

    // ۵. دریافت آمار و وضعیت تمام کلیدهای هوش مصنوعی تنظیم‌شده در config.php
    if ($action === 'admin_ai_keys_status') {
        $configured_keys = get_ordered_ai_keys_list();
        $db_rows = [];
        try {
            $st = $pdo->query("SELECT * FROM ai_key_stats");
            foreach ($st->fetchAll() as $r) {
                $db_rows[$r['key_hash']] = $r;
            }
        } catch (Exception $ignore) {}

        $today = date('Y-m-d');
        $keys_report = [];
        $summary = [
            'keys_count'      => count($configured_keys),
            'total_calls'     => 0,
            'today_calls'     => 0,
            'success_calls'   => 0,
            'error_calls'     => 0,
            'translate_calls' => 0,
            'exam_calls'      => 0
        ];

        foreach ($configured_keys as $idx => $raw_key) {
            $hash   = md5($raw_key);
            $masked = mask_ai_key_string($raw_key);
            $stat   = $db_rows[$hash] ?? null;

            $total_c   = $stat ? (int)$stat['total_calls'] : 0;
            $succ_c    = $stat ? (int)$stat['success_calls'] : 0;
            $err_c     = $stat ? (int)$stat['error_calls'] : 0;
            $today_c   = ($stat && $stat['last_call_date'] === $today) ? (int)$stat['today_calls'] : 0;
            $tr_c      = $stat ? (int)$stat['translate_calls'] : 0;
            $ex_c      = $stat ? (int)$stat['exam_calls'] : 0;
            $last_code = $stat ? (int)$stat['last_http_code'] : null;

            $summary['total_calls']     += $total_c;
            $summary['today_calls']     += $today_c;
            $summary['success_calls']   += $succ_c;
            $summary['error_calls']     += $err_c;
            $summary['translate_calls'] += $tr_c;
            $summary['exam_calls']      += $ex_c;

            $keys_report[] = [
                'index'           => $idx + 1,
                'key_hash'        => $hash,
                'key_masked'      => $masked,
                'total_calls'     => $total_c,
                'today_calls'     => $today_c,
                'success_calls'   => $succ_c,
                'error_calls'     => $err_c,
                'translate_calls' => $tr_c,
                'exam_calls'      => $ex_c,
                'last_http_code'  => $last_code,
                'last_model'      => $stat['last_model'] ?? '---',
                'last_error'      => $stat['last_error'] ?? null,
                'updated_at'      => $stat['updated_at'] ?? null
            ];
        }

        echo json_encode([
            'status'        => 'success',
            'primary_model' => defined('AI_MODEL_NAME') ? AI_MODEL_NAME : 'gemini-3.7-flash',
            'summary'       => $summary,
            'keys'          => $keys_report
        ]);
        exit;
    }

    // ۶. تست سلامت و پینگ زنده تمام کلیدهای هوش مصنوعی (Health Check)
    if ($action === 'admin_ping_ai_keys') {
        $configured_keys = get_ordered_ai_keys_list();
        $model = defined('AI_MODEL_NAME') ? preg_replace('#^models/#i', '', trim(AI_MODEL_NAME)) : 'gemini-3.7-flash';
        $results = [];

        foreach ($configured_keys as $idx => $api_key) {
            $t0  = microtime(true);
            $url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($model) . ":generateContent?key=" . urlencode($api_key);
            $payload = [
                'contents'         => [['role' => 'user', 'parts' => [['text' => 'Reply OK']]]],
                'generationConfig' => ['maxOutputTokens' => 8]
            ];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Connection: close'],
                CURLOPT_POSTFIELDS     => json_encode($payload)
            ]);

            $res       = curl_exec($ch);
            $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_err  = curl_error($ch);
            curl_close($ch);

            $latency_ms = (int)round((microtime(true) - $t0) * 1000);
            $data       = json_decode($res, true);
            $is_ok      = ($http_code === 200 && isset($data['candidates']));
            $err_msg    = $is_ok ? '' : ($data['error']['message'] ?? ($curl_err ?: "HTTP {$http_code}"));

            record_ai_key_stat($pdo, $api_key, 'exam', $is_ok, $http_code, $model, $err_msg);

            $results[] = [
                'index'      => $idx + 1,
                'key_masked' => mask_ai_key_string($api_key),
                'ok'         => $is_ok,
                'http_code'  => $http_code,
                'latency_ms' => $latency_ms,
                'model'      => $model,
                'error'      => $err_msg
            ];
        }

        echo json_encode([
            'status'  => 'success',
            'results' => $results
        ]);
        exit;
    }

    // ۷. ریست و صفر کردن آمار مصرف کلیدهای هوش مصنوعی
    if ($action === 'admin_reset_ai_stats') {
        $pdo->exec("TRUNCATE TABLE ai_key_stats");
        echo json_encode(['status' => 'success', 'message' => 'آمار مصرف کلیدهای هوش مصنوعی صفر شد.']);
        exit;
    }

    // ۸. بروزرسانی دسته‌جمعی کلید پاسخنامه تمام سوالات یک آزمون (Bulk Answer Key Importer)
    if ($action === 'bulk_update_answer_key') {
        $exam_id     = (int)($_POST['exam_id'] ?? 0);
        $raw_key_str = trim($_POST['answer_key_str'] ?? '');

        if ($exam_id <= 0 || $raw_key_str === '') {
            echo json_encode(['status' => 'error', 'message' => 'لطفاً رشته کلید پاسخنامه را وارد کنید.']);
            exit;
        }

        // تبدیل اعداد فارسی/عربی به انگلیسی
        $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $english = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
        $norm_str = str_replace($persian, $english, $raw_key_str);

        $qStmt = $pdo->prepare("SELECT id, sort_order FROM exam_questions WHERE exam_id = ? ORDER BY sort_order ASC, id ASC");
        $qStmt->execute([$exam_id]);
        $questions = $qStmt->fetchAll();

        if (empty($questions)) {
            echo json_encode(['status' => 'error', 'message' => 'هنوز سوالی در این آزمون ثبت نشده است.']);
            exit;
        }

        // پشتیبانی از دو فرمت: ۱. جفتی (مثلاً 1-3, 2-1, 3:4) ۲. متوالی (مثلاً 3 1 4 2 1)
        $mapped_answers = []; // [ 1-based index => option (1..4) ]
        if (preg_match_all('/(\d+)\s*[-:=.)]\s*([1-4])/', $norm_str, $pair_matches, PREG_SET_ORDER) && count($pair_matches) >= 2) {
            foreach ($pair_matches as $pm) {
                $q_num = (int)$pm[1];
                $op    = (int)$pm[2];
                $mapped_answers[$q_num] = $op;
            }
        } else {
            preg_match_all('/[1-4]/', $norm_str, $seq_matches);
            foreach (($seq_matches[0] ?? []) as $i => $op) {
                $mapped_answers[$i + 1] = (int)$op;
            }
        }

        if (empty($mapped_answers)) {
            echo json_encode(['status' => 'error', 'message' => 'هیچ شماره گزینه معتبری (بین ۱ تا ۴) در متن واردشده پیدا نشد.']);
            exit;
        }

        $upd = $pdo->prepare("UPDATE exam_questions SET correct_option = ? WHERE id = ?");
        $updated_count = 0;

        foreach ($questions as $idx => $q_row) {
            $q_pos = $idx + 1;
            if (isset($mapped_answers[$q_pos])) {
                $upd->execute([$mapped_answers[$q_pos], (int)$q_row['id']]);
                $updated_count++;
            }
        }

        echo json_encode([
            'status'  => 'success',
            'updated' => $updated_count,
            'message' => "گزینه صحیح {$updated_count} سوال از روی کلید پاسخنامه بروزرسانی شد."
        ]);
        exit;
    }

    // ۹. لیست تمام آزمون‌ها برای پنل ادمین
    if ($action === 'admin_list_exams') {
        $stmt = $pdo->query("
            SELECT e.*,
                   (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id = e.id) AS question_count,
                   (SELECT COUNT(*) FROM exam_attempts a WHERE a.exam_id = e.id) AS attempt_count,
                   (SELECT ROUND(AVG(a.score_percent), 1) FROM exam_attempts a WHERE a.exam_id = e.id) AS avg_score
            FROM exams e
            ORDER BY e.id DESC
        ");
        echo json_encode([
            'status' => 'success',
            'exams'  => $stmt->fetchAll()
        ]);
        exit;
    }

    // ۱۰. دریافت جزئیات کامل یک آزمون برای ویرایش در پنل ادمین
    if ($action === 'admin_get_exam') {
        $exam_id = (int)($_GET['exam_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
        $stmt->execute([$exam_id]);
        $exam = $stmt->fetch();

        if (!$exam) {
            echo json_encode(['status' => 'error', 'message' => 'آزمون یافت نشد.']);
            exit;
        }

        $qStmt = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id = ? ORDER BY sort_order ASC, id ASC");
        $qStmt->execute([$exam_id]);
        $questions = $qStmt->fetchAll();

        echo json_encode([
            'status'    => 'success',
            'exam'      => $exam,
            'questions' => $questions
        ]);
        exit;
    }

    // ۱۱. ساخت یا ویرایش شناسنامه آزمون توسط ادمین
    if ($action === 'save_exam') {
        $id               = (int)($_POST['id'] ?? 0);
        $title            = trim($_POST['title'] ?? '');
        $category         = trim($_POST['category'] ?? 'عمومی');
        $description      = trim($_POST['description'] ?? '');
        $duration_mins    = max(1, (int)($_POST['duration_mins'] ?? 30));
        $negative_marking = (int)($_POST['negative_marking'] ?? 1) === 1 ? 1 : 0;
        $is_vip_only      = (int)($_POST['is_vip_only'] ?? 0) === 1 ? 1 : 0;
        $is_active        = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

        if (empty($title)) {
            echo json_encode(['status' => 'error', 'message' => 'لطفاً عنوان آزمون را وارد کنید.']);
            exit;
        }

        if ($id > 0) {
            $upd = $pdo->prepare("
                UPDATE exams
                SET title = ?, category = ?, description = ?, duration_mins = ?, negative_marking = ?, is_vip_only = ?, is_active = ?
                WHERE id = ?
            ");
            $upd->execute([$title, $category, $description, $duration_mins, $negative_marking, $is_vip_only, $is_active, $id]);
            echo json_encode(['status' => 'success', 'exam_id' => $id, 'message' => 'مشخصات آزمون بروزرسانی شد.']);
        } else {
            $ins = $pdo->prepare("
                INSERT INTO exams (title, category, description, duration_mins, negative_marking, is_vip_only, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([$title, $category, $description, $duration_mins, $negative_marking, $is_vip_only, $is_active]);
            $new_id = (int)$pdo->lastInsertId();
            echo json_encode(['status' => 'success', 'exam_id' => $new_id, 'message' => 'آزمون جدید ساخته شد؛ اکنون سوالات را اضافه کنید.']);
        }
        exit;
    }

    // ۱۲. حذف کامل یک آزمون به همراه تصاویر آن
    if ($action === 'delete_exam') {
        $exam_id = (int)($_POST['exam_id'] ?? 0);
        $qStmt = $pdo->prepare("SELECT question_image, explanation_image FROM exam_questions WHERE exam_id = ?");
        $qStmt->execute([$exam_id]);
        foreach ($qStmt->fetchAll() as $row) {
            if (!empty($row['question_image']) && is_file(dirname(__DIR__) . '/' . $row['question_image'])) {
                @unlink(dirname(__DIR__) . '/' . $row['question_image']);
            }
            if (!empty($row['explanation_image']) && is_file(dirname(__DIR__) . '/' . $row['explanation_image'])) {
                @unlink(dirname(__DIR__) . '/' . $row['explanation_image']);
            }
        }

        $del = $pdo->prepare("DELETE FROM exams WHERE id = ?");
        $del->execute([$exam_id]);
        echo json_encode(['status' => 'success', 'message' => 'آزمون و سوالات آن حذف شدند.']);
        exit;
    }

    // ۱۳. استخراج خودکار سوالات، شبه‌کدها و مختصات شکل‌ها از تصویر صفحه PDF با Gemini Vision
    if ($action === 'extract_questions_ai') {
        $image_base64 = $_POST['image_base64'] ?? '';
        $extra_hint   = trim($_POST['extra_hint'] ?? '');
        $page_index   = (int)($_POST['page_index'] ?? 0);

        if (empty($image_base64)) {
            echo json_encode(['status' => 'error', 'message' => 'تصویری برای پردازش ارسال نشده است.']);
            exit;
        }

        $mime_type = 'image/jpeg';
        if (preg_match('/^data:(image\/\w+);base64,/', $image_base64, $matches)) {
            $mime_type    = $matches[1];
            $image_base64 = substr($image_base64, strpos($image_base64, ',') + 1);
        }
        $image_base64 = str_replace(' ', '+', trim($image_base64));

        $ai_res = extract_questions_from_image_via_gemini($pdo, $mime_type, $image_base64, $extra_hint, $page_index);
        if ($ai_res['ok']) {
            echo json_encode([
                'status'    => 'success',
                'questions' => $ai_res['questions'],
                'model'     => $ai_res['model'],
                'key_used'  => $ai_res['key_used'],
                'message'   => count($ai_res['questions']) . ' سوال چهارگزینه‌ای با موفقیت استخراج شد.'
            ]);
        } else {
            echo json_encode([
                'status'  => 'error',
                'message' => 'خطا در استخراج هوشمند: ' . $ai_res['error']
            ]);
        }
        exit;
    }

    // ۱۴. آپلود مستقیم برش تصویر (دستی یا خودکار از روی مختصات figure_box)
    if ($action === 'upload_question_image') {
        $base64 = $_POST['image_base64'] ?? '';
        $saved_rel_path = save_base64_exam_image($base64, $exams_upload_dir);

        if ($saved_rel_path) {
            echo json_encode([
                'status'     => 'success',
                'image_path' => $saved_rel_path
            ]);
        } else {
            echo json_encode([
                'status'  => 'error',
                'message' => 'خطا در ذخیره تصویر برش‌خورده.'
            ]);
        }
        exit;
    }

    // ۱۵. ذخیره تکی یک سوال
    if ($action === 'save_question') {
        $id                = (int)($_POST['id'] ?? 0);
        $exam_id           = (int)($_POST['exam_id'] ?? 0);
        $question_text     = trim($_POST['question_text'] ?? '');
        $question_image    = trim($_POST['question_image'] ?? '');
        $option_1          = trim($_POST['option_1'] ?? 'گزینه ۱');
        $option_2          = trim($_POST['option_2'] ?? 'گزینه ۲');
        $option_3          = trim($_POST['option_3'] ?? 'گزینه ۳');
        $option_4          = trim($_POST['option_4'] ?? 'گزینه ۴');
        $correct_option    = max(1, min(4, (int)($_POST['correct_option'] ?? 1)));
        $explanation       = trim($_POST['explanation'] ?? '');
        $explanation_image = trim($_POST['explanation_image'] ?? '');

        if (!empty($_POST['question_image_base64'])) {
            $new_q_img = save_base64_exam_image($_POST['question_image_base64'], $exams_upload_dir);
            if ($new_q_img) $question_image = $new_q_img;
        }
        if (!empty($_POST['explanation_image_base64'])) {
            $new_e_img = save_base64_exam_image($_POST['explanation_image_base64'], $exams_upload_dir);
            if ($new_e_img) $explanation_image = $new_e_img;
        }

        if ($exam_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'شناسه آزمون نامعتبر است.']);
            exit;
        }

        if ($question_text === '' && $question_image === '') {
            echo json_encode(['status' => 'error', 'message' => 'حداقل متن صورت سوال یا تصویر سوال باید وارد شود.']);
            exit;
        }

        if ($id > 0) {
            $upd = $pdo->prepare("
                UPDATE exam_questions
                SET question_text = ?, question_image = ?, option_1 = ?, option_2 = ?, option_3 = ?, option_4 = ?,
                    correct_option = ?, explanation = ?, explanation_image = ?
                WHERE id = ? AND exam_id = ?
            ");
            $upd->execute([
                $question_text, $question_image ?: null, $option_1, $option_2, $option_3, $option_4,
                $correct_option, $explanation ?: null, $explanation_image ?: null,
                $id, $exam_id
            ]);
            echo json_encode(['status' => 'success', 'question_id' => $id, 'message' => 'سوال بروزرسانی شد.']);
        } else {
            $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM exam_questions WHERE exam_id = ?");
            $maxStmt->execute([$exam_id]);
            $next_order = (int)$maxStmt->fetchColumn() + 1;

            $ins = $pdo->prepare("
                INSERT INTO exam_questions
                (exam_id, sort_order, question_text, question_image, option_1, option_2, option_3, option_4, correct_option, explanation, explanation_image)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $exam_id, $next_order, $question_text, $question_image ?: null,
                $option_1, $option_2, $option_3, $option_4,
                $correct_option, $explanation ?: null, $explanation_image ?: null
            ]);
            echo json_encode([
                'status'      => 'success',
                'question_id' => (int)$pdo->lastInsertId(),
                'message'     => 'سوال به آزمون اضافه شد.'
            ]);
        }
        exit;
    }

    // ۱۶. ذخیره دسته‌جمعی سوالات استخراج‌شده در آزمون
    if ($action === 'save_questions_batch') {
        $exam_id        = (int)($_POST['exam_id'] ?? 0);
        $questions_json = $_POST['questions_json'] ?? '[]';
        $questions_list = json_decode($questions_json, true);

        if ($exam_id <= 0 || !is_array($questions_list) || empty($questions_list)) {
            echo json_encode(['status' => 'error', 'message' => 'لیست سوالات ارسالی معتبر نیست.']);
            exit;
        }

        $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM exam_questions WHERE exam_id = ?");
        $maxStmt->execute([$exam_id]);
        $next_order = (int)$maxStmt->fetchColumn() + 1;

        $ins = $pdo->prepare("
            INSERT INTO exam_questions
            (exam_id, sort_order, question_text, question_image, option_1, option_2, option_3, option_4, correct_option, explanation)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $inserted_count = 0;
        foreach ($questions_list as $q) {
            $q_text = trim($q['question_text'] ?? '');
            $q_img  = trim($q['question_image'] ?? '');
            if ($q_text === '' && $q_img === '') continue;

            $opt1 = trim($q['option_1'] ?? 'گزینه ۱');
            $opt2 = trim($q['option_2'] ?? 'گزینه ۲');
            $opt3 = trim($q['option_3'] ?? 'گزینه ۳');
            $opt4 = trim($q['option_4'] ?? 'گزینه ۴');
            $corr = max(1, min(4, (int)($q['correct_option'] ?? 1)));
            $expl = trim($q['explanation'] ?? '');

            $ins->execute([
                $exam_id,
                $next_order++,
                $q_text,
                $q_img ?: null,
                $opt1 !== '' ? $opt1 : 'گزینه ۱',
                $opt2 !== '' ? $opt2 : 'گزینه ۲',
                $opt3 !== '' ? $opt3 : 'گزینه ۳',
                $opt4 !== '' ? $opt4 : 'گزینه ۴',
                $corr,
                $expl ?: null
            ]);
            $inserted_count++;
        }

        echo json_encode([
            'status'  => 'success',
            'count'   => $inserted_count,
            'message' => "{$inserted_count} سوال با موفقیت به بانک سوالات این آزمون اضافه شد."
        ]);
        exit;
    }

    // ۱۷. حذف یک سوال از آزمون
    if ($action === 'delete_question') {
        $question_id = (int)($_POST['question_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT question_image, explanation_image FROM exam_questions WHERE id = ?");
        $stmt->execute([$question_id]);
        $q = $stmt->fetch();

        if ($q) {
            if (!empty($q['question_image']) && is_file(dirname(__DIR__) . '/' . $q['question_image'])) {
                @unlink(dirname(__DIR__) . '/' . $q['question_image']);
            }
            if (!empty($q['explanation_image']) && is_file(dirname(__DIR__) . '/' . $q['explanation_image'])) {
                @unlink(dirname(__DIR__) . '/' . $q['explanation_image']);
            }
        }

        $del = $pdo->prepare("DELETE FROM exam_questions WHERE id = ?");
        $del->execute([$question_id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'اکشن نامعتبر است.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'خطای سرور: ' . $e->getMessage()]);
}