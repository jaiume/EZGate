-- WiFi security type for site networks
SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE site_wifi_networks
    ADD COLUMN IF NOT EXISTS security ENUM('open','wpa2','wpa3') NOT NULL DEFAULT 'wpa2' AFTER password;

UPDATE site_wifi_networks SET security = 'open' WHERE password = '';
