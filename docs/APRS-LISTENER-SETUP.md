# APRS-IS Persistent Listener — Setup Guide

This is the **low-latency** path for ingesting APRS positions into TicketsCAD. Compared to the aprs.fi polling cron job (which lags up to 5 minutes), the persistent listener pushes packets through within seconds of receiving them from the APRS network.

Both methods can run side-by-side — most deployments do.

## What it is

A Python service (`services/aprs-is/listener.py`) that opens a long-lived TCP socket to an APRS-IS tier-2 server (e.g., `noam.aprs2.net:14580`), sends an APRS-IS login + filter line, and parses every position packet that arrives. Each parsed position is written to the `location_reports` table under the configured `provider_id` for APRS.

## Quick install (Debian/Ubuntu)

```bash
# 1. Python deps
sudo apt-get install -y python3-venv
python3 -m venv ~/aprs-listener-venv
source ~/aprs-listener-venv/bin/activate
pip install --upgrade pip
pip install aprslib requests pymysql

# 2. Drop the listener.py file in place
sudo cp /var/www/newui/services/aprs-is/listener.py /opt/aprs-listener.py

# 3. Create the config
sudo mkdir -p /etc/ticketscad
sudo tee /etc/ticketscad/aprs-listener.ini > /dev/null <<EOF
[aprs]
server   = noam.aprs2.net
port     = 14580
# Your APRS-IS login (no password needed for receive-only)
callsign = N0CALL
passcode = -1
# Server-side filter — see http://www.aprs-is.net/javAPRSFilter.aspx
# Examples:
#   r/44.97/-93.27/50      → radius 50 km around Minneapolis
#   b/W0AM/KC0GHQ          → buddy list of callsigns
#   p/KC0/W0/N0            → callsign prefixes
filter   = r/44.97/-93.27/50

[cad]
url        = https://your-server.example.com
api_token  = <FEED_API_KEY_OR_DEDICATED_TOKEN>
provider_code = aprs

[runtime]
reconnect_seconds = 30
EOF
sudo chmod 0640 /etc/ticketscad/aprs-listener.ini
```

## Systemd unit

```bash
sudo tee /etc/systemd/system/aprs-listener.service > /dev/null <<'EOF'
[Unit]
Description=TicketsCAD APRS-IS Persistent Listener
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=ejosterberg
ExecStart=/home/ejosterberg/aprs-listener-venv/bin/python /opt/aprs-listener.py --config /etc/ticketscad/aprs-listener.ini
Restart=on-failure
RestartSec=30
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now aprs-listener
sudo journalctl -u aprs-listener -f
```

You should see lines like:

```
INFO  Connected to noam.aprs2.net:14580
INFO  Sent filter: r/44.97/-93.27/50
INFO  Received beacon: KC0GHQ-9 @ 44.94, -93.30
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

## Verifying ingest

Once the service is running, every received packet should land in `location_reports` within seconds. On the **System Health** page → **Location Providers** card, the APRS provider should flip from "passive" / "no_data" to a green dot with a recent timestamp.

```sql
SELECT received_at, unit_identifier, lat, lng
  FROM location_reports
  JOIN location_providers ON location_providers.id = location_reports.provider_id
 WHERE location_providers.code = 'aprs'
 ORDER BY received_at DESC LIMIT 10;
```

## Troubleshooting

- **"connection refused"** — try a different tier-2 server (`euro.aprs2.net`, `asia.aprs2.net`).
- **"no packets after login"** — your filter probably excludes everything. Try removing the filter line entirely as a test.
- **"can't auth"** — your callsign is wrong, or you used a real passcode without permission. Receive-only login uses passcode `-1`.
- **High CPU** — switch filter from a wide-area `r/` to a buddy list `b/`. The listener parses every packet that matches the server-side filter; narrower filters mean less work.

## Combining with aprs.fi polling

Run both. The polling cron job catches anything the listener missed during reconnect windows, and the listener gives you near-real-time on the happy path. Both write into the same `location_reports` table — duplicate detection happens in the ingest layer.
