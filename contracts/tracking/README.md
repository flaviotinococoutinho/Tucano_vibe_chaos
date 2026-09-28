# Live delivery

While a parcel of the own fleet (Tucano Express) is out for delivery, the courier's device reports where it is, and whoever follows the tracking code sees it move. Tracking owns this protocol (an open host service, in the terms of the context map); the device reports over HTTP, and the browser follows over a WebSocket. Use case: [UC-TRK-03](../../docs/use-cases/UC-TRK-03-follow-delivery-live.md).

Every message is a **delivery news**, one JSON object, the same shape in both directions ([`delivery-news.schema.json`](delivery-news.schema.json)):

| `type` | Fields | Meaning |
|---|---|---|
| `position` | `trackingCode`, `latitude`, `longitude`, `at`, `remainingMeters` | where the courier carrying the parcel is, at `at`, and how far the door still is |
| `ended` | `trackingCode`, `outcome` (`delivered` or `delivery_failed`), `at` | the visit is over; no position follows |

`at` is RFC 3339 in UTC. `latitude` and `longitude` are WGS 84 degrees. `remainingMeters` is a whole number of meters along the route, never negative.

```json
{"type": "position", "trackingCode": "TX02Q6AGJQ45G00", "latitude": -19.9112, "longitude": -44.0321, "at": "2026-09-28T21:56:13.634Z", "remainingMeters": 3120}
{"type": "ended", "trackingCode": "TX02Q6AGJQ45G00", "outcome": "delivered", "at": "2026-09-28T21:56:33.101Z"}
```

## Reporting: the device

`POST /v1/positions`, through Kong at `/api/tracking/v1/positions`, with one delivery news as the body.

- `Courier-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">`, with the secret shared through `COURIERS_SECRET`: the same scheme as the carrier webhooks, five minutes of tolerance, and several `v1` values allowed so a secret can rotate.
- `X-Correlation-Id`, optional, carried to the logs.

| Answer | When |
|---|---|
| `202 Accepted`, no body | the news is kept as the last one of its tracking code and sent to its followers |
| `401` problem | the signature is missing, malformed, stale or does not match |
| `422` problem, with `errors` per field | the body is not a delivery news |

A report is sent once and never retried: the next position replaces a lost one. Tracking keeps only the last news of each tracking code, for 15 minutes (`LIVE_NEWS_TTL_SECONDS`), and no history.

## Following: the browser

`GET /v1/live?trackingCode=<code>` with `Upgrade: websocket`, through Kong at `/api/tracking/v1/live?trackingCode=<code>`. The web never builds this address: the tracking screen of the BFF offers it as a link with the relation [`rel-live`](../http/bff/README.md#rel-live) while a parcel of the own fleet is out for delivery.

- A missing or malformed tracking code refuses the handshake with a `422` problem; any other path, with a `404` problem.
- Right after the handshake, tracking sends the last news it has for the code, if any, and then every new one, each as a text frame with the JSON above.
- After an `ended` news, tracking closes the connection with code `1000`.
- On shutdown, tracking closes with `1001` (going away), and the client connects again with a growing wait.
- The client sends nothing; a frame it sends is ignored.
- Staleness is the client's call: a `position` older than 30 s means the courier is out of signal.

## Fan-out

A news reaches tracking on one worker of one instance, and its followers may be connected to any other. Every worker subscribes to the Redis channel `deliveries`; the worker that takes a report keeps it (`delivery:<code>:last`) and publishes it there, and each worker pushes it to the followers of that code among its own connections ([ADR 0013](../../docs/adr/0013-swoole-for-fleet-tracking.md)).
