<?php
/**
 * Shared static-analysis helpers for the tokenizing audit tools
 * (tools/audit_log_arity.php, tools/csrf_coverage_audit.php).
 *
 * WHY TOKENS, NOT REGEXES
 *
 *   A regex over PHP source cannot tell a call from a string that mentions one,
 *   from a comment that explains one, from a heredoc that contains one. It cannot
 *   balance the parentheses of an argument list that itself holds an interpolated
 *   string with parentheses in it. token_get_all() can, because the lexer has
 *   already decided what is code. Every earlier gate in this family that read
 *   the source as text eventually produced a false negative that hid a real
 *   defect or a false positive that trained people to baseline it.
 *
 * A "significant token" is [id|null, text, line]: whitespace and comments are
 * dropped, single-character tokens (brackets, operators) carry id null.
 *
 * File name does not start with test_ and the directory is tools/lib, so neither
 * tools/test_all.php nor the web-exposure CLI-guard test treats this as a script.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

if (!function_exists('ps_sig')) {

/** Significant tokens of a PHP source string: list of [id|null, text, line]. */
function ps_sig(string $src): array
{
    $raw = @token_get_all($src);
    $sig = [];
    $line = 1;
    foreach ($raw as $t) {
        if (is_array($t)) {
            $ln = $t[2];
            $line = $ln + substr_count($t[1], "\n");
            if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
            $sig[] = [$t[0], $t[1], $ln];
        } else {
            $sig[] = [null, $t, $line];
        }
    }
    return $sig;
}

/** Does this token open a bracket-like group that a later "}" / ")" / "]" closes? */
function ps_is_open(array $tok): bool
{
    return $tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES
        || ($tok[0] === null && ($tok[1] === '(' || $tok[1] === '[' || $tok[1] === '{'));
}

/** Does this token close a bracket-like group? */
function ps_is_close(array $tok): bool
{
    return $tok[0] === null && ($tok[1] === ')' || $tok[1] === ']' || $tok[1] === '}');
}

/** Index of the bracket that closes the one opened at $from, or -1. */
function ps_matching(array $sig, int $from): int
{
    $depth = 0;
    for ($i = $from, $n = count($sig); $i < $n; $i++) {
        if (ps_is_open($sig[$i])) { $depth++; }
        elseif (ps_is_close($sig[$i])) {
            $depth--;
            if ($depth === 0) { return $i; }
        }
    }
    return -1;
}

/**
 * Resolve a require/include target spelled with __DIR__, dirname(__DIR__[, n]),
 * named constants and string literals joined by ".". $start..$end are the
 * expression's token indices. Returns an absolute, normalised path, or null when
 * it cannot be known statically.
 *
 * @param array<string,string> $consts  e.g. ['NEWUI_ROOT' => '/path/to/app']
 */
function ps_resolve_include(array $sig, int $start, int $end, string $fileDir, array $consts = []): ?string
{
    $path = '';
    $i = $start;
    // A wrapping "( ... )" around the whole expression.
    if (($sig[$i][1] ?? '') === '(' && ps_matching($sig, $i) === $end) {
        $i++;
        $end--;
    }
    $expectOperand = true;
    while ($i <= $end) {
        [$id, $text] = $sig[$i];
        if ($expectOperand) {
            if ($id === T_DIR) {
                $path .= $fileDir;
                $i++;
            } elseif ($id === T_STRING && strtolower($text) === 'dirname' && ($sig[$i + 1][1] ?? '') === '(') {
                $close = ps_matching($sig, $i + 1);
                if ($close < 0 || ($sig[$i + 2][0] ?? null) !== T_DIR) { return null; }
                $levels = 1;
                if (($sig[$i + 3][1] ?? '') === ',' && ($sig[$i + 4][0] ?? null) === T_LNUMBER) {
                    $levels = (int) $sig[$i + 4][1];
                }
                $d = $fileDir;
                for ($l = 0; $l < $levels; $l++) { $d = dirname($d); }
                $path .= $d;
                $i = $close + 1;
            } elseif ($id === T_STRING && isset($consts[$text])) {
                $path .= $consts[$text];
                $i++;
            } elseif ($id === T_CONSTANT_ENCAPSED_STRING) {
                $path .= substr($text, 1, -1);
                $i++;
            } else {
                return null;
            }
            $expectOperand = false;
        } else {
            if ($text !== '.') { return null; }
            $i++;
            $expectOperand = true;
        }
    }
    if ($expectOperand || $path === '') { return null; }

    // Normalise "x/../y" without touching the filesystem.
    $norm = str_replace('\\', '/', $path);
    $parts = [];
    foreach (explode('/', $norm) as $seg) {
        if ($seg === '..') { array_pop($parts); }
        elseif ($seg !== '.' && $seg !== '') { $parts[] = $seg; }
    }
    // Keep a Windows drive letter ("C:") or a leading slash.
    $lead = ($norm !== '' && $norm[0] === '/') ? '/' : '';
    return $lead . implode('/', $parts);
}

/**
 * Follow a file's require/include statements (those that can be resolved
 * statically) and ask whether any file in the closure matches $targetRegex.
 *
 * Returns true when one does, false when the whole closure was resolved and none
 * does, and null when an include could not be resolved (unknown: the caller must
 * not guess).
 *
 * $followRegex, when given, limits which included files are entered (a file that
 * does not match is not searched, and is not "unknown" either). The CSRF gate uses
 * it to stay inside api/: library code under inc/ legitimately requires
 * api/auth.php in one conditional path (api/owntracks-config.php, library mode), which
 * says nothing about how an endpoint that merely includes the library authenticates.
 *
 * @param array<string,string> $consts
 * @param array<string,bool>   $seen   loop guard
 */
function ps_closure_matches(string $file, string $targetRegex, array $consts = [], array &$seen = [], ?string $followRegex = null): ?bool
{
    $real = str_replace('\\', '/', $file);
    if (isset($seen[$real])) { return false; }
    $seen[$real] = true;
    if (!is_file($file)) { return null; }

    $sig = ps_sig((string) @file_get_contents($file));
    $dir = str_replace('\\', '/', dirname($file));
    $unknown = false;
    for ($i = 0, $n = count($sig); $i < $n; $i++) {
        if (!in_array($sig[$i][0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) { continue; }
        // The expression runs to the statement's ";" at depth 0.
        $depth = 0;
        $end = $i + 1;
        for ($k = $i + 1; $k < $n; $k++) {
            if (ps_is_open($sig[$k])) { $depth++; }
            if (ps_is_close($sig[$k])) { $depth--; }
            if ($sig[$k][0] === null && $sig[$k][1] === ';' && $depth <= 0) { $end = $k - 1; break; }
        }
        $target = ps_resolve_include($sig, $i + 1, $end, $dir, $consts);
        if ($target === null) { $unknown = true; continue; }
        if (preg_match($targetRegex, $target)) { return true; }
        if ($followRegex !== null && !preg_match($followRegex, $target)) { continue; }   // a file we were told not to enter
        if (is_file($target)) {
            $r = ps_closure_matches($target, $targetRegex, $consts, $seen, $followRegex);
            if ($r === true) { return true; }
            if ($r === null) { $unknown = true; }
        } else {
            $unknown = true;   // a conditional include of a file that is not here: cannot say
        }
    }
    return $unknown ? null : false;
}

/** Every file in the include closure of $file that exists on disk (the file itself first). */
function ps_closure_files(string $file, array $consts = [], array &$seen = [], ?string $followRegex = null): array
{
    $real = str_replace('\\', '/', $file);
    if (isset($seen[$real]) || !is_file($file)) { return []; }
    $seen[$real] = true;
    $out = [$real];
    $sig = ps_sig((string) @file_get_contents($file));
    $dir = str_replace('\\', '/', dirname($file));
    for ($i = 0, $n = count($sig); $i < $n; $i++) {
        if (!in_array($sig[$i][0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) { continue; }
        $depth = 0;
        $end = $i + 1;
        for ($k = $i + 1; $k < $n; $k++) {
            if (ps_is_open($sig[$k])) { $depth++; }
            if (ps_is_close($sig[$k])) { $depth--; }
            if ($sig[$k][0] === null && $sig[$k][1] === ';' && $depth <= 0) { $end = $k - 1; break; }
        }
        $target = ps_resolve_include($sig, $i + 1, $end, $dir, $consts);
        if ($target !== null && is_file($target) && ($followRegex === null || preg_match($followRegex, $target))) {
            foreach (ps_closure_files($target, $consts, $seen, $followRegex) as $f) { $out[] = $f; }
        }
    }
    return $out;
}

} // function_exists guard
