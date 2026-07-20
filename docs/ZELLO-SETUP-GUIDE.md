# Zello Setup Guide — TicketsCAD

**Audience:** Administrators and dispatchers wiring up Zello for the first time.
**Version:** NewUI v4.0. Reflects the Zello integration as deployed today
(text broadcast, receive, inbox + reply, direct-message a unit, channel PTT
audio, and "Speak on channel" TTS broadcast).

This guide walks you all the way from a blank Zello account to a working
dispatch console that can talk to field units over the internet. Read it
top to bottom the first time — several steps depend on choices you make
earlier, and one of them (the per-channel API key) is the single most common
thing people get wrong.

> Screenshots are called out as **[Screenshot: …]** placeholders. The written
> steps are complete without them.

---

## Table of contents

1. [What Zello adds to TicketsCAD, and when to use it](#1-what-zello-adds-to-ticketscad-and-when-to-use-it)
2. [Create your Zello accounts (you need two)](#2-create-your-zello-accounts-you-need-two)
3. [Create the dispatch channel](#3-create-the-dispatch-channel)
4. [Get the Channels API credentials (the key gotcha)](#4-get-the-channels-api-credentials-the-key-gotcha)
5. [Configure TicketsCAD](#5-configure-ticketscad)
6. [Start the Zello proxy service](#6-start-the-zello-proxy-service)
7. [Using Zello from the dispatch console](#7-using-zello-from-the-dispatch-console)
8. [Troubleshooting](#8-troubleshooting)
9. [Quick reference](#9-quick-reference)

---

## 1. What Zello adds to TicketsCAD, and when to use it

Zello is an internet-based push-to-talk (PTT) walkie-talkie. It runs as an app
on any phone, tablet, or computer with a data connection. TicketsCAD's Zello
integration lets the **dispatch console** act as one more participant on a
Zello channel — so a dispatcher can talk to and message field units straight
from the dashboard, without picking up a separate phone.

With Zello configured, the dispatch console can:

- **Broadcast a text message** to everyone on the channel.
- **Receive text** from field units, with a reply-able inbox.
- **Direct-message (DM) a specific unit** by name (e.g. "Engine 1") — TicketsCAD
  looks up that unit's Zello username for you.
- **Talk and listen by voice** — hold a push-to-talk button and your voice goes
  out over the channel; incoming voice plays back live and is saved as a
  replayable clip.
- **"Speak on channel"** — type a message and have TicketsCAD synthesize it to
  speech and key it onto the channel as audio (text-to-speech broadcast).

**When to use Zello vs. the other radio paths.** TicketsCAD also bridges to
mesh radio (Meshtastic, MeshCore) and DMR. Those are independent radio networks
with their own coverage and licensing. Reach for **Zello** when:

- Your units carry smartphones and have cell or Wi-Fi data coverage.
- You want voice **and** text **and** the ability to DM one unit privately.
- You don't have (or don't want to rely on) RF infrastructure for a given
  incident.

You can run Zello alongside mesh and DMR — they don't conflict. Many agencies
use mesh/DMR as the RF-resilient backbone and Zello as the everyday,
data-network channel.

---

## 2. Create your Zello accounts (you need two)

You need **two separate Zello accounts**. This trips people up, so here is the
reasoning before the steps.

The dispatch console signs in to Zello as a normal Zello user — it occupies one
account. Your phone (the "field unit" you test with) is a **second**,
different account. Zello only allows one active session per account: if the
console and your phone were the same account, logging in on the phone would
kick the console offline (and vice-versa). You also literally cannot watch a
message travel from console → field unit if both ends are the same account.

So:

| Account | Used by | Example username |
|---|---|---|
| **Dispatch account** | TicketsCAD console (the proxy logs in as this) | `dispatch-console` |
| **Field account** | Your phone / tablet running the Zello app | `eric-handheld` |

### Steps

1. **Create the dispatch account.** Go to <https://zello.com/personal/> and
   create a free Zello account. Pick a username you'll recognize as the
   console, e.g. `dispatch-console`. Note the **username and password** — you'll
   type both into TicketsCAD later.

2. **Create the field account.** On your phone, install the Zello app
   (iOS / Android) and create a **second** account with a **different**
   username, e.g. `eric-handheld`. This is your test "unit in the field."

3. **Sign in to the developer console** at <https://developers.zello.com/>
   using your **dispatch** account. The developer console is where you'll mint
   the API key in step 4. (Signing in there doesn't cost anything and doesn't
   change your account — it just unlocks the developer features.)

> **[Screenshot: developers.zello.com signed in, showing the Apps / Channels
> area]**

---

## 3. Create the dispatch channel

A Zello **channel** is the shared room everyone talks in. For dispatch you want
a channel **you own** (or one you administer) — ownership matters in step 4,
because the API key can only be generated by someone who administers the
channel.

1. In the **Zello app** (signed in as the **dispatch** account), create a new
   channel — for example `dispatch`. The account that creates the channel is
   its **owner/administrator**, which is exactly what you want.

2. **Join the channel from your field account too.** On your phone (the
   `eric-handheld` account), search for the channel and join it. Now both ends
   are on the same channel and you can test a real round-trip.

3. **Channel options to decide now** — these change how the bridge behaves:

   - **Password / private vs. public.** A private or passworded channel only
     admits users who have been approved or have the password. **Important:**
     you do **not** type a channel password into TicketsCAD. Instead, the
     console's Zello account (`dispatch-console`) must itself have **joined and
     been admitted** to the channel. If the console account isn't an admitted
     member, the proxy will log in to Zello fine but see an empty channel.

   - **"Only approved users can talk" (moderation / talk vs. listen).** Many
     dispatch channels are set so only approved users may transmit; everyone
     else is listen-only. If the console account is **not** on the
     approved-talkers list, TicketsCAD will show your text or audio as "sent"
     but Zello silently drops it. **Make the console account an administrator or
     an approved talker** on the channel.

   - **Roles / trust (Zello Work only).** On a Zello Work network, roles gate
     who can do what. Give the console account at least talk rights on every
     channel TicketsCAD will use.

> Rule of thumb: the **dispatch console account should be an administrator (or
> at minimum an approved talker)** on the dispatch channel. That avoids every
> "it says sent but nothing happens" surprise.

> **[Screenshot: Zello app channel admin screen showing the talk/moderation
> setting]**

---

## 4. Get the Channels API credentials (the key gotcha)

This is the step that bites everyone. Read it carefully.

TicketsCAD authenticates to Zello using a **Channels API key**: an **Issuer**
(a short string) plus a **Private Key** (a block of text). On Zello **Consumer**,
that key pair is generated **inside one specific channel's admin settings**, and
**it authorizes only that one channel.**

Consequences you must internalize:

- The Issuer + Private Key are **per channel**. You generate them from the admin
  area of the channel you want to dispatch on.
- You **must administer that channel** to generate its key. A public channel you
  merely *joined* (but don't own) will **not** let you create an API key, and
  you cannot transmit to it via the API.
- One Consumer channel = one API key. You can't reuse one Issuer + Private Key
  across two different Consumer channels. (Zello **Work** is different — a Work
  network token can span the network's channels.)

### Steps (Zello Consumer)

1. In the Zello developer console / channel admin for the channel you created
   in step 3, open the channel's **Channels API** (sometimes shown as
   **Developer** or **API keys**) section. You must be the channel's
   administrator to see it.

2. The page shows the credentials **inline** — typically **Issuer**, a
   **Developer Token**, a **Private Key**, and a **Public Key**. (Newer consoles
   display these to copy directly; there's no longer a "Generate" button or a
   downloadable `.pem` file.)

3. **Copy two of them:**
   - **Issuer** — a short string (looks like `WkM6…`). You'll paste this into
     TicketsCAD's *Issuer* field.
   - **Private Key** — copy the whole key block, including the
     `-----BEGIN …-----` / `-----END …-----` lines if they're shown. You'll
     paste this into TicketsCAD's *Private Key* field.

4. While you're here, write down (you'll need these in step 5):
   - The **console account username** (`dispatch-console`) and its **password**.
   - The **channel name**, exactly as spelled (`dispatch`). The name you type in
     TicketsCAD must match the channel the key was generated for.

> **The one-line rule:** the Issuer/Private Key you paste into TicketsCAD must
> be the key generated **inside the same channel** you put in the *Dispatch
> Channel* field. A mismatch authenticates fine but then reports the channel
> offline (see [Troubleshooting](#8-troubleshooting)).

> **[Screenshot: channel admin → Channels API panel showing Issuer + Private Key
> inline]**

---

## 5. Configure TicketsCAD

Open TicketsCAD and go to **Settings → Zello Network Radio**. The panel opens
with a **Setup Wizard** ("Get connected in 4 steps") at the top that mirrors
these fields.

> **[Screenshot: Settings → Zello Network Radio panel with the Setup Wizard]**

Fill in the form section by section:

### Connection

- **Service Type** — choose **Zello Consumer (free, 1 channel)** for a personal
  Zello account, or **Zello Work (paid, multi-channel)** for a Zello Work
  network. (Leave it on "— Disabled —" to turn the integration off.)
- **WebSocket URL** —
  - Consumer: `wss://zello.io/ws`
  - Work: `wss://zellowork.io/ws/YOUR-NETWORK`
- **Network Name** — Work only; the name of your Zello Work network.
- **Proxy Port** — the port the WebSocket proxy listens on. Default `8090`.
  Leave it unless that port is taken.
- **Connection Mode** — leave on **Server Proxy (recommended)**. This is what
  generates the JWT auth token server-side from your Private Key. ("Direct
  Browser" is a dev-only mode that needs a manual auth token.)
- **Message Retention (days)** — how long messages and saved audio are kept
  before auto-purge. Default `90`.

### Authentication

- **Username** — the console's Zello account (`dispatch-console`).
- **Password** — that account's Zello password. (Stored encrypted; the field
  shows dots once saved — leave it blank to keep the existing one, or type to
  replace.)
- **Auth Token** — leave **blank**. It's only for quick dev testing without a
  Private Key; you're using the Issuer + Private Key instead.

### API Credentials (required)

- **Issuer** — paste the Issuer from step 4.
- **Private Key (PEM)** — paste the Private Key block from step 4. (Stored
  encrypted; leave blank to keep the existing one, paste a new key to replace.)

### Channels

- **Dispatch Channel** — the **exact** channel name from step 3/4
  (e.g. `dispatch`). This **must** be the channel your Issuer/Private Key was
  generated for.
- **Alert Channel** (optional) — a channel for automated text alerts.
- **Additional Channels** (optional, comma-separated) — extra channels for
  Zello Work.

### Audio Settings

- **Codec** — Opus (the only/recommended option).
- **Sample Rate / Frame Duration** — leave at the defaults (16 kHz, 20 ms)
  unless you have a specific reason to change them.
- **Mode** — **Full (talk + listen)** for a normal dispatch console, or
  **Listen only** if this console should never transmit.

### Behavior

- **Auto-connect on page load** — connect the Zello widget automatically when
  the dashboard loads.
- **Send dispatch alerts as text messages** — push dispatch alerts onto the
  channel as text.
- **Enable voice transcription** — Zello Work only.

When everything is filled in, click **Save Zello Settings**.

> **A note on the proxy token:** you do **not** mint a token by hand. When a
> dispatcher opens the Zello widget, TicketsCAD automatically requests a
> short-lived token (`api/zello-token.php`) and hands it to the proxy. You just
> need the proxy **running** (next step).

---

## 6. Start the Zello proxy service

The Zello widget in the browser does not talk to Zello directly. It talks to a
small **proxy daemon** that runs on the TicketsCAD server, holds the Zello
connection, generates the JWT from your Private Key, and relays messages and
audio both ways. **The proxy must be running** for any of this to work.

### Windows (development)

From the `proxy` folder, run:

```
proxy\start-proxy.bat
```

Leave that window open; it's the running proxy.

### Linux (production)

Install it as a systemd service (recommended) so it starts on boot and
restarts on failure:

```bash
sudo cp proxy/newui-zello-proxy.service.example /etc/systemd/system/newui-zello-proxy.service
sudo mkdir -p /var/log/newui && sudo chown www-data:www-data /var/log/newui
sudo systemctl daemon-reload
sudo systemctl enable --now newui-zello-proxy.service
sudo systemctl status newui-zello-proxy
```

(See `proxy/INSTALL-LINUX.md` for the foreground/dev option and full details.)

### HTTPS deployments — the WebSocket reverse-proxy snippet (required)

If TicketsCAD is served over **HTTPS**, the browser will **not** allow the
widget to connect to an insecure `ws://your-host:8090`. Instead the widget
connects to `wss://your-host/zello-ws`, and your web server must reverse-proxy
that path to the local proxy daemon. On Apache:

```bash
sudo a2enmod proxy_wstunnel
```

Then inside your HTTPS `<VirtualHost *:443>`:

```apache
<Location /zello-ws>
    ProxyPass        ws://127.0.0.1:8090/
    ProxyPassReverse ws://127.0.0.1:8090/
</Location>
```

```bash
sudo apachectl configtest && sudo systemctl reload apache2
```

> Without this snippet on an HTTPS site, the widget shows
> **"Requesting auth token… Disconnected from proxy"** in a loop — the proxy is
> running, but the browser's WebSocket connection has nowhere to land. This is a
> real, commonly-hit cause; see [Troubleshooting](#8-troubleshooting).

### Verify

Back in **Settings → Zello Network Radio**, click **Test Connection**. You're
looking for an **authenticated / logged in** status. If you get there, the
console is connected to Zello.

---

## 7. Using Zello from the dispatch console

### Open the Zello widget

On the **dashboard**, click the **Zello** control button (megaphone icon). A
floating Zello widget appears — you can drag it, resize it, and the
colored status dot in its header shows the connection state. You can also
toggle it from the command bar.

> **[Screenshot: dashboard with the floating Zello widget open, status dot
> green]**

### Broadcast a text message

Type into the widget's text box and press **Enter** (or click **Send**). The
message goes to **everyone on the channel**. It appears in your feed as an
outgoing message and lands on every device joined to the channel — including
your test phone.

> Try it: send from the console, watch it arrive on the `eric-handheld` phone.
> Then send **from the phone** and watch it appear in the widget feed.

### The Inbox and replying

Inbound Zello text is collected in a reply-able **inbox**. Open
**Mesh Console** and find the **Zello** inbox card. Each inbound message shows
who it's from and offers a **Reply**:

- **Reply to channel** — broadcasts your reply to the whole channel.
- **DM the sender** — replies privately to just the person who messaged you
  (only when the inbound message was itself a direct message to you).

Replies are queued and the proxy relays them within a couple of seconds.

> **[Screenshot: Mesh Console → Zello inbox card with the reply modal open]**

### DM a specific unit (originate a direct message)

You can start a private Zello message to a named unit/person — you don't have to
wait for them to message you first.

1. Open **Mesh Console → Send / Compose**.
2. Set **Protocol** to **Zello**.
3. Set **Send to** to **Direct — unit / person**.
4. Pick the unit or person from the **To unit / person (DM)** list.
5. Type your message and click **Send**.

TicketsCAD resolves that unit/person to their **Zello username** automatically
and sends a per-user direct message (it still rides on the channel, but only
that user receives it).

> **Requirement:** the unit/person must have a **Zello username on file**. Add
> it in **Roster** → open the member → **Comm / Location IDs** section → add a
> **Zello** identifier with their Zello username. Units inherit their
> identifier from the person assigned to them. If a unit has no Zello username
> on file, the DM is refused with a clear message rather than silently
> broadcasting.

> **[Screenshot: Mesh Console → Send tab with Protocol=Zello, Send to=Direct,
> and a unit selected]**

### Channel audio — push to talk (PTT)

> **HTTPS is required for the microphone.** Browsers only grant microphone
> access on secure (HTTPS) origins. On plain HTTP, listening and text still
> work, but you can't transmit voice.

In the Zello widget:

- **Hold** the **Push to Talk** button (or hold the **Space** bar while the
  widget is focused and you're not typing in the text box). The button shows
  **TRANSMITTING** with a live level meter and a timer.
- **Release** to stop. Your voice goes out over the channel.

Incoming voice from the channel plays back **live** as it arrives, and is saved
as a replayable clip with a play button in the feed.

> **[Screenshot: Zello widget transmitting, VU meter active]**

### "Speak on channel" — text-to-speech broadcast

You can type a message and have TicketsCAD **speak it** onto the channel as
audio (instead of sending it as text). This is useful for hands-free or
automated announcements.

1. **Mesh Console → Send / Compose.**
2. Set **Protocol** to **Zello** and **Send to** to **Channel (broadcast)**.
3. Tick **Speak on channel (TTS audio)**.
4. Type the message and **Send**.

TicketsCAD synthesizes the text to speech and keys it onto the channel as Opus
audio.

> **Requirements / limits:**
> - **Piper TTS must be configured on the proxy host** (the server-side
>   text-to-speech engine and `ffmpeg`). Without it, a TTS send is marked
>   failed. This is a server-side setup item, not something the dispatcher
>   configures.
> - **TTS is channel-broadcast only.** Zello voice has no per-user address, so
>   you can't TTS-DM one unit — any DM target is ignored and it goes to the
>   whole channel.

> **[Screenshot: Send tab with Protocol=Zello, Channel broadcast, "Speak on
> channel" ticked]**

---

## 8. Troubleshooting

### "Channel offline" right after a successful login

**Symptom:** TicketsCAD authenticates to Zello (you see a logged-in/authenticated
status), but the channel shows **offline** and nothing you send goes anywhere.

**Cause:** the **Issuer / Private Key don't authorize that channel.** On Zello
Consumer the API key is **per channel** and only works for the channel it was
generated in. If you generated the key in channel A but pointed *Dispatch
Channel* at channel B — or if you used a key from a channel you only *joined*
rather than *administer* — Zello accepts the login but the channel won't come
online.

**Fix:** use a channel **you administer**, generate the Issuer + Private Key
**from inside that channel's** Channels API (step 4), and make sure the
*Dispatch Channel* name in TicketsCAD **exactly matches** that channel.

### "Signed in on another device" / the console keeps dropping

**Symptom:** the console connection drops whenever you (or someone) logs into
the **dispatch** Zello account somewhere else — e.g. you opened the Zello app on
your own phone using the console's account.

**Cause:** Zello allows only **one active session per account**. The TicketsCAD
proxy is holding that account's session; logging in elsewhere with the same
account knocks one of them off.

**Fix:** keep the **dispatch account** exclusively for the console. Use your
**separate field account** (`eric-handheld`) on your phone. If you must take
over the dispatch session intentionally, **stop the proxy** first
(`sudo systemctl stop newui-zello-proxy`, or close the `start-proxy.bat`
window).

### Voice "playback error" on saved clips

**Symptom:** voice messages show **"Voice (…s) — playback error"** and won't
play; live incoming audio may also fail.

**Cause:** the proxy can't write the recorded audio. It saves clips to
`cache/zello-audio/` under the NewUI install, and that directory must **exist
and be writable by the proxy service user** (`www-data` by default).

**Fix:**

```bash
sudo mkdir -p /var/www/newui/cache/zello-audio
sudo chown -R www-data:www-data /var/www/newui/cache/zello-audio
```

If you run the proxy under the **hardened systemd unit** (`ProtectSystem=strict`),
the filesystem is read-only except for the paths listed in `ReadWritePaths`.
The shipped example lists `…/proxy`, `/var/log/newui`, and `/tmp` — it does
**not** include the audio cache. Add it so the service is allowed to write
recordings, then reload:

```ini
# in /etc/systemd/system/newui-zello-proxy.service, under [Service]
ReadWritePaths=/var/www/newui/proxy /var/www/newui/cache/zello-audio /var/log/newui /tmp
```

```bash
sudo systemctl daemon-reload
sudo systemctl restart newui-zello-proxy
```

### "Requesting auth token… Disconnected from proxy" loop (HTTPS)

**Symptom:** on an HTTPS site, the widget cycles through "Requesting auth
token…" then "Disconnected from proxy" over and over.

**Cause:** the proxy daemon is running, but the browser's secure WebSocket
(`wss://your-host/zello-ws`) has nowhere to land — the Apache
`<Location /zello-ws>` reverse-proxy snippet is missing. Browsers block an
insecure `ws://` connection from a secure page, so the widget connects via the
`/zello-ws` proxy route on HTTPS.

**Fix:** add the `mod_proxy_wstunnel` snippet from
[step 6](#https-deployments--the-websocket-reverse-proxy-snippet-required) and
reload Apache. Check the proxy's own logs to confirm the upstream side is fine:

```bash
sudo journalctl -u newui-zello-proxy -n 100 --no-pager
```

> **On cPanel / WHM hosts:** you can't add the Apache snippet by editing config
> files directly — use **WHM's Include Editor** for the `<Location /zello-ws>`
> block, enable `mod_proxy_wstunnel` via **EasyApache** (there is no `a2enmod`),
> and note the logs live under `/usr/local/apache/logs/`. See the "On cPanel /
> WHM hosts" section of [`proxy/INSTALL-LINUX.md`](../proxy/INSTALL-LINUX.md)
> for the exact steps.

### "It says sent, but nothing arrives"

**Cause:** usually a **channel-side permission**. If the channel is set to
"only approved users can talk" and the **console account isn't an approved
talker** (or isn't an admitted member of a private channel), Zello drops your
text/audio silently while TicketsCAD reports it as sent.

**Fix:** make the console's Zello account an **administrator or approved
talker** on the channel (step 3), and confirm it has actually **joined** the
channel.

### A DM to a unit is refused

**Cause:** that unit/person has **no Zello username on file**.

**Fix:** add a **Zello** comm identifier for them in **Roster → member →
Comm / Location IDs**. A unit inherits the identifier of the person assigned
to it.

### Voice transmission doesn't work at all (no mic)

**Cause:** you're on **plain HTTP**. Microphone access requires HTTPS.

**Fix:** serve TicketsCAD over HTTPS. Listening and text messaging work on HTTP;
only transmitting voice needs the secure origin.

---

## 9. Quick reference

**Outside TicketsCAD (Zello side):**

| What | Where | Notes |
|---|---|---|
| Dispatch account | <https://zello.com/personal/> | The console logs in as this |
| Field account | Zello app on your phone | A **different** account, joined to the channel |
| Developer console | <https://developers.zello.com/> | Sign in as the dispatch account |
| Channel | Created in the Zello app | Own it (admin) so you can mint the API key |
| Issuer + Private Key | Channel admin → **Channels API** | **Per channel**; only authorizes that channel |

**Inside TicketsCAD:**

| Field (Settings → Zello Network Radio) | Value |
|---|---|
| Service Type | Consumer or Work |
| WebSocket URL | `wss://zello.io/ws` (Consumer) |
| Username / Password | The **dispatch** account |
| Issuer / Private Key | From the dispatch channel's Channels API |
| Dispatch Channel | Exact channel name the key was minted for |
| Connection Mode | Server Proxy (recommended) |

**Proxy:**

| Task | Command |
|---|---|
| Start (Windows dev) | `proxy\start-proxy.bat` |
| Start / restart (Linux) | `sudo systemctl restart newui-zello-proxy` |
| Stop (Linux) | `sudo systemctl stop newui-zello-proxy` |
| Logs (Linux) | `sudo journalctl -u newui-zello-proxy -f` |
| HTTPS WebSocket route | Apache `<Location /zello-ws>` → `ws://127.0.0.1:8090/` |

**Where each feature lives in the UI:**

| Feature | Location |
|---|---|
| Open the Zello widget | Dashboard → **Zello** button (megaphone) |
| Broadcast text / PTT voice | Zello widget |
| Inbox + reply | **Mesh Console** → Zello inbox card |
| DM a unit | **Mesh Console → Send** → Protocol = Zello, Send to = Direct |
| Speak on channel (TTS) | **Mesh Console → Send** → Protocol = Zello, Channel, tick "Speak on channel" |
| A unit's Zello username | **Roster** → member → **Comm / Location IDs** → add Zello |

---

*If you get stuck, the most common single cause is the **per-channel API key**:
the Issuer/Private Key must be generated inside the same channel you put in
the Dispatch Channel field, on a channel you administer. Re-check
[step 4](#4-get-the-channels-api-credentials-the-key-gotcha) first.*
