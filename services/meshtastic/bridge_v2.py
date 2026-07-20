#!/usr/bin/env python3
"""
TicketsCAD dual-protocol mesh bridge — v2 (Phase 26.1 + Phase 34 follow-up).

Auto-detects whether each attached radio is running Meshtastic or
MeshCore firmware, then runs the appropriate protocol adapter in a
single process. Each adapter normalizes received traffic to a
common `LocationReport` / `TextEvent` shape so downstream HTTP
forwarding doesn't care which protocol the radio speaks.

This is the successor to the original `bridge.py` (Meshtastic-only).
The dual-protocol design was validated on 2026-06-12 against the
cluster's 3 Heltec V3 nodes (2 Meshtastic + 1 MeshCore freshly
flashed) — see bench/CLUSTER-TEST-2026-06-12.md.

Status: bench-quality. POST-to-CAD is in dry-run mode by default
(prints what it would send). Production auth flow lands in a
follow-up phase.

Usage:
    python bridge_v2.py --port /dev/ttyUSB0 --port /dev/ttyUSB1
    python bridge_v2.py --port /dev/ttyUSB0 --post-url http://cad/api/location.php --post-token ABC
    python bridge_v2.py --port /dev/ttyUSB1 --protocol meshcore   # skip auto-detect
"""
import base64
import argparse
import asyncio
import json
import logging
import signal
import sys
import time
from dataclasses import dataclass, field, asdict
from typing import Optional

logger = logging.getLogger("bridge_v2")

# ─────────────────────────────────────────────────────────────
#  Normalized event shapes (protocol-agnostic)
# ─────────────────────────────────────────────────────────────

@dataclass
class LocationReport:
    """Position fix from a unit. Maps to /api/location.php?action=report."""
    protocol:        str           # 'meshtastic' or 'meshcore'
    port:            str           # /dev/ttyUSB0
    unit_identifier: str           # node id e.g. !849ad914 or pubkey-prefix
    lat:             float
    lng:             float
    altitude:        Optional[float] = None
    speed:           Optional[float] = None
    heading:         Optional[float] = None
    received_at:     float         = field(default_factory=time.time)
    snr:             Optional[float] = None
    rssi:            Optional[int]   = None
    hops_away:       Optional[int]   = None
    raw:             dict           = field(default_factory=dict)


@dataclass
class TextEvent:
    """Chat message from the mesh.

    Phase C (messaging-send-gaps-2026-06): channel_idx records the channel
    slot a CHANNEL message arrived on, so the CAD can thread a CHANNEL reply
    back to the same slot. None = direct message (no channel context)."""
    protocol:        str
    port:            str
    unit_identifier: str
    text:            str
    channel_idx:     Optional[int] = None
    received_at:     float = field(default_factory=time.time)


@dataclass
class ATAKEvent:
    """CoT marker / PLI from an ATAK client routed via Meshtastic TAK plugin
    or Meshtastic-Android local TAK Server. Phase 91 — bridge captures the
    raw TAKPacket protobuf so api/mesh.php's atak_route_inbound() can decode
    it and route into entities."""
    protocol:        str
    port:            str
    unit_identifier: str           # source node id (the meshtastic radio)
    payload_b64:     str           # raw TAKPacket protobuf, base64-encoded
    atak_uid:        Optional[str] = None  # extracted if decoded already
    callsign:        Optional[str] = None
    lat:             Optional[float] = None
    lng:             Optional[float] = None
    cot_type:        Optional[str] = None
    snr:             Optional[float] = None
    rssi:            Optional[int]   = None
    hops_away:       Optional[int]   = None
    received_at:     float = field(default_factory=time.time)


@dataclass
class NodeInfoEvent:
    """Identity broadcast — long/short name + hw model + role.
    Phase 39A: pushed to /api/mesh.php?action=node_info on its own (not the
    packets queue) so the receiving side can populate mesh_nodes.
    Phase 42b: extended with MeshCore-specific fields (public_key + radio
    params + tx_power + advertised position). All optional + back-compat."""
    protocol:        str
    port:            str
    unit_identifier: str
    short_name:      Optional[str] = None
    long_name:       Optional[str] = None
    hw_model:        Optional[str] = None
    role:            Optional[str] = None
    # Phase 42b enrichment — populated by MeshCoreAdapter from self_info / adverts.
    public_key:      Optional[str]   = None
    firmware_ver:    Optional[str]   = None
    manuf_name:      Optional[str]   = None
    adv_type:        Optional[int]   = None
    radio_freq:      Optional[float] = None
    radio_bw:        Optional[float] = None
    radio_sf:        Optional[int]   = None
    radio_cr:        Optional[int]   = None
    tx_power:        Optional[int]   = None
    max_tx_power:    Optional[int]   = None
    adv_lat:         Optional[float] = None
    adv_lon:         Optional[float] = None
    is_self:         bool            = False
    from_self_info:  bool            = False
    # Phase 39A signal-quality enrichment: last-heard SNR/RSSI/hop-count for the
    # node, so the Mesh Console nodes tab can show signal quality / nearest nodes
    # even for nodes only seen via the node-DB snapshot or NODEINFO_APP broadcast
    # (the packet-ingest path already carried these for position/text traffic).
    snr:             Optional[float] = None
    rssi:            Optional[int]   = None
    hops:            Optional[int]   = None
    received_at:     float = field(default_factory=time.time)
    raw:             dict = field(default_factory=dict)


# ─────────────────────────────────────────────────────────────
#  HTTP sink — dry-run by default
# ─────────────────────────────────────────────────────────────

class CadSink:
    """Posts normalized events to TicketsCAD /api/mesh.php?action=ingest
    via bearer-token auth. Batches multiple events per POST.

    Phase 35A wiring (2026-06-12) — replaces the original dry-run /
    location.php path. The mesh endpoint understands packets natively
    and stores them in mesh_packet_log for the admin UI to render.
    """

    def __init__(self, cad_url: Optional[str], bearer_token: Optional[str], dry_run: bool):
        self.cad_url       = cad_url.rstrip('/') if cad_url else None
        self.bearer_token  = bearer_token
        self.dry_run       = dry_run or not cad_url or not bearer_token
        self.session       = None
        self.buffer        = []
        self.last_flush    = time.time()
        if not self.dry_run:
            import requests
            self.session = requests.Session()
            self.session.headers.update({
                'Authorization': f'Bearer {bearer_token}',
                'Content-Type':  'application/json',
            })

    def submit(self, event):
        # Phase 39A: NodeInfoEvent gets its own endpoint, not the packet log.
        if isinstance(event, NodeInfoEvent):
            return self._submit_node_info(event)
        if self.dry_run:
            payload = self._event_to_packet(event)
            logger.info("DRY-RUN ingest packet: %s",
                        json.dumps(payload, default=str)[:300])
            return True
        self.buffer.append(self._event_to_packet(event))
        # Flush when 10 packets buffered OR 3 seconds elapsed since last
        if len(self.buffer) >= 10 or (time.time() - self.last_flush) > 3:
            return self.flush()
        return True

    def _submit_node_info(self, event):
        body = {
            'node_id':    event.unit_identifier,
            'protocol':   event.protocol,
            'short_name': event.short_name,
            'long_name':  event.long_name,
            'hw_model':   event.hw_model,
            'role':       event.role,
        }
        # Phase 42b: only include MeshCore-specific keys when they are present,
        # so a legacy ingest endpoint that doesn't know about them isn't
        # confused by null-pollution in the JSON. The API endpoint also
        # tolerates the keys not being present (COALESCE preserves existing).
        for k in ('public_key', 'firmware_ver', 'manuf_name', 'adv_type',
                  'radio_freq', 'radio_bw', 'radio_sf', 'radio_cr',
                  'tx_power', 'max_tx_power', 'adv_lat', 'adv_lon',
                  'snr', 'rssi', 'hops'):
            v = getattr(event, k, None)
            if v is not None:
                body[k] = v
        if getattr(event, 'is_self', False):
            body['is_self'] = True
        if getattr(event, 'from_self_info', False):
            body['from_self_info'] = True
        if self.dry_run:
            logger.info("DRY-RUN node_info: %s", json.dumps(body, default=str))
            return True
        try:
            r = self.session.post(
                f'{self.cad_url}/api/mesh.php?action=node_info',
                json=body, timeout=10)
            return r.status_code == 200
        except Exception as e:
            logger.debug("node_info POST failed: %s", e)
            return False

    def _event_to_packet(self, event) -> dict:
        """Map a LocationReport/TextEvent into the mesh.php ingest shape."""
        if isinstance(event, LocationReport):
            return {
                'protocol':     event.protocol,
                'src_node':     event.unit_identifier,
                'port_kind':    'POSITION',
                'snr':          event.snr,
                'rssi':         event.rssi,
                'hops':         event.hops_away,
                'lat':          event.lat,
                'lng':          event.lng,
                'payload_json': event.raw,
                'received_at':  int(event.received_at),
            }
        elif isinstance(event, TextEvent):
            pkt = {
                'protocol':     event.protocol,
                'src_node':     event.unit_identifier,
                'port_kind':    'TEXT',
                'payload_text': event.text,
                'received_at':  int(event.received_at),
            }
            # Phase C: carry the originating channel slot so the CAD can
            # thread a channel reply. Omitted for direct messages.
            if event.channel_idx is not None:
                pkt['channel_idx'] = int(event.channel_idx)
            return pkt
        elif isinstance(event, ATAKEvent):
            return {
                'protocol':     event.protocol,
                'src_node':     event.unit_identifier,
                'port_kind':    'ATAK_PLUGIN',
                'snr':          event.snr,
                'rssi':         event.rssi,
                'hops':         event.hops_away,
                'lat':          event.lat,
                'lng':          event.lng,
                'payload_text': event.callsign,
                'payload_json': {
                    'payload_b64': event.payload_b64,
                    'atak_uid':    event.atak_uid,
                    'callsign':    event.callsign,
                    'cot_type':    event.cot_type,
                },
                'received_at':  int(event.received_at),
            }
        return {}

    def flush(self) -> bool:
        if not self.buffer:
            return True
        if self.dry_run:
            self.buffer.clear()
            return True
        body = {'packets': self.buffer}
        try:
            r = self.session.post(
                f'{self.cad_url}/api/mesh.php?action=ingest',
                json=body, timeout=15)
            if r.status_code == 200:
                self.buffer.clear()
                self.last_flush = time.time()
                return True
            logger.warning("ingest POST %d: %s", r.status_code, r.text[:200])
        except Exception as e:
            logger.error("ingest POST failed: %s", e)
        return False

    def poll_outbox(self) -> Optional[dict]:
        """Returns one queued work item or None."""
        if self.dry_run: return None
        try:
            r = self.session.get(
                f'{self.cad_url}/api/mesh.php?action=poll_outbox',
                timeout=10)
            if r.status_code == 200:
                d = r.json()
                return d.get('work')
        except Exception as e:
            logger.debug("outbox poll error: %s", e)
        return None

    def ack_outbox(self, oid: int, ok: bool, result=None, error=None):
        if self.dry_run: return
        try:
            self.session.post(
                f'{self.cad_url}/api/mesh.php?action=ack_outbox',
                json={'id': oid, 'ok': ok,
                      'result': result, 'error': error},
                timeout=10)
        except Exception:
            pass


# ─────────────────────────────────────────────────────────────
#  Protocol adapters
# ─────────────────────────────────────────────────────────────

class MeshtasticAdapter:
    """Reads Meshtastic packets via the official `meshtastic` library,
    normalizes to LocationReport / TextEvent, hands to the sink."""

    PROTOCOL = 'meshtastic'

    def __init__(self, port: str, sink: CadSink):
        self.port = port
        self.sink = sink
        self.iface = None
        self.stats = {'positions': 0, 'texts': 0, 'errors': 0}

    def connect(self):
        # Phase 39D: accept transport prefix in self.port:
        #   "/dev/ttyUSB0"     → SerialInterface (default)
        #   "tcp:host[:port]"  → TCPInterface (port defaults to 4403)
        #   "ble:NAME"         → BLEInterface (paired Heltec/T-Beam)
        from pubsub import pub
        if self.port.startswith('tcp:'):
            from meshtastic.tcp_interface import TCPInterface
            spec = self.port[4:]
            host, _, p = spec.partition(':')
            port = int(p) if p else 4403
            self.iface = TCPInterface(hostname=host, portNumber=port)
            logger.info("[meshtastic %s] TCP connect to %s:%d", self.port, host, port)
        elif self.port.startswith('ble:'):
            from meshtastic.ble_interface import BLEInterface
            name = self.port[4:]
            self.iface = BLEInterface(address=name)
            logger.info("[meshtastic %s] BLE connect to %s", self.port, name)
        else:
            import meshtastic.serial_interface
            self.iface = meshtastic.serial_interface.SerialInterface(devPath=self.port)
        pub.subscribe(self._on_receive, 'meshtastic.receive')
        info = self.iface.getMyNodeInfo() or {}
        user = info.get('user') or {}
        long_name = user.get('longName', '?')
        logger.info("[meshtastic %s] connected to %s", self.port, long_name)
        # Phase 39A: push the attached node's identity AND every node in the
        # nodedb the radio already knows about, so the admin console sees
        # friendly names immediately (not just !hex IDs).
        try:
            own_id = info.get('user', {}).get('id') or f"!{info.get('num', 0):08x}"
            self.sink.submit(NodeInfoEvent(
                protocol=self.PROTOCOL, port=self.port, unit_identifier=own_id,
                short_name=user.get('shortName'),
                long_name=user.get('longName'),
                hw_model=str(user.get('hwModel') or ''),
                role=str(user.get('role') or ''),
            ))
            for n in (self.iface.nodes or {}).values():
                u = n.get('user') or {}
                nid = u.get('id') or f"!{n.get('num', 0):08x}"
                if nid == own_id:
                    continue
                pos = n.get('position') or {}
                # The Meshtastic node DB carries last-heard signal quality per
                # node: `snr` (float dB) and `hopsAway` (int). RSSI is only on
                # individual rx packets, not the node snapshot, so leave it null
                # here — the packet-ingest path fills it when traffic flows.
                node_snr  = n.get('snr')
                node_hops = n.get('hopsAway')
                ev = NodeInfoEvent(
                    protocol=self.PROTOCOL, port=self.port, unit_identifier=nid,
                    short_name=u.get('shortName'),
                    long_name=u.get('longName'),
                    hw_model=str(u.get('hwModel') or '') or None,
                    role=str(u.get('role') or '') or None,
                    snr=float(node_snr) if node_snr is not None else None,
                    hops=int(node_hops) if node_hops is not None else None,
                )
                self.sink.submit(ev)
                if pos.get('latitudeI') and pos.get('longitudeI'):
                    self.sink.submit(LocationReport(
                        protocol=self.PROTOCOL, port=self.port, unit_identifier=nid,
                        lat=round(pos.get('latitudeI') / 1e7, 6),
                        lng=round(pos.get('longitudeI') / 1e7, 6),
                        altitude=pos.get('altitude'),
                        raw={'source': 'nodedb_snapshot'},
                    ))
        except Exception as e:
            logger.debug("nodedb seed failed: %s", e)

    def _on_receive(self, packet, interface):
        decoded = packet.get('decoded') or {}
        portnum = decoded.get('portnum') or ''
        src     = packet.get('fromId') or f"!{packet.get('from', 0):08x}"
        own_num = (self.iface.getMyNodeInfo() or {}).get('num', 0)
        if packet.get('from') == own_num:
            return   # ignore own
        snr  = packet.get('rxSnr')
        rssi = packet.get('rxRssi')
        hops = packet.get('hopStart', 0) - packet.get('hopLimit', 0)
        # Phase 39A: capture NodeInfo (long/short name + hw + role) when broadcast.
        if portnum == 'NODEINFO_APP':
            u = decoded.get('user') or {}
            ni = NodeInfoEvent(
                protocol=self.PROTOCOL, port=self.port, unit_identifier=src,
                short_name=u.get('shortName'),
                long_name=u.get('longName'),
                hw_model=u.get('hwModel'),
                role=u.get('role'),
                # The NODEINFO_APP broadcast arrived as an rx packet, so we have
                # live signal quality for the source node — carry it through so
                # the nodes tab shows SNR/RSSI even for identity-only beacons.
                snr=snr, rssi=rssi, hops=hops,
                raw={'macaddr': u.get('macaddr')},
            )
            self.sink.submit(ni)
            self.stats.setdefault('nodeinfo', 0)
            self.stats['nodeinfo'] += 1
            return
        if portnum == 'POSITION_APP':
            pos = decoded.get('position') or {}
            lat = pos.get('latitude') or (pos.get('latitudeI', 0) / 1e7 if pos.get('latitudeI') else None)
            lng = pos.get('longitude') or (pos.get('longitudeI', 0) / 1e7 if pos.get('longitudeI') else None)
            if lat is None or lng is None:
                return
            ev = LocationReport(
                protocol=self.PROTOCOL, port=self.port, unit_identifier=src,
                lat=round(lat, 6), lng=round(lng, 6),
                altitude=pos.get('altitude'),
                speed=pos.get('groundSpeed') or pos.get('speed'),
                heading=pos.get('groundTrack') or pos.get('heading'),
                snr=snr, rssi=rssi, hops_away=hops,
                raw={'rxTime': packet.get('rxTime')},
            )
            self.sink.submit(ev)
            self.stats['positions'] += 1
        elif portnum == 'TEXT_MESSAGE_APP':
            text = (decoded.get('text')
                    or decoded.get('payload', b'').decode('utf-8', errors='replace'))
            # Phase C: a Meshtastic text addressed to a channel carries a
            # `channel` index on the packet; a direct message (to our node)
            # has `to`/`toId` set to us and no meaningful channel. Treat a
            # packet whose dest is the broadcast address as a channel message
            # (record the slot); otherwise it's a DM (channel_idx=None).
            chan_idx = None
            to_id = packet.get('to')
            BROADCAST = 0xffffffff
            if to_id is None or to_id == BROADCAST:
                chan_idx = int(packet.get('channel', 0) or 0)
            self.sink.submit(TextEvent(
                protocol=self.PROTOCOL, port=self.port,
                unit_identifier=src, text=text, channel_idx=chan_idx))
            self.stats['texts'] += 1

        # Phase 91: capture ATAK_PLUGIN port 72 (Meshtastic TAK packets sent
        # from ATAK via the Meshtastic-Android local TAK Server OR the legacy
        # plugin). Pass raw TAKPacket protobuf bytes through; CAD-side decoder
        # extracts lat/lng/uid/callsign and routes into Phase 91 entities.
        elif portnum in ('ATAK_PLUGIN', 'ATAK_FORWARDER', 'DETECTION_APP', 'ATAK_PLUGIN_V2'):
            raw = decoded.get('payload', b'') or b''
            # Some lib versions auto-decode into decoded['atak']; opportunistic
            atak_obj = decoded.get('atak') or {}
            pli = atak_obj.get('pli') if isinstance(atak_obj, dict) else None
            contact = atak_obj.get('contact') if isinstance(atak_obj, dict) else None
            lat = lng = None
            if isinstance(pli, dict):
                if pli.get('latitudeI') is not None:
                    lat = round(pli['latitudeI'] / 1e7, 6)
                if pli.get('longitudeI') is not None:
                    lng = round(pli['longitudeI'] / 1e7, 6)
            ev = ATAKEvent(
                protocol=self.PROTOCOL, port=self.port, unit_identifier=src,
                payload_b64=base64.b64encode(bytes(raw)).decode('ascii'),
                atak_uid=(contact or {}).get('deviceCallsign') if isinstance(contact, dict) else None,
                callsign=(contact or {}).get('callsign') if isinstance(contact, dict) else None,
                lat=lat, lng=lng,
                snr=snr, rssi=rssi, hops_away=hops,
            )
            self.sink.submit(ev)
            self.stats.setdefault('atak', 0)
            self.stats['atak'] += 1
            return

    def close(self):
        try:
            if self.iface:
                self.iface.close()
        except Exception:
            pass


class MeshCoreAdapter:
    """Reads MeshCore events via the `meshcore` Python lib."""

    PROTOCOL = 'meshcore'

    def __init__(self, port: str, sink: CadSink):
        self.port = port
        self.sink = sink
        self.mc = None
        self.stats = {'positions': 0, 'texts': 0, 'errors': 0}
        self._stop = False
        # Phase 44 (Sonar python:S7502): hold a strong reference to the
        # self_info refresh task. asyncio.create_task() returns a Task that
        # the event loop only weakly references — if we don't keep our own,
        # the task can be garbage-collected mid-run with no warning.
        self._refresh_task = None

    def _hard_reset(self) -> None:
        """Run esptool's --after hard-reset to recover a radio stuck in
        DOWNLOAD-mode boot strap. Best-effort; if esptool isn't installed
        we just let the connect retry happen on its own."""
        import subprocess, sys
        subprocess.run(
            [sys.executable, "-m", "esptool",
             "--port", self.port, "--chip", "esp32s3",
             "--after", "hard-reset", "run"],
            check=True, capture_output=True, timeout=30,
        )

    async def run(self):
        from meshcore import MeshCore, EventType
        # Phase 42b: the MeshCore radio sometimes lands in DOWNLOAD-mode boot
        # strap after a reboot — pyserial's port-open DTR/RTS toggle can
        # re-trigger the strap. Try the connection once; if it fails, do a
        # hard-reset via esptool and retry once before giving up. Avoids
        # crashing the bridge on a recoverable transient.
        self.mc = await MeshCore.create_serial(self.port)
        if self.mc is None:
            logger.warning("[meshcore %s] initial connect failed; trying hard-reset", self.port)
            try:
                self._hard_reset()
                await asyncio.sleep(3)
                self.mc = await MeshCore.create_serial(self.port)
            except Exception as e:
                logger.error("[meshcore %s] hard-reset attempt failed: %s", self.port, e)
        if self.mc is None:
            logger.error("[meshcore %s] giving up after retry; bridge will idle on this port", self.port)
            while not self._stop:
                await asyncio.sleep(30)
            return
        await asyncio.sleep(2)
        await self.mc.commands.send_appstart()
        dq = await self.mc.commands.send_device_query()
        model = (dq.payload if dq else {}).get('model', '?')
        ver   = (dq.payload if dq else {}).get('ver', '?')
        logger.info("[meshcore %s] connected: %s firmware %s", self.port, model, ver)
        self._model = model
        self._fw_ver = ver

        # Phase 42b — publish the locally-attached radio's full self_info on
        # startup. The CAD side stores this against is_self=1 so the Mesh
        # Console can render the bridge's own radio's config (frequency, SF,
        # TX power, public key) alongside what it can see in the mesh.
        try:
            si = self.mc.self_info or {}
            if si:
                self._publish_node_info_from_self_info(si)
                # Refresh every 15 minutes so a re-config without a bridge
                # restart still gets reflected in the dashboard.
                # Phase 44 — hold a strong ref so the task isn't gc'd before
                # the loop body runs.
                self._refresh_task = asyncio.create_task(self._self_info_refresh_loop())
        except Exception as e:
            logger.warning("meshcore self_info publish error: %s", e)

        # Subscribe to received events. MeshCore's event API differs
        # from Meshtastic — events fire via dispatcher callbacks.
        self.mc.dispatcher.subscribe(EventType.CONTACT_MSG_RECV, self._on_contact_msg)
        self.mc.dispatcher.subscribe(EventType.CHANNEL_MSG_RECV, self._on_channel_msg)
        try:
            self.mc.dispatcher.subscribe(EventType.ADVERTISEMENT, self._on_advert)
        except Exception:
            pass

        # Loop until cancelled
        while not self._stop:
            await asyncio.sleep(0.5)

    async def _self_info_refresh_loop(self):
        """Re-fetch self_info every 15 minutes so dashboard reflects current
        radio config even when an admin reconfigured it via the companion
        app without bouncing the bridge."""
        while not self._stop:
            await asyncio.sleep(900)
            try:
                si = self.mc.self_info if self.mc else None
                if si:
                    self._publish_node_info_from_self_info(si)
            except Exception:
                pass

    def _publish_node_info_from_self_info(self, si: dict):
        """Compose a NodeInfoEvent for the bridge-attached MeshCore radio."""
        pubkey = str(si.get('public_key') or '')
        if not pubkey:
            return
        # MeshCore node_id is the public key (or its short hex prefix). Stick
        # to the short prefix as the canonical id so mesh_packet_log entries
        # collated by src_node line up with the mesh_nodes row.
        node_id = pubkey[:16] if len(pubkey) >= 16 else pubkey
        name = si.get('name') or ''
        # MeshCore companion role is the only one we run; lift the integer
        # adv_type into a readable role label too.
        adv_type = si.get('adv_type')
        role_text = {1: 'companion', 2: 'repeater', 3: 'room_server'}.get(int(adv_type or 0))
        self.sink.submit(NodeInfoEvent(
            protocol=self.PROTOCOL, port=self.port,
            unit_identifier=node_id,
            short_name=name[:8] if name else None,
            long_name=name or None,
            hw_model=getattr(self, '_model', None),
            role=role_text,
            public_key=pubkey,
            firmware_ver=getattr(self, '_fw_ver', None),
            manuf_name=si.get('manuf_name'),
            adv_type=int(adv_type) if adv_type is not None else None,
            radio_freq=float(si['radio_freq']) if si.get('radio_freq') else None,
            radio_bw=float(si['radio_bw'])     if si.get('radio_bw')   else None,
            radio_sf=int(si['radio_sf'])       if si.get('radio_sf')   else None,
            radio_cr=int(si['radio_cr'])       if si.get('radio_cr')   else None,
            tx_power=int(si['tx_power'])           if si.get('tx_power') is not None else None,
            max_tx_power=int(si['max_tx_power'])   if si.get('max_tx_power') is not None else None,
            adv_lat=float(si['adv_lat']) if si.get('adv_lat') else None,
            adv_lon=float(si['adv_lon']) if si.get('adv_lon') else None,
            is_self=True,
            from_self_info=True,
            raw={k: v for k, v in si.items() if isinstance(v, (str, int, float, bool))},
        ))

    def _on_contact_msg(self, event):
        # Direct (contact) message — no channel context. channel_idx stays
        # None so the CAD addresses a reply as a DM (to_node = pubkey_prefix).
        try:
            p = event.payload or {}
            self.sink.submit(TextEvent(
                protocol=self.PROTOCOL, port=self.port,
                unit_identifier=str(p.get('pubkey_prefix', '?'))[:16],
                text=str(p.get('text', '')),
                channel_idx=None,
            ))
            self.stats['texts'] += 1
        except Exception as e:
            logger.warning("meshcore msg parse error: %s", e)
            self.stats['errors'] += 1

    def _on_channel_msg(self, event):
        # Phase C: channel message — capture the originating channel slot so
        # the CAD can thread a CHANNEL reply back to the same slot. Previously
        # this delegated to _on_contact_msg and dropped the slot, so a channel
        # reply couldn't recover which slot to answer on.
        try:
            p = event.payload or {}
            # MeshCore payloads name the slot a few ways depending on lib
            # version — accept the common keys.
            slot = (p.get('channel_idx')
                    if p.get('channel_idx') is not None
                    else p.get('channel'))
            if slot is None:
                slot = p.get('channel_no')
            self.sink.submit(TextEvent(
                protocol=self.PROTOCOL, port=self.port,
                unit_identifier=str(p.get('pubkey_prefix', '?'))[:16],
                text=str(p.get('text', '')),
                channel_idx=int(slot) if slot is not None else 0,
            ))
            self.stats['texts'] += 1
        except Exception as e:
            logger.warning("meshcore channel msg parse error: %s", e)
            self.stats['errors'] += 1

    def _on_advert(self, event):
        # Phase 42b: adverts carry identity + (optionally) an advertised
        # position. Always emit a NodeInfoEvent so the mesh_nodes table
        # gets the public key / name / first-seen / role for every node
        # the bridge hears, even if the node never sends a text or
        # position packet.
        try:
            p = event.payload or {}
            pubkey = str(p.get('public_key') or '')
            if not pubkey:
                return
            node_id  = pubkey[:16]
            name     = p.get('name') or p.get('adv_name') or ''
            adv_type = p.get('adv_type')
            role_text = {1: 'companion', 2: 'repeater', 3: 'room_server'}.get(
                int(adv_type or 0))
            lat = p.get('adv_lat')
            lng = p.get('adv_lon')
            # Some MeshCore lib versions expose the receive SNR/RSSI on the
            # advert payload. Opportunistic + null-safe — absent on older libs.
            adv_snr  = p.get('snr')  if p.get('snr')  is not None else p.get('rx_snr')
            adv_rssi = p.get('rssi') if p.get('rssi') is not None else p.get('rx_rssi')

            self.sink.submit(NodeInfoEvent(
                protocol=self.PROTOCOL, port=self.port,
                unit_identifier=node_id,
                short_name=name[:8] if name else None,
                long_name=name or None,
                role=role_text,
                public_key=pubkey,
                adv_type=int(adv_type) if adv_type is not None else None,
                adv_lat=float(lat) if lat else None,
                adv_lon=float(lng) if lng else None,
                snr=float(adv_snr) if adv_snr is not None else None,
                rssi=int(adv_rssi) if adv_rssi is not None else None,
                is_self=False,
                from_self_info=False,
                raw={k: v for k, v in p.items()
                     if isinstance(v, (str, int, float, bool))},
            ))

            # If the advert carried a real position (not the 0,0 unset
            # sentinel), also emit a LocationReport so the live map and
            # mesh_packet_log capture the movement.
            if lat and lng and (abs(lat) > 0.0001 or abs(lng) > 0.0001):
                self.sink.submit(LocationReport(
                    protocol=self.PROTOCOL, port=self.port,
                    unit_identifier=node_id,
                    lat=lat, lng=lng,
                ))
                self.stats['positions'] += 1
        except Exception as e:
            logger.debug("meshcore advert parse error: %s", e)

    async def close(self):
        self._stop = True
        try:
            if self.mc:
                await self.mc.disconnect()
        except Exception:
            pass


# ─────────────────────────────────────────────────────────────
#  Protocol auto-detect
# ─────────────────────────────────────────────────────────────

def detect_protocol(port: str, timeout: float = 12.0) -> Optional[str]:
    """Best-effort: try Meshtastic first, then MeshCore."""
    try:
        import meshtastic.serial_interface
        iface = meshtastic.serial_interface.SerialInterface(devPath=port)
        deadline = time.time() + timeout
        info = None
        while time.time() < deadline:
            info = iface.getMyNodeInfo()
            if info and info.get('user', {}).get('longName'):
                break
            time.sleep(0.5)
        try: iface.close()
        except Exception: pass
        if info and info.get('user', {}).get('longName'):
            return 'meshtastic'
    except Exception:
        pass
    try:
        async def _try():
            from meshcore import MeshCore
            mc = await MeshCore.create_serial(port)
            await asyncio.sleep(2)
            r = await asyncio.wait_for(mc.commands.send_appstart(), timeout=5)
            try: await mc.disconnect()
            except Exception: pass
            return r is not None
        if asyncio.run(asyncio.wait_for(_try(), timeout=timeout)):
            return 'meshcore'
    except Exception:
        pass
    return None


# ─────────────────────────────────────────────────────────────
#  Main loop
# ─────────────────────────────────────────────────────────────

async def outbox_loop(sink: CadSink, adapters: list, poll_interval: float = 5.0):
    """Polls CAD for outbound work and dispatches via the right adapter."""
    while True:
        try:
            work = sink.poll_outbox()
            if work:
                oid    = work.get('id')
                kind   = work.get('kind')
                target = work.get('target_protocol', 'any')
                payload = work.get('payload') or {}
                logger.info("[outbox %s] kind=%s target=%s payload=%s",
                            oid, kind, target, json.dumps(payload)[:200])
                ok, result, err = dispatch_outbox(adapters, kind, target, payload)
                sink.ack_outbox(oid, ok, result=result, error=err)
        except Exception as e:
            logger.error("outbox loop error: %s", e)
        # Also flush any buffered packets
        try: sink.flush()
        except Exception: pass
        await asyncio.sleep(poll_interval)


def _meshcore_extract_ack_ms(send_res) -> Optional[int]:
    """Best-effort: pull an end-to-end ACK round-trip (ms) out of whatever
    the meshcore lib's send_msg returned. Lib versions vary — the result may
    be an Event, a dict, or an object with attributes. We look for common
    round-trip keys and never raise (returns None when no ACK is present).

    Phase C: this only succeeds when the lib blocks for and returns the ACK
    on the send result. The event-driven ACK path is the documented bridge
    follow-up (see the call site)."""
    if send_res is None:
        return None
    try:
        payload = None
        # Event-like: .payload dict
        if hasattr(send_res, 'payload') and isinstance(getattr(send_res, 'payload'), dict):
            payload = send_res.payload
        elif isinstance(send_res, dict):
            payload = send_res
        if not isinstance(payload, dict):
            return None
        for key in ('ack_ms', 'round_trip_ms', 'rtt_ms', 'roundtrip_ms'):
            if payload.get(key) is not None:
                return max(0, int(payload[key]))
        # Some libs report round-trip in seconds.
        for key in ('round_trip', 'rtt', 'roundtrip'):
            if payload.get(key) is not None:
                return max(0, int(float(payload[key]) * 1000))
    except Exception:
        return None
    return None


def dispatch_outbox(adapters: list, kind: str, target: str, payload: dict):
    """Find an adapter that matches and execute the work item."""
    # Pick the first adapter that matches the target protocol; "any" picks the first.
    candidates = [a for a in adapters
                  if target == 'any' or a.PROTOCOL == target]
    if not candidates:
        return False, None, f"no adapter for protocol {target}"
    ad = candidates[0]
    if kind == 'send_text':
        text = (payload or {}).get('text', '')
        # Phase 39C: optional direct-message and channel slot.
        to_node = (payload or {}).get('to_node') or ''
        slot    = int((payload or {}).get('channel_slot', 0) or 0)
        try:
            if ad.PROTOCOL == 'meshtastic':
                kwargs = {'channelIndex': slot}
                if to_node:
                    # Meshtastic accepts either node-num int or "!hex" string.
                    kwargs['destinationId'] = to_node
                    kwargs['wantAck'] = True
                ad.iface.sendText(text, **kwargs)
                return True, {'sent_via': ad.port, 'dm': bool(to_node), 'slot': slot}, None
            elif ad.PROTOCOL == 'meshcore':
                # MeshCore: DM if to_node is a destination pubkey (hex), otherwise
                # broadcast on the channel slot.
                #
                # Verified against the INSTALLED lib on meshbridge-02
                # (meshcore 2.3.7, github.com/fdlamotte/meshcore_py) by
                # importing meshcore.commands.CommandHandler and inspecting
                # signatures (2026-06-25). The real methods are:
                #   commands.send_chan_msg(chan: int, msg: str, timestamp=None)
                #   commands.send_msg(dst, msg: str, timestamp=None, attempt=0)
                # There is NO `send_dm` in this lib — the prior `send_dm` call
                # raised AttributeError, so every MeshCore DM failed.
                #
                # `dst` (DestinationType = Union[bytes, str, dict]) accepts a
                # hex pubkey string; the lib's _validate_destination() does
                # bytes.fromhex(dst)[:6], so `to_node` may be a full 64-char
                # pubkey or any prefix >= 12 hex chars (6 bytes). No prior
                # "ensure contact" step is required — send_msg validates the
                # hex directly without a contact lookup.
                if to_node:
                    coro = ad.mc.commands.send_msg(to_node, text)
                else:
                    coro = ad.mc.commands.send_chan_msg(slot, text)
                fut = asyncio.run_coroutine_threadsafe(coro, asyncio.get_event_loop())
                send_res = fut.result(timeout=5)
                result = {'sent_via': ad.port, 'dm': bool(to_node), 'slot': slot}

                # ── Phase C: MeshCore end-to-end ACK round-trip ──
                #
                # MeshCore's differentiator over Meshtastic is a per-message
                # end-to-end ACK: send_msg returns a MSG_SENT result carrying
                # an expected ack_code + a suggested timeout; the radio later
                # fires an ACK / PUSH_CODE_SEND_CONFIRMED event with the
                # round-trip ms when the distant node confirms delivery.
                #
                # We populate result['ack_ms'] when the lib surfaces a
                # round-trip directly on the send result (some versions block
                # for the ACK and return it). The CAD's ack_outbox stores it
                # on mesh_outbox.ack_ms and the operator's reply shows
                # "delivered in N ms".
                #
                # TODO(bridge-followup): for libs where the ACK arrives as a
                # *later* event rather than on the send result, subscribe to
                # EventType.ACK (matchable by the expected ack_code from
                # send_res) and correlate it to this outbox id, then re-ack
                # the CAD with the measured round-trip. That needs the adapter
                # to track in-flight ack_codes → outbox ids and is deferred to
                # a hardware-in-the-loop session on meshbridge-01/02. Until
                # then DM ack_ms is only populated for the blocking-result
                # case; channel sends have no end-to-end ACK by design.
                if to_node:
                    ack_ms = _meshcore_extract_ack_ms(send_res)
                    if ack_ms is not None:
                        result['ack_ms'] = ack_ms
                return True, result, None
        except Exception as e:
            return False, None, str(e)
    if kind in ('set_owner','set_channel','set_region','reboot'):
        if ad.PROTOCOL != 'meshtastic':
            return False, None, f"config {kind} not implemented for {ad.PROTOCOL}"
        try:
            if kind == 'set_owner':
                ad.iface.localNode.setOwner(
                    long_name=payload.get('long_name'),
                    short_name=payload.get('short_name'))
                return True, {'applied': payload}, None
            if kind == 'reboot':
                ad.iface.localNode.reboot()
                return True, {'applied': 'reboot'}, None
            # Phase 39B: set_channel on a slot. Uses meshtastic.localNode
            # channel API. Payload:
            #   slot: 0..7
            #   name, psk_b64 (base64), modem_preset, region
            #   uplink_enabled, downlink_enabled
            if kind == 'set_channel':
                from meshtastic import channel_pb2  # protobufs
                import base64 as _b64
                slot = int(payload.get('slot', 0))
                ch = ad.iface.localNode.getChannelByChannelIndex(slot)
                if ch is None:
                    # Create new
                    from meshtastic import protocols
                    return False, None, f"slot {slot} missing on device"
                ch.settings.name = (payload.get('name') or '')[:11]
                psk_b64 = payload.get('psk_b64') or ''
                if psk_b64:
                    ch.settings.psk = _b64.b64decode(psk_b64)
                ch.settings.uplink_enabled   = bool(payload.get('uplink_enabled', True))
                ch.settings.downlink_enabled = bool(payload.get('downlink_enabled', True))
                # Role: SECONDARY for slots > 0, PRIMARY for slot 0
                ch.role = (channel_pb2.Channel.Role.PRIMARY
                           if slot == 0 else channel_pb2.Channel.Role.SECONDARY)
                ad.iface.localNode.writeChannel(slot)
                # Region applied at the localNode/config level:
                if payload.get('region'):
                    try:
                        from meshtastic import config_pb2
                        cfg = ad.iface.localNode.localConfig
                        cfg.lora.region = getattr(
                            config_pb2.Config.LoRaConfig.RegionCode,
                            payload['region'], 0)
                        ad.iface.localNode.writeConfig('lora')
                    except Exception as e:
                        logger.debug("region apply failed: %s", e)
                return True, {'applied': 'set_channel slot=%d' % slot}, None
            # set_region applied separately if needed (rare path)
            if kind == 'set_region':
                from meshtastic import config_pb2
                cfg = ad.iface.localNode.localConfig
                cfg.lora.region = getattr(
                    config_pb2.Config.LoRaConfig.RegionCode,
                    payload.get('region', 'US'), 0)
                ad.iface.localNode.writeConfig('lora')
                return True, {'applied': payload}, None
            return False, None, f"{kind} not yet implemented"
        except Exception as e:
            return False, None, str(e)
    return False, None, f"unknown kind {kind}"


async def main_async(ports, protocols, sink, duration):
    tasks = []
    adapters = []

    for port, proto in zip(ports, protocols):
        if proto == 'auto':
            logger.info("[%s] auto-detecting protocol", port)
            proto = detect_protocol(port)
            if not proto:
                logger.error("[%s] no protocol responded — skipping", port)
                continue
            logger.info("[%s] detected: %s", port, proto)

        if proto == 'meshtastic':
            try:
                ad = MeshtasticAdapter(port, sink)
                ad.connect()
                adapters.append(ad)
            except Exception as e:
                logger.error("[meshtastic %s] connect failed: %s", port, e)
        elif proto == 'meshcore':
            ad = MeshCoreAdapter(port, sink)
            adapters.append(ad)
            tasks.append(asyncio.create_task(ad.run()))

    if not adapters:
        logger.error("no adapters started")
        return 1

    # Outbox poll task (only when not dry-run, i.e. real CAD configured)
    if not sink.dry_run:
        tasks.append(asyncio.create_task(outbox_loop(sink, adapters)))

    # Run for the specified duration (or forever)
    if duration > 0:
        await asyncio.sleep(duration)
    else:
        await asyncio.Event().wait()

    # Cleanup
    for ad in adapters:
        if isinstance(ad, MeshCoreAdapter):
            await ad.close()
        else:
            ad.close()
    for t in tasks:
        t.cancel()
    # Final flush
    try: sink.flush()
    except Exception: pass

    # Stats
    logger.info("=== bridge stats ===")
    for ad in adapters:
        logger.info("  %s %s: %s", ad.PROTOCOL, ad.port, ad.stats)
    return 0


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--port", action='append', required=True,
                    help="Serial port (repeatable, one per radio)")
    ap.add_argument("--protocol", action='append', default=None,
                    choices=['auto', 'meshtastic', 'meshcore'],
                    help="Protocol per --port. Defaults to 'auto' for each.")
    ap.add_argument("--cad-url", help="TicketsCAD base URL (e.g. https://your-server.example.com). Empty = dry-run.")
    ap.add_argument("--cad-token", help="Bearer token issued from the admin UI.")
    ap.add_argument("--duration", type=int, default=0,
                    help="Seconds to run (0 = forever). Default 0.")
    ap.add_argument("-v", "--verbose", action='store_true')
    args = ap.parse_args()

    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s")

    protocols = args.protocol or ['auto'] * len(args.port)
    if len(protocols) < len(args.port):
        protocols += ['auto'] * (len(args.port) - len(protocols))

    sink = CadSink(args.cad_url, args.cad_token,
                   dry_run=not (args.cad_url and args.cad_token))

    def handle_sig(*_):
        logger.info("signal received, shutting down")
        sys.exit(0)
    signal.signal(signal.SIGINT, handle_sig)
    signal.signal(signal.SIGTERM, handle_sig)

    return asyncio.run(main_async(args.port, protocols, sink, args.duration))


if __name__ == '__main__':
    sys.exit(main())
