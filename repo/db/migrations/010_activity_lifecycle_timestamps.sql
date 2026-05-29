-- Phase 10: Add explicit lifecycle transition timestamps to the activities table.
-- These record the exact moment each status was first entered; they are set by
-- ActivityService::transition() and never overwritten once written.
SET NAMES utf8mb4;

ALTER TABLE `activities`
    ADD COLUMN `published_at`   DATETIME NULL DEFAULT NULL AFTER `updated_at`,
    ADD COLUMN `in_progress_at` DATETIME NULL DEFAULT NULL AFTER `published_at`,
    ADD COLUMN `completed_at`   DATETIME NULL DEFAULT NULL AFTER `in_progress_at`,
    ADD COLUMN `archived_at`    DATETIME NULL DEFAULT NULL AFTER `completed_at`;
