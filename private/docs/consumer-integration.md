# Consumer integration

External callers use two JSON endpoints on the portal host. They do not use an operator session cookie or a CSRF token.

Base URL example: `https://ezgate-dev.stuckbendix.com`

Ask the operator for the API key. They copy it from `api.api_key` in `config.ini`. Do not commit that value.

## Authentication

Send the key in the first of these that is present. A blank value in an earlier slot is still the one that is checked.

1. `Authorization: Bearer <api_key>`
2. `X-EZGate-Token: <api_key>`
3. `token` in the POST body, or as a query parameter on `GET /api/status`

## Board identifier

The board is the hardware id the device determines. Send it as `identifier`. If `identifier` is omitted, send `hardware_id` with the same value.

The value is trimmed. Lookup is case-insensitive. The response `hardware_id` is the stored value, which can differ in case from the request. Any known board can be called, including one with no site.

## POST /api/trigger

Queues an open pulse for the board if none is already in flight. It does not return online or offline status.

```http
POST /api/trigger HTTP/1.1
Host: ezgate-dev.stuckbendix.com
Authorization: Bearer <api_key>
Content-Type: application/json

{"identifier": "EC0F0604A7AC"}
```

The body may be JSON (`Content-Type: application/json`) or form fields. `hardware_id` is accepted when `identifier` is absent. `token` is accepted in the body when the key is not in a header. No other fields are read.

`200` means the portal accepted the trigger. It does not mean the board is online and does not mean the relay has fired. Extra calls while a pulse is still in flight (queued, or already handed to a heartbeat but not yet reported executed) return `200` and do not queue another relay fire. A newer pulse cancels any earlier pending pulses for that board. A new pulse is allowed after the board reports that command executed, or after the in-flight pulse expires. Expiry is four heartbeat intervals of the board’s current path: `wifi.heartbeat_interval_seconds` on Wi‑Fi, `lora.heartbeat_interval_seconds` on LoRa (20 seconds at the default 5-second intervals).

```json
{
  "success": true,
  "data": {
    "hardware_id": "EC0F0604A7AC"
  },
  "error": null
}
```

## GET /api/status

Reads whether the portal currently shows that board Online or Offline. It does not queue a pulse and does not require a prior trigger. There is no request body.

```http
GET /api/status?identifier=EC0F0604A7AC HTTP/1.1
Host: ezgate-dev.stuckbendix.com
Authorization: Bearer <api_key>
```

`hardware_id` is accepted when `identifier` is absent. `token` is accepted as a query parameter when the key is not in a header.

`status` is `online` or `offline`. `online` uses the same heartbeat cutoff as the portal Online badge (`app.isolation_timeout_seconds`, 60 seconds unless that setting is changed). A board that has never checked in is `offline`.

```json
{
  "success": true,
  "data": {
    "hardware_id": "EC0F0604A7AC",
    "status": "online"
  },
  "error": null
}
```

## Errors

Both endpoints use the same failure envelope.

```json
{
  "success": false,
  "message": "Board not found.",
  "data": null,
  "error": { "code": "NOT_FOUND", "details": [] }
}
```

| HTTP status | `error.code` | `message` |
| --- | --- | --- |
| 401 | `UNAUTHORIZED` | Invalid API key. |
| 400 | `VALIDATION_ERROR` | Board identifier is required. |
| 404 | `NOT_FOUND` | Board not found. |
