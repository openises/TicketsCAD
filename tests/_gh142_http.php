<?php
/**
 * GH#142 (Phase 155) - shared harness for the branding tests that drive the
 * REAL endpoints over real HTTP: a local `php -S` rooted at the actual project
 * tree (tests/_pb_test_server.php), real accounts logged in through login.php's
 * own CSRF + cookie flow (the pattern tests/test_gh99_* established), and real
 * multipart uploads with curl. Nothing here simulates a router or fakes a
 * session, so the tests exercise the same code path a browser does.
 *
 * Leading underscore so tools/test_all.php's `test_*.php` glob does not run it.
 */

require_once __DIR__ . '/_pb_test_server.php';

if (!function_exists('gh142_role_id')) {

    /**
     * A local php -S rooted at the project tree, like pb_test_start_server(), but
     * with a PRIVATE temp directory on every platform (TMP and TEMP on Windows,
     * TMPDIR on Linux). The serving endpoint's rate limiter keeps its buckets under
     * sys_get_temp_dir(), and tests/test_gh142_branding_serve.php deliberately
     * exhausts the bucket for 127.0.0.1: on a Linux CI runner that would be a SHARED
     * /tmp bucket and would 429 other tests that run within the next minute.
     * pb_test_start_server() only sets TMP/TEMP, which Linux ignores.
     *
     * @return array{proc:resource,port:int,tmpdir:string}|null  stop with pb_test_stop_server()
     */
    function gh142_start_server(): ?array
    {
        if (!function_exists('proc_open')) return null;
        $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : null;
        if ($bin === null || !@is_file($bin)) return null;
        $port = pb_test_free_port();
        if ($port === null) return null;
        $tmpdir = sys_get_temp_dir() . '/tcad-gh142-' . getmypid() . '-' . mt_rand();
        if (!@mkdir($tmpdir, 0777, true) && !is_dir($tmpdir)) return null;
        $logdir = $tmpdir . '/logs';
        @mkdir($logdir, 0777, true);
        $docroot = rtrim(str_replace('\\', '/', NEWUI_ROOT), '/');
        $env = array_merge($_ENV ?: [], getenv() ?: [], ['TMP' => $tmpdir, 'TEMP' => $tmpdir, 'TMPDIR' => $tmpdir]);
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

    function gh142_role_id(string $name): int
    {
        return (int) db_fetch_value("SELECT `id` FROM " . db_table('roles') . " WHERE `name` = ? ORDER BY `id` LIMIT 1", [$name]);
    }

    /** A throwaway organization. Returns its id. */
    function gh142_make_org(string $name, ?int $parentId = null): int
    {
        db_query(
            "INSERT INTO " . db_table('organizations') . " (`name`, `parent_org_id`, `active`) VALUES (?, ?, 1)",
            [$name, $parentId]
        );
        return (int) db_insert_id();
    }

    /**
     * A throwaway account with a real bcrypt password, never TFA-enrolled,
     * must_change_password = 0 so login completes in one hop. A role is granted
     * globally ($orgId null) or scoped to one organization. An org-scoped grant
     * needs BOTH org_id and scope_id set to the same value: inc/rbac.php's
     * scope check reads scope_id, the org-visibility code reads org_id.
     */
    function gh142_make_user(string $username, string $plainPw, int $roleId, ?int $orgId = null): int
    {
        db_query(
            "INSERT INTO " . db_table('user') . " (`user`, `passwd`, `must_change_password`) VALUES (?, ?, 0)",
            [$username, password_hash($plainPw, PASSWORD_BCRYPT)]
        );
        $uid = (int) db_insert_id();
        gh142_grant_role($uid, $roleId, $orgId);
        return $uid;
    }

    function gh142_grant_role(int $userId, int $roleId, ?int $orgId = null): void
    {
        if ($orgId === null) {
            db_query(
                "INSERT INTO " . db_table('user_roles') . " (`user_id`, `role_id`, `org_id`, `scope_kind`, `scope_id`) VALUES (?, ?, NULL, 'global', NULL)",
                [$userId, $roleId]
            );
        } else {
            db_query(
                "INSERT INTO " . db_table('user_roles') . " (`user_id`, `role_id`, `org_id`, `scope_kind`, `scope_id`) VALUES (?, ?, ?, 'org', ?)",
                [$userId, $roleId, $orgId, $orgId]
            );
        }
    }

    /** Parse an HTTP response into [status, headers(lowercased), body]. */
    function gh142_parse_response($ch, $resp): ?array
    {
        if ($resp === false) { return null; }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $raw    = substr($resp, 0, $size);
        $body   = substr($resp, $size);
        $headers = [];
        $setCookies = [];
        foreach (explode("\r\n", $raw) as $line) {
            if (strpos($line, ':') === false) continue;
            [$k, $v] = explode(':', $line, 2);
            $k = strtolower(trim($k));
            $headers[$k] = trim($v);
            if ($k === 'set-cookie') { $setCookies[] = trim($v); }
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'set_cookies' => $setCookies];
    }

    /**
     * One request. $cookieFile (a cookie jar path) makes it an authenticated
     * session. $post is an array for a form/multipart body or a string for raw.
     */
    function gh142_request(string $method, string $url, ?string $cookieFile = null, $post = null, array $headers = []): ?array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($method === 'HEAD') { curl_setopt($ch, CURLOPT_NOBODY, true); }
        if ($cookieFile !== null) {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        }
        if ($post !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
        if ($headers) { curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); }
        $resp = curl_exec($ch);
        $out = gh142_parse_response($ch, $resp);
        curl_close($ch);
        return $out;
    }

    /** Real login through login.php. Returns a cookie-jar path, or null. */
    function gh142_login(string $base, string $username, string $password): ?string
    {
        $cookie = tempnam(sys_get_temp_dir(), 'gh142ck');
        $page = gh142_request('GET', $base . '/login.php', $cookie);
        if ($page === null) { @unlink($cookie); return null; }
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $page['body'], $m);
        $csrf = $m[1] ?? '';
        $res = gh142_request('POST', $base . '/login.php', $cookie, http_build_query([
            'username' => $username, 'password' => $password, 'csrf_token' => $csrf,
        ]));
        if ($res !== null && in_array($res['status'], [301, 302], true)) {
            return $cookie;
        }
        @unlink($cookie);
        return null;
    }

    /** The CSRF token a signed-in session would send: the page's own meta tag. */
    function gh142_csrf(string $base, string $cookie): string
    {
        $page = gh142_request('GET', $base . '/branding-admin.php', $cookie);
        if ($page !== null && preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page['body'], $m)) {
            return $m[1];
        }
        // A session with no branding permission still has a token on any page.
        $page = gh142_request('GET', $base . '/profile.php', $cookie);
        if ($page !== null && preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page['body'], $m)) {
            return $m[1];
        }
        return '';
    }

    function gh142_json(?array $resp): ?array
    {
        if ($resp === null) return null;
        $j = json_decode($resp['body'], true);
        return is_array($j) ? $j : null;
    }

    /** Multipart upload of $bytes as field $field, with a deliberately misleading name and type. */
    function gh142_upload(string $base, string $cookie, array $fields, string $bytes, string $fakeName = 'logo.png', string $fakeType = 'image/png'): ?array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gh142up');
        file_put_contents($tmp, $bytes);
        $fields['logo'] = new CURLFile($tmp, $fakeType, $fakeName);
        $res = gh142_request('POST', $base . '/api/branding-admin.php', $cookie, $fields);
        @unlink($tmp);
        return $res;
    }

    function gh142_post_json(string $base, string $cookie, array $payload): ?array
    {
        return gh142_request('POST', $base . '/api/branding-admin.php', $cookie, json_encode($payload),
            ['Content-Type: application/json']);
    }

    /** A bare-metal recursive remove for a temp directory the harness made. */
    function gh142_cleanup_cookies(array $cookies): void
    {
        foreach ($cookies as $c) { if ($c) @unlink($c); }
    }
}
