-- Fallback firmware slot reported by each board
SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE boards ADD COLUMN IF NOT EXISTS reported_fallback_firmware_version VARCHAR(64) DEFAULT NULL;
