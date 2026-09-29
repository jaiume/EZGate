-- Uptime seconds reported on each heartbeat
SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE boards
    ADD COLUMN IF NOT EXISTS last_uptime_seconds INT(10) UNSIGNED DEFAULT NULL AFTER last_heartbeat_at;
