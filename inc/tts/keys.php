<?php
/**
 * Where text-to-speech API keys live on disk.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * tts_keys_dir() returned `dirname(__DIR__, 2) . '/keys/tts'` — that is
 * NEWUI_ROOT/keys/tts, INSIDE the application tree, and the documented install
 * points the web root at the application root. Every comment around it said
 * "../keys/tts, outside the webroot". The code and the comment named two
 * different directories, and only the comment was true of the layout its
 * author was picturing. This is the fourth instance of the assumption behind
 * GHSA-rrp6 (backups), the 4.2.3 Windows regression, and GHSA-3jmh (the RSA
 * and 2FA keys); inc/served-dir.php's header tells that history.
 *
 * Apache was "covered" by the root .htaccess denying keys/. IIS reads no
 * .htaccess, and nginx only ignores the file when the operator has installed
 * docs/nginx/ticketscad-hardening.conf. A `.key` file was refused on IIS only
 * because that extension has no MIME mapping — an accident of naming.
 *
 * A second thing was wrong with the same line: in the Docker image
 * NEWUI_ROOT is /var/www/html, which is not on any volume, so
 * `docker compose up -d --build` — the documented update step — silently threw
 * away every saved TTS API key. The engine then failed over to Piper with
 * nothing telling the administrator why.
 *
 * ── THE FIX ────────────────────────────────────────────────────────────────
 *
 * TTS keys now live in a `tts` subdirectory of the SAME keys directory the RSA
 * and 2FA keys use: one place for an operator to look, one place to back up,
 * and on Docker it sits under the app_keys volume that is already mounted and
 * already chown'd by the entrypoint.
 *
 *   POSIX    <parent of install>/keys/tts
 *   Windows  %ProgramData%\TicketsCAD\keys\tts
 *
 * Unlike the encryption keys, TTS keys are NOT chosen to follow wherever the
 * old directory happens to be: an API key can be re-pasted in thirty seconds,
 * whereas losing tfa.key locks every 2FA user out. So on Windows an install
 * whose RSA/2FA keys are still in the legacy, published sibling directory gets
 * its TTS keys in %ProgramData% regardless — they do not follow the exposure.
 * An operator who has deliberately defined FE_KEYS_DIR somewhere else in
 * config.php gets `tts` beneath it; TTS_KEYS_DIR (also a config.php define)
 * overrides both.
 *
 * Reading still finds a key where it used to be, so an install keeps working
 * across the upgrade with no operator action; writing never goes back there.
 * sql/run_tts_keys_relocate.php moves what exists, and the Status page's
 * "Encryption key location" row names anything left behind.
 */

require_once __DIR__ . '/../served-dir.php';
require_once __DIR__ . '/../field-encrypt.php';

/** Normalise a path for comparison only (forward slashes, no trailing slash). */
function _tts_norm_path(string $p): string
{
    return rtrim(str_replace('\\', '/', $p), '/');
}

/**
 * The directory TTS API keys are written to, for an application root.
 *
 * The platform and the "keys directory in use" are parameters, not reads of
 * the machine, so each layout can be asserted from any CI box — a test that
 * can only see its own platform's answer is how the Windows backups and keys
 * regressions shipped.
 *
 * @param string      $appRoot        The application root (NEWUI_ROOT).
 * @param bool|null   $windows        NULL = detect from this machine.
 * @param string|null $feKeysDirInUse What FE_KEYS_DIR resolved to; NULL = read the constant.
 */
function tts_keys_dir_for(string $appRoot, ?bool $windows = null, ?string $feKeysDirInUse = null): string
{
    if ($windows === null) {
        $windows = (DIRECTORY_SEPARATOR === '\\');
    }
    $default = fe_default_keys_dir_for($appRoot, $windows);
    $legacy  = fe_legacy_keys_dir_for($appRoot, $windows);
    // The FE_KEYS_DIR constant describes THIS install only. Asked about some
    // other application root (a test simulating a different layout), it says
    // nothing — and reading it anyway would mistake this machine's own keys
    // directory for an operator override of the simulated one.
    $inUse   = $feKeysDirInUse
        ?? ((defined('FE_KEYS_DIR') && defined('NEWUI_ROOT')
             && _tts_norm_path($appRoot) === _tts_norm_path(NEWUI_ROOT))
            ? (string) FE_KEYS_DIR : $default);

    // Only a directory the operator CHOSE is followed. FE_KEYS_DIR equal to
    // either built-in location is not a choice — the legacy one is the very
    // directory that proved to be published.
    $isBuiltIn = (_tts_norm_path($inUse) === _tts_norm_path($legacy))
              || (_tts_norm_path($inUse) === _tts_norm_path($default));
    $base = $isBuiltIn ? $default : $inUse;

    return rtrim($base, '/\\') . ($windows ? '\\tts' : '/tts');
}

/** Directory holding TTS API-key files — outside the served tree. */
function tts_keys_dir(): string
{
    if (defined('TTS_KEYS_DIR') && trim((string) TTS_KEYS_DIR) !== '') {
        return rtrim((string) TTS_KEYS_DIR, '/\\');
    }
    return tts_keys_dir_for(NEWUI_ROOT);
}

/**
 * Where every version before this fix kept them: inside the application tree.
 * Never written to any more; still read from, and still the thing the
 * relocation script empties.
 */
function tts_keys_dir_legacy(?string $appRoot = null): string
{
    $root = $appRoot ?? (defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__, 2));
    return _tts_norm_path($root) . '/keys/tts';
}

/** The file name an engine's key is stored under. Same rule the writer always used. */
function tts_key_file_name(string $engineKey): string
{
    return preg_replace('/[^a-z0-9_\-]/i', '_', $engineKey) . '.key';
}

/** Deny rules beside the keys, wherever they are. Unconditional: a secret has no legitimate served state. */
function tts_harden_keys_dir(string $dir): void
{
    served_dir_harden($dir, 'TicketsCAD text-to-speech API keys', true);
}

/**
 * Read an engine's API key from its key file (never from the DB).
 *
 * Looks in the current directory first, then in the old in-tree one so an
 * install keeps working between the upgrade and the relocation. A name that
 * is not a bare filename is cut down to one: a stored value cannot traverse
 * out of the key directories.
 */
function tts_read_key(?string $keyRef): string
{
    $keyRef = trim((string) $keyRef);
    if ($keyRef === '') {
        return '';
    }
    $file = basename($keyRef);
    if ($file === '' || $file === '.' || $file === '..') {
        return '';
    }
    foreach ([tts_keys_dir(), tts_keys_dir_legacy()] as $dir) {
        $path = $dir . '/' . $file;
        if (is_file($path)) {
            return trim((string) @file_get_contents($path));
        }
    }
    return '';
}

/**
 * Store an engine's API key. The one writer: api/tts.php calls this, and so do
 * the tests, so what is tested is what ships.
 *
 * Refuses — rather than falling back — when the directory is inside the
 * application tree, which is the one thing that can be known for certain from
 * the filesystem. A secret written somewhere served "because the right place
 * was not writable" is the exposure this file exists to end; the caller gets a
 * message naming the directory instead.
 *
 * @return array{ok:bool,file:string,dir:string,error:string,public?:string}
 */
function tts_write_key(string $engineKey, string $key, ?string $dir = null): array
{
    $dir  = $dir ?? tts_keys_dir();
    $key  = trim($key);
    $file = tts_key_file_name($engineKey);
    // 'error' names the directory (for logs, the CLI and tests); 'public' does
    // not, because it is what an HTTP response may carry — a server path is
    // not something an error body needs to disclose.
    $fail = function (string $why, string $public) use ($dir, $file): array {
        return ['ok' => false, 'file' => $file, 'dir' => $dir, 'error' => $why, 'public' => $public];
    };

    if ($key === '') {
        return $fail('No API key was supplied.', 'No API key was supplied.');
    }
    if (served_dir_is_in_app_tree($dir)) {
        return $fail('The text-to-speech key directory (' . $dir . ') is inside the TicketsCAD '
            . 'install directory, which is published over HTTP. Define TTS_KEYS_DIR in config.php '
            . 'as a directory no web site serves, or remove the FE_KEYS_DIR override that points here.',
            'The API key was NOT stored: the text-to-speech key directory is inside the web root. '
            . 'Define TTS_KEYS_DIR in config.php as a directory no web site serves (see Settings → '
            . 'System Health, Encryption key location).');
    }
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return $fail('Cannot create the text-to-speech key directory ' . $dir
            . ' — the web server account needs write access to its parent.',
            'The API key was NOT stored: the text-to-speech key directory could not be created. '
            . 'The web server account needs write access to the keys directory (see Settings → '
            . 'System Health, Encryption key location).');
    }
    tts_harden_keys_dir($dir);

    $path = $dir . '/' . $file;
    $tmp  = $path . '.tmp' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $key, LOCK_EX) === false) {
        @unlink($tmp);
        return $fail('Cannot write to the text-to-speech key directory ' . $dir
            . ' — the web server account needs write access to it.',
            'The API key was NOT stored: the text-to-speech key directory is not writable by the '
            . 'web server account (see Settings → System Health, Encryption key location).');
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) {
        // Windows refuses to rename over a file another handle has open.
        if (@file_put_contents($path, $key, LOCK_EX) === false) {
            @unlink($tmp);
            return $fail('Cannot replace the existing key file in ' . $dir . '.',
                'The API key was NOT stored: the existing key file could not be replaced.');
        }
        @unlink($tmp);
        @chmod($path, 0640);
    }

    // A re-saved key must not leave the previous copy behind in the web tree.
    $old = tts_keys_dir_legacy() . '/' . $file;
    if (is_file($old) && _tts_norm_path($old) !== _tts_norm_path($path)) {
        @unlink($old);
    }
    return ['ok' => true, 'file' => $file, 'dir' => $dir, 'error' => '', 'public' => ''];
}

/** Key files still sitting in the old in-tree directory (bare names only). */
function tts_keys_legacy_files(?string $legacyDir = null): array
{
    $legacyDir = $legacyDir ?? tts_keys_dir_legacy();
    if (!is_dir($legacyDir)) {
        return [];
    }
    $out = [];
    foreach ((array) @scandir($legacyDir) as $name) {
        if (preg_match('/^[A-Za-z0-9_\-]+\.key$/', (string) $name) === 1
            && is_file($legacyDir . '/' . $name)) {
            $out[] = $name;
        }
    }
    sort($out);
    return $out;
}

/**
 * Move every key file out of the old directory into the current one.
 *
 * Copy, verify the bytes, THEN delete — never rename across what may be two
 * file systems, and never delete an original that has not been proven to exist
 * at the destination. Only `*.key` files with a bare name are touched: the
 * deny files this code wrote beside them are removed with the directory only
 * when nothing else is left in it (an earlier relocation script elsewhere in
 * this project moved a web.config along with the data and undid the very deny
 * it existed to provide).
 *
 * If the destination already holds a file of the same name, the destination
 * wins: either an earlier partial run put it there (identical bytes) or the
 * administrator re-saved the key after upgrading (newer bytes). In both cases
 * the old copy is stale, and leaving a secret in the web tree is the one
 * outcome to avoid.
 *
 * @return array{ok:bool,same_dir:bool,legacy_dir:string,new_dir:string,
 *               moved:string[],duplicates:string[],stale:string[],errors:string[]}
 */
function tts_keys_relocate(?string $legacyDir = null, ?string $newDir = null, bool $dryRun = false): array
{
    $legacyDir = $legacyDir ?? tts_keys_dir_legacy();
    $newDir    = $newDir ?? tts_keys_dir();
    $r = ['ok' => true, 'same_dir' => false, 'legacy_dir' => $legacyDir, 'new_dir' => $newDir,
          'moved' => [], 'duplicates' => [], 'stale' => [], 'errors' => []];

    $realOld = @realpath($legacyDir);
    $realNew = @realpath($newDir);
    if (_tts_norm_path($legacyDir) === _tts_norm_path($newDir)
        || ($realOld !== false && $realNew !== false && _tts_norm_path($realOld) === _tts_norm_path($realNew))) {
        // The operator pointed TTS_KEYS_DIR at the old directory on purpose.
        $r['same_dir'] = true;
        return $r;
    }

    $files = tts_keys_legacy_files($legacyDir);
    if (empty($files)) {
        if (!$dryRun) {
            _tts_remove_legacy_dir_if_empty($legacyDir);
        }
        return $r;
    }
    if ($dryRun) {
        $r['moved'] = $files;
        return $r;
    }

    if (!is_dir($newDir) && !@mkdir($newDir, 0750, true) && !is_dir($newDir)) {
        $r['ok'] = false;
        $r['errors'][] = 'cannot create ' . $newDir . ' — run this as the web server account, or create it '
                       . 'and give that account write access';
        return $r;
    }
    tts_harden_keys_dir($newDir);

    foreach ($files as $name) {
        $from = $legacyDir . '/' . $name;
        $to   = $newDir . '/' . $name;
        if (!is_file($from)) {
            continue;   // another run (a deploy and an admin overlapping) already moved it
        }
        $data = @file_get_contents($from);
        if ($data === false) {
            $r['ok'] = false;
            $r['errors'][] = 'cannot read ' . $from;
            continue;
        }
        // A destination is authoritative only if it is a COMPLETE file. It is
        // written below through a temp file and a rename, so a killed run cannot
        // leave a partial one — and a zero-length one (no writer here ever
        // produces an empty key) is never allowed to outrank an original that
        // has content, which would delete the only good copy.
        if (is_file($to) && (int) @filesize($to) > 0) {
            $same = hash_equals(hash('sha256', (string) @file_get_contents($to)), hash('sha256', $data));
            if (@unlink($from) || !is_file($from)) {
                $r[$same ? 'duplicates' : 'stale'][] = $name;
            } else {
                $r['ok'] = false;
                $r['errors'][] = 'already present at the destination, but cannot remove the original ' . $from;
            }
            continue;
        }
        $tmp = $to . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $data, LOCK_EX) === false) {
            @unlink($tmp);
            $r['ok'] = false;
            $r['errors'][] = 'cannot write ' . $to;
            continue;
        }
        @chmod($tmp, 0640);
        if (!hash_equals(hash('sha256', $data), (string) @hash_file('sha256', $tmp))) {
            @unlink($tmp);   // never leave a partial secret behind
            $r['ok'] = false;
            $r['errors'][] = 'copy of ' . $name . ' did not verify; original left in place';
            continue;
        }
        if (!@rename($tmp, $to)) {
            // Windows will not rename over an existing (empty) file another
            // handle has open; fall back to replacing its content, then verify again.
            $okCopy = @file_put_contents($to, $data, LOCK_EX) !== false
                   && hash_equals(hash('sha256', $data), (string) @hash_file('sha256', $to));
            @unlink($tmp);
            if (!$okCopy) {
                $r['ok'] = false;
                $r['errors'][] = 'could not place ' . $name . ' at the destination; original left in place';
                continue;
            }
            @chmod($to, 0640);
        }
        if (!@unlink($from) && is_file($from)) {
            $r['ok'] = false;
            $r['errors'][] = 'copied ' . $name . ' but cannot remove the original ' . $from;
            continue;
        }
        $r['moved'][] = $name;
    }

    _tts_remove_legacy_dir_if_empty($legacyDir);
    return $r;
}

/**
 * Take the old directory away once it holds nothing but the deny files this
 * code wrote into it. Anything else in there is someone else's and stays.
 */
function _tts_remove_legacy_dir_if_empty(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $left = array_values(array_diff((array) @scandir($dir), ['.', '..', '.htaccess', 'web.config']));
    if (!empty($left)) {
        return;
    }
    @unlink($dir . '/.htaccess');
    @unlink($dir . '/web.config');
    @rmdir($dir);
}

/**
 * The Status-page view of all this: where the keys go, whether anything
 * publishes that place, and whether any are still in the web tree.
 *
 * Layout evidence only — it cannot see another site's document root, and says
 * so through the 'blind_spot' that health_check_keys() already prints.
 *
 * @return array{active_dir:string,legacy_dir:string,legacy_files:string[],
 *               exposure:array,severity:string,summary:string,notes:string[],remedy:string}
 */
function tts_keys_exposure(?string $activeDir = null, ?string $legacyDir = null): array
{
    $active = $activeDir ?? tts_keys_dir();
    $legacy = $legacyDir ?? tts_keys_dir_legacy();

    // Judge the keys directory the `tts` folder sits in, not the folder itself.
    // served_dir_exposure() treats a web.config in a PARENT as the sign of a
    // published document root — and the parent here is the keys directory,
    // where fe_harden_keys_dir() (and this module) write a DENY web.config.
    // Asked about keys/tts directly, every Windows install would read as
    // "may be published" because of the very file that protects it, and a row
    // that is amber on every correct install is a row nobody reads. The keys
    // directory is the same question the "Encryption key location" row already
    // asks of the RSA and 2FA keys, so the two cannot disagree. (A directory
    // somebody chose by hand under another name is judged as itself.)
    $judge = (strcasecmp(basename(_tts_norm_path($active)), 'tts') === 0)
        ? dirname(_tts_norm_path($active))
        : $active;
    $x      = served_dir_exposure($judge);
    $left   = (_tts_norm_path($active) === _tts_norm_path($legacy)) ? [] : tts_keys_legacy_files($legacy);

    $severity = 'ok';
    $notes    = [];
    if ($x['served']) {
        $severity = 'critical';
        $notes[]  = 'Text-to-speech API keys are written to ' . $active . ', which is ' . $x['why']
                  . '. Define TTS_KEYS_DIR in config.php as a directory no web site publishes.';
    } elseif ($x['suspect']) {
        $severity = 'warn';
        $notes[]  = 'Text-to-speech API keys are written to ' . $active . ', which may be published ('
                  . $x['why'] . '). Define TTS_KEYS_DIR in config.php to be sure.';
    }
    if (!empty($left)) {
        if ($severity === 'ok') {
            $severity = 'warn';
        }
        $notes[] = count($left) . ' text-to-speech API key file' . (count($left) === 1 ? ' is' : 's are')
                 . ' still inside the web root (' . $legacy . '): ' . implode(', ', $left)
                 . '. Nothing moves them automatically while a web server user owns them.';
    }

    $summary = '';
    $remedy  = '';
    if ($severity !== 'ok') {
        $summary = !empty($left) && !$x['served'] && !$x['suspect']
            ? 'Text-to-speech API keys are still inside the web root'
            : 'Text-to-speech API keys may be published over HTTP';
        $remedy = !empty($left)
            ? "Move the text-to-speech keys out of the web root. Run, as the web server account:\n"
              . '  php sql/run_tts_keys_relocate.php' . "\n"
              . '(php sql/run_migrations.php does the same.) It copies each key, checks the copy, '
              . 'then deletes the original; nothing is overwritten.'
            : 'Define TTS_KEYS_DIR in config.php as a directory no web site publishes, then re-save '
              . 'each engine key under Settings → Voice & Speech.';
    }
    return ['active_dir' => $active, 'legacy_dir' => $legacy, 'legacy_files' => $left,
            'exposure' => $x, 'severity' => $severity, 'summary' => $summary,
            'notes' => $notes, 'remedy' => $remedy];
}
