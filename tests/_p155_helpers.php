<?php
/**
 * Phase 155 -- shared helpers for the scheduled-incident / status-event /
 * reservation tests. File name starts with `_` so tools/test_all.php (which
 * globs test_*.php) does not run it as a test. Safe to require_once from
 * several test files.
 *
 * Everything here drives the REAL code. Nothing writes a row the real writers
 * never produce except the bare fixture incident/unit/disposition rows that
 * every test in this project starts from (and the "booked in the past" state,
 * which is exactly how time passing is simulated -- never by sleeping).
 */

require_once __DIR__ . '/_test_fixture_guard.php';

if (!function_exists('p155_run_process')) {
    /**
     * Run a PHP CLI script and return its exit code and output, with a hard
     * deadline. ARGV ARRAY + bypass_shell (escapeshellarg mangles quotes on
     * Windows); stdout/stderr go to temp FILES, never pipes -- a pipe plus a
     * non-draining parent is the Windows deadlock this project already hit
     * (CLAUDE.md, "stream_set_blocking IS A NO-OP ON A proc_open PIPE").
     *
     * @param string[] $scriptAndArgs  [script path, arg1, ...]
     * @return array{exit:int,stdout:string,stderr:string,timed_out:bool}
     */
    function p155_run_process(array $scriptAndArgs, int $timeoutS = 90, array $env = []): array {
        $php = PHP_BINARY ?: 'php';
        $cmd = array_merge([$php], $scriptAndArgs);
        $out = tempnam(sys_get_temp_dir(), 'p155o');
        $err = tempnam(sys_get_temp_dir(), 'p155e');
        $envFull = $env ? array_merge(getenv(), $env) : null;
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
            $pipes, null, $envFull, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            @unlink($out); @unlink($err);
            return ['exit' => -1, 'stdout' => '', 'stderr' => 'proc_open failed', 'timed_out' => false];
        }
        fclose($pipes[0]);
        $deadline = microtime(true) + $timeoutS;
        $timedOut = false;
        $exit = -1;
        while (true) {
            $st = proc_get_status($proc);
            if (!$st['running']) { $exit = (int) $st['exitcode']; break; }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                $pid = (int) $st['pid'];
                if (stripos(PHP_OS, 'WIN') === 0) {
                    @exec('taskkill /T /F /PID ' . $pid . ' 2>&1');
                } else {
                    @proc_terminate($proc, 9);
                }
                break;
            }
            usleep(20000);
        }
        $code = proc_close($proc);
        if ($exit === -1 && !$timedOut) $exit = $code;
        $res = ['exit' => $exit, 'stdout' => (string) @file_get_contents($out),
                'stderr' => (string) @file_get_contents($err), 'timed_out' => $timedOut];
        @unlink($out); @unlink($err);
        return $res;
    }
}

if (!function_exists('p155_probe')) {
    /**
     * Drive a real endpoint through tests/_p155_endpoint_probe.php.
     *
     * @param string $mode     'session' (identity = user id) | 'bearer' (identity = raw token)
     * @return array{http:int,json:?array,raw:string}
     */
    function p155_probe(string $mode, string $apiPath, $identity, $body = [], string $method = 'POST', string $qs = '', int $activeOrgId = 0): array {
        $res = p155_run_process([
            __DIR__ . '/_p155_endpoint_probe.php', $mode, $apiPath, (string) $identity, strtoupper($method),
            is_string($body) ? $body : json_encode($body), $qs, (string) $activeOrgId,
        ]);
        $line = trim($res['stdout']);
        // The probe prints one JSON envelope; PHP notices/warnings (none are
        // expected) would precede it, so parse the LAST line.
        $lines = preg_split('/\r?\n/', $line);
        $last = $lines ? end($lines) : '';
        $env = json_decode((string) $last, true);
        if (!is_array($env) || !isset($env['http'])) {
            return ['http' => 0, 'json' => null,
                    'raw' => 'probe output unparseable: ' . substr($res['stdout'] . ' | ' . $res['stderr'], 0, 600)];
        }
        $decoded = json_decode((string) $env['body'], true);
        return ['http' => (int) $env['http'], 'json' => is_array($decoded) ? $decoded : null, 'raw' => (string) $env['body']];
    }
}

if (!function_exists('p155_make_ticket')) {
    /**
     * Insert a bare fixture incident and register it for guaranteed cleanup
     * (the ticket, its action-log rows, its audit rows and any assigns).
     *
     * @param int         $status  1 Closed, 2 Open, 3 Scheduled
     * @param string|null $booked  an SQL expression ('NOW()', "DATE_SUB(NOW(), INTERVAL 5 MINUTE)") or null
     */
    function p155_make_ticket(int $status, ?string $booked = null, string $scope = 'P155 fixture incident'): int {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $bookedSql = $booked === null ? 'NULL' : $booked;   // test-controlled literal, never user input
        db_query(
            "INSERT INTO `{$prefix}ticket` (`in_types_id`, `scope`, `description`, `date`, `status`, `severity`, `booked_date`)
             VALUES (0, ?, 'p155 fixture', NOW(), ?, 0, {$bookedSql})",
            [$scope, $status]
        );
        $tid = (int) db_insert_id();
        p155_track_ticket($tid);
        return $tid;
    }
}

if (!function_exists('p155_track_ticket')) {
    /** Register guaranteed cleanup for an incident and everything the real writers hang off it. */
    function p155_track_ticket(int $tid): void {
        test_fixture_guard_track('ticket', $tid);
        test_fixture_guard_track_where('action', 'ticket_id = ?', [$tid]);
        test_fixture_guard_track_where('assigns', 'ticket_id = ?', [$tid]);
        test_fixture_guard_track_where('newui_audit_log', "target_type = 'ticket' AND target_id = ?", [(string) $tid]);
        // GH#141: reservation and assign audit rows hang off the reservation/assign id and carry the incident in their details.
        test_fixture_guard_track_where('newui_audit_log', "category = 'incident' AND target_type = 'assigns' AND JSON_EXTRACT(details, '$.ticket_id') = ?", [$tid]);
        test_fixture_guard_track_where('assign_reservations', 'ticket_id = ?', [$tid]);
    }
}

if (!function_exists('p155_make_unit')) {
    /** Insert a bare fixture responder (unit). */
    function p155_make_unit(string $handle, int $multi = 0): int {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        db_query(
            "INSERT INTO `{$prefix}responder` (`name`, `handle`, `description`, `multi`) VALUES (?, ?, '', ?)",
            ['P155 ' . $handle, $handle, $multi]
        );
        $rid = (int) db_insert_id();
        test_fixture_guard_track('responder', $rid);
        test_fixture_guard_track_where('assigns', 'responder_id = ?', [$rid]);
        return $rid;
    }
}

if (!function_exists('p155_audit_rows')) {
    /**
     * Audit rows for a ticket, newest last, with details decoded.
     *
     * @return array<int,array>
     */
    function p155_audit_rows(int $ticketId, ?string $activity = null): array {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $sql = "SELECT id, activity, user_id, user_name, summary, details FROM `{$prefix}newui_audit_log`
                 WHERE category = 'incident' AND target_type = 'ticket' AND target_id = ?";
        $params = [(string) $ticketId];
        if ($activity !== null) { $sql .= ' AND activity = ?'; $params[] = $activity; }
        $rows = db_fetch_all($sql . ' ORDER BY id ASC', $params);
        foreach ($rows as &$r) {
            $r['d'] = $r['details'] !== null ? (json_decode((string) $r['details'], true) ?: []) : [];
        }
        unset($r);
        return $rows;
    }
}

if (!function_exists('p155_set_setting')) {
    /** Write a settings row directly (test setup only). */
    function p155_set_setting(string $name, string $value): void {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $has = db_fetch_value("SELECT 1 FROM `{$prefix}settings` WHERE name = ?", [$name]);
        if ($has) {
            db_query("UPDATE `{$prefix}settings` SET value = ? WHERE name = ?", [$value, $name]);
        } else {
            db_query("INSERT INTO `{$prefix}settings` (name, value) VALUES (?, ?)", [$name, $value]);
        }
    }
}

if (!function_exists('p155_cleanup')) {
    /**
     * Remove every fixture this process registered, NOW, silently. The shutdown
     * sweep in _test_fixture_guard.php remains the backstop for a test that dies
     * early (and announces itself when it has to act); calling this at the end of
     * a test that finished normally keeps the output free of that notice.
     */
    function p155_cleanup(): void {
        ob_start();
        try { test_fixture_guard_sweep(); } catch (Throwable $e) { /* backstop reports if anything is left */ }
        ob_end_clean();
    }
}

if (!function_exists('p155_track_fanout_queue')) {
    /**
     * A test that fires audited events with a webhook subscription active makes
     * the real fan-out enqueue rows in `pending_routed_messages` (channel
     * '_notify_fanout') that stay 'pending' when the endpoint is unreachable --
     * and a leftover pending row flips sched_job_required('pending_messages_tick')
     * for every later test. Register removal of every queue row created from
     * this point on. Call it BEFORE the first event is fired.
     */
    function p155_track_fanout_queue(): void {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $max = 0;
        try { $max = (int) db_fetch_value("SELECT COALESCE(MAX(id), 0) FROM `{$prefix}pending_routed_messages`"); } catch (Throwable $e) { return; }
        test_fixture_guard_track_where('pending_routed_messages', "channel = '_notify_fanout' AND id > ?", [$max]);
        // The outbound breaker is persisted in `settings` and opens after a few
        // consecutive failed deliveries -- which is exactly what unreachable test
        // endpoints produce. Leave it closed for the next test, and for ours.
        if (function_exists('notify_breaker_reset')) {
            notify_breaker_reset();
            test_fixture_guard_track_cleanup(function () { notify_breaker_reset(); }, 'notification breaker reset');
        }
    }
}

if (!function_exists('p155_drain')) {
    /**
     * Drain the notification queue once, with the breaker forced closed so an
     * earlier unreachable-endpoint failure cannot defer the delivery under test.
     */
    function p155_drain(int $limit = 50): array {
        if (function_exists('notify_breaker_reset')) notify_breaker_reset();
        return notify_fanout_drain(8.0, $limit);
    }
}

if (!function_exists('p155_remember_settings')) {
    /**
     * Snapshot settings rows and register their restoration (or removal, if the
     * row did not exist). Workers and p155_set_setting() change the shared
     * `settings` table; a test must leave it exactly as it found it.
     *
     * @param string[] $names
     */
    function p155_remember_settings(array $names): void {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        $snap = [];
        foreach ($names as $n) {
            $row = db_fetch_one("SELECT `value` FROM `{$prefix}settings` WHERE name = ?", [$n]);
            $snap[$n] = $row ? (string) $row['value'] : null;
        }
        test_fixture_guard_track_cleanup(function () use ($snap, $prefix) {
            foreach ($snap as $n => $v) {
                if ($v === null) {
                    db_query("DELETE FROM `{$prefix}settings` WHERE name = ?", [$n]);
                } else {
                    $has = db_fetch_value("SELECT 1 FROM `{$prefix}settings` WHERE name = ?", [$n]);
                    if ($has) db_query("UPDATE `{$prefix}settings` SET value = ? WHERE name = ?", [$v, $n]);
                    else db_query("INSERT INTO `{$prefix}settings` (name, value) VALUES (?, ?)", [$n, $v]);
                }
            }
        }, 'restore settings');
    }
}

if (!function_exists('p155_worker')) {
    /**
     * Run tests/_p155_worker.php once and return its decoded `result`.
     *
     * @param string[] $args  e.g. ['id=12', 'to=1', 'set:disposition_required_on_close=1']
     * @return array|null
     */
    function p155_worker(string $action, array $args = [], float $startAt = 0.0): ?array {
        $res = p155_run_process(array_merge(
            [__DIR__ . '/_p155_worker.php', $action, sprintf('%.4f', $startAt)], $args));
        $lines = preg_split('/\r?\n/', trim($res['stdout']));
        $j = json_decode((string) end($lines), true);
        if (!is_array($j) || !array_key_exists('result', $j)) {
            return ['_error' => 'worker output unparseable: ' . substr($res['stdout'] . ' | ' . $res['stderr'], 0, 500)];
        }
        return is_array($j['result']) ? $j['result'] : ['_scalar' => $j['result']];
    }
}

if (!function_exists('p155_worker_race')) {
    /**
     * Launch several workers at the SAME instant and return their results.
     *
     * @param array[] $jobs  each ['action' => string, 'args' => string[]]
     * @return array[]       decoded `result` per job, in order (null if unparseable)
     */
    function p155_worker_race(array $jobs, float $leadS = 2.5): array {
        $php = PHP_BINARY ?: 'php';
        $startAt = microtime(true) + $leadS;
        $running = [];
        foreach ($jobs as $i => $job) {
            $o = tempnam(sys_get_temp_dir(), 'p155w');
            $proc = proc_open(
                array_merge([$php, __DIR__ . '/_p155_worker.php', $job['action'], sprintf('%.4f', $startAt)], $job['args'] ?? []),
                [0 => ['pipe', 'r'], 1 => ['file', $o, 'w'], 2 => ['file', $o . '.err', 'w']],
                $pipes, null, null, ['bypass_shell' => true]);
            $running[$i] = ['proc' => $proc, 'pipes' => $pipes, 'out' => $o];
        }
        $results = [];
        foreach ($running as $i => $r) {
            if (is_resource($r['pipes'][0] ?? null)) fclose($r['pipes'][0]);
            proc_close($r['proc']);
            $lines = preg_split('/\r?\n/', trim((string) @file_get_contents($r['out'])));
            $j = json_decode((string) end($lines), true);
            $results[$i] = (is_array($j) && array_key_exists('result', $j))
                ? (is_array($j['result']) ? $j['result'] : ['_scalar' => $j['result']]) : null;
            @unlink($r['out']); @unlink($r['out'] . '.err');
        }
        return $results;
    }
}

if (!function_exists('p155_start_receiver')) {
    /**
     * Start a throwaway local webhook receiver (tests/_p155_webhook_receiver.php)
     * on a free loopback port. Returns ['proc','port','file','pipes'] or null if it
     * cannot be started. Remember to put the receiver's host (127.0.0.1) on the
     * `webhook_url_allowlist` setting BEFORE any webhook is fired in this process --
     * the SSRF guard otherwise refuses loopback targets, and it caches the allowlist
     * for the life of the process.
     */
    function p155_start_receiver(): ?array {
        $s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($s)) return null;
        $name = stream_socket_get_name($s, false);
        fclose($s);
        $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
        if ($port <= 0) return null;
        $file = sys_get_temp_dir() . '/p155_recv_' . getmypid() . '_' . $port . '.jsonl';
        @unlink($file);
        $env = array_merge(getenv(), ['P155_RECV_FILE' => $file]);
        $log = tempnam(sys_get_temp_dir(), 'p155srv');
        $proc = proc_open(
            [PHP_BINARY ?: 'php', '-S', '127.0.0.1:' . $port, __DIR__ . '/_p155_webhook_receiver.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'w']],
            $pipes, null, $env, ['bypass_shell' => true]);
        if (!is_resource($proc)) return null;
        $up = false;
        for ($i = 0; $i < 50; $i++) {
            $c = @stream_socket_client('tcp://127.0.0.1:' . $port, $e1, $e2, 0.2);
            if (is_resource($c)) { fclose($c); $up = true; break; }
            usleep(100000);
        }
        if (!$up) { proc_terminate($proc); return null; }
        $r = ['proc' => $proc, 'port' => $port, 'file' => $file, 'pipes' => $pipes, 'log' => $log];
        test_fixture_guard_track_cleanup(function () use ($r) { p155_stop_receiver($r); }, 'stop webhook receiver');
        return $r;
    }
}

if (!function_exists('p155_stop_receiver')) {
    function p155_stop_receiver(array $r): void {
        if (!empty($r['proc']) && is_resource($r['proc'])) {
            $st = proc_get_status($r['proc']);
            if (!empty($st['running'])) {
                if (stripos(PHP_OS, 'WIN') === 0) @exec('taskkill /T /F /PID ' . (int) $st['pid'] . ' 2>&1');
                else @proc_terminate($r['proc'], 9);
            }
            @proc_close($r['proc']);
        }
        @unlink($r['file'] ?? ''); @unlink($r['log'] ?? '');
    }
}

if (!function_exists('p155_receiver_events')) {
    /** @return array<int,array{event_type:?string,uid:string,data:mixed}> every POST the receiver has seen */
    function p155_receiver_events(array $r): array {
        $out = [];
        $raw = @file_get_contents($r['file']);
        if ($raw === false) return $out;
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            if ($line === '') continue;
            $j = json_decode($line, true);
            if (is_array($j)) $out[] = $j;
        }
        return $out;
    }
}

if (!function_exists('p155_make_subscription')) {
    /** Insert an active webhook subscription and register its cleanup. */
    function p155_make_subscription(string $name, array $filters, string $url): int {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        db_query(
            "INSERT INTO `{$prefix}webhook_subscriptions`
                (`name`, `target_url`, `hmac_secret`, `event_filters_json`, `active`, `retry_policy_json`)
             VALUES (?, ?, 'p155-test-secret', ?, 1, ?)",
            [$name . '_' . getmypid(), $url, json_encode($filters), json_encode(['max_retries' => 0])]);
        $id = (int) db_insert_id();
        test_fixture_guard_track('webhook_subscriptions', $id);
        test_fixture_guard_track_where('webhook_deliveries', 'subscription_id = ?', [$id]);
        return $id;
    }
}

if (!function_exists('p155_make_status')) {
    /** Insert a throwaway un_status row with a given Dispatch level (0 allow, 1 warn, 2 block). */
    function p155_make_status(string $val, int $dispatch): int {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        db_query(
            "INSERT INTO `{$prefix}un_status`
                (`status_val`, `description`, `dispatch`, `watch`, `hide`, `excl_from_reset`, `group`, `sort`, `bg_color`, `text_color`)
             VALUES (?, ?, ?, 0, 'n', 'n', 'p155_test', 999, '#888888', '#000000')",
            [$val, 'Phase 155 test - ' . $val, $dispatch]);
        $id = (int) db_insert_id();
        test_fixture_guard_track('un_status', $id);
        return $id;
    }
}
