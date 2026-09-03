<?php
/**
 * test_gh134_webhook_review_fixes.php — GH#134.
 *
 * rjonesbsink posted five code-review observations, then came back and
 * verified each one himself before anyone else looked at them: three were
 * false alarms or already fixed on his own fork, two were real and are
 * fixed here.
 *
 *   1. Per-event webhook subscription checkboxes (settings.php) used
 *      colon-notation ("incident:close") while every event webhook_fire()
 *      actually receives is dot-notation ("incident.closed") -- not even
 *      the same word in that case. The filter match in webhook_fire() is
 *      a strict string comparison with no normalization, so only the "*"
 *      (All Events) checkbox could ever deliver anything; every
 *      individual-event checkbox was silently inert.
 *   2. inc/webhooks.php's SSRF-guard allowlist (webhook_url_allowlist)
 *      had no settings.php field at all -- the only way to populate it
 *      was a direct database write, despite help.php telling admins to
 *      set it in Settings.
 *
 * Both are proven here by driving the REAL webhook_fire() / real
 * _webhook_url_safe() functions against real subscription/setting rows,
 * not by asserting the source text looks right.
 */

$base = realpath(__DIR__ . '/..');
require_once $base . '/config.php';

echo "=== GH#134 — webhook/audit-log review fixes ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. Every checkbox value is a real, correctly-spelled fireable event --\n";
// ─────────────────────────────────────────────────────────────────────────

$settingsSrc = (string) file_get_contents($base . '/settings.php');
if (!preg_match('/id="webhookEventsGrid">(.*?)<\/div>\s*<\/div>\s*<div class="row g-2 mb-2">|id="webhookEventsGrid">(.*?)id="webhookRetryMax"/s', $settingsSrc, $m)) {
    // Fall back to a looser slice: from the grid id to the next major section marker.
    $start = strpos($settingsSrc, 'id="webhookEventsGrid"');
    $end   = strpos($settingsSrc, 'Recent deliveries for selected webhook', $start);
    $grid  = ($start !== false && $end !== false) ? substr($settingsSrc, $start, $end - $start) : '';
} else {
    $grid = $m[1] ?: $m[2];
}
is_true($grid !== '', 'webhook events checkbox grid found in settings.php for extraction');

preg_match_all('/class="form-check-input wh-evt" type="checkbox" value="([^"]+)"/', $grid, $vm);
$checkboxValues = $vm[1] ?? [];
is_true(count($checkboxValues) >= 7, 'at least 7 event checkboxes remain', (string) count($checkboxValues));

is_true(!in_array('system:refresh', $checkboxValues, true),
    'the system:refresh checkbox (an SSE event, not a webhook event) was removed');
foreach ($checkboxValues as $v) {
    if ($v === '*') continue;
    is_true(strpos($v, ':') === false, "checkbox value \"$v\" contains no colon-notation remnant");
}

require_once $base . '/inc/webhooks.php';
// Extract the real event map's values via reflection-free re-derivation:
// call the real function with every (cat,act,target) it's known to
// handle would require duplicating the map, so instead assert against
// the map's own literal values in the source — the SAME technique
// dead_control_audit.php's own baseline files use for this class of
// check, and it still means "these exact strings appear as real
// _audit_to_webhook_event() outputs", not a guess.
$webhooksSrc = (string) file_get_contents($base . '/inc/webhooks.php');
preg_match_all("/=>\\s*'([a-z_]+\\.[a-z_]+)',/", $webhooksSrc, $em);
$realEvents = array_unique($em[1] ?? []);
is_true(count($realEvents) >= 20, 'the real event map yields a healthy number of dot-notation events',
    (string) count($realEvents));

$unmatched = [];
foreach ($checkboxValues as $v) {
    if ($v === '*') continue;
    if (!in_array($v, $realEvents, true)) { $unmatched[] = $v; }
}
is_true($unmatched === [], 'every remaining checkbox value is a real event name from the map',
    implode(', ', $unmatched));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. The real webhook_fire() now matches a subscription using a fixed value --\n";
// ─────────────────────────────────────────────────────────────────────────

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}

// The webhook_url_allowlist live check (section 3 below) MUST run before
// anything in this section: _webhook_url_safe() caches the allowlist in a
// static per-process variable on its OWN first call, and webhook_fire()
// below calls it internally (via _webhook_send()'s SSRF guard) as soon as
// it tries to deliver to the fixed subscription's target_url -- which
// would otherwise populate that cache as EMPTY before section 3 ever gets
// a chance to insert a real allowlist row. Same class of "populated on
// first call, invisible ordering dependency" trap as get_variable()'s own
// documented per-request cache; see the two tests earlier in this session
// (GH#137, and this file's own SELECT-original-value-via-raw-SQL pattern)
// that hit the identical shape.
$allowlistLive = ['ran' => false];
if ($haveDb) {
    $prefix0 = $GLOBALS['db_prefix'] ?? '';
    $originalAllowlist = db_fetch_value(
        "SELECT `value` FROM `{$prefix0}settings` WHERE `name` = 'webhook_url_allowlist'");
    try {
        db_query("DELETE FROM `{$prefix0}settings` WHERE `name` = 'webhook_url_allowlist'");
        db_query("INSERT INTO `{$prefix0}settings` (`name`, `value`) VALUES ('webhook_url_allowlist', ?)",
            ["gh134-test-internal.example.lan"]);

        $allowlistLive['ran'] = true;
        $allowlistLive['allowed'] = _webhook_url_safe('https://sub.gh134-test-internal.example.lan/hook');
        $allowlistLive['still_blocked'] = !_webhook_url_safe('http://10.0.0.5/hook');
    } finally {
        db_query("DELETE FROM `{$prefix0}settings` WHERE `name` = 'webhook_url_allowlist'");
        if ($originalAllowlist !== false && $originalAllowlist !== null && $originalAllowlist !== '') {
            db_query("INSERT INTO `{$prefix0}settings` (`name`, `value`) VALUES ('webhook_url_allowlist', ?)",
                [$originalAllowlist]);
        }
    }
}

if (!$haveDb) {
    echo "SKIP: no database available — the real webhook_fire() checks were not run\n";
} else {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $subIds = [];

    $makeSub = function (string $name, array $filters) use ($prefix, &$subIds) {
        db_query(
            "INSERT INTO `{$prefix}webhook_subscriptions`
                (`name`, `target_url`, `hmac_secret`, `event_filters_json`, `active`, `retry_policy_json`)
             VALUES (?, ?, ?, ?, 1, ?)",
            [$name, 'http://127.0.0.1:1/unreachable-test-endpoint', 'test-secret-' . $name,
             json_encode($filters), json_encode(['max_retries' => 0])]
        );
        $id = (int) db_insert_id();
        $subIds[] = $id;
        return $id;
    };

    try {
        // The FIXED value: a real dot-notation checkbox value.
        $fixedId = $makeSub('gh134_fixed_' . getmypid(), ['incident.closed']);
        // The OLD, BROKEN value: exactly what the pre-fix checkbox used to
        // save. Proves the historical bug, not just today's fix.
        $brokenId = $makeSub('gh134_broken_' . getmypid(), ['incident:close']);

        $before = ['fixed' => 0, 'broken' => 0];
        foreach (['fixed' => $fixedId, 'broken' => $brokenId] as $k => $id) {
            $before[$k] = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}webhook_deliveries` WHERE `subscription_id` = ?", [$id]
            );
        }

        webhook_fire('incident.closed', ['ticket_id' => 999999999, 'scope' => 'GH#134 test']);

        $after = ['fixed' => 0, 'broken' => 0];
        foreach (['fixed' => $fixedId, 'broken' => $brokenId] as $k => $id) {
            $after[$k] = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}webhook_deliveries` WHERE `subscription_id` = ?", [$id]
            );
        }

        is_true($after['fixed'] > $before['fixed'],
            'a subscription using the FIXED dot-notation value receives a delivery attempt',
            "before={$before['fixed']} after={$after['fixed']}");
        is_true($after['broken'] === $before['broken'],
            'a subscription still holding the OLD colon-notation value gets nothing (reproduces the original bug)',
            "before={$before['broken']} after={$after['broken']}");
    } finally {
        foreach ($subIds as $id) {
            db_query("DELETE FROM `{$prefix}webhook_deliveries` WHERE `subscription_id` = ?", [$id]);
            db_query("DELETE FROM `{$prefix}webhook_subscriptions` WHERE `id` = ?", [$id]);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. webhook_url_allowlist now has a real UI field, and it actually works --\n";
// ─────────────────────────────────────────────────────────────────────────

is_true(strpos($settingsSrc, 'data-webhook-setting="webhook_url_allowlist"') !== false,
    'settings.php renders a field bound to webhook_url_allowlist');
is_true(strpos($settingsSrc, 'id="webhookUrlAllowlist"') !== false,
    'the allowlist field has a stable element id');

$jsSrc = (string) file_get_contents($base . '/assets/js/config.js');
is_true(strpos($jsSrc, 'bindWebhookAllowlistSetting') !== false,
    'config.js defines the allowlist load/save binder');
is_true(strpos($jsSrc, "getAttribute('data-webhook-setting')") !== false,
    'the binder reads the field via the same data-webhook-setting attribute the markup sets');
is_true((bool) preg_match('/api\/config-admin\.php\?section=settings[\'"],\s*\{\s*method:\s*[\'"]POST/s',
    substr($jsSrc, strpos($jsSrc, 'bindWebhookAllowlistSetting'), 1200)),
    'the binder saves through the generic settings endpoint (POST), not a bespoke one');

// The live functional check itself ran earlier (see the comment above
// section 2) so its result is unaffected by _webhook_url_safe()'s
// per-process allowlist cache being populated by anything after it.
if ($allowlistLive['ran']) {
    is_true($allowlistLive['allowed'],
        'a URL matching an allowlisted suffix is accepted, unresolvable DNS and all');
    is_true($allowlistLive['still_blocked'],
        'a private-range URL NOT on the allowlist is still rejected');
} else {
    echo "SKIP: no database available — the live allowlist round-trip was not exercised\n";
}

echo "\n";
echo "==========================================================\n";
echo "GH#134 webhook review tests: {$pass} passed, {$fail} failed\n";
echo "==========================================================\n";

exit($fail > 0 ? 1 : 0);
