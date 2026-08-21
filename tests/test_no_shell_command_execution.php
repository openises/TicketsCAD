<?php
/**
 * Gate: no PHP in this application may hand a command line to a shell.
 *
 * openises/TicketsCAD PR #10 (@rjonesbsink) replaced eight exec()/shell_exec()
 * call sites with argv-array proc_open(), because `disable_functions` on
 * hardened Windows/IIS installs removes exec/shell_exec and PHP's @ operator
 * does not suppress the resulting "Call to undefined function" fatal — the
 * request died mid-flight with an empty body.
 *
 * That change also removed every escapeshellarg() at those sites, which looks
 * alarming and is in fact correct: escapeshellarg() is a SHELL-QUOTING
 * function, and after the change there is no shell. Array-form proc_open goes
 * straight to execvp/CreateProcess, so `;`, `|`, `$(…)` and backticks inside an
 * argument are inert data rather than syntax. Re-adding escapeshellarg there
 * would be an active bug — the child would receive literal quote characters.
 *
 * ── WHY A TEST AND NOT A COMMENT ─────────────────────────────────────
 *
 * The safety of those sites rests on a property of the CODE SHAPE, not of the
 * data: "the thing handed to the OS is a list of discrete arguments, and no
 * shell parses it." A comment cannot hold that line across future edits, and
 * the `array $argv` type hints on the two new helpers only protect those two
 * helpers — nothing stops a seventh call site appearing next month with
 * proc_open("$bin $userInput"), which runs /bin/sh -c and is an injection.
 *
 * Four rules:
 *
 *   A. Every proc_open() first argument is an array — either an inline literal,
 *      or a variable this file proves is an array (an `array $x` parameter, or
 *      a variable whose every assignment is an array literal). Two pre-existing
 *      string-form sites are an explicit allowlist. A NEW one fails.
 *   B. No argv-shaped array literal contains a superglobal or builds an element
 *      by concatenation. This is the "what if an element later becomes
 *      caller-influenced" guard: the day someone writes
 *      ['php', 'script.php', '--id=' . $_GET['id']] the suite goes red.
 *   C. If argv[0] IS a shell interpreter (sh/bash/cmd/powershell/…), every
 *      remaining element must be a literal string. A static PowerShell probe is
 *      fine; `['sh','-c',$cmd]` is a shell command line wearing an array
 *      costume and must be justified in the allowlist.
 *   D. The six files PR #10 converted never regain exec/shell_exec/system/
 *      passthru/popen or the backtick operator. Guards against a revert, and
 *      against someone "fixing" a hardened-host bug by putting them back.
 *
 * Limits worth stating: this is a LEXICAL gate. It cannot follow a variable
 * back through a function boundary, so `$bin = $_GET['x']; proc_open([$bin])`
 * satisfies A and B. What it does guarantee is that no shell is involved,
 * which removes the metacharacter class of injection entirely — a
 * caller-influenced element can then only ever be one argument to a fixed
 * program, never a second command.
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');

$tests = 0;
$fails = 0;

function sh(bool $cond, string $label): void
{
    global $tests, $fails;
    $tests++;
    if (!$cond) {
        $fails++;
        echo "FAIL: $label\n";
    }
}

// ── Pre-existing string-form proc_open sites (rule A allowlist) ──────
// Frozen deliberately. Both are bounded by a deadline + proc_terminate(), and
// neither is touched by PR #10. Adding to this list is a decision someone has
// to make on purpose, in a diff a reviewer will see.
//
//   inc/tts/engine.php       tts_run_pipe(string $cmd, …)  — non-blocking
//     reads, 30 s deadline, proc_terminate(). Command built by the TTS engine
//     drivers from admin-configured binary paths.
//   proxy/ZelloProxyApp.php  runPipe(string $cmd, …)       — same shape, so a
//     wedged synth cannot block the proxy event loop.
$allowedStringForm = [
    'inc/tts/engine.php'      => ['$cmd'],
    'proxy/ZelloProxyApp.php' => ['$cmd'],
];

// ── Deliberate shell invocations (rule C allowlist) ──────────────────
//   tools/install_fresh.php  ['sh','-c',$cmd] — an explicit shell is required
//     for `command -v mariadb || command -v mysql` plus a stdin redirect.
//     Every interpolated value inside $cmd is escapeshellarg()'d at the point
//     of construction. Reviewed and accepted; do not copy the pattern.
$allowedShellInvocation = ['tools/install_fresh.php'];

// ── The files PR #10 (and later follow-ups) convert (rule D) ─────────
// Value is the list of shellFunctions names that file is DELIBERATELY still
// permitted to reference — always because that specific call site is (a)
// reached only behind a function_exists() guard that explains the real
// cause instead of crashing, and (b) independently regression-tested
// elsewhere. An empty list means the file must never reference ANY of
// $shellFunctions (or the backtick operator) again, full stop.
$convertedFiles = [
    'api/health.php'               => [],
    'inc/tts/engine.php'           => [],
    'proxy/ZelloProxyApp.php'      => [],
    'sql/run_migrations.php'       => [],
    'tools/check-schema.php'       => [],
    'tools/install_fresh.php'      => [],
    // openises/TicketsCAD #93 follow-up (2026-08-20): the three popen() call
    // sites that streamed live import progress (amateur/GMRS/zip-code) are
    // converted to runStreamingImport()'s argv-array proc_open(), the same
    // exec()->proc_open() move PR #10 made elsewhere — so a future
    // regression (someone reintroducing popen()) is caught the same way.
    // extractZip()'s pre-existing, SEPARATE exec() fallback (for hosts with
    // no PHP zip extension) is untouched by this follow-up and stays exempt
    // here: it is reached only behind a function_exists('exec') guard, and
    // is proven reachable-and-safe by tests/test_gh93_lookup_data_extraction.php's
    // Test C. popen/shell_exec/system/passthru and the backtick operator
    // remain fully forbidden in this file.
    'tools/update-lookup-data.php' => ['exec'],
];

/** Functions whose first argument is an argv array, for rules B and C. */
$argvTakingCalls  = ['proc_open', 'runshellcapture', 'run_via_proc_open'];
$shellInterpreters = ['sh', 'bash', 'zsh', 'dash', 'ksh', 'csh',
                      'cmd', 'cmd.exe', 'command.com',
                      'powershell', 'powershell.exe', 'pwsh', 'pwsh.exe'];
$shellFunctions   = ['exec', 'shell_exec', 'system', 'passthru', 'popen'];
$superglobalNames = ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SERVER', '$_FILES', '$_ENV', '$GLOBALS'];

// ── Collect the files to scan ────────────────────────────────────────
$scanRoots = ['api', 'inc', 'sql', 'tools', 'proxy'];
$files = glob($root . '/*.php') ?: [];
foreach ($scanRoots as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) continue;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
            $files[] = $f->getPathname();
        }
    }
}
$files = array_values(array_filter($files, static function (string $p): bool {
    $n = str_replace('\\', '/', $p);
    return strpos($n, '/vendor/') === false
        && strpos($n, '/node_modules/') === false
        && strpos($n, '/.claude/') === false;
}));

sh($files !== [], 'found PHP files to scan (scan roots resolved)');

/** Significant tokens only — comments and whitespace dropped, lines kept. */
function sig_tokens(string $src): array
{
    $out = [];
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
            $out[] = ['id' => $t[0], 'text' => $t[1], 'line' => $t[2]];
        } else {
            $out[] = ['id' => null, 'text' => $t, 'line' => 0];
        }
    }
    return $out;
}

function rel(string $abs, string $root): string
{
    return ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');
}

/** True when the `[` at $i opens an array LITERAL rather than an index access. */
function opens_array_literal(array $toks, int $i): bool
{
    $prev = $toks[$i - 1] ?? null;
    if ($prev === null) return true;
    if ($prev['id'] === T_VARIABLE) return false;                       // $x[…]
    if ($prev['id'] === T_STRING) return false;                         // foo()[…] / CONST[…]
    if ($prev['id'] === T_CONSTANT_ENCAPSED_STRING) return false;       // 'abc'[…]
    if ($prev['id'] === null && in_array($prev['text'], [']', ')'], true)) return false;
    return true;
}

/** Index of the `]` matching the `[` at $open. */
function match_bracket(array $toks, int $open): int
{
    $depth = 0;
    for ($j = $open, $n = count($toks); $j < $n; $j++) {
        if ($toks[$j]['text'] === '[') { $depth++; continue; }
        if ($toks[$j]['text'] === ']') { $depth--; if ($depth === 0) return $j; }
    }
    return count($toks) - 1;
}

/**
 * Every array literal assigned to $name in this file, as token slices.
 * Handles `$x = [...]` and `$x = $cond ? [...] : [...]`.
 */
function literal_slices_for(array $toks, string $name): array
{
    $slices = [];
    $n = count($toks);
    for ($i = 0; $i < $n - 1; $i++) {
        if ($toks[$i]['id'] !== T_VARIABLE || $toks[$i]['text'] !== $name) continue;
        if (($toks[$i + 1]['text'] ?? '') !== '=') continue;
        for ($j = $i + 2; $j < $n; $j++) {
            if ($toks[$j]['id'] === null && $toks[$j]['text'] === ';') break;
            if ($toks[$j]['text'] === '[' && opens_array_literal($toks, $j)) {
                $close = match_bracket($toks, $j);
                $slices[] = [$toks[$i]['line'], array_slice($toks, $j + 1, $close - $j - 1)];
                $j = $close;
            }
        }
    }
    return $slices;
}

/**
 * Is $name provably an array within this file? True when it is declared as an
 * `array $name` parameter, or when it has at least one assignment and EVERY
 * assignment's right-hand side contains an array literal.
 */
function provably_array(array $toks, string $name): bool
{
    $n = count($toks);
    for ($i = 1; $i < $n; $i++) {
        if ($toks[$i]['id'] === T_VARIABLE && $toks[$i]['text'] === $name
            && ($toks[$i - 1]['id'] ?? null) === T_ARRAY) {
            return true;   // `array $name` parameter (T_ARRAY is the type hint)
        }
    }

    $assignments = 0;
    $allArrays   = true;
    for ($i = 0; $i < $n - 1; $i++) {
        if ($toks[$i]['id'] !== T_VARIABLE || $toks[$i]['text'] !== $name) continue;
        if (($toks[$i + 1]['text'] ?? '') !== '=') continue;
        $assignments++;
        $sawLiteral = false;
        for ($j = $i + 2; $j < $n; $j++) {
            if ($toks[$j]['id'] === null && $toks[$j]['text'] === ';') break;
            if ($toks[$j]['text'] === '[' && opens_array_literal($toks, $j)) {
                $sawLiteral = true;
                $j = match_bracket($toks, $j);
            }
        }
        if (!$sawLiteral) $allArrays = false;
    }
    return $assignments > 0 && $allArrays;
}

// ── Rule A: proc_open's first argument ───────────────────────────────
$stringFormViolations = [];
$procOpenSitesSeen    = 0;
$argvLiterals         = [];   // [relPath, line, token slice]

foreach ($files as $abs) {
    $src = file_get_contents($abs);
    if ($src === false) continue;
    $interesting = false;
    foreach ($argvTakingCalls as $fn) {
        if (stripos($src, $fn) !== false) { $interesting = true; break; }
    }
    if (!$interesting && strpos($src, '$argv =') === false) continue;

    $relPath = rel($abs, $root);
    $toks    = sig_tokens($src);
    $n       = count($toks);

    for ($i = 0; $i < $n; $i++) {
        if ($toks[$i]['id'] !== T_STRING) continue;
        $fname = strtolower($toks[$i]['text']);
        if (!in_array($fname, $argvTakingCalls, true)) continue;
        if (($toks[$i + 1]['text'] ?? '') !== '(') continue;
        $prev = $toks[$i - 1] ?? null;
        if ($prev && in_array($prev['id'], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) continue;

        $line  = $toks[$i]['line'];
        $first = $toks[$i + 2] ?? null;
        if ($first === null) continue;

        if ($fname === 'proc_open') {
            $procOpenSitesSeen++;
            if ($first['text'] !== '[') {
                $isArrayVar = ($first['id'] === T_VARIABLE && provably_array($toks, $first['text']));
                $ok = $isArrayVar
                    || (isset($allowedStringForm[$relPath])
                        && in_array($first['text'], $allowedStringForm[$relPath], true));
                if (!$ok) {
                    $stringFormViolations[] = "{$relPath}:{$line} — first argument is `{$first['text']}`, "
                        . 'which this file does not prove is an array';
                }
                // An argv held in a variable is still an argv: pull its literals
                // in so rules B and C apply to it too. Without this,
                // `$shell = ['sh','-c',$cmd]; proc_open($shell, …)` would slip
                // past the shell-interpreter rule that exists precisely for it.
                if ($isArrayVar) {
                    foreach (literal_slices_for($toks, $first['text']) as [$ln, $slice]) {
                        $argvLiterals[] = [$relPath, $ln, $slice];
                    }
                }
            }
        }

        if ($first['text'] === '[') {
            $close = match_bracket($toks, $i + 2);
            $argvLiterals[] = [$relPath, $line, array_slice($toks, $i + 3, $close - $i - 3)];
        }
    }

    // Also inspect `$argv = [...]` / `$argv = $cond ? [...] : [...]`, which is
    // how the new helpers' callers build their argument lists.
    for ($i = 0; $i < $n - 1; $i++) {
        if ($toks[$i]['id'] !== T_VARIABLE || $toks[$i]['text'] !== '$argv') continue;
        if (($toks[$i + 1]['text'] ?? '') !== '=') continue;
        for ($j = $i + 2; $j < $n; $j++) {
            if ($toks[$j]['id'] === null && $toks[$j]['text'] === ';') break;
            if ($toks[$j]['text'] === '[' && opens_array_literal($toks, $j)) {
                $close = match_bracket($toks, $j);
                $argvLiterals[] = [$relPath, $toks[$i]['line'], array_slice($toks, $j + 1, $close - $j - 1)];
                $j = $close;
            }
        }
    }
}

sh($procOpenSitesSeen > 0, 'at least one proc_open() call site was located (the scanner is really reading code)');

sh($stringFormViolations === [],
    'every proc_open() outside the documented allowlist receives an array — '
    . 'the string form runs /bin/sh -c and reintroduces command injection'
    . ($stringFormViolations ? "\n        " . implode("\n        ", $stringFormViolations) : ''));

sh($argvLiterals !== [], 'at least one argv array literal was located (rules B and C have something to check)');

// ── Rules B and C over every argv-shaped literal ─────────────────────
$argvViolations  = [];
$shellViolations = [];

foreach ($argvLiterals as [$relPath, $line, $elems]) {
    $depth = 0;
    foreach ($elems as $k => $t) {
        if ($t['text'] === '[' || $t['text'] === '(') { $depth++; continue; }
        if ($t['text'] === ']' || $t['text'] === ')') { $depth--; continue; }

        if ($t['id'] === T_VARIABLE && in_array($t['text'], $superglobalNames, true)) {
            $argvViolations[] = "{$relPath}:{$line} — argv contains the superglobal {$t['text']}";
        }
        if ($t['id'] === null && $t['text'] === '.') {
            $argvViolations[] = "{$relPath}:{$line} — argv builds an element by concatenation "
                . '(keep every element a whole, discrete argument)';
        }
        if ($t['id'] === T_ENCAPSED_AND_WHITESPACE || $t['id'] === T_CURLY_OPEN
            || $t['id'] === T_DOLLAR_OPEN_CURLY_BRACES) {
            $argvViolations[] = "{$relPath}:{$line} — argv interpolates a variable into a double-quoted element";
        }
    }

    // Rule C — argv[0] is a shell interpreter.
    $first = $elems[0] ?? null;
    if ($first && $first['id'] === T_CONSTANT_ENCAPSED_STRING) {
        $prog = strtolower(trim($first['text'], "'\""));
        $prog = basename(str_replace('\\', '/', $prog));
        if (in_array($prog, $shellInterpreters, true)
            && !in_array($relPath, $allowedShellInvocation, true)) {
            foreach ($elems as $t) {
                if ($t['id'] === T_VARIABLE) {
                    $shellViolations[] = "{$relPath}:{$line} — argv[0] is the shell `{$prog}` and a later "
                        . "element is the variable {$t['text']}; that is a shell command line, "
                        . 'not an argument list';
                    break;
                }
            }
        }
    }
}

sh($argvViolations === [],
    'no argv array contains a superglobal, a concatenated element, or an interpolated string'
    . ($argvViolations ? "\n        " . implode("\n        ", array_unique($argvViolations)) : ''));

sh($shellViolations === [],
    'no undocumented shell interpreter is invoked with a variable argument'
    . ($shellViolations ? "\n        " . implode("\n        ", array_unique($shellViolations)) : ''));

// ── Rule D: the converted files never regain a shell-executing call ──
foreach ($convertedFiles as $relPath => $exempt) {
    $abs = $root . '/' . $relPath;
    if (!is_file($abs)) {
        sh(false, "{$relPath} exists (expected — it is one of the files PR #10 (or a follow-up) converts)");
        continue;
    }
    $toks  = sig_tokens((string) file_get_contents($abs));
    $n     = count($toks);
    $found = [];

    for ($i = 0; $i < $n; $i++) {
        if ($toks[$i]['id'] === null && $toks[$i]['text'] === '`') {
            if (in_array('`', $exempt, true)) continue;
            $found[] = 'backtick operator';
            continue;
        }
        if ($toks[$i]['id'] !== T_STRING) continue;
        $name = strtolower($toks[$i]['text']);
        if (!in_array($name, $shellFunctions, true)) continue;
        if (in_array($name, $exempt, true)) continue;
        if (($toks[$i + 1]['text'] ?? '') !== '(') continue;
        $prev = $toks[$i - 1] ?? null;
        if ($prev && in_array($prev['id'], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) continue;
        $found[] = $name . '() at line ' . $toks[$i]['line'];
    }

    sh($found === [],
        "{$relPath} spawns processes only via argv-array proc_open()"
        . ($found ? ' — found ' . implode(', ', array_unique($found)) : ''));
}

// ── The helpers keep the type declaration that blocks the string form ──
// runShellCapture(array $argv) / run_via_proc_open(array $argv, …) cannot be
// handed a command string without a TypeError. That is the cheapest possible
// enforcement at those call sites, so it must not be relaxed to `mixed` or
// dropped. Only asserted once the helpers exist (i.e. after PR #10 merges).
$helperChecks = [
    // runShellCapture() moved out of api/health.php into inc/host-uptime.php
    // when the Windows uptime probe gained its PowerShell fallback. The check
    // below self-skips when a file does not declare the function, so leaving
    // the stale path here would have quietly stopped checking anything.
    'inc/host-uptime.php'          => 'runShellCapture',
    'tools/check-schema.php'       => 'run_via_proc_open',
    'tools/update-lookup-data.php' => 'runStreamingImport',
];
foreach ($helperChecks as $relPath => $fn) {
    $abs = $root . '/' . $relPath;
    if (!is_file($abs)) continue;
    $src = (string) file_get_contents($abs);
    if (strpos($src, 'function ' . $fn) === false) continue;   // not merged yet
    sh(preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(\s*array\s+\$/', $src) === 1,
        "{$relPath}: {$fn}() still declares its first parameter as `array` "
        . '(a string parameter would let a shell command line reach proc_open)');
}

echo "Shell-execution gate: " . ($tests - $fails) . " passed, $fails failed\n";
exit($fails ? 1 : 0);
