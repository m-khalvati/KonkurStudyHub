<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/security_shield.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'لطفاً ابتدا وارد حساب کاربری خود شوید.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$action  = $_GET['action'] ?? ($_POST['action'] ?? 'status');

try {
    if ($action === 'status') {
        $sub = get_user_subscription_info($pdo, $user_id);
        echo json_encode([
            'status'       => 'success',
            'subscription' => $sub
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'redeem_code') {
        // محدودیت حداکثر ۸ تلاش در دقیقه برای جلوگیری از حدس زدن کدهای اشتراک
        enforce_api_rate_limit($pdo, 'redeem_promo', 8, 60);

        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        if ($code === '' || !preg_match('/^[A-Z0-9-]{6,32}$/', $code)) {
            echo json_encode(['status' => 'error', 'message' => 'فرمت کد فعال‌سازی واردشده معتبر نیست.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // شروع تراکنش اتمیک برای جلوگیری از Race Condition (Double-Spend)
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM subscription_codes WHERE code = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$code]);
        $promo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$promo) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'کد فعال‌سازی واردشده نامعتبر است.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ((int)$promo['is_used'] === 1) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'این کد فعال‌سازی قبلاً استفاده شده است.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $days = max(1, (int)$promo['days']);

        // علامت‌گذاری کد به عنوان استفاده‌شده با شرط is_used = 0
        $updCode = $pdo->prepare("UPDATE subscription_codes SET is_used = 1, used_by = ?, used_at = NOW() WHERE id = ? AND is_used = 0");
        $updCode->execute([$user_id, (int)$promo['id']]);

        if ($updCode->rowCount() !== 1) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'این کد قبلاً ثبت شده است.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // تمدید تجمعی اشتراک کاربر
        extend_user_subscription($pdo, $user_id, $days);
        $pdo->commit();

        $updated_sub = get_user_subscription_info($pdo, $user_id);

        echo json_encode([
            'status'       => 'success',
            'message'      => "🎉 تبریک! {$days} روز اشتراک ویژه (VIP) با موفقیت به حساب شما اضافه شد.",
            'subscription' => $updated_sub
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'اکشن نامعتبر است.'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Subscription API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'خطا در پردازش درخواست اشتراک.'], JSON_UNESCAPED_UNICODE);
}