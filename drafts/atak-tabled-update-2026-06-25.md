# Draft email — ATAK over Meshtastic / TicketsCAD update

**Audience:** TicketsCAD beta testers who expressed interest in ATAK integration.

**Format note:** plain prose, Gmail-friendly. No markdown lists or fenced code blocks (Gmail rich-text composes those into columns when pasted). Send in Plain Text mode (Gmail compose → three-dot menu → Plain text mode) for safest paste.

---

**Subject:** ATAK over Meshtastic in TicketsCAD — what works, what's blocked, where we go from here

Hi all,

Quick honest update on the ATAK-over-Meshtastic integration in TicketsCAD. Short version: server side works, the client side hit an upstream Meshtastic regression we couldn't fully fix from our end, and we're tabling the rich-content path while waiting on patches we filed upstream.

The longer version below if you want the technical detail. Otherwise: identity and presence work today, marker content delivery is blocked on Meshtastic — we'll resume when they merge our fix.

WHAT WE BUILT

TicketsCAD's mesh bridge now captures ATAK traffic from any node on a connected Meshtastic LongFast channel. The packets land in our mesh_packet_log with correct UTC timestamps, attributed to the source radio. Phase 91 in the project repo, commits 1c9c590 through 0056ac4, with a full setup-log at specs/phase-91-atak-interop/setup-log.md if you want the deep dive.

The server side is genuinely done. Anything the radio side delivers, TicketsCAD captures.

WHAT WORKS TODAY (identity and presence)

If you configure ATAK CIV with Meshtastic-Android's built-in TAK Server (Meshtastic app → Advanced → Local TAK Server, then import the generated package into ATAK), TicketsCAD sees the wrapper packets every time you act in ATAK. We log: source node, channel, signal strength, timestamp. That's enough to build a "who is on the mesh right now" dispatcher view, which we have.

WHAT DOESN'T WORK (marker content)

The actual marker, position, and chat content does not survive the trip. The Meshtastic-Android app's local TAK Server only forwards content-empty wrapper packets over LoRa on the legacy v1 channel (port 72). We verified this at the library level — the packets really do carry empty payloads on the wire. Not a TicketsCAD bug.

Meshtastic ships a v2 protocol on port 78 that DOES carry full content, but it only activates when the connected radio firmware is 2.8.0 or newer, and firmware 2.8.0+ hasn't widely shipped yet.

The other path — the legacy "ATAK Plugin for Meshtastic" — would carry full content, but it's been broken on every Meshtastic-Android version newer than 2.7.13 since April 2026. Open issue at meshtastic/ATAK-Plugin#111. The plugin maintainer noted he'd rebuild when 2.7.14 hits the Play Store, but no updated plugin has shipped in two months.

WHAT WE DID ABOUT IT

We traced the regression to its actual root cause. Three things changed simultaneously in Meshtastic-Android v2.7.14:

The MeshService class was repackaged (fine, refactor work). The android:exported flag was flipped from true to false (this is what blocks the plugin — Android's security model refuses cross-app bindings to non-exported services). And the canonical intent-filter was dropped.

Filed two upstream PRs with the patches:

meshtastic/Meshtastic-Android pull request 5947 — restore the export flag and intent-filter on the relocated service declaration.

meshtastic/ATAK-Plugin pull request 114 — update the plugin's bind-target constant to track the package rename.

Both PRs need to merge for the plugin to bind on Meshtastic-Android 2.7.14 and newer. Posted a comment on issue 111 with the full analysis and PR links so anyone tracking that thread sees the fix path.

TABLING THIS UNTIL UPSTREAM MOVES

Without the upstream fixes, the only paths to full ATAK content visibility are: (a) downgrade your Meshtastic-Android to 2.7.13 (works on a per-tester basis, instructions in the setup-log if you want them), or (b) we maintain custom-built APKs of the patched apps and distribute them, which is more maintenance overhead than the current audience size justifies.

We're going to wait on upstream. If either PR merges, beta testers get the fix via normal Play Store update — no work on your end. If they sit for months, we'll revisit the custom-build option.

EXPECTATIONS

If you tried the integration and hit "Connected Node: Not connected" in the ATAK plugin, that's the bug we documented above. Not your fault, not your configuration. If you want the immediate workaround, reply and I'll send the downgrade-to-2.7.13 procedure.

If you weren't actively testing ATAK yet, just sit tight. When upstream merges or when we have time to come back to this, you'll get another update.

Real progress was made — the server side is genuinely solid and ready to receive content the moment the upstream issues clear. Thanks for the patience and for the original push to make ATAK interop a priority.

Eric

---

## Internal notes (not part of the email)

- This goes to beta-testers who explicitly expressed interest in ATAK. Not the full TicketsCAD beta-tester list.
- If anyone replies asking for the downgrade procedure, point them at `specs/phase-91-atak-interop/setup-log.md` Path 2 section, or just copy the relevant paragraph (download URL + steps).
- If anyone asks "what about the custom-build path you mentioned" — the answer is the friction breakdown in this same session's chat: package collision, GPL compliance, trademark, and ~2 weeks of Google Play first-account onboarding for an unused developer account. Custom builds make sense if upstream goes silent for a few months; not before.
- Subject line option B if "what works, what's blocked, where we go from here" feels too long: "ATAK over Meshtastic — tabled pending upstream fix"
