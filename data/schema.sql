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
-- Table structure for table `admin_sessions`
--

DROP TABLE "IF" EXISTS `admin_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_sessions` (
    `session_id` INT unsigned NOT NULL AUTO_INCREMENT,
    `token` CHAR(64) COLLATE "utf8mb4_unicode_ci" NOT NULL COMMENT 'SHA-256 hash of random token',
    `ip_address` VARCHAR(45) COLLATE "utf8mb4_unicode_ci" NOT NULL COMMENT 'IPv4 or IPv6 address',
    `user_agent` VARCHAR(255) COLLATE "utf8mb4_unicode_ci" DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NOT NULL,
    PRIMARY KEY (`session_id`),
    UNIQUE KEY `token` (`token`),
    "KEY" `idx_token` (`token`),
    "KEY" `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cards`
--

DROP TABLE "IF" EXISTS `cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cards` (
    `card_id` INT unsigned NOT NULL AUTO_INCREMENT,
    `type` enum('prompt','response') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
    `copy` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL COMMENT 'Card content - supports markdown and base64 encoded images',
    `special` VARCHAR(255) CHARACTER SET "utf8mb4" COLLATE "utf8mb4_unicode_520_ci" DEFAULT NULL COMMENT 'Additional info like "Pick 2" or "Pick 2, Choose 3"',
    `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci COMMENT 'Additional notes on the card',
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `active` tinyint(1) NOT NULL DEFAULT '1',
    PRIMARY KEY (`card_id`),
    "KEY" `idx_type` (`type`)
) ENGINE=InnoDB AUTO_INCREMENT=301 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cards_to_packs`
--

DROP TABLE "IF" EXISTS `cards_to_packs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cards_to_packs` (
    `card_id` INT unsigned NOT NULL,
    `pack_id` INT unsigned NOT NULL,
    PRIMARY KEY (`card_id`, `pack_id`),
    "KEY" `idx_card_id` (`card_id`),
    "KEY" `idx_pack_id` (`pack_id`),
    CONSTRAINT `cards_to_packs_ibfk_1` FOREIGN KEY (`card_id`) REFERENCES `cards`(`card_id`) ON DELETE CASCADE,
    CONSTRAINT `cards_to_packs_ibfk_2` FOREIGN KEY (`pack_id`) REFERENCES `packs`(`pack_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cards_to_tags`
--

DROP TABLE "IF" EXISTS `cards_to_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cards_to_tags` (
    `card_id` INT unsigned NOT NULL,
    `tag_id` INT unsigned NOT NULL,
    PRIMARY KEY (`card_id`, `tag_id`),
    "KEY" `idx_card_id` (`card_id`),
    "KEY" `idx_tag_id` (`tag_id`),
    CONSTRAINT `cards_to_tags_ibfk_1` FOREIGN KEY (`card_id`) REFERENCES `cards`(`card_id`) ON DELETE CASCADE,
    CONSTRAINT `cards_to_tags_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `tags`(`tag_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `games`
--

DROP TABLE "IF" EXISTS `games`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `games` (
    `game_id` CHAR(4) COLLATE "utf8mb4_unicode_ci" NOT NULL COMMENT 'Unique 4-character game code',
    `tags` json NOT NULL COMMENT 'Array of tag IDs used in this game',
    `draw_pile` json NOT NULL COMMENT 'Array of available card IDs',
    `discard_pile` json NOT NULL COMMENT 'Array of used card IDs',
    `player_data` json NOT NULL COMMENT 'Current game state including players, scores, hands, submissions (round_history moved to separate column)',
    `round_history` json DEFAULT NULL COMMENT 'Historical round data (loaded separately for performance)',
    `state` VARCHAR(20) COLLATE "utf8mb4_unicode_ci" GENERATED ALWAYS AS ("json_unquote"("json_extract"(`player_data`, "_utf8mb4" '$.state'))) STORED COMMENT 'Computed column for efficient state queries',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Game creation timestamp',
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`game_id`),
    "KEY" `idx_created_at` (`created_at`),
    "KEY" `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `packs`
--

DROP TABLE "IF" EXISTS `packs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `packs` (
    `pack_id` INT unsigned NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) CHARACTER SET "utf8mb4" COLLATE "utf8mb4_unicode_520_ci" NOT NULL,
    `version` VARCHAR(255) CHARACTER SET "utf8mb4" COLLATE "utf8mb4_unicode_520_ci" DEFAULT NULL,
    `data` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci COMMENT 'JSON data for additional metadata (release location, etc.)',
    `release_date` datetime DEFAULT NULL,
    `active` tinyint(1) NOT NULL DEFAULT '1',
    PRIMARY KEY (`pack_id`),
    UNIQUE KEY `unique_pack_version` (`name`,`version`),
    KEY `idx_active` (`active`)
) ENGINE=InnoDB AUTO_INCREMENT=612 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rate_limits`
--

DROP TABLE "IF" EXISTS `rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rate_limits` (
    `id` INT unsigned NOT NULL AUTO_INCREMENT,
    `ip_address` VARCHAR(45) COLLATE "utf8mb4_unicode_ci" NOT NULL COMMENT 'IPv4 or IPv6 address',
    `action` VARCHAR(50) COLLATE "utf8mb4_unicode_ci" NOT NULL COMMENT 'Action being rate limited (e.g., join_game, create_game)',
    `attempts` INT unsigned NOT NULL DEFAULT '1',
    `first_attempt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_attempt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `locked_until` datetime DEFAULT NULL COMMENT 'Lock expiration time',
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_ip_action` (`ip_address`,`action`),
    "KEY" `idx_locked_until` (`locked_until`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tags`
--

DROP TABLE "IF" EXISTS `tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tags` (
    `tag_id` INT unsigned NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) CHARACTER SET "utf8mb4" COLLATE "utf8mb4_unicode_520_ci" NOT NULL,
    `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
    `active` tinyint(1) NOT NULL DEFAULT '1',
    PRIMARY KEY (`tag_id`),
    UNIQUE KEY `name` (`name`),
    "KEY" `idx_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-01-28  0:33:03
