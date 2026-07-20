<?php
/**
 * NewUI v4.0 — i18n / Captions Helper
 *
 * Provides translation and caption-override functions.
 *
 * USAGE:
 *   require_once __DIR__ . '/i18n.php';
 *
 *   // In PHP templates:
 *   echo t('nav.dashboard', 'Dashboard');
 *   echo t('btn.save', 'Save');
 *
 *   // In a <script> block, embed all captions for JS use:
 *   <script>var CAPTIONS = <?php echo t_js(); ?>;</script>
 *   // Then in JS: CAPTIONS['nav.dashboard'] || 'Dashboard'
 *
 * LOOKUP ORDER:
 *   1. captions_i18n table for current language
 *   2. captions_i18n table for 'en' fallback (if current lang != 'en')
 *   3. Legacy captions table (capt → repl mapping)
 *   4. The $default parameter passed to t()
 */

/**
 * Detect the current language for this request.
 *
 * Priority (Phase 8b registry-aware):
 *   1. $_SESSION['lang']                — explicit user choice this session
 *   2. user.preferred_lang via $_SESSION['user_id'] — persisted choice
 *   3. Accept-Language HTTP header      — browser preference
 *   4. languages.is_default=1           — install default
 *   5. 'en'                             — hardcoded last-resort fallback
 *
 * Steps 2 and 4 require the Phase 8b registry; both degrade gracefully
 * to skip if the tables are missing (fresh install pre-migration).
 *
 * Returned value is a 2–8 char sanitized code (e.g. 'en', 'de', 'pt-br').
 *
 * @return string
 */
function i18n_lang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }

    // 1. Session override
    if (!empty($_SESSION['lang'])) {
        $lang = _i18n_sanitize_lang($_SESSION['lang']);
        return $lang;
    }

    // 2. User's persisted preference (if logged in and registry-enabled).
    //    Degrades gracefully when the column is absent (pre-8b installs).
    if (!empty($_SESSION['user_id'])) {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        try {
            $pref = db_fetch_value(
                "SELECT u.preferred_lang
                 FROM `{$prefix}user` u
                 LEFT JOIN `{$prefix}languages` l ON l.code = u.preferred_lang
                 WHERE u.id = ?
                   AND u.preferred_lang IS NOT NULL
                   AND u.preferred_lang <> ''
                   AND (l.enabled = 1 OR l.enabled IS NULL)
                 LIMIT 1",
                [(int) $_SESSION['user_id']]
            );
            if ($pref) {
                $lang = _i18n_sanitize_lang($pref);
                return $lang;
            }
        } catch (Exception $e) {
            // user.preferred_lang column missing (pre-8b) — skip silently
        }
    }

    // 3. Accept-Language header (first tag wins)
    if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
        if (preg_match('/^([a-zA-Z]{2})/', $header, $m)) {
            $candidate = strtolower($m[1]);
            // Only accept if registry has it enabled (or registry is missing).
            // Avoid honouring an Accept-Language we can't actually translate to.
            if (_i18n_is_lang_enabled($candidate)) {
                $lang = $candidate;
                return $lang;
            }
        }
    }

    // 4. Install default from registry
    $lang = i18n_default_lang();
    return $lang;
}

/**
 * Return the install default language (the row with is_default=1).
 * Falls back to 'en' if the registry is empty or unreachable.
 *
 * @return string
 */
function i18n_default_lang(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $code = db_fetch_value(
            "SELECT code FROM `{$prefix}languages`
             WHERE is_default = 1 AND enabled = 1 LIMIT 1"
        );
        if ($code) {
            $cached = _i18n_sanitize_lang($code);
            return $cached;
        }
    } catch (Exception $e) {
        // Registry table missing — fall through.
    }
    $cached = 'en';
    return $cached;
}

/**
 * Internal: is a language code enabled in the registry?
 *
 * Returns TRUE when the registry table is missing entirely (pre-8b),
 * so the upgrade is non-breaking — every detected lang is treated as
 * enabled until the admin opens the management UI and starts disabling.
 */
function _i18n_is_lang_enabled(string $code): bool
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $r = db_fetch_one(
            "SELECT enabled FROM `{$prefix}languages` WHERE code = ? LIMIT 1",
            [$code]
        );
        if ($r === null) {
            // Code not in registry — treat as not enabled.
            return false;
        }
        return ((int) $r['enabled']) === 1;
    } catch (Exception $e) {
        // Registry missing entirely — backward-compat: allow.
        return true;
    }
}

/**
 * Sanitize a language code to 2-8 lowercase alphanumeric chars.
 *
 * @param string $input
 * @return string
 */
function _i18n_sanitize_lang(string $input): string
{
    $clean = preg_replace('/[^a-zA-Z0-9\-]/', '', $input);
    $clean = strtolower(substr($clean, 0, 8));
    return $clean !== '' ? $clean : 'en';
}

/**
 * Load all captions into a per-request cache.
 * Returns an associative array: [ lang => [ key => value, ... ], ... ]
 *
 * @return array
 */
function _i18n_load_cache(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // Load from captions_i18n table
    try {
        $rows = db_fetch_all("SELECT `caption_key`, `lang`, `value` FROM `{$prefix}captions_i18n`");
        foreach ($rows as $row) {
            $cache[$row['lang']][$row['caption_key']] = $row['value'];
        }
    } catch (Exception $e) {
        // Table may not exist yet — graceful degradation
    }

    // Load legacy captions table as 'en' fallback layer
    // Only fill keys that are NOT already set from captions_i18n
    try {
        $rows = db_fetch_all("SELECT `capt`, `repl` FROM `{$prefix}captions`");
        if (!isset($cache['_legacy'])) {
            $cache['_legacy'] = [];
        }
        if (!isset($cache['_legacy_ci'])) {
            $cache['_legacy_ci'] = [];
        }
        foreach ($rows as $row) {
            if ($row['capt'] !== '' && $row['repl'] !== '' && $row['repl'] !== $row['capt']) {
                $cache['_legacy'][$row['capt']] = $row['repl'];
                // Issue #42 (a beta tester 2026-07-03): also index by
                // lower-cased key so t() can match on the ENGLISH
                // default when the admin's caption row happens to
                // differ in case ('state' vs 'State', 'zip' vs
                // 'ZIP', etc.). Otherwise a translation only fires
                // when the caption key exactly matches the default
                // text in the t() call.
                $cache['_legacy_ci'][mb_strtolower($row['capt'])] = $row['repl'];
            }
        }
    } catch (Exception $e) {
        // Legacy table may not exist — that is fine
    }

    return $cache;
}

/**
 * Translate a caption key.
 *
 * @param string $key     Caption key (e.g. 'nav.dashboard' or legacy 'Dashboard')
 * @param string $default Fallback text if no translation found
 * @return string
 */
function t(string $key, string $default = ''): string
{
    $cache = _i18n_load_cache();
    $lang  = i18n_lang();

    // 1. Check captions_i18n for current language
    if (isset($cache[$lang][$key])) {
        return $cache[$lang][$key];
    }

    // 2. Fallback to English in captions_i18n (if not already English)
    if ($lang !== 'en' && isset($cache['en'][$key])) {
        return $cache['en'][$key];
    }

    // 3. Check legacy captions table (capt → repl)
    if (isset($cache['_legacy'][$key])) {
        return $cache['_legacy'][$key];
    }
    // Also try the default text as a legacy key
    if ($default !== '' && isset($cache['_legacy'][$default])) {
        return $cache['_legacy'][$default];
    }
    // Issue #42 (a beta tester 2026-07-03): case-insensitive fallback for
    // legacy captions so an admin's row keyed as 'State' still
    // matches a t('field.state', 'State') call whose default is
    // 'State' but whose caption row happens to be 'state' or 'STATE'.
    if (isset($cache['_legacy_ci'])) {
        $keyCi = mb_strtolower($key);
        if (isset($cache['_legacy_ci'][$keyCi])) {
            return $cache['_legacy_ci'][$keyCi];
        }
        if ($default !== '') {
            $defCi = mb_strtolower($default);
            if (isset($cache['_legacy_ci'][$defCi])) {
                return $cache['_legacy_ci'][$defCi];
            }
        }
    }

    // 4. Return the explicit default, or the key itself
    return $default !== '' ? $default : $key;
}

/**
 * Return a JSON-encoded object of all captions for the current language.
 * Suitable for embedding in a <script> tag so JavaScript can use captions.
 *
 * Merges: English base → current language overrides → legacy overrides.
 *
 * @return string JSON string
 */
function t_js(): string
{
    $cache = _i18n_load_cache();
    $lang  = i18n_lang();

    // Start with English base
    $merged = isset($cache['en']) ? $cache['en'] : [];

    // Layer current language on top (if not English)
    if ($lang !== 'en' && isset($cache[$lang])) {
        foreach ($cache[$lang] as $k => $v) {
            $merged[$k] = $v;
        }
    }

    // Layer legacy overrides (lower priority than i18n table)
    if (isset($cache['_legacy'])) {
        foreach ($cache['_legacy'] as $k => $v) {
            if (!isset($merged[$k])) {
                $merged[$k] = $v;
            }
        }
    }

    return json_encode($merged, JSON_UNESCAPED_UNICODE);
}

/**
 * Get a list of language codes the user-facing switcher should show.
 *
 * Phase 8b: reads from the `languages` registry, returning only rows
 * where enabled=1, ordered by sort_order. Falls back to the legacy
 * "DISTINCT lang from captions_i18n" behaviour if the registry is
 * missing entirely (pre-8b installs).
 *
 * @return array e.g. ['en', 'de']
 */
function i18n_available_langs(): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // Try registry first (Phase 8b).
    try {
        $rows = db_fetch_all(
            "SELECT code FROM `{$prefix}languages`
             WHERE enabled = 1
             ORDER BY sort_order, code"
        );
        if (!empty($rows)) {
            $langs = [];
            foreach ($rows as $r) {
                $langs[] = $r['code'];
            }
            return $langs;
        }
        // Empty registry — fall through to legacy behaviour
    } catch (Exception $e) {
        // Registry missing — fall through to legacy behaviour
    }

    // Legacy fallback: infer from captions_i18n
    try {
        $rows = db_fetch_all(
            "SELECT DISTINCT `lang` FROM `{$prefix}captions_i18n` ORDER BY `lang`"
        );
        $langs = [];
        foreach ($rows as $r) {
            $langs[] = $r['lang'];
        }
        return $langs;
    } catch (Exception $e) {
        return ['en'];
    }
}

/**
 * Look up the registry row for a language code.
 *
 * Returns an associative array with keys: code, display_name, native_name,
 * enabled, is_default, sort_order. Or null if the code isn't registered
 * (or the registry table is missing).
 *
 * @param string $code
 * @return array|null
 */
function i18n_language_meta(string $code): ?array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_one(
            "SELECT code, display_name, native_name, enabled, is_default, sort_order
             FROM `{$prefix}languages` WHERE code = ? LIMIT 1",
            [$code]
        );
        return $row ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Return the full enabled-language registry as an array (used by the
 * navbar to bootstrap the switcher with display names).
 *
 * Falls back to a synthesized list (code uppercased) if the registry is
 * missing — keeps the switcher functional on pre-8b installs.
 *
 * @return array of {code, display_name, native_name}
 */
function i18n_language_registry(): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $rows = db_fetch_all(
            "SELECT code, display_name, native_name
             FROM `{$prefix}languages` WHERE enabled = 1
             ORDER BY sort_order, code"
        );
        if (!empty($rows)) {
            return $rows;
        }
    } catch (Exception $e) {
        // Fall through to synthesized list.
    }

    // Synthesize from captions_i18n if the registry is missing.
    $codes = i18n_available_langs();
    $out = [];
    foreach ($codes as $c) {
        $out[] = ['code' => $c, 'display_name' => strtoupper($c), 'native_name' => ''];
    }
    return $out;
}
