# APRS-IS Persistent Listener — Setup Guide

This is the **low-latency** path for ingesting APRS positions (and addressed
APRS messages) into TicketsCAD. Compared to the aprs.fi polling cron job
(which lags up to 5 minutes), the persistent listener pushes packets through
within seconds of receiving them from the APRS network.

Both methods can run side-by-side — most deployments do.

> **History note:** an earlier listener at `services/aprs-is/listener.py`
> (2026-06-13) was retired 2026-08-21 (SPEC-STATUS.md B18) — it POSTed to
> `/api/location.php?action=report` with only a CSRF token, and that endpoint
> requires an authenticated session **and** dispatcher/admin RBAC, so the
> daemon could never actually authenticate. It never ran anywhere. The
> listener documented here, `services/aprs/aprs_listener.py` (2026-07-08), is
> the maintained implementation — it writes directly to the database via
> `mysql.connector`, so it has no HTTP auth gap.

## What it is

A Python service (`services/aprs/aprs_listener.py`) that opens a long-lived
TCP socket to an APRS-IS server (default `rotate.aprs2.net:14580`), logs in
with the install's own licensed callsign + passcode, subscribes to a
server-side filter, and parses every packet that arrives. It writes:

- **Position packets** → the `location_reports` table, under the `aprs`
  `location_providers` row.
- **Addressed messages** to the station's own callsign → `chat_messages`
  (channel `aprs`), with an automatic ack reply.

It reconnects with exponential backoff on disconnect, and runs as a systemd
service under `www-data`.

## Configure the station identity (web UI)

Before installing the service, configure the station's APRS-IS identity in
**Config → APRS Configuration → Station Radio (send + receive)**
(`settings.php#panel-aprs-config`):

1. Read and accept the FCC Part 97 license attestation gate. APRS-IS is a
   licensed amateur radio service — only a licensed operator may transmit
   under a callsign, and this listener logs in with a real passcode (not
   receive-only `-1`) so it can also ack inbound messages.
2. Enter the station **callsign** (with SSID, e.g. `N0NE-10`) and click
   **Compute passcode** — the passcode is derived from the callsign by the
   public APRS-IS algorithm; there's no separate password to remember or
   lose.
3. **APRS-IS Server** / **Port** default to `rotate.aprs2.net:14580` (an
   anycast address that auto-selects the nearest tier-2 server) — leave them
   unless you have a reason to pin a specific server.
4. Save.

These four settings (`aprs_send_callsign`, `aprs_send_passcode`,
`aprs_is_server`, `aprs_is_port`) are shared with the outbound send path
(`inc/channels/aprs.php`) — one station identity drives both directions.

The **Receive Filter** field on the same tab is present but currently
**disabled in the UI** (a separate, already-tracked gap — GH #91): the
listener does read the `aprs_recv_filter` setting on each connect
(`services/aprs/aprs_listener.py`), but nothing in the web UI can write it
today, so every install effectively runs the default filter,
`r/45.0/-93.0/200` (200 km around the Twin Cities) — change it directly in
the `settings` table if you need a different area until that gap closes.

## Install the service (Debian/Ubuntu)

The service ships its own installer, `services/aprs/install.sh` — it
generates the DB-credentials file, installs Python deps, and starts the
systemd unit in one idempotent pass:

```bash
sudo bash /var/www/newui/services/aprs/install.sh
```

What it does:

1. `apt-get install python3-pip` + `pip3 install aprslib mysql-connector-python`.
2. Generates `/etc/ticketscad-aprs.conf` (JSON: `db_host`/`db_user`/`db_pass`/`db_name`)
   from the install's own `config.php`, `0600` owned by `www-data`. This is
   the **only** file you configure by hand, and only if you want to point
   the listener at a non-default DB — normally the generated file is
   correct as-is. It never overwrites an existing file, so re-running the
   installer is safe.
3. Copies `services/aprs/ticketscad-aprs-listener.service` into
   `/etc/systemd/system/`, `daemon-reload`, `enable`, `restart`.
4. Prints the first 20 lines of the journal so you can confirm it started.

Re-running the installer is safe (idempotent) — it's also run automatically
by `tools/deploy.sh` as part of a normal deploy, so a routine update does not
require re-running it by hand.

## Verifying ingest

- **Settings → APRS Configuration → Listener Status** shows the live
  listener state, station count, and time since the last packet (from
  `api/aprs-positions.php`).
- **APRS Map** (`aprs-map.php`) shows received stations on the map in real
  time.
- Watch the raw feed on the server:

  ```bash
  sudo journalctl -fu ticketscad-aprs-listener.service
  ```

- Or query directly:

  ```sql
  SELECT received_at, unit_identifier, lat, lng
    FROM location_reports
    JOIN location_providers ON location_providers.id = location_reports.provider_id
   WHERE location_providers.code = 'aprs'
   ORDER BY received_at DESC LIMIT 10;
  ```

## Filter syntax cheat-sheet

| Filter | Effect |
|---|---|
| `r/44.97/-93.27/50` | Receive everything within 50 km of the point |
| `b/W0AM/KC0GHQ-9` | Buddy list — only these callsigns |
| `p/KC0/W0/N0` | Any callsign starting with these prefixes |
| `t/p` | Position packets only (no weather / messages / etc.) |
| Combinations: `t/p r/44.97/-93.27/50` | AND of all filters |

Full reference: <http://www.aprs-is.net/javAPRSFilter.aspx>

## Troubleshooting

- **"APRS-IS callsign not configured" in the journal** — accept the license
  gate and set a callsign in Settings → APRS Configuration, then
  `sudo systemctl restart ticketscad-aprs-listener.service`.
- **"APRS-IS passcode is 0"** — click **Compute passcode** after entering the
  callsign, or set `aprs_send_passcode` to `-1` directly in the `settings`
  table for receive-only mode (no ack capability).
- **"connection refused"** — try a different tier-2 server
  (`noam.aprs2.net`, `euro.aprs2.net`, `asia.aprs2.net`) in
  `aprs_is_server`.
- **"no packets after connect"** — the filter probably excludes everything
  in your area; check `aprs_recv_filter` in the `settings` table.
- **High CPU** — narrow the filter from a wide-area `r/` to a buddy list
  `b/`. The listener parses every packet that matches the server-side
  filter; narrower filters mean less work.

## Combining with aprs.fi polling

Run both. The polling cron job (`tools/aprs-poller.php`) catches anything
the listener missed during reconnect windows, and the listener gives you
near-real-time on the happy path. Both write into the same
`location_reports` table — duplicate detection happens in the ingest layer.
