<?php
if (!function_exists('gdate_to_jalali_str')) {
    function gdate_to_jalali_str($g_date) {
        $parts = explode('-', trim((string)$g_date));
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
}

// پل ارتباطی خودکار برای تمام فایل‌های پوشه api/
if (!headers_sent()) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Client-Platform");
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$mobile_token = null;
if (!empty($_GET['api_token'])) {
    $mobile_token = trim((string)$_GET['api_token']);
} elseif (!empty($_POST['api_token'])) {
    $mobile_token = trim((string)$_POST['api_token']);
} elseif (!empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
    $mobile_token = $m[1];
} elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && preg_match('/Bearer\s+(\S+)/i', $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $m)) {
    $mobile_token = $m[1];
}

if (!empty($mobile_token) && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT id, full_name, username, phone, is_admin FROM users WHERE api_token = ? LIMIT 1");
        $stmt->execute([$mobile_token]);
        $mob_user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($mob_user) {
            $_SESSION['user_id']   = (int)$mob_user['id'];
            $_SESSION['full_name'] = $mob_user['full_name'];
            $_SESSION['username']  = $mob_user['username'];
            $_SESSION['phone']     = $mob_user['phone'] ?? '';
            $_SESSION['is_admin']  = (int)($mob_user['is_admin'] ?? 0);
        }
    } catch (Exception $ignore) {}
}