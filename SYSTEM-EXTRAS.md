# System Features the Paper Does Not Explain Well

Features that already exist in the code but are missing or only lightly
mentioned in the manuscript. Use this as a companion to `FEATURES.md`
when updating the documentation (Chapter 3 / System Features section).

---

## 1. Special Dean Role and Course-Based Restriction

- **What it is:** an `office` account flagged as College Dean
  (`users.college_dean=1` + `users.college_dean_course`, e.g. `BSIT`).
  Helper: `is_dean_user()` in `config/auth.php`.
- **What changes:** only deans see **Clearance Reviews** and
  **Department Assignatories** in the office sidebar
  (`office/_header.php`); non-dean office accounts get an
  "Access restricted" panel (`office/reviews.php`).
- **Course restriction:** the review queue is scoped to
  `students.course = users.college_dean_course`, so a BSIT dean only
  reviews BSIT students.
- **Where:** `admin/users.php` (dean flag + course fields),
  `office/reviews.php`, `office/assignatories.php`,
  `office/dashboard.php`.

## 2. Full Assignatory Management

- **What it is:** a complete module for "who signs for each office".
- **Two kinds of signatories** (`office_assignatories` table):
  - Linked to a user account (`user_id`), or
  - Manual named signatory (`assignatory_name`, `user_id=NULL`).
- **Admin side** (`admin/assignatories.php`): assign any signatory to
  any office + position; toggle active/inactive.
- **Dean side** (`office/assignatories.php`): deans manage their own
  department signatories (owner-scoped by `assigned_by_user_id`,
  duplicate-guarded, full CRUD).
- **Where it shows:** the In Charge column of the printable clearance
  form and the certificate's Signed By column
  (`COALESCE(assignatory_name, full_name)` + position).

## 3. Maintenance Mode

- **What it is:** one switch that takes the whole portal offline for
  non-admins (`system_settings.maintenance_mode`).
- **Behavior:** visitors see a standalone maintenance page
  (`index.php`); logged-in students/offices are redirected there;
  only admins can sign in (`admin/login.php` shows
  "Only administrators can sign in right now").
- **Where:** `admin/settings.php` (toggle), `config/db.php` +
  `index.php` (enforcement).

## 4. Form Configuration

- **What it is:** admin-editable header of every printable clearance
  form (`admin/form_configuration.php` → `system_settings` keys
  `clearance_school_name`, `clearance_school_address`,
  `clearance_school_contact`, `clearance_department`,
  `clearance_form_title`, `clearance_term`).
- **Extras:** live paper preview; empty values fall back to CKCM
  defaults; **office visibility table** — Remove/Add toggles
  `offices.status` (affects reviews, assignments, and the form, but
  keeps history).

## 5. System Settings (Allow Requests, Allow Remarks)

- **What they are** (`admin/settings.php`, one Save button):
  - `allow_clearance_requests` — when off, students cannot start new
    requests ("currently disabled").
  - `allow_office_remarks` — system-wide remarks toggle.
  - `maintenance_mode` — see Section 3.
- **Enforcement:** checked in `student/dashboard.php` and
  `student/clearance.php` before every request creation.

## 6. NSTP/NSRC Automatic Exclusion

- **What it is:** offices whose name normalizes to `nstpnsrc`
  (`preg_replace('/[^a-z]/i','',name)`, case-insensitive) are
  **automatically skipped for 2nd–4th Year students** and included
  only for 1st Year students.
- **Where:** request creation (`student/dashboard.php`,
  `student/clearance.php`), dashboard progress, and the printable
  form filter the same way — no manual per-student setup needed.

## 7. Dark Mode + Live Clock

- **Dark mode:** sidebar switch, persisted in
  `localStorage clearance-theme` (`body.dark` overrides in
  `assets/css/dashboard.css`); clicking the whole row toggles it.
- **Live PHT clock:** topbar badge ticking every second via
  `Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila' })`
  in `assets/js/app.js`.
- Both work for all roles with zero backend calls.

## 8. Announcements Module

- **What it is:** a full publishing system, not just "notifications".
- **Admin** (`admin/announcements.php`): CRUD + Publish/Draft toggle,
  author tracking (`created_by`), 180-char title limit.
- **Office** (`office/announcements.php`): same, but strictly scoped —
  an office can only edit/toggle/delete posts it created
  (`created_by=self`, `affected_rows` guard); list shows
  `published OR own`.
- **Students** (`student/announcements.php` + dashboard widget):
  read-only published list. Publishing also fires automatic
  notifications (see `FEATURES.md` Section 3).

## 9. Avatar Uploads

- **Who:** admin (`admin/profile.php`, `admin/users.php`) and office
  users (`office/profile.php`). (Students edit name/email/password
  only.)
- **Rules:** jpeg/png/webp, ≤2MB (`finfo` MIME check), stored as
  `assets/uploads/profiles/user_<24 hex>.<ext>` via `random_bytes` +
  `move_uploaded_file`; 4-branch `UPDATE` (avatar/password present or
  not); session avatar synced; topbar/sidebar fall back to
  `system_logo.jpg`.

## 10. Auto Student Number Generation

- **What it is:** `SC-001, SC-002, …` assigned automatically when the
  student number field is left empty (`admin/users.php`).
- **How:** `$generateStudentNumber()` scans all
  `students.student_number` matching `/^SC\s*-?\s*(\d+)$/i` and
  returns `SC-` + zero-padded `max+1` (3 digits).
- **Related:** `admin/students.php` sorts by the numeric part of
  `SC-` numbers so the list stays in true number order.
