<?php
/**
 * Gate: every audit_log() call must match the function's real signature, and
 * must be able to run.
 *
 *   php tools/audit_log_arity.php                 # the whole tree (CI / pre-commit)
 *   php tools/audit_log_arity.php --path=<dir>    # one directory (the test's fixtures)
 *
 * The signature is READ FROM THE LIVE FUNCTION (ReflectionFunction over
 * inc/audit.php), never copied into this file, so adding a parameter to
 * audit_log() can never leave this gate checking yesterday's shape:
 *
 *   audit_log(string $category, string $activity, ?string $targetType = null,
 *             $targetId = null, string $summary = '', ?array $details = null,
 *             int $severity = AUDIT_INFO, ?array $actor = null)
 *
 * (audit_login() and audit_data_access(), the two wrappers in the same file, are
 * checked the same way.)
 *
 * ── CHECK 1: ARGUMENTS ────────────────────────────────────────────────────
 *
 * A wrong argument is a TypeError, and TypeError extends Error, not Exception.
 * The `catch (Exception $e)` that wraps almost every API handler does not see it,
 * and with display_errors off (every endpoint sets that, deliberately, so a
 * warning cannot corrupt JSON) the request dies with an EMPTY BODY. The browser
 * then reports "Unexpected end of JSON input". Worse, the writes before the call
 * have usually committed, so the action appears to half-work: the user sees an
 * error and the change happened. A beta tester hit exactly that on the
 * mesh-bridge delete (2026-07-28), and api/aprs-license-accept.php passed
 * ('settings|aprs|license_attestation', "<summary>", [details]) -- three
 * arguments in the shape of an older logger, the array landing in the
 * `?string $targetType` slot -- so its legal-trail row could never be written.
 *
 * A wrong argument is invisible to `php -l`, invisible to a smoke test that only
 * checks the row is gone, and invisible until a real user clicks the button.
 *
 * The first version of this gate looked for ONE shape (a details array in the
 * fifth slot) with a character scanner. This one tokenizes (so a string, a
 * comment or a heredoc that merely MENTIONS audit_log( can never be mistaken for
 * a call, and the parentheses inside an interpolated string cannot unbalance the
 * argument split) and checks every argument against the declared parameter type
 * wherever the argument's type is certain from its spelling: an array literal, a
 * string literal or concatenation, an int or float literal, null, a cast, or a
 * call to a function whose return type is fixed. Anything it cannot know (a
 * variable, a ternary, a method call) is left alone -- a gate that guesses
 * trains people to baseline it.
 *
 * ── CHECK 2: CAN IT RUN ───────────────────────────────────────────────────
 *
 * The same endpoint carried a second, independent reason its row was missing: the
 * call sat behind `if (function_exists('audit_log'))`, and the file never loaded
 * inc/audit.php -- neither api/auth.php nor inc/rbac.php does -- so on that
 * request path the guard was false and the call was skipped without a sound.
 * Fixing the arguments alone would have left the row unwritten.
 *
 * So an ENTRY POINT (any file outside inc/ -- an api endpoint, a page, a tool)
 * that calls audit_log() must load inc/audit.php itself or through a file it
 * includes. Includes are followed statically: a require/include whose target is
 * spelled with __DIR__, dirname(__DIR__[, n]) and string literals is resolved and
 * read in turn. An include this gate cannot resolve is treated as "might load it"
 * (the gate stays silent rather than guess), and the rule applies only where the
 * answer is certain.
 *
 * Findings are listed with file:line. Verified false positives go in
 * tools/audit_log_arity_baseline.txt ("path:line  reason" or "path  reason" per
 * line), with the reasoning written next to the entry.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/lib/php_static.php';

// ═══════════════════════════════════════════════════════════════════════════
// Library part -- pure functions the test drives directly.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * The signature of each checked function, read from the real definition.
 *
 * @return array<string, array{required:int, params: list<array{name:string,type:string,nullable:bool}>}>
 */
function alc_signatures(string $auditFile): array
{
    // inc/audit.php only defines constants and functions; loading it is how the
    // signature is learned. Guarded so a caller that already loaded it is fine.
    if (!function_exists('audit_log')) {
        require_once $auditFile;
    }
    $out = [];
    foreach (['audit_log', 'audit_login', 'audit_data_access'] as $fn) {
        if (!function_exists($fn)) { continue; }
        $rf = new ReflectionFunction($fn);
        $params = [];
        foreach ($rf->getParameters() as $p) {
            $t = $p->getType();
            $type = 'mixed';
            $nullable = true;
            if ($t instanceof ReflectionNamedType) {
                $type = $t->getName();
                $nullable = $t->allowsNull();
            }
            $params[] = ['name' => $p->getName(), 'type' => $type, 'nullable' => $nullable];
        }
        $out[$fn] = ['required' => $rf->getNumberOfRequiredParameters(), 'params' => $params];
    }
    return $out;
}

/** Functions whose return type is fixed and certain, for argument classification. */
function alc_fixed_return_kinds(): array
{
    static $m = null;
    if ($m !== null) { return $m; }
    $m = [];
    foreach (['sprintf', 'implode', 'join', 'json_encode', 'trim', 'ltrim', 'rtrim', 'substr', 'strtolower', 'strtoupper',
              'ucfirst', 'ucwords', 'lcfirst', 'str_replace', 'number_format', 'date', 'gmdate', 'htmlspecialchars',
              'strval', 'print_r', 'var_export', 'str_pad', 'str_repeat', 'basename', 'dirname', 'mb_substr', 'preg_replace'] as $f) {
        $m[$f] = 'string';
    }
    foreach (['array_merge', 'array_filter', 'array_map', 'array_values', 'array_keys', 'array_slice', 'array_unique',
              'array_combine', 'array_diff', 'array_intersect', 'compact', 'explode', 'str_split', 'array_column'] as $f) {
        $m[$f] = 'array';
    }
    foreach (['intval', 'count', 'strlen', 'time', 'abs', 'max_int'] as $f) {
        $m[$f] = 'int';
    }
    return $m;
}

/**
 * Split a call's tokens into arguments. $sig is the significant-token list and
 * $open the index of the call's "(". Returns [list of argument token lists, index of the ")"].
 */
function alc_split_args(array $sig, int $open): array
{
    $args = [];
    $cur = [];
    $depth = 1;
    $n = count($sig);
    for ($i = $open + 1; $i < $n; $i++) {
        [$id, $text] = $sig[$i];
        if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES
            || $text === '(' || $text === '[' || $text === '{') {
            $depth++;
        } elseif ($text === ')' || $text === ']' || $text === '}') {
            $depth--;
            if ($depth === 0) {
                if ($cur) { $args[] = $cur; }
                return [$args, $i];
            }
        } elseif ($text === ',' && $depth === 1 && $id === null) {
            $args[] = $cur;
            $cur = [];
            continue;
        }
        $cur[] = $sig[$i];
    }
    return [$args, $n - 1];   // unterminated: the file does not parse; report what we have
}

/** The index of the bracket that closes the one at $from, within $toks. */
function alc_matching(array $toks, int $from): int
{
    $depth = 0;
    for ($i = $from, $n = count($toks); $i < $n; $i++) {
        [$id, $text] = $toks[$i];
        if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES || $text === '(' || $text === '[' || $text === '{') {
            $depth++;
        } elseif ($text === ')' || $text === ']' || $text === '}') {
            $depth--;
            if ($depth === 0) { return $i; }
        }
    }
    return -1;
}

/**
 * What can be said for CERTAIN about one argument's type from how it is spelled.
 * Returns one of: array, string, int, float, bool, null, spread, unknown.
 */
function alc_kind(array $toks): string
{
    if (!$toks) { return 'unknown'; }
    if ($toks[0][0] === T_ELLIPSIS) { return 'spread'; }

    $n = count($toks);

    // Any top-level operator other than "." makes the type unknowable from here.
    $depth = 0;
    $hasConcat = false;
    $inDq = false;
    for ($i = 0; $i < $n; $i++) {
        [$id, $text] = $toks[$i];
        if ($id === null && $text === '"') { $inDq = !$inDq; continue; }
        if ($inDq) { continue; }
        if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES || $text === '(' || $text === '[' || $text === '{') { $depth++; continue; }
        if ($text === ')' || $text === ']' || $text === '}') { $depth--; continue; }
        if ($depth !== 0) { continue; }
        if ($id === null && $text === '.') { $hasConcat = true; continue; }
        if ($id === null && in_array($text, ['?', ':', '+', '-', '*', '/', '%', '&', '|', '^', '<', '>', '='], true)
            && !($text === '-' && $i === 0)) {
            return 'unknown';
        }
        if (in_array($id, [T_COALESCE, T_BOOLEAN_AND, T_BOOLEAN_OR, T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_IDENTICAL,
                           T_IS_NOT_IDENTICAL, T_INSTANCEOF, T_LOGICAL_AND, T_LOGICAL_OR, T_SPACESHIP,
                           T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_SL, T_SR], true)) {
            return 'unknown';
        }
    }
    if ($hasConcat) { return 'string'; }

    [$id0, $t0] = $toks[0];
    [$idN, $tN] = $toks[$n - 1];

    if ($n === 1) {
        if ($id0 === T_CONSTANT_ENCAPSED_STRING) { return 'string'; }
        if ($id0 === T_LNUMBER) { return 'int'; }
        if ($id0 === T_DNUMBER) { return 'float'; }
        if ($id0 === T_STRING) {
            $l = strtolower($t0);
            if ($l === 'null') { return 'null'; }
            if ($l === 'true' || $l === 'false') { return 'bool'; }
        }
        return 'unknown';
    }
    if ($n === 2 && $t0 === '-' && ($toks[1][0] === T_LNUMBER || $toks[1][0] === T_DNUMBER)) {
        return $toks[1][0] === T_LNUMBER ? 'int' : 'float';
    }
    if ($t0 === '[' && alc_matching($toks, 0) === $n - 1) { return 'array'; }
    if ($id0 === T_ARRAY && ($toks[1][1] ?? '') === '(' && alc_matching($toks, 1) === $n - 1) { return 'array'; }
    if ($id0 === null && $t0 === '"' && $tN === '"') { return 'string'; }
    if ($id0 === T_START_HEREDOC && $idN === T_END_HEREDOC) { return 'string'; }
    if ($id0 === T_STRING_CAST) { return 'string'; }
    if ($id0 === T_INT_CAST) { return 'int'; }
    if ($id0 === T_DOUBLE_CAST) { return 'float'; }
    if ($id0 === T_ARRAY_CAST) { return 'array'; }
    if ($id0 === T_BOOL_CAST) { return 'bool'; }

    // A plain function call with a fixed return type.
    if ($id0 === T_STRING && ($toks[1][1] ?? '') === '(' && alc_matching($toks, 1) === $n - 1) {
        $kinds = alc_fixed_return_kinds();
        return $kinds[strtolower($t0)] ?? 'unknown';
    }
    return 'unknown';
}

/** May an argument whose certain kind is $kind be passed to a parameter of this type? */
function alc_compatible(string $paramType, bool $nullable, string $kind, bool $strict): bool
{
    if ($kind === 'unknown' || $kind === 'spread') { return true; }
    if ($kind === 'null') { return $nullable; }
    switch ($paramType) {
        case 'mixed':
            return true;
        case 'string':
            if ($kind === 'string') { return true; }
            // Weak mode coerces a scalar to string; strict mode does not.
            return !$strict && in_array($kind, ['int', 'float', 'bool'], true);
        case 'int':
            if ($kind === 'int') { return true; }
            return !$strict && in_array($kind, ['float', 'bool'], true);
        case 'float':
            return $kind === 'float' || $kind === 'int';
        case 'bool':
            return $kind === 'bool' || (!$strict && in_array($kind, ['int', 'float'], true));
        case 'array':
            return $kind === 'array';
        default:
            return true;
    }
}

/**
 * Scan one PHP source string. Returns [findings, calls checked], where a finding
 * is ['line' => int, 'fn' => string, 'msg' => string].
 */
function alc_scan_source(string $src, array $sigs): array
{
    $raw = @token_get_all($src);
    if (!$raw) { return [[], 0]; }

    $strict = (bool) preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)/i', $src);

    // Significant tokens only: [id|null, text, line].
    $sig = [];
    $line = 1;
    foreach ($raw as $t) {
        if (is_array($t)) {
            [$id, $text] = $t;
            $ln = $t[2];
            $line = $ln + substr_count($text, "\n");
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
            $sig[] = [$id, $text, $ln];
        } else {
            $sig[] = [null, $t, $line];
        }
    }

    $findings = [];
    $checked = 0;
    $n = count($sig);
    for ($i = 0; $i < $n - 1; $i++) {
        [$id, $text, $ln] = $sig[$i];
        $isName = $id === T_STRING || (defined('T_NAME_FULLY_QUALIFIED') && $id === T_NAME_FULLY_QUALIFIED);
        if (!$isName) { continue; }
        $name = strtolower(ltrim($text, '\\'));
        if (!isset($sigs[$name]) || $sig[$i + 1][1] !== '(') { continue; }

        // Not a call: a declaration, a method, a static, a constructor, a const.
        $prevId = $i > 0 ? $sig[$i - 1][0] : null;
        if (in_array($prevId, [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_CONST, T_USE], true)
            || (defined('T_NULLSAFE_OBJECT_OPERATOR') && $prevId === T_NULLSAFE_OBJECT_OPERATOR)) {
            continue;
        }

        [$args, ] = alc_split_args($sig, $i + 1);
        $checked++;
        $def = $sigs[$name];
        $params = $def['params'];

        $hasSpread = false; $hasNamed = false;
        foreach ($args as $a) {
            if (($a[0][0] ?? null) === T_ELLIPSIS) { $hasSpread = true; }
            if (($a[0][0] ?? null) === T_STRING && ($a[1][1] ?? '') === ':' && ($a[1][0] ?? null) === null) { $hasNamed = true; }
        }

        if (!$hasSpread && !$hasNamed) {
            if (count($args) < $def['required']) {
                $findings[] = ['line' => $ln, 'fn' => $name,
                    'msg' => sprintf('%d argument(s) given, %d required (%s)', count($args), $def['required'],
                        implode(', ', array_map(static fn($p) => '$' . $p['name'], array_slice($params, 0, $def['required'])))) ];
            }
            if (count($args) > count($params)) {
                $findings[] = ['line' => $ln, 'fn' => $name,
                    'msg' => sprintf('%d arguments given but the function takes %d -- a surplus argument is silently ignored, which means the call was written for a different signature', count($args), count($params))];
            }
        }

        foreach ($args as $pos => $a) {
            $p = null;
            if (($a[0][0] ?? null) === T_STRING && ($a[1][1] ?? '') === ':' && ($a[1][0] ?? null) === null) {
                foreach ($params as $cand) { if ($cand['name'] === $a[0][1]) { $p = $cand; } }
                $a = array_slice($a, 2);
            } else {
                $p = $params[$pos] ?? null;
            }
            if ($p === null) { continue; }
            $kind = alc_kind($a);
            if (!alc_compatible($p['type'], $p['nullable'], $kind, $strict)) {
                $findings[] = ['line' => $a[0][2] ?? $ln, 'fn' => $name,
                    'msg' => sprintf('argument %d ($%s) expects %s%s but is %s %s -- a TypeError at the call (an Error, so catch (Exception) does not see it)',
                        $pos + 1, $p['name'], ($p['nullable'] && $p['type'] !== 'mixed') ? '?' : '', $p['type'],
                        in_array($kind, ['array', 'int', 'float', 'bool'], true) ? 'an' : 'a', $kind === 'null' ? 'null literal' : $kind . ' literal')];
            }
        }
    }
    return [$findings, $checked];
}

/**
 * Does this file load inc/audit.php, directly or through the files it includes?
 * Returns true, false, or null when an include could not be resolved (unknown).
 * (The include-following is shared with tools/csrf_coverage_audit.php: tools/lib/php_static.php.)
 *
 * @param array<string,bool> $seen  loop guard
 */
function alc_loads_audit(string $file, array &$seen = [], array $consts = []): ?bool
{
    return ps_closure_matches($file, '#(^|/)audit\.php$#', $consts, $seen);
}

/** Lines of the calls to $fn in $src (tokenized), for the reachability finding's location. */
function alc_calls_of(string $src, string $fn): array
{
    $raw = @token_get_all($src);
    $lines = [];
    $prevId = null; $prevLine = 1;
    for ($i = 0, $n = count($raw); $i < $n; $i++) {
        $t = $raw[$i];
        if (!is_array($t)) { if (trim((string) $t) !== '') { $prevId = null; } continue; }
        if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        if ($t[0] === T_STRING && strtolower($t[1]) === $fn) {
            $j = $i + 1;
            while ($j < $n && is_array($raw[$j]) && $raw[$j][0] === T_WHITESPACE) { $j++; }
            if (($raw[$j] ?? '') === '(' && !in_array($prevId, [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) {
                $lines[] = $t[2];
            }
        }
        $prevId = $t[0];
    }
    return $lines;
}


// ═══════════════════════════════════════════════════════════════════════════
// Command-line part
// ═══════════════════════════════════════════════════════════════════════════

if (defined('AUDIT_LOG_ARITY_LIBRARY_ONLY')) { return; }

$appRoot = str_replace('\\', '/', dirname(__DIR__));
$opts = getopt('', ['path:', 'baseline:', 'no-baseline']);
$scanRoot = isset($opts['path']) ? str_replace('\\', '/', rtrim((string) $opts['path'], '/\\')) : $appRoot;
$baselineFile = isset($opts['baseline']) ? (string) $opts['baseline'] : __DIR__ . '/audit_log_arity_baseline.txt';

$sigs = alc_signatures($appRoot . '/inc/audit.php');
if (!isset($sigs['audit_log'])) {
    fwrite(STDERR, "cannot read the audit_log() signature from inc/audit.php\n");
    exit(2);
}

// Baseline: "relative/path.php:LINE  reason" or "relative/path.php  reason".
$baseline = [];
if (!isset($opts['no-baseline']) && is_file($baselineFile)) {
    foreach (file($baselineFile, FILE_IGNORE_NEW_LINES) as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#') { continue; }
        $key = preg_split('/\s+/', $l, 2)[0];
        $baseline[$key] = ['used' => false];
    }
}

$skipDir = '#/(vendor|node_modules|\.git|cache|uploads|backups)(/|$)#';
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS));
foreach ($it as $fi) {
    if ($fi->getExtension() !== 'php') { continue; }
    $p = str_replace('\\', '/', $fi->getPathname());
    if (preg_match($skipDir, substr($p, strlen($scanRoot)))) { continue; }
    if (strpos($p, '/assets/vendor/') !== false) { continue; }
    $files[] = $p;
}
sort($files);

$bad = 0; $checked = 0; $filesWithCalls = 0; $entryFiles = 0;
$report = [];
foreach ($files as $path) {
    $src = (string) @file_get_contents($path);
    if (stripos($src, 'audit_') === false) { continue; }   // cheap pre-filter; the tokenizer decides
    [$findings, $n] = alc_scan_source($src, $sigs);
    if ($n === 0) { continue; }
    $checked += $n;
    $filesWithCalls++;
    $rel = ltrim(substr($path, strlen($scanRoot)), '/');

    // CHECK 2 -- an entry point that calls audit_log() must load inc/audit.php.
    $isEntry = $rel !== 'inc/audit.php';
    $callsAuditLog = false;
    foreach (alc_calls_of($src, 'audit_log') as $_) { $callsAuditLog = true; break; }
    if ($isEntry && $callsAuditLog) {
        $entryFiles++;
        $seen = [];
        $loads = alc_loads_audit($path, $seen, ['NEWUI_ROOT' => $scanRoot]);
        if ($loads === false) {
            $firstLine = alc_calls_of($src, 'audit_log')[0] ?? 1;
            $findings[] = ['line' => $firstLine, 'fn' => 'audit_log',
                'msg' => 'this file calls audit_log() but neither it nor anything it includes loads inc/audit.php -- a call behind function_exists() is silently skipped, an unguarded one is a fatal error'];
        }
    }

    foreach ($findings as $f) {
        $k1 = $rel . ':' . $f['line'];
        if (isset($baseline[$k1])) { $baseline[$k1]['used'] = true; continue; }
        if (isset($baseline[$rel])) { $baseline[$rel]['used'] = true; continue; }
        $report[] = sprintf("  %s:%d  %s() -- %s", $rel, $f['line'], $f['fn'], $f['msg']);
        $bad++;
    }
}

printf("\n  audit_log arity: %d call(s) in %d file(s) checked, %d entry point(s) checked for loading inc/audit.php, %d finding(s)\n",
    $checked, $filesWithCalls, $entryFiles, $bad);

$stale = [];
foreach ($baseline as $k => $info) { if (!$info['used']) { $stale[] = $k; } }
if ($stale) {
    echo "\n  STALE baseline entries (no longer matched -- remove them):\n";
    foreach ($stale as $k) { echo "    {$k}\n"; }
}

if ($bad) {
    echo "\n";
    echo implode("\n", $report) . "\n\n";
    echo "  audit_log(\$category, \$activity, \$targetType = null, \$targetId = null, \$summary = '',\n";
    echo "            \$details = null, \$severity = AUDIT_INFO, \$actor = null)\n";
    echo "  A wrong argument is a TypeError -> not caught by catch (Exception) -> an empty response\n";
    echo "  body (\"Unexpected end of JSON input\") with the earlier writes already committed.\n";
}
exit(($bad || $stale) ? 1 : 0);
