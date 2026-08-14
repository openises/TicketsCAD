<?php
/**
 * Shared config-lookup helper for the upload endpoint, extracted from
 * api/upload.php (GH#58, 2026-08-13) so it can be required directly by a
 * test without also loading that endpoint's request-dispatch code, which
 * hard-exits under CLI when there is no authenticated session.
 */

if (!function_exists('upload_config')) {

function upload_config($key, $default) {
    global $prefix;
    try {
        // PRE-RELEASE-FIXES #12 — settings column is `name`, not `key`.
        // Pre-fix this query always failed silently and every config knob
        // (max file size, disk warn/block %) was stuck on its default.
        //
        // GH#58 (2026-08-13): a SECOND, closely related bug in the same
        // function. db_fetch_value() wraps PDO's fetchColumn(), which
        // returns false — not null — when no row matches. This function
        // only guarded against null, so a missing settings row (every
        // install: upload_disk_block_pct/warn_pct/max_file_size are never
        // seeded anywhere, and there is no Settings UI for them) returned
        // false, not $default. (float) false is 0.0, so the disk-usage
        // block check `$stats['disk_used_pct'] >= $blockPct` became
        // `>= 0.0` — always true, since real disk usage is never exactly
        // zero. Every upload was blocked on every install, regardless of
        // file size, which is exactly what was reported: a 384KB photo
        // rejected with "threshold: 0%".
        $val = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$key]);
        return ($val !== null && $val !== false) ? $val : $default;
    } catch (Exception $e) {
        return $default;
    }
}

}
