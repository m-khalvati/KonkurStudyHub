# 🎯 Smart Konkur Study Planner, PDF Booklet Studio, Academic Paper Reader, AI Exam Studio & Video LMS (v4.4)

An all-in-one, offline-resilient **PHP & MySQL** educational platform engineered for university entrance exam (**Konkur**) preparation and academic research. The suite integrates five core studios into a unified ecosystem:
1. **📅 Interactive Jalali/Gregorian Study Calendar** (Drag & Drop, Resize, Drag-to-Create, Weekly Analytics)
2. **📚 PDF Booklet Studio & Vector Annotation Engine** (Pinch-to-Zoom, Opaque Pens, Whiteout, Formula Boxes)
3. **🌐 Academic Paper Reader & Multi-Engine AI Translator** (2-Column Sentence Detection, Gemini Pool, Auto-Advance TTS)
4. **📝 Autonomous AI Exam Studio & Online Testing Runner** (Multi-Page PDF Vision OCR, Auto Figure Crop, Bubble Sheet)
5. **🎬 Video Academy & Dual-Mode Educational Player** (Direct MP4 & **Aparat** Integration, Auto-Resume, `.srt`/`.vtt` Subtitles, Live Transcript, Timestamped Notes)

---

## ✨ Key Features by Module

### 1. 📅 Interactive Jalali Study Calendar (`index.php` & `api_calendar.php`)
* **Google-Calendar-Style Interactions:**
  * **Drag & Drop Tasks:** Drag tasks across hours or between days of the week using mouse (desktop) or short long-press (mobile) with 15-minute snap intervals.
  * **Interactive Resize Handle (`═`):** Drag the bottom edge of any task card to adjust its end time on the fly.
  * **Drag-to-Create:** Drag across empty time slots on the grid to visually select a range and open the creation modal pre-populated with exact start and end times.
* **One-Click Quick Complete & Task Duplication:** Mark tasks complete directly on the card or duplicate recurring study blocks in one click.
* **Konkur Test Score Calculator:** Built-in negative-marking percentage formula:
  $$\text{Score (\%)} = \frac{(3 \times \text{Correct}) - \text{Wrong}}{3 \times \text{Total}} \times 100$$
* **Weekly Analytics & Overdue Backlog:** Visual bar charts comparing planned vs. completed study hours per day, category breakdown, and one-click rescheduling for overdue tasks to "Today" or "Tomorrow".
* **Automated SMS Reminders (`cron_sms.php`):** Opt-in SMS alerts for uncompleted study blocks (unchecked by default).

### 2. 📚 PDF Booklet Reader & Vector Annotation Studio (`index.php` & `api_pdf.php`)
* **Centered Mobile Viewport & True Edge-to-Edge Fullscreen:** Automatically centers PDF pages vertically and horizontally on mobile devices.
* **Pinch-to-Zoom & Double-Tap Focal Lock:** Smooth two-finger pinch-to-zoom that locks onto the exact focal point between the user's fingers while preventing accidental pen strokes.
* **Rich Vector Annotation Tools:**
  * **100% Opaque Pens & Whiteout Tool:** Charcoal black, ballpoint blue, red, dark green, purple, orange, custom color picker, and an **opaque whiteout pen** for covering test answers during practice.
  * **Ruler (Straight Line) & Formula Box (Rectangle):** Draw crisp underlines and boxes around key formulas.
  * **4 Stroke Thicknesses, Sticky Notes (`📌`), Undo & Eraser.**
  * **Annotated Page Snapshot (`📸`):** Export any page along with its vector annotations as a high-resolution `PNG` image.

### 3. 🌐 Academic Paper Reader & Smart Translator (`paper_reader.php` & `api_translate.php`)
* **2-Column Layout & Sentence-Boundary Detection:** Extracts interactive sentence (`🎯`) and paragraph (`📄`) bounding boxes using percentage-based (`%`) coordinates that stay locked onto text at any zoom level.
* **Multi-Engine Translation & AI Summarizer:**
  * Supports **Google GTX**, **MyMemory**, and **Gemini AI Pool** (`gemini-3.7-flash`, `gemini-3.6-flash`, `gemini-3.5-flash`) with automatic key rotation, failover, and permanent MySQL caching.
  * Extracts bilingual academic terminology (`English: Persian`) and concise scientific takeaways (`✨ پیام کلیدی`).
* **Smart Auto-Advance & Background Pre-fetching:**
  * Adjustable reading speed slider (`3s` to `25s`) with **Background Pre-fetching**—the next sentence is translated in the background while the current sentence is being read for `0ms` transition latency.
  * Synchronized English **Text-to-Speech (TTS)** with voice and speed controls (`0.5x`–`1.5x`).
* **Research Notes (Fiching):** Save translated segments with personal notes and export them to **Microsoft Word (`.doc`)** or **Printable PDF**.

### 4. 📝 Autonomous AI Exam Studio & Online Testing (`admin.php`, `exam.php`, `api_exam.php`)
* **Multi-Page AI Question Crawler:** Load a multi-page test PDF in the Admin Studio, select a page range, and let the crawler automatically render each page, extract 4-option multiple-choice questions via **Gemini Vision OCR**, and save them directly to the database.
* **Automatic Figure Cropping (`figure_box`):** Gemini Vision detects diagrams, circuits, charts, or tables on the PDF page and returns normalized bounding-box coordinates (`[ymin, xmin, ymax, xmax]`), which the browser automatically crops and attaches to the question.
* **Pseudocode & Algorithm Formatting:** Automatically wraps pseudocode and code snippets in `LTR` monospace blocks (` ``` `) with preserved indentation.
* **Interactive Manual Crop & Bulk Answer Key Importer:** Draw a box on any PDF page to extract a single question, attach a diagram, or create a full-image question in one click. Update all correct options at once using sequential (`3 1 4 2`) or paired (`1-3, 2-1`) answer key strings.
* **Student Exam Environment (`exam.php`):** Single-Question and Booklet view modes, interactive **Bubble Answer Sheet**, question flagging (`🚩`), countdown timer, instant Konkur report card, explanatory answer review, and automatic score logging into the user's study calendar.

### 5. 🎬 Video Academy & Dual-Mode Aparat / HTML5 Player (`courses.php` & `api_courses.php`)
* **Dual-Mode Video Engine (Direct MP4/CDN + Aparat Integration):**
  * Accepts direct video URLs (`.mp4`, `.webm`, `.mkv`), **Aparat page links** (`[aparat.com/v/xxxxx](https://aparat.com/v/xxxxx)`), **Aparat embed URLs**, or raw `<iframe>` embed codes.
  * Automatically queries the Aparat API to extract direct `.mp4` streams (`720p`/`1080p` primary and `480p` backup) plus exact video duration so Aparat videos can play inside the custom HTML5 player with full subtitle and speed controls, while also providing a one-click **"🟣 Switch to Official Aparat Player"** (`<iframe>` with `?t=XX` resume support).
* **Smart Auto-Resume (`video_progress` + `sendBeacon`):**
  * Saves the user's exact playback second every 5 seconds, on pause, and on tab close (`navigator.sendBeacon`).
  * Automatically jumps to the exact saved second on the next visit with an interactive toast banner (`▶️ Resume from 18:42 | 🔄 Restart`).
* **Custom `.srt` & `.vtt` Subtitle Engine + Live Interactive Transcript:**
  * Built-in JavaScript subtitle parser supporting both `.srt` and `.vtt` files without CORS issues, with adjustable font sizes (`A+` / `A-`).
  * Interactive **Live Transcript** tab next to the video that highlights spoken sentences in real time, supports keyword search, and seeks the video when any sentence is clicked.
* **Session Booklets & One-Click Import to Booklet Reader:**
  * Attach a dedicated PDF/ZIP booklet to every lesson for direct download (`📥`), or import the lesson's PDF directly into the user's **PDF Booklet Studio** (`🖍️`) for highlighting and vector annotation.
* **Educational Player Controls & Mobile Touch Gestures:**
  * Playback speed (`0.5x` to `2.5x`), `±10s` seek, **A-B Loop Repeat** (`🔁`) for reviewing difficult segments, Theater Mode (`↔`), and Picture-in-Picture (`🖼️ PiP`).
  * Mobile gestures: **Double-tap** left/right to seek `±10s`, and **Long-press** on the video for temporary `2.0x` fast-forward playback.
* **Timestamped Video Notes (`📌`):** Take notes linked to the exact video second; click any note's timestamp badge later to jump straight to that moment, or export all lesson notes to **Microsoft Word (`.doc`)**.

### 6. 🛡️ Offline-First Asset Localization & AI Key Pool Telemetry (`setup_assets.php` & `admin.php`)
* **100% Local Asset Hosting:** Run `setup_assets.php` once to download Tailwind CSS, Vazirmatn `.woff2` fonts, and `PDF.js` (`pdf.min.js` + `pdf.worker.min.js`) into the local `assets/` directory so the UI never breaks during external CDN blocks or international internet disruptions.
* **AI Key Pool Dashboard:** Real-time admin telemetry tracking total calls, today's calls, `200 OK` vs. error rates, module breakdown (Translator vs. Exam Studio), and live latency ping tests (`ms`) for every API key in `config.php`.

---

## 🗂️ Project Architecture

```text
├── config.php               # Database, AI keys pool, SMS & VIP tier configuration
├── auth.php                 # User registration, login & session authentication
├── index.php                # Interactive Study Calendar + PDF Booklet Studio
├── paper_reader.php         # Academic Paper Reader & Smart Multi-Engine Translator
├── exam.php                 # Online Exam Lobby, Runner, Bubble Sheet & Report Card
├── courses.php              # Video Academy Lobby, Dual-Mode Player, Subtitles & Notes
├── admin.php                # Admin Panel: AI Exam Studio, Video Courses, AI Key Monitor & Users
├── setup_assets.php         # One-Click Offline Asset Localizer (Tailwind, Fonts, PDF.js)
├── api_calendar.php         # REST API for Calendar CRUD, Drag/Resize & Analytics
├── api_pdf.php              # REST API for PDF streaming, folders & vector annotations
├── api_translate.php        # REST API for Multi-Engine Translation, AI Pool & Research Notes
├── api_exam.php             # REST API for AI Vision OCR, Multi-Page Crawler & Exam Grading
├── api_courses.php          # REST API for Video Courses, Aparat Parser, Subtitles & Progress
├── api_subscription.php     # REST API for Promo Code Redemption & VIP Status
├── cron_sms.php             # Automated Cron Job for Overdue Task SMS Alerts
├── database.sql             # Complete MySQL Database Schema (18 Tables)
├── assets/                  # Locally hosted CSS, Vazirmatn fonts & PDF.js worker
└── uploads/                 # Protected storage (pdfs/, papers/, exams/, courses/)
```

---

## 🚀 Installation & Deployment Guide (Local & Linux cPanel Hosting)

### 1. Database Setup
1. Create a MySQL 8.0 / MariaDB database with `utf8mb4_persian_ci` collation.
2. Import [`database.sql`](database.sql) via **phpMyAdmin** or CLI:
   ```bash
   mysql -u your_db_user -p your_db_name < database.sql
   ```
   *(All PHP endpoints also include idempotent auto-migration blocks that automatically create any missing tables or columns on first request).*

### 2. Configuration (`config.php`)
Configure your database credentials, Gemini API keys pool, and Telegram support ID in `config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// Multi-Key Gemini AI Pool (Automatic Round-Robin Rotation & Failover)
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
2. Open `[https://yourdomain.com/setup_assets.php](https://yourdomain.com/setup_assets.php)` in your browser and click the localization button to download all CSS, Vazirmatn fonts, and PDF.js files directly into `assets/`.

### 4. Cron Job for Overdue Task Notifications (Optional)
To enable automated SMS reminders for overdue study sessions, configure a Cron Job in cPanel every 15 minutes:
```bash
*/15 * * * * curl -s "https://yourdomain.com/cron_sms.php" > /dev/null 2>&1
```

---

## 📄 License
Developed for educational, academic research, and Konkur exam preparation workflows.