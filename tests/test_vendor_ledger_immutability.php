<?php
/**
 * GH#148 (Phase 155) -- the ledger is APPEND-ONLY.
 *
 * "Who is next" and "prove we were fair" both rest on vendor_dispatch_ledger never being edited. This test enforces
 * it three ways:
 *   1. a TOKENIZING scan of api/ inc/ sql/ tools/ finds no UPDATE / DELETE / TRUNCATE / REPLACE aimed at the ledger
 *      (comments are dropped by the tokenizer first, so a docblock that DESCRIBES the rule never trips it; a negative
 *      control proves the scanner really flags each shape),
 *   2. a hard purge of an incident (wb_purge_ticket_children() + deleting the ticket) leaves the dispatch and its ledger
 *      intact and readable through the snapshotted ticket_ref,
 *   3. a company or list that any row refers to can only be retired, never deleted.
 *
 * @requires-db
 * Usage: php tests/test_vendor_ledger_immutability.php
 */
require_once __DIR__ . '/_vendor_fixtures.php';
require_once __DIR__ . '/../inc/wastebasket-write.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

/** Source with comments removed (tokenizer, not a regex over raw text). */
function strip_comments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) continue;
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

/** Statements in $src (comments already removed) that mutate the ledger. */
function ledger_mutations(string $src): array
{
    $found = [];
    $re = '/\b(UPDATE|DELETE|TRUNCATE|REPLACE)\b[^;]{0,200}?vendor_dispatch_ledger/is';
    if (preg_match_all($re, $src, $m)) $found = $m[0];
    return $found;
}

echo "=== GH#148 -- the ledger is append-only ===\n\n";

// ── 1. the scan ──────────────────────────────────────────────
echo "--- tokenizing scan of the application code ---\n";
$root = dirname(__DIR__);
$dirs = ['api', 'inc', 'sql', 'tools'];
$files = [];
foreach ($dirs as $d) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if ($f->isFile() && substr($f->getFilename(), -4) === '.php') $files[] = $f->getPathname(); }
}
foreach (glob($root . '/*.php') ?: [] as $f) $files[] = $f;
t('the scan covers a real file set (sanity floor)', count($files) > 200);

$violations = [];
$mentions = 0;
foreach ($files as $f) {
    $raw = file_get_contents($f);
    if (strpos($raw, 'vendor_dispatch_ledger') === false) continue;
    $mentions++;
    foreach (ledger_mutations(strip_comments($raw)) as $hit) $violations[] = substr($f, strlen($root) + 1) . ': ' . trim(preg_replace('/\s+/', ' ', $hit));
}
t('the ledger table is referenced by application code (so the scan has something to prove)', $mentions >= 3);
t('NO application code UPDATEs, DELETEs, TRUNCATEs or REPLACEs a ledger row', $violations === []);
foreach ($violations as $v) echo "    VIOLATION: $v\n";

// negative controls: the scanner must flag every shape (otherwise the test above proves nothing)
$plant = function ($sql) { return ledger_mutations(strip_comments("<?php\n\$x = \"{$sql}\";\n")); };
t('control: it flags UPDATE', count($plant('UPDATE `{$prefix}vendor_dispatch_ledger` SET reason = ? WHERE id = ?')) === 1);
t('control: it flags DELETE FROM', count($plant('DELETE FROM `{$prefix}vendor_dispatch_ledger` WHERE id = ?')) === 1);
t('control: it flags the multi-table DELETE form', count($plant('DELETE g FROM `{$prefix}vendor_dispatch_ledger` g WHERE 1=1')) === 1);
t('control: it flags TRUNCATE TABLE', count($plant('TRUNCATE TABLE `{$prefix}vendor_dispatch_ledger`')) === 1);
t('control: it flags REPLACE INTO', count($plant('REPLACE INTO `{$prefix}vendor_dispatch_ledger` (id) VALUES (1)')) === 1);
t('control: it does NOT flag the INSERT the application really uses', count($plant('INSERT INTO `{$prefix}vendor_dispatch_ledger` (`dispatch_id`) VALUES (?)')) === 0);
t('control: it does NOT flag a SELECT', count($plant('SELECT * FROM `{$prefix}vendor_dispatch_ledger` WHERE id = ?')) === 0);
t('control: it does NOT flag a comment that merely describes the rule',
    ledger_mutations(strip_comments("<?php\n// never UPDATE or DELETE a vendor_dispatch_ledger row\n/* DELETE FROM vendor_dispatch_ledger */\n")) === []);

// ── 2. a hard purge leaves the history ───────────────────────
echo "\n--- a hard purge of the incident leaves the history ---\n";
$prefix = vf_prefix();
$admin = test_admin_user_id();
$A = vf_actor($admin, 'vf-admin');
vf_session_as($admin, null, 'vf-admin');
register_shutdown_function('vf_cleanup');
if (!vendor_schema_ready()) {
    echo "SKIP: the vendor tables are not installed\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}
vf_set_settings(['vendor_dispatch_enabled' => '1', 'vendor_rotation_mode' => 'round_robin', 'vendor_advance_rule' => 'any_offer',
                 'vendor_allow_override' => '1', 'vendor_override_requires_reason' => '1']);
$TOW = vf_service_type_id('tow');
$tid = vf_ticket();
$ref = incnum_display($tid);
$list = vf_list('VTI list');
$pa = vf_provider('VTI Alpha Towing', [], $A);
$pb = vf_provider('VTI Bravo Towing', [], $A);
vf_members($list, [$pa, $pb], $A);
$o = vendor_dispatch_offer(['ticket_id' => $tid, 'service_type_id' => $TOW, 'list_id' => $list, 'provider_id' => $pa, 'client_head_provider_id' => $pa], $A);
vendor_dispatch_outcome($o['dispatch_id'], $o['event_id'], 'accepted', 20, $A);
$did = $o['dispatch_id'];
$ledgerBefore = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatch_ledger` WHERE `dispatch_id` = ?", [$did]);

wb_purge_ticket_children([$tid]);
db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$tid]);
t('the incident row is really gone', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}ticket` WHERE `id` = ?", [$tid]) === 0);
t('the dispatch header survived the purge', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatches` WHERE `id` = ?", [$did]) === 1);
t('every ledger row survived the purge', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatch_ledger` WHERE `dispatch_id` = ?", [$did]) === $ledgerBefore && $ledgerBefore >= 2);
$h = vendor_history_query(['dispatch_id' => $did]);
t('the history is still readable, labelled by the snapshotted incident number', count($h['events']) === $ledgerBefore
    && $h['events'][0]['ticket_ref'] === $ref);
$orphan = vendor_dispatch_get($did);
$view = vendor_dispatch_view($orphan, $A, true);
t('the dispatch view still renders its reference and timeline', $view['ref'] === $ref . '-T1' && count($view['events']) === $ledgerBefore);

// ── 3. retire, never delete, anything history points at ──────
echo "\n--- referenced companies and lists can only be retired ---\n";
$del = vendor_provider_delete($pa, $A);
t('a company that appears in the ledger cannot be deleted (409 provider_referenced)', $del['ok'] === false && $del['code'] === 'provider_referenced' && $del['http'] === 409);
t('...and is still there', vendor_provider_get($pa) !== null);
$ret = vendor_provider_retire($pa, $A);
t('...but it can be retired, which keeps its history', $ret['ok'] === true && (int) vendor_provider_get($pa)['is_active'] === 0);
t('a company nothing refers to CAN be deleted', ($dd = vendor_provider_delete($pb, $A)) && $dd['ok'] === true && vendor_provider_get($pb) === null);
$ld = vendor_list_delete($list, $A);
t('a list that appears in the history cannot be deleted (409 list_referenced)', $ld['ok'] === false && $ld['code'] === 'list_referenced');
t('...it can be retired', vendor_list_retire($list, $A)['ok'] === true && (int) vendor_list_get($list)['is_active'] === 0);
$fresh = vf_list('VTI never used');
t('a list nothing refers to can be deleted', vendor_list_delete($fresh, $A)['ok'] === true && vendor_list_get($fresh) === null);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
