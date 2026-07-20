/**
 * TCADStates — shared State / Province dropdown helper  (GitHub issue #42)
 *
 * a beta tester (Canada) asked for the State field on the member, constituent,
 * unit, vehicle and warn-location forms to be a database-backed dropdown
 * like the facility form, instead of a free-text box — so his provinces
 * (any row he adds to `states_translator`) appear everywhere.
 *
 * This module fills a <select> from the live states_translator list
 * (served by api/incident-types.php as { states: [{code,name}, ...] }),
 * fetching once per page and caching the promise. It is deliberately
 * tolerant of legacy data: any previously-saved value that is NOT in the
 * list (e.g. a hand-typed "Minnesota" from before the dropdown existed)
 * is injected as its own option so nothing is lost or blanked on load.
 *
 * Usage:
 *   TCADStates.fill(selectEl);                 // populate + keep current value
 *   TCADStates.setValue(selectEl, 'MB');       // select a value later (after a
 *                                              // record loads async); injects
 *                                              // the option if it is missing.
 *
 * ES5 only (var, function expressions) — matches the NewUI JS convention.
 */
(function () {
    'use strict';

    var FALLBACK_US = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA',
        'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD',
        'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
        'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC',
        'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY', 'DC'
    ];

    var _promise = null;   // cached promise -> normalized list of {code,name}

    function _normalize(list) {
        var out = [];
        var i;
        for (i = 0; i < list.length; i++) {
            var st = list[i];
            if (typeof st === 'string') {
                out.push({ code: st, name: '' });
            } else if (st && st.code) {
                out.push({ code: st.code, name: st.name || '' });
            }
        }
        return out;
    }

    /**
     * Load (once) the states list. Resolves to an array of {code,name}.
     * Falls back to the US list if the API is unreachable or empty, so a
     * broken fetch never leaves an empty dropdown.
     */
    function load() {
        if (_promise) {
            return _promise;
        }
        _promise = fetch('api/incident-types.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (data && Array.isArray(data.states) && data.states.length) {
                    return _normalize(data.states);
                }
                return _normalize(FALLBACK_US);
            })
            .catch(function () { return _normalize(FALLBACK_US); });
        return _promise;
    }

    function _optionText(st) {
        return st.name ? (st.code + ' — ' + st.name) : st.code;
    }

    /**
     * Ensure `sel` has an <option> whose value === val, then select it.
     * Injects a bare option when the value isn't in the list (legacy data).
     * A blank value simply clears the selection.
     */
    function setValue(sel, val) {
        if (!sel) {
            return;
        }
        val = (val === null || val === undefined) ? '' : ('' + val);
        if (val !== '') {
            var found = false;
            var i;
            for (i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value === val) {
                    found = true;
                    break;
                }
            }
            if (!found) {
                var opt = document.createElement('option');
                opt.value = val;
                opt.textContent = val;
                sel.appendChild(opt);
            }
        }
        sel.value = val;
    }

    /**
     * Populate a <select> with the states list. Preserves whatever value
     * the element currently holds (so it is safe to call before OR after a
     * record's value has been assigned). Options may be provided:
     *   opts.placeholder   text for the leading blank option (default '—');
     *                      pass false to omit the blank option entirely.
     * Returns a promise that resolves once the options are in place.
     */
    function fill(sel, opts) {
        opts = opts || {};
        if (!sel) {
            return Promise.resolve();
        }
        var current = sel.value || sel.getAttribute('data-selected') || '';
        return load().then(function (list) {
            // Rebuild from scratch so repeat calls don't duplicate options.
            sel.innerHTML = '';
            if (opts.placeholder !== false) {
                var blank = document.createElement('option');
                blank.value = '';
                blank.textContent = opts.placeholder || '—';
                sel.appendChild(blank);
            }
            var i;
            for (i = 0; i < list.length; i++) {
                var o = document.createElement('option');
                o.value = list[i].code;
                o.textContent = _optionText(list[i]);
                sel.appendChild(o);
            }
            if (current) {
                setValue(sel, current);
            }
        });
    }

    window.TCADStates = {
        load: load,
        fill: fill,
        setValue: setValue
    };
})();
