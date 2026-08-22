/**
 * Phase 149 (2026-08-22) — New Incident pre-fill for an answered inbound
 * call (spec.md FR-20/FR-21, plan.md §7).
 *
 * Modeled DELIBERATELY line-for-line on the existing assets/js/
 * net-prefill.js (`?net_entry=N`) rather than inventing a second prefill
 * mechanism — same shape, `?call_id=N` in place of `?net_entry=N`:
 *
 *   new-incident.php?call_id=N
 *     -> fetch api/inbound-calls.php?action=detail&id=N
 *        (field.caller_history/field.patient_history enforced downstream,
 *         at api/constituents.php / api/call-history.php — see plan.md §5)
 *     -> write caller_number into #phone
 *     -> phone.dispatchEvent('blur') — re-triggers the EXISTING
 *        constituent lookup, Caller Info panel, and Call History section
 *        in assets/js/new-incident.js UNMODIFIED
 *
 * Deliberately does NOT touch the incident type — the existing
 * description-blur regex auto-detect owns that (net-prefill.js's own
 * stated restraint, reused verbatim here).
 *
 * ES5 only (project rule).
 */
(function () {
    'use strict';

    function param(name) {
        try {
            return new URLSearchParams(window.location.search).get(name);
        } catch (e) {
            var m = new RegExp('[?&]' + name + '=([^&]*)').exec(window.location.search);
            return m ? decodeURIComponent(m[1]) : null;
        }
    }

    function init() {
        var callId = param('call_id');
        if (!callId) return;

        fetch('api/inbound-calls.php?action=detail&id=' + encodeURIComponent(callId),
              { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error || !data.call) return;

                // Remember which call this page is working, so the
                // create/save hook can mark it linked once the work is real.
                window.CALL_PREFILL_ID = parseInt(callId, 10);

                var phone = document.getElementById('phone');
                if (!phone) return;

                phone.value = data.call.caller_number || '';

                // Let the existing constituent lookup / Call History panel
                // resolve the number exactly as if it had been typed by
                // hand (see the reference-lookup fix in new-incident.js).
                if (phone.value && typeof Event === 'function') {
                    try { phone.dispatchEvent(new Event('blur')); } catch (e) {}
                }
            })
            .catch(function () { /* the dispatcher can still type it by hand */ });
    }

    /**
     * Called by new-incident.js once the incident actually exists.
     * Marking "linked" here rather than at keypress time means an
     * abandoned tab (closed without saving) leaves the call correctly
     * recorded as still needing follow-up, not silently "handled"
     * (spec.md FR-22).
     */
    window.CallPrefill = {
        markHandled: function (ticketId) {
            if (!window.CALL_PREFILL_ID) return;
            var callId = window.CALL_PREFILL_ID;
            window.CALL_PREFILL_ID = null; // once only, even if a handler re-fires

            var meta = document.querySelector('meta[name="csrf-token"]');
            var tokenEl = document.getElementById('csrfToken');
            var token = (tokenEl && tokenEl.value)
                ? tokenEl.value
                : (meta ? meta.getAttribute('content') : (window.CALL_ALERT_CSRF || window.CSRF_TOKEN || ''));

            fetch('api/inbound-calls.php?action=link_ticket', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: callId,
                    ticket_id: ticketId ? parseInt(ticketId, 10) : 0,
                    csrf_token: token
                })
            }).catch(function () { /* best effort — never block the dispatcher */ });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
