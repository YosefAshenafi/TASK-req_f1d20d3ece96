SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `orders` (
  `id`                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id`              BIGINT UNSIGNED NULL,
  `created_by`               BIGINT UNSIGNED NOT NULL,
  `type`                     VARCHAR(100)    NOT NULL,
  `description`              TEXT            NULL,
  `status`                   ENUM('placed','pending_payment','paid','ticketing','ticketed','canceled','closed') NOT NULL DEFAULT 'placed',
  `payment_reference`        VARCHAR(255)    NULL,
  `placed_at`                DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `pending_payment_at`       DATETIME        NULL,
  `paid_at`                  DATETIME        NULL,
  `ticketed_at`              DATETIME        NULL,
  `canceled_at`              DATETIME        NULL,
  `closed_at`                DATETIME        NULL,
  `invoice_address`          TEXT            NULL,
  `invoice_contact`          VARCHAR(255)    NULL,
  `invoice_contact_encrypted` VARCHAR(512)   NULL,
  `created_at`               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_pending_payment` (`status`, `pending_payment_at`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_orders_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_refunds` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     BIGINT UNSIGNED NOT NULL,
  `refunded_by`  BIGINT UNSIGNED NOT NULL,
  `reason`       TEXT            NULL,
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  CONSTRAINT `fk_refunds_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  CONSTRAINT `fk_refunds_user` FOREIGN KEY (`refunded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoice_corrections` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`       BIGINT UNSIGNED NOT NULL,
  `requested_by`   BIGINT UNSIGNED NOT NULL,
  `reviewed_by`    BIGINT UNSIGNED NULL,
  `field_patch`    JSON            NOT NULL,
  `status`         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `decision_notes` TEXT            NULL,
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_correction` (`order_id`),
  CONSTRAINT `fk_corrections_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shipments` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     BIGINT UNSIGNED NOT NULL,
  `created_by`   BIGINT UNSIGNED NOT NULL,
  `status`       ENUM('created','in_transit','delivered','exception') NOT NULL DEFAULT 'created',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_shipment` (`order_id`),
  CONSTRAINT `fk_shipments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shipment_packages` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shipment_id`    BIGINT UNSIGNED NOT NULL,
  `package_ref`    VARCHAR(100)    NOT NULL,
  `carrier_name`   VARCHAR(255)    NULL,
  `tracking_number` VARCHAR(255)   NULL,
  `status`         ENUM('created','in_transit','delivered','exception') NOT NULL DEFAULT 'created',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shipment` (`shipment_id`),
  CONSTRAINT `fk_packages_shipment` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shipment_events` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shipment_id`  BIGINT UNSIGNED NOT NULL,
  `event_type`   ENUM('dispatched','in_transit','delivered','exception') NOT NULL,
  `location`     VARCHAR(255)    NULL,
  `note`         TEXT            NULL,
  `entered_by`   BIGINT UNSIGNED NOT NULL,
  `occurred_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shipment_event` (`shipment_id`),
  CONSTRAINT `fk_events_shipment` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_events_user` FOREIGN KEY (`entered_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_subscriptions` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          BIGINT UNSIGNED NOT NULL,
  `notify_arrival`   TINYINT(1) NOT NULL DEFAULT 1,
  `notify_exception` TINYINT(1) NOT NULL DEFAULT 1,
  `notify_delay`     TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_subscription` (`user_id`),
  CONSTRAINT `fk_subscriptions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
