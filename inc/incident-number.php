<?php
/**
 * NewUI v4.0 — Incident Number Template Engine (Phase 15, 2026-06-11)
 *
 * Renders an incident "case number" by interpolating tokens in an
 * admin-configured template string with the current date/time and the
 * next sequence counter. Spec'd by Eric Osterberg on 2026-06-11.
 *
 * Token grammar (all wrapped in curly braces):
 *
 *   DATE TOKENS — Unix-date-inspired
 *     {YYYY}  4-digit year                       (2026)
 *     {YY}    2-digit year                       (26)
 *     {MM}    2-digit month, zero-padded         (01..12)
 *     {DD}    2-digit day of month, zero-padded  (01..31)
 *     {HH}    2-digit hour (24-hour), zero-padded(00..23)
 *     {JJJ}   3-digit day-of-year                (001..366)
 *     {UU}    2-digit ISO week                   (01..53)
 *
 *   SEQUENCE TOKEN — token-duplication width pattern
 *     {N}, {0}             min-width 1 (no zero-padding)
 *     {NNN}, {000}         fixed width 3 (e.g., 042)
 *     {NNNNNNNN}, {00000000} fixed width 8 (e.g., 00000042)
 *
 *     Overflow rule: if the counter exceeds the token width, the
 *     full number is printed without truncation. e.g., {000} with
 *     counter=1024 → "1024".
 *
 *   ESCAPING
 *     \{      literal opening brace
 *     \}      literal closing brace
 *     \\      literal backslash
 *
 *   MALFORMED TOKENS
 *     Anything inside braces that doesn't match a known date or
 *     duplication pattern (e.g., {XYZ}, {N0N}, {YYYY1}) is left
 *     UNTOUCHED as literal text. The validator returns it as a
 *     warning so the admin can fix it; the renderer never throws.
 *
 *
 * Public API:
 *
 *   incnum_render(string $template, int $sequence, ?int $timestamp = null): string
 *     Render a template with a specific sequence value and timestamp.
 *     Pure function; no DB side-effects. Used by the live-preview in
 *     the admin settings panel AND by incnum_allocate() below.
 *
 *   incnum_validate(string $template): array
 *     Returns ['valid' => bool, 'errors' => [...], 'warnings' => [...],
 *              'has_sequence' => bool, 'has_date' => bool].
 *     Use to gate the Save button in the admin UI.
 *
 *   incnum_allocate(?int $timestamp = null): array
 *     Atomically increment the sequence counter and render the
 *     resulting incident number using the current template.
 *     Returns ['number' => 'INC-2026-0042', 'sequence' => 42].
 *     Uses MySQL's LAST_INSERT_ID() trick for atomic increment so
 *     concurrent incident-create calls never collide on a sequence.
 *
 *   incnum_get_template(): string
 *     Returns the current template (admin-configured).
 *
 *   incnum_get_next(): int
 *     Returns the next sequence value WITHOUT consuming it. Useful
 *     for "preview" mode in the admin UI.
 *
 *   incnum_set_next(int $n): bool
 *     Manually set the next sequence value (admin override).
 *
 *   incnum_get_reset_mode(): string
 *     Returns 'never' | 'yearly' | 'monthly' | 'daily'.
 *
 *   incnum_set_reset_mode(string $mode): bool
 *     Persist the reset mode.
 *
 *   incnum_suggest_reset_mode(string $template): string
 *     Best-effort suggestion from the template's date tokens. Used
 *     by the UI to pre-fill the dropdown when admin types a new
 *     template. Does NOT persist.
 *
 *
 * Period reset (Phase 15b, 2026-06-11):
 *
 *   On each allocation the engine computes the CURRENT period key
 *   (depending on the configured mode) and compares it to the
 *   stored `incident_number_period` setting. If they match, the
 *   sequence increments normally. If they differ, the sequence
 *   resets to 1 and the new period key is persisted.
 *
 *     mode      period key example
 *     never     '0' (constant)
 *     yearly    '2026'
 *     monthly   '2026-06'
 *     daily     '2026-06-11'
 *
 *   The period check + sequence increment is wrapped in a
 *   transaction with row-level locks (SELECT ... FOR UPDATE) so
 *   two simultaneous allocations across a period boundary still
 *   serialize correctly — neither gets a duplicate sequence and
 *   neither misses the reset.
 */

// Tokens we recognize as date tokens. Map: token (without braces) →
// PHP date() format character (or 'special' if computed differently).
$INCNUM_DATE_TOKENS = [
    'YYYY' => 'Y',
    'YY'   => 'y',
    'MM'   => 'm',
    'DD'   => 'd',
    'HH'   => 'H',
    'JJJ'  => 'z+1',  // PHP z is 0-indexed; we add 1 and zero-pad to 3
    'UU'   => 'W',
];

/**
 * Render the template. PURE — no DB side effects.
 */
function incnum_render(string $template, int $sequence, ?int $timestamp = null): string
{
    global $INCNUM_DATE_TOKENS;
    if ($timestamp === null) $timestamp = time();

    // First pass: stash escape sequences so they don't get interpreted
    // as token boundaries. We use sentinel placeholders that won't
    // appear in user templates and put the literals back at the end.
    $sentinelOpen  = "\x01ESC_OPEN\x01";
    $sentinelClose = "\x01ESC_CLOSE\x01";
    $sentinelBack  = "\x01ESC_BACK\x01";
    // \\ first so we don't accidentally double-escape
    $work = str_replace('\\\\', $sentinelBack,  $template);
    $work = str_replace('\\{',  $sentinelOpen,  $work);
    $work = str_replace('\\}',  $sentinelClose, $work);

    // Process tokens. Match {anything that doesn't contain braces}.
    // The callback decides whether each match is a real token or
    // gets left as literal.
    $out = preg_replace_callback('/\{([^{}]*)\}/', function ($m) use ($INCNUM_DATE_TOKENS, $sequence, $timestamp) {
        $body = $m[1];

        // Date token?
        if (isset($INCNUM_DATE_TOKENS[$body])) {
            $fmt = $INCNUM_DATE_TOKENS[$body];
            if ($fmt === 'z+1') {
                // day-of-year, 1-indexed, 3-digit zero-padded
                return str_pad((string)(date('z', $timestamp) + 1), 3, '0', STR_PAD_LEFT);
            }
            return date($fmt, $timestamp);
        }

        // Sequence token — duplication of 'N' or '0' (case-sensitive on N)?
        if ($body !== '' && (preg_match('/^N+$/', $body) || preg_match('/^0+$/', $body))) {
            $width = strlen($body);
            // Overflow: if the number is wider than the token, print
            // the full number. Otherwise zero-pad to the token width.
            return str_pad((string)$sequence, $width, '0', STR_PAD_LEFT);
        }

        // Unknown token — leave the original {...} text literal.
        return $m[0];
    }, $work);

    // Restore escape sequences as literal characters.
    $out = str_replace($sentinelOpen,  '{',  $out);
    $out = str_replace($sentinelClose, '}',  $out);
    $out = str_replace($sentinelBack,  '\\', $out);

    return $out;
}

/**
 * Validate a template. Returns errors + warnings without rendering.
 */
function incnum_validate(string $template): array
{
    global $INCNUM_DATE_TOKENS;

    $out = [
        'valid'        => true,
        'errors'       => [],
        'warnings'     => [],
        'has_sequence' => false,
        'has_date'     => false,
    ];

    // Skip escaped braces for analysis purposes — they're literals.
    $work = str_replace(['\\{', '\\}', '\\\\'], ['', '', ''], $template);

    if (preg_match_all('/\{([^{}]*)\}/', $work, $matches)) {
        foreach ($matches[1] as $body) {
            if (isset($INCNUM_DATE_TOKENS[$body])) {
                $out['has_date'] = true;
            } elseif (preg_match('/^N+$/', $body) || preg_match('/^0+$/', $body)) {
                $out['has_sequence'] = true;
            } else {
                // Malformed — warn but don't reject. The renderer
                // leaves these as literal text.
                $out['warnings'][] = "Unknown token: {{$body}} — will be rendered as literal text. Did you mean one of: YYYY, YY, MM, DD, HH, JJJ, UU, NNN, 000 ?";
            }
        }
    }

    // The only HARD error: a template with no sequence token. That
    // means every incident gets the same number — almost certainly
    // a misconfiguration. Block save.
    if (!$out['has_sequence']) {
        $out['valid'] = false;
        $out['errors'][] = 'Template must contain at least one sequence token (e.g., {NNNN} or {0000}). Otherwise every incident will get the same number.';
    }

    // Stray unmatched braces — warn but don't reject. The renderer
    // is permissive.
    $opens  = substr_count($work, '{');
    $closes = substr_count($work, '}');
    if ($opens !== $closes) {
        $out['warnings'][] = 'Unbalanced braces — make sure every { has a matching }.';
    }

    return $out;
}

/**
 * Compute the current period key for the configured reset mode.
 * Pure function — no DB.
 */
function incnum_period_key(string $mode, ?int $timestamp = null): string
{
    if ($timestamp === null) $timestamp = time();
    switch ($mode) {
        case 'yearly':  return date('Y',     $timestamp);
        case 'monthly': return date('Y-m',   $timestamp);
        case 'daily':   return date('Y-m-d', $timestamp);
        case 'never':
        default:        return '0';
    }
}

/**
 * Atomically allocate the next incident-number, honoring the
 * configured reset period (Phase 15b).
 *
 *   1. BEGIN
 *   2. SELECT period, sequence FOR UPDATE on the settings rows
 *   3. Compute current period key for the configured mode
 *   4. If the stored period != current period → reset sequence to 1
 *      AND persist the new period; otherwise increment sequence
 *   5. COMMIT
 *
 * The FOR UPDATE row locks serialize concurrent allocations even
 * across a reset boundary — neither call returns a duplicate
 * sequence and neither misses the reset.
 *
 * Returns ['number' => '...', 'sequence' => N, 'period' => '...',
 *          'reset_mode' => '...', 'did_reset' => bool].
 */
function incnum_allocate(?int $timestamp = null): array
{
    if ($timestamp === null) $timestamp = time();
    $prefix = $GLOBALS['db_prefix'] ?? '';

    $template = incnum_get_template();
    $mode     = incnum_get_reset_mode();
    $current  = incnum_period_key($mode, $timestamp);

    try {
        db_query('START TRANSACTION');

        // Lock both rows together — order matters across allocators
        // to avoid deadlock, so always: period first, then sequence.
        $stored = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings`
              WHERE `name` = 'incident_number_period' LIMIT 1 FOR UPDATE"
        );
        $seqStr = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings`
              WHERE `name` = 'incident_number_sequence' LIMIT 1 FOR UPDATE"
        );

        $didReset = false;
        if ($mode === 'never' || $stored === $current) {
            // Same period (or no resets ever) — increment.
            $next = ((int) $seqStr) + 1;
            if ($next < 1) $next = 1;
        } else {
            // Period rolled over — reset to 1 and persist new period.
            $next = 1;
            $didReset = true;
            db_query(
                "INSERT INTO `{$prefix}settings` (`name`, `value`)
                 VALUES ('incident_number_period', ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [$current]
            );
        }

        // Phase 15c — retry-on-collision. The atomic period+sequence
        // path guarantees concurrent allocations don't COLLIDE WITH
        // EACH OTHER, but it can't defend against an admin who set
        // Next sequence to a value already on disk, or a template
        // change that suddenly overlaps historic numbers. Walk
        // forward from $next until we hit a free slot — INSIDE the
        // FOR UPDATE transaction so concurrent allocators see the
        // advance and don't double-claim the same "next free".
        $startNext     = $next;
        $collisionHops = 0;
        $maxHops       = 1000;
        while ($collisionHops < $maxHops) {
            $candidate = incnum_render($template, $next, $timestamp);
            if (incnum_find_existing($candidate) === null) break;
            $next++;
            $collisionHops++;
        }
        if ($collisionHops >= $maxHops) {
            // Pathological — almost certainly a template with no
            // sequence token. Abort cleanly so the caller gets a
            // useful error.
            throw new Exception('incnum_allocate: could not find a free incident number within 1000 hops — check that the template contains a sequence token like {NNNN}.');
        }

        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`)
             VALUES ('incident_number_sequence', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [(string) $next]
        );

        db_query('COMMIT');

        // Audit-log the skip when it happened so admins can spot
        // unexpected sequence advances (collision with imported data,
        // restore, etc.).
        if ($collisionHops > 0 && function_exists('audit_log')) {
            try {
                audit_log('config', 'auto_advance', 'incident_number', null,
                    "Sequence advanced past {$collisionHops} colliding number(s): asked for {$startNext}, allocated {$next}", [
                        'asked_sequence'  => $startNext,
                        'actual_sequence' => $next,
                        'collision_hops'  => $collisionHops,
                        'period'          => $current,
                    ]);
            } catch (Exception $e) {}
        }
    } catch (Exception $e) {
        try { db_query('ROLLBACK'); } catch (Exception $e2) {}
        // Fall back so we don't fail the whole incident-create flow.
        return [
            'number'    => '#' . (string)(time()),
            'sequence'  => 0,
            'period'    => $current,
            'reset_mode'=> $mode,
            'did_reset' => false,
            'error'     => $e->getMessage(),
        ];
    }

    return [
        'number'         => incnum_render($template, $next, $timestamp),
        'sequence'       => $next,
        'period'         => $current,
        'reset_mode'     => $mode,
        'did_reset'      => $didReset,
        'collision_hops' => $collisionHops,
        'asked_sequence' => $startNext,
    ];
}

/**
 * Read the configured template from settings. Falls back to a safe
 * default when unset (matches v3.x behavior of "year-NNNN").
 */
function incnum_get_template(): string
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $v = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'incident_number_template' LIMIT 1"
        );
        if (is_string($v) && trim($v) !== '') return $v;
    } catch (Exception $e) { /* settings table missing */ }
    return '{YY}-{NNNN}';
}

/**
 * Phase 99o (Eric beta 2026-06-29) — admin-configurable label for
 * the rendered case number. Used as the prefix in user-facing
 * places that pair the label with the number:
 *   - dashboard Incidents widget column header ("{label} #")
 *   - incident-detail page title ("{label} 26-0062 (id #217)")
 *
 * Default "Incident" matches the canonical name used throughout the
 * codebase (settings panel "Incident Numbers", DB column
 * "incident_number"). Admins can swap to "Case", "Call", "Ticket",
 * "Run", etc. Stored without a trailing #; the UI adds it where
 * appropriate.
 */
function incnum_get_label(): string
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $v = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'incident_number_label' LIMIT 1"
        );
        if (is_string($v) && trim($v) !== '') return trim($v);
    } catch (Exception $e) { /* settings table missing */ }
    return 'Incident';
}

function incnum_set_label(string $label): bool
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $label = trim($label);
    if ($label === '') $label = 'Incident';
    if (strlen($label) > 32) $label = substr($label, 0, 32);
    try {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('incident_number_label', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$label]
        );
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Phase 99p (Eric beta 2026-06-29) — return the user-facing display
 * for a given internal ticket id. Use this in server-rendered HTML,
 * toast/audit messages, exports, etc. where the dispatcher should
 * see the case number, not the internal id.
 *
 *   incnum_display(217)  →  "26-0062"  (when ticket has a case number)
 *   incnum_display(123)  →  "#123"     (legacy ticket — fallback)
 *
 * Pre-fetched case numbers can be passed via $known to avoid a DB
 * round-trip in tight loops:
 *
 *   foreach ($tickets as $t) {
 *       echo incnum_display($t['id'], $t['incident_number'] ?? null);
 *   }
 */
function incnum_display(int $ticket_id, ?string $known = null): string
{
    if ($known !== null && trim($known) !== '') {
        return trim($known);
    }
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $v = db_fetch_value(
            "SELECT `incident_number` FROM `{$prefix}ticket` WHERE `id` = ? LIMIT 1",
            [$ticket_id]
        );
        if (is_string($v) && trim($v) !== '') return trim($v);
    } catch (Exception $e) {
        // ticket table missing or row gone — fall through
    }
    return '#' . $ticket_id;
}

/**
 * Read the NEXT sequence value without consuming it (preview-only).
 *
 * Phase 15b note: the allocator increments BEFORE returning, so the
 * stored value is "the last allocated value". This helper adds 1 so
 * the admin UI shows "the next incident will be N".
 *
 * If a reset is currently due (stored period != current period), the
 * UI should show 1 — because that's what the next allocation will
 * actually return.
 */
function incnum_get_next(): int
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $mode = incnum_get_reset_mode();
        if ($mode !== 'never') {
            $stored = (string) db_fetch_value(
                "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'incident_number_period' LIMIT 1"
            );
            if ($stored !== incnum_period_key($mode)) {
                return 1;
            }
        }
        $v = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'incident_number_sequence' LIMIT 1"
        );
        return $v !== null && $v !== false ? ((int) $v) + 1 : 1;
    } catch (Exception $e) {
        return 1;
    }
}

/**
 * Admin override — set the next sequence to a specific value.
 *
 * Note: this sets the value such that the NEXT allocation returns
 * exactly $n. (The allocator increments the stored value before
 * returning it, so we store $n - 1 here.)
 */
function incnum_set_next(int $n): bool
{
    if ($n < 1) return false;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $stored = (string) ($n - 1);
    try {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('incident_number_sequence', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$stored]
        );
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Phase 15c (2026-06-11) — uniqueness check.
 *
 * Returns the incident-id of any existing ticket already using
 * the given rendered number, or null if the number is unused.
 * Used by:
 *   - incnum_allocate()'s retry-on-collision loop
 *   - The Settings page's "would this number collide?" live check
 */
function incnum_find_existing(string $renderedNumber): ?int
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $id = db_fetch_value(
            "SELECT `id` FROM `{$prefix}ticket`
             WHERE `incident_number` = ? LIMIT 1",
            [$renderedNumber]
        );
        return ($id !== false && $id !== null) ? (int) $id : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Phase 15c — preview-only collision check for the admin UI.
 *
 * Given a template + a "next sequence" the admin is about to save,
 * checks whether the rendered number would collide with an existing
 * ticket. If so, also computes how far the sequence would have to
 * advance to find a free slot.
 *
 * Returns:
 *   [
 *     'rendered'         => 'CASE-2026-06-0042',
 *     'collision'        => true|false,
 *     'existing_ticket'  => 123 | null,
 *     'next_safe_seq'    => 47 | null,  // first free sequence >= candidate
 *   ]
 *
 * Cap the next-safe search at 1000 iterations so a degenerate config
 * (no sequence token, template renders identically every time) doesn't
 * spin forever.
 */
function incnum_check_collision(string $template, int $candidateSeq, ?int $timestamp = null): array
{
    if ($timestamp === null) $timestamp = time();
    $rendered = incnum_render($template, $candidateSeq, $timestamp);
    $existing = incnum_find_existing($rendered);

    $out = [
        'rendered'        => $rendered,
        'collision'       => $existing !== null,
        'existing_ticket' => $existing,
        'next_safe_seq'   => null,
    ];

    if ($existing === null) {
        $out['next_safe_seq'] = $candidateSeq;
        return $out;
    }

    // Walk forward looking for a free slot.
    for ($i = 1; $i <= 1000; $i++) {
        $seq = $candidateSeq + $i;
        $r = incnum_render($template, $seq, $timestamp);
        if ($r === $rendered) {
            // Template has no sequence token — every iteration renders
            // identically. No safe number exists. Caller must change
            // the template.
            return $out;  // next_safe_seq stays null
        }
        if (incnum_find_existing($r) === null) {
            $out['next_safe_seq'] = $seq;
            return $out;
        }
    }
    return $out;  // gave up after 1000 — next_safe_seq stays null
}

/**
 * Reset mode helpers (Phase 15b).
 */
function incnum_get_reset_mode(): string
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $v = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings`
              WHERE `name` = 'incident_number_reset_mode' LIMIT 1"
        );
        if (in_array($v, ['never', 'yearly', 'monthly', 'daily'], true)) {
            return $v;
        }
    } catch (Exception $e) {}
    return 'yearly';
}

function incnum_set_reset_mode(string $mode): bool
{
    if (!in_array($mode, ['never', 'yearly', 'monthly', 'daily'], true)) {
        return false;
    }
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('incident_number_reset_mode', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$mode]
        );
        // When admin changes the mode, also stamp the current period
        // so the NEXT allocation doesn't trigger a spurious reset.
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('incident_number_period', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [incnum_period_key($mode)]
        );
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Best-effort suggestion based on the template's date tokens.
 *
 *   contains {DD} or {JJJ}   → daily
 *   contains {MM} (no D)     → monthly
 *   contains {YY} or {YYYY}  → yearly
 *   otherwise                → never (no date token means sequence
 *                              is the sole differentiator, so any
 *                              reset would create duplicates)
 */
function incnum_suggest_reset_mode(string $template): string
{
    if (strpos($template, '{DD}') !== false || strpos($template, '{JJJ}') !== false) {
        return 'daily';
    }
    if (strpos($template, '{MM}') !== false) {
        return 'monthly';
    }
    if (strpos($template, '{YY}') !== false || strpos($template, '{YYYY}') !== false) {
        return 'yearly';
    }
    return 'never';
}
