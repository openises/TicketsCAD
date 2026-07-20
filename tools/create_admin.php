<?php
/**
 * Create the first super-admin user on a fresh TicketsCAD NewUI install.
 *
 * NewUI ships without a default admin account on purpose — every
 * install picks its own. This script is the bootstrap.
 *
 * Usage:
 *   sudo -u www-data php tools/create_admin.php --username=admin --email=you@example.com
 *   sudo -u www-data php tools/create_admin.php --username=admin --email=you@example.com --password=ChooseYourOwn
 *
 * Behavior:
 *   - Refuses to create a second admin if one already exists (per-username),
 *     unless --force is passed.
 *   - Generates a random 14-character temp password if --password isn't
 *     supplied (recommended — avoids your shell history capturing the value).
 *   - Hashes via bcrypt cost 12 — same as the rest of the codebase.
 *   - Assigns the user to the Super Admin role (id=1 by convention)
 *     AND sets the legacy user.level=0 column for backwards compatibility.
 *   - Prints username + temp password to stdout. Copy it before the
 *     terminal scrolls — it is not recoverable.
 *
 * Safe to run only on a fresh install or to add a second admin. Does
 * NOT modify existing users' passwords (see "NEVER reset live passwords"
 * rule in the project CLAUDE.md).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$opts = getopt('', ['username:', 'email:', 'password::', 'force', 'help']);
if (isset($opts['help']) || !isset($opts['username']) || !isset($opts['email'])) {
    echo "Usage: php tools/create_admin.php --username=<name> --email=<email> [--password=<pw>] [--force]\n";
    echo "\n";
    echo "  --username  Required. The username they'll sign in with.\n";
    echo "  --email     Required. For account recovery / notifications.\n";
    echo "  --password  Optional. Random 14-char generated if omitted (recommended).\n";
    echo "  --force     Create even if a user with this username already exists\n";
    echo "              (rotates the password — be sure that's what you want).\n";
    echo "\n";
    echo "Standing rule: NEVER use --force to reset a password on a real user\n";
    echo "without their explicit knowledge. See CLAUDE.md.\n";
    exit($opts['help'] ?? false ? 0 : 1);
}

$username = trim((string) $opts['username']);
$email    = trim((string) $opts['email']);
$force    = isset($opts['force']);

if (!preg_match('/^[a-zA-Z0-9._-]{2,32}$/', $username)) {
    fwrite(STDERR, "ERROR: username must be 2-32 chars of [a-zA-Z0-9._-]\n");
    exit(2);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "ERROR: '$email' doesn't look like a valid email address\n");
    exit(2);
}

// Generate or accept the password.
function gen_password(int $len = 14): string {
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}
$tempPw  = (string) ($opts['password'] ?? gen_password(14));
$hash    = password_hash($tempPw, PASSWORD_BCRYPT, ['cost' => 12]);

// Check for existing user.
$prefix = $GLOBALS['db_prefix'] ?? '';
$pdo = db();

// Does the user table have the forced-change column? Older legacy schemas
// may not. We only set must_change_password when the column actually exists,
// so the bootstrap still works on a pre-Phase-9 schema (it just can't force
// the prompt, and we say so honestly in the output below).
$hasMustChange = false;
try {
    $hasMustChange = (bool) $pdo->query(
        "SHOW COLUMNS FROM `{$prefix}user` LIKE 'must_change_password'"
    )->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $hasMustChange = false;
}

$existing = $pdo->prepare("SELECT id, user FROM `{$prefix}user` WHERE user = ?");
$existing->execute([$username]);
$row = $existing->fetch(PDO::FETCH_ASSOC);

if ($row && !$force) {
    fwrite(STDERR, "ERROR: user '$username' already exists (id={$row['id']}).\n");
    fwrite(STDERR, "       Use --force to rotate the password (be cautious — see CLAUDE.md).\n");
    exit(3);
}

if ($row && $force) {
    // Forced rotation also re-arms the first-login change prompt.
    $mcSet = $hasMustChange ? ', must_change_password = 1' : '';
    $pdo->prepare("UPDATE `{$prefix}user` SET passwd = ?, level = 0, can_login = 1, status = 'approved'{$mcSet} WHERE id = ?")
        ->execute([$hash, (int) $row['id']]);
    $userId = (int) $row['id'];
    echo "  [rotate] existing user id={$userId} password rotated, level set to 0\n";
} else {
    // Legacy user table — many NOT NULL columns without defaults. Use the
    // same self-healing-insert pattern documented in CLAUDE.md.
    // Set must_change_password=1 so the admin is forced to choose their own
    // password on first login (the temp/generated one may be in shell history).
    $mcCol = $hasMustChange ? ', `must_change_password`' : '';
    $mcVal = $hasMustChange ? ', 1' : '';
    try {
        $pdo->prepare(
            "INSERT INTO `{$prefix}user`
                (`user`, `passwd`, `email`, `level`, `name_f`, `name_l`,
                 `callsign`, `org`, `status`, `can_login`{$mcCol})
             VALUES (?, ?, ?, 0, '', '', '', 0, 'approved', 1{$mcVal})"
        )->execute([$username, $hash, $email]);
        $userId = (int) $pdo->lastInsertId();
        echo "  [created] user '$username' id={$userId} level=0"
           . ($hasMustChange ? " (must change password on first login)" : "") . "\n";
    } catch (PDOException $e) {
        // If the legacy schema is older, some columns may not exist or
        // need default values. Surface the error rather than silently
        // failing — the admin can manually adjust.
        fwrite(STDERR, "ERROR: insert into user table failed: " . $e->getMessage() . "\n");
        fwrite(STDERR, "       Your schema may need additional column defaults.\n");
        fwrite(STDERR, "       Check the legacy 'field1'-'field65' columns in CLAUDE.md.\n");
        exit(4);
    }
}

// Assign Super Admin role via the RBAC table (modern path).
try {
    $superAdminRoleId = (int) ($pdo->query(
        "SELECT id FROM `{$prefix}roles` WHERE name = 'Super Admin' OR is_super = 1 ORDER BY id LIMIT 1"
    )->fetchColumn() ?: 0);
    if ($superAdminRoleId > 0) {
        $pdo->prepare(
            "INSERT IGNORE INTO `{$prefix}user_roles` (user_id, role_id) VALUES (?, ?)"
        )->execute([$userId, $superAdminRoleId]);
        echo "  [rbac] granted role_id={$superAdminRoleId} (Super Admin)\n";
    } else {
        echo "  [rbac] WARN: no Super Admin role found in roles table — legacy level=0 will still grant admin\n";
    }
} catch (PDOException $e) {
    echo "  [rbac] roles table not present (legacy install?) — legacy level=0 will grant admin: " . $e->getMessage() . "\n";
}

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Admin account ready — copy these credentials NOW\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Username: {$username}\n";
echo "  Password: {$tempPw}\n";
echo "  Email:    {$email}\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo $hasMustChange
    ? "  Bcrypt cost: 12 — first login will prompt to change password.\n"
    : "  Bcrypt cost: 12 — NOTE: schema lacks must_change_password; no first-login prompt.\n";
echo "═══════════════════════════════════════════════════════════════\n";
