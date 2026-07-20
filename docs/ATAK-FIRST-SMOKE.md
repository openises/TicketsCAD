# ATAK first smoke — what's set up, what you do next

**Audience:** the operator standing up the first ATAK + Meshtastic test of TicketsCAD Phase 91, after the consolidation that folded ATAK routing into the existing Phase 35 mesh-bridge infrastructure. One-page checklist; the long version is [docs/ATAK-SETUP.md](ATAK-SETUP.md).

---

## How this works (post-consolidation)

ATAK is **layered onto the existing mesh bridges** rather than running as a separate service:

```
ATAK-CIV phone (with Meshtastic ATAK Plugin)
   │ Bluetooth
   ▼
your phone-side Meshtastic radio
   │ LoRa mesh
   ▼
training-side Meshtastic radio (paired to one of the existing mesh bridges)
   │ USB / TCP / MQTT (whatever transport the bridge uses)
   ▼
existing meshbridge daemon on your-host or your-host
   │ HTTPS POST to api/mesh.php?action=ingest
   ▼
TicketsCAD api/mesh.php
   ├──→ mesh_packet_log row (existing behavior — visible on mesh-console.php)
   └──→ NEW: atak_route_inbound() when port_kind=ATAK_PLUGIN or text starts with '<event'
            → location_reports row attributed to bound personnel
            → OR atak_unbound_uids row if the uid isn't bound yet
            → marker → new incident OR note on nearest open incident
            → chat → broker_send('local_chat', ...)
```

**No new daemon, no new auth flow.** The existing two bridges (`meshbridge-01` on your-host, `meshbridge-02` on your-host) already deliver every packet they see. The consolidation hook in `api/mesh.php` recognizes ATAK content and additionally routes it.

---

## What's already in place on training (you don't touch this)

| Piece | Status |
|---|---|
| Two mesh bridges live | ✅ `meshbridge-01` (your-host) + `meshbridge-02` (your-host) — verified live on [https://your-server.example.com/mesh-console.php](https://your-server.example.com/mesh-console.php) |
| `mesh_channels` table extended with `atak_*` policy columns | ✅ via `sql/run_atak_consolidation.php` |
| `api/mesh.php` ingest hook → CoT routing | ✅ deployed; gracefully no-ops if ATAK isn't enabled on any channel |
| `inc/cot.php` + `inc/atak_route.php` | ✅ 50 CoT unit tests passing |
| `atak_unbound_uids` review queue | ✅ table present, surfaced in the admin UI |
| Old parallel `atak_bridge.py` service + venv + env file + `atak_channels`/`atak_push_log` tables | ✅ **removed** from training (consolidated) |
| ATAK provider in `location_providers` | ✅ enabled |
| `comm_modes.atak` row | ✅ present (Roster shows ATAK in the comm-identifier dropdown) |
| Admin panel at **Config → ATAK / TAK (CoT bridge)** | ✅ deployed; reads from `mesh_channels` + `mesh_packet_log` |

---

## What YOU do — in this order

### 1. Find a mesh channel to use for ATAK

Open [https://your-server.example.com/mesh-console.php](https://your-server.example.com/mesh-console.php) and look at the **Channels** section. Pick the channel both bridges have access to (likely `LongFast`). Note its **PSK** — you'll set the same PSK on your phone-side radio.

### 2. Enable ATAK routing on that channel

In TicketsCAD: **Config → ATAK / TAK (CoT bridge)** → find your channel in the table → **Edit** → tick **"Route ATAK CoT for this channel"** → Save.

Default policy is sensitive=ON (PII stripped), push everything, markers create new incidents. Adjust if you want different behavior.

### 3. Pair your phone-side Meshtastic radio with your phone

In the Meshtastic Android app:
- Connect to your radio via Bluetooth.
- Open Channels → set the primary channel to the same name + PSK as the bridges use (e.g., `LongFast`).
- Save.

### 4. Pair your phone-side radio with ATAK-CIV via the Meshtastic ATAK Plugin

In ATAK-CIV:
- Settings → Tool Preferences → Specific Tool Preferences → **Meshtastic ATAK Plugin**.
- Connect to the same Meshtastic radio over Bluetooth (close the Meshtastic Android app first if needed — only one app can hold the BT session at a time).

### 5. Find your ATAK device UID

In ATAK-CIV: Settings → Device Preferences → **My Callsign and Device Preferences** → **UID**. Copy that string.

### 6. Bind your UID to your personnel record

In TicketsCAD: **Roster → Personnel → [your record] → Comm Identifiers → Add → ATAK**, paste the UID, save.

If you skip this step, positions still land — they'll show up under **Config → ATAK / TAK → Unbound ATAK devices**, and you can bind retrospectively.

### 7. Drop a marker on your phone, watch it land

On your phone: open the ATAK map → long-press → drop a marker.

In TicketsCAD: **Config → ATAK / TAK (CoT bridge) → Recent CoT events** → refresh. Within 30 seconds you should see an inbound row from your bridge with `port_kind=ATAK_PLUGIN` or `TEXT_MESSAGE_APP` and the marker text in the payload preview.

Then check **Roster → Personnel → [your record]** — the position should be plotted on the dispatch map (assuming the UID binding from step 6).

---

## Diagnostic if something doesn't land

| Symptom | First place to look |
|---|---|
| Nothing arrives at all | [mesh-console.php](https://your-server.example.com/mesh-console.php) packet feed — does any packet from your phone show? If no, the channel + PSK don't match between your phone-side radio and the training-side bridge radio. |
| Packets in mesh-console but not in the ATAK panel | Channel doesn't have ATAK enabled — re-do step 2. Or the packet's `port_kind` isn't `ATAK_PLUGIN` and the payload isn't raw CoT XML — the plugin version may differ from what the bridge daemon recognizes. |
| Position lands but personnel attribution wrong | UID binding mismatch — check **Roster → Personnel → Comm Identifiers** vs. what your ATAK app shows for UID. |
| Position shows in Unbound list but never bind retrospectively | Roster doesn't yet show the position because the binding happened after the report landed. New reports after the bind will attribute correctly; historical Unbound rows stay attributed to the pseudo-record (no rewrite). |

For deeper diagnostics: `sudo journalctl -u <name-of-the-bridge-service-on-your-host-or-your-host> -f` — watch the bridge daemon's live logs.

---

## Quick reference

| Thing | Value |
|---|---|
| Training URL | https://your-server.example.com |
| Mesh Console | https://your-server.example.com/mesh-console.php |
| ATAK admin panel | https://your-server.example.com/settings.php#atak-tak |
| Operator guide (long form) | [docs/ATAK-SETUP.md](ATAK-SETUP.md) |
| Phase 91 spec | specs/phase-91-atak-interop/spec.md |

---

## What's NOT yet wired (defer to a later session)

- **Outbound emission** (TicketsCAD incident → ATAK marker). The push policy fields exist on `mesh_channels` but no emitter is hooked into the incident/responder/facility lifecycle yet. We wire that once inbound smoke is green.
- **TAKPacket protobuf decode validation against real plugin output.** The hook in `api/mesh.php` handles two formats (TAKPacket fields under `payload_json` AND raw `<event>` XML in `payload_text`); first smoke might reveal the bridge daemons need to be taught to decode and ship the protobuf fields. If they're not currently parsing port 72, that's a fix on the bridge-daemon side (separate from this PHP side).
- **TAK Server federation (v1.5)** — IP path is a separate phase.
- **Bloomington UI smoke** — code + migration deployed there, but no live bridges on Bloomington yet, so nothing to receive ATAK packets locally. Mirror demo after training is green.
