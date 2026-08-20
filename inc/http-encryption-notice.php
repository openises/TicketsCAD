<?php
/**
 * Phase 118 (2026-07-24) — "operating without HTTPS" per-admin acknowledgment.
 *
 * When a TicketsCAD install runs over plain HTTP (deliberately, e.g. on a
 * trusted LAN), we don't want to nag on every page load — but we also don't
 * want an administrator to permanently forget that transport encryption is off.
 * The compromise (Eric, 2026-07-24): an ADMIN acknowledges the state with an
 * affirmative click; that quiets the banner for 7 days; after 7 days it returns
 * on the next admin page load and must be re-acknowledged. Per-admin — one
 * admin's acknowledgment does not quiet the banner for another.
 *
 * Non-admins and the pre-auth login page keep the gentler dismissible note from
 * https_warning_banner() (inc/security.php). The real fix — enabling HTTPS —
 * is documented in docs/HTTPS-SETUP.md.
 *
 * Storage: reuses the already-wired per-user JSON store `user_screen_prefs`
 * (inc/screen-prefs.php) under the synthetic screen key HTTP_ENC_ACK_SCREEN, so
 * there is no new table/migration to wire (and thus none to forget). Fails safe:
 * if the write fails, the ack doesn't persist and the banner keeps showing.
 *
 * HTTPS detection is delegated to fe_is_https() (inc/field-encrypt.php), which
 * already honors HTTPS, X-Forwarded-Proto (TLS-terminating proxies), and :443.
 */

require_once __DIR__ . '/field-encrypt.php';   // fe_is_https()
require_once __DIR__ . '/screen-prefs.php';    // prefs_get() / prefs_set()

const HTTP_ENC_ACK_SCREEN = 'security.http_ack';

/** Days an acknowledgment stays valid before the banner returns. */
function http_enc_ttl_days(): int {
    return 7;
}

/**
 * Pure staleness test. An ack is stale (banner should show) when it was never
 * made (0) or is older than the TTL. No DB access — unit-testable in isolation.
 */
function http_enc_is_stale(int $ackedAt): bool {
    if ($ackedAt <= 0) return true;
    return (time() - $ackedAt) >= (http_enc_ttl_days() * 86400);
}

/**
 * Last acknowledgment time (unix seconds) for a user, or 0 if never / unknown.
 */
function http_enc_ack_at(int $userId): int {
    if ($userId <= 0) return 0;
    try {
        $p = prefs_get($userId, HTTP_ENC_ACK_SCREEN);
        return (int) ($p['options']['acked_at'] ?? 0);
    } catch (Throwable $e) {
        return 0; // fail safe → treated as stale → banner shows
    }
}

/**
 * Record an acknowledgment for a user (per-admin). Returns false if it couldn't
 * persist (missing table etc.) — the caller treats that as "still not acked".
 */
function http_enc_record_ack(int $userId, string $ip = ''): bool {
    if ($userId <= 0) return false;
    try {
        return prefs_set($userId, HTTP_ENC_ACK_SCREEN, [
            'options' => [
                'acked_at' => time(),
                'acked_ip' => substr($ip, 0, 45),
            ],
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Should the weekly acknowledge banner be shown to this viewer right now?
 * True only when: NOT on HTTPS, the viewer is an administrator, and their
 * acknowledgment is missing or stale.
 */
function http_enc_should_prompt_admin(int $userId): bool {
    if (fe_is_https()) return false;
    if (!function_exists('is_admin') || !is_admin()) return false;
    return http_enc_is_stale(http_enc_ack_at($userId));
}

/**
 * The banner markup + its small acknowledge handler. Rendered by the navbar in
 * the same banner zone as the password-rotation reminder, and styled to match.
 * $csrf is the session CSRF token (posted to api/http-encryption-ack.php).
 */
function http_enc_ack_banner_html(string $csrf): string {
    $c = htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8');
    ob_start();
    ?>
<div class="alert alert-warning d-flex align-items-center justify-content-between mb-0 rounded-0 py-2 px-3" role="alert" id="httpEncryptionBanner">
    <div class="small">
        <i class="bi bi-shield-lock-fill me-2"></i>
        <strong>This system is running without HTTPS encryption.</strong>
        Traffic between browsers and this server is not encrypted in transit.
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="documentation/?doc=HTTPS-SETUP" target="_blank" rel="noopener" class="btn btn-sm btn-outline-warning">
            <i class="bi bi-book me-1"></i>How to enable HTTPS
        </a>
        <button type="button" class="btn btn-sm btn-warning" id="btnAckHttpEncryption" data-csrf="<?php echo $c; ?>">
            <i class="bi bi-check2-circle me-1"></i>I acknowledge
        </button>
    </div>
</div>
<script>
(function () {
    'use strict';
    var btn = document.getElementById('btnAckHttpEncryption');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var token = btn.getAttribute('data-csrf') || (window.CSRF_TOKEN || '');
        btn.disabled = true;
        fetch('api/http-encryption-ack.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body: JSON.stringify({ csrf_token: token })
        })
        .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
        .then(function (res) {
            if (res.status === 200 && res.body && res.body.success) {
                // Hide via closest('.alert') rather than the id: the a11y
                // skip-target helper can rename the first banner's id, so an
                // id lookup isn't reliable. The button's own .alert ancestor is.
                var b = btn.closest('.alert') || document.getElementById('httpEncryptionBanner');
                if (b) b.classList.add('d-none');
            } else {
                btn.disabled = false;
                if (typeof window.alert === 'function') {
                    window.alert((res.body && res.body.error) || 'Could not record acknowledgment');
                }
            }
        })
        .catch(function () { btn.disabled = false; });
    });
})();
</script>
    <?php
    return (string) ob_get_clean();
}
