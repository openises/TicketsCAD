// GH#52 follow-up (2026-08-13) — a small, self-contained extra-data
// collection modal, exposed as window.TCADStatusExtraDataPrompt so any
// page can offer the same facility/note/numeric/mileage/location picker
// the dashboard's status modal already has, without loading app.js's
// dashboard-only modal markup and event wiring.
//
// incident-detail.js already had a call site shaped for exactly this
// global (Phase 104f, 2026-07-02) -- window.TCADStatusExtraDataPrompt was
// referenced there but never defined anywhere, so every status requiring
// extra data on the Incident page silently fell back to a plain
// window.prompt(), which cannot render a facility <select> (GH#52 follow-
// up, Chris Byrd, 2026-08-13).
//
// Builds its own modal DOM on first use (once, reused after) rather than
// depending on markup a specific page happens to ship, so it works the
// same way on every page that loads this file.
(function () {
    'use strict';

    var _facilityCache = null;
    function _loadFacilityOptions() {
        if (_facilityCache) return Promise.resolve(_facilityCache);
        return fetch('api/facilities.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                _facilityCache = data.facilities || data.rows || [];
                return _facilityCache;
            })
            .catch(function () { return []; });
    }

    function _escHtml(s) {
        var d = document.createElement('div');
        d.textContent = (s === null || s === undefined) ? '' : String(s);
        return d.innerHTML;
    }

    var _modalEl = null;
    function _ensureModal() {
        if (_modalEl) return _modalEl;
        var el = document.createElement('div');
        el.className = 'modal fade';
        el.id = 'tcadExtraDataPromptModal';
        el.tabIndex = -1;
        el.innerHTML =
            '<div class="modal-dialog modal-dialog-centered">' +
              '<div class="modal-content">' +
                '<div class="modal-header py-2">' +
                  '<h6 class="modal-title" id="tcadExtraDataPromptTitle"></h6>' +
                  '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
                '</div>' +
                '<div class="modal-body" id="tcadExtraDataPromptBody"></div>' +
              '</div>' +
            '</div>';
        document.body.appendChild(el);
        _modalEl = el;
        return el;
    }

    /**
     * window.TCADStatusExtraDataPrompt(cfg, done)
     *   cfg: { type, label, status_val, required }
     *   done(value) is called with the collected value on Apply/Skip,
     *   or null if the dispatcher cancels (dismisses the modal without
     *   applying) -- matching the contract incident-detail.js already
     *   expects (see its updateAssignmentStatus()).
     */
    window.TCADStatusExtraDataPrompt = function (cfg, done) {
        var modal = _ensureModal();
        var bodyEl = modal.querySelector('#tcadExtraDataPromptBody');
        var titleEl = modal.querySelector('#tcadExtraDataPromptTitle');
        var type = cfg.type || 'text';
        var label = cfg.label || 'value';
        var required = !!cfg.required;
        var settled = false;

        titleEl.textContent = cfg.status_val ? ('Status: ' + cfg.status_val) : 'Additional information needed';

        var html = '<label class="form-label form-label-sm">' + _escHtml(label)
                 + (required ? ' <span class="text-danger">*</span>' : '')
                 + '</label>';
        if (type === 'note') {
            html += '<textarea id="tcadEdInput" class="form-control form-control-sm mb-2" rows="3"></textarea>';
        } else if (type === 'numeric') {
            html += '<input type="number" id="tcadEdInput" class="form-control form-control-sm mb-2" step="any">';
        } else if (type === 'mileage') {
            html += '<input type="number" id="tcadEdInput" class="form-control form-control-sm mb-2" min="0" step="1" placeholder="Odometer reading">';
        } else if (type === 'facility') {
            html += '<select id="tcadEdInput" class="form-select form-select-sm mb-2"><option value="">— Loading facilities… —</option></select>';
        } else {
            html += '<input type="text" id="tcadEdInput" class="form-control form-control-sm mb-2">';
        }
        html += '<div class="d-flex gap-2">'
              + '<button type="button" class="btn btn-sm btn-primary" id="tcadEdApply">Apply</button>';
        if (!required) {
            html += '<button type="button" class="btn btn-sm btn-outline-secondary" id="tcadEdSkip">Skip</button>';
        }
        html += '</div>';
        bodyEl.innerHTML = html;

        if (type === 'facility') {
            _loadFacilityOptions().then(function (facs) {
                var sel = bodyEl.querySelector('#tcadEdInput');
                if (!sel) return;
                var opts = '<option value="">— Select a facility —</option>';
                facs.forEach(function (f) {
                    opts += '<option value="' + f.id + '">' + _escHtml(f.name) + '</option>';
                });
                sel.innerHTML = opts;
            });
        }

        function collect() {
            var el = bodyEl.querySelector('#tcadEdInput');
            if (!el) return null;
            var v = el.value;
            if (type === 'numeric' || type === 'mileage' || type === 'facility') {
                if (v === '' || v == null) return null;
                return type === 'facility' ? (parseInt(v, 10) || null) : (parseFloat(v));
            }
            return v;
        }

        var bsModal = bootstrap.Modal.getOrCreateInstance(modal, { backdrop: 'static' });

        function finish(value) {
            if (settled) return;
            settled = true;
            bsModal.hide();
            done(value);
        }

        bodyEl.querySelector('#tcadEdApply').addEventListener('click', function () {
            var v = collect();
            if (required && (v === null || v === '')) {
                alert('This field is required.');
                return;
            }
            finish(v);
        });
        var skipBtn = bodyEl.querySelector('#tcadEdSkip');
        if (skipBtn) skipBtn.addEventListener('click', function () { finish(null); });

        // Dismissed via the X button, backdrop, or Esc -- without this the
        // caller's done() never fires and any UI it was meant to reset
        // (e.g. a disabled dropdown) stays stuck. bootstrap fires 'hidden'
        // once, whether closed by our own finish() or by the user.
        modal.addEventListener('hidden.bs.modal', function onHidden() {
            modal.removeEventListener('hidden.bs.modal', onHidden);
            if (!settled) { settled = true; done(null); }
        });

        bsModal.show();
    };
})();
