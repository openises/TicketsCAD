<?php
/**
 * NewUI v4.0 — Installation Health / File-Permission Checker (GH #41)
 *
 * Shared library of pure check functions. No side effects, no output,
 * no database requirement — every public function is wrapped so it can
 * NEVER throw. Callers:
 *
 *   - api/health-check.php   (web SAPI — authoritative: is_writable /
 *                             is_readable answer for the WEB user)
 *   - tools/check-health.php (CLI — writability answers reflect the CLI
 *                             user; the unreadable-by-others scan and the
 *                             opcache/version checks are still valid)
 *   - status.php "File & Code Health" card (via the API)
 *
 * Design brief (Eric, 2026-07-04): a self-hosted beta tester who deploys
 * with `git pull` as root repeatedly hits (a) new files owned by root /
 * unreadable by the web user → new JS/endpoints 404 silently, and
 * (b) PHP opcache serving stale code after a pull because apache/php-fpm
 * was never reloaded. Policy: DETECT AND WARN, NEVER AUTO-FIX — "if
 * someone has their own way of managing their file permissions, stay out
 * of their way, but let them know when we see a potential problem."
 */

require_once __DIR__ . '/https.php';   // is_https(), is_https_verified()

// Literal build date. Compiled into the opcache'd copy of this file; the
// version-match check re-reads this constant FRESH from disk and compares
// — a mismatch means the server is executing a stale compiled copy.
if (!defined('HEALTH_CHECK_BUILD')) {
    define('HEALTH_CHECK_BUILD', '2026-07-29');
}

/**
 * Application root. NEWUI_ROOT when config.php has been loaded, else
 * derived from this file's location (inc/ is one level below root).
 */
function health_check_root(): string
{
    if (defined('NEWUI_ROOT')) {
        return NEWUI_ROOT;
    }
    return dirname(__DIR__);
}

/**
 * Resolve a file's owner to a username when possible.
 * Returns username (posix systems), numeric uid string (posix ext
 * missing), or null (Windows / stat failure).
 */
function _health_file_owner(string $path): ?string
{
    try {
        $uid = @fileowner($path);
        if ($uid === false) {
            return null;
        }
        if (function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid($uid);
            if (is_array($pw) && isset($pw['name'])) {
                return $pw['name'];
            }
        }
        // Windows: fileowner() returns 0 for everything — meaningless.
        if (PHP_OS_FAMILY === 'Windows') {
            return null;
        }
        return (string) $uid;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * The user the CURRENT process runs as (web user via web SAPI, CLI user
 * via CLI). Best-effort; null when undeterminable.
 */
function _health_process_user(): ?string
{
    try {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && isset($pw['name'])) {
                return $pw['name'];
            }
        }
        $u = @get_current_user();
        return ($u !== '' && $u !== false) ? $u : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * ── WHO WILL ACTUALLY WRITE THESE DIRECTORIES ───────────────────────────────
 *
 * Everything below exists because this check spent its whole life answering
 * the wrong question.
 *
 * `is_writable()` answers for the CURRENT process user. Under the web SAPI
 * that is the web server account and the answer is right. Run from the command
 * line — which is what docs/UPDATE-CHECKLIST.md tells administrators to do,
 * over SSH, as themselves — it answers for a human login that was never
 * supposed to write those directories in the first place. On 2026-07-31 both
 * live hosts reported "5 critical" and told the operator to `chown` three
 * directories that were already `www-data:www-data 775` and already writable;
 * the same check rendered in the browser said OK. Not a disagreement between
 * two checks: one check, asked as two different people.
 *
 * That is the worst failure mode a health check has. An install that is
 * correct is told it is critically broken, every time it is checked, so the
 * report gets ignored — and this project has already been bitten once by a
 * monitoring surface nobody reads (a scheduled job that had never run for
 * seven weeks, silently, while its noisy neighbour kept working).
 *
 * So: work out who the web server runs as, and answer for THEM. When that
 * cannot be established, say `unknown` — never `ok`, and never `critical`.
 * A confident wrong answer is what caused this.
 */

/**
 * Account names that are conventionally a web server, across the
 * distributions this application is deployed on. Used ONLY to raise
 * confidence in a name discovered by some other means — never to guess one,
 * because a shared-hosting install serves the site as the account owner and
 * that name is on nobody's list.
 */
function _health_known_web_user_names(): array
{
    return [
        'www-data',   // Debian / Ubuntu (apache2, nginx, php-fpm)
        'apache',     // RHEL / Fedora / Alma / Rocky
        'httpd',
        'nginx',      // RHEL nginx packages
        'http',       // Arch
        '_www',       // macOS
        'daemon',     // some minimal / BSD packagings
        'web',
        'nobody',
    ];
}

/**
 * Resolve a username (or uid) to the identity facts writability depends on:
 * uid, primary gid, and every supplementary group.
 *
 * Returns null when POSIX is unavailable (Windows) or the account does not
 * exist. Supplementary groups come from /etc/group; groups provided only by
 * LDAP/SSSD are invisible here, which can under-report access — noted in the
 * output rather than papered over.
 */
function _health_user_record(?string $name, ?int $uid = null): ?array
{
    try {
        if (!function_exists('posix_getpwnam') || !function_exists('posix_getpwuid')) {
            return null;
        }
        $pw = false;
        if ($name !== null && $name !== '') {
            $pw = @posix_getpwnam($name);
        }
        if ($pw === false && $uid !== null) {
            $pw = @posix_getpwuid($uid);
        }
        if (!is_array($pw) || !isset($pw['uid'], $pw['name'])) {
            return null;
        }

        $gids = [(int) $pw['gid']];
        // Supplementary groups. PHP exposes no getgrouplist(), so read the
        // group database directly — a file read, no shell, no subprocess.
        try {
            $groupFile = '/etc/group';
            if (@is_readable($groupFile)) {
                $raw = @file_get_contents($groupFile, false, null, 0, 1048576);
                if (is_string($raw)) {
                    foreach (explode("\n", $raw) as $line) {
                        $parts = explode(':', $line);
                        if (count($parts) < 4) {
                            continue;
                        }
                        $members = array_filter(array_map('trim', explode(',', $parts[3])));
                        if (in_array($pw['name'], $members, true)) {
                            $gids[] = (int) $parts[2];
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Primary group alone is still a usable answer.
        }

        return [
            'name' => (string) $pw['name'],
            'uid'  => (int) $pw['uid'],
            'gid'  => (int) $pw['gid'],
            'gids' => array_values(array_unique($gids)),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Is a web server running on this machine right now, and as whom?
 *
 * Reads /proc directly — no shell, no `ps`, no subprocess (this application
 * hands no command line to a shell anywhere; see
 * tests/test_no_shell_command_execution.php). The master process of apache and
 * nginx runs as root and the workers run as the web account, so uid 0 is
 * skipped: the workers are the ones that will open a file for writing.
 *
 * Returns null on a host with no /proc (Windows, macOS), with hidepid set, or
 * with no web server running.
 *
 * @param string $procRoot Injectable so tests can drive a fixture tree.
 */
function _health_web_user_from_proc(string $procRoot = '/proc'): ?array
{
    try {
        if (!@is_dir($procRoot)) {
            return null;
        }
        $wanted = ['apache2', 'httpd', 'nginx', 'php-fpm', 'lighttpd', 'caddy', 'openlitespeed', 'litespeed'];

        $entries = @scandir($procRoot);
        if ($entries === false) {
            return null;
        }
        foreach ($entries as $pid) {
            if (!ctype_digit((string) $pid)) {
                continue;
            }
            $comm = @file_get_contents($procRoot . '/' . $pid . '/comm', false, null, 0, 256);
            if (!is_string($comm)) {
                continue;
            }
            $comm = trim($comm);
            $match = false;
            foreach ($wanted as $w) {
                // php-fpm workers present as "php-fpm8.2", "php-fpm: pool www".
                if ($comm === $w || strpos($comm, $w) === 0) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                continue;
            }
            $status = @file_get_contents($procRoot . '/' . $pid . '/status', false, null, 0, 8192);
            if (!is_string($status)) {
                continue;
            }
            // "Uid:\treal\teffective\tsaved\tfs" — the effective uid is what
            // the kernel checks when the worker opens a file.
            if (!preg_match('/^Uid:\s+(\d+)\s+(\d+)/m', $status, $m)) {
                continue;
            }
            $euid = (int) $m[2];
            if ($euid === 0) {
                continue;   // the master; its workers carry the answer
            }
            $pw   = function_exists('posix_getpwuid') ? @posix_getpwuid($euid) : false;
            $name = (is_array($pw) && isset($pw['name'])) ? (string) $pw['name'] : null;
            return [
                'name'  => $name,
                'uid'   => $euid,
                'basis' => 'the ' . $comm . ' worker process running on this machine',
            ];
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * What the web server's own configuration says it runs as.
 *
 * Weaker than a running process (a box can have apache installed and idle
 * while nginx actually serves the site) and weaker than the ownership of this
 * install's runtime directories, so it sits below both. Still the only signal
 * left on a host where the server is stopped at the moment of the check.
 *
 * @param array|null $files Injectable [path => regex] so tests can drive a fixture.
 */
function _health_web_user_from_server_config(?array $files = null): ?array
{
    try {
        if ($files === null) {
            $files = [
                '/etc/apache2/envvars'          => '/^\s*export\s+APACHE_RUN_USER=(\S+)/m',
                '/etc/httpd/conf/httpd.conf'    => '/^\s*User\s+([A-Za-z0-9._-]+)\s*$/m',
                '/etc/nginx/nginx.conf'         => '/^\s*user\s+([A-Za-z0-9._-]+)\s*;/m',
                '/usr/local/etc/php-fpm.d/www.conf' => '/^\s*user\s*=\s*(\S+)/m',
                '/etc/php-fpm.d/www.conf'       => '/^\s*user\s*=\s*(\S+)/m',
            ];
        }
        foreach ($files as $path => $pattern) {
            if (!@is_file($path) || !@is_readable($path)) {
                continue;
            }
            $raw = @file_get_contents($path, false, null, 0, 262144);
            if (!is_string($raw) || !preg_match($pattern, $raw, $m)) {
                continue;
            }
            $name = trim($m[1], "\"' \t");
            // "User ${APACHE_RUN_USER}" and friends are indirection, not an answer.
            if ($name === '' || strpos($name, '$') !== false) {
                continue;
            }
            return ['name' => $name, 'uid' => null, 'basis' => $path];
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Who owns this install's runtime directories?
 *
 * Direct evidence about THIS application rather than about the machine: a
 * correctly-installed tree has these owned by (or group-shared with) the web
 * account by construction, because that is what the install documentation
 * instructs. Files inside them are stronger still — a tile under cache/weather
 * was written by api/weather-proxy.php, i.e. by the web server, at runtime.
 *
 * Deliberately refuses to answer when the owners DISAGREE and no candidate
 * carries a conventional web-server name: a split-ownership tree is exactly
 * the situation where a guess would produce the confident wrong answer this
 * whole change exists to stop.
 */
function _health_web_user_from_runtime_owner(?string $root = null): ?array
{
    try {
        if (PHP_OS_FAMILY === 'Windows') {
            return null;   // fileowner() returns 0 for everything on NTFS
        }
        $root = $root ?? health_check_root();
        $sep  = DIRECTORY_SEPARATOR;

        // Artefacts first (proof the web server wrote here), then the
        // directories themselves. GHSA-x9x6-w4fg-pmcc moved Zello recordings
        // to a private directory OUTSIDE $root, added separately below since
        // it can't be expressed as a root-relative path like the others.
        $artefacts = [];
        foreach (['cache/weather', 'uploads', 'uploads/overlays', 'cache'] as $rel) {
            $dir = $root . $sep . str_replace('/', $sep, $rel);
            if (!@is_dir($dir)) {
                continue;
            }
            $found = @glob(rtrim($dir, '/\\') . '/*') ?: [];
            foreach (array_slice($found, 0, 5) as $f) {
                if (@is_file($f)) {
                    $artefacts[] = ['path' => $f, 'rel' => $rel . '/' . basename($f)];
                }
            }
        }
        $dirs = [];
        foreach (['uploads', 'cache', 'cache/weather', 'uploads/overlays'] as $rel) {
            $dir = $root . $sep . str_replace('/', $sep, $rel);
            if (@is_dir($dir)) {
                $dirs[] = ['path' => $dir, 'rel' => $rel];
            }
        }
        // Derived from the SAME $root this function was called with, not the
        // global zello_audio_dir() helper (which always answers for the real
        // NEWUI_ROOT). This function is also called with a throwaway fixture
        // root by tests/test_health_web_user.php to prove an empty install
        // yields no answer -- calling the global helper there would have
        // reached past the fixture and found the real host's actual private
        // directory, which exists once GHSA-x9x6-w4fg-pmcc has shipped, and
        // broken that isolation.
        $zDir = dirname($root) . $sep . 'zello-audio';
        if (@is_dir($zDir)) {
            $dirs[] = ['path' => $zDir, 'rel' => 'zello-audio (private)'];
            $found = @glob(rtrim($zDir, '/\\') . '/*') ?: [];
            foreach (array_slice($found, 0, 5) as $f) {
                if (@is_file($f)) {
                    $artefacts[] = ['path' => $f, 'rel' => 'zello-audio (private)/' . basename($f)];
                }
            }
        }

        foreach ([['artefact', $artefacts], ['directory', $dirs]] as [$kind, $set]) {
            $owners = [];
            foreach ($set as $s) {
                $uid = @fileowner($s['path']);
                if ($uid === false) {
                    continue;
                }
                $owners[(int) $uid][] = $s['rel'];
            }
            if (empty($owners)) {
                continue;
            }
            $uid = null;
            if (count($owners) === 1) {
                $uid = (int) array_key_first($owners);
            } else {
                // Split ownership — only accept a conventional web account.
                $known = _health_known_web_user_names();
                foreach (array_keys($owners) as $candidate) {
                    $pw = function_exists('posix_getpwuid') ? @posix_getpwuid((int) $candidate) : false;
                    if (is_array($pw) && isset($pw['name']) && in_array($pw['name'], $known, true)) {
                        $uid = (int) $candidate;
                        break;
                    }
                }
                if ($uid === null) {
                    return null;   // genuinely ambiguous → unknown, not a guess
                }
            }
            $pw   = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
            $name = (is_array($pw) && isset($pw['name'])) ? (string) $pw['name'] : null;
            $ex   = $owners[$uid][0] ?? '';
            return [
                'name'  => $name,
                'uid'   => $uid,
                'basis' => 'the owner of this install\'s runtime ' . $kind . ' ' . $ex,
            ];
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * What to tell someone whose web server account could not be established.
 *
 * Deliberately platform-aware. Naming a remedy that cannot work on the reader's
 * system is its own defect — the same shape as a health check reporting a
 * problem that is not there. Setting NEWUI_WEB_USER genuinely fixes this on a
 * POSIX host, because the account resolves to a uid and a set of groups and the
 * access question becomes answerable. On a system with no POSIX account model
 * (Windows/IIS) it cannot: there is no way to evaluate another account's access
 * to a path from here, whatever name it is given. There the honest instruction
 * is the browser, where the check runs as the web server and needs to work
 * nothing out at all.
 */
function _health_undetermined_remedy(): string
{
    if (function_exists('posix_getpwnam')) {
        return 'To get a real answer, add define(\'NEWUI_WEB_USER\', \'www-data\'); to config.php, '
             . 'substituting your own web server account (apache, nginx, http, or on shared hosting '
             . 'your own login) — or open Settings → System Health in a browser, where the check runs as the '
             . 'web server itself.';
    }
    return 'This system has no POSIX account model, so one account\'s access to a path cannot be '
         . 'evaluated from another — setting NEWUI_WEB_USER would not change that. Open Settings → '
         . 'System Health in a browser instead: there the check runs as the web server, and reports its '
         . 'own access directly.';
}

/**
 * The account the web server serves this application as.
 *
 * Ordered by how directly the signal answers the question actually being
 * asked, which is "who will open these files for writing":
 *
 *   1. This process, when we ARE the web server (any non-CLI SAPI). Nothing
 *      beats being the user in question — and it is the one path that sees
 *      POSIX ACLs and SELinux, because it can just ask the kernel.
 *   2. NEWUI_WEB_USER, defined in config.php or set in the environment. The
 *      operator told us; that outranks anything we can infer.
 *   3. A web server worker process running right now on this machine.
 *   4. The owner of this install's runtime directories/artefacts.
 *   5. The web server's configuration files.
 *   6. Nothing → not determined. The caller must report `unknown`.
 *
 * Never hardcodes www-data. Installs run as apache, nginx, http, _www, or —
 * on shared hosting — as the account that owns the site, and inventing a
 * default is how a correct install gets told it is broken.
 */
function health_check_web_user(bool $force = false): array
{
    static $cached = null;
    if ($cached !== null && !$force) {
        return $cached;
    }

    $out = [
        'checked'       => true,
        'name'          => null,
        'uid'           => null,
        'gids'          => [],
        'determined'    => false,
        'is_this_process' => false,
        'confidence'    => null,
        'basis'         => null,
        'note'          => '',
    ];

    try {
        $candidate = null;   // ['name'=>?string,'uid'=>?int,'basis'=>string,'confidence'=>string]

        // 1. We are the web server.
        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
            $me = _health_process_user();
            if ($me !== null) {
                $candidate = [
                    'name'       => $me,
                    'uid'        => function_exists('posix_geteuid') ? posix_geteuid() : null,
                    'basis'      => 'this process (SAPI ' . PHP_SAPI . ') — authoritative',
                    'confidence' => 'certain',
                ];
            }
        }

        // 2. Told to us explicitly.
        if ($candidate === null) {
            $configured = null;
            if (defined('NEWUI_WEB_USER')) {
                $configured = trim((string) constant('NEWUI_WEB_USER'));
            }
            if (($configured === null || $configured === '')) {
                $env = getenv('NEWUI_WEB_USER');
                if ($env !== false && trim($env) !== '') {
                    $configured = trim($env);
                }
            }
            if ($configured !== null && $configured !== '') {
                $candidate = [
                    'name'       => $configured,
                    'uid'        => null,
                    'basis'      => 'NEWUI_WEB_USER, configured for this install',
                    'confidence' => 'certain',
                ];
            }
        }

        // 3-5. Inference, strongest first.
        if ($candidate === null) {
            foreach ([
                ['fn' => '_health_web_user_from_proc',           'confidence' => 'high'],
                ['fn' => '_health_web_user_from_runtime_owner',  'confidence' => 'high'],
                ['fn' => '_health_web_user_from_server_config',  'confidence' => 'medium'],
            ] as $probe) {
                $hit = $probe['fn']();
                if (is_array($hit) && ($hit['name'] !== null || $hit['uid'] !== null)) {
                    $candidate = $hit + ['confidence' => $probe['confidence']];
                    break;
                }
            }
        }

        if ($candidate === null) {
            $out['note'] = 'Could not establish which account the web server runs as, so writability '
                . 'cannot be answered for it. Every directory below is reported as UNKNOWN rather than '
                . 'guessed. ' . _health_undetermined_remedy();
            $cached = $out;
            return $out;
        }

        $rec = _health_user_record($candidate['name'] ?? null, $candidate['uid'] ?? null);

        $out['name']       = $rec['name'] ?? ($candidate['name'] ?? null);
        $out['uid']        = $rec['uid']  ?? ($candidate['uid']  ?? null);
        $out['gids']       = $rec['gids'] ?? [];
        $out['basis']      = $candidate['basis'];
        $out['confidence'] = $candidate['confidence'];

        $myUid  = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $myName = _health_process_user();
        $out['is_this_process'] = ($out['uid'] !== null && $myUid !== null && (int) $out['uid'] === (int) $myUid)
            || ($out['uid'] === null && $myName !== null && $out['name'] === $myName);

        // Determined only if we can actually EVALUATE access as that account:
        // either it is us (ask the kernel) or POSIX gave us its uid + groups.
        $out['determined'] = $out['is_this_process'] || ($out['uid'] !== null && !empty($out['gids']));

        if (!$out['determined']) {
            $out['note'] = 'The web server appears to run as "' . (string) $out['name'] . '" (' . $out['basis']
                . '), but this system cannot resolve that account\'s user and group ids, so its access '
                . 'cannot be evaluated. Directories below are reported as UNKNOWN rather than guessed. '
                . _health_undetermined_remedy();
        } elseif (!$out['is_this_process']) {
            $out['note'] = 'Writability below is evaluated for "' . (string) $out['name'] . '" — '
                . $out['basis'] . ' — not for the account running this command. '
                . 'Ownership and mode bits are what is examined; POSIX ACLs and SELinux are not visible '
                . 'here, so a directory reported unwritable may still be writable through an ACL.';
        }

        $cached = $out;
        return $out;
    } catch (Throwable $e) {
        $cached = null;   // do not memoise a failure
        $out['note'] = 'Web server account could not be determined (internal error).';
        return $out;
    }
}

/**
 * PURE: would an account with these identity facts be able to write into a
 * directory with this ownership and mode?
 *
 * POSIX checks exactly ONE class and stops — owner, else group, else other —
 * so a directory you own at mode 0077 is not writable by you, however
 * permissive the group and other bits look. Creating an entry in a directory
 * needs BOTH write and search (x) on that class.
 *
 * @param array $user ['uid'=>int,'gids'=>int[]]
 */
function _health_mode_writable(int $ownerUid, int $ownerGid, int $mode, array $user): bool
{
    $uid  = (int) ($user['uid'] ?? -1);
    $gids = array_map('intval', (array) ($user['gids'] ?? []));

    if ($uid === 0) {
        return true;   // root is not subject to mode bits
    }
    $mode &= 0777;
    if ($uid === $ownerUid) {
        return ($mode & 0300) === 0300;
    }
    if (in_array($ownerGid, $gids, true)) {
        return ($mode & 0030) === 0030;
    }
    return ($mode & 0003) === 0003;
}

/**
 * Can the web server account write into $abs?
 *
 * true / false / null, where null means "not established" and must surface as
 * UNKNOWN. Asking the kernel (is_writable) is preferred whenever the account
 * in question is the one running this code, because only that path accounts
 * for ACLs and SELinux.
 */
function _health_path_writable_for(string $abs, array $webUser): ?bool
{
    try {
        if (empty($webUser['determined'])) {
            return null;
        }
        if (!empty($webUser['is_this_process'])) {
            return @is_writable($abs);
        }
        if ($webUser['uid'] === null) {
            return null;
        }
        if ((int) $webUser['uid'] === 0) {
            return true;
        }
        $st = @stat($abs);
        if (!is_array($st) || !isset($st['uid'], $st['gid'], $st['mode'])) {
            return null;
        }
        return _health_mode_writable((int) $st['uid'], (int) $st['gid'], (int) $st['mode'], $webUser);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Is a recursive chown of this path safe to suggest to an administrator?
 *
 * Standing rule in this project (docs/UPDATE-CHECKLIST.md, 2026-07-28): never
 * tell anyone to `chown -R` anything that carries .git with it. Git ≥ 2.35.2
 * refuses to operate on a repository owned by someone else (CVE-2022-24765),
 * so the reader's next `git pull` dies with "detected dubious ownership" —
 * and it was never necessary: the web server only READS program files.
 *
 * Suggestions scoped to uploads/ and cache/ are fine. Anything that is the
 * install root, an ancestor of it, or contains a .git directory is not.
 */
function _health_recursive_chown_safe(string $abs): bool
{
    try {
        if ($abs === '') {
            return false;
        }
        $norm = function (string $p): string {
            $r = @realpath($p);
            return rtrim(str_replace('\\', '/', $r !== false ? $r : $p), '/');
        };
        $target = $norm($abs);
        $root   = $norm(health_check_root());
        if ($target === '' || $target === '/') {
            return false;
        }
        if ($target === $root) {
            return false;                                   // the install itself
        }
        if (strpos($root . '/', $target . '/') === 0) {
            return false;                                   // an ancestor of the install
        }
        if (@is_dir($abs . DIRECTORY_SEPARATOR . '.git') || @is_file($abs . DIRECTORY_SEPARATOR . '.git')) {
            return false;                                   // carries a repository
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Check the required-writable directories.
 *
 * Answers for the WEB SERVER account (health_check_web_user()), not for
 * whoever invoked the check — see the long note above. Severity model, which
 * is the SAME for the command line and the browser because it is computed
 * here once:
 *
 *   - exists + writable                → ok
 *   - exists + NOT writable            → critical (uploads/recordings/cache writes fail)
 *   - missing, parent writable         → warn     (created on demand at runtime)
 *   - missing, parent NOT writable     → critical (creation will fail at runtime)
 *   - web server account not known     → unknown  (never ok, never critical)
 *
 * @param array      $extraDirs Additional absolute or root-relative paths to
 *                              check (used by tests and future recordings dirs).
 * @param array|null $webUser   Override the resolved web server account. Exists
 *                              so the severity model can be driven directly —
 *                              including the not-determined case, which no host
 *                              can be relied upon to reproduce on demand.
 */
function health_check_dirs(array $extraDirs = [], ?array $webUser = null): array
{
    try {
        $root    = health_check_root();
        $webUser = $webUser ?? health_check_web_user();

        // Root-relative required-writable dirs. This is the exact set that
        // broke for the git-pull-as-root beta install. Zello's recordings
        // dir is OUTSIDE $root since GHSA-x9x6-w4fg-pmcc, so it travels via
        // $extraDirs (see the call site) rather than living in this list.
        $relDirs = [
            'uploads'           => 'file attachments (api/upload.php)',
            'uploads/overlays'  => 'map image overlays (api/map-image-overlays.php)',
            'cache'             => 'general cache root',
            'cache/weather'     => 'weather tile cache (api/weather-proxy.php)',
        ];

        $entries = [];
        $who     = $webUser['name'] !== null ? '"' . $webUser['name'] . '"' : 'the web server';

        $check = function (string $abs, string $rel, string $purpose) use (&$entries, $webUser, $who) {
            $exists   = @is_dir($abs);
            $writable = $exists ? _health_path_writable_for($abs, $webUser) : null;
            $owner    = $exists ? _health_file_owner($abs) : null;
            $mode     = null;
            if ($exists) {
                $perms = @fileperms($abs);
                if ($perms !== false) {
                    $mode = sprintf('%04o', $perms & 0777);
                }
            }

            if ($exists && $writable === true) {
                $severity = 'ok';
                $note     = '';
            } elseif ($exists && $writable === false) {
                $severity = 'critical';
                $note     = 'Directory exists but ' . $who . ' cannot write to it'
                          . ($owner !== null ? ' (owner ' . $owner . ($mode !== null ? ', mode ' . $mode : '') . ')' : '')
                          . ' — uploads, recordings and cache writes will fail.';
            } elseif ($exists) {
                $severity = 'unknown';
                $note     = 'Directory exists; whether the web server can write to it could not be established.';
            } else {
                // Missing — creatable if the nearest existing ancestor is
                // writable BY THE WEB SERVER. Being missing is normal: every
                // one of these is created on demand (api/upload.php,
                // api/weather-proxy.php, api/map-image-overlays.php,
                // proxy/ZelloProxyApp.php all mkdir their own target).
                $parent = dirname($abs);
                while ($parent !== '' && $parent !== dirname($parent) && !@is_dir($parent)) {
                    $parent = dirname($parent);
                }
                $creatable = ($parent !== '' && @is_dir($parent))
                    ? _health_path_writable_for($parent, $webUser)
                    : false;

                if ($creatable === true) {
                    $severity = 'warn';
                    $note     = 'Directory is missing but can be created on demand.';
                } elseif ($creatable === false) {
                    $severity = 'critical';
                    $note     = 'Directory is missing and ' . $who . ' cannot write its parent ('
                              . $parent . ') — the app cannot create it.';
                } else {
                    $severity = 'unknown';
                    $note     = 'Directory is missing; whether the web server could create it could not '
                              . 'be established.';
                }
            }

            $entries[] = [
                'path'         => $rel,
                'abs'          => $abs,
                'purpose'      => $purpose,
                'exists'       => (bool) $exists,
                // true / false / null. null means UNKNOWN and must never be
                // rendered as "No" — that is how a correct install gets called
                // broken.
                'writable'     => $writable,
                'writable_for' => $webUser['name'],
                'owner'        => $owner,
                'mode'         => $mode,
                'severity'     => $severity,
                'note'         => $note,
            ];
        };

        foreach ($relDirs as $rel => $purpose) {
            $check($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel), $rel, $purpose);
        }

        foreach ($extraDirs as $extra) {
            $extra = (string) $extra;
            if ($extra === '') {
                continue;
            }
            // Absolute path (unix or windows drive) vs root-relative.
            $isAbs = ($extra[0] === '/' || $extra[0] === '\\' || preg_match('/^[A-Za-z]:[\\/\\\\]/', $extra));
            $abs   = $isAbs ? $extra : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $extra);
            $check($abs, $extra, 'extra (caller-supplied)');
        }

        return [
            'checked'      => true,
            'process_user' => _health_process_user(),
            'web_user'     => $webUser,
            'dirs'         => $entries,
        ];
    } catch (Throwable $e) {
        return ['checked' => false, 'error' => 'dirs check failed', 'dirs' => []];
    }
}

/**
 * Find files the CURRENT process cannot read.
 *
 * A full-tree scan is too slow per-request, so scan the highest-risk sets:
 *   (a) every file in assets/js/ and api/ — the "new JS / new endpoint
 *       404s silently" killers (an unreadable event-bus.js kills ALL
 *       real-time updates with no visible error), and
 *   (b) the 20 most-recently-modified .php/.js files anywhere under the
 *       app root — the "just pulled" set — via a bounded iterator that
 *       skips .git, vendor, uploads, cache, node_modules, backups.
 *
 * Output capped at 50 entries + a truncated flag.
 */
function health_check_unreadable(): array
{
    try {
        $root       = health_check_root();
        $rootReal   = @realpath($root) ?: $root;
        $unreadable = [];
        $scanned    = 0;
        $truncated  = false;
        $cap        = 50;

        $relPath = function (string $abs) use ($rootReal): string {
            $rel = $abs;
            if (strpos($abs, $rootReal) === 0) {
                $rel = ltrim(substr($abs, strlen($rootReal)), '/\\');
            }
            return str_replace('\\', '/', $rel);
        };

        $addIfUnreadable = function (string $abs) use (&$unreadable, &$scanned, &$truncated, $cap, $relPath): void {
            $scanned++;
            if (@is_readable($abs)) {
                return;
            }
            if (count($unreadable) >= $cap) {
                $truncated = true;
                return;
            }
            $unreadable[] = ['path' => $relPath($abs), 'issue' => 'unreadable'];
        };

        // ── (a) Targeted sets: assets/js/ and api/ ──────────────────────
        foreach (['assets/js', 'api'] as $sub) {
            $dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sub);
            if (!@is_dir($dir)) {
                continue;
            }
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY,
                    RecursiveIteratorIterator::CATCH_GET_CHILD
                );
                foreach ($it as $file) {
                    if (!$file->isFile()) {
                        continue;
                    }
                    $addIfUnreadable($file->getPathname());
                    if ($scanned > 20000) {
                        $truncated = true;
                        break;
                    }
                }
            } catch (Throwable $e) {
                // The directory itself may be unreadable — that IS a finding.
                if (count($unreadable) < $cap) {
                    $unreadable[] = ['path' => $sub . '/', 'issue' => 'unreadable'];
                } else {
                    $truncated = true;
                }
            }
        }

        // ── (b) 20 most-recently-modified .php/.js files under root ─────
        $skipDirs = ['.git', 'vendor', 'uploads', 'cache', 'node_modules', 'backups', '.claude'];
        $recent   = []; // mtime-keyed candidates
        try {
            $filter = new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                function ($current) use ($skipDirs) {
                    /** @var SplFileInfo $current */
                    if ($current->isDir()) {
                        return !in_array($current->getFilename(), $skipDirs, true);
                    }
                    $ext = strtolower((string) $current->getExtension());
                    return ($ext === 'php' || $ext === 'js');
                }
            );
            $it = new RecursiveIteratorIterator(
                $filter,
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            $visited = 0;
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $visited++;
                if ($visited > 20000) { // hard bound on per-request cost
                    break;
                }
                $mtime = 0;
                try {
                    $mtime = (int) $file->getMTime();
                } catch (Throwable $e) {
                    // Can't even stat it — very likely unreadable; probe below.
                    $mtime = PHP_INT_MAX; // force into the "recent" probe set
                }
                $recent[] = ['path' => $file->getPathname(), 'mtime' => $mtime];
            }
            usort($recent, function ($a, $b) {
                return $b['mtime'] <=> $a['mtime'];
            });
            $recent = array_slice($recent, 0, 20);
            foreach ($recent as $r) {
                // Avoid double-reporting files already caught in set (a).
                $rel = $relPath($r['path']);
                $already = false;
                foreach ($unreadable as $u) {
                    if ($u['path'] === $rel) {
                        $already = true;
                        break;
                    }
                }
                if (!$already) {
                    $addIfUnreadable($r['path']);
                }
            }
        } catch (Throwable $e) {
            // Bounded scan failed (permissions on root?) — report nothing
            // extra rather than crash; set (a) results still stand.
        }

        return [
            'checked'    => true,
            'scanned'    => $scanned,
            'unreadable' => $unreadable,
            'truncated'  => $truncated,
        ];
    } catch (Throwable $e) {
        return ['checked' => false, 'error' => 'unreadable scan failed', 'unreadable' => [], 'truncated' => false];
    }
}

/**
 * Report opcache configuration as seen by THIS SAPI.
 *
 * WARN when opcache is enabled with validate_timestamps off: code changes
 * on disk will NOT take effect until the web server / php-fpm is reloaded.
 * (Even with validate_timestamps on, revalidate_freq seconds may pass
 * before a change is picked up — informational.)
 *
 * The definitive "server is executing stale code" signal is
 * health_check_version_match(), not this.
 */
function health_check_opcache(): array
{
    try {
        $available = function_exists('opcache_get_status');
        $enabled   = false;
        if ($available) {
            $enabled = filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
            if (PHP_SAPI === 'cli') {
                $enabled = filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN);
            }
        }

        $vtRaw = ini_get('opcache.validate_timestamps');
        $validateTimestamps = ($vtRaw === false) ? null : filter_var($vtRaw, FILTER_VALIDATE_BOOLEAN);
        $freqRaw = ini_get('opcache.revalidate_freq');
        $revalidateFreq = ($freqRaw === false) ? null : (int) $freqRaw;

        $severity = 'ok';
        $note     = '';
        if ($enabled && $validateTimestamps === false) {
            $severity = 'warn';
            $note     = 'opcache is enabled with validate_timestamps=0 — code changes on disk will NOT take effect until the web server or php-fpm is reloaded (sudo systemctl reload apache2 / php-fpm).';
        }

        $mtime = @filemtime(__FILE__);

        return [
            'checked'             => true,
            'sapi'                => PHP_SAPI,
            'enabled'             => (bool) $enabled,
            'validate_timestamps' => $validateTimestamps,
            'revalidate_freq'     => $revalidateFreq,
            'build'               => HEALTH_CHECK_BUILD,
            'file_mtime'          => $mtime ? date('Y-m-d H:i:s', $mtime) : null,
            'severity'            => $severity,
            'note'                => $note,
        ];
    } catch (Throwable $e) {
        return ['checked' => false, 'error' => 'opcache check failed', 'severity' => 'ok'];
    }
}

/**
 * Parse a define('CONST', 'literal') value fresh from a file ON DISK.
 * Returns the literal string or null.
 */
function _health_parse_define(string $file, string $constName): ?string
{
    try {
        if (!@is_file($file) || !@is_readable($file)) {
            return null;
        }
        $src = @file_get_contents($file, false, null, 0, 65536);
        if ($src === false) {
            return null;
        }
        $pat = '/define\s*\(\s*[\'"]' . preg_quote($constName, '/') . '[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/';
        if (preg_match($pat, $src, $m)) {
            return $m[1];
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Definitive opcache-staleness detector: compare constants as COMPILED
 * into the running process against the same literals parsed FRESH from
 * disk (file_get_contents bypasses opcache).
 *
 *   1. NEWUI_VERSION — a legacy config.php may still define it as a literal.
 *      Since 2026-07 the canonical version is the git-tracked `VERSION` file
 *      (see inc/version.php), which is read at RUNTIME — so on a modern
 *      install this arm can no longer detect staleness (a file read always
 *      reflects disk). It is kept because installs predating the change do
 *      still carry the literal, and because reporting the resolved version +
 *      its source is useful on the health card either way.
 *   2. HEALTH_CHECK_BUILD — self-probe against this very file, which IS
 *      git-tracked: after a pull that updates inc/health-check.php, a
 *      stale opcache serves the old compiled constant while the disk
 *      regex shows the new one. THIS is the reliable staleness detector.
 *
 * Either mismatch → CRITICAL: "server is executing stale code; reload
 * apache2/php-fpm."
 */
function health_check_version_match(): array
{
    try {
        $root = health_check_root();

        // ── NEWUI_VERSION: running vs disk ───────────────────────────────
        $running     = defined('NEWUI_VERSION') ? (string) NEWUI_VERSION : null;
        $versionFile = null;
        $onDisk      = null;
        foreach (['config.php', 'inc/version.php', 'config.example.php'] as $cand) {
            $abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cand);
            $val = _health_parse_define($abs, 'NEWUI_VERSION');
            if ($val !== null) {
                $versionFile = $cand;
                $onDisk      = $val;
                break;
            }
        }
        // No literal define anywhere → a current install, whose version comes
        // from the tracked VERSION file. Report that as the source.
        if ($onDisk === null) {
            $verFile = $root . DIRECTORY_SEPARATOR . 'VERSION';
            $raw     = is_file($verFile) ? @file_get_contents($verFile, false, null, 0, 256) : false;
            if (is_string($raw) && trim($raw) !== '') {
                $versionFile = 'VERSION';
                $onDisk      = trim(strtok($raw, "\r\n") ?: '');
                if ($running === null) {
                    $running = $onDisk;
                }
            }
        }
        // Only meaningful when both sides resolved.
        $versionComparable = ($running !== null && $onDisk !== null);
        $versionMatch      = $versionComparable ? ($running === $onDisk) : null;

        // A pre-2026-07 config.php may still pin a define('NEWUI_VERSION', …)
        // from its install date. Harmless — every reader calls newui_version(),
        // which prefers the tracked file — but worth telling the admin so the
        // dead line can go. Advisory only: severity stays 'ok'.
        // NOTE: deliberately does NOT touch $versionMatch. running-vs-disk stays
        // a pure staleness comparison (both sides read the same config.php
        // literal); the pin is reported separately.
        $configPin = function_exists('newui_version_config_pin') ? newui_version_config_pin() : null;
        $reported  = function_exists('newui_version') ? newui_version() : $running;

        // ── Self-probe: HEALTH_CHECK_BUILD running vs disk ───────────────
        $probeRunning = HEALTH_CHECK_BUILD;
        $probeOnDisk  = _health_parse_define(__FILE__, 'HEALTH_CHECK_BUILD');
        $probeMatch   = ($probeOnDisk !== null) ? ($probeRunning === $probeOnDisk) : null;

        $severity = 'ok';
        $note     = '';
        if ($versionMatch === false || $probeMatch === false) {
            $severity = 'critical';
            $note     = 'The server is EXECUTING STALE CODE: the version compiled into the running process differs from the file on disk. Reload the web server: sudo systemctl reload apache2   (or: sudo systemctl reload php8.2-fpm)';
        }

        if ($configPin !== null && $note === '') {
            $note = 'config.php still pins define(\'NEWUI_VERSION\', \'' . $configPin . '\') from when this '
                  . 'install was created. TicketsCAD now reports the git-tracked VERSION file (' . $reported
                  . '), so nothing is broken — but that line is dead and can be deleted (or replaced with '
                  . "require_once __DIR__ . '/inc/version.php';).";
        }

        return [
            'checked'       => true,
            'version_file'  => $versionFile,
            'running'       => $running,
            'on_disk'       => $onDisk,
            'reported'      => $reported,
            'config_pin'    => $configPin,
            'match'         => $versionMatch,
            'probe_file'    => 'inc/health-check.php',
            'probe_running' => $probeRunning,
            'probe_on_disk' => $probeOnDisk,
            'probe_match'   => $probeMatch,
            'severity'      => $severity,
            'note'          => $note,
        ];
    } catch (Throwable $e) {
        return ['checked' => false, 'error' => 'version check failed', 'severity' => 'ok', 'match' => null];
    }
}

/**
 * Bundle every check + a summary the banner / status card can key off.
 * Summary counts: each problem dir, each unreadable file, an opcache
 * warn, and a version mismatch each count once.
 */
/**
 * Composer dependency presence. `vendor/` is gitignored and recreated by
 * `composer install`; if an admin deploys the code without running it, several
 * optional PHP features silently no-op. The most common casualty is Web Push
 * (minishlink/web-push): push can be ENABLED with the library absent, so
 * browsers subscribe fine but notifications are never delivered (GH #8, found
 * via the Diagnostics page 2026-07-13). This makes the gap visible on the
 * installation health page. Pure: filesystem-only, no DB, no autoloader
 * dependency (is_dir on the package path is the reliable signal even when the
 * composer autoloader hasn't been registered in this request).
 */
function health_check_dependencies(): array
{
    try {
        $root     = health_check_root();
        $autoload = $root . '/vendor/autoload.php';
        $hasVendor = is_file($autoload);

        // composer package → its installed dir → the feature it powers.
        $libs = [
            ['pkg' => 'minishlink/web-push', 'dir' => 'vendor/minishlink/web-push', 'class' => 'Minishlink\\WebPush\\WebPush', 'feature' => 'Web Push notifications'],
            ['pkg' => 'firebase/php-jwt',    'dir' => 'vendor/firebase/php-jwt',    'class' => 'Firebase\\JWT\\JWT',        'feature' => 'External API bearer tokens'],
            ['pkg' => 'cboden/ratchet',      'dir' => 'vendor/cboden/ratchet',      'class' => 'Ratchet\\Server\\IoServer', 'feature' => 'Realtime WebSocket proxy (Zello/DMR)'],
        ];
        $entries = [];
        $missing = 0;
        foreach ($libs as $l) {
            $present = is_dir($root . '/' . $l['dir']) || class_exists($l['class']);
            if (!$present) { $missing++; }
            $entries[] = ['package' => $l['pkg'], 'feature' => $l['feature'], 'present' => $present];
        }
        // Missing vendor/ or any optional lib is a WARN here (features degraded,
        // not a crash). The push-enabled-but-missing → CRITICAL elevation lives
        // in the Notifications settings panel + api/diagnostics.php, which read
        // the push_enabled setting.
        $severity = (!$hasVendor || $missing > 0) ? 'warn' : 'ok';
        return [
            'checked'    => true,
            'has_vendor' => $hasVendor,
            'libraries'  => $entries,
            'missing'    => $missing,
            'severity'   => $severity,
            'remedy'     => $severity === 'ok' ? ''
                : 'Run `composer install --no-dev --optimize-autoloader` in the install directory.',
        ];
    } catch (Throwable $e) {
        return ['checked' => false, 'error' => 'dependency check failed', 'severity' => 'ok', 'libraries' => []];
    }
}

/**
 * ── Web exposure ────────────────────────────────────────────────────────────
 *
 * Ask this install, over HTTP, whether the directories that must never be
 * served actually are.
 *
 * Every other check here reads the local filesystem. None of them could have
 * caught what happened on 2026-07-30: the code and the files were exactly as
 * intended, and `GET /backups/<archive>.zip` still returned a 110 MB database
 * dump to the public internet, because the WEB SERVER had never been told not
 * to. That is not a fact about the install directory; it is a fact about the
 * server config, and the only honest way to learn it is to make the request.
 *
 * Why a self-probe rather than a line in a document: an admin who reads
 * docs/WEB-SERVER-HARDENING.md and follows it correctly still has no way to
 * know a later nginx upgrade, a vhost edit, or a move to a different host did
 * not quietly undo it. This runs on every visit to Settings → System Health.
 *
 * Reporting rules, in order of importance:
 *   - A path that answers 200 is CRITICAL. It is reachable, full stop.
 *   - A path we could not reach at all is 'unknown', never 'ok'. A refused
 *     connection means the probe failed, not that the site is safe — the
 *     server may only be reachable through a tunnel or an external proxy.
 *   - 403 / 404 / 401 are all a pass. Which one an install returns depends on
 *     whether mod_rewrite or mod_alias won, and it does not matter.
 *   - A DIRECTORY that answers 403 proves NOTHING about the files inside it,
 *     so this never asks for one. See below.
 *
 * ── ASKING FOR A DIRECTORY IS NOT A TEST (2026-08-02) ──────────────────────
 *
 * @rjonesbsink, on his own install: `/backups/` answered **403 while the
 * archive inside it answered 200 and served in full** — the complete database
 * export. That is not an exotic configuration. It is what any server with
 * directory browsing turned off and no deny rule on files does, which on
 * Apache is the default posture: `Options -Indexes` alone earns the 403.
 *
 * So a 403 on `backups/` is the single most reassuring and least informative
 * answer a server can give, and it was the fallback this function reached for
 * whenever it could not name an archive — the check an operator runs to
 * confirm they are safe, returning "good" while their database was being
 * served. The published advisory taught the same wrong check.
 *
 * The backups probe therefore asks for a FILE or it does not claim an answer:
 *
 *   1. A real archive, named, from any backup directory this install knows
 *      about that lies inside the served tree (the operator may have pointed
 *      `backup_dir` at one, and there are two historical defaults). Its 200 or
 *      its 403 is about a file, and means what it says.
 *   2. Failing that, the canary — health_check_backup_probe() writes a few
 *      dozen bytes of random hex into the directory and asks for it back,
 *      counting a 200 only when the body is the token. Also a file, also
 *      conclusive, and it never puts an archive URL into a proxy or CDN log.
 *   3. Failing both, the state is 'untested' and says why. NOT 'blocked'.
 *      An install with no archive yet is genuinely untested, not safe, and the
 *      Status row renders grey "Not determined" rather than a green tick.
 *
 * The one determination made without a request: when no backup directory
 * exists inside the served tree at all, nothing can be served from that URL,
 * which is a fact about the filesystem rather than a claim about the server.
 * That is still reported as 'untested' — with the reason, and pointing at the
 * Backup archive location row, which is where the answer for an out-of-tree
 * directory actually lives.
 *
 * Cached for 12 hours in cache/, because this is on a page an admin may
 * refresh repeatedly and each call is three outbound requests.
 *
 * @param bool $force Re-probe even if a fresh cached result exists.
 */
function health_check_web_exposure(bool $force = false): array
{
    try {
        $base = _health_self_base_url();
        if ($base === null) {
            return [
                'checked'  => false,
                'severity' => 'ok',
                'probes'   => [],
                'error'    => 'Cannot work out this install\'s own URL from the '
                            . 'command line. Open Settings → System Health in a browser, '
                            . 'or run the curl checks in docs/WEB-SERVER-HARDENING.md.',
            ];
        }

        $cacheFile = health_check_root() . '/cache/health-web-exposure.json';
        if (!$force && is_file($cacheFile)) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached)
                && ($cached['base'] ?? '') === $base
                && (time() - (int) ($cached['at'] ?? 0)) < 43200) {
                $cached['result']['cached'] = true;
                return $cached['result'];
            }
        }

        // What to ask for. sql/ and tools/ always exist, so a 200 on either is
        // unambiguous — a request for those two IS a request for something that
        // is there. The backups probe is the one that matters most and the one
        // that cannot be built that way; it is assembled separately below.
        $probes = [
            ['path' => 'sql/run_migrations.php', 'label' => 'sql/ (database migration scripts)'],
            ['path' => 'tools/',                 'label' => 'tools/ (maintenance scripts)'],
        ];

        $results  = [];
        $exposed  = 0;
        $unknown  = 0;
        $untested = 0;
        foreach ($probes as $p) {
            $code = _health_probe_head($base . '/' . $p['path']);
            $state = ($code === null) ? 'unknown' : (($code >= 200 && $code < 300) ? 'exposed' : 'blocked');
            if ($state === 'exposed') { $exposed++; }
            if ($state === 'unknown') { $unknown++; }
            $results[] = [
                'path'   => $p['path'],
                'label'  => $p['label'],
                'url'    => $base . '/' . $p['path'],
                'status' => $code,
                'state'  => $state,
                'note'   => '',
            ];
        }

        $bk = _health_backups_probe_result($base, $force);
        $results[] = $bk;
        if ($bk['state'] === 'exposed') { $exposed++; }
        if ($bk['state'] === 'unknown') { $unknown++; }

        // 2026-08-14 (Ron Jones, @rjonesbsink) — .git/ and vendor/, the gap
        // sql/ + tools/ + backups/ never covered: on IIS neither carries a
        // git-shippable web.config (see the long comment in inc/navbar.php,
        // which hardens both at runtime), so a stock IIS install served
        // .git/config, .git/HEAD, .git/objects/*, .git/index (the whole
        // repository and its history, over plain HTTP) and
        // vendor/composer/installed.json (every dependency's exact version,
        // a ready-made CVE-matching list) until this was added.
        foreach (_health_git_vendor_probes($base) as $gv) {
            $results[] = $gv;
            if ($gv['state'] === 'exposed') { $exposed++; }
            if ($gv['state'] === 'unknown') { $unknown++; }
            if ($gv['state'] === 'untested' && ($gv['untested_reason'] ?? '') !== 'absent') {
                $untested++;
            }
        }
        if ($bk['state'] === 'untested' && ($bk['untested_reason'] ?? '') !== 'absent') {
            $untested++;
        }

        $severity = _health_exposure_severity($results);
        $out = [
            'checked'  => true,
            'cached'   => false,
            'base_url' => $base,
            'probes'   => $results,
            'exposed'  => $exposed,
            'unknown'  => $unknown,
            'untested' => $untested,
            'severity' => $severity,
            'summary'  => $exposed > 0
                ? $exposed . ' path' . ($exposed === 1 ? ' is' : 's are')
                    . ' reachable over HTTP that should not be'
                : ($unknown === count($results)
                    ? 'Could not reach this install from itself — check by hand '
                        . '(docs/WEB-SERVER-HARDENING.md)'
                    : ($untested > 0 || $unknown > 0
                        // Never "no directory answered" when one of them was
                        // not asked. That sentence is what an operator reads as
                        // a clean bill of health.
                        ? 'Partly checked only — ' . ($untested + $unknown) . ' of '
                            . count($results) . ' could not be tested'
                        : (($bk['untested_reason'] ?? '') === 'absent'
                            // Says what was actually established rather than
                            // implying the backups path was tested and passed.
                            ? 'sql/ and tools/ are not served; no backups directory '
                                . 'exists inside the served tree'
                            : 'Nothing private answered over HTTP'))),
            // Said out loud, always, including on a clean result. These probes go
            // to ONE address: the one this install answers on. Everything about
            // the 2026-08-02 Windows regression turned on that limit — a database
            // archive was public on port 80 while every probe here, aimed at the
            // application's own port, came back clean. A check that quietly
            // reports "ok" for the part it never looked at is the failure, not
            // the report.
            'blind_spot' => 'Probed ' . $base . ' only. Other web sites on this machine, '
                . 'other ports, and anything published through a reverse proxy are outside '
                . 'what this can see — including directories that hold this install\'s own '
                . 'files. See docs/WEB-SERVER-HARDENING.md to check those by hand.',
            'remedy'   => $exposed > 0
                ? 'Apache: confirm AllowOverride is All or FileInfo so the shipped '
                    . '.htaccess is read. nginx: install '
                    . 'docs/nginx/ticketscad-hardening.conf. IIS: confirm every '
                    . 'directory\'s web.config is present and unmodified — never add '
                    . 'hiddenSegments, it matches any path segment and has previously '
                    . 'taken out assets/vendor/ site-wide. Full instructions in '
                    . 'docs/WEB-SERVER-HARDENING.md. '
                    . 'If backups/ answered 200, treat the database as disclosed — '
                    . 'see docs/security/advisory-2026-07-30-exposed-directories.md.'
                : '',
        ];

        try {
            $cacheDir = dirname($cacheFile);
            if (is_dir($cacheDir) && is_writable($cacheDir)) {
                @file_put_contents($cacheFile, json_encode(
                    ['at' => time(), 'base' => $base, 'result' => $out]
                ));
            }
        } catch (Throwable $e) { /* caching is a nicety, never a requirement */ }

        return $out;
    } catch (Throwable $e) {
        return ['checked' => false, 'severity' => 'ok', 'probes' => [],
                'error' => 'web exposure check failed'];
    }
}

/**
 * Turn a set of probe rows into one verdict.
 *
 * Its own function so the classification can be driven directly with rows the
 * real prober produced, for every combination a single machine cannot get into
 * at once. (A developer box has archives inside the tree; a correctly
 * configured install has none; only one of those is true here today.)
 *
 * The order is the whole policy:
 *   critical  anything answered 200. Nothing else matters beside it.
 *   warn      every probe failed to reach the host — the report is empty.
 *   unknown   at least one probe could not be answered. NOT a pass: the
 *             unanswered one is usually backups/, and a green tick over an
 *             unasked question is the defect @rjonesbsink reported.
 *   ok        every probe was answered and none of them was 200.
 *
 * 'untested' rows marked 'absent' are excluded from that count deliberately:
 * "there is no such directory in the served tree" is a certain answer from the
 * filesystem, not an unanswered question, and it is the ordinary state of a
 * correctly configured install. Colouring that grey for ever is how a row stops
 * being read.
 */
function _health_exposure_severity(array $probes): string
{
    $exposed = 0;
    $unknown = 0;
    $open    = 0;   // could not be answered, and it mattered
    foreach ($probes as $p) {
        $state = (string) ($p['state'] ?? '');
        if ($state === 'exposed') { $exposed++; continue; }
        if ($state === 'unknown') { $unknown++; $open++; continue; }
        if ($state === 'untested' && (string) ($p['untested_reason'] ?? '') !== 'absent') {
            $open++;
        }
    }
    if ($exposed > 0)                                    { return 'critical'; }
    if (!empty($probes) && $unknown === count($probes))  { return 'warn'; }
    if ($open > 0)                                       { return 'unknown'; }
    return 'ok';
}

/**
 * Every backup directory this install knows about that lies INSIDE the served
 * tree, as URL paths relative to the application root, in preference order.
 *
 * Only in-tree directories can appear at `<base>/…` at all, so only they can be
 * tested by this probe. An out-of-tree directory (the v4.2.4 default on every
 * platform) is the other row's job — health_check_backups(), which asks the
 * default ports, where a *different* site's document root answers.
 *
 * Four sources, because 5b88fbb made "the backups directory" a list rather than
 * a constant: the operator's `backup_dir` setting, the current default, and the
 * two historical defaults. backup_dirs_all() resolves the first three; the
 * constants are re-added so this still answers on an install with no database
 * (a fresh clone, or the CLI before config.php exists).
 *
 * @return array<string,string> url path (no leading slash) => absolute dir
 */
function _health_backup_dirs_in_tree(): array
{
    $out = [];
    if (!defined('NEWUI_ROOT')) { return $out; }

    $norm = function (string $p): string {
        $r = @realpath($p);
        return rtrim(str_replace('\\', '/', $r !== false ? $r : $p), '/');
    };
    $root = $norm(NEWUI_ROOT);
    if ($root === '') { return $out; }

    $dirs = [];
    try {
        require_once __DIR__ . '/backup.php';
        require_once __DIR__ . '/backup_schedule.php';
        if (function_exists('backup_dirs_all')) { $dirs = backup_dirs_all(); }
    } catch (Throwable $e) {
        // No database yet. The compiled-in constants below still describe every
        // place this code has ever written an archive.
        $dirs = [];
    }
    foreach ([
        defined('BACKUP_DIR')                ? BACKUP_DIR                : null,
        defined('BACKUP_DIR_LEGACY')         ? BACKUP_DIR_LEGACY         : null,
        defined('BACKUP_DIR_LEGACY_SIBLING') ? BACKUP_DIR_LEGACY_SIBLING : null,
    ] as $d) {
        if ($d !== null) { $dirs[] = $d; }
    }

    foreach ($dirs as $d) {
        if (!is_string($d) || $d === '' || !is_dir($d)) { continue; }
        $n = $norm($d);
        // Strictly BELOW the root: the application root itself is not a backup
        // directory, and mapping it to '' would probe the home page.
        if ($n === $root || strpos($n, $root . '/') !== 0) { continue; }
        $rel = substr($n, strlen($root) + 1);
        if ($rel === '' || isset($out[$rel])) { continue; }
        $out[$rel] = $d;
    }
    return $out;
}

/**
 * The backups row of the web-exposure report — a request for a FILE, or a
 * stated refusal to claim an answer.
 *
 * Never returns 'blocked' on the strength of a directory request. See the
 * 2026-08-02 note on health_check_web_exposure() for why: a 403 on `backups/`
 * is what a server with indexes off and no deny rule returns while it hands out
 * every archive inside.
 *
 * $inTree is a parameter, defaulting to real discovery, for the same reason
 * backup_default_dir_for() takes $windows: every branch has to be assertable
 * from one machine. A developer box has archives in the tree and a fresh
 * install has none, and both states are ordinary — a test that can only see the
 * one its own machine happens to be in is how the directory fallback survived.
 *
 * @param array<string,string>|null $inTree NULL = discover for real.
 * @return array{path:string,label:string,url:?string,status:?int,state:string,note:string}
 */
function _health_backups_probe_result(string $base, bool $force = false, ?array $inTree = null): array
{
    // 'untested_reason' separates two things that both mean "no answer", because
    // treating them alike breaks the check in one direction or the other:
    //
    //   absent       — there is no backup directory inside the served tree, so
    //                  no request to that URL could return one of our archives.
    //                  Certain, and from the filesystem. This is the ordinary
    //                  state of a correctly configured v4.2.4 install, and
    //                  colouring it grey forever would train operators to
    //                  ignore the row — after which it is the silent one.
    //   inconclusive — a directory IS there and we could not ask for anything
    //                  in it. Files may be published and we do not know. That
    //                  escalates.
    $mk = function (string $path, string $label, ?string $url, ?int $status,
                    string $state, string $note, string $why = ''): array {
        return ['path' => $path, 'label' => $label, 'url' => $url,
                'status' => $status, 'state' => $state, 'note' => $note,
                'untested_reason' => $why];
    };

    if ($inTree === null) {
        try {
            $inTree = _health_backup_dirs_in_tree();
        } catch (Throwable $e) { $inTree = []; }
    }

    if (empty($inTree)) {
        // Certain, and from the filesystem rather than from the server: there
        // is no such directory under the served tree, so no request to it could
        // return one of this install's archives. Still not a pass — the archives
        // are somewhere else, and "somewhere else" is the Backup archive
        // location row, which probes the default ports this one never sees.
        return $mk('backups/', 'backups/ (database archives)', null, null, 'untested',
            'No backup directory exists inside the served tree, so there is nothing at '
            . 'that URL to request. Where this install actually keeps its archives is '
            . 'checked in the “Backup archive location” row below.', 'absent');
    }

    // 1. A real archive, named. The only probe whose 403 means what an operator
    //    thinks it means.
    foreach ($inTree as $rel => $dir) {
        $found = [];
        try {
            $found = glob(rtrim($dir, '/\\') . '/ticketscad-*.{zip,gz}', GLOB_BRACE) ?: [];
        } catch (Throwable $e) { $found = []; }
        if (empty($found)) { continue; }

        $seg  = implode('/', array_map('rawurlencode', explode('/', $rel)));
        $path = $seg . '/' . rawurlencode(basename($found[0]));
        $url  = $base . '/' . $path;
        // HEAD, deliberately: the target may be hundreds of megabytes, and the
        // question is whether the server will serve it, not what is in it.
        $code = _health_probe_head($url);
        $state = ($code === null) ? 'unknown'
               : (($code >= 200 && $code < 300) ? 'exposed' : 'blocked');
        return $mk($path, 'backups/ (a real database archive, by name)', $url, $code, $state,
            $state === 'blocked'
                ? 'Asked for an actual archive, not the directory — a 403 on the directory '
                    . 'would have proved nothing about the files in it.'
                : '');
    }

    // 2. No archive to name. The canary: a few dozen bytes of random hex written
    //    into the directory and fetched back, counted only when the body is the
    //    token. Also a file, so also conclusive — and it keeps an archive URL out
    //    of every proxy, cache and analytics log between here and the browser.
    foreach ($inTree as $rel => $dir) {
        $probe = [];
        try {
            $probe = health_check_backup_probe($dir, $force);
        } catch (Throwable $e) { $probe = []; }

        if (!empty($probe['exposed'])) {
            return $mk($rel . '/', 'backups/ (proved with a self-test file)',
                (string) ($probe['url'] ?? ($base . '/' . $rel . '/')), 200, 'exposed',
                'A file written into ' . $dir . ' was served back over HTTP. Any archive '
                . 'placed there is downloadable.');
        }
        if (!empty($probe['checked'])) {
            return $mk($rel . '/', 'backups/ (proved with a self-test file)', null, null, 'blocked',
                'No archive present, so a self-test file was written into ' . $dir
                . ' and requested instead; it was not served. Tried '
                . implode(', ', (array) ($probe['tried'] ?? [])) . '.');
        }

        // 3. Could not place the canary either. Say so, and say why.
        return $mk($rel . '/', 'backups/ (database archives)', null, null, 'untested',
            'Could not test: no archive present in ' . $dir . ' to request, and the '
            . 'self-test file could not be used — '
            . ((string) ($probe['reason'] ?? 'the HTTP self-test could not run')) . '. '
            . 'A 403 on the directory itself would NOT have answered this: servers with '
            . 'directory listing off routinely return 403 for the folder and 200 for the '
            . 'files in it. Check by hand once you have taken a backup — '
            . 'docs/WEB-SERVER-HARDENING.md.', 'inconclusive');
    }

    return $mk('backups/', 'backups/ (database archives)', null, null, 'untested',
        'Could not test the backups directory.', 'inconclusive');
}

/**
 * .git/ and vendor/ — 2026-08-14 (Ron Jones, @rjonesbsink). Neither can carry
 * a git-tracked web.config (.git/ is git's own internal directory, never
 * versioned content; vendor/ is excluded by .gitignore's `/vendor/` directory
 * pattern, and a directory-level ignore blocks re-including anything inside
 * it even by name) — see inc/navbar.php's served_dir_harden() calls, this
 * gap's actual fix. This function only PROBES; it never writes anything
 * (this file's own policy is detect-and-warn, not auto-fix).
 *
 * A known, always-present file within each is asked for by name, exactly
 * like the backups probe's own "ask for a real archive, not the directory"
 * rule — a 403 on .git/ or vendor/ themselves would prove nothing about the
 * files inside.
 *
 * Each returns 'untested'/'absent' when the directory does not exist in this
 * install (a ZIP install has no .git; an install that has never run
 * `composer install` has no vendor) — certain, from the filesystem, and
 * excluded from the severity count the same way an absent backups/ is.
 *
 * @return array[] one or two probe rows (both always present in the array;
 *                  their state is 'untested'/'absent' when the directory
 *                  itself does not exist)
 */
function _health_git_vendor_probes(string $base): array
{
    $mk = function (string $path, string $label, ?string $url, ?int $status,
                    string $state, string $note, string $why = ''): array {
        return ['path' => $path, 'label' => $label, 'url' => $url,
                'status' => $status, 'state' => $state, 'note' => $note,
                'untested_reason' => $why];
    };

    $root = health_check_root();
    $out  = [];

    $gitFile = $root . '/.git/HEAD';
    if (is_file($gitFile)) {
        $url  = $base . '/.git/HEAD';
        $code = _health_probe_head($url);
        $state = ($code === null) ? 'unknown' : (($code >= 200 && $code < 300) ? 'exposed' : 'blocked');
        $out[] = $mk('.git/HEAD', '.git/ (repository metadata and full history)', $url, $code, $state, '');
    } else {
        $out[] = $mk('.git/HEAD', '.git/ (repository metadata and full history)', null, null, 'untested',
            'No .git directory in this install (a ZIP install, or one where .git was '
            . 'later removed), so there is nothing at that URL to request.', 'absent');
    }

    $vendorFile = $root . '/vendor/composer/installed.json';
    if (is_file($vendorFile)) {
        $url  = $base . '/vendor/composer/installed.json';
        $code = _health_probe_head($url);
        $state = ($code === null) ? 'unknown' : (($code >= 200 && $code < 300) ? 'exposed' : 'blocked');
        $out[] = $mk('vendor/composer/installed.json', 'vendor/ (composer dependencies, exact versions)',
            $url, $code, $state, '');
    } else {
        $out[] = $mk('vendor/composer/installed.json', 'vendor/ (composer dependencies, exact versions)',
            null, null, 'untested',
            'No vendor directory in this install (composer install has not been run), '
            . 'so there is nothing at that URL to request.', 'absent');
    }

    return $out;
}

/**
 * Are any database archives sitting in a directory a web server publishes?
 *
 * The half of the exposure story that also works from the CLI. v4.2.3 moved the
 * default backup directory out of the application tree, but an install that has
 * been running for a while still has older archives in the old place — nothing
 * moves them automatically, because the ownership and the free space are the
 * operator's to judge. This is what tells them the job is outstanding, and how
 * many files it is about.
 *
 * ── WHY THIS CHECKS THE DESTINATION, NOT JUST THE SOURCE (2026-08-02) ──────
 *
 * Until now it asked one question — "is this inside OUR tree?" — and answered
 * OK for everything else. @rjonesbsink found what that misses: on Windows,
 * v4.2.3's own "above the web root" default resolved to C:\inetpub\wwwroot,
 * the document root of IIS's Default Web Site on port 80. Archives were moved
 * OUT of this application's tree and INTO somebody else's published one, and
 * every check here went green, because a directory belonging to another site
 * was, quite literally, not this application's problem to look at.
 *
 * It is now. Three sources of evidence, in increasing order of authority:
 *
 *   1. backup_dir_exposure() — local file layout. Certain about our own tree
 *      and about %SystemDrive%\inetpub\wwwroot; a graded suspicion about
 *      anything that looks like a document root; explicit about knowing nothing
 *      otherwise.
 *   2. The HTTP canary — writes a small random token into the directory and
 *      asks this host for it on the default ports. A 200 whose BODY is the
 *      token is proof: only a server publishing that directory could return it.
 *   3. Neither, in which case the answer is "no evidence", the blind spot is
 *      printed in plain words, and nobody is told they are safe.
 *
 * CRITICAL when archives are present somewhere published: one of those files is
 * a complete copy of the database.
 */
function health_check_backups(): array
{
    try {
        if (!defined('NEWUI_ROOT')) {
            return ['checked' => false, 'severity' => 'ok', 'dirs' => []];
        }
        require_once __DIR__ . '/backup.php';

        $dirs = [];
        try {
            require_once __DIR__ . '/backup_schedule.php';
            $dirs = backup_dirs_all();
            $active = backup_dir();
        } catch (Throwable $e) {
            // No database (fresh install, CLI without config) — fall back to the
            // compiled-in paths so the check still says something useful.
            $dirs   = array_values(array_unique(array_filter([
                BACKUP_DIR,
                defined('BACKUP_DIR_LEGACY_SIBLING') ? BACKUP_DIR_LEGACY_SIBLING : null,
                defined('BACKUP_DIR_LEGACY') ? BACKUP_DIR_LEGACY : null,
            ])));
            $active = BACKUP_DIR;
        }

        $norm = function (string $p): string {
            return rtrim(str_replace('\\', '/', $p), '/');
        };

        $entries         = [];
        $exposedArchives = 0;   // archives somewhere we KNOW is published
        $suspectArchives = 0;   // archives somewhere that looks published
        $notes           = [];

        foreach ($dirs as $d) {
            if (!is_dir($d)) { continue; }
            $files = glob(rtrim($d, '/\\') . '/ticketscad-*.{zip,gz}', GLOB_BRACE) ?: [];
            $x     = backup_dir_exposure($d);
            if ($x['served'])                       { $exposedArchives += count($files); }
            elseif ($x['suspect'])                  { $suspectArchives += count($files); }
            $entries[] = [
                'dir'        => $d,
                'active'     => $norm($d) === $norm($active),
                'web_served' => $x['served'],
                'suspect'    => $x['suspect'],
                'state'      => $x['state'],
                'why'        => $x['why'],
                'archives'   => count($files),
            ];
        }

        $activeX = backup_dir_exposure($active);

        // The HTTP canary. Only the ACTIVE directory: it is the one that will
        // keep filling up, the probe costs outbound requests, and a directory
        // being retired is already reported on its file layout alone.
        $probe = health_check_backup_probe($active);
        if (!empty($probe['exposed'])) {
            $activeX['served'] = true;
            $activeX['state']  = 'probe_confirmed';
            $activeX['why']    = 'proved reachable over HTTP at ' . $probe['url'];
        }

        // ── Was this install moved into a published directory BY v4.2.3? ──
        // The specific state @rjonesbsink hit, and the state an operator who
        // followed v4.2.3's own remediation text is now in. It must be named,
        // not merely counted, or it reads as "you did something wrong".
        if (defined('BACKUP_DIR_LEGACY_SIBLING')
            && $norm(BACKUP_DIR_LEGACY_SIBLING) !== $norm(BACKUP_DIR)
            && is_dir(BACKUP_DIR_LEGACY_SIBLING)) {
            $sx = backup_dir_exposure(BACKUP_DIR_LEGACY_SIBLING);
            if ($sx['served'] || $sx['suspect']) {
                $notes[] = 'v4.2.3 wrote backups to ' . BACKUP_DIR_LEGACY_SIBLING
                    . ' and told you to move them there. On this server that '
                    . 'directory is ' . $sx['why'] . '. Anything still in it should be '
                    . 'treated as having been downloadable, and moved to ' . BACKUP_DIR . '.';
            }
        }

        $severity = 'ok';
        if ($exposedArchives > 0 || $activeX['served']) {
            $severity = 'critical';
        } elseif ($suspectArchives > 0 || $activeX['suspect']) {
            $severity = 'warn';
        }

        // Which directory does the operator actually have to empty? The active
        // one when it is itself published; otherwise the worst offender holding
        // archives — an install whose new backups go somewhere safe while old
        // ones sit in a served folder is the common shape, and the instructions
        // have to be about the served folder.
        $offender = $active;
        if (!$activeX['served'] && !$activeX['suspect']) {
            foreach ([true, false] as $wantServed) {
                foreach ($entries as $e) {
                    if ($e['archives'] < 1) { continue; }
                    if ($wantServed ? $e['web_served'] : $e['suspect']) {
                        $offender = $e['dir'];
                        break 2;
                    }
                }
            }
        }

        if ($activeX['served']) {
            $summary = 'Backups are being written to a directory that is PUBLISHED over HTTP ('
                     . $active . ' — ' . $activeX['why'] . ')';
        } elseif ($exposedArchives > 0) {
            $summary = $exposedArchives . ' archive' . ($exposedArchives === 1 ? '' : 's')
                     . ' sitting in a directory that is published over HTTP';
        } elseif ($activeX['suspect']) {
            $summary = 'Backups may be published over HTTP (' . $active . ' — '
                     . $activeX['why'] . ')';
        } elseif ($suspectArchives > 0) {
            $summary = $suspectArchives . ' archive' . ($suspectArchives === 1 ? '' : 's')
                     . ' in a directory that looks like it is published over HTTP';
        } else {
            $summary = 'Backups are written outside every web root this install can see ('
                     . $active . ')';
        }

        // The disclosure. Present whenever the strongest available evidence is
        // still only "we looked and found nothing", which is not the same thing
        // as "it is not reachable" — and saying so is the entire lesson of the
        // regression this check exists to catch.
        $blind = '';
        if (empty($probe['checked'])) {
            $blind = 'Not proved either way: ' . ($probe['reason'] ?? 'the HTTP self-test could not run')
                   . '. This check only probes the address THIS install answers on — a directory '
                   . 'published by another site, another port or a reverse proxy would not be '
                   . 'detected here. Verify by hand: docs/WEB-SERVER-HARDENING.md.';
        } elseif (empty($probe['exposed'])) {
            $blind = 'Confirmed unreachable on ' . implode(', ', $probe['tried'] ?? [])
                   . ' only. Other hostnames, other ports and other web sites on this machine '
                   . 'are outside what this check can see.';
        }

        return [
            'checked'          => true,
            'active_dir'       => $active,
            'active_web_served'=> $activeX['served'],
            'active_suspect'   => $activeX['suspect'],
            'active_state'     => $activeX['state'],
            'active_why'       => $activeX['why'],
            'default_dir'      => BACKUP_DIR,
            'legacy_dir'       => defined('BACKUP_DIR_LEGACY') ? BACKUP_DIR_LEGACY : null,
            'legacy_dirs'      => function_exists('backup_legacy_dirs') ? backup_legacy_dirs() : [],
            'dirs'             => $entries,
            'exposed_archives' => $exposedArchives,
            'suspect_archives' => $suspectArchives,
            'probe'            => $probe,
            'blind_spot'       => $blind,
            'notes'            => $notes,
            'severity'         => $severity,
            'summary'          => $summary,
            // The directory the reader has to move files OUT of, which is not
            // necessarily the active one: the usual case is an active directory
            // that is fine and older archives left behind somewhere published.
            // Naming the destination as the source produced
            // `Move-Item -Path <target>\ticketscad-* -Destination <target>\`,
            // an instruction that moves a directory onto itself.
            'offender_dir'     => $offender,
            'remedy'           => $severity === 'ok' ? '' : health_backup_move_remedy($offender),
        ];
    } catch (Throwable $e) {
        return ['checked' => false, 'severity' => 'ok', 'dirs' => [],
                'error' => 'backup location check failed'];
    }
}

/**
 * Where are the encryption keys, and is anything publishing that directory?
 *
 * GHSA-3jmh-c6f6-64jc, 2026-08-03. FE_KEYS_DIR was `NEWUI_ROOT . '/../keys'`
 * for the same reason BACKUP_DIR was `dirname(NEWUI_ROOT)`, and it is wrong on
 * Windows in the same way: an IIS site at C:\inetpub\wwwroot\<app> puts the
 * keys in C:\inetpub\wwwroot\keys, inside Default Web Site, bound to port 80.
 * @rjonesbsink fetched a control file out of it over HTTP.
 *
 * The asset here is worse than a backup archive in one specific respect: the
 * private key is not a snapshot of data at a point in time, it is the thing
 * that decrypts data going forward, and rotating it is not free — a new tfa.key
 * un-enrols every 2FA user. So this row is CRITICAL when the directory is
 * certainly published, whether or not a key file is sitting in it yet: if the
 * directory is served, the next key generated lands there.
 *
 * Nothing is moved. A half-completed key move loses 2FA for everyone, so this
 * reports and instructs; the operator moves.
 */
function health_check_keys(): array
{
    try {
        if (!defined('NEWUI_ROOT')) {
            return ['checked' => false, 'severity' => 'ok'];
        }
        require_once __DIR__ . '/field-encrypt.php';

        $norm = function (string $p): string {
            return rtrim(str_replace('\\', '/', $p), '/');
        };
        $active   = FE_KEYS_DIR;
        $activeX  = served_dir_exposure($active);
        $present  = [];
        foreach (['private.pem', 'public.pem', 'tfa.key'] as $f) {
            if (@is_file(rtrim($active, '/\\') . '/' . $f)) { $present[] = $f; }
        }

        // The canary. Only the directory actually in use: it is the one that
        // will hold the keys, and the probe costs outbound requests.
        $probe = health_check_dir_probe($active, 'keys', 'keys directory');
        if (!empty($probe['exposed'])) {
            $activeX['served'] = true;
            $activeX['state']  = 'probe_confirmed';
            $activeX['why']    = 'proved reachable over HTTP at ' . $probe['url'];
        }

        // Key material left behind in a location this install no longer uses.
        // Not hypothetical: an operator who moves their keys to the safe
        // directory and leaves copies behind has still left a private key in a
        // published folder, and the resolver deliberately keeps USING the old
        // one until it is empty — so this note is how they learn the move is
        // not finished.
        $notes  = [];
        $others = [];
        foreach ([FE_KEYS_DIR_LEGACY, FE_KEYS_DIR_DEFAULT] as $d) {
            if ($norm($d) === $norm($active)) { continue; }
            if (!fe_dir_holds_keys($d))       { continue; }
            $ox = served_dir_exposure($d);
            $others[] = ['dir' => $d, 'served' => $ox['served'], 'suspect' => $ox['suspect'],
                         'state' => $ox['state'], 'why' => $ox['why']];
            if ($ox['served'] || $ox['suspect']) {
                $notes[] = 'Key files are also present in ' . $d . ', which is ' . $ox['why']
                    . '. A private key there is readable by anyone who can reach that site — '
                    . 'move those files out (or delete them once you have verified the copies '
                    . 'in ' . $active . ' work).';
            }
        }

        $severity = 'ok';
        if ($activeX['served']) {
            $severity = 'critical';
        } elseif ($activeX['suspect']) {
            $severity = 'warn';
        }
        foreach ($others as $o) {
            if ($o['served'] && $severity !== 'critical') { $severity = 'critical'; }
            elseif ($o['suspect'] && $severity === 'ok')  { $severity = 'warn'; }
        }

        if ($activeX['served']) {
            $summary = 'The encryption keys are in a directory that is PUBLISHED over HTTP ('
                     . $active . ' — ' . $activeX['why'] . ')';
        } elseif ($activeX['suspect']) {
            $summary = 'The encryption keys may be published over HTTP (' . $active . ' — '
                     . $activeX['why'] . ')';
        } elseif ($severity !== 'ok') {
            $summary = 'Key files are left in a directory that looks published';
        } else {
            $summary = 'Keys are outside every web root this install can see (' . $active . ')';
        }

        $blind = '';
        if (empty($probe['checked'])) {
            $blind = 'Not proved either way: ' . ($probe['reason'] ?? 'the HTTP self-test could not run')
                   . '. This check only probes the address THIS install answers on — a directory '
                   . 'published by another site, another port or a reverse proxy would not be '
                   . 'detected here. Verify by hand: docs/WEB-SERVER-HARDENING.md.';
        } elseif (empty($probe['exposed'])) {
            $blind = 'Confirmed unreachable on ' . implode(', ', $probe['tried'] ?? [])
                   . ' only. Other hostnames, other ports and other web sites on this machine '
                   . 'are outside what this check can see.';
        }

        return [
            'checked'      => true,
            'active_dir'   => $active,
            'default_dir'  => FE_KEYS_DIR_DEFAULT,
            'legacy_dir'   => FE_KEYS_DIR_LEGACY,
            'in_legacy'    => $norm($active) === $norm(FE_KEYS_DIR_LEGACY)
                              && $norm(FE_KEYS_DIR_LEGACY) !== $norm(FE_KEYS_DIR_DEFAULT),
            'key_files'    => $present,
            'web_served'   => $activeX['served'],
            'suspect'      => $activeX['suspect'],
            'state'        => $activeX['state'],
            'why'          => $activeX['why'],
            'other_dirs'   => $others,
            'probe'        => $probe,
            'notes'        => $notes,
            'blind_spot'   => $blind,
            'severity'     => $severity,
            'summary'      => $summary,
            'remedy'       => $severity === 'ok' ? '' : health_keys_move_remedy($active),
        ];
    } catch (Throwable $e) {
        return ['checked' => false, 'severity' => 'ok',
                'error' => 'key location check failed'];
    }
}

/**
 * "Move your keys somewhere no site serves", in commands for the machine the
 * reader is sitting at.
 *
 * Different in one important way from the backup version: there is no in-app
 * setting for this. FE_KEYS_DIR is a define() read before any database is
 * available, so the override lives in config.php — and on Windows the operator
 * usually needs no override at all, because the destination we name IS the new
 * default and the resolver picks it up as soon as the old directory no longer
 * holds keys.
 *
 * The order of operations is stated the safe way round — copy, verify, then
 * delete — because half a key move locks every 2FA user out of the system.
 */
function health_keys_move_remedy(string $active, ?bool $windows = null): string
{
    if ($windows === null) {
        $windows = (DIRECTORY_SEPARATOR === '\\');
    }
    $n       = function (string $p): string { return rtrim(str_replace('\\', '/', $p), '/'); };
    $default = fe_default_keys_dir_for(NEWUI_ROOT, $windows);

    // Is the flagged directory the platform default itself? Then moving out of
    // it cannot be picked up automatically and config.php must name the new
    // location. Otherwise the default IS the destination, and emptying the old
    // directory is the whole procedure — the resolver switches on its own.
    $defaultIsTheProblem = ($n($default) === $n($active));
    $dest = $defaultIsTheProblem
        ? ($windows ? 'C:\\TicketsCAD\\keys' : '/var/lib/ticketscad/keys')
        : $default;

    $lead = "Move the key files to a directory no web site publishes. Do it when nobody is "
          . "signing in, and keep the originals until you have confirmed a 2FA login still "
          . "works: tfa.key decrypts every enrolled authenticator, and there is no way back "
          . "from losing it.\n\n";

    $tail = $defaultIsTheProblem
        ? "\nThen add this line anywhere in config.php — it is read before the keys are "
        . "opened, and it overrides the built-in default:\n"
        . "  define('FE_KEYS_DIR', '"
        . ($windows ? str_replace('\\', '\\\\', $dest) : $dest) . "');\n"
        : "\nNo config change is needed: " . $dest . " is this version's default, and "
        . "TicketsCAD uses it as soon as the old directory no longer holds key files. "
        . "(To keep them somewhere else instead, add define('FE_KEYS_DIR', '…'); to "
        . "config.php.)\n";

    $close = "\nIf that directory was reachable over HTTP, treat the private key as disclosed — "
           . 'see docs/security/advisory-2026-08-03-fe-keys-dir.md.';

    if ($windows) {
        $w  = function (string $p): string { return str_replace('/', '\\', $p); };
        $me = health_os_account($windows);
        return $lead
            . "PowerShell, as Administrator:\n"
            . "  New-Item -ItemType Directory -Force -Path '" . $w($dest) . "'\n"
            . "  icacls '" . $w($dest) . "' /grant '" . $me . ":(OI)(CI)M'\n"
            . "  Copy-Item -Path '" . $w($active) . "\\*.pem','" . $w($active) . "\\tfa.key' "
            . "-Destination '" . $w($dest) . "\\' -ErrorAction SilentlyContinue\n"
            . "  # sign in with 2FA to confirm it still works, THEN:\n"
            . "  Remove-Item -Path '" . $w($active) . "\\*.pem','" . $w($active) . "\\tfa.key'\n"
            . $tail
            . "\nDo NOT use C:\\inetpub\\wwwroot or any directory beneath it — that is the "
            . "physical path of IIS's Default Web Site and it is bound to port 80, so anything "
            . "in it is public even though TicketsCAD answers on a different port.\n"
            . $close;
    }

    return $lead
        . "  sudo mkdir -p " . $dest . "\n"
        . "  sudo cp -p " . $active . "/*.pem " . $active . "/tfa.key " . $dest . "/\n"
        . "  sudo chown -R www-data:www-data " . $dest . " && sudo chmod 700 " . $dest . "\n"
        . "  # sign in with 2FA to confirm it still works, THEN:\n"
        . "  sudo rm -f " . $active . "/*.pem " . $active . "/tfa.key\n"
        . $tail
        . $close;
}

/**
 * The "move your archives somewhere safe" instructions, in commands the reader
 * can actually paste on the machine they are sitting at.
 *
 * v4.2.3 printed `mkdir -p`, `mv` and `sudo chown … www-data` unconditionally.
 * On Windows that rendered as `mkdir -p C:\inetpub\wwwroot/backups` — POSIX
 * verbs, a group that does not exist, and mixed path separators. An IIS
 * administrator could not run a line of it, and following it by hand is exactly
 * what moved a database archive into the port-80 site root.
 *
 * The first paragraph is deliberately platform-neutral and comes first, because
 * it is the whole fix: the `backup_dir` setting overrides every default in this
 * file, and setting it needs no shell at all.
 *
 * $windows is a parameter rather than a bare DIRECTORY_SEPARATOR read so that
 * both branches can be asserted from one machine. A test that can only see the
 * text its own platform produces is exactly how POSIX-only instructions shipped
 * to IIS administrators in the first place.
 */
function health_backup_move_remedy(string $active, ?bool $windows = null): string
{
    if ($windows === null) {
        $windows = (DIRECTORY_SEPARATOR === '\\');
    }
    $target  = backup_default_dir_for(NEWUI_ROOT, $windows);
    $from    = $active;

    $lead = "Set “Backup folder” in Settings → Backup / Maintenance to a directory outside every web "
          . "site root. That alone fixes it, on any server, with no shell.\n"
          . 'Suggested: ' . $target . "\n\n"
          . "Then move what is already there:\n";

    if ($windows) {
        // Mixed separators were half the reason the old text was unusable:
        // BACKUP_DIR_LEGACY is built with '/', so it rendered as
        // `C:\inetpub\wwwroot\TicketsV4/backups`. Normalise everything shown.
        $w  = function (string $p): string { return str_replace('/', '\\', $p); };
        $me = health_os_account($windows);
        return $lead
            . "\nPowerShell, as Administrator:\n"
            . "  New-Item -ItemType Directory -Force -Path '" . $w($target) . "'\n"
            . "  Move-Item -Path '" . $w($from) . "\\ticketscad-*' -Destination '" . $w($target) . "\\'\n"
            . "  icacls '" . $w($target) . "' /grant '" . $me . ":(OI)(CI)M'\n"
            . "  Get-ChildItem '" . $w($from) . "'    # should list no ticketscad-* archives\n"
            . "\nDo NOT use C:\\inetpub\\wwwroot — that is the physical path of IIS's "
            . "Default Web Site and it is bound to port 80, so anything in it is public "
            . "even though TicketsCAD answers on a different port. C:\\inetpub\\backups is "
            . "safe if you would rather keep it on the same volume as the site.\n"
            . "\nIf that directory was reachable over HTTP, treat the database as disclosed — "
            . 'see docs/security/advisory-2026-07-30-exposed-directories.md.';
    }

    return $lead
        . "  mkdir -p " . $target . "\n"
        . "  mv " . $from . "/ticketscad-* " . $target . "/\n"
        . "  sudo chown -R \"\$(id -un)\":www-data " . $target . ' && sudo chmod 2770 ' . $target . "\n"
        . "  ls " . $from . "    # should list no ticketscad-* archives\n"
        . "\nIf that directory was reachable over HTTP, treat the database as disclosed — "
        . 'see docs/security/advisory-2026-07-30-exposed-directories.md.';
}

/**
 * The account this PHP process runs as, for an icacls/chown line the reader can
 * paste. Best effort — a placeholder is better than a wrong name.
 */
function health_os_account(?bool $windows = null): string
{
    if ($windows === null) {
        $windows = (DIRECTORY_SEPARATOR === '\\');
    }
    try {
        if ($windows) {
            $u = getenv('USERNAME');
            $d = getenv('USERDOMAIN');
            if ($u !== false && trim((string) $u) !== '') {
                return (($d !== false && trim((string) $d) !== '') ? $d . '\\' : '') . $u;
            }
            return 'IIS AppPool\\<your application pool>';
        }
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && !empty($pw['name'])) { return (string) $pw['name']; }
        }
        $u = getenv('USER');
        return ($u !== false && $u !== '') ? (string) $u : 'www-data';
    } catch (Throwable $e) {
        return $windows ? 'IIS AppPool\\<your application pool>' : 'www-data';
    }
}

/**
 * Prove — or fail to prove — that a directory is reachable over HTTP, by
 * putting a file in it and asking for that file back.
 *
 * ── WHY A CANARY AND NOT A CONFIG READ ────────────────────────────────────
 *
 * Reading `appcmd list site` would say what IIS is configured to publish. It
 * needs a shell (this project gates against shelling out), it does not exist on
 * Apache or nginx, it is not readable by the application pool identity, and it
 * still would not settle the question — a reverse proxy in front can publish a
 * path no local config mentions. Asking the server for the file settles it.
 *
 * ── WHY THE TOKEN IS CHECKED, AND WHY NOT AN ARCHIVE ──────────────────────
 *
 * A bare 200 is not evidence: plenty of sites answer 200 for everything. The
 * body must contain a token this process generated a moment ago, which only a
 * server publishing THIS directory can return — so there are no false alarms,
 * and a positive result is worth acting on.
 *
 * The probe never requests a real archive. An archive URL in a proxy log, a
 * cache or an upstream analytics pipeline is a disclosure in its own right, and
 * the point here is to test the DIRECTORY, not to fetch anything valuable. The
 * canary is a few dozen bytes of random hex and is deleted afterwards.
 *
 * Cached for 12 hours: the Status page can be refreshed repeatedly and this
 * costs outbound requests.
 *
 * @return array{checked:bool,exposed:bool,url:?string,tried:string[],reason:?string}
 */
function health_check_backup_probe(string $dir, bool $force = false): array
{
    return health_check_dir_probe($dir, 'backup', 'backup directory', $force);
}

/**
 * The canary probe itself, for any directory that is supposed to be private.
 *
 * Generalised 2026-08-03: the encryption-key directory (GHSA-3jmh-c6f6-64jc)
 * needs exactly this question asked of it, and the reporter's own evidence was
 * this experiment run by hand — a control file placed in the directory and
 * fetched over HTTP. Writing a second copy of it would be the third time this
 * assumption was implemented independently.
 *
 * @param string $dir    Directory to test.
 * @param string $slug   Short name, used for the per-directory probe cache file.
 * @param string $label  How to name the directory in a reason string.
 */
function health_check_dir_probe(string $dir, string $slug, string $label, bool $force = false): array
{
    $miss = function (string $why): array {
        return ['checked' => false, 'exposed' => false, 'url' => null,
                'tried' => [], 'reason' => $why];
    };
    $slug = preg_replace('/[^a-z0-9_-]/i', '', $slug);
    if ($slug === '') { $slug = 'dir'; }

    try {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        if ($host === '') {
            return $miss('there is no web request to work out this server\'s address from '
                       . '(run it from Settings → System Health in a browser)');
        }
        // Strip the port: the whole point is to try the DEFAULT ports, which is
        // where another site's document root answers.
        $hostOnly = preg_replace('/:\d+$/', '', (string) $host);
        if (!is_string($hostOnly) || $hostOnly === '') {
            return $miss('could not parse this server\'s host name');
        }
        if (!is_dir($dir))      { return $miss('the ' . $label . ' does not exist yet'); }
        if (!is_writable($dir)) { return $miss('the ' . $label . ' is not writable by the web server, '
                                             . 'so the self-test file could not be placed in it'); }

        $cacheFile = health_check_root() . '/cache/health-' . $slug . '-probe.json';
        $key       = $hostOnly . '|' . rtrim(str_replace('\\', '/', $dir), '/');
        if (!$force && is_file($cacheFile)) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached) && ($cached['key'] ?? '') === $key
                && (time() - (int) ($cached['at'] ?? 0)) < 43200) {
                return $cached['result'];
            }
        }

        $token = bin2hex(random_bytes(16));
        // No leading dot (nginx and IIS commonly deny dotfiles outright, which
        // would read as "blocked" and hide a real exposure) and no
        // `ticketscad-` prefix (that is the archive glob).
        $name  = 'tcad-selftest-' . substr($token, 0, 12) . '.txt';
        $path  = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
        if (@file_put_contents($path, "TicketsCAD exposure self-test " . $token . "\n") === false) {
            return $miss('could not write the self-test file into the ' . $label);
        }

        $result = ['checked' => true, 'exposed' => false, 'url' => null,
                   'tried' => [], 'reason' => null];
        try {
            $seg  = rawurlencode(basename(rtrim(str_replace('\\', '/', $dir), '/')));
            $urls = [
                'http://'  . $hostOnly . '/' . $seg . '/' . $name, // NOSONAR S5332: this IS the check — it probes plain HTTP deliberately, to detect exactly the exposure this function exists to catch
                'https://' . $hostOnly . '/' . $seg . '/' . $name,
            ];
            // …and the path this application's OWN site would map the directory
            // to, when the backup directory is a sibling of the install: the
            // URL prefix one level up from the app's.
            $base = _health_self_base_url();
            if ($base !== null && preg_match('#^(https?://[^/]+)(/.*)?$#', $base, $m) === 1) {
                $prefix = rtrim((string) ($m[2] ?? ''), '/');
                $parent = ($prefix === '') ? '' : rtrim(str_replace('\\', '/', dirname($prefix)), '/');
                if ($parent === '.' || $parent === '/') { $parent = ''; }
                $urls[] = $m[1] . $parent . '/' . $seg . '/' . $name;
                // …and the application's own prefix, which is where an IN-TREE
                // backup directory lives. Without this line the canary could not
                // answer the case @rjonesbsink reported at all on a subdirectory
                // install (https://host/newui/backups/…): it asked the host root
                // and the parent, neither of which is where the file is.
                if ($prefix !== '') {
                    $urls[] = $m[1] . $prefix . '/' . $seg . '/' . $name;
                }
            }
            $urls = array_values(array_unique($urls));

            foreach ($urls as $u) {
                $result['tried'][] = $u;
                $body = _health_probe_body($u);
                if ($body !== null && strpos($body, $token) !== false) {
                    $result['exposed'] = true;
                    $result['url']     = $u;
                    break;
                }
            }
        } finally {
            @unlink($path);
        }

        try {
            $cacheDir = dirname($cacheFile);
            if (is_dir($cacheDir) && is_writable($cacheDir)) {
                @file_put_contents($cacheFile, json_encode(
                    ['at' => time(), 'key' => $key, 'result' => $result]
                ));
            }
        } catch (Throwable $e) { /* caching is a nicety, never a requirement */ }

        return $result;
    } catch (Throwable $e) {
        return $miss('the HTTP self-test could not be completed');
    }
}

/**
 * GET a URL and return at most the first $maxBytes of the body, or null when
 * the request could not be made. Bounded because a wrong guess may land on a
 * full HTML page — the default (8 KB) is sized for the backups canary, which
 * is only ever looking for a 40-character token. Phase 138's public-board
 * health check passes a larger cap (still bounded, never unlimited) because
 * its target is a real JSON array of incidents, not a token.
 */
function _health_probe_body(string $url, int $maxBytes = 8192): ?string
{
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) { return null; }
            $buf = '';
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => 'TicketsCAD-health-check',
                CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$buf, $maxBytes) {
                    $buf .= $chunk;
                    if (strlen($buf) > $maxBytes) { return -1; }   // abort the transfer
                    return strlen($chunk);
                },
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($code < 200 || $code >= 300) { return null; }
            return $buf;
        }

        $ctx = stream_context_create(['http' => [
            'method'          => 'GET',
            'timeout'         => 5,
            'follow_location' => 0,
            'ignore_errors'   => true,
            'header'          => "User-Agent: TicketsCAD-health-check\r\n",
        ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $body = @file_get_contents($url, false, $ctx, 0, $maxBytes);
        if ($body === false) { return null; }
        // $http_response_header is populated by the stream wrapper.
        $status = 0;
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) { $status = (int) $m[1]; }
        }
        return ($status >= 200 && $status < 300) ? $body : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * This install's own base URL, without a trailing slash, or null when it
 * cannot be determined (typically the CLI, where there is no request to read
 * it from).
 */
function _health_self_base_url(): ?string
{
    try {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        if ($host === '') {
            $env = getenv('NEWUI_BASE_URL');
            return ($env !== false && $env !== '') ? rtrim($env, '/') : null;
        }
        // Honour the reverse proxy: the origin may speak plain HTTP while the
        // visitor is on HTTPS. Probing the wrong scheme gets a redirect, which
        // reads as "blocked" and would hide a real exposure.
        $scheme = is_https() ? 'https' : 'http';

        // Application prefix: /newui for a subdirectory install, '' at a vhost root.
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
        // API endpoints live one level down; step back up to the app root.
        if (substr($dir, -4) === '/api') { $dir = substr($dir, 0, -4); }
        if ($dir === '.' || $dir === '/') { $dir = ''; }

        return $scheme . '://' . $host . $dir;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * HEAD a URL and return the status code, or null when the request could not be
 * made at all. HEAD deliberately, not GET: one of the probe targets may be a
 * multi-hundred-megabyte database archive.
 */
function _health_probe_head(string $url): ?int
{
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) { return null; }
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,   // a redirect is not a pass
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_SSL_VERIFYPEER => false,   // self-signed / internal names
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => 'TicketsCAD-health-check',
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return $code > 0 ? $code : null;
        }

        $ctx = stream_context_create(['http' => [
            'method'          => 'HEAD',
            'timeout'         => 5,
            'follow_location' => 0,
            'ignore_errors'   => true,
            'header'          => "User-Agent: TicketsCAD-health-check\r\n",
        ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $headers = @get_headers($url, false, $ctx);
        if (!is_array($headers) || empty($headers[0])) { return null; }
        if (preg_match('#\s(\d{3})\s#', ' ' . $headers[0] . ' ', $m)) {
            return (int) $m[1];
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Does the database have the columns this version of the code writes to?
 *
 * Phase 125 (2026-07-26). Every other check here is about FILES. None of them
 * could see the failure that actually cost a beta tester his week: a database
 * whose structure had fallen behind the code, so saving a team returned an
 * unexplained HTTP 400. The migration runner could not see it either — its
 * tracker records whether a script RAN, not whether its schema still exists.
 *
 * CRITICAL rather than warn: a missing column is not degraded, it is a screen
 * that cannot save.
 */
function health_check_schema(): array
{
    try {
        require_once __DIR__ . '/schema-verify.php';
        $v = schema_verify();

        if (!$v['available']) {
            // Cannot verify (no manifest, unreadable information_schema).
            // Report it, but never as a failure of the user's install.
            return [
                'checked'  => false,
                'error'    => $v['error'] ?? 'schema could not be verified',
                'severity' => 'ok',
                'summary'  => schema_verify_summary($v),
            ];
        }

        return [
            'checked'              => true,
            'ok'                   => $v['ok'],
            'checked_tables'       => $v['checked_tables'],
            'checked_columns'      => $v['checked_columns'],
            'missing_tables'       => $v['missing_tables'],
            'missing_columns'      => $v['missing_columns'],
            'missing_column_count' => $v['missing_column_count'],
            'severity'             => $v['ok'] ? 'ok' : 'critical',
            'summary'              => schema_verify_summary($v),
            'remedy'               => $v['ok'] ? '' : schema_verify_repair_hint(),
        ];
    } catch (Throwable $e) {
        return ['checked' => false, 'error' => 'schema check failed', 'severity' => 'ok'];
    }
}

/**
 * Are the background jobs actually being run by anything?
 *
 * Added 2026-07-29, after two scheduled ticks were found to have never
 * executed in the seven weeks since they were installed. They had been
 * dropped into /etc/cron.d on hosts with no cron daemon, which fails
 * silently, and no surface anywhere reported a job's last run — so there
 * was no observation that could have distinguished "running fine" from
 * "never started". This is that observation.
 *
 * Delegates to sched_jobs_status(); shaped like the other sections.
 */
/**
 * GH#76 Phase 144 (2026-08-18) — team membership reconciliation.
 *
 * Reports how many legacy member.team_id assignments have been
 * reconciled into team_members (the sole source of truth for team
 * assignment as of this release), and names any member whose team_id
 * still lacks a matching team_members row — which should never happen
 * once sql/run_phase144_team_membership_unification.php has run, but
 * this makes the "nothing was silently dropped" guarantee verifiable
 * in-app rather than only asserted in a commit message. Mirrors
 * health_check_public_board()'s "report a fact, don't infer a fault"
 * shape — an install with zero legacy team_id values (the common case;
 * see the design spec's live-data findings) reports 'ok' with 0
 * reconciled, not a warning.
 */
function health_check_team_membership_reconciliation(): array
{
    try {
        if (!function_exists('db_fetch_all') || !function_exists('db_table')) {
            return ['checked' => false, 'severity' => 'ok', 'error' => 'database not available in this context'];
        }

        $members = [];
        try {
            $members = db_fetch_all(
                "SELECT m.id, m.team_id, m.first_name, m.last_name
                 FROM " . db_table('member') . " m
                 WHERE m.team_id IS NOT NULL AND m.team_id > 0"
            );
        } catch (Throwable $e) {
            return ['checked' => false, 'severity' => 'ok', 'error' => 'member/team_members not queryable'];
        }

        $reconciled = 0;
        $orphaned   = [];   // team_id -> a team that no longer exists (expected, informational)
        $unresolved = [];   // still no matching row (should never happen post-migration)

        foreach ($members as $m) {
            $mid  = (int) $m['id'];
            $tid  = (int) $m['team_id'];
            $name = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));

            $teamExists = false;
            try {
                $teamExists = (bool) db_fetch_value("SELECT id FROM " . db_table('teams') . " WHERE id = ?", [$tid]);
            } catch (Throwable $e) {}
            if (!$teamExists) {
                $orphaned[] = "#{$mid} {$name} -> team #{$tid} (team no longer exists)";
                continue;
            }

            $matching = false;
            try {
                $matching = (bool) db_fetch_value(
                    "SELECT id FROM " . db_table('team_members') . " WHERE team_id = ? AND member_id = ?",
                    [$tid, $mid]
                );
            } catch (Throwable $e) {}
            if ($matching) {
                $reconciled++;
            } else {
                $unresolved[] = "#{$mid} {$name} -> team #{$tid}";
            }
        }

        $severity = empty($unresolved) ? 'ok' : 'critical';

        return [
            'checked'             => true,
            'severity'            => $severity,
            'total_with_team_id'  => count($members),
            'reconciled'          => $reconciled,
            'orphaned'            => $orphaned,
            'unresolved'          => $unresolved,
            'summary'             => $reconciled . ' legacy team assignment(s) reconciled into Team Memberships'
                . (!empty($unresolved) ? '; ' . count($unresolved) . ' UNRESOLVED (run php sql/run_phase144_team_membership_unification.php)' : '')
                . (!empty($orphaned) ? '; ' . count($orphaned) . ' orphaned (team since deleted)' : ''),
            'remedy'              => empty($unresolved) ? '' : 'Run: php sql/run_phase144_team_membership_unification.php',
        ];
    } catch (Throwable $e) {
        return ['checked' => false, 'error' => 'team membership reconciliation check failed', 'severity' => 'ok'];
    }
}

function health_check_scheduled_jobs(): array
{
    try {
        require_once __DIR__ . '/scheduled-jobs.php';
        return sched_jobs_status();
    } catch (Throwable $e) {
        return ['checked' => false, 'error' => 'scheduled job check failed',
                'jobs' => [], 'severity' => 'ok'];
    }
}

/**
 * Address lookup — is it configured coherently, and is it actually working?
 *
 * WHY THIS EXISTS, AND WHY IT DOES NOT PROBE THE NETWORK BY DEFAULT.
 *
 * Geocoding moved from the dispatcher's browser to this server on 2026-07-31
 * (see inc/geocode.php). That is the right place for it — it is the only place
 * that can cache, throttle, identify itself, keep an API key off a browser, or
 * reach a geocoder on your own network. But it moves the dependency: an
 * install where the BROWSERS have internet and the PHP process does not now
 * has no address lookup, and the usual cause is invisible. On Rocky/RHEL,
 * SELinux ships `httpd_can_network_connect` OFF, so a stock install cannot
 * make outbound HTTP from PHP at all and says nothing about it.
 *
 * The obvious answer — fire a test lookup whenever someone opens the Status
 * page — is the wrong one, twice over. It puts an outbound call with a
 * multi-second worst case on a page load, and this project has already shipped
 * a check that reported every fresh install as critically broken because it
 * inferred a fault from configuration rather than from evidence (commit
 * aed9d41). A check that cries wolf gets muted, and then it is the silent one.
 *
 * So this reports EVIDENCE:
 *   * configuration that cannot possibly work (a keyed provider with no key,
 *     a self-hosted one with no address) — a fact, not a guess;
 *   * the circuit breaker, which records real failures from real lookups. If
 *     PHP cannot reach the provider, three genuine dispatcher lookups open it
 *     and this turns critical, naming the transport error.
 * and leaves the on-demand probe to the Test button in Settings, where an
 * administrator has asked for it.
 *
 * @param bool $probe true = actually perform a live lookup (Settings' Test
 *                    button path). Off by default: see above.
 */
function health_check_geocoding(bool $probe = false): array
{
    try {
        if (!function_exists('geocode_settings')) {
            $inc = __DIR__ . '/geocode.php';
            if (!is_file($inc)) {
                return ['checked' => false, 'severity' => 'ok',
                        'error' => 'geocoding support is not installed'];
            }
            require_once $inc;
        }
        if (!function_exists('get_variable')) {
            return ['checked' => false, 'severity' => 'ok',
                    'error' => 'settings are not available in this context'];
        }

        $settings = geocode_settings();
        $cfg      = geocode_client_config($settings);
        $policy   = geocode_policy()[$settings['provider']] ?? [];

        $severity = 'ok';
        $notes    = [];

        if ($cfg['mode'] === 'off') {
            // A deliberate choice on an air-gapped install. Report it, do not
            // grade it — reporting a chosen configuration as a fault is how a
            // health page teaches people to ignore it.
            $notes[] = 'Address lookup is switched off. Dispatchers set incident locations by '
                     . 'clicking the map.';
        } else {
            if (!empty($policy['needs_key']) && trim((string) $settings['api_key']) === '') {
                $severity = 'critical';
                $notes[] = ($policy['label'] ?? $settings['provider']) . ' needs an API key and none '
                         . 'is saved. Every address lookup will fail until one is set in '
                         . 'Settings → API Keys.';
            }
            if (!empty($policy['needs_url']) && geocode_base_url((string) $settings['url']) === '') {
                $severity = 'critical';
                $notes[] = ($policy['label'] ?? $settings['provider']) . ' needs the address of your '
                         . 'own geocoding server and none is saved. Every address lookup will fail '
                         . 'until one is set in Settings → API Keys → Geocoding.';
            }
            if ($cfg['requested'] !== $cfg['mode'] && $cfg['reason'] !== '') {
                $notes[] = 'Configured as "' . $cfg['requested'] . '", running as "' . $cfg['mode']
                         . '": ' . $cfg['reason'];
            }
            if ($cfg['mode'] === 'server' && !function_exists('curl_init')) {
                if ($severity === 'ok') { $severity = 'warn'; }
                $notes[] = 'The PHP cURL extension is not installed, so lookups fall back to the '
                         . 'stream wrapper, which cannot enforce a separate connect timeout. '
                         . 'Installing php-curl makes failures faster and more predictable.';
            }
        }

        // The evidence half: what real lookups have actually done.
        $breaker = geocode_breaker_read((string) $settings['provider']);
        $decided = geocode_breaker_decide($breaker, time());
        if ($decided['open']) {
            $severity = 'critical';
            $notes[] = 'Address lookup is failing: ' . $decided['fails'] . ' consecutive failures'
                     . ($breaker['last_error'] !== '' ? ' (' . $breaker['last_error'] . ')' : '')
                     . '. If the internet is up, check that PHP itself is allowed to make outbound '
                     . 'connections — on Rocky/RHEL that is SELinux\'s httpd_can_network_connect, '
                     . 'which is off by default.';
        }

        $result = [
            'checked'    => true,
            'mode'       => $cfg['mode'],
            'requested'  => $cfg['requested'],
            'provider'   => $settings['provider'],
            'label'      => (string) ($policy['label'] ?? $settings['provider']),
            'verified'   => (string) ($policy['verified'] ?? ''),
            'cache'      => geocode_cache_usage(),
            'breaker'    => ['open' => $decided['open'], 'fails' => $decided['fails'],
                             'retry_in' => $decided['retry_in'],
                             'last_error' => $breaker['last_error']],
            'severity'   => $severity,
            'note'       => implode(' ', $notes),
        ];

        if ($probe && $cfg['mode'] !== 'off') {
            $t0 = microtime(true);
            $res = geocode_lookup('search', ['q' => '1600 Pennsylvania Ave NW, Washington, DC', 'limit' => 1]);
            $result['probe'] = [
                'ok' => (bool) $res['ok'], 'ms' => (int) round((microtime(true) - $t0) * 1000),
                'source' => $res['source'], 'count' => count($res['results']),
                'message' => $res['message'],
            ];
            if (!$res['ok']) {
                $result['severity'] = 'critical';
                $result['note'] = trim($result['note'] . ' Live test failed: ' . $res['message']);
            }
        }

        return $result;
    } catch (Throwable $e) {
        // Never let the health page be the thing that breaks. "Could not tell"
        // is its own answer and must not read as "fine" or as "broken".
        return ['checked' => false, 'severity' => 'ok', 'error' => 'geocoding check failed'];
    }
}

/**
 * ── Cache-directory write capability (2026-08-19) ───────────────────────────
 *
 * `health_check_geocoding()` above reports on the geocode PROVIDER (API key,
 * circuit breaker, cache byte count) — it never asks whether the cache
 * directory can actually be WRITTEN to. On your-server.example.com it could
 * not: a CLI/SSH process had won the race to create GEOCODE_CACHE_DIR before
 * any real web request did, leaving it owned ejosterberg:ejosterberg mode
 * 0700. `geocode_cache_write()` is documented "best effort: a cache we
 * cannot write is not an error", so nothing anywhere logged the failure —
 * every geocode lookup silently bypassed the cache for weeks. This is the
 * "A REASSURING STATUS CODE IS NOT PROOF" lesson applied one more time:
 * `is_dir()` would have reported the directory present and told the reader
 * nothing about whether it actually WORKS.
 *
 * `health_check_dirs()` already has a graded severity model for exactly this
 * question (exists+writable=ok, exists+NOT writable=critical, missing+parent
 * writable=warn i.e. "created on demand" — never critical for a cache that
 * simply hasn't been touched yet, missing+parent-not-writable=critical,
 * account undetermined=unknown — never ok, never critical), built via
 * `_health_path_writable_for()`, which prefers asking the KERNEL
 * (`is_writable()`) whenever the account being asked about is the one
 * actually running this code — the one context where that answer is not a
 * simulation. This function reuses that exact machinery for identity
 * correctness (so a CLI run over SSH as the operator never reports a false
 * "ok" for www-data's own access, the precise bug fixed 2026-07-31 and
 * documented at length above), and adds ONE thing on top: whenever the
 * verdict is "yes, writable, and I am actually asking as that account", it
 * does not stop at the permission bits — it writes a short-lived probe file,
 * reads it back, and deletes it, the same write-prove-cleanup discipline
 * `health_check_dir_probe()` already uses for the exposure question. That
 * catches what a permission-bit check cannot: a read-only mount, a full
 * filesystem, a restrictive ACL or SELinux context that `is_writable()`
 * itself accounts for but that a caller re-deriving the verdict from stat()
 * alone would miss.
 *
 * @param string     $dir     Absolute path (GEOCODE_CACHE_DIR / TILE_CACHE_DIR),
 *                            or '' when the constant is not defined.
 * @param string     $label   Human name for the note text, e.g. "The geocode
 *                            lookup cache".
 * @param string     $fixHint One command to suggest when broken.
 * @param array|null $webUser Override the resolved web server account —
 *                            exists so the severity model can be driven
 *                            directly in tests, exactly like
 *                            health_check_dirs()'s own $webUser parameter.
 */
function health_check_cache_dir_writable(
    string $dir,
    string $label,
    string $fixHint,
    ?array $webUser = null
): array {
    try {
        if ($dir === '') {
            return ['checked' => false, 'severity' => 'ok', 'dir' => $dir, 'label' => $label,
                    'exists' => null, 'writable' => null, 'note' => 'not configured on this install'];
        }

        $webUser = $webUser ?? health_check_web_user();
        $exists  = @is_dir($dir);

        if (!$exists) {
            // Same "missing, is the nearest ancestor writable" logic
            // health_check_dirs() uses — a cache directory that has simply
            // never been touched yet is normal, not a fault.
            $parent = dirname($dir);
            while ($parent !== '' && $parent !== dirname($parent) && !@is_dir($parent)) {
                $parent = dirname($parent);
            }
            $creatable = ($parent !== '' && @is_dir($parent))
                ? _health_path_writable_for($parent, $webUser)
                : false;

            if ($creatable === true) {
                return ['checked' => true, 'severity' => 'warn', 'dir' => $dir, 'label' => $label,
                        'exists' => false, 'writable' => null,
                        'note' => $label . ' does not exist yet. It will be created automatically '
                                . 'the first time it is needed, owned by whichever process gets there '
                                . 'first — run `sudo php tools/fix-permissions.php` to create it '
                                . 'correctly up front instead (this already runs on every '
                                . '`tools/deploy.sh` deploy).'];
            }
            if ($creatable === false) {
                return ['checked' => true, 'severity' => 'critical', 'dir' => $dir, 'label' => $label,
                        'exists' => false, 'writable' => null,
                        'note' => $label . ' does not exist, and its nearest existing ancestor ('
                                . $parent . ') cannot be written to by ' . ($webUser['name'] ?? 'the web server')
                                . ' either — it can never be created without help. ' . $fixHint];
            }
            return ['checked' => true, 'severity' => 'unknown', 'dir' => $dir, 'label' => $label,
                    'exists' => false, 'writable' => null,
                    'note' => $label . ' does not exist; whether it could be created could not be established.'];
        }

        $writable = _health_path_writable_for($dir, $webUser);
        $owner    = _health_file_owner($dir);
        $mode     = null;
        $perms    = @fileperms($dir);
        if ($perms !== false) {
            $mode = sprintf('%04o', $perms & 0777);
        }
        $ownerTxt = $owner !== null ? ' (owner ' . $owner . ($mode !== null ? ', mode ' . $mode : '') . ')' : '';

        if ($writable === null) {
            return ['checked' => true, 'severity' => 'unknown', 'dir' => $dir, 'label' => $label,
                    'exists' => true, 'writable' => null, 'owner' => $owner, 'mode' => $mode,
                    'note' => 'Whether ' . ($webUser['name'] ?? 'the web server') . ' can write to '
                            . $label . ' could not be established.'];
        }
        if ($writable === false) {
            return ['checked' => true, 'severity' => 'critical', 'dir' => $dir, 'label' => $label,
                    'exists' => true, 'writable' => false, 'owner' => $owner, 'mode' => $mode,
                    'note' => $label . ' exists but ' . ($webUser['name'] ?? 'the web server')
                            . ' cannot write to it' . $ownerTxt . ' — every write to it is silently '
                            . 'skipped (this cache is best-effort by design, so nothing else would have '
                            . 'told you). ' . $fixHint];
        }

        // Permission bits say writable. Whenever we are actually asking as the
        // account in question (real web request, or a CLI run as/via the web
        // user), prove it rather than trust it: write, read back, delete.
        if (!empty($webUser['is_this_process'])) {
            $token = bin2hex(random_bytes(8));
            $probe = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '.health-write-probe-' . $token . '.tmp';
            $wrote = @file_put_contents($probe, $token, LOCK_EX);
            $readBack = ($wrote !== false) ? @file_get_contents($probe) : false;
            @unlink($probe);

            if ($wrote === false || $readBack !== $token) {
                return ['checked' => true, 'severity' => 'critical', 'dir' => $dir, 'label' => $label,
                        'exists' => true, 'writable' => false, 'owner' => $owner, 'mode' => $mode,
                        'note' => $label . ' looks writable by permission bits' . $ownerTxt . ' but a real '
                                . 'write failed — check free disk space and, if this is a network '
                                . 'filesystem, whether it is mounted read-only. ' . $fixHint];
            }
        }

        return ['checked' => true, 'severity' => 'ok', 'dir' => $dir, 'label' => $label,
                'exists' => true, 'writable' => true, 'owner' => $owner, 'mode' => $mode, 'note' => ''];
    } catch (Throwable $e) {
        return ['checked' => false, 'severity' => 'ok', 'dir' => $dir, 'label' => $label,
                'exists' => null, 'writable' => null, 'note' => 'could not run the write check'];
    }
}

/**
 * GEOCODE_CACHE_DIR specifically. See health_check_cache_dir_writable().
 *
 * @param array|null $webUser Override, for tests — see health_check_cache_dir_writable().
 */
function health_check_geocode_cache_writable(?array $webUser = null): array
{
    if (!defined('GEOCODE_CACHE_DIR')) {
        try { require_once __DIR__ . '/geocode.php'; } catch (Throwable $e) { /* optional */ }
    }
    $dir = defined('GEOCODE_CACHE_DIR') ? GEOCODE_CACHE_DIR : '';
    return health_check_cache_dir_writable(
        $dir,
        'The geocode lookup cache',
        'sudo php tools/fix-permissions.php',
        $webUser
    );
}

/**
 * TILE_CACHE_DIR specifically. See health_check_cache_dir_writable().
 *
 * @param array|null $webUser Override, for tests — see health_check_cache_dir_writable().
 */
function health_check_tile_cache_writable(?array $webUser = null): array
{
    if (!defined('TILE_CACHE_DIR')) {
        try { require_once __DIR__ . '/tile-proxy.php'; } catch (Throwable $e) { /* optional */ }
    }
    $dir = defined('TILE_CACHE_DIR') ? TILE_CACHE_DIR : '';
    return health_check_cache_dir_writable(
        $dir,
        'The map tile cache',
        'sudo php tools/fix-permissions.php',
        $webUser
    );
}

/**
 * ── Public incident board (Phase 138, 2026-08-13) ──────────────────────────
 *
 * The 2026-08-02 lesson, applied here on purpose: a reassuring status code
 * proves nothing. `health_check_web_exposure()` learned this the hard way —
 * a 403 on a directory answered "safe" while a named file inside it served
 * the whole database. The public board's own redaction is the same shape of
 * risk: an admin can configure a never-publish type, an excluded group, or
 * an address-precision ceiling, and NONE of that is worth anything unless
 * the live endpoint is actually honouring it. So this check never merely
 * asks "did the request succeed" — it asks the database what SHOULD be true,
 * fetches the live public response, and structurally compares the two.
 *
 * THREE independent, deterministic checks against the live database (never
 * synthetic/fabricated rows — the install's own open incidents are the test
 * material, compared structurally, never displayed in the check's own
 * output beyond an id number):
 *
 *   1. Exclusion is active — an open incident subject to a never-publish
 *      type or an excluded group must be ABSENT from the live response.
 *   2. Address masking is active — when precision is not 'exact', a sampled
 *      open incident's real street must NOT appear byte-identical in the
 *      live response's street_display for that same incident id.
 *   3. An org-scoped board isn't silently empty by misconfiguration — for
 *      every org with its own board enabled, 0 open incidents tagged to
 *      that org_id is reported as an 'info' diagnostic (never critical —
 *      a quiet org board is a legitimate, common state, not a defect).
 *
 * If the board is disabled entirely (no global switch, no org switch),
 * this reports 'ok' with an explicit "disabled; nothing to check" note —
 * the same "absent, not merely untested" distinction the backups-probe fix
 * introduced (health_check_backups()) — so this row never renders as a
 * permanent grey unknown on the overwhelming majority of installs that
 * simply have never turned the feature on.
 *
 * Never probes with a fabricated/synthetic incident, and never echoes real
 * street text back into this check's own output — checks 1/2 assert facts
 * (present/absent, identical/not-identical) about data the caller already
 * has full authenticated access to (this runs behind Settings → System
 * Health, which is is_admin()/action.manage_config-gated) rather than
 * reprinting the sensitive value itself.
 */
function health_check_public_board(): array
{
    try {
        if (!function_exists('get_variable') || !function_exists('db_fetch_all')) {
            return ['checked' => false, 'severity' => 'ok', 'enabled' => false,
                    'error' => 'settings/database are not available in this context'];
        }

        $globalEnabled = ((string) (get_variable('public_board_enabled') ?: '0')) === '1';

        $orgs = [];
        try {
            // Security review finding #2 (2026-08-13): a deactivated org
            // (active = 0) no longer resolves on the public board (see
            // api/public-board.php's own org lookup, which now requires
            // active = 1 too) — exclude it here as well, otherwise this
            // check would probe a URL that correctly 404s and misreport a
            // healthy, intentionally-deactivated org as a broken board.
            $orgs = db_fetch_all(
                "SELECT `id`, `name`, `public_board_slug`
                   FROM " . db_table('organizations') . "
                  WHERE `public_board_enabled` = 1 AND `active` = 1"
            );
        } catch (Throwable $e) {
            $orgs = [];
        }

        if (!$globalEnabled && empty($orgs)) {
            return [
                'checked'  => true,
                'enabled'  => false,
                'severity' => 'ok',
                'checks'   => [],
                'summary'  => 'Public incident board is disabled; nothing to check.',
            ];
        }

        $checks   = [];
        $severity = 'ok';
        // critical beats warn beats unknown beats info beats ok — a single
        // row must report the worst thing any one of its sub-checks found.
        $rank = ['ok' => 0, 'info' => 1, 'unknown' => 2, 'warn' => 3, 'critical' => 4];
        $bump = function (string $s) use (&$severity, $rank) {
            if (($rank[$s] ?? 0) > ($rank[$severity] ?? 0)) { $severity = $s; }
        };

        $base = _health_self_base_url();

        // Pick ONE primary probe target for checks 1/2 — the shared board
        // when it's on (it covers every org's incidents at once, the most
        // direct test of the board-wide never-publish/excluded-group rules),
        // otherwise the first org-scoped board with a usable slug.
        $primaryUrl   = null;
        $primaryLabel = null;
        $primaryOrgId = null; // null = the shared/unscoped board

        if ($base !== null) {
            if ($globalEnabled) {
                $primaryUrl   = $base . '/api/public-board.php';
                $primaryLabel = 'the shared public board';
            } else {
                foreach ($orgs as $o) {
                    $slug = trim((string) ($o['public_board_slug'] ?? ''));
                    if ($slug !== '') {
                        $primaryUrl   = $base . '/api/public-board.php?org=' . rawurlencode($slug);
                        $primaryLabel = 'the "' . (string) $o['name'] . '" public board';
                        $primaryOrgId = (int) $o['id'];
                        break;
                    }
                }
            }
        }

        if ($base === null) {
            $checks[] = ['name' => 'self_probe', 'severity' => 'unknown',
                'message' => 'Cannot determine this install\'s own URL from the command line — '
                           . 'open Settings → System Health in a browser to run this check.'];
            $bump('unknown');
        } elseif ($primaryUrl === null) {
            // Enabled somewhere, but no reachable URL could be built — e.g.
            // an org's board is switched on but has no slug saved yet. A
            // config gap for the admin UI to surface, not a redaction defect.
            $checks[] = ['name' => 'self_probe', 'severity' => 'warn',
                'message' => 'The public board is enabled but no organization has a usable slug '
                           . 'yet, so no live URL could be probed.'];
            $bump('warn');
        } else {
            $openNotDeleted = "t.status = 2 AND (t.deleted_at IS NULL OR t.deleted_at = '0000-00-00 00:00:00')";
            $orgFilterSql   = $primaryOrgId !== null ? ' AND t.org_id = ?' : '';

            // ── Check 1: exclusion is active ──────────────────────────
            $excludedIds = [];
            try {
                $excludedGroupsRaw = get_variable('public_board_excluded_groups');
                $excludedGroups = [];
                if (is_string($excludedGroupsRaw) && trim($excludedGroupsRaw) !== '') {
                    $excludedGroups = array_values(array_filter(
                        array_map('trim', explode(',', $excludedGroupsRaw)),
                        function ($g) { return $g !== ''; }
                    ));
                }
                $groupSql = '';
                $params   = [];
                if (!empty($excludedGroups)) {
                    $ph = implode(',', array_fill(0, count($excludedGroups), '?'));
                    $groupSql = " OR (it.`group` IS NOT NULL AND it.`group` IN ($ph))";
                    $params   = array_merge($params, $excludedGroups);
                }
                if ($primaryOrgId !== null) { $params[] = $primaryOrgId; }
                $rows = db_fetch_all(
                    "SELECT t.id
                       FROM " . db_table('ticket') . " t
                       INNER JOIN " . db_table('in_types') . " it ON t.in_types_id = it.id
                      WHERE {$openNotDeleted}
                        AND (it.public_board_never_publish = 1{$groupSql})
                        {$orgFilterSql}
                      LIMIT 50",
                    $params
                );
                foreach ($rows as $r) { $excludedIds[] = (int) $r['id']; }
            } catch (Throwable $e) {
                $excludedIds = [];
            }

            if (empty($excludedIds)) {
                $checks[] = ['name' => 'exclusion_active', 'severity' => 'info',
                    'message' => 'No currently-open incident is subject to a never-publish/'
                               . 'excluded-group rule right now, so exclusion could not be '
                               . 'exercised against live data this pass. Not a defect.'];
                $bump('info');
            } else {
                $body    = _health_probe_body($primaryUrl, 262144);
                $decoded = $body !== null ? json_decode($body, true) : null;
                if (!is_array($decoded) || !isset($decoded['incidents']) || !is_array($decoded['incidents'])) {
                    $checks[] = ['name' => 'exclusion_active', 'severity' => 'unknown',
                        'message' => 'Could not fetch or parse the live response from ' . $primaryLabel
                                   . ' to verify exclusion this pass.'];
                    $bump('unknown');
                } else {
                    $seenIds = array_map(function ($i) { return (int) ($i['id'] ?? 0); }, $decoded['incidents']);
                    $leaked  = array_values(array_intersect($excludedIds, $seenIds));
                    if (!empty($leaked)) {
                        $checks[] = ['name' => 'exclusion_active', 'severity' => 'critical',
                            'message' => 'Incident id(s) ' . implode(', ', $leaked) . ' appear on '
                                       . $primaryLabel . ' despite a never-publish or excluded-group '
                                       . 'rule configured for their type.'];
                        $bump('critical');
                    } else {
                        $checks[] = ['name' => 'exclusion_active', 'severity' => 'ok',
                            'message' => count($excludedIds) . ' excluded incident(s) confirmed '
                                       . 'absent from ' . $primaryLabel . '.'];
                    }
                }
            }

            // ── Check 2: address masking is active ────────────────────
            $precision = (string) (get_variable('public_board_address_precision') ?: 'block');
            if (!in_array($precision, ['exact', 'block', 'city', 'hidden'], true)) {
                $precision = 'block';
            }
            if ($precision === 'exact') {
                $checks[] = ['name' => 'masking_active', 'severity' => 'ok',
                    'message' => 'Board precision is set to "exact" — masking is intentionally '
                               . 'off, so there is nothing to verify.'];
            } else {
                $sample = null;
                try {
                    $params2 = $primaryOrgId !== null ? [$primaryOrgId] : [];
                    $sample = db_fetch_one(
                        "SELECT t.id, t.street
                           FROM " . db_table('ticket') . " t
                           INNER JOIN " . db_table('in_types') . " it ON t.in_types_id = it.id
                          WHERE {$openNotDeleted}
                            AND it.public_board_never_publish = 0
                            AND t.street IS NOT NULL AND t.street <> ''
                            {$orgFilterSql}
                          ORDER BY t.date DESC LIMIT 1",
                        $params2
                    );
                } catch (Throwable $e) {
                    $sample = null;
                }

                if (empty($sample)) {
                    $checks[] = ['name' => 'masking_active', 'severity' => 'info',
                        'message' => 'No currently-open, eligible incident with a street address '
                                   . 'is available to verify masking against this pass. Not a defect.'];
                    $bump('info');
                } else {
                    $body2    = _health_probe_body($primaryUrl, 262144);
                    $decoded2 = $body2 !== null ? json_decode($body2, true) : null;
                    $found    = null;
                    if (is_array($decoded2) && isset($decoded2['incidents']) && is_array($decoded2['incidents'])) {
                        foreach ($decoded2['incidents'] as $inc) {
                            if ((int) ($inc['id'] ?? 0) === (int) $sample['id']) { $found = $inc; break; }
                        }
                    }
                    if ($found === null) {
                        // The sample may simply not have cleared its publish delay yet,
                        // or the live response could not be fetched/parsed — either way
                        // this is "could not verify", never a false pass or false fail.
                        $checks[] = ['name' => 'masking_active', 'severity' => 'unknown',
                            'message' => 'Sampled incident #' . (int) $sample['id'] . ' did not appear '
                                       . 'in the live response from ' . $primaryLabel . ' this pass '
                                       . '(publish delay not yet elapsed, or the response could not be '
                                       . 'fetched/parsed) — masking could not be verified this time.'];
                        $bump('unknown');
                    } else {
                        $realStreet = (string) $sample['street'];
                        $shown      = (string) ($found['street_display'] ?? '');
                        if ($shown !== '' && $shown === $realStreet) {
                            $checks[] = ['name' => 'masking_active', 'severity' => 'critical',
                                'message' => 'Incident #' . (int) $sample['id'] . '\'s full street '
                                           . 'address is showing unmasked on ' . $primaryLabel
                                           . ' even though the configured precision is "' . $precision . '".'];
                            $bump('critical');
                        } else {
                            $checks[] = ['name' => 'masking_active', 'severity' => 'ok',
                                'message' => 'Incident #' . (int) $sample['id'] . '\'s address is masked '
                                           . 'on ' . $primaryLabel . ' as configured.'];
                        }
                    }
                }
            }
        }

        // ── Check 3: an org-scoped board isn't silently empty ────────────
        // Independent of checks 1/2 above (pure DB counts, no HTTP) — runs
        // for EVERY org with its own board enabled, not just the primary one
        // probed above.
        foreach ($orgs as $o) {
            $oid = (int) $o['id'];
            $cnt = 0;
            try {
                $cnt = (int) db_fetch_value(
                    "SELECT COUNT(*) FROM " . db_table('ticket') . "
                      WHERE org_id = ? AND status = 2
                        AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')",
                    [$oid]
                );
            } catch (Throwable $e) {
                $cnt = 0;
            }
            $orgName = (string) ($o['name'] ?? ('organization ' . $oid));
            if ($cnt === 0) {
                $checks[] = ['name' => 'org_not_empty', 'org_id' => $oid, 'severity' => 'info',
                    'message' => 'Public board enabled for "' . $orgName . '", but 0 open incidents '
                               . 'are tagged org_id = ' . $oid . ' — confirm incidents are being '
                               . 'assigned to this organization if you expect this board to show '
                               . 'activity.'];
                $bump('info');
            } else {
                $checks[] = ['name' => 'org_not_empty', 'org_id' => $oid, 'severity' => 'ok',
                    'message' => '"' . $orgName . '" has ' . $cnt . ' open incident(s) tagged to it.'];
            }
        }

        // One-line summary for the Status page row — names the worst thing
        // found, or a clean "N checks passed" when nothing did.
        $worstMsg = null;
        foreach (['critical', 'warn', 'unknown'] as $wantSev) {
            foreach ($checks as $c) {
                if (($c['severity'] ?? '') === $wantSev) { $worstMsg = $c['message']; break 2; }
            }
        }
        if ($worstMsg === null && $severity === 'info') {
            foreach ($checks as $c) {
                if (($c['severity'] ?? '') === 'info') { $worstMsg = $c['message']; break; }
            }
        }
        $okCount = 0;
        foreach ($checks as $c) { if (($c['severity'] ?? '') === 'ok') { $okCount++; } }
        $summary = $worstMsg ?? ($okCount . ' check' . ($okCount === 1 ? '' : 's') . ' passed.');

        return [
            'checked'  => true,
            'enabled'  => true,
            'severity' => $severity,
            'checks'   => $checks,
            'summary'  => $summary,
        ];
    } catch (Throwable $e) {
        // Never let this be the thing that breaks the health page. "Could
        // not tell" must not read as either "fine" or "broken".
        return ['checked' => false, 'severity' => 'ok', 'enabled' => false,
                'error' => 'public board health check failed'];
    }
}

function health_check_all(): array
{
    try {
        // GHSA-x9x6-w4fg-pmcc — Zello recordings now live outside $root, so
        // the writability check travels via $extraDirs.
        $zelloExtra = [];
        if (!function_exists('zello_audio_dir')) {
            try { require_once __DIR__ . '/zello_audio_dir.php'; } catch (Throwable $e) { /* optional */ }
        }
        if (function_exists('zello_audio_dir')) {
            $zelloExtra[] = zello_audio_dir();
        }
        $dirs       = health_check_dirs($zelloExtra);
        $unreadable = health_check_unreadable();
        $opcache    = health_check_opcache();
        $version    = health_check_version_match();
        $deps       = health_check_dependencies();
        $schema     = health_check_schema();
        $jobs       = health_check_scheduled_jobs();
        $backups    = health_check_backups();
        $keys       = health_check_keys();
        $exposure   = health_check_web_exposure();
        $geocoding  = health_check_geocoding();
        $geocodeCacheWritable = health_check_geocode_cache_writable();
        $tileCacheWritable    = health_check_tile_cache_writable();
        $publicBoard = health_check_public_board();
        $teamMembership = health_check_team_membership_reconciliation();

        $critical = 0;
        $warn     = 0;
        // A third bucket, deliberately not folded into either of the other
        // two. "We could not tell" is a distinct answer from "fine" and from
        // "broken", and collapsing it into one of them is how this check
        // came to report a healthy install as critically broken.
        $unknown  = 0;

        foreach (($dirs['dirs'] ?? []) as $d) {
            if (($d['severity'] ?? '') === 'critical') {
                $critical++;
            } elseif (($d['severity'] ?? '') === 'warn') {
                $warn++;
            } elseif (($d['severity'] ?? '') === 'unknown') {
                $unknown++;
            }
        }
        $critical += count($unreadable['unreadable'] ?? []);
        if (($opcache['severity'] ?? '') === 'warn') {
            $warn++;
        } elseif (($opcache['severity'] ?? '') === 'critical') {
            $critical++;
        }
        if (($version['severity'] ?? '') === 'critical') {
            $critical++;
        }
        if (($deps['severity'] ?? '') === 'warn') {
            $warn++;
        }
        if (($schema['severity'] ?? '') === 'critical') {
            $critical++;
        } elseif (($schema['severity'] ?? '') === 'warn') {
            $warn++;
        }
        if (($jobs['severity'] ?? '') === 'critical') {
            $critical++;
        } elseif (($jobs['severity'] ?? '') === 'warn') {
            $warn++;
        }
        foreach ([$backups, $keys, $exposure, $geocoding, $geocodeCacheWritable, $tileCacheWritable,
                  $publicBoard, $teamMembership] as $sec) {
            if (($sec['severity'] ?? '') === 'critical') {
                $critical++;
            } elseif (($sec['severity'] ?? '') === 'warn') {
                $warn++;
            } elseif (($sec['severity'] ?? '') === 'unknown') {
                // Counted, not dropped. A web-exposure check that could not test
                // the backups path must not sum into "healthy" — that is the
                // false all-clear this bucket exists for.
                $unknown++;
            }
            // 'info' (public-board's org-not-empty/no-sample-available notes)
            // is deliberately NOT counted here — an install that simply has
            // no open incidents to test against, or a genuinely quiet org
            // board, is not a fault and must not turn the overall badge amber.
        }

        return [
            'checked'      => true,
            'generated_at' => date('Y-m-d H:i:s'),
            'sapi'         => PHP_SAPI,
            'process_user' => _health_process_user(),
            'web_user'     => $dirs['web_user'] ?? health_check_web_user(),
            'dirs'         => $dirs,
            'unreadable'   => $unreadable,
            'opcache'      => $opcache,
            'version'      => $version,
            'dependencies' => $deps,
            'schema'       => $schema,
            'scheduled_jobs' => $jobs,
            'backups'      => $backups,
            'keys'         => $keys,
            'web_exposure' => $exposure,
            'geocoding'    => $geocoding,
            'geocode_cache_writable' => $geocodeCacheWritable,
            'tile_cache_writable'    => $tileCacheWritable,
            'public_board' => $publicBoard,
            'team_membership' => $teamMembership,
            'summary'      => ['critical' => $critical, 'warn' => $warn, 'unknown' => $unknown],
        ];
    } catch (Throwable $e) {
        return [
            'checked' => false,
            'error'   => 'health check failed',
            'summary' => ['critical' => 0, 'warn' => 0, 'unknown' => 0],
        ];
    }
}
