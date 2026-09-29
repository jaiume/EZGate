-- Live schema dump from EntryZen_ezgate, 2026-09-27
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE `sites` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `pulse_duration_ms` int(10) unsigned DEFAULT NULL,
  `lora_key` char(32) DEFAULT NULL,
  `lora_key_id` smallint(5) unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `site_wifi_networks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `site_id` int(10) unsigned NOT NULL,
  `ssid` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  `password` varchar(255) NOT NULL DEFAULT '',
  `security` enum('open','wpa2','wpa3') NOT NULL DEFAULT 'wpa2',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_ssid` (`site_id`,`ssid`),
  CONSTRAINT `fk_wifi_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `firmware_versions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(64) NOT NULL,
  `notes` text DEFAULT NULL,
  `checksum` char(64) NOT NULL,
  `original_filename` varchar(191) NOT NULL,
  `stored_filename` varchar(191) NOT NULL,
  `file_size` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `boards` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `hardware_id` varchar(191) NOT NULL,
  `lora_credential` char(32) DEFAULT NULL,
  `friendly_name` varchar(191) DEFAULT NULL,
  `site_id` int(10) unsigned DEFAULT NULL,
  `pulse_duration_ms` int(10) unsigned DEFAULT NULL,
  `config_updated_at` datetime DEFAULT NULL,
  `reported_config_version` datetime DEFAULT NULL,
  `last_heartbeat_at` datetime DEFAULT NULL,
  `last_uptime_seconds` int(10) unsigned DEFAULT NULL,
  `last_status` varchar(64) DEFAULT NULL,
  `last_gate_state` varchar(32) DEFAULT NULL,
  `last_wifi_ssid` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin DEFAULT NULL,
  `last_wifi_rssi` smallint(6) DEFAULT NULL,
  `last_connectivity_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`last_connectivity_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reported_firmware_version` varchar(64) DEFAULT NULL,
  `reported_fallback_firmware_version` varchar(64) DEFAULT NULL,
  `target_firmware_version_id` int(10) unsigned DEFAULT NULL,
  `scan_requested` tinyint(1) NOT NULL DEFAULT 0,
  `reboot_requested` tinyint(1) NOT NULL DEFAULT 0,
  `lora_only` tinyint(1) NOT NULL DEFAULT 0,
  `ota_version` varchar(64) DEFAULT NULL,
  `ota_offset` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hardware_id` (`hardware_id`),
  KEY `site_id` (`site_id`),
  KEY `idx_boards_target_firmware` (`target_firmware_version_id`),
  CONSTRAINT `fk_board_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_board_target_firmware` FOREIGN KEY (`target_firmware_version_id`) REFERENCES `firmware_versions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `open_commands` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `board_id` int(10) unsigned NOT NULL,
  `status` enum('pending','completed','expired') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `delivered_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `board_status` (`board_id`,`status`),
  CONSTRAINT `fk_open_board` FOREIGN KEY (`board_id`) REFERENCES `boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
