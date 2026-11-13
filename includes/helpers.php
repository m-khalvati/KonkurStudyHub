<?php
/**
 * Shared Helper Functions (includes/helpers.php)
 */
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
require_once __DIR__ . '/security_shield.php';