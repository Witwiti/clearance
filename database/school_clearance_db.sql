-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: school_clearance_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `announcements`
--

DROP TABLE IF EXISTS `announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcements` (
  `announcement_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(180) NOT NULL,
  `content` text NOT NULL,
  `status` enum('published','draft') NOT NULL DEFAULT 'published',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`announcement_id`),
  KEY `fk_announcement_user` (`created_by`),
  CONSTRAINT `fk_announcement_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `announcements`
--

LOCK TABLES `announcements` WRITE;
/*!40000 ALTER TABLE `announcements` DISABLE KEYS */;
/*!40000 ALTER TABLE `announcements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `clearance_archives`
--

DROP TABLE IF EXISTS `clearance_archives`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clearance_archives` (
  `archive_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `clearance_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `school_year` varchar(20) NOT NULL,
  `semester` varchar(30) NOT NULL,
  `overall_status` varchar(20) NOT NULL DEFAULT 'cleared',
  `archived_by` int(10) unsigned DEFAULT NULL,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`archive_id`),
  UNIQUE KEY `clearance_id` (`clearance_id`),
  CONSTRAINT `fk_archive_request` FOREIGN KEY (`clearance_id`) REFERENCES `clearance_requests` (`clearance_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clearance_archives`
--

LOCK TABLES `clearance_archives` WRITE;
/*!40000 ALTER TABLE `clearance_archives` DISABLE KEYS */;
/*!40000 ALTER TABLE `clearance_archives` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `clearance_items`
--

DROP TABLE IF EXISTS `clearance_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clearance_items` (
  `item_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `clearance_id` int(10) unsigned NOT NULL,
  `office_id` int(10) unsigned NOT NULL,
  `item_name` varchar(120) NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `is_completed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`item_id`),
  KEY `fk_item_clearance` (`clearance_id`),
  KEY `fk_item_office` (`office_id`),
  CONSTRAINT `fk_item_clearance` FOREIGN KEY (`clearance_id`) REFERENCES `clearance_requests` (`clearance_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_item_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clearance_items`
--

LOCK TABLES `clearance_items` WRITE;
/*!40000 ALTER TABLE `clearance_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `clearance_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `clearance_requests`
--

DROP TABLE IF EXISTS `clearance_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clearance_requests` (
  `clearance_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_id` int(10) unsigned NOT NULL,
  `school_year` varchar(20) NOT NULL,
  `semester` varchar(30) NOT NULL,
  `overall_status` enum('pending','cleared','not_cleared') NOT NULL DEFAULT 'pending',
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`clearance_id`),
  KEY `fk_clearance_student` (`student_id`),
  CONSTRAINT `fk_clearance_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10000 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clearance_requests`
--

LOCK TABLES `clearance_requests` WRITE;
/*!40000 ALTER TABLE `clearance_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `clearance_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `clearance_transactions`
--

DROP TABLE IF EXISTS `clearance_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clearance_transactions` (
  `transaction_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `clearance_id` int(10) unsigned NOT NULL,
  `office_id` int(10) unsigned NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `remarks` varchar(255) DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`transaction_id`),
  KEY `fk_transaction_clearance` (`clearance_id`),
  KEY `fk_transaction_office` (`office_id`),
  KEY `fk_transaction_user` (`reviewed_by`),
  CONSTRAINT `fk_transaction_clearance` FOREIGN KEY (`clearance_id`) REFERENCES `clearance_requests` (`clearance_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_transaction_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_transaction_user` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clearance_transactions`
--

LOCK TABLES `clearance_transactions` WRITE;
/*!40000 ALTER TABLE `clearance_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `clearance_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `courses`
--

DROP TABLE IF EXISTS `courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `courses` (
  `course_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `course_code` varchar(30) NOT NULL,
  `course_name` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`course_id`),
  UNIQUE KEY `course_code` (`course_code`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `courses`
--

LOCK TABLES `courses` WRITE;
/*!40000 ALTER TABLE `courses` DISABLE KEYS */;
INSERT INTO `courses` VALUES (1,'BSCS','Bachelor Of Science In Computer Science','','active','2026-09-22 17:24:43'),(2,'BSCRIM','Bachelor Of Science In Criminology','','active','2026-09-22 17:29:11'),(3,'DSA','Dean of Student Affairs','Not a course but needed for clearance configuration purposes.','active','2026-09-22 20:33:31'),(4,'BSEd','Bachelor Of Science In Education','','active','2026-09-23 04:16:39');
/*!40000 ALTER TABLE `courses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `notification_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(30) NOT NULL DEFAULT 'general',
  `related_id` int(10) unsigned DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `idx_notifications_user` (`user_id`,`is_read`,`created_at`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `office_assignatories`
--

DROP TABLE IF EXISTS `office_assignatories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `office_assignatories` (
  `assignatory_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `assignatory_name` varchar(120) DEFAULT NULL,
  `assigned_by_user_id` int(10) unsigned DEFAULT NULL,
  `office_id` int(10) unsigned NOT NULL,
  `position` varchar(100) NOT NULL DEFAULT 'Office Assignatory',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`assignatory_id`),
  KEY `fk_assignatory_user` (`user_id`),
  KEY `fk_assignatory_office` (`office_id`),
  CONSTRAINT `fk_assignatory_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_assignatory_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `office_assignatories`
--

LOCK TABLES `office_assignatories` WRITE;
/*!40000 ALTER TABLE `office_assignatories` DISABLE KEYS */;
/*!40000 ALTER TABLE `office_assignatories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `offices`
--

DROP TABLE IF EXISTS `offices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `offices` (
  `office_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `office_name` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `scope` enum('general','department') NOT NULL DEFAULT 'general',
  `course_name` varchar(120) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`office_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `offices`
--

LOCK TABLES `offices` WRITE;
/*!40000 ALTER TABLE `offices` DISABLE KEYS */;
INSERT INTO `offices` VALUES (1,'Dean of Student Affairs (DSA)','','general',NULL,'active','2026-09-22 16:01:17'),(2,'Registrar','','general',NULL,'active','2026-09-22 16:01:37'),(3,'Guidance Counselor','','general',NULL,'active','2026-09-22 16:01:50'),(4,'Librarian','','general',NULL,'active','2026-09-22 16:01:59'),(5,'CMO (Institutional)','','general',NULL,'active','2026-09-22 16:02:09'),(6,'Dept. Head/Dean','Dept. Head/Dean','general',NULL,'active','2026-09-22 16:02:25'),(7,'SSC President','','general',NULL,'active','2026-09-22 16:02:38'),(8,'Dept. Treasurer','Dept. Treasurer','department',NULL,'active','2026-09-22 16:02:47'),(9,'NSTP–NSRC','','general',NULL,'active','2026-09-22 16:02:55'),(10,'Finance Officer','','general',NULL,'active','2026-09-22 16:03:14'),(11,'VP for Research, Extension & Development','','general',NULL,'active','2026-09-22 16:03:37'),(12,'VP for Academic Affairs','','general',NULL,'active','2026-09-22 16:03:47'),(13,'Executive Vice President','','general',NULL,'active','2026-09-22 16:03:56'),(10000,'Dept. Club','department club','department',NULL,'active','2026-10-07 20:57:33');
/*!40000 ALTER TABLE `offices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `requirement_types`
--

DROP TABLE IF EXISTS `requirement_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `requirement_types` (
  `requirement_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `office_id` int(10) unsigned NOT NULL,
  `requirement_name` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`requirement_id`),
  KEY `idx_reqtype_office` (`office_id`,`status`),
  CONSTRAINT `fk_reqtype_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `requirement_types`
--

LOCK TABLES `requirement_types` WRITE;
/*!40000 ALTER TABLE `requirement_types` DISABLE KEYS */;
/*!40000 ALTER TABLE `requirement_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `school_terms`
--

DROP TABLE IF EXISTS `school_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `school_terms` (
  `term_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `school_year` varchar(20) NOT NULL,
  `semester` varchar(30) NOT NULL,
  `term_label` varchar(100) NOT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`term_id`),
  UNIQUE KEY `uq_school_terms` (`school_year`,`semester`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_terms`
--

LOCK TABLES `school_terms` WRITE;
/*!40000 ALTER TABLE `school_terms` DISABLE KEYS */;
INSERT INTO `school_terms` VALUES (1,'2025-2026','2nd Semester','2nd Semester S.Y. 2025 - 2026',1,'active','2026-10-04 13:18:22');
/*!40000 ALTER TABLE `school_terms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `students` (
  `student_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `student_number` varchar(50) NOT NULL,
  `first_name` varchar(60) NOT NULL,
  `middle_name` varchar(60) DEFAULT NULL,
  `last_name` varchar(60) NOT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `course` varchar(120) DEFAULT NULL,
  `year_level` varchar(30) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`student_id`),
  UNIQUE KEY `student_number` (`student_number`),
  KEY `fk_student_user` (`user_id`),
  CONSTRAINT `fk_student_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10000 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `students`
--

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `setting_key` varchar(80) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES ('allow_clearance_requests','1','2026-09-22 20:26:26'),('allow_office_remarks','1','2026-09-22 20:26:26'),('clearance_department','COLLEGE DEPARTMENT','2026-09-22 17:38:19'),('clearance_form_title','STUDENT CLEARANCE FORM','2026-09-22 17:38:19'),('clearance_school_address','Maranding, Lala, Lanao del Norte, Philippines 9211','2026-09-22 17:38:19'),('clearance_school_contact','Tel. (063) 338-7039','2026-09-22 17:38:19'),('clearance_school_name','CHRIST THE KING COLLEGE DE MARANDING, INC.','2026-09-22 17:38:19'),('clearance_signatory_one_name','','2026-09-22 17:38:19'),('clearance_signatory_one_office_id','11','2026-09-22 17:38:19'),('clearance_signatory_one_role','','2026-09-22 17:38:19'),('clearance_signatory_one_status','active','2026-09-22 17:38:19'),('clearance_signatory_one_user_id','0','2026-09-22 17:38:19'),('clearance_signatory_three_name','','2026-09-22 17:38:19'),('clearance_signatory_three_office_id','13','2026-09-22 17:38:19'),('clearance_signatory_three_role','','2026-09-22 17:38:19'),('clearance_signatory_three_status','active','2026-09-22 17:38:19'),('clearance_signatory_three_user_id','0','2026-09-22 17:38:19'),('clearance_signatory_two_name','','2026-09-22 17:38:19'),('clearance_signatory_two_office_id','12','2026-09-22 17:38:19'),('clearance_signatory_two_role','','2026-09-22 17:38:19'),('clearance_signatory_two_status','active','2026-09-22 17:38:19'),('clearance_signatory_two_user_id','0','2026-09-22 17:38:19'),('clearance_term','2nd Semester S.Y. 2025 - 2026','2026-09-22 17:38:19'),('maintenance_mode','0','2026-09-22 20:31:43');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(120) NOT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `department` varchar(120) DEFAULT NULL,
  `college_dean` tinyint(1) NOT NULL DEFAULT 0,
  `college_dean_course` varchar(120) DEFAULT NULL,
  `role` enum('admin','student','office') NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `last_login` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin','$2y$10$fVNJkLeaEUF2yJ4f2AP3d.TbsxpqNz5OJo3zrGO7XMyxOoDW6pQT6','System Administrator','admin@school.edu',NULL,NULL,0,NULL,'admin','active',NULL,NULL,'2026-09-22 14:52:21'),(6,'felma','$2y$10$GgWAY8r5icUApUL01exZzufFZxcppk2o913u.oLBj1emMgImU0kHi','Felma F. Pamat','felmapamat@ckcm.edu.ph',NULL,NULL,1,'Bachelor Of Science In Computer Science','office','active',NULL,NULL,'2026-10-07 20:52:51');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-08  5:20:16
