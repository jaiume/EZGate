# EZGate

EZGate manages a fleet of smart gate relays and gives consumers connectivity to them. Every relay is a [Heltec Wireless Stick](https://wiki.heltec.org/docs/devices/open-source-hardware/esp32-series/lora-32/wireless-stick/) with a relay module tied to one GPIO pin. Operators manage the fleet through a portal. This project includes writing the firmware, and every board runs that same firmware.

The board provides the radios and the sensor input. The relay module provides the dry-contact output. The firmware provides portal connectivity, LoRa routing, gateway mode, and fallback when WiFi or its internet connectivity is lost.

## Boards

Each board determines its own unique identifier. Its friendly name is set during initial setup.

An open command arrives over WiFi or LoRa. The firmware pulses the relay module, through its GPIO pin, for a configurable duration into a low-voltage gate controller input. The default duration is 500 ms.

An external gate-state sensor is optional. When one is connected, the board reads open/closed feedback. The board includes gate state and status in the heartbeat it sends to the portal.

On the board:

- One green status LED. Its blink pattern shows the operating mode.
- Config resets WiFi.
- Boot is used to flash firmware.
- Reset reboots the board.

## Sites

Boards are assigned to sites. A site may have more than one WiFi network. A board uses the best configured WiFi network for its site.

## Initial setup

A board is in the initial setup state after a USB firmware load, and it returns to that state if it becomes isolated. Until setup is complete, the board broadcasts its own WiFi network, including when its only field link will be LoRa. The installer connects to that network and can see the WiFi networks available to the board. When a LoRa connection is available for setup, that choice is offered as well. The installer chooses one of the presented options.

If the installer adds the board to a WiFi network, they enter that network's credentials. Once the board connects, it contacts the portal and receives the list of sites. The installer chooses a site and gives the board a friendly name. The WiFi connection details are stored on the portal for the site, and the board is configured onto the chosen site.

If the installer chooses LoRa, the board reaches the portal through a board that already has a portal connection. The installer chooses a site and gives the board a friendly name, and the board is configured onto the chosen site.

## Connectivity

WiFi is preferred. LoRa is the fallback. After setup, the board uses the best configured WiFi network for its site. It switches to LoRa when no configured WiFi network has internet, and it returns to the best configured WiFi network when one has internet again. The portal can tell a board to use LoRa only. Before the board switches, its firmware checks that an upstream connection is available, so the board stays reachable. If a board becomes isolated, it reverts to the initial setup state.

- **WiFi with internet.** The board connects to the portal, performs its own gate functions, and listens on LoRa so it can gateway peer boards.
- **No configured WiFi with internet.** The board operates over LoRa and sends commands and state to the nearest WiFi-connected gateway it can reach. A LoRa-only board relays for another LoRa-only board when that board cannot reach a WiFi-connected board directly.

Any WiFi-connected board on the same site can serve as a gateway.

Each board sends a heartbeat to the portal with its status and connectivity, including the option it is using and the other options available to it. The heartbeat also includes the board's current configuration version, a datetime stamp of that configuration. The portal remembers the version for each board and sends configuration back only when its copy is newer. That configuration includes the relay pulse duration, WiFi connections, and other config.

All boards share one private LoRa network, EZGate. Traffic on that network is encrypted with keys set during the initial firmware load.

## Firmware

Every firmware load over USB wipes the board's configuration and leaves the board in the initial setup state. The first load is done before the board is put in the field. It adds the board to inventory with no site assigned and sets the LoRa encryption keys.

After deployment, updates are pushed to the board. An update in the field must not lose the board's configuration. The board checks the new firmware's checksum before applying it and keeps the existing working firmware as a fallback. A watchdog switches back to that older firmware if the new firmware cannot connect to the portal.

## Management portal

The portal is how operators run the fleet:

- List boards by the identifier the board determines and by friendly name
- Show each board's upstream connectivity: WiFi or LoRa, and connection strength. For a board that is on LoRa, also show its parent node. Show the other connectivity options the board reports
- Open a modal that shows the current connectivity graph
- Tell a board to use LoRa only. The firmware switches only after it confirms an available upstream connection
- Add a board to a site, remove it, or move it to another site
- List the WiFi networks configured for a site
- Ask a board to scan for WiFi networks in range and report each network with its signal strength
- Add a scanned WiFi network to the WiFi networks configured for the board's site
- Set the relay pulse duration. The default is 500 ms. The portal delivers it with the board's other configuration in the heartbeat response
- Manage firmware versions and push them to devices
- Load firmware over USB

## External API

The portal accepts external API calls that trigger a board or read whether that board is online. See [consumer-integration.md](consumer-integration.md).
