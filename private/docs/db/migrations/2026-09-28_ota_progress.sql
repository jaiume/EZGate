-- OTA progress reported on each heartbeat while a firmware push is in flight
SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE boards
    ADD COLUMN IF NOT EXISTS ota_version VARCHAR(64) DEFAULT NULL AFTER reboot_requested;

ALTER TABLE boards
    ADD COLUMN IF NOT EXISTS ota_offset INT(10) UNSIGNED DEFAULT NULL AFTER ota_version;
