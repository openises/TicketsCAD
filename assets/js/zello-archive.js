/**
 * NewUI v4.0 — Zello archive page (Phase 101, 2026-07-01)
 *
 * Simple filter+render over api/zello-messages.php. Server sorts newest-
 * first; we reverse for chronological display like the widget does.
 *
 * ES5 IIFE style matching the rest of the codebase.
 */
(function () {
    'use strict';

    var typeSel   = document.getElementById('zArchType');
    var dirSel    = document.getElementById('zArchDir');
    var searchEl  = document.getElementById('zArchSearch');
    var limitSel  = document.getElementById('zArchLimit');
    var applyBtn  = document.getElementById('zArchApply');
    var statsEl   = document.getElementById('zArchStats');
    var resultsEl = document.getElementById('zArchResults');
    var modal     = null;   // reused image-expand overlay

    function fetchAndRender() {
        var limit = parseInt(limitSel.value, 10) || 250;
        resultsEl.innerHTML = '<div class="z-arch-loading"><div class="spinner-border spinner-border-sm"></div> Loading messages...</div>';
        fetch('api/zello-messages.php?limit=' + limit, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var msgs = data.messages || [];
                var typeFilter = typeSel.value;
                var dirFilter  = dirSel.value;
                var q          = (searchEl.value || '').trim().toLowerCase();
                var filtered = msgs.filter(function (m) {
                    if (typeFilter && m.message_type !== typeFilter) return false;
                    if (dirFilter  && m.direction    !== dirFilter)  return false;
                    if (q) {
                        var hay = (m.sender_username || '') + ' '
                                + (m.channel || '') + ' '
                                + (m.content || '');
                        if (hay.toLowerCase().indexOf(q) < 0) return false;
                    }
                    return true;
                });
                statsEl.textContent = filtered.length + ' of ' + msgs.length + ' messages';
                if (!filtered.length) {
                    resultsEl.innerHTML = '<div class="z-arch-empty">No matching messages.</div>';
                    return;
                }
                // Server returns newest-first; keep it that way for a
                // scrollback view (opposite of the widget's live-feed
                // ordering). Users scanning history usually want the
                // most-recent row at top.
                var frag = document.createDocumentFragment();
                for (var i = 0; i < filtered.length; i++) {
                    frag.appendChild(renderCard(filtered[i]));
                }
                resultsEl.innerHTML = '';
                resultsEl.appendChild(frag);
            })
            .catch(function (err) {
                resultsEl.innerHTML = '<div class="z-arch-empty">Load failed: ' + (err && err.message ? err.message : 'unknown error') + '</div>';
            });
    }

    function renderCard(m) {
        var card = document.createElement('div');
        card.className = 'z-arch-card';

        var head = document.createElement('div');
        head.className = 'z-arch-head';

        var time = document.createElement('span');
        time.className = 'z-arch-time';
        time.textContent = m.created || '';
        head.appendChild(time);

        var src = document.createElement('span');
        src.className = 'z-arch-src';
        src.textContent = m.sender_display || m.sender_username || 'Unknown';
        head.appendChild(src);

        if (m.channel) {
            var ch = document.createElement('span');
            ch.className = 'z-arch-type';
            ch.textContent = m.channel;
            head.appendChild(ch);
        }

        var dir = document.createElement('span');
        dir.className = 'z-arch-dir ' + (m.direction || 'incoming');
        dir.textContent = m.direction || 'incoming';
        head.appendChild(dir);

        var type = document.createElement('span');
        type.className = 'z-arch-type';
        type.textContent = m.message_type || 'text';
        head.appendChild(type);

        card.appendChild(head);

        // Body: rendered per message_type
        if (m.message_type === 'voice' && m.media_url) {
            var audio = document.createElement('audio');
            audio.className = 'z-arch-audio';
            audio.controls = true;
            audio.src = m.media_url;
            // Phase 101 (Eric beta 2026-07-01) — single-playback lock.
            // When this clip starts, pause every OTHER <audio> on the
            // page so voices don't stack. Mirrors the widget's Phase
            // 99am behavior. The archive-wide pause loop is done
            // per-play instead of a global captured listener so
            // pausing THIS clip doesn't fire the loop against itself
            // (avoids the fire-back cascade).
            audio.addEventListener('play', function () {
                var others = document.querySelectorAll('audio.z-arch-audio');
                for (var i = 0; i < others.length; i++) {
                    if (others[i] !== audio && !others[i].paused) {
                        others[i].pause();
                    }
                }
            });
            card.appendChild(audio);
        } else if (m.message_type === 'image' && m.media_url) {
            var img = document.createElement('img');
            img.className = 'z-arch-img';
            img.src = m.media_url;
            img.alt = 'Image from ' + (m.sender_username || 'Zello');
            img.title = 'Click to expand';
            img.addEventListener('click', function () { expandImage(m.media_url); });
            card.appendChild(img);
        } else {
            var body = document.createElement('div');
            var text = (m.content || '').trim();
            body.className = 'z-arch-body' + (text ? '' : ' empty');
            body.textContent = text || '(no content)';
            card.appendChild(body);
        }
        return card;
    }

    function expandImage(url) {
        if (!url) return;
        if (!modal) {
            modal = document.createElement('div');
            modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;'
                + 'background:rgba(0,0,0,0.85);display:flex;align-items:center;'
                + 'justify-content:center;z-index:100050;cursor:zoom-out;';
            modal.addEventListener('click', function () { document.body.removeChild(modal); modal = null; });
            document.addEventListener('keydown', function onKey(e) {
                if (e.key === 'Escape' && modal) {
                    document.body.removeChild(modal); modal = null;
                    document.removeEventListener('keydown', onKey);
                }
            });
        }
        modal.innerHTML = '';
        var big = document.createElement('img');
        big.src = url;
        big.style.cssText = 'max-width:95vw;max-height:95vh;border-radius:6px;'
            + 'box-shadow:0 10px 40px rgba(0,0,0,0.6);';
        modal.appendChild(big);
        document.body.appendChild(modal);
    }

    if (applyBtn) applyBtn.addEventListener('click', fetchAndRender);
    if (searchEl) searchEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') fetchAndRender();
    });

    fetchAndRender();
})();
