STUDENT CLEARANCE MANAGEMENT SYSTEM
====================================

TECH STACK
- PHP
- MySQL / MariaDB
- MySQLi
- HTML/CSS
- JavaScript
- Lucide Icons

SETUP
1. Put the student_clearance_system folder inside XAMPP/htdocs.
2. Start Apache and MySQL from XAMPP.
3. Open phpMyAdmin.
4. Import database/student_clearance_db.sql.
5. Check config/db.php and change database credentials if needed.
6. Open:
   http://localhost/student_clearance_system/admin/create_admin.php
7. The starter admin account is:
   Username: admin
   Password: admin123
8. Delete admin/create_admin.php after creating the account.
9. Open:
   http://localhost/student_clearance_system/admin/login.php

DESIGN
The dashboard uses a separate assets/css/dashboard.css file.
Icons are SVG icons from Lucide Icons rather than emojis.
Dark mode preference is saved in localStorage.

CURRENT SCOPE
- Role-based login for admin, student, and office users
- Admin session protection
- Admin dashboard with database statistics
- Student listing
- Office listing
- Office assignatory listing
- Clearance monitoring
- User listing
- Announcement creation, editing, publishing, and deletion
- Settings placeholder
- Profile page
- Logout
- Student/Office starter dashboard files

NEXT DEVELOPMENT
- Add/create/edit/delete forms for students
- Add/create/edit/delete offices
- Create office assignatory accounts
- Student registration/login
- Office assignatory login
- Clearance creation per school year/semester
- Office approval/rejection workflow
- Automatic overall clearance status
- Notifications and announcements
- Admin audit logs
