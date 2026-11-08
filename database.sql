SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ۱. جدول کاربران (چندکاربره)
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL UNIQUE,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ۲. جدول جزوات PDF (اختصاصی برای هر کاربر)
CREATE TABLE IF NOT EXISTS `pdfs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT 'عمومی',
  `file_path` varchar(255) NOT NULL,
  `total_pages` int(11) DEFAULT 1,
  `last_page` int(11) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_pdf` (`user_id`),
  CONSTRAINT `fk_pdfs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

-- ۳. جدول علامت‌گذاری‌های روی هر صفحه PDF (ذخیره به صورت JSON)
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

-- ۴. جدول رویدادهای تقویم (تا سقف ۱۶ بهمن)
CREATE TABLE IF NOT EXISTS `calendar_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `g_date` date NOT NULL COMMENT 'تاریخ میلادی برای محاسبات سریع هفتگی',
  `j_date` varchar(10) NOT NULL COMMENT 'تاریخ شمسی مثل 1405-11-16',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `color` varchar(20) DEFAULT '#3b82f6',
  `is_completed` tinyint(1) DEFAULT 0,
  `pdf_id` int(11) DEFAULT NULL,
  `pdf_page` int(11) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_date` (`user_id`, `g_date`),
  CONSTRAINT `fk_event_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_event_pdf` FOREIGN KEY (`pdf_id`) REFERENCES `pdfs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

SET FOREIGN_KEY_CHECKS = 1;

SET NAMES utf8mb4;

-- ۱. افزودن شماره موبایل به جدول کاربران
ALTER TABLE `users` 
  ADD COLUMN IF NOT EXISTS `phone` varchar(15) DEFAULT NULL AFTER `username`;

-- ۲. ساخت جدول پوشه‌بندی جزوات (برای تفکیک دروس)
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

-- ۳. افزودن شناسه پوشه و لیست صفحات ستاره‌دار (JSON) به جدول جزوات
ALTER TABLE `pdfs`
  ADD COLUMN IF NOT EXISTS `folder_id` int(11) DEFAULT NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `starred_pages` text DEFAULT NULL COMMENT 'آرایه JSON از صفحات مهم ستاره‌دار' AFTER `last_page`;

-- ۴. افزودن فیلدهای پیامک و محاسبه درصد تست به جدول رویدادهای تقویم
ALTER TABLE `calendar_events`
  ADD COLUMN IF NOT EXISTS `category_type` varchar(30) DEFAULT 'specialized' COMMENT 'specialized, review, test, exam, misc' AFTER `color`,
  ADD COLUMN IF NOT EXISTS `send_sms` tinyint(1) DEFAULT 1 COMMENT 'آیا در صورت عدم انجام پیامک ارسال شود؟' AFTER `is_completed`,
  ADD COLUMN IF NOT EXISTS `sms_sent` tinyint(1) DEFAULT 0 COMMENT 'آیا پیامک هشدار ارسال شده است؟' AFTER `send_sms`,
  ADD COLUMN IF NOT EXISTS `test_correct` int(11) DEFAULT NULL AFTER `pdf_page`,
  ADD COLUMN IF NOT EXISTS `test_wrong` int(11) DEFAULT NULL AFTER `test_correct`,
  ADD COLUMN IF NOT EXISTS `test_blank` int(11) DEFAULT NULL AFTER `test_wrong`,
  ADD COLUMN IF NOT EXISTS `test_percent` decimal(5,2) DEFAULT NULL AFTER `test_blank`;