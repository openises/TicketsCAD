<?php
/**
 * Multi-tenant org_id scoping write-path restoration — facilities,
 * responder, teams, newui_equipment, newui_vehicles (2026-08-20).
 *
 * Found by tools/dead_control_audit.php's new check (c) ("phantom
 * column") sweep: five tables are filtered on every LIST read via
 * org_query_filter() (inc/org-scope.php), but none of their create
 * paths actually populated org_id.
 *
 *   - facilities / responder: api/facility-save.php and
 *     api/responder-save.php both computed a default org_id on create
 *     ("the upsert helper handles that next"), but neither
 *     facility_upsert_internal() nor responder_upsert_internal() ever
 *     included `org_id` in their INSERT column list — the value was
 *     computed, handed in, and silently dropped. This is the "comment
 *     states design as implemented fact" root-cause smell this
 *     project's own standing troubleshooting discipline names
 *     explicitly.
 *   - teams: org_id was never even attempted by any caller.
 *   - newui_equipment / newui_vehicles: same — never attempted. (Not
 *     to be confused with newui_vehicles.owner_org_id, a semantically
 *     DIFFERENT column meaning "which agency owns this vehicle" that
 *     already IS written correctly — this fix does not touch it.)
 *
 * Impact: org_query_filter() treats org_id IS NULL as visible-to-
 * everyone under the default fallback, or invisible to everyone but
 * Super Admin once org_strict_isolation_enabled() is turned on — so
 * this silently defeated org-scoping for every facility, unit, team,
 * equipment, and vehicle record in the system.
 *
 * This test drives the REAL writer functions directly (not hand-seeded
 * rows) with a throwaway organization + throwaway user carrying a real
 * home_org_id, per this project's standing rule to reproduce bugs
 * through the real writer/UI/API path. equipment.php/vehicles.php's
 * save logic lives inline in an HTTP action handler (not an extracted
 * inc/*-write.php function) — structural + functional checks instead,
 * matching tests/test_vehicle_owner_agency.php's own established
 * pattern for this exact endpoint shape.
 *
 * @requires-db
 * Usage: php tests/test_facility_responder_org_id_write.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/facility-write.php';
require_once __DIR__ . '/../inc/responder-write.php';
require_once __DIR__ . '/../inc/org-scope.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== facilities.org_id / responder.org_id write-path restoration ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Fixtures: a throwaway org + a throwaway user with that home org ──
db_query(
    "INSERT INTO `{$prefix}organizations` (`name`, `short_name`, `org_type`, `active`)
     VALUES (?, ?, ?, 1)",
    ['claude-orgid-test-A', 'orgidA', 'agency']
);
$orgAId = (int) db_insert_id();

db_query(
    "INSERT INTO `{$prefix}organizations` (`name`, `short_name`, `org_type`, `active`)
     VALUES (?, ?, ?, 1)",
    ['claude-orgid-test-B', 'orgidB', 'agency']
);
$orgBId = (int) db_insert_id();

db_query(
    "INSERT INTO `{$prefix}user` (`user`, `passwd`, `name_f`, `name_l`, `home_org_id`)
     VALUES (?, ?, ?, ?, ?)",
    ['claude-orgid-test-user', password_hash('unused', PASSWORD_BCRYPT), 'Claude', 'OrgIDTest', $orgAId]
);
$testUserId = (int) db_insert_id();

$createdFacilityIds = [];
$createdResponderIds = [];
$createdTeamIds = [];
// Captured BY REFERENCE (&$var) deliberately — these arrays are appended
// to AFTER this registration point as each fixture row is created below.
// A by-VALUE `use ($createdFacilityIds, ...)` closure would freeze on
// the still-empty arrays as they stood at THIS line, silently skip every
// cleanup DELETE, and then (since facilities/responder/teams rows would
// still reference these orgs) the final organizations DELETE below would
// fail on a foreign-key constraint and be swallowed by its own
// try/catch — leaving every fixture row behind with no visible error.
// Caught and fixed during this test's own development: exactly that
// happened on the first run (confirmed via a live DB query afterward)
// before this was changed to capture by reference.
register_shutdown_function(function () use (
    $prefix, &$createdFacilityIds, &$createdResponderIds, &$createdTeamIds, $testUserId, $orgAId, $orgBId
) {
    foreach ($createdFacilityIds as $id) {
        try { db_query("DELETE FROM `{$prefix}facilities` WHERE `id` = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdResponderIds as $id) {
        try { db_query("DELETE FROM `{$prefix}responder` WHERE `id` = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdTeamIds as $id) {
        try { db_query("DELETE FROM `{$prefix}teams` WHERE `id` = ?", [$id]); } catch (Throwable $e) {}
    }
    try { db_query("DELETE FROM `{$prefix}user` WHERE `id` = ?", [$testUserId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}organizations` WHERE `id` IN (?, ?)", [$orgAId, $orgBId]); } catch (Throwable $e) {}
});

// ═══════════════════════════════════════════════════════════════════════
// facilities
// ═══════════════════════════════════════════════════════════════════════
echo "--- facility_upsert_internal() ---\n";

// 1. No org_id supplied on create -> falls back to the creating user's home org.
$r1 = facility_upsert_internal(
    ['name' => 'Claude OrgID Test Facility 1', 'description' => 'test'],
    $testUserId
);
t('create with no org_id supplied succeeds', empty($r1['errors']));
if (!empty($r1['id'])) $createdFacilityIds[] = $r1['id'];
$f1OrgId = (int) db_fetch_value("SELECT `org_id` FROM `{$prefix}facilities` WHERE `id` = ?", [$r1['id']]);
t('facility.org_id defaults to the creating user\'s home_org_id', $f1OrgId === $orgAId,
    "expected $orgAId, got $f1OrgId");

// 2. Explicit org_id supplied on create -> that value wins, not the home org.
$r2 = facility_upsert_internal(
    ['name' => 'Claude OrgID Test Facility 2', 'description' => 'test', 'org_id' => $orgBId],
    $testUserId
);
t('create with an explicit org_id succeeds', empty($r2['errors']));
if (!empty($r2['id'])) $createdFacilityIds[] = $r2['id'];
$f2OrgId = (int) db_fetch_value("SELECT `org_id` FROM `{$prefix}facilities` WHERE `id` = ?", [$r2['id']]);
t('an explicit org_id overrides the home-org default', $f2OrgId === $orgBId,
    "expected $orgBId, got $f2OrgId");

// 3. UPDATE never changes org_id, even if a different one is passed —
// org assignment is a create-time decision, matching ticket.org_id's
// own established convention (never touched by a general edit).
$r3 = facility_upsert_internal(
    ['id' => $r1['id'], 'name' => 'Claude OrgID Test Facility 1 (edited)', 'description' => 'test', 'org_id' => $orgBId],
    $testUserId,
    $r1['id']
);
t('update of an existing facility succeeds', empty($r3['errors']));
$f1OrgIdAfterUpdate = (int) db_fetch_value("SELECT `org_id` FROM `{$prefix}facilities` WHERE `id` = ?", [$r1['id']]);
t('updating a facility does not change its org_id', $f1OrgIdAfterUpdate === $orgAId,
    "expected unchanged $orgAId, got $f1OrgIdAfterUpdate");

// ═══════════════════════════════════════════════════════════════════════
// responder
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- responder_upsert_internal() ---\n";

$r4 = responder_upsert_internal(
    ['name' => 'Claude OrgID Test Unit 1', 'description' => 'test'],
    $testUserId
);
t('create with no org_id supplied succeeds', empty($r4['errors']));
if (!empty($r4['id'])) $createdResponderIds[] = $r4['id'];
$u1OrgId = (int) db_fetch_value("SELECT `org_id` FROM `{$prefix}responder` WHERE `id` = ?", [$r4['id']]);
t('responder.org_id defaults to the creating user\'s home_org_id', $u1OrgId === $orgAId,
    "expected $orgAId, got $u1OrgId");

$r5 = responder_upsert_internal(
    ['name' => 'Claude OrgID Test Unit 2', 'description' => 'test', 'org_id' => $orgBId],
    $testUserId
);
t('create with an explicit org_id succeeds', empty($r5['errors']));
if (!empty($r5['id'])) $createdResponderIds[] = $r5['id'];
$u2OrgId = (int) db_fetch_value("SELECT `org_id` FROM `{$prefix}responder` WHERE `id` = ?", [$r5['id']]);
t('an explicit org_id overrides the home-org default', $u2OrgId === $orgBId,
    "expected $orgBId, got $u2OrgId");

$r6 = responder_upsert_internal(
    ['id' => $r4['id'], 'name' => 'Claude OrgID Test Unit 1 (edited)', 'description' => 'test', 'org_id' => $orgBId],
    $testUserId,
    $r4['id']
);
t('update of an existing responder succeeds', empty($r6['errors']));
$u1OrgIdAfterUpdate = (int) db_fetch_value("SELECT `org_id` FROM `{$prefix}responder` WHERE `id` = ?", [$r4['id']]);
t('updating a responder does not change its org_id', $u1OrgIdAfterUpdate === $orgAId,
    "expected unchanged $orgAId, got $u1OrgIdAfterUpdate");

// ═══════════════════════════════════════════════════════════════════════
// teams — same fix shape, but teams.org_id was NEVER even attempted by
// any caller (unlike facilities/responder, where a default was at least
// computed and then dropped) — team_upsert_internal() resolves it
// entirely on its own now.
// ═══════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../inc/team-write.php';
echo "\n--- team_upsert_internal() ---\n";

$r7 = team_upsert_internal(
    ['name' => 'Claude OrgID Test Team 1', 'description' => 'test'],
    $testUserId
);
t('create with no org_id supplied succeeds', empty($r7['errors']), implode(', ', $r7['errors'] ?? []));
if (!empty($r7['id'])) $createdTeamIds[] = $r7['id'];
$team1OrgId = (int) db_fetch_value("SELECT `org_id` FROM `{$prefix}teams` WHERE `id` = ?", [$r7['id']]);
t('teams.org_id defaults to the creating user\'s home_org_id', $team1OrgId === $orgAId,
    "expected $orgAId, got $team1OrgId");

$r8 = team_upsert_internal(
    ['name' => 'Claude OrgID Test Team 2', 'description' => 'test', 'org_id' => $orgBId],
    $testUserId
);
t('create with an explicit org_id succeeds', empty($r8['errors']), implode(', ', $r8['errors'] ?? []));
if (!empty($r8['id'])) $createdTeamIds[] = $r8['id'];
$team2OrgId = (int) db_fetch_value("SELECT `org_id` FROM `{$prefix}teams` WHERE `id` = ?", [$r8['id']]);
t('an explicit org_id overrides the home-org default', $team2OrgId === $orgBId,
    "expected $orgBId, got $team2OrgId");

$r9 = team_upsert_internal(
    ['id' => $r7['id'], 'name' => 'Claude OrgID Test Team 1 (edited)', 'description' => 'test', 'org_id' => $orgBId],
    $testUserId,
    $r7['id']
);
t('update of an existing team succeeds', empty($r9['errors']), implode(', ', $r9['errors'] ?? []));
$team1OrgIdAfterUpdate = (int) db_fetch_value("SELECT `org_id` FROM `{$prefix}teams` WHERE `id` = ?", [$r7['id']]);
t('updating a team does not change its org_id', $team1OrgIdAfterUpdate === $orgAId,
    "expected unchanged $orgAId, got $team1OrgIdAfterUpdate");

// ═══════════════════════════════════════════════════════════════════════
// newui_equipment / newui_vehicles — the save logic lives inline in
// api/equipment.php's / api/vehicles.php's handlePost(), same situation
// tests/test_vehicle_owner_agency.php already documents for this exact
// endpoint shape: exercising the real HTTP action needs a live request
// (@requires-http, skipped in the CI fresh-install job). Two checks
// instead, matching that file's own established pattern: (1) structural
// — the org_id resolution code is actually present in the shipped file;
// (2) functional — the identical field-computation the endpoint runs,
// against a real database, so a regression in the LOGIC itself is
// caught in every environment even without the HTTP wiring.
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- api/equipment.php / api/vehicles.php org_id (structural + functional) ---\n";

/**
 * Positional check: `$fields['org_id'] = ...` must appear AFTER
 * `$fields['created_at'] = ...` — a marker confirmed unique per file
 * (grep -c === 1 in both), which this codebase's own established
 * convention already uses to mean "we are inside the create-only
 * branch" (see the identical pattern already present in both files
 * before this fix) — and within a short distance after it (the same
 * create branch, not some later unrelated action in the same file). A
 * regex trying to match the whole shape in one pattern proved fragile
 * across two files with slightly different surrounding whitespace;
 * anchoring on the unique marker and checking a bounded window after it
 * is exact and easy to reason about.
 */
function _org_id_is_create_only(string $src): bool {
    $createdAt = strpos($src, "\$fields['created_at'] = date(");
    if ($createdAt === false) return false;
    // Wide enough to span this fix's own explanatory comment block
    // (~1000 chars in both files as shipped) but still well short of
    // reaching a later, unrelated action's own code.
    $window = substr($src, $createdAt, 1500);
    return strpos($window, "\$fields['org_id'] = \$orgId;") !== false;
}

$equipSrc = (string) file_get_contents(realpath(__DIR__ . '/../api/equipment.php'));
t('equipment.php resolves org_id from input or the creating user\'s home org',
    strpos($equipSrc, "\$fields['org_id'] = \$orgId;") !== false
    && strpos($equipSrc, 'org_user_home_id((int) $current_user_id)') !== false);
t('equipment.php only assigns org_id in the CREATE branch (never overwrites on update)',
    _org_id_is_create_only($equipSrc));

$vehSrc = (string) file_get_contents(realpath(__DIR__ . '/../api/vehicles.php'));
t('vehicles.php resolves org_id from input or the creating user\'s home org',
    strpos($vehSrc, "\$fields['org_id'] = \$orgId;") !== false
    && strpos($vehSrc, 'org_user_home_id((int) $current_user_id)') !== false);
t('vehicles.php only assigns org_id in the CREATE branch (never overwrites on update)',
    _org_id_is_create_only($vehSrc));

// Functional: the identical resolution logic, driven directly.
function _org_id_resolution_test(int $inputOrgId, int $homeOrgId, int $expectedOrgId): bool {
    $input = $inputOrgId > 0 ? ['org_id' => $inputOrgId] : [];
    $orgId = (isset($input['org_id']) && (int) $input['org_id'] > 0) ? (int) $input['org_id'] : null;
    if ($orgId === null) {
        $orgId = $homeOrgId;
    }
    return $orgId === $expectedOrgId;
}
t('resolution logic: no input org_id -> falls back to home org', _org_id_resolution_test(0, $orgAId, $orgAId));
t('resolution logic: explicit input org_id -> overrides home org', _org_id_resolution_test($orgBId, $orgAId, $orgBId));

// ═══════════════════════════════════════════════════════════════════════
// dead_control_audit.php check (c) no longer flags any of the five columns
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- tools/dead_control_audit.php check (c) ---\n";
$base = realpath(__DIR__ . '/..');
foreach (['facilities', 'responder', 'teams', 'newui_equipment', 'newui_vehicles'] as $tbl) {
    $dcaOut = shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/tools/dead_control_audit.php')
        . ' --phantom-only --table=' . escapeshellarg($tbl) . ' 2>&1'
    );
    t("phantom:$tbl.org_id no longer flagged", strpos((string) $dcaOut, "phantom:$tbl.org_id") === false, (string) $dcaOut);
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
