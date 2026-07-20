# Radio AI — Operator Guide

**Audience:** licensed amateur radio operator at the TicketsCAD console.
**Goal:** review and release AI-drafted voice responses to callers on the talkgroup, with the AI never transmitting on its own.
**Page:** `/radio-ai.php` (nav: **Radio AI**).
**Permission required:** `action.dmr_transmit` (same as manual PTT).

---

## What this page is

The Radio AI page is where you handle responses that the **listener daemon** drafts for callers who address Claude on the talkgroup. The system is **operator-in-the-loop by design** — Claude never keys the radio on its own. Every transmission goes out only because a licensed operator clicked **Approve & Send** or because they enabled the **Auto-approve** safety-limited toggle for a defined window.

Under FCC §97.119 you, the licensed operator, are the **control operator** for every transmission. Approving a draft makes you legally responsible for what goes on the air, exactly the same as if you had keyed up and said it yourself.

---

## The columns of an AI draft

When the listener picks up a wake-word transcript ("Claude, ...") on a watched channel, a card appears:

| Field | What it means |
|---|---|
| **caller callsign** | The transmitting station, looked up from radioid.net via the local cache. Falls back to the bare DMR ID if the cache doesn't know them yet. |
| **status badge** | `pending_generation` (Claude is thinking), `pending_approval` (ready for you), `filtered` (a content-filter flag fired — read carefully), `error` (the draft couldn't be generated). |
| **age** | Seconds since the wake-word was heard. Old drafts age out. |
| **Caller said** | What the listener transcribed. This is what Claude based the response on. |
| **Draft response** | The text Claude wrote. This is exactly what will be spoken if you approve. |

---

## The three buttons

| Button | Effect | Use when |
|---|---|---|
| **Approve & Send** | Posts the text to the bridge, which TTS-encodes and transmits on-air. Card disappears. | The draft is accurate and you want it heard right now. |
| **Edit** | Swaps the draft for a textarea. Save updates the draft; the row stays pending so the next click can be Approve. | The draft is close but needs a small fix — typo, callsign sign-off, factual correction. |
| **Reject** | Marks the draft `discarded`, no transmission. Card disappears. | The draft is wrong, off-topic, or the channel context has moved on. |

---

## The two toggles in the toolbar

### Dry run

When **on**, **Approve & Send** runs the full pipeline (Piper TTS, AMBE encode, packet framing) **but skips the on-air transmission**. The card vanishes exactly as if it had been sent, and the console log shows `Dry run OK — N packets, M ms`. Use it to rehearse the workflow without bothering operators on the talkgroup.

The bridge logs the dry-run TX too, so you can verify the synthesised duration looked right before flipping the toggle off and going live.

### Auto-approve

When **on**, any draft that lands in `pending_approval` status is automatically Approved on the next 10-second poll. Three hard safeties:

1. **2-hour ceiling.** When you turn it on, an expiry stamp gets written to your browser. A watchdog independent of the polling loop flips it OFF the moment 2 hours pass, regardless of whether anything has been on the air.
2. **Closing the tab = OFF.** Auto-approve is per-browser-session. If you close the page and come back, you have to re-check the box.
3. **Filtered drafts never auto-fire.** If Claude's content filter flagged the draft (`status=filtered`), it always requires you to look at it manually. The auto path skips filtered rows entirely.

When auto-approve is active you'll see a small yellow **"off at HH:MM"** badge next to the toggle so you can see how much window is left at a glance.

The dry-run and auto-approve toggles are **independent**. You can leave both on to have the system continuously rehearse drafts without anything actually transmitting — the natural way to leave it running while you work on something else and want a sanity check that the listener is alive.

---

## When to edit vs reject

| Situation | What to do |
|---|---|
| Draft is factually wrong | Reject. A wrong answer on the air reflects badly on N0NKI and on the station. |
| Draft is technically right but missing context the listener didn't know about | Edit to add the context, then approve. |
| Draft skipped the closing callsign ID and this transmission ends a conversation | Edit to append `n 0 n k i` (space-separated so Piper pronounces each character) before approving. |
| Channel got busy in the seconds between the listener picking up the question and the draft landing | Reject if the moment has passed; the caller has likely moved on. |
| The wake-word was a false trigger (somebody used the word "Claude" in unrelated chatter) | Reject. |
| Draft is appropriate but Claude rambled | Edit down to the essential answer. Voice traffic is precious — shorter is almost always better. |

---

## What you should NOT use the AI for

The system has a content filter that catches obvious problems, but it can't catch everything. Don't approve drafts that:

- **Contain phone numbers, addresses, or URLs.** §97.113 forbids business communications and most personal-data exchanges over amateur radio.
- **Use profanity or coarse language.**
- **Make claims about emergencies, weather warnings, or public-safety incidents** unless you have first-hand verification. The bot can hallucinate, and a fake severe-weather report is its own kind of FCC problem.
- **Promote a product, service, or commercial venture.** Same §97.113 prohibition.
- **Carry traffic for somebody who isn't licensed.** If a caller is asking the bot to relay a message to a non-ham, that's third-party traffic with country restrictions.

When in doubt, reject and key up yourself.

---

## Conversational context

The system maintains rolling per-callsign conversation history (the `ai_conversations` + `ai_conversation_messages` tables). When the same caller comes back within the configured window, Claude sees the previous exchanges and can give a more useful follow-up. You don't have to do anything to enable this — it just works.

If you want to break the context (e.g. somebody borrowed the caller's radio and is asking about something completely different), you can edit the draft to be a fresh-topic reply. The next exchange will be in the same conversation thread, though, until the daemon's idle timer expires.

---

## What to do if the page is silent

| Symptom | Likely cause | What to check |
|---|---|---|
| **"No drafts waiting for review."** but you heard somebody say "Claude" on the radio | Listener daemon isn't running, or the channel isn't in `radio_ai_channel_ids`, or `radio_ai_enabled` is `0`. | Ask your admin to check the listener service and the settings table. |
| Page shows pending rows but Approve does nothing | Bridge is down (no TX possible) OR your operator account is missing `action.dmr_transmit`. | Status badge will turn to `error` and the row stays visible with the bridge response. |
| `403` on page load | You lack `action.dmr_transmit` on this org. | Ask your admin for the permission. |

---

## Related pages

- [/dmr-archive.php](../dmr-archive.php) — full history of all RX/TX on a channel, with playback. Useful to listen back to what the caller actually said vs what the listener transcribed (transcripts aren't always perfect).
- [Radio Widget](../index.php) on the dashboard — live RX feed and manual PTT. Use this to key up yourself when you need to.
