SET NAMES utf8mb4;

ALTER TABLE `logistics_index`
    ADD COLUMN `view_count`  INT NOT NULL DEFAULT 0 AFTER `pinyin_name`,
    ADD COLUMN `reply_count` INT NOT NULL DEFAULT 0 AFTER `view_count`;
