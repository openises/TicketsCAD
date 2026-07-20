#!/usr/bin/env python3
"""
TicketsCAD Meshtastic Bridge Service

Bridges Meshtastic mesh radio network with TicketsCAD for:
  - Location tracking (position packets → location API)
  - Messaging (text messages ↔ chat system)
  - Telemetry (battery, environment → unit status)

Supports three connection methods:
  1. MQTT — Subscribe to Meshtastic MQTT broker (recommended for multi-node)
  2. Serial — Direct USB connection to a local radio
  3. TCP — Network connection to a WiFi-enabled node

Configuration is read from TicketsCAD's settings API or a local config file.

Usage:
  python bridge.py                      # Auto-detect config from TicketsCAD API
  python bridge.py --config bridge.ini  # Use local config file
  python bridge.py --mode serial --port COM3
  python bridge.py --mode mqtt --broker localhost
  python bridge.py --mode tcp --host 192.168.1.100

Requirements:
  pip install meshtastic paho-mqtt requests

Service management:
  - Run as a Windows service, systemd unit, or in a terminal
  - Writes logs to stdout and optionally to a log file
  - PID file for process management
  - Health endpoint for monitoring
"""

import argparse
import json
import logging
import os
import signal
import sys
import time
import threading
from datetime import datetime, timezone
from pathlib import Path

# Optional imports (checked at runtime based on mode)
try:
    import requests
except ImportError:
    requests = None

try:
    import meshtastic
    import meshtastic.serial_interface
    import meshtastic.tcp_interface
    from pubsub import pub
    HAS_MESHTASTIC = True
except ImportError:
    HAS_MESHTASTIC = False

try:
    import paho.mqtt.client as mqtt
    HAS_PAHO = True
except ImportError:
    HAS_PAHO = False

# ─────────────────────────────────────────────────────────────
#  Configuration
# ─────────────────────────────────────────────────────────────

DEFAULT_CONFIG = {
    # Connection mode: mqtt, serial, tcp
    "mode": "mqtt",

    # TicketsCAD API
    "ticketscad_url": "http://localhost/newui",
    "ticketscad_api_token": "",  # If API token auth is used
    "ticketscad_csrf_token": "",

    # MQTT settings
    "mqtt_broker": "localhost",
    "mqtt_port": 1883,
    "mqtt_username": "",
    "mqtt_password": "",
    "mqtt_topic_root": "msh/US/2",
    "mqtt_json_enabled": True,

    # Serial settings
    "serial_port": "",  # Auto-detect if empty
    "serial_baud": 115200,

    # TCP settings
    "tcp_host": "meshtastic.local",
    "tcp_port": 4403,

    # Behavior
    "poll_interval": 5,       # Seconds between position reports
    "message_channel": "general",  # TicketsCAD chat channel for mesh messages
    "log_level": "INFO",
    "log_file": "",
    "pid_file": "",
    "health_port": 0,         # HTTP health check port (0 = disabled)

    # Provider code for location reports
    "provider_code": "meshtastic",
}

# ─────────────────────────────────────────────────────────────
#  Logging
# ─────────────────────────────────────────────────────────────

logger = logging.getLogger("meshtastic-bridge")


def setup_logging(config):
    level = getattr(logging, config.get("log_level", "INFO").upper(), logging.INFO)
    fmt = "%(asctime)s [%(levelname)s] %(message)s"
    handlers = [logging.StreamHandler(sys.stdout)]

    log_file = config.get("log_file", "")
    if log_file:
        handlers.append(logging.FileHandler(log_file))

    logging.basicConfig(level=level, format=fmt, handlers=handlers)


# ─────────────────────────────────────────────────────────────
#  TicketsCAD API Client
# ─────────────────────────────────────────────────────────────

class TicketsCADClient:
    """Sends location reports and messages to TicketsCAD API."""

    def __init__(self, config):
        self.base_url = config.get("ticketscad_url", "http://localhost/newui").rstrip("/")
        self.session = requests.Session() if requests else None
        self.provider_code = config.get("provider_code", "meshtastic")
        self._csrf_token = config.get("ticketscad_csrf_token", "")

    def report_position(self, unit_identifier, lat, lng, altitude=None,
                        speed=None, heading=None, battery=None, raw_data=None):
        """Send a position report to TicketsCAD location API."""
        if not self.session:
            logger.error("requests library not installed")
            return False

        payload = {
            "action": "report",
            "provider_code": self.provider_code,
            "unit_identifier": unit_identifier,
            "lat": lat,
            "lng": lng,
            "altitude": altitude,
            "speed": speed,
            "heading": heading,
            "battery": battery,
            "raw_data": json.dumps(raw_data) if raw_data else None,
            "csrf_token": self._csrf_token,
        }

        try:
            resp = self.session.post(
                f"{self.base_url}/api/location.php",
                json=payload,
                timeout=10
            )
            if resp.status_code == 200:
                data = resp.json()
                if data.get("saved"):
                    logger.debug(f"Position saved for {unit_identifier}: ({lat}, {lng})")
                    return True
                else:
                    logger.warning(f"Position not saved: {data}")
            else:
                logger.warning(f"API returned {resp.status_code}: {resp.text[:200]}")
        except Exception as e:
            logger.error(f"Failed to report position: {e}")

        return False

    def send_chat_message(self, from_name, body, channel="general"):
        """Send a received mesh message to TicketsCAD chat."""
        if not self.session:
            return False

        payload = {
            "action": "send",
            "channel": channel,
            "body": body,
            "from_name": from_name,
            "msg_type": "mesh_radio",
            "csrf_token": self._csrf_token,
        }

        try:
            resp = self.session.post(
                f"{self.base_url}/api/chat.php",
                json=payload,
                timeout=10
            )
            return resp.status_code == 200
        except Exception as e:
            logger.error(f"Failed to send chat: {e}")
            return False

    def report_health(self, status, details=None):
        """Report bridge health to TicketsCAD service monitor."""
        if not self.session:
            return

        try:
            self.session.post(
                f"{self.base_url}/api/service-uptime.php",
                json={
                    "service": "meshtastic",
                    "status": status,
                    "details": details or {},
                    "csrf_token": self._csrf_token,
                },
                timeout=5
            )
        except Exception:
            pass


# ─────────────────────────────────────────────────────────────
#  MQTT Mode
# ─────────────────────────────────────────────────────────────

class MQTTBridge:
    """Connects to Meshtastic MQTT broker for position and message data."""

    def __init__(self, config, cad_client):
        if not HAS_PAHO:
            raise RuntimeError("paho-mqtt not installed: pip install paho-mqtt")

        self.config = config
        self.cad = cad_client
        # paho-mqtt 2.0 requires a CallbackAPIVersion as the first arg; 1.x has
        # no such parameter. Detect and use VERSION2 on 2.x, else the 1.x form.
        if hasattr(mqtt, "CallbackAPIVersion"):
            self.client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2,
                                      client_id="ticketscad-meshtastic-bridge")
        else:
            self.client = mqtt.Client(client_id="ticketscad-meshtastic-bridge")
        self.running = False
        self.stats = {"positions": 0, "messages": 0, "errors": 0, "connected_at": None}

        # Auth
        username = config.get("mqtt_username", "")
        password = config.get("mqtt_password", "")
        if username:
            self.client.username_pw_set(username, password)

        self.client.on_connect = self._on_connect
        self.client.on_message = self._on_message
        self.client.on_disconnect = self._on_disconnect

    def start(self):
        broker = self.config.get("mqtt_broker", "localhost")
        port = int(self.config.get("mqtt_port", 1883))

        logger.info(f"Connecting to MQTT broker {broker}:{port}")
        self.running = True

        try:
            self.client.connect(broker, port, keepalive=60)
            self.client.loop_start()
        except Exception as e:
            logger.error(f"MQTT connection failed: {e}")
            self.running = False

    def stop(self):
        self.running = False
        self.client.loop_stop()
        self.client.disconnect()
        logger.info("MQTT bridge stopped")

    def _on_connect(self, client, userdata, flags, *args):
        # paho v1: args=(rc,); v2: args=(reason_code, properties). Normalize to int.
        reason = args[0] if args else 0
        rc = getattr(reason, "value", reason)
        if rc == 0:
            logger.info("Connected to MQTT broker")
            self.stats["connected_at"] = datetime.now(timezone.utc).isoformat()

            root = self.config.get("mqtt_topic_root", "msh/US/2")

            # Subscribe to JSON topics if enabled
            if self.config.get("mqtt_json_enabled", True):
                topic = f"{root}/json/#"
                client.subscribe(topic)
                logger.info(f"Subscribed to JSON topic: {topic}")
            else:
                # Subscribe to protobuf topics
                topic = f"{root}/e/#"
                client.subscribe(topic)
                logger.info(f"Subscribed to protobuf topic: {topic}")
        else:
            logger.error(f"MQTT connection refused (rc={rc})")

    def _on_disconnect(self, client, userdata, *args):
        # paho v1: args=(rc,); v2: args=(disconnect_flags, reason_code, properties).
        reason = args[1] if len(args) >= 2 else (args[0] if args else 0)
        rc = getattr(reason, "value", reason)
        if rc != 0:
            logger.warning(f"MQTT disconnected unexpectedly (rc={rc}), will reconnect")
        self.stats["connected_at"] = None

    def _on_message(self, client, userdata, msg):
        try:
            if "/json/" in msg.topic:
                self._handle_json_message(msg)
            else:
                self._handle_protobuf_message(msg)
        except Exception as e:
            logger.error(f"Error processing message on {msg.topic}: {e}")
            self.stats["errors"] += 1

    def _handle_json_message(self, msg):
        """Process Meshtastic JSON MQTT messages."""
        try:
            data = json.loads(msg.payload)
        except json.JSONDecodeError:
            return

        msg_type = data.get("type")
        sender = data.get("sender", data.get("from", "unknown"))

        # Position message
        if msg_type == "position" or "position" in data:
            pos = data.get("payload", data.get("position", data))
            lat = pos.get("latitude_i", 0) / 1e7 if "latitude_i" in pos else pos.get("latitude", 0)
            lng = pos.get("longitude_i", 0) / 1e7 if "longitude_i" in pos else pos.get("longitude", 0)
            alt = pos.get("altitude")
            speed = pos.get("ground_speed")
            heading = pos.get("ground_track")
            battery = pos.get("battery_level")

            if lat != 0 and lng != 0:
                # Use short name or node ID as identifier
                node_id = str(sender)
                self.cad.report_position(
                    unit_identifier=node_id,
                    lat=lat, lng=lng,
                    altitude=alt, speed=speed, heading=heading,
                    battery=battery, raw_data=data
                )
                self.stats["positions"] += 1

        # Text message
        elif msg_type == "text" or "text" in data:
            text = data.get("payload", data.get("text", ""))
            if isinstance(text, dict):
                text = text.get("text", str(text))
            from_name = data.get("sender_short_name", sender)
            channel = self.config.get("message_channel", "general")

            self.cad.send_chat_message(
                from_name=f"[Mesh] {from_name}",
                body=str(text),
                channel=channel
            )
            self.stats["messages"] += 1
            logger.info(f"Mesh message from {from_name}: {text[:100]}")

    def _handle_protobuf_message(self, msg):
        """Process Meshtastic protobuf MQTT messages (requires meshtastic library)."""
        if not HAS_MESHTASTIC:
            return

        try:
            from meshtastic.protobuf import mesh_pb2, mqtt_pb2
            envelope = mqtt_pb2.ServiceEnvelope()
            envelope.ParseFromString(msg.payload)

            packet = envelope.packet
            if packet.decoded.portnum == 3:  # POSITION_APP
                pos = mesh_pb2.Position()
                pos.ParseFromString(packet.decoded.payload)

                lat = pos.latitude_i / 1e7
                lng = pos.longitude_i / 1e7
                if lat != 0 and lng != 0:
                    self.cad.report_position(
                        unit_identifier=f"!{packet.from_node:08x}",
                        lat=lat, lng=lng,
                        altitude=pos.altitude if pos.altitude else None,
                        speed=pos.ground_speed if pos.ground_speed else None,
                    )
                    self.stats["positions"] += 1

            elif packet.decoded.portnum == 1:  # TEXT_MESSAGE_APP
                text = packet.decoded.payload.decode("utf-8", errors="replace")
                self.cad.send_chat_message(
                    from_name=f"[Mesh] !{packet.from_node:08x}",
                    body=text,
                )
                self.stats["messages"] += 1

        except Exception as e:
            logger.debug(f"Protobuf parse error: {e}")


# ─────────────────────────────────────────────────────────────
#  Serial Mode
# ─────────────────────────────────────────────────────────────

class SerialBridge:
    """Direct USB serial connection to a Meshtastic radio."""

    def __init__(self, config, cad_client):
        if not HAS_MESHTASTIC:
            raise RuntimeError("meshtastic not installed: pip install meshtastic")

        self.config = config
        self.cad = cad_client
        self.interface = None
        self.running = False
        self.stats = {"positions": 0, "messages": 0, "errors": 0, "connected_at": None}

    def start(self):
        port = self.config.get("serial_port", "")
        logger.info(f"Connecting to Meshtastic serial{' on ' + port if port else ' (auto-detect)'}...")

        try:
            if port:
                self.interface = meshtastic.serial_interface.SerialInterface(port)
            else:
                self.interface = meshtastic.serial_interface.SerialInterface()

            self.running = True
            self.stats["connected_at"] = datetime.now(timezone.utc).isoformat()

            # Subscribe to events
            pub.subscribe(self._on_receive, "meshtastic.receive")
            pub.subscribe(self._on_position, "meshtastic.receive.position")
            pub.subscribe(self._on_text, "meshtastic.receive.text")

            logger.info("Serial connection established")
        except Exception as e:
            logger.error(f"Serial connection failed: {e}")
            self.running = False

    def stop(self):
        self.running = False
        if self.interface:
            try:
                self.interface.close()
            except Exception:
                pass
        logger.info("Serial bridge stopped")

    def send_message(self, text, destination=None):
        """Send a text message to the mesh network."""
        if not self.interface:
            return False
        try:
            if destination:
                self.interface.sendText(text, destinationId=destination)
            else:
                self.interface.sendText(text)
            logger.info(f"Sent to mesh: {text[:100]}")
            return True
        except Exception as e:
            logger.error(f"Failed to send: {e}")
            return False

    def _on_receive(self, packet, interface):
        """Generic packet handler."""
        pass

    def _on_position(self, packet, interface):
        """Handle position packets."""
        try:
            pos = packet.get("decoded", {}).get("position", {})
            node = packet.get("fromId", packet.get("from", "unknown"))

            lat = pos.get("latitude", 0)
            lng = pos.get("longitude", 0)

            if lat != 0 and lng != 0:
                self.cad.report_position(
                    unit_identifier=str(node),
                    lat=lat, lng=lng,
                    altitude=pos.get("altitude"),
                    speed=pos.get("groundSpeed"),
                    heading=pos.get("groundTrack"),
                    battery=packet.get("decoded", {}).get("telemetry", {}).get("batteryLevel"),
                    raw_data=packet
                )
                self.stats["positions"] += 1
        except Exception as e:
            logger.error(f"Position handler error: {e}")
            self.stats["errors"] += 1

    def _on_text(self, packet, interface):
        """Handle text message packets."""
        try:
            text = packet.get("decoded", {}).get("text", "")
            node = packet.get("fromId", packet.get("from", "unknown"))

            self.cad.send_chat_message(
                from_name=f"[Mesh] {node}",
                body=text,
                channel=self.config.get("message_channel", "general")
            )
            self.stats["messages"] += 1
            logger.info(f"Mesh text from {node}: {text[:100]}")
        except Exception as e:
            logger.error(f"Text handler error: {e}")
            self.stats["errors"] += 1


# ─────────────────────────────────────────────────────────────
#  TCP Mode
# ─────────────────────────────────────────────────────────────

class TCPBridge(SerialBridge):
    """Network TCP connection to a WiFi-enabled Meshtastic node.
    Uses the same event handlers as SerialBridge."""

    def start(self):
        host = self.config.get("tcp_host", "meshtastic.local")
        port = int(self.config.get("tcp_port", 4403))

        logger.info(f"Connecting to Meshtastic TCP {host}:{port}...")

        try:
            self.interface = meshtastic.tcp_interface.TCPInterface(host)
            self.running = True
            self.stats["connected_at"] = datetime.now(timezone.utc).isoformat()

            pub.subscribe(self._on_receive, "meshtastic.receive")
            pub.subscribe(self._on_position, "meshtastic.receive.position")
            pub.subscribe(self._on_text, "meshtastic.receive.text")

            logger.info("TCP connection established")
        except Exception as e:
            logger.error(f"TCP connection failed: {e}")
            self.running = False


# ─────────────────────────────────────────────────────────────
#  Health Check Server (optional)
# ─────────────────────────────────────────────────────────────

class HealthServer:
    """Simple HTTP health check endpoint for monitoring."""

    def __init__(self, port, bridge):
        self.port = port
        self.bridge = bridge
        self.thread = None

    def start(self):
        if self.port <= 0:
            return

        from http.server import HTTPServer, BaseHTTPRequestHandler

        bridge = self.bridge

        class Handler(BaseHTTPRequestHandler):
            def do_GET(self):
                status = {
                    "service": "meshtastic-bridge",
                    "status": "running" if bridge.running else "stopped",
                    "mode": bridge.config.get("mode", "unknown"),
                    "stats": bridge.stats,
                    "timestamp": datetime.now(timezone.utc).isoformat(),
                }
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(json.dumps(status).encode())

            def log_message(self, format, *args):
                pass  # Suppress access logs

        server = HTTPServer(("0.0.0.0", self.port), Handler)
        self.thread = threading.Thread(target=server.serve_forever, daemon=True)
        self.thread.start()
        logger.info(f"Health check server on port {self.port}")


# ─────────────────────────────────────────────────────────────
#  Main
# ─────────────────────────────────────────────────────────────

def load_config(args):
    """Load configuration from file or command-line args."""
    config = dict(DEFAULT_CONFIG)

    # Load from config file if specified
    if args.config and os.path.exists(args.config):
        import configparser
        cp = configparser.ConfigParser()
        cp.read(args.config)
        if "meshtastic" in cp:
            for key, val in cp["meshtastic"].items():
                config[key] = val

    # Override with CLI args
    if args.mode:
        config["mode"] = args.mode
    if args.port:
        config["serial_port"] = args.port
    if args.broker:
        config["mqtt_broker"] = args.broker
    if args.host:
        config["tcp_host"] = args.host
    if args.url:
        config["ticketscad_url"] = args.url
    if args.verbose:
        config["log_level"] = "DEBUG"

    return config


def main():
    parser = argparse.ArgumentParser(description="TicketsCAD Meshtastic Bridge")
    parser.add_argument("--config", help="Config file path (INI format)")
    parser.add_argument("--mode", choices=["mqtt", "serial", "tcp"], help="Connection mode")
    parser.add_argument("--port", help="Serial port (e.g., COM3, /dev/ttyUSB0)")
    parser.add_argument("--broker", help="MQTT broker hostname")
    parser.add_argument("--host", help="TCP host for WiFi node")
    parser.add_argument("--url", help="TicketsCAD base URL")
    parser.add_argument("--verbose", "-v", action="store_true", help="Debug logging")
    args = parser.parse_args()

    config = load_config(args)
    setup_logging(config)

    logger.info("=" * 60)
    logger.info("TicketsCAD Meshtastic Bridge")
    logger.info(f"Mode: {config['mode']}")
    logger.info(f"TicketsCAD: {config['ticketscad_url']}")
    logger.info("=" * 60)

    # Initialize TicketsCAD API client
    cad_client = TicketsCADClient(config)

    # Create bridge based on mode
    mode = config["mode"]
    if mode == "mqtt":
        bridge = MQTTBridge(config, cad_client)
    elif mode == "serial":
        bridge = SerialBridge(config, cad_client)
    elif mode == "tcp":
        bridge = TCPBridge(config, cad_client)
    else:
        logger.error(f"Unknown mode: {mode}")
        sys.exit(1)

    # Start health check server
    health = HealthServer(int(config.get("health_port", 0)), bridge)
    health.start()

    # Handle signals
    def shutdown(sig, frame):
        logger.info("Shutting down...")
        bridge.stop()
        cad_client.report_health("stopped")
        sys.exit(0)

    signal.signal(signal.SIGINT, shutdown)
    signal.signal(signal.SIGTERM, shutdown)

    # Start bridge
    bridge.start()
    cad_client.report_health("running", {"mode": mode})

    # Keep running
    try:
        while bridge.running:
            time.sleep(1)
    except KeyboardInterrupt:
        pass

    bridge.stop()
    cad_client.report_health("stopped")


if __name__ == "__main__":
    main()
