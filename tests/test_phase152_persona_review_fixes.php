<?php
/**
 * Phase 152 -- fixes from the 2026-09-08 nine-agent persona review of the
 * full Communications Console v2 build (see specs/phase-152-comms-
 * console-v2/tasks.md, "9-agent persona review" section, for the full
 * findings list and what was deliberately deferred instead of fixed).
 *
 * Covers what can be driven directly: the shared-resolver JS logic
 * (structural -- see the file's own note on why this project's
 * established pattern is source guards, not a Node harness, for files
 * this DOM/BOM-dependent) and the PHP-side RBAC/audit/scheduled-job
 * fixes (driven for real against a throwaway fixture where the logic is
 * pure PHP).
 *
 * Usage: php tests/test_phase152_persona_review_fixes.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/functions.php';
require_once 'inc/scheduled-jobs.php';

$pass = 0; $fail = 0;
function t($label, $cond, $hint = '') {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . ($hint !== '' && !$cond ? " -- $hint" : '') . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 152 -- persona review fix pass (2026-09-08) ===\n\n";

// ── 1. Simulselect: the readout can no longer promise more than the press delivers ──
$audioJs = (string) @file_get_contents('assets/js/console-audio.js');
$widgetJs = (string) @file_get_contents('assets/js/console-simulselect-widget.js');

echo "-- Simulselect readout/PTT can no longer diverge --\n";
t('a single _simulselectResolved() function now exists', strpos($audioJs, '_simulselectResolved: function ()') !== false);
t('simulselectPttStart() reads from the resolved list, not simulselectMembers() directly',
    (bool) preg_match('/simulselectPttStart:\s*function\s*\(\)\s*\{\s*var resolved = ConsoleAudio\._simulselectResolved\(\);/', $audioJs));
t('simulselectTargets() reads from the SAME resolved list',
    (bool) preg_match('/simulselectTargets:\s*function\s*\(\)\s*\{\s*var resolved = ConsoleAudio\._simulselectResolved\(\);/', $audioJs));
t('the resolver dedupes Zello to one member (seenZello) and Radio to one member (seenRadio)',
    strpos($audioJs, 'var seenZello = false, seenRadio = false;') !== false
    && strpos($audioJs, "if (seenZello) { continue; }") !== false
    && strpos($audioJs, "if (seenRadio) { continue; }") !== false);
t('matrix-engaged members are never deduped (isMatrixEngaged branch has no seen-guard)',
    strpos($audioJs, "resolved.push({ id: id, meta: meta, kind: 'matrix' });") !== false);
t('the matrix-connect closure captures each id via a per-iteration IIFE, not the shared loop var (the second bug found while fixing the first)',
    (bool) preg_match('/\(function \(id\) \{\s*window\.ConsoleMatrix\.connect\(function \(ok\) \{\s*if \(ok\) \{ window\.ConsoleMatrix\.talkStart\(id\); \}\s*\}\);\s*\}\)\(r\.id\);/', $audioJs));
t('the channel-list UI marks a checked-but-excluded box as "(will not transmit)"',
    strpos($widgetJs, "warn.textContent = '(will not transmit)';") !== false
    && strpos($widgetJs, 'var resolvedIds = {};') !== false);

// ── 2. window-detach: beforeunload + pending-request cancellation ──
$detachJs = (string) @file_get_contents('assets/js/window-detach.js');

echo "\n-- window-detach.js: navigation no longer orphans a detached window --\n";
t('a beforeunload handler is registered on successful detach (both PiP and popup paths)',
    substr_count($detachJs, "window.addEventListener('beforeunload', unloadHandler);") === 2);
t('the handler is removed again in restore() so it never lingers after a normal close',
    strpos($detachJs, 'removeUnloadHandler();') !== false
    && strpos($detachJs, "function removeUnloadHandler() {\n            window.removeEventListener('beforeunload', unloadHandler);") !== false);
t('closeDetached() closes a real reference to the window THIS call opened (activeWindow), not the browser\'s current global PiP window',
    strpos($detachJs, 'var activeWindow = null;') !== false
    && strpos($detachJs, 'activeWindow = pipWindow;') !== false
    && strpos($detachJs, 'activeWindow = popup;') !== false
    && strpos($detachJs, 'try { activeWindow.close(); }') !== false);
t('a still-pending PiP request is cancellable (checked in the .then() before ever moving rootEl)',
    (bool) preg_match('/\.then\(function \(pipWindow\) \{\s*if \(cancelled\) \{/', $detachJs));
t('close() and cancellation are the same function (cancelled is set unconditionally by closeDetached)',
    strpos($detachJs, 'function closeDetached() {') !== false
    && strpos($detachJs, 'cancelled = true;') !== false);
t('both open() code paths return closeDetached as their close handle', substr_count($detachJs, 'return { close: closeDetached };') === 2);

foreach (['zello-widget.js' => 'zello', 'radio-widget.js' => 'radio'] as $file => $label) {
    $js = (string) @file_get_contents("assets/js/{$file}");
    t("{$label}-widget.js's Close handler cancels a pending/active detach before hiding",
        (bool) preg_match('/if \(detachHandle\) \{ detachHandle\.close\(\); \}\s*hide\(\);/', $js));
}

// ── 3. matrix_ws_url: a real writer now exists, and the failure is diagnosable ──
echo "\n-- matrix_ws_url: writer added, failure is now diagnosable --\n";
$configurePhp = (string) @file_get_contents('services/audio-matrix/configure-php-settings.php');
t('configure-php-settings.php now accepts an optional browser_public_ws_url conf field',
    strpos($configurePhp, "browser_public_ws_url") !== false
    && strpos($configurePhp, "\$rows['matrix_ws_url'] = \$conf['browser_public_ws_url'];") !== false);
$setupDoc = (string) @file_get_contents('docs/AUDIO-MATRIX-SETUP.md');
t('the setup guide has a dedicated "Expose the browser leg" section', strpos($setupDoc, '## Expose the browser leg') !== false);
t('the troubleshooting table names the actual cause (matrix_ws_url unset) as its own row',
    strpos($setupDoc, "matrix_ws_url` isn't set") !== false);
$apacheConf = (string) @file_get_contents('apache/newui.conf.example');
t('apache/newui.conf.example has a matching (commented-out) /matrix-ws reverse-proxy block',
    strpos($apacheConf, '<Location /matrix-ws>') !== false
    && strpos($apacheConf, 'ws://127.0.0.1:18093/') !== false);
$micJs = (string) @file_get_contents('assets/js/console-mic.js');
t('console-mic.js now warns with the SPECIFIC cause (no session vs. no matrix_ws_url) instead of failing silently',
    strpos($micJs, "could not open a console session") !== false
    && strpos($micJs, "matrix_ws_url is not configured on this install") !== false);

// ── 4. api/console-patch.php: the 'talk' leg requires action.console_tx, and is audited ──
echo "\n-- console-patch.php: 'talk' leg requires console_tx and is audited --\n";
$patchPhp = (string) @file_get_contents('api/console-patch.php');
t('a talk-direction connect additionally requires action.console_tx',
    (bool) preg_match("/if \\(\\\$direction === 'talk' && !rbac_can\\('action\\.console_tx'\\)\\) \\{\\s*json_error\\('Forbidden/", $patchPhp));
t('listen-direction connects are unaffected (no console_tx check added to that branch)',
    substr_count($patchPhp, "rbac_can('action.console_tx')") === 1);
t('a successful talk connect is audited (console.patch_talk_connect)',
    strpos($patchPhp, "'communications', 'console.patch_talk_connect', 'comm_channels', \$other['id'],") !== false);
t('the audit call is scoped to talk only, not fired for every listen/monitor toggle',
    (bool) preg_match("/if \\(\\\$direction === 'talk'\\) \\{\\s*audit_log\\(/", $patchPhp));
t('inc/audit.php is now required by this file', strpos($patchPhp, "require_once __DIR__ . '/../inc/audit.php';") !== false);

$sessionPhp = (string) @file_get_contents('api/console-session.php');
t('console-session.php audits every session create (console.session_create)',
    strpos($sessionPhp, "'communications', 'console.session_create', 'console_sessions', \$sessionRowId,") !== false);
t('console-session.php now requires inc/audit.php', strpos($sessionPhp, "require_once __DIR__ . '/../inc/audit.php';") !== false);

// ── 5. Scheduled job: the patch-rail expiry warning is now registered ──
echo "\n-- The patch-rail expiry-warning tick is registered in the scheduled-jobs registry --\n";
$registry = sched_job_registry();
t('matrix_expiry_warning is a real registry entry', array_key_exists('matrix_expiry_warning', $registry));
if (array_key_exists('matrix_expiry_warning', $registry)) {
    $job = $registry['matrix_expiry_warning'];
    t('interval is 60s (matching the tick script\'s own docblock-suggested cadence)', ($job['interval_s'] ?? null) === 60);
    t('command points at the real tick script', strpos((string) ($job['command'] ?? ''), 'matrix_expiry_warning_tick.php') !== false);
}
$req = sched_job_required('matrix_expiry_warning');
t('sched_job_required() answers for the new job (not the "Unknown job" fallback)',
    is_array($req) && $req['why'] !== 'Unknown job');
t('a fresh/unused install (no comm_routes table, or none with an expiry) reports NOT required',
    $req['required'] === false,
    'got required=' . var_export($req['required'] ?? null, true) . ' why=' . ($req['why'] ?? '?'));

// ── 6. Documentation: known gaps are flagged, not silently dropped ──
echo "\n-- Deferred items are documented, not silently skipped --\n";
$guideDoc = (string) @file_get_contents('docs/COMMS-CONSOLE-GUIDE.md');
t('COMMS-CONSOLE-GUIDE.md now correctly says action.manage_matrix defaults to Org Admin and above',
    strpos($guideDoc, '| Manage the full audio matrix | `action.manage_matrix` | Org Admin and above |') !== false);
t('the Group Transmit cross-class gap is documented as a known, flagged (not silently accepted) limitation',
    strpos($guideDoc, 'Known gap, flagged rather than silently accepted') !== false);
// 2026-10-01 (found while syncing to the public repo): specs/ is
// deliberately excluded from every public release snapshot
// (tools/release-snapshot.sh's own EXCLUDES list) -- it's internal
// planning/design-review material, not shipped product docs. Running
// this suite against that scrubbed tree (which the snapshot script's own
// step 6 does, on purpose, specifically to catch exactly this class of
// "behaves differently once published" gap) found these two assertions
// hard-failing on a FILE THAT WAS NEVER MEANT TO EXIST THERE, rather than
// skipping. Pre-existing gap, unrelated to anything else in this session
// -- docs/COMMS-CONSOLE-GUIDE.md a few lines above IS shipped and its own
// assertions correctly still run either way.
$tasksDoc = (string) @file_get_contents('specs/phase-152-comms-console-v2/tasks.md');
if ($tasksDoc === '') {
    echo "[SKIP] specs/phase-152-comms-console-v2/tasks.md not present in this tree (expected on a public release snapshot -- specs/ is dev-only) -- skipping its 2 content checks\n";
} else {
    t('tasks.md records the persona-review section with its fixed/deferred split',
        strpos($tasksDoc, '9-agent persona review of the full Phase 152 build') !== false
        && strpos($tasksDoc, 'Deliberately NOT built') !== false);
    // The old FALSE claim ("Steps 2-5 not started") is still quoted, in past
    // tense, as context for why the sentence was corrected -- check for the
    // corrected CLAIM instead of asserting the substring is gone entirely.
    t('the stale claim is corrected -- all five steps are now recorded done',
        strpos($tasksDoc, 'All five steps above are done and deployed') !== false);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
