<?php
/**
 * GH#153 — seed the caption keys the States / Provinces page added, so the
 * Translations page has a row to edit (a t('key', 'fallback') call whose key
 * was never seeded can not be renamed per install — see the facmodal.*
 * precedent, sql/run_facmodal_captions.php).
 *
 * Idempotent — INSERT IGNORE on (caption_key, lang).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "GH#153 — seed States / Provinces caption keys\n";
echo "=============================================\n\n";

$captions = [
    'sidebar.tab.states_provinces' => ['States / Provinces', 'sidebar'],
];

$added = 0;
foreach ($captions as $key => [$value, $category]) {
    try {
        $st = db_query(
            "INSERT IGNORE INTO `{$prefix}captions_i18n` (`caption_key`, `lang`, `value`, `category`)
             VALUES (?, 'en', ?, ?)",
            [$key, $value, $category]
        );
        $added += $st->rowCount();
    } catch (Exception $e) {
        fwrite(STDERR, "ERROR seeding $key: " . $e->getMessage() . "\n");
        exit(1);
    }
}
foreach (array_keys($captions) as $key) {
    $have = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}captions_i18n` WHERE `caption_key` = ? AND `lang` = 'en'", [$key]);
    if ((int) $have !== 1) {
        fwrite(STDERR, "ERROR: caption $key is missing after seeding\n");
        exit(1);
    }
}

echo "done: $added new caption row(s) seeded (" . count($captions) . " keys checked)\n";
