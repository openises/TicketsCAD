<?php
/**
 * Gate: every state-changing endpoint under api/ that is authenticated by the
 * session cookie must pass a CSRF check BEFORE it changes anything.
 *
 *   php tools/csrf_coverage_audit.php                  # the whole tree (CI / pre-commit)
 *   php tools/csrf_coverage_audit.php --path=<dir>     # one directory (the test's fixtures)
 *   php tools/csrf_coverage_audit.php --list           # also list the exempt files and why
 *
 * WHY THIS EXISTS (Phase 155)
 *
 *   api/organizations.php answered delete_org, save_org, assign_member and every
 *   other state-changing action with no CSRF check at all. It was not alone:
 *   api/rbac.php (the Roles & Permissions editor), api/training.php and others
 *   carried the same gap, and nothing in the suite could have noticed, because
 *   the existing CSRF tests each name the files they already know about
 *   (tests/test_csrf_enforced.php lists five). A test that lists the endpoints
 *   it checks cannot find the endpoint that was forgotten.
 *
 *   This gate derives the list from the code instead.
 *
 * THE RULE
 *
 *   CSRF matters when the browser attaches credentials on its own. An endpoint
 *   that authenticates through the session cookie (it loads api/auth.php or calls
 *   session_start()) is exposed: any page the victim visits can make their
 *   browser POST to it. An endpoint that authenticates with a bearer token the
 *   caller must supply (api/external/*, api/sip-ingest.php, ...) is not: a
 *   forged request carries no token. So:
 *
 *     * SESSION endpoints: every state-changing statement must be DOMINATED by a
 *       csrf_verify() guard (below). No allowlist; a baseline entry is allowed
 *       only for a verified false positive, with its reasoning.
 *     * BEARER endpoints (no session, an Authorization header or bearer token is
 *       verified in the file or what it includes): exempt automatically, listed
 *       by --list so the exemption is visible.
 *     * A file that writes and has NEITHER is reported: an unauthenticated writer
 *       must be a deliberate, recorded decision.
 *
 * "STATE-CHANGING" (tokenized, never grepped)
 *
 *     * a string literal that begins an INSERT / REPLACE / UPDATE / DELETE /
 *       TRUNCATE statement (the first literal of a concatenated query counts)
 *     * a call to a writer function: any function in inc/ that writes (directly,
 *       or through another writer), less an explicit list of incidental sinks
 *       (audit rows, SSE, push, session bookkeeping) whose effect a forged request
 *       gains nothing from
 *     * file_put_contents / unlink / rename / move_uploaded_file / rmdir / mkdir
 *     * an assignment to $_SESSION[...] other than the CSRF token itself
 *     * broker_send() / mail()
 *
 * "DOMINATED"
 *
 *   A file-level "does it mention csrf_verify" check is not enough, and the
 *   project learned it twice: api/talkgroups.php checked the POST branch and not
 *   the DELETE branch, and a token-less DELETE removed a row. The check must be
 *   proven to run BEFORE each write on every path that reaches it. A guard G
 *   covers a later statement T in the same function when
 *
 *     1. G is a rejecting guard -- `if (!csrf_verify($t)) { json_error(...); }`,
 *        whose body always terminates (exit / return / throw / json_error), and
 *     2. every NON-method condition that encloses G also encloses T (so a guard
 *        inside `if ($action === 'save') { ... }` does not cover the
 *        `delete_org` branch), and
 *     3. every HTTP method T can run under is a method G ran under (so a guard
 *        inside `if ($method === 'POST' || $method === 'DELETE') { ... }` covers a
 *        later `if ($method === 'POST') { ... }` but not a write that also runs
 *        on GET).
 *
 *   A positive guard `if (csrf_verify($t)) { ...writes... }` covers the writes
 *   inside its block. Method conditions are understood in the forms this codebase
 *   uses: $method / $_SERVER['REQUEST_METHOD'] compared with === / !== / == / !=,
 *   in_array(), combined with ! || &&, and an early `if ($method !== 'POST') exit`
 *   narrowing everything after it. A write inside a helper function is covered
 *   only if EVERY call to that helper in the file is itself covered.
 *
 *   A csrf_verify() whose result is ignored, a rejecting branch that does not
 *   terminate, or a check joined to another condition with && (so omitting the
 *   token skips it) covers nothing, and is reported as such.
 *
 * Findings are listed as file:line. Verified false positives go in
 * tools/csrf_coverage_baseline.txt ("path:LINE  reason", reason mandatory); an
 * entry that no longer matches anything fails the run, so the list cannot rot.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/lib/php_static.php';

// ═══════════════════════════════════════════════════════════════════════════
// Library part -- pure functions the test drives directly.
// ═══════════════════════════════════════════════════════════════════════════

const CSRF_M_GET = 1;
const CSRF_M_POST = 2;
const CSRF_M_PUT = 4;
const CSRF_M_PATCH = 8;
const CSRF_M_DELETE = 16;
const CSRF_M_OTHER = 32;
const CSRF_M_ALL = 63;

/**
 * Functions whose only effect is bookkeeping a forged request gains nothing from
 * (an audit row, a pushed event, a rate-limit counter, the session's own
 * timestamps, a login-attempt log). They are neither "writers" nor propagate
 * writer-ness to their callers. Readers are listed by prefix for the same reason:
 * a function that is called get_*() or is_*() is not what a forged request is
 * after, even when it lazily heals a table on the way.
 */
function csrf_incidental_function_pattern(): string
{
    return '/^(_?audit(_|$)|_?sse_|_?notify_|_?push_|_?webhook_|_?sm_|sess_|_?rate_limit|_?ls_(record|cleanup|alert)'
         . '|sched_job_|_?geofence_|_?notification_|_?router_(log|forward|evaluate)|_?broker_(log|update|receive)'
         . '|ext_api_record_use|force_pw_|pw_record_|_?cr_record_|_?fcc_?record|comm_fcc_record'
         . '|[a-z0-9_]*ensure[a-z0-9_]*|db_|csrf_|json_|log_|error_|channel_state_|totp_verify'
         . '|tfa_verify_login|prefs_get|get_|is_|has_|can_)/i';
}

/** Is this token a string literal that begins a SQL write statement? */
function csrf_is_sql_write_token(?int $id, string $text): bool
{
    if ($id !== T_CONSTANT_ENCAPSED_STRING && $id !== T_ENCAPSED_AND_WHITESPACE) { return false; }
    $s = ($id === T_CONSTANT_ENCAPSED_STRING) ? substr($text, 1, -1) : $text;
    // Upper-case only: prose such as "update failed" must never read as a statement.
    return (bool) preg_match('/^\s*(INSERT\s+(?:IGNORE\s+)?INTO\b|REPLACE\s+INTO\b|UPDATE\s|DELETE\s+FROM\b|TRUNCATE\s)/', $s);
}

/**
 * The functions in inc/ that change stored state: a function that contains a SQL
 * write literal, or calls one that does, less the incidental sinks above.
 *
 * @return array<string,true>  lower-case names
 */
function csrf_writer_functions(string $incDir): array
{
    $incidental = csrf_incidental_function_pattern();
    $funcs = [];   // name => ['writes' => bool, 'calls' => [names]]
    foreach (glob(rtrim($incDir, '/\\') . '/*.php') ?: [] as $f) {
        $sig = ps_sig((string) @file_get_contents($f));
        $n = count($sig);
        $brace = 0;
        $fnStack = [];
        $pending = null;
        for ($i = 0; $i < $n; $i++) {
            [$id, $t] = $sig[$i];
            if ($id === T_FUNCTION) {
                $j = $i + 1;
                if (($sig[$j][1] ?? '') === '&') { $j++; }
                $pending = (($sig[$j][0] ?? null) === T_STRING) ? strtolower($sig[$j][1]) : null;
            }
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) { $brace++; continue; }
            if ($id === null && $t === '{') {
                $brace++;
                if ($pending !== null) {
                    $fnStack[] = [$pending, $brace];
                    $funcs[$pending] = $funcs[$pending] ?? ['writes' => false, 'calls' => []];
                    $pending = null;
                }
                continue;
            }
            if ($id === null && $t === '}') {
                if ($fnStack && end($fnStack)[1] === $brace) { array_pop($fnStack); }
                $brace--;
                continue;
            }
            if ($id === null && $t === ';') { $pending = null; }
            if (!$fnStack) { continue; }
            $cur = end($fnStack)[0];
            if (csrf_is_sql_write_token($id, $t)) { $funcs[$cur]['writes'] = true; }
            if ($id === T_STRING && ($sig[$i + 1][1] ?? '') === '('
                && !in_array($sig[$i - 1][0] ?? 0, [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) {
                $funcs[$cur]['calls'][strtolower($t)] = true;
            }
        }
    }
    $writers = [];
    foreach ($funcs as $name => $f) {
        if ($f['writes'] && !preg_match($incidental, $name)) { $writers[$name] = true; }
    }
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($funcs as $name => $f) {
            if (isset($writers[$name]) || preg_match($incidental, $name)) { continue; }
            foreach ($f['calls'] as $c => $_) {
                if (isset($writers[$c])) { $writers[$name] = true; $changed = true; break; }
            }
        }
    }
    return $writers;
}

/**
 * Parse one PHP source into the structure the dominance rules need.
 *
 * Returns: sig, blockOf[i] (innermost block id at token i), fnOf[i], blockEnd[id],
 * blockParent[id], blockBrace[id] (token index of the "{"), funcNames[fnId],
 * calls[name] => [token indices].
 */
function csrf_parse(string $src): array
{
    $sig = ps_sig($src);
    $n = count($sig);
    $blockOf = array_fill(0, max($n, 1), 0);
    $fnOf = array_fill(0, max($n, 1), 0);
    $blockEnd = [0 => $n - 1];
    $blockParent = [0 => -1];
    $blockBrace = [0 => -1];
    $fnBlock = [];          // fnId => the block id of its body
    $funcNames = [0 => '(main)'];
    $stack = [];            // entries: ['kind' => 'block'|'interp', ...]
    $curBlock = 0;
    $curFn = 0;
    $nextBlock = 1;
    $nextFn = 1;
    $pending = null;

    for ($i = 0; $i < $n; $i++) {
        [$id, $t] = $sig[$i];
        if ($id === T_FUNCTION) {
            $j = $i + 1;
            if (($sig[$j][1] ?? '') === '&') { $j++; }
            // A closure ("function (") is inline: it shares its parent's scope for dominance.
            $pending = (($sig[$j][0] ?? null) === T_STRING) ? strtolower($sig[$j][1]) : null;
        }
        $blockOf[$i] = $curBlock;
        $fnOf[$i] = $curFn;

        if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $stack[] = ['kind' => 'interp'];
            continue;
        }
        if ($id === null && $t === '{') {
            $bid = $nextBlock++;
            $blockParent[$bid] = $curBlock;
            $blockBrace[$bid] = $i;
            $entry = ['kind' => 'block', 'block' => $curBlock, 'fn' => $curFn, 'id' => $bid];
            if ($pending !== null) {
                $fid = $nextFn++;
                $funcNames[$fid] = $pending;
                $fnBlock[$fid] = $bid;
                $curFn = $fid;
                $pending = null;
            }
            $stack[] = $entry;
            $curBlock = $bid;
            continue;
        }
        if ($id === null && $t === '}') {
            $e = array_pop($stack);
            if ($e && $e['kind'] === 'block') {
                $blockEnd[$e['id']] = $i;
                $curBlock = $e['block'];
                $curFn = $e['fn'];
            }
            continue;
        }
        if ($id === null && $t === ';') { $pending = null; }
    }

    $calls = [];
    for ($i = 0; $i < $n - 1; $i++) {
        if ($sig[$i][0] !== T_STRING || ($sig[$i + 1][1] ?? '') !== '(') { continue; }
        $prev = $i > 0 ? $sig[$i - 1][0] : null;
        if (in_array($prev, [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) { continue; }
        if (defined('T_NULLSAFE_OBJECT_OPERATOR') && $prev === T_NULLSAFE_OBJECT_OPERATOR) { continue; }
        $calls[strtolower($sig[$i][1])][] = $i;
    }
    return ['sig' => $sig, 'blockOf' => $blockOf, 'fnOf' => $fnOf, 'blockEnd' => $blockEnd,
            'blockParent' => $blockParent, 'blockBrace' => $blockBrace, 'fnBlock' => $fnBlock,
            'funcNames' => $funcNames, 'calls' => $calls];
}

/**
 * Statements that end a request or the current function: whatever follows a
 * rejecting guard that contains one of these is reached only when the guard
 * passed. json_error()/json_response() exit; so do the External API's helpers.
 */
function csrf_terminator_calls(): array
{
    return ['json_error', 'json_response', 'ext_api_error', 'json_error_safe', 'api_fail', 'api_error',
            'deny', 'http_exit', 'rate_limit_reject'];
}

/** Does the token range [from, to] contain a statement that never falls through? */
function csrf_range_terminates(array $sig, int $from, int $to): bool
{
    $term = csrf_terminator_calls();
    for ($i = $from; $i <= $to && $i < count($sig); $i++) {
        $id = $sig[$i][0];
        if ($id === T_EXIT || $id === T_RETURN || $id === T_THROW) { return true; }
        if ($id === T_STRING && ($sig[$i + 1][1] ?? '') === '(' && in_array(strtolower($sig[$i][1]), $term, true)) { return true; }
    }
    return false;
}

// ── HTTP-method reasoning ─────────────────────────────────────────────────

function csrf_method_bit(string $name): int
{
    switch (strtoupper($name)) {
        case 'GET':    return CSRF_M_GET;
        case 'POST':   return CSRF_M_POST;
        case 'PUT':    return CSRF_M_PUT;
        case 'PATCH':  return CSRF_M_PATCH;
        case 'DELETE': return CSRF_M_DELETE;
        default:       return CSRF_M_OTHER;
    }
}

/** Variable names that hold the request method: $method plus anything assigned from REQUEST_METHOD. */
function csrf_method_vars(array $sig): array
{
    $vars = ['$method' => true, '$requestMethod' => true, '$reqMethod' => true];
    for ($i = 0, $n = count($sig); $i < $n - 2; $i++) {
        if ($sig[$i][0] !== T_VARIABLE || ($sig[$i + 1][1] ?? '') !== '=' || $sig[$i + 1][0] !== null) { continue; }
        for ($k = $i + 2; $k < min($n, $i + 14); $k++) {
            if ($sig[$k][0] === null && $sig[$k][1] === ';') { break; }
            if ($sig[$k][0] === T_CONSTANT_ENCAPSED_STRING && trim($sig[$k][1], "'\"") === 'REQUEST_METHOD') {
                $vars[$sig[$i][1]] = true;
                break;
            }
        }
    }
    return $vars;
}

/**
 * Classify one atomic condition. Returns [trueMask, falseMask, isMethodAtom]: the
 * methods the condition can be true / false under.
 */
function csrf_atom_sets(array $toks, array $methodVars): array
{
    $text = '';
    foreach ($toks as $t) { $text .= $t[1]; }
    $text = str_replace('"', "'", $text);
    $var = '(\$[A-Za-z_][A-Za-z0-9_]*|\$_SERVER\[\'REQUEST_METHOD\'\])';
    $coalesce = '(?:\?\?\'[A-Z]+\')?';
    $isMethodVar = static function (string $v) use ($methodVars): bool {
        return $v === "\$_SERVER['REQUEST_METHOD']" || isset($methodVars[$v]);
    };

    if (preg_match('/^\(?' . $var . $coalesce . '\)?(===|==|!==|!=)\'([A-Za-z]+)\'$/', $text, $m) && $isMethodVar($m[1])) {
        $bit = csrf_method_bit($m[3]);
        return ($m[2] === '===' || $m[2] === '==')
            ? [$bit, CSRF_M_ALL & ~$bit, true]
            : [CSRF_M_ALL & ~$bit, $bit, true];
    }
    if (preg_match('/^\'([A-Za-z]+)\'(===|==|!==|!=)\(?' . $var . $coalesce . '\)?$/', $text, $m) && $isMethodVar($m[3])) {
        $bit = csrf_method_bit($m[1]);
        return ($m[2] === '===' || $m[2] === '==')
            ? [$bit, CSRF_M_ALL & ~$bit, true]
            : [CSRF_M_ALL & ~$bit, $bit, true];
    }
    if (preg_match('/^in_array\(\(?' . $var . $coalesce . '\)?,\[((?:\'[A-Za-z]+\',?)+)\](?:,true)?\)$/', $text, $m) && $isMethodVar($m[1])) {
        $mask = 0;
        preg_match_all('/\'([A-Za-z]+)\'/', $m[2], $names);
        foreach ($names[1] as $nm) { $mask |= csrf_method_bit($nm); }
        return [$mask, CSRF_M_ALL & ~$mask, true];
    }
    return [CSRF_M_ALL, CSRF_M_ALL, false];
}

/** Boolean-expression parser over [from..to]: returns [trueMask, falseMask, pureMethod]. */
function csrf_cond_or(array $sig, int &$i, int $to, array $mv): array
{
    [$T, $F, $pure] = csrf_cond_and($sig, $i, $to, $mv);
    while ($i <= $to && ($sig[$i][0] === T_BOOLEAN_OR || $sig[$i][0] === T_LOGICAL_OR)) {
        $i++;
        [$T2, $F2, $p2] = csrf_cond_and($sig, $i, $to, $mv);
        $T |= $T2;
        $F &= $F2;
        $pure = $pure && $p2;
    }
    return [$T, $F, $pure];
}

function csrf_cond_and(array $sig, int &$i, int $to, array $mv): array
{
    [$T, $F, $pure] = csrf_cond_not($sig, $i, $to, $mv);
    while ($i <= $to && ($sig[$i][0] === T_BOOLEAN_AND || $sig[$i][0] === T_LOGICAL_AND)) {
        $i++;
        [$T2, $F2, $p2] = csrf_cond_not($sig, $i, $to, $mv);
        $T &= $T2;
        $F |= $F2;
        $pure = $pure && $p2;
    }
    return [$T, $F, $pure];
}

function csrf_cond_not(array $sig, int &$i, int $to, array $mv): array
{
    if ($i <= $to && $sig[$i][0] === null && $sig[$i][1] === '!') {
        $i++;
        [$T, $F, $p] = csrf_cond_not($sig, $i, $to, $mv);
        return [$F, $T, $p];
    }
    if ($i <= $to && $sig[$i][0] === null && $sig[$i][1] === '(') {
        $close = ps_matching($sig, $i);
        $after = $close + 1;
        $isConnector = $after > $to
            || in_array($sig[$after][0], [T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR], true);
        if ($close > 0 && $close <= $to && $isConnector) {
            $inner = $i + 1;
            $r = csrf_cond_or($sig, $inner, $close - 1, $mv);
            $i = $close + 1;
            return $r;
        }
    }
    // An atom: everything up to the next top-level connector.
    $start = $i;
    $depth = 0;
    while ($i <= $to) {
        if (ps_is_open($sig[$i])) { $depth++; }
        elseif (ps_is_close($sig[$i])) { $depth--; }
        elseif ($depth === 0 && in_array($sig[$i][0], [T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR], true)) { break; }
        $i++;
    }
    return csrf_atom_sets(array_slice($sig, $start, $i - $start), $mv);
}

/**
 * Assign every token the set of HTTP methods it can execute under, by walking the
 * statements and narrowing at method conditions. Also records which block braces
 * belong to a PURELY method-conditioned branch (those do not count as "another
 * condition" when asking whether a guard encloses a write).
 *
 * @return array{0: int[], 1: array<int,true>}  [mask per token, methodOnly[braceTokenIndex]]
 */
function csrf_method_sets(array $sig, array $methodVars): array
{
    $n = count($sig);
    $mask = array_fill(0, max($n, 1), CSRF_M_ALL);
    $methodOnly = [];
    csrf_ms_walk($sig, 0, $n - 1, CSRF_M_ALL, $mask, $methodOnly, $methodVars);
    return [$mask, $methodOnly];
}

function csrf_ms_walk(array $sig, int $from, int $to, int $M, array &$mask, array &$methodOnly, array $mv): void
{
    $i = $from;
    while ($i <= $to) {
        $id = $sig[$i][0];
        if ($id === T_IF) {
            $i = csrf_ms_if($sig, $i, $to, $M, $mask, $methodOnly, $mv);
            continue;
        }
        $mask[$i] = $M;
        if ($id === null && $sig[$i][1] === '{') {
            $end = ps_matching($sig, $i);
            if ($end < 0 || $end > $to) { $end = $to; }
            // A named function body starts with no assumption about the request method.
            $inner = csrf_ms_is_function_body($sig, $i) ? CSRF_M_ALL : $M;
            csrf_ms_walk($sig, $i + 1, $end - 1, $inner, $mask, $methodOnly, $mv);
            $mask[$end] = $M;
            $i = $end + 1;
            continue;
        }
        $i++;
    }
}

/**
 * Is the "{" at $i the body of a NAMED function declaration? A closure
 * (`function (...) use (...) {`) runs inline, inside whatever request method the
 * code around it runs under, so it inherits that and is not reset here.
 */
function csrf_ms_is_function_body(array $sig, int $i): bool
{
    for ($k = $i - 1; $k >= 0 && $i - $k < 200; $k--) {
        if ($sig[$k][0] === T_FUNCTION) {
            $j = $k + 1;
            if (($sig[$j][1] ?? '') === '&') { $j++; }
            return ($sig[$j][0] ?? null) === T_STRING;
        }
        if ($sig[$k][0] === null && in_array($sig[$k][1], [';', '{', '}'], true)) { return false; }
    }
    return false;
}

/** Walk one if / elseif / else chain starting at the T_IF at $i; returns the index after it. */
function csrf_ms_if(array $sig, int $i, int $to, int &$M, array &$mask, array &$methodOnly, array $mv): int
{
    $flowIn = $M;            // methods that reach the chain
    $cur = $M;               // methods for which every earlier condition was false
    $afterUnion = 0;         // methods that leave the chain through a non-terminating branch
    $first = true;
    while (true) {
        $open = $i + 1;
        if (($sig[$open][1] ?? '') !== '(') { $mask[$i] = $cur; return $i + 1; }
        $close = ps_matching($sig, $open);
        for ($k = $i; $k <= $close; $k++) { $mask[$k] = $cur; }
        $c = $open + 1;
        [$T, $F, $pure] = csrf_cond_or($sig, $c, $close - 1, $mv);
        $bodyStart = $close + 1;
        $isBlock = ($sig[$bodyStart][1] ?? '') === '{' && $sig[$bodyStart][0] === null;
        if ($isBlock) {
            $bodyEnd = ps_matching($sig, $bodyStart);
            if ($bodyEnd < 0) { $bodyEnd = $to; }
            $from = $bodyStart + 1;
            $upto = $bodyEnd - 1;
            $next = $bodyEnd + 1;
            $mask[$bodyStart] = $cur;
            $mask[$bodyEnd] = $cur;
        } else {
            $bodyEnd = $bodyStart;
            $dd = 0;
            for ($k = $bodyStart; $k <= $to; $k++) {
                if (ps_is_open($sig[$k])) { $dd++; }
                if (ps_is_close($sig[$k])) { $dd--; }
                if ($sig[$k][0] === null && $sig[$k][1] === ';' && $dd <= 0) { $bodyEnd = $k; break; }
            }
            $from = $bodyStart;
            $upto = $bodyEnd;
            $next = $bodyEnd + 1;
        }
        $bodyM = $cur & $T;
        if ($isBlock && $pure && ($T !== CSRF_M_ALL || $F !== CSRF_M_ALL)) { $methodOnly[$bodyStart] = true; }
        csrf_ms_walk($sig, $from, $upto, $bodyM, $mask, $methodOnly, $mv);
        if (!csrf_range_terminates($sig, $from, $upto)) { $afterUnion |= $bodyM; }
        $cur &= $F;
        $first = false;

        $nx = $sig[$next][0] ?? null;
        if ($nx === T_ELSEIF) { $i = $next; continue; }
        if ($nx === T_ELSE && ($sig[$next + 1][0] ?? null) === T_IF) { $mask[$next] = $cur; $i = $next + 1; continue; }
        if ($nx === T_ELSE) {
            $mask[$next] = $cur;
            $eStart = $next + 1;
            if (($sig[$eStart][1] ?? '') === '{' && $sig[$eStart][0] === null) {
                $eEnd = ps_matching($sig, $eStart);
                if ($eEnd < 0) { $eEnd = $to; }
                $mask[$eStart] = $cur;
                $mask[$eEnd] = $cur;
                if ($pure) { $methodOnly[$eStart] = true; }
                csrf_ms_walk($sig, $eStart + 1, $eEnd - 1, $cur, $mask, $methodOnly, $mv);
                if (!csrf_range_terminates($sig, $eStart + 1, $eEnd - 1)) { $afterUnion |= $cur; }
                $M = $afterUnion;
                return $eEnd + 1;
            }
            // else <single statement>
            $dd = 0; $eEnd = $eStart;
            for ($k = $eStart; $k <= $to; $k++) {
                if (ps_is_open($sig[$k])) { $dd++; }
                if (ps_is_close($sig[$k])) { $dd--; }
                if ($sig[$k][0] === null && $sig[$k][1] === ';' && $dd <= 0) { $eEnd = $k; break; }
            }
            csrf_ms_walk($sig, $eStart, $eEnd, $cur, $mask, $methodOnly, $mv);
            if (!csrf_range_terminates($sig, $eStart, $eEnd)) { $afterUnion |= $cur; }
            $M = $afterUnion;
            return $eEnd + 1;
        }
        // No else: the methods for which every condition was false fall through.
        $afterUnion |= $cur;
        $M = $afterUnion;
        return $next;
    }
}

// ── The analysis ──────────────────────────────────────────────────────────

/**
 * Analyse one source string. Returns ['sites' => [...], 'guards' => [...],
 * 'unclear' => [...]] where a site is ['line','kind','label','covered'].
 *
 * @param array<string,true> $writers  lower-case writer function names from inc/
 */
function csrf_analyze(string $src, array $writers, ?string $fileDir = null, array $consts = []): array
{
    $p = csrf_parse($src);
    $sig = $p['sig'];
    $n = count($sig);
    $blockOf = $p['blockOf'];
    $fnOf = $p['fnOf'];
    $blockParent = $p['blockParent'];
    $blockBrace = $p['blockBrace'];
    $fnBlock = $p['fnBlock'];
    $calls = $p['calls'];
    $funcNames = $p['funcNames'];
    $incidental = csrf_incidental_function_pattern();

    [$mask, $methodOnlyBrace] = csrf_method_sets($sig, csrf_method_vars($sig));
    $methodOnlyBlock = [];
    foreach ($blockBrace as $bid => $bi) { if ($bi >= 0 && isset($methodOnlyBrace[$bi])) { $methodOnlyBlock[$bid] = true; } }

    // Wrapper functions declared in this file whose body calls csrf_verify().
    $csrfNames = ['csrf_verify' => true, 'csrf_require' => true];   // csrf_require(): inc/functions.php's reject-or-403 helper
    $fnIdByName = [];
    foreach ($funcNames as $fid => $name) { if ($fid > 0) { $fnIdByName[$name] = $fid; } }
    foreach ($calls['csrf_verify'] ?? [] as $ci) {
        $fid = $fnOf[$ci];
        if ($fid > 0) { $csrfNames[$funcNames[$fid]] = true; }
    }

    // Authentication gates other than the session cookie: a local function that reads the
    // Authorization header. Calling one and rejecting on a null result is a guard just like
    // csrf_verify(); its own body (stamping a token's last-used time) is part of the gate.
    $bearerFns = [];
    for ($i = 0; $i < $n; $i++) {
        if ($sig[$i][0] === T_CONSTANT_ENCAPSED_STRING && $fnOf[$i] > 0
            && (stripos($sig[$i][1], 'HTTP_AUTHORIZATION') !== false)) {
            $bearerFns[$funcNames[$fnOf[$i]]] = true;
        }
    }

    // The first top-level, unconditional require of api/auth.php: whatever runs before it runs
    // before any session exists (OwnTracks / Traccar / OpenGTS ingest in api/location.php).
    $authIdx = null;
    if ($fileDir !== null) {
        for ($i = 0; $i < $n; $i++) {
            if (!in_array($sig[$i][0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) { continue; }
            if ($fnOf[$i] !== 0 || $blockOf[$i] !== 0) { continue; }
            $end = $i + 1;
            $d = 0;
            for ($k = $i + 1; $k < $n; $k++) {
                if (ps_is_open($sig[$k])) { $d++; }
                if (ps_is_close($sig[$k])) { $d--; }
                if ($sig[$k][0] === null && $sig[$k][1] === ';' && $d <= 0) { $end = $k - 1; break; }
            }
            $target = ps_resolve_include($sig, $i + 1, $end, $fileDir, $consts);
            if ($target !== null && preg_match('#(^|/)api/auth\.php$#', $target)) { $authIdx = $i; break; }
        }
    }

    // Guards: each csrf_verify() / wrapper / bearer-gate call, classified by its context.
    $guards = [];     // each: type reject|positive, after (reject) or from/to (positive), fn, M, blocks
    $unclear = [];

    // The statement end ( ; ) at or after token $from.
    $stmtEnd = static function (int $from) use ($sig, $n): int {
        $dd = 0;
        for ($k = $from; $k < $n; $k++) {
            if (ps_is_open($sig[$k])) { $dd++; }
            if (ps_is_close($sig[$k])) { $dd--; }
            if ($sig[$k][0] === null && $sig[$k][1] === ';' && $dd <= 0) { return $k; }
        }
        return $n - 1;
    };
    // The body of the if whose condition closes at $close: [start, end].
    $bodyOf = static function (int $close) use ($sig, $n, $stmtEnd): array {
        $bodyStart = $close + 1;
        if (($sig[$bodyStart][1] ?? '') === '{' && $sig[$bodyStart][0] === null) {
            $end = ps_matching($sig, $bodyStart);
            return [$bodyStart, $end < 0 ? $n - 1 : $end];
        }
        return [$bodyStart, $stmtEnd($bodyStart)];
    };
    $nonMethodBlocks = static function (int $block) use ($blockParent, $methodOnlyBlock): array {
        $blocks = [];
        for ($b = $block; $b > 0; $b = $blockParent[$b]) {
            if (!isset($methodOnlyBlock[$b])) { $blocks[] = $b; }
        }
        return $blocks;
    };

    // Pass 0 reads the csrf_verify() calls themselves; pass 1 the calls to this file's own wrappers
    // and bearer gates. A wrapper whose body rejects unconditionally (a "guard function") is itself
    // a guard when called as a statement: `_require_csrf($input);`.
    $guardFns = ['csrf_require' => true];   // csrf_require() rejects (403 + exit) unconditionally
    $bearerNames = $bearerFns;
    $passes = [['csrf_verify' => true], array_diff_key($csrfNames, ['csrf_verify' => true]) + $bearerNames];
    foreach ($passes as $passNo => $passNames) {
        foreach ($passNames as $name => $_) {
            $isBearerGate = isset($bearerNames[$name]);
            foreach ($calls[$name] ?? [] as $ci) {
                $line = $sig[$ci][2];
                // Find the "(" of the innermost enclosing group; it is a guard only when that
                // group is the condition of an if / elseif.
                $depth = 0;
                $open = -1;
                for ($k = $ci - 1; $k >= 0; $k--) {
                    $tid = $sig[$k][0];
                    $tx = $sig[$k][1];
                    if ($tid === null && ($tx === ')' || $tx === ']')) { $depth++; continue; }
                    if ($tid === null && ($tx === '(' || $tx === '[')) {
                        if ($depth === 0) { $open = $k; break; }
                        $depth--;
                        continue;
                    }
                    // Do not walk out of the statement we are in.
                    if ($tid === null && $depth === 0 && ($tx === ';' || $tx === '{' || $tx === '}')) { break; }
                }
                $isIf = $open > 0 && $sig[$open][1] === '(' && in_array($sig[$open - 1][0], [T_IF, T_ELSEIF], true);

                if (!$isIf) {
                    // A guard function called as a statement: it rejects (and ends the request) itself.
                    if ($passNo === 1 && isset($guardFns[$name]) && !$isBearerGate) {
                        $guards[] = ['line' => $line, 'type' => 'reject', 'after' => $stmtEnd($ci), 'fn' => $fnOf[$ci],
                                     'M' => $mask[$ci], 'blocks' => $nonMethodBlocks($blockOf[$ci])];
                        continue;
                    }
                    // `$bridge = bridge_auth(); if (!$bridge) { json_error(...); }`
                    if ($isBearerGate) {
                        $var = (($sig[$ci - 1][1] ?? '') === '=' && ($sig[$ci - 2][0] ?? null) === T_VARIABLE) ? $sig[$ci - 2][1] : null;
                        if ($var !== null) {
                            $e = $stmtEnd($ci);
                            if (($sig[$e + 1][0] ?? null) === T_IF && ($sig[$e + 2][1] ?? '') === '(') {
                                $c2 = ps_matching($sig, $e + 2);
                                $cond = '';
                                for ($k = $e + 3; $k < $c2; $k++) { $cond .= $sig[$k][1]; }
                                if ($cond === '!' . $var || $cond === $var . '===null' || $cond === 'empty(' . $var . ')') {
                                    [$bs, $be] = $bodyOf($c2);
                                    if (csrf_range_terminates($sig, $bs, $be)) {
                                        $guards[] = ['line' => $line, 'type' => 'reject', 'after' => $be, 'fn' => $fnOf[$ci],
                                                     'M' => $mask[$ci], 'blocks' => $nonMethodBlocks($blockOf[$ci])];
                                    }
                                }
                            }
                        }
                        continue;   // a bearer gate used any other way is simply not a guard; nothing to report
                    }
                    // The csrf_verify() inside a wrapper's own body is the wrapper's definition, not a use.
                    $fn = $fnOf[$ci];
                    if (!($fn > 0 && isset($csrfNames[$funcNames[$fn]]))) {
                        $unclear[] = ['line' => $line, 'why' => 'the result of csrf_verify() is not used as an if-condition, so it cannot be shown to reject anything'];
                    }
                    continue;
                }

                $close = ps_matching($sig, $open);
                $ifTok = $open - 1;

                // The condition's top-level connectors decide what the guard means.
                $hasAnd = false; $hasOr = false;
                $andTerms = [];
                $d = 0;
                $termStart = $open + 1;
                for ($k = $open + 1; $k < $close; $k++) {
                    if (ps_is_open($sig[$k])) { $d++; continue; }
                    if (ps_is_close($sig[$k])) { $d--; continue; }
                    if ($d !== 0) { continue; }
                    if ($sig[$k][0] === T_BOOLEAN_AND || $sig[$k][0] === T_LOGICAL_AND) {
                        $hasAnd = true;
                        $andTerms[] = [$termStart, $k - 1];
                        $termStart = $k + 1;
                    }
                    if ($sig[$k][0] === T_BOOLEAN_OR || $sig[$k][0] === T_LOGICAL_OR) { $hasOr = true; }
                }
                if ($hasAnd) { $andTerms[] = [$termStart, $close - 1]; }
                $negated = ($sig[$ci - 1][0] === null && $sig[$ci - 1][1] === '!');

                // `!csrf_verify($header) && !csrf_verify($body)`: every && term is itself a negated
                // check, so the branch is taken only when NONE passed -- a genuine rejecting guard.
                $andAllChecks = false;
                if ($negated && $hasAnd && !$hasOr) {
                    $andAllChecks = true;
                    foreach ($andTerms as [$ta, $tb]) {
                        $ok = ($sig[$ta][0] === null && $sig[$ta][1] === '!' && ($sig[$ta + 1][0] ?? null) === T_STRING
                               && (isset($csrfNames[strtolower($sig[$ta + 1][1])]))
                               && ($sig[$ta + 2][1] ?? '') === '(' && ps_matching($sig, $ta + 2) === $tb);
                        if (!$ok) { $andAllChecks = false; break; }
                    }
                }

                [$bodyStart, $bodyEnd] = $bodyOf($close);
                $fnId = $fnOf[$ci];

                if ($negated && (!$hasAnd || $andAllChecks)) {
                    if (csrf_range_terminates($sig, $bodyStart, $bodyEnd)) {
                        $guards[] = ['line' => $line, 'type' => 'reject', 'after' => $bodyEnd, 'fn' => $fnId,
                                     'M' => $mask[$ci], 'blocks' => $nonMethodBlocks($blockOf[$ifTok])];
                    } elseif ($isBearerGate) {
                        // not a guard; nothing to report for a bearer gate
                    } else {
                        $unclear[] = ['line' => $line, 'why' => 'the rejecting csrf_verify() branch does not terminate (no exit/return/throw/json_error), so a failed check falls through into the writes'];
                    }
                } elseif ($isBearerGate) {
                    continue;
                } elseif (!$negated && !$hasOr && !$hasAnd) {
                    $guards[] = ['line' => $line, 'type' => 'positive', 'from' => $bodyStart, 'to' => $bodyEnd, 'fn' => $fnId];
                } elseif ($negated && $hasAnd) {
                    $unclear[] = ['line' => $line, 'why' => 'the csrf_verify() rejection is joined to another condition with &&, so a request that omits the other part skips the check entirely'];
                } else {
                    $unclear[] = ['line' => $line, 'why' => 'unrecognised shape of the csrf_verify() condition'];
                }
            }
        }
        if ($passNo === 0) {
            // A function is a guard function when a rejecting guard sits in its own top-level body,
            // under no other condition and for every method.
            foreach ($guards as $g) {
                $fid = $g['fn'];
                if ($g['type'] === 'reject' && $fid > 0 && $g['M'] === CSRF_M_ALL
                    && $g['blocks'] === [$fnBlock[$fid] ?? -1]) {
                    $guardFns[$funcNames[$fid]] = true;
                }
            }
        }
    }

    // Is every block in $blocks an ancestor-or-self of token $idx's block?
    $encloses = static function (array $blocks, int $idx) use ($blockOf, $blockParent): bool {
        if (!$blocks) { return true; }
        $have = [];
        for ($b = $blockOf[$idx]; $b >= 0; $b = $blockParent[$b] ?? -1) { $have[$b] = true; if ($b === 0) { break; } }
        foreach ($blocks as $b) { if (!isset($have[$b])) { return false; } }
        return true;
    };

    $directlyCovered = static function (int $idx) use ($guards, $fnOf, $mask, $encloses): bool {
        foreach ($guards as $g) {
            if ($g['fn'] !== $fnOf[$idx]) { continue; }
            if ($g['type'] === 'positive') {
                if ($idx >= $g['from'] && $idx <= $g['to']) { return true; }
                continue;
            }
            if ($idx > $g['after'] && ($mask[$idx] & ~$g['M']) === 0 && $encloses($g['blocks'], $idx)) { return true; }
        }
        return false;
    };

    // Function safety: a helper is safe iff it is called, and every call is covered.
    $safeMemo = [];
    $isCovered = null;
    $fnSafe = static function (int $fid, array $visiting = []) use (&$fnSafe, &$isCovered, &$safeMemo, $funcNames, $calls, $fnOf): bool {
        if ($fid === 0) { return false; }
        if (isset($safeMemo[$fid])) { return $safeMemo[$fid]; }
        if (isset($visiting[$fid])) { return false; }
        $visiting[$fid] = true;
        $name = $funcNames[$fid] ?? '';
        // Only calls from outside the function itself count (a recursive call proves nothing).
        $sites = array_values(array_filter($calls[$name] ?? [], static fn($ci) => $fnOf[$ci] !== $fid));
        $ok = $sites !== [];
        foreach ($sites as $ci) {
            if (!$isCovered($ci, $visiting)) { $ok = false; break; }
        }
        return $safeMemo[$fid] = $ok;
    };
    $isCovered = static function (int $idx, array $visiting = []) use ($directlyCovered, $fnSafe, $fnOf): bool {
        if ($directlyCovered($idx)) { return true; }
        $fid = $fnOf[$idx];
        return $fid > 0 && $fnSafe($fid, $visiting);
    };

    // Write sites.
    $sites = [];
    $benignSession = ['csrf_token' => true];
    // mkdir() is not listed: creating an empty directory is never what a forged request is after.
    $fileOps = ['file_put_contents' => 'file write', 'unlink' => 'file delete', 'rename' => 'file rename',
                'move_uploaded_file' => 'upload', 'rmdir' => 'directory delete',
                'broker_send' => 'outbound message', 'mail' => 'outbound mail'];
    // A file written as a CACHE or a housekeeping marker (.htaccess, a circuit-breaker state file, a
    // fetched-tile cache) is bookkeeping about the server, not state a forged request can steer.
    $cacheish = '/cache|htaccess|breaker|\.state|\.lock|tmp|temp|meta/i';
    for ($i = 0; $i < $n; $i++) {
        [$id, $t, $ln] = $sig[$i];
        $fid = $fnOf[$i];
        // Whatever is written inside an idempotent self-healing / lookup function (ensure_*, get_*)
        // is schema or seed repair, not a response to this request.
        if ($fid > 0 && preg_match($incidental, $funcNames[$fid] ?? '')) { continue; }
        // The gate's own bookkeeping (stamping a bearer token's last-used time).
        if ($fid > 0 && isset($bearerFns[$funcNames[$fid] ?? ''])) { continue; }
        // Runs before api/auth.php is even loaded: no session exists to be forged.
        if ($authIdx !== null && $fid === 0 && $i < $authIdx) { continue; }

        if (csrf_is_sql_write_token($id, $t)) {
            $raw = ($id === T_CONSTANT_ENCAPSED_STRING) ? substr($t, 1, -1) : $t;
            $label = preg_replace('/\s+/', ' ', trim(substr($raw, 0, 60)));
            $sites[] = ['idx' => $i, 'line' => $ln, 'kind' => 'SQL write', 'label' => $label];
            continue;
        }
        if ($id === T_STRING && ($sig[$i + 1][1] ?? '') === '(') {
            $prev = $i > 0 ? $sig[$i - 1][0] : null;
            if (in_array($prev, [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) { continue; }
            $name = strtolower($t);
            if (isset($fileOps[$name])) {
                if ($name === 'file_put_contents') {
                    $argEnd = ps_matching($sig, $i + 1);
                    $first = '';
                    $dd = 0;
                    for ($k = $i + 2; $k < $argEnd; $k++) {
                        if (ps_is_open($sig[$k])) { $dd++; }
                        elseif (ps_is_close($sig[$k])) { $dd--; }
                        elseif ($dd === 0 && $sig[$k][0] === null && $sig[$k][1] === ',') { break; }
                        $first .= $sig[$k][1] . ' ';
                    }
                    if (preg_match($cacheish, $first) || ($fid > 0 && preg_match($cacheish, $funcNames[$fid] ?? ''))) { continue; }
                }
                $sites[] = ['idx' => $i, 'line' => $ln, 'kind' => $fileOps[$name], 'label' => $name . '()'];
            } elseif (isset($writers[$name]) && !isset($fnIdByName[$name])) {
                // A writer defined elsewhere (inc/). One defined in THIS file is judged by its body.
                $sites[] = ['idx' => $i, 'line' => $ln, 'kind' => 'writer call', 'label' => $t . '()'];
            }
            continue;
        }
        if ($id === T_VARIABLE && $t === '$_SESSION' && ($sig[$i + 1][1] ?? '') === '[') {
            $close = ps_matching($sig, $i + 1);
            if ($close > 0 && ($sig[$close + 1][1] ?? '') === '=' && ($sig[$close + 1][0] ?? null) === null) {
                $key = ($sig[$i + 2][0] ?? null) === T_CONSTANT_ENCAPSED_STRING ? trim($sig[$i + 2][1], "'\"") : '?';
                if (!isset($benignSession[$key])) {
                    $sites[] = ['idx' => $i, 'line' => $ln, 'kind' => 'session write', 'label' => '$_SESSION[' . $key . '] ='];
                }
            }
        }
    }
    foreach ($sites as &$s) { $s['covered'] = $isCovered($s['idx']); }
    unset($s);

    return ['sites' => $sites, 'guards' => $guards, 'unclear' => $unclear];
}

/**
 * Classify how a file authenticates, by following its includes.
 *   'session' -- loads api/auth.php or calls session_start()
 *   'bearer'  -- no session, but verifies an Authorization header / bearer token
 *   'none'    -- neither
 *
 * @param array<string,string> $consts
 */
function csrf_auth_class(string $file, array $consts): string
{
    $seen = [];
    $viaAuth = ps_closure_matches($file, '#(^|/)api/auth\.php$#', $consts, $seen, '#/api/#');
    if ($viaAuth === true) { return 'session'; }
    $seen = [];
    foreach (ps_closure_files($file, $consts, $seen, '#/api/#') as $f) {
        // Only the endpoint itself and files under api/: config.php, inc/https.php and the like
        // legitimately mention session_start() for every request without authenticating anyone.
        if ($f !== str_replace('\\', '/', $file) && strpos($f, '/api/') === false) { continue; }
        $sig = ps_sig((string) @file_get_contents($f));
        foreach ($sig as $i => $tok) {
            if ($tok[0] === T_STRING && strtolower($tok[1]) === 'session_start' && ($sig[$i + 1][1] ?? '') === '(') { return 'session'; }
        }
    }
    // The External API's gate: every api/external/v1/*.php endpoint requires _auth.php, which
    // authenticates a bearer token (inc/external-auth.php) and never touches the session.
    $seen = [];
    if (ps_closure_matches($file, '#(^|/)api/external/v1/_auth\.php$#', $consts, $seen, '#/api/#') === true) {
        return 'bearer';
    }
    $seen = [];
    foreach (ps_closure_files($file, $consts, $seen, '#/api/#') as $f) {
        foreach (ps_sig((string) @file_get_contents($f)) as $tok) {
            if ($tok[0] === T_CONSTANT_ENCAPSED_STRING
                && (stripos($tok[1], 'HTTP_AUTHORIZATION') !== false || stripos($tok[1], 'Bearer') !== false)) {
                return 'bearer';
            }
        }
    }
    return 'none';
}

// ═══════════════════════════════════════════════════════════════════════════
// Command-line part
// ═══════════════════════════════════════════════════════════════════════════

if (defined('CSRF_COVERAGE_LIBRARY_ONLY')) { return; }

$appRoot = str_replace('\\', '/', dirname(__DIR__));
$opts = getopt('', ['path:', 'baseline:', 'no-baseline', 'list']);
$scanRoot = isset($opts['path']) ? str_replace('\\', '/', rtrim((string) $opts['path'], '/\\')) : $appRoot;
$baselineFile = isset($opts['baseline']) ? (string) $opts['baseline'] : __DIR__ . '/csrf_coverage_baseline.txt';
$consts = ['NEWUI_ROOT' => $scanRoot];

$writers = csrf_writer_functions($scanRoot . '/inc');

$baseline = [];
if (!isset($opts['no-baseline']) && is_file($baselineFile)) {
    foreach (file($baselineFile, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#') { continue; }
        $parts = preg_split('/\s+/', $l, 2);
        $baseline[$parts[0]] = ['used' => false, 'reason' => trim($parts[1] ?? '')];
    }
}

$files = [];
if (is_dir($scanRoot . '/api')) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanRoot . '/api', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fi) {
        if ($fi->getExtension() !== 'php') { continue; }
        $b = $fi->getBasename();
        if ($b[0] === '_' || $b === 'auth.php') { continue; }   // includes, not endpoints
        $files[] = str_replace('\\', '/', $fi->getPathname());
    }
}
sort($files);

$report = [];
$stats = ['files' => 0, 'session' => 0, 'bearer' => 0, 'none' => 0, 'sites' => 0, 'covered' => 0, 'readonly' => 0];
$exempt = [];
$bad = 0;
foreach ($files as $path) {
    $rel = ltrim(substr($path, strlen($scanRoot)), '/');
    $src = (string) @file_get_contents($path);
    $stats['files']++;
    $a = csrf_analyze($src, $writers, str_replace("\\", "/", dirname($path)), $consts);
    $class = ($a['sites'] || $a['unclear']) ? csrf_auth_class($path, $consts) : 'n/a';
    if (!$a['sites'] && !$a['unclear']) { $stats['readonly']++; continue; }
    if ($class !== 'n/a') { $stats[$class]++; }
    $stats['sites'] += count($a['sites']);

    if ($class === 'bearer') {
        $exempt[] = [$rel, 'bearer-token endpoint (no session cookie is consulted, so a forged request carries no credential)'];
        continue;
    }
    $findings = [];
    foreach ($a['sites'] as $s) {
        if ($s['covered']) { $stats['covered']++; continue; }
        $why = $class === 'none'
            ? 'a file that is neither session- nor bearer-authenticated changes state'
            : 'not preceded by a csrf_verify() guard on every path that reaches it';
        $findings[] = ['line' => $s['line'], 'msg' => sprintf('%s %s -- %s', $s['kind'], $s['label'], $why)];
    }
    foreach ($a['unclear'] as $u) {
        $findings[] = ['line' => $u['line'], 'msg' => 'csrf_verify() does not guard what follows -- ' . $u['why']];
    }
    foreach ($findings as $f) {
        $k1 = $rel . ':' . $f['line'];
        if (isset($baseline[$k1])) { $baseline[$k1]['used'] = true; continue; }
        $report[] = sprintf('  %s:%d  %s', $rel, $f['line'], $f['msg']);
        $bad++;
    }
}

printf("\n  csrf coverage: %d endpoint file(s); %d change state (%d session, %d bearer, %d unauthenticated); "
     . "%d state-changing site(s) found, %d covered in session files; %d read-only; %d finding(s)\n",
    $stats['files'], $stats['files'] - $stats['readonly'], $stats['session'], $stats['bearer'], $stats['none'],
    $stats['sites'], $stats['covered'], $stats['readonly'], $bad);

if (isset($opts['list'])) {
    echo "\n  Exempt (listed so the exemption is visible):\n";
    foreach ($exempt as [$rel, $why]) { echo "    {$rel}  -- {$why}\n"; }
}

$stale = [];
foreach ($baseline as $k => $info) {
    if ($info['reason'] === '') { $stale[] = $k . '  (no reason given -- every entry must say why it is a false positive)'; }
    elseif (!$info['used']) { $stale[] = $k; }
}
if ($stale) {
    echo "\n  BAD or STALE baseline entries (remove them / give them a reason):\n";
    foreach ($stale as $k) { echo "    {$k}\n"; }
}

if ($bad) {
    echo "\n" . implode("\n", $report) . "\n\n";
    echo "  A session-authenticated endpoint must call csrf_verify() and reject BEFORE it changes anything:\n";
    echo "      if (empty(\$input['csrf_token']) || !csrf_verify(\$input['csrf_token'])) { json_error('Invalid CSRF token', 403); }\n";
    echo "  The check must dominate every write: a guard inside one action's branch does not cover another's.\n";
}
exit(($bad || $stale) ? 1 : 0);
