<?php
/**
 * Ownership and mode policy for the directories the application WRITES to.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────
 *
 * tools/deploy.sh ended every deploy with
 *
 *     sudo chown -R www-data:www-data /var/www/newui
 *
 * which recursed into the in-webroot backups directory (BACKUP_DIR_LEGACY) and
 * handed it to the web server. That directory has TWO writers — the operator,
 * running `php tools/backup_run.php` over SSH, and the web server, via
 * api/backup.php and the scheduled systemd timer — so an owner it shares with
 * neither breaks one of them. Observed twice on live hosts: the CLI backup
 * failed with "Cannot create zip file: error code 5", was repaired by hand, and
 * the next deploy undid the repair.
 *
 * The chown is also the exact pattern this project forbids (it takes .git with
 * it, so the reader's next `git pull` dies with "detected dubious ownership" —
 * git >= 2.35.2, CVE-2022-24765), and it was never needed for program files:
 * the web server only READS those.
 *
 * A detail worth recording, because it is why the break was hard to see: on
 * Linux, chown clears the setgid bit on FILES but not on DIRECTORIES
 * (chown_common() sets ATTR_KILL_SGID only when !S_ISDIR). So `chown -R` left
 * the mode reading a perfectly correct 2770 and changed only the owner —
 * anything that inspected the mode would have reported the directory healthy.
 *
 * ── THE CLASSIFICATION ───────────────────────────────────────────────
 *
 * SHARED (two writers) — owner = the operator, group = the web server's group,
 * mode 2770. The setgid bit is not decoration: without it a file created by one
 * writer lands in the creator's own group and the other writer cannot manage it,
 * so the directory breaks again one file at a time.
 *
 *   BACKUP_DIR          tools/backup_run.php + tools/restore.php (operator, CLI)
 *                       api/backup.php + the scheduled timer   (web server)
 *   BACKUP_DIR_LEGACY   the same two writers; still read for listing and
 *                       retention so pre-4.2.3 archives are not orphaned.
 *                       Never created here — creating it would re-establish the
 *                       in-webroot path that was downloadable over HTTP.
 *
 * WEB SERVER ONLY — owner = the web server. Every writer is a request path;
 * nothing on the command line writes to them in normal operation, so a second
 * owner would only widen access:
 *
 *   uploads, uploads/overlays   api/upload.php, api/map-image-overlays.php
 *   cache, cache/weather        api/weather-proxy.php, health-check cache
 *   zello_audio_dir() (private) proxy/ZelloProxyApp.php -- outside the web
 *                               root since GHSA-x9x6-w4fg-pmcc; the legacy
 *                               in-tree cache/zello-audio is tracked too,
 *                               but only if it still exists, and is never
 *                               (re-)created.
 *   TILE_CACHE_DIR              inc/tile-proxy.php
 *   GEOCODE_CACHE_DIR           inc/geocode.php
 *
 * DELIBERATELY NOT TOUCHED:
 *
 *   FE_KEYS_DIR   holds private key material and is chmod 0700 by the code that
 *                 creates it (inc/field-encrypt.php). It is outside the install
 *                 tree, the deploy never reached it, and giving it a shared
 *                 group would be a security regression, not a fix.
 *   everything    the web server only reads program files. 644/755 covers that
 *   else in tree  without any ownership change at all.
 *
 * ── PRESERVE FIRST ───────────────────────────────────────────────────
 *
 * A directory that is already in a working state is left exactly as it is —
 * including its owner, which on a shared directory may legitimately be an
 * operator other than whoever is running the deploy today. Only a state that
 * actually fails is rewritten. That is what makes this safe to run on every
 * deploy instead of something that fights the administrator.
 *
 * Writability is decided by _health_mode_writable() — the same predicate the
 * installation health check uses — so this cannot reach a verdict the Status
 * page disagrees with.
 */

require_once __DIR__ . '/health-check.php';
require_once __DIR__ . '/backup.php';

/** Two writers: the operator on the command line and the web server. */
const INSTALL_PERM_SHARED = 'shared';
/** One writer: the web server. */
const INSTALL_PERM_WEB    = 'web';

/** Canonical modes. 02770 = rwxrws--- (setgid), 0775 = rwxrwxr-x. */
const INSTALL_PERM_MODE_SHARED = 02770;
const INSTALL_PERM_MODE_WEB    = 0775;

/**
 * The directories this policy covers, derived from the constants rather than
 * from memory: if BACKUP_DIR or TILE_CACHE_DIR ever moves, this follows it.
 *
 * @return array<int,array{path:string,label:string,role:string,purpose:string,create:bool}>
 */
function install_perm_targets(): array
{
    $root = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);

    // TILE_CACHE_DIR and GEOCODE_CACHE_DIR are defined at the bottom of their
    // own includes. Pull them in if the caller has not; never re-derive the
    // path here, because a second copy of the expression is a second thing to
    // keep in step.
    if (!defined('TILE_CACHE_DIR')) {
        try { require_once __DIR__ . '/tile-proxy.php'; } catch (Throwable $e) { /* optional */ }
    }
    if (!defined('GEOCODE_CACHE_DIR')) {
        try { require_once __DIR__ . '/geocode.php'; } catch (Throwable $e) { /* optional */ }
    }

    $t = [];

    $t[] = [
        'path'    => BACKUP_DIR,
        'label'   => 'BACKUP_DIR',
        'role'    => INSTALL_PERM_SHARED,
        'purpose' => 'database archives — written by tools/backup_run.php (you) and api/backup.php + the scheduled timer (web server)',
        'create'  => true,
    ];

    // Only when it already exists. Creating it would put a served directory
    // back on disk for no reason; it is here so that an install still holding
    // older archives keeps them writable by both writers.
    if (defined('BACKUP_DIR_LEGACY') && @is_dir(BACKUP_DIR_LEGACY)) {
        $t[] = [
            'path'    => BACKUP_DIR_LEGACY,
            'label'   => 'BACKUP_DIR_LEGACY',
            'role'    => INSTALL_PERM_SHARED,
            'purpose' => 'pre-4.2.3 archives, still listed and pruned by both writers',
            'create'  => false,
        ];
    }

    foreach ([
        [$root . '/uploads',           'uploads',           'file attachments (api/upload.php)'],
        [$root . '/uploads/overlays',  'uploads/overlays',  'map image overlays (api/map-image-overlays.php)'],
        [$root . '/cache',             'cache',             'general cache root'],
        [$root . '/cache/weather',     'cache/weather',     'weather tiles (api/weather-proxy.php)'],
    ] as $d) {
        $t[] = ['path' => $d[0], 'label' => $d[1], 'role' => INSTALL_PERM_WEB,
                'purpose' => $d[2], 'create' => true];
    }

    // GHSA-x9x6-w4fg-pmcc — recordings now live outside the web root.
    // Created here like every other web-only cache dir; not derived from a
    // require, because zello_audio_dir.php has no other dependents that
    // would justify pulling it in unconditionally.
    if (!function_exists('zello_audio_dir')) {
        try { require_once __DIR__ . '/zello_audio_dir.php'; } catch (Throwable $e) { /* optional */ }
    }
    if (function_exists('zello_audio_dir')) {
        $t[] = ['path' => zello_audio_dir(), 'label' => 'zello-audio (private)',
                'role' => INSTALL_PERM_WEB,
                'purpose' => 'Zello recordings (proxy/ZelloProxyApp.php), outside the served tree',
                'create' => true];
    }
    // Pre-fix recordings, still readable (api/zello-audio.php falls back to
    // it) until sql/run_zello_audio_relocate.php moves them. Only tracked if
    // it already exists -- never re-created, since that would put a served
    // directory back on disk for no reason (same reasoning as BACKUP_DIR_LEGACY).
    if (function_exists('zello_audio_dir_legacy') && @is_dir(zello_audio_dir_legacy())) {
        $t[] = ['path' => zello_audio_dir_legacy(), 'label' => 'cache/zello-audio (legacy)',
                'role' => INSTALL_PERM_WEB,
                'purpose' => 'pre-fix Zello recordings, readable until relocated',
                'create' => false];
    }

    // 'create' => true (2026-08-19). It used to be false, on the reasoning
    // that "every one of these is created on demand" (see the comment on
    // $relDirs above, which is true for uploads/cache/weather). It is NOT
    // true for these two: tile_cache_dir()/geocode_cache_dir() are called
    // from whatever process happens to reach them FIRST — a real web request
    // (fine) or a CLI/SSH diagnostic run as the operator (not fine) — and
    // that process's own @mkdir() creates the directory owned by ITSELF,
    // with no way for PHP to force the group to match the web server's
    // afterwards (chgrp requires either owning the target group or root).
    // On your-server.example.com an operator CLI command won this race:
    // GEOCODE_CACHE_DIR came up ejosterberg:ejosterberg mode 0700, www-data
    // could not write a single byte into it, and every geocode lookup
    // silently bypassed the cache for weeks — geocode_cache_write() is
    // documented "best effort: a cache we cannot write is not an error", so
    // nothing logged it. 'create' => true here means tools/fix-permissions.php
    // — already run on every tools/deploy.sh deploy, and the documented
    // shortcut for a self-hosted admin — creates BOTH directories owned by
    // the web server up front, before either kind of process can race to
    // create them the wrong way. See tests/test_deploy_permissions.php.
    if (defined('TILE_CACHE_DIR')) {
        $t[] = ['path' => TILE_CACHE_DIR, 'label' => 'TILE_CACHE_DIR', 'role' => INSTALL_PERM_WEB,
                'purpose' => 'map tile cache (inc/tile-proxy.php)', 'create' => true];
    }
    if (defined('GEOCODE_CACHE_DIR')) {
        $t[] = ['path' => GEOCODE_CACHE_DIR, 'label' => 'GEOCODE_CACHE_DIR', 'role' => INSTALL_PERM_WEB,
                'purpose' => 'geocoding results cache (inc/geocode.php)', 'create' => true];
    }

    return $t;
}

/**
 * PURE: is this ownership/mode a working state for a directory in this role?
 *
 * The whole point of the exercise, and the one function worth reading twice.
 *
 *   web    — the web server can create entries. Nothing else is required.
 *   shared — BOTH the web server and the operator can create entries, AND the
 *            setgid bit is set so that what either of them creates stays in the
 *            group the other one holds.
 *
 * Delegates to _health_mode_writable(), so "can write" means the same thing
 * here as it does on the Status page: POSIX checks exactly one class — owner,
 * else group, else other — and stops.
 *
 * @param array      $webUser  ['uid'=>int,'gids'=>int[]]
 * @param array|null $operator ['uid'=>int,'gids'=>int[]] — required for 'shared'
 */
function install_perm_state_ok(
    string $role,
    int $ownerUid,
    int $ownerGid,
    int $mode,
    array $webUser,
    ?array $operator = null
): bool {
    $webOk = _health_mode_writable($ownerUid, $ownerGid, $mode, $webUser);
    if ($role !== INSTALL_PERM_SHARED) {
        return $webOk;
    }
    if ($operator === null || ($operator['uid'] ?? null) === null) {
        return false;                       // cannot answer ⇒ not established
    }
    $opOk    = _health_mode_writable($ownerUid, $ownerGid, $mode, $operator);
    $setgid  = ($mode & 02000) === 02000;
    return $webOk && $opOk && $setgid;
}

/**
 * Resolve the human operator — the account that runs backups from a shell.
 *
 * Never hardcoded. Order: an explicit name, then SUDO_USER (set by sudo, and
 * the deploy reaches this through sudo, so it names the person who connected),
 * then the account running this process. root is rejected: making root the
 * owner of a shared directory locks out the very operator it exists for.
 *
 * @return array{name:?string,uid:?int,gids:int[],determined:bool,basis:string}
 */
function install_perm_operator(?string $explicit = null): array
{
    $out = ['name' => null, 'uid' => null, 'gids' => [], 'determined' => false, 'basis' => ''];
    try {
        $name  = null;
        $basis = '';
        if ($explicit !== null && trim($explicit) !== '') {
            $name  = trim($explicit);
            $basis = 'named on the command line';
        }
        if ($name === null) {
            $sudo = getenv('SUDO_USER');
            if ($sudo !== false && trim($sudo) !== '' && trim($sudo) !== 'root') {
                $name  = trim($sudo);
                $basis = 'SUDO_USER — the account that invoked sudo';
            }
        }
        if ($name === null) {
            $me = _health_process_user();
            if ($me !== null && $me !== 'root') {
                $name  = $me;
                $basis = 'the account running this command';
            }
        }
        if ($name === null) {
            $out['basis'] = 'could not be established (running as root with no SUDO_USER)';
            return $out;
        }

        $rec = _health_user_record($name, null);
        if ($rec === null || ($rec['uid'] ?? null) === null) {
            $out['name']  = $name;
            $out['basis'] = 'account "' . $name . '" (' . $basis . ') is not resolvable on this system';
            return $out;
        }
        if ((int) $rec['uid'] === 0) {
            $out['name']  = $rec['name'];
            $out['basis'] = 'resolved to root, which cannot own a directory shared with the web server';
            return $out;
        }

        return ['name' => $rec['name'], 'uid' => (int) $rec['uid'],
                'gids' => array_map('intval', (array) ($rec['gids'] ?? [])),
                'determined' => true, 'basis' => $basis];
    } catch (Throwable $e) {
        $out['basis'] = 'internal error while resolving the operator account';
        return $out;
    }
}

/**
 * What would have to change, and why. Changes nothing.
 *
 * Every entry carries a state:
 *   ok      — already working; will not be touched (preserve first)
 *   fix     — a real failure; the canonical owner/group/mode will be applied
 *   create  — missing and will be created
 *   unsafe  — refused: the path is the install root, an ancestor of it, or
 *             carries a repository. Never operated on, at any depth.
 *   unknown — the web server's account could not be established, or (shared
 *             only) the operator's could not. Reported, never guessed at.
 *
 * @param array $webUser  result of health_check_web_user()
 * @param array $operator result of install_perm_operator()
 */
function install_perm_plan(array $webUser, array $operator, ?array $targets = null): array
{
    $targets = $targets ?? install_perm_targets();
    $out     = [];

    $webUid = ($webUser['uid'] ?? null) !== null ? (int) $webUser['uid'] : null;
    $webGid = null;
    $gids   = array_map('intval', (array) ($webUser['gids'] ?? []));
    if (!empty($gids)) {
        $webGid = $gids[0];                 // the web account's primary group
    }
    $webKnown = !empty($webUser['determined']) && $webUid !== null && $webGid !== null;

    foreach ($targets as $t) {
        $row = [
            'path'    => $t['path'],
            'label'   => $t['label'],
            'role'    => $t['role'],
            'purpose' => $t['purpose'],
            'state'   => 'ok',
            'reason'  => '',
            'owner'   => null,
            'group'   => null,
            'mode'    => null,
            'want_uid'   => null,
            'want_gid'   => null,
            'want_mode'  => null,
            'recursive'  => false,
        ];

        // Safety first, before anything is even considered. A path that could
        // carry .git with it is refused outright — see the standing rule in
        // _health_recursive_chown_safe().
        $exists = @is_dir($t['path']);
        if ($exists && !_health_recursive_chown_safe($t['path'])) {
            $row['state']  = 'unsafe';
            $row['reason'] = 'refused: this is the install directory, an ancestor of it, or carries a '
                           . '.git — changing its ownership would break the next `git pull`';
            $out[] = $row;
            continue;
        }

        if (!$webKnown) {
            $row['state']  = 'unknown';
            // The full explanation is printed once, in the header. Repeating it
            // per directory buries the list it is supposed to be annotating.
            $row['reason'] = 'the web server\'s account could not be established, so the correct '
                           . 'ownership cannot be worked out (see the note above)';
            $out[] = $row;
            continue;
        }

        $shared = ($t['role'] === INSTALL_PERM_SHARED);
        if ($shared && empty($operator['determined'])) {
            $row['state']  = 'unknown';
            $row['reason'] = 'this directory is shared with a human operator, whose account could not be '
                           . 'established: ' . (string) ($operator['basis'] ?? '');
            $out[] = $row;
            continue;
        }

        $row['want_uid']  = $shared ? (int) $operator['uid'] : $webUid;
        $row['want_gid']  = $webGid;
        $row['want_mode'] = $shared ? INSTALL_PERM_MODE_SHARED : INSTALL_PERM_MODE_WEB;
        $row['recursive'] = true;

        if (!$exists) {
            $row['state']  = empty($t['create']) ? 'absent' : 'create';
            $row['reason'] = empty($t['create'])
                ? 'not present on this install; nothing to do'
                : 'missing — will be created';
            $out[] = $row;
            continue;
        }

        $st = @stat($t['path']);
        if (!is_array($st) || !isset($st['uid'], $st['gid'], $st['mode'])) {
            $row['state']  = 'unknown';
            $row['reason'] = 'could not read the directory\'s ownership';
            $out[] = $row;
            continue;
        }

        $row['owner'] = (int) $st['uid'];
        $row['group'] = (int) $st['gid'];
        $row['mode']  = (int) $st['mode'] & 07777;

        if (install_perm_state_ok($t['role'], $row['owner'], $row['group'], $row['mode'],
                                  $webUser, $operator['determined'] ? $operator : null)) {
            $row['state']  = 'ok';
            $row['reason'] = $shared
                ? 'both writers can write and new files inherit the shared group'
                : 'the web server can write';
        } else {
            $row['state']  = 'fix';
            $row['reason'] = $shared
                ? install_perm_shared_failure($row['owner'], $row['group'], $row['mode'], $webUser, $operator)
                : 'the web server cannot write here';
        }
        $out[] = $row;
    }

    return $out;
}

/** Which half of a shared directory is broken — named, so the log is readable. */
function install_perm_shared_failure(int $uid, int $gid, int $mode, array $webUser, array $operator): string
{
    $bits = [];
    if (!_health_mode_writable($uid, $gid, $mode, $webUser)) {
        $bits[] = 'the web server (' . (string) ($webUser['name'] ?? '?') . ') cannot write';
    }
    if (!empty($operator['determined']) && !_health_mode_writable($uid, $gid, $mode, $operator)) {
        $bits[] = 'the operator (' . (string) ($operator['name'] ?? '?') . ') cannot write';
    }
    if (($mode & 02000) !== 02000) {
        $bits[] = 'setgid is not set, so new archives would not inherit the shared group';
    }
    return empty($bits) ? 'not a working shared state' : implode('; ', $bits);
}

/**
 * Apply a plan. Only 'fix' and 'create' rows do anything.
 *
 * Recursion is gated a second time by _health_recursive_chown_safe(), on the
 * literal path about to be operated on. The plan already refused unsafe paths;
 * this is the belt to that braces, because the cost of getting it wrong is
 * someone's repository.
 *
 * @return array<int,array{path:string,action:string,ok:bool,detail:string}>
 */
function install_perm_apply(array $plan, bool $dryRun = false): array
{
    $results = [];
    foreach ($plan as $row) {
        if (!in_array($row['state'], ['fix', 'create'], true)) {
            continue;
        }
        $path = $row['path'];
        if (!_health_recursive_chown_safe($path) && @is_dir($path)) {
            $results[] = ['path' => $path, 'action' => 'refused', 'ok' => false,
                          'detail' => 'unsafe to change ownership of this path'];
            continue;
        }

        $want = sprintf('%s:%s mode %04o', (string) $row['want_uid'], (string) $row['want_gid'], $row['want_mode']);
        if ($dryRun) {
            $results[] = ['path' => $path, 'action' => $row['state'], 'ok' => true,
                          'detail' => '[dry-run] would set ' . $want];
            continue;
        }

        $ok = true;
        $detail = [];
        if ($row['state'] === 'create' && !@is_dir($path)) {
            if (!@mkdir($path, 0775, true) && !@is_dir($path)) {
                $results[] = ['path' => $path, 'action' => 'create', 'ok' => false,
                              'detail' => 'could not create the directory'];
                continue;
            }
            $detail[] = 'created';
        }

        // Order matters: chown can clear setuid/setgid on files, so the mode
        // goes on last. (On directories Linux preserves setgid across a chown,
        // but relying on that would be relying on a kernel detail.)
        if (!@chown($path, (int) $row['want_uid'])) { $ok = false; $detail[] = 'chown failed'; }
        if (!@chgrp($path, (int) $row['want_gid'])) { $ok = false; $detail[] = 'chgrp failed'; }
        if (!@chmod($path, (int) $row['want_mode'])) { $ok = false; $detail[] = 'chmod failed'; }

        if ($ok && !empty($row['recursive'])) {
            _install_perm_recurse($path, (int) $row['want_uid'], (int) $row['want_gid']);
            $detail[] = 'contents followed';
        }

        $detail[] = 'set ' . $want;
        $results[] = ['path' => $path, 'action' => $row['state'], 'ok' => $ok,
                      'detail' => implode(', ', $detail)];
    }
    return $results;
}

/**
 * Follow the ownership down into a directory's existing contents.
 *
 * Scoped to one already-vetted directory, never to a tree that could contain a
 * repository, and it does not follow symlinks out of it. Modes are left alone —
 * an archive at 0640 is the operator's business.
 */
function _install_perm_recurse(string $dir, int $uid, int $gid, int $depth = 0): void
{
    if ($depth > 12) {
        return;
    }
    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return;
    }
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $dir . DIRECTORY_SEPARATOR . $e;
        if (@is_link($p)) {
            continue;
        }
        @chown($p, $uid);
        @chgrp($p, $gid);
        if (@is_dir($p)) {
            _install_perm_recurse($p, $uid, $gid, $depth + 1);
        }
    }
}
