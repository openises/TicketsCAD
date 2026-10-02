# Audio-Matrix Service — Setup Guide

This is the install/admin guide for `services/audio-matrix/service.py` — the
Python daemon behind the Communications Console's real per-channel audio
(Phase 114c, extended by Phase 152's "Matrix Audio" / "Join Intercom"
checkboxes, workstation mute, and physical PTT). Without this service
running, the console UI still loads and every strip still shows, but the
**Matrix Audio** / **Join Intercom** checkboxes will fail to connect —
there's nothing listening for them to connect to.

## What it is, and what it is not

One process, one control plane, per-channel routing (`comm_channels` /
`comm_routes`), plus two optional *legs* that attach real transport to a
channel:

- **The browser leg** — a WebSocket server a console tab connects to when an
  operator checks "Matrix Audio"/"Join Intercom". This is what Phase 152's
  new capabilities actually need. Per-operator, opt-in, requires a real
  `getUserMedia` permission prompt and `screen.console` RBAC — it never runs
  without an explicit click.
- **The DMR leg** — bridges a channel to the **live amateur-radio DMR
  bridge**. This is a separate, more consequential decision (real RF
  transmission) that this guide's installer does **not** enable by default,
  and this doc does not walk you through flipping it — see
  `specs/phase-114-audio-matrix/changes.md` decision 5 for that cutover's
  own safety framing. Nothing here requires it.
- **The dispatcher intercom** (`intercom_dd:main`) needs neither leg — it
  carries audio via an internal reflector, so it works the moment the
  service is running with the browser leg live.

## Configuration reference

`/etc/ticketscad-audio-matrix.conf` (JSON, mode `0600`, owned by
`www-data` — the same shape and location convention as
`/etc/ticketscad-aprs.conf`):

```json
{
  "db_host": "localhost", "db_user": "newui", "db_pass": "...", "db_name": "newui",
  "control_port": 18092,
  "control_token": "<random secret — install.sh generates this>",
  "dmr": { "mode": "off", "bridge_url": "http://127.0.0.1:18091", "channel_key": "dmr_bm:3127" },
  "browser": { "mode": "live", "host": "0.0.0.0", "port": 18093 },
  "ffmpeg_path": "ffmpeg",
  "php_base_url": "https://your-install.example.com"
}
```

- `control_port` — the loopback-only HTTP control plane PHP talks to
  (`inc/matrix-control-client.php`). Never exposed beyond `127.0.0.1`.
- `control_token` — the shared secret between this file and the
  `matrix_control_token` row in the `settings` table. `install.sh`
  generates one and syncs both sides in the same pass; if you ever
  hand-edit either side, update the other to match or every route/
  workstation-mute mutation from the PHP admin UI will fail with a 401.
- `dmr.mode` — `"off"` (default) keeps the DMR channel present in the
  matrix (so routes validate and it shows on the console) with no live
  bridge contact at all. `"live"` is the RF cutover — a separate decision.
- `browser.mode` — `"live"` is what this guide's installer sets. `"off"`
  means literally nothing listens on `browser.port`; every "Matrix Audio"/
  "Join Intercom" checkbox on every console will fail to connect.
- `php_base_url` — optional. Without it, a channel join / TX start-stop is
  visible only in the joining operator's own tab. With it set to the
  install's real URL, the SAME state fans out over SSE to every other
  `screen.console` viewer (Phase 152 prerequisite #7).
- `ffmpeg_path` — optional, defaults to `"ffmpeg"` (resolved via `PATH`).
  Only used by public stream channels — see below. Set to a full path if
  `ffmpeg` isn't on the service's `PATH`.

## Public audio stream channels (Broadcastify, LiveATC, NOAA Weather Radio, Icecast, …)

A full, first-class channel type — not a CLI/config-file tool — for
patching a public HTTP audio stream into the console like any other
channel: Sel/Mon/Mute/Volume, routed through the patch rail, listened to
alongside Zello and DMR traffic. Useful both for testing the console
without needing live radio traffic on hand, and for genuinely monitoring
a public feed an agency cares about (a NOAA Weather Radio station, for
example).

Managed entirely from **Settings → Communications & Integrations →
Stream Channels** (`stream-channels-admin.php`, `action.manage_matrix`).
Creating, editing, or deleting a stream there applies to the **running**
matrix service **immediately** — no restart needed — the same live-apply
discipline the Patch Matrix page already uses for routes.

1. Install `ffmpeg` on the audio-matrix host (it decodes whatever codec
   the stream serves — MP3, AAC, OGG — and resamples to the matrix's own
   8 kHz mono; the one thing this feature needs beyond the base install):
   ```bash
   sudo apt-get install -y ffmpeg
   ```
2. Open **Settings → Stream Channels → New Stream**. You'll need a
   **direct, playable stream URL** — not a "listen" web page. Aggregator
   sites (Broadcastify, TuneIn, mytuner-radio, …) usually don't expose
   this on the page you'd normally visit:
   - **Broadcastify**: free feeds' static MP3 URL
     (`https://audio.broadcastify.com/<feed-id>.mp3`) requires a
     **Premium** subscription — a free/anonymous request gets 401.
   - **TuneIn**: resolve `https://opml.radiotime.com/Tune.ashx?id=<station-id>`
     (the station id is the `s########` in the station's TuneIn URL) — it
     returns a one-line `.m3u` playlist pointing at the real stream.
   - Some aggregators (mytuner-radio.com and similar) encrypt the stream
     URL client-side and don't expose a usable link at all — skip these.
3. Fill in a **Channel Key** (short, unique, e.g. `noaa_wx_msp` —
   immutable once saved), **Label**, the **Stream URL**, and leave
   **Regulatory Class** at its default `internal` (a public feed carries
   no TicketsCAD-side transmit obligation). Save.
4. The channel is live immediately. It appears on the **Patch Matrix**
   page ready to route to any Console strip, and in Console Designer like
   any other channel. It's one-way (listen-only) — there's nothing to
   transmit back to a public stream.

Delete a stream the same way, from its edit modal — this stops it and
removes any patches routed through it immediately.

If the matrix service is unreachable when you save, the change is
refused outright (not silently queued) so the admin UI can never disagree
with what's actually running.

## Install (Debian/Ubuntu, from the deployed webroot)

```bash
sudo bash /var/www/newui/services/audio-matrix/install.sh --base-url https://your-install.example.com
```

What it does, in order (idempotent — safe to re-run):

1. Installs `websockets` (prefers the `python3-websockets` Debian package;
   falls back to `pip3 install --break-system-packages websockets` if that
   package isn't available on your distro) and verifies
   `mysql-connector-python` is present, installing it the same way if not.
2. Generates `/etc/ticketscad-audio-matrix.conf` from the install's own
   `config.php` DB credentials plus a freshly generated `control_token`
   (`openssl rand -hex 32`) — **skipped if the file already exists**, so
   re-running never rotates a token a running install is already using.
3. Installs `services/audio-matrix/ticketscad-audio-matrix.service` into
   `/etc/systemd/system/`, `daemon-reload`, `enable`, `restart`.
4. Runs `services/audio-matrix/configure-php-settings.php` against the
   current conf file to sync `matrix_control_url`/`matrix_control_token`
   into the `settings` table — **this step always runs**, even on a
   re-install over an existing conf, so the PHP side and the Python side
   can never silently drift out of sync.
5. Curls the control plane's own `/health` endpoint (no auth required for
   a GET) to confirm the service actually started and is answering.

Like the APRS listener, `tools/deploy.sh` does **not** run this installer
automatically — a routine deploy updates the committed `service.py` on disk
but doesn't restart the running daemon. Re-run `install.sh` (or just
`sudo systemctl restart ticketscad-audio-matrix`) after any deploy that
touches `services/audio-matrix/`.

## Expose the browser leg (required for "Matrix Audio"/"Join Intercom" to work)

**The four steps above bring the SERVICE up, but a browser still cannot
reach it without this step.** Found missing from this guide entirely
during a persona review (2026-09-08) — an admin who followed the guide
exactly, top to bottom, would have a service reporting healthy on every
`systemctl status`/`/health` check while the console's own "Matrix
Audio"/"Join Intercom" checkboxes silently un-check themselves with zero
explanation on every attempt.

Why: the control plane (`127.0.0.1:18092`, what `install.sh` already wires
up) is PHP-to-Python, loopback-only, and never touches a browser. The
BROWSER's own WebSocket connection goes to a *different* port — the
browser leg, `0.0.0.0:18093` by default — and unlike the control plane,
this one genuinely can't be auto-configured: it needs a real `wss://`
path through your web server, matching the same pattern this project
already uses for the DMR radio widget and Zello (`docs/ZELLO-PROXY-
LESSONS.md`), because a raw `ws://host:18093` is both unencrypted and
blocked outright as mixed content by any browser on an HTTPS install.

1. **Reverse-proxy the port.** `apache/newui.conf.example` has a ready
   `/matrix-ws` block, commented out by default — uncomment it (Apache
   needs `mod_proxy_wstunnel` enabled, same as the DMR/Zello blocks right
   above it in that file). nginx: use the same `proxy_pass`/`Upgrade`
   header pattern as your existing `/dmr-ws`/`/zello-ws` locations,
   pointed at `127.0.0.1:18093`.
2. **Save the resulting URL.** The setting is `matrix_ws_url` (read by
   `api/console-session.php`), and — same reasoning as `matrix_control_
   token` above — it has to come from you, not be guessed. Two ways to
   set it:
   - Add `"browser_public_ws_url": "wss://your-install.example.com/matrix-ws"`
     to `/etc/ticketscad-audio-matrix.conf`, then re-run
     `php services/audio-matrix/configure-php-settings.php /etc/ticketscad-audio-matrix.conf`
     (safe to re-run any time; it only ever updates these two-or-three
     specific settings rows).
   - Or set it directly: `UPDATE settings SET value = 'wss://your-install.example.com/matrix-ws' WHERE name = 'matrix_ws_url'`
     (insert the row if it doesn't exist yet).
3. **Confirm it took.** `curl -s https://your-install.example.com/api/console-session.php` won't
   tell you much unauthenticated — easier to just open the Communications
   Console as a logged-in operator and check "Matrix Audio" on any strip;
   see the verification steps below for what success and failure each
   look like.

An install that skips this section is not broken — `browser.mode`
being `"live"` with `matrix_ws_url` unset is exactly the "off/undeployed
by default" state every prior phase of this project has shipped in.
This step is what turns it on for real.

## Verifying it's actually working

```bash
sudo systemctl status ticketscad-audio-matrix
sudo journalctl -fu ticketscad-audio-matrix        # watch live
curl http://127.0.0.1:18092/health                  # {"running":true,"ticks":...,"channels":N,"routes":N}
curl http://127.0.0.1:18092/channels                # confirm your comm_channels rows loaded
```

Then in the browser: open the Communications Console (`console.php`), check
"Matrix Audio" on any DMR strip (or "Join Intercom" on the dispatcher
intercom strip) — a real `getUserMedia` permission prompt should appear, and
a held-down PTT control should replace the launcher button once you allow
it. A red disconnect banner means the browser leg isn't reachable — re-check
`browser.mode` is `"live"` and the service is actually running.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| "Matrix Audio" checkbox never connects, un-checks itself with no message | `matrix_ws_url` isn't set — see "Expose the browser leg" above; this is the DEFAULT state on any install that hasn't done that step yet, not a malfunction |
| "Matrix Audio" checkbox never connects | `browser.mode` is `"off"`, or the service isn't running at all — check `systemctl status` |
| Route/workstation-mute changes in the admin UI silently fail | `matrix_control_token` in the `settings` table doesn't match `control_token` in the conf file — re-run `configure-php-settings.php <conf-path>` |
| `/health` doesn't respond | Service crashed at boot — check `journalctl`; the most common cause is a missing/unreadable `/etc/ticketscad-audio-matrix.conf` or a DB the config can't reach |
| DMR "Matrix Audio" connects but carries no real radio audio | Expected while `dmr.mode` is `"off"` — that toggle is the separate live-RF cutover, not part of this setup |
| Dispatcher intercom carries no audio | Confirm the service actually restarted after the Phase 152 `intercom_dd` reflector-leg fix (`services/audio-matrix/legs/reflector.py`) — a service still running the pre-fix code has no leg on that channel at all |

## See also

- `docs/COMMS-CONSOLE-GUIDE.md` — the operator-facing feature guide this
  service powers.
- `specs/phase-114-audio-matrix/changes.md` — the matrix core's own design
  decisions (single-hop routing, the DMR-leg safety framing, topology).
- `specs/phase-152-comms-console-v2/tasks.md` — the full build record,
  including this deployment's own live-verification log.
