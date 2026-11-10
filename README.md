# 📚 Konkur Study Hub, Interactive PDF Annotator & AI Academic Paper Reader (v3.8)

![Version](https://img.shields.io/badge/Version-3.8.0-indigo?style=flat-square)
![PHP](https://img.shields.io/badge/PHP-7.4%2B%20%7C%208.x-777BB4?style=flat-square&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B%20%7C%20MariaDB-4479A1?style=flat-square&logo=mysql&logoColor=white)
![TailwindCSS](https://img.shields.io/badge/Tailwind_CSS-3.x-38B2AC?style=flat-square&logo=tailwind-css&logoColor=white)
![PDF.js](https://img.shields.io/badge/Mozilla-PDF.js_3.11-FF7139?style=flat-square&logo=firefox&logoColor=white)
![Gemini AI](https://img.shields.io/badge/Google_Gemini-Multi--Key_Pool-4285F4?style=flat-square&logo=google&logoColor=white)

A full-stack, multi-user educational SaaS platform and academic research suite built with **PHP**, **MySQL**, **Tailwind CSS**, and **PDF.js**.  
Originally engineered for university entrance exam preparation (targeting **16 Bahman**) and graduate-level academic research, this platform unifies four core modules into a single high-performance web application:
1. **Smart Jalali Study Calendar** with side-by-side collision layout and negative-marking test analytics.
2. **Interactive Booklet PDF Reader** with subject folders and normalized canvas drawing/highlighting.
3. **AI-Powered Academic Paper Reader (`paper_reader.php`)** featuring a custom **Dot-to-Dot (Sentence-by-Sentence)** two-column layout parser, multi-engine translation, multi-voice TTS, and Word/PDF research note exporting.
4. **Freemium / VIP Subscription & Admin Management System** with automated **SMS.ir** overdue task alerts and Telegram order deep-linking.

---

## ✨ Key Features

### 1. 🌐 AI Academic Paper Reader & Dot-to-Dot Translator (`paper_reader.php`)
* **Strict Two-Column Isolation & Dot-to-Dot Segmentation:** Custom spatial clustering algorithm that detects central column gutters in IEEE/Elsevier double-column papers, orders reading zones naturally (*Top Full-Width* ➔ *Left Column* ➔ *Right Column*), and slices paragraphs from **previous period to next period** while ignoring academic abbreviations (`et al.`, `Fig.`, `Eq.`, `e.g.`, `i.e.`, decimals).
* **Dual Granularity Modes & Sequential Navigation:** Switch on the fly between **🎯 Sentence Mode (Dot-to-Dot)** and **📄 Paragraph Mode**, or step through sentences sequentially using the **Next / Previous Sentence** controls.
* **Multi-Engine Translation Gateway (`api_translate.php`):**
  * **Google Translate GTX (Free):** Sub-second translation for general reading.
  * **MyMemory API (Free):** Translation memory engine with automatic chunking.
  * **Academic AI Engine (VIP):** Powered by a **Multi-Key & Multi-Model Rotation Pool** (`gemini-3.7-flash`, `gemini-3.6-flash`, `gemini-3.5-flash`, `gemma-4-31b-it`). Preserves technical English terminology in parentheses—e.g., `مطالعه حذفی (Ablation Study)`—and automatically falls back if a model experiences `HTTP 503 / 429` load spikes.
* **Key Takeaway Summarizer & Academic Vocabulary Extractor:** One-click AI summarization (`✨ پیام کلیدی و خلاصه`) that condenses complex paragraphs into bulleted insights and extracts 3–5 interactive academic keyword pills with click-to-pronounce audio.
* **Multi-Voice & Multi-Speed TTS Audio Bar:** Built-in Web Speech synthesizer supporting all system English voices (`US`, `UK`, male/female operators), 5 playback speeds (`0.5x` to `1.5x`), Play/Pause/Resume/Stop controls, and instant inline substring lookup (`🔍 معنی فوری`).
* **Visual Reading & Note Tracking on PDF:** Highlights active sentences in deep indigo, visited sentences in soft sky blue, and saved research notes in emerald green with a `📌` badge directly on the PDF canvas.
* **Export Research Notes to Word (`.doc`) & Printable PDF:** Searchable research notebook per paper with one-click export to formatted Microsoft Word documents and Vazirmatn-styled printable PDF reports.

### 2. 📅 Smart Jalali Study Calendar (Locked to 16 Bahman)
* **Weekly & Mobile-First Daily Views:** 24-hour time grid (`00:00` to `23:59`) with a live current-time indicator and a 7-day quick-switch strip on mobile.
* **Side-by-Side Overlap Engine:** Automatically places concurrent tasks in equal side-by-side columns with live conflict warnings inside the scheduling modal.
* **Custom User Categories & Separated Analytics:** 5 default categories plus custom user-defined categories and a 10-color palette. Strictly separates **Pure Study Hours (📚)** from **Miscellaneous Tasks (🧩)** in all progress bars.
* **Konkur Negative-Marking Calculator:** Calculates $\frac{(\text{Correct} \times 3) - \text{Wrong}}{\text{Total} \times 3} \times 100$ and displays test score badges on calendar cards.
* **Productivity Suite:** Routine weekly task repetition, one-click **"Move to Tomorrow"**, **"Copy Full Day Schedule"**, and integrated **Pomodoro / Stopwatch Timer**.

### 3. 📖 Interactive Booklet PDF Reader (`index.php`)
* **Zero-Error Binary Streaming (`HTTP 412` Proof):** Streams PDFs as raw binary `Uint8Array` buffers with multi-CDN fallback (`jsDelivr` ➔ `unpkg`) and isolated `LTR` canvas contexts for flawless Persian and English font rendering.
* **Accordion Subject Folders:** Organize booklets into color-coded subject folders with direct per-folder uploads.
* **Normalized Canvas Annotations:** Highlighter, Freehand Pen, Sticky Notes, and Eraser stored as normalized JSON coordinates (`0.0`–`1.0`) in MySQL, plus Starred Pages (`⭐`) and Night Mode (`🌙`).

### 4. 👑 Freemium / VIP Subscription & Admin Dashboard (`admin.php`)
* **Free vs. VIP Quotas:**
  * **Free Users:** Up to **1 PDF file** (max **3 MB**), free translation engines (Google & MyMemory), full calendar & annotation tools.
  * **VIP Users (1-Month & 3-Month Plans):** **Unlimited PDF & Paper uploads** (up to **64 MB** per file), **AI Academic Translator & Summarizer**, and **automated SMS.ir overdue task alerts**.
* **Cumulative Renewal & Non-Destructive Locking:** Early renewals add days (`+30` or `+90`) to the existing expiration timestamp. After expiration, extra files are safely locked (`🔒`) rather than deleted.
* **Telegram Deep-Linking & Instant Promo Codes:** Pre-filled Telegram order links with user metadata alongside instant one-time promo code redemption (`VIP30-XXXX` / `VIP90-XXXX`).

---

## 🗂️ Project Structure

```text
konkur-study-hub/
├── admin.php             # Admin dashboard (User management, 1-click VIP charging & code generator)
├── api_calendar.php      # AJAX endpoint for calendar CRUD, custom categories, copy-day & stats
├── api_pdf.php           # AJAX endpoint for binary booklet streaming, folders, quotas & annotations
├── api_subscription.php  # AJAX endpoint for redeeming VIP codes & admin subscription actions
├── api_translate.php     # Multi-engine translation gateway (Google, MyMemory, AI Pool, Cache & Notes)
├── auth.php              # Multi-user authentication (Login / Registration with mobile number)
├── config.example.php    # Configuration template (Database, SMS.ir, AI Key Pool & VIP quotas)
├── cron_sms.php          # Background cron script for sending SMS.ir template alerts to VIP users
├── database.sql          # Complete MySQL database schema (9 relational tables)
├── index.php             # Main dashboard (Smart Jalali Calendar + Booklet PDF Reader + Pomodoro)
├── paper_reader.php      # Standalone AI Academic Paper Reader (Dot-to-Dot parser, TTS & Word/PDF export)
├── uploads/              # Writable directory for uploaded PDFs (auto-protected against HTTP 412)
│   └── papers/           # Dedicated subdirectory for English academic papers
└── README.md             # Project documentation
```

---

## 🗄️ Database Schema Overview (9 Tables)

| # | Table Name | Purpose | Key Columns |
| :- | :--- | :--- | :--- |
| 1 | **`users`** | User accounts, mobile numbers, admin role & VIP expiry | `id`, `username`, `phone`, `is_admin`, `vip_expires_at` |
| 2 | **`subscription_codes`** | One-time redeemable 1-month and 3-month VIP codes | `id`, `code`, `plan_type`, `days`, `is_used`, `used_by` |
| 3 | **`pdf_folders`** | Subject folders for organizing study booklets | `id`, `user_id`, `name`, `color` |
| 4 | **`pdfs`** | Booklets (`is_paper=0`) and academic papers (`is_paper=1`) | `id`, `user_id`, `folder_id`, `is_paper`, `file_path`, `starred_pages` |
| 5 | **`pdf_annotations`** | Per-page normalized canvas drawings & sticky notes | `id`, `user_id`, `pdf_id`, `page_number`, `annotation_data` |
| 6 | **`calendar_categories`** | User-defined custom task categories and colors | `id`, `user_id`, `name`, `color`, `is_study` |
| 7 | **`calendar_events`** | Scheduled tasks up to 16 Bahman, SMS state & test scores | `id`, `user_id`, `g_date`, `j_date`, `is_study`, `send_sms`, `test_percent` |
| 8 | **`translation_cache`** | MD5-indexed cache for translations, AI summaries & keywords | `id`, `source_hash`, `engine`, `translated_text`, `keywords_json` |
| 9 | **`paper_notes`** | Saved research notes linked to specific paper sentences | `id`, `user_id`, `pdf_id`, `page_number`, `para_hash`, `translated_text` |

---

## 🚀 Installation & Setup Guide

### Prerequisites
* **PHP** 7.4 or 8.x (with `pdo_mysql`, `curl`, `fileinfo`, and `mbstring` extensions enabled)
* **MySQL** 5.7+ or **MariaDB**
* Web Server: Apache / LiteSpeed / Nginx (XAMPP, Laragon, or cPanel / DirectAdmin shared hosting)

### Step 1: Clone the Repository
```bash
git clone [https://github.com/YOUR_USERNAME/konkur-study-hub.git](https://github.com/YOUR_USERNAME/konkur-study-hub.git)
cd konkur-study-hub
```

### Step 2: Create & Import the MySQL Database
1. Create a new MySQL database with `utf8mb4_persian_ci` collation:
   ```sql
   CREATE DATABASE konkur_db CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci;
   ```
2. Import `database.sql` via **phpMyAdmin** or CLI:
   ```bash
   mysql -u your_username -p konkur_db < database.sql
   ```

### Step 3: Configure `config.php`
1. Duplicate `config.example.php` and rename it to `config.php`:
   ```bash
   cp config.example.php config.php
   ```
2. Open `config.php` and configure your database credentials, Telegram ID, `sms.ir` API key, and your Gemini API key pool:
   ```php
   // 1. Database Credentials
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'konkur_db');
   define('DB_USER', 'your_mysql_user');
   define('DB_PASS', 'your_mysql_password');

   // 2. VIP Subscription & Telegram Sales Config
   define('TELEGRAM_SALES_ID', 'YourTelegramID'); // Without @
   define('FREE_MAX_PDF_COUNT', 1);
   define('FREE_MAX_PDF_MB', 3);
   define('VIP_MAX_PDF_MB', 64);

   // 3. AI Academic Paper Translator (Multi-Key Pool)
   define('AI_TRANSLATION_ENABLED', true);
   define('AI_VIP_ONLY', true);
   define('AI_PROVIDER', 'gemini');
   define('AI_MODEL_NAME', 'gemini-3.7-flash');
   define('AI_API_KEYS', [
       'YOUR_GEMINI_API_KEY_1',
       'YOUR_GEMINI_API_KEY_2',
   ]);
   ```

### Step 4: Directory Permissions & Upload Limits
1. Ensure `uploads/` and `uploads/papers/` are writable (`chmod 755`).
2. To allow VIP users to upload large PDFs (up to 64MB), add these lines to `.user.ini` or `php.ini`:
   ```ini
   upload_max_filesize = 64M
   post_max_size = 64M
   max_execution_time = 300
   ```

---

## 📄 License
This project is open-source and available under the [MIT License](https://opensource.org/licenses/MIT).