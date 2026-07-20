# Glossary

Every term used in TicketsCAD NewUI, defined.

**Audience:** anyone — users, admins, developers.

**Format:** alphabetical. Look up the word you don't recognise. Cross-references link to the doc that explains the term in depth.

**Also see:** [Legacy → NewUI term map](LEGACY-TO-NEWUI-TERMS.md) for v3.44 terms that were renamed.

---

## Quick index by topic

- **People & organisations**: [Account](#account), [Allocates](#allocates), [Constituent](#constituent), [Group](#group), [Member](#member), [Organisation (org)](#organisation-org), [Personnel](#personnel), [Personal resource](#personal-resource), [Responder](#responder), [Roster](#roster), [Team](#team), [User](#user)
- **Operations & dispatch**: [Assignment](#assignment), [Available](#available), [Cleared](#cleared), [Dispatch](#dispatch), [Incident](#incident), [In-type](#in-type), [Major incident](#major-incident), [Mayday](#mayday), [PAR](#par-personnel-accountability-report), [Severity](#severity), [Signal](#signal), [Standby](#standby), [Status](#status), [Ticket](#ticket), [Unit](#unit)
- **Comms**: [AMBE](#ambe), [APRS / APRS-IS](#aprs--aprs-is), [Broadcast](#broadcast), [Broker](#broker), [Channel](#channel), [Chat](#chat), [DigestPlay](#digestplay), [Direct message (DM)](#direct-message-dm), [DMR](#dmr), [DVSwitch](#dvswitch), [Forward (routing)](#forward-routing), [HMAC](#hmac), [md380-emu](#md380-emu), [MeshCore](#meshcore), [Meshtastic](#meshtastic), [MMDVM_Bridge](#mmdvm_bridge), [Notification tray](#notification-tray), [ODMRTP](#odmrtp), [OwnTracks](#owntracks), [Piper](#piper), [Recipient](#recipient), [Route](#route), [Routing engine](#routing-engine), [SSE](#sse-server-sent-events), [Talkgroup (TG)](#talkgroup-tg), [TTS / STT](#tts--stt), [USRP](#usrp), [Vosk](#vosk), [Webhook](#webhook), [Whisper / faster-whisper](#whisper--faster-whisper), [Winlink](#winlink), [Zello](#zello)
- **Map & location**: [Binding (location)](#binding-location), [Browser GPS](#browser-gps), [Clock-in](#clock-in), [Geofence](#geofence), [Inheritance (location)](#inheritance-location), [Location provider](#location-provider), [Location resolver](#location-resolver), [Map markup](#map-markup), [Mileage log](#mileage-log), [Overlay category](#overlay-category), [Personal resource](#personal-resource), [Place](#place), [Route playback](#route-playback), [Stale marker](#stale-marker)
- **Security & RBAC**: [Audit log](#audit-log), [Backup code](#backup-code), [Bridge token](#bridge-token), [CJIS](#cjis), [CSRF](#csrf), [Encryption key](#encryption-key), [Field encryption](#field-encryption), [Force password change](#force-password-change), [Grant](#grant), [is_admin()](#is_admin), [Lockout](#lockout), [Password policy](#password-policy), [Permission](#permission), [RBAC](#rbac), [Remember device](#remember-device), [Role](#role), [Session](#session), [Super (admin)](#super-admin), [TFA / 2FA / TOTP](#tfa--2fa--totp), [Trusted CIDR](#trusted-cidr)
- **ICS forms**: [ICS-202](#ics-202), [ICS-205](#ics-205), [ICS-205A](#ics-205a), [ICS-206](#ics-206), [ICS-213](#ics-213), [ICS-213RR](#ics-213rr), [ICS-214](#ics-214), [ICS-214A](#ics-214a), [ICS-221](#ics-221)
- **Codebase concepts**: [Allocates](#allocates), [Captions](#captions), [db_query / db_fetch_*](#db_query--db_fetch_), [Migration](#migration), [Schema drift](#schema-drift), [Self-healing INSERT](#self-healing-insert), [`safe_*` wrappers](#safe_-wrappers)

---

## A

### Account
A user's login record in the [`user`](#user) table — credentials, role assignment, profile settings, must-change-password flag. Distinct from a [Member](#member) (the personnel-roster record); an Account is *attached to* a Member via `user.member_id`.

### Allocates
The legacy v3 visibility table (`allocates`) that decides which user [Groups](#group) may see which resource (incident, responder, facility). Each row is `(resource_id, type, group)`. NewUI uses RBAC as the primary gate but still falls back to `allocates` when an [RBAC](#rbac) permission doesn't explicitly grant access. See [ACCESS-CHAIN.md](ACCESS-CHAIN.md).

### AMBE
*Advanced Multi-Band Excitation* — the proprietary voice codec used by [DMR](#dmr) and other digital-voice radio standards. AMBE frames are tiny (9 bytes per 20 ms frame) but require a licensed codec implementation. TicketsCAD transcodes AMBE↔PCM via [md380-emu](#md380-emu) so software speech engines like [Vosk](#vosk) and [Piper](#piper) can talk to a DMR talkgroup.

### Analog_Bridge
A daemon from the DVSwitch project that translates between digital-radio protocols (DMR, P25, NXDN, YSF) and a [USRP](#usrp) PCM stream. TicketsCAD's [`bridge.py`](../services/dvswitch/bridge.py) talks USRP-PCM to Analog_Bridge on the audio side. See [DVSWITCH-ADMIN-GUIDE.md](DVSWITCH-ADMIN-GUIDE.md).

### APRS / APRS-IS
*Automatic Packet Reporting System.* Amateur-radio position-reporting protocol. **APRS-IS** is the internet backbone. TicketsCAD listens to APRS-IS via a persistent Python service (see [APRS-LISTENER-SETUP.md](APRS-LISTENER-SETUP.md)) and stores received positions as `location_reports` rows.

### Assignment
A row in the `assigns` table linking a [Responder](#responder) to a [Ticket](#ticket). Created when a dispatcher assigns a unit; cleared when the unit is released. PAR cycles count active assignments.

### Audit log
The `audit_log` table — every state-changing action gets a row. Categories: `auth`, `data`, `admin`, `comms`, `security`. See [AUDIT-LOG-REFERENCE.md](AUDIT-LOG-REFERENCE.md).

### Available
A [Status](#status) marker meaning the responder is fit for assignment. The "default" status when not on a call.

---

## B

### Backup code
A single-use 8-digit code issued during [TFA](#tfa--2fa--totp) enrollment. Eight codes per user; each can be used once to log in if the user loses their authenticator app.

### Binding (location)
A row in `unit_location_bindings` that ties a [Responder](#responder) to a specific [Location provider](#location-provider) instance (e.g. "unit-12 reports via OwnTracks token #34"). Determines whose feed updates the unit's position.

### Bridge token
A bearer-token credential held by a daemon (Meshtastic bridge, DVSwitch bridge) so it can POST to the corresponding TicketsCAD ingest endpoint. Server stores the SHA-256 hash; the daemon holds the plaintext in its env file. Minted once via the admin UI, revealed once, then the admin pastes it into the daemon config.

### Broadcast
A message sent to every recipient on a channel (`recipient='all'`) or to every enabled channel in the [Broker](#broker) (`broker_broadcast()`). Requires elevated permissions — see [`action.broadcast_alerts`](RBAC-GUIDE.md).

### Broker
The unified messaging fan-out layer ([`inc/broker.php`](../inc/broker.php)) that takes a `broker_send($channel, $message)` call and dispatches to the matching channel adapter (chat, SMTP, Slack, Twilio, BulkVS, Pushbullet, Meshtastic, DMR, Zello). The same call shape works for every channel.

### Browser GPS
Location provider using the W3C `navigator.geolocation` API on a phone/tablet/PC browser. No installation needed — the user grants permission in the browser. Backs the mobile self-clock-in flow.

---

## C

### Captions
The translation infrastructure: PHP `t($key, $default)` calls in source, JSON files under `captions/` (per-language seeds), database overlay in the `captions` table. See [I18N-GUIDE.md](I18N-GUIDE.md).

### Channel
**Comms sense:** a logical message bus identifier (`general`, `dispatch`, `incident-NN`, etc.) that the [Broker](#broker) routes within. **Code sense:** a row in `channels_enabled` for the in-app channel registry.

### Chat
The local in-database chat channel. Messages stored in `chat_messages`, delivered via [SSE](#sse-server-sent-events), addressable per-user (DM) or per-incident or to the `all` audience.

### CJIS
*Criminal Justice Information Services Security Policy.* The FBI-administered security policy that US dispatch / police-adjacent systems must comply with. See [CJIS-POSTURE.md](CJIS-POSTURE.md) for TicketsCAD's mapping.

### Cleared
A terminal [Status](#status) for an [Assignment](#assignment) — unit is no longer engaged on the incident. Sets `assigns.clear` to a non-zero value (legacy schema uses string format).

### Clock-in
The act of a [Member](#member) marking themselves "on-duty". Triggers creation (or activation) of a [Personal resource](#personal-resource) unit so the dispatcher can see and assign them.

### Constituent
A non-user contact record in the `constituents` table — a member of the public, a frequent caller, or a community contact. Searchable by `?reference=` lookup from the new-incident form to auto-fill caller info.

### CSRF
*Cross-Site Request Forgery.* TicketsCAD defends with per-session tokens via `csrf_token()` / `csrf_verify()`. The token rotates on login (see [SECURITY-POLICY.md](SECURITY-POLICY.md)).

---

## D

### `db_query` / `db_fetch_*`
PDO helpers in [`inc/db.php`](../inc/db.php). Always use parameterised queries via these — never string-concatenate user input into SQL. `db_query` for INSERT/UPDATE/DELETE, `db_fetch_one` / `db_fetch_all` / `db_fetch_value` for SELECT.

### DigestPlay
A BrandMeister-published reference C implementation showing how to connect to BrandMeister via [ODMRTP](#odmrtp). The ODMRTP branch of [github.com/BrandMeister/DigestPlay](https://github.com/BrandMeister/DigestPlay/tree/ODMRTP) is what TicketsCAD studied for protocol details. GPL-3.

### Direct message (DM)
A [Chat](#chat) message with a numeric `recipient` field equal to a single user_id. Visible to sender and recipient only. SSE delivery is scoped; REST history endpoint was scoped in Phase 73u.

### Dispatch
The act of sending a unit to an incident. **Verb:** dispatcher assigns a unit. **Noun:** the unit's first response.

### DMR
*Digital Mobile Radio* — an open ETSI standard for digital two-way radio. Voice uses the proprietary [AMBE](#ambe) codec. TicketsCAD bridges DMR via the [DVSwitch](#dvswitch) stack.

### DVSwitch
A suite of open-source daemons ([Analog_Bridge](#analog_bridge), [MMDVM_Bridge](#mmdvm_bridge), [md380-emu](#md380-emu)) that bridge digital-radio protocols. Lives on VM `dvswitch-01` in TicketsCAD's setup. See [DVSWITCH-ADMIN-GUIDE.md](DVSWITCH-ADMIN-GUIDE.md).

---

## E

### Encryption key
TicketsCAD uses three key types:
1. **TFA key** — 32 random bytes at `../keys/tfa.key`, encrypts TOTP secrets + backup codes.
2. **RSA keypair** — at `../keys/rsa-public.pem` / `../keys/rsa-private.pem`, used for HTTP-deployment field encryption.
3. **AES-GCM session keys** — hybrid wrap inside RSA-protected blobs (`ENC2:` format).

See [SECURITY-POLICY.md](SECURITY-POLICY.md).

### EventBus
The browser-side counterpart to [SSE](#sse-server-sent-events) — a JavaScript helper ([`assets/js/event-bus.js`](../assets/js/event-bus.js)) that subscribes to the SSE stream and re-publishes events to local listeners. Auto-reconnects with backoff.

---

## F

### Field encryption
Encrypting individual form fields client-side (Web Crypto API) before they're posted to the server, for installations on plain HTTP (no TLS). Encrypted blobs are stored with the `ENC:` (legacy RSA-only) or `ENC2:` (RSA-wrapped AES-GCM) prefix.

### Force password change
The `must_change_password` flag on the `user` row. When set, the user is restricted to `profile.php` until they change their password. Set on new-user creation and after admin reset. See [SECURITY-POLICY.md](SECURITY-POLICY.md).

### Forward (routing)
The act of the [Routing engine](#routing-engine) taking a message that arrived on one [Channel](#channel) and re-sending it on another, with optional transforms (priority override, prefix). Loop-prevented via `_is_routed_forward` flag + `_route_depth` counter (max 5 hops).

---

## G

### Geofence
A polygon or circle drawn on the map, paired with an alert policy. When a unit's position crosses the boundary, a notification fires (`geofence:enter` / `geofence:exit`). Implemented in [`inc/geofence.php`](../inc/geofence.php) using ray-casting.

### Grant
A row in `user_roles` that gives a user a [Role](#role). Can have an `expires_at` timestamp for time-bound delegation (Phase 14). Expired grants are removed by the `rbac_expire_due_grants()` cron.

### Group
A legacy v3 organisational scope. A [User](#user) can belong to multiple groups via `user.groups` (semicolon-separated). The [Allocates](#allocates) table joins groups to resources. NewUI continues to honour groups for the per-resource visibility filter, alongside RBAC permissions.

---

## H

### HMAC
*Hash-based Message Authentication Code.* TicketsCAD signs outbound [Webhook](#webhook) deliveries with HMAC-SHA256 using a per-webhook secret. The receiver verifies the `X-Webhook-Signature` header before trusting the payload. See [WEBHOOKS-INTEGRATOR-GUIDE.md](WEBHOOKS-INTEGRATOR-GUIDE.md).

### Hint
Legacy v3 term — see [Signal](#signal). The `hints` table was renamed to `codes` in some installations; queries fall back to either.

---

## I

### ICS-202
Incident Objectives form. ICS = Incident Command System (US/NIMS).

### ICS-205
Incident Radio Communications Plan.

### ICS-205A
Communications List (supplement to 205).

### ICS-206
Medical Plan.

### ICS-213
General Message form. The most-used ICS form. TicketsCAD can export ICS-213 as Winlink-compatible XML for transmission over amateur radio.

### ICS-213RR
Resource Request Message form.

### ICS-214
Activity Log form. Personal ICS-214 entries are auto-generated from PAR acks (Phase 26A).

### ICS-214A
Individual Activity Log form.

### ICS-221
Demobilization Check-Out form.

### Incident
The canonical name for what gets dispatched on. Stored in the `ticket` table for legacy schema reasons. **Use "incident" in user-facing text.** Equivalent legacy term: [Ticket](#ticket).

### Inheritance (location)
The mechanism by which a [Responder](#responder) (unit) gets its position from the [Personnel](#personnel) currently assigned to it. If alice has OwnTracks running and is assigned to Engine 1, Engine 1's resolved position is alice's OwnTracks position.

### In-type
Incident type. Row in `in_types` defining a category of incident (medical, fire, traffic, etc.) with associated protocol text, default severity, color, and PAR cadence override. The new-incident form dropdown reads from this table.

### `is_admin()`
The canonical PHP check for "is the current user an administrator?" Returns true if the user has a role with `is_super=1` or holds `action.manage_config`. **Use this instead of `$_SESSION['level'] <= 1`** — Phase 12 deprecated the legacy level integer.

---

## L

### Lockout
After N consecutive failed login attempts (default 5) within a window (default 5 min), an account is locked for a duration (default 15 min). Configured in `settings.login_lockout_*`. See [SECURITY-POLICY.md](SECURITY-POLICY.md).

### Location provider
A row in `location_providers` representing one source of position data (e.g. `owntracks`, `aprs`, `meshtastic`, `dmr`, `internal`, `browser_gps`). Multiple [Bindings](#binding-location) per provider, each tying a provider feed to a specific responder.

### Location resolver
The function ([`inc/location-resolver.php`](../inc/location-resolver.php) `unit_resolved_location()`) that asks "what is the current position of unit N?" It walks the bindings by priority and returns the freshest report within the configured staleness window.

---

## M

### Major incident
An [Incident](#incident) flagged as "large enough to coordinate as a campaign" — typically with multiple linked sub-incidents and command structure. Tracked in `major_incidents`.

### Map markup
A user-drawn shape on the map (polygon, polyline, circle, marker). Stored in `mmarkup` (legacy table name retained). Can be categorised (`overlay_categories`) and toggled in groups.

### Mayday
Emergency status. A unit declaring mayday triggers a global alarm + broadcast message. Auto-triggered by selected statuses (e.g. "Officer Down").

### md380-emu
An ARM/Linux binary that runs the actual MD-380 portable-radio firmware in QEMU and exposes the [AMBE](#ambe) codec. TicketsCAD's [DVSwitch](#dvswitch) bridge uses it to decode RX AMBE frames to PCM for [Vosk](#vosk) STT and to encode [Piper](#piper) TTS output back to AMBE for TX. From [travisgoodspeed/md380tools](https://github.com/travisgoodspeed/md380tools/wiki/MD380-Emulator).

### Member
A row in the `member` table — a person on the personnel [Roster](#roster). May or may not have a linked login [Account](#account). Distinct from a [Responder](#responder) (the dispatchable unit). One Member can be one personal-resource Responder.

### MeshCore
Alternative LoRa mesh firmware/protocol, supported alongside [Meshtastic](#meshtastic) on the same mesh-bridge node. Both run via the bridge_v2 service on the meshbridge VMs.

### Meshtastic
A LoRa mesh-radio firmware and protocol. TicketsCAD has bridge VMs running a Python adapter that proxies Meshtastic↔HTTPS. See [MESH-BRIDGE-GUIDE.md](MESH-BRIDGE-GUIDE.md).

### Mileage log
A row in `mileage_log` recording a [Responder](#responder)'s odometer at trip start/end. Used for volunteer reimbursement.

### Migration
A schema-change script under [`sql/run_*.php`](../sql/). Idempotent: safe to run on any install at any time. Each script handles its own check-then-create logic.

### MMDVM_Bridge
The DVSwitch daemon that talks the wire protocol to a real DMR network (BrandMeister, TGIF, etc.) and forwards to/from [Analog_Bridge](#analog_bridge). Configured with your DMR ID and the talkgroup.

---

## N

### Notification tray
The bell-icon panel in the navbar that captures every [SSE](#sse-server-sent-events) event for visual review. Implemented in [`assets/js/notification-tray.js`](../assets/js/notification-tray.js).

---

## O

### ODMRTP
*Open DMR Terminal Protocol* — a BrandMeister-published UDP protocol (over their "Rewind" framing) that lets a software client connect directly to BrandMeister as a terminal, bypassing physical hotspot hardware. Authentication via SHA-256 challenge-response. Voice frames are AMBE mode 33 (27-byte triplets). See `specs/odmrtp-2026-06/research.md`.

### Organisation (org)
A row in `organisations`. Groups members under an agency. Permissions and visibility can be scoped per-org.

### Overlay category
A way to group [Map markups](#map-markup) — "Fire hydrants", "Restricted zones", "Hospital staging" — so dispatchers can toggle whole categories on/off. Rows in `overlay_categories`.

### OwnTracks
An open-source phone app that posts location to a server in MQTT or HTTP-direct mode. TicketsCAD has a custom HTTP-direct ingest path at [`api/location.php?provider=owntracks`](../api/location.php) with per-member token auth. See [OWNTRACKS-CONFIG-PUSH.md](OWNTRACKS-CONFIG-PUSH.md).

---

## P

### PAR (Personnel Accountability Report)
A periodic check-in cycle. Dispatcher (or an automated scheduler) initiates a PAR; each assigned unit must acknowledge within the cycle window or escalate. Configurable per-incident-type cadence (default 15 min). See [PAR-CHECK-GUIDE.md](PAR-CHECK-GUIDE.md).

### Password policy
Configurable knobs: minimum length, complexity (upper/lower/digit/symbol), history depth (no reuse of last N), rotation interval, lockout after failed attempts. Configured in Settings → Login Settings.

### Permission
A capability code (e.g. `screen.dashboard`, `action.create_incident`, `widget.map`, `field.view_patient`). 65 total. Granted to [Roles](#role), which are granted to users. Codes follow `<category>.<noun>` shape. See [RBAC-GUIDE.md](RBAC-GUIDE.md).

### Personal resource
A [Responder](#responder) auto-created for a [Member](#member) when they [Clock-in](#clock-in). Lets the member be tracked + assigned without needing pre-existing fleet vehicles. The personal resource is bound to the member's location providers (OwnTracks, browser GPS).

### Personnel
The members on the [Roster](#roster) — the people available for assignment. Some have logins ([Accounts](#account)), some don't.

### Piper
A neural text-to-speech engine — [github.com/rhasspy/piper](https://github.com/rhasspy/piper). TicketsCAD uses it on `dvswitch-01` to synthesise dispatcher-typed text into audio that gets keyed onto the DMR talkgroup. Voice: `en_US-lessac-medium` (22050 Hz, downsampled to 8 kHz inline).

### Place
A named map location with optional polygon. Stored in `places` (new) or repurposed `mmarkup` rows. Used as autocomplete options on the new-incident form.

---

## R

### RBAC
*Role-Based Access Control.* The Phase 11 / Phase 12 redesign that replaced the legacy `user.level` integer with a roles + permissions matrix. 6 default roles (Super Admin, Org Admin, Dispatcher, Operator, Read-Only, Field Unit), 65 permissions. Editable per-install. See [RBAC-GUIDE.md](RBAC-GUIDE.md).

### Recipient
The `recipient` field on a [Chat](#chat) or [Internal message](#chat) row. Values: `'all'` (broadcast), `'<user_id>'` (DM), or empty (treated as 'all').

### Remember device
A trusted-device cookie that bypasses [TFA](#tfa--2fa--totp) prompt on subsequent logins for some configurable window. Bound to a fingerprint of UA + Accept-Language + /24 IPv4 prefix. See [SECURITY-POLICY.md](SECURITY-POLICY.md).

### Responder
A row in the `responder` table — a dispatchable resource. Engines, ambulances, patrol cars, personal-resource units. Carries position, status, assignments, and bindings.

### Role
A named bundle of [Permissions](#permission). Six come pre-seeded; admins can create custom roles. Users get one or more roles via [Grants](#grant). See [RBAC-GUIDE.md](RBAC-GUIDE.md).

### Roster
The list of [Personnel](#personnel). The `roster.php` page is the admin view; members include FCC callsign, OwnTracks tokens, comm identifiers, team memberships.

### Route
A row in the `routes` table that defines a [Routing engine](#routing-engine) rule: source channel + direction + optional filters → destination channel(s) + optional transforms.

### Route playback
Time-scrubbing UI on the map ([`assets/js/route-playback.js`](../assets/js/route-playback.js)) that shows a unit's historical position trail.

### Routing engine
[`inc/router.php`](../inc/router.php) — evaluates [Routes](#route) against incoming/outgoing messages and forwards matches to destination channels with optional transforms. Loop-prevented via `_is_routed_forward` flag + `_route_depth` counter (Phase 73u hardening). See [ROUTING-ENGINE-REFERENCE.md](ROUTING-ENGINE-REFERENCE.md).

---

## S

### `safe_*` wrappers
Helper functions like `safe_fetch_all()` that wrap [`db_fetch_all`](#db_query--db_fetch_) in try/catch + return `[]` on error. Used for queries against optional schema columns. Phase 73f added `error_log()` to every wrapper so silent failures still surface in the Apache error log.

### Schema drift
When the SQL written by code doesn't match the live schema — columns the code expects don't exist, or vice versa. Caught proactively by [`safe_*` wrappers](#safe_-wrappers) returning empty results. Audited in Phase 73a.

### Stale marker
A unit's map marker shown dimmed because its location hasn't updated recently — a visual cue that the position may be out of date. Implemented in [`assets/js/units.js`](../assets/js/units.js) (Phase 26B): marker opacity is reduced based on time since last activity, with the last-update time shown on hover.

### Self-healing INSERT
A pattern where if an INSERT fails with "doesn't have a default value", the code auto-ALTERs the offending column to a type-appropriate default and retries. Used for legacy `member.field1..field65` columns. Reference impl: [`api/members.php`](../api/members.php) `fixLegacyDefaults()`.

### Session
PHP session backed by `$_SESSION` plus an `active_sessions` table row keyed by `session_id`. Marker `_sm_tracked=1` distinguishes tracked vs. fresh-create state. Force-logout DELETEs the row; `sm_is_session_valid()` rejects requests where the marker is set but the row is gone. See Phase 73aa.

### Severity
Numeric 1–5 (lower = higher priority). Auto-set from the [In-type](#in-type) on new-incident creation; dispatcher can override.

### Signal
Short code (e.g. "10-4", "Signal 7") with a fixed meaning. Stored in `codes` (or legacy `hints`) table. Quick-send chat shortcuts; renders as `<code>: <meaning>` in the chat stream.

### SSE (Server-Sent Events)
Unidirectional server→client streaming protocol. TicketsCAD's [`api/stream.php`](../api/stream.php) holds the connection open for 5 minutes and pushes JSON event lines. Backs real-time dashboard updates. Browser side: [EventBus](#eventbus). Scope-filtered per user via the `sse_events.visibility` columns.

### Standby
A non-terminal [Status](#status) — "ready but not actively engaged". Distinct from [Available](#available) (free for new dispatch) and [Cleared](#cleared) (terminal).

### Status
A [Responder](#responder)'s operational state, drawn from `un_status` (unit statuses). Configurable per install. Examples: Available, Enroute, On Scene, Cleared, Out of Service.

### Super (admin)
A [Role](#role) flag (`roles.is_super = 1`) that bypasses all permission checks. The seeded "Super Admin" role has this; custom roles can be flagged too. Last super admin can't be demoted/deleted.

---

## T

### Talkgroup (TG)
A DMR concept — a logical channel within a network. E.g. TG 9990 (parrot, echoes you back) or TG 31xxx (US statewide). Each TicketsCAD DMR channel binds to one talkgroup.

### Team
A row in `teams` grouping members for assignment + tracking. NIMS-aligned resource typing supported.

### TFA / 2FA / TOTP
*Two-factor authentication.* TicketsCAD uses RFC 6238 TOTP (30-second window, 6-digit code, ±1 period tolerance) plus 8 single-use [Backup codes](#backup-code). TOTP secrets and backup-code blobs are encrypted at rest with the [TFA encryption key](#encryption-key). Phase 73bb added one-time-use enforcement (replay protection within the slip window).

### Ticket
Legacy v3 name for [Incident](#incident). The table is still called `ticket`; user-facing text says "incident".

### Token
Generic name for a credential string. Specific kinds: [Bridge token](#bridge-token) (daemon → server), OwnTracks per-member token, `tfa_remember` device token.

### Trusted CIDR
An IP range (e.g. `10.0.0.0/24`) configured in Settings → Login Settings. Sessions coming from this range may bypass the [Remember device](#remember-device) requirement and/or stay logged-in longer. Used for office LANs.

### TTS / STT
*Text-to-speech* / *Speech-to-text.* TicketsCAD's DMR bridge uses [Piper](#piper) for TTS and [Vosk](#vosk) (with [Whisper](#whisper--faster-whisper) planned) for STT.

---

## U

### Unit
Alias for [Responder](#responder) in user-facing text. Same row, same table.

### USRP
*Universal Software Radio Peripheral* — both a hardware platform and a UDP wire format. The DVSwitch ecosystem uses the USRP UDP format to ship 8 kHz PCM voice between daemons (32-byte big-endian header + 320-byte payload per packet). See [DVSWITCH-ADMIN-GUIDE.md](DVSWITCH-ADMIN-GUIDE.md).

### User
A row in the `user` table — login credentials, role assignment, profile settings. Linked to a [Member](#member) (personnel record) via `user.member_id`.

---

## V

### Vosk
An open-source speech-to-text engine — [alphacephei.com/vosk](https://alphacephei.com/vosk). Streaming, on-device, no API key. TicketsCAD's DMR bridge uses the `vosk-model-small-en-us-0.15` model (40 MB) to transcribe incoming radio calls into the `dmr_messages.transcript` column.

---

## W

### Webhook
An outbound HTTP POST that TicketsCAD fires to a user-configured URL when a subscribed event happens (incident created, status changed, etc.). Signed with [HMAC](#hmac). Retried with exponential backoff. See [WEBHOOKS-INTEGRATOR-GUIDE.md](WEBHOOKS-INTEGRATOR-GUIDE.md).

### Whisper / faster-whisper
OpenAI's speech-to-text family. [faster-whisper](https://github.com/SYSTRAN/faster-whisper) is a re-implementation using CTranslate2. Higher accuracy than [Vosk](#vosk), heavier CPU. Queued as `DMR_WHISPER_MODEL` for the DVSwitch bridge as a complement to Vosk.

### Winlink
An amateur-radio email system. TicketsCAD can export [ICS-213](#ics-213) forms as Winlink-compatible RMS Express XML.

---

## Z

### Zello
A push-to-talk voice app. Registered as a stub channel — broker-registered, ready for the Zello Work API but not yet implemented end-to-end.

---

## Conventions and unresolved terms

- **Ticket vs. Incident** — interchangeable. Code uses `ticket`, UI uses "incident", we treat them as the same thing.
- **Hint vs. Signal vs. Code** — three names for the same thing across versions. NewUI says "Signal" in UI.
- **Member vs. Responder** — different entities. A Member is a person; a Responder is a dispatchable unit (which can be a vehicle OR a person via the [Personal resource](#personal-resource) mechanism).
- **Action vs. Activity** — legacy v3 used "Action" for the incident log entries. NewUI calls them "Activity" in UI; table is still `action`. See [LEGACY-TO-NEWUI-TERMS.md](LEGACY-TO-NEWUI-TERMS.md).
- **Level vs. Role** — legacy `user.level` integer is deprecated; use [Role](#role).

---

*If a term is missing from this glossary, file an issue or send a patch. Coverage should grow with the system.*
