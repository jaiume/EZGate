-- Firmware catalog and per-board running/target versions
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS firmware_versions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    version VARCHAR(64) NOT NULL,
    notes TEXT DEFAULT NULL,
    checksum CHAR(64) NOT NULL,
    original_filename VARCHAR(191) NOT NULL,
    stored_filename VARCHAR(191) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE boards ADD COLUMN IF NOT EXISTS reported_firmware_version VARCHAR(64) DEFAULT NULL;
ALTER TABLE boards ADD COLUMN IF NOT EXISTS target_firmware_version_id INT UNSIGNED DEFAULT NULL;
CREATE INDEX IF NOT EXISTS idx_boards_target_firmware ON boards (target_firmware_version_id);
ALTER TABLE boards
    ADD CONSTRAINT fk_board_target_firmware
    FOREIGN KEY (target_firmware_version_id) REFERENCES firmware_versions (id) ON DELETE SET NULL;
