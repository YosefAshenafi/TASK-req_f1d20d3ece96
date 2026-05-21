SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `activity_tasks` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id`    BIGINT UNSIGNED NOT NULL,
  `title`          VARCHAR(255)    NOT NULL,
  `description`    TEXT            NULL,
  `staffing_count` INT             NOT NULL DEFAULT 0,
  `status`         ENUM('pending','in_progress','done') NOT NULL DEFAULT 'pending',
  `checklist`      JSON            NULL,
  `assigned_to`    BIGINT UNSIGNED NULL,
  `created_by`     BIGINT UNSIGNED NOT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_task` (`activity_id`),
  CONSTRAINT `fk_tasks_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tasks_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `violation_rules` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(255)    NOT NULL,
  `description` TEXT            NULL,
  `point_value` INT             NOT NULL,
  `is_active`   TINYINT(1)     NOT NULL DEFAULT 1,
  `created_by`  BIGINT UNSIGNED NOT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_rules_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `violations` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_id`         BIGINT UNSIGNED NOT NULL,
  `subject_user_id` BIGINT UNSIGNED NOT NULL,
  `group_id`        INT             NULL,
  `points_applied`  INT             NOT NULL,
  `status`          ENUM('active','appealed','reversed','upheld') NOT NULL DEFAULT 'active',
  `notes`           TEXT            NULL,
  `created_by`      BIGINT UNSIGNED NOT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subject` (`subject_user_id`),
  CONSTRAINT `fk_violations_rule` FOREIGN KEY (`rule_id`) REFERENCES `violation_rules` (`id`),
  CONSTRAINT `fk_violations_subject` FOREIGN KEY (`subject_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_violations_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `violation_evidence` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `violation_id`     BIGINT UNSIGNED NOT NULL,
  `file_path`        VARCHAR(512)    NOT NULL,
  `file_type`        ENUM('jpg','png','pdf') NOT NULL,
  `file_size_bytes`  INT             NOT NULL,
  `sha256_hash`      CHAR(64)        NOT NULL,
  `uploaded_by`      BIGINT UNSIGNED NOT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_violation_evidence` (`violation_id`),
  CONSTRAINT `fk_evidence_violation` FOREIGN KEY (`violation_id`) REFERENCES `violations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_evidence_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `violation_appeals` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `violation_id`   BIGINT UNSIGNED NOT NULL,
  `appellant_id`   BIGINT UNSIGNED NOT NULL,
  `reason`         TEXT            NOT NULL,
  `status`         ENUM('pending','reviewed') NOT NULL DEFAULT 'pending',
  `reviewer_id`    BIGINT UNSIGNED NULL,
  `decision_notes` TEXT            NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at`    DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_violation_appeal` (`violation_id`),
  CONSTRAINT `fk_appeals_violation` FOREIGN KEY (`violation_id`) REFERENCES `violations` (`id`),
  CONSTRAINT `fk_appeals_appellant` FOREIGN KEY (`appellant_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_point_cache` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`             BIGINT UNSIGNED NOT NULL,
  `total_points`        INT             NOT NULL DEFAULT 0,
  `last_alert_threshold` INT            NOT NULL DEFAULT 0,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_points` (`user_id`),
  CONSTRAINT `fk_upc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `group_point_cache` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`            INT             NOT NULL,
  `total_points`        INT             NOT NULL DEFAULT 0,
  `last_alert_threshold` INT            NOT NULL DEFAULT 0,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_group_points` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient_id` BIGINT UNSIGNED NOT NULL,
  `type`         VARCHAR(100)    NOT NULL,
  `message`      TEXT            NOT NULL,
  `entity_type`  VARCHAR(100)    NULL,
  `entity_id`    BIGINT          NULL,
  `is_read`      TINYINT(1)     NOT NULL DEFAULT 0,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recipient` (`recipient_id`, `is_read`),
  CONSTRAINT `fk_notif_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
