# Student Clearance Management System

A role-based PHP/MySQLi web portal for Christ The King College De Maranding, Inc. (CKCM) that digitizes the student clearance process. Students submit one clearance request per school year + semester, each request fans out to every active office, offices/deans approve or reject with remarks, and admins monitor overall status, users, offices, courses, announcements, and the printable clearance form.

## 1. Tech Stack and Requirements

- PHP (procedural, no framework), MySQLi with prepared statements
- MySQL / MariaDB, database name `school_clearance_db`, charset `utf8mb4`
- HTML + `assets/css/dashboard.css` (app shell) + `assets/css/auth.css` (landing/login/maintenance)
- Vanilla JavaScript `assets/js/app.js`, Lucide SVG icons via CDN (`https://unpkg.com/lucide@latest`)
- XAMPP (Apache + MySQL + phpMyAdmin) on Windows; project lives at `C:\xampp\htdocs\clearance`
- Images: `assets/images/system_logo.jpg` (brand/avatar fallback), `ckcm_logo.jpg` (org switcher), `ckcm transparent.png` (printable form header), `school-logo.svg`
- Uploads: `assets/uploads/profiles/user_<24 hex>.<jpg|png|webp>`

## 2. Setup

1. Copy folder to `C:\xampp\htdocs\clearance`.
2. Start Apache + MySQL in XAMPP.
3. In phpMyAdmin create `school_clearance_db` and import your dump. If tables are missing, the app self-creates `system_settings`, `courses`, `clearance_items` on first hit (see Section 5).
4. Verify `config/db.php`: host `localhost`, user `root`, password `""`, database `school_clearance_db`.
5. Open `http://localhost/clearance/admin/login.php`. This one login page serves admin, student, and office roles.
6. Create users in `admin/users.php`. A student `users` row auto-creates a linked `students` row. An office user becomes functional after linking in `admin/assignatories.php` or after a dean creates signatories under them.

> `README.txt` is the original short note. `scms/` is an empty placeholder directory.

## 3. Roles and Core Concepts

- `users.role`: `admin` (full management), `student` (request + track + print), `office` (dashboard + announcements + profile; deans additionally get reviews + assignatories).
- College dean = `users.college_dean=1` + `college_dean_course` (e.g. `BSIT`). Helper `is_dean_user()` in `config/auth.php` checks `role==='office' && college_dean==1`. `users.department` mirrors the dean course for display.
- Clearance hierarchy: `students (1) -> clearance_requests (N per term) -> clearance_transactions (1 per office per request) + clearance_items (N custom requirements per request)`.
- Term model: admin sets free-text `clearance_term` (default `2nd Semester S.Y. 2025 - 2026`) in Form Configuration. Student code parses it with two regexes into `semester` (`1st Semester|2nd Semester|Summer`) and `school_year` (`YYYY-YYYY`, whitespace stripped). If parsing fails, request creation is blocked with "term is incomplete".
- Office visibility = `offices.status`. Only `status='active'` offices generate transactions, appear on the printable form, and count in dashboards. Form Configuration toggles this same flag.
- First-year rule: offices whose name normalizes to `nstpnsrc` (`preg_replace('/[^a-z]/i','',name)`, case-insensitive compare) are skipped for non-1st-Year students at request creation and filtered from dashboard/form views. 1st-Year students include NSTP/NSRC.

## 4. Authentication and Session Logic

`config/auth.php`:

- Starts session if none, defines `require_role(string $role)` (redirect to `../index.php` + `exit` unless `$_SESSION[user_id]` and `role` match), `is_dean_user()`, and `e()` (`htmlspecialchars(..., ENT_QUOTES, UTF-8)`).
- Every protected page calls `require_role()`: admin pages directly or via `admin/_header.php`; student/office pages both directly and via their `_header.php`.

`admin/login.php` (shared for all roles):

- If already logged in, redirects to `admin/dashboard.php`, `student/dashboard.php`, or `office/dashboard.php`.
- POST: `SELECT user_id, username, password, full_name, email, avatar_path, role, status, department, college_dean, college_dean_course FROM users WHERE username=? LIMIT 1`. Errors: empty fields, inactive account, `password_verify()` failure, or non-admin during `maintenance_mode=1` ("Only administrators can sign in right now").
- Success: `session_regenerate_id(true)`, populates `user_id, username, full_name, email, avatar_path, role, college_dean, college_dean_course, department` (department falls back to dean course), role-based redirect.
- UI: `auth.css` card, click on backdrop returns to `../index.php`.

`admin/logout.php`: `session_start`, clear `$_SESSION`, `session_destroy`, redirect to `login.php`. Student/office sidebars link to `../admin/logout.php`.

`index.php`:

1. Checks `SHOW TABLES LIKE 'system_settings'`, reads `maintenance_mode`. If `1` and `($_SESSION[role] ?? '') !== 'admin'`, renders standalone maintenance page (`auth.css`, logo, "System under maintenance", admin-login link) and exits.
2. If `user_id + role` set, redirects by role to the three dashboards.
3. Else renders marketing landing (`auth.css`): nav (brand, "Portal online" dot, Sign in), hero ("Complete your student clearance with confidence"), seal ring with `system_logo.jpg` and `W.B. / EST. 2026`, proof strip (01-03), three feature cards (Track progress / Stay organized / Finish smoothly).

## 5. Global Infrastructure (`config/db.php`)

- Connects `new mysqli(localhost, root, "", school_clearance_db)`, dies on connect error, sets `utf8mb4`.
- Ensures `system_settings(setting_key VARCHAR(80) PK, setting_value TEXT, updated_at TIMESTAMP)`.
- Maintenance gate: if `maintenance_mode==1` and logged-in non-admin, redirects to `index.php` (or `../index.php` when inside a subdirectory, derived from `dirname($_SERVER[SCRIPT_NAME])`).
- Lightweight migrations on every request via `SHOW COLUMNS LIKE`: adds `users.avatar_path`, `users.department`, `users.college_dean TINYINT DEFAULT 0`, `users.college_dean_course`; adds `office_assignatories.assignatory_name`, `assigned_by_user_id`; modifies `office_assignatories.user_id` to `INT UNSIGNED NULL` so manual named signatories can exist without a user account.

## 6. Clearance Lifecycle and Algorithms

### 6.1 Student request creation (`student/dashboard.php`, `student/clearance.php`)

Same algorithm in both files (dashboard shows inline progress; clearance.php redirects to `?request=<id>`):

1. Load `students WHERE user_id=?`, compute `$isFirstYearStudent = strcasecmp(year_level,'1st Year')==0`.
2. Load `clearance_term`, regex-parse semester + school year.
3. Check `system_settings.allow_clearance_requests==1`, else "currently disabled".
4. Duplicate check: `SELECT clearance_id FROM clearance_requests WHERE student_id=? AND school_year=? AND semester=? LIMIT 1` -> "already have a request".
5. Transaction: `BEGIN`, `INSERT clearance_requests(student_id, school_year, semester)`, `SELECT office_id, office_name FROM offices WHERE status='active'`, dedupe IDs skipping `nstpnsrc` for non-first-years, `INSERT clearance_transactions(clearance_id, office_id)` per office. `COMMIT` on full success else `ROLLBACK`.

### 6.2 Overall-status recalculation

Used identically in `admin/clearance.php` (closure `$recalculateClearanceStatus`) and `office/reviews.php` (inline after each review):

```
SELECT status FROM clearance_transactions WHERE clearance_id=?
total=count, rejected=count(status=rejected), approved=count(status=approved)
if total==0: pending
elif rejected>0: not_cleared
elif approved==total: cleared
else: pending
UPDATE clearance_requests SET overall_status=?
```

Admin additionally recalculates every request on every GET, so the monitor view self-heals stale statuses. Per-office `status` values are `pending|approved|rejected`; request-level `overall_status` values are `pending|cleared|not_cleared`.

### 6.3 Progress percentage (student dashboard)

`approvedCount/total*100`, rounded to int. `total==0` yields `0%`. Displayed as "Office progress" stat plus per-office table (Office / Requirement status / Remarks / Updated with `reviewed_at` or "Waiting for review").

### 6.4 Custom requirements (`admin/clearance.php`)

- Auto-creates `clearance_items(item_id, clearance_id FK CASCADE, office_id FK CASCADE, item_name, notes, is_required, is_completed, created_at)`.
- List view: all requests join students, newest first; defaults `?review=` to first row; detail loads request + items join offices.
- Editor (`#clearance-review-form`, `clearance-template-editor`): dynamic rows with Office select + Requirement name + Notes + Required/Completed checkboxes + Delete. JS `addClearanceRow()` computes next index, injects `officeOptionsHtml` from PHP, `bindSingleRowEvents()` adds hidden `removed_office_ids[]` when deleting a row with an office selected. POST `save_requirements`: `DELETE FROM clearance_items WHERE clearance_id=`, loop `item_name[]/item_office[]/item_notes[]/item_required[]/item_completed[]`, skip empty name, `office<=0`, or removed office, `INSERT` remainder, recalc status, redirect `?review=id`.
- Inline page script also defines `openClearanceModal()` ("Add office" top button).

## 7. Admin Portal (`admin/`)

Shared shell `admin/_header.php` (enforces `require_role('admin')`, loads `dashboard.css` + Lucide, sidebar: Dashboard/Profile, Management: Students/Offices/Courses/Assignatories/Clearance/Form Configuration/Users/Announcements, System: Settings, sidebar search with `Ctrl K`, org switcher to `https://edurie.com/ckcm`, dark-mode switch, topbar with sidebar toggles, PHT clock, notifications/help buttons, avatar from session) and `admin/_footer.php` (closes tags, delete-confirm modal `#deleteConfirmModal`, `app.js`, `lucide.createIcons()`). All admin forms use POST `action` hidden field, PRG redirects with `$_SESSION[flash_message]`, `.notice` success/error blocks, floating modals (`data-form-target`), client-side search, `data-delete-confirm` interception.

- `dashboard.php`: `COUNT(*) students(active)`, `offices(active)`, `office_assignatories(active)`; `SUM(overall_status=...)` for pending/cleared/not_cleared; last 8 requests join students. UI: 4 stat cards + 2 mini cards + recent-requests table (Student/School Year/Semester/Status/Date).
- `users.php` (most complex): manages admin/student/office accounts.
  - `$generateStudentNumber()`: scans all `students.student_number`, regex `/^SC\s*-?\s*(\d+)$/i`, returns `SC-` + zero-padded `max+1` (3 digits). Auto-applied when student number empty.
  - `$checkUserConflict(username,email,ignoreId)`: `SELECT ... WHERE username=? OR email=?` distinguishes username-taken vs email-taken.
  - Avatar: `finfo` MIME must be jpeg/png/webp, `<=2MB`, saved to `assets/uploads/profiles/user_<random12>.<ext>` via `random_bytes` + `move_uploaded_file`.
  - Save: validates required fields, student-number, dean-course-required-when-dean; conflict check; update uses 4-branch `UPDATE users` (avatar/password present or not) incl. `college_dean(_course)`, splits `full_name` into first/middle/last (`preg_split/\s+/`, first token = first, last token = last, middle = remainder) and `UPDATE students ... WHERE user_id=?`; syncs session if self. Insert requires password, uses transaction `INSERT users` + (if student) `INSERT students(user_id, student_number, first, middle, last, course, year_level, email, status)`; commit/rollback.
  - Toggle: `IF(active,inactive,active)`, blocked for self. Delete: blocks admin role and self; transaction deletes `students WHERE user_id=?` (student) or `office_assignatories WHERE user_id=?` (office), then `DELETE FROM users WHERE user_id=? AND role<>'admin'`.
  - Edit via `?edit=` (users LEFT JOIN students); course dropdown from `courses WHERE status='active'`; list newest first with avatar, role/status pills, Edit/Activate/Delete; role filter `select[data-filter=role]` + search handled by `app.js`; role select toggles `.student-only-field/.office-only-field/.dean-course-field`.
- `students.php`: edit/toggle/delete only. `save` requires number/first/last; `UPDATE students SET ... WHERE student_id=?`; insert rejected ("must be created from the Users page"). Course filter via `?course_filter=` + distinct-course dropdown + `SC-` numeric sort (`CASE WHEN student_number REGEXP '^SC-[0-9]+$' THEN CAST(SUBSTRING... )`). Table: Number/Name/Course/Year/Status/Action.
- `offices.php`: full CRUD `offices(office_name, description, status)`, toggle, `SELECT ... ORDER BY office_name`.
- `assignatories.php`: signatories linked to office user (`user_id`) or manual name (`assignatory_name`, `user_id=NULL`). Save requires `(user_id OR name) + office + position`; update/insert branches on `user_id>0`; toggle only (no delete). Dropdowns from `offices` and `users WHERE role='office'`; list `COALESCE(assignatory_name, full_name)`.
- `courses.php`: auto-creates `courses(course_id, course_code UNIQUE, course_name, description, status ENUM, created_at)`; CRUD + toggle; unique-code error handling.
- `announcements.php`: CRUD + publish/draft toggle (`IF(published,draft,published)`), `created_by=session`, list joins author, title maxlength 180.
- `form_configuration.php`: six header keys with CKCM defaults (school name/address/contact, department, form title, term); empty POST values fall back to defaults; upsert via `INSERT ... ON DUPLICATE KEY UPDATE`; live preview paper; office-visibility table (`SELECT ... ORDER BY status DESC, office_name`) where Remove/Add toggles `offices.status` (warns it affects reviews/assignments but keeps history).
- `settings.php`: three checkboxes (`allow_clearance_requests`, `allow_office_remarks`, `maintenance_mode` defaults `1,1,0`), upsert loop, single Save button.
- `profile.php`: self edit with same avatar rules, `FILTER_VALIDATE_EMAIL` + uniqueness check, 4-branch update, syncs `$_SESSION[full_name,email,avatar_path]`.

## 8. Student Portal (`student/`)

Shell `student/_header.php` (`require_role('student')`, Student Portal topbar, menu Dashboard/My Clearance/Announcements/My Profile, logout to `../admin/logout.php`) + `_footer.php`.

- `dashboard.php`: welcome header, identity card (name/number/course/status), 3 stat cards (current overall status, progress %, total requests), `#request-form` modal showing configured term (no term inputs — term is admin-controlled), clearance-progress table, latest 3 published announcements.
- `clearance.php` (My Clearance): printable paper (`student-clearance-form-paper`, logo `ckcm transparent.png`, school header/department/title/term from `system_settings` keys prefixed `clearance_`, Name/Course meta rows, Office / In Charge (`assignatory_name — position`) / Signature (status badge) table built from active offices + active assignatories `GROUP BY`, filtered for NSTP/NSRC). Request history via `?request=` (defaults to latest). Read-only `clearance_items` load (admin-authored). New-request modal identical algorithm to dashboard.
- `announcements.php`: read-only `SELECT title, content, created_at WHERE status='published' ORDER BY created_at DESC`, `nl2br(e(content))`, empty panel fallback.
- `profile.php`: edits own `users.full_name/email/password` (no avatar unlike office/admin), email format + uniqueness checks, syncs session; shows read-only student-details grid (number/course/year/status from `students WHERE user_id=?`).

## 9. Office Portal (`office/`)

Shell `office/_header.php` (`require_role('office')`, Office Clearance Portal topbar, conditional menu: Dashboard always, Reviews + Department Assignatories only `if(is_dean_user())`, Announcements, My Profile; help button carries `data-help-message`) + `_footer.php` (loads `app.js?v=20260922`).

- `dashboard.php`: resolves `$officeIds` via `SELECT DISTINCT office_id FROM office_assignatories WHERE status='active' AND (user_id=? OR assigned_by_user_id=?)`. Dynamic `IN(?,...)` query joins transactions/requests/students/offices ordered by request date. Counts pending/approved/rejected in PHP (unknown -> pending). Shows 4 stat cards + assigned-office label (first office name) or "No office assignment" empty panel. CTA "Open reviews".
- `reviews.php` (dean-only; non-deans get "Access restricted" panel + exit): queue scoped to `assigned_by_user_id=self` offices AND `students.course = users.college_dean_course`.
  - POST `update_transaction`: sanitize IDs, whitelist `pending/approved/rejected`, verify `in_array(officeId, officeIds)`, `UPDATE clearance_transactions SET status, remarks, reviewed_at=NOW() WHERE clearance_id=? AND office_id=?`, then overall-status recalc (Section 6.2), flash + redirect.
  - Filters: Signatory dropdown (`office_filter` GET auto-submit, options `office — name (position)`), Status dropdown (`data-filter=status`, JS-filtered), search field.
  - Table: Student (avatar or icon + number + course) / Office / School Year / Semester / Status / Updated / Action (inline status select + remarks textarea + Save).
- `assignatories.php` (dean-only + requires `currentDepartment = college_dean_course ?: department` non-empty): CRUD for named department signatories (`user_id=NULL`, `assigned_by_user_id=self`).
  - Save requires name/office/position; duplicate check `(assignatory_name, office_id, id<>)`; update/insert scoped by `assigned_by_user_id`; toggle via explicit `new_status`; delete scoped, checks `affected_rows`.
  - Edit via `?edit=` scoped to owner; list only `WHERE assigned_by_user_id=self`; delete/activate confirm modals via `data-delete-confirm` attributes.
- `announcements.php` (any office user): CRUD strictly scoped `created_by=self` on update/toggle/delete/edit (`affected_rows` error "only ... created by your office account"). List: `WHERE status='published' OR created_by=self`. Card UI with date + author, owner-only Edit/Publish/Delete.
- `profile.php`: same avatar/password logic as admin (4-branch update, 2MB jpeg/png/webp, session sync) + assignment lookup `(user_id=? OR assigned_by_user_id=?) AND active LIMIT 1` shown as `Office · {name|Unassigned}` badge.

## 10. Frontend Behavior (`assets/js/app.js`)

Single global script, no backend calls: auto-dismiss `.notice` after 4s; PHT clock (`Intl.DateTimeFormat en-PH, Asia/Manila`, 1s); dark mode (`localStorage clearance-theme`, body `.dark`, whole-row click); mobile sidebar `.open` + outside-click close; collapsible sidebar (`localStorage clearance-sidebar-collapsed`); school-switcher dropdown; `filterTable(container)` (search substring over row text + `select[data-filter]` matching `.status-{value}` or `row.dataset.role`, `all/all_statuses` pass); user-role field toggling (`#userRole`, `.student-only-field/.office-only-field`, dean-course required); floating modals (`[data-form-target]` open, close button/backdrop/`Escape`, special `clearance-review-form` URL cleanup removing `?review`); delete-confirm modal intercepting `[data-delete-confirm]`; sidebar search filter + `Enter` navigation + `Ctrl/Cmd+K` focus; notifications button routes to `reviews.php` under `/office/` else `clearance.php`; help button alert.

## 11. Styling

- `auth.css`: reset + Inter font, `.auth-card` (420px), form/btn/alert styles, maintenance card (480px), landing grid background, 1180px shell with orange top border, 2-col hero (copy + seal ring/circle/label), 3-col proof/features, responsive single-column under 760px.
- `dashboard.css` (~2000 lines): CSS vars (sidebar/page/surface/border/text/muted/orange/green/red/yellow), fixed 250px -> 72px collapsed sidebar, school header/menu, search, active menu items, switch, sticky topbar with centered PHT, icon buttons, `content max-width 1220px`, stats/mini grids, identity/announcement/clearance-paper/detail grids, tables, status pills (`pending/approved/cleared/active/rejected/not_cleared/inactive/draft/published`), review-table layout (1180px min), floating centered modals + backdrop, announcement/configuration forms, confirm modal, dark-mode overrides.

## 12. Database Reference (inferred from queries)

- `users(user_id, username UNIQUE, password HASH, full_name, email UNIQUE, avatar_path NULL, department NULL, college_dean TINYINT DEFAULT 0, college_dean_course NULL, role admin|student|office, status active|inactive, created_at)`
- `students(student_id, user_id FK, student_number UNIQUE e.g. SC-001, first_name, middle_name, last_name, course, year_level 1st-4th Year, email, status, created_at)`
- `offices(office_id, office_name, description, status active|inactive, created_at)`
- `office_assignatories(assignatory_id, user_id NULL FK, assignatory_name NULL, assigned_by_user_id NULL FK (dean owner), office_id FK, position, status)`
- `clearance_requests(clearance_id, student_id FK, school_year YYYY-YYYY, semester, overall_status pending|cleared|not_cleared, requested_at, updated_at)`
- `clearance_transactions(?, clearance_id FK, office_id FK, status pending|approved|rejected, remarks, reviewed_at)`
- `clearance_items(item_id, clearance_id FK CASCADE, office_id FK CASCADE, item_name, notes, is_required BOOL, is_completed BOOL, created_at)`
- `courses(course_id, course_code UNIQUE, course_name, description, status ENUM active|inactive, created_at)`
- `announcements(announcement_id, title 180 chars, content, status published|draft, created_by FK users, created_at)`
- `system_settings(setting_key PK VARCHAR(80), setting_value TEXT, updated_at)` keys: `maintenance_mode, allow_clearance_requests, allow_office_remarks, clearance_school_name, clearance_school_address, clearance_school_contact, clearance_department, clearance_form_title, clearance_term`

## 13. Conventions, Security, and Limitations

- PRG pattern everywhere: POST -> `$_SESSION[flash_message]` -> `header(Location)` -> GET; toggle/delete messages use local `$message`.
- Validation: required-field trims, email format + uniqueness, enum whitelisting (`role`, `status`, transaction `status`), numeric ID casts, `SC-` format handling, file MIME + size checks, dean-course-required, duplicate-request and duplicate-signatory guards.
- Security: prepared statements for inputs, `e()` on output, `password_hash/password_verify`, `session_regenerate_id`, self/admin delete blocks, owner scoping (`assigned_by_user_id`, `created_by`), `random_bytes` filenames, `affected_rows` checks. Note: `admin/clearance.php` uses one raw `DELETE ... (int)` cast (safe by cast) and `office/announcements.php` interpolates `(int)session_id` (safe by cast).
- Known limits: no pagination (full table scans), admin recalculates all requests per page load, student `clearance.php` has a `$GLOBALS[_POST]` typo-guard that still works, no audit logs, no email/notifications beyond in-app lists, no public registration (accounts via admin).
