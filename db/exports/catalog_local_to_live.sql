-- Copy LOCAL catalog onto LIVE (Hostinger u556809062_tocca_db).
-- Generated from local tocca_db on 2026-08-17.
--
-- BEFORE THIS FILE:
--   1. Run db/migrations/012_live_missing_schema.sql
--   2. Keep config.local.php as-is (do not import tbl_config)
--
-- THIS REPLACES live: events, categories, award titles, businesses,
-- nature of business, registration/tracking/voting form copy, TWG scores.
-- It does NOT change: admin users, voters, votes, emails, site root URL.
--
-- phpMyAdmin: select u556809062_tocca_db -> Import this file.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';

DELETE FROM tbl_twg_member_scores;
DELETE FROM tbl_twg_scores;
DELETE FROM tbl_draft_vote_proof;
DELETE FROM tbl_vote_proof;
DELETE FROM tbl_draft_choice;
DELETE FROM tbl_draft_freetext;
DELETE FROM tbl_poll_choice;
DELETE FROM tbl_poll_freetext;
DELETE FROM tbl_choice_media;
DELETE FROM tbl_choice_tokens;
DELETE FROM tbl_question_choices;
DELETE FROM tbl_choice_establishment_types;
DELETE FROM tbl_nomination_establishment_types;
DELETE FROM tbl_establishment_type_awards;
DELETE FROM tbl_nomination_answers;
DELETE FROM tbl_nomination_questions;
DELETE FROM tbl_nomination_question_audit;
DELETE FROM tbl_nomination_media;
DELETE FROM tbl_nomination_audit;
DELETE FROM tbl_nominations;
DELETE FROM tbl_voter_portal_copy;
DELETE FROM tbl_nomination_texts;
DELETE FROM tbl_nomination_fields;
DELETE FROM tbl_questions;
DELETE FROM tbl_categories;
DELETE FROM tbl_choices;
DELETE FROM tbl_establishment_types;
DELETE FROM tbl_events;
-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: tocca_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `tbl_events`
--

/*!40000 ALTER TABLE `tbl_events` DISABLE KEYS */;
INSERT INTO `tbl_events` (`event_id`, `event_name`, `public_slug`, `year`, `description`, `nomination_start`, `nomination_end`, `voting_start`, `voting_end`, `reminder_grace_hours`, `is_active`, `total_votes`, `is_archived`, `archived_date`, `created_at`) VALUES (7,'TOCCA 2026 Awards','tocca-2024-awards',2026,'','2026-08-17 01:35:00','2026-08-17 08:00:00','2026-08-17 09:05:00','2026-08-21 21:02:00',48,1,0,0,'2025-10-16 16:45:54','2025-10-16 06:53:48'),(11,'Bagong Awards','bagong-awards',2025,'','2025-10-17 10:36:00','2025-10-18 10:36:00','2026-06-30 10:37:00','2026-07-03 10:37:00',48,0,0,1,'2026-08-17 02:48:41','2025-10-17 10:38:17'),(22,'Test  Event','test-event',2026,'This is a test event created by Rysha Gucela on May 24, 2026.','2026-05-28 02:44:00','2026-05-28 04:30:00','2026-05-28 17:04:00','2026-06-22 09:00:00',48,0,0,1,'2026-08-17 02:48:39','2026-05-24 14:46:46');
/*!40000 ALTER TABLE `tbl_events` ENABLE KEYS */;

--
-- Dumping data for table `tbl_categories`
--

/*!40000 ALTER TABLE `tbl_categories` DISABLE KEYS */;
INSERT INTO `tbl_categories` (`category_id`, `category_name`, `status`, `voting_profile`, `event_id`) VALUES (10,'Food',1,'business',7),(11,'Service',1,'business',7),(13,'Feelings',1,'mixed',7),(30,'Food',1,'business',22),(31,'Service',1,'business',22),(32,'Retail',1,'business',22),(33,'Feelings',1,'mixed',22),(35,'Test Category',1,'business',22),(37,'New Category',1,'business',22);
/*!40000 ALTER TABLE `tbl_categories` ENABLE KEYS */;

--
-- Dumping data for table `tbl_questions`
--

/*!40000 ALTER TABLE `tbl_questions` DISABLE KEYS */;
INSERT INTO `tbl_questions` (`question_id`, `question_name`, `category_id`, `choice_type`, `answer_fields`) VALUES (63,'Best Chicken Barbecue',10,1,'business_photo'),(64,'Best Chocolate Cake',10,1,'business_photo'),(65,'Best Halo-Halo',10,1,'business_photo'),(67,'Best Pancit',10,1,'business_photo'),(68,'Best Salon',11,1,'business_photo'),(70,'Best Barbershop',11,1,'business_photo'),(71,'Best Breakup Food',13,0,'product_business'),(72,'Best Date Place',13,0,'product_business'),(73,'Best Hangover Recovery Food',13,0,'product_business'),(76,'Best Hamburger',10,1,'business_photo'),(77,'Best Café',11,1,'business_photo'),(79,'Best Flower Shop',11,1,'business_photo'),(102,'Best Chicken Barbecue',30,1,'business_photo'),(103,'Best Hair Salon',31,1,'business_photo'),(104,'Best Grocery Shop',32,1,'business_photo'),(105,'Best Date Place',33,0,'product_business'),(107,'Test Award Options',35,1,'business_photo'),(108,'Test Award Freeform',35,1,'business_photo'),(110,'Best in Pakbet',30,1,'business_photo'),(111,'Best in Fried Chicken',30,1,'business_photo'),(112,'Best Break Up Song',33,0,'song_singer'),(113,'Best Adobo',10,1,'business_photo'),(114,'Best Sinigang/Tinola',10,1,'business_photo'),(115,'Best Dinuguan',10,1,'business_photo'),(116,'Best Crispy Pata',10,1,'business_photo'),(117,'Best Sisig',10,1,'business_photo'),(118,'Best Siomai',10,1,'business_photo'),(119,'Best Siopao',10,1,'business_photo'),(120,'Best Empanada',10,1,'business_photo'),(121,'Best Leche Flan',10,1,'business_photo'),(122,'Best Local Ice Cream',10,1,'business_photo'),(123,'Best Ube Delicacy',10,1,'business_photo'),(124,'Best Pineapple Delicacy',10,1,'business_photo'),(125,'Best Pandesal',10,1,'business_photo'),(126,'Best Meat Bread',10,1,'business_photo'),(127,'Best Sliced Bread',10,1,'business_photo'),(128,'Best Local Pizza',10,1,'business_photo'),(129,'Best Chorizo or Longganiza',10,1,'business_photo'),(130,'Best Porkchop',10,1,'business_photo'),(131,'Best Make-up Artist',11,0,'product_business'),(132,'Best Local Massage Services',11,1,'business_photo'),(133,'Best Hotel',11,1,'business_photo'),(134,'Best Little Hotel (Mabuhay Accommodation)',11,1,'business_photo'),(135,'Best Photo/Video Service',11,1,'business_photo'),(136,'Best Event Stylist',11,1,'business_photo'),(137,'Best Delivery Services',11,1,'business_photo'),(138,'Best Comfort Food',13,0,'product_business'),(139,'Best Date Food',13,0,'product_business'),(140,'Best Happy Food',13,0,'product_business'),(141,'Best Heart Break Food',13,0,'product_business'),(142,'Best Make-up Food',13,0,'product_business'),(143,'Best Study Buddy',13,0,'product_business'),(144,'Best Sad Food',13,0,'product_business'),(145,'Best Break Up Song',13,0,'song_singer');
/*!40000 ALTER TABLE `tbl_questions` ENABLE KEYS */;

--
-- Dumping data for table `tbl_choices`
--

/*!40000 ALTER TABLE `tbl_choices` DISABLE KEYS */;
INSERT INTO `tbl_choices` (`choice_id`, `choice_name`, `public_slug`, `email`, `status`, `on_ballot`, `event_id`, `establishment_type_id`, `qr_sent`) VALUES (1,'25th Lane Ormoc','25th-lane-ormoc','amfcapacio@gmail.com',1,0,7,4,0),(2,'Milagrina Jo\'s Chicken','milagrina-jo-s-chicken','gucelarysha@gmail.com',1,0,7,6,0),(3,'Kutaw','kutaw','gucelarysha@gmail.com',1,1,7,6,0),(4,'Laysholog\'s Eatery','laysholog-s-eatery','amfcapacio@gmail.com',1,0,7,4,0),(5,'Angel Event','angel-event','amfcapacio@gmail.com',1,0,7,40,0),(6,'Angel\'s Burger','angel-s-burger','amfcapacio@gmail.com',1,0,7,4,0),(7,'Everything Iced','everything-iced','gucelarysha@gmail.com',1,1,7,4,1),(8,'Dayka Bakeshop','dayka-bakeshop','gucelarysha@gmail.com',1,0,7,4,0),(9,'4ever Cafe','4ever-cafe','amfcapacio@gmail.com',1,1,7,6,1);
/*!40000 ALTER TABLE `tbl_choices` ENABLE KEYS */;

--
-- Dumping data for table `tbl_question_choices`
--

/*!40000 ALTER TABLE `tbl_question_choices` DISABLE KEYS */;
INSERT INTO `tbl_question_choices` (`id`, `question_id`, `choice_id`, `on_ballot`) VALUES (1,77,1,0),(2,125,1,0),(3,127,1,0),(4,63,2,0),(5,65,2,0),(6,115,2,0),(7,116,2,0),(8,117,2,0),(9,121,2,0),(11,63,3,1),(12,64,3,1),(13,67,3,1),(14,114,3,1),(15,115,3,1),(16,76,4,0),(17,114,4,0),(18,116,4,0),(19,118,4,0),(20,129,4,0),(21,130,4,0),(22,134,5,0),(23,136,5,0),(24,145,5,0),(25,63,6,0),(26,73,6,0),(27,113,6,0),(28,117,6,0),(29,129,6,0),(30,138,6,0),(31,140,6,0),(32,141,6,0),(33,142,6,0),(34,77,7,1),(35,117,7,1),(36,118,7,1),(37,119,7,1),(38,130,7,1),(39,125,8,0),(40,127,8,0),(41,73,9,1),(42,138,9,1),(43,141,9,1),(44,142,9,1),(45,143,9,1),(46,144,9,1);
/*!40000 ALTER TABLE `tbl_question_choices` ENABLE KEYS */;

--
-- Dumping data for table `tbl_establishment_types`
--

/*!40000 ALTER TABLE `tbl_establishment_types` DISABLE KEYS */;
INSERT INTO `tbl_establishment_types` (`type_id`, `type_name`, `status`, `display_order`, `created_at`, `updated_at`) VALUES (2,'Fastfood',0,60,'2025-10-18 03:34:46','2026-08-15 15:01:19'),(4,'Café',1,80,'2025-10-18 03:45:07','2026-08-15 15:01:19'),(6,'Restaurant / Food Stall / Food Cart / Food Kiosk / Café / Fastfood / Snack House / Eatery',1,10,'2025-10-18 06:27:20','2026-08-15 15:01:19'),(16,'Salon / Parlor',1,40,'2026-05-24 08:00:49','2026-08-15 15:01:19'),(17,'Grocery',0,NULL,'2026-05-24 08:00:49','2026-08-15 13:53:57'),(18,'Date Spot',0,NULL,'2026-05-24 08:00:49','2026-08-15 13:53:57'),(19,'Karaoke Bar',0,NULL,'2026-05-24 08:00:49','2026-08-15 13:53:57'),(20,'Test Establishment Type',0,NULL,'2026-05-24 08:58:12','2026-08-15 13:53:57'),(21,'Eatery',0,80,'2026-05-25 04:32:01','2026-08-15 15:01:19'),(22,'Bakeshop',1,20,'2026-08-12 15:59:10','2026-08-15 15:01:19'),(23,'Carinderia / Eatery',0,NULL,'2026-08-12 15:59:10','2026-08-15 13:53:57'),(24,'Catering Service',0,NULL,'2026-08-12 15:59:10','2026-08-15 13:53:57'),(25,'Grocery / Mini Mart',0,NULL,'2026-08-12 15:59:10','2026-08-15 13:53:57'),(26,'Meat Shop',0,NULL,'2026-08-12 15:59:10','2026-08-15 13:53:57'),(27,'Dried Fish / Pasalubong',0,NULL,'2026-08-12 15:59:10','2026-08-15 13:53:57'),(28,'Fruit Stand',0,NULL,'2026-08-12 15:59:10','2026-08-15 13:53:57'),(29,'Flowershop',1,90,'2026-08-12 15:59:10','2026-08-15 15:01:19'),(30,'Barbershop',1,30,'2026-08-12 15:59:10','2026-08-15 15:01:19'),(31,'Hair Salon',0,NULL,'2026-08-12 15:59:10','2026-08-15 13:53:57'),(32,'Food Stall',0,20,'2026-08-15 13:53:57','2026-08-15 15:01:19'),(33,'Food Cart',0,30,'2026-08-15 13:53:57','2026-08-15 15:01:19'),(34,'Food Kiosk',0,40,'2026-08-15 13:53:57','2026-08-15 15:01:19'),(35,'Snack House',0,70,'2026-08-15 13:53:57','2026-08-15 15:01:19'),(36,'Parlor',0,120,'2026-08-15 13:53:57','2026-08-15 13:56:21'),(37,'Massage Parlor / Spa',1,50,'2026-08-15 13:53:57','2026-08-15 15:01:19'),(38,'Spa',0,140,'2026-08-15 13:53:57','2026-08-15 13:56:21'),(39,'Hotel',1,60,'2026-08-15 13:53:57','2026-08-15 15:01:19'),(40,'Mabuhay Accommodation',1,70,'2026-08-15 13:53:57','2026-08-15 15:01:19'),(41,'Photo and Video Service',1,100,'2026-08-15 13:53:57','2026-08-15 15:01:19'),(42,'Event Organizer',1,110,'2026-08-15 13:53:57','2026-08-15 15:01:19'),(43,'Food Courier / Freight Forwarding Services',1,120,'2026-08-15 13:53:57','2026-08-15 15:01:19'),(44,'Freight Forwarding Services',0,210,'2026-08-15 13:53:57','2026-08-15 13:56:21'),(45,'Song',1,130,'2026-08-15 13:53:57','2026-08-15 15:01:19');
/*!40000 ALTER TABLE `tbl_establishment_types` ENABLE KEYS */;

--
-- Dumping data for table `tbl_establishment_type_awards`
--

/*!40000 ALTER TABLE `tbl_establishment_type_awards` DISABLE KEYS */;
INSERT INTO `tbl_establishment_type_awards` (`id`, `type_id`, `question_id`, `created_at`) VALUES (39,17,104,'2026-05-24 16:00:49'),(40,18,105,'2026-05-24 16:00:49'),(44,20,108,'2026-05-24 16:59:33'),(45,20,107,'2026-05-24 16:59:33'),(47,16,103,'2026-05-25 12:34:35'),(48,16,108,'2026-05-25 12:34:35'),(49,21,105,'2026-05-28 11:57:36'),(50,21,102,'2026-05-28 11:57:36'),(51,21,111,'2026-05-28 11:57:36'),(52,21,110,'2026-05-28 11:57:37'),(574,6,113,'2026-08-15 23:01:19'),(575,6,114,'2026-08-15 23:01:19'),(576,6,115,'2026-08-15 23:01:19'),(577,6,116,'2026-08-15 23:01:19'),(578,6,117,'2026-08-15 23:01:19'),(579,6,67,'2026-08-15 23:01:19'),(580,6,118,'2026-08-15 23:01:19'),(581,6,119,'2026-08-15 23:01:19'),(582,6,120,'2026-08-15 23:01:19'),(583,6,63,'2026-08-15 23:01:19'),(584,6,64,'2026-08-15 23:01:19'),(585,6,121,'2026-08-15 23:01:19'),(586,6,122,'2026-08-15 23:01:19'),(587,6,123,'2026-08-15 23:01:19'),(588,6,124,'2026-08-15 23:01:19'),(589,6,65,'2026-08-15 23:01:19'),(590,22,125,'2026-08-15 23:01:19'),(591,6,126,'2026-08-15 23:01:19'),(592,22,127,'2026-08-15 23:01:19'),(593,6,76,'2026-08-15 23:01:19'),(594,6,128,'2026-08-15 23:01:19'),(595,6,129,'2026-08-15 23:01:19'),(596,6,130,'2026-08-15 23:01:19'),(597,30,70,'2026-08-15 23:01:19'),(598,16,68,'2026-08-15 23:01:19'),(599,16,131,'2026-08-15 23:01:19'),(600,37,132,'2026-08-15 23:01:19'),(601,39,133,'2026-08-15 23:01:19'),(602,40,134,'2026-08-15 23:01:19'),(603,4,77,'2026-08-15 23:01:19'),(604,29,79,'2026-08-15 23:01:19'),(605,41,135,'2026-08-15 23:01:19'),(606,42,136,'2026-08-15 23:01:19'),(607,43,137,'2026-08-15 23:01:19'),(608,6,71,'2026-08-15 23:01:19'),(609,6,138,'2026-08-15 23:01:19'),(610,6,139,'2026-08-15 23:01:19'),(611,6,73,'2026-08-15 23:01:19'),(612,6,140,'2026-08-15 23:01:19'),(613,6,141,'2026-08-15 23:01:19'),(614,6,142,'2026-08-15 23:01:19'),(615,6,143,'2026-08-15 23:01:19'),(616,6,144,'2026-08-15 23:01:19'),(617,45,145,'2026-08-15 23:01:19'),(618,6,72,'2026-08-15 23:01:19');
/*!40000 ALTER TABLE `tbl_establishment_type_awards` ENABLE KEYS */;

--
-- Dumping data for table `tbl_choice_establishment_types`
--

/*!40000 ALTER TABLE `tbl_choice_establishment_types` DISABLE KEYS */;
INSERT INTO `tbl_choice_establishment_types` (`choice_id`, `type_id`, `created_at`) VALUES (1,4,'2026-08-17 03:19:47'),(1,22,'2026-08-17 03:19:47'),(2,6,'2026-08-17 03:51:27'),(2,43,'2026-08-17 03:51:27'),(3,6,'2026-08-17 04:49:30'),(4,4,'2026-08-17 04:51:35'),(4,6,'2026-08-17 04:51:35'),(5,40,'2026-08-17 04:58:19'),(5,42,'2026-08-17 04:58:19'),(5,43,'2026-08-17 04:58:19'),(5,45,'2026-08-17 04:58:19'),(6,4,'2026-08-17 04:58:40'),(6,6,'2026-08-17 04:58:40'),(7,4,'2026-08-17 04:58:47'),(7,6,'2026-08-17 04:58:47'),(7,43,'2026-08-17 04:58:47'),(8,4,'2026-08-17 04:58:51'),(8,22,'2026-08-17 04:58:51'),(9,6,'2026-08-17 04:58:55');
/*!40000 ALTER TABLE `tbl_choice_establishment_types` ENABLE KEYS */;

--
-- Dumping data for table `tbl_nomination_establishment_types`
--

/*!40000 ALTER TABLE `tbl_nomination_establishment_types` DISABLE KEYS */;
INSERT INTO `tbl_nomination_establishment_types` (`nomination_id`, `type_id`, `created_at`) VALUES (1,4,'2026-08-17 03:07:36'),(1,22,'2026-08-17 03:07:36'),(2,6,'2026-08-17 03:12:28'),(2,43,'2026-08-17 03:12:28'),(3,6,'2026-08-17 04:45:20'),(4,4,'2026-08-17 04:36:37'),(4,6,'2026-08-17 04:36:37'),(5,4,'2026-08-17 04:38:55'),(5,6,'2026-08-17 04:38:55'),(5,43,'2026-08-17 04:38:55'),(6,40,'2026-08-17 04:41:41'),(6,42,'2026-08-17 04:41:41'),(6,43,'2026-08-17 04:41:41'),(6,45,'2026-08-17 04:41:41'),(7,4,'2026-08-17 04:42:48'),(7,6,'2026-08-17 04:42:48'),(8,4,'2026-08-17 04:43:23'),(8,22,'2026-08-17 04:43:23'),(9,6,'2026-08-17 04:47:58');
/*!40000 ALTER TABLE `tbl_nomination_establishment_types` ENABLE KEYS */;

--
-- Dumping data for table `tbl_nomination_fields`
--

/*!40000 ALTER TABLE `tbl_nomination_fields` DISABLE KEYS */;
INSERT INTO `tbl_nomination_fields` (`id`, `label`, `name`, `type`, `options`, `is_required`, `is_active`, `sort_order`, `event_id`, `help_text`, `placeholder`, `validation_json`, `profile_role`, `field_width`, `created_at`, `updated_at`) VALUES (24,'Business Name','business_name','text',NULL,1,1,0,NULL,NULL,'Enter your business name',NULL,'custom','half','2025-10-16 08:17:46','2026-08-15 11:14:08'),(25,'Owner/President/General Manager','name_of_ownder_president_general_manager','text',NULL,1,1,1,NULL,NULL,NULL,NULL,'custom','half','2025-10-16 08:18:19','2026-08-15 11:14:08'),(26,'Mobile Number','mobile_number','tel',NULL,1,1,2,NULL,NULL,'Enter your mobile number',NULL,'mobile','half','2025-10-16 08:18:34','2026-08-15 11:14:08'),(27,'Type of Ownership','designation_in_the_business_company','select','[\"Sole Proprietorship\",\"Partnership\",\"Limited Liability Company\",\"Corporation\"]',1,1,4,NULL,NULL,NULL,NULL,'business_name','half','2025-10-16 08:20:57','2026-08-15 11:14:08'),(28,'Mayor\'s Permit','mayor_s_permit_number','file',NULL,1,1,4,22,'Upload a clear photo of your Mayor\'s Permit (PNG, JPG, or WEBP).',NULL,'{\"accept\":\".png,.jpg,.jpeg,.webp\"}','mayor_permit','full','2025-10-16 08:21:21','2026-08-13 02:18:27'),(29,'Email','email','email',NULL,1,1,3,NULL,NULL,NULL,NULL,'email','half','2025-10-16 08:21:36','2026-08-15 11:14:08'),(30,'Website','website','url',NULL,0,1,6,NULL,NULL,NULL,NULL,'custom','half','2025-10-16 08:21:54','2026-08-15 11:14:08'),(31,'Business/Company Address','business_company_address','text',NULL,1,1,7,NULL,NULL,NULL,NULL,'custom','half','2025-10-16 08:22:24','2026-08-15 11:14:08'),(32,'Business/Company Logo','business_company_logo','file',NULL,0,1,8,NULL,NULL,NULL,NULL,'custom','half','2025-10-16 08:22:41','2026-08-15 11:14:08'),(35,'Mayor\'s Permit','mayor_s_permit','file',NULL,1,1,5,7,NULL,NULL,NULL,'mayor_permit','half','2026-08-13 01:12:40','2026-08-15 11:14:08');
/*!40000 ALTER TABLE `tbl_nomination_fields` ENABLE KEYS */;

--
-- Dumping data for table `tbl_nomination_texts`
--

/*!40000 ALTER TABLE `tbl_nomination_texts` DISABLE KEYS */;
INSERT INTO `tbl_nomination_texts` (`id`, `event_id`, `section`, `title`, `subtitle`, `body_html`, `bullets_json`, `is_active`, `updated_at`) VALUES (32,1,'intro',NULL,NULL,'The City Government of Ormoc through the Tatak Ormoc Business Awards Organizing Committee in partnership with the Ormoc City Chamber of Commerce and Industry and STI College ΓÇô Ormoc is pleased to inform the public of the opening of the 2024 Tatak Ormoc ConsumersΓÇÖ Choice Awards (TOCCA)\r\n\r\nThe 2024 TOCCA determines which Ormocanon products and services are top of mind to the citizens, and recognizes the best among them.',NULL,0,'2025-10-15 21:53:35'),(33,1,'instructions','Instructions',NULL,NULL,'[\"Please complete all required fields before submitting.\",\"Select the category and awards that best fit your business.\",\"Use the address search to auto-fill your exact location.\",\"Prepare your official business or company name.\",\"Have a high-resolution company logo ready (PNG\\/JPG\\/WEBP, max 10MB).\",\"Keep your current Mayor\'s Permit number on hand.\",\"Provide a complete business address: Street, Barangay, City\\/Municipality, Province, and Postal Code.\",\"Know the full name of the owner, president, or general manager.\",\"Ensure you have a mobile number in 09XXXXXXXXX format.\"]',1,'2025-10-15 21:54:40'),(34,7,'intro',NULL,NULL,'The City Government of Ormoc through the **Tatak Ormoc Business Awards Organizing Committee** in partnership with the Ormoc City Chamber of Commerce and Industry and STI College ΓÇô Ormoc is pleased to inform the public of the opening of the **2026 Tatak Ormoc ConsumersΓÇÖ Choice Awards (TOCCA)**\r\n\r\nThe 2026 TOCCA determines which Ormocanon products and services are top of mind to the citizens, and recognizes the best among them.',NULL,1,'2026-08-12 15:27:21'),(35,7,'instructions','Instructions',NULL,NULL,'[\"Please complete all required fields before submitting.\",\"Select the category and awards that best fit your business.\",\"Use the address search to auto-fill your exact location.\",\"Prepare your official business or company name.\",\"Have a high-resolution company logo ready (PNG\\/JPG\\/WEBP, max 10MB).\",\"Keep your current Mayor\'s Permit number on hand.\",\"Provide a complete business address: Street, Barangay, City\\/Municipality, Province, and Postal Code.\",\"Know the full name of the owner, president, or general manager.\",\"Ensure you have a mobile number in **09XXXXXXXXX** format.\"]',1,'2025-10-16 00:15:16'),(39,22,'intro',NULL,NULL,'This is a test **test**',NULL,1,'2026-05-25 04:52:43'),(40,22,'instructions','Instructions',NULL,NULL,'[\"This is an introduction test for event Test Event\",\"Test lang\",\"Oh lage test\"]',1,'2026-05-24 08:19:08');
/*!40000 ALTER TABLE `tbl_nomination_texts` ENABLE KEYS */;

--
-- Dumping data for table `tbl_voter_portal_copy`
--

/*!40000 ALTER TABLE `tbl_voter_portal_copy` DISABLE KEYS */;
INSERT INTO `tbl_voter_portal_copy` (`event_id`, `intro_title`, `intro_body`, `how_to_title`, `how_to_lead`, `steps_json`, `footer_note`, `updated_at`) VALUES (22,'Welcome to TOCCA 2024','The City Government of Ormoc through the Tatak Ormoc Business Awards Organizing Committee in partnership with the Ormoc City Chamber of Commerce and Industry is pleased to inform the public of the opening of the **2024 Tatak Ormoc Consumers\' Choice Awards (TOCCA)**.\r\n\r\nThe 2024 TOCCA determines which Ormocanon products and services are top of mind to the citizens, and recognizes the best among them. Vote and choose which of your favorites deserves to be called one of Tatak Ormoc!\r\n\r\nA nomination was held last **August 4ΓÇô28, 2024**, to the general public through Google Form and drop boxes located in a conspicuous place within the City. The top businesses are then placed in each award category.\r\n\r\n\r\n**QUALIFICATIONS:**\r\n- Duly registered and in good standing per records of the Business Permits and Licensing Office (BPLO) and other regulatory offices;\r\n- The nominated business must have been in operation for at least one (1) year at the time of the award.','How to Vote','The 2024 Tatak Ormoc Consumers\' Choice Awards include 58 categories. Follow these steps to cast your ballot.','[{\"title\":\"Verify your mobile number\",\"body\":\"one ballot per mobile. New voters complete OTP and set a 4-digit access code; returning voters enter mobile + code.\"},{\"title\":\"Choose your best per category\",\"body\":\"pick from nominated businesses, search the list, or type a business name if it is not listed.\"},{\"title\":\"Review your summary\",\"body\":\"check every award before submitting. Use Back to change any category.\"},{\"title\":\"Cast your votes\",\"body\":\"submit each award from the summary page or use Vote All when every answer is ready.\"}]','Businesses entered manually may be reviewed by the awards body for eligibility.','2026-05-28 03:22:35');
/*!40000 ALTER TABLE `tbl_voter_portal_copy` ENABLE KEYS */;

--
-- Dumping data for table `tbl_choice_media`
--

/*!40000 ALTER TABLE `tbl_choice_media` DISABLE KEYS */;
INSERT INTO `tbl_choice_media` (`id`, `choice_id`, `media_type`, `file_path`, `caption`, `sort_order`, `uploaded_at`) VALUES (1,1,'image','uploads/choice_media/1/inbound7510725086124216254_1786907257_4429.webp',NULL,1,'2026-08-17 03:19:47'),(2,1,'image','uploads/choice_media/1/inbound2325483702175484897_1786907257_7416.jpg',NULL,2,'2026-08-17 03:19:47'),(3,1,'image','uploads/choice_media/1/inbound7234411269428853490_1786907257_3676.png',NULL,3,'2026-08-17 03:19:47'),(4,3,'image','uploads/choice_media/3/inbound1081676000256984626_1786912488_8347.png',NULL,1,'2026-08-17 04:49:30'),(5,3,'image','uploads/choice_media/3/inbound5266122388194571584_1786912488_2282.png',NULL,2,'2026-08-17 04:49:30'),(6,3,'image','uploads/choice_media/3/inbound7081419689590849375_1786912488_1797.png',NULL,3,'2026-08-17 04:49:30'),(7,8,'image','uploads/choice_media/8/inbound6112881218929156048_1786913003_8560.png',NULL,1,'2026-08-17 04:58:51'),(8,8,'image','uploads/choice_media/8/inbound179751392495419870_1786913003_8230.png',NULL,2,'2026-08-17 04:58:51');
/*!40000 ALTER TABLE `tbl_choice_media` ENABLE KEYS */;

--
-- Dumping data for table `tbl_choice_tokens`
--

/*!40000 ALTER TABLE `tbl_choice_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `tbl_choice_tokens` ENABLE KEYS */;

--
-- Dumping data for table `tbl_twg_scores`
--

/*!40000 ALTER TABLE `tbl_twg_scores` DISABLE KEYS */;
INSERT INTO `tbl_twg_scores` (`question_id`, `choice_id`, `twg_average`, `updated_at`) VALUES (63,2,4.00,'2026-08-17 05:19:23'),(63,3,6.20,'2026-08-17 04:50:21'),(63,6,4.60,'2026-08-17 05:18:06'),(64,3,6.20,'2026-08-17 04:50:21'),(65,2,6.00,'2026-08-17 05:19:23'),(67,3,5.20,'2026-08-17 04:50:21'),(73,6,6.20,'2026-08-17 05:18:06'),(73,9,6.80,'2026-08-17 05:01:05'),(76,4,5.40,'2026-08-17 05:19:11'),(77,1,7.02,'2026-08-17 04:02:05'),(77,7,5.60,'2026-08-17 05:19:01'),(113,6,7.60,'2026-08-17 05:18:06'),(114,3,6.80,'2026-08-17 04:50:21'),(114,4,6.00,'2026-08-17 05:19:12'),(115,2,7.20,'2026-08-17 05:19:23'),(115,3,6.00,'2026-08-17 04:50:21'),(116,2,4.60,'2026-08-17 05:19:23'),(116,4,6.00,'2026-08-17 05:19:11'),(117,2,8.60,'2026-08-17 05:19:23'),(117,6,6.20,'2026-08-17 05:18:06'),(117,7,6.00,'2026-08-17 05:19:01'),(118,4,9.00,'2026-08-17 05:19:12'),(118,7,7.00,'2026-08-17 05:19:01'),(119,7,5.00,'2026-08-17 05:19:01'),(121,2,4.20,'2026-08-17 05:19:23'),(125,1,6.66,'2026-08-17 04:02:04'),(125,8,4.60,'2026-08-17 05:17:45'),(127,1,6.52,'2026-08-17 04:02:05'),(127,8,7.20,'2026-08-17 05:17:45'),(129,4,4.00,'2026-08-17 05:19:11'),(129,6,4.40,'2026-08-17 05:18:06'),(130,4,6.60,'2026-08-17 05:19:12'),(130,7,6.80,'2026-08-17 05:19:00'),(134,5,8.00,'2026-08-17 05:18:20'),(136,5,6.20,'2026-08-17 05:18:20'),(138,6,3.00,'2026-08-17 05:18:05'),(138,9,6.20,'2026-08-17 05:01:05'),(140,6,8.40,'2026-08-17 05:18:06'),(141,6,4.00,'2026-08-17 05:18:06'),(141,9,6.20,'2026-08-17 05:01:05'),(142,6,7.40,'2026-08-17 05:18:06'),(142,9,7.40,'2026-08-17 05:01:05'),(143,9,6.00,'2026-08-17 05:01:06'),(144,9,5.20,'2026-08-17 05:01:05'),(145,5,5.60,'2026-08-17 05:18:20');
/*!40000 ALTER TABLE `tbl_twg_scores` ENABLE KEYS */;

--
-- Dumping data for table `tbl_twg_member_scores`
--

/*!40000 ALTER TABLE `tbl_twg_member_scores` DISABLE KEYS */;
INSERT INTO `tbl_twg_member_scores` (`question_id`, `choice_id`, `member_key`, `score`, `updated_at`) VALUES (63,2,'bplo',1.00,'2026-08-17 05:19:23'),(63,2,'ledipo',2.00,'2026-08-17 05:19:23'),(63,2,'lgu_1',6.00,'2026-08-17 05:19:23'),(63,2,'lgu_2',5.00,'2026-08-17 05:19:23'),(63,2,'orcham',6.00,'2026-08-17 05:19:23'),(63,3,'bplo',8.00,'2026-08-17 04:50:21'),(63,3,'ledipo',6.00,'2026-08-17 04:50:21'),(63,3,'lgu_1',7.00,'2026-08-17 04:50:21'),(63,3,'lgu_2',5.00,'2026-08-17 04:50:21'),(63,3,'orcham',5.00,'2026-08-17 04:50:21'),(63,6,'bplo',1.00,'2026-08-17 05:18:06'),(63,6,'ledipo',6.00,'2026-08-17 05:18:06'),(63,6,'lgu_1',2.00,'2026-08-17 05:18:06'),(63,6,'lgu_2',5.00,'2026-08-17 05:18:06'),(63,6,'orcham',9.00,'2026-08-17 05:18:06'),(64,3,'bplo',9.00,'2026-08-17 04:50:21'),(64,3,'ledipo',6.00,'2026-08-17 04:50:21'),(64,3,'lgu_1',2.00,'2026-08-17 04:50:21'),(64,3,'lgu_2',4.00,'2026-08-17 04:50:21'),(64,3,'orcham',10.00,'2026-08-17 04:50:21'),(65,2,'bplo',9.00,'2026-08-17 05:19:23'),(65,2,'ledipo',3.00,'2026-08-17 05:19:23'),(65,2,'lgu_1',10.00,'2026-08-17 05:19:23'),(65,2,'lgu_2',1.00,'2026-08-17 05:19:23'),(65,2,'orcham',7.00,'2026-08-17 05:19:23'),(67,3,'bplo',10.00,'2026-08-17 04:50:21'),(67,3,'ledipo',1.00,'2026-08-17 04:50:21'),(67,3,'lgu_1',8.00,'2026-08-17 04:50:21'),(67,3,'lgu_2',5.00,'2026-08-17 04:50:21'),(67,3,'orcham',2.00,'2026-08-17 04:50:21'),(73,6,'bplo',6.00,'2026-08-17 05:18:06'),(73,6,'ledipo',5.00,'2026-08-17 05:18:06'),(73,6,'lgu_1',8.00,'2026-08-17 05:18:06'),(73,6,'lgu_2',2.00,'2026-08-17 05:18:06'),(73,6,'orcham',10.00,'2026-08-17 05:18:06'),(73,9,'bplo',10.00,'2026-08-17 05:01:05'),(73,9,'ledipo',10.00,'2026-08-17 05:01:05'),(73,9,'lgu_1',2.00,'2026-08-17 05:01:05'),(73,9,'lgu_2',9.00,'2026-08-17 05:01:05'),(73,9,'orcham',3.00,'2026-08-17 05:01:05'),(76,4,'bplo',6.00,'2026-08-17 05:19:11'),(76,4,'ledipo',9.00,'2026-08-17 05:19:11'),(76,4,'lgu_1',1.00,'2026-08-17 05:19:11'),(76,4,'lgu_2',10.00,'2026-08-17 05:19:11'),(76,4,'orcham',1.00,'2026-08-17 05:19:11'),(77,1,'bplo',8.10,'2026-08-17 04:02:05'),(77,1,'ledipo',6.30,'2026-08-17 04:02:05'),(77,1,'lgu_1',8.20,'2026-08-17 04:02:05'),(77,1,'lgu_2',10.00,'2026-08-17 04:02:05'),(77,1,'orcham',2.50,'2026-08-17 04:02:05'),(77,7,'bplo',1.00,'2026-08-17 05:19:01'),(77,7,'ledipo',9.00,'2026-08-17 05:19:01'),(77,7,'lgu_1',9.00,'2026-08-17 05:19:01'),(77,7,'lgu_2',5.00,'2026-08-17 05:19:01'),(77,7,'orcham',4.00,'2026-08-17 05:19:01'),(113,6,'bplo',9.00,'2026-08-17 05:18:06'),(113,6,'ledipo',6.00,'2026-08-17 05:18:06'),(113,6,'lgu_1',7.00,'2026-08-17 05:18:06'),(113,6,'lgu_2',8.00,'2026-08-17 05:18:06'),(113,6,'orcham',8.00,'2026-08-17 05:18:06'),(114,3,'bplo',10.00,'2026-08-17 04:50:21'),(114,3,'ledipo',4.00,'2026-08-17 04:50:21'),(114,3,'lgu_1',9.00,'2026-08-17 04:50:21'),(114,3,'lgu_2',5.00,'2026-08-17 04:50:21'),(114,3,'orcham',6.00,'2026-08-17 04:50:21'),(114,4,'bplo',1.00,'2026-08-17 05:19:12'),(114,4,'ledipo',5.00,'2026-08-17 05:19:12'),(114,4,'lgu_1',10.00,'2026-08-17 05:19:12'),(114,4,'lgu_2',10.00,'2026-08-17 05:19:12'),(114,4,'orcham',4.00,'2026-08-17 05:19:12'),(115,2,'bplo',8.00,'2026-08-17 05:19:23'),(115,2,'ledipo',8.00,'2026-08-17 05:19:23'),(115,2,'lgu_1',5.00,'2026-08-17 05:19:23'),(115,2,'lgu_2',9.00,'2026-08-17 05:19:23'),(115,2,'orcham',6.00,'2026-08-17 05:19:23'),(115,3,'bplo',4.00,'2026-08-17 04:50:21'),(115,3,'ledipo',9.00,'2026-08-17 04:50:21'),(115,3,'lgu_1',5.00,'2026-08-17 04:50:21'),(115,3,'lgu_2',9.00,'2026-08-17 04:50:21'),(115,3,'orcham',3.00,'2026-08-17 04:50:21'),(116,2,'bplo',5.00,'2026-08-17 05:19:23'),(116,2,'ledipo',2.00,'2026-08-17 05:19:23'),(116,2,'lgu_1',2.00,'2026-08-17 05:19:23'),(116,2,'lgu_2',5.00,'2026-08-17 05:19:23'),(116,2,'orcham',9.00,'2026-08-17 05:19:23'),(116,4,'bplo',7.00,'2026-08-17 05:19:11'),(116,4,'ledipo',10.00,'2026-08-17 05:19:11'),(116,4,'lgu_1',3.00,'2026-08-17 05:19:11'),(116,4,'lgu_2',9.00,'2026-08-17 05:19:11'),(116,4,'orcham',1.00,'2026-08-17 05:19:11'),(117,2,'bplo',10.00,'2026-08-17 05:19:23'),(117,2,'ledipo',6.00,'2026-08-17 05:19:23'),(117,2,'lgu_1',9.00,'2026-08-17 05:19:23'),(117,2,'lgu_2',9.00,'2026-08-17 05:19:23'),(117,2,'orcham',9.00,'2026-08-17 05:19:23'),(117,6,'bplo',7.00,'2026-08-17 05:18:06'),(117,6,'ledipo',7.00,'2026-08-17 05:18:06'),(117,6,'lgu_1',7.00,'2026-08-17 05:18:06'),(117,6,'lgu_2',6.00,'2026-08-17 05:18:06'),(117,6,'orcham',4.00,'2026-08-17 05:18:06'),(117,7,'bplo',9.00,'2026-08-17 05:19:01'),(117,7,'ledipo',8.00,'2026-08-17 05:19:01'),(117,7,'lgu_1',1.00,'2026-08-17 05:19:01'),(117,7,'lgu_2',4.00,'2026-08-17 05:19:01'),(117,7,'orcham',8.00,'2026-08-17 05:19:01'),(118,4,'bplo',10.00,'2026-08-17 05:19:12'),(118,4,'ledipo',10.00,'2026-08-17 05:19:12'),(118,4,'lgu_1',10.00,'2026-08-17 05:19:12'),(118,4,'lgu_2',8.00,'2026-08-17 05:19:12'),(118,4,'orcham',7.00,'2026-08-17 05:19:12'),(118,7,'bplo',9.00,'2026-08-17 05:19:01'),(118,7,'ledipo',3.00,'2026-08-17 05:19:01'),(118,7,'lgu_1',6.00,'2026-08-17 05:19:01'),(118,7,'lgu_2',10.00,'2026-08-17 05:19:01'),(118,7,'orcham',7.00,'2026-08-17 05:19:01'),(119,7,'bplo',7.00,'2026-08-17 05:19:01'),(119,7,'ledipo',8.00,'2026-08-17 05:19:01'),(119,7,'lgu_1',1.00,'2026-08-17 05:19:01'),(119,7,'lgu_2',7.00,'2026-08-17 05:19:01'),(119,7,'orcham',2.00,'2026-08-17 05:19:01'),(121,2,'bplo',9.00,'2026-08-17 05:19:23'),(121,2,'ledipo',2.00,'2026-08-17 05:19:23'),(121,2,'lgu_1',4.00,'2026-08-17 05:19:23'),(121,2,'lgu_2',3.00,'2026-08-17 05:19:23'),(121,2,'orcham',3.00,'2026-08-17 05:19:23'),(125,1,'bplo',10.00,'2026-08-17 04:02:04'),(125,1,'ledipo',6.30,'2026-08-17 04:02:04'),(125,1,'lgu_1',5.10,'2026-08-17 04:02:04'),(125,1,'lgu_2',9.40,'2026-08-17 04:02:04'),(125,1,'orcham',2.50,'2026-08-17 04:02:04'),(125,8,'bplo',4.00,'2026-08-17 05:17:45'),(125,8,'ledipo',7.00,'2026-08-17 05:17:45'),(125,8,'lgu_1',9.00,'2026-08-17 05:17:45'),(125,8,'lgu_2',1.00,'2026-08-17 05:17:45'),(125,8,'orcham',2.00,'2026-08-17 05:17:45'),(127,1,'bplo',8.50,'2026-08-17 04:02:04'),(127,1,'ledipo',3.10,'2026-08-17 04:02:04'),(127,1,'lgu_1',6.50,'2026-08-17 04:02:04'),(127,1,'lgu_2',4.50,'2026-08-17 04:02:04'),(127,1,'orcham',10.00,'2026-08-17 04:02:04'),(127,8,'bplo',10.00,'2026-08-17 05:17:45'),(127,8,'ledipo',10.00,'2026-08-17 05:17:45'),(127,8,'lgu_1',6.00,'2026-08-17 05:17:45'),(127,8,'lgu_2',8.00,'2026-08-17 05:17:45'),(127,8,'orcham',2.00,'2026-08-17 05:17:45'),(129,4,'bplo',3.00,'2026-08-17 05:19:11'),(129,4,'ledipo',5.00,'2026-08-17 05:19:11'),(129,4,'lgu_1',4.00,'2026-08-17 05:19:11'),(129,4,'lgu_2',5.00,'2026-08-17 05:19:11'),(129,4,'orcham',3.00,'2026-08-17 05:19:11'),(129,6,'bplo',3.00,'2026-08-17 05:18:06'),(129,6,'ledipo',10.00,'2026-08-17 05:18:06'),(129,6,'lgu_1',6.00,'2026-08-17 05:18:06'),(129,6,'lgu_2',2.00,'2026-08-17 05:18:06'),(129,6,'orcham',1.00,'2026-08-17 05:18:06'),(130,4,'bplo',8.00,'2026-08-17 05:19:12'),(130,4,'ledipo',10.00,'2026-08-17 05:19:12'),(130,4,'lgu_1',6.00,'2026-08-17 05:19:11'),(130,4,'lgu_2',1.00,'2026-08-17 05:19:12'),(130,4,'orcham',8.00,'2026-08-17 05:19:12'),(130,7,'bplo',2.00,'2026-08-17 05:19:00'),(130,7,'ledipo',8.00,'2026-08-17 05:19:00'),(130,7,'lgu_1',8.00,'2026-08-17 05:18:59'),(130,7,'lgu_2',8.00,'2026-08-17 05:19:00'),(130,7,'orcham',8.00,'2026-08-17 05:19:00'),(134,5,'bplo',10.00,'2026-08-17 05:18:20'),(134,5,'ledipo',8.00,'2026-08-17 05:18:20'),(134,5,'lgu_1',9.00,'2026-08-17 05:18:20'),(134,5,'lgu_2',7.00,'2026-08-17 05:18:20'),(134,5,'orcham',6.00,'2026-08-17 05:18:20'),(136,5,'bplo',8.00,'2026-08-17 05:18:20'),(136,5,'ledipo',6.00,'2026-08-17 05:18:20'),(136,5,'lgu_1',5.00,'2026-08-17 05:18:20'),(136,5,'lgu_2',3.00,'2026-08-17 05:18:20'),(136,5,'orcham',9.00,'2026-08-17 05:18:20'),(138,6,'bplo',2.00,'2026-08-17 05:18:05'),(138,6,'ledipo',4.00,'2026-08-17 05:18:05'),(138,6,'lgu_1',7.00,'2026-08-17 05:18:05'),(138,6,'lgu_2',1.00,'2026-08-17 05:18:05'),(138,6,'orcham',1.00,'2026-08-17 05:18:05'),(138,9,'bplo',3.00,'2026-08-17 05:01:05'),(138,9,'ledipo',4.00,'2026-08-17 05:01:05'),(138,9,'lgu_1',9.00,'2026-08-17 05:01:05'),(138,9,'lgu_2',6.00,'2026-08-17 05:01:05'),(138,9,'orcham',9.00,'2026-08-17 05:01:05'),(140,6,'bplo',10.00,'2026-08-17 05:18:06'),(140,6,'ledipo',10.00,'2026-08-17 05:18:06'),(140,6,'lgu_1',9.00,'2026-08-17 05:18:06'),(140,6,'lgu_2',6.00,'2026-08-17 05:18:06'),(140,6,'orcham',7.00,'2026-08-17 05:18:06'),(141,6,'bplo',1.00,'2026-08-17 05:18:06'),(141,6,'ledipo',5.00,'2026-08-17 05:18:06'),(141,6,'lgu_1',2.00,'2026-08-17 05:18:06'),(141,6,'lgu_2',5.00,'2026-08-17 05:18:06'),(141,6,'orcham',7.00,'2026-08-17 05:18:06'),(141,9,'bplo',7.00,'2026-08-17 05:01:05'),(141,9,'ledipo',3.00,'2026-08-17 05:01:05'),(141,9,'lgu_1',8.00,'2026-08-17 05:01:05'),(141,9,'lgu_2',6.00,'2026-08-17 05:01:05'),(141,9,'orcham',7.00,'2026-08-17 05:01:05'),(142,6,'bplo',3.00,'2026-08-17 05:18:06'),(142,6,'ledipo',8.00,'2026-08-17 05:18:06'),(142,6,'lgu_1',10.00,'2026-08-17 05:18:06'),(142,6,'lgu_2',6.00,'2026-08-17 05:18:06'),(142,6,'orcham',10.00,'2026-08-17 05:18:06'),(142,9,'bplo',3.00,'2026-08-17 05:01:05'),(142,9,'ledipo',8.00,'2026-08-17 05:01:05'),(142,9,'lgu_1',6.00,'2026-08-17 05:01:05'),(142,9,'lgu_2',10.00,'2026-08-17 05:01:05'),(142,9,'orcham',10.00,'2026-08-17 05:01:05'),(143,9,'bplo',5.00,'2026-08-17 05:01:05'),(143,9,'ledipo',5.00,'2026-08-17 05:01:05'),(143,9,'lgu_1',6.00,'2026-08-17 05:01:05'),(143,9,'lgu_2',9.00,'2026-08-17 05:01:05'),(143,9,'orcham',5.00,'2026-08-17 05:01:06'),(144,9,'bplo',5.00,'2026-08-17 05:01:05'),(144,9,'ledipo',1.00,'2026-08-17 05:01:05'),(144,9,'lgu_1',8.00,'2026-08-17 05:01:05'),(144,9,'lgu_2',9.00,'2026-08-17 05:01:05'),(144,9,'orcham',3.00,'2026-08-17 05:01:05'),(145,5,'bplo',8.00,'2026-08-17 05:18:20'),(145,5,'ledipo',2.00,'2026-08-17 05:18:20'),(145,5,'lgu_1',7.00,'2026-08-17 05:18:20'),(145,5,'lgu_2',5.00,'2026-08-17 05:18:20'),(145,5,'orcham',6.00,'2026-08-17 05:18:20');
/*!40000 ALTER TABLE `tbl_twg_member_scores` ENABLE KEYS */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-17 20:16:55
