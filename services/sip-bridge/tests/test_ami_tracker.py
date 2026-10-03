"""
Phase 155 (GH#108 S4): one ringing event per CALL from the Asterisk AMI bridge.

Asterisk reports a single call as many channels (the caller's, plus one
outbound channel per phone a ring group rings), all sharing a Linkedid. The
bridge used to forward a "ringing" per Newchannel keyed by Uniqueid, so a
three-phone ring group produced several banner rows for one call, and every
leg's Hangup produced an "ended" for a row nobody had seen. AmiCallTracker
reports one call per Linkedid, from its first channel only.

HONESTY NOTE -- these event sequences are SYNTHETIC. They are written from
the Asterisk AMI event documentation (Newchannel, Newstate, DialBegin/DialEnd,
Hangup and their Uniqueid / Linkedid / ChannelState / DialStatus headers, the
Asterisk 12+ shapes); no live PBX produced them. Replacing them with a capture
from a real ring-group call is the open verification step recorded in the
Phase 155 build log. What THIS file proves is the bridge's logic against those
sequences, and (test_wire_format_end_to_end) that real AMI text on a real TCP
socket flows through the real parser and tracker into the canonical events.

Run:  python services/sip-bridge/tests/test_ami_tracker.py
"""
import os
import socket
import sys
import threading
import time
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
BRIDGE_DIR = os.path.dirname(HERE)
sys.path.insert(0, BRIDGE_DIR)

try:
    import bridge  # noqa: E402
except ImportError as exc:  # pragma: no cover
    print("SKIP: %s" % exc)
    sys.exit(0)

LINKED = "1759421234.12"


def newchannel(uid, linked=LINKED, num="6125551234", name="ACME DISPATCH", exten="100", context="from-trunk", ch="PJSIP/trunk-00000001"):
    return {"Event": "Newchannel", "Channel": ch, "ChannelState": "0", "CallerIDNum": num, "CallerIDName": name,
            "Context": context, "Exten": exten, "Uniqueid": uid, "Linkedid": linked}


def newstate(uid, state, linked=LINKED):
    return {"Event": "Newstate", "Uniqueid": uid, "Linkedid": linked, "ChannelState": str(state)}


def dialend(status, linked=LINKED, uid=LINKED):
    return {"Event": "DialEnd", "Uniqueid": uid, "Linkedid": linked, "DialStatus": status}


def hangup(uid, linked=LINKED, cause="16"):
    return {"Event": "Hangup", "Uniqueid": uid, "Linkedid": linked, "Cause": cause}


def run(tracker, events):
    out = []
    for e in events:
        out.extend(tracker.handle(e))
    return out


def ring_group_prefix():
    """The caller's channel, then three outbound legs (one per ringing phone)."""
    return [
        newchannel(LINKED),
        newchannel("1759421234.13", exten="", ch="PJSIP/101-00000002", num="101"),
        newchannel("1759421234.14", exten="", ch="PJSIP/102-00000003", num="102"),
        newchannel("1759421234.15", exten="s", ch="PJSIP/103-00000004", num="103"),
    ]


class AmiTrackerTest(unittest.TestCase):

    def test_a_three_phone_ring_group_is_one_ringing_not_four(self):
        out = run(bridge.AmiCallTracker(), ring_group_prefix())
        self.assertEqual(len(out), 1, out)
        self.assertEqual(out[0]["event"], "ringing")
        self.assertEqual(out[0]["call_id"], LINKED)
        self.assertEqual(out[0]["caller_number"], "6125551234")
        self.assertEqual(out[0]["called_number"], "100")  # the dialed number, not a leg's blank / 's'

    def test_answered_call_ends_exactly_once(self):
        events = ring_group_prefix() + [
            dialend("ANSWER"),
            newstate(LINKED, 6),
            hangup("1759421234.14"), hangup("1759421234.15"), hangup("1759421234.13"),  # the legs
            hangup(LINKED),
        ]
        out = run(bridge.AmiCallTracker(), events)
        self.assertEqual([o["event"] for o in out], ["ringing", "ended"], out)
        self.assertTrue(all(o["call_id"] == LINKED for o in out))

    def test_nobody_answers_and_the_caller_gives_up_is_one_abandoned(self):
        events = ring_group_prefix() + [
            dialend("NOANSWER"), dialend("NOANSWER"), dialend("NOANSWER"),
            hangup("1759421234.13", cause="19"), hangup("1759421234.14", cause="19"), hangup("1759421234.15", cause="19"),
            hangup(LINKED, cause="16"),
        ]
        out = run(bridge.AmiCallTracker(), events)
        self.assertEqual([o["event"] for o in out], ["ringing", "abandoned"], out)

    def test_a_caller_who_hangs_up_while_it_rings_is_abandoned_not_ended(self):
        # Hangup cause 16 ("normal clearing") is what the caller's own hang-up
        # produces. The old cause-code guess called that "ended", so a missed
        # call never reached the Missed Calls list.
        out = run(bridge.AmiCallTracker(), ring_group_prefix() + [hangup(LINKED, cause="16")])
        self.assertEqual([o["event"] for o in out], ["ringing", "abandoned"], out)

    def test_answered_by_the_pbx_itself_counts_as_answered(self):
        # An IVR / queue that Answer()s the caller's channel: state Up, no DialEnd.
        out = run(bridge.AmiCallTracker(), [newchannel(LINKED), newstate(LINKED, 6), hangup(LINKED)])
        self.assertEqual([o["event"] for o in out], ["ringing", "ended"], out)

    def test_a_leg_reaching_up_is_not_the_call_being_answered(self):
        # Only the caller's channel (Uniqueid == Linkedid) going Up, or a DialEnd
        # ANSWER, answers the call: a ringing leg's own Newstate does not.
        events = ring_group_prefix() + [newstate("1759421234.13", 5), newstate("1759421234.13", 6), hangup(LINKED)]
        out = run(bridge.AmiCallTracker(), events)
        self.assertEqual([o["event"] for o in out], ["ringing", "abandoned"], out)

    def test_a_call_that_began_before_the_bridge_started_is_never_invented(self):
        out = run(bridge.AmiCallTracker(), [newstate(LINKED, 6), dialend("ANSWER"), hangup("1759421234.14"), hangup(LINKED)])
        self.assertEqual(out, [])

    def test_a_duplicate_newchannel_does_not_ring_twice(self):
        out = run(bridge.AmiCallTracker(), [newchannel(LINKED), newchannel(LINKED)])
        self.assertEqual(len(out), 1)

    def test_two_overlapping_calls_are_tracked_independently(self):
        a, b = "1759421300.1", "1759421301.2"
        events = [
            newchannel(a, linked=a, num="6125550001"), newchannel(b, linked=b, num="6125550002"),
            dialend("ANSWER", linked=b, uid=b), newstate(b, 6, linked=b),
            hangup(a, linked=a), hangup(b, linked=b),
        ]
        out = run(bridge.AmiCallTracker(), events)
        self.assertEqual([(o["event"], o["call_id"]) for o in out],
                         [("ringing", a), ("ringing", b), ("abandoned", a), ("ended", b)], out)

    def test_the_context_filter_scopes_to_one_trunk(self):
        tracker = bridge.AmiCallTracker(context_filter="from-pstn-mytrunk")
        events = [
            newchannel(LINKED, context="from-internal"),            # a dispatcher dialing out
            hangup(LINKED),
            newchannel("1759421400.1", linked="1759421400.1", context="from-pstn-mytrunk"),
            hangup("1759421400.1", linked="1759421400.1"),
        ]
        out = run(tracker, events)
        self.assertEqual([(o["event"], o["call_id"]) for o in out],
                         [("ringing", "1759421400.1"), ("abandoned", "1759421400.1")], out)

    def test_no_linkedid_from_an_older_asterisk_falls_back_to_uniqueid(self):
        e = newchannel("1759421500.1")
        del e["Linkedid"]
        out = bridge.AmiCallTracker().handle(e)
        self.assertEqual(len(out), 1)
        self.assertEqual(out[0]["call_id"], "1759421500.1")

    def test_unknown_caller_id_is_reported_as_absent(self):
        out = bridge.AmiCallTracker().handle(newchannel(LINKED, num="<unknown>", name=""))
        self.assertIsNone(out[0]["caller_number"])
        self.assertIsNone(out[0]["caller_name"])

    def test_a_dialend_for_a_call_not_tracked_is_ignored(self):
        self.assertEqual(bridge.AmiCallTracker().handle(dialend("ANSWER", linked="9.9", uid="9.9")), [])

    def test_state_is_bounded(self):
        clock = [1000.0]
        tracker = bridge.AmiCallTracker(clock=lambda: clock[0])
        tracker.MAX_TRACKED = 20
        for i in range(60):
            clock[0] += 1
            tracker.handle(newchannel("100.%d" % i, linked="100.%d" % i))
        self.assertLessEqual(len(tracker.calls), 21)
        clock[0] += tracker.MAX_AGE_SECONDS + 10
        tracker.handle(newchannel("200.1", linked="200.1"))
        self.assertEqual(list(tracker.calls), ["200.1"], "calls older than MAX_AGE_SECONDS are dropped")

    def test_ami_context_is_a_real_config_key(self):
        # The old docstring and bridge.ini.example both described an ami_context
        # option that load_config() silently ignored.
        self.assertIn("ami_context", bridge.DEFAULT_CONFIG)


class WireFormatEndToEnd(unittest.TestCase):
    """Real AMI text on a real TCP socket -> the real parser and tracker ->
    canonical payloads. Only the HTTP POST to TicketsCAD is replaced."""

    @staticmethod
    def block(fields):
        return "".join("%s: %s\r\n" % (k, v) for k, v in fields.items()) + "\r\n"

    def test_wire_format_end_to_end(self):
        script = ring_group_prefix() + [dialend("ANSWER"), newstate(LINKED, 6), hangup("1759421234.14"), hangup(LINKED)]
        server = socket.socket()
        server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        server.bind(("127.0.0.1", 0))
        server.listen(1)
        port = server.getsockname()[1]
        received_login = []

        def serve():
            conn, _ = server.accept()
            conn.sendall(b"Asterisk Call Manager/9.0.0\r\n")
            received_login.append(conn.recv(4096).decode("utf-8", "replace"))
            conn.sendall(self.block({"Event": "FullyBooted", "Status": "Fully Booted"}).encode("utf-8"))
            for ev in script:
                conn.sendall(self.block(ev).encode("utf-8"))
            time.sleep(0.5)
            conn.close()

        threading.Thread(target=serve, daemon=True).start()

        posted = []
        original = bridge._post_event
        bridge._post_event = lambda cfg, log, payload: (posted.append(payload), "ok")[1]
        stop = threading.Event()
        cfg = dict(bridge.DEFAULT_CONFIG)
        cfg.update({"ami_host": "127.0.0.1", "ami_port": port, "ami_user": "cad", "ami_secret": "s3cret", "ami_reconnect_seconds": 1})
        import logging
        thread = threading.Thread(target=bridge.run_ami_bridge, args=(cfg, logging.getLogger("t"), stop), daemon=True)
        try:
            thread.start()
            deadline = time.time() + 5
            while len(posted) < 2 and time.time() < deadline:
                time.sleep(0.05)
        finally:
            stop.set()
            bridge._post_event = original
            server.close()
        self.assertTrue(received_login and "Action: Login" in received_login[0] and "Events: call" in received_login[0])
        self.assertEqual([p["event"] for p in posted], ["ringing", "ended"], posted)
        self.assertEqual(posted[0]["call_id"], LINKED)
        self.assertEqual(posted[0]["called_number"], "100")


if __name__ == "__main__":
    unittest.main(verbosity=2)
