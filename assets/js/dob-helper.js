/**
 * DOB helper — parse, normalize, format, and age-compute date-of-birth
 * strings with partial-date tolerance.
 *
 * Phase 2026-06-28 (Eric beta): patient DOB field needs to handle
 * cases where the dispatcher only knows part of the DOB (year only,
 * or month + year). The patient.dob column is varchar(32) so we can
 * store anything; we standardize on an ISO-ish format with '00' for
 * unknown month/day:
 *
 *   1970-10-12  (full DOB)
 *   1964-12-00  (month + year, day unknown)
 *   1964-00-00  (year only)
 *   ''          (nothing entered)
 *
 * Display format follows the install's date_format setting where
 * known, defaulting to MM/DD/YYYY:
 *   "10/12/1970 (55)"   full
 *   "12/1964 (~60)"     month + year (age estimated from June of year)
 *   "1964 (~60)"        year only (age estimated from June of year)
 *
 * Usage:
 *   var iso = DobHelper.parse('101270');         // '1970-10-12'
 *   var iso = DobHelper.parse('12/1964');        // '1964-12-00'
 *   var iso = DobHelper.parse('1964');           // '1964-00-00'
 *   var iso = DobHelper.parse('Dec 1964');       // '1964-12-00'
 *   var disp = DobHelper.format('1964-12-00');   // '12/1964 (~60)'
 *   DobHelper.bindInput(inputEl, badgeEl);       // attach blur handler
 */
(function () {
    'use strict';

    var MONTHS = {
        jan: 1, january: 1, feb: 2, february: 2, mar: 3, march: 3,
        apr: 4, april: 4, may: 5, jun: 6, june: 6, jul: 7, july: 7,
        aug: 8, august: 8, sep: 9, sept: 9, september: 9, oct: 10, october: 10,
        nov: 11, november: 11, dec: 12, december: 12
    };

    /**
     * Infer the century for a 2-digit year. Cutoff is (current year - 100):
     * anything <= currentYY (within the last 100 years) is 20YY, anything
     * higher is 19YY.
     *
     * Example in 2026: cutoff=26, so:
     *   '70 → 1970, '95 → 1995, '24 → 2024, '27 → 1927 (probably wrong but
     *   safer than 2027 for DOB)
     */
    function inferCentury(yy) {
        var nowYY = (new Date()).getFullYear() % 100;
        return (yy <= nowYY) ? (2000 + yy) : (1900 + yy);
    }

    /**
     * Parse a free-form DOB string into ISO-ish 'YYYY-MM-DD' with
     * '00' for unknown components. Returns '' for unparseable input
     * (so the caller can fall back to displaying raw).
     */
    function parse(input) {
        if (input === null || input === undefined) return '';
        var s = String(input).trim();
        if (s === '') return '';

        // Already canonical ISO? Just validate + pass through.
        var iso = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (iso) {
            var y = parseInt(iso[1], 10);
            var m = parseInt(iso[2], 10);
            var d = parseInt(iso[3], 10);
            if (y >= 1900 && y <= 2100 && m >= 0 && m <= 12 && d >= 0 && d <= 31) {
                return _pad(y, 4) + '-' + _pad(m, 2) + '-' + _pad(d, 2);
            }
            return '';
        }

        // Strip out commas; collapse whitespace
        s = s.replace(/,/g, ' ').replace(/\s+/g, ' ').trim();

        // "Dec 15, 1964" / "December 1964" / "Dec 1964"
        // Try month-name patterns first since they're unambiguous.
        var mn = s.match(/^([A-Za-z]+)\s+(\d{1,2})\s+(\d{2,4})$/);
        if (mn) {
            var mo = MONTHS[mn[1].toLowerCase()];
            if (mo) {
                var day = parseInt(mn[2], 10);
                var yr = parseInt(mn[3], 10);
                if (yr < 100) yr = inferCentury(yr);
                if (day >= 1 && day <= 31 && yr >= 1900 && yr <= 2100) {
                    return _pad(yr, 4) + '-' + _pad(mo, 2) + '-' + _pad(day, 2);
                }
            }
        }
        var mny = s.match(/^([A-Za-z]+)\s+(\d{2,4})$/);
        if (mny) {
            var moOnly = MONTHS[mny[1].toLowerCase()];
            if (moOnly) {
                var yrOnly = parseInt(mny[2], 10);
                if (yrOnly < 100) yrOnly = inferCentury(yrOnly);
                if (yrOnly >= 1900 && yrOnly <= 2100) {
                    return _pad(yrOnly, 4) + '-' + _pad(moOnly, 2) + '-00';
                }
            }
        }

        // Numeric-only forms.
        // Strip all non-digits-and-separators, then case-split.
        var digits = s.replace(/[^\d\/\-\.]/g, '');

        // YYYY-MM-DD / YYYY/MM/DD / YYYY.MM.DD
        var ymd = digits.match(/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})$/);
        if (ymd) {
            return _pad(+ymd[1], 4) + '-' + _pad(+ymd[2], 2) + '-' + _pad(+ymd[3], 2);
        }
        // YYYY-MM / YYYY/MM
        var ym = digits.match(/^(\d{4})[\/\-\.](\d{1,2})$/);
        if (ym) {
            return _pad(+ym[1], 4) + '-' + _pad(+ym[2], 2) + '-00';
        }
        // MM/DD/YYYY / MM-DD-YYYY
        var mdy = digits.match(/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})$/);
        if (mdy) {
            return _pad(+mdy[3], 4) + '-' + _pad(+mdy[1], 2) + '-' + _pad(+mdy[2], 2);
        }
        // MM/DD/YY / MM-DD-YY
        var mdy2 = digits.match(/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2})$/);
        if (mdy2) {
            var yShort = inferCentury(+mdy2[3]);
            return _pad(yShort, 4) + '-' + _pad(+mdy2[1], 2) + '-' + _pad(+mdy2[2], 2);
        }
        // MM/YYYY / MM-YYYY
        var my = digits.match(/^(\d{1,2})[\/\-\.](\d{4})$/);
        if (my) {
            return _pad(+my[2], 4) + '-' + _pad(+my[1], 2) + '-00';
        }
        // MM/YY
        var my2 = digits.match(/^(\d{1,2})[\/\-\.](\d{2})$/);
        if (my2) {
            var y2 = inferCentury(+my2[2]);
            return _pad(y2, 4) + '-' + _pad(+my2[1], 2) + '-00';
        }
        // Pure-digit strings — guess by length.
        var pure = digits.replace(/[\/\-\.]/g, '');
        if (/^\d+$/.test(pure)) {
            if (pure.length === 4) {
                // YYYY only
                var y4 = +pure;
                if (y4 >= 1900 && y4 <= 2100) return _pad(y4, 4) + '-00-00';
            } else if (pure.length === 6) {
                // MMDDYY (US default)
                var mo6 = +pure.substr(0, 2);
                var d6  = +pure.substr(2, 2);
                var y6  = inferCentury(+pure.substr(4, 2));
                if (mo6 >= 1 && mo6 <= 12 && d6 >= 1 && d6 <= 31) {
                    return _pad(y6, 4) + '-' + _pad(mo6, 2) + '-' + _pad(d6, 2);
                }
            } else if (pure.length === 8) {
                // MMDDYYYY (US default) — try first
                var mo8 = +pure.substr(0, 2);
                var d8  = +pure.substr(2, 2);
                var y8  = +pure.substr(4, 4);
                if (mo8 >= 1 && mo8 <= 12 && d8 >= 1 && d8 <= 31 && y8 >= 1900 && y8 <= 2100) {
                    return _pad(y8, 4) + '-' + _pad(mo8, 2) + '-' + _pad(d8, 2);
                }
                // Fallback: YYYYMMDD
                var y8b = +pure.substr(0, 4);
                var m8b = +pure.substr(4, 2);
                var d8b = +pure.substr(6, 2);
                if (y8b >= 1900 && y8b <= 2100 && m8b >= 1 && m8b <= 12 && d8b >= 1 && d8b <= 31) {
                    return _pad(y8b, 4) + '-' + _pad(m8b, 2) + '-' + _pad(d8b, 2);
                }
            } else if (pure.length === 2) {
                // YY only — interpret as year. Useful for "I know they're 65"
                // → '60 → 1960, but the more common case is "DOB 70" meaning
                // 1970. Year-only is the safer guess.
                var y2only = inferCentury(+pure);
                return _pad(y2only, 4) + '-00-00';
            }
        }

        // Unparseable — return empty so caller knows to leave raw value.
        return '';
    }

    /**
     * Format an ISO-ish DOB for display. Includes an age tag when
     * possible. If the input doesn't match the canonical pattern,
     * returns it as-is so old free-text rows still render.
     */
    function format(iso, opts) {
        opts = opts || {};
        if (!iso) return '';
        var m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) return iso;
        var y = +m[1], mo = +m[2], d = +m[3];
        if (y < 1900 || y > 2100) return iso;

        var datePart;
        var ageEstimated = false;
        if (mo === 0 && d === 0) {
            datePart = String(y);
            ageEstimated = true;
        } else if (d === 0) {
            datePart = _pad(mo, 2) + '/' + y;
            ageEstimated = true;
        } else {
            datePart = _pad(mo, 2) + '/' + _pad(d, 2) + '/' + y;
        }

        if (opts.noAge) return datePart;
        var age = computeAge(iso);
        if (age === null) return datePart;
        var prefix = ageEstimated ? '~' : '';
        return datePart + ' (' + prefix + age + ')';
    }

    /**
     * Compute integer age in years from an ISO-ish DOB. Uses June 15
     * as the "midpoint" stand-in when month or day is missing — gets
     * within a year either way which is what dispatchers care about.
     * Returns null for unparseable / future / impossibly-old DOBs.
     */
    function computeAge(iso) {
        if (!iso) return null;
        var m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) return null;
        var y = +m[1], mo = +m[2], d = +m[3];
        if (y < 1900 || y > 2100) return null;
        if (mo === 0) mo = 6;
        if (d === 0)  d = 15;
        var now = new Date();
        var age = now.getFullYear() - y;
        var mNow = now.getMonth() + 1;
        var dNow = now.getDate();
        if (mNow < mo || (mNow === mo && dNow < d)) age--;
        if (age < 0 || age > 130) return null;
        return age;
    }

    /**
     * Wire blur/focus handlers to a DOB input element to give the
     * "type fast, see formatted, see age" experience.
     *
     * @param input    The <input> element.
     * @param ageBadge Optional element to receive the age display
     *                 (typically a small <span> next to the input).
     *                 Pass null to skip the badge.
     */
    function bindInput(input, ageBadge) {
        if (!input) return;
        function refresh() {
            var raw = input.value;
            if (raw === '') {
                input.removeAttribute('data-iso');
                if (ageBadge) { ageBadge.textContent = ''; ageBadge.classList.add('d-none'); }
                return;
            }
            var iso = parse(raw);
            if (!iso) {
                // Couldn't parse — leave the input alone, clear the badge,
                // and mark the field so submit can be aware.
                input.removeAttribute('data-iso');
                if (ageBadge) {
                    ageBadge.textContent = '?';
                    ageBadge.title = 'Could not parse DOB — saved as raw text';
                    ageBadge.classList.remove('d-none');
                }
                return;
            }
            input.value = format(iso, { noAge: true });
            input.setAttribute('data-iso', iso);
            var age = computeAge(iso);
            if (ageBadge) {
                if (age === null) {
                    ageBadge.classList.add('d-none');
                } else {
                    var partial = /-00$/.test(iso) || /-00-/.test(iso);
                    ageBadge.textContent = (partial ? '~' : '') + age + ' yr';
                    ageBadge.title = 'Computed from DOB ' + iso;
                    ageBadge.classList.remove('d-none');
                }
            }
        }
        input.addEventListener('blur', refresh);
        // Initial paint if there's already a value (edit mode).
        if (input.value) refresh();
    }

    /**
     * Read the canonical ISO from an input. Prefers data-iso (set by
     * bindInput on blur), falls back to parsing the raw value.
     */
    function readIso(input) {
        if (!input) return '';
        var iso = input.getAttribute('data-iso');
        if (iso) return iso;
        return parse(input.value);
    }

    function _pad(n, w) {
        var s = String(n);
        while (s.length < w) s = '0' + s;
        return s;
    }

    window.DobHelper = {
        parse: parse,
        format: format,
        computeAge: computeAge,
        bindInput: bindInput,
        readIso: readIso,
    };
})();
