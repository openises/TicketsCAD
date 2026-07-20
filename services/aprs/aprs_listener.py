#!/usr/bin/env python3
"""
TicketsCAD APRS-IS listener — Phase 99g #50 (2026-06-28).

Connects to the APRS-IS public network with the install's licensed
callsign + computed passcode, subscribes to a configurable geographic
filter, parses incoming position and message packets, and writes:

  - location_reports (provider_id = APRS-IS row) for position packets
  - chat_messages    (channel = 'aprs')           for addressed messages

Reconnects with exponential backoff on disconnect. Runs as a systemd
service under www-data. Reads settings (callsign, passcode, filter,
server, port) from the TicketsCAD `settings` table on each connect.

Dependencies:
  - aprslib (pip install aprslib)
  - mysql.connector (pip install mysql-connector-python)
"""

from __future__ import annotations

import json
import logging
import os
import re
import signal
import sys
import time
from datetime import datetime

import aprslib                       # type: ignore
import mysql.connector               # type: ignore

try:
    from zoneinfo import ZoneInfo    # Python 3.9+
except ImportError:                  # pragma: no cover — ancient interpreter
    ZoneInfo = None

# ── Config ────────────────────────────────────────────────────────
CONFIG_FILE = os.environ.get('APRS_LISTENER_CONFIG', '/etc/ticketscad-aprs.conf')
DEFAULT_PROVIDER_CODE = 'aprs'
DEFAULT_SERVER = 'rotate.aprs2.net'
DEFAULT_PORT = 14580
DEFAULT_FILTER = 'r/45.0/-93.0/200'   # 200km around Twin Cities — overridden by settings

# Stop flag set by SIGTERM/SIGINT
_should_stop = False

# Configure logging to stderr (systemd captures to journal).
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
)
log = logging.getLogger('aprs-listener')


def _load_db_config() -> dict:
    """
    Read DB credentials from /etc/ticketscad-aprs.conf — JSON:
        {"db_host": "...", "db_user": "...", "db_pass": "...", "db_name": "..."}
    File mode should be 0600 owned by www-data.
    """
    if not os.path.exists(CONFIG_FILE):
        log.error('config file not found: %s', CONFIG_FILE)
        log.error('create it with: {"db_host":"localhost","db_user":"newui",'
                  '"db_pass":"...","db_name":"newui"}')
        sys.exit(2)
    with open(CONFIG_FILE) as fh:
        cfg = json.load(fh)
    for key in ('db_host', 'db_user', 'db_pass', 'db_name'):
        if key not in cfg:
            log.error('missing %s in %s', key, CONFIG_FILE)
            sys.exit(2)
    return cfg


def _db_connect(cfg: dict):
    """Open a fresh DB connection. Caller closes."""
    return mysql.connector.connect(
        host=cfg['db_host'], user=cfg['db_user'],
        password=cfg['db_pass'], database=cfg['db_name'],
        autocommit=True, connection_timeout=10,
    )


def _settings_lookup(db, name: str, default: str = '') -> str:
    """Read a value from the TicketsCAD settings table."""
    cur = db.cursor()
    try:
        cur.execute("SELECT value FROM settings WHERE name = %s LIMIT 1", (name,))
        row = cur.fetchone()
        return (row[0] if row and row[0] is not None else default)
    finally:
        cur.close()


# ── Timezone alignment (Eric 2026-07-08) ──────────────────────────
# TicketsCAD DATETIME columns hold wall-clock time in the install's
# area_timezone: every PHP session syncs its MySQL session time_zone to
# that zone (config.php), so NOW() / CURRENT_TIMESTAMP / date() all
# agree. This listener used datetime.utcnow() AND left its MySQL session
# on the server default (UTC on the training VM) — every APRS row landed
# ~5 h in the future for US-Central installs: the Location Ingest panel
# showed "-17999s ago" and staleness checks saw APRS units as
# permanently fresh. Resolve the area timezone once per connect cycle
# (settings can change between reconnects) and use it for BOTH the
# explicit stamps and the session time_zone.
_area_tz = None


def _resolve_area_tz(db):
    """Set _area_tz from the settings table; align the session clock."""
    global _area_tz
    # config.php's hardcoded pre-override default is America/New_York,
    # so an install with no area_timezone setting runs PHP in Eastern —
    # match that rather than the VM's UTC.
    tz_name = _settings_lookup(db, 'area_timezone', 'America/New_York') \
        or 'America/New_York'
    if ZoneInfo is not None:
        try:
            _area_tz = ZoneInfo(tz_name)
        except Exception:
            log.warning('unknown area_timezone %r — keeping previous/none', tz_name)
    if _area_tz is not None:
        # Align NOW()/CURRENT_TIMESTAMP for everything this connection
        # writes (internal_messages fan-out uses SQL-side timestamps).
        offset = datetime.now(_area_tz).strftime('%z')          # +HHMM / -HHMM
        offset = offset[:3] + ':' + offset[3:]                  # +HH:MM
        cur = db.cursor()
        try:
            cur.execute("SET time_zone = %s", (offset,))
        except mysql.connector.Error as e:
            log.warning('SET time_zone failed: %s', e)
        finally:
            cur.close()


def _now_local_str() -> str:
    """Wall-clock string in the same zone the PHP stack reads/writes."""
    if _area_tz is not None:
        return datetime.now(_area_tz).strftime('%Y-%m-%d %H:%M:%S')
    return datetime.now().strftime('%Y-%m-%d %H:%M:%S')


def _resolve_provider_id(db, code: str) -> int | None:
    cur = db.cursor()
    try:
        cur.execute(
            "SELECT id FROM location_providers WHERE code = %s LIMIT 1",
            (code,)
        )
        row = cur.fetchone()
        return int(row[0]) if row else None
    finally:
        cur.close()


# ── Packet handlers ───────────────────────────────────────────────

def _insert_position(db, provider_id: int, callsign: str, parsed: dict):
    """
    Write a row to location_reports for a position packet.
    Schema (per training instance):
        id, provider_id, unit_identifier, lat, lng, altitude, speed,
        heading, accuracy, battery, raw_data, reported_at, received_at,
        auth_token_id
    """
    lat = parsed.get('latitude')
    lng = parsed.get('longitude')
    if lat is None or lng is None:
        return
    # APRS speed is knots; convert to kph for consistency with other
    # providers in TicketsCAD (which use kph).
    speed_kts = parsed.get('speed')
    speed_kph = (float(speed_kts) * 1.852) if speed_kts is not None else None
    course = parsed.get('course')
    altitude_ft = parsed.get('altitude')
    altitude_m = (float(altitude_ft) * 0.3048) if altitude_ft is not None else None

    raw = parsed.get('raw') or ''
    if len(raw) > 65000:
        raw = raw[:65000]   # location_reports.raw_data is TEXT (~64KB)

    now = _now_local_str()

    cur = db.cursor()
    try:
        cur.execute(
            """INSERT INTO location_reports
                 (provider_id, unit_identifier, lat, lng,
                  altitude, speed, heading, raw_data,
                  reported_at, received_at)
               VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""",
            (provider_id, callsign[:64], float(lat), float(lng),
             altitude_m, speed_kph, course, raw,
             now, now)
        )
    except mysql.connector.Error as e:
        log.warning('DB insert (position) failed for %s: %s', callsign, e)
    finally:
        cur.close()


def _insert_message(db, from_call: str, body: str, msg_no: str | None, raw: str):
    """
    Write an inbound APRS addressed-message to the messaging system so
    it surfaces in messaging.php's Inbox (not the chat widget).

    Pattern mirrors api/messaging.php's broadcast action — insert one
    internal_messages row, then fan out to ALL users via
    message_recipients. Each user sees the message in their Inbox with
    an unread badge.

    Schemas (verified on training 2026-06-29):
      internal_messages:  id, from_user_id, subject, body, priority,
                          incident_id, is_broadcast, created_at
      message_recipients: id, message_id, to_user_id, read_at, deleted_at

    from_user_id is 0 (no user row) since APRS senders are external
    callsigns. The Inbox UI shows from_name=NULL as 'Unknown' — to
    make the source obvious despite that, we encode the callsign +
    msg_no in the subject line.
    """
    subject = f'APRS msg from {from_call}'
    if msg_no:
        subject += f' (#{msg_no})'
    subject = subject[:255]

    # Body keeps the actual message text; raw frame appended as a
    # 'Source:' line at the bottom so operators can see what came
    # over the air if they need to debug.
    full_body = body
    if raw:
        full_body = (body + '\n\nSource frame: ' + raw)[:65000]

    cur = db.cursor()
    try:
        # 1. Insert the message
        cur.execute(
            """INSERT INTO internal_messages
                 (from_user_id, subject, body, priority, is_broadcast)
               VALUES (%s, %s, %s, %s, %s)""",
            (0, subject, full_body, 'normal', 1)
        )
        message_id = cur.lastrowid

        # 2. Fan out to all users (matches messaging.php broadcast pattern)
        cur.execute("SELECT id FROM user")
        user_ids = [row[0] for row in cur.fetchall()]
        for uid in user_ids:
            cur.execute(
                """INSERT INTO message_recipients
                     (message_id, to_user_id)
                   VALUES (%s, %s)""",
                (message_id, uid)
            )

        log.info('inbound APRS message from %s → internal_messages #%d, '
                 'fanned to %d users', from_call, message_id, len(user_ids))
    except mysql.connector.Error as e:
        log.warning('DB insert (message) failed: %s', e)
    finally:
        cur.close()


def _send_aprs_ack(ais, my_callsign: str, to_call: str, msg_no: str):
    """Send an ack back through the open APRS-IS connection."""
    # APRS message ack format: addressee:ackNN
    # addressee field is exactly 9 chars, space-padded.
    addressee = (to_call + ' ' * 9)[:9]
    packet = f'{my_callsign}>APRS,TCPIP*::{addressee}:ack{msg_no}'
    try:
        ais.sendall(packet)
        log.info('ACK sent to %s for msg %s', to_call, msg_no)
    except Exception as e:
        log.warning('failed to send ACK: %s', e)


# ── Main run loop ────────────────────────────────────────────────

def _on_packet_factory(db, provider_id: int, my_callsign: str, ais_holder: list):
    """
    Build the packet callback. ais_holder is a 1-element list that
    aprslib will populate with the active connection so we can send
    acks back through it.
    """
    msg_addressee_re = re.compile(r'^:([A-Z0-9-]{1,9}\s*):(.+?)(\{(\w{1,5}))?$')

    def _on_packet(packet):
        try:
            fmt = packet.get('format')
            from_call = packet.get('from')

            # Position packets — formats: 'compressed', 'uncompressed',
            # 'mic-e', 'object'. All carry latitude + longitude when valid.
            if fmt in ('compressed', 'uncompressed', 'mic-e', 'object'):
                if from_call and packet.get('latitude') is not None:
                    _insert_position(db, provider_id, from_call, packet)
                return

            # Addressed messages — format='message' with addressee == us
            if fmt == 'message':
                addressee = packet.get('addresse', '').strip()
                if addressee.upper() == my_callsign.upper():
                    body = packet.get('message_text', '')
                    msg_no = packet.get('msgNo')
                    raw = packet.get('raw', '')
                    _insert_message(db, from_call, body, msg_no, raw)
                    # ACK if a msg_no was provided
                    if msg_no and ais_holder and ais_holder[0]:
                        _send_aprs_ack(ais_holder[0], my_callsign,
                                       from_call, msg_no)
                return

            # Status, weather, telemetry, etc — log volume but skip
        except Exception as e:
            log.warning('packet handler error: %s — packet=%r', e, packet)

    return _on_packet


def run_once(cfg: dict):
    """One connect-and-listen cycle. Returns when the connection drops."""
    db = _db_connect(cfg)
    try:
        _resolve_area_tz(db)
        # Setting keys match inc/channels/aprs.php — the canonical
        # send-side handler uses these exact names. Earlier drafts of
        # this listener had aprs_send_server / aprs_send_port (wrong
        # — those keys are not written anywhere in the codebase) and
        # always fell back to defaults, ignoring admin overrides.
        callsign = _settings_lookup(db, 'aprs_send_callsign')
        passcode_str = _settings_lookup(db, 'aprs_send_passcode', '0')
        passcode = int(passcode_str) if passcode_str else 0
        server   = _settings_lookup(db, 'aprs_is_server', DEFAULT_SERVER) or DEFAULT_SERVER
        port_str = _settings_lookup(db, 'aprs_is_port', str(DEFAULT_PORT))
        port     = int(port_str) if port_str else DEFAULT_PORT
        flt      = _settings_lookup(db, 'aprs_recv_filter', DEFAULT_FILTER) or DEFAULT_FILTER

        # passcode > 0  → full auth, can send + receive + ack messages
        # passcode = -1 → APRS-IS receive-only mode (no login required;
        #                 receive positions but cannot send messages)
        # passcode = 0 or missing → not configured
        if not callsign:
            log.warning('APRS-IS callsign not configured — sleeping 60s '
                        '(set in Settings → APRS-IS, accept the license gate)')
            time.sleep(60)
            return
        if passcode == 0:
            log.warning('APRS-IS passcode is 0 — sleeping 60s '
                        '(should be the computed passcode from your callsign, '
                        'or -1 for receive-only mode)')
            time.sleep(60)
            return

        provider_id = _resolve_provider_id(db, DEFAULT_PROVIDER_CODE)
        if not provider_id:
            log.error('location_providers row with code=aprs not found — '
                      'run sql/run_99g_aprs_provider.php')
            time.sleep(60)
            return

        # APRS-IS filter — include user's own callsign for addressed messages
        # plus whatever geographic filter the install configured.
        full_filter = f'{flt} b/{callsign.upper()}'

        log.info('connecting to %s:%d as %s, filter=%r, provider_id=%d',
                 server, port, callsign, full_filter, provider_id)

        ais = aprslib.IS(callsign, passwd=passcode, host=server, port=port)
        ais.set_filter(full_filter)
        ais.connect(blocking=True)

        ais_holder = [ais]
        handler = _on_packet_factory(db, provider_id, callsign, ais_holder)

        # Spin our own loop so we can honor _should_stop. aprslib's
        # consumer() blocks indefinitely; using a thread-safe stopper
        # is cleaner via this poll pattern.
        ais.consumer(handler, blocking=True, immortal=False, raw=False)

    except aprslib.exceptions.LoginError as e:
        log.error('APRS-IS login rejected: %s', e)
        time.sleep(60)
    except (aprslib.exceptions.ConnectionDrop,
            aprslib.exceptions.ConnectionError) as e:
        log.warning('APRS-IS connection dropped: %s', e)
    except Exception as e:
        log.exception('unexpected error in run loop: %s', e)
    finally:
        try:
            db.close()
        except Exception:
            pass


def main():
    def _stop(_signum, _frame):
        global _should_stop
        log.info('signal received, stopping...')
        _should_stop = True
        sys.exit(0)

    signal.signal(signal.SIGTERM, _stop)
    signal.signal(signal.SIGINT, _stop)

    cfg = _load_db_config()
    log.info('TicketsCAD APRS-IS listener starting (db=%s/%s)',
             cfg['db_host'], cfg['db_name'])

    # Exponential backoff on reconnect.
    backoff = 5
    while not _should_stop:
        start = time.time()
        try:
            run_once(cfg)
        except Exception as e:
            log.exception('run_once raised: %s', e)
        elapsed = time.time() - start
        if elapsed > 60:
            backoff = 5   # session held > 1 min counts as success
        else:
            backoff = min(backoff * 2, 300)
        log.info('reconnecting in %d seconds', backoff)
        time.sleep(backoff)


if __name__ == '__main__':
    main()
