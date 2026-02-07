-- Cards Against Humanity / Cards API Hub Database Schema
-- MySQL/MariaDB

-- Drop tables (disable FK checks so order doesn't matter)
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `cards_to_packs`;
DROP TABLE IF EXISTS `cards_to_tags`;
DROP TABLE IF EXISTS `cards`;
DROP TABLE IF EXISTS `packs`;
DROP TABLE IF EXISTS `tags`;
DROP TABLE IF EXISTS `games`;
DROP TABLE IF EXISTS `rate_limits`;
DROP TABLE IF EXISTS `admin_sessions`;
SET FOREIGN_KEY_CHECKS = 1;

-- Cards table: stores all response and prompt cards
CREATE TABLE IF NOT EXISTS `cards` (
    `card_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('prompt', 'response') NOT NULL,
    `copy` TEXT NOT NULL COMMENT 'Card content - supports markdown', -- and soon will support base64 encoded images
    `special` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Additional info like "Pick 2" or "Pick 2, Choose 3"',
    `notes` TEXT NULL DEFAULT NULL COMMENT 'Additional information on the card',
    `metadata` TEXT NULL DEFAULT NULL COMMENT 'Additional JSON metadata on the card',
    `choices` SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Number of response cards needed (prompt cards only)',
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    INDEX `idx_type` (`type`),
    INDEX `idx_active` (`active`),
    INDEX `idx_active_type` (`active`, `type`) COMMENT 'Optimized for filtering active cards by type when building draw pile'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Packs table: card expansion packs
CREATE TABLE IF NOT EXISTS `packs` (
    `pack_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `version` VARCHAR(255) DEFAULT NULL,
    `data` TEXT NULL DEFAULT NULL COMMENT 'JSON data for additional metadata (release location, etc.)',
    `release_date` DATE DEFAULT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY `unique_pack_version` (`name`, `version`),
    INDEX `idx_release_date` (`release_date`),
    INDEX `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Tags table: categorize cards. type: rating (Basic/Mild/Moderate/Severe), advisory (content warnings), source (Clams/franchise), location (UK, CA, Bay Area, etc.), other
CREATE TABLE IF NOT EXISTS `tags` (
    `tag_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `description` VARCHAR(255) NULL,
    `type` VARCHAR(50) NULL DEFAULT 'other' COMMENT 'rating, advisory, source, location, or other',
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    INDEX `idx_name` (`name`),
    INDEX `idx_type` (`type`),
    INDEX `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Card to Pack relationship table (many-to-many)
CREATE TABLE IF NOT EXISTS `cards_to_packs` (
    `card_id` INT UNSIGNED NOT NULL,
    `pack_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`card_id`, `pack_id`),
    FOREIGN KEY (`card_id`) REFERENCES `cards`(`card_id`) ON DELETE CASCADE,
    FOREIGN KEY (`pack_id`) REFERENCES `packs`(`pack_id`) ON DELETE CASCADE,
    INDEX `idx_card_id` (`card_id`),
    INDEX `idx_pack_id` (`pack_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Card to Tags relationship table (many-to-many)
CREATE TABLE IF NOT EXISTS `cards_to_tags` (
    `card_id` INT UNSIGNED NOT NULL,
    `tag_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`card_id`, `tag_id`),
    FOREIGN KEY (`card_id`) REFERENCES `cards`(`card_id`) ON DELETE CASCADE,
    FOREIGN KEY (`tag_id`) REFERENCES `tags`(`tag_id`) ON DELETE CASCADE,
    INDEX `idx_card_id` (`card_id`),
    INDEX `idx_tag_id` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Games table: stores active game sessions
CREATE TABLE IF NOT EXISTS `games` (
    `game_id` CHAR(4) PRIMARY KEY COMMENT 'Unique 4-character game code',
    `tags` JSON NOT NULL COMMENT 'Array of tag IDs used in this game',
    `draw_pile` JSON NOT NULL COMMENT 'Array of available card IDs',
    `discard_pile` JSON NOT NULL COMMENT 'Array of used card IDs',
    `player_data` JSON NOT NULL COMMENT 'Current game state including players, scores, hands, submissions',
    `round_history` JSON NULL COMMENT 'Historical round data (loaded separately for performance)',
    `state` VARCHAR(50) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(player_data, '$.state'))) STORED COMMENT 'Computed column for efficient state queries',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Game creation timestamp',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Rate limits table: prevent brute force attacks
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL COMMENT 'IPv4 or IPv6 address',
    `action` VARCHAR(50) NOT NULL COMMENT 'Action being rate limited (e.g., join_game, create_game)',
    `attempts` INT UNSIGNED NOT NULL DEFAULT 1,
    `first_attempt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_attempt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `locked_until` DATETIME NULL DEFAULT NULL COMMENT 'Lock expiration time',
    UNIQUE KEY `idx_ip_action` (`ip_address`, `action`),
    INDEX `idx_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Admin sessions table: store admin authentication tokens
CREATE TABLE IF NOT EXISTS `admin_sessions` (
    `session_id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `token` CHAR(64) NOT NULL UNIQUE COMMENT 'SHA-256 hash of random token',
    `ip_address` VARCHAR(45) NOT NULL COMMENT 'IPv4 or IPv6 address',
    `user_agent` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NOT NULL,
    INDEX `idx_token` (`token`),
    INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Sample player_data JSON structure (for reference, not actual data):
-- {
--   "settings": { "rando_enabled": false, "unlimited_renew": false, "max_score": 10, "hand_size": 10 },
--   "state": "waiting|playing|finished",
--   "players": [ { "id": "uuid-v4", "name": "Player Name", "score": 0, "hand": [1,2,3,...], "is_rando": false } ],
--   "current_czar_id": "uuid-v4", "current_prompt_card": 42, "current_round": 1,
--   "submissions": [ { "player_id": "uuid-v4", "cards": [1, 5] } ],
--   "round_history": [ { "round": 1, "prompt_card": 40, "winner_id": "uuid-v4", "submissions": [...] } ]
-- }
