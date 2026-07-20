<?php
/**
 * Run Phase 8e i18n — Add nl/fr/es to registry + translate all existing keys.
 *
 * Purpose:  Eric asked for "full support across all screens for Dutch,
 *           Spanish, German, French and of course English." Phase 8a-d
 *           shipped EN + DE coverage of the navbar/sidebar/login/dashboard/
 *           profile pages. This migration adds DUTCH (nl), FRENCH (fr),
 *           and SPANISH (es) translations of every existing English seed,
 *           and registers all three in the `languages` table as enabled.
 *
 *           After this runs, the language switcher will offer 5 languages
 *           and every retrofitted page renders correctly in all 5.
 *
 *           NOTE on translation quality: these are Claude-generated
 *           translations. A native speaker should review before this
 *           goes to production; the `Languages` and `Translations` admin
 *           UIs make per-string corrections trivial.
 *
 * Usage:    php sql/run_phase08e_i18n.php
 * Prereqs:  run_phase08_i18n.php and run_phase08b_i18n.php already ran.
 * Safety:   Idempotent. INSERT IGNORE on (caption_key, lang).
 */
require_once __DIR__ . '/../config.php';

echo "Phase 8e i18n — Add Dutch / French / Spanish\n";
echo "============================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ─── 1. Register the new languages ──────────────────────────────────────
$newLangs = [
    // [code, display_name, native_name, sort_order]
    ['nl', 'Dutch',   'Nederlands', 25],
    ['fr', 'French',  'Français',   30],
    ['es', 'Spanish', 'Español',    40],
];

foreach ($newLangs as $L) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}languages`
             (code, display_name, native_name, enabled, is_default, sort_order)
             VALUES (?, ?, ?, 1, 0, ?)",
            [$L[0], $L[1], $L[2], $L[3]]
        );
        echo "[OK] Registered language: {$L[0]} ({$L[2]})\n";
    } catch (Exception $e) {
        echo "[WARN] {$L[0]}: " . $e->getMessage() . "\n";
    }
}

// ─── 2. Translations of every existing EN key ───────────────────────────
// Each row: [caption_key, nl, fr, es, category]
// (We emit 3 INSERTs per row — one per language.)
$translations = [
    // ── buttons ──
    ['btn.add',              'Toevoegen',      'Ajouter',       'Añadir',       'button'],
    ['btn.back',             'Terug',          'Retour',        'Atrás',        'button'],
    ['btn.cancel',           'Annuleren',      'Annuler',       'Cancelar',     'button'],
    ['btn.close',            'Sluiten',        'Fermer',        'Cerrar',       'button'],
    ['btn.confirm',          'Bevestigen',     'Confirmer',     'Confirmar',    'button'],
    ['btn.delete',           'Verwijderen',    'Supprimer',     'Eliminar',     'button'],
    ['btn.edit',             'Bewerken',       'Modifier',      'Editar',       'button'],
    ['btn.next',             'Volgende',       'Suivant',       'Siguiente',    'button'],
    ['btn.no',               'Nee',            'Non',           'No',           'button'],
    ['btn.ok',               'OK',             'OK',            'OK',           'button'],
    ['btn.refresh',          'Vernieuwen',     'Actualiser',    'Actualizar',   'button'],
    ['btn.save',             'Opslaan',        'Enregistrer',   'Guardar',      'button'],
    ['btn.search',           'Zoeken',         'Rechercher',    'Buscar',       'button'],
    ['btn.submit',           'Verzenden',      'Envoyer',       'Enviar',       'button'],
    ['btn.yes',              'Ja',             'Oui',           'Sí',           'button'],

    // ── common ──
    ['common.error',         'Fout',           'Erreur',        'Error',        'common'],
    ['common.loading',       'Bezig met laden...', 'Chargement...', 'Cargando...', 'common'],

    // ── dashboard stats ──
    ['dash.stat.available_responders', 'Beschikbaar',   'Disponible',          'Disponible',       'dash'],
    ['dash.stat.avg_dispatch',         'Gem. uitzending', 'Envoi moyen',       'Despacho prom.',   'dash'],
    ['dash.stat.closed_today',         'Vandaag gesloten', 'Clos aujourd\'hui','Cerrados hoy',     'dash'],
    ['dash.stat.dispatched',           'Uitgezonden',   'Envoyé',              'Despachado',       'dash'],
    ['dash.stat.in_progress',          'In behandeling','En cours',            'En curso',         'dash'],
    ['dash.stat.on_scene',             'Ter plaatse',   'Sur place',           'En el lugar',      'dash'],
    ['dash.stat.open_tickets',         'Open',          'Ouvert',              'Abierto',          'dash'],
    ['dash.stat.responding',           'Onderweg',      'En intervention',     'Respondiendo',     'dash'],
    ['dash.stat.unassigned',           'Niet toegewezen','Non assigné',        'Sin asignar',      'dash'],

    // ── dashboard table headers ──
    ['dash.table.status',    'Status',         'Statut',        'Estado',       'dash'],
    ['dash.table.units',     'Eenheden',       'Unités',        'Unidades',     'dash'],

    // ── dashboard toolbar ──
    ['dash.toolbar.reset',          'Indeling resetten naar standaard', 'Réinitialiser la disposition', 'Restablecer diseño', 'dash'],
    ['dash.toolbar.snapshot_name',  'Naam van momentopname...',  'Nom de l\'instantané...',     'Nombre de la instantánea...', 'dash'],
    ['dash.toolbar.snapshot_save',  'Huidige indeling opslaan',  'Enregistrer la disposition',  'Guardar diseño actual',       'dash'],
    ['dash.toolbar.snapshots',      'Indelings-momentopnamen',   'Instantanés de disposition',  'Instantáneas de diseño',      'dash'],
    ['dash.toolbar.undo',           'Laatste indelingswijziging ongedaan maken', 'Annuler le dernier changement', 'Deshacer último cambio', 'dash'],
    ['dash.toolbar.widgets',        'Widgets',                   'Widgets',                     'Widgets',                     'dash'],

    // ── dashboard widget toggles ──
    ['dash.widget.comms',       'Communicatie',  'Communications', 'Comunicaciones', 'dash'],
    ['dash.widget.controls',    'Bediening',     'Commandes',      'Controles',      'dash'],
    ['dash.widget.facilities',  'Voorzieningen', 'Installations',  'Instalaciones',  'dash'],
    ['dash.widget.incidents',   'Incidenten',    'Incidents',      'Incidentes',     'dash'],
    ['dash.widget.log',         'Recente gebeurtenissen', 'Événements récents', 'Eventos recientes', 'dash'],
    ['dash.widget.map',         'Kaart',         'Carte',          'Mapa',           'dash'],
    ['dash.widget.responders',  'Hulpverleners', 'Intervenants',   'Personal de respuesta', 'dash'],
    ['dash.widget.statistics',  'Statistieken',  'Statistiques',   'Estadísticas',   'dash'],

    // ── forms (legacy seed) ──
    ['form.address',         'Adres',          'Adresse',       'Dirección',    'form'],
    ['form.city',            'Stad',           'Ville',         'Ciudad',       'form'],
    ['form.description',     'Beschrijving',   'Description',   'Descripción',  'form'],
    ['form.name',            'Naam',           'Nom',           'Nombre',       'form'],
    ['form.notes',           'Opmerkingen',    'Notes',         'Notas',        'form'],
    ['form.phone',           'Telefoon',       'Téléphone',     'Teléfono',     'form'],
    ['form.state',           'Provincie',      'État/Région',   'Estado',       'form'],
    ['form.zip',             'Postcode',       'Code postal',   'Código postal','form'],

    // ── login page ──
    ['login.btn.submit',         'Inloggen',                          'Connexion',                         'Iniciar sesión',                    'login'],
    ['login.err.disabled',       'Dit account is uitgeschakeld.',     'Ce compte est désactivé.',          'Esta cuenta está desactivada.',     'login'],
    ['login.err.invalid',        'Ongeldige gebruikersnaam of wachtwoord.', 'Nom d\'utilisateur ou mot de passe invalide.', 'Usuario o contraseña no válidos.', 'login'],
    ['login.err.locked',         'Account vergrendeld. Probeer het later opnieuw.', 'Compte verrouillé. Réessayez plus tard.', 'Cuenta bloqueada. Inténtelo de nuevo más tarde.', 'login'],
    ['login.err.missing',        'Voer zowel gebruikersnaam als wachtwoord in.', 'Veuillez saisir le nom d\'utilisateur et le mot de passe.', 'Por favor, introduzca usuario y contraseña.', 'login'],
    ['login.form.language',      'Taal',                              'Langue',                            'Idioma',                            'login'],
    ['login.form.password',      'Wachtwoord',                        'Mot de passe',                      'Contraseña',                        'login'],
    ['login.form.theme',         'Thema',                             'Thème',                             'Tema',                              'login'],
    ['login.form.theme_day',     'Dag',                               'Jour',                              'Día',                               'login'],
    ['login.form.theme_night',   'Nacht',                             'Nuit',                              'Noche',                             'login'],
    ['login.form.username',      'Gebruikersnaam',                    'Nom d\'utilisateur',                'Usuario',                           'login'],
    ['login.recent_failures',    'recente mislukte inlogpogingen op dit account.', 'tentative(s) de connexion récente(s) échouée(s) sur ce compte.', 'intento(s) de inicio de sesión reciente(s) fallido(s) en esta cuenta.', 'login'],

    // ── login → 2FA ──
    ['login.tfa.back',           'Terug naar inloggen',               'Retour à la connexion',             'Volver al inicio de sesión',        'login'],
    ['login.tfa.days',           'dagen',                             'jours',                             'días',                              'login'],
    ['login.tfa.heading',        'Tweefactor-authenticatie vereist',  'Authentification à deux facteurs requise', 'Se requiere autenticación de dos factores', 'login'],
    ['login.tfa.help_auth',      'Voer de 6-cijferige code in van uw authenticator-app.', 'Entrez le code à 6 chiffres de votre application d\'authentification.', 'Introduzca el código de 6 dígitos de su aplicación de autenticación.', 'login'],
    ['login.tfa.help_backup',    'Voer een van uw 8-cijferige back-upcodes in.', 'Entrez l\'un de vos codes de secours à 8 chiffres.', 'Introduzca uno de sus códigos de respaldo de 8 dígitos.', 'login'],
    ['login.tfa.label_auth',     'Authenticatiecode',                 'Code d\'authentification',          'Código de autenticación',           'login'],
    ['login.tfa.label_backup',   'Back-upcode',                       'Code de secours',                   'Código de respaldo',                'login'],
    ['login.tfa.remember',       'Dit apparaat onthouden voor',       'Mémoriser cet appareil pendant',    'Recordar este dispositivo durante', 'login'],
    ['login.tfa.use_auth',       'Authenticator-app gebruiken',       'Utiliser l\'application d\'authentification', 'Usar aplicación de autenticación', 'login'],
    ['login.tfa.use_backup',     'Back-upcode gebruiken',             'Utiliser un code de secours',       'Usar código de respaldo',           'login'],
    ['login.tfa.verify',         'Verifiëren',                        'Vérifier',                          'Verificar',                         'login'],
    ['login.title',              'Tickets NewUI',                     'Tickets NewUI',                     'Tickets NewUI',                     'login'],

    // ── legacy nav (kept for backward compat) ──
    ['nav.dashboard',        'Overzicht',     'Tableau de bord','Panel',         'nav'],
    ['nav.equipment',        'Uitrusting',    'Équipement',    'Equipo',         'nav'],
    ['nav.facilities',       'Voorzieningen', 'Installations', 'Instalaciones',  'nav'],
    ['nav.incidents',        'Incidenten',    'Incidents',     'Incidentes',     'nav'],
    ['nav.logout',           'Uitloggen',     'Déconnexion',   'Cerrar sesión',  'nav'],
    ['nav.new_incident',     'Nieuw incident','Nouvel incident','Nuevo incidente','nav'],
    ['nav.reports',          'Rapporten',     'Rapports',      'Informes',       'nav'],
    ['nav.roster',           'Lijst',         'Liste',         'Lista',          'nav'],
    ['nav.scheduling',       'Planning',      'Planification', 'Programación',   'nav'],
    ['nav.search',           'Zoeken',        'Rechercher',    'Buscar',         'nav'],
    ['nav.settings',         'Instellingen',  'Paramètres',    'Configuración',  'nav'],
    ['nav.teams',            'Teams',         'Équipes',       'Equipos',        'nav'],
    ['nav.vehicles',         'Voertuigen',    'Véhicules',     'Vehículos',      'nav'],

    // ── navbar main menu ──
    ['nav.menu.board',           'Bord',                 'Tableau',         'Tablero',           'nav.menu'],
    ['nav.menu.certifications',  'Certificaten',         'Certifications',  'Certificaciones',   'nav.menu'],
    ['nav.menu.config',          'Configuratie',         'Config.',         'Config.',           'nav.menu'],
    ['nav.menu.contacts',        'Contacten',            'Contacts',        'Contactos',         'nav.menu'],
    ['nav.menu.equipment',       'Uitrusting',           'Équipement',      'Equipo',            'nav.menu'],
    ['nav.menu.fac_board',       'Voorzieningenbord',    'Tableau install.', 'Tablero instal.',  'nav.menu'],
    ['nav.menu.facs',            'Voorz.',               'Install.',        'Instal.',           'nav.menu'],
    ['nav.menu.full_screen',     'Volledig scherm',      'Plein écran',     'Pantalla completa', 'nav.menu'],
    ['nav.menu.ics',             'ICS',                  'ICS',             'ICS',               'nav.menu'],
    ['nav.menu.ics_positions',   'ICS-functies',         'Postes ICS',      'Posiciones ICS',    'nav.menu'],
    ['nav.menu.links',           'Links',                'Liens',           'Enlaces',           'nav.menu'],
    ['nav.menu.member_statuses', 'Ledenstatus',          'Statuts membres', 'Estados de miembros', 'nav.menu'],
    ['nav.menu.member_types',    'Ledentypen',           'Types de membres','Tipos de miembros', 'nav.menu'],
    ['nav.menu.messages',        'Berichten',            'Messages',        'Mensajes',          'nav.menu'],
    ['nav.menu.new',             'Nieuw',                'Nouveau',         'Nuevo',             'nav.menu'],
    ['nav.menu.personnel',       'Personeel',            'Personnel',       'Personal',          'nav.menu'],
    ['nav.menu.reports',         'Rapporten',            'Rapports',        'Informes',          'nav.menu'],
    ['nav.menu.roles_permissions', 'Rollen & rechten',   'Rôles & autorisations', 'Roles y permisos', 'nav.menu'],
    ['nav.menu.roster',          'Ledenlijst',           'Liste des membres', 'Lista de miembros','nav.menu'],
    ['nav.menu.scheduling',      'Planning',             'Planification',   'Programación',      'nav.menu'],
    ['nav.menu.search',          'Zoeken',               'Rechercher',      'Buscar',            'nav.menu'],
    ['nav.menu.situation',       'Situatie',             'Situation',       'Situación',         'nav.menu'],
    ['nav.menu.sop',             'SOP',                  'SOP',             'SOP',               'nav.menu'],
    ['nav.menu.teams',           'Teams',                'Équipes',         'Equipos',           'nav.menu'],
    ['nav.menu.time_approvals',  'Openstaande tijdgoedkeuringen', 'Validations de temps en attente', 'Aprobaciones de tiempo pendientes', 'nav.menu'],
    ['nav.menu.training',        'Training',             'Formation',       'Formación',         'nav.menu'],
    ['nav.menu.units',           'Eenheden',             'Unités',          'Unidades',          'nav.menu'],
    ['nav.menu.vehicles',        'Voertuigen',           'Véhicules',       'Vehículos',         'nav.menu'],

    // ── navbar titles ──
    ['nav.title.language',           'Taal',                       'Langue',                   'Idioma',                       'nav.title'],
    ['nav.title.no_notifications',   'Geen recente meldingen',     'Aucune notification récente', 'Sin notificaciones recientes', 'nav.title'],
    ['nav.title.notifications',      'Meldingen',                  'Notifications',            'Notificaciones',               'nav.title'],

    // ── navbar user menu ──
    ['nav.user.about',           'Over',                 'À propos',         'Acerca de',           'nav.user'],
    ['nav.user.change_password', 'Wachtwoord wijzigen',  'Changer le mot de passe', 'Cambiar contraseña', 'nav.user'],
    ['nav.user.help',            'Help',                 'Aide',             'Ayuda',               'nav.user'],
    ['nav.user.logout',          'Uitloggen',            'Déconnexion',      'Cerrar sesión',       'nav.user'],
    ['nav.user.mobile',          'Mobiele eenheid',      'Vue unité mobile', 'Vista de unidad móvil','nav.user'],
    ['nav.user.profile',         'Mijn profiel',         'Mon profil',       'Mi perfil',           'nav.user'],
    ['nav.user.quick_start',     'Snelstart',            'Démarrage rapide', 'Inicio rápido',       'nav.user'],
    ['nav.user.tfa',             'Tweefactor-auth.',     'Authentification 2FA', 'Autenticación 2FA','nav.user'],

    // ── profile ──
    ['profile.back_to_dashboard',     'Overzicht',                        'Tableau de bord',                  'Panel',                         'profile'],
    ['profile.btn.change_password',   'Wachtwoord wijzigen',              'Changer le mot de passe',          'Cambiar contraseña',            'profile'],
    ['profile.btn.save_display_prefs','Weergavevoorkeuren opslaan',       'Enregistrer les préférences d\'affichage', 'Guardar preferencias de visualización', 'profile'],
    ['profile.btn.save_profile',      'Profiel opslaan',                  'Enregistrer le profil',            'Guardar perfil',                'profile'],
    ['profile.card.change_password',  'Wachtwoord wijzigen',              'Changer le mot de passe',          'Cambiar contraseña',            'profile'],
    ['profile.card.display_prefs',    'Weergavevoorkeuren',               'Préférences d\'affichage',         'Preferencias de visualización', 'profile'],
    ['profile.card.my_profile',       'Mijn profiel',                     'Mon profil',                       'Mi perfil',                     'profile'],
    ['profile.display_prefs.note',    'Deze instellingen worden per browser opgeslagen en gelden alleen voor dit apparaat.', 'Ces paramètres sont enregistrés par navigateur et ne s\'appliquent qu\'à cet appareil.', 'Estos ajustes se guardan por navegador y se aplican solo a este dispositivo.', 'profile'],
    ['profile.label.access_level',    'Toegangsniveau',                   'Niveau d\'accès',                  'Nivel de acceso',               'profile'],
    ['profile.label.callsign',        'Roepnaam',                         'Indicatif',                        'Indicativo',                    'profile'],
    ['profile.label.confirm_password','Nieuw wachtwoord bevestigen',      'Confirmer le nouveau mot de passe','Confirmar nueva contraseña',    'profile'],
    ['profile.label.current_password','Huidig wachtwoord',                'Mot de passe actuel',              'Contraseña actual',             'profile'],
    ['profile.label.display_name',    'Weergavenaam',                     'Nom affiché',                      'Nombre para mostrar',           'profile'],
    ['profile.label.email',           'E-mail',                           'E-mail',                           'Correo electrónico',            'profile'],
    ['profile.label.new_password',    'Nieuw wachtwoord',                 'Nouveau mot de passe',             'Nueva contraseña',              'profile'],
    ['profile.label.phone',           'Telefoon',                         'Téléphone',                        'Teléfono',                      'profile'],
    ['profile.label.username',        'Gebruikersnaam',                   'Nom d\'utilisateur',               'Nombre de usuario',             'profile'],
    ['profile.password_changed',      'Wachtwoord succesvol gewijzigd.',  'Mot de passe modifié avec succès.','Contraseña cambiada correctamente.', 'profile'],
    ['profile.password_changed_logout','Andere apparaten zijn uitgelogd.','Les autres appareils ont été déconnectés.', 'Otros dispositivos han sido desconectados.', 'profile'],
    ['profile.password_min',          'Minimaal 6 tekens.',               'Minimum 6 caractères.',            'Mínimo 6 caracteres.',          'profile'],
    ['profile.saved',                 'Opgeslagen!',                      'Enregistré!',                      '¡Guardado!',                    'profile'],
    ['profile.tab.password',          'Wachtwoord wijzigen',              'Changer le mot de passe',          'Cambiar contraseña',            'profile'],
    ['profile.tab.profile',           'Profiel',                          'Profil',                           'Perfil',                        'profile'],
    ['profile.tab.security',          'Beveiliging',                      'Sécurité',                         'Seguridad',                     'profile'],
    ['profile.title',                 'Mijn account',                     'Mon compte',                       'Mi cuenta',                     'profile'],
    ['profile.username_locked',       'Gebruikersnaam kan niet worden gewijzigd.', 'Le nom d\'utilisateur ne peut pas être modifié.', 'El nombre de usuario no se puede cambiar.', 'profile'],

    // ── sidebar sections ──
    ['sidebar.section.app_prefs',    'App-voorkeuren',       'Préférences d\'app.',  'Preferencias de la app', 'sidebar.section'],
    ['sidebar.section.comms',        'Communicatie',         'Communications',       'Comunicaciones',         'sidebar.section'],
    ['sidebar.section.install',      'Installatie',          'Installation',         'Instalación',            'sidebar.section'],
    ['sidebar.section.maps_places',  'Kaarten & plaatsen',   'Cartes & lieux',       'Mapas y lugares',        'sidebar.section'],
    ['sidebar.section.personnel',    'Personeel',            'Personnel',            'Personal',               'sidebar.section'],
    ['sidebar.section.system',       'Systeem',              'Système',              'Sistema',                'sidebar.section'],
    ['sidebar.section.tracking',     'Locatievolging',       'Suivi',                'Seguimiento',            'sidebar.section'],
    ['sidebar.section.users',        'Gebruikers',           'Utilisateurs',         'Usuarios',               'sidebar.section'],

    // ── sidebar tabs ──
    ['sidebar.tab.audit_log',        'Auditlogboek',         'Journal d\'audit',     'Registro de auditoría',  'sidebar.tab'],
    ['sidebar.tab.display_settings', 'Weergave-instellingen','Paramètres d\'affichage','Ajustes de visualización','sidebar.tab'],
    ['sidebar.tab.import_export',    'Import / Export',      'Import / Export',      'Importar / Exportar',    'sidebar.tab'],
    ['sidebar.tab.incident_types',   'Incidenttypen',        'Types d\'incident',    'Tipos de incidente',     'sidebar.tab'],
    ['sidebar.tab.languages',        'Talen',                'Langues',              'Idiomas',                'sidebar.tab'],
    ['sidebar.tab.members',          'Leden / Personeel',    'Membres / Personnel',  'Miembros / Personal',    'sidebar.tab'],
    ['sidebar.tab.organizations',    'Organisaties',         'Organisations',        'Organizaciones',         'sidebar.tab'],
    ['sidebar.tab.roles_levels',     'Rollen & niveaus',     'Rôles & niveaux',      'Roles y niveles',        'sidebar.tab'],
    ['sidebar.tab.severity_levels',  'Ernstniveaus',         'Niveaux de gravité',   'Niveles de gravedad',    'sidebar.tab'],
    ['sidebar.tab.system_health',    'Systeemstatus',        'État du système',      'Estado del sistema',     'sidebar.tab'],
    ['sidebar.tab.time_check',       'Tijdcontrole',         'Vérification horaire', 'Comprobación horaria',   'sidebar.tab'],
    ['sidebar.tab.translations',     'Vertalingen',          'Traductions',          'Traducciones',           'sidebar.tab'],
    ['sidebar.tab.user_accounts',    'Gebruikersaccounts',   'Comptes utilisateurs', 'Cuentas de usuario',     'sidebar.tab'],
    ['sidebar.tab.wastebasket',      'Prullenbak',           'Corbeille',            'Papelera',               'sidebar.tab'],

    // ── status ──
    ['status.closed',        'Gesloten',     'Fermé',         'Cerrado',      'status'],
    ['status.open',          'Open',         'Ouvert',        'Abierto',      'status'],
    ['status.pending',       'In afwachting','En attente',    'Pendiente',    'status'],
];

$inserted = 0;
$skipped  = 0;
foreach ($translations as $row) {
    list($key, $nl, $fr, $es, $cat) = $row;
    foreach ([['nl', $nl], ['fr', $fr], ['es', $es]] as $tr) {
        try {
            $stmt = db_query(
                "INSERT IGNORE INTO `{$prefix}captions_i18n`
                 (caption_key, lang, value, category)
                 VALUES (?, ?, ?, ?)",
                [$key, $tr[0], $tr[1], $cat]
            );
            if ($stmt && $stmt->rowCount() > 0) {
                $inserted++;
            } else {
                $skipped++;
            }
        } catch (Exception $e) {
            echo "[WARN] {$key} ({$tr[0]}): " . $e->getMessage() . "\n";
            $skipped++;
        }
    }
}
echo "\n[OK] Inserted {$inserted} translations (skipped {$skipped} existing duplicates)\n";

// ─── 3. Report final counts ─────────────────────────────────────────────
try {
    $rows = db_fetch_all(
        "SELECT lang, COUNT(*) AS n FROM `{$prefix}captions_i18n` GROUP BY lang ORDER BY lang"
    );
    echo "\nFinal caption counts per language:\n";
    foreach ($rows as $r) {
        printf("  %-4s : %d\n", $r['lang'], (int)$r['n']);
    }
    $regRows = db_fetch_all(
        "SELECT code, display_name, native_name, enabled, is_default
         FROM `{$prefix}languages` ORDER BY sort_order, code"
    );
    echo "\nLanguages registry:\n";
    foreach ($regRows as $r) {
        printf("  %-4s | %-12s | %-12s | %s%s\n",
            $r['code'], $r['display_name'], $r['native_name'],
            ((int)$r['enabled']) ? 'enabled' : 'DISABLED',
            ((int)$r['is_default']) ? ' (DEFAULT)' : ''
        );
    }
} catch (Exception $e) {
    echo "[WARN] report: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
