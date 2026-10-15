-- Live schema dump from EntryZen_ezgate, 2026-10-07
SET NAMES utf8mb4;
SET time_zone = "+00:00";

CREATE TABLE `access_reads` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `virtual_device_id` int(10) unsigned NOT NULL,
  `accesstag_number` varchar(64) NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `in_list` tinyint(1) NOT NULL,
  `board_event_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `vd_event` (`virtual_device_id`,`board_event_id`),
  CONSTRAINT `fk_read_vd` FOREIGN KEY (`virtual_device_id`) REFERENCES `virtual_devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `board_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `relay_count` int(10) unsigned NOT NULL,
  `wiegand_count` int(10) unsigned NOT NULL DEFAULT 0,
  `has_lora` tinyint(1) NOT NULL DEFAULT 0,
  `has_wifi` tinyint(1) NOT NULL DEFAULT 0,
  `has_ethernet` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `boards` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `hardware_id` varchar(191) NOT NULL,
  `board_type_id` int(10) unsigned NOT NULL,
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
  `target_firmware_version_id` int(10) unsigned DEFAULT NULL,
  `scan_requested` tinyint(1) NOT NULL DEFAULT 0,
  `reboot_requested` tinyint(1) NOT NULL DEFAULT 0,
  `lora_only` tinyint(1) NOT NULL DEFAULT 0,
  `ota_version` varchar(64) DEFAULT NULL,
  `ota_offset` int(10) unsigned DEFAULT NULL,
  `reported_fallback_firmware_version` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hardware_id` (`hardware_id`),
  KEY `site_id` (`site_id`),
  KEY `idx_boards_target_firmware` (`target_firmware_version_id`),
  KEY `fk_board_type` (`board_type_id`),
  CONSTRAINT `fk_board_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_board_target_firmware` FOREIGN KEY (`target_firmware_version_id`) REFERENCES `firmware_versions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_board_type` FOREIGN KEY (`board_type_id`) REFERENCES `board_types` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `firmware_versions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `board_type_id` int(10) unsigned NOT NULL,
  `version` varchar(64) NOT NULL,
  `notes` text DEFAULT NULL,
  `checksum` char(64) NOT NULL,
  `original_filename` varchar(191) NOT NULL,
  `stored_filename` varchar(191) NOT NULL,
  `file_size` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `board_type_version` (`board_type_id`,`version`),
  CONSTRAINT `fk_firmware_board_type` FOREIGN KEY (`board_type_id`) REFERENCES `board_types` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `open_commands` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `board_id` int(10) unsigned NOT NULL,
  `virtual_device_id` int(10) unsigned DEFAULT NULL,
  `status` enum('pending','completed','expired') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `delivered_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `board_status` (`board_id`,`status`),
  KEY `virtual_device_status` (`virtual_device_id`,`status`),
  CONSTRAINT `fk_open_board` FOREIGN KEY (`board_id`) REFERENCES `boards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_open_vd` FOREIGN KEY (`virtual_device_id`) REFERENCES `virtual_devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=121 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sites` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `pulse_duration_ms` int(10) unsigned DEFAULT NULL,
  `lora_key` char(32) DEFAULT NULL,
  `lora_key_id` smallint(5) unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `virtual_device_accesstag_baseline` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `virtual_device_id` int(10) unsigned NOT NULL,
  `accesstag_number` varchar(64) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vd_number` (`virtual_device_id`,`accesstag_number`),
  CONSTRAINT `fk_base_vd` FOREIGN KEY (`virtual_device_id`) REFERENCES `virtual_devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `virtual_device_accesstags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `virtual_device_id` int(10) unsigned NOT NULL,
  `accesstag_number` varchar(64) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vd_number` (`virtual_device_id`,`accesstag_number`),
  CONSTRAINT `fk_tag_vd` FOREIGN KEY (`virtual_device_id`) REFERENCES `virtual_devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `virtual_device_relays` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `virtual_device_id` int(10) unsigned NOT NULL,
  `relay_number` int(10) unsigned NOT NULL,
  `pulse_duration_ms` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_relay` (`virtual_device_id`,`relay_number`),
  CONSTRAINT `fk_relay_vd` FOREIGN KEY (`virtual_device_id`) REFERENCES `virtual_devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `virtual_devices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `board_id` int(10) unsigned NOT NULL,
  `public_id` varchar(32) NOT NULL,
  `friendly_name` varchar(191) DEFAULT NULL,
  `wiegand_reader` tinyint(3) unsigned DEFAULT NULL,
  `accesstag_list_version` varchar(64) DEFAULT NULL,
  `accesstag_list_pushed_version` varchar(64) DEFAULT NULL,
  `reads_endpoint` varchar(512) DEFAULT NULL,
  `reads_token` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `public_id` (`public_id`),
  KEY `board_id` (`board_id`),
  CONSTRAINT `fk_vd_board` FOREIGN KEY (`board_id`) REFERENCES `boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

