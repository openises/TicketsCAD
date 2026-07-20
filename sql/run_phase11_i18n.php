<?php
/**
 * Run Phase 11 i18n — captions for the canonical RBAC UI strings.
 *
 * ~5 new keys × 5 languages = ~25 rows covering:
 *   - User Accounts dropdown label "Role & Permissions set"
 *   - Dropdown placeholder
 *   - User list table "Role" column header
 *   - Migrate Legacy Levels button + hint
 *   - "All users on RBAC" confirmation message
 *
 * Usage:  php sql/run_phase11_i18n.php
 * Safety: Idempotent (INSERT IGNORE on caption_key+lang).
 */
require_once __DIR__ . '/../config.php';

echo "Phase 11 i18n — canonical RBAC captions\n";
echo "=======================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function _phase11_seed(string $key, string $category, array $byLang): void {
    global $prefix, $inserted, $skipped;
    foreach ($byLang as $lang => $value) {
        try {
            $stmt = db_query(
                "INSERT IGNORE INTO `{$prefix}captions_i18n`
                 (caption_key, lang, value, category)
                 VALUES (?, ?, ?, ?)",
                [$key, $lang, $value, $category]
            );
            if ($stmt && $stmt->rowCount() > 0) { $inserted++; } else { $skipped++; }
        } catch (Exception $e) {
            echo "[WARN] {$key} ({$lang}): " . $e->getMessage() . "\n";
            $skipped++;
        }
    }
}

$inserted = 0;
$skipped  = 0;

_phase11_seed('useracct.role_label', 'useracct', [
    'en' => 'Role & Permissions set',
    'de' => 'Rolle & Berechtigungssatz',
    'nl' => 'Rol & Machtigingenset',
    'fr' => 'Rôle et ensemble d\'autorisations',
    'es' => 'Rol y conjunto de permisos',
]);

_phase11_seed('useracct.role_placeholder', 'useracct', [
    'en' => '— Select a role —',
    'de' => '— Rolle wählen —',
    'nl' => '— Selecteer een rol —',
    'fr' => '— Sélectionner un rôle —',
    'es' => '— Seleccione un rol —',
]);

_phase11_seed('useracct.role_col', 'useracct', [
    'en' => 'Role', 'de' => 'Rolle', 'nl' => 'Rol', 'fr' => 'Rôle', 'es' => 'Rol',
]);

_phase11_seed('migrate_legacy.button', 'migrate_legacy', [
    'en' => 'Migrate Legacy Levels',
    'de' => 'Legacy-Stufen migrieren',
    'nl' => 'Legacy-niveaus migreren',
    'fr' => 'Migrer les niveaux hérités',
    'es' => 'Migrar niveles heredados',
]);

_phase11_seed('migrate_legacy.hint', 'migrate_legacy', [
    'en' => 'Automatically assigns RBAC roles to all users based on their current access level. Safe to run multiple times.',
    'de' => 'Weist allen Benutzern automatisch RBAC-Rollen anhand ihrer aktuellen Zugriffsstufe zu. Kann mehrfach ausgeführt werden.',
    'nl' => 'Wijst automatisch RBAC-rollen toe aan alle gebruikers op basis van hun huidige toegangsniveau. Kan meerdere keren worden uitgevoerd.',
    'fr' => 'Attribue automatiquement des rôles RBAC à tous les utilisateurs en fonction de leur niveau d\'accès actuel. Peut être exécuté plusieurs fois.',
    'es' => 'Asigna automáticamente roles RBAC a todos los usuarios según su nivel de acceso actual. Se puede ejecutar varias veces.',
]);

_phase11_seed('migrate_legacy.done', 'migrate_legacy', [
    'en' => 'All users are on the canonical RBAC role system. No migration needed.',
    'de' => 'Alle Benutzer sind im kanonischen RBAC-Rollensystem. Keine Migration erforderlich.',
    'nl' => 'Alle gebruikers staan op het canonieke RBAC-rollensysteem. Geen migratie nodig.',
    'fr' => 'Tous les utilisateurs sont sur le système de rôles RBAC canonique. Aucune migration nécessaire.',
    'es' => 'Todos los usuarios están en el sistema canónico de roles RBAC. No se necesita migración.',
]);

echo "[OK] Inserted {$inserted} caption rows (skipped {$skipped} existing)\n";

try {
    $rows = db_fetch_all(
        "SELECT lang, COUNT(*) AS n FROM `{$prefix}captions_i18n` GROUP BY lang ORDER BY lang"
    );
    echo "\nFinal caption counts per language:\n";
    foreach ($rows as $r) printf("  %-4s : %d\n", $r['lang'], (int)$r['n']);
} catch (Exception $e) {
    echo "[WARN] report: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
