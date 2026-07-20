# APRS-IS Listener Service

Persistent TCP listener that subscribes to the APRS-IS network and posts
position packets to the TicketsCAD location API in real time.

This replaces the 5-minute aprs.fi REST poller (`tools/aprs-poller.php`)
for sites that want sub-second location updates. Both can coexist — the
poller is a safety net if the listener goes down.

## Why

- **Latency**: A packet hits the dashboard within a second of being
  received instead of up to 5 minutes later.
- **Cost**: APRS-IS is free and has no API rate limit. aprs.fi is free
  for low volumes but is rate-limited and discouraged for production.
- **Bidirectional**: With a real passcode the listener can be extended
  to send packets back into APRS-IS (e.g. status beacons for dispatched
  units). That's not implemented yet but the connection is already open.

## Files

| File | Purpose |
|---|---|
| `listener.py` | The service itself. Python 3.8+ |
| `listener.ini.example` | Copy to `/etc/ticketscad/aprs-is.ini` and edit |
| `aprs-is.service.example` | systemd unit. Copy to `/etc/systemd/system/aprs-is.service` |
| `README.md` | You are here |

## Quick start

```bash
# 1. install dependencies (Debian / Ubuntu)
sudo apt install python3-pip
sudo pip3 install requests aprslib

# 2. configure
sudo mkdir -p /etc/ticketscad
sudo cp listener.ini.example /etc/ticketscad/aprs-is.ini
sudo nano /etc/ticketscad/aprs-is.ini   # set callsign + filter

# 3. test by hand first (Ctrl-C to stop)
python3 listener.py --config /etc/ticketscad/aprs-is.ini -v

# 4. when it works, install as a service
sudo cp aprs-is.service.example /etc/systemd/system/aprs-is.service
sudo systemctl daemon-reload
sudo systemctl enable --now aprs-is
sudo journalctl -u aprs-is -f
```

## Health monitoring

The listener pings `/api/service-uptime.php` every 60 seconds with a
status of `running` (with packet/position counters) or `disconnected`
(with the error and next-reconnect delay). The TicketsCAD UI's
**Settings → System Health → Location Providers** card will reflect
this in real time.

## Filter syntax cheat sheet

| Filter | Meaning |
|---|---|
| `b/W7ABC*/KE0XYZ` | Any callsign starting with W7ABC or exactly KE0XYZ |
| `r/44.97/-93.26/100` | Within 100 km of (44.97 N, 93.26 W) |
| `t/p` | All position packets (firehose — DO NOT use without geographic filter) |
| `g/EMERG*` | Any group beginning with EMERG |

Combine filters with spaces: `b/W7ABC* r/44.97/-93.26/50`

Full reference: http://www.aprs-is.net/javAPRSFilter.aspx

## Passcode

The APRS-IS passcode is a 16-bit integer derived from the callsign.
The algorithm is public domain and has many calculators online. Use
`-1` to connect in receive-only mode (no login required, useful for
testing).
