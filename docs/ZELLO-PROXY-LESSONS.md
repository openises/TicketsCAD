# TicketsCAD Zello proxy — lessons learned

Concrete gotchas discovered while building `newui-dev/newui/proxy/zello-proxy.php` and its supporting classes. Read this before touching any Zello code.

For the general "browser audio to voice service" playbook, see the global skill: `~/.claude/skills/browser-audio-to-voice-service/SKILL.md`.

## The stack

```
Browser (Bootstrap 5 + jQuery-free ES5)
  └─ Zello widget (assets/js/zello-widget.js)
     └─ WebSocket → ws(s)://<host>:8090
        └─ Ratchet PHP proxy (proxy/zello-proxy.php as systemd daemon)
           ├─ ZelloProxyApp    (browser-facing WS: PTT, chat, controls)
           ├─ ZelloUpstream    (upstream WSS to Zello)
           ├─ WebSocketConnector (raw Ratchet Pawl connector with TLS)
           ├─ WebmOpusExtractor  (parse WebM SimpleBlocks → Opus packets)
           └─ OggOpusWriter      (write Opus packets → .ogg file for replay)
              └─ wss://zellowork.io/ws/<network>  (Zello Work)
                 or wss://zello.io/ws              (Zello consumer)
```

Systemd unit: `/etc/systemd/system/newui-zello-proxy.service`.
Log file: `/var/log/newui/zello-proxy.log` (StandardOutput and StandardError both append here).

## Configuration table (Settings → Zello Network Radio)

| Setting              | Purpose                                                                 |
|----------------------|-------------------------------------------------------------------------|
| `zello_service`      | `work` / `consumer` / `disabled`. Determines WSS URL branch             |
| `zello_network`      | Zello Work network name (only for `work`). Becomes the URL path segment |
| `zello_ws_url`       | Manual override. Only compute automatically when this is blank          |
| `zello_username`     | Login username (Work) or channel username (consumer)                    |
| `zello_password`     | Login password (Work)                                                   |
| `zello_issuer`       | Zello developer console issuer (for JWT auth)                           |
| `zello_private_key`  | JWT signing key                                                         |
| `zello_auth_token`   | Alternate — direct auth token, skips JWT flow                           |
| `zello_dispatch_channel` | Channel the proxy joins on connect                                  |

## Zello Work vs consumer

| Aspect              | Consumer (`zello.io`)                       | Work (`zellowork.io`)                                 |
|---------------------|---------------------------------------------|-------------------------------------------------------|
| WSS URL             | `wss://zello.io/ws`                         | `wss://zellowork.io/ws/<network>`                     |
| Auth                | JWT or auth token                           | username + password (basic auth over WS)              |
| Channels            | Public channels                             | Per-network channels                                  |
| Rate limits         | Looser                                      | Tighter — trips at ~3-5 connects/min per IP           |
| Multi-session       | Allowed (mostly)                            | Kicks older session with close code 3003              |

Eric's install uses **Zello Work**. Network name: `odymed`. Default channel: `Dispatch Test`.

## WSS close codes (observed empirically)

**These are the definitive codes we've seen; document new ones here as they show up.**

| Code | Reason string              | What it means                                                  | Correct response                                              |
|------|----------------------------|----------------------------------------------------------------|---------------------------------------------------------------|
| 1000 | normal                     | Zello closed gracefully (rare — usually paired with another cause) | Exponential backoff                                       |
| 1006 | abnormal                   | TCP-level drop, no WSS close frame                             | Exponential backoff                                           |
| 3001 | unable to verify           | Fatal auth error. Credentials wrong OR JWT expired OR network name mismatch | **STOP. Do not auto-reconnect.** Log clearly, wait for operator fix + daemon restart |
| 3003 | kicked                     | Another session logged in with the same username on the same channel | Wait **30 s**, then retry once. Reset attempt counter — don't count kicks against exponential |

**Never observed but plausible per Zello docs:**
- 3002 — server closing (maintenance)
- 3004 — banned
- 3005 — protocol error (probably means we sent something malformed)

Add these to `ZelloUpstream::onClose` when they appear.

## The 22:37 CDT incident (2026-06-30) — reference case study

**Trigger:** two `systemctl restart newui-zello-proxy` calls within 3 min for two consecutive deploys (Phase 99ag + 99ah). This *alone* should not have been enough to hit Zello's rate limit — the primary cause was actually…

**Actual root cause:** concurrent-connect bug. The `on('close')` handler and the promise's error handler could BOTH schedule a reconnect for the same connect attempt, and there was no guard preventing a second `connect()` while the first was still in-flight. Result:

1. Deploy 99ah restarts daemon → fresh connect A
2. Connect A auths, gets kicked by (something else — maybe our own reconnect from earlier) with 3003
3. Both `on('close')` AND the pending reconnect timer fire → 2 concurrent connects
4. Both succeed and authenticate as `eric dispatch` → Zello kicks one, we reconnect from that, another kick, another reconnect
5. ~10 WSS connects in 5 seconds → 429

**Log evidence (line ordering shows the race clearly):**
```
[22:37:06] Connection closed: 3003 kicked            ← close A
[22:37:06] Reconnecting in 1s (attempt 1)            ← scheduled from close A
[22:37:06] Got HTTP response, validating handshake   ← connect B finishing
[22:37:06] WebSocket connected
[22:37:06] Sending logon as 'eric dispatch'
[22:37:06] Connection closed: 1000                   ← connect B closed
[22:37:06] Reconnecting in 1s (attempt 1)            ← scheduled from close B
[22:37:07] Connecting to wss://…    (×2 lines same second — two concurrent connects)
```

**Fix (Phase 99ai):** 5 layers of defense in `ZelloUpstream.php`:
1. `$connecting` flag prevents `connect()` reentry
2. `$reconnectTimer` check prevents timer stacking
3. 3003 kick → 30 s fixed wait, reset attempts
4. 3001 fatal-auth → `$fatalAuth` latch, no auto-retry
5. 429 detection → 15-min cool-off + widget status
6. Proactive rate limiter: refuse to connect if >3 attempts in last 60 s

**Cool-off duration: 15 min.** Empirical for Zello — waiting less didn't clear, waiting more didn't help.

## Binary message type table

Zello multiplexes types on the same binary WSS frame, byte 0 = type:

| Type | Meaning                                                                          | Handled? |
|------|----------------------------------------------------------------------------------|----------|
| 0x01 | Audio packet (9-byte header: type, stream_id BE u32, packet_id BE u32)           | Yes — `ZelloUpstream::handleBinaryFrame` extracts stream_id + Opus and forwards to widget |
| 0x02 | Image (9-byte header: type, image_id BE u32, image_type BE u32 (1=full, 2=thumb)) | **Phase 100 (2026-07-01) — full support.** RX parsed by handleBinaryFrame → onImagePacket callback; ProxyApp buffers thumb + full by image_id, writes both to `cache/zello-images/`, broadcasts `image_message` to widgets. TX via `send_image` JSON → wait for image_id ACK → two binaries. |
| 0x03 | Reserved / observed as text                                                      | Log + drop |

**Never crash or close on unknown type.** Zello interprets client-side protocol errors by closing with 3001 fatal-auth, which trashes your credential state and burns the rate budget.

## Image messages (Phase 100, 2026-07-01)

Full end-to-end image support: mobile → widget (RX) and clipboard paste → mobile (TX).

**Receive flow:**
1. Zello sends `on_image` JSON with `channel, from, message_id, width, height, ct` (note: field is `message_id` in on_image but matches `image_id` in the binary frames — Zello's own documented inconsistency).
2. Zello then sends TWO binary frames, both starting with `0x02 + image_id BE u32 + image_type BE u32 + JPEG bytes`. **Thumbnail first (image_type=2), then full (image_type=1).**
3. Our proxy buffers all three by image_id in `$incomingImages[$id] = ['meta', 'thumb', 'full', 'started']`. When all three are present, `finalizeIncomingImage()` writes both JPEGs to `cache/zello-images/in_z_<id>_<ts>.jpg` + `.thumb.jpg`, calls `logMessage(message_type='image', media_url=…)`, and broadcasts `image_message` with the thumb as an inline `data:image/jpeg;base64,…` URI (thumbs are ~10-15 KB, base64 overhead is fine) plus the full URL for click-to-expand.
4. Widget renders inline in the message feed. Click opens a full-screen modal (95vw/95vh).

**Send flow:**
1. Widget's text input has a `paste` event handler. If clipboard contains an `image/*` item, the handler intercepts, otherwise plain-text paste falls through.
2. Widget draws the paste to a canvas, resamples to max 1600 px longest edge, encodes JPEG at quality 0.85 (full) and 0.75 (thumbnail at max 180 px longest edge).
3. Widget sends one JSON message: `{cmd:'send_image', channel, recipient, width, height, thumb_b64, full_b64}` — everything base64 in ONE frame, no interleaved binary from browser. Cost: ~33% base64 overhead. Cap: 1 MB full, 32 KB thumb (enforced in proxy).
4. Proxy calls `sendImageStart()` on ZelloUpstream (send_image JSON with content_length + thumbnail_content_length), stores `pendingImageSends[$seq]` with client_id + both binaries.
5. Zello responds `{seq, success:true, image_id: N}`. Proxy's `handleUpstreamEvent` matches the seq, calls `sendImageBinary($imageId, 2, $thumb)` then `sendImageBinary($imageId, 1, $full)`, saves both to `cache/zello-images/out_z_<id>_<ts>.jpg`, logs + broadcasts `image_message` with `direction='outgoing'`.
6. Widget's `image_message` handler renders the card. **Widget suppresses auto-play/auto-expand for `direction='outgoing'`** (same rule as voice loopback in Phase 99am — you don't want your own paste popping up a modal).

**Per-channel gate — `on_channel_status.images_supported`:**
- Zello includes `images_supported: boolean` on the `on_channel_status` event.
- We track it per-channel in `$channelImagesSupported`.
- On `send_image`, we pre-check `channelImagesSupported($channel)` and reject with an immediate error if false, rather than round-tripping to Zello.

**Codec:** JPEG only. Zello's API.md is explicit. Browser paste of `image/png` gets transcoded via canvas to JPEG before send. No PNG/WebP passthrough.

**Size limits observed from Zello's own reference:** thumbnails ~10-15 KB, full ~200 KB at 1279×959. Our proxy hard-caps 1 MB full / 32 KB thumb to guard the WS frame. Adjust upward if Zello ever exposes a documented ceiling.

**Storage layout:**
```
/var/www/newui/cache/zello-images/
    in_z_<image_id>_<ts>.jpg           (received, full)
    in_z_<image_id>_<ts>.thumb.jpg     (received, thumbnail)
    out_z_<image_id>_<ts>.jpg          (sent, full)
    out_z_<image_id>_<ts>.thumb.jpg    (sent, thumbnail)
    .dedup                              (SHA-256 hashes, one per line)
```

Owner `www-data:www-data`, 0644. No retention policy yet (matches audio cache — followup).

## Zello has no client-to-server message ACK — content-hash dedup required

**Verified 2026-07-01 against the Zello Channel API spec and JS SDK.**
Findings:

- The `logon` command carries `(auth_token, refresh_token, username, password, channels, listen_only, version, platform_type, platform_name, language, features)`. There is **no** `last_message_id`, `since_message_id`, `resume_from`, or any equivalent field. The client cannot tell the server "start after this ID."
- The complete client-to-server command surface is `(logon, start_stream, stop_stream, send_image, send_text_message, send_location)` plus a `keepalive` heartbeat. **There is no** `mark_read`, `message_ack`, or any per-message acknowledgment command.
- Same on Zello Consumer AND Zello Work — no product-tier difference for this.
- Not documented as intentional; just an omission. The API was clearly designed for voice PTT (where replay is meaningless) with image/text retrofitted.

**Consequence:** every undelivered image on the Zello side gets replayed on every reconnect. a beta tester's first test image accumulated 40+ duplicate rows on training over an afternoon before we noticed.

**Our fix — SHA-256 content dedup:** on each finalize, hash the full JPEG bytes; if the hash is in `/cache/zello-images/.dedup`, drop the message silently. The dedup log is persisted to disk so it survives proxy restarts. Cap 1000 entries. See `imageAlreadyDelivered` / `markImageDelivered` in `ZelloProxyApp.php`.

**Sources for the "no ACK" finding:**
- `github.com/zelloptt/zello-channel-api/blob/master/API.md`
- `github.com/zelloptt/zello-channel-api/blob/master/sdks/js/src/classes/session.js` (`doLogon()`)
- Issue search on `last_message_id`, `message ack`, `acknowledge`, `replay` — zero relevant results

## Codec header format (outgoing — proxy → Zello `start_stream`)

Zello expects a base64-encoded 4-byte header:

```
byte 0-1: sample_rate  (little-endian u16)
byte 2:   frames_per_packet (u8)
byte 3:   frame_size_ms (u8)
```

Plus a separate `packet_duration` JSON field.

**Phase 99al update (2026-07-01) — always send the COLLAPSED form:**

```php
// Correct — packet_ms in the frame-size byte, frames_per_packet=1
'codec_header'    => base64_encode(pack('v', 48000) . chr(1) . chr($packetMs)),
'packet_duration' => $packetMs,
```

The earlier version of this proxy tried to describe the real Opus internal layout by sending `(48000, N_frames_per_packet, single_frame_ms)`. Zello Work uses `frames_per_packet × frame_size_ms` for **playback scheduling**, so any lie in those two bytes shows up as a timing bug at the receiver. Meanwhile the Opus decoder itself reads each packet's actual frame layout from that packet's own TOC byte, so it doesn't need our header to describe it.

**For browser-originated audio** (MediaRecorder → us → Zello):
- `sample_rate = 48000` (always — MediaRecorder ignores your sample rate constraint)
- `frames_per_packet = 1` (always — collapsed form)
- `frame_size_ms = packet_ms` (real per-packet audio duration from `_opusPacketInfo()`)

`_opusPacketInfo()` in `ZelloProxyApp.php` returns the full struct (`frame_ms`, `frames_per_packet`, `packet_ms`, `toc_config`, `toc_frame_code`) for logging and internal use — we just collapse before sending to Zello.

**CRITICAL — cover all 32 Opus configs in the TOC lookup table**. Chrome switches into CELT-only at higher bitrates and emits configs in the 16-31 range with 2.5, 5, or 10 ms per frame. The earlier table only had 10/20 ms rows, so CELT 2.5 ms packets defaulted to 20 ms → codec_header sent 20 ms when the actual audio was 10 ms → Zello scheduled packets 2× apart → half-speed playback (Phase 99al, observed as "99 packets/sec means real packet is 10 ms" in the log). Store the table in **tenths of ms** so 2.5 × N stays exact.

## Codec header format (incoming — Zello → proxy)

Zello sends the same 4-byte layout in the `on_stream_start` `codec_header` field. We parse it to configure our WebmStreamWriter (for live browser-side loopback playback) and OggOpusWriter (for stored history):

```php
$sr               = unpack('v', substr($decoded, 0, 2))[1];  // sample_rate LE
$framesPerPacket  = max(1, ord($decoded[2]));                // BYTE 2 — DON'T IGNORE
$frameDurationMs  = max(1, ord($decoded[3]));                // BYTE 3
$packetDurationMs = $framesPerPacket * $frameDurationMs;     // real per-packet audio ms
```

**Phase 99al bug + fix (2026-07-01):** the earlier parser only read byte 3 (frame_size_ms) and ignored byte 2 (frames_per_packet). For Zello mobile clients that pack multiple frames per packet, this made `packet_ms` 1/2 to 1/3 the real value. Consequences:
- **WebmStreamWriter cluster timestamps** were 1/N their real length → browser MediaSource buffered N seconds of decoded audio into 1 real second of timeline → **playback cut off after 3-5 seconds** of a 10-second incoming TX
- **OggOpusWriter granule** was 1/N → stored history playback ran at N× speed
- **Duration logs** were N× short

Zello Work often sends 16000 Hz sample rate from mobile clients. The incoming path *must* honor the header — do NOT hardcode 48000. Only the outgoing path is always 48000 (because that's what MediaRecorder emits).

## Debugging quick reference

**Before anything else — check the `[Proxy] Detected Opus layout:` line in the log.** It prints the TOC byte in hex plus the decoded config number and frame count code. This one line tells you:
- Whether the browser is emitting SILK/Hybrid/CELT (config number → RFC 6716 Table 2)
- Real per-packet duration
- Whether the packet is multi-frame

If this line is missing, you're on old code. Add the log line before doing anything else.

**"Why is my audio garbled to the other party?"** — Sample rate mismatch. Check that `start_stream` sent `sample_rate=48000` for browser-originated TX. Trace: `[Proxy] First real audio frame detected — sending start_stream to Zello` line in log; if `codec_header` shows anything but 48000 in byte 0-1, you're broken.

**"Why does the stored .ogg play at 1/3 speed?"** — OGG file's sample_rate = 16000 but audio data is 48 kHz. Check `OggOpusWriter` constructor first arg.

**"Why does the stored .ogg say 1.5 s for a 5 s Chrome TX?"** — Missing frame_count. Chrome packs 3 frames per packet; if OggOpusWriter's third arg is 20 (single frame) instead of 60 (packet), granule advances 1/3 as fast → duration reports 1/3 real.

**"Why does the stored .ogg say 20 s for a 10 s Firefox TX?"** — Assumed 20 ms/packet but Firefox emits 40 ms. Same fix.

**"Why does audio play at half-speed on Zello AND in the local loopback?"** (Phase 99al, 2026-07-01) — Almost certainly the CELT 2.5/5 ms configs are missing from your `_opusPacketInfo` table. Chrome switched into CELT at higher bitrate; your table defaults unknown configs to 20 ms; real packet is 10 ms; everything downstream stretches 2×. Look at the log's `TOC=0xNN, config=N` field — if config is in 16-31 and packet_ms=20, that's it.

**"Why does mobile TX cut off after 3-5 seconds when transmission was 10 seconds?"** (Phase 99al, 2026-07-01) — The incoming codec_header parser is ignoring byte 2 (frames_per_packet). Read both bytes and use `packet_ms = frames_per_packet × frame_size_ms` for WebmStreamWriter timing.

**"Why do I hear myself playing back after I release PTT?"** (Phase 99am, 2026-07-01) — The widget's `voice_message` handler is auto-playing the completed .ogg for the sender. Check `direction === 'outgoing'` and skip auto-play; keep the play button working for manual replay.

**"Why do two playback buttons play simultaneously if I click both?"** (Phase 99am, 2026-07-01) — Missing global playback lock in the widget. Wrap play in `acquirePlayback(myStopFn)` / `releasePlayback(myStopFn)` so a new play always stops the previous.

**"Why does my widget keep reconnecting?"** — Check the proxy log for a kick loop. If you see repeated `Connection closed: 3003 kicked` + `Reconnecting in 1s`, you don't have the concurrent-connect guard.

**"Why did I get a 429?"** — Ran out of connect budget. Check the log for how many connect attempts in the 60 s before the 429. If more than 3, you have a reconnect storm. Fix that; don't tune the cool-off.

**"Why is my proxy silently doing nothing after a 429?"** — The 15-min cool-off is active. Check the log for `[Upstream] Zello rate-limited (HTTP 429). Cooling off 900s until HH:MM:SS`. Wait for that clock to pass.

## Recovery procedures

**Zello 429 hit:**
1. Stop the proxy: `ssh training-ticketscad "sudo systemctl stop newui-zello-proxy.service"`
2. Wait 15 min from the 429 timestamp (check `/var/log/newui/zello-proxy.log`)
3. Restart the proxy
4. Watch the log for a clean auth OR another 429 — if another 429, wait longer

With Phase 99ai code deployed, the proxy will auto-cool-off — stopping the service is optional but faster.

**Fatal auth latched:**
1. Check Settings → Zello Network Radio for correct credentials and network name
2. Restart the proxy — `$fatalAuth` is per-process state, resets on fresh daemon boot
3. If it re-latches, credentials are actually wrong

**Widget flapping without proxy restart:**
- Almost certainly the concurrent-connect bug (Phase 99ai). Confirm the deploy has the guard.
- If it doesn't (older code): expected behavior; upgrade the proxy.

## Related files in this repo

- Reference implementation: `newui-dev/newui/proxy/ZelloUpstream.php`
- Frame decoder + codec header logic: `newui-dev/newui/proxy/ZelloProxyApp.php` — search for `_opusPacketInfo`
- Widget: `newui-dev/newui/assets/js/zello-widget.js`
- Global playbook: `~/.claude/skills/browser-audio-to-voice-service/SKILL.md`
- Companion doc: `newui-dev/newui/docs/DMR-CODEC-LESSONS.md`

## Origin

2026-06-30 Eric-beta session with a beta tester testing Zello Work end-to-end. Revised 2026-07-01 with the CELT 2.5/5 ms config lessons (Phase 99al), the codec_header collapse to `(48000, 1, packet_ms)` (Phase 99al), the incoming-side `packet_ms` parser fix (Phase 99al), and the widget UX rules — no-autoplay-own-TX + single-playback lock (Phase 99am). All findings documented within the same session that produced them. Update this doc whenever a new close code, a new failure mode, or a new browser Opus config is observed.
