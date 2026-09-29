-- Site and board pulse duration: NULL means inherit (site from app default, board from site).
SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE sites
    ADD COLUMN IF NOT EXISTS pulse_duration_ms INT(10) UNSIGNED DEFAULT NULL AFTER name;

ALTER TABLE boards
    MODIFY pulse_duration_ms INT(10) UNSIGNED DEFAULT NULL;

UPDATE boards
    SET pulse_duration_ms = NULL
    WHERE pulse_duration_ms = 500;
