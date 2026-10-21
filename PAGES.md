# System Pages & Functions by Role

Student Clearance Management System (`scms.v1.0.2`). Three roles — **Admin**, **Office**, **Student** — each with its own portal. Every portal page enforces its role (`require_role(...)`); users only ever see and change their own data except where the admin role explicitly manages records.

## Shared shell (all roles)

- **Sidebar (PC/laptop)** — app logo + motto, page search (`Ctrl K`), role menu, version line (`scms.v1.0.2 / Developed by BSCS STUDENT`). Hidden on mobile (≤ 800 px).
- **Bottom navigation (mobile only)** — Home / role tabs + a **More** button that slides up the remaining pages. Student has 4 tabs and no More sheet.
- **Topbar** — page title, notification bell with unread badge + dropdown (mark-all-read, per-item mark-read, "View all notifications"), help button (floating Help modal; text adapts to mobile vs PC), avatar menu (Profile, Change password, Dark Mode toggle, Logout with floating confirm modal).
- **Floating confirm modal** — used for delete/archive/toggle/password/logout/dedup confirmations (Cancel + action button, Esc/backdrop close).
- **Login (`admin/login.php`, shared)** — email + password sign-in for all roles; blocks inactive accounts and non-admins during maintenance mode; redirects each role to its dashboard.
- **Logout (`admin/logout.php`)** — ends the session after a "Log out?" confirm; returns to login.

---

## Admin (`admin/`)

Full control over master data, requests, and system behavior.

- **Dashboard (`dashboard.php`)** — At-a-glance overview: stat cards (students, offices, assignatories, pending/cleared/not-cleared — archived excluded) and the 8 most recent active clearance requests. Quick links to report, archive, requirement types, terms, notifications.
- **Students (`students.php`)** — Manage student records (number, name, course, year level, email, status). Functions: edit via `?edit=` floating form (new records must be created from Users), activate/deactivate toggle, delete with confirm (also removes their clearance requests), client-side search.
- **Offices (`offices.php`)** — Manage clearance offices (name, description, scope `general`/`department`, status). Functions: Add/Edit floating form, activate/deactivate, delete with confirm, search. Inactive offices disappear from new requests.
- **Courses (`courses.php`)** — Manage academic programs feeding student/user dropdowns. Functions: Add/Edit form (code must be unique), activate/deactivate, delete with confirm (blocked while students use the course), search.
- **Assignatories (`assignatories.php`)** — Link signatories to offices: either an existing office user or a manual name, plus office, position, status. Functions: Add/Edit, activate/deactivate, delete with confirm, search. This is how office staff get review access.
- **Institutional Clubs (`institutional_clubs.php`)** — Manage student clubs. Functions: Add/Edit club name + moderator (pick an existing office user or create one inline with hashed password), activate/deactivate, delete with confirm, search. Students choose one club per request; only that moderator sees its approval.
- **Clearance (`clearance.php`)** — Monitor active requests. Functions: auto-recalculates each request's overall status on load, search + status filter, per-request drawer to edit office items, certificate link for cleared requests, archive with confirm, and the requirement-publishing panel (push a requirement template to all students or selected students, with notifications; unpublish removes it).
- **Requirement Types (`requirement_types.php`)** — Master list of reusable office requirements. Functions: Add/Edit (office, name, description, required flag, status), office filter + search, scope shown as Public vs Custom(n), publish/unpublish with student targeting and notifications, activate/deactivate, delete.
- **School Terms (`school_terms.php`)** — Semester/school-year master data. Functions: Add/Edit (validated `YYYY-YYYY` year + semester select, auto label), Set current (syncs the term every new request uses), activate/deactivate, delete (current term protected).
- **Reports (`reports.php`)** — Filtered summaries and records. Functions: stat cards, filters (school year, semester, status, course, date range with presets, include-archived), removable filter pills, Excel CSV export, Print/PDF, and **Clean duplicates** (keeps newest request per student+term, archives older pending ones).
- **Archive (`archive.php`)** — Filed records. Functions: counts, status filter + search, per-row certificate link (cleared only) and Restore, back-to-clearance link.
- **Notifications (`notifications.php`)** — Personal inbox. Functions: All/Unread toggle, per-row mark-read, mark-all-read, delete with confirm; strictly own rows.
- **Form Configuration (`form_configuration.php`)** — Branding/text printed on every clearance form (school name, address, contact, department, title, term) with live preview, plus the Offices-on-the-form table (add/remove offices from new requests; history kept).
- **Users (`users.php`)** — Manage login accounts (admin/student/office). Functions: Add/Edit (uniqueness + password + avatar-upload validation, dean/course/student fields per role, auto `SC-00N` numbers with linked student rows), role filter + search, activate/deactivate (not self), delete with confirm (blocks admin role and self).
- **Announcements (`announcements.php`)** — Publish updates to students and offices. Functions: New/Edit floating form (title, content, draft/published), publish/draft toggle, delete with confirm, search; publishing notifies students + offices.
- **Settings (`settings.php`)** — System switches. Functions: single Save form — allow student clearance requests, allow office remarks, maintenance mode (locks out non-admins).
- **Profile (`profile.php`)** — Own info and picture. Functions: avatar upload (type/size checked, session synced), name/email edit with uniqueness check, read-only role/status/login meta.
- **Change password (`change_password.php`)** — Own password. Functions: current/new/confirm form with show-hide toggles and an "Update password?" confirm modal; length, match, and current-password verification.
- **Certificate (`certificate.php`)** — Printable Certificate of Clearance for a cleared request. Functions: request lookup with cleared-gate, cert number, school branding, offices/signatories table, signature lines, print CSS.

## Office (`office/`)

Review clearances and manage department signatories. Review access requires an assignment (direct link, dean ownership, club moderator, or DSA); unassigned users see a "No office assignment" panel.

- **Dashboard (`dashboard.php`)** — Workload overview for assigned offices/clubs (archived excluded). Functions: Pending/Approved/Rejected stat cards, assigned-office label, Open reviews button.
- **Clearance Reviews (`reviews.php`)** — The core queue: one row per student request with a single bulk decision. Functions: approve/reject/pending + remarks per student (applies to every step the reviewer is authorized for), automatic overall-status recalculation and student notification, signatory/club dropdown filters, client status filter + search. Deans see only their course; direct staff see all courses for their office; moderators see only their club; DSA sees SSC. The *Overall status* column shows the request-level status with a breakdown (`x of y offices approved`, plus how many offices rejected or are still pending) so it is clear why a request is not cleared even when the reviewer's own step is approved.
- **Requirement compliance checklist (`reviews.php`)** — A collapsible *Requirement checklist* panel in the *Checklist* column of each queue row, listing the `clearance_items` of the offices the reviewer is authorized for. Each requirement has a *Complied* checkbox plus its office, required/optional flag and notes. Saving the checklist marks the checked requirements as complied and sends the unchecked ones back for action — including for students who already complied (an already-approved office step is set back to `pending`, the overall status is recalculated and the student is notified). An office can only ever touch its own requirements.
- **Department Assignatories (`assignatories.php`, dean only)** — Manage signatories for the dean's course, including the Head/Dean seat (each dean's entry shows only for their course). Functions: Add/Edit (department offices + Head/Dean seat), activate/deactivate and delete with confirm, search; all rows owner-scoped.
- **SSC Assignatories (`ssc_assignatories.php`, DSA only)** — Same pattern fixed to the SSC President seat (no office picker).
- **Notifications (`notifications.php`)** — Personal inbox: All/Unread toggle, mark-read, mark-all-read; own rows only.
- **Announcements (`announcements.php`)** — Publish reminders to students. Functions: New/Edit form (title, content, draft/published), publish/draft toggle, delete; only own rows editable; publishing notifies students.
- **Profile (`profile.php`)** — Own info, avatar upload, read-only office-assignment badge and account meta.
- **Change password (`change_password.php`)** — Own password with the same validations + confirm modal as admin.

## Student (`student/`)

Request and track personal clearance. All data scoped to the logged-in student's own record.

- **Dashboard (`dashboard.php`)** — Clearance journey overview. Functions: New Request floating form (admin-configured read-only term + required club picker), identity card, Current-status / Office-progress-% / Total-requests cards, per-office progress table, My Requirements table, latest announcements, certificate banner when cleared. Submit rules: requests toggle must be on, term complete, valid club, **no duplicate request for the term**, and **no other pending request** (must finish the current one before requesting another term).
- **My Clearance (`clearance.php`)** — The printable clearance form. Functions: same New Request form + rules (success jumps to the new request), per-office signatory resolution (dean match preferred, DSA boost, club moderator shown), Office / In Charge / Signature table with status badges, Print form (form-only print CSS), certificate download when cleared.
- **Notifications (`notifications.php`)** — Personal inbox for review updates. Functions: All/Unread toggle, mark-read, mark-all-read, delete, "View clearance" shortcut per row.
- **Announcements (`announcements.php`)** — Read-only list of published announcements (date, title, content).
- **Profile (`profile.php`)** — Contact details + picture. Functions: avatar upload (auto-submit, type/size checked), editable suffix/gender/birth-date/contact/address with validation; name, number, course, year level read-only (admin-managed).
- **Change password (`change_password.php`)** — Own password with the same validations + confirm modal as other roles.
- **Certificate (`certificate.php`)** — Official printable/PDF Certificate of Clearance. Functions: ownership + fully-cleared gate, cert number, offices/signatories table, signature lines, print CSS.
