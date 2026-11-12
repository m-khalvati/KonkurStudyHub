-- ============================================================================
-- Smart Study Planner, PDF Booklet Studio, Paper Reader, AI Exam & Video LMS
-- Version: 4.4 (Complete Database Schema - 18 Tables)
-- Database Charset: utf8mb4 / Collation: utf8mb4_persian_ci
-- ============================================================================

SET NAMES utf8mb4;
SET TIME_ZONE = '+03:30';
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. جدول کاربران و وضعیت اشتراک ویژه (users)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(120) NOT NULL,
  `username` varchar(60) NOT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=مدیر کل, 0=کاربر',
  `vip_expires_at` datetime DEFAULT NULL COMMENT 'تاریخ انقضای اشتراک ویژه',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ----------------------------------------------------------------------------
-- 2. جدول کدهای یک‌بارمصرف فعال‌سازی اشتراک ویژه (subscription_codes)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscription_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `plan_type` varchar(20) NOT NULL COMMENT '1m یا 3m',
  `days` int(11) NOT NULL DEFAULT 30,
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `used_by` int(11) DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_promo_code` (`code`),
  KEY `idx_used_by` (`used_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ----------------------------------------------------------------------------
-- 3. جدول دسته‌بندی‌ها و رنگ‌های سفارشی تقویم (calendar_categories)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `calendar_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `name` varchar(60) NOT NULL,
  `color` varchar(20) NOT NULL DEFAULT '#0891b2',
  `is_study` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=درسی, 0=متفرقه',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_cat` (`user_id`),
  CONSTRAINT `fk_cat_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ----------------------------------------------------------------------------
-- 4. جدول پوشه‌های درسی جزوه‌خوان (pdf_folders)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pdf_folders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `color` varchar(20) NOT NULL DEFAULT '#4f46e5',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_folder_user` (`user_id`),
  CONSTRAINT `fk_folder_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ----------------------------------------------------------------------------
-- 5. جدول فایل‌های PDF (جزوات کنکوری و مقالات انگلیسی) (pdfs)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pdfs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `folder_id` int(11) DEFAULT NULL,
  `is_paper` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=جزوه درسی, 1=مقاله علمی انگلیسی',
  `title` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT 'عمومی',
  `file_path` varchar(255) NOT NULL,
  `total_pages` int(11) NOT NULL DEFAULT 1,
  `last_page` int(11) NOT NULL DEFAULT 1,
  `starred_pages` text DEFAULT NULL COMMENT 'آرایه JSON صفحات ستاره‌دار',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pdf_user` (`user_id`, `is_paper`),
  KEY `idx_pdf_folder` (`folder_id`),
  CONSTRAINT `fk_pdf_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pdf_folder` FOREIGN KEY (`folder_id`) REFERENCES `pdf_folders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ----------------------------------------------------------------------------
-- 6. جدول لایه‌های حاشیه‌نویسی، هایلایت و دست‌خط روی جزوات (pdf_annotations)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pdf_annotations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `pdf_id` int(11) NOT NULL,
  `page_number` int(11) NOT NULL,
  `annotation_data` longtext DEFAULT NULL COMMENT 'داده‌های برداری JSON با مختصات نرمال‌شده 0 تا 1',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_pdf_page` (`user_id`, `pdf_id`, `page_number`),
  CONSTRAINT `fk_annot_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_annot_pdf` FOREIGN KEY (`pdf_id`) REFERENCES `pdfs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ----------------------------------------------------------------------------
-- 7. جدول رویدادها و برنامه‌های تقویم مطالعاتی (calendar_events)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `calendar_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `g_date` date NOT NULL COMMENT 'تاریخ میلادی YYYY-MM-DD',
  `j_date` varchar(15) NOT NULL COMMENT 'تاریخ شمسی YYYY-MM-DD',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `color` varchar(20) NOT NULL DEFAULT '#4f46e5',
  `category_type` varchar(30) NOT NULL DEFAULT 'specialized',
  `category_label` varchar(60) NOT NULL DEFAULT 'تخصصی',
  `is_study` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=برنامه درسی, 0=کار متفرقه',
  `is_completed` tinyint(1) NOT NULL DEFAULT 0,
  `send_sms` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'پیش‌فرض غیرفعال (0)',
  `sms_sent` tinyint(1) NOT NULL DEFAULT 0,
  `pdf_id` int(11) DEFAULT NULL,
  `pdf_page` int(11) DEFAULT 1,
  `test_correct` int(11) DEFAULT NULL,
  `test_wrong` int(11) DEFAULT NULL,
  `test_blank` int(11) DEFAULT NULL,
  `test_percent` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_gdate` (`user_id`, `g_date`),
  KEY `idx_sms_cron` (`is_completed`, `send_sms`, `sms_sent`, `g_date`),
  CONSTRAINT `fk_event_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_event_pdf` FOREIGN KEY (`pdf_id`) REFERENCES `pdfs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ----------------------------------------------------------------------------
-- 8. جدول کش دائمی ترجمه‌ها و خلاصه‌های علمی (translation_cache)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `translation_cache` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_hash` char(32) NOT NULL,
  `engine` varchar(30) NOT NULL DEFAULT 'google',
  `source_text` text NOT NULL,
  `translated_text` text NOT NULL,
  `keywords_json` text DEFAULT NULL COMMENT 'آرایه JSON واژگان تخصصی استخراج‌شده',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_hash_engine` (`source_hash`, `engine`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ----------------------------------------------------------------------------
-- 9. جدول فیش‌های پژوهشی و یادداشت‌های مقاله‌خوان (paper_notes)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `paper_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `pdf_id` int(11) NOT NULL,
  `page_number` int(11) NOT NULL DEFAULT 1,
  `para_hash` varchar(64) DEFAULT NULL,
  `original_text` text NOT NULL,
  `translated_text` text NOT NULL,
  `user_comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pnote_user_pdf` (`user_id`, `pdf_id`),
  CONSTRAINT `fk_pnote_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pnote_pdf` FOREIGN KEY (`pdf_id`) REFERENCES `pdfs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ----------------------------------------------------------------------------
-- 10. جدول آزمون‌های آنلاین (exams)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT 'عمومی',
  `description` text DEFAULT NULL,
  `duration_mins` int(11) NOT NULL DEFAULT 30,
  `negative_marking` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=با نمره منفی کنکور (۱ به ۳), 0=بدون نمره منفی',
  `is_vip_only` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=ویژه مشترکین VIP, 0=عمومی',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=منتشرشده, 0=پیش‌نویس',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ----------------------------------------------------------------------------
-- 11. جدول بانک سوالات آزمون (متنی، شبه‌کد و تصویری) (exam_questions)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exam_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 1,
  `question_text` text DEFAULT NULL,
  `question_image` varchar(255) DEFAULT NULL COMMENT 'تصویر نمودار پیوست یا برش کامل سوال',
  `option_1` text DEFAULT NULL,
  `option_2` text DEFAULT NULL,
  `option_3` text DEFAULT NULL,
  `option_4` text DEFAULT NULL,
  `correct_option` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'گزینه صحیح از 1 تا 4',
  `explanation` text DEFAULT NULL COMMENT 'پاسخ تشریحی',
  `explanation_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exam_q` (`exam_id`, `sort_order`),
  CONSTRAINT `fk_eq_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ----------------------------------------------------------------------------
-- 12. جدول کارنامه‌ها و سوابق شرکت در آزمون (exam_attempts)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exam_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `total_questions` int(11) NOT NULL DEFAULT 0,
  `correct_count` int(11) NOT NULL DEFAULT 0,
  `wrong_count` int(11) NOT NULL DEFAULT 0,
  `blank_count` int(11) NOT NULL DEFAULT 0,
  `score_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `time_spent_sec` int(11) NOT NULL DEFAULT 0,
  `answers_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_exam_att` (`user_id`, `exam_id`),
  CONSTRAINT `fk_att_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_att_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ----------------------------------------------------------------------------
-- 13. جدول مانیتورینگ و آمار مصرف استخر کلیدهای هوش مصنوعی (ai_key_stats)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_key_stats` (
  `key_hash` char(32) NOT NULL,
  `key_masked` varchar(40) NOT NULL,
  `total_calls` int(11) NOT NULL DEFAULT 0,
  `success_calls` int(11) NOT NULL DEFAULT 0,
  `error_calls` int(11) NOT NULL DEFAULT 0,
  `today_calls` int(11) NOT NULL DEFAULT 0,
  `last_call_date` date DEFAULT NULL,
  `translate_calls` int(11) NOT NULL DEFAULT 0,
  `exam_calls` int(11) NOT NULL DEFAULT 0,
  `last_http_code` int(11) DEFAULT NULL,
  `last_model` varchar(60) DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ----------------------------------------------------------------------------
-- 14. جدول دوره‌های ویدیویی آموزشی (courses)
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- 15. جدول فصل‌ها و سرفصل‌های هر دوره ویدیویی (course_chapters)
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- 16. جدول جلسات ویدیویی، زیرنویس‌ها، جزوات و لینک آپارات (course_lessons)
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- 17. جدول ذخیره ثانیه‌به‌ثانیه پیشرفت پخش ویدیو برای هر کاربر (video_progress)
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- 18. جدول یادداشت‌های زمان‌دار دانشجو روی ویدیو (video_notes)
-- ----------------------------------------------------------------------------
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

SET FOREIGN_KEY_CHECKS = 1;