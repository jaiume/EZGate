-- LoRa credentials, site traffic keys, LoRa-only, open delivery tracking.
ALTER TABLE sites
    ADD COLUMN IF NOT EXISTS lora_key CHAR(32) DEFAULT NULL AFTER pulse_duration_ms,
    ADD COLUMN IF NOT EXISTS lora_key_id SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER lora_key;

ALTER TABLE boards
    ADD COLUMN IF NOT EXISTS lora_credential CHAR(32) DEFAULT NULL AFTER hardware_id,
    ADD COLUMN IF NOT EXISTS lora_only TINYINT(1) NOT NULL DEFAULT 0 AFTER reboot_requested;

ALTER TABLE open_commands
    ADD COLUMN IF NOT EXISTS delivered_at DATETIME DEFAULT NULL AFTER created_at,
    ADD COLUMN IF NOT EXISTS expires_at DATETIME DEFAULT NULL AFTER delivered_at;
