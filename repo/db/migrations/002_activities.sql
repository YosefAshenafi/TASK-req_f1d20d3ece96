SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `activities` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`               VARCHAR(512)    NOT NULL,
  `body`                TEXT            NOT NULL,
  `author_id`           BIGINT UNSIGNED NOT NULL,
  `status`              ENUM('draft','published','in_progress','completed','archived') NOT NULL DEFAULT 'draft',
  `signup_open_at`      DATETIME        NULL,
  `signup_close_at`     DATETIME        NULL,
  `max_headcount`       INT             NULL,
  `required_supplies`   JSON            NULL,
  `current_version_id`  BIGINT UNSIGNED NULL,
  `reply_count`         INT             NOT NULL DEFAULT 0,
  `view_count`          INT             NOT NULL DEFAULT 0,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_author` (`author_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_activities_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_versions` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id`    BIGINT UNSIGNED NOT NULL,
  `version_number` INT             NOT NULL DEFAULT 1,
  `snapshot`       JSON            NOT NULL,
  `diff`           JSON            NULL,
  `changed_by`     BIGINT UNSIGNED NOT NULL,
  `action`         VARCHAR(50)     NOT NULL DEFAULT 'edit',
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_version` (`activity_id`, `version_number`),
  CONSTRAINT `fk_versions_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_versions_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_tags` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id` BIGINT UNSIGNED NOT NULL,
  `tag`         VARCHAR(100)    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_activity_tag` (`activity_id`, `tag`),
  CONSTRAINT `fk_actags_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_signups` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id`  BIGINT UNSIGNED NOT NULL,
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `status`       ENUM('active','canceled') NOT NULL DEFAULT 'active',
  `signed_up_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_signup` (`activity_id`, `user_id`),
  KEY `idx_user_signups` (`user_id`),
  CONSTRAINT `fk_signups_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`),
  CONSTRAINT `fk_signups_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add deferred self-reference FK
ALTER TABLE `activities`
  ADD CONSTRAINT `fk_activities_version`
  FOREIGN KEY (`current_version_id`) REFERENCES `activity_versions` (`id`)
  ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;
