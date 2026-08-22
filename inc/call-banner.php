<?php
/**
 * Phase 149 (2026-08-22) — the persistent, page-wide ringing-call banner
 * (spec.md FR-5). Self-contained include, modeled on inc/https-
 * enforcement-notice.php's shape: a container div + one small inline
 * bootstrap script, with the actual state/rendering logic living in
 * assets/js/call-alert.js (loaded globally from navbar.php — see the
 * loadGlobal() call added there — never per-page, matching this
 * project's own documented lesson about SSE-dependent scripts needing
 * global load).
 *
 * On an install with zero pbx_trunks configured (or a user who lacks
 * screen.call_queue), call-alert.js's own initial list fetch 404s/403s
 * harmlessly and the container simply never gains any content — no new
 * UI appears (spec.md FR-29's "fully built, off by default" bar).
 *
 * Wired into inc/navbar.php with:
 *   include_once __DIR__ . '/call-banner.php';
 */
?>
<div id="callAlertBanner" class="d-none" aria-live="polite"></div>
<script>
window.CALL_ALERT_USER_ID   = <?php echo (int) ($_SESSION['user_id'] ?? 0); ?>;
window.CALL_ALERT_USER_NAME = <?php echo json_encode((string) ($_SESSION['user'] ?? $_SESSION['username'] ?? '')); ?>;
window.CALL_ALERT_CSRF      = <?php echo json_encode((string) ($_SESSION['csrf_token'] ?? '')); ?>;
</script>
