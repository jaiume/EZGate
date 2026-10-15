# EZGate

Operator portal and firmware for a fleet of smart gate relays. Each relay is a [Heltec Wireless Stick V3](https://wiki.heltec.org/docs/devices/open-source-hardware/esp32-series/lora-32/wireless-stick/) with a relay module on one GPIO pin. Every board runs the firmware in this repository. Operators manage sites, boards, Wi‑Fi, firmware, and open pulses through the portal. External systems can queue an open pulse or ask whether a board is online.

Wi‑Fi is the preferred path to the portal. LoRa is the fallback: a board that cannot reach the internet joins a gateway-centred tree and uses a Wi‑Fi board on the same site as its upstream. A board that loses every path returns to setup.

## What the portal does

- List boards by hardware id and friendly name, with online or offline status
- Show how each board is connected (Wi‑Fi or LoRa), signal, parent, and other networks it has seen
- Open a per-site connectivity graph
- Assign boards to sites, move them, or remove them
- Store the Wi‑Fi networks for a site, request a scan from a board, and add a scanned network
- Set the relay pulse duration (default 500 ms) and push it with the rest of the board config
- Queue an open pulse and follow it from sent to delivered to executed
- Manage firmware versions, push them over the air, and load firmware, site config, and LoRa credentials over USB

Operator pages use a single account from `config.ini` and a session cookie. Device and consumer APIs do not use that session.

## Layout

`private/` is the PHP application. The name is a hosting constraint (the web root and the app directory are the two paths the server may read), not a marker that the directory is omitted from the repository.

```text
public/                 Web root (document root)
private/
  bin/                  migrate.php, publish-firmware.php
  config/               config.ini.example, container, routes
  docs/                 product notes, consumer API, schema and migrations
  src/                  controllers, services, DAOs
  templates/            Twig pages
  tests/                PHPUnit
firmware/               PlatformIO project for the Heltec Wireless Stick V3
```

## Requirements

- PHP 8.2 with the PDO MySQL extension
- Composer
- MariaDB or MySQL
- Apache or Nginx with the document root set to `public/`
- For firmware: Python 3 and a USB connection to a Heltec Wireless Stick V3 (see `firmware/README.md`)

## Portal setup

1. Create an empty database and a user that can use it.
2. Copy `private/config/config.ini.example` to `private/config/config.ini`.
3. Set the database, operator username and password, and `api.api_key`. Do not commit `config.ini`.
4. Install dependencies and apply migrations:

```bash
cd private
composer install
php bin/migrate.php
```

5. Point the site document root at `public/` and open `/login`.

`public/index.php` sets the application base directory to `private/`. On a Hestia host, `public_html` can be a symlink to `public/`.

## Consumer API

External callers use an API key from `api.api_key`. They do not use the operator session.

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/api/trigger` | Queue an open pulse for a virtual device that has relays |
| `GET` | `/api/status` | Read whether that virtual device's relays are available |
| `GET` | `/api/accesstag-reader-status` | Read whether a Wiegand device is autonomous or reader-only |
| `POST` | `/api/accesstags` | Replace an autonomous list, or set where reads are posted |

The request and response contract, including auth headers and error codes, is in [`private/docs/consumer-integration.md`](private/docs/consumer-integration.md).

Boards use separate unauthenticated endpoints identified by hardware id: `GET /api/setup/sites`, `POST /api/setup/complete`, `POST /api/heartbeat`, and `GET /api/firmware/download`.

## Firmware

PlatformIO project for the Heltec Wireless Stick V3. Current image is **1.0.51**.

```bash
firmware/setup-pio.sh
firmware/pio run
```

Set `EZG_PORTAL_HOST` in `firmware/src/config.h` before building, and keep `EZG_FIRMWARE_VERSION` in sync with `board_build.app_version` in `firmware/platformio.ini`.

After a USB factory flash the board starts in setup. Join the access point `EZGate-<last4 of id>` (password `ezgate-setup`) or pick a heard LoRa site, then open `http://192.168.4.1` and choose a network, a portal site, and a friendly name.

Pins, LoRa behavior, OTA, and the manual checklist are in [`firmware/README.md`](firmware/README.md).

## Tests

```bash
cd private && vendor/bin/phpunit
```

## Further reading

- [`private/docs/projectdescription.md`](private/docs/projectdescription.md) — product behavior
- [`private/docs/project-decisions.md`](private/docs/project-decisions.md) — stack and hosting choices
- [`private/docs/db/current_schema.sql`](private/docs/db/current_schema.sql) — schema reference
