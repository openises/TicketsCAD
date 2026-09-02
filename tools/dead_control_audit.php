<?php
/**
 * Dead-control audit (GH #91, 2026-08-19; extended 2026-08-20 with checks
 * (c) and (d) — the two other directions of the same pattern).
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
 * Eric's own framing (2026-08-20), after the first two checks shipped:
 * the general pattern here is "something with a producer and no
 * consumer, or a consumer and no producer" — a systematic, ongoing
 * discovery practice, not a one-off. Checks (a) and (b) below only ever
 * covered the PRODUCER-with-no-CONSUMER half of that pattern (a settings
 * write nothing reads, a column write nothing reads). Checks (c) and (d)
 * close the other half — a CONSUMER with no PRODUCER — at the two
 * boundaries this tool already understands (the database, and the
 * API-to-browser JSON boundary).
 *
 * FOUR INDEPENDENT CHECKS:
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
 * (c) PHANTOM DATABASE COLUMN — the mirror of (b): a column whose value
 *     IS consulted somewhere (a WHERE/JOIN/ORDER/HAVING reference
 *     resolvable to a real table via the SAME alias-resolution pass
 *     check (b) uses, a bare column selected in a SELECT list, or a bare
 *     PHP/JS `$row['col']`/`->col` read anywhere in the app) but has NO
 *     confirmed write path anywhere — no INSERT column list, no
 *     UPDATE ... SET target, no ON DUPLICATE KEY UPDATE target (the SAME
 *     $writeCols detection check (b) already builds; this check does not
 *     re-derive write detection, it shares check (b)'s own single parse
 *     pass over the SAME SQL files, so the two checks can never quietly
 *     disagree about what counts as "written").
 *
 *     This is historically the most dangerous shape in THIS codebase —
 *     not a hypothetical. Bed automation read `assigns.rec_facility_id`
 *     for weeks while nothing on any real dispatch path wrote it (Phase
 *     95/116, see this file's own CLAUDE.md pitfall entry); the general
 *     "unused column inherited into a rewritten/migrated system" smell
 *     is a standing root-cause-troubleshooting hard-stop for this
 *     project. A phantom read doesn't error — it silently resolves to
 *     NULL/empty forever, which reads as "no data yet" rather than
 *     "broken", so it can hide for months. A dead WRITE (check (b)) at
 *     least does nothing loudly (the column visibly never changes); a
 *     phantom READ does something quietly wrong (the feature reading it
 *     looks like it works, on an empty/null value, forever).
 *
 *     Detection, sharing check (b)'s single parse pass over the same SQL
 *     files (never re-parsed separately):
 *       - READ evidence (STRONG, table-attributed): `table.col` present
 *         in the SAME `$readColsSql` map check (b) already builds —
 *         alias-qualified references anywhere in a statement, and bare
 *         SELECT-list columns credited to every table the statement's
 *         FROM/JOIN touches. This is exactly as reliable as check (b)'s
 *         own $writeCols set, since it comes from the identical parser.
 *       - READ evidence (WEAKER, scope-limited): a bare PHP/JS
 *         `$row['col']`/`->col` read anywhere in the app (the SAME
 *         `$bareRead` set check (b) already builds, there used only to
 *         SUPPRESS its own false "dead" claims) — but here credited to
 *         a `table.col` pair ONLY when that TABLE is one the app's own
 *         SQL touches somewhere (`$sqlTouchedTables`, built from every
 *         FROM/JOIN/UPDATE/INSERT/DELETE target seen during the shared
 *         parse pass). Without this scoping, a column name as common as
 *         `id` or `name` would credential every one of this schema's
 *         ~260 tables at once purely from unrelated bareRead evidence —
 *         scoping to tables the running application actually queries
 *         keeps the candidate space bounded to real, in-use tables
 *         instead of the full schema.
 *       - WRITE evidence: `table.col` present in `$writeCols` (check
 *         (b)'s own confirmed-write set — unchanged, not widened here).
 *       - FINDING = read evidence present (either kind), write evidence
 *         absent.
 *
 *     Known limitations (the same honesty check (b) already applies to
 *     its own ambiguity, extended here):
 *       - A column populated ONLY by a one-time migration/import
 *         backfill (sql/, tools/upgrade/, or any path
 *         `dca_is_migration_tooling()` already excludes) reads as
 *         phantom here on purpose — this check's job is the RUNNING
 *         application, not history, same stance check (b) already
 *         takes. A column genuinely meant to be set once (by an
 *         installer/migration) and read forever after by the running
 *         app is a legitimate baseline entry, not a bug — check the
 *         write side didn't simply move to excluded tooling before
 *         accepting that explanation.
 *       - A column read via a JOIN against a table this codebase
 *         doesn't itself own/write (an external integration's own
 *         table, a legacy v3 table kept only for read compatibility)
 *         may show as phantom when the real write happens outside this
 *         codebase entirely — also a legitimate, documented baseline
 *         entry rather than a bug to fix.
 *       - LIMITATION #4 (found during the 2026-08-20 sweep): a table
 *         name built from an OBJECT PROPERTY (`` `{$this->prefix}foo` ``
 *         inside a standalone class, e.g. proxy/ZelloProxyApp.php's
 *         Ratchet daemon) is invisible the same way limitation #1's
 *         plain `$table` variable is — sql_extract_normalize() only
 *         strips `{$prefix}`-shaped and bare-`$var`-before-backtick
 *         interpolation, not `{$this->prop}`. Same fix direction as
 *         limitation #1 (teach sql_extract_normalize() the shape, or
 *         document with a baseline entry citing the real writer) — not
 *         yet taught to the tool as of this writing; every known
 *         instance is baselined.
 *       - LIMITATION #5 (found by CI's genuinely fresh install, not by
 *         any local dev-DB run — the dev box's long-lived database
 *         happened to mask it): a COLUMN NAME discovered entirely at
 *         RUNTIME via `information_schema.COLUMNS.GENERATION_EXPRESSION`
 *         — api/members.php's `getGeneratedColumnMap()` /
 *         `remapGeneratedColumns()` queries the live schema for which
 *         legacy `field<N>` column backs a given GENERATED column (e.g.
 *         `callsign`), then redirects a write to whichever `field<N>`
 *         name that query returns. The literal string naming that
 *         column never appears ANYWHERE in the PHP source — it exists
 *         only as a VALUE inside a PHP array populated from a live SQL
 *         result at request time. No static tool can see this without
 *         actually running the query itself, which is out of scope for
 *         a source-text audit. Every affected `field<N>` column needs
 *         an individual baseline entry when CI (or a fresh install)
 *         surfaces it — there is no way to enumerate the full set
 *         ahead of time without knowing which GENERATED columns a given
 *         install has defined.
 *       - The bareRead half of the read-evidence union is table-blind
 *         by construction (see check (b)'s own docblock above) —
 *         scoping it to `$sqlTouchedTables` reduces but does not
 *         eliminate the risk of crediting table A's read evidence to
 *         table B's same-named column when both tables are queried
 *         somewhere in the app. A genuine false positive here gets
 *         resolved the same way every other ambiguous finding in this
 *         tool is: read the sites, confirm by grep/git history/git log,
 *         then either fix it (the write path really is missing) or
 *         document it in the baseline with a reason.
 *
 *         CONFIRMED REAL INSTANCE (2026-08-22, Phase 149 inbound SIP/PBX
 *         calls): a reporting agent believed this check had a "file-count
 *         sensitivity" BUG — adding one content-empty file anywhere under
 *         api/ or inc/ appeared to newly flag
 *         beta_tester_applications.reviewed_at/.reviewed_by as phantom.
 *         Investigated end to end (isolated clean-main worktree, A/B
 *         tested file-by-file): NO caching, count-keyed state, or
 *         batching exists anywhere in this tool (confirmed by grep — no
 *         file_put_contents()/fopen(...'w'...)/cache: anywhere in this
 *         file), and an empty probe file changes NOTHING in a genuinely
 *         isolated repro. The real trigger is this exact, already-
 *         documented table-blind ambiguity: Phase 149 introduces a NEW,
 *         unrelated table (`inbound_calls`) with its OWN genuinely
 *         written-and-read `reviewed_at`/`reviewed_by` columns
 *         (inc/inbound-calls.php:691 `UPDATE ... SET reviewed_at = NOW(),
 *         reviewed_by = ?`; api/inbound-calls.php:123-124
 *         `$row['reviewed_at']`/`$row['reviewed_by']`). The moment that
 *         bareRead evidence for the LITERAL column names exists anywhere
 *         in the tree, this check's table-blind secondary candidate loop
 *         credits the same names to every OTHER SQL-touched table that
 *         also happens to have same-named columns — including the
 *         completely unrelated `beta_tester_applications` table, whose
 *         own `reviewed_at`/`reviewed_by` columns (created for an admin-
 *         review workflow that was never actually built — see the
 *         already-baselined `phantom:beta_tester_applications.status`
 *         entry immediately below, the SAME underlying gap) have no
 *         application-level write path of their own. Two natural
 *         tightenings (require same-FILE co-occurrence of the table's SQL
 *         and the bareRead; suppress a column name that has ANY write on
 *         a different table) were prototyped and REJECTED after measuring
 *         against this project's real ~273-finding baseline: they lost
 *         55/160 and 109/160 real secondary findings respectively,
 *         INCLUDING beta_tester_applications.status itself in both cases
 *         — trading a rare, correctly-triageable false positive for
 *         silently blinding the tool to the exact phantom-read class it
 *         exists to catch. No safe general fix exists short of the "much
 *         larger undertaking" (real call-graph/type tracing) check (d)'s
 *         own docblock already declines to attempt for its analogous
 *         ambiguity. Left AS-IS; resolved per this bullet's own
 *         prescription — investigated, confirmed genuinely no write path,
 *         documented in the baseline (see
 *         tools/dead_control_phantom_baseline.txt).
 *       - This check does NOT conclude "drop the column" on its own —
 *         a column with a real reader and no writer almost always means
 *         the WRITE side is the bug (restore/fix it), not the read side.
 *         The rarer case — the reader itself is unreachable/dead code —
 *         is a DIFFERENT finding (both sides missing, i.e. check (b)'s
 *         `--include-orphaned` territory) and needs the same care as
 *         any dead-code removal, not a mechanical fix here.
 *
 * (d) DEAD API RESPONSE KEY — a key emitted to the browser (a literal
 *     `'key' => ...` inside the balanced-parenthesis argument of a
 *     `json_response([...])` call, or an `echo json_encode([...])` /
 *     `print json_encode([...])` call, anywhere PHP sends JSON to a
 *     browser — api/, inc/, services/, proxy/, and the page-template
 *     roots; deliberately NOT tools/, whose json_response()/json_encode()
 *     call sites are all CLI-only diagnostics that never reach a browser)
 *     that no file under assets/js/ ever references by that literal
 *     name. The confirmed, real-world first instance: api/reports.php's
 *     'incident_summary' case computes `severity_breakdown` and
 *     `disposition_breakdown` as top-level response keys; neither name
 *     appeared anywhere in assets/js/reports.js until the same change
 *     that landed this check wired them in.
 *
 *     Detection:
 *       1. EMITTED = every literal `'key' => ...` pair found inside the
 *          balanced-parenthesis argument of a `json_response(...)` call,
 *          or the argument of an `echo json_encode(...)` /
 *          `print json_encode(...)` call, anywhere in the scanned PHP
 *          tree. Deliberately NARROWER than tools/api_contract_audit.php's
 *          own EMITTABLE set (which unions every array-literal key
 *          anywhere in PHP, on purpose, to catch a JS read with NO
 *          server-side source at all) — this check's job is the OPPOSITE
 *          direction (a key that genuinely reaches the browser but
 *          nothing reads it), so only counting keys inside an actual
 *          emission call keeps the EMITTED set honest about what really
 *          goes out over the wire — the same discipline check (b)/(c)
 *          apply by only counting a column as WRITTEN when a real
 *          INSERT/UPDATE targets it, not any array key anywhere that
 *          happens to share the name.
 *       2. READ = a global, name-based sweep of every `assets/js/*.js`
 *          file (recursively — includes assets/js/widgets/) for the
 *          literal key as a whole identifier token (matches
 *          `reportData.severity_breakdown`, `data['severity_breakdown']`,
 *          a JSDoc mention, or a comment equally — this check does not
 *          attempt to distinguish a real data read from a coincidental
 *          mention, the SAME tradeoff tools/api_contract_audit.php's own
 *          docblock already accepts for its own JS-side scan:
 *          "attribution is per-file, not per-variable"). This is a
 *          GLOBAL union across every JS file, not scoped to the one
 *          endpoint that emits the key, for the same reason
 *          api_contract_audit.php's own EMITTABLE set is global:
 *          precise endpoint-to-caller call-graph tracing is a much
 *          larger undertaking this tool deliberately does not attempt.
 *       3. FINDING = an EMITTED key with zero READ matches anywhere.
 *
 *     Known limitations (honest, matching every sibling check's own
 *     caveat section):
 *       - FALSE NEGATIVE risk (the safe, under-report direction): a
 *         short or common key name (`id`, `total`, `status`, `type`)
 *         will coincidentally appear SOMEWHERE across 60+ JS files even
 *         when the SPECIFIC endpoint's key is never actually consumed —
 *         this check stays silent on those, exactly like check (b)'s own
 *         "a genuinely dead column with a common name will slip through"
 *         admission. Short/generic key names are under-covered by
 *         design, not a bug to fix.
 *       - FALSE POSITIVE risk (the dangerous, cry-wolf direction,
 *         mitigated by the baseline mechanism, not eliminated): a value
 *         consumed only through a DYNAMIC property access
 *         (`data[someVariable]`, where `someVariable` is computed rather
 *         than a literal `'key'` in the JS source) leaves no literal
 *         string in assets/js/ for this check to find, and would be
 *         misreported as dead. None found in this codebase's real
 *         findings as of this writing, but a future one would need
 *         either a documented baseline entry or, if it becomes common
 *         enough to matter, the same "dynamic call site" broadening
 *         technique check (a) already applies to `get_variable($var)`.
 *       - Inline `<script>` blocks on a *.php page (rather than
 *         assets/js/*.js) ARE scanned as a READ source, the same widening
 *         tools/api_contract_audit.php's own JS-read scan already applies.
 *         Confirmed necessary during this check's own development, not
 *         theoretical: `severity_counts` (api/incidents.php) is read ONLY
 *         by an inline `<script>` block in situation.php
 *         (`data.severity_counts`) — with only assets/js/ scanned this
 *         misreported as dead; widening the scan to inline `<script>`
 *         blocks fixed it without a baseline entry.
 *       - tools/ is excluded from the EMITTED scan entirely (see above)
 *         — a future tools/ script that genuinely serves a browser
 *         (unlikely given the CLI-only guard convention, but not
 *         impossible) would need to be added to the scan directories.
 *       - An endpoint that builds its response as a BARE VARIABLE
 *         (`json_response($out)`, where `$out['key'] = ...;` was
 *         assigned incrementally across the file, never as one literal
 *         `[...]` array in the call itself) is entirely invisible to
 *         the EMITTED extraction — the tokenizer finds a single
 *         T_VARIABLE argument, not a literal array, so it yields zero
 *         keys for that call. Another false-negative (under-report)
 *         case, same safe direction as the rest of this list; not
 *         solved here (would need assignment tracking across the whole
 *         file, the same "much larger undertaking" scope this docblock
 *         already declines for JS-side call-graph tracing).
 *
 * Baseline / exit code: identical contract to the sibling audits. Exit
 * 0 = clean or every finding already in the baseline; exit 1 = a NEW
 * finding. Four baseline files, one per check, each requiring a comment
 * explaining WHY a finding is accepted rather than fixed, per this
 * project's standing convention for every baseline file:
 *   tools/dead_control_settings_baseline.txt   (a) `setting:<key>`
 *   tools/dead_control_column_baseline.txt     (b) `column:<table>.<col>`
 *   tools/dead_control_phantom_baseline.txt    (c) `phantom:<table>.<col>`
 *   tools/dead_control_api_baseline.txt        (d) `apikey:<key>`
 *
 * Usage:
 *   php tools/dead_control_audit.php                # all four checks
 *   php tools/dead_control_audit.php --settings-only # (a) only
 *   php tools/dead_control_audit.php --columns-only  # (b) only
 *   php tools/dead_control_audit.php --phantom-only  # (c) only
 *   php tools/dead_control_audit.php --api-only      # (d) only
 *   php tools/dead_control_audit.php --table=user    # (b)/(c), one table
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
 *   php tools/dead_control_audit.php --include-orphaned  # (b)-adjacent:
 *                                                     # columns with NEITHER
 *                                                     # a write NOR a read —
 *                                                     # opt-in, see (b)'s docs.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// Captured BEFORE any chdir — config.php/inc/db.php are always the real
// app's, even when --path scans a fixture tree elsewhere.
$appRoot = realpath(__DIR__ . '/..');

$argvList       = $argv ?? [];
$showAll        = in_array('--all', $argvList, true);
$settingsOnly   = in_array('--settings-only', $argvList, true);
$columnsOnly    = in_array('--columns-only', $argvList, true);
$phantomOnly    = in_array('--phantom-only', $argvList, true);
$apiOnly        = in_array('--api-only', $argvList, true);
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

// Which of the four checks run this invocation. No "only" flag given ->
// all four run (the historical default for (a)/(b), extended here).
// Any "only" flag given -> ONLY the checks whose flag was passed run, so
// --settings-only / --columns-only keep their EXACT prior meaning ("just
// this one check", not "this check plus whatever else got added later").
$anyOnlyFlag  = $settingsOnly || $columnsOnly || $phantomOnly || $apiOnly;
$runSettingsA = !$anyOnlyFlag || $settingsOnly;
$runColumnsB  = !$anyOnlyFlag || $columnsOnly;
$runPhantomC  = !$anyOnlyFlag || $phantomOnly;
$runApiD      = !$anyOnlyFlag || $apiOnly;

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
// migration/import tooling, tests, or docs). Shared by checks (a)/(b)/(c).
$appPhpDirs = ['api', 'inc', 'tools', 'services', 'proxy'];
// Check (d)'s own EMITTED-scan directories: same idea, minus tools/ — every
// tools/ call site that touches json_response()/json_encode() is a CLI-only
// diagnostic (release-divergence-check.php, test_compat.php, the upgrade
// pre/postcheck scripts) that never reaches a browser; including it would
// manufacture "dead API key" findings for output nothing ever meant to ship
// to JS in the first place.
$apiEmitDirs = ['api', 'inc', 'services', 'proxy'];

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
echo "Four checks: (a) settings-table keys a UI writes but nothing reads;\n";
echo "             (b) database columns written but never read back;\n";
echo "             (c) database columns read but never written (phantom);\n";
echo "             (d) API response keys emitted to the browser, read by no JS.\n\n";

$findings = [];   // key => [ 'kind'=>..., 'sites'=>[[file,line,msg],...] ]

// ═══════════════════════════════════════════════════════════════════════
// (a) Dead settings keys
// ═══════════════════════════════════════════════════════════════════════
if ($runSettingsA) {
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

// ═══════════════════════════════════════════════════════════════════════
// (b)/(c) Database columns — one shared parse pass, two classifications.
// ═══════════════════════════════════════════════════════════════════════
if ($runColumnsB || $runPhantomC) {
    echo "--- (b)/(c) database columns ---\n";

    if (!is_file($appRoot . '/config.php')) {
        echo "config.php not found — column checks need a reachable database, skipping\n";
    } else {
        require_once $appRoot . '/config.php';
        require_once $appRoot . '/inc/db.php';
        require_once __DIR__ . '/sql_extract.php';

        $prefix = $GLOBALS['db_prefix'] ?? '';
        $schema = [];   // table => [col => true]
        // Check (c)'s own exclusion set: columns the DATABASE ITSELF writes
        // at insert/update time, never the application's own SQL — an
        // AUTO_INCREMENT primary key (never in an app INSERT's column list
        // by construction; MySQL/MariaDB assigns it) and a
        // DEFAULT CURRENT_TIMESTAMP / ON UPDATE CURRENT_TIMESTAMP column
        // (created_at/updated_at-shaped columns the app is never expected
        // to set explicitly). Without this, EVERY table's `id` column in
        // this ~260-table schema would misreport as phantom the moment its
        // value is read anywhere (i.e. always) — confirmed during this
        // check's own development: turning this off produced 676 raw
        // findings dominated by `<table>.id`; turning it on cut that by
        // more than half. This is schema-level ground truth (queried live,
        // not inferred from SQL text), so it cannot itself be fooled by
        // any concatenation/interpolation shape the way regex-based write
        // detection can.
        $dbManagedCols = [];   // "table.col" => true
        foreach (db_fetch_all(
            "SELECT TABLE_NAME, COLUMN_NAME, EXTRA, COLUMN_DEFAULT FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()"
        ) as $r) {
            $tbl = strtolower($r['TABLE_NAME']);
            $col = strtolower($r['COLUMN_NAME']);
            $schema[$tbl][$col] = true;
            $extra = strtolower((string) ($r['EXTRA'] ?? ''));
            $default = strtolower((string) ($r['COLUMN_DEFAULT'] ?? ''));
            if (strpos($extra, 'auto_increment') !== false
                || strpos($extra, 'on update current_timestamp') !== false
                || strpos($default, 'current_timestamp') !== false) {
                $dbManagedCols["$tbl.$col"] = true;
            }
        }
        echo count($schema) . " tables loaded from live schema\n";
        echo count($dbManagedCols) . " column(s) are database-managed (AUTO_INCREMENT / CURRENT_TIMESTAMP"
            . " default) — excluded from check (c) candidacy\n";

        // Files to scan for SQL: running app code + page templates.
        $sqlFiles = $pageTemplates;
        foreach ($appPhpDirs as $d) { $sqlFiles = array_merge($sqlFiles, dca_files($d)); }
        $sqlFiles = array_values(array_filter($sqlFiles, function ($f) {
            return !dca_is_migration_tooling($f);
        }));

        $writeCols        = [];   // "table.col" => [[file,line],...]
        $readColsSql      = [];   // "table.col" => true   (alias-qualified, non-write-target)
        $sqlTouchedTables = [];   // "table" => true  (appears in FROM/JOIN/UPDATE/INSERT/DELETE anywhere)
        // Check (c)'s own broadening, mirroring check (a)'s "dynamic call
        // site" technique: "UPDATE table SET " . implode(', ', $setParts) .
        // " WHERE ..." (and the INSERT equivalent, column list built via
        // implode(',', array_keys($fields))) is a WIDESPREAD pattern in
        // this codebase (33 files as of 2026-08-20: api/constituents.php,
        // api/events.php, api/vehicles.php, inc/incident-write.php, ...) —
        // the literal SQL string carries no column names at all (they live
        // in a separate PHP array a few lines away), so $writeCols can
        // never see them and check (c) would otherwise misreport every one
        // of those tables' real, actively-written columns as phantom. See
        // the docblock's discussion of this exact false-positive shape.
        $dynamicWriteSites = [];   // file => [table => true, ...]

        /**
         * Parse one SQL string. Mirrors schema_audit.php's alias
         * resolution (kept in sync deliberately — both tools read the
         * same FROM/JOIN/UPDATE/INSERT shapes).
         */
        $parseSql = function (string $sql, string $file, int $line) use (
            &$writeCols, &$readColsSql, &$schema, &$sqlTouchedTables, &$dynamicWriteSites
        ) {
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
            $deleteTable = null;
            if (preg_match('/DELETE\s+FROM\s+`?([a-z0-9_]+)`?/i', $norm, $md0)) {
                $deleteTable = strtolower($md0[1]);
            }

            // GH#130 (rjonesbsink): CREATE TABLE new_tbl AS SELECT ... FROM
            // old_tbl is a real, deliberate write into new_tbl's columns --
            // nothing above (UPDATE/INSERT/DELETE-only) ever recognised it,
            // so a fork-only backup/rollback tool built this way showed
            // every column of the new table as "read somewhere, but has NO
            // write path anywhere" even though the write is real, static,
            // and sitting three lines away in the same file. $ctasTable is
            // resolved here (alongside update/insert/delete) so it joins
            // the SAME $sqlTouchedTables bookkeeping below; the SELECT
            // list itself is walked further down, after the bare-SELECT
            // reader it deliberately mirrors.
            $ctasTable = null;
            $ctasSelectList = null;
            if (preg_match(
                '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z0-9_]+)`?\s+AS\s+SELECT\s+(?:DISTINCT\s+)?(.*?)\s+FROM\s+`?([a-z0-9_]+)`?/is',
                $norm, $mctas
            )) {
                $ctasTable = strtolower($mctas[1]);
                $ctasSelectList = $mctas[2];
            }

            // Check (c)'s own bookkeeping: every table this statement
            // touches in ANY capacity, for scoping the bareRead-driven
            // phantom-column candidate search below to tables the app's
            // SQL actually queries.
            foreach ($aliases as $tbl) { $sqlTouchedTables[$tbl] = true; }
            if ($updateTable !== null) { $sqlTouchedTables[$updateTable] = true; }
            if ($insertTable !== null) { $sqlTouchedTables[$insertTable] = true; }
            if ($deleteTable !== null) { $sqlTouchedTables[$deleteTable] = true; }
            if ($ctasTable !== null) { $sqlTouchedTables[$ctasTable] = true; }

            // Write-target spans: the SET clause's bare `col = ` list, and
            // the INSERT column-list parens. Bare (no alias) — collected
            // separately from the alias.col regex below.
            if ($updateTable !== null
                && preg_match('/\bSET\s+(.*?)(?:\bWHERE\b|$)/is', $norm, $ms)) {
                $foundAny = false;
                if (preg_match_all('/(?:^|,)\s*`?([a-z0-9_]+)`?\s*=/i', $ms[1], $mc)) {
                    foreach ($mc[1] as $col) {
                        $col = strtolower($col);
                        if (isset($schema[$updateTable][$col])) {
                            $writeCols["$updateTable.$col"][] = [$file, $line];
                            $foundAny = true;
                        }
                    }
                }
                // Nothing resolvable after SET at all -> the column list was
                // built dynamically (concatenation flushed it out of this
                // literal). Record for the broadening pass below.
                if (!$foundAny && trim($ms[1]) === '') {
                    $dynamicWriteSites[$file][$updateTable] = true;
                }
            }
            if ($insertTable !== null) {
                if (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?[a-z0-9_]+`?\s*\(([^)]+)\)/i', $norm, $mi2)) {
                    foreach (explode(',', $mi2[1]) as $col) {
                        $col = strtolower(trim(trim($col), '` '));
                        if ($col !== '' && isset($schema[$insertTable][$col])) {
                            $writeCols["$insertTable.$col"][] = [$file, $line];
                        }
                    }
                } else {
                    // $insertTable resolved (INSERT INTO table ( matched)
                    // but the column-list-with-closing-paren regex didn't —
                    // the list was built dynamically past this point
                    // (e.g. implode(',', array_keys($fields)) — cut off the
                    // same way the concatenation chain cuts off a dynamic
                    // SET clause above).
                    $dynamicWriteSites[$file][$insertTable] = true;
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

            // CREATE TABLE new_tbl AS SELECT ... -- every column the SELECT
            // projects into new_tbl is a write of new_tbl. `SELECT *`
            // (a whole-table copy) can't be resolved to individual source
            // columns from the SQL text alone, so it credits EVERY known
            // column of new_tbl directly -- unambiguous for a full copy,
            // and the safe (false-negative-avoiding) direction for this
            // audit. An explicit projection list is walked the same way
            // the bare-SELECT reader above does: alias.col stripped first,
            // then the text after each `AS name` is dropped (the alias IS
            // the new table's real column name, not the source expression),
            // leaving the bare identifiers that survive as write targets.
            if ($ctasTable !== null) {
                if (trim($ctasSelectList) === '*') {
                    if (isset($schema[$ctasTable])) {
                        foreach (array_keys($schema[$ctasTable]) as $col) {
                            $writeCols["$ctasTable.$col"][] = [$file, $line];
                        }
                    } else {
                        $dynamicWriteSites[$file][$ctasTable] = true;
                    }
                } else {
                    $ctasList = preg_replace('/\b[a-z0-9_]+\s*\.\s*`?([a-z0-9_]+)`?/i', '$1', $ctasSelectList);
                    $foundAny = false;
                    foreach (explode(',', $ctasList) as $proj) {
                        $proj = trim($proj);
                        if ($proj === '') continue;
                        // "expr AS alias" -> the alias is the new column's
                        // real name; otherwise the whole (bare) projection
                        // text is itself the identifier.
                        $col = preg_match('/\bAS\s+`?([a-z0-9_]+)`?\s*$/i', $proj, $mas)
                            ? strtolower($mas[1])
                            : strtolower(trim($proj, '` '));
                        if ($col !== '' && preg_match('/^[a-z0-9_]+$/', $col)
                            && isset($schema[$ctasTable][$col])) {
                            $writeCols["$ctasTable.$col"][] = [$file, $line];
                            $foundAny = true;
                        }
                    }
                    if (!$foundAny) {
                        $dynamicWriteSites[$file][$ctasTable] = true;
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
                // Strip the ALIAS TARGET of every `AS name` too (both the
                // keyword and the identifier it introduces) — an alias is an
                // OUTPUT name, never a column reference, and leaving it in
                // let a real column on some OTHER table in the join coincide
                // with the alias text and get miscredited as "read". Caught
                // live during this check's own development: training's
                // teams.deputy_id is a genuine live column (schema drift —
                // not on this dev box, not on a fresh CI install) that
                // exists only because `t.leader_dpty AS deputy_id` (this
                // exact file's own SELECT, api/teams.php) left "deputy_id"
                // behind for the bare-word extractor below to find, which
                // then matched training's real (but genuinely unwritten,
                // orphaned/legacy) teams.deputy_id column purely by name
                // coincidence with the ALIAS text — the source column that
                // is actually read is `leader_dpty`, already credited by
                // the alias.column stripping immediately above. This does
                // NOT weaken the "phone_m AS cell" case this mechanism
                // exists for: only the text AFTER "AS" is removed, so the
                // real source column before it (`phone_m`) is untouched.
                $collistBare = preg_replace('/\bAS\s+`?[a-z0-9_]+`?/i', '', $collistBare);
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
        echo count($writeCols) . " column(s) with a confirmed write path (before dynamic-write broadening)\n";

        // Dynamic-write broadening (check (c)'s own -- see $dynamicWriteSites'
        // declaration above): for every file where a table's SET clause or
        // INSERT column list resolved to nothing (built from a PHP array a
        // few lines away, not a literal in the SQL string itself), credit a
        // write for every literal array-KEY in that SAME FILE that is also
        // a real column of the flagged table -- TWO shapes, both real
        // conventions this codebase uses interchangeably for the exact
        // same "build a $fields array, then INSERT/UPDATE from it" idiom:
        //   1. array-literal:      'col' => $value            (the original
        //      shape this broadening pass shipped with)
        //   2. bracket-assignment: $fields['col'] = $value;    (found missing
        //      live during this check's OWN development: the org_id fix
        //      this same change makes to api/equipment.php/api/vehicles.php
        //      uses exactly this shape -- `$fields['org_id'] = $orgId;` --
        //      and was invisible to shape 1's regex, misreporting a column
        //      this very audit run had just been taught to write correctly)
        // Scoped per-file (not global) to keep the false-credit risk low,
        // the same discipline check (a)'s dynamic-call broadening uses.
        $broadenedWrites = 0;
        foreach ($dynamicWriteSites as $dwFile => $dwTables) {
            $dwSrc = @file_get_contents($dwFile);
            if ($dwSrc === false) continue;
            $dwKeys = [];
            if (preg_match_all('/[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]\s*=>/', $dwSrc, $dwm)) {
                $dwKeys = array_merge($dwKeys, $dwm[1]);
            }
            if (preg_match_all('/\[\s*[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]\s*\]\s*=(?!=)/', $dwSrc, $dwm2)) {
                $dwKeys = array_merge($dwKeys, $dwm2[1]);
            }
            if (!$dwKeys) continue;
            foreach ($dwKeys as $dwKey) {
                $dwKey = strtolower($dwKey);
                foreach (array_keys($dwTables) as $dwTbl) {
                    $dwTc = "$dwTbl.$dwKey";
                    if (isset($schema[$dwTbl][$dwKey]) && !isset($writeCols[$dwTc])) {
                        $writeCols[$dwTc][] = [$dwFile, 0];
                        $broadenedWrites++;
                    }
                }
            }
        }
        echo count($dynamicWriteSites) . " file(s) had a dynamically-built SET/column list"
            . " -> $broadenedWrites column(s) credited by broadening\n";
        echo count($writeCols) . " column(s) with a confirmed write path (after broadening)\n";
        echo count($readColsSql) . " column(s) with a confirmed SQL read path (table-attributed)\n";

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
            // JS dot-property reads (`mk.line_opacity`) — the PHP `->` arrow
            // pattern above never matches JS source at all (JS has no `->`),
            // so without this a column read ONLY via JS dot notation (the
            // dominant JS property-access style in this codebase — far more
            // common than bracket notation) had no read-evidence path at
            // all. Confirmed live during this check's own development:
            // mmarkup.line_opacity is written (via check (c)'s dynamic-write
            // broadening, see above) and read in both assets/js/app.js and
            // assets/js/map-prefs.js as `mk.line_opacity` — dot notation
            // only — and misreported as a NEW dead-write finding for check
            // (b) until this pattern was added. Same "under-report rather
            // than cry wolf" direction as every other bareRead pattern here:
            // this can only SUPPRESS candidate findings, never manufacture
            // one, so widening it is safe.
            if (preg_match_all('/\.([a-zA-Z_][a-zA-Z0-9_]*)\b/', $src, $m)) {
                foreach ($m[1] as $k) $bareRead[strtolower($k)] = true;
            }
        }
        echo count($bareRead) . " distinct bare array-key/property name(s) read anywhere in the app\n";

        // ── (b) dead columns: written, never read ──────────────────────
        if ($runColumnsB) {
            $findingsBeforeB = count($findings);
            foreach ($writeCols as $tc => $sites) {
                [$tbl, $col] = explode('.', $tc, 2);
                if ($onlyTable !== null && $tbl !== $onlyTable) continue;
                if (isset($readColsSql[$tc])) continue;      // aliased SQL read
                if (isset($bareRead[$col])) continue;         // bare PHP/JS read anywhere
                $findings["column:$tc"] = ['kind' => 'column', 'sites' => $sites];
            }
            echo (count($findings) - $findingsBeforeB)
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

        // ── (c) phantom columns: read, never written ────────────────────
        if ($runPhantomC) {
            $phantomCount = 0;

            // Primary candidates: table-attributed SQL read evidence —
            // exactly as reliable as check (b)'s own $writeCols set, since
            // it comes from the identical parser pass.
            foreach ($readColsSql as $tc => $_) {
                [$tbl, $col] = explode('.', $tc, 2);
                if ($onlyTable !== null && $tbl !== $onlyTable) continue;
                if (isset($dbManagedCols[$tc])) continue;   // AUTO_INCREMENT / CURRENT_TIMESTAMP
                if (isset($writeCols[$tc])) continue;   // has a real write path
                $findings["phantom:$tc"] = [
                    'kind' => 'phantom',
                    'sites' => [["(SQL read evidence: $tbl.$col)", 0]],
                ];
                $phantomCount++;
            }

            // Secondary candidates: bare PHP/JS reads, scoped to tables the
            // app's SQL actually touches somewhere (never the full schema —
            // see the docblock's false-positive discussion).
            foreach (array_keys($sqlTouchedTables) as $tbl) {
                if ($onlyTable !== null && $tbl !== $onlyTable) continue;
                if (!isset($schema[$tbl])) continue;
                foreach (array_keys($schema[$tbl]) as $col) {
                    $tc = "$tbl.$col";
                    if (isset($findings["phantom:$tc"])) continue;  // already found above
                    if (isset($dbManagedCols[$tc])) continue;        // AUTO_INCREMENT / CURRENT_TIMESTAMP
                    if (isset($writeCols[$tc])) continue;            // has a real write path
                    if (isset($readColsSql[$tc])) continue;          // would have been caught above
                    if (!isset($bareRead[$col])) continue;           // no bare PHP/JS read evidence
                    $findings["phantom:$tc"] = [
                        'kind' => 'phantom',
                        'sites' => [["(bare PHP/JS read of `$col`; table `$tbl` is queried elsewhere in SQL)", 0]],
                    ];
                    $phantomCount++;
                }
            }
            echo "$phantomCount phantom-column finding(s) (columns with a read path but no write anywhere)\n\n";
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════
// (d) Dead API response keys
// ═══════════════════════════════════════════════════════════════════════
if ($runApiD) {
    echo "--- (d) API response keys ---\n";

    /**
     * Token-based (not regex-over-raw-source) extraction of literal
     * `'key' => ...` pairs inside the balanced-parenthesis argument of
     * every call to $funcNameLower(...) in $src — optionally requiring
     * an immediately-preceding `echo`/`print` keyword (for the
     * json_encode() shape, which is only "emitted to the browser" when
     * it's the direct argument of an output statement, not e.g. a value
     * assigned to a variable or written to a log file).
     *
     * Token-based, like tools/sql_extract.php, specifically so PHP
     * comments (T_COMMENT/T_DOC_COMMENT) can never desynchronize a
     * naive string-literal-tracking character scanner — an apostrophe
     * inside an ordinary English comment ("callers that don't know
     * about this key...") is exactly the kind of thing a hand-rolled
     * quote-tracking walker over raw source mistakes for the start of a
     * string literal, corrupting everything parsed after it. Caught
     * live during this check's own development: an earlier char-scanning
     * version of this function silently swallowed BOTH of
     * api/reports.php's `severity_breakdown`/`disposition_breakdown`
     * keys, because the doc-comment immediately above them contains
     * "callers that don't know" — a single stray apostrophe threw off
     * string-state tracking for the rest of the call. Tokenizing sees a
     * T_COMMENT and skips over it as a single atomic unit, so the
     * apostrophe inside it never touches string-tracking state.
     *
     * @return array<int, array{0:string,1:int}>  list of [key, line]
     */
    $extractEmittedKeys = function (string $src, string $funcNameLower, bool $requireEchoOrPrint) {
        $out = [];
        $tokens = @token_get_all($src);
        if (!$tokens) return $out;
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tk = $tokens[$i];
            if (!is_array($tk) || $tk[0] !== T_STRING || strtolower($tk[1]) !== $funcNameLower) continue;

            if ($requireEchoOrPrint) {
                $b = $i - 1;
                while ($b >= 0 && is_array($tokens[$b])
                    && in_array($tokens[$b][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $b--; }
                if ($b < 0 || !is_array($tokens[$b]) || !in_array($tokens[$b][0], [T_ECHO, T_PRINT], true)) {
                    continue;
                }
            }

            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j])
                && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $j++; }
            if ($j >= $n || $tokens[$j] !== '(') continue;

            // Walk the token stream from just after '(' tracking paren
            // depth (both '(' and '[' — short-array-literal syntax
            // nests brackets, not just parens, inside a call argument).
            $depth = 1;
            $k = $j + 1;
            $argTokens = [];
            while ($k < $n && $depth > 0) {
                $t = $tokens[$k];
                if ($t === '(' || $t === '[') { $depth++; $argTokens[] = $t; $k++; continue; }
                if ($t === ')' || $t === ']') {
                    $depth--;
                    if ($depth === 0) { $k++; break; }
                    $argTokens[] = $t; $k++; continue;
                }
                $argTokens[] = $t;
                $k++;
            }
            if ($depth !== 0) continue;   // unbalanced within the file — skip

            for ($m = 0; $m < count($argTokens); $m++) {
                $at = $argTokens[$m];
                if (!is_array($at) || $at[0] !== T_CONSTANT_ENCAPSED_STRING) continue;
                $p = $m + 1;
                while ($p < count($argTokens) && is_array($argTokens[$p])
                    && in_array($argTokens[$p][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $p++; }
                if ($p >= count($argTokens) || !is_array($argTokens[$p]) || $argTokens[$p][0] !== T_DOUBLE_ARROW) {
                    continue;
                }
                $key = trim(stripcslashes(substr($at[1], 1, -1)));
                if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                    $out[] = [strtolower($key), (int) $at[2]];
                }
            }
        }
        return $out;
    };

    // 1. EMITTED: literal 'key' => pairs inside json_response(...) and
    //    echo/print json_encode(...) call arguments.
    $emitFiles = $pageTemplates;
    foreach ($apiEmitDirs as $d) { $emitFiles = array_merge($emitFiles, dca_files($d)); }
    $emitFiles = array_values(array_filter($emitFiles, function ($f) {
        return !dca_is_migration_tooling($f);
    }));

    $emitted = [];   // key => [[file,line],...]
    foreach ($emitFiles as $file) {
        $src = @file_get_contents($file);
        if ($src === false) continue;

        $hits = array_merge(
            $extractEmittedKeys($src, 'json_response', false),
            $extractEmittedKeys($src, 'json_encode', true)
        );
        foreach ($hits as [$key, $line]) {
            $emitted[$key][] = [$file, $line];
        }
    }
    echo count($emitFiles) . " PHP files scanned for JSON emission\n";
    echo count($emitted) . " distinct key(s) emitted via json_response()/echo json_encode()\n";

    // 2. READ: every whole-word identifier token appearing anywhere under
    //    assets/js/ (recursive — includes assets/js/widgets/). A single
    //    pass building a lookup set, not one regex search per key — see
    //    the docblock for why this is a deliberately blunt "does the
    //    literal name appear anywhere" sweep, matching
    //    tools/api_contract_audit.php's own stated tradeoff.
    $jsWords = [];
    $jsFiles = dca_files('assets/js', 'js');
    $jsFileCount = 0;
    foreach ($jsFiles as $f) {
        $src = @file_get_contents($f);
        if ($src === false) continue;
        $jsFileCount++;
        if (preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $src, $m)) {
            foreach ($m[0] as $w) { $jsWords[strtolower($w)] = true; }
        }
    }
    // Inline <script> blocks in page templates also count as a READ source
    // — the same widening tools/api_contract_audit.php's own JS-read scan
    // already applies (its docblock: "assets/js/*.js plus inline <script>
    // blocks in the page roots"). Confirmed necessary, not merely
    // theoretical, during this check's own development:
    // `severity_counts` (api/incidents.php) is read ONLY by an inline
    // `<script>` block in situation.php (`data.severity_counts`) — with
    // only assets/js/ scanned, this misreported as dead.
    $inlineScriptFiles = 0;
    foreach ($pageTemplates as $f) {
        $src = @file_get_contents($f);
        if ($src === false) continue;
        if (preg_match_all('/<script(?![^>]*\bsrc=)[^>]*>(.*?)<\/script>/is', $src, $m)) {
            $inlineScriptFiles++;
            $joined = implode("\n", $m[1]);
            if (preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $joined, $m2)) {
                foreach ($m2[0] as $w) { $jsWords[strtolower($w)] = true; }
            }
        }
    }
    echo $jsFileCount . " JS files + $inlineScriptFiles page(s) with inline <script> scanned; "
        . count($jsWords) . " distinct identifier token(s) found\n";

    // 3. Findings.
    $apiFindingsBefore = count($findings);
    foreach ($emitted as $key => $sites) {
        if (isset($jsWords[$key])) continue;
        $findings["apikey:$key"] = ['kind' => 'apikey', 'sites' => $sites];
    }
    echo (count($findings) - $apiFindingsBefore) . " dead API-response-key finding(s)\n\n";
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
$phantomBaselineFile  = $appRoot . '/tools/dead_control_phantom_baseline.txt';
$apiBaselineFile      = $appRoot . '/tools/dead_control_api_baseline.txt';
$baseline = [];
foreach ([$settingsBaselineFile, $columnBaselineFile, $phantomBaselineFile, $apiBaselineFile] as $bf) {
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
    [, $name] = array_pad(explode(':', $key, 2), 2, '');
    switch ($f['kind']) {
        case 'setting':
            $label = "settings key `$name` written by a UI control, read by nothing";
            break;
        case 'column':
            $label = "column `$name` has a write path, its value is read nowhere";
            break;
        case 'phantom':
            $label = "column `$name` is read somewhere, but has NO write path anywhere";
            break;
        case 'apikey':
            $label = "API response key `$name` is emitted to the browser, read by no JS file";
            break;
        default:
            $label = $name;
    }
    echo ($inBaseline ? '[baseline] ' : '[NEW]      ') . $key . " — $label\n";
    foreach (array_slice($f['sites'], 0, 5) as [$sf, $sl]) {
        echo "             $sf:$sl\n";
    }
    if (count($f['sites']) > 5) echo '             … +' . (count($f['sites']) - 5) . " more site(s)\n";
}

echo "\n" . count($findings) . " distinct finding(s), $newCount new (not in baseline)\n";
exit($newCount === 0 ? 0 : 1);
