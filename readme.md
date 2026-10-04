# Student Clearance Management System

A role-based web portal for managing student clearance across offices, assignatories, and academic terms. Students submit clearance requests per school year / semester, offices review them, and admins monitor everything.

## Tech Stack

- PHP (procedural, MySQLi, no framework)
- MySQL / MariaDB (`school_clearance_db`)
- HTML / CSS (`assets/css/auth.css`, `assets/css/dashboard.css`)
- JavaScript (`assets/js/app.js`)
- Lucide SVG icons
- XAMPP (Apache + MySQL + phpMyAdmin)

## Project Structure

```
clearance/
  index.php                 # public landing + maintenance gate + role redirect
  config/
    db.php                  # connection + auto-migration + maintenance gate
    auth.php                # require_role(), is_dean_user(), e()
  admin/                    # admin portal (role=admin)
    dashboard.php, students.php, offices.php, assignatories.php
    clearance.php, users.php, courses.php, announcements.php
    form_configuration.php, settings.php, profile.php
    login.php, logout.php, _header.php, _footer.php
  student/                  # student portal (role=student)
    dashboard.php, clearance.php, announcements.php, profile.php
    _header.php, _footer.php
  office/                   # office portal (role=office)
    dashboard.php, reviews.php, assignatories.php
    announcements.php, profile.php, _header.php, _footer.php
  assets/
    css/dashboard.css       # app design system + dark mode
    css/auth.css            # landing / login / maintenance styles
    js/app.js               # clock, theme, sidebar, tables, modals
    images/system_logo.jpg, ckcm_logo.jpg, ckcm transparent.png, school-logo.svg
    uploads/profiles/       # avatar uploads (user_<random>.jpg/png/webp)
  scms/                     # empty placeholder
  README.txt                # original short setup notes
```

## Setup

1. Copy folder to `C:\xampp\htdocs\clearance` (or `student_clearance_system` — update links accordingly).
2. Start Apache + MySQL in XAMPP.
3. Create database `school_clearance_db` in phpMyAdmin and import your SQL dump.
4. Check `config/db.php`: host `localhost`, user `root`, password `""`, database `school_clearance_db`.
5. Open `http://localhost/clearance/admin/login.php` and log in.
6. Missing tables/columns are auto-created on first load (see `config/db.php`, `admin/courses.php`, `admin/clearance.php`, `admin/form_configuration.php`, `admin/settings.php`).

## Roles and Authentication

- Three roles in `users.role`: `admin`, `student`, `office`.
- Shared login at `admin/login.php` for all roles:
  - `SELECT ... FROM users WHERE username=?`, check `status='active'`, `password_verify()`.
  - Blocks non-admins when `system_settings.maintenance_mode=1`.
  - `session_regenerate_id()`, stores `user_id, username, full_name, email, avatar_path, role, college_dean, college_dean_course, department` in `$_SESSION`, redirects by role.
- Guards: `require_role($role)` in `config/auth.php` redirects to `../index.php` on mismatch. Every `admin/*`, `student/*`, `office/*` page calls it (directly or via `_header.php`).
- Dean flag: `is_dean_user()` = `role==='office' && college_dean==1`. Only deans see `office/reviews.php` and `office/assignatories.php`.
- `e()` = `htmlspecialchars(..., ENT_QUOTES, UTF-8)` used for all output.
- `admin/logout.php`: clears session, redirects to `login.php`.
- `index.php`: maintenance check first, then role redirect, else landing page.

## Global Systems

### Maintenance mode (`index.php`, `config/db.php`, `admin/settings.php`)
- Key `system_settings.maintenance_mode` (`1/0`).
- `index.php`: if `1` and role is not admin, renders standalone maintenance card.
- `config/db.php`: if `1` and logged-in non-admin, redirects to `index.php`.
- `admin/login.php`: blocks non-admin logins during maintenance.

### Settings (`admin/settings.php`)
- Three booleans in `system_settings`: `allow_clearance_requests` (default 1), `allow_office_remarks` (default 1), `maintenance_mode` (default 0).
- Saved via checkbox POST + `INSERT ... ON DUPLICATE KEY UPDATE`.

### Form configuration (`admin/form_configuration.php`)
- Six header keys in `system_settings` with CKCM defaults: `clearance_school_name`, `clearance_school_address`, `clearance_school_contact`, `clearance_department`, `clearance_form_title`, `clearance_term`.
- `student/clearance.php` reads keys prefixed `clearance_` to render printable form.
- Also toggles office visibility via `offices.status`.

### Frontend (`assets/js/app.js`, `assets/css/*`)
- PHT clock (`Asia/Manila`, 1s interval), dark mode in `localStorage clearance-theme`, collapsible/mobile sidebar, school switcher dropdown.
- `filterTable()`: client-side search + `select[data-filter]` (`status`, `role`).
- Role-based user form fields (`student-only-field`, `office-only-field`, dean course required logic).
- Floating modals (`data-form-target`), delete-confirm modal, auto-dismiss notices (4s), `Ctrl+K` sidebar search, notifications button routes to `office/reviews.php` or `clearance.php`.

## Admin Portal (`admin/`)

- `dashboard.php`: counts `students(active)`, `offices`, `office_assignatories`; `SUM(overall_status=pending/cleared/not_cleared)` from `clearance_requests`; last 8 requests join `students`.
- `users.php`: full account management. Auto-generates `SC-001...` by scanning `students.student_number` with regex `/^SC\s*-?\s*(\d+)$/i`. Conflict check on username/email. Avatar validation (jpeg/png/webp, 2MB, `random_bytes(12)` filename). 4-branch update (avatar/password combos). Insert uses transaction `users` + `students` (splits `full_name` into first/middle/last). Toggle blocks self. Delete blocks admins/self, cascades `students` or `office_assignatories` in transaction.
- `students.php`: edit/toggle/delete only — creation blocked with message to use Users page. Course filter dropdown, `SC-` numeric sort.
- `offices.php`: CRUD on `offices(office_name, description, status)`, toggle active/inactive.
- `assignatories.php`: signatories linked to office user (`user_id`) or manual name (`assignatory_name`, `user_id=NULL`). Requires office + position. List uses `COALESCE(assignatory_name, users.full_name)`. Toggle only, no delete.
- `courses.php`: CRUD on `courses(course_code UNIQUE, course_name, description, status)`, auto-creates table.
- `clearance.php`: monitoring + per-request requirements editor. Auto-creates `clearance_items`. Algorithm `recalculateClearanceStatus(id)`: read `clearance_transactions.status` → `pending` if empty, `not_cleared` if any `rejected`, `cleared` if all `approved`, else `pending`; updates `clearance_requests.overall_status`. Recalculates all requests on every GET. `save_requirements` deletes + re-inserts items skipping removed/empty rows.
- `announcements.php`: CRUD + publish/draft toggle, `created_by=$_SESSION[user_id]`.
- `profile.php`: self edit (name/email/avatar/password), email uniqueness check, syncs session.
- `_header.php` / `_footer.php`: shared shell, sidebar (Dashboard/Profile/Students/Offices/Courses/Assignatories/Clearance/Form Configuration/Users/Announcements/Settings), topbar, dark toggle, delete modal.

## Student Portal (`student/`)

- Common: `require_role("student")`, sidebar (Dashboard/My Clearance/Announcements/Profile), PHT clock, avatar.
- `dashboard.php`:
  - Loads `students WHERE user_id=?`, detects first-year (`year_level='1st Year'`).
  - Reads `system_settings.clearance_term` (default `2nd Semester S.Y. 2025 - 2026`), parses semester/year via regex.
  - Create request: checks `allow_clearance_requests`, duplicate check on `(student_id, school_year, semester)`, transaction inserts `clearance_requests` + one `clearance_transactions` row per active office, skipping `NSTP/NSRC` (normalized `preg_replace('/[^a-z]/')`) for non-first-years.
  - Progress: `approved/total*100` rounded. Shows identity card, stats, progress table, 3 latest published announcements.
- `clearance.php`: printable form (school header from `system_settings`, logo `ckcm transparent.png`, Name/Course meta, Office / In Charge / Signature table with `pending/approved/rejected` badges). Request selector via `?request=id`. Same create-request algorithm. Loads offices with active assignatories (`GROUP BY`), transactions map, read-only `clearance_items`.
- `announcements.php`: lists `status='published'` newest first.
- `profile.php`: edits `users.full_name/email/password` (no avatar), shows student number/course/year/status.

## Office Portal (`office/`)

- Common: `require_role("office")`, sidebar shows Reviews/Assignatories only for deans.
- `dashboard.php`: resolves `office_id`s via `office_assignatories WHERE status='active' AND (user_id=? OR assigned_by_user_id=?)`, lists transactions join requests/students/offices, counts pending/approved/rejected.
- `reviews.php` (dean only): review queue scoped to offices the dean owns (`assigned_by_user_id=?`) and course (`students.course = users.college_dean_course`). Update: validates office ownership, whitelists `pending/approved/rejected`, `UPDATE clearance_transactions SET status, remarks, reviewed_at=NOW()`, then same overall-status recalculation as admin. Filters: office dropdown (GET auto-submit), status dropdown (JS), search field.
- `assignatories.php` (dean only): CRUD for named signatories (`user_id=NULL`, `assigned_by_user_id=self`). Duplicate check on `(assignatory_name, office_id)`. All edits scoped by `assigned_by_user_id`.
- `announcements.php`: own announcements CRUD, all queries scoped `created_by=self`; list shows published + own drafts.
- `profile.php`: edits name/email/password + avatar upload (same 2MB jpeg/png/webp rules), shows current office/position.

## Database (inferred from queries)

- `users(user_id, username, password, full_name, email, avatar_path, department, college_dean, college_dean_course, role, status, created_at)`
- `students(student_id, user_id, student_number, first_name, middle_name, last_name, course, year_level, email, status)`
- `offices(office_id, office_name, description, status, created_at)`
- `office_assignatories(assignatory_id, user_id NULL, assignatory_name, assigned_by_user_id, office_id, position, status)`
- `clearance_requests(clearance_id, student_id, school_year, semester, overall_status, requested_at, updated_at)`
- `clearance_transactions(..., clearance_id, office_id, status, remarks, reviewed_at)`
- `clearance_items(item_id, clearance_id CASCADE, office_id CASCADE, item_name, notes, is_required, is_completed, created_at)`
- `courses(course_id, course_code UNIQUE, course_name, description, status, created_at)`
- `announcements(id/announcement_id, title, content, status published/draft, created_by, created_at)`
- `system_settings(setting_key PK, setting_value TEXT, updated_at)`

Key algorithms: overall-status derivation (any rejected → not_cleared; all approved → cleared; else pending), SC-number generation (max numeric suffix + 1, zero-padded to 3), NSTP/NSRC exclusion for 2nd–4th year, full-name split for student records.

## Security Notes

- Prepared statements for user input; `e()` on output; `password_hash` / `password_verify`; `finfo` MIME + size checks on uploads; self-deletion and admin-deletion blocked; dean/owner scoping on office writes.
#   c l e a r a n c e  
 