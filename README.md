# College Cycle Test Examination System (starter build)

Lightweight PHP/MySQL examination portal for Windows XAMPP. Includes secure sign-in, role-based navigation, student creation, CSV student import, subjects, MCQ and typing-answer question banks, MCQ/typing/mixed exam scheduling, timed autosaved attempts, results publication, descriptive answer review, branding/theme settings, editable privacy/exam rules and audit logs.

The supplied `favicon_io/` brand assets are used for portal logos and browser/app icons. The default brand palette is aviation red (`#E31825`) and gold (`#FFC20E`); administrators can change both in Branding & settings.

## Important status notice

This is a **starter MVP, not yet production-ready for a live high-stakes exam**. It is useful for local development and acceptance testing. Before using real students or live examinations, complete the limitations listed below, test all flows, and conduct a security review and load test. Do not assume that the presence of a feature in the interface means it has been fully validated.

### Known limitations to address before production
- Student import currently supports CSV; save Excel workbooks as CSV UTF-8. Native XLSX/XLS import is not bundled.
- Questions are currently selected by subject in the exam attempt flow; explicit per-exam question assignment/snapshotting and randomization are not implemented. The exam's configured total marks are not yet enforced against the sum of question marks.
- MCQ exams (including the MCQ portion of a Mixed Exam) continue to use every MCQ in the exam subject's question bank. Typing-answer questions are attached to an individual exam. Check subject question-bank contents before scheduling a mixed exam.
- Exam attempts include question navigation, review flags, per-answer server autosave, and same-tab retry for answers changed while offline. Reopen an in-progress attempt from the student dashboard to resume; after the deadline or exam closure, server-saved answers are submitted automatically when the student reconnects. Unsaved offline changes cannot be guaranteed if the browser tab is closed, and live-exam load testing is not implemented.
- The browser can warn when a student switches away and offer fullscreen, but mobile browsers do not let an ordinary website block other apps or tabs. Browser-side timer submission still depends on the page being available; server finalization runs when the student next reconnects. An exam marked Active is only available between its configured start and end times.
- The initial schema enforces one attempt per student/exam. Multiple attempts are not implemented.
- Exam schedule fields are editable from Examinations until the first attempt starts. After that, only Active/Closed status changes are available; closing an exam finalizes its in-progress attempts from server-saved answers when the student next reconnects.
- Student and staff accounts have My profile and Change password links. Students may update their display name, email, and phone; login/register number and role stay institution-managed.
- Descriptive questions support exact teacher-approved answers, explicitly weighted keyword phrases, optional numeric tolerance and optional ASCII spelling tolerance. Answers that do not fit deterministic rules remain pending teacher review; no similarity percentage is used.
- Teacher review screens support score overrides with mandatory reasons and an append-only score-review history. A result is not created while any descriptive answer is pending review; after review, the existing result publication flow applies.
- Teacher subject-level assignments, email notifications, policy acknowledgement version tracking, exportable result reports and tested backup/restore automation are not implemented.
- Audit logs and login throttling are basic and need retention, privacy, and concurrency review.
- Run security tests and load tests before any production use.

## Requirements
- Windows with XAMPP (Apache, PHP 8.x and MySQL/MariaDB).
- PDO MySQL enabled. Fileinfo enabled for logo upload.
- Browser access to the XAMPP server.
- For production use, HTTPS is required for credentials and student data over a network.

## Installation
1. Copy the `cycle_exam` folder to `C:\xampp\htdocs\cycle_exam\`.
2. Start Apache and MySQL in the XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin`.
4. Import `install.sql`. It creates the `cycle_exam` database and tables.
5. Copy `config.example.php` to `config.php`. Confirm the MySQL host, database, username and password. Typical local XAMPP defaults are user `root` and blank password; do not use those defaults on an exposed server.
6. Open Command Prompt and run:
   `cd C:\xampp\htdocs\cycle_exam`
   `C:\xampp\php\php.exe create_admin.php`
7. Choose an admin username, display name and strong password (12+ characters). Sign in at `http://localhost/cycle_exam/`.
8. Immediately change the admin password, configure the institution name/colors, add subjects, add questions, add students, and schedule an exam.
9. Delete `create_admin.php` after creating the admin account. Keep `config.php` private.
10. Verify the database backup and restore process before importing real student records.

### Existing database upgrade for exam navigation
The app automatically checks for the question review flag column and applies this upgrade when the database account has ALTER permission. If the app displays “Database update required,” run this once in phpMyAdmin using a database administrator account:
`ALTER TABLE attempt_answers ADD is_flagged TINYINT(1) NOT NULL DEFAULT 0;`

### Existing database upgrade for descriptive exams
1. Back up the `cycle_exam` database in phpMyAdmin (Export → Quick → SQL) and keep the file outside the web root.
2. In phpMyAdmin, select the `cycle_exam` database and import `migrations/20261024_descriptive_exams.sql` once. It adds an `exam_pattern` column with the default `mcq` (preserving all existing exam behavior) and creates separate descriptive question, answer, and review-history tables. It does not delete or rewrite existing records.
3. Deploy the updated PHP/assets and reload the portal. New exams can be MCQ, Typing Answer, or Mixed. Existing exams remain MCQ.
4. Restore only into a test database first if you need to verify the backup; never test a restore over the live database.

### Existing database upgrade for question archiving
The app automatically adds `is_archived` flags to the MCQ and typing question tables when the database account has ALTER permission. If the portal displays “Database update required,” run `migrations/20261027_question_archive.sql` once in phpMyAdmin, then reload the portal. The update preserves all questions, student answers, review history, and results.

## Student import
- In Students, download the CSV template.
- Open it in Excel and enter `register_number`, `student_name`, and optional `department`, `year_level`, `section`.
- Save as **CSV UTF-8** and upload it.
- Existing register numbers are skipped. Imported students are marked to change password on first login. Use the per-student Reset password action to generate a one-time temporary password; it is displayed once in the administrator's session. Share it privately and require the student to change it immediately. Do not give everyone the same password. For bulk onboarding, implement an audited one-time activation workflow before production rollout.
- Students created individually from the Students page receive a unique temporary password displayed once in the administrator's session after creation. Share it privately and require a password change at first sign-in. Do not copy it into audit logs or shared documents.

## Typing-answer and mixed exams
1. An administrator creates an exam and selects **Typing Answer** or **Mixed Exam**. Existing and newly created MCQ exams default to **MCQ**.
2. Open **Typing questions** and add questions to the exam. Each question has a number, prompt, maximum marks, expected answer, optional accepted answers, optional numeric tolerance, and optional `phrase|marks` keyword rubric. Rubric weights must be positive and their sum cannot exceed the question maximum. Questions lock after the first attempt starts.
3. Case, repeated whitespace, and (when enabled) punctuation are normalized for answer matching. Exact accepted answers receive full marks; numeric tolerance and explicitly weighted keyword rules are deterministic. Optional spelling tolerance is limited to a teacher-configured number of ASCII edits. Unsupported answers without a deterministic rubric are held for teacher review rather than assigned a guessed score.
4. Students type answers in the timed exam page; answers autosave using the same secure attempt flow as MCQ answers. Answer keys are not loaded into student pages.
5. In **Question Bank**, choose a subject and optionally search through its paginated MCQs. In **Typing Questions**, choose an exam to manage its paginated written questions. Both pages can show archived questions. Archiving hides a question from future attempts while preserving existing answers and results; archiving/restoring is blocked while an affected exam has an in-progress attempt or is currently open.
6. Teachers open **Typing review**, choose an exam date and exam, then search or page through students by register number. Open one student to review their answers question by question; **Save & next pending answer** advances through outstanding work, and **Close student review** returns to the student list. Completed reviews can be included when needed. Save any score override with a reason. Results are created only after every descriptive answer has a score; then an admin can publish through **Results & reports**. Published results include per-question marks.

Run the focused deterministic marker tests with:
`C:\xampp\php\php.exe tests\descriptive_marking_test.php`

## Local network use
To allow trusted devices on the same college LAN, configure Windows Firewall to permit Apache only on the private college network, and use the server's LAN IP address. Do not expose phpMyAdmin or MySQL port 3306 to the internet.

## Internet deployment
Do not directly expose a development XAMPP setup to the public internet. Use HTTPS, secure Apache/PHP settings, private configuration and backups, restricted admin access, and a reviewed deployment architecture. Keep MySQL private. A security review and concurrency testing are mandatory.

## Load testing
Test in stages (e.g. 25, 50, 100, 250 concurrent virtual users, then increase only if the server remains healthy). Measure login latency, question loading, answer saves, submission latency, database CPU/memory, Apache worker usage and errors. Use JMeter or k6 and test on hardware/network representative of the real exam day. 3,000 registered accounts does not mean 3,000 simultaneous users are supported.

## Security notes
- Uses PDO prepared statements, password hashing, session regeneration, CSRF checks on POST actions, output escaping and basic role checks.
- This is not a guarantee of security. Have a qualified reviewer test IDOR, XSS, CSRF, brute force, file upload, session, race condition and exam submission edge cases.
- Use a unique strong password for every user; never store plaintext passwords.
#   e x a m - m o o d l e  
 