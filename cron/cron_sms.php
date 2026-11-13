<?php
// بارگذاری فایل تنظیمات از پوشه config/
$root_path = dirname(__DIR__);
if (is_file($root_path . '/config/config.php')) {
    require_once $root_path . '/config/config.php';
} elseif (is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    http_response_code(500);
    die('Config file not found.');
}

@set_time_limit(120);

// تولید کلید مخفی یکتا برای کرون‌جاب اینترنتی (URL Cron)
$cron_secret_key = defined('CRON_SECRET_KEY')
    ? CRON_SECRET_KEY
    : substr(hash('sha256', DB_NAME . ':' . DB_USER . ':konkur_sms_cron_v44'), 0, 24);

$is_cli = (php_sapi_name() === 'cli' || defined('STDIN'));
$provided_key = $_GET['key'] ?? ($_SERVER['HTTP_X_CRON_KEY'] ?? '');
$is_logged_in_admin = (isset($_SESSION['user_id']) && ((int)$_SESSION['user_id'] === 1 || !empty($_SESSION['is_admin'])));

// اگر ادمین بدون کلید و از طریق مرورگر این صفحه را باز کرد، دستور آماده سی‌پنل را به او نشان بده
if (!$is_cli && empty($provided_key) && $is_logged_in_admin) {
    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'yourdomain.com';
    $cron_url = "{$scheme}://{$host}/cron/cron_sms.php?key={$cron_secret_key}";
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>راهنمای تنظیم کرون‌جاب پیامکی در سی‌پنل</title>
        <style>
            body { font-family: Tahoma, sans-serif; background: #0f172a; color: #f8fafc; padding: 30px; direction: rtl; }
            .box { max-width: 720px; margin: 20px auto; background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 24px; }
            h2 { color: #fbbf24; margin-top: 0; font-size: 17px; }
            code { display: block; background: #020617; color: #34d399; padding: 14px; border-radius: 10px; font-family: monospace; direction: ltr; text-align: left; margin: 12px 0; word-break: break-all; border: 1px solid #1e293b; }
            .btn { display: inline-block; background: #4f46e5; color: #fff; text-decoration: none; padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: bold; margin-top: 10px; }
        </style>
    </head>
    <body>
        <div class="box">
            <h2>🛡️ دستور امن کرون‌جاب (URL Cron) برای سی‌پنل شما</h2>
            <p style="font-size:13px;line-height:1.9;color:#cbd5e1;">
                برای اینکه افراد متفرقه نتوانند لینک کرون‌جاب را صدا بزنند، لینک شما به یک کلید امنیتی مجهز شده است. دستور زیر را کپی کرده و در بخش <b>Cron Jobs</b> سی‌پنل (روی هر ۱۵ دقیقه: <code>*/15 * * * *</code>) قرار دهید:
            </p>
            <code>curl -s "<?php echo htmlspecialchars($cron_url); ?>" >/dev/null 2>&1</code>
            <p style="font-size:12px;color:#94a3b8;margin-top:14px;">یا اگر فقط آدرس اینترنتی را می‌خواهید:</p>
            <code><?php echo htmlspecialchars($cron_url); ?></code>
            <a href="<?php echo htmlspecialchars($cron_url); ?>" class="btn">⚡ تست و اجرای دستی کرون‌جاب همین الان</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// بررسی کلید امنیتی برای درخواست‌های وب (URL)
if (!$is_cli && !hash_equals($cron_secret_key, (string)$provided_key)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => 'error',
        'message' => 'Forbidden: Invalid or missing cron secret key.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $today_g  = date('Y-m-d');
    $now_time = date('H:i:s');

    // دریافت برنامه‌های درسی انجام‌نشده امروز که زمان پایان آن‌ها گذشته، تیک پیامک دارند (send_sms = 1)
    // و هنوز پیامکی برایشان ارسال نشده است (sms_sent = 0) و کاربر دارای اشتراک ویژه فعال است
    $stmt = $pdo->prepare("
        SELECT e.id, e.title, e.user_id, e.g_date, e.end_time,
               u.full_name, u.phone, u.vip_expires_at, u.is_admin
        FROM calendar_events e
        INNER JOIN users u ON u.id = e.user_id
        WHERE e.g_date = ?
          AND e.end_time <= ?
          AND e.is_completed = 0
          AND e.send_sms = 1
          AND e.sms_sent = 0
        ORDER BY e.end_time ASC
        LIMIT 25
    ");
    $stmt->execute([$today_g, $now_time]);
    $overdue_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sent_count    = 0;
    $skipped_count = 0;

    foreach ($overdue_tasks as $task) {
        $event_id = (int)$task['id'];
        $phone    = trim($task['phone'] ?? '');
        $is_vip   = ((int)($task['is_admin'] ?? 0) === 1)
                 || (!empty($task['vip_expires_at']) && strtotime($task['vip_expires_at']) > time());

        // اگر کاربر شماره موبایل معتبر ندارد یا اشتراک ویژه‌اش منقضی شده، علامت زده شود تا دوباره بررسی نگردد
        if (!$is_vip || !preg_match('/^09[0-9]{9}$/', $phone)) {
            $pdo->prepare("UPDATE calendar_events SET sms_sent = 1 WHERE id = ?")->execute([$event_id]);
            $skipped_count++;
            continue;
        }

        // قفل کردن ردیف قبل از ارسال برای جلوگیری از ارسال پیامک تکراری
        $upd = $pdo->prepare("UPDATE calendar_events SET sms_sent = 1 WHERE id = ? AND sms_sent = 0");
        $upd->execute([$event_id]);

        if ($upd->rowCount() === 1 && function_exists('send_smsir_verify')) {
            $res = send_smsir_verify($phone, $task['full_name'], $task['title']);
            if (!empty($res['status'])) {
                $sent_count++;
            }
        }
    }

    echo json_encode([
        'status'        => 'success',
        'checked_at'    => date('Y-m-d H:i:s'),
        'found_overdue' => count($overdue_tasks),
        'sms_sent'      => $sent_count,
        'skipped'       => $skipped_count
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Cron SMS Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal cron error.'], JSON_UNESCAPED_UNICODE);
}