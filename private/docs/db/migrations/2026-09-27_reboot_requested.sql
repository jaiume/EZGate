-- One-shot reboot requested by the portal
SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE boards
    ADD COLUMN IF NOT EXISTS reboot_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER scan_requested;
