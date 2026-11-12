<?php
require_once 'config.php';

@set_time_limit(120);
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

$is_admin = !empty($sub_info['is_admin']) || ($user_id === 1);

// ====================================================
// ۱. ساخت خودکار پوشه آپلود دوره‌ها و ۵ جدول دیتابیس
// ====================================================
$courses_upload_dir = __DIR__ . '/uploads/courses/';
$subtitles_dir      = $courses_upload_dir . 'subtitles/';
$booklets_dir       = $courses_upload_dir . 'booklets/';
$covers_dir         = $courses_upload_dir . 'covers/';

foreach ([$courses_upload_dir, $subtitles_dir, $booklets_dir, $covers_dir] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `courses` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `title` varchar(255) NOT NULL,
          `instructor` varchar(120) DEFAULT NULL,
          `category` varchar(100) DEFAULT 'عمومی',
          `description` text DEFAULT NULL,
          `cover_image` varchar(255) DEFAULT NULL,
          `is_vip_only` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=ویژه مشترکین VIP, 0=رایگان برای همه',
          `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=منتشرشده, 0=پیش‌نویس',
          `sort_order` int(11) NOT NULL DEFAULT 1,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `course_chapters` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `course_id` int(11) NOT NULL,
          `title` varchar(255) NOT NULL,
          `sort_order` int(11) NOT NULL DEFAULT 1,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_chap_course` (`course_id`, `sort_order`),
          CONSTRAINT `fk_chap_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `course_lessons` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `course_id` int(11) NOT NULL,
          `chapter_id` int(11) NOT NULL,
          `title` varchar(255) NOT NULL,
          `description` text DEFAULT NULL,
          `video_url` text NOT NULL COMMENT 'لینک مستقیم ویدیو یا لینک/کد امبد آپارات',
          `video_url_backup` text DEFAULT NULL COMMENT 'لینک کیفیت دوم یا کمکی',
          `duration_sec` int(11) NOT NULL DEFAULT 0,
          `subtitle_url` text DEFAULT NULL COMMENT 'فایل یا لینک زیرنویس SRT/VTT',
          `booklet_url` text DEFAULT NULL COMMENT 'فایل یا لینک جزوه اختصاصی جلسه',
          `booklet_title` varchar(150) DEFAULT 'جزوه و نکات جلسه',
          `linked_exam_id` int(11) DEFAULT NULL COMMENT 'اتصال به آزمون مرتبط در exam.php',
          `is_free_preview` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=پیش‌نمایش رایگان برای همه, 0=تابع وضعیت دوره',
          `sort_order` int(11) NOT NULL DEFAULT 1,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_less_course_chap` (`course_id`, `chapter_id`, `sort_order`),
          CONSTRAINT `fk_less_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_less_chap` FOREIGN KEY (`chapter_id`) REFERENCES `course_chapters` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `video_progress` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `course_id` int(11) NOT NULL,
          `lesson_id` int(11) NOT NULL,
          `current_time_sec` int(11) NOT NULL DEFAULT 0,
          `duration_sec` int(11) NOT NULL DEFAULT 0,
          `max_watched_sec` int(11) NOT NULL DEFAULT 0,
          `is_completed` tinyint(1) NOT NULL DEFAULT 0,
          `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_user_lesson_prog` (`user_id`, `lesson_id`),
          KEY `idx_user_course_prog` (`user_id`, `course_id`),
          CONSTRAINT `fk_vprog_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_vprog_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `course_lessons` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `video_notes` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `course_id` int(11) NOT NULL,
          `lesson_id` int(11) NOT NULL,
          `timestamp_sec` int(11) NOT NULL DEFAULT 0,
          `note_text` text NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_vnote_user_lesson` (`user_id`, `lesson_id`, `timestamp_sec`),
          CONSTRAINT `fk_vnote_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
          CONSTRAINT `fk_vnote_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `course_lessons` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
    ");
} catch (Exception $ignore) {}

// ====================================================
// ۲. توابع کمکی تاریخ شمسی و موتور پردازش لینک و API آپارات
// ====================================================
function course_gdate_to_jalali($g_date) {
    $parts = explode('-', trim($g_date));
    if (count($parts) !== 3) return $g_date;
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

// استخراج کد یکتای ویدیوی آپارات (videohash) از لینک صفحه، لینک امبد یا کد کامل iframe
function extract_aparat_video_hash($raw_input) {
    $str = trim((string)$raw_input);
    if ($str === '') return null;

    // اگر لینک مستقیم فایل .mp4 از CDN آپارات باشد، به عنوان فایل ویدیویی مستقیم پخش شود
    if (preg_match('/\.mp4(\?.*)?$/i', $str) && stripos($str, '<iframe') === false) {
        return null;
    }

    // ۱. تشخیص از لینک یا کد امبد آپارات: aparat.com/video/video/embed/videohash/XXXXX/vt/frame
    if (preg_match('#aparat\.com/video/video/embed/videohash/([a-zA-Z0-9]+)#i', $str, $m)) {
        return $m[1];
    }

    // ۲. تشخیص از لینک صفحه عادی آپارات: aparat.com/v/XXXXX
    if (preg_match('#aparat\.com/v/([a-zA-Z0-9]+)#i', $str, $m)) {
        return $m[1];
    }

    return null;
}

// استانداردسازی ورودی ادمین (تبدیل کد <iframe> آپارات به لینک تمیز و استاندارد)
function normalize_admin_video_url_input($raw_input) {
    $str = trim((string)$raw_input);
    $hash = extract_aparat_video_hash($str);
    if ($hash !== null) {
        return "https://www.aparat.com/v/{$hash}";
    }
    if (preg_match('/src=["\']([^"\']+)["\']/i', $str, $m)) {
        return trim($m[1]);
    }
    return $str;
}

// دریافت عنوان، زمان، پوستر و لینک‌های مستقیم .mp4 از API رسمی آپارات
function fetch_aparat_video_streams($video_hash) {
    $embed_url = "https://www.aparat.com/video/video/embed/videohash/" . urlencode($video_hash) . "/vt/frame";
    $api_url   = "https://www.aparat.com/etc/api/video/videohash/" . urlencode($video_hash);

    $result = [
        'is_aparat'        => true,
        'aparat_hash'      => $video_hash,
        'aparat_embed_url' => $embed_url,
        'title'            => '',
        'poster'           => '',
        'direct_mp4_main'  => '',
        'direct_mp4_bkp'   => '',
        'duration_sec'     => 0
    ];

    try {
        $ch = curl_init($api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && !empty($raw)) {
            $json = json_decode($raw, true);
            $vinfo = $json['video'] ?? null;
            if (is_array($vinfo)) {
                $result['title']  = trim($vinfo['title'] ?? '');
                $result['poster'] = trim($vinfo['big_poster'] ?? ($vinfo['small_poster'] ?? ''));

                if (!empty($vinfo['duration'])) {
                    $result['duration_sec'] = (int)$vinfo['duration'];
                }

                $links = [];
                if (!empty($vinfo['file_link_all']) && is_array($vinfo['file_link_all'])) {
                    foreach ($vinfo['file_link_all'] as $item) {
                        if (!empty($item['urls'][0])) {
                            $profile = strtolower($item['profile'] ?? '');
                            $links[$profile] = $item['urls'][0];
                        }
                    }
                }

                $preferred_main = ['720p', '1080p', '480p', '360p', '240p'];
                foreach ($preferred_main as $p) {
                    if (!empty($links[$p])) {
                        $result['direct_mp4_main'] = $links[$p];
                        break;
                    }
                }
                if (empty($result['direct_mp4_main']) && !empty($links)) {
                    $result['direct_mp4_main'] = reset($links);
                }
                if (empty($result['direct_mp4_main']) && !empty($vinfo['file_link'])) {
                    $result['direct_mp4_main'] = $vinfo['file_link'];
                }

                $preferred_bkp = ['480p', '360p', '720p'];
                foreach ($preferred_bkp as $p) {
                    if (!empty($links[$p]) && $links[$p] !== $result['direct_mp4_main']) {
                        $result['direct_mp4_bkp'] = $links[$p];
                        break;
                    }
                }
            }
        }
    } catch (Exception $ignore) {}

    return $result;
}

function handle_course_file_upload($file_key, array $allowed_exts, $dest_dir, $rel_prefix) {
    if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $f = $_FILES[$file_key];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) {
        throw new Exception('فرمت فایل آپلودشده مجاز نیست. فرمت‌های مجاز: ' . implode(', ', $allowed_exts));
    }
    $new_name = 'cfile_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $full_dest = rtrim($dest_dir, '/') . '/' . $new_name;
    if (!move_uploaded_file($f['tmp_name'], $full_dest)) {
        throw new Exception('خطا در ذخیره فایل در پوشه uploads/courses هاست.');
    }
    return rtrim($rel_prefix, '/') . '/' . $new_name;
}

function can_user_access_lesson(array $course, array $lesson, array $sub_info, bool $is_admin) {
    if ($is_admin || !empty($sub_info['is_vip'])) {
        return true;
    }
    if ((int)($course['is_vip_only'] ?? 1) === 0) {
        return true;
    }
    if ((int)($lesson['is_free_preview'] ?? 0) === 1) {
        return true;
    }
    return false;
}

try {
    // ====================================================
    // الف) اکشن‌های عمومی دانشجویان (courses.php)
    // ====================================================

    // ۱. دریافت لیست تمام دوره‌های فعال به همراه درصد پیشرفت کاربر و آخرین جلسه دیده‌شده
    if ($action === 'list_courses') {
        $stmt = $pdo->query("
            SELECT c.*,
                   (SELECT COUNT(*) FROM course_chapters ch WHERE ch.course_id = c.id) AS chapters_count,
                   (SELECT COUNT(*) FROM course_lessons l WHERE l.course_id = c.id) AS lessons_count,
                   (SELECT COALESCE(SUM(l.duration_sec), 0) FROM course_lessons l WHERE l.course_id = c.id) AS total_duration_sec
            FROM courses c
            WHERE c.is_active = 1
            ORDER BY c.sort_order ASC, c.id DESC
        ");
        $courses = $stmt->fetchAll();

        $progStmt = $pdo->prepare("
            SELECT p.*, l.title AS lesson_title
            FROM video_progress p
            INNER JOIN course_lessons l ON l.id = p.lesson_id
            WHERE p.user_id = ?
            ORDER BY p.updated_at DESC
        ");
        $progStmt->execute([$user_id]);
        $user_progs = $progStmt->fetchAll();

        $completed_by_course = [];
        $last_watched_by_course = [];
        $global_last_watched = null;

        foreach ($user_progs as $pr) {
            $cid = (int)$pr['course_id'];
            if ((int)$pr['is_completed'] === 1) {
                $completed_by_course[$cid] = ($completed_by_course[$cid] ?? 0) + 1;
            }
            if (!isset($last_watched_by_course[$cid])) {
                $last_watched_by_course[$cid] = [
                    'lesson_id'        => (int)$pr['lesson_id'],
                    'lesson_title'     => $pr['lesson_title'],
                    'current_time_sec' => (int)$pr['current_time_sec'],
                    'duration_sec'     => (int)$pr['duration_sec'],
                    'updated_at'       => $pr['updated_at']
                ];
            }
            if ($global_last_watched === null) {
                $global_last_watched = [
                    'course_id'        => $cid,
                    'lesson_id'        => (int)$pr['lesson_id'],
                    'lesson_title'     => $pr['lesson_title'],
                    'current_time_sec' => (int)$pr['current_time_sec'],
                    'duration_sec'     => (int)$pr['duration_sec']
                ];
            }
        }

        foreach ($courses as &$c) {
            $cid = (int)$c['id'];
            $total_l = max(0, (int)$c['lessons_count']);
            $done_l  = $completed_by_course[$cid] ?? 0;

            $c['chapters_count']     = (int)$c['chapters_count'];
            $c['lessons_count']      = $total_l;
            $c['total_duration_sec'] = (int)$c['total_duration_sec'];
            $c['completed_lessons']  = $done_l;
            $c['progress_percent']   = $total_l > 0 ? min(100, round(($done_l / $total_l) * 100)) : 0;
            $c['last_watched']       = $last_watched_by_course[$cid] ?? null;
        }
        unset($c);

        echo json_encode([
            'status'              => 'success',
            'courses'             => $courses,
            'global_last_watched' => $global_last_watched,
            'subscription'        => $sub_info
        ]);
        exit;
    }

    // ۲. دریافت ساختار کامل یک دوره (فصل‌ها، جلسات و ثانیه پخش ذخیره‌شده هر جلسه)
    if ($action === 'get_course_details') {
        $course_id = (int)($_GET['course_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ? AND (is_active = 1 OR ? = 1)");
        $stmt->execute([$course_id, $is_admin ? 1 : 0]);
        $course = $stmt->fetch();

        if (!$course) {
            echo json_encode(['status' => 'error', 'message' => 'دوره آموزشی مورد نظر یافت نشد.']);
            exit;
        }

        $chapStmt = $pdo->prepare("SELECT * FROM course_chapters WHERE course_id = ? ORDER BY sort_order ASC, id ASC");
        $chapStmt->execute([$course_id]);
        $chapters = $chapStmt->fetchAll();

        $lessStmt = $pdo->prepare("
            SELECT l.*,
                   e.title AS linked_exam_title
            FROM course_lessons l
            LEFT JOIN exams e ON e.id = l.linked_exam_id
            WHERE l.course_id = ?
            ORDER BY l.sort_order ASC, l.id ASC
        ");
        $lessStmt->execute([$course_id]);
        $lessons = $lessStmt->fetchAll();

        $progStmt = $pdo->prepare("SELECT * FROM video_progress WHERE user_id = ? AND course_id = ?");
        $progStmt->execute([$user_id, $course_id]);
        $prog_map = [];
        $last_watched_lesson_id = null;
        $latest_ts = 0;

        foreach ($progStmt->fetchAll() as $pr) {
            $lid = (int)$pr['lesson_id'];
            $prog_map[$lid] = [
                'current_time_sec' => (int)$pr['current_time_sec'],
                'duration_sec'     => (int)$pr['duration_sec'],
                'max_watched_sec'  => (int)$pr['max_watched_sec'],
                'is_completed'     => (int)$pr['is_completed'] === 1
            ];
            $u_ts = strtotime($pr['updated_at'] ?? '');
            if ($u_ts >= $latest_ts) {
                $latest_ts = $u_ts;
                $last_watched_lesson_id = $lid;
            }
        }

        $lessons_by_chapter = [];
        $total_lessons_count = 0;
        $completed_lessons_count = 0;
        $first_unlocked_lesson_id = null;

        foreach ($lessons as $ls) {
            $lid     = (int)$ls['id'];
            $chid    = (int)$ls['chapter_id'];
            $has_acc = can_user_access_lesson($course, $ls, $sub_info, $is_admin);
            $u_prog  = $prog_map[$lid] ?? [
                'current_time_sec' => 0,
                'duration_sec'     => (int)$ls['duration_sec'],
                'max_watched_sec'  => 0,
                'is_completed'     => false
            ];

            $total_lessons_count++;
            if ($u_prog['is_completed']) {
                $completed_lessons_count++;
            }
            if ($has_acc && $first_unlocked_lesson_id === null) {
                $first_unlocked_lesson_id = $lid;
            }

            $ls['is_aparat'] = (extract_aparat_video_hash($ls['video_url']) !== null);

            if (!$has_acc) {
                $ls['video_url']        = '';
                $ls['video_url_backup'] = '';
                $ls['subtitle_url']     = '';
                $ls['booklet_url']      = '';
            }

            $ls['is_locked']     = !$has_acc;
            $ls['user_progress'] = $u_prog;

            if (!isset($lessons_by_chapter[$chid])) {
                $lessons_by_chapter[$chid] = [];
            }
            $lessons_by_chapter[$chid][] = $ls;
        }

        foreach ($chapters as &$ch) {
            $chid = (int)$ch['id'];
            $ch['lessons'] = $lessons_by_chapter[$chid] ?? [];
        }
        unset($ch);

        echo json_encode([
            'status'            => 'success',
            'course'            => $course,
            'chapters'          => $chapters,
            'total_lessons'     => $total_lessons_count,
            'completed_lessons' => $completed_lessons_count,
            'progress_percent'  => $total_lessons_count > 0 ? min(100, round(($completed_lessons_count / $total_lessons_count) * 100)) : 0,
            'resume_lesson_id'  => $last_watched_lesson_id ?: $first_unlocked_lesson_id,
            'subscription'      => $sub_info
        ]);
        exit;
    }

    // ۳. دریافت اطلاعات کامل یک جلسه تکی + استخراج هوشمند لینک‌های آپارات + یادداشت‌های زمان‌دار
    if ($action === 'get_lesson_data') {
        $lesson_id = (int)($_GET['lesson_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT l.*, c.title AS course_title, c.is_vip_only AS course_vip_only,
                   ch.title AS chapter_title, e.title AS linked_exam_title
            FROM course_lessons l
            INNER JOIN courses c ON c.id = l.course_id
            INNER JOIN course_chapters ch ON ch.id = l.chapter_id
            LEFT JOIN exams e ON e.id = l.linked_exam_id
            WHERE l.id = ?
        ");
        $stmt->execute([$lesson_id]);
        $lesson = $stmt->fetch();

        if (!$lesson) {
            echo json_encode(['status' => 'error', 'message' => 'جلسه مورد نظر یافت نشد.']);
            exit;
        }

        $course_stub = ['is_vip_only' => $lesson['course_vip_only']];
        if (!can_user_access_lesson($course_stub, $lesson, $sub_info, $is_admin)) {
            echo json_encode([
                'status'   => 'error',
                'need_vip' => true,
                'message'  => 'تماشای این جلسه و دریافت جزوه آن مخصوص مشترکین ویژه (VIP) است. لطفاً اشتراک ویژه تهیه نمایید.'
            ]);
            exit;
        }

        $aparat_hash = extract_aparat_video_hash($lesson['video_url']);
        $lesson['is_aparat']        = false;
        $lesson['aparat_hash']      = null;
        $lesson['aparat_embed_url'] = null;

        if ($aparat_hash !== null) {
            $ap_info = fetch_aparat_video_streams($aparat_hash);
            $lesson['is_aparat']        = true;
            $lesson['aparat_hash']      = $aparat_hash;
            $lesson['aparat_embed_url'] = $ap_info['aparat_embed_url'];

            if (!empty($ap_info['direct_mp4_main'])) {
                $lesson['video_url'] = $ap_info['direct_mp4_main'];
                if (empty($lesson['video_url_backup']) && !empty($ap_info['direct_mp4_bkp'])) {
                    $lesson['video_url_backup'] = $ap_info['direct_mp4_bkp'];
                }
            } else {
                $lesson['video_url'] = '';
            }

            if ((int)$lesson['duration_sec'] === 0 && $ap_info['duration_sec'] > 0) {
                $lesson['duration_sec'] = $ap_info['duration_sec'];
                $pdo->prepare("UPDATE course_lessons SET duration_sec = ? WHERE id = ?")
                    ->execute([$ap_info['duration_sec'], $lesson_id]);
            }
        }

        $pStmt = $pdo->prepare("SELECT * FROM video_progress WHERE user_id = ? AND lesson_id = ? LIMIT 1");
        $pStmt->execute([$user_id, $lesson_id]);
        $prog = $pStmt->fetch();

        $nStmt = $pdo->prepare("SELECT * FROM video_notes WHERE user_id = ? AND lesson_id = ? ORDER BY timestamp_sec ASC, id ASC");
        $nStmt->execute([$user_id, $lesson_id]);
        $notes = $nStmt->fetchAll();

        echo json_encode([
            'status'   => 'success',
            'lesson'   => $lesson,
            'progress' => [
                'current_time_sec' => $prog ? (int)$prog['current_time_sec'] : 0,
                'duration_sec'     => $prog ? (int)$prog['duration_sec'] : (int)$lesson['duration_sec'],
                'is_completed'     => $prog ? ((int)$prog['is_completed'] === 1) : false
            ],
            'notes'    => $notes
        ]);
        exit;
    }

    // ۴. خواندن محتوای خام زیرنویس (.srt یا .vtt) بدون مشکل CORS
    if ($action === 'get_subtitle_raw') {
        $lesson_id = (int)($_GET['lesson_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT l.*, c.is_vip_only AS course_vip_only
            FROM course_lessons l
            INNER JOIN courses c ON c.id = l.course_id
            WHERE l.id = ?
        ");
        $stmt->execute([$lesson_id]);
        $lesson = $stmt->fetch();

        if (!$lesson || empty($lesson['subtitle_url'])) {
            echo json_encode(['status' => 'error', 'message' => 'زیرنویسی برای این جلسه ثبت نشده است.']);
            exit;
        }

        if (!can_user_access_lesson(['is_vip_only' => $lesson['course_vip_only']], $lesson, $sub_info, $is_admin)) {
            echo json_encode(['status' => 'error', 'need_vip' => true, 'message' => 'نیازمند اشتراک ویژه']);
            exit;
        }

        $sub_url = trim($lesson['subtitle_url']);
        $raw_content = '';

        if (preg_match('#^https?://#i', $sub_url)) {
            $ch = curl_init($sub_url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (SmartVideoSubtitleLoader/4.4)'
            ]);
            $raw_content = curl_exec($ch);
            curl_close($ch);
        } else {
            $local_file = __DIR__ . '/' . ltrim($sub_url, '/');
            if (is_file($local_file)) {
                $raw_content = file_get_contents($local_file);
            }
        }

        if (empty($raw_content)) {
            echo json_encode(['status' => 'error', 'message' => 'فایل زیرنویس قابل خواندن نبود.']);
            exit;
        }

        $raw_content = preg_replace('/^\xEF\xBB\xBF/', '', $raw_content);

        echo json_encode([
            'status'  => 'success',
            'content' => $raw_content
        ]);
        exit;
    }

    // ۵. ذخیره خودکار ثانیه فعلی پخش ویدیو (Auto-Resume) و تشخیص تکمیل جلسه
    if ($action === 'save_progress') {
        $lesson_id        = (int)($_POST['lesson_id'] ?? 0);
        $course_id        = (int)($_POST['course_id'] ?? 0);
        $current_time_sec = max(0, (int)($_POST['current_time_sec'] ?? 0));
        $duration_sec     = max(0, (int)($_POST['duration_sec'] ?? 0));
        $force_completed  = isset($_POST['is_completed']) ? (int)$_POST['is_completed'] : null;

        if ($lesson_id <= 0 || $course_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'شناسه جلسه نامعتبر است.']);
            exit;
        }

        $auto_done = ($duration_sec > 30 && $current_time_sec >= ($duration_sec * 0.90)) ? 1 : 0;
        if ($force_completed !== null) {
            $auto_done = $force_completed ? 1 : 0;
        }

        $sql = "
            INSERT INTO video_progress
            (user_id, course_id, lesson_id, current_time_sec, duration_sec, max_watched_sec, is_completed)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                current_time_sec = VALUES(current_time_sec),
                duration_sec     = IF(VALUES(duration_sec) > 0, VALUES(duration_sec), duration_sec),
                max_watched_sec  = GREATEST(max_watched_sec, VALUES(current_time_sec)),
                is_completed     = GREATEST(is_completed, VALUES(is_completed))
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $course_id, $lesson_id, $current_time_sec, $duration_sec, $current_time_sec, $auto_done]);

        if ($duration_sec > 0) {
            $pdo->prepare("UPDATE course_lessons SET duration_sec = ? WHERE id = ? AND duration_sec = 0")
                ->execute([$duration_sec, $lesson_id]);
        }

        echo json_encode([
            'status'           => 'success',
            'current_time_sec' => $current_time_sec,
            'is_completed'     => (bool)$auto_done
        ]);
        exit;
    }

    // ۶. تغییر دستی وضعیت تکمیل یک جلسه توسط دانشجو
    if ($action === 'toggle_lesson_complete') {
        $lesson_id = (int)($_POST['lesson_id'] ?? 0);
        $course_id = (int)($_POST['course_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT is_completed FROM video_progress WHERE user_id = ? AND lesson_id = ? LIMIT 1");
        $stmt->execute([$user_id, $lesson_id]);
        $row = $stmt->fetch();

        $new_val = ($row && (int)$row['is_completed'] === 1) ? 0 : 1;

        $ins = $pdo->prepare("
            INSERT INTO video_progress (user_id, course_id, lesson_id, current_time_sec, duration_sec, max_watched_sec, is_completed)
            VALUES (?, ?, ?, 0, 0, 0, ?)
            ON DUPLICATE KEY UPDATE is_completed = VALUES(is_completed)
        ");
        $ins->execute([$user_id, $course_id, $lesson_id, $new_val]);

        echo json_encode([
            'status'       => 'success',
            'is_completed' => (bool)$new_val
        ]);
        exit;
    }

    // ۷. مدیریت یادداشت‌های زمان‌دار دانشجو روی ویدیو
    if ($action === 'save_note') {
        $lesson_id     = (int)($_POST['lesson_id'] ?? 0);
        $course_id     = (int)($_POST['course_id'] ?? 0);
        $timestamp_sec = max(0, (int)($_POST['timestamp_sec'] ?? 0));
        $note_text     = trim($_POST['note_text'] ?? '');

        if ($lesson_id <= 0 || empty($note_text)) {
            echo json_encode(['status' => 'error', 'message' => 'متن یادداشت نمی‌تواند خالی باشد.']);
            exit;
        }

        $ins = $pdo->prepare("
            INSERT INTO video_notes (user_id, course_id, lesson_id, timestamp_sec, note_text)
            VALUES (?, ?, ?, ?, ?)
        ");
        $ins->execute([$user_id, $course_id, $lesson_id, $timestamp_sec, $note_text]);

        echo json_encode([
            'status'  => 'success',
            'note_id' => (int)$pdo->lastInsertId(),
            'message' => 'یادداشت در ثانیه مشخص‌شده ثبت شد.'
        ]);
        exit;
    }

    if ($action === 'delete_note') {
        $note_id = (int)($_POST['note_id'] ?? 0);
        $pdo->prepare("DELETE FROM video_notes WHERE id = ? AND user_id = ?")->execute([$note_id, $user_id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // ۸. افزودن یک‌کلیکی جزوه PDF این جلسه به بخش «📚 جزوه‌خوان»
    if ($action === 'import_booklet_to_reader') {
        $lesson_id = (int)($_POST['lesson_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT l.*, c.title AS course_title, c.is_vip_only AS course_vip_only
            FROM course_lessons l
            INNER JOIN courses c ON c.id = l.course_id
            WHERE l.id = ?
        ");
        $stmt->execute([$lesson_id]);
        $lesson = $stmt->fetch();

        if (!$lesson || empty($lesson['booklet_url'])) {
            echo json_encode(['status' => 'error', 'message' => 'جزوه‌ای برای این جلسه ثبت نشده است.']);
            exit;
        }

        if (!can_user_access_lesson(['is_vip_only' => $lesson['course_vip_only']], $lesson, $sub_info, $is_admin)) {
            echo json_encode(['status' => 'error', 'need_vip' => true, 'message' => 'نیازمند اشتراک ویژه']);
            exit;
        }

        if (!$sub_info['is_vip'] && !$is_admin) {
            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM pdfs WHERE user_id = ?");
            $cntStmt->execute([$user_id]);
            if ((int)$cntStmt->fetchColumn() >= $sub_info['max_pdf_cnt']) {
                echo json_encode([
                    'status'   => 'error',
                    'need_vip' => true,
                    'message'  => 'سهمیه ۱ فایل رایگان جزوه‌خوان شما پر شده است. برای انتقال جزوات جلسات به جزوه‌خوان و هایلایت روی آن‌ها، اشتراک ویژه تهیه کنید.'
                ]);
                exit;
            }
        }

        $b_url = trim($lesson['booklet_url']);
        $pdfs_dir = __DIR__ . '/uploads/pdfs/';
        if (!is_dir($pdfs_dir)) @mkdir($pdfs_dir, 0755, true);

        $new_pdf_name = 'lesson_bkl_u' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.pdf';
        $dest_full    = $pdfs_dir . $new_pdf_name;
        $rel_db_path  = 'uploads/pdfs/' . $new_pdf_name;

        if (preg_match('#^https?://#i', $b_url)) {
            $ch = curl_init($b_url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 45,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $raw_pdf = curl_exec($ch);
            curl_close($ch);
            if (empty($raw_pdf) || strlen($raw_pdf) < 500) {
                echo json_encode(['status' => 'error', 'message' => 'امکان دریافت مستقیم فایل PDF از لینک خارجی نبود؛ لطفاً از دکمه دانلود مستقیم استفاده کنید.']);
                exit;
            }
            file_put_contents($dest_full, $raw_pdf);
        } else {
            $src_local = __DIR__ . '/' . ltrim($b_url, '/');
            if (!is_file($src_local) || strtolower(pathinfo($src_local, PATHINFO_EXTENSION)) !== 'pdf') {
                echo json_encode(['status' => 'error', 'message' => 'فقط جزوات با فرمت PDF قابلیت انتقال به محیط جزوه‌خوان را دارند.']);
                exit;
            }
            if (!copy($src_local, $dest_full)) {
                echo json_encode(['status' => 'error', 'message' => 'خطا در کپی کردن جزوه به پوشه جزوه‌خوان.']);
                exit;
            }
        }

        $pdf_title = $lesson['course_title'] . ' — ' . $lesson['title'];
        $insPdf = $pdo->prepare("
            INSERT INTO pdfs (user_id, folder_id, is_paper, title, category, file_path, total_pages, last_page, starred_pages)
            VALUES (?, NULL, 0, ?, ?, ?, 1, 1, '[]')
        ");
        $insPdf->execute([$user_id, $pdf_title, $lesson['course_title'], $rel_db_path]);

        echo json_encode([
            'status'  => 'success',
            'pdf_id'  => (int)$pdo->lastInsertId(),
            'message' => 'جزوه این جلسه به بخش «📚 جزوه‌خوان» شما اضافه شد!'
        ]);
        exit;
    }

    // ۹. ثبت جلسه ویدیویی تماشاشده در تقویم مطالعاتی امروز دانشجو
    if ($action === 'log_to_calendar') {
        $lesson_id = (int)($_POST['lesson_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT l.*, c.title AS course_title
            FROM course_lessons l
            INNER JOIN courses c ON c.id = l.course_id
            WHERE l.id = ?
        ");
        $stmt->execute([$lesson_id]);
        $lesson = $stmt->fetch();

        if (!$lesson) {
            echo json_encode(['status' => 'error', 'message' => 'جلسه یافت نشد.']);
            exit;
        }

        $today_g = date('Y-m-d');
        $today_j = course_gdate_to_jalali($today_g);
        $now_ts  = time();
        $dur_sec = max(1800, min(14400, (int)$lesson['duration_sec'] > 300 ? (int)$lesson['duration_sec'] : 2700));

        $end_time_str   = date('H:i:00', $now_ts);
        $start_time_str = date('H:i:00', max(strtotime($today_g . ' 00:00:00'), $now_ts - $dur_sec));
        if ($end_time_str <= $start_time_str) {
            $start_time_str = '16:00:00';
            $end_time_str   = '17:00:00';
        }

        $cal_title = '🎬 ویدیو: ' . $lesson['title'];
        $cal_desc  = 'تماشای جلسه آموزشی از دوره «' . $lesson['course_title'] . '»';

        $calIns = $pdo->prepare("
            INSERT INTO calendar_events
            (user_id, title, description, g_date, j_date, start_time, end_time, color, category_type, category_label, is_study, is_completed, send_sms, sms_sent)
            VALUES (?, ?, ?, ?, ?, ?, ?, '#4f46e5', 'specialized', 'تخصصی', 1, 1, 0, 0)
        ");
        $calIns->execute([$user_id, $cal_title, $cal_desc, $today_g, $today_j, $start_time_str, $end_time_str]);

        echo json_encode([
            'status'  => 'success',
            'message' => 'مدت زمان تماشای این جلسه به عنوان «✅ مطالعه انجام‌شده» در تقویم امروز شما ثبت شد!'
        ]);
        exit;
    }

    // ====================================================
    // ب) اکشن‌های اختصاصی پنل مدیریت ادمین (نیازمند دسترسی ادمین)
    // ====================================================
    if (!$is_admin) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'دسترسی محدود به مدیر کل سامانه است.']);
        exit;
    }

    // ۱۰. استعلام آنی مشخصات ویدیو از آپارات برای فرم پنل ادمین (عنوان، مدت زمان و لینک‌های کیفیت)
    if ($action === 'fetch_aparat_meta') {
        $raw_input = $_POST['video_url'] ?? ($_GET['video_url'] ?? '');
        $hash = extract_aparat_video_hash($raw_input);

        if ($hash === null) {
            echo json_encode(['status' => 'error', 'message' => 'لینک یا کد واردشده یک آدرس معتبر آپارات نیست.']);
            exit;
        }

        $ap_info = fetch_aparat_video_streams($hash);
        echo json_encode([
            'status'        => 'success',
            'aparat_hash'   => $hash,
            'clean_url'     => "https://www.aparat.com/v/{$hash}",
            'title'         => $ap_info['title'],
            'duration_sec'  => $ap_info['duration_sec'],
            'duration_mins' => $ap_info['duration_sec'] > 0 ? max(1, (int)round($ap_info['duration_sec'] / 60)) : 0,
            'has_direct_mp4'=> !empty($ap_info['direct_mp4_main']),
            'embed_url'     => $ap_info['aparat_embed_url']
        ]);
        exit;
    }

    // ۱۱. لیست تمام دوره‌ها + لیست آزمون‌ها برای پنل ادمین
    if ($action === 'admin_list_courses') {
        $stmt = $pdo->query("
            SELECT c.*,
                   (SELECT COUNT(*) FROM course_chapters ch WHERE ch.course_id = c.id) AS chapters_count,
                   (SELECT COUNT(*) FROM course_lessons l WHERE l.course_id = c.id) AS lessons_count,
                   (SELECT COUNT(DISTINCT vp.user_id) FROM video_progress vp WHERE vp.course_id = c.id) AS students_count
            FROM courses c
            ORDER BY c.sort_order ASC, c.id DESC
        ");
        $courses = $stmt->fetchAll();

        $exams_list = [];
        try {
            $exams_list = $pdo->query("SELECT id, title, category FROM exams ORDER BY id DESC")->fetchAll();
        } catch (Exception $ignore) {}

        echo json_encode([
            'status'  => 'success',
            'courses' => $courses,
            'exams'   => $exams_list
        ]);
        exit;
    }

    // ۱۲. دریافت ساختار کامل یک دوره برای ویرایش در پنل ادمین
    if ($action === 'admin_get_course') {
        $course_id = (int)($_GET['course_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
        $stmt->execute([$course_id]);
        $course = $stmt->fetch();

        if (!$course) {
            echo json_encode(['status' => 'error', 'message' => 'دوره یافت نشد.']);
            exit;
        }

        $chapStmt = $pdo->prepare("SELECT * FROM course_chapters WHERE course_id = ? ORDER BY sort_order ASC, id ASC");
        $chapStmt->execute([$course_id]);
        $chapters = $chapStmt->fetchAll();

        $lessStmt = $pdo->prepare("SELECT * FROM course_lessons WHERE course_id = ? ORDER BY sort_order ASC, id ASC");
        $lessStmt->execute([$course_id]);
        $lessons = $lessStmt->fetchAll();

        echo json_encode([
            'status'   => 'success',
            'course'   => $course,
            'chapters' => $chapters,
            'lessons'  => $lessons
        ]);
        exit;
    }

    // ۱۳. ساخت یا ویرایش دوره آموزشی توسط ادمین
    if ($action === 'save_course') {
        $id          = (int)($_POST['id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $instructor  = trim($_POST['instructor'] ?? '');
        $category    = trim($_POST['category'] ?? 'عمومی');
        $description = trim($_POST['description'] ?? '');
        $cover_image = trim($_POST['cover_image'] ?? '');
        $is_vip_only = (int)($_POST['is_vip_only'] ?? 1) === 1 ? 1 : 0;
        $is_active   = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;
        $sort_order  = max(1, (int)($_POST['sort_order'] ?? 1));

        if (empty($title)) {
            echo json_encode(['status' => 'error', 'message' => 'لطفاً عنوان دوره آموزشی را وارد کنید.']);
            exit;
        }

        $uploaded_cover = handle_course_file_upload('cover_file', ['jpg', 'jpeg', 'png', 'webp'], $covers_dir, 'uploads/courses/covers');
        if ($uploaded_cover) {
            $cover_image = $uploaded_cover;
        }

        if ($id > 0) {
            $upd = $pdo->prepare("
                UPDATE courses
                SET title = ?, instructor = ?, category = ?, description = ?, cover_image = ?,
                    is_vip_only = ?, is_active = ?, sort_order = ?
                WHERE id = ?
            ");
            $upd->execute([$title, $instructor ?: null, $category, $description ?: null, $cover_image ?: null, $is_vip_only, $is_active, $sort_order, $id]);
            echo json_encode(['status' => 'success', 'course_id' => $id, 'message' => 'مشخصات دوره بروزرسانی شد.']);
        } else {
            $ins = $pdo->prepare("
                INSERT INTO courses (title, instructor, category, description, cover_image, is_vip_only, is_active, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([$title, $instructor ?: null, $category, $description ?: null, $cover_image ?: null, $is_vip_only, $is_active, $sort_order]);
            $new_cid = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO course_chapters (course_id, title, sort_order) VALUES (?, 'فصل اول: مباحث پایه و مقدماتی', 1)")
                ->execute([$new_cid]);

            echo json_encode(['status' => 'success', 'course_id' => $new_cid, 'message' => 'دوره جدید ساخته شد؛ اکنون می‌توانید فصل‌ها و جلسات ویدیویی را اضافه کنید.']);
        }
        exit;
    }

    // ۱۴. حذف کامل یک دوره آموزشی
    if ($action === 'delete_course') {
        $course_id = (int)($_POST['course_id'] ?? 0);
        $pdo->prepare("DELETE FROM courses WHERE id = ?")->execute([$course_id]);
        echo json_encode(['status' => 'success', 'message' => 'دوره و جلسات آن حذف شدند.']);
        exit;
    }

    // ۱۵. ساخت یا ویرایش فصل (سرفصل) دوره
    if ($action === 'save_chapter') {
        $id         = (int)($_POST['id'] ?? 0);
        $course_id  = (int)($_POST['course_id'] ?? 0);
        $title      = trim($_POST['title'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);

        if ($course_id <= 0 || empty($title)) {
            echo json_encode(['status' => 'error', 'message' => 'لطفاً عنوان فصل را وارد کنید.']);
            exit;
        }

        if ($id > 0) {
            $upd = $pdo->prepare("UPDATE course_chapters SET title = ?, sort_order = ? WHERE id = ? AND course_id = ?");
            $upd->execute([$title, max(1, $sort_order), $id, $course_id]);
            echo json_encode(['status' => 'success', 'chapter_id' => $id, 'message' => 'عنوان فصل ویرایش شد.']);
        } else {
            if ($sort_order <= 0) {
                $mStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM course_chapters WHERE course_id = ?");
                $mStmt->execute([$course_id]);
                $sort_order = (int)$mStmt->fetchColumn() + 1;
            }
            $ins = $pdo->prepare("INSERT INTO course_chapters (course_id, title, sort_order) VALUES (?, ?, ?)");
            $ins->execute([$course_id, $title, $sort_order]);
            echo json_encode(['status' => 'success', 'chapter_id' => (int)$pdo->lastInsertId(), 'message' => 'فصل جدید به دوره اضافه شد.']);
        }
        exit;
    }

    // ۱۶. حذف یک فصل از دوره
    if ($action === 'delete_chapter') {
        $chapter_id = (int)($_POST['chapter_id'] ?? 0);
        $pdo->prepare("DELETE FROM course_chapters WHERE id = ?")->execute([$chapter_id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // ۱۷. ساخت یا ویرایش جلسه ویدیویی (با تشخیص خودکار لینک یا کد امبد آپارات)
    if ($action === 'save_lesson') {
        $id               = (int)($_POST['id'] ?? 0);
        $course_id        = (int)($_POST['course_id'] ?? 0);
        $chapter_id       = (int)($_POST['chapter_id'] ?? 0);
        $title            = trim($_POST['title'] ?? '');
        $description      = trim($_POST['description'] ?? '');
        $video_url        = normalize_admin_video_url_input($_POST['video_url'] ?? '');
        $video_url_backup = normalize_admin_video_url_input($_POST['video_url_backup'] ?? '');
        $duration_mins    = (float)($_POST['duration_mins'] ?? 0);
        $duration_sec     = isset($_POST['duration_sec']) && (int)$_POST['duration_sec'] > 0
            ? (int)$_POST['duration_sec']
            : (int)round($duration_mins * 60);
        $subtitle_url     = trim($_POST['subtitle_url'] ?? '');
        $booklet_url      = trim($_POST['booklet_url'] ?? '');
        $booklet_title    = trim($_POST['booklet_title'] ?? 'جزوه و نکات جلسه');
        $linked_exam_id   = !empty($_POST['linked_exam_id']) ? (int)$_POST['linked_exam_id'] : null;
        $is_free_preview  = (int)($_POST['is_free_preview'] ?? 0) === 1 ? 1 : 0;
        $sort_order       = (int)($_POST['sort_order'] ?? 0);

        if ($course_id <= 0 || $chapter_id <= 0 || empty($title) || empty($video_url)) {
            echo json_encode(['status' => 'error', 'message' => 'لطفاً فصل، عنوان جلسه و لینک ویدیو (یا لینک آپارات) را وارد نمایید.']);
            exit;
        }

        $ap_hash = extract_aparat_video_hash($video_url);
        if ($ap_hash !== null && $duration_sec <= 0) {
            $ap_meta = fetch_aparat_video_streams($ap_hash);
            if ($ap_meta['duration_sec'] > 0) {
                $duration_sec = $ap_meta['duration_sec'];
            }
        }

        $uploaded_sub = handle_course_file_upload('subtitle_file', ['srt', 'vtt', 'txt'], $subtitles_dir, 'uploads/courses/subtitles');
        if ($uploaded_sub) {
            $subtitle_url = $uploaded_sub;
        }

        $uploaded_booklet = handle_course_file_upload('booklet_file', ['pdf', 'zip', 'rar', 'doc', 'docx', 'png', 'jpg'], $booklets_dir, 'uploads/courses/booklets');
        if ($uploaded_booklet) {
            $booklet_url = $uploaded_booklet;
        }

        if ($id > 0) {
            $upd = $pdo->prepare("
                UPDATE course_lessons
                SET chapter_id = ?, title = ?, description = ?, video_url = ?, video_url_backup = ?,
                    duration_sec = ?, subtitle_url = ?, booklet_url = ?, booklet_title = ?,
                    linked_exam_id = ?, is_free_preview = ?, sort_order = ?
                WHERE id = ? AND course_id = ?
            ");
            $upd->execute([
                $chapter_id, $title, $description ?: null, $video_url, $video_url_backup ?: null,
                $duration_sec, $subtitle_url ?: null, $booklet_url ?: null, $booklet_title ?: 'جزوه جلسه',
                $linked_exam_id, $is_free_preview, max(1, $sort_order),
                $id, $course_id
            ]);
            echo json_encode(['status' => 'success', 'lesson_id' => $id, 'message' => 'اطلاعات جلسه ویدیویی بروزرسانی شد.']);
        } else {
            if ($sort_order <= 0) {
                $mStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM course_lessons WHERE chapter_id = ?");
                $mStmt->execute([$chapter_id]);
                $sort_order = (int)$mStmt->fetchColumn() + 1;
            }
            $ins = $pdo->prepare("
                INSERT INTO course_lessons
                (course_id, chapter_id, title, description, video_url, video_url_backup, duration_sec, subtitle_url, booklet_url, booklet_title, linked_exam_id, is_free_preview, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $course_id, $chapter_id, $title, $description ?: null, $video_url, $video_url_backup ?: null,
                $duration_sec, $subtitle_url ?: null, $booklet_url ?: null, $booklet_title ?: 'جزوه جلسه',
                $linked_exam_id, $is_free_preview, $sort_order
            ]);
            echo json_encode([
                'status'    => 'success',
                'lesson_id' => (int)$pdo->lastInsertId(),
                'message'   => 'جلسه ویدیویی جدید به فصل اضافه شد.'
            ]);
        }
        exit;
    }

    // ۱۸. حذف یک جلسه ویدیویی
    if ($action === 'delete_lesson') {
        $lesson_id = (int)($_POST['lesson_id'] ?? 0);
        $pdo->prepare("DELETE FROM course_lessons WHERE id = ?")->execute([$lesson_id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'اکشن درخواستی نامعتبر است.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'خطای سرور: ' . $e->getMessage()]);
}