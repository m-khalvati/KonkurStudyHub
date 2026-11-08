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