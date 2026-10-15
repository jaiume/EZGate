ALTER TABLE virtual_devices
    ADD COLUMN reads_endpoint VARCHAR(512) DEFAULT NULL AFTER accesstag_list_pushed_version,
    ADD COLUMN reads_token VARCHAR(255) DEFAULT NULL AFTER reads_endpoint;
