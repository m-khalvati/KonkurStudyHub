# 📚 Konkur Study Hub & Interactive PDF Reader

![PHP](https://img.shields.io/badge/PHP-7.4%2B%20%7C%208.x-777BB4?style=flat-square&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)
![TailwindCSS](https://img.shields.io/badge/Tailwind_CSS-3.x-38B2AC?style=flat-square&logo=tailwind-css&logoColor=white)
![PDF.js](https://img.shields.io/badge/Mozilla-PDF.js-FF7139?style=flat-square&logo=firefox&logoColor=white)

A full-stack, multi-user educational platform and study planner built with **PHP**, **MySQL**, **Tailwind CSS**, and **PDF.js**. 
Designed specifically for exam preparation (targeting the Iranian National Entrance Exam on **16 Bahman**), this web application combines a Google Calendar-style weekly/daily scheduler with a high-DPI responsive PDF reader and real-time canvas annotation engine.

---

## ✨ Key Features

### 1. 📅 Interactive Jalali Study Calendar (Locked to 16 Bahman)
* **Weekly & Daily Hourly Grid:** Google Calendar-style 24-hour time grid (`00:00` to `23:59`) with a live current-time indicator line.
* **Built-in Jalali (Shamsi) Engine:** Native bidirectional Gregorian-to-Jalali date conversion and automatic countdown timer to **16 Bahman**.
* **Exam Deadline Lock:** Automatically locks the calendar after 16 Bahman to keep study planning strictly bounded.
* **Direct PDF Deep-Linking:** Attach any uploaded PDF booklet and a specific starting page number directly to a calendar task for one-click launch.
* **Task Progress Tracking:** Categorize tasks by color (Specialized, Review, Practice Tests, Mock Exam) and track overall completion percentages.

### 2. 📖 Responsive PDF Reader & Annotation Engine
* **Cross-Device Compatibility:** Powered by Mozilla's `PDF.js` with automatic `Fit-to-Width` scaling and High-DPI (`devicePixelRatio`) rendering for crystal-clear text on mobile, tablet, and desktop screens.
* **Normalized Canvas Annotations:** Draw with a **Highlighter**, **Freehand Pen**, **Sticky Notes**, or **Eraser** using mouse, touch, or stylus. Coordinates are normalized (`0.0` to `1.0`) and stored as lightweight JSON in MySQL, ensuring annotations stay perfectly aligned across any screen size or zoom level.
* **Auto-Bookmarking:** Automatically saves and restores the last read page and calculates reading progress for each PDF.
* **Night Study Mode:** Built-in dark mode filter for comfortable late-night reading.

---

## 🗂️ Project Structure

```text
KonKurStudyHub/
├── api_calendar.php      # AJAX endpoint for calendar CRUD & progress stats
├── api_pdf.php           # AJAX endpoint for PDF uploads, progress & annotations
├── auth.php              # Multi-user authentication (Login / Register / Logout)
├── config.example.php    # Database connection template & Jalali core functions
├── database.sql          # MySQL database schema (4 relational tables)
├── index.php             # Main dashboard (Calendar UI + PDF Reader + Canvas)
├── uploads/              # Directory for user-uploaded PDF files (writable)
│   └── .gitkeep
├── .gitignore            # Excludes sensitive config.php and uploaded PDFs
└── README.md             # Project documentation
```

---

## 🗄️ Database Schema Overview

| Table Name | Description | Key Columns |
| :--- | :--- | :--- |
| **`users`** | Multi-user accounts & hashed passwords | `id`, `full_name`, `username`, `password_hash` |
| **`pdfs`** | Uploaded PDF metadata & reading state | `id`, `user_id`, `title`, `category`, `file_path`, `total_pages`, `last_page` |
| **`pdf_annotations`** | Per-page normalized canvas drawings | `id`, `user_id`, `pdf_id`, `page_number`, `annotation_data` (JSON) |
| **`calendar_events`** | Study tasks scheduled up to 16 Bahman | `id`, `user_id`, `title`, `g_date`, `j_date`, `start_time`, `end_time`, `pdf_id`, `pdf_page` |

---

## 🚀 Installation & Database Setup

### Prerequisites
* **PHP** >= 7.4 or 8.x (with `pdo_mysql` and `fileinfo` extensions enabled)
* **MySQL** >= 5.7 or **MariaDB**
* Web Server: Apache / Nginx / LiteSpeed (XAMPP, Laragon, or cPanel shared hosting)

### Step 1: Clone the Repository
```bash
git clone https://github.com/m-khalvati/KonKurStudyHub.git
cd KonKurStudyHub
```

### Step 2: Create & Import the MySQL Database
1. Open **phpMyAdmin** (or your MySQL CLI) and create a new database with `utf8mb4_persian_ci` collation:
   ```sql
   CREATE DATABASE konkur_db CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci;
   ```
2. Import the database schema from `database.sql`:
   * **Via phpMyAdmin:** Select your database -> Click the **Import** tab -> Choose `database.sql` -> Click **Go**.
   * **Via Terminal:**
     ```bash
     mysql -u your_username -p konkur_db < database.sql
     ```

### Step 3: Configure Database Credentials
1. Duplicate the example configuration file to create your active `config.php`:
   ```bash
   cp config.example.php config.php
   ```
2. Open `config.php` and update lines 9–12 with your MySQL credentials:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'konkur_db');
   define('DB_USER', 'your_mysql_user');
   define('DB_PASS', 'your_mysql_password');
   ```

### Step 4: Set Directory Permissions
Ensure the `uploads/` directory has write permissions so PHP can store uploaded PDF files:
```bash
chmod 755 uploads
```

### Step 5: Configure PHP Upload Limits (Recommended for cPanel)
To allow uploading large PDF textbooks (e.g., up to 64MB), add the following rules to your `.user.ini` or `php.ini` file on your server:
```ini
upload_max_filesize = 64M
post_max_size = 64M
max_execution_time = 300
```

---

## 🖥️ Usage

1. Open your browser and navigate to `http://localhost/KonKurStudyHub/auth.php` (or your domain URL).
2. Create a new user account on the **Register** tab and log in.
3. Switch to the **PDF Reader** tab to upload your study booklets, highlight key sections, and add sticky notes.
4. Use the **Study Calendar** tab to schedule daily or weekly study blocks up to **16 Bahman**, link specific PDF pages to your tasks, and track your completion percentage.

---

## 📄 License

This project is open-source and available under the [MIT License](https://opensource.org/licenses/MIT).