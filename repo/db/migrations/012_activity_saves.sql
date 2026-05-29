SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `activity_saves` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `activity_id` BIGINT UNSIGNED NOT NULL,
  `saved_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_save` (`user_id`, `activity_id`),
  KEY `idx_activity_saves` (`activity_id`),
  CONSTRAINT `fk_saves_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`      (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_saves_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
