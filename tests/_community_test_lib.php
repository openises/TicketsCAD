<?php
/**
 * Shared helpers for the Phase 155 community-responsiveness tests.
 * Not a test itself (underscore prefix): the runner does not execute it.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/lib/community.php';

$GLOBALS['ct_pass'] = 0;
$GLOBALS['ct_fail'] = 0;

/** Record one assertion. */
function ct(string $label, bool $ok, string $hint = ''): void
{
    if ($ok) {
        echo "[PASS] {$label}\n";
        $GLOBALS['ct_pass']++;
    } else {
        echo "[FAIL] {$label}" . ($hint !== '' ? " — {$hint}" : '') . "\n";
        $GLOBALS['ct_fail']++;
    }
}

/** The canonical closing line the suite runner requires. */
function ct_finish(): void
{
    echo "\n=== {$GLOBALS['ct_pass']} passed, {$GLOBALS['ct_fail']} failed ===\n";
    exit($GLOBALS['ct_fail'] > 0 ? 1 : 0);
}

/** A file written under the test's own temp directory. */
function ct_tmp(): string
{
    static $dir = null;
    if ($dir === null) {
        $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/ct-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($dir, 0777, true);
        register_shutdown_function(static function () use ($dir): void { ct_rmtree($dir); });
    }
    return $dir;
}

function ct_rmtree(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        @chmod($f->getPathname(), 0777);               // git object files are read-only on Windows
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/**
 * The environment a child gets: the parent's, plus overrides. Built explicitly
 * because putenv() does not reliably reach a Windows child's environment block.
 * A null override REMOVES the variable (so a test is not at the mercy of a
 * developer's own AWAY_UNTIL).
 *
 * @param array<string,?string> $over
 * @return array<string,string>
 */
function ct_env(array $over = []): array
{
    $env = getenv();
    foreach ($env as $k => $_v) {
        if (preg_match('/^(AWAY_UNTIL|TRIAGE_|COMMUNITY_|SYNC_|RELEASE_NOTIFY_|STATUS_|GH_TOKEN|GITHUB_|FAKE_GH_)/', (string) $k)) unset($env[$k]);
    }
    foreach ($over as $k => $v) {
        if ($v === null) unset($env[$k]); else $env[$k] = $v;
    }
    return $env;
}

/**
 * Environment that routes every `gh` call of a tool to the recording fake.
 *
 * @param array<int,array<string,mixed>> $rules see tests/_fake_gh.php
 * @param array<string,?string> $extra
 * @return array{env:array<string,string>,log:string}
 */
function ct_fake_gh(array $rules, array $extra = []): array
{
    $dir = ct_tmp();
    $n = bin2hex(random_bytes(3));
    $log = "{$dir}/gh-{$n}.log";
    $rulesFile = "{$dir}/gh-{$n}.rules.json";
    file_put_contents($rulesFile, json_encode($rules));
    $fake = str_replace('\\', '/', __DIR__) . '/_fake_gh.php';
    $env = ct_env($extra + [
        'COMMUNITY_GH_CMD' => json_encode([str_replace('\\', '/', PHP_BINARY), $fake]),
        'FAKE_GH_LOG'      => $log,
        'FAKE_GH_RULES'    => $rulesFile,
    ]);
    return ['env' => $env, 'log' => $log];
}

/** The calls the fake recorded. @return array<int,array{args:string[],stdin:string}> */
function ct_calls(string $log): array
{
    if (!is_file($log)) return [];
    $out = [];
    foreach (file($log, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $j = json_decode($line, true);
        if (is_array($j)) $out[] = $j;
    }
    return $out;
}

/** Calls whose space-joined arguments contain $needle. */
function ct_calls_matching(string $log, string $needle): array
{
    return array_values(array_filter(ct_calls($log), static fn(array $c): bool => strpos(implode(' ', $c['args']), $needle) !== false));
}

/**
 * Run a PHP script as a CLI subprocess.
 *
 * @param string[] $args
 * @param array<string,string>|null $env
 * @return array{code:int,out:string,err:string}
 */
function ct_php(string $script, array $args = [], ?array $env = null, ?string $cwd = null, ?string $stdin = null): array
{
    $cmd = array_merge([PHP_BINARY, $script], $args);
    return cm_run($cmd, $cwd, $stdin, $env ?? ct_env(), 120);
}

/** Write a JSON file and return its path. */
function ct_json_file(string $name, $data): string
{
    $p = ct_tmp() . '/' . $name;
    file_put_contents($p, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    return $p;
}

/** Skip the whole file, in the form the runner accepts. */
function ct_skip(string $why): void
{
    echo "SKIP: {$why}\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

function ct_have(string $bin, array $versionArgs = ['--version']): bool
{
    $r = cm_run(array_merge([$bin], $versionArgs), null, null, null, 20);
    return $r['code'] === 0 || trim($r['out']) !== '';
}

/** Run git in a directory. @return array{code:int,out:string,err:string} */
function ct_git(string $dir, array $args, array $env = []): array
{
    $e = ct_env($env);
    return cm_run(array_merge(['git', '-C', $dir], $args), null, null, $e, 60);
}

/**
 * The bash that ships with git. On Windows a bare `bash` resolves to WSL's,
 * which cannot see the Windows paths a fixture uses; git's own bash is the one
 * the release scripts are actually run with. null when there is none.
 */
function ct_bash(): ?string
{
    static $found = false;
    if ($found !== false) return $found;
    $found = null;
    $r = cm_run(['git', '--exec-path'], null, null, null, 20);
    if ($r['code'] === 0) {
        $dir = str_replace('\\', '/', trim($r['out']));
        for ($i = 0; $i < 6 && $dir !== '' && $dir !== '.' && $dir !== '/'; $i++) {
            foreach (['/bin/bash.exe', '/bin/bash'] as $suffix) {
                if (is_file($dir . $suffix)) { $found = $dir . $suffix; return $found; }
            }
            $dir = dirname($dir);
        }
    }
    if (ct_have('bash')) $found = 'bash';
    return $found;
}

/** git init with the identity and settings every fixture needs. */
function ct_repo_init(string $dir): bool
{
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    if (cm_run(['git', 'init', '-q', '-b', 'main', $dir], null, null, ct_env(), 60)['code'] !== 0) return false;
    ct_git($dir, ['config', 'user.email', 'fixture@example.invalid']);
    ct_git($dir, ['config', 'user.name', 'fixture']);
    ct_git($dir, ['config', 'commit.gpgsign', 'false']);
    ct_git($dir, ['config', 'core.autocrlf', 'false']);
    return true;
}

/** Write path => content (null deletes) under $dir. */
function ct_write(string $dir, array $files): void
{
    foreach ($files as $rel => $content) {
        $abs = $dir . '/' . $rel;
        if ($content === null) { @unlink($abs); continue; }
        if (!is_dir(dirname($abs))) mkdir(dirname($abs), 0777, true);
        file_put_contents($abs, $content);
    }
}

/**
 * Commit with a fixed date, so ages are exact and a test never sleeps.
 * Returns the full sha.
 */
function ct_commit(string $dir, array $files, string $message, string $isoDate): string
{
    ct_write($dir, $files);
    ct_git($dir, ['add', '-A']);
    ct_git($dir, ['commit', '-q', '--allow-empty', '-m', $message], ['GIT_AUTHOR_DATE' => $isoDate, 'GIT_COMMITTER_DATE' => $isoDate]);
    return trim(ct_git($dir, ['rev-parse', 'HEAD'])['out']);
}
