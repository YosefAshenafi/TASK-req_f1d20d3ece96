SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `search_index` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type`  ENUM('activity','order','user') NOT NULL,
  `entity_id`    BIGINT UNSIGNED NOT NULL,
  `title`        VARCHAR(512)    NOT NULL DEFAULT '',
  `body`         TEXT           ,
  `author_name`  VARCHAR(255)    NOT NULL DEFAULT '',
  `tags`         JSON           ,
  `view_count`   INT            NOT NULL DEFAULT 0,
  `save_count`   INT            NOT NULL DEFAULT 0,
  `signup_count` INT            NOT NULL DEFAULT 0,
  `reply_count`  INT            NOT NULL DEFAULT 0,
  `indexed_at`   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_entity` (`entity_type`, `entity_id`),
  KEY `idx_entity_type` (`entity_type`),
  FULLTEXT KEY `ft_search` (`title`, `body`, `author_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `logistics_index` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type`   ENUM('order','shipment') NOT NULL,
  `entity_id`     BIGINT UNSIGNED NOT NULL,
  `display_name`  VARCHAR(512)    NOT NULL,
  `pinyin_name`   VARCHAR(512)    NULL,
  `synonyms`      JSON            NULL,
  `indexed_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_logistics` (`entity_type`, `entity_id`),
  FULLTEXT KEY `ft_logistics` (`display_name`, `pinyin_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `index_orphan_candidates` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type`  VARCHAR(50)     NOT NULL,
  `entity_id`    BIGINT UNSIGNED NOT NULL,
  `deleted_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `synonym_map` (
  `id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `term`     VARCHAR(255)    NOT NULL,
  `synonyms` JSON            NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_term` (`term`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed some common synonyms
INSERT IGNORE INTO `synonym_map` (`term`, `synonyms`) VALUES
  ('equipment', '["gear","device","apparatus","machinery"]'),
  ('event',     '["activity","meeting","gathering","seminar"]'),
  ('staff',     '["personnel","team","crew","workers"]'),
  ('rental',    '["hire","lease","borrow"]'),
  ('print',     '["printing","printed","materials"]');

SET FOREIGN_KEY_CHECKS = 1;
