/**
 * DMR Archive page (Phase 86-archive).
 *
 * Lets dispatchers browse the full dmr_messages history filtered by
 * date range, direction, or text search. Reuses /api/dmr-history.php
 * and /api/dmr-audio.php from the radio widget, but operates on the
 * dedicated archive view instead of the dashboard widget.
 *
 * ES5 IIFE per project convention. No jQuery. Self-contained
 * playback bookkeeping so opening this page in a second tab doesn't
 * conflict with the radio widget's audio players.
 */
(function () {
    'use strict';

    var elFrom    = document.getElementById('archDateFrom');
    var elTo      = document.getElementById('archDateTo');
    var elDir     = document.getElementById('archDirection');
    var elSearch  = document.getElementById('archSearch');
    var elLimit   = document.getElementById('archLimit');
    var elApply   = document.getElementById('archApply');
    var elResults = document.getElementById('archResults');
    var elStats   = document.getElementById('archStats');

    function todayLocal() {
        var d = new Date();
        return d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
    }
    function daysAgoLocal(n) {
        var d = new Date();
        d.setDate(d.getDate() - n);
        return d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
    }

    // Default: today only.
    elFrom.value = todayLocal();
    elTo.value   = todayLocal();

    function escapeHTML(s) {
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function formatTime(iso) {
        if (!iso) return '';
        var d = new Date(iso.replace(' ', 'T'));
        if (isNaN(d.getTime())) return iso;
        var dateStr = String(d.getMonth() + 1).padStart(2, '0') + '/' +
                      String(d.getDate()).padStart(2, '0');
        var timeStr = String(d.getHours()).padStart(2, '0') + ':' +
                      String(d.getMinutes()).padStart(2, '0') + ':' +
                      String(d.getSeconds()).padStart(2, '0');
        return dateStr + ' ' + timeStr;
    }
    function formatDuration(ms) {
        if (!ms || ms < 0) return '';
        var secs = Math.round(ms / 1000);
        if (secs < 60) return secs + 's';
        var m = Math.floor(secs / 60);
        var s = secs - m * 60;
        return m + ':' + String(s).padStart(2, '0');
    }

    // One <audio> per row, lazily created. Click toggles play/pause;
    // starting a different row stops the previous.
    var audioPlayers = {};
    function stopAllExcept(rowId) {
        for (var k in audioPlayers) {
            if (!audioPlayers.hasOwnProperty(k)) continue;
            if (String(k) === String(rowId)) continue;
            var p = audioPlayers[k];
            try { if (p && !p.paused) { p.pause(); p.currentTime = 0; } } catch (e) {}
            var pc = document.querySelector('[data-arch-id="' + k + '"]');
            if (pc) pc.classList.remove('playing');
        }
    }
    function playRow(card, rowId) {
        stopAllExcept(rowId);
        var a = audioPlayers[rowId];
        if (a && !a.paused) {
            card.classList.remove('playing');
            return;
        }
        if (!a) {
            a = new Audio('api/dmr-audio.php?msg_id=' + rowId);
            a.preload = 'auto';
            a.addEventListener('ended', function () {
                card.classList.remove('playing');
            });
            a.addEventListener('error', function () {
                card.classList.remove('playing');
                var err = a.error ? (' (code ' + a.error.code + ')') : '';
                console.warn('[arch] playback failed rowId=' + rowId, a.error);
                showError(card, 'Playback failed' + err + ' — file may be missing');
            });
            audioPlayers[rowId] = a;
        }
        card.classList.add('playing');
        var p = a.play();
        if (p && p.catch) p.catch(function (e) {
            card.classList.remove('playing');
            console.warn('[arch] play() rejected:', e);
        });
    }
    function showError(card, msg) {
        var existing = card.querySelector('.arch-err');
        if (existing) { existing.textContent = msg; return; }
        var d = document.createElement('div');
        d.className = 'arch-err small text-danger mt-1';
        d.textContent = msg;
        card.appendChild(d);
    }

    function renderCard(row) {
        var card = document.createElement('div');
        card.className = 'dmr-archive-card';
        card.setAttribute('data-arch-id', String(row.id));

        var head = document.createElement('div');
        head.className = 'dmr-archive-head';
        // Prefer callsign; fall back to numeric DMR id. If the
        // radioid.net cache has a name, append it for context — e.g.
        // "KE0XYZ — Dan Anderson". Pure numeric src always shows
        // bare so it's obvious there's no lookup.
        var srcLabel = row.callsign || row.src_id || 'unknown';
        var nameTail = (row.callsign && row.name)
            ? ' <span class="text-secondary small">— ' + escapeHTML(row.name) + '</span>'
            : '';
        head.innerHTML =
            '<span class="dmr-archive-dir ' + escapeHTML(row.direction) + '">' +
                escapeHTML(row.direction || '?') + '</span>' +
            '<span class="dmr-archive-time">' + escapeHTML(formatTime(row.started_at)) + '</span>' +
            '<span class="dmr-archive-src">' + escapeHTML(srcLabel) + '</span>' + nameTail +
            '<span class="text-secondary small">→ TG ' + escapeHTML(row.talkgroup || '?') + '</span>' +
            '<span class="dmr-archive-dur">' + escapeHTML(formatDuration(row.duration_ms)) + '</span>';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-outline-primary dmr-archive-replay';
        if (row.audio_path) {
            btn.innerHTML = '<i class="bi bi-play-circle"></i> Play';
            btn.addEventListener('click', function () { playRow(card, row.id); });
        } else {
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-x-circle"></i> No audio';
            btn.title = 'No recording on file';
        }
        head.appendChild(btn);
        card.appendChild(head);

        if (row.transcript) {
            var t = document.createElement('div');
            t.className = 'dmr-archive-transcript';
            t.textContent = row.transcript;
            card.appendChild(t);
        } else {
            var t2 = document.createElement('div');
            t2.className = 'dmr-archive-transcript empty';
            t2.textContent = '(no transcript)';
            card.appendChild(t2);
        }
        return card;
    }

    function fetchAndRender() {
        // Stop any playing audio when refreshing the list.
        stopAllExcept(-1);
        audioPlayers = {};

        var params = new URLSearchParams();
        if (elFrom.value)   params.set('date_from', elFrom.value);
        if (elTo.value)     params.set('date_to',   elTo.value);
        if (elDir.value)    params.set('direction', elDir.value);
        if (elSearch.value) params.set('search',    elSearch.value);
        params.set('limit', elLimit.value || '250');

        elResults.innerHTML = '<div class="dmr-archive-loading"><div class="spinner-border spinner-border-sm"></div> Loading...</div>';
        elStats.innerHTML = '&nbsp;';

        fetch('api/dmr-history.php?' + params.toString(), { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (j) {
                var rows = (j && j.rows) || [];
                if (!rows.length) {
                    elResults.innerHTML = '<div class="dmr-archive-empty"><i class="bi bi-inbox" style="font-size:2rem"></i><br>No recordings match your filters.</div>';
                    elStats.textContent = '0 calls';
                    return;
                }
                // API returns oldest-first within the limit window;
                // reverse so newest is at top of the list.
                rows = rows.slice().reverse();
                var withAudio = 0;
                var frag = document.createDocumentFragment();
                rows.forEach(function (row) {
                    if (row.audio_path) withAudio++;
                    frag.appendChild(renderCard(row));
                });
                elResults.innerHTML = '';
                elResults.appendChild(frag);
                elStats.textContent = rows.length + ' calls — ' +
                    withAudio + ' with recordings — channel: ' +
                    (j.channel || '?') + ' (TG ' + (j.talkgroup || '?') + ')';
            })
            .catch(function (err) {
                elResults.innerHTML = '<div class="dmr-archive-empty text-danger">Load failed: ' + escapeHTML(String(err)) + '</div>';
                elStats.textContent = 'error';
            });
    }

    elApply.addEventListener('click', fetchAndRender);
    elSearch.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') fetchAndRender();
    });
    document.getElementById('archToday').addEventListener('click', function () {
        elFrom.value = todayLocal(); elTo.value = todayLocal(); fetchAndRender();
    });
    document.getElementById('arch7d').addEventListener('click', function () {
        elFrom.value = daysAgoLocal(6); elTo.value = todayLocal(); fetchAndRender();
    });
    document.getElementById('arch30d').addEventListener('click', function () {
        elFrom.value = daysAgoLocal(29); elTo.value = todayLocal(); fetchAndRender();
    });

    // Initial load.
    fetchAndRender();
})();
