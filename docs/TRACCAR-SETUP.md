# Traccar / OpenGTS Setup

This guide gets you from "I have a device that emits GPS" to "I see that device on the TicketsCAD dispatch map." It covers four common scenarios — pick the one that matches your fleet.

If you're new to TicketsCAD itself, finish [INSTALL.md](INSTALL.md) first.

---

## Pick your path

| You have... | Use |
|---|---|
| **Smartphones, no central server** | [§1 — OwnTracks app direct](#1-owntracks-app-direct) |
| **Smartphones running Traccar Client app** | [§2 — Traccar Client direct to TicketsCAD](#2-traccar-client-direct) |
| **A Traccar Server you already run** (with mixed hardware) | [§3 — Traccar Server forwarder](#3-traccar-server-forwarder) |
| **Hardware GPS modems speaking legacy OpenGTS** | [§4 — OpenGTS direct](#4-opengts-direct) |

All four paths land in the same `location_reports` table; the dispatch map, breadcrumbs, geofences, and stale-position dimming treat them all the same.

---

## Prerequisites — once per TicketsCAD install

You'll do these whichever path you pick.

### Enable the right provider(s)

1. Sign in as Super Admin.
2. **Config → Location Providers**.
3. Find the row that matches your path (`OwnTracks`, `Traccar`, or `OpenGTS`) and flip **Enabled** on. Save.

### Decide on authentication

Open **Config → Location Ingest (Traccar / OpenGTS)** in the admin UI. The panel has four sections:

1. **Authentication** — toggle "Require token on every report", set the legacy shared secret (or click Generate for a random one), tune the rate limit, and toggle the Null Island guard.
2. **Per-device tokens** — mint one token per device (recommended over a single shared secret). One leaked token revokes one device, not the entire fleet.
3. **Recent reports** — live view of the last 50 reports across all providers. Use this to verify a freshly-onboarded device is reaching TicketsCAD without dropping to a shell.
4. **Mint Token modal** — generates a token, shows the raw value ONCE — copy it immediately into the device's configuration. Optional scoping: pin to one provider, or bind to one device unique-id so a leaked token can't be used elsewhere.

The underlying settings (for reference, or for older installs without the panel):

| Setting | Value | Effect |
|---|---|---|
| `location_ingest_require_token` | `0` (default) | Anonymous — anybody who knows your URL can post. OK on private LAN or behind Cloudflare with IP allowlist. |
| `location_ingest_require_token` | `1` | All ingest must include `?token=<value>` matching either a per-device token (preferred) OR the legacy shared secret. |
| `location_ingest_secret` | a long random string | The legacy single shared secret. Per-device tokens take precedence. |
| `location_ingest_allow_null_island` | `0` (default) | Drop reports at exactly `(0,0)` — almost always means a broken device. |
| `location_ingest_allow_null_island` | `1` | Accept `(0,0)` (rare; for evaluations or operations on the Equator at the prime meridian). |
| `location_ingest_rate_limit_per_min` | `600` (default) | Per-IP rate limit. Misconfigured devices flooding the endpoint hit this cap. |

Recommendation: leave anonymous during initial bring-up, switch to per-device tokens once you've confirmed traffic is flowing. The legacy shared secret stays for backwards-compatibility but per-device is the supported long-term path.

### Bind devices to personnel/units

A position arriving at TicketsCAD carries a device identifier (OwnTracks TID, Traccar uniqueId, etc.) but TicketsCAD doesn't know which dispatcher-visible person/unit that's supposed to be. You bind it via the **Roster → Personnel → Comm Identifiers** UI:

1. Open the personnel record for the person whose device this is.
2. **Comm Identifiers** → **Add**.
3. Pick the matching type (OwnTracks, Traccar, or OpenGTS/GPRMC).
4. Fill the binding key (TID for OwnTracks, uniqueId/IMEI for Traccar, device ID for OpenGTS).
5. Save.

The same person can have multiple identifiers — you might have an OwnTracks TID for a phone AND a Traccar uniqueId for an in-vehicle modem; whichever reports last wins for the live map.

---

## 1. OwnTracks app direct

**Best for:** ham clubs, small teams, anyone whose tracked devices are all smartphones and who doesn't want to run a middle-tier server.

**On each phone, once:**

1. Install **OwnTracks** from the App Store / Play Store.
2. Open the app → Settings → Connection.
3. **Mode:** HTTP
4. **URL:** `https://<your-tickets-host>/api/location.php?provider=owntracks` (use exactly this — the legacy `?action=report&provider_code=...` URL that the Settings panel previously showed was a long-standing typo, fixed 2026-06-22)
5. **Authentication:** depends on which mode you set in the Prerequisites:
   - Anonymous → leave Username/Password blank
   - Token → put your shared secret in Password; Username can be anything
6. **Tracker ID (TID):** 2 characters; this MUST match what you put in the personnel binding (step 4 of Prerequisites). Convention: initials.
7. Grant the app **Always** location permission.
8. Reporting mode: **Significant changes** is power-efficient; **Move** is more frequent.

Within ~60 seconds of walking with the phone, a position pin appears on the TicketsCAD dispatch map.

---

## 2. Traccar Client direct

**Best for:** smartphones, but with a richer feature set than OwnTracks (battery info, custom intervals, etc.).

**On each phone, once:**

1. Install **Traccar Client** from the App Store / Play Store.
2. **Server URL:** `https://<your-tickets-host>/api/location.php?provider=traccar` (note `?provider=traccar`, not `?osmand=...` which is the Traccar Server default).
3. **Device identifier:** Traccar Client auto-generates a per-install ID. Copy it from the app's main screen — you'll paste it into the personnel binding.
4. Enable the service. The app reports position on movement.

The first position arrives → goes into `location_reports` → you'll see it under **Config → Location Ingest → Recent reports** within a few seconds.

---

## 3. Traccar Server forwarder

**Best for:** you already run Traccar Server with a mixed fleet of hardware GPS modems, and you want TicketsCAD to mirror what your operators see in Traccar without re-pointing every device.

**On the Traccar Server, once:**

> **Corrected 2026-07-08** (beta report): an earlier version of this
> section — and the video built from it — described a "Server →
> Forwarding" screen. Stock self-hosted Traccar has NO forwarding UI:
> position forwarding is configured in the **configuration file**.

1. On the Traccar host, edit `conf/traccar.xml` (Linux packages install
   under `/opt/traccar/conf/traccar.xml`).
2. Add these entries inside the `<properties>` block:

   ```xml
   <entry key='forward.enable'>true</entry>
   <entry key='forward.type'>json</entry>
   <entry key='forward.url'>https://<your-tickets-host>/api/location.php?provider=traccar</entry>
   ```

   - If you set `location_ingest_require_token=1` in TicketsCAD, either
     append `&amp;token=<your-secret>` to the URL (note the XML-escaped
     ampersand) or add:
     `<entry key='forward.header'>Authorization: Bearer <your-secret></entry>`
   - Older Traccar 5.x releases used `<entry key='forward.json'>true</entry>`
     instead of `forward.type` — if `forward.type` is ignored on your
     version, use that.
3. Restart the Traccar service (`sudo systemctl restart traccar`).
   Config-file changes do not apply live.
4. Traccar starts pushing positions for every device in its database to
   that URL. Optional reliability knobs: `forward.retry.enable`,
   `forward.retry.count`, `forward.retry.limit` — see
   https://www.traccar.org/forward/ for the current reference.

**Important — uniqueId mapping.** Traccar identifies devices by `device.uniqueId` (usually the IMEI). TicketsCAD's personnel binding uses the same value. Make sure each TicketsCAD personnel record's Traccar comm-identifier matches the IMEI Traccar Server has for that device. If you change a device's IMEI in Traccar (e.g. swap a SIM card), update the personnel record too — otherwise positions land in `location_reports` but don't bind to any responder.

**About the OsmAnd format.** Traccar Server's default forwarder type is "OsmAnd" — it sends positions as URL query parameters, NOT JSON. Use TicketsCAD's `?provider=opengts` endpoint for that (see §4) — it understands the OsmAnd parameter shape. The JSON forwarder (`?provider=traccar`) gives richer fields (device.uniqueId, device.name, position.accuracy, etc.) and is the recommended path when both ends are under your control.

---

## 4. OpenGTS direct

**Best for:** older hardware GPS modems that speak the OpenGTS GPRMC-over-HTTP protocol natively. Many vehicle trackers, asset trackers, and consumer GPS sport devices use this format.

**On each modem, configure:**

- **Server / URL:** `https://<your-tickets-host>/api/location.php?provider=opengts`
- **Format:** GPRMC HTTP (query string)
- **Device ID parameter:** the modem's IMEI typically goes in `id=` (some vendors use `deviceid=` or `imei=` — all three are accepted).

The minimum useful URL the device will send is:
```
POST /api/location.php?provider=opengts&id=<IMEI>&lat=<LAT>&lon=<LON>&speed=<KPH>&heading=<DEG>&timestamp=<EPOCH>
```

TicketsCAD also accepts `latitude`/`longitude`/`lng`, `spd`, `bearing`/`cog`, `time`/`tst`, and the optional `altitude`/`alt`, `accuracy`/`hdop`. If your modem uses a non-standard name, point me at its docs and I'll add the alias.

---

## Verifying it works

After your first device starts reporting:

```bash
# On the TicketsCAD host:
sudo mysql newui -e "
  SELECT lp.code, lr.unit_identifier, lr.lat, lr.lng, lr.speed,
         lr.heading, lr.reported_at, lr.raw_data
    FROM location_reports lr
    JOIN location_providers lp ON lp.id = lr.provider_id
   WHERE lr.reported_at > NOW() - INTERVAL 10 MINUTE
   ORDER BY lr.id DESC LIMIT 10;
"
```

You should see one row per report. If you don't:

| What you see | Likely cause | Fix |
|---|---|---|
| Empty result | Device isn't reaching TicketsCAD | Try `curl -X POST 'https://<host>/api/location.php?provider=traccar' -H 'Content-Type: application/json' -d '{"device":{"uniqueId":"TEST"},"position":{"latitude":44.97,"longitude":-93.26}}'` from the device's network — confirm you get `{"ok":true,...}` back. If not, network or TLS issue. |
| Row exists, lat/lng populated, but the dispatch map shows nothing | `unit_identifier` doesn't match any personnel's comm-identifier binding | Open the personnel record, add a comm-identifier with the exact value from `unit_identifier` |
| 401 response from the endpoint | `location_ingest_require_token=1` is set but the device isn't sending the right token | Either set the token correctly on the device, or temporarily set `location_ingest_require_token=0` while you bring things up |
| 429 response from the endpoint | Rate limit hit (600 req/60s/IP default) | The device is configured too chatty, or you're behind a single NAT with many devices — talk to me about raising the limit |

---

## Security notes

- **Anonymous mode is fine for evaluation, not for production.** Anyone who guesses your URL can post positions and trigger geofence alerts. Turn on `location_ingest_require_token=1` once you're past the bring-up phase.
- **All four paths assume HTTPS.** Plain HTTP is acceptable inside a private LAN but anything reaching the device over the internet should be TLS-protected — phones and GPS modems both support HTTPS today.
- **Personnel comm-identifiers are visible to anyone who can read the Roster page.** If your IMEIs are sensitive (some agencies treat asset IDs as restricted), gate the Roster page via RBAC and audit access.
- **Position history is retained indefinitely by default.** Use the maintenance runbook to set a retention window matching your records policy.

---

## What's NOT supported (yet)

- **Traccar's binary protocols** (raw TCP from the GPS modem direct to Traccar Server) — those land at Traccar Server, get translated, and forwarded to TicketsCAD via §3. We don't implement the binary parsers because Traccar Server already does it better.
- **OwnTracks MQTT mode** — the comm-identifier defines a `mqtt_topic` field but no MQTT subscriber is running. Use HTTP-direct (§1) for now.

---

## Related docs

- [INSTALL.md](INSTALL.md) — base TicketsCAD install
- [RADIO-DMR-INSTALL.md](RADIO-DMR-INSTALL.md) — DMR radio feature (different stack; same `location_reports` table — DMR radio GPS shows up alongside Traccar)
- BACKLOG.md § 5.4 — current status of all location-provider work
