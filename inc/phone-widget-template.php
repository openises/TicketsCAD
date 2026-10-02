<?php
/**
 * Phase 153 (2026-09-08) — floating browser phone widget template.
 *
 * Same "external template file, included once from inc/navbar.php" shape
 * as inc/zello-widget-template.php (which itself replaced an inline copy
 * that had drifted out of sync across pages, GH#137) -- so this one is
 * never at risk of the same drift from day one.
 */
?>
<template id="tpl-phone-widget">
    <div class="phone-widget phone-hidden">
        <div class="phone-header">
            <span class="phone-status-badge status-disconnected" title="Registration status"></span>
            <i class="bi bi-telephone-fill phone-header-icon"></i>
            <span class="phone-header-title">Phone</span>
            <span class="phone-header-extension"></span>
            <div class="phone-header-actions">
                <button class="btn btn-sm btn-outline-secondary" id="phoneDetach" title="Detach into its own window" aria-label="Detach Phone into its own window">
                    <i class="bi bi-box-arrow-up-right"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="phoneMinimize" title="Minimize" aria-label="Minimize Phone">
                    <i class="bi bi-dash"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="phoneClose" title="Close" aria-label="Close Phone">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </div>

        <!-- Not bound to any extension yet -- shown instead of everything below. -->
        <div class="phone-unbound-panel d-none" id="phoneUnboundPanel">
            <i class="bi bi-telephone-x d-block mb-2" style="font-size:1.5rem"></i>
            <div class="mb-2">This workstation isn't bound to a SIP extension yet.</div>
            <div class="small text-body-secondary mb-2">Copy the token below and ask an
                administrator to bind it to an extension on the
                <a href="phone-extensions-admin.php">Phone Extensions</a> page.</div>
            <div class="input-group input-group-sm">
                <input type="text" class="form-control form-control-sm font-monospace" id="phoneMyToken" readonly>
                <button class="btn btn-outline-secondary" type="button" id="phoneCopyToken"><i class="bi bi-clipboard"></i></button>
            </div>
        </div>

        <div class="phone-body" id="phoneBody">
            <!-- Incoming call banner -->
            <div class="phone-incoming d-none" id="phoneIncoming">
                <div class="phone-incoming-caller">
                    <i class="bi bi-telephone-inbound-fill me-2"></i>
                    <span id="phoneIncomingFrom">Incoming call…</span>
                </div>
                <div class="phone-incoming-actions">
                    <button class="btn btn-success btn-sm" id="phoneAnswerBtn"><i class="bi bi-telephone-fill me-1"></i>Answer</button>
                    <button class="btn btn-danger btn-sm" id="phoneDeclineBtn"><i class="bi bi-telephone-x-fill me-1"></i>Decline</button>
                </div>
            </div>

            <!-- In-call panel -->
            <div class="phone-incall d-none" id="phoneIncall">
                <div class="phone-incall-peer" id="phoneIncallPeer">—</div>
                <div class="phone-incall-timer" id="phoneIncallTimer">00:00</div>
                <div class="phone-incall-actions">
                    <button class="btn btn-outline-secondary btn-sm" id="phoneMuteCallBtn" title="Mute microphone" aria-pressed="false">
                        <i class="bi bi-mic-fill"></i>
                    </button>
                    <button class="btn btn-danger btn-sm" id="phoneHangupBtn"><i class="bi bi-telephone-x-fill me-1"></i>Hang Up</button>
                </div>
            </div>

            <!-- Dial pad -->
            <div class="phone-dialpad" id="phoneDialpad">
                <input type="text" class="form-control form-control-sm font-monospace text-center mb-2"
                       id="phoneDialInput" placeholder="Enter a number" inputmode="tel">
                <div class="phone-dialpad-grid">
                    <button type="button" class="btn btn-outline-secondary phone-key" data-key="1">1</button>
                    <button type="button" class="btn btn-outline-secondary phone-key" data-key="2">2</button>
                    <button type="button" class="btn btn-outline-secondary phone-key" data-key="3">3</button>
                    <button type="button" class="btn btn-outline-secondary phone-key" data-key="4">4</button>
                    <button type="button" class="btn btn-outline-secondary phone-key" data-key="5">5</button>
                    <button type="button" class="btn btn-outline-secondary phone-key" data-key="6">6</button>
                    <button type="button" class="btn btn-outline-secondary phone-key" data-key="7">7</button>
                    <button type="button" class="btn btn-outline-secondary phone-key" data-key="8">8</button>
                    <button type="button" class="btn btn-outline-secondary phone-key" data-key="9">9</button>
                    <button type="button" class="btn btn-outline-secondary phone-key" data-key="*">*</button>
                    <button type="button" class="btn btn-outline-secondary phone-key" data-key="0">0</button>
                    <button type="button" class="btn btn-outline-secondary phone-key" data-key="#">#</button>
                </div>
                <div class="d-flex gap-2 mt-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" id="phoneBackspaceBtn"><i class="bi bi-backspace"></i></button>
                    <button type="button" class="btn btn-success btn-sm flex-fill" id="phoneCallBtn"><i class="bi bi-telephone-fill me-1"></i>Call</button>
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm w-100 mt-2 phone-general-btn d-none" id="phoneGeneralBtn">
                    <i class="bi bi-broadcast me-1"></i>Call General Number (<span id="phoneGeneralNum"></span>)
                </button>
            </div>
        </div>

        <div class="phone-footer small text-body-secondary" id="phoneFooter">Not registered</div>
        <div class="phone-resize-handle"></div>
    </div>
</template>
