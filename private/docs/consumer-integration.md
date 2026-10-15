# Consumer integration

External callers use the JSON endpoints on the portal host. They do not use an operator session cookie or a CSRF token.

Base URL example: `https://ezgate-dev.stuckbendix.com`

Ask the operator for the API key. They copy it from `api.api_key` in `config.ini`. Do not commit that value.

EntryZen addresses a virtual device (`vd_` plus random hex). The board hardware id is used by the board itself on heartbeat, reads, and list pulls. Sending a hardware id as `identifier` is a 404.

What a virtual device is assigned decides the contract. A virtual device has at most one Wiegand reader, plus zero or more relays.

- Relays and a Wiegand reader: operation `autonomous`. The board checks its list and pulses its own relays. EntryZen sends the list. The portal sends reads back. EntryZen can still pulse those relays with `POST /api/trigger`.
- Relays only: an opener. `GET /api/status` reports whether it is available. EntryZen opens it with `POST /api/trigger`.
- Wiegand reader only: operation `reader_only`. The board caches the read and does not match a list or pulse. The portal sends the read to EntryZen. EntryZen records it, or triggers a different opener virtual device. That association lives in EntryZen, not in the portal.

Portal errors use this envelope. HTTP status matches `error.code`: 401 `UNAUTHORIZED`, 400 `VALIDATION_ERROR`, 404 `NOT_FOUND`.

```json
{
  "success": false,
  "message": "Virtual device not found.",
  "data": null,
  "error": { "code": "NOT_FOUND", "details": [] }
}
```

## Board to EZGate

The board calls these. No portal API key.

### POST /api/reads

Same body for autonomous and reader_only devices. The portal stores `in_list` as false when that virtual device has no relays, even if the board sends true. An autonomous device keeps the board's `in_list`. A read for a virtual device with no Wiegand reader is listed in `rejected`.

The same `board_event_id` is the same read only when `virtual_device`, `accesstag_number`, `read_at`, and `in_list` also match. The same id with different fields is a new read. The portal owns every id in `accepted`, including when the EntryZen push is still pending.

```json
{
  "hardware_id": "60CF0604A7AC",
  "reads": [
    {
      "read_at": "2026-10-07T18:00:00Z",
      "virtual_device": "vd_94070a59",
      "accesstag_number": "1001",
      "in_list": true,
      "board_event_id": 7
    }
  ]
}
```

```json
{
  "success": true,
  "data": { "accepted": [7], "rejected": [] },
  "error": null
}
```

Unknown `hardware_id` is `404` `Board not found.` A missing `hardware_id` is `400` `Board identifier is required.` A missing `reads` array is `400` `Reads are required.` A read whose virtual device is not on that board is listed in `rejected`.

### POST /api/heartbeat

The board request is unchanged. `config` is included when the portal copy is newer. It has `friendly_name`, `relays`, and `wiegand`. It does not carry a processing mode. The board infers the mode from those fields.

- Wiegand set and at least one relay: list fields are included. `open` is included only when a trigger is already queued.
- Wiegand set and no relays: no `accesstag_list_version`, `accesstag_list_hash`, or `accesstag_list_replace`. No list pull.
- No Wiegand: relays and, when a trigger is waiting, `open`. No list fields.

The body includes `board_type` (`Heltec Wireless Stick Lite V3` or `Kincony KC868-A8`) and `virtual_devices`. Each reported virtual device carries `id`, and `completed_command_id` / `received_command_id` for the device whose relays just turned on. An autonomous device also sends `accesstag_list_version` and `accesstag_list_hash`.

The response keeps board `config` (present only when the portal copy is newer), plus `firmware`, `scan`, `reboot`, and the heartbeat intervals. Board `config` has no friendly name, no pulse, and no virtual devices. `virtual_devices[]` is one object per device. Pulse duration is the resolved milliseconds for that relay. `open` is omitted when nothing is waiting.

Autonomous device. The portal copy is newer, so `config` is included. The list fields tell the board the pushed list is version 3 and that it must replace. `open` is a trigger waiting to be delivered. `accesstag_list_version` here is the pushed version, not the version the board has confirmed.

```json
{
  "id": "vd_94070a59",
  "config": {
    "friendly_name": "Gate",
    "relays": [{ "relay": 1, "pulse_duration_ms": 500 }],
    "wiegand": 1
  },
  "accesstag_list_version": "3",
  "accesstag_list_hash": "aaa2b6a1bd052dd558f640c8e84d74ec53a2ad4c89c206198224ef762483917f",
  "accesstag_list_replace": true,
  "open": { "command_id": 12 }
}
```

### POST /api/accesstag-list

Used by an autonomous device. The request and `data` fields stay `version`, `list_hash`, `replace`, `total`, `offset`, and `changes` of `{ "op": "add"|"remove", "accesstag": "1001" }`.

Wi-Fi and Ethernet boards request up to 200 changes. A board whose portal path is LoRa requests 40 so one response stays under the 4 KB LoRa reassembly cap.

`from_version` and `from_hash` are required. Both are null when the board has no list. `offset` is zero-based and required. `limit` is required, from 1 to 200. `full` is optional.

`replace` is false when `from_version` is the version the board last reported and `from_hash` matches that baseline. `replace` is true when `full` is true, `from_version` is null, `from_version` is anything else, or `from_hash` does not match. A replace lists every pushed number as an add. A diff lists removals first, then additions, each sorted as raw bytes. Unchanged numbers are omitted. `total` is the change count. `list_hash` is the SHA-256 of the whole pushed list (each number, sorted, followed by a newline). The same hash is on every chunk. When `from_version` is already the pushed version and `from_hash` matches, `total` is 0.

```json
{
  "hardware_id": "60CF0604A7AC",
  "virtual_device": "vd_94070a59",
  "from_version": "1",
  "from_hash": "aaa2b6a1bd052dd558f640c8e84d74ec53a2ad4c89c206198224ef762483917f",
  "offset": 0,
  "limit": 200
}
```

A reader-only device is `400` `This reader does not use an accesstag list.` The stored list is kept when the device stops being autonomous. It is not pulled or reported until the device is autonomous again.

## EZGate to EntryZen

EntryZen calls the portal with the portal API key. The first of these that is present is the one that is checked: `Authorization: Bearer <api_key>`, `X-EZGate-Token: <api_key>`, then `token` in the JSON body or, on either GET, the query string. `reads_token` is not that key.

`identifier` is the virtual device public id. The value is trimmed. Lookup is exact.

### GET /api/status

Relay availability only. The virtual device must have at least one relay. `status` is `online` or `offline` from the parent board heartbeat. No accesstag fields. `accesstag_list_version` is not on this response. `online` uses `app.isolation_timeout_seconds` (60 seconds unless that setting is changed).

```http
GET /api/status?identifier=vd_94070a59 HTTP/1.1
Authorization: Bearer <api_key>
```

```json
{
  "success": true,
  "data": {
    "virtual_device": "vd_94070a59",
    "status": "online"
  },
  "error": null
}
```

- 401 `Invalid API key.`
- 400 `Identifier is required.`
- 400 `Virtual device has no relays.`
- 404 `Virtual device not found.`

Calling status retries queued reads. That retry is not part of this response.

### GET /api/accesstag-reader-status

Wiegand status. The path is the portal name for `accesstag_reader_status`. The virtual device must have a Wiegand reader. `operation` is derived. There is no stored mode.

Autonomous, relays and a reader, list not confirmed yet:

```http
GET /api/accesstag-reader-status?identifier=vd_94070a59 HTTP/1.1
Authorization: Bearer <api_key>
```

```json
{
  "success": true,
  "data": {
    "virtual_device": "vd_94070a59",
    "operation": "autonomous",
    "accesstag_list_version": null
  },
  "error": null
}
```

`operation` is computed on every call from the device's current relays and reader. `accesstag_list_version` is a string once the board confirms that version and hash. A push does not change it. `operation: "autonomous"` tells EntryZen to send a list and to expect reads. The board checks that list and pulses its own relays. EntryZen can still pulse this device's relays with `POST /api/trigger`.

Reader only:

```json
{
  "success": true,
  "data": {
    "virtual_device": "vd_94070a59",
    "operation": "reader_only"
  },
  "error": null
}
```

No `accesstag_list_version`. No `status`. Availability stays on `GET /api/status` for a virtual device that has relays. A reader-only device has none, so that call is `400` `Virtual device has no relays.` EntryZen does not send a list. The portal still caches reads and posts them. EntryZen chooses whether to record the read or trigger a separate opener.

- 401 `Invalid API key.`
- 400 `Identifier is required.`
- 400 `Virtual device has no Wiegand reader.`
- 404 `Virtual device not found.`

Calling this retries queued reads. That retry is not part of the response.

### POST /api/accesstags

Autonomous device. `version` and `accesstags` are required. Duplicates are stored once. An empty array clears the list. The same `version` is `200`, does not change the stored numbers, and still applies a destination pair when both fields are present. Other virtual devices on the same board are unchanged.

```json
{
  "identifier": "vd_94070a59",
  "version": "3",
  "accesstags": ["1001", "1002"],
  "reads_endpoint": "https://entryzen.example/reads",
  "reads_token": "..."
}
```

Omitting both destination fields leaves the stored destination as it is. One field without the other is `400` `Reads endpoint and token must both be set.` A non-http URL is `400` `Reads endpoint must be an http or https URL.` That check happens before the list is written.

```json
{
  "success": true,
  "data": { "virtual_device": "vd_94070a59", "version": "3" },
  "error": null
}
```

Reader-only device. No list. The body is the destination pair. A later call replaces the endpoint and token. There is no token history.

```json
{
  "identifier": "vd_94070a59",
  "reads_endpoint": "https://entryzen.example/reads",
  "reads_token": "..."
}
```

```json
{
  "success": true,
  "data": { "virtual_device": "vd_94070a59" },
  "error": null
}
```

A reader-only body that includes `version` or `accesstags` is `400` `This reader does not use an accesstag list.` A reader-only body with neither destination field, or with only one of them, is `400` `Reads endpoint and token must both be set.`

The success body never returns the endpoint or the token. The field is `reads_token`, not `token`, because `token` is already the portal API key.

- 401 `Invalid API key.`
- 400 `Identifier is required.`
- 400 `Version is required.` for an autonomous device
- 400 `Accesstags must be an array.`
- 400 `Accesstags must be non-empty strings.`
- 400 `Virtual device has no Wiegand reader.`
- 400 `Reads endpoint and token must both be set.`
- 400 `Reads endpoint must be an http or https URL.`
- 400 `This reader does not use an accesstag list.`
- 404 `Virtual device not found.`

### POST /api/trigger

For every virtual device that has relays, including an autonomous device that also has a Wiegand reader. `200` means the portal queued the pulse. It does not mean the relays have fired. A pulse already in flight for that device returns `200` and does not queue another. EntryZen uses this for an opener, and can use it to pulse an autonomous device's relays as well. The portal test button uses it too.

The body may be JSON or form fields. A pulse on one virtual device does not block a pulse on another device on the same board. Expiry is four heartbeat intervals of the board’s current path. Ethernet uses the Wi-Fi interval.

```json
{"identifier": "vd_94070a59"}
```

```json
{
  "success": true,
  "data": { "virtual_device": "vd_94070a59" },
  "error": null
}
```

- 401 `Invalid API key.`
- 400 `Identifier is required.`
- 400 `Virtual device has no relays.`
- 404 `Virtual device not found.`

### POST reads to EntryZen

One POST per destination. A device with `reads_endpoint` and `reads_token` uses those. `Authorization: Bearer` is the stored `reads_token`, loaded from the virtual device at send time. Devices that have never had a destination still use `[entryzen] reads_url` and `[entryzen] api_key`. A device with no destination stays queued. Reads for different endpoints or tokens are not mixed. The token and the response body are not logged.

```http
POST /reads HTTP/1.1
Host: entryzen.example
Authorization: Bearer <reads_token>
Content-Type: application/json
```

```json
{
  "reads": [
    {
      "read_at": "2026-10-07T18:00:00Z",
      "virtual_device": "vd_94070a59",
      "accesstag_number": "1001",
      "in_list": true
    }
  ]
}
```

`read_at` is UTC, or null when the board clock was unset. `in_list` is true only for an autonomous list match. The portal has already forced it false for a reader-only device.

Drop those rows only when the response is HTTP 200 and the body is JSON with `success: true`:

```json
{ "success": true }
```

Anything else keeps the whole batch queued, including EntryZen middleware HTTP 401:

```json
{ "error": "Unauthorized", "message": "Invalid or missing authentication token" }
```

Retry runs on that board's heartbeat, on `GET /api/status`, on `GET /api/accesstag-reader-status`, on `POST /api/reads` for that board, and immediately after an accesstag push that changes the stored endpoint or token.
