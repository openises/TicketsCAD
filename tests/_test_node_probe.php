<?php
/**
 * GH#120 (2026-08-28) — shared, hardening-safe CLI probe + runner for
 * tests that shell out to a short Node.js (or other CLI) script.
 *
 * `@shell_exec($cmd)` is NOT a safe way to detect whether an execution
 * function is available. When a function is listed in php.ini's
 * disable_functions — a real, documented hardening posture; this
 * project's own security docs recommend disabling the exec/shell_exec/
 * system/passthru/popen family — calling it throws an UNCATCHABLE-BY-@
 * fatal Error ("Call to undefined function"), not a suppressible
 * warning. Two test files shipped this same session
 * (tests/test_gh117_zello_windows_diagnostics.php,
 * tests/test_gh118_assign_remove_ticketid.php) both probed with
 * `@shell_exec($cand . ' --version 2>&1')` as their FIRST move, so on a
 * host with shell_exec disabled they crashed with exit 255 before their
 * own "SKIP: node not available" fallback ever got a chance to run.
 *
 * test_probe_cli()/test_run_cli() guard with function_exists() first,
 * and prefer proc_open() when available — this project's own GH#93 fix
 * already established proc_open() as the more robust choice for
 * shelling out, and it's a SEPARATE function from the exec/shell_exec/
 * popen family disable_functions commonly targets, so on many hardened
 * hosts the underlying check can actually RUN instead of merely
 * skipping. Both return null (never throw) when no execution mechanism
 * is available at all, so a caller's existing "SKIP: ..." fallback stays
 * reachable no matter which functions this host has disabled.
 *
 * This file has no dependency on config.php/db.php — safe to require
 * from any test, with or without a database.
 */

/**
 * Run a CLI command as an argv array (never a shell string — no
 * escapeshellarg() footguns at call sites, and proc_open's argv form
 * never touches a shell at all) and return its combined stdout+stderr,
 * or null if it could not be run (binary missing, or no execution
 * mechanism available on this host at all).
 *
 * @param string[] $argv e.g. ['node', '/path/to/script.js', 'arg1']
 */
function test_run_cli(array $argv): ?string {
    if (empty($argv)) return null;

    if (function_exists('proc_open')) {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($argv, $descriptors, $pipes);
        if (is_resource($proc)) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return $out . $err;
        }
        // proc_open exists but failed to start (binary not found, etc.) —
        // fall through and try shell_exec in case it's the one actually
        // usable here; cheap to try, and some hosts disable one but not
        // the other in either direction.
    }

    if (function_exists('shell_exec')) {
        $cmd = implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
        $out = @shell_exec($cmd);
        return is_string($out) ? $out : null;
    }

    return null; // neither execution mechanism is available on this host
}

/**
 * Find a working CLI binary from a list of candidates (e.g.
 * ['node', 'node.exe']), verified by running "<bin> --version" and
 * checking the output matches $versionPattern. Returns the resolved
 * binary name, or null if none work or no execution mechanism exists.
 *
 * @param string[] $candidates
 */
function test_probe_cli(array $candidates, string $versionPattern = '/^v?\d+/'): ?string {
    foreach ($candidates as $cand) {
        $out = test_run_cli([$cand, '--version']);
        if ($out !== null && preg_match($versionPattern, trim($out))) {
            return $cand;
        }
    }
    return null;
}
