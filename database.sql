SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ====================================================
-- 1. جدول کاربران (پشتیبانی از چندکاربره، ادمین و اشتراک ویژه VIP)
-- ====================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL UNIQUE,
  `phone` varchar(15) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_admin` tinyint(1) DEFAULT 0 COMMENT '1=مدیر کل سایت, 0=کاربر عادی/ویژه',
  `vip_expires_at` datetime DEFAULT NULL COMMENT 'تاریخ و ساعت انقضای اشتراک ویژه',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ====================================================
-- 2. جدول کدهای فعال‌سازی یک‌بارمصرف اشتراک (۱ ماهه و ۳ ماهه)
-- ====================================================
CREATE TABLE IF NOT EXISTS `subscription_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL UNIQUE,
  `plan_type` varchar(20) NOT NULL COMMENT '1m یا 3m',
  `days` int(11) NOT NULL DEFAULT 30,
  `is_used` tinyint(1) DEFAULT 0,
  `used_by` int(11) DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_code_used_by` (`used_by`),
  CONSTRAINT `fk_subcode_user` FOREIGN KEY (`used_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ====================================================
-- 3. جدول پوشه‌بندی درسی جزوات PDF
-- ====================================================
CREATE TABLE IF NOT EXISTS `pdf_folders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `color` varchar(20) DEFAULT '#4f46e5',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_folder` (`user_id`),
  CONSTRAINT `fk_folder_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ====================================================
-- 4. جدول جزوات PDF (به همراه پوشه، آخرین صفحه و صفحات ستاره‌دار)
-- ====================================================
CREATE TABLE IF NOT EXISTS `pdfs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `folder_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT 'عمومی',
  `file_path` varchar(255) NOT NULL,
  `total_pages` int(11) DEFAULT 1,
  `last_page` int(11) DEFAULT 1,
  `starred_pages` text DEFAULT NULL COMMENT 'آرایه JSON از شماره صفحات مهم ستاره‌دار',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_pdf` (`user_id`),
  KEY `idx_folder_pdf` (`folder_id`),
  CONSTRAINT `fk_pdfs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pdfs_folder` FOREIGN KEY (`folder_id`) REFERENCES `pdf_folders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ====================================================
-- 5. جدول علامت‌گذاری‌های روی هر صفحه PDF (مختصات نسبی JSON)
-- ====================================================
CREATE TABLE IF NOT EXISTS `pdf_annotations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `pdf_id` int(11) NOT NULL,
  `page_number` int(11) NOT NULL,
  `annotation_data` longtext NOT NULL,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_pdf_page` (`user_id`, `pdf_id`, `page_number`),
  CONSTRAINT `fk_annot_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_annot_pdf` FOREIGN KEY (`pdf_id`) REFERENCES `pdfs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ====================================================
-- 6. جدول دسته‌بندی‌ها و رنگ‌های سفارشی تقویم (تعریف‌شده توسط کاربر)
-- ====================================================
CREATE TABLE IF NOT EXISTS `calendar_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `name` varchar(60) NOT NULL,
  `color` varchar(20) NOT NULL,
  `is_study` tinyint(1) DEFAULT 1 COMMENT '1=جزو ساعات مطالعه درسی, 0=جزو کارهای متفرقه',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_cal_cat` (`user_id`),
  CONSTRAINT `fk_calcat_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ====================================================
-- 7. جدول رویدادها و برنامه‌های تقویم (تا سقف ۱۶ بهمن)
-- ====================================================
CREATE TABLE IF NOT EXISTS `calendar_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `g_date` date NOT NULL COMMENT 'تاریخ میلادی برای محاسبات سریع هفتگی',
  `j_date` varchar(10) NOT NULL COMMENT 'تاریخ شمسی مثل 1405-11-16',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `color` varchar(20) DEFAULT '#4f46e5',
  `category_type` varchar(30) DEFAULT 'specialized',
  `category_label` varchar(60) DEFAULT 'تخصصی',
  `is_study` tinyint(1) DEFAULT 1 COMMENT '1=درسی و مطالعه, 0=کارهای متفرقه',
  `is_completed` tinyint(1) DEFAULT 0,
  `send_sms` tinyint(1) DEFAULT 1 COMMENT 'آیا در صورت عدم انجام پیامک ارسال شود؟',
  `sms_sent` tinyint(1) DEFAULT 0 COMMENT 'آیا پیامک هشدار ارسال شده است؟',
  `pdf_id` int(11) DEFAULT NULL,
  `pdf_page` int(11) DEFAULT 1,
  `test_correct` int(11) DEFAULT NULL,
  `test_wrong` int(11) DEFAULT NULL,
  `test_blank` int(11) DEFAULT NULL,
  `test_percent` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_date` (`user_id`, `g_date`),
  CONSTRAINT `fk_event_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_event_pdf` FOREIGN KEY (`pdf_id`) REFERENCES `pdfs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SET FOREIGN_KEY_CHECKS = 1;