<?php
/**
 * Run Phase 36 i18n — captions for the rewritten settings sidebar.
 *
 * Adds keys for new sections, sub-section headers, and the filter box
 * placeholder. ~30 keys × 5 languages = ~150 rows.
 *
 * Usage:  php sql/run_phase36_i18n.php
 * Safety: Idempotent (INSERT IGNORE).
 */
require_once __DIR__ . '/../config.php';

echo "Phase 36 i18n — settings sidebar reorg captions\n";
echo "================================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function _phase36_seed(string $key, string $category, array $byLang): void {
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

// Filter box
_phase36_seed('sidebar.filter.placeholder', 'sidebar', [
    'en' => 'Filter settings…', 'de' => 'Einstellungen filtern…',
    'nl' => 'Instellingen filteren…', 'fr' => 'Filtrer les réglages…',
    'es' => 'Filtrar ajustes…',
]);

// Section headers
_phase36_seed('sidebar.section.operations', 'sidebar', [
    'en' => 'Operations', 'de' => 'Betrieb',
    'nl' => 'Operaties', 'fr' => 'Opérations', 'es' => 'Operaciones',
]);
_phase36_seed('sidebar.section.identity_security', 'sidebar', [
    'en' => 'Identity & Security', 'de' => 'Identität & Sicherheit',
    'nl' => 'Identiteit & Beveiliging', 'fr' => 'Identité et sécurité',
    'es' => 'Identidad y seguridad',
]);
_phase36_seed('sidebar.section.initial_setup', 'sidebar', [
    'en' => 'Initial Setup', 'de' => 'Erstinstallation',
    'nl' => 'Eerste installatie', 'fr' => 'Configuration initiale',
    'es' => 'Configuración inicial',
]);
_phase36_seed('sidebar.section.app_dispatch', 'sidebar', [
    'en' => 'Application — Dispatch', 'de' => 'Anwendung — Disposition',
    'nl' => 'Applicatie — Verzending', 'fr' => 'Application — Répartition',
    'es' => 'Aplicación — Despacho',
]);
_phase36_seed('sidebar.section.app_presentation', 'sidebar', [
    'en' => 'Application — Presentation', 'de' => 'Anwendung — Darstellung',
    'nl' => 'Applicatie — Weergave', 'fr' => 'Application — Présentation',
    'es' => 'Aplicación — Presentación',
]);
_phase36_seed('sidebar.section.app_geo_data', 'sidebar', [
    'en' => 'Application — Geographic Data', 'de' => 'Anwendung — Geodaten',
    'nl' => 'Applicatie — Geografische gegevens', 'fr' => 'Application — Données géographiques',
    'es' => 'Aplicación — Datos geográficos',
]);
_phase36_seed('sidebar.section.people', 'sidebar', [
    'en' => 'People', 'de' => 'Personen',
    'nl' => 'Personen', 'fr' => 'Personnes', 'es' => 'Personas',
]);
_phase36_seed('sidebar.section.resources', 'sidebar', [
    'en' => 'Resources', 'de' => 'Ressourcen',
    'nl' => 'Bronnen', 'fr' => 'Ressources', 'es' => 'Recursos',
]);
_phase36_seed('sidebar.section.comms_integrations', 'sidebar', [
    'en' => 'Communications & Integrations', 'de' => 'Kommunikation & Integrationen',
    'nl' => 'Communicatie & integraties', 'fr' => 'Communications et intégrations',
    'es' => 'Comunicaciones e integraciones',
]);

// Sub-headers
$subs = [
    'sidebar.sub.connectivity'   => ['Connectivity', 'Konnektivität', 'Connectiviteit', 'Connectivité', 'Conectividad'],
    'sidebar.sub.localization'   => ['Localization', 'Lokalisierung', 'Lokalisatie', 'Localisation', 'Localización'],
    'sidebar.sub.people_teams'   => ['People & Teams', 'Personen & Teams', 'Personen & teams', 'Personnes et équipes', 'Personas y equipos'],
    'sidebar.sub.roles_quals'    => ['Roles & Qualifications', 'Rollen & Qualifikationen', 'Rollen & kwalificaties', 'Rôles et qualifications', 'Roles y cualificaciones'],
    'sidebar.sub.workflow_config'=> ['Workflow Config', 'Workflow-Konfiguration', 'Workflowconfiguratie', 'Configuration des flux', 'Configuración de flujo'],
    'sidebar.sub.address_book'   => ['Address Book', 'Adressbuch', 'Adresboek', 'Carnet d’adresses', 'Libreta de direcciones'],
    'sidebar.sub.routing_policy' => ['Routing & Policy', 'Routing & Richtlinien', 'Routing & beleid', 'Routage et politique', 'Enrutamiento y política'],
    'sidebar.sub.voice'          => ['Voice', 'Sprache', 'Spraak', 'Voix', 'Voz'],
    'sidebar.sub.text'           => ['Text', 'Text', 'Tekst', 'Texte', 'Texto'],
    'sidebar.sub.location'       => ['Location', 'Standort', 'Locatie', 'Localisation', 'Ubicación'],
    'sidebar.sub.multi_protocol' => ['Multi-protocol', 'Mehrprotokoll', 'Multi-protocol', 'Multi-protocole', 'Multiprotocolo'],
    'sidebar.sub.integrations'   => ['Integrations', 'Integrationen', 'Integraties', 'Intégrations', 'Integraciones'],
];
foreach ($subs as $key => $vals) {
    _phase36_seed($key, 'sidebar', [
        'en' => $vals[0], 'de' => $vals[1], 'nl' => $vals[2], 'fr' => $vals[3], 'es' => $vals[4],
    ]);
}

// Item labels that didn't previously have keys (most existing tab.* keys reused)
_phase36_seed('sidebar.tab.welcome', 'sidebar', [
    'en' => 'Welcome', 'de' => 'Willkommen', 'nl' => 'Welkom', 'fr' => 'Bienvenue', 'es' => 'Bienvenida',
]);
_phase36_seed('sidebar.tab.database_info', 'sidebar', [
    'en' => 'Database Info', 'de' => 'Datenbankinfo', 'nl' => 'Database-info', 'fr' => 'Info BD', 'es' => 'Info de base de datos',
]);
_phase36_seed('sidebar.tab.backup', 'sidebar', [
    'en' => 'Backup / Maintenance', 'de' => 'Sicherung / Wartung', 'nl' => 'Back-up / onderhoud',
    'fr' => 'Sauvegarde / maintenance', 'es' => 'Copia de seguridad / mantenimiento',
]);
_phase36_seed('sidebar.tab.system_settings', 'sidebar', [
    'en' => 'System Settings', 'de' => 'Systemeinstellungen', 'nl' => 'Systeeminstellingen',
    'fr' => 'Paramètres système', 'es' => 'Ajustes del sistema',
]);
_phase36_seed('sidebar.tab.api_keys', 'sidebar', [
    'en' => 'API Keys', 'de' => 'API-Schlüssel', 'nl' => 'API-sleutels', 'fr' => 'Clés API', 'es' => 'Claves API',
]);
_phase36_seed('sidebar.tab.lookup_services', 'sidebar', [
    'en' => 'Lookup Services', 'de' => 'Nachschlagedienste', 'nl' => 'Opzoekdiensten',
    'fr' => 'Services de recherche', 'es' => 'Servicios de consulta',
]);
_phase36_seed('sidebar.tab.constituents', 'sidebar', [
    'en' => 'Constituents', 'de' => 'Kontakte', 'nl' => 'Contacten', 'fr' => 'Contacts', 'es' => 'Contactos',
]);
_phase36_seed('sidebar.tab.field_help', 'sidebar', [
    'en' => 'Field Help Text', 'de' => 'Feldhilfetexte', 'nl' => 'Veldhulptekst',
    'fr' => 'Aide des champs', 'es' => 'Ayuda de campos',
]);
_phase36_seed('sidebar.tab.location_retention', 'sidebar', [
    'en' => 'Location History Retention', 'de' => 'Standortverlauf-Aufbewahrung',
    'nl' => 'Bewaartermijn locatiegeschiedenis', 'fr' => 'Conservation de l’historique de localisation',
    'es' => 'Retención del historial de ubicación',
]);
_phase36_seed('sidebar.tab.std_messages', 'sidebar', [
    'en' => 'Standard Messages', 'de' => 'Standardnachrichten', 'nl' => 'Standaardberichten',
    'fr' => 'Messages standard', 'es' => 'Mensajes estándar',
]);

echo "\nDone. Inserted: $inserted, Skipped/existing: $skipped.\n";
