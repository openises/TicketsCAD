<?php
/**
 * Welcome-email batch sender — operates on the CSV produced by
 * a-seed-script.php (or any seed script that writes the
 * same shape: Full Name, Email, Username, TempPassword, Role).
 *
 * Designed so an admin can:
 *   * Smoke-test with one user first (--user=<username>)
 *   * Dry-run the full batch to inspect the rendered emails before
 *     anything actually leaves the host (--dry-run)
 *   * Send the full batch once the dry-run looks right (--all)
 *
 * Sends through the broker's `smtp` channel — config already lives in
 * the `settings` table (email_mode, smtp_host, smtp_user, smtp_pass,
 * email_from, email_from_name). If those are unset the broker will
 * surface a clear error.
 *
 * Usage:
 *   sudo php tools/send_welcome_emails.php --user=eric.osterberg
 *   sudo php tools/send_welcome_emails.php --all --dry-run
 *   sudo php tools/send_welcome_emails.php --all
 *   sudo php tools/send_welcome_emails.php --user=foo --csv=/path/to/other.csv
 *   sudo php tools/send_welcome_emails.php --all --login-url=https://example.com/login.php
 *
 * Defaults:
 *   --csv       latest /etc/ticketscad/seed-credentials-*.csv
 *   --login-url derived from current_origin (or override)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/broker.php';
require_once __DIR__ . '/../inc/channels/smtp.php';

$opts = getopt('', ['user::', 'all', 'dry-run', 'csv::', 'login-url::', 'help']);
if (isset($opts['help'])) {
    echo "Usage: sudo php tools/send_welcome_emails.php --user=<username>  (send to one)\n";
    echo "       sudo php tools/send_welcome_emails.php --all --dry-run    (preview batch)\n";
    echo "       sudo php tools/send_welcome_emails.php --all               (send batch)\n";
    echo "       Optional: --csv=<path> --login-url=<url>\n";
    exit(0);
}

// ─── Resolve CSV path ──────────────────────────────────────────
$csvPath = $opts['csv'] ?? null;
if ($csvPath === null) {
    // Latest mtime in /etc/ticketscad/seed-credentials-*.csv
    $matches = glob('/etc/ticketscad/seed-credentials-*.csv') ?: [];
    if (!$matches) {
        fwrite(STDERR, "no seed-credentials CSV found in /etc/ticketscad/ — pass --csv=<path>\n");
        exit(2);
    }
    usort($matches, function ($a, $b) { return filemtime($b) - filemtime($a); });
    $csvPath = $matches[0];
}
if (!is_readable($csvPath)) {
    fwrite(STDERR, "csv not readable: $csvPath (run as sudo?)\n");
    exit(2);
}

// ─── Resolve login URL ────────────────────────────────────────
$loginUrl = $opts['login-url'] ?? null;
if (!$loginUrl) {
    // Best-effort: read org_url setting, else fall back to hostname.
    try {
        $val = db_fetch_value("SELECT `value` FROM `settings` WHERE `name` = ?", ['org_url']);
        if ($val) { $loginUrl = rtrim($val, '/') . '/login.php'; }
    } catch (Exception $e) { /* ignore */ }
}
if (!$loginUrl) {
    $loginUrl = 'https://' . trim(gethostname()) . '/login.php';
}

$orgName  = '';
try { $orgName = (string) db_fetch_value("SELECT `value` FROM `settings` WHERE `name` = ?", ['org_name']); }
catch (Exception $e) { /* keep empty */ }
if ($orgName === '') $orgName = 'TicketsCAD';

// ─── Read + filter the CSV ─────────────────────────────────────
$rows = [];
$fh = fopen($csvPath, 'r');
$header = fgetcsv($fh, 0, ',', '"', ''); // skip header
while ($r = fgetcsv($fh, 0, ',', '"', '')) {
    if (count($r) < 5) continue;
    $rows[] = [
        'fullname' => $r[0],
        'email'    => $r[1],
        'username' => $r[2],
        'password' => $r[3],
        'role'     => $r[4],
    ];
}
fclose($fh);

$targetUser = $opts['user'] ?? null;
if ($targetUser !== null) {
    $rows = array_values(array_filter($rows, fn($r) => $r['username'] === $targetUser));
    if (!$rows) {
        fwrite(STDERR, "no row in $csvPath with username='$targetUser'\n");
        exit(3);
    }
} elseif (!isset($opts['all'])) {
    fwrite(STDERR, "must pass --user=<username> or --all\n");
    fwrite(STDERR, "(use --dry-run with --all to preview before sending)\n");
    exit(4);
}

$dryRun = isset($opts['dry-run']);
$mode   = $dryRun ? 'DRY-RUN' : 'LIVE';
echo "[$mode] CSV: $csvPath\n";
echo "[$mode] Login URL: $loginUrl\n";
echo "[$mode] Org name: $orgName\n";
echo "[$mode] Recipients: " . count($rows) . "\n\n";

// ─── Compose + send ───────────────────────────────────────────
$sent = 0; $failed = 0; $skipped = 0;
foreach ($rows as $row) {
    if ($row['email'] === '') { $skipped++; echo "  SKIP {$row['username']}: no email on file\n"; continue; }

    $subject = "Welcome to {$orgName} — your TicketsCAD account is ready";

    $body = <<<HTML
<p>Hi {$row['fullname']},</p>

<p>Your TicketsCAD account has been provisioned for <strong>{$orgName}</strong>. You can sign in at:</p>

<p style="font-size:1.1rem"><a href="{$loginUrl}">{$loginUrl}</a></p>

<p><strong>Username:</strong> <code>{$row['username']}</code><br>
<strong>Temporary password:</strong> <code>{$row['password']}</code><br>
<strong>Role:</strong> {$row['role']}</p>

<p>The system will prompt you to set a new password the first time you sign in. Please pick something memorable to you that no one else would know — TicketsCAD never displays your password back to you and we have no way to recover a forgotten one (only reset it).</p>

<h3 style="margin-top:1.5rem">A few first-time tips</h3>
<ul>
  <li>The <strong>Help</strong> page in the top navigation has a quick keyboard-shortcut reference.</li>
  <li>If your role grants it, the radio widget in the upper-right opens the DMR dispatch interface.</li>
  <li>The <strong>Profile</strong> page is where you turn on two-factor authentication. We strongly recommend it.</li>
  <li>If anything looks broken or surprising, reply to this email — Eric monitors it.</li>
</ul>

<p>73,<br>
TicketsCAD bootstrap</p>

<hr>
<p style="color:#888;font-size:0.8rem">This message was sent automatically when your account was created. It is not monitored if you reply, but Eric's address ({$row['email']} group admin) is on the From: line.</p>
HTML;

    if ($dryRun) {
        echo "  ── {$row['username']} <{$row['email']}> ──\n";
        echo "  Subject: $subject\n";
        echo "  Body (first 300 chars): " . substr(strip_tags($body), 0, 300) . "...\n\n";
        continue;
    }

    $result = broker_send('smtp', [
        'to'      => $row['email'],
        'subject' => $subject,
        'body'    => $body,
    ]);

    if (!empty($result['success'])) {
        $sent++;
        echo "  ✓ sent to {$row['email']} ({$row['username']}) — message_id={$result['message_id']}\n";
    } else {
        $failed++;
        echo "  ✗ FAILED {$row['email']} ({$row['username']}): " . ($result['error'] ?? 'unknown') . "\n";
    }

    // Polite spacing — Gmail SMTP doesn't strictly need it but avoids
    // tripping over a per-second rate limit on the larger batches.
    if (!$targetUser) usleep(500_000);
}

echo "\n[$mode] summary: $sent sent, $failed failed, $skipped skipped\n";
