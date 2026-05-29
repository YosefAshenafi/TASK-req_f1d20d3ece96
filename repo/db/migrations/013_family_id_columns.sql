SET NAMES utf8mb4;

-- Stable family identifier for activity dedup in recommendation feed
ALTER TABLE `activities`
    ADD COLUMN `family_id` VARCHAR(100) NULL DEFAULT NULL AFTER `view_count`;

-- Backfill existing activities: default family = 'activity:<id>'
UPDATE `activities` SET `family_id` = CONCAT('activity:', `id`) WHERE `family_id` IS NULL;

-- Stable family identifier for order dedup
ALTER TABLE `orders`
    ADD COLUMN `family_id` VARCHAR(100) NULL DEFAULT NULL AFTER `updated_at`;

-- Backfill existing orders
UPDATE `orders` SET `family_id` = CONCAT('order:', `id`) WHERE `family_id` IS NULL;

-- Denormalise family_id into search/logistics indexes so engine reads without JOIN
ALTER TABLE `search_index`
    ADD COLUMN `family_id` VARCHAR(100) NULL DEFAULT NULL AFTER `reply_count`;

ALTER TABLE `logistics_index`
    ADD COLUMN `family_id` VARCHAR(100) NULL DEFAULT NULL AFTER `reply_count`;
