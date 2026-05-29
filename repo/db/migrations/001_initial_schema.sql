SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `users` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`        VARCHAR(64)     NOT NULL,
  `password_hash`   VARCHAR(255)    NOT NULL,
  `role`            ENUM('admin','ops_staff','team_lead','reviewer','regular') NOT NULL DEFAULT 'regular',
  `failed_attempts` TINYINT         NOT NULL DEFAULT 0,
  `locked_until`    DATETIME        NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `action`      VARCHAR(100)    NOT NULL,
  `entity_type` VARCHAR(100)    NOT NULL,
  `entity_id`   BIGINT          NOT NULL DEFAULT 0,
  `payload`     JSON            NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_action` (`user_id`, `action`),
  KEY `idx_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_tags` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `tag`         VARCHAR(100)    NOT NULL,
  `assigned_by` BIGINT UNSIGNED NOT NULL,
  `assigned_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_tag` (`user_id`, `tag`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed accounts: placeholder hashes only — real passwords set by `php think db:seed`.
-- Run `docker compose exec backend php think db:seed` after migrations to activate credentials.
INSERT IGNORE INTO `users` (`username`, `password_hash`, `role`) VALUES
  ('admin',     '$2y$12$placeholder.hash.admin.AAAAAAAAAAAAAAAAAAAAAA', 'admin'),
  ('ops_user',  '$2y$12$placeholder.hash.ops_u.AAAAAAAAAAAAAAAAAAAAAA', 'ops_staff'),
  ('team_lead', '$2y$12$placeholder.hash.lead_.AAAAAAAAAAAAAAAAAAAAAA', 'team_lead'),
  ('reviewer',  '$2y$12$placeholder.hash.revie.AAAAAAAAAAAAAAAAAAAAAA', 'reviewer'),
  ('user1',     '$2y$12$placeholder.hash.user1.AAAAAAAAAAAAAAAAAAAAAA', 'regular'),
  ('user2',     '$2y$12$placeholder.hash.user2.AAAAAAAAAAAAAAAAAAAAAA', 'regular');

SET FOREIGN_KEY_CHECKS = 1;
