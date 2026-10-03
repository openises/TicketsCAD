# Browser Phone & AllStar Relay Test (Phase 153, updated Phase 155)

**Audience:** administrators (setup) and dispatchers (the "Using it" sections).

## What it is

Two related capabilities. Neither ships pre-wired to a phone carrier, and
neither is a hosted service: both need an ordinary self-hosted Asterisk server
that you set up.

1. **Browser calling.** A workstation's browser registers directly with an
   Asterisk PBX (over a secure WebSocket) and places or receives calls through a
   floating **Phone** widget or a small standalone **Phone window** — no
   separate softphone program to install. A **general number** rings every
   registered direct-station workstation at once (including the workstation that
   places the call, because the shipped dial plan does not exclude the caller);
   a **direct number** rings only the one workstation it is bound to.
2. **Relay test page.** An optional button on an incident page sends a short
   spoken summary of the incident to a **simulated test node** (a plain Asterisk
   server) and measures the recording that comes back. **This is not AllStarLink
   and nothing in it keys a radio.** It is off until an administrator turns it
   on. See [The relay test page](#the-relay-test-page-simulated-not-allstarlink).

## What has and has not been verified

Be clear about this before you promise anything to a group:

- **Checked against real equipment (Phase 153):** the Asterisk side — ring
  groups, direct numbers, the event stream — with real SIP clients; and that
  JsSIP loads in a real browser and asks the server for its extension.
- **Checked only against simulations (Phase 155):** the browser phone's logic
  (registration, dialing, answering, errors) runs in automated tests against a
  *fake* SIP library; the Asterisk bridge's one-row-per-call logic runs against
  *synthetic* event sequences written from Asterisk's documentation. These prove
  the code does what it is meant to do; they cannot prove a real browser and a
  real PBX agree.
- **Not yet validated live by the maintainer:** a real browser completing the
  secure-WebSocket handshake and carrying two-way audio; the bridge against a
  real ring-group call; the dial-plan header in
  [One Answer](#one-answer-answering-here-also-claims-the-call). Treat the phone
  as something to pilot with a small group first.

## What you need

- **A WebRTC-capable PBX.** JsSIP talks SIP over a WebSocket, so the PBX must
  accept that (Asterisk with `res_http_websocket`/PJSIP, FreeSWITCH or Kamailio
  style). A hosted SIP provider, or a PBX with no WebSocket transport, cannot be
  reached directly; put an Asterisk or FreePBX server in between.
- **An HTTPS page.** Browsers refuse microphone access on a plain `http://`
  page. The widget says so, in words, instead of failing silently.
- **A certificate the browser trusts** for the PBX's WebSocket address (see
  [The one-time certificate step](#the-one-time-certificate-step)).
- **Network path for audio.** There is **no STUN/TURN setting yet**. A browser on
  a different network from the PBX (behind NAT) can end up with one-way audio or
  none; keep browsers and PBX on the same network, or arrange the PBX's own NAT
  handling, until that setting exists.

## Setting up phone extensions

**Settings → Communications & Integrations → Phone Extensions**
(`phone-extensions-admin.php`), gated on `action.manage_calls` (Super Admin and
Org Admin by default).

1. **PBX Connection** — set the PBX's **WebSocket URL** (for example
   `wss://your-asterisk-host:8089/ws`) and, optionally, the general number to
   display. One install-wide setting; every browser reads it.
2. **New Extension** — pick a 2–10 digit number, a label, and whether it is the
   shared **general number** or a **direct-station number**.
   - **PBX password (optional).** The browser logs in to the PBX with this
     extension's password, so it must **match the endpoint on the PBX**. Two ways:
     - *You already have an endpoint on the PBX:* type its existing password
       (8–128 characters, no spaces). Nothing on the PBX has to change. It is
       **never displayed again and never written to the audit log**. On an
       existing extension, typing a password here and saving **replaces** the
       stored one.
     - *You are creating the endpoint:* leave the box blank. A strong random
       password is generated and shown **once** — copy it into the PBX's own
       endpoint (`pjsip.conf` or equivalent). Rotate it from the same dialog.
     (In `pjsip.conf` a semicolon starts a comment, so avoid one in a password
     you will paste there.)
   - The Asterisk-side endpoint must exist separately. This page manages
     TicketsCAD's record of "which extension is this", not the PBX.
3. **Binding a direct number to a workstation.** A workstation is identified by
   a **token** its browser keeps (per browser profile — a different browser, or a
   private window, is a different workstation). To find it:
   - on that workstation, open the **Console** and click **Phone token** in the
     workstation bar (it shows the token with a copy button); or
   - open the Phone widget while it is not bound — it shows the same token; or
   - when you are on the workstation yourself, in the extension dialog click
     **This browser** next to *Workstation Token*.

   Paste it into the extension's *Workstation Token* field. A ring then targets
   the operator currently logged in at that workstation instead of everyone. An
   extension with no token just sits configured.
4. **Two install-wide switches** (Super Admin only — `action.manage_config`; they
   do not appear for an Org Admin):
   - **Where the phone registers** — *The Console and the Phone window only*
     (default) or *Every page*. A SIP registration lasts only as long as the page
     that made it, and TicketsCAD is a multi-page application, so with *every
     page* the extension drops off the PBX (and an active call ends) each time an
     operator clicks to another page. The default registers in the **Phone
     window**, which never navigates, plus the Console. Only one window of a
     browser holds the registration at a time. Choose *every page* only if your
     operators stay on one page.
   - **Record calls from our own extensions as Constituents** — on by default.
     When workstation 101 calls the general number, the caller is added to the
     Constituents list under the extension's label (for example "Desk 1 (ext 101)"),
     so the dispatcher who answers sees who is calling and the next call from that
     desk finds the same record. Turn it off if you do not want your own desks in
     the public contact list; a call from an extension then matches no Constituent.

## Using the Phone

- **The navbar phone button** (gated on `screen.call_queue`, the existing "works
  the phone" permission). On the Console it shows or hides the floating widget;
  on any other page — under the default setting — it opens the **Phone window**.
- **The Phone window** (`phone.php`) is a small window that holds the
  registration and **keeps ringing while you work in other tabs**. Open it once at
  the start of a shift and leave it open. If it is closed normally, the Console tab
  takes the registration back within a few seconds (up to about twenty if the window
  crashed rather than closed). If a second window of the same
  browser tries to register while one already holds it, it says *"another window
  of this browser is handling your calls"* and does not register (a double
  registration rings twice and answers twice).
- **On a page that does not register** the widget says *"The phone is active in
  your Console or Phone window"* with an **Open the Phone window** link, instead
  of the misleading "not bound" panel it used to show.
- **Not bound yet?** On the Console or in the Phone window the widget shows this
  workstation's token with a copy button — give it to an administrator.
- **Dialing:** the keypad, or type a number and press **Enter**, or the
  general-number shortcut. Dialing before the PBX has accepted the registration
  says so instead of doing nothing.
- **Incoming calls** show the caller, with **Answer** focused so **Enter**
  answers, and **Decline**. An active call shows a timer, mute and hang-up. A
  second incoming call while you are on one is rejected "busy". In the Phone
  window the title bar flashes "(Incoming call)" so a window behind others is
  noticed; the ringing tone and the banner come from the main pages, so keep a
  TicketsCAD tab open too.
- **Detach** (the widget's own button) still moves the widget into a picture-in-
  picture window, but that window shares the page's JavaScript and ends with it;
  use the **Phone window** when you need a call to survive navigation.

### One Answer: answering here also claims the call

TicketsCAD's inbound-call banner (Phase 149) and the browser phone used to be two
separate things, so answering took two clicks. Now:

- **Answer in the phone** answers the audio **and**, when the PBX tagged that
  call (below), claims the matching call in the banner and opens the **New
  Incident** form in a new tab — the same as clicking Answer in the banner.
- **Answer in the banner** (on any page) claims the call, opens the New Incident
  tab, **and** answers the matching ringing leg in the phone.
- If nothing correlates (no tag, the bridge is not running, the call is already
  someone else's) the audio is answered anyway and nothing else happens; answering
  never depends on the bridge being up.
- **One known gap:** a banner Answer only picks up a phone leg that is *already
  ringing*. If your dial plan keeps the caller in an announcement or menu for a while
  before the phones ring, the banner can appear first; if you click Answer there
  before the phone starts ringing, click Answer in the phone as well when it does
  (it will not open a second New Incident tab; the call is already yours).

For the two to find each other, the leg that rings a browser must carry the PBX's
call id in a SIP header, `X-Call-Linkedid`. **This snippet is written from the
Asterisk documentation and has not been confirmed on the maintainer's PBX** —
check it with `pjsip set logger on` and look for the header in the INVITE sent to
the browser:

```
; extensions.conf (Asterisk 12 or later) -- UNVERIFIED on a live PBX
[phone-leg-tag]
exten => s,1,Set(PJSIP_HEADER(add,X-Call-Linkedid)=${CHANNEL(linkedid)})
 same => n,Return()

[from-internal]
exten => 100,1,Dial(PJSIP/101&PJSIP/102&PJSIP/103,25,b(phone-leg-tag^s^1))
```

The Asterisk bridge (`services/sip-bridge/`, `mode = ami`) now reports **one**
call per Asterisk Linkedid instead of one per channel, so a ring group no longer
produces several banner rows; set `ami_context` in `bridge.ini` to the dial-plan
context your trunk rings into so calls your own dispatchers place are not shown as
ringing calls. See [INBOUND-SIP-CALLS.md](INBOUND-SIP-CALLS.md).

### What the footer tells you

| The footer says | What it means / what to do |
|---|---|
| *The PBX connection is not configured yet* | An administrator must set the PBX WebSocket URL. |
| *Cannot reach the PBX … open https://host:8089/ once and accept the certificate* | First connection from this browser to a PBX with a self-signed certificate: visit that address once. Otherwise check the PBX address and the network. |
| *The PBX rejected this extension's password* | The password on the Phone Extensions page does not match the PBX endpoint. Supply the PBX's password there. |
| *Lost the connection to the PBX. Reconnecting automatically* | The PBX or the network dropped. It retries on its own. |
| *Calls need a secure page … https://* | The page is open over plain `http://`; the browser will not allow the microphone. |
| *Microphone blocked. Allow microphone access for this site* | The browser's microphone permission was refused; click the lock icon beside the address. |
| *The call could not set up audio* | Microphone problem, PBX media settings, or NAT (see "What you need"). |
| *Busy.* / *No answer.* / *That number does not exist on the PBX.* | As written. |
| *Another window of this browser is handling your calls* | The Phone window (or another tab) holds the registration. |

### The one-time certificate step

A self-hosted Asterisk almost always uses a **self-signed certificate** for its
WebSocket. The first time a browser profile tries to register, the connection
fails until that browser trusts the certificate. **Visit the PBX's plain HTTPS
address once** (same host and port as the WebSocket URL, for example
`https://your-asterisk-host:8089/`) and click through the browser's warning
(Chrome: Advanced → Proceed). One time per browser profile. A certificate from a
real certificate authority avoids this and is strongly preferred.

## Callers become Constituents

When a call rings, TicketsCAD finds the caller's Constituent record — or creates a
bare one holding just the number — so the dispatcher who answers sees who is
calling and their history, using the New Incident form's existing caller-history
prefill. Phone numbers are matched however they are written: `+1 612 555 1234`,
`(612) 555-1234`, `612.555.1234` and `612-555-1234` are one person (the last ten
digits are compared when both numbers have ten or more). Fewer than four digits
match nothing, except a number that is one of your own extensions (see the switch
above). Typing a seven-digit local number in the New Incident phone box still
finds the ten-digit record; the automatic match on a ringing call is stricter so
it never puts the wrong person's history in front of a dispatcher.

## The relay test page (simulated, not AllStarLink)

An incident page can show a **Relay test page** button. It sends a spoken summary
of that incident (case number, type, location, description) to a **plain Asterisk
test server**, over SSH and the Asterisk Manager Interface, plays it out there and
records it, then measures the recording (duration and loudness) so you can see the
audio really arrived. **Nothing in it uses AllStarLink or keys a radio.** It
exists to prove the relay path works end to end.

It is **off** until a Super Admin turns it on, and it has no built-in server
address (a fresh install points nowhere).

1. **Settings → Communications & Integrations → AllStar Relay (test)**
   (`allstar-relay-admin.php`, `action.manage_config`). Read the banner at the top
   of the page, then fill in the **SSH alias** (a host entry in the web server
   account's `~/.ssh/config` that can `ssh`/`scp` to the test node without a
   password and run `sudo mv` there), the **AMI host, port, user and secret** (a
   dedicated `manager.conf` user with `originate` permission only), and, under
   *Advanced*, the dial-plan extension and the three folders on the test node
   (plain absolute paths only — they end up in commands run on the test node).
2. **Save**, then **Test saved connection**. It logs in to the Manager Interface
   and logs out again — no audio, nothing sent — and tells you where it stopped
   (cannot connect, not an AMI port, login refused). The secret is never shown
   again; leave the box blank to keep the stored one.
3. Switch **Enable the relay test** on. The button now appears for anyone who may
   dispatch a unit (`action.dispatch_unit`).
4. Click **Relay test page** on an incident. It takes about 20–30 seconds because
   the message genuinely plays out in real time; the button disables itself, and
   **you can keep working** (the request no longer holds your session). A toast
   reports the measured duration and loudness; a short or quiet result is flagged
   as inconclusive rather than called a success. The caller must be able to see
   the incident (organization scoping applies).

Every change to the settings, every connection test and every relay is in the
audit log (setting changes name the fields that changed, never their values).

### What is *not* available

- **No AllStarLink.** No AllStar node receive, transmit, key-up, or connect and
  disconnect exists in TicketsCAD. A real integration (a private AllStarLink node
  and a voice leg in the audio matrix) is separate, unstarted work with no date.
- **No radio.** Nothing here keys a transmitter.
- **Listening to a repeater that publishes an audio stream** is possible today as a
  receive-only Public Audio Stream channel; see
  [AUDIO-MATRIX-SETUP.md](AUDIO-MATRIX-SETUP.md#listening-to-an-allstar-node-or-repeater-receive-only).

## How it fits together (for anyone extending this)

- `inc/phone-extensions.php` / `api/phone-extensions.php` — extension CRUD (a
  supplied or generated password), PBX connection settings, the two Super-Admin
  switches (`phone_register_scope`, `phone_internal_constituents`), and the
  `my_extension` lookup every browser's phone calls to resolve its own extension
  and credentials by its workstation token.
- `assets/js/phone-widget.js` + `inc/phone-widget-template.php` +
  `assets/css/phone-widget.css` — the widget; `assets/js/phone-dial-logic.js` — its
  DOM-free logic (registration scope, the single-registrant election, the
  `X-Call-Linkedid` reader, the plain-English failure text); `phone.php` — the
  standalone window; `assets/js/console-workstation.js` — the workstation token,
  loaded on every page by `inc/navbar.php`. JsSIP is `assets/vendor/jssip/` (a
  locally built bundle; the npm package ships no browser build).
- `inc/phone-match.php` — the one phone-number matcher used by the ringing-call
  resolver and the manual `?phone=` lookup.
- `inc/inbound-calls.php` / `api/inbound-calls.php` — Phase 149's call record;
  `claim_by_provider` is the phone's Answer. `services/sip-bridge/bridge.py` —
  `AmiCallTracker`, one call per Linkedid.
- `inc/allstar-relay.php` / `api/allstar-relay.php` / `allstar-relay-admin.php` —
  the relay test: message building, speech synthesis (this app's TTS engine
  registry first, `espeak-ng` on the test node as a fallback), delivery over
  SSH/SCP, an AMI Originate, and a pure-PHP WAV parser to verify the recording.

## Out of scope / known limits

- **No real telephony carrier.** Nothing here provisions or tests a trunk to the
  public phone network, a toll-free number or billing. Dialing an outside number
  only works if your PBX's dial plan routes it, and **nothing in TicketsCAD stops a
  volunteer dialing an emergency number** — the PBX dial plan is the place to
  enforce who may dial what; the browser holds the extension's password, so any
  limit in the page is a guard rail against mistakes, not a security boundary.
- **No keypad during a call, hold, transfer, call history or click-to-call from a
  number on screen** yet.
- **No STUN/TURN setting, no per-user extension binding** (it is per browser), and
  no reference PBX configuration is shipped yet.
- **The general number rings the caller too.** `Dial(PJSIP/101&PJSIP/102&PJSIP/103,25)`
  rings every registered direct-station extension, including the one placing the
  call.
- **No call recording or archive** for browser calls.
- **No encryption beyond standard WSS/TLS and SRTP** — see
  `docs/security/architecture.md` for the project-wide cryptographic inventory.
