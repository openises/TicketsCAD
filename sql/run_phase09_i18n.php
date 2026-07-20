<?php
/**
 * Run Phase 9 i18n — seed captions for the force-password-change UI strings.
 *
 * Adds: banner title, banner body, system-setting toggle label + hint,
 * per-user toggle label, success message reused from existing
 * profile.password_changed key (no new seed needed there).
 *
 * Languages covered: EN + DE + NL + FR + ES.
 *
 * Usage:    php sql/run_phase09_i18n.php
 * Prereqs:  Phase 8 i18n migrations already applied.
 * Safety:   Idempotent. INSERT IGNORE on (caption_key, lang).
 */
require_once __DIR__ . '/../config.php';

echo "Phase 9 i18n — force-password-change captions\n";
echo "=============================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function _phase09_seed(string $key, string $category, array $byLang): void {
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

// ─── Forced-mode banner (profile.php) ─────────────────────────────────────
_phase09_seed('force_pw.banner_title', 'force_pw', [
    'en' => 'Password change required.',
    'de' => 'Passwortänderung erforderlich.',
    'nl' => 'Wachtwoord wijzigen vereist.',
    'fr' => 'Changement de mot de passe requis.',
    'es' => 'Se requiere cambio de contraseña.',
]);
_phase09_seed('force_pw.banner_body', 'force_pw', [
    'en' => 'Your administrator requires you to choose your own password before continuing. Use the form below. Other pages are unavailable until you complete this step.',
    'de' => 'Ihr Administrator verlangt, dass Sie vor dem Fortfahren ein eigenes Passwort wählen. Verwenden Sie das Formular unten. Andere Seiten sind erst nach diesem Schritt verfügbar.',
    'nl' => 'Uw beheerder vereist dat u eerst zelf een wachtwoord kiest voordat u verdergaat. Gebruik onderstaand formulier. Andere pagina\'s zijn pas beschikbaar nadat u deze stap heeft voltooid.',
    'fr' => 'Votre administrateur exige que vous choisissiez votre propre mot de passe avant de continuer. Utilisez le formulaire ci-dessous. Les autres pages ne sont pas disponibles tant que vous n\'avez pas terminé cette étape.',
    'es' => 'Su administrador requiere que elija su propia contraseña antes de continuar. Use el formulario a continuación. Las demás páginas no estarán disponibles hasta completar este paso.',
]);

// ─── System setting label (settings.php Login Settings panel) ─────────────
_phase09_seed('login_settings.force_pw_default', 'login_settings', [
    'en' => 'All new users must choose their own password on first login',
    'de' => 'Alle neuen Benutzer müssen beim ersten Login ihr eigenes Passwort wählen',
    'nl' => 'Alle nieuwe gebruikers moeten bij de eerste keer inloggen hun eigen wachtwoord kiezen',
    'fr' => 'Tous les nouveaux utilisateurs doivent choisir leur propre mot de passe lors de la première connexion',
    'es' => 'Todos los nuevos usuarios deben elegir su propia contraseña en el primer inicio de sesión',
]);
_phase09_seed('login_settings.force_pw_default_hint', 'login_settings', [
    'en' => 'When you create a new user account, default the per-user "Require password reset at next login" toggle to ON. Admin can still override per-user.',
    'de' => 'Beim Erstellen eines neuen Benutzerkontos wird die Option „Passwort beim nächsten Login zurücksetzen" standardmäßig auf EIN gesetzt. Der Administrator kann dies pro Benutzer überschreiben.',
    'nl' => 'Wanneer u een nieuwe gebruikersaccount aanmaakt, staat de per-gebruiker-schakelaar "Vereis wachtwoordwijziging bij volgende login" standaard op AAN. Beheerder kan dit per gebruiker overschrijven.',
    'fr' => 'Lorsque vous créez un nouveau compte utilisateur, la bascule par utilisateur « Exiger la réinitialisation du mot de passe à la prochaine connexion » est activée par défaut. L\'administrateur peut toujours la modifier par utilisateur.',
    'es' => 'Al crear una nueva cuenta de usuario, el conmutador por usuario "Requerir restablecimiento de contraseña en el próximo inicio de sesión" se establece en ENCENDIDO por defecto. El administrador puede anularlo por usuario.',
]);

// ─── Per-user toggle label (settings.php User Accounts form) ──────────────
_phase09_seed('useracct.force_pw', 'useracct', [
    'en' => 'Require this user to reset their password at next login',
    'de' => 'Dieser Benutzer muss beim nächsten Login das Passwort zurücksetzen',
    'nl' => 'Vereisen dat deze gebruiker het wachtwoord bij de volgende login wijzigt',
    'fr' => 'Exiger que cet utilisateur réinitialise son mot de passe à la prochaine connexion',
    'es' => 'Requerir que este usuario restablezca su contraseña en el próximo inicio de sesión',
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
