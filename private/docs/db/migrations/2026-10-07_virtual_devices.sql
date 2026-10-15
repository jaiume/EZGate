-- Board types, virtual devices, accesstag lists, and reads still owed to EntryZen.

CREATE TABLE IF NOT EXISTS board_types (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(191) NOT NULL,
    relay_count INT UNSIGNED NOT NULL,
    wiegand_count INT UNSIGNED NOT NULL DEFAULT 0,
    has_lora TINYINT(1) NOT NULL DEFAULT 0,
    has_wifi TINYINT(1) NOT NULL DEFAULT 0,
    has_ethernet TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO board_types (name, relay_count, wiegand_count, has_lora, has_wifi, has_ethernet)
SELECT 'Heltec Wireless Stick Lite V3', 1, 0, 1, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM board_types WHERE name = 'Heltec Wireless Stick Lite V3');

INSERT INTO board_types (name, relay_count, wiegand_count, has_lora, has_wifi, has_ethernet)
SELECT 'Kincony KC868-A8', 8, 2, 0, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM board_types WHERE name = 'Kincony KC868-A8');

ALTER TABLE boards
    ADD COLUMN IF NOT EXISTS board_type_id INT UNSIGNED DEFAULT NULL AFTER hardware_id;

UPDATE boards b
JOIN board_types t ON t.name = 'Heltec Wireless Stick Lite V3'
SET b.board_type_id = t.id
WHERE b.board_type_id IS NULL;

ALTER TABLE boards
    MODIFY board_type_id INT UNSIGNED NOT NULL;

ALTER TABLE boards
    ADD CONSTRAINT fk_board_type FOREIGN KEY (board_type_id) REFERENCES board_types (id);

ALTER TABLE firmware_versions
    ADD COLUMN IF NOT EXISTS board_type_id INT UNSIGNED DEFAULT NULL AFTER id;

UPDATE firmware_versions fv
JOIN board_types t ON t.name = 'Heltec Wireless Stick Lite V3'
SET fv.board_type_id = t.id
WHERE fv.board_type_id IS NULL;

ALTER TABLE firmware_versions
    DROP INDEX IF EXISTS version;

ALTER TABLE firmware_versions
    MODIFY board_type_id INT UNSIGNED NOT NULL;

ALTER TABLE firmware_versions
    ADD UNIQUE KEY board_type_version (board_type_id, version);

ALTER TABLE firmware_versions
    ADD CONSTRAINT fk_firmware_board_type FOREIGN KEY (board_type_id) REFERENCES board_types (id);

CREATE TABLE IF NOT EXISTS virtual_devices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    board_id INT UNSIGNED NOT NULL,
    public_id VARCHAR(32) NOT NULL,
    friendly_name VARCHAR(191) DEFAULT NULL,
    wiegand_reader TINYINT UNSIGNED DEFAULT NULL,
    accesstag_list_version VARCHAR(64) DEFAULT NULL,
    accesstag_list_pushed_version VARCHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY public_id (public_id),
    KEY board_id (board_id),
    CONSTRAINT fk_vd_board FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO virtual_devices (board_id, public_id, friendly_name)
SELECT b.id,
       CONCAT('vd_', SUBSTRING(SHA2(CONCAT('ezgate', b.id, b.hardware_id), 256), 1, 8)),
       b.friendly_name
FROM boards b
WHERE NOT EXISTS (SELECT 1 FROM virtual_devices vd WHERE vd.board_id = b.id);

CREATE TABLE IF NOT EXISTS virtual_device_relays (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    virtual_device_id INT UNSIGNED NOT NULL,
    relay_number INT UNSIGNED NOT NULL,
    pulse_duration_ms INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY device_relay (virtual_device_id, relay_number),
    CONSTRAINT fk_relay_vd FOREIGN KEY (virtual_device_id) REFERENCES virtual_devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO virtual_device_relays (virtual_device_id, relay_number, pulse_duration_ms)
SELECT vd.id, 1, b.pulse_duration_ms
FROM virtual_devices vd
JOIN boards b ON b.id = vd.board_id
JOIN board_types t ON t.id = b.board_type_id
WHERE t.relay_count = 1
  AND NOT EXISTS (
      SELECT 1 FROM virtual_device_relays r WHERE r.virtual_device_id = vd.id AND r.relay_number = 1
  );

CREATE TABLE IF NOT EXISTS virtual_device_accesstags (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    virtual_device_id INT UNSIGNED NOT NULL,
    accesstag_number VARCHAR(64) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY vd_number (virtual_device_id, accesstag_number),
    CONSTRAINT fk_tag_vd FOREIGN KEY (virtual_device_id) REFERENCES virtual_devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS virtual_device_accesstag_baseline (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    virtual_device_id INT UNSIGNED NOT NULL,
    accesstag_number VARCHAR(64) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY vd_number (virtual_device_id, accesstag_number),
    CONSTRAINT fk_base_vd FOREIGN KEY (virtual_device_id) REFERENCES virtual_devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE open_commands
    ADD COLUMN IF NOT EXISTS virtual_device_id INT UNSIGNED DEFAULT NULL AFTER board_id;

UPDATE open_commands oc
JOIN virtual_devices vd ON vd.board_id = oc.board_id
SET oc.virtual_device_id = vd.id
WHERE oc.virtual_device_id IS NULL;

UPDATE open_commands
SET status = 'expired', completed_at = UTC_TIMESTAMP()
WHERE status = 'pending' AND virtual_device_id IS NULL;

ALTER TABLE open_commands
    ADD KEY virtual_device_status (virtual_device_id, status);

ALTER TABLE open_commands
    ADD CONSTRAINT fk_open_vd FOREIGN KEY (virtual_device_id) REFERENCES virtual_devices (id) ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS access_reads (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    virtual_device_id INT UNSIGNED NOT NULL,
    accesstag_number VARCHAR(64) NOT NULL,
    read_at DATETIME DEFAULT NULL,
    in_list TINYINT(1) NOT NULL,
    board_event_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY vd_event (virtual_device_id, board_event_id),
    CONSTRAINT fk_read_vd FOREIGN KEY (virtual_device_id) REFERENCES virtual_devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
