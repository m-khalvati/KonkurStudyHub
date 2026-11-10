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

try {
    // ====================================================
    // ۱. دریافت وضعیت فعلی اشتراک کاربر
    // ====================================================
    if ($action === 'status') {
        echo json_encode([
            'status'       => 'success',
            'subscription' => $sub_info
        ]);
        exit;
    }

    // ====================================================
    // ۲. فعال‌سازی یا تمدید اشتراک توسط کاربر با «کد اشتراک»
    // ====================================================
    if ($action === 'redeem_code') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        if (empty($code)) {
            echo json_encode(['status' => 'error', 'message' => 'لطفاً کد فعال‌سازی اشتراک را وارد کنید.']);
            exit;
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM subscription_codes WHERE UPPER(code) = ? FOR UPDATE");
        $stmt->execute([$code]);
        $code_row = $stmt->fetch();

        if (!$code_row) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'کد وارد شده معتبر نیست. لطفاً دقت کنید.']);
            exit;
        }

        if ((int)$code_row['is_used'] === 1) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'این کد اشتراک قبلاً استفاده شده است.']);
            exit;
        }

        $days = (int)$code_row['days'];
        // تمدید تجمعی اشتراک کاربر
        extend_user_subscription($pdo, $user_id, $days);

        // علامت‌گذاری کد به عنوان استفاده‌شده
        $updCode = $pdo->prepare("UPDATE subscription_codes SET is_used = 1, used_by = ?, used_at = NOW() WHERE id = ?");
        $updCode->execute([$user_id, $code_row['id']]);

        $pdo->commit();

        $updated_sub = get_user_subscription_info($pdo, $user_id);
        $plan_label  = ($days >= 90) ? '۳ ماهه (۹۰ روز)' : '۱ ماهه (۳۰ روز)';

        echo json_encode([
            'status'       => 'success',
            'message'      => "🎉 تبریک! اشتراک ویژه {$plan_label} با موفقیت فعال شد. مجموع اعتبار فعلی شما: {$updated_sub['days_left']} روز.",
            'subscription' => $updated_sub
        ]);
        exit;
    }

    // ====================================================
    // ۳. عملیات ویژه مدیر سایت (Admin Only)
    // ====================================================
    if (!$sub_info['is_admin']) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'شما دسترسی مدیریت ندارید.']);
        exit;
    }

    // الف) شارژ مستقیم اشتراک کاربر توسط ادمین (+30 روز یا +90 روز به صورت تجمعی)
    if ($action === 'admin_extend_user') {
        $target_user_id = (int)($_POST['target_user_id'] ?? 0);
        $days           = (int)($_POST['days'] ?? 30);

        if ($target_user_id <= 0 || $days <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'اطلاعات کاربر یا تعداد روز نامعتبر است.']);
            exit;
        }

        extend_user_subscription($pdo, $target_user_id, $days);
        $new_info = get_user_subscription_info($pdo, $target_user_id);

        echo json_encode([
            'status'    => 'success',
            'message'   => "اشتراک کاربر به مدت {$days} روز دیگر شارژ/تمدید شد (مجموع: {$new_info['days_left']} روز).",
            'user_info' => $new_info
        ]);
        exit;
    }

    // ب) لغو اشتراک ویژه کاربر توسط ادمین (بازگشت به کاربر عادی)
    if ($action === 'admin_revoke_user') {
        $target_user_id = (int)($_POST['target_user_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE users SET vip_expires_at = NULL WHERE id = ?");
        $stmt->execute([$target_user_id]);

        echo json_encode([
            'status'  => 'success',
            'message' => 'اشتراک ویژه کاربر لغو شد و به حالت کاربر عادی بازگشت.'
        ]);
        exit;
    }

    // ج) تولید کد فعال‌سازی یک‌بارمصرف ۱ ماهه یا ۳ ماهه برای ارسال در تلگرام
    if ($action === 'admin_generate_code') {
        $plan_type = ($_POST['plan_type'] ?? '1m') === '3m' ? '3m' : '1m';
        $days      = ($plan_type === '3m') ? 90 : 30;
        $prefix    = ($plan_type === '3m') ? 'VIP90' : 'VIP30';

        $rand_part = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4) . '-' . substr(bin2hex(random_bytes(3)), 0, 4));
        $code      = "{$prefix}-{$rand_part}";

        $stmt = $pdo->prepare("INSERT INTO subscription_codes (code, plan_type, days) VALUES (?, ?, ?)");
        $stmt->execute([$code, $plan_type, $days]);

        echo json_encode([
            'status'    => 'success',
            'message'   => 'کد اشتراک جدید ساخته شد.',
            'code'      => $code,
            'plan_type' => $plan_type,
            'days'      => $days
        ]);
        exit;
    }

    // د) حذف کد اشتراک استفاده نشده
    if ($action === 'admin_delete_code') {
        $code_id = (int)($_POST['code_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM subscription_codes WHERE id = ? AND is_used = 0");
        $stmt->execute([$code_id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'عملیات نامعتبر است.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'خطای سرور: ' . $e->getMessage()]);
}