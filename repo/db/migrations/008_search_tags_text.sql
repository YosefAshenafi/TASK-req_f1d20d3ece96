-- Add tags_text column to search_index for full-text tag matching
SET NAMES utf8mb4;

ALTER TABLE `search_index`
    ADD COLUMN `tags_text` TEXT NULL
        COMMENT 'Space-separated tag strings — included in FULLTEXT index';

-- Rebuild FULLTEXT index to include tags_text
-- (DROP + ADD required because MySQL cannot ALTER a FULLTEXT key in place)
ALTER TABLE `search_index` DROP KEY `ft_search`;
ALTER TABLE `search_index`
    ADD FULLTEXT KEY `ft_search` (`title`, `body`, `author_name`, `tags_text`);
