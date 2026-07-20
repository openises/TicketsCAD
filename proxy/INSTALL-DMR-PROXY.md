# DMR WebSocket Proxy — installation (Phase 85c)

The proxy is a separate long-running PHP CLI daemon that bridges
browser WebSocket connections to the DMR backend (`hbp_client.py`
on the dvswitch VM). It exists because browser-side streaming
fetch uploads are unreliable in Firefox/HTTP-1.1; the proxy solves
that with a real WebSocket from the browser plus a reliable
server-side chunked HTTP POST upstream.

Architecture, anti-patterns to avoid, and the operational rationale
live in the `realtime-streaming-proxy` Claude skill at
`~/.claude/skills/realtime-streaming-proxy/SKILL.md`. Read that
first if you're new to this layer.

## Prerequisites

- The bridge (`hbp_client.py`) must be running and reachable on its
  HTTP control port (default `18091`) from the host where the proxy
  runs.
- A `dmr_channels` row enabled in the database — the proxy uses
  `bridge_host`, `bridge_port`, and `bridge_token` from there.
- The Phase 85c migration applied:
  ```
  php sql/run_phase85c_dmr_ws_tokens.php
  ```
  Creates the `dmr_ws_tokens` table and seeds `dmr_proxy_port=8092`.
- Apache modules: `proxy`, `proxy_http`, and **`proxy_wstunnel`**.
  ```
  sudo a2enmod proxy proxy_http proxy_wstunnel && sudo systemctl reload apache2
  ```
- Composer dependencies already installed (`cboden/ratchet`,
  `react/socket`, etc) — they ship with the Zello proxy.

## Foreground / dev

```
chmod +x proxy/start-dmr-proxy.sh
proxy/start-dmr-proxy.sh
```

Press Ctrl+C to stop. Override the PHP binary with
`PHP_BIN=/usr/bin/php8.4 proxy/start-dmr-proxy.sh`.

## systemd (production)

```bash
sudo cp proxy/newui-dmr-proxy.service.example /etc/systemd/system/newui-dmr-proxy.service
sudo mkdir -p /var/log/newui && sudo chown www-data:www-data /var/log/newui
sudo systemctl daemon-reload
sudo systemctl enable --now newui-dmr-proxy.service
sudo systemctl status newui-dmr-proxy
sudo ss -tlnp | grep :8092
```

## Apache vhost (one-time edit)

Add inside the existing `<VirtualHost>` block on your NewUI host:

```apache
<Location /dmr-ws>
    ProxyPass        ws://localhost:8092/ keepalive=On
    ProxyPassReverse ws://localhost:8092/
</Location>
```

Then `sudo systemctl reload apache2`. No changes needed for
Cloudflare Tunnel — WebSocket upgrades pass through transparently.

## Verifying

1. **Daemon listening**
   ```
   sudo ss -tlnp | grep :8092
   ```
2. **WS upgrade reaches the daemon** (from any machine on the LAN):
   ```
   curl --include --http1.1 \
       --header "Connection: Upgrade" \
       --header "Upgrade: websocket" \
       --header "Sec-WebSocket-Version: 13" \
       --header "Sec-WebSocket-Key: $(echo -n some_test_value | base64)" \
       http://localhost:8092/
   ```
   Expect `HTTP/1.1 101 Switching Protocols`. If you get 426 / 403, the
   Ratchet WsServer is rejecting — check the daemon's stderr.
3. **End-to-end** — open the radio widget on `your-server.example.com`.
   Browser console should show:
   ```
   [radio] DMR WS open
   [radio] DMR auth_ok
   ```
   Hold PTT 3 seconds. The bridge log on dvswitch-01 should show
   the matching `streaming TX started / done` lines with
   `packets_sent ≈ 50` (3 sec ≈ 50 voice bursts + headers + terminator).

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| daemon won't start, "Address already in use" | Old process still bound | `sudo pkill -9 -f dmr-proxy.php; sleep 3; systemctl restart` |
| browser sees 502 from Apache on /dmr-ws | `proxy_wstunnel` not enabled | `a2enmod proxy_wstunnel && systemctl reload apache2` |
| auth fails with "Invalid or expired token" | Browser took >2 min between `/api/dmr-token.php` and WS connect | Browser should request a fresh token each time it opens the WS; check `radio-widget.js` |
| `bridge_token` empty in auth_ok logs | DB row `dmr_channels.bridge_token` is blank | Apply the dmr_channels migration; set via Settings → DMR |
| TX succeeds (tx_ack OK) but radio hears nothing | Bridge isn't subscribed to TG | Check `brandmeister.network/?page=device&id=YOURID`; ensure TG is in static-subscribed list |

## Reading order for the source

1. `proxy/dmr-proxy.php` — bootstrap (180 lines)
2. `proxy/DmrProxyApp.php` — onOpen/onMessage/onClose + auth + ptt
3. `proxy/DmrUpstream.php` — TCP socket + chunked-POST upstream
4. `~/.claude/skills/realtime-streaming-proxy/SKILL.md` — pattern + anti-patterns
5. `specs/phase-85c-dmr-websocket-proxy/spec.md` — what was built and why
