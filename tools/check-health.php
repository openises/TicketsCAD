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
 * IMPORTANT — CLI vs web user:
 *   On the CLI, writability/readability answers reflect the CLI USER
 *   (often root or your login), NOT the web server user. The
 *   authoritative check is api/health-check.php (or the System Health
 *   page → "File & Code Health" card), which runs AS the web user.
 *   The unreadable-by-others scan here is still valid for catching
 *   root-owned 0600/0700 files left behind by `git pull` as root.
 *
 * Exit codes: 0 = all ok, 1 = warnings only, 2 = at least one critical.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only. Use api/health-check.php from the web.\n";
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/health-check.php';

$root = health_check_root();
// Example web user for suggested commands. Debian/Ubuntu Apache default;
// the admin must adapt (apache, nginx, php-fpm pool user, ...).
$webUser = 'www-data';

echo "=== TicketsCAD NewUI — Installation Health ===\n";
echo "App root:     $root\n";
echo "Running as:   " . (_health_process_user() ?? 'unknown') . " (CLI)\n";
echo "NOTE: on the CLI, writability answers reflect the CLI user, not the\n";
echo "      web user. api/health-check.php / the System Health page is\n";
echo "      authoritative. The unreadable-file scan below is still valid.\n\n";

$all = health_check_all();
$suggestions = [];

// ── Directories ──────────────────────────────────────────────────────
echo "-- Required-writable directories --\n";
foreach (($all['dirs']['dirs'] ?? []) as $d) {
    $tag = '[OK]  ';
    if ($d['severity'] === 'warn') {
        $tag = '[WARN]';
    } elseif ($d['severity'] === 'critical') {
        $tag = '[CRIT]';
    }
    $ownerTxt = $d['owner'] !== null ? " owner={$d['owner']}" : '';
    $state = $d['exists']
        ? ($d['writable'] ? 'writable' : 'NOT WRITABLE')
        : 'missing';
    echo "$tag {$d['path']} — $state$ownerTxt";
    if ($d['note'] !== '') {
        echo "\n       {$d['note']}";
    }
    echo "\n";
    if ($d['severity'] !== 'ok') {
        $suggestions[] = "sudo chown -R $webUser:$webUser " . $d['abs'] . "   # adjust '$webUser' to YOUR web server user";
        if (!$d['exists']) {
            array_splice($suggestions, count($suggestions) - 1, 0, ["sudo mkdir -p " . $d['abs']]);
        }
    }
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
    $suggestions[] = "sudo chown -R $webUser:$webUser $root   # adjust '$webUser' to YOUR web server user";
    $suggestions[] = "sudo find $root -type d -exec chmod 755 {} \\;   # EXAMPLE — adapt to your policy";
    $suggestions[] = "sudo find $root -type f -exec chmod 644 {} \\;   # EXAMPLE — adapt to your policy";
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
    echo "[CRIT] STALE CODE: running NEWUI_VERSION=" . var_export($v['running'] ?? null, true)
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

// ── Summary + suggestions ────────────────────────────────────────────
$crit = (int) ($all['summary']['critical'] ?? 0);
$warn = (int) ($all['summary']['warn'] ?? 0);

echo "\n=== Summary: $crit critical, $warn warning(s) ===\n";

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
exit($warn > 0 ? 1 : 0);
