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

// Literal build date. Compiled into the opcache'd copy of this file; the
// version-match check re-reads this constant FRESH from disk and compares
// — a mismatch means the server is executing a stale compiled copy.
if (!defined('HEALTH_CHECK_BUILD')) {
    define('HEALTH_CHECK_BUILD', '2026-07-04');
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
 * Check the required-writable directories.
 *
 * Severity model (detect-and-warn only):
 *   - exists + writable            → ok
 *   - missing, parent writable     → warn     (app will create it on demand)
 *   - missing, parent NOT writable → critical (creation will fail at runtime)
 *   - exists but NOT writable      → critical (uploads/recordings/cache writes fail)
 *
 * NOTE: is_writable() answers for the CURRENT process user. Via the web
 * SAPI (api/health-check.php) that is the web server user — authoritative.
 * Via CLI it is the shell user — informative only.
 *
 * @param array $extraDirs Additional absolute or root-relative paths to
 *                         check (used by tests and future recordings dirs).
 */
function health_check_dirs(array $extraDirs = []): array
{
    try {
        $root = health_check_root();

        // Root-relative required-writable dirs. cache/zello-audio is the
        // Zello proxy recordings dir (hardcoded in proxy/ZelloProxyApp.php
        // as dirname(__DIR__) . '/cache/zello-audio') — this is the exact
        // dir that broke for the git-pull-as-root beta install.
        $relDirs = [
            'uploads'           => 'file attachments (api/upload.php)',
            'uploads/overlays'  => 'map image overlays (api/map-image-overlays.php)',
            'cache'             => 'general cache root',
            'cache/weather'     => 'weather tile cache (api/weather-proxy.php)',
            'cache/zello-audio' => 'Zello voice recordings (proxy/ZelloProxyApp.php)',
        ];

        $entries = [];

        $check = function (string $abs, string $rel, string $purpose) use (&$entries) {
            $exists   = @is_dir($abs);
            $writable = $exists ? @is_writable($abs) : false;
            $owner    = $exists ? _health_file_owner($abs) : null;

            if ($exists && $writable) {
                $severity = 'ok';
                $note     = '';
            } elseif ($exists && !$writable) {
                $severity = 'critical';
                $note     = 'Directory exists but is NOT writable by this process — writes (uploads, recordings, cache) will fail.';
            } else {
                // Missing — creatable if the nearest existing ancestor is writable.
                $parent = dirname($abs);
                while ($parent !== '' && $parent !== dirname($parent) && !@is_dir($parent)) {
                    $parent = dirname($parent);
                }
                $creatable = ($parent !== '' && @is_dir($parent) && @is_writable($parent));
                if ($creatable) {
                    $severity = 'warn';
                    $note     = 'Directory is missing but can be created on demand.';
                } else {
                    $severity = 'critical';
                    $note     = 'Directory is missing and its parent is not writable — the app cannot create it.';
                }
            }

            $entries[] = [
                'path'     => $rel,
                'abs'      => $abs,
                'purpose'  => $purpose,
                'exists'   => (bool) $exists,
                'writable' => (bool) $writable,
                'owner'    => $owner,
                'severity' => $severity,
                'note'     => $note,
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
 *   1. NEWUI_VERSION — defined in config.php (config.example.php on a
 *      template checkout, inc/version.php if a future refactor moves it).
 *   2. HEALTH_CHECK_BUILD — self-probe against this very file, which IS
 *      git-tracked: after a pull that updates inc/health-check.php, a
 *      stale opcache serves the old compiled constant while the disk
 *      regex shows the new one.
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
        // Only meaningful when both sides resolved.
        $versionComparable = ($running !== null && $onDisk !== null);
        $versionMatch      = $versionComparable ? ($running === $onDisk) : null;

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

        return [
            'checked'       => true,
            'version_file'  => $versionFile,
            'running'       => $running,
            'on_disk'       => $onDisk,
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

function health_check_all(): array
{
    try {
        $dirs       = health_check_dirs();
        $unreadable = health_check_unreadable();
        $opcache    = health_check_opcache();
        $version    = health_check_version_match();
        $deps       = health_check_dependencies();

        $critical = 0;
        $warn     = 0;

        foreach (($dirs['dirs'] ?? []) as $d) {
            if (($d['severity'] ?? '') === 'critical') {
                $critical++;
            } elseif (($d['severity'] ?? '') === 'warn') {
                $warn++;
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

        return [
            'checked'      => true,
            'generated_at' => date('Y-m-d H:i:s'),
            'sapi'         => PHP_SAPI,
            'process_user' => _health_process_user(),
            'dirs'         => $dirs,
            'unreadable'   => $unreadable,
            'opcache'      => $opcache,
            'version'      => $version,
            'dependencies' => $deps,
            'summary'      => ['critical' => $critical, 'warn' => $warn],
        ];
    } catch (Throwable $e) {
        return [
            'checked' => false,
            'error'   => 'health check failed',
            'summary' => ['critical' => 0, 'warn' => 0],
        ];
    }
}
