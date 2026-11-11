<?php
require_once 'config.php';

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
        'max_pdf_mb'  => defined('FREE_MAX_PDF_MB') ? FREE_MAX_PDF_MB : 3,
        'max_pdf_cnt' => defined('FREE_MAX_PDF_COUNT') ? FREE_MAX_PDF_COUNT : 1,
        'username'    => $_SESSION['username'] ?? '',
        'full_name'   => $_SESSION['full_name'] ?? '',
        'phone'       => $_SESSION['phone'] ?? ''
    ];

// تابع مستقل و بدون خطای تبدیل رشته تاریخ میلادی (YYYY-MM-DD) به تاریخ شمسی
function gdate_str_to_jalali($g_date) {
    $parts = explode('-', trim($g_date));
    if (count($parts) !== 3) return '';
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

// تابع کمکی محاسبه درصد تست کنکور با نمره منفی
function calc_konkur_percent($correct, $wrong, $blank) {
    if ($correct === null && $wrong === null && $blank === null) {
        return null;
    }
    $c = max(0, (int)$correct);
    $w = max(0, (int)$wrong);
    $b = max(0, (int)$blank);
    $total = $c + $w + $b;
    if ($total <= 0) {
        return null;
    }
    $pct = ((($c * 3) - $w) / ($total * 3)) * 100;
    return round(max(-33.33, min(100, $pct)), 2);
}

// تابع کمکی تشخیص نوع و برچسب دسته‌بندی بر اساس کد رنگ انتخابی
function resolve_category_by_color($pdo, $user_id, $color) {
    $norm_color = strtolower(trim($color));
    $defaults = [
        '#4f46e5' => ['type' => 'specialized', 'label' => 'تخصصی',  'is_study' => 1],
        '#059669' => ['type' => 'review',      'label' => 'مرور',   'is_study' => 1],
        '#d97706' => ['type' => 'test',        'label' => 'تست‌زنی', 'is_study' => 1],
        '#e11d48' => ['type' => 'exam',        'label' => 'آزمون',  'is_study' => 1],
        '#7c3aed' => ['type' => 'misc',        'label' => 'متفرقه', 'is_study' => 0],
    ];

    if (isset($defaults[$norm_color])) {
        return $defaults[$norm_color];
    }

    try {
        $stmt = $pdo->prepare("SELECT name, is_study FROM calendar_categories WHERE user_id = ? AND LOWER(color) = ? LIMIT 1");
        $stmt->execute([$user_id, $norm_color]);
        $custom = $stmt->fetch();

        if ($custom) {
            return [
                'type'     => ((int)$custom['is_study'] === 1) ? 'custom_study' : 'misc',
                'label'    => $custom['name'],
                'is_study' => (int)$custom['is_study']
            ];
        }
    } catch (Exception $ignore) {}

    return ['type' => 'specialized', 'label' => 'تخصصی', 'is_study' => 1];
}

try {
    // ====================================================
    // ۱. دریافت لیست برنامه‌های بازه زمانی، دسته‌های سفارشی، آمار و عقب‌افتاده‌ها
    // ====================================================
    if ($action === 'list') {
        $start = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
        $end   = $_GET['end']   ?? date('Y-m-d', strtotime('+7 days'));

        $stmt = $pdo->prepare("
            SELECT *
            FROM calendar_events
            WHERE user_id = ? AND g_date BETWEEN ? AND ?
            ORDER BY g_date ASC, start_time ASC, id ASC
        ");
        $stmt->execute([$user_id, $start, $end]);
        $events = $stmt->fetchAll();

        $custom_categories = [];
        try {
            $catStmt = $pdo->prepare("SELECT id, name, color, is_study FROM calendar_categories WHERE user_id = ? ORDER BY id ASC");
            $catStmt->execute([$user_id]);
            $custom_categories = $catStmt->fetchAll();
        } catch (Exception $ignore) {}

        $study_total          = 0;
        $study_completed      = 0;
        $study_planned_mins   = 0;
        $study_completed_mins = 0;
        $misc_planned_mins    = 0;
        $misc_completed_mins  = 0;
        $test_percents        = [];

        foreach ($events as $ev) {
            $sh_parts = explode(':', $ev['start_time']);
            $eh_parts = explode(':', $ev['end_time']);
            $s_mins   = ((int)$sh_parts[0] * 60) + (int)($sh_parts[1] ?? 0);
            $e_mins   = ((int)$eh_parts[0] * 60) + (int)($eh_parts[1] ?? 0);
            $dur_mins = max(0, $e_mins - $s_mins);

            $is_study = isset($ev['is_study'])
                ? ((int)$ev['is_study'] === 1)
                : (($ev['category_type'] ?? '') !== 'misc' && strtolower($ev['color'] ?? '') !== '#7c3aed');

            if ($is_study) {
                $study_total++;
                $study_planned_mins += $dur_mins;
                if ((int)$ev['is_completed'] === 1) {
                    $study_completed++;
                    $study_completed_mins += $dur_mins;
                }
            } else {
                $misc_planned_mins += $dur_mins;
                if ((int)$ev['is_completed'] === 1) {
                    $misc_completed_mins += $dur_mins;
                }
            }

            if (isset($ev['test_percent']) && $ev['test_percent'] !== null && $ev['test_percent'] !== '') {
                $test_percents[] = (float)$ev['test_percent'];
            }
        }

        // استخراج برنامه‌های درسی عقب‌افتاده
        $overdue_events = [];
        try {
            $now_date = date('Y-m-d');
            $now_time = date('H:i:s');
            $overdueStmt = $pdo->prepare("
                SELECT id, title, g_date, j_date, start_time, end_time, color, category_label, pdf_id, pdf_page
                FROM calendar_events
                WHERE user_id = ?
                  AND is_completed = 0
                  AND is_study = 1
                  AND (g_date < ? OR (g_date = ? AND end_time < ?))
                ORDER BY g_date DESC, start_time DESC
                LIMIT 30
            ");
            $overdueStmt->execute([$user_id, $now_date, $now_date, $now_time]);
            $overdue_events = $overdueStmt->fetchAll();
        } catch (Exception $ignore) {}

        $avg_test = count($test_percents) > 0
            ? round(array_sum($test_percents) / count($test_percents), 1)
            : null;

        echo json_encode([
            'status'            => 'success',
            'events'            => $events,
            'overdue_events'    => $overdue_events,
            'custom_categories' => $custom_categories,
            'subscription'      => $sub_info,
            'user_phone'        => $sub_info['phone'] ?? '',
            'stats'             => [
                'study_total'          => $study_total,
                'study_completed'      => $study_completed,
                'planned_hours'        => round($study_planned_mins / 60, 1),
                'completed_hours'      => round($study_completed_mins / 60, 1),
                'misc_planned_hours'   => round($misc_planned_mins / 60, 1),
                'misc_completed_hours' => round($misc_completed_mins / 60, 1),
                'avg_test_percent'     => $avg_test
            ]
        ]);
        exit;
    }

    // ====================================================
    // ۲. ذخیره یا ویرایش برنامه (با پیش‌فرض غیرفعال برای پیامک یادآوری)
    // ====================================================
    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $g_date      = trim($_POST['g_date'] ?? '');
        $start_time  = trim($_POST['start_time'] ?? '');
        $end_time    = trim($_POST['end_time'] ?? '');
        $color       = trim($_POST['color'] ?? '#4f46e5');
        $repeat_mode = $_POST['repeat_mode'] ?? 'none';
        $pdf_id      = !empty($_POST['pdf_id']) ? (int)$_POST['pdf_id'] : null;
        $pdf_page    = max(1, (int)($_POST['pdf_page'] ?? 1));

        // پیش‌فرض تیک پیامک یادآوری غیرفعال (0) است
        $send_sms = (!empty($sub_info['is_vip']) && isset($_POST['send_sms']) && (int)$_POST['send_sms'] === 1) ? 1 : 0;

        if (empty($title) || empty($g_date) || empty($start_time) || empty($end_time)) {
            echo json_encode(['status' => 'error', 'message' => 'لطفاً عنوان، تاریخ و ساعت شروع و پایان را وارد کنید.']);
            exit;
        }

        if ($end_time <= $start_time) {
            echo json_encode(['status' => 'error', 'message' => 'ساعت پایان باید بعد از ساعت شروع باشد.']);
            exit;
        }

        if (defined('KONKUR_GREGORIAN_DATE') && $g_date > KONKUR_GREGORIAN_DATE) {
            echo json_encode(['status' => 'error', 'message' => 'امکان ثبت برنامه پس از تاریخ ۱۶ بهمن (روز کنکور) وجود ندارد.']);
            exit;
        }

        $cat_info = resolve_category_by_color($pdo, $user_id, $color);

        $tc = ($_POST['test_correct'] ?? '') !== '' ? max(0, (int)$_POST['test_correct']) : null;
        $tw = ($_POST['test_wrong']   ?? '') !== '' ? max(0, (int)$_POST['test_wrong'])   : null;
        $tb = ($_POST['test_blank']   ?? '') !== '' ? max(0, (int)$_POST['test_blank'])   : null;
        $test_percent = calc_konkur_percent($tc, $tw, $tb);

        if ($id > 0) {
            $j_date = gdate_str_to_jalali($g_date);
            $upd = $pdo->prepare("
                UPDATE calendar_events
                SET title = ?, description = ?, g_date = ?, j_date = ?, start_time = ?, end_time = ?,
                    color = ?, category_type = ?, category_label = ?, is_study = ?, send_sms = ?,
                    pdf_id = ?, pdf_page = ?, test_correct = ?, test_wrong = ?, test_blank = ?, test_percent = ?
                WHERE id = ? AND user_id = ?
            ");
            $upd->execute([
                $title, $description, $g_date, $j_date, $start_time, $end_time,
                $color, $cat_info['type'], $cat_info['label'], $cat_info['is_study'], $send_sms,
                $pdf_id, $pdf_page, $tc, $tw, $tb, $test_percent,
                $id, $user_id
            ]);

            echo json_encode(['status' => 'success', 'message' => 'برنامه با موفقیت بروزرسانی شد.']);
            exit;
        }

        // ایجاد برنامه جدید (با پشتیبانی از تکرار روتین هفتگی)
        $target_dates = [$g_date];
        if ($repeat_mode === 'week_daily' || $repeat_mode === 'week_alternate') {
            $base_ts = strtotime($g_date);
            $iranian_day_idx = ((int)date('w', $base_ts) + 1) % 7;
            $days_until_friday = 6 - $iranian_day_idx;
            $step = ($repeat_mode === 'week_alternate') ? 2 : 1;

            for ($d = $step; $d <= $days_until_friday; $d += $step) {
                $next_g = date('Y-m-d', strtotime("+{$d} days", $base_ts));
                if (!defined('KONKUR_GREGORIAN_DATE') || $next_g <= KONKUR_GREGORIAN_DATE) {
                    $target_dates[] = $next_g;
                }
            }
        }

        $ins = $pdo->prepare("
            INSERT INTO calendar_events
            (user_id, title, description, g_date, j_date, start_time, end_time, color, category_type, category_label, is_study, is_completed, send_sms, sms_sent, pdf_id, pdf_page, test_correct, test_wrong, test_blank, test_percent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($target_dates as $idx => $dt) {
            $j_dt = gdate_str_to_jalali($dt);
            $ins->execute([
                $user_id, $title, $description, $dt, $j_dt, $start_time, $end_time,
                $color, $cat_info['type'], $cat_info['label'], $cat_info['is_study'],
                $send_sms, $pdf_id, $pdf_page,
                ($idx === 0 ? $tc : null),
                ($idx === 0 ? $tw : null),
                ($idx === 0 ? $tb : null),
                ($idx === 0 ? $test_percent : null)
            ]);
        }

        echo json_encode(['status' => 'success', 'message' => 'برنامه در تقویم ثبت شد.']);
        exit;
    }

    // ====================================================
    // ۳. جابه‌جایی و تغییر سایز زنده با کشیدن موس یا لمس (Drag & Drop / Resize)
    // ====================================================
    if ($action === 'move_resize') {
        $id         = (int)($_POST['id'] ?? 0);
        $g_date     = trim($_POST['g_date'] ?? '');
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time   = trim($_POST['end_time'] ?? '');

        if ($id <= 0 || empty($g_date) || empty($start_time) || empty($end_time)) {
            echo json_encode(['status' => 'error', 'message' => 'اطلاعات جابه‌جایی ناقص است.']);
            exit;
        }

        if (defined('KONKUR_GREGORIAN_DATE') && $g_date > KONKUR_GREGORIAN_DATE) {
            echo json_encode(['status' => 'error', 'message' => 'انتقال برنامه به بعد از ۱۶ بهمن مجاز نیست.']);
            exit;
        }

        if ($end_time <= $start_time) {
            echo json_encode(['status' => 'error', 'message' => 'بازه زمانی نامعتبر است.']);
            exit;
        }

        $j_date = gdate_str_to_jalali($g_date);
        $stmt = $pdo->prepare("
            UPDATE calendar_events
            SET g_date = ?, j_date = ?, start_time = ?, end_time = ?, sms_sent = 0
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$g_date, $j_date, $start_time, $end_time, $id, $user_id]);

        echo json_encode([
            'status'     => 'success',
            'id'         => $id,
            'g_date'     => $g_date,
            'j_date'     => $j_date,
            'start_time' => $start_time,
            'end_time'   => $end_time
        ]);
        exit;
    }

    // ====================================================
    // ۴. کپی سریع از یک تسک (Duplicate Task)
    // ====================================================
    if ($action === 'duplicate') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM calendar_events WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $src = $stmt->fetch();

        if (!$src) {
            echo json_encode(['status' => 'error', 'message' => 'برنامه مورد نظر یافت نشد.']);
            exit;
        }

        $sh_parts = explode(':', $src['start_time']);
        $eh_parts = explode(':', $src['end_time']);
        $s_mins   = ((int)$sh_parts[0] * 60) + (int)($sh_parts[1] ?? 0);
        $e_mins   = ((int)$eh_parts[0] * 60) + (int)($eh_parts[1] ?? 0);
        $dur      = max(30, $e_mins - $s_mins);

        if ($e_mins + $dur <= 1435) {
            $new_s_mins = $e_mins;
            $new_e_mins = $e_mins + $dur;
        } else {
            $new_s_mins = $s_mins;
            $new_e_mins = $e_mins;
        }

        $new_start = sprintf('%02d:%02d:00', intdiv($new_s_mins, 60), $new_s_mins % 60);
        $new_end   = sprintf('%02d:%02d:00', intdiv($new_e_mins, 60), $new_e_mins % 60);

        $ins = $pdo->prepare("
            INSERT INTO calendar_events
            (user_id, title, description, g_date, j_date, start_time, end_time, color, category_type, category_label, is_study, is_completed, send_sms, sms_sent, pdf_id, pdf_page)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0, ?, ?)
        ");
        $ins->execute([
            $user_id,
            $src['title'],
            $src['description'],
            $src['g_date'],
            $src['j_date'],
            $new_start,
            $new_end,
            $src['color'],
            $src['category_type'] ?? 'specialized',
            $src['category_label'] ?? 'تخصصی',
            (int)($src['is_study'] ?? 1),
            (int)($src['send_sms'] ?? 0),
            $src['pdf_id'],
            (int)($src['pdf_page'] ?? 1)
        ]);

        echo json_encode([
            'status'  => 'success',
            'message' => 'یک کپی از برنامه ساخته شد.',
            'new_id'  => (int)$pdo->lastInsertId()
        ]);
        exit;
    }

    // ====================================================
    // ۵. تیک زدن / لغو تیک انجام برنامه
    // ====================================================
    if ($action === 'toggle_complete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM calendar_events WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $ev = $stmt->fetch();

        if (!$ev) {
            echo json_encode(['status' => 'error', 'message' => 'برنامه یافت نشد.']);
            exit;
        }

        $new_status = ((int)$ev['is_completed'] === 1) ? 0 : 1;

        if (isset($_POST['test_correct']) || isset($_POST['test_wrong']) || isset($_POST['test_blank'])) {
            $tc = ($_POST['test_correct'] ?? '') !== '' ? max(0, (int)$_POST['test_correct']) : null;
            $tw = ($_POST['test_wrong']   ?? '') !== '' ? max(0, (int)$_POST['test_wrong'])   : null;
            $tb = ($_POST['test_blank']   ?? '') !== '' ? max(0, (int)$_POST['test_blank'])   : null;
            $pct = calc_konkur_percent($tc, $tw, $tb);

            $upd = $pdo->prepare("
                UPDATE calendar_events
                SET is_completed = ?, test_correct = ?, test_wrong = ?, test_blank = ?, test_percent = ?
                WHERE id = ? AND user_id = ?
            ");
            $upd->execute([$new_status, $tc, $tw, $tb, $pct, $id, $user_id]);
        } else {
            $upd = $pdo->prepare("UPDATE calendar_events SET is_completed = ? WHERE id = ? AND user_id = ?");
            $upd->execute([$new_status, $id, $user_id]);
        }

        echo json_encode(['status' => 'success', 'is_completed' => $new_status]);
        exit;
    }

    // ====================================================
    // ۶. انتقال برنامه به فردا یا به یک تاریخ مشخص
    // ====================================================
    if ($action === 'move_to_tomorrow' || $action === 'move_to_date') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, g_date FROM calendar_events WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $ev = $stmt->fetch();

        if (!$ev) {
            echo json_encode(['status' => 'error', 'message' => 'برنامه یافت نشد.']);
            exit;
        }

        if ($action === 'move_to_date' && !empty($_POST['target_date'])) {
            $next_g = trim($_POST['target_date']);
        } else {
            $next_g = date('Y-m-d', strtotime('+1 day', strtotime($ev['g_date'])));
        }

        if (defined('KONKUR_GREGORIAN_DATE') && $next_g > KONKUR_GREGORIAN_DATE) {
            echo json_encode(['status' => 'error', 'message' => 'امکان انتقال برنامه به بعد از ۱۶ بهمن وجود ندارد.']);
            exit;
        }

        $next_j = gdate_str_to_jalali($next_g);
        $upd = $pdo->prepare("UPDATE calendar_events SET g_date = ?, j_date = ?, is_completed = 0, sms_sent = 0 WHERE id = ? AND user_id = ?");
        $upd->execute([$next_g, $next_j, $id, $user_id]);

        echo json_encode(['status' => 'success', 'message' => 'برنامه با موفقیت منتقل شد.', 'new_date' => $next_g]);
        exit;
    }

    // ====================================================
    // ۷. کپی تمام برنامه‌های یک روز به روزی دیگر
    // ====================================================
    if ($action === 'copy_day') {
        $source_date = trim($_POST['source_date'] ?? '');
        $target_date = trim($_POST['target_date'] ?? '');
        $only_study  = (int)($_POST['only_study'] ?? 1);

        if (empty($source_date) || empty($target_date) || $source_date === $target_date) {
            echo json_encode(['status' => 'error', 'message' => 'لطفاً دو روز متفاوت را برای مبدا و مقصد انتخاب کنید.']);
            exit;
        }

        if (defined('KONKUR_GREGORIAN_DATE') && $target_date > KONKUR_GREGORIAN_DATE) {
            echo json_encode(['status' => 'error', 'message' => 'تاریخ مقصد نمی‌تواند بعد از ۱۶ بهمن باشد.']);
            exit;
        }

        $sql = "SELECT * FROM calendar_events WHERE user_id = ? AND g_date = ?";
        if ($only_study === 1) {
            $sql .= " AND is_study = 1";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $source_date]);
        $src_events = $stmt->fetchAll();

        if (empty($src_events)) {
            echo json_encode(['status' => 'error', 'message' => 'در روز مبدا هیچ برنامه‌ای برای کپی کردن یافت نشد.']);
            exit;
        }

        $target_j = gdate_str_to_jalali($target_date);
        $ins = $pdo->prepare("
            INSERT INTO calendar_events
            (user_id, title, description, g_date, j_date, start_time, end_time, color, category_type, category_label, is_study, is_completed, send_sms, sms_sent, pdf_id, pdf_page)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0, ?, ?)
        ");

        foreach ($src_events as $se) {
            $ins->execute([
                $user_id,
                $se['title'],
                $se['description'],
                $target_date,
                $target_j,
                $se['start_time'],
                $se['end_time'],
                $se['color'],
                $se['category_type'] ?? 'specialized',
                $se['category_label'] ?? 'تخصصی',
                (int)($se['is_study'] ?? 1),
                (int)($se['send_sms'] ?? 0),
                $se['pdf_id'],
                (int)($se['pdf_page'] ?? 1)
            ]);
        }

        echo json_encode([
            'status'  => 'success',
            'message' => count($src_events) . ' برنامه با موفقیت به روز مقصد کپی شد.'
        ]);
        exit;
    }

    // ====================================================
    // ۸. ساخت و حذف دسته‌بندی و رنگ سفارشی کاربر
    // ====================================================
    if ($action === 'create_category') {
        $name     = trim($_POST['name'] ?? '');
        $color    = trim($_POST['color'] ?? '#0891b2');
        $is_study = (int)($_POST['is_study'] ?? 1) === 1 ? 1 : 0;

        if (empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'نام نوع برنامه را وارد کنید.']);
            exit;
        }

        $ins = $pdo->prepare("INSERT INTO calendar_categories (user_id, name, color, is_study) VALUES (?, ?, ?, ?)");
        $ins->execute([$user_id, $name, $color, $is_study]);

        echo json_encode([
            'status'   => 'success',
            'category' => [
                'id'       => (int)$pdo->lastInsertId(),
                'name'     => $name,
                'color'    => $color,
                'is_study' => $is_study
            ]
        ]);
        exit;
    }

    if ($action === 'delete_category') {
        $cat_id = (int)($_POST['category_id'] ?? 0);
        $del = $pdo->prepare("DELETE FROM calendar_categories WHERE id = ? AND user_id = ?");
        $del->execute([$cat_id, $user_id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // ====================================================
    // ۹. حذف برنامه از تقویم
    // ====================================================
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $del = $pdo->prepare("DELETE FROM calendar_events WHERE id = ? AND user_id = ?");
        $del->execute([$id, $user_id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // ====================================================
    // ۱۰. بروزرسانی شماره موبایل کاربر (بدون ذکر نام سرویس‌دهنده پیامک)
    // ====================================================
    if ($action === 'update_phone') {
        $phone = trim($_POST['phone'] ?? '');
        if (!preg_match('/^09[0-9]{9}$/', $phone)) {
            echo json_encode(['status' => 'error', 'message' => 'لطفاً یک شماره موبایل ۱۱ رقمی معتبر (با پیش‌شماره 09) وارد کنید.']);
            exit;
        }

        $upd = $pdo->prepare("UPDATE users SET phone = ? WHERE id = ?");
        $upd->execute([$phone, $user_id]);
        $_SESSION['phone'] = $phone;

        echo json_encode([
            'status'  => 'success',
            'message' => 'شماره موبایل شما برای دریافت پیامک‌های یادآوری سامانه ذخیره شد.'
        ]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'درخواست نامعتبر است.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'خطای سرور: ' . $e->getMessage()]);
}