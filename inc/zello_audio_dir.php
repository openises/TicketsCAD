<?php
/**
 * GHSA-x9x6-w4fg-pmcc — where Zello recordings live on disk.
 *
 * Recordings used to be written to cache/zello-audio/, inside the web root.
 * cache/ has to stay reachable for other things (tile cache), so a deny
 * rule can't cover it -- the only durable fix is to store new recordings
 * somewhere the web server never serves at all, the same move already made
 * for BACKUP_DIR and FE_KEYS_DIR.
 *
 * zello_audio_dir()        the private, non-served directory. All NEW
 *                          recordings are written here (ZelloProxyApp.php)
 *                          and read from here first (api/zello-audio.php).
 * zello_audio_dir_legacy() the old in-tree path. Recordings made before this
 *                          fix still live here; api/zello-audio.php falls
 *                          back to it so nothing already recorded is lost.
 *                          Never write new files here.
 *
 * Shared by the web app (config.php already defines NEWUI_ROOT) and the
 * standalone proxy daemon (proxy/zello-proxy.php requires config.php before
 * constructing ZelloProxyApp, so NEWUI_ROOT is defined there too).
 */

function zello_audio_dir(): string
{
    $root = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);
    return dirname($root) . '/zello-audio';
}

function zello_audio_dir_legacy(): string
{
    $root = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);
    return $root . '/cache/zello-audio';
}

/**
 * The directory ZelloProxyApp should actually write NEW recordings to.
 *
 * Prefers the private directory, creating it on first use. Falls back to
 * the legacy in-tree directory (still writable on every install today) if
 * the private one can't be created or written to -- e.g. a systemd unit
 * whose ReadWritePaths hasn't been updated yet for this host. A recording
 * that lands in the legacy, HTTP-denied-but-not-relocated directory beats
 * one that's silently never written at all; error_log() so it's visible.
 */
function zello_audio_write_dir(): string
{
    $primary = zello_audio_dir();
    if (!is_dir($primary)) {
        @mkdir($primary, 0775, true);
    }
    if (is_dir($primary) && is_writable($primary)) {
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
    return $legacy;
}

/**
 * Resolve a stored media_url / filename value to an absolute path,
 * checking the private directory first, then the legacy in-tree one.
 * Returns null if the file doesn't exist in either place.
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

    $primary = zello_audio_dir() . '/' . $filename;
    if (is_file($primary)) {
        return $primary;
    }

    $legacy = zello_audio_dir_legacy() . '/' . $filename;
    if (is_file($legacy)) {
        return $legacy;
    }

    return null;
}
