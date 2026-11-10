<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

$today_g  = date('Y-m-d');
$now_time = date('H:i:s');
$now_full = date('Y-m-d H:i:s');

try {
    // فقط برنامه‌های عقب‌افتاده کاربرانی که اشتراک ویژه (VIP) فعال دارند انتخاب می‌شوند
    $stmt = $pdo->prepare("
        SELECT e.id, e.title, e.end_time, u.full_name, u.phone
        FROM calendar_events e
        INNER JOIN users u ON e.user_id = u.id
        WHERE e.g_date = ?
          AND e.end_time < ?
          AND e.is_completed = 0
          AND e.send_sms = 1
          AND e.sms_sent = 0
          AND u.phone IS NOT NULL
          AND u.phone != ''
          AND u.vip_expires_at IS NOT NULL
          AND u.vip_expires_at > ?
        LIMIT 20
    ");
    $stmt->execute([$today_g, $now_time, $now_full]);
    $overdue_tasks = $stmt->fetchAll();

    $sent_count = 0;

    foreach ($overdue_tasks as $task) {
        $res = send_smsir_verify($task['phone'], $task['full_name'], $task['title']);

        if ($res['status']) {
            $upd = $pdo->prepare("UPDATE calendar_events SET sms_sent = 1 WHERE id = ?");
            $upd->execute([$task['id']]);
            $sent_count++;
        }
    }

    echo json_encode([
        'status'        => 'success',
        'checked_at'    => $now_time,
        'overdue_found' => count($overdue_tasks),
        'sms_sent'      => $sent_count
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}