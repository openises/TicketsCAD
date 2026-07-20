# Weather Alerts (NWS) — Administrator Guide

Phase 112 adds configurable **National Weather Service** watch/warning
awareness to TicketsCAD. Phase 1 (shipped) surfaces alerts in the
**notification tray with an audible chime** and a situation-screen banner.
Later phases add message routing (chat/SMS/email) and a spoken **radio
read-out** (DMR/Zello).

> **Off by default.** The whole feature ships disabled. Installs outside the
> United States never turn it on and never see any NWS traffic. Nothing about
> weather alerts is hard-coded — every behavior below is a setting.

Configure it at **Settings → Communications & Integrations → Routing & Policy →
Weather Alerts** (or `weather-alerts.php`). Requires the
`action.manage_weather_alerts` permission (Super Admin + Org Admin by default).

---

## 1. Turn it on (master switch)

1. Open the Weather Alerts admin page.
2. Set a **Contact email** — the NWS API rejects anonymous requests, so it needs
   a contact that identifies your install (e.g. `dispatch@youragency.org`). The
   feature refuses to poll with a blank contact and shows a warning on the
   System Health page.
3. Tick **Enable weather alerts** and **Save settings**.
4. Choose a **Poll interval** (default 60s, minimum 30s) and **Provider**
   (`NWS` — the seam exists for a future non-US provider, but Phase 112 ships
   NWS only).

With the switch off, the poller is completely inert: zero rows written, no UI.

---

## 2. Coverage areas — *where* you want alerts

Add one or more areas. Three kinds:

| Kind | Value | Use when |
|---|---|---|
| **State** | 2-letter code (e.g. `MN`) | You cover a whole state |
| **Zones / counties** | CSV of NWS UGC codes (e.g. `MNZ060,MNC053`) | You cover specific forecast zones/counties |
| **Point + radius** | lat, lng, radius (miles) | You cover an area around a point (e.g. "within 40 mi of downtown") |

Find your UGC zone codes at <https://www.weather.gov/gis/AWIPSShapefiles>, or
hit `https://api.weather.gov/points/{lat},{lng}` and read `properties.forecastZone`
/ `properties.county`.

**Minnesota example:** the **Load Minnesota example** button seeds two areas
(`MN statewide` and `Metro (40 mi)` around Minneapolis) and three rules — all
**inactive** for you to review. It is idempotent and safe to click once.

---

## 3. Routing rules — *what to do* with matching alerts

Each rule ties one area to one destination and only fires for alerts that clear
its filters.

| Field | Meaning |
|---|---|
| **Area** | Which coverage area this rule watches |
| **Target** | `tray` · `chat`/`sms`/`email` · `dmr` (bridge TTS read-out) · `zello` (proxy TTS read-out) — all shipped |
| **Target ref** | TG number, Zello channel name (blank = the proxy's default dispatch channel), or SMS list |
| **Min severity** | Floor: `Minor` < `Moderate` < `Severe` < `Extreme` |
| **Min urgency** | Floor: `Past` < `Future` < `Expected` < `Immediate` |
| **Event allow / deny** | Optional CSV substrings (e.g. `tornado`, `severe thunderstorm`). Blank allow = all events. |
| **Message types** | `Alert,Update` by default; add `Cancel` to also act on cancellations |
| **Mode** | `notify` · `auto_fire` · `operator_approve` (see below) |
| **Repeat on update** | Re-notify when NWS revises an alert (an *Update* message) |

A common setup: one **tray** rule at `Minor` so dispatchers see *everything*, plus
a **radio** rule at `Severe` so only warnings hit the air.

De-dup is automatic: an alert notifies a target once; an Update re-notifies only
if *Repeat on update* is on; the same revision never double-fires.

---

## 4. Radio read-out (Phase 3) — settings & SKYWARN framing

When a rule's target is **DMR** or **Zello**, the read-out settings apply:

- **Clear-channel wait** — seconds of quiet required before keying (reuses the
  DMR bridge's `wait_clear_channel`).
- **Callsign** — appended as the FCC §97.119 station ID on each amateur
  transmission. **Set this before enabling a DMR read-out on an amateur
  talkgroup.**
- **Max read-out** — long descriptions are truncated; the event, area, and
  instruction are always read.
- **Prefix / Piper voice** — the spoken lead-in and TTS voice.

> **SKYWARN / FCC note.** Relaying NWS watches and warnings during severe
> weather on amateur DMR (e.g. BrandMeister TG 3127) is **SKYWARN** — an
> established, encouraged amateur use, *not* prohibited §97.113 "broadcasting."
> To stay clean, the DMR target enforces a station-ID suffix, waits for a clear
> channel, and honors the severity floor so only warnings hit the air. Use
> `operator_approve` mode (routes through the Phase 85f approval queue) unless
> you are running attended "office hours." See the `claude-on-amateur-radio`
> skill for the full legal frame.

Non-amateur targets (Zello Work, public-safety DMR, SMS, chat, tray) have none
of the FCC constraints and honor only the rule's filters.

### Zello read-out specifics

A `zello` rule speaks the bulletin onto a Zello channel through the Zello
proxy's own TTS (a `zello_outbox` row with `kind='tts'` — the proxy
synthesizes with Piper and keys the Opus audio). Requirements:

- The Zello proxy must be running with TTS configured (`zello_tts_piper_bin`
  + `zello_tts_piper_voice` settings; `ffmpeg` on the proxy host — see
  `docs/TTS-DEPLOYMENT.md`).
- *Target ref* is the Zello **channel name**; blank uses the proxy's default
  dispatch channel.
- The spoken script carries **no callsign suffix** — Zello is an IP service.
  If your Zello channel is gatewayed onto an RF repeater, the gateway is the
  station and must do its own ID.
- The safety ladder is identical to DMR — `operator_approve` puts a card in
  the radio widget's approval queue (labelled `Zello: <channel>`), and
  `auto_fire` honors the same *Unattended keying* switch, **precisely because**
  Zello channels are routinely bridged onto repeaters.

---

## 5. Running the poller

The poller runs server-side (browsers never touch NWS directly — no CSP change,
central de-dup).

### Linux (training / Bloomington / any VM) — systemd timer (preferred)

`/etc/systemd/system/tickets-weather.service`:

```ini
[Unit]
Description=TicketsCAD weather-alert poll
After=network-online.target

[Service]
Type=oneshot
User=www-data
ExecStart=/usr/bin/php /var/www/newui/tools/weather_poll.php
```

`/etc/systemd/system/tickets-weather.timer`:

```ini
[Unit]
Description=Poll NWS weather alerts every minute

[Timer]
OnBootSec=60
OnUnitActiveSec=60
AccuracySec=15s

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now tickets-weather.timer
```

Match the timer cadence to your **Poll interval** setting. Or use cron:

```cron
* * * * * php /var/www/newui/tools/weather_poll.php >/dev/null 2>&1
```

Test by hand: `php tools/weather_poll.php --verbose` (or `--dry-run` to evaluate
without notifying).

### Shared hosting (no cron) — page-load catch-up

`api/weather-poll.php` self-throttles to your poll interval; a dispatcher's
browser (or a monitoring ping) can hit it on a heartbeat. On cron/systemd
installs it simply reports `throttled`, so it is harmless everywhere.

---

## 4a. Recipe: automated repeater alerts (SKYWARN-style)

The common repeater-owner pattern — "announce each NWS Warning for my counties
on my talkgroup, once, automatically." Works for any US area:

1. **Coverage area** — Add area → kind **Zones/counties** → enter your state
   code and click **Pick counties…** — check the counties you serve (the list
   comes from NWS, so this works anywhere in the country). E.g. the Twin Cities
   metro: Hennepin, Ramsey, Anoka, Dakota, Washington, Scott, Carver.
2. **Rule** — Add rule → that area → target **DMR read-out** → your talkgroup →
   min severity **Severe** → *Event allow* quick-pick **Warnings only** (or pick
   specific types: Tornado, Svr T-storm, Flash Flood, …) → *Message types* =
   `Alert` only and **Repeat on update OFF** for strictly one announcement per
   warning issued (leave Update on if you also want re-announcements when NWS
   revises a warning) → mode **Auto-fire**.
3. **Callsign** — set the licensee's callsign in the read-out settings; every
   transmission signs off "<CALLSIGN> clear." per §97.119.
4. **Enable unattended keying** — flip *Unattended keying (auto-fire)* to
   allowed. Until you do, auto-fire rules stay in the operator-approval queue.
5. **Poller** — make sure the cron/systemd poller is running (§5) so alerts are
   picked up within a minute of issuance.

De-duplication is automatic: the dispatch ledger guarantees a given warning
fires a given rule **once** per revision, even across poller restarts.

> **Licensee note.** Automated weather announcements are an established amateur
> practice, but §97.103 control-operator responsibility stays with the license
> holder — the auto-fire switch is per-install and OFF by default for exactly
> that reason. The severity floor, Warnings-only filter, and one-per-alert
> semantics are your guardrails against the channel becoming noise.

## 5a. Who's inside the warning? (geofence cross-reference)

When a warning carries a storm polygon (tornado and severe-thunderstorm warnings
do), TicketsCAD cross-references it against **live unit positions** and — if an
active event is set — the event's **zone geometry**:

- The notification names the units inside ("Units inside: Alpha, Delta").
- The **situation map** draws the warning polygon in its severity colour
  (red = Extreme, orange = Severe, dashed outline); clicking it lists the units
  inside and the zones affected, recomputed live as units move.
- Toggle: **Units inside alert polygons** on this settings page (default on).
  It uses the same ray-casting engine as geofencing and the same location data
  the unit map already shows — no extra configuration.

## 6. Test tools

| Button | What it does |
|---|---|
| **Send test alert to tray** | Injects a synthetic Severe MN alert so you can confirm the tray + chime fire (honors the master switch — nothing fires if off) |
| **Dry-run live NWS** | Fetches real NWS alerts and reports what *would* match — writes/emits nothing |
| **Poll now (live)** | A real poll right now (needs the switch on + a contact set) |
| **Load Minnesota example** | Seeds Eric's MN areas + rules, all inactive |

---

## 7. How it fits the existing system

Weather alerts reuse infrastructure rather than duplicate it: the notification
tray + audio-alert engine (Phase 1), the message router `inc/router.php`
(Phase 2), and the DMR `wait_clear_channel` + Piper TTS + Phase 85f operator
queue (Phase 3). The only genuinely new pieces are the server-side poller
(`inc/weather_provider_nws.php` + `inc/weather_alerts.php`), the config store
(`weather_alert_areas` / `weather_alert_rules` / `weather_alerts` /
`weather_alert_dispatch`), and this admin page.

Every configuration change and every radio read-out is written to the audit log
under the `weather` category.
