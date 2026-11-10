<?php
session_start();
date_default_timezone_set('Asia/Tehran');

// ==========================================
// ۱. تنظیمات اتصال به دیتابیس MySQL هاست
// ==========================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'konkur_db'); // نام دیتابیس خود را وارد کنید
define('DB_USER', 'root');      // نام کاربری دیتابیس هاست
define('DB_PASS', '');          // رمز عبور دیتابیس هاست

// ==========================================
// ۲. تنظیمات اشتراک ویژه و فروش تلگرام
// ==========================================
define('TELEGRAM_SALES_ID', 'YourTelegramID'); // آیدی تلگرام فروش بدون @
define('FREE_MAX_PDF_COUNT', 1);               // حداکثر تعداد PDF برای کاربر عادی (۱ فایل)
define('FREE_MAX_PDF_MB', 3);                  // حداکثر حجم هر PDF برای کاربر عادی (۳ مگابایت)
define('VIP_MAX_PDF_MB', 64);                  // حداکثر حجم هر PDF برای کاربر ویژه (۶۴ مگابایت)
define('PLAN_1M_PRICE', '۵۰۰,۰۰۰');            // قیمت اشتراک ۱ ماهه (تومان)
define('PLAN_3M_PRICE', '۱,۲۰۰,۰۰۰');          // قیمت اشتراک ۳ ماهه (تومان)

// ==========================================
// ۳. تنظیمات وب‌سرویس پیامک خدماتی (sms.ir)
// ==========================================
define('SMSIR_ENABLED', true);
define('SMSIR_API_KEY', 'YOUR_SMSIR_API_KEY');
define('SMSIR_TEMPLATE_ID', 100000);
define('SMSIR_PARAM_NAME', 'NAME');
define('SMSIR_PARAM_TASK', 'TASK');

// ==========================================
// ۴. تنظیمات مقاله‌خوان و استخر چرخشی کلیدهای هوش مصنوعی (Multi-Key Pool)
// ==========================================
define('AI_TRANSLATION_ENABLED', true);      // true = فعال بودن هوش مصنوعی | false = غیرفعال کردن کامل
define('AI_VIP_ONLY', true);                 // true = هوش مصنوعی مخصوص کاربران ویژه (VIP) باشد
define('AI_PROVIDER', 'gemini');             // 'gemini' (پیشنهادی) یا 'openai' (سازگار با Groq / OpenRouter / DeepSeek)
define('AI_MODEL_NAME', 'gemini-3.7-flash'); // نام مدل هوش مصنوعی
define('AI_OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions');

// لیست چرخشی کلیدهای API شما (می‌توانید ۱ تا ۱۰+ کلید رایگان جمینای قرار دهید تا ترافیک بین آن‌ها تقسیم شود)
define('AI_API_KEYS', [
    'YOUR_GEMINI_API_KEY_1',
    'YOUR_GEMINI_API_KEY_2',
    'YOUR_GEMINI_API_KEY_3',
    // در صورت نیاز کلیدهای بیشتر را در خط‌های بعدی اضافه کنید
]);

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die("خطا در اتصال به دیتابیس: " . $e->getMessage());
}

// ==========================================
// ۵. ارتقای خودکار جداول و ستون‌های دیتابیس
// ==========================================
try {
    $uCols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('phone', $uCols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `phone` varchar(15) DEFAULT NULL AFTER `username`");
    }
    if (!in_array('is_admin', $uCols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `is_admin` tinyint(1) DEFAULT 0 AFTER `password_hash`");
        $pdo->exec("UPDATE `users` SET `is_admin` = 1 WHERE `id` = 1");
    }
    if (!in_array('vip_expires_at', $uCols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `vip_expires_at` datetime DEFAULT NULL AFTER `is_admin`");
    }

    $pCols = $pdo->query("SHOW COLUMNS FROM `pdfs`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('is_paper', $pCols)) {
        $pdo->exec("ALTER TABLE `pdfs` ADD COLUMN `is_paper` tinyint(1) DEFAULT 0 COMMENT '0=جزوه درسی, 1=مقاله انگلیسی' AFTER `folder_id`");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `subscription_codes` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `code` varchar(32) NOT NULL UNIQUE,
          `plan_type` varchar(20) NOT NULL,
          `days` int(11) NOT NULL DEFAULT 30,
          `is_used` tinyint(1) DEFAULT 0,
          `used_by` int(11) DEFAULT NULL,
          `used_at` datetime DEFAULT NULL,
          `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
    ");

    // جدول کش هوشمند ترجمه‌ها، واژگان تخصصی و خلاصه‌ها
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `translation_cache` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `source_hash` char(32) NOT NULL,
          `engine` varchar(30) NOT NULL,
          `source_text` text NOT NULL,
          `translated_text` text NOT NULL,
          `keywords_json` text DEFAULT NULL,
          `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_hash_engine` (`source_hash`, `engine`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
    ");

    $tcCols = $pdo->query("SHOW COLUMNS FROM `translation_cache`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('keywords_json', $tcCols)) {
        $pdo->exec("ALTER TABLE `translation_cache` ADD COLUMN `keywords_json` text DEFAULT NULL AFTER `translated_text`");
    }

    // جدول ذخیره فیش‌های پژوهشی مقاله (به همراه para_hash برای متمایز کردن رنگ بند در PDF)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `paper_notes` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `pdf_id` int(11) NOT NULL,
          `page_number` int(11) NOT NULL DEFAULT 1,
          `para_hash` char(32) DEFAULT NULL,
          `original_text` text NOT NULL,
          `translated_text` text NOT NULL,
          `user_comment` text DEFAULT NULL,
          `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_user_paper_note` (`user_id`, `pdf_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
    ");

    $pnCols = $pdo->query("SHOW COLUMNS FROM `paper_notes`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('para_hash', $pnCols)) {
        $pdo->exec("ALTER TABLE `paper_notes` ADD COLUMN `para_hash` char(32) DEFAULT NULL AFTER `page_number`");
    }
} catch (Exception $ignore) {}

// ==========================================
// ۶. توابع تبدیل تاریخ میلادی و شمسی (جلالی)
// ==========================================
function gregorian_to_jalali($gy, $gm, $gd) {
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + (int)(($gy2 + 3) / 4) - (int)(($gy2 + 99) / 100) + (int)(($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * (int)($days / 12053));
    $days %= 12053;
    $jy += 4 * (int)($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int)($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

function jalali_to_gregorian($jy, $jm, $jd) {
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (((int)($jy / 33)) * 8) + ((int)((($jy % 33) + 3) / 4)) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * ((int)($days / 146097));
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * ((int)(--$days / 36524));
        $days %= 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $gy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $sal_a = [0, 31, (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    for ($gm = 0; $gm < 13 && $gd > $sal_a[$gm]; $gm++) $gd -= $sal_a[$gm];
    return [$gy, $gm, $gd];
}

function format_jalali($gy, $gm, $gd) {
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf('%04d-%02d-%02d', $jy, $jm, $jd);
}

function datetime_to_jalali_str($datetime_str) {
    if (empty($datetime_str)) return '---';
    $ts = strtotime($datetime_str);
    if (!$ts) return '---';
    return format_jalali((int)date('Y', $ts), (int)date('m', $ts), (int)date('d', $ts));
}

// ==========================================
// ۷. تنظیم تاریخ هدف کنکور (۱۶ بهمن)
// ==========================================
list($cur_jy, $cur_jm, $cur_jd) = gregorian_to_jalali((int)date('Y'), (int)date('m'), (int)date('d'));
$target_jy = ($cur_jm > 11 || ($cur_jm == 11 && $cur_jd > 16)) ? $cur_jy + 1 : $cur_jy;
list($t_gy, $t_gm, $t_gd) = jalali_to_gregorian($target_jy, 11, 16);

define('KONKUR_JALALI_DATE', $target_jy . '-11-16');
define('KONKUR_GREGORIAN_DATE', sprintf('%04d-%02d-%02d', $t_gy, $t_gm, $t_gd));

$today_ts = strtotime(date('Y-m-d'));
$konkur_ts = strtotime(KONKUR_GREGORIAN_DATE);
$days_left = max(0, (int)round(($konkur_ts - $today_ts) / 86400));
define('DAYS_TO_KONKUR', $days_left);

// ==========================================
// ۸. توابع بررسی و تمدید تجمعی اشتراک ویژه (VIP)
// ==========================================
function get_user_subscription_info($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT id, full_name, username, phone, is_admin, vip_expires_at FROM users WHERE id = ?");
    $stmt->execute([(int)$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        return [
            'is_vip'       => false,
            'is_admin'     => false,
            'days_left'    => 0,
            'expires_at'   => null,
            'expires_j'    => '---',
            'max_pdf_mb'   => FREE_MAX_PDF_MB,
            'max_pdf_cnt'  => FREE_MAX_PDF_COUNT
        ];
    }

    $now = time();
    $exp_ts = !empty($user['vip_expires_at']) ? strtotime($user['vip_expires_at']) : 0;
    $is_vip = ($exp_ts > $now);
    $days_remaining = $is_vip ? (int)ceil(($exp_ts - $now) / 86400) : 0;

    return [
        'is_vip'       => $is_vip,
        'is_admin'     => ((int)$user['is_admin'] === 1),
        'days_left'    => $days_remaining,
        'expires_at'   => $user['vip_expires_at'],
        'expires_j'    => $is_vip ? datetime_to_jalali_str($user['vip_expires_at']) : 'ندارد',
        'max_pdf_mb'   => $is_vip ? VIP_MAX_PDF_MB : FREE_MAX_PDF_MB,
        'max_pdf_cnt'  => $is_vip ? 9999 : FREE_MAX_PDF_COUNT,
        'username'     => $user['username'],
        'full_name'    => $user['full_name'],
        'phone'        => $user['phone'] ?? ''
    ];
}

function extend_user_subscription($pdo, $user_id, $add_days) {
    $stmt = $pdo->prepare("SELECT vip_expires_at FROM users WHERE id = ?");
    $stmt->execute([(int)$user_id]);
    $row = $stmt->fetch();

    $now = time();
    $current_exp = (!empty($row['vip_expires_at'])) ? strtotime($row['vip_expires_at']) : 0;

    $base_ts = ($current_exp > $now) ? $current_exp : $now;
    $new_exp_str = date('Y-m-d H:i:s', strtotime("+{$add_days} days", $base_ts));

    $upd = $pdo->prepare("UPDATE users SET vip_expires_at = ? WHERE id = ?");
    $upd->execute([$new_exp_str, (int)$user_id]);

    return $new_exp_str;
}

// ==========================================
// ۹. تابع ارسال پیامک سریع (قالب خدماتی) با sms.ir
// ==========================================
function send_smsir_verify($mobile, $user_name, $task_title) {
    if (!SMSIR_ENABLED || empty(SMSIR_API_KEY) || SMSIR_API_KEY === 'YOUR_SMSIR_API_KEY') {
        return ['status' => false, 'message' => 'API Key تنظیم نشده است'];
    }

    $clean_name = mb_substr(trim($user_name), 0, 24, 'UTF-8');
    $clean_task = mb_substr(trim($task_title), 0, 24, 'UTF-8');

    $payload = [
        'mobile'     => $mobile,
        'templateId' => (int)SMSIR_TEMPLATE_ID,
        'parameters' => [
            ['name' => SMSIR_PARAM_NAME, 'value' => $clean_name],
            ['name' => SMSIR_PARAM_TASK, 'value' => $clean_task]
        ]
    ];

    $ch = curl_init('https://api.sms.ir/v1/send/verify');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: text/plain',
            'x-api-key: ' . SMSIR_API_KEY
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $res_data = json_decode($response, true);
    $is_ok = ($http_code === 200 && isset($res_data['status']) && (int)$res_data['status'] === 1);

    return [
        'status'   => $is_ok,
        'response' => $res_data
    ];
}

function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: auth.php");
        exit;
    }
}