# ATAK / TAK setup — operator guide

> **Status:** outline / skeleton. The full document is filled out as Phase 91 ships. Use this as the planning artifact for the matching training video and to gauge what setup will look like.
> **Spec:** `specs/phase-91-atak-interop/spec.md`.
> **Authored:** 2026-06-24 (Eric initiated planning).

This guide gets you from "I have ATAK installed and want to use it with TicketsCAD" to "ATAK and TicketsCAD share incidents, units, facilities, and notes in real time." Pick the path that matches your deployment.

---

## Pick your path

| You have... | Use |
|---|---|
| **ATAK on phones/tablets + Meshtastic radios, no IP infrastructure** | [§1 — Meshtastic-only mesh](#1-meshtastic-only-mesh) — works fully off-grid |
| **ATAK on devices with cell or Wi-Fi to the internet** | [§2 — TAK Server federation](#2-tak-server-federation) — IP-connected clients |
| **Both** | [§3 — Hybrid (mesh + IP)](#3-hybrid-mesh--ip) — TicketsCAD bridges both transports to the same world |
| **Just want to load a TicketsCAD operational area as a one-shot map overlay** | [§4 — KML / Data Package floor case](#4-kml--data-package-floor-case) — already supported via the existing Phase 43c export, no real-time integration |

All paths share the same CoT translation layer inside TicketsCAD; the difference is transport.

---

## Prerequisites — once per TicketsCAD install

### Enable the ATAK provider

1. Sign in as Super Admin.
2. **Config → Location Providers**.
3. Find the **ATAK** row and toggle it on.

### Configure the link transport(s)

Open **Config → Communications & Integrations → ATAK / TAK**. You'll see three subsections (filled in by Phase 91 Slice 5):

- **Meshtastic transport** — pick which Meshtastic channels carry ATAK CoT traffic.
- **TAK Server transport** (optional, v1.5) — paste your TAK Server URL, upload TLS cert if required.
- **Push policy** — per-channel control of which entity classes flow out (incidents / units / facilities / chat).

### Bind ATAK devices to personnel

A CoT message arriving carries the ATAK user's `uid` and `callsign`. TicketsCAD needs to know which dispatcher-visible personnel record that maps to. Open **Roster → Personnel → [person] → Comm Identifiers → Add**, pick **ATAK** from the dropdown, paste the ATAK uid (or paste the callsign — both can serve as the binding key per your policy).

Same person can have multiple identifiers — phone-side ATAK uid AND a Traccar IMEI from an in-vehicle modem; the dispatcher map sees whichever provider reported last.

---

## 1. Meshtastic-only mesh

**Best for:** SAR teams, storm spotters, CERT/ARES/RACES deployments without reliable cell coverage. ATAK + Meshtastic radio works fully off-grid.

### On each Android device

1. Install **ATAK-CIV** from the Play Store (or your agency's APK distribution channel).
2. Install the **Meshtastic ATAK Plugin** from the Play Store (current version range pinned by Phase 91 — see [Compatibility matrix](#compatibility-matrix) below).
3. Pair the Meshtastic radio (T-Beam, RAK, Heltec, etc.) to the phone via Bluetooth.
4. In Meshtastic Android app, set the device to the channel TicketsCAD is configured for. Use the same PSK across every device on the operation.
5. In ATAK: **Settings → Network Connections → Manage Server Connections → Add → "Meshtastic Plugin"** (the plugin registers itself as a transport).
6. Confirm: open ATAK's map; you should see other Meshtastic-mesh ATAK users appear within ~60 seconds.

### On the TicketsCAD side

1. Confirm the Meshtastic bridge is running: `systemctl status meshbridge.service`.
2. **Config → Communications & Integrations → ATAK / TAK → Meshtastic transport** — pick the same channel ATAK devices joined, enable CoT routing on it.
3. **Mint a device token per ATAK user** at **Config → Location Ingest** (the same per-device token UI that Traccar/OpenGTS uses — see [docs/TRACCAR-SETUP.md](TRACCAR-SETUP.md)). Bind each token to the ATAK user's uid.
4. Bind the ATAK uid to the personnel record (see Prerequisites).

### Verify

- Drop a marker on any ATAK device. Within ~30 seconds the marker shows up in TicketsCAD as a new incident at that location, attributed to the bound personnel.
- Create an incident in TicketsCAD. Within ~30 seconds every ATAK in the mesh shows the marker.
- Watch the **Recent CoT events** table at **Config → ATAK / TAK** for live bidirectional traffic.

---

## 2. TAK Server federation

**Best for:** deployments where ATAK devices have IP (cell, Wi-Fi, agency LAN). One TAK Server handles federation; TicketsCAD publishes to it.

> **Available in Phase 91 v1.5.** Slices 1–3 (Meshtastic) ship first; this section gets fleshed out when Slice 4 lands.

Sketch of what the section will cover:
- Standing up FreeTAKServer on your-host (~2GB VM, Docker compose, Apache 2.0).
- Generating TLS certificates and distributing client certs to ATAK devices.
- Pointing TicketsCAD at the TAK Server URL + cert in the admin panel.
- ATAK-side configuration (server URL, port, cert import).
- The federation model (mission groups, data-sync filters).
- Combining with the Meshtastic transport (the same CoT events go out both paths).

---

## 3. Hybrid (mesh + IP)

**Best for:** mixed deployment — some volunteers in the field with Meshtastic radios, others at base or on cell. Both see the same world.

> **Available in Phase 91 v1.5.** Configuration is straightforward once §1 and §2 work independently: enable both transports in the admin panel, set per-channel push policy if you want certain entity classes (e.g., facility positions) to flow over Meshtastic but not IP.

---

## 4. KML / Data Package floor case

**Best for:** one-shot sharing of TicketsCAD operational areas with ATAK users you don't otherwise integrate with. No real-time link.

> **Available today** — the existing Phase 43c KML export covers this. A future enhancement will wrap the KML plus a `MANIFEST.xml` into an ATAK Data Package (`.zip`) for one-tap import via ATAK's Data Sync menu.

To use today: in TicketsCAD, open the relevant operational area, **Export → KML**, send the file to the ATAK user. They open it in ATAK via **File Manager → Import**.

---

## Authentication and trust

Same model as the Traccar/OpenGTS receiver (Phase 89):
- **Anonymous** (default for evaluation; fine on private mesh or behind agency-managed TAK Server with client certs).
- **Per-device tokens** (recommended for production — one leaked token revokes one device). Mint, list, revoke from the same **Config → Location Ingest** panel that handles Traccar tokens; tokens are scoped per provider, so an ATAK token can't be reused as a Traccar token.
- **Shared secret** (legacy fallback, single secret all devices use).

For the TAK Server path, additionally:
- **TLS client certificates** (standard TAK practice). TicketsCAD presents its cert; the TAK Server's CA chain validates it. Document operator certificate lifecycle separately.

---

## Privacy and CJIS posture

The **per-channel "sensitive" flag** at **Config → ATAK / TAK** is the lever. When set on a Meshtastic channel or TAK Server connection:
- TicketsCAD strips any field marked PII from outbound CoT (patient names in incident notes, e.g.).
- Non-PII fields (incident type, lat/lng, severity, callsigns) still flow.
- Default: strict — operator opts in per channel.

For deployments running actual CJIS workloads, the recommendation is: treat any open mesh channel as untrusted public and never push CJIS-protected fields. The flag enforces this.

---

## Verifying it works

Same pattern as Phase 89's "Recent reports" panel — **Config → ATAK / TAK → Recent CoT events** shows the last 50 messages each direction (inbound from ATAK, outbound from TicketsCAD), refreshing live. Use this to verify a freshly-onboarded device is reaching the system without dropping to a shell.

Diagnostic SQL probe (escape-hatch only; the UI is the canonical view):
```sql
SELECT lr.unit_identifier, lr.lat, lr.lng, lr.reported_at, t.label AS token
  FROM location_reports lr
  JOIN location_providers lp ON lp.id = lr.provider_id
  LEFT JOIN location_ingest_tokens t ON t.id = lr.auth_token_id
 WHERE lp.code = 'atak' AND lr.reported_at > NOW() - INTERVAL 10 MINUTE
 ORDER BY lr.id DESC LIMIT 20;
```

---

## Compatibility matrix

> **Pinned by Phase 91.** This will list:
> - Tested ATAK-CIV version range
> - Tested Meshtastic ATAK Plugin version range
> - Tested Meshtastic firmware version range
> - Tested TAK Server version range (for §2)
> - Known incompatibilities (older plugin protobuf format revisions, etc.)

---

## Troubleshooting

> Filled in during Phase 91 v1 implementation. The shape mirrors [docs/TRACCAR-SETUP.md](TRACCAR-SETUP.md)'s troubleshooting matrix:
> - Symptom → likely cause → fix
> - Covering: no marker appears, marker appears but wrong personnel, 401, channel saturation, NPM/cloudflared header mangling for the IP path, TLS cert errors.

---

## When to use ATAK vs. TicketsCAD

A mapping document so the operational "which tool do I open when?" decision is explicit. Will live at `docs/ATAK-FEATURE-COMPARISON.md` (to be authored as part of Phase 91 Slice 5; not yet in the repo).

| Concern | TicketsCAD NewUI | ATAK | Notes |
|---|---|---|---|
| Incident creation and central record | ✅ primary | ⚠ via inbound CoT | TicketsCAD is the system of record |
| ICS forms (213, 214, 202, 205, 213rr) | ✅ | ❌ | TicketsCAD only |
| Audit log + reports | ✅ | ❌ | TicketsCAD only |
| Field navigation, route planning | ⚠ basic | ✅ primary | ATAK is purpose-built |
| Offline / field-tablet UX | ⚠ PWA install only | ✅ native | ATAK wins field-side |
| Multi-agency federation | ⚠ single-tenant | ✅ designed for it | TAK Server bridges this |
| Position tracking | ✅ multi-provider | ✅ native CoT | Both, bridged |
| Mesh comms | ✅ Meshtastic + MeshCore | ✅ via Meshtastic plugin | Same underlying mesh, different presentation |

---

## Related docs

- `specs/phase-91-atak-interop/spec.md` — the spec this guide implements.
- [docs/TRACCAR-SETUP.md](TRACCAR-SETUP.md) — sibling integration; reuses the same per-device-token infrastructure.
- [docs/OWNTRACKS-CONFIG-PUSH.md](OWNTRACKS-CONFIG-PUSH.md) — the per-provider equivalent for OwnTracks; useful comparison reading for how a single-protocol integration is set up.
- [docs/MESH-BRIDGE-GUIDE.md](MESH-BRIDGE-GUIDE.md) — the underlying Meshtastic bridge service this integration extends.
