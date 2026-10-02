/**
 * NewUI v4.0 — Console rebuild: the Recall tab (Phase 152)
 *
 * plan.md's own design: "fans out to the existing per-adapter history
 * APIs... into one merged, client-sorted chronological list — no new
 * comm_events table" (Proposal B's heavier backend bet explicitly not
 * taken, keeping this genuinely secondary/lightweight). Built while
 * reading those APIs directly this turned out simpler than plan.md
 * anticipated: api/channels.php's own channel_feed() (already backing
 * every strip's live text-drawer loadFeed()) already normalizes Zello,
 * DMR, local_chat, NWS, eventbus, and every generic broker-backed
 * adapter into ONE {when,who,body,dir} shape, under the SAME
 * screen.console gate every strip already requires — reusing it here
 * means one consistent RBAC story and one already-tested code path,
 * instead of fanning out to 4 endpoints with 4 different permission
 * codes and 4 different response shapes. The one source with no per-
 * channel equivalent is api/inbound-calls.php's missed-call queue
 * (screen.call_queue, install-wide, not tied to one channel) — that one
 * IS still its own separate fetch, exactly as plan.md described, and
 * renders as its own group ("Missed Calls") alongside the per-channel
 * ones.
 *
 * Grouped by channel/mode with counts, chronological within group
 * (5-persona review, unanimous). No "unseen since I logged into this
 * position" default yet — that needs comm_channel_reads from the thin
 * position layer, a separate not-yet-built task (tasks.md's own
 * sequencing note) — everything currently visible is shown; the position
 * layer can add an unseen-only filter on top of this same list later
 * without changing this file's own merge/render logic.
 *
 * Per-strip "replay last" is a one-button shortcut fetching just the ONE
 * most recent item from the RICHER per-adapter endpoint (api/dmr-
 * history.php / api/zello-messages.php) that actually carries a playable
 * audio_path/media_url — channel_feed() itself only returns text
 * summaries ("[voice call]"), by design, to keep the default list light.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    var CH_API = 'api/channels.php';
    var DMR_HISTORY_API = 'api/dmr-history.php';
    var ZELLO_HISTORY_API = 'api/zello-messages.php';
    var MISSED_CALLS_API = 'api/inbound-calls.php?action=list_missed';
    var PER_CHANNEL_LIMIT = 10; // a summary view across MANY channels, smaller than the live drawer's 30

    var panelEl = document.getElementById('consoleRecallPanel');
    var toggleBtn = document.getElementById('consoleRecallToggle');
    if (!panelEl || !toggleBtn) { return; } // console.php must render both

    var open = false;
    var loading = false;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }

    function relTime(mysqlDt) {
        if (!mysqlDt) { return ''; }
        var t = new Date(String(mysqlDt).replace(' ', 'T'));
        if (isNaN(t.getTime())) { return String(mysqlDt); }
        var s = Math.floor((Date.now() - t.getTime()) / 1000);
        if (s < 0) { s = 0; }
        if (s < 60) { return s + 's ago'; }
        if (s < 3600) { return Math.floor(s / 60) + 'm ago'; }
        if (s < 86400) { return Math.floor(s / 3600) + 'h ago'; }
        return Math.floor(s / 86400) + 'd ago';
    }

    function fetchJson(url) {
        return fetch(url).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; });
    }

    // ── Build one group per channel + one for missed calls ───────────
    function loadRecall() {
        loading = true;
        renderLoading();
        fetchJson(CH_API + '?probe=1').then(function (chJson) {
            var channels = (chJson && chJson.channels) || [];
            var enabled = [];
            for (var i = 0; i < channels.length; i++) {
                if (parseInt(channels[i].enabled, 10) === 1) { enabled.push(channels[i]); }
            }
            var feedPromises = [];
            for (var k = 0; k < enabled.length; k++) {
                (function (ch) {
                    feedPromises.push(
                        fetchJson(CH_API + '?feed=' + encodeURIComponent(ch.id) + '&limit=' + PER_CHANNEL_LIMIT)
                            .then(function (j) { return { ch: ch, feed: (j && j.feed) || [] }; })
                    );
                })(enabled[k]);
            }
            return Promise.all(feedPromises.concat([
                fetchJson(MISSED_CALLS_API).then(function (j) { return { missed: (j && j.calls) || [] }; })
            ]));
        }).then(function (results) {
            var groups = [];
            var missed = [];
            for (var i = 0; i < results.length; i++) {
                var r = results[i];
                if (r && r.missed) { missed = r.missed; continue; }
                if (r && r.feed && r.feed.length) {
                    var cfg = r.ch.config || {};
                    groups.push({
                        key: 'ch:' + r.ch.id,
                        label: r.ch.short_label || r.ch.label,
                        adapter: r.ch.adapter,
                        channelId: r.ch.id,
                        // Only meaningful for the two adapters playLatest()
                        // actually uses them for — undefined is harmless
                        // (fetchJson() with an empty/undefined channel
                        // param never runs since canReplay gates the call).
                        dmrChannelId: cfg.dmr_channel_id,
                        zelloChannelName: cfg.channel,
                        canReplay: (r.ch.adapter === 'zello' || r.ch.adapter === 'dmr_bm' || r.ch.adapter === 'dmr_local'),
                        items: r.feed.map(function (it) {
                            return { when: it.when, who: it.who, body: it.body, dir: it.dir };
                        }),
                    });
                }
            }
            if (missed.length) {
                groups.unshift({
                    key: 'missed', label: 'Missed Calls', adapter: null, channelId: null, canReplay: false,
                    items: missed.map(function (c) {
                        return { when: c.ringing_at, who: c.caller_name || c.caller_number, body: 'from ' + (c.caller_number || 'unknown'), dir: 'rx' };
                    }),
                });
            }
            // Most-recently-active group first — the busiest channel surfaces
            // at the top, matching "what do I need to catch up on right now".
            groups.sort(function (a, b) {
                var at = a.items.length ? new Date(String(a.items[a.items.length - 1].when).replace(' ', 'T')).getTime() : 0;
                var bt = b.items.length ? new Date(String(b.items[b.items.length - 1].when).replace(' ', 'T')).getTime() : 0;
                return bt - at;
            });
            loading = false;
            render(groups);
        });
    }

    function renderLoading() {
        panelEl.innerHTML = '';
        panelEl.appendChild(el('div', 'text-body-secondary p-3 small', 'Loading recent activity…'));
    }

    function playLatest(group) {
        // channel_feed() only ever returns a text summary ("[voice call]")
        // by design (keeps the default list light) — fetch the ONE real
        // audio_path/media_url from the richer per-adapter endpoint only
        // when the operator actually asks to hear it.
        if (group.adapter === 'dmr_bm' || group.adapter === 'dmr_local') {
            fetchJson(DMR_HISTORY_API + '?channel=' + encodeURIComponent(group.dmrChannelId || '') + '&limit=1').then(function (j) {
                var row = j && j.rows && j.rows.length ? j.rows[j.rows.length - 1] : null;
                if (row && row.audio_path) { playAudioUrl(row.audio_path); }
                else { window.alert('No recorded audio available for this call.'); }
            });
        } else if (group.adapter === 'zello') {
            fetchJson(ZELLO_HISTORY_API + '?channel=' + encodeURIComponent(group.zelloChannelName || '') + '&limit=1').then(function (j) {
                var row = j && j.messages && j.messages.length ? j.messages[0] : null;
                if (row && row.media_url) { playAudioUrl(row.media_url); }
                else { window.alert('No recorded audio available for this message.'); }
            });
        }
    }

    var audioEl = null;
    function playAudioUrl(url) {
        if (!audioEl) {
            audioEl = document.createElement('audio');
            audioEl.className = 'console-recall-audio';
            audioEl.controls = true;
            panelEl.parentNode.insertBefore(audioEl, panelEl);
        }
        audioEl.src = url;
        audioEl.classList.remove('d-none');
        audioEl.play().catch(function () { /* autoplay may be blocked — the visible controls remain pressable */ });
    }

    function render(groups) {
        panelEl.innerHTML = '';
        if (!groups.length) {
            panelEl.appendChild(el('div', 'text-body-secondary p-3 small', 'No recent activity to recall.'));
            return;
        }
        for (var i = 0; i < groups.length; i++) {
            (function (g) {
                var card = el('div', 'console-recall-group');
                var head = el('div', 'console-recall-group-head');
                head.appendChild(el('span', 'console-recall-group-label', g.label));
                head.appendChild(el('span', 'badge text-bg-secondary ms-1', String(g.items.length)));
                if (g.canReplay) {
                    var replayBtn = el('button', 'btn btn-sm btn-outline-secondary ms-2', null);
                    replayBtn.type = 'button';
                    replayBtn.appendChild(el('i', 'bi bi-play-fill me-1'));
                    replayBtn.appendChild(document.createTextNode('Replay last'));
                    replayBtn.addEventListener('click', function () { playLatest(g); });
                    head.appendChild(replayBtn);
                }
                card.appendChild(head);
                var list = el('div', 'console-recall-items');
                // items[] arrive oldest-first from channel_feed() (its own
                // DESC-then-nothing-reversed queries actually come back
                // newest-first per-row, but this file doesn't re-sort them
                // beyond what the API already returns) — display newest
                // first within the group, matching "what just happened".
                var ordered = g.items.slice().reverse();
                for (var k = 0; k < ordered.length; k++) {
                    var it = ordered[k];
                    var row = el('div', 'console-recall-item');
                    row.appendChild(el('span', 'console-recall-item-when', relTime(it.when)));
                    if (it.who) { row.appendChild(el('span', 'console-recall-item-who', it.who)); }
                    row.appendChild(el('span', 'console-recall-item-body', it.body || ''));
                    list.appendChild(row);
                }
                card.appendChild(list);
                panelEl.appendChild(card);
            })(groups[i]);
        }
    }

    toggleBtn.addEventListener('click', function () {
        open = !open;
        panelEl.classList.toggle('d-none', !open);
        toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open && !loading) { loadRecall(); }
    });

    // Per-strip "replay last" shortcut (plan.md: "a one-button shortcut
    // into the same per-adapter replay endpoints the Recall tab already
    // uses") — console.js calls this directly with a channel's own
    // {adapter, config} rather than needing a full recall group object;
    // playLatest() only ever reads the same three fields either way.
    window.ConsoleRecall = {
        replayLatestForChannel: function (ch) {
            var cfg = ch.config || {};
            playLatest({ adapter: ch.adapter, dmrChannelId: cfg.dmr_channel_id, zelloChannelName: cfg.channel });
        },
    };
})();
