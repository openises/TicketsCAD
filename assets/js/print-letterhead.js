/**
 * NewUI v4.0 - Print letterhead (GH#142, Phase 155).
 *
 * Builds the agency logo letterhead on printed in-app pages and reports, and
 * stamps data-print-date on every page. Loaded globally by inc/navbar.php.
 *
 * WHY JS AND NOT PAGE TEMPLATES. Every printing page closes its own <header>
 * after including the navbar, so anything the navbar prints is inside
 * #appHeader, which every print stylesheet hides. One script that puts the
 * letterhead first in <body> reaches all twelve pages without editing any of them.
 *
 * WHY AT PAGE LOAD. The element is built on DOMContentLoaded, not lazily inside
 * `beforeprint`: an image first requested inside beforeprint can print blank,
 * because the print dialog renders before it has loaded. Built early, the image
 * is already in cache when the user presses Print. (The ICS form print path uses
 * a data: URI instead, for the same reason; see inc/branding.php.)
 *
 * The server emits ONE block, <script type="application/json" id="brandingConfig">,
 * only when printing branding is on and a logo applies. No block, no letterhead:
 * an install that never configured a logo is untouched. The config is validated
 * here again before use (the image path must be exactly the capability URL
 * api/branding-logo.php?k=<32 hex>), and the DOM is built with createElement /
 * setAttribute only, never innerHTML.
 *
 * ES5 IIFE. Pure functions are exposed (window.BrandingLetterhead, and
 * module.exports under Node) so tests/test_gh142_branding_letterhead_js.php can
 * drive them without a browser.
 */
(function () {
    'use strict';

    var SRC_RE = /^api\/branding-logo\.php\?k=[a-f0-9]{32}$/;
    var SIZES = ['small', 'medium', 'large'];
    var ALIGNS = ['left', 'center', 'right'];
    var BANNERS = ['replace', 'keep'];

    function pick(value, allowed, fallback) {
        return allowed.indexOf(value) === -1 ? fallback : value;
    }

    function toInt(value) {
        var n = parseInt(value, 10);
        return isNaN(n) || n < 0 ? 0 : n;
    }

    /**
     * PURE. JSON text -> a validated letterhead model, or null (no letterhead).
     * Anything unexpected falls back to a safe default or drops the letterhead;
     * it never throws.
     */
    function parseConfig(text) {
        var cfg;
        try {
            cfg = JSON.parse(String(text));
        } catch (e) {
            return null;
        }
        if (!cfg || typeof cfg !== 'object') return null;
        if (typeof cfg.src !== 'string' || !SRC_RE.test(cfg.src)) return null;
        var alt = typeof cfg.alt === 'string' ? cfg.alt : '';
        if (alt.length > 100) alt = alt.substring(0, 100);
        return {
            src: cfg.src,
            alt: alt,
            size: pick(cfg.size, SIZES, 'medium'),
            align: pick(cfg.align, ALIGNS, 'center'),
            banner: pick(cfg.banner, BANNERS, 'replace'),
            w: toInt(cfg.w),
            h: toInt(cfg.h)
        };
    }

    /** Build the letterhead element for a model. Uses the passed document. */
    function buildLetterhead(doc, model) {
        var box = doc.createElement('div');
        box.setAttribute('id', 'printLetterhead');
        box.className = 'print-letterhead print-letterhead-align-' + model.align;
        var img = doc.createElement('img');
        img.className = 'branding-logo branding-logo-light branding-size-print-' + model.size;
        img.setAttribute('src', model.src);
        img.setAttribute('alt', model.alt);
        if (model.w > 0) img.setAttribute('width', String(model.w));
        if (model.h > 0) img.setAttribute('height', String(model.h));
        box.appendChild(img);
        return box;
    }

    /** The same string format the three pages that already stamped the date use. */
    function stampPrintDate(doc) {
        if (!doc || !doc.body) return;
        var now = new Date();
        doc.body.setAttribute('data-print-date', now.toLocaleDateString() + ' ' + now.toLocaleTimeString());
    }

    /**
     * Wire a document: stamp the print date before every print (every page, which
     * fixes the pages that printed "Printed " with an empty date), and, when a
     * valid config block is present, put the letterhead first in <body>.
     */
    function init(doc, win) {
        if (!doc || !doc.body) return null;
        if (win && win.addEventListener) {
            win.addEventListener('beforeprint', function () { stampPrintDate(doc); });
        }
        var holder = doc.getElementById('brandingConfig');
        if (!holder) return null;
        var model = parseConfig(holder.textContent);
        if (!model) return null;
        if (doc.getElementById('printLetterhead')) return null;   // idempotent
        var box = buildLetterhead(doc, model);
        doc.body.insertBefore(box, doc.body.firstChild);
        // branding_print_banner = replace: the logo takes the place of print.css's
        // text banner ("Tickets CAD - Printed [date]").
        if (model.banner === 'replace') {
            doc.body.classList.add('has-letterhead');
        }
        return box;
    }

    var api = {
        parseConfig: parseConfig,
        buildLetterhead: buildLetterhead,
        stampPrintDate: stampPrintDate,
        init: init
    };
    if (typeof window !== 'undefined') { window.BrandingLetterhead = api; }
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; }

    if (typeof document === 'undefined') { return; }   // under Node for tests only

    function run() { init(document, window); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
