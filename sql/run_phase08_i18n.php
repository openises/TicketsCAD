<?php
/**
 * Run Phase 8 i18n — seed navbar + sidebar captions in English and German.
 *
 * Purpose:  Adds the new caption_key rows used by Phase 8's navbar + sidebar
 *           retrofit, plus German translations for every key we now use in
 *           the UI shell. Does NOT touch the table schema (run_captions.php
 *           still owns that).
 * Usage:    php sql/run_phase08_i18n.php
 * Prereqs:  config.php with valid DB credentials. captions_i18n table
 *           already exists (created by run_captions.php).
 * Safety:   Idempotent. INSERT IGNORE on the (caption_key, lang) unique key.
 *           Safe to run repeatedly. Re-running will NOT clobber any caption
 *           values the admin has edited via the UI — only un-set rows are
 *           added.
 * Output:   [OK]/[WARN] status; final per-language row counts.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 8 i18n — Seed Captions\n";
echo "============================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ─── New seed data ─────────────────────────────────────────────────────────
// Each row: [caption_key, lang, value, category]
//
// English defaults match the strings hardcoded in inc/navbar.php and
// inc/config-sidebar.php (Phase 8 retrofit). German translations follow.
//
// Adding a new key here: add the English row AND a German row so the
// switcher demo continues to fully work.

$seeds = [
    // ── Navbar — main menu buttons (categories: nav.menu) ─────────────
    ['nav.menu.situation',    'en', 'Situation',     'nav.menu'],
    ['nav.menu.situation',    'de', 'Lage',          'nav.menu'],
    ['nav.menu.new',          'en', 'New',           'nav.menu'],
    ['nav.menu.new',          'de', 'Neu',           'nav.menu'],
    ['nav.menu.units',        'en', 'Units',         'nav.menu'],
    ['nav.menu.units',        'de', 'Einheiten',     'nav.menu'],
    ['nav.menu.facs',         'en', "Fac's",         'nav.menu'],
    ['nav.menu.facs',         'de', 'Einrichtungen', 'nav.menu'],
    ['nav.menu.search',       'en', 'Search',        'nav.menu'],
    ['nav.menu.search',       'de', 'Suche',         'nav.menu'],
    ['nav.menu.personnel',    'en', 'Personnel',     'nav.menu'],
    ['nav.menu.personnel',    'de', 'Personal',      'nav.menu'],
    ['nav.menu.reports',      'en', 'Reports',       'nav.menu'],
    ['nav.menu.reports',      'de', 'Berichte',      'nav.menu'],
    ['nav.menu.config',       'en', 'Config',        'nav.menu'],
    ['nav.menu.config',       'de', 'Konfig.',       'nav.menu'],
    ['nav.menu.sop',          'en', 'SOP',           'nav.menu'],
    ['nav.menu.sop',          'de', 'SOP',           'nav.menu'],
    ['nav.menu.ics',          'en', 'ICS',           'nav.menu'],
    ['nav.menu.ics',          'de', 'ICS',           'nav.menu'],
    ['nav.menu.contacts',     'en', 'Contacts',      'nav.menu'],
    ['nav.menu.contacts',     'de', 'Kontakte',      'nav.menu'],
    ['nav.menu.messages',     'en', 'Messages',      'nav.menu'],
    ['nav.menu.messages',     'de', 'Nachrichten',   'nav.menu'],
    ['nav.menu.links',        'en', 'Links',         'nav.menu'],
    ['nav.menu.links',        'de', 'Links',         'nav.menu'],
    ['nav.menu.full_screen',  'en', 'Full Screen',   'nav.menu'],
    ['nav.menu.full_screen',  'de', 'Vollbild',      'nav.menu'],
    ['nav.menu.board',        'en', 'Board',         'nav.menu'],
    ['nav.menu.board',        'de', 'Tafel',         'nav.menu'],
    ['nav.menu.fac_board',    'en', 'Fac Board',     'nav.menu'],
    ['nav.menu.fac_board',    'de', 'Einrichtungstafel', 'nav.menu'],

    // ── Navbar — Personnel dropdown items (nav.menu) ──────────────────
    ['nav.menu.roster',                 'en', 'Roster',                    'nav.menu'],
    ['nav.menu.roster',                 'de', 'Mitgliederverzeichnis',     'nav.menu'],
    ['nav.menu.teams',                  'en', 'Teams',                     'nav.menu'],
    ['nav.menu.teams',                  'de', 'Teams',                     'nav.menu'],
    ['nav.menu.scheduling',             'en', 'Scheduling',                'nav.menu'],
    ['nav.menu.scheduling',             'de', 'Dienstplanung',             'nav.menu'],
    ['nav.menu.time_approvals',         'en', 'Pending Time Approvals',    'nav.menu'],
    ['nav.menu.time_approvals',         'de', 'Offene Zeitfreigaben',      'nav.menu'],
    ['nav.menu.vehicles',               'en', 'Vehicles',                  'nav.menu'],
    ['nav.menu.vehicles',               'de', 'Fahrzeuge',                 'nav.menu'],
    ['nav.menu.equipment',              'en', 'Equipment',                 'nav.menu'],
    ['nav.menu.equipment',              'de', 'Ausrüstung',                'nav.menu'],
    ['nav.menu.certifications',         'en', 'Certifications',            'nav.menu'],
    ['nav.menu.certifications',         'de', 'Qualifikationen',           'nav.menu'],
    ['nav.menu.ics_positions',          'en', 'ICS Positions',             'nav.menu'],
    ['nav.menu.ics_positions',          'de', 'ICS-Funktionen',            'nav.menu'],
    ['nav.menu.training',               'en', 'Training',                  'nav.menu'],
    ['nav.menu.training',               'de', 'Ausbildung',                'nav.menu'],
    ['nav.menu.member_types',           'en', 'Member Types',              'nav.menu'],
    ['nav.menu.member_types',           'de', 'Mitgliedertypen',           'nav.menu'],
    ['nav.menu.member_statuses',        'en', 'Member Statuses',           'nav.menu'],
    ['nav.menu.member_statuses',        'de', 'Mitgliederstatus',          'nav.menu'],
    ['nav.menu.roles_permissions',      'en', 'Roles & Permissions',       'nav.menu'],
    ['nav.menu.roles_permissions',      'de', 'Rollen & Berechtigungen',   'nav.menu'],

    // ── Navbar — User menu (nav.user) ─────────────────────────────────
    ['nav.user.profile',           'en', 'My Profile',         'nav.user'],
    ['nav.user.profile',           'de', 'Mein Profil',        'nav.user'],
    ['nav.user.change_password',   'en', 'Change Password',    'nav.user'],
    ['nav.user.change_password',   'de', 'Passwort ändern',    'nav.user'],
    ['nav.user.tfa',               'en', 'Two-Factor Auth',    'nav.user'],
    ['nav.user.tfa',               'de', 'Zwei-Faktor-Auth.',  'nav.user'],
    ['nav.user.mobile',            'en', 'Mobile Unit View',   'nav.user'],
    ['nav.user.mobile',            'de', 'Mobile Einheit',     'nav.user'],
    ['nav.user.quick_start',       'en', 'Quick Start',        'nav.user'],
    ['nav.user.quick_start',       'de', 'Schnellstart',       'nav.user'],
    ['nav.user.help',              'en', 'Help',               'nav.user'],
    ['nav.user.help',              'de', 'Hilfe',              'nav.user'],
    ['nav.user.about',             'en', 'About',              'nav.user'],
    ['nav.user.about',             'de', 'Über',               'nav.user'],
    ['nav.user.logout',            'en', 'Log Out',            'nav.user'],
    ['nav.user.logout',            'de', 'Abmelden',           'nav.user'],

    // ── Navbar — switcher labels (nav.title) ──────────────────────────
    ['nav.title.language',         'en', 'Language',           'nav.title'],
    ['nav.title.language',         'de', 'Sprache',            'nav.title'],
    ['nav.title.notifications',    'en', 'Notifications',      'nav.title'],
    ['nav.title.notifications',    'de', 'Benachrichtigungen', 'nav.title'],
    ['nav.title.no_notifications', 'en', 'No recent notifications', 'nav.title'],
    ['nav.title.no_notifications', 'de', 'Keine neuen Benachrichtigungen', 'nav.title'],

    // ── Sidebar — section headers (sidebar.section) ───────────────────
    ['sidebar.section.system',         'en', 'System',              'sidebar.section'],
    ['sidebar.section.system',         'de', 'System',              'sidebar.section'],
    ['sidebar.section.install',        'en', 'Installation',        'sidebar.section'],
    ['sidebar.section.install',        'de', 'Installation',        'sidebar.section'],
    ['sidebar.section.app_prefs',      'en', 'App Preferences',     'sidebar.section'],
    ['sidebar.section.app_prefs',      'de', 'Anwendungsoptionen',  'sidebar.section'],
    ['sidebar.section.users',          'en', 'Users',               'sidebar.section'],
    ['sidebar.section.users',          'de', 'Benutzer',            'sidebar.section'],
    ['sidebar.section.personnel',      'en', 'Personnel',           'sidebar.section'],
    ['sidebar.section.personnel',      'de', 'Personal',            'sidebar.section'],
    ['sidebar.section.comms',          'en', 'Communications',      'sidebar.section'],
    ['sidebar.section.comms',          'de', 'Kommunikation',       'sidebar.section'],
    ['sidebar.section.maps_places',    'en', 'Maps & Places',       'sidebar.section'],
    ['sidebar.section.maps_places',    'de', 'Karten & Orte',       'sidebar.section'],
    ['sidebar.section.tracking',       'en', 'Tracking',            'sidebar.section'],
    ['sidebar.section.tracking',       'de', 'Standortverfolgung',  'sidebar.section'],

    // ── Sidebar — most-visible tabs (sidebar.tab) ─────────────────────
    ['sidebar.tab.user_accounts',  'en', 'User Accounts',     'sidebar.tab'],
    ['sidebar.tab.user_accounts',  'de', 'Benutzerkonten',    'sidebar.tab'],
    ['sidebar.tab.roles_levels',   'en', 'Roles & Levels',    'sidebar.tab'],
    ['sidebar.tab.roles_levels',   'de', 'Rollen & Stufen',   'sidebar.tab'],
    ['sidebar.tab.translations',   'en', 'Translations',      'sidebar.tab'],
    ['sidebar.tab.translations',   'de', 'Übersetzungen',     'sidebar.tab'],
    ['sidebar.tab.languages',      'en', 'Languages',         'sidebar.tab'],
    ['sidebar.tab.languages',      'de', 'Sprachen',          'sidebar.tab'],

    // ── Login page (Phase 8b feature-complete pass) ──────────────────
    ['login.title',                 'en', 'Tickets NewUI',                 'login'],
    ['login.title',                 'de', 'Tickets NewUI',                 'login'],
    ['login.form.username',         'en', 'Username',                      'login'],
    ['login.form.username',         'de', 'Benutzername',                  'login'],
    ['login.form.password',         'en', 'Password',                      'login'],
    ['login.form.password',         'de', 'Passwort',                      'login'],
    ['login.form.theme',            'en', 'Theme',                         'login'],
    ['login.form.theme',            'de', 'Design',                        'login'],
    ['login.form.theme_day',        'en', 'Day',                           'login'],
    ['login.form.theme_day',        'de', 'Tag',                           'login'],
    ['login.form.theme_night',      'en', 'Night',                         'login'],
    ['login.form.theme_night',      'de', 'Nacht',                         'login'],
    ['login.form.language',         'en', 'Language',                      'login'],
    ['login.form.language',         'de', 'Sprache',                       'login'],
    ['login.btn.submit',            'en', 'Log In',                        'login'],
    ['login.btn.submit',            'de', 'Anmelden',                      'login'],
    ['login.err.missing',           'en', 'Please enter both username and password.', 'login'],
    ['login.err.missing',           'de', 'Bitte Benutzername und Passwort eingeben.',  'login'],
    ['login.err.invalid',           'en', 'Invalid username or password.', 'login'],
    ['login.err.invalid',           'de', 'Ungültiger Benutzername oder Passwort.',     'login'],
    ['login.err.locked',            'en', 'Account is locked. Try again later.',         'login'],
    ['login.err.locked',            'de', 'Konto gesperrt. Bitte später erneut versuchen.', 'login'],
    ['login.err.disabled',          'en', 'This account is disabled.',     'login'],
    ['login.err.disabled',          'de', 'Dieses Konto ist deaktiviert.', 'login'],
    ['login.recent_failures',       'en', 'recent failed login attempt(s) on this account.', 'login'],
    ['login.recent_failures',       'de', 'kürzliche fehlgeschlagene Anmeldeversuch(e) bei diesem Konto.', 'login'],

    // ── 2FA verification step ─────────────────────────────────────────
    ['login.tfa.heading',           'en', 'Two-factor authentication required', 'login'],
    ['login.tfa.heading',           'de', 'Zwei-Faktor-Authentifizierung erforderlich', 'login'],
    ['login.tfa.label_auth',        'en', 'Authentication Code',           'login'],
    ['login.tfa.label_auth',        'de', 'Authentifizierungscode',        'login'],
    ['login.tfa.label_backup',      'en', 'Backup Code',                   'login'],
    ['login.tfa.label_backup',      'de', 'Wiederherstellungscode',        'login'],
    ['login.tfa.help_auth',         'en', 'Enter the 6-digit code from your authenticator app.', 'login'],
    ['login.tfa.help_auth',         'de', 'Geben Sie den 6-stelligen Code aus Ihrer Authentifizierungs-App ein.', 'login'],
    ['login.tfa.help_backup',       'en', 'Enter one of your 8-digit backup codes.',             'login'],
    ['login.tfa.help_backup',       'de', 'Geben Sie einen Ihrer 8-stelligen Wiederherstellungscodes ein.', 'login'],
    ['login.tfa.remember',          'en', 'Remember this device for',      'login'],
    ['login.tfa.remember',          'de', 'Dieses Gerät merken für',       'login'],
    ['login.tfa.days',              'en', 'days',                          'login'],
    ['login.tfa.days',              'de', 'Tage',                          'login'],
    ['login.tfa.verify',            'en', 'Verify',                        'login'],
    ['login.tfa.verify',            'de', 'Bestätigen',                    'login'],
    ['login.tfa.use_backup',        'en', 'Use a backup code instead',     'login'],
    ['login.tfa.use_backup',        'de', 'Wiederherstellungscode verwenden', 'login'],
    ['login.tfa.use_auth',          'en', 'Use authenticator app instead', 'login'],
    ['login.tfa.use_auth',          'de', 'Authentifizierungs-App verwenden', 'login'],
    ['login.tfa.back',              'en', 'Back to login',                 'login'],
    ['login.tfa.back',              'de', 'Zurück zur Anmeldung',          'login'],
    ['sidebar.tab.organizations',  'en', 'Organizations',     'sidebar.tab'],
    ['sidebar.tab.organizations',  'de', 'Organisationen',    'sidebar.tab'],
    ['sidebar.tab.members',        'en', 'Members / Personnel', 'sidebar.tab'],
    ['sidebar.tab.members',        'de', 'Mitglieder / Personal', 'sidebar.tab'],
    ['sidebar.tab.audit_log',      'en', 'Audit Log',         'sidebar.tab'],
    ['sidebar.tab.audit_log',      'de', 'Prüfprotokoll',     'sidebar.tab'],
    ['sidebar.tab.wastebasket',    'en', 'Wastebasket',       'sidebar.tab'],
    ['sidebar.tab.wastebasket',    'de', 'Papierkorb',        'sidebar.tab'],
    ['sidebar.tab.system_health',  'en', 'System Health',     'sidebar.tab'],
    ['sidebar.tab.system_health',  'de', 'Systemgesundheit',  'sidebar.tab'],
    ['sidebar.tab.time_check',     'en', 'Time Check',        'sidebar.tab'],
    ['sidebar.tab.time_check',     'de', 'Zeitprüfung',       'sidebar.tab'],
    ['sidebar.tab.import_export',  'en', 'Import / Export',   'sidebar.tab'],
    ['sidebar.tab.import_export',  'de', 'Import / Export',   'sidebar.tab'],
    ['sidebar.tab.incident_types', 'en', 'Incident Types',    'sidebar.tab'],
    ['sidebar.tab.incident_types', 'de', 'Vorfalltypen',      'sidebar.tab'],
    ['sidebar.tab.severity_levels','en', 'Severity Levels',   'sidebar.tab'],
    ['sidebar.tab.severity_levels','de', 'Dringlichkeitsstufen','sidebar.tab'],
    ['sidebar.tab.display_settings','en', 'Display Settings', 'sidebar.tab'],
    ['sidebar.tab.display_settings','de', 'Anzeigeeinstellungen', 'sidebar.tab'],

    // ── German translations for the existing 31 EN seed keys ─────────
    // (so switching language is visibly comprehensive)
    ['nav.dashboard',       'de', 'Übersicht',     'nav'],
    ['nav.incidents',       'de', 'Vorfälle',      'nav'],
    ['nav.new_incident',    'de', 'Neuer Vorfall', 'nav'],
    ['nav.roster',          'de', 'Mitgliederverzeichnis', 'nav'],
    ['nav.teams',           'de', 'Teams',         'nav'],
    ['nav.facilities',      'de', 'Einrichtungen', 'nav'],
    ['nav.equipment',       'de', 'Ausrüstung',    'nav'],
    ['nav.vehicles',        'de', 'Fahrzeuge',     'nav'],
    ['nav.scheduling',      'de', 'Dienstplanung', 'nav'],
    ['nav.search',          'de', 'Suche',         'nav'],
    ['nav.reports',         'de', 'Berichte',      'nav'],
    ['nav.settings',        'de', 'Einstellungen', 'nav'],
    ['nav.logout',          'de', 'Abmelden',      'nav'],
    ['btn.save',            'de', 'Speichern',     'button'],
    ['btn.cancel',          'de', 'Abbrechen',     'button'],
    ['btn.delete',          'de', 'Löschen',       'button'],
    ['btn.edit',            'de', 'Bearbeiten',    'button'],
    ['btn.add',             'de', 'Hinzufügen',    'button'],
    ['btn.close',           'de', 'Schließen',     'button'],
    ['btn.submit',          'de', 'Absenden',      'button'],
    ['form.address',        'de', 'Adresse',       'form'],
    ['form.city',           'de', 'Stadt',         'form'],
    ['form.state',          'de', 'Bundesland',    'form'],
    ['form.zip',            'de', 'Postleitzahl',  'form'],
    ['form.phone',          'de', 'Telefon',       'form'],
    ['form.name',           'de', 'Name',          'form'],
    ['form.description',    'de', 'Beschreibung',  'form'],
    ['form.notes',          'de', 'Notizen',       'form'],
    ['status.open',         'de', 'Offen',         'status'],
    ['status.closed',       'de', 'Geschlossen',   'status'],
    ['status.pending',      'de', 'Ausstehend',    'status'],

    // ── Dashboard (index.php) ─────────────────────────────────────────
    ['dash.stat.open_tickets',          'en', 'Open',              'dash'],
    ['dash.stat.open_tickets',          'de', 'Offen',             'dash'],
    ['dash.stat.in_progress',           'en', 'In Progress',       'dash'],
    ['dash.stat.in_progress',           'de', 'In Bearbeitung',    'dash'],
    ['dash.stat.available_responders',  'en', 'Available',         'dash'],
    ['dash.stat.available_responders',  'de', 'Verfügbar',         'dash'],
    ['dash.stat.closed_today',          'en', 'Closed Today',      'dash'],
    ['dash.stat.closed_today',          'de', 'Heute geschlossen', 'dash'],
    ['dash.stat.unassigned',            'en', 'Unassigned',        'dash'],
    ['dash.stat.unassigned',            'de', 'Nicht zugewiesen',  'dash'],
    ['dash.stat.on_scene',              'en', 'On Scene',          'dash'],
    ['dash.stat.on_scene',              'de', 'Vor Ort',           'dash'],
    ['dash.stat.dispatched',            'en', 'Dispatched',        'dash'],
    ['dash.stat.dispatched',            'de', 'Entsandt',          'dash'],
    ['dash.stat.responding',            'en', 'Responding',        'dash'],
    ['dash.stat.responding',            'de', 'Auf Anfahrt',       'dash'],
    ['dash.stat.avg_dispatch',          'en', 'Avg Dispatch',      'dash'],
    ['dash.stat.avg_dispatch',          'de', 'Ø Entsendung',      'dash'],
    ['dash.toolbar.widgets',            'en', 'Widgets',           'dash'],
    ['dash.toolbar.widgets',            'de', 'Widgets',           'dash'],
    ['dash.widget.statistics',          'en', 'Statistics',        'dash'],
    ['dash.widget.statistics',          'de', 'Statistik',         'dash'],
    ['dash.widget.incidents',           'en', 'Incidents',         'dash'],
    ['dash.widget.incidents',           'de', 'Vorfälle',          'dash'],
    ['dash.widget.responders',          'en', 'Responders',        'dash'],
    ['dash.widget.responders',          'de', 'Einsatzkräfte',     'dash'],
    ['dash.widget.facilities',          'en', 'Facilities',        'dash'],
    ['dash.widget.facilities',          'de', 'Einrichtungen',     'dash'],
    ['dash.widget.controls',            'en', 'Controls',          'dash'],
    ['dash.widget.controls',            'de', 'Steuerung',         'dash'],
    ['dash.widget.comms',               'en', 'Communications',    'dash'],
    ['dash.widget.comms',               'de', 'Kommunikation',     'dash'],
    ['dash.widget.map',                 'en', 'Map',               'dash'],
    ['dash.widget.map',                 'de', 'Karte',             'dash'],
    ['dash.widget.log',                 'en', 'Recent Events',     'dash'],
    ['dash.widget.log',                 'de', 'Letzte Ereignisse', 'dash'],
    ['dash.toolbar.reset',              'en', 'Reset layout to defaults', 'dash'],
    ['dash.toolbar.reset',              'de', 'Layout zurücksetzen',      'dash'],
    ['dash.toolbar.undo',               'en', 'Undo last layout change',  'dash'],
    ['dash.toolbar.undo',               'de', 'Layout-Änderung rückgängig', 'dash'],
    ['dash.toolbar.snapshots',          'en', 'Layout snapshots',         'dash'],
    ['dash.toolbar.snapshots',          'de', 'Layout-Sicherungen',       'dash'],
    ['dash.toolbar.snapshot_save',      'en', 'Save current layout',      'dash'],
    ['dash.toolbar.snapshot_save',      'de', 'Aktuelles Layout speichern', 'dash'],
    ['dash.toolbar.snapshot_name',      'en', 'Snapshot name...',         'dash'],
    ['dash.toolbar.snapshot_name',      'de', 'Name der Sicherung...',    'dash'],
    ['dash.table.units',                'en', 'Units',             'dash'],
    ['dash.table.units',                'de', 'Einheiten',         'dash'],
    ['dash.table.status',               'en', 'Status',            'dash'],
    ['dash.table.status',               'de', 'Status',            'dash'],

    // ── Profile / My Account (profile.php) ────────────────────────────
    ['profile.title',                   'en', 'My Account',          'profile'],
    ['profile.title',                   'de', 'Mein Konto',          'profile'],
    ['profile.back_to_dashboard',       'en', 'Dashboard',           'profile'],
    ['profile.back_to_dashboard',       'de', 'Übersicht',           'profile'],
    ['profile.tab.profile',             'en', 'Profile',             'profile'],
    ['profile.tab.profile',             'de', 'Profil',              'profile'],
    ['profile.tab.password',            'en', 'Change Password',     'profile'],
    ['profile.tab.password',            'de', 'Passwort ändern',     'profile'],
    ['profile.tab.security',            'en', 'Security',            'profile'],
    ['profile.tab.security',            'de', 'Sicherheit',          'profile'],
    ['profile.card.my_profile',         'en', 'My Profile',          'profile'],
    ['profile.card.my_profile',         'de', 'Mein Profil',         'profile'],
    ['profile.label.username',          'en', 'Username',            'profile'],
    ['profile.label.username',          'de', 'Benutzername',        'profile'],
    ['profile.label.display_name',      'en', 'Display Name',        'profile'],
    ['profile.label.display_name',      'de', 'Anzeigename',         'profile'],
    ['profile.label.access_level',      'en', 'Access Level',        'profile'],
    ['profile.label.access_level',      'de', 'Berechtigungsstufe',  'profile'],
    ['profile.label.email',             'en', 'Email',               'profile'],
    ['profile.label.email',             'de', 'E-Mail',              'profile'],
    ['profile.label.phone',             'en', 'Phone',               'profile'],
    ['profile.label.phone',             'de', 'Telefon',             'profile'],
    ['profile.label.callsign',          'en', 'Callsign',            'profile'],
    ['profile.label.callsign',          'de', 'Rufzeichen',          'profile'],
    ['profile.username_locked',         'en', 'Username cannot be changed.',  'profile'],
    ['profile.username_locked',         'de', 'Benutzername kann nicht geändert werden.', 'profile'],
    ['profile.btn.save_profile',        'en', 'Save Profile',        'profile'],
    ['profile.btn.save_profile',        'de', 'Profil speichern',    'profile'],
    ['profile.saved',                   'en', 'Saved!',              'profile'],
    ['profile.saved',                   'de', 'Gespeichert!',        'profile'],
    ['profile.card.display_prefs',      'en', 'Display Preferences', 'profile'],
    ['profile.card.display_prefs',      'de', 'Anzeigeoptionen',     'profile'],
    ['profile.display_prefs.note',      'en', 'These settings are saved per-browser and apply only to this device.', 'profile'],
    ['profile.display_prefs.note',      'de', 'Diese Einstellungen werden pro Browser gespeichert und gelten nur für dieses Gerät.', 'profile'],
    ['profile.btn.save_display_prefs',  'en', 'Save Display Preferences', 'profile'],
    ['profile.btn.save_display_prefs',  'de', 'Anzeigeoptionen speichern', 'profile'],
    ['profile.card.change_password',    'en', 'Change Password',     'profile'],
    ['profile.card.change_password',    'de', 'Passwort ändern',     'profile'],
    ['profile.label.current_password',  'en', 'Current Password',    'profile'],
    ['profile.label.current_password',  'de', 'Aktuelles Passwort',  'profile'],
    ['profile.label.new_password',      'en', 'New Password',        'profile'],
    ['profile.label.new_password',      'de', 'Neues Passwort',      'profile'],
    ['profile.label.confirm_password',  'en', 'Confirm New Password',  'profile'],
    ['profile.label.confirm_password',  'de', 'Neues Passwort bestätigen', 'profile'],
    ['profile.password_min',            'en', 'Minimum 6 characters.',         'profile'],
    ['profile.password_min',            'de', 'Mindestens 6 Zeichen.',          'profile'],
    ['profile.btn.change_password',     'en', 'Change Password',                'profile'],
    ['profile.btn.change_password',     'de', 'Passwort ändern',                'profile'],
    ['profile.password_changed',        'en', 'Password changed successfully.', 'profile'],
    ['profile.password_changed',        'de', 'Passwort erfolgreich geändert.', 'profile'],
    ['profile.password_changed_logout', 'en', 'Other devices have been logged out.', 'profile'],
    ['profile.password_changed_logout', 'de', 'Andere Geräte wurden abgemeldet.',    'profile'],

    // ── Common buttons / actions (reusable across pages) ──────────────
    ['btn.refresh',                     'en', 'Refresh',             'button'],
    ['btn.refresh',                     'de', 'Aktualisieren',       'button'],
    ['btn.search',                      'en', 'Search',              'button'],
    ['btn.search',                      'de', 'Suchen',              'button'],
    ['btn.back',                        'en', 'Back',                'button'],
    ['btn.back',                        'de', 'Zurück',              'button'],
    ['btn.next',                        'en', 'Next',                'button'],
    ['btn.next',                        'de', 'Weiter',              'button'],
    ['btn.yes',                         'en', 'Yes',                 'button'],
    ['btn.yes',                         'de', 'Ja',                  'button'],
    ['btn.no',                          'en', 'No',                  'button'],
    ['btn.no',                          'de', 'Nein',                'button'],
    ['btn.ok',                          'en', 'OK',                  'button'],
    ['btn.ok',                          'de', 'OK',                  'button'],
    ['btn.confirm',                     'en', 'Confirm',             'button'],
    ['btn.confirm',                     'de', 'Bestätigen',          'button'],
    ['common.loading',                  'en', 'Loading...',          'common'],
    ['common.loading',                  'de', 'Wird geladen...',     'common'],
    ['common.error',                    'en', 'Error',               'common'],
    ['common.error',                    'de', 'Fehler',              'common'],
];

$inserted = 0;
$skipped  = 0;
foreach ($seeds as $s) {
    try {
        // INSERT IGNORE: silent skip on duplicate-key conflict
        db_query(
            "INSERT IGNORE INTO `{$prefix}captions_i18n`
             (`caption_key`, `lang`, `value`, `category`)
             VALUES (?, ?, ?, ?)",
            $s
        );
        // Count actual inserts vs skipped duplicates via SELECT
        $row = db_fetch_one(
            "SELECT id FROM `{$prefix}captions_i18n`
             WHERE `caption_key` = ? AND `lang` = ?
             ORDER BY id DESC LIMIT 1",
            [$s[0], $s[1]]
        );
        if ($row) {
            $inserted++;
        } else {
            $skipped++;
        }
    } catch (Exception $e) {
        echo "[WARN] {$s[0]} ({$s[1]}): " . $e->getMessage() . "\n";
        $skipped++;
    }
}
echo "[OK] Seeded {$inserted} caption entries (duplicates skipped: {$skipped})\n";

// ─── Report final counts ───────────────────────────────────────────────────
try {
    $total = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}captions_i18n`");
    $langs = db_fetch_all(
        "SELECT `lang`, COUNT(*) AS cnt
         FROM `{$prefix}captions_i18n`
         GROUP BY `lang`
         ORDER BY `lang`"
    );
    echo "\nTotal captions: {$total}\n";
    foreach ($langs as $l) {
        echo "  {$l['lang']}: {$l['cnt']} entries\n";
    }
} catch (Exception $e) {
    echo "[WARN] Count query failed: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
