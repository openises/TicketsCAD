# Acoustic Proximity Auto-Discovery — Manual Test Procedure

The tone-generation and FFT-decode math (`assets/js/console-beacon.js`)
is proven mechanically in `tests/test_phase152_beacon_discovery.php`
(Node-driven, no browser needed). What that suite **cannot** prove is
whether two real workstations, with real speakers and a real microphone,
in a real room, actually hear each other's near-ultrasonic tone through
the air. That is genuine acoustic behavior — it depends on hardware,
distance, and ambient noise, none of which a fixture can stand in for.
This is the live procedure for confirming it actually works, per
`specs/phase-152-comms-console-v2/tasks.md`'s own requirement.

Run this once after deploying the feature to a real environment, and
again any time the tone plan (`FREQS` in `console-beacon.js`) or the
detection threshold changes.

## Prerequisites

- Two physical machines (or a machine and a phone/tablet — anything with
  a working speaker and microphone) able to load the console at the same
  time, positioned as they realistically would be in the dispatch room —
  typically 3-10 feet apart, not stacked on the same desk.
- `console_beacon_discovery_enabled` must be on (Settings → Console
  Positions, "Acoustic Proximity Auto-Discovery" — Super Admin only).
- Both machines' operating systems must actually grant the browser
  microphone permission — check this first; a silently-denied permission
  prompt is the single most common reason a real test "fails."

## Procedure

1. Log into the console (`console.php`) on **Workstation A** and
   **Workstation B**, each in its own real browser, on its own real
   machine, positioned near each other.
2. On each machine, confirm the workstation identity bar shows "This
   workstation: (unnamed)" or an existing label. Name them something
   recognizable — click the pencil icon, type "Desk A" / "Desk B" — so
   the search result is unambiguous.
3. On **Workstation A**, click "Hearing yourself? Nearby workstations" to
   open the panel, then click "Search automatically."
4. Within about 4 seconds:
   - **Workstation B** should play a brief tone. It is intentionally
     near-inaudible to most adult hearing — do not expect to clearly
     "hear" it as a normal tone; a very faint, high, brief hiss or
     nothing perceptible at all is the expected, correct behavior. If
     your own hearing is more sensitive to high frequencies (common in
     children and some adults), you may hear a faint chirp — this is not
     a bug.
   - **Workstation A**'s panel should update to "Found and muted: Desk
     B" (or whatever label B carries).
5. Confirm the pairing actually applied: on Workstation A's panel, "Desk
   B" should now show as checked in the manual list too (auto-discovery
   populates the same list manual entry uses, per design). Workstation
   B's own panel should independently show "Desk A" checked as well —
   remember, a discovered match is created in **both directions** in one
   action, not just the initiator's side.
6. Repeat in the other direction (search FROM Workstation B) to confirm
   symmetry — it should either report B already having discovered A (no
   new pairing needed) or independently succeed the same way.

## What to check when it does NOT work

| Symptom | Likely cause | Fix |
|---|---|---|
| "No nearby workstations detected" every time | Microphone permission was denied, or the OS/browser blocked it silently | Check the browser's site permissions for the console's origin; check OS-level microphone privacy settings |
| Detected on some searches but not others | Real acoustic variability (distance, ambient noise, speaker volume) — this is disclosed as best-effort, not guaranteed | Move the workstations closer together for the test, or raise the OS/browser output volume on the responding machine, and try again — manual entry is always the reliable fallback |
| A phone/tablet used as one "workstation" never plays or detects the tone | Some mobile browsers apply more aggressive high-frequency rolloff or noise suppression than desktop browsers, even with the constraints this feature explicitly requests | Try a different mobile browser, or use it only as the RESPONDER (playing the tone) rather than the initiator (detecting it), since playback is far more reliable than mobile capture in practice |
| The search button never appears | `console_beacon_discovery_enabled` is off, or you don't hold `action.manage_positions`/the page never resolved a workstation token | Confirm the setting in Settings → Console Positions; confirm `assets/js/console-workstation.js` loaded (check the browser console for errors) |

## What "pass" looks like

At minimum, confirm ONE successful cross-machine discovery in each
direction, with the resulting mute pairing visible on both sides
afterward. Record the date, the two devices/browsers used, and the
approximate physical distance between them in this project's own
`specs/phase-152-comms-console-v2/tasks.md` changelog when this is run
against a real deployment (training/your deployment or the dedicated
validation instance), since browser/OS audio-pipeline behavior can shift
across updates and this is exactly the kind of check that should be
re-run after any major browser version change on the fleet.
