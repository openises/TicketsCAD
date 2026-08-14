<?php
/**
 * GHSA-x9x6-w4fg-pmcc — where Zello recordings live on disk.
 *
 * ── HISTORY (two rounds, same root cause as BACKUP_DIR and FE_KEYS_DIR) ────
 *
 * Round 1: recordings were written to cache/zello-audio/, inside the web
 * root — served directly, the same shape as the 2026-07-30 backups exposure.
 *
 * Round 2 (2026-08-13) moved them to dirname(NEWUI_ROOT) . '/zello-audio' —
 * "a sibling of the app root", described in this file as "the same move
 * already made for BACKUP_DIR and FE_KEYS_DIR". That claim was already false
 * when written: both of those had, by then, moved PAST the sibling-of-root
 * shape to a platform-aware default (see inc/served-dir.php's own history —
 * BACKUP_DIR at commit 5b88fbb, FE_KEYS_DIR at GHSA-3jmh-c6f6-64jc), because
 * a sibling of the app root is only safe on POSIX. On a stock Windows/IIS
 * install (`C:\inetpub\wwwroot\<app>`), the sibling is `C:\inetpub\wwwroot`
 * itself — the physical path of the Default Web Site, bound to port 80.
 * @rjonesbsink (round 1's own reporter) proved it: after upgrading and
 * re-running the migration, 210 recordings that had been reachable only on
 * a local, unfirewalled port became reachable, complete and unauthenticated,
 * on the firewalled port every Windows box in this fleet answers on.
 *
 * This round brings zello-audio up to the SAME standard backups and keys
 * already use — a platform-aware default (%ProgramData%\TicketsCAD\
 * zello-audio on Windows; unchanged sibling-of-root on POSIX, which was
 * always correct there) PLUS unconditional served_dir_harden(), so a
 * directory-placement mistake and a missing deny file have to BOTH happen
 * before anything leaks, not either one alone.
 *
 * zello_audio_dir()               the current, platform-aware private
 *                                  directory. All NEW recordings are written
 *                                  here (ZelloProxyApp.php) and read from
 *                                  here first (api/zello-audio.php).
 * zello_audio_dir_sibling_legacy() round 2's directory (sibling of app
 *                                  root). Safe on POSIX — identical to
 *                                  zello_audio_dir() there — but the exposed
 *                                  one on Windows. Kept as a read fallback so
 *                                  an install that already ran the round-2
 *                                  migration (like the reporter's) doesn't
 *                                  lose access to what it already moved;
 *                                  never written to again.
 * zello_audio_dir_legacy()        round 1's directory (inside cache/, inside
 *                                  the web root). Still a read fallback for
 *                                  the same reason. Never write new files
 *                                  to either legacy location.
 */

require_once __DIR__ . '/served-dir.php';

/**
 * @param string|null $appRoot Defaults to NEWUI_ROOT. Parameterized (matching
 *                     fe_default_keys_dir_for()'s shape) so a test can assert
 *                     against an ARBITRARY layout — e.g. a fake
 *                     C:\inetpub\wwwroot\TicketsV4 — from any CI machine,
 *                     POSIX or Windows. That is not optional: a test that can
 *                     only see the answer on its own platform is exactly how
 *                     the sibling-of-root bug shipped in the first place (see
 *                     inc/served-dir.php's own docblock).
 */
function zello_audio_dir(?string $appRoot = null, ?bool $windows = null): string
{
    $root = $appRoot ?? (defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__));
    if ($windows === null) {
        $windows = (DIRECTORY_SEPARATOR === '\\');
    }
    if (!$windows) {
        // Unchanged from round 2 — correct here, and identical to the path
        // every pre-this-fix POSIX install already uses, so this is a no-op
        // upgrade on Linux/Docker.
        return served_dir_parent_of($root, false) . '/zello-audio';
    }
    // %ProgramData% is set by Windows itself and inherited by IIS worker
    // processes and the CLI alike, so the web UI and the CLI relocation
    // script resolve the same directory. Same base backups/keys use, so an
    // operator has one place to look, not three.
    return served_dir_program_data() . '\\TicketsCAD\\zello-audio';
}

function zello_audio_dir_sibling_legacy(?string $appRoot = null, ?bool $windows = null): string
{
    $root = $appRoot ?? (defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__));
    if ($windows === null) {
        $windows = (DIRECTORY_SEPARATOR === '\\');
    }
    return served_dir_parent_of($root, $windows) . ($windows ? '\zello-audio' : '/zello-audio');
}

function zello_audio_dir_legacy(?string $appRoot = null): string
{
    $root = $appRoot ?? (defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__));
    return rtrim(str_replace('\\', '/', $root), '/') . '/cache/zello-audio';
}

/**
 * Put deny rules beside the recordings, wherever they are.
 *
 * Unconditional, like fe_harden_keys_dir() and unlike backup_harden_dir():
 * a recorded voice message has no legitimate reachable-over-HTTP state, so
 * there is no case where skipping the fence is correct. Safe to call on
 * every request that touches the directory — served_dir_harden() no-ops
 * once the deny files exist.
 */
function zello_audio_harden_dir(string $dir): void
{
    served_dir_harden($dir, 'Zello voice-message recordings', true);
}

/**
 * The directory ZelloProxyApp should actually write NEW recordings to.
 *
 * Prefers the private directory, creating and hardening it on first use.
 * Falls back to the legacy in-tree directory (still writable on every
 * install today) if the private one can't be created or written to — e.g. a
 * systemd unit whose ReadWritePaths hasn't been updated yet for this host.
 * A recording that lands in the legacy, HTTP-denied-but-not-relocated
 * directory beats one that's silently never written at all; error_log() so
 * it's visible.
 */
function zello_audio_write_dir(): string
{
    $primary = zello_audio_dir();
    if (!is_dir($primary)) {
        @mkdir($primary, 0775, true);
    }
    if (is_dir($primary) && is_writable($primary)) {
        zello_audio_harden_dir($primary);
        return $primary;
    }

    error_log('[zello-audio] private directory not writable (' . $primary . '); '
        . 'falling back to the legacy in-tree directory. If this host runs the '
        . 'proxy under systemd with ProtectSystem=strict, add ' . $primary
        . ' to ReadWritePaths= and restart the service.');

    $legacy = zello_audio_dir_legacy();
    if (!is_dir($legacy)) {
        @mkdir($legacy, 0775, true);
    }
    zello_audio_harden_dir($legacy);
    return $legacy;
}

/**
 * Resolve a stored media_url / filename value to an absolute path, checking
 * the private directory first, then round 2's sibling location, then round
 * 1's in-tree location. Returns null if the file doesn't exist in any of
 * them.
 *
 * Accepts both the new bare-filename form and the old
 * "cache/zello-audio/<file>" form so pre-fix rows keep working.
 */
function zello_audio_resolve(string $storedValue): ?string
{
    $filename = basename($storedValue);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return null;
    }

    foreach ([zello_audio_dir(), zello_audio_dir_sibling_legacy(), zello_audio_dir_legacy()] as $dir) {
        $candidate = $dir . '/' . $filename;
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}
