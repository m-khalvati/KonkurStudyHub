<?php
session_start();
date_default_timezone_set('Asia/Tehran');

// ==========================================
// ۱. تنظیمات اتصال به دیتابیس MySQL هاست
// ==========================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

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
// ۴. ارتقای خودکار دیتابیس برای سیستم اشتراک و ادمین
// ==========================================
try {
    $uCols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('phone', $uCols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `phone` varchar(15) DEFAULT NULL AFTER `username`");
    }
    if (!in_array('is_admin', $uCols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `is_admin` tinyint(1) DEFAULT 0 AFTER `password_hash`");
        // اولین کاربر سایت (صاحب سایت) به صورت خودکار ادمین می‌شود
        $pdo->exec("UPDATE `users` SET `is_admin` = 1 WHERE `id` = 1");
    }
    if (!in_array('vip_expires_at', $uCols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `vip_expires_at` datetime DEFAULT NULL AFTER `is_admin`");
    }

    // ساخت جدول کدهای فعال‌سازی اشتراک (کدهای یک‌بارمصرف ۱ ماهه و ۳ ماهه)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `subscription_codes` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `code` varchar(32) NOT NULL UNIQUE,
          `plan_type` varchar(20) NOT NULL COMMENT '1m یا 3m',
          `days` int(11) NOT NULL DEFAULT 30,
          `is_used` tinyint(1) DEFAULT 0,
          `used_by` int(11) DEFAULT NULL,
          `used_at` datetime DEFAULT NULL,
          `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
    ");
} catch (Exception $ignore) {}

// ==========================================
// ۵. توابع تبدیل تاریخ میلادی و شمسی (جلالی)
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
// ۶. تنظیم تاریخ هدف کنکور (۱۶ بهمن)
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
// ۷. توابع بررسی و تمدید تجمعی اشتراک ویژه (VIP)
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

// تمدید تجمعی اشتراک (اگر کاربر روز فعال داشته باشد، روزهای جدید به انتهای تاریخ فعلی اضافه می‌شود)
function extend_user_subscription($pdo, $user_id, $add_days) {
    $stmt = $pdo->prepare("SELECT vip_expires_at FROM users WHERE id = ?");
    $stmt->execute([(int)$user_id]);
    $row = $stmt->fetch();

    $now = time();
    $current_exp = (!empty($row['vip_expires_at'])) ? strtotime($row['vip_expires_at']) : 0;

    // اگر هنوز اشتراک فعال دارد، به تاریخ انقضای فعلی اضافه کن؛ وگرنه از همین لحظه حساب کن
    $base_ts = ($current_exp > $now) ? $current_exp : $now;
    $new_exp_str = date('Y-m-d H:i:s', strtotime("+{$add_days} days", $base_ts));

    $upd = $pdo->prepare("UPDATE users SET vip_expires_at = ? WHERE id = ?");
    $upd->execute([$new_exp_str, (int)$user_id]);

    return $new_exp_str;
}

// ==========================================
// ۸. تابع ارسال پیامک سریع (قالب خدماتی) با sms.ir
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