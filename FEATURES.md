# Student Clearance Management System — Features & Functionalities

Role-based PHP/MySQLi portal for Christ The King College De Maranding, Inc. (CKCM).
Project root: `C:\xampp\htdocs\clearance` · Open via `http://localhost/clearance/admin/login.php`.

---

## 1. Roles & Login

- **Single shared login** (`admin/login.php`) serves all three roles. Already-logged-in users redirect to their dashboard.
- **Admin** — full management (students, offices, courses, assignatories, clearance, requirement types, school terms, reports, archive, announcements, form config, settings, users, notifications, profile).
- **Student** — Dashboard, My Clearance, Notifications, Announcements, My Profile.
- **Office** — Dashboard always; **Clearance Reviews** + **Department Assignatories** only for College Dean accounts (`users.college_dean=1`, course-restricted); Announcements; Notifications; My Profile.
- **Session protection** (`config/auth.php`): `require_role()` on every protected page, `session_regenerate_id()` on login, `e()` output escaping.
- **Maintenance mode** (`system_settings.maintenance_mode`): non-admin logins blocked with a notice; full-page maintenance screen for visitors (`index.php`).

## 2. Student Clearance Lifecycle (core flow)

1. Admin sets the **current term** in School Terms (synced to `system_settings.clearance_term`).
2. Student submits **one request per school year + semester** (`student/dashboard.php`, `student/clearance.php`):
   - Blocked when `allow_clearance_requests=0` or term is unparseable.
   - Duplicate `(student_id, school_year, semester)` rejected.
   - Transaction: `INSERT clearance_requests` + one `clearance_transactions` row per **active** office (NSTP/NSRC office skipped for non-1st-Year students).
3. Offices/deans set each transaction to `pending | approved | rejected` with remarks (`office/reviews.php`).
4. **Overall status recalculation** after every review (and self-healed on each admin clearance page load):
   - `total==0` → `pending`; any `rejected` → `not_cleared`; all `approved` → `cleared`; else `pending`.
5. Student tracks per-office status, remarks, `reviewed_at`, progress % (`approved/total`), and prints the form.

## 3. Notifications (real-time / automatic)

- Table: `notifications(notification_id, user_id FK, title, message, type, related_id, is_read, created_at)`.
- Helpers: `config/notifications.php` — `notify_user()`, `notify_role()`, `get_unread_notification_count()`, `get_current_term()`; schema self-creates via `config/db.php`.
- **Automatic triggers:**
  - New student request → all admins + assigned office users (incl. deans via `assigned_by_user_id`).
  - Office approve/reject/pending → the student (with office name, remarks, overall status).
  - Admin edits requirements → the student.
  - Announcement published (admin or office) → students (+ offices for admin posts).
- **UI:** bell icon with live unread badge in all 3 topbars; `*/notifications.php` pages (All/Unread, Mark read, Mark all read, Delete); badge polling every 30s via `notifications_unread.php` (`assets/js/app.js`).
- Sidebar menu item: Notifications (all roles).

## 4. Clearance Certificate (official, downloadable)

- Available **only** when `overall_status='cleared'`.
- `student/certificate.php?request=ID` (owner-only) and `admin/certificate.php?request=ID` (any cleared request, incl. archived).
- Contents: school header (from Form Configuration), `CERTIFICATE OF CLEARANCE` title, Certificate No. `CKCM-CLR-YYYY-XXXXX`, student identity, term, per-office table (office / signed by / status), issue date, Registrar + Dean signature lines.
- **Download = Print to PDF** via `window.print()` with print CSS (nav/buttons hidden).
- Entry points: success banner + `Download Clearance Certificate` button (`student/clearance.php`, `student/dashboard.php`); `Certificate` links in `admin/clearance.php` and `admin/archive.php`; always-on `Print form` button on My Clearance.

## 5. Archive of Completed Clearances

- Storage: `clearance_requests.is_archived` + `archived_at` flag, plus `clearance_archives` log table (clearance, student, term, status, `archived_by`, `archived_at`).
- `admin/clearance.php` lists **active only**; per-row **Archive** action (confirm modal) moves the record.
- `admin/archive.php`: archived-only list, status filter + search, counts (total/cleared/not cleared/pending), **Restore** (un-archives, removes log row), Certificate links for cleared rows.
- Sidebar menu item: Archive (admin).

## 6. Reports (admin)

- `admin/reports.php`: summary cards (Total / Pending / Cleared / Not cleared, respecting filters except status) + detailed table (student, term, status, office progress `approved/total`, requested date; 500-row limit).
- **Filters (server-side GET, auto-submit on change):** school year, semester, status, course, date-from/to (validated `YYYY-MM-DD`, invalid ignored, From/To auto-swapped), Include-archived checkbox, Apply/Reset, active-filter pills with per-filter remove, result count.
- **Date UX:** calendar-icon inputs (`max=today`), presets (Today / Last 7 days / This month / Clear), client guard against From > To.
- **Exports:** `Export Excel (CSV)` preserves current filters (UTF-8 BOM, Excel-compatible); `Print / PDF` via print CSS (nav, filters, stats hidden).
- Sidebar + Dashboard quick link: Reports.

## 7. Requirement Types (master data)

- Table: `requirement_types(requirement_id, office_id FK, requirement_name, description, is_required, status, created_at)`.
- `admin/requirement_types.php`: full CRUD + activate/deactivate + delete (confirm modal), office filter + search, reusable across requests.
- Integration: `admin/clearance.php` detail panel has **Apply requirement templates** (copies all active types, skips exact duplicates) + `Manage templates` shortcut.
- Per-request custom items still live in `clearance_items` (unchanged editor).

## 8. School Terms (semester + school year master data)

- Table: `school_terms(term_id, school_year `YYYY-YYYY`, semester `1st Semester|2nd Semester|Summer`, term_label, is_current, status)` with unique `(school_year, semester)`; seeded once from legacy `clearance_term`.
- `admin/school_terms.php`: CRUD + **Set current** (exclusively one current; syncs `system_settings.clearance_term`), no-delete guard on current term.
- `get_current_term()` prefers the current active term, falls back to parsing the legacy free-text setting.
- `admin/form_configuration.php` term field links to School Terms.
- Sidebar menu item: School Terms (admin).

## 9. Offices, Assignatories & Dean Role

- **Offices** (`admin/offices.php`): CRUD + activate/deactivate. `status='active'` controls transaction generation, printable-form rows, and dashboards.
- **Assignatories** (`admin/assignatories.php`): signatory per office linked to an office `user_id` **or** a manual `assignatory_name` (`user_id=NULL`), with `assigned_by_user_id` owner tracking; toggle-only (no delete).
- **Dean role** (`users.college_dean` + `college_dean_course`, `is_dean_user()`): deans review only students of their course (`office/reviews.php` filters `students.course = college_dean_course`) and manage **Department Assignatories** (`office/assignatories.php`, owner-scoped CRUD for named signatories).
- **Office dashboard** (`office/dashboard.php`): queues resolved via `(user_id OR assigned_by_user_id)`; pending/approved/rejected counts; assigned-office label.

## 10. Courses, Students & Users

- **Courses** (`admin/courses.php`): CRUD + toggle, unique `course_code`; feeds student/user dropdowns and report filters.
- **Students** (`admin/students.php`): edit/toggle/delete only (creation happens via Users); course filter + `SC-` numeric sort.
- **Users** (`admin/users.php`): admin/student/office accounts; auto-creates linked `students` row; **auto student number** `SC-001…` (max+1, zero-padded); username/email conflict checks; avatar upload (jpeg/png/webp ≤2MB → `assets/uploads/profiles/user_<hex>.<ext>`); password hashing; dean-course-required validation; self-toggle/admin-delete guards; role filter + search.
- **NSTP/NSRC rule:** offices normalizing to `nstpnsrc` are skipped at request creation and hidden in dashboards/forms for non-1st-Year students.

## 11. Announcements (vs Notifications)

- **Announcements** = human-written broadcasts (`announcements` table, `published|draft`, `created_by`); admin has full CRUD + publish/draft toggle; office users CRUD strictly on own posts (`created_by=self` enforced via `affected_rows`).
- Students see latest 3 on dashboard + full published list; offices see `published OR own`.
- **Notifications** = automatic system alerts (Section 3). Publishing an announcement also fires notifications (admin → students + offices; office → students).

## 12. Form Configuration & System Settings

- **Form Configuration** (`admin/form_configuration.php`): school name/address/contact, department, form title, term (upserted to `system_settings`, empty → defaults); live paper preview; office-visibility table (Remove/Add toggles `offices.status`, history kept).
- **Settings** (`admin/settings.php`): `allow_clearance_requests` (students can start requests), `allow_office_remarks`, `maintenance_mode` — single Save.
- **Profile** (`admin/profile.php`, `office/profile.php` with avatar; `student/profile.php` without avatar): self edit with email uniqueness + password change, session sync.

## 13. UI / UX Details

- Shared app shell (`*_header.php` + `*_footer.php`): fixed sidebar (250px → 72px mini → hidden + full-width content when `body.sidebar-collapsed` on desktop), collapsible via toggle (persisted in `localStorage`), mobile drawer, sidebar search + `Ctrl/Cmd+K`, org switcher, **dark mode** (persisted), **PHT live clock** (`Asia/Manila`), Lucide icons, topbar avatar.
- `assets/js/app.js` (no backend calls except badge polling): notice auto-dismiss (4s), clock, dark mode, sidebars,一模一样的 school menu, `filterTable()` client search for simple lists (server-side GET forms opt out via `data-server-filter`), floating modals (`data-form-target`, backdrop/`Escape` close), delete-confirm modal (`data-delete-confirm`), help button.
- Status pills: `pending/approved/cleared/active/rejected/not_cleared/inactive/draft/published`.
- Printable clearance form (`student/clearance.php`): school header + logo (`ckcm transparent.png`), Name/Course rows, Office / In Charge / Signature badge table.
- Responsive: single-column landing ≤760px; content padding shrinks ≤620px; 2-col stats ≤620px; review table scrolls (1180px min).

## 14. Security & Conventions

- Prepared statements for inputs, `e()` on output, `password_hash`/`password_verify`, `session_regenerate_id`, owner scoping (`assigned_by_user_id`, `created_by`), `random_bytes` avatar names, `affected_rows` guards, PRG pattern with `$_SESSION[flash_message]`.
- Security headers (`X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`) in `config/db.php`.
- Known limits: no pagination (full scans; reports capped at 500), admin clearance page recalculates all active requests per load, no audit logs, no public self-registration, no email/2FA/SSL enforcement, chat/discussion/file-sharing intentionally excluded per delimitation.
