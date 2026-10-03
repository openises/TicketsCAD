/**
 * GH#141 (Phase 155) -- the "committed for later" chip.
 *
 * A unit committed to a Scheduled incident (a reservation, or -- when the install
 * dispatches immediately -- an assignment to a call whose booked time is still
 * ahead) must not read as plain "Dispatched/Available" with nothing to say WHICH
 * call or WHEN: a dispatcher hunting for a unit for a right-now emergency needs
 * to see "free, but spoken for at 18:00". This renders that chip from the
 * `future` array api/responders.php and api/responder-detail.php return (built
 * by unit_future_commitments(); one batched read for a whole list).
 *
 * One definition, used by the unit page, the dashboard units widget and the
 * incident page's available-units list, so they cannot drift apart.
 *
 * Output is built from escaped strings only; the colour is a Bootstrap theme
 * class (text-bg-*) so it works in light and dark. `title` and `aria-label`
 * carry the full sentence for hover and screen readers.
 */
(function () {
    'use strict';

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // "2026-10-04 18:00:00" -> "10-04 18:00"
    function shortWhen(t) {
        var s = String(t || '');
        return s.length >= 16 ? s.substring(5, 16) : s;
    }

    // One commitment -> {cls, label, title}
    function describe(c) {
        var num = c.incident_number || ('#' + c.ticket_id);
        var when = shortWhen(c.booked_date);
        var full;
        var cls = 'text-bg-info';
        var lead = '';
        if (c.kind === 'reserved') {
            if (c.held) {
                cls = 'text-bg-danger';
                lead = 'HELD ';
                full = 'Reservation for incident ' + num + ' is held for a dispatcher (the unit was busy at the booked time) - booked ' + c.booked_date;
            } else if (c.due) {
                cls = 'text-bg-warning';
                lead = 'DUE ';
                full = 'Reservation for incident ' + num + ' is DUE but the unit has not been dispatched yet - booked ' + c.booked_date;
            } else {
                full = 'Reserved for incident ' + num + ' - booked ' + c.booked_date + '. Still available until then.';
            }
        } else {
            full = 'Already dispatched to Scheduled incident ' + num + ' - booked ' + c.booked_date;
        }
        return { cls: cls, label: lead + num + (when ? ' ' + when : ''), title: full };
    }

    /**
     * @param {Array} future  the unit's `future` array (may be undefined/empty)
     * @returns {string} HTML for the chip(s), or '' when there is nothing
     */
    function chipHtml(future) {
        if (!future || !future.length) return '';
        var first = describe(future[0]);
        var more = future.length > 1 ? ' +' + (future.length - 1) : '';
        var titles = [];
        for (var i = 0; i < future.length; i++) titles.push(describe(future[i]).title);
        var full = titles.join('; ');
        var href = 'incident-detail.php?id=' + parseInt(future[0].ticket_id, 10);
        return '<a class="badge ' + first.cls + ' text-decoration-none future-chip" href="' + esc(href) + '"' +
            ' title="' + esc(full) + '" aria-label="' + esc(full) + '">' +
            '<i class="bi bi-clock me-1"></i>' + esc(first.label + more) + '</a>';
    }

    window.TCADFutureChip = { html: chipHtml, describe: describe };
})();
