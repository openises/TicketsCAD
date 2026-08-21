/**
 * NewUI v4.0 — Console select/monitor/mute/volume/simulselect PURE LOGIC
 * (Phase 114b3)
 *
 * No DOM, no fetch, no widget references — deliberately, so this file can
 * be unit-tested under Node without a browser (tests/test_console_audio_
 * state.php drives it directly, the same technique test_tile_proxy.php
 * uses for map-prefs.js). assets/js/console-audio.js is the DOM/network
 * glue layer that wraps this and talks to api/console-audio-prefs.php and
 * the zello/radio widget audio hooks.
 *
 * The model (console-designer.md §1 — Eric's Mimer SoftRadio reference):
 *   - Every channel strip has independent state: selected, muted, volume
 *     (0-100), simulselect (multi-TX-set membership).
 *   - SELECT = "this is the channel I'm actively working" -> its audio
 *     goes out at full (volume) level.
 *   - UNSELECTED ("monitored") channels play at a REDUCED level once
 *     ANYTHING on the board is selected — commercial-console select/
 *     monitor semantics. Until the operator selects anything, nothing is
 *     de-prioritized: an untouched console behaves exactly as it did
 *     before this feature existed (gain 1.0 for every unmuted, default-
 *     volume channel). This is deliberate — it means shipping this
 *     feature never silently changes anyone's existing audio level; the
 *     attenuation only ever appears as a direct consequence of an
 *     operator's own explicit "select" action.
 *   - MUTE always forces silence regardless of select state.
 *   - VOLUME is a per-channel ceiling under either state.
 *   - SIMULSELECT is a SEPARATE flag (paging-group membership), not tied
 *     to select/monitor — see simulselectMembers().
 *
 * Non-audio (text-only) channels have no literal "volume" — select/
 * monitor/mute translate into UI PROMINENCE instead (textProminence()).
 * See console.js's renderStrip() for how that maps to CSS.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    // Commercial-console "monitor" attenuation — a monitored (unselected)
    // channel plays at this fraction of its own volume slider once
    // anything on the board is selected. Not yet exposed as a setting;
    // a reasonable, documented constant for this pass.
    var MONITOR_ATTEN = 0.35;

    // mon defaults TRUE — see effectiveGain()'s docblock for why that's
    // what keeps an untouched console's behavior unchanged.
    function defaultState() {
        return { selected: false, mon: true, muted: false, volume: 100, simulselect: false };
    }

    // Coerce/clamp an arbitrary (possibly partial, possibly attacker- or
    // corruption-supplied) object into the canonical shape. Never throws.
    function normalizeState(s) {
        s = s || {};
        var vol = (typeof s.volume === 'number' && isFinite(s.volume)) ? s.volume : 100;
        if (vol < 0) { vol = 0; }
        if (vol > 100) { vol = 100; }
        return {
            selected: !!s.selected,
            mon: (s.mon === undefined) ? true : !!s.mon,
            muted: !!s.muted,
            volume: vol,
            simulselect: !!s.simulselect
        };
    }

    // True when at least one channel in the whole board's state map is
    // currently selected.
    function anySelected(channelsMap) {
        channelsMap = channelsMap || {};
        for (var id in channelsMap) {
            if (Object.prototype.hasOwnProperty.call(channelsMap, id)
                && channelsMap[id] && channelsMap[id].selected) {
                return true;
            }
        }
        return false;
    }

    // The core select/monitor/mute/volume rule. boardHasAnySelected is
    // passed in (rather than recomputed here) so callers can compute it
    // once per board-state change and reuse it across every channel.
    //
    //   muted            -> 0, always (overrides everything else)
    //   selected         -> volume/100 (full, under its own ceiling)
    //   unselected, !mon -> 0 (operator explicitly said "don't monitor
    //                       this while I'm not on it")
    //   unselected, mon, something else selected -> volume/100 * MONITOR_ATTEN
    //   unselected, mon, NOTHING selected anywhere -> volume/100 (full —
    //                       this is the untouched-console default: mon
    //                       defaults true and volume defaults 100, so an
    //                       operator who never touches select/mon/mute/
    //                       volume gets gain 1.0 always, identical to
    //                       before this feature existed)
    function effectiveGain(state, boardHasAnySelected) {
        var s = normalizeState(state);
        if (s.muted) { return 0; }
        var base = s.volume / 100;
        if (s.selected) { return base; }
        if (!s.mon) { return 0; }
        if (boardHasAnySelected) { return base * MONITOR_ATTEN; }
        return base;
    }

    // Non-audio (text-only) strips: the same 3-state model expressed as UI
    // prominence rather than a gain multiplier.
    //   'suppressed' — muted: no flash/pulse/badge-emphasis on new activity
    //   'prominent'  — selected: highlighted, feed auto-opens
    //   'normal'     — everything else (including "board has a selection
    //                  but this channel isn't it" — text channels don't
    //                  lose visibility the way audio loses volume; a
    //                  missed dispatch message is a worse failure mode
    //                  than a quiet radio channel, so no text-channel
    //                  ever goes below 'normal' just because another
    //                  channel is selected)
    function textProminence(state) {
        var s = normalizeState(state);
        if (s.muted) { return 'suppressed'; }
        if (s.selected) { return 'prominent'; }
        return 'normal';
    }

    // Simulselect membership: channel ids (numbers or numeric strings)
    // whose state.simulselect is true AND are present in txCapableIds
    // (only a TX-capable channel can ever key). Filtering here means a
    // channel that lost TX capability (e.g. an admin disabled voice_tx)
    // automatically drops out of an active simulselect set without a
    // separate cleanup step.
    function simulselectMembers(channelsMap, txCapableIds) {
        channelsMap = channelsMap || {};
        txCapableIds = txCapableIds || [];
        var capable = {};
        var i;
        for (i = 0; i < txCapableIds.length; i++) { capable[String(txCapableIds[i])] = true; }
        var out = [];
        for (var id in channelsMap) {
            if (!Object.prototype.hasOwnProperty.call(channelsMap, id)) { continue; }
            if (channelsMap[id] && channelsMap[id].simulselect && capable[String(id)]) {
                out.push(id);
            }
        }
        return out;
    }

    window.ConsoleAudioLogic = {
        MONITOR_ATTEN: MONITOR_ATTEN,
        defaultState: defaultState,
        normalizeState: normalizeState,
        anySelected: anySelected,
        effectiveGain: effectiveGain,
        textProminence: textProminence,
        simulselectMembers: simulselectMembers
    };
})();
