# Inbound SIP/PBX Call Integration (Phase 149)

**Audience:** administrators (setup) and dispatchers (the "Using it" section).
**Status:** fully built, off by default. An install with zero trunks configured
shows no new UI and runs no new background activity.

## What it is

When an agency's phone system (a SIP trunk or PBX — FreePBX/Asterisk, 3CX, a
hosted SIP provider) receives an inbound call, TicketsCAD shows every
qualified, logged-in dispatcher a live, hard-to-miss notice of the ringing
line. The first one to answer claims it with a single click, and that click
opens a New Incident form pre-filled with the caller's number and (once
claimed) their prior-incident history — without ever losing whatever that
dispatcher was already doing.

This is **coordination and screen-pop.** The claim itself is a record kept in
TicketsCAD; it never answers, holds, transfers or bridges the phone call. (Since
Phase 153/155 a **browser phone** can answer and place calls through an Asterisk
PBX — see [PHONE-TELEPHONY-GUIDE.md](PHONE-TELEPHONY-GUIDE.md) — and answering
there can claim the matching call here with one click; that is the phone's doing,
not this banner's.) A companion adapter process normalizes your PBX's native events into one canonical
webhook shape, the same "small bridge process talks to the vendor, PHP never
does" pattern this project already uses for the DMR radio bridge
(`services/bridge/`) and the Meshtastic bridge (`services/meshtastic/bridge.py`).

Full design rationale: `specs/phase-149-inbound-sip-calls/{spec.md,plan.md}`.

## Setting up a trunk

1. **Settings → Communications & Integrations → Inbound Calls → Inbound
   Calls (SIP/PBX)** (requires the "Manage Inbound Calls" permission,
   `action.manage_calls` — Super Admin and Org Admin by default).
2. Click **New Trunk**. Give it a label (e.g. "Main Dispatch Line"), pick an
   organization if this is a multi-agency install (leave blank for
   install-wide — the common single-agency case), and set:
   - **Wrap-up seconds** (default 90) — how long a call stays shown as
     "wrapping up" after the PBX reports it ended, before folding to fully
     closed. Gives the claimant time to finish the incident write-up.
   - **Reassign grace seconds** (default 20) — how long after a claim any
     OTHER qualified user may instantly "Take" it with no supervisor
     permission and no reason (see "Quick reassignment" below).
   - **Ringing tone bypasses mute** (default on) — for the overwhelmingly
     common deployment (a single volunteer agency's one emergency line), a
     missed call is worse than an unwanted loud tone. Turn this off for a
     non-emergency line where over-alerting is the bigger cost.
3. Save. The **Connect your phone system** dialog opens with the trunk's
   **bearer token already filled into a ready-to-paste `bridge.ini`** for the
   phone system you pick (3CX, Asterisk/FreePBX, or a hosted SIP provider).
   The token is shown only now — if it is lost, use **Rotate Token** on the
   trunk to mint a new one (the old one stops working immediately).
4. **Creating a trunk does not connect anything.** A small program — the
   *bridge* — has to run somewhere that can reach both your phone system and
   this server. Follow the numbered steps in the dialog (also written out
   below), then watch the **Bridge** column on the trunks list: it turns green
   ("Connected") within about 30 seconds of the bridge starting. You can
   reopen the dialog any time with the **Setup** button on the trunk.
5. Click **Send test call** in the dialog to prove the TicketsCAD half works
   (a clearly-labelled TEST CALL rings for ~12 seconds on every dispatcher's
   screen, then clears itself and never lands in anyone's Missed Calls). It
   works before your phone system is connected, which separates "my
   TicketsCAD side is fine" from "my PBX side is not wired yet".

## The adapter process (`services/sip-bridge/`)

TicketsCAD's PHP tree never speaks SIP, AMI, or ARI directly. A separate,
small process — one per PBX vendor — normalizes your PBX's native events
into ONE canonical JSON contract and POSTs it to `api/sip-ingest.php`:

```json
{
  "event": "ringing | claimed_externally | ended | abandoned",
  "call_id": "<PBX Uniqueid/Linkedid or SIP Call-ID>",
  "caller_number": "+16125551234",
  "caller_name": "CNAM string or null",
  "called_number": "<DID dialed>",
  "event_ts": "2026-08-22T14:03:11Z"
}
```

The one bridge program (`services/sip-bridge/bridge.py`) has three modes:

| `mode =` | For | Needs |
|---|---|---|
| `threecx` | 3CX (V20) through its Call Control API | `pip install requests websockets`; a 3CX licence that includes the Call Control API |
| `ami` | Asterisk / FreePBX through the Manager Interface (one row per call, keyed by Asterisk's Linkedid) | `pip install requests` |
| `webhook` | A hosted SIP provider that POSTs call events to you | `pip install requests` |

Whatever the mode, it reduces the phone system's own events to the one
contract above, and — new in Phase 155 — also sends
`{"event": "heartbeat", "bridge": "sip-bridge 1.1.0 (threecx)"}` every 30
seconds so this server can tell a **running but quiet** bridge from a
**dead** one. (A heartbeat carries no call data and never reaches the call
state machine; a disabled trunk still records it so the page can say
"connected, but switched off".)

### Asterisk (`mode = ami`): one call, one row

Asterisk reports one phone call as **several channels** — the caller's, plus one
outbound channel for every phone a ring group rings — all sharing a **Linkedid**.
The bridge now reports **one** call per Linkedid, from the caller's channel
(`Uniqueid` equal to `Linkedid`), and ignores the outbound legs, so a three-phone
ring group is one banner row, not four. It calls the call **answered** when the
caller's channel reaches state Up or a dialed leg reports `ANSWER` (the call then
*ends*), and **abandoned** otherwise — including a caller who hangs up while it
rings, which previously ended up as "ended" and never reached Missed Calls. A call
that began before the bridge started is not reported (no inventing a ring that was
not seen). The call id sent to TicketsCAD is the Linkedid, which is also what lets
the browser phone claim the matching call when it answers
([PHONE-TELEPHONY-GUIDE.md](PHONE-TELEPHONY-GUIDE.md#one-answer-answering-here-also-claims-the-call)).

Set **`ami_context`** in `bridge.ini` (or `--ami-context`) to the dial-plan context
your trunk rings into; only calls whose first channel is in that context are
reported, so calls your own dispatchers place from their browsers are not shown as
ringing calls. (Before Phase 155 this option was described here and in the example
file but silently ignored.)

> **Not yet verified on a live PBX.** This logic is tested against synthetic event
> sequences written from Asterisk's AMI documentation (Asterisk 12 or later field
> names: `Newchannel`, `Newstate`, `DialEnd`/`DialStatus`, `Hangup`, `Uniqueid`,
> `Linkedid`, `ChannelState`); an older Asterisk that sends no `Linkedid` falls back
> to the old per-channel behaviour. Capturing a real ring-group call to replace the
> synthetic sequences is the open check.

### Check it before you trust it: `--check`

After editing `bridge.ini`, run

    python bridge.py --config bridge.ini --check

It walks the chain and names the setting at fault, in plain English: the
token is empty → TicketsCAD unreachable or the address wrong (`ticketscad_url`
must be the folder containing `login.php`, with nothing after it) → the token
is rejected → the trunk is disabled → the PBX credentials are rejected → the
PBX connection fails. Exit code 0 means every link works. Run it again any
time something stops working.

### 3CX, step by step

**First, check your licence.** 3CX's Call Control API — the only 3CX feature
that reports a call ringing, being answered, ending or going unanswered, live —
is included **only in 3CX AI Edition** (the edition called *Enterprise* before
April 2026). PRO, Basic and the free tier do not include it; 3CX's own feature
matrix lists it for AI Edition alone. Quick test: in the 3CX Admin Console, is
there an **Integrations → API** entry? If not, this mode cannot work on your
system (see "If your 3CX cannot use the Call Control API" below). Self-hosted and
3CX-hosted systems can differ; it is not documented whether hosted systems show
the API screen — ask 3CX if yours is hosted.

1. **3CX Admin Console → Integrations → API → Add.**
   - *Client ID*: any name or number you like, for example `ticketscad`. It goes
     in `threecx_client_id`. (3CX's own text calls it a "DN"; real systems
     accept any string.)
   - Tick **3CX Call Control API Access**.
   - **Extensions to monitor — required.** Select every dispatch extension that
     should ring TicketsCAD. **3CX sends no events at all for an empty list; it
     does not mean "all".** Add each extension a dispatch call can ring.
   - Leave *DID numbers* empty. A DID assigned here puts the bridge in the call
     path, which you do not want for a watch-only integration.
   - Save, and **copy the API key — 3CX shows it exactly once.** It goes in
     `threecx_client_secret`.
   - If **Admin → Advanced → Console Restrictions** limits which IP addresses may
     use the console, the bridge computer must be on that list too.
2. On any computer that can reach **both** the 3CX web address and this
   TicketsCAD server (the 3CX server itself is fine), install Python 3 and run
   `pip install requests websockets`. Nothing is installed on the PBX, and the
   bridge needs no inbound port — it only makes outbound connections.
3. Copy the `services/sip-bridge` folder there. Open **Settings →
   Communications & Integrations → Inbound Calls (SIP/PBX)**, click **Setup**
   on your trunk, pick *3CX*, and copy the generated text into `bridge.ini`
   next to `bridge.py`. Fill in `threecx_url` (the address you type to open
   the 3CX web client — add `:5001` if that is the port your system uses — no
   path), `threecx_client_id` and `threecx_client_secret`.
   - Self-signed 3CX certificate? Add `threecx_verify_tls = false`.
   - **3CX does not tell the bridge which number was dialed** (the field exists
     but is empty on V20). If you want TicketsCAD to show the line a call came
     in on — or to ring one dispatcher's own screen for a direct number
     (Phone Extensions) — map each trunk to its number:
     `threecx_trunk_did_map = 10001=+16125550100,10002=+16125550111`
     (the left side is the trunk's DN as 3CX numbers it; `--check` and the
     capture file show it), or set one fixed `threecx_default_called_number`.
4. `python bridge.py --config bridge.ini --check` — fix every red line. It also
   tells you whether the licence looks wrong and whether the extension list on
   the API client is empty.
5. `python bridge.py --config bridge.ini`. Install it as a service when happy
   (systemd example: `sip-bridge.service.example`; on Windows use Task
   Scheduler "at startup", or NSSM).

**What the bridge turns 3CX activity into**

| What 3CX does | What TicketsCAD sees |
|---|---|
| An outside call rings one or many dispatch phones | **One** ringing banner (not one per phone) |
| One phone answers; the others stop ringing | `claimed_externally` note (it records which extension answered); the banner stays so a dispatcher can still **Answer** to open the incident |
| The answered call ends | Call ends |
| Every phone stops ringing, nobody answered | **Missed call** — decided only after a 2-second grace period, so a queue handing the call to its next wave of agents is not reported as missed |
| A call your own staff place from a monitored extension | Nothing |
| An extension-to-extension call | Nothing (`threecx_include_internal = true` to see these) |
| The bridge restarts or reconnects while a call is ringing | It re-reads the live call list, carries on, and recovers a hangup that happened while it was disconnected |
| 3CX never reports a call ending | The bridge closes a call that rings for 3 minutes (`threecx_ringing_max_seconds`) |

The bridge only reports a call it saw **ring**: if it starts in the middle of
an answered call it stays silent about that call rather than inventing a ring.
Every message is sent in order and retried if TicketsCAD is briefly
unreachable.

**Recording real 3CX traffic (`capture_file`).** This bridge was built from
3CX's published documentation and from real call captures posted by other
3CX users, and is tested against a simulator of both. It has not yet been run
against a live 3CX server in this project, and a few details are still
unconfirmed (how a call arriving through a queue or ring group is described,
whether a transfer keeps the same call number). To close those, add one line to
`bridge.ini`:

    capture_file = threecx-capture.jsonl

place a few test calls (one answered, one left to ring out, one through your
queue), stop the bridge and send the file. Phone numbers, caller names and
device addresses are masked automatically (digits become `9`, letters become
`x`); the structure and every status is kept, which is all that is needed to fix
the bridge. Set `capture_redact = false` only if you want the raw values.

### If your 3CX cannot use the Call Control API

On **PRO**, 3CX's CRM-integration feature calls out to a web address when a call
*arrives* (caller number only, no answered/ended events) and reports the call
when it *ends*. A provider adapter for that is designed but not built, because it
cannot show who answered and cannot tell TicketsCAD the instant a call ends. On
**Basic or the free tier** no server-side hook that could do this has been
verified. Either way, tell us which edition you run — that decides which adapter
gets built next — or run Asterisk/FreePBX in front of 3CX.

### Troubleshooting

| You see | Cause | Fix |
|---|---|---|
| Bridge column says **Waiting for bridge** | Bridge never started, or `ticketscad_url`/`bearer_token` is wrong | Run `--check` |
| **Silent 12 min** | It was running, then stopped or lost its network | Restart it; check its log |
| `--check`: *TicketsCAD rejected the bearer token* | Token mistyped, or rotated | Copy it exactly, or **Rotate Token** and paste the new one |
| `--check`: *HTTP 404* | `ticketscad_url` has a path on the end | Folder that contains `login.php` only |
| `--check`: *that trunk is DISABLED* | Trunk switched off | Enable it on the Inbound Calls page |
| `--check`: *3CX rejected the API credentials* | Wrong Client ID or API key | Re-check Integrations → API; the key is shown once — create a new client if lost |
| `--check`: *403 for /callcontrol*, or *no 'Enterprise' role* | *3CX Call Control API Access* not ticked, or the licence is not AI Edition | Tick it; confirm the licence |
| `--check`: *lists NO extensions* | The API client's *extensions to monitor* list is empty | Add every dispatch extension to it |
| Bridge connects but no call ever appears | Extension not on the client's monitored list, or the call rings a queue/ring group that is not monitored | Add the individual dispatch extensions; capture a call with `capture_file` |
| `--check`: *TLS error* | Self-signed 3CX certificate | `threecx_verify_tls = false` |
| Banner appears but never goes away | The PBX never reported the call ending | Bridge closes a call that never ends after 5 min (ringing) / 6 h (answered); report the log |
| Test call rings but real calls do not | The TicketsCAD side is fine; the PBX side is not feeding the bridge | `log_level = DEBUG`, place a call, look for `3CX event:` lines; or use `capture_file` |

`claimed_externally` is a deliberately narrow, informational-only event
(the PBX saw a physical extension answer with no TicketsCAD claim at all) —
recorded as an audit note, never changing the call's coordination state in
this phase (see "Out of scope" below).

## The scheduled sweep

`tools/inbound_calls_tick.php` runs every 15 seconds (matching the claim-
heartbeat cadence) and does two things:

- Folds a `wrapup` call to `ended` once `wrapup_seconds` has elapsed.
- Flags a `claimed` call whose heartbeat has gone quiet (three missed 15s
  beats, 45s) as **stale** — never auto-releasing the claim.

Install it as a systemd timer (Linux) — see `docs/MAINTENANCE-RUNBOOK.md`'s
"inbound SIP/PBX call sweep" section — or via `tools\run-scheduled-jobs.bat`
on Windows/Task Scheduler. Safe to enable unconditionally: zero configured
trunks means zero rows either sweep ever finds.

## Using it (for dispatchers)

### The banner

The moment a call rings, every logged-in user holding **screen.call_queue**
sees a persistent strip beneath the navbar — never a modal, never a
full-screen takeover. Multiple simultaneous ringing/claimed calls stack as
compact cards, oldest first. Click **Answer** to claim a ringing call; a
**New Incident** tab opens automatically, pre-filled with the caller's
number, with the existing constituent lookup and call-history panel already
triggered — exactly as if you'd typed the number and tabbed out.

If you can't get to the phone in time, the call moves to a **Missed Calls**
section (collapsible, count badge) instead of vanishing — click **Callback**
any time to open the same pre-filled New Incident tab, or **Review** to
clear it from the panel once you've followed up (the record itself is never
deleted).

### Quick reassignment ("Take")

Many SIP/PBX deployments let only one physical extension actually answer a
call — the hardware race can resolve differently from the CAD's software
claim. If someone else's name shows on a call you're actually holding
(or vice versa), click **Take** within the trunk's configured grace window
(default 20s) to instantly correct it — no permission beyond the ordinary
claim permission, no reason required. This is a self-correction of an
honest, mechanical race, not an override of someone else's settled work.

Once the grace window elapses, "Take" is replaced by a supervisor-gated
override that requires the "Manage Inbound Calls" permission and a typed
reason (visible in the audit trail) — overriding a colleague who is, as far
as the system can tell, still genuinely on the call is a supervisor
decision, never a peer one.

### Stale claims

If a claiming browser stops confirming it's still there (crash, lost
network, walked away) while the PBX has not reported the call ended, the
card turns amber ("Stale") for every other qualified user. Click
**Reclaim** — no reason required, since this is recovering from an apparent
technical failure, not a live dispute. The system never silently hands a
stale claim to someone else on its own.

### Keyboard shortcuts

Reachable without the mouse, matching this project's keyboard-first
convention elsewhere (the `/` command bar, arrow-key list navigation):

| Key | Action |
|---|---|
| `↑` / `↓` | Move the highlighted-call cursor among simultaneous calls |
| `A` | Claim (Answer) the highlighted ringing call |
| `T` | Take/Reclaim the highlighted claimed-by-another call (quick-reassign if fresh, low-friction reclaim if stale) |
| `Esc` | Locally acknowledge the highlighted call (e.g. you heard someone else physically pick up) — never touches the server, never affects any other user's view |

None of these fire while typing in a text field, and only while at least
one call is visible in the banner — they never contend with another page's
own shortcuts otherwise.

## Access control

Five independently grantable permissions (deliberately not a reuse of
`screen.constituents` or `action.manage_members`, neither of which means
what this feature needs):

| Code | Gates | Super Admin | Org Admin | Dispatcher | Operator |
|---|---|:-:|:-:|:-:|:-:|
| `screen.call_queue` | See the live banner at all | Y | Y | Y | Y |
| `action.claim_call` | Claim/release/quick-reassign; reclaim a **stale** claim | Y | Y | Y | Y |
| `action.manage_calls` | Reclaim an **active** claim (with reason); configure trunks | Y | Y | N | N |
| `field.caller_history` | See a claimed call's matched identity + prior-incident summary | Y | Y | Y | Y |
| `field.patient_history` | See clinical/patient detail nested in that history | Y | Y | Y | N |

The live, broadcast ring notification itself never carries a matched
identity, address, prior-incident count, or warning flag — only what a
physical caller-ID display would show (number, and which line). Identity
and history are only ever fetched, permission-checked, once a specific
user opens or claims that specific call — via the SAME two permissions
this phase retrofitted onto the previously wide-open
`api/constituents.php` / `api/call-history.php` lookups.

## Out of scope (this phase)

- **Call control from the banner** — the claim is a software-side coordination
  record, not a command to the PBX. The browser phone
  ([PHONE-TELEPHONY-GUIDE.md](PHONE-TELEPHONY-GUIDE.md)) answers and places calls;
  it has no hold or transfer yet.
- **Preventing a busy signal at the telephony layer** — ring-group/hunt-
  group configuration is a PBX concern, not something TicketsCAD's software
  layer can or should try to solve.
- **Outbound webhook subscriptions** for third parties on `call:*` events —
  the mechanism (`inc/webhooks.php`) already exists and can be extended
  later.
- **Command-bar (`/`) support** for calls.
- **Supervisor analytics** (handle-time reporting, abandonment trends) — the
  audit trail this phase builds is the foundation a later reporting phase
  would read from.
