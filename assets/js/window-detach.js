/**
 * NewUI v4.0 — Detach a floating widget into its own real OS window
 * (2026-09-08, Eric's request: "many operators with multiple screens
 * wanting to spread out their windows into additional displays").
 *
 * Uses the Document Picture-in-Picture API (documentPictureInPicture.
 * requestWindow()) where available — Chrome/Edge 130+ (Oct 2024),
 * Firefox 151+ (May 2026) — which is a REAL, spec-defined OS-level
 * always-on-top window: it floats above every other application on the
 * operator's desktop, not just other browser tabs. This is honest, not
 * an approximation — no ordinary website can fake true OS-level
 * always-on-top, and this file does not pretend the popup fallback
 * below achieves it. Falls back to a plain window.open() popup (minimal
 * chrome via popup features — no menubar/toolbar/location bar — but NOT
 * always-on-top; the operator can still drag it to a second monitor and
 * use their OS's own window-pinning feature if it has one) on browsers
 * without PiP support (Safari as of this writing; older Chrome/Firefox/
 * Edge).
 *
 * BOTH paths share the exact same JavaScript execution context as the
 * opener. This is a documented property of the PiP API, and equally
 * true of a same-origin window.open() popup — a DOM node's event
 * listeners and closures belong to the node/script that created them,
 * not to whichever document object currently renders it. So detaching does
 * NOT recreate the widget or its state: it MOVES the SAME DOM element
 * (and therefore the SAME live WebSocket/audio connections, the SAME
 * click handlers, still running in the main tab) into a new window,
 * then moves it back when that window closes. Never call open() on a
 * node still needed in the main document — it is REMOVED from its
 * original parent, not cloned; the caller is responsible for leaving
 * some placeholder/toggle behind if the widget can be re-opened while
 * detached (each of this project's three floating widgets does this by
 * simply disabling their own toggle button while detached — see each
 * widget's own "Detach" wiring for specifics).
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    function copyTheme(targetDoc) {
        var theme = document.documentElement.getAttribute('data-bs-theme');
        if (theme) { targetDoc.documentElement.setAttribute('data-bs-theme', theme); }
    }

    // PiP/popup windows start with an empty <head> -- nothing is
    // inherited from the opener automatically. Clone every stylesheet
    // link (by reference URL, so it's the browser's own cached copy --
    // no extra network fetch in practice) and every inline <style> block
    // so the detached widget renders identically to how it looked
    // in-page, including dark/light theme variables.
    //
    // The media attribute MUST be copied too (Eric, 2026-09-08 bug
    // report -- a detached Zello widget rendered blank with a spurious
    // "Tickets CAD -- Printed [date]" header). Every page links
    // print.css with media="print" so it only applies while printing;
    // without copying that attribute the cloned link defaults to
    // media="all" and print.css's aggressive print-only rules -- which
    // include ".zello-widget { display: none !important; }" and a
    // print-header pseudo-element on body::before -- silently applied
    // to the popup on screen, hiding the real widget entirely.
    function copyStyles(targetDoc) {
        var links = document.querySelectorAll('link[rel="stylesheet"]');
        var i;
        for (i = 0; i < links.length; i++) {
            var link = targetDoc.createElement('link');
            link.rel = 'stylesheet';
            link.href = links[i].href;
            if (links[i].media) { link.media = links[i].media; }
            targetDoc.head.appendChild(link);
        }
        var styles = document.querySelectorAll('style');
        for (i = 0; i < styles.length; i++) {
            targetDoc.head.appendChild(styles[i].cloneNode(true));
        }
    }

    // rootEl: the widget's own root DOM element (moved, not cloned).
    // opts: {width, height, title, onOpen(), onClose(reason)} -- reason
    // is 'closed' (operator closed the detached window; rootEl has
    // already been restored to its original position by the time this
    // fires) or 'unsupported' (the popup fallback itself was blocked by
    // the browser -- rootEl was never moved).
    // Returns {close: function()} -- close the detached window
    // programmatically (e.g. if the caller's own "Close" button should
    // also close a detached instance).
    function open(rootEl, opts) {
        opts = opts || {};
        var width = opts.width || 360;
        var height = opts.height || 420;
        var title = opts.title || 'TicketsCAD';
        var originalParent = rootEl.parentNode;
        var originalNextSibling = rootEl.nextSibling;
        var closed = false;
        // Cancels a REQUEST still in flight (the PiP promise hasn't
        // resolved yet) -- distinct from the closed flag above, which only
        // ever becomes true once a window has actually opened and been
        // torn down. Found
        // during a security-focused persona review (2026-09-08): clicking
        // the widget's own Close/Minimize button while a detach request was
        // still pending used to leave nothing able to stop the .then()
        // callback from later moving an already-hidden node into a
        // freshly-opened, real always-on-top window -- a confusing dead end
        // (recoverable via the OS close button, but never should have
        // happened). Every caller of open() now MUST call the returned
        // close() before hiding/removing rootEl for any other reason.
        var cancelled = false;

        // Every widget this ships with is positioned via inline
        // position:fixed + absolute-pixel left/top/right/bottom set by
        // its OWN drag logic, relative to the MAIN window's viewport
        // (e.g. "right: 20px; bottom: 20px" or a saved drag position
        // like "left: 1400px; top: 640px" on a wide main monitor).
        // Moving that same inline style into a small 380x540 detached
        // window would render it clipped or entirely off-screen. Save
        // it, blank it out to fill the new window edge-to-edge while
        // detached, and put the ORIGINAL positioning back verbatim on
        // restore() -- so re-attaching lands the widget exactly where
        // the operator had it in the main window, not at some default.
        var savedStyleCssText = rootEl.style.cssText;

        function fillDetachedWindow() {
            rootEl.style.position = 'static';
            rootEl.style.top = '';
            rootEl.style.left = '';
            rootEl.style.right = '';
            rootEl.style.bottom = '';
            rootEl.style.width = '100%';
            rootEl.style.height = '100%';
            rootEl.style.maxWidth = 'none';
            rootEl.style.maxHeight = 'none';
        }

        // Closes the detached window BEFORE the main tab itself unloads
        // (a real nav, not just a reload) -- found in a persona review
        // (2026-09-08, a shift-supervisor lens): this app is a classic
        // multi-page site, not a single-page app -- every nav is a full
        // reload, which destroys the JS realm/WebSocket connections a
        // detached POPUP's listeners depend on (leaving it an orphaned dead
        // husk with nothing left to answer its clicks), and separately
        // causes the browser to auto-close a PiP window outright (per spec,
        // tied to the opener document's lifetime) WHILE restore() is still
        // trying to reattach rootEl into a document that's already gone.
        // Proactively closing here means the operator sees the detached
        // window disappear cleanly on nav, instead of either failure mode.
        function unloadHandler() { closeDetached(); }

        function removeUnloadHandler() {
            window.removeEventListener('beforeunload', unloadHandler);
        }

        function restore() {
            if (closed) { return; }
            closed = true;
            removeUnloadHandler();
            rootEl.style.cssText = savedStyleCssText;
            if (originalNextSibling && originalNextSibling.parentNode === originalParent) {
                originalParent.insertBefore(rootEl, originalNextSibling);
            } else if (originalParent) {
                originalParent.appendChild(rootEl);
            }
            if (opts.onClose) { opts.onClose('closed'); }
        }

        // Set by whichever path actually opens a window, so closeDetached()
        // (called by both unloadHandler() and the returned close()) has a
        // real reference to close rather than guessing at the browser's
        // current global PiP window -- a second finding from the same
        // security review: window.documentPictureInPicture.window is
        // whichever PiP window is CURRENTLY OPEN, not necessarily the one
        // THIS open() call created, so a stale close() call could once have
        // closed a different widget's PiP window entirely.
        var activeWindow = null;
        function closeDetached() {
            cancelled = true;
            if (closed || !activeWindow) { return; }
            try { activeWindow.close(); } catch (e) { /* already gone */ }
        }

        function openPopupFallback() {
            if (cancelled) { return { close: function () {} }; }
            var popup = window.open('', '_blank',
                'popup=yes,width=' + width + ',height=' + height +
                ',menubar=no,toolbar=no,location=no,status=no,resizable=yes');
            if (!popup) {
                if (opts.onClose) { opts.onClose('unsupported'); }
                return { close: function () {} };
            }
            activeWindow = popup;
            copyTheme(popup.document);
            copyStyles(popup.document);
            popup.document.title = title;
            popup.document.body.style.margin = '0';
            fillDetachedWindow();
            popup.document.body.appendChild(rootEl);
            if (opts.onOpen) { opts.onOpen(); }
            window.addEventListener('beforeunload', unloadHandler);
            var checkClosed = window.setInterval(function () {
                if (popup.closed) {
                    window.clearInterval(checkClosed);
                    restore();
                }
            }, 500);
            return { close: closeDetached };
        }

        if (window.documentPictureInPicture && window.documentPictureInPicture.requestWindow) {
            window.documentPictureInPicture.requestWindow({ width: width, height: height })
                .then(function (pipWindow) {
                    if (cancelled) {
                        // The operator closed/minimized the widget while
                        // this request was still in flight -- the window
                        // already opened (requestWindow() can't be aborted
                        // mid-flight), so close it immediately without ever
                        // moving rootEl into it.
                        try { pipWindow.close(); } catch (e) {}
                        return;
                    }
                    activeWindow = pipWindow;
                    copyTheme(pipWindow.document);
                    copyStyles(pipWindow.document);
                    pipWindow.document.title = title;
                    pipWindow.document.body.style.margin = '0';
                    fillDetachedWindow();
                    pipWindow.document.body.appendChild(rootEl);
                    if (opts.onOpen) { opts.onOpen(); }
                    window.addEventListener('beforeunload', unloadHandler);
                    pipWindow.addEventListener('pagehide', restore, { once: true });
                })
                .catch(function () {
                    // requestWindow() can reject (e.g. transient-activation
                    // requirement not met by the calling event) -- fall
                    // back rather than silently doing nothing on click.
                    openPopupFallback();
                });
            return { close: closeDetached };
        }
        return openPopupFallback();
    }

    window.WindowDetach = {
        hasPiP: function () { return !!(window.documentPictureInPicture && window.documentPictureInPicture.requestWindow); },
        open: open
    };
})();
