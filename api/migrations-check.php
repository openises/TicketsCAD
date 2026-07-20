<?php
/**
 * NewUI v4.0 API — Pending Migrations Check (Phase 13)
 *
 * GET /api/migrations-check.php
 *   Returns whether the install has unrun database migrations.
 *   Admin-only. Cached at the JS layer (navbar checks once per session).
 *
 * Response shape:
 *   {
 *     "checked": true,
 *     "pending": 0,           // count of sql/run_*.php files not yet recorded
 *     "applied": 42,          // count successfully applied
 *     "failed":  0,           // count with last status = failed
 *     "tracking_table": true, // false on pre-Phase-13 installs that haven't
 *                             // run the orchestrator at all
 *     "details": [            // present only when pending > 0
 *       { "script": "run_phase14_xyz.php", "note": "new file" },
 *       ...
 *     ]
 *   }
 *
 * The navbar uses `pending > 0 OR !tracking_table` to show a banner
 * pointing the admin at sql/run_migrations.php.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

ini_set('display_errors', '0');

if (!is_admin()) {
    json_error('Admin access required', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$prefix = $GLOBALS['db_prefix'] ?? '';

$response = [
    'checked'        => true,
    'pending'        => 0,
    'applied'        => 0,
    'failed'         => 0,
    'tracking_table' => false,
    'details'        => [],
];

// ── Does the tracking table exist? ──────────────────────────────────────
try {
    db_fetch_value("SELECT COUNT(*) FROM `{$prefix}_migrations`");
    $response['tracking_table'] = true;
} catch (Exception $e) {
    // Pre-Phase-13 install. The orchestrator's first run will create
    // the table and apply everything. Treat that as "all pending".
    $response['tracking_table'] = false;
}

// ── Enumerate migration scripts on disk ─────────────────────────────────
$migDir = realpath(__DIR__ . '/../sql');
$files = $migDir ? glob($migDir . '/run_*.php') : [];
$skip = ['run_migrations.php']; // the orchestrator itself

$onDisk = [];
foreach ($files as $path) {
    $name = basename($path);
    if (in_array($name, $skip, true)) continue;
    $onDisk[$name] = hash_file('sha256', $path);
}

// ── Compare against tracking table ──────────────────────────────────────
$alreadyApplied = [];
if ($response['tracking_table']) {
    try {
        // Most recent ok status per script. A migration that ran ok and
        // was later edited (hash changed) shows as pending again — the
        // orchestrator handles that case too.
        $rows = db_fetch_all(
            "SELECT script_name, script_hash, status, applied_at
             FROM `{$prefix}_migrations`
             ORDER BY applied_at DESC"
        );
        foreach ($rows as $r) {
            $key = $r['script_name'] . '|' . $r['script_hash'];
            if (!isset($alreadyApplied[$key])) {
                $alreadyApplied[$key] = $r['status'];
            }
        }
    } catch (Exception $e) {
        // Can happen during a race with someone running the orchestrator
        // right now. Treat as "couldn't check fully" — show banner as
        // safe default.
        $response['tracking_table'] = false;
    }
}

foreach ($onDisk as $name => $hash) {
    $key = $name . '|' . $hash;
    if (!$response['tracking_table']) {
        // Without a tracking table, everything is pending.
        $response['details'][] = ['script' => $name, 'note' => 'tracking table missing'];
        $response['pending']++;
    } elseif (!isset($alreadyApplied[$key])) {
        // Either never applied, or the file changed since last apply.
        $reason = 'new file';
        // Was a prior version of this script applied?
        $priorVersions = array_filter(
            array_keys($alreadyApplied),
            function ($k) use ($name) { return strpos($k, $name . '|') === 0; }
        );
        if (!empty($priorVersions)) {
            $reason = 'script changed since last apply';
        }
        $response['details'][] = ['script' => $name, 'note' => $reason];
        $response['pending']++;
    } else {
        if ($alreadyApplied[$key] === 'failed') {
            $response['failed']++;
            $response['details'][] = ['script' => $name, 'note' => 'previous attempt FAILED — re-run after fixing'];
        } else {
            $response['applied']++;
        }
    }
}

json_response($response);
