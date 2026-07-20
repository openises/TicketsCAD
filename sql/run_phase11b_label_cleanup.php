<?php
/**
 * Phase 11b — purge "Level/Levels" wording from user-facing strings.
 *
 * Eric noted on 2026-06-11 that the user-facing concept is "Roles &
 * Permissions" — the word "Level/Levels" shouldn't appear anywhere
 * a user can see, even where it referred to the legacy migration
 * concept. The DB column `user.level` stays internally for backward
 * compat with the 18-file admin-shortcut surface, but every label,
 * button, hint, sidebar entry, and warning gets renamed.
 *
 * This migration UPDATEs the existing Phase 11 caption rows in place
 * for both EN and the other 4 languages. It overwrites — the prior
 * INSERT IGNORE seeded with "Levels" wording is what we want gone.
 *
 * Usage:  php sql/run_phase11b_label_cleanup.php
 * Safety: Idempotent. Repeats are no-ops because the new wording is
 *         already in the table after the first run.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 11b — purge 'Level/Levels' from captions\n";
echo "==============================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

$updates = [
    // sidebar entry
    'sidebar.tab.roles_permissions' => [
        'category' => 'sidebar.tab',
        'en' => 'Roles & Permissions',
        'de' => 'Rollen & Berechtigungen',
        'nl' => 'Rollen & Machtigingen',
        'fr' => 'Rôles et autorisations',
        'es' => 'Roles y permisos',
    ],
    'migrate_legacy.button' => [
        'category' => 'migrate_legacy',
        'en' => 'Migrate Legacy Accounts to Roles',
        'de' => 'Legacy-Konten in Rollen überführen',
        'nl' => 'Legacy-accounts overzetten naar rollen',
        'fr' => 'Migrer les comptes hérités vers des rôles',
        'es' => 'Migrar cuentas heredadas a roles',
    ],
    'migrate_legacy.hint' => [
        'category' => 'migrate_legacy',
        'en' => 'Assigns roles to any user accounts carried over from a legacy installation. Safe to run multiple times. Once every account has a role, this option disappears.',
        'de' => 'Weist Rollen an Benutzerkonten zu, die aus einer Legacy-Installation übernommen wurden. Kann mehrfach ausgeführt werden. Sobald jedes Konto eine Rolle hat, verschwindet diese Option.',
        'nl' => 'Wijst rollen toe aan gebruikersaccounts die zijn overgenomen uit een legacy-installatie. Kan meerdere keren worden uitgevoerd. Zodra elk account een rol heeft, verdwijnt deze optie.',
        'fr' => 'Attribue des rôles aux comptes utilisateur repris d\'une installation héritée. Peut être exécuté plusieurs fois. Une fois que chaque compte a un rôle, cette option disparaît.',
        'es' => 'Asigna roles a las cuentas de usuario heredadas de una instalación anterior. Se puede ejecutar varias veces. Cuando todas las cuentas tienen un rol, esta opción desaparece.',
    ],
    'migrate_legacy.done' => [
        'category' => 'migrate_legacy',
        'en' => 'All user accounts are on the Roles & Permissions system.',
        'de' => 'Alle Benutzerkonten verwenden das Rollen-und-Berechtigungssystem.',
        'nl' => 'Alle gebruikersaccounts gebruiken het Rollen & Machtigingen-systeem.',
        'fr' => 'Tous les comptes utilisateur sont sur le système Rôles et autorisations.',
        'es' => 'Todas las cuentas de usuario están en el sistema de Roles y permisos.',
    ],
    // profile.php — old label was "Access Level" (showed get_level_text()).
    // New label is "Role" and the value is the user's RBAC role name(s).
    'profile.label.role' => [
        'category' => 'profile.label',
        'en' => 'Role',
        'de' => 'Rolle',
        'nl' => 'Rol',
        'fr' => 'Rôle',
        'es' => 'Rol',
    ],
];

$updated = 0;
$inserted = 0;

foreach ($updates as $key => $row) {
    $cat = $row['category'];
    foreach (['en', 'de', 'nl', 'fr', 'es'] as $lang) {
        $value = $row[$lang];
        try {
            // Try UPDATE first; if no row exists, INSERT.
            $stmt = db_query(
                "UPDATE `{$prefix}captions_i18n`
                 SET `value` = ?, `category` = ?
                 WHERE `caption_key` = ? AND `lang` = ?",
                [$value, $cat, $key, $lang]
            );
            if ($stmt && $stmt->rowCount() > 0) {
                $updated++;
            } else {
                // No existing row — insert
                db_query(
                    "INSERT IGNORE INTO `{$prefix}captions_i18n`
                     (caption_key, lang, value, category) VALUES (?, ?, ?, ?)",
                    [$key, $lang, $value, $cat]
                );
                $inserted++;
            }
        } catch (Exception $e) {
            echo "[WARN] {$key} ({$lang}): " . $e->getMessage() . "\n";
        }
    }
    echo "[OK] {$key} updated/inserted in 5 languages\n";
}

// Delete now-obsolete caption keys that referenced the legacy concept.
$staleKeys = [
    'sidebar.tab.roles_levels',     // → sidebar.tab.roles_permissions
    'profile.label.access_level',   // → profile.label.role
];
foreach ($staleKeys as $stale) {
    try {
        $stmt = db_query(
            "DELETE FROM `{$prefix}captions_i18n` WHERE `caption_key` = ?",
            [$stale]
        );
        $del = $stmt ? $stmt->rowCount() : 0;
        if ($del > 0) {
            echo "[OK] removed {$del} stale '{$stale}' caption row(s)\n";
        }
    } catch (Exception $e) {
        echo "[WARN] delete stale {$stale}: " . $e->getMessage() . "\n";
    }
}

echo "\nSummary: {$updated} updated, {$inserted} inserted\n";
echo "Done.\n";
