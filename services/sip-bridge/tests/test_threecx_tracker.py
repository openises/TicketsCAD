"""
Unit tests for services/sip-bridge/threecx.py's ThreeCxTracker -- the pure
state machine that turns 3CX Call Control WebSocket events into TicketsCAD's
canonical ringing / claimed_externally / ended / abandoned events.

PROVENANCE OF THE FIXTURES (read this before trusting a green run):
  * The ENVELOPE shape (attached_data is null on Upsert and Remove, so the
    participant must be fetched) and the participant FIELD VALUES for an
    inbound external call (party_dn_type "Wexternalline", empty party_did,
    E.164 party_caller_id) follow REAL captures posted on 3CX's forum and
    3CX's own sample code -- see specs/phase-155-community-backlog/
    003-3cx-integration-research.md, section 2 and fixtures F1/F2.
  * The SEQUENCES (a ring group ringing three extensions, a queue wave,
    a transfer) are RECONSTRUCTED from that schema (research fixtures F3-F7);
    nobody has published a complete real capture of those cases. They test
    this bridge's LOGIC. The first real capture (capture_file) should be added
    here as a new test.

Run:  python services/sip-bridge/tests/test_threecx_tracker.py
"""
import os
import re
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from threecx import ThreeCxTracker, parse_did_map  # noqa: E402


class FakeClock:
    def __init__(self, t=1_760_000_000.0):
        self.t = t

    def __call__(self):
        return self.t

    def advance(self, seconds):
        self.t += seconds


def participant(pid, dn, status, callid=4711, caller="+16125551234", name="MINNEAPOLIS MN",
                ptype="Wexternalline", party_dn="10001", originated_by_dn="", did="", legid=2):
    return {
        "id": pid, "status": status, "dn": dn,
        "party_caller_name": name, "party_dn": party_dn, "party_caller_id": caller,
        "party_did": did, "device_id": "sip:%s@10.0.0.21:5060" % dn, "party_dn_type": ptype,
        "direct_control": False,
        "originated_by_dn": originated_by_dn, "originated_by_type": "None",
        "referred_by_dn": "", "referred_by_type": "None",
        "on_behalf_of_dn": "", "on_behalf_of_type": "None",
        "callid": callid, "legid": legid,
    }


def envelope(etype, dn, pid, seq=1):
    # attached_data is NULL on both, exactly as real 3CX servers send it.
    return {"sequence": seq, "event": {
        "event_type": etype, "entity": "/callcontrol/%s/participants/%s" % (dn, pid),
        "attached_data": None}}


class World:
    """What 3CX would answer to a GET of a participant right now."""

    def __init__(self):
        self.p = {}

    # upsert()/remove() return a STEP, not a message: the change to this world
    # happens at the moment Rig.feed() reaches it. Building several in one call
    # (feed(upsert, upsert, remove)) must behave like the same events arriving
    # one after another -- if the world changed while the arguments were being
    # built, every fetch would read back the FINAL state and the test would
    # prove nothing.
    def upsert(self, dn, p):
        def step():
            self.p[(dn, p["id"])] = p
            return envelope(0, dn, p["id"])
        return step

    def remove(self, dn, pid):
        def step():
            self.p.pop((dn, pid), None)
            return envelope(1, dn, pid)
        return step

    def fetch(self, entity):
        m = re.match(r"^/callcontrol/([^/]+)/participants/(\d+)$", entity)
        return self.p.get((m.group(1), int(m.group(2)))) if m else None

    def snapshot(self, dns):
        out = []
        for dn in dns:
            out.append({"dn": dn, "type": "Wextension", "devices": [],
                        "participants": [p for (d, _), p in self.p.items() if d == dn]})
        return out


class Rig:
    """A tracker plus its world and clock, with helpers."""

    def __init__(self, **kw):
        self.clock = FakeClock()
        kw.setdefault("trunk_did_map", {"10001": "+16125550100"})
        self.tracker = ThreeCxTracker(clock=self.clock, **kw)
        self.world = World()

    def feed(self, *messages):
        out = []
        for m in messages:
            msg = m() if callable(m) else m
            out.extend(self.tracker.handle_message(msg, fetch=self.world.fetch))
        return out

    def settle(self, seconds=3):
        self.clock.advance(seconds)
        return self.tracker.tick()


def names(events):
    return [e["event"] for e in events]


class RingGroupTest(unittest.TestCase):
    def ring_three(self, r, callid=4711):
        return r.feed(
            r.world.upsert("101", participant(301, "101", "Ringing", callid=callid, legid=2)),
            r.world.upsert("102", participant(302, "102", "Ringing", callid=callid, legid=3)),
            r.world.upsert("103", participant(303, "103", "Ringing", callid=callid, legid=4)))

    def test_three_phones_ringing_is_one_ring_with_the_caller_and_the_mapped_number(self):
        r = Rig()
        out = self.ring_three(r)
        self.assertEqual(names(out), ["ringing"])
        ev = out[0]
        self.assertEqual(ev["caller_number"], "+16125551234")
        self.assertEqual(ev["caller_name"], "MINNEAPOLIS MN")
        self.assertEqual(ev["called_number"], "+16125550100", "3CX sends no DID on V20: the trunk map fills it")
        self.assertTrue(ev["call_id"].startswith("3cx:4711:"))

    def test_one_answers_the_others_are_cancelled_and_the_hangup_ends_it_once(self):
        r = Rig()
        self.ring_three(r)
        out = r.feed(r.world.upsert("102", participant(302, "102", "Connected", legid=3)))
        self.assertEqual(names(out), ["claimed_externally"])
        self.assertEqual(out[0]["answered_by_dn"], "102")
        self.assertEqual(r.feed(r.world.remove("101", 301), r.world.remove("103", 303)), [])
        self.assertEqual(r.settle(), [], "102 is still on the call: nothing terminal yet")
        r.feed(r.world.remove("102", 302))
        self.assertEqual(r.feed(), [])
        self.assertEqual(names(r.settle()), ["ended"])
        self.assertEqual(r.settle(), [], "terminal is one-shot")

    def test_nobody_answers_is_one_abandoned_only_after_the_grace_period(self):
        r = Rig()
        self.ring_three(r)
        r.feed(r.world.remove("101", 301), r.world.remove("102", 302), r.world.remove("103", 303))
        self.assertEqual(r.settle(1), [], "inside the grace window nothing is decided")
        self.assertEqual(names(r.settle(2)), ["abandoned"])

    def test_sequential_hunt_removes_spread_over_time_still_give_one_abandoned(self):
        r = Rig()
        self.ring_three(r)
        r.feed(r.world.remove("101", 301))
        r.clock.advance(5)
        r.feed(r.world.remove("102", 302))
        r.clock.advance(5)
        self.assertEqual(r.tracker.tick(), [], "103 is still ringing")
        r.feed(r.world.remove("103", 303))
        self.assertEqual(names(r.settle()), ["abandoned"])

    def test_a_queue_next_wave_refilling_inside_the_grace_window_is_not_a_missed_call(self):
        r = Rig()
        self.ring_three(r)
        r.feed(r.world.remove("101", 301), r.world.remove("102", 302), r.world.remove("103", 303))
        r.clock.advance(1)                      # inside the 2s grace
        r.feed(r.world.upsert("104", participant(304, "104", "Ringing", legid=5)))
        self.assertEqual(r.settle(5), [], "refilled in time: still one live call")
        r.feed(r.world.upsert("104", participant(304, "104", "Connected", legid=5)))
        r.feed(r.world.remove("104", 304))
        self.assertEqual(names(r.settle()), ["ended"])

    def test_two_separate_calls_do_not_interfere(self):
        r = Rig()
        a = r.feed(r.world.upsert("101", participant(301, "101", "Ringing", callid=1)))
        b = r.feed(r.world.upsert("102", participant(302, "102", "Ringing", callid=2, caller="+16125559999")))
        r.feed(r.world.remove("101", 301))
        out = r.settle()
        self.assertEqual(names(a + b + out), ["ringing", "ringing", "abandoned"])
        self.assertEqual(out[0]["call_id"], a[0]["call_id"])
        self.assertNotEqual(a[0]["call_id"], b[0]["call_id"])


class TransferTest(unittest.TestCase):
    def test_transfer_with_the_same_callid_is_one_call_one_end(self):
        r = Rig()
        out = r.feed(r.world.upsert("101", participant(331, "101", "Ringing", callid=4714)))
        out += r.feed(r.world.upsert("101", participant(331, "101", "Connected", callid=4714)))
        out += r.feed(r.world.upsert("103", participant(332, "103", "Ringing", callid=4714, legid=3)),
                      r.world.remove("101", 331))
        out += r.settle(1)
        out += r.feed(r.world.upsert("103", participant(332, "103", "Connected", callid=4714, legid=3)))
        out += r.feed(r.world.remove("103", 332))
        out += r.settle()
        self.assertEqual(names(out), ["ringing", "claimed_externally", "ended"])


class WhatIsNotAnInboundCallTest(unittest.TestCase):
    def test_a_call_dialled_out_from_a_monitored_extension_is_ignored(self):
        r = Rig()
        p = participant(7, "101", "Dialing", originated_by_dn="101", party_dn="10001")
        out = r.feed(r.world.upsert("101", p),
                     r.world.upsert("101", dict(p, status="Connected")),
                     r.world.remove("101", 7))
        self.assertEqual(out + r.settle(), [])

    def test_an_outbound_leg_that_reports_ringing_is_still_ignored(self):
        # The originating participant may read "Ringing" while the far end rings.
        # Only the originated-by-this-DN rule stops that popping an inbound banner.
        r = Rig()
        p = participant(7, "101", "Ringing", originated_by_dn="101")
        self.assertEqual(r.feed(r.world.upsert("101", p), r.world.remove("101", 7)) + r.settle(), [])

    def test_extension_to_extension_is_ignored_by_default(self):
        r = Rig()
        p = participant(7, "101", "Ringing", ptype="Wextension", party_dn="102", originated_by_dn="102")
        self.assertEqual(r.feed(r.world.upsert("101", p)), [])

    def test_include_internal_reports_them(self):
        r = Rig(include_internal=True)
        p = participant(7, "101", "Ringing", ptype="Wextension", party_dn="102", caller="102")
        self.assertEqual(names(r.feed(r.world.upsert("101", p))), ["ringing"])

    def test_monitor_dns_filter_ignores_other_extensions(self):
        r = Rig(monitor_dns=["800"])
        self.assertEqual(r.feed(r.world.upsert("101", participant(7, "101", "Ringing"))), [])
        self.assertEqual(names(r.feed(r.world.upsert("800", participant(9, "800", "Ringing", callid=50)))), ["ringing"])


class EventHandlingTest(unittest.TestCase):
    def test_duplicate_upserts_ring_once(self):
        r = Rig()
        step = r.world.upsert("101", participant(7, "101", "Ringing"))
        self.assertEqual(names(r.feed(step, step, step)), ["ringing"])

    def test_a_participant_that_404s_on_fetch_is_treated_as_removed(self):
        r = Rig()
        r.feed(r.world.upsert("101", participant(7, "101", "Ringing")))
        r.world.p.pop(("101", 7))                      # gone before we could GET it
        r.feed(envelope(0, "101", 7))
        self.assertEqual(names(r.settle()), ["abandoned"])

    def test_without_a_fetch_function_a_null_upsert_is_ignored_not_fatal(self):
        t = ThreeCxTracker(clock=FakeClock())
        self.assertEqual(t.handle_message(envelope(0, "101", 7)), [])

    def test_attached_data_is_used_directly_when_a_build_does_send_it(self):
        t = ThreeCxTracker(clock=FakeClock())
        msg = envelope(0, "101", 7)
        msg["event"]["attached_data"] = participant(7, "101", "Ringing")
        self.assertEqual(names(t.handle_message(msg)), ["ringing"])

    def test_unknown_status_is_not_a_call_event_and_is_logged_exactly_once(self):
        class Log:
            def __init__(self):
                self.lines = []

            def warning(self, fmt, *a):
                self.lines.append(fmt % a)

        log = Log()
        r = Rig(log=log)
        r.feed(r.world.upsert("101", participant(7, "101", "Ringing")))
        # Alternate between two unknown statuses so each Upsert is a genuine change
        # (an identical repeat is dropped as a duplicate before the warning check).
        for status in ("Busy", "Failed", "Busy", "Failed", "Busy"):
            self.assertEqual(r.feed(r.world.upsert("101", participant(7, "101", status))), [])
        self.assertEqual(sum("'busy'" in line for line in log.lines), 1)
        self.assertEqual(sum("'failed'" in line for line in log.lines), 1)

    def test_pascal_case_envelope_and_string_event_types_are_understood(self):
        r = Rig()
        r.world.p[("101", 7)] = participant(7, "101", "Ringing")
        msg = {"Sequence": 1, "Event": {"EventType": "Upsert", "Entity": "/callcontrol/101/participants/7",
                                         "AttachedData": None}}
        self.assertEqual(names(r.feed(msg)), ["ringing"])
        rm = {"Event": {"EventType": "Remove", "Entity": "/callcontrol/101/participants/7", "AttachedData": None}}
        r.feed(rm)
        self.assertEqual(names(r.settle()), ["abandoned"])

    def test_junk_and_unrelated_events_are_ignored(self):
        r = Rig()
        junk = [None, "text", 5, {}, {"event": "nope"},
                {"event": {"event_type": 4, "entity": "/x", "attached_data": {"RequestID": "1"}}},
                {"event": {"event_type": 2, "entity": "/callcontrol/101/participants/7", "attached_data": "1"}},
                {"event": {"event_type": 3, "entity": "/callcontrol/101/participants/7"}},
                {"event": {"event_type": 0, "entity": "/callcontrol/101", "attached_data": 12}},
                {"event": {"event_type": 0, "entity": "/callcontrol/101/participants/x", "attached_data": {}}}]
        self.assertEqual(r.feed(*junk), [])

    def test_remove_for_a_participant_never_tracked_is_ignored(self):
        r = Rig()
        self.assertEqual(r.feed(envelope(1, "101", 99)) + r.settle(), [])


class CallerAndNumberFieldsTest(unittest.TestCase):
    def test_number_only_in_the_name_field_is_used_as_the_number(self):
        r = Rig()
        out = r.feed(r.world.upsert("101", participant(7, "101", "Ringing", caller="", name="6125551234")))
        self.assertEqual(out[0]["caller_number"], "6125551234")
        self.assertIsNone(out[0]["caller_name"], "a number in the name slot is not a name")

    def test_anonymous_caller_is_shown_by_name_with_no_number(self):
        r = Rig()
        out = r.feed(r.world.upsert("101", participant(7, "101", "Ringing", caller="Anonymous", name="")))
        self.assertIsNone(out[0]["caller_number"])
        self.assertEqual(out[0]["caller_name"], "Anonymous")

    def test_called_number_prefers_a_real_did_then_the_map_then_the_default_then_none(self):
        r = Rig(trunk_did_map={"10001": "MAPPED"}, default_called_number="DEFAULT")
        out = r.feed(r.world.upsert("101", participant(1, "101", "Ringing", callid=1, did="REALDID")))
        self.assertEqual(out[0]["called_number"], "REALDID")
        out = r.feed(r.world.upsert("101", participant(2, "101", "Ringing", callid=2)))
        self.assertEqual(out[0]["called_number"], "MAPPED")
        out = r.feed(r.world.upsert("101", participant(3, "101", "Ringing", callid=3, party_dn="99999")))
        self.assertEqual(out[0]["called_number"], "DEFAULT")
        r2 = Rig(trunk_did_map={})
        out = r2.feed(r2.world.upsert("101", participant(4, "101", "Ringing", callid=4)))
        self.assertIsNone(out[0]["called_number"])

    def test_parse_did_map(self):
        self.assertEqual(parse_did_map("10001=+16125550100, 10002 = +16125550111"),
                         {"10001": "+16125550100", "10002": "+16125550111"})
        self.assertEqual(parse_did_map(""), {})
        self.assertEqual(parse_did_map("garbage"), {})

    def test_event_keys_match_the_ingest_contract(self):
        r = Rig()
        ring = r.feed(r.world.upsert("101", participant(7, "101", "Ringing")))[0]
        self.assertEqual(set(ring), {"event", "call_id", "caller_number", "caller_name", "called_number", "event_ts"})
        claim = r.feed(r.world.upsert("101", participant(7, "101", "Connected")))[0]
        self.assertEqual(set(claim) - set(ring), {"answered_by_dn"})

    def test_event_ts_never_goes_backwards_within_a_call(self):
        r = Rig()
        ring = r.feed(r.world.upsert("101", participant(7, "101", "Ringing")))[0]
        r.clock.advance(-120)                           # the machine's clock jumps back
        claim = r.feed(r.world.upsert("101", participant(7, "101", "Connected")))[0]
        self.assertGreaterEqual(claim["event_ts"], ring["event_ts"])


class ReconnectAndSafetyNetTest(unittest.TestCase):
    def test_snapshot_priming_announces_a_call_already_ringing(self):
        r = Rig()
        r.world.p[("101", 7)] = participant(7, "101", "Ringing")
        out = r.tracker.apply_snapshot(r.world.snapshot(["101", "102"]))
        self.assertEqual(names(out), ["ringing"])

    def test_snapshot_priming_never_invents_a_ring_for_a_call_already_answered(self):
        r = Rig()
        r.world.p[("101", 7)] = participant(7, "101", "Connected")
        self.assertEqual(r.tracker.apply_snapshot(r.world.snapshot(["101"])), [])
        r.feed(r.world.remove("101", 7))
        self.assertEqual(r.settle(), [], "TicketsCAD never saw it ring, so it is told nothing about its end")

    def test_a_transfer_out_of_a_call_first_seen_mid_conversation_does_not_ring_afterwards(self):
        # TicketsCAD never saw this call ring (the bridge joined mid-call). When it is
        # then transferred and the destination rings, that is a continuation of the
        # same call, not a fresh inbound one -- announcing it would pop a banner for a
        # call a human is already handling.
        r = Rig()
        r.world.p[("101", 7)] = participant(7, "101", "Connected", callid=4720)
        r.tracker.apply_snapshot(r.world.snapshot(["101"]))
        out = r.feed(r.world.upsert("103", participant(8, "103", "Ringing", callid=4720, legid=3)))
        self.assertEqual(out, [])
        r.feed(r.world.remove("101", 7), r.world.remove("103", 8))
        self.assertEqual(r.settle(), [])

    def test_a_hangup_missed_during_a_disconnect_is_recovered_by_the_next_snapshot(self):
        r = Rig()
        r.feed(r.world.upsert("101", participant(341, "101", "Ringing", callid=4716)))
        r.world.p.clear()                               # the caller hung up while the socket was down
        self.assertEqual(r.tracker.apply_snapshot(r.world.snapshot(["101", "102"])), [])
        self.assertEqual(names(r.settle()), ["abandoned"])

    def test_the_route_point_of_the_api_client_is_ignored_in_a_snapshot(self):
        r = Rig()
        snap = [{"dn": "800", "type": "Wroutepoint", "participants": [participant(5, "800", "Ringing")]}]
        self.assertEqual(r.tracker.apply_snapshot(snap), [])

    def test_snapshot_does_not_disturb_calls_that_are_still_there(self):
        r = Rig()
        r.feed(r.world.upsert("101", participant(7, "101", "Ringing")))
        self.assertEqual(r.tracker.apply_snapshot(r.world.snapshot(["101"])), [])
        self.assertEqual(r.settle(), [])

    def test_a_remove_that_never_arrives_does_not_ring_forever(self):
        r = Rig(ringing_max_seconds=180)
        r.feed(r.world.upsert("101", participant(7, "101", "Ringing")))
        self.assertEqual(r.settle(170), [])
        self.assertEqual(names(r.settle(20)), ["abandoned"])
        self.assertEqual(r.settle(500), [], "one-shot")

    def test_an_answered_call_with_no_hangup_report_closes_after_the_longer_limit(self):
        r = Rig(stale_connected_seconds=3600)
        # Sequential on purpose: World.fetch answers with the participant's CURRENT
        # state, so two upserts of one participant built in a single call would
        # both read back "Connected" (and the ring would never be seen).
        r.feed(r.world.upsert("101", participant(7, "101", "Ringing")))
        r.feed(r.world.upsert("101", participant(7, "101", "Connected")))
        self.assertEqual(r.settle(3500), [])
        self.assertEqual(names(r.settle(200)), ["ended"])

    def test_open_call_count(self):
        r = Rig()
        r.feed(r.world.upsert("101", participant(7, "101", "Ringing")))
        self.assertEqual(r.tracker.open_call_count(), 1)
        r.feed(r.world.remove("101", 7))
        r.settle()
        self.assertEqual(r.tracker.open_call_count(), 0)


if __name__ == "__main__":
    unittest.main(verbosity=2)
