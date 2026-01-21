-- MariaDB dump 10.19-11.4.0-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: cah_game
-- ------------------------------------------------------
-- Server version	8.4.3

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
-- Table structure for table `tags`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `tags` (
  `tag_id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`tag_id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tags`
--

LOCK TABLES `tags` WRITE;
/*!40000 ALTER TABLE `tags` DISABLE KEYS */;
INSERT INTO `tags` VALUES
(1,'Mild','likely acceptable for teens/PG‑13',1),
(2,'Moderate','edgy but not extreme',1),
(3,'Severe','highly graphic, deeply offensive, or likely to upset',1),

(4,'Sexual Innuendo','flirty jokes, mild suggestiveness, double entendres',1),
(5,'Sexually Explicit','graphic sexual acts, explicit body parts, porn references',1),

(7,'Mild Profanity','\"damn\", \"hell\", mild slang',1),
(8,'Strong Profanity','\"f**k\", \"s**t\", \"c**t\", etc.',1),

(9,'Slurs / Hate Speech','direct slurs (racial, homophobic, etc.)',1),

(10,'Racism / Ethnicity','racist stereotypes, slurs, race-based jokes',1),

(11,'Sexism / Misogyny','gender-based insults or stereotypes',1),

( 6, 'Sexual Orientation / Gender Identity', 'content mentioning LGBTQ+ topics', 1),
(12,'Homophobia / Transphobia','anti-LGBTQ+ jokes or slurs',1),

(13,'Religion / Blasphemy','mocking religions, religious figures, sacred concepts',1),

(14,'Body Shaming / Appearance','mocking fatness, disability, appearance',1),

(15,'Mild Violence','non-graphic harm, slapstick, \"punching\", etc.',1),
(16,'Graphic Violence / Gore','blood, dismemberment, torture',1),

(17,'Self‑Harm / Suicide','references to suicide, cutting, etc.',1),

(18,'Abuse / Domestic Violence','abusive relationships, child abuse, etc.',1),

(19,'Drugs','recreational drugs, drug abuse',1),
(20,'Alcohol','heavy drinking, alcoholism, getting drunk',1),
(21,'Tobacco / Vaping','smoking, vaping',1),

(22,'Crime / Illegal Activity','theft, murder, terrorism, etc.',1),

(23,'Toilet Humor','poop, pee, fart jokes',1),
(24,'Body Fluids / Gross‑out','vomit, mucus, pus, etc.',1),

(25,'Medical / Sensitive Health','serious disease, terminal illness',1),

(26,'Dark Humor','jokes about death, tragedy, misfortune',1),

(27,'Trauma / Sensitive','references to rape, child abuse, genocide, etc.',1),

(28,'People','just... people',1);
/*!40000 ALTER TABLE `tags` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-01-22 13:55:59
