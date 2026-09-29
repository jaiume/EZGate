# EZGate firmware

PlatformIO project for the Heltec Wireless Stick V3. Every board runs this image.

Version **1.0.49**. USB factory flash writes bootloader @ `0x0`, partitions @ `0x8000`, otadata @ `0xE000`, and the app @ `0x10000` using the Arduino `default_8MB` layout (two OTA slots, no `phy_init` partition). The portal **USB** page treats firmware, site configuration (`EZGCFG`), and the per-board LoRa credential (`EZGKEY`) as separate writes and does not reboot the board afterward. Clear config (`EZGCLR`) wipes `ezgate` only; `ezgsec` keys survive. The Config / PRG button does the same clear, then reboots into setup. Heltec USB-C is the CP2102 UART, so `Serial` is UART0 (not native USB CDC). USB is host-driven: the portal sends `EZGUSB`, the board replies `EZGUSB ok <version> <id>` and then waits for `EZGCLR` / `EZGCFG` / `EZGKEY`. It does not print a ready banner. Missing NVS string keys use defaults without error logs. Heartbeat poll is `wifi.heartbeat_interval_seconds` on Wi‑Fi and `lora.heartbeat_interval_seconds` on LoRa (both default 5); the board stores both and uses the interval for its current path. Site traffic keys arrive wrapped with the USB-injected board credential, never as plaintext.

## Pins

| Function | GPIO | Notes |
|----------|------|--------|
| Relay module | 4 | Active high pulse. Change in `src/config.h`. |
| Optional gate-state input | 5 | Disabled until `EZG_GATE_STATE_ENABLED` is 1. |
| Status LED | 35 | Setup/recovery: 1s on, 1s off. Service: 1 flash per heartbeat when the portal path is confirmed (Wi‑Fi or LoRa `portal_ok`); 2/3/4 blinks then a 1s pause for no uplink / no internet / no portal. |
| Config (WiFi reset) | 0 | PRG button. Clears stored Wi‑Fi/site config and reboots into setup. Does not wipe LoRa keys in `ezgsec`. |
| SX1262 NSS / DIO1 / RST / BUSY | 8 / 14 / 12 / 13 | 915.0 MHz, SF7, BW 125 kHz, CR 4/5. |
| Boot / Reset | hardware | Unchanged. |

## Setup

1. Create the project-local PlatformIO venv once: `firmware/setup-pio.sh`.
2. Build with `firmware/pio run`. Flash over USB from the portal USB page (Chrome or Edge), or with `firmware/pio run -t upload`.
3. Join the board AP `EZGate-<last4 of id>`, password `ezgate-setup`. Or pick a heard LoRa site on the setup page when a gateway is in range.
4. Open `http://192.168.4.1`, pick a WiFi network (optional when using LoRa), then a portal site and friendly name.

The Python venv lives in `firmware/.venv`. Toolchains and packages live in `firmware/.platformio`. Neither is shared with other EntryZen projects.

Set `EZG_PORTAL_HOST` in `src/config.h` before building. Keep `EZG_FIRMWARE_VERSION` in sync with `board_build.app_version` in `platformio.ini`. The portal reads the version from the `EZGVER:` marker and `esp_app_desc`.

## WiFi scans

- Setup AP scans so the installer can pick a network.
- In service, a full neighborhood scan runs only when the portal requests it (Scan WiFi). Results are reused on later heartbeats as `connectivity.other`.
- Reconnect probes configured site SSIDs. If the associated RSSI falls below `wifi_roam_rssi_dbm` (default −80, from `[app]`), the board scans those SSIDs and joins a better one when it is above the floor by `wifi_roam_hysteresis_dbm`. If none qualify and another LoRa gateway is heard, it drops Wi‑Fi and becomes a LoRa leaf of that gateway. A board on Wi‑Fi is always direct to the portal: it never takes a LoRa parent, and two Wi‑Fi boards never form a LoRa parent/child pair. If neither a better SSID nor a LoRa gateway exists, it stays on the current Wi‑Fi. Non‑`lora_only` boards re-probe Wi‑Fi about once a minute while on LoRa.

## Isolation

If the board misses `isolation_misses` heartbeats without `portal_ok` (default 3, from `[lora]`) and has no LoRa parent, it opens the setup/recovery AP and keeps retrying saved Wi‑Fi. A live LoRa parent is a path, so the board stays in service. If the recovery AP is already up, LoRa heartbeats continue and a LoRa `portal_ok` returns it to service. It does not wipe site configuration or LoRa keys. LoRa stays up until a Wi‑Fi heartbeat is portal-confirmed. A LoRa-only board drops Wi‑Fi only after a LoRa `portal_ok`.

## LoRa mesh

Gateway-centred tree (not a flood). A board relays only for children that selected it. Parent must have strictly fewer `hops_to_gateway`. Weak uplinks do not answer probes. Serial traces use `EZG lora` / `EZG http`. Heartbeats carry compact `radio_stats`. Open `command_id` is idempotent; `radio_ok` is not `portal_ok` is not `executed`.

`[lora]` knobs in `config.ini` are pushed like `ota_chunk_bytes`. Firmware keeps last-good NVS copies.

## OTA

`partitions.csv` is Arduino `default_8MB`: two 3.2MB app slots. From **1.0.15**, chunk sizes arrive with a normal config push (`ota_chunk_bytes` and `ota_lora_chunk_bytes` from `config.ini`) and are stored in NVS. From **1.0.14**, a portal firmware push downloads one chunk per heartbeat (default 50 KB). From **1.0.44**, LoRa config and firmware share one chunked downlink (`T_CHUNK`): 16-byte header plus payload. From **1.0.45**, chunks are stop-and-wait (ACK before the next offset) and spaced ~150 ms from other TX so a leaf ACK is not stomped by the next frame. From **1.0.46**, the gateway reads nested `config` / `firmware` as `JsonObjectConst` (ArduinoJson 7 `is<JsonObject>()` is always false on a const variant, so 1.0.44–1.0.45 never started a LoRa config chunk). Config JSON is reassembled in RAM (4 KB cap) and CRC-32 checked; firmware still writes the OTA slot and SHA-256 checks the full image. From **1.0.47**, the same chunker is bidirectional: a leaf can send uplink JSON (WiFi scan results) to its parent; a relay hops the reassembled blob toward the WiFi gateway, which POSTs it as a heartbeat. Compact LoRa heartbeats do not carry `connectivity.other`; the portal keeps the last scan list until a new list arrives. The relay pulse is serviced during each Wi‑Fi chunk. The image is SHA-256 checked only after the last chunk, then the board reboots into the new slot. From **1.0.5**, the new image is confirmed after the main loop has run for about 10 seconds. A panic or watchdog reset before that rolls back to the previous slot. Isolation (no portal) opens the recovery AP and does not roll back firmware. A portal reboot marks the running image valid first so it is not treated as a failed OTA. From **1.0.48**, WiFi and LoRa ACK a repeated open `command_id` without pulsing the relay again. From **1.0.49**, WiFi and LoRa heartbeat intervals are stored separately (`wifi.heartbeat_interval_seconds` and `lora.heartbeat_interval_seconds`).

The heartbeat reports `EZG_FIRMWARE_VERSION`, `uptime_seconds` since boot, and, when the other OTA slot has an image, `fallback_firmware_version`.

## Manual checklist

- USB factory flash from the USB page (firmware only; no reboot)
- USB Load config on a board already on a site (`EZGCFG`, overwrites NVS, no reboot)
- USB Load LoRa keys (`EZGKEY`)
- USB Clear config (`EZGCLR`) leaves keys and portal assignment; press RST to enter setup
- Board shows in portal inventory after setup
- Heartbeat updates last-seen, SSID, RSSI, and uptime
- Heartbeat checks google.com with a timed DNS lookup (not a blocking ICMP session)
- Status LED: setup/recovery is 1s on / 1s off; service is 1 flash per confirmed portal heartbeat (Wi‑Fi or LoRa), or 2/3/4 blinks then a 1s pause for no uplink / no internet / no portal
- Scan WiFi from inventory/dashboard fills other networks after the next heartbeats; Add to site from those scans
- Trigger API queues an open; next heartbeat pulses the relay; dashboard shows sent / delivered / executed
- Config button returns the board to setup without wiping LoRa keys
- Portal firmware push (1.0.14+) takes one 50 KB chunk per heartbeat; an open command still pulses during the transfer
- Pulling internet from WiFi opens recovery AP after isolation misses; firmware is not rolled back for that
- USB Write LoRa keys / Load config / Clear config reopen CDC, handshake with `EZGUSB`, then send `EZGKEY` / `EZGCFG` / `EZGCLR`
- LoRa: Serial `EZG lora` parent select/loss, hops, probe answer/suppress, TX fail/retry, queue drop, open received/executed/dup, OTA chunk offset

## LoRa load-test (last)

Use two boards minimum, three if available. Tune `[lora]` in `config.ini` (intervals, retries, hop limit, `ota_airtime_budget_ms`) after the run — do not hard-code a second protocol pass. Watch Serial, dashboard `radio_stats`, and `logging.path`.

1. Heartbeat: leaf `radio_ok` then gateway HTTPS `portal_ok`; dashboard role/hops/parent update.
2. Promotion / demotion: move the mid board so a leaf selects it (`hops_to_gateway` increases by one on the leaf, child count on the relay).
3. Idempotent open: Test Trigger; hop ACK is not “gate opened”; dashboard goes sent → delivered → executed; a duplicate `command_id` logs `open dup` and does not double-pulse.
4. Gateway interrupt: stop the gateway radio/Wi‑Fi; children re-probe; recovery AP only after `isolation_misses`; restore gateway and confirm tree reforms.
5. OTA under load: assign 1.0.26+ while heartbeats and an open are in flight; one OTA per gateway; leftover airtime only; resume from `ota_offset` if a chunk is dropped.
6. After the run, adjust idle/active interval, `tx_retries`, and `ota_airtime_budget_ms` from ini and confirm the next config push picks them up.
