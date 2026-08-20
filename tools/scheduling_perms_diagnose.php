<?php
/**
 * Scheduling-permissions multi-team union diagnostic (GH#76 Phase 144).
 *
 * Read-only. Compares the OLD (pre-Phase-144, single member.team_id
 * column) resolution of scheduling_get_permissions() against the NEW
 * (team_members-union, most-permissive-wins across every team a member
 * belongs to) resolution, for every member at every scope that actually
 * has a team-targeted permission assignment configured.
 *
 * This is the one piece of GH#76 ("unify team assignment on team_members")
 * that changes live AUTHORIZATION behavior, not just display — see
 * inc/scheduling-perms.php and the design spec's Section 7. Per that spec:
 * run this against your-server.example.com and your-server BEFORE
 * deploying the Phase 144 release; if it reports any changed member, that
 * list goes to Eric for explicit review before the deploy is considered
 * complete — mirroring the sign-off Eric asked for on the roster dropdown
 * question itself.
 *
 *   php tools/scheduling_perms_diagnose.php                  # every configured scope
 *   php tools/scheduling_perms_diagnose.php --scope=global   # just the global scope
 *   php tools/scheduling_perms_diagnose.php --scope=template:5
 *   php tools/scheduling_perms_diagnose.php --scope=event:12
 *
 * Run from the install root (or anywhere — it chdir's to its parent's parent).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(dirname(__DIR__));
require_once 'config.php';
require_once 'inc/scheduling-perms.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

$args = [];
foreach ($argv as $a) { if (preg_match('/^--([a-z_]+)=(.*)$/', $a, $m)) $args[$m[1]] = $m[2]; }

/**
 * Reproduces the PRE-Phase-144 algorithm exactly: a single member.team_id
 * lookup (no union across multiple teams). This is what
 * scheduling_get_permissions() did before this release — kept here only
 * so this diagnostic can compare against it; it is NOT restored anywhere
 * in the shipped code (inc/scheduling-perms.php no longer reads
 * member.team_id at all as of this release).
 */
function _p144_old_scheduling_get_permissions(string $prefix, int $memberId, string $scopeType, ?int $scopeId): array
{
    $member = null;
    try {
        $member = db_fetch_one(
            "SELECT `id`, `team_id`, `member_type_id` FROM `{$prefix}member` WHERE `id` = ?",
            [$memberId]
        );
    } catch (Exception $e) {}
    $teamId = $member ? (int) ($member['team_id'] ?? 0) : 0;
    $typeId = $member ? (int) ($member['member_type_id'] ?? 0) : 0;

    $candidates = [];
    if ($scopeType !== 'global' && $scopeId) {
        $candidates[] = ['scope_type' => $scopeType, 'scope_id' => $scopeId, 'target_type' => 'member', 'target_id' => $memberId];
    }
    if ($scopeType !== 'global' && $scopeId && $teamId) {
        $candidates[] = ['scope_type' => $scopeType, 'scope_id' => $scopeId, 'target_type' => 'team', 'target_id' => $teamId];
    }
    if ($scopeType !== 'global' && $scopeId && $typeId) {
        $candidates[] = ['scope_type' => $scopeType, 'scope_id' => $scopeId, 'target_type' => 'member_type', 'target_id' => $typeId];
    }
    if ($scopeType !== 'global' && $scopeId) {
        $candidates[] = ['scope_type' => $scopeType, 'scope_id' => $scopeId, 'target_type' => 'all', 'target_id' => null];
    }
    $candidates[] = ['scope_type' => 'global', 'scope_id' => null, 'target_type' => 'member', 'target_id' => $memberId];
    if ($teamId) {
        $candidates[] = ['scope_type' => 'global', 'scope_id' => null, 'target_type' => 'team', 'target_id' => $teamId];
    }
    if ($typeId) {
        $candidates[] = ['scope_type' => 'global', 'scope_id' => null, 'target_type' => 'member_type', 'target_id' => $typeId];
    }
    $candidates[] = ['scope_type' => 'global', 'scope_id' => null, 'target_type' => 'all', 'target_id' => null];

    foreach ($candidates as $c) {
        $profile = _sched_perm_find($prefix, $c);
        if ($profile) return $profile;
    }
    return _sched_perm_default();
}

function line($s = '') { echo $s . "\n"; }

// ── Determine which scopes to check ──────────────────────────────────────
$scopes = [];
if (isset($args['scope'])) {
    if ($args['scope'] === 'global') {
        $scopes[] = ['type' => 'global', 'id' => null];
    } elseif (preg_match('/^(template|event|role):(\d+)$/', $args['scope'], $m)) {
        $scopes[] = ['type' => $m[1], 'id' => (int) $m[2]];
    } else {
        line("Unrecognized --scope value: {$args['scope']} (expected 'global', 'template:N', 'event:N', or 'role:N')");
        exit(1);
    }
} else {
    // Every scope that actually has at least one team-targeted assignment
    // configured, plus the global scope always (it's the universal fallback).
    $scopes[] = ['type' => 'global', 'id' => null];
    try {
        $rows = db_fetch_all(
            "SELECT DISTINCT `scope_type`, `scope_id`
             FROM `{$prefix}scheduling_permission_assignments`
             WHERE `target_type` = 'team' AND `scope_type` != 'global'"
        );
        foreach ($rows as $r) {
            $scopes[] = ['type' => $r['scope_type'], 'id' => (int) $r['scope_id']];
        }
    } catch (Exception $e) {
        line("[WARN] could not enumerate configured scopes: " . $e->getMessage());
    }
}

line("=== Scheduling permissions multi-team union diagnostic (GH#76 Phase 144) ===");
line("Checking " . count($scopes) . " scope(s): " . implode(', ', array_map(
    fn($s) => $s['type'] . ($s['id'] !== null ? ":{$s['id']}" : ''), $scopes
)));
line();

$members = [];
try {
    $members = db_fetch_all(
        "SELECT `id`, `first_name`, `last_name`
         FROM `{$prefix}member`
         WHERE (`deleted_at` IS NULL OR `deleted_at` = '0000-00-00 00:00:00')
         ORDER BY `id`"
    );
} catch (Exception $e) {
    line("[FATAL] could not read member table: " . $e->getMessage());
    exit(1);
}

$flagKeys = [
    'can_view_schedule', 'can_view_own', 'can_view_others', 'can_view_available',
    'can_self_assign', 'can_self_remove', 'can_mark_unavailable', 'can_swap',
    'can_request_cover', 'can_assign_others', 'can_remove_others',
    'can_change_status', 'can_manage_slots',
];

$totalChanged = 0;
foreach ($scopes as $scope) {
    $scopeLabel = $scope['type'] . ($scope['id'] !== null ? ":{$scope['id']}" : '');
    $scopeChanged = 0;
    $rowsOut = [];

    foreach ($members as $m) {
        $mid = (int) $m['id'];
        $old = _p144_old_scheduling_get_permissions($prefix, $mid, $scope['type'], $scope['id']);
        $new = scheduling_get_permissions($mid, $scope['type'], $scope['id']);

        $diffs = [];
        foreach ($flagKeys as $k) {
            if ((int) ($old[$k] ?? 0) !== (int) ($new[$k] ?? 0)) {
                $diffs[] = "{$k}: " . (int) ($old[$k] ?? 0) . ' -> ' . (int) ($new[$k] ?? 0);
            }
        }
        if (!empty($diffs) || ($old['profile_code'] ?? '') !== ($new['profile_code'] ?? '')) {
            $scopeChanged++;
            $name = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
            $rowsOut[] = [
                'id' => $mid, 'name' => $name,
                'old_profile' => $old['profile_code'] ?? '?', 'new_profile' => $new['profile_code'] ?? '?',
                'diffs' => $diffs,
            ];
        }
    }

    line("── Scope: {$scopeLabel} ──");
    if ($scopeChanged === 0) {
        line("  No members would see a different effective scheduling permission here.");
    } else {
        foreach ($rowsOut as $ro) {
            line("  [CHANGE] #{$ro['id']} {$ro['name']}");
            line("    old profile: {$ro['old_profile']}   new profile: {$ro['new_profile']}");
            foreach ($ro['diffs'] as $d) line("    {$d}");
        }
    }
    line();
    $totalChanged += $scopeChanged;
}

line("=== Summary ===");
if ($totalChanged === 0) {
    line("0 members changed across all checked scopes. Safe to deploy without further review.");
    exit(0);
}
line("{$totalChanged} member-scope combination(s) would see a DIFFERENT effective scheduling");
line("permission after this release. This is the one behavior-changing piece of GH#76");
line("Phase 144 (see inc/scheduling-perms.php) — review this list with Eric before");
line("considering the deploy complete, per the design spec's Section 7.");
exit(2);
