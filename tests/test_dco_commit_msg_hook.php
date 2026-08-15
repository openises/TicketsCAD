<?php
/**
 * DCO commit-msg hook (2026-08-14) — CONTRIBUTING.md requires a
 * "Signed-off-by:" trailer on every commit; nothing enforced it until this
 * hook existed, and a v4.2.19 release commit shipped without one (caught
 * before push, fixed with --amend -s --no-edit — which only works pre-push).
 *
 * Drives the REAL hook script via shell, against synthetic message files —
 * not a reimplementation of its regex.
 *
 * Usage: php tests/test_dco_commit_msg_hook.php
 */
require __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

echo "=== DCO commit-msg hook ===\n\n";

$hook = __DIR__ . '/../tools/git-hooks/commit-msg';
t('hook script exists', is_file($hook));

function run_hook(string $hookPath, string $message): array {
    $tmp = sys_get_temp_dir() . '/dco_hook_test_' . getmypid() . '_' . mt_rand() . '.txt';
    file_put_contents($tmp, $message);
    $cmd = 'bash ' . escapeshellarg($hookPath) . ' ' . escapeshellarg($tmp) . ' 2>&1';
    exec($cmd, $outLines, $exitCode);
    @unlink($tmp);
    return [$exitCode, implode("\n", $outLines)];
}

if (is_file($hook)) {
    [$code, $out] = run_hook($hook, "Fix something\n\nNo trailer here.\n");
    t('rejects a message with no Signed-off-by trailer', $code !== 0);
    t('rejection message names -s as the fix', strpos($out, 'git commit -s') !== false);

    [$code] = run_hook($hook, "Fix something\n\nSigned-off-by: Eric Osterberg <ejosterberg@gmail.com>\n");
    t('accepts a message with a real Signed-off-by trailer', $code === 0);

    [$code] = run_hook($hook, "Signed-off-by: nobody\n");
    t('rejects a Signed-off-by line missing a real name/email shape', $code !== 0);

    [$code] = run_hook($hook, "Merge branch 'feature-x' into main\n");
    t('merge commits are exempt (no trailer needed)', $code === 0);

    [$code] = run_hook($hook, "Merge pull request #42 from someone/branch\n");
    t('a GitHub-style merge commit is also exempt', $code === 0);

    // The exact false start this hook exists to catch: --amend -s --no-edit
    // only works on an UNPUSHED commit. Confirm the hook's own guidance says so.
    [, $rejectOut] = run_hook($hook, "No trailer\n");
    t('rejection guidance distinguishes amend (unpushed) from bypass', strpos($rejectOut, '--amend') !== false);
}

$installer = file_get_contents(__DIR__ . '/../tools/install-git-hooks.sh');
t('install-git-hooks.sh chmods every file in the dir (new hook needs no separate wiring)',
    strpos($installer, "chmod +x tools/git-hooks/*") !== false);
t('install-git-hooks.sh sets core.hooksPath to tools/git-hooks',
    strpos($installer, 'core.hooksPath tools/git-hooks') !== false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
