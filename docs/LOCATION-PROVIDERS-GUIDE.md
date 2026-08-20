# Location Providers — Integration Guide

TicketsCAD supports multiple location tracking technologies. Each provider has different connection methods, accuracy characteristics, and use cases.

---

## Provider Summary

| Provider | Connection Methods | Direction | Accuracy | Battery Impact | Use Case |
|----------|-------------------|-----------|----------|---------------|----------|
| **OwnTracks** | HTTP direct, MQTT | Phone → Server | High (GPS) | Medium | Personnel phone tracking |
| **Meshtastic** | MQTT, Serial USB, TCP/WiFi | Radio ↔ Server | Medium (GPS) | Low (mesh radio) | Off-grid areas, radio operators |
| **APRS** | aprs.fi API (poll), APRS-IS TCP | Radio → Server | Medium | None (radio) | Ham radio operators |
| **Internal GPS** | Browser Geolocation API | Browser → Server | High (GPS) | Medium | Mobile web interface |
| **DMR Radio** | BrandMeister MQTT/API | Radio → Server | Medium | None (radio) | DMR radio operators |
| **OpenGTS/Traccar** | HTTP (OsmAnd protocol) | Tracker → Server | High (GPS) | Low | Dedicated GPS trackers |

---

## 1. OwnTracks

### Two Connection Methods

#### HTTP Direct Mode (Recommended for small deployments)
The phone sends position updates directly to TicketsCAD's HTTP endpoint. No additional infrastructure needed.

```
Phone (OwnTracks App) ──HTTP POST──> TicketsCAD /api/location.php?provider=owntracks
```

**Setup:** See `docs/OWNTRACKS-SETUP.md` for detailed instructions.

**App settings:**
- Mode: HTTP
- URL: `http://YOUR-SERVER/newui/api/location.php?provider=owntracks`
- TrackerID: 2-character code matching the unit binding

#### MQTT Mode (Recommended for large deployments)
The phone publishes to an MQTT broker. A bridge service or the OwnTracks Recorder processes the data and forwards it to TicketsCAD.

```
Phone ──MQTT──> Mosquitto Broker ──> OwnTracks Recorder ──> TicketsCAD API
```

**Infrastructure needed:** Mosquitto MQTT broker + OwnTracks Recorder (Docker stack provided in `newui-dev/docker/docker-compose-owntracks.yml`).

**App settings:**
- Mode: MQTT
- Host: your server IP
- Port: 1883
- TrackerID: 2-character code

**Staleness threshold:** 120 seconds (2 minutes) — configurable in Settings > Tracking > Location Providers.

---

## 2. Meshtastic

### Three Connection Methods

#### MQTT Mode (Recommended for multi-node networks)
Meshtastic radios publish position and text messages to an MQTT broker. The TicketsCAD Meshtastic bridge service subscribes and forwards data.

```
Mesh Radios ──LoRa──> Gateway Node ──MQTT──> Broker ──> Bridge Service ──> TicketsCAD API
```

**Setup:**
1. Configure one Meshtastic node as an MQTT gateway (enable MQTT module, set broker address)
2. Enable JSON mode on the gateway for easier parsing
3. Install and run the bridge: `python services/meshtastic/bridge.py --mode mqtt --broker localhost`

**MQTT topic structure:** `msh/US/2/json/LongFast/!abcd1234`

#### Serial USB Mode (Single radio, simplest)
Direct USB connection to a Meshtastic radio plugged into the server.

```
Mesh Radios ──LoRa──> Connected Radio ──USB──> Bridge Service ──> TicketsCAD API
```

**Setup:**
1. Plug a Meshtastic radio into the server via USB
2. `python services/meshtastic/bridge.py --mode serial --port COM3`
3. The bridge receives all mesh packets through this radio

#### TCP/WiFi Mode (Network-connected node)
Connect to a WiFi-enabled Meshtastic node (ESP32-based) over the network.

```
Mesh Radios ──LoRa──> WiFi Node ──TCP:4403──> Bridge Service ──> TicketsCAD API
```

**Setup:**
1. Connect a WiFi-enabled Meshtastic node to your network
2. Note its IP address
3. `python services/meshtastic/bridge.py --mode tcp --host 192.168.1.100`

#### Bridge Service Installation

```bash
cd newui-dev/newui/services/meshtastic
pip install -r requirements.txt
cp bridge.ini.example bridge.ini
# Edit bridge.ini with your settings
python bridge.py --config bridge.ini
```

**Features:**
- Position tracking (lat/lng/altitude/speed/heading/battery)
- Two-way text messaging (mesh ↔ TicketsCAD chat)
- Health monitoring endpoint (HTTP on configurable port)
- Auto-reconnect on connection loss
- Protobuf and JSON MQTT message parsing

**Staleness threshold:** 300 seconds (5 minutes) — mesh radios report less frequently.

---

## 3. APRS

APRS position ingest is **inbound and position-only** today. There are **two**
connection methods, and **both are implemented** — they can run side by side (most
deployments do), and both write into the same `location_reports` table.

> APRS messaging and bidirectional APRS objects (publishing incidents as APRS objects,
> ingesting filtered APRS objects/stations) are **not** built yet — they are planned in
> `specs/aprs-future-dev.md` (inside the NewUI repo). This section covers only what
> exists: position ingest.

### Two Connection Methods

#### Method 1 — aprs.fi API Polling (`tools/aprs-poller.php`, cron)
A PHP CLI script polls the aprs.fi REST API every N minutes for the bound callsigns.

```
Ham Radios ──RF──> APRS-IS Network ──> aprs.fi ──REST API──> PHP Poller (cron) ──> location_reports
```

**Setup:**
1. Get a free API key from [aprs.fi/page/api](https://aprs.fi/page/api).
2. **Config → APRS Configuration** (`settings.php` panel `#panel-aprs-config`): paste
   the API key (`aprs_fi_api_key`), set the **poll interval** (`aprs_poll_interval`,
   default 5 min), tick **APRS polling active** (`aprs_enabled`), Save. Use the
   **Test Callsign** button to confirm the key works against a live beaconing callsign.
3. Add the cron line the panel generates: `*/5 * * * * php tools/aprs-poller.php`.
4. Bind unit callsigns in **Unit detail → Location Sources** (see "Callsign mapping").

**How it works:** the poller reads active APRS bindings, deduplicates and **batches
callsigns 20 at a time** (the aprs.fi request limit), sleeps 500 ms between batches,
parses each entry (lat/lng/altitude/speed/course/lasttime), writes a `location_reports`
row, and runs `geofence_check()` on each new position. It also purges reports older than
`location_retention_days` (default 90). Run it manually with `--dry-run` or `--verbose`.

**Limitations:** position is up to N minutes stale; aprs.fi free tier is rate-limited
(~60 calls/min). Read-only — the aprs.fi API cannot transmit.

#### Method 2 — APRS-IS Persistent TCP Listener (`services/aprs-is/listener.py`, systemd)
A long-lived Python service that opens a TCP socket to an APRS-IS tier-2 server and
parses every beacon within seconds of receiving it. **This is implemented**, not a
future enhancement — see `docs/APRS-LISTENER-SETUP.md` for the full install.

```
Ham Radios ──RF──> APRS-IS Network ──TCP socket──> Python Listener (systemd) ──HTTP──> TicketsCAD API
```

**Setup (summary; full steps in `docs/APRS-LISTENER-SETUP.md`):**
1. `pip install aprslib requests` in a venv.
2. Create `listener.ini`: APRS-IS `server`/`port` (e.g. `noam.aprs2.net:14580`),
   `callsign`, `passcode` (`-1` for receive-only), and a **server-side filter**
   (e.g. `r/44.97/-93.27/50` for a 50 km radius, or `b/W0AM/KC0GHQ-9` for a buddy list —
   see [javAPRSFilter](http://www.aprs-is.net/javAPRSFilter.aspx)).
3. Install the systemd unit (`services/aprs-is/aprs-is.service.example`) and
   `systemctl enable --now`.

**How it works:** auto-reconnects with exponential backoff, reports listener health to
`/api/service-uptime.php` every 60 s, parses positions with `aprslib`, and POSTs each to
`/api/location.php`. Filter width drives CPU — narrow filters (buddy lists) are cheaper
than wide-area radius filters.

> **Known wiring gap (2026-06-26):** the listener POSTs `action=report`, which the API
> currently gates behind a **CSRF token + dispatcher/admin RBAC** — a headless daemon
> has neither. The setup doc references an `api_token`, but `action=report` does not
> accept a bearer token yet. Until a service-account / API-key auth path lands
> (Phase 7b in `specs/future-phases.md`; also tracked in `specs/aprs-future-dev.md`
> Slice 1), the **aprs.fi poller is the fully-working end-to-end path** and the listener
> needs that auth slice to write positions. The poller and listener otherwise coexist
> cleanly (both target the same table; dedup happens in the ingest layer).

### Callsign mapping (both methods)

APRS attribution uses **`unit_location_bindings`**, NOT the `member_comm_identifiers`
comm-mode system that Zello/Meshtastic use (`inc/comm_resolve.php` deliberately does not
map APRS). In **Unit detail → Location Sources → Add binding**, pick the **APRS**
provider, enter the unit's **callsign-SSID** (e.g. `KC0GHQ-9`), and set a priority. This
calls `POST /api/location.php action=bind`. A callsign with no binding still lands in
`location_reports` but won't be attributed to a responder on the map.

### Monitoring positions

- **Map overlay:** `assets/js/unit-tracking.js` polls `GET /api/location.php?all_units=1`
  (~10 s refresh). That endpoint returns the freshest position per bound unit with
  staleness filtering applied — only reports within the provider's `max_age_seconds` are
  "fresh"; older ones fall through to lower-priority providers.
- **System Health → Location Providers card:** the APRS provider shows a green dot with a
  recent timestamp once positions are flowing (the listener also reports health there).
- **Direct check:**
  ```sql
  SELECT received_at, unit_identifier, lat, lng
    FROM location_reports
    JOIN location_providers ON location_providers.id = location_reports.provider_id
   WHERE location_providers.code = 'aprs'
   ORDER BY received_at DESC LIMIT 10;
  ```

**Staleness threshold:** 600 seconds (10 minutes) by default — APRS beacons are
typically sent every 2–10 minutes, so markers grey out / drop after a quiet period.

---

## 4. Internal GPS (Browser)

### One Connection Method

#### Browser Geolocation API
The mobile web interface uses the browser's built-in GPS to report the user's position.

```
Phone Browser ──Geolocation API──> JavaScript ──fetch──> TicketsCAD API
```

**Setup:** Automatic — enabled by default on the mobile interface (`mobile.php`). GPS auto-starts on login.

**How it works:**
1. `navigator.geolocation.watchPosition()` tracks the phone
2. Every 30 seconds, position is POST'd to `api/mobile-data.php` (legacy) and `api/location.php` (new)
3. Requires HTTPS or localhost for Geolocation API access

**Staleness threshold:** 60 seconds (1 minute) — frequent updates from browser.

---

## 5. DMR Radio GPS

### Connection via BrandMeister

DMR radios with GPS send position data through the DMR network to BrandMeister, which forwards it to APRS.fi. TicketsCAD can pick this up via the APRS poller.

```
DMR Radio ──RF──> Repeater/Hotspot ──> BrandMeister ──> APRS.fi ──> APRS Poller ──> TicketsCAD
```

**Setup:**
1. Enable GPS on your DMR radio
2. Configure BrandMeister GPS gateway for your DMR ID
3. The APRS poller picks up DMR positions automatically (they appear on aprs.fi)
4. Bind the APRS callsign to the unit in Location Sources

**Alternative: BrandMeister MQTT**
BrandMeister publishes GPS data to its own MQTT server. A future bridge service could subscribe directly.

**Staleness threshold:** 900 seconds (15 minutes) — DMR GPS updates are infrequent.

---

## 6. OpenGTS / Traccar

### HTTP OsmAnd Protocol

Dedicated GPS trackers and the Traccar Client app use the OsmAnd HTTP protocol to report positions.

```
GPS Tracker ──HTTP──> TicketsCAD /api/location.php?provider=opengts (port 5055)
```

**Setup:**
1. Configure your GPS tracker or Traccar Client app to report to your server
2. Protocol: OsmAnd HTTP
3. URL: `http://YOUR-SERVER:5055` or `http://YOUR-SERVER/newui/api/location.php?provider=opengts`
4. Device ID: matching identifier in unit Location Sources

**Supported devices:** Any tracker compatible with Traccar/OsmAnd protocol (hundreds of models).

**Staleness threshold:** 600 seconds (10 minutes).

---

## Priority Resolution

When multiple providers are active for the same unit, TicketsCAD uses the **unified priority list** configured on each unit's edit page:

1. Check highest-priority (lowest number) provider
2. If data is within the provider's staleness threshold → use it
3. If stale → check next provider
4. Continue until fresh data is found
5. If all stale → use the most recent stale data (with "stale" indicator)

Typical priority order:
```
10-50    Unit's own hardware (vehicle APRS, GPS tracker)
60       Internal GPS (browser on unit tablet)
100-150  Assigned personnel providers (phone OwnTracks, handheld radio)
999      Manual update (admin-set lat/lng)
```

---

## Health Monitoring

All provider services report their status to the System Health page:

| Service | Health Check Method |
|---------|-------------------|
| APRS Poller | Cron job success/failure logged |
| Meshtastic Bridge | HTTP health endpoint (configurable port) |
| OwnTracks (HTTP) | Inline — reports arrive directly to API |
| OwnTracks (MQTT) | Docker container health check |
| Internal GPS | Browser-side — no server health check needed |

The System Health page shows each provider's status, last report time, and error count.
