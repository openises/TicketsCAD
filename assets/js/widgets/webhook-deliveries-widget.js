/**
 * Phase 94 Stage 5.4 — Recent Webhook Deliveries widget.
 *
 * Mountable widget that an admin Settings panel embeds. Renders a
 * compact "X / Y / Z" counter for the last hour / 24h / 7d, a list
 * of recent deliveries (most recent first), and a per-subscription
 * health column. Click a row → modal with the full payload + response.
 *
 * Mount:
 *
 *   <div id="webhookDeliveriesWidget"></div>
 *   <script>WebhookDeliveriesWidget.mount('webhookDeliveriesWidget');</script>
 *
 * ES5 IIFE, no jQuery, no template literals.
 */

var WebhookDeliveriesWidget = (function () {
    'use strict';

    var refreshTimer = null;
    var mountId = null;

    function escHtml(s) {
        if (s === null || s === undefined) return '';
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    function statusBadge(status) {
        var cls = 'bg-secondary';
        if (status === 'success')      cls = 'bg-success';
        else if (status === 'failed')  cls = 'bg-warning text-dark';
        else if (status === 'dead_letter') cls = 'bg-danger';
        else if (status === 'pending') cls = 'bg-info text-dark';
        else if (status === 'retried') cls = 'bg-light text-dark border';
        return '<span class="badge ' + cls + '">' + escHtml(status) + '</span>';
    }

    function renderCounts(counts) {
        function row(label, c) {
            if (!c) return '<div class="col text-body-secondary small">' + escHtml(label) + ': —</div>';
            var s = parseInt(c.success || 0, 10);
            var f = parseInt(c.failed || 0, 10);
            var d = parseInt(c.dead_letter || 0, 10);
            var t = parseInt(c.total || 0, 10);
            return '<div class="col">' +
                '<div class="small text-body-secondary">' + escHtml(label) + '</div>' +
                '<div>' +
                '<span class="badge bg-success">' + s + ' ok</span> ' +
                '<span class="badge bg-warning text-dark">' + f + ' failed</span> ' +
                '<span class="badge bg-danger">' + d + ' DL</span> ' +
                '<span class="text-body-secondary small">/' + t + '</span>' +
                '</div></div>';
        }
        return '<div class="row g-2 mb-2">' +
            row('Last hour',  counts.hour) +
            row('Last 24h',   counts.day) +
            row('Last 7d',    counts.week) +
            '</div>';
    }

    function renderSubscriptions(subs) {
        if (!subs || subs.length === 0) {
            return '<div class="text-body-secondary small mb-2">No webhook subscriptions configured.</div>';
        }
        var html = '<table class="table table-sm mb-3"><thead><tr>' +
            '<th>Subscription</th><th>Active</th><th>Last Success</th><th>Last Failure</th><th>Dead-Letter</th>' +
            '</tr></thead><tbody>';
        for (var i = 0; i < subs.length; i++) {
            var s = subs[i];
            html += '<tr>' +
                '<td><strong>' + escHtml(s.name) + '</strong>' +
                '<br><span class="small text-body-secondary">' + escHtml(s.target_url) + '</span></td>' +
                '<td>' + (parseInt(s.active, 10) ? '<span class="text-success">●</span>' : '<span class="text-body-secondary">○</span>') + '</td>' +
                '<td class="small">' + (s.last_success_at ? escHtml(s.last_success_at) : '—') + '</td>' +
                '<td class="small">' + (s.last_failure_at ? '<span class="text-warning">' + escHtml(s.last_failure_at) + '</span>' : '—') + '</td>' +
                '<td>' + (parseInt(s.dead_letter_count, 10) > 0 ? '<span class="badge bg-danger">' + s.dead_letter_count + '</span>' : '0') + '</td>' +
                '</tr>';
        }
        html += '</tbody></table>';
        return html;
    }

    function renderDeliveries(deliveries) {
        if (!deliveries || deliveries.length === 0) {
            return '<div class="text-body-secondary small">No deliveries yet.</div>';
        }
        var html = '<table class="table table-sm table-hover"><thead><tr>' +
            '<th>Time</th><th>Subscription</th><th>Event</th><th>Status</th><th>HTTP</th><th>Att.</th><th>Dur (ms)</th><th></th>' +
            '</tr></thead><tbody>';
        for (var i = 0; i < deliveries.length; i++) {
            var d = deliveries[i];
            html += '<tr data-id="' + escHtml(String(d.id)) + '" style="cursor:pointer">' +
                '<td class="small">' + escHtml(d.created_at) + '</td>' +
                '<td>' + escHtml(d.subscription_name || ('Sub #' + d.subscription_id)) + '</td>' +
                '<td class="small font-monospace">' + escHtml(d.event_type) + '</td>' +
                '<td>' + statusBadge(d.status) + '</td>' +
                '<td class="small">' + (d.http_status || '—') + '</td>' +
                '<td class="small">' + escHtml(String(d.attempt)) + '</td>' +
                '<td class="small">' + (d.duration_ms || '—') + '</td>' +
                '<td class="small text-end">' +
                (d.status === 'dead_letter' ? '<button class="btn btn-sm btn-outline-warning py-0 px-2 replay-btn" data-id="' + d.id + '">Replay</button>' : '') +
                '</td>' +
                '</tr>';
        }
        html += '</tbody></table>';
        return html;
    }

    function loadCsrf() {
        var el = document.getElementById('csrfToken');
        return el ? el.value : '';
    }

    function fetchAndRender() {
        var container = document.getElementById(mountId);
        if (!container) return;
        fetch('api/webhook-deliveries.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    container.innerHTML = '<div class="alert alert-warning small">' + escHtml(data.error) + '</div>';
                    return;
                }
                container.innerHTML =
                    renderCounts(data.counts || {}) +
                    renderSubscriptions(data.subscriptions || []) +
                    '<h6 class="small text-body-secondary">Recent Deliveries</h6>' +
                    renderDeliveries(data.deliveries || []);

                // Wire row clicks → detail modal
                var rows = container.querySelectorAll('tr[data-id]');
                for (var i = 0; i < rows.length; i++) {
                    rows[i].addEventListener('click', function (ev) {
                        if (ev.target.classList.contains('replay-btn')) return; // let the button handle it
                        showDetailModal(this.getAttribute('data-id'));
                    });
                }
                var replayBtns = container.querySelectorAll('.replay-btn');
                for (var j = 0; j < replayBtns.length; j++) {
                    replayBtns[j].addEventListener('click', function (ev) {
                        ev.stopPropagation();
                        replayDelivery(this.getAttribute('data-id'));
                    });
                }
            })
            .catch(function (err) {
                container.innerHTML = '<div class="alert alert-danger small">Load failed: ' + escHtml(err.message) + '</div>';
            });
    }

    function showDetailModal(id) {
        fetch('api/webhook-deliveries.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error || !data.delivery) {
                    alert('Could not load delivery detail: ' + (data.error || 'not found'));
                    return;
                }
                var d = data.delivery;
                var modalEl = document.getElementById('webhookDeliveryModal');
                if (!modalEl) {
                    modalEl = document.createElement('div');
                    modalEl.id = 'webhookDeliveryModal';
                    modalEl.className = 'modal fade';
                    modalEl.tabIndex = -1;
                    document.body.appendChild(modalEl);
                }
                modalEl.innerHTML =
                    '<div class="modal-dialog modal-lg modal-dialog-scrollable">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header"><h5 class="modal-title">Delivery #' + escHtml(String(d.id)) + '</h5>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
                    '<div class="modal-body">' +
                    '<dl class="row small">' +
                    '<dt class="col-sm-3">Subscription</dt><dd class="col-sm-9">' + escHtml(d.subscription_name || ('Sub #' + d.subscription_id)) + '</dd>' +
                    '<dt class="col-sm-3">Target URL</dt><dd class="col-sm-9 font-monospace">' + escHtml(d.target_url) + '</dd>' +
                    '<dt class="col-sm-3">Event type</dt><dd class="col-sm-9 font-monospace">' + escHtml(d.event_type) + '</dd>' +
                    '<dt class="col-sm-3">Status</dt><dd class="col-sm-9">' + statusBadge(d.status) + '</dd>' +
                    '<dt class="col-sm-3">HTTP status</dt><dd class="col-sm-9">' + escHtml(String(d.http_status || '—')) + '</dd>' +
                    '<dt class="col-sm-3">Duration</dt><dd class="col-sm-9">' + escHtml(String(d.duration_ms || '—')) + ' ms</dd>' +
                    '<dt class="col-sm-3">Attempt</dt><dd class="col-sm-9">' + escHtml(String(d.attempt)) + '</dd>' +
                    '<dt class="col-sm-3">Created</dt><dd class="col-sm-9 font-monospace">' + escHtml(d.created_at) + '</dd>' +
                    (d.dead_lettered_at ? '<dt class="col-sm-3">Dead-lettered</dt><dd class="col-sm-9 font-monospace">' + escHtml(d.dead_lettered_at) + '</dd>' : '') +
                    (d.replayed_from_id ? '<dt class="col-sm-3">Replayed from</dt><dd class="col-sm-9">delivery #' + escHtml(String(d.replayed_from_id)) + '</dd>' : '') +
                    (d.error ? '<dt class="col-sm-3">Error</dt><dd class="col-sm-9 text-danger small">' + escHtml(d.error) + '</dd>' : '') +
                    '</dl>' +
                    '<h6 class="small text-body-secondary mt-3">Payload</h6>' +
                    '<pre class="small p-2 bg-body-tertiary border rounded" style="max-height:200px;overflow:auto">' + escHtml(d.payload || '') + '</pre>' +
                    '<h6 class="small text-body-secondary mt-3">Response body</h6>' +
                    '<pre class="small p-2 bg-body-tertiary border rounded" style="max-height:200px;overflow:auto">' + escHtml(d.response_body || '(empty)') + '</pre>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    (d.status === 'dead_letter' || d.status === 'failed' ?
                        '<button type="button" class="btn btn-warning btn-sm replay-from-modal" data-id="' + d.id + '">Replay this delivery</button>' : '') +
                    '<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>' +
                    '</div></div></div>';
                var modal = new bootstrap.Modal(modalEl);
                modal.show();

                var rb = modalEl.querySelector('.replay-from-modal');
                if (rb) {
                    rb.addEventListener('click', function () {
                        replayDelivery(this.getAttribute('data-id'));
                        modal.hide();
                    });
                }
            });
    }

    function replayDelivery(id) {
        if (!confirm('Replay delivery #' + id + '? This will fire a fresh delivery against the same subscription.')) return;
        fetch('api/webhooks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                action: 'replay',
                delivery_id: parseInt(id, 10),
                csrf_token: loadCsrf()
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                alert('Replay failed: ' + data.error);
                return;
            }
            alert('Replayed as delivery #' + data.replay_id +
                  (data.delivery ? ' (' + data.delivery.status + ', HTTP ' + (data.delivery.http_status || '—') + ')' : ''));
            fetchAndRender();
        });
    }

    return {
        mount: function (elementId) {
            mountId = elementId;
            fetchAndRender();
            // Refresh every 30 seconds while the widget is visible
            if (refreshTimer) clearInterval(refreshTimer);
            refreshTimer = setInterval(fetchAndRender, 30000);
        },
        refresh: fetchAndRender,
        unmount: function () {
            if (refreshTimer) {
                clearInterval(refreshTimer);
                refreshTimer = null;
            }
        }
    };
})();
