<?php
/**
 * GH#142 (Phase 155) - agency logo: NOTHING TOUCHES THE DISK.
 *
 * The design decision that makes the feature safe to ship on IIS, Docker and
 * shared hosting alike (no served-directory rule to keep in sync, no new Docker
 * volume, no directory-permission race, no web-executable-file class, no path to
 * traverse) is that the image lives in the database and no file is ever written.
 * That is only a design if it is verified, so this test TOKENIZES the branding
 * files and fails on any filesystem-write call. Tokenized, never grepped: the
 * docblocks name these functions on purpose (to say the code never calls them),
 * and a substring scan cannot tell an explanation from an occurrence.
 *
 * Also asserts the image encoders (imagepng/imagejpeg/imagewebp/imagegif) are
 * only ever called with a NULL destination (they write to the output buffer, not
 * to a path), and that the branding paths never touch the uploads/ directory.
 *
 * Not @requires-db: it parses static source only.
 *
 * Usage: php tests/test_gh142_branding_nofs.php
 */
// Loaded first, before any output: config.php sets session ini directives PHP
// refuses (with a warning) once a byte has been echoed.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/branding.php';
require_once __DIR__ . '/_gh142_branding_fixtures.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#142 - branding never writes a file ===\n\n";

$base = dirname(__DIR__);
$files = ['inc/branding.php', 'api/branding-logo.php', 'api/branding-admin.php', 'branding-admin.php', 'sql/run_gh142_branding_logos.php'];

// Every function that creates, writes, moves, copies, links, truncates, changes
// the mode of, or deletes something on disk. Read-only helpers (is_file for the
// asset cache-buster in the admin page, file_get_contents on the upload tmp path)
// are deliberately NOT here: the claim is that nothing is WRITTEN.
$banned = [
    'move_uploaded_file', 'file_put_contents', 'fopen', 'fwrite', 'fputs', 'fputcsv', 'ftruncate',
    'mkdir', 'rename', 'copy', 'unlink', 'rmdir', 'touch', 'tempnam', 'tmpfile', 'symlink',
    'link', 'chmod', 'chown', 'chgrp',
];
$encoders = ['imagepng', 'imagejpeg', 'imagewebp', 'imagegif', 'imagebmp', 'imageavif', 'image2wbmp', 'imagewbmp', 'imagexbm', 'imagegd', 'imagegd2'];

/** @return array{calls:string[], encoderArgs:array<int,string>} */
function gh142_scan(string $source, array $banned, array $encoders): array
{
    $tokens = token_get_all($source);
    $calls = [];
    $encoderArgs = [];
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok) || $tok[0] !== T_STRING) { continue; }
        $name = strtolower($tok[1]);
        // The next significant token must be "(" for this to be a call, and the
        // previous significant token must not be "->" / "::" / "function" (a
        // method call or a declaration of that name is not the builtin).
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $j++; }
        if (!isset($tokens[$j]) || $tokens[$j] !== '(') { continue; }
        $k = $i - 1;
        while ($k >= 0 && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $k--; }
        if ($k >= 0 && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) { continue; }
        if (in_array($name, $banned, true)) { $calls[] = $name; }
        if (in_array($name, $encoders, true)) {
            // Collect the tokens of the argument list up to the first top-level ")"
            // and keep the text of the SECOND argument.
            $depth = 0; $arg = 0; $second = '';
            for ($m = $j; $m < $n; $m++) {
                $t = $tokens[$m];
                $text = is_array($t) ? $t[1] : $t;
                if ($text === '(') { $depth++; if ($depth === 1) continue; }
                if ($text === ')') { $depth--; if ($depth === 0) break; }
                if ($depth === 1 && $text === ',') { $arg++; continue; }
                if ($depth >= 1 && $arg === 1 && !(is_array($t) && $t[0] === T_WHITESPACE)) { $second .= $text; }
            }
            $encoderArgs[] = $name . ':' . strtolower($second);
        }
    }
    return ['calls' => $calls, 'encoderArgs' => $encoderArgs];
}

foreach ($files as $rel) {
    $path = $base . '/' . $rel;
    t("{$rel} exists", is_file($path));
    $scan = gh142_scan((string) file_get_contents($path), $banned, $encoders);
    t("{$rel} calls no filesystem function" . ($scan['calls'] ? ' (found: ' . implode(', ', array_unique($scan['calls'])) . ')' : ''), $scan['calls'] === []);
    $bad = array_filter($scan['encoderArgs'], function ($e) { return substr($e, -5) !== ':null'; });
    t("{$rel}: every image encoder writes to the output buffer (destination null)" . ($bad ? ' (found: ' . implode(', ', $bad) . ')' : ''), $bad === []);
}

// The files that legitimately READ the upload are allowed file_get_contents(),
// but only on PHP's own temporary upload path and php://input, never a path
// built from anything the client sent.
$admin = (string) file_get_contents($base . '/api/branding-admin.php');
t("the endpoint reads only PHP's own upload tmp_name (verified with is_uploaded_file) and php://input",
    substr_count($admin, 'file_get_contents(') === 2 && strpos($admin, 'is_uploaded_file($tmp)') !== false
    && strpos($admin, "file_get_contents('php://input')") !== false && strpos($admin, 'file_get_contents($tmp, false, null, 0, BRANDING_MAX_UPLOAD_BYTES + 1)') !== false);
t('the endpoint never reads the client-supplied file NAME or TYPE at all',
    strpos($admin, "\$f['name']") === false && strpos($admin, "['logo']['name']") === false
    && strpos($admin, "\$f['type']") === false && strpos($admin, "['logo']['type']") === false);
$lib = (string) file_get_contents($base . '/inc/branding.php');
t('the library never reads a file (its only input is bytes handed to it)', strpos($lib, 'file_get_contents') === false);
t('nothing in the branding code refers to the uploads/ directory', strpos($lib . $admin, 'uploads/') === false);

// Positive controls: the scanner really would catch these (so a pass is not vacuous).
$ctl = gh142_scan("<?php\n// file_put_contents is only named in this comment\n\$x = 'fopen';\nfile_put_contents('/tmp/x', 'y');\n\$o->unlink();\nclass A { function mkdir() {} }\nimagepng(\$im, '/tmp/x.png');\nimagepng(\$im, null, 9);\n", $banned, $encoders);
t('control: a real file_put_contents() call IS found', in_array('file_put_contents', $ctl['calls'], true));
t('control: a string, a comment, a method call and a method declaration are NOT flagged',
    count(array_keys($ctl['calls'], 'fopen')) === 0 && count(array_keys($ctl['calls'], 'unlink')) === 0 && count(array_keys($ctl['calls'], 'mkdir')) === 0
    && count(array_keys($ctl['calls'], 'file_put_contents')) === 1);
t('control: imagepng() to a path IS distinguished from imagepng() to null',
    in_array("imagepng:'/tmp/x.png'", $ctl['encoderArgs'], true) && in_array('imagepng:null', $ctl['encoderArgs'], true));

// And the runtime proof: the whole life of a logo (validate, re-encode, store,
// resolve, read back as a data: URI, delete) in a CHILD process whose temp
// directory (TMP/TEMP, which sys_get_temp_dir() honours) is a fresh private
// empty directory. This machine runs other processes that write to the shared
// temp directory, so diffing that one would be noise. If the pipeline wrote a
// file anywhere PHP's temp functions point, it would land in the private one.
require_once __DIR__ . '/_gh142_cli.php';
if (branding_gd_available() && branding_table_exists()) {
    $private = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gh142-nofs-' . getmypid() . '-' . mt_rand();
    $made = @mkdir($private, 0777, true) || is_dir($private);
    $uploadsBefore = glob($base . '/uploads/*') ?: [];
    $snapshot = db_fetch_all("SELECT * FROM " . db_table('branding_logos'));
    try {
        $r = gh142_run_php([__DIR__ . '/_gh142_probe.php', 'lifecycle', '{}'], 60, ['TMP' => $private, 'TEMP' => $private, 'TMPDIR' => $private]);
        $res = json_decode(trim($r['out']), true)['v'] ?? null;
        t('the lifecycle child ran, stored, read back a data: URI and deleted', is_array($res) && $res['stored'] && $res['uri'] && $res['deleted']);
        t('...and its temp directory really was the private one (so the check below means something)',
            is_array($res) && realpath($res['tmp']) === realpath($private));
        $left = array_diff(scandir($private) ?: [], ['.', '..']);
        t('...it created NO file or directory in that temp directory' . ($left ? ' (found: ' . implode(', ', $left) . ')' : ''), $left === []);
        $uploadsAfter = glob($base . '/uploads/*') ?: [];
        t('...and none under uploads/', count(array_diff($uploadsAfter, $uploadsBefore)) === 0);
    } finally {
        db_query("DELETE FROM " . db_table('branding_logos'));
        foreach ($snapshot as $row) {
            $cols = array_keys($row);
            db_query("INSERT INTO " . db_table('branding_logos') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")", array_values($row));
        }
        branding_reset_cache();
        if ($made) { @rmdir($private); }
    }
} else {
    echo "[SKIP] runtime check: GD or the branding table is not available\n";
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
