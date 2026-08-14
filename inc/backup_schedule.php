<?php
/**
 * Phase 122 (2026-07-25) — automatic, verified, restorable backups.
 *
 * Why this exists. A new user lost power with MySQL running, four tables were
 * damaged, and the honest advice at the end of a long recovery was "turn on
 * backups" — advice given three times in one week to three different people.
 * That is a tell that the PRODUCT should be doing this, not the operator. The
 * audience runs TicketsCAD on laptops, Raspberry Pis and mini-PCs, frequently
 * for emergency response. Their hardware will lose power.
 *
 * Three properties this aims for, in order:
 *   1. ON BY DEFAULT. A backup nobody enabled is the backup nobody has.
 *   2. VERIFIED. A backup that has never been read back is a hypothesis, not a
 *      backup — backup_verify() opens every archive it writes and checks the
 *      SQL inside really contains the schema.
 *   3. RESTORABLE. Until Phase 122 there was no restore tool at all; see
 *      tools/restore.php. A backup you cannot restore is a rounding error.
 *
 * Scheduling without cron. Most of these installs are Windows/XAMPP where cron
 * does not exist and Task Scheduler is never set up. So scheduling is
 * OPPORTUNISTIC: backup_maybe_run_opportunistic() is cheap (one settings read
 * plus a timestamp compare), safe to call on ordinary requests, and guarded by
 * a lock so concurrent requests cannot stampede. Operators who *do* have
 * cron/Task Scheduler should call tools/backup_run.php and get exact timing.
 *
 * Settings (settings table, read via get_variable — NOT the `config` table):
 *   backup_auto_enabled     '1' (default ON)
 *   backup_opportunistic    '1' (default ON — the no-scheduler fallback)
 *   backup_interval_hours   '24'
 *   backup_retention_count  '7'
 *   backup_retention_days   '0' (0 = keep by count only)
 *   backup_min_free_mb      '1024' — refuse to write below this much free space
 *   backup_max_dir_mb       '2048' — hard ceiling on the backup directory
 *   backup_dir              '' → BACKUP_DIR
 *   backup_last_run_at      unix ts of last ATTEMPT
 *   backup_last_ok_at       unix ts of last VERIFIED success
 *   backup_last_status      'ok' | 'failed: …' | 'skipped: …'
 *
 * ── Phase 126 (2026-07-29): backups must not be able to cause the outage ──
 *
 * Eric: "I'm concerned we risk filling someone's disk space. We don't want to
 * create an outage because we consumed the disk space." That is the right
 * worry for this audience — TicketsCAD dispatches live fire/EMS/SAR incidents
 * off laptops, mini-PCs and Raspberry Pis. A full disk does not degrade the
 * app, it stops it, potentially mid-incident. So a backup is now a GUARDED
 * operation, and the ordering of the guarantees is deliberate:
 *
 *   1. The running system outranks the backup. If writing would push free
 *      space below the floor, we REFUSE and say so loudly. A backup that
 *      fails visibly is infinitely better than one that fills the disk.
 *   2. Retention never destroys the last line of defence. Every pruning path
 *      takes a $minKeep floor and will not delete the newest archive — not
 *      for age, not for the count, not to satisfy the size cap. Running out
 *      of room is a reason to stop making backups, never a reason to end up
 *      with none.
 *   3. Undetectable is not the same as unsafe. If free space cannot be read
 *      (open_basedir, exotic filesystem, disk_free_space disabled) we log it
 *      and PROCEED — refusing to back up because we could not read a number
 *      would trade a hypothetical disk-full for a certain no-backup.
 *
 * The space/retention decisions are pure functions (backup_space_verdict,
 * backup_retention_plan) precisely so the boundary cases — exactly at the
 * floor, only one backup exists, free space unreadable — are unit-testable
 * without a full disk to hand.
 */

require_once __DIR__ . '/backup.php';

const BACKUP_DEFAULT_INTERVAL_HOURS = 24;
const BACKUP_DEFAULT_RETENTION      = 7;
/** 0 = no age-based expiry; keep purely by count. */
const BACKUP_DEFAULT_RETENTION_DAYS = 0;
/** Free space that must REMAIN after a backup is written. */
const BACKUP_DEFAULT_MIN_FREE_MB    = 1024;
/**
 * Ceiling on everything this app has written into the backup directory.
 *
 * 5 GB is chosen against the real size range in docs/BACKUP-RECOVERY-RUNBOOK.md
 * ("typically 100 MB – 5 GB" of database, compressing well below that). At 5 GB
 * the ceiling never binds on a small department install, and on a large one it
 * quietly reduces how many copies are kept rather than refusing to back up. An
 * earlier 2 GB default would have hard-refused every backup on a big install —
 * a guard that turns into "you now have no backups" is not a guard.
 */
const BACKUP_DEFAULT_MAX_DIR_MB     = 5120;
/** Never leave the operator with zero backups, whatever the policy says. */
const BACKUP_MIN_KEEP               = 1;

/** Read a backup setting with a default (settings table via get_variable). */
function backup_setting(string $name, string $default = ''): string {
    if (!function_exists('get_variable')) return $default;
    $v = get_variable($name);
    // get_variable() returns FALSE for an absent setting (not null, not ''), so
    // all three must count as "unset" and fall back to the default. Missing this
    // made backup_auto_enabled() return false on every fresh install — i.e.
    // automatic backups would have shipped silently OFF, which is precisely the
    // failure this feature exists to prevent. Caught by test_backup_schedule.php.
    if ($v === null || $v === false || $v === '') return $default;
    return (string) $v;
}

/** Persist a backup setting. Best-effort — never fatal to a backup run. */
function backup_setting_set(string $name, string $value): void {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [$name, $value]
        );
    } catch (Throwable $e) {
        error_log('[backup] could not persist setting ' . $name . ': ' . $e->getMessage());
    }
}

function backup_auto_enabled(): bool {
    return backup_setting('backup_auto_enabled', '1') === '1';
}

function backup_interval_hours(): int {
    $h = (int) backup_setting('backup_interval_hours', (string) BACKUP_DEFAULT_INTERVAL_HOURS);
    return $h > 0 ? $h : BACKUP_DEFAULT_INTERVAL_HOURS;
}

function backup_retention_count(): int {
    $n = (int) backup_setting('backup_retention_count', (string) BACKUP_DEFAULT_RETENTION);
    return $n > 0 ? $n : BACKUP_DEFAULT_RETENTION;
}

/**
 * Where this install writes new archives.
 *
 * Preference order:
 *   1. The operator's explicit `backup_dir` setting, if any.
 *   2. BACKUP_DIR (outside the web root) once it exists — so the choice is
 *      stable from the moment the directory is there, whichever user is asking.
 *   3. BACKUP_DIR when it can be CREATED, i.e. its nearest existing ancestor is
 *      writable. The ancestor walk matters on Windows, where the default is
 *      %ProgramData%\TicketsCAD\backups and neither of the last two segments
 *      exists on a fresh install — testing only the immediate parent said "not
 *      creatable" for a directory mkdir(…, true) makes without complaint.
 *   4. A historical location that already exists, when step 3 is impossible —
 *      some shared hosting gives the account no writable directory outside the
 *      web root at all. Backing up to a served directory is bad; NOT backing up
 *      is worse, and this path is reported as a failure on Settings → System Health
 *      with the fix, plus backup_harden_dir() drops deny rules beside the
 *      archives.
 *   5. BACKUP_DIR, so the failure message names the directory we actually want.
 */
function backup_dir(): string {
    $d = backup_setting('backup_dir', '');
    if ($d !== '') return $d;
    if (is_dir(BACKUP_DIR)) return BACKUP_DIR;
    if (backup_dir_creatable(BACKUP_DIR)) return BACKUP_DIR;

    foreach (backup_legacy_dirs() as $legacy) {
        if (is_dir($legacy)) return $legacy;
    }
    return BACKUP_DIR;
}

/**
 * Can mkdir($dir, …, true) plausibly succeed? Walks up to the nearest existing
 * ancestor and asks whether THAT is writable, because the recursive mkdir the
 * writers use will create every missing segment beneath it.
 */
function backup_dir_creatable(string $dir): bool {
    $p    = rtrim(str_replace('\\', '/', $dir), '/');
    $seen = 0;
    while ($p !== '' && $seen++ < 24) {
        if (is_dir($p)) return is_writable($p);
        $up = dirname($p);
        if ($up === $p) break;
        $p = $up;
    }
    return false;
}

/**
 * Locations this application has defaulted to in the past, newest first, minus
 * whatever the current default is.
 *
 * There are two, and the second one is the reason this function exists rather
 * than a single constant. v4.2.3's `dirname(NEWUI_ROOT)/backups` is correct on
 * POSIX and lands inside C:\inetpub\wwwroot on a stock Windows/IIS install, so
 * a Windows install that ran 4.2.3 has archives in a directory this version no
 * longer writes to. Forgetting it would orphan them: absent from the
 * Settings → Backup / Maintenance list, absent from the System Health page, and
 * still downloadable by anyone on port 80. They must stay visible precisely
 * BECAUSE that location is wrong.
 */
function backup_legacy_dirs(): array {
    $cur  = rtrim(str_replace('\\', '/', BACKUP_DIR), '/');
    $out  = [];
    foreach ([
        defined('BACKUP_DIR_LEGACY_SIBLING') ? BACKUP_DIR_LEGACY_SIBLING : null,  // 4.2.3
        defined('BACKUP_DIR_LEGACY') ? BACKUP_DIR_LEGACY : null,                  // pre-4.2.3
    ] as $d) {
        if ($d === null) continue;
        if (rtrim(str_replace('\\', '/', $d), '/') === $cur) continue;            // == BACKUP_DIR on POSIX
        $out[] = $d;
    }
    return $out;
}

/**
 * Every directory that may hold archives THIS install has written: the active
 * one, the current default, plus every historical default that still exists.
 *
 * History listing and downloads read from all of them, so moving the default
 * out of the web root never makes an operator's existing restore points vanish
 * from the UI. Pruning deliberately does NOT use this — retention only ever
 * deletes from the ACTIVE directory, so an update cannot delete archives the
 * operator has not been told about yet.
 */
function backup_dirs_all(): array {
    $dirs = [backup_dir()];
    foreach (backup_legacy_dirs() as $legacy) {
        if (is_dir($legacy)) {
            $dirs[] = $legacy;
        }
    }
    if (is_dir(BACKUP_DIR)) {
        $dirs[] = BACKUP_DIR;
    }
    $seen = [];
    $out  = [];
    foreach ($dirs as $d) {
        $k = rtrim(str_replace('\\', '/', $d), '/');
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $d;
    }
    return $out;
}

/**
 * The in-request fallback scheduler. Separate from backup_auto_enabled so an
 * operator who HAS cron can keep automatic backups on while switching the
 * opportunistic path off, and so the whole in-request mechanism has one
 * unambiguous off switch.
 */
function backup_opportunistic_enabled(): bool {
    return backup_setting('backup_opportunistic', '1') === '1';
}

/** Age-based expiry in days. 0 = disabled (keep by count only). */
function backup_retention_days(): int {
    $d = (int) backup_setting('backup_retention_days', (string) BACKUP_DEFAULT_RETENTION_DAYS);
    return $d > 0 ? $d : 0;
}

/** Free space that must remain AFTER a backup, in bytes. 0 disables the floor. */
function backup_min_free_bytes(): int {
    $mb = backup_setting('backup_min_free_mb', (string) BACKUP_DEFAULT_MIN_FREE_MB);
    // An explicit '0' is a deliberate "I accept the risk" and must survive; only
    // a negative/garbage value falls back to the default.
    $n = (int) $mb;
    if ($n < 0) $n = BACKUP_DEFAULT_MIN_FREE_MB;
    return $n * 1024 * 1024;
}

/** Ceiling on the backup directory, in bytes. 0 = uncapped. */
function backup_max_dir_bytes(): int {
    $n = (int) backup_setting('backup_max_dir_mb', (string) BACKUP_DEFAULT_MAX_DIR_MB);
    if ($n < 0) $n = BACKUP_DEFAULT_MAX_DIR_MB;
    return $n * 1024 * 1024;
}

/**
 * Archives THIS APPLICATION wrote, and nothing else.
 *
 * backup_dir is operator-configurable free text, so it may well point at a
 * folder that already holds other things (/var/backups, a NAS share, a
 * Dropbox folder). The pruner used to glob '*.{zip,gz,sql}', which would have
 * deleted an operator's unrelated archives as "old backups". Matching our own
 * 'ticketscad-' prefix keeps retention confined to files we created.
 *
 * @return array<string,array{mtime:int,size:int}> keyed by absolute path
 */
function backup_archives(string $dir): array {
    $dir = rtrim($dir, '/\\');
    if ($dir === '' || !is_dir($dir)) return [];
    $files = glob($dir . '/ticketscad-*.{zip,gz,sql}', GLOB_BRACE);
    if (!is_array($files)) return [];
    $out = [];
    foreach ($files as $f) {
        if (!is_file($f)) continue;
        $out[$f] = ['mtime' => (int) @filemtime($f), 'size' => (int) @filesize($f)];
    }
    return $out;
}

/** Count + total bytes of our archives in $dir. */
function backup_dir_usage(string $dir): array {
    $files = backup_archives($dir);
    $bytes = 0;
    foreach ($files as $m) $bytes += $m['size'];
    return ['count' => count($files), 'bytes' => $bytes];
}

/**
 * Free bytes on the filesystem holding $dir, or NULL when undeterminable.
 * NULL is a first-class answer here — see guarantee (3) in the file header.
 */
function backup_free_bytes(string $dir): ?int {
    // Walk up to the nearest existing ancestor: the backup directory itself may
    // not exist yet on a first run, and disk_free_space() on a missing path
    // returns false, which would read as "undeterminable" for a perfectly
    // measurable filesystem.
    $probe = rtrim($dir, '/\\');
    $guard = 0;
    while ($probe !== '' && !@is_dir($probe) && $guard++ < 64) {
        $parent = dirname($probe);
        if ($parent === $probe) break;
        $probe = $parent;
    }
    if ($probe === '' || !@is_dir($probe)) return null;
    $free = @disk_free_space($probe);
    if ($free === false || $free === null || !is_numeric($free)) return null;
    return (int) $free;
}

/**
 * PURE disk-space decision. No filesystem, no clock, no DB — so "exactly at the
 * floor" and "free space unreadable" are testable without a full disk.
 *
 * @param ?int $freeBytes  NULL = could not be determined
 * @param ?int $needBytes  NULL = could not be estimated (treated as 0)
 * @param int  $floorBytes free space that must REMAIN after the write
 * @return array{ok:bool,undetermined:bool,reason:string}
 */
function backup_space_verdict(?int $freeBytes, ?int $needBytes, int $floorBytes): array {
    if ($freeBytes === null) {
        return ['ok' => true, 'undetermined' => true,
                'reason' => 'free disk space could not be determined — proceeding without the space guard'];
    }
    $need  = ($needBytes !== null && $needBytes > 0) ? $needBytes : 0;
    $after = $freeBytes - $need;
    // >= is deliberate: landing exactly ON the floor is within policy. The floor
    // is "space that must remain", not "space that must be exceeded".
    if ($floorBytes > 0 && $after < $floorBytes) {
        return ['ok' => false, 'undetermined' => false,
                'reason' => 'not enough disk space: ' . backup_format_size($freeBytes) . ' free, this backup needs about '
                            . backup_format_size($need) . ', which would leave ' . backup_format_size(max(0, $after))
                            . ' — below the ' . backup_format_size($floorBytes) . ' reserve. Free some space, lower the'
                            . ' reserve, or reduce how many backups are kept.'];
    }
    return ['ok' => true, 'undetermined' => false,
            'reason' => 'space ok: ' . backup_format_size($freeBytes) . ' free, about '
                        . backup_format_size($need) . ' needed'];
}

/**
 * PURE retention math: given the archives, decide which to delete.
 *
 * Applies, in order: age expiry, then the keep-count, then the size cap
 * (oldest first). $minKeep is a hard floor honoured by ALL THREE — the newest
 * $minKeep archives are never selected for deletion, so an install can never be
 * pruned down to zero backups.
 *
 * @param array<string,array{mtime:int,size:int}> $files
 * @return array{delete:string[],kept_bytes:int,kept_count:int,over_cap:bool}
 */
function backup_retention_plan(array $files, int $keepCount, int $keepDays,
                               int $capBytes, int $now, int $minKeep = BACKUP_MIN_KEEP): array {
    // Newest first — index 0..$minKeep-1 are untouchable.
    uasort($files, static function ($a, $b) { return $b['mtime'] <=> $a['mtime']; });
    $paths  = array_keys($files);
    $total  = count($paths);
    $minKeep = max(0, $minKeep);
    $delete = [];

    $protected = static function (int $idx) use ($minKeep) { return $idx < $minKeep; };

    // 1. Age expiry.
    if ($keepDays > 0) {
        $cutoff = $now - ($keepDays * 86400);
        foreach ($paths as $i => $p) {
            if ($protected($i)) continue;
            if ($files[$p]['mtime'] > 0 && $files[$p]['mtime'] < $cutoff) $delete[$p] = true;
        }
    }

    // 2. Keep-count: anything past the Nth newest survivor.
    if ($keepCount > 0) {
        $rank = 0;
        foreach ($paths as $i => $p) {
            if (isset($delete[$p])) continue;
            $rank++;
            if ($rank > max($keepCount, $minKeep) && !$protected($i)) $delete[$p] = true;
        }
    }

    // 3. Size cap: keep dropping the oldest survivor until we fit.
    $survivorBytes = 0;
    foreach ($paths as $p) if (!isset($delete[$p])) $survivorBytes += $files[$p]['size'];
    if ($capBytes > 0 && $survivorBytes > $capBytes) {
        for ($i = $total - 1; $i >= 0; $i--) {
            if ($survivorBytes <= $capBytes) break;
            $p = $paths[$i];
            if (isset($delete[$p]) || $protected($i)) continue;
            $delete[$p] = true;
            $survivorBytes -= $files[$p]['size'];
        }
    }

    $keptCount = $total - count($delete);
    return [
        'delete'     => array_keys($delete),
        'kept_bytes' => $survivorBytes,
        'kept_count' => $keptCount,
        // TRUE means: policy has done all it may, and the directory is STILL
        // over its ceiling. The caller must surface this rather than delete the
        // protected archive.
        'over_cap'   => ($capBytes > 0 && $survivorBytes > $capBytes),
    ];
}

/**
 * PURE directory-ceiling decision — bounds how much of the disk backups can
 * EVER take, independent of how big the disk is.
 *
 * ONE EXCEPTION, and it is deliberate: the ceiling may not block the FIRST
 * backup. When the directory is empty, refusing leaves the operator with
 * nothing at all — the precise outcome this subsystem exists to prevent. A
 * database larger than the configured ceiling is a misconfiguration to
 * surface, not a reason to have zero backups. The free-space floor is checked
 * separately and still applies, so the disk stays protected either way.
 *
 * @param int  $dirBytes  bytes already stored (our archives only)
 * @param int  $dirCount  how many archives already exist
 * @param ?int $needBytes estimated size of the new archive (NULL = unknown)
 * @param int  $capBytes  the ceiling; 0 = uncapped
 * @return array{ok:bool,reason:string,first:bool}
 */
function backup_cap_verdict(int $dirBytes, int $dirCount, ?int $needBytes, int $capBytes): array {
    if ($capBytes <= 0) {
        return ['ok' => true, 'first' => false, 'reason' => 'no folder limit set'];
    }
    if ($dirCount <= 0) {
        return ['ok' => true, 'first' => true,
                'reason' => 'first backup — the folder limit never blocks having no backup at all'];
    }
    $need      = $needBytes !== null ? max(0, $needBytes) : 0;
    $projected = $dirBytes + $need;
    if ($projected > $capBytes) {
        return ['ok' => false, 'first' => false,
                'reason' => 'backup folder limit reached: ' . backup_format_size($dirBytes)
                    . ' already stored and this backup needs about ' . backup_format_size($need)
                    . ', which would exceed the ' . backup_format_size($capBytes)
                    . ' limit. Raise the limit, keep fewer backups, or move older archives off'
                    . ' this machine.'];
    }
    return ['ok' => true, 'first' => false, 'reason' => 'within the folder limit'];
}

/**
 * Estimate what one backup will consume. Two different numbers because they can
 * land on two different filesystems: the uncompressed .sql goes to the system
 * temp directory, the compressed archive goes to the backup directory.
 *
 * @return array{sql:?int,archive:?int} NULL where it could not be estimated
 */
function backup_estimate_bytes(?string $dir = null): array {
    $out = ['sql' => null, 'archive' => null];

    try {
        if (function_exists('db_fetch_value')) {
            $n = db_fetch_value(
                "SELECT SUM(data_length + index_length) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()"
            );
            if ($n !== null && $n !== false && is_numeric($n)) {
                // A text dump is typically larger than the on-disk footprint
                // (numbers and dates become ASCII, every row carries syntax).
                // 1.6x is a deliberate over-estimate: refusing a borderline
                // backup is recoverable, filling the disk is not.
                $out['sql'] = (int) round(((float) $n) * 1.6);
            }
        }
    } catch (Throwable $e) {
        error_log('[backup] could not estimate database size: ' . $e->getMessage());
    }

    // The best predictor of the next archive is the last one we actually wrote.
    $files = backup_archives($dir ?: backup_dir());
    if (!empty($files)) {
        $largest = 0;
        foreach ($files as $m) if ($m['size'] > $largest) $largest = $m['size'];
        if ($largest > 0) $out['archive'] = (int) round($largest * 1.2);
    } elseif ($out['sql'] !== null) {
        // No history yet: assume compression buys us little (worst case).
        $out['archive'] = (int) round($out['sql'] * 0.5);
    }

    return $out;
}

/**
 * Pure schedule decision, so the rule is unit-testable without a clock or DB.
 * Due when automatic backups are on AND we have never run, or the interval has
 * elapsed.
 */
function backup_is_due_at(bool $enabled, int $lastRunAt, int $intervalHours, int $now): bool {
    if (!$enabled) return false;
    if ($lastRunAt <= 0) return true;                 // never run → due now
    return ($now - $lastRunAt) >= ($intervalHours * 3600);
}

/** Is a scheduled backup due right now? */
function backup_is_due(): bool {
    return backup_is_due_at(
        backup_auto_enabled(),
        (int) backup_setting('backup_last_run_at', '0'),
        backup_interval_hours(),
        time()
    );
}

/**
 * Open the archive we just wrote and prove it contains a real SQL dump. This is
 * what separates "a file exists" from "a backup exists". Returns [ok, detail].
 */
function backup_verify(string $archivePath): array {
    if (!is_file($archivePath)) return [false, 'archive missing'];
    $size = filesize($archivePath);
    // Only reject an EMPTY file here. Verification must rest on the CONTENT of
    // the dump (below), not on a guessed minimum size: a small database
    // compresses to very little, and an arbitrary byte threshold would reject
    // perfectly good backups while telling us nothing about whether the dump is
    // actually usable.
    if ($size === false || $size < 32) return [false, "archive is empty ({$size} bytes)"];

    $sql = null;
    if (substr($archivePath, -4) === '.zip' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) return [false, 'archive will not open'];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (substr($n, -4) === '.sql') { $sql = $zip->getFromIndex($i); break; }
        }
        $zip->close();
        if ($sql === null) return [false, 'archive contains no .sql dump'];
    } elseif (substr($archivePath, -3) === '.gz' && function_exists('gzopen')) {
        $fh = gzopen($archivePath, 'rb');
        if (!$fh) return [false, 'archive will not open'];
        $sql = gzread($fh, 262144);   // first 256 KB is plenty to prove structure
        gzclose($fh);
    } else {
        $sql = file_get_contents($archivePath, false, null, 0, 262144);
    }

    if (!is_string($sql) || $sql === '') return [false, 'dump is empty'];
    if (stripos($sql, 'CREATE TABLE') === false) {
        return [false, 'dump contains no CREATE TABLE statements'];
    }
    return [true, 'verified: readable archive containing schema'];
}

/**
 * Delete the oldest archives beyond the retention count. Returns count pruned.
 *
 * Kept as a thin wrapper over the full policy so existing callers and tests
 * keep working; the count is only one of the three retention rules now.
 */
function backup_prune(string $dir, int $keep): int {
    $r = backup_apply_retention($dir, $keep, 0, 0);
    return $r['pruned'];
}

/**
 * Apply the whole retention policy — age, count and size cap — to $dir.
 *
 * Never deletes the newest BACKUP_MIN_KEEP archive(s). If the directory is
 * still over its cap after everything the policy is allowed to delete, that is
 * reported in 'over_cap' for the caller to SURFACE — it is never resolved by
 * deleting the last backup.
 *
 * @return array{pruned:int,freed:int,over_cap:bool,kept_count:int,kept_bytes:int}
 */
function backup_apply_retention(string $dir, ?int $keepCount = null, ?int $keepDays = null,
                                ?int $capBytes = null, ?int $now = null): array {
    $files = backup_archives($dir);
    $plan  = backup_retention_plan(
        $files,
        $keepCount !== null ? $keepCount : backup_retention_count(),
        $keepDays  !== null ? $keepDays  : backup_retention_days(),
        $capBytes  !== null ? $capBytes  : backup_max_dir_bytes(),
        $now       !== null ? $now       : time()
    );

    $pruned = 0; $freed = 0;
    foreach ($plan['delete'] as $old) {
        $size = isset($files[$old]) ? $files[$old]['size'] : 0;
        if (@unlink($old)) { $pruned++; $freed += $size; }
        else error_log('[backup] could not delete expired archive: ' . $old);
    }

    return ['pruned' => $pruned, 'freed' => $freed, 'over_cap' => $plan['over_cap'],
            'kept_count' => $plan['kept_count'], 'kept_bytes' => $plan['kept_bytes']];
}

/**
 * Pre-flight guard. Runs BEFORE any bytes are written and decides whether this
 * backup may proceed.
 *
 * Ordering matters: we first delete only what the retention policy already
 * considers expendable (the archives that would have been pruned after this run
 * anyway), THEN re-measure. That way making room never costs the operator an
 * archive the policy wanted to keep, and a run that later fails has not
 * destroyed anything of value.
 *
 * @return array{ok:bool,reason:string,free:?int,need:?int,dir_bytes:int,
 *                dir_count:int,over_cap:bool,undetermined:bool,pruned:int}
 */
function backup_guard(string $dir): array {
    $floor = backup_min_free_bytes();
    $cap   = backup_max_dir_bytes();

    // 1. Retire what policy already says is expendable (age + count only — the
    //    cap is applied after a successful write, see backup_run_now).
    $pre = backup_apply_retention($dir, backup_retention_count(), backup_retention_days(), 0);

    // 2. Re-measure after that.
    $usage = backup_dir_usage($dir);
    $est   = backup_estimate_bytes($dir);

    $out = [
        'ok' => true, 'reason' => '', 'pruned' => $pre['pruned'],
        'free' => null, 'need' => $est['archive'], 'undetermined' => false,
        'dir_bytes' => $usage['bytes'], 'dir_count' => $usage['count'], 'over_cap' => false,
    ];

    // 3. The directory ceiling.
    $capVerdict = backup_cap_verdict($usage['bytes'], $usage['count'], $est['archive'], $cap);
    if (!$capVerdict['ok']) {
        $out['ok']       = false;
        $out['over_cap'] = true;
        $out['reason']   = $capVerdict['reason'] . ' (' . $dir . ')';
        return $out;
    }

    // 4. Free space on BOTH filesystems involved — the uncompressed dump lands
    //    in the system temp directory, the archive in the backup directory, and
    //    on a real server those are frequently different volumes.
    $targets = [
        [$dir,                 $est['archive'], 'backup directory'],
        [sys_get_temp_dir(),   $est['sql'],     'temporary directory'],
    ];
    foreach ($targets as [$path, $need, $label]) {
        $free    = backup_free_bytes((string) $path);
        $verdict = backup_space_verdict($free, $need !== null ? (int) $need : null, $floor);
        if ($label === 'backup directory') {
            $out['free'] = $free;
            $out['undetermined'] = !empty($verdict['undetermined']);
        }
        if (!$verdict['ok']) {
            $out['ok']     = false;
            $out['reason'] = 'on the ' . $label . ' (' . $path . '): ' . $verdict['reason'];
            return $out;
        }
        if (!empty($verdict['undetermined'])) {
            error_log('[backup] free space on the ' . $label . ' (' . $path
                . ') could not be determined — proceeding without the space guard');
        }
    }

    $out['reason'] = 'space and limits ok';
    return $out;
}

/**
 * Read-only mirror of backup_guard()'s space/cap checks — same two targets
 * (backup directory + system temp directory, since the uncompressed dump
 * lands in temp before compression), but with NO side effects (no retention
 * pruning, no setting writes). Safe to call on every Status/Settings page
 * load, unlike backup_guard() itself.
 *
 * Exists because backup_status() used to answer "is there a space problem?"
 * by re-printing backup_last_status verbatim — a string written the last time
 * a REAL backup attempt ran, which can be hours or days stale on an install
 * with backup_opportunistic on and a 24h interval (a refusal deliberately
 * advances backup_last_run_at, see backup_run_now, so a full-disk skip is not
 * retried until the next interval). Conditions can and do change in that
 * window — disk fills up transiently during a Windows Update, or clears once
 * something else is deleted — and the old code had no way to tell "still
 * true" from "was true once." Reported as GH#32: System Status showed "Last
 * Backup refused — not enough room" while the live free-space figure shown
 * right next to it was nowhere near the reserve, because that figure was
 * computed fresh (from backup_dir() only) while the refusal text was frozen
 * from whatever the guard saw — sometimes a DIFFERENT directory (temp) than
 * the one the live figure described.
 *
 * @return array{ok:bool,reason:string,undetermined:bool,
 *   targets:array<string,array{path:string,free:?int,free_size:string,need:?int,ok:bool}>}
 */
function backup_live_space_check(string $dir): array {
    $floor = backup_min_free_bytes();
    $cap   = backup_max_dir_bytes();
    $usage = backup_dir_usage($dir);
    $est   = backup_estimate_bytes($dir);

    $out = ['ok' => true, 'reason' => 'space and limits ok', 'undetermined' => false, 'targets' => []];

    $capVerdict = backup_cap_verdict($usage['bytes'], $usage['count'], $est['archive'], $cap);
    if (!$capVerdict['ok']) {
        $out['ok']     = false;
        $out['reason'] = $capVerdict['reason'] . ' (' . $dir . ')';
    }

    $targets = [
        'backup_directory'    => [$dir,               $est['archive']],
        'temporary_directory' => [sys_get_temp_dir(),  $est['sql']],
    ];
    foreach ($targets as $key => [$path, $need]) {
        $free    = backup_free_bytes((string) $path);
        $verdict = backup_space_verdict($free, $need !== null ? (int) $need : null, $floor);
        $out['targets'][$key] = [
            'path'      => $path,
            'free'      => $free,
            'free_size' => $free !== null ? backup_format_size($free) : 'unknown',
            'need'      => $need,
            'ok'        => $verdict['ok'],
        ];
        if (!empty($verdict['undetermined'])) $out['undetermined'] = true;
        if ($out['ok'] && !$verdict['ok']) {
            $out['ok']     = false;
            $out['reason'] = 'on the ' . str_replace('_', ' ', $key) . ' (' . $path . '): ' . $verdict['reason'];
        }
    }
    return $out;
}

/**
 * Create one backup: dump → package → VERIFY → record status → prune.
 * Returns ['ok'=>bool, 'path'=>?string, 'detail'=>string].
 */
function backup_run_now(?string $dir = null, bool $skipGuard = false): array {
    $dir = $dir ?: backup_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        $msg = 'cannot create backup directory: ' . $dir
             . ' (create it yourself, or set a different one in Settings → Backup / Maintenance)';
        backup_setting_set('backup_last_status', 'failed: ' . $msg);
        return ['ok' => false, 'path' => null, 'detail' => $msg];
    }
    // If the archives are landing inside the web root after all, put deny rules
    // beside them. Best effort; never fatal.
    backup_harden_dir($dir);

    // ── The disk guard. Refuse BEFORE writing a byte. ──────────────────
    // A refusal DOES advance backup_last_run_at ("last attempt"), deliberately.
    // If it did not, every page load on a full disk would re-run the guard —
    // a directory scan plus an information_schema query on the hot path — and
    // the backup subsystem would itself become the performance problem it is
    // supposed to prevent. Advancing the clock throttles retries to once per
    // interval; the "your backups are stale" warning is unaffected because it
    // keys off backup_last_ok_at, which a refusal never touches. So a machine
    // that cannot back up looks exactly like what it is: overdue and warning.
    if (!$skipGuard) {
        $guard = backup_guard($dir);
        if (!$guard['ok']) {
            $msg = $guard['reason'];
            backup_setting_set('backup_last_run_at',  (string) time());
            backup_setting_set('backup_last_skip_at', (string) time());
            backup_setting_set('backup_last_status', 'skipped: ' . $msg);
            error_log('[backup] REFUSED to run: ' . $msg);
            // Surface it where an admin will see it, not just in a log file.
            if (function_exists('audit_log')) {
                try {
                    audit_log('system', 'error', 'backup', null,
                        'Automatic backup refused: ' . $msg,
                        ['free' => $guard['free'], 'dir_bytes' => $guard['dir_bytes'],
                         'dir_count' => $guard['dir_count'], 'over_cap' => $guard['over_cap']],
                        defined('AUDIT_HIGH') ? AUDIT_HIGH : 4);
                } catch (Throwable $e) { /* auditing must never break the guard */ }
            }
            return ['ok' => false, 'path' => null, 'skipped' => true,
                    'detail' => $msg, 'guard' => $guard];
        }
    }

    backup_setting_set('backup_last_run_at', (string) time());
    $stamp   = date('Ymd-His');
    $tmpSql  = rtrim(sys_get_temp_dir(), '/\\') . "/ticketscad-{$stamp}.sql";
    // backup_extension() already includes the dot ('.zip' / '.sql.gz').
    $archive = rtrim($dir, '/\\') . "/ticketscad-{$stamp}" . backup_extension();

    try {
        if (!backup_dump_sql($tmpSql)) throw new RuntimeException('database dump failed');
        $config = function_exists('backup_export_config') ? backup_export_config() : '{}';
        $made = backup_has_zip()
            ? backup_create_zip($tmpSql, $config, $archive)
            : backup_create_gzip_fallback($tmpSql, $config, $archive);
        if (!$made) throw new RuntimeException('could not write archive');
    } catch (Throwable $e) {
        @unlink($tmpSql);
        $msg = $e->getMessage();
        backup_setting_set('backup_last_status', 'failed: ' . $msg);
        error_log('[backup] FAILED: ' . $msg);
        return ['ok' => false, 'path' => null, 'detail' => $msg];
    }
    @unlink($tmpSql);

    // Prove it, don't assume it.
    [$ok, $detail] = backup_verify($archive);
    if (!$ok) {
        backup_setting_set('backup_last_status', 'failed verification: ' . $detail);
        error_log('[backup] wrote an archive that FAILED verification: ' . $detail);
        return ['ok' => false, 'path' => $archive, 'detail' => 'verification failed: ' . $detail];
    }

    backup_setting_set('backup_last_ok_at', (string) time());

    // Retention runs only AFTER a verified success, so a failed run can never
    // cost the operator an archive. This pass applies the full policy — age,
    // count AND the size cap — because the new archive is now on disk and
    // counted.
    $ret   = backup_apply_retention($dir);
    $usage = backup_dir_usage($dir);

    $status = 'ok';
    $note   = '';
    if ($ret['over_cap']) {
        // Policy has deleted everything it is allowed to and the directory is
        // STILL over its ceiling — the remaining archives are protected by
        // BACKUP_MIN_KEEP. Say so instead of quietly deleting the last backup.
        $note = ' — WARNING: the backup directory is still over its '
              . backup_format_size(backup_max_dir_bytes()) . ' limit at '
              . backup_format_size($usage['bytes'])
              . '. Raise the limit or move archives off this machine; the newest backup was kept.';
        $status = 'ok (over limit)';
    }
    backup_setting_set('backup_last_status', $status);

    return ['ok' => true, 'path' => $archive, 'over_cap' => $ret['over_cap'],
            'detail' => $detail
                . ($ret['pruned'] ? "; pruned {$ret['pruned']} old backup(s), freed "
                                    . backup_format_size($ret['freed']) : '')
                . '; ' . $usage['count'] . ' backup(s) now using ' . backup_format_size($usage['bytes'])
                . $note];
}

/**
 * Cheap opportunistic hook, safe to call on an ordinary page request: does
 * nothing unless a backup is due, and a lock file keeps concurrent requests
 * from starting several at once. This is what makes backups actually happen on
 * a Windows/XAMPP box where nobody configured a scheduler.
 */
function backup_maybe_run_opportunistic(): void {
    try {
        if (!backup_is_due()) return;
        $lock = rtrim(sys_get_temp_dir(), '/\\') . '/ticketscad-backup.lock';
        $fh = @fopen($lock, 'c');
        if (!$fh) return;
        if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return; }  // another run holds it
        if (backup_is_due()) backup_run_now();                        // re-check under lock
        flock($fh, LOCK_UN);
        fclose($fh);
    } catch (Throwable $e) {
        error_log('[backup] opportunistic run error: ' . $e->getMessage());
    }
}

/**
 * THE HOOK THAT MAKES ANY OF THIS HAPPEN. Called from inc/navbar.php, i.e. on
 * ordinary authenticated page loads.
 *
 * Until Phase 126 backup_maybe_run_opportunistic() was defined and called from
 * NOWHERE. The docblock above it, tools/backup_run.php and the runbook all
 * stated that TicketsCAD "still takes opportunistic backups on ordinary page
 * requests" — it never did. Any install without cron or Task Scheduler (the
 * common case: Windows/XAMPP, and the exact audience Phase 122 was written for)
 * had automatic backups switched ON, reported as ON, and produced none.
 *
 * Two rules make it safe to call this on a dispatcher's page load:
 *
 *   1. THE HOT PATH IS FREE. Everything before the deferral is one cached
 *      settings read and an integer compare. A machine that is not due pays
 *      nothing measurable.
 *   2. THE WORK NEVER HAPPENS INSIDE THE REQUEST. When a backup IS due we
 *      register a shutdown handler that first finishes the response, releases
 *      the session lock, and only then dumps. Nobody waiting on a page — least
 *      of all somebody working a live incident — ever waits on a database dump.
 *
 * The session lock matters as much as the response: PHP holds an exclusive lock
 * on the session file for the life of the request, so a slow shutdown task
 * would block that dispatcher's NEXT request (and every parallel tab) for the
 * duration of the dump. session_write_close() first.
 */
function backup_schedule_tick(): void {
    static $armed = false;
    try {
        if ($armed) return;                       // once per request, whatever includes us
        if (PHP_SAPI === 'cli') return;           // CLI has tools/backup_run.php
        // Only ever ride along on a plain page view. A POST is somebody SAVING
        // something; if that request appears to hang they will resubmit, and a
        // duplicated incident or unit update is a far worse outcome than a late
        // backup.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
        if (!backup_opportunistic_enabled()) return;
        if (!backup_auto_enabled()) return;       // the master off switch
        if (!backup_is_due()) return;             // ← the cheap common case
        $armed = true;

        register_shutdown_function(static function () {
            try {
                // 1. Let the user's page go.
                //
                // Under php-fpm this genuinely CLOSES the connection, so the
                // browser is completely finished before we touch the database.
                //
                // Under Apache mod_php there is no equivalent — no PHP-level
                // call closes the client connection early. We push every byte
                // out so the page is delivered and rendered, but the connection
                // stays open until the dump ends, which the browser shows as a
                // still-loading tab. That is the honest limitation of the
                // no-scheduler fallback: acceptable for the small databases it
                // exists to protect, and the reason an install with a large
                // database should run tools/backup_run.php from cron / Task
                // Scheduler and switch "Run without a scheduler" off. Guessing
                // at a PHP CLI path to spawn a detached process was considered
                // and rejected: PHP_BINDIR is a compile-time constant that is
                // simply wrong on common installs (XAMPP reports C:\php), and a
                // dispatch system should not execute a guessed binary.
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                } else {
                    while (ob_get_level() > 0) { @ob_end_flush(); }
                    @flush();
                }
                // 2. Release the session lock so this user's other requests
                //    aren't serialised behind the dump.
                if (function_exists('session_write_close') && PHP_SESSION_ACTIVE === session_status()) {
                    @session_write_close();
                }
                // 3. Survive the browser going away mid-dump; a half-written
                //    archive would fail verification and be reported as failed.
                @ignore_user_abort(true);
                @set_time_limit(1800);

                backup_maybe_run_opportunistic();
            } catch (Throwable $e) {
                error_log('[backup] deferred opportunistic run failed: ' . $e->getMessage());
            }
        });
    } catch (Throwable $e) {
        // A backup scheduler must never be able to break a page render.
        error_log('[backup] schedule tick error: ' . $e->getMessage());
    }
}

/**
 * Extract the SQL text out of a backup archive (.zip / .gz / .sql).
 * Shared by the restore tool and the drill so they cannot drift apart.
 */
function backup_extract_sql(string $archive): ?string {
    if (!is_file($archive)) return null;
    if (substr($archive, -4) === '.zip') {
        if (!class_exists('ZipArchive')) return null;
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) return null;
        $sql = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (substr($zip->getNameIndex($i), -4) === '.sql') { $sql = $zip->getFromIndex($i); break; }
        }
        $zip->close();
        return is_string($sql) ? $sql : null;
    }
    if (substr($archive, -3) === '.gz') {
        if (!function_exists('gzopen')) return null;
        $fh = gzopen($archive, 'rb');
        if (!$fh) return null;
        $sql = '';
        while (!gzeof($fh)) { $sql .= gzread($fh, 1048576); }
        gzclose($fh);
        return $sql !== '' ? $sql : null;
    }
    $sql = file_get_contents($archive);
    return is_string($sql) && $sql !== '' ? $sql : null;
}

/** Apply a dump to an already-open PDO handle. Returns [applied, errors, firstErrors]. */
function backup_apply_sql(PDO $pdo, string $sql, int $maxReportedErrors = 3): array {
    $applied = 0; $errors = 0; $reported = [];
    try { $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); } catch (Throwable $e) {}
    foreach (preg_split('/;\s*[\r\n]+/', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || str_starts_with($stmt, '--') || str_starts_with($stmt, '/*')) continue;
        try { $pdo->exec($stmt); $applied++; }
        catch (Throwable $e) {
            $errors++;
            if (count($reported) < $maxReportedErrors) $reported[] = substr($e->getMessage(), 0, 160);
        }
    }
    try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $e) {}
    return [$applied, $errors, $reported];
}

/**
 * RESTORE DRILL — the thing that turns "verified" into "proven".
 *
 * backup_verify() proves an archive is readable and contains schema. That is
 * necessary but not sufficient: the only way to know a backup RESTORES is to
 * restore it. This does exactly that, into a throwaway database, and then
 * reports how many rows of real content came back — so you learn "this backup
 * yields 8 people and 214 incidents", not merely "the file opens".
 *
 * It needs database-admin credentials because creating a database is a
 * privilege the app's own user should NOT have (a correctly-permissioned
 * install denies it — verified 2026-07-25). Credentials are passed in per-run
 * and never stored.
 *
 * The live database is only ever READ (row counts, for comparison). The scratch
 * database is always dropped, including on failure.
 *
 * @return array ok, scratch, applied, errors, tables, counts[], compare[], detail
 */
function backup_drill(string $archive, string $adminUser, string $adminPass,
                      array $countTables = ['member', 'ticket', 'responder', 'facilities']): array {
    // 'conclusive' distinguishes "we drilled and the BACKUP is bad" from "we
    // could not drill at all" (bad credentials, no privilege, host unreachable).
    // Reporting a setup problem as a failed backup would send someone chasing a
    // healthy backup — the same class of misleading message this phase exists to
    // remove. Only a conclusive run may condemn a backup.
    $out = ['ok' => false, 'conclusive' => false, 'scratch' => null, 'applied' => 0,
            'errors' => 0, 'tables' => 0, 'counts' => [], 'compare' => [], 'detail' => ''];

    [$vok, $vdetail] = backup_verify($archive);
    if (!$vok) {
        $out['conclusive'] = true;   // we read the archive; it really is unusable
        $out['detail'] = 'archive failed verification: ' . $vdetail;
        return $out;
    }

    $sql = backup_extract_sql($archive);
    if ($sql === null) {
        $out['conclusive'] = true;
        $out['detail'] = 'could not extract SQL from the archive';
        return $out;
    }

    $host = $GLOBALS['db_host'] ?? 'localhost';
    $live = (string) ($GLOBALS['db_name'] ?? '');
    // Distinct, obviously-temporary name. Never the live database.
    $scratch = ($live !== '' ? $live : 'ticketscad') . '_drill_' . substr(bin2hex(random_bytes(4)), 0, 8);
    if (strcasecmp($scratch, $live) === 0) { $out['detail'] = 'refusing to drill onto the live database'; return $out; }
    $out['scratch'] = $scratch;

    $root = null;
    try {
        $root = new PDO("mysql:host={$host};charset=utf8mb4", $adminUser, $adminPass,
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable $e) {
        $out['detail'] = 'could not connect with the supplied admin credentials: ' . $e->getMessage();
        return $out;
    }

    try {
        // $scratch is generated two lines above from random_bytes(), never from
        // any request input — and a CREATE DATABASE identifier can't be bound as
        // a parameter regardless (MySQL has no placeholder syntax for DDL names).
        $root->exec("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4"); // NOSONAR S2077: $scratch is server-generated, not user input; DDL identifiers can't be parameters
    } catch (Throwable $e) {
        $out['detail'] = 'could not create the scratch database (needs CREATE privilege): ' . $e->getMessage();
        return $out;
    }

    // Declared out here so the finally block can CLOSE it before dropping the
    // database. Leaving this handle open makes DROP DATABASE wait forever on a
    // metadata lock — the drill hangs and leaks the scratch database. (Found by
    // running a real drill, 2026-07-25; every unit test passed while it
    // deadlocked, which is exactly why this had to be exercised for real.)
    $pdo = null;
    try {
        $pdo = new PDO("mysql:host={$host};dbname={$scratch};charset=utf8mb4", $adminUser, $adminPass,
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        [$applied, $errors, $reported] = backup_apply_sql($pdo, $sql);
        $out['applied'] = $applied;
        $out['errors']  = $errors;

        $tablesStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?");
        $tablesStmt->execute([$scratch]);
        $out['tables'] = (int) $tablesStmt->fetchColumn();

        // $t always comes from $countTables, whose only value in this codebase is
        // the hardcoded default above — but a table name can't be a bind
        // parameter, so it's whitelisted here rather than interpolated on trust,
        // in case a future caller ever passes something else.
        foreach ($countTables as $t) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) { $out['counts'][$t] = null; continue; }
            try { $out['counts'][$t] = (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn(); } // NOSONAR S2077: $t whitelisted to [A-Za-z0-9_] above
            catch (Throwable $e) { $out['counts'][$t] = null; }   // table not in this backup
        }

        // Compare against what is live right now — a drill that restores an
        // EMPTY copy of a populated system should not read as a pass.
        foreach ($countTables as $t) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) { $out['compare'][$t] = null; continue; }
            try { $out['compare'][$t] = (int) db_fetch_value("SELECT COUNT(*) FROM `{$t}`"); } // NOSONAR S2077: $t whitelisted to [A-Za-z0-9_] above
            catch (Throwable $e) { $out['compare'][$t] = null; }
        }

        $restoredRows = array_sum(array_map(static fn($v) => (int) $v, $out['counts']));
        $out['conclusive'] = true;   // the restore actually ran — this verdict is real
        $out['ok'] = ($out['tables'] > 0 && $errors === 0);
        $out['detail'] = $out['ok']
            ? "restored {$applied} statements into {$out['tables']} tables, {$restoredRows} rows across sampled tables"
            : "restore produced {$errors} error(s): " . implode(' | ', $reported);
    } catch (Throwable $e) {
        $out['detail'] = 'drill failed: ' . $e->getMessage();
    } finally {
        // Release the scratch connection FIRST — an open handle on that database
        // blocks DROP DATABASE on a metadata lock (indefinitely).
        $pdo = null;
        // ALWAYS clean up the scratch database, success or failure.
        try { $root->exec("DROP DATABASE IF EXISTS `{$scratch}`"); } // NOSONAR S2077: $scratch is server-generated, not user input; DDL identifiers can't be parameters
        catch (Throwable $e) { $out['detail'] .= ' (WARNING: could not drop scratch database ' . $scratch . ')'; }
    }

    backup_setting_set('backup_last_drill_at', (string) time());
    backup_setting_set('backup_last_drill_status', $out['ok'] ? 'passed' : ('failed: ' . $out['detail']));
    return $out;
}

/** Status for the UI / health page. */
function backup_status(): array {
    $lastOk    = (int) backup_setting('backup_last_ok_at', '0');
    $interval  = backup_interval_hours();
    $ageHours  = $lastOk > 0 ? (int) floor((time() - $lastOk) / 3600) : null;
    // Stale once we're past two intervals without a verified success.
    $stale     = ($lastOk <= 0) || ($ageHours !== null && $ageHours > ($interval * 2));

    // ── Space picture: what is stored, what is left, and how close to the
    // limits we are. This is the part an operator needs BEFORE it becomes an
    // outage, so it is computed here and shown on both Settings and Status.
    $dir       = backup_dir();
    $usage     = backup_dir_usage($dir);
    $free      = backup_free_bytes($dir);
    $cap       = backup_max_dir_bytes();
    $floor     = backup_min_free_bytes();

    // Warn while there is still time to act, rather than at the wall.
    $capPct    = $cap > 0 ? (int) round($usage['bytes'] / $cap * 100) : 0;
    $lowDisk   = ($free !== null && $floor > 0 && $free < $floor * 2);
    $nearCap   = ($cap > 0 && $capPct >= 80);
    $lastStatus = backup_setting('backup_last_status', 'never run');
    $skipped    = strpos($lastStatus, 'skipped:') === 0;
    $lastSkipAt = (int) backup_setting('backup_last_skip_at', '0');

    // GH#32: backup_last_status is written only when a real backup attempt
    // runs, so on an install with the opportunistic scheduler (24h default
    // interval) a one-time refusal could sit there, worded as if current, for
    // up to a day after the actual condition cleared. Re-check LIVE before
    // presenting a "refused" banner so the message and the numbers next to it
    // never disagree.
    $live = backup_live_space_check($dir);

    $spaceWarning = '';
    if ($skipped && !$live['ok']) {
        $spaceWarning = 'The last automatic backup was refused and the condition is still present: '
            . $live['reason'];
    } elseif ($skipped) {
        $spaceWarning = 'A backup attempt' . ($lastSkipAt > 0 ? ' on ' . date('Y-m-d H:i', $lastSkipAt) : '')
            . ' was refused (' . substr($lastStatus, 9) . '), but that condition has since cleared — '
            . 'the next scheduled attempt should succeed.';
    } elseif ($nearCap && $lowDisk) {
        $spaceWarning = 'Backups are using ' . backup_format_size($usage['bytes']) . ' of their '
            . backup_format_size($cap) . ' limit, and only ' . backup_format_size((int) $free)
            . ' of disk is free. Free space or reduce how many backups are kept.';
    } elseif ($nearCap) {
        $spaceWarning = 'Backups are using ' . $capPct . '% of their '
            . backup_format_size($cap) . ' limit. Older copies will be removed automatically; '
            . 'raise the limit if you want to keep more.';
    } elseif ($lowDisk) {
        $spaceWarning = 'Only ' . backup_format_size((int) $free) . ' of disk space is free — '
            . 'close to the ' . backup_format_size($floor) . ' reserve below which backups stop.';
    }

    return [
        'enabled'         => backup_auto_enabled(),
        'opportunistic'   => backup_opportunistic_enabled(),
        'interval_hours'  => $interval,
        'retention_count' => backup_retention_count(),
        'retention_days'  => backup_retention_days(),
        'directory'       => backup_dir(),
        'backup_count'    => $usage['count'],
        'backup_bytes'    => $usage['bytes'],
        'backup_size'     => backup_format_size($usage['bytes']),
        'free_bytes'      => $free,
        'free_size'       => $free !== null ? backup_format_size($free) : 'unknown',
        'max_dir_bytes'   => $cap,
        'max_dir_size'    => $cap > 0 ? backup_format_size($cap) : 'no limit',
        'cap_pct'         => $capPct,
        'min_free_bytes'  => $floor,
        'min_free_size'   => $floor > 0 ? backup_format_size($floor) : 'no reserve',
        // The dump also has to fit in the system temp directory before it is
        // compressed into the archive — a second location the guard checks
        // (backup_guard, backup_live_space_check) that the older status
        // report never surfaced, even though a refusal could be about THIS
        // one while every other number on the page describes the other.
        'temp_directory'  => $live['targets']['temporary_directory']['path'] ?? sys_get_temp_dir(),
        'temp_free_bytes' => $live['targets']['temporary_directory']['free'] ?? null,
        'temp_free_size'  => $live['targets']['temporary_directory']['free_size'] ?? 'unknown',
        'space_ok_now'    => $live['ok'],
        'space_warning'   => $spaceWarning,
        'last_skip_at'    => (int) backup_setting('backup_last_skip_at', '0') ?: null,
        'last_ok_at'      => $lastOk ?: null,
        'last_ok_age_hours' => $ageHours,
        'last_status'     => backup_setting('backup_last_status', 'never run'),
        // A backup that has never been restored is still only a hypothesis —
        // surface when the last restore DRILL ran so "restorable" is evidenced.
        'last_drill_at'     => (int) backup_setting('backup_last_drill_at', '0') ?: null,
        'last_drill_status' => backup_setting('backup_last_drill_status', 'never drilled'),
        'stale'           => $stale,
        'warning'         => $stale
            ? 'No verified backup recently — if this machine lost power now, recent work could be lost.'
            : '',
    ];
}
