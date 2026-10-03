<?php
/**
 * Shared fixtures for the GH#148 (towing / roadside vendor dispatch) tests. NOT a test: the leading underscore keeps
 * tools/test_all.php (which globs test_*.php) from running it.
 *
 * Every fixture goes through the REAL writer (incident_create_internal(), vendor_provider_save(), vendor_list_save(),
 * vendor_member_add(), ...) so a test can never pass on a hand-seeded row the product would not have produced. Every
 * created id is recorded in $GLOBALS['VF'] and removed by vf_cleanup() BY REFERENCE TO THAT REGISTRY (read at cleanup
 * time, never captured by value: GH#96's leaked-ticket lesson).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/vendor-admin-write.php';
require_once __DIR__ . '/_test_admin.php';

$GLOBALS['VF'] = ['tickets' => [], 'users' => [], 'orgs' => [], 'providers' => [], 'lists' => [], 'types' => [], 'facilities' => [], 'in_types' => [], 'settings' => null];
// Every dispatch change publishes a real vendor:dispatch SSE event; remember where the table stood so vf_cleanup() can remove
// exactly the rows this test run caused (and leave every other session's events alone).
try { $GLOBALS['VF']['sse_mark'] = (int) db_fetch_value("SELECT COALESCE(MAX(`id`), 0) FROM `" . ($GLOBALS['db_prefix'] ?? '') . "sse_events`"); }
catch (Throwable $e) { $GLOBALS['VF']['sse_mark'] = null; }

function vf_prefix(): string { return $GLOBALS['db_prefix'] ?? ''; }

function vf_track(string $kind, int $id): int
{
    $GLOBALS['VF'][$kind][] = $id;
    return $id;
}

/** An actor array as the writers take it. */
function vf_actor(?int $userId = null, string $name = 'vf-actor', ?int $orgId = null): array
{
    return ['user_id' => $userId ?? test_admin_user_id(), 'name' => $name, 'ip' => '127.0.0.1', 'org_id' => $orgId];
}

/** Make THIS process act as the given user (org_visible_ids(), rbac_can() and audit_log() all read $_SESSION). */
function vf_session_as(int $userId, ?int $activeOrgId = null, string $name = 'vf-user'): void
{
    $_SESSION['user_id'] = $userId;
    $_SESSION['user'] = $name;
    $_SESSION['username'] = $name;
    if ($activeOrgId !== null) $_SESSION['active_org_id'] = $activeOrgId; else unset($_SESSION['active_org_id']);
}

function vf_org(string $name): int
{
    db_query("INSERT INTO `" . vf_prefix() . "organizations` (`name`) VALUES (?)", [$name]);
    return vf_track('orgs', (int) db_insert_id());
}

/**
 * A login-less user holding one role. $roleId 1 = Super Admin (global), 2 = Org Admin, 3 = Dispatcher, 4 = Operator,
 * 6 = Field Unit. With $orgId the grant is org-scoped: BOTH org_id (what org_visible_ids() walks) and scope_id
 * (what rbac_can() checks against $_SESSION['active_org_id']) must carry it.
 */
function vf_user(string $login, int $roleId, ?int $orgId = null): int
{
    $prefix = vf_prefix();
    db_query("INSERT INTO `{$prefix}user` (`user`, `passwd`, `can_login`) VALUES (?, 'x', 0)", [$login]);
    $uid = vf_track('users', (int) db_insert_id());
    if ($orgId === null) {
        db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`, `scope_kind`) VALUES (?, ?, 'global')", [$uid, $roleId]);
    } else {
        db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`, `org_id`, `scope_kind`, `scope_id`) VALUES (?, ?, ?, 'org', ?)",
            [$uid, $roleId, $orgId, $orgId]);
    }
    return $uid;
}

/** An open incident created through the real writer; optionally pinned to an organization and/or created closed. */
function vf_ticket(?int $orgId = null, bool $closed = false, string $street = '100 Test Road', ?int $typeId = null): int
{
    $prefix = vf_prefix();
    if ($typeId === null) $typeId = (int) db_fetch_value("SELECT `id` FROM `{$prefix}in_types` ORDER BY `id` LIMIT 1");
    $res = incident_create_internal([
        'in_types_id' => $typeId, 'scope' => 'GH148 vendor test incident', 'street' => $street,
        'city' => 'Testville', 'state' => 'MN', 'address_about' => 'at Elm St',
    ], test_admin_user_id());
    $tid = (int) ($res['id'] ?? 0);
    if ($tid <= 0) throw new RuntimeException('could not create the fixture incident: ' . json_encode($res));
    vf_track('tickets', $tid);
    if ($orgId !== null) db_query("UPDATE `{$prefix}ticket` SET `org_id` = ? WHERE `id` = ?", [$orgId, $tid]);
    if ($closed) incident_update_status_internal($tid, 1, test_admin_user_id());
    return $tid;
}

/** An incident type (configuration data, so a plain INSERT is how an administrator's own save would land). */
function vf_incident_type(string $type, string $group = 'LAW'): int
{
    $prefix = vf_prefix();
    db_query("INSERT INTO `{$prefix}in_types` (`type`, `description`, `group`) VALUES (?, ?, ?)", [$type, 'GH148 fixture type', $group]);
    return vf_track('in_types', (int) db_insert_id());
}

/** A facility created through the real writer (a tow destination / a provider's yard). */
function vf_facility(string $name = 'VT Impound Lot'): int
{
    require_once __DIR__ . '/../inc/facility-write.php';
    $res = facility_upsert_internal(['name' => $name, 'description' => 'GH148 fixture', 'street' => '9 Yard Rd', 'city' => 'Testville', 'state' => 'MN'], test_admin_user_id());
    if (empty($res['id'])) throw new RuntimeException('vf_facility failed: ' . json_encode($res));
    return vf_track('facilities', (int) $res['id']);
}

function vf_service_type_id(string $code = 'tow'): int
{
    return (int) db_fetch_value("SELECT `id` FROM `" . vf_prefix() . "vendor_service_types` WHERE `code` = ?", [$code]);
}

/** A provider saved through the real admin writer. */
function vf_provider(string $name, array $over = [], ?array $actor = null): int
{
    $res = vendor_provider_save(array_merge(['name' => $name, 'phone' => '555-0100', 'org_id' => ''], $over), $actor ?? vf_actor());
    if (!($res['ok'] ?? false)) throw new RuntimeException('vf_provider failed: ' . json_encode($res));
    return vf_track('providers', (int) $res['provider']['id']);
}

/** A rotation list saved through the real admin writer. */
function vf_list(string $name, string $serviceCode = 'tow', array $over = [], ?array $actor = null): int
{
    $res = vendor_list_save(array_merge(['name' => $name, 'service_type_id' => vf_service_type_id($serviceCode), 'org_id' => ''], $over), $actor ?? vf_actor());
    if (!($res['ok'] ?? false)) throw new RuntimeException('vf_list failed: ' . json_encode($res));
    return vf_track('lists', (int) $res['list']['id']);
}

/** Put providers on a list, in the order given, through the real writer. */
function vf_members(int $listId, array $providerIds, ?array $actor = null): void
{
    foreach ($providerIds as $pid) {
        $r = vendor_member_add($listId, $pid, $actor ?? vf_actor());
        if (!($r['ok'] ?? false)) throw new RuntimeException('vf_members failed: ' . json_encode($r));
    }
}

/** Remember the vendor settings once so vf_cleanup() can put them back exactly. */
function vf_remember_settings(): void
{
    if ($GLOBALS['VF']['settings'] !== null) return;
    $snap = [];
    foreach (array_keys(vendor_setting_defs()) as $name) {
        $v = db_fetch_value("SELECT `value` FROM `" . vf_prefix() . "settings` WHERE `name` = ?", [$name]);
        $snap[$name] = ($v === false) ? null : (string) $v;
    }
    $GLOBALS['VF']['settings'] = $snap;
}

/** Write settings straight to the table (a worker subprocess then reads them fresh; this process's cache is stale by design). */
function vf_set_settings(array $kv): void
{
    vf_remember_settings();
    foreach ($kv as $name => $value) {
        db_query("INSERT INTO `" . vf_prefix() . "settings` (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$name, $value]);
    }
}

/** The ledger rows of a dispatch, in order. */
function vf_ledger(int $dispatchId): array
{
    return vendor_dispatch_ledger_rows($dispatchId);
}

function vf_ledger_types(int $dispatchId): array
{
    return array_map(function ($r) { return $r['event_type']; }, vf_ledger($dispatchId));
}

/** Delete everything the test created (reads the registry NOW, so ids added late are still removed). */
function vf_cleanup(): void
{
    $prefix = vf_prefix();
    $VF = $GLOBALS['VF'];
    $in = function (array $ids) { return [implode(',', array_fill(0, count($ids), '?')), array_values($ids)]; };
    try {
        if (!empty($VF['tickets'])) {
            [$ph, $p] = $in($VF['tickets']);
            $dIds = array_column(db_fetch_all("SELECT `id` FROM `{$prefix}vendor_dispatches` WHERE `ticket_id` IN ($ph)", $p), 'id');
            if ($dIds) { [$dph, $dp] = $in($dIds); db_query("DELETE FROM `{$prefix}vendor_dispatch_ledger` WHERE `dispatch_id` IN ($dph)", $dp); }
            db_query("DELETE FROM `{$prefix}vendor_dispatches` WHERE `ticket_id` IN ($ph)", $p);
            foreach (['incident_shares' => 'ticket_id', 'action' => 'ticket_id', 'log' => 'ticket_id', 'assigns' => 'ticket_id'] as $tbl => $col) {
                try { db_query("DELETE FROM `{$prefix}{$tbl}` WHERE `{$col}` IN ($ph)", $p); } catch (Throwable $e) {}
            }
            db_query("DELETE FROM `{$prefix}ticket` WHERE `id` IN ($ph)", $p);
        }
    } catch (Throwable $e) { fwrite(STDERR, "vf_cleanup tickets: " . $e->getMessage() . "\n"); }
    try {
        if (!empty($VF['providers'])) {
            [$ph, $p] = $in($VF['providers']);
            db_query("DELETE FROM `{$prefix}vendor_dispatch_ledger` WHERE `provider_id` IN ($ph) OR `expected_head_provider_id` IN ($ph)", array_merge($p, $p));
            db_query("DELETE FROM `{$prefix}vendor_rotation_members` WHERE `provider_id` IN ($ph)", $p);
            db_query("DELETE FROM `{$prefix}vendor_providers` WHERE `id` IN ($ph)", $p);
        }
        if (!empty($VF['lists'])) {
            [$ph, $p] = $in($VF['lists']);
            db_query("DELETE FROM `{$prefix}vendor_dispatch_ledger` WHERE `list_id` IN ($ph)", $p);
            db_query("DELETE FROM `{$prefix}vendor_rotation_members` WHERE `list_id` IN ($ph)", $p);
            db_query("DELETE FROM `{$prefix}vendor_rotation_lists` WHERE `id` IN ($ph)", $p);
        }
        if (!empty($VF['types'])) {
            [$ph, $p] = $in($VF['types']);
            db_query("DELETE FROM `{$prefix}vendor_service_types` WHERE `id` IN ($ph)", $p);
        }
    } catch (Throwable $e) { fwrite(STDERR, "vf_cleanup vendor rows: " . $e->getMessage() . "\n"); }
    try {
        if (!empty($VF['in_types'])) {
            [$ph, $p] = $in($VF['in_types']);
            db_query("DELETE FROM `{$prefix}in_types` WHERE `id` IN ($ph)", $p);
        }
    } catch (Throwable $e) { fwrite(STDERR, "vf_cleanup in_types: " . $e->getMessage() . "\n"); }
    try {
        if (!empty($VF['facilities'])) {
            [$ph, $p] = $in($VF['facilities']);
            db_query("DELETE FROM `{$prefix}facilities` WHERE `id` IN ($ph)", $p);
        }
    } catch (Throwable $e) { fwrite(STDERR, "vf_cleanup facilities: " . $e->getMessage() . "\n"); }
    try {
        if (!empty($VF['users'])) {
            [$ph, $p] = $in($VF['users']);
            db_query("DELETE FROM `{$prefix}user_roles` WHERE `user_id` IN ($ph)", $p);
            db_query("DELETE FROM `{$prefix}user` WHERE `id` IN ($ph)", $p);
        }
        if (!empty($VF['orgs'])) {
            [$ph, $p] = $in($VF['orgs']);
            db_query("DELETE FROM `{$prefix}organizations` WHERE `id` IN ($ph)", $p);
        }
    } catch (Throwable $e) { fwrite(STDERR, "vf_cleanup users/orgs: " . $e->getMessage() . "\n"); }
    try {
        if (($VF['sse_mark'] ?? null) !== null) {
            db_query("DELETE FROM `{$prefix}sse_events` WHERE `id` > ? AND `event_type` = 'vendor:dispatch'", [(int) $VF['sse_mark']]);
        }
    } catch (Throwable $e) { fwrite(STDERR, "vf_cleanup sse: " . $e->getMessage() . "
"); }
    if (is_array($VF['settings'])) {
        foreach ($VF['settings'] as $name => $v) {
            try {
                if ($v === null) db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
                else db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$name, $v]);
            } catch (Throwable $e) {}
        }
    }
}

/**
 * Run vendor-library calls in a FRESH PHP process with the given settings in force. get_variable() caches the whole
 * settings table for the life of a process (Phase 151's lesson), so a test that needs two values of a setting needs two
 * processes. proc_open with an argv array and SEPARATE stdout/stderr pipes (CI-ENVIRONMENT.md: a merged stream corrupts the JSON).
 * @return array|null the worker's decoded JSON, or null
 */
function vf_worker(array $settings, string $action, array $args): ?array
{
    $argv = [PHP_BINARY ?: 'php', __DIR__ . '/_vendor_worker.php', json_encode($settings), $action, json_encode($args)];
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($argv, $spec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return null;
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    $decoded = json_decode(trim((string) $out), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "vf_worker: undecodable output for {$action}: " . substr((string) $out, 0, 500) . " | stderr: " . substr((string) $err, 0, 500) . "\n");
        return null;
    }
    return $decoded;
}
