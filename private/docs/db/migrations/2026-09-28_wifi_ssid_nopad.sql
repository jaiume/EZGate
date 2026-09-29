-- SSIDs may include trailing spaces and are case-sensitive.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE site_wifi_networks
    MODIFY ssid VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL;

ALTER TABLE boards
    MODIFY last_wifi_ssid VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin DEFAULT NULL;
