<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'لطفاً ابتدا وارد حساب کاربری شوید.']);
    exit;
}

$user_id  = (int)$_SESSION['user_id'];
$action   = $_GET['action'] ?? ($_POST['action'] ?? '');
$sub_info = get_user_subscription_info($pdo, $user_id);

// ۵ رنگ اصلی رزرو شده سیستم
const RESERVED_MAIN_COLORS = ['#4f46e5', '#059669', '#d97706', '#e11d48', '#7c3aed'];

// ====================================================
// ۰. ارتقای خودکار دیتابیس تقویم
// ====================================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `calendar_categories` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `name` varchar(60) NOT NULL,
          `color` varchar(20) NOT NULL,
          `is_study` tinyint(1) DEFAULT 1 COMMENT '1=درسی و مطالعه, 0=کارهای متفرقه',
          `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_user_cat` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
    ");

    $eCols = $pdo->query("SHOW COLUMNS FROM `calendar_events`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('category_label', $eCols)) {
        $pdo->exec("ALTER TABLE `calendar_events` ADD COLUMN `category_label` varchar(60) DEFAULT 'تخصصی' AFTER `category_type`");
    }
    if (!in_array('is_study', $eCols)) {
        $pdo->exec("ALTER TABLE `calendar_events` ADD COLUMN `is_study` tinyint(1) DEFAULT 1 COMMENT '1=درسی, 0=متفرقه' AFTER `category_label`");
        $pdo->exec("UPDATE `calendar_events` SET `is_study` = 0, `category_label` = 'متفرقه' WHERE `category_type` = 'misc' OR `color` = '#7c3aed'");
    }
} catch (Exception $ignore) {}

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

function resolve_event_category_meta($pdo, $user_id, $color) {
    $defaults = [
        '#4f46e5' => ['type' => 'specialized', 'label' => 'تخصصی',  'is_study' => 1],
        '#059669' => ['type' => 'review',      'label' => 'مرور',   'is_study' => 1],
        '#d97706' => ['type' => 'test',        'label' => 'تست‌زنی', 'is_study' => 1],
        '#e11d48' => ['type' => 'exam',        'label' => 'آزمون',  'is_study' => 1],
        '#7c3aed' => ['type' => 'misc',        'label' => 'متفرقه', 'is_study' => 0],
    ];

    $norm_color = strtolower(trim($color));
    if (isset($defaults[$norm_color])) {
        return $defaults[$norm_color];
    }

    $stmt = $pdo->prepare("SELECT id, name, is_study FROM calendar_categories WHERE user_id = ? AND LOWER(color) = ? LIMIT 1");
    $stmt->execute([$user_id, $norm_color]);
    $custom = $stmt->fetch();

    if ($custom) {
        return [
            'type'     => 'custom_' . $custom['id'],
            'label'    => $custom['name'],
            'is_study' => (int)$custom['is_study']
        ];
    }

    return ['type' => 'custom', 'label' => 'سفارشی', 'is_study' => 1];
}

try {
    // ====================================================
    // ۱. دریافت لیست رویدادها + دسته‌های سفارشی + آمار + وضعیت اشتراک
    // ====================================================
    if ($action === 'list') {
        $start = $_GET['start'] ?? date('Y-m-d');
        $end   = $_GET['end'] ?? date('Y-m-d');

        $stmt = $pdo->prepare("
            SELECT e.*, p.title AS pdf_title 
            FROM calendar_events e
            LEFT JOIN pdfs p ON e.pdf_id = p.id AND p.user_id = e.user_id
            WHERE e.user_id = ? AND e.g_date BETWEEN ? AND ?
            ORDER BY e.g_date ASC, e.start_time ASC, e.end_time DESC
        ");
        $stmt->execute([$user_id, $start, $end]);
        $events = $stmt->fetchAll();

        $catStmt = $pdo->prepare("SELECT id, name, color, is_study FROM calendar_categories WHERE user_id = ? ORDER BY id ASC");
        $catStmt->execute([$user_id]);
        $custom_categories = $catStmt->fetchAll();

        $study_planned_mins   = 0;
        $study_completed_mins = 0;
        $misc_planned_mins    = 0;
        $misc_completed_mins  = 0;
        $percent_sum          = 0;
        $percent_count        = 0;

        foreach ($events as $ev) {
            $diff_mins = max(0, (int)round((strtotime($ev['end_time']) - strtotime($ev['start_time'])) / 60));
            $is_study_task = isset($ev['is_study']) ? ((int)$ev['is_study'] === 1) : ($ev['category_type'] !== 'misc' && $ev['color'] !== '#7c3aed');

            if ($is_study_task) {
                $study_planned_mins += $diff_mins;
                if ((int)$ev['is_completed'] === 1) {
                    $study_completed_mins += $diff_mins;
                }
            } else {
                $misc_planned_mins += $diff_mins;
                if ((int)$ev['is_completed'] === 1) {
                    $misc_completed_mins += $diff_mins;
                }
            }

            if ($ev['test_percent'] !== null && $ev['test_percent'] !== '') {
                $percent_sum += (float)$ev['test_percent'];
                $percent_count++;
            }
        }

        $statStmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_tasks,
                SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END) AS completed_tasks,
                SUM(CASE WHEN is_study = 1 THEN 1 ELSE 0 END) AS study_total_tasks,
                SUM(CASE WHEN is_study = 1 AND is_completed = 1 THEN 1 ELSE 0 END) AS study_completed_tasks
            FROM calendar_events 
            WHERE user_id = ?
        ");
        $statStmt->execute([$user_id]);
        $overall = $statStmt->fetch();

        echo json_encode([
            'status'            => 'success',
            'events'            => $events,
            'custom_categories' => $custom_categories,
            'user_phone'        => $sub_info['phone'],
            'subscription'      => $sub_info,
            'stats'             => [
                'total'                 => (int)($overall['total_tasks'] ?? 0),
                'completed'             => (int)($overall['completed_tasks'] ?? 0),
                'study_total'           => (int)($overall['study_total_tasks'] ?? 0),
                'study_completed'       => (int)($overall['study_completed_tasks'] ?? 0),
                'planned_hours'         => round($study_planned_mins / 60, 1),
                'completed_hours'       => round($study_completed_mins / 60, 1),
                'misc_planned_hours'    => round($misc_planned_mins / 60, 1),
                'misc_completed_hours'  => round($misc_completed_mins / 60, 1),
                'avg_test_percent'      => $percent_count > 0 ? round($percent_sum / $percent_count, 1) : null
            ]
        ]);
        exit;
    }

    // ====================================================
    // ۲. ساخت نوع و رنگ برنامه سفارشی توسط کاربر (رایگان برای همه)
    // ====================================================
    if ($action === 'create_category') {
        $name     = trim($_POST['name'] ?? '');
        $color    = strtolower(trim($_POST['color'] ?? '#0891b2'));
        $is_study = isset($_POST['is_study']) ? (int)$_POST['is_study'] : 1;
        $is_study = ($is_study === 0) ? 0 : 1;

        if (empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'لطفاً عنوان نوع برنامه را وارد کنید.']);
            exit;
        }

        if (in_array($color, RESERVED_MAIN_COLORS, true)) {
            echo json_encode(['status' => 'error', 'message' => 'این رنگ جزو ۵ رنگ اصلی سیستم است. لطفاً یکی از رنگ‌های پالت سفارشی را انتخاب کنید.']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO calendar_categories (user_id, name, color, is_study) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $name, $color, $is_study]);

        echo json_encode([
            'status'   => 'success',
            'message'  => 'نوع برنامه جدید اضافه شد.',
            'category' => [
                'id'       => (int)$pdo->lastInsertId(),
                'name'     => $name,
                'color'    => $color,
                'is_study' => $is_study
            ]
        ]);
        exit;
    }

    // ====================================================
    // ۳. حذف نوع برنامه سفارشی کاربر
    // ====================================================
    if ($action === 'delete_category') {
        $cat_id = (int)($_POST['category_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM calendar_categories WHERE id = ? AND user_id = ?");
        $stmt->execute([$cat_id, $user_id]);

        echo json_encode(['status' => 'success', 'message' => 'نوع برنامه سفارشی حذف شد.']);
        exit;
    }

    // ====================================================
    // ۴. ثبت یا ویرایش رویداد (همراه با قفل پیامک برای کاربران عادی)
    // ====================================================
    if ($action === 'save') {
        $id          = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $g_date      = $_POST['g_date'] ?? '';
        $start_time  = $_POST['start_time'] ?? '';
        $end_time    = $_POST['end_time'] ?? '';
        $color       = strtolower(trim($_POST['color'] ?? '#4f46e5'));
        $req_sms     = isset($_POST['send_sms']) ? (int)$_POST['send_sms'] : 0;
        // اگر کاربر اشتراک ویژه ندارد، گزینه پیامک در سمت سرور هم غیرفعال می‌شود
        $send_sms    = ($sub_info['is_vip'] && $req_sms === 1) ? 1 : 0;
        $pdf_id      = !empty($_POST['pdf_id']) ? (int)$_POST['pdf_id'] : null;
        $pdf_page    = !empty($_POST['pdf_page']) ? max(1, (int)$_POST['pdf_page']) : 1;
        $repeat_mode = $_POST['repeat_mode'] ?? 'none';

        $cat_meta       = resolve_event_category_meta($pdo, $user_id, $color);
        $category_type  = $cat_meta['type'];
        $category_label = $cat_meta['label'];
        $is_study       = $cat_meta['is_study'];

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
            $parts  = explode('-', $g_date);
            $j_date = format_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);

            $stmt = $pdo->prepare("
                UPDATE calendar_events 
                SET title = ?, description = ?, g_date = ?, j_date = ?, start_time = ?, end_time = ?, 
                    color = ?, category_type = ?, category_label = ?, is_study = ?, send_sms = ?, 
                    pdf_id = ?, pdf_page = ?, test_correct = ?, test_wrong = ?, test_blank = ?, test_percent = ?
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([
                $title, $description, $g_date, $j_date, $start_time, $end_time,
                $color, $category_type, $category_label, $is_study, $send_sms,
                $pdf_id, $pdf_page, $test_correct, $test_wrong, $test_blank, $test_percent,
                $id, $user_id
            ]);

            echo json_encode(['status' => 'success', 'message' => 'برنامه با موفقیت بروزرسانی شد.']);
            exit;
        } else {
            $dates_to_insert = [$g_date];

            if ($repeat_mode === 'week_daily' || $repeat_mode === 'week_alternate') {
                $base_ts = strtotime($g_date);
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
                    color, category_type, category_label, is_study, send_sms, pdf_id, pdf_page,
                    test_correct, test_wrong, test_blank, test_percent
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($dates_to_insert as $target_g) {
                $parts  = explode('-', $target_g);
                $j_date = format_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);

                $insertStmt->execute([
                    $user_id, $title, $description, $target_g, $j_date, $start_time, $end_time,
                    $color, $category_type, $category_label, $is_study, $send_sms, $pdf_id, $pdf_page,
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
    // ۵. انتقال سریع یک برنامه به روز بعد
    // ====================================================
    if ($action === 'move_to_tomorrow') {
        $id = (int)($_POST['id'] ?? 0);

        $stmt = $pdo->prepare("SELECT g_date FROM calendar_events WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        $ev = $stmt->fetch();

        if (!$ev) {
            echo json_encode(['status' => 'error', 'message' => 'برنامه مورد نظر یافت نشد.']);
            exit;
        }

        $next_g = date('Y-m-d', strtotime($ev['g_date'] . ' +1 day'));
        if ($next_g > KONKUR_GREGORIAN_DATE) {
            echo json_encode(['status' => 'error', 'message' => 'امکان انتقال به بعد از ۱۶ بهمن (روز کنکور) وجود ندارد.']);
            exit;
        }

        $parts  = explode('-', $next_g);
        $next_j = format_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);

        $upd = $pdo->prepare("UPDATE calendar_events SET g_date = ?, j_date = ?, sms_sent = 0 WHERE id = ? AND user_id = ?");
        $upd->execute([$next_g, $next_j, $id, $user_id]);

        echo json_encode(['status' => 'success', 'message' => 'برنامه با موفقیت به فردا منتقل شد.']);
        exit;
    }

    // ====================================================
    // ۶. کپی کردن تمام برنامه‌های یک روز به روزی دیگر
    // ====================================================
    if ($action === 'copy_day') {
        $source_date = $_POST['source_date'] ?? '';
        $target_date = $_POST['target_date'] ?? '';
        $only_study  = isset($_POST['only_study']) ? (int)$_POST['only_study'] : 0;

        if (empty($source_date) || empty($target_date)) {
            echo json_encode(['status' => 'error', 'message' => 'لطفاً تاریخ مبدا و مقصد را انتخاب کنید.']);
            exit;
        }

        if ($source_date === $target_date) {
            echo json_encode(['status' => 'error', 'message' => 'تاریخ مبدا و مقصد نمی‌توانند یکسان باشند.']);
            exit;
        }

        if ($target_date > KONKUR_GREGORIAN_DATE) {
            echo json_encode(['status' => 'error', 'message' => 'تاریخ مقصد نمی‌تواند بعد از ۱۶ بهمن باشد.']);
            exit;
        }

        $sql = "SELECT * FROM calendar_events WHERE user_id = ? AND g_date = ?";
        if ($only_study === 1) {
            $sql .= " AND is_study = 1";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $source_date]);
        $source_events = $stmt->fetchAll();

        if (empty($source_events)) {
            echo json_encode(['status' => 'error', 'message' => 'در روز مبدا هیچ برنامه‌ای برای کپی کردن یافت نشد.']);
            exit;
        }

        $parts    = explode('-', $target_date);
        $target_j = format_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);

        $ins = $pdo->prepare("
            INSERT INTO calendar_events (
                user_id, title, description, g_date, j_date, start_time, end_time,
                color, category_type, category_label, is_study, send_sms, pdf_id, pdf_page
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($source_events as $sev) {
            $copied_sms = ($sub_info['is_vip'] && (int)($sev['send_sms'] ?? 1) === 1) ? 1 : 0;
            $ins->execute([
                $user_id, $sev['title'], $sev['description'], $target_date, $target_j,
                $sev['start_time'], $sev['end_time'], $sev['color'], $sev['category_type'],
                $sev['category_label'] ?? 'تخصصی', $sev['is_study'] ?? 1, $copied_sms,
                $sev['pdf_id'], $sev['pdf_page']
            ]);
        }

        $cnt = count($source_events);
        echo json_encode(['status' => 'success', 'message' => "{$cnt} برنامه با موفقیت به روز مقصد کپی شد."]);
        exit;
    }

    // ====================================================
    // ۷. تغییر وضعیت انجام برنامه
    // ====================================================
    if ($action === 'toggle_complete') {
        $id = (int)($_POST['id'] ?? 0);

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
    // ۸. بروزرسانی شماره موبایل کاربر برای sms.ir
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

        echo json_encode(['status' => 'success', 'message' => 'شماره موبایل شما ثبت شد.']);
        exit;
    }

    // ====================================================
    // ۹. حذف رویداد از تقویم
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