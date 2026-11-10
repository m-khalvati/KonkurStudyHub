# 📚 Konkur Study Hub & Interactive PDF Reader (v3.0)

![Version](https://img.shields.io/badge/Version-3.0.0-indigo?style=flat-square)
![PHP](https://img.shields.io/badge/PHP-7.4%2B%20%7C%208.x-777BB4?style=flat-square&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B%20%7C%20MariaDB-4479A1?style=flat-square&logo=mysql&logoColor=white)
![TailwindCSS](https://img.shields.io/badge/Tailwind_CSS-3.x-38B2AC?style=flat-square&logo=tailwind-css&logoColor=white)
![PDF.js](https://img.shields.io/badge/Mozilla-PDF.js_3.11-FF7139?style=flat-square&logo=firefox&logoColor=white)

A full-stack, multi-user educational SaaS platform and study planner built with **PHP**, **MySQL**, **Tailwind CSS**, and **PDF.js**. 
Designed specifically for university entrance exam preparation (targeting the Iranian National Exam on **16 Bahman**), this web application combines a Google Calendar-style weekly/daily scheduler, an interactive high-DPI mobile-first PDF reader with real-time canvas annotations, automated **SMS.ir** overdue task alerts, and a built-in **Freemium/VIP Subscription & Admin Management System**.

---

## ✨ Key Features in v3.0

### 1. 📅 Smart Jalali Study Calendar (Locked to 16 Bahman)
* **Weekly & Mobile-First Daily Views:** Google Calendar-style 24-hour time grid (`00:00` to `23:59`) with a live current-time indicator and a 7-day horizontal quick-switch strip on mobile devices.
* **Side-by-Side Collision Layout:** Automatically clusters overlapping tasks and places them side-by-side in equal columns so concurrent events never hide each other, accompanied by a live overlap alert inside the scheduling modal.
* **Custom User Categories & Color Palette:** Includes 5 core system categories (*Specialized*, *Review*, *Practice Tests*, *Mock Exam*, *Miscellaneous*) plus the ability for users to create custom categories with a dedicated 10-color palette and classify them as **Study** or **Miscellaneous**.
* **Separated Study vs. Miscellaneous Analytics:** Strictly separates pure study hours from miscellaneous/personal tasks in both the top analytics bar and daily column header progress bars.
* **Konkur Test Percentage Calculator:** Built-in negative-marking formula $\frac{(\text{Correct} \times 3) - \text{Wrong}}{\text{Total} \times 3} \times 100$ that calculates and displays test performance badges on calendar cards.
* **Smart Productivity Tools:** Routine weekly task repetition (daily or alternate days), one-click **"Move to Tomorrow"**, **"Copy Full Day Schedule"**, and direct **Pomodoro Timer** linking.

### 2. 📖 Mobile-First Responsive PDF Reader & Canvas Engine
* **Zero-Error Binary Streaming (`HTTP 412` Proof):** Streams PDFs as raw binary buffers (`Uint8Array`) with multi-CDN fallback (`jsDelivr` -> `unpkg`) and isolated `LTR` canvas contexts for crystal-clear Persian and English typography across all mobile and desktop browsers.
* **Full-Screen Mode & Touch Swipe Gestures:** Dedicated full-screen reading view with a Floating Action Button (FAB) popup toolbar and natural left/right touch swipe page turning.
* **Subject Folder Organization:** Accordion-style subject folders with custom colors, direct per-folder uploads, and one-click PDF moving between folders.
* **Normalized Canvas Annotations:** Draw with a **Highlighter**, **Freehand Stylus/Pen**, **Sticky Notes**, or **Eraser**. Coordinates are normalized (`0.0` to `1.0`) and auto-saved as JSON in MySQL so drawings remain aligned at any zoom level or screen size.
* **Starred Pages (Bookmarks) & Night Mode:** Bookmark important formula pages (`⭐`) for quick jumping and toggle the eye-friendly dark mode filter.

### 3. 👑 Freemium / VIP Subscription & Admin Panel
* **Tiered Access Control:**
  * **Free Tier (Default):** Up to **1 PDF file** (max **3 MB**), full access to calendar scheduling, custom categories, test percentage calculator, and canvas annotations.
  * **VIP Tier (1-Month & 3-Month Plans):** **Unlimited PDF uploads** (up to **64 MB** per file), **automated SMS.ir overdue task alerts**, and full access to all archived booklets.
* **Cumulative Early Renewal:** Renewing a subscription before expiration automatically adds the new days (`+30` or `+90` days) to the user's remaining active balance without losing a single hour.
* **Non-Destructive Post-Expiry Locking:** If a VIP user's subscription expires while having multiple PDFs, no files or annotations are deleted—only additional PDFs beyond the free quota are locked (`🔒`) until renewal.
* **Telegram Deep-Linking & Instant Promo Codes:** The upgrade modal generates pre-filled Telegram order messages containing the user's name, username, phone number, and selected plan, alongside an instant one-time activation code redeemer.
* **Admin Management Dashboard (`admin.php`):** Allows the site owner to search users, extend/revoke VIP subscriptions with one click (`+1 Month`, `+3 Months`), and generate one-time activation codes (`VIP30-XXXX` / `VIP90-XXXX`).

---

## 🗂️ Project Structure

```text
konkur-study-hub/
├── admin.php             # Admin dashboard (User management, 1-click VIP charging & code generator)
├── api_calendar.php      # AJAX endpoint for calendar CRUD, custom categories, copy-day & stats
├── api_pdf.php           # AJAX endpoint for binary PDF streaming, folders, quotas & annotations
├── api_subscription.php  # AJAX endpoint for redeeming VIP codes & admin subscription actions
├── auth.php              # Multi-user authentication (Login / Registration with mobile number)
├── config.example.php    # Configuration template (Database, SMS.ir API, VIP quotas & Telegram ID)
├── cron_sms.php          # Background cron script for sending SMS.ir template alerts to VIP users
├── database.sql          # Complete MySQL database schema (7 relational tables)
├── index.php             # Main application UI (Calendar + PDF Reader + Pomodoro + VIP Modal)
├── uploads/              # Writable directory for uploaded PDFs (auto-protected against HTTP 412)
│   └── .gitkeep
├── .gitignore            # Excludes sensitive config.php and user-uploaded PDFs
└── README.md             # Project documentation
```

---

## 🗄️ Database Schema Overview (7 Tables)

| Table Name | Description | Key Columns |
| :--- | :--- | :--- |
| **`users`** | User accounts, mobile numbers, admin flag & VIP expiry | `id`, `username`, `phone`, `is_admin`, `vip_expires_at` |
| **`subscription_codes`** | One-time redeemable 1-month and 3-month VIP codes | `id`, `code`, `plan_type`, `days`, `is_used`, `used_by` |
| **`pdf_folders`** | Subject folders created by users | `id`, `user_id`, `name`, `color` |
| **`pdfs`** | Uploaded PDF metadata, folder links & starred pages | `id`, `user_id`, `folder_id`, `title`, `file_path`, `last_page`, `starred_pages` |
| **`pdf_annotations`** | Per-page normalized canvas drawings & sticky notes | `id`, `user_id`, `pdf_id`, `page_number`, `annotation_data` (JSON) |
| **`calendar_categories`** | User-defined custom task types and colors | `id`, `user_id`, `name`, `color`, `is_study` |
| **`calendar_events`** | Scheduled tasks up to 16 Bahman, SMS state & test scores | `id`, `user_id`, `g_date`, `j_date`, `is_study`, `send_sms`, `test_percent` |

---

## 🚀 Installation & Setup Guide

### Prerequisites
* **PHP** 7.4 or 8.x (with `pdo_mysql`, `curl`, `fileinfo`, and `mbstring` extensions enabled)
* **MySQL** 5.7+ or **MariaDB**
* Web Server: Apache / LiteSpeed / Nginx (XAMPP, Laragon, or cPanel shared hosting)

### Step 1: Clone the Repository
```bash
git clone [https://github.com/YOUR_USERNAME/konkur-study-hub.git](https://github.com/YOUR_USERNAME/konkur-study-hub.git)
cd konkur-study-hub
```

### Step 2: Create & Import the MySQL Database
1. Open **phpMyAdmin** and create a new database with `utf8mb4_persian_ci` collation:
   ```sql
   CREATE DATABASE konkur_db CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci;
   ```
2. Import `database.sql` via the **Import** tab in phpMyAdmin or via CLI:
   ```bash
   mysql -u your_username -p konkur_db < database.sql
   ```
   *(Note: The application also includes an auto-migration engine that automatically creates any missing tables or columns on first load).*

### Step 3: Configure `config.php`
1. Copy the template configuration file:
   ```bash
   cp config.example.php config.php
   ```
2. Open `config.php` and set your MySQL credentials, Telegram sales username, and `sms.ir` API details:
   ```php
   // 1. MySQL Database Credentials
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'konkur_db');
   define('DB_USER', 'your_mysql_user');
   define('DB_PASS', 'your_mysql_password');

   // 2. VIP Subscription & Telegram Sales Config
   define('TELEGRAM_SALES_ID', 'YourTelegramID'); // Without @
   define('FREE_MAX_PDF_COUNT', 1);               // Max PDFs for free users
   define('FREE_MAX_PDF_MB', 3);                  // Max size (MB) for free users
   define('VIP_MAX_PDF_MB', 64);                  // Max size (MB) for VIP users

   // 3. SMS.ir Fast/Verify Template Configuration
   define('SMSIR_ENABLED', true);
   define('SMSIR_API_KEY', 'YOUR_SMSIR_API_KEY');
   define('SMSIR_TEMPLATE_ID', 100000);
   ```

### Step 4: Set Directory Permissions & PHP Upload Limits
1. Ensure the `uploads/` folder is writable:
   ```bash
   chmod 755 uploads
   ```
2. To allow VIP users to upload large PDF textbooks (up to 64MB), add the following to `.user.ini` or `php.ini` in your hosting root:
   ```ini
   upload_max_filesize = 64M
   post_max_size = 64M
   max_execution_time = 300
   ```

### Step 5: Set Up the SMS Reminder Cron Job (Optional)
While the dashboard automatically triggers background checks every 3 minutes when open, you can configure a 24/7 server Cron Job in **cPanel -> Cron Jobs** (every 5 minutes):
```bash
curl -s "[https://yourdomain.com/cron_sms.php](https://yourdomain.com/cron_sms.php)" >/dev/null 2>&1
```

---

## 🛠️ Admin Access
* The **first registered user (`id = 1`)** is automatically granted **Admin (`is_admin = 1`)** privileges.
* Log in with your admin account and click the **🛠️ مدیریت (Admin)** button in the top navigation bar (or visit `/admin.php`) to manage users, activate 1-month/3-month VIP plans, or generate activation codes.

---

## 📄 License
This project is open-source and available under the [MIT License](https://opensource.org/licenses/MIT).