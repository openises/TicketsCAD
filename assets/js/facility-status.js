/**
 * Shared facility-status rendering (GH #49).
 *
 * The single source of truth for turning a facility record from
 * api/facilities.php into a coloured status badge. Every surface that
 * shows facility status MUST use this so the configured
 * `fac_status.bg_color` / `text_color` are honoured identically
 * everywhere — the dashboard widget, the Facilities page, the facility
 * board, the facility detail page, and the situation screen. This exists
 * because the bug recurred four times: each surface had its own render
 * (some plain text, some a hardcoded status→colour map, some reading
 * field names the API never sends).
 *
 * Field names differ by endpoint (a recurring source of this exact bug):
 *   api/facilities.php     → bg_color   / text_color
 *   api/facility-detail.php→ status_bg  / status_text
 * Both alias the same fac_status columns. This helper accepts EITHER, so a
 * field-name mismatch can never silently grey out the badge again. The
 * label (status_name/status_val) is NULL/'' when the facility has no status;
 * we key "has a status" off the LABEL, never the colour, because both
 * endpoints default the colour to '#ffffff' for a statusless facility.
 *
 * ES5 / no modules — attaches window.FacilityStatus.
 */
(function () {
    'use strict';

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = (s === null || s === undefined) ? '' : String(s);
        return d.innerHTML;
    }

    /**
     * Normalize a facility's status into render-ready parts.
     * hasStatus is driven by the LABEL, not the colour — a statusless
     * facility's API bg_color of '#ffffff' must never be mistaken for a
     * configured colour (the exact trap the earlier fixes fell into).
     * @returns {{label:string, bg:string, text:string, hasStatus:boolean}}
     */
    function bits(f) {
        f = f || {};
        var label = f.status_name || f.status_val || '';
        if (!label) {
            return { label: '', bg: '#6c757d', text: '#ffffff', hasStatus: false };
        }
        // Accept either endpoint's field names (bg_color/text_color OR
        // status_bg/status_text) so a mismatch can't grey out the badge.
        var rawBg   = f.bg_color   || f.status_bg;
        var rawText = f.text_color || f.status_text;
        var bg   = (rawBg   && String(rawBg).trim())   ? rawBg   : '#6c757d';
        var text = (rawText && String(rawText).trim()) ? rawText : '#ffffff';
        return { label: label, bg: bg, text: text, hasStatus: true };
    }

    /**
     * A coloured status badge (or a neutral em-dash pill when the facility
     * has no status and opts.emptyDash is set; otherwise '' for no status).
     * @param {object} f     facility record
     * @param {object} [opts] { emptyDash:bool, style:'extra;css', fontSize:'0.62rem' }
     */
    function badge(f, opts) {
        opts = opts || {};
        var b = bits(f);
        if (!b.hasStatus) {
            if (!opts.emptyDash) return '';
            var fs = opts.fontSize ? (';font-size:' + opts.fontSize) : '';
            return '<span class="badge bg-secondary bg-opacity-25 text-body-secondary" style="font-weight:normal' + fs + ';">&mdash;</span>';
        }
        var extra = '';
        if (opts.fontSize) extra += ';font-size:' + opts.fontSize;
        if (opts.style)    extra += ';' + opts.style;
        return '<span class="badge" style="background-color:' + esc(b.bg)
             + ';color:' + esc(b.text) + extra + ';">' + esc(b.label) + '</span>';
    }

    /**
     * CSS-colour whitelist for a value that lands in a style attribute
     * (admin-controlled, but keep the attribute injection-safe). Mirrors
     * the responders-widget safeColor so the two widgets accept the same
     * colour syntax.
     */
    function safeColor(v) {
        v = String(v || '');
        return /^(#[0-9A-Fa-f]{3,8}|rgba?\([\d,.%\s]+\)|hsla?\([\d,.%\s]+\)|[A-Za-z]+)$/.test(v)
            ? v : '';
    }

    /**
     * FILLED-CELL presentation — paints the whole <td> in the configured
     * colour, identical to the dashboard Responders widget's status cell
     * (Phase 104j). Use this in table rows where the widgets should match;
     * use badge() where a pill fits (cards, a single detail badge).
     * @returns {{style:string, label:string}} style is a ' style="…"'
     *          attribute string (empty when unstyled), label is the text.
     */
    function cell(f) {
        var b = bits(f);
        if (!b.hasStatus) return { style: '', label: '' };
        var bg = safeColor(b.bg);
        var fg = safeColor(b.text);
        var style = '';
        if (bg && bg !== 'transparent') {
            style = ' style="background:' + bg + ';'
                  + (fg ? 'color:' + fg + ';' : '')
                  + 'font-weight:600;text-align:center;border-radius:3px;"';
        }
        return { style: style, label: b.label };
    }

    window.FacilityStatus = { bits: bits, badge: badge, cell: cell };
})();
