<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'لطفاً ابتدا وارد حساب کاربری شوید.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$action  = $_GET['action'] ?? ($_POST['action'] ?? '');

// اطمینان از وجود پوشه آپلود و ساخت خودکار .htaccess برای جلوگیری از خطای 412 در وب‌سرور
$upload_dir = __DIR__ . '/uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}
$htaccess_path = $upload_dir . '.htaccess';
if (!is_file($htaccess_path)) {
    $ht_content = "<IfModule mod_headers.c>\n    Header unset ETag\n    Header set Accept-Ranges \"none\"\n</IfModule>\nFileETag None\nSetEnv no-gzip 1\n";
    @file_put_contents($htaccess_path, $ht_content);
}

// ====================================================
// اکشن ویژه: استریم مستقیم و بدون خطای فایل PDF (ضد ارور 412)
// ====================================================
if ($action === 'raw') {
    $pdf_id = (int)($_GET['pdf_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT file_path FROM pdfs WHERE id = ? AND user_id = ?");
    $stmt->execute([$pdf_id, $user_id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        exit('File not found');
    }

    $full_path = __DIR__ . '/' . $row['file_path'];
    if (!is_file($full_path)) {
        http_response_code(404);
        exit('Physical file missing');
    }

    // پاکسازی بافرهای خروجی برای ارسال باینری خالص
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($full_path));
    header('Accept-Ranges: none');
    header('Cache-Control: private, no-transform, no-store, must-revalidate');
    header('Pragma: no-cache');
    readfile($full_path);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// بررسی و ساخت خودکار جدول پوشه‌ها و ستون‌های نسخه ۲
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `pdf_folders` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `name` varchar(100) NOT NULL,
          `color` varchar(20) DEFAULT '#4f46e5',
          `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_user_folder` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
    ");

    $cols = $pdo->query("SHOW COLUMNS FROM `pdfs`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('folder_id', $cols)) {
        $pdo->exec("ALTER TABLE `pdfs` ADD COLUMN `folder_id` int(11) DEFAULT NULL AFTER `user_id`");
    }
    if (!in_array('starred_pages', $cols)) {
        $pdo->exec("ALTER TABLE `pdfs` ADD COLUMN `starred_pages` text DEFAULT NULL AFTER `last_page`");
    }
} catch (Exception $ignore) {}

try {
    // ۱. دریافت لیست پوشه‌ها + لیست تمام جزوات کاربر
    if ($action === 'list') {
        $fStmt = $pdo->prepare("
            SELECT id, name, color, created_at 
            FROM pdf_folders 
            WHERE user_id = ? 
            ORDER BY id ASC
        ");
        $fStmt->execute([$user_id]);
        $folders = $fStmt->fetchAll();

        $pStmt = $pdo->prepare("
            SELECT p.id, p.folder_id, p.title, p.category, p.file_path, 
                   p.total_pages, p.last_page, p.starred_pages, p.created_at,
                   f.name AS folder_name, f.color AS folder_color
            FROM pdfs p
            LEFT JOIN pdf_folders f ON p.folder_id = f.id AND f.user_id = p.user_id
            WHERE p.user_id = ? 
            ORDER BY p.id DESC
        ");
        $pStmt->execute([$user_id]);
        $pdfs = $pStmt->fetchAll();

        foreach ($pdfs as &$pdf) {
            $decoded = !empty($pdf['starred_pages']) ? json_decode($pdf['starred_pages'], true) : [];
            $pdf['starred_pages'] = is_array($decoded) ? array_values(array_map('intval', $decoded)) : [];
        }
        unset($pdf);

        echo json_encode([
            'status'  => 'success',
            'folders' => $folders,
            'pdfs'    => $pdfs
        ]);
        exit;
    }

    // ۲. ساخت پوشه درسی جدید
    if ($action === 'create_folder') {
        $name  = trim($_POST['name'] ?? '');
        $color = trim($_POST['color'] ?? '#4f46e5');

        if (empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'لطفاً نام پوشه (درس) را وارد کنید.']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO pdf_folders (user_id, name, color) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $name, $color]);

        echo json_encode([
            'status'  => 'success',
            'message' => 'پوشه جدید با موفقیت ساخته شد.',
            'folder'  => [
                'id'    => (int)$pdo->lastInsertId(),
                'name'  => $name,
                'color' => $color
            ]
        ]);
        exit;
    }

    // ۳. حذف پوشه
    if ($action === 'delete_folder') {
        $folder_id = (int)($_POST['folder_id'] ?? 0);

        $upd = $pdo->prepare("UPDATE pdfs SET folder_id = NULL, category = 'عمومی' WHERE folder_id = ? AND user_id = ?");
        $upd->execute([$folder_id, $user_id]);

        $del = $pdo->prepare("DELETE FROM pdf_folders WHERE id = ? AND user_id = ?");
        $del->execute([$folder_id, $user_id]);

        echo json_encode(['status' => 'success', 'message' => 'پوشه حذف شد و جزوات آن به بخش عمومی منتقل شدند.']);
        exit;
    }

    // ۴. انتقال یک جزوه به پوشه دیگر
    if ($action === 'move_pdf') {
        $pdf_id    = (int)($_POST['pdf_id'] ?? 0);
        $folder_id = !empty($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;

        if ($folder_id !== null) {
            $chk = $pdo->prepare("SELECT id, name FROM pdf_folders WHERE id = ? AND user_id = ?");
            $chk->execute([$folder_id, $user_id]);
            $folder = $chk->fetch();
            if (!$folder) {
                echo json_encode(['status' => 'error', 'message' => 'پوشه انتخاب‌شده معتبر نیست.']);
                exit;
            }
            $category_label = $folder['name'];
        } else {
            $category_label = 'عمومی';
        }

        $stmt = $pdo->prepare("UPDATE pdfs SET folder_id = ?, category = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$folder_id, $category_label, $pdf_id, $user_id]);

        echo json_encode(['status' => 'success', 'message' => 'جزوه با موفقیت به پوشه جدید منتقل شد.']);
        exit;
    }

    // ۵. آپلود جزوه PDF جدید
    if ($action === 'upload') {
        $title     = trim($_POST['title'] ?? '');
        $folder_id = !empty($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;
        $category  = trim($_POST['category'] ?? 'عمومی');

        if (empty($title)) {
            echo json_encode(['status' => 'error', 'message' => 'لطفاً عنوان جزوه را وارد کنید.']);
            exit;
        }

        if ($folder_id !== null) {
            $fChk = $pdo->prepare("SELECT id, name FROM pdf_folders WHERE id = ? AND user_id = ?");
            $fChk->execute([$folder_id, $user_id]);
            $fRow = $fChk->fetch();
            if ($fRow) {
                $category = $fRow['name'];
            } else {
                $folder_id = null;
            }
        }

        if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error', 'message' => 'خطا در دریافت فایل PDF. حجم فایل را بررسی کنید.']);
            exit;
        }

        $file = $_FILES['pdf_file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if ($ext !== 'pdf' || $mime !== 'application/pdf') {
            echo json_encode(['status' => 'error', 'message' => 'فقط فایل‌های استاندارد PDF مجاز هستند.']);
            exit;
        }

        $new_filename = 'pdf_u' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
        $dest_path    = $upload_dir . $new_filename;
        $db_rel_path  = 'uploads/' . $new_filename;

        if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
            echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی نوشتن در پوشه uploads هاست.']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO pdfs (user_id, folder_id, title, category, file_path, total_pages, last_page, starred_pages)
            VALUES (?, ?, ?, ?, ?, 1, 1, '[]')
        ");
        $stmt->execute([$user_id, $folder_id, $title, $category, $db_rel_path]);
        $new_id = (int)$pdo->lastInsertId();

        echo json_encode([
            'status'  => 'success',
            'message' => 'جزوه با موفقیت آپلود شد.',
            'pdf'     => [
                'id'            => $new_id,
                'folder_id'     => $folder_id,
                'title'         => $title,
                'category'      => $category,
                'file_path'     => $db_rel_path,
                'total_pages'   => 1,
                'last_page'     => 1,
                'starred_pages' => []
            ]
        ]);
        exit;
    }

    // ۶. ذخیره خودکار آخرین صفحه خوانده‌شده
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

    // ۷. ستاره‌دار کردن / برداشتن ستاره یک صفحه مهم
    if ($action === 'toggle_star_page') {
        $pdf_id      = (int)($_POST['pdf_id'] ?? 0);
        $page_number = max(1, (int)($_POST['page_number'] ?? 1));

        $stmt = $pdo->prepare("SELECT starred_pages FROM pdfs WHERE id = ? AND user_id = ?");
        $stmt->execute([$pdf_id, $user_id]);
        $row = $stmt->fetch();

        if (!$row) {
            echo json_encode(['status' => 'error', 'message' => 'جزوه یافت نشد.']);
            exit;
        }

        $starred = !empty($row['starred_pages']) ? json_decode($row['starred_pages'], true) : [];
        if (!is_array($starred)) {
            $starred = [];
        }
        $starred = array_map('intval', $starred);

        $is_starred = false;
        if (in_array($page_number, $starred, true)) {
            $starred = array_values(array_diff($starred, [$page_number]));
            $is_starred = false;
        } else {
            $starred[] = $page_number;
            $starred = array_values(array_unique($starred));
            sort($starred, SORT_NUMERIC);
            $is_starred = true;
        }

        $upd = $pdo->prepare("UPDATE pdfs SET starred_pages = ? WHERE id = ? AND user_id = ?");
        $upd->execute([json_encode($starred), $pdf_id, $user_id]);

        echo json_encode([
            'status'        => 'success',
            'is_starred'    => $is_starred,
            'starred_pages' => $starred
        ]);
        exit;
    }

    // ۸. حذف جزوه
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

    // ۹. دریافت علامت‌گذاری‌های یک صفحه خاص از جزوه
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

    // ۱۰. ذخیره خودکار علامت‌گذاری‌های صفحه
    if ($action === 'save_annotations') {
        $pdf_id          = (int)($_POST['pdf_id'] ?? 0);
        $page_number     = max(1, (int)($_POST['page_number'] ?? 1));
        $annotation_data = $_POST['annotation_data'] ?? '[]';

        json_decode($annotation_data);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['status' => 'error', 'message' => 'فرمت داده‌های علامت‌گذاری نامعتبر است.']);
            exit;
        }

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