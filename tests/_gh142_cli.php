<?php
/**
 * GH#142 (Phase 155) - run a PHP script as a child process and capture its
 * stdout, stderr and exit code SEPARATELY. Nothing here can deadlock on a full
 * pipe (stdout/stderr go to temp files) and nothing goes through a shell (an
 * argv array, so no escapeshellarg mangling on Windows). Needed because
 * get_variable() caches the whole settings table for the life of a process: a
 * test that needs two values of a setting needs one fresh process per value.
 *
 * Leading underscore so tools/test_all.php's `test_*.php` glob does not run it.
 */

if (!function_exists('gh142_run_php')) {
    /**
     * @param string[] $args script path first, then its arguments
     * @param array<string,string>|null $extraEnv variables added to the child's environment (e.g. a private TMP/TEMP)
     * @return array{code:int, out:string, err:string}
     */
    function gh142_run_php(array $args, int $timeoutSeconds = 60, ?array $extraEnv = null): array
    {
        $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
        $out = tempnam(sys_get_temp_dir(), 'gh142o');
        $err = tempnam(sys_get_temp_dir(), 'gh142e');
        $desc = [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', $out, 'w'],
            2 => ['file', $err, 'w'],
        ];
        $env = null;
        if ($extraEnv !== null) {
            $env = array_merge(getenv() ?: [], $extraEnv);
        }
        $proc = @proc_open(array_merge([$bin], $args), $desc, $pipes, dirname(__DIR__), $env, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            @unlink($out);
            @unlink($err);
            return ['code' => -1, 'out' => '', 'err' => 'proc_open failed'];
        }
        $deadline = time() + $timeoutSeconds;
        $code = -1;
        while (true) {
            $st = proc_get_status($proc);
            if (!$st['running']) {
                $code = (int) $st['exitcode'];
                break;
            }
            if (time() > $deadline) {
                proc_terminate($proc);
                $code = -2;
                break;
            }
            usleep(20000);
        }
        proc_close($proc);
        $res = ['code' => $code, 'out' => (string) @file_get_contents($out), 'err' => (string) @file_get_contents($err)];
        @unlink($out);
        @unlink($err);
        return $res;
    }

    /**
     * Run tests/_gh142_probe.php in a fresh process and return the value it
     * printed (any JSON type: array, string, bool or null). A probe that crashes
     * or prints no value THROWS: a null result is a legitimate answer for several
     * probes (no logo), so a failed probe must never be mistaken for one.
     *
     * @return mixed
     */
    function gh142_probe(string $action, array $params = [])
    {
        $r = gh142_run_php([__DIR__ . '/_gh142_probe.php', $action, json_encode($params)]);
        $j = json_decode(trim($r['out']), true);
        if (!is_array($j) || !array_key_exists('v', $j)) {
            throw new RuntimeException("probe '{$action}' produced no value (exit {$r['code']}): " . substr($r['out'] . $r['err'], 0, 400));
        }
        return $j['v'];
    }
}
