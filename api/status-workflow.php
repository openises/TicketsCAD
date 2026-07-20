<?php
/**
 * NewUI v4.0 API — Status Workflow (Phase 105, a beta tester GH #16)
 *
 * Backend for the visual status-workflow designer (workflow-designer.php).
 *
 * GET  /api/status-workflow.php
 *   → { mode, statuses:[{id,status_val,bg_color,text_color,hide}],
 *       transitions:[{id,from_status_id,to_status_id,conditions}],
 *       layout:{ "<status_id>": {x,y}, ... } }
 *
 * POST /api/status-workflow.php  action=save   (CSRF + RBAC)
 *   Body: { action:'save', csrf_token,
 *           transitions:[{from_status_id,to_status_id,conditions}],
 *           layout:{ "<status_id>": {x,y} }, mode:'off'|'warn'|'enforce' }
 *   Replace-all semantics inside one transaction: transitions and layout
 *   are deleted + re-inserted, and the settings row
 *   `status_workflow_mode` is upserted.
 *
 * RBAC: action.manage_status_workflow (Super Admin passes implicitly).
 */

ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/status-workflow.php';

if (!rbac_can('action.manage_status_workflow')) {
    json_error('Insufficient permissions: manage status workflow', 403);
}

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── GET: full workflow snapshot ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $statuses = db_fetch_all(
            "SELECT `id`, `status_val`, `bg_color`, `text_color`, `hide`
             FROM `{$prefix}un_status`
             ORDER BY `sort`, `id`"
        );
        $statuses = array_map(static function ($s) {
            return [
                'id'         => (int) $s['id'],
                'status_val' => (string) $s['status_val'],
                'bg_color'   => (string) $s['bg_color'],
                'text_color' => (string) $s['text_color'],
                'hide'       => (string) $s['hide'],
            ];
        }, $statuses);

        // Transitions + layout tables are created by
        // sql/run_status_workflow.php — degrade to empty sets when the
        // migration hasn't run yet so the designer still opens.
        $transitions = [];
        try {
            $rows = db_fetch_all(
                "SELECT `id`, `from_status_id`, `to_status_id`, `conditions_json`
                 FROM `{$prefix}status_transitions`
                 ORDER BY `from_status_id`, `to_status_id`"
            );
            foreach ($rows as $r) {
                $conds = json_decode((string) ($r['conditions_json'] ?? ''), true);
                $transitions[] = [
                    'id'             => (int) $r['id'],
                    'from_status_id' => (int) $r['from_status_id'],
                    'to_status_id'   => (int) $r['to_status_id'],
                    'conditions'     => is_array($conds) ? $conds : new stdClass(),
                ];
            }
        } catch (Exception $e) { /* table missing — pre-migration */ }

        $layout = new stdClass();
        try {
            $rows = db_fetch_all(
                "SELECT `status_id`, `pos_x`, `pos_y` FROM `{$prefix}status_workflow_layout`"
            );
            $layout = [];
            foreach ($rows as $r) {
                $layout[(string) (int) $r['status_id']] = [
                    'x' => (int) $r['pos_x'],
                    'y' => (int) $r['pos_y'],
                ];
            }
            if (empty($layout)) $layout = new stdClass();
        } catch (Exception $e) { $layout = new stdClass(); }

        json_response([
            'mode'        => sw_get_mode(),
            'statuses'    => $statuses,
            'transitions' => $transitions,
            'layout'      => $layout,
        ]);
    } catch (Throwable $e) {
        json_error_safe('Failed to load status workflow', $e, 'status-workflow');
    }
}

// ── POST: save workflow ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    json_error('Invalid JSON body');
}

// CSRF check on every state change
if (empty($input['csrf_token']) || !csrf_verify((string) $input['csrf_token'])) {
    json_error('Invalid CSRF token', 403);
}

$action = (string) ($input['action'] ?? '');
if ($action !== 'save') {
    json_error('Unknown action');
}

// ── Validate mode ────────────────────────────────────────────────
$mode = strtolower(trim((string) ($input['mode'] ?? 'off')));
if (!in_array($mode, ['off', 'warn', 'enforce'], true)) {
    json_error('Invalid mode — must be off, warn, or enforce');
}

// ── Validate transitions ─────────────────────────────────────────
$transitionsIn = $input['transitions'] ?? [];
if (!is_array($transitionsIn)) {
    json_error('transitions must be an array');
}

$knownConditionKeys = ['requires_assignment', 'requires_no_assignment'];
$cleanTransitions = [];
$seenEdges = [];
foreach ($transitionsIn as $t) {
    if (!is_array($t)) {
        json_error('Each transition must be an object');
    }
    if (!isset($t['from_status_id']) || !isset($t['to_status_id'])
        || !is_numeric($t['from_status_id']) || !is_numeric($t['to_status_id'])) {
        json_error('Each transition needs numeric from_status_id and to_status_id');
    }
    $from = (int) $t['from_status_id'];
    $to   = (int) $t['to_status_id'];
    if ($from < 0 || $to <= 0) {
        json_error('Status ids must be >= 0 (from; 0 = ANY) and > 0 (to)');
    }
    if ($from === $to) {
        json_error('Self-loop transitions are not allowed');
    }

    // Conditions: only the two known keys, boolean values, mutually
    // exclusive. Anything else is rejected so a malformed payload
    // can't smuggle arbitrary condition config into the table.
    $conds = $t['conditions'] ?? [];
    if (!is_array($conds)) {
        json_error('Transition conditions must be an object');
    }
    $cleanConds = [];
    foreach ($conds as $k => $v) {
        if (!in_array($k, $knownConditionKeys, true)) {
            json_error('Unknown condition key: ' . substr((string) $k, 0, 64));
        }
        if (!is_bool($v)) {
            json_error('Condition values must be boolean');
        }
        if ($v === true) {
            $cleanConds[$k] = true;
        }
    }
    if (isset($cleanConds['requires_assignment'], $cleanConds['requires_no_assignment'])) {
        json_error('requires_assignment and requires_no_assignment are mutually exclusive');
    }

    // Dedupe (unique key uniq_edge would throw on re-insert otherwise)
    $edgeKey = $from . '>' . $to;
    if (isset($seenEdges[$edgeKey])) {
        continue;
    }
    $seenEdges[$edgeKey] = true;

    $cleanTransitions[] = [
        'from'       => $from,
        'to'         => $to,
        'conditions' => empty($cleanConds) ? null : json_encode($cleanConds),
    ];
}

// ── Validate layout ──────────────────────────────────────────────
$layoutIn = $input['layout'] ?? [];
if (!is_array($layoutIn)) {
    json_error('layout must be an object');
}
$cleanLayout = [];
foreach ($layoutIn as $sid => $pos) {
    if (!is_numeric($sid) || (int) $sid <= 0 || !is_array($pos)) {
        continue; // skip malformed rows rather than failing the save
    }
    $cleanLayout[(int) $sid] = [
        'x' => (int) ($pos['x'] ?? 0),
        'y' => (int) ($pos['y'] ?? 0),
    ];
}

// ── Persist: replace-all in one transaction ──────────────────────
try {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_query("DELETE FROM `{$prefix}status_transitions`");
        foreach ($cleanTransitions as $t) {
            db_query(
                "INSERT INTO `{$prefix}status_transitions`
                 (`from_status_id`, `to_status_id`, `conditions_json`, `created_by`)
                 VALUES (?, ?, ?, ?)",
                [$t['from'], $t['to'], $t['conditions'], (int) $current_user_id]
            );
        }

        db_query("DELETE FROM `{$prefix}status_workflow_layout`");
        foreach ($cleanLayout as $sid => $pos) {
            db_query(
                "INSERT INTO `{$prefix}status_workflow_layout` (`status_id`, `pos_x`, `pos_y`)
                 VALUES (?, ?, ?)",
                [$sid, $pos['x'], $pos['y']]
            );
        }

        // Upsert the enforcement mode settings row (legacy settings
        // table uses `name`, NOT `key`).
        $existing = db_fetch_value(
            "SELECT `id` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1",
            ['status_workflow_mode']
        );
        if ($existing) {
            db_query(
                "UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = ?",
                [$mode, 'status_workflow_mode']
            );
        } else {
            db_query(
                "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
                ['status_workflow_mode', $mode]
            );
        }

        $pdo->commit();
    } catch (Throwable $inner) {
        $pdo->rollBack();
        throw $inner;
    }

    // Refresh the in-request mode cache so anything later in this
    // request sees the new mode.
    sw_mode_cache_reset();

    // Audit — never let a logging failure break the save.
    try {
        audit_log('config', 'status_workflow_save', 'status_workflow', null,
            'Status workflow saved: ' . count($cleanTransitions)
                . ' transition(s), mode=' . $mode,
            [
                'transition_count' => count($cleanTransitions),
                'layout_count'     => count($cleanLayout),
                'mode'             => $mode,
            ]
        );
    } catch (Throwable $e) {
        error_log('[status-workflow] audit_log failed: ' . $e->getMessage());
    }

    json_response([
        'message'          => 'Status workflow saved',
        'transition_count' => count($cleanTransitions),
        'mode'             => $mode,
    ]);
} catch (Throwable $e) {
    json_error_safe('Failed to save status workflow', $e, 'status-workflow');
}
