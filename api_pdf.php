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

// اطمینان از وجود پوشه آپلود
$upload_dir = __DIR__ . '/uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

try {
    // ۱. دریافت لیست تمام جزوات کاربر
    if ($action === 'list') {
        $stmt = $pdo->prepare("
            SELECT id, title, category, file_path, total_pages, last_page, created_at 
            FROM pdfs 
            WHERE user_id = ? 
            ORDER BY id DESC
        ");
        $stmt->execute([$user_id]);
        echo json_encode(['status' => 'success', 'pdfs' => $stmt->fetchAll()]);
        exit;
    }

    // ۲. آپلود جزوه PDF جدید
    if ($action === 'upload') {
        $title    = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? 'عمومی');

        if (empty($title)) {
            echo json_encode(['status' => 'error', 'message' => 'لطفاً عنوان جزوه را وارد کنید.']);
            exit;
        }

        if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error', 'message' => 'خطا در دریافت فایل PDF. لطفاً دوباره تلاش کنید.']);
            exit;
        }

        $file = $_FILES['pdf_file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        // بررسی پسوند و نوع MIME فایل برای امنیت هاست
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if ($ext !== 'pdf' || $mime !== 'application/pdf') {
            echo json_encode(['status' => 'error', 'message' => 'فقط فایل‌های استاندارد PDF مجاز هستند.']);
            exit;
        }

        // تولید نام یکتا برای جلوگیری از تداخل فایل‌های کاربران مختلف
        $new_filename = 'pdf_u' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
        $dest_path    = $upload_dir . $new_filename;
        $db_rel_path  = 'uploads/' . $new_filename;

        if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
            echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی نوشتن در پوشه uploads هاست.']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO pdfs (user_id, title, category, file_path, total_pages, last_page)
            VALUES (?, ?, ?, ?, 1, 1)
        ");
        $stmt->execute([$user_id, $title, $category, $db_rel_path]);
        $new_id = $pdo->lastInsertId();

        echo json_encode([
            'status'  => 'success',
            'message' => 'جزوه با موفقیت آپلود شد.',
            'pdf'     => [
                'id'          => (int)$new_id,
                'title'       => $title,
                'category'    => $category,
                'file_path'   => $db_rel_path,
                'total_pages' => 1,
                'last_page'   => 1
            ]
        ]);
        exit;
    }

    // ۳. ذخیره خودکار آخرین صفحه خوانده‌شده و تعداد کل صفحات جزوه
    if ($action === 'save_progress') {
        $pdf_id      = (int)($_POST['pdf_id'] ?? 0);
        $last_page   = max(1, (int)($_POST['last_page'] ?? 1));
        $total_pages = max(1, (int)($_POST['total_pages'] ?? 1));

        $stmt = $pdo->prepare("
            UPDATE pdfs 
            SET last_page = ?, total_pages = ? 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$last_page, $total_pages, $pdf_id, $user_id]);

        echo json_encode(['status' => 'success']);
        exit;
    }

    // ۴. حذف جزوه (حذف فایل فیزیکی از هاست + حذف از دیتابیس)
    if ($action === 'delete') {
        $pdf_id = (int)($_POST['pdf_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT file_path FROM pdfs WHERE id = ? AND user_id = ?");
        $stmt->execute([$pdf_id, $user_id]);
        $pdf = $stmt->fetch();

        if ($pdf) {
            $full_path = __DIR__ . '/' . $pdf['file_path'];
            if (is_file($full_path)) {
                @unlink($full_path);
            }
            $delStmt = $pdo->prepare("DELETE FROM pdfs WHERE id = ? AND user_id = ?");
            $delStmt->execute([$pdf_id, $user_id]);
        }

        echo json_encode(['status' => 'success']);
        exit;
    }

// ۵. دریافت علامت‌گذاری‌های یک صفحه خاص از جزوه
    if ($action === 'get_annotations') {
        $pdf_id      = (int)($_GET['pdf_id'] ?? 0);
        $page_number = max(1, (int)($_GET['page_number'] ?? 1));

        $stmt = $pdo->prepare("
            SELECT annotation_data 
            FROM pdf_annotations 
            WHERE user_id = ? AND pdf_id = ? AND page_number = ?
        ");
        $stmt->execute([$user_id, $pdf_id, $page_number]);
        $row = $stmt->fetch();

        echo json_encode([
            'status' => 'success',
            'data'   => $row ? json_decode($row['annotation_data'], true) : []
        ]);
        exit;
    }

    // ۶. ذخیره خودکار علامت‌گذاری‌های صفحه (هایلایت، قلم و یادداشت‌ها)
    if ($action === 'save_annotations') {
        $pdf_id          = (int)($_POST['pdf_id'] ?? 0);
        $page_number     = max(1, (int)($_POST['page_number'] ?? 1));
        $annotation_data = $_POST['annotation_data'] ?? '[]';

        // اعتبارسنجی JSON
        json_decode($annotation_data);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['status' => 'error', 'message' => 'فرمت داده‌های علامت‌گذاری نامعتبر است.']);
            exit;
        }

        // ذخیره یا بروزرسانی خودکار در دیتابیس (Upsert)
        $stmt = $pdo->prepare("
            INSERT INTO pdf_annotations (user_id, pdf_id, page_number, annotation_data)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE annotation_data = VALUES(annotation_data), updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$user_id, $pdf_id, $page_number, $annotation_data]);

        echo json_encode(['status' => 'success']);
        exit;
    }
	
    echo json_encode(['status' => 'error', 'message' => 'درخواست نامعتبر است.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'خطای سرور: ' . $e->getMessage()]);
}