<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'لطفاً ابتدا وارد حساب کاربری شوید.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$action  = $_GET['action'] ?? ($_POST['action'] ?? '');

// تابع کمکی محاسبه درصد کنکوری با نمره منفی (هر ۳ غلط یک صحیح را خنثی می‌کند)
function calculate_konkur_percent($correct, $wrong, $blank) {
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

    $raw_percent = ((($c * 3) - $w) / ($total * 3)) * 100;
    return round(max(-33.33, min(100, $raw_percent)), 2);
}

// نگاشت رنگ به نوع دسته‌بندی برنامه
function resolve_category_type($color, $explicit_type = '') {
    if (!empty($explicit_type)) {
        return $explicit_type;
    }
    $map = [
        '#4f46e5' => 'specialized', // تخصصی
        '#059669' => 'review',      // مرور
        '#d97706' => 'test',        // تست‌زنی
        '#e11d48' => 'exam',        // آزمون
        '#7c3aed' => 'misc'         // کارهای متفرقه
    ];
    return $map[$color] ?? 'specialized';
}

try {
    // ====================================================
    // ۱. دریافت لیست رویدادها + آمار عملکرد هفتگی و کلی
    // ====================================================
    if ($action === 'list') {
        $start = $_GET['start'] ?? date('Y-m-d');
        $end   = $_GET['end'] ?? date('Y-m-d');

        // الف) رویدادهای بازه زمانی انتخاب‌شده (هفتگی یا روزانه)
        $stmt = $pdo->prepare("
            SELECT e.*, p.title AS pdf_title 
            FROM calendar_events e
            LEFT JOIN pdfs p ON e.pdf_id = p.id AND p.user_id = e.user_id
            WHERE e.user_id = ? AND e.g_date BETWEEN ? AND ?
            ORDER BY e.g_date ASC, e.start_time ASC
        ");
        $stmt->execute([$user_id, $start, $end]);
        $events = $stmt->fetchAll();

        // ب) محاسبه آمار دقیق بازه فعلی (ساعات برنامه‌ریزی، ساعات مطالعه واقعی و میانگین درصد تست)
        $planned_minutes   = 0;
        $completed_minutes = 0;
        $percent_sum       = 0;
        $percent_count     = 0;

        foreach ($events as $ev) {
            $diff_mins = max(0, (int)round((strtotime($ev['end_time']) - strtotime($ev['start_time'])) / 60));
            $planned_minutes += $diff_mins;

            if ((int)$ev['is_completed'] === 1) {
                $completed_minutes += $diff_mins;
            }

            if ($ev['test_percent'] !== null && $ev['test_percent'] !== '') {
                $percent_sum += (float)$ev['test_percent'];
                $percent_count++;
            }
        }

        // ج) آمار کل پیشرفت تا کنکور
        $statStmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_tasks,
                SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END) AS completed_tasks
            FROM calendar_events 
            WHERE user_id = ?
        ");
        $statStmt->execute([$user_id]);
        $overall = $statStmt->fetch();

        // د) دریافت شماره موبایل فعلی کاربر برای نمایش در تنظیمات پیامک
        $uStmt = $pdo->prepare("SELECT phone FROM users WHERE id = ?");
        $uStmt->execute([$user_id]);
        $user_row = $uStmt->fetch();

        echo json_encode([
            'status'      => 'success',
            'events'      => $events,
            'user_phone'  => $user_row['phone'] ?? '',
            'stats'       => [
                'total'             => (int)($overall['total_tasks'] ?? 0),
                'completed'         => (int)($overall['completed_tasks'] ?? 0),
                'planned_hours'     => round($planned_minutes / 60, 1),
                'completed_hours'   => round($completed_minutes / 60, 1),
                'avg_test_percent'  => $percent_count > 0 ? round($percent_sum / $percent_count, 1) : null
            ]
        ]);
        exit;
    }

    // ====================================================
    // ۲. ثبت یا ویرایش رویداد (به همراه تکرار روتین و درصد تست)
    // ====================================================
    if ($action === 'save') {
        $id            = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $title         = trim($_POST['title'] ?? '');
        $description   = trim($_POST['description'] ?? '');
        $g_date        = $_POST['g_date'] ?? '';
        $start_time    = $_POST['start_time'] ?? '';
        $end_time      = $_POST['end_time'] ?? '';
        $color         = $_POST['color'] ?? '#4f46e5';
        $category_type = resolve_category_type($color, $_POST['category_type'] ?? '');
        $send_sms      = isset($_POST['send_sms']) ? (int)$_POST['send_sms'] : 0;
        $pdf_id        = !empty($_POST['pdf_id']) ? (int)$_POST['pdf_id'] : null;
        $pdf_page      = !empty($_POST['pdf_page']) ? max(1, (int)$_POST['pdf_page']) : 1;
        $repeat_mode   = $_POST['repeat_mode'] ?? 'none'; // 'none', 'week_daily', 'week_even_odd'

        // دریافت آمار تست (در صورت وارد شدن)
        $has_test_input = (isset($_POST['test_correct']) && $_POST['test_correct'] !== '') ||
                          (isset($_POST['test_wrong']) && $_POST['test_wrong'] !== '') ||
                          (isset($_POST['test_blank']) && $_POST['test_blank'] !== '');

        $test_correct = $has_test_input ? max(0, (int)($_POST['test_correct'] ?? 0)) : null;
        $test_wrong   = $has_test_input ? max(0, (int)($_POST['test_wrong'] ?? 0)) : null;
        $test_blank   = $has_test_input ? max(0, (int)($_POST['test_blank'] ?? 0)) : null;
        $test_percent = calculate_konkur_percent($test_correct, $test_wrong, $test_blank);

        if (empty($title) || empty($g_date) || empty($start_time) || empty($end_time)) {
            echo json_encode(['status' => 'error', 'message' => 'عنوان، تاریخ و ساعت شروع و پایان الزامی است.']);
            exit;
        }

        if (strtotime($end_time) <= strtotime($start_time)) {
            echo json_encode(['status' => 'error', 'message' => 'ساعت پایان باید بعد از ساعت شروع باشد.']);
            exit;
        }

        if ($g_date > KONKUR_GREGORIAN_DATE) {
            echo json_encode(['status' => 'error', 'message' => 'تقویم در تاریخ ۱۶ بهمن (روز کنکور) بسته می‌شود و امکان ثبت برنامه پس از آن وجود ندارد.']);
            exit;
        }

        if ($id) {
            // ویرایش یک رویداد مشخص
            $parts  = explode('-', $g_date);
            $j_date = format_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);

            $stmt = $pdo->prepare("
                UPDATE calendar_events 
                SET title = ?, description = ?, g_date = ?, j_date = ?, start_time = ?, end_time = ?, 
                    color = ?, category_type = ?, send_sms = ?, pdf_id = ?, pdf_page = ?,
                    test_correct = ?, test_wrong = ?, test_blank = ?, test_percent = ?
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([
                $title, $description, $g_date, $j_date, $start_time, $end_time,
                $color, $category_type, $send_sms, $pdf_id, $pdf_page,
                $test_correct, $test_wrong, $test_blank, $test_percent,
                $id, $user_id
            ]);

            echo json_encode(['status' => 'success', 'message' => 'برنامه با موفقیت بروزرسانی شد.']);
            exit;
        } else {
            // ساخت لیست تاریخ‌هایی که برنامه باید در آن‌ها درج شود (پشتیبانی از تکرار روتین هفتگی)
            $dates_to_insert = [$g_date];

            if ($repeat_mode === 'week_daily' || $repeat_mode === 'week_alternate') {
                $base_ts = strtotime($g_date);
                // محاسبه اندیس روز هفته ایرانی (شنبه = 0 تا جمعه = 6)
                $iranian_day_idx = ((int)date('w', $base_ts) + 1) % 7;
                $days_remaining_in_week = 6 - $iranian_day_idx;
                $step = ($repeat_mode === 'week_alternate') ? 2 : 1;

                for ($d = $step; $d <= $days_remaining_in_week; $d += $step) {
                    $next_g = date('Y-m-d', strtotime("+{$d} days", $base_ts));
                    if ($next_g <= KONKUR_GREGORIAN_DATE) {
                        $dates_to_insert[] = $next_g;
                    }
                }
            }

            $insertStmt = $pdo->prepare("
                INSERT INTO calendar_events (
                    user_id, title, description, g_date, j_date, start_time, end_time,
                    color, category_type, send_sms, pdf_id, pdf_page,
                    test_correct, test_wrong, test_blank, test_percent
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($dates_to_insert as $target_g) {
                $parts  = explode('-', $target_g);
                $j_date = format_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);

                $insertStmt->execute([
                    $user_id, $title, $description, $target_g, $j_date, $start_time, $end_time,
                    $color, $category_type, $send_sms, $pdf_id, $pdf_page,
                    $test_correct, $test_wrong, $test_blank, $test_percent
                ]);
            }

            $count = count($dates_to_insert);
            $msg = $count > 1 ? "برنامه در {$count} روز از هفته ثبت شد." : "برنامه با موفقیت ذخیره شد.";
            echo json_encode(['status' => 'success', 'message' => $msg]);
            exit;
        }
    }

    // ====================================================
    // ۳. تغییر وضعیت انجام برنامه (به همراه ثبت اختیاری درصد تست)
    // ====================================================
    if ($action === 'toggle_complete') {
        $id = (int)($_POST['id'] ?? 0);

        // اگر کاربر هنگام تیک زدن، آمار تست هم ارسال کرده باشد، همزمان ذخیره شود
        if (isset($_POST['test_correct']) && $_POST['test_correct'] !== '') {
            $tc = max(0, (int)$_POST['test_correct']);
            $tw = max(0, (int)($_POST['test_wrong'] ?? 0));
            $tb = max(0, (int)($_POST['test_blank'] ?? 0));
            $tp = calculate_konkur_percent($tc, $tw, $tb);

            $stmt = $pdo->prepare("
                UPDATE calendar_events 
                SET is_completed = 1, test_correct = ?, test_wrong = ?, test_blank = ?, test_percent = ?
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$tc, $tw, $tb, $tp, $id, $user_id]);
        } else {
            // تغییر ساده وضعیت انجام (تیک زدن / برداشتن تیک)
            $stmt = $pdo->prepare("
                UPDATE calendar_events 
                SET is_completed = NOT is_completed 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$id, $user_id]);
        }

        echo json_encode(['status' => 'success']);
        exit;
    }

    // ====================================================
    // ۴. بروزرسانی شماره موبایل کاربر برای دریافت پیامک sms.ir
    // ====================================================
    if ($action === 'update_phone') {
        $phone = trim($_POST['phone'] ?? '');
        if (!preg_match('/^09[0-9]{9}$/', $phone)) {
            echo json_encode(['status' => 'error', 'message' => 'فرمت شماره موبایل صحیح نیست (مثال: 09123456789).']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE users SET phone = ? WHERE id = ?");
        $stmt->execute([$phone, $user_id]);
        $_SESSION['phone'] = $phone;

        echo json_encode(['status' => 'success', 'message' => 'شماره موبایل شما برای دریافت پیامک‌های یادآوری ثبت شد.']);
        exit;
    }

    // ====================================================
    // ۵. حذف رویداد از تقویم
    // ====================================================
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM calendar_events WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'عملیات نامعتبر است.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'خطای سرور: ' . $e->getMessage()]);
}