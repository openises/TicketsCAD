<?php
/**
 * NewUI v4.0 API — Set Language (Phase 8 i18n)
 *
 * POST /api/set-language.php
 *   Body: { "lang": "de", "csrf_token": "..." }
 *
 * Validates that `lang` is a 2-8 char ASCII identifier AND exists as a
 * configured language in captions_i18n (an admin must have seeded at
 * least one caption row for the lang first — prevents typos from
 * setting an unknown/empty session lang).
 *
 * On success: updates $_SESSION['lang']; the browser typically reloads
 * to re-render every page with the new language. Returns
 * { success: true, lang: "<sanitized>", reload: true }.
 *
 * Lookup priority in inc/i18n.php:i18n_lang():
 *   1. $_SESSION['lang']  ← this endpoint
 *   2. Accept-Language    ← browser
 *   3. 'en'               ← fallback
 *
 * RBAC: any authenticated user can change THEIR OWN session language.
 *       (Not an admin action; no rbac_can() check required.)
 *
 * Audit: logs as `i18n.set_language` so an admin can see who switched.
 *
 * Persistence (Phase 8b): writes the choice to user.preferred_lang so
 * a returning user is greeted in their last-chosen language. login.php
 * seeds $_SESSION['lang'] from that column on successful authentication.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/i18n.php';
require_once __DIR__ . '/../inc/audit.php';

// Suppress display_errors so PHP warnings can't corrupt the JSON body.
ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF — accept either body field or header.
$csrfTok = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!csrf_verify((string) $csrfTok)) {
    json_error('Invalid CSRF token', 403);
}

$raw = isset($input['lang']) ? (string) $input['lang'] : '';
$raw = trim($raw);
if ($raw === '') {
    json_error('Missing "lang" parameter', 400);
}

// Sanitize: 2-8 ASCII lowercase alphanumeric / dash.
$lang = preg_replace('/[^a-zA-Z0-9\-]/', '', strtolower(substr($raw, 0, 8)));
if (!preg_match('/^[a-z0-9]{2}([a-z0-9\-]{0,6})$/', $lang)) {
    json_error('Invalid language code; expected 2-8 ASCII chars', 400);
}

// Phase 8b: enforce registry. Lang must be present AND enabled. Pre-8b
// fallback is still honoured by _i18n_is_lang_enabled() — if the registry
// table is missing entirely, any lang with captions_i18n rows is accepted.
$prefix = $GLOBALS['db_prefix'] ?? '';

$enabled = false;
$registryExists = true;
try {
    $regRow = db_fetch_one(
        "SELECT enabled FROM `{$prefix}languages` WHERE code = ? LIMIT 1",
        [$lang]
    );
    $enabled = $regRow !== null && ((int) $regRow['enabled']) === 1;
} catch (Exception $e) {
    // languages table missing — pre-8b install. Fall back to legacy check.
    $registryExists = false;
}

if (!$registryExists) {
    // Legacy: accept any lang with rows in captions_i18n.
    try {
        $count = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}captions_i18n` WHERE `lang` = ?",
            [$lang]
        );
        $enabled = $count > 0;
    } catch (Exception $e) {
        json_error('Translations table unavailable: ' . $e->getMessage(), 500);
    }
}

if (!$enabled) {
    // Return the available langs so the caller can show a useful error.
    $available = [];
    if ($registryExists) {
        try {
            $rows = db_fetch_all(
                "SELECT code FROM `{$prefix}languages`
                 WHERE enabled = 1 ORDER BY sort_order, code"
            );
            foreach ($rows as $r) $available[] = $r['code'];
        } catch (Exception $e) {
            // ignore
        }
    } else {
        try {
            $rows = db_fetch_all(
                "SELECT DISTINCT `lang` FROM `{$prefix}captions_i18n` ORDER BY `lang`"
            );
            foreach ($rows as $r) $available[] = $r['lang'];
        } catch (Exception $e) {
            // ignore
        }
    }
    json_response([
        'error'     => 'Language "' . $lang . '" is not enabled on this install',
        'available' => $available,
    ], 422);
}

// Commit to session.
$_SESSION['lang'] = $lang;

// Phase 8b: persist to user.preferred_lang so the choice survives logout.
// Best-effort — a missing column (pre-8b install) silently no-ops.
if (!empty($_SESSION['user_id'])) {
    try {
        db_query(
            "UPDATE `{$prefix}user` SET `preferred_lang` = ? WHERE `id` = ?",
            [$lang, (int) $_SESSION['user_id']]
        );
    } catch (Exception $e) {
        // Column missing or DB error — switch still works for the session.
    }
}

// Audit. Best-effort: a logging failure should never block the language
// switch itself, so this is in its own try/catch.
try {
    audit_log(
        'i18n',
        'set_language',
        'user',
        (int) ($_SESSION['user_id'] ?? 0),
        'Switched session language to ' . $lang
    );
} catch (Exception $e) {
    // Swallow — audit failure must not break the user action.
}

json_response([
    'success' => true,
    'lang'    => $lang,
    'reload'  => true,
]);
