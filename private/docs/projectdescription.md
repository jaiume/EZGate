# EZGate

EZGate manages a fleet of gate boards and gives consumers connectivity to the virtual devices on those boards. A virtual device is what EntryZen addresses. It may own relays, one Wiegand reader, or both. Two board types are built in: Heltec Wireless Stick Lite V3 (one relay, Wi-Fi, LoRa) and Kincony KC868-A8 (eight relays, two Wiegand readers, Wi-Fi, Ethernet). Each type has its own firmware image. Operators manage the fleet through the portal.

The board provides the radios and the sensor input. The relay module provides the dry-contact output. The firmware provides portal connectivity, LoRa routing, gateway mode, and fallback when WiFi or its internet connectivity is lost.

## Boards

Each physical board has a type, a hardware id, and its own name. Its virtual devices are listed under it on the dashboard and in inventory, including a one-relay board. An operator adds, changes, and removes those devices on the board’s edit page. A relay and a reader each belong to at most one virtual device. Pulse duration is stored per relay. A null pulse inherits the site pulse, then the app default (500 ms).

An open command is queued for one virtual device. The heartbeat delivers it on that device, and the board reports `completed_command_id` and `received_command_id` on that same device after the relays turn on.

The Heltec board can include gate state in its heartbeat. The A8 runs as an offline controller after boot: its accesstag lists and virtual-device map are on SPIFFS and survive power loss and field OTA. A USB firmware load wipes NVS and SPIFFS. Wiegand checks and relay pulses keep running while the A8 setup AP is up.

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

The portal accepts external API calls that trigger a virtual device, replace an autonomous accesstag list, read relay availability, and read whether a Wiegand device is autonomous or reader-only. The board reports reads to the portal. The portal posts each device's reads to the destination from its latest accesstag push, or to `[entryzen] reads_url` when that device has never had a destination. See [consumer-integration.md](consumer-integration.md).
