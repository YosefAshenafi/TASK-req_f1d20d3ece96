-- Phase 14: Formalize invoice correction scope + add appeal re-review history.
--
-- 1. invoice_corrections.scope — records the correctable field scope explicitly.
--    Only invoice_address is accepted for closed-order corrections going forward.
--    Historical rows are back-filled; no historical data is deleted.
--
-- 2. violation_appeal_reviews — stores each reviewer decision for a given appeal,
--    enabling a full re-review history. The initial review remains on
--    violation_appeals; subsequent decisions accumulate in this table.

SET NAMES utf8mb4;

-- 1. Add scope column to invoice_corrections (invoice_address only going forward)
ALTER TABLE `invoice_corrections`
  ADD COLUMN `scope` VARCHAR(50) NOT NULL DEFAULT 'invoice_address'
  COMMENT 'Correction field scope; only invoice_address is accepted for closed orders';

-- Back-fill historical rows to invoice_address scope (no data removed)
UPDATE `invoice_corrections` SET `scope` = 'invoice_address';

-- 2. Re-review history for violation appeals
CREATE TABLE IF NOT EXISTS `violation_appeal_reviews` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `appeal_id`      BIGINT UNSIGNED NOT NULL,
  `reviewer_id`    BIGINT UNSIGNED NOT NULL,
  `decision`       ENUM('approved','rejected') NOT NULL,
  `decision_notes` TEXT NOT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_appeal_reviews` (`appeal_id`),
  CONSTRAINT `fk_appeal_review_appeal`    FOREIGN KEY (`appeal_id`)   REFERENCES `violation_appeals` (`id`),
  CONSTRAINT `fk_appeal_review_reviewer`  FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
