# Mesh Bridges — administrator guide

This guide covers the distributed LoRa-mesh integration shipped in Phases 34–39.

## What a bridge is

A *bridge* is a Linux host (Proxmox VM, Raspberry Pi, NUC, etc.) with one or two LoRa radios (Heltec V3 / T-Beam / similar) attached. A small Python daemon — `bridge_v2.py` — runs on it and:

- listens to the attached radio(s),
- ingests every packet into TicketsCAD via authenticated HTTPS,
- polls TicketsCAD for outbound work (send text, configure device, change channel),
- applies that work to the attached radio.

The CAD instance itself never talks to a radio directly — that's the bridge's job. This lets you deploy as many bridges as you need across geographic coverage.

## Connection methods (Phase 39D)

| Transport | bridge_v2 `--port` value | When to use |
|---|---|---|
| USB Serial | `/dev/ttyUSB0` | Default. Radio plugged directly into the bridge host's USB. |
| TCP / IP | `tcp:192.168.1.50` (or `tcp:host:4403`) | Radio configured for Wi-Fi via the Meshtastic phone app. No USB needed. |
| Bluetooth LE | `ble:Meshtastic_a1b2` | Heltec paired to the bridge host via `bluetoothctl`. |
| MQTT | *coming next* | Subscribe to the public Meshtastic MQTT broker or a private one. |

The Mesh Console → **Setup** tab generates a tailored install script for any chosen bridge + transport.

## Channels (Phase 39B)

Meshtastic supports up to **8 channel slots** per radio. Slot 0 is the *primary* — that's the channel used for the public LongFast traffic by default, and where the channel-share URL is anchored.

Every channel has a **PSK** (pre-shared key). PSKs are stored base64-encoded:
- 0 bytes → broadcast (no encryption)
- 1 byte → use a well-known public key (the default LongFast slot is `AQ==`, i.e. `0x01`)
- 16 bytes → AES-128
- 32 bytes → AES-256 (recommended for private organizational channels)

### Sharing a channel

1. Go to **Mesh Console → Channels → Share / QR** for the channel.
2. Either scan the QR code from a Meshtastic phone app (which decodes the URL and offers to add the channel), or copy the URL / PSK and send it to the recipient out-of-band (email to a verified contact, in-person, etc.).
3. **The PSK is a secret.** Anyone with it can read AND send messages on the channel.

### Assigning a channel to a bridge

The **Assign a channel to a bridge slot** card on the Channels tab lets you pick a bridge × channel × slot. On Apply, TicketsCAD queues a `set_channel` outbox item — the bridge picks it up within 5 seconds and writes the slot on the attached radio.

## Direct messages (Phase 39C)

In the **Send / Compose** tab, the **To node** dropdown lists every node TicketsCAD has heard recently. Pick a node to send a DM (uses Meshtastic's `destinationId` with `wantAck=True`). Leave it on *Broadcast* to send on the chosen channel slot to everyone.

## Nodes tab (Phase 39A)

Every node TicketsCAD has heard, with long name + short name + hex ID + hardware model + role + last position + last signal stats + last-heard time. Bridge restart pushes the radio's full nodedb up in one go, so newly-connected bridges populate this list immediately.

## Map tab (Phase 39E)

Live Leaflet map of every node with a known position. Color-coded by protocol (blue = Meshtastic, cyan = MeshCore). Auto-fits to the bounding box of all visible nodes. Hover/click a marker for long_name, hex ID, which bridge heard it, signal, and last-seen.

## MeshCore support (Phase 39G)

MeshCore reference firmware exposes three roles:

| Mode | Use |
|---|---|
| Companion | Pairs with a phone app over USB/BLE. The bridge talks to this mode via the python-meshcore library, which gives us the equivalent of a "KISS-style" packet pipe over USB serial. **This is the mode the bridge uses today.** |
| Repeater | Relay-only — no phone pairing. Useful as a fixed RF amplifier in a tower; the bridge can monitor by sniffing nearby Companion mode radios. |
| Room Server | Hosts persistent multi-user "rooms". Bridge support is read-only today (we ingest messages but don't run a room). |

If you're flashing a new Heltec for the bridge, leave it in **Companion** mode unless you specifically need Repeater. The bridge handles both protocols (Meshtastic on one USB port, MeshCore on another) — see Phase 34F findings for our cluster's wiring.

## Operations

- **Mint a new bridge token** — Overview tab → "Mint Bridge Token". Tokens are shown ONCE; copy immediately into `/etc/ticketscad/meshbridge.env` on the bridge host as `CAD_TOKEN=…`.
- **Revoke a token** — coming next (revoke endpoint exists, UI button to add).
- **Restart a bridge** — `sudo systemctl restart meshbridge` on the bridge host. Within seconds the radio's full nodedb gets re-pushed to TicketsCAD.

## Glossary

- **NodeInfo** — Meshtastic broadcast packet carrying long_name + short_name + hw_model + role. Heard automatically every few minutes; also queryable from the nodedb.
- **NodeDB** — the radio firmware's persistent table of every peer it has heard, with last-known identity + position. The bridge snapshots this on connect.
- **Channel slot** — index 0..7 in the Meshtastic firmware; each slot has its own PSK + name + uplink/downlink toggles.
- **PSK** — pre-shared key for AES on a channel. Stored base64 in `mesh_channels.psk_b64`.
