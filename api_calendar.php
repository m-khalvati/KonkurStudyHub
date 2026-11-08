<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'لطفاً ابتدا وارد حساب کاربری شوید.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

try {
    // ۱. دریافت لیست رویدادها در بازه زمانی مشخص
    if ($action === 'list') {
        $start = $_GET['start'] ?? date('Y-m-d');
        $end   = $_GET['end'] ?? date('Y-m-d');

        $stmt = $pdo->prepare("
            SELECT e.*, p.title AS pdf_title 
            FROM calendar_events e
            LEFT JOIN pdfs p ON e.pdf_id = p.id AND p.user_id = e.user_id
            WHERE e.user_id = ? AND e.g_date BETWEEN ? AND ?
            ORDER BY e.g_date ASC, e.start_time ASC
        ");
        $stmt->execute([$user_id, $start, $end]);
        $events = $stmt->fetchAll();

        // محاسبه آمار کلی پیشرفت کاربر تا کنکور
        $statStmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_tasks,
                SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END) AS completed_tasks
            FROM calendar_events 
            WHERE user_id = ?
        ");
        $statStmt->execute([$user_id]);
        $stats = $statStmt->fetch();

        echo json_encode([
            'status' => 'success',
            'events' => $events,
            'stats'  => [
                'total'     => (int)($stats['total_tasks'] ?? 0),
                'completed' => (int)($stats['completed_tasks'] ?? 0)
            ]
        ]);
        exit;
    }

    // ۲. ثبت یا ویرایش رویداد در تقویم
    if ($action === 'save') {
        $id          = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $g_date      = $_POST['g_date'] ?? '';
        $start_time  = $_POST['start_time'] ?? '';
        $end_time    = $_POST['end_time'] ?? '';
        $color       = $_POST['color'] ?? '#4f46e5';
        $pdf_id      = !empty($_POST['pdf_id']) ? (int)$_POST['pdf_id'] : null;
        $pdf_page    = !empty($_POST['pdf_page']) ? max(1, (int)$_POST['pdf_page']) : 1;

        if (empty($title) || empty($g_date) || empty($start_time) || empty($end_time)) {
            echo json_encode(['status' => 'error', 'message' => 'عنوان، تاریخ و ساعت شروع و پایان الزامی است.']);
            exit;
        }

        if (strtotime($end_time) <= strtotime($start_time)) {
            echo json_encode(['status' => 'error', 'message' => 'ساعت پایان باید بعد از ساعت شروع باشد.']);
            exit;
        }

        // جلوگیری از ثبت برنامه بعد از ۱۶ بهمن (روز کنکور)
        if ($g_date > KONKUR_GREGORIAN_DATE) {
            echo json_encode(['status' => 'error', 'message' => 'تقویم در تاریخ ۱۶ بهمن (روز کنکور) بسته می‌شود و امکان ثبت برنامه پس از آن وجود ندارد.']);
            exit;
        }

        // تبدیل تاریخ میلادی به شمسی برای ذخیره در دیتابیس
        $parts = explode('-', $g_date);
        $j_date = format_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);

        if ($id) {
            // ویرایش رویداد موجود (فقط متعلق به همین کاربر)
            $stmt = $pdo->prepare("
                UPDATE calendar_events 
                SET title = ?, description = ?, g_date = ?, j_date = ?, start_time = ?, end_time = ?, color = ?, pdf_id = ?, pdf_page = ?
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$title, $description, $g_date, $j_date, $start_time, $end_time, $color, $pdf_id, $pdf_page, $id, $user_id]);
        } else {
            // ثبت رویداد جدید
            $stmt = $pdo->prepare("
                INSERT INTO calendar_events (user_id, title, description, g_date, j_date, start_time, end_time, color, pdf_id, pdf_page)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$user_id, $title, $description, $g_date, $j_date, $start_time, $end_time, $color, $pdf_id, $pdf_page]);
        }

        echo json_encode(['status' => 'success', 'message' => 'برنامه با موفقیت ذخیره شد.']);
        exit;
    }

    // ۳. تغییر وضعیت انجام شدن برنامه (تیک زدن)
    if ($action === 'toggle_complete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE calendar_events SET is_completed = NOT is_completed WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // ۴. حذف رویداد
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