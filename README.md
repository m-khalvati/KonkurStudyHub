# 🎯 Smart Konkur Study Planner, PDF Booklet Studio, Academic Paper Reader & AI Exam LMS (v4.3)

An all-in-one, offline-resilient **PHP & MySQL** web application designed for university entrance exam (**Konkur**) preparation and academic research. The platform combines an interactive **Jalali/Gregorian Study Calendar**, a **Vector PDF Annotation & Booklet Studio**, a **Dual-Column Academic Paper Reader with Multi-Engine AI Translation**, and an **Autonomous AI Exam Generator & Online Testing Suite**.

---

## ✨ Key Features

### 1. 📅 Interactive Jalali Study Calendar (`index.php` & `api_calendar.php`)
* **Google-Calendar-Style Interactions:**
  * **Drag & Drop Tasks:** Smoothly drag tasks across hours or between different days of the week on both desktop (mouse) and mobile (touch long-press) with 15-minute snap intervals.
  * **Interactive Resize Handle (`═`):** Drag the bottom edge of any task card to extend or shorten its duration in real time.
  * **Drag-to-Create:** Drag across empty time slots on the calendar grid to visually select a time range and open the task creation modal pre-filled with the exact start and end times.
* **Konkur Test Score Calculator:** Built-in negative-marking percentage calculator:
  $$\text{Score (\%)} = \frac{(3 \times \text{Correct}) - \text{Wrong}}{3 \times \text{Total}} \times 100$$
* **Weekly Analytics & Overdue Backlog:** Visual bar charts comparing planned vs. completed study hours per day, category breakdown, and one-click rescheduling for overdue tasks.
* **Linked Pomodoro & Stopwatch Timer:** Start a 25-minute Pomodoro timer directly linked to a specific calendar task and mark it complete upon finishing.
* **Automated SMS Reminders (`cron_sms.php`):** Optional SMS alerts for overdue tasks (opt-in per task; disabled by default).

### 2. 📚 PDF Booklet Reader & Vector Annotation Studio (`index.php` & `api_pdf.php`)
* **True Edge-to-Edge Fullscreen & Centered Mobile Viewport:** Automatically centers PDF pages vertically and horizontally on mobile screens with breathing room for effortless zooming.
* **Pinch-to-Zoom & Double-Tap Zoom:** Locks zoom focal points directly under the user's fingers. Smart 2-finger pan/zoom detection prevents accidental pen strokes while zooming.
* **Rich Annotation Studio:**
  * **100% Opaque Pens & Whiteout Tool:** Includes charcoal black, ballpoint blue, red, dark green, purple, orange, custom color picker, and an **opaque whiteout pen** (ideal for covering answer keys during practice).
  * **Ruler (Straight Line) & Formula Box (Rectangle):** Draw crisp underlines and rectangular boxes around formulas.
  * **4 Stroke Thicknesses, Sticky Notes (`📌`), Undo & Eraser.**
  * **Snapshot Export (`📸`):** Merge the rendered PDF page and vector annotations into a downloadable high-resolution `PNG` image.

### 3. 🌐 Academic Paper Reader & Smart Translator (`paper_reader.php` & `api_translate.php`)
* **2-Column Layout & Sentence-Boundary Detection:** Extracts interactive sentence (`🎯`) and paragraph (`📄`) bounding boxes using percentage-based (`%`) coordinates that stay locked onto words at any zoom level.
* **Multi-Engine Translation & AI Summarizer:**
  * Supports **Google GTX**, **MyMemory**, and **Gemini AI Pool** (`gemini-3.7-flash` / `gemini-3.5-flash`) with automatic model & key rotation and permanent MySQL caching.
  * Extracts academic terminology (`English: Persian`) and key scientific takeaways (`✨ پیام کلیدی`).
* **Smart Auto-Advance & Background Pre-fetching:**
  * Customizable reading speed slider (`3s` to `25s`) with **Background Pre-fetching**—while reading the current sentence, the next sentence is translated in the background for `0ms` transition latency.
  * Multi-voice, multi-speed (`0.5x`–`1.5x`) English **Text-to-Speech (TTS)** synchronization.
* **Research Notes (Fiching):** Save translated segments with personal notes and export the entire collection to **Microsoft Word (`.doc`)** or **Printable PDF**.

### 4. 📝 Autonomous AI Exam Studio & Online Exam Runner (`admin.php`, `exam.php`, `api_exam.php`)
* **Multi-Page AI Question Crawler:** Load a multi-page test PDF in the Admin Studio, set a page range, and let the crawler automatically render each page, extract 4-option multiple-choice questions via **Gemini Vision OCR**, and save them directly to the database.
* **Automatic Figure Cropping (`figure_box`):** Gemini Vision detects diagrams, circuits, charts, or tables in the PDF page and returns normalized bounding-box coordinates (`[ymin, xmin, ymax, xmax]`), which the browser automatically crops and attaches to the corresponding question.
* **Pseudocode & Algorithm Formatting:** Automatically wraps pseudocode and code snippets in `LTR` monospace blocks (` ``` `) with preserved indentation.
* **Interactive Manual Crop & Bulk Answer Key Importer:** Draw a box on any PDF page to extract a single question, attach a diagram, or create a full-image question in one click. Update all correct options at once using sequential (`3 1 4 2`) or paired (`1-3, 2-1`) answer key strings.
* **Student Exam Environment (`exam.php`):** Features Single-Question and Booklet view modes, an interactive **Bubble Answer Sheet**, question flagging (`🚩`), countdown timer, instant Konkur report card, explanatory answer review, and automatic score logging into the user's study calendar.

### 5. 🛡️ Offline-First Asset Localization & AI Key Pool Telemetry (`setup_assets.php` & `admin.php`)
* **100% Local Asset Hosting:** Run `setup_assets.php` once to download Tailwind CSS, Vazirmatn `.woff2` fonts, and `PDF.js` (`pdf.min.js` + `pdf.worker.min.js`) directly into the local `assets/` directory so the UI never breaks during external CDN outages.
* **AI Key Pool Dashboard:** Real-time admin telemetry tracking total calls, today's calls, `200 OK` vs. error rates, module breakdown (Translator vs. Exam Studio), and live latency ping tests (`ms`) for every API key in `config.php`.

---

## 🗂️ Project Structure

```text
├── config.php               # Database, AI keys pool, SMS & VIP tier configuration
├── auth.php                 # User registration, login & session authentication
├── index.php                # Interactive Study Calendar + PDF Booklet Studio
├── paper_reader.php         # Academic Paper Reader & Smart Translator
├── exam.php                 # Online Exam Lobby, Runner, Bubble Sheet & Report Card
├── admin.php                # Admin Panel: AI Exam Studio, AI Key Pool Monitor & VIP Management
├── setup_assets.php         # One-click Offline Asset Localizer (Tailwind, Fonts, PDF.js)
├── api_calendar.php         # REST API for Calendar CRUD, Drag/Resize & Analytics
├── api_pdf.php              # REST API for PDF streaming, folders & vector annotations
├── api_translate.php        # REST API for Multi-Engine Translation, AI Pool & Research Notes
├── api_exam.php             # REST API for AI Vision OCR, Exam CRUD & Grading
├── api_subscription.php     # REST API for Promo Code Redemption & VIP Status
├── cron_sms.php             # Automated Cron Job for Overdue Task SMS Alerts
├── database.sql             # Full MySQL Database Schema (13 Tables)
├── assets/                  # Locally hosted CSS, Vazirmatn fonts & PDF.js worker
└── uploads/                 # Protected user storage (pdfs/, papers/, exams/)
```

---

## 🚀 Installation & Deployment Guide

### 1. Database Setup
1. Create a MySQL database with `utf8mb4_persian_ci` collation.
2. Import [`database.sql`](database.sql) via **phpMyAdmin** or CLI:
   ```bash
   mysql -u your_db_user -p your_db_name < database.sql
   ```
   *(Note: All core PHP endpoints also include idempotent auto-migration blocks that automatically create missing tables or columns on first run).*

### 2. Configuration (`config.php`)
Update your database credentials, Gemini API keys pool, and SMS credentials in `config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// Multi-Key Gemini AI Pool (Automatic Rotation & Failover)
define('AI_TRANSLATION_ENABLED', true);
define('AI_VIP_ONLY', true);
define('AI_PROVIDER', 'gemini');
define('AI_MODEL_NAME', 'gemini-3.7-flash');
define('AI_API_KEYS', [
    'YOUR_GEMINI_API_KEY_1',
    'YOUR_GEMINI_API_KEY_2',
]);
```

### 3. Localize Frontend Assets (`setup_assets.php`)
1. Register the first user account via `auth.php` (User ID `1` is automatically granted Admin privileges).
2. Open `[https://yourdomain.com/setup_assets.php](https://yourdomain.com/setup_assets.php)` in your browser and click **"🚀 دانلود فایل‌ها روی هاست و بومی‌سازی خودکار کل صفحات"** to store all CSS, fonts, and PDF.js files locally inside `assets/`.

### 4. Cron Job for Overdue Task Notifications (Optional)
To enable automated SMS reminders for overdue study sessions, configure a Cron Job in cPanel / DirectAdmin every 15 minutes:
```bash
*/15 * * * * curl -s "https://yourdomain.com/cron_sms.php" > /dev/null 2>&1
```

---

## 📄 License
Developed for educational, academic research, and Konkur exam preparation workflows.