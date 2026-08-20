<?php
/**
 * NewUI v4.0 — Installation Health CLI (GH #41)
 *
 * Usage: php tools/check-health.php
 *
 * Runs the shared health library (inc/health-check.php) and prints a
 * human-readable [OK]/[WARN]/[CRIT] report. For every problem it ECHOES
 * a suggested fix command — it NEVER executes anything. Policy: detect
 * and warn, never auto-fix. If you manage permissions your own way,
 * keep doing that; this report just tells you when something looks
 * broken.
 *
 * IMPORTANT — WHOSE ACCESS IS BEING REPORTED:
 *   The question that matters is whether the WEB SERVER can write these
 *   directories, not whether you can. This tool works out which account
 *   serves the application (health_check_web_user()) and answers for that
 *   account, so running it over SSH as yourself gives the same verdict as
 *   the System Health page in a browser. When the web server's account
 *   cannot be established, directories are reported UNKNOWN — never ok,
 *   never critical. On a POSIX host that is resolved by adding
 *   define('NEWUI_WEB_USER', '<account>') to config.php; the exact wording
 *   printed at runtime comes from _health_undetermined_remedy(), which
 *   differs by platform so the tool never names a fix that cannot work.
 *
 *   Until 2026-07-31 this reported for whoever invoked it, so both live
 *   servers were told "5 critical" about three directories that were
 *   already correct, while the browser said OK.
 *
 * Exit codes: 0 = all ok, 1 = warnings and/or unknowns, 2 = at least one critical.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/health-check.php';

$root = health_check_root();
$wu   = health_check_web_user();

echo "=== TicketsCAD NewUI — Installation Health ===\n";
echo "App root:     $root\n";
echo "Running as:   " . (_health_process_user() ?? 'unknown') . " (CLI)\n";
echo "Web server:   " . ($wu['determined']
        ? (($wu['name'] ?? ('uid ' . $wu['uid'])) . '  (' . $wu['basis'] . ')')
        : 'COULD NOT BE DETERMINED') . "\n";
if (($wu['note'] ?? '') !== '') {
    echo wordwrap('NOTE: ' . $wu['note'], 72, "\n      ") . "\n";
}
echo "\n";

$all = health_check_all();
$suggestions = [];

// ── Directories ──────────────────────────────────────────────────────
echo "-- Required-writable directories"
    . ($wu['determined'] ? ' (as ' . ($wu['name'] ?? ('uid ' . $wu['uid'])) . ')' : '')
    . " --\n";
foreach (($all['dirs']['dirs'] ?? []) as $d) {
    $tag = '[OK]  ';
    if ($d['severity'] === 'warn') {
        $tag = '[WARN]';
    } elseif ($d['severity'] === 'critical') {
        $tag = '[CRIT]';
    } elseif ($d['severity'] === 'unknown') {
        $tag = '[UNKN]';
    }
    $ownerTxt = $d['owner'] !== null ? " owner={$d['owner']}" : '';
    if (($d['mode'] ?? null) !== null) {
        $ownerTxt .= " mode={$d['mode']}";
    }
    if (!$d['exists']) {
        $state = 'missing';
    } elseif ($d['writable'] === true) {
        $state = 'writable';
    } elseif ($d['writable'] === false) {
        $state = 'NOT WRITABLE';
    } else {
        $state = 'writability unknown';
    }
    echo "$tag {$d['path']} — $state$ownerTxt";
    if ($d['note'] !== '') {
        echo "\n       " . wordwrap($d['note'], 68, "\n       ");
    }
    echo "\n";

    // Only a genuine failure earns a command. A missing-but-creatable
    // directory is a warning about something the app does for itself, and an
    // UNKNOWN is a gap in what we could observe — neither is a reason to tell
    // an administrator to start changing ownership.
    if ($d['severity'] === 'critical') {
        if (!$d['exists']) {
            $suggestions[] = 'sudo -u ' . ($wu['name'] ?? 'WEB_USER') . ' mkdir -p ' . $d['abs'];
        }
        // chown -R is only ever suggested for a path that cannot carry a
        // repository with it — see _health_recursive_chown_safe().
        if ($wu['name'] !== null && _health_recursive_chown_safe($d['abs'])) {
            $suggestions[] = 'sudo chown -R ' . $wu['name'] . ':' . $wu['name'] . ' ' . $d['abs'];
        } elseif ($wu['name'] !== null) {
            $suggestions[] = '# ' . $d['abs'] . ' is the install directory or carries a .git — do NOT'
                . ' chown -R it (it breaks your next `git pull`). Grant write access to '
                . $wu['name'] . ' on that path only.';
        }
    }
}
if (($all['summary']['unknown'] ?? 0) > 0) {
    // The remedy text lives in the library (_health_undetermined_remedy) and is
    // printed once in the header, because it differs by platform and must not
    // drift into two versions — one of which would eventually name a fix that
    // does not work on the reader's system.
    echo "\n       The rows above marked [UNKN] are not findings. This tool could not\n";
    echo "       establish which account serves the application, so it declined to\n";
    echo "       guess rather than report a verdict it cannot support. See the NOTE\n";
    echo "       at the top of this report for how to resolve it on this system.\n";
}

// ── Unreadable files ─────────────────────────────────────────────────
echo "\n-- Unreadable files (assets/js/, api/, 20 most-recently-modified) --\n";
$un = $all['unreadable'] ?? [];
$unList = $un['unreadable'] ?? [];
if (empty($unList)) {
    echo "[OK]   No unreadable files found (" . ($un['scanned'] ?? 0) . " files probed).\n";
} else {
    foreach ($unList as $f) {
        echo "[CRIT] {$f['path']} — {$f['issue']} (will 404 / silently fail via the web)\n";
    }
    if (!empty($un['truncated'])) {
        echo "[CRIT] ...list truncated at 50 — there are more.\n";
    }
    // Fix READABILITY, not ownership. A recursive chown of the install dir
    // takes .git with it and the next `git pull` dies with "detected dubious
    // ownership" (git >= 2.35.2, CVE-2022-24765) — see docs/UPDATE-CHECKLIST.md.
    $suggestions[] = "sudo find $root -path '*/.git' -prune -o -type d -exec chmod 755 {} \\;   # EXAMPLE — adapt to your policy";
    $suggestions[] = "sudo find $root -path '*/.git' -prune -o -type f -exec chmod 644 {} \\;   # EXAMPLE — adapt to your policy";
    $suggestions[] = "# do NOT 'chown -R … $root' — that takes .git with it and breaks your next git pull";
}

// ── Opcache ──────────────────────────────────────────────────────────
echo "\n-- PHP opcache (this SAPI: " . ($all['opcache']['sapi'] ?? PHP_SAPI) . ") --\n";
$oc = $all['opcache'] ?? [];
if (!empty($oc['enabled'])) {
    $vt = $oc['validate_timestamps'];
    echo ($vt === false ? '[WARN]' : '[OK]  ')
        . " opcache enabled; validate_timestamps=" . var_export($vt, true)
        . "; revalidate_freq=" . var_export($oc['revalidate_freq'] ?? null, true) . "\n";
    if ($vt === false) {
        echo "       Code changes on disk will NOT take effect until the web server\n";
        echo "       or php-fpm is reloaded after every update.\n";
        $suggestions[] = "sudo systemctl reload apache2   # or: sudo systemctl reload php8.2-fpm";
    }
    echo "       NOTE: CLI opcache settings can differ from the web SAPI's —\n";
    echo "       check api/health-check.php for the web server's real values.\n";
} else {
    echo "[OK]   opcache not enabled for this SAPI (no staleness risk here).\n";
}

// ── Version match (stale-code detector) ──────────────────────────────
echo "\n-- Running code vs disk (opcache staleness) --\n";
$v = $all['version'] ?? [];
if (($v['severity'] ?? 'ok') === 'critical') {
    echo "[CRIT] STALE CODE: running version=" . var_export($v['running'] ?? null, true)
        . " but " . ($v['version_file'] ?? 'disk') . " says " . var_export($v['on_disk'] ?? null, true) . "\n";
    if (($v['probe_match'] ?? null) === false) {
        echo "[CRIT] STALE CODE: inc/health-check.php compiled build=" . ($v['probe_running'] ?? '?')
            . " but disk says " . ($v['probe_on_disk'] ?? '?') . "\n";
    }
    echo "       The server is executing an old compiled copy. Reload it:\n";
    $suggestions[] = "sudo systemctl reload apache2   # or: sudo systemctl reload php8.2-fpm";
} else {
    echo "[OK]   Running version " . var_export($v['running'] ?? null, true)
        . " matches " . ($v['version_file'] ?? 'disk')
        . " (" . var_export($v['on_disk'] ?? null, true) . ").\n";
    echo "       (On a fresh CLI process this always matches — the web check is\n";
    echo "       the one that catches a stale apache/php-fpm.)\n";
}
echo "       Reported version: " . var_export($v['reported'] ?? null, true)
    . " (source: " . (function_exists('newui_version_source') ? newui_version_source() : '?') . ")\n";
if (!empty($v['config_pin'])) {
    // Safe to act on, and safe BY CONSTRUCTION rather than by luck:
    // newui_version_config_pin() returns non-null only when the tracked
    // VERSION file was read successfully, so this advice cannot appear on an
    // install whose version would become unresolvable once the line is gone.
    //
    // Of the two ways to do it, REPLACING the line is the one to lead with.
    // Deleting it outright also works — the constant is redefined further down
    // the same file, when config.php requires inc/functions.php, which requires
    // inc/version.php — but that relies on nothing at the top level of config.php
    // reading NEWUI_VERSION in between. Replacing the line defines the constant
    // at exactly the point the old define stood, which is what the shipped
    // config.example.php does today, so no ordering question arises at all.
    // (asset_v(), the only reader of the constant anywhere in the tree, is a
    // function body and is safe under either form. Both were exercised against
    // this install before this wording was written.)
    echo "[INFO] config.php still pins define('NEWUI_VERSION', '" . $v['config_pin'] . "') from install time.\n";
    echo "       Harmless — the app reports the tracked VERSION file (" . var_export($v['reported'] ?? null, true) . ") —\n";
    echo "       but that line is dead and misleads anyone reading config.php.\n";
    $suggestions[] = "# optional: in config.php, REPLACE the define('NEWUI_VERSION', …) line with:"
        . "\n  #     require_once __DIR__ . '/inc/version.php';"
        . "\n  # (deleting it outright also works — inc/functions.php loads inc/version.php"
        . "\n  #  later in the same file — but replacing it keeps the constant defined at the"
        . "\n  #  same point, which is what config.example.php ships.)";
}

// ── Scheduled background jobs ────────────────────────────────────────
// A job nobody records is a job nobody can miss. Two of these had never
// executed for seven weeks because /etc/cron.d was a no-op on a host with
// no cron daemon, and there was no surface anywhere that would have said so.
$sj = $all['scheduled_jobs'] ?? [];
echo "\n-- Scheduled background jobs --\n";
if (empty($sj['jobs'])) {
    echo "[INFO] No background jobs registered.\n";
} else {
    foreach ($sj['jobs'] as $jb) {
        $tag = ($jb['severity'] === 'critical') ? '[CRIT]'
             : (($jb['severity'] === 'warn') ? '[WARN]' : '[OK]  ');
        $last = ($jb['state'] === 'never') ? 'NEVER RUN' : (string) $jb['last_ok_at'];
        echo "$tag {$jb['label']} — last success: $last\n";
        echo "       " . wordwrap((string) ($jb['note'] ?? ''), 68, "\n       ") . "\n";
    }
    if (($sj['severity'] ?? 'ok') !== 'ok') {
        echo "\n       " . wordwrap((string) ($sj['remedy'] ?? ''), 68, "\n       ") . "\n";
        // GH openises/TicketsCAD#18 — a Windows admin was previously told to
        // run systemctl, which does not exist on their machine, from the one
        // screen that had correctly identified the problem.
        if (($sj['platform'] ?? 'unix') === 'windows') {
            $suggestions[] = 'schtasks /Query /TN "TicketsCAD Background Jobs"   # "cannot find" means NOTHING is scheduled';
            $suggestions[] = '# then: see docs/INSTALL-WINDOWS-IIS.md "The background jobs need Task Scheduler"';
        } else {
            $suggestions[] = 'systemctl is-active cron   # "not-found" means NOTHING is scheduled';
            $suggestions[] = '# then: see docs/MAINTENANCE-RUNBOOK.md "Scheduled background jobs"';
        }
    }
    echo "       Stale-work cutoff: " . (int) ($sj['cutoff_min'] ?? 0)
       . " min (work older than this is expired, not run retroactively).\n";
}

// ── Backup archive location ──────────────────────────────────────────
// A backup is the most concentrated copy of everything in the system. Until
// v4.2.3 the default directory was inside the web root, and on 2026-07-30 a
// 110 MB database dump was downloaded from a live install by an unauthenticated
// GET. This reports where the archives actually are on THIS machine.
$bk = $all['backups'] ?? [];
echo "\n-- Backup archive location --\n";
if (empty($bk['checked'])) {
    echo "[INFO] Could not determine the backup directory.\n";
} else {
    $tag = (($bk['severity'] ?? 'ok') === 'critical') ? '[CRIT]' : '[OK]  ';
    echo "$tag " . ($bk['summary'] ?? '') . "\n";
    foreach (($bk['dirs'] ?? []) as $d) {
        echo '       ' . $d['dir']
            . ' — ' . (int) $d['archives'] . ' archive(s)'
            . ($d['active'] ? ', ACTIVE' : '')
            . ($d['web_served'] ? ', SERVED OVER HTTP' : '') . "\n";
    }
    if (($bk['severity'] ?? 'ok') === 'critical' && !empty($bk['remedy'])) {
        foreach (explode("\n", (string) $bk['remedy']) as $line) {
            echo '       ' . $line . "\n";
        }
    }
}

// ── Encryption key location (GHSA-3jmh-c6f6-64jc) ────────────────────
// The same mistake as the backups, one directory over: FE_KEYS_DIR was a
// sibling of the install directory on every platform, which is above the web
// root on Linux and inside C:\inetpub\wwwroot on a stock IIS box. Nothing is
// ever moved automatically — losing tfa.key un-enrols every 2FA user — so this
// is how an operator on the command line finds out.
$ky = $all['keys'] ?? [];
echo "\n-- Encryption key location --\n";
if (empty($ky['checked'])) {
    echo "[INFO] Could not determine the keys directory.\n";
} else {
    $sev = (string) ($ky['severity'] ?? 'ok');
    $tag = $sev === 'critical' ? '[CRIT]' : ($sev === 'warn' ? '[WARN]' : '[OK]  ');
    echo "$tag " . ($ky['summary'] ?? '') . "\n";
    if (!empty($ky['key_files'])) {
        echo '       key files: ' . implode(', ', (array) $ky['key_files']) . "\n";
    }
    foreach (($ky['notes'] ?? []) as $note) {
        echo '       ' . $note . "\n";
    }
    if ($sev !== 'ok' && !empty($ky['remedy'])) {
        foreach (explode("\n", (string) $ky['remedy']) as $line) {
            echo '       ' . $line . "\n";
        }
    }
}

// ── Cache directories: can they actually be written to? ──────────────
// GEOCODE_CACHE_DIR and TILE_CACHE_DIR are created lazily by whichever
// process reaches them first (a real web request, or a CLI/SSH diagnostic
// run as the operator) — see inc/geocode.php's geocode_cache_dir() docblock.
// On your-server.example.com the CLI won that race and every geocode lookup
// silently bypassed the cache for weeks; both caches are "best effort" by
// design, so nothing logged the failure. This is a real write test (write a
// probe file, read it back, delete it) whenever it is asked as the account
// that would actually be writing, not merely a permission-bit prediction.
foreach ([
    ['label' => 'Geocode lookup cache', 'data' => $all['geocode_cache_writable'] ?? []],
    ['label' => 'Map tile cache',       'data' => $all['tile_cache_writable'] ?? []],
] as $cacheCheck) {
    $cc = $cacheCheck['data'];
    echo "\n-- " . $cacheCheck['label'] . " — can it be written to? --\n";
    if (empty($cc['checked'])) {
        echo "[INFO] " . ($cc['note'] ?? 'not configured on this install') . "\n";
        continue;
    }
    $sev = (string) ($cc['severity'] ?? 'ok');
    $tag = $sev === 'critical' ? '[CRIT]' : ($sev === 'warn' ? '[WARN]' : ($sev === 'unknown' ? '[UNKN]' : '[OK]  '));
    $state = !($cc['exists'] ?? false) ? 'missing' : (($cc['writable'] ?? null) === true ? 'writable'
        : (($cc['writable'] ?? null) === false ? 'NOT WRITABLE' : 'writability unknown'));
    $ownerTxt = ($cc['owner'] ?? null) !== null ? " owner={$cc['owner']}" : '';
    if (($cc['mode'] ?? null) !== null) {
        $ownerTxt .= " mode={$cc['mode']}";
    }
    echo "$tag {$cc['dir']} — $state$ownerTxt\n";
    if (($cc['note'] ?? '') !== '') {
        echo '       ' . wordwrap((string) $cc['note'], 68, "\n       ") . "\n";
    }
    if ($sev === 'critical') {
        $suggestions[] = 'sudo php tools/fix-permissions.php   # creates/repairs both cache directories';
    }
}

// ── Can BOTH writers actually write there? ───────────────────────────
// Deliberately only on the command line. The backup directories have two
// writers — you, and the web server — and from a browser there is no way to
// know which human the other one is, so a web-side check cannot tell a
// correctly shared directory from one that has been quietly handed to the web
// server outright. Run from a shell, the account invoking this IS that human.
//
// This is the gap that let tools/deploy.sh break the operator's backup twice
// without anything reporting it. A recursive chown to the web server's account
// left the mode reading a perfectly correct 2770 — Linux does not strip setgid
// from a DIRECTORY on chown — and moved only the owner.
require_once __DIR__ . '/../inc/install-permissions.php';
$op = install_perm_operator();
echo "\n-- Directories the application writes to (as " .
    ($op['determined'] ? $op['name'] : 'unknown') . ") --\n";
$permPlan    = install_perm_plan($wu, $op);
$permBroken  = 0;
$permUnknown = 0;
foreach ($permPlan as $row) {
    if ($row['state'] === 'ok' || $row['state'] === 'absent') {
        continue;
    }
    if ($row['state'] === 'unknown' || $row['state'] === 'unsafe') {
        $permUnknown++;
        echo '[INFO] ' . $row['path'] . ' — ' . $row['reason'] . "\n";
        continue;
    }
    $permBroken++;
    echo '[CRIT] ' . $row['path'] . ' — ' . $row['reason'] . "\n";
}
if ($permBroken === 0 && $permUnknown === 0) {
    echo "[OK]   Nothing found that would stop a write.\n";
} elseif ($permBroken === 0) {
    // Not a clean bill of health — an answer was not reached. Same policy as
    // the directory section above: never report ok for something unmeasured.
    echo "[INFO] $permUnknown of " . count($permPlan) . " could not be evaluated on this host.\n";
} else {
    $suggestions[] = 'sudo php tools/fix-permissions.php   # scoped repair; never touches program files or .git';
}

// ── Web exposure ─────────────────────────────────────────────────────
// Needs a URL to probe, which the CLI does not have; the web SAPI does it
// properly. Point the operator at the two ways to get the answer.
$we = $all['web_exposure'] ?? [];
echo "\n-- Web exposure (are backups/, sql/, tools/ reachable over HTTP?) --\n";
if (empty($we['checked'])) {
    echo "[INFO] " . ($we['error'] ?? 'not probed from the command line') . "\n";
    echo "       Check it from a browser: Settings -> System Health, \"Web exposure\".\n";
    echo "       Or by hand (anything answering 200 is a problem):\n";
    echo "         curl -s -o /dev/null -w '%{http_code}\\n' https://your-site/sql/run_migrations.php\n";
    echo "         curl -s -o /dev/null -w '%{http_code}\\n' https://your-site/tools/\n";
    // Deliberately NOT `.../backups/`. A 403 on the directory is what a server
    // with directory listing off returns while it serves every archive inside;
    // @rjonesbsink measured exactly that on his own install. Ask for a file.
    echo "       For backups, ask for an ARCHIVE BY NAME - a 403 on the folder\n";
    echo "       says nothing about the files in it. Filenames: Settings -> Backup / Maintenance.\n";
    echo "         curl -s -o /dev/null -w '%{http_code}\\n' \\\n";
    echo "              https://your-site/backups/ticketscad-YYYYMMDD-HHMMSS.zip\n";
} else {
    foreach (($we['probes'] ?? []) as $p) {
        // 'untested' must never print [OK]. A backups path that could not be
        // asked for is not a pass, and the whole point of the 2026-08-02 fix is
        // that it stops looking like one.
        $st  = (string) ($p['state'] ?? '');
        $tag = $st === 'exposed'  ? '[CRIT]'
             : ($st === 'unknown'  ? '[WARN]'
             : ($st === 'untested' ? '[????]' : '[OK]  '));
        echo "$tag " . ($p['url'] ?? $p['path']) . ' → ' . var_export($p['status'] ?? null, true) . "\n";
        if ($st === 'untested' || $st === 'unknown') {
            $note = trim((string) ($p['note'] ?? ''));
            if ($note !== '') {
                echo "       " . wordwrap($note, 68, "\n       ") . "\n";
            }
        }
    }
    if (($we['severity'] ?? 'ok') === 'critical') {
        echo "       " . wordwrap((string) ($we['remedy'] ?? ''), 68, "\n       ") . "\n";
        $suggestions[] = '# see docs/WEB-SERVER-HARDENING.md — nginx and IIS need their own rules';
    }
}

// ── Summary + suggestions ────────────────────────────────────────────
// $permBroken is counted in: a backup directory neither writer can use is a
// real failure, and reporting it while exiting 0 is how it stays unnoticed.
$crit = (int) ($all['summary']['critical'] ?? 0) + $permBroken;
$warn = (int) ($all['summary']['warn'] ?? 0);
$unkn = (int) ($all['summary']['unknown'] ?? 0);

echo "\n=== Summary: $crit critical, $warn warning(s)"
    . ($unkn > 0 ? ", $unkn not determined" : '') . " ===\n";

if (!empty($suggestions)) {
    echo "\nSuggested fixes (NOT executed — review and adapt before running):\n";
    foreach (array_values(array_unique($suggestions)) as $s) {
        echo "  $s\n";
    }
    echo "\nIf you manage permissions your own way, keep doing that — this\n";
    echo "report only flags what looks broken. See docs/UPDATE-CHECKLIST.md.\n";
}

if ($crit > 0) {
    exit(2);
}
// An unknown is not a fault, but it is not a clean bill of health either —
// it means the report is incomplete and something is needed to complete it.
exit(($warn > 0 || $unkn > 0) ? 1 : 0);
