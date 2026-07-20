<?php
/**
 * Run Phase 8f i18n — Per-page caption seeds (multi-language).
 *
 * Purpose:  As Eric retrofits page after page with t() calls, this file
 *           grows with the new caption keys + their 5-language seeds.
 *           Each page section is a clearly marked block; new entries go
 *           in the appropriate section.
 *
 *           Languages covered: EN (default), DE, NL, FR, ES.
 *
 * Usage:    php sql/run_phase08f_i18n.php
 * Prereqs:  run_phase08_i18n.php, run_phase08b_i18n.php, run_phase08e_i18n.php
 * Safety:   Idempotent. INSERT IGNORE on (caption_key, lang).
 */
require_once __DIR__ . '/../config.php';

echo "Phase 8f i18n — Per-page seeds\n";
echo "===============================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

/**
 * Helper: insert a caption row across multiple languages.
 * Pass an associative array of [lang => value]. Category is required.
 */
function _seed(string $key, string $category, array $byLang): void {
    global $prefix, $inserted, $skipped;
    foreach ($byLang as $lang => $value) {
        try {
            $stmt = db_query(
                "INSERT IGNORE INTO `{$prefix}captions_i18n`
                 (caption_key, lang, value, category)
                 VALUES (?, ?, ?, ?)",
                [$key, $lang, $value, $category]
            );
            if ($stmt && $stmt->rowCount() > 0) {
                $inserted++;
            } else {
                $skipped++;
            }
        } catch (Exception $e) {
            echo "[WARN] {$key} ({$lang}): " . $e->getMessage() . "\n";
            $skipped++;
        }
    }
}

$inserted = 0;
$skipped  = 0;

// ═════════════════════════════════════════════════════════════════════════
// new-incident.php — incident creation form
// ═════════════════════════════════════════════════════════════════════════

// Page chrome
_seed('newinc.title', 'newinc', [
    'en' => 'New Incident',
    'de' => 'Neuer Vorfall',
    'nl' => 'Nieuw incident',
    'fr' => 'Nouvel incident',
    'es' => 'Nuevo incidente',
]);
_seed('newinc.btn.cancel', 'newinc', [
    'en' => 'Cancel', 'de' => 'Abbrechen', 'nl' => 'Annuleren', 'fr' => 'Annuler', 'es' => 'Cancelar',
]);
_seed('newinc.btn.reset', 'newinc', [
    'en' => 'Reset', 'de' => 'Zurücksetzen', 'nl' => 'Resetten', 'fr' => 'Réinitialiser', 'es' => 'Restablecer',
]);
_seed('newinc.btn.submit', 'newinc', [
    'en' => 'Submit Incident', 'de' => 'Vorfall einreichen', 'nl' => 'Incident verzenden',
    'fr' => 'Soumettre l\'incident', 'es' => 'Enviar incidente',
]);

// Section headers
_seed('newinc.section.classification', 'newinc', [
    'en' => 'Classification', 'de' => 'Klassifizierung', 'nl' => 'Classificatie',
    'fr' => 'Classification', 'es' => 'Clasificación',
]);
_seed('newinc.section.location', 'newinc', [
    'en' => 'Location', 'de' => 'Ort', 'nl' => 'Locatie', 'fr' => 'Lieu', 'es' => 'Ubicación',
]);
_seed('newinc.section.contact', 'newinc', [
    'en' => 'Caller / Contact', 'de' => 'Anrufer / Kontakt', 'nl' => 'Beller / contact',
    'fr' => 'Appelant / contact', 'es' => 'Llamante / contacto',
]);
_seed('newinc.section.facilities', 'newinc', [
    'en' => 'Facilities', 'de' => 'Einrichtungen', 'nl' => 'Voorzieningen',
    'fr' => 'Installations', 'es' => 'Instalaciones',
]);
_seed('newinc.section.time_status', 'newinc', [
    'en' => 'Time & Status', 'de' => 'Zeit & Status', 'nl' => 'Tijd & status',
    'fr' => 'Heure & statut', 'es' => 'Hora y estado',
]);
_seed('newinc.section.call_history', 'newinc', [
    'en' => 'Call History', 'de' => 'Anrufverlauf', 'nl' => 'Oproepgeschiedenis',
    'fr' => 'Historique des appels', 'es' => 'Historial de llamadas',
]);
_seed('newinc.section.patients', 'newinc', [
    'en' => 'Patients', 'de' => 'Patienten', 'nl' => 'Patiënten', 'fr' => 'Patients', 'es' => 'Pacientes',
]);
_seed('newinc.section.details', 'newinc', [
    'en' => 'Additional Details', 'de' => 'Weitere Details', 'nl' => 'Aanvullende details',
    'fr' => 'Détails supplémentaires', 'es' => 'Detalles adicionales',
]);
_seed('newinc.required_badge', 'newinc', [
    'en' => 'Required', 'de' => 'Erforderlich', 'nl' => 'Verplicht', 'fr' => 'Requis', 'es' => 'Obligatorio',
]);

// Right-column panel headers
_seed('newinc.panel.protocol', 'newinc', [
    'en' => 'Response Protocol', 'de' => 'Einsatzprotokoll', 'nl' => 'Responsprotocol',
    'fr' => 'Protocole d\'intervention', 'es' => 'Protocolo de respuesta',
]);
_seed('newinc.panel.warnings', 'newinc', [
    'en' => 'Location Warnings', 'de' => 'Standortwarnungen', 'nl' => 'Locatie-waarschuwingen',
    'fr' => 'Avertissements de lieu', 'es' => 'Avisos de ubicación',
]);
_seed('newinc.panel.map', 'newinc', [
    'en' => 'Location Map', 'de' => 'Standortkarte', 'nl' => 'Locatiekaart',
    'fr' => 'Carte du lieu', 'es' => 'Mapa de ubicación',
]);
_seed('newinc.panel.map_hint', 'newinc', [
    'en' => 'Click to set incident location', 'de' => 'Klicken, um den Vorfallort festzulegen',
    'nl' => 'Klik om de incidentlocatie in te stellen', 'fr' => 'Cliquez pour définir l\'emplacement',
    'es' => 'Haga clic para fijar la ubicación',
]);
_seed('newinc.panel.assign', 'newinc', [
    'en' => 'Assign Responders', 'de' => 'Einsatzkräfte zuweisen', 'nl' => 'Hulpverleners toewijzen',
    'fr' => 'Affecter des intervenants', 'es' => 'Asignar personal de respuesta',
]);
_seed('newinc.panel.selected_count', 'newinc', [
    'en' => 'selected', 'de' => 'ausgewählt', 'nl' => 'geselecteerd', 'fr' => 'sélectionné(s)', 'es' => 'seleccionado(s)',
]);

// Form labels
_seed('newinc.label.incident_type', 'newinc', [
    'en' => 'Incident Type', 'de' => 'Vorfalltyp', 'nl' => 'Incidenttype',
    'fr' => 'Type d\'incident', 'es' => 'Tipo de incidente',
]);
_seed('newinc.label.severity', 'newinc', [
    'en' => 'Severity', 'de' => 'Dringlichkeit', 'nl' => 'Ernst', 'fr' => 'Gravité', 'es' => 'Gravedad',
]);
_seed('newinc.severity.normal', 'newinc', [
    'en' => 'Normal', 'de' => 'Normal', 'nl' => 'Normaal', 'fr' => 'Normale', 'es' => 'Normal',
]);
_seed('newinc.severity.elevated', 'newinc', [
    'en' => 'Elevated', 'de' => 'Erhöht', 'nl' => 'Verhoogd', 'fr' => 'Élevée', 'es' => 'Elevada',
]);
_seed('newinc.severity.critical', 'newinc', [
    'en' => 'Critical', 'de' => 'Kritisch', 'nl' => 'Kritiek', 'fr' => 'Critique', 'es' => 'Crítica',
]);
_seed('newinc.label.scope', 'newinc', [
    'en' => 'Incident Name / Scope', 'de' => 'Vorfallname / Umfang',
    'nl' => 'Incidentnaam / -omvang', 'fr' => 'Nom / portée de l\'incident',
    'es' => 'Nombre / alcance del incidente',
]);
_seed('newinc.placeholder.scope', 'newinc', [
    'en' => 'Brief summary of the incident', 'de' => 'Kurze Zusammenfassung des Vorfalls',
    'nl' => 'Korte samenvatting van het incident', 'fr' => 'Bref résumé de l\'incident',
    'es' => 'Resumen breve del incidente',
]);
_seed('newinc.label.description', 'newinc', [
    'en' => 'Description', 'de' => 'Beschreibung', 'nl' => 'Beschrijving',
    'fr' => 'Description', 'es' => 'Descripción',
]);
_seed('newinc.placeholder.description', 'newinc', [
    'en' => 'Caller\'s report — type the call narrative; Incident Type and Severity will auto-fill on Tab if a pattern matches',
    'de' => 'Bericht des Anrufers — Anruferzählung eingeben; Vorfalltyp und Dringlichkeit werden bei Tab automatisch gefüllt',
    'nl' => 'Melding van de beller — typ het verhaal; type en ernst worden bij Tab automatisch ingevuld',
    'fr' => 'Rapport de l\'appelant — saisissez le récit; le type et la gravité se rempliront automatiquement au Tab',
    'es' => 'Informe del llamante — escriba el relato; el tipo y la gravedad se rellenarán al Tab',
]);
_seed('newinc.label.signal', 'newinc', [
    'en' => 'Signal', 'de' => 'Signal', 'nl' => 'Signaal', 'fr' => 'Signal', 'es' => 'Señal',
]);
_seed('newinc.label.major_incident', 'newinc', [
    'en' => 'Major Incident', 'de' => 'Großschadensereignis', 'nl' => 'Groot incident',
    'fr' => 'Incident majeur', 'es' => 'Incidente mayor',
]);
_seed('newinc.btn.new_major', 'newinc', [
    'en' => 'New', 'de' => 'Neu', 'nl' => 'Nieuw', 'fr' => 'Nouveau', 'es' => 'Nuevo',
]);
_seed('newinc.label.street', 'newinc', [
    'en' => 'Street Address', 'de' => 'Straße', 'nl' => 'Straatadres',
    'fr' => 'Adresse de rue', 'es' => 'Dirección',
]);
_seed('newinc.btn.lookup', 'newinc', [
    'en' => 'Lookup', 'de' => 'Suchen', 'nl' => 'Opzoeken', 'fr' => 'Rechercher', 'es' => 'Buscar',
]);
_seed('newinc.label.city', 'newinc', [
    'en' => 'City', 'de' => 'Stadt', 'nl' => 'Stad', 'fr' => 'Ville', 'es' => 'Ciudad',
]);
_seed('newinc.label.state', 'newinc', [
    'en' => 'State', 'de' => 'Bundesland', 'nl' => 'Provincie', 'fr' => 'État/Région', 'es' => 'Estado',
]);
_seed('newinc.label.zip', 'newinc', [
    'en' => 'Zip', 'de' => 'PLZ', 'nl' => 'Postcode', 'fr' => 'CP', 'es' => 'CP',
]);
_seed('newinc.label.area', 'newinc', [
    'en' => 'Area / About / Cross St', 'de' => 'Gebiet / Nähe / Querstraße',
    'nl' => 'Gebied / Bij / Zijstraat', 'fr' => 'Zone / Près / Rue transversale',
    'es' => 'Zona / Cerca / Calle transversal',
]);
_seed('newinc.show_zip', 'newinc', [
    'en' => 'Show Zip Code', 'de' => 'PLZ anzeigen', 'nl' => 'Postcode tonen',
    'fr' => 'Afficher le code postal', 'es' => 'Mostrar código postal',
]);
_seed('newinc.label.coordinates', 'newinc', [
    'en' => 'Coordinates', 'de' => 'Koordinaten', 'nl' => 'Coördinaten',
    'fr' => 'Coordonnées', 'es' => 'Coordenadas',
]);
_seed('newinc.coords_hint', 'newinc', [
    'en' => 'Click the map or use Lookup to set coordinates',
    'de' => 'Auf die Karte klicken oder Suchen verwenden, um Koordinaten festzulegen',
    'nl' => 'Klik op de kaart of gebruik Opzoeken om coördinaten in te stellen',
    'fr' => 'Cliquez sur la carte ou utilisez Rechercher pour définir les coordonnées',
    'es' => 'Haga clic en el mapa o use Buscar para fijar las coordenadas',
]);
_seed('newinc.label.destination', 'newinc', [
    'en' => 'Destination Address', 'de' => 'Zieladresse', 'nl' => 'Bestemmingsadres',
    'fr' => 'Adresse de destination', 'es' => 'Dirección de destino',
]);
_seed('newinc.placeholder.destination', 'newinc', [
    'en' => 'Transport destination (if applicable)',
    'de' => 'Transportziel (falls zutreffend)',
    'nl' => 'Transportbestemming (indien van toepassing)',
    'fr' => 'Destination du transport (le cas échéant)',
    'es' => 'Destino del transporte (si aplica)',
]);
_seed('newinc.label.reported_by', 'newinc', [
    'en' => 'Reported By', 'de' => 'Gemeldet von', 'nl' => 'Gemeld door',
    'fr' => 'Signalé par', 'es' => 'Reportado por',
]);
_seed('newinc.placeholder.caller_name', 'newinc', [
    'en' => 'Caller name', 'de' => 'Name des Anrufers', 'nl' => 'Naam van beller',
    'fr' => 'Nom de l\'appelant', 'es' => 'Nombre del llamante',
]);
_seed('newinc.label.phone', 'newinc', [
    'en' => 'Phone Number', 'de' => 'Telefonnummer', 'nl' => 'Telefoonnummer',
    'fr' => 'Numéro de téléphone', 'es' => 'Número de teléfono',
]);
_seed('newinc.label.911_notes', 'newinc', [
    'en' => '911 Notes', 'de' => 'Notruf-Notizen', 'nl' => '112-notities',
    'fr' => 'Notes 112', 'es' => 'Notas 112',
]);
_seed('newinc.label.fac_at', 'newinc', [
    'en' => 'Incident at Facility', 'de' => 'Vorfall in Einrichtung',
    'nl' => 'Incident bij voorziening', 'fr' => 'Incident à l\'installation',
    'es' => 'Incidente en instalación',
]);
_seed('newinc.label.fac_recv', 'newinc', [
    'en' => 'Receiving Facility', 'de' => 'Aufnehmende Einrichtung',
    'nl' => 'Ontvangende voorziening', 'fr' => 'Installation d\'accueil',
    'es' => 'Instalación receptora',
]);
_seed('newinc.label.status', 'newinc', [
    'en' => 'Status', 'de' => 'Status', 'nl' => 'Status', 'fr' => 'Statut', 'es' => 'Estado',
]);
_seed('newinc.status.open', 'newinc', [
    'en' => 'Open', 'de' => 'Offen', 'nl' => 'Open', 'fr' => 'Ouvert', 'es' => 'Abierto',
]);
_seed('newinc.status.scheduled', 'newinc', [
    'en' => 'Scheduled', 'de' => 'Geplant', 'nl' => 'Gepland', 'fr' => 'Planifié', 'es' => 'Programado',
]);
_seed('newinc.status.closed', 'newinc', [
    'en' => 'Closed', 'de' => 'Geschlossen', 'nl' => 'Gesloten', 'fr' => 'Fermé', 'es' => 'Cerrado',
]);
_seed('newinc.label.problem_start', 'newinc', [
    'en' => 'Problem Start', 'de' => 'Beginn', 'nl' => 'Begin', 'fr' => 'Début', 'es' => 'Inicio',
]);
_seed('newinc.label.problem_end', 'newinc', [
    'en' => 'Problem End', 'de' => 'Ende', 'nl' => 'Einde', 'fr' => 'Fin', 'es' => 'Fin',
]);
_seed('newinc.label.scheduled_date', 'newinc', [
    'en' => 'Scheduled Date', 'de' => 'Geplantes Datum',
    'nl' => 'Geplande datum', 'fr' => 'Date prévue', 'es' => 'Fecha programada',
]);
_seed('newinc.call_history.hint', 'newinc', [
    'en' => 'Previous calls from this phone number or address',
    'de' => 'Frühere Anrufe von dieser Nummer oder Adresse',
    'nl' => 'Eerdere oproepen van dit nummer of adres',
    'fr' => 'Appels précédents de ce numéro ou adresse',
    'es' => 'Llamadas previas de este número o dirección',
]);
_seed('newinc.call_history.empty', 'newinc', [
    'en' => 'Enter a phone number or address to search call history',
    'de' => 'Telefonnummer oder Adresse für die Suche eingeben',
    'nl' => 'Voer een telefoonnummer of adres in om geschiedenis te zoeken',
    'fr' => 'Saisissez un numéro ou une adresse pour la recherche',
    'es' => 'Introduzca un número o dirección para buscar',
]);
_seed('newinc.btn.search_history', 'newinc', [
    'en' => 'Search History', 'de' => 'Verlauf suchen', 'nl' => 'Geschiedenis zoeken',
    'fr' => 'Historique', 'es' => 'Buscar historial',
]);
_seed('newinc.btn.add_patient', 'newinc', [
    'en' => 'Add Patient', 'de' => 'Patient hinzufügen', 'nl' => 'Patiënt toevoegen',
    'fr' => 'Ajouter un patient', 'es' => 'Añadir paciente',
]);
_seed('newinc.label.affected', 'newinc', [
    'en' => 'Affected Area', 'de' => 'Betroffenes Gebiet',
    'nl' => 'Getroffen gebied', 'fr' => 'Zone affectée', 'es' => 'Zona afectada',
]);
_seed('newinc.placeholder.affected', 'newinc', [
    'en' => 'Description of affected area...',
    'de' => 'Beschreibung des betroffenen Gebiets...',
    'nl' => 'Beschrijving van getroffen gebied...',
    'fr' => 'Description de la zone affectée...',
    'es' => 'Descripción de la zona afectada...',
]);
_seed('newinc.label.comments', 'newinc', [
    'en' => 'Disposition / Comments', 'de' => 'Erledigung / Kommentare',
    'nl' => 'Afhandeling / opmerkingen', 'fr' => 'Décision / commentaires',
    'es' => 'Resolución / comentarios',
]);
_seed('newinc.placeholder.comments', 'newinc', [
    'en' => 'Additional comments or disposition...',
    'de' => 'Weitere Kommentare oder Erledigung...',
    'nl' => 'Aanvullende opmerkingen of afhandeling...',
    'fr' => 'Commentaires supplémentaires...',
    'es' => 'Comentarios adicionales...',
]);
_seed('newinc.option_none', 'newinc', [
    'en' => '— None —', 'de' => '— Keine —', 'nl' => '— Geen —', 'fr' => '— Aucun —', 'es' => '— Ninguno —',
]);
_seed('newinc.option_select_type', 'newinc', [
    'en' => '— Select type —', 'de' => '— Typ wählen —', 'nl' => '— Type selecteren —',
    'fr' => '— Sélectionner un type —', 'es' => '— Seleccionar tipo —',
]);

// ═════════════════════════════════════════════════════════════════════════
// roster.php — Personnel roster page
// ═════════════════════════════════════════════════════════════════════════

_seed('roster.title', 'roster', [
    'en' => 'Personnel Roster', 'de' => 'Personalverzeichnis',
    'nl' => 'Personeelslijst', 'fr' => 'Liste du personnel', 'es' => 'Lista de personal',
]);
_seed('roster.btn.new_member', 'roster', [
    'en' => 'New Member', 'de' => 'Neues Mitglied',
    'nl' => 'Nieuw lid', 'fr' => 'Nouveau membre', 'es' => 'Nuevo miembro',
]);
_seed('roster.btn.print', 'roster', [
    'en' => 'Print', 'de' => 'Drucken', 'nl' => 'Afdrukken', 'fr' => 'Imprimer', 'es' => 'Imprimir',
]);
_seed('roster.search_placeholder', 'roster', [
    'en' => 'Search by name, callsign, phone, email...',
    'de' => 'Suche nach Name, Rufzeichen, Telefon, E-Mail...',
    'nl' => 'Zoek op naam, roepnaam, telefoon, e-mail...',
    'fr' => 'Rechercher par nom, indicatif, téléphone, e-mail...',
    'es' => 'Buscar por nombre, indicativo, teléfono, correo...',
]);
_seed('roster.label.status_prefix', 'roster', [
    'en' => 'Status:', 'de' => 'Status:', 'nl' => 'Status:', 'fr' => 'Statut :', 'es' => 'Estado:',
]);
_seed('roster.filter.search', 'roster', [
    'en' => 'Search name, callsign, email...',
    'de' => 'Suche Name, Rufzeichen, E-Mail...',
    'nl' => 'Zoek naam, roepnaam, e-mail...',
    'fr' => 'Rechercher nom, indicatif, e-mail...',
    'es' => 'Buscar nombre, indicativo, correo...',
]);
_seed('roster.filter.team',     'roster', ['en'=>'Team',      'de'=>'Team',         'nl'=>'Team',           'fr'=>'Équipe',          'es'=>'Equipo']);
_seed('roster.filter.type',     'roster', ['en'=>'Type',      'de'=>'Typ',          'nl'=>'Type',           'fr'=>'Type',            'es'=>'Tipo']);
_seed('roster.filter.status',   'roster', ['en'=>'Status',    'de'=>'Status',       'nl'=>'Status',         'fr'=>'Statut',          'es'=>'Estado']);
_seed('roster.filter.all',      'roster', ['en'=>'All',       'de'=>'Alle',         'nl'=>'Alle',           'fr'=>'Tous',            'es'=>'Todos']);
_seed('roster.col.name',        'roster', ['en'=>'Name',      'de'=>'Name',         'nl'=>'Naam',           'fr'=>'Nom',             'es'=>'Nombre']);
_seed('roster.col.callsign',    'roster', ['en'=>'Callsign',  'de'=>'Rufzeichen',   'nl'=>'Roepnaam',       'fr'=>'Indicatif',       'es'=>'Indicativo']);
_seed('roster.col.type',        'roster', ['en'=>'Type',      'de'=>'Typ',          'nl'=>'Type',           'fr'=>'Type',            'es'=>'Tipo']);
_seed('roster.col.email',       'roster', ['en'=>'Email',     'de'=>'E-Mail',       'nl'=>'E-mail',         'fr'=>'E-mail',          'es'=>'Correo']);
_seed('roster.col.phone',       'roster', ['en'=>'Phone',     'de'=>'Telefon',      'nl'=>'Telefoon',       'fr'=>'Téléphone',       'es'=>'Teléfono']);
_seed('roster.col.teams',       'roster', ['en'=>'Teams',     'de'=>'Teams',        'nl'=>'Teams',          'fr'=>'Équipes',         'es'=>'Equipos']);
_seed('roster.col.status',      'roster', ['en'=>'Status',    'de'=>'Status',       'nl'=>'Status',         'fr'=>'Statut',          'es'=>'Estado']);
_seed('roster.empty',           'roster', [
    'en' => 'No personnel found. Try a different filter or add a new member.',
    'de' => 'Kein Personal gefunden. Filter ändern oder neues Mitglied hinzufügen.',
    'nl' => 'Geen personeel gevonden. Probeer een ander filter of voeg lid toe.',
    'fr' => 'Aucun personnel trouvé. Essayez un autre filtre ou ajoutez un membre.',
    'es' => 'No se encontró personal. Pruebe otro filtro o añada un miembro.',
]);

// ═════════════════════════════════════════════════════════════════════════
// incident-list.php — Incident list view
// ═════════════════════════════════════════════════════════════════════════

_seed('inclist.title',            'inclist', ['en'=>'Incidents',          'de'=>'Vorfälle',         'nl'=>'Incidenten',         'fr'=>'Incidents',          'es'=>'Incidentes']);
_seed('inclist.filter.all',       'inclist', ['en'=>'All',                'de'=>'Alle',             'nl'=>'Alle',               'fr'=>'Tous',               'es'=>'Todos']);
_seed('inclist.filter.open',      'inclist', ['en'=>'Open Only',          'de'=>'Nur offene',       'nl'=>'Alleen open',        'fr'=>'Ouverts seulement',  'es'=>'Sólo abiertos']);
_seed('inclist.filter.closed',    'inclist', ['en'=>'Closed Only',        'de'=>'Nur geschlossen',  'nl'=>'Alleen gesloten',    'fr'=>'Fermés seulement',   'es'=>'Sólo cerrados']);
_seed('inclist.col.id',           'inclist', ['en'=>'ID',                 'de'=>'ID',               'nl'=>'ID',                 'fr'=>'ID',                 'es'=>'ID']);
_seed('inclist.col.type',         'inclist', ['en'=>'Type',               'de'=>'Typ',              'nl'=>'Type',               'fr'=>'Type',               'es'=>'Tipo']);
_seed('inclist.col.scope',        'inclist', ['en'=>'Scope',              'de'=>'Umfang',           'nl'=>'Omvang',             'fr'=>'Portée',             'es'=>'Alcance']);
_seed('inclist.col.location',     'inclist', ['en'=>'Location',           'de'=>'Ort',              'nl'=>'Locatie',            'fr'=>'Lieu',               'es'=>'Ubicación']);
_seed('inclist.col.severity',     'inclist', ['en'=>'Sev',                'de'=>'Dring.',           'nl'=>'Ernst',              'fr'=>'Grav.',              'es'=>'Grav.']);
_seed('inclist.col.opened',       'inclist', ['en'=>'Opened',             'de'=>'Eröffnet',         'nl'=>'Geopend',            'fr'=>'Ouvert',             'es'=>'Abierto']);
_seed('inclist.col.status',       'inclist', ['en'=>'Status',             'de'=>'Status',           'nl'=>'Status',             'fr'=>'Statut',             'es'=>'Estado']);
_seed('inclist.empty',            'inclist', ['en'=>'No incidents found.','de'=>'Keine Vorfälle gefunden.','nl'=>'Geen incidenten gevonden.','fr'=>'Aucun incident trouvé.','es'=>'No se encontraron incidentes.']);
_seed('inclist.auto_refresh',     'inclist', ['en'=>'Auto-refresh',       'de'=>'Auto-Aktualisierung','nl'=>'Auto-vernieuwen',  'fr'=>'Actualisation auto','es'=>'Actualizar auto']);
_seed('inclist.filter.all_groups','inclist', ['en'=>'All Groups',         'de'=>'Alle Gruppen',     'nl'=>'Alle groepen',       'fr'=>'Tous les groupes',  'es'=>'Todos los grupos']);
_seed('inclist.filter.all_sev',   'inclist', ['en'=>'All Sev',            'de'=>'Alle Dring.',      'nl'=>'Alle ernst',         'fr'=>'Toutes grav.',      'es'=>'Toda grav.']);
_seed('inclist.sev.low',          'inclist', ['en'=>'Low',                'de'=>'Niedrig',          'nl'=>'Laag',               'fr'=>'Faible',            'es'=>'Baja']);
_seed('inclist.sev.medium',       'inclist', ['en'=>'Medium',             'de'=>'Mittel',           'nl'=>'Gemiddeld',          'fr'=>'Moyenne',           'es'=>'Media']);
_seed('inclist.sev.high',         'inclist', ['en'=>'High',               'de'=>'Hoch',             'nl'=>'Hoog',               'fr'=>'Élevée',            'es'=>'Alta']);
_seed('inclist.col.updated',      'inclist', ['en'=>'Updated',            'de'=>'Aktualisiert',     'nl'=>'Bijgewerkt',         'fr'=>'Mis à jour',        'es'=>'Actualizado']);

// ═════════════════════════════════════════════════════════════════════════
// incident-detail.php — Incident detail view
// ═════════════════════════════════════════════════════════════════════════

_seed('incdetail.title',           'incdetail', ['en'=>'Incident',           'de'=>'Vorfall',         'nl'=>'Incident',         'fr'=>'Incident',          'es'=>'Incidente']);
_seed('incdetail.btn.edit',        'incdetail', ['en'=>'Edit Incident',      'de'=>'Vorfall bearbeiten','nl'=>'Incident bewerken','fr'=>'Modifier l\'incident','es'=>'Editar incidente']);
_seed('incdetail.btn.navigate',    'incdetail', ['en'=>'Navigate',           'de'=>'Navigation',      'nl'=>'Navigeren',        'fr'=>'Naviguer',          'es'=>'Navegar']);
_seed('incdetail.btn.ics213',      'incdetail', ['en'=>'Export ICS-213',     'de'=>'ICS-213 exportieren','nl'=>'ICS-213 exporteren','fr'=>'Exporter ICS-213','es'=>'Exportar ICS-213']);
_seed('incdetail.btn.close',       'incdetail', ['en'=>'Close Incident',     'de'=>'Vorfall schließen','nl'=>'Incident sluiten','fr'=>'Fermer l\'incident','es'=>'Cerrar incidente']);
_seed('incdetail.section.actions', 'incdetail', ['en'=>'Action Log',         'de'=>'Aktionsprotokoll','nl'=>'Actie-logboek',   'fr'=>'Journal d\'actions','es'=>'Registro de acciones']);
_seed('incdetail.section.units',   'incdetail', ['en'=>'Assigned Units',     'de'=>'Zugewiesene Einheiten','nl'=>'Toegewezen eenheden','fr'=>'Unités affectées','es'=>'Unidades asignadas']);
_seed('incdetail.section.patients','incdetail', ['en'=>'Patients',           'de'=>'Patienten',       'nl'=>'Patiënten',        'fr'=>'Patients',          'es'=>'Pacientes']);
_seed('incdetail.section.details', 'incdetail', ['en'=>'Incident Details',   'de'=>'Vorfalldetails',  'nl'=>'Incidentdetails',  'fr'=>'Détails de l\'incident','es'=>'Detalles del incidente']);
_seed('incdetail.label.add_note',  'incdetail', ['en'=>'Add a note...',      'de'=>'Notiz hinzufügen...','nl'=>'Notitie toevoegen...','fr'=>'Ajouter une note...','es'=>'Añadir nota...']);
_seed('incdetail.page_title',      'incdetail', ['en'=>'Incident Detail',    'de'=>'Vorfalldetail',    'nl'=>'Incidentdetail',   'fr'=>'Détail de l\'incident','es'=>'Detalle del incidente']);
_seed('incdetail.section.description','incdetail',['en'=>'Description',      'de'=>'Beschreibung',     'nl'=>'Beschrijving',     'fr'=>'Description',       'es'=>'Descripción']);
_seed('incdetail.section.location','incdetail', ['en'=>'Location',           'de'=>'Ort',              'nl'=>'Locatie',          'fr'=>'Lieu',              'es'=>'Ubicación']);
_seed('incdetail.section.contact', 'incdetail', ['en'=>'Caller / Contact',   'de'=>'Anrufer / Kontakt','nl'=>'Beller / contact', 'fr'=>'Appelant / contact','es'=>'Llamante / contacto']);
_seed('incdetail.section.facilities','incdetail',['en'=>'Facilities',        'de'=>'Einrichtungen',    'nl'=>'Voorzieningen',    'fr'=>'Installations',     'es'=>'Instalaciones']);
_seed('incdetail.section.time_status','incdetail',['en'=>'Time & Status',    'de'=>'Zeit & Status',    'nl'=>'Tijd & status',    'fr'=>'Heure & statut',    'es'=>'Hora y estado']);
_seed('incdetail.section.additional','incdetail', ['en'=>'Additional Details','de'=>'Weitere Details', 'nl'=>'Aanvullende details','fr'=>'Détails supplémentaires','es'=>'Detalles adicionales']);
_seed('incdetail.section.protocol','incdetail', ['en'=>'Response Protocol',  'de'=>'Einsatzprotokoll', 'nl'=>'Responsprotocol',  'fr'=>'Protocole d\'intervention','es'=>'Protocolo de respuesta']);
_seed('incdetail.section.map',     'incdetail', ['en'=>'Location Map',       'de'=>'Standortkarte',    'nl'=>'Locatiekaart',     'fr'=>'Carte du lieu',     'es'=>'Mapa de ubicación']);
_seed('incdetail.section.assigned','incdetail', ['en'=>'Assigned Responders','de'=>'Zugewiesene Einsatzkräfte','nl'=>'Toegewezen hulpverleners','fr'=>'Intervenants affectés','es'=>'Personal asignado']);
_seed('incdetail.section.activity','incdetail', ['en'=>'Activity Log',       'de'=>'Aktivitätsprotokoll','nl'=>'Activiteitenlogboek','fr'=>'Journal d\'activité','es'=>'Registro de actividad']);
_seed('incdetail.btn.update',      'incdetail', ['en'=>'Update',             'de'=>'Aktualisieren',    'nl'=>'Bijwerken',        'fr'=>'Mettre à jour',     'es'=>'Actualizar']);
_seed('incdetail.btn.navigate',    'incdetail', ['en'=>'Navigate',           'de'=>'Navigation',       'nl'=>'Navigeren',        'fr'=>'Naviguer',          'es'=>'Navegar']);

// ═════════════════════════════════════════════════════════════════════════
// units.php — Units list page (and headers reused by unit-detail / unit-edit)
// ═════════════════════════════════════════════════════════════════════════

_seed('units.title',          'units', ['en'=>'Units',           'de'=>'Einheiten',     'nl'=>'Eenheden',     'fr'=>'Unités',          'es'=>'Unidades']);
_seed('units.btn.new',        'units', ['en'=>'New Unit',        'de'=>'Neue Einheit',  'nl'=>'Nieuwe eenheid','fr'=>'Nouvelle unité','es'=>'Nueva unidad']);
_seed('units.filter.search',  'units', ['en'=>'Search units...', 'de'=>'Einheiten suchen...', 'nl'=>'Eenheden zoeken...', 'fr'=>'Rechercher des unités...', 'es'=>'Buscar unidades...']);
_seed('units.col.name',       'units', ['en'=>'Name',            'de'=>'Name',          'nl'=>'Naam',         'fr'=>'Nom',             'es'=>'Nombre']);
_seed('units.col.status',     'units', ['en'=>'Status',          'de'=>'Status',        'nl'=>'Status',       'fr'=>'Statut',          'es'=>'Estado']);
_seed('units.col.location',   'units', ['en'=>'Location',        'de'=>'Standort',      'nl'=>'Locatie',      'fr'=>'Emplacement',     'es'=>'Ubicación']);
_seed('units.col.incident',   'units', ['en'=>'Incident',        'de'=>'Vorfall',       'nl'=>'Incident',     'fr'=>'Incident',        'es'=>'Incidente']);
_seed('units.empty',          'units', ['en'=>'No units configured. Click "New Unit" to add one.',
                                         'de'=>'Keine Einheiten konfiguriert. Auf "Neue Einheit" klicken.',
                                         'nl'=>'Geen eenheden geconfigureerd. Klik op "Nieuwe eenheid".',
                                         'fr'=>'Aucune unité configurée. Cliquez sur "Nouvelle unité".',
                                         'es'=>'No hay unidades configuradas. Haga clic en "Nueva unidad".']);

// ═════════════════════════════════════════════════════════════════════════
// facilities.php — Facilities list page
// ═════════════════════════════════════════════════════════════════════════

_seed('facs.title',          'facs', ['en'=>'Facilities',      'de'=>'Einrichtungen','nl'=>'Voorzieningen','fr'=>'Installations','es'=>'Instalaciones']);
_seed('facs.btn.new',        'facs', ['en'=>'New Facility',    'de'=>'Neue Einrichtung','nl'=>'Nieuwe voorziening','fr'=>'Nouvelle installation','es'=>'Nueva instalación']);
_seed('facs.filter.search',  'facs', ['en'=>'Search facilities...','de'=>'Einrichtungen suchen...','nl'=>'Voorzieningen zoeken...','fr'=>'Rechercher des installations...','es'=>'Buscar instalaciones...']);
_seed('facs.col.name',       'facs', ['en'=>'Name',            'de'=>'Name',          'nl'=>'Naam',         'fr'=>'Nom',             'es'=>'Nombre']);
_seed('facs.col.type',       'facs', ['en'=>'Type',            'de'=>'Typ',           'nl'=>'Type',         'fr'=>'Type',            'es'=>'Tipo']);
_seed('facs.col.address',    'facs', ['en'=>'Address',         'de'=>'Adresse',       'nl'=>'Adres',        'fr'=>'Adresse',         'es'=>'Dirección']);
_seed('facs.col.phone',      'facs', ['en'=>'Phone',           'de'=>'Telefon',       'nl'=>'Telefoon',     'fr'=>'Téléphone',       'es'=>'Teléfono']);
_seed('facs.empty',          'facs', ['en'=>'No facilities configured.','de'=>'Keine Einrichtungen konfiguriert.','nl'=>'Geen voorzieningen geconfigureerd.','fr'=>'Aucune installation configurée.','es'=>'No hay instalaciones configuradas.']);
_seed('facs.show_hidden',    'facs', ['en'=>'Show Hidden',     'de'=>'Versteckte anzeigen','nl'=>'Verborgen tonen','fr'=>'Afficher masqués','es'=>'Mostrar ocultos']);

// ═════════════════════════════════════════════════════════════════════════
// Page titles + headers for the remaining high-visibility pages
// (Minimum-viable retrofit: title + h1/h5 + key buttons. Form-field
//  labels inside each page are retrofit-as-needed in follow-up commits.)
// ═════════════════════════════════════════════════════════════════════════

_seed('page.unit_detail_header','page',['en'=>'Unit Detail',    'de'=>'Einheitsdetails',  'nl'=>'Eenheidsdetails',  'fr'=>'Détail de l\'unité',    'es'=>'Detalle de unidad']);
_seed('page.facility_detail_header','page',['en'=>'Facility Detail','de'=>'Einrichtungsdetails','nl'=>'Voorzieningsdetails','fr'=>'Détail de l\'installation','es'=>'Detalle de instalación']);
_seed('page.dispatch_board','page',['en'=>'Dispatch Call Board','de'=>'Einsatztafel',     'nl'=>'Inzetbord',        'fr'=>'Tableau de répartition','es'=>'Tablero de despacho']);
_seed('page.incident_search','page',['en'=>'Incident Search',   'de'=>'Vorfallsuche',     'nl'=>'Incident zoeken',  'fr'=>'Recherche d\'incident', 'es'=>'Búsqueda de incidentes']);
_seed('page.unit_detail',  'page', ['en'=>'Unit',              'de'=>'Einheit',          'nl'=>'Eenheid',          'fr'=>'Unité',                 'es'=>'Unidad']);
_seed('page.unit_edit',    'page', ['en'=>'Edit Unit',         'de'=>'Einheit bearbeiten','nl'=>'Eenheid bewerken','fr'=>'Modifier l\'unité',      'es'=>'Editar unidad']);
_seed('page.facility_detail','page',['en'=>'Facility',         'de'=>'Einrichtung',      'nl'=>'Voorziening',      'fr'=>'Installation',          'es'=>'Instalación']);
_seed('page.facility_edit','page', ['en'=>'Edit Facility',     'de'=>'Einrichtung bearbeiten','nl'=>'Voorziening bewerken','fr'=>'Modifier l\'installation','es'=>'Editar instalación']);
_seed('page.teams',        'page', ['en'=>'Teams',             'de'=>'Teams',            'nl'=>'Teams',            'fr'=>'Équipes',               'es'=>'Equipos']);
_seed('page.scheduling',   'page', ['en'=>'Scheduling',        'de'=>'Dienstplanung',    'nl'=>'Planning',         'fr'=>'Planification',         'es'=>'Programación']);
_seed('page.messaging',    'page', ['en'=>'Messaging',         'de'=>'Nachrichten',      'nl'=>'Berichten',        'fr'=>'Messagerie',            'es'=>'Mensajería']);
_seed('page.callboard',    'page', ['en'=>'Call Board',        'de'=>'Einsatztafel',     'nl'=>'Oproepbord',       'fr'=>'Tableau d\'appels',      'es'=>'Tablero de llamadas']);
_seed('page.constituents', 'page', ['en'=>'Contacts',          'de'=>'Kontakte',         'nl'=>'Contacten',        'fr'=>'Contacts',              'es'=>'Contactos']);
_seed('page.search',       'page', ['en'=>'Search',            'de'=>'Suche',            'nl'=>'Zoeken',           'fr'=>'Recherche',             'es'=>'Buscar']);
_seed('page.sop',          'page', ['en'=>'Standard Operating Procedures','de'=>'Standardarbeitsanweisungen','nl'=>'Standaard procedures','fr'=>'Procédures opérationnelles standard','es'=>'Procedimientos operativos estándar']);
_seed('page.reports',      'page', ['en'=>'Reports',           'de'=>'Berichte',         'nl'=>'Rapporten',        'fr'=>'Rapports',              'es'=>'Informes']);
_seed('page.help',         'page', ['en'=>'Help',              'de'=>'Hilfe',            'nl'=>'Help',             'fr'=>'Aide',                  'es'=>'Ayuda']);
_seed('page.about',        'page', ['en'=>'About',             'de'=>'Über',             'nl'=>'Over',             'fr'=>'À propos',              'es'=>'Acerca de']);
_seed('page.ics_forms',    'page', ['en'=>'ICS Forms',         'de'=>'ICS-Formulare',    'nl'=>'ICS-formulieren',  'fr'=>'Formulaires ICS',       'es'=>'Formularios ICS']);
_seed('page.facility_board','page',['en'=>'Facility Board',    'de'=>'Einrichtungstafel','nl'=>'Voorzieningenbord','fr'=>'Tableau des installations','es'=>'Tablero de instalaciones']);
_seed('page.links',        'page', ['en'=>'Links',             'de'=>'Links',            'nl'=>'Links',            'fr'=>'Liens',                 'es'=>'Enlaces']);
_seed('page.mobile',       'page', ['en'=>'Mobile Unit View',  'de'=>'Mobile Einheit',   'nl'=>'Mobiele eenheid',  'fr'=>'Vue unité mobile',      'es'=>'Vista de unidad móvil']);
_seed('page.quick_start',  'page', ['en'=>'Quick Start',       'de'=>'Schnellstart',     'nl'=>'Snelstart',        'fr'=>'Démarrage rapide',      'es'=>'Inicio rápido']);

// Universal "back to dashboard" link reused across many pages
// (Already exists as profile.back_to_dashboard — included here for cross-page
//  callers; canonical key is profile.back_to_dashboard.)

echo "[OK] Inserted {$inserted} caption rows (skipped {$skipped} existing)\n";

// ─── Report ─────────────────────────────────────────────────────────────
try {
    $rows = db_fetch_all(
        "SELECT lang, COUNT(*) AS n FROM `{$prefix}captions_i18n` GROUP BY lang ORDER BY lang"
    );
    echo "\nFinal caption counts per language:\n";
    foreach ($rows as $r) {
        printf("  %-4s : %d\n", $r['lang'], (int)$r['n']);
    }
} catch (Exception $e) {
    echo "[WARN] report: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
