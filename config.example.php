<?php
session_start();
date_default_timezone_set('Asia/Tehran');

// ==========================================
// ۱. تنظیمات اتصال به دیتابیس MySQL
// ==========================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

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
// ۲. توابع تبدیل تاریخ میلادی و شمسی (جلالی)
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

// ==========================================
// ۳. تنظیم تاریخ هدف کنکور (۱۶ بهمن)
// ==========================================
list($cur_jy, $cur_jm, $cur_jd) = gregorian_to_jalali((int)date('Y'), (int)date('m'), (int)date('d'));

// اگر از ۱۶ بهمن امسال عبور کرده باشیم، ۱۶ بهمن سال بعد هدف قرار می‌گیرد
$target_jy = ($cur_jm > 11 || ($cur_jm == 11 && $cur_jd > 16)) ? $cur_jy + 1 : $cur_jy;
list($t_gy, $t_gm, $t_gd) = jalali_to_gregorian($target_jy, 11, 16);

define('KONKUR_JALALI_DATE', $target_jy . '-11-16');
define('KONKUR_GREGORIAN_DATE', sprintf('%04d-%02d-%02d', $t_gy, $t_gm, $t_gd));

// محاسبه تعداد روزهای باقی‌مانده تا ۱۶ بهمن
$today_ts = strtotime(date('Y-m-d'));
$konkur_ts = strtotime(KONKUR_GREGORIAN_DATE);
$days_left = max(0, (int)round(($konkur_ts - $today_ts) / 86400));
define('DAYS_TO_KONKUR', $days_left);

// ==========================================
// ۴. تابع بررسی وضعیت لاگین کاربر
// ==========================================
function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: auth.php");
        exit;
    }
}