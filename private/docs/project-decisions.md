# EZGate project decisions

Recorded during first-pass setup. Product behavior stays in `private/docs/projectdescription.md`.

## Confirmed choices

- Project type: web application (operator pages plus JSON APIs). No mobile API. No email.
- Host: Hestia domain `ezgate-dev.stuckbendix.com`. Runtime is PHP 8.2.33, so the app targets PHP 8.2.
- Stack: Slim 4, PHP-DI, Twig, Bootstrap 5.3.8, `config/config.ini`.
- Database: MariaDB database `EntryZen_ezgate`.
- Auth: one operator account in `config.ini` (username `admin`). Session cookie for pages. Web auth failure redirects to `/login`. API failures return JSON 401.
- Device APIs do not use the operator session. A board is identified by the hardware id it sends. EntryZen calls a virtual device id on `POST /api/trigger`, `GET /api/status`, `GET /api/accesstag-reader-status`, and `POST /api/accesstags`. Consumer contract: `private/docs/consumer-integration.md`.
- Tests: PHPUnit unit tests for config-version comparison and the trigger token. Firmware uses the checklist in `firmware/README.md`.
- Twig is used because the operator portal is server-rendered.

## Document root

The Slim web root is `public/` (`public_html` is a symlink to it). Hestia `open_basedir` only allows `public_html` and `private`, so the PHP application (config, src, templates, vendor, var) lives under `private/`. `public/index.php` sets `BASE_DIR` to `private/`.

## Firmware pins

Heltec Wireless Stick V3, documented in `firmware/src/config.h`:

- Relay module: GPIO 4
- Optional gate-state input: GPIO 5 (disabled in this pass)
- Green status LED: GPIO 35
- Config / WiFi reset: GPIO 0 (PRG)

## Heartbeat

Default interval is 10 seconds (`app.heartbeat_interval_seconds`). Isolation timeout is 60 seconds. Config timestamps are UTC datetimes. The portal sends configuration only when its `config_updated_at` is newer than the version the board reports.

## Shipped after first pass

- Portal-requested WiFi scan (`boards.scan_requested`, dashboard/inventory Scan WiFi). Add a scanned SSID to the site from the dashboard.
- OTA checksum, dual app slots, confirm after a stable loop (~10 s), panic/WDT rollback. Rollback is not based on portal connectivity.
- Per-site connectivity graph modal on the dashboard (WiFi now; LoRa hops when boards report them).

## LoRa (shipped 1.0.26)

Heltec Wireless Stick V3 SX1262 at 915 MHz SF7/BW125. Per-site traffic keys. USB writes a per-board credential only (inventory JS never fetches site keys). Dynamic leaf/relay on a hop-to-gateway tree. Config.ini `[lora]` knobs ride the normal heartbeat config push. `radio_stats` is stored in `last_connectivity_json`. Load-test last using Serial `EZG lora` traces, dashboard radio stats, and those knobs.
