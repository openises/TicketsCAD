<?php
/**
 * NewUI v4.0 - Agency logo and branding (GH#142, Phase 155).
 *
 * One library behind every branding surface: the login card, the navbar
 * (opt-in), the print letterhead on in-app pages and reports, the ICS form
 * print/PDF, and the public incident board. Spec:
 * specs/phase-155-community-backlog/142-agency-logo-and-143-recheck.md Part B.
 * Operator guide: docs/AGENCY-BRANDING.md.
 *
 * -- Where the image lives ------------------------------------------------
 *
 * In the database (`branding_logos`, base64 text in a MEDIUMTEXT column), and
 * NOWHERE ELSE. No file is ever written, so there is no path to traverse, no
 * extension to derive, nothing for a web server to execute, no directory
 * permission race, no new Docker volume, no served-directory rule to keep in
 * sync, and the logo is part of every backup and restore automatically (the
 * backup dumps every base table). tests/test_gh142_branding_nofs.php
 * tokenizes the branding files and fails on any filesystem-write call, which
 * is what turns "nothing touches the disk" into a verified claim.
 *
 * -- What is accepted -----------------------------------------------------
 *
 * Raster only. PNG and JPEG always; WebP only when this PHP can decode it
 * (the shipped Docker image builds GD without WebP). SVG is refused: it can
 * carry script, and a "allow SVG" switch would be a security control whose
 * only effect is making a mistake possible. GIF, ICO, PDF, animated PNG and
 * animated WebP are refused too. The upload pipeline never trusts the client's
 * file name or MIME type: the bytes are sniffed three independent ways (own
 * magic bytes, getimagesizefromstring, finfo) and must agree, a header-only
 * decompression-bomb guard runs BEFORE any decode, and (when GD is present)
 * the image is decoded and RE-ENCODED, which strips EXIF/GPS, ICC and XMP and
 * discards any trailing bytes a polyglot file carries.
 *
 * -- How a logo is chosen -------------------------------------------------
 *
 * Scope is an organization id (0 = install-wide) plus a variant: `light`
 * (for light backgrounds, required) and `dark` (optional). Inheritance walks UP
 * the organization tree (depth cap 8, cycle guard) and ends at the install-wide
 * logo. The scope is chosen by the LIGHT logo; the dark variant is then read
 * from that same scope only, so one organization's identity is never mixed with
 * another's. Choosing which logo to DISPLAY may use the session's active
 * organization; deciding who may CHANGE a logo never does (see
 * branding_resolve_caller_org_id()).
 *
 * Every helper here is written to NEVER throw into a page: a missing table (an
 * install mid-upgrade), a database error or a garbage setting value all
 * degrade to "no logo", which renders today's markup unchanged.
 */

if (!defined('BRANDING_MAX_UPLOAD_BYTES')) {
    define('BRANDING_MAX_UPLOAD_BYTES', 2 * 1024 * 1024);   // raw upload
    define('BRANDING_MAX_STORED_BYTES', 256 * 1024);        // after processing
    define('BRANDING_MAX_PIXELS', 16000000);                // width * height
    define('BRANDING_MAX_EDGE', 6000);                      // either edge
    define('BRANDING_DOWNSCALE_EDGE', 1200);                // long edge kept
    define('BRANDING_MAX_ORG_DEPTH', 8);                    // parent walk cap
    define('BRANDING_LOGO_RATE_LIMIT', 240);                // per IP ...
    define('BRANDING_LOGO_RATE_WINDOW', 60);                // ... per window (s)
}

// ─────────────────────────────────────────────────────────────────────────
// Settings
// ─────────────────────────────────────────────────────────────────────────

/**
 * The eleven administrator-configurable settings: type, allowed values and
 * default. This ONE array is the single definition the migration seeds from,
 * the reader validates against, the admin endpoint writes through, and the
 * admin page renders its controls from. The generic Settings API can write any
 * `settings` row, so the writer is not the only gate: every value is also
 * validated at READ time and an unknown value means "use the default".
 *
 * Sizes are enumerations, never pixel numbers, on purpose: nothing numeric
 * reaches a style attribute, so there is no injection surface and no unusable
 * extreme.
 *
 * @return array<string, array{type:string, default:string, values?:array<int,string>, max?:int}>
 */
function branding_setting_definitions(): array
{
    return [
        'branding_login'          => ['type' => 'bool', 'default' => '1'],
        'branding_navbar'         => ['type' => 'enum', 'default' => 'product', 'values' => ['product', 'agency']],
        'branding_print'          => ['type' => 'bool', 'default' => '1'],
        'branding_public_board'   => ['type' => 'bool', 'default' => '1'],
        'branding_org_logos'      => ['type' => 'bool', 'default' => '1'],
        'branding_dark_fallback'  => ['type' => 'enum', 'default' => 'plate', 'values' => ['plate', 'as_is']],
        'branding_login_size'     => ['type' => 'enum', 'default' => 'medium', 'values' => ['small', 'medium', 'large']],
        'branding_print_size'     => ['type' => 'enum', 'default' => 'medium', 'values' => ['small', 'medium', 'large']],
        'branding_print_align'    => ['type' => 'enum', 'default' => 'center', 'values' => ['left', 'center', 'right']],
        'branding_print_banner'   => ['type' => 'enum', 'default' => 'replace', 'values' => ['replace', 'keep']],
        'branding_logo_alt'       => ['type' => 'text', 'default' => '', 'max' => 100],
    ];
}

/**
 * Pure: turn a raw stored value into a valid one for $name, or the default.
 * Never throws. Unknown setting names return ''.
 *
 * @param mixed $raw
 */
function branding_normalize_setting(string $name, $raw): string
{
    $defs = branding_setting_definitions();
    if (!isset($defs[$name])) {
        return '';
    }
    $def = $defs[$name];
    if ($raw === false || $raw === null) {
        return $def['default'];
    }
    if (!is_scalar($raw)) {
        return $def['default'];
    }
    $value = (string) $raw;

    switch ($def['type']) {
        case 'bool':
            return ($value === '1' || $value === '0') ? $value : $def['default'];
        case 'enum':
            return in_array($value, $def['values'], true) ? $value : $def['default'];
        case 'text':
            // Control characters and markup delimiters are refused outright
            // rather than escaped later: the alt text lands in an attribute on
            // five surfaces and in a JSON block inside a script element.
            $value = trim($value);
            if ($value === '') {
                return $def['default'];
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
                return $def['default'];
            }
            if (function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') > $def['max'] : strlen($value) > $def['max']) {
                return $def['default'];
            }
            return $value;
    }
    return $def['default'];
}

/** The validated value of one branding setting (default when absent/invalid). */
function branding_setting(string $name): string
{
    $defs = branding_setting_definitions();
    if (!isset($defs[$name])) {
        return '';
    }
    $raw = false;
    try {
        if (function_exists('get_variable')) {
            $raw = get_variable($name);
        }
    } catch (Throwable $e) {
        error_log('[branding] settings read failed: ' . $e->getMessage());
    }
    return branding_normalize_setting($name, $raw);
}

/** @return array<string,string> every branding setting, validated. */
function branding_all_settings(): array
{
    $out = [];
    foreach (array_keys(branding_setting_definitions()) as $name) {
        $out[$name] = branding_setting($name);
    }
    return $out;
}

/** Pixel height for an enumerated size, per context (CSS carries the same numbers). */
function branding_size_px(string $context, string $size): int
{
    $table = [
        'login' => ['small' => 48, 'medium' => 72, 'large' => 96],
        'print' => ['small' => 36, 'medium' => 60, 'large' => 84],
    ];
    return $table[$context][$size] ?? $table[$context]['medium'] ?? 60;
}

// ─────────────────────────────────────────────────────────────────────────
// Per-request cache (a global so tests can reset it)
// ─────────────────────────────────────────────────────────────────────────

function branding_reset_cache(): void
{
    $GLOBALS['__branding_cache'] = [];
}

/** @return mixed|null */
function _branding_cache_get(string $key)
{
    return $GLOBALS['__branding_cache'][$key] ?? null;
}

/** @param mixed $value */
function _branding_cache_set(string $key, $value): void
{
    if (!isset($GLOBALS['__branding_cache']) || !is_array($GLOBALS['__branding_cache'])) {
        $GLOBALS['__branding_cache'] = [];
    }
    $GLOBALS['__branding_cache'][$key] = $value;
}

/**
 * Does branding_logos exist on this install? Cached per request. When it does
 * not (migration not run yet) every reader returns "no logo".
 */
function branding_table_exists(): bool
{
    $cached = _branding_cache_get('table_exists');
    if ($cached !== null) {
        return (bool) $cached;
    }
    $exists = false;
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $exists = (bool) db_fetch_value(
            "SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
            [$prefix . 'branding_logos']
        );
    } catch (Throwable $e) {
        $exists = false;
    }
    _branding_cache_set('table_exists', $exists ? 1 : 0);
    return $exists;
}

// ─────────────────────────────────────────────────────────────────────────
// Resolution
// ─────────────────────────────────────────────────────────────────────────

/** A stored asset key is exactly 32 lowercase hex characters, never anything else. */
function branding_valid_key($key): bool
{
    // \z, not $: PCRE's $ also matches before a trailing newline.
    return is_string($key) && preg_match('/^[a-f0-9]{32}\z/', $key) === 1;
}

/**
 * A fresh random asset key: 32 lowercase hex characters (124 bits of randomness
 * plus a fixed-class first character).
 *
 * The FIRST character is never a digit or `e`, so the key can never satisfy PHP's
 * is_numeric(). Until Phase 155 inc/backup.php wrote any is_numeric() value
 * UNQUOTED into the dump, so a key such as 12345e678... was restored as a float
 * and the logo vanished after a restore. The dump now quotes by the column's
 * type (tests/test_backup_value_fidelity.php); the leading letter stays as a
 * second layer, and keeps a key unambiguous to anything reading it bare.
 *
 */
function branding_new_asset_key(): string
{
    $lead = ['a', 'b', 'c', 'd', 'f'][random_int(0, 4)];
    return $lead . substr(bin2hex(random_bytes(16)), 1);
}

/** The relative serving URL (relative so a subdirectory install keeps working). */
function branding_logo_url(string $key): string
{
    return 'api/branding-logo.php?k=' . $key;
}

/**
 * The organization chain used for inheritance: the organization itself, then
 * each parent upward. Depth-capped and cycle-guarded; an unknown organization
 * yields just itself.
 *
 * @return int[]
 */
function branding_org_chain(int $orgId): array
{
    if ($orgId <= 0) {
        return [];
    }
    $cached = _branding_cache_get('chain:' . $orgId);
    if (is_array($cached)) {
        return $cached;
    }
    $chain = [];
    $seen  = [];
    $cur   = $orgId;
    try {
        for ($i = 0; $i < BRANDING_MAX_ORG_DEPTH && $cur > 0 && !isset($seen[$cur]); $i++) {
            $chain[]    = $cur;
            $seen[$cur] = true;
            $parent = db_fetch_value(
                "SELECT `parent_org_id` FROM " . db_table('organizations') . " WHERE `id` = ?",
                [$cur]
            );
            $cur = ($parent === false || $parent === null) ? 0 : (int) $parent;
        }
    } catch (Throwable $e) {
        // keep whatever was walked so far
    }
    _branding_cache_set('chain:' . $orgId, $chain);
    return $chain;
}

/** Is there at least one organization-level logo row at all? (cached) */
function branding_has_org_rows(): bool
{
    $cached = _branding_cache_get('has_org_rows');
    if ($cached !== null) {
        return (bool) $cached;
    }
    $has = false;
    try {
        $has = (bool) db_fetch_value(
            "SELECT 1 FROM " . db_table('branding_logos') . " WHERE `org_id` > 0 LIMIT 1"
        );
    } catch (Throwable $e) {
        $has = false;
    }
    _branding_cache_set('has_org_rows', $has ? 1 : 0);
    return $has;
}

/**
 * Resolve which scope's logo applies for $orgId: the organization, each parent
 * up the chain (when organization logos are switched on), then install-wide.
 * The scope is chosen by its LIGHT logo; the dark variant is read from that
 * same scope only. METADATA ONLY: this never selects data_b64.
 *
 * @return array{scope:string, org_id:int, light:array, dark:?array}|null
 */
function branding_resolve_scope(?int $orgId = null): ?array
{
    if (!branding_table_exists()) {
        return null;
    }
    $orgId  = (int) ($orgId ?? 0);
    $useOrg = ($orgId > 0 && branding_setting('branding_org_logos') === '1' && branding_has_org_rows());
    $cacheKey = 'scope:' . ($useOrg ? $orgId : 0);
    $cached = _branding_cache_get($cacheKey);
    if ($cached !== null) {
        return $cached === false ? null : $cached;
    }

    $scopes = $useOrg ? branding_org_chain($orgId) : [];
    $scopes[] = 0;

    $result = null;
    try {
        $in   = implode(',', array_fill(0, count($scopes), '?'));
        $rows = db_fetch_all(
            "SELECT `id`, `org_id`, `variant`, `asset_key`, `mime`, `width`, `height`
               FROM " . db_table('branding_logos') . "
              WHERE `org_id` IN ({$in})",
            $scopes
        );
        $byScope = [];
        foreach ($rows as $r) {
            if (!branding_valid_key($r['asset_key'])) {
                continue;
            }
            $byScope[(int) $r['org_id']][(string) $r['variant']] = [
                'id'     => (int) $r['id'],
                'key'    => (string) $r['asset_key'],
                'mime'   => (string) $r['mime'],
                'width'  => (int) $r['width'],
                'height' => (int) $r['height'],
                'org_id' => (int) $r['org_id'],
                'scope'  => ((int) $r['org_id'] === 0) ? 'install' : 'org',
            ];
        }
        foreach ($scopes as $sid) {
            if (isset($byScope[$sid]['light'])) {
                $result = [
                    'scope'  => $byScope[$sid]['light']['scope'],
                    'org_id' => $sid,
                    'light'  => $byScope[$sid]['light'],
                    'dark'   => $byScope[$sid]['dark'] ?? null,
                ];
                break;
            }
        }
    } catch (Throwable $e) {
        error_log('[branding] resolve failed: ' . $e->getMessage());
        $result = null;
    }
    _branding_cache_set($cacheKey, $result === null ? false : $result);
    return $result;
}

/**
 * Metadata for one variant of the logo that applies to $orgId, or null.
 * Returns key, mime, width, height, scope ('org'|'install') and org_id.
 *
 * @return array{key:string,mime:string,width:int,height:int,scope:string,org_id:int}|null
 */
function branding_resolve(?int $orgId = null, string $variant = 'light'): ?array
{
    $scope = branding_resolve_scope($orgId);
    if ($scope === null) {
        return null;
    }
    return $variant === 'dark' ? $scope['dark'] : $scope['light'];
}

/**
 * The organization whose logo to DISPLAY for the signed-in viewer: the
 * session's active organization, or 0 (install-wide) when there is none. This
 * deliberately does NOT call org_user_home_id(), which returns 1 when unset and
 * would show organization 1's logo to a global-scope user. Showing a logo is
 * not an authorization decision; changing one is (branding_resolve_caller_org_id).
 */
function branding_display_org_id(): int
{
    return (int) ($_SESSION['active_org_id'] ?? 0);
}

/** The alt text for every logo image. */
function branding_logo_alt(): string
{
    $alt = branding_setting('branding_logo_alt');
    if ($alt !== '') {
        return $alt;
    }
    return function_exists('t') ? (string) t('branding.logo_alt', 'Agency logo') : 'Agency logo';
}

/**
 * Escaped <img> markup for a surface, or '' when no logo applies. Emits the
 * light image, the dark image when one exists, or (no dark image and
 * branding_dark_fallback = plate) the light image on a white plate so a dark
 * logo stays legible in dark theme. CSS (assets/css/branding.css) shows the
 * right one for the current data-bs-theme.
 *
 * $surface: 'login' | 'navbar' | 'public'
 */
function branding_img_html(string $surface, ?int $orgId = null): string
{
    $scope = branding_resolve_scope($orgId);
    if ($scope === null) {
        return '';
    }
    $light = $scope['light'];
    $dark  = $scope['dark'];

    if ($surface === 'login') {
        $sizeClass = 'branding-size-login-' . branding_setting('branding_login_size');
    } elseif ($surface === 'navbar') {
        $sizeClass = 'branding-size-navbar';
    } else {
        $sizeClass = 'branding-size-public';
    }
    $alt = e(branding_logo_alt());

    $lightClass = 'branding-logo branding-logo-light ' . $sizeClass;
    if ($dark !== null) {
        $lightClass .= ' branding-has-dark';
    } elseif (branding_setting('branding_dark_fallback') === 'plate') {
        $lightClass .= ' branding-logo-plate';
    }
    $html = '<img class="' . $lightClass . '" src="' . e(branding_logo_url($light['key'])) . '"'
          . ' alt="' . $alt . '" width="' . (int) $light['width'] . '" height="' . (int) $light['height'] . '">';
    if ($dark !== null) {
        $html .= '<img class="branding-logo branding-logo-dark ' . $sizeClass . '" src="'
              . e(branding_logo_url($dark['key'])) . '" alt="' . $alt . '"'
              . ' width="' . (int) $dark['width'] . '" height="' . (int) $dark['height'] . '">';
    }
    return $html;
}

/**
 * The login card's logo markup: install-wide only (nothing is known about the
 * visitor before sign-in), and only when branding_login is on. '' keeps the
 * Bootstrap glyph.
 */
function branding_login_logo_html(): string
{
    if (branding_setting('branding_login') !== '1') {
        return '';
    }
    return branding_img_html('login', 0);
}

/**
 * The navbar brand mark in `agency` mode, or '' (keep the product mark) in
 * `product` mode and whenever the viewer has no logo to show.
 */
function branding_navbar_brand_html(): string
{
    if (branding_setting('branding_navbar') !== 'agency') {
        return '';
    }
    return branding_img_html('navbar', branding_display_org_id());
}

/**
 * The print letterhead configuration, or null when printing branding is off or
 * no logo applies. Light variant only (paper is white).
 *
 * @return array{src:string,alt:string,size:string,align:string,banner:string,w:int,h:int}|null
 */
function branding_print_config(?int $orgId = null): ?array
{
    if (!branding_table_exists() || branding_setting('branding_print') !== '1') {
        return null;
    }
    $scope = branding_resolve_scope($orgId ?? branding_display_org_id());
    if ($scope === null) {
        return null;
    }
    return [
        'src'    => branding_logo_url($scope['light']['key']),
        'alt'    => branding_logo_alt(),
        'size'   => branding_setting('branding_print_size'),
        'align'  => branding_setting('branding_print_align'),
        'banner' => branding_setting('branding_print_banner'),
        'w'      => (int) $scope['light']['width'],
        'h'      => (int) $scope['light']['height'],
    ];
}

/**
 * The <script type="application/json"> block the letterhead script reads, or ''.
 * Encoded with every HEX flag so a hostile alt text can never close the element.
 */
function branding_print_config_script(?int $orgId = null): string
{
    $cfg = branding_print_config($orgId);
    if ($cfg === null) {
        return '';
    }
    $json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if (!is_string($json)) {
        return '';
    }
    return '<script type="application/json" id="brandingConfig">' . $json . '</script>';
}

/**
 * The public board's logo URL: install-wide for the shared board, or the
 * organization's own (inheriting upward) for an org board. null when branding
 * for the public board is off or no logo applies.
 */
function branding_public_logo_url(?int $orgId = null): ?string
{
    if (!branding_table_exists() || branding_setting('branding_public_board') !== '1') {
        return null;
    }
    $meta = branding_resolve($orgId, 'light');
    return $meta === null ? null : branding_logo_url($meta['key']);
}

// ─────────────────────────────────────────────────────────────────────────
// ICS letterhead (a data: URI, so "Save as PDF" and the 500 ms print timer in
// assets/js/ics-forms.js never race a freshly requested image)
// ─────────────────────────────────────────────────────────────────────────

/** Owning organization of an incident (ticket.org_id), 0 when unset or on error. */
function branding_incident_org_id(int $ticketId): int
{
    if ($ticketId <= 0) {
        return 0;
    }
    try {
        return (int) db_fetch_value(
            "SELECT `org_id` FROM " . db_table('ticket') . " WHERE `id` = ?",
            [$ticketId]
        );
    } catch (Throwable $e) {
        return 0;   // ticket.org_id is added lazily on some installs
    }
}

/**
 * The stored image as a data: URI. The only readers of data_b64 are this and
 * api/branding-logo.php, so page renders never pull up to 342 KB a row. The
 * bytes are re-hashed against the stored sha256 before use.
 */
function branding_data_uri(array $meta): ?string
{
    if (!branding_valid_key($meta['key'] ?? null)) {
        return null;
    }
    try {
        $row = db_fetch_one(
            "SELECT `mime`, `sha256`, `data_b64` FROM " . db_table('branding_logos') . " WHERE `asset_key` = ?",
            [$meta['key']]
        );
    } catch (Throwable $e) {
        return null;
    }
    if (!$row || !in_array($row['mime'], ['image/png', 'image/jpeg'], true)) {
        return null;
    }
    $b64 = (string) $row['data_b64'];
    if ($b64 === '' || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $b64) !== 1) {
        return null;
    }
    $bin = base64_decode($b64, true);
    if ($bin === false || !hash_equals((string) $row['sha256'], hash('sha256', $bin))) {
        return null;
    }
    return 'data:' . $row['mime'] . ';base64,' . $b64;
}

/**
 * Letterhead for an ICS form's print/PDF document. $row is the ics_forms row.
 * Organization: the incident's owning organization, falling back to the
 * viewer's, then the install-wide logo. Returns null (the document is left
 * exactly as it was) when printing branding is off or no logo applies.
 *
 * @return array{html:string, footer_replace:bool}|null
 */
function branding_ics_letterhead(array $row): ?array
{
    if (!branding_table_exists() || branding_setting('branding_print') !== '1') {
        return null;
    }
    $orgId = 0;
    if (!empty($row['incident_id'])) {
        $orgId = branding_incident_org_id((int) $row['incident_id']);
    }
    if ($orgId <= 0) {
        $orgId = branding_display_org_id();
    }
    $scope = branding_resolve_scope($orgId);
    if ($scope === null) {
        return null;
    }
    $uri = branding_data_uri($scope['light']);
    if ($uri === null) {
        return null;
    }
    $px = branding_size_px('print', branding_setting('branding_print_size'));
    $justify = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][branding_setting('branding_print_align')] ?? 'center';
    $html = '<style>.bl-lh{display:flex;justify-content:' . $justify . ';margin:0 0 10px;padding-bottom:6px;border-bottom:1px solid currentColor}'
          . '.bl-lh img{max-height:' . $px . 'px;max-width:100%;width:auto;height:auto}</style>'
          . '<div class="bl-lh"><img src="' . $uri . '" alt="' . e(branding_logo_alt()) . '"></div>';
    return ['html' => $html, 'footer_replace' => branding_setting('branding_print_banner') === 'replace'];
}

// ─────────────────────────────────────────────────────────────────────────
// Upload validation and normalisation (pure: takes bytes, returns bytes)
// ─────────────────────────────────────────────────────────────────────────

/** Is GD usable here? Absence is a REPORTED, safe state, never an error. */
function branding_gd_available(): bool
{
    return extension_loaded('gd') && function_exists('imagecreatefromstring') && function_exists('imagepng');
}

/**
 * The MIME types this server will accept: PNG and JPEG always, WebP only when
 * GD can decode it.
 *
 * @return string[]
 */
function branding_accepted_types(?bool $gd = null): array
{
    $gd = $gd ?? branding_gd_available();
    $types = ['image/png', 'image/jpeg'];
    if ($gd && function_exists('imagecreatefromwebp')) {
        $types[] = 'image/webp';
    }
    return $types;
}

/** A refusal, in the one shape every caller handles. */
function _branding_refuse(string $code, string $message): array
{
    return ['ok' => false, 'code' => $code, 'message' => $message];
}

/**
 * PNG chunk walk up to and including IEND. null when the file is not a
 * well-formed PNG (bad signature, truncated chunk, no IEND). Bytes after IEND
 * are NOT part of the image and are what a polyglot hides in.
 *
 * @return array<int, array{0:string,1:int,2:int}>|null  [type, offset, length]
 */
function branding_png_chunks(string $b): ?array
{
    if (substr($b, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        return null;
    }
    $n = strlen($b);
    $pos = 8;
    $chunks = [];
    while ($pos + 12 <= $n && count($chunks) < 4096) {
        $len  = unpack('N', substr($b, $pos, 4))[1];
        $type = substr($b, $pos + 4, 4);
        if ($len > $n - $pos - 12) {
            return null;
        }
        $chunks[] = [$type, $pos, $len];
        $pos += 12 + $len;
        if ($type === 'IEND') {
            return $chunks;
        }
    }
    return null;
}

/** Animated PNG: an `acTL` chunk before the image data. */
function branding_png_is_animated(string $b): bool
{
    $chunks = branding_png_chunks($b);
    if ($chunks === null) {
        return false;
    }
    foreach ($chunks as $c) {
        if ($c[0] === 'acTL') {
            return true;
        }
    }
    return false;
}

/** Animated WebP: the VP8X animation flag, or an ANIM/ANMF chunk. */
function branding_webp_is_animated(string $b): bool
{
    if (substr($b, 0, 4) !== 'RIFF' || substr($b, 8, 4) !== 'WEBP') {
        return false;
    }
    $n = strlen($b);
    $pos = 12;
    for ($i = 0; $i < 4096 && $pos + 8 <= $n; $i++) {
        $fourcc = substr($b, $pos, 4);
        $size   = unpack('V', substr($b, $pos + 4, 4))[1];
        if ($fourcc === 'ANIM' || $fourcc === 'ANMF') {
            return true;
        }
        if ($fourcc === 'VP8X' && $pos + 9 <= $n && (ord($b[$pos + 8]) & 0x02)) {
            return true;
        }
        $pos += 8 + $size + ($size & 1);
    }
    return false;
}

/** The format our OWN magic-byte check sees, independent of every library. */
function branding_magic_mime(string $b): ?string
{
    if (substr($b, 0, 8) === "\x89PNG\r\n\x1a\n") {
        return 'image/png';
    }
    if (substr($b, 0, 3) === "\xFF\xD8\xFF") {
        return 'image/jpeg';
    }
    if (substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    return null;
}

/** Does this look like SVG (or XML wrapping SVG), whatever it claims to be? */
function branding_looks_like_svg(string $b): bool
{
    $head = strtolower(substr(ltrim($b, "\xEF\xBB\xBF \t\r\n"), 0, 4096));
    if (strpos($head, '<svg') !== false) {
        return true;
    }
    return strpos($head, '<?xml') === 0 && strpos(strtolower(substr($b, 0, 65536)), '<svg') !== false;
}

/** PHP's memory_limit in bytes, or -1 for unlimited. */
function branding_memory_limit_bytes(): int
{
    $v = trim((string) ini_get('memory_limit'));
    if ($v === '' || $v === '-1') {
        return -1;
    }
    $n = (int) $v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;   // fall through
        case 'm': $n *= 1024;   // fall through
        case 'k': $n *= 1024;
    }
    return $n;
}

/** Could decoding a $w x $h image fit in the memory this request has left? */
function branding_memory_headroom_ok(int $w, int $h): bool
{
    $limit = branding_memory_limit_bytes();
    if ($limit < 0) {
        return true;
    }
    $need  = $w * $h * 5;   // 4 bytes per truecolor pixel plus working copy slack
    $avail = $limit - memory_get_usage(true);
    return $need < (int) ($avail * 0.8);
}

/**
 * Validate and normalise an uploaded logo. Pure: bytes in, bytes out, nothing
 * touches the disk or the database.
 *
 * Order matters and is the security design:
 *   1 size cap  2 sniff three ways (own magic, getimagesizefromstring, finfo)
 *   3 refuse SVG/GIF/other  4 refuse animated PNG/WebP  5 header-only
 *   decompression-bomb guard BEFORE any decode  6 (GD) decode + downscale +
 *   RE-ENCODE, which strips every metadata block and any trailing bytes; a
 *   decode failure is a refusal and the original bytes are NEVER stored
 *   7 (no GD) PNG/JPEG already validated stored as-is, PNG cut at IEND, with a
 *   warning  8 stored-size cap.
 *
 * Options (all optional, used by tests): 'force_no_gd' => bool.
 *
 * @return array{ok:true, bytes:string, mime:string, width:int, height:int, sha256:string, warnings:string[]}
 *       |array{ok:false, code:string, message:string}
 */
function branding_process_upload(string $bytes, array $opts = []): array
{
    $size = strlen($bytes);
    if ($size === 0) {
        return _branding_refuse('empty', 'The uploaded file is empty.');
    }
    if ($size > BRANDING_MAX_UPLOAD_BYTES) {
        return _branding_refuse('too_large',
            'The file is larger than ' . (BRANDING_MAX_UPLOAD_BYTES / 1048576) . ' MB. Use a smaller image.');
    }

    $gd       = empty($opts['force_no_gd']) && branding_gd_available();
    $accepted = branding_accepted_types($gd);

    if (branding_looks_like_svg($bytes)) {
        return _branding_refuse('svg',
            'SVG images are not accepted because they can carry script. Export your logo as a PNG and upload that.');
    }

    $magic = branding_magic_mime($bytes);
    $info  = @getimagesizefromstring($bytes);
    if ($magic === null || $info === false || empty($info[0]) || empty($info[1])) {
        return _branding_refuse('not_image',
            'That file is not a PNG, JPEG or WebP image. Export your logo as a PNG and upload that.');
    }
    $sniffed = (string) ($info['mime'] ?? '');
    $finfoMime = null;
    if (class_exists('finfo')) {
        $finfoMime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    }
    if ($sniffed !== $magic || ($finfoMime !== null && $finfoMime !== $magic)) {
        return _branding_refuse('type_mismatch',
            'The file contents do not match a single image format, so it was refused.');
    }
    if (!in_array($magic, $accepted, true)) {
        if ($magic === 'image/webp') {
            return _branding_refuse('webp_unsupported',
                'This server cannot read WebP images. Export your logo as a PNG and upload that.');
        }
        return _branding_refuse('bad_type', 'Only PNG and JPEG images are accepted.');
    }

    $w = (int) $info[0];
    $h = (int) $info[1];

    // Animated images and truncated PNGs are refused before anything decodes them.
    if ($magic === 'image/png') {
        if (branding_png_chunks($bytes) === null) {
            return _branding_refuse('corrupt', 'The PNG is damaged or truncated.');
        }
        if (branding_png_is_animated($bytes)) {
            return _branding_refuse('animated', 'Animated images are not accepted. Upload a still PNG.');
        }
    } elseif ($magic === 'image/webp' && branding_webp_is_animated($bytes)) {
        return _branding_refuse('animated', 'Animated images are not accepted. Upload a still PNG.');
    }

    // Decompression-bomb guard: header dimensions only, nothing allocated yet.
    if ($w < 1 || $h < 1 || $w > BRANDING_MAX_EDGE || $h > BRANDING_MAX_EDGE || ($w * $h) > BRANDING_MAX_PIXELS) {
        return _branding_refuse('too_many_pixels',
            'The image is ' . $w . ' x ' . $h . ' pixels. The limit is ' . BRANDING_MAX_EDGE
            . ' pixels on a side and 16 million pixels in total. Resize it and try again.');
    }

    if ($gd) {
        if (!branding_memory_headroom_ok($w, $h)) {
            return _branding_refuse('memory',
                'This server does not have enough PHP memory to process an image this large. Resize it to about 1200 pixels on its longest side and try again.');
        }
        return _branding_reencode($bytes, $magic, $w, $h);
    }

    // No GD: store as-is, but still enforce the stored cap and cut a PNG at IEND.
    $out = $bytes;
    if ($magic === 'image/png') {
        $chunks = branding_png_chunks($bytes);
        $last = $chunks[count($chunks) - 1];
        $out  = substr($bytes, 0, $last[1] + 12 + $last[2]);
    }
    if (strlen($out) > BRANDING_MAX_STORED_BYTES) {
        return _branding_refuse('stored_too_large',
            'The image is larger than ' . (BRANDING_MAX_STORED_BYTES / 1024)
            . ' KB. Use a smaller image, or ask your administrator to enable the PHP GD extension so large images can be reduced automatically.');
    }
    return [
        'ok'       => true,
        'bytes'    => $out,
        'mime'     => $magic,
        'width'    => $w,
        'height'   => $h,
        'sha256'   => hash('sha256', $out),
        'warnings' => ['The PHP GD extension is not available, so the image was stored as uploaded and its metadata (such as camera or location tags) was NOT removed. Ask your administrator to enable GD.'],
    ];
}

/**
 * GD path: decode, downscale, re-encode. Tries progressively smaller long
 * edges until the result fits the stored cap, because no surface ever shows a
 * logo taller than 96 CSS pixels, so a 400 px original loses nothing visible.
 */
function _branding_reencode(string $bytes, string $mime, int $w, int $h): array
{
    $src = @imagecreatefromstring($bytes);
    if ($src === false) {
        // A decode failure is evidence of a corrupt or hostile file: refuse,
        // never fall back to the original bytes.
        return _branding_refuse('decode_failed', 'The image could not be read. It may be damaged. Export it again as a PNG.');
    }
    if (!imageistruecolor($src)) {
        @imagepalettetotruecolor($src);
    }
    $isJpeg = ($mime === 'image/jpeg');
    $long   = max($w, $h);

    $edges = [];
    foreach ([BRANDING_DOWNSCALE_EDGE, 900, 700, 500, 400] as $edge) {
        if ($edge < $long) {
            $edges[] = $edge;
        }
    }
    array_unshift($edges, min($long, BRANDING_DOWNSCALE_EDGE));
    $edges = array_values(array_unique($edges));

    $warnings = [];
    $encoded  = null;
    $outW = $outH = 0;
    foreach ($edges as $edge) {
        $scale = ($long > $edge) ? ($edge / $long) : 1.0;
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($dst === false) {
            return _branding_refuse('decode_failed', 'The image could not be processed.');
        }
        if (!$isJpeg) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $clear = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $clear);
        }
        imagealphablending($src, false);
        if (!imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h)) {
            return _branding_refuse('decode_failed', 'The image could not be processed.');
        }
        ob_start();
        $okEnc = $isJpeg ? imagejpeg($dst, null, 90) : imagepng($dst, null, 9);
        $buf = (string) ob_get_clean();
        if (!$okEnc || $buf === '') {
            return _branding_refuse('encode_failed', 'The image could not be re-encoded.');
        }
        if (strlen($buf) <= BRANDING_MAX_STORED_BYTES) {
            $encoded = $buf;
            $outW = $nw;
            $outH = $nh;
            if ($scale < 1.0) {
                $warnings[] = 'The image was reduced to ' . $nw . ' x ' . $nh . ' pixels'
                    . ($edge < BRANDING_DOWNSCALE_EDGE ? ' to fit the ' . (BRANDING_MAX_STORED_BYTES / 1024) . ' KB limit' : '') . '.';
            }
            break;
        }
    }
    if ($encoded === null) {
        return _branding_refuse('stored_too_large',
            'Even after reducing it, the image is larger than ' . (BRANDING_MAX_STORED_BYTES / 1024)
            . ' KB. It is probably a photograph or very detailed artwork. Use a simpler, smaller logo.');
    }

    // Confirm what we are about to store is the type and size we think it is.
    $check = @getimagesizefromstring($encoded);
    if ($check === false || (string) ($check['mime'] ?? '') !== ($isJpeg ? 'image/jpeg' : 'image/png')) {
        return _branding_refuse('encode_failed', 'The image could not be re-encoded.');
    }
    return [
        'ok'       => true,
        'bytes'    => $encoded,
        'mime'     => $isJpeg ? 'image/jpeg' : 'image/png',
        'width'    => (int) $check[0],
        'height'   => (int) $check[1],
        'sha256'   => hash('sha256', $encoded),
        'warnings' => $warnings,
    ];
}

// ─────────────────────────────────────────────────────────────────────────
// Storage
// ─────────────────────────────────────────────────────────────────────────

/** Valid variants, and the one place that says so. */
function branding_valid_variant($variant): bool
{
    return $variant === 'light' || $variant === 'dark';
}

/** The server's max_allowed_packet in bytes (0 when it cannot be read). */
function branding_max_allowed_packet(): int
{
    try {
        $row = db_fetch_one("SHOW VARIABLES LIKE 'max_allowed_packet'");
        return $row ? (int) $row['Value'] : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Store (replace) the logo for (org, variant). One statement; a fresh random
 * asset_key every time, so a replaced logo gets a new URL (and the old one
 * 404s) which also designs out the stale-cache problem GH#143 exposed.
 *
 * @param array $p the ok result of branding_process_upload()
 * @return array{ok:true, id:int, asset_key:string, replaced:bool}|array{ok:false, code:string, message:string}
 */
function branding_store_logo(int $orgId, string $variant, array $p, int $userId, string $userName): array
{
    if (!branding_table_exists()) {
        return _branding_refuse('no_schema', 'The branding tables are missing. Run: php sql/run_migrations.php');
    }
    if ($orgId < 0 || !branding_valid_variant($variant)) {
        return _branding_refuse('bad_scope', 'Invalid logo scope.');
    }
    if (empty($p['ok']) || !isset($p['bytes'], $p['mime'])) {
        return _branding_refuse('bad_input', 'Nothing to store.');
    }
    if ($variant === 'dark') {
        $hasLight = db_fetch_value(
            "SELECT 1 FROM " . db_table('branding_logos') . " WHERE `org_id` = ? AND `variant` = 'light'",
            [$orgId]
        );
        if (!$hasLight) {
            return _branding_refuse('light_first',
                'Upload the logo for light backgrounds first. The dark-background logo is an optional second image.');
        }
    }
    $b64 = base64_encode($p['bytes']);
    $packet = branding_max_allowed_packet();
    if ($packet > 0 && strlen($b64) + 2048 > $packet) {
        return _branding_refuse('packet',
            'The database server will not accept a value this large (max_allowed_packet). Use a smaller image.');
    }
    $existed = (bool) db_fetch_value(
        "SELECT 1 FROM " . db_table('branding_logos') . " WHERE `org_id` = ? AND `variant` = ?",
        [$orgId, $variant]
    );
    $key = branding_new_asset_key();
    $name = function_exists('mb_substr') ? mb_substr($userName, 0, 64, 'UTF-8') : substr($userName, 0, 64);
    try {
        db_query(
            "INSERT INTO " . db_table('branding_logos') . "
                (`org_id`, `variant`, `asset_key`, `mime`, `width`, `height`, `byte_size`, `sha256`, `data_b64`, `uploaded_by`, `uploaded_by_name`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                `asset_key` = VALUES(`asset_key`), `mime` = VALUES(`mime`), `width` = VALUES(`width`),
                `height` = VALUES(`height`), `byte_size` = VALUES(`byte_size`), `sha256` = VALUES(`sha256`),
                `data_b64` = VALUES(`data_b64`), `uploaded_by` = VALUES(`uploaded_by`),
                `uploaded_by_name` = VALUES(`uploaded_by_name`)",
            [$orgId, $variant, $key, $p['mime'], (int) $p['width'], (int) $p['height'],
             strlen($p['bytes']), $p['sha256'], $b64, $userId, $name]
        );
        $id = (int) db_fetch_value(
            "SELECT `id` FROM " . db_table('branding_logos') . " WHERE `org_id` = ? AND `variant` = ?",
            [$orgId, $variant]
        );
    } catch (Throwable $e) {
        error_log('[branding] store failed: ' . $e->getMessage());
        return _branding_refuse('db_error', 'The logo could not be saved.');
    }
    branding_reset_cache();
    return ['ok' => true, 'id' => $id, 'asset_key' => $key, 'replaced' => $existed];
}

/**
 * Remove the logo for (org, variant). Removing the LIGHT logo also removes the
 * dark one for that scope: a dark logo with no light logo is meaningless and
 * would otherwise be an invisible orphan.
 *
 * The result names what was removed (id, variant, who uploaded it, content
 * hash; never the image bytes) so the caller can write a complete audit entry.
 *
 * @return array{ok:true, deleted:int, ids:int[], removed:array<int,array<string,mixed>>}|array{ok:false, code:string, message:string}
 */
function branding_delete_logo(int $orgId, string $variant): array
{
    if (!branding_table_exists()) {
        return _branding_refuse('no_schema', 'The branding tables are missing. Run: php sql/run_migrations.php');
    }
    if ($orgId < 0 || !branding_valid_variant($variant)) {
        return _branding_refuse('bad_scope', 'Invalid logo scope.');
    }
    try {
        $sel = "SELECT `id`, `variant`, `uploaded_by`, `sha256` FROM " . db_table('branding_logos') . " WHERE `org_id` = ?";
        if ($variant === 'light') {
            $rows = db_fetch_all($sel, [$orgId]);
            db_query("DELETE FROM " . db_table('branding_logos') . " WHERE `org_id` = ?", [$orgId]);
        } else {
            $rows = db_fetch_all($sel . " AND `variant` = 'dark'", [$orgId]);
            db_query("DELETE FROM " . db_table('branding_logos') . " WHERE `org_id` = ? AND `variant` = 'dark'", [$orgId]);
        }
    } catch (Throwable $e) {
        error_log('[branding] delete failed: ' . $e->getMessage());
        return _branding_refuse('db_error', 'The logo could not be removed.');
    }
    branding_reset_cache();
    $ids = [];
    $removed = [];
    foreach ($rows as $r) {
        $ids[] = (int) $r['id'];
        $removed[] = [
            'id'          => (int) $r['id'],
            'variant'     => (string) $r['variant'],
            'uploaded_by' => (int) $r['uploaded_by'],
            'sha256'      => substr((string) $r['sha256'], 0, 12),
        ];
    }
    return ['ok' => true, 'deleted' => count($ids), 'ids' => $ids, 'removed' => $removed];
}

/**
 * api/organizations.php hard-deletes an organization; its logos go with it
 * (there is deliberately no foreign key: org 0 means install-wide).
 */
function branding_delete_for_org(int $orgId): int
{
    if ($orgId <= 0 || !branding_table_exists()) {
        return 0;
    }
    try {
        $n = (int) db_fetch_value(
            "SELECT COUNT(*) FROM " . db_table('branding_logos') . " WHERE `org_id` = ?",
            [$orgId]
        );
        if ($n > 0) {
            db_query("DELETE FROM " . db_table('branding_logos') . " WHERE `org_id` = ?", [$orgId]);
            branding_reset_cache();
        }
        return $n;
    } catch (Throwable $e) {
        error_log('[branding] org delete cleanup failed: ' . $e->getMessage());
        return 0;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Authorization helpers (pure where possible, so the boundary is testable
// without an HTTP session; the endpoint is only glue around these)
// ─────────────────────────────────────────────────────────────────────────

/**
 * The SPECIFIC organization a user has been granted action.manage_branding_org
 * for, via an org-SCOPED role assignment, independent of session state.
 *
 * Copies the semantics of pb_resolve_caller_org_id() (inc/public-board.php),
 * whose docblock records the live proof: $_SESSION['active_org_id'] could be
 * set to ANY id, and org_user_home_id() returns 1 for anyone with no home
 * organization, so neither may decide who can change an organization's logo.
 * Exactly one distinct org-scoped, unexpired grant resolves to that org; none,
 * global scope only, or more than one distinct org resolves to 0 ("no
 * organization", a 403), never the first or lowest one.
 *
 * The permission's canonical alias is included because rbac_can() treats a
 * code and its alias as interchangeable, so a grant held under either name
 * must count here too. Fails safe (0) on any database error.
 */
function branding_resolve_caller_org_id(int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    $codes = ['action.manage_branding_org'];
    if (function_exists('_rbac_alias_candidates')) {
        try {
            $codes = array_values(array_unique(array_merge($codes, _rbac_alias_candidates('action.manage_branding_org'))));
        } catch (Throwable $e) {
            // keep the literal code
        }
    }
    try {
        $in = implode(',', array_fill(0, count($codes), '?'));
        $rows = db_fetch_all(
            "SELECT DISTINCT ur.scope_id
               FROM " . db_table('user_roles') . " ur
               JOIN " . db_table('role_permissions') . " rp ON rp.role_id = ur.role_id
               JOIN " . db_table('permissions') . " p ON p.id = rp.permission_id
              WHERE ur.user_id = ?
                AND ur.scope_kind = 'org'
                AND ur.scope_id IS NOT NULL
                AND p.code IN ({$in})
                AND (ur.expires_at IS NULL OR ur.expires_at > NOW())",
            array_merge([$userId], $codes)
        );
    } catch (Throwable $e) {
        error_log('[branding_resolve_caller_org_id] grant lookup failed: ' . $e->getMessage());
        return 0;
    }
    return count($rows) === 1 ? (int) $rows[0]['scope_id'] : 0;
}

/**
 * Which organization may this caller write a logo for? The whole boundary,
 * pure so it can be driven directly (the pattern of pb_resolve_admin_write_org).
 *
 *   $isBrandAdmin  holds action.manage_branding (install-wide, Super Admin)
 *   $isOrgSelf     holds action.manage_branding_org (own organization only)
 *   $callerOrgId   branding_resolve_caller_org_id(): 0 = no single org
 *   $scope         'install' | 'org'
 *   $requestedOrg  the org id the CLIENT named, or null
 *
 * Returns ['ok','org_id','error','status']. On ok, org_id 0 is install-wide.
 * An org-scoped holder's organization is FORCED; a client-named different id is
 * a 403, never silently overridden (which would mask a crafted request).
 *
 * @return array{ok:bool, org_id:?int, error:?string, status:int}
 */
function branding_resolve_write_target(bool $isBrandAdmin, bool $isOrgSelf, int $callerOrgId, string $scope, ?int $requestedOrg): array
{
    if ($scope === 'install') {
        if ($isBrandAdmin) {
            return ['ok' => true, 'org_id' => 0, 'error' => null, 'status' => 200];
        }
        return ['ok' => false, 'org_id' => null, 'error' => 'Forbidden: the install-wide logo needs the Manage Branding permission.', 'status' => 403];
    }
    if ($scope !== 'org') {
        return ['ok' => false, 'org_id' => null, 'error' => 'Invalid scope.', 'status' => 400];
    }
    if ($isBrandAdmin) {
        if ($requestedOrg === null || $requestedOrg <= 0) {
            return ['ok' => false, 'org_id' => null, 'error' => 'Missing organization id.', 'status' => 400];
        }
        return ['ok' => true, 'org_id' => $requestedOrg, 'error' => null, 'status' => 200];
    }
    if ($isOrgSelf) {
        if ($callerOrgId <= 0) {
            return ['ok' => false, 'org_id' => null, 'error' => 'No organization on this account', 'status' => 403];
        }
        if ($requestedOrg !== null && $requestedOrg > 0 && $requestedOrg !== $callerOrgId) {
            return ['ok' => false, 'org_id' => null, 'error' => 'Forbidden: cannot modify another organization', 'status' => 403];
        }
        return ['ok' => true, 'org_id' => $callerOrgId, 'error' => null, 'status' => 200];
    }
    return ['ok' => false, 'org_id' => null, 'error' => 'Forbidden', 'status' => 403];
}

// ─────────────────────────────────────────────────────────────────────────
// Admin status (metadata only; the serving endpoint is the only bulk reader)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Every stored logo's metadata for the admin page, newest scope first. Never
 * selects data_b64.
 *
 * @return array<int, array<string,mixed>>
 */
function branding_list_rows(?int $onlyOrgId = null): array
{
    if (!branding_table_exists()) {
        return [];
    }
    try {
        $sql = "SELECT `id`, `org_id`, `variant`, `asset_key`, `mime`, `width`, `height`, `byte_size`, `sha256`,
                       `uploaded_by_name`, `updated_at`
                  FROM " . db_table('branding_logos');
        $params = [];
        if ($onlyOrgId !== null) {
            $sql .= " WHERE `org_id` = ?";
            $params[] = $onlyOrgId;
        }
        $sql .= " ORDER BY `org_id`, `variant`";
        $rows = db_fetch_all($sql, $params);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        if (!branding_valid_key($r['asset_key'])) {
            continue;
        }
        $out[] = [
            'id'               => (int) $r['id'],
            'org_id'           => (int) $r['org_id'],
            'variant'          => (string) $r['variant'],
            'url'              => branding_logo_url($r['asset_key']),
            'mime'             => (string) $r['mime'],
            'width'            => (int) $r['width'],
            'height'           => (int) $r['height'],
            'byte_size'        => (int) $r['byte_size'],
            'sha256'           => substr((string) $r['sha256'], 0, 12),
            'uploaded_by_name' => (string) $r['uploaded_by_name'],
            'updated_at'       => (string) $r['updated_at'],
        ];
    }
    return $out;
}
