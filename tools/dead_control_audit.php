<?php
/**
 * Dead-control audit (GH #91, 2026-08-19).
 *
 * THE DISEASE: a control that saves, or a column that exists, with
 * nothing on the other end. Four instances in one day of testing — the
 * four Chat Bridge checkboxes under Settings -> Chat Settings (#89, now
 * wired to real routing rules), Settings -> Severity Levels label fields
 * (#84/#88, fixed the same night this tool was built), Settings ->
 * Notification Rules (a described-but-unbuilt stub), and ~12 `user`
 * table columns nothing reads. The reporter's own
 * framing is why this is worth a permanent gate and not a one-time
 * cleanup: "the cost isn't the dead code itself, it's that it reads as
 * live." A checkbox that persists looks exactly like the switch an
 * administrator needs; a column commented 'For level = facility' looks
 * exactly like the hook you'd populate to add a feature. The honest
 * answer only comes from grepping the whole tree and finding nothing —
 * fine for one report, not something every operator should have to do
 * to their own install.
 *
 * Same family as tools/schema_audit.php (SQL vs the real schema),
 * tools/api_contract_audit.php (JS reads vs API emits), and
 * tools/legacy_level_audit.php (API gate vs page gate) — a thing on one
 * side of a boundary with nothing on the other. This is the first in the
 * family that isn't about two things DISAGREEING; it's about one thing
 * existing and the other being simply ABSENT.
 *
 * TWO INDEPENDENT CHECKS:
 *
 * (a) DEAD SETTINGS KEY — a `data-key="foo"` control in a page template.
 *     Every such control is picked up generically by
 *     collectSettingsFromForm() (assets/js/config.js) and POSTed to
 *     api/config-admin.php?section=settings, which upserts it into the
 *     `settings` table with NO validation that anything downstream cares.
 *     The value is even read BACK into the same control on next page
 *     load (apiGet('settings') -> applySettingsToForm()) — so the round
 *     trip looks completely alive from the browser. The only way to tell
 *     a real setting from a decorative one is whether anything in
 *     application code ever calls get_variable('foo') (or the
 *     equivalent direct `SELECT value FROM settings WHERE name = 'foo'`)
 *     to actually consult the value.
 *
 *     Detection:
 *       1. WRITTEN = every `data-key="..."` found in a *.php page
 *          template at the webroot (settings.php and any future admin
 *          page — NOT assets/js, NOT tests, NOT docs).
 *       2. READ = every literal `get_variable('...')` /
 *          `get_variable("...")` argument, and every literal value
 *          compared against a `name` column (covers the direct-SQL
 *          `SELECT value FROM settings WHERE name = '...'` shape used
 *          in a few older call sites), found across api/, inc/, tools/,
 *          services/, proxy/ (never tests/, docs/, or the page
 *          templates themselves — a page reading its OWN posted value
 *          back into the form is the illusion this tool exists to see
 *          through, not evidence of a real read).
 *       3. BROADENING for dynamic call sites: several channel handlers
 *          (inc/channels/telegram.php, slack.php, sms.php, ...) build
 *          the key name from a loop variable — `get_variable($k)` where
 *          $k walks a literal array defined nearby. A literal-only scan
 *          would call every one of those dead. Same technique
 *          api_contract_audit.php already uses for its EMITTABLE set:
 *          whenever a file contains a *dynamic* get_variable($var) call
 *          or a `name` = ? bound-parameter settings lookup, every
 *          snake_case string literal in that FILE (not the whole tree —
 *          narrower than api_contract_audit's global union, since a
 *          settings key list is normally local to the one handler that
 *          owns it) is added to the READ set. This trades a few false
 *          negatives (a key list defined in one file, consumed via a
 *          dynamic call in another) for keeping false positives near
 *          zero, matching this project's standing rule that a gate which
 *          cries wolf gets baselined into uselessness.
 *       4. FINDING = a WRITTEN key with no matching READ anywhere.
 *
 *     Known limitation (matches the reporter's own caveat): a settings
 *     key consulted only through a totally different lookup helper this
 *     tool doesn't know about would not be found as a read and would
 *     misreport as dead. None have been found in this codebase as of
 *     2026-08-19 — get_variable() and the direct `name = '...'`/`name = ?`
 *     shape are the only two read paths `settings` has ever had (see
 *     docs/SCHEMA-REFERENCE.md's "TWO settings stores" note) — but a
 *     future third path would need teaching to this tool the same way
 *     the dynamic-call broadening was.
 *
 * (b) DEAD DATABASE COLUMN — a column present in the live schema that
 *     application code writes (INSERT column list, or the target of an
 *     UPDATE ... SET) but whose VALUE is never consulted anywhere:
 *     never selected explicitly, never referenced via an alias-qualified
 *     WHERE/JOIN/ORDER/HAVING clause, and never read back out of a
 *     fetched row in PHP or JS. This is the `user.level` /
 *     `user.pers` / `user.open_at` / ... class of finding.
 *
 *     Detection re-uses schema_audit.php's alias-resolution approach
 *     (FROM/JOIN `table` [AS] alias -> table map) so a reference like
 *     `u.level` can be attributed to the right table, then classifies
 *     every alias-qualified column reference in a SQL string as either:
 *       - a WRITE-TARGET (a bare `col` immediately before `=` inside an
 *         UPDATE ... SET clause, or inside an INSERT INTO table (...)
 *         column list), or
 *       - everything else (SELECT list, WHERE/JOIN/ORDER/GROUP/HAVING,
 *         the right-hand side of a SET assignment) -> a READ.
 *     Then, GLOBALLY and independent of any single SQL string, any bare
 *     PHP array-key read (`['col']`, whatever the base variable) or
 *     object-property read (`->col`) found ANYWHERE in api/, inc/,
 *     tools/, services/, proxy/, the page templates, or assets/js/ also
 *     counts as a READ. This is the deliberate, DOCUMENTED trade-off:
 *     it is what makes SELECT * safe (a column selected with `*` can
 *     still be proven "read" if code later does `$row['col']`) but it
 *     is table-blind — `$row['sort_order']` proves SOME table's
 *     sort_order column is read, not necessarily `user`'s. A column
 *     whose name collides with a live column on a different, unrelated
 *     table can therefore read as "used" when the `user` copy of it
 *     never actually is. This tool is built to under-report rather than
 *     cry wolf, exactly like schema_audit.php's own "bare column names
 *     are too ambiguous to attribute to a table" stance — it catches
 *     the whole class this tool exists for (isolated, no-real-collision
 *     column names, which is the overwhelming majority) and stays
 *     silent on the ambiguous remainder rather than false-alarming on
 *     it. A genuinely dead column with a common name (id, name,
 *     status, sort_order, ...) will slip through this tool undetected;
 *     the GH #91 sweep that first ran this tool treated a human-vetted
 *     list (the issue's own ~12 `user` columns) as the floor, not the
 *     ceiling, for exactly this reason — read the tool's OUTPUT as a
 *     lead, not a verdict, the same caveat the issue reporter gave for
 *     their own grep-based list.
 *
 *     Scope: every table in the live schema is scanned by default
 *     (`--table=name` narrows to one, for iterating on a single result).
 *     `sql/`, `tests/` and migration/import tooling are excluded from
 *     the WRITE-target and READ scans — a one-time importer writing a
 *     legacy column is not evidence the running application uses it,
 *     and this tool's job is the running application, not history.
 *
 * Baseline / exit code: identical contract to the sibling audits. Exit
 * 0 = clean or every finding already in the baseline; exit 1 = a NEW
 * finding. Baselines: tools/dead_control_settings_baseline.txt (one
 * `setting:<key>` per line) and tools/dead_control_column_baseline.txt
 * (one `column:<table>.<col>` per line) — both require a comment
 * explaining WHY a finding is accepted rather than fixed, per this
 * project's standing convention for every baseline file.
 *
 * Usage:
 *   php tools/dead_control_audit.php                # both checks
 *   php tools/dead_control_audit.php --settings-only
 *   php tools/dead_control_audit.php --columns-only
 *   php tools/dead_control_audit.php --table=user    # columns, one table
 *   php tools/dead_control_audit.php --all           # include baselined
 *   php tools/dead_control_audit.php --path=DIR      # scan DIR instead of
 *                                                     # the real app tree
 *                                                     # (tests/fixtures only —
 *                                                     # see tests/test_dead_control_audit.php).
 *                                                     # The live schema/DB is
 *                                                     # always the real one
 *                                                     # (same convention as
 *                                                     # tools/rbac_permission_audit.php:
 *                                                     # what's SCANNED can be a
 *                                                     # fixture, what's VALIDATED
 *                                                     # AGAINST is always real).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// Captured BEFORE any chdir — config.php/inc/db.php are always the real
// app's, even when --path scans a fixture tree elsewhere.
$appRoot = realpath(__DIR__ . '/..');

$argvList       = $argv ?? [];
$showAll        = in_array('--all', $argvList, true);
$settingsOnly   = in_array('--settings-only', $argvList, true);
$columnsOnly    = in_array('--columns-only', $argvList, true);
$onlyTable      = null;
$scanRoot       = $appRoot;
foreach ($argvList as $a) {
    if (strpos($a, '--table=') === 0) { $onlyTable = strtolower(substr($a, 8)); }
    if (strpos($a, '--path=') === 0) {
        $p = realpath(substr($a, 7));
        if ($p !== false) { $scanRoot = $p; }
    }
}
chdir($scanRoot);

/** Every file with a given extension under a directory, forward-slashed. */
function dca_files(string $dir, string $ext = 'php'): array {
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
        if (!$f->isFile()) continue;
        if (strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION)) !== $ext) continue;
        $out[] = str_replace('\\', '/', $f->getPathname());
    }
    sort($out);
    return $out;
}

// Directories that hold RUNNING application code (as opposed to one-time
// migration/import tooling, tests, or docs). Shared by both checks.
$appPhpDirs = ['api', 'inc', 'tools', 'services', 'proxy'];
// Within tools/, one-time migration/upgrade helpers legitimately touch
// columns the running app no longer does — exclude by path substring,
// same technique and same "keep it short and reviewable" discipline as
// legacy_level_audit.php's lla_is_migration_path().
function dca_is_migration_tooling(string $path): bool {
    static $exempt = [
        'tools/upgrade/',
        'tools/migrate_rbac.php',
        'tools/create_admin.php',      // one-time account bootstrap, not a read path
        'tools/legacy_level_audit.php',
        'tools/schema_audit.php',
        'tools/api_contract_audit.php',
        'tools/dead_control_audit.php', // this file's own doc-comment examples
    ];
    foreach ($exempt as $e) { if (strpos($path, $e) !== false) return true; }
    return false;
}

$pageTemplates = glob('*.php') ?: [];

echo "=== Dead-control audit (GH #91) ===\n";
echo "Two checks: (a) settings-table keys a UI writes but nothing reads;\n";
echo "            (b) database columns written but never read back.\n\n";

$findings = [];   // key => [ 'kind'=>..., 'sites'=>[[file,line,msg],...] ]

// ═══════════════════════════════════════════════════════════════════════
// (a) Dead settings keys
// ═══════════════════════════════════════════════════════════════════════
if (!$columnsOnly) {
    echo "--- (a) settings-table keys ---\n";

    // 1. WRITTEN: data-key="..." in page templates only.
    $written = [];   // key => [[file,line], ...]
    foreach ($pageTemplates as $f) {
        $src = @file_get_contents($f);
        if ($src === false) continue;
        if (preg_match_all('/data-key="([a-zA-Z0-9_.]+)"/', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$key, $off]) {
                $line = 1 + substr_count(substr($src, 0, $off), "\n");
                $written[$key][] = [$f, $line];
            }
        }
    }
    echo count($written) . " distinct settings key(s) written via data-key=\"...\"\n";

    // 2. READ: literal get_variable(...) / get_setting(...) args, literal
    //    `name` = '...' comparisons, AND bare array-key reads of the
    //    settings payload (`$config['key']`, `$settings['key']`, ...) —
    //    across running application code AND page templates (every page
    //    except settings.php itself: settings.php's only per-key PHP-side
    //    access is the generic, illusion-producing round trip this tool
    //    exists to see through, not a real consumer).
    //
    //    get_setting() is included deliberately even though it reads a
    //    DIFFERENT table (`config`, not `settings` — see docs/SCHEMA-
    //    REFERENCE.md's "TWO settings stores" note): a key written via
    //    data-key (always `settings`) and consumed only via get_setting()
    //    (always `config`) is unambiguously the GH #79 cross-store bug,
    //    not a real read — but this tool cannot tell the two apart
    //    without a second schema-aware pass, so it counts get_setting()
    //    reads as "read" and relies on the fix being made by a human (or
    //    a future, more precise version of this check) rather than
    //    silently mis-scoring a cross-store bug as "dead" when it is
    //    really "wired to the wrong store". Documented, not solved, here.
    $literalRead   = [];   // key => true
    $dynamicFiles  = [];   // file => true (contains a dynamic lookup)
    $fileLiterals  = [];   // file => [snake_case literal, ...]
    $readFiles = [];
    foreach ($appPhpDirs as $d) { $readFiles = array_merge($readFiles, dca_files($d)); }
    foreach ($pageTemplates as $f) {
        if (strtolower($f) === 'settings.php') continue;
        $readFiles[] = $f;
    }

    foreach ($readFiles as $f) {
        if (dca_is_migration_tooling($f)) continue;
        $src = @file_get_contents($f);
        if ($src === false) continue;

        if (preg_match_all('/get_(?:variable|setting)\(\s*[\'"]([a-zA-Z0-9_.]+)[\'"]/', $src, $m)) {
            foreach ($m[1] as $k) $literalRead[$k] = true;
        }
        // Direct-SQL shape: `name` = 'literal' or name='literal' anywhere
        // a settings row is looked up by name.
        if (preg_match_all('/`?name`?\s*=\s*[\'"]([a-zA-Z0-9_.]+)[\'"]/', $src, $m)) {
            foreach ($m[1] as $k) $literalRead[$k] = true;
        }
        // Bare array-key reads of a settings payload: $config['key'],
        // $settings['key'], $s['key'], $data['settings']['key'], ... —
        // the shape every non-generic consumer actually uses once it has
        // the settings row/array in hand (proxy/, inc/channels/*.php).
        if (preg_match_all('/\[\s*[\'"]([a-zA-Z0-9_.]+)[\'"]\s*\]/', $src, $m)) {
            foreach ($m[1] as $k) $literalRead[$k] = true;
        }

        $isDynamic = (bool) preg_match('/get_(?:variable|setting)\(\s*\$/', $src)
            || (bool) preg_match('/`?name`?\s*=\s*\?/', $src);
        if ($isDynamic) {
            $dynamicFiles[$f] = true;
            if (preg_match_all('/[\'"]([a-z][a-z0-9]*(?:_[a-z0-9]+)+)[\'"]/', $src, $m)) {
                $fileLiterals[$f] = $m[1];
            }
        }
    }
    foreach ($dynamicFiles as $f => $_) {
        foreach ($fileLiterals[$f] ?? [] as $k) { $literalRead[$k] = true; }
    }
    echo count($literalRead) . " distinct key(s) read (get_variable()/get_setting()/direct-SQL/"
        . "bare array-key, " . count($dynamicFiles) . " file(s) broadened for dynamic lookups)\n";

    // 3. Findings.
    foreach ($written as $key => $sites) {
        if (isset($literalRead[$key])) continue;
        $findings["setting:$key"] = ['kind' => 'setting', 'sites' => $sites];
    }
    echo count($findings) . " dead settings key finding(s) so far\n\n";
}
$findingsAfterSettings = count($findings);

// ═══════════════════════════════════════════════════════════════════════
// (b) Dead database columns
// ═══════════════════════════════════════════════════════════════════════
if (!$settingsOnly) {
    echo "--- (b) database columns ---\n";

    if (!is_file($appRoot . '/config.php')) {
        echo "config.php not found — column check needs a reachable database, skipping\n";
    } else {
        require_once $appRoot . '/config.php';
        require_once $appRoot . '/inc/db.php';
        require_once __DIR__ . '/sql_extract.php';

        $prefix = $GLOBALS['db_prefix'] ?? '';
        $schema = [];   // table => [col => true]
        foreach (db_fetch_all(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()"
        ) as $r) {
            $schema[strtolower($r['TABLE_NAME'])][strtolower($r['COLUMN_NAME'])] = true;
        }
        echo count($schema) . " tables loaded from live schema\n";

        // Files to scan for SQL: running app code + page templates.
        $sqlFiles = $pageTemplates;
        foreach ($appPhpDirs as $d) { $sqlFiles = array_merge($sqlFiles, dca_files($d)); }
        $sqlFiles = array_values(array_filter($sqlFiles, function ($f) {
            return !dca_is_migration_tooling($f);
        }));

        $writeCols = [];   // "table.col" => [[file,line],...]
        $readColsSql = []; // "table.col" => true   (alias-qualified, non-write-target)

        /**
         * Parse one SQL string. Mirrors schema_audit.php's alias
         * resolution (kept in sync deliberately — both tools read the
         * same FROM/JOIN/UPDATE/INSERT shapes).
         */
        $parseSql = function (string $sql, string $file, int $line) use (&$writeCols, &$readColsSql, &$schema) {
            // sql_extract_normalize() strips leftover {$prefix}-style
            // interpolation remnants (db_table() calls are already resolved
            // to bare table names by sql_extract_strings() itself); the
            // whitespace collapse below is this tool's own, so the many
            // regexes further down can assume single-space separation
            // across what may have been a multi-line, re-indented SQL string.
            $norm = sql_extract_normalize($sql);
            $norm = preg_replace('/\s+/', ' ', $norm);

            $aliases = [];
            if (preg_match_all(
                '/(?:FROM|JOIN)\s+`?([a-z0-9_]+)`?\s+(?:AS\s+)?`?([a-z0-9_]+)`?/i',
                $norm, $mm, PREG_SET_ORDER
            )) {
                foreach ($mm as $m) {
                    $tbl = strtolower($m[1]);
                    $ali = strtolower($m[2]);
                    if (in_array($ali, ['on', 'set', 'where', 'left', 'right', 'inner',
                        'outer', 'join', 'group', 'order', 'limit', 'using', 'cross',
                        'straight_join', 'values', 'select'], true)) {
                        $ali = $tbl;
                    }
                    $aliases[$ali] = $tbl;
                }
            }
            if (preg_match_all('/(?:FROM|JOIN)\s+`?([a-z0-9_]+)`?/i', $norm, $mm2)) {
                foreach ($mm2[1] as $tbl) {
                    $tbl = strtolower($tbl);
                    if (!isset($aliases[$tbl])) $aliases[$tbl] = $tbl;
                }
            }
            $updateTable = null;
            if (preg_match('/(?<!KEY )UPDATE\s+`?([a-z0-9_]+)`?\s+SET\b/i', $norm, $mu)) {
                $updateTable = strtolower($mu[1]);
                if (!isset($aliases[$updateTable])) $aliases[$updateTable] = $updateTable;
            }
            $insertTable = null;
            if (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?([a-z0-9_]+)`?\s*\(/i', $norm, $mi)) {
                $insertTable = strtolower($mi[1]);
            }

            // Write-target spans: the SET clause's bare `col = ` list, and
            // the INSERT column-list parens. Bare (no alias) — collected
            // separately from the alias.col regex below.
            if ($updateTable !== null
                && preg_match('/\bSET\s+(.*?)(?:\bWHERE\b|$)/is', $norm, $ms)) {
                if (preg_match_all('/(?:^|,)\s*`?([a-z0-9_]+)`?\s*=/i', $ms[1], $mc)) {
                    foreach ($mc[1] as $col) {
                        $col = strtolower($col);
                        if (isset($schema[$updateTable][$col])) {
                            $writeCols["$updateTable.$col"][] = [$file, $line];
                        }
                    }
                }
            }
            if ($insertTable !== null
                && preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?[a-z0-9_]+`?\s*\(([^)]+)\)/i', $norm, $mi2)) {
                foreach (explode(',', $mi2[1]) as $col) {
                    $col = strtolower(trim(trim($col), '` '));
                    if ($col !== '' && isset($schema[$insertTable][$col])) {
                        $writeCols["$insertTable.$col"][] = [$file, $line];
                    }
                }
            }
            // ON DUPLICATE KEY UPDATE `col` = VALUES(`col`) is also a write.
            if (preg_match('/ON\s+DUPLICATE\s+KEY\s+UPDATE\s+(.*)$/is', $norm, $md) && $insertTable !== null) {
                if (preg_match_all('/`?([a-z0-9_]+)`?\s*=\s*VALUES\s*\(/i', $md[1], $mdc)) {
                    foreach ($mdc[1] as $col) {
                        $col = strtolower($col);
                        if (isset($schema[$insertTable][$col])) {
                            $writeCols["$insertTable.$col"][] = [$file, $line];
                        }
                    }
                }
            }

            // All alias-qualified references anywhere in the string count
            // as a READ. This deliberately over-counts a SET clause's own
            // `alias.col` form (rare in this codebase — most UPDATEs use
            // bare `col = ` inside SET, matched above instead) and a
            // WHERE clause that also happens to run against the just-
            // updated table; both are genuine reads of the CURRENT value
            // in SQL terms (a WHERE always reads), so this is correct,
            // not a loophole.
            if (preg_match_all('/`?([a-z0-9_]+)`?\.`([a-z0-9_]+)`|\b([a-z0-9_]+)\.([a-z0-9_]+)\b/i', $norm, $mcc, PREG_SET_ORDER)) {
                foreach ($mcc as $m) {
                    $ali = strtolower($m[1] !== '' ? $m[1] : ($m[3] ?? ''));
                    $col = strtolower($m[1] !== '' ? $m[2] : ($m[4] ?? ''));
                    if ($ali === '' || $col === '' || $col === '*') continue;
                    if (!isset($aliases[$ali])) continue;
                    $tbl = $aliases[$ali];
                    if (!isset($schema[$tbl][$col])) continue;
                    $readColsSql["$tbl.$col"] = true;
                }
            }

            // Bare (non-alias-qualified) identifiers in the SELECT column
            // list — "SELECT id, phone_m AS cell FROM user" — also count as
            // a read. Without this, a column only ever selected unqualified
            // (single-table query, no `AS table_alias`, often renamed via
            // `AS`) reads as unused even though its value is fetched and
            // used under the alias. This is exactly what the GH #91 issue
            // itself flagged as a near-miss: `user.phone_m` looked dead by
            // simple grep before the #84 fix added `phone_m AS cell`, and a
            // column-name-only scan would have called it dead again here.
            // Credits EVERY table referenced in the query (not just one) —
            // ambiguous in a join, but that only widens what counts as
            // "read", which is the safe direction for this tool.
            if (preg_match('/^\s*SELECT\s+(?:DISTINCT\s+)?(.*?)\s+FROM\s+/is', $norm, $msel)) {
                $collistBare = preg_replace('/\b[a-z0-9_]+\s*\.\s*`?[a-z0-9_]+`?/i', '', $msel[1]);
                if (preg_match_all('/\b([a-z][a-z0-9_]*)\b/i', $collistBare, $mb)) {
                    static $selectStop = null;
                    if ($selectStop === null) {
                        $selectStop = array_flip(['select', 'distinct', 'as', 'from', 'count', 'sum',
                            'avg', 'min', 'max', 'case', 'when', 'then', 'else', 'end', 'and', 'or',
                            'not', 'null', 'is', 'in', 'like', 'between', 'concat', 'coalesce', 'if',
                            'ifnull', 'now', 'date', 'datediff', 'group_concat', 'cast', 'convert',
                            'exists', 'over', 'partition', 'by', 'order', 'row_number', 'left', 'right']);
                    }
                    foreach ($mb[1] as $bareCol) {
                        $bareCol = strtolower($bareCol);
                        if (isset($selectStop[$bareCol])) continue;
                        foreach (array_unique(array_values($aliases)) as $tbl) {
                            if (isset($schema[$tbl][$bareCol])) {
                                $readColsSql["$tbl.$bareCol"] = true;
                            }
                        }
                    }
                }
            }
        };

        // Phase 125 (2026-07-26) found schema_audit.php blind to every SQL
        // string built as a concatenation chain — "UPDATE " . db_table('user')
        // . " SET info = ?, phone_p = ? ..." is THREE tokens; a check that
        // requires the verb and the column list in the SAME literal sees
        // none of it. That is the shared extractor tools/sql_extract.php
        // exists to fix, and this tool uses it rather than re-deriving its
        // own (weaker) tokenizer, for the same reason api/profile.php's
        // `UPDATE ... SET phone_p = ?` write was invisible here until this
        // was wired in: a bare, unbackticked, concatenation-built UPDATE is
        // exactly the shape half the real writers in this codebase use.
        foreach ($sqlFiles as $file) {
            $src = @file_get_contents($file);
            if ($src === false) continue;
            foreach (sql_extract_strings($src) as [$line, $str]) {
                if ($str === '' || !sql_extract_is_query($str)) continue;
                $parseSql($str, $file, $line);
            }
        }
        echo count($sqlFiles) . " PHP files scanned for SQL\n";
        echo count($writeCols) . " column(s) with a confirmed write path\n";

        // GLOBAL bare-key/property read evidence, whole application tree
        // (PHP + JS). Deliberately table-blind — see the docblock.
        $bareRead = [];   // colname => true
        $bareScanFiles = $pageTemplates;
        foreach ($appPhpDirs as $d) { $bareScanFiles = array_merge($bareScanFiles, dca_files($d)); }
        $bareScanFiles = array_merge($bareScanFiles, dca_files('assets/js', 'js'));
        foreach ($bareScanFiles as $f) {
            if (dca_is_migration_tooling($f)) continue;
            $src = @file_get_contents($f);
            if ($src === false) continue;
            if (preg_match_all('/\[\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*\]/', $src, $m)) {
                foreach ($m[1] as $k) $bareRead[strtolower($k)] = true;
            }
            if (preg_match_all('/->([a-zA-Z_][a-zA-Z0-9_]*)\b/', $src, $m)) {
                foreach ($m[1] as $k) $bareRead[strtolower($k)] = true;
            }
        }
        echo count($bareRead) . " distinct bare array-key/property name(s) read anywhere in the app\n";

        foreach ($writeCols as $tc => $sites) {
            [$tbl, $col] = explode('.', $tc, 2);
            if ($onlyTable !== null && $tbl !== $onlyTable) continue;
            if (isset($readColsSql[$tc])) continue;      // aliased SQL read
            if (isset($bareRead[$col])) continue;         // bare PHP/JS read anywhere
            $findings["column:$tc"] = ['kind' => 'column', 'sites' => $sites];
        }
        echo (count($findings) - (isset($findingsAfterSettings) ? $findingsAfterSettings : 0))
            . " dead-column finding(s) (columns with a write path but no read anywhere)\n\n";

        // ── Orphaned columns: OPT-IN, not part of the default/CI-gated run ──
        // A column this tool never saw written by the RUNNING app (no INSERT
        // list, no UPDATE SET target, outside migration/import tooling) is a
        // DIFFERENT, weaker signal than "written but never read" — its only
        // value ever comes from the CREATE TABLE default, so there is no live
        // data an operator could mistake for something working. It is still
        // squarely the GH #91 shape (the reporter's own examples — a stale
        // comment reading 'For level = facility' — describe exactly this),
        // and it is how 11 of the 12 `user` columns named in GH #91 actually
        // look: zero writers anywhere, not "written, never read".
        //
        // Deliberately NOT wired into the pre-commit hook or CI: run across
        // all ~260 tables in this schema it would surface a very large,
        // mostly-untriaged pile on day one (rarely-populated optional
        // columns, admin-configured feature flags with no default row yet,
        // etc.) — exactly the "cries wolf, gets baselined into uselessness"
        // failure this project's other audits are built to avoid. Use
        // `--include-orphaned` for a deliberate, manual sweep (optionally
        // scoped with `--table=`), the way the GH #91 investigation itself
        // used it against `user` specifically before deciding what to do
        // with each column.
        if (in_array('--include-orphaned', $argvList, true)) {
            $orphanCount = 0;
            foreach ($schema as $tbl => $cols) {
                if ($onlyTable !== null && $tbl !== $onlyTable) continue;
                foreach (array_keys($cols) as $col) {
                    $tc = "$tbl.$col";
                    if (isset($writeCols[$tc])) continue;      // has a real write path
                    if (isset($readColsSql[$tc])) continue;    // aliased SQL read
                    if (isset($bareRead[$col])) continue;      // bare PHP/JS read anywhere
                    $findings["column:$tc"] = [
                        'kind' => 'column',
                        'sites' => [["(schema: $tbl.$col)", 0]],
                    ];
                    $orphanCount++;
                }
            }
            echo "$orphanCount orphaned-column finding(s) (--include-orphaned: no write path AND no read found)\n\n";
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Report
// ═══════════════════════════════════════════════════════════════════════
// Baselines are always the real app's, even during a --path fixture run —
// a fixture finding should never silently pass by matching an unrelated
// real-app baseline entry, and it never will (fixture table/key names in
// tests are deliberately fake), but this keeps the CONTRACT explicit.
$settingsBaselineFile = $appRoot . '/tools/dead_control_settings_baseline.txt';
$columnBaselineFile   = $appRoot . '/tools/dead_control_column_baseline.txt';
$baseline = [];
foreach ([$settingsBaselineFile, $columnBaselineFile] as $bf) {
    if (!is_file($bf)) continue;
    foreach (file($bf, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $baseline[$line] = true;
    }
}

ksort($findings);
$newCount = 0;
foreach ($findings as $key => $f) {
    $inBaseline = isset($baseline[$key]);
    if ($inBaseline && !$showAll) continue;
    if (!$inBaseline) $newCount++;
    $label = $f['kind'] === 'setting'
        ? "settings key `" . substr($key, 8) . "` written by a UI control, read by nothing"
        : "column `" . substr($key, 7) . "` has a write path, its value is read nowhere";
    echo ($inBaseline ? '[baseline] ' : '[NEW]      ') . $key . " — $label\n";
    foreach (array_slice($f['sites'], 0, 5) as [$sf, $sl]) {
        echo "             $sf:$sl\n";
    }
    if (count($f['sites']) > 5) echo '             … +' . (count($f['sites']) - 5) . " more site(s)\n";
}

echo "\n" . count($findings) . " distinct finding(s), $newCount new (not in baseline)\n";
exit($newCount === 0 ? 0 : 1);
