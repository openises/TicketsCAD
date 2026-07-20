# Zello Proxy — Linux installation

The Windows launcher (`start-proxy.bat`) ships in this directory for dev
work on Windows. For Linux, use one of these two paths:

## Option 1: Foreground (development)

```bash
chmod +x proxy/start-proxy.sh
proxy/start-proxy.sh
```

Press Ctrl+C to stop. Auto-discovers the `php` binary on PATH; override with
`PHP_BIN=/usr/bin/php8.2 proxy/start-proxy.sh` if you have multiple PHP
versions installed.

## Option 2: systemd service (production)

```bash
# Edit the example to match your install (User, paths)
sudo cp proxy/newui-zello-proxy.service.example /etc/systemd/system/newui-zello-proxy.service

# Create log directory writable by the service user
sudo mkdir -p /var/log/newui
sudo chown www-data:www-data /var/log/newui

# Create the voice-recording cache dir writable by the service user.
# The proxy saves replayable .ogg recordings here; the unit's ReadWritePaths
# already includes /var/www/newui/cache so the write succeeds under
# ProtectSystem=strict. (The proxy also self-creates zello-audio/ on start,
# but only if it can write the parent cache/ dir.)
sudo mkdir -p /var/www/newui/cache/zello-audio
sudo chown -R www-data:www-data /var/www/newui/cache

# Enable + start
sudo systemctl daemon-reload
sudo systemctl enable --now newui-zello-proxy.service

# Verify
sudo systemctl status newui-zello-proxy
sudo ss -tlnp | grep :8090
```

The service auto-restarts on failure (5 s back-off), runs under `www-data`
by default, and writes logs to `/var/log/newui/zello-proxy.log` *and*
`journalctl -u newui-zello-proxy`.

The unit is hardened with `NoNewPrivileges`, `ProtectSystem=strict`,
`PrivateTmp`, etc. — see the file for the full list. `ReadWritePaths` must
include both `/var/www/newui/proxy` (PID file) **and** `/var/www/newui/cache`
(voice recordings) — under `ProtectSystem=strict` every other path is
read-only, so a missing cache entry makes recordings fail with "Read-only
file system" and replay shows "playback error". If you change the NewUI
install path, edit the `WorkingDirectory`, `ExecStart`, and `ReadWritePaths`
lines accordingly.

## Apache WebSocket reverse-proxy snippet (required for HTTPS deployments)

On any install behind HTTPS, the dispatcher's Zello widget can't connect to `ws://your-host:8090` directly — browsers block mixed-content (insecure WebSocket from a secure page). The widget uses `wss://your-host/zello-ws` instead, which Apache needs to reverse-proxy to the local Zello proxy daemon on port 8090.

Enable `mod_proxy_wstunnel` and add the location block to your existing `<VirtualHost *:443>`:

```bash
sudo a2enmod proxy_wstunnel
```

Then in `/etc/apache2/sites-available/newui-le-ssl.conf` (or whichever vhost file certbot generated), inside the `<VirtualHost *:443>` block:

```apache
# Zello proxy reverse-proxy (Phase 39 Zello integration)
<Location /zello-ws>
    ProxyPass        ws://127.0.0.1:8090/
    ProxyPassReverse ws://127.0.0.1:8090/
</Location>
```

Reload Apache:

```bash
sudo apachectl configtest && sudo systemctl reload apache2
```

Verify with the dispatcher's Zello widget — it should connect cleanly (no "Disconnected from proxy" loop). If you see the loop, check journal logs (`sudo journalctl -u newui-zello-proxy -n 100 --no-pager`) — the proxy connects to Zello's upstream and the widget connects to YOUR proxy; either side can fail independently.

Beta tester a beta tester hit the "Requesting auth token... Disconnected from proxy" loop 2026-06-26 because his deployment had the proxy daemon running but no `<Location /zello-ws>` snippet in Apache — the widget's WebSocket connection had nowhere to land.

## On cPanel / WHM hosts

cPanel manages Apache differently — direct edits to `/etc/apache2/sites-available/*.conf` get overwritten on the next Apache config regeneration. The snippet above won't survive a `httpd -S` rebuild on a cPanel box, even if you can find the right file to edit.

**The cPanel-supported path** is the WHM Include Editor. The snippet lives in a managed include that cPanel knows about and won't clobber:

1. Log into WHM as root.
2. Navigate to **Service Configuration → Apache Configuration → Include Editor**.
3. Under **Post-VirtualHost Include**, pick **All Versions** from the version dropdown (or the specific Apache version your install uses if you only want this on one).
4. Click **Update**.
5. Paste the snippet, adjusted for your account's domain (replace `your.domain.tld` with the host TicketsCAD is served from):

   ```apache
   <VirtualHost *:443>
       ServerName your.domain.tld

       <Location /zello-ws>
           ProxyPass        ws://127.0.0.1:8090/
           ProxyPassReverse ws://127.0.0.1:8090/
       </Location>
   </VirtualHost>
   ```

6. Click **Update** at the bottom of the editor. WHM runs `httpd -t` to validate; if it passes, click **Restart Apache** when prompted.
7. Verify mod_proxy_wstunnel is loaded: WHM **Service Configuration → Apache Configuration → Global Configuration** has Apache module checkboxes. `mod_proxy_wstunnel` needs to be checked + saved + Apache rebuilt. If it's already checked, you're fine; if not, check it, click **Save**, then go to **EasyApache 4** and click **Customize → Apache Modules → Provision** to actually install it. This is the one place cPanel diverges noticeably from a stock Apache install — `a2enmod` doesn't exist on cPanel; modules are loaded via the EasyApache config.

**cPanel-specific log paths** when you need to diagnose:

- Apache error log: `/usr/local/apache/logs/error_log` (NOT `/var/log/apache2/error.log` like stock Debian/Ubuntu).
- Per-domain error log: `/usr/local/apache/logs/domlogs/your.domain.tld-error_log`.
- The Zello proxy daemon's systemd log (assuming you got the systemd unit installed; see the "On cPanel + systemd" note below) is still at `journalctl -u newui-zello-proxy`.

**Systemd on cPanel/WHM**: cPanel hosts usually run systemd, so the Option 2 systemd flow above works as-is — you just can't manage it through the WHM UI. SSH in as root and run `systemctl enable --now newui-zello-proxy.service` directly. If your hosting provider gives you a non-root account with `sudo`, use that.

**End-to-end diagnostic** when the widget still flaps after configuring both pieces:

```bash
# 1. Is the proxy daemon actually listening on 8090?
sudo ss -tlnp | grep :8090

# 2. Can Apache reverse-proxy reach it? (returns HTTP 400 or 426 with WS upgrade attempt is OK;
#    a 404 means the <Location> block isn't loaded)
curl -sv -o /dev/null -w "%{http_code}\n" http://localhost/zello-ws

# 3. Does WHM see the Location block? (any output here means the include is loaded)
sudo apachectl -t -D DUMP_INCLUDES 2>&1 | grep -i zello

# 4. Live tail of incoming WS connections to the proxy daemon
sudo journalctl -u newui-zello-proxy -f
# Then open the dispatcher Zello widget in the browser. If nothing appears in journal,
# the WS connection isn't reaching the daemon — the issue is between browser and daemon
# (Apache reverse-proxy, or mod_proxy_wstunnel not loaded).
```

## Stopping / restarting

```bash
sudo systemctl restart newui-zello-proxy   # rolling restart
sudo systemctl stop    newui-zello-proxy
sudo systemctl disable newui-zello-proxy   # remove from autostart
```

## Common gotchas

- `proxy/zello-proxy.pid` is written into the proxy directory; the unit
  expects that directory to be writable by www-data. If you see
  `Permission denied` warnings in the log, run
  `sudo chown -R www-data:www-data /var/www/newui/proxy`.
- The service depends on `mariadb.service`. If your DB runs on a separate
  host, change `After=` accordingly.
- Port 8090 is hard-coded as the default; admins can change it via Settings
  → Integrations → Zello in the NewUI UI. Don't forget to open the new port
  on the firewall and update the front-end's WebSocket URL.

## Troubleshooting

```bash
# Live tail
sudo journalctl -u newui-zello-proxy -f

# Last 200 lines
sudo journalctl -u newui-zello-proxy -n 200 --no-pager

# Run in the foreground for debugging (kills any running service first)
sudo systemctl stop newui-zello-proxy
sudo -u www-data /usr/bin/php /var/www/newui/proxy/zello-proxy.php
```
