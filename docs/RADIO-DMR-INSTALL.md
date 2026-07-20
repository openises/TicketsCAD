# DMR Radio — End-to-End Install

This guide takes you from "no radio anywhere" to "the radio widget in the dispatch UI connects to a BrandMeister talkgroup, RX is audible, TX is keyable." Plan on **45–90 minutes** for the first install — most of it is on-air verification, not configuration.

If you're new to TicketsCAD itself, finish [INSTALL.md](INSTALL.md) first. This guide assumes the app is up and you can log in as Super Admin.

If you want **Claude responding on the air** under operator supervision, finish this guide first, then continue to [RADIO-AI-ADMIN-GUIDE.md](RADIO-AI-ADMIN-GUIDE.md).

---

## What you're building

```
   ┌──────────────────┐                  ┌────────────────────┐
   │ Dispatcher       │  wss://host      │  TicketsCAD web    │
   │ browser          │ /dmr-ws          │  (Apache + PHP)    │
   │ (radio widget)   │ ───────────────► │                    │
   └──────────────────┘                  └─────────┬──────────┘
                                                   │ ws://127.0.0.1:8092
                                                   ▼
                                         ┌────────────────────┐
                                         │ dmr-proxy.php      │
                                         │ (Ratchet daemon)   │
                                         └─────────┬──────────┘
                                                   │ HTTP control + SSE
                                                   ▼
                                         ┌────────────────────┐
                                         │ hbp_client.py      │
                                         │ (HBP bridge)       │
                                         └─────────┬──────────┘
                                                   │ UDP (HomeBrew Protocol)
                                                   ▼
                                         ┌────────────────────┐
                                         │ BrandMeister       │
                                         │ master server      │
                                         └────────────────────┘
                                                   │
                                                   ▼
                                            DMR talkgroup
                                            (your radio)
```

Three processes need to be running on top of the base TicketsCAD install:

| Process | Where | Default Port |
|---|---|---|
| `md380-emu` | bridge host | UDP 2470 (local only) — AMBE+2 voice codec |
| `hbp_client.py` | bridge host | UDP 62032 (HBP) + TCP 18091 (HTTP control + SSE) |
| `dmr-proxy.php` (Ratchet) | TicketsCAD web host | TCP 8092 — browser-facing WebSocket bridge |

The **bridge host** and the **TicketsCAD web host** can be the same VM (Bloomington's setup) or separate (training has the bridge on `dvswitch-01`). Co-locating is simpler; separating is better if you'll run multiple TicketsCAD instances against one bridge.

---

## Prerequisites

You'll need:

1. **A BrandMeister hotspot account** with a registered DMR ID (your "hotspot ID"), a master server hostname (e.g. `3102.master.brandmeister.network` for US Central), and a per-account password. Sign up at brandmeister.network.
2. **Your personal DMR ID** (the one your handheld is registered to on radioid.net). This will be the `src_id` on every TX the bridge originates.
3. **A target talkgroup** (TG number) you want to RX from and TX to. Pick one that's relevant to your area and where you have permission to operate.
4. **The `md380-emu` ARM binary** (~1.8 MB) — the install script can build it from upstream or scp it from another working bridge. Source: <https://github.com/travisgoodspeed/md380tools/wiki/MD380-Emulator>.
5. **Outbound UDP** from the bridge host to your BrandMeister master server (default port 62031). Most NAT setups Just Work; restrictive firewalls may need a rule.

---

## Step 1 — Run the bridge install script

On the host you're putting the bridge on (Bloomington = same VM as the web; training = separate `dvswitch-01` VM):

```bash
cd /var/www/newui      # or wherever the TicketsCAD repo lives
sudo bash services/dvswitch/install-bridge.sh
```

The script is idempotent and prints a step-by-step list of what it's doing:

1. Installs OS packages (`python3-venv`, `ffmpeg`, `qemu-user-static`)
2. Creates the unprivileged `ticketscad` system user
3. Sources `md380-emu` (see below for the three options)
4. Copies the bridge Python source to `/opt/ticketscad-dvswitch/`
5. Builds a Python venv with `faster-whisper`, `piper-tts`, `onnxruntime`, `numpy`
6. Downloads the default Piper voice (`en_US-lessac-medium`)
7. Creates state dirs under `/var/cache/ticketscad-dvswitch/`
8. Lays down `/opt/MMDVM_Bridge/MMDVM_Bridge.ini` TEMPLATE and `/etc/ticketscad/hbp-client.env` TEMPLATE
9. Installs the `ticketscad-hbp-client.service` and `md380-emu.service` systemd units
10. Reloads systemd. **Does NOT start anything** — you finish the config first.

### How the script gets `md380-emu`

The MD-380 firmware emulator is not shipped in the TicketsCAD repo (not our code; upstream is travisgoodspeed/md380tools). Three options, pick one:

**(a) scp from an existing TicketsCAD bridge in your fleet** — fastest:
```bash
sudo MD380_SOURCE_BRIDGE=dvswitch-01 bash services/dvswitch/install-bridge.sh
```

**(b) Build from upstream source** — canonical, slower (~5 minutes):
```bash
sudo MD380_BUILD=1 bash services/dvswitch/install-bridge.sh
```
This clones `travisgoodspeed/md380tools`, installs `gcc-arm-linux-gnueabi`, runs `make clean all` in `emulator/`, and installs the resulting binary. The upstream build sometimes fails on a fresh distro (firmware blob, toolchain mismatch); if it does, try (a) instead.

**(c) Pre-built binary from a URL you trust**:
```bash
sudo MD380_URL=https://example.com/md380-emu bash services/dvswitch/install-bridge.sh
```

If none of the three is set, the script bails with a clear message — drop the binary at `/opt/md380-emu/md380-emu` manually and re-run.

---

## Step 2 — Configure `/opt/MMDVM_Bridge/MMDVM_Bridge.ini`

```bash
sudo vim /opt/MMDVM_Bridge/MMDVM_Bridge.ini
```

Edit these fields with your actual values:

```ini
[General]
Callsign=YOUR_CALLSIGN          # e.g. N0NKI
Id=000000000                    # your hotspot DMR ID (NOT your personal handheld ID)

[DMR Network]
Address=YOUR_BM_MASTER_HOSTNAME # e.g. 3102.master.brandmeister.network
Password=REPLACE_ME_WITH_BM_PASSWORD
```

Tighten permissions once populated:

```bash
sudo chown root:ticketscad /opt/MMDVM_Bridge/MMDVM_Bridge.ini
sudo chmod 0640 /opt/MMDVM_Bridge/MMDVM_Bridge.ini
```

The `Password` line is your BrandMeister account password, NOT a per-talkgroup secret. It lives only on this host; it should never reach git, screenshots, or chat history.

---

## Step 3 — Configure `/etc/ticketscad/hbp-client.env`

```bash
sudo vim /etc/ticketscad/hbp-client.env
```

Set:

```bash
DMR_BEARER_TOKEN=<paste the value the install script printed,
                  or generate with: openssl rand -hex 32>
DMR_OPERATOR_ID=<your personal handheld DMR ID — src_id on TX>
DMR_DEFAULT_TG=<the talkgroup number you want to TX on by default>
```

`DMR_BEARER_TOKEN` is the shared secret between this bridge and the TicketsCAD web host. It's NOT a BrandMeister credential. You'll paste the same value into the `dmr_channels.bridge_token` column in Step 6.

Tighten:
```bash
sudo chown root:ticketscad /etc/ticketscad/hbp-client.env
sudo chmod 0640 /etc/ticketscad/hbp-client.env
```

---

## Step 4 — Start the bridge services

```bash
sudo systemctl enable --now md380-emu.service
sudo systemctl enable --now ticketscad-hbp-client.service
sudo journalctl -u ticketscad-hbp-client -f
```

Within a few seconds you should see:

```
INFO [hbp] config loaded — DMR ID=... callsign=... master=...
INFO [hbp] bound local UDP 62032
INFO [hbp] sent RPTL for DMR ID ...
INFO [hbp] HTTP control listening on :18091
INFO [hbp] sent RPTK (auth hash)
INFO [hbp] sent RPTC config (302 bytes)
INFO [hbp] RUNNING — authenticated to <master>:62031 as DMR ID <Id>
```

If it stops at `sent RPTK` and doesn't reach `RUNNING`, the BrandMeister master rejected the password. Re-check `[DMR Network] Password=` in the INI.

If it doesn't reach `sent RPTL`, the master isn't reachable on UDP — check your firewall + `nslookup` the hostname.

`Ctrl-C` to exit the log tail; the service keeps running.

---

## Step 5 — Set up the WebSocket proxy (web host)

These steps run on the **TicketsCAD web host**. If that's the same VM as the bridge, you're still in the right place.

### 5a. Composer dependencies

The proxy uses Ratchet for WebSockets. On a fresh TicketsCAD install you already ran `composer install` (per INSTALL.md). If you didn't, do it now:

```bash
cd /var/www/newui
sudo -u www-data composer install --no-dev --optimize-autoloader
```

### 5b. Install the dmr-proxy systemd unit

```bash
sudo cp /var/www/newui/proxy/newui-dmr-proxy.service.example \
        /etc/systemd/system/newui-dmr-proxy.service
sudo mkdir -p /var/log/newui
sudo chown www-data:www-data /var/log/newui
sudo systemctl daemon-reload
sudo systemctl enable --now newui-dmr-proxy
sudo systemctl status newui-dmr-proxy --no-pager | head -10
```

You should see `Active: active (running)`. The proxy logs to `/var/log/newui/dmr-proxy.log`.

### 5c. Enable Apache modules + add the `/dmr-ws` Location rule

The Apache vhost template already has the rule (see `apache/newui.conf.example`). If you used it during INSTALL.md, it's there; verify with:

```bash
grep -A 3 "dmr-ws" /etc/apache2/sites-enabled/newui.conf
```

If it's not there, add this block inside your `<VirtualHost *:80>` (or `:443`):

```apache
<Location /dmr-ws>
    ProxyPass        ws://127.0.0.1:8092/ keepalive=On
    ProxyPassReverse ws://127.0.0.1:8092/
</Location>
```

Make sure the required mods are enabled and reload:

```bash
sudo a2enmod proxy proxy_http proxy_wstunnel
sudo apache2ctl configtest
sudo systemctl reload apache2
```

---

## Step 6 — Insert the channel row

TicketsCAD discovers DMR channels through the `dmr_channels` table. Add one row per (bridge, talkgroup) pair you want exposed in the radio widget.

```bash
sudo mysql newui <<SQL
INSERT INTO dmr_channels
    (label, talkgroup, network, bridge_host, bridge_port, bridge_token,
     usrp_listen_port, usrp_send_port, link_mode, enabled)
VALUES (
    'My Local TG',                   -- whatever the operator should see
    31272,                           -- your talkgroup
    'BrandMeister',
    '127.0.0.1',                     -- 'localhost' if bridge is co-located,
                                     -- otherwise the bridge host's IP
    18091,                           -- the bridge's HTTP control port
    'PASTE_DMR_BEARER_TOKEN_HERE',   -- same value as /etc/ticketscad/hbp-client.env
    0, 0,                            -- USRP ports unused on the HBP path
    'bidirectional',                 -- 'rx_only', 'tx_only', or 'bidirectional'
    1                                -- 1 = enabled
);
SQL
```

The `link_mode` is an ENUM. Use `bidirectional` for the normal case; use `rx_only` if you want operators to monitor but not transmit (the widget will hide the PTT button).

---

## Step 7 — Grant operator permission + test

In the TicketsCAD admin UI:

1. **Permissions** — your operator's role needs at least `action.dmr_receive` to see the Radio button + RX traffic. Add `action.dmr_transmit` to allow PTT. (Super Admin has both by default.)
2. **Log out and back in** (or hard-refresh) so the new permission lands in the session.

In the dispatch UI:

1. Click the **Radio** button (broadcast icon) in the top nav. The widget opens.
2. Within a few seconds the connection indicator should go from gray to green and you should see incoming RX cards as traffic happens on the talkgroup.
3. Press and hold the **Push to Talk** button (or hold `Space`) and speak a short test phrase. Release.
4. Verify on a separate radio (yours, a hotspot dashboard like Pi-Star's, or BrandMeister hoseline at `hose.brandmeister.network`) that the TX reached the talkgroup.

If you don't hear yourself on the air, see the troubleshooting matrix in [DVSWITCH-ADMIN-GUIDE.md](DVSWITCH-ADMIN-GUIDE.md#troubleshooting).

---

## What's running now

After a successful install you have these services up:

| Service | Host | Restart on crash | Logs |
|---|---|---|---|
| `md380-emu` | bridge | yes | `journalctl -u md380-emu` |
| `ticketscad-hbp-client` | bridge | yes | `journalctl -u ticketscad-hbp-client` |
| `newui-dmr-proxy` | web | yes (on-failure) | `/var/log/newui/dmr-proxy.log` |
| `apache2` | web | yes | `journalctl -u apache2` |

All four are systemd-managed and survive host reboots.

---

## Common gotchas

| Symptom | Likely cause | Fix |
|---|---|---|
| Widget shows "disconnected" forever | dmr-proxy not running, OR Apache `/dmr-ws` not configured, OR `dmr_channels` row missing | `systemctl status newui-dmr-proxy`; `grep dmr-ws /etc/apache2/sites-enabled/*`; `SELECT * FROM dmr_channels` |
| Widget connects but never shows RX | Bridge not authenticated to BM, OR the talkgroup is genuinely quiet | Tail bridge journal for `RUNNING` line; verify on BM hoseline |
| TX returns "Auth DB error" | Proxy lost its MySQL connection (idle wait_timeout); the auto-reconnect is fix-18 (Phase 85c) — should be in current code | `systemctl restart newui-dmr-proxy` |
| TX returns "Invalid command" mid-TX | Ratchet WS interface bug (fixed in Phase 85c fix-19) | Pull latest code and `systemctl restart newui-dmr-proxy` |
| TX audio sounds bad / chopped | Widget chunking bug (fixed in Phase 85c fix-22) | Pull latest code and hard-refresh browser |
| `RUNNING` in bridge log but BM dashboard says "not connected" | The TG you tried isn't actually static-linked to this hotspot, OR you have to TX once to register | Either link the TG in your BrandMeister settings, or just key up and the TG should appear in your hotspot's connected list |
| "older than 30-second DVR window" on history card playback | Live card aged out of ring before the history WAV got stamped (fixed in Phase 85c fix-23) | Pull latest code and hard-refresh |

---

## Optional next steps

- **Per-channel transcripts.** The bridge ships with `faster-whisper` installed but transcript writes are off by default. Set `dmr_messages.transcript_engine` per-channel or via the listener settings.
- **Audio archive.** Recordings land in `/var/cache/ticketscad-dvswitch/recordings/`. The Radio Archive page (`dmr-archive.php`) provides UI to browse + replay them. No extra setup; just point a browser at it.
- **Radio AI** — Claude responds to amateur callers under operator-in-the-loop approval. See [RADIO-AI-ADMIN-GUIDE.md](RADIO-AI-ADMIN-GUIDE.md).
- **Multiple channels** — add more rows to `dmr_channels`. Each gets its own card in the widget channel picker. Same bridge can serve multiple TGs if your hotspot is static-linked to them on BrandMeister.

---

## Files this guide installed or modified

| Path | Purpose |
|---|---|
| `/opt/md380-emu/md380-emu` | ARM AMBE+2 codec binary |
| `/opt/md380-emu/qemu-arm-static` | symlink → `/usr/bin/qemu-arm-static` |
| `/opt/ticketscad-dvswitch/` | Python bridge source + venv |
| `/opt/MMDVM_Bridge/MMDVM_Bridge.ini` | BrandMeister-side config (Callsign, Id, Address, Password) |
| `/etc/ticketscad/hbp-client.env` | bridge env (DMR_BEARER_TOKEN, operator ID, default TG) |
| `/etc/systemd/system/md380-emu.service` | systemd unit for codec |
| `/etc/systemd/system/ticketscad-hbp-client.service` | systemd unit for bridge |
| `/etc/systemd/system/newui-dmr-proxy.service` | systemd unit for WS proxy |
| `/var/cache/ticketscad-dvswitch/recordings/` | per-call WAV files |
| `/var/log/newui/dmr-proxy.log` | WS proxy log |
| `<DB>.dmr_channels` | one row per bridge/talkgroup pair |
| `apache vhost: <Location /dmr-ws>` | reverse-proxies WS to the proxy daemon |

---

## Related docs

- [INSTALL.md](INSTALL.md) — base TicketsCAD install (must come first)
- [DVSWITCH-ADMIN-GUIDE.md](DVSWITCH-ADMIN-GUIDE.md) — deeper architecture + per-channel ops
- [RADIO-AI-ADMIN-GUIDE.md](RADIO-AI-ADMIN-GUIDE.md) — Claude-on-amateur-radio feature (Phase 85f)
- [RADIO-AI-USER-GUIDE.md](RADIO-AI-USER-GUIDE.md) — operator-facing AI approval workflow
- RADIO-AI-SECURITY-REVIEW.md — threat model
- [services/dvswitch/install-bridge.sh](../services/dvswitch/install-bridge.sh) — the install script itself
- [services/dvswitch/hbp-client.env.example](../services/dvswitch/hbp-client.env.example) — env template
- [apache/newui.conf.example](../apache/newui.conf.example) — Apache vhost template
