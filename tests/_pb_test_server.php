<?php
/**
 * Phase 138 — shared test harness: a real local PHP built-in server rooted
 * at the ACTUAL project tree, so tests/test_public_board_org_scope.php and
 * tests/test_public_board_rate_limit.php drive the REAL api/public-board.php
 * over real HTTP — not a hand-simulated router — while staying fully
 * self-contained (own ephemeral port, own TMP dir so the file-based
 * rate-limit fallback is deterministically controllable, nothing leaves
 * this machine). Mirrors tests/test_web_exposure_backups_probe.php's
 * proc_open pattern (argv array, never a command string — this project
 * gates against shelling out, tests/test_no_shell_command_execution.php).
 *
 * Leading underscore so tools/test_all.php's `test_*.php` glob does not
 * try to run this as a test file itself (same convention as
 * tests/_test_admin.php).
 */

if (!function_exists('pb_test_free_port')) {
    function pb_test_free_port(): ?int
    {
        $s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($s)) return null;
        $name = stream_socket_get_name($s, false);
        fclose($s);
        if (!is_string($name) || strrpos($name, ':') === false) return null;
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * Start `php -S 127.0.0.1:<port> -t <NEWUI_ROOT>` so requests to
     * /api/public-board.php hit the REAL file. A private TMP/TEMP directory
     * is passed via the child's environment so the rate-limiter's
     * file-based fallback bucket path is deterministic and test-owned
     * (this environment has no APCu — extension_loaded('apcu') === false —
     * so the file fallback is always the live path here, not a
     * best-effort alternate).
     *
     * @return array{proc:resource,port:int,tmpdir:string}|null
     */
    function pb_test_start_server(): ?array
    {
        if (!function_exists('proc_open')) return null;
        $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : null;
        if ($bin === null || !@is_file($bin)) return null;
        $port = pb_test_free_port();
        if ($port === null) return null;

        $tmpdir = sys_get_temp_dir() . '/tcad-pb-' . getmypid() . '-' . mt_rand();
        if (!@mkdir($tmpdir, 0777, true) && !is_dir($tmpdir)) return null;
        $logdir = $tmpdir . '/logs';
        @mkdir($logdir, 0777, true);

        $docroot = rtrim(str_replace('\\', '/', NEWUI_ROOT), '/');
        $env = array_merge($_ENV ?: [], getenv() ?: [], ['TMP' => $tmpdir, 'TEMP' => $tmpdir]);

        $desc = [1 => ['file', $logdir . '/out.log', 'a'], 2 => ['file', $logdir . '/err.log', 'a']];
        $proc = @proc_open([$bin, '-S', '127.0.0.1:' . $port, '-t', $docroot], $desc, $pipes, $docroot, $env);
        if (!is_resource($proc)) return null;

        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
            if (is_resource($c)) {
                fclose($c);
                return ['proc' => $proc, 'port' => $port, 'tmpdir' => $tmpdir];
            }
            usleep(50000);
        }
        @proc_terminate($proc);
        @proc_close($proc);
        return null;
    }

    function pb_test_stop_server(?array $srv): void
    {
        if ($srv === null) return;
        @proc_terminate($srv['proc']);
        @proc_close($srv['proc']);
        pb_test_rrmdir($srv['tmpdir']);
    }

    function pb_test_rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            if (is_dir($p)) pb_test_rrmdir($p); else @unlink($p);
        }
        @rmdir($dir);
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string}|null
     */
    function pb_test_http_get(string $url, array $extraHeaders = []): ?array
    {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        if (!empty($extraHeaders)) curl_setopt($ch, CURLOPT_HTTPHEADER, $extraHeaders);
        $resp = curl_exec($ch);
        if ($resp === false) { curl_close($ch); return null; }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $rawHeaders = substr($resp, 0, $headerSize);
        $body = substr($resp, $headerSize);
        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (strpos($line, ':') === false) continue;
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
