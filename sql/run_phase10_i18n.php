<?php
/**
 * Run Phase 10 i18n — captions for the CJIS hardening package.
 *
 * Adds ~30 caption keys × 5 languages = ~150 rows covering:
 *   - Password Policy section in Login Settings
 *   - Admin Password Reset form (reason field)
 *   - Rotation reminder banner
 *   - Security Compliance Dashboard
 *   - Sidebar entry
 *
 * Usage:    php sql/run_phase10_i18n.php
 * Safety:   Idempotent (INSERT IGNORE on caption_key+lang unique key).
 */
require_once __DIR__ . '/../config.php';

echo "Phase 10 i18n — CJIS captions\n";
echo "=============================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function _phase10_seed(string $key, string $category, array $byLang): void {
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

// ─── Password policy admin UI ─────────────────────────────────────────────
_phase10_seed('pw_policy.section_title', 'pw_policy', [
    'en' => 'Password Policy', 'de' => 'Passwortrichtlinie',
    'nl' => 'Wachtwoordbeleid', 'fr' => 'Politique des mots de passe', 'es' => 'Política de contraseñas',
]);
_phase10_seed('pw_policy.section_blurb', 'pw_policy', [
    'en' => 'CJIS Security Policy v6.0 (aligned with NIST SP 800-63B) recommends: minimum 8 characters, history of ≥10, no forced periodic rotation. See docs/SECURITY-POLICY.md.',
    'de' => 'CJIS Security Policy v6.0 (gemäß NIST SP 800-63B) empfiehlt: mindestens 8 Zeichen, Verlauf von ≥10, keine erzwungene periodische Rotation. Siehe docs/SECURITY-POLICY.md.',
    'nl' => 'CJIS Security Policy v6.0 (afgestemd op NIST SP 800-63B) raadt aan: minimaal 8 tekens, geschiedenis van ≥10, geen verplichte periodieke rotatie. Zie docs/SECURITY-POLICY.md.',
    'fr' => 'La politique de sécurité CJIS v6.0 (alignée sur NIST SP 800-63B) recommande : minimum 8 caractères, historique de ≥10, pas de rotation périodique forcée. Voir docs/SECURITY-POLICY.md.',
    'es' => 'La Política de Seguridad CJIS v6.0 (alineada con NIST SP 800-63B) recomienda: mínimo 8 caracteres, historial de ≥10, sin rotación periódica forzada. Ver docs/SECURITY-POLICY.md.',
]);
_phase10_seed('pw_policy.label_min', 'pw_policy', [
    'en' => 'Minimum length', 'de' => 'Mindestlänge',
    'nl' => 'Minimumlengte', 'fr' => 'Longueur minimale', 'es' => 'Longitud mínima',
]);
_phase10_seed('pw_policy.hint_min', 'pw_policy', [
    'en' => 'CJIS recommended: 8 or more.',
    'de' => 'CJIS empfohlen: 8 oder mehr.',
    'nl' => 'CJIS aanbevolen: 8 of meer.',
    'fr' => 'Recommandé par CJIS : 8 ou plus.',
    'es' => 'CJIS recomendado: 8 o más.',
]);
_phase10_seed('pw_policy.warn_min', 'pw_policy', [
    'en' => 'Below CJIS recommended minimum (8). Does not meet CJIS standards.',
    'de' => 'Unter dem von CJIS empfohlenen Minimum (8). Erfüllt die CJIS-Standards nicht.',
    'nl' => 'Onder het door CJIS aanbevolen minimum (8). Voldoet niet aan CJIS-normen.',
    'fr' => 'En dessous du minimum recommandé par CJIS (8). Ne répond pas aux normes CJIS.',
    'es' => 'Por debajo del mínimo recomendado por CJIS (8). No cumple los estándares CJIS.',
]);
_phase10_seed('pw_policy.label_history', 'pw_policy', [
    'en' => 'History count', 'de' => 'Verlaufsanzahl',
    'nl' => 'Geschiedenisaantal', 'fr' => 'Historique', 'es' => 'Cantidad de historial',
]);
_phase10_seed('pw_policy.hint_history', 'pw_policy', [
    'en' => 'Last N passwords retained. CJIS recommended: 10 or more.',
    'de' => 'Letzte N Passwörter werden gespeichert. CJIS empfohlen: 10 oder mehr.',
    'nl' => 'Laatste N wachtwoorden bewaard. CJIS aanbevolen: 10 of meer.',
    'fr' => 'Les N derniers mots de passe sont conservés. CJIS recommande : 10 ou plus.',
    'es' => 'Se retienen las últimas N contraseñas. CJIS recomendado: 10 o más.',
]);
_phase10_seed('pw_policy.warn_history', 'pw_policy', [
    'en' => 'Below CJIS recommended (10). Reuse may go undetected.',
    'de' => 'Unter CJIS-Empfehlung (10). Wiederverwendung könnte unbemerkt bleiben.',
    'nl' => 'Onder CJIS-aanbeveling (10). Hergebruik kan onopgemerkt blijven.',
    'fr' => 'En dessous de la recommandation CJIS (10). La réutilisation pourrait passer inaperçue.',
    'es' => 'Por debajo de lo recomendado por CJIS (10). La reutilización puede pasar desapercibida.',
]);
_phase10_seed('pw_policy.label_rotation_days', 'pw_policy', [
    'en' => 'Rotation reminder (days)', 'de' => 'Rotationserinnerung (Tage)',
    'nl' => 'Rotatieherinnering (dagen)', 'fr' => 'Rappel de rotation (jours)', 'es' => 'Recordatorio de rotación (días)',
]);
_phase10_seed('pw_policy.hint_rotation_days', 'pw_policy', [
    'en' => 'Days before showing the "consider rotating" banner. 0 = disabled.',
    'de' => 'Tage bis zum Anzeigen des Rotationsbanners. 0 = deaktiviert.',
    'nl' => 'Dagen voordat de rotatiebanner verschijnt. 0 = uitgeschakeld.',
    'fr' => 'Jours avant l\'affichage de la bannière de rotation. 0 = désactivé.',
    'es' => 'Días antes de mostrar el banner de rotación. 0 = desactivado.',
]);
_phase10_seed('pw_policy.label_snooze_days', 'pw_policy', [
    'en' => 'Reminder snooze (days)', 'de' => 'Erinnerungs-Pause (Tage)',
    'nl' => 'Herinnering-snooze (dagen)', 'fr' => 'Report de rappel (jours)', 'es' => 'Posponer recordatorio (días)',
]);
_phase10_seed('pw_policy.hint_snooze_days', 'pw_policy', [
    'en' => 'After "Remind Me Later", days before next reminder. 0 = re-prompt every login.',
    'de' => 'Nach „Später erinnern" - Tage bis zur nächsten Erinnerung. 0 = bei jeder Anmeldung neu.',
    'nl' => 'Na "Herinner me later", dagen tot volgende herinnering. 0 = bij elke login opnieuw vragen.',
    'fr' => 'Après « Me rappeler plus tard », jours avant le prochain rappel. 0 = re-demander à chaque connexion.',
    'es' => 'Después de "Recuérdame más tarde", días hasta el próximo recordatorio. 0 = preguntar en cada inicio.',
]);

// ─── Admin password reset form (reason) ──────────────────────────────────
_phase10_seed('admin_reset.section_title', 'admin_reset', [
    'en' => 'Reset User Password', 'de' => 'Benutzerpasswort zurücksetzen',
    'nl' => 'Gebruikerswachtwoord opnieuw instellen', 'fr' => 'Réinitialiser le mot de passe utilisateur',
    'es' => 'Restablecer contraseña de usuario',
]);
_phase10_seed('admin_reset.section_blurb', 'admin_reset', [
    'en' => 'Reset a user\'s password. They will be required to change it on next login. All their active sessions will be terminated. A reason is required for the CJIS audit trail.',
    'de' => 'Setzen Sie das Passwort eines Benutzers zurück. Er muss es bei der nächsten Anmeldung ändern. Alle seine aktiven Sitzungen werden beendet. Eine Begründung ist für den CJIS-Audit-Trail erforderlich.',
    'nl' => 'Stel het wachtwoord van een gebruiker opnieuw in. Hij/zij moet het wijzigen bij de volgende login. Alle actieve sessies worden beëindigd. Een reden is vereist voor het CJIS-auditspoor.',
    'fr' => 'Réinitialisez le mot de passe d\'un utilisateur. Il devra le changer à la prochaine connexion. Toutes ses sessions actives seront terminées. Une raison est requise pour la piste d\'audit CJIS.',
    'es' => 'Restablezca la contraseña de un usuario. Tendrá que cambiarla en el próximo inicio de sesión. Se cerrarán todas sus sesiones activas. Se requiere un motivo para el rastro de auditoría CJIS.',
]);
_phase10_seed('admin_reset.label_user', 'admin_reset', [
    'en' => 'User', 'de' => 'Benutzer', 'nl' => 'Gebruiker', 'fr' => 'Utilisateur', 'es' => 'Usuario',
]);
_phase10_seed('admin_reset.select_user', 'admin_reset', [
    'en' => 'Select a user', 'de' => 'Benutzer auswählen', 'nl' => 'Selecteer een gebruiker',
    'fr' => 'Sélectionner un utilisateur', 'es' => 'Seleccione un usuario',
]);
_phase10_seed('admin_reset.label_new_pw', 'admin_reset', [
    'en' => 'New Password', 'de' => 'Neues Passwort', 'nl' => 'Nieuw wachtwoord',
    'fr' => 'Nouveau mot de passe', 'es' => 'Nueva contraseña',
]);
_phase10_seed('admin_reset.label_reason', 'admin_reset', [
    'en' => 'Reason for reset', 'de' => 'Grund für das Zurücksetzen', 'nl' => 'Reden voor reset',
    'fr' => 'Raison de la réinitialisation', 'es' => 'Motivo del restablecimiento',
]);
_phase10_seed('admin_reset.placeholder_reason', 'admin_reset', [
    'en' => 'E.g., User reported forgotten password during shift change',
    'de' => 'Z. B. Benutzer meldete vergessenes Passwort beim Schichtwechsel',
    'nl' => 'Bijv. Gebruiker meldde vergeten wachtwoord tijdens ploegwisseling',
    'fr' => 'P. ex., L\'utilisateur a signalé un mot de passe oublié pendant le changement de quart',
    'es' => 'Ej. Usuario informó contraseña olvidada durante cambio de turno',
]);
_phase10_seed('admin_reset.btn_submit', 'admin_reset', [
    'en' => 'Reset', 'de' => 'Zurücksetzen', 'nl' => 'Resetten', 'fr' => 'Réinitialiser', 'es' => 'Restablecer',
]);
_phase10_seed('admin_reset.cjis_note', 'admin_reset', [
    'en' => 'CJIS compliance: every admin password reset is logged with a reason. The reset user is forced to change the password on next login.',
    'de' => 'CJIS-Konformität: Jedes Admin-Passwort-Zurücksetzen wird mit Begründung protokolliert. Der zurückgesetzte Benutzer muss das Passwort bei der nächsten Anmeldung ändern.',
    'nl' => 'CJIS-naleving: elke admin-wachtwoordreset wordt met reden gelogd. De gereset gebruiker moet het wachtwoord bij de volgende login wijzigen.',
    'fr' => 'Conformité CJIS : chaque réinitialisation de mot de passe par un admin est journalisée avec une raison. L\'utilisateur réinitialisé doit changer le mot de passe à la prochaine connexion.',
    'es' => 'Cumplimiento CJIS: cada restablecimiento por administrador se registra con un motivo. El usuario debe cambiar la contraseña en el próximo inicio de sesión.',
]);

// ─── Rotation reminder banner ────────────────────────────────────────────
_phase10_seed('pw_rotation.banner_title', 'pw_rotation', [
    'en' => 'Password rotation suggested.',
    'de' => 'Passwortrotation empfohlen.',
    'nl' => 'Wachtwoordrotatie aanbevolen.',
    'fr' => 'Rotation de mot de passe suggérée.',
    'es' => 'Se sugiere rotación de contraseña.',
]);
_phase10_seed('pw_rotation.banner_body', 'pw_rotation', [
    'en' => "You haven't changed your password in %d days.",
    'de' => 'Sie haben Ihr Passwort seit %d Tagen nicht geändert.',
    'nl' => 'U hebt uw wachtwoord al %d dagen niet gewijzigd.',
    'fr' => "Vous n'avez pas changé votre mot de passe depuis %d jours.",
    'es' => 'No ha cambiado su contraseña en %d días.',
]);
_phase10_seed('pw_rotation.btn_change_now', 'pw_rotation', [
    'en' => 'Change Now', 'de' => 'Jetzt ändern', 'nl' => 'Nu wijzigen',
    'fr' => 'Changer maintenant', 'es' => 'Cambiar ahora',
]);
_phase10_seed('pw_rotation.btn_snooze', 'pw_rotation', [
    'en' => 'Remind Me Later', 'de' => 'Später erinnern', 'nl' => 'Herinner me later',
    'fr' => 'Me rappeler plus tard', 'es' => 'Recuérdame más tarde',
]);

// ─── Security Compliance Dashboard ───────────────────────────────────────
_phase10_seed('compliance_dash.title', 'compliance_dash', [
    'en' => 'Security Compliance Dashboard',
    'de' => 'Sicherheits-Konformitätsübersicht',
    'nl' => 'Beveiligingsnaleving Dashboard',
    'fr' => 'Tableau de bord de conformité de sécurité',
    'es' => 'Panel de cumplimiento de seguridad',
]);
_phase10_seed('compliance_dash.intro', 'compliance_dash', [
    'en' => 'Live snapshot of how this install measures against CJIS Security Policy v6.0 (aligned with NIST SP 800-63B). Green badge = meets recommendation; yellow = below; red = significantly below. Click "Refresh" to re-poll.',
    'de' => 'Live-Momentaufnahme, wie diese Installation gegenüber CJIS Security Policy v6.0 (gemäß NIST SP 800-63B) abschneidet. Grünes Abzeichen = erfüllt Empfehlung; gelb = darunter; rot = deutlich darunter. „Aktualisieren" anklicken zum Neuabfragen.',
    'nl' => 'Live momentopname van hoe deze installatie scoort tegen CJIS Security Policy v6.0 (afgestemd op NIST SP 800-63B). Groene badge = voldoet aan aanbeveling; geel = onder; rood = duidelijk onder. Klik "Vernieuwen" om opnieuw op te halen.',
    'fr' => 'Instantané en direct de la conformité de cette installation à la CJIS Security Policy v6.0 (alignée NIST SP 800-63B). Badge vert = respecte la recommandation ; jaune = en dessous ; rouge = nettement en dessous. Cliquer « Actualiser » pour ré-interroger.',
    'es' => 'Vista en tiempo real de cómo se mide esta instalación frente a CJIS Security Policy v6.0 (alineada con NIST SP 800-63B). Insignia verde = cumple recomendación; amarillo = por debajo; rojo = significativamente por debajo. Haga clic en "Actualizar" para volver a sondear.',
]);
_phase10_seed('compliance_dash.btn_policy_doc', 'compliance_dash', [
    'en' => 'Security Policy Doc', 'de' => 'Sicherheitsrichtlinie',
    'nl' => 'Beveiligingsbeleid', 'fr' => 'Document de politique', 'es' => 'Documento de política',
]);

// ─── Sidebar link ────────────────────────────────────────────────────────
_phase10_seed('sidebar.tab.security_compliance', 'sidebar.tab', [
    'en' => 'Security Compliance', 'de' => 'Sicherheits-Konformität',
    'nl' => 'Beveiligingsnaleving', 'fr' => 'Conformité de sécurité', 'es' => 'Cumplimiento de seguridad',
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
