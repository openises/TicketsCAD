<?php
/**
 * NewUI — "is this directory published over HTTP, and can we fence it?"
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────────
 *
 * The same wrong assumption has now been shipped three times, each time in a
 * different place, each time by a different author, and each time it read as
 * obviously correct:
 *
 *   1. 2026-07-30 (GHSA-rrp6-pqhj-w5wj)  Database backups lived INSIDE the
 *      application tree, and the documented install points the web root at the
 *      application root. `GET /backups/<archive>.zip` returned a 110 MB
 *      database dump from a live, internet-facing install.
 *
 *   2. 2026-08-02 (the 4.2.3 regression)  The fix for (1) moved them to
 *      `dirname(NEWUI_ROOT)`, described as "above the web root". That is true
 *      of a POSIX layout and false of a stock Windows one: a site at
 *      C:\inetpub\wwwroot\<app> has a parent of C:\inetpub\wwwroot, the
 *      physical path of IIS's Default Web Site, bound to *:80. XAMPP has the
 *      identical shape (C:\xampp\htdocs\<app> → the DocumentRoot).
 *
 *   3. 2026-08-03 (GHSA-3jmh-c6f6-64jc)  FE_KEYS_DIR — the RSA private key and
 *      the 2FA encryption key — was `NEWUI_ROOT . '/../keys'`, chosen for
 *      exactly the reason in (2) and inverted on Windows in exactly the same
 *      way. @rjonesbsink proved the directory was served:
 *      `GET /keys/_probe.txt` → 200.
 *
 * "One level up" is not a security boundary. It is a security boundary on the
 * layout the author happened to be picturing. Everything in this file exists so
 * that the next directory placed outside the tree gets the same graded answer
 * and the same fence, instead of the same assumption a fourth time.
 *
 * These helpers are deliberately dependency-free — no database, no settings, no
 * autoloader — so the two callers that must not depend on each other (backups
 * and key storage) can both use them.
 *
 * Callers: inc/backup.php (via backup_dir_exposure() / backup_harden_dir()),
 * inc/field-encrypt.php (via fe_harden_keys_dir()), inc/health-check.php.
 */

/**
 * %ProgramData%, normalised, without a trailing separator.
 *
 * Windows sets this on every install and it is inherited by IIS worker
 * processes and by the CLI alike, so the web UI and a command-line tool resolve
 * the same directory. The two fallbacks exist only so a scrubbed environment
 * cannot produce a relative path.
 *
 * Deliberately no filesystem access: callers use this to build the value of a
 * define(), and a constant whose value tracks mutable filesystem state
 * relocates an install's data when something unrelated changes.
 */
function served_dir_program_data(): string
{
    $base = getenv('ProgramData');
    if ($base === false || trim((string) $base) === '') {
        $sd   = getenv('SystemDrive');
        $sd   = ($sd !== false && trim((string) $sd) !== '') ? rtrim((string) $sd, '\\/') : 'C:';
        $base = $sd . '\\ProgramData';
    }
    return rtrim(str_replace('/', '\\', (string) $base), '\\');
}

/**
 * The platform-aware "just needs to be above the web root" default, generic
 * over the subdirectory name — for the CACHE/STATE directories that don't
 * carry irreplaceable data (no migration or legacy-preference needed the way
 * backups/keys/zello-audio do; an empty directory at a new location is a
 * cache miss, not data loss).
 *
 * Four call sites had each hand-rolled `dirname(NEWUI_ROOT) . '/xxx'` before
 * this existed — GEOCODE_CACHE_DIR, TILE_CACHE_DIR, the weather-proxy
 * breaker-state file's directory, and the DMR bridge health-state file's
 * directory — every one of them correct on POSIX and, on a stock Windows/IIS
 * install, inside `C:\inetpub\wwwroot`: the SAME mistake GHSA-x9x6-w4fg-pmcc's
 * round 2 made for zello-audio, in four more places, none of them caught by
 * a report because none of them had a reporter looking at exactly that
 * directory. See this file's own header for why "one level up" is a belief
 * about a layout, not a fact about the machine.
 *
 * @param string    $appRoot The application root (NEWUI_ROOT).
 * @param string    $subdir  Bare subdirectory name, e.g. 'geocode-cache'.
 * @param bool|null $windows NULL = detect from this machine.
 */
function served_dir_above_root(string $appRoot, string $subdir, ?bool $windows = null): string
{
    if ($windows === null) {
        $windows = (DIRECTORY_SEPARATOR === '\\');
    }
    $subdir = trim(str_replace(['\\', '/'], '', $subdir)); // bare name only
    if (!$windows) {
        return served_dir_parent_of($appRoot, false) . '/' . $subdir;
    }
    return served_dir_program_data() . '\\TicketsCAD\\' . $subdir;
}

/**
 * The parent of a path, on a platform given as an argument rather than read
 * from the machine this happens to be running on.
 *
 * dirname('C:\inetpub\wwwroot\App') answers '.' on Linux, so with dirname()
 * alone the Windows layouts in this file's history could not be asserted from
 * the CI machine at all — and a test that can only see its own platform's
 * answer is precisely how (2) above shipped.
 */
function served_dir_parent_of(string $path, bool $windows): string
{
    $p  = rtrim(str_replace('\\', '/', $path), '/');
    $at = strrpos($p, '/');
    if ($at === false) {
        return $p;
    }
    $parent = ($at === 0) ? '/' : substr($p, 0, $at);
    return $windows ? str_replace('/', '\\', $parent) : $parent;
}

/**
 * Is a path inside the served application tree (and therefore potentially
 * reachable over HTTP)?
 *
 * Narrow on purpose — "inside OUR tree" is the one thing that can be decided
 * with certainty from the filesystem. For the broader, honest question ("is
 * this directory published by ANY site on this machine?") use
 * served_dir_exposure(), which grades its own confidence and says what it
 * cannot see.
 */
function served_dir_is_in_app_tree(string $dir): bool
{
    if (!defined('NEWUI_ROOT')) {
        return false;
    }
    $n    = function (string $p): string { return rtrim(str_replace('\\', '/', $p), '/'); };
    $real = @realpath($dir);
    $dirN = $n($real !== false ? $real : $dir);
    $rootReal = @realpath(NEWUI_ROOT);
    $rootN    = $n($rootReal !== false ? $rootReal : NEWUI_ROOT);
    return $dirN === $rootN || strpos($dirN, $rootN . '/') === 0;
}

/**
 * Could this directory be published over HTTP by some web site on this machine?
 *
 * ── WHAT THIS CAN AND CANNOT KNOW ──────────────────────────────────────────
 *
 * The application does not know the server's site layout. It has no list of
 * vhosts, no bindings, no document roots but its own. Pretending otherwise is
 * exactly the failure being fixed here: v4.2.3 moved archives into another
 * site's document root and then reported the install healthy, because the only
 * question it knew how to ask was "is this inside MY tree?".
 *
 * So this returns a graded verdict and never a bare boolean:
 *
 *   served=true    Certain. Inside this application's own tree, or inside
 *                  %SystemDrive%\inetpub\wwwroot — the physical path of IIS's
 *                  Default Web Site on every stock Windows machine.
 *   suspect=true   The containing directory looks like a document root (it
 *                  holds an index/default page or a web.config, or it is named
 *                  wwwroot / htdocs / public_html / httpdocs). Reported as a
 *                  warning, not a failure: a heuristic that shouts is a
 *                  heuristic that gets ignored.
 *   neither        No LOCAL evidence. That is not the same as safe, and the
 *                  caller must say so — see 'blind_spot'. Only an HTTP canary
 *                  probe (inc/health-check.php) can turn this into knowledge,
 *                  and only for the hostname this install answers on.
 *
 * Deliberately no shelling out to `appcmd list site` / `apache2ctl -S`: the
 * project gates against shell execution, the canary probe is stronger evidence
 * anyway (it proves the file is reachable, not that a config says it might be),
 * and an install with no IIS still needs an answer.
 *
 * @return array{dir:string,served:bool,suspect:bool,state:string,why:string,blind_spot:string}
 */
function served_dir_exposure(string $dir): array
{
    $n = function (string $p): string {
        return rtrim(str_replace('\\', '/', $p), '/');
    };
    $blind = 'This looks only at file layout on this machine. It cannot see other '
           . 'web sites, other ports, or a reverse proxy — a directory published '
           . 'by a different vhost is invisible to it.';

    try {
        $real = @realpath($dir);
        $abs  = $n($real !== false ? $real : $dir);

        if (served_dir_is_in_app_tree($dir)) {
            return ['dir' => $abs, 'served' => true, 'suspect' => true,
                    'state' => 'in_app_tree',
                    'why'   => 'inside this application\'s own directory, which IS the web root',
                    'blind_spot' => ''];
        }

        // IIS Default Web Site. Its physical path is %SystemDrive%\inetpub\wwwroot
        // on every stock Windows install, and it is bound to *:80 — so anything
        // under it is public even when this application answers on another port.
        $sd = getenv('SystemDrive');
        $sd = ($sd !== false && trim((string) $sd) !== '') ? rtrim((string) $sd, '\\/') : 'C:';
        $wwwroot = $n($sd . '/inetpub/wwwroot');
        if (strcasecmp($abs, $wwwroot) === 0
            || strncasecmp($abs, $wwwroot . '/', strlen($wwwroot) + 1) === 0) {
            return ['dir' => $abs, 'served' => true, 'suspect' => true,
                    'state' => 'in_default_site_root',
                    'why'   => 'inside ' . str_replace('/', '\\', $wwwroot)
                             . ', the physical path of IIS\'s Default Web Site (bound to port 80)',
                    'blind_spot' => ''];
        }

        // Does the directory that would map to the URL prefix look like a
        // document root? Checked over the dir itself and two levels up, because
        // <docroot>/private/backups is served just as readily as <docroot>/backups.
        //
        // The marker list is deliberately SHORT. A bare index.html is not on it:
        // it turns up in temp directories, download folders and archive trees,
        // and the first draft of this function flagged %TEMP% as a document root
        // because one was lying there. A heuristic that fires on innocent
        // layouts gets muted, and then it is the silent one — the same failure
        // shape as the scheduled-job check that called every fresh install
        // broken. Everything here means "a web server put this here" or "a web
        // application is served from here", and nothing else.
        $markers = ['web.config', 'iisstart.htm', 'iisstart.png',
                    'index.php', 'default.aspx'];
        // Names that mean "document root" and essentially nothing else. `www`
        // and `html` are NOT here: /var/www and /var/www/html are the normal
        // parents of a correct POSIX install, and flagging every one of those
        // would train operators to ignore this row.
        $rootNames = ['wwwroot', 'htdocs', 'public_html', 'httpdocs'];

        $probe = $abs;
        for ($up = 0; $up < 3; $up++) {
            $probe = $n(dirname($probe));
            if ($probe === '' || $probe === '.' || dirname($probe) === $probe) {
                break;
            }
            if (in_array(strtolower(basename($probe)), $rootNames, true)) {
                return ['dir' => $abs, 'served' => false, 'suspect' => true,
                        'state' => 'looks_like_site_root',
                        'why'   => basename($probe) . ' is a document-root name — '
                                 . $probe . ' is very likely published',
                        'blind_spot' => $blind];
            }
            foreach ($markers as $m) {
                if (@is_file($probe . '/' . $m)) {
                    return ['dir' => $abs, 'served' => false, 'suspect' => true,
                            'state' => 'looks_like_site_root',
                            'why'   => $probe . ' contains ' . $m
                                     . ', so it looks like a published document root',
                            'blind_spot' => $blind];
                }
            }
            // Debian/Ubuntu's stock Apache DocumentRoot is /var/www/html and its
            // stock page is index.html — the one place a bare index.html is
            // evidence. This is the POSIX twin of the Windows bug: an install at
            // /var/www/html/newui gets a v4.2.3 default of /var/www/html/backups,
            // which is served.
            if (strtolower(basename($probe)) === 'html'
                && (@is_file($probe . '/index.html') || @is_file($probe . '/index.htm'))) {
                return ['dir' => $abs, 'served' => false, 'suspect' => true,
                        'state' => 'looks_like_site_root',
                        'why'   => $probe . ' is a stock Apache DocumentRoot '
                                 . '(named html, holding an index page)',
                        'blind_spot' => $blind];
            }
        }

        return ['dir' => $abs, 'served' => false, 'suspect' => false,
                'state' => 'no_local_evidence',
                'why'   => 'outside this application\'s tree, with nothing on disk '
                         . 'suggesting a web site publishes it',
                'blind_spot' => $blind];
    } catch (Throwable $e) {
        // Unknown is never "fine". Say so rather than returning a clean bill.
        return ['dir' => $n($dir), 'served' => false, 'suspect' => false,
                'state' => 'unknown',
                'why'   => 'could not examine the path',
                'blind_spot' => $blind];
    }
}

/**
 * Drop deny rules beside files that must never be served, so that at least
 * Apache and IIS refuse them.
 *
 * $force exists because the two callers want different triggers, and both are
 * right for what they hold:
 *
 *   backups   fence only when the directory is served or suspect. A .htaccess
 *             in a directory no server can see is noise, and noise in a backup
 *             folder is one more file an operator has to reason about.
 *   keys      fence whenever the directory is created, wherever it is. A
 *             private key has no legitimate reachable-over-HTTP state at all,
 *             the cost is two inert files, and the whole lesson of this file's
 *             history is that "outside the web root" was a belief about a
 *             layout rather than a fact about the machine.
 *
 * Best effort by design: a failure here must never stop a backup being taken or
 * a key being generated. The Status page reports the exposure either way, and a
 * deny file is a mitigation, not a reason to leave the files where they are.
 *
 * @param string $dir   The directory to fence.
 * @param string $what  Human label for the first comment line ("Database backups").
 * @param bool   $force Write even when there is no local evidence of exposure.
 */
function served_dir_harden(string $dir, string $what, bool $force = false): void
{
    try {
        if (!is_dir($dir)) {
            return;
        }
        if (!$force) {
            $x = served_dir_exposure($dir);
            if (!$x['served'] && !$x['suspect']) {
                return;
            }
        }
        $base = basename(rtrim(str_replace('\\', '/', $dir), '/'));
        // RedirectMatch takes an Apache regex. Only emit the directory-name rule
        // when the name is plain enough that it cannot be one — the RewriteRule
        // below denies the whole directory regardless, so nothing is lost.
        $nameRule = preg_match('/^[A-Za-z0-9_-]+$/', $base) === 1
            ? "<IfModule mod_alias.c>\n"
              . "    RedirectMatch 404 (^|/)" . $base . "(/|\$)\n"
              . "</IfModule>\n"
            : '';

        $ht = rtrim($dir, '/\\') . '/.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht,
                "# " . $what . ". Never serve these over HTTP.\n"
                . "# Written automatically by TicketsCAD; see docs/WEB-SERVER-HARDENING.md.\n"
                . "# nginx ignores this file — use docs/nginx/ticketscad-hardening.conf there.\n"
                . $nameRule
                . "<IfModule mod_rewrite.c>\n"
                . "    RewriteEngine On\n"
                . "    RewriteRule .* - [F,L]\n"
                . "</IfModule>\n");
        }
        $wc = rtrim($dir, '/\\') . '/web.config';
        if (!file_exists($wc)) {
            // Request Filtering, NOT <authorization>. This is the ONE shape
            // TicketsCAD ships — sql/web.config and tools/web.config are the
            // same four lines, and the full reasoning is in the comment at the
            // top of sql/web.config. In short: Request Filtering is in the
            // default IIS feature set, URL Authorization is an optional role
            // service, and a web.config naming a section whose module is absent
            // makes IIS answer 500.19 for the directory — a deny by accident,
            // which is what gets the file deleted and the exposure restored.
            // (The version shipped before 2026-08-02 also nested <authorization>
            // directly under <system.webServer> instead of under <security>,
            // which is not a valid section path at all.)
            //
            // What it denies here: allowUnlisted="false" with nothing allowed
            // refuses every URL that carries a file name extension — so the
            // ARCHIVE or the .pem itself, not merely the listing. That
            // distinction is the whole report: /backups/ answered 403 while the
            // .zip inside it answered 200 and served a complete database export.
            // Denied requests are HTTP 404, substatus 404.7 in the IIS log.
            // Extension-less URLs (GET /keys/) are refused as well, because IIS
            // treats no-extension as unlisted; never add a
            // <add fileExtension="." allowed="true" /> entry here, and keep
            // <directoryBrowse enabled="false" /> as the independent second
            // stop for that case.
            //
            // NOTE on .pem specifically: on IIS a private key currently escapes
            // by accident, because there is no MIME mapping for .pem and IIS
            // answers 404.3. That is not a control — adding a mapping for any
            // unrelated reason serves it, and Apache has no MIME allow-list to
            // fall back on at all. This file is the control.
            @file_put_contents($wc,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<!-- " . $what . ". IIS ignores .htaccess; this is the equivalent. -->\n"
                . "<configuration>\n  <system.webServer>\n"
                . "    <security>\n"
                . "      <requestFiltering>\n"
                . "        <fileExtensions allowUnlisted=\"false\" />\n"
                . "      </requestFiltering>\n"
                . "    </security>\n"
                . "    <directoryBrowse enabled=\"false\" />\n"
                . "  </system.webServer>\n</configuration>\n");
        }
    } catch (Throwable $e) {
        error_log('[served-dir] could not harden ' . $dir . ': ' . $e->getMessage());
    }
}
