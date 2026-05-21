SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `behavior_events` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `entity_type`  ENUM('activity') NOT NULL DEFAULT 'activity',
  `entity_id`    BIGINT UNSIGNED NOT NULL,
  `event_type`   ENUM('view','save','signup') NOT NULL,
  `occurred_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_entity` (`user_id`, `entity_type`, `entity_id`),
  KEY `idx_occurred_at` (`occurred_at`),
  CONSTRAINT `fk_behavior_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recommendation_cache` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`           BIGINT UNSIGNED NOT NULL,
  `context`           ENUM('list','detail') NOT NULL DEFAULT 'list',
  `context_entity_id` BIGINT UNSIGNED NULL,
  `items`             JSON NOT NULL,
  `computed_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_context` (`user_id`, `context`, `context_entity_id`),
  CONSTRAINT `fk_rec_cache_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tag_popularity` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tag`          VARCHAR(100)    NOT NULL,
  `period_start` DATE            NOT NULL,
  `signup_count` INT             NOT NULL DEFAULT 0,
  `view_count`   INT             NOT NULL DEFAULT 0,
  `score`        FLOAT           NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tag_period` (`tag`, `period_start`),
  KEY `idx_score` (`score` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
