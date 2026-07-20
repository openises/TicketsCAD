<?php
/**
 * Docker helper — generate config.php from config.example.php using environment
 * variables. Called once by docker-entrypoint.sh at container start, and ONLY
 * when config.php is absent, so a user-mounted config.php is never overwritten.
 *
 * Uses var_export() for the substituted values so a password containing quotes
 * or backslashes is escaped safely — no shell/sed quoting hazards.
 *
 * Recognised env vars (with container-friendly fallbacks):
 *   NEWUI_DB_HOST  (default: db)     NEWUI_DB_NAME (default: newui)
 *   NEWUI_DB_USER  (default: newui)  NEWUI_DB_PASS (default: newui)
 *   NEWUI_BASE_URL (default: http://localhost:8081)
 */

$root    = __DIR__;
$tplPath = $root . '/config.example.php';
$outPath = $root . '/config.php';

$tpl = @file_get_contents($tplPath);
if ($tpl === false) {
    fwrite(STDERR, "config-gen: cannot read template $tplPath\n");
    exit(1);
}

// Each entry: [exact template line, replacement PHP statement].
$subs = [
    ["\$db_host   = 'localhost';",               '$db_host',  getenv('NEWUI_DB_HOST') ?: 'db'],
    ["\$db_user   = 'newui';",                    '$db_user',  getenv('NEWUI_DB_USER') ?: 'newui'],
    ["\$db_pass   = 'CHANGE-ME';",                '$db_pass',  getenv('NEWUI_DB_PASS') ?: 'newui'],
    ["\$db_name   = 'newui';",                    '$db_name',  getenv('NEWUI_DB_NAME') ?: 'newui'],
    ["\$base_url  = 'https://cad.example.org';",  '$base_url', getenv('NEWUI_BASE_URL') ?: 'http://localhost:8081'],
];

$fatalMissing = false;
foreach ($subs as [$needle, $var, $value]) {
    if (strpos($tpl, $needle) === false) {
        fwrite(STDERR, "config-gen: WARNING — template line not found (config.example.php format changed?): $needle\n");
        // db_host/db_pass are load-bearing; a miss there means a broken container.
        if ($var === '$db_host' || $var === '$db_pass') $fatalMissing = true;
        continue;
    }
    $tpl = str_replace($needle, $var . ' = ' . var_export($value, true) . ';', $tpl);
}

if ($fatalMissing) {
    fwrite(STDERR, "config-gen: aborting — could not inject database host/password.\n");
    exit(1);
}

if (@file_put_contents($outPath, $tpl) === false) {
    fwrite(STDERR, "config-gen: cannot write $outPath\n");
    exit(1);
}

echo "config-gen: wrote config.php (db host=" . (getenv('NEWUI_DB_HOST') ?: 'db')
   . ", db=" . (getenv('NEWUI_DB_NAME') ?: 'newui') . ")\n";
