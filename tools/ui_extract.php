<?php
/**
 * Shared markup/source extractor for the UI-consistency audit.
 *
 * WHY THIS EXISTS
 * ---------------
 * tools/schema_audit.php examined each PHP string literal in ISOLATION, and so
 * could not see a single one of the 89 writer INSERTs, because every one of
 * them is built by concatenation (Phase 125 — tools/sql_extract.php is the fix
 * and the precedent). Markup in this codebase has exactly the same shape, and
 * there is no template-literal escape hatch because the JavaScript is ES5:
 *
 *     assets/js/widget-manager.js:277
 *         '<span class="widget-refresh text-body-secondary" data-widget="'
 *             + item.id + '" style="cursor:pointer" title="Refresh">'
 *             + '<i class="bi bi-arrow-clockwise"></i></span>'
 *
 * Three literals. The first has the class list but no closing '>'; the second
 * has the tail of one attribute and the start of another; the third has the
 * icon. Scanned separately, no rule about "the widget header's control" can
 * ever match. The same is true in PHP, where a tag is routinely split by an
 * inline `<?php echo ... ?>` in the middle of its own attribute list
 * (index.php:134).
 *
 * So this extractor produces, for one source file, a list of MARKUP CHUNKS in
 * which a tag and its attributes have been put back together:
 *
 *   PHP   — the file with every `<?php … ?>` region blanked to spaces (byte
 *           offsets and line numbers preserved), so inline-HTML tags survive
 *           an interpolation in the middle of an attribute list; PLUS every
 *           stitched string-concatenation chain, so echoed markup is seen too.
 *   JS    — comments removed, then every `'a' + expr + 'b'` chain stitched
 *           into one chunk.
 *
 * It also exposes ui_js_code_only(), which removes comments AND string
 * literals, for syntax rules (ES5). That distinction is load-bearing: a naive
 * backtick scan over assets/js reports 100-odd "template literals" in this
 * tree and every single one is a backtick inside a COMMENT
 * (assets/js/config.js:15, assets/js/net-checkins.js:23, …).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

/**
 * Directories/paths that are never product source.
 *
 * `.claude/` matters more than it looks: each .claude/worktrees/<name>/ is an
 * entire second copy of this repository, and a tree-walking tool that forgets
 * it silently triples its own findings. generate-sbom.php was broken exactly
 * this way (fixed in 269d79b); .gitignore carries the same warning.
 *
 * GH#130 (rjonesbsink) -- `/tools/` added for the same reason `(d)` (the
 * emitted-JSON-key scan in tools/dead_control_audit.php) already excludes
 * itself from browser-emission scanning: this whole file collects PRODUCT
 * source for browser/UI-convention checks, and tools/ holds Node.js CLI
 * scripts (require('fs'), process.argv) and PHP maintenance scripts that
 * are never served to a browser and were never meant to follow the
 * ES5-no-build-step convention this audit enforces on assets/js/.
 */
function ui_is_excluded(string $path): bool
{
    foreach (['/vendor/', '/node_modules/', '/.claude/', '/.git/', '/coverage/', '/tools/'] as $frag) {
        if (strpos($path, $frag) !== false) { return true; }
    }
    return (bool) preg_match('/\.min\.(js|css)$/', $path);
}

/**
 * Recursively collect product source files with the given extensions.
 *
 * Paths come back relative to $dir, so the same call works for the app tree
 * ('.') and for the fixture tree the gate test points the tool at with
 * --path=<tmpdir>. Returning absolute paths for the second case would make
 * every finding key machine-specific and unbaselineable.
 *
 * @param array<int,string> $exts e.g. ['php', 'js', 'css']
 * @return array<int,string> relative, forward-slashed, sorted
 */
function ui_collect_files(string $dir, array $exts): array
{
    $out = [];
    if (!is_dir($dir)) { return $out; }
    $root = rtrim(str_replace('\\', '/', $dir), '/') . '/';
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!$f->isFile()) { continue; }
        $p = str_replace('\\', '/', $f->getPathname());
        if (strpos($p, $root) === 0) { $p = substr($p, strlen($root)); }
        $p = preg_replace('#^\./#', '', $p);
        if (ui_is_excluded('/' . $p)) { continue; }
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) { continue; }
        $out[] = $p;
    }
    sort($out);
    return $out;
}

/** Line number (1-based) of a byte offset. */
function ui_line_at(string $src, int $offset): int
{
    return substr_count(substr($src, 0, max(0, $offset)), "\n") + 1;
}

/**
 * Real source line of a match INSIDE a chunk.
 *
 * Every chunk preserves the newlines of the region it came from — the blanked
 * PHP document by construction, a stitched literal because its own newlines
 * survive — so the offset arithmetic is exact for both chunk kinds.
 */
function ui_chunk_line(int $chunkStartLine, string $chunkText, int $offset): int
{
    return $chunkStartLine + substr_count(substr($chunkText, 0, max(0, $offset)), "\n");
}

/**
 * Remove JS comments, preserving every byte position and newline.
 *
 * String literals are KEPT — markup lives inside them.
 */
function ui_js_strip_comments(string $code): string
{
    $out = '';
    $len = strlen($code);
    $i = 0;
    $state = '';   // '' | '"' | "'" | '`' | '//' | '/*'
    while ($i < $len) {
        $c = $code[$i];
        $n = $i + 1 < $len ? $code[$i + 1] : '';
        if ($state === '') {
            if ($c === '"' || $c === "'" || $c === '`') { $state = $c; $out .= $c; $i++; continue; }
            if ($c === '/' && $n === '/') { $state = '//'; $out .= '  '; $i += 2; continue; }
            if ($c === '/' && $n === '*') { $state = '/*'; $out .= '  '; $i += 2; continue; }
            $out .= $c; $i++; continue;
        }
        if ($state === '"' || $state === "'" || $state === '`') {
            if ($c === '\\') { $out .= $c . $n; $i += 2; continue; }
            if ($c === $state) { $state = ''; }
            $out .= $c; $i++; continue;
        }
        if ($state === '//') {
            if ($c === "\n") { $state = ''; $out .= "\n"; } else { $out .= ' '; }
            $i++; continue;
        }
        // '/*'
        if ($c === '*' && $n === '/') { $state = ''; $out .= '  '; $i += 2; continue; }
        $out .= ($c === "\n") ? "\n" : ' ';
        $i++;
    }
    return $out;
}

/**
 * Remove JS comments AND string literals, preserving byte positions.
 * What is left is executable syntax — the only safe input for a rule about
 * arrow functions, let/const or template literals.
 */
function ui_js_code_only(string $code): string
{
    $out = '';
    $len = strlen($code);
    $i = 0;
    $state = '';
    while ($i < $len) {
        $c = $code[$i];
        $n = $i + 1 < $len ? $code[$i + 1] : '';
        if ($state === '') {
            if ($c === '"' || $c === "'") { $state = $c; $out .= ' '; $i++; continue; }
            if ($c === '/' && $n === '/') { $state = '//'; $out .= '  '; $i += 2; continue; }
            if ($c === '/' && $n === '*') { $state = '/*'; $out .= '  '; $i += 2; continue; }
            $out .= $c; $i++; continue;   // backticks survive: that IS the finding
        }
        if ($state === '"' || $state === "'") {
            if ($c === '\\') { $out .= '  '; $i += 2; continue; }
            if ($c === $state) { $state = ''; }
            $out .= ($c === "\n") ? "\n" : ' ';
            $i++; continue;
        }
        if ($state === '//') {
            if ($c === "\n") { $state = ''; $out .= "\n"; } else { $out .= ' '; }
            $i++; continue;
        }
        if ($c === '*' && $n === '/') { $state = ''; $out .= '  '; $i += 2; continue; }
        $out .= ($c === "\n") ? "\n" : ' ';
        $i++;
    }
    return $out;
}

/**
 * A PHP file's inline HTML, with every `<?php … ?>` region blanked to spaces.
 *
 * Byte offsets and line numbers are preserved, so a tag whose attribute list
 * is interrupted by an interpolation still reads as one tag:
 *
 *     <button class="btn btn-sm" data-widget="statistics"
 *             title="<?php echo e(t('dash.widget.statistics','Statistics')); ?>">
 *
 * becomes a well-formed `<button …>` with an empty title, which is exactly
 * what a rule about button attributes needs to see.
 */
function ui_strip_html_comments(string $doc): string
{
    // `<!-- … -->` is not markup, and this codebase comments in it heavily —
    // login.php:944 explains a Phase 44 accessibility change by quoting the
    // very `<button>` shape a rule about buttons is looking for. Blank the
    // comment, keep every newline so line numbers stay exact.
    return (string) preg_replace_callback(
        '/<!--.*?-->/s',
        static fn(array $m): string => (string) preg_replace('/[^\n]/', ' ', $m[0]),
        $doc
    );
}

function ui_php_markup_document(string $src): string
{
    $out = str_repeat(' ', strlen($src));
    $tokens = @token_get_all($src);
    if (!$tokens) { return ui_strip_html_comments($src); }
    $pos = 0;
    foreach ($tokens as $tk) {
        $text = is_array($tk) ? $tk[1] : $tk;
        $len  = strlen($text);
        $keep = is_array($tk) && $tk[0] === T_INLINE_HTML;
        for ($k = 0; $k < $len; $k++) {
            $ch = $text[$k];
            if ($keep) { $out[$pos + $k] = $ch; }
            elseif ($ch === "\n") { $out[$pos + $k] = "\n"; }
        }
        $pos += $len;
    }
    return ui_strip_html_comments($out);
}

/**
 * Stitch concatenated PHP string literals into markup chunks.
 *
 * `'<div class="card ' . $extra . '">'` arrives as one chunk. Interpolated
 * expressions are dropped but never break the chain — the same rule
 * tools/sql_extract.php uses, for the same reason.
 *
 * @return array<int, array{0:int,1:string}> [line, text]
 */
function ui_php_string_chunks(string $src): array
{
    $tokens = @token_get_all($src);
    if (!$tokens) { return []; }
    $out = [];
    $buf = null;
    $bufLine = 0;
    $inDq = false;
    $inHd = false;
    $curLine = 1;

    $flush = static function () use (&$buf, &$bufLine, &$out) {
        if ($buf !== null && trim($buf) !== '') { $out[] = [$bufLine, $buf]; }
        $buf = null;
    };
    $begin = static function (int $line) use (&$buf, &$bufLine) {
        if ($buf === null) { $buf = ''; $bufLine = $line; }
    };

    foreach ($tokens as $tk) {
        if (is_array($tk)) {
            [$id, $text, $ln] = $tk;
            $curLine = $ln;
            switch ($id) {
                case T_WHITESPACE:
                case T_COMMENT:
                case T_DOC_COMMENT:
                    continue 2;                       // never breaks a chain
                case T_CONSTANT_ENCAPSED_STRING:
                    $begin($ln);
                    $buf .= stripcslashes(substr($text, 1, -1));
                    continue 2;
                case T_ENCAPSED_AND_WHITESPACE:
                    $begin($ln);
                    $buf .= ($inDq || $inHd) ? $text : stripcslashes($text);
                    continue 2;
                case T_START_HEREDOC:
                    $inHd = true; $begin($ln);
                    continue 2;
                case T_END_HEREDOC:
                    $inHd = false;
                    continue 2;
                case T_VARIABLE:
                case T_CURLY_OPEN:
                case T_STRING_VARNAME:
                case T_OBJECT_OPERATOR:
                case T_NUM_STRING:
                case T_STRING:
                    if ($inDq || $inHd) { continue 2; }
                    continue 2;                       // interpolation: skip, keep chain
                default:
                    if (!$inDq && !$inHd) { $flush(); }
                    continue 2;
            }
        }
        if ($tk === '"') {
            $inDq = !$inDq;
            if ($inDq) { $begin($curLine); }
            continue;
        }
        if ($tk === '.' || $tk === '(' || $tk === ')' || $tk === ',') {
            continue;                                  // concatenation / call: keep going
        }
        if ($inDq || $inHd) { continue; }
        $flush();
    }
    $flush();
    return $out;
}

/**
 * Stitch `'a' + expr + 'b'` chains in (comment-stripped) JS into markup chunks.
 *
 * The gap between two literals continues the chunk only when it is a `+`-joined
 * expression: it must start with '+', end with '+', and contain no statement
 * terminator. So `f('a', 'b')` yields two chunks and `'a' + x.y + 'b'` yields
 * one — which is what puts widget-manager.js's split `data-widget="` attribute
 * back together.
 *
 * @return array<int, array{0:int,1:string}> [line, text]
 */
function ui_js_string_chunks(string $code): array
{
    $out = [];
    $len = strlen($code);
    $i = 0;
    $buf = null;
    $bufLine = 0;
    $gapStart = -1;

    while ($i < $len) {
        $c = $code[$i];
        if ($c !== '"' && $c !== "'" && $c !== '`') { $i++; continue; }

        // Read one string literal.
        $quote = $c;
        $start = $i;
        $i++;
        $val = '';
        while ($i < $len) {
            $ch = $code[$i];
            if ($ch === '\\') { $val .= $code[$i + 1] ?? ''; $i += 2; continue; }
            if ($ch === $quote) { $i++; break; }
            $val .= $ch;
            $i++;
        }

        $continues = false;
        if ($buf !== null && $gapStart >= 0) {
            $gap = trim(substr($code, $gapStart, $start - $gapStart));
            $continues = $gap !== ''
                && $gap[0] === '+'
                && substr($gap, -1) === '+'
                && strpos($gap, ';') === false;
        }
        if ($buf !== null && !$continues) {
            if (trim($buf) !== '') { $out[] = [$bufLine, $buf]; }
            $buf = null;
        }
        if ($buf === null) { $buf = ''; $bufLine = ui_line_at($code, $start); }
        $buf .= stripcslashes($val);
        $gapStart = $i;
    }
    if ($buf !== null && trim($buf) !== '') { $out[] = [$bufLine, $buf]; }
    return $out;
}

/**
 * Every markup chunk in a source file, tag-and-attributes intact.
 *
 * @return array<int, array{0:int,1:string}> [line, text]
 */
function ui_markup_chunks(string $path, string $src): array
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'php') {
        $doc = ui_php_markup_document($src);
        $chunks = [];
        // The blanked document is kept whole so a multi-line tag survives;
        // line numbers come from offsets at match time, so callers pass the
        // document itself as one chunk anchored at line 1.
        if (trim($doc) !== '') { $chunks[] = [1, $doc]; }
        foreach (ui_php_string_chunks($src) as $c) { $chunks[] = $c; }
        return $chunks;
    }
    if ($ext === 'js') {
        return ui_js_string_chunks(ui_js_strip_comments($src));
    }
    return [[1, $src]];
}
