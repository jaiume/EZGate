-- EZGate first-pass schema
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS sites (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(191) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_wifi_networks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id INT UNSIGNED NOT NULL,
    ssid VARCHAR(191) NOT NULL,
    password VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY site_ssid (site_id, ssid),
    CONSTRAINT fk_wifi_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS boards (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    hardware_id VARCHAR(191) NOT NULL,
    friendly_name VARCHAR(191) DEFAULT NULL,
    site_id INT UNSIGNED DEFAULT NULL,
    pulse_duration_ms INT UNSIGNED NOT NULL DEFAULT 500,
    config_updated_at DATETIME DEFAULT NULL,
    reported_config_version DATETIME DEFAULT NULL,
    last_heartbeat_at DATETIME DEFAULT NULL,
    last_status VARCHAR(64) DEFAULT NULL,
    last_gate_state VARCHAR(32) DEFAULT NULL,
    last_wifi_ssid VARCHAR(191) DEFAULT NULL,
    last_wifi_rssi SMALLINT DEFAULT NULL,
    last_connectivity_json JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY hardware_id (hardware_id),
    KEY site_id (site_id),
    CONSTRAINT fk_board_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS open_commands (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    board_id INT UNSIGNED NOT NULL,
    status ENUM('pending', 'completed') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY board_status (board_id, status),
    CONSTRAINT fk_open_board FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
