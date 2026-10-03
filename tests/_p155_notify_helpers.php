<?php
/**
 * Shared fixtures for the Phase 155 notification tests (GH#144 / GH#145).
 *
 * The file name starts with `_` so tools/test_all.php (which globs test_*.php)
 * does not try to run it as a test.
 *
 * DISCIPLINE (CLAUDE.md, repeatedly): fixtures are created through the REAL
 * writers wherever one exists (member_create_internal, email_list_*_internal,
 * incident_create_internal, assign_create_internal ...), never as hand-seeded
 * "ideal" rows - the only direct INSERTs are for records that are INPUTS to the
 * thing under test and have no writer function (a constituent, a throwaway
 * user account, a notification rule before the rules API exists).
 *
 * Cleanup closures capture the id arrays BY REFERENCE. Capturing them by value
 * (`use ($ids)`) at registration time - before any id was appended - silently
 * deleted nothing and left fixtures behind (CLAUDE.md, the dead-control sweep).
 * p155_cleanup() reads the live arrays, and the suite verifies things are gone.
 *
 * NEVER touches a live user account: every user here is a throwaway row in the
 * 900155xxx id range and is deleted afterwards.
 */

if (!function_exists('p155_t')) {

    $GLOBALS['P155_PASS'] = 0;
    $GLOBALS['P155_FAIL'] = 0;

    /** Record one assertion. */
    function p155_t(string $label, $cond): bool
    {
        if ($cond) { $GLOBALS['P155_PASS']++; echo "[PASS] {$label}\n"; return true; }
        $GLOBALS['P155_FAIL']++; $GLOBALS['P155_FAILED'][] = $label; echo "[FAIL] {$label}\n";
        return false;
    }

    /** Finish a test file with the canonical summary the runner requires. */
    function p155_done(): void
    {
        $pass = $GLOBALS['P155_PASS']; $fail = $GLOBALS['P155_FAIL'];
        // The suite runner prints only the TAIL of a failing file; repeat the failures
        // there so they are never lost in "earlier lines omitted".
        foreach ($GLOBALS['P155_FAILED'] ?? [] as $l) echo "FAILED CHECK: {$l}\n";
        echo "\n=== {$pass} passed, {$fail} failed ===\n";
        exit($fail > 0 ? 1 : 0);
    }

    /** Skip cleanly: the canonical summary the runner requires, plus a SKIP line. */
    function p155_skip(string $reason): void
    {
        echo "SKIP: {$reason}\n";
        echo "\n=== 0 passed, 0 failed ===\n";
        exit(0);
    }

    $GLOBALS['P155_FIX'] = [
        'users' => [], 'members' => [], 'consts' => [], 'lists' => [],
        'rules' => [], 'tickets' => [], 'responders' => [], 'statuses' => [],
        'logs' => [], 'settings' => [], 'sql' => [],
    ];

    /** Register a raw cleanup statement to run (in reverse order) at the end. */
    function p155_on_cleanup(string $sql, array $params = []): void
    {
        $GLOBALS['P155_FIX']['sql'][] = [$sql, $params];
    }

    function p155_cleanup(): void
    {
        static $running = false;
        if ($running) return;   // re-entrancy guard only; reset below so a later call runs again
        $running = true;
        $p = $GLOBALS['db_prefix'] ?? '';
        $f = &$GLOBALS['P155_FIX'];
        $try = static function (string $sql, array $params = []) {
            try { db_query($sql, $params); } catch (Throwable $e) { /* best effort */ }
        };
        foreach (array_reverse($f['sql']) as $s) $try($s[0], $s[1]);
        foreach ($f['lists'] as $id) {
            $try("DELETE FROM `{$p}email_list_members` WHERE `list_id` = ?", [$id]);
            $try("DELETE FROM `{$p}email_list_members` WHERE `member_type` = 'list' AND `ref_id` = ?", [$id]);
            $try("DELETE FROM `{$p}email_lists` WHERE `id` = ?", [$id]);
            $try("DELETE FROM `{$p}newui_audit_log` WHERE `target_type` = 'email_list' AND `target_id` = ?", [(string) $id]);
        }
        foreach ($f['rules'] as $id) {
            $try("DELETE FROM `{$p}notification_log` WHERE `rule_id` = ?", [$id]);
            $try("DELETE FROM `{$p}notification_rules` WHERE `id` = ?", [$id]);
        }
        foreach ($f['tickets'] as $id) {
            $try("DELETE FROM `{$p}notification_log` WHERE `ticket_id` = ?", [$id]);
            $try("DELETE FROM `{$p}pending_routed_messages` WHERE `ticket_id` = ?", [$id]);
            $try("DELETE FROM `{$p}assigns` WHERE `ticket_id` = ?", [$id]);
            $try("DELETE FROM `{$p}action` WHERE `ticket_id` = ?", [$id]);
            $try("DELETE FROM `{$p}log` WHERE `ticket_id` = ?", [$id]);
            $try("DELETE FROM `{$p}ticket` WHERE `id` = ?", [$id]);
        }
        foreach ($f['responders'] as $id) {
            $try("DELETE FROM `{$p}assigns` WHERE `responder_id` = ?", [$id]);
            $try("DELETE FROM `{$p}responder` WHERE `id` = ?", [$id]);
        }
        foreach ($f['members'] as $id) {
            $try("DELETE FROM `{$p}member_organizations` WHERE `member_id` = ?", [$id]);
            $try("DELETE FROM `{$p}member` WHERE `id` = ?", [$id]);
        }
        foreach ($f['consts'] as $id) $try("DELETE FROM `{$p}constituents` WHERE `id` = ?", [$id]);
        foreach ($f['statuses'] as $id) $try("DELETE FROM `{$p}member_status` WHERE `id` = ?", [$id]);
        foreach ($f['users'] as $id) {
            $try("DELETE FROM `{$p}notification_preferences` WHERE `user_id` = ?", [$id]);
            $try("DELETE FROM `{$p}user_roles` WHERE `user_id` = ?", [$id]);
            $try("DELETE FROM `{$p}user` WHERE `id` = ?", [$id]);
        }
        foreach ($f['settings'] as $name => $orig) {
            if ($orig === null) $try("DELETE FROM `{$p}settings` WHERE `name` = ?", [$name]);
            else $try("UPDATE `{$p}settings` SET `value` = ? WHERE `name` = ?", [$orig, $name]);
        }
        // Everything captured is gone; forget it so a second call is a clean no-op.
        foreach (array_keys($f) as $k) $f[$k] = [];
        $running = false;
    }

    /** Remember a setting's original value so cleanup restores it (null = was absent). */
    function p155_remember_setting(string $name): void
    {
        if (array_key_exists($name, $GLOBALS['P155_FIX']['settings'])) return;
        $p = $GLOBALS['db_prefix'] ?? '';
        $v = db_fetch_value("SELECT `value` FROM `{$p}settings` WHERE `name` = ?", [$name]);
        $GLOBALS['P155_FIX']['settings'][$name] = ($v === false) ? null : (string) $v;
    }

    /** Write a setting (direct, uncached) after remembering the original. */
    function p155_set_setting(string $name, ?string $value): void
    {
        p155_remember_setting($name);
        $p = $GLOBALS['db_prefix'] ?? '';
        $n = (int) db_fetch_value("SELECT COUNT(*) FROM `{$p}settings` WHERE `name` = ?", [$name]);
        if ($value === null) { db_query("DELETE FROM `{$p}settings` WHERE `name` = ?", [$name]); return; }
        if ($n > 0) db_query("UPDATE `{$p}settings` SET `value` = ? WHERE `name` = ?", [$value, $name]);
        else db_query("INSERT INTO `{$p}settings` (`name`, `value`) VALUES (?, ?)", [$name, $value]);
    }

    function p155_install_cleanup(): void
    {
        // Leftovers from an earlier ABORTED run (a fatal can skip the shutdown
        // handler): remove anything carrying the fixture markers before
        // starting, so a re-run is deterministic.
        $p = $GLOBALS['db_prefix'] ?? '';
        $try = static function (string $sql, array $params = []) {
            try { db_query($sql, $params); } catch (Throwable $e) { /* best effort */ }
        };
        $try("DELETE FROM `{$p}email_list_members` WHERE `list_id` IN (SELECT `id` FROM `{$p}email_lists` WHERE `name` LIKE 'P155%')");
        $try("DELETE FROM `{$p}email_lists` WHERE `name` LIKE 'P155%'");
        $try("DELETE FROM `{$p}member` WHERE `first_name` LIKE 'P155%'");
        $try("DELETE FROM `{$p}constituents` WHERE `contact` LIKE 'P155%'");
        $try("DELETE FROM `{$p}member_status` WHERE `description` = 'p155 test'");
        $try("DELETE FROM `{$p}notification_log` WHERE `rule_id` IN (SELECT `id` FROM `{$p}notification_rules` WHERE `name` LIKE 'P155%')");
        $try("DELETE FROM `{$p}notification_rules` WHERE `name` LIKE 'P155%'");
        $try("DELETE FROM `{$p}notification_log` WHERE `subject` LIKE '%P155%' OR `body` LIKE '%P155%'");
        $try("DELETE FROM `{$p}pending_routed_messages` WHERE `channel` = '_notify_rule'");
        $try("DELETE FROM `{$p}assigns` WHERE `ticket_id` IN (SELECT `id` FROM `{$p}ticket` WHERE `scope` LIKE 'P155%')");
        $try("DELETE FROM `{$p}action` WHERE `ticket_id` IN (SELECT `id` FROM `{$p}ticket` WHERE `scope` LIKE 'P155%')");
        $try("DELETE FROM `{$p}ticket` WHERE `scope` LIKE 'P155%'");
        $try("DELETE FROM `{$p}assigns` WHERE `responder_id` IN (SELECT `id` FROM `{$p}responder` WHERE `description` = 'p155 test unit')");
        $try("DELETE FROM `{$p}responder` WHERE `description` = 'p155 test unit'");
        $try("DELETE FROM `{$p}notification_preferences` WHERE `user_id` >= 900155000");
        $try("DELETE FROM `{$p}user_roles` WHERE `user_id` >= 900155000");
        $try("DELETE FROM `{$p}user` WHERE `id` >= 900155000");
        register_shutdown_function('p155_cleanup');
    }

    /** A throwaway user account (never a live one). */
    function p155_make_user(int $id, string $login, ?string $email = null, ?string $phoneM = null,
                            ?string $phoneP = null, array $roleIds = []): int
    {
        $p = $GLOBALS['db_prefix'] ?? '';
        $GLOBALS['P155_FIX']['users'][] = $id;
        db_query("DELETE FROM `{$p}notification_preferences` WHERE `user_id` = ?", [$id]);
        db_query("DELETE FROM `{$p}user_roles` WHERE `user_id` = ?", [$id]);
        db_query("DELETE FROM `{$p}user` WHERE `id` = ?", [$id]);
        db_query(
            "INSERT INTO `{$p}user` (`id`, `user`, `passwd`, `email`, `phone_m`, `phone_p`)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$id, $login, password_hash('unused-test-fixture', PASSWORD_BCRYPT), $email, $phoneM, $phoneP]);
        foreach ($roleIds as $rid) {
            db_query("INSERT INTO `{$p}user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$id, (int) $rid]);
        }
        return $id;
    }

    /** A roster member, through the REAL writer. */
    function p155_make_member(string $first, string $last, ?string $email = null, ?int $statusId = null,
                              ?int $userId = null, string $callsign = '', string $phoneCell = ''): int
    {
        require_once __DIR__ . '/../inc/member-write.php';
        // phone_cell is a GENERATED column on a fresh install (it mirrors field7);
        // member_create_internal() remaps it to the real column.
        $r = member_create_internal([
            'first_name' => $first, 'last_name' => $last, 'email' => (string) $email,
            'member_status_id' => $statusId, 'callsign' => $callsign, 'phone_cell' => $phoneCell,
        ], 0);
        $id = (int) ($r['id'] ?? 0);
        if ($id > 0) $GLOBALS['P155_FIX']['members'][] = $id;
        if ($id > 0 && $userId !== null) {
            $p = $GLOBALS['db_prefix'] ?? '';
            db_query("UPDATE `{$p}member` SET `user_id` = ? WHERE `id` = ?", [$userId, $id]);
        }
        return $id;
    }

    /** A constituent (no writer function exists; it is an INPUT to what is under test). */
    function p155_make_constituent(string $name, ?string $email = null): int
    {
        $p = $GLOBALS['db_prefix'] ?? '';
        db_query(
            "INSERT INTO `{$p}constituents` (`contact`, `phone`, `email`) VALUES (?, '5550000000', ?)",
            [$name, $email]);
        $id = (int) db_insert_id();
        $GLOBALS['P155_FIX']['consts'][] = $id;
        return $id;
    }

    /** A member_status row with an exact label (the table has no unique key). */
    function p155_make_status(string $label): int
    {
        $p = $GLOBALS['db_prefix'] ?? '';
        db_query("INSERT INTO `{$p}member_status` (`status_val`, `description`) VALUES (?, 'p155 test')", [$label]);
        $id = (int) db_insert_id();
        $GLOBALS['P155_FIX']['statuses'][] = $id;
        return $id;
    }

    /** An email list through the REAL writer. */
    function p155_make_list(string $name): int
    {
        require_once __DIR__ . '/../inc/email-list-write.php';
        $r = email_list_create_internal($name, null, 'p155 test', 0);
        $id = (int) ($r['id'] ?? 0);
        if ($id > 0) $GLOBALS['P155_FIX']['lists'][] = $id;
        return $id;
    }

    /** Count rows in newui_audit_log for an email_list activity + target. */
    function p155_audit_count(string $activity, $targetId): int
    {
        $p = $GLOBALS['db_prefix'] ?? '';
        return (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$p}newui_audit_log`
              WHERE `category` = 'config' AND `activity` = ? AND `target_id` = ?",
            [$activity, (string) $targetId]);
    }

    /**
     * Run a PHP script as a subprocess with an argv ARRAY and no shell
     * (escapeshellarg() mangles embedded quotes on Windows), stdout and stderr
     * on separate pipes. Returns ['out'=>string,'err'=>string,'code'=>int].
     */
    function p155_run_php(array $args, ?array $env = null): array
    {
        $php = PHP_BINARY ?: 'php';
        $cmd = array_merge([$php], $args);
        // stderr goes to a temp FILE, not a pipe: a child that logs more than a pipe
        // buffer's worth while we are still draining stdout would deadlock both.
        $errFile = tempnam(sys_get_temp_dir(), 'p155err');
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, $env, ['bypass_shell' => true]);
        if (!is_resource($proc)) { @unlink($errFile); return ['out' => '', 'err' => 'proc_open failed', 'code' => -1]; }
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $code = proc_close($proc);
        $err = (string) @file_get_contents($errFile);
        @unlink($errFile);
        return ['out' => (string) $out, 'err' => $err, 'code' => (int) $code];
    }

    /**
     * Drive the REAL api/notification-rules.php (or api/email-lists.php) in a
     * subprocess with a real session + csrf_token (tests/_p155_rules_probe.php).
     *
     * @param array|string|null $payload POST: an array (JSON-encoded); GET: a query string
     * @param array $opts  endpoint ('rules'|'email-lists'), capture_file (path - replaces the
     *                     send channels with capturing stubs), capture_fail (message)
     * @return array{status:int,json:?array,raw:string,err:string}
     */
    function p155_api(string $method, string $action, int $userId, $payload = null, array $opts = []): array
    {
        $env = getenv();
        if (!is_array($env)) $env = [];
        if (!empty($opts['capture_file'])) {
            $env['P155_CAPTURE'] = '1';
            $env['P155_CAPTURE_FILE'] = $opts['capture_file'];
            if (!empty($opts['capture_fail'])) $env['P155_CAPTURE_FAIL'] = (string) $opts['capture_fail'];
        }
        $arg = ($method === 'GET') ? (string) ($payload ?? '') : ($payload === null ? '' : json_encode($payload));
        $r = p155_run_php([__DIR__ . '/_p155_rules_probe.php', $method, $action, (string) $userId, $arg,
                           (string) ($opts['endpoint'] ?? 'rules')], $env);
        $json = json_decode(trim($r['out']), true);
        $status = preg_match('/HTTP_STATUS=(\d+)/', $r['err'], $m) ? (int) $m[1] : 0;
        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => $r['out'], 'err' => $r['err']];
    }

    /**
     * Create a notification rule through the REAL endpoint (api/notification-rules.php,
     * as the given administrator) and track it for cleanup. Returns the new id, or 0 -
     * the full response of the call is left in $GLOBALS['P155_LAST_API'].
     */
    function p155_create_rule(array $fields, int $adminId): int
    {
        $fields += ['name' => 'P155 rule', 'event_type' => 'incident_create', 'channel' => 'email', 'recipients' => []];
        $r = p155_api('POST', 'create', $adminId, $fields);
        $GLOBALS['P155_LAST_API'] = $r;
        $id = (int) ($r['json']['id'] ?? 0);
        if ($id > 0) $GLOBALS['P155_FIX']['rules'][] = $id;
        return $id;
    }

    /**
     * Replace the send channels IN THIS PROCESS with stubs that record what they
     * would have sent in $GLOBALS['P155_SENT'] and report success (or the given
     * failure). Re-registering a code replaces the real adapter in the registry.
     *
     * @param string[] $codes
     * @param callable|null $result fn(string $code, array $message): array|null - return a
     *        ['success'=>..,'error'=>..] to override the default success
     */
    function p155_install_capture(array $codes = ['email', 'smtp', 'sms', 'local_chat', 'slack', 'telegram', 'push'], ?callable $result = null): void
    {
        require_once __DIR__ . '/../inc/notification-engine.php';
        require_once __DIR__ . '/../inc/notification-delivery.php';
        $GLOBALS['P155_SENT'] = [];
        // The per-channel breaker lives in the settings table, so it OUTLIVES the test process that
        // tripped it. An earlier test file that fires the REAL push adapter on an install with push
        // switched off ("push disabled in settings") leaves that channel's breaker open for its whole
        // cool-off, and every capture below would then be held instead of delivered. Found in CI: the
        // push-recipient assertion failed with the breaker {"push":{"fails":2,...}} printed beside an
        // empty capture. These stubs always succeed, so start them from a closed breaker, and put the
        // setting back afterwards like every other setting a test touches.
        p155_remember_setting('notify_rule_breaker');
        notification_breaker_reset();
        foreach ($codes as $code) {
            $shared = in_array($code, ['slack', 'telegram'], true);
            broker_register($code, [
                'name' => 'p155 capture ' . $code,
                'send' => static function (array $m) use ($code, $result) {
                    $GLOBALS['P155_SENT'][] = ['channel' => $code, 'to' => $m['to'] ?? '', 'subject' => $m['subject'] ?? '',
                        'body' => $m['body'] ?? '', 'content_type' => $m['content_type'] ?? '', 'priority' => $m['priority'] ?? '',
                        'uids' => $m['_recipient_user_ids'] ?? null];
                    if ($result !== null) { $r = $result($code, $m); if (is_array($r)) return $r; }
                    return ['success' => true];
                },
                'receive' => null,
                'status' => static function () { return 'active'; },
                'shared_destination' => $shared,
            ]);
        }
    }

    /** Recipients ('to') of everything captured so far, sorted. */
    function p155_sent_to(?string $channel = null): array
    {
        $to = [];
        foreach ($GLOBALS['P155_SENT'] ?? [] as $s) {
            if ($channel === null || $s['channel'] === $channel) $to[] = (string) $s['to'];
        }
        sort($to);
        return $to;
    }

    /** Deliver every due Notification Rule delivery now (what the scheduled sweep does). */
    function p155_drain(): array
    {
        return notification_delivery_drain(null, 500);
    }

    /**
     * Make the scheduled sweep look LIVE (a fresh pending_messages_tick heartbeat,
     * written by the production writer sched_job_record()) or DEAD (no heartbeat).
     * Live: a dispatch only queues - zero network, the timer owns delivery - so a
     * test drains explicitly. Dead: a bounded inline attempt follows the queueing.
     * The job row's original state is restored at cleanup.
     */
    function p155_scheduler(bool $live): void
    {
        require_once __DIR__ . '/../inc/scheduled-jobs.php';
        $p = $GLOBALS['db_prefix'] ?? '';
        static $remembered = false;
        if (!$remembered) {
            $remembered = true;
            $orig = null;
            try { $orig = db_fetch_one("SELECT * FROM `{$p}scheduled_job_runs` WHERE `job_key` = 'pending_messages_tick'"); } catch (Throwable $e) {}
            if ($orig === null) {
                p155_on_cleanup("DELETE FROM `{$p}scheduled_job_runs` WHERE `job_key` = 'pending_messages_tick'");
            } else {
                p155_on_cleanup("UPDATE `{$p}scheduled_job_runs` SET `last_run_at` = ?, `last_ok_at` = ?, `last_status` = ? WHERE `job_key` = 'pending_messages_tick'",
                    [$orig['last_run_at'], $orig['last_ok_at'], $orig['last_status']]);
            }
        }
        if ($live) {
            sched_job_record('pending_messages_tick', 'ok', 'p155 test: pretending the timer just ran');
        } else {
            try { db_query("DELETE FROM `{$p}scheduled_job_runs` WHERE `job_key` = 'pending_messages_tick'"); } catch (Throwable $e) {}
        }
    }

    /** A unit, through the REAL writer. */
    function p155_make_responder(string $name): int
    {
        require_once __DIR__ . '/../inc/responder-write.php';
        $r = responder_upsert_internal(['name' => $name, 'handle' => $name, 'description' => 'p155 test unit', 'multi' => 1], 0, null);
        $id = (int) ($r['id'] ?? 0);
        if ($id > 0) $GLOBALS['P155_FIX']['responders'][] = $id;
        return $id;
    }

    /**
     * An incident, through the REAL writer (which itself fires the create/dispatch
     * notification events - so create fixtures BEFORE a test rule exists unless the
     * create IS the thing under test).
     */
    function p155_make_incident(array $over = []): array
    {
        require_once __DIR__ . '/../inc/incident-write.php';
        $in = $over + ['in_types_id' => 1, 'scope' => 'P155 test incident', 'description' => 'p155',
                       'street' => '123 Main St', 'city' => 'Springfield', 'state' => 'MN', 'severity' => 0];
        $r = incident_create_internal($in, 0);
        $id = (int) ($r['id'] ?? 0);
        if ($id > 0) $GLOBALS['P155_FIX']['tickets'][] = $id;
        return $r;
    }

    /** Remove notification_log rows + queue rows for a ticket (between sub-scenarios). */
    function p155_reset_deliveries(): void
    {
        $p = $GLOBALS['db_prefix'] ?? '';
        db_query("DELETE FROM `{$p}notification_log` WHERE `subject` LIKE 'P155%' OR `body` LIKE '%P155%' OR `rule_id` IN (SELECT `id` FROM `{$p}notification_rules` WHERE `name` LIKE 'P155%')");
        db_query("DELETE FROM `{$p}pending_routed_messages` WHERE `channel` = '_notify_rule'");
        $GLOBALS['P155_SENT'] = [];
    }
}
