<?php
/**
 * GH#76 Phase 144 (2026-08-18) — legacy member.team_id write-path audit.
 *
 * THE RULE: no INTERNAL (non-external-API) code path may ever write
 * member.team_id again. member.team_id is a permanent, read-only-from-
 * internal-code, external-API-compat mirror as of this release (same
 * treatment this project already gave user.level in Phase 128 —
 * "eliminated from behavior, not necessarily dropped from schema"). The
 * ONE exception is api/external/v1/members.php's team_id compatibility
 * shim, which does NOT write member.team_id either — it mirrors into
 * team_members instead (see ext_api_sync_team_id_shim()).
 *
 * Mirrors tools/legacy_level_audit.php's tokenizing-scan shape (strip
 * comments via token_get_all, classify each hit's surrounding context),
 * scoped narrowly to this one column instead of that tool's broader "no
 * user.level comparisons" rule:
 *
 *   1. Strip comments to FIND occurrences (a false hit inside a docblock
 *      explaining the rule — like this one — must not trip the gate).
 *   2. Find every literal `'team_id'` occurrence in the stripped source.
 *   3. A READ — `$var['team_id']` (property/array access on an
 *      already-fetched row or an incoming request array) or
 *      `array_column($rows, 'team_id')` — is always safe and is
 *      excluded regardless of context.
 *   4. Anything else (a bare array-literal element or key, the exact
 *      shape a whitelist like $allowed/$intCols/$passthrough/$fields
 *      uses) is a candidate WRITE. It is exempt only if a GENEROUS
 *      character window around it (checked against the ORIGINAL,
 *      comment-INTACT source — comments are legitimate context here,
 *      unlike for occurrence-finding) mentions "team_member" (the
 *      team_members junction table, its own always-legitimate team_id
 *      column, or the 'team_member' audit-log target type).
 *   5. Scope is deliberately api/ and inc/ ONLY — the ongoing
 *      request-handling/write-helper layer where a regression would
 *      silently reintroduce this exact bug. One-time schema/migration
 *      scripts under sql/ and standalone tools/ scripts are a different
 *      risk category (matching tools/legacy_level_audit.php's own
 *      narrower treatment of migration-bridge paths) and are out of
 *      scope by directory, not by exemption list.
 *   6. Whole-file exemptions within that scope: api/external/v1/members.php
 *      (the one allowed compat-shim path) and this test file itself.
 *
 * Part 2 proves the rule functionally, through the REAL writers: a
 * team_id passed to member_create_internal()/member_update_internal() is
 * silently ignored — the column is never touched — using a throwaway
 * member, not hand-inspected source alone.
 *
 * Usage: php tests/test_legacy_team_id_write_audit.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/member-write.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#76 Phase 144 — legacy member.team_id write-path audit ===\n\n";

$base = realpath(__DIR__ . '/..');
function tbl($n) { return db_table($n); }

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — static tokenizing audit
// ═══════════════════════════════════════════════════════════════════════
echo "--- Part 1: static audit ---\n\n";

/** Strip comments, replacing every character with a space so byte
 * offsets/line numbers still map to the original file (mirrors
 * tools/legacy_level_audit.php's lla_code_only()). */
function ltwa_code_only(string $src): string {
    try {
        $tokens = @token_get_all($src);
    } catch (Throwable $e) {
        return $src;
    }
    $out = '';
    foreach ($tokens as $tk) {
        $text = is_array($tk) ? $tk[1] : $tk;
        $drop = is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true);
        $out .= $drop ? preg_replace('/[^\n]/', ' ', $text) : $text;
    }
    return $out;
}

/** Every file with the given extension under a directory. */
function ltwa_files(string $dir, array $exts = ['php']): array {
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
        if (!$f->isFile()) continue;
        $ext = strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION));
        if (in_array($ext, $exts, true)) $out[] = str_replace('\\', '/', $f->getPathname());
    }
    sort($out);
    return $out;
}

/**
 * Scan one file. Returns [[line, snippet], ...] for every candidate WRITE
 * occurrence of 'team_id' (single- or double-quoted).
 */
function ltwa_scan(string $path): array {
    $original = file_get_contents($path);
    if ($original === false) return [];
    // Same length/byte-positions as $original (comments are replaced with
    // spaces, never removed) — used ONLY to find occurrences, so a
    // docblock's PROSE mention of 'team_id' (like this file's own) never
    // becomes a candidate.
    $stripped = ltwa_code_only($original);

    // Mark every READ-access span as excluded — always safe regardless of
    // context: $var['team_id'] / $var["team_id"], or array_column(..., 'team_id').
    $readSpans = [];
    $readPatterns = [
        '/\$\w+\s*\[\s*[\'"]team_id[\'"]\s*\]/',
        '/array_column\s*\(\s*\$\w+\s*,\s*[\'"]team_id[\'"]/',
    ];
    foreach ($readPatterns as $rp) {
        if (preg_match_all($rp, $stripped, $rm, PREG_OFFSET_CAPTURE)) {
            foreach ($rm[0] as $hit) {
                $readSpans[] = [$hit[1], $hit[1] + strlen($hit[0])];
            }
        }
    }
    $inReadSpan = function (int $pos) use ($readSpans): bool {
        foreach ($readSpans as $span) {
            if ($pos >= $span[0] && $pos < $span[1]) return true;
        }
        return false;
    };

    $findings = [];
    if (!preg_match_all('/[\'"]team_id[\'"]/', $stripped, $m, PREG_OFFSET_CAPTURE)) return [];
    foreach ($m[0] as $hit) {
        $pos = $hit[1];
        if ($inReadSpan($pos)) continue;

        // Exempt: a generous window of the ORIGINAL (comment-intact)
        // source around this position mentions "team_member" — the
        // team_members junction table (its own always-legitimate team_id
        // column), or the 'team_member' audit-log target type. Comments
        // count here on purpose: a docblock naming "team_members.id" two
        // lines above a `'team_id' => ...` return element is exactly the
        // kind of context that makes the write legitimate.
        $winStart = max(0, $pos - 500);
        $window = substr($original, $winStart, 900);
        if (stripos($window, 'team_member') !== false) continue;

        $line = substr_count(substr($stripped, 0, $pos), "\n") + 1;
        $snippet = trim(preg_replace('/\s+/', ' ', substr($original, max(0, $pos - 60), 140)));
        $findings[] = [$line, $snippet];
    }
    return $findings;
}

$exemptFiles = [
    'api/external/v1/members.php', // the ONE allowed compat-shim path
    'tests/test_legacy_team_id_write_audit.php', // this file
];
function ltwa_is_exempt(string $path, array $exempt): bool {
    foreach ($exempt as $e) {
        if (strpos($path, $e) !== false) return true;
    }
    return false;
}

// Deliberately api/ and inc/ ONLY — see docblock point 5.
$targets = array_merge(
    ltwa_files($base . '/api'),
    ltwa_files($base . '/inc')
);
sort($targets);

$newFindings = 0;
foreach ($targets as $path) {
    $rel = str_replace($base . '/', '', $path);
    if (ltwa_is_exempt($rel, $exemptFiles)) continue;
    $findings = ltwa_scan($path);
    foreach ($findings as [$line, $stmt]) {
        $newFindings++;
        echo "  [VIOLATION] {$rel}:{$line}\n    {$stmt}\n";
    }
}
t("no internal code path writes member.team_id (found {$newFindings} violation(s) outside the allowed external-API shim)", $newFindings === 0);

// Sanity: the audit itself must be reachable and not accidentally return
// zero findings because it scanned nothing.
t("audit scanned at least 50 files (sanity check the scan actually ran)", count($targets) > 50);

// Regression canary: prove the scanner CAN find a violation, by scanning a
// throwaway fixture file containing the exact shape the old
// inc/member-write.php whitelist used. If this ever reports 0, the
// scanner itself is broken (a silent pass, not a clean codebase).
$canaryFile = tempnam(sys_get_temp_dir(), 'ltwa_canary') . '.php';
file_put_contents($canaryFile, "<?php\n\$allowed = ['member_type_id', 'member_status_id', 'team_id'];\n");
$canaryFindings = ltwa_scan($canaryFile);
@unlink($canaryFile);
t("scanner correctly DETECTS a planted violation (canary test — proves the gate isn't silently inert)", count($canaryFindings) === 1);

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — functional: the real writers silently ignore team_id
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- Part 2: functional (real writer, throwaway fixture) ---\n\n";

$memberId = 0;
try {
    $res = member_create_internal(
        ['first_name' => 'zzP144audit', 'last_name' => 'IgnoreTeamId', 'available' => 'Yes', 'team_id' => 999999],
        0
    );
    $memberId = (int) ($res['id'] ?? 0);
    t("member created despite team_id present in the input (not rejected, just ignored)", $memberId > 0);

    if ($memberId > 0) {
        $col = db_fetch_value("SELECT team_id FROM " . tbl('member') . " WHERE id = ?", [$memberId]);
        t("member_create_internal() did NOT write the bogus team_id (999999) into member.team_id", empty($col));

        $updRes = member_update_internal($memberId, ['team_id' => 888888, 'notes' => 'zzP144audit note'], 0);
        t("member_update_internal() reports success (team_id silently dropped, not an error)", empty($updRes['errors']));
        t("'team_id' does not appear in fields_changed (it was never a recognized field)",
            !in_array('team_id', $updRes['fields_changed'] ?? [], true));
        $col2 = db_fetch_value("SELECT team_id FROM " . tbl('member') . " WHERE id = ?", [$memberId]);
        t("member.team_id is STILL untouched after the update attempt", empty($col2));
        $notesCol = db_fetch_value("SELECT notes FROM " . tbl('member') . " WHERE id = ?", [$memberId]);
        t("the OTHER field in the same request (notes) WAS written (proves this isn't a blanket update failure)",
            $notesCol === 'zzP144audit note');
    }
} catch (Throwable $e) {
    t("functional audit ran without a fatal error: " . $e->getMessage(), false);
} finally {
    if ($memberId > 0) {
        try { db_query("DELETE FROM " . tbl('team_members') . " WHERE member_id = ?", [$memberId]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM " . tbl('member_organizations') . " WHERE member_id = ?", [$memberId]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM " . tbl('member') . " WHERE id = ?", [$memberId]); } catch (Throwable $e) {}
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
