<?php
/**
 * One-off: add `<meta name="csrf-token" content="<?php echo e($csrf); ?>">`
 * to every top-level page that calls csrf_token() but doesn't already render
 * the meta tag.
 *
 * Idempotent. Skips files that don't compute $csrf or already have the tag.
 */

$root = realpath(__DIR__ . '/..');
$files = glob($root . '/*.php');
$added = 0; $skipped = 0;
foreach ($files as $f) {
    $base = basename($f);
    $src  = file_get_contents($f);

    if (!preg_match('/csrf_token\s*\(\s*\)/', $src)) {
        // doesn't even use csrf — leave alone
        continue;
    }
    if (strpos($src, 'name="csrf-token"') !== false) {
        $skipped++;
        continue;
    }
    if (!preg_match('/\$csrf\s*=\s*csrf_token\s*\(\s*\)/', $src)) {
        echo "  [skip] $base — no \$csrf variable\n";
        continue;
    }

    $newSrc = preg_replace(
        '/(<meta\s+name="viewport"[^>]*>)/i',
        '$1' . "\n    " . '<meta name="csrf-token" content="<?php echo e($csrf); ?>">',
        $src,
        1,
        $count
    );
    if (!$count) {
        echo "  [skip] $base — could not find <meta viewport>\n";
        continue;
    }
    file_put_contents($f, $newSrc);
    echo "  [ok]   $base\n";
    $added++;
}
echo "\n[result] added to $added files, $skipped already had it\n";
