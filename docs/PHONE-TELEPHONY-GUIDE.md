# Browser Phone & AllStar Relay (Phase 153)

**Audience:** administrators (setup) and dispatchers (the "Using it" sections).
**Status:** built and live-verified against a real Asterisk PBX and a real
mock AllStar relay node. Both are ordinary self-hosted Asterisk instances —
this feature is not a hosted telephony service and ships nothing pre-wired to
a real phone carrier.

## What it is

Two related capabilities, both built on the SAME idea — a browser-native
WebRTC phone, no separate softphone app to install:

1. **Browser calling.** Any workstation's browser can register directly
   against an Asterisk PBX (over WSS) and place or receive calls through a
   floating Phone widget, the same shape as the existing Zello/Radio
   widgets. A **general number** rings every registered direct-station
   workstation at once (a dispatch line — including whichever workstation
   places the call, since the dialplan doesn't exclude the caller); a
   **direct number** rings only the one workstation it's bound to.
2. **AllStar relay.** A button on an incident's detail page relays a short
   spoken summary of that incident to a second Asterisk instance — a stand-in
   for a real AllStarLink ham-radio node — genuinely synthesizing speech,
   delivering it, and playing it out there. It simulates a dispatcher calling
   a responder over ham radio to page them about an incident, and reports
   back real, measured proof of delivery (duration + loudness), not a bare
   success flag.

Full design rationale: `specs/phase-153-webrtc-telephony-allstar/{spec.md,plan.md}`.

## Setting up phone extensions

**Settings → Communications & Integrations → Phone Extensions** (or
`phone-extensions-admin.php` directly), gated on `action.manage_calls` —
the same permission Phase 149's Inbound Calls admin page uses (Super Admin
and Org Admin by default).

1. **PBX Connection panel** at the top — set the PBX's **WebSocket URL**
   (e.g. `wss://your-asterisk-host:8089/ws`) and, optionally, the general
   number for display. This is a single install-wide setting; every browser
   reads it to know where to connect.
2. **New Extension** — pick a 2-10 digit number, a label, and whether it's
   the shared **general number** (check the box) or a **direct-station
   number** (leave unchecked). Creating an extension mints a SIP
   username/password shown **exactly once** — copy it immediately (this is
   the same one-time-reveal convention as every other credential-minting
   panel in this app, e.g. SIP trunk bearer tokens). The actual Asterisk-side
   endpoint (its own `pjsip.conf` entry, or equivalent) has to exist
   separately on the PBX with matching credentials — this page manages
   TicketsCAD's OWN record of "which extension is this," not the PBX
   itself.
3. **Binding a direct number to a workstation** — paste that workstation's
   own token (visible on its Console page, or inside the Phone widget's own
   "not bound yet" panel) into the extension's **Workstation Token** field.
   This targets ringing notifications at the operator currently logged in
   at THAT specific browser/workstation instead of broadcasting to
   everyone. An extension with no token bound just sits configured but
   inactive.

## Using the Phone widget

Open it from the phone icon in the navbar (next to Zello/Radio), gated on
`screen.call_queue` — the same "who works the phones" permission Phase 149
already established.

- **Not bound yet?** The widget shows its own workstation token with a copy
  button — hand that to an administrator to bind on the Phone Extensions
  page above.
- **Bound:** the widget registers against the PBX automatically. The status
  dot and footer text report registration state honestly — green/"Registered
  as 101" once connected, red/"Disconnected from PBX" otherwise.
- **Dialing:** use the on-screen keypad or type a number, then Call — or tap
  the general-number shortcut button if one is configured.
- **Incoming calls** show a banner with Answer/Decline; an active call shows
  a timer, mute, and hang-up.
- Detaches into its own window exactly like the Zello/Radio widgets (the
  Detach button in its header), and its open/closed state survives page
  navigation the same way theirs do.

### The one-time certificate step

A self-hosted Asterisk PBX almost always uses a **self-signed TLS
certificate** for its WSS signaling. The first time any given browser
profile tries to register, the WebSocket connection will silently fail
(shown honestly as "Disconnected from PBX") until that browser has been
told to trust the certificate. **Fix: visit the PBX's plain HTTPS URL once**
(same host/port as the WSS URL, e.g. `https://your-asterisk-host:8089/`)
and click through the browser's security warning (Chrome: Advanced →
Proceed). This is a one-time, per-browser-profile step — do it before
troubleshooting anything else if a workstation's Phone widget won't
register.

## Using the AllStar Relay

**On an incident's detail page**, dispatchers/anyone holding
`action.dispatch_unit` see an **AllStar Relay** button in the toolbar. Click
it and the button disables itself with a progress message for roughly
20-30 seconds — this is a REAL delay, not a loading spinner masking
instant work: the message is genuinely synthesized to speech, delivered to
the relay node, and played out there in real time while a recorder
captures it. When it completes, a toast reports the actual **duration** and
**loudness (RMS)** measured from the recording that came back — concrete,
checkable numbers, not a bare "sent" confirmation. A short/quiet result is
flagged as inconclusive rather than claimed as success.

The spoken message is built automatically from the incident's case number,
type, location, and scope/description — there's no manual message-editing
UI yet; that's a natural next increment if wanted.

## How it fits together (for anyone extending this)

- `inc/phone-extensions.php` / `api/phone-extensions.php` — extension CRUD,
  PBX connection settings, and the `my_extension` lookup every browser's
  Phone widget calls to resolve its own bound extension + credentials by
  its workstation token (Phase 152's `console-workstation.js` convention).
- `assets/js/phone-widget.js` + `inc/phone-widget-template.php` +
  `assets/css/phone-widget.css` — the floating widget itself, using
  `assets/vendor/jssip/` (a locally-built bundle — JsSIP's npm package
  ships no browser/UMD build at all).
- `inc/allstar-relay.php` / `api/allstar-relay.php` — the relay: message
  building, speech synthesis (this app's own TTS engine registry first,
  falling back to `espeak-ng` run on the relay node itself when no engine
  is configured), delivery over SSH/SCP, an AMI Originate call into the
  relay node's dialplan, and a pure-PHP WAV parser to verify the resulting
  recording.
- `inc/inbound-calls.php`'s `_p153_resolve_extension()` /
  `_p153_resolve_constituent()` — when a call rings a registered extension
  (general or direct), the caller's number is resolved to a real
  Constituent record automatically (creating one if none matches), reusing
  Phase 149's existing New-Incident caller-history prefill with zero
  additional client-side work.

## Out of scope (this phase)

- **No real telephony-carrier integration.** Both PBX instances used to
  build and verify this feature are self-hosted Asterisk boxes on a private
  network — there is no SIP trunk to the public phone network, no
  toll-free/DID provisioning, and no billing integration. Wiring a real
  carrier trunk into the same Asterisk PBX is a separate, carrier-specific
  task that doesn't change anything on the TicketsCAD side.
- **No real AllStarLink node emulation.** The relay target is a plain
  Asterisk dialplan (Answer → record → play → hang up), not `app_rpt` or
  real RF hardware — deliberately, since there's no physical radio to back
  a fuller emulation and the plain version already proves the thing that
  matters (TicketsCAD can genuinely originate and verify delivery of a
  real-time audio transmission to a separate instance).
- **No manual message editing** for the AllStar relay (see above).
- **The general number doesn't exclude the caller.** `Dial(PJSIP/101&PJSIP/102&PJSIP/103,25)`
  rings every registered direct-station extension unconditionally — if the
  workstation placing the call happens to be one of them, its own phone
  rings too. A natural refinement (exclude the calling extension from the
  Dial string) is straightforward to add later but isn't built yet.
- **No call recording/archive** for browser-to-browser Phone widget calls
  (Zello and DMR both have an archive; this doesn't yet).
- **No encryption beyond standard WSS/TLS + SRTP** — nothing here changes
  this project's existing HTTPS/TLS posture; see
  `docs/security/architecture.md` for the project-wide cryptographic
  inventory.
