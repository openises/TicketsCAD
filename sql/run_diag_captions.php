<?php
/**
 * GH #8 / #13 tester-assist — seed the diag.* caption keys.
 *
 * The self-service Diagnostics page (diagnostics.php + assets/js/diagnostics.js)
 * was internationalized with t('diag.*', fallback) calls. Per the standing i18n
 * practice, every new t() key must be seeded into captions_i18n so the
 * Translations UI has a row to edit — otherwise the string permanently shows its
 * hardcoded English fallback and a per-install rename can't reach it.
 *
 * Idempotent — INSERT IGNORE on (caption_key, lang).
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "GH #8/#13 — seed diag.* caption keys\n";
echo "====================================\n\n";

$captions = [
    'diag.title'     => 'Diagnostics',
    'diag.heading'   => 'Diagnostics',
    'diag.sub'       => 'Check that real-time updates and notifications work on this device',
    'diag.rerun'     => 'Re-run',
    'diag.copy'      => 'Copy report',
    'diag.intro'     => 'If something on the dashboard or mobile isn\'t updating on its own, or you\'re not getting notifications, run this and send the results (use "Copy report" or a screenshot) so we can see exactly where it breaks.',
    'diag.rt'        => 'Real-time updates (live refresh)',
    'diag.testing'   => 'Testing…',
    'diag.push'      => 'Push notifications',
    'diag.push_test' => 'Send a test to this device',
    'diag.env'       => 'This device & browser',
    'diag.server'    => 'Server settings',
    'diag.zello'     => 'Radio (Zello) connection',
    // Navbar entry (Help/User menu → Diagnostics).
    'nav.user.diagnostics' => 'Diagnostics',
];

$added = 0;
foreach ($captions as $key => $value) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}captions_i18n` (`caption_key`, `lang`, `value`, `category`)
             VALUES (?, 'en', ?, 'diag')",
            [$key, $value]
        );
        $added += (int) db_fetch_value("SELECT ROW_COUNT()");
    } catch (Exception $e) {
        fwrite(STDERR, "ERROR seeding $key: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "done: $added new caption row(s) seeded (" . count($captions) . " keys checked)\n";
echo "These now appear in Settings → Translations for per-install renaming.\n";
