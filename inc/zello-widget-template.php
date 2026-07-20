<?php
/**
 * Shared Zello widget template (extracted from index.php 2026-07-08).
 *
 * The floating Zello widget (zello-widget.js) needs #tpl-zello-widget
 * present to init. It was inline in index.php only, so on console.php
 * (and anywhere else) the widget could not open — the console's
 * "Open Zello" launcher fired zello:toggle into the void. This include
 * makes the template available wherever the widget script loads, the
 * same pattern radio-widget.js already uses via inc/navbar.php.
 */
?>
<template id="tpl-zello-widget">
    <div class="zello-widget zello-hidden">
        <div class="zello-header">
            <span class="zello-status-badge status-disconnected"></span>
            <i class="bi bi-megaphone zello-header-icon"></i>
            <span class="zello-header-title">Zello</span>
            <span class="zello-header-channel"></span>
            <div class="zello-header-actions">
                <!-- Phase 101 (Eric beta 2026-07-01) — header toolbar in
                     the Responders-widget style. Archive + Mute live
                     with Minimize + Close. -->
                <a href="zello-archive.php" target="_blank" rel="noopener"
                   class="btn btn-sm btn-outline-secondary" id="zelloArchive"
                   title="Open archive in a new tab" aria-label="Open Zello archive">
                    <i class="bi bi-clock-history"></i>
                </a>
                <!-- GH #55 (Eric 2026-07-04) — Live monitor: when ON, channel
                     audio keeps playing even while the widget is minimized /
                     you navigate to another page. OFF (default) = audio only
                     when the widget is open (prior behavior). Mute (below)
                     silences everything and overrides this. -->
                <button class="btn btn-sm btn-outline-secondary" id="zelloLiveBtn"
                        title="Live monitor off — audio plays only while the widget is open"
                        aria-label="Live monitor off" aria-pressed="false">
                    <i class="bi bi-broadcast"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="zelloMuteBtn"
                        title="Mute incoming audio" aria-label="Mute incoming audio"
                        aria-pressed="false">
                    <i class="bi bi-volume-up-fill"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="zelloMinimize" title="Minimize" aria-label="Minimize Zello">
                    <i class="bi bi-dash"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="zelloClose" title="Close" aria-label="Close Zello">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </div>
        <div class="zello-feed" id="zelloFeed">
            <div class="zello-feed-empty">
                <span><i class="bi bi-megaphone d-block mb-2" style="font-size:1.5rem"></i>No messages yet.<br>Connect to start receiving.</span>
            </div>
        </div>
        <div class="zello-input-row">
            <input type="text" class="form-control form-control-sm" id="zelloTextInput"
                   placeholder="Type a message..." autocomplete="off">
            <button class="btn btn-sm btn-primary" id="zelloSendBtn" title="Send" aria-label="Send message">
                <i class="bi bi-send"></i>
            </button>
        </div>
        <div class="zello-ptt-bar">
            <button class="zello-ptt-btn" id="zelloPttBtn">
                <i class="bi bi-mic-fill me-1"></i> Push to Talk
            </button>
            <div class="zello-ptt-hint">Hold Space or click to talk</div>
        </div>
        <div class="zello-resize-handle"></div>
    </div>
</template>
