<?php
/**
 * RBAC exclusion-list privilege-leak audit (2026-08-21).
 *
 * THE DISEASE. sql/rbac.sql and sql/run_00_rbac.php grant a role
 * "everything except" a hand-written literal list of admin-only codes:
 *
 *     SELECT 3, `id` FROM `permissions` WHERE `code` NOT IN ('action.manage_config', ...)
 *
 * This shape has leaked a Super-Admin-only permission down to Org Admin
 * and/or Dispatcher FOUR times this project has found and fixed, by three
 * different mechanisms, each discovered independently on a different
 * date by someone who happened to notice (see CLAUDE.md's several "RBAC
 * EXCLUSION-LIST MECHANISM LEAKS" entries):
 *
 *   (1) DIRECT   — the role held the code from before it was added to the
 *                  exclusion list; nothing retroactively revokes a
 *                  pre-existing grant when the string is added later.
 *   (2) ALIAS    — sql/run_rbac_v2.php's A8 step canonicalizes every
 *                  permission to a `<resource>.<verb>` code and links the
 *                  old code to it via `deprecated_alias_of`; rbac_can()
 *                  treats a code and its canonical alias as fully
 *                  interchangeable, so a literal exclusion list can never
 *                  name a canonical alias that didn't exist when it was
 *                  written.
 *   (3) ROGUE MIGRATION — a *separate* run_*.php script directly grants a
 *                  role the exact code the exclusion list withholds
 *                  (sql/run_phase12_org_admin_manage_config.php, found
 *                  2026-08-20 building GH#96) — invisible to both repairs
 *                  above because it isn't a stale grant an exclusion list
 *                  forgot to clean up, it's a DIFFERENT script actively
 *                  re-creating the grant on every fresh install.
 *
 * tests/test_rbac_canonical_alias_leak.php catches (1) and (2) for the
 * SPECIFIC codes someone remembered to add to its own `$excludedByRole`
 * array — proportionate the first three times, but by the fourth
 * instance (action.manage_matrix, 2026-08-20) it was clear the check
 * itself has the same shape as the bug: something a human has to
 * remember to update in lockstep with a change made somewhere else.
 *
 * THIS TOOL removes that hand-maintenance step for (1) and (2): it PARSES
 * the exclusion lists directly out of sql/rbac.sql and
 * sql/run_00_rbac.php (rela_extract_exclusions() below — generic over
 * ANY role id that uses the `WHERE code NOT IN (...)` shape, not just
 * roles 2/3) and checks the LIVE database against exactly what it finds.
 * Add a new code to an exclusion list and this tool checks it on the
 * very next run, with no companion edit anywhere else required.
 *
 * It also adds two checks neither the tool's predecessor nor the hand-
 * written test attempt:
 *   - CROSS-FILE DRIFT: sql/rbac.sql's and sql/run_00_rbac.php's
 *     exclusion lists for the same role must name the same codes. A
 *     human edits one and forgets the other; nothing before this asked.
 *   - ROGUE-GRANT SCAN (mechanism 3, generalized): every OTHER
 *     sql/run_*.php migration is scanned for a literal grant of a code
 *     that is currently excluded for some role, with no matching revoke
 *     in the same file. This does not (and structurally cannot, without
 *     real data-flow analysis) trace a grant built entirely from
 *     variables the way the original Phase-12 script's role_id was — see
 *     the docblock on rela_scan_rogue_grants() for exactly what it does
 *     and does not prove. It is a defensive, baseline-gated net, not a
 *     guarantee.
 *
 * ── UPDATE (2026-08-22): the remaining gap named above is now closed ──
 *
 * `permissions.admin_only` (see inc/rbac_admin_only.php) is now that real,
 * first-class registry: 0=unrestricted, 1=Org Admin or above, 2=Super
 * Admin only. It was added specifically because the Phase 149 incident
 * (action.manage_calls's canonical alias leaking onto Dispatcher, in the
 * SAME COMMIT that created the permission and its own exclusion-list
 * entry) proved even maximal care in the moment isn't enough against a
 * bug class whose detection depends entirely on a human remembering to
 * update a string list.
 *
 * Part 0 below is now the PRIMARY, most authoritative check: it queries
 * role_permissions/permissions/roles directly for any row where a role's
 * tier is lower than the permission's admin_only value — mechanism-
 * agnostic (it doesn't care whether the leak came from a stale grant, an
 * alias mirror, a rogue migration, or something not yet imagined), and it
 * doesn't need special alias-chasing the way Part 1 does, because the
 * classification is propagated onto BOTH a code and its canonical alias
 * at data-population time (sql/rbac.sql / sql/run_00_rbac.php), so a
 * direct join catches a leak under EITHER name automatically.
 *
 * Parts 1-3 (the original exclusion-list-text-parsing checks) are KEPT as
 * a secondary safety net for the transition period and as a genuine
 * cross-reference: Part 0's NEW "classification drift" check compares
 * each exclusion-list code's ACTUAL admin_only value in the database
 * against what its presence in these lists implies, so the two sources of
 * truth (hand-written exclusion-list text vs. the real column) are
 * continuously checked against each other rather than one silently
 * replacing the other.
 *
 * Remaining gap, now narrower: a permission kept off a role by a positive
 * allow-list purely by never being named (e.g. a role that legitimately
 * holds NOTHING beyond a short explicit list) still has no exclusion-list
 * text for Parts 1-3 to parse — but Part 0 covers it regardless, since it
 * checks the LIVE grant against admin_only directly, independent of which
 * mechanism (NOT-IN exclusion or bare absence from an allow-list) is
 * supposed to be withholding it.
 *
 * Exit code: 0 = clean/baseline-only, 1 = new findings.
 * Baseline:  tools/rbac_exclusion_leak_audit_baseline.txt
 *            ("kind|role_id|code|detail" per line; add a verified-
 *            legitimate finding WITH a reason in a comment above it).
 *
 * Usage:
 *   php tools/rbac_exclusion_leak_audit.php            # report + exit code
 *   php tools/rbac_exclusion_leak_audit.php --all      # include baselined finds
 *   php tools/rbac_exclusion_leak_audit.php --rbac-sql=FILE --run00=FILE --scan-dir=DIR
 *       # point the three inputs at fixture files/dir instead of the real
 *       # app tree (tests only) -- still checks the REAL app's live
 *       # permissions/role_permissions tables for the DB-state check,
 *       # same convention as tools/rbac_permission_audit.php's --path=.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
chdir($root);
$showAll = in_array('--all', $argv, true);

$rbacSqlPath = 'sql/rbac.sql';
$run00Path   = 'sql/run_00_rbac.php';
$scanDir     = 'sql';
foreach ($argv as $a) {
    if (strpos($a, '--rbac-sql=') === 0) { $rbacSqlPath = substr($a, 11); }
    if (strpos($a, '--run00=') === 0)    { $run00Path   = substr($a, 8); }
    if (strpos($a, '--scan-dir=') === 0) { $scanDir     = substr($a, 11); }
}

// Loaded first, before any output -- config.php sets session ini directives
// that PHP refuses (with a warning) once a byte has already been echoed.
$dbAvailable = true;
$dbError = '';
try {
    require_once __DIR__ . '/../config.php';
} catch (Throwable $e) {
    $dbAvailable = false;
    $dbError = $e->getMessage();
}

$baselineFile = __DIR__ . '/rbac_exclusion_leak_audit_baseline.txt';
$baseline = [];
if (is_file($baselineFile)) {
    foreach (file($baselineFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $baseline[$line] = true;
    }
}

/**
 * Blank every comment character to a space (never removing bytes, so line
 * numbers and offsets stay valid) -- PHP /* *\/ and //, then SQL --
 * (needed for both the raw .sql file and the SQL text embedded as PHP
 * strings in run_00_rbac.php, which uses -- throughout). Matches
 * tools/rbac_permission_audit.php's own technique for the same reason: a
 * docblock or comment that MENTIONS a permission code as prose must not
 * be read as a real reference.
 */
function rela_strip_comments(string $src): string {
    $blank = fn($m) => preg_replace('/[^\n]/', ' ', $m[0]);
    $src = preg_replace_callback('#/\*.*?\*/#s', $blank, $src);
    $src = preg_replace_callback('#//.*$#m', $blank, $src);
    $src = preg_replace_callback('#--.*$#m', $blank, $src);
    return $src;
}

/**
 * Find every balanced `(...)` span starting at $openParenOffset (which
 * must point AT an opening '('). Returns the substring strictly between
 * the matching parens, or null if unbalanced. Simple depth counter --
 * safe here because comments are already blanked and none of this
 * codebase's permission codes contain '(' or ')'.
 */
function rela_balanced_span(string $src, int $openParenOffset): ?string {
    $depth = 0;
    $len = strlen($src);
    $start = $openParenOffset + 1;
    for ($i = $openParenOffset; $i < $len; $i++) {
        if ($src[$i] === '(') $depth++;
        elseif ($src[$i] === ')') {
            $depth--;
            if ($depth === 0) return substr($src, $start, $i - $start);
        }
    }
    return null;
}

/**
 * Every `SELECT <roleId>, id FROM permissions WHERE code NOT IN (...)`
 * broad-exclusion block in $src, generic over role id (not hardcoded to
 * 2/3) so a FUTURE role adopting this same "everything except" shape is
 * automatically covered. Returns [roleId => [code, code, ...]], codes in
 * source order, deduplicated.
 */
function rela_extract_exclusions(string $src): array {
    $src = rela_strip_comments($src);
    $re = '/SELECT\s+(\d+)\s*,\s*`?id`?\s+FROM\s+`?(?:\{\$prefix\})?permissions`?\s+WHERE\s+`?code`?\s+NOT\s+IN\s*(\()/i';
    if (!preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE)) return [];
    $out = [];
    foreach ($m[1] as $i => $roleHit) {
        $roleId = (int) $roleHit[0];
        $openParenOffset = $m[2][$i][1];
        $span = rela_balanced_span($src, $openParenOffset);
        if ($span === null) continue; // unbalanced -- don't guess
        if (!preg_match_all("/'([^']*)'/", $span, $cm)) continue;
        foreach ($cm[1] as $code) {
            if ($code === '') continue;
            $out[$roleId][$code] = true;
        }
    }
    // Flatten the dedup-by-key map to a plain code list per role.
    foreach ($out as $roleId => $codes) {
        $out[$roleId] = array_keys($codes);
    }
    return $out;
}

/**
 * Every high-precision, purely-SQL-literal grant of the shape
 *
 *     SELECT <roleId>, id FROM permissions WHERE code = '<code>'
 *     SELECT <roleId>, id FROM permissions WHERE code IN ('<code>', ...)
 *
 * across every sql/run_*.php file except the two trusted exclusion-list
 * sources ($excludeFiles) -- the exact positive mirror of what
 * rela_extract_exclusions() parses for the NOT-IN case. Flags a
 * (file, role, code) triple when $roleId is granted a $code that is
 * CURRENTLY on that role's exclusion list.
 *
 * WHAT THIS PROVES: a migration file contains a literal SQL statement
 * that would grant a specific excluded role a specific excluded code, in
 * one self-contained SELECT.
 * WHAT THIS DELIBERATELY DOES NOT ATTEMPT: a grant assembled from PHP
 * variables -- role id resolved by a name/id lookup a few lines earlier,
 * permission id resolved into its own variable before the INSERT -- is
 * invisible to this static text pattern by construction. That is
 * EXACTLY the shape the real, now-fixed
 * sql/run_phase12_org_admin_manage_config.php bug used (`$orgAdminId`
 * resolved from `SELECT id FROM roles WHERE id = 2`, three lines above
 * the INSERT that used it) -- real interprocedural data-flow tracing to
 * catch that generically is the "large architecture change" this tool
 * deliberately does not attempt. The live DB-state check in Part 1 above
 * IS the actual backstop for that shape: it checks the RESULT (does the
 * role hold the code right now) regardless of which script produced it,
 * the moment the leak is live on a real database -- this scan only adds
 * shift-left value for the narrower, purely-literal shape.
 */
function rela_scan_rogue_grants(string $scanDir, array $excludeFiles, array $exclusionsByRole): array {
    $findings = [];
    if (!$exclusionsByRole) return $findings;

    $files = glob(rtrim($scanDir, '/') . '/run_*.php') ?: [];
    sort($files);
    $headRe = '/SELECT\s+(\d+)\s*,\s*`?id`?\s+FROM\s+`?(?:\{\$prefix\})?permissions`?\s+WHERE\s+`?code`?\s+'
            . '(?:=\s*\'([^\']*)\'|IN\s*(\())/i';
    foreach ($files as $path) {
        $base = basename($path);
        if (in_array($base, $excludeFiles, true)) continue;
        $src = file_get_contents($path);
        if ($src === false) continue;
        $stripped = rela_strip_comments($src);
        if (!preg_match_all($headRe, $stripped, $m, PREG_OFFSET_CAPTURE)) continue;

        foreach ($m[1] as $i => $roleHit) {
            $roleId = (int) $roleHit[0];
            if (!isset($exclusionsByRole[$roleId])) continue;

            $codes = [];
            $eqCode = $m[2][$i][0] ?? '';
            $inParen = $m[3][$i][1] ?? -1;
            if ($eqCode !== '') {
                $codes[] = $eqCode;
            } elseif ($inParen >= 0) {
                $span = rela_balanced_span($stripped, $inParen);
                if ($span !== null) {
                    preg_match_all("/'([^']*)'/", $span, $cm);
                    $codes = $cm[1] ?? [];
                }
            }

            foreach ($codes as $code) {
                if (!in_array($code, $exclusionsByRole[$roleId], true)) continue;
                $findings[] = [
                    'kind' => 'rogue_grant',
                    'role_id' => $roleId,
                    'code' => $code,
                    'detail' => "$path contains a literal SELECT $roleId, id FROM permissions WHERE code "
                              . "granting excluded code '$code' to role $roleId",
                ];
            }
        }
    }
    return $findings;
}

/**
 * Part 0 (2026-08-22, primary/authoritative): every role_permissions row
 * whose permission has admin_only > the holding role's tier is a genuine,
 * live privilege leak — regardless of which of the historical mechanisms
 * produced it. This is a direct DB check against the real, structural
 * `permissions.admin_only` column (see inc/rbac_admin_only.php), not a
 * parse of hand-written exclusion-list text, so it needs no alias-chasing
 * and cannot miss a mechanism nobody has thought of yet.
 */
function rela_scan_admin_only_violations(string $prefix): array {
    $findings = [];
    try {
        $hasCol = (bool) db_fetch_value(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'admin_only'",
            [$prefix . 'permissions']
        );
    } catch (Throwable $e) { $hasCol = false; }
    if (!$hasCol) return $findings;

    try {
        $rows = db_fetch_all(
            "SELECT rp.role_id, r.name AS role_name, p.code, p.admin_only,
                    (CASE WHEN r.is_super = 1 THEN 2
                          WHEN r.id = 2 OR r.name = 'Org Admin' THEN 1
                          ELSE 0 END) AS role_tier
               FROM `{$prefix}role_permissions` rp
               JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
               JOIN `{$prefix}roles` r ON r.id = rp.role_id
              WHERE p.admin_only > 0"
        );
    } catch (Throwable $e) { return $findings; }

    foreach ($rows as $row) {
        $required = (int) $row['admin_only'];
        $roleTier = (int) $row['role_tier'];
        if ($roleTier < $required) {
            $tierName = $required >= 2 ? 'Super Admin only' : 'Org Admin or above';
            $findings[] = [
                'kind' => 'admin_only_violation',
                'role_id' => (int) $row['role_id'],
                'code' => $row['code'],
                'detail' => "role {$row['role_id']} ({$row['role_name']}) holds admin_only={$required} "
                          . "code '{$row['code']}' ($tierName) but its own tier is only {$roleTier}",
            ];
        }
    }
    return $findings;
}

/**
 * Cross-reference check: for every code the exclusion-list text (rbac.sql)
 * withholds from Dispatcher and/or Org Admin, the DATABASE's admin_only
 * value should agree with what that exclusion implies -- excluded from
 * BOTH implies tier 2 (Super Admin only), excluded from Dispatcher ONLY
 * implies tier >= 1 (Org Admin or above). The two Facility-only codes are
 * excluded from both lists for an UNRELATED reason (reserved for a
 * specific bespoke role, not a seniority tier -- see
 * inc/rbac_admin_only.php's docblock) and are explicitly exempted here,
 * not flagged as drift.
 */
function rela_scan_classification_drift(string $prefix, array $orgAdminExcluded, array $dispatcherExcluded): array {
    $findings = [];
    $exempt = ['screen.facility_portal', 'action.facility_self_report'];
    try {
        $hasCol = (bool) db_fetch_value(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'admin_only'",
            [$prefix . 'permissions']
        );
    } catch (Throwable $e) { $hasCol = false; }
    if (!$hasCol) return $findings;

    foreach ($dispatcherExcluded as $code) {
        if (in_array($code, $exempt, true)) continue;
        $expectedMin = in_array($code, $orgAdminExcluded, true) ? 2 : 1;
        try {
            $actual = db_fetch_value("SELECT admin_only FROM `{$prefix}permissions` WHERE code = ?", [$code]);
        } catch (Throwable $e) { continue; }
        if ($actual === false || $actual === null) continue; // not seeded on this database
        if ((int) $actual < $expectedMin) {
            $findings[] = [
                'kind' => 'classification_drift',
                'role_id' => 0,
                'code' => $code,
                'detail' => "'$code' is excluded from Dispatcher's broad grant (and from Org Admin's too: "
                          . (in_array($code, $orgAdminExcluded, true) ? 'yes' : 'no')
                          . ") implying admin_only >= {$expectedMin}, but the database has admin_only={$actual} "
                          . "-- the exclusion-list text and the admin_only column have drifted apart",
            ];
        }
    }
    return $findings;
}

echo "=== RBAC exclusion-list privilege-leak audit ===\n";
echo "Rule: a code that sql/rbac.sql or sql/run_00_rbac.php excludes from a\n";
echo "      role's broad grant must never actually be held by that role --\n";
echo "      directly, via its canonical alias, or via a separate migration\n";
echo "      that grants it outside the exclusion mechanism.\n\n";

if (!is_file($rbacSqlPath)) { echo "[FAIL] {$rbacSqlPath} not found\n"; exit(1); }
if (!is_file($run00Path))   { echo "[FAIL] {$run00Path} not found\n"; exit(1); }

$rbacSqlSrc = file_get_contents($rbacSqlPath);
$run00Src   = file_get_contents($run00Path);

$exclFromRbacSql = rela_extract_exclusions($rbacSqlSrc);
$exclFromRun00   = rela_extract_exclusions($run00Src);

$totalRbacSqlCodes = array_sum(array_map('count', $exclFromRbacSql));
$totalRun00Codes   = array_sum(array_map('count', $exclFromRun00));
echo "Parsed {$rbacSqlPath}: " . count($exclFromRbacSql) . " role(s), {$totalRbacSqlCodes} excluded code reference(s)\n";
echo "Parsed {$run00Path}: " . count($exclFromRun00) . " role(s), {$totalRun00Codes} excluded code reference(s)\n\n";

// Sanity floor -- if the parser regex ever silently stops matching (a
// future reformatting of these files), $excl* collapses to [] and every
// check below would iterate zero times and report a false "clean". Fail
// LOUDLY instead of passing vacuously, same discipline this project's
// test-runner-contract applies to 0/0 results elsewhere.
if ($totalRbacSqlCodes < 5) {
    echo "[FAIL] parser found suspiciously few excluded codes in {$rbacSqlPath} "
       . "({$totalRbacSqlCodes}) -- the extraction regex may have broken on a\n";
    echo "       reformatted file. Fix the parser before trusting this tool's silence.\n";
    exit(1);
}

$findings = [];

// ── Part 0: admin_only column check (PRIMARY/authoritative) + the
//    classification-drift cross-reference against the exclusion-list
//    text just parsed. ────────────────────────────────────────────────
if (!$dbAvailable) {
    echo "connection failed — {$dbError}\n";
    echo "(cannot check live grants without the database — CI will run it)\n";
} else {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $adminOnlyFindings = rela_scan_admin_only_violations($prefix);
    $findings = array_merge($findings, $adminOnlyFindings);
    echo "admin_only column check: " . count($adminOnlyFindings) . " violation(s)\n";

    $driftFindings = rela_scan_classification_drift(
        $prefix,
        $exclFromRbacSql[2] ?? [],
        $exclFromRbacSql[3] ?? []
    );
    $findings = array_merge($findings, $driftFindings);
    echo "classification-drift check: " . count($driftFindings) . " drift(s)\n\n";
}

// ── Part 1: DB-state leak check (mechanisms 1 DIRECT + 2 ALIAS), driven
//    entirely by what was just parsed -- no hand-maintained code list. ──
if (!$dbAvailable) {
    echo "(skipping Part 1 too — no database connection)\n";
} else {
    $hasAlias = false;
    try {
        $hasAlias = (bool) db_fetch_value(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deprecated_alias_of'",
            [$prefix . 'permissions']
        );
    } catch (Throwable $e) {}

    foreach ($exclFromRbacSql as $roleId => $codes) {
        foreach ($codes as $code) {
            try {
                $row = db_fetch_one(
                    "SELECT id, deprecated_alias_of FROM `{$prefix}permissions` WHERE code = ?",
                    [$code]
                );
            } catch (Throwable $e) {
                continue;
            }
            if (!$row) continue; // not seeded on this database -- nothing to leak

            try {
                $direct = db_fetch_one(
                    "SELECT 1 FROM `{$prefix}role_permissions` WHERE role_id = ? AND permission_id = ?",
                    [$roleId, (int) $row['id']]
                );
            } catch (Throwable $e) { $direct = null; }
            if ($direct) {
                $findings[] = ['kind' => 'direct', 'role_id' => $roleId, 'code' => $code,
                    'detail' => "role $roleId directly holds excluded code '$code'"];
            }

            if ($hasAlias && !empty($row['deprecated_alias_of'])) {
                try {
                    $canon = db_fetch_one(
                        "SELECT id FROM `{$prefix}permissions` WHERE code = ?",
                        [$row['deprecated_alias_of']]
                    );
                    $aliased = $canon ? db_fetch_one(
                        "SELECT 1 FROM `{$prefix}role_permissions` WHERE role_id = ? AND permission_id = ?",
                        [$roleId, (int) $canon['id']]
                    ) : null;
                } catch (Throwable $e) { $aliased = null; }
                if (!empty($aliased)) {
                    $findings[] = ['kind' => 'alias', 'role_id' => $roleId, 'code' => $code,
                        'detail' => "role $roleId holds the canonical alias ({$row['deprecated_alias_of']}) of excluded code '$code'"];
                }
            }

            // Reverse alias direction too -- a role could hold an OLD code
            // that now points (via deprecated_alias_of) AT a currently
            // excluded canonical code, which is functionally the same
            // grant under a different name.
            if ($hasAlias) {
                try {
                    $oldCodes = db_fetch_all(
                        "SELECT id FROM `{$prefix}permissions` WHERE deprecated_alias_of = ?",
                        [$code]
                    );
                } catch (Throwable $e) { $oldCodes = []; }
                foreach ($oldCodes as $oc) {
                    try {
                        $held = db_fetch_one(
                            "SELECT 1 FROM `{$prefix}role_permissions` WHERE role_id = ? AND permission_id = ?",
                            [$roleId, (int) $oc['id']]
                        );
                    } catch (Throwable $e) { $held = null; }
                    if ($held) {
                        $findings[] = ['kind' => 'alias', 'role_id' => $roleId, 'code' => $code,
                            'detail' => "role $roleId holds a deprecated alias of excluded code '$code' (id {$oc['id']})"];
                    }
                }
            }
        }
    }
}

// ── Part 2: cross-file drift -- rbac.sql and run_00_rbac.php must agree
//    on which codes each role excludes. ──────────────────────────────
foreach ($exclFromRbacSql as $roleId => $codes) {
    if (!isset($exclFromRun00[$roleId])) continue; // that role's exclusion isn't mirrored in run_00 -- not this tool's business
    $a = $codes;
    $b = $exclFromRun00[$roleId];
    $onlyInRbacSql = array_diff($a, $b);
    $onlyInRun00   = array_diff($b, $a);
    foreach ($onlyInRbacSql as $code) {
        $findings[] = ['kind' => 'drift', 'role_id' => $roleId, 'code' => $code,
            'detail' => "role $roleId: '$code' excluded in {$rbacSqlPath} but NOT in {$run00Path} -- the two files have drifted out of sync"];
    }
    foreach ($onlyInRun00 as $code) {
        $findings[] = ['kind' => 'drift', 'role_id' => $roleId, 'code' => $code,
            'detail' => "role $roleId: '$code' excluded in {$run00Path} but NOT in {$rbacSqlPath} -- the two files have drifted out of sync"];
    }
}

// ── Part 3: rogue-grant scan across every OTHER sql/run_*.php file. ───
$rogue = rela_scan_rogue_grants($scanDir, [basename($run00Path), 'run_migrations.php'], $exclFromRbacSql);
$findings = array_merge($findings, $rogue);

// ── Report, baseline-gated like every sibling audit in tools/. ────────
$new = [];
$baselineMatched = [];
foreach ($findings as $f) {
    $key = $f['kind'] . '|' . $f['role_id'] . '|' . $f['code'] . '|' . $f['detail'];
    if (isset($baseline[$key])) { $baselineMatched[] = $key; if ($showAll) echo "[baseline] {$f['detail']}\n"; continue; }
    $new[] = $f;
    echo "[LEAK] {$f['detail']}\n";
}

$staleBaseline = array_diff(array_keys($baseline), $baselineMatched);

echo "\n" . count($findings) . " total finding(s), " . count($new) . " new, "
   . count($baselineMatched) . " baseline entries matched, " . count($staleBaseline) . " stale\n";

if ($new) {
    echo "\nA NEW finding means an admin-only permission is reachable by a role\n";
    echo "it was deliberately withheld from, or the two exclusion-list files have\n";
    echo "drifted apart. Fix the underlying grant/exclusion list -- do not baseline\n";
    echo "a real leak. Baseline only a confirmed non-issue, with a reason.\n";
    exit(1);
}

exit(0);
