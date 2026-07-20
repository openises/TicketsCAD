<?php
/**
 * Phase 11c — rename the User Accounts role label.
 *
 * Eric on 2026-06-11: "Role & Permissions set" → "Role and permission group".
 *
 * Idempotent. Updates the existing useracct.role_label rows in place.
 *
 * Usage:  php sql/run_phase11c_label_rename.php
 */
require_once __DIR__ . '/../config.php';

echo "Phase 11c — rename role label\n";
echo "=============================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

$newLabel = [
    'en' => 'Role and permission group',
    'de' => 'Rolle und Berechtigungsgruppe',
    'nl' => 'Rol en machtigingengroep',
    'fr' => 'Rôle et groupe d\'autorisations',
    'es' => 'Rol y grupo de permisos',
];

$updated = 0;
foreach ($newLabel as $lang => $value) {
    try {
        $stmt = db_query(
            "UPDATE `{$prefix}captions_i18n`
             SET `value` = ?
             WHERE `caption_key` = 'useracct.role_label' AND `lang` = ?",
            [$value, $lang]
        );
        if ($stmt && $stmt->rowCount() > 0) {
            $updated++;
        } else {
            // No row to update — insert.
            db_query(
                "INSERT IGNORE INTO `{$prefix}captions_i18n`
                 (caption_key, lang, value, category) VALUES (?, ?, ?, 'useracct')",
                ['useracct.role_label', $lang, $value]
            );
        }
        echo "[OK] useracct.role_label [{$lang}] = '{$value}'\n";
    } catch (Exception $e) {
        echo "[WARN] [{$lang}]: " . $e->getMessage() . "\n";
    }
}

echo "\n{$updated} row(s) updated.\nDone.\n";
